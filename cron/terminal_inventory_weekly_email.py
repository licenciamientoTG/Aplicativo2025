"""Envía los reportes de incidencias abiertas del inventario de terminales.

El proceso está pensado para ejecutarse desde el programador del servidor. No
crea ni modifica datos: únicamente lee TG.dbo.inv_ter_incidencias y envía dos
mensajes independientes (uno por cada lista de destinatarios).

Variables requeridas (pueden estar en ``.env`` junto al script o en el entorno):
  EFC_CONC_DB_HOST, EFC_CONC_DB_NAME, EFC_CONC_DB_USER, EFC_CONC_DB_PASSWORD

Opcionales:
  SMTP_HOST (smtp-relay.gmail.com), SMTP_PORT (587), SMTP_FROM
  TERMINAL_EMAIL_TO_1 y TERMINAL_EMAIL_TO_2 (ambas tienen por defecto
  daniel.ramirez@totalgas.com).

El envío usa el SMTP Relay de Google Workspace igual que ``send_mail()`` del
portal: STARTTLS en el puerto 587, sin usuario ni contraseña, autorizado por IP.
"""
from __future__ import annotations

import argparse
from datetime import datetime, timedelta
from email.message import EmailMessage
from html import escape
import os
from pathlib import Path
import smtplib
import sys
from typing import Iterable

import pyodbc


SCRIPT_DIR = Path(__file__).resolve().parent
ROOT = SCRIPT_DIR.parent
MOJO_TICKET_URL = "https://totalgas.mojohelpdesk.com/mc/tickets/{}"


def load_env_file() -> None:
    """Carga un .env sin sobrescribir variables ya definidas por el servidor."""
    candidates = (SCRIPT_DIR / ".env", ROOT / ".env", Path.cwd() / ".env")
    path = next((candidate for candidate in candidates if candidate.is_file()), None)
    if not path:
        return
    for raw_line in path.read_text(encoding="utf-8-sig").splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        name, value = line.split("=", 1)
        os.environ.setdefault(name.strip(), value.strip().strip('"').strip("'"))


def env_first(*names: str, default: str = "") -> str:
    for name in names:
        value = os.environ.get(name, "").strip()
        if value:
            return value
    return default


def db_connection() -> pyodbc.Connection:
    host = env_first("EFC_CONC_DB_HOST", "DB_HOST")
    name = env_first("EFC_CONC_DB_NAME", "DB_NAME", default="TG")
    user = env_first("EFC_CONC_DB_USER", "DB_USER")
    password = env_first("EFC_CONC_DB_PASSWORD", "DB_PASSWORD")
    if not all((host, user, password)):
        raise RuntimeError("Faltan EFC_CONC_DB_HOST, EFC_CONC_DB_USER o EFC_CONC_DB_PASSWORD")
    port = env_first("EFC_CONC_DB_PORT", "DB_PORT", default="1433")
    driver = env_first("EFC_CONC_DB_DRIVER", "DB_DRIVER", default="ODBC Driver 17 for SQL Server")
    trust = env_first("EFC_CONC_DB_TRUST_CERTIFICATE", "DB_TRUST_CERTIFICATE", default="yes")
    connection_string = (
        f"DRIVER={{{driver}}};SERVER={host},{port};DATABASE={name};"
        f"UID={user};PWD={password};TrustServerCertificate={trust};"
    )
    return pyodbc.connect(connection_string, autocommit=True)


def parse_datetime(value: object) -> datetime:
    if isinstance(value, datetime):
        return value
    text = str(value).replace("T", " ").split(".", 1)[0]
    return datetime.strptime(text, "%Y-%m-%d %H:%M:%S")


def business_hours(from_value: object, until: datetime) -> float:
    """Horas laborables 08:00-18:00, lunes a viernes, convertidas a jornadas."""
    start = parse_datetime(from_value)
    if start >= until:
        return 0.0
    cursor = start.date()
    end_date = until.date()
    total_hours = 0.0
    while cursor <= end_date:
        if cursor.weekday() < 5:
            window_start = datetime.combine(cursor, datetime.min.time()).replace(hour=8)
            window_end = datetime.combine(cursor, datetime.min.time()).replace(hour=18)
            left = max(start, window_start)
            right = min(until, window_end)
            if right > left:
                total_hours += (right - left).total_seconds() / 3600
        cursor += timedelta(days=1)
    return round(total_hours / 10, 2)


