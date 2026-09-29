"""Envía dos acumulados mensuales de incidencias por fecha de apertura.

Ejecutar una vez al mes desde el programador del servidor. --dry-run consulta la
base de datos y muestra los destinatarios y totales sin enviar mensajes.
Requiere EFC_CONC_DB_HOST, EFC_CONC_DB_USER y EFC_CONC_DB_PASSWORD; acepta las
variables SMTP_* y TERMINAL_EMAIL_TO_1/2 del correo semanal.
"""
from __future__ import annotations

import argparse
from datetime import date, datetime, timedelta
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
MONTHS = ("Ene", "Feb", "Mar", "Abr", "May", "Jun", "Jul", "Ago", "Sep", "Oct", "Nov", "Dic")
CATEGORIES = (("internas", "Urovo / Verifone", "TERMINAL_EMAIL_TO_1"),
              ("valeras", "Valeras", "TERMINAL_EMAIL_TO_2"))


def load_env_file() -> None:
    for path in (SCRIPT_DIR / ".env", SCRIPT_DIR.parent / ".env", Path.cwd() / ".env"):
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


def report_period(today: date) -> tuple[date, date, list[date]]:
    """Devuelve el año del reporte y meses completos; fin es exclusivo."""
    year = today.year - 1 if today.month == 1 else today.year
    start = date(year, 1, 1)
    end = date(year + 1, 1, 1) if today.month == 1 else date(year, today.month, 1)
    months = [date(year, month, 1) for month in range(1, 13 if today.month == 1 else today.month)]
    return start, end, months


def fetch_stations_and_counts(connection: pyodbc.Connection, start: date, end: date):
    """Incluye estaciones activas y cualquier estación con historia en el periodo."""
    with connection.cursor() as cursor:
        cursor.execute("""
            SELECT Codigo, Nombre FROM TG.dbo.Estaciones
            WHERE activa=1 AND Codigo NOT IN (0,4,20)
            ORDER BY Codigo
        """)
        stations = {int(code): str(name or f"Estación {code}") for code, name in cursor.fetchall()}
        cursor.execute("""
            SELECT i.estacion_id, YEAR(i.fecha_apertura_mojo), MONTH(i.fecha_apertura_mojo),
                   CASE WHEN i.tipo_terminal IN ('urovo','verifone') THEN 'internas' ELSE 'valeras' END,
                   COUNT(*)
            FROM TG.dbo.inv_ter_incidencias AS i
            WHERE i.fecha_apertura_mojo >= ? AND i.fecha_apertura_mojo < ?
              AND i.estacion_id NOT IN (0,4,20)
              AND i.tipo_terminal IS NOT NULL
            GROUP BY i.estacion_id, YEAR(i.fecha_apertura_mojo), MONTH(i.fecha_apertura_mojo),
                     CASE WHEN i.tipo_terminal IN ('urovo','verifone') THEN 'internas' ELSE 'valeras' END
        """, start, end)
        counts = {}
        for station, year, month, category, count in cursor.fetchall():
            if station is None:
                # El reporte filtra por estación; un ID nulo no tendría enlace equivalente.
                continue
            station = int(station)
            counts[(station, int(year), int(month), category)] = int(count)
            stations.setdefault(station, f"Estación {station}")
        missing = [code for code, name in stations.items() if name == f"Estación {code}"]
        if missing:
            marks = ",".join("?" for _ in missing)
            cursor.execute(f"SELECT Codigo, Nombre FROM TG.dbo.Estaciones WHERE Codigo IN ({marks})", missing)
            for code, name in cursor.fetchall():
                stations[int(code)] = str(name or f"Estación {code}")
    return sorted(stations.items(), key=lambda item: item[0]), counts


def report_link(station: int, category: str, start: date, end: date) -> str:
    base = env_first("TERMINAL_INCIDENT_REPORT_URL", default="http://totalgasonline.net:400/operations/terminal_incident_report")
    parts = urlsplit(base)
    # A configured base URL may have unrelated query values; report filters must
    # come exclusively from this cell to keep the displayed count reproducible.
    old = [(key, value) for key, value in parse_qsl(parts.query, keep_blank_values=True)
           if key not in {"station", "type", "from", "to", "status", "as_of", "month", "assigned"}]
    query = urlencode(old + [("station", station), ("type", category),
                           ("from", start.isoformat()), ("to", end.isoformat())])
    return urlunsplit((parts.scheme, parts.netloc, parts.path, query, parts.fragment))


