# Plan Maestro — Pago a Proveedores (combustible)

**Fecha:** 2026-07-25 (revisado 2026-07-29 con respuestas a las preguntas abiertas)
**Estado:** Aprobado en lo general — no se ha implementado nada todavía. Listo para diseñar Fase 0 a detalle.

## Visión

Reemplazar el flujo manual actual (escaneos por correo + Excel de compras + descarga manual de facturas + subida manual de XML a ControlGas) por un ciclo dentro del sistema:

1. La estación captura su recepción en ControlGas (como hoy) → `MovimientosTan`.
2. Las facturas llegan por correo y se importan automáticamente (como hoy) → `TG.dbo.FacturasRecibidas`, **ahora también con el XML**.
3. El sistema concilia recepción ↔ factura (con bandera Petrotal cuando aplica).
4. La estación entra a un portal, ve sus recepciones, sube el escaneo de su remisión y **descarga el XML** que le corresponde para subirlo a ControlGas.
5. El sistema verifica en ControlGas que el UUID ya quedó en `DocumentosC.satuid` y cierra el ciclo; de ahí alimenta el módulo de pagos existente (`/payment/...`).
6. En compras vía Petrotal, la deuda se sigue contra el **proveedor real** (ej. Tesoro), aunque en ControlGas se suba la factura de Petrotal.

## Principios / restricciones

- **SG12 y las BD de estación (ControlGas) son SOLO LECTURA.** Todo lo propio vive en `TG`.
- La subida del XML a ControlGas la sigue haciendo la estación con su cliente ControlGas; el sistema solo se lo entrega y verifica después.
- El módulo de pagos existente (`payment.php`, `payment_requests`, autorizaciones) no se rehace; estas fases lo alimentan.
- Convivencia con el Excel de compras durante la transición (no se apaga hasta que Fase 1 esté probada).

## Estado actual relevante (ya existe)

| Pieza | Dónde | Nota |
|---|---|---|
| Importación de facturas desde correo | `CorreoFactruras.py` (servidor) + `ApiER/api/modelos/ImportadorFacturas.py` | Guarda PDF y datos; **no guarda XML** |
| Lectura de recepciones | `MovimientosTanModel::sp_obtener_recepciones_combustible` (OPENQUERY, tiptrn=3) | Ya trae JOIN a `Documentos`/`DocumentosC` y parsea `txtref` (`@F:` factura, `@R:` remisión, `@V:` vehículo) |
| Búsqueda de documentos por UUID | `MovimientosTanModel::buscarPorUUID` | Sirve para verificar cierre del ciclo |
| Conciliación factura↔movimiento | `TG.dbo.FacturasMovimientosTanques` + `supply.php::fuel_reconciliation` | Ya tiene `TipoOperacion` (1=Directa, 2=Con Petrotal) y bandera `Petrotal` |
| Usuario ligado a estación | `validate.inc.php` → `sp_consulta_usuario_estacion` → `$_SESSION` con `IdEstacion` | Base para el portal de estaciones |
| Módulo de pagos | `payment.php` + `payment_requests` / `payment_request_invoices` | Requisiciones, autorizaciones, ejecución |
| Petrotal (otros usos) | `ERComprasPetrotal` (estado de resultados), `/accounting/remisiones_petrotal` (imprime remisiones) | No persisten la relación de deuda |

---

## Fase 0 — Guardar y servir los XML (prerequisito de todo lo demás)

**Objetivo:** que cada factura en `FacturasRecibidas` tenga su XML en disco y localizable.

- Modificar `CorreoFactruras.py` (servidor) para guardar el adjunto `.xml` junto al PDF, mismo nombre base. Para MCG, extraer el XML del ZIP Sovos.
- Columnas nuevas en `TG.dbo.FacturasRecibidas`: `RutaXml`, `NombreXml` (NULL si el proveedor no adjuntó XML).
- Ajustar `ImportadorFacturas.py` para poblarlas; el "mover/renombrar PDF" existente debe mover también el XML.
- En AplicativoPhp: endpoint de descarga del XML (con permiso), análogo a como se sirven los PDF.
- **Carga manual/masiva de XML** (mismo patrón que ya se usó con las facturas): pantalla donde abastos sube uno o varios XML para las facturas que llegaron sin él. El sistema lee el UUID del XML y lo liga a su registro en `FacturasRecibidas`; si el UUID no existe, se reporta como no conciliado.
  - Esta es la vía de escape para proveedores que no adjuntan XML y para el histórico anterior a Fase 0. Hace que el resto del plan no dependa de que el 100% de los correos traiga XML.
