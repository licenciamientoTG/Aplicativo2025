"""Sync Santander cash deposits to existing or account-specific Sheets tabs."""

from __future__ import annotations

import calendar
import json
import logging
import os
import re
from datetime import date, datetime, timedelta
from decimal import Decimal
from pathlib import Path

import pyodbc
from dotenv import load_dotenv
from google.oauth2.service_account import Credentials
from googleapiclient.discovery import build


load_dotenv(Path(__file__).with_name(".env"))

SCOPES = ["https://www.googleapis.com/auth/spreadsheets"]
STATE_FILE = Path(__file__).with_name("sync_depositos_state.json")
MONTHS = {
    "ENERO": 1, "FEBRERO": 2, "MARZO": 3, "ABRIL": 4, "MAYO": 5, "JUNIO": 6,
    "JULIO": 7, "AGOSTO": 8, "SEPTIEMBRE": 9, "SETIEMBRE": 9, "OCTUBRE": 10,
    "NOVIEMBRE": 11, "DICIEMBRE": 12,
}
PRIMARY_ACCOUNT_SUFFIXES = ("8504", "4409", "4547", "3678", "8214", "8492", "4412", "4777", "4669", "4638")
FOREIGN_ACCOUNT_TABS = {
    "3281": "A 3281",
    "8837": "8837",
    "8520": "A 8520",
    "7291": "C 7291",
    "2570": "C 2570",
    "7533": "C 7533",
    "2627": "2627 USD",
    "5247": "C 5247",
    "7604": "FG 7604",
    "0031": "BTSA 0031",
    "1785": "TSA 1785",
}
DG_ACCOUNT_TABS = {
    "369": "P 3698",
    # "8973": "8973 HA",  # Pausada temporalmente: no sincronizar esta cuenta.
    # "0741": "DG 0741",  # Pausada temporalmente: no sincronizar esta cuenta.
    # "2470": "DG 2470",  # Pausada temporalmente: no sincronizar esta cuenta.
}
# Los archivos de septiembre eran nuevos y necesitan una carga inicial aunque
# sus movimientos ya estén registrados en el estado de sincronización.
INITIAL_LOAD_WORKBOOK_IDS = {
    #"17XjMlk1JiLScnAgrbeE3FU3I112XgsU0paEimmRrCPA",
    #"1thze-fguB2VrsFmpDK0Z-Oiz9az0xLaLTvgiCcMHH5s",
    #"1mnZZFjhZ39E8k2OZ7aYw4Bg5AhwUNxan_YYQzC9QYCQ",
    # Los archivos de octubre se incorporan como nuevas cargas iniciales.
    "1oM5HxiEvobNwAgzyPDtu-1mpqWvLFrgq1D_eTHtp0ug",
    "1bDy8u01Hxc2JPs9eorQK3D6pESlbJdC9YZIJnRHOBIk",
    "1Z83RePkLXjzuCvUGKdYtmMWeZA16ZLkf6ASWrKzR-Bg",
}
OCTOBER_CLEANUP_WORKBOOK_IDS = {
    "1oM5HxiEvobNwAgzyPDtu-1mpqWvLFrgq1D_eTHtp0ug",
    "1bDy8u01Hxc2JPs9eorQK3D6pESlbJdC9YZIJnRHOBIk",
    "1Z83RePkLXjzuCvUGKdYtmMWeZA16ZLkf6ASWrKzR-Bg",
}
ACCOUNT_BANKS = {
    "8837": "BANKAOOL",
    "8520": "BANORTE",
    "7604": "BANORTE",
    "0031": "BANORTE",
    "1785": "BANORTE",
    "8973": "SANTANDER",
    "0741": "BANORTE",
    "2470": "BANORTE",
}
# Account-specific auxiliary columns found in the DG reconciliation.
ACCOUNT_LAYOUT_OVERRIDES = {
    "8973": "SANTANDER_ID",
    "2470": "BANORTE_WITH_STATION",
}
MOVEMENT_HEADERS = [
    "Fecha", "Hora", "Sucursal", "Descripci\u00f3n", "Importe Cargo",
    "Importe Abono", "Saldo", "Referencia",
]
SANTANDER_HEADERS = [
    "Fecha", "Hora", "Sucursal", "Descripcion", "Importe Cargo", "Importe Abono",
    "Saldo", "Referencia", "Concepto", "Concepto", "", "",
]
BANORTE_HEADERS = [
    "FECHA DE OPERACION", "FECHA", "REFERENCIA", "DESCRIPCION", "COD. TRANSAC",
    "SUCURSAL", "DEPOSITOS", "RETIROS", "SALDO", "MOVIMIENTO", "DESCRIPCION DETALLADA",
    "CHEQUE", "",
]
BANKAOOL_HEADERS = [
    "Fecha", "Descripción", "Referencia", "Monto", "Saldo", "Clave Rastreo", "Comprobante Electrónico",
]

