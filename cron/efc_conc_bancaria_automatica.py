"""Conciliación bancaria automática de efectivo (cada 10 minutos).

No invoca endpoints PHP ni modifica datos al importar: lee ControlGas y TG,
y delega la persistencia a dbo.usp_efc_conc_guardar_automatica.  El SP usa una
transacción SERIALIZABLE para que un depósito/turno no pueda ser tomado por
dos ejecuciones (ni por una asociación manual concurrente).

Instalación: python cron/efc_conc_bancaria_automatica.py
Programar cada diez minutos; la ejecución adquiere un applock SQL y termina
sin hacer trabajo si otra instancia está activa.
"""
from __future__ import annotations

import json
import os
import re
from datetime import date, datetime, timedelta
from pathlib import Path
from urllib.request import Request, urlopen

import pyodbc

ROOT = Path(__file__).resolve().parent.parent
TOLERANCE = 1.00  # Misma tolerancia usada por runBankFixed en la consola.
COMPANY_STATIONS = {
    2, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 21, 22,
    23, 24, 25, 26, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36, 37, 38, 39, 199,
}
# Same known-account universe as EfcConciliacionModel::allAccountSuffixes().
ACCOUNT_SUFFIXES = (
    "0185322470", "369", "3281", "8837", "8520", "7291", "2570", "7533",
    "2627", "5247", "7604", "0031", "8504", "4409", "4547", "8214", "8492",
    "4412", "4777", "4669", "3678", "4457",
)


def load_env_file() -> None:
    path = ROOT / ".env"
    if not path.is_file():
        return
    for line in path.read_text(encoding="utf-8-sig").splitlines():
        line = line.strip()
        if line and not line.startswith("#") and "=" in line:
            key, value = line.split("=", 1)
            os.environ.setdefault(key.strip(), value.strip().strip("\"").strip("'"))


def connect() -> pyodbc.Connection:
    required = ("EFC_CONC_DB_HOST", "EFC_CONC_DB_NAME", "EFC_CONC_DB_USER", "EFC_CONC_DB_PASSWORD")
    missing = [key for key in required if not os.environ.get(key, "").strip()]
    if missing:
        raise RuntimeError("Faltan variables .env: " + ", ".join(missing))
    driver = os.environ.get("EFC_CONC_DB_DRIVER", "ODBC Driver 17 for SQL Server")
    port = os.environ.get("EFC_CONC_DB_PORT", "1433")
    trust = os.environ.get("EFC_CONC_DB_TRUST_CERTIFICATE", "yes")
    return pyodbc.connect(
        f"DRIVER={{{driver}}};SERVER={os.environ['EFC_CONC_DB_HOST']},{port};"
        f"DATABASE={os.environ['EFC_CONC_DB_NAME']};UID={os.environ['EFC_CONC_DB_USER']};"
        f"PWD={os.environ['EFC_CONC_DB_PASSWORD']};TrustServerCertificate={trust};",
        autocommit=False,
    )


