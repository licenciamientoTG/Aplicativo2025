"""Purge expired Tableros trash and remove private stored files.

Run every minute with the same SQL Server identity and storage configuration
used by the web application. Requires pyodbc and an installed SQL Server ODBC
driver.
"""
from __future__ import annotations

import os
from pathlib import Path
import re
import sys
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode
from urllib.request import Request, urlopen

import pyodbc


SCRIPT_DIR = Path(__file__).resolve().parent
ROOT = SCRIPT_DIR.parent
LOCK_NAME = "Tableros.Trash.Worker"
KEY_PATTERN = re.compile(r"^(?:[1-9][0-9]*/[1-9][0-9]*/[0-9]{4}/(?:0[1-9]|1[0-2])/[a-f0-9]{2}/[a-f0-9]{64}|[a-f0-9]{64})$")


def load_env_file() -> None:
    for path in (SCRIPT_DIR / ".env", ROOT / ".env", Path.cwd() / ".env"):
        if not path.is_file():
            continue
        for raw in path.read_text(encoding="utf-8-sig").splitlines():
            line = raw.strip()
            if line and not line.startswith("#") and "=" in line:
                key, value = line.split("=", 1)
                os.environ.setdefault(key.strip(), value.strip().strip('"').strip("'"))
        break


def env(*names: str, default: str = "") -> str:
    return next((os.environ[name].strip() for name in names if os.environ.get(name, "").strip()), default)


def connect() -> pyodbc.Connection:
    host = env("TABLEROS_DB_HOST", "DB_HOST")
    database = env("TABLEROS_DB_NAME", default="tableros")
    user = env("TABLEROS_DB_USER", "DB_USER")
    password = env("TABLEROS_DB_PASSWORD", "DB_PASSWORD")
    if not all((host, database, user, password)):
        raise RuntimeError("Configura TABLEROS_DB_HOST, TABLEROS_DB_USER y TABLEROS_DB_PASSWORD.")
    driver = env("TABLEROS_DB_DRIVER", "DB_DRIVER", default="ODBC Driver 17 for SQL Server")
    port = env("TABLEROS_DB_PORT", "DB_PORT", default="1433")
    trust = env("TABLEROS_DB_TRUST_CERTIFICATE", "DB_TRUST_CERTIFICATE", default="yes")
    return pyodbc.connect(
        f"DRIVER={{{driver}}};SERVER={host},{port};DATABASE={database};"
        f"UID={user};PWD={password};TrustServerCertificate={trust};",
        autocommit=True,
    )


def acquire_lock(cursor: pyodbc.Cursor) -> bool:
    row = cursor.execute("""
        DECLARE @result INT;
        EXEC @result = sys.sp_getapplock @Resource=?, @LockMode=N'Exclusive',
             @LockOwner=N'Session', @LockTimeout=0, @DbPrincipal=N'public';
        SELECT @result;
    """, LOCK_NAME).fetchone()
    return row is not None and int(row[0]) >= 0


def claim_entries(cursor: pyodbc.Cursor, limit: int) -> list[int]:
    rows = cursor.execute(f"""
        ;WITH candidates AS (
            SELECT TOP ({limit}) id FROM dbo.tb_trash_entry WITH (UPDLOCK, READPAST, ROWLOCK)
            WHERE entry_kind='trash' AND restored_at IS NULL
              AND (purge_requested_at IS NOT NULL OR expires_at <= SYSUTCDATETIME())
            ORDER BY COALESCE(purge_requested_at, expires_at), id
        )
        UPDATE t SET purge_started_at=COALESCE(t.purge_started_at, SYSUTCDATETIME())
        OUTPUT INSERTED.id FROM dbo.tb_trash_entry t JOIN candidates c ON c.id=t.id;
    """).fetchall()
    return [int(row[0]) for row in rows]


def enqueue_file_cleanup(cursor: pyodbc.Cursor, entry_id: int) -> None:
    cursor.execute("""
        INSERT INTO dbo.tb_storage_cleanup(entry_id,storage_provider,storage_container,storage_object_key)
        SELECT ?,v.storage_provider,v.storage_container,v.storage_object_key
        FROM dbo.tb_trash_entity e
        JOIN dbo.tb_file_version v ON e.table_name='tb_file_version' AND v.id=e.entity_id
        WHERE e.entry_id=? AND NOT EXISTS (
          SELECT 1 FROM dbo.tb_storage_cleanup c WHERE c.entry_id=?
            AND c.storage_provider=v.storage_provider AND c.storage_container=v.storage_container
            AND c.storage_object_key=v.storage_object_key
        )
    """, entry_id, entry_id, entry_id)


