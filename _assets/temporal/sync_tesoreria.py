import os
import sys
import pickle
import gspread
import pyodbc
from datetime import datetime
from google_auth_oauthlib.flow import InstalledAppFlow
from google.auth.transport.requests import Request

# --- CONFIGURACIÓN ---
# MODIFICACIÓN: Ahora es una lista con los dos documentos
SHEET_URLS = [
    'https://docs.google.com/spreadsheets/d/12JYPROfP0olmGo6z_R1H8vMFcULB5Ue0lWzml5b3aLk/edit?',

]

# Mapeo de Hojas
CONFIG_HOJAS = [
    # BANORTE
    {'hoja': '0956',        'tabla': 'Tesoreria_0956',   'banco': 'BANORTE'},
    # SANTANDER / AMEX
    {'hoja': '5117',        'tabla': 'Tesoreria_5117',   'banco': 'SANTANDER_AMEX'},
    {'hoja': '8973 HA',     'tabla': 'Tesoreria_8973',   'banco': 'SANTANDER'},
    # AFIRME (NUEVO) - Ambas hojas van a la misma tabla
    {'hoja': 'AFIRME DG',   'tabla': 'Tesoreria_Afirme', 'banco': 'AFIRME'},
    {'hoja': 'AFIRME FIDE', 'tabla': 'Tesoreria_Afirme', 'banco': 'AFIRME'}
]

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