# Exact headers and auxiliary columns from the original Bankaool source tab.
BANKAOOL_HEADERS = [
    "Fecha", "Descripción", "Referencia", "Monto", "Saldo", "Clave Rastreo", "Comprobante Electrónico",
    "", "", "", "", "",
]

# Keep the labels exactly as they appear in the original Bankaool sheet.
BANKAOOL_HEADERS = [
    "Fecha", "Descripción", "Referencia", "Monto", "Saldo", "Clave Rastreo", "Comprobante Electrónico",
    "", "", "", "", "",
]


def setting(name: str, required: bool = True) -> str:
    value = os.getenv(name, "").strip()
    if required and not value:
        raise RuntimeError(f"Falta configurar {name} en el archivo .env")
    return value


def month_from_title(title: str) -> tuple[date, date]:
    match = re.search(r"\b(" + "|".join(MONTHS) + r")\s+(20\d{2})\b", title.upper())
    if not match:
        raise ValueError("No se encontro un mes y anio como 'SEPTIEMBRE 2026' en el titulo")
    year, month = int(match.group(2)), MONTHS[match.group(1)]
    start = date(year, month, 1)
    return start, date(year, month, calendar.monthrange(year, month)[1]) + timedelta(days=1)


def normalized_title(title: str) -> str:
    """Compares titles without incidental leading or repeated whitespace."""
    return " ".join(title.split())


def sql_connection() -> pyodbc.Connection:
    driver = setting("SQL_DRIVER")
    encrypt = setting("SQL_ENCRYPT", required=False) or "no"
    trust = setting("SQL_TRUST_SERVER_CERTIFICATE", required=False) or "yes"
    connection_string = (
        f"DRIVER={{{driver}}};SERVER={setting('SQL_SERVER')};DATABASE={setting('SQL_DATABASE')};"
        f"UID={setting('SQL_USER')};PWD={setting('SQL_PASSWORD')};"
        f"Encrypt={encrypt};TrustServerCertificate={trust};"
    )
    return pyodbc.connect(connection_string, timeout=20)


def load_sync_state() -> dict[str, object]:
    """Loads record IDs seen in the last successful executions."""
    if not STATE_FILE.exists():
        return {"version": 1, "snapshots": {}}
    try:
        state = json.loads(STATE_FILE.read_text(encoding="utf-8"))
        if isinstance(state, dict) and isinstance(state.get("snapshots"), dict):
            return state
    except (OSError, json.JSONDecodeError):
        logging.exception("No se pudo leer el estado anterior; se creara una linea base nueva")
    return {"version": 1, "snapshots": {}}


def save_sync_state(state: dict[str, object]) -> None:
    temp_file = STATE_FILE.with_suffix(".tmp")
    temp_file.write_text(json.dumps(state, ensure_ascii=False, indent=2), encoding="utf-8")
    temp_file.replace(STATE_FILE)


