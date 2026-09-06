# Programación de recepciones de combustible — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the manual Drive Excel ("Programa Julio 2026.xlsx") with a new "Programación" screen under Abastos where Abastos captures and views the daily fuel reception schedule (provider, terminal, product, liters, station, time, carrier), grouped by day like the Excel's tabs but with one standardized set of fields instead of columns that change meaning per provider.

**Architecture:** Three new tables (`fuel_reception_schedule`, `fuel_terminals`, `fuel_carriers`) plus the existing `TG.dbo.Proveedores`/`SG12.dbo.Proveedores` for the real supplier catalog and `TG.dbo.Estaciones` for stations. New model `FuelReceptionScheduleModel` (CRUD + day-grouped query + on-the-fly terminal/carrier creation). New methods appended to the existing `Supply` controller (`_assets/controllers/supply.php`), following the exact pattern already used by `petrotal_reconciliation()`/`frmCapturaCompra()`: a view method, a JSON data method for the day's grouped rows, add/update/cancel AJAX endpoints, and two "create catalog item" AJAX endpoints. New Twig view `views/supply/scheduling.html` + modal partial `views/supply/modals/frmProgramacionRecepcion.html`, new JS `_assets/js/scheduling.js`. A one-time standalone PHP import script loads "Programa Julio 2026.xlsx" as test data using a hardcoded station/provider mapping confirmed against the real database in this session.

**Tech Stack:** PHP (no framework), Twig templates, jQuery + Bootstrap (bootstrap-select) + select2-style "creatable" pattern via a simple prompt-based add, MSSQL via `MySqlPdoHandler`/PDO, PhpSpreadsheet (already a composer dependency) for the import script. No test framework exists in this project — verification is `php -l` plus manual checks against real data, run directly via `php -r` scripts using `MySqlPdoHandler` (confirmed working in this session).

**Spec:** `docs/superpowers/specs/2026-09-05-programacion-recepciones-combustible-design.md`

## Global Constraints

- No test framework exists in this project (confirmed in `CLAUDE.md`) — every "test" step is `php -l` syntax verification plus a manual data check with exact commands and expected output.
- All new SQL must use parameterized queries via `$this->sql->select/insert/update($query, $params)` — never string-concatenate user input, following the existing convention in `PetrotalReconciliationModel`.
- New permission id: **95** (confirmed free against `TG.dbo.tg_permissions`, max id in use is 94 as of 2026-09-05) — hardcode this exact id in `authorized()` calls and the sidebar `{% if %}`.
- `supplier_id` in `fuel_reception_schedule` is `TG.dbo.Proveedores.id` (not `id_control_gas`, not a free-text name). Supplier display name is resolved via `id_control_gas` → `SG12.dbo.Proveedores.den`, exactly like `ProveedoresModel::get_by_id()` already does.
- Follow the existing modal/JS conventions from `frmCapturaCompra` (`_assets/controllers/supply.php:1138-1158`) exactly: controller method returns `json_output(['success' => true, 'html' => $html])` with a Twig-rendered partial; JS injects it into a modal container with `.html(content)`.
- Follow the existing DataTables/AJAX conventions from `petrotal_reconciliation.js` for loading/error states (`.loading` class toggling, `alertify.myAlert` for errors) — but this feature does NOT use DataTables (day view is small, plain grouped tables per spec), so only the loading/error UI conventions carry over, not the DataTable initialization itself.
- Never modify existing methods in `_assets/controllers/supply.php` — only append new methods at the end of the class, same as the Petrotal reconciliation plan did.
- This project has no environment variable or config file for DB credentials beyond what's already wired into `MySqlPdoHandler` — verification scripts in this plan connect exactly as shown in Task 1's example, copied from a pattern already proven to work in this session.
- **`SG12` is read-only. Never write to it — no `INSERT`, `UPDATE`, `DELETE`, `CREATE`, or `ALTER` against any `SG12.*` object, in any task, for any reason.** All new tables and all writes in this plan target `TG` only. `SG12.dbo.Proveedores` is only ever read (for display names via joins) — confirmed 2026-09-06 by the user after a plan draft mistakenly proposed inserting a new supplier row there; corrected before execution (AEMSA already existed under a different legal name, no insert was ever needed — see Task 1).

---

## File Structure

| File | Responsibility |
|---|---|
| `_assets/models/FuelReceptionScheduleModel.php` (new) | CRUD on `fuel_reception_schedule`; day-grouped query joining terminal/carrier/station/supplier names; cancel (soft). |
| `_assets/models/FuelTerminalsModel.php` (new) | CRUD on `fuel_terminals` (list + create). |
| `_assets/models/FuelCarriersModel.php` (new) | CRUD on `fuel_carriers` (list + create). |
| `_assets/controllers/supply.php` (modify — append methods) | `scheduling()`, `scheduling_day_data()`, `scheduling_add()`, `scheduling_update()`, `scheduling_cancel()`, `scheduling_add_terminal()`, `scheduling_add_carrier()`. |
| `views/supply/scheduling.html` (new) | Day view: date picker with prev/next, grouped tables by Proveedor→Terminal, "Agregar recepción" button. |
| `views/supply/modals/frmProgramacionRecepcion.html` (new) | Capture/edit form partial (no `{% extends %}`, rendered into the modal body). |
| `_assets/js/scheduling.js` (new) | Date navigation, AJAX load of day data, render grouped tables, modal open/submit, on-the-fly terminal/carrier creation. |
| `views/layouts/sidebar.html` (modify) | Add sidebar entry under Abastos guarded by `authorized(95)`. |
| Scratchpad: one-time import script (not committed to the app, lives in the scratchpad dir) | Reads `Programa Julio 2026.xlsx`, maps to the tables above, inserts test data. |

SQL to run directly against `TG` (new tables + permission row) is a one-time setup step, not an app file — captured as Task 1.

---

## Task 1: Create tables and permission row

**Files:**
- None (direct SQL against `TG` only, run via `php -r` using `MySqlPdoHandler` — proven to work against the real DB in this session)