# MODIFICACIÓN: Recibe el objeto 'sheet_doc' ya abierto en lugar de 'client'
def sincronizar_hoja(sheet_doc, cursor, config):
    hoja = config['hoja']
    tabla = config['tabla']
    banco = config['banco']
    
    # Obtenemos el título del documento para saber cuál estamos procesando
    doc_title = sheet_doc.title
    print(f"\n---------------------------------------------------")
    print(f"🟦 Procesando Doc: '{doc_title}' | Hoja: {hoja} -> {tabla}")

    try:
        # Ya no abrimos por URL aquí, usamos el objeto pasado
        ws = sheet_doc.worksheet(hoja)
        filas_crudas = ws.get_all_values()
    except Exception as e:
        print(f"⚠️  No se encontró la hoja '{hoja}' en '{doc_title}' o error: {e}")
        return

    if len(filas_crudas) < 2: return

    headers = filas_crudas[0]
    datos = filas_crudas[1:]

    if hoja == '8973 HA' and (not headers[0] or headers[0].strip() == ''):
        headers[0] = 'Fecha'
        
    # --- MAPEO COMÚN ---
    idx_fecha = obtener_indice(headers, ['Fecha', 'Dia', 'F. Operación'])
    idx_desc  = obtener_indice(headers, ['Descripcion', 'Descripción', 'Concepto', 'Movimiento'])
    idx_dep   = obtener_indice(headers, ['Depósitos', 'Depositos', 'Abono', 'Crédito', 'Importe Abono', 'Importe', 'Monto'])
    idx_ret   = obtener_indice(headers, ['Retiros', 'Cargo', 'Débito', 'Importe Cargo'])
    idx_ref   = obtener_indice(headers, ['Referencia', 'Ref', 'Referencia 1', 'No. Referencia'])
    idx_suc   = obtener_indice(headers, ['Sucursal'])
    idx_id    = obtener_indice(headers, ['MovimientoID', 'Id', 'No. Movimiento'])

    # --- MAPEO ESPECÍFICO ---
    idx_det = -1 
    idx_con = -1 

    es_0956 = (tabla == 'Tesoreria_0956')
    es_5117 = (tabla == 'Tesoreria_5117')
    
    if es_0956:
        idx_det = obtener_indice(headers, ['Descripción Detallada', 'Descripcion Detallada', 'Detalle'])
    
    if es_5117:
        idx_con = obtener_indice(headers, ['Concepto']) 

    if idx_fecha == -1:
        print("🔴 ERROR: Sin columna Fecha.")
        return

    # Cargar existentes
    try:
        cursor.execute(f"SELECT MovimientoID, Fecha, Descripcion, Depositos FROM {tabla}")
        rows = cursor.fetchall()
        huellas = set(f"{r[1]}|{str(r[2])[:20].strip()}|{float(r[3]):.2f}" for r in rows)
    except Exception as e:
        print(f"⚠️ Error BD ({tabla}): {e}")
        return

    nuevos = []
    
    # print("⚙️ Analizando filas...") # Comentado para limpiar output

    for i, f in enumerate(datos):
        raw_fecha = f[idx_fecha] if len(f) > idx_fecha else ''
        fecha_sql = limpiar_fecha(raw_fecha)
        if not fecha_sql: continue

        desc = f[idx_desc].strip() if idx_desc != -1 and len(f) > idx_desc else ''
        dep = limpiar_moneda(f[idx_dep]) if idx_dep != -1 and len(f) > idx_dep else 0.0
        ret = limpiar_moneda(f[idx_ret]) if idx_ret != -1 and len(f) > idx_ret else 0.0
        ref = f[idx_ref].strip() if idx_ref != -1 and len(f) > idx_ref else ''
        suc = f[idx_suc].strip() if idx_suc != -1 and len(f) > idx_suc else ''
        mov_id = f[idx_id].strip() if idx_id != -1 and len(f) > idx_id else ''

        huella = f"{fecha_sql}|{desc[:20].strip()}|{dep:.2f}"
        if huella in huellas: continue
        if desc == '' and dep == 0 and ret == 0: continue

        # CONSTRUIR TUPLA
        if es_0956:
            detalle = f[idx_det].strip() if idx_det != -1 and len(f) > idx_det else ''
            nuevos.append((fecha_sql, ref, desc, '', suc, dep, ret, 0.0, mov_id, detalle))
        
        elif es_5117:
            concepto = f[idx_con].strip() if idx_con != -1 and len(f) > idx_con else ''
            nuevos.append((fecha_sql, ref, desc, '', suc, dep, ret, 0.0, mov_id, concepto))
            
        else:
            # Afirme caerá aquí (estructura estándar)
            nuevos.append((fecha_sql, ref, desc, '', suc, dep, ret, 0.0, mov_id))

    print(f"📊 {hoja}: {len(nuevos)} registros nuevos encontrados.")

    if nuevos:
        if es_0956:
            sql = f"""INSERT INTO {tabla} (Fecha, Referencia, Descripcion, CodTransaccion, Sucursal, Depositos, Retiros, Saldo, MovimientoID, DescripcionDetallada) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"""
        elif es_5117:
            sql = f"""INSERT INTO {tabla} (Fecha, Referencia, Descripcion, CodTransaccion, Sucursal, Depositos, Retiros, Saldo, MovimientoID, Concepto) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"""
        else:
            sql = f"""INSERT INTO {tabla} (Fecha, Referencia, Descripcion, CodTransaccion, Sucursal, Depositos, Retiros, Saldo, MovimientoID) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"""
        
        cursor.executemany(sql, nuevos)
        cursor.commit()
        print("✅ Guardado en BD con éxito.")
    else:
        print("✨ Sin cambios.")

def main():
    creds = obtener_credenciales()
    client = gspread.authorize(creds)
    try:
        conn = pyodbc.connect(f'DRIVER={{SQL Server}};SERVER={SERVER};DATABASE={DATABASE};UID={USERNAME};PWD={PASSWORD}')
        cursor = conn.cursor()
        
        # MODIFICACIÓN: Bucle principal itera sobre las URLs
        for url in SHEET_URLS:
            try:
                print(f"\n📁 Abriendo documento: {url}")
                sheet_doc = client.open_by_url(url) # Abrimos el documento una vez
                
                # Para este documento abierto, corremos todas las configs
                for c in CONFIG_HOJAS:
                    sincronizar_hoja(sheet_doc, cursor, c)
                    
            except Exception as e:
                print(f"❌ Error al abrir documento {url}: {e}")

        conn.close()
    except Exception as e:
        print(f"🔥 Error General / DB: {e}")

if __name__ == '__main__':
    main()