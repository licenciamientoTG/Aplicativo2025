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
from urllib.parse import urlencode

import pyodbc


SCRIPT_DIR = Path(__file__).resolve().parent
ROOT = SCRIPT_DIR.parent
MOJO_TICKET_URL = "https://totalgas.mojohelpdesk.com/mc/tickets/{}"
VALERA_DISPLAY_ORDER = ("ticketcard", "ultragas", "efecticard", "eox", "inburgas", "sodexo", "mobil")


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


def business_time(from_value: object, until: datetime) -> dict[str, float]:
    """Cuenta días y horas laborables dentro de 08:00-18:00, lunes a viernes."""
    start = parse_datetime(from_value)
    if start >= until:
        return {"dias_laborales": 0, "horas_laborales": 0.0}
    cursor = start.date()
    end_date = until.date()
    working_days = 0
    total_hours = 0.0
    while cursor <= end_date:
        if cursor.weekday() < 5:
            window_start = datetime.combine(cursor, datetime.min.time()).replace(hour=8)
            window_end = datetime.combine(cursor, datetime.min.time()).replace(hour=18)
            left = max(start, window_start)
            right = min(until, window_end)
            if right > left:
                working_days += 1
                total_hours += (right - left).total_seconds() / 3600
        cursor += timedelta(days=1)
    return {"dias_laborales": working_days, "horas_laborales": round(total_hours, 2)}


def fetch_open_incidents(connection: pyodbc.Connection) -> list[dict[str, object]]:
    query = """
        SELECT i.ticket_mojo_id, i.tipo_terminal, i.descripcion,
               i.fecha_apertura_mojo, i.estado_mojo, s.Nombre AS estacion_nombre,
               NULLIF(LTRIM(RTRIM(COALESCE(u.first_name, '') + CASE WHEN COALESCE(u.last_name, '') = '' THEN '' ELSE ' ' + u.last_name END)), '') AS responsable
        FROM TG.dbo.inv_ter_incidencias AS i
        LEFT JOIN TG.dbo.Estaciones AS s ON s.Codigo = i.estacion_id
        LEFT JOIN TG.dbo.mojo_tickets AS t ON t.id_mojo = i.ticket_mojo_id
        LEFT JOIN TG.dbo.mojo_users AS u ON u.id_mojo = t.assigned_to_id
        WHERE i.fecha_cierre_mojo IS NULL
        ORDER BY i.fecha_apertura_mojo ASC, i.id ASC
    """
    now = datetime.now()
    with connection.cursor() as cursor:
        cursor.execute(query)
        columns = [column[0] for column in cursor.description]
        rows = [dict(zip(columns, row)) for row in cursor.fetchall()]
    for row in rows:
        duration = business_time(row["fecha_apertura_mojo"], now)
        row.update(duration)
        row["dias_habiles"] = duration["dias_laborales"]
    rows.sort(key=lambda row: (-int(row["dias_laborales"]), -float(row["horas_laborales"]), parse_datetime(row["fecha_apertura_mojo"])))
    return rows


