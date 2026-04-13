import os
import sys
import time
import pickle
import gspread
import pyodbc
from datetime import datetime
from google_auth_oauthlib.flow import InstalledAppFlow
from google.auth.transport.requests import Request

# --- CONFIGURACIÓN ---
SHEET_URLS = [
    'https://docs.google.com/spreadsheets/d/12JYPROfP0olmGo6z_R1H8vMFcULB5Ue0lWzml5b3aLk/edit?',
    'https://docs.google.com/spreadsheets/d/1nH7JFysa3HwQdZX7kB2kLRW8OPnPCSO9_HFPAEyOpic/edit?',
    'https://docs.google.com/spreadsheets/d/1ctjrtht_bBfECfQAmdlqqGfQnMIC6ntbwNk4PcQ19ks/edit?',
    'https://docs.google.com/spreadsheets/d/1H_WfhEe9rcmltOF8FXQ8nXSVZDqYor5D_wP8VBbGBhY/edit?',
    'https://docs.google.com/spreadsheets/d/1bf4Wi7xofHJJYewHdwAcmyTtJZzNfCuXSkTJPmo1xaw/edit?',
]

# Mapeo de Hojas - todas se buscan en todos los documentos
CONFIG_HOJAS = [
    # BANORTE estándar
    {'hoja': '0956',              'tabla': 'Tesoreria_0956',   'banco': 'BANORTE'},
    {'hoja': 'A 9475 ',           'tabla': 'Tesoreria_A9475',  'banco': 'BANORTE'},
    {'hoja': 'FG 4113',           'tabla': 'Tesoreria_FG4113', 'banco': 'BANORTE'},
    # AMEX cuenta bancaria (misma estructura que Banorte STD)
    {'hoja': '8520 EC',           'tabla': 'Tesoreria_8520',   'banco': 'AMEX'},
    # SANTANDER / AMEX estándar
    {'hoja': '5117',              'tabla': 'Tesoreria_5117',   'banco': 'SANTANDER_AMEX'},
    {'hoja': '8973 HA',           'tabla': 'Tesoreria_8973',   'banco': 'SANTANDER'},
    # AFIRME - ambas hojas a la misma tabla
    {'hoja': 'AFIRME DG',         'tabla': 'Tesoreria_Afirme', 'banco': 'AFIRME'},
    {'hoja': 'AFIRME FIDE',       'tabla': 'Tesoreria_Afirme', 'banco': 'AFIRME'},
    # AMEX tarjetas (Fecha, Hora, Referencia, Descripcion, Depositos, Concepto)
    {'hoja': 'SJ 8504',           'tabla': 'Tesoreria_8504',   'banco': 'AMEX'},
    {'hoja': 'SC 8492',           'tabla': 'Tesoreria_8492',   'banco': 'AMEX'},
    {'hoja': 'SG 4547',           'tabla': 'Tesoreria_4547',   'banco': 'AMEX'},
    {'hoja': 'SG 8214',           'tabla': 'Tesoreria_8214',   'banco': 'AMEX'},
    {'hoja': 'SG 4638',           'tabla': 'Tesoreria_4638',   'banco': 'AMEX'},
    {'hoja': 'SG 4777',           'tabla': 'Tesoreria_4777',   'banco': 'AMEX'},
    # SANTANDER extendido (Fecha, Hora, Sucursal, Descripcion, Retiros, Depositos, Saldo, Referencia, Concepto)
    {'hoja': 'A 6115 ',           'tabla': 'Tesoreria_A6115',  'banco': 'SANTANDER'},
    {'hoja': 'SERVICIO SYC 5791', 'tabla': 'Tesoreria_5791',   'banco': 'SANTANDER'},
    {'hoja': '2951 EC SANT',      'tabla': 'Tesoreria_2951',   'banco': 'SANTANDER'},
    {'hoja': 'TSA 4098',          'tabla': 'Tesoreria_4098',   'banco': 'SANTANDER'},
    # Hojas compartidas Santander + Amex (misma tabla)
    {'hoja': 'Castaño 5247 sant', 'tabla': 'Tesoreria_5247',   'banco': 'SANTANDER'},
    {'hoja': 'Castaño 5247 sant', 'tabla': 'Tesoreria_5247',   'banco': 'AMEX'},
    {'hoja': 'PICACHOS 7291 MX',  'tabla': 'Tesoreria_7291',   'banco': 'SANTANDER'},
    {'hoja': 'PICACHOS 7291 MX',  'tabla': 'Tesoreria_7291',   'banco': 'AMEX'},
    {'hoja': 'VENTANAS 7533 MX',  'tabla': 'Tesoreria_7533',   'banco': 'SANTANDER'},
    {'hoja': 'VENTANAS 7533 MX',  'tabla': 'Tesoreria_7533',   'banco': 'AMEX'},
    {'hoja': 'SG 4669',  'tabla': 'Tesoreria_4669',   'banco': 'AMEX'},

]

