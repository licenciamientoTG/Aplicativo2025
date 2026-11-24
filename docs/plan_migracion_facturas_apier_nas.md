# Plan: descarga de facturas de proveedores en ApiER + archivos en la NAS

Fecha: 2026-11-19 · Estado: PROPUESTA aprobada en lo general, sin implementar.
Reemplaza el enfoque de `plan_archivos_facturas_nas.md` (servicio IIS + tabla `FacturaArchivos`):
aquí la NAS se usa con la misma estructura de carpetas de hoy, montada en ApiER y leída por UNC desde IIS.

## 1. Decisiones tomadas

| Tema | Decisión |
|---|---|
| Quién descarga, extrae y guarda | **ApiER** (Debian, 192.168.0.109), como *management command* de Django. Ya tiene `pdfplumber`, `PyMuPDF`, `pyodbc`, los extractores y `ImportadorFacturas`. |
| Cómo se leen los correos | **Gmail API con cuenta de servicio + delegación en todo el dominio** (método del proyecto Starlink), en vez de IMAP con contraseñas de aplicación. |
| Dónde se guardan los archivos | `\\192.168.0.15\FacturasProveedores\combustible\<proveedor>\...` (misma estructura que `attachments\<proveedor>\...`). |
| Cuenta de la NAS | Por ahora `alejandro.martinez`. Se cambia después a una cuenta de servicio editando **un solo archivo** de credenciales. |
| Cómo lee PHP | **Opción A**: IIS lee directo por UNC. Escribir desde PHP (import manual) también irá a la NAS en la fase 4. |
| Tareas programadas actuales | Se pausan **proveedor por proveedor** a medida que cada uno queda LISTO; al final se deshabilitan. |

## 2. Lo ya verificado (2026-11-19)

- ApiER → NAS: puertos 445/139 abiertos; montaje CIFS SMB 3.1.1 con tu usuario OK; crear carpetas, escribir, renombrar (atómico), leer y borrar OK; nombres con acentos y espacios OK; `nocase` necesario; ~109 MB/s. `sudo` sin contraseña.
- ApiER → Google: `oauth2.googleapis.com:443` y `gmail.googleapis.com:443` abiertos. `pip` puede descargar paquetes. Las librerías `google-api-python-client` y `google-auth` **no están** en el venv todavía.
- Gmail API con la cuenta de servicio de Starlink (`gmail.readonly`) **sí entra** a `facturascombustibles@` (7,849 mensajes) y a `jesus.olivas@` (235,436). Conteo desde 2026-11-01: aemsa 127, Tesoro 347 (facturascombustibles) y ≥500 (jesus.olivas), mcg ≥500, petrotal 18, premiergas 26, essafuel 0, enerey 0.
- Disco de ApiER: 12 GB libres (80 % usado). No se acumulan archivos locales; solo temporales.
- Raíz de la NAS: ACL con **"Todos: Control total"** → quitar al terminar (fase 5).

**Verificado 2026-11-23 — IIS → NAS:** el pool `IIS APPPOOL\TG_PHP` sale a la red como la cuenta de equipo `TOTALGAS\SERVIDORAPLICAC$`. Se creó el grupo AD `NAS_Facturas_IIS` (Global, Seguridad) con ese equipo y se le dieron permisos en `FacturasProveedores` y `combustible`; después `klist -li 0x3e7 purge` (consola elevada) y reciclar `TG_PHP`. Resultado con `test_nas_facturas.php` (ya borrado): listar, crear carpeta, escribir `.tmp` → rename, leer, `AttachmentsPath::resolver('combustible\...')` y borrar, todo OK (≈0.2 s). Hoy el grupo también puede **escribir** (sirve para la fase 4). `is_readable()` da falso negativo en UNC con esa identidad: el resolver confirma con `fopen`.

## 3. Arquitectura destino

