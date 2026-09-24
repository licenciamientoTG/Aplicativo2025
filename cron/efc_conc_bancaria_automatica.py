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
COMPANY_STATIONS = {
    2, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 21, 22,
    23, 24, 25, 26, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36, 37, 38, 39, 199,
}
GASOMEX_STATIONS = {23, 24, 25, 26, 27, 28, 29}
GASOMEX_ACCOUNT_STATIONS = {
    "4409": {29}, "4547": {23}, "8214": {23}, "8492": {25, 26},
    "4412": {26}, "4777": {27}, "4669": {28}, "3678": {28}, "4638": {24},
}
# Same known-account universe as EfcConciliacionModel::allAccountSuffixes().
ACCOUNT_SUFFIXES = (
    "0185322470", "369", "3281", "8837", "8520", "7291", "2570", "7533",
    "2627", "5247", "7604", "0031", "4409", "4547", "8214", "8492",
    "4412", "4777", "4669", "3678", "4638",
)


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
      DECLARE @concepto VARCHAR(20)='MN',@importe_cg DECIMAL(18,2)=(SELECT ROUND(SUM(importe),2) FROM @cg),@turno VARCHAR(20)=CASE WHEN (SELECT COUNT(*) FROM @cg)>1 THEN 'VARIOS' ELSE (SELECT TOP 1 turno FROM @cg) END;
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


def matching_bank_rows(cursor: pyodbc.Cursor, first: date, last: date | None = None) -> list[tuple]:
    # Corrections take precedence. Bank account must still belong to the
    # controlled account universe, matching validateDeposit's account gate.
    account_where = " OR ".join("RIGHT(UPPER(REPLACE(REPLACE(ISNULL(M.cuenta,''),'-',''),' ','')),LEN(?))=?" for _ in ACCOUNT_SUFFIXES)
    # A single run covers every possible cut window: first of month through
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


def index_bank_rows(rows: list[tuple], stations: list[tuple]) -> dict[int, dict[date, list[tuple]]]:
    """Classify each eligible movement once and index it by station and date."""
    indexed: dict[int, dict[date, list[tuple]]] = {}
    for bank in rows:
        station_id = resolved_bank_station(bank, stations)
        if station_id is None:
            continue
        bank_date = as_date(bank[1])
        indexed.setdefault(station_id, {}).setdefault(bank_date, []).append(bank)
    return indexed


def candidate_bank_rows(
    indexed: dict[int, dict[date, list[tuple]]], station_id: int, cut: date, used: set[int], target: float
) -> list[tuple]:
    """Return the same unique-candidate pool as the old per-cut query."""
    candidates: list[tuple] = []
    station_rows = indexed.get(station_id, {})
    for offset in range(8):
        for bank in station_rows.get(cut + timedelta(days=offset), ()):
            if int(bank[0]) not in used and abs(amount(bank[2]) - target) <= TOLERANCE:
                candidates.append(bank)
    return candidates


def gasomex_lots(turns: list[dict]) -> list[dict]:
    """Build the known GASOMEX operational lots.

    The deposit for operational day D contains D/T2, D/T3, D/T4 and
    (D+1)/T1.  The bank date is intentionally not part of this grouping:
    deposits may arrive several days after the operational lot.
    """
    by_turn: dict[tuple[date, str], list[dict]] = {}
    for item in turns:
        by_turn.setdefault((item["date"], turn_key(item["turn"])), []).append(item)

    lots: list[dict] = []
    anchors = sorted({item["date"] for item in turns})
    for anchor in anchors:
        required = [(anchor, "2"), (anchor, "3"), (anchor, "4"), (anchor + timedelta(days=1), "1")]
        if not all((day, turn) in by_turn for day, turn in required):
            continue
        items = [item for key in required for item in by_turn[key]]
        lots.append({
            "anchor": anchor,
            "last_date": anchor + timedelta(days=1),
            "items": items,
            "amount": round(sum(item["amount"] for item in items), 2),
        })
    return lots


