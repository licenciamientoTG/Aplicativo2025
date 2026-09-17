# Reporte semanal CNE Petrotal — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a new `petrotal` module that previews, generates, sends, and archives Petrotal's weekly CNE (Comisión Nacional de Energía) statistical obligation report, sourcing data from `FacturasRecibidas` and existing permit catalogs already in the database.

**Architecture:** A new MVC slice following this codebase's existing conventions exactly — one controller (`_assets/controllers/petrotal.php`), two data models (`PetrotalObligacionModel`, `PetrotalFielModel`), one infrastructure class for the CNE HTTP client (`_assets/classes/PetrotalCneClient.class.php`), Twig views under `views/petrotal/`, two new TG tables, and a new sidebar section. No changes to SG12 or `devTotalGas` (read-only sources).

**Tech Stack:** PHP 8, PDO (sqlsrv driver via `MySqlPdoHandler::getInstance()`), Twig 3, Bootstrap Material Design + jQuery, cURL for the CNE API call, `openssl_encrypt`/`openssl_decrypt` for the FIEL password at rest.

**Spec:** `docs/superpowers/specs/2026-09-17-petrotal-reporte-cne-design.md`

## Global Constraints

- RFC de Petrotal: `PET180213L66` (ya definido como `PetrotalReconciliationModel::PETROTAL_RFC` — reusar esa constante, no redeclarar).
- Número de permiso CNE de Petrotal: `H/22730/COM/2019`.
- Factor de conversión litros→barriles: `158.987` (÷).
- Clasificación de producto por descripción del concepto: "MAXIMA"/"REGULAR" → ProductoId=7, SubProductoId=13 (Regular); "SUPER"/"PREMIUM" → ProductoId=7, SubProductoId=14 (Premium); "DIESEL"/"DIÉSEL" → ProductoId=3, SubProductoId=62 (Diésel UBA); cualquier otra descripción queda excluida y listada como advertencia.
- Catálogo de clientes (ventas): `TG.dbo.XmlCre` (`Rfc`, `NumeroPermisoCRE`) — no crear tabla nueva.
- Catálogo de proveedores (compras): `SG12.dbo.Proveedores` (`rfc`, `nropcc` = permiso comercializador) — solo lectura, nunca escribir en SG12.
- Archivos subidos van en `_assets/uploads/petrotal/{fiel,json,acuse}/` (convención existente del proyecto, ver `payment.php` uploads en `_assets/uploads/credit_debit_notes/`), nunca en `_uploads/` en la raíz.
- Todas las consultas parametrizadas vía `$this->sql->select($query, $params)` / `insert` / `update` — nunca interpolar valores de usuario directamente en el SQL.
- Todo texto de interfaz en español, siguiendo el tono ya usado en el resto de la app.
- No hay framework de tests en este proyecto — la verificación es manual (scripts en `tools/`, correr desde CLI con `php tools/archivo.php`).

---

## File Structure

| File | Responsibility |
|---|---|
| `_assets/models/PetrotalObligacionModel.php` | Construye el reporte (consulta `FacturasRecibidas`, clasifica producto, convierte unidades, resuelve contraparte) y hace CRUD de `PetrotalReportesObligacion`. |
| `_assets/models/PetrotalFielModel.php` | CRUD de `PetrotalFielConfig` (rutas de `.cer`/`.key`, password cifrado). |
| `_assets/classes/PetrotalCneClient.class.php` | Cliente HTTP puro: arma el multipart, hace el POST a la API de la CNE, interpreta la respuesta, descarga el acuse. No toca BD. |
| `_assets/controllers/petrotal.php` | Orquesta: autorización, llamadas a los modelos y al cliente HTTP, renderiza vistas, expone endpoints AJAX. |
| `views/petrotal/volumetricos.html` | Pantalla principal: selector de semana + tabla de preview + botones. |
| `views/petrotal/historial.html` | Tabla de envíos previos. |
| `views/petrotal/configuracion_fiel.html` | Formulario de subida de `.cer`/`.key`/password. |
| `_assets/js/petrotal.js` | JS de la pantalla principal: AJAX de preview, render de tablas, envío. |
| `views/layouts/sidebar.html` | Modificar: agregar sección "PETROTAL". |
| SQL migration (ejecutado a mano contra TG) | Crea `PetrotalReportesObligacion` y `PetrotalFielConfig`, inserta permisos 98/99 en `tg_permissions`. |

---

## Task 1: Tablas de base de datos y permisos

**Files:**
- Create: `tools/migrate_petrotal_reporte_cne.php` (script de migración, se corre una vez a mano; no es parte del código de producción pero queda en el repo como registro de lo aplicado, igual que otros scripts en `tools/`)

**Interfaces:**
- Produces: tablas `PetrotalReportesObligacion`, `PetrotalFielConfig` en TG; filas con `id=98` y `id=99` en `tg_permissions`.

- [ ] **Step 1: Escribir el script de migración**

```php
<?php
$_SERVER['DOCUMENT_ROOT'] = __DIR__ . '/..';
$_SERVER['REQUEST_URI'] = '/';
chdir($_SERVER['DOCUMENT_ROOT']);
require $_SERVER['DOCUMENT_ROOT'] . '/_assets/classes/header.class.php';
require $_SERVER['DOCUMENT_ROOT'] . '/_assets/classes/php_functions.php';
spl_autoload_register(function ($class) {
    if (file_exists(CLASSES . $class . '.class.php')) require CLASSES . $class . '.class.php';
    if (file_exists(MODELS . $class . '.php')) require MODELS . $class . '.php';
});
$db = MySqlPdoHandler::getInstance();

echo "Creando PetrotalReportesObligacion...\n";
$db->update("
    IF NOT EXISTS (SELECT 1 FROM sys.tables WHERE name = 'PetrotalReportesObligacion')
    CREATE TABLE PetrotalReportesObligacion (
        Id INT IDENTITY PRIMARY KEY,
        PeriodoDesde DATE NOT NULL,
        PeriodoHasta DATE NOT NULL,
        Estado VARCHAR(20) NOT NULL,
        RutaJson VARCHAR(500) NULL,
        RutaAcuse VARCHAR(500) NULL,
        FolioAcuse VARCHAR(50) NULL,
        RespuestaRaw NVARCHAR(MAX) NULL,
        UsuarioId INT NOT NULL,
        FechaEnvio DATETIME NULL,
        CreatedAt DATETIME NOT NULL DEFAULT GETDATE(),
        UpdatedAt DATETIME NOT NULL DEFAULT GETDATE()
    )
", []);

echo "Creando PetrotalFielConfig...\n";
$db->update("
    IF NOT EXISTS (SELECT 1 FROM sys.tables WHERE name = 'PetrotalFielConfig')
    CREATE TABLE PetrotalFielConfig (
        Id INT IDENTITY PRIMARY KEY,
        RutaCer VARCHAR(500) NOT NULL,
        RutaKey VARCHAR(500) NOT NULL,
        PasswordCifrado VARCHAR(500) NOT NULL,
        ActualizadoPor INT NOT NULL,
        UpdatedAt DATETIME NOT NULL DEFAULT GETDATE()
    )
", []);

echo "Insertando permisos 98/99...\n";
$existing = $db->select("SELECT id FROM tg_permissions WHERE id IN (98, 99)", []);
$existingIds = array_column($existing, 'id');

if (!in_array(98, $existingIds)) {
    $db->insert("SET IDENTITY_INSERT tg_permissions ON; INSERT INTO tg_permissions (id, action, department, description, status) VALUES (98, 'read', 'Petrotal', 'Ver módulo Petrotal - Reporte CNE', 1); SET IDENTITY_INSERT tg_permissions OFF;", []);
}
if (!in_array(99, $existingIds)) {
    $db->insert("SET IDENTITY_INSERT tg_permissions ON; INSERT INTO tg_permissions (id, action, department, description, status) VALUES (99, 'update', 'Petrotal', 'Configurar FIEL Petrotal', 1); SET IDENTITY_INSERT tg_permissions OFF;", []);
}

echo "Listo.\n";
```

- [ ] **Step 2: Correr el script y verificar**

Run: `php tools/migrate_petrotal_reporte_cne.php`
Expected: imprime "Listo." sin errores.

Verificar con un script rápido inline:
```bash
php -r '
$_SERVER["DOCUMENT_ROOT"] = __DIR__;
$_SERVER["REQUEST_URI"] = "/";
chdir($_SERVER["DOCUMENT_ROOT"]);
require $_SERVER["DOCUMENT_ROOT"] . "/_assets/classes/header.class.php";
require $_SERVER["DOCUMENT_ROOT"] . "/_assets/classes/php_functions.php";
spl_autoload_register(function ($class) {
    if (file_exists(CLASSES . $class . ".class.php")) require CLASSES . $class . ".class.php";
    if (file_exists(MODELS . $class . ".php")) require MODELS . $class . ".php";
});
$db = MySqlPdoHandler::getInstance();
print_r($db->select("SELECT id, department, description FROM tg_permissions WHERE id IN (98,99)", []));
print_r($db->select("SELECT name FROM sys.tables WHERE name IN (?, ?)", ["PetrotalReportesObligacion", "PetrotalFielConfig"]));
'
```
Expected: 2 filas de permisos, 2 nombres de tabla.

- [ ] **Step 3: Commit**

```bash
git add tools/migrate_petrotal_reporte_cne.php
git commit -m "Agrega migración de tablas y permisos para módulo Petrotal CNE"
```

---

## Task 2: Carpetas de almacenamiento

**Files:**
- Create: `_assets/uploads/petrotal/fiel/.gitkeep`
- Create: `_assets/uploads/petrotal/json/.gitkeep`
- Create: `_assets/uploads/petrotal/acuse/.gitkeep`
- Modify: `.gitignore` (si existe, agregar exclusión del contenido real pero conservar `.gitkeep`)

**Interfaces:**
- Produces: rutas de disco `_assets/uploads/petrotal/fiel/`, `_assets/uploads/petrotal/json/`, `_assets/uploads/petrotal/acuse/` que los siguientes tasks usan para `move_uploaded_file()` / `file_put_contents()`.

- [ ] **Step 1: Crear las carpetas con marcador**

```bash
mkdir -p _assets/uploads/petrotal/fiel _assets/uploads/petrotal/json _assets/uploads/petrotal/acuse
touch _assets/uploads/petrotal/fiel/.gitkeep _assets/uploads/petrotal/json/.gitkeep _assets/uploads/petrotal/acuse/.gitkeep
```

- [ ] **Step 2: Revisar `.gitignore` existente**

```bash
grep -n "uploads" .gitignore 2>/dev/null || echo "sin reglas de uploads todavia"
```

Si `_assets/uploads/` ya tiene una regla de ignorar contenido salvo `.gitkeep` (patrón común en este repo para `credit_debit_notes/`), seguir el mismo patrón para `petrotal/`. Si no existe ninguna regla previa de uploads, no agregar nada nuevo — los demás uploads del proyecto tampoco están en `.gitignore` (verificado: no aparecen reglas de `uploads` en el grep anterior si sale vacío), así que las carpetas simplemente quedan versionadas vacías vía `.gitkeep`.

