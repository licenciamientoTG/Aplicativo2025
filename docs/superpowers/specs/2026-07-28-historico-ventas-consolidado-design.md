# Histórico mensual del Reporte de Ventas Consolidado — Diseño

**Fecha:** 2026-07-28
**Módulo:** Abastos (AplicativoPhp) — controlador `merma`
**Amplía:** [Reporte de Ventas Consolidado](2026-07-28-reporte-ventas-consolidado-design.md)
**Reemplaza:** el bloque de filas 47–292 del libro `VETS X EST X MARCA Y PROTS <Mes><Año>.xlsm`, que el spec original declaró fuera de alcance.

## Objetivo

Una sexta pestaña en `/merma/ventas` que muestre el acumulado mensual por estación a lo largo de un rango de años seleccionable, con su propio selector de periodo y de producto, independiente del selector de mes que gobierna las cinco pestañas diarias.

## Fuente de datos y su alcance

La pestaña lee **`TG.dbo.merma_diaria.ventas_reales`**, el mismo snapshot que llena el sync de mermas y que ya alimenta las cinco pestañas diarias. Decisión explícita del usuario: el reporte completo debe salir de una sola tabla, para que ninguna pestaña pueda contradecir a otra.

### Lo que eso implica hoy

`merma_diaria` arrancó en enero de 2026 y tiene huecos. Cobertura al 2026-07-28:

| Año | Mes | Días | Estaciones | Millones de litros |
|---|---|---|---|---|
| 2026 | enero | 31 | 35 | 21.94 |
| 2026 | mayo | 31 | 36 | 25.75 |
| 2026 | junio | 30 | 36 | 25.80 |
| 2026 | julio | 26 | 36 | 21.48 |

Con el rango por defecto de 3 años (36 filas de mes), **32 filas saldrán en cero**. Febrero, marzo y abril de 2026 también, porque nunca se sincronizaron.

Esto no es un defecto de la vista: es el estado de la tabla. La pestaña se llena sola conforme se sincronicen más meses con el botón *Actualizar datos* de `/merma/analisis`, que topa en 40 días por corrida.

### Alternativa descartada y por qué se documenta

`SG12.dbo.Ventas` tiene historia mensual completa desde 2013 y produce **exactamente los mismos números**: en junio de 2026 ambas fuentes dan 25,804,653.073 litros sobre 36 estaciones, idéntico al dígito, y LERDO 1149 en junio de 2025 da 441,598.18, que es justo lo que trae la celda `C280` de la hoja LITROS DE COMBUSTIBLE del Excel.

Se descartó por decisión del usuario a favor de la consistencia de fuente. Queda anotado porque la diferencia entre ambas opciones es solo de cobertura, no de metodología: si algún día se quiere llenar el histórico sin sincronizar 14 años mes por mes, la ruta existe y ya está verificada.

## Decisiones tomadas

1. **Fuente:** `TG.dbo.merma_diaria`, la misma que las cinco pestañas diarias. Historia limitada a lo que se haya sincronizado.
2. **Forma de la tabla:** meses × estaciones, como el Excel. Una fila por mes de todo el rango, una columna por estación.
3. **Producto:** la pestaña tiene su propio selector (las cinco opciones de `VentasConsolidado::PESTANAS`), no un bloque histórico dentro de cada pestaña diaria.
4. **Celdas sin dato: `0`.** Decisión explícita del usuario. Rompe con la convención `—` del resto de la vista; ver *Riesgos*.
5. **Rango por defecto:** los últimos 3 años (hoy 2024–2026). El límite inferior de los selectores es el primer año presente en `merma_diaria`, calculado en tiempo de consulta, de modo que crezca solo conforme se sincronice más historia.
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
                          ├── MermaDiariaModel::get_historico_mensual($desde,$hasta) ─> TG.dbo.merma_diaria
                          ├── MermaDiariaModel::get_anio_min_historico()             ─> TG.dbo.merma_diaria
                          ├── MermaDiariaModel::get_estaciones_ordenadas()           ─> TG.dbo.Estaciones
                          └── VentasConsolidado::construirHistorico()                ─> lógica pura
                          │
                          └─> views/merma/ventas_historico.html (solo la tabla, sin layout)
```

## Datos

### Dos métodos nuevos en `MermaDiariaModel`

Van en el modelo que ya es dueño de `merma_diaria`, junto a `get_ventas_mes()` y `get_ventas_totales_mes()` que agregó el reporte diario. Reusan el `familiaCase()` privado que ya existe, de modo que los códigos de producto siguen viviendo en un solo lugar (`MermaDiariaModel::FAMILIAS`).

```php
public function get_historico_mensual(int $desde, int $hasta): array
// @return [anio => [mes => [codgas => ['maxima'=>?float,'super'=>?float,'diesel'=>?float]]]]
```

```sql
SELECT YEAR(fecha) AS anio, MONTH(fecha) AS mes, codgas,
       SUM(CASE WHEN codprd IN (1,179,192) THEN ventas_reales END) AS maxima,
       SUM(CASE WHEN codprd IN (2,180,193) THEN ventas_reales END) AS [super],
       SUM(CASE WHEN codprd IN (3,181)     THEN ventas_reales END) AS diesel
