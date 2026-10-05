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
from xml.sax.saxutils import escape

import pyodbc

SCRIPT_DIR = Path(__file__).resolve().parent
ROOT = SCRIPT_DIR.parent
TOLERANCE = 1.00  # Misma tolerancia usada por runBankFixed en la consola.
PARRAL_TOLERANCE = 6.00
COMPANY_STATIONS = {
    2, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 21, 22,
    23, 24, 25, 26, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36, 37, 38, 39, 199,
    40,
}
GASOMEX_STATIONS = {23, 24, 25, 26, 27, 28, 29}
GASOMEX_ACCOUNT_STATIONS = {
    "8504": {29}, "4547": {23}, "8214": {23, 25}, "8492": {25, 26},
    "4412": {26}, "4777": {27}, "4669": {28}, "3678": {28}, "4638": {24},
}
# Deposit accounts are the currency boundary for GASOMEX.  A lot is built
# independently per bucket, so MN and USD can never be searched against the
# same bank movement.  MORRALLA is part of the MN bucket when present.
GASOMEX_ACCOUNT_STATION_BUCKETS = {
    "8504": {29: {"MN"}}, "4547": {23: {"MN"}},
    "8214": {23: {"USD"}, 25: {"MN"}},
    "8492": {25: {"USD"}, 26: {"MN", "USD"}},
    "4412": {26: {"USD"}}, "4777": {27: {"MN"}},
    "4669": {28: {"MN"}}, "3678": {28: {"USD"}},
    "4638": {24: {"MN", "USD"}},
}
# Same known-account universe as EfcConciliacionModel::allAccountSuffixes().
ACCOUNT_SUFFIXES = (
    "0185322470", "369", "3281", "8837", "8520", "7291", "2570", "7533",
    "2627", "5247", "7604", "0031", "8504", "4547", "8214", "8492",
    "4412", "4777", "4669", "3678", "4638", "60630878973",
)
# Mirror EfcConciliacionModel::COMPANY_ACCOUNT_STATIONS for unmapped bank rows.
# The 369 account is assigned to Parral; shared accounts require text/correction.
BANK_ACCOUNT_STATION_MARKERS = {
    "369": ("PARRAL",),
    "60630878973": ("PRAXEDIS",),
}


def log(message: str) -> None:
    """Emite progreso inmediatamente, sin incluir secretos de configuración."""
    print(f"[efc-conc] {message}", flush=True)


def error_text(exc: Exception) -> str:
    """Evita que un mensaje de error accidentalmente exponga credenciales."""
    return re.sub(r"(?i)(password|pwd)\s*=\s*[^;\s]+", r"\1=[redacted]", str(exc))