def new_rows_since_last_sync(
    state: dict[str, object], workbook_key: str, accounts: dict[str, dict[str, object]]
) -> tuple[dict[str, list[list[object]]], int, bool]:
    """Returns only movements whose movimiento_id has never been written.

    The saved IDs are cumulative, rather than only a snapshot from the previous
    execution. This preserves manual Sheet edits: existing rows are never
    rewritten, and an old movement cannot be appended a second time.
    """
    snapshots = state["snapshots"]
    previous = snapshots.get(workbook_key)
    current = {account: list(payload["record_ids"]) for account, payload in accounts.items()}
    seen_by_workbook = state.setdefault("seen_ids", {})
    known_by_account = seen_by_workbook.get(workbook_key)

    # Existing state files contain snapshots from past successful runs. Migrate
    # them to the permanent index on the first run after this update.
    if known_by_account is None:
        if previous is None:
            seen_by_workbook[workbook_key] = current
            snapshots[workbook_key] = current
            return {account: [] for account in accounts}, 0, True
        known_by_account = {account: list(record_ids) for account, record_ids in previous.items()}
        seen_by_workbook[workbook_key] = known_by_account

    new_rows: dict[str, list[list[object]]] = {}
    for account, payload in accounts.items():
        known_ids = set(known_by_account.get(account, []))
        new_rows[account] = [
            row_values
            for record_id, row_values in zip(payload["record_ids"], payload["rows"])
            if record_id not in known_ids
        ]
        known_by_account[account] = list(known_ids | set(payload["record_ids"]))
    snapshots[workbook_key] = current
    return new_rows, sum(len(rows) for rows in new_rows.values()), False


def sheet_value(value: object) -> object:
    if value is None:
        return ""
    if isinstance(value, Decimal):
        return float(value)
    if isinstance(value, date):
        return value.isoformat()
    if isinstance(value, bytes):
        return value.hex()
    return str(value)


def sheet_date(value: object) -> str:
    """Writes every source date in the spreadsheet's dd/mm/yyyy convention."""
    if value is None:
        return ""
    if isinstance(value, datetime):
        value = value.date()
    if isinstance(value, date):
        return value.strftime("%d/%m/%Y")

    value_text = str(value).strip()
    if not value_text:
        return ""
    try:
        return date.fromisoformat(value_text[:10]).strftime("%d/%m/%Y")
    except ValueError:
        return value_text


def text_cell(value: object) -> str:
    """Force text so reference numbers retain their leading zeros."""
    value = sheet_value(value)
    return "" if value == "" else f"'{value}"


def numeric_cell(value: object) -> object:
    """Writes numeric references as numbers, intentionally dropping leading zeros."""
    value = sheet_value(value)
    if value == "":
        return ""
    try:
        return int(value)
    except ValueError:
        return value


def cell_datetime(day: object, time: object) -> str:
    """Formats Bankaool's single datetime source column from the normalized database fields."""
    date_part = sheet_date(day)
    time_part = sheet_value(time)
    return date_part if not time_part else f"{date_part} {time_part}"


def layout_definition(bank: object, account: str | None = None) -> tuple[list[str], str]:
    normalized_bank = ACCOUNT_LAYOUT_OVERRIDES.get(account or "", sheet_value(bank).upper())
    if normalized_bank == "BANKAOOL_PLAIN":
        return BANKAOOL_HEADERS[:7], "L"
    if normalized_bank == "SANTANDER_ID":
        return SANTANDER_HEADERS, "L"
    if normalized_bank == "SANTANDER_PLAIN":
        return SANTANDER_HEADERS[:10], "L"
    if normalized_bank == "BANORTE_WITH_STATION":
        return BANORTE_HEADERS[:12], "L"
    if normalized_bank == "BANORTE":
        return BANORTE_HEADERS[:12], "L"
    if normalized_bank == "BANKAOOL":
        return BANKAOOL_HEADERS, "L"
    return SANTANDER_HEADERS, "L"


