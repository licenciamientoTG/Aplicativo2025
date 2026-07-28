# Histórico mensual del Reporte de Ventas Consolidado — Diseño

**Fecha:** 2026-07-28
**Módulo:** Abastos (AplicativoPhp) — controlador `merma`
**Amplía:** [Reporte de Ventas Consolidado](2026-07-28-reporte-ventas-consolidado-design.md)
**Reemplaza:** el bloque de filas 47–292 del libro `VETS X EST X MARCA Y PROTS <Mes><Año>.xlsm`, que el spec original declaró fuera de alcance.

## Objetivo

Una sexta pestaña en `/merma/ventas` que muestre el acumulado mensual por estación a lo largo de un rango de años seleccionable, con su propio selector de periodo y de producto, independiente del selector de mes que gobierna las cinco pestañas diarias.

## Por qué cambia la decisión del spec original

El spec del reporte declaró el histórico 2006+ fuera de alcance con este argumento:

> `merma_diaria` arranca en enero de 2026. Replicar 20 años pediría o cargar el histórico a mano o reconstruirlo desde `SG12.dbo.Ventas` con una metodología distinta a la del `VR` — un proyecto aparte, con su propio riesgo de no cuadrar contra los números que la dirección ya conoce.

**La premisa de la metodología distinta resultó falsa.** `SG12.dbo.Ventas` no es otra metodología: es la misma fuente. Tres comprobaciones contra la BD real:

| Comprobación | Resultado |
|---|---|
| Junio 2026, todas las estaciones — `TG.dbo.merma_diaria` vs `SG12.dbo.Ventas` | 25,804,653.073 litros en ambas, 36 estaciones en ambas. Idéntico al dígito. |
| LERDO 1149, junio 2025 — celda `C280` de la hoja LITROS DE COMBUSTIBLE vs `SG12.dbo.Ventas` | 441,598.18 en ambos |
| Cobertura por año en `SG12.dbo.Ventas` | 2012 parcial (oct–dic), **2013–2025 completos** (12 meses cada uno), 2026 al día |

Es decir: el histórico se puede construir con números reales que cuadran a la vez contra el Excel que la dirección ya conoce y contra las cinco pestañas diarias. No hay que cargar nada a mano ni aceptar ceros.

El riesgo que el spec original quiso evitar —"no cuadrar contra los números que la dirección ya conoce"— queda medido, no supuesto.

## Decisiones tomadas

1. **Fuente:** `SG12.dbo.Ventas` unida a `SG12.dbo.ISLAS`. Historia útil desde 2013.
2. **Forma de la tabla:** meses × estaciones, como el Excel. Una fila por mes de todo el rango, una columna por estación.
3. **Producto:** la pestaña tiene su propio selector (las cinco opciones de `VentasConsolidado::PESTANAS`), no un bloque histórico dentro de cada pestaña diaria.
4. **Celdas sin dato: `0`.** Decisión explícita del usuario. Rompe con la convención `—` del resto de la vista; ver *Riesgos*.
5. **Rango por defecto:** los últimos 3 años (hoy 2024–2026). Selectores desde 2013 hasta el año en curso.
6. **Carga diferida:** la pestaña se calcula por AJAX al primer clic, no en la carga inicial de `/merma/ventas`.
7. **Subtotales:** columna TOTAL por mes y fila TOTAL por año. Sin promedio mensual ni variación % contra el año previo (YAGNI).
8. **Permiso:** el mismo 33 que el resto del módulo.

## Arquitectura

```
/merma/ventas ──> vista con 6 pestañas
                    │
                    └── pestaña HISTÓRICO (vacía al cargar)
                          │ clic o cambio de selector → fetch AJAX
                          v
/merma/ventas_historico?desde=&hasta=&prod= ──> Merma::ventas_historico()
                          │
                          ├── VentasHistoricoModel::get_historico($desde,$hasta) ─> SG12.dbo.Ventas + ISLAS
                          ├── MermaDiariaModel::get_estaciones_ordenadas()       ─> TG.dbo.Estaciones
                          └── VentasConsolidado::construirHistorico()            ─> lógica pura
                          │
                          └─> views/merma/ventas_historico.html (solo la tabla, sin layout)
```

## Datos

### `VentasHistoricoModel` — archivo nuevo

Modelo propio en vez de agregar a `VentasModel` (que ya pasa de 1,900 líneas y no tiene que ver con este módulo) o a `MermaDiariaModel` (cuya identidad es la tabla `merma_diaria`, y esta consulta no la toca).

