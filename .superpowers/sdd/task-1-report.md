# Task 1 Report: Agregar `get_invoice_by_id()` a PaymentRequestInvoicesModel

## Qué se hizo

Se implementó el método `get_invoice_by_id($invoice_id): array|false` en la clase `PaymentRequestInvoicesModel`, insertado inmediatamente antes de `remove_invoice_from_payment()` (línea 1819 original, ahora línea 1835).

### Detalles de la implementación

- **Ubicación**: `_assets/models/PaymentRequestInvoicesModel.php`, líneas 1814–1829
- **Query**: Selecciona `id, payment_request_id, payment_authorized, folio` de `[TG].[dbo].[payment_request_invoices]` donde `id = ?` y `is_deleted = 0`
- **Comportamiento**: Devuelve un array asociativo con las 4 claves si la factura existe, o `false` si no existe
- **Documentación**: Incluye docblock explicando el propósito (validación para guard de requisición agrupada)

## Verificación

```
PHP syntax check: No syntax errors detected in _assets/models/PaymentRequestInvoicesModel.php ✓
```

## Commit

```
Commit hash: 85e706c
Message: Agregar get_invoice_by_id() a PaymentRequestInvoicesModel
```

## Notas

- El método es de solo lectura (SELECT), sin efectos en el flujo de borrado existente
- No se modificó ningún otro archivo
- No se implementó la Tarea 2 (controller)