def fetch_inventory_summary(connection: pyodbc.Connection) -> list[dict[str, object]]:
    """Obtiene el último inventario de UROVO por estación y su responsable."""
    query = """
        WITH latest_inventory AS (
            SELECT i.id, i.estacion_id, i.fecha_inventario,
                   ROW_NUMBER() OVER (PARTITION BY i.estacion_id ORDER BY i.fecha_inventario DESC, i.id DESC) AS rn
            FROM TG.dbo.inv_ter_inventarios AS i
        ), urovo_detail AS (
            SELECT d.inventario_id, d.funcionando, d.danadas
            FROM TG.dbo.inv_ter_inventario_detalles AS d
            WHERE d.tipo_terminal = 'urovo'
        )
        SELECT s.Codigo AS estacion_codigo, s.Nombre AS estacion_nombre,
               li.fecha_inventario AS inventario_fecha,
               COALESCE(c.terminales_esperadas, 0) AS stock,
               COALESCE(d.danadas, 0) AS danadas,
               COALESCE(d.funcionando, 0) AS funcionando,
               COALESCE(opened.open_count, 0) AS open_count,
               COALESCE(opened.responsable, 'Sin asignar') AS responsable
        FROM TG.dbo.Estaciones AS s
        LEFT JOIN latest_inventory AS li ON li.estacion_id = s.Codigo AND li.rn = 1
        LEFT JOIN urovo_detail AS d ON d.inventario_id = li.id
        LEFT JOIN TG.dbo.inv_ter_configuracion_estacion AS c
               ON c.estacion_id = s.Codigo AND c.tipo_terminal = 'urovo'
        OUTER APPLY (
            SELECT COUNT(*) AS open_count,
                   NULLIF(MAX(LTRIM(RTRIM(COALESCE(u.first_name, '') + CASE WHEN COALESCE(u.last_name, '') = '' THEN '' ELSE ' ' + u.last_name END))), '') AS responsable
            FROM TG.dbo.inv_ter_incidencias AS inc
            LEFT JOIN TG.dbo.mojo_tickets AS t ON t.id_mojo = inc.ticket_mojo_id
            LEFT JOIN TG.dbo.mojo_users AS u ON u.id_mojo = t.assigned_to_id
            WHERE inc.estacion_id = s.Codigo
              AND inc.tipo_terminal = 'urovo'
              AND inc.fecha_cierre_mojo IS NULL
        ) AS opened
        WHERE s.activa = 1 AND s.Codigo NOT IN (0, 4, 20)
        ORDER BY s.Codigo
    """
    with connection.cursor() as cursor:
        cursor.execute(query)
        columns = [column[0] for column in cursor.description]
        return [dict(zip(columns, row)) for row in cursor.fetchall()]


def fetch_valera_inventory(connection: pyodbc.Connection) -> tuple[list[dict[str, object]], list[str]]:
    """Obtiene el último stock y las dañadas de cada valera habilitada por estación."""
    with connection.cursor() as cursor:
        cursor.execute("SELECT valeras_habilitadas FROM TG.dbo.inv_ter_configuracion WHERE id=1")
        settings_row = cursor.fetchone()
        cursor.execute("SELECT codigo, nombre FROM TG.dbo.inv_ter_valeras WHERE activo=1")
        catalog = {str(code).strip().lower(): str(name).strip() for code, name in cursor.fetchall()}

    enabled = {code.strip().lower() for code in str(settings_row[0] if settings_row else "").split(",") if code.strip()}
    codes = [code for code in VALERA_DISPLAY_ORDER if code in enabled and code in catalog]
    codes.extend(sorted(code for code in enabled if code in catalog and code not in codes))
    if not codes:
        return [], []

    marks = ",".join("?" for _ in codes)
    query = f"""
        WITH latest_inventory AS (
            SELECT i.id, i.estacion_id, i.fecha_inventario,
                   ROW_NUMBER() OVER (PARTITION BY i.estacion_id ORDER BY i.fecha_inventario DESC, i.id DESC) AS rn
            FROM TG.dbo.inv_ter_inventarios AS i
        )
        SELECT s.Codigo AS estacion_codigo, s.Nombre AS estacion_nombre,
               li.fecha_inventario AS inventario_fecha,
               v.codigo AS valera_codigo, v.nombre AS valera_nombre,
               COALESCE(c.terminales_esperadas, 0) AS stock,
               COALESCE(d.danadas, 0) AS danadas
        FROM TG.dbo.Estaciones AS s
        CROSS JOIN TG.dbo.inv_ter_valeras AS v
        LEFT JOIN latest_inventory AS li ON li.estacion_id = s.Codigo AND li.rn = 1
        LEFT JOIN TG.dbo.inv_ter_configuracion_estacion AS c
               ON c.estacion_id = s.Codigo AND c.tipo_terminal = v.codigo
        LEFT JOIN TG.dbo.inv_ter_inventario_detalles AS d
               ON d.inventario_id = li.id AND d.tipo_terminal = v.codigo
        WHERE s.activa = 1 AND s.Codigo NOT IN (0, 4, 20)
          AND v.activo = 1 AND v.codigo IN ({marks})
        ORDER BY s.Codigo, v.codigo
    """
    with connection.cursor() as cursor:
        cursor.execute(query, codes)
        columns = [column[0] for column in cursor.description]
        return [dict(zip(columns, row)) for row in cursor.fetchall()], codes


def recipients(name: str) -> list[str]:
    return [address.strip() for address in os.environ.get(name, "daniel.ramirez@totalgas.com").split(",") if address.strip()]


def terminal_report_url() -> str:
    return env_first("TERMINAL_REPORT_URL", default="http://totalgasonline.net:400/operations/terminal_report")


