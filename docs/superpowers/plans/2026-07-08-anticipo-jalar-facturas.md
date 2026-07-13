# Anticipos: jalar facturas disponibles (rediseño del ligado) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reemplazar el ligado de anticipos a facturas: en lugar de buscar facturas que ya viven en otras requisiciones, el anticipo "jala" documentos de compra **disponibles** (misma fuente que `add_payment`), los inserta como sus propias filas en `payment_request_invoices` (con `payment_request_id` = el anticipo) y crea la aplicación con su monto. Una factura cubierta por un anticipo desaparece automáticamente de `add_payment` (su UUID queda "en orden de pago"), lo que resuelve por construcción la regla "factura en pago ↔ anticipo no se mezclan".

**Architecture:** PHP MVC sin framework de tests. UI: página dedicada `/payment/aplicar_anticipo/{id}` (copia adaptada de `views/payment/add_payment.html`). Backend: endpoint de documentos disponibles (reutiliza el curl a la API `estacion_documentos_compra` — SIN cambios en ApiER) + método transaccional `attach_invoices_to_anticipo()`.

## Contexto y decisiones de diseño (acordadas con el usuario 2026-07-08)

1. **Fuente de datos**: documentos de compra del proveedor del anticipo vía API `http://192.168.0.109:82/api/estacion_documentos_compra/` (igual que `payment_control_table()`), filtrando en el controlador: `satuid` no vacío y `en_orden_pago == 0`. `provider_cod` de `payment_requests` es el mismo código ControlGas que espera la API (SG12 = corporativo ControlGas).
2. **Al confirmar**: INSERT en `payment_request_invoices` bajo el anticipo (mismos campos que `create_payment_with_invoices()`: folio=nro, invoice_number=Factura, codgas, amount=total efectivo, uuid=satuid) + INSERT en `anticipo_invoice_applications` con el monto aplicado. NO se toca `monto_total`, `status` ni autorizaciones del anticipo.
3. **Parciales permitidos**: monto aplicado editable, precargado con el total, tope = min(total factura, saldo restante del anticipo). Status de la fila: 2 (Pagado) si monto == total, 3 (Pago Parcial) si menor.
4. **Reemplazo total**: el modal viejo y su fuente (`get_invoices_pendientes_by_provider`, `apply_anticipo_to_invoices`) se eliminan.
5. **Regla de ciclo de vida ya existente** (se conserva): quitar una factura del anticipo (`remove_invoice_from_payment`) o eliminar una requisición libera las aplicaciones y regresa saldo.
6. **Prerrequisito operativo**: desplegar por SFTP el cambio ya hecho en `ApiER/api/modelos/Documentos_estaciones.py` (joins con `AND is_deleted = 0`). Sin eso, documentos liberados siguen ocultos. **Esta fase no requiere ningún cambio adicional en la API.**

### Análisis del remanente (fase futura, NO implementar ahora)

Cuando la aplicación es parcial, el remanente = `amount - SUM(monto_aplicado)` de esa fila. La factura vive bajo el anticipo y no aparece en `add_payment`. Caminos para el remanente, en orden recomendado:

- **(a) Cubrir con otro anticipo del mismo proveedor**: en la página de aplicar del anticipo B, una sección secundaria "Facturas con remanente en otros anticipos" (query directo a `payment_request_invoices` bajo `tipo=1` con remanente > 0, mismo proveedor). Inserta solo la aplicación (la fila ya existe); soporta N aplicaciones por factura.
- **(b) Mandar remanente a requisición normal**: mover la fila (`UPDATE payment_request_id` del anticipo → la requisición nueva), conservando las aplicaciones (la FK es por `invoice_id`, sobrevive). El pago de esa requisición debe descontar lo aplicado — depende del pendiente estructural "descuento real de anticipos al pagar" (`total_advances` en `get_payment_summary_from_transactions`). No duplicar la fila: dos filas activas con el mismo UUID duplican renglones en los joins de la API.

Ninguno de los dos entra en este plan; (b) requiere primero el descuento real al pagar.

## Global Constraints

- No romper el flujo de requisiciones normales: todo lo nuevo vive en métodos/rutas propias de anticipos.
- Escrituras multi-tabla dentro de `beginTransaction()/commit()/rollback()`.
- Permisos: ver la página = sesión activa (como `anticipo_detail`); confirmar el ligado = `authorized(68)` (Tesorería), igual que el resto de acciones de anticipos.
- El anticipo debe estar **Pagado (status 2)** para ligarle facturas (las facturas justifican dinero ya transferido). Validar en backend.
- Validar saldo: suma de montos a aplicar ≤ saldo disponible (`get_saldo_disponible`).
- Validar duplicados: rechazar documentos cuyo UUID ya exista activo (`invoice_exists_by_uuid` filtra `is_deleted = 0`).
- Textos de UI en español, alertify para confirmaciones, patrones visuales de `add_payment`.

