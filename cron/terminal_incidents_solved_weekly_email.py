"""Envía semanalmente un resumen de incidencias que siguen en estado Solved.

El correo incluye una fila por estación activa, los tickets resueltos de cada
una y un enlace al reporte web filtrado por estación y estado Solved. El script
solo consulta SQL Server y envía correo; no modifica incidencias.

Configuración requerida:
  EFC_CONC_DB_HOST, EFC_CONC_DB_USER, EFC_CONC_DB_PASSWORD

Opcionales:
  TERMINAL_INCIDENT_REPORT_URL, SMTP_HOST, SMTP_PORT, SMTP_FROM

Usa --dry-run para consultar los datos y mostrar destinatarios sin enviar.
"""
from __future__ import annotations

import argparse
from datetime import datetime
from email.message import EmailMessage
from html import escape
import os
from pathlib import Path
import smtplib
import sys
from urllib.parse import parse_qsl, urlencode, urlsplit, urlunsplit
from zoneinfo import ZoneInfo

import pyodbc


SCRIPT_DIR = Path(__file__).resolve().parent
LOCAL_TIMEZONE = ZoneInfo("America/Ojinaga")
DANIEL_EMAIL = "daniel.ramirez@totalgas.com"
EXCLUDED_STATIONS = (0, 4, 20)

# Destinatarios fijos para habilitar cuando termine la prueba con Daniel:
# Dirección proporcionada de gerencia/operaciones; confirmar si corresponde a Jonathan Gómez:
# TERMINAL_SOLVED_EMAIL_JONATHAN_GOMEZ = "gerente.operaciones@totalgas.com"
# TERMINAL_SOLVED_EMAIL_ALMA = "alma.tinajero@totalgas.com"
# TERMINAL_SOLVED_EMAIL_JONATHAN_RIVAS = "jonatan.rivas@totalgas.com"
# TERMINAL_SOLVED_EMAIL_IVAN = "ivan.meraz@totalgas.com"
# TERMINAL_SOLVED_EMAIL_JOEL = "joel.briseno@totalgas.com"


def load_env_file() -> None:
    for path in (SCRIPT_DIR / "terminal_incidents_solved_weekly_email.env",
                 SCRIPT_DIR / ".env", SCRIPT_DIR.parent / ".env", Path.cwd() / ".env"):
        if path.is_file():
            for raw_line in path.read_text(encoding="utf-8-sig").splitlines():
                line = raw_line.strip()
                if line and not line.startswith("#") and "=" in line:
                    name, value = line.split("=", 1)
                    os.environ.setdefault(name.strip(), value.strip().strip('"').strip("'"))
            break


def env_first(*names: str, default: str = "") -> str:
    return next((value for name in names if (value := os.environ.get(name, "").strip())), default)


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
    return pyodbc.connect(
        f"DRIVER={{{driver}}};SERVER={host},{port};DATABASE={name};"
        f"UID={user};PWD={password};TrustServerCertificate={trust};", autocommit=True)


def fetch_solved_by_station(connection: pyodbc.Connection) -> list[dict[str, object]]:
    """Devuelve todas las estaciones activas y sus tickets Solved actuales."""
    with connection.cursor() as cursor:
        cursor.execute("""
            SELECT s.Codigo AS station_id, s.Nombre AS station_name,
                   NULLIF(LTRIM(RTRIM(s.email)), '') AS station_email,
                   i.ticket_mojo_id
            FROM TG.dbo.Estaciones AS s
            LEFT JOIN TG.dbo.inv_ter_incidencias AS i
              ON i.estacion_id = s.Codigo AND i.estado_local = 'Solved'
            WHERE s.activa = 1 AND s.Codigo NOT IN (0, 4, 20)
            ORDER BY s.Nombre, i.fecha_apertura_mojo, i.id
        """)
        columns = [column[0] for column in cursor.description]
        raw_rows = [dict(zip(columns, row)) for row in cursor.fetchall()]

    by_station: dict[int, dict[str, object]] = {}
    for row in raw_rows:
        station_id = int(row["station_id"])
        station = by_station.setdefault(station_id, {
            "station_id": station_id,
            "station_name": str(row.get("station_name") or f"Estación {station_id}"),
            "station_email": str(row.get("station_email") or "").strip(),
            "tickets": [],
        })
        ticket_id = row.get("ticket_mojo_id")
        if ticket_id is not None:
            station["tickets"].append(str(ticket_id))

    return sorted(by_station.values(), key=lambda station: (
        -len(station["tickets"]), str(station["station_name"]).casefold()))


