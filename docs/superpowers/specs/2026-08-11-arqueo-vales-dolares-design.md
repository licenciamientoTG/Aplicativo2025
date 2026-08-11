# Vales en dólares — conversión correcta a MXN (Arqueo D2GO)

## Problema

En `/arqueo/caja/{id}` y `/arqueo/concentrado/{sesion_id}`, un vale capturado en
la columna Dólares se trata incorrectamente:

1. **Se suma 1:1 como si fuera MXN.** `gran_total_vales_mxn = vales_dolares + vales_mxn`
   (sin aplicar `costo_promedio`), tanto en `_assets/js/arqueo.js` (línea 141)
   como en `Arqueo::calcular_totales_caja()` (`_assets/controllers/arqueo.php:1078`).
   Este valor alimenta la columna "Vales Autorizados" (G) del concentrado.
2. **No entra al Resumen del arqueo ni al Resultado Final de la caja.**
   `arqueoMxn` / `total_arqueo_mxn` solo suman `fisico_mxn + vales_mxn`;
   `usdCostoPromedio` / la diferencia en dólares solo consideran el efectivo
   físico, nunca `vales_dolares`. El vale en USD queda invisible en el panel
   de la caja individual, aunque sí "aparece" (mal convertido) en el
   concentrado.

## Decisión

Un vale en dólares se trata igual que el efectivo físico en dólares: se
convierte a MXN multiplicando por `costo_promedio` antes de sumarse a
cualquier total en pesos. Los vales no forman parte del "Arqueo físico"
(que refleja solo lo contado en las denominaciones), pero sí de "Total
Dólares" / "Total Moneda Nacional" y de ahí en adelante.

## Fórmulas corregidas

Aplican igual en JS (`arqueo.js`) y PHP (`calcular_totales_caja`):

```
usd_costo_promedio = (fisico_usd + vales_usd) * costo_promedio
arqueo_mxn         = fisico_mxn + vales_mxn
total_arqueo        = usd_costo_promedio + arqueo_mxn
total_sistema        = go_exchange_usd * costo_promedio + go_exchange_mxn
resultado_final      = total_arqueo - total_sistema

gran_total_vales_mxn = vales_usd * costo_promedio + vales_mxn
```

Notas:

- `fisico_usd`, `fisico_mxn` (solo denominaciones) y las filas "Diferencias"
  (`dif_usd = fisico_usd - go_usd`, `dif_mxn = arqueo_mxn - go_mxn`) se
  mantienen informativas, física-only, sin vales — no cambian de fórmula.
- `resultado_final` deja de calcularse como `dif_mxn + dif_usd * costo_promedio`
  y pasa a calcularse directamente como `total_arqueo - total_sistema`
  (matemáticamente equivalente cuando no hay vales en USD; con vales en USD
  el vale se cuenta exactamente una vez, vía `usd_costo_promedio`).
- `gran_total_vales_mxn` (mostrado en la tabla de Vales de la caja, y usado
  como columna G "Vales Autorizados" en el concentrado) usa la misma
  conversión. Como el concentrado (`Arqueo::concentrado()`) lee
  `gran_total_vales_mxn` ya calculado y guardado en BD, se corrige
  automáticamente sin tocar el método `concentrado()`.

## Alcance del cambio

1. **`_assets/js/arqueo.js`** — función `recalcular()`: aplicar las fórmulas
   de arriba para el cálculo en vivo del panel de la caja.
2. **`_assets/controllers/arqueo.php`** — método privado `calcular_totales_caja()`:
   misma corrección server-side (fuente de verdad al guardar).
3. **`views/arqueo/caja.html`** — sin cambios de estructura; las mismas
   celdas (`r_usd_costo_promedio`, `r_arqueo_mxn`, `r_total_arqueo`,
   `r_resultado`, `gran_total_vales_mxn`) ya existen y solo reciben los
   nuevos valores calculados.
4. **`_assets/controllers/arqueo.php` → `concentrado()`** — sin cambios de
   código; se beneficia automáticamente al leer `gran_total_vales_mxn`
   corregido desde `arqueo_cajas`.

No se modifica el esquema de base de datos ni los modelos (`ArqueoValesModel`,
`ArqueoCajasModel`) — solo la aritmética de agregación.

## Fuera de alcance

- Recalcular sesiones ya cerradas/guardadas con vales en USD previos al fix
  (los totales quedarán como se guardaron; si se requiere corregir histórico,
  es una tarea aparte de backfill).
- Cambios a la vista `concentrado.html` — la columna G ya lee el valor
  corregido sin cambios de plantilla.
