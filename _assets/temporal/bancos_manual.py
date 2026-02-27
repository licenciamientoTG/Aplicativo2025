import time
import os
import sys

# --- SOLUCIÓN ROBUSTA PARA EXE ---
os.environ['PLAYWRIGHT_BROWSERS_PATH'] = r'C:\PlaywrightBrowsers'

import shutil
import pyodbc
import pandas as pd
import unicodedata
import re
import traceback
import argparse
import json
from datetime import datetime, timedelta
from playwright.sync_api import sync_playwright

# --- CONFIGURACION BD ---
SERVER = '192.168.0.6'
DATABASE = 'TG'
USERNAME = 'cguser'
PASSWORD = 'sahei1712'

# --- CORE MAP Y CREDENCIALES (de bancos.py) ---
CORE_MAP = {
    'BANORTE': {
        'Afiliación': 'Afiliacion', 'Afiliacion': 'Afiliacion', 'Nombre de Afiliación': 'Nombre_Afiliacion', 'Nombre de Afiliacion': 'Nombre_Afiliacion', 'Moneda': 'Moneda', 'Estatus de Transacción': 'Estatus', 'Estatus de Transaccion': 'Estatus', 'Tipo transaccion': 'Tipo_Transaccion', 'Tipo de Transacción': 'Tipo_Transaccion', 'Tipo de Transaccion': 'Tipo_Transaccion', 'Número de Control': 'ID_Externo', 'Numero de Control': 'ID_Externo', 'Número de Tarjeta': 'Tarjeta', 'Numero de Tarjeta': 'Tarjeta', 'Tipo de Tarjeta': 'Tipo_Tarjeta', 'Monto de Transacción Signo': 'Monto', 'Monto de Transaccion Signo': 'Monto', 'Fecha Transacción': 'Fecha_Transaccion', 'Fecha Transaccion': 'Fecha_Transaccion', 'Código Autorización': 'Codigo_Autorizacion', 'Codigo Autorizacion': 'Codigo_Autorizacion', 'Referencia': 'Referencia_Pago', 'Terminal ID': 'Terminal', 'Terminal': 'Terminal', 'Lote de Transacción': 'Lote', 'Lote': 'Lote', 'Hora de Transacción': 'Hora', 'Hora Transacción': 'Hora', 'Hora': 'Hora', 'Referencia Interbancaria': 'Referencia', 'Fecha Depósito': 'Fecha_Deposito', 'Fecha Deposito': 'Fecha_Deposito', 'Fecha de Depósito': 'Fecha_Deposito', 'Fecha de Deposito': 'Fecha_Deposito', 'Fecha Aplicación': 'Fecha_Deposito', 'Fecha Aplicacion': 'Fecha_Deposito', 'Fecha de Aplicación': 'Fecha_Deposito', 'Fecha de Aplicacion': 'Fecha_Deposito'
    },
    'SANTANDER': {
        'ID movimiento': 'ID_Externo', 'ID Movimiento': 'ID_Externo', 'Fecha Transacción': 'Fecha_Transaccion', 'Fecha Transaccion': 'Fecha_Transaccion', 'Hora de Transacción': 'Hora', 'Hora de Transaccion': 'Hora', 'Hora Transacción': 'Hora', 'Hora Transaccion': 'Hora', 'Afiliación': 'Afiliacion', 'Afiliacion': 'Afiliacion', 'Cod. Terminal': 'Terminal', 'Terminal ID': 'Terminal', 'Código Autorización': 'Codigo_Autorizacion', 'Cod. Aut': 'Codigo_Autorizacion', 'Total': 'Monto', 'Monto de Transacción Signo': 'Monto', 'Monto de Transaccion Signo': 'Monto', 'Referencia': 'Referencia', 'Fecha Depósito': 'Fecha_Deposito', 'Fecha Deposito': 'Fecha_Deposito', 'Fecha de Depósito': 'Fecha_Deposito', 'Fecha de Deposito': 'Fecha_Deposito', 'Fecha Aplicación': 'Fecha_Deposito', 'Fecha Aplicacion': 'Fecha_Deposito', 'Fecha de Aplicación': 'Fecha_Deposito', 'Fecha de Aplicacion': 'Fecha_Deposito'
    }
}
CREDENCIALES_SANTANDER = [
    {"razon": "DIAZ GAS", "usuario": "Alfredo_escalera", "pass": "DIAZ1147"}, {"razon": "GASOMEX", "usuario": "israel.ibarra", "pass": "TotalG2023"}, {"razon": "JARUDO", "usuario": "israel.ibarrat", "pass": "IsraelTG17"}, {"razon": "CLARA", "usuario": "israel.ibarra1", "pass": "IsraelTG017"}, {"razon": "ESTACION CUSTODIA", "usuario": "tesoreria19", "pass": "Tesoreria19"}, {"razon": "SYC DELICIAS", "usuario": "susana.pantoja2", "pass": "Susana1511"}, {"razon": "VILLA AHUMADA", "usuario": "facturavilla", "pass": "VILLA1242"}, {"razon": "SMA VENTANAS", "usuario": "sve200529db9", "pass": "Hrmm7k01"}, {"razon": "SMA PICACHOS", "usuario": "spi200529sc7", "pass": "Hrmm7k01"}, {"razon": "EL CASTAÑO", "usuario": "martin.puentes", "pass": "Castaño12900"}, {"razon": "TSA", "usuario": "goperadortsadelcent", "pass": "Tsa2024!"}, {"razon": "HECTOR ARMANDINO", "usuario": "fihh7303026k7", "pass": "Praxedis25"},
]

