import time
import os
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

# Mapeo de nombres de archivo a nombres de BD existentes (para no romper el PHP)
CORE_MAP = {
    'BANORTE': {
        'Afiliación': 'Afiliacion',
        'Nombre de Afiliación': 'Nombre_Afiliacion',
        'Moneda': 'Moneda',
        'Estatus de Transacción': 'Estatus',
        'Tipo transaccion': 'Tipo_Transaccion',
        'Tipo de Transacción': 'Tipo_Transaccion',
        'Número de Control': 'Numero_Control',
        'Número de Tarjeta': 'Tarjeta',
        'Tipo de Tarjeta': 'Tipo_Tarjeta',
        'Monto de Transacción Signo': 'Monto',
        'Fecha Transacción': 'Fecha_Transaccion',
        'Código Autorización': 'Codigo_Autorizacion',
        'Referencia': 'Referencia',
        'Terminal ID': 'Terminal_ID',
        'Lote de Transacción': 'Lote',
        'Hora de Transacción': 'Hora'
    },
    'GETNET': {
        'ID movimiento': 'ID_Movimiento',
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
        'Código Autorización': 'Cod_Autorizacion',
        'Cod. Aut': 'Cod_Autorizacion',
        'Total': 'Total',
        'Monto de Transacción Signo': 'Total',
        'Comisión': 'Comision'
    }
}

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
    if formato_origen:
        try:
            return datetime.strptime(s, formato_origen).strftime('%Y-%m-%d')
        except: pass
    
    formatos = ['%d/%m/%Y', '%Y-%m-%d', '%d-%m-%y', '%m/%d/%Y']
    for fmt in formatos:
        try:
            return datetime.strptime(s, fmt).strftime('%Y-%m-%d')
        except: continue
    return None

def sanitizar_nombre_columna(nombre, bank_type=None):
    if not nombre: return "SinNombre"
    orig = str(nombre).strip()
    orig = orig.replace('\ufeff', '').replace('\xef\xbb\xbf', '')
    if bank_type and orig in CORE_MAP.get(bank_type, {}):
        return CORE_MAP[bank_type][orig]
    s = ''.join(c for c in unicodedata.normalize('NFD', orig) if unicodedata.category(c) != 'Mn')
    s = re.sub(r'[^a-zA-Z0-9_]', '_', s)
    s = re.sub(r'_+', '_', s).strip('_')
    return s

def asegurar_columnas(cursor, tabla, df_columns):
    cursor.execute(f"SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = '{tabla}'")
    actuales = set(r[0].lower() for r in cursor.fetchall())
    for col in df_columns:
        if col.lower() not in actuales:
            print(f"      + Agregando columna [{col}] a {tabla}...")
            cursor.execute(f"ALTER TABLE {tabla} ADD [{col}] VARCHAR(MAX)")

def crear_tablas_si_no_existen():
    conn = obtener_conexion()
    cursor = conn.cursor()
    cursor.execute("""
        IF NOT EXISTS (SELECT * FROM sysobjects WHERE name='banco_banorte' and xtype='U')
        CREATE TABLE banco_banorte (
            ID INT IDENTITY(1,1) PRIMARY KEY, Afiliacion VARCHAR(50), Nombre_Afiliacion VARCHAR(255), Moneda VARCHAR(10),
            Estatus VARCHAR(50), Tipo_Transaccion VARCHAR(100), Numero_Control VARCHAR(50), Tarjeta VARCHAR(50),
            Tipo_Tarjeta VARCHAR(50), Monto DECIMAL(18,2), Fecha_Transaccion DATE, Codigo_Autorizacion VARCHAR(50),
            Referencia VARCHAR(255), Terminal_ID VARCHAR(50), Lote VARCHAR(50), Hora VARCHAR(20), Fecha_Carga DATETIME DEFAULT GETDATE()
        )
    """)
    cursor.execute("""
        IF NOT EXISTS (SELECT * FROM sysobjects WHERE name='banco_getnet' and xtype='U')
        CREATE TABLE banco_getnet (
            ID INT IDENTITY(1,1) PRIMARY KEY, ID_Movimiento VARCHAR(100), Fecha_Transaccion DATE, Hora VARCHAR(20),
            Afiliacion VARCHAR(50), Comercio VARCHAR(255), Tipo_Transaccion VARCHAR(100), Tarjeta VARCHAR(50),
            Terminal VARCHAR(50), Operacion VARCHAR(100), Tarjeta_Numero VARCHAR(50), Cod_Autorizacion VARCHAR(50),
            Total DECIMAL(18,2), Comision VARCHAR(50), Referencia VARCHAR(255), Fecha_Carga DATETIME DEFAULT GETDATE()
        )
    """)
    conn.commit()
    conn.close()

