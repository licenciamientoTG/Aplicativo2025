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

# Mapeo de nombres de archivo a nombres de BD existentes (para no romper el PHP)
# Mapeo de nombres de archivo a nombres de BD ESTANDARIZADOS
CORE_MAP = {
    'BANORTE': {
        'Afiliación': 'Afiliacion',
        'Afiliacion': 'Afiliacion',
        'Nombre de Afiliación': 'Nombre_Afiliacion',
        'Nombre de Afiliacion': 'Nombre_Afiliacion',
        'Moneda': 'Moneda',
        'Estatus de Transacción': 'Estatus',
        'Estatus de Transaccion': 'Estatus',
        'Tipo transaccion': 'Tipo_Transaccion',
        'Tipo de Transacción': 'Tipo_Transaccion',
        'Tipo de Transaccion': 'Tipo_Transaccion',
        'Número de Control': 'ID_Externo',          # ESTANDARIZADO
        'Numero de Control': 'ID_Externo',
        'Número de Tarjeta': 'Tarjeta',
        'Numero de Tarjeta': 'Tarjeta',
        'Tipo de Tarjeta': 'Tipo_Tarjeta',
        'Monto de Transacción Signo': 'Monto',
        'Monto de Transaccion Signo': 'Monto',
        'Fecha Transacción': 'Fecha_Transaccion',
        'Fecha Transaccion': 'Fecha_Transaccion',
        'Fecha TransacciÃ³n': 'Fecha_Transaccion',
        'Código Autorización': 'Codigo_Autorizacion',
        'Codigo Autorizacion': 'Codigo_Autorizacion',
        'Referencia': 'Referencia_Pago',            # Referencia normal (menos fuerte)
        'Terminal ID': 'Terminal',                  # ESTANDARIZADO
        'Terminal': 'Terminal',
        'Lote de Transacción': 'Lote',
        'Lote': 'Lote',
        'Hora de Transacción': 'Hora',
        'Hora Transacción': 'Hora',
        'Hora': 'Hora',
        'Referencia Interbancaria': 'Referencia'    # ESTANDARIZADO
    },
    'GETNET': {
        'ID movimiento': 'ID_Externo',              # ESTANDARIZADO
        'Fecha Transacción': 'Fecha_Transaccion',
        'Hora de Transacción': 'Hora',
        'Hora Transacción': 'Hora',
        'Afiliación': 'Afiliacion',
        'Nombre del comercio': 'Comercio',
        'Tipo de Transacción': 'Tipo_Transaccion',
        'Tipo Transacción': 'Tipo_Transaccion',
        'Tarjeta': 'Tarjeta',
        'Cod. Terminal': 'Terminal',                # ESTANDARIZADO
        'Terminal ID': 'Terminal',
        'Operación': 'Operacion',
        'Tipo de Tarjeta': 'Tipo_Tarjeta',
        'Tipo Tarjeta': 'Tipo_Tarjeta',
        'Número de Tarjeta': 'Tarjeta_Numero',
        'Tarjeta Número': 'Tarjeta_Numero',
        'Código Autorización': 'Codigo_Autorizacion',
        'Cod. Aut': 'Codigo_Autorizacion',
        'Total': 'Monto',                           # ESTANDARIZADO
        'Monto de Transacción Signo': 'Monto',
        'Comisión': 'Comision',
        'Referencia': 'Referencia'                  # ESTANDARIZADO
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
    # Las tablas ya fueron creadas con el esquema estandarizado desde PHP/PowerShell
    pass

def subir_a_db(file_path, tipo_banco):
    print(f"   >>> Procesando {file_path} ({tipo_banco})...")
    if not os.path.exists(file_path): return
    
    # LISTA BLANCA DE COLUMNAS ESTANDARIZADAS
    COLUMNAS_OFICIALES = [
        'ID_Externo', 'Afiliacion', 'Fecha_Transaccion', 'Hora', 
        'Monto', 'Codigo_Autorizacion', 'Terminal', 'Referencia', 'Fecha_Deposito', 'Nombre_Archivo'
    ]

    try:
        conn = obtener_conexion()
        cursor = conn.cursor()
        df = pd.read_excel(file_path) if tipo_banco == 'BANORTE' else pd.read_csv(file_path, encoding='utf-8-sig')
        if df.empty:
            conn.close()
            return
        
        # 1. Mapear columnas según CORE_MAP
        mapped_cols = {}
        for c in df.columns:
            name = sanitizar_nombre_columna(c, tipo_banco)
            if name in COLUMNAS_OFICIALES:
                mapped_cols[c] = name
        
        # 2. Filtrar el DataFrame solo con las columnas mapeadas y renombrarlas
        df = df[list(mapped_cols.keys())].rename(columns=mapped_cols)
        
        # DEFINIR RUTA FINAL ANTES DE SUBIR PARA GUARDAR EL NOMBRE
        ruta_base_abs = r"C:\inetpub\wwwroot\TG_PHP\_assets\uploads"
        sub_ruta = os.path.join(tipo_banco, datetime.now().strftime('%Y'), datetime.now().strftime('%m'))
        ruta_final = os.path.join(ruta_base_abs, sub_ruta)
        
        if not os.path.exists(ruta_final): os.makedirs(ruta_final)
        
        # Nombre único del archivo
        nombre_solo = f"{datetime.now().strftime('%H%M%S')}_{os.path.basename(file_path)}"
        nombre_relativo = os.path.join(sub_ruta, nombre_solo).replace("\\", "/") # Ruta para la DB
        
        # 3. Asegurar que todas las columnas oficiales existan
        for col in COLUMNAS_OFICIALES:
            if col not in df.columns:
                df[col] = None
        
        # ASIGNAR EL NOMBRE DEL ARCHIVO A TODAS LAS FILAS
        df['Nombre_Archivo'] = nombre_relativo

        # 4. Limpieza de datos
        df['Fecha_Transaccion'] = df['Fecha_Transaccion'].apply(lambda x: limpiar_fecha(x))
        if 'Fecha_Deposito' in df.columns:
            df['Fecha_Deposito'] = df['Fecha_Deposito'].apply(lambda x: limpiar_fecha(x))
        
        # Asegurar que Monto sea numérico
        df['Monto'] = df['Monto'].apply(lambda x: limpiar_moneda(x))
        df['Monto'] = pd.to_numeric(df['Monto'], errors='coerce').fillna(0.0)
        
        tabla = 'banco_banorte' if tipo_banco == 'BANORTE' else 'banco_getnet'
        
        # 5. Huellas Digitales
        cursor.execute(f"SELECT Afiliacion, ID_Externo, Fecha_Transaccion, Monto, Hora, Codigo_Autorizacion, Referencia, Terminal FROM {tabla}")
        huellas = set(f"{str(r[0]).strip()}|{str(r[1]).strip()}|{str(r[2])[:10]}|{float(r[3]):.2f}|{str(r[4]).strip()}|{str(r[5]).strip()}|{str(r[6]).strip()}|{str(r[7]).strip()}" for r in cursor.fetchall())
        
        records = df.to_dict('records')
        nuevos_rows = []
        for row in records:
            val_afil = str(row.get('Afiliacion') or '').strip()
            val_idex = str(row.get('ID_Externo') or '').strip()
            val_fecha = str(row.get('Fecha_Transaccion') or '')
            val_monto = float(row.get('Monto') or 0)
            val_hora = str(row.get('Hora') or '').strip()
            val_auth = str(row.get('Codigo_Autorizacion') or '').strip()
            val_ref = str(row.get('Referencia') or '').strip()
            val_term = str(row.get('Terminal') or '').strip()

            huella = f"{val_afil}|{val_idex}|{val_fecha}|{val_monto:.2f}|{val_hora}|{val_auth}|{val_ref}|{val_term}"
            if huella in huellas: continue
            
            final_vals = []
            for col in COLUMNAS_OFICIALES:
                val = row.get(col)
                final_vals.append(None if (pd.isna(val) or val == '') else val)
            nuevos_rows.append(tuple(final_vals))
            
        if nuevos_rows:
            placeholders = ", ".join(["?" for _ in COLUMNAS_OFICIALES])
            cols_sql = ", ".join(COLUMNAS_OFICIALES)
            sql = f"INSERT INTO {tabla} ({cols_sql}) VALUES ({placeholders})"
            for i in range(0, len(nuevos_rows), 1000):
                cursor.executemany(sql, nuevos_rows[i:i+1000])
            conn.commit(); print(f"   ✅ {len(nuevos_rows)} insertados en {tabla}.")
        
        conn.close()
        
        # Copiar y borrar en lugar de mover para asegurar herencia de permisos NTFS
        destino_final = os.path.join(ruta_final, nombre_solo)
        shutil.copy(file_path, destino_final)
        os.remove(file_path)
        print(f"   📂 Archivado en: {destino_final}")
        
    except Exception as e:
        print(f"   ❌ Error procesando {tipo_banco}: {e}"); traceback.print_exc()

def get_last_banorte_date():
    try:
        conn = obtener_conexion()
        cursor = conn.cursor()
        # Filtro de seguridad: Ignorar fechas futuras que puedan estar corrompiendo el MAX
        cursor.execute("SELECT MAX(Fecha_Transaccion) FROM banco_banorte WHERE Fecha_Transaccion <= GETDATE()")
        res = cursor.fetchone()[0]
        conn.close()
        
        if res is None: 
            print("   ℹ️ No se encontraron registros. Iniciando desde hace 30 días.")
            return datetime.now() - timedelta(days=30)
        
        # Si ya es un objeto de fecha/tiempo
        if isinstance(res, (datetime, pd.Timestamp)):
            print(f"   ℹ️ Última fecha en DB: {res.strftime('%Y-%m-%d')}")
            return res
            
        # Si es string, limpiar y convertir
        res_str = str(res).split(' ')[0] 
        try:
            val = datetime.strptime(res_str, '%Y-%m-%d')
        except:
            val = datetime.strptime(res_str, '%d/%m/%Y')
            
        print(f"   ℹ️ Última fecha en DB: {val.strftime('%Y-%m-%d')}")
        return val
    except Exception as e:
        print(f"   ❌ Error consultando última fecha: {e}")
        return datetime.now() - timedelta(days=30)

def ejecutar_banorte(browser):
    print("\n" + "="*50 + "\n>>> 1. INICIANDO PROCESO BANORTE (MODO CONTINUIDAD)\n" + "="*50)
    USUARIO, PASSWORD = "israel.ibarra@totalgas.com", "TotGAS26@$"
    
    fecha_inicio_dt = get_last_banorte_date() + timedelta(days=1)
    fecha_hoy_dt = datetime.now()
    
    if fecha_inicio_dt > fecha_hoy_dt:
        print(f"   ✅ Banorte está al día. Última fecha: {fecha_inicio_dt - timedelta(days=1)}")
        return

    # RESTAURACIÓN: Configuración original de contexto (Geolocalización y Permisos)
    context = browser.new_context(
        user_agent="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36",
        viewport={'width': 1366, 'height': 768}, 
        permissions=['geolocation'], 
        geolocation={'latitude': 19.4326, 'longitude': -99.1332}, 
        locale='es-MX'
    )
    
    # EVASION: Ocultar propiedad webdriver para evitar detección
    context.add_init_script("Object.defineProperty(navigator, 'webdriver', {get: () => undefined})")

    page = context.new_page()
    
    try:
        print("   Login..."); page.goto("https://multicobros.banorte.com/internet/CIBV2/homepublico")
        page.wait_for_selector('input[formcontrolname="username"]', state="visible")
        page.fill('input[formcontrolname="username"]', USUARIO)
        page.fill('input[formcontrolname="segu"]', PASSWORD)
        page.click('.login_box_btn_entrar')
        time.sleep(5) # Espera un poco más para que cargue el dashboard o el error

        # NUEVO: Detección de Sesión Abierta
        if page.is_visible("text=abierta") or page.is_visible("text=Abierta") or page.is_visible("text=ABIERTA"):
            print("   ⚠️ ALERTA: Se detectó una sesión ya abierta en otro dispositivo. Abortando proceso Banorte.")
            return

        print("   Abriendo menú Analítica...")
        time.sleep(3)
        if page.is_visible("text=Analítica"): page.click("text=Analítica", force=True)
        else: page.click("a.nav-link:has-text('Analítica')", force=True)
        time.sleep(1.5)
        
        selector_reportes = ".dropdown-menu-custom-opcion:has-text('Reportes')"
        try: page.wait_for_selector(selector_reportes, timeout=1000); page.click(selector_reportes, force=True)
        except: page.click("text=Analítica", force=True); time.sleep(1); page.click(selector_reportes, force=True)
        
        print("   Eligiendo Depósitos...")
        page.wait_for_selector("button.icon_r-reporteDepositos"); page.click("button.icon_r-reporteDepositos", force=True)
        
        # BUCLE DÍA POR DÍA
        fecha_actual = fecha_inicio_dt
        while fecha_actual <= fecha_hoy_dt:
            fecha_str = fecha_actual.strftime("%d/%m/%Y")
            print(f"\n   >>> Consultando día: {fecha_str}")
            
            page.wait_for_selector('input[data-provide="datepicker"]')
            # Llenar fecha inicio
            i_ini = page.locator('input[data-provide="datepicker"]').nth(0)
            i_ini.click(); page.keyboard.press("Control+a"); page.keyboard.press("Backspace")
            time.sleep(0.3); i_ini.type(fecha_str, delay=100); page.keyboard.press("Enter"); page.keyboard.press("Escape")
            time.sleep(0.5)
            # Llenar fecha fin (misma fecha)
            i_fin = page.locator('input[data-provide="datepicker"]').nth(1)
            i_fin.click(); page.keyboard.press("Control+a"); page.keyboard.press("Backspace")
            time.sleep(0.3); i_fin.type(fecha_str, delay=100); page.keyboard.press("Enter"); page.keyboard.press("Escape")
            
            # NUEVO: Clic en espacio en blanco para asentar los inputs (fuerza el evento change/blur)
            page.mouse.click(10, 10)
            time.sleep(1.0) 
            
            page.click("button.btn-buscarn", force=True)
            time.sleep(5) # Espera mayor para carga de resultados o detección de mensaje
            
            if page.is_visible("text=No existe información de acuerdo con los criterios seleccionados"):
                print(f"   ⚠️ El banco aún no tiene datos para el {fecha_str}. Terminando proceso.")
                break
            
            print("   Esperando tabla..."); 
            try: page.wait_for_selector("table", timeout=15000)
            except: pass

            print("   Descargando Excel...")
            selector_excel = ".icon-excel-2"
            if page.is_visible(selector_excel):
                with page.expect_download(timeout=60000) as download_info:
                    page.evaluate("document.querySelector('.icon-excel-2').click()")
                
                download = download_info.value
                nombre_archivo = f"Banorte_{fecha_str.replace('/','-')}.xlsx"
                download.save_as(nombre_archivo)
                
                print(f"   ✅ Descarga Banorte: {nombre_archivo}")
                subir_a_db(nombre_archivo, 'BANORTE')
                
                # RESTAURACIÓN: Limpiar popups después de cada descarga
                print("   Limpiando popups..."); time.sleep(2); page.mouse.click(100, 100); page.keyboard.press("Escape"); time.sleep(0.5); page.keyboard.press("Escape"); time.sleep(1)
                
                fecha_actual += timedelta(days=1)
            else:
                print("   ❌ No se encontró el botón de Excel para este día.")
                break

        print("\n   Cerrando sesión...")
        try: page.locator("button.btn-close-session-xs").dispatch_event("click")
        except: page.click("button.btn-close-session-xs", force=True)
        time.sleep(3)

    except Exception as e:
        print(f"   ❌ Error Banorte: {e}")
    finally:
        context.close()

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

        print(f"\nGetnet: {estacion}..."); 

        context = browser.new_context(
            user_agent="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36",
            locale='es-MX', 
            viewport={'width': 1366, 'height': 768},
            extra_http_headers={
                "sec-ch-ua": '"Not A(Brand";v="99", "Google Chrome";v="121", "Chromium";v="121"',
                "sec-ch-ua-mobile": "?0",
                "sec-ch-ua-platform": '"Windows"',
            }
        )
        
        # STEALTH AVANZADO: Simular un navegador real completo
        context.add_init_script("""
            # Eliminar rastro de automatización
            Object.defineProperty(navigator, 'webdriver', {get: () => undefined});
            
            # Simular objeto chrome
            window.chrome = { runtime: {}, loadTimes: function() {}, csi: function() {}, app: {} };
            
            # Simular plugins, lenguajes, hardware y plataforma
            Object.defineProperty(navigator, 'languages', {get: () => ['es-MX', 'es', 'en-US', 'en']});
            Object.defineProperty(navigator, 'plugins', {get: () => [1, 2, 3, 4, 5]});
            Object.defineProperty(navigator, 'hardwareConcurrency', {get: () => 8});
            Object.defineProperty(navigator, 'deviceMemory', {get: () => 8});
            Object.defineProperty(navigator, 'platform', {get: () => 'Win32'});

            # Mock de WebGL para evitar detección de renderizado por software
            const getParameter = WebGLRenderingContext.prototype.getParameter;
            WebGLRenderingContext.prototype.getParameter = function(parameter) {
                if (parameter === 37445) return 'Intel Inc.'; 
                if (parameter === 37446) return 'Intel(R) Iris(TM) Graphics 6100';
                return getParameter(parameter);
            };
        """)

        # DEBUG: Iniciar traza (Trace Viewer)
        context.tracing.start(screenshots=True, snapshots=True, sources=True)

        page = context.new_page()
        
        # HUMANO: Mover el mouse a una posición aleatoria inicial
        page.mouse.move(100, 100)
        time.sleep(0.5)

        # CDP: Forzar el User-Agent a nivel de protocolo (más profundo que el contexto)
        cdp = page.context.new_cdp_session(page)
        cdp.send("Network.setUserAgentOverride", {
            "userAgent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36",
            "platform": "Win32",
            "acceptLanguage": "es-MX,es,en",
            "userAgentMetadata": {
                "brands": [{"brand": "Not A(Brand", "version": "99"}, {"brand": "Google Chrome", "version": "121"}, {"brand": "Chromium", "version": "121"}],
                "fullVersion": "121.0.6167.85",
                "platform": "Windows",
                "platformVersion": "10.0.0",
                "architecture": "x86",
                "model": "",
                "mobile": False
            }
        })
        
        try:
            print("   Accediendo..."); 
            page.goto("https://www.globalgetnet.com/", timeout=90000, wait_until="domcontentloaded")
            time.sleep(2)
            page.mouse.wheel(0, 300) # Scroll pequeño para simular humano
            time.sleep(3) 
            
            # --- LÓGICA TIPO BANORTE (Selectores Directos) ---
            print("   Llenando credenciales...");
            
            # Selector inteligente: busca por name (clásico) O por placeholder (moderno)
            # Esto cubre ambas versiones del portal sin fallar
            sel_user = 'input[name="callback_0"], input[name="callback_1"], input[placeholder*="Correo"], input[placeholder*="usuario"]'
            sel_pass = 'input[name="callback_1"], input[name="callback_2"], input[placeholder*="Contraseña"]'
            
            page.wait_for_selector(sel_user, state="visible", timeout=30000)
            
            # Llenar usuario (primer input visible que coincida)
            page.locator(sel_user).first.fill(usuario)
            
            # TRUCO: Tabular para activar el campo de contraseña
            page.keyboard.press("Tab")
            time.sleep(1)
            
            # Escribir contraseña carácter por carácter (más seguro contra bloqueos)
            page.keyboard.type(password, delay=100)
            
            # Click en Entrar (buscar botón primario)
            if page.is_visible('button[type="submit"]'):
                page.click('button[type="submit"]')
            else:
                page.click("text=Iniciar sesión")
            
            # --- NAVEGACIÓN POR TEXTO ---
            print("   Navegando a Reportes...");
            time.sleep(5)
            page.click("text=Reportes", timeout=30000)
            
            time.sleep(2)
            print("   Abriendo Transacciones...");
            page.click("text=Transacciones", timeout=20000)
            
            time.sleep(5)
            print("   Configurando periodo...");
            page.click("text=Seleccionar período", timeout=20000)
            time.sleep(1)
            page.click("text=Ayer", timeout=15000)
            
            time.sleep(3)
            print("   Iniciando Descarga...");
            # Intentar click en Descargar (puede ser botón o texto)
            try:
                page.click("button:has-text('Descargar')", timeout=5000)
            except:
                page.click("text=Descargar", timeout=5000)
            
            # El banco suele mostrar un menú de formato (.csv o .xlsx)
            with page.expect_download(timeout=60000) as download_info:
                page.click("text=.csv")
            
            download = download_info.value
            nombre_archivo = f"Getnet_{estacion.replace(' ', '_')}_{fecha_ayer}.csv"
            download.save_as(nombre_archivo)
            
            print(f"   ✅ Guardado: {nombre_archivo}")
            subir_a_db(nombre_archivo, 'GETNET')
            
            # Cerrar sesión (basado en icono de perfil y Salir)
            print("   Cerrando sesión...");
            try:
                page.click("text=Salir", timeout=5000)
            except:
                # Si no está visible, clic en el círculo de iniciales primero
                page.mouse.click(1330, 30) # Esquina superior derecha aproximada
                time.sleep(1)
                page.click("text=Salir")

        except Exception as e:
            print(f"   ❌ Error {estacion}: {e}")
            # DEBUG: Captura de pantalla en error
            safe_estacion = estacion.replace(" ", "_")
            page.screenshot(path=f"debug_error_{safe_estacion}.png")
        finally:
            # DEBUG: Guardar traza
            safe_estacion = estacion.replace(" ", "_")
            context.tracing.stop(path=f"trace_{safe_estacion}.zip")
            context.close()

if __name__ == "__main__":
    with sync_playwright() as p:
        # User agent consistente para launch y context
        UA = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36"
        
        # Modo headless=True para ejecución en servidor/tarea programada
        browser = p.chromium.launch(
            headless=True, 
            args=[
                f"--user-agent={UA}",
                "--disable-features=Translate", 
                "--disable-blink-features=AutomationControlled",
                "--use-gl=desktop",
                "--disable-infobars",
                "--window-size=1366,768"
            ]
        )
        #ejecutar_banorte(browser);
        ejecutar_getnet(browser); browser.close()