def fetch_open_incidents(connection: pyodbc.Connection) -> list[dict[str, object]]:
    query = """
        SELECT i.ticket_mojo_id, i.tipo_terminal, i.descripcion,
               i.fecha_apertura_mojo, i.estado_mojo, s.Nombre AS estacion_nombre
        FROM TG.dbo.inv_ter_incidencias AS i
        LEFT JOIN TG.dbo.Estaciones AS s ON s.Codigo = i.estacion_id
        WHERE i.fecha_cierre_mojo IS NULL
        ORDER BY i.fecha_apertura_mojo ASC, i.id ASC
    """
    now = datetime.now()
    with connection.cursor() as cursor:
        cursor.execute(query)
        columns = [column[0] for column in cursor.description]
        rows = [dict(zip(columns, row)) for row in cursor.fetchall()]
    for row in rows:
        row["dias_habiles"] = business_hours(row["fecha_apertura_mojo"], now)
    rows.sort(key=lambda row: (-float(row["dias_habiles"]), parse_datetime(row["fecha_apertura_mojo"])))
    return rows


def recipients(name: str) -> list[str]:
    return [address.strip() for address in os.environ.get(name, "daniel.ramirez@totalgas.com").split(",") if address.strip()]


def spanish_status(value: object) -> str:
    """Traduce estados estándar de Mojo sin alterar estados personalizados."""
    original = str(value or "—").strip()
    normalized = original.lower().replace("_", "-").replace(" ", "-")
    translations = {
        "open": "Abierto", "opened": "Abierto", "abierto": "Abierto",
        "closed": "Cerrado", "close": "Cerrado", "closed-status": "Cerrado", "cerrado": "Cerrado",
        "new": "Nuevo", "nuevo": "Nuevo",
        "pending": "Pendiente", "pendiente": "Pendiente",
        "on-hold": "En espera", "onhold": "En espera", "en-espera": "En espera",
        "solved": "Resuelto", "resolved": "Resuelto", "resuelto": "Resuelto",
        "reopened": "Reabierto", "re-opened": "Reabierto", "reabierto": "Reabierto",
        "waiting-customer": "Esperando al cliente", "waiting-for-customer": "Esperando al cliente",
        "waiting-provider": "Esperando al proveedor", "waiting-for-provider": "Esperando al proveedor",
    }
    return translations.get(normalized, original)


def render_html(rows: Iterable[dict[str, object]], sent_at: datetime, category: str) -> str:
    body = []
    for index, row in enumerate(rows):
        ticket = str(row["ticket_mojo_id"])
        opened = parse_datetime(row["fecha_apertura_mojo"]).strftime("%d/%m/%Y %H:%M")
        state = spanish_status(row.get("estado_mojo"))
        background = "#ffffff" if index % 2 == 0 else "#f4f8fc"
        body.append(
            f'<tr style="background:{background};border-bottom:1px solid #dbe7f2">'
            f'<td style="padding:11px 10px"><a style="color:#125ca8;font-weight:700;text-decoration:none" href="{escape(MOJO_TICKET_URL.format(ticket), quote=True)}">#{escape(ticket)}</a></td>'
            f'<td style="padding:11px 10px;color:#304a61;font-weight:700">{escape(str(row.get("tipo_terminal") or "—"))}</td>'
            f'<td style="padding:11px 10px;color:#52616f">{escape(str(row.get("estacion_nombre") or "—"))}</td>'
            f'<td style="padding:11px 10px;color:#52616f;max-width:280px">{escape(str(row.get("descripcion") or "—"))}</td>'
            f'<td style="padding:11px 10px;color:#52616f;white-space:nowrap">{opened}</td>'
            f'<td style="padding:11px 10px;text-align:center;color:#125ca8;font-weight:800;white-space:nowrap">{float(row["dias_habiles"]):.2f}</td>'
            f'<td style="padding:11px 10px;white-space:nowrap"><span style="display:inline-block;background:#eaf3fb;color:#125ca8;padding:4px 9px;border-radius:12px;font-weight:700;font-size:12px">{escape(state)}</span></td>'
            "</tr>"
        )
    sent = sent_at.strftime("%d/%m/%Y %H:%M")
    return f"""<!doctype html><html lang="es"><body style="margin:0;padding:18px;background:#f4f8fc;font-family:Arial,sans-serif;color:#304a61">
<div style="max-width:1180px;margin:0 auto;border:1px solid #dbe7f2;border-radius:10px;overflow:hidden;background:#ffffff">
<div style="padding:22px 24px;background:#125ca8;color:#ffffff"><div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;opacity:.82">TotalGas · Operaciones</div><h2 style="margin:7px 0 0;font-size:22px;font-weight:700">Incidencias abiertas — {escape(category)}</h2></div>
<div style="padding:16px 24px 12px;background:#f4f8fc;color:#52616f;font-size:13px">Reporte enviado el <strong style="color:#304a61">{sent}</strong>. Ordenado de mayor a menor por jornadas hábiles (08:00–18:00, lunes a viernes).</div>
<div style="padding:0 14px 16px;overflow-x:auto"><table border="0" cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%;font-size:13px;min-width:850px">
<thead><tr style="background:#e5f0fa;color:#174a78;text-align:left"><th style="padding:11px 10px;font-size:11px;text-transform:uppercase">Ticket Mojo</th><th style="padding:11px 10px;font-size:11px;text-transform:uppercase">Tipo de terminal</th><th style="padding:11px 10px;font-size:11px;text-transform:uppercase">Estación</th><th style="padding:11px 10px;font-size:11px;text-transform:uppercase">Descripción</th><th style="padding:11px 10px;font-size:11px;text-transform:uppercase">Apertura</th><th style="padding:11px 10px;font-size:11px;text-transform:uppercase;text-align:center">Jornadas hábiles</th><th style="padding:11px 10px;font-size:11px;text-transform:uppercase">Estado</th></tr></thead>
<tbody>{''.join(body) or '<tr><td colspan="7" style="padding:16px;text-align:center;color:#687887">No hay incidencias abiertas.</td></tr>'}</tbody></table>
</div></div><p style="max-width:1180px;margin:12px auto;color:#8a98a5;font-size:11px">Mensaje generado automáticamente por el módulo de Inventario de terminales.</p></body></html>"""


