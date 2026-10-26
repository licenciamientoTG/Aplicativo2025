"""Hourly incremental sync of Mojo tickets used by TotalGas reports.

This server-side task reuses the existing UpdateMojoTickets client for its API
credentials, database connection, and SQL upsert logic. It also refreshes the
Mojo state of tickets linked to terminal incidents so the incident report stays
current even when a ticket edit falls behind the incremental cursor. A checkpoint
is only advanced after tickets, linked incidents, and users sync successfully.
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
from zoneinfo import ZoneInfo, ZoneInfoNotFoundError


SCRIPT_DIR = Path(__file__).resolve().parent
LEGACY_SYNC = SCRIPT_DIR / "update_mojo_tickets.py"
STATE_FILE = SCRIPT_DIR / "mojo_tickets_sync_hourly_state.json"
LOG_FILE = SCRIPT_DIR / "mojo_tickets_sync_hourly.log"
PAGE_SIZE = 100
OVERLAP = timedelta(minutes=10)
INITIAL_LOOKBACK = timedelta(hours=24)
try:
    LOCAL_TIMEZONE = ZoneInfo("America/Ciudad_Juarez")
    LOCAL_TIMEZONE_FALLBACK = False
except ZoneInfoNotFoundError:
    # Windows Python may not have an IANA timezone database installed.
    LOCAL_TIMEZONE = datetime.now().astimezone().tzinfo or timezone.utc
    LOCAL_TIMEZONE_FALLBACK = True


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


def sync_changed_tickets(
    sync: Any,
    since: datetime,
    logger: logging.Logger,
    ticket_cache: dict[int, dict[str, Any]],
) -> int:
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
            ticket_cache[int(ticket_id)] = ticket
            processed += 1

        sync.conn.commit()
        logger.info("Página %s sincronizada: %s tickets", page, len(summaries))
        if len(summaries) < PAGE_SIZE:
            break
        page += 1

    return processed


def ticket_local_state(ticket: dict[str, Any], previous: str) -> str:
    status = ticket.get("status")
    status_id = ticket.get("status_id")
    if isinstance(status, dict):
        status_id = status_id or status.get("id")
        status = status.get("name") or status.get("status_name") or ""
    try:
        status_id = int(status_id or 0)
    except (TypeError, ValueError):
        status_id = 0

    if status_id > 0:
        if status_id == 60:
            return "Closed"
        if status_id == 50:
            return "Solved"
        return "Reabierta" if previous in ("Solved", "Closed") else "Abierta"

    normalized = str(status or ticket.get("status_name") or "").strip().lower()
    if normalized in ("closed", "cerrado", "cerrada") or ticket.get("closed_on"):
        return "Closed"
    if normalized in ("solved", "resolved", "resuelto", "resuelta") or ticket.get("solved_on"):
        return "Solved"
    return "Reabierta" if previous in ("Solved", "Closed") else "Abierta"


def ticket_closed_at(ticket: dict[str, Any], now: datetime) -> str:
    value = ticket.get("closed_on") or ticket.get("status_changed_on") or ticket.get("solved_on")
    if value:
        if isinstance(value, datetime):
            parsed = value
        else:
            text = str(value).strip()
            parsed = datetime.fromisoformat(text[:-1] + "+00:00" if text.endswith("Z") else text)
        if parsed.tzinfo is None:
            parsed = parsed.replace(tzinfo=timezone.utc)
        return parsed.astimezone(LOCAL_TIMEZONE).strftime("%Y-%m-%d %H:%M:%S")
    return now.astimezone(LOCAL_TIMEZONE).strftime("%Y-%m-%d %H:%M:%S")


def ticket_state_changed_at(ticket: dict[str, Any], now: datetime) -> str:
    value = ticket.get("status_changed_on") or ticket.get("closed_on") or ticket.get("solved_on") or ticket.get("updated_on")
    if value:
        if isinstance(value, datetime):
            parsed = value
        else:
            text = str(value).strip()
            parsed = datetime.fromisoformat(text[:-1] + "+00:00" if text.endswith("Z") else text)
        if parsed.tzinfo is None:
            parsed = parsed.replace(tzinfo=timezone.utc)
        return parsed.astimezone(LOCAL_TIMEZONE).strftime("%Y-%m-%d %H:%M:%S")
    return now.astimezone(LOCAL_TIMEZONE).strftime("%Y-%m-%d %H:%M:%S")


def closed_by(ticket: dict[str, Any]) -> str | None:
    for key in ("solved_by", "closed_by", "resolved_by", "solved_by_user", "closed_by_user", "resolver"):
        actor = ticket.get(key)
        if isinstance(actor, dict):
            for field in ("name", "full_name", "email", "display_name", "username"):
                value = actor.get(field)
                if value and not isinstance(value, (dict, list)):
                    return str(value).strip()[:150]
        elif actor is not None and str(actor).strip():
            return str(actor).strip()[:150]
    return None


def sync_linked_terminal_incidents(
    sync: Any,
    logger: logging.Logger,
    ticket_cache: dict[int, dict[str, Any]],
) -> int:
    """Refresh report states, including changes older than the incremental cursor."""
    cursor = sync.conn.cursor()
    incidents = cursor.execute(
        """SELECT id, ticket_mojo_id, estado_local
           FROM TG.dbo.inv_ter_incidencias
           WHERE ticket_mojo_id IS NOT NULL"""
    ).fetchall()
    transitions = 0
    checked = 0
    failures = 0

    for incident_id, ticket_id, previous_value in incidents:
        incident_id = int(incident_id)
        ticket_id = int(ticket_id)
        previous = str(previous_value or "Abierta")
        ticket = ticket_cache.get(ticket_id)
        if ticket is None:
            try:
                ticket = sync.make_api_request(f"tickets/{ticket_id}", version="v2")
            except Exception as exc:
                failures += 1
                logger.warning(
                    "No se pudo consultar el ticket de incidencia %s (%s); se reintentará en la siguiente corrida.",
                    ticket_id,
                    type(exc).__name__,
                )
                continue
            if not isinstance(ticket, dict) or not ticket:
                failures += 1
                logger.warning("Mojo no devolvió el ticket de incidencia %s; se reintentará en la siguiente corrida.", ticket_id)
                continue
            ticket_cache[ticket_id] = ticket

        # Keep the Mojo mirror current for report metadata as well as status.
        sync.upsert_ticket(ticket)
        state = ticket_local_state(ticket, previous)
        closed_at = ticket_closed_at(ticket, datetime.now(timezone.utc)) if state == "Closed" else None
        actor = closed_by(ticket)

        cursor.execute(
            """UPDATE TG.dbo.inv_ter_incidencias
               SET estado_mojo=?, estado_local=?, fecha_cierre_mojo=?,
                   cerrado_por_mojo=COALESCE(?,cerrado_por_mojo)
               WHERE id=? AND estado_local=?""",
            state, state, closed_at, actor, incident_id, previous,
        )
        if cursor.rowcount == 1 and state != previous:
            cursor.execute(
                """INSERT INTO TG.dbo.inv_ter_incidencia_estados
                   (incidencia_id,ticket_mojo_id,estado_anterior,estado_nuevo,
                    fecha_estado_mojo,origen,usuario_id,usuario_correo,comentario,sincronizacion)
                   VALUES (?,?,?,?,?,'mojo_sync',NULL,'MOJO/API',NULL,'sincronizado')""",
                incident_id, ticket_id, previous, state,
                ticket_state_changed_at(ticket, datetime.now(timezone.utc)),
            )
            transitions += 1
            logger.info("Estado de incidencia actualizado: ticket=%s %s -> %s", ticket_id, previous, state)
        # Finish each ticket's database work before making another remote call.
        # If a later ticket fails, the checkpoint stays put and this sync is safe
        # to repeat because unchanged states do not create another history row.
        sync.conn.commit()
        checked += 1

    logger.info("Incidencias vinculadas revisadas en Mojo: %s; transiciones: %s; fallas API: %s", checked, transitions, failures)
    return transitions


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
        if LOCAL_TIMEZONE_FALLBACK:
            logger.warning("No se encontró tzdata; usando la zona horaria local del servidor para las fechas de cierre.")

        ticket_cache: dict[int, dict[str, Any]] = {}
        ticket_count = sync_changed_tickets(sync, since, logger, ticket_cache)
        incident_transitions = sync_linked_terminal_incidents(sync, logger, ticket_cache)

        # Keep the local name lookup current so reports can show newly assigned
        # agents instead of falling back to "Sin asignar".
        sync.sync_users()
        sync.conn.commit()

        # Use the start time as checkpoint. The overlap catches changes made
        # while this run was in progress on the next hourly execution.
        save_cursor(started_at)
        logger.info(
            "Sincronización horaria completada: %s tickets actualizados; %s incidencias cambiaron de estado.",
            ticket_count,
            incident_transitions,
        )
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