- [ ] **Step 3: Commit**

```bash
git add _assets/uploads/petrotal/
git commit -m "Crea estructura de carpetas para archivos del módulo Petrotal"
```

---

## Task 3: `PetrotalObligacionModel` — construcción del reporte (ventas y compras)

**Files:**
- Create: `_assets/models/PetrotalObligacionModel.php`
- Test: `tools/test_petrotal_obligacion_model.php` (script manual, no framework de tests en este proyecto)

**Interfaces:**
- Consumes: `PetrotalReconciliationModel::PETROTAL_RFC` (constante ya existente en `_assets/models/PetrotalReconciliationModel.php:6`).
- Produces:
  - `PetrotalObligacionModel::construir_reporte(string $desde, string $hasta): array` — retorna `['ventas' => array, 'compras' => array, 'advertencias' => array]`, donde cada fila de `ventas`/`compras` es un arreglo asociativo con claves: `fecha` (Y-m-d), `factura_id`, `folio`, `producto_id` (int), `subproducto_id` (int), `producto_label` (string), `contraparte_rfc`, `contraparte_nombre`, `permiso_cre` (string|null), `volumen_bbl` (float), `precio` (float — `Total factura / volumen_bbl`), `descripcion_original` (string).
  - `PetrotalObligacionModel::clasificar_producto(string $descripcion): ?array` — retorna `['producto_id' => int, 'subproducto_id' => int, 'label' => string]` o `null` si no clasifica.

- [ ] **Step 1: Escribir el modelo — método `clasificar_producto`**

```php
<?php
class PetrotalObligacionModel extends Model {

    const LITROS_POR_BARRIL = 158.987;

    // Clasificación por descripción del concepto de factura, validada contra
    // los 4 acuses de agosto 2026: Petrotal usa "MAXIMA"/"T-SUPER PREMIUM" en
    // sus propias facturas de venta; Tesoro usa "UNBRANDED REGULAR/PREMIUM
    // GAS"; ESSA usa "Diésel". No se asume un producto por defecto — lo que
    // no clasifica se excluye y se reporta como advertencia.
    public function clasificar_producto(string $descripcion): ?array {
        $d = mb_strtoupper($descripcion, 'UTF-8');
        if (strpos($d, 'PREMIUM') !== false || strpos($d, 'SUPER') !== false) {
            return ['producto_id' => 7, 'subproducto_id' => 14, 'label' => 'Premium'];
        }
        if (strpos($d, 'REGULAR') !== false || strpos($d, 'MAXIMA') !== false || strpos($d, 'MÁXIMA') !== false) {
            return ['producto_id' => 7, 'subproducto_id' => 13, 'label' => 'Regular'];
        }
        if (strpos($d, 'DIESEL') !== false || strpos($d, 'DIÉSEL') !== false || strpos($d, 'DIÉ') !== false) {
            return ['producto_id' => 3, 'subproducto_id' => 62, 'label' => 'Diesel'];
        }
        return null;
    }
}
```

- [ ] **Step 2: Escribir el script de prueba manual para `clasificar_producto`**

```php
<?php
// tools/test_petrotal_obligacion_model.php
$_SERVER['DOCUMENT_ROOT'] = __DIR__ . '/..';
$_SERVER['REQUEST_URI'] = '/';
chdir($_SERVER['DOCUMENT_ROOT']);
require $_SERVER['DOCUMENT_ROOT'] . '/_assets/classes/header.class.php';
require $_SERVER['DOCUMENT_ROOT'] . '/_assets/classes/php_functions.php';
spl_autoload_register(function ($class) {
    if (file_exists(CLASSES . $class . '.class.php')) require CLASSES . $class . '.class.php';
    if (file_exists(MODELS . $class . '.php')) require MODELS . $class . '.php';
});

$model = new PetrotalObligacionModel();

$casos = [
    'MAXIMA' => ['producto_id' => 7, 'subproducto_id' => 13],
    'T-SUPER PREMIUM' => ['producto_id' => 7, 'subproducto_id' => 14],
    'DIESEL' => ['producto_id' => 3, 'subproducto_id' => 62],
    'UNBRANDED REGULAR GAS H/19873/COM/2017' => ['producto_id' => 7, 'subproducto_id' => 13],
    'UNBRANDED PREMIUM GAS H/19873/COM/2017' => ['producto_id' => 7, 'subproducto_id' => 14],
    'Diésel ESSA' => ['producto_id' => 3, 'subproducto_id' => 62],
    'PEMEX MAGNA' => null,
];

$fallos = 0;
foreach ($casos as $desc => $esperado) {
    $resultado = $model->clasificar_producto($desc);
    $ok = $esperado === null
        ? $resultado === null
        : ($resultado && $resultado['producto_id'] === $esperado['producto_id'] && $resultado['subproducto_id'] === $esperado['subproducto_id']);
    echo ($ok ? "OK  " : "FAIL") . " clasificar_producto('$desc') => " . json_encode($resultado) . "\n";
    if (!$ok) $fallos++;
}

echo $fallos === 0 ? "\nTodos los casos pasaron.\n" : "\n$fallos caso(s) fallaron.\n";
```

- [ ] **Step 3: Correr el script y verificar que todos los casos pasen**

Run: `php tools/test_petrotal_obligacion_model.php`
Expected: `OK` en las 7 líneas, "Todos los casos pasaron." al final.

- [ ] **Step 4: Commit**

```bash
git add _assets/models/PetrotalObligacionModel.php tools/test_petrotal_obligacion_model.php
git commit -m "Agrega clasificación de producto para reporte CNE Petrotal"
```

---

## Task 4: `PetrotalObligacionModel` — resolución de contraparte y consulta de facturas

**Files:**
- Modify: `_assets/models/PetrotalObligacionModel.php`
- Modify: `tools/test_petrotal_obligacion_model.php`

**Interfaces:**
- Consumes: `clasificar_producto()` de Task 3.
- Produces:
  - `PetrotalObligacionModel::resolver_cliente(string $rfc): ?array` — retorna `['permiso_cre' => string, 'candidatos' => array]` consultando `XmlCre`; si hay más de un `NumeroPermisoCRE` para el mismo RFC, `permiso_cre` es `null` y `candidatos` trae todos los permisos encontrados (para que el controlador pida desambiguación).
  - `PetrotalObligacionModel::resolver_proveedor(string $rfc): ?string` — retorna el `nropcc` de `SG12.dbo.Proveedores` o `null` si no existe/está vacío.
  - `PetrotalObligacionModel::obtener_facturas_venta(string $desde, string $hasta): array` — filas crudas de `FacturasRecibidas` + `FacturasRecibidasConceptos` donde `EmisorRfc = PetrotalReconciliationModel::PETROTAL_RFC`.
  - `PetrotalObligacionModel::obtener_facturas_compra(string $desde, string $hasta): array` — mismo query con `ReceptorRfc = PetrotalReconciliationModel::PETROTAL_RFC`.

- [ ] **Step 1: Agregar los métodos de consulta y resolución al modelo**

```php
    // Resuelve el permiso CRE de un cliente vía el catálogo de estaciones de
    // servicio con volumétricos (XmlCre). Un mismo RFC puede tener más de un
    // permiso (ej. Estación Custodia, Díaz Gas con múltiples estaciones) —
    // en ese caso no se adivina: se listan los candidatos para que el
    // controlador pida desambiguación en el preview.
    public function resolver_cliente(string $rfc): ?array {
        $rows = $this->sql->select(
            "SELECT DISTINCT NumeroPermisoCRE FROM XmlCre WHERE Rfc = ?",
            [$rfc]
        );
        if (!$rows) return null;
        $permisos = array_column($rows, 'NumeroPermisoCRE');
        return [
            'permiso_cre' => count($permisos) === 1 ? $permisos[0] : null,
            'candidatos' => $permisos,
        ];
    }

    // Resuelve el permiso de comercializador de un proveedor vía
    // SG12.dbo.Proveedores.nropcc (confirmado contra Tesoro H/19873/COM/2017
    // y ESSA H/23183/COM/2020, coincide con los acuses reales de agosto
    // 2026). Solo lectura sobre SG12, nunca se escribe ahí.
    public function resolver_proveedor(string $rfc): ?string {
        $rows = $this->sql->select(
            "SELECT nropcc FROM SG12.dbo.Proveedores WHERE rfc = ?",
            [$rfc]
        );
        $permiso = $rows[0]['nropcc'] ?? null;
        return ($permiso !== null && trim($permiso) !== '') ? trim($permiso) : null;
    }

    // Facturas donde Petrotal es el EMISOR: lo que Petrotal vendió a sus
    // clientes (estaciones de servicio) en el periodo.
    public function obtener_facturas_venta(string $desde, string $hasta): array {
        $query = "
            SELECT fr.Id AS FacturaId, fr.Fecha, fr.Folio, fr.Total,
                   fr.ReceptorRfc AS ContraparteRfc, fr.ReceptorNombre AS ContraparteNombre,
                   c.Cantidad, c.Descripcion
            FROM FacturasRecibidas fr
            JOIN FacturasRecibidasConceptos c ON c.FacturaId = fr.Id
            WHERE fr.EmisorRfc = ?
              AND fr.Fecha BETWEEN ? AND ?
            ORDER BY fr.Fecha
        ";
        return $this->sql->select($query, [
            PetrotalReconciliationModel::PETROTAL_RFC,
            $desde . ' 00:00:00',
            $hasta . ' 23:59:59',
        ]) ?: [];
    }

    // Facturas donde Petrotal es el RECEPTOR: lo que Petrotal compró a sus
    // proveedores (comercializadores mayoristas) en el periodo.
    public function obtener_facturas_compra(string $desde, string $hasta): array {
        $query = "
            SELECT fr.Id AS FacturaId, fr.Fecha, fr.Folio, fr.Total,
                   fr.EmisorRfc AS ContraparteRfc, fr.EmisorNombre AS ContraparteNombre,
                   c.Cantidad, c.Descripcion
            FROM FacturasRecibidas fr
            JOIN FacturasRecibidasConceptos c ON c.FacturaId = fr.Id
            WHERE fr.ReceptorRfc = ?
              AND fr.Fecha BETWEEN ? AND ?
            ORDER BY fr.Fecha
        ";
        return $this->sql->select($query, [
            PetrotalReconciliationModel::PETROTAL_RFC,
            $desde . ' 00:00:00',
            $hasta . ' 23:59:59',
        ]) ?: [];
    }
```

- [ ] **Step 2: Agregar casos de prueba manual al script de Task 3**

Agregar al final de `tools/test_petrotal_obligacion_model.php` (antes del bloque de resumen de fallos, ajustando el contador `$fallos`):