def month_end(month: date) -> date:
    next_month = date(month.year + 1, 1, 1) if month.month == 12 else date(month.year, month.month + 1, 1)
    return next_month - timedelta(days=1)


def render_bodies(stations, counts, months: list[date], category: str, title: str) -> tuple[str, str, int]:
    start, end = months[0], month_end(months[-1])
    headers = "".join(f'<th style="padding:8px;background:#28587c;color:white">{MONTHS[m.month-1]}</th>' for m in months)
    html_rows = []
    text_rows = [f"{title} — incidencias registradas entre {start:%d/%m/%Y} y {end:%d/%m/%Y}",
                 "Conteo por fecha de apertura, sin filtro de estado.",
                 "Estación | " + " | ".join(MONTHS[m.month-1] for m in months) + " | Total"]
    grand_total = 0
    column_totals = [0] * len(months)
    for code, name in stations:
        numbers = [counts.get((code, month.year, month.month, category), 0) for month in months]
        total = sum(numbers)
        grand_total += total
        column_totals = [old + value for old, value in zip(column_totals, numbers)]
        cells = []
        for month, value in zip(months, numbers):
            url = escape(report_link(code, category, month, month_end(month)), quote=True)
            label = f'<a href="{url}">{value}</a>'
            cells.append(f'<td style="padding:7px;text-align:center;border-bottom:1px solid #ddd">{label}</td>')
        url = escape(report_link(code, category, start, end), quote=True)
        total_label = f'<a href="{url}">{total}</a>'
        html_rows.append(f'<tr><td style="padding:7px;border-bottom:1px solid #ddd">{escape(name)} ({code})</td>'
                         + "".join(cells) + f'<td style="padding:7px;text-align:center;font-weight:bold">{total_label}</td></tr>')
        text_rows.append(f"{name} ({code}) | " + " | ".join(map(str, numbers)) + f" | {total}")
    total_cells = "".join(f'<td style="padding:7px;text-align:center;font-weight:bold">{value}</td>' for value in column_totals)
    html_rows.append(f'<tr><th style="padding:7px;text-align:left">Total</th>{total_cells}<th>{grand_total}</th></tr>')
    text_rows.append("Total | " + " | ".join(map(str, column_totals)) + f" | {grand_total}")
    html = (f'<!doctype html><html lang="es"><body style="font-family:Arial,sans-serif;color:#173b59">'
            f'<h2>{escape(title)} — {start.year}</h2><p>Incidencias por fecha de apertura, sin filtro de estado. '
            f'Periodo: {start:%d/%m/%Y} al {end:%d/%m/%Y}. Seleccione un conteo para ver sus incidencias.</p>'
            f'<table style="border-collapse:collapse"><thead><tr><th style="padding:8px;background:#28587c;color:white">Estación</th>'
            f'{headers}<th style="padding:8px;background:#28587c;color:white">Total</th></tr></thead>'
            f'<tbody>{"".join(html_rows)}</tbody></table></body></html>')
    return html, "\n".join(text_rows), grand_total


def send_message(to: list[str], subject: str, plain: str, html: str, dry_run: bool, total: int) -> None:
    if not to:
        raise RuntimeError("La lista de destinatarios está vacía")
    message = EmailMessage()
    message["From"] = env_first("SMTP_FROM", "EMAIL_FROM", default="no-reply@totalgas.com")
    message["To"] = ", ".join(to)
    message["Subject"] = subject
    message.set_content(plain)
    message.add_alternative(html, subtype="html")
    if dry_run:
        print(f"DRY-RUN: {subject} -> {message['To']} ({total} incidencias)")
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
    parser.add_argument("--dry-run", action="store_true", help="consulta sin enviar correos")
    args = parser.parse_args()
    load_env_file()
    today = datetime.now(LOCAL_TIMEZONE).date()
    start, end, months = report_period(today)
    try:
        with db_connection() as connection:
            stations, counts = fetch_stations_and_counts(connection, start, end)
        for category, title, recipient_var in CATEGORIES:
            html, plain, total = render_bodies(stations, counts, months, category, title)
            subject = f"Incidencias mensuales {title} — {start.year} hasta {MONTHS[months[-1].month-1]}"
            recipients = [address.strip() for address in os.environ.get(recipient_var, "daniel.ramirez@totalgas.com").split(",") if address.strip()]
            send_message(recipients, subject, plain, html, args.dry_run, total)
        return 0
    except Exception as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