def load_env_file() -> None:
    """Carga .env junto al script, desde la carpeta actual o desde la raíz."""
    paths = (Path(SCRIPT_DIR) / ".env", Path.cwd() / ".env", Path(ROOT) / ".env")
    for candidate in paths:
        path = Path(candidate)
        if path.is_file():
            break
    else:
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
    cursor.execute("""CREATE OR ALTER PROCEDURE dbo.usp_efc_conc_guardar_gasomex
      @estacion_id INT,@fecha DATE,@cg_xml XML,
      @movimiento_bancario_id INT,@fecha_banco DATE,@importe_banco DECIMAL(18,2),@referencia VARCHAR(255),@ejecucion_id BIGINT
    AS BEGIN
      SET NOCOUNT ON; SET XACT_ABORT ON; SET TRANSACTION ISOLATION LEVEL SERIALIZABLE;
      BEGIN TRANSACTION;
      DECLARE @cg TABLE(clave_externa VARCHAR(180),fecha DATE,turno VARCHAR(20),concepto VARCHAR(20),importe DECIMAL(18,2));
      INSERT @cg SELECT item.value('@id','VARCHAR(180)'),item.value('@date','DATE'),item.value('@turn','VARCHAR(20)'),item.value('@currency','VARCHAR(20)'),item.value('@amount','DECIMAL(18,2)') FROM @cg_xml.nodes('/turns/turn') AS turns(item);
      IF NOT EXISTS(SELECT 1 FROM @cg) THROW 50006,'No hay turnos GASOMEX para conciliar.',1;
      DECLARE @concepto VARCHAR(20)=CASE WHEN EXISTS(SELECT 1 FROM @cg WHERE concepto='USD') THEN 'USD' ELSE 'MN' END,
              @importe_cg DECIMAL(18,2)=(SELECT ROUND(SUM(importe),2) FROM @cg),
              @turno VARCHAR(20)=CASE WHEN (SELECT COUNT(*) FROM @cg)>1 THEN 'VARIOS' ELSE (SELECT TOP 1 turno FROM @cg) END;
      IF EXISTS(SELECT 1 FROM @cg WHERE concepto NOT IN ('MN','MORRALLA','USD'))
         OR (@concepto='USD' AND EXISTS(SELECT 1 FROM @cg WHERE concepto<>'USD'))
        THROW 50008,'Conceptos GASOMEX mezclados en un lote.',1;
      IF ABS(@importe_banco-@importe_cg)>=1.00 THROW 50007,'La combinación GASOMEX no coincide con el depósito en menos de un peso.',1;
      IF EXISTS(SELECT 1 FROM dbo.efc_conc_cierres WITH(UPDLOCK,HOLDLOCK) WHERE estacion_id=@estacion_id AND mes=CONVERT(CHAR(7),@fecha,23) AND concepto=@concepto AND estado='CERRADO')
        THROW 50001,'Periodo cerrado.',1;
      IF EXISTS(SELECT 1 FROM dbo.efc_conc_cierres_etapas WITH(UPDLOCK,HOLDLOCK) WHERE estacion_id=@estacion_id AND mes=CONVERT(CHAR(7),@fecha,23) AND concepto=@concepto AND etapa='BANCO' AND estado='CERRADO')
        THROW 50005,'Etapa bancaria cerrada.',1;
      IF EXISTS(SELECT 1 FROM dbo.efc_conc_transitos T WITH(UPDLOCK,HOLDLOCK) JOIN @cg C ON C.clave_externa=T.clave_externa WHERE T.estacion_id=@estacion_id AND T.estado='PENDIENTE')
        THROW 50002,'Turno en transito pendiente.',1;
      IF EXISTS(SELECT 1 FROM dbo.efc_conc_partidas P WITH(UPDLOCK,HOLDLOCK) JOIN dbo.efc_conc_grupos G ON G.id=P.grupo_id JOIN @cg C ON C.clave_externa=P.clave_externa WHERE P.origen='CG' AND P.activo=1 AND G.estado='ACTIVA')
        THROW 50003,'Turno ya conciliado.',1;
      IF EXISTS(SELECT 1 FROM dbo.efc_conc_partidas P WITH(UPDLOCK,HOLDLOCK) JOIN dbo.efc_conc_grupos G ON G.id=P.grupo_id WHERE P.movimiento_bancario_id=@movimiento_bancario_id AND P.origen='BANCO' AND P.activo=1 AND G.estado='ACTIVA')
        THROW 50004,'Deposito ya conciliado.',1;
      DECLARE @grupo TABLE(id INT); INSERT dbo.efc_conc_grupos(estacion_id,fecha_operativa,turno,concepto,tipo,total_controlgas,total_banorte,diferencia,creado_por)
        OUTPUT inserted.id INTO @grupo VALUES(@estacion_id,@fecha,@turno,@concepto,'AUTOMATICA',@importe_cg,@importe_banco,@importe_banco-@importe_cg,NULL);
      DECLARE @id INT=(SELECT id FROM @grupo);
      INSERT dbo.efc_conc_partidas(grupo_id,origen,clave_externa,movimiento_bancario_id,fecha_operacion,turno,concepto,importe,referencia,estacion_id)
        SELECT @id,'CG',clave_externa,NULL,fecha,turno,concepto,importe,NULL,@estacion_id FROM @cg;
      INSERT dbo.efc_conc_partidas(grupo_id,origen,clave_externa,movimiento_bancario_id,fecha_operacion,turno,concepto,importe,referencia,estacion_id)
        VALUES(@id,'BANCO','mb_'+CONVERT(VARCHAR(20),@movimiento_bancario_id),@movimiento_bancario_id,@fecha_banco,NULL,NULL,@importe_banco,@referencia,@estacion_id);
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


def fetch_station_turns(cursor: pyodbc.Cursor, station_id: int, first: date, last: date) -> list[dict]:
    """Use Praxedis' reviewed cuts for station 40; preserve ControlGas elsewhere."""
    if station_id != 40:
        return fetch_controlgas(station_id, first, last)

    rows = cursor.execute(
        """SELECT fecha_operativa, turno, efectivo, [dollar]
           FROM TG.dbo.efc_conc_praxedis_cortes
           WHERE estacion_id=40 AND fecha_operativa>=? AND fecha_operativa<=?
           ORDER BY fecha_operativa, turno""",
        first,
        last,
    ).fetchall()
    return [
        {
            "Fecha": row[0],
            "Turno": str(row[1]).strip(),
            "MN": row[2] or 0,
            "Morralla": 0,
            "Dolares": row[3] or 0,
            "Dolares2": 0,
        }
        for row in rows
    ]


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
    """Resolve correction, then explicit description/code or known account map."""
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
        # Banks do not use one single format for the station code.  Some
        # movements contain E04188, while others contain the zero-padded
        # numeric token 000000000004188.  Match the complete token in either
        # format; never match a short code embedded inside another number.
        code_hit = bool(code) and (
            re.search(r"(?<![A-Z0-9])E0*" + re.escape(code) + r"(?![A-Z0-9])", text) is not None
            or re.search(r"(?<![A-Z0-9])0*" + re.escape(code) + r"(?![A-Z0-9])", text) is not None
        )
        if name_hit or code_hit:
            matches.add(int(station_id))
    if matches:
        return next(iter(matches)) if len(matches) == 1 else None

    account = re.sub(r"[^A-Z0-9]", "", str(bank[6] or "").upper())
    account_matches: set[int] = set()
    for suffix, station_markers in BANK_ACCOUNT_STATION_MARKERS.items():
        if not account.endswith(suffix):
            continue
        for station_id, station_name, _station_code in catalog:
            normalized_station = re.sub(r"^\d+\s+", "", normalize_name(station_name))
            if any(marker in normalized_station for marker in station_markers):
                account_matches.add(int(station_id))
    return next(iter(account_matches)) if len(account_matches) == 1 else None


def matching_bank_rows(cursor: pyodbc.Cursor, first: date, last: date | None = None) -> list[tuple]:
    # Corrections take precedence. Bank account must still belong to the
    # controlled account universe, matching validateDeposit's account gate.
    account_where = " OR ".join("RIGHT(UPPER(REPLACE(REPLACE(ISNULL(M.cuenta,''),'-',''),' ','')),LEN(?))=?" for _ in ACCOUNT_SUFFIXES)
    # A single run covers every possible cut window: scan start through
    # today+7 (the query's exclusive upper bound is therefore today+8).
    end_base = first if last is None else last
    params = [first, end_base, *[value for suffix in ACCOUNT_SUFFIXES for value in (suffix, suffix)]]
    return cursor.execute("""SELECT M.id,CONVERT(CHAR(10),M.fecha,23),M.abono,COALESCE(M.referencia,''),
      COALESCE(M.descripcion_larga,M.descripcion,''),C.estacion_id,M.cuenta FROM TG.dbo.movimientos_bancarios M
      LEFT JOIN dbo.efc_conc_correcciones_banco C ON C.movimiento_bancario_id=M.id
      WHERE M.abono>0 AND M.fecha>=? AND M.fecha<DATEADD(day,8,?)
        AND (UPPER(COALESCE(M.descripcion,'')) LIKE '%DEPOSITO EN EFECTIVO%' OR UPPER(COALESCE(M.descripcion_larga,'')) LIKE '%DEPOSITO EN EFECTIVO%')
        AND (""" + account_where + ")", *params).fetchall()


def gasomex_account_matches(bank: tuple, station_id: int) -> bool:
    account = re.sub(r"\D", "", str(bank[6] or ""))
    return station_id in {candidate for suffix, stations in GASOMEX_ACCOUNT_STATIONS.items() if account.endswith(suffix) for candidate in stations}


def gasomex_bank_buckets(bank: tuple, station_id: int) -> set[str]:
    account = re.sub(r"\D", "", str(bank[6] or ""))
    return {
        bucket
        for suffix, station_buckets in GASOMEX_ACCOUNT_STATION_BUCKETS.items()
        if account.endswith(suffix)
        for bucket in station_buckets.get(station_id, set())
    }


def index_bank_rows(
    rows: list[tuple],
    stations: list[tuple],
    inferred_stations: dict[int, tuple[int, tuple[str, str, str, float]]] | None = None,
) -> dict[int, dict[date, list[tuple]]]:
    """Classify each eligible movement once and index it by station and date."""
    indexed: dict[int, dict[date, list[tuple]]] = {}
    inferred_stations = inferred_stations or {}
    for bank in rows:
        station_id = resolved_bank_station(bank, stations) or inferred_stations.get(int(bank[0]), (None,))[0]
        if station_id is None:
            continue
        bank_date = as_date(bank[1])
        indexed.setdefault(station_id, {}).setdefault(bank_date, []).append(bank)
    return indexed


def infer_unassigned_bank_stations(
    cursor: pyodbc.Cursor,
    bank_rows: list[tuple],
    stations: list[tuple],
    used_banks: set[int],
    first: date,
    last: date,
) -> dict[int, tuple[int, tuple[str, str, str, float]]]:
    """Infer a station only for a unique exact REGIO-real match in its date window."""
    station_names = {int(row[0]): str(row[1] or "") for row in stations}
    station_ids = sorted(station_names)
    unresolved = [
        bank for bank in bank_rows
        if int(bank[0]) not in used_banks
        and bank[5] is None
        and resolved_bank_station(bank, stations) is None
    ]
    if not unresolved or not station_ids:
        return {}

    marks = ",".join("?" for _ in station_ids)
    link_rows = cursor.execute(
        """SELECT V.estacion_id,CONVERT(CHAR(10),V.fecha_cg,23),V.turno,V.concepto,
                  CASE WHEN V.concepto='USD' THEN ISNULL(P.real_usd,0)*ISNULL(V.tipo_cambio_usd,0)
                       ELSE ISNULL(P.real_mn,0) END
           FROM dbo.efc_conc_analiticos_vinculos V
           JOIN dbo.efc_conc_analiticos_papeletas P ON P.id=V.papeleta_id
           WHERE V.activo=1 AND V.estacion_id IN (""" + marks + ") AND V.fecha_cg>=? AND V.fecha_cg<=?",
        *station_ids,
        first,
        last,
    ).fetchall()
    occupied_rows = cursor.execute(
        """SELECT P.estacion_id,CONVERT(CHAR(10),P.fecha_operacion,23),P.turno,P.concepto
           FROM dbo.efc_conc_partidas P JOIN dbo.efc_conc_grupos G ON G.id=P.grupo_id
           WHERE P.origen='CG' AND P.activo=1 AND G.estado='ACTIVA'
             AND P.estacion_id IN (""" + marks + ")",
        *station_ids,
    ).fetchall()
    occupied_turns = {
        (int(row[0]), str(row[1]), turn_key(row[2]), str(row[3]).upper())
        for row in occupied_rows
    }
    transit_rows = cursor.execute(
        """SELECT estacion_id,CONVERT(CHAR(10),fecha_origen,23),turno,concepto
           FROM dbo.efc_conc_transitos WHERE estado='PENDIENTE' AND estacion_id IN (""" + marks + ")",
        *station_ids,
    ).fetchall()
    occupied_turns.update(
        (int(row[0]), str(row[1]), turn_key(row[2]), str(row[3]).upper())
        for row in transit_rows
    )

    unresolved_ids = {int(bank[0]) for bank in unresolved}
    banks_by_turn: dict[tuple[int, str, str, str], set[int]] = {}
    turns_by_bank: dict[int, set[tuple[int, str, str, str]]] = {}
    targets: dict[tuple[int, str, str, str], float] = {}
    for row in link_rows:
        station_id = int(row[0])
        day = str(row[1])
        turn = turn_key(row[2])
        concept = str(row[3] or "").upper()
        target = amount(row[4])
        if target <= 0 or (station_id, day, turn, concept) in occupied_turns:
            continue
        # Only infer from REGIO amounts in workflows that already reconcile
        # against REGIO real. Parral compares bank deposits directly to CG.
        if "PARRAL" in station_names.get(station_id, "").upper():
            continue
        if station_id not in GASOMEX_STATIONS and concept not in {"MN", "MORRALLA"}:
            continue
        targets[(station_id, day, turn, concept)] = target

    for turn_key_value, target in targets.items():
        station_id, day_text, turn, concept = turn_key_value
        cut = date.fromisoformat(day_text)
        gasomex_bucket = "USD" if concept == "USD" else "MN"
        for bank in unresolved:
            bank_id = int(bank[0])
            bank_day = as_date(bank[1])
            if not cut <= bank_day <= cut + timedelta(days=7) or amount(bank[2]) != target:
                continue
            if station_id in GASOMEX_STATIONS and gasomex_bucket not in gasomex_bank_buckets(bank, station_id):
                continue

            # Count other possible bank movements within the existing amount
            # tolerance before assigning a station. Unknown deposits are
            # counted conservatively across stations to avoid guessing.
            competing_banks = []
            for other in bank_rows:
                other_id = int(other[0])
                if other_id in used_banks or not cut <= as_date(other[1]) <= cut + timedelta(days=7):
                    continue
                other_station = resolved_bank_station(other, stations)
                if other_station not in (None, station_id):
                    continue
                if station_id in GASOMEX_STATIONS:
                    other_bucket = "USD" if concept == "USD" else "MN"
                    if other_bucket not in gasomex_bank_buckets(other, station_id):
                        continue
                if abs(amount(other[2]) - target) <= TOLERANCE:
                    competing_banks.append(other_id)
            if len(competing_banks) != 1 or competing_banks[0] != bank_id:
                continue

            banks_by_turn.setdefault(turn_key_value, set()).add(bank_id)
            turns_by_bank.setdefault(bank_id, set()).add(turn_key_value)

    inferred: dict[int, tuple[int, tuple[str, str, str, float]]] = {}
    for turn_key_value, bank_ids in banks_by_turn.items():
        if len(bank_ids) != 1:
            continue
        bank_id = next(iter(bank_ids))
        if bank_id not in unresolved_ids or len(turns_by_bank.get(bank_id, set())) != 1:
            continue
        inferred[bank_id] = (turn_key_value[0], (*turn_key_value[1:], targets[turn_key_value]))
    return inferred


def save_inferred_station_correction(
    cursor: pyodbc.Cursor,
    bank_id: int,
    station_id: int,
    match: tuple[str, str, str, float],
    run_id: int,
) -> None:
    """Persist an inferred station inside the caller's reconciliation transaction."""
    existing = cursor.execute(
        "SELECT estacion_id FROM dbo.efc_conc_correcciones_banco WITH (UPDLOCK,HOLDLOCK) WHERE movimiento_bancario_id=?",
        bank_id,
    ).fetchone()
    if existing:
        if int(existing[0]) != station_id:
            raise RuntimeError("El depósito recibió una corrección manual de estación durante la conciliación.")
        return
    day, turn, concept, target = match
    cursor.execute(
        "INSERT dbo.efc_conc_correcciones_banco(movimiento_bancario_id,estacion_id,creado_por) VALUES(?,?,NULL)",
        bank_id,
        station_id,
    )
    cursor.execute(
        "INSERT dbo.efc_conc_bitacora(grupo_id,movimiento_bancario_id,accion,detalle,usuario_id) VALUES(NULL,?,?,?,NULL)",
        bank_id,
        "CORRECCION_ESTACION_AUTO_REGIO",
        json.dumps({"estacion_id": station_id, "fecha_cg": day, "turno": turn, "concepto": concept, "regio_real": target, "ejecucion_id": run_id}),
    )


def candidate_bank_rows(
    indexed: dict[int, dict[date, list[tuple]]],
    station_id: int,
    cut: date,
    used: set[int],
    target: float,
    tolerance: float = TOLERANCE,
) -> list[tuple]:
    """Return the same unique-candidate pool as the old per-cut query."""
    candidates: list[tuple] = []
    station_rows = indexed.get(station_id, {})
    for offset in range(8):
        for bank in station_rows.get(cut + timedelta(days=offset), ()):
            if int(bank[0]) not in used and abs(amount(bank[2]) - target) <= tolerance:
                candidates.append(bank)
    return candidates


def gasomex_next_turn(day: date, turn: str) -> tuple[date, str]:
    return (day, str(int(turn) + 1)) if turn != "4" else (day + timedelta(days=1), "1")


def gasomex_slots(controlgas_rows: list[dict], links: dict, active_keys: set[str], station_id: int) -> dict[str, dict]:
    """Keep every operational turn, including zero-USD shifts and unsafe gaps."""
    slots: dict[str, dict] = {bucket: {} for bucket in ("MN", "USD")}
    for row in controlgas_rows:
        day = as_date(row.get("Fecha"))
        turn = turn_key(row.get("Turno"))
        if turn not in {"1", "2", "3", "4"}:
            continue
        key = (day, turn)
        for bucket in ("MN", "USD"):
            slot = slots[bucket].setdefault(key, {"items": [], "blocked": False})
            # Duplicate CG shifts make the chronology ambiguous.
            if "seen" in slot:
                slot["blocked"] = True
            slot["seen"] = True
            concepts = ("USD",) if bucket == "USD" else ("MN", "MORRALLA")
            for concept in concepts:
                raw = amount(row.get("Dolares")) + amount(row.get("Dolares2")) if concept == "USD" else amount(row.get("Morralla" if concept == "MORRALLA" else "MN"))
                if raw <= 0:
                    continue
                target = links.get((day.isoformat(), turn, concept))
                source_key = f"cg-{station_id}-{day.isoformat()}-{str(row.get('Turno', '')).strip()}-{concept}"
                if not target or target <= 0 or source_key in active_keys:
                    slot["blocked"] = True
                    continue
                slot["items"].append({"key": source_key, "date": day, "turn": str(row.get("Turno", "")).strip(), "concept": concept, "amount": target})
    return slots


def gasomex_candidates(bank: tuple, slots: dict[str, dict], used_keys: set[str], station_id: int) -> list[dict]:
    """Find only continuous 3–9 shift runs ending after T1 or T2."""
    found: list[dict] = []
    bank_date = as_date(bank[1])
    bank_amount = amount(bank[2])
    for bucket in gasomex_bank_buckets(bank, station_id):
        by_turn = slots[bucket]
        for start_day, start_turn in sorted(by_turn):
            if start_turn not in {"2", "3"}:
                continue
            day, turn = start_day, start_turn
            items: list[dict] = []
            total = 0.0
            for length in range(1, 10):
                slot = by_turn.get((day, turn))
                if not slot or slot["blocked"] or any(item["key"] in used_keys for item in slot["items"]):
                    break
                items.extend(slot["items"])
                total = round(total + sum(item["amount"] for item in slot["items"]), 2)
                if length >= 3 and turn in {"1", "2"} and items and bank_date >= day and abs(bank_amount - total) < TOLERANCE:
                    found.append({"anchor": start_day, "last_date": day, "bucket": bucket, "items": items.copy(), "amount": total, "shifts": length})
                day, turn = gasomex_next_turn(day, turn)
    return found


def run() -> int:
    log("Inicio de ejecución; cargando configuración.")
    load_env_file()
    log(
        "Configuración lista: "
        f"DB={os.environ.get('EFC_CONC_DB_HOST', '<no configurada>')}/"
        f"{os.environ.get('EFC_CONC_DB_NAME', '<no configurada>')}, "
        f"ControlGas={'configurado' if os.environ.get('EFC_CONC_CONTROLGAS_URL') else 'predeterminado'}, "
        f"timeout={os.environ.get('EFC_CONC_CONTROLGAS_TIMEOUT', '30')}s."
    )
    try:
        conn = connect(); cursor = conn.cursor()
    except Exception as exc:
        log(f"Error DB al conectar ({type(exc).__name__}): {error_text(exc)}")
        raise
    run_id = None
    try:
        ensure_schema(cursor); conn.commit()
        log("Esquema listo.")
        if not acquire_lock(cursor):
            conn.rollback(); log("Otra ejecución automática mantiene el applock; no se procesa nada."); return 0
        log("Applock adquirido.")
        run_id = cursor.execute("INSERT dbo.efc_conc_ejecuciones_automaticas(estado) OUTPUT inserted.id VALUES('EJECUTANDO')").fetchone()[0]; conn.commit()
        today = date.today()
        current_month_first = today.replace(day=1)
        # During days 1–4, include the full previous month so late bank
        # movements and station corrections can still be reconciled.
        first = (current_month_first - timedelta(days=1)).replace(day=1) if today.day <= 4 else current_month_first
        last = today
        allowed = ",".join(str(value) for value in sorted(COMPANY_STATIONS))
        stations = cursor.execute(f"SELECT Codigo,Nombre,Estacion FROM TG.dbo.Estaciones WHERE Codigo IN ({allowed}) AND Nombre<>'NO FUNCIONA'").fetchall()
        previous_month_included = today.day <= 4
        log(
            f"Ejecución {run_id}: ventana {first.isoformat()} a {last.isoformat()}"
            f"{' (incluye el mes anterior, días 1–4)' if previous_month_included else ''}; "
            f"{len(stations)} estaciones."
        )
        used = {int(row[0]) for row in cursor.execute("SELECT P.movimiento_bancario_id FROM dbo.efc_conc_partidas P JOIN dbo.efc_conc_grupos G ON G.id=P.grupo_id WHERE P.origen='BANCO' AND P.activo=1 AND G.estado='ACTIVA'").fetchall()}
        bank_rows = matching_bank_rows(cursor, first, last)
        inferred_stations = infer_unassigned_bank_stations(cursor, bank_rows, stations, used, first, last)
        unassigned_count = sum(
            1 for bank in bank_rows
            if bank[5] is None and resolved_bank_station(bank, stations) is None
        )
        log(
            f"Depósitos sin estación detectada: {unassigned_count}; "
            f"coincidencias exactas y únicas con REGIO real: {len(inferred_stations)}."
        )
        bank_index = index_bank_rows(bank_rows, stations, inferred_stations)
        indexed_count = sum(len(rows) for by_date in bank_index.values() for rows in by_date.values())
        log(f"Movimientos bancarios elegibles: {len(bank_rows)}; clasificados: {indexed_count}; usados: {len(used)}.")
        matched = skipped = errors = 0; details: list[str] = []
        for station_number, (station_id, name, code) in enumerate(stations, 1):
            log(f"Estación {station_number}/{len(stations)} inicia: {station_id} {name}.")
            try:
                links = {(str(row[0]), turn_key(row[1]), str(row[2])): amount(row[3]) for row in cursor.execute("""SELECT CONVERT(CHAR(10),V.fecha_cg,23),V.turno,V.concepto,
                    CASE WHEN V.concepto='USD' THEN ISNULL(P.real_usd,0)*ISNULL(V.tipo_cambio_usd,0) ELSE ISNULL(P.real_mn,0) END
                    FROM dbo.efc_conc_analiticos_vinculos V JOIN dbo.efc_conc_analiticos_papeletas P ON P.id=V.papeleta_id
                    WHERE V.estacion_id=? AND V.activo=1""", station_id).fetchall()}
                # Nine shifts can span parts of three operational days across
                # a month boundary. Extra days contain only candidate shifts;
                # the bank's actual date still determines eligibility.
                controlgas_first = first - timedelta(days=3) if int(station_id) in GASOMEX_STATIONS else first
                controlgas_last = last + timedelta(days=2) if int(station_id) in GASOMEX_STATIONS else last
                turn_rows = fetch_station_turns(cursor, int(station_id), controlgas_first, controlgas_last)
                source_name = "cortes Praxedis" if int(station_id) == 40 else "ControlGas"
                log(f"Estación {station_id}: {source_name} devolvió {len(turn_rows)} registros.")
                if int(station_id) in GASOMEX_STATIONS:
                    active_cg_keys = {
                        str(row[0]) for row in cursor.execute("""SELECT P.clave_externa
                            FROM dbo.efc_conc_partidas P
                            JOIN dbo.efc_conc_grupos G ON G.id=P.grupo_id
                            WHERE P.estacion_id=? AND P.origen='CG' AND P.activo=1 AND G.estado='ACTIVA'""", station_id).fetchall()
                    }
                    transit_keys = {
                        str(row[0]) for row in cursor.execute("""SELECT clave_externa
                            FROM dbo.efc_conc_transitos
                            WHERE estacion_id=? AND estado='PENDIENTE'""", station_id).fetchall()
                    }
                    active_cg_keys.update(transit_keys)
                    slots = gasomex_slots(turn_rows, links, active_cg_keys, int(station_id))
                    gas_bank_rows = [
                        bank for bank in bank_rows
                        if int(bank[0]) not in used
                        and gasomex_account_matches(bank, int(station_id))
                        and resolved_bank_station(bank, stations) in (None, int(station_id))
                        and inferred_stations.get(int(bank[0]), (int(station_id),))[0] == int(station_id)
                    ]
                    reserved_keys: set[str] = set(active_cg_keys)
                    log(f"GASOMEX estación={station_id}: vínculos activos={len(links)}, turnos MN={len(slots['MN'])}, turnos USD={len(slots['USD'])}, turnos bloqueados por tránsito={len(transit_keys)}, depósitos por cuenta={len(gas_bank_rows)}.")
                    no_sequence = ambiguous = 0
                    for bank in sorted(gas_bank_rows, key=lambda item: (as_date(item[1]), int(item[0]))):
                        candidates = gasomex_candidates(bank, slots, reserved_keys, int(station_id))
                        if len(candidates) != 1:
                            if candidates:
                                ambiguous += 1
                                log(f"GASOMEX depósito ambiguo: estación={station_id}, banco={bank[0]}, importe={amount(bank[2]):.2f}, secuencias={len(candidates)}; revisión manual.")
                            else:
                                no_sequence += 1
                            continue
                        lot = candidates[0]
                        picked = lot["items"]
                        cg_total = lot["amount"]
                        bank_date = as_date(bank[1])
                        log(f"GASOMEX candidato: estación={station_id}, banco={bank[0]}, lote={lot['anchor']}, bucket={lot['bucket']}, fecha_banco={bank_date}, importe_banco={amount(bank[2]):.2f}, importe_lote={cg_total:.2f}, turnos={lot['shifts']}, partidas={len(picked)}.")
                        payload = "<turns>" + "".join(
                            f'<turn id="{escape(item["key"])}" date="{item["date"].isoformat()}" turn="{escape(item["turn"])}" currency="{escape(item["concept"])}" amount="{item["amount"]:.2f}" />'
                            for item in picked
                        ) + "</turns>"
                        try:
                            inferred = inferred_stations.get(int(bank[0]))
                            if inferred:
                                save_inferred_station_correction(cursor, int(bank[0]), int(station_id), inferred[1], int(run_id))
                            cursor.execute("EXEC dbo.usp_efc_conc_guardar_gasomex ?,?,?,?,?,?,?,?", station_id, lot["anchor"], payload, bank[0], bank[1], amount(bank[2]), bank[3], run_id)
                            conn.commit(); used.add(int(bank[0])); reserved_keys.update(item["key"] for item in picked); matched += 1
                            log(f"Conciliada GASOMEX: estación={station_id}, lote={lot['anchor']}, bucket={lot['bucket']}, turnos={lot['shifts']}, banco={bank[0]}.")
                        except pyodbc.Error as exc:
                            conn.rollback(); errors += 1
                            log(f"Error DB conciliando GASOMEX estación={station_id}, banco={bank[0]}: {error_text(exc)}")
                            details.append(f"GASOMEX/{station_id}/banco/{bank[0]}: {exc}")
                    log(f"GASOMEX estación={station_id}: depósitos sin secuencia={no_sequence}, ambiguos={ambiguous}.")
                    continue
                for row in turn_rows:
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
                        tolerance = PARRAL_TOLERANCE if "PARRAL" in str(name).upper() else TOLERANCE
                        candidates = candidate_bank_rows(bank_index, int(station_id), cut, used, target, tolerance)
                        if len(candidates) != 1:
                            skipped += 1; continue
                        bank = candidates[0]
                        try:
                            inferred = inferred_stations.get(int(bank[0]))
                            if inferred:
                                save_inferred_station_correction(cursor, int(bank[0]), int(station_id), inferred[1], int(run_id))
                            cursor.execute("EXEC dbo.usp_efc_conc_guardar_automatica ?,?,?,?,?,?,?,?,?,?", station_id, cut, turn, concept, cg, bank[0], bank[1], amount(bank[2]), bank[3], run_id)
                            conn.commit(); used.add(int(bank[0])); matched += 1
                            log(f"Conciliada: estación={station_id}, fecha={cut}, turno={turn}, concepto={concept}, banco={bank[0]}.")
                        except pyodbc.Error as exc:
                            conn.rollback(); errors += 1
                            log(f"Error DB conciliando estación={station_id}, fecha={cut}, turno={turn}, concepto={concept}: {error_text(exc)}")
                            details.append(f"{station_id}/{cut}/{turn}/{concept}: {exc}")
            except Exception as exc:
                conn.rollback(); errors += 1
                category = "API" if not isinstance(exc, pyodbc.Error) else "DB"
                log(f"Error {category} en estación {station_id} ({type(exc).__name__}): {error_text(exc)}")
                details.append(f"estación {station_id}: {exc}")
            log(f"Estación {station_id} completa; acumulado: {matched} conciliadas, {skipped} omitidas, {errors} errores.")
        cursor.execute("UPDATE dbo.efc_conc_ejecuciones_automaticas SET estado=?,fin_en=SYSDATETIME(),conciliadas=?,omitidas=?,errores=?,detalle=? WHERE id=?", "PARCIAL" if errors else "COMPLETADA", matched, skipped, errors, "\n".join(details[-100:]), run_id)
        conn.commit(); log(f"Ejecución {run_id} finalizada: {matched} conciliadas, {skipped} omitidas, {errors} errores."); return 0
    except Exception as exc:
        conn.rollback()
        log(f"Error DB/fatal en ejecución {run_id or '<sin id>'} ({type(exc).__name__}): {error_text(exc)}")
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