```php
echo "\n--- resolver_cliente / resolver_proveedor (requiere BD) ---\n";
$casosCliente = [
    'ECU0602287R6' => 'multiple', // Estación Custodia, 2 permisos conocidos
    'GVA9709154V2' => 'PL/5114/EXP/ES/2015', // Villa Ahumada, único permiso
    'RFC_INEXISTENTE_XYZ' => null,
];
foreach ($casosCliente as $rfc => $esperado) {
    $resultado = $model->resolver_cliente($rfc);
    echo "resolver_cliente('$rfc') => " . json_encode($resultado) . "\n";
}

$casosProveedor = [
    'TMS1611162N5' => 'H/19873/COM/2017', // Tesoro
    'EFA1903122IA' => 'H/23183/COM/2020', // ESSA
    'RFC_INEXISTENTE_XYZ' => null,
];
foreach ($casosProveedor as $rfc => $esperado) {
    $resultado = $model->resolver_proveedor($rfc);
    $ok = $resultado === $esperado;
    echo ($ok ? "OK  " : "FAIL") . " resolver_proveedor('$rfc') => " . json_encode($resultado) . " (esperado: " . json_encode($esperado) . ")\n";
    if (!$ok) $fallos++;
}

echo "\n--- obtener_facturas_venta / obtener_facturas_compra (semana 21-27 ago 2026) ---\n";
$ventas = $model->obtener_facturas_venta('2026-08-21', '2026-08-27');
$compras = $model->obtener_facturas_compra('2026-08-21', '2026-08-27');
echo "Ventas encontradas: " . count($ventas) . " (esperado > 0)\n";
echo "Compras encontradas: " . count($compras) . " (esperado > 0)\n";
if (count($ventas) === 0 || count($compras) === 0) $fallos++;
```

- [ ] **Step 3: Correr el script y verificar**

Run: `php tools/test_petrotal_obligacion_model.php`
Expected:
- `resolver_cliente('ECU0602287R6')` devuelve `permiso_cre: null` con 2 candidatos.
- `resolver_cliente('GVA9709154V2')` devuelve `permiso_cre: "PL/5114/EXP/ES/2015"`.
- `resolver_proveedor('TMS1611162N5')` → `OK` con `"H/19873/COM/2017"`.
- `resolver_proveedor('EFA1903122IA')` → `OK` con `"H/23183/COM/2020"`.
- Ventas y compras de la semana 21-27 ago ambas > 0.
- "Todos los casos pasaron." o el conteo de fallos en 0.

- [ ] **Step 4: Commit**

```bash
git add _assets/models/PetrotalObligacionModel.php tools/test_petrotal_obligacion_model.php
git commit -m "Agrega resolución de contraparte (XmlCre/SG12.Proveedores) al modelo Petrotal"
```

---

## Task 5: `PetrotalObligacionModel` — ensamblado del reporte completo

**Files:**
- Modify: `_assets/models/PetrotalObligacionModel.php`
- Modify: `tools/test_petrotal_obligacion_model.php`

**Interfaces:**
- Consumes: `clasificar_producto()`, `resolver_cliente()`, `resolver_proveedor()`, `obtener_facturas_venta()`, `obtener_facturas_compra()` (Tasks 3-4).
- Produces: `PetrotalObligacionModel::construir_reporte(string $desde, string $hasta): array` con la forma:
  ```php
  [
      'ventas' => [
          [
              'fecha' => '2026-08-21', 'factura_id' => 123, 'folio' => 'PET31496',
              'producto_id' => 7, 'subproducto_id' => 13, 'producto_label' => 'Regular',
              'contraparte_rfc' => 'ECU0602287R6', 'contraparte_nombre' => 'ESTACION CUSTODIA SACV',
              'permiso_cre' => 'PL/2060/EXP/ES/2015', 'volumen_bbl' => 88.88, 'precio' => 3301.47,
              'descripcion_original' => 'MAXIMA',
          ],
          // ...
      ],
      'compras' => [ /* misma forma, contraparte_rfc/nombre = proveedor */ ],
      'advertencias' => [
          ['tipo' => 'sin_permiso', 'contraparte_rfc' => '...', 'contraparte_nombre' => '...', 'mensaje' => '...'],
          ['tipo' => 'producto_no_clasificado', 'factura_id' => ..., 'descripcion' => '...', 'mensaje' => '...'],
          ['tipo' => 'permiso_ambiguo', 'contraparte_rfc' => '...', 'candidatos' => [...], 'mensaje' => '...'],
      ],
  ]
  ```

- [ ] **Step 1: Agregar el método de ensamblado**

```php
    // Punto de entrada del modelo: arma el reporte completo del periodo,
    // clasificando producto y resolviendo contraparte fila por fila. No
    // excluye silenciosamente nada que no resuelva — todo lo problemático
    // queda en 'advertencias' para que el controlador decida qué mostrar.
    public function construir_reporte(string $desde, string $hasta): array {
        $ventas = [];
        $compras = [];
        $advertencias = [];

        $filasVenta = $this->obtener_facturas_venta($desde, $hasta);
        foreach ($filasVenta as $fila) {
            $this->procesar_fila($fila, 'cliente', $ventas, $advertencias);
        }

        $filasCompra = $this->obtener_facturas_compra($desde, $hasta);
        foreach ($filasCompra as $fila) {
            $this->procesar_fila($fila, 'proveedor', $compras, $advertencias);
        }

        return ['ventas' => $ventas, 'compras' => $compras, 'advertencias' => $advertencias];
    }

    private function procesar_fila(array $fila, string $tipoContraparte, array &$destino, array &$advertencias): void {
        $clasificacion = $this->clasificar_producto($fila['Descripcion']);
        if ($clasificacion === null) {
            $advertencias[] = [
                'tipo' => 'producto_no_clasificado',
                'factura_id' => $fila['FacturaId'],
                'descripcion' => $fila['Descripcion'],
                'mensaje' => "Factura {$fila['Folio']}: descripción \"{$fila['Descripcion']}\" no se pudo clasificar como Regular/Premium/Diesel.",
            ];
            return;
        }

        $rfc = $fila['ContraparteRfc'];
        $permisoCre = null;
        if ($tipoContraparte === 'cliente') {
            $resolucion = $this->resolver_cliente($rfc);
            if ($resolucion === null) {
                $advertencias[] = [
                    'tipo' => 'sin_permiso',
                    'contraparte_rfc' => $rfc,
                    'contraparte_nombre' => $fila['ContraparteNombre'],
                    'mensaje' => "Cliente {$fila['ContraparteNombre']} ({$rfc}) no tiene NumeroPermisoCRE en XmlCre.",
                ];
                return;
            }
            if ($resolucion['permiso_cre'] === null) {
                $advertencias[] = [
                    'tipo' => 'permiso_ambiguo',
                    'contraparte_rfc' => $rfc,
                    'contraparte_nombre' => $fila['ContraparteNombre'],
                    'candidatos' => $resolucion['candidatos'],
                    'mensaje' => "Cliente {$fila['ContraparteNombre']} ({$rfc}) tiene más de un permiso CRE, requiere selección manual.",
                ];
                return;
            }
            $permisoCre = $resolucion['permiso_cre'];
        } else {
            $permisoCre = $this->resolver_proveedor($rfc);
            if ($permisoCre === null) {
                $advertencias[] = [
                    'tipo' => 'sin_permiso',
                    'contraparte_rfc' => $rfc,
                    'contraparte_nombre' => $fila['ContraparteNombre'],
                    'mensaje' => "Proveedor {$fila['ContraparteNombre']} ({$rfc}) no tiene nropcc en SG12.Proveedores.",
                ];
                return;
            }
        }

        $volumenBbl = round(((float) $fila['Cantidad']) / self::LITROS_POR_BARRIL, 2);
        $total = (float) $fila['Total'];
        $precio = $volumenBbl > 0 ? round($total / $volumenBbl, 2) : 0.0;

        $destino[] = [
            'fecha' => substr($fila['Fecha'], 0, 10),
            'factura_id' => $fila['FacturaId'],
            'folio' => $fila['Folio'],
            'producto_id' => $clasificacion['producto_id'],
            'subproducto_id' => $clasificacion['subproducto_id'],
            'producto_label' => $clasificacion['label'],
            'contraparte_rfc' => $rfc,
            'contraparte_nombre' => $fila['ContraparteNombre'],
            'permiso_cre' => $permisoCre,
            'volumen_bbl' => $volumenBbl,
            'precio' => $precio,
            'descripcion_original' => $fila['Descripcion'],
        ];
    }
```

Nota: el `precio` aquí es `Total factura / volumen en barriles`, que aproxima el `PrecioVenta`/`PrecioCompra` en $/barril que pide el XSD. No es exactamente el precio unitario de la factura (que viene en $/litro) — es una derivación intencional para cumplir el campo requerido del reporte; no se necesita mayor precisión porque el volumen ya es la magnitud que se valida contra los acuses.

- [ ] **Step 2: Agregar prueba de ensamblado completo al script**

```php
echo "\n--- construir_reporte (semana 21-27 ago 2026, comparar contra acuse real) ---\n";
$reporte = $model->construir_reporte('2026-08-21', '2026-08-27');
echo "Ventas: " . count($reporte['ventas']) . " filas\n";
echo "Compras: " . count($reporte['compras']) . " filas\n";
echo "Advertencias: " . count($reporte['advertencias']) . "\n";
foreach ($reporte['advertencias'] as $a) echo "  [{$a['tipo']}] {$a['mensaje']}\n";

$sumaRegularVentas = array_sum(array_map(fn($v) => $v['producto_label'] === 'Regular' ? $v['volumen_bbl'] : 0, $reporte['ventas']));
echo "Suma Regular ventas: $sumaRegularVentas (acuse semana 21-27ago declaró 1460.73 bbl)\n";
```

- [ ] **Step 3: Correr el script y comparar contra el acuse real**

Run: `php tools/test_petrotal_obligacion_model.php`
Expected: `Suma Regular ventas` cercano a `1460.73` (el total declarado en el acuse `B8CA2CFC` de la semana 21-27 ago, ya validado en la conversación previa). Diferencias menores por redondeo de conversión son aceptables; diferencias grandes indican un bug en `procesar_fila` o en la query — investigar antes de continuar.

- [ ] **Step 4: Commit**

```bash
git add _assets/models/PetrotalObligacionModel.php tools/test_petrotal_obligacion_model.php
git commit -m "Agrega ensamblado completo del reporte Petrotal (construir_reporte)"
```

---

## Task 6: `PetrotalObligacionModel` — CRUD de historial de envíos

**Files:**
- Modify: `_assets/models/PetrotalObligacionModel.php`
- Modify: `tools/test_petrotal_obligacion_model.php`

