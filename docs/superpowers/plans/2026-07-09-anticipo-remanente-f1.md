# Remanente de anticipos parciales — Fase 1 (visibilidad + selección + movimiento)

**Goal:** una factura ligada a un anticipo con aplicación PARCIAL debe poder pagarse por su remanente: aparecer seleccionable en `add_payment`, y al agregarse a una requisición **moverse** (no duplicarse) del anticipo a la requisición, conservando su aplicación. La requisición muestra el descuento (`advance_total`). Facturas cubiertas al 100% por anticipo siguen bloqueadas, con etiqueta clara.

**Caso real:** FE-39664 (folio 2036), $415,476.96 con $41,288.60 aplicados del anticipo 1157 → restan $374,188.36.

**Fase 2 (fuera de alcance):** que la ejecución del pago transfiera el neto (saldo − aplicado): autorización de montos, conciliación y layouts bancarios.

## Tasks

1. **PaymentRequestInvoicesModel**: `get_active_invoice_by_uuid($uuid)` (fila activa + tipo del dueño + aplicado), `get_anticipo_parcial_info($invoice_ids)` (batch para el listado), `move_invoice_to_payment($invoice_id, $payment_id)` (UPDATE payment_request_id, status=0). Patch `add_invoice_to_payment()`: si el UUID vive bajo anticipo con remanente > 0 → mover en vez de insertar; si cubierta 100% → mensaje claro.
2. **PaymentRequestsModel::create_payment_with_invoices()**: misma lógica de mover/rechazar dentro de su transacción (protege también contra duplicados que hoy se insertaban ciegos).
3. **payment.php::payment_control_table()**: post-proceso de filas `en_orden_pago=1` → si su factura vive bajo anticipo: `anticipo_parcial`, `anticipo_aplicado`, `monto_restante` + statusLabel "Anticipo −$X · resta $Y" (parcial) o "Pagada con anticipo" (completa).
4. **payment.js** (tabla de add_payment): checkbox y drag habilitados para `anticipo_parcial=1`; fondo ámbar distintivo.
5. **PaymentRequestInvoicesModel::get_payment_summary_from_transactions()**: calcular `total_advances` (aplicaciones de las facturas activas de la requisición) — `payment_detail.html` ya pinta "Anticipos: −$X" y descuenta en `final_amount`.
6. Verificación CLI contra BD + lint; prueba en navegador la hace el usuario.

## Reglas

- Una fila activa por UUID, siempre: mover, jamás duplicar (los joins de la API multiplican filas).
- Facturas sin anticipos: comportamiento idéntico al actual (aplicado = 0 → nada cambia).
- Al mover, la aplicación del anticipo NO se toca (FK por invoice_id la sigue); `remove_invoice_from_payment` sobre la fila movida libera la aplicación (regla de ciclo de vida existente, coherente).