def terminal_incident_report_url() -> str:
    return env_first("TERMINAL_INCIDENT_REPORT_URL", default="http://totalgasonline.net:400/operations/terminal_incident_report")


def terminal_report_link(type_code: str = "", station_code: object = "", date_value: object = "") -> str:
    if date_value:
        date_value = date_value.strftime("%Y-%m-%d") if hasattr(date_value, "strftime") else str(date_value).split(" ", 1)[0]
    params = {key: value for key, value in (("tab", "inventories"), ("type", type_code), ("station", station_code), ("date", date_value)) if str(value).strip()}
    base = terminal_report_url()
    return base + (("&" if "?" in base else "?") + urlencode(params) if params else "")


def terminal_incident_report_link(station_code: object = "", date_value: object = "") -> str:
    if date_value:
        date_value = date_value.strftime("%Y-%m-%d") if hasattr(date_value, "strftime") else str(date_value).split(" ", 1)[0]
    params = {key: value for key, value in (("station", station_code), ("as_of", date_value)) if str(value).strip()}
    base = terminal_incident_report_url()
    return base + (("&" if "?" in base else "?") + urlencode(params) if params else "")


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
            f'<td style="padding:11px 10px;text-align:center;color:#125ca8;font-weight:800;white-space:nowrap">{int(row["dias_laborales"])} días / {float(row["horas_laborales"]):.2f} h</td>'
            f'<td style="padding:11px 10px;white-space:nowrap"><span style="display:inline-block;background:#eaf3fb;color:#125ca8;padding:4px 9px;border-radius:12px;font-weight:700;font-size:12px">{escape(state)}</span></td>'
            "</tr>"
        )
    sent = sent_at.strftime("%d/%m/%Y %H:%M")
    return f"""<!doctype html><html lang="es"><body style="margin:0;padding:18px;background:#f4f8fc;font-family:Arial,sans-serif;color:#304a61">
<div style="max-width:1180px;margin:0 auto;border:1px solid #dbe7f2;border-radius:10px;overflow:hidden;background:#ffffff">
<div style="padding:22px 24px;background:#125ca8;color:#ffffff"><div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;opacity:.82">TotalGas · Operaciones</div><h2 style="margin:7px 0 0;font-size:22px;font-weight:700">Incidencias abiertas — {escape(category)}</h2></div>
<div style="padding:16px 24px 12px;background:#f4f8fc;color:#52616f;font-size:13px">Reporte enviado el <strong style="color:#304a61">{sent}</strong>. Ordenado de mayor a menor por días laborales y horas laborales (08:00–18:00, lunes a viernes).</div>
<div style="padding:0 14px 16px;overflow-x:auto"><table border="0" cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%;font-size:13px;min-width:850px">
<thead><tr style="background:#e5f0fa;color:#174a78;text-align:left"><th style="padding:11px 10px;font-size:11px;text-transform:uppercase">Ticket Mojo</th><th style="padding:11px 10px;font-size:11px;text-transform:uppercase">Tipo de terminal</th><th style="padding:11px 10px;font-size:11px;text-transform:uppercase">Estación</th><th style="padding:11px 10px;font-size:11px;text-transform:uppercase">Descripción</th><th style="padding:11px 10px;font-size:11px;text-transform:uppercase">Apertura</th><th style="padding:11px 10px;font-size:11px;text-transform:uppercase;text-align:center">Días laborales / Horas</th><th style="padding:11px 10px;font-size:11px;text-transform:uppercase">Estado</th></tr></thead>
<tbody>{''.join(body) or '<tr><td colspan="7" style="padding:16px;text-align:center;color:#687887">No hay incidencias abiertas.</td></tr>'}</tbody></table>
</div></div><p style="max-width:1180px;margin:12px auto;color:#8a98a5;font-size:11px">Mensaje generado automáticamente por el módulo de Inventario de terminales.</p></body></html>"""


def render_text(rows: Iterable[dict[str, object]], sent_at: datetime) -> str:
    lines = [f"Incidencias de terminales abiertas ({sent_at:%d/%m/%Y %H:%M})", ""]
    for row in rows:
        lines.append(
            f"#{row['ticket_mojo_id']} | {row.get('tipo_terminal', '—')} | "
            f"{row.get('estacion_nombre', '—')} | {row.get('descripcion', '—')} | "
            f"{row['dias_laborales']} días / {row['horas_laborales']:.2f} h | {spanish_status(row.get('estado_mojo'))} | "
            f"{MOJO_TICKET_URL.format(row['ticket_mojo_id'])}"
        )
    return "\n".join(lines)