**Interfaces:**
- Produces:
  - `PetrotalObligacionModel::crear_envio(string $desde, string $hasta, int $usuarioId): int` — inserta fila en `PetrotalReportesObligacion` con `Estado = 'borrador'`, retorna `Id`.
  - `PetrotalObligacionModel::actualizar_envio(int $id, array $campos): bool` — actualiza cualquier subconjunto de `Estado`, `RutaJson`, `RutaAcuse`, `FolioAcuse`, `RespuestaRaw`, `FechaEnvio`; siempre setea `UpdatedAt = GETDATE()`.
  - `PetrotalObligacionModel::buscar_envio_periodo(string $desde, string $hasta): ?array` — busca un envío existente para ese periodo exacto.
  - `PetrotalObligacionModel::listar_envios(): array` — todos los envíos ordenados por `PeriodoDesde DESC`.
  - `PetrotalObligacionModel::obtener_envio(int $id): ?array` — una fila por `Id`.

- [ ] **Step 1: Agregar los métodos CRUD**

```php
    public function crear_envio(string $desde, string $hasta, int $usuarioId): int {
        $id = $this->sql->insert(
            "INSERT INTO PetrotalReportesObligacion (PeriodoDesde, PeriodoHasta, Estado, UsuarioId) VALUES (?, ?, 'borrador', ?)",
            [$desde, $hasta, $usuarioId]
        );
        return (int) $id;
    }

    public function actualizar_envio(int $id, array $campos): bool {
        $columnasPermitidas = ['Estado', 'RutaJson', 'RutaAcuse', 'FolioAcuse', 'RespuestaRaw', 'FechaEnvio'];
        $sets = [];
        $params = [];
        foreach ($campos as $columna => $valor) {
            if (!in_array($columna, $columnasPermitidas, true)) continue;
            $sets[] = "{$columna} = ?";
            $params[] = $valor;
        }
        if (!$sets) return false;
        $sets[] = "UpdatedAt = GETDATE()";
        $params[] = $id;

        $query = "UPDATE PetrotalReportesObligacion SET " . implode(', ', $sets) . " WHERE Id = ?";
        return (bool) $this->sql->update($query, $params);
    }

    public function buscar_envio_periodo(string $desde, string $hasta): ?array {
        $rows = $this->sql->select(
            "SELECT * FROM PetrotalReportesObligacion WHERE PeriodoDesde = ? AND PeriodoHasta = ?",
            [$desde, $hasta]
        );
        return $rows[0] ?? null;
    }

    public function listar_envios(): array {
        return $this->sql->select("SELECT * FROM PetrotalReportesObligacion ORDER BY PeriodoDesde DESC", []) ?: [];
    }

    public function obtener_envio(int $id): ?array {
        $rows = $this->sql->select("SELECT * FROM PetrotalReportesObligacion WHERE Id = ?", [$id]);
        return $rows[0] ?? null;
    }
```

- [ ] **Step 2: Agregar prueba manual del ciclo CRUD**

```php
echo "\n--- CRUD PetrotalReportesObligacion ---\n";
$idPrueba = $model->crear_envio('2099-01-05', '2099-01-11', 1); // periodo ficticio para no chocar con datos reales
echo "Creado Id=$idPrueba\n";

$envio = $model->buscar_envio_periodo('2099-01-05', '2099-01-11');
$ok = $envio && $envio['Estado'] === 'borrador';
echo ($ok ? "OK  " : "FAIL") . " buscar_envio_periodo devuelve Estado=borrador\n";
if (!$ok) $fallos++;

$actualizado = $model->actualizar_envio($idPrueba, ['Estado' => 'enviado', 'FolioAcuse' => 'TEST123']);
echo ($actualizado ? "OK  " : "FAIL") . " actualizar_envio retorna true\n";
if (!$actualizado) $fallos++;

$envioActualizado = $model->obtener_envio($idPrueba);
$ok2 = $envioActualizado && $envioActualizado['Estado'] === 'enviado' && $envioActualizado['FolioAcuse'] === 'TEST123';
echo ($ok2 ? "OK  " : "FAIL") . " obtener_envio refleja el cambio\n";
if (!$ok2) $fallos++;

// Limpieza del registro de prueba
$model->sql->update("DELETE FROM PetrotalReportesObligacion WHERE Id = ?", [$idPrueba]);
echo "Registro de prueba Id=$idPrueba eliminado.\n";
```

- [ ] **Step 3: Correr el script y verificar el ciclo completo**

Run: `php tools/test_petrotal_obligacion_model.php`
Expected: los 3 `OK` del bloque CRUD, y confirmación de que el registro de prueba fue eliminado (no debe quedar basura en `PetrotalReportesObligacion`).

- [ ] **Step 4: Commit**

```bash
git add _assets/models/PetrotalObligacionModel.php tools/test_petrotal_obligacion_model.php
git commit -m "Agrega CRUD de historial de envíos a PetrotalObligacionModel"
```

---

## Task 7: `PetrotalFielModel` — configuración de la FIEL

**Files:**
- Create: `_assets/models/PetrotalFielModel.php`
- Modify: `_assets/classes/header.class.php` (agregar constante de cifrado)
- Test: `tools/test_petrotal_fiel_model.php`

**Interfaces:**
- Consumes: constante `PETROTAL_FIEL_ENCRYPTION_KEY` definida en `header.class.php`.
- Produces:
  - `PetrotalFielModel::guardar_config(string $rutaCer, string $rutaKey, string $password, int $usuarioId): bool` — cifra el password con `openssl_encrypt` (AES-256-CBC), borra cualquier config previa e inserta la nueva (solo una fila activa a la vez, como define el spec).
  - `PetrotalFielModel::obtener_config(): ?array` — retorna `['ruta_cer' => string, 'ruta_key' => string, 'password' => string]` con el password ya descifrado, o `null` si no hay configuración.

- [ ] **Step 1: Agregar la constante de cifrado a `header.class.php`**

Modificar `_assets/classes/header.class.php`, después de la línea 59 (`define('DEFAULT_ERROR_CONTROLLER', 'error');`):

```php
// Clave de cifrado simétrico para secretos guardados en BD (ej. password de
// la FIEL de Petrotal en PetrotalFielConfig). AES-256-CBC vía openssl_*,
// nativo de PHP, sin dependencias nuevas.
define('PETROTAL_FIEL_ENCRYPTION_KEY', 'TG_Petrotal_FIEL_2026_ChangeMe!');
```

- [ ] **Step 2: Escribir el modelo**

```php
<?php
class PetrotalFielModel extends Model {

    private function cifrar(string $texto): string {
        $iv = openssl_random_pseudo_bytes(16);
        $cifrado = openssl_encrypt($texto, 'AES-256-CBC', PETROTAL_FIEL_ENCRYPTION_KEY, 0, $iv);
        return base64_encode($iv . $cifrado);
    }

    private function descifrar(string $textoCifrado): string {
        $datos = base64_decode($textoCifrado);
        $iv = substr($datos, 0, 16);
        $cifrado = substr($datos, 16);
        return openssl_decrypt($cifrado, 'AES-256-CBC', PETROTAL_FIEL_ENCRYPTION_KEY, 0, $iv);
    }

    // Solo una configuración activa a la vez (spec: "se sobreescribe al
    // resubir"). Se borra la anterior antes de insertar la nueva dentro de
    // una transacción para no dejar el módulo sin configuración si el
    // insert falla a medio camino.
    public function guardar_config(string $rutaCer, string $rutaKey, string $password, int $usuarioId): bool {
        $passwordCifrado = $this->cifrar($password);

        $this->sql->beginTransaction();
        try {
            $this->sql->update("DELETE FROM PetrotalFielConfig", []);
            $this->sql->insert(
                "INSERT INTO PetrotalFielConfig (RutaCer, RutaKey, PasswordCifrado, ActualizadoPor) VALUES (?, ?, ?, ?)",
                [$rutaCer, $rutaKey, $passwordCifrado, $usuarioId]
            );
            $this->sql->commit();
            return true;
        } catch (Exception $e) {
            $this->sql->rollBack();
            return false;
        }
    }

    public function obtener_config(): ?array {
        $rows = $this->sql->select("SELECT TOP 1 * FROM PetrotalFielConfig ORDER BY UpdatedAt DESC", []);
        if (!$rows) return null;
        return [
            'ruta_cer' => $rows[0]['RutaCer'],
            'ruta_key' => $rows[0]['RutaKey'],
            'password' => $this->descifrar($rows[0]['PasswordCifrado']),
        ];
    }
}
```

- [ ] **Step 3: Escribir el script de prueba manual**

```php
<?php
// tools/test_petrotal_fiel_model.php
$_SERVER['DOCUMENT_ROOT'] = __DIR__ . '/..';
$_SERVER['REQUEST_URI'] = '/';
chdir($_SERVER['DOCUMENT_ROOT']);
require $_SERVER['DOCUMENT_ROOT'] . '/_assets/classes/header.class.php';
require $_SERVER['DOCUMENT_ROOT'] . '/_assets/classes/php_functions.php';
spl_autoload_register(function ($class) {
    if (file_exists(CLASSES . $class . '.class.php')) require CLASSES . $class . '.class.php';
    if (file_exists(MODELS . $class . '.php')) require MODELS . $class . '.php';
});

$model = new PetrotalFielModel();
$fallos = 0;

$guardado = $model->guardar_config('/ruta/prueba.cer', '/ruta/prueba.key', 'PasswordDePrueba123', 1);
echo ($guardado ? "OK  " : "FAIL") . " guardar_config retorna true\n";
if (!$guardado) $fallos++;

$config = $model->obtener_config();
$ok = $config && $config['ruta_cer'] === '/ruta/prueba.cer' && $config['password'] === 'PasswordDePrueba123';
echo ($ok ? "OK  " : "FAIL") . " obtener_config descifra el password correctamente: " . json_encode($config) . "\n";
if (!$ok) $fallos++;

// Limpieza
$model->sql->update("DELETE FROM PetrotalFielConfig", []);
echo "Configuración de prueba eliminada.\n";

echo $fallos === 0 ? "\nTodos los casos pasaron.\n" : "\n$fallos caso(s) fallaron.\n";
```

- [ ] **Step 4: Correr el script y verificar**

Run: `php tools/test_petrotal_fiel_model.php`
Expected: ambos `OK`, password descifrado idéntico al original en texto plano, "Todos los casos pasaron."

- [ ] **Step 5: Commit**

```bash
git add _assets/models/PetrotalFielModel.php _assets/classes/header.class.php tools/test_petrotal_fiel_model.php
git commit -m "Agrega PetrotalFielModel con cifrado de password de la FIEL"
```

---

## Task 8: `PetrotalCneClient` — construcción del JSON y cliente HTTP

**Files:**
- Create: `_assets/classes/PetrotalCneClient.class.php`
- Test: `tools/test_petrotal_cne_client.php`

**Interfaces:**
- Consumes: la forma de `construir_reporte()` (Task 5): `['ventas' => array, 'compras' => array]`.
- Produces:
  - `PetrotalCneClient::armar_json(string $numeroPermiso, string $desde, string $hasta, array $ventas, array $compras): string` — retorna el JSON codificado siguiendo la estructura `Permiso→Fecha→Producto→VentasNacional/ComprasNacional→PermisionarioCRECliente/PermisionarioCREProveedor` del XSD.
  - `PetrotalCneClient::enviar(string $jsonPath, string $cerPath, string $keyPath, string $password, string $numeroPermiso): array` — retorna `['ok' => bool, 'rc' => int|null, 'msg' => string, 'acuse' => array|null, 'link_descarga' => string|null, 'error_conexion' => string|null]`.
  - `PetrotalCneClient::descargar_acuse(string $linkDescarga, string $destinoPath): bool`.

