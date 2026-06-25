# Simplificar vista de captura de arqueo (Cajón + Caja Fuerte en una sola sección)

## Contexto

La pantalla `/arqueo/caja/{caja_id}` (`views/arqueo/caja.html`) captura el conteo físico de una caja de Dollar2Go en 4 secciones independientes, cada una con su propia tabla por moneda (USD/MXN):

1. **Cajón del Cajero** — billete suelto (`tipo='billete'`, total = cantidad × denominación)
2. **Billetes de Caja Fuerte** — fajilla (`tipo='fajilla'`, total = cantidad × denominación × 100)
3. **Morrallero del Cajero** — moneda suelta (`tipo='moneda'`, total = cantidad × denominación)
4. **Morrallero de Caja Fuerte** — bolsa (`tipo='bolsa'`, total = cantidad × valor_bolsa fijo)

Cada denominación dentro de una sección/moneda se guarda como una fila independiente en `arqueo_denominaciones` (`seccion`, `moneda`, `tipo`, `denominacion`, `valor_bolsa`, `cantidad`, `total`). El cálculo de totales de la caja (`Arqueo::calcular_totales_caja()`) suma por `seccion`+`moneda` sin distinguir más allá de eso.

**Problema:** 4 secciones separadas obligan al cajero a repetir la misma denominación dos veces (una vez en "del Cajero", otra en "de Caja Fuerte"), saltando entre tablas. Es más natural ver ambas cantidades de una misma denominación en la misma fila.

## Decisión de diseño

Reducir de 4 secciones a 2 (**Billetes** y **Monedas**), cada una con una sola tabla por moneda que tiene **dos columnas de captura** (Cajón / Caja Fuerte) en vez de dos tablas separadas. Es un cambio **puramente de presentación**: no se modifica el controlador, los modelos, el esquema de BD, ni las fórmulas de cálculo. Cada columna sigue grabando su propia fila en BD (`seccion='cajon'` o `seccion='caja_fuerte'`), igual que hoy graban `seccion='cajon'`/`seccion='caja_fuerte'`/`seccion='morrallero'`/`seccion='morrallero_cf'`.

Se descartó:
- **Unificar Cajón y Caja Fuerte como un solo input/fila** (sin distinguir billete suelto de fajilla, o pieza de bolsa): rechazado porque pierde el desglose que ya existe en BD y obligaría a cambiar cómo se cuenta físicamente en caja fuerte (fajilla/bolsa vs. pieza suelta), que es una decisión operativa, no solo visual.
- **Aplicar el cambio solo a Billetes y dejar Morrallero con sus 2 secciones actuales**: descartado a pedido explícito del usuario — el mismo patrón de simplificación aplica a ambos.

## Comportamiento esperado

### Mapeo de secciones (sin cambios en el backend)

| Sección visual nueva | Columna  | `seccion` (BD) | `tipo` (BD)        | Fórmula total                          |
|-----------------------|----------|-----------------|---------------------|-----------------------------------------|
| Billetes              | Cajón        | `cajon`         | `billete`           | cantidad × denominación                |
| Billetes              | Caja Fuerte  | `caja_fuerte`   | `fajilla`           | cantidad × denominación × 100          |
| Monedas               | Cajón        | `morrallero`    | `moneda`            | cantidad × denominación                |
| Monedas               | Caja Fuerte  | `morrallero_cf` | `bolsa`             | cantidad × valor_bolsa (fijo por fila) |

Los nombres internos de `seccion` (`cajon`, `caja_fuerte`, `morrallero`, `morrallero_cf`) **no cambian** — siguen siendo las claves que usa `Arqueo::DENOMINACIONES`, `calcular_totales_caja()`, y los selectores `data-seccion` en JS. Solo cambian los títulos visibles y el agrupamiento de tablas.

### Estructura de tabla (por sección × moneda)

**Billetes** (alineación 1:1, mismas denominaciones en Cajón y Caja Fuerte):

```
Denominación | Cajón | Caja Fuerte | Total
$100         | [ 5]  |    [ 2]     | $700.00
$50          | [ 3]  |    [ 1]     | $250.00
...
                          Gran Total Billetes USD: $X,XXX.XX
```

**Monedas** (las denominaciones de Caja Fuerte son "valor por bolsa", alineadas posicionalmente con la denominación facial de Cajón — igual que hoy en `morrallero_cf`):