def account_layout(bank: object, row: object, account: str | None = None) -> tuple[list[str], str, list[object]]:
    """Recreates the original raw-bank schemas used by the existing pivot tables."""
    normalized_bank = sheet_value(bank).upper()
    headers, end_column = layout_definition(bank, account)
    if normalized_bank == "BANORTE":
        values = [
            sheet_date(row.fecha_operacion) or sheet_date(row.fecha),
            sheet_date(row.fecha),
            text_cell(row.referencia),
            sheet_value(row.descripcion),
            sheet_value(row.clave_trans),
            sheet_value(row.sucursal),
            sheet_value(row.abono),
            sheet_value(row.cargo),
            sheet_value(row.saldo),
            sheet_value(row.secuencia),
            sheet_value(row.descripcion_larga),
            sheet_value(row.cheque) or "-",
            "",
        ]
        return headers, end_column, values[:len(headers)]
    if normalized_bank == "BANKAOOL":
        amount = row.abono if row.abono not in (None, 0) else -row.cargo if row.cargo not in (None, 0) else 0
        values = [
            cell_datetime(row.fecha, row.hora),
            sheet_value(row.descripcion),
            numeric_cell(row.referencia) if account == "369" else text_cell(row.referencia),
            sheet_value(amount),
            sheet_value(row.saldo),
            text_cell(row.clave_rastreo),
            "",
            "", "", "", "", "", "",
        ]
        return headers, end_column, values[:len(headers)]
    values = [
        sheet_date(row.fecha),
        sheet_value(row.hora),
        sheet_value(row.sucursal),
        sheet_value(row.descripcion),
        sheet_value(row.cargo),
        sheet_value(row.abono),
        sheet_value(row.saldo),
        text_cell(row.referencia),
            sheet_value(row.concepto),
            sheet_value(row.descripcion_larga),
            "", "", "",
    ]
    return headers, end_column, values[:len(headers)]


def deposits_for_period(
    start: date, end: date, account_terms: tuple[str, ...], match_mode: str
) -> dict[str, dict[str, object]]:
    if match_mode == "right":
        account_filter = "RIGHT(CONVERT(varchar(100), cuenta), 4) IN (" + ",".join("?" for _ in account_terms) + ")"
        account_params: tuple[object, ...] = account_terms
    elif match_mode == "suffix":
        account_filter = "(" + " OR ".join(
            "RIGHT(CONVERT(varchar(100), cuenta), ?) = ?" for _ in account_terms
        ) + ")"
        account_params = tuple(value for term in account_terms for value in (len(term), term))
    elif match_mode == "like":
        account_filter = "(" + " OR ".join("CONVERT(varchar(100), cuenta) LIKE ?" for _ in account_terms) + ")"
        account_params = tuple(f"%{term}%" for term in account_terms)
    else:
        raise ValueError(f"Modo de cuenta no soportado: {match_mode}")

    sql = f"""
        SELECT
            id AS movimiento_id,
            cuenta,
            banco,
            fecha_operacion,
            CONVERT(date, fecha) AS fecha,
            CONVERT(varchar(20), hora) AS hora,
            sucursal,
            descripcion,
            cargo,
            abono,
            saldo,
            referencia,
            concepto,
            descripcion_larga,
            clave_trans,
            secuencia,
            clave_rastreo,
            NULL AS cheque
        FROM TG.dbo.movimientos_bancarios
        WHERE {account_filter}
          AND fecha >= ? AND fecha < ?
        ORDER BY fecha ASC, id ASC;
    """
    params = (*account_params, start, end)
    with sql_connection() as connection:
        cursor = connection.cursor()
        cursor.execute(sql, params)
        rows = cursor.fetchall()

    values: dict[str, dict[str, object]] = {
        term: {"headers": None, "end_column": None, "rows": [], "record_ids": []}
        for term in account_terms
    }
    for row in rows:
        account = sheet_value(row.cuenta)
        if match_mode == "right":
            matching_terms = (account[-4:],)
        elif match_mode == "suffix":
            matching_terms = tuple(term for term in account_terms if account.endswith(term))
        else:
            matching_terms = tuple(term for term in account_terms if term in account)
        for term in matching_terms:
            headers, end_column, row_values = account_layout(row.banco, row, term)
            payload = values[term]
            if payload["headers"] is None:
                payload["headers"] = headers
                payload["end_column"] = end_column
            elif payload["headers"] != headers:
                raise RuntimeError(f"La cuenta {term} tiene movimientos de bancos con estructuras distintas")
            payload["rows"].append(row_values)
            payload["record_ids"].append(str(row.movimiento_id))
    for term, payload in values.items():
        if payload["headers"] is None:
            payload["headers"], payload["end_column"] = layout_definition(
                ACCOUNT_BANKS.get(term, "SANTANDER"), term
            )
    return values