```php
public function get_historico(int $desde, int $hasta): array
// @return [anio => [mes => [codgas => ['maxima'=>?float,'super'=>?float,'diesel'=>?float]]]]
```

Consulta:

```sql
SELECT YEAR(DATEADD(DAY, v.fch - 1, '19000101'))  AS anio,
       MONTH(DATEADD(DAY, v.fch - 1, '19000101')) AS mes,
       isd.codgas AS codgas,
       SUM(CASE WHEN v.codprd IN (1,179,192) THEN v.canven END) AS maxima,
       SUM(CASE WHEN v.codprd IN (2,180,193) THEN v.canven END) AS [super],
       SUM(CASE WHEN v.codprd IN (3,181)     THEN v.canven END) AS diesel
FROM [SG12].[dbo].[Ventas] v
INNER JOIN [SG12].[dbo].[ISLAS] isd ON v.codisl = isd.cod
WHERE v.fch >= DATEDIFF(DAY, '19000101', ?) + 1
  AND v.fch <  DATEDIFF(DAY, '19000101', ?) + 1
GROUP BY YEAR(DATEADD(DAY, v.fch - 1, '19000101')),
         MONTH(DATEADD(DAY, v.fch - 1, '19000101')), isd.codgas
```

Notas sobre esta consulta:

- **`fch` es un entero, no una fecha:** días desde 1900-01-01 con base 1 (el mismo serial que usa `VentasModel`). El filtro se escribe contra `v.fch` con `DATEDIFF` sobre los parámetros —no contra `DATEADD(v.fch)`— para que el predicado sea *sargable* y pueda usar índice sobre `fch`. Es la diferencia entre 1 s y un escaneo completo de 3.6 millones de filas.
- **`super` va entre corchetes:** palabra reservada en T-SQL, igual que en `MermaDiariaModel`.
- **Basta unir `ISLAS`:** `ISLAS.codgas` ya es el código de estación (equivale a `Gasolineras.cod` y a `TG.dbo.Estaciones.Codigo`). No hace falta la unión adicional a `Gasolineras` que usa `VentasModel`.
- **Los códigos de producto son los mismos** que `MermaDiariaModel::FAMILIAS`. La consulta los escribe literales porque vive en otro modelo; la proyección por pestaña sí reusa `VentasConsolidado::PESTANAS`.
- **Estaciones fuera del catálogo:** `ISLAS` tiene 39 `codgas` distintos (2 a 40); la vista solo dibuja las columnas de `get_estaciones_ordenadas()`, que excluye 0, 4 y 20. Las sobrantes se ignoran solas.

### Rendimiento medido

| Rango | Filas | Tiempo |
|---|---|---|
| 3 años (default) | 1,074 | 0.98 s |
| 14 años (2013 al presente) | 3,711 | 2.56 s |

Este costo es la razón de la carga diferida: la mayoría de las visitas a `/merma/ventas` nunca abren la pestaña histórica, y no tienen por qué pagar ese segundo.

### `VentasConsolidado::construirHistorico()` — método nuevo

Lógica pura, sin BD, en la clase que ya concentra las fórmulas del reporte. Reusa `PESTANAS` para el mapeo de producto.

```php
/**
 * @param string $clave  llave de self::PESTANAS
 * @param array  $ctx    [
 *   'estaciones' => [['Codigo'=>int,'Nombre'=>string], ...] en orden de columna
 *   'historico'  => [anio => [mes => [codgas => ['maxima'=>?float,'super'=>?float,'diesel'=>?float]]]]
 *   'desde'      => int  (año)
 *   'hasta'      => int  (año)
 * ]
 * @return array [
 *   'label' => string,
 *   'filas' => [
 *      ['tipo'=>'mes',   'anio'=>int, 'mes'=>int, 'etiqueta'=>'ENERO 2024',
 *       'celdas'=>[codgas=>float], 'total'=>float],
 *      ['tipo'=>'anual', 'anio'=>int, 'etiqueta'=>'TOTAL 2024',
 *       'celdas'=>[codgas=>float], 'total'=>float],
 *      …
 *   ],
 * ]
 */
public static function construirHistorico(string $clave, array $ctx): array
```

Genera un renglón por cada mes del rango completo (enero del año `desde` a diciembre del año `hasta`) y, después del último mes de cada año, un renglón `anual` con la suma de sus doce meses.

**Las celdas son `float`, nunca `null`:** a diferencia de `construir()`, aquí una estación sin ventas en un mes vale `0.0`. Es la decisión 4.

## Vista