def ensure_schema(cursor: pyodbc.Cursor) -> None:
    cursor.execute("""IF OBJECT_ID('dbo.efc_conc_ejecuciones_automaticas','U') IS NULL
        CREATE TABLE dbo.efc_conc_ejecuciones_automaticas (
          id BIGINT IDENTITY PRIMARY KEY, inicio_en DATETIME2 NOT NULL DEFAULT SYSDATETIME(),
          fin_en DATETIME2 NULL, estado VARCHAR(20) NOT NULL, conciliadas INT NOT NULL DEFAULT 0,
          omitidas INT NOT NULL DEFAULT 0, errores INT NOT NULL DEFAULT 0, detalle NVARCHAR(MAX) NULL)""")
    cursor.execute("""IF NOT EXISTS(SELECT 1 FROM sys.indexes WHERE name='IX_efc_conc_ejecuciones_estado_fin')
        CREATE INDEX IX_efc_conc_ejecuciones_estado_fin ON dbo.efc_conc_ejecuciones_automaticas(estado,fin_en DESC)""")
    # A procedure (rather than client-side INSERTs) keeps the reservations,
    # closure validation and group creation in one server-side transaction.
    cursor.execute("""CREATE OR ALTER PROCEDURE dbo.usp_efc_conc_guardar_automatica
      @estacion_id INT,@fecha DATE,@turno VARCHAR(20),@concepto VARCHAR(20),@importe_cg DECIMAL(18,2),
      @movimiento_bancario_id INT,@fecha_banco DATE,@importe_banco DECIMAL(18,2),@referencia VARCHAR(255),@ejecucion_id BIGINT
    AS BEGIN
      SET NOCOUNT ON; SET XACT_ABORT ON; SET TRANSACTION ISOLATION LEVEL SERIALIZABLE;
      BEGIN TRANSACTION;
      IF EXISTS(SELECT 1 FROM dbo.efc_conc_cierres WITH(UPDLOCK,HOLDLOCK) WHERE estacion_id=@estacion_id AND mes=CONVERT(CHAR(7),@fecha,23) AND concepto=@concepto AND estado='CERRADO')
        THROW 50001,'Periodo cerrado.',1;
      IF EXISTS(SELECT 1 FROM dbo.efc_conc_cierres_etapas WITH(UPDLOCK,HOLDLOCK) WHERE estacion_id=@estacion_id AND mes=CONVERT(CHAR(7),@fecha,23) AND concepto=@concepto AND etapa='BANCO' AND estado='CERRADO')
        THROW 50005,'Etapa bancaria cerrada.',1;
      IF EXISTS(SELECT 1 FROM dbo.efc_conc_transitos WITH(UPDLOCK,HOLDLOCK) WHERE estacion_id=@estacion_id AND clave_externa='cg-'+CONVERT(VARCHAR(20),@estacion_id)+'-'+CONVERT(CHAR(10),@fecha,23)+'-'+@turno+'-'+@concepto AND estado='PENDIENTE')
        THROW 50002,'Turno en transito pendiente.',1;
      DECLARE @cg_key VARCHAR(180)='cg-'+CONVERT(VARCHAR(20),@estacion_id)+'-'+CONVERT(CHAR(10),@fecha,23)+'-'+@turno+'-'+@concepto;
      IF EXISTS(SELECT 1 FROM dbo.efc_conc_partidas P WITH(UPDLOCK,HOLDLOCK) JOIN dbo.efc_conc_grupos G ON G.id=P.grupo_id WHERE P.clave_externa=@cg_key AND P.origen='CG' AND P.activo=1 AND G.estado='ACTIVA')
        THROW 50003,'Turno ya conciliado.',1;
      IF EXISTS(SELECT 1 FROM dbo.efc_conc_partidas P WITH(UPDLOCK,HOLDLOCK) JOIN dbo.efc_conc_grupos G ON G.id=P.grupo_id WHERE P.movimiento_bancario_id=@movimiento_bancario_id AND P.origen='BANCO' AND P.activo=1 AND G.estado='ACTIVA')
        THROW 50004,'Deposito ya conciliado.',1;
      DECLARE @grupo TABLE(id INT); INSERT dbo.efc_conc_grupos(estacion_id,fecha_operativa,turno,concepto,tipo,total_controlgas,total_banorte,diferencia,creado_por)
        OUTPUT inserted.id INTO @grupo VALUES(@estacion_id,@fecha,@turno,@concepto,'AUTOMATICA',@importe_cg,@importe_banco,@importe_banco-@importe_cg,NULL);
      DECLARE @id INT=(SELECT id FROM @grupo);
      INSERT dbo.efc_conc_partidas(grupo_id,origen,clave_externa,movimiento_bancario_id,fecha_operacion,turno,concepto,importe,referencia,estacion_id)
        VALUES(@id,'CG',@cg_key,NULL,@fecha,@turno,@concepto,@importe_cg,NULL,@estacion_id),(@id,'BANCO','mb_'+CONVERT(VARCHAR(20),@movimiento_bancario_id),@movimiento_bancario_id,@fecha_banco,NULL,NULL,@importe_banco,@referencia,@estacion_id);
      INSERT dbo.efc_conc_bitacora(grupo_id,movimiento_bancario_id,accion,detalle) VALUES(@id,@movimiento_bancario_id,'CONCILIACION_AUTOMATICA',CONCAT('ejecucion=',@ejecucion_id));
      COMMIT;
    END""")


def acquire_lock(cursor: pyodbc.Cursor) -> bool:
    row = cursor.execute("DECLARE @r INT; EXEC @r=sp_getapplock @Resource='efc_conc_bancaria_automatica',@LockMode='Exclusive',@LockOwner='Session',@LockTimeout=0; SELECT @r").fetchone()
    return row is not None and int(row[0]) >= 0


def fetch_controlgas(station_id: int, first: date, last: date) -> list[dict]:
    url = os.environ.get("EFC_CONC_CONTROLGAS_URL", "http://201.174.170.236:99/api/Depositos/GetDepositosEstacion")
    payload = json.dumps({"Datos": {"FechaInicial": first.strftime("%Y%m%d"), "FechaFinal": last.strftime("%Y%m%d"), "Gasolinera": station_id}}).encode()
    request = Request(url, data=payload, headers={"Content-Type": "application/json"})
    with urlopen(request, timeout=int(os.environ.get("EFC_CONC_CONTROLGAS_TIMEOUT", "30"))) as response:
        data = json.loads(response.read().decode())
    # Mirror the browser: an explicit failure is accepted only when codigo=0.
    if not bool(data.get("exito")) and data.get("codigo") != 0:
        raise RuntimeError(data.get("mensaje", "ControlGas no respondió"))
    return data.get("respuesta", [])


def as_date(value: object) -> date:
    text = str(value)[:10]
    if "/" in text:
        return datetime.strptime(text, "%d/%m/%Y").date()
    return datetime.strptime(text, "%Y-%m-%d").date()


def amount(value: object) -> float:
    return round(float(str(value or 0).replace(",", "").replace("$", "")), 2)


def turn_key(value: object) -> str:
    """The web board compares ControlGas labels by their numeric turn too."""
    match = re.search(r"\d+", str(value or ""))
    return match.group(0) if match else str(value or "").strip()


def normalize_name(value: object) -> str:
    text = str(value or "").upper()
    return "".join(char if char.isalnum() else " " for char in text).strip()


def resolved_bank_station(bank: tuple, catalog: list[tuple]) -> int | None:
    """Accept explicit correction, else exactly one explicit name/E-code hit."""
    correction = bank[5]
    if correction is not None:
        return int(correction)
    text = normalize_name(bank[4])
    matches: set[int] = set()
    for station_id, station_name, station_code in catalog:
        station_name = re.sub(r"^\d+\s+", "", normalize_name(station_name))
        # Short names are unsafe substring markers. Operating codes must have
        # the E-prefix used by ControlGas (never a broad LIKE with an empty code).
        code = "".join(char for char in str(station_code or "") if char.isdigit()).lstrip("0")
        name_hit = len(station_name) >= 4 and station_name in text
        code_hit = bool(code) and re.search(r"(?<![A-Z0-9])E0*" + re.escape(code) + r"(?![A-Z0-9])", text) is not None
        if name_hit or code_hit:
            matches.add(int(station_id))
    return next(iter(matches)) if len(matches) == 1 else None


def matching_bank_rows(cursor: pyodbc.Cursor, cut: date) -> list[tuple]:
    # Corrections take precedence. Bank account must still belong to the
    # controlled account universe, matching validateDeposit's account gate.
    account_where = " OR ".join("RIGHT(UPPER(REPLACE(REPLACE(ISNULL(M.cuenta,''),'-',''),' ','')),LEN(?))=?" for _ in ACCOUNT_SUFFIXES)
    params = [cut, cut, *[value for suffix in ACCOUNT_SUFFIXES for value in (suffix, suffix)]]
    return cursor.execute("""SELECT M.id,CONVERT(CHAR(10),M.fecha,23),M.abono,COALESCE(M.referencia,''),
      COALESCE(M.descripcion_larga,M.descripcion,''),C.estacion_id FROM TG.dbo.movimientos_bancarios M
      LEFT JOIN dbo.efc_conc_correcciones_banco C ON C.movimiento_bancario_id=M.id
      WHERE M.abono>0 AND M.fecha>=? AND M.fecha<DATEADD(day,8,?)
        AND (UPPER(COALESCE(M.descripcion,'')) LIKE '%DEPOSITO EN EFECTIVO%' OR UPPER(COALESCE(M.descripcion_larga,'')) LIKE '%DEPOSITO EN EFECTIVO%')
        AND (""" + account_where + ")", *params).fetchall()