def resolve_account_sheets(
    sheets_service, spreadsheet_id: str, sheets: list[dict], account_tabs: dict[str, str], create_if_missing: bool
) -> dict[str, tuple[int, str]]:
    properties = [sheet["properties"] for sheet in sheets]
    resolved: dict[str, tuple[int, str]] = {}
    missing: list[tuple[str, str]] = []
    for account, requested_title in account_tabs.items():
        exact = next((item for item in properties if item["title"] == requested_title), None)
        if exact:
            resolved[account] = (exact["sheetId"], exact["title"])
            continue
        candidates = [item for item in properties if account in item["title"]]
        if not create_if_missing and len(candidates) == 1:
            resolved[account] = (candidates[0]["sheetId"], candidates[0]["title"])
        elif not create_if_missing and len(candidates) > 1:
            names = ", ".join(item["title"] for item in candidates)
            raise RuntimeError(f"Hay varias pestanas que contienen '{account}': {names}")
        elif create_if_missing:
            missing.append((account, requested_title))
        else:
            raise RuntimeError(f"No existe la pestana requerida para la cuenta '{account}'")

    if missing:
        response = sheets_service.spreadsheets().batchUpdate(
            spreadsheetId=spreadsheet_id,
            body={"requests": [{"addSheet": {"properties": {"title": title}}} for _, title in missing]},
        ).execute()
        for (account, _), reply in zip(missing, response["replies"]):
            created = reply["addSheet"]["properties"]
            resolved[account] = (created["sheetId"], created["title"])
    return resolved


