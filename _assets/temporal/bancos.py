import time
import os
import sys

# --- SOLUCIÓN ROBUSTA PARA EXE ---
# Obtenemos la ruta real de AppData del usuario (C:\Users\TuUsuario\AppData\Local)
local_app_data = os.getenv('LOCALAPPDATA')
if local_app_data:
    # Construimos la ruta completa a la carpeta de navegadores
    ruta_browsers = os.path.join(local_app_data, "ms-playwright")
    
    # Le obligamos a usar ESA ruta específica
    os.environ['PLAYWRIGHT_BROWSERS_PATH'] = ruta_browsers
    print(f"DEBUG: Forzando búsqueda de navegadores en: {ruta_browsers}")
else:
    # Fallback por si acaso
    os.environ['PLAYWRIGHT_BROWSERS_PATH'] = '0'

import shutil
import pyodbc
import pandas as pd
import unicodedata
import re
import traceback
from datetime import datetime, timedelta
from playwright.sync_api import sync_playwright
import smtplib
import ssl
from email.message import EmailMessage

# --- CONFIGURACION CORREO ---
SMTP_HOST = "smtp.gmail.com"
SMTP_PORT = 465  # SMTPS
SMTP_USER = "no-reply@totalgas.com"
SMTP_PASS = "sysdhepknmlkigbs"
ALERT_RECIPIENT = "daniel.ramirez@totalgas.com, sergio.guerra@totalgas.com"

# --- RESULTADOS GLOBALES ---
global_results = {
    'BANORTE': {
        'status': 'Iniciado',
        'registros_insertados': 0,
        'archivos_procesados': [],
        'mensaje': ''
    },
    'SANTANDER': {
        'status': 'Iniciado',
        'registros_insertados': 0,
        'archivos_procesados': [],
        'mensaje': ''
    }
}