def storage_root() -> Path:
    configured = env("TABLEROS_PRIVATE_STORAGE", default=str(ROOT / "uploads" / "tableros"))
    root = Path(configured).expanduser()
    if not root.is_absolute():
        raise RuntimeError("TABLEROS_PRIVATE_STORAGE debe ser una ruta absoluta.")
    root.mkdir(parents=True, exist_ok=True)
    return root.resolve(strict=True)


def is_within(path: Path, root: Path) -> bool:
    try:
        path.relative_to(root)
        return True
    except ValueError:
        return False


def remove_local(key: str) -> None:
    if not KEY_PATTERN.fullmatch(key):
        raise RuntimeError("Invalid stored object reference")
    root = storage_root()
    relative = Path(key[:2]) / key if re.fullmatch(r"[a-f0-9]{64}", key) else Path(*key.split("/"))
    path = root / relative
    if not path.exists() and re.fullmatch(r"[a-f0-9]{64}", key):
        old_root = (ROOT.parent / "tableros-private").resolve()
        old_path = old_root / key[:2] / key
        if old_path.exists() and is_within(old_path.resolve(), old_root) and old_path.is_file():
            old_path.unlink()
        return
    if not path.exists():
        return
    resolved = path.resolve(strict=True)
    if not is_within(resolved, root) or not resolved.is_file():
        raise RuntimeError("Stored object is outside the configured storage root")
    resolved.unlink()


def remove_nas(key: str) -> None:
    if not KEY_PATTERN.fullmatch(key):
        raise RuntimeError("Invalid stored object reference")
    base = env("TABLEROS_STORAGE_SERVICE_URL").rstrip("/")
    token = env("TABLEROS_STORAGE_SERVICE_TOKEN")
    if not base or len(token) < 32:
        raise RuntimeError("Falta configurar el servicio de almacenamiento Tableros.")
    request = Request(f"{base}?{urlencode({'key': key})}", headers={"Authorization": f"Bearer {token}"}, method="DELETE")
    try:
        with urlopen(request, timeout=600) as response:
            if not 200 <= response.status < 300:
                raise RuntimeError(f"Storage service returned HTTP {response.status}")
    except (HTTPError, URLError, TimeoutError) as exc:
        raise RuntimeError(f"Storage service request failed: {exc}") from exc


def remove_object(provider: str, container: str, key: str) -> None:
    if container != "tableros":
        raise RuntimeError("Invalid storage container")
    if provider == "local":
        remove_local(key)
    elif provider == "nas_service":
        remove_nas(key)
    else:
        raise RuntimeError("Unknown storage provider")


def process_files(cursor: pyodbc.Cursor, entry_id: int) -> tuple[int, int, bool]:
    jobs = cursor.execute("""
        SELECT id,storage_provider,storage_container,storage_object_key
        FROM dbo.tb_storage_cleanup WHERE entry_id=? AND completed_at IS NULL ORDER BY id
    """, entry_id).fetchall()
    removed = failures = 0
    for job in jobs:
        try:
            remove_object(str(job.storage_provider), str(job.storage_container), str(job.storage_object_key))
            cursor.execute("UPDATE dbo.tb_storage_cleanup SET completed_at=SYSUTCDATETIME(),last_error=NULL WHERE id=?", int(job.id))
            removed += 1
        except Exception as exc:  # Keep the durable queue row for the next worker run.
            cursor.execute("UPDATE dbo.tb_storage_cleanup SET attempts=attempts+1,last_error=? WHERE id=?", str(exc)[:900], int(job.id))
            failures += 1
    pending = int(cursor.execute("SELECT COUNT(*) FROM dbo.tb_storage_cleanup WHERE entry_id=? AND completed_at IS NULL", entry_id).fetchone()[0])
    return removed, failures, pending == 0


def delete_ids(cursor: pyodbc.Cursor, table: str, column: str, values: list[int]) -> None:
    # Identifiers are hard-coded at call sites; values are always parameters.
    for start in range(0, len(set(values)), 500):
        chunk = list(dict.fromkeys(values))[start:start + 500]
        if chunk:
            marks = ",".join("?" for _ in chunk)
            cursor.execute(f"DELETE FROM dbo.{table} WHERE {column} IN ({marks})", *chunk)