- [ ] **Step 1: Escribir `armar_json`**

```php
<?php
class PetrotalCneClient {

    const API_BASE_URL = 'https://api-masivos-qa.cne.gob.mx';
    const ENDPOINT_REPORTE = self::API_BASE_URL . '/ReporteObligacion';

    // Arma el JSON con la forma Permiso->Fecha->Producto->VentasNacional/
    // ComprasNacional->PermisionarioCRECliente/PermisionarioCREProveedor,
    // agrupando las filas planas del modelo (una por factura/concepto) en
    // la jerarquía que pide el XSD. Todas las contrapartes vistas hasta
    // ahora son Permisionario CRE (nunca UsuarioFinal), así que solo se
    // construye ese nodo.
    public static function armar_json(string $numeroPermiso, string $desde, string $hasta, array $ventas, array $compras): string {
        $porFecha = [];

        foreach ($ventas as $fila) {
            $porFecha[$fila['fecha']]['productos'][self::claveProducto($fila)]['ventas'][] = [
                'NumeroPermisoCRECliente' => $fila['permiso_cre'],
                'PrecioVenta' => $fila['precio'],
                'VolumenVendido' => $fila['volumen_bbl'],
            ];
            $porFecha[$fila['fecha']]['productos'][self::claveProducto($fila)]['producto_id'] = $fila['producto_id'];
            $porFecha[$fila['fecha']]['productos'][self::claveProducto($fila)]['subproducto_id'] = $fila['subproducto_id'];
        }

        foreach ($compras as $fila) {
            $porFecha[$fila['fecha']]['productos'][self::claveProducto($fila)]['compras'][] = [
                'NumeroPermisoCREProveedor' => $fila['permiso_cre'],
                'PrecioCompra' => $fila['precio'],
                'VolumenComprado' => $fila['volumen_bbl'],
            ];
            $porFecha[$fila['fecha']]['productos'][self::claveProducto($fila)]['producto_id'] = $fila['producto_id'];
            $porFecha[$fila['fecha']]['productos'][self::claveProducto($fila)]['subproducto_id'] = $fila['subproducto_id'];
        }

        ksort($porFecha);

        $fechasJson = [];
        foreach ($porFecha as $fecha => $datos) {
            $productosJson = [];
            foreach ($datos['productos'] as $producto) {
                $nodoProducto = [
                    'ProductoId' => $producto['producto_id'],
                    'SubProductoId' => $producto['subproducto_id'],
                ];
                if (!empty($producto['ventas'])) {
                    $nodoProducto['VentasNacional'] = [
                        ['PermisionarioCRECliente' => $producto['ventas']],
                    ];
                }
                if (!empty($producto['compras'])) {
                    $nodoProducto['ComprasNacional'] = [
                        ['PermisionarioCREProveedor' => $producto['compras']],
                    ];
                }
                $productosJson[] = $nodoProducto;
            }
            $fechasJson[] = [
                'Diaareportar' => $fecha,
                'Producto' => $productosJson,
            ];
        }

        $estructura = [
            'Permiso' => [
                'Numero' => $numeroPermiso,
                'FechaInicio' => $desde,
                'FechaFin' => $hasta,
                'TipoReporte' => 1,
                'Fecha' => $fechasJson,
            ],
        ];

        return json_encode($estructura, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    private static function claveProducto(array $fila): string {
        return $fila['producto_id'] . '_' . $fila['subproducto_id'];
    }
}
```

- [ ] **Step 2: Escribir prueba manual de `armar_json` con datos de ejemplo fijos**

```php
<?php
// tools/test_petrotal_cne_client.php
$_SERVER['DOCUMENT_ROOT'] = __DIR__ . '/..';
$_SERVER['REQUEST_URI'] = '/';
chdir($_SERVER['DOCUMENT_ROOT']);
require $_SERVER['DOCUMENT_ROOT'] . '/_assets/classes/header.class.php';
require $_SERVER['DOCUMENT_ROOT'] . '/_assets/classes/php_functions.php';
spl_autoload_register(function ($class) {
    if (file_exists(CLASSES . $class . '.class.php')) require CLASSES . $class . '.class.php';
    if (file_exists(MODELS . $class . '.php')) require MODELS . $class . '.php';
});

$ventas = [
    [
        'fecha' => '2026-08-21', 'producto_id' => 7, 'subproducto_id' => 13,
        'permiso_cre' => 'PL/2060/EXP/ES/2015', 'volumen_bbl' => 88.88, 'precio' => 3301.47,
    ],
];
$compras = [
    [
        'fecha' => '2026-08-21', 'producto_id' => 7, 'subproducto_id' => 13,
        'permiso_cre' => 'H/19873/COM/2017', 'volumen_bbl' => 88.88, 'precio' => 3215.62,
    ],
];

$json = PetrotalCneClient::armar_json('H/22730/COM/2019', '2026-08-21', '2026-08-27', $ventas, $compras);
echo $json . "\n\n";

$decoded = json_decode($json, true);
$fallos = 0;

$ok = $decoded['Permiso']['Numero'] === 'H/22730/COM/2019';
echo ($ok ? "OK  " : "FAIL") . " Permiso.Numero correcto\n";
if (!$ok) $fallos++;

$ok2 = count($decoded['Permiso']['Fecha']) === 1 && $decoded['Permiso']['Fecha'][0]['Diaareportar'] === '2026-08-21';
echo ($ok2 ? "OK  " : "FAIL") . " Una sola fecha agrupada correctamente\n";
if (!$ok2) $fallos++;

$producto = $decoded['Permiso']['Fecha'][0]['Producto'][0];
$ok3 = $producto['ProductoId'] === 7 && $producto['SubProductoId'] === 13;
echo ($ok3 ? "OK  " : "FAIL") . " ProductoId/SubProductoId correctos\n";
if (!$ok3) $fallos++;

$ok4 = isset($producto['VentasNacional'][0]['PermisionarioCRECliente'][0]['VolumenVendido'])
    && $producto['VentasNacional'][0]['PermisionarioCRECliente'][0]['VolumenVendido'] === 88.88;
echo ($ok4 ? "OK  " : "FAIL") . " VentasNacional.PermisionarioCRECliente.VolumenVendido correcto\n";
if (!$ok4) $fallos++;

$ok5 = isset($producto['ComprasNacional'][0]['PermisionarioCREProveedor'][0]['VolumenComprado'])
    && $producto['ComprasNacional'][0]['PermisionarioCREProveedor'][0]['VolumenComprado'] === 88.88;
echo ($ok5 ? "OK  " : "FAIL") . " ComprasNacional.PermisionarioCREProveedor.VolumenComprado correcto\n";
if (!$ok5) $fallos++;

echo $fallos === 0 ? "\nTodos los casos pasaron.\n" : "\n$fallos caso(s) fallaron.\n";
```

- [ ] **Step 3: Correr el script y verificar**

Run: `php tools/test_petrotal_cne_client.php`
Expected: JSON válido impreso, los 5 `OK`, "Todos los casos pasaron."

- [ ] **Step 4: Commit**

```bash
git add _assets/classes/PetrotalCneClient.class.php tools/test_petrotal_cne_client.php
git commit -m "Agrega armar_json para reporte CNE en PetrotalCneClient"
```

---

## Task 9: `PetrotalCneClient` — envío HTTP y descarga de acuse

**Files:**
- Modify: `_assets/classes/PetrotalCneClient.class.php`
- Modify: `tools/test_petrotal_cne_client.php`

**Interfaces:**
- Consumes: `armar_json()` (Task 8).
- Produces: `enviar()` y `descargar_acuse()` (firmas descritas al inicio de Task 8).

- [ ] **Step 1: Agregar `enviar()` y `descargar_acuse()` a la clase**

```php
    // Envía el reporte a la API de la CNE vía multipart/form-data, siguiendo
    // exactamente el ejemplo curl del documento técnico "Web Service-COM":
    // reporte (json), cerFile, keyFile, password, permiso. No lanza
    // excepción ante fallas de red/HTTP — las reporta en 'error_conexion'
    // para que el caller decida cómo mostrarlas (el ambiente QA de la CNE
    // es intermitente, ver spec).
    public static function enviar(string $jsonPath, string $cerPath, string $keyPath, string $password, string $numeroPermiso): array {
        $resultado = [
            'ok' => false, 'rc' => null, 'msg' => '', 'acuse' => null,
            'link_descarga' => null, 'error_conexion' => null,
        ];

        if (!is_readable($jsonPath) || !is_readable($cerPath) || !is_readable($keyPath)) {
            $resultado['error_conexion'] = 'Uno o más archivos requeridos (JSON, .cer, .key) no son legibles.';
            return $resultado;
        }

        $ch = curl_init(self::ENDPOINT_REPORTE);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['accept: */*']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, [
            'reporte' => new CURLFile($jsonPath, 'application/json', basename($jsonPath)),
            'cerFile' => new CURLFile($cerPath, 'application/x-x509-ca-cert', basename($cerPath)),
            'keyFile' => new CURLFile($keyPath, 'application/octet-stream', basename($keyPath)),
            'password' => $password,
            'permiso' => $numeroPermiso,
        ]);

        $respuesta = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($respuesta === false || $curlError) {
            $resultado['error_conexion'] = "Error de conexión: {$curlError}";
            return $resultado;
        }

        if ($httpCode >= 500) {
            $resultado['error_conexion'] = "El servidor de la CNE respondió con error {$httpCode} (ambiente probablemente no disponible en este momento).";
            return $resultado;
        }

        $decoded = json_decode($respuesta, true);
        if ($decoded === null) {
            $resultado['error_conexion'] = "Respuesta no es JSON válido (HTTP {$httpCode}): " . substr($respuesta, 0, 500);
            return $resultado;
        }

        $resultado['rc'] = $decoded['rc'] ?? null;
        $resultado['msg'] = $decoded['msg'] ?? '';
        $resultado['acuse'] = $decoded['acuse'] ?? null;
        $resultado['link_descarga'] = $decoded['linkDescarga'] ?? null;
        $resultado['ok'] = ($resultado['rc'] === 0);

        return $resultado;
    }

    public static function descargar_acuse(string $linkDescarga, string $destinoPath): bool {
        $ch = curl_init($linkDescarga);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $contenido = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($contenido === false || $httpCode !== 200) {
            return false;
        }

        return file_put_contents($destinoPath, $contenido) !== false;
    }
```

- [ ] **Step 2: Agregar prueba manual de `enviar()` contra un endpoint que sí responde (sin depender de la CNE)**

No se puede probar contra la CNE real fuera de viernes (ambiente intermitente, confirmado en la conversación). La prueba manual valida el manejo de errores contra un endpoint controlado:

