# Auto-agrupar requisición al autorizar (Tesorería)

## Contexto

El ciclo de pagos a proveedores tiene este flujo:

1. **Abastos** (permiso 66) revisa requisiciones del día con PDFs completos y ejecuta "Mandar pagos" (`send_to_payments()`, payment.php:2273). Esto llama a `PaymentAccountingGroupsModel::auto_group_by_date()`, que agrupa por empresa (`emp_cod`) las requisiciones pendientes sin grupo (`accounting_group_id IS NULL`), y luego envía un correo a Tesorería con las requisiciones agrupadas que tienen PDFs completos.
2. **Tesorería** (permiso 68) recibe el correo, entra al sistema y autoriza facturas individuales vía `authorize_payment_execution()` (payment.php:1775) → `PaymentRequestInvoicesModel::authorize_invoices_for_payment()` (línea 409).

**Problema identificado:** no existe ninguna validación que impida a Tesorería autorizar facturas de una requisición que **nunca fue agrupada** por Abastos (es decir, `accounting_group_id IS NULL`). Esto significa que Tesorería puede autorizar y pagar una requisición que Abastos no ha revisado ni mandado por correo todavía, saltándose el paso de control de Abastos. Adicionalmente, esa requisición nunca quedaría asociada a un archivo contable (`payment_accounting_groups`), rompiendo trazabilidad.

## Decisión de diseño

Cuando Tesorería autoriza facturas de una requisición sin grupo contable, el sistema **agrupa automáticamente** esa requisición en ese momento (en vez de bloquear la autorización o ignorar el problema). Se reutiliza la lógica de agrupación existente (`auto_group_by_date()`), no se crea un mecanismo de agrupación paralelo. Se muestra un aviso a Tesorería para que sepa que actuó sobre algo que Abastos no había enviado.

Se descartaron:
- **Bloquear duro**: obligar a Tesorería a esperar a que Abastos agrupe primero. Rechazado porque añade fricción operativa sin necesidad — Tesorería ya tiene permiso para autorizar, y bloquear introduce un nuevo punto de fallo/espera.
- **Grupo individual aislado** (uno por requisición): rechazado porque rompe la consistencia del esquema actual de "un grupo contable = una empresa por día" y complicaría la conciliación contable posterior.

## Comportamiento esperado

### Backend: `authorize_payment_execution()` (payment.php:1775)

Antes de llamar a `authorize_invoices_for_payment()`:

1. Cargar la requisición (`$payment`) y verificar `$payment['accounting_group_id']`.
2. Si es `NULL`:
   - Invocar la misma lógica de `PaymentAccountingGroupsModel::auto_group_by_date()`, acotada a la empresa (`emp_cod`) de esta requisición puntual — reutilizando un `accounting_group` del día para esa empresa si ya existe (creado por una corrida previa de "Mandar pagos" o por otra auto-agrupación), o creando uno nuevo si no existe ninguno para esa empresa+fecha.
   - Esto debe reutilizar la función/consulta existente, no duplicar la lógica de generación de `accounting_id`.
3. Continuar con la autorización de facturas como hoy.
4. En la respuesta JSON añadir:
   - `auto_grouped: true|false`
   - Si `true`: `accounting_id` del grupo usado/creado.

### Frontend: payment.js

Cuando la respuesta de `/payment/authorize_payment_execution` incluya `auto_grouped: true`, mostrar un aviso (alertify) tipo:

> "Esta requisición no había sido enviada por Abastos — se agrupó automáticamente en el archivo contable {accounting_id} al autorizarla."

No bloquea el flujo ni requiere confirmación previa; es informativo, después de que la autorización ya se ejecutó.

### Sin cambios en `send_to_payments()`

El correo de "Mandar pagos" sigue funcionando igual: agrupa y notifica solo requisiciones con `accounting_group_id IS NULL` antes de agrupar. Una requisición auto-agrupada por Tesorería ya no será candidata a aparecer en un futuro envío de Abastos (correcto: ya fue gestionada).

## Fuera de alcance

- No se modifica el criterio de elegibilidad de agrupación (`get_ungrouped_by_date`, ya ajustado en el diff actual en progreso para excluir notas de cargo del requisito de PDF).
- No se modifica `unauthorize_invoices()` (funcionalidad de desautorización ya en progreso) — la requisición auto-agrupada permanece agrupada incluso si luego se desautorizan sus facturas; no se "desagrupa".
- No se añade ninguna tabla ni columna nueva.

## Archivos afectados

- `_assets/controllers/payment.php` — `authorize_payment_execution()` (línea ~1775)
- `_assets/models/PaymentAccountingGroupsModel.php` — posible refactor menor para exponer una función reusable que agrupe una empresa/fecha puntual (extraída de `auto_group_by_date()`) sin duplicar lógica
- `_assets/js/payment.js` — manejo de la respuesta `auto_grouped` con aviso alertify