## File Structure

| Archivo | Acción | Responsabilidad |
|---|---|---|
| `_assets/controllers/payment.php` | Modificar | `aplicar_anticipo($id)` (render), `anticipo_documentos_table()` (POST JSON), `apply_anticipo_documentos()` (POST confirm); eliminar `get_invoices_pendientes_by_provider()` y `apply_anticipo_to_invoices()` |
| `_assets/models/PaymentRequestsModel.php` | Modificar | `attach_invoices_to_anticipo($anticipo_id, $documents, $user_id)` transaccional |
| `_assets/models/PaymentRequestInvoicesModel.php` | Modificar | Eliminar `get_pending_by_provider()` |
| `views/payment/aplicar_anticipo.html` | Crear | Página copia adaptada de `add_payment.html` |
| `views/payment/anticipo_detail.html` | Modificar | Botón "Aplicar a Facturas" → link a la página nueva; quitar include del modal |
| `views/payment/modals/aplicar_anticipo.html` | Eliminar | Modal reemplazado |
| `_assets/js/payment.js` | Modificar | Eliminar JS del modal viejo (`abrirModalAplicarAFacturas`, `renderFacturasPendientesAplicar`, `facturasAplicarVisibles`, filtro de folios del modal, `confirmarAplicarAnticipo`, handlers delegados) — el JS de la página nueva vive inline en la vista (patrón `add_payment.html`) |

---

### Task 1: Endpoint `anticipo_documentos_table()`

**Files:** `_assets/controllers/payment.php`

- [ ] **Step 1**: Agregar método POST que recibe `anticipo_id`, `fromDate`, `untilDate`, `codgas` (opcional). Valida que el anticipo exista, `tipo=1`. Hace el mismo curl de `payment_control_table()` con `proveedor = $anticipo['provider_cod']`. Filtra filas: `satuid` no vacío, `en_orden_pago == 0`. Devuelve JSON limpio por fila: `nro, Factura, codgas, gasolinera, fecha, fecha_vencimiento (fecha_vencimiento_credito ?? fechaVto), total_fac, total_mostrar (Total de FacturasRecibidas si existe, si no total_fac), satuid, tiene_factura_recibida`.
- [ ] **Step 2**: Verificar con script CLI puntual (patrón `$_SERVER['REQUEST_URI']/DOCUMENT_ROOT` + curl directo) que para el anticipo 1157 (Premiergas 71) regresa los 13 documentos liberados de la ex-1226. Requiere el deploy SFTP de ApiER hecho.
- [ ] **Step 3**: Commit.

### Task 2: Modelo `attach_invoices_to_anticipo()`

**Files:** `_assets/models/PaymentRequestsModel.php`

- [ ] **Step 1**: Firma `attach_invoices_to_anticipo(int $anticipo_id, array $documents, int $user_id): array`. Cada `$documents[]`: `nro, Factura, codgas, total, satuid, fecha_vencimiento, monto_aplicar`. Validaciones (fuera de la transacción): anticipo existe, `tipo=1`, `status=2` (Pagado), `monto_aplicar > 0` y `<= total` por documento, `SUM(monto_aplicar) <= get_saldo_disponible()`, ningún `satuid` activo ya en `payment_request_invoices` (`invoice_exists_by_uuid`).
- [ ] **Step 2**: Transacción: por documento, INSERT en `payment_request_invoices` (`payment_request_id = $anticipo_id`, `status` = 2 si monto==total, 3 si parcial, `is_debit_note=0`) capturando el id insertado; INSERT en `anticipo_invoice_applications` (`anticipo_id, invoice_id, monto_aplicado, fecha_aplicacion=GETDATE, aplicado_por`). Rollback ante cualquier fallo. Return `['success', 'message', 'total_aplicado', 'facturas_ligadas']`.
- [ ] **Step 3**: Verificar con script CLI: attach de 1 documento de prueba → filas creadas, saldo baja; luego `remove_invoice_from_payment` de esa fila → aplicación liberada, saldo regresa (valida integración con la regla de ciclo de vida). Limpiar datos de prueba.
- [ ] **Step 4**: Commit.

### Task 3: Página `aplicar_anticipo.html` + render

**Files:** `views/payment/aplicar_anticipo.html` (copia adaptada de `add_payment.html`), `_assets/controllers/payment.php`