# --- GRUPOS DE TABLAS POR ESTRUCTURA ---

# Banorte: Fecha, Referencia, Descripcion, CodTransaccion, Sucursal, Depositos, Retiros, Saldo, MovimientoID, DescripcionDetallada
TABLAS_BANORTE_STD = {'Tesoreria_0956', 'Tesoreria_A9475', 'Tesoreria_FG4113', 'Tesoreria_8520'}

# AMEX tarjetas: Fecha, Hora, Referencia, Descripcion, Depositos, Concepto
TABLAS_VENTAS_DIA = set()

# Santander / AMEX extendido: Fecha, Hora, Sucursal, Descripcion, Retiros, Depositos, Saldo, Referencia, Concepto
TABLAS_SANT_EXT = {'Tesoreria_A6115', 'Tesoreria_5791', 'Tesoreria_2951', 'Tesoreria_5247',
                   'Tesoreria_4098', 'Tesoreria_7291', 'Tesoreria_7533',
                   'Tesoreria_8504', 'Tesoreria_8492', 'Tesoreria_4547', 'Tesoreria_8214', 
                   'Tesoreria_4638', 'Tesoreria_4777', 'Tesoreria_4669'}

# Credenciales BD
SERVER = '192.168.0.6'
DATABASE = 'TG'
USERNAME = 'cguser'
PASSWORD = 'sahei1712'

# Google Auth
ruta_token = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'token.pickle')
CLIENT_CONFIG = {
    "installed": {
        "client_id": "823696453524-elp0dg2hf2ljb3s3fcshbqkv3csqdis9.apps.googleusercontent.com",
        "project_id": "fine-method-479420-c1",
        "auth_uri": "https://accounts.google.com/o/oauth2/auth",
        "token_uri": "https://oauth2.googleapis.com/token",
        "auth_provider_x509_cert_url": "https://www.googleapis.com/oauth2/v1/certs",
        "client_secret": "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",
        "redirect_uris": ["http://localhost"]
    }
}
SCOPES = ['https://www.googleapis.com/auth/spreadsheets', 'https://www.googleapis.com/auth/drive']

def obtener_credenciales():
    creds = None
    if os.path.exists(ruta_token):
        with open(ruta_token, 'rb') as token:
            try: creds = pickle.load(token)
            except: pass
    if not creds or not creds.valid:
        if creds and creds.expired and creds.refresh_token:
            try: creds.refresh(Request())
            except: creds = None
        if not creds:
            flow = InstalledAppFlow.from_client_config(CLIENT_CONFIG, SCOPES)
            creds = flow.run_local_server(port=0)
        with open(ruta_token, 'wb') as token:
            pickle.dump(creds, token)
    return creds

def limpiar_moneda(valor):
    if isinstance(valor, (int, float)): return float(valor)
    if not valor: return 0.0
    s = str(valor).replace('$', '').replace(',', '').strip()
    try: return float(s)
    except: return 0.0