```
cron (ApiER, cada 15 min, flock + timeout)  →  manage.py ciclo_facturas hoy
   1. Gmail API (cuenta de servicio) → mensajes por remitente/ventana → adjuntos
   2. Filtros por proveedor (prefijo, ZIP de MCG, enlaces reachcore) → escribe en NAS
        combustible/<prov>/<archivo>.pdf|xml          (cola de entrada, como hoy)
   3. Extrae con api/pdf_extractors + ImportadorFacturas (llamada directa, sin HTTP) → TG.dbo.FacturasRecibidas
   4. Archiva en NAS: procesadas / procesadasXml / errores / canceladas / notas de credito / complemento de pago
   5. Actualiza RutaArchivo/RutaXml (ruta LÓGICA relativa: combustible\<prov>\procesadas\<uuid>.pdf)
   6. Resuelve estación (port de resolver_estacion.py)
   7. Latido, registro de correos procesados, alertas
```

### 3.1 Estructura en la NAS (idéntica a la actual, sin el prefijo `attachments`)

```
FacturasProveedores\combustible\
  aemsa | enerey | essafuel | lobo | mcg | tesoro | premiergas | petrotal
    (archivos en la raíz = cola de entrada pendiente de importar, igual que hoy)
    procesadas\  procesadasXml\
    errores\  erroresXml\
    canceladas\  canceladasXml\
    notas de credito\  notas de creditoXml\
    complemento de pago\  complemento de pagoXml\
  _sombra\<prov>\...      (solo fase 1: pruebas sin tocar la BD)
```

Nombre final: `<UUID con guiones bajos>.pdf|xml` (igual que hoy). Escritura siempre `archivo.tmp` → rename.

### 3.2 Rutas en la BD

- Se guarda ruta **lógica relativa**: `combustible\<prov>\procesadas\<uuid>.pdf`. Cada sistema la resuelve a su base:
  PHP → `\\192.168.0.15\FacturasProveedores\`, ApiER → `/mnt/nas/FacturasProveedores/`.
- Un **resolver único en PHP** (extiende `AttachmentsPath`) reemplaza las ~11 copias de la regla "si no empieza con `C:\` es relativa":
  1. `combustible\...` → UNC de la NAS.
  2. `attachments\<prov>\...` (relativa vieja) o `C:\Software\...\attachments\<prov>\...` (absoluta vieja) → primero intenta la NAS (`combustible\<prov>\...`) y, si no existe el archivo, la ruta vieja del servidor. Esa doble lectura es temporal.
  3. La validación de seguridad cambia de prefijo `C:\Software\TareasProgramadas\Facturas_proveedores\` a "dentro de la base NAS o de la base vieja".
- **Baseline medido en TG el 2026-11-21** (`FacturasRecibidas`, 23,586 filas; columnas `RutaArchivo` y `RutaXml` nvarchar(1000)): 14,326 con ruta absoluta `C:\Software\TareasProgramadas\Facturas_proveedores\correoFacturas\attachments\...`; ~7,125 relativas `attachments\<prov>\...` (mcg 3,158, tesoro 2,001, premiergas 947, petrotal 608, aemsa 393, essafuel 13, enerey 5); 2,135 sin ruta; 0 en `combustible\`. `OrigenImportacion`: 23,213 nulos y 373 `aplicativo`. El UPDATE masivo de la fase 3 debe cubrir los dos formatos y dejar las 2,135 vacías como están. Otras tablas con rutas (a revisar en fase 0, sin tocar por ahora): `FacturaEncabezado.ruta_xml`, `XmlCre.RutaXml`, `ProveedorDocumentosPortal.ruta_relativa`.
- Sitios a cambiar (leen/escriben por ruta): `payment.php` (≈4), `station_portal.php`, `supply.php`, `it.php`, `FuelReceptionInvoiceModel`, `FuelReceptionScheduleModel`, `PaymentAccountingGroupsModel`, `PaymentRequestsModel`, `PetrotalReconciliationModel`, `XmlCreModel`, `SatFacturasModel`, `FacturasRecibidasModel`. Primero se hace un inventario exacto con `grep` de `RutaArchivo|RutaXml|correoFacturas|Facturas_proveedores`.

### 3.3 Estructura del código en ApiER

```
api/facturas_correo/
  management/commands/ciclo_facturas.py      # hoy | ayer | rango DESDE HASTA [--proveedor X] [--dry-run] [--sombra]
  proveedores.py        # config de ACCOUNTS: buzón, remitente(s), prefijos, carpeta, aceptar_todo, modo
  gmail_cliente.py      # cuenta de servicio + delegación (código base: descargar_factura_starlink.py)
  descarga.py           # filtros por prefijo, ZIP MCG, enlaces reachcore, reintentos con backoff
  almacen.py            # raíz NAS, escritura atómica, nombre único, mover a subcarpeta, XML espejo
  importar.py           # llama a extraer_datos_pdf + ImportadorFacturas (sin HTTP)
  estacion.py           # port de resolver_estacion.py
  estado.py             # flock, latido, último ciclo ok, recuperación de días caídos
  alertas.py            # correo de montos en $0, errores acumulados, proveedor sin facturas