- [ ] **Step 1**: `aplicar_anticipo($anticipo_id)` en el controlador: carga el anticipo (`get_request_by_id`, validar tipo=1; si status != 2 mostrar la página con aviso y sin botón de confirmar), `get_anticipo_summary` (saldo), nombre proveedor/empresa, estaciones para el filtro. Render de la vista.
- [ ] **Step 2**: Crear la vista copiando `add_payment.html` y adaptando: (a) header = card del anticipo (id, proveedor, empresa, monto original, aplicado, **saldo disponible**); (b) filtros: fechas default últimos 60 días + estación — SIN selector de proveedor ni empresa (fijos del anticipo); (c) tabla DataTable contra `anticipo_documentos_table` (POST con anticipo_id); (d) **conservar el filtro masivo por folios** (ya viene en la copia — re-apuntar al id de la tabla nueva); (e) selección con click en cualquier parte de la fila; (f) panel de seleccionadas con input de monto editable precargado con `total_mostrar`, cap dinámico = saldo restante; (g) total a aplicar + contador + alerta si excede saldo; (h) botón "Aplicar anticipo" → `apply_anticipo_documentos` (visible solo con `authorized(68)`). Quitar de la copia todo lo de notas de crédito/cargo, fecha programada de pago y `generate_payment`.
- [ ] **Step 3**: Ruta: verificar que `/payment/aplicar_anticipo/{id}` resuelve (el front controller ya rutea `[controller]/[method]/[params]` — no requiere cambios en index.php).
- [ ] **Step 4**: Commit.

### Task 4: Endpoint `apply_anticipo_documentos()`

**Files:** `_assets/controllers/payment.php`

- [ ] **Step 1**: POST JSON `{anticipo_id, documentos: [{nro, Factura, codgas, total, satuid, fecha_vencimiento, monto_aplicar}]}`. Valida sesión, `authorized(68)`, payload no vacío. Llama `attach_invoices_to_anticipo()`. Respuesta JSON con resumen (n facturas, total aplicado, saldo restante).
- [ ] **Step 2**: Prueba end-to-end en navegador con el 1157: pegar folios de la ex-1226 en el filtro, seleccionar, aplicar (una completa y una parcial), verificar: saldo del anticipo baja, panel expandible del anticipo muestra las facturas con archivo, los documentos desaparecen de `add_payment`, y el child row del anticipo enlaza requisición = el propio anticipo.
- [ ] **Step 3**: Commit.

### Task 5: Recableado y limpieza del flujo viejo

**Files:** `views/payment/anticipo_detail.html`, `views/payment/modals/aplicar_anticipo.html` (eliminar), `_assets/js/payment.js`, `_assets/controllers/payment.php`, `_assets/models/PaymentRequestInvoicesModel.php`

- [ ] **Step 1**: En `anticipo_detail.html`: botón "Aplicar a Facturas" → `<a href="/payment/aplicar_anticipo/{{ anticipo.id }}">`; quitar `{% include 'views/payment/modals/aplicar_anticipo.html' %}`.
- [ ] **Step 2**: Eliminar `views/payment/modals/aplicar_anticipo.html`.
- [ ] **Step 3**: En `payment.js` eliminar: `facturasPendientesAplicar`, `folioFilterSetAplicar`, `abrirModalAplicarAFacturas`, `normalizeFolioAplicar`, `facturasAplicarVisibles`, `renderFacturasPendientesAplicar`, `toggleFacturaAplicar`, `toggleFolioFilterAplicar`, `clearFolioFilterAplicar`, `parseFolioFilterAplicar`, `actualizarTotalAplicar`, `confirmarAplicarAnticipo` y sus handlers delegados (`#tablaFacturasPendientesAplicar`, `#buscarFacturaAplicar`, `#folioFilterInputAplicar`, `#selectAllFacturasAplicar`). Verificar con grep que no queden referencias.
- [ ] **Step 4**: En `payment.php` eliminar `get_invoices_pendientes_by_provider()` y `apply_anticipo_to_invoices()`; en `PaymentRequestInvoicesModel.php` eliminar `get_pending_by_provider()`. Si `register_anticipo_applications()` queda sin callers (grep), eliminarla también.
- [ ] **Step 5**: `php -l` de los 2 PHP + `node --check` de payment.js + smoke de la página. Commit.

### Task 6: Documentación y memoria

- [ ] **Step 1**: Actualizar memoria del proyecto (flujo-anticipos): nuevo modelo de ligado (facturas viven bajo el anticipo), remanentes = fase futura con las 2 rutas analizadas.
- [ ] **Step 2**: Commit final.

## Verificación global

- Flujo normal de facturas intacto: crear requisición en `add_payment`, autorizar, conciliar — sin cambios de comportamiento.
- Documento ligado a anticipo NO aparece en `add_payment` ni en facturas vencidas.
- Quitar factura del anticipo (botón del detalle o eliminación) libera la aplicación y el documento reaparece en `add_payment`.
- Anticipo no pagado (status 0/1): la página carga pero no permite aplicar.