```php
echo "\n--- enviar() contra endpoint inexistente (validar manejo de error) ---\n";
// Usamos rutas de archivo temporales válidas para que el chequeo de
// legibilidad pase, y apuntamos el cURL a una URL que no puede responder
// 200 para validar la rama de error_conexion.
$tmpJson = sys_get_temp_dir() . '/test_petrotal.json';
$tmpCer = sys_get_temp_dir() . '/test_petrotal.cer';
$tmpKey = sys_get_temp_dir() . '/test_petrotal.key';
file_put_contents($tmpJson, '{}');
file_put_contents($tmpCer, 'dummy');
file_put_contents($tmpKey, 'dummy');

// Truco: probamos armar_json + PetrotalCneClient::enviar contra la URL real
// de QA, que hoy (no-viernes) devuelve 502 — así confirmamos en vivo que la
// rama de error_conexion por HTTP >= 500 funciona como se espera.
$resultado = PetrotalCneClient::enviar($tmpJson, $tmpCer, $tmpKey, 'x', 'H/22730/COM/2019');
echo "Resultado: " . json_encode($resultado) . "\n";
$ok = $resultado['ok'] === false && $resultado['error_conexion'] !== null;
echo ($ok ? "OK  " : "FAIL") . " enviar() reporta error_conexion sin lanzar excepción cuando la API no responde\n";
if (!$ok) $fallos++;

unlink($tmpJson);
unlink($tmpCer);
unlink($tmpKey);

echo $fallos === 0 ? "\nTodos los casos pasaron.\n" : "\n$fallos caso(s) fallaron.\n";
```

- [ ] **Step 3: Correr el script y verificar el manejo de error**

Run: `php tools/test_petrotal_cne_client.php`
Expected: `error_conexion` no nulo (la API de QA responderá 502 fuera de viernes, según lo observado), `ok` false, sin ninguna excepción no capturada ni fatal error. Si por coincidencia se corre un viernes y la API responde, validar que `rc`/`msg` vengan pobladas en vez de `error_conexion`.

- [ ] **Step 4: Commit**

```bash
git add _assets/classes/PetrotalCneClient.class.php tools/test_petrotal_cne_client.php
git commit -m "Agrega envío HTTP y descarga de acuse a PetrotalCneClient"
```

---

## Task 10: Controlador `petrotal.php` — pantalla principal y preview AJAX

**Files:**
- Create: `_assets/controllers/petrotal.php`
- Create: `views/petrotal/volumetricos.html`

**Interfaces:**
- Consumes: `PetrotalObligacionModel::construir_reporte()` (Task 5), `PetrotalObligacionModel::buscar_envio_periodo()` (Task 6).
- Produces: rutas `/petrotal/volumetricos` (GET, vista) y `/petrotal/preview_json` (GET AJAX, JSON).

- [ ] **Step 1: Escribir el controlador con constructor y método `volumetricos`**

```php
<?php
class Petrotal {
    public $twig;
    public $route;
    public PetrotalObligacionModel $petrotalObligacionModel;
    public PetrotalFielModel $petrotalFielModel;

    public function __construct($twig) {
        $this->twig = $twig;
        $this->route = 'views/petrotal/';
        $this->petrotalObligacionModel = new PetrotalObligacionModel();
        $this->petrotalFielModel = new PetrotalFielModel();
    }

    // Sugiere la última semana lunes-domingo ya cerrada (hoy - 7 días desde
    // el lunes más reciente) como punto de partida del selector — el
    // usuario puede cambiarla libremente en la pantalla.
    private function semana_sugerida(): array {
        $hoy = new DateTime();
        $diaSemana = (int) $hoy->format('N'); // 1=lunes .. 7=domingo
        $lunesActual = (clone $hoy)->modify('-' . ($diaSemana - 1) . ' days');
        $lunesSemanaAnterior = (clone $lunesActual)->modify('-7 days');
        $domingoSemanaAnterior = (clone $lunesSemanaAnterior)->modify('+6 days');
        return [$lunesSemanaAnterior->format('Y-m-d'), $domingoSemanaAnterior->format('Y-m-d')];
    }

    public function volumetricos() {
        if (!authorized(98)) {
            echo "No autorizado";
            return;
        }
        [$desdeSugerido, $hastaSugerido] = $this->semana_sugerida();
        echo $this->twig->render($this->route . 'volumetricos.html', compact('desdeSugerido', 'hastaSugerido'));
    }

    public function preview_json() {
        header('Content-Type: application/json');
        if (!authorized(98)) {
            json_output(['error' => 'No autorizado']);
            return;
        }

        $desde = $_REQUEST['desde'] ?? '';
        $hasta = $_REQUEST['hasta'] ?? '';
        if (!$desde || !$hasta) {
            json_output(['error' => 'Selecciona un periodo (desde/hasta)']);
            return;
        }

        $reporte = $this->petrotalObligacionModel->construir_reporte($desde, $hasta);
        $envioExistente = $this->petrotalObligacionModel->buscar_envio_periodo($desde, $hasta);

        json_output([
            'ventas' => $reporte['ventas'],
            'compras' => $reporte['compras'],
            'advertencias' => $reporte['advertencias'],
            'envio_existente' => $envioExistente,
        ]);
    }
}
```

- [ ] **Step 2: Escribir la vista `volumetricos.html`**

Seguir el patrón de layout existente (extiende `layouts/base.html` — confirmar el nombre exacto revisando otra vista de `supply/` antes de escribir esta, ej. `views/supply/petrotal_reconciliation.html`, y replicar su bloque `{% extends %}` / `{% block %}` tal cual):

```html
{% extends "views/layouts/base.html" %}
{% block title %}Reporte CNE - Volumétricos Petrotal{% endblock %}
{% block mycss %}{% endblock %}
{% block menutitle %}Reporte CNE / Volumétricos Petrotal{% endblock %}
{% block content %}
<div class="container-fluid p-0">
  <h1 class="h3 mb-3">Reporte CNE / Volumétricos Petrotal</h1>

  <div class="card">
    <div class="card-body">
      <div class="row g-2 align-items-end mb-3">
        <div class="col-auto">
          <label class="form-label">Desde</label>
          <input type="date" id="periodo_desde" class="form-control" value="{{ desdeSugerido }}">
        </div>
        <div class="col-auto">
          <label class="form-label">Hasta</label>
          <input type="date" id="periodo_hasta" class="form-control" value="{{ hastaSugerido }}">
        </div>
        <div class="col-auto">
          <button type="button" id="btn_preview" class="btn btn-primary">Generar vista previa</button>
        </div>
      </div>

      <div id="advertencias_container"></div>

      <ul class="nav nav-tabs" role="tablist">
        <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tab_ventas">Ventas</a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab_compras">Compras</a></li>
      </ul>
      <div class="tab-content mt-3">
        <div class="tab-pane fade show active" id="tab_ventas">
          <table class="table table-sm" id="tabla_ventas">
            <thead>
              <tr><th>Fecha</th><th>Folio</th><th>Producto</th><th>Cliente</th><th>Permiso CRE</th><th>Volumen (bbl)</th><th>Precio</th></tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>
        <div class="tab-pane fade" id="tab_compras">
          <table class="table table-sm" id="tabla_compras">
            <thead>
              <tr><th>Fecha</th><th>Folio</th><th>Producto</th><th>Proveedor</th><th>Permiso COM</th><th>Volumen (bbl)</th><th>Precio</th></tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>
      </div>

      <div class="mt-3">
        <button type="button" id="btn_generar_json" class="btn btn-secondary" disabled>Generar JSON</button>
        <button type="button" id="btn_enviar_cne" class="btn btn-success" disabled>Enviar a CNE</button>
      </div>

      <pre id="json_preview" class="mt-3 bg-light p-3" style="display:none; max-height: 400px; overflow: auto;"></pre>
    </div>
  </div>
</div>
{% endblock %}

{% block scripts %}
<script src="{{ JS }}petrotal.js"></script>
{% endblock %}
```

- [ ] **Step 3: Verificar manualmente que el controlador carga sin errores fatales**

Run: `php -l _assets/controllers/petrotal.php`
Expected: `No syntax errors detected`.

Con el servidor corriendo (el usuario lo gestiona él mismo — no lo levantes), pedirle al usuario que confirme visitando `/petrotal/volumetricos` y reporte si carga la vista o da error de permisos/plantilla. Si el usuario no puede probarlo en este momento, dejar este paso explícito para que él lo haga.

- [ ] **Step 4: Commit**

```bash
git add _assets/controllers/petrotal.php views/petrotal/volumetricos.html
git commit -m "Agrega controlador Petrotal y vista principal con preview AJAX"
```

---

## Task 11: JS de la pantalla principal — render de preview y generación de JSON

**Files:**
- Create: `_assets/js/petrotal.js`

**Interfaces:**
- Consumes: endpoint `/petrotal/preview_json` (Task 10), estructura de respuesta `{ventas, compras, advertencias, envio_existente}`.
- Produces: comportamiento de UI — al hacer click en "Generar vista previa" llena las tablas; habilita "Generar JSON" solo si no hay advertencias de tipo `sin_permiso`/`permiso_ambiguo`/`producto_no_clasificado` sin resolver.

- [ ] **Step 1: Escribir el JS**