# --- FUNCIONES HELPERS (de bancos.py) ---
def obtener_conexion():
    return pyodbc.connect(f'DRIVER={{SQL Server}};SERVER={SERVER};DATABASE={DATABASE};UID={USERNAME};PWD={PASSWORD}')

def limpiar_moneda(valor):
    if isinstance(valor, (int, float)): return float(valor)
    s = str(valor).replace('$', '').replace(',', '').strip(); return float(s) if s else 0.0

def limpiar_hora(valor):
    if not valor or str(valor).lower() == 'nan': return "00:00:00"
    s = str(valor).strip().upper().replace('.', '')
    s = re.sub(r'([AP])\s*M', r'\1M', s).replace('AM', ' AM').replace('PM', ' PM')
    s = re.sub(r'\s+', ' ', s).strip()
    if 'AM' in s or 'PM' in s:
        for fmt in ('%I:%M:%S %p', '%I:%M %p', '%H:%M:%S %p', '%H:%M %p'):
            try: return datetime.strptime(s, fmt).strftime('%H:%M:%S')
            except: continue
    if re.match(r'^\d{1,2}:\d{2}:\d{2}$', s): return f"{int(s.split(':')[0]):02d}:{s.split(':')[1]}:{s.split(':')[2]}"
    if re.match(r'^\d{1,2}:\d{2}$', s): return f"{int(s.split(':')[0]):02d}:{s.split(':')[1]}:00"
    d = re.sub(r'\D', '', s); return f"{d[:2]}:{d[2:4]}:{d[4:]}" if len(d) == 6 else (f"{d[:2]}:{d[2:]}:00" if len(d) == 4 else s[:8])

def limpiar_fecha(valor, formato_origen=None):
    if not valor or str(valor).lower() == 'nan': return None
    if isinstance(valor, datetime): return valor.strftime('%Y-%m-%d')
    s = str(valor).strip().replace("'", "")
    if formato_origen:
        try: return datetime.strptime(s, formato_origen).strftime('%Y-%m-%d')
        except: pass
    for fmt in ['%d/%m/%Y', '%Y-%m-%d', '%d-%m-%y', '%m/%d/%Y']:
        try: return datetime.strptime(s, fmt).strftime('%Y-%m-%d')
        except: continue
    return None