def write_workbook_movements(
    sheets_service,
    spreadsheet_id: str,
    resolved_sheets: dict[str, tuple[int, str]],
    accounts: dict[str, dict[str, object]],
    new_rows: dict[str, list[list[object]]],
) -> None:
    value_updates = []
    write_positions: dict[str, tuple[int, int]] = {}
    accounts_to_write = [
        (account, title)
        for account, (_, title) in resolved_sheets.items()
        if new_rows[account]
    ]
    existing_values_by_account: dict[str, list[list[object]]] = {}
    if accounts_to_write:
        response = sheets_service.spreadsheets().values().batchGet(
            spreadsheetId=spreadsheet_id,
            ranges=[f"'{title}'!A:A" for _, title in accounts_to_write],
        ).execute()
        existing_values_by_account = {
            account: value_range.get("values", [])
            for (account, _), value_range in zip(
                accounts_to_write, response.get("valueRanges", [])
            )
        }

    for account, (_, title) in resolved_sheets.items():
        payload = accounts[account]
        rows = new_rows[account]
        if not rows:
            continue

        existing_values = existing_values_by_account[account]
        last_used_row = len(existing_values)
        if last_used_row == 0:
            start_row = 1
            values = [payload["headers"], *rows]
            first_data_row = 2
        else:
            start_row = last_used_row + 1
            values = rows
            first_data_row = start_row
        value_updates.append({
            "range": f"'{title}'!A{start_row}",
            "majorDimension": "ROWS",
            "values": values,
        })
        write_positions[account] = (first_data_row, len(rows))
    if value_updates:
        # values.batchUpdate no amplía la cuadrícula automáticamente. Consultar
        # el tamaño actual y crecer solo las pestañas que lo necesiten.
        metadata = sheets_service.spreadsheets().get(
            spreadsheetId=spreadsheet_id,
            fields="sheets(properties(sheetId,title,gridProperties(rowCount)))",
        ).execute()
        row_counts = {
            item["properties"]["title"]: item["properties"].get("gridProperties", {}).get("rowCount", 1000)
            for item in metadata.get("sheets", [])
        }
        resize_requests = []
        for update in value_updates:
            title = update["range"].split("!", 1)[0].strip("'")
            start_row = int(re.search(r"A(\d+)$", update["range"]).group(1))
            required_rows = start_row + len(update["values"]) - 1
            current_rows = row_counts.get(title, 1000)
            if required_rows > current_rows:
                sheet_id = next(sheet_id for sheet_id, sheet_title in resolved_sheets.values() if sheet_title == title)
                resize_requests.append({
                    "updateSheetProperties": {
                        "properties": {"sheetId": sheet_id, "gridProperties": {"rowCount": required_rows}},
                        "fields": "gridProperties.rowCount",
                    }
                })
        if resize_requests:
            sheets_service.spreadsheets().batchUpdate(
                spreadsheetId=spreadsheet_id, body={"requests": resize_requests}
            ).execute()
        sheets_service.spreadsheets().values().batchUpdate(
            spreadsheetId=spreadsheet_id,
            body={"valueInputOption": "USER_ENTERED", "data": value_updates},
        ).execute()

    # Do not apply or replace formats; each sheet owns its existing cell formatting.
    return

    format_requests = []
    for account, (sheet_id, _) in resolved_sheets.items():
        if account not in write_positions:
            continue
        headers = accounts[account]["headers"]
        first_data_row, row_count = write_positions[account]
        is_banorte = headers[0] == "FECHA DE OPERACION"
        is_bankaool = headers[0] == "Fecha" and headers[1] == "Descripción"
        date_columns = (0, 1) if is_banorte else (0,)
        date_pattern = "dd/MM/yyyy H:mm" if is_bankaool else "dd/MM/yyyy"
        number_type = "DATE_TIME" if is_bankaool else "DATE"
        for column in date_columns:
            format_requests.append({
                "repeatCell": {
                    "range": {
                        "sheetId": sheet_id,
                        "startRowIndex": first_data_row - 1,
                        "endRowIndex": first_data_row - 1 + row_count,
                        "startColumnIndex": column,
                        "endColumnIndex": column + 1,
                    },
                    "cell": {"userEnteredFormat": {"numberFormat": {"type": number_type, "pattern": date_pattern}}},
                    "fields": "userEnteredFormat.numberFormat",
                }
            })
    if format_requests:
        sheets_service.spreadsheets().batchUpdate(
            spreadsheetId=spreadsheet_id, body={"requests": format_requests}
        ).execute()


def clear_initial_workbook_rows(
    sheets_service,
    spreadsheet_id: str,
    resolved_sheets: dict[str, tuple[int, str]],
) -> None:
    """Remove previous movement rows while keeping row 1 headers."""
    sheet_items = list(resolved_sheets.items())
    response = sheets_service.spreadsheets().values().batchGet(
        spreadsheetId=spreadsheet_id,
        ranges=[f"'{title}'!A:A" for _, (_, title) in sheet_items],
    ).execute()
    requests = []
    for (account, (sheet_id, title)), value_range in zip(
        sheet_items, response.get("valueRanges", [])
    ):
        values = value_range.get("values", [])
        if len(values) > 1:
            requests.append({
                "repeatCell": {
                    "range": {
                        "sheetId": sheet_id,
                        "startRowIndex": 1,
                        "endRowIndex": len(values),
                        "startColumnIndex": 0,
                        "endColumnIndex": 26,
                    },
                    "cell": {},
                    "fields": "userEnteredValue",
                }
            })
            logging.info("Borradas %s filas anteriores en '%s'", len(values) - 1, title)
    if requests:
        sheets_service.spreadsheets().batchUpdate(
            spreadsheetId=spreadsheet_id,
            body={"requests": requests},
        ).execute()