```
Denominación | Cajón | Caja Fuerte ($val/bolsa) | Total
$1           | [ 5]  |   [ 2] ($100/bolsa)      | $105.00
$0.50        | [10]  |   [ 1] ($50/bolsa)        | $55.00
...
                          Gran Total Monedas USD: $X,XXX.XX
```

El encabezado de la columna Caja Fuerte en Monedas debe indicar el valor por bolsa de cada fila (texto pequeño junto al input o en una sub-etiqueta), ya que ese valor no es deducible de la denominación facial.

Cada fila de la tabla tiene una celda "Total" única que suma cantidad_cajón×denom + cantidad_cf×(denom×100 o valor_bolsa, según corresponda). El "Gran Total" al pie de cada tabla sigue siendo la suma de todas las filas de esa moneda (Cajón + Caja Fuerte combinados) — coincide con la suma de los dos gran-totales que hoy se muestran por separado.

### Cambios en `views/arqueo/caja.html`

- Sustituir el bucle actual de 4 secciones (`secciones = {cajon, morrallero, caja_fuerte, morrallero_cf}` recorridas independientemente) por un bucle de 2 secciones visuales (`billetes`, `monedas`), cada una iterando un único array de denominaciones y generando, por fila, dos inputs (`data-seccion="cajon|morrallero"` y `data-seccion="caja_fuerte|morrallero_cf"`) más una celda de total combinado.
- Las imágenes de apoyo (`_assets/images/arqueo/*.png`) se mantienen, mostradas junto a la denominación (sin cambios en nomenclatura de archivo).
- Los `data-*` atributos de cada input (`data-seccion`, `data-moneda`, `data-tipo`, `data-denominacion`, `data-valor-bolsa`) se conservan igual que hoy; son la clave para que el JS y el guardado sigan funcionando sin tocar el controlador.

### Cambios en `_assets/js/arqueo.js`

- `recalcular()`: la lógica de suma por `seccion`+`moneda` (objeto `seccionMoneda`) no cambia. Lo que cambia es cómo se pinta la celda "Total" de cada fila: en vez de una celda de total por input, ahora una fila tiene 2 inputs y 1 celda de total — el total de la celda = `totalDenominacion(inputCajón) + totalDenominacion(inputCajaFuerte)`. Hay que ubicar ambos inputs de la fila (por ejemplo, vía `closest('tr')` y `querySelectorAll('.denom-input')` dentro de esa fila) en vez de asumir un input por celda.
- `precargarCaja()`: sin cambios de lógica — sigue buscando inputs por `data-seccion`+`data-moneda`+`data-denominacion`; solo cambia su ubicación en el DOM (misma fila en vez de tabla separada), lo cual es transparente para el selector.
- `guardarCaja()`: sin cambios — sigue recolectando todos los `.denom-input` sin importar su posición visual; el payload enviado al backend es idéntico en forma.

### Sin cambios

- `_assets/controllers/arqueo.php` (constante `DENOMINACIONES`, `calcular_totales_caja()`, `normalizar_denominaciones()`, `total_denominacion()`, `guardar_caja()`)
- `_assets/models/ArqueoDenominacionesModel.php`, esquema de `arqueo_denominaciones`
- Panel de resumen (columna derecha) — sin cambios
- Sección de Vales — sin cambios

## Fuera de alcance

- No se modifica el ciclo de sesión (`programado → abierto → cerrado`).
- No se agregan subtotales de Cajón vs Caja Fuerte en el panel de resumen.
- No se cambia el criterio de conteo físico en caja fuerte (sigue siendo fajilla para billetes y bolsa para monedas, no pieza suelta).
- No se modifica `concentrado()` ni `cajas()` ni la exportación (pendiente, fuera de este cambio).

## Archivos afectados

- `views/arqueo/caja.html` — reestructurar el bloque de 4 secciones en 2, con tabla de 4 columnas (Denominación, Cajón, Caja Fuerte, Total) por moneda
- `_assets/js/arqueo.js` — ajustar `recalcular()` para calcular el total combinado por fila (dos inputs por fila en vez de uno); sin cambios en `precargarCaja()` ni `guardarCaja()` más allá de que ya funcionan correctamente con la nueva disposición del DOM