AFILIACION_TZ_POLICY = {}

def cargar_politica_horaria_afiliaciones(tipo_banco: str):
    """
    Construye (con cache en memoria) un mapa afiliacion -> zona horaria lógica:
    - 'CDMX': conservar hora tal como viene del banco.
    - 'JUAREZ': aplicar ajuste CDMX -> Juárez (invierno -1h).

    Regla de negocio:
      * Foráneas y Parral => 'CDMX'
      * Demás estaciones  => 'JUAREZ'
    """
    global AFILIACION_TZ_POLICY
    key = (tipo_banco or '').upper()
    if key in AFILIACION_TZ_POLICY:
        return AFILIACION_TZ_POLICY[key]

    entidad_id = None
    if key == 'BANORTE':
        entidad_id = 4
    elif key == 'SANTANDER':
        entidad_id = 1

    policy = {}
    if entidad_id is None:
        AFILIACION_TZ_POLICY[key] = policy
        return policy

    try:
        conn = obtener_conexion()
        cursor = conn.cursor()
        cursor.execute("""
            SELECT 
                A.afiliacion,
                ISNULL(S.Nombre, V.Nombre) as Estacion,
                ISNULL(A.rfc, 'FORANEAS') as RFC
            FROM Tesoreria_afil A
            LEFT JOIN Estaciones S ON A.estacion_id = S.Codigo
            LEFT JOIN Tesoreria_Estaciones_Virtuales V ON A.estacion_id = V.Codigo
            WHERE A.entidad_id = ?
              AND LEN(ISNULL(A.afiliacion,'')) > 0
              AND (S.Nombre IS NOT NULL OR V.Nombre IS NOT NULL)
        """, entidad_id)

        for afil, estacion, rfc in cursor.fetchall():
            if not afil:
                continue
            af = str(afil).strip().lstrip('0')
            if not af:
                continue

            est_nombre = (estacion or '').strip().upper()
            rfc_norm   = (rfc or '').strip().upper()

            es_foranea = (rfc_norm == '' or rfc_norm == 'FORANEAS')
            es_parral  = ('PARRAL' in est_nombre)
            es_clara   = ('CLARA' in est_nombre)

            policy[af] = 'CDMX' if (es_foranea or es_parral or es_clara) else 'JUAREZ'

        conn.close()
    except Exception as e:
        print(f"      ! Error cargando política horaria afiliaciones: {e}", file=sys.stderr)
        policy = {}

    AFILIACION_TZ_POLICY[key] = policy
    return policy

def debe_ajustar_juarez_para_afiliacion(afiliacion: str, tipo_banco: str) -> bool:
    """
    Devuelve True si, para la afiliación dada y el banco, se debe aplicar el
    ajuste de horario Juárez (CDMX -> Juárez). Si no hay información en catálogo,
    mantiene el comportamiento anterior (ajustar).
    """
    if not afiliacion:
        return True

    policy = cargar_politica_horaria_afiliaciones(tipo_banco)
    if not policy:
        return True

    af = str(afiliacion).strip()
    af_limpia = af.lstrip('0')

    zona = policy.get(af) or policy.get(af_limpia)
    if zona is None:
        return True

    return zona == 'JUAREZ'

def ajustar_tz_juarez(fecha_str, hora_str):
    if not fecha_str or not hora_str or hora_str == "00:00:00":
        return fecha_str, hora_str
    try:
        dt = datetime.strptime(f"{fecha_str} {hora_str}", "%Y-%m-%d %H:%M:%S")
        mar1 = datetime(dt.year, 3, 1)
        inicio_verano = (mar1 + timedelta(days=(6 - mar1.weekday()) % 7 + 7)).replace(hour=2)
        nov1 = datetime(dt.year, 11, 1)
        fin_verano = (nov1 + timedelta(days=(6 - nov1.weekday()) % 7)).replace(hour=2)
        offset = 0 if inicio_verano <= dt < fin_verano else -1
        if offset != 0:
            dt_adj = dt + timedelta(hours=offset)
            return dt_adj.strftime("%Y-%m-%d"), dt_adj.strftime("%H:%M:%S")
    except Exception as e:
        print(f"      ! Error ajustando TZ: {e}", file=sys.stderr)
    return fecha_str, hora_str

