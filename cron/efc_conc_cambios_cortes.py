"""Escanea diariamente cambios históricos de cortes ControlGas y notifica por correo."""
from __future__ import annotations

import os
import smtplib
import sys
from datetime import date, datetime, timedelta
from email.message import EmailMessage
from pathlib import Path
from zoneinfo import ZoneInfo

import pyodbc

SCRIPT_DIR = Path(__file__).resolve().parent
sys.path.insert(0, str(SCRIPT_DIR))
from efc_conc_bancaria_automatica import connect, error_text, fetch_controlgas, load_env_file  # noqa: E402

STATIONS = (2, *range(4, 19), 22, 30, 33)  # Praxedis 40 está excluida expresamente.
LOCK_RESOURCE = "efc_conc_cambios_cortes"
NOTIFICATION_EMAIL = "daniel.ramirez@totalgas.com"


def log(message: str) -> None:
    print(f"[efc-cortes] {message}", flush=True)


def as_date(value: object) -> date:
    if isinstance(value, datetime):
        return value.date()
    if isinstance(value, date):
        return value
    text = str(value or "").strip()
    for fmt in ("%Y-%m-%d", "%Y%m%d", "%d/%m/%Y"):
        try:
            return datetime.strptime(text[:10], fmt).date()
        except ValueError:
            continue
    raise ValueError("Fecha operativa inválida en respuesta ControlGas")


def decimal_amount(value: object) -> float:
    if value is None or str(value).strip() == "":
        return 0.0
    return round(float(str(value).replace(",", "")), 2)


def cut_values(rows: list[dict], first: date, last: date) -> dict[tuple[date, str, str], float]:
    values: dict[tuple[date, str, str], float] = {}
    for row in rows:
        day = as_date(row.get("Fecha"))
        if day < first or day > last:
            continue
        turn = str(row.get("Turno", "")).strip()
        if not turn:
            raise ValueError("Corte ControlGas sin turno")
        concepts = {
            "MN": decimal_amount(row.get("MN")),
            "MORRALLA": decimal_amount(row.get("Morralla")),
            "USD": decimal_amount(row.get("Dolares")) + decimal_amount(row.get("Dolares2")),
        }
        for concept, amount in concepts.items():
            key = (day, turn, concept)
            if key in values:
                raise ValueError("Respuesta ControlGas contiene cortes duplicados")
            values[key] = round(amount, 2)
    return values


def acquire_lock(cursor: pyodbc.Cursor) -> bool:
    row = cursor.execute(
        "DECLARE @r INT; EXEC @r=sp_getapplock @Resource=?,@LockMode='Exclusive',"
        "@LockOwner='Session',@LockTimeout=0; SELECT @r", LOCK_RESOURCE
    ).fetchone()
    return row is not None and int(row[0]) >= 0


def start_run(cursor: pyodbc.Cursor) -> int:
    cursor.execute(
        "INSERT dbo.efc_conc_cortes_runs(iniciado_en,estado,estaciones_total,estaciones_ok,estaciones_error,cambios_detectados) "
        "OUTPUT inserted.id VALUES(SYSDATETIME(),'EN_PROCESO',?,0,0,0)", len(STATIONS)
    )
    return int(cursor.fetchone()[0])


