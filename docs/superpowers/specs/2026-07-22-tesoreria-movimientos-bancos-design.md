# Tesorería — Movimientos bancos (importación TXT Santander)

**Fecha:** 2026-07-22
**Estado:** aprobado (chat 2026-07-22)

## Objetivo

Nuevo controlador `Tesoreria` con la sección **Movimientos bancos**: tabla
consultable de los movimientos bancarios diarios y botón para subir el TXT
de movimientos de Enlace Santander, importando todos sus movimientos a BD.

## Contexto

Santander entrega diario un TXT de ancho fijo (630 chars/línea, layout
descifrado el 2026-07-22; ver memoria `layout-movimientos-santander-txt`).
Cada línea es un movimiento con cuenta, fecha (MMDDAAAA), hora, sucursal,
clave de transacción, descripción, signo +/-, importe y saldo (14 dígitos,
2 decimales implícitos), referencia, concepto y — en SPEI/transferencias —
banco/CLABE/nombre de contraparte, RFC y clave de rastreo.

## Decisiones

- **Permiso:** sin permiso por ahora (visible a cualquier usuario logueado);
  queda `TODO` en sidebar y controlador para agregarlo después.
- **Duplicados:** se ignoran. Cada movimiento genera una huella SHA1 de sus
  campos crudos (banco|cuenta|fecha|hora|sucursal|clave|signo|importe|saldo|
  referencia|concepto) con índice UNIQUE en BD. Resubir un archivo o subir
  archivos traslapados solo inserta lo nuevo y reporta `insertados` /
  `duplicados`.
- **Tabla genérica** `TG.dbo.movimientos_bancarios` con columna `banco`
  ('SANTANDER'); cuando llegue Banorte solo se agrega su parser y botón.

## Componentes

### Schema — `docs/sql/tesoreria_schema.sql`

`TG.dbo.movimientos_bancarios`: id, banco, cuenta, fecha, hora, sucursal,
clave_trans, descripcion, cargo, abono, saldo, referencia, concepto,
banco_contraparte, cuenta_contraparte, nombre_contraparte, rfc_contraparte,
clave_rastreo, descripcion_larga, huella (UNIQUE), archivo_origen,
created_by, created_at. Índice (banco, cuenta, fecha).

### Modelo — `_assets/models/MovimientosBancariosModel.php`

- `parse_santander_txt($contenido)` (estático, sin BD, testeable por CLI):
  devuelve `['movimientos' => [...], 'errores' => [...]]`. Líneas que no
  parsean (largo < 500, fecha inválida, importe no numérico) se saltan y se
  reportan con número de línea. RFC y clave de rastreo se extraen por tokens
  de la zona 384–503 (RFC = token con forma de RFC; rastreo = token largo).
- `insert_bulk($movimientos, $archivo, $usuario)`: transacción; precarga
  huellas existentes del rango de fechas del archivo y solo inserta las
  nuevas. Devuelve `[insertados, duplicados]`.
- `get_movimientos($filtros)`, `get_cuentas()`, `get_totales($filtros)`.

### Controlador — `_assets/controllers/tesoreria.php`

- `movimientos_bancos()`: vista principal. Filtros GET: `desde`/`hasta`
  (default: últimos 7 días), `cuenta`, `tipo` (cargo/abono), `q` (texto en
  concepto/contraparte/descripcion/referencia). Pasa filas, cuentas,
  totales (abonos, cargos, neto, # movimientos).
- `upload_santander()` (POST AJAX, `$_FILES['txt_santander']`): valida
  extensión .txt y tamaño (≤10 MB), normaliza encoding (Windows-1252→UTF-8
  si aplica), parsea, inserta y responde JSON:
  `{success, insertados, duplicados, errores[], fecha_min, fecha_max}`.
  El JS recarga la vista con `?desde=fecha_min&hasta=fecha_max` para que se
  vean los movimientos recién importados.

### Vista — `views/tesoreria/movimientos_bancos.html`

Tarjetas de totales (abonos verde, cargos rojo, neto, # movs), formulario
de filtros, tabla (fecha, hora, cuenta, descripción, concepto, contraparte,
cargo, abono, saldo), botón "Subir TXT Santander" con modal de upload y
resumen del resultado. JS inline en bloque `myjs`.

### Sidebar

Sección nueva `TESORERÍA` con "Movimientos bancos", entre ABASTOS y
COMERCIAL, sin `authorized()` por ahora (`{# TODO permiso #}`).

## Manejo de errores

- Archivo no legible / vacío / sin líneas válidas → JSON con `success:false`.
- Líneas inválidas no tumban el import: se acumulan en `errores[]` con línea
  y motivo, y el resto se inserta.
- El insert corre en transacción: un error de BD hace rollback completo.

## Pruebas

- Parser probado por CLI (sin BD) contra el TXT real del 20/07/2026:
  649 movimientos, totales por cuenta cuadrados contra el análisis previo.
- `php -l` de todos los archivos nuevos/modificados.
- La tabla en BD la crea el usuario ejecutando `tesoreria_schema.sql`
  (o Claude vía sqlcmd si está disponible).