- Opcional: al tener XML, validar/completar los datos extraídos del PDF (el XML es más confiable que el texto del PDF).

**Supuesto de trabajo:** se asume que todos los proveedores adjuntan XML; los que no, se irán detectando en operación y se cubren con la carga manual. No se bloquea el diseño esperando el censo de proveedores.

**Pregunta abierta:** ¿el buzón conserva correos históricos para re-procesar y hacer backfill de XML? (si no, la carga manual también cubre el histórico).

## Fase 1 — Conciliación recepción ↔ factura (sustituye la hoja "compras" del Excel)

**Objetivo:** una vista central donde cada recepción de combustible tenga su estado: *sin factura → factura asignada → XML entregado → subida a ControlGas*.

- Construir sobre `fuel_reconciliation` y `FacturasMovimientosTanques` (auditar qué tanto está en uso hoy y qué le falta).
- Snapshot/caché de recepciones en `TG` (patrón ya probado en el módulo de merma) para no depender de OPENQUERY en línea con 40+ estaciones.
- Match automático sugerido factura↔recepción: proveedor + litros + fecha con tolerancias; **la confirmación siempre la hace abastos** (nunca se concilia solo). Cuando la estación ya subió a ControlGas, el UUID (`satuid`) da match exacto.
  - Como la confirmación es manual en el 100% de los casos, el diseño debe optimizar el esfuerzo de abastos, no la automatización: sugerencia precargada y ordenada por confianza, confirmar en un clic, **confirmación en lote** de las coincidencias exactas, y las dudosas o sin candidato separadas en su propia bandeja.
- Recepciones marcadas como **"sin factura esperada"** (ver más abajo) no entran a la bandeja de conciliación.
- Captura del número de remisión y del escaneo (mientras no exista el portal, lo sube abastos; después, la estación en Fase 3).
- Reporte "compras": comprado (recepciones) vs facturado (FacturasRecibidas) vs pagado (payment_requests), por estación/proveedor/periodo.

## Fase 2 — Petrotal: deuda hacia el proveedor real

**Objetivo:** cuando se compra vía Petrotal, en ControlGas queda la factura de Petrotal, pero el seguimiento de pago es contra el proveedor real.

- Modelo de datos: en la operación "Con Petrotal" hay dos CFDI — proveedor real → Petrotal, y Petrotal → razón social. Extender `FacturasMovimientosTanques` (o tabla puente) para ligar ambos: `FacturaProveedorRealId` + `FacturaPetrotalId` sobre la misma recepción.
- Reglas de deuda:
  - La deuda externa (la que entra a requisiciones de pago) es la del **proveedor real**.
  - La factura de Petrotal es intercompañía: se excluye de deuda externa para no duplicar, pero queda ligada para conciliación y para el margen (ya existe `ERComprasPetrotal` para el ER).
- Vistas de deuda/facturas vencidas deben resolver a través de esta liga (mostrar "Tesoro (vía Petrotal)").
- **Confirmado:** la factura del proveedor real → Petrotal llega al **mismo buzón**, así que ambos CFDI ya están (o estarán) en `FacturasRecibidas`. No se requiere una vía de importación aparte; el trabajo es de conciliación: distinguir cuál de las dos facturas es la del proveedor real y cuál la de Petrotal, y ligarlas a la misma recepción.
- Pendiente de diseño: migración de los registros existentes con `Petrotal = 1` bajo el esquema actual de una sola factura (`FacturaProveedorId`).

## Fase 3 — Portal de estaciones

**Objetivo:** que la estación sea autosuficiente: ver sus recepciones, subir su remisión escaneada, descargar su XML.

