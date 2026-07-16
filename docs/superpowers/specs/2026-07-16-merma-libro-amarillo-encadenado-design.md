# Merma: inventario contable encadenado (regla del libro amarillo)

**Fecha:** 2026-07-16
**Módulo:** `/merma/` (Análisis de Merma Diaria)
**Spec previo:** `2026-07-13-analisis-merma-diaria-design.md`

## Problema

El snapshot `TG.dbo.merma_diaria` guarda `inv_inicial`, `inv_contable` y
`diferencia` tal como los calcula el SP portado en ApiER
(`get_inventarios_turnos_estacion`): el inventario inicial de cada turno es el
**físico del mismo turno del día anterior** (`fch - 1`, mismo `nrotur`).

El libro amarillo (Excel `FormatoJul2026.xlsm`) usa otra regla: el inicial de
cada turno es el **físico del turno inmediato anterior**, encadenado fila a
fila (`F_n = G_{n-1} - VR_n + Compras_n`), y la primera fila del mes arranca
del último físico del mes anterior (referencia externa `'[2]1149'!$G$96`).

Validado contra datos reales de Lerdo (codgas 5, Máxima/codprd 179, julio
2026): ventas, físico y compras coinciden al centavo entre snapshot y Excel;
solo el baseline difiere. La regla del SP produce "diferencias" por turno de
±7,000–14,000 L (ventana de 24 h contra ventas de un solo turno) mientras el
Excel da mermas reales de ±25–165 L.

Afecta a ambas vistas: `/merma/detalle/{codgas}` (columnas INV. CONT. y
Diferencia) y `/merma/` (el resumen suma `diferencia` del snapshot).

## Decisión

Recalcular y **sobreescribir en el snapshot** las tres columnas derivadas con
la regla encadenada del Excel. Sin cambios en ApiER ni en las vistas.

## Diseño

### 1. `MermaDiariaModel::recalc_contable(int $codgas = 0): void`

Un UPDATE con función ventana (`codgas = 0` recalcula todas las estaciones):

```sql
WITH b AS (
    SELECT id, LAG(inv_fisico) OVER (
             PARTITION BY codgas, codprd ORDER BY fecha, turno) AS fis_prev
    FROM TG.dbo.merma_diaria
    WHERE (? = 0 OR codgas = ?)
)
UPDATE m SET
    inv_inicial  = b.fis_prev,
    inv_contable = ROUND(b.fis_prev - ISNULL(m.ventas_reales, 0)
                         + ISNULL(m.compras, 0), 2),
    diferencia   = ROUND(m.inv_fisico - (b.fis_prev
                         - ISNULL(m.ventas_reales, 0)
                         + ISNULL(m.compras, 0)), 2)
FROM TG.dbo.merma_diaria m
JOIN b ON b.id = m.id;
```

Semántica de NULL:

- `fis_prev` NULL (primera fila del histórico de la estación/producto, o el
  turno anterior no tuvo corte físico): `inv_inicial`, `inv_contable` y
  `diferencia` quedan NULL → la vista muestra "s/d". No se arrastra un 0.
- `inv_fisico` NULL en la fila actual: `diferencia` NULL, `inv_contable` sí
  se calcula.
- El encadenamiento cruza días y meses de forma natural (equivalente a la
  referencia del Excel al archivo del mes anterior), siempre que el mes
  previo esté sincronizado.

### 2. Sync

`replace_station_range()` llama a `recalc_contable($codgas)` después del
commit. Recalcula la partición completa de la estación (miles de filas,
costo trivial); esto corrige también las filas posteriores al rango
sincronizado cuyo baseline cambió.

El insert sigue guardando los valores que responde ApiER; el recalc los
sobreescribe de inmediato. Así no hay deploy SFTP ni cambio de contrato del
endpoint `/api/inventarios_turnos/`.

### 3. Backfill

Corrida única de `recalc_contable(0)` sobre la tabla completa tras el
deploy (script one-off, no queda en el repo).

### 4. Documentación en código

Actualizar docblocks de `MermaDiariaModel` y `merma.php`: las columnas
derivadas usan la regla del libro amarillo (turno inmediato anterior) y ya
**no cuadran por turno** con `/supply/tgr01`, que conserva la regla del SP
original.

## Fuera de alcance

- Cambios en ApiER (`inventarios_estaciones.py`).
- Cambios en las vistas `analisis.html` / `detalle.html`.
- Conservar la diferencia vieja en columnas aparte (descartado por el
  usuario: se sobreescribe).

## Verificación

Contra el Excel de Lerdo (hoja `1149`, bloque Máxima, julio 2026):

| Fila | Diferencia esperada |
|---|---|
| 2026-07-01 t11 | −25.16 |
| 2026-07-01 t21 | −165.45 |
| 2026-07-01 t41 | +43.13 |

Y el acumulado mensual de la familia contra la celda `H1` de la hoja.
