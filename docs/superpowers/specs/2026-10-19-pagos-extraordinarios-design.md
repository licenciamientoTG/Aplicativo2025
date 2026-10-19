# Pagos extraordinarios — diseño

Fecha: 2026-10-19. Origen: `FORMATO SOLICITUD EXTRA-MKT (1013).xlsx` (hojas EXTRA INDIVIDUAL y EXTRA MULT).

## Intención
Reemplazar el Excel de solicitud de pago extraordinario por un flujo en el sistema: un usuario con acceso captura la solicitud, Cuentas por Pagar da VoBo, Administración y Finanzas autoriza y Tesorería la paga.

## Metas
- Capturar solicitudes individuales y múltiples con el mismo modelo (encabezado + renglones; la individual es un solo renglón).
- Dos aprobaciones en orden: CxP (VoBo) → Admón y Finanzas (Autorización). Después Tesorería registra el pago.
- Historial, adjuntos, impresión con el layout del Excel y reporte.

## No metas
- No se integra con requisiciones (`payment_requests`) ni con FacturasRecibidas.
- La firma del jefe de área no se modela en el sistema.
- Sin catálogo de centros de costos por ahora (texto libre).

## Decisiones del usuario
- Aprobadores: CxP → Admón y Finanzas.
- Centro de costos: texto libre.
- Pago múltiple: un solo registro de pago; la fecha de pago por renglón es solo la fecha requerida.
- Empresas: `SG12.dbo.Empresas` (cruzar nombres del Excel y avisar los que no coincidan).

## Decisiones propias (a confirmar)
- Controlador aparte `ExtraPayments`, router explícito `routes/extrapayments.php`, URLs `/pagos-extraordinarios/...`.
- Entrada de sidebar "Pagos extraordinarios".
- Permisos 103–107 (verificar libres en BD): 103 crear/ver propias, 104 VoBo CxP, 105 autoriza Admón, 106 paga Tesorería, 107 ver todas.
- Rechazo regresa al solicitante con comentario; al reenviar se invalidan las aprobaciones previas.

## Datos (TG)
- `extra_payment_requests`: folio, fecha, usuario, departamento_id, motivo_id, motivo_otros, tipo, total, estatus.
- `extra_payment_lines`: solicitud, empresa_cod, centro_costos, proveedor, factura, fecha_factura, orden_compra, descripcion, monto, fecha_pago_requerida, comentario_urgencia.
- `extra_payment_reasons`: catálogo (pocos días de crédito; solicitud de los Sr. Díaz; viaje esporádico; corte de servicios o créditos; no se programó con tiempo; otros).
- `extra_payment_approvals`: solicitud, paso, permiso, usuario, decisión, comentario, fecha.
- `extra_payment_documents`: adjuntos (factura, cotización, comprobante).
- `extra_payment_payments`: fecha real, cuenta bancaria, referencia, comprobante, monto.
- `extra_payment_audit_log`.

Estatus: Pendiente VoBo CxP → Pendiente Admón → Autorizada → Pagada; además Rechazada y Cancelada.

## Pantallas
Mis solicitudes · Nueva/editar (renglones dinámicos, total calculado) · Detalle (firmas, adjuntos, historial) · Bandejas por rol (CxP, Admón, Tesorería) · Imprimir (layout del Excel) · Reporte con export a Excel. Correo al siguiente responsable en cada paso (patrón de destinatarios de pagos).

## Entrega por etapas
1. SQL, modelos y transiciones de estatus.
2. Controlador, rutas y captura.
3. Bandejas de aprobación y pago, correos.
4. Adjuntos, impresión y reporte.
5. Sidebar, documentación en `docs/` y pruebas manuales.