def limpiar_fecha(valor):
    if not valor: return None
    s = str(valor).strip().replace("'", "")

    if len(s) == 8 and s.isdigit():
        try:
            return datetime(int(s[4:8]), int(s[2:4]), int(s[0:2])).strftime('%Y-%m-%d')
        except: pass

    formatos = ['%d/%m/%Y', '%d-%m-%Y', '%Y-%m-%d', '%d/%m/%y']
    for fmt in formatos:
        try:
            return datetime.strptime(s, fmt).strftime('%Y-%m-%d')
        except: continue
    return None

def obtener_indice(headers, posibles_nombres):
    if isinstance(posibles_nombres, str): posibles_nombres = [posibles_nombres]
    buscados = [x.lower().strip() for x in posibles_nombres]

    for i, h in enumerate(headers):
        if str(h).lower().strip() in buscados: return i
    for i, h in enumerate(headers):
        encabezado_limpio = str(h).lower().strip()
        for b in buscados:
            if b in encabezado_limpio and len(b) > 3: return i
    return -1

def crear_tabla_si_no_existe(cursor, tabla):
    if tabla in TABLAS_BANORTE_STD:
        ddl = f"""
            IF OBJECT_ID('{tabla}', 'U') IS NULL
            CREATE TABLE {tabla} (
                ID                   INT IDENTITY(1,1) PRIMARY KEY,
                Fecha                DATE,
                Referencia           VARCHAR(100),
                Descripcion          VARCHAR(255),
                CodTransaccion       VARCHAR(50),
                Sucursal             VARCHAR(100),
                Depositos            DECIMAL(18,6),
                Retiros              DECIMAL(18,6),
                Saldo                DECIMAL(18,6),
                MovimientoID         VARCHAR(100),
                DescripcionDetallada VARCHAR(500)
            )"""
    elif tabla in TABLAS_VENTAS_DIA:
        ddl = f"""
            IF OBJECT_ID('{tabla}', 'U') IS NULL
            CREATE TABLE {tabla} (
                ID          INT IDENTITY(1,1) PRIMARY KEY,
                Fecha       DATE,
                Hora        VARCHAR(20),
                Referencia  VARCHAR(100),
                Descripcion VARCHAR(255),
                Depositos   DECIMAL(18,6),
                Concepto    VARCHAR(255)
            )"""
    elif tabla in TABLAS_SANT_EXT:
        ddl = f"""
            IF OBJECT_ID('{tabla}', 'U') IS NULL
            CREATE TABLE {tabla} (
                ID          INT IDENTITY(1,1) PRIMARY KEY,
                Fecha       DATE,
                Hora        VARCHAR(20),
                Sucursal    VARCHAR(100),
                Descripcion VARCHAR(255),
                Retiros     DECIMAL(18,6),
                Depositos   DECIMAL(18,6),
                Saldo       DECIMAL(18,6),
                Referencia  VARCHAR(100),
                Concepto    VARCHAR(255)
            )"""
    else:
        return True  # tabla estándar, ya existe

    try:
        cursor.execute(ddl)
        cursor.commit()
    except Exception as e:
        print(f"[WARN] Error al crear tabla {tabla}: {e}")
        return False
    return True