def sanitizar_nombre_columna(nombre, bank_type=None):
    if not nombre: return "SinNombre"
    orig = str(nombre).replace('\ufeff', '').replace('\xa0', ' ').strip()
    orig = re.sub(r'\s+', ' ', orig)
    if bank_type and orig in CORE_MAP.get(bank_type, {}): return CORE_MAP[bank_type][orig]
    norm = ''.join(c for c in unicodedata.normalize('NFD', orig.upper()) if unicodedata.category(c) != 'Mn')
    if 'FECHA' in norm:
        if re.search(r'DEPOSITO|APLICACION', norm): return 'Fecha_Deposito'
        if 'TRANSACCION' in norm: return 'Fecha_Transaccion'
    s = re.sub(r'[^a-zA-Z0-9_]', '_', ''.join(c for c in unicodedata.normalize('NFD', orig) if unicodedata.category(c) != 'Mn'))
    return re.sub(r'_+', '_', s).strip('_')

# --- FUNCIÓN DE PROCESAMIENTO MODIFICADA ---
# Ya no escribe en DB ni en archivos, solo procesa y devuelve los datos para el JSON final.
def procesar_y_obtener_nuevos_registros(file_path, tipo_banco, razon_social=None, target_date=None):
    if not os.path.exists(file_path): return "error", [], "Archivo no encontrado"
    try:
        conn = obtener_conexion(); cursor = conn.cursor()
        df = pd.read_excel(file_path) if tipo_banco == 'BANORTE' else pd.read_csv(file_path, encoding='utf-8-sig')
        if df.empty: conn.close(); return "success", [], "Archivo vacio"
        
        df.columns = [sanitizar_nombre_columna(c, tipo_banco) for c in df.columns]

        if 'Fecha_Deposito' not in df.columns or df['Fecha_Deposito'].isnull().all():
             return "error", [], "Archivo sin fechas de deposito. Reintentar mas tarde."

        tabla = 'banco_banorte' if tipo_banco == 'BANORTE' else 'banco_getnet'
        cursor.execute(f"SELECT Afiliacion, ID_Externo, Fecha_Transaccion, Monto, Hora, Codigo_Autorizacion, Referencia, Terminal FROM {tabla} WHERE Fecha_Transaccion > GETDATE()-90")
        huellas = {f"{str(r[0] or '').strip().lstrip('0')}|{str(r[1] or '').strip()}|{str(r[2])[:10]}|{float(r[3] or 0):.2f}|{limpiar_hora(r[4])}|{str(r[5] or '').strip()}|{str(r[6] or '').strip()}|{str(r[7] or '').strip()}" for r in cursor.fetchall()}
        
        nuevos_rows = []
        for _, row in df.iterrows():
            afil = str(row.get('Afiliacion', '')).strip().lstrip('0')
            id_ext = str(row.get('ID_Externo', '')).strip()
            monto = limpiar_moneda(row.get('Monto'))
            if not id_ext or monto <= 0:
                continue

            auth = str(row.get('Codigo_Autorizacion', '')).strip()
            hora = limpiar_hora(row.get('Hora'))
            ref = str(row.get('Referencia', '')).strip()
            term = str(row.get('Terminal', '')).strip()
            f_t = limpiar_fecha(row.get('Fecha_Transaccion'))
            f_d = limpiar_fecha(row.get('Fecha_Deposito'))

            # Ajuste horario: solo para estaciones no foráneas ni Parral
            if debe_ajustar_juarez_para_afiliacion(afil, tipo_banco):
                f_t, hora = ajustar_tz_juarez(f_t, hora)
            
            huella_row = f"{afil}|{id_ext}|{f_t or ''}|{monto:.2f}|{hora}|{auth}|{ref}|{term}"
            if huella_row in huellas:
                continue

            nuevos_rows.append({
                "ID_Externo": id_ext,
                "Afiliacion": afil,
                "Fecha_Transaccion": f_t,
                "Hora": hora,
                "Monto": monto,
                "Codigo_Autorizacion": auth,
                "Terminal": term,
                "Referencia": ref,
                "Fecha_Deposito": f_d,
                "Nombre_Archivo": os.path.basename(file_path),
                "Banco": tipo_banco,
                "Razon_Social": razon_social
            })
        
        # Si se procesó correctamente, actualizar la tabla de pendientes
        updated_pending_count = 0
        if target_date:
            try:
                update_cursor = conn.cursor()
                update_cursor.execute(
                    "UPDATE bancos_reportes_pendientes SET estado = 'COMPLETADO' WHERE banco = ? AND razon_social = ? AND CONVERT(date, fecha_reporte) = ? AND estado = 'PENDIENTE'",
                    (tipo_banco, razon_social, target_date.strftime('%Y-%m-%d'))
                )
                updated_pending_count = update_cursor.rowcount
                conn.commit()
            except Exception as e_update:
                print(f"DEBUG: No se pudo actualizar el estado de reportes pendientes: {e_update}", file=sys.stderr)

        message = f"{len(nuevos_rows)} registros nuevos encontrados."
        if updated_pending_count > 0:
            message += f" Se marcó {updated_pending_count} reporte pendiente como COMPLETADO."

        conn.close()
        os.remove(file_path)
        return "success", nuevos_rows, message
    except Exception as e:
        traceback.print_exc(file=sys.stderr)
        return "error", [], str(e)

