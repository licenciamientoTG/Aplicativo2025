import time
import os
import shutil
import pyodbc
import pandas as pd
import unicodedata
import re
import traceback
from datetime import datetime, timedelta
from playwright.sync_api import sync_playwright

# --- CONFIGURACIÓN BD ---
SERVER = '192.168.0.6'
DATABASE = 'TG'
USERNAME = 'cguser'
PASSWORD = 'sahei1712'

# Mapeo de nombres de archivo a nombres de BD ESTANDARIZADOS
CORE_MAP = {
    'GETNET': {
        'ID movimiento': 'ID_Externo',
        'Fecha Transacción': 'Fecha_Transaccion',
        'Hora de Transacción': 'Hora',
        'Hora Transacción': 'Hora',
        'Afiliación': 'Afiliacion',
        'Nombre del comercio': 'Comercio',
        'Tipo de Transacción': 'Tipo_Transaccion',
        'Tipo Transacción': 'Tipo_Transaccion',
        'Tarjeta': 'Tarjeta',
        'Cod. Terminal': 'Terminal',
        'Terminal ID': 'Terminal',
        'Operación': 'Operacion',
        'Tipo de Tarjeta': 'Tipo_Tarjeta',
        'Tipo Tarjeta': 'Tipo_Tarjeta',
        'Número de Tarjeta': 'Tarjeta_Numero',
        'Tarjeta Número': 'Tarjeta_Numero',
        'Código Autorización': 'Codigo_Autorizacion',
        'Cod. Aut': 'Codigo_Autorizacion',
        'Total': 'Monto',
        'Monto de Transacción Signo': 'Monto',
        'Comisión': 'Comision',
        'Referencia': 'Referencia'
    }
}

COLUMNAS_OFICIALES = [
    'ID_Externo', 'Afiliacion', 'Fecha_Transaccion', 'Hora', 
    'Monto', 'Codigo_Autorizacion', 'Terminal', 'Referencia', 'Fecha_Deposito'
]

def obtener_conexion():
    return pyodbc.connect(f'DRIVER={{SQL Server}};SERVER={SERVER};DATABASE={DATABASE};UID={USERNAME};PWD={PASSWORD}')

def limpiar_moneda(valor):
    if isinstance(valor, (int, float)): return float(valor)
    if not valor: return 0.0
    s = str(valor).replace('$', '').replace(',', '').strip()
    try: return float(s)
    except: return 0.0

def limpiar_fecha(valor, formato_origen=None):
    if not valor or str(valor).lower() == 'nan': return None
    if isinstance(valor, datetime): return valor.strftime('%Y-%m-%d')
    s = str(valor).strip().replace("'", "")
    formatos = ['%d/%m/%Y', '%Y-%m-%d', '%d-%m-%y', '%m/%d/%Y']
    for fmt in formatos:
        try:
            return datetime.strptime(s, fmt).strftime('%Y-%m-%d')
        except: continue
    return None

def sanitizar_nombre_columna(nombre, bank_type=None):
    if not nombre: return "SinNombre"
    orig = str(nombre).strip()
    if bank_type and orig in CORE_MAP.get(bank_type, {}):
        return CORE_MAP[bank_type][orig]
    s = ''.join(c for c in unicodedata.normalize('NFD', orig) if unicodedata.category(c) != 'Mn')
    s = re.sub(r'[^a-zA-Z0-9_]', '_', s)
    return s.strip('_')