def render_valera_html(inventory_rows: list[dict[str, object]], codes: list[str], incidents: list[dict[str, object]], sent_at: datetime) -> str:
    """Renderiza el control de valeras con columnas agrupadas y detalle desplegable."""
    labels = {}
    for row in inventory_rows:
        labels[str(row["valera_codigo"]).lower()] = str(row.get("valera_nombre") or row["valera_codigo"])
    incidents_by_station: dict[str, list[dict[str, object]]] = {}
    for incident in incidents:
        incidents_by_station.setdefault(str(incident.get("estacion_nombre") or "Sin estación"), []).append(incident)

    station_rows: dict[str, dict[str, object]] = {}
    for row in inventory_rows:
        station = str(row.get("estacion_nombre") or "Sin estación")
        station_rows.setdefault(station, {"codigo": row.get("estacion_codigo"), "date": row.get("inventario_fecha"), "types": {}})
        station_rows[station]["types"][str(row["valera_codigo"]).lower()] = row

    group_headers = "".join(
        f'<th colspan="3" style="padding:5px 8px;background:#28587c;color:#fff;font-size:20px;text-align:center">{escape(labels.get(code, code))}</th>'
        for code in codes
    )
    sub_headers = "".join(
        '<th style="padding:5px 8px;background:#377dc5;color:#fff">Stock</th>'
        '<th style="padding:5px 8px;background:#377dc5;color:#fff">Dañadas</th>'
        '<th style="padding:5px 8px;background:#377dc5;color:#fff">Cobertura</th>'
        for _ in codes
    )
    body = []
    for index, (station, station_data) in enumerate(station_rows.items()):
        values = []
        for code in codes:
            row = station_data["types"].get(code, {})
            stock = int(row.get("stock") or 0)
            damaged = int(row.get("danadas") or 0)
            working = max(0, stock - damaged)
            coverage_value = working / stock * 100 if stock else None
            coverage = f"{coverage_value:.0f}%" if coverage_value is not None else "—"
            if coverage_value is None:
                coverage_color = "#f1f3f5"
                coverage_text = "#687887"
            elif coverage_value >= 100:
                coverage_color = "#c6efce"
                coverage_text = "#006100"
            elif coverage_value >= 90:
                coverage_color = "#ffeb9c"
                coverage_text = "#9c6500"
            elif coverage_value > 80:
                coverage_color = "#f4b183"
                coverage_text = "#7f3f00"
            else:
                coverage_color = "#ffc7ce"
                coverage_text = "#9c0006"
            link = escape(terminal_report_link(code, station_data["codigo"], row.get("inventario_fecha")), quote=True)
            values.append(f'<td style="padding:4px 8px;text-align:center"><a href="{link}" style="color:#125ca8;font-weight:700;text-decoration:none">{stock}</a></td><td style="padding:4px 8px;text-align:center">{damaged}</td><td style="padding:4px 8px;text-align:center;background:{coverage_color};color:{coverage_text};font-weight:700">{coverage}</td>')
        station_incidents = incidents_by_station.get(station, [])
        incident_detail = "".join(
            f'<tr><td style="padding:5px 7px">#{escape(str(item.get("ticket_mojo_id") or "—"))}</td><td style="padding:5px 7px">{escape(str(item.get("tipo_terminal") or "—"))}</td><td style="padding:5px 7px">{escape(str(item.get("descripcion") or "—"))}</td><td style="padding:5px 7px">{escape(str(item.get("responsable") or "Sin asignar"))}</td><td style="padding:5px 7px;white-space:nowrap">{int(item.get("dias_laborales") or 0)} días / {float(item.get("horas_laborales") or 0):.2f} h</td></tr>'
            for item in station_incidents
        ) or '<tr><td colspan="5" style="padding:7px;color:#687887">No hay incidencias abiertas.</td></tr>'
        details = f'<details><summary style="cursor:pointer;color:#125ca8;font-weight:700">Ver incidencias ({len(station_incidents)})</summary><table style="margin-top:8px;border-collapse:collapse;width:100%;font-size:11px"><thead><tr style="background:#e5f0fa"><th style="padding:5px 7px;text-align:left">Ticket Mojo</th><th style="padding:5px 7px;text-align:left">Tipo</th><th style="padding:5px 7px;text-align:left">Descripción</th><th style="padding:5px 7px;text-align:left">Responsable</th><th style="padding:5px 7px;text-align:left">Días / Horas</th></tr></thead><tbody>{incident_detail}</tbody></table></details>'
        background = "#ffffff" if index % 2 == 0 else "#dff3fb"
        station_link = escape(terminal_incident_report_link(station_data["codigo"], sent_at), quote=True)
        body.append(f'<tr style="background:{background};border-bottom:1px solid #9bd5e8"><td style="padding:4px 8px;color:#123f66;min-width:190px"><a href="{station_link}" style="color:#125ca8;font-weight:700;text-decoration:none">{escape(station)}</a></td>{"".join(values)}</tr>')

    sent = sent_at.strftime("%d/%m/%Y %H:%M")
    return f'''<!doctype html><html lang="es"><body style="margin:0;padding:6px;background:#fff;font-family:Arial,sans-serif;color:#173b59"><div style="max-width:1800px;margin:0 auto"><div style="background:#28587c;color:#fff;text-align:center;padding:4px 8px;font-size:20px;font-weight:700">Control Valeras</div><p style="font-size:12px;color:#687887">Reporte enviado el {sent}. Haz clic en una estación o en el valor de Stock de la valera que deseas analizar; el enlace abrirá directamente su reporte.</p><table style="border-collapse:collapse;width:100%;font-size:12px;border:1px solid #9bd5e8"><thead><tr><th rowspan="2" style="padding:5px 8px;background:#377dc5;color:#fff">Estación</th>{group_headers}</tr><tr>{sub_headers}</tr></thead><tbody>{"".join(body) or '<tr><td colspan="99" style="padding:16px;text-align:center">No hay estaciones disponibles.</td></tr>'}</tbody></table></div></body></html>'''