def matching_gasomex_bank(lot: dict, banks: list[tuple], used: set[int]) -> list[tuple]:
    """Return deposits after the lot date that match its total.

    There is no assumed T+1 rule.  The earliest available matching movement
    is preferred by the caller, while a same-value duplicate remains visible
    in diagnostics instead of being solved through arbitrary turn subsets.
    """
    return [
        bank for bank in banks
        if int(bank[0]) not in used
        and as_date(bank[1]) >= lot["last_date"]
        and abs(amount(bank[2]) - lot["amount"]) < TOLERANCE
    ]


def gasomex_lot_diagnostics(lot: dict, banks: list[tuple], used: set[int]) -> dict:
    """Explain why a complete lot did not find a usable bank movement."""
    scoped = [bank for bank in banks if int(bank[0]) not in used]
    same_amount = [bank for bank in scoped if abs(amount(bank[2]) - lot["amount"]) < TOLERANCE]
    before_lot = [bank for bank in same_amount if as_date(bank[1]) < lot["last_date"]]
    nearest = sorted(scoped, key=lambda bank: (abs(amount(bank[2]) - lot["amount"]), as_date(bank[1]), int(bank[0])))[:3]
    return {
        "available": len(scoped),
        "same_amount": same_amount,
        "before_lot": before_lot,
        "nearest": nearest,
    }


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
        today = date.today(); first = today.replace(day=1); last = today
        allowed = ",".join(str(value) for value in sorted(COMPANY_STATIONS))
        stations = cursor.execute(f"SELECT Codigo,Nombre,Estacion FROM TG.dbo.Estaciones WHERE Codigo IN ({allowed}) AND Nombre<>'NO FUNCIONA'").fetchall()
        log(f"Ejecución {run_id}: ventana {first.isoformat()} a {last.isoformat()}; {len(stations)} estaciones.")
        used = {int(row[0]) for row in cursor.execute("SELECT P.movimiento_bancario_id FROM dbo.efc_conc_partidas P JOIN dbo.efc_conc_grupos G ON G.id=P.grupo_id WHERE P.origen='BANCO' AND P.activo=1 AND G.estado='ACTIVA'").fetchall()}
        bank_rows = matching_bank_rows(cursor, first, last)
        bank_index = index_bank_rows(bank_rows, stations)
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
                controlgas_first = first - timedelta(days=2) if int(station_id) in GASOMEX_STATIONS else first
                # GASOMEX lots include T1 of the following day, so include
                # that day even when the deposit itself arrives later.
                controlgas_last = last + timedelta(days=1) if int(station_id) in GASOMEX_STATIONS else last
                controlgas_rows = fetch_controlgas(int(station_id), controlgas_first, controlgas_last)
                log(f"Estación {station_id}: ControlGas devolvió {len(controlgas_rows)} registros.")
                if int(station_id) in GASOMEX_STATIONS:
                    turns: list[dict] = []
                    no_link = 0
                    no_amount = 0
                    active_cg_keys = {
                        str(row[0]) for row in cursor.execute("""SELECT P.clave_externa
                            FROM dbo.efc_conc_partidas P
                            JOIN dbo.efc_conc_grupos G ON G.id=P.grupo_id
                            WHERE P.estacion_id=? AND P.origen='CG' AND P.activo=1 AND G.estado='ACTIVA'""", station_id).fetchall()
                    }
                    already_conciliated = 0
                    for row in controlgas_rows:
                        cut = as_date(row.get("Fecha")); turn = str(row.get("Turno", "")).strip()
                        if not turn or cut < controlgas_first: continue
                        for concept, raw in (("MN", row.get("MN")), ("MORRALLA", row.get("Morralla")), ("USD", amount(row.get("Dolares")) + amount(row.get("Dolares2")))):
                            if amount(raw) <= 0:
                                no_amount += 1
                                continue
                            target = links.get((cut.isoformat(), turn_key(turn), concept))
                            if not target or target <= 0:
                                no_link += 1
                                continue
                            key = f"cg-{station_id}-{cut.isoformat()}-{turn}-{concept}"
                            if key in active_cg_keys:
                                already_conciliated += 1
                                continue
                            turns.append({"key": key, "date": cut, "turn": turn, "concept": concept, "amount": target})
                    gas_bank_rows = [bank for bank in bank_rows if int(bank[0]) not in used and gasomex_account_matches(bank, int(station_id))]
                    lots = gasomex_lots(turns)
                    log(f"GASOMEX estación={station_id}: vínculos activos={len(links)}, turnos elegibles={len(turns)}, ya conciliados={already_conciliated}, sin vínculo={no_link}, sin importe CG={no_amount}, lotes completos={len(lots)}, depósitos por cuenta={len(gas_bank_rows)}.")
                    for lot in lots:
                        candidates = matching_gasomex_bank(lot, gas_bank_rows, used)
                        if not candidates:
                            diagnostic = gasomex_lot_diagnostics(lot, gas_bank_rows, used)
                            before_ids = ",".join(f"{bank[0]}({bank[1]})" for bank in diagnostic["before_lot"][:5]) or "ninguno"
                            nearest_text = ", ".join(f"{bank[0]}:{as_date(bank[1])}/{amount(bank[2]):.2f}" for bank in diagnostic["nearest"]) or "ninguno"
                            log(f"GASOMEX lote pendiente: estación={station_id}, lote={lot['anchor']}, cubre_hasta={lot['last_date']}, importe={lot['amount']:.2f}, depósitos_disponibles={diagnostic['available']}, mismo_importe={len(diagnostic['same_amount'])}, mismos_anteriores={before_ids}, más_cercanos={nearest_text}.")
                            continue
                        candidates.sort(key=lambda item: (as_date(item[1]), int(item[0])))
                        bank = candidates[0]
                        if len(candidates) > 1:
                            log(f"GASOMEX lote con varios depósitos candidatos: estación={station_id}, lote={lot['anchor']}, candidatos={','.join(str(item[0]) for item in candidates)}; se toma el más antiguo {bank[0]}.")
                        picked = lot["items"]
                        cg_total = lot["amount"]
                        bank_date = as_date(bank[1])
                        log(f"GASOMEX candidato: estación={station_id}, banco={bank[0]}, lote={lot['anchor']}, fecha_banco={bank_date}, importe_banco={amount(bank[2]):.2f}, importe_lote={cg_total:.2f}, turnos={len(picked)}.")
                        payload = "<turns>" + "".join(
                            f'<turn id="{escape(item["key"])}" date="{item["date"].isoformat()}" turn="{escape(item["turn"])}" currency="MN" amount="{item["amount"]:.2f}" />'
                            for item in picked
                        ) + "</turns>"
                        try:
                            cursor.execute("EXEC dbo.usp_efc_conc_guardar_gasomex ?,?,?,?,?,?,?,?", station_id, lot["anchor"], payload, bank[0], bank[1], amount(bank[2]), bank[3], run_id)
                            conn.commit(); used.add(int(bank[0])); gas_bank_rows = [item for item in gas_bank_rows if int(item[0]) != int(bank[0])]; turns = [item for item in turns if item["key"] not in {chosen["key"] for chosen in picked}]; matched += 1
                            log(f"Conciliada GASOMEX: estación={station_id}, lote={lot['anchor']}, turnos={len(picked)}, banco={bank[0]}.")
                        except pyodbc.Error as exc:
                            conn.rollback(); errors += 1
                            log(f"Error DB conciliando GASOMEX estación={station_id}, banco={bank[0]}: {error_text(exc)}")
                            details.append(f"GASOMEX/{station_id}/banco/{bank[0]}: {exc}")
                    continue
                for row in controlgas_rows:
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
                        candidates = candidate_bank_rows(bank_index, int(station_id), cut, used, target)
                        if len(candidates) != 1:
                            skipped += 1; continue
                        bank = candidates[0]
                        try:
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
