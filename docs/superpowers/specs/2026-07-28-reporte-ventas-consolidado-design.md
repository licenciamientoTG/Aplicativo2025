# Reporte de Ventas Consolidado — Diseño

**Fecha:** 2026-07-28
**Módulo:** Abastos (AplicativoPhp) — controlador `merma`
**Reemplaza:** el libro `VETS X EST X MARCA Y PROTS <Mes><Año>.xlsm`, que hoy se llena por referencias externas contra `Formato<Mes><Año>.xlsm`.
**Relacionado:** [Análisis de Merma Diaria](2026-07-13-analisis-merma-diaria-design.md), [Libro amarillo encadenado](2026-07-16-merma-libro-amarillo-encadenado-design.md)

## Objetivo

Una vista en el aplicativo que muestre las ventas en litros por día × estación, desglosadas por producto en cinco pestañas, con las filas de resumen y comparativos que hoy calcula el Excel — sin referencias externas entre libros y sin llenado manual.

## Contexto del flujo actual (lo que se reemplaza)

### `Formato<Mes><Año>.xlsm` — el origen

Una hoja por estación, nombrada con el número de estación (`1149`, `1163`, `1242`, … 39 hojas) más hojas de apoyo (`MERMA MENSUAL`, `MERMA ANUAL23..26`, `Proveedores`, `GCC`).

Dentro de cada hoja de estación:

- Filas 7–99: 31 días × 3 turnos.
- Tres bloques de producto, cada uno con las mismas seis columnas:

  | Producto | VR | % | COMPRAS | INV. CONT. | Inv. Físico | Diferencia |
  |---|---|---|---|---|---|---|
  | Máxima / Regular | **C** | D | E | F | G | H |
  | Súper / Premium | **J** | K | L | M | N | O |
  | Diesel | **Q** | R | S | T | U | V |

- Filas 103–133: rollup diario. `C103 = SUMA(C7:C9)` (día 1), `C104 = SUMA(C10:C12)` (día 2), etc.

**`VR` = Ventas Reales en litros.** Es la columna que el usuario identifica como la fuente del reporte de ventas.

### `VETS X EST X MARCA Y PROTS <Mes><Año>.xlsm` — el destino

Jala las celdas del rollup diario por referencia externa `[1]`:

```
REGULAR!C8  = '[1]1149'!$C103     ← VR Máxima, estación 1149, día 1
PREMIUM!C8  = '[1]1149'!$J103     ← VR Súper
DIESEL!C8   = '[1]1149'!$Q103     ← VR Diesel
```

Y las dos hojas restantes solo suman las anteriores:

```
'REGULAR + PREMIUM'!C8      = REGULAR!C8 + PREMIUM!C8
'LITROS DE COMBUSTIBLE'!C8  = REGULAR!C8 + PREMIUM!C8 + DIESEL!C8
```

El libro tiene 7 hojas, pero **solo 5 están vivas**. `PRODUCTOS` está fechada en diciembre 2021 y apunta a un libro `[20]`; `MERMA` está fechada en junio 2022 y apunta a `[24]`. Ambas quedan fuera de alcance.

### Estructura de cada hoja viva (idéntica en las 5)

| Rango | Contenido |
|---|---|
| Fila 6 | Nombres de estación (columnas C..AC, 27 estaciones) |
| Filas 8–38 | Días 1–31; col. A = día del mes, col. B = `TEXT(fecha,"DDDD")` |
| Col. AE | TOTAL del día = `SUMA(C:AC)` |
| Fila 39 | `TOTAL` = `SUMA(C8:C38)` |
| Fila 40 | `% MIX` = `C39 / $AE$39` |
| Fila 41 | `PROY. MENSUAL` = `C39 / $C$299 * $C$298` |
| Fila 42 | `PRESUPUESTO` (capturado a mano) |
| Fila 43 | `DIFERENCIA` = `C41 - C42` |
| Fila 44 | `% PRESUPUESTO` = `C41 / C42 - 1` |
| Fila 45 | `VS SEMANA PREVIA` = `C33 / C26 - 1` |
| Filas 47–292 | Histórico mensual desde ENERO 2006 (~240 filas) |
| Fila 295 | `% M.A.` = `C41 / C292 - 1` (proyección vs mes anterior) |
| Fila 296 | `% A.A.` = `C41 / C281 - 1` (proyección vs mismo mes del año pasado) |
| `C298` | DÍAS LABORABLES (días del mes) |
| `C299` | DÍAS LABORADOS (días con datos) |

La columna AE de la hoja `LITROS DE COMBUSTIBLE` está rotulada "TOTAL JUAREZ", pero su fórmula es `SUMA(C8:AC8)` sobre todas las estaciones: es el total general con una etiqueta heredada. **No hay agrupación por zona que replicar.**

## Decisiones tomadas

1. **Fuente de datos:** `TG.dbo.merma_diaria.ventas_reales`. Es el mismo `VR` del Formato, ya sincronizado por el cron del módulo de merma, y respeta las correcciones de cortes físicos y exclusiones de compra que ya se capturan ahí. Sin sincronización nueva.
2. **Presupuesto:** se reusa `TGV2.dbo.Budget`, que ya existe con importador Excel. **No se crea tabla nueva.**
3. **Estaciones:** todas las de la BD, en el orden corporativo (ver *Orden de estaciones*), no la lista fija de 27 del Excel.
4. **Periodo:** selector de año/mes. Filas = días 1..N del mes.
5. **Permisos:** mismo permiso 33 (Reportes de Abastos) que el resto del módulo.
6. **Ubicación:** método del controlador `merma` existente, no un controlador nuevo.
7. **Filas de resumen:** las 9 del Excel (TOTAL, % MIX, PROY. MENSUAL, PRESUPUESTO, DIFERENCIA, % PRESUPUESTO, VS SEMANA PREVIA, % M.A., % A.A.).
8. **Histórico 2006+:** fuera de alcance (ver *Fuera de alcance*).

## Arquitectura

```
/merma/ventas ──> Merma::ventas()
                    │
                    ├── MermaDiariaModel::get_ventas_mes($anio,$mes)         ─> TG.dbo.merma_diaria
                    ├── MermaDiariaModel::get_ventas_totales_mes($anio,$mes) ─> TG.dbo.merma_diaria  (×2: mes anterior, año anterior)
                    ├── MermaDiariaModel::get_estaciones_ordenadas()         ─> TG.dbo.Estaciones
                    └── BudgetModel::getBudget($mes,$anio)                   ─> TGV2.dbo.Budget
                    │
                    └─> views/merma/ventas.html  (5 pestañas)

/merma/ventas_excel ──> mismo dataset ──> PhpSpreadsheet ──> .xlsx de 5 hojas
```

## Datos

### Fuente principal: `TG.dbo.merma_diaria`

Ya existe (ver spec de Análisis de Merma Diaria). Las columnas relevantes:

| Columna | Uso aquí |
|---|---|
| `fecha` | día operativo → fila |
| `codgas` | estación → columna |
| `codprd` | familia de producto → pestaña |
| `ventas_reales` | el valor `VR` |

El mapeo de producto ya está en `MermaDiariaModel::FAMILIAS`:

```php
'maxima' => [1, 179, 192],
'super'  => [2, 180, 193],
'diesel' => [3, 181],
```

### Una consulta, cinco proyecciones

Las 5 hojas son la misma matriz con distinto filtro de producto. En vez de cinco consultas, una sola agrupada por día y estación, con las tres familias en columnas separadas:

```php
// MermaDiariaModel
public function get_ventas_mes(int $anio, int $mes): array
// SELECT fecha, codgas,
//   SUM(CASE WHEN codprd IN (1,179,192) THEN ventas_reales END) AS maxima,
//   SUM(CASE WHEN codprd IN (2,180,193) THEN ventas_reales END) AS super,
//   SUM(CASE WHEN codprd IN (3,181)     THEN ventas_reales END) AS diesel
// FROM [TG].[dbo].[merma_diaria]
// WHERE fecha >= ? AND fecha < DATEADD(MONTH, 1, ?)
// GROUP BY fecha, codgas
```

~31 × 37 = 1,147 filas. El controlador deriva las cinco vistas en PHP:

| Pestaña | Valor por celda |
|---|---|
| LITROS DE COMBUSTIBLE | `maxima + super + diesel` |
| REGULAR + PREMIUM | `maxima + super` |
| REGULAR | `maxima` |
| PREMIUM | `super` |
| DIESEL | `diesel` |

Una estación que no vende un producto simplemente no tiene filas de ese `codprd` y la celda queda nula — que es lo que el Excel resuelve a mano omitiendo el término (`AB8 = REGULAR!AB8 + DIESEL!AB8`, sin premium, para PRAXEDIS).

### Consulta de comparativos

Para `% M.A.` y `% A.A.` se necesitan totales mensuales por estación y familia de dos meses puntuales: el mes anterior y el mismo mes del año pasado.

```php
public function get_ventas_totales_mes(int $anio, int $mes): array
// mismas expresiones de familia, GROUP BY codgas (sin fecha)
```

