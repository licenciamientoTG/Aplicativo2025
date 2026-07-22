# Manual Técnico: Sistema de Pago a Proveedores

> ⚠️ **OBSOLETO (2026-07-22):** este manual describe la versión original del flujo en
> `supply.php` con autorización multinivel (66→70→67→68). El módulo se migró al controlador
> dedicado `_assets/controllers/payment.php` (rutas `/payment/...`) con un solo nivel de
> autorización (Tesorería 68), layouts bancarios, anticipos, notas de crédito con CFDI,
> conciliación de comprobantes y grupos contables. La documentación vigente es:
> - `docs/pagos_documentacion_tecnica.html` — reporte técnico
> - `docs/pagos_flujo_procesos.html` — diagramas de flujo
>
> Conservar solo como referencia histórica.

**Aplicativo:** TotalGas
**Módulo:** Supply / Abastecimiento — Pago a Proveedores
**Fecha:** 2026-02-20
**Versión:** 1.0

---

## Tabla de Contenidos

1. [Visión General](#1-visión-general)
2. [Arquitectura del Módulo](#2-arquitectura-del-módulo)
3. [Estructura de Base de Datos](#3-estructura-de-base-de-datos)
4. [Modelos](#4-modelos)
5. [Controladores](#5-controladores)
6. [Vistas y Plantillas](#6-vistas-y-plantillas)
7. [JavaScript y Lógica Frontend](#7-javascript-y-lógica-frontend)
8. [Flujos de Trabajo](#8-flujos-de-trabajo)
9. [Integraciones Externas](#9-integraciones-externas)
10. [Sistema de Permisos y Autorización](#10-sistema-de-permisos-y-autorización)
11. [Validaciones](#11-validaciones)
12. [Estructuras de Datos Clave](#12-estructuras-de-datos-clave)
13. [Mapa de Archivos](#13-mapa-de-archivos)

---

## 1. Visión General

El sistema de pago a proveedores es un flujo de autorización multi-nivel para gestionar el pago de facturas de los proveedores de las 40+ gasolineras TotalGas. El proceso abarca:

- Consulta de facturas pendientes de pago en la API de abastecimiento
- Creación de órdenes de pago agrupando facturas
- Flujo secuencial de 3 niveles de autorización (Abastos → Administración y Finanzas → Tesorería)
- Registro de transacciones de pago con control de saldos
- Generación de layouts de transferencia bancaria (Excel/TXT)
- Soporte para anticipos, notas de crédito y notas de cargo

---

## 2. Arquitectura del Módulo

```
Browser
  │
  ├── GET  /supply/add_payment          → supply.php::add_payment()
  ├── POST /supply/payment_control_table → supply.php::payment_control_table()
  ├── POST /supply/create_payment        → supply.php::create_payment()
  ├── GET  /supply/payment_list          → supply.php::payment_list()
  ├── GET  /supply/payment_detail/{id}   → supply.php::payment_detail()
  ├── POST /supply/process_bulk_authorization → supply.php::process_bulk_authorization()
  ├── POST /supply/register_payment      → supply.php::register_payment()
  └── GET  /accounting/supplier_payments → accounting.php::supplier_payments()
            │
            ├── PaymentRequestsModel
            ├── PaymentRequestInvoicesModel
            ├── PaymentRequestAuthorizationsModel
            ├── PaymentTransactionsModel
            ├── ProveedoresModel
            └── CxpPagosModel (legacy)
                      │
                      ├── DB [TG] en 192.168.0.6  (tablas principales)
                      ├── DB [SG12] en 192.168.0.6 (catálogos)
                      ├── DB [1G_TOTALGAS] en 192.168.0.5 (legacy)
                      └── API externa 192.168.0.109:82 (documentos de compra)
```

---

## 3. Estructura de Base de Datos

### 3.1 Base de datos: `[TG]` (principal)

#### Tabla: `payment_requests`
Cabecera de cada orden de pago.

| Columna | Tipo | Descripción |
|---|---|---|
| `id` | INT PK | Identificador único |
| `request_date` | DATETIME | Fecha de creación de la solicitud |
| `user_id` | INT FK | Usuario que creó la solicitud (→ Usuario) |
| `comment` | NVARCHAR | Comentario general de la orden |
| `status` | TINYINT | `0`=Pendiente, `1`=Autorizado, `2`=Pagado, `3`=Cancelado |
| `provider_cod` | INT | Código del proveedor (→ SG12.Proveedores) |
| `emp_cod` | INT | Código de empresa (→ SG12.Empresas) |
| `tipo` | TINYINT | `0`=Pago normal, `1`=Anticipo |
| `monto_total` | DECIMAL | Monto total de la orden |
| `total_notas_credito` | DECIMAL | Suma de notas de crédito aplicadas |
| `total_notas_cargo` | DECIMAL | Suma de notas de cargo aplicadas |
| `scheduled_payment_date` | DATE | Fecha deseada de pago |
| `date_added` | DATETIME | Timestamp de inserción |

---

#### Tabla: `payment_request_invoices`
Facturas individuales que integran una orden de pago.

| Columna | Tipo | Descripción |
|---|---|---|
| `id` | INT PK | Identificador único |
| `payment_request_id` | INT FK | Referencia a `payment_requests` |
| `folio` | NVARCHAR | Folio interno del documento |
| `invoice_number` | NVARCHAR | Número de factura del proveedor |
| `codgas` | INT | Código de gasolinera |
| `amount` | DECIMAL | Importe total de la factura |
| `paid_amount` | DECIMAL | Monto ya pagado |
| `status` | TINYINT | `0`=Pendiente, `1`=Autorizado, `2`=Pagado, `3`=Parcial, `4`=Cancelado |
| `date_added` | DATETIME | Timestamp de inserción |
| `expiration_date` | DATE | Fecha de vencimiento |
| `uuid` | NVARCHAR | UUID fiscal SAT (único, obligatorio) |
| `payment_authorized` | BIT | Flag de autorización para pago |
| `authorized_amount` | DECIMAL | Monto autorizado para pago |

---

#### Tabla: `payment_request_authorizations`
Registro del flujo de autorizaciones multi-nivel.

| Columna | Tipo | Descripción |
|---|---|---|
| `id` | INT PK | Identificador único |
| `payment_request_id` | INT FK | Referencia a `payment_requests` |
| `staff_user_id` | INT FK | Usuario que autorizó |
| `permission_number` | INT | `66`=Abastos, `67`=Admin/Finanzas, `68`=Tesorería |
| `authorization_date` | DATETIME | Fecha y hora de la autorización |

---

#### Tabla: `payment_transactions`
Transacciones de pago efectivamente registradas.

| Columna | Tipo | Descripción |
|---|---|---|
| `id` | INT PK | Identificador único |
| `payment_request_id` | INT FK | Referencia a `payment_requests` |
| `invoice_id` | INT FK | Referencia a `payment_request_invoices` |
| `payment_amount` | DECIMAL | Monto pagado en esta transacción |
| `payment_date` | DATE | Fecha del pago |
| `payment_method` | NVARCHAR | Método (ej. `TRANSFERENCIA`) |
| `payment_reference` | NVARCHAR | Referencia bancaria |
| `bank_account` | NVARCHAR | Cuenta bancaria origen |
| `beneficiary_account` | NVARCHAR | Cuenta bancaria destino |
| `beneficiary_name` | NVARCHAR | Nombre del beneficiario |
| `status` | TINYINT | `0`=Pendiente, `1`=Procesado, `2`=Confirmado, `3`=Rechazado |
| `notes` | NVARCHAR | Notas adicionales |
| `created_by` | INT FK | Usuario que registró el pago |
| `created_at` | DATETIME | Timestamp de creación |
| `confirmed_by` | INT FK | Usuario que confirmó |
| `confirmed_at` | DATETIME | Timestamp de confirmación |

---

#### Tabla: `CreditNoteApplications`
Aplicación de notas de crédito/cargo a órdenes de pago.

| Columna | Tipo | Descripción |
|---|---|---|
| `payment_request_id` | INT FK | Orden de pago |
| `invoice_id` | INT FK | Factura a la que aplica |
| `debit_note_id` | INT | ID de nota de cargo |
| `credit_note_id` | INT | ID de nota de crédito |

---

### 3.2 Base de datos: `[SG12]` (catálogos)

| Tabla | Uso |
|---|---|
| `Proveedores` | Catálogo de proveedores activos |
| `Empresas` | Catálogo de empresas del grupo |
| `Estaciones` | Catálogo de gasolineras |
| `Documentos` | Facturas/documentos de compra |
| `DocumentosC` | Encabezados de documentos |

---

### 3.3 Base de datos: `[1G_TOTALGAS]` en `192.168.0.5` (legacy)

| Tabla | Uso |
|---|---|
| `cxp_pagos` | Pagos históricos del sistema legacy |
| `cat_prov` | Catálogo de proveedores legacy |
| `bco_cuentas` | Cuentas bancarias |
| `bco_bancos` | Bancos |
| `bco_iva_aux` | Auxiliar de IVA bancario |

---

## 4. Modelos

### 4.1 `PaymentRequestsModel`

**Archivo:** `_assets/models/PaymentRequestsModel.php`

Gestiona la tabla `payment_requests`. Es el modelo principal del módulo.

#### Métodos clave

| Método | Descripción |
|---|---|
| `create_payment_with_invoices($user_id, $documents, $comment, $provider_cod, $empresa_cod, $monto_total, $scheduled_payment_date)` | Crea orden de pago e inserta facturas en transacción DB. Retorna `['success', 'payment_id', 'total_documents']` |
| `get_requests_with_summary($type, $status)` | Query con JOINs que devuelve órdenes con conteos, totales y estado de autorizaciones por nivel |
| `create_anticipo($data)` | Crea una orden de tipo anticipo (`tipo=1`) |
| `get_request_by_id($id)` | Obtiene orden completa con datos del usuario |
| `update_request_status($id, $status, $comment)` | Actualiza estado y comentario |
| `delete_payment_complete($payment_id)` | Eliminación en cascada: autorizaciones → facturas → orden |

#### Estructura del array `$documents`
```php
[
    'nro'       => '12345',           // Folio del documento
    'Factura'   => 'F-20250220',      // Número de factura
    'codgas'    => 15,                // Código de gasolinera
    'total_fac' => 45678.50,          // Monto total
    'fechaVto'  => '2025-02-20',      // Fecha de vencimiento
    'satuid'    => 'ABC-1234-...',    // UUID SAT (obligatorio)
]
```

---

### 4.2 `PaymentRequestInvoicesModel`

**Archivo:** `_assets/models/PaymentRequestInvoicesModel.php`

Gestiona la tabla `payment_request_invoices`.

#### Métodos clave

| Método | Descripción |
|---|---|
| `insertInvoicesBulk($documents, $payment_request_id)` | Inserta facturas validando UUID único. Retorna conteos de insertadas/omitidas |
| `invoice_exists_by_uuid($uuid)` | Verifica si el UUID ya existe en otra orden |
| `get_payment_summary($payment_request_id)` | Retorna: total_invoices, total_amount, total_paid, total_pending |
| `update_invoice_status($id, $status)` | Actualiza status de una factura individual |
| `update_paid_amount($id, $paid_amount)` | Actualiza monto pagado y recalcula status automáticamente |

#### Lógica de status automático en `update_paid_amount`
```
paid_amount == 0           → status = 0 (Pendiente)
0 < paid_amount < amount   → status = 3 (Parcial)
paid_amount >= amount      → status = 2 (Pagado)
```

---

### 4.3 `PaymentRequestAuthorizationsModel`

**Archivo:** `_assets/models/PaymentRequestAuthorizationsModel.php`

Gestiona la tabla `payment_request_authorizations`.

#### Constantes del modelo
```php
const PERM_ABASTOS         = 66;
const PERM_ADMIN_FINANZAS  = 67;
const PERM_TESORERIA       = 68;

const AUTHORIZATION_SEQUENCE = [
    1 => 66,   // Nivel 1: Abastos
    2 => 67,   // Nivel 2: Administración y Finanzas
    3 => 68,   // Nivel 3: Tesorería
];
```

#### Métodos clave

| Método | Descripción |
|---|---|
| `insert_authorization($payment_request_id, $staff_user_id, $permission_number)` | Registra autorización con timestamp |
| `is_authorized_by_permission($payment_request_id, $permission_number)` | Verifica si un nivel ya autorizó |
| `get_authorization_status($payment_request_id)` | Retorna estado por nivel y siguiente nivel requerido |
| `get_next_authorization_level($payment_request_id)` | Calcula el siguiente nivel de autorización pendiente |

#### Estructura de retorno de `get_authorization_status`
```php
[
    'abastos'       => bool,
    'admin_finanzas'=> bool,
    'tesoreria'     => bool,
    'next_level'    => int|null,   // null = todos autorizados
    'completed'     => bool,
]
```

---

### 4.4 `PaymentTransactionsModel`

**Archivo:** `_assets/models/PaymentTransactionsModel.php`

Gestiona la tabla `payment_transactions`.

#### Métodos clave

| Método | Descripción |
|---|---|
| `process_bulk_payment($facturas, $user_id, $fecha_pago, $notes, $payment_reference, $payment_method)` | Procesa múltiples pagos en una transacción DB. Retorna `['success', 'total_pagado', 'facturas_procesadas']` |
| `get_total_paid_for_invoice($invoice_id)` | Suma todas las transacciones procesadas/confirmadas de una factura |
| `get_payment_history($invoice_id)` | Historial de transacciones con nombre del operador |
| `insert_transaction(...)` | Inserta un registro de transacción individual |
| `update_invoice_paid_amount($invoice_id, $nuevo_paid_amount, $total_amount)` | Actualiza monto pagado y recalcula status |

#### Estructura del array `$facturas` en `process_bulk_payment`
```php
[
    'invoice_id'         => 123,
    'monto_pagar'        => 15000.00,
    'folio'              => '12345',
    'payment_request_id' => 456,
]
```

---

### 4.5 `CxpPagosModel` (Legacy)

**Archivo:** `_assets/models/CxpPagosModel.php`

Se conecta a la base de datos legacy `[1G_TOTALGAS]` en `192.168.0.5`. Consulta el historial de pagos del sistema anterior para su visualización en el módulo de contabilidad.

---

### 4.6 `ProveedoresModel`

**Archivo:** `_assets/models/ProveedoresModel.php`

| Método | Descripción |
|---|---|
| `get_actives()` | Lista proveedores activos para los filtros |
| `get_rows()` | Obtiene información de pagos por proveedor |
| `update_credit_info($id, $dias_credito, $limite_credito)` | Actualiza términos de crédito del proveedor |

---

## 5. Controladores

### 5.1 `supply.php`

**Archivo:** `_assets/controllers/supply.php`

#### Métodos relacionados con pagos

| Método | Ruta | Tipo | Descripción |
|---|---|---|---|
| `add_payment()` | `/supply/add_payment` | GET | Renderiza la pantalla de creación de orden de pago |
| `payment_control_table()` | `/supply/payment_control_table` | POST | Consulta facturas disponibles vía API externa. Agrega badges de status |
| `create_payment()` | `/supply/create_payment` | POST | Crea la orden de pago usando `PaymentRequestsModel` |
| `payment_list()` | `/supply/payment_list` | GET | Renderiza la lista de órdenes de pago |
| `payment_detail()` | `/supply/payment_detail/{id}` | GET | Detalle de una orden con historial de autorizaciones |
| `list_payments()` | `/supply/list_payments` | GET | JSON con lista de órdenes para DataTable |
| `list_anticipos()` | `/supply/list_anticipos` | GET | JSON con lista de anticipos para DataTable |
| `get_pending_counts_all()` | `/supply/get_pending_counts_all` | GET | Contadores de autorizaciones pendientes por nivel |
| `process_bulk_authorization()` | `/supply/process_bulk_authorization` | POST | Procesa autorizaciones masivas por nivel |
| `register_payment()` | `/supply/register_payment` | POST | Registra el pago efectivo de facturas |
| `resumen_payment_table()` | `/supply/resumen_payment_table` | POST | Resumen de movimientos de tanques con asignación de facturas |

#### Detalle: `payment_control_table()`
```php
// Parámetros recibidos (POST)
$postData = [
    'from'      => dateToInt($_POST['fromDate']),
    'until'     => dateToInt($_POST['untilDate']),
    'codgas'    => $_POST['codgas']    ?: '0',
    'proveedor' => $_POST['proveedor'] ?: '0',
    'company'   => $_POST['company']   ?: '0',
];

// Llama API: POST http://192.168.0.109:82/api/estacion_documentos_compra/

// Agrega badge de status a cada factura:
// payment_status = 0 + en_orden_pago = 0 → "Enviado" (gris)
// payment_status = 0 + en_orden_pago = 1 → "En orden de pago" (azul)
// payment_status = 1                     → "Autorizado" (naranja)
// payment_status = 2                     → "Pagado" (verde)
// fechaVto < hoy                         → "Vencido" (rojo)
```

---

### 5.2 `accounting.php`

**Archivo:** `_assets/controllers/accounting.php`

| Método | Ruta | Descripción |
|---|---|---|
| `supplier_payments()` | `/accounting/supplier_payments` | Renderiza vista de pagos para contabilidad. Rango por default: año en curso |
| `payments_table()` | `/accounting/payments_table` | Consulta API `192.168.0.3:388/api/pagos/get_pagos`. Valida totales vs control |

---

## 6. Vistas y Plantillas

### 6.1 `views/supply/add_payment.html` — Creación de Orden de Pago

**Propósito:** Permite al usuario buscar facturas disponibles y armar una orden de pago.

**Estructura de la pantalla:**

```
┌─────────────────────────────────────────────────────────────┐
│  Filtros: Desde | Hasta | Empresa | Estación | Proveedor    │
│           [Fecha pago deseada]   [Buscar]                   │
├──────────────────────────────┬──────────────────────────────┤
│  FACTURAS DISPONIBLES        │  CANASTA DE PAGO             │
│  (DataTable con checkboxes)  │  (Facturas seleccionadas)    │
│                              │                              │
│  ☐ Folio | Factura | Est.    │  Folio | Monto | Eliminar    │
│  ☐ ...   | ...     | ...     │  ...   | ...   | [x]         │
│                              │                              │
│  [Agregar Seleccionadas]     │  Total: $XXX.XX              │
│  [Seleccionar Todos]         │  [Generar Pago]              │
└──────────────────────────────┴──────────────────────────────┘
```

**Campos del formulario:**

| Campo | ID | Tipo | Descripción |
|---|---|---|---|
| Fecha inicio | `from1` | Date | Inicio del rango de búsqueda |
| Fecha fin | `until1` | Date | Fin del rango de búsqueda |
| Empresa | `company` | Select | Código de empresa |
| Estación | `station_id1` | Select | `codgas` de la gasolinera |
| Proveedor | `proveedor_id` | Select | `id_control_gas` del proveedor |
| Fecha pago deseada | `scheduled_payment_date` | Date | Fecha solicitada para el pago |

---

### 6.2 `views/supply/payment_list.html` — Lista de Pagos

**Propósito:** Pantalla principal de gestión. Contiene 3 secciones en pestañas.

**Pestaña 1: Aprobación Masiva**
- Cards por nivel de autorización con contador de pendientes
- Botones de aprobación masiva (visibles según permiso del usuario)

**Pestaña 2: Lista de Pagos**

| Columna | Descripción |
|---|---|
| ID | Identificador de la orden |
| Fecha Solicitud | Cuándo se creó |
| Fecha Pago Esperada | Fecha solicitada |
| Empresa | Nombre de empresa |
| Proveedor | Nombre del proveedor |
| Usuario | Quién la creó |
| Facturas | Número de facturas |
| Total Facturas | Suma de montos |
| NC / ND | Notas de crédito / cargo |
| Monto Total | Total neto |
| Pagado | Monto pagado |
| Autorizadas | Facturas con autorización |
| Estado | Badge con status actual |
| Autorizaciones | Iconos de los 3 niveles |
| Acciones | Ver detalle, Cancelar |

**Pestaña 3: Facturas Autorizadas para Pago**

Muestra las facturas listas para ejecutar el pago (las 3 autorizaciones completadas).

| Columna | Descripción |
|---|---|
| Banco | Banco asignado (Banorte/Santander/Sin asignar) |
| Empresa | Nombre de empresa |
| Proveedor | Nombre del proveedor |
| Tipo | Normal / Anticipo |
| Monto Total Autorizado | Suma autorizada |
| Saldo Total | Pendiente de pago |
| Último Autorizador | Usuario y fecha de última auth |
| Acciones | Generar Layout, Registrar Pago |

**Filtros por banco:** Banorte | Santander | Sin asignar
**Sumarios:** Totales por banco al pie de la tabla

---

### 6.3 `views/supply/payment_detail.html` — Detalle de Orden de Pago

**Propósito:** Vista completa de una orden con:
- Indicadores visuales del estado de las 3 autorizaciones
- Tabla de facturas incluidas con status individual
- Historial de transacciones de pago
- Formulario de autorización según nivel del usuario
- Formulario de registro de pago (solo Tesorería)

---

### 6.4 `views/accounting/supplier_payments.html` — Vista Contabilidad

**Propósito:** Vista simplificada para el departamento de contabilidad.

| Columna | Descripción |
|---|---|
| Num Pago | Identificador del pago |
| Clave | Clave del proveedor |
| Proveedor | Nombre |
| Cuenta | Cuenta bancaria |
| Referencia | Referencia bancaria |
| Banco | Banco emisor |
| Fecha | Fecha de pago |
| Monto | Monto pagado |
| Folio | Folio de documento |
| Control | Reconciliación vs control |

---

### 6.5 `views/supply/fuel_payments.html` — Visor de Facturas

**Propósito:** Consulta de facturas PDF con asignación a movimientos de tanque.

---

## 7. JavaScript y Lógica Frontend

**Archivo principal:** `_assets/js/supply.js` (~7,931 líneas)

### 7.1 Variables globales del módulo de pagos

```javascript
let paymentItems = [];           // Facturas en la canasta de pago
let selectedInvoices = new Set();// Folios seleccionados (evita duplicados)
let paymentTable;                // Instancia del DataTable principal
let filterVencidas = false;      // Filtro de facturas vencidas activo
let currentProvider = null;      // Proveedor actualmente seleccionado
let pagosPendientesAprobacion = [];   // Pagos para aprobación masiva
let pagosSeleccionadosMasivo = [];    // Seleccionados en modal de aprobación
let permissionNumberActual = null;    // Nivel de autorización activo (66/67/68)
```

### 7.2 Funciones principales

#### Creación de la orden

```javascript
// Carga facturas disponibles desde la API
function payment_create_table()
// → POST /supply/payment_control_table
// → Renderiza DataTable con soporte de drag & drop
// → Aplica colores de vencimiento

// Agrega las facturas checadas a la canasta
function addSelectedInvoices()
// → Valida que no existan duplicados en paymentItems
// → Actualiza el resumen (total y conteo)

// Genera la orden de pago
function generatePayment()
// → Valida que paymentItems no esté vacío
// → Solicita confirmación + comentario
// → POST /supply/create_payment con el array de documentos
// → Redirige a /supply/payment_detail/{id}

// Actualiza el panel resumen de la canasta
function updatePaymentSummary()
// → Suma total_fac de todos los ítems en paymentItems
// → Muestra/oculta el panel según si hay ítems
```

#### Autorización masiva

```javascript
// Obtiene contadores de pendientes por nivel
function cargarContadoresPendientes()
// → GET /supply/get_pending_counts_all
// → Actualiza badges en los 3 cards de autorización

// Abre modal de aprobación para un nivel
function abrirModalAprobacionMasiva(permissionNumber, nombreNivel)
// → Llama cargarPagosPendientesAprobacion(permissionNumber)
// → Muestra resumen y lista de pagos pendientes

// Confirma selección antes de procesar
function confirmarAprobacionMasiva()
// → Muestra modal de confirmación con monto y nivel

// Ejecuta la aprobación masiva
function ejecutarAprobacionMasiva()
// → POST /supply/process_bulk_authorization
// → { payment_ids: [...], permission_number: 66|67|68, comentario: '' }
// → Refresca listas tras completar
```

#### Registro de pago

```javascript
// Carga datos de un pago para el layout de transferencia
function cargarDatosParaLayout(paymentId)

// Genera archivo de transferencia bancaria
function generarTransferenciasBancarias(paymentId)
// → Formatea por banco: Banorte / Santander
// → Genera Excel o TXT

// Valida y genera el archivo layout
function validarYGenerarLayout()
// → Verifica autorizaciones completas
// → Verifica límites de montos

// Abre modal para registrar el pago efectivo
function abrirModalRegistroPago()
// → Muestra: Monto Facturas, NC, ND, Saldo Neto
// → POST /supply/register_payment
```

### 7.3 Drag & Drop

La canasta de pago soporta arrastrar filas desde el DataTable:

```javascript
function setupDragAndDrop()
// Eventos: dragstart (tabla) → dragover (canasta) → drop (canasta)
// Al soltar: agrega al array paymentItems y actualiza resumen
// Previene duplicados por folio
```

---

## 8. Flujos de Trabajo

### 8.1 Flujo: Creación de Orden de Pago

```
1. Usuario entra a /supply/add_payment
2. Selecciona filtros: Empresa, Estación, Proveedor, Fechas
3. Clic "Buscar" → AJAX POST /supply/payment_control_table
   └─ API externa devuelve facturas con status actual
4. Usuario selecciona facturas (checkbox o drag & drop)
5. Usuario define fecha de pago deseada
6. Clic "Generar Pago" → Solicita comentario
7. AJAX POST /supply/create_payment
   └─ PaymentRequestsModel::create_payment_with_invoices()
       ├─ INSERT INTO payment_requests
       ├─ INSERT INTO payment_request_invoices (por cada factura)
       │   ├─ Omite facturas sin UUID
       │   └─ Omite facturas con UUID ya registrado
       └─ COMMIT o ROLLBACK
8. Respuesta: { success: true, payment_id: 1234 }
9. Redirección → /supply/payment_detail/1234
```

---

### 8.2 Flujo: Autorización Multi-Nivel

```
NIVEL 1 — ABASTOS (Permiso 66)
  └─ Valida: cadena de suministro, proveedor, folios
  └─ Registra en payment_request_authorizations (permission_number=66)

NIVEL 2 — ADMINISTRACIÓN Y FINANZAS (Permiso 67)
  └─ Requiere: Nivel 1 completado
  └─ Valida: presupuesto, límite de crédito, condiciones de pago
  └─ Registra (permission_number=67)

NIVEL 3 — TESORERÍA (Permiso 68)
  └─ Requiere: Niveles 1 y 2 completados
  └─ Autoriza la ejecución del pago
  └─ Registra (permission_number=68)
  └─ Orden queda disponible en tab "Facturas Autorizadas"
```

**Secuencia obligatoria:** `66 → 67 → 68` (no se puede saltear niveles)

```
Estado de la orden según autorizaciones:
  0/3 niveles → Pendiente (gris)
  1/3 niveles → En proceso (amarillo)
  2/3 niveles → En proceso (naranja)
  3/3 niveles → Listo para pago (verde)
```

---

### 8.3 Flujo: Registro de Pago

```
1. Usuario entra a /supply/payment_list → Tab "Facturas Autorizadas"
2. Ve facturas con las 3 autorizaciones completas
3. Selecciona facturas a pagar con checkbox
4. Clic "Registrar Pago" → Abre modal
   └─ Muestra: Monto Facturas, NC aplicadas, ND aplicadas, Saldo Neto
5. Usuario ingresa:
   └─ Monto a pagar por factura
   └─ Referencia bancaria
   └─ Fecha de pago
6. AJAX POST /supply/register_payment
   └─ PaymentTransactionsModel::process_bulk_payment()
       Para cada factura:
       ├─ Verifica que la factura exista
       ├─ Calcula saldo = amount - ya_pagado
       ├─ Valida monto_pagar <= saldo
       ├─ INSERT INTO payment_transactions
       └─ UPDATE payment_request_invoices.paid_amount + status
       └─ Si todas pagadas: UPDATE payment_requests.status = 2
7. Respuesta: { success: true, total_pagado: X, facturas_procesadas: N }
```

---

### 8.4 Flujo: Anticipo a Proveedor

```
1. Usuario abre modal "Crear Anticipo" en /supply/payment_list
2. Ingresa: Proveedor, Empresa, Monto, Fecha deseada, Nombre, Comentario
3. POST /supply/create_anticipo
   └─ PaymentRequestsModel::create_anticipo()
       └─ INSERT payment_requests con tipo=1
4. Sigue el mismo flujo de autorizaciones (3 niveles)
5. Registro de pago igual al flujo normal
```

---

## 9. Integraciones Externas

### 9.1 API de Documentos de Compra

**URL:** `http://192.168.0.109:82/api/estacion_documentos_compra/`
**Método:** POST
**Usado por:** `supply.php::payment_control_table()`

**Parámetros de entrada:**
```json
{
  "from": 20250101,
  "until": 20250220,
  "codgas": 15,
  "proveedor": 837,
  "company": 1
}
```

**Respuesta:** Array de facturas con campos:
- `nro` — Folio
- `Factura` — Número de factura
- `satuid` — UUID SAT
- `total_fac` — Monto total
- `fechaVto` — Vencimiento (calculado desde días de crédito)
- `payment_status` — `0`=Enviado, `1`=Autorizado, `2`=Pagado
- `en_orden_pago` — `0`/`1`

---

### 9.2 API de Movimientos de Tanques

**URL:** `http://192.168.0.109:82/api/resumen_movimientos_tanques/`
**Método:** POST
**Usado por:** `supply.php::resumen_payment_table()`

---

### 9.3 API de Pagos (Contabilidad)

**URL:** `http://192.168.0.3:388/api/pagos/get_pagos`
**Método:** POST
**Usado por:** `accounting.php::payments_table()`

Valida montos vs. totales de control:
```php
$status = ($total === $totalControl) ? 'SI' : 'NO';
```

---

## 10. Sistema de Permisos y Autorización

### Permisos del módulo

| Permiso ID | Nivel | Descripción |
|---|---|---|
| `66` | Abastos | Primer nivel de autorización |
| `67` | Administración y Finanzas | Segundo nivel |
| `68` | Tesorería | Tercer nivel — autoriza el pago |

Los permisos del usuario se leen de `$_SESSION['permisos']` (array de IDs).

### Visibilidad en la interfaz

- Los botones de aprobación masiva solo se muestran al usuario con el permiso correspondiente
- El formulario de autorización en el detalle se muestra según el nivel del usuario y el siguiente nivel requerido
- El formulario de registro de pago solo es visible para usuarios con permiso `68` (Tesorería)

---

## 11. Validaciones

### 11.1 Validaciones al insertar facturas

| Validación | Acción si falla |
|---|---|
| UUID vacío o nulo | Omitir factura, registrar en log |
| UUID ya existe en otra orden | Omitir factura, registrar en log |
| `total_fac` <= 0 | Omitir factura |

### 11.2 Validaciones al procesar pago

| Validación | Acción si falla |
|---|---|
| Factura no encontrada en DB | Error, rollback completo |
| `monto_pagar` > saldo disponible | Error, rollback completo |
| Transacción DB fallida | Rollback completo |

### 11.3 Validaciones de autorización

| Validación | Acción si falla |
|---|---|
| Nivel anterior no completado | Rechaza autorización |
| Usuario sin el permiso necesario | No muestra el formulario / rechaza |
| Nivel ya autorizado por este usuario | No permite re-autorizar |

---

## 12. Estructuras de Datos Clave

### Objeto Factura (en JavaScript)
```javascript
{
    nro: '12345',
    Factura: 'F-20250220',
    codgas: 15,
    proveedor: 'TESORO',
    proveedor_codigo: 837,
    fecha: '2025-01-20',
    fechaVto: '2025-02-20',
    total_fac: 45678.50,
    satuid: 'ABCD1234-...',
    gasolinera: 'EST-15',
    codigo_empresa: 1,
    payment_status: 0,
    en_orden_pago: 0,
    statusLabel: '<span class="badge bg-light">Enviado</span>'
}
```

### Objeto Orden de Pago (PHP)
```php
[
    'id'                     => 1234,
    'request_date'           => '2025-02-20 10:30:00',
    'scheduled_payment_date' => '2025-02-25',
    'user_id'                => 5,
    'usuario_nombre'         => 'Juan García',
    'provider_cod'           => 837,
    'provider_name'          => 'TESORO MEXICO',
    'emp_cod'                => 1,
    'emp_name'               => 'Empresa Principal',
    'status'                 => 0,       // 0=Pendiente, 1=Auth, 2=Pagado, 3=Cancelado
    'tipo'                   => 0,       // 0=Normal, 1=Anticipo
    'monto_total'            => 91357.00,
    'comment'                => 'Pago regular mensual',
    'total_invoices'         => 2,
    'total_amount'           => 91357.00,
    'total_paid'             => 0,
    'authorized_invoices_count' => 0,
    'authorized_amount_total'   => 0,
    'total_notas_credito'    => 0,
    'total_notas_cargo'      => 0,
    // Estado de autorizaciones
    'auth_abastos'           => 0,
    'auth_abastos_user'      => null,
    'auth_abastos_date'      => null,
    'auth_admin'             => 0,
    'auth_admin_user'        => null,
    'auth_admin_date'        => null,
    'auth_tesoreria'         => 0,
    'auth_tesoreria_user'    => null,
    'auth_tesoreria_date'    => null,
]
```

---

## 13. Mapa de Archivos

| Tipo | Archivo | Descripción |
|---|---|---|
| Controller | `_assets/controllers/supply.php` | Lógica principal del módulo de abastecimiento y pagos |
| Controller | `_assets/controllers/accounting.php` | Vista de pagos para contabilidad |
| Model | `_assets/models/PaymentRequestsModel.php` | Órdenes de pago |
| Model | `_assets/models/PaymentRequestInvoicesModel.php` | Facturas de cada orden |
| Model | `_assets/models/PaymentRequestAuthorizationsModel.php` | Autorizaciones multi-nivel |
| Model | `_assets/models/PaymentTransactionsModel.php` | Transacciones de pago |
| Model | `_assets/models/CxpPagosModel.php` | Pagos legacy (consulta) |
| Model | `_assets/models/ProveedoresModel.php` | Catálogo de proveedores |
| Model | `_assets/models/CreditNoteApplicationsModel.php` | Notas de crédito/cargo |
| View | `views/supply/add_payment.html` | Crear orden de pago |
| View | `views/supply/payment_list.html` | Lista y gestión de pagos |
| View | `views/supply/payment_detail.html` | Detalle de orden |
| View | `views/supply/fuel_payments.html` | Visor de facturas PDF |
| View | `views/accounting/supplier_payments.html` | Vista contabilidad |
| JS | `_assets/js/supply.js` | Lógica frontend completa (~7,931 líneas) |
| JS | `_assets/js/accounting.js` | Lógica frontend contabilidad |