# --- CONFIGURACION BD ---
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
        'Referencia Interbancaria': 'Referencia',    # ESTANDARIZADO
        'Fecha Depósito': 'Fecha_Deposito',
        'Fecha Deposito': 'Fecha_Deposito',
        'Fecha de Depósito': 'Fecha_Deposito',
        'Fecha de Deposito': 'Fecha_Deposito',
        'Fecha Aplicación': 'Fecha_Deposito',
        'Fecha Aplicacion': 'Fecha_Deposito',
        'Fecha de Aplicación': 'Fecha_Deposito',
        'Fecha de Aplicacion': 'Fecha_Deposito'
    },
    'SANTANDER': {
        'ID movimiento': 'ID_Externo',
        'ID Movimiento': 'ID_Externo',
        'Fecha Transacción': 'Fecha_Transaccion',
        'Fecha Transaccion': 'Fecha_Transaccion',
        'Hora de Transacción': 'Hora',
        'Hora de Transaccion': 'Hora',
        'Hora Transacción': 'Hora',
        'Hora Transaccion': 'Hora',
        'Afiliación': 'Afiliacion',
        'Afiliacion': 'Afiliacion',
        'Cod. Terminal': 'Terminal',                
        'Terminal ID': 'Terminal',
        'Código Autorización': 'Codigo_Autorizacion',
        'Cod. Aut': 'Codigo_Autorizacion',
        'Total': 'Monto',                           
        'Monto de Transacción Signo': 'Monto',
        'Monto de Transaccion Signo': 'Monto',
        'Referencia': 'Referencia',                 
        'Fecha Depósito': 'Fecha_Deposito',
        'Fecha Deposito': 'Fecha_Deposito',
        'Fecha de Depósito': 'Fecha_Deposito',
        'Fecha de Deposito': 'Fecha_Deposito',
        'Fecha Aplicación': 'Fecha_Deposito',
        'Fecha Aplicacion': 'Fecha_Deposito',
        'Fecha de Aplicación': 'Fecha_Deposito',
        'Fecha de Aplicacion': 'Fecha_Deposito'
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

def limpiar_hora(valor):
    if not valor or str(valor).lower() == 'nan': return "00:00:00"
    s = str(valor).strip().upper()
    
    # 1. Normalización de AM/PM (Quitar puntos, unir A M -> AM y asegurar espacio)
    s = s.replace('.', '')
    s = re.sub(r'([AP])\s*M', r'\1M', s) 
    s = s.replace('AM', ' AM').replace('PM', ' PM')
    s = re.sub(r'\s+', ' ', s).strip()

    # 2. Manejo de AM/PM (Prioridad: convertir 12h a 24h)
    if 'AM' in s or 'PM' in s:
        try:
            # Intentar varios formatos comunes de AM/PM
            for fmt in ('%I:%M:%S %p', '%I:%M %p', '%H:%M:%S %p', '%H:%M %p'):
                try:
                    return datetime.strptime(s, fmt).strftime('%H:%M:%S')
                except: continue
        except: pass

    # 3. Si ya tiene formato HH:MM:SS
    if re.match(r'^\d{1,2}:\d{2}:\d{2}$', s):
        # Asegurar ceros a la izquierda si es necesario (ej: 9:05:00 -> 09:05:00)
        parts = s.split(':')
        return f"{int(parts[0]):02d}:{parts[1]}:{parts[2]}"
    # Si tiene formato HH:MM (añadir segundos)
    if re.match(r'^\d{1,2}:\d{2}$', s):
        parts = s.split(':')
        return f"{int(parts[0]):02d}:{parts[1]}:00"
    
    # 4. Si solo son números (ej: 140542 de algunos reportes)
    digit_only = re.sub(r'\D', '', s)
    if len(digit_only) == 6:
        return f"{digit_only[:2]}:{digit_only[2:4]}:{digit_only[4:]}"
    if len(digit_only) == 4:
        return f"{digit_only[:2]}:{digit_only[2:]}:00"
        
    return s if len(s) <= 8 else s[:8]

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
        print(f"      ! Error cargando política horaria afiliaciones: {e}")
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
    """ 
    Ajusta la hora de CDMX (Bancos) a Hora Local Juarez.
    Regla:
    - Horario Verano (2do Dom Marzo 2am - 1er Dom Nov 2am): Igual a CDMX (Offset 0).
    - Horario Invierno (Resto del año): -1 Hora respecto a CDMX.
    """
    if not fecha_str or not hora_str or hora_str == "00:00:00":
        return fecha_str, hora_str
    
    try:
        # 1. Parsear fecha y hora
        dt_str = f"{fecha_str} {hora_str}"
        dt = datetime.strptime(dt_str, "%Y-%m-%d %H:%M:%S")
        year = dt.year
        
        # 2. Calcular límites DST para ese año
        # 2do Domingo de Marzo
        mar1 = datetime(year, 3, 1)
        # weekday(): 0=Lunes...6=Domingo. Dias para llegar al primer domingo:
        dias_para_1er_dom = (6 - mar1.weekday()) % 7
        segundo_dom_marzo = mar1 + timedelta(days=dias_para_1er_dom + 7)
        inicio_verano = segundo_dom_marzo.replace(hour=2, minute=0, second=0)
        
        # 1er Domingo de Noviembre
        nov1 = datetime(year, 11, 1)
        dias_para_1er_dom_nov = (6 - nov1.weekday()) % 7
        primer_dom_nov = nov1 + timedelta(days=dias_para_1er_dom_nov)
        fin_verano = primer_dom_nov.replace(hour=2, minute=0, second=0)
        
        # 3. Determinar Offset
        # Si estamos EN verano, la hora es igual (CDMX UTC-6, Juarez UTC-6)
        if inicio_verano <= dt < fin_verano:
            offset = 0
        else:
            # En invierno, Juarez se atrasa una hora (CDMX UTC-6, Juarez UTC-7)
            offset = -1
            
        # 4. Aplicar Offset
        if offset != 0:
            dt_adj = dt + timedelta(hours=offset)
            return dt_adj.strftime("%Y-%m-%d"), dt_adj.strftime("%H:%M:%S")
        
        # Si no hay cambio, retornar originales
        return fecha_str, hora_str

    except Exception as e:
        print(f"      ! Error ajustando TZ: {e}")
        return fecha_str, hora_str

def sanitizar_nombre_columna(nombre, bank_type=None):
    if not nombre: return "SinNombre"
    # Normalizar espacios y quitar BOM
    orig = str(nombre).replace('\ufeff', '').replace('\xef\xbb\xbf', '').replace('\xa0', ' ').strip()
    orig = re.sub(r'\s+', ' ', orig)

    if bank_type and orig in CORE_MAP.get(bank_type, {}):
        return CORE_MAP[bank_type][orig]
    
    # --- FUZZY MATCH ROBUSTO (Regex) ---
    # Normalizar para busqueda de patrones (Mayúsculas y sin acentos)
    norm = ''.join(c for c in unicodedata.normalize('NFD', orig.upper()) if unicodedata.category(c) != 'Mn')
    
    if 'FECHA' in norm:
        if re.search(r'DEPOSITO|APLICACION', norm):
            return 'Fecha_Deposito'
        if 'TRANSACCION' in norm:
            return 'Fecha_Transaccion'

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

def subir_a_db(file_path, tipo_banco, razon_social=None):
    print(f"Procesando {file_path} ({tipo_banco})...")
    if not os.path.exists(file_path): return True, 0, "Archivo no encontrado"
    
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
            return True, 0, "Archivo vacio"
        
        # 1. Mapear columnas segun CORE_MAP
        mapped_indices = {}
        for c in df.columns:
            std_name = sanitizar_nombre_columna(c, tipo_banco)
            if std_name in COLUMNAS_OFICIALES:
                mapped_indices[std_name] = c
        
        # --- VALIDACION FECHA DEPOSITO ---
        if 'Fecha_Deposito' not in mapped_indices:
            msg = "Error: No se encontro columna de Fecha de Deposito."
            print(f"{msg}")
            conn.close()
            return False, 0, msg
            
        col_fecha_depo = mapped_indices['Fecha_Deposito']
        # Validar que todos los registros tengan fecha de deposito
        series_depo = df[col_fecha_depo].apply(lambda x: limpiar_fecha(x))
        if series_depo.isnull().any():
            msg = f"El archivo {os.path.basename(file_path)} aun no cuenta con el 100% de las fechas de deposito."
            print(f"Mensaje: {msg}")
            
            # --- NUEVA LÓGICA PARA PENDIENTES ---
            ruta_base_abs = r"C:\inetpub\wwwroot\TG_PHP\_assets\uploads"
            pending_dir = os.path.join(ruta_base_abs, "PENDING", tipo_banco, datetime.now().strftime('%Y'), datetime.now().strftime('%m'))
            if not os.path.exists(pending_dir):
                os.makedirs(pending_dir)
            
            pending_file_name = f"PENDING_{datetime.now().strftime('%H%M%S')}_{os.path.basename(file_path)}"
            pending_file_path = os.path.join(pending_dir, pending_file_name)
            
            shutil.copy(file_path, pending_file_path)
            
            try:
                cursor_pend = conn.cursor()
                # Intentar extraer fecha del nombre de archivo (Soportar YYYY-MM-DD y DD-MM-YYYY)
                match_fecha = re.search(r'(\d{4}-\d{2}-\d{2})', os.path.basename(file_path))
                if match_fecha:
                    fecha_reporte = match_fecha.group(1)
                else:
                    match_fecha_inv = re.search(r'(\d{2}-\d{2}-\d{4})', os.path.basename(file_path))
                    if match_fecha_inv:
                        # Convertir DD-MM-YYYY a YYYY-MM-DD
                        d, m, y = match_fecha_inv.group(1).split('-')
                        fecha_reporte = f"{y}-{m}-{d}"
                    else:
                        fecha_reporte = datetime.now().strftime('%Y-%m-%d')

                cursor_pend.execute(
                    "SELECT id, numero_intentos FROM bancos_reportes_pendientes WHERE banco = ? AND razon_social = ? AND fecha_reporte = ? AND estado IN ('PENDIENTE', 'FALLIDO_MAX_INTENTOS')",
                    (tipo_banco, razon_social, fecha_reporte)
                )
                existing_pending = cursor_pend.fetchone()

                if existing_pending:
                    cursor_pend.execute(
                        "UPDATE bancos_reportes_pendientes SET fecha_ultimo_intento = GETDATE(), numero_intentos = numero_intentos + 1, mensaje_ultimo_error = ?, estado = 'PENDIENTE' WHERE id = ?",
                        (msg, existing_pending.id)
                    )
                else:
                    cursor_pend.execute(
                        "INSERT INTO bancos_reportes_pendientes (banco, razon_social, fecha_reporte, ruta_archivo_temporal, estado, mensaje_ultimo_error) VALUES (?, ?, ?, ?, 'PENDIENTE', ?)",
                        (tipo_banco, razon_social, fecha_reporte, pending_file_path.replace("\\", "/"), msg)
                    )
                conn.commit()
            except Exception as e_pend:
                print(f"Error al registrar pendiente: {e_pend}")
            
            conn.close()
            if os.path.exists(file_path):
                os.remove(file_path)
            return False, 0, "PENDIENTE_VALIDACION"
        # ---------------------------------

        # 2. Huellas para duplicados (8 CAMPOS CLAVE - COINCIDENCIA CON PHP)
        tabla = 'banco_banorte' if tipo_banco == 'BANORTE' else 'banco_getnet'
        # Optimización: Filtrar por banco/afiliacion si es posible, pero por ahora mantenemos consistencia
        cursor.execute(f"SELECT Afiliacion, ID_Externo, Fecha_Transaccion, Monto, Hora, Codigo_Autorizacion, Referencia, Terminal FROM {tabla}")
        huellas = set()
        for r in cursor.fetchall():
            # ESTANDARIZAR PARA COMPARACION ROBUSTA
            afil_db = str(r[0] or '').strip().lstrip('0')
            id_ext_db = str(r[1] or '').strip()
            fch_db = str(r[2])[:10] if r[2] else ''
            monto_db = float(r[3] or 0)
            hora_db = limpiar_hora(r[4])
            auth_db = str(r[5] or '').strip()
            ref_db = str(r[6] or '').strip()
            term_db = str(r[7] or '').strip()
            
            key = f"{afil_db}|{id_ext_db}|{fch_db}|{monto_db:.2f}|{hora_db}|{auth_db}|{ref_db}|{term_db}"
            huellas.add(key)

        # 3. Procesar Filas
        nuevos_rows = []
        # Revertido a ruta absoluta servidor segun indicacion usuario
        ruta_base_abs = r"C:\inetpub\wwwroot\TG_PHP\_assets\uploads"
        
        sub_ruta = os.path.join(tipo_banco, datetime.now().strftime('%Y'), datetime.now().strftime('%m'))
        nombre_solo = f"f{datetime.now().strftime('%H%M%S')}_{os.path.basename(file_path)}"
        db_file_path = os.path.join(sub_ruta, nombre_solo).replace("\\", "/")

        for _, row in df.iterrows():
            # Extraer y Limpiar (Logica Bank Upload)
            afil = str(row.get(mapped_indices.get('Afiliacion')) or '').strip().lstrip('0')
            id_ext = str(row.get(mapped_indices.get('ID_Externo')) or '').strip()
            monto = limpiar_moneda(row.get(mapped_indices.get('Monto')))
            auth = str(row.get(mapped_indices.get('Codigo_Autorizacion')) or '').strip()
            # CAMBIO: Usar limpiar_hora para estandarizar formato HH:MM:SS
            hora = limpiar_hora(row.get(mapped_indices.get('Hora')))
            ref = str(row.get(mapped_indices.get('Referencia')) or '').strip()
            term = str(row.get(mapped_indices.get('Terminal')) or '').strip()
            f_trans = limpiar_fecha(row.get(mapped_indices.get('Fecha_Transaccion')))
            f_depo = limpiar_fecha(row.get(mapped_indices.get('Fecha_Deposito')))

            # CAMBIO: Ajustar horario CDMX -> JUAREZ (-1 hora) solo para estaciones
            # que no sean foráneas ni Parral (regla de negocio).
            if debe_ajustar_juarez_para_afiliacion(afil, tipo_banco):
                # Esto también puede mover la fecha si la transacción fue cerca de medianoche
                f_trans, hora = ajustar_tz_juarez(f_trans, hora)

            # EVITAR REGISTROS FANTASMA O NEGATIVOS
            if not id_ext or monto <= 0:
                continue

            huella_row = f"{afil}|{id_ext}|{f_trans or ''}|{monto:.2f}|{hora}|{auth}|{ref}|{term}"
            if huella_row in huellas:
                continue

            data_to_insert = [id_ext, afil, f_trans, hora, monto, auth, term, ref, f_depo, db_file_path]
            nuevos_rows.append(tuple(data_to_insert))
            huellas.add(huella_row)

        # ---------------------------------------------------------
        # COPIA SEGURA: Primero copiamos el archivo fisico
        # ---------------------------------------------------------
        target_dir = os.path.join(ruta_base_abs, sub_ruta)
        if not os.path.exists(target_dir): 
            os.makedirs(target_dir)
            
        target_path_full = os.path.join(target_dir, nombre_solo)
        shutil.copy(file_path, target_path_full)
        print(f"   Archivado en: {target_path_full}")

        # ---------------------------------------------------------
        # LUEGO INSERTAMOS EN DB
        # ---------------------------------------------------------
        count_inserted = len(nuevos_rows)
        if nuevos_rows:
            placeholders = ", ".join(["?" for _ in COLUMNAS_OFICIALES])
            cols_sql = ", ".join(COLUMNAS_OFICIALES)
            sql = f"INSERT INTO {tabla} ({cols_sql}) VALUES ({placeholders})"
            for i in range(0, len(nuevos_rows), 1000):
                cursor.executemany(sql, nuevos_rows[i:i+1000])
            conn.commit()
            print(f"   {count_inserted} insertados en {tabla}.")
        else:
            print("   No hay registros nuevos para insertar.")
        
        conn.close()
        
        # Actualizar globales
        global_results[tipo_banco if tipo_banco == 'BANORTE' else 'SANTANDER']['registros_insertados'] += count_inserted
        global_results[tipo_banco if tipo_banco == 'BANORTE' else 'SANTANDER']['archivos_procesados'].append(os.path.basename(file_path))

        # Limpiar temporal solo al final de todo
        if os.path.exists(file_path):
            os.remove(file_path)
        return True, count_inserted, "OK"
        
    except Exception as e:
        msg_err = f"Error procesando {tipo_banco}: {e}"
        print(f"   {msg_err}"); traceback.print_exc()
        if 'conn' in locals() and conn:
            try: conn.close()
            except: pass
        return False, 0, msg_err

def get_last_banorte_date():
    try:
        conn = obtener_conexion()
        cursor = conn.cursor()
        # Filtro de seguridad: Ignorar fechas futuras que puedan estar corrompiendo el MAX
        cursor.execute("SELECT MAX(Fecha_Transaccion) FROM banco_banorte WHERE Fecha_Transaccion <= GETDATE()")
        res = cursor.fetchone()[0]
        conn.close()
        
        if res is None: 
            print("   No se encontraron registros. Iniciando desde hace 30 días.")
            return datetime.now() - timedelta(days=30)
        
        # Si ya es un objeto de fecha/tiempo
        if isinstance(res, (datetime, pd.Timestamp)):
            print(f"   Última fecha Banorte en DB: {res.strftime('%Y-%m-%d')}")
            return res
            
        # Si es string, limpiar y convertir
        res_str = str(res).split(' ')[0] 
        try:
            val = datetime.strptime(res_str, '%Y-%m-%d')
        except:
            val = datetime.strptime(res_str, '%d/%m/%Y')
            
        print(f"   Última fecha Banorte en DB: {val.strftime('%Y-%m-%d')}")
        return val
    except Exception as e:
        print(f"   Error consultando última fecha Banorte: {e}")
        return datetime.now() - timedelta(days=30)

def get_last_date_by_razon(banco, razon):
    try:
        conn = obtener_conexion()
        cursor = conn.cursor()
        if banco == 'SANTANDER':
            # Buscamos el último registro que pertenezca a esta estación vía Nombre_Archivo
            # FILTRO: Solo archivos que estén en la subcarpeta SANTANDER/
            pattern_razon = f"%{razon.replace(' ', '%')}%"
            cursor.execute("SELECT MAX(Fecha_Transaccion) FROM banco_getnet WHERE Nombre_Archivo LIKE 'SANTANDER/%' AND Nombre_Archivo LIKE ? AND Fecha_Transaccion <= GETDATE()", (pattern_razon,))
        else:
            cursor.execute("SELECT MAX(Fecha_Transaccion) FROM banco_banorte WHERE Fecha_Transaccion <= GETDATE()")
            
        res = cursor.fetchone()[0]
        conn.close()
        
        if res is None: 
            return datetime.now() - timedelta(days=30)
        
        if isinstance(res, (datetime, pd.Timestamp)):
            return res
            
        res_str = str(res).split(' ')[0] 
        try:
            return datetime.strptime(res_str, '%Y-%m-%d')
        except:
            return datetime.strptime(res_str, '%d/%m/%Y')
    except Exception as e:
        print(f"   [!] Error consultando última fecha ({razon}): {e}")
        return datetime.now() - timedelta(days=30)

def ejecutar_banorte(browser):
    print("\n" + "="*50 + "\n>>> 1. INICIANDO PROCESO BANORTE (BUCLE DE RECUPERACIÓN)\n" + "="*50)
    RAZON_SOCIAL_BANORTE = "BANORTE_GENERAL"
    USUARIO, PASSWORD = "israel.ibarra@totalgas.com", "TotGAS26@$"
    
    # 1. Definir rango de fechas
    fecha_limite = datetime.now() - timedelta(days=3)
    fecha_inicio = get_last_banorte_date() + timedelta(days=1)
    
    # NUEVO: Tracking de dias procesados en esta sesion para no repetir
    dias_procesados_hoy = set()

    # NUEVO: Verificar si hay reportes pendientes en la base de datos
    tiene_pendientes = False
    try:
        conn_check = obtener_conexion()
        cursor_check = conn_check.cursor()
        cursor_check.execute(
            "SELECT TOP 1 id FROM bancos_reportes_pendientes WHERE banco = 'BANORTE' AND estado = 'PENDIENTE' AND razon_social = ?",
            (RAZON_SOCIAL_BANORTE,)
        )
        if cursor_check.fetchone():
            tiene_pendientes = True
        conn_check.close()
    except Exception as e_check:
        print(f"   [!] Error verificando pendientes: {e_check}")

    # Ajuste: Solo salir si esta al dia Y no tiene nada pendiente por procesar
    if fecha_inicio > fecha_limite and not tiene_pendientes:
        print(f"   [OK] Banorte esta al dia (Meta: {fecha_limite.strftime('%Y-%m-%d')}) y no hay reportes pendientes.")
        return

    print(f"   [FECHA] Rango a procesar: {fecha_inicio.strftime('%Y-%m-%d')} -> {fecha_limite.strftime('%Y-%m-%d')}")
    if tiene_pendientes:
        print("   [INFO] Se detectaron reportes pendientes que seran procesados.")

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
        time.sleep(5) 

        # NUEVO: Detección de Sesión Abierta
        if page.is_visible("text=abierta") or page.is_visible("text=Abierta") or page.is_visible("text=ABIERTA"):
            print("   ALERTA: Se detectó una sesión ya abierta en otro dispositivo. Abortando proceso Banorte.")
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
        
        # --- PROCESAR PENDIENTES ---
        try:
            conn_pend = obtener_conexion()
            cursor_pend_check = conn_pend.cursor()
            cursor_pend_check.execute(
                "SELECT id, fecha_reporte, numero_intentos FROM bancos_reportes_pendientes WHERE banco = 'BANORTE' AND estado IN ('PENDIENTE', 'FALLIDO_MAX_INTENTOS') AND razon_social = ?",
                (RAZON_SOCIAL_BANORTE,)
            )
            pendientes = cursor_pend_check.fetchall()
            conn_pend.close()

            if pendientes:
                print(f"\n   [REINTENTO] Procesando {len(pendientes)} reportes pendientes para BANORTE...")
                for p_id, p_fecha_reporte, p_intentos in pendientes:
                    # NORMALIZACION: Asegurar que p_fecha_reporte sea datetime
                    if isinstance(p_fecha_reporte, str):
                        try: p_fecha_reporte = datetime.strptime(p_fecha_reporte[:10], "%Y-%m-%d")
                        except: pass

                    fecha_str = p_fecha_reporte.strftime("%d/%m/%Y")
                    print(f"      Reintentando día: {fecha_str} (Intento {p_intentos + 1})")
                    dias_procesados_hoy.add(p_fecha_reporte.strftime("%Y-%m-%d"))
                    
                    try:
                        page.wait_for_selector('input[data-provide="datepicker"]')
                        i_ini = page.locator('input[data-provide="datepicker"]').nth(0)
                        i_ini.click(); page.keyboard.press("Control+a"); page.keyboard.press("Backspace")
                        time.sleep(0.3); i_ini.type(fecha_str, delay=100); page.keyboard.press("Enter"); page.keyboard.press("Escape")
                        time.sleep(0.5)
                        i_fin = page.locator('input[data-provide="datepicker"]').nth(1)
                        i_fin.click(); page.keyboard.press("Control+a"); page.keyboard.press("Backspace")
                        time.sleep(0.3); i_fin.type(fecha_str, delay=100); page.keyboard.press("Enter"); page.keyboard.press("Escape")
                        page.mouse.click(10, 10); time.sleep(1.0)
                        page.click("button.btn-buscarn", force=True); time.sleep(5)

                        if page.is_visible("text=No existe información de acuerdo con los criterios seleccionados"):
                            print(f"      (Vacío) El banco aún no tiene datos para el {fecha_str}.")
                        else:
                            with page.expect_download(timeout=60000) as download_info:
                                page.evaluate("document.querySelector('.icon-excel-2').click()")
                            
                            download = download_info.value
                            nombre_archivo = f"Banorte_{p_fecha_reporte.strftime('%Y-%m-%d')}.xlsx"
                            download.save_as(nombre_archivo)
                            
                            success, count, msg = subir_a_db(nombre_archivo, 'BANORTE', razon_social=RAZON_SOCIAL_BANORTE)
                            
                            conn_update = obtener_conexion()
                            cursor_update = conn_update.cursor()
                            if success and msg != "PENDIENTE_VALIDACION":
                                print(f"      [COMPLETADO] Reporte pendiente {fecha_str} procesado exitosamente.")
                                cursor_update.execute("UPDATE bancos_reportes_pendientes SET estado = 'COMPLETADO' WHERE id = ?", (p_id,))
                            elif msg == "PENDIENTE_VALIDACION":
                                print(f"      [PENDIENTE] Reporte {fecha_str} sigue incompleto.")
                            else: # Otro error
                                cursor_update.execute("UPDATE bancos_reportes_pendientes SET mensaje_ultimo_error = ? WHERE id = ?", (msg, p_id))
                            conn_update.commit()
                            conn_update.close()

                    except Exception as e_reintento:
                        print(f"      [!] Error en reintento de {fecha_str}: {e_reintento}")

        except Exception as e_pend_main:
            print(f"   [ERROR] Fallo al procesar pendientes de Banorte: {e_pend_main}")

        # --- BUCLE POR DÍA ---
        current_date = fecha_inicio
        while current_date <= fecha_limite:
            if current_date.strftime("%Y-%m-%d") in dias_procesados_hoy:
                current_date += timedelta(days=1)
                continue

            fecha_str = current_date.strftime("%d/%m/%Y")
            print(f"\n   >>> Procesando día: {fecha_str}")
            
            try:
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
                
                page.mouse.click(10, 10)
                time.sleep(1.0) 
                
                page.click("button.btn-buscarn", force=True)
                time.sleep(5) 
                
                if page.is_visible("text=No existe información de acuerdo con los criterios seleccionados"):
                    print(f"      (Vacío) El banco no tiene datos para el {fecha_str}.")
                else:
                    print("      Esperando tabla..."); 
                    try: page.wait_for_selector("table", timeout=15000)
                    except: pass

                    print("      Descargando Excel...")
                    selector_excel = ".icon-excel-2"
                    if page.is_visible(selector_excel):
                        with page.expect_download(timeout=60000) as download_info:
                            page.evaluate("document.querySelector('.icon-excel-2').click()")
                        
                        download = download_info.value
                        nombre_archivo = f"Banorte_{current_date.strftime('%Y-%m-%d')}.xlsx"
                        download.save_as(nombre_archivo)
                        
                        print(f"      Descarga exitosa: {nombre_archivo}")
                        success, count, msg = subir_a_db(nombre_archivo, 'BANORTE', razon_social=RAZON_SOCIAL_BANORTE)
                        if not success:
                            print(f"      [AVISO] El día {fecha_str} quedó como pendiente/incompleto: {msg}")
                            if not global_results['BANORTE']['mensaje']:
                                global_results['BANORTE']['mensaje'] = f"Día {fecha_str} pendiente."
                    else:
                        print("      No se encontro el boton de Excel para este dia.")
            except Exception as e_dia:
                print(f"      Error en dia {fecha_str}: {e_dia}")
            
            # Avanzar al siguiente dia
            current_date += timedelta(days=1)
            time.sleep(2) # Pausa tecnica

        if global_results['BANORTE']['status'] == 'Iniciado':
            global_results['BANORTE']['status'] = 'Completado'

        print("\n   Cerrando sesion...")
        try: page.locator("button.btn-close-session-xs").dispatch_event("click")
        except: page.click("button.btn-close-session-xs", force=True)
        time.sleep(3)

    except Exception as e:
        print(f"   Error Banorte: {e}")
    finally:
        context.close()

def ejecutar_getnet(browser):

    print("\n" + "="*50 + "\n>>> 2. INICIANDO PROCESO GETNET (BUCLE DE RECUPERACIÓN)\n" + "="*50)

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

    # 1. Definir fecha limite global
    fecha_limite = datetime.now() - timedelta(days=3)
    
    for cred in CREDENCIALES:
        estacion = cred["razon"]; usuario = cred["usuario"]; password = cred["pass"]
        print(f"\nGetnet: {estacion}..."); 

        # 2. Fecha inicio especifica por estacion
        fecha_inicio = get_last_date_by_razon('SANTANDER', estacion) + timedelta(days=1)
        
        # Tracking de dias procesados por estacion
        dias_procesados_estacion = set()

        # Verificar si hay pendientes para esta estacion
        tiene_pendientes = False
        try:
            conn_c = obtener_conexion(); cursor_c = conn_c.cursor()
            cursor_c.execute(
                "SELECT TOP 1 id FROM bancos_reportes_pendientes WHERE banco = 'SANTANDER' AND razon_social = ? AND estado = 'PENDIENTE'",
                (estacion,)
            )
            if cursor_c.fetchone(): tiene_pendientes = True
            conn_c.close()
        except: pass

        if fecha_inicio > fecha_limite and not tiene_pendientes:
            print(f"   [OK] {estacion} al día.")
            continue

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
        
        # STEALTH AVANZADO
        context.add_init_script("""
            Object.defineProperty(navigator, 'webdriver', {get: () => undefined});
            window.chrome = { runtime: {}, loadTimes: function() {}, csi: function() {}, app: {} };
            Object.defineProperty(navigator, 'languages', {get: () => ['es-MX', 'es', 'en-US', 'en']});
            Object.defineProperty(navigator, 'plugins', {get: () => [1, 2, 3, 4, 5]});
            Object.defineProperty(navigator, 'hardwareConcurrency', {get: () => 8});
            Object.defineProperty(navigator, 'deviceMemory', {get: () => 8});
            Object.defineProperty(navigator, 'platform', {get: () => 'Win32'});
            const getParameter = WebGLRenderingContext.prototype.getParameter;
            WebGLRenderingContext.prototype.getParameter = function(parameter) {
                if (parameter === 37445) return 'Intel Inc.'; 
                if (parameter === 37446) return 'Intel(R) Iris(TM) Graphics 6100';
                return getParameter(parameter);
            };
        """)

        # DEBUG: Iniciar traza
        context.tracing.start(screenshots=True, snapshots=True, sources=True)

        page = context.new_page()
        page.mouse.move(100, 100)
        time.sleep(0.5)

        # CDP
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
            page.mouse.wheel(0, 300) 
            time.sleep(3) 
            
            print("   Llenando credenciales...");
            sel_user = 'input[name="callback_0"], input[name="callback_1"], input[placeholder*="Correo"], input[placeholder*="usuario"]'
            page.wait_for_selector(sel_user, state="visible", timeout=30000)
            page.locator(sel_user).first.fill(usuario)
            page.keyboard.press("Tab")
            time.sleep(1)
            page.keyboard.type(password, delay=100)
            
            if page.is_visible('button[type="submit"]'): page.click('button[type="submit"]')
            else: page.click("text=Iniciar sesión")
            
            print("   Navegando a Reportes...");
            time.sleep(5)
            page.click("text=Reportes", timeout=30000)
            
            time.sleep(2)
            print("   Abriendo Transacciones...");
            page.click("text=Transacciones", timeout=20000)
            
            time.sleep(5)
            print("   Configurando periodo...");
            page.click("text=/Seleccionar per.odo/i", timeout=30000)
            time.sleep(1)
            page.click("text=Una fecha personalizada", timeout=30000)
            time.sleep(2)
            
            # --- PROCESAR PENDIENTES ---
            try:
                conn_pend = obtener_conexion()
                cursor_pend_check = conn_pend.cursor()
                cursor_pend_check.execute(
                    "SELECT id, fecha_reporte, numero_intentos FROM bancos_reportes_pendientes WHERE banco = 'SANTANDER' AND estado IN ('PENDIENTE', 'FALLIDO_MAX_INTENTOS') AND razon_social = ?",
                    (estacion,)
                )
                pendientes = cursor_pend_check.fetchall()
                conn_pend.close()

                if pendientes:
                    print(f"\n   [REINTENTO] Procesando {len(pendientes)} reportes pendientes...")
                    for p_id, p_fecha_reporte, p_intentos in pendientes:
                        # NORMALIZACION: Asegurar que p_fecha_reporte sea datetime
                        if isinstance(p_fecha_reporte, str):
                            try: p_fecha_reporte = datetime.strptime(p_fecha_reporte[:10], "%Y-%m-%d")
                            except: pass

                        fecha_str = p_fecha_reporte.strftime("%d/%m/%Y")
                        print(f"      Reintentando día: {fecha_str} (Intento {p_intentos + 1})")
                        dias_procesados_estacion.add(p_fecha_reporte.strftime("%Y-%m-%d"))

                        try:
                            selector_dia = f"text='{p_fecha_reporte.day}'"
                            page.locator(selector_dia).first.click()
                            time.sleep(0.5)
                            page.locator(selector_dia).first.click()
                            time.sleep(2)

                            try: page.click("button:has-text('Descargar')", timeout=5000)
                            except: page.click("text=Descargar", timeout=5000)

                            with page.expect_download(timeout=60000) as download_info:
                                page.click("text=.csv")

                            download = download_info.value
                            nombre_archivo = f"Getnet_{estacion.replace(' ', '_')}_{p_fecha_reporte.strftime('%Y-%m-%d')}.csv"
                            download.save_as(nombre_archivo)
                            
                            success, count, msg = subir_a_db(nombre_archivo, 'SANTANDER', razon_social=estacion)
                            
                            conn_update = obtener_conexion()
                            cursor_update = conn_update.cursor()
                            if success and msg != "PENDIENTE_VALIDACION":
                                print(f"      [COMPLETADO] Reporte pendiente {p_fecha_reporte.strftime('%Y-%m-%d')} procesado.")
                                cursor_update.execute("UPDATE bancos_reportes_pendientes SET estado = 'COMPLETADO' WHERE id = ?", (p_id,))
                            elif msg == "PENDIENTE_VALIDACION":
                                print(f"      [PENDIENTE] Reporte {p_fecha_reporte.strftime('%Y-%m-%d')} sigue incompleto.")
                            else:
                                cursor_update.execute("UPDATE bancos_reportes_pendientes SET mensaje_ultimo_error = ? WHERE id = ?", (msg, p_id))
                            conn_update.commit()
                            conn_update.close()

                            page.click("text=/Seleccionar per.odo/i", timeout=30000)
                            time.sleep(1)
                            page.click("text=Una fecha personalizada", timeout=30000)
                            time.sleep(1)

                        except Exception as e_reintento:
                            print(f"      [!] Error en reintento de {fecha_str}: {e_reintento}")
                            try: 
                                page.keyboard.press("Escape")
                                page.click("text=/Seleccionar per.odo/i")
                                page.click("text=Una fecha personalizada")
                            except: pass

            except Exception as e_pend_main:
                print(f"   [ERROR] Fallo al procesar pendientes para {estacion}: {e_pend_main}")

            # --- BUCLE POR DÍA (Dentro de la sesión) ---
            current_date = fecha_inicio
            while current_date <= fecha_limite:
                fecha_consulta_str = current_date.strftime("%Y-%m-%d")
                if fecha_consulta_str in dias_procesados_estacion:
                    current_date += timedelta(days=1)
                    continue

                dia_objetivo = str(current_date.day)
                print(f"      Procesando día: {fecha_consulta_str} (Día {dia_objetivo})...")

                try:
                    selector_dia = f"text='{dia_objetivo}'"
                    page.locator(selector_dia).first.click()
                    time.sleep(0.5)
                    page.locator(selector_dia).first.click()
                    time.sleep(2)
                    
                    try: page.click("button:has-text('Descargar')", timeout=5000)
                    except: page.click("text=Descargar", timeout=5000)
                    
                    with page.expect_download(timeout=60000) as download_info:
                        page.click("text=.csv")
                    
                    download = download_info.value
                    nombre_archivo = f"Getnet_{estacion.replace(' ', '_')}_{fecha_consulta_str}.csv"
                    download.save_as(nombre_archivo)
                    
                    success, count, msg = subir_a_db(nombre_archivo, 'SANTANDER', razon_social=estacion)
                    if not success:
                        print(f"      [AVISO] {fecha_consulta_str} incompleto: {msg}")
                        if not global_results['SANTANDER']['mensaje']:
                            global_results['SANTANDER']['mensaje'] = f"Día {fecha_consulta_str} ({estacion}) pendiente."
                    
                    page.click("text=/Seleccionar per.odo/i", timeout=30000)
                    time.sleep(1)
                    page.click("text=Una fecha personalizada", timeout=30000)
                    time.sleep(1)
                    
                except Exception as e_dia:
                    print(f"      [!] Error en día {fecha_consulta_str}: {e_dia}")
                    try: 
                        page.keyboard.press("Escape")
                        page.click("text=/Seleccionar per.odo/i")
                        page.click("text=Una fecha personalizada")
                    except: pass

                current_date += timedelta(days=1)
                time.sleep(1)

            # Cerrar sesión
            print("   Cerrando sesion...");
            try: page.click("text=Salir", timeout=5000)
            except:
                try: 
                    page.mouse.click(1330, 30)
                    time.sleep(1)
                    page.click("text=Salir")
                except: pass

        except Exception as e:
            print(f"    Error {estacion}: {e}")
            safe_estacion = estacion.replace(" ", "_")
            page.screenshot(path=f"debug_error_{safe_estacion}.png")
        finally:
            safe_estacion = estacion.replace(" ", "_")
            try:
                context.tracing.stop(path=f"trace_{safe_estacion}.zip")
            except: pass
            
            try:
                # Pequeña pausa para asegurar que no haya procesos de descarga colgados
                time.sleep(1)
                context.close()
                print(f"   Contexto de {estacion} cerrado correctamente.")
            except Exception as e_close:
                print(f"   [!] Error cerrando contexto de {estacion}: {e_close}")
            
            # Pausa extra entre estaciones para asegurar limpieza de procesos
            time.sleep(2)

    if global_results['SANTANDER']['status'] == 'Iniciado':
        global_results['SANTANDER']['status'] = 'Completado'

def send_email_report():
    print("\n>>> Enviando reporte por correo...")
    
    # --- Texto plano ---
    lines_text = ["Reporte de ejecucion script de Bancos\n"]
    lines_text.append(f"Fecha: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}\n")
    
    for banco, data in global_results.items():
        lines_text.append(f"BANCO: {banco}")
        lines_text.append(f"Status: {data['status']}")
        lines_text.append(f"Registros insertados: {data['registros_insertados']}")
        lines_text.append(f"Archivos procesados: {', '.join(data['archivos_procesados']) if data['archivos_procesados'] else 'Ninguno'}")
        if data['mensaje']:
            lines_text.append(f"Mensaje: {data['mensaje']}")
        lines_text.append("-" * 30)
        
    text_body = "\n".join(lines_text)

    # --- HTML ---
    html_lines = ["<html><body>"]
    html_lines.append("<h2>Reporte de ejecucion script de Bancos</h2>")
    html_lines.append(f"<p><strong>Fecha:</strong> {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}</p>")
    
    html_lines.append('<table border="1" cellpadding="5" style="border-collapse: collapse; font-family: Arial, sans-serif;">')
    html_lines.append('<tr style="background-color: #f2f2f2;"><th>Banco</th><th>Status</th><th>Registros</th><th>Archivos</th><th>Mensaje</th></tr>')
    
    for banco, data in global_results.items():
        archivos = "<br>".join(data['archivos_procesados']) if data['archivos_procesados'] else "Ninguno"
        html_lines.append(f"<tr><td>{banco}</td><td>{data['status']}</td><td>{data['registros_insertados']}</td><td>{archivos}</td><td>{data['mensaje']}</td></tr>")
        
    html_lines.append("</table>")
    html_lines.append("</body></html>")

    # --- Resumen de Pendientes/Fallidos ---
    try:
        conn_report = obtener_conexion()
        cursor_report = conn_report.cursor()
        cursor_report.execute(
            "SELECT banco, razon_social, fecha_reporte, estado, numero_intentos, mensaje_ultimo_error FROM bancos_reportes_pendientes WHERE estado IN ('PENDIENTE', 'FALLIDO_MAX_INTENTOS') ORDER BY banco, fecha_reporte"
        )
        reportes_especiales = cursor_report.fetchall()
        conn_report.close()

        if reportes_especiales:
            lines_text.append("\n--- RESUMEN DE REPORTES PENDIENTES/FALLIDOS ---\n")
            html_lines.append("<h3>Resumen de Reportes Pendientes/Fallidos</h3>")
            html_lines.append('<table border="1" cellpadding="5" style="border-collapse: collapse; font-family: Arial, sans-serif;">')
            html_lines.append('<tr style="background-color: #f2f2f2;"><th>Banco</th><th>Razon Social</th><th>Fecha Reporte</th><th>Estado</th><th>Intentos</th><th>Ultimo Mensaje</th></tr>')

            for r in reportes_especiales:
                # --- CORRECCIÓN AQUÍ ---
                # Verificamos si es un objeto fecha (tiene strftime) o si ya es texto
                if hasattr(r.fecha_reporte, 'strftime'):
                    f_reporte = r.fecha_reporte.strftime('%Y-%m-%d')
                else:
                    # Si ya es string, lo usamos tal cual (o cortamos los primeros 10 chars por si trae hora)
                    f_reporte = str(r.fecha_reporte)[:10]

                lines_text.append(f"  - {r.banco} | {r.razon_social} | {f_reporte} | {r.estado} | Intentos: {r.numero_intentos} | Mensaje: {r.mensaje_ultimo_error}")
                html_lines.append(f"<tr><td>{r.banco}</td><td>{r.razon_social}</td><td>{f_reporte}</td><td>{r.estado}</td><td>{r.numero_intentos}</td><td>{r.mensaje_ultimo_error}</td></tr>")
            
            html_lines.append("</table>")
    except Exception as e_report:
        print(f"Error al generar resumen de pendientes para el correo: {e_report}")
        lines_text.append(f"\nError al obtener resumen de pendientes: {e_report}\n")
        html_lines.append(f"<p style='color:red;'>Error al obtener resumen de pendientes: {e_report}</p>")
    html_body = "\n".join(html_lines)

    try:
        msg = EmailMessage()
        msg["Subject"] = "Reporte de Automatizacion de Bancos"
        msg["From"] = SMTP_USER
        msg["To"] = ALERT_RECIPIENT
        msg.set_content(text_body)
        msg.add_alternative(html_body, subtype="html")

        context = ssl.create_default_context()
        with smtplib.SMTP_SSL(SMTP_HOST, SMTP_PORT, context=context) as server:
            server.login(SMTP_USER, SMTP_PASS)
            server.send_message(msg)
        print(">>> Correo enviado exitosamente.")
    except Exception as e:
        print(f">>> Error enviando correo: {e}")

if __name__ == "__main__":
    with sync_playwright() as p:
        # User agent consistente para launch y context
        UA = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36"
        
        # Modo headless=True para ejecución en servidor/tarea programada
        browser = p.chromium.launch(
            headless=False, 
            args=[
                f"--user-agent={UA}",
                "--disable-features=Translate", 
                "--disable-blink-features=AutomationControlled",
                "--use-gl=desktop",
                "--disable-infobars",
                "--window-size=1366,768"
            ]
        )
        ejecutar_banorte(browser);
        ejecutar_getnet(browser); 
        browser.close()
    
    send_email_report()