def scan_station(cursor: pyodbc.Cursor, run_id: int, station: int, first: date, last: date) -> int:
    station_run = cursor.execute(
        "INSERT dbo.efc_conc_cortes_runs_estaciones(run_id,estacion_id,iniciado_en,estado,cambios_detectados) "
        "OUTPUT inserted.id VALUES(?,?,SYSDATETIME(),'EN_PROCESO',0)", run_id, station
    ).fetchone()
    if station_run is None or station_run[0] is None:
        raise RuntimeError("No se pudo obtener el ID de la ejecución por estación")
    station_run_id = int(station_run[0])
    cursor.connection.commit()
    # Fetch and validate the complete station response before opening its DB transaction.
    rows = fetch_controlgas(station, first, last)
    if not isinstance(rows, list):
        raise ValueError("ControlGas no devolvió una lista completa de cortes")
    incoming = cut_values(rows, first, last)
    changes = 0
    cursor.execute("SET XACT_ABORT ON; BEGIN TRANSACTION")
    try:
        # A missing key in a validated, complete response represents a removed/zeroed
        # cut. Compare against the station's current snapshot only after the full
        # ControlGas response has passed validation.
        prior = cursor.execute(
            "SELECT fecha_operativa,turno,concepto FROM dbo.efc_conc_cortes_snapshot WITH(UPDLOCK,HOLDLOCK) "
            "WHERE estacion_id=? AND fecha_operativa>=? AND fecha_operativa<=?",
            station, first, last,
        ).fetchall()
        for old_key in prior:
            key = (old_key[0], str(old_key[1]), str(old_key[2]))
            if key not in incoming:
                incoming[key] = 0.0
        for (day, turn, concept), observed in incoming.items():
            old = cursor.execute(
                "SELECT importe_observado FROM dbo.efc_conc_cortes_snapshot WITH(UPDLOCK,HOLDLOCK) "
                "WHERE estacion_id=? AND fecha_operativa=? AND turno=? AND concepto=?",
                station, day, turn, concept,
            ).fetchone()
            if old is None:
                cursor.execute(
                    "INSERT dbo.efc_conc_cortes_snapshot(estacion_id,fecha_operativa,turno,concepto,importe_observado,importe_aceptado,observado_en) "
                    "VALUES(?,?,?,?,?,?,SYSDATETIME())", station, day, turn, concept, observed, observed,
                )
                continue
            previous = float(old[0])
            if previous == observed:
                cursor.execute(
                    "UPDATE dbo.efc_conc_cortes_snapshot SET observado_en=SYSDATETIME() "
                    "WHERE estacion_id=? AND fecha_operativa=? AND turno=? AND concepto=?",
                    station, day, turn, concept,
                )
                continue
            cursor.execute(
                "UPDATE dbo.efc_conc_cortes_cambios SET estado='SUPERSEDIDO' "
                "WHERE estacion_id=? AND fecha_operativa=? AND turno=? AND concepto=? AND estado='PENDIENTE'",
                station, day, turn, concept,
            )
            cursor.execute(
                "INSERT dbo.efc_conc_cortes_cambios(estacion_id,fecha_operativa,turno,concepto,importe_anterior,importe_nuevo,estado,detectado_en) "
                "OUTPUT inserted.id VALUES(?,?,?,?,?,?,'PENDIENTE',SYSDATETIME())",
                station, day, turn, concept, previous, observed,
            )
            change_id = int(cursor.fetchone()[0])
            cursor.execute(
                "INSERT dbo.efc_conc_cortes_notificaciones(change_id,user_id,estado,intentos,creado_en) "
                "VALUES(?,0,'PENDIENTE',0,SYSDATETIME())",
                change_id,
            )
            cursor.execute(
                "UPDATE dbo.efc_conc_cortes_snapshot SET importe_observado=?,observado_en=SYSDATETIME() "
                "WHERE estacion_id=? AND fecha_operativa=? AND turno=? AND concepto=?",
                observed, station, day, turn, concept,
            )
            changes += 1
        cursor.execute(
            "UPDATE dbo.efc_conc_cortes_runs_estaciones SET estado='COMPLETADO',terminado_en=SYSDATETIME(),cambios_detectados=? WHERE id=?",
            changes, station_run_id,
        )
        cursor.execute("COMMIT TRANSACTION")
        return changes
    except Exception:
        cursor.execute("IF XACT_STATE()<>0 ROLLBACK TRANSACTION")
        raise


def send_one(conn: pyodbc.Connection) -> bool:
    cursor = conn.cursor()
    cursor.execute("BEGIN TRANSACTION")
    row = cursor.execute(
        "SELECT TOP(1) N.change_id,N.user_id,C.estacion_id,C.fecha_operativa,C.turno,C.concepto,C.importe_anterior,C.importe_nuevo "
        "FROM dbo.efc_conc_cortes_notificaciones N WITH(UPDLOCK,READPAST,ROWLOCK) "
        "JOIN dbo.efc_conc_cortes_cambios C ON C.id=N.change_id "
        "WHERE N.estado IN('PENDIENTE','ERROR') AND NOT EXISTS ("
        "SELECT 1 FROM dbo.efc_conc_cortes_notificaciones X WHERE X.change_id=N.change_id "
        "AND X.estado IN('PENDIENTE','ERROR','ENVIANDO') AND X.id<N.id) ORDER BY N.change_id,N.user_id"
    ).fetchone()
    if row is None:
        cursor.execute("COMMIT TRANSACTION")
        return False
    cursor.execute(
        "UPDATE dbo.efc_conc_cortes_notificaciones SET estado='ENVIANDO',intentos=intentos+1,ultimo_intento_en=SYSDATETIME(),error_detalle=NULL WHERE change_id=? AND user_id=?",
        row[0], row[1],
    )
    conn.commit()
    msg = EmailMessage()
    msg["From"] = os.environ.get("SMTP_FROM", os.environ.get("EMAIL_FROM", "no-reply@totalgas.com"))
    msg["To"] = NOTIFICATION_EMAIL
    msg["Message-ID"] = f"<efc-corte-{int(row[0])}-{int(row[1])}@totalgas.local>"
    msg["Subject"] = f"Cambio en corte ControlGas | estación {row[2]} | {row[3]}"
    app_url = os.environ.get(
        "EFC_CONC_APP_URL",
        "http://totalgasonline.net:400/income/cash_reconciliation_triple",
    ).rstrip("?")
    review_url = f"{app_url}?cambio_id={int(row[0])}"
    msg.set_content(
        f"Se detectó un cambio en ControlGas.\n\nEstación: {row[2]}\nFecha: {row[3]}\nTurno: {row[4]}\n"
        f"Concepto: {row[5]}\nImporte anterior: {float(row[6]):.2f}\nImporte nuevo: {float(row[7]):.2f}\n"
        f"Cambio {row[0]} requiere revisión.\n\nRevisar y resolver: {review_url}"
    )
    try:
        host = os.environ.get("SMTP_HOST", os.environ.get("EMAIL_SMTP_HOST", "smtp-relay.gmail.com"))
        port = int(os.environ.get("SMTP_PORT", os.environ.get("EMAIL_SMTP_PORT", "587")))
        with smtplib.SMTP(host, port, timeout=30) as smtp:
            smtp.ehlo()
            smtp.starttls()
            smtp.ehlo()
            smtp.send_message(msg)
        cursor.execute(
            "UPDATE dbo.efc_conc_cortes_notificaciones SET estado='ENVIADO',enviado_en=SYSDATETIME(),error_detalle=CASE WHEN user_id<>0 THEN 'Aviso duplicado consolidado' ELSE NULL END WHERE change_id=? AND estado IN('PENDIENTE','ERROR','ENVIANDO')",
            row[0],
        )
    except Exception as exc:
        cursor.execute(
            "UPDATE dbo.efc_conc_cortes_notificaciones SET estado='ERROR',error_detalle=? WHERE change_id=? AND user_id=?",
            error_text(exc)[:2000], row[0], row[1],
        )
        conn.commit()
        log(f"Falló notificación change_id={row[0]}, user_id={row[1]} ({type(exc).__name__}); queda en cola para reintento.")
        return False
    conn.commit()
    log(f"Notificación enviada: change_id={row[0]}, user_id={row[1]}.")
    return True