def subir_a_db(file_path, tipo_banco):
    print(f"   >>> Procesando {file_path} ({tipo_banco})...")
    if not os.path.exists(file_path): return
    try:
        crear_tablas_si_no_existen()
        conn = obtener_conexion(); cursor = conn.cursor()
        df = pd.read_excel(file_path) if tipo_banco == 'BANORTE' else pd.read_csv(file_path, encoding='utf-8-sig')
        if df.empty: conn.close(); return
        original_cols = df.columns.tolist(); clean_cols = []; seen = {}
        for c in original_cols:
            name = sanitizar_nombre_columna(c, tipo_banco)
            if name in seen: seen[name] += 1; name = f"{name}_{seen[name]}"
            else: seen[name] = 0
            clean_cols.append(name)
        df.columns = clean_cols
        asegurar_columnas(cursor, 'banco_banorte' if tipo_banco == 'BANORTE' else 'banco_getnet', clean_cols)
        if tipo_banco == 'BANORTE':
            cursor.execute("SELECT Afiliacion, Numero_Control, Fecha_Transaccion, Monto FROM banco_banorte")
            huellas = set(f"{str(r[0]).strip()}|{str(r[1]).strip()}|{str(r[2])[:10]}|{float(r[3]):.2f}" for r in cursor.fetchall())
        else:
            cursor.execute("SELECT ID_Movimiento, Cod_Autorizacion, Total FROM banco_getnet")
            huellas = set(f"{str(r[0]).strip()}|{str(r[1]).strip()}|{float(r[2]):.2f}" for r in cursor.fetchall())
        nuevos_rows = []
        idx_fecha = [i for i, c in enumerate(clean_cols) if 'Fecha_Transaccion' in c]
        idx_monto = [i for i, c in enumerate(clean_cols) if c == 'Monto']
        idx_total = [i for i, c in enumerate(clean_cols) if c == 'Total']
        idx_idm, idx_auth, idx_afil, idx_nctrl = [i for i,c in enumerate(clean_cols) if c=='ID_Movimiento'], [i for i,c in enumerate(clean_cols) if c=='Cod_Autorizacion'], [i for i,c in enumerate(clean_cols) if c=='Afiliacion'], [i for i,c in enumerate(clean_cols) if c=='Numero_Control']
        for _, row in df.iterrows():
            if tipo_banco == 'BANORTE':
                huella = f"{str(row.iloc[idx_afil[0]]).strip()}|{str(row.iloc[idx_nctrl[0]]).strip()}|{limpiar_fecha(row.iloc[idx_fecha[0]])}|{limpiar_moneda(row.iloc[idx_monto[0]]):.2f}"
            else:
                huella = f"{str(row.iloc[idx_idm[0]]).strip()}|{str(row.iloc[idx_auth[0]]).strip()}|{limpiar_moneda(row.iloc[idx_total[0]]):.2f}"
            if huella in huellas: continue
            vals = [ (None if pd.isna(v) else (limpiar_fecha(v) if i in idx_fecha else (limpiar_moneda(v) if i in (idx_monto+idx_total) else str(v)))) for i, v in enumerate(row.values) ]
            nuevos_rows.append(tuple(vals))
        if nuevos_rows:
            sql = f"INSERT INTO {'banco_banorte' if tipo_banco == 'BANORTE' else 'banco_getnet'} ({', '.join([f'[{c}]' for c in clean_cols])}) VALUES ({', '.join(['?' for _ in clean_cols])})"
            for i in range(0, len(nuevos_rows), 1000): cursor.executemany(sql, nuevos_rows[i:i+1000])
            conn.commit(); print(f"   ✅ {len(nuevos_rows)} insertados.")
        conn.close(); os.remove(file_path)
    except Exception as e: print(f"   ❌ Error: {e}"); traceback.print_exc()