Se llama dos veces. Si el mes pedido no tiene filas, el comparativo sale vacío.

### Presupuesto: `TGV2.dbo.Budget`

Tabla existente, ya modelada en `BudgetModel` y alimentada por `/commercial/import_file_budget` (importador Excel con formato descargable en `/commercial/download_format_budget`).

| Columna | Contenido |
|---|---|
| `codgas` | estación |
| `codprd` | 179 = Máxima, 180 = Súper, 181 = Diesel (192/193 aparecen solo en años viejos) |
| `budget_monthy` | litros presupuestados del mes |
| `year`, `month` | periodo |

El presupuesto de cada pestaña suma los `codprd` correspondientes:

| Pestaña | `codprd` sumados |
|---|---|
| LITROS DE COMBUSTIBLE | 179 + 180 + 181 + 192 + 193 |
| REGULAR + PREMIUM | 179 + 180 + 192 + 193 |
| REGULAR | 179 + 192 |
| PREMIUM | 180 + 193 |
| DIESEL | 181 |

**Estado de los datos:** la tabla cubre 2022–2025, con última carga en mayo 2025. No hay 2026. Es un pendiente de captura, no de código: se resuelve con el importador que ya existe. Mientras no haya presupuesto del mes, las filas PRESUPUESTO, DIFERENCIA y % PRESUPUESTO salen vacías (ver *Manejo de datos faltantes*).

### Orden de estaciones

`TG.dbo.Estaciones.Nombre` ya trae el número corporativo como prefijo (`02 Lerdo`, `03 Delicias`, … `38 PRAXEDIS`). Ese es el orden que se usa, extrayendo el prefijo numérico para que ordene como número y no como texto:

```sql
SELECT e.Codigo, e.Nombre, g.cveest
FROM [TG].[dbo].[Estaciones] e
LEFT JOIN [SG12].[dbo].[Gasolineras] g ON g.cod = e.Codigo
WHERE e.Codigo NOT IN (0, 4, 20)
ORDER BY TRY_CAST(LEFT(e.Nombre, 2) AS INT), e.Nombre;
```

**Esto es una reinterpretación deliberada de "orden del Excel".** El orden de las columnas C..AC del libro es aproximadamente por número de estación, pero con drift acumulado a mano (AZTECAS 5465 aparece entre MADRID 7167 y PERMUTA 8244; TRAVEL 24938 después de VENTANAS 24500 pero antes que PRAXEDIS 10702) y con una columna "AHUMADA 1242" cuyo número no corresponde a ninguna estación viva. Replicar ese orden exacto obligaría a mantener una lista fija en código. El orden por prefijo corporativo da el mismo agrupamiento lógico, se mantiene solo, e incluye las 10 estaciones que el Excel omite (Ejército Nacional, Satélite, Las fuentes, Clara, Solís, Santiago Troncoso, Jarudo, San Rafael, Puertecito, Jesús María).

## Vista

`views/merma/ventas.html`. Una sola página con 5 pestañas Bootstrap; las cinco matrices se calculan en el mismo request y se renderizan juntas.

### Controles

- Selector de **año** y **mes**. Default: el mes de *ayer* (misma convención que `Merma::analisis()` — el día en curso nunca tiene turnos completos y `merma_diaria` nunca lo trae).
- Botón **Exportar a Excel**.

### Tabla de cada pestaña

```
        │ 02 Lerdo │ 03 Delicias │ … │ 38 PRAXEDIS │ TOTAL
────────┼──────────┼─────────────┼───┼─────────────┼────────
1  mié  │   28,431 │      19,204 │ … │       6,118 │  931,204
2  jue  │   27,905 │      18,880 │ … │       5,902 │  918,447
…
31 vie  │        — │           — │ … │           — │        —
────────┼──────────┼─────────────┼───┼─────────────┼────────
TOTAL   │  731,004 │     498,221 │ … │     158,330 │ 24,204,118
% MIX   │    3.02% │       2.06% │ … │       0.65% │  100.00%
PROY.   │  871,428 │     593,957 │ … │     188,747 │ 28,858,140
PPTO    │  850,000 │     600,000 │ … │     190,000 │ 28,400,000
DIF     │   21,428 │      -6,043 │ … │      -1,253 │    458,140
% PPTO  │    2.52% │      -1.01% │ … │      -0.66% │     1.61%
VS SEM  │   -1.20% │       0.84% │ … │       3.11% │     0.44%
% M.A.  │    1.80% │      -2.10% │ … │       0.95% │     0.71%
% A.A.  │        — │           — │ … │           — │        —
```

Primera columna = día del mes, segunda = día de la semana (equivalente a `TEXT(fecha,"DDDD")`).