Una sexta pestaña en `views/merma/ventas.html`, después de DIESEL, rotulada **HISTÓRICO**. Su panel arranca vacío con un indicador de carga.

### Controles propios

Dentro del panel de la pestaña, no en el formulario de arriba —para no colisionar con el selector de mes que gobierna las cinco pestañas diarias:

- **Año desde** y **Año hasta**: `2013` al año en curso. Default: año en curso − 2 al año en curso.
- **Producto**: las cinco opciones de `PESTANAS`, rotuladas con su `label`. Default `total` (LITROS DE COMBUSTIBLE).

Cambiar cualquiera dispara el mismo fetch que el primer clic. Los selectores no recargan la página.

**Validación en `ventas_historico()`**, con los mismos criterios que `ventas()` usa para `anio`/`mes`: `desde` y `hasta` se castean a entero y se acotan a `[2013, año en curso]`; si `desde > hasta` se intercambian; `prod` que no sea una llave de `PESTANAS` cae a `total`. Un parámetro inválido nunca produce un error, siempre un rango razonable.

### Carga

Al primer clic en la pestaña —y en cada cambio de selector— se hace `fetch('/merma/ventas_historico?desde=…&hasta=…&prod=…')`. La respuesta es el HTML de la tabla, que se inyecta en el panel. Mientras tanto, un texto "Cargando histórico…". Si la petición falla, un mensaje de error en el panel; el resto de la vista no se ve afectado.

El JavaScript vive en `_assets/js/merma_ventas.js`, cargado desde el bloque `myjs` de la vista. No hay bundler en el proyecto: es un archivo servido directo, como el resto de `_assets/js/`.

### Tabla

```
                    LERDO     TECNOL.    DELICIAS   …      TOTAL
ENERO 2024        419,548    508,207     418,547    …  24,120,455
FEBRERO 2024      406,049    489,422     354,732    …  22,845,110
…
DICIEMBRE 2024    428,665    524,819     426,234    …  26,043,881
TOTAL 2024      5,102,338  6,180,554   4,998,201    … 285,551,204
ENERO 2025        419,548    508,207     418,547    …  24,998,003
…
```

Reusa `.merma-tabla` y `.merma-scroll`. Encabezado y la primera columna (la etiqueta del mes) fijos. Las filas `TOTAL <año>` van en negritas con un borde superior que las separe del bloque de meses.

Todas las cifras en litros con separador de miles y sin decimales, igual que las cinco pestañas diarias.

## Exportación

`/merma/ventas_excel` gana una sexta hoja, `HISTÓRICO`, con el rango y producto que la pestaña tenga seleccionados. La vista pasa esos tres valores al enlace de exportación cuando cambian, de modo que el archivo refleje lo que está en pantalla.

Si el usuario nunca abrió la pestaña, el enlace lleva los valores por defecto y la hoja sale con los últimos 3 años de combustible total.

## Fuera de alcance

- **2006–2012.** El Excel arranca su histórico en enero 2006, pero `SG12.dbo.Ventas` no tiene nada antes de octubre de 2012. Esos años solo existen como números escritos a mano en el libro. Cargarlos pediría capturarlos, y no hay forma de verificarlos contra ninguna fuente.
- **Promedio mensual por año y variación % contra el año previo.** Se agregan si se piden.
- **La fila `Suma 12 Meses`** (fila 293 del Excel): es un acumulado móvil ligado a la proyección del mes en curso, no al histórico por año. Los comparativos `% M.A.` y `% A.A.` que sí dependen de ella ya están en las cinco pestañas diarias.

## Riesgos

| Riesgo | Mitigación |
|---|---|
| **Los ceros de meses futuros se leen como "no vendimos".** Diciembre 2026 mostrará `0` igual que un mes real sin ventas. | Decisión explícita del usuario, tomada con la alternativa a la vista (cortar las filas en el último mes con datos). Queda documentada aquí para poder revertirla en una línea: es el único punto donde `construirHistorico()` decide `0.0` en vez de `null`. |
| Inconsistencia visual con el resto de la vista, que usa `—` para "sin dato". | Misma decisión. La pestaña histórica es la única con esta convención. |
| El rango completo (14 años) tarda 2.6 s. | Carga diferida y default de 3 años. El usuario que pide 14 años sabe lo que pidió. |
| `SG12.dbo.Ventas` es una réplica; si deja de sincronizar, el histórico se congela sin avisar. | Los meses recientes se pueden contrastar contra las pestañas diarias, que leen `merma_diaria`. Una discrepancia visible en el mes en curso es la señal. |