```

Tablas nuevas en TG (SQL a correr por ti; se entrega como `tools/` o `docs/` antes de la fase 0):

1. **`TG.dbo.FacturasCorreoMensajes`** — un renglón por correo de Gmail revisado. Sirve para no procesar dos veces un correo, para auditar "¿llegó el correo de tal proveedor?" (incluye los que no traían factura: complementos, omitidos por prefijo, con error) y para recuperar sin depender de ventanas de fecha.

   | Columna | Tipo | Nota |
   |---|---|---|
   | Id | bigint identity PK | |
   | Buzon | varchar(100) | `facturascombustibles@…` / `jesus.olivas@…` |
   | GmailId | varchar(32) | id del mensaje; **UNIQUE (Buzon, GmailId)** |
   | Proveedor | varchar(30) | aemsa, mcg, tesoro… |
   | Remitente / Asunto | varchar(150) / varchar(300) | |
   | FechaCorreo | datetime2 | `internalDate` de Gmail |
   | Adjuntos | int | PDF/XML aceptados |
   | Estado | varchar(25) | descargado · sin_adjunto_objetivo · omitido_prefijo · error |
   | Detalle | varchar(500) | motivo del omitido o del error |
   | Uuid | varchar(40) NULL | si el correo trae un solo CFDI |
   | Motor | varchar(10) | `sombra` o `apier` |
   | Creado | datetime2 default sysdatetime() | índice (Proveedor, FechaCorreo) |

2. **`TG.dbo.FacturasCorreoProveedorEstado`** — un renglón por proveedor (≈8). Es el "semáforo": qué motor lo atiende y si ya está LISTO. Lo lee el panel de monitoreo de `/payment`.

   | Columna | Tipo | Nota |
   |---|---|---|
   | Proveedor | varchar(30) PK | |
   | Motor | varchar(10) | `python` · `sombra` · `apier` |
   | Activo | bit | lobo = 0 |
   | CutoverEn | datetime2 NULL | cuándo pasó a `apier` |
   | ListoEn | datetime2 NULL | checklist §5 completo |
   | HistoricoMigradoEn | datetime2 NULL | fase 3 terminada |
   | UltimoCicloOk / UltimoCorreo / UltimaFactura | datetime2 NULL | latidos por proveedor |
   | PendientesCola / ErroresCola | int | archivos en la raíz / en `errores\` |
   | Notas | varchar(500) | |

Además, **temporal** en la fase 3: `FacturasRecibidas_rutas_bkp(Id, RutaArchivo, RutaXml, Fecha)` como respaldo antes del UPDATE masivo de rutas. No se necesita ninguna otra tabla: las rutas de los archivos siguen viviendo en `FacturasRecibidas`.

Consultas a Gmail con **segundos epoch** (`after:<epoch> before:<epoch>`), no con fechas, para evitar el desfase de zona horaria; ventana con traslape de 2 días y dedupe por `gmail_id`.

### 3.4 Programación (Linux)

```
*/15 * * * *  desarrollador  flock -n /var/lock/ciclo_facturas.lock timeout 25m  <venv>/python manage.py ciclo_facturas hoy   >> /var/log/apier/ciclo.log 2>&1
35 6 * * *    desarrollador  flock -n /var/lock/ciclo_facturas.lock timeout 60m  <venv>/python manage.py ciclo_facturas ayer  >> /var/log/apier/ciclo.log 2>&1
```
`flock` evita solapes y `timeout` mata un proceso colgado (los cuelgues de 17 h y 27 h del Task Scheduler ya no pueden repetirse). `logrotate` para `/var/log/apier`. Montaje en `/etc/fstab` con `nofail,_netdev,vers=3.1.1,nocase,uid=<usuario del cron>,file_mode=0664,dir_mode=0775,credentials=/etc/.smbcredentials_facturas`.

### 3.5 Secretos

- Llave de la cuenta de servicio → `/etc/apier/gmail_sa.json` (root/desarrollador, 600), **fuera de git y del webroot**. Alcance únicamente `gmail.readonly`.
- Credenciales NAS → `/etc/.smbcredentials_facturas` (600). Contraseñas SQL → archivo de entorno de ApiER (600).
- Riesgo a tener presente: esa llave puede leer **cualquier buzón del dominio**. El proyecto de Google es `gen-lang-client-…` (creado por AI Studio); a mediano plazo conviene uno propio de la empresa.

## 4. Fases

### Fase 0A — Repositorio y despliegue de ApiER por GitHub (antes de escribir código nuevo)
Estado encontrado el 2026-11-20:
- Local `Desktop\codigo\ApiER` es repo (`github.com/amartinezTG/ApiER2`, rama `master`, 65 commits, último push 2026-10-29). El servidor `/var/www/html/ApiER` **no** es repo (su `.git` está vacío).
- El repo de GitHub es **PÚBLICO** y versiona: `.vscode/sftp.json` (con campo `password`, desde el primer commit 2025-06-06), `env/` (1,489 archivos del venv), `__pycache__/`, y líneas con credenciales literales en `myproject/settings.py`, `api/XmlCre/views.py` y `api/modelos/Volumetricos_Diarios.py`. No hay `.gitignore`.
- El código del servidor coincide con el local salvo `api/modelos/cotizaciones_estaciones.py` (distinto) y dos `__init__.py` vacíos que solo existen en local.

Pasos:
1. Poner el repo **privado** y **rotar** lo expuesto (contraseña SFTP del 109, contraseñas SQL, `SECRET_KEY` si es literal). Reescribir la historia no sustituye a la rotación.
2. Resolver la diferencia de `cotizaciones_estaciones.py` (¿cuál es la vigente?) antes del primer despliegue.
3. Limpiar: `.gitignore` (`env/`, `__pycache__/`, `*.pyc`, `.vscode/`, `db.sqlite3`, `*.env`, `staticfiles/`), `git rm -r --cached` de lo que no va, secretos a un archivo de entorno del servidor (`/etc/apier/apier.env`, 600) leído por `settings.py`, y `requirements.txt` generado del venv del servidor.
4. Servidor: llave de despliegue **de solo lectura** propia para `ApiER2` (las llaves de despliegue de GitHub son por repo; la actual `totalgas-aplicativo-deploy` es del aplicativo) y clonar en una carpeta nueva junto a la actual; copiar el venv `env/` y el archivo de entorno; probar; intercambiar carpetas. El servidor solo hace `git pull --ff-only`, nunca commits (su `user.name` global es de otra persona).
5. Script `deploy.sh` en el repo: `git pull --ff-only` → `pip install -r requirements.txt` si cambió → `python manage.py check` → recarga de Apache (`sudo` ya funciona sin contraseña).
6. Ramas: `master` = lo que corre en producción; el trabajo de `facturas_correo` en una rama aparte, con tag antes de cada despliegue para poder volver atrás.
7. Desactivar `uploadOnSave` del SFTP de VS Code (sube cada guardado directo a producción).

### Fase 0 — Preparación (sin impacto en producción)
1. Inventario de tareas `sya_*` del servidor 192.168.0.3 (por CIM/DCOM, con tu autorización) y de columnas `Ruta*` en TG.
2. ApiER: `pip install google-api-python-client google-auth` en el venv; carpeta `/var/log/apier`; llave en `/etc/apier/`; montaje permanente de la NAS en `/etc/fstab`.
3. SQL: las dos tablas nuevas + `OrigenImportacion='correo'` desde ApiER.
4. PHP: resolver único con doble lectura, **sin cambiar comportamiento**. Probar lectura desde IIS sobre `combustible\` con la identidad real de `TG_PHP` (tipo `test_nas.php`).
5. Parche chico en los scripts de Python del servidor 0.3: lista `PROVEEDORES_PAUSADOS` (por variable de entorno o archivo) para poder apagar un proveedor sin tocar a los demás. **Es obligatoria**: si ambos motores corren sobre el mismo proveedor, `completar_factura_existente()` del Python reescribe `RutaArchivo` de vuelta a su ruta vieja.
6. Línea base: conteos por proveedor/día de `FacturasRecibidas` y de archivos, para comparar después.

**Inventario 2026-11-23 (0.3, 31 tareas `sya_*`).** Las que tocan esta migración (todas como `alejandro.martinez`, `C:\python31210\python.exe`, dir `C:\Software\TareasProgramadas\Facturas_proveedores\correoFacturas`):

| Tarea | Estado | Disparador | Acción | Última / resultado |
|---|---|---|---|---|
| `sya_descargar_facturas_proveedor` | Activa | cada 15 min desde 08:28 | `run_ciclo.py hoy` | 2026-11-23 14:28 · 0 |
| `sya_descargar_facturas_proveedor_respaldo_ayer` | Activa | diaria 06:35 | `run_ciclo.py ayer` | 2026-11-23 06:35 · 0 |
| `sya_descargar_facturas_proveedor_importar` | Deshabilitada | diaria 06:35 | `main.py` | 2026-09-11 · 0 |

Ninguna otra tarea usa `correoFacturas` ni `attachments`. Versiones en el servidor: `run_ciclo.py` y `CorreoFactruras.py` del 2026-11-05, `main.py` del 2026-10-27 → **no** tienen el parche de pausados (local 2026-11-21). `config.py` igual que el local.

**Cambio de estrategia (2026-11-23, decisión del usuario):** en vez de pasar proveedor por proveedor, se hace el **cambio total**: sombra de todos → comparación → deshabilitar las dos tareas `sya_descargar_facturas_*` del 0.3 y activar el cron de ApiER el mismo día. El parche `PROVEEDORES_PAUSADOS` ya no hace falta desplegarlo.

### Estado del código en ApiER (rama `facturas-correo`, 2026-11-23)

`api/facturas_correo/` + comando `python manage.py ciclo_facturas`:

| Archivo | Qué hace |
|---|---|
| `proveedores.py` | Catálogo (buzón, remitente, prefijos), copia de `ACCOUNTS`; **petrotal ahora acepta `PET`** |
| `descarga.py` | Filtros de `CorreoFactruras.py` (prefijo/"factura", aceptar todo, ZIP MCG, enlaces reachcore) |
| `gmail_cliente.py` | Gmail API (cuenta de servicio + delegación), mensajes en `raw`, ventanas en epoch |
| `almacen.py` | NAS: verifica montaje, escritura `.tmp`→rename, `mover()` por UUID, XML hermano, ruta lógica `combustible\...` |
| `importar.py` | Cuerpo de `/api/importar_factura_pdf/` sacado a función; la vista y el comando usan el mismo código |
| `registro.py` | `FacturasCorreoMensajes` (dedupe por GmailId, reintenta los de `error`) y semáforo/latidos |
| `estacion.py` | Port de `resolver_estacion.py` |
| `ciclo.py` | Orquestador: motor `sombra` (a `_sombra\<prov>\` + `_manifest.csv`, sin BD) y `apier` (cola → importar → archivar → ruta lógica → `OrigenImportacion='correo'` → estación → alerta $0); `comparar` |
| `tests.py` | 24 pruebas sin red ni BD (`python -m unittest api.facturas_correo.tests`) |

Diferencias deliberadas contra el script viejo: no se reapunta `RutaArchivo` de una factura duplicada que ya tiene ruta (solo se completan huecos: XML o ruta vacía); no existe el "reprocesar si el archivo falta en disco" (el 109 no ve el disco del 0.3).

**Prueba real `--dry-run` desde la PC (2026-11-23, 16–23 nov, todos):** mcg 480 correos/960 archivos, tesoro 426+142, aemsa 52, premiergas 18, petrotal 18, enerey y essafuel 0. Omitidos solo comprobantes de pago de petrotal (`TESORO`, `PREMIER`, `ESSA`).
**Hallazgo:** Petrotal mandó los folios 31703–31706 como `PET 3170x.pdf`; el filtro `F_` (Python y port) los tiraba. 31704, 31705 y 31706 **no están en FacturasRecibidas** (31703 entró a mano).

**Despliegue en el 109 (pendiente):**
1. `sudo install -m 640 -o root -g www-data credenciales.json /etc/apier/gmail_sa.json` (la llave de Starlink, la misma cuenta de servicio).
2. Merge de `facturas-correo` a `master`, push y `./deploy.sh`.
3. Prueba: `sudo -u www-data ./env/bin/python manage.py ciclo_facturas rango 2026-11-16 2026-11-23 --proveedor petrotal --sombra` y luego `... comparar 2026-11-17 2026-11-22`.
4. Sombra completa: `... rango 2026-11-01 2026-11-23 --sombra` (todos) y `comparar 2026-11-03 2026-11-21`.
5. Cron como `www-data` (el montaje es `uid=www-data`):
```
*/15 * * * *  www-data  cd /var/www/html/ApiER && flock -n /var/lock/ciclo_facturas.lock timeout 25m ./env/bin/python manage.py ciclo_facturas hoy  >> /var/log/apier/ciclo.log 2>&1
35 6 * * *    www-data  cd /var/www/html/ApiER && flock -w 1800 /var/lock/ciclo_facturas.lock timeout 60m ./env/bin/python manage.py ciclo_facturas ayer >> /var/log/apier/ciclo.log 2>&1
```
   (en sombra se agrega `--sombra`; `/var/log/apier` debe ser escribible por `www-data`).

### Cambio total HECHO (2026-11-23, ~17:15 Juárez)

- ApiER `master` = `1a0380e`, desplegado con `./deploy.sh`; 29 tests OK en el servidor. `/etc/apier/gmail_sa.json` (640 root:www-data).
- Sombra 2026-11-01..23 de todos + `comparar 2026-11-03 2026-11-21`: **todos OK, `faltan_en_sombra = 0`**.

  | Proveedor | BD | Sombra | Sombra no en BD |
  |---|---|---|---|
  | aemsa | 63 | 131 | 0 |
  | tesoro | 349 | 406 | 0 |
  | mcg | 453 | 679 | 0 |
  | petrotal | 86 | 97 | 0 (+ PET 31705/31706 del 22) |
  | premiergas | 111 | 129 | 1 (`001-FE-00044611 GASOMEX EJERCITO MAGNA BOL 1261495`, 2026-11-03) |
  | essafuel / enerey | 0 | 0 | 0 |

- 0.3: `sya_descargar_facturas_proveedor` y `_respaldo_ayer` **deshabilitadas** (CIM/DCOM); `_importar` ya lo estaba. Para volver atrás: habilitarlas y poner `Motor='python'`.
- `FacturasCorreoProveedorEstado`: los 7 activos en `Motor='apier'` (lobo sigue `python`, inactivo).
- Primera corrida en vivo `hoy`: PET 31705 (Id 78002) y PET 31706 (Id 78003) importadas con XML; lo demás `duplicada`. **PET 31704 es PDF escaneado (sin texto)** → `combustible/petrotal/errores/` + `erroresXml/`: capturar a mano.
- Cron instalado en `/etc/cron.d/apier_facturas_correo` (el servidor está en America/Mexico_City, por eso el respaldo es `35 7`). Lock en `/run/lock/ciclo_facturas.lock`. Log `/var/log/apier/ciclo.log`.
- Alerta de $0 (la única que mandaba el Python): ApiER `8ed6e64` la manda por el **relay SMTP de Google** (`smtp-relay.gmail.com:587` STARTTLS, por IP, sin contraseña), igual que `send_mail` del aplicativo; la contraseña de aplicación de `main.py` ya no sirve. Destinatarios en `/etc/apier/facturas_correo.env` (`FACTURAS_ALERTA_A`, 640 root:www-data), que el cron carga. Prueba enviada OK.
- `/etc/logrotate.d/apier` (semanal, 8 copias, comprimido). Carpeta `combustible/_sombra` borrada.

### Fase 3 y 4 HECHAS (2026-11-23 noche)

- robocopy 0.3 → NAS de los 8 proveedores (~85 000 archivos, ~6 GB), 0 errores, sin sobrescribir lo que ApiER ya había dejado.
- Verificación: 21 553 de 21 575 filas con archivo en la NAS y mismo tamaño que en el 0.3; los 13 860 XML con SHA-256 idéntico.
- Fase 4 en `main` `e7871427` (desplegada por el usuario): PHP escribe en `combustible\...` (respaldo: carpeta vieja si la NAS no responde), `.tmp` + rename, UUID validado; los lectores que usaban `file_exists(RutaArchivo)` pasan por `AttachmentsPath::resolver()`.
- UPDATE lote `fase3_20261123_1824`: 21 553 `RutaArchivo` y 6 378 `RutaXml` → `combustible\...`; respaldo en `dbo.FacturasRecibidas_rutas_bkp`. Muestra de 300 rutas: todas abren en la NAS.
  Revertir: `UPDATE f SET RutaArchivo=b.RutaArchivo, RutaXml=b.RutaXml FROM dbo.FacturasRecibidas f JOIN dbo.FacturasRecibidas_rutas_bkp b ON b.Id=f.Id AND b.Lote='fase3_20261123_1824'`.
- Quedan con ruta vieja 22: 12 AEMSA de julio 2026 sin archivo en ningún lado (Ids 60792, 60793, 61202, 61212, 61213, 61259, 61266, 62551, 64966, 64967, 65298, 66869) y 10 MCG del 2026-11-23 cuya copia en la NAS es la de ApiER (mismos datos, otros bytes); se sirven igual.
- La carpeta `attachments` del 0.3 se queda como respaldo de lectura. Retirarla (y la lectura doble del resolver) es parte de la fase 5.
- `tools/add_origen_importacion_facturarecibidas.php` filtra rutas `C:\...`: ya no encuentra filas, no volver a correrlo.

### Fase 1 — Descarga en modo sombra (por proveedor, sin tocar la BD)
- `ciclo_facturas --sombra`: lee Gmail, filtra, escribe en `combustible\_sombra\<prov>\` y **no** importa ni archiva.
- Comparación contra lo que ya bajó Python en el mismo rango: nº de correos, de archivos y **UUIDs** idénticos.
- Criterio de salida: 7 días seguidos (o un rango histórico de 30 días) con cobertura 100 % y sin archivos omitidos que Python sí tomó. Aquí se aclaran Essafuel y Enerey (0 correos en noviembre: ¿cambió el remitente o no facturan?).

### Fase 2 — Entrada en vivo, proveedor por proveedor (el "semáforo")
Orden por riesgo creciente: **petrotal → premiergas → aemsa → enerey → essafuel → tesoro → mcg**.

Para cada proveedor:
1. Pausar el proveedor en Python (`PROVEEDORES_PAUSADOS`) — lo copias al servidor 0.3.
2. Activarlo en ApiER (`motor='apier'`) y correr `rango` de los últimos 5 días para cubrir el traslape (el dedupe por UUID y `gmail_id` evita duplicados).
3. Validar el checklist (§5). Cuando todo pase, marcar **LISTO** en la tabla y en el panel.
4. Observar 3 días; si algo falla, se regresa el proveedor a Python (reversible: los archivos ya escritos en la NAS no estorban).

### Fase 3 — Migración del histórico (~65 000 archivos, 4.85 GB)
1. `robocopy` desde 192.168.0.3: `…\correoFacturas\attachments\<prov>` → `\\192.168.0.15\FacturasProveedores\combustible\<prov>` con `/E /COPY:DAT /R:2 /W:5 /MT:8 /LOG`. Un proveedor a la vez, empezando por los chicos; mcg (39 k) y tesoro (8.5 k) al final.
2. Verificación por proveedor: conteo, tamaño total, y SHA-256 de todos los XML y de una muestra del 1 % de PDF.
3. Respaldo de rutas (`FacturasRecibidas_rutas_bkp`: Id, RutaArchivo, RutaXml) y luego `UPDATE` por lotes de relativas y absolutas viejas → `combustible\<prov>\...`. Marcar el proveedor como "histórico migrado".
4. Las ~14 600 rutas absolutas `C:\...` (cargadas a mano) se tratan igual, cada una según su proveedor.
5. La carpeta original se deja **solo lectura 30 días** y luego se archiva.

### Fase 4 — Escrituras de PHP a la NAS
`Payment::save_imported_pdf` y `FuelReceptionInvoiceModel::guardarArchivos` escriben en `combustible\...` (requiere permiso de modificación de la identidad de `TG_PHP` sobre `combustible`). Se quita la doble lectura del resolver cuando todos los proveedores estén en "histórico migrado".

### Fase 5 — Cierre
- Deshabilitar `sya_descargar_facturas_proveedor` y `sya_descargar_facturas_proveedor_respaldo_ayer` (la `_importar` ya está deshabilitada).
- Pasar a cuenta de servicio de la NAS; quitar "Todos: Control total"; cambiar las contraseñas que se pegaron en el chat (NAS y SFTP de ApiER).
- Retirar las contraseñas en claro de `CorreoFactruras.py`, `main.py` y `config.py`.
- Alertas activas: latido > 1 h, proveedor sin correos en N días, `errores/` con archivos, facturas con total $0.

## 5. Checklist por proveedor (marcar LISTO)

| Proveedor | Gmail → descarga | Escritura NAS | Insert BD | Lectura PHP (UNC) | Estación | Python pausado | Histórico migrado | **LISTO** |
|---|---|---|---|---|---|---|---|---|
| petrotal | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ |
| premiergas | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ |
| aemsa | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ |
| enerey | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ |
| essafuel | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ |
| tesoro | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ |
| mcg | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ |
| lobo | inactivo (sin correos en 200+ días) | | | | | | ☐ | — |

Cómo se confirma cada columna:
- **Gmail → descarga:** mismos correos y UUIDs que Python en el rango de comparación.
- **Escritura NAS:** el PDF y el XML existen en `procesadas\` y `procesadasXml\` con el nombre-UUID.
- **Insert BD:** fila en `FacturasRecibidas` con conceptos y `RutaArchivo` = `combustible\...`; sin crecimiento en `errores\`.
- **Lectura PHP:** se abre el PDF desde Búsqueda de facturas (`/payment/import_invoices`) y desde Mis Recepciones.
- **Estación:** `EstacionCodgas` resuelto en las nuevas.
- **Python pausado / Histórico migrado:** pasos 2.1 y 3.3.

## 6. Riesgos y mitigaciones

| Riesgo | Mitigación |
|---|---|
| Dos motores sobre el mismo proveedor reescriben rutas | Lista `PROVEEDORES_PAUSADOS` (fase 0.5) y un motor por proveedor en `FacturasCorreoProveedorEstado`. |
| NAS caída | Los pendientes quedan en la cola y se reintentan en el siguiente ciclo; alerta si falla el montaje. `nofail` evita colgar el arranque. |
| Montaje CIFS vacío (sin montar) y que ApiER escriba en el disco local | El comando verifica que el montaje esté activo (`os.path.ismount`) antes de escribir. |
| Archivo a medias leído por PHP | Escritura `.tmp` + rename. |
| Mayúsculas/minúsculas | Montaje con `nocase`; nombres normalizados a UUID. |
| Disco de ApiER (12 GB) | Sin staging local; logs rotados. |
| Llave de delegación muy poderosa | Permiso 600, solo `gmail.readonly`, fuera de git; revisar proyecto Google propio. |
| Cambios de formato de proveedores (como aemsa `F_`→`S100`) | Registrar y alertar cualquier PDF/XML omitido por el filtro de prefijo. |
| UPDATE masivo de rutas | Tabla de respaldo, por lotes y por proveedor; reversible. |

## 7. Deploy

- ApiER: lo subes por SFTP (o git) como hoy. Un management command nuevo no toca las vistas existentes.
- Scripts de Python del 0.3: solo el parche de `PROVEEDORES_PAUSADOS` (fase 0.5), copiado a mano.
- AplicativoPhp: resolver y cambios de PHP por GitHub como siempre.
- Cada entrega termina con la lista de archivos tocados y el SQL a correr.

## 8. Pendientes de decisión

1. ¿Lobo se da de baja definitivamente (sin correos en 200+ días)?
2. ¿Se mantiene Tesoro leído en los dos buzones, o con Gmail API se lee solo uno?
3. Fecha objetivo del cambio a cuenta de servicio de la NAS.