def render_text(rows: Iterable[dict[str, object]], sent_at: datetime) -> str:
    lines = [f"Incidencias de terminales abiertas ({sent_at:%d/%m/%Y %H:%M})", ""]
    for row in rows:
        lines.append(
            f"#{row['ticket_mojo_id']} | {row.get('tipo_terminal', '—')} | "
            f"{row.get('estacion_nombre', '—')} | {row.get('descripcion', '—')} | "
            f"{row['dias_habiles']:.2f} jornadas | {spanish_status(row.get('estado_mojo'))} | "
            f"{MOJO_TICKET_URL.format(row['ticket_mojo_id'])}"
        )
    return "\n".join(lines)


def send_email(to: list[str], rows: list[dict[str, object]], sent_at: datetime, category: str, dry_run: bool) -> None:
    if not to:
        raise RuntimeError("La lista de destinatarios está vacía")
    subject = f"Incidencias abiertas de {category} — {sent_at:%d/%m/%Y}"
    message = EmailMessage()
    message["From"] = env_first("SMTP_FROM", "EMAIL_FROM", default="no-reply@totalgas.com")
    message["To"] = ", ".join(to)
    message["Subject"] = subject
    message.set_content(render_text(rows, sent_at))
    message.add_alternative(render_html(rows, sent_at, category), subtype="html")
    if dry_run:
        print(f"DRY-RUN: {subject} -> {message['To']} ({len(rows)} incidencias)")
        return
    host = env_first("SMTP_HOST", "EMAIL_SMTP_HOST", default="smtp-relay.gmail.com")
    port = int(env_first("SMTP_PORT", "EMAIL_SMTP_PORT", default="587"))
    with smtplib.SMTP(host, port, timeout=30) as smtp:
        smtp.ehlo()
        smtp.starttls()
        smtp.ehlo()
        # El relay del portal autoriza por IP y explícitamente no usa SMTPAuth.
        smtp.send_message(message)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--dry-run", action="store_true", help="consulta y muestra destinatarios sin enviar")
    args = parser.parse_args()
    load_env_file()
    try:
        with db_connection() as connection:
            rows = fetch_open_incidents(connection)
        if not rows:
            print("No hay incidencias abiertas; no se envía correo.")
            return 0
        sent_at = datetime.now()
        urovo_rows = [row for row in rows if str(row.get("tipo_terminal", "")).strip().lower() == "urovo"]
        valera_rows = [row for row in rows if str(row.get("tipo_terminal", "")).strip().lower() != "urovo"]
        send_email(recipients("TERMINAL_EMAIL_TO_1"), urovo_rows, sent_at, "Urovo", args.dry_run)
        send_email(recipients("TERMINAL_EMAIL_TO_2"), valera_rows, sent_at, "valeras", args.dry_run)
        print(f"Se enviaron dos reportes: {len(urovo_rows)} Urovo y {len(valera_rows)} valeras.")
        return 0
    except Exception as exc:  # el programador del servidor verá un código distinto de cero
        print(f"ERROR: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
