# Registrar Pago con lectura de comprobantes y asignación por factura

## Contexto

En el tab **Facturas Autorizadas** (`/payment`, tab `pl-tab-facturas-auth`), Tesorería (permiso 68) selecciona grupos de facturas autorizadas y pulsa **"Registrar Pago"** (`abrirModalRegistroPago()` en `_assets/js/payment.js`). Hoy esto abre un `alertify.confirm` con un formulario simple: fecha de pago, referencia bancaria, observaciones y **un** comprobante opcional (que NO se lee — solo se adjunta). Al confirmar, `ejecutarRegistroPago()` llama a `/payment/execute_authorized_payments` con todos los `invoice_ids` y un único comprobante.

Existe en paralelo el modal **"Subir Comprobantes"** (`abrirModalComprobantes()`), que sí **lee** cada PDF con `ComprobantePagoParser::parse()` (extrae banco, RFC ordenante/beneficiario, importe, referencia y fecha) vía `/payment/preview_comprobantes_match`, hace match automático por RFC+monto contra todos los grupos autorizados, y guarda vía `/payment/conciliar_comprobantes` (que aplica cada comprobante como un lote independiente con su PDF adjunto, con fallo parcial seguro).

**Objetivo:** que "Registrar Pago" se comporte como el flujo de comprobantes — subir uno o varios PDFs que el sistema lee automáticamente (fecha/referencia/importe) — pero acotado a las facturas ya seleccionadas, donde el usuario asigna manualmente **qué facturas cubre cada comprobante**, descontando del importe leído hasta llegar a cero.

## Decisiones de diseño

- **Reutilizar backend existente, sin duplicar lógica de pago.**
  - Lectura de PDF: reutilizar `/payment/preview_comprobantes_match` enviando **un** PDF en `comprobantes[]`; del response se usa solo `comprobantes[0].comprobante` (banco, fecha, referencia, importe), ignorando `grupos` y el match.
  - Guardado: reutilizar `/payment/conciliar_comprobantes`, que ya recibe `comprobantes[]` (los PDFs) + `asignaciones` (JSON) y aplica cada comprobante como un lote con su PDF. **Cero cambios de backend.**
- **Asignación a nivel de factura completa, no de monto parcial.** `conciliar_comprobantes` paga cada factura por su `authorized_amount` íntegro (no acepta montos parciales). Por tanto el usuario asigna facturas **completas** a cada comprobante; el "descuento hasta 0" opera restando el `authorized_amount` de cada factura asignada del importe leído del comprobante.
- **Avisar pero permitir** cuando la suma de facturas asignadas a un comprobante difiere de su importe leído: mostrar la diferencia en rojo, sin bloquear el registro.
- **Reemplazar el `alertify.confirm`** del registro por un modal Bootstrap propio, porque el flujo (dropzone + tarjetas de comprobante + tabla de facturas con interacción en vivo) excede lo que un diálogo simple soporta.

## Comportamiento

### Apertura
`abrirModalRegistroPago()` mantiene sus validaciones actuales (debe haber selección; un solo banco). En vez de `mostrarModalRegistroPago()` con `alertify`, abre el nuevo modal `#modalRegistroPagoNuevo` y lo puebla.

### Estructura del modal
1. **Header-resumen**: banco (con color/ícono), empresa(s), total de facturas y monto total — equivalente al header actual.
2. **Zona de comprobantes**: dropzone para subir 1+ PDFs. Cada PDF subido se lee con `preview_comprobantes_match` y genera una **tarjeta de comprobante** con: nombre de archivo, banco leído, **fecha** (input date editable, pre-rellenada del PDF), **referencia** (input text editable, pre-rellenada del PDF), **importe leído** (solo lectura) y **saldo restante** (importe leído − suma de `authorized_amount` de facturas asignadas a ese comprobante). La tarjeta activa se resalta.
3. **Tabla de facturas**: todas las facturas de la(s) línea(s) seleccionada(s), con folio, estación, monto autorizado y un checkbox de asignación.

### Mecánica de asignación
- Hay un **comprobante activo** (seleccionado por el usuario; por defecto el último subido). Las facturas marcadas se asignan a ese comprobante.
- Al marcar una factura, su `authorized_amount` se descuenta del **saldo restante** del comprobante activo, en vivo. Al desmarcar, se devuelve.
- Una factura asignada a un comprobante queda bloqueada (deshabilitada) para los demás, para no pagarla dos veces.
- El saldo restante se muestra por comprobante. Si la suma de facturas asignadas ≠ importe leído, se muestra la **diferencia en rojo** (aviso, no bloqueo).
- Facturas sin asignar a ningún comprobante no se incluyen en el registro.

### Guardado
Al confirmar, el frontend arma `asignaciones` — un arreglo donde cada entrada es `{ archivo_idx, archivo, invoice_ids, fecha_pago, referencia, observaciones }` — y hace **una** petición a `/payment/conciliar_comprobantes` con todos los PDFs en `comprobantes[]` (mismo orden que `archivo_idx`) y el JSON `asignaciones`. Esto es idéntico a `guardarConciliacionComprobantes()`/`ejecutarConciliacionComprobantes()`, solo que las asignaciones las arma el usuario en vez del match automático.

Comprobantes sin facturas asignadas se omiten del envío. La respuesta (resultado por comprobante, total aplicado) se muestra como en la conciliación, y se recarga `#tabla_facturas_autorizadas`.

## Manejo de errores
- PDF ilegible o archivo no-PDF: la tarjeta se crea con aviso, importe 0 y fecha/referencia vacías editables a mano (el usuario puede completar y usarlo igual).
- Comprobante sin facturas asignadas: no se envía (se omite de `asignaciones`).
- Suma de facturas ≠ importe leído: aviso visual en rojo, permite registrar.
- `conciliar_comprobantes` ya maneja fallo parcial seguro y devuelve resultado por comprobante; se muestra ese detalle.

## Archivos afectados
- `views/payment/payment_list.html` — nuevo modal Bootstrap `#modalRegistroPagoNuevo` (dropzone, contenedor de tarjetas, tabla de facturas). El `alertify.confirm` del registro deja de usarse.
- `_assets/js/payment.js` — reescribir `mostrarModalRegistroPago()` para abrir y poblar el nuevo modal; estado en memoria (`regPagoComprobantes`, `regPagoFacturas`, `regPagoComprobanteActivo`); handlers de subida/lectura, asignación en vivo y guardado vía `conciliar_comprobantes`. Las funciones `ejecutarRegistroPago()`/`mostrarResumenPagoRegistrado()` se conservan o adaptan según el resultado a mostrar.

## Fuera de alcance
- No se modifica `execute_authorized_payments`, `conciliar_comprobantes`, `preview_comprobantes_match` ni el parser.
- No se permite pago parcial por factura (el backend no lo soporta; se asigna factura completa).
- No se toca el modal "Subir Comprobantes" ni su match automático.
- No se añaden tablas ni columnas.

## Verificación
El proyecto no tiene framework de tests (CLAUDE.md). Verificación: `php -l` para PHP (no debería cambiar PHP), `node --check` para JS, y prueba manual en navegador como Tesorería (subir 1 y 2 comprobantes, asignar facturas, comprobar descuento de saldo, diferencia en rojo, registro y recarga de tabla).
