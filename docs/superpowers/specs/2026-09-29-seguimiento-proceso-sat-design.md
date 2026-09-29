# Seguimiento del proceso de descarga masiva SAT — diseño

Fecha: 2026-09-29 · Vista: `/it/llamadas_sat` (AplicativoPhp) · Backend: ApiTotal (`/api/facturas/…`)

## Objetivo

Desde el aplicativo, ver de un vistazo si el proceso de descarga masiva del SAT
está sano de punta a punta y poder ejecutar a mano cada paso cuando lo
automático no alcanza o se atora.

Motivación: la importación a la BD (`process_xml`) no corre desde el
2026-07-01 (última factura en `TGV2.dbo.Facturas`/`FacturasRecibidas` =
2026-06-30; ~24,470 XML emitidos y ~2,911 recibidos esperando) y nadie se
enteró porque no hay dónde verlo.

## Alcance

- **Sí:** panel de salud global del pipeline (4 etapas con semáforo) y botones
  para Descargar (por RFC), Descomprimir e Importar.
- **No:** rastreo por petición / por paquete / por UUID (decisión del usuario:
  solo salud global, sin tablas nuevas de seguimiento). Las tareas programadas
  del servidor siguen igual; no se reemplazan. Investigar y corregir la causa
  de fondo del paro de julio y volver a programar la importación automática
  queda fuera (se aborda cuando el botón Importar muestre el error real).

## Proceso actual (referencia)