**Encabezado y las dos primeras columnas fijos** (`position: sticky`): son ~40 columnas × 40 filas y sin eso la tabla es inusable. El módulo ya tiene precedente en `views/merma/analisis.html`.

### Fórmulas de las filas de resumen

Con `T` = total de la columna, `D` = días con dato, `N` = días del mes, `P` = presupuesto:

| Fila | Fórmula | Nota |
|---|---|---|
| TOTAL | `T = Σ días` | |
| % MIX | `T / T_general` | |
| PROY. MENSUAL | `T / D × N` | `D` = días distintos con al menos un registro **en el mes completo**, no por estación (equivale a `C299` del Excel, que es un escalar único) |
| PRESUPUESTO | `P` de `Budget` | |
| DIFERENCIA | `PROY − P` | |
| % PRESUPUESTO | `PROY / P − 1` | |
| VS SEMANA PREVIA | `Σ(últimos 7 días con dato) / Σ(7 días anteriores) − 1` | El Excel usa filas fijas 33 vs 26; aquí se ancla al último día con dato para que funcione a mitad de mes |
| % M.A. | `PROY / total mes anterior − 1` | |
| % A.A. | `PROY / total mismo mes año anterior − 1` | |

### Manejo de datos faltantes

Nunca se muestra `0` ni `#¡DIV/0!` cuando el insumo no existe. Una celda sin dato se renderiza como `—` en gris:

- Día futuro, o día sin sincronizar → celda de día vacía.
- Estación que no vende ese producto → columna en blanco en esa pestaña.
- Sin presupuesto cargado del mes → PRESUPUESTO, DIFERENCIA y % PRESUPUESTO vacíos, con un aviso arriba de la tabla: *"Sin presupuesto cargado para <mes> <año>. Cárgalo en Comercial → Importar presupuesto."*
- Sin mes anterior / sin año anterior en `merma_diaria` → % M.A. / % A.A. vacíos.
- Menos de 14 días con dato → VS SEMANA PREVIA vacío.

Los porcentajes negativos van en rojo y los positivos en verde, consistente con el resto del módulo.

## Exportación a Excel

`/merma/ventas_excel?anio=&mes=` genera con PhpSpreadsheet un `.xlsx` con las 5 hojas nombradas igual que el libro original (`LITROS DE COMBUSTIBLE`, `REGULAR + PREMIUM`, `REGULAR`, `PREMIUM`, `DIESEL`), con la misma estructura de días + filas de resumen, pero con **valores, no fórmulas ni referencias externas**. El archivo sigue circulando como hoy sin depender de que `Formato<Mes><Año>.xlsm` esté abierto.

## Fuera de alcance

**El histórico mensual desde 2006 (filas 47–292).** `merma_diaria` arranca en enero de 2026. Replicar 20 años pediría o cargar el histórico a mano o reconstruirlo desde `SG12.dbo.Ventas` con una metodología distinta a la del `VR` — un proyecto aparte, con su propio riesgo de no cuadrar contra los números que la dirección ya conoce. Los comparativos `% M.A.` y `% A.A.` sí quedan en la vista, calculados contra lo que haya en `merma_diaria`.

**Las hojas `PRODUCTOS` y `MERMA` del libro.** Muertas desde 2021 y 2022 respectivamente.

## Pendientes de datos (no de código)

Estos dos no bloquean la implementación, pero sí la utilidad del reporte:

1. **Presupuesto 2026 en `TGV2.dbo.Budget`.** Última carga: mayo 2025. Se sube con `/commercial/import_file_budget`. Sin esto, tres filas de resumen salen vacías.
2. **Huecos en `merma_diaria`.** Cobertura actual: ene-2026 completo, **feb/mar/abr ausentes**, may (31 días), jun (30), jul (26, al corriente). Los meses faltantes se llenan con el sync del módulo de merma, que topa en 40 días por corrida — son ~3 pasadas. Sin febrero, el `% M.A.` de marzo no se puede calcular.

## Riesgos

| Riesgo | Mitigación |
|---|---|
| Los números no cuadran contra el Excel del mes | La primera validación es comparar `TOTAL` por estación de julio 2026 contra la fila 39 del libro. Si difieren, la causa probable son días sin sincronizar en `merma_diaria`, no la fórmula. |
| Tabla de ~40 columnas ilegible en pantalla | Columnas fijas + scroll horizontal, y exportación a Excel para el consumo detallado. |
| `Budget` usa `codprd` 192/193 en años viejos y 179/180 en los nuevos | El mapeo suma ambos pares por familia, así que funciona en cualquier año. |