```javascript
// _assets/js/petrotal.js
$(function () {
  let ultimoReporte = null;

  function renderTabla(selector, filas, esVenta) {
    const $tbody = $(selector + ' tbody');
    $tbody.empty();
    filas.forEach(function (f) {
      const contraparteLabel = esVenta ? f.contraparte_nombre : f.contraparte_nombre;
      $tbody.append(
        '<tr>' +
        '<td>' + f.fecha + '</td>' +
        '<td>' + f.folio + '</td>' +
        '<td>' + f.producto_label + '</td>' +
        '<td>' + contraparteLabel + '</td>' +
        '<td>' + (f.permiso_cre || '-') + '</td>' +
        '<td>' + f.volumen_bbl.toFixed(2) + '</td>' +
        '<td>' + f.precio.toFixed(2) + '</td>' +
        '</tr>'
      );
    });
  }

  function renderAdvertencias(advertencias) {
    const $container = $('#advertencias_container');
    $container.empty();
    if (!advertencias.length) return;

    const lista = advertencias.map(function (a) {
      return '<li>' + a.mensaje + '</li>';
    }).join('');

    $container.append(
      '<div class="alert alert-warning">' +
      '<strong>' + advertencias.length + ' advertencia(s) — revisa antes de enviar:</strong>' +
      '<ul>' + lista + '</ul>' +
      '</div>'
    );
  }

  function hayAdvertenciasBloqueantes(advertencias) {
    return advertencias.some(function (a) {
      return a.tipo === 'sin_permiso' || a.tipo === 'permiso_ambiguo' || a.tipo === 'producto_no_clasificado';
    });
  }

  $('#btn_preview').on('click', function () {
    const desde = $('#periodo_desde').val();
    const hasta = $('#periodo_hasta').val();
    if (!desde || !hasta) {
      alert('Selecciona ambas fechas del periodo.');
      return;
    }

    $.get('/petrotal/preview_json', { desde: desde, hasta: hasta })
      .done(function (respuesta) {
        if (respuesta.error) {
          alert(respuesta.error);
          return;
        }
        ultimoReporte = respuesta;
        renderTabla('#tabla_ventas', respuesta.ventas, true);
        renderTabla('#tabla_compras', respuesta.compras, false);
        renderAdvertencias(respuesta.advertencias);

        const bloqueado = hayAdvertenciasBloqueantes(respuesta.advertencias);
        $('#btn_generar_json').prop('disabled', bloqueado || (!respuesta.ventas.length && !respuesta.compras.length));
      })
      .fail(function () {
        alert('Error al consultar la vista previa. Intenta de nuevo.');
      });
  });

  $('#btn_generar_json').on('click', function () {
    if (!ultimoReporte) return;
    $.post('/petrotal/generar_json', {
      desde: $('#periodo_desde').val(),
      hasta: $('#periodo_hasta').val(),
    })
      .done(function (respuesta) {
        if (respuesta.error) {
          alert(respuesta.error);
          return;
        }
        $('#json_preview').show().text(respuesta.json);
        $('#btn_enviar_cne').prop('disabled', false).data('envio-id', respuesta.envio_id);
      })
      .fail(function () {
        alert('Error al generar el JSON.');
      });
  });

  $('#btn_enviar_cne').on('click', function () {
    const envioId = $(this).data('envio-id');
    if (!envioId) return;
    if (!confirm('¿Enviar este reporte a la CNE? Esta acción intentará el envío real.')) return;

    $.post('/petrotal/enviar_reporte', { envio_id: envioId })
      .done(function (respuesta) {
        if (respuesta.ok) {
          alert('Reporte enviado. Folio de acuse: ' + (respuesta.folio_acuse || '(pendiente)'));
        } else {
          alert('No se pudo enviar: ' + (respuesta.mensaje || 'error desconocido'));
        }
      })
      .fail(function () {
        alert('Error de red al intentar el envío.');
      });
  });
});
```

- [ ] **Step 2: Verificar sintaxis del JS**

Run: `node --check _assets/js/petrotal.js` (si Node está disponible en el entorno; si no, revisar visualmente que no haya llaves/paréntesis desbalanceados).
Expected: sin errores de sintaxis.

- [ ] **Step 3: Commit**

```bash
git add _assets/js/petrotal.js
git commit -m "Agrega JS de preview y envío para pantalla Petrotal volumétricos"
```

---

## Task 12: Controlador `petrotal.php` — generar JSON, guardar copia y enviar

**Files:**
- Modify: `_assets/controllers/petrotal.php`

**Interfaces:**
- Consumes: `PetrotalCneClient::armar_json()`, `PetrotalCneClient::enviar()`, `PetrotalCneClient::descargar_acuse()` (Tasks 8-9); `PetrotalObligacionModel::crear_envio()`, `actualizar_envio()`, `buscar_envio_periodo()`, `obtener_envio()` (Task 6); `PetrotalFielModel::obtener_config()` (Task 7).
- Produces: rutas `/petrotal/generar_json` (POST) y `/petrotal/enviar_reporte` (POST).

- [ ] **Step 1: Agregar `generar_json()` al controlador**

```php
    public function generar_json() {
        header('Content-Type: application/json');
        if (!authorized(98)) {
            json_output(['error' => 'No autorizado']);
            return;
        }

        $desde = $_POST['desde'] ?? '';
        $hasta = $_POST['hasta'] ?? '';
        if (!$desde || !$hasta) {
            json_output(['error' => 'Selecciona un periodo (desde/hasta)']);
            return;
        }

        $reporte = $this->petrotalObligacionModel->construir_reporte($desde, $hasta);
        $json = PetrotalCneClient::armar_json('H/22730/COM/2019', $desde, $hasta, $reporte['ventas'], $reporte['compras']);

        $envioExistente = $this->petrotalObligacionModel->buscar_envio_periodo($desde, $hasta);
        $usuarioId = $_SESSION['tg_user']['id'] ?? 0;

        if ($envioExistente) {
            $envioId = $envioExistente['Id'];
        } else {
            $envioId = $this->petrotalObligacionModel->crear_envio($desde, $hasta, $usuarioId);
        }

        $nombreArchivo = "{$desde}_{$hasta}_{$envioId}.json";
        $rutaJson = ROOT . '_assets' . DS . 'uploads' . DS . 'petrotal' . DS . 'json' . DS . $nombreArchivo;
        file_put_contents($rutaJson, $json);

        $this->petrotalObligacionModel->actualizar_envio($envioId, [
            'Estado' => 'borrador',
            'RutaJson' => $rutaJson,
        ]);

        json_output(['json' => $json, 'envio_id' => $envioId]);
    }
```

- [ ] **Step 2: Agregar `enviar_reporte()` al controlador**

```php
    public function enviar_reporte() {
        header('Content-Type: application/json');
        if (!authorized(98)) {
            json_output(['error' => 'No autorizado']);
            return;
        }

        $envioId = (int) ($_POST['envio_id'] ?? 0);
        if (!$envioId) {
            json_output(['ok' => false, 'mensaje' => 'Falta envio_id']);
            return;
        }

        $envio = $this->petrotalObligacionModel->obtener_envio($envioId);
        if (!$envio || empty($envio['RutaJson'])) {
            json_output(['ok' => false, 'mensaje' => 'No se encontró el JSON generado para este envío. Genera el JSON primero.']);
            return;
        }

        $fielConfig = $this->petrotalFielModel->obtener_config();
        if (!$fielConfig) {
            json_output(['ok' => false, 'mensaje' => 'No hay una FIEL configurada. Ve a Configuración FIEL primero.']);
            return;
        }

        $resultado = PetrotalCneClient::enviar(
            $envio['RutaJson'],
            $fielConfig['ruta_cer'],
            $fielConfig['ruta_key'],
            $fielConfig['password'],
            'H/22730/COM/2019'
        );

        if ($resultado['error_conexion']) {
            $this->petrotalObligacionModel->actualizar_envio($envioId, [
                'Estado' => 'error',
                'RespuestaRaw' => json_encode($resultado),
                'FechaEnvio' => date('Y-m-d H:i:s'),
            ]);
            json_output(['ok' => false, 'mensaje' => $resultado['error_conexion']]);
            return;
        }

        if (!$resultado['ok']) {
            $this->petrotalObligacionModel->actualizar_envio($envioId, [
                'Estado' => 'error',
                'RespuestaRaw' => json_encode($resultado),
                'FechaEnvio' => date('Y-m-d H:i:s'),
            ]);
            json_output(['ok' => false, 'mensaje' => $resultado['msg']]);
            return;
        }

        $folioAcuse = null;
        $rutaAcuse = null;
        if (!empty($resultado['link_descarga'])) {
            $folioAcuse = basename(parse_url($resultado['link_descarga'], PHP_URL_PATH));
            $rutaAcuse = ROOT . '_assets' . DS . 'uploads' . DS . 'petrotal' . DS . 'acuse' . DS . "{$envioId}_{$folioAcuse}.pdf";
            PetrotalCneClient::descargar_acuse($resultado['link_descarga'], $rutaAcuse);
        }

        $this->petrotalObligacionModel->actualizar_envio($envioId, [
            'Estado' => $rutaAcuse ? 'acuse_recibido' : 'enviado',
            'RespuestaRaw' => json_encode($resultado),
            'FolioAcuse' => $folioAcuse,
            'RutaAcuse' => $rutaAcuse,
            'FechaEnvio' => date('Y-m-d H:i:s'),
        ]);

        json_output(['ok' => true, 'folio_acuse' => $folioAcuse]);
    }
```

- [ ] **Step 3: Verificar sintaxis**

Run: `php -l _assets/controllers/petrotal.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Prueba manual del flujo completo con datos de agosto (sin depender de la CNE)**

Pedirle al usuario que, con el servidor corriendo, visite `/petrotal/volumetricos`, seleccione el periodo `2026-08-21` a `2026-08-27`, dé click en "Generar vista previa" y confirme que las tablas de Ventas/Compras se llenan con datos parecidos a los que vimos en el acuse `B8CA2CFC` (Regular ~1460.73 bbl total). Luego "Generar JSON" y confirmar que aparece el JSON en pantalla y que se creó un archivo en `_assets/uploads/petrotal/json/`.

- [ ] **Step 5: Commit**

```bash
git add _assets/controllers/petrotal.php
git commit -m "Agrega generación de JSON y envío a CNE en controlador Petrotal"
```

---

## Task 13: Pantalla de configuración de la FIEL

**Files:**
- Modify: `_assets/controllers/petrotal.php`
- Create: `views/petrotal/configuracion_fiel.html`

**Interfaces:**
- Consumes: `PetrotalFielModel::guardar_config()`, `obtener_config()` (Task 7).
- Produces: rutas `/petrotal/configuracion_fiel` (GET) y `/petrotal/guardar_fiel` (POST, multipart).

- [ ] **Step 1: Agregar los métodos al controlador**

```php
    public function configuracion_fiel() {
        if (!authorized(99)) {
            echo "No autorizado";
            return;
        }
        $configActual = $this->petrotalFielModel->obtener_config();
        echo $this->twig->render($this->route . 'configuracion_fiel.html', compact('configActual'));
    }

    public function guardar_fiel() {
        if (!authorized(99)) {
            setFlashMessage('error', 'No autorizado.');
            redirect('/petrotal/configuracion_fiel');
            return;
        }

        $password = $_POST['password'] ?? '';
        if (!$password) {
            setFlashMessage('error', 'Falta el password de la llave privada.');
            redirect('/petrotal/configuracion_fiel');
            return;
        }

        $uploadDir = ROOT . '_assets' . DS . 'uploads' . DS . 'petrotal' . DS . 'fiel' . DS;
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $cerOk = isset($_FILES['cerFile']) && $_FILES['cerFile']['error'] === UPLOAD_ERR_OK;
        $keyOk = isset($_FILES['keyFile']) && $_FILES['keyFile']['error'] === UPLOAD_ERR_OK;
        if (!$cerOk || !$keyOk) {
            setFlashMessage('error', 'Faltan los archivos .cer y/o .key.');
            redirect('/petrotal/configuracion_fiel');
            return;
        }

        $extCer = strtolower(pathinfo($_FILES['cerFile']['name'], PATHINFO_EXTENSION));
        $extKey = strtolower(pathinfo($_FILES['keyFile']['name'], PATHINFO_EXTENSION));
        if ($extCer !== 'cer' || $extKey !== 'key') {
            setFlashMessage('error', 'El archivo de certificado debe ser .cer y el de llave .key.');
            redirect('/petrotal/configuracion_fiel');
            return;
        }

        $rutaCer = $uploadDir . 'petrotal.cer';
        $rutaKey = $uploadDir . 'petrotal.key';

        if (!move_uploaded_file($_FILES['cerFile']['tmp_name'], $rutaCer) || !move_uploaded_file($_FILES['keyFile']['tmp_name'], $rutaKey)) {
            setFlashMessage('error', 'Error al guardar los archivos subidos.');
            redirect('/petrotal/configuracion_fiel');
            return;
        }

        $usuarioId = $_SESSION['tg_user']['id'] ?? 0;
        $guardado = $this->petrotalFielModel->guardar_config($rutaCer, $rutaKey, $password, $usuarioId);

        setFlashMessage($guardado ? 'success' : 'error', $guardado ? 'FIEL guardada correctamente.' : 'Error al guardar la configuración en base de datos.');
        redirect('/petrotal/configuracion_fiel');
    }