# --- FUNCIONES DE NAVEGACIÓN (de bancos.py, adaptadas) ---
def get_banorte_manual(browser, target_date):
    UA = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36"
    fecha_str = target_date.strftime("%d/%m/%Y")
    context = browser.new_context(user_agent=UA, viewport={'width': 1366, 'height': 768}, permissions=['geolocation'], geolocation={'latitude': 19.4326, 'longitude': -99.1332}, locale='es-MX')
    context.add_init_script("Object.defineProperty(navigator, 'webdriver', {get: () => undefined})")
    page = context.new_page()
    try:
        page.goto("https://multicobros.banorte.com/internet/CIBV2/homepublico")
        page.wait_for_selector('input[formcontrolname="username"]', state="visible", timeout=30000)
        page.fill('input[formcontrolname="username"]', "israel.ibarra@totalgas.com")
        page.fill('input[formcontrolname="segu"]', "TotGAS26@$")
        page.click('.login_box_btn_entrar'); page.wait_for_load_state('networkidle', timeout=45000)
        if page.is_visible("text=abierta"): return {"status": "error", "message": "Sesion ya abierta", "data": []}
        page.click("a.nav-link:has-text('Analítica')", force=True); time.sleep(2)
        page.click(".dropdown-menu-custom-opcion:has-text('Reportes')", force=True); time.sleep(1)
        page.click("button.icon_r-reporteDepositos", force=True)
        page.wait_for_selector('input[data-provide="datepicker"]', timeout=30000)
        for i in [0, 1]:
            inp = page.locator('input[data-provide="datepicker"]').nth(i)
            inp.click(); page.keyboard.press("Control+a"); page.keyboard.press("Backspace"); inp.type(fecha_str, delay=50); page.keyboard.press("Enter")
        page.mouse.click(10, 10); time.sleep(1); page.click("button.btn-buscarn", force=True)
        page.wait_for_load_state('networkidle', timeout=90000)
        if page.is_visible("text=No existe información"): return {"status": "success", "data": [], "message": "Sin datos"}
        with page.expect_download(timeout=90000) as d_info: page.evaluate("document.querySelector('.icon-excel-2').click()")
        f_name = f"Manual_Banorte_{target_date.strftime('%Y%m%d%H%M%S')}.xlsx"; d_info.value.save_as(f_name)
        status, data, message = procesar_y_obtener_nuevos_registros(f_name, 'BANORTE', "BANORTE_GENERAL", target_date=target_date)
        return {"status": status, "data": data, "message": message}
    except Exception as e: return {"status": "error", "message": str(e), "data": []}
    finally: context.close()