def hard_delete_bundle(connection: pyodbc.Connection, entry_id: int) -> bool:
    connection.autocommit = False
    try:
        cursor = connection.cursor()
        state = cursor.execute("SELECT restored_at,purge_started_at FROM dbo.tb_trash_entry WITH (UPDLOCK,HOLDLOCK) WHERE id=?", entry_id).fetchone()
        if not state or state.restored_at is not None or state.purge_started_at is None:
            connection.rollback()
            return False
        by_table: dict[str, list[int]] = {}
        for row in cursor.execute("SELECT table_name,entity_id FROM dbo.tb_trash_entity WHERE entry_id=?", entry_id).fetchall():
            by_table.setdefault(str(row.table_name), []).append(int(row.entity_id))
        ids = lambda name: list(dict.fromkeys(by_table.get(name, [])))
        files, file_comments, comments = ids("tb_file"), ids("tb_file_comment"), ids("tb_comment")
        items, boards, workspaces = ids("tb_item"), ids("tb_board"), ids("tb_workspace")
        for table, column, values in (
            ("tb_file_comment_sticker", "file_comment_id", file_comments),
            ("tb_file_comment_attachment", "file_comment_id", file_comments),
            ("tb_file_comment_attachment", "file_id", files),
            ("tb_notification", "file_comment_id", file_comments), ("tb_notification", "comment_id", comments),
            ("tb_notification", "file_id", files), ("tb_notification", "item_id", items), ("tb_notification", "board_id", boards),
            ("tb_automation_run", "automation_id", ids("tb_automation")),
            ("tb_item_relation", "id", ids("tb_item_relation")), ("tb_item_dependency", "id", ids("tb_item_dependency")),
            ("tb_activity", "item_id", items), ("tb_activity", "board_id", boards), ("tb_activity", "workspace_id", workspaces),
            ("tb_cell", "id", ids("tb_cell")), ("tb_item_person", "id", ids("tb_item_person")),
            ("tb_file_comment", "id", file_comments), ("tb_comment", "id", comments),
        ):
            delete_ids(cursor, table, column, values)
        if files:
            marks = ",".join("?" for _ in files)
            cursor.execute(f"UPDATE dbo.tb_file SET current_version_id=NULL WHERE id IN ({marks})", *files)
        for table, values in (
            ("tb_file_version", ids("tb_file_version")), ("tb_file", files), ("tb_view", ids("tb_view")),
            ("tb_automation", ids("tb_automation")), ("tb_item", items), ("tb_group", ids("tb_group")),
            ("tb_column", ids("tb_column")), ("tb_board_member", ids("tb_board_member")),
            ("tb_workspace_member", ids("tb_workspace_member")),
        ):
            delete_ids(cursor, table, "id", values)
        delete_ids(cursor, "tb_board_member", "board_id", boards)
        delete_ids(cursor, "tb_workspace_member", "workspace_id", workspaces)
        for table, values in (("tb_board", boards), ("tb_folder", ids("tb_folder")), ("tb_workspace", workspaces)):
            delete_ids(cursor, table, "id", values)
        cursor.execute("DELETE FROM dbo.tb_trash_entity WHERE entry_id=?", entry_id)
        cursor.execute("DELETE FROM dbo.tb_storage_cleanup WHERE entry_id=?", entry_id)
        cursor.execute("DELETE FROM dbo.tb_trash_entry WHERE id=?", entry_id)
        connection.commit()
        return True
    except Exception:
        connection.rollback()
        raise
    finally:
        connection.autocommit = True


def run() -> dict[str, int]:
    result = {"entries_purged": 0, "files_removed": 0, "failures": 0}
    connection = connect()
    try:
        cursor = connection.cursor()
        if not acquire_lock(cursor):
            print("Another Tableros trash worker holds the database lock.", flush=True)
            return result
        try:
            for entry_id in claim_entries(cursor, 50):
                try:
                    enqueue_file_cleanup(cursor, entry_id)
                    removed, failures, ready = process_files(cursor, entry_id)
                    result["files_removed"] += removed
                    result["failures"] += failures
                    if ready and hard_delete_bundle(connection, entry_id):
                        result["entries_purged"] += 1
                except Exception as exc:
                    print(f"Tableros trash purge failed for entry {entry_id}: {exc}", file=sys.stderr, flush=True)
                    result["failures"] += 1
        finally:
            cursor.execute("EXEC sys.sp_releaseapplock @Resource=?, @LockOwner=N'Session', @DbPrincipal=N'public';", LOCK_NAME)
        return result
    finally:
        connection.close()


if __name__ == "__main__":
    try:
        load_env_file()
        print(run(), flush=True)
    except Exception as exc:
        print(f"Tableros trash worker failed: {exc}", file=sys.stderr, flush=True)
        raise SystemExit(1)