def subir_a_db(file_path, tipo_banco):
    print(f"   >>> Procesando {file_path} ({tipo_banco})...")
    if not os.path.exists(file_path): return
    try:
        conn = obtener_conexion(); cursor = conn.cursor()
        df = pd.read_csv(file_path, encoding='utf-8-sig')
        if df.empty: conn.close(); return
        
        mapped_cols = {}
        for c in df.columns:
            name = sanitizar_nombre_columna(c, tipo_banco)
            if name in COLUMNAS_OFICIALES: mapped_cols[c] = name
        
        df = df[list(mapped_cols.keys())].rename(columns=mapped_cols)
        for col in COLUMNAS_OFICIALES:
            if col not in df.columns: df[col] = None

        df['Fecha_Transaccion'] = df['Fecha_Transaccion'].apply(limpiar_fecha)
        df['Monto'] = df['Monto'].apply(limpiar_moneda)
        df['Monto'] = pd.to_numeric(df['Monto'], errors='coerce').fillna(0.0)
        
        tabla = 'banco_getnet'
        cursor.execute(f"SELECT Afiliacion, ID_Externo, Fecha_Transaccion, Monto, Hora, Codigo_Autorizacion, Referencia, Terminal FROM {tabla}")
        huellas = set(f"{str(r[0]).strip()}|{str(r[1]).strip()}|{str(r[2])[:10]}|{float(r[3]):.2f}|{str(r[4]).strip()}|{str(r[5]).strip()}|{str(r[6]).strip()}|{str(r[7]).strip()}" for r in cursor.fetchall())
        
        records = df.to_dict('records')
        nuevos_rows = []
        for row in records:
            v_afil = str(row.get('Afiliacion') or '').strip()
            v_idex = str(row.get('ID_Externo') or '').strip()
            v_fecha = str(row.get('Fecha_Transaccion') or '')
            v_monto = float(row.get('Monto') or 0)
            v_hora = str(row.get('Hora') or '').strip()
            v_auth = str(row.get('Codigo_Autorizacion') or '').strip()
            v_ref = str(row.get('Referencia') or '').strip()
            v_term = str(row.get('Terminal') or '').strip()

            huella = f"{v_afil}|{v_idex}|{v_fecha}|{v_monto:.2f}|{v_hora}|{v_auth}|{v_ref}|{v_term}"
            if huella in huellas: continue
            
            final_vals = [ (None if pd.isna(row.get(col)) or row.get(col) == '' else row.get(col)) for col in COLUMNAS_OFICIALES ]
            nuevos_rows.append(tuple(final_vals))
            
        if nuevos_rows:
            placeholders = ", ".join(["?" for _ in COLUMNAS_OFICIALES])
            cols_sql = ", ".join(COLUMNAS_OFICIALES)
            sql = f"INSERT INTO {tabla} ({cols_sql}) VALUES ({placeholders})"
            for i in range(0, len(nuevos_rows), 1000):
                cursor.executemany(sql, nuevos_rows[i:i+1000])
            conn.commit(); print(f"   ✅ {len(nuevos_rows)} insertados.")
        
        conn.close()
        ruta_final = os.path.join(os.getcwd(), "uploads", tipo_banco, datetime.now().strftime('%Y'), "RECUPERACION_HISTORICA")
        if not os.path.exists(ruta_final): os.makedirs(ruta_final)
        shutil.move(file_path, os.path.join(ruta_final, f"{datetime.now().strftime('%H%M%S')}_{os.path.basename(file_path)}"))
        
    except Exception as e: print(f"   ❌ Error: {e}"); traceback.print_exc()

def ejecutar_getnet_historico(browser):
    print("\n" + "="*50 + "\n>>> INICIANDO RECUPERACIÓN HISTÓRICA GETNET (ÚLTIMO MES)\n" + "="*50)
    CREDENCIALES = [
        {"razon": "DIAZ GAS", "usuario": "Alfredo_escalera", "pass": "DIAZ1147"},
        {"razon": "GASOMEX", "usuario": "israel.ibarra", "pass": "TotalG2023"},
        {"razon": "JARUDO", "usuario": "israel.ibarrat", "pass": "IsraelTG17"},
        {"razon": "CLARA", "usuario": "israel.ibarra1", "pass": "IsraelTG017"},
        {"razon": "ESTACION CUSTODIA", "usuario": "tesoreria19", "pass": "Tesoreria19"},
        {"razon": "SYC DELICIAS", "usuario": "susana.pantoja2", "pass": "Susana1511"},
        {"razon": "VILLA AHUMADA", "usuario": "facturavilla", "pass": "VILLA1242"},
        {"razon": "SMA VENTANAS", "usuario": "sve200529db9", "pass": "Hrmm7k01"},
        {"razon": "SMA PICACHOS", "usuario": "spi200529sc7", "pass": "Hrmm7k01"},
        {"razon": "EL CASTAÑO", "usuario": "martin.puentes", "pass": "Castaño12900"},
        {"razon": "TSA", "usuario": "goperadortsadelcent", "pass": "Tsa2024!"},
        {"razon": "HECTOR ARMANDINO", "usuario": "fihh7303026k7", "pass": "Praxedis25"},
    ]
    for cred in CREDENCIALES:
        estacion = cred["razon"]
        print(f"\nProcesando {estacion}...")
        context = browser.new_context(locale='es-MX', viewport={'width': 1366, 'height': 768})
        page = context.new_page()
        try:
            page.goto("https://www.globalgetnet.com/", timeout=60000)
            page.fill('input[name="callback_3"]', cred["usuario"])
            page.fill('input[name="callback_4"]', cred["pass"])
            page.click('button[type="submit"]')
            time.sleep(5)
            page.click('div[data-testid="MenuReportes"]')
            page.wait_for_selector('a[href*="/statement/transactions"]', state="visible")
            page.click('a[href*="/statement/transactions"]')
            time.sleep(5)
            
            # CAMBIO CRÍTICO: Selección de periodo "Último mes"
            page.click("text=Seleccionar período")
            time.sleep(1)
            page.click("text=Último mes") # Selección del mes anterior completo
            time.sleep(5)
            
            if page.is_visible("text=Descargar archivos"): page.click("text=Descargar archivos")
            else: page.click("button:has-text('Descargar')")
            
            # Sin límite de tiempo para descargas pesadas
            with page.expect_download(timeout=0) as download_info:
                page.click("text=.csv")
            
            download = download_info.value
            nombre_archivo = f"Historico_Getnet_{estacion.replace(' ', '_')}.csv"
            download.save_as(nombre_archivo)
            subir_a_db(nombre_archivo, 'GETNET')
            
            page.click('div[data-testid="iconProfile"]')
            page.click("text=Salir")
            time.sleep(2)
        except Exception as e: print(f"❌ Error {estacion}: {e}")
        finally: context.close()

if __name__ == "__main__":
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=False)
        ejecutar_getnet_historico(browser)
        browser.close()