def render_internal_html(summary: list[dict[str, object]], rows: list[dict[str, object]], sent_at: datetime) -> str:
    summary_rows = []
    total_stock = total_damaged = total_working = total_missing = 0
    incidents_by_station: dict[str, list[dict[str, object]]] = {}
    for incident in rows:
        incidents_by_station.setdefault(str(incident.get("estacion_nombre") or "Sin estación"), []).append(incident)
    for index, row in enumerate(summary):
        stock = int(row.get("stock") or 0)
        damaged = int(row.get("danadas") or 0)
        working = max(0, stock - damaged)
        missing = max(0, damaged - int(row.get("open_count") or 0))
        coverage_value = working / stock * 100 if stock else None
        coverage = f"{coverage_value:.0f}%" if coverage_value is not None else "—"
        if coverage_value is None:
            coverage_color, coverage_text = "#f1f3f5", "#687887"
        elif coverage_value >= 100:
            coverage_color, coverage_text = "#c6efce", "#006100"
        elif coverage_value >= 90:
            coverage_color, coverage_text = "#ffeb9c", "#9c6500"
        elif coverage_value > 80:
            coverage_color, coverage_text = "#f4b183", "#7f3f00"
        else:
            coverage_color, coverage_text = "#ffc7ce", "#9c0006"
        total_stock += stock; total_damaged += damaged; total_working += working; total_missing += missing
        background = "#ffffff" if index % 2 == 0 else "#dff3fb"
        station = str(row.get("estacion_nombre") or "Sin estación")
        station_incidents = incidents_by_station.get(station, [])
        incident_detail = "".join(
            f'<tr><td style="padding:5px 7px">#{escape(str(item.get("ticket_mojo_id") or "—"))}</td><td style="padding:5px 7px">{escape(str(item.get("tipo_terminal") or "—"))}</td><td style="padding:5px 7px">{escape(str(item.get("descripcion") or "—"))}</td><td style="padding:5px 7px">{escape(str(item.get("responsable") or "Sin asignar"))}</td><td style="padding:5px 7px;white-space:nowrap">{int(item.get("dias_laborales") or 0)} días / {float(item.get("horas_laborales") or 0):.2f} h</td></tr>'
            for item in station_incidents
        ) or '<tr><td colspan="5" style="padding:7px;color:#687887">No hay incidencias abiertas.</td></tr>'
        details = f'<details><summary style="cursor:pointer;color:#125ca8;font-weight:700">Ver incidencias ({len(station_incidents)})</summary><table style="margin-top:8px;border-collapse:collapse;width:100%;font-size:11px"><thead><tr style="background:#e5f0fa"><th style="padding:5px 7px;text-align:left">Ticket Mojo</th><th style="padding:5px 7px;text-align:left">Tipo</th><th style="padding:5px 7px;text-align:left">Descripción</th><th style="padding:5px 7px;text-align:left">Responsable</th><th style="padding:5px 7px;text-align:left">Días / Horas</th></tr></thead><tbody>{incident_detail}</tbody></table></details>'
        station_link = escape(terminal_incident_report_link(row.get("estacion_codigo"), sent_at), quote=True)
        summary_rows.append(
            f'<tr style="background:{background};border-bottom:1px solid #9bd5e8">'
            f'<td style="padding:5px 8px;color:#123f66"><a href="{station_link}" style="color:#125ca8;font-weight:700;text-decoration:none">{escape(station)}</a></td>'
            f'<td style="padding:5px 8px;text-align:center">{stock}</td><td style="padding:5px 8px;text-align:center">{damaged}</td>'
            f'<td style="padding:5px 8px;text-align:center">{working}</td><td style="padding:5px 8px;text-align:center;color:#d71920;font-weight:700">{missing}</td>'
            f'<td style="padding:5px 8px;text-align:center;background:{coverage_color};color:{coverage_text};font-weight:700">{coverage}</td></tr>'
        )
    total_coverage = f"{(total_working / total_stock * 100):.0f}%" if total_stock else "—"
    summary_rows.append(f'<tr style="background:#ffffff;font-weight:700;border-top:2px solid #1583bd"><td style="padding:5px 8px">Total</td><td style="padding:5px 8px;text-align:center">{total_stock}</td><td style="padding:5px 8px;text-align:center">{total_damaged}</td><td style="padding:5px 8px;text-align:center">{total_working}</td><td style="padding:5px 8px;text-align:center;color:#d71920">{total_missing}</td><td style="padding:5px 8px;text-align:center">{total_coverage}</td></tr>')

    grouped: dict[str, list[dict[str, object]]] = {}
    for row in rows:
        grouped.setdefault(str(row.get("responsable") or "Sin asignar"), []).append(row)
    assigned_rows = []
    for responsable, assigned_tickets in grouped.items():
        between_7_14 = sum(1 for row in assigned_tickets if 7 <= int(row["dias_laborales"]) <= 14)
        over_two_weeks = sum(1 for row in assigned_tickets if int(row["dias_laborales"]) > 14)
        urgency = over_two_weeks / len(assigned_tickets) * 100 if assigned_tickets else 0
        assigned_rows.append((urgency, responsable, between_7_14, over_two_weeks))
    assigned_rows.sort(key=lambda item: (-item[0], item[1].casefold()))
    assigned_table = []
    for urgency, responsable, between_7_14, over_two_weeks in assigned_rows:
        critical = urgency >= 70
        background = "#fff0f0" if critical else "#fffaf0"
        color = "#d71920" if critical else "#a56a00"
        assigned_table.append(f'<tr style="background:{background};color:{color}"><td style="padding:11px 10px">{escape(responsable)}</td><td style="padding:11px 10px;text-align:center">{between_7_14}</td><td style="padding:11px 10px;text-align:center">{over_two_weeks}</td><td style="padding:11px 10px;text-align:center">{urgency:.2f}%</td></tr>')
    sent = sent_at.strftime("%d/%m/%Y %H:%M")
    return f'''<!doctype html><html lang="es"><body style="margin:0;padding:18px;background:#ffffff;font-family:Arial,sans-serif;color:#173b59"><div style="max-width:900px;margin:0 auto"><div style="background:#28587c;color:#fff;text-align:center;padding:4px 8px;font-size:19px;font-weight:700">UROVO — Control de Inventario de Terminales</div><p style="font-size:12px;color:#687887">Reporte enviado el {sent}. El stock corresponde a la configuración vigente; funcionando = stock menos dañadas. Haz clic en el nombre de una estación para abrir directamente su reporte y consultar UROVO/Verifone.</p><table style="border-collapse:collapse;width:100%;font-size:12px;border:1px solid #9bd5e8"><thead><tr style="background:#377dc5;color:#fff"><th style="padding:7px 8px;text-align:left">Estación</th><th style="padding:7px 8px">Stock</th><th style="padding:7px 8px">Dañadas</th><th style="padding:7px 8px">Funcionando</th><th style="padding:7px 8px">Faltante</th><th style="padding:7px 8px">Cobertura</th></tr></thead><tbody>{"".join(summary_rows)}</tbody></table><h2 style="margin:24px 0 8px;color:#28587c;font-size:18px">Estadísticas por Asignado</h2><table style="border-collapse:collapse;width:100%;font-size:12px"><thead><tr style="border-bottom:1px solid #d8d8d8"><th style="padding:9px 10px;text-align:left">Asignado a</th><th style="padding:9px 10px">Tickets Sin Atención Entre 7 y 14 Días</th><th style="padding:9px 10px">Tickets Sin Atención Con Mas De Dos Semanas</th><th style="padding:9px 10px">% Urgencia</th></tr></thead><tbody>{"".join(assigned_table) or '<tr><td colspan="4" style="padding:16px;text-align:center">No hay tickets abiertos.</td></tr>'}</tbody></table></div></body></html>'''


def send_internal_email(to: list[str], summary: list[dict[str, object]], rows: list[dict[str, object]], sent_at: datetime, dry_run: bool) -> None:
    if not to:
        raise RuntimeError("La lista de destinatarios está vacía")
    subject = f"UROVO — Control de Inventario de Terminales — {sent_at:%d/%m/%Y}"
    message = EmailMessage()
    message["From"] = env_first("SMTP_FROM", "EMAIL_FROM", default="no-reply@totalgas.com")
    message["To"] = ", ".join(to)
    message["Subject"] = subject
    message.set_content("Reporte de control de inventario UROVO y tickets abiertos por responsable.")
    message.add_alternative(render_internal_html(summary, rows, sent_at), subtype="html")
    if dry_run:
        print(f"DRY-RUN: {subject} -> {message['To']} ({len(rows)} tickets)")
        return
    host = env_first("SMTP_HOST", "EMAIL_SMTP_HOST", default="smtp-relay.gmail.com")
    port = int(env_first("SMTP_PORT", "EMAIL_SMTP_PORT", default="587"))
    with smtplib.SMTP(host, port, timeout=30) as smtp:
        smtp.ehlo(); smtp.starttls(); smtp.ehlo(); smtp.send_message(message)


def send_email(to: list[str], rows: list[dict[str, object]], inventory_rows: list[dict[str, object]], valera_codes: list[str], sent_at: datetime, category: str, dry_run: bool) -> None:
    if not to:
        raise RuntimeError("La lista de destinatarios está vacía")
    subject = f"Incidencias abiertas de {category} — {sent_at:%d/%m/%Y}"
    message = EmailMessage()
    message["From"] = env_first("SMTP_FROM", "EMAIL_FROM", default="no-reply@totalgas.com")
    message["To"] = ", ".join(to)
    message["Subject"] = subject
    message.set_content(render_text(rows, sent_at))
    message.add_alternative(render_valera_html(inventory_rows, valera_codes, rows, sent_at), subtype="html")
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
            summary = fetch_inventory_summary(connection)
            valera_inventory, valera_codes = fetch_valera_inventory(connection)
        if not summary and not rows and not valera_inventory:
            print("No hay inventario ni incidencias abiertas; no se envía correo.")
            return 0
        sent_at = datetime.now()
        internal_rows = [row for row in rows if str(row.get("tipo_terminal", "")).strip().lower() in {"urovo", "verifone"}]
        valera_rows = [row for row in rows if str(row.get("tipo_terminal", "")).strip().lower() not in {"urovo", "verifone"}]
        send_internal_email(recipients("TERMINAL_EMAIL_TO_1"), summary, internal_rows, sent_at, args.dry_run)
        if valera_inventory:
            send_email(recipients("TERMINAL_EMAIL_TO_2"), valera_rows, valera_inventory, valera_codes, sent_at, "valeras", args.dry_run)
        print(f"Se envió el control de inventario: {len(internal_rows)} tickets Urovo/Verifone y {len(valera_rows)} tickets de valeras.")
        return 0
    except Exception as exc:  # el programador del servidor verá un código distinto de cero
        print(f"ERROR: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