**Interfaces:**
- Produces: `TG.dbo.fuel_reception_schedule`, `TG.dbo.fuel_terminals`, `TG.dbo.fuel_carriers` tables exist; permission id `95` exists in `TG.dbo.tg_permissions`.
- AEMSA does NOT need to be created — confirmed 2026-09-06 against the real DB: it already exists in `SG12.dbo.Proveedores` under the legal name "ALTOS ENERGETICOS MEXICANOS" (`cod=96`, RFC `AEM160511LMA`), linked at `TG.dbo.Proveedores.id=163` (`activo=1`). **`SG12` is read-only — never write to it, for any reason, in this or any other task.** Use supplier_id `163` for AEMSA everywhere in this plan (Task 7's import script).

- [ ] **Step 1: Create `fuel_terminals`**

Run via `php -r` (adjust working directory to the repo root first):

Note: use `getConnection()->exec(...)` for DDL, NOT `$sql->insert(...)` — confirmed 2026-09-06 by running this exact step: `MySqlPdoHandler::insert()` (`_assets/classes/common/MySqlPdoHandler.class.php:214`) requires the query string to contain the literal word "insert" and rejects anything else with "query mal formado", so `CREATE TABLE` always fails through that method.

```php
<?php
require "vendor/autoload.php";
require "_assets/classes/common/MySqlPdoHandler.class.php";
$sql = MySqlPdoHandler::getInstance();
$sql->connect("SG12");
$pdo = $sql->getConnection();
$pdo->exec("
    CREATE TABLE TG.dbo.fuel_terminals (
        id INT IDENTITY(1,1) PRIMARY KEY,
        nombre VARCHAR(100) NOT NULL,
        supplier_id INT NULL,
        activo BIT NOT NULL DEFAULT 1
    );
");
echo "fuel_terminals created\n";
```

- [ ] **Step 2: Create `fuel_carriers`**

```php
<?php
require "vendor/autoload.php";
require "_assets/classes/common/MySqlPdoHandler.class.php";
$sql = MySqlPdoHandler::getInstance();
$sql->connect("SG12");
$pdo = $sql->getConnection();
$pdo->exec("
    CREATE TABLE TG.dbo.fuel_carriers (
        id INT IDENTITY(1,1) PRIMARY KEY,
        nombre VARCHAR(100) NOT NULL,
        activo BIT NOT NULL DEFAULT 1
    );
");
echo "fuel_carriers created\n";
```

- [ ] **Step 3: Create `fuel_reception_schedule`**

```php
<?php
require "vendor/autoload.php";
require "_assets/classes/common/MySqlPdoHandler.class.php";
$sql = MySqlPdoHandler::getInstance();
$sql->connect("SG12");
$pdo = $sql->getConnection();
$pdo->exec("
    CREATE TABLE TG.dbo.fuel_reception_schedule (
        id INT IDENTITY(1,1) PRIMARY KEY,
        fecha DATE NOT NULL,
        hora VARCHAR(10) NULL,
        supplier_id INT NOT NULL,
        terminal_id INT NOT NULL,
        station_code INT NOT NULL,
        product VARCHAR(20) NOT NULL,
        mezcla VARCHAR(20) NULL,
        litros INT NOT NULL,
        carrier_id INT NULL,
        referencia VARCHAR(100) NULL,
        notas VARCHAR(500) NULL,
        estatus VARCHAR(20) NOT NULL DEFAULT 'Programado',
        created_by INT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT GETDATE(),
        updated_by INT NULL,
        updated_at DATETIME NULL
    );
");
echo "fuel_reception_schedule created\n";
```

- [ ] **Step 4: Verify all three tables exist with the expected columns**

```php
<?php
require "vendor/autoload.php";
require "_assets/classes/common/MySqlPdoHandler.class.php";
$sql = MySqlPdoHandler::getInstance();
$sql->connect("SG12");
foreach (["fuel_terminals", "fuel_carriers", "fuel_reception_schedule"] as $t) {
    $cols = $sql->select("SELECT COLUMN_NAME FROM TG.INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=?", ["dbo", $t]);
    echo "$t: " . count($cols) . " columns\n";
}
```

Expected: `fuel_terminals: 4 columns`, `fuel_carriers: 3 columns`, `fuel_reception_schedule: 17 columns` (id, fecha, hora, supplier_id, terminal_id, station_code, product, mezcla, litros, carrier_id, referencia, notas, estatus, created_by, created_at, updated_by, updated_at).

- [ ] **Step 5: Create the permission row**

Note: `tg_permissions.id` is an IDENTITY column — a plain parameterized `INSERT` with an explicit `id` value fails with "Cannot insert explicit value for identity column ... when IDENTITY_INSERT is set to OFF" (confirmed 2026-09-06 by running this exact step). Toggle `IDENTITY_INSERT` around the insert via `getConnection()`, not `$sql->insert(...)`.

```php
<?php
require "vendor/autoload.php";
require "_assets/classes/common/MySqlPdoHandler.class.php";
$sql = MySqlPdoHandler::getInstance();
$sql->connect("SG12");
$pdo = $sql->getConnection();
$pdo->exec("SET IDENTITY_INSERT TG.dbo.tg_permissions ON");
$stmt = $pdo->prepare("
    INSERT INTO TG.dbo.tg_permissions (id, action, department, description, status, updated_at, created_at)
    VALUES (?, ?, ?, ?, ?, GETDATE(), GETDATE())
");
$stmt->execute([95, "read", "Abastos", "Ver Programacion de Recepciones", 1]);
$pdo->exec("SET IDENTITY_INSERT TG.dbo.tg_permissions OFF");
$rows = $sql->select("SELECT id, department, description, status FROM TG.dbo.tg_permissions WHERE id = 95", []);
print_r($rows);
```

Expected: one row, `id=95`, `department='Abastos'`, `status=1`. Do not assign this permission to any user yet — that is a manual step the user does later once the feature is verified.

**No Step 6.** AEMSA already exists — confirmed 2026-09-06 against the real DB (`TG.dbo.Proveedores.id=163`, `id_control_gas=96`, `SG12.dbo.Proveedores.den='ALTOS ENERGETICOS MEXICANOS'`). Do not insert into `SG12` for any reason — it is read-only.

No commit for this task (it's a DB change, not a code change).

---

## Task 2: `FuelTerminalsModel` and `FuelCarriersModel`

**Files:**
- Create: `_assets/models/FuelTerminalsModel.php`
- Create: `_assets/models/FuelCarriersModel.php`

**Interfaces:**
- Produces:
  - `FuelTerminalsModel::get_all(): array` — all active terminals, `[['id' => int, 'nombre' => string, 'supplier_id' => int|null], ...]`, ordered by `nombre`.
  - `FuelTerminalsModel::add(string $nombre, ?int $supplierId): int` — inserts, returns new id.
  - `FuelTerminalsModel::find_by_name(string $nombre): ?array` — case-insensitive exact match, returns the row or null.
  - `FuelCarriersModel::get_all(): array` — all active carriers, `[['id' => int, 'nombre' => string], ...]`, ordered by `nombre`.
  - `FuelCarriersModel::add(string $nombre): int` — inserts, returns new id.
  - `FuelCarriersModel::find_by_name(string $nombre): ?array` — case-insensitive exact match, returns the row or null.

- [ ] **Step 1: Write `FuelTerminalsModel.php`**

```php
<?php
class FuelTerminalsModel extends Model {

    function get_all(): array {
        $query = "SELECT id, nombre, supplier_id FROM TG.dbo.fuel_terminals WHERE activo = 1 ORDER BY nombre";
        return $this->sql->select($query, []) ?: [];
    }

    function find_by_name(string $nombre): ?array {
        $query = "SELECT id, nombre, supplier_id FROM TG.dbo.fuel_terminals WHERE LOWER(nombre) = LOWER(?) AND activo = 1";
        $rows = $this->sql->select($query, [$nombre]);
        return $rows[0] ?? null;
    }

    // NOTE: do NOT use "INSERT ... OUTPUT INSERTED.id" with $this->sql->select() —
    // confirmed 2026-09-06 by running this exact query: select() (MySqlPdoHandler.class.php:119)
    // requires the query to contain the literal word "select" (via stristr), which
    // "OUTPUT INSERTED.id" does not, so it's rejected as "query mal formado".
    // $sql->insert() already returns the new id via PDO::lastInsertId() (confirmed
    // working) — use that instead.
    function add(string $nombre, ?int $supplierId): int {
        $query = "INSERT INTO TG.dbo.fuel_terminals (nombre, supplier_id, activo) VALUES (?, ?, 1)";
        return (int)$this->sql->insert($query, [$nombre, $supplierId]);
    }
}
```

- [ ] **Step 2: Write `FuelCarriersModel.php`**

```php
<?php
class FuelCarriersModel extends Model {

    function get_all(): array {
        $query = "SELECT id, nombre FROM TG.dbo.fuel_carriers WHERE activo = 1 ORDER BY nombre";
        return $this->sql->select($query, []) ?: [];
    }

    function find_by_name(string $nombre): ?array {
        $query = "SELECT id, nombre FROM TG.dbo.fuel_carriers WHERE LOWER(nombre) = LOWER(?) AND activo = 1";
        $rows = $this->sql->select($query, [$nombre]);
        return $rows[0] ?? null;
    }

    // See the note on FuelTerminalsModel::add() above -- same reasoning.
    function add(string $nombre): int {
        $query = "INSERT INTO TG.dbo.fuel_carriers (nombre, activo) VALUES (?, 1)";
        return (int)$this->sql->insert($query, [$nombre]);
    }
}
```

- [ ] **Step 3: Verify PHP syntax**

Run:
```
php -l _assets/models/FuelTerminalsModel.php
php -l _assets/models/FuelCarriersModel.php
```
Expected: `No syntax errors detected` for both.

- [ ] **Step 4: Manually verify against the real DB**

```php
<?php
require "vendor/autoload.php";
require "_assets/classes/common/MySqlPdoHandler.class.php";
require "_assets/models/Model.php";
require "_assets/models/FuelTerminalsModel.php";
require "_assets/models/FuelCarriersModel.php";

$terminals = new FuelTerminalsModel();
$id = $terminals->add("Diaz Gas", null);
echo "Created terminal id=$id\n";
print_r($terminals->get_all());
print_r($terminals->find_by_name("diaz gas"));

$carriers = new FuelCarriersModel();
$cid = $carriers->add("Carretera");
echo "Created carrier id=$cid\n";
print_r($carriers->get_all());
```

Expected: the created rows appear in `get_all()`, and `find_by_name("diaz gas")` returns the "Diaz Gas" row despite the case difference (confirms `LOWER()` comparison works via the `sqlsrv` driver).

- [ ] **Step 5: Commit**

```bash
git add _assets/models/FuelTerminalsModel.php _assets/models/FuelCarriersModel.php
git commit -m "$(cat <<'EOF'
Añade modelos de catálogo para terminales y transportistas de combustible

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01WkVRfbcMNyMCN31xLtbkad
EOF
)"
```

---

## Task 3: `FuelReceptionScheduleModel`

**Files:**
- Create: `_assets/models/FuelReceptionScheduleModel.php`

**Interfaces:**
- Consumes: nothing from other new files (queries `TG.dbo.fuel_reception_schedule`, `TG.dbo.fuel_terminals`, `TG.dbo.fuel_carriers`, `TG.dbo.Estaciones`, `TG.dbo.Proveedores`, `SG12.dbo.Proveedores` directly).
- Produces:
  - `FuelReceptionScheduleModel::get_day(string $fecha): array` — every non-cancelled row for that date, each row an associative array with joined display fields: `id, fecha, hora, supplier_id, supplier_nombre, terminal_id, terminal_nombre, station_code, station_nombre, product, mezcla, litros, carrier_id, carrier_nombre, referencia, notas, estatus`. Ordered by `supplier_nombre, terminal_nombre, hora`.
  - `FuelReceptionScheduleModel::add(array $data, int $userId): int` — `$data` keys: `fecha, hora, supplier_id, terminal_id, station_code, product, mezcla, litros, carrier_id, referencia, notas`. Returns new id.
  - `FuelReceptionScheduleModel::update(int $id, array $data, int $userId): void` — same `$data` shape as `add`, sets `estatus = 'Modificado'`, `updated_by`, `updated_at`.
  - `FuelReceptionScheduleModel::cancel(int $id, int $userId): void` — sets `estatus = 'Cancelado'`, `updated_by`, `updated_at`.
  - `FuelReceptionScheduleModel::get_one(int $id): ?array` — single row (raw columns, no joins) for pre-filling the edit modal, or null if not found.
  - `FuelReceptionScheduleModel::get_proveedores(): array` — `[['id' => int, 'nombre' => string], ...]`, `id` = `TG.dbo.Proveedores.id` (the real PK this feature's `supplier_id` foreign key points to), ordered by `nombre`. **Do not use `ProveedoresModel::get_actives()` for this screen** — confirmed by reading `_assets/models/ProveedoresModel.php:18-24`: that method's `SELECT t1.*` selects from `SG12.dbo.Proveedores t1` (PK `cod`) and only adds `t2.id_control_gas` from the join, never `t2.id` — so it cannot supply the value this feature's `supplier_id` column needs. `ProveedoresModel::get_actives()` is used by 6+ other controllers for unrelated purposes; do not modify it.

- [ ] **Step 1: Write `FuelReceptionScheduleModel.php`**

```php
<?php
class FuelReceptionScheduleModel extends Model {

    function get_day(string $fecha): array {
        $query = "
            SELECT
                s.id, s.fecha, s.hora, s.supplier_id, p2.den AS supplier_nombre,
                s.terminal_id, t.nombre AS terminal_nombre,
                s.station_code, e.Nombre AS station_nombre,
                s.product, s.mezcla, s.litros,
                s.carrier_id, c.nombre AS carrier_nombre,
                s.referencia, s.notas, s.estatus
            FROM TG.dbo.fuel_reception_schedule s
            LEFT JOIN TG.dbo.Proveedores p1 ON p1.id = s.supplier_id
            LEFT JOIN SG12.dbo.Proveedores p2 ON p2.cod = p1.id_control_gas
            LEFT JOIN TG.dbo.fuel_terminals t ON t.id = s.terminal_id
            LEFT JOIN TG.dbo.fuel_carriers c ON c.id = s.carrier_id
            LEFT JOIN TG.dbo.Estaciones e ON e.Codigo = s.station_code
            WHERE s.fecha = ? AND s.estatus <> 'Cancelado'
            ORDER BY p2.den, t.nombre, s.hora
        ";
        return $this->sql->select($query, [$fecha]) ?: [];
    }

    function get_one(int $id): ?array {
        $query = "SELECT * FROM TG.dbo.fuel_reception_schedule WHERE id = ?";
        $rows = $this->sql->select($query, [$id]);
        return $rows[0] ?? null;
    }

    // A dedicated query, NOT ProveedoresModel::get_actives() -- that method
    // selects SG12.dbo.Proveedores.* (PK `cod`) and never exposes
    // TG.dbo.Proveedores.id, which is what this feature's supplier_id
    // foreign key actually points to (confirmed 2026-09-05 against real data).
    function get_proveedores(): array {
        $query = "
            SELECT t1.id, t2.den AS nombre
            FROM TG.dbo.Proveedores t1
            JOIN SG12.dbo.Proveedores t2 ON t2.cod = t1.id_control_gas
            WHERE t1.activo = 1
            ORDER BY t2.den
        ";
        return $this->sql->select($query, []) ?: [];
    }

    // NOTE: do NOT use "INSERT ... OUTPUT INSERTED.id" with $this->sql->select() --
    // confirmed 2026-09-06 against the real DB: select() requires the literal word
    // "select" in the query text and rejects this as "query mal formado". Use
    // $sql->insert(), which already returns the new id via PDO::lastInsertId().
    function add(array $data, int $userId): int {
        $query = "
            INSERT INTO TG.dbo.fuel_reception_schedule
                (fecha, hora, supplier_id, terminal_id, station_code, product, mezcla, litros,
                 carrier_id, referencia, notas, estatus, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Programado', ?, GETDATE())
        ";
        return (int)$this->sql->insert($query, [
            $data['fecha'], $data['hora'] ?: null, $data['supplier_id'], $data['terminal_id'],
            $data['station_code'], $data['product'], $data['mezcla'] ?: null, $data['litros'],
            $data['carrier_id'] ?: null, $data['referencia'] ?: null, $data['notas'] ?: null,
            $userId,
        ]);
    }

    function update(int $id, array $data, int $userId): void {
        $query = "
            UPDATE TG.dbo.fuel_reception_schedule
            SET fecha = ?, hora = ?, supplier_id = ?, terminal_id = ?, station_code = ?,
                product = ?, mezcla = ?, litros = ?, carrier_id = ?, referencia = ?, notas = ?,
                estatus = 'Modificado', updated_by = ?, updated_at = GETDATE()
            WHERE id = ?
        ";
        $this->sql->update($query, [
            $data['fecha'], $data['hora'] ?: null, $data['supplier_id'], $data['terminal_id'],
            $data['station_code'], $data['product'], $data['mezcla'] ?: null, $data['litros'],
            $data['carrier_id'] ?: null, $data['referencia'] ?: null, $data['notas'] ?: null,
            $userId, $id,
        ]);
    }

    function cancel(int $id, int $userId): void {
        $query = "
            UPDATE TG.dbo.fuel_reception_schedule
            SET estatus = 'Cancelado', updated_by = ?, updated_at = GETDATE()
            WHERE id = ?
        ";
        $this->sql->update($query, [$userId, $id]);
    }
}
```

- [ ] **Step 2: Verify PHP syntax**

Run: `php -l _assets/models/FuelReceptionScheduleModel.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Manually verify against the real DB (uses the terminal/carrier ids created in Task 2 Step 4, and a real supplier/station)**

```php
<?php
require "vendor/autoload.php";
require "_assets/classes/common/MySqlPdoHandler.class.php";
require "_assets/models/Model.php";
require "_assets/models/FuelReceptionScheduleModel.php";

$model = new FuelReceptionScheduleModel();

// 123 = Tesoro (TG.dbo.Proveedores.id, confirmed 2026-09-05), 10 = Misiones station code.
// terminalId=2 ("Diaz Gas") and carrierId=1 ("Carretera") are the REAL ids
// Task 2 created against the live DB (confirmed 2026-09-06, commit 339db9cc)
// -- do not use 1/1 as a guess, these are the actual rows now in TG.dbo.fuel_terminals/fuel_carriers.
$terminalId = 2;
$carrierId = 1;

$id = $model->add([
    'fecha' => '2026-09-10', 'hora' => '11:00', 'supplier_id' => 123, 'terminal_id' => $terminalId,
    'station_code' => 10, 'product' => 'Regular', 'mezcla' => null, 'litros' => 31000,
    'carrier_id' => $carrierId, 'referencia' => 'TEST-1', 'notas' => 'Fila de prueba',
], 1);
echo "Created id=$id\n";

print_r($model->get_day('2026-09-10'));

$model->update($id, [
    'fecha' => '2026-09-10', 'hora' => '12:00', 'supplier_id' => 123, 'terminal_id' => $terminalId,
    'station_code' => 10, 'product' => 'Diesel', 'mezcla' => null, 'litros' => 20000,
    'carrier_id' => $carrierId, 'referencia' => 'TEST-1-EDIT', 'notas' => null,
], 1);
print_r($model->get_one($id));

$model->cancel($id, 1);
print_r($model->get_day('2026-09-10')); // should no longer include this row
```

Expected: the first `get_day` call shows the row with `supplier_nombre` = the Tesoro display name and `station_nombre` = "11 Misiones"; after `update`, `get_one` shows `product='Diesel'`, `estatus='Modificado'`; after `cancel`, the second `get_day` call returns an array without this row.

- [ ] **Step 4: Commit**

```bash
git add _assets/models/FuelReceptionScheduleModel.php
git commit -m "$(cat <<'EOF'
Añade modelo de programación de recepciones de combustible

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01WkVRfbcMNyMCN31xLtbkad
EOF
)"
```

---

## Task 4: Controller methods on `Supply`

**Files:**
- Modify: `_assets/controllers/supply.php` (append new public properties to the class-property block near the top, and new methods at the end of the class, following exactly how `PetrotalReconciliationModel $petrotalReconciliationModel` and `petrotal_reconciliation()` were added)

**Interfaces:**
- Consumes: `FuelReceptionScheduleModel` (Task 3, including `get_proveedores()` — do NOT use `ProveedoresModel::get_actives()`, see Task 3's note), `FuelTerminalsModel`, `FuelCarriersModel` (Task 2), `EstacionesModel::get_select_stations()` (existing, `_assets/models/EstacionesModel.php:63`).
- Produces: routes `GET /supply/scheduling`, `GET /supply/scheduling/{fecha}`, `GET /supply/scheduling_day_data`, `POST /supply/scheduling_add`, `POST /supply/scheduling_update`, `POST /supply/scheduling_cancel`, `POST /supply/scheduling_add_terminal`, `POST /supply/scheduling_add_carrier` — all as `Supply` methods, dispatched automatically by the existing generic router in `index.php` (confirmed: no route table exists, `index.php:104-107` does `new $controller($twig)` + `method_exists` + call, matching `_assets/controllers/{$controller}.php`'s public method named after the URL segment).

- [ ] **Step 1: Add model properties to the `Supply` class**

In `_assets/controllers/supply.php`, find the property block (around line 57, right after `public PetrotalReconciliationModel $petrotalReconciliationModel;`) and add:

```php
    public FuelReceptionScheduleModel $fuelReceptionScheduleModel;
    public FuelTerminalsModel $fuelTerminalsModel;
    public FuelCarriersModel $fuelCarriersModel;
```

- [ ] **Step 2: Instantiate them in the constructor**

Find where `$this->petrotalReconciliationModel = new PetrotalReconciliationModel();` (or equivalent) is instantiated in the `Supply::__construct()` method, and add right after it:

```php
        $this->fuelReceptionScheduleModel = new FuelReceptionScheduleModel();
        $this->fuelTerminalsModel = new FuelTerminalsModel();
        $this->fuelCarriersModel = new FuelCarriersModel();
```

- [ ] **Step 3: Verify PHP syntax after the property/constructor edits**

Run: `php -l _assets/controllers/supply.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Append the view method**

At the end of the `Supply` class (before the final closing `}`), add:

```php
    // Vista por día de la programación de recepciones de combustible —
    // reemplaza el Excel manual de Drive. $fecha llega como segmento de
    // URL opcional (/supply/scheduling/2026-09-10); sin fecha, hoy.
    public function scheduling($fecha = null)
    {
        if (!authorized(95)) {
            echo "No autorizado";
            return;
        }
        $fecha = $fecha ?: date('Y-m-d');
        $proveedores = $this->fuelReceptionScheduleModel->get_proveedores();
        $estaciones = $this->estacionesModel->get_select_stations();
        $terminales = $this->fuelTerminalsModel->get_all();
        $transportistas = $this->fuelCarriersModel->get_all();

        echo $this->twig->render($this->route . 'scheduling.html', compact(
            'fecha', 'proveedores', 'estaciones', 'terminales', 'transportistas'
        ));
    }
```

- [ ] **Step 5: Append the day-data JSON endpoint**

```php
    public function scheduling_day_data()
    {
        header('Content-Type: application/json');
        if (!authorized(95)) {
            json_output(['data' => [], 'error' => 'No autorizado']);
            return;
        }
        $fecha = $_REQUEST['fecha'] ?? date('Y-m-d');
        $filas = $this->fuelReceptionScheduleModel->get_day($fecha);
        json_output(['data' => $filas]);
    }
```

- [ ] **Step 6: Append add/update/cancel endpoints**

```php
    public function scheduling_add()
    {
        header('Content-Type: application/json');
        if (!authorized(95)) {
            json_output(['success' => false, 'message' => 'No autorizado']);
            return;
        }
        $userId = (int)($_SESSION['tg_user']['id'] ?? 0);
        $id = $this->fuelReceptionScheduleModel->add($_POST, $userId);
        json_output(['success' => true, 'id' => $id]);
    }

    public function scheduling_update()
    {
        header('Content-Type: application/json');
        if (!authorized(95)) {
            json_output(['success' => false, 'message' => 'No autorizado']);
            return;
        }
        $userId = (int)($_SESSION['tg_user']['id'] ?? 0);
        $id = (int)($_POST['id'] ?? 0);
        $this->fuelReceptionScheduleModel->update($id, $_POST, $userId);
        json_output(['success' => true]);
    }

    public function scheduling_cancel()
    {
        header('Content-Type: application/json');
        if (!authorized(95)) {
            json_output(['success' => false, 'message' => 'No autorizado']);
            return;
        }
        $userId = (int)($_SESSION['tg_user']['id'] ?? 0);
        $id = (int)($_POST['id'] ?? 0);
        $this->fuelReceptionScheduleModel->cancel($id, $userId);
        json_output(['success' => true]);
    }
```

- [ ] **Step 7: Append the edit-modal loader and catalog "add" endpoints**

```php
    // Carga el modal de captura/edición. Sin $id: formulario vacío para
    // "Agregar recepción". Con $id: precarga la fila (patrón idéntico a
    // frmCapturaCompra()).
    public function scheduling_modal()
    {
        header('Content-Type: application/json');
        if (!authorized(95)) {
            json_output(['success' => false, 'message' => 'No autorizado']);
            return;
        }
        $id = (int)($_POST['id'] ?? 0);
        $fecha = $_POST['fecha'] ?? date('Y-m-d');
        $registro = $id ? $this->fuelReceptionScheduleModel->get_one($id) : null;

        $proveedores = $this->fuelReceptionScheduleModel->get_proveedores();
        $estaciones = $this->estacionesModel->get_select_stations();
        $terminales = $this->fuelTerminalsModel->get_all();
        $transportistas = $this->fuelCarriersModel->get_all();

        $html = $this->twig->render($this->route . 'modals/frmProgramacionRecepcion.html', compact(
            'registro', 'fecha', 'proveedores', 'estaciones', 'terminales', 'transportistas'
        ));
        json_output(['success' => true, 'html' => $html]);
    }

    public function scheduling_add_terminal()
    {
        header('Content-Type: application/json');
        if (!authorized(95)) {
            json_output(['success' => false, 'message' => 'No autorizado']);
            return;
        }
        $nombre = trim($_POST['nombre'] ?? '');
        if ($nombre === '') {
            json_output(['success' => false, 'message' => 'Nombre requerido']);
            return;
        }
        if ($existing = $this->fuelTerminalsModel->find_by_name($nombre)) {
            json_output(['success' => true, 'id' => $existing['id'], 'nombre' => $existing['nombre']]);
            return;
        }
        $id = $this->fuelTerminalsModel->add($nombre, null);
        json_output(['success' => true, 'id' => $id, 'nombre' => $nombre]);
    }

    public function scheduling_add_carrier()
    {
        header('Content-Type: application/json');
        if (!authorized(95)) {
            json_output(['success' => false, 'message' => 'No autorizado']);
            return;
        }
        $nombre = trim($_POST['nombre'] ?? '');
        if ($nombre === '') {
            json_output(['success' => false, 'message' => 'Nombre requerido']);
            return;
        }
        if ($existing = $this->fuelCarriersModel->find_by_name($nombre)) {
            json_output(['success' => true, 'id' => $existing['id'], 'nombre' => $existing['nombre']]);
            return;
        }
        $id = $this->fuelCarriersModel->add($nombre);
        json_output(['success' => true, 'id' => $id, 'nombre' => $nombre]);
    }
```

- [ ] **Step 8: Verify PHP syntax**

Run: `php -l _assets/controllers/supply.php`
Expected: `No syntax errors detected`

- [ ] **Step 9: Commit**

```bash
git add _assets/controllers/supply.php
git commit -m "$(cat <<'EOF'
Añade endpoints de programación de recepciones de combustible al controlador Supply

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01WkVRfbcMNyMCN31xLtbkad
EOF
)"
```

---

## Task 5: Views — day screen and capture modal

**Files:**
- Create: `views/supply/scheduling.html`
- Create: `views/supply/modals/frmProgramacionRecepcion.html`

**Interfaces:**
- Consumes: template variables produced by `Supply::scheduling()` (`fecha, proveedores, estaciones, terminales, transportistas`) and `Supply::scheduling_modal()` (`registro, fecha, proveedores, estaciones, terminales, transportistas`).
- Produces: DOM ids consumed by `scheduling.js` in Task 6 — `#fecha_programacion`, `#btnDiaAnterior`, `#btnDiaSiguiente`, `#contenedorGrupos`, `#btnAgregarRecepcion`, `#modalProgramacion` (Bootstrap modal wrapper), `#modalProgramacionContent` (injection target), and inside the modal partial: `#frmProgramacion`, `#fecha`, `#supplier_id`, `#terminal_id`, `#product`, `#mezcla_wrapper`, `#mezcla`, `#litros`, `#station_code`, `#hora`, `#carrier_id`, `#referencia`, `#notas`, `#id` (hidden), `#btnNuevaTerminal`, `#btnNuevoTransportista`.

- [ ] **Step 1: Write `views/supply/scheduling.html`**

```html
{% extends "views/layouts/base.html" %}
{% block title %}Programación de Recepciones{% endblock %}
{% block mycss %}{% endblock %}
{% block menutitle %}Programación de Recepciones{% endblock %}
{% block content %}
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <div class="row align-items-center">
                    <div class="col-auto">
                        <button type="button" class="btn btn-outline-secondary" id="btnDiaAnterior">
                            <i data-feather="chevron-left"></i>
                        </button>
                    </div>
                    <div class="col-auto">
                        <input type="date" class="form-control" id="fecha_programacion" value="{{ fecha }}">
                    </div>
                    <div class="col-auto">
                        <button type="button" class="btn btn-outline-secondary" id="btnDiaSiguiente">
                            <i data-feather="chevron-right"></i>
                        </button>
                    </div>
                    <div class="col-auto ms-auto">
                        <span class="text-muted">Total programado del día: <strong id="totalLitrosDia">0</strong> L</span>
                    </div>
                    <div class="col-auto">
                        <button type="button" class="btn btn-primary" id="btnAgregarRecepcion">
                            <i data-feather="plus"></i> Agregar recepción
                        </button>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <div id="contenedorGrupos">
                    <p class="text-muted text-center">Cargando…</p>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalProgramacion" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Recepción programada</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="frmProgramacion">
                <div id="modalProgramacionContent"></div>
            </form>
        </div>
    </div>
</div>
{% endblock %}
{% block myjs %}
<script>
    window.SCHEDULING_PROVEEDORES = {{ proveedores|json_encode|raw }};
    window.SCHEDULING_ESTACIONES = {{ estaciones|json_encode|raw }};
    window.SCHEDULING_TERMINALES = {{ terminales|json_encode|raw }};
    window.SCHEDULING_TRANSPORTISTAS = {{ transportistas|json_encode|raw }};
</script>
<script src="/_assets/js/scheduling.js"></script>
{% endblock %}
```

- [ ] **Step 2: Write `views/supply/modals/frmProgramacionRecepcion.html`**

```html
<input type="hidden" id="id" name="id" value="{{ registro.id|default('') }}">
<div class="modal-body">
    <div class="row">
        <div class="col-12 col-md-4">
            <div class="mb-3">
                <label class="col-form-label col-form-label-sm" for="fecha">Fecha:</label>
                <input type="date" class="form-control" id="fecha" name="fecha" value="{{ registro.fecha|default(fecha) }}" required>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="mb-3">
                <label class="col-form-label col-form-label-sm" for="hora">Hora:</label>
                <input type="time" class="form-control" id="hora" name="hora" value="{{ registro.hora|default('') }}">
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="mb-3">
                <label class="col-form-label col-form-label-sm" for="litros">Litros:</label>
                <input type="number" min="1" step="1" class="form-control" id="litros" name="litros" value="{{ registro.litros|default('') }}" required>
            </div>
        </div>
    </div>
    <div class="row">
        <div class="col-12 col-md-6">
            <div class="mb-3">
                <label class="col-form-label col-form-label-sm" for="supplier_id">Proveedor:</label>
                <select class="selectpicker form-control" data-live-search="true" data-width="100%" id="supplier_id" name="supplier_id" required>
                    <option value="">Seleccione un proveedor…</option>
                    {% for p in proveedores %}
                    <option value="{{ p.id }}" {{ registro.supplier_id == p.id ? 'selected' : '' }}>{{ p.nombre }}</option>
                    {% endfor %}
                </select>
            </div>
        </div>
        <div class="col-12 col-md-6">
            <div class="mb-3">
                <label class="col-form-label col-form-label-sm" for="terminal_id">Terminal / base de carga:</label>
                <div class="input-group">
                    <select class="form-control" id="terminal_id" name="terminal_id" required>
                        <option value="">Seleccione una terminal…</option>
                        {% for t in terminales %}
                        <option value="{{ t.id }}" {{ registro.terminal_id == t.id ? 'selected' : '' }}>{{ t.nombre }}</option>
                        {% endfor %}
                    </select>
                    <button type="button" class="btn btn-outline-secondary" id="btnNuevaTerminal">+ Nueva</button>
                </div>
            </div>
        </div>
    </div>
    <div class="row">
        <div class="col-12 col-md-6">
            <div class="mb-3">
                <label class="col-form-label col-form-label-sm" for="product">Producto:</label>
                <select class="form-control" id="product" name="product" required>
                    <option value="Regular" {{ registro.product == 'Regular' ? 'selected' : '' }}>Regular</option>
                    <option value="Premium" {{ registro.product == 'Premium' ? 'selected' : '' }}>Premium</option>
                    <option value="Diesel" {{ registro.product == 'Diesel' ? 'selected' : '' }}>Diesel</option>
                    <option value="Mixta" {{ registro.product == 'Mixta' ? 'selected' : '' }}>Mixta</option>
                </select>
            </div>
        </div>
        <div class="col-12 col-md-6" id="mezcla_wrapper" style="{{ registro.product == 'Mixta' ? '' : 'display:none;' }}">
            <div class="mb-3">
                <label class="col-form-label col-form-label-sm" for="mezcla">Mezcla:</label>
                <input type="text" class="form-control" id="mezcla" name="mezcla" placeholder="Ej. 21/11" value="{{ registro.mezcla|default('') }}">
            </div>
        </div>
    </div>
    <div class="row">
        <div class="col-12 col-md-6">
            <div class="mb-3">
                <label class="col-form-label col-form-label-sm" for="station_code">Estación:</label>
                <select class="selectpicker form-control" data-live-search="true" data-width="100%" id="station_code" name="station_code" required>
                    <option value="">Seleccione una estación…</option>
                    {% for e in estaciones %}
                    <option value="{{ e.Codigo }}" {{ registro.station_code == e.Codigo ? 'selected' : '' }}>{{ e.Nombre }}</option>
                    {% endfor %}
                </select>
            </div>
        </div>
        <div class="col-12 col-md-6">
            <div class="mb-3">
                <label class="col-form-label col-form-label-sm" for="carrier_id">Transportista:</label>
                <div class="input-group">
                    <select class="form-control" id="carrier_id" name="carrier_id">
                        <option value="">Seleccione un transportista…</option>
                        {% for c in transportistas %}
                        <option value="{{ c.id }}" {{ registro.carrier_id == c.id ? 'selected' : '' }}>{{ c.nombre }}</option>
                        {% endfor %}
                    </select>
                    <button type="button" class="btn btn-outline-secondary" id="btnNuevoTransportista">+ Nuevo</button>
                </div>
            </div>
        </div>
    </div>
    <div class="row">
        <div class="col-12 col-md-6">
            <div class="mb-3">
                <label class="col-form-label col-form-label-sm" for="referencia">Referencia (folio/embarque/guía):</label>
                <input type="text" class="form-control" id="referencia" name="referencia" value="{{ registro.referencia|default('') }}">
            </div>
        </div>
        <div class="col-12 col-md-6">
            <div class="mb-3">
                <label class="col-form-label col-form-label-sm" for="notas">Notas:</label>
                <input type="text" class="form-control" id="notas" name="notas" value="{{ registro.notas|default('') }}">
            </div>
        </div>
    </div>
</div>
<div class="modal-footer">
    <button type="submit" class="btn btn-primary" id="btnGuardarProgramacion">Guardar</button>
    <button type="button" class="btn btn-danger" data-bs-dismiss="modal">Cancelar</button>
</div>
```

Note: `supplier_id` option `value` uses `p.id` (`TG.dbo.Proveedores.id`, produced by `FuelReceptionScheduleModel::get_proveedores()` from Task 3) — NOT `id_control_gas`. This matches exactly what `add()`/`update()` write into `fuel_reception_schedule.supplier_id` and what `get_day()`'s join expects (`p1.id = s.supplier_id`). Do not swap this for `ProveedoresModel::get_actives()`'s `id_control_gas` field — see Task 3's note on why that method doesn't expose the right id.

- [ ] **Step 2: Verify Twig syntax by rendering a smoke test**

There's no standalone Twig linter in this project; verify by requesting the page in a browser after Task 6 is done (this step is folded into Task 6's manual verification). For now, just re-read both files and confirm every `{{ }}`/`{% %}` tag is closed and every referenced variable name matches what Task 4's controller methods pass via `compact(...)`.

- [ ] **Step 3: Commit**

```bash
git add "views/supply/scheduling.html" "views/supply/modals/frmProgramacionRecepcion.html"
git commit -m "$(cat <<'EOF'
Añade vistas de programación de recepciones de combustible

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01WkVRfbcMNyMCN31xLtbkad
EOF
)"
```

---

## Task 6: JS — day navigation, grouped rendering, modal wiring

**Files:**
- Create: `_assets/js/scheduling.js`

**Interfaces:**
- Consumes: `window.SCHEDULING_PROVEEDORES/ESTACIONES/TERMINALES/TRANSPORTISTAS` (Task 5), DOM ids from Task 5, endpoints from Task 4 (`/supply/scheduling_day_data`, `/supply/scheduling_modal`, `/supply/scheduling_add`, `/supply/scheduling_update`, `/supply/scheduling_cancel`, `/supply/scheduling_add_terminal`, `/supply/scheduling_add_carrier`).
- Produces: nothing consumed by later tasks (this is the last app-code task before the import script, which is independent).

- [ ] **Step 1: Write `_assets/js/scheduling.js`**

```javascript
function formatearFilaGrupo(fila) {
    return `
        <tr data-id="${fila.id}">
            <td>${fila.hora || '<span class="text-muted">—</span>'}</td>
            <td>${fila.product}${fila.mezcla ? ' (' + fila.mezcla + ')' : ''}</td>
            <td>${Number(fila.litros).toLocaleString('es-MX')}</td>
            <td>${fila.station_nombre || '<span class="text-muted">—</span>'}</td>
            <td>${fila.carrier_nombre || '<span class="text-muted">—</span>'}</td>
            <td>${fila.referencia || ''}</td>
            <td>${fila.notas || ''}</td>
            <td>
                <button type="button" class="btn btn-sm btn-outline-primary btn-editar-recepcion" data-id="${fila.id}">Editar</button>
                <button type="button" class="btn btn-sm btn-outline-danger btn-cancelar-recepcion" data-id="${fila.id}">Cancelar</button>
            </td>
        </tr>
    `;
}

function renderGrupos(filas) {
    const contenedor = $('#contenedorGrupos');
    contenedor.empty();

    if (!filas.length) {
        contenedor.html('<p class="text-muted text-center">Sin recepciones programadas para este día.</p>');
        $('#totalLitrosDia').text('0');
        return;
    }

    let totalLitros = 0;
    const grupos = {};
    filas.forEach(function (fila) {
        totalLitros += Number(fila.litros) || 0;
        const clave = (fila.supplier_nombre || 'Sin proveedor') + ' — ' + (fila.terminal_nombre || 'Sin terminal');
        if (!grupos[clave]) grupos[clave] = [];
        grupos[clave].push(fila);
    });

    Object.keys(grupos).sort().forEach(function (clave) {
        const filasGrupo = grupos[clave];
        const tabla = `
            <div class="mb-4">
                <h6>${clave}</h6>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Hora</th><th>Producto</th><th>Litros</th><th>Estación</th>
                                <th>Transportista</th><th>Referencia</th><th>Notas</th><th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>${filasGrupo.map(formatearFilaGrupo).join('')}</tbody>
                    </table>
                </div>
            </div>
        `;
        contenedor.append(tabla);
    });

    $('#totalLitrosDia').text(totalLitros.toLocaleString('es-MX'));
}

function cargarDia(fecha) {
    $('#contenedorGrupos').html('<p class="text-muted text-center">Cargando…</p>');
    $.get('/supply/scheduling_day_data', { fecha: fecha })
        .done(function (resp) {
            renderGrupos(resp.data || []);
        })
        .fail(function () {
            $('#contenedorGrupos').html('<p class="text-danger text-center">No se pudo cargar la programación.</p>');
        });
}

function abrirModal(id, fecha) {
    $.post('/supply/scheduling_modal', { id: id || '', fecha: fecha })
        .done(function (resp) {
            if (!resp.success) {
                alertify.myAlert('<div class="text-danger text-center"><p>No se pudo abrir el formulario.</p></div>');
                return;
            }
            $('#modalProgramacionContent').html(resp.html);
            $('.selectpicker').selectpicker();
            $('#product').on('change', function () {
                $('#mezcla_wrapper').toggle($(this).val() === 'Mixta');
            });
            const modal = new bootstrap.Modal(document.getElementById('modalProgramacion'));
            modal.show();
        })
        .fail(function () {
            alertify.myAlert('<div class="text-danger text-center"><p>No se pudo abrir el formulario.</p></div>');
        });
}

$(document).ready(function () {
    const fechaInput = $('#fecha_programacion');

    cargarDia(fechaInput.val());

    fechaInput.on('change', function () {
        cargarDia($(this).val());
    });

    $('#btnDiaAnterior').on('click', function () {
        const fecha = new Date(fechaInput.val() + 'T00:00:00');
        fecha.setDate(fecha.getDate() - 1);
        fechaInput.val(fecha.toISOString().slice(0, 10)).trigger('change');
    });

    $('#btnDiaSiguiente').on('click', function () {
        const fecha = new Date(fechaInput.val() + 'T00:00:00');
        fecha.setDate(fecha.getDate() + 1);
        fechaInput.val(fecha.toISOString().slice(0, 10)).trigger('change');
    });

    $('#btnAgregarRecepcion').on('click', function () {
        abrirModal(null, fechaInput.val());
    });

    $(document).on('click', '.btn-editar-recepcion', function () {
        abrirModal($(this).data('id'), fechaInput.val());
    });

    $(document).on('click', '.btn-cancelar-recepcion', function () {
        const id = $(this).data('id');
        if (!confirm('¿Cancelar esta recepción programada?')) return;
        $.post('/supply/scheduling_cancel', { id: id })
            .done(function () { cargarDia(fechaInput.val()); })
            .fail(function () {
                alertify.myAlert('<div class="text-danger text-center"><p>No se pudo cancelar.</p></div>');
            });
    });

    $(document).on('submit', '#frmProgramacion', function (e) {
        e.preventDefault();
        const datos = $(this).serialize();
        const id = $('#id').val();
        const url = id ? '/supply/scheduling_update' : '/supply/scheduling_add';
        $.post(url, datos)
            .done(function (resp) {
                if (!resp.success) {
                    alertify.myAlert('<div class="text-danger text-center"><p>No se pudo guardar.</p></div>');
                    return;
                }
                bootstrap.Modal.getInstance(document.getElementById('modalProgramacion')).hide();
                cargarDia(fechaInput.val());
            })
            .fail(function () {
                alertify.myAlert('<div class="text-danger text-center"><p>No se pudo guardar.</p></div>');
            });
    });

    $(document).on('click', '#btnNuevaTerminal', function () {
        const nombre = prompt('Nombre de la nueva terminal/base de carga:');
        if (!nombre) return;
        $.post('/supply/scheduling_add_terminal', { nombre: nombre })
            .done(function (resp) {
                if (!resp.success) return;
                $('#terminal_id').append(`<option value="${resp.id}" selected>${resp.nombre}</option>`);
            });
    });

    $(document).on('click', '#btnNuevoTransportista', function () {
        const nombre = prompt('Nombre del nuevo transportista:');
        if (!nombre) return;
        $.post('/supply/scheduling_add_carrier', { nombre: nombre })
            .done(function (resp) {
                if (!resp.success) return;
                $('#carrier_id').append(`<option value="${resp.id}" selected>${resp.nombre}</option>`);
            });
    });
});
```

- [ ] **Step 2: Add the sidebar entry**

In `views/layouts/sidebar.html`, right after the existing `{% if authorized(91) %}...{% endif %}` block for "Conciliación Petrotal" (around line 186-192), add:

```twig
      {% if authorized(95) %}
      <li class="sidebar-item">
        <a class="sidebar-link" href="/supply/scheduling">
          <i data-feather="calendar"></i>
          <span class="align-middle">Programación de Recepciones</span>
        </a>
      </li>
      {% endif %}
```

- [ ] **Step 3: Manual browser verification**

The user runs the local PHP server themselves (do not start/reload it — confirmed project convention). Ask the user to:
1. Load `/supply/scheduling` while logged in as a user with permission 95 (grant it to the test user first via whatever mechanism the project already uses for `tg_permissions`, e.g. appending `,95` to that user's `permissions` column).
2. Confirm the date picker shows today, "Sin recepciones programadas para este día." shows (table is empty at this point), and no console errors.
3. Click "Agregar recepción", fill the form (pick any supplier/terminal/station/product/liters), submit, confirm the row appears grouped under "Proveedor — Terminal".
4. Click "+ Nueva" next to Terminal, type a new name, confirm it's selected and later saves correctly.
5. Edit the row just created, change liters, confirm the table updates.
6. Cancel the row, confirm it disappears from the day view.
7. Navigate to the previous/next day with the arrow buttons, confirm the date input and loaded data change accordingly.

Report back pass/fail per step — this is the actual functional verification since no automated test framework exists.

- [ ] **Step 4: Commit**

```bash
git add _assets/js/scheduling.js views/layouts/sidebar.html
git commit -m "$(cat <<'EOF'
Añade JS y entrada de menú para programación de recepciones de combustible

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01WkVRfbcMNyMCN31xLtbkad
EOF
)"
```

---

## Task 7: One-time import of "Programa Julio 2026.xlsx"

**Files:**
- Create (scratchpad, NOT committed to the app repo): a standalone PHP script, e.g. `import_programa_julio_2026.php`, run once from the repo root so `vendor/autoload.php` and `MySqlPdoHandler` resolve, then discarded.

**Interfaces:**
- Consumes: `FuelReceptionScheduleModel::add()` (Task 3), `FuelTerminalsModel::find_by_name()/add()` (Task 2), `FuelCarriersModel::find_by_name()/add()` (Task 2), the station mapping table and supplier id mapping confirmed in this session (below).
- Produces: rows in `TG.dbo.fuel_reception_schedule` for July 2026, `created_by = 6296` (confirmed 2026-09-06 against the real DB: `TG.dbo.Usuario.Id` for `Usuario = 'alejandro.martinez'`, `Nombre = 'Manuel Alejandro Martinez Velez'`, `Correo = 'alejandro.martinez@totalgas.com'` — the login table is `TG.dbo.Usuario`, NOT `tg_users`; confirmed by reading `sp_usuario_login`'s definition, which selects from `dbo.Usuario u`). No changes to files inside `AplicativoPhp/` — this script lives only in the scratchpad directory and is run manually via `php import_programa_julio_2026.php`, never wired into the app.

- [ ] **Step 1: `created_by` is already confirmed — no lookup needed**

`CREATED_BY = 6296` is already the real, verified id (see Interfaces above). Do not re-derive it or query `tg_users` (that table does not exist in this schema — confirmed 2026-09-06). Skip straight to Step 2.

- [ ] **Step 2: Write the import script in the scratchpad directory**

Write to `C:\Users\ALEJAN~1.MAR\AppData\Local\Temp\claude\C--Users-alejandro-martinez-Desktop-codigo-AplicativoPhp\1883dbdc-dc39-4a32-ba12-0062101bc7e2\scratchpad\import_programa_julio_2026.php` (adjust if this session's scratchpad path differs from a fresh session — check the current system prompt for the live path):

```php
<?php
// One-time import of "Programa Julio 2026.xlsx" into TG.dbo.fuel_reception_schedule.
// Run once from the AplicativoPhp repo root: php <path-to-this-script>
// Never wire this into the app; it's throwaway test-data tooling.

chdir('C:\\Users\\alejandro.martinez\\Desktop\\codigo\\AplicativoPhp');
require 'vendor/autoload.php';
require '_assets/classes/common/MySqlPdoHandler.class.php';
require '_assets/models/Model.php';
require '_assets/models/FuelReceptionScheduleModel.php';
require '_assets/models/FuelTerminalsModel.php';
require '_assets/models/FuelCarriersModel.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

const CREATED_BY = 6296; // alejandro.martinez, TG.dbo.Usuario.Id, confirmed 2026-09-06 against the real DB

// Confirmed 2026-09-05 against TG.dbo.Proveedores / SG12.dbo.Proveedores.
const SUPPLIER_IDS = [
    'PREMIER' => 138,   // Premier Gas
    'TESORO' => 123,    // Tesoro México Supply & Marketing
    'MGC' => 139,       // MGC México
    'ENEREY' => 150,    // Enerey Latinoamérica
    'PETROTAL' => 122,  // Petrotal
    'AEMSA' => 163,     // ALTOS ENERGETICOS MEXICANOS, confirmed 2026-09-06 against the real DB
    'ESSA FUEL' => 151, // Essa Fuel Advisors
    'LOBO' => 143,      // Petrolíferos Lobo
];

// Confirmed 2026-09-05 against TG.dbo.Estaciones. Keys are lowercased,
// whitespace-normalized station names as they appear (without the leading
// numeric prefix) in "Programa Julio 2026.xlsx".
const STATION_CODES = [
    'solis' => 27,
    'independencia' => 3, // Aguascalientes -- Av. Independencia is its fiscal address
    'jarudo' => 29, 'jaurdo' => 29, 'jaurudo' => 29,
    'lerdo' => 5,
    'clara' => 26,
    'fuentes' => 25,
    'tecnologico' => 22,
    'santiago' => 28, 'samtiago' => 28,
    'ahumada' => 31, 'villa ahumada' => 31,
    'castaño' => 32,
    'delicias' => 19,
    'parral' => 18,
    'colosio' => 199,
    'picachos' => 34, 'picachis' => 34, 'piccahos' => 34,
    'ventanas' => 35,
    'lopez mateos' => 6,
    'san rafael' => 36,
    'g chica' => 7,
    'g grande' => 2, 'ggrande' => 2,
    'satelite' => 24,
    'puertecito' => 37,
    'plutarco' => 21, 'plutraco' => 21,
    'municipio' => 8, 'munucipio' => 8,
    'jesus maria' => 38,
    'aztecas' => 9,
    'praxedis' => 40,
    'misiones' => 10,
    'pto de palos' => 11,
    'madrid' => 12,
    'permuta' => 13,
    'electrolux' => 14,
    'aeronautica' => 15,
    'ejercito' => 23,
    'custodia' => 16,
    'anapra' => 17,
    'travel center' => 33,
    'hnos escobar' => 30,
];

function normalizarEstacion(string $texto): ?string {
    $texto = strtolower(trim($texto));
    // Quita el prefijo numérico ("6410 misiones" -> "misiones").
    $texto = preg_replace('/^\d{3,6}\s+/', '', $texto);
    $texto = preg_replace('/\s+/', ' ', $texto);
    return $texto ?: null;
}

function resolverProveedor(string $encabezadoBloque): ?int {
    $encabezado = strtoupper($encabezadoBloque);
    foreach (SUPPLIER_IDS as $clave => $id) {
        if ($id !== null && strpos($encabezado, $clave) !== false) return $id;
    }
    return null;
}

function normalizarProducto(string $texto): array {
    $texto = trim($texto);
    if (preg_match('/^(\d{1,2}\/\d{1,2})\s*Mixta$/i', $texto, $m)) {
        return ['Mixta', $m[1]];
    }
    if (stripos($texto, 'mixta') !== false) return ['Mixta', $texto];
    if (stripos($texto, 'regular') !== false) return ['Regular', null];
    if (stripos($texto, 'premium') !== false) return ['Premium', null];
    if (stripos($texto, 'diesel') !== false) return ['Diesel', null];
    return ['Regular', null]; // fallback -- log this case for manual review
}

$path = 'C:\\Users\\alejandro.martinez\\Downloads\\Programa Julio 2026.xlsx';
$spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);

$scheduleModel = new FuelReceptionScheduleModel();
$terminalsModel = new FuelTerminalsModel();
$carriersModel = new FuelCarriersModel();

$terminalCache = [];
$carrierCache = [];
function resolverTerminal($terminalsModel, array &$cache, string $nombre): int {
    $clave = strtolower($nombre);
    if (isset($cache[$clave])) return $cache[$clave];
    $existing = $terminalsModel->find_by_name($nombre);
    $id = $existing ? $existing['id'] : $terminalsModel->add($nombre, null);
    $cache[$clave] = $id;
    return $id;
}
function resolverTransportista($carriersModel, array &$cache, string $nombre): ?int {
    if ($nombre === '') return null;
    $clave = strtolower($nombre);
    if (isset($cache[$clave])) return $cache[$clave];
    $existing = $carriersModel->find_by_name($nombre);
    $id = $existing ? $existing['id'] : $carriersModel->add($nombre);
    $cache[$clave] = $id;
    return $id;
}

$insertados = 0;
$omitidos = [];

foreach ($spreadsheet->getSheetNames() as $nombreHoja) {
    $sheet = $spreadsheet->getSheetByName($nombreHoja);
    $highestRow = $sheet->getHighestDataRow();
    $highestCol = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

    // Detecta encabezados de bloque: una celda no vacía en la fila N cuyo
    // texto no es una fecha ni un número, seguida en la fila N+1 por una
    // celda "FECHA" (o "FECHA  " con espacios, visto en el dump real).
    for ($row = 1; $row <= $highestRow; $row++) {
        for ($col = 1; $col <= $highestCol; $col++) {
            $colLetter = Coordinate::stringFromColumnIndex($col);
            $encabezado = trim((string)$sheet->getCell($colLetter . $row)->getValue());
            if ($encabezado === '' || stripos($encabezado, 'FECHA') !== false) continue;

            $filaColumnas = trim((string)$sheet->getCell($colLetter . ($row + 1))->getValue());
            if (stripos($filaColumnas, 'FECHA') !== 0 && stripos($filaColumnas, 'FECHA') === false) continue;

            $supplierId = resolverProveedor($encabezado);
            if ($supplierId === null) continue; // no es un encabezado de bloque de proveedor reconocido

            // Extrae el nombre de terminal entre paréntesis, ej. "TESORO... (Diaz Gas)" -> "Diaz Gas".
            $terminalNombre = 'Sin terminal';
            if (preg_match('/\(([^)]+)\)/', $encabezado, $m)) {
                $terminalNombre = trim($m[1]);
            }
            $terminalId = resolverTerminal($terminalsModel, $terminalCache, $terminalNombre);

            // Recorre filas de datos bajo este bloque hasta encontrar una fila vacía
            // en esta columna o un nuevo encabezado (mismo criterio que arriba).
            for ($dataRow = $row + 2; $dataRow <= $highestRow; $dataRow++) {
                $fechaVal = trim((string)$sheet->getCell($colLetter . $dataRow)->getFormattedValue());
                if ($fechaVal === '') break; // fin del bloque

                $litros = trim((string)$sheet->getCell(Coordinate::stringFromColumnIndex($col + 1) . $dataRow)->getValue());
                $productoTexto = trim((string)$sheet->getCell(Coordinate::stringFromColumnIndex($col + 2) . $dataRow)->getValue());
                $estacionTexto = trim((string)$sheet->getCell(Coordinate::stringFromColumnIndex($col + 3) . $dataRow)->getValue());
                $horaTexto = trim((string)$sheet->getCell(Coordinate::stringFromColumnIndex($col + 4) . $dataRow)->getValue());
                $transpTexto = trim((string)$sheet->getCell(Coordinate::stringFromColumnIndex($col + 5) . $dataRow)->getValue());

                if (!is_numeric($litros) || $productoTexto === '' || $estacionTexto === '') {
                    $omitidos[] = "$nombreHoja!$colLetter$dataRow: fila incompleta ($fechaVal / $litros / $productoTexto / $estacionTexto)";
                    continue;
                }

                // Celdas ambiguas tipo "1148 / 1159" -- confirmado con el usuario:
                // excluir del import, son errores de captura del Excel original.
                if (strpos($estacionTexto, '/') !== false) {
                    $omitidos[] = "$nombreHoja!$colLetter$dataRow: estación ambigua '$estacionTexto'";
                    continue;
                }

                $estacionClave = normalizarEstacion($estacionTexto);
                if (!isset(STATION_CODES[$estacionClave])) {
                    $omitidos[] = "$nombreHoja!$colLetter$dataRow: estación no mapeada '$estacionTexto' (normalizada: '$estacionClave')";
                    continue;
                }
                $stationCode = STATION_CODES[$estacionClave];

                [$producto, $mezcla] = normalizarProducto($productoTexto);

                $fechaObj = \DateTime::createFromFormat('d/m/Y', $fechaVal);
                if (!$fechaObj) {
                    $omitidos[] = "$nombreHoja!$colLetter$dataRow: fecha inválida '$fechaVal'";
                    continue;
                }

                $carrierId = resolverTransportista($carriersModel, $carrierCache, $transpTexto);

                $scheduleModel->add([
                    'fecha' => $fechaObj->format('Y-m-d'),
                    'hora' => $horaTexto ?: null,
                    'supplier_id' => $supplierId,
                    'terminal_id' => $terminalId,
                    'station_code' => $stationCode,
                    'product' => $producto,
                    'mezcla' => $mezcla,
                    'litros' => (int)$litros,
                    'carrier_id' => $carrierId,
                    'referencia' => null,
                    'notas' => null,
                ], CREATED_BY);
                $insertados++;
            }
        }
    }
}

echo "Insertados: $insertados\n";
echo "Omitidos: " . count($omitidos) . "\n";
foreach ($omitidos as $o) echo "  - $o\n";
```

**Note for the implementer:** the block-detection logic above (scanning for a header cell followed by a "FECHA" row) is a best-effort parse of an irregular hand-built spreadsheet — it will very likely need small adjustments once run against real sheet data (e.g. the MGC/AEMSA blocks use `DESTINO`/`EMBARQUE` instead of `TRANSP` in column position 5/6, per the spec's documented irregularity). Budget time in this task for iterating on the column-offset logic per block shape, re-running, and re-checking the `$omitidos` log — this is expected, not a sign something is broken. Only fix by broadening the detection, never by dropping rows silently instead of logging them.

- [ ] **Step 3: Run the import against the real database**

Run: `php import_programa_julio_2026.php` (from wherever it was saved). Both `CREATED_BY = 6296` and `SUPPLIER_IDS['AEMSA'] = 163` are already the real confirmed values — no placeholder replacement needed before running.

Expected: `Insertados: N` where N is a large majority of the ~600+ data rows across 31 sheets (exact count varies by how many blocks are recognized on the first pass), plus a printed list of omitted rows for manual review.

- [ ] **Step 4: Cross-check the import count**

```php
<?php
require "vendor/autoload.php";
require "_assets/classes/common/MySqlPdoHandler.class.php";
$sql = MySqlPdoHandler::getInstance();
$sql->connect("SG12");
$rows = $sql->select("SELECT COUNT(*) AS n, MIN(fecha) AS min_fecha, MAX(fecha) AS max_fecha FROM TG.dbo.fuel_reception_schedule", []);
print_r($rows);
```

Expected: `min_fecha` around `2026-07-01`, `max_fecha` around `2026-07-31` (or `2026-08-01` if hora/date rollover edge cases produced an off-by-one — verify against the source Excel's last populated sheet if so).

- [ ] **Step 5: Verify the day view renders the imported data**

Ask the user to load `/supply/scheduling/2026-07-01` in the browser and confirm the groups match the "01" sheet dump captured during brainstorming (Premier Gas, Tesoro, MGC, Enerey blocks with their respective terminals and row counts).

- [ ] **Step 6: No commit**

This task produces no committed files — the import script stays in the scratchpad directory and is discarded after use, per the spec ("script de una sola vez, no una pantalla de importación permanente"). If the user wants to keep a copy for reference, that's their call, but it must not be added to the `AplicativoPhp` git repo.

---

## Manual Deploy Note

Per project convention (`[[deploy-manual-scripts-y-apier]]` from memory — nothing is "production" until the user confirms), do not consider this feature live after finishing these tasks. At the end of Task 6, list every file touched (new models, new controller methods, new views, new JS, the sidebar edit) and wait for the user's explicit confirmation before treating it as done.