def get_santander_manual(browser, target_date, estacion):
    cred = next((c for c in CREDENCIALES_SANTANDER if c["razon"] == estacion), None)
    if not cred: return {"status": "error", "message": "Estacion no encontrada", "data": []}
    context = browser.new_context(user_agent="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36", locale='es-MX', viewport={'width': 1366, 'height': 768})
    context.add_init_script("Object.defineProperty(navigator, 'webdriver', {get: () => undefined});")
    page = context.new_page()
    try:
        page.goto("https://www.globalgetnet.com/", timeout=90000)
        page.wait_for_selector('input[placeholder*="usuario"]', timeout=60000).fill(cred["usuario"])
        page.keyboard.press("Tab"); time.sleep(0.5); page.keyboard.type(cred["pass"], delay=50)
        page.click('button[type="submit"]')
        page.wait_for_selector("text=Reportes", timeout=45000).click()
        page.wait_for_selector("text=Transacciones", timeout=20000).click()
        page.wait_for_selector("text=/Seleccionar per.odo/i", timeout=30000).click()
        page.click("text=Una fecha personalizada", timeout=30000); time.sleep(2)
        sel_d = f"text='{target_date.day}'"
        page.locator(sel_d).first.click(); time.sleep(0.5); page.locator(sel_d).first.click(); time.sleep(2)
        page.click("button:has-text('Descargar')", timeout=5000)
        with page.expect_download(timeout=90000) as d_i: page.click("text=.csv")
        f_n = f"Manual_Getnet_{estacion.replace(' ', '_')}_{target_date.strftime('%Y%m%d%H%M%S')}.csv"; d_i.value.save_as(f_n)
        status, data, message = procesar_y_obtener_nuevos_registros(f_n, 'SANTANDER', estacion, target_date=target_date)
        return {"status": status, "data": data, "message": message}
    except Exception as e: return {"status": "error", "message": str(e), "data": []}
    finally: context.close()

# --- MAIN ---
if __name__ == "__main__":
    parser = argparse.ArgumentParser(); parser.add_argument("--banco", required=True); parser.add_argument("--fecha", required=True); parser.add_argument("--estacion", required=False)
    args = parser.parse_args()
    try: target_date = datetime.strptime(args.fecha, "%Y-%m-%d")
    except: print(json.dumps({"status": "error", "message": "Fecha invalida. Usar YYYY-MM-DD", "data": []})); sys.exit(1)
    
    with sync_playwright() as p:
        res = {}
        try:
            UA = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36"
            browser = p.chromium.launch(headless=False, args=[f"--user-agent={UA}", "--disable-features=Translate", "--disable-blink-features=AutomationControlled", "--use-gl=desktop", "--disable-infobars", "--window-size=1366,768"])
            if args.banco.upper() == "BANORTE": res = get_banorte_manual(browser, target_date)
            elif args.banco.upper() == "SANTANDER": res = get_santander_manual(browser, target_date, args.estacion) if args.estacion else {"status": "error", "message": "Falta --estacion para SANTANDER", "data": []}
            else: res = {"status": "error", "message": f"Banco '{args.banco}' no soportado.", "data": []}
            browser.close()
        except Exception as e:
            res = {"status": "error", "message": f"Error critico en Playwright: {e}", "data": []}
        
        print(json.dumps(res, indent=4))
