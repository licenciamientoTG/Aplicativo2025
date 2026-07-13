# Formato imprimible del arqueo de caja (una hoja, con firmas)

**Fecha:** 2026-07-07
**Estado:** aprobado por el usuario (diseño conversado en sesión)

## Objetivo

Botón "Imprimir" junto a Regresar en `/arqueo/caja/{id}` que abre un formato
de UNA hoja con el resumen de hallazgos del arqueo, para firmarse por ambas
partes (cajero y auditor). Decisiones del usuario: solo resumen (sin desglose
de denominaciones) e imprime **lo guardado en BD** (no lo tecleado sin
guardar).

## Diseño

### 1. Botón en `views/arqueo/caja.html` (bloque `menutitle`)

Junto a Regresar. Si `caja.estado == 'pendiente'` (nunca guardada) → 
`alertify.error('Guarda el arqueo antes de imprimir.')` en vez de abrir.
Si no → abre `/arqueo/imprimir_caja/{{ caja.id }}` en `_blank`.

### 2. Endpoint `Arqueo::imprimir_caja($caja_id)`

`guard([PERM_AUDITOR, PERM_ADMIN])` + mismo candado `puede_capturar($caja)`
que la captura (403 si no es su caja). 404 si no existe. Carga `$sesion` y
`$vales` (`valesModel->by_caja`) y renderiza `caja_impresion.html` con
`compact('caja', 'sesion', 'vales')`. Si `caja.estado == 'pendiente'`
responde texto "La caja aún no tiene captura guardada." (defensa server-side
del mismo caso que la alerta).

### 3. Template `views/arqueo/caja_impresion.html` (standalone, sin layout)

Documento HTML completo propio (NO `extends base.html`): hoja carta,
tipografía compacta, `@media print` oculta el botón de reimprimir,
`window.print()` al cargar. Secciones:

1. **Encabezado:** "Arqueo de Caja — Dollar2Go", sesión (nombre + fecha),
   sucursal + caja, cajero, encargado de revisión, fecha/hora de impresión.
2. **Resumen de hallazgos** (tabla de 2 columnas concepto/importe, valores de
   `arqueo_cajas`): Total físico USD, Total físico MXN, Total vales (USD,
   MXN, gran total), Total arqueo M.N., Go Exchange USD, Go Exchange MXN,
   Costo promedio, Total en sistema, Diferencia USD, Diferencia MXN.
   Diferencias negativas en rojo.
3. **Resultado final** destacado en recuadro (rojo si < 0, con leyenda
   "FALTANTE"/"SOBRANTE"/"SIN DIFERENCIA").
4. **Vales** (solo si hay): tabla compacta número / fecha / concepto /
   USD / MXN.
5. **Observaciones:** 3 renglones en blanco (líneas punteadas) para anotar a
   mano.
6. **Firmas:** dos bloques lado a lado con línea superior para firma:
   "Entregó — Cajero" (debajo `cajero_nombre`) y "Revisó — Auditor"
   (debajo `encargado_revision`).

Números con `number_format(2,'.',',')`. Sin imágenes ni dependencias externas
(imprime rápido y sin red).

## Fuera de alcance

- Desglose de denominaciones (decisión explícita).
- PDF en servidor (se usa el diálogo de impresión del navegador).
- Detección de cambios sin guardar en la pantalla de captura.

## Verificación

`php -l` del controlador; lint Twig del template standalone y de caja.html;
revisión manual en navegador (abrir, verificar 1 hoja en vista previa de
impresión, firmas y montos correctos).
