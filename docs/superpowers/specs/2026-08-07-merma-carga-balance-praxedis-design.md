# Carga manual de "Balance de Producto" (PDF) para Praxedis — Diseño

## Contexto

El módulo de Análisis de Merma Diaria (`/merma/...`) llena `TG.dbo.merma_diaria`
sincronizando contra ApiER (`/api/inventarios_turnos/`), que a su vez consulta
cada estación por linked server. Praxedis (codgas 40, servidor `192.168.40.101`,
BD `E10702`) sí tiene conexión SQL normal y aparece en otros módulos (ventas,
responsables), pero **no está incluida en el sync de ApiER para merma** — por
eso hoy no tiene datos en `merma_diaria` y aparece "sin conectar" en ese módulo
específico.

Para Praxedis, el corte diario se obtiene manualmente como un PDF llamado
"Balance de Producto" (ejemplo real adjunto: fecha 2026-08-06). Este documento
propone cómo cargar ese PDF para llenar `merma_diaria` con el mismo esquema y
las mismas reglas de cálculo (recalc_contable, libro amarillo) que usan las
demás estaciones.

## Formato del PDF de origen

Encabezado: `Fecha`, `Fecha Hasta` (iguales — reporte tipo "Diario"), `Estación`
(`PRAXEDIS`), `Tipo` (`Diario`).

Tres secciones por producto — `87 Octanos`, `91 Octanos`, `Diesel` — cada una
con una tabla de una fila por fecha (aquí siempre 1 fila, más una fila `TOTAL`)
y columnas: `Fecha, Inv Inicial, Compras Lec, Compras Doc, Ventas, Inv Lec,
Inv Doc, Inv Final, Dif Lec, Dif Doc, Lec, Doc`. Si la estación no vendió ese
producto ese día, la sección trae `TOTAL null null null null null null null
null null null null` (sin fila de fecha).

## Mapeo de campos

| Origen (PDF)          | Destino (`merma_diaria`) | Nota |
|------------------------|---------------------------|------|
| Sección `87 Octanos`   | `codprd = 1` (familia maxima) | |
| Sección `91 Octanos`   | `codprd = 2` (familia super)   | |
| Sección `Diesel`       | `codprd = 3` (familia diesel)  | |
| `Fecha`                | `fecha`                   | |
| `Inv Lec`              | `inv_fisico`               | Lectura física de tanque, equivalente al corte físico de StockReal en las demás estaciones |
| `Ventas`               | `ventas_reales`            | |
| `Compras Doc`          | `compras`                  | Consistente con cómo se alimenta `compras` desde recepción documental en el resto del sistema |
| — (fijo)               | `turno = 41`                | Turno sintético de cierre de día — ver "Turnos" abajo |
| — (fijo)               | `codgas = 40`, `estacion = 'PRAXEDIS'` | |

**No se insertan** `inv_inicial`, `inv_contable`, `diferencia` — estos los
calcula `MermaDiariaModel::recalc_contable()` encadenando `inv_fisico` día a
día (regla libro amarillo), igual que para las demás estaciones. Si una
sección de producto viene `null` ese día, no se inserta fila para esa familia
ese día (se trata igual que "sin dato" en el resto del sistema).

## Turnos

El schema de `merma_diaria` es por turno (`11`, `21`, `41`) porque las demás
estaciones reportan 3 cortes/día vía StockReal. El "Balance de Producto" de
Praxedis solo trae un total diario. Se usará **un único turno sintético `41`**
por día (cierre de día), dejando `11`/`21` sin fila para Praxedis. Esto es
compatible sin cambios con `recalc_contable()`, que particiona por
`(codgas, codprd)` y ordena por `(fecha, turno)`: con un solo turno por día,
el LAG simplemente encadena el físico de un día al inicial del siguiente.

## Componentes a construir

### 1. Parser — `_assets/classes/BalanceProductoPdfParser.class.php`

Mismo patrón que `NotaCreditoPdfParser` (`_assets/classes/NotaCreditoPdfParser.class.php`):
ejecuta `_assets/bin/poppler/pdftotext.exe -layout` vía `proc_open` sobre el
PDF subido y parsea el texto plano resultante con regex.

Método estático `parse(string $rutaPdf, string $nombreArchivo): array`:

1. Extrae encabezado (`Fecha`, `Fecha Hasta`, `Estación`, `Tipo`).
2. Valida estructuralmente: `Fecha == Fecha Hasta`, `Estación == 'PRAXEDIS'`
   (case-insensitive), `Tipo == 'Diario'`. Si no matchea, retorna
   `{ok: false, error: '...', archivo: $nombreArchivo}` sin lanzar excepción
   (el preview debe poder mostrar el error por archivo, no tumbar el lote).