```

Nota: `redirect($to = null)` (definida en `_assets/classes/php_functions.php:232`) acepta una ruta opcional — al pasarla explícitamente hace `header('Location: ' . $to)`, tal como se usa arriba.

- [ ] **Step 2: Escribir la vista `configuracion_fiel.html`**

```html
{% extends "views/layouts/base.html" %}
{% block title %}Configuración FIEL Petrotal{% endblock %}
{% block mycss %}{% endblock %}
{% block menutitle %}Configuración FIEL Petrotal{% endblock %}
{% block content %}
<div class="container-fluid p-0">
  <h1 class="h3 mb-3">Configuración FIEL Petrotal</h1>

  <div class="card">
    <div class="card-body">
      {% if configActual %}
      <div class="alert alert-info">
        Ya existe una FIEL configurada (ruta: {{ configActual.ruta_cer }}). Al subir una nueva, se reemplaza.
      </div>
      {% endif %}

      <form action="/petrotal/guardar_fiel" method="POST" enctype="multipart/form-data">
        <div class="mb-3">
          <label class="form-label">Archivo .cer</label>
          <input type="file" name="cerFile" class="form-control" accept=".cer" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Archivo .key</label>
          <input type="file" name="keyFile" class="form-control" accept=".key" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Password de la llave privada</label>
          <input type="password" name="password" class="form-control" required>
        </div>
        <button type="submit" class="btn btn-primary">Guardar</button>
      </form>
    </div>
  </div>
</div>
{% endblock %}
```

- [ ] **Step 3: Verificar sintaxis**

Run: `php -l _assets/controllers/petrotal.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add _assets/controllers/petrotal.php views/petrotal/configuracion_fiel.html
git commit -m "Agrega pantalla de configuración de la FIEL Petrotal"
```

---

## Task 14: Pantalla de historial de envíos

**Files:**
- Modify: `_assets/controllers/petrotal.php`
- Create: `views/petrotal/historial.html`

**Interfaces:**
- Consumes: `PetrotalObligacionModel::listar_envios()` (Task 6).
- Produces: ruta `/petrotal/historial` (GET) y `/petrotal/descargar_json/{id}`, `/petrotal/descargar_acuse/{id}` (GET, descarga de archivo).

- [ ] **Step 1: Agregar los métodos al controlador**

```php
    public function historial() {
        if (!authorized(98)) {
            echo "No autorizado";
            return;
        }
        $envios = $this->petrotalObligacionModel->listar_envios();
        echo $this->twig->render($this->route . 'historial.html', compact('envios'));
    }

    public function descargar_json($id = null) {
        if (!authorized(98)) {
            echo "No autorizado";
            return;
        }
        $envio = $this->petrotalObligacionModel->obtener_envio((int) $id);
        if (!$envio || empty($envio['RutaJson']) || !file_exists($envio['RutaJson'])) {
            http_response_code(404);
            echo "Archivo no encontrado";
            return;
        }
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="' . basename($envio['RutaJson']) . '"');
        readfile($envio['RutaJson']);
    }

    public function descargar_acuse($id = null) {
        if (!authorized(98)) {
            echo "No autorizado";
            return;
        }
        $envio = $this->petrotalObligacionModel->obtener_envio((int) $id);
        if (!$envio || empty($envio['RutaAcuse']) || !file_exists($envio['RutaAcuse'])) {
            http_response_code(404);
            echo "Acuse no encontrado";
            return;
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . basename($envio['RutaAcuse']) . '"');
        readfile($envio['RutaAcuse']);
    }
```

- [ ] **Step 2: Escribir la vista `historial.html`**

```html
{% extends "views/layouts/base.html" %}
{% block title %}Historial de envíos Petrotal{% endblock %}
{% block mycss %}{% endblock %}
{% block menutitle %}Historial de envíos — Reporte CNE Petrotal{% endblock %}
{% block content %}
<div class="container-fluid p-0">
  <h1 class="h3 mb-3">Historial de envíos — Reporte CNE Petrotal</h1>

  <div class="card">
    <div class="card-body">
      <table class="table table-striped">
        <thead>
          <tr>
            <th>Periodo</th>
            <th>Estado</th>
            <th>Folio acuse</th>
            <th>Fecha de envío</th>
            <th>Archivos</th>
          </tr>
        </thead>
        <tbody>
          {% for envio in envios %}
          <tr>
            <td>{{ envio.PeriodoDesde }} al {{ envio.PeriodoHasta }}</td>
            <td>{{ envio.Estado }}</td>
            <td>{{ envio.FolioAcuse ?? '-' }}</td>
            <td>{{ envio.FechaEnvio ?? '-' }}</td>
            <td>
              {% if envio.RutaJson %}
              <a href="/petrotal/descargar_json/{{ envio.Id }}" class="btn btn-sm btn-outline-secondary">JSON</a>
              {% endif %}
              {% if envio.RutaAcuse %}
              <a href="/petrotal/descargar_acuse/{{ envio.Id }}" class="btn btn-sm btn-outline-primary">Acuse</a>
              {% endif %}
            </td>
          </tr>
          {% else %}
          <tr><td colspan="5" class="text-center">Sin envíos registrados.</td></tr>
          {% endfor %}
        </tbody>
      </table>
    </div>
  </div>
</div>
{% endblock %}
```

- [ ] **Step 3: Verificar sintaxis**

Run: `php -l _assets/controllers/petrotal.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add _assets/controllers/petrotal.php views/petrotal/historial.html
git commit -m "Agrega pantalla de historial de envíos Petrotal"
```

---

## Task 15: Sección "PETROTAL" en el sidebar

**Files:**
- Modify: `views/layouts/sidebar.html`

**Interfaces:**
- Consumes: permisos `98` y `99` (creados en Task 1).

- [ ] **Step 1: Agregar la sección al sidebar**

Insertar después del bloque de cierre de la sección `ABASTOS` (justo después de la línea que cierra `{% if authorized(32) %}` en `views/layouts/sidebar.html`, línea 204 `{% endif %}`, antes de la sección `ADMINISTRACIÓN` que empieza en la línea 206):

```html
      {% if authorized(98) %}
      <li class="sidebar-header">PETROTAL</li>
      <li class="sidebar-item">
        <a class="sidebar-link" href="/petrotal/volumetricos">
          <i data-feather="upload-cloud"></i>
          <span class="align-middle">Reporte CNE / Volumétricos</span>
        </a>
      </li>
      <li class="sidebar-item">
        <a class="sidebar-link" href="/petrotal/historial">
          <i data-feather="archive"></i>
          <span class="align-middle">Historial de envíos</span>
        </a>
      </li>
      {% if authorized(99) %}
      <li class="sidebar-item">
        <a class="sidebar-link" href="/petrotal/configuracion_fiel">
          <i data-feather="key"></i>
          <span class="align-middle">Configuración FIEL</span>
        </a>
      </li>
      {% endif %}
      {% endif %}

```

- [ ] **Step 2: Verificar visualmente que el bloque quedó bien anidado**

Run: `grep -n "PETROTAL\|authorized(98)\|authorized(99)" views/layouts/sidebar.html`
Expected: las 3 líneas de apertura de `{% if %}` y sus `{% endif %}` correspondientes visibles, sin romper la indentación del bloque `ADMINISTRACIÓN` que sigue.

- [ ] **Step 3: Commit**

```bash
git add views/layouts/sidebar.html
git commit -m "Agrega sección PETROTAL al sidebar"
```

---

## Task 16: Verificación manual end-to-end contra datos reales de agosto

**Files:** ninguno nuevo — solo verificación.

**Interfaces:** ninguna nueva.

- [ ] **Step 1: Pedirle al usuario que confirme, con el servidor corriendo (él lo gestiona), el flujo completo**

1. Entrar a `/petrotal/configuracion_fiel`, subir un `.cer`/`.key`/password de prueba (o los reales de Alfredo Escalera si ya los tiene a mano) y confirmar que guarda sin error.
2. Entrar a `/petrotal/volumetricos`, seleccionar el periodo `2026-08-21` a `2026-08-27`.
3. Click en "Generar vista previa" y comparar los totales de Regular/Premium contra el acuse `B8CA2CFC` ya analizado (Regular 1460.73 bbl, Premium 334.88 bbl) — deben coincidir en el mismo orden de magnitud (pequeñas diferencias de redondeo son aceptables, grandes diferencias indican un bug).
4. Click en "Generar JSON", confirmar que se ve el JSON y que aparece un archivo en `_assets/uploads/petrotal/json/`.
5. Si es viernes y la API de QA responde: click en "Enviar a CNE" y confirmar que el flujo completo (envío, guardado de acuse, actualización de estado) funciona de punta a punta.
6. Si no es viernes: confirmar que el botón "Enviar a CNE" muestra el error de conexión de forma clara, sin romper la pantalla, y que el registro en `/petrotal/historial` queda con `Estado = error` y el JSON sigue disponible para descarga/reintento.

- [ ] **Step 2: Reportar hallazgos**

El usuario reporta cualquier discrepancia encontrada contra lo esperado en el Step 1. Si hay bugs, se abren como tasks de corrección puntuales (no parte de este plan — este plan termina en la entrega funcional para prueba).

---

## Self-Review Notes

- **Cobertura del spec:** las 6 secciones de "Alcance" del spec (preview, generación JSON, envío, guardado, historial, configuración FIEL) están cubiertas por Tasks 10-14. La construcción de datos (clasificación, conversión, resolución de contraparte) está en Tasks 3-5, validada contra los acuses reales ya vistos. El manejo de errores de API caída está en Task 9 (`error_conexion`) y Task 12 (persistencia como `Estado = 'error'`).
- **Corrección de convención de directorio:** el spec original mencionaba `_uploads/petrotal/` — se corrigió a `_assets/uploads/petrotal/` en este plan tras confirmar la convención real del proyecto (`payment.php` usa `_assets/uploads/credit_debit_notes/`).
- **Dependencia entre tasks:** Tasks 3→4→5→6 son estrictamente secuenciales (mismo archivo, cada uno se apoya en el anterior). Tasks 7 y 8-9 pueden hacerse en paralelo entre sí (archivos distintos) pero ambos deben completarse antes de Task 12. Task 15 (sidebar) depende solo de Task 1 (permisos) y puede hacerse en cualquier momento después de esa.