def ejecutar_banorte(browser):
    print("\n" + "="*50 + "\n>>> 1. INICIANDO PROCESO BANORTE\n" + "="*50)
    USUARIO, PASSWORD = "israel.ibarra@totalgas.com", "TotGAS26@$"
    fecha_hoy = datetime.now()
    fecha_inicio = fecha_fin = (fecha_hoy - timedelta(days=1)).strftime("%d/%m/%Y")
    context = browser.new_context(viewport={'width': 1366, 'height': 768}, permissions=['geolocation'], geolocation={'latitude': 19.4326, 'longitude': -99.1332}, locale='es-MX')
    page = context.new_page()
    try:
        print("   Login..."); page.goto("https://multicobros.banorte.com/internet/CIBV2/homepublico")
        page.wait_for_selector('input[formcontrolname="username"]', state="visible"); page.fill('input[formcontrolname="username"]', USUARIO); page.fill('input[formcontrolname="segu"]', PASSWORD); page.click('.login_box_btn_entrar')
        time.sleep(3); print("   Abriendo menú Analítica..."); time.sleep(3)
        if page.is_visible("text=Analítica"): page.click("text=Analítica", force=True)
        else: page.click("a.nav-link:has-text('Analítica')", force=True)
        time.sleep(1.5); selector_reportes = ".dropdown-menu-custom-opcion:has-text('Reportes')"
        try: page.wait_for_selector(selector_reportes, timeout=1000); page.click(selector_reportes, force=True)
        except: page.click("text=Analítica", force=True); time.sleep(1); page.click(selector_reportes, force=True)
        print("   Eligiendo Depósitos..."); page.wait_for_selector("button.icon_r-reporteDepositos"); page.click("button.icon_r-reporteDepositos", force=True)
        print("   Llenando fechas..."); page.wait_for_selector('input[data-provide="datepicker"]')
        i_ini = page.locator('input[data-provide="datepicker"]').nth(0); i_ini.click(); page.keyboard.press("Control+a"); page.keyboard.press("Backspace"); i_ini.type(fecha_inicio, delay=50); page.keyboard.press("Escape"); time.sleep(0.5)
        i_fin = page.locator('input[data-provide="datepicker"]').nth(1); i_fin.click(); page.keyboard.press("Control+a"); page.keyboard.press("Backspace"); i_fin.type(fecha_fin, delay=50); page.keyboard.press("Escape")
        print("   Buscando..."); page.click("button.btn-buscarn", force=True)
        print("   Esperando tabla..."); 
        try: page.wait_for_selector("table", timeout=30000)
        except: pass
        print("   Descargando..."); selector_excel = ".icon-excel-2"
        if page.is_visible(selector_excel):
            with page.expect_download(timeout=60000) as download_info: page.evaluate("document.querySelector('.icon-excel-2').click()")
            download = download_info.value; nombre_archivo = f"Banorte_Final_{fecha_inicio.replace('/','-')}.xlsx"; download.save_as(nombre_archivo)
            print(f"✅ Descarga Banorte: {nombre_archivo}"); subir_a_db(nombre_archivo, 'BANORTE')
        else: print("❌ No se encontró el botón de Excel.")
        print("   Limpiando popups..."); time.sleep(2); page.mouse.click(100, 100); page.keyboard.press("Escape"); time.sleep(0.5); page.keyboard.press("Escape"); time.sleep(1)
        print("   Cerrando sesión...")
        try: page.locator("button.btn-close-session-xs").dispatch_event("click")
        except: page.click("button.btn-close-session-xs", force=True)
        time.sleep(3)
    except Exception as e: print(f"❌ Error Banorte: {e}")
    finally: context.close()

def ejecutar_getnet(browser):
    print("\n" + "="*50 + "\n>>> 2. INICIANDO PROCESO GETNET (SANTANDER)\n" + "="*50)
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
    fecha_ayer = (datetime.now() - timedelta(days=1)).strftime("%Y-%m-%d")
    for cred in CREDENCIALES:
        estacion = cred["razon"]; usuario = cred["usuario"]; password = cred["pass"]
        print(f"\nGetnet: {estacion}..."); context = browser.new_context(locale='es-MX', viewport={'width': 1366, 'height': 768})
        page = context.new_page()
        try:
            print("   Login..."); page.goto("https://www.globalgetnet.com/", timeout=60000)
            page.wait_for_selector('input[name="callback_3"]', state="visible"); page.fill('input[name="callback_3"]', usuario); page.fill('input[name="callback_4"]', password); page.click('button[type="submit"]')
            time.sleep(4); print("   Yendo a Transacciones..."); page.click('div[data-testid="MenuReportes"]')
            page.wait_for_selector('a[href*="/statement/transactions"]', state="visible"); page.click('a[href*="/statement/transactions"]'); time.sleep(4)
            print("   Seleccionando 'Ayer'..."); page.click("text=Seleccionar período"); page.click("text=Ayer"); time.sleep(4)
            print("   Descargando..."); 
            if page.is_visible("text=Descargar archivos"):
                page.click("text=Descargar archivos")
            else: page.click("button:has-text('Descargar')")
            with page.expect_download(timeout=60000) as download_info: page.click("text=.csv")
            download = download_info.value; nombre_archivo = f"Getnet_{estacion.replace(' ', '_')}_{fecha_ayer}.csv"; download.save_as(nombre_archivo)
            print(f"✅ Guardado: {nombre_archivo}"); subir_a_db(nombre_archivo, 'GETNET')
            print("   Cerrando sesión..."); page.click('div[data-testid="iconProfile"]'); page.wait_for_selector("text=Salir", timeout=5000); page.click("text=Salir"); time.sleep(2)
        except Exception as e: print(f"❌ Error {estacion}: {e}")
        finally: context.close()

if __name__ == "__main__":
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=False, args=["--disable-features=Translate"])
        ejecutar_banorte(browser); ejecutar_getnet(browser); browser.close()