| Etapa | Endpoint ApiTotal | Tarea programada (192.168.0.3) | Carpeta / tabla |
|---|---|---|---|
| Crear peticiones | `create_double_petition` | `sya_peticion_sat` 01:00 | `TGV2.dbo.FacturasPeticiones` (estado 1) |
| Descargar ZIP | `consult_pending` (≤5 pendientes por RFC) | `sya_petition_download_sat_poll` 01:15 y cada 4 h | `C:\tareasprogramadas\invoice\invoice_new_{emited,received}\{packageId}.zip` |
| Descomprimir | `process_downloaded_packages` | `sya_peticion_decompress` 01:45 | `…\invoice\cfdis_{emited,received}\{uuid}.xml` (el ZIP se borra) |
| Importar | `process_xml` (≤400 por carpeta por llamada) | **ninguna** | `Facturas` (emitidas) / `FacturasRecibidas` (recibidas); el XML se mueve a `…\invoice\cfdis_processed\` |

El botón **Verificar** actual (`consult_status`) solo consulta al SAT; no descarga.

## Diseño

### 1. Panel "Estado del proceso"

Arriba de la tabla de pendientes, cuatro tarjetas en fila (en móvil, apiladas),
cada una con semáforo verde/amarillo/rojo y su botón de acción:

| # | Tarjeta | Datos | Rojo si | Amarillo si | Acción |
|---|---|---|---|---|---|
| 1 | Peticiones SAT | pendientes (estado 1) y fecha de la más vieja | la más vieja > 3 días | hay pendientes > 1 día | — (Descargar va por fila) |
| 2 | ZIP por descomprimir | cantidad emitidas/recibidas, fecha del más viejo | más viejo > 1 día | hay ZIP | **Descomprimir** |
| 3 | XML por importar | cantidad emitidas/recibidas, fecha del más viejo, cantidad en `cfdis_error` | más viejo > 1 día | hay XML o hay en error | **Importar** |
| 4 | Importado a la BD | fecha del CFDI más reciente entre los últimos 5,000 `Id` de `Facturas` y de `FacturasRecibidas` | > 3 días | > 1 día | — |

- Umbrales como constantes del controlador (`SAT_UMBRAL_*_DIAS`).
- Tarjeta 4 usa `MAX(Fecha)` sobre los últimos 5,000 Id (orden de inserción) en
  vez de toda la tabla: evita escanear 1.3M filas y refleja lo último importado.
- Botón "Actualizar" del panel; el panel también se refresca solo después de
  cualquier acción.

### 2. Acciones

| Botón | Ubicación | Endpoint AplicativoPhp → ApiTotal | Comportamiento |
|---|---|---|---|
| **Descargar** | cada fila de pendientes, junto a Verificar y Marcar error | `POST /it/llamadas_sat_descargar` → `consult_pending {razon_social}` | Revisa hasta 5 pendientes del RFC; las terminadas se descargan y pasan a estado 0. Muestra el resultado por petición (`finished` / `in_progress` / `download_failed` / errores con su explicación SAT). Refresca tabla y panel. |
| **Descomprimir** | tarjeta 2 | `POST /it/llamadas_sat_descomprimir` → `process_downloaded_packages` | Descomprime todos los ZIP. Resume procesados/fallidos y lista los fallidos con su mensaje. |
| **Importar** | tarjeta 3 | `POST /it/llamadas_sat_importar_lote` → `process_xml` | Un lote = una llamada (≤400 emitidas + ≤400 recibidas). El navegador repite lotes con barra de progreso ("importados X · quedan Y") y botón **Detener**. Para solo cuando: quedan 0, el usuario detiene, un lote no avanza (0 importados y 0 movidos) o hay error HTTP. Al final muestra totales y los errores (agrupados por mensaje, con ejemplos de archivo). |

Todas piden confirmación (SweetAlert, patrón actual de la vista), usan
`SAT_USERS` como permiso y reportan errores con los helpers existentes
(`mostrarError` / `errorAjax`).

### 3. Cambios en ApiTotal (subida manual por FTP)

1. **`GET pipeline_status`** (nuevo, solo lectura): por cada carpeta
   (`invoice_new_emited`, `invoice_new_received`, `cfdis_emited`,
   `cfdis_received`, `cfdis_error`) regresa `{existe, cantidad, mas_viejo,
   mas_reciente}`. Se recorre con `DirectoryIterator` (sin cargar el contenido).
2. **`process_xml` informa lo que falta:** agrega al `summary`
   `remaining_emited` y `remaining_received` (hoy `remaining_files` se
   sobrescribe entre carpetas y no se devuelve).
3. **Los XML que fallan salen de la cola:** en `procesarArchivosXML`, un archivo
   que falla (XML ilegible, excepción al insertar) se mueve a
   `C:\tareasprogramadas\invoice\cfdis_error\{emited|received}\` y se registra en
   `ApiFailures` (`Method = process_xml`, archivo, tipo y mensaje). Motivo: hoy
   un XML que falla se queda en la carpeta y `glob` lo vuelve a tomar primero en
   cada lote; con ≥400 fallidos permanentes la importación queda atorada para
   siempre en el mismo lote (posible causa del paro de julio). Los de
   `cfdis_error` se pueden regresar a mano a la carpeta de entrada tras
   corregir la causa.

Mientras ApiTotal no se suba, `llamadas_sat_estado` detecta el 404 de
`pipeline_status` y las tarjetas 2 y 3 muestran "Falta subir ApiTotal
(pipeline_status)"; las tarjetas 1 y 4 (de BD) funcionan igual. Importar
funciona con el `process_xml` viejo, pero sin `remaining_*` el progreso se
calcula con el conteo previo de la tarjeta 3 y la parada por "lote sin avance".

### 4. Cambios en AplicativoPhp

- `_assets/controllers/it.php`:
  - `llamadas_sat_estado()` (GET, JSON): combina `pipeline_status` con datos de
    BD de `SatPeticionesModel` y calcula el semáforo de cada tarjeta.
  - `llamadas_sat_descargar()`, `llamadas_sat_descomprimir()`,
    `llamadas_sat_importar_lote()` (POST, JSON): proxys a ApiTotal vía
    `_sat_api()` con `set_time_limit(0)` y timeout de curl de 600 s.
- `_assets/models/SatPeticionesModel.php`: `get_resumen_pendientes()` y
  `get_ultima_importacion()`.
- `views/it/llamadas_sat.html`: panel de 4 tarjetas, botón Descargar por fila,
  modal/overlay de progreso de Importar. Estilo Bootstrap 5 y feather icons como
  el resto de la vista.

## Riesgos

- **Timeouts de IIS/FastCGI en producción:** un lote de `process_xml` (hasta 800
  XML) puede tardar minutos. En local no aplica; en producción el
  `activityTimeout` de FastCGI podría cortar la petición del aplicativo aunque
  ApiTotal siga trabajando. Mitigación: si ocurre, bajar el lote (parámetro
  `limit` en `process_xml`) — se valida en la primera prueba.
- **Concurrencia con las tareas programadas:** seguro — `process_xml` salta
  UUID ya existentes y descomprimir/descargar dos veces solo repite trabajo.
- **Carpetas grandes:** contar 24k archivos con `DirectoryIterator` toma ~1 s;
  aceptable para un panel que se refresca a demanda.

## Verificación

No hay framework de pruebas. Se verifica:
1. Script CLI (scratchpad) que instancia `SatPeticionesModel` y llama los métodos
   nuevos contra la BD real (solo lectura).
2. `curl` a `pipeline_status` una vez subido ApiTotal, comparando conteos con
   `Get-ChildItem` en el servidor.
3. En navegador: panel con los datos de hoy (tarjetas 3 y 4 en rojo), Descargar
   sobre la petición 16158 (ya `finished` en el SAT) → pasa a estado 0 y aparece
   su ZIP en la tarjeta 2, Descomprimir → ZIP a XML, Importar un lote → sube la
   fecha de la tarjeta 4 o aparece el error real del paro de julio.