def sync_workbook(
    sheets_service, spreadsheet_id: str, expected_title: str, account_tabs: dict[str, str], match_mode: str, create_if_missing: bool,
    state: dict[str, object],
) -> dict[str, object]:
    metadata = sheets_service.spreadsheets().get(
        spreadsheetId=spreadsheet_id, fields="properties.title,sheets.properties(sheetId,title)"
    ).execute()
    actual_title = metadata["properties"]["title"]
    if normalized_title(actual_title) != normalized_title(expected_title):
        raise RuntimeError(f"El titulo no coincide. Esperado: '{expected_title}'; actual: '{actual_title}'")
    start, end = month_from_title(actual_title)
    initial_load_completed = state.setdefault("initial_load_completed", {})
    resolved_sheets = resolve_account_sheets(
        sheets_service, spreadsheet_id, metadata.get("sheets", []), account_tabs, create_if_missing
    )
    if spreadsheet_id in OCTOBER_CLEANUP_WORKBOOK_IDS and not initial_load_completed.get(spreadsheet_id):
        clear_initial_workbook_rows(sheets_service, spreadsheet_id, resolved_sheets)
    accounts = deposits_for_period(start, end, tuple(account_tabs), match_mode)
    new_rows, new_records, baseline = new_rows_since_last_sync(state, f"{spreadsheet_id}:{actual_title}", accounts)
    initial_load_completed = state.setdefault("initial_load_completed", {})
    resolved_sheets = resolve_account_sheets(
        sheets_service, spreadsheet_id, metadata.get("sheets", []), account_tabs, create_if_missing
    )
    # Si el usuario vació una pestaña de septiembre, reconstruirla desde cero
    # aunque sus IDs sigan registrados en el estado local.
    if spreadsheet_id in INITIAL_LOAD_WORKBOOK_IDS:
        empty_accounts = set()
        sheet_items = list(resolved_sheets.items())
        response = sheets_service.spreadsheets().values().batchGet(
            spreadsheetId=spreadsheet_id,
            ranges=[f"'{title}'!A:A" for _, (_, title) in sheet_items],
        ).execute()
        for (account, _), value_range in zip(sheet_items, response.get("valueRanges", [])):
            values = value_range.get("values", [])
            if len(values) <= 1:
                empty_accounts.add(account)
        for account in empty_accounts:
            new_rows[account] = list(accounts[account]["rows"])
        new_records = sum(len(rows) for rows in new_rows.values())
    write_workbook_movements(sheets_service, spreadsheet_id, resolved_sheets, accounts, new_rows)
    if spreadsheet_id in INITIAL_LOAD_WORKBOOK_IDS:
        initial_load_completed[spreadsheet_id] = True
    for account, sheet_title in account_tabs.items():
        logging.info("Actualizada cuenta %s en '%s' - %s registros", account, resolved_sheets[account][1], len(accounts[account]["rows"]))
    total_records = sum(len(account["rows"]) for account in accounts.values())
    logging.info("Actualizado: %s - %s registros totales", actual_title, total_records)
    return {
        "workbook": actual_title,
        "accounts": len(accounts),
        "records": total_records,
        "new_records": new_records,
        "baseline": baseline,
    }


def sync_and_save(
    sheets_service,
    spreadsheet_id: str,
    expected_title: str,
    account_tabs: dict[str, str],
    match_mode: str,
    create_if_missing: bool,
    state: dict[str, object],
) -> dict[str, object]:
    """Sync one workbook and persist its state before moving to the next one."""
    result = sync_workbook(
        sheets_service,
        spreadsheet_id,
        expected_title,
        account_tabs,
        match_mode,
        create_if_missing,
        state,
    )
    save_sync_state(state)
    return result