FROM [TG].[dbo].[merma_diaria]
WHERE fecha >= ? AND fecha < DATEADD(YEAR, 1, CAST(? AS DATE))
GROUP BY YEAR(fecha), MONTH(fecha), codgas
```

Los parámetros son `'<desde>-01-01'` y `'<hasta>-01-01'`; el `DATEADD(YEAR, 1, …)` cierra el rango en el 31 de diciembre del año `hasta`. Se filtra por `fecha` directo, sin envolver la columna en una función, para no anular el índice `IX_merma_diaria_estacion`.

`super` va entre corchetes por ser palabra reservada en T-SQL, igual que en el resto del modelo.

```php
public function get_anio_min_historico(): ?int
// SELECT MIN(YEAR(fecha)) FROM [TG].[dbo].[merma_diaria]
// null si la tabla está vacía
```

Alimenta el límite inferior de los selectores de año, de modo que el rango disponible crezca solo conforme se sincronice más historia. Si devuelve `null`, el selector ofrece únicamente el año en curso.

### Rendimiento

`merma_diaria` tiene hoy 29,827 filas en total, así que la consulta es inmediata en cualquier rango. **La carga diferida no se justifica por costo sino por independencia de los controles**: la pestaña necesita sus propios selectores de año y producto, y si vivieran en el formulario de arriba, cambiarlos recargaría la página y arrastraría al selector de mes que gobierna las cinco pestañas diarias. Cargarla por AJAX mantiene los dos juegos de controles separados.

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
 *   'meses_con_datos' => ['ENERO 2026', 'MAYO 2026', …],  // para la leyenda
 *   'meses_del_rango' => int,
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

- **Año desde** y **Año hasta**: del año que devuelva `get_anio_min_historico()` al año en curso. Default: año en curso − 2 al año en curso, recortado al mínimo disponible.
- **Producto**: las cinco opciones de `PESTANAS`, rotuladas con su `label`. Default `total` (LITROS DE COMBUSTIBLE).

Cambiar cualquiera dispara el mismo fetch que el primer clic. Los selectores no recargan la página.

**Validación en `ventas_historico()`**, con los mismos criterios que `ventas()` usa para `anio`/`mes`: `desde` y `hasta` se castean a entero y se acotan a `[2020, año en curso]` —el mismo piso duro que ya usa `ventas()`, para que un parámetro manipulado no pida un rango absurdo aunque la tabla crezca hacia atrás—; si `desde > hasta` se intercambian; `prod` que no sea una llave de `PESTANAS` cae a `total`. Un parámetro inválido nunca produce un error, siempre un rango razonable.

El piso del **selector** (`get_anio_min_historico()`) y el piso de la **validación** (2020) son distintos a propósito: el primero ofrece solo lo que existe, el segundo tolera cualquier cosa razonable que llegue por URL.

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

### Leyenda de cobertura

Debajo de la tabla, siempre visible:

> Con datos: **enero, mayo, junio, julio de 2026** (4 de 36 meses del rango). Los meses sin sincronizar aparecen en cero. [Sincronizar los faltantes](/merma/analisis)

El conteo y la lista salen de los meses que `get_historico_mensual()` efectivamente devolvió. Sin esta leyenda, las 32 filas en cero del estado actual se leen como una caída de ventas, no como falta de sincronización — es el riesgo principal de la pestaña (ver *Riesgos*).

## Exportación

`/merma/ventas_excel` gana una sexta hoja, `HISTÓRICO`, con el rango y producto que la pestaña tenga seleccionados. La vista pasa esos tres valores al enlace de exportación cuando cambian, de modo que el archivo refleje lo que está en pantalla.

Si el usuario nunca abrió la pestaña, el enlace lleva los valores por defecto y la hoja sale con los últimos 3 años de combustible total.

## Fuera de alcance

- **Todo lo anterior a lo que haya en `merma_diaria`.** El Excel arranca su histórico en enero 2006; la tabla arranca en enero 2026. Llenar el hueco es trabajo de sincronización, no de esta vista: cada corrida del botón *Actualizar datos* cubre hasta 40 días. La vista muestra ceros mientras tanto y se llena sola.
- **Promedio mensual por año y variación % contra el año previo.** Se agregan si se piden.
- **La fila `Suma 12 Meses`** (fila 293 del Excel): es un acumulado móvil ligado a la proyección del mes en curso, no al histórico por año. Los comparativos `% M.A.` y `% A.A.` que sí dependen de ella ya están en las cinco pestañas diarias.

## Riesgos

| Riesgo | Mitigación |
|---|---|
| **Los ceros de meses futuros se leen como "no vendimos".** Diciembre 2026 mostrará `0` igual que un mes real sin ventas. | Decisión explícita del usuario, tomada con la alternativa a la vista (cortar las filas en el último mes con datos). Queda documentada aquí para poder revertirla en una línea: es el único punto donde `construirHistorico()` decide `0.0` en vez de `null`. |
| Inconsistencia visual con el resto de la vista, que usa `—` para "sin dato". | Misma decisión. La pestaña histórica es la única con esta convención. |
| **La tabla nace casi vacía.** Con el default de 3 años, hoy 32 de 36 filas salen en cero, y no hay nada en la pantalla que distinga "no se ha sincronizado" de "no se vendió". Un lector desprevenido puede concluir que las ventas se desplomaron. | Debajo de la tabla va una leyenda con los meses que sí tienen datos en el rango consultado y un enlace a `/merma/analisis` para sincronizar los faltantes. Es la única señal que impide leer los ceros como una caída real. |
| Sincronizar hacia atrás cuesta una corrida por cada 40 días. | Fuera del alcance de esta vista, pero anotado en el spec para que la expectativa sea correcta: llenar 2025 completo son ~9 corridas. |
