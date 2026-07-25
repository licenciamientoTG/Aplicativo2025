# Diagnóstico: corte físico corrupto — López Mateos (codgas 6), 14-jul-2026

**Snapshot PRE tomado:** 2026-07-25 (archivo `snapshot_pre.json`)
**Estación:** 05 López Mateos — linked server `192.168.5.101`, BD `SG12_25262020`
**Rango analizado:** 13–15 jul 2026 (fch 46215–46217)

## Qué está dañado

`StockReal` del 14-jul (fch **46216**), turno **40** (corte de cierre, 23:59:07):

| codprd | producto | codtan | can grabado | debería andar en |
|--------|----------|--------|-------------|------------------|
| 179 | T-Maxima Regular | 14 | **0.0** | ≈ 53,124 lts |
| 180 | T-Super Premium  | 15 | **0.0** | ≈ 23,875 lts |

**Los DOS tanques quedaron en 0.0 a la misma hora exacta** → falla del corte automático
(la consola no respondió a las 23:59), no es problema de un tanque ni captura humana.
`lognew = logfch = 2026-07-14 23:59:07` (nunca se ha corregido).

## Evidencia de los valores esperados

- MAXIMA: último corte bueno del día = turno 30, 21:59:36 → **53,773.50** (ya incluye la
  descarga). Ventas del turno nocturno (nrotur 41) = 649.38 → esperado ≈ **53,124.12**.
  Cuadra con el arranque del 15-jul (turno 10, 06:00) = 52,751.55.
- SUPER: turno 30 = **24,042.32** − ventas 167.54 → esperado ≈ **23,874.78**.
  Cuadra con el 15-jul turno 10 = 23,864.43.
- El contable del reporte de merma (52,769.67 MAXIMA) difiere del esperado por ~354 lts:
  es la merma real acumulada del día, normal.
- Compra de MAXIMA del 14-jul: `Movimientos` tip=11, nro=5443, **+28,261.79 lts**
  (nrotur 31; capturada tardíamente el 16-jul 09:35, logusu=0). Por eso el reporte
  muestra COMPRAS 28,262 en el turno 41.

## Estado de las tablas al momento PRE (lo que hay que vigilar tras corregir en ControlGas)

| Tabla | Filas (rango) | Nota |
|-------|---------------|------|
| estacion.StockReal | 24 | ← **aquí está el daño** (2 filas en 0.0) |
| estacion.Ventas (agrupadas) | 24 grupos | sanas, cuadran con el reporte |
| estacion.Movimientos | 74 | compra 28,261.79 en nrotur 31 del 14-jul |
| estacion.MovimientosTan | 3,481 | transacciones de tanque (telemetría) |
| estacion.Medicion | 96 | acumulados por bomba/turno; turno 41 con bombas 3/4 en 0 (cerradas) |
| estacion.Tanques | 2 | `capocu` trae basura acumulada (−139,292 MAXIMA) — preexistente |
| central SG12.StockReal | 24 | réplica: MISMOS valores que la estación (incluye los 0.0) |
| central TG.merma_diaria | 18 | snapshot de merma con el corte excluido (corrupto) |

## Procedimiento de verificación POST

1. Corregir en ControlGas el corte del 14-jul turno de cierre (ambos productos).
2. Correr: `php snapshot_estacion.php post` (script copiado en esta carpeta; ejecutarlo
   desde cualquier ruta, escribe `snapshot_post.json` aquí).
3. Comparar pre vs post para ver qué tablas tocó realmente ControlGas
   (se espera: `StockReal` estación con logfch nuevo; ¿réplica SG12? ¿Medicion? —
   eso es justo lo que queremos aprender).
4. Re-sincronizar el 14-jul en /merma para que el snapshot central se actualice.
