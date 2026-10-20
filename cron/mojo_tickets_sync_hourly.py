"""Hourly incremental sync of Mojo tickets used by TotalGas reports.

This server-side task reuses the existing UpdateMojoTickets client for its API
credentials, database connection, and SQL upsert logic. A checkpoint is only
advanced after every changed ticket (and the user catalog refresh) succeeds.
"""
from __future__ import annotations

from datetime import datetime, timedelta, timezone
import importlib.util
import json
import logging
import msvcrt
import os
from pathlib import Path
import sys
from typing import Any


SCRIPT_DIR = Path(__file__).resolve().parent
LEGACY_SYNC = SCRIPT_DIR / "update_mojo_tickets.py"
STATE_FILE = SCRIPT_DIR / "mojo_tickets_sync_hourly_state.json"
LOG_FILE = SCRIPT_DIR / "mojo_tickets_sync_hourly.log"
PAGE_SIZE = 100
OVERLAP = timedelta(minutes=10)
INITIAL_LOOKBACK = timedelta(hours=24)


def get_logger() -> logging.Logger:
    logger = logging.getLogger("mojo_tickets_sync_hourly")
    logger.setLevel(logging.INFO)
    if not logger.handlers:
        handler = logging.FileHandler(LOG_FILE, encoding="utf-8")
        handler.setFormatter(logging.Formatter("%(asctime)s %(levelname)s %(message)s"))
        logger.addHandler(handler)
    return logger


def acquire_lock(logger: logging.Logger):
    lock_path = SCRIPT_DIR / "mojo_tickets_sync_hourly.lock"
    lock_file = lock_path.open("a+b")
    if lock_file.tell() == 0:
        lock_file.write(b"0")
        lock_file.flush()
    lock_file.seek(0)
    try:
        msvcrt.locking(lock_file.fileno(), msvcrt.LK_NBLCK, 1)
    except OSError:
        lock_file.close()
        logger.info("Ya hay una sincronización en curso; se omite esta ejecución.")
        return None
    return lock_file


def load_sync_class():
    if not LEGACY_SYNC.is_file():
        raise FileNotFoundError(f"No se encontró el sincronizador existente: {LEGACY_SYNC}")
    spec = importlib.util.spec_from_file_location("update_mojo_tickets", LEGACY_SYNC)
    if spec is None or spec.loader is None:
        raise RuntimeError("No se pudo cargar el cliente existente de Mojo.")
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)
    return module.MojoHelpDeskSync


def load_cursor(now: datetime, logger: logging.Logger) -> datetime:
    if not STATE_FILE.exists():
        start = now - INITIAL_LOOKBACK
        logger.info("Sin cursor previo; primera búsqueda desde %s", start.isoformat())
        return start
    state = json.loads(STATE_FILE.read_text(encoding="utf-8"))
    value = state.get("last_successful_sync_utc")
    if not value:
        raise ValueError("El archivo de cursor no contiene last_successful_sync_utc.")
    cursor = datetime.fromisoformat(value.replace("Z", "+00:00"))
    if cursor.tzinfo is None:
        raise ValueError("El cursor debe tener zona horaria UTC.")
    return cursor.astimezone(timezone.utc)


def save_cursor(cursor: datetime) -> None:
    content = json.dumps(
        {"last_successful_sync_utc": cursor.astimezone(timezone.utc).isoformat().replace("+00:00", "Z")},
        indent=2,
    )
    temporary = STATE_FILE.with_suffix(".tmp")
    temporary.write_text(content + "\n", encoding="utf-8")
    os.replace(temporary, STATE_FILE)


def sync_changed_tickets(sync: Any, since: datetime, logger: logging.Logger) -> int:
    since_iso = since.astimezone(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
    page = 1
    processed = 0
    logger.info("Buscando cambios de Mojo desde %s", since_iso)

    while True:
        summaries = sync.make_api_request(
            "tickets/search",
            params={
                "query": f"updated_on:[{since_iso} TO *]",
                "sf": "updated_on",
                "r": 0,
                "per_page": PAGE_SIZE,
                "page": page,
            },
            version="v2",
        )
        if summaries is None:
            raise RuntimeError(f"Mojo no devolvió la página {page} de tickets.")
        if not isinstance(summaries, list):
            raise RuntimeError(f"La página {page} de Mojo no tiene el formato esperado.")
        if not summaries:
            break

        for summary in summaries:
            ticket_id = summary.get("id") if isinstance(summary, dict) else None
            if not ticket_id:
                raise RuntimeError(f"Mojo devolvió un ticket sin ID en la página {page}.")
            ticket = sync.make_api_request(f"tickets/{ticket_id}", version="v2")
            if not isinstance(ticket, dict) or not ticket:
                raise RuntimeError(f"No se pudieron obtener los detalles del ticket {ticket_id}.")
            sync.upsert_ticket(ticket)
            processed += 1

        sync.conn.commit()
        logger.info("Página %s sincronizada: %s tickets", page, len(summaries))
        if len(summaries) < PAGE_SIZE:
            break
        page += 1

    return processed


def main() -> int:
    logger = get_logger()
    lock_file = acquire_lock(logger)
    if lock_file is None:
        return 0

    sync = None
    started_at = datetime.now(timezone.utc)
    try:
        sync_class = load_sync_class()
        sync = sync_class()
        sync.connect_database()
        cursor = load_cursor(started_at, logger)
        since = cursor - OVERLAP

        ticket_count = sync_changed_tickets(sync, since, logger)

        # Keep the local name lookup current so reports can show newly assigned
        # agents instead of falling back to "Sin asignar".
        sync.sync_users()
        sync.conn.commit()

        # Use the start time as checkpoint. The overlap catches changes made
        # while this run was in progress on the next hourly execution.
        save_cursor(started_at)
        logger.info("Sincronización horaria completada: %s tickets actualizados.", ticket_count)
        return 0
    except Exception:
        logger.exception("Falló la sincronización horaria; el cursor no avanzó.")
        if sync is not None and getattr(sync, "conn", None):
            try:
                sync.conn.rollback()
            except Exception:
                pass
        return 1
    finally:
        if sync is not None:
            try:
                sync.close_database_connection()
            except Exception:
                logger.exception("No se pudo cerrar la conexión a SQL Server.")
        try:
            lock_file.seek(0)
            msvcrt.locking(lock_file.fileno(), msvcrt.LK_UNLCK, 1)
        finally:
            lock_file.close()


if __name__ == "__main__":
    raise SystemExit(main())