def run() -> int:
    load_env_file()
    conn = connect(); cursor = conn.cursor()
    run_id = None
    try:
        ensure_schema(cursor); conn.commit()
        if not acquire_lock(cursor):
            conn.rollback(); print("Otra ejecución automática sigue activa."); return 0
        run_id = cursor.execute("INSERT dbo.efc_conc_ejecuciones_automaticas(estado) OUTPUT inserted.id VALUES('EJECUTANDO')").fetchone()[0]; conn.commit()
        today = date.today(); first = (today.replace(day=1) - timedelta(days=1)).replace(day=1); last = today
        allowed = ",".join(str(value) for value in sorted(COMPANY_STATIONS))
        stations = cursor.execute(f"SELECT Codigo,Nombre,Estacion FROM TG.dbo.Estaciones WHERE Codigo IN ({allowed}) AND Nombre<>'NO FUNCIONA'").fetchall()
        matched = skipped = errors = 0; details: list[str] = []
        for station_id, name, code in stations:
            try:
                used = {row[0] for row in cursor.execute("SELECT P.movimiento_bancario_id FROM dbo.efc_conc_partidas P JOIN dbo.efc_conc_grupos G ON G.id=P.grupo_id WHERE P.origen='BANCO' AND P.activo=1 AND G.estado='ACTIVA'").fetchall()}
                links = {(str(row[0]), turn_key(row[1]), str(row[2])): amount(row[3]) for row in cursor.execute("""SELECT CONVERT(CHAR(10),V.fecha_cg,23),V.turno,V.concepto,
                    CASE WHEN V.concepto='USD' THEN ISNULL(P.real_usd,0)*ISNULL(V.tipo_cambio_usd,0) ELSE ISNULL(P.real_mn,0) END
                    FROM dbo.efc_conc_analiticos_vinculos V JOIN dbo.efc_conc_analiticos_papeletas P ON P.id=V.papeleta_id
                    WHERE V.estacion_id=? AND V.activo=1""", station_id).fetchall()}
                for row in fetch_controlgas(int(station_id), first, last):
                    cut = as_date(row.get("Fecha")); turn = str(row.get("Turno", "")).strip()
                    if not turn: continue
                    for concept, raw in (("MN", row.get("MN")), ("MORRALLA", row.get("Morralla"))):
                        cg = amount(raw)
                        if cg <= 0: continue
                        # Normal stations compare against REGIO real amount; Parral's
                        # Bankaool rule compares CG directly, exactly as runBankFixed.
                        link_key = (cut.isoformat(), turn_key(turn), concept)
                        if "PARRAL" not in str(name).upper() and link_key not in links:
                            skipped += 1; continue
                        target = cg if "PARRAL" in str(name).upper() else links[link_key]
                        if target <= 0:
                            skipped += 1; continue
                        candidates = [b for b in matching_bank_rows(cursor, cut) if int(b[0]) not in used and resolved_bank_station(b, stations) == int(station_id) and abs(amount(b[2]) - target) <= TOLERANCE]
                        if len(candidates) != 1:
                            skipped += 1; continue
                        bank = candidates[0]
                        try:
                            cursor.execute("EXEC dbo.usp_efc_conc_guardar_automatica ?,?,?,?,?,?,?,?,?,?", station_id, cut, turn, concept, cg, bank[0], bank[1], amount(bank[2]), bank[3], run_id)
                            conn.commit(); used.add(int(bank[0])); matched += 1
                        except pyodbc.Error as exc:
                            conn.rollback(); errors += 1; details.append(f"{station_id}/{cut}/{turn}/{concept}: {exc}")
            except Exception as exc:
                conn.rollback(); errors += 1; details.append(f"estación {station_id}: {exc}")
        cursor.execute("UPDATE dbo.efc_conc_ejecuciones_automaticas SET estado=?,fin_en=SYSDATETIME(),conciliadas=?,omitidas=?,errores=?,detalle=? WHERE id=?", "PARCIAL" if errors else "COMPLETADA", matched, skipped, errors, "\n".join(details[-100:]), run_id)
        conn.commit(); print(f"Ejecución {run_id}: {matched} conciliadas, {skipped} omitidas, {errors} errores."); return 0
    except Exception as exc:
        conn.rollback()
        if run_id is not None:
            try:
                cursor.execute("UPDATE dbo.efc_conc_ejecuciones_automaticas SET estado='ERROR',fin_en=SYSDATETIME(),errores=errores+1,detalle=? WHERE id=?", str(exc), run_id)
                conn.commit()
            except Exception:
                conn.rollback()
        raise
    finally:
        try: cursor.execute("EXEC sp_releaseapplock @Resource='efc_conc_bancaria_automatica',@LockOwner='Session'")
        except Exception: pass
        conn.close()


if __name__ == "__main__":
    raise SystemExit(run())