def run() -> int:
    load_env_file()
    timezone = ZoneInfo(os.environ.get("EFC_CONC_TIMEZONE", "America/Mexico_City"))
    last = datetime.now(timezone).date() - timedelta(days=1)
    first = last - timedelta(days=59)
    conn = connect()
    cursor = conn.cursor()
    run_id = None
    errors = 0
    total_changes = 0
    try:
        if not acquire_lock(cursor):
            log("Ya existe una ejecución activa; se omite esta instancia.")
            return 0
        run_id = start_run(cursor)
        conn.commit()
        cursor.execute(
            "UPDATE dbo.efc_conc_cortes_notificaciones SET estado='ERROR',error_detalle='Reintento tras ejecución interrumpida' "
            "WHERE estado='ENVIANDO' AND ultimo_intento_en<DATEADD(MINUTE,-30,SYSDATETIME())"
        )
        conn.commit()
        log(f"Ejecución {run_id}: ventana {first} a {last}; estaciones={len(STATIONS)}.")
        for station in STATIONS:
            try:
                changed = scan_station(cursor, run_id, station, first, last)
                conn.commit()
                total_changes += changed
                log(f"Estación {station}: completa; cambios={changed}.")
            except Exception as exc:
                conn.rollback()
                errors += 1
                safe = error_text(exc)[:2000]
                cursor.execute(
                    "UPDATE dbo.efc_conc_cortes_runs_estaciones SET estado='ERROR',terminado_en=SYSDATETIME(),error_detalle=? WHERE run_id=? AND estacion_id=?",
                    safe, run_id, station,
                )
                conn.commit()
                log(f"Estación {station}: error ({type(exc).__name__}): {safe}; snapshot sin cambios.")
        while send_one(conn):
            pass
        state = "PARCIAL" if errors else "COMPLETADO"
        cursor.execute(
            "UPDATE dbo.efc_conc_cortes_runs SET terminado_en=SYSDATETIME(),estado=?,estaciones_ok=?,estaciones_error=?,cambios_detectados=?,error_resumen=? WHERE id=?",
            state, len(STATIONS) - errors, errors, total_changes,
            f"Errores en {errors} estación(es)" if errors else None, run_id,
        )
        conn.commit()
        log(f"Ejecución {run_id} finalizada: estado={state}, cambios={total_changes}, estaciones_con_error={errors}.")
        return 0 if not errors else 1
    except Exception as exc:
        conn.rollback()
        log(f"Error fatal ({type(exc).__name__}): {error_text(exc)}")
        if run_id is not None:
            try:
                cursor.execute("UPDATE dbo.efc_conc_cortes_runs SET terminado_en=SYSDATETIME(),estado='ERROR',error_resumen=? WHERE id=?", error_text(exc)[:2000], run_id)
                conn.commit()
            except Exception:
                conn.rollback()
        return 1
    finally:
        try:
            cursor.execute("EXEC sp_releaseapplock @Resource=?,@LockOwner='Session'", LOCK_RESOURCE)
        except Exception:
            pass
        conn.close()


if __name__ == "__main__":
    raise SystemExit(run())