def recipients(stations: list[dict[str, object]]) -> list[str]:
    # Modo de prueba: el correo solo se envía a Daniel.
    addresses = [DANIEL_EMAIL]

    # Habilitar después de las pruebas para incluir a jefes de zona y gerencia:
    # addresses.extend([
    #     "gerente.operaciones@totalgas.com",
    #     "alma.tinajero@totalgas.com",
    #     "jonatan.rivas@totalgas.com",
    #     "ivan.meraz@totalgas.com",
    #     "joel.briseno@totalgas.com",
    # ])

    # Habilitar después de las pruebas para incluir los correos de las estaciones
    # activas, leídos de TG.dbo.Estaciones.email:
    # addresses.extend(str(station.get("station_email") or "").strip() for station in stations)
    unique: dict[str, str] = {}
    for address in addresses:
        if address and "@" in address:
            unique.setdefault(address.casefold(), address)
    if not unique:
        raise RuntimeError("No hay destinatarios con correos válidos")
    return list(unique.values())


def report_link(station_id: int) -> str:
    base = env_first("TERMINAL_INCIDENT_REPORT_URL", default="http://totalgasonline.net:400/operations/terminal_incident_report")
    parts = urlsplit(base)
    preserved = [(key, value) for key, value in parse_qsl(parts.query, keep_blank_values=True)
                 if key not in {"station", "status", "month", "from", "to", "type", "assigned"}]
    query = urlencode(preserved + [("station", station_id), ("status", "solved")])
    return urlunsplit((parts.scheme, parts.netloc, parts.path, query, parts.fragment))


def render_bodies(stations: list[dict[str, object]], sent_at: datetime) -> tuple[str, str, int]:
    rows_html = []
    rows_text = [f"Incidencias en estado resuelto — {sent_at:%d/%m/%Y}",
                 "El conteo incluye todos los tickets que actualmente siguen en estado Solved.",
                 "Estación | Tickets resueltos"]
    total = 0
    for station in stations:
        station_id = int(station["station_id"])
        station_name = str(station["station_name"])
        tickets = list(station["tickets"])
        count = len(tickets)
        total += count
        href = escape(report_link(station_id), quote=True)
        rows_html.append(
            f'<tr><td style="padding:8px;border-bottom:1px solid #dbe4ea">{escape(station_name)} ({station_id})</td>'
            f'<td style="padding:8px;text-align:center;border-bottom:1px solid #dbe4ea;font-weight:700">'
            f'<a href="{href}" style="color:#125ca8">{count}</a></td></tr>'
        )
        rows_text.append(f"{station_name} ({station_id}) | {count}")
    rows_html.append(
        f'<tr style="font-weight:700;background:#eef5fa"><td style="padding:8px">Total de tickets resueltos</td>'
        f'<td style="padding:8px;text-align:center">{total}</td></tr>'
    )
    rows_text.append(f"Total | {total}")
    html = (
        '<!doctype html><html lang="es"><body style="font-family:Arial,sans-serif;color:#173b59">'
        f'<h2>Incidencias resueltas por estación — {sent_at:%d/%m/%Y}</h2>'
        '<p>Se muestran tickets que actualmente tienen estado local <strong>Solved</strong>. '
        'Selecciona el total de una estación para abrir el reporte con esa estación y el estado resuelto filtrados.</p>'
        '<table style="border-collapse:collapse;width:100%;max-width:1000px"><thead><tr style="background:#28587c;color:white">'
        '<th style="padding:8px;text-align:left">Estación</th><th style="padding:8px">Tickets resueltos</th>'
        '</tr></thead><tbody>' + "".join(rows_html) + '</tbody></table></body></html>'
    )
    return html, "\n".join(rows_text), total


def send_email(to: list[str], stations: list[dict[str, object]], sent_at: datetime, dry_run: bool) -> None:
    html, plain, total = render_bodies(stations, sent_at)
    message = EmailMessage()
    message["From"] = env_first("SMTP_FROM", "EMAIL_FROM", default="no-reply@totalgas.com")
    message["To"] = ", ".join(to)
    message["Subject"] = f"Incidencias de terminales resueltas — {sent_at:%d/%m/%Y}"
    message.set_content(plain)
    message.add_alternative(html, subtype="html")
    if dry_run:
        print(f"DRY-RUN: {message['Subject']} -> {message['To']} ({total} tickets Solved)")
        return
    host = env_first("SMTP_HOST", "EMAIL_SMTP_HOST", default="smtp-relay.gmail.com")
    port = int(env_first("SMTP_PORT", "EMAIL_SMTP_PORT", default="587"))
    with smtplib.SMTP(host, port, timeout=30) as smtp:
        smtp.ehlo()
        smtp.starttls()
        smtp.ehlo()
        smtp.send_message(message)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--dry-run", action="store_true", help="consulta y muestra destinatarios sin enviar")
    args = parser.parse_args()
    load_env_file()
    try:
        with db_connection() as connection:
            stations = fetch_solved_by_station(connection)
        to = recipients(stations)
        send_email(to, stations, datetime.now(LOCAL_TIMEZONE), args.dry_run)
        return 0
    except Exception as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
