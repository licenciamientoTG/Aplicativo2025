# Verificación POST — corrección en ControlGas (2026-07-25 08:47)

Snapshot POST: `snapshot_post.json` (2026-07-25 ~08:50). Diff completo contra `snapshot_pre.json`.

## Qué tocó ControlGas al corregir: SOLO StockReal de la estación

| Tabla | Resultado |
|-------|-----------|
| **estacion.StockReal** | ✅ ÚNICAS 2 filas modificadas (las del corte dañado) |
| estacion.Ventas | sin cambios |
| estacion.Movimientos | sin cambios |
| estacion.MovimientosTan | sin cambios (3,481 filas) |
| estacion.Medicion | sin cambios |
| estacion.Tanques | sin cambios |
| **central SG12.StockReal** | ⚠️ **SIN CAMBIOS — la réplica corporativa sigue en 0.0** |
| central TG.merma_diaria | re-sincronizada a las 08:48 (delete+insert, ids nuevos) |

## Detalle del cambio en StockReal (estación)

| fila | antes | después |
|------|-------|---------|
| prd 179 (MAXIMA, tan 14) | can=0.0, logusu=-1 | can=**53,773.496**, logusu=**1656**, logfch=2026-07-25 08:47:01 |
| prd 180 (SUPER, tan 15)  | can=0.0, logusu=-1 | can=**24,042.320**, logusu=**1656**, logfch=2026-07-25 08:47:01 |

Aprendizajes de semántica ControlGas:
- `logusu = -1` en el corte original → lo grabó el proceso automático de la consola.
- Al corregir: cambia `can`, `logusu` (usuario que corrigió: 1656) y `logfch`; `lognew` se conserva (creación). Exactamente lo que imita nuestro `/merma/corregir_fisico`.
- Se corrigió con el valor del corte de las 21:59 (turno 30), no con el cierre estimado
  (~53,124 MAXIMA / ~23,875 SUPER). Efecto: las ventas nocturnas aparecen dos veces en el
  reparto por turno → dif +1,003.62 el 14-jul t41 y −648.50 el 15-jul t11 (MAXIMA);
  +187.86 / −172.95 (SUPER). El ACUMULADO queda bien (neto ≈ merma real ~+355 MAXIMA);
  solo el desglose por turno queda con ese vaivén.

## La réplica corporativa NO se actualizó

`SG12.dbo.StockReal` central conserva `can=0.0` en ambas filas; su `logfch/lognew =
2026-07-15 00:47:25` (la sincronización nocturna que copió el corte original) y `fchsyn = NULL`.

**Pendiente de observar**: si la sincronización nocturna de ControlGas re-empuja filas ya
copiadas (revisar el 26-jul). Si mañana sigue en 0.0 → la sync central NO refresca filas
históricas corregidas, y la única forma de mantener el corporativo consistente es el doble
UPDATE que ya hace `/merma/corregir_fisico` (estación + SG12), o corregir SG12 a mano en
este caso.

## Conclusión operativa

Para corregir un corte dañado basta tocar **una sola tabla**: `StockReal` de la estación
(llave fch+codgas+codprd+nrotur+codtan, actualizando can/logusu/logfch). Ventas, compras,
telemetría y mediciones no se ven afectadas. El módulo de merma se pone al corriente con el
re-sync del rango. La duda abierta es únicamente la propagación a la réplica SG12 central.