- Nuevo controlador (p. ej. `station_portal.php`) + permiso nuevo; **filtrado forzoso** por el `IdEstacion` de la sesión (ya disponible desde el login).
  - **Confirmado:** las estaciones ya tienen usuarios activos, porque hoy usan el tabulador. No hay trabajo de alta masiva; solo asignar el permiso nuevo.
  - `validate.inc.php:42-45` pone `IdEstacion`/`Estacion` en `$_SESSION['tg_user']` vía `sp_consulta_usuario_estacion`, **pero solo si el SP devuelve fila**. El controlador debe negar acceso cuando la llave no exista, en lugar de asumirla (un usuario corporativo sin estación no debe ver un listado sin filtrar).
- Vistas:
  - **Mis recepciones**: lista desde el snapshot de Fase 1, con estado y semáforo (falta remisión / falta factura / XML listo para descargar / ya subida a ControlGas).
  - **Subir remisión**: escaneo (PDF/imagen) + número de remisión → tabla en TG + archivo en servidor. Sustituye el correo de escaneos.
  - **Descargar XML**: habilitado cuando la conciliación de Fase 1 ya asignó factura a esa recepción.
- Notificación (correo o campana) a la estación cuando su XML esté disponible.

## Fase 4 — Cierre del ciclo y verificación

**Objetivo:** confirmar automáticamente que la estación sí subió el XML a ControlGas.

- Job programado (patrón cron CLI ya usado en merma; el cron por HTTP no sirve porque `index.php` exige sesión) que lee `DocumentosC.satuid` de cada estación y lo cruza con los UUID entregados → marca la recepción como "subida a ControlGas".
- Alertas de rezago: recepciones con XML entregado y sin subir después de N días; recepciones sin factura después de N días.
- **Recepciones sin CFDI (remisiones puras):** se marcan como **"sin factura esperada"** para que no ensucien las alertas de rezago ni la bandeja de conciliación. Se mantiene la regla actual: no van a pagos y siguen apareciendo en vencidas. Pendiente de definir en el spec de Fase 1: quién pone la marca (abastos, manual) y si es reversible.
- Con esto, `fuel_payments` (que ya lee los documentos de compra vía API) queda alimentado sin captura manual.

---

## Orden y dependencias

```
Fase 0 (XML) ──► Fase 1 (conciliación) ──┬──► Fase 2 (Petrotal)
                                          └──► Fase 3 (portal estaciones) ──► Fase 4 (cierre ciclo)
```

- Fase 2 y Fase 3 son independientes entre sí; pueden ir en paralelo.
- Cada fase se diseña a detalle (spec propio) y se implementa por separado.

## Preguntas abiertas (resolver antes de diseñar cada fase)

**Resueltas (2026-07-29):**

1. ~~¿El buzón conserva histórico para backfill de XML?~~ → Sin confirmar, pero **deja de bloquear**: la carga manual/masiva de XML de Fase 0 cubre tanto el histórico como los faltantes.
2. ~~¿Qué proveedores NO adjuntan XML?~~ → Aún no se sabe. Se asume que todos lo adjuntan y se detectan en operación; los faltantes se resuelven con la carga manual.
3. ~~¿La factura proveedor-real→Petrotal llega al mismo buzón?~~ → **Sí**, mismo buzón. Fase 2 no necesita otra vía de importación.

4. ~~¿Las estaciones ya tienen usuarios con `IdEstacion`?~~ → **Sí**, ya existen porque usan el tabulador. Solo falta asignarles el permiso del portal.
5. ~~¿Quién confirma el match factura↔recepción?~~ → **Abastos siempre**, nunca automático. El diseño debe minimizar el esfuerzo (confirmación en lote, orden por confianza).
6. ~~¿Recepciones sin CFDI?~~ → Se marcan **"sin factura esperada"**; se excluyen de alertas y de la bandeja. Se mantiene: no van a pagos, sí aparecen en vencidas.

**Pendientes:**

- ¿El buzón conserva histórico para backfill de XML? (no bloquea: la carga manual lo cubre)
- ¿Quién marca "sin factura esperada" y es reversible? (se resuelve en el spec de Fase 1)
- Migración de los registros existentes con `Petrotal = 1` al esquema de dos facturas (se resuelve en el spec de Fase 2)