def sincronizar_hoja(sheet_doc, cursor, config):
    hoja_buscada = config['hoja']
    tabla = config['tabla']

    doc_title = sheet_doc.title
    print(f"\n---------------------------------------------------")
    print(f"[INFO] Procesando Doc: '{doc_title}' | Hoja: {hoja_buscada} -> {tabla}")

    try:
        # Intentar coincidencia exacta primero
        try:
            ws = sheet_doc.worksheet(hoja_buscada)
        except gspread.exceptions.WorksheetNotFound:
            # Intentar coincidencia flexible (sin espacios)
            hojas_disponibles = sheet_doc.worksheets()
            nombres_hojas = {w.title.strip().upper(): w for w in hojas_disponibles}
            hoja_limpia = hoja_buscada.strip().upper()
            
            if hoja_limpia in nombres_hojas:
                ws = nombres_hojas[hoja_limpia]
            else:
                # Silencioso por defecto para otros documentos, 
                # pero podemos imprimir si es el doc que esperamos tenga la hoja
                # print(f"[DEBUG] Hoja '{hoja_buscada}' no encontrada en '{doc_title}'")
                return

        time.sleep(7.0)  # evitar rate limit (60 req/min por usuario)
        filas_crudas = ws.get_all_values()
    except Exception as e:
        print(f"[WARN] Error al obtener datos de hoja '{hoja_buscada}' en '{doc_title}': {e}")
        return

    if len(filas_crudas) < 2: 
        print(f"[INFO] {hoja_buscada}: Hoja vacia o solo con encabezados.")
        return

    headers = filas_crudas[0]
    datos   = filas_crudas[1:]

    hoja = ws.title # Usar el nombre real de la hoja encontrada

    if hoja == '8973 HA' and (not headers[0] or headers[0].strip() == ''):
        headers[0] = 'Fecha'

    if hoja in ('AFIRME DG', 'AFIRME FIDE'):
        # Los headers en GSheets no coinciden con el layout real de los datos
        # Layout real: Concepto, Fecha, Referencia, Cargo, Abono, Saldo
        headers[0] = 'Concepto'
        headers[1] = 'Fecha'
        headers[2] = 'Referencia'
        headers[3] = 'Cargo'
        headers[4] = 'Abono'
        headers[5] = 'Saldo'

    # --- MAPEO COMÚN ---
    idx_fecha = obtener_indice(headers, ['Fecha', 'Dia', 'F. Operación', 'Fecha de Operación', 'Fecha De Operación'])
    idx_desc  = obtener_indice(headers, ['Descripcion', 'Descripción', 'Movimiento', 'Concepto'])
    idx_dep   = obtener_indice(headers, ['Depósitos', 'Depositos', 'Abono', 'Crédito', 'Importe Abono', 'Importe', 'Monto'])
    idx_ret   = obtener_indice(headers, ['Retiros', 'Cargo', 'Débito', 'Importe Cargo'])
    idx_ref   = obtener_indice(headers, ['Referencia', 'Ref', 'Referencia 1', 'No. Referencia'])
    idx_suc   = obtener_indice(headers, ['Sucursal', 'Suc.', 'Suc'])
    idx_id    = obtener_indice(headers, ['MovimientoID', 'Id', 'No. Movimiento', 'Movimiento'])
    idx_saldo = obtener_indice(headers, ['Saldo'])


    # --- FLAGS DE GRUPO ---
    es_banorte_std = tabla in TABLAS_BANORTE_STD
    es_5117        = (tabla == 'Tesoreria_5117')
    es_ventas_dia  = tabla in TABLAS_VENTAS_DIA
    es_sant_ext    = tabla in TABLAS_SANT_EXT

    # --- MAPEO ESPECÍFICO POR GRUPO ---
    idx_det  = -1
    idx_con  = -1
    idx_hora = -1

    if es_banorte_std:
        idx_det = obtener_indice(headers, ['Descripción Detallada', 'Descripcion Detallada', 'Detalle'])

    if es_5117 or es_ventas_dia or es_sant_ext:
        idx_hora = obtener_indice(headers, ['Hora'])
        idx_con  = obtener_indice(headers, ['Concepto'])

    if idx_fecha == -1:
        print("[ERROR] Sin columna Fecha.")
        return

    # Crear tabla si no existe
    if not crear_tabla_si_no_existe(cursor, tabla):
        return

    # Cargar huellas existentes
    try:
        cursor.execute(f"SELECT Fecha, Descripcion, Depositos FROM {tabla}")
        rows = cursor.fetchall()
        huellas = set(f"{r[0]}|{str(r[1])[:20].strip()}|{float(r[2]):.6f}" for r in rows)
    except Exception as e:
        print(f"[WARN] Error BD ({tabla}): {e}")
        return

    nuevos = []

    for f in datos:
        raw_fecha = f[idx_fecha] if len(f) > idx_fecha else ''
        fecha_sql = limpiar_fecha(raw_fecha)
        if not fecha_sql: continue

        desc  = f[idx_desc].strip()  if idx_desc  != -1 and len(f) > idx_desc  else ''
        dep   = limpiar_moneda(f[idx_dep])  if idx_dep  != -1 and len(f) > idx_dep  else 0.0
        ret   = limpiar_moneda(f[idx_ret])  if idx_ret  != -1 and len(f) > idx_ret  else 0.0
        ref   = f[idx_ref].strip()   if idx_ref   != -1 and len(f) > idx_ref   else ''
        suc   = f[idx_suc].strip()   if idx_suc   != -1 and len(f) > idx_suc   else ''
        saldo = limpiar_moneda(f[idx_saldo]) if idx_saldo != -1 and len(f) > idx_saldo else 0.0
        mov_id = f[idx_id].strip()   if idx_id    != -1 and len(f) > idx_id    else ''
        hora   = f[idx_hora].strip() if idx_hora  != -1 and len(f) > idx_hora  else ''
        concepto = f[idx_con].strip() if idx_con  != -1 and len(f) > idx_con   else ''

        huella = f"{fecha_sql}|{desc[:20].strip()}|{dep:.6f}"
        if huella in huellas: continue
        if desc == '' and dep == 0 and ret == 0: continue

        if es_banorte_std:
            detalle = f[idx_det].strip() if idx_det != -1 and len(f) > idx_det else ''
            nuevos.append((fecha_sql, ref, desc, '', suc, dep, ret, 0.0, mov_id, detalle))

        elif es_5117:
            nuevos.append((fecha_sql, ref, desc, '', suc, dep, ret, 0.0, mov_id, concepto))

        elif es_ventas_dia:
            nuevos.append((fecha_sql, hora, ref, desc, dep, concepto))

        elif es_sant_ext:
            nuevos.append((fecha_sql, hora, suc, desc, ret, dep, saldo, ref, concepto))

        else:
            # Afirme, 8973 (estructura estándar sin extras)
            nuevos.append((fecha_sql, ref, desc, '', suc, dep, ret, 0.0, mov_id))

    print(f"[INFO] {hoja}: {len(nuevos)} registros nuevos encontrados.")

    if nuevos:
        if es_banorte_std:
            sql = f"INSERT INTO {tabla} (Fecha, Referencia, Descripcion, CodTransaccion, Sucursal, Depositos, Retiros, Saldo, MovimientoID, DescripcionDetallada) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        elif es_5117:
            sql = f"INSERT INTO {tabla} (Fecha, Referencia, Descripcion, CodTransaccion, Sucursal, Depositos, Retiros, Saldo, MovimientoID, Concepto) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        elif es_ventas_dia:
            sql = f"INSERT INTO {tabla} (Fecha, Hora, Referencia, Descripcion, Depositos, Concepto) VALUES (?, ?, ?, ?, ?, ?)"
        elif es_sant_ext:
            sql = f"INSERT INTO {tabla} (Fecha, Hora, Sucursal, Descripcion, Retiros, Depositos, Saldo, Referencia, Concepto) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        else:
            sql = f"INSERT INTO {tabla} (Fecha, Referencia, Descripcion, CodTransaccion, Sucursal, Depositos, Retiros, Saldo, MovimientoID) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"

        cursor.executemany(sql, nuevos)
        cursor.commit()
        print("[OK] Guardado en BD con exito.")
    else:
        print("[INFO] Sin cambios.")

def main():
    creds = obtener_credenciales()
    client = gspread.authorize(creds)
    try:
        conn = pyodbc.connect(f'DRIVER={{SQL Server}};SERVER={SERVER};DATABASE={DATABASE};UID={USERNAME};PWD={PASSWORD}')
        cursor = conn.cursor()

        for url in SHEET_URLS:
            try:
                print(f"\n[INFO] Abriendo documento: {url}")
                sheet_doc = client.open_by_url(url)

                for c in CONFIG_HOJAS:
                    sincronizar_hoja(sheet_doc, cursor, c)

            except Exception as e:
                print(f"[ERROR] Error al abrir documento {url}: {e}")

            time.sleep(5)  # pausa entre documentos

        conn.close()
    except Exception as e:
        print(f"[ERROR] Error General / DB: {e}")

if __name__ == '__main__':
    main()