3. Por cada una de las 3 secciones de producto, ubica la fila `TOTAL` de esa
   sección; si sus valores son numéricos, extrae `Inv Inicial, Compras Doc,
   Ventas, Inv Lec` de la fila de fecha (no de la fila TOTAL, que es la suma
   del rango — aquí coincide porque el rango es 1 día, pero se usa la fila de
   fecha por claridad semántica). Si la sección es todo `null`, se omite.
4. Si las 3 secciones vienen vacías, retorna error ("PDF sin datos numéricos
   en ninguna familia de producto").
5. Retorna `{ok: true, fecha: 'YYYY-MM-DD', archivo: $nombreArchivo, filas: [
   {codprd, producto, inv_fisico, ventas_reales, compras}, ... ]}`.

### 2. Endpoints — `_assets/controllers/merma.php`

**`preview_balance_praxedis()`** (POST, `$_FILES['balances']` múltiple)
- Valida cada archivo: extensión `pdf`, `UPLOAD_ERR_OK`.
- Corre `BalanceProductoPdfParser::parse()` por archivo (usando la ruta
  temporal de `$_FILES`, sin moverlo todavía).
- Devuelve JSON: lista de resultados (uno por archivo, éxito o error) para que
  la vista arme la tabla de preview.

**`guardar_balance_praxedis()`** (POST)
- Recibe el mismo conjunto de archivos (se vuelven a parsear server-side; no
  se confía en un preview editable desde el cliente, igual que notas de
  crédito no persiste lo que el navegador le devuelva sin re-parsear).
- Descarta archivos con `ok: false`.
- Agrupa las filas válidas por `fecha` (un PDF = un día, pero el lote puede
  traer varios PDFs = varios días).
- Por cada fecha: arma el array de filas con `Turno = 41` fijo y llama a
  `MermaDiariaModel::replace_station_range(40, 'PRAXEDIS', $fecha, $fecha, $filas)`
  — reutilizado tal cual (ya hace DELETE+INSERT transaccional, aplica
  exclusiones de compras y corre `recalc_contable`). Sobrescribe automático si
  ya existía un snapshot para esa fecha (no se pide confirmación adicional:
  el usuario ya vio el preview antes de confirmar).
- Devuelve JSON de resumen: fechas insertadas, filas insertadas, archivos
  descartados por error.

### 3. UI — `views/merma/analisis.html`

- Nuevo botón **"Cargar Balance PDF"** junto al botón "Actualizar datos"
  existente (línea ~28), visible para Praxedis (codgas 40) dado que es el
  único caso hoy sin sync automático.
- Nuevo modal `#balancePraxedisModal`:
  - Input de archivo múltiple (`accept=".pdf"`, `multiple`).
  - Botón "Previsualizar" → POST a `preview_balance_praxedis`, renderiza una
    tabla: una fila por archivo con `fecha | 87 Octanos (inv inicial/compras/
    ventas/inv final) | 91 Octanos (ídem) | Diesel (ídem)`, o fila de error en
    rojo con el mensaje si el archivo no matchea.
  - Botón "Confirmar carga" (deshabilitado hasta que haya al menos un archivo
    válido en el preview) → POST a `guardar_balance_praxedis`, cierra el modal
    y refresca `#merma_table`.

## Validación

Solo estructural, sin bloquear por umbrales de diferencia (el PDF ya trae la
diferencia calculada por ControlGas; el sistema no la re-audita en esta
carga):

- Encabezado completo y coherente (`Fecha == Fecha Hasta`, `Estación ==
  PRAXEDIS`, `Tipo == Diario`).
- Al menos una familia de producto con datos numéricos (no las 3 en `null`).

## Fuera de alcance

- No se generaliza a otras estaciones sin conexión — el diseño hardcodea
  Praxedis (codgas 40) como caso único, ya que es el único hoy. Generalizar
  (detectar estaciones sin `Servidor`/`BaseDatos` dinámicamente) puede
  hacerse después si aparece un segundo caso.
- No se automatiza la obtención del PDF (por correo, portal, etc.) — la carga
  es manual, el usuario descarga el PDF de ControlGas y lo sube a mano.
- No se persiste el PDF original (a diferencia de notas de crédito, que sí
  guarda el archivo). Si se requiere trazabilidad del PDF fuente, se puede
  añadir `_assets/uploads/balance_praxedis/` como extensión futura.