def main() -> list[dict[str, object]]:
    logging.basicConfig(level=getattr(logging, setting("LOG_LEVEL", required=False) or "INFO"), format="%(asctime)s %(levelname)s %(message)s")
    service_account_file = Path(setting("GOOGLE_SERVICE_ACCOUNT_FILE")).expanduser()
    if not service_account_file.is_absolute():
        service_account_file = Path(__file__).parent / service_account_file
    credentials = Credentials.from_service_account_file(service_account_file, scopes=SCOPES)
    sheets_service = build("sheets", "v4", credentials=credentials, cache_discovery=False)
    state = load_sync_state()
    # Desde octubre solo se sincronizan los tres archivos del mes vigente.
    results = []
    september_foreign_spreadsheet_id = setting("SEPTEMBER_FOREIGN_GOOGLE_SPREADSHEET_ID", required=False)
    if False and september_foreign_spreadsheet_id:
        results.append(sync_and_save(
            sheets_service,
            september_foreign_spreadsheet_id,
            setting("SEPTEMBER_FOREIGN_EXPECTED_SPREADSHEET_TITLE"),
            FOREIGN_ACCOUNT_TABS,
            "like",
            False,
            state,
        ))
    else:
        logging.info("Archivo foráneo de septiembre no configurado; se omite en esta ejecucion.")
    dg_spreadsheet_id = setting("DG_GOOGLE_SPREADSHEET_ID", required=False)
    if False and dg_spreadsheet_id:
        results.append(sync_and_save(
            sheets_service,
            dg_spreadsheet_id,
            setting("DG_EXPECTED_SPREADSHEET_TITLE"),
            DG_ACCOUNT_TABS,
            "suffix",
            False,
            state,
        ))
    else:
        logging.info("Tercer archivo DG no configurado; se omite en esta ejecucion.")
    september_dg_spreadsheet_id = setting("SEPTEMBER_DG_GOOGLE_SPREADSHEET_ID", required=False)
    if False and september_dg_spreadsheet_id:
        results.append(sync_and_save(
            sheets_service,
            september_dg_spreadsheet_id,
            setting("SEPTEMBER_DG_EXPECTED_SPREADSHEET_TITLE"),
            DG_ACCOUNT_TABS,
            "suffix",
            False,
            state,
        ))
    else:
        logging.info("Archivo DG de septiembre no configurado; se omite en esta ejecucion.")
    october_spreadsheet_id = setting("OCTOBER_GOOGLE_SPREADSHEET_ID", required=False)
    if october_spreadsheet_id:
        results.append(sync_and_save(
            sheets_service,
            october_spreadsheet_id,
            setting("OCTOBER_EXPECTED_SPREADSHEET_TITLE"),
            {suffix: suffix for suffix in PRIMARY_ACCOUNT_SUFFIXES},
            "right",
            True,
            state,
        ))
    else:
        logging.info("Archivo de octubre no configurado; se omite en esta ejecucion.")
    october_foreign_spreadsheet_id = setting("OCTOBER_FOREIGN_GOOGLE_SPREADSHEET_ID", required=False)
    if october_foreign_spreadsheet_id:
        results.append(sync_and_save(
            sheets_service,
            october_foreign_spreadsheet_id,
            setting("OCTOBER_FOREIGN_EXPECTED_SPREADSHEET_TITLE"),
            FOREIGN_ACCOUNT_TABS,
            "like",
            False,
            state,
        ))
    else:
        logging.info("Archivo foraneo de octubre no configurado; se omite en esta ejecucion.")
    october_dg_spreadsheet_id = setting("OCTOBER_DG_GOOGLE_SPREADSHEET_ID", required=False)
    if october_dg_spreadsheet_id:
        results.append(sync_and_save(
            sheets_service,
            october_dg_spreadsheet_id,
            setting("OCTOBER_DG_EXPECTED_SPREADSHEET_TITLE"),
            DG_ACCOUNT_TABS,
            "suffix",
            False,
            state,
        ))
    else:
        logging.info("Archivo DG de octubre no configurado; se omite en esta ejecucion.")
    save_sync_state(state)
    return results


if __name__ == "__main__":
    try:
        main()
        raise SystemExit(0)
    except Exception as exc:
        logging.exception("La sincronizacion fallo: %s", exc)
        raise SystemExit(1)
