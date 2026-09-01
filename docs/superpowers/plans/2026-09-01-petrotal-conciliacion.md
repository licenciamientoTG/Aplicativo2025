# Conciliación Petrotal — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the Fase 2 reconciliation screen that suggests, and lets Abastos confirm, the link between a real supplier's invoice (Tesoro, Premiergas, etc.) and the matching Petrotal invoice, for stations that capture receptions in ControlGas.

**Architecture:** New model `PetrotalReconciliationModel` parses `txtref` from `MovimientosTan` (already fetched by the existing `sp_obtener_recepciones_combustible`), normalizes remision/folio to match `FacturasRecibidas` of the real supplier, and matches Petrotal invoices by `Destino`→`PermisoCRE` filtered by product. New controller methods on the existing `Supply` class expose a DataTables endpoint and confirm/undo actions writing to the existing (currently empty) `TG.dbo.FacturasMovimientosTanques` table. New Twig view + JS follow the exact pattern already used by `station_portal/mis_recepciones`.

**Tech Stack:** PHP (no framework), Twig templates, jQuery + DataTables + Bootstrap (bootstrap-select), MSSQL via `MySqlPdoHandler`/PDO, no test framework — verification is manual (`php -l` + real-data checks against TG).

**Spec:** `docs/superpowers/specs/2026-09-01-petrotal-conciliacion-design.md`

## Global Constraints

- No test framework exists in this project (confirmed in `CLAUDE.md`) — every "test" step below is `php -l` syntax verification plus a manual data check documented as exact commands to run and expected output to read.
- Never modify `_assets/controllers/supply.php`'s existing methods (`fuel_reconciliation`, `buscar_facturas_proveedor`, `buscar_facturas_petrotal`, `guardar_asignacion_completa`) or the `FacturasMovimientosTanques` schema — only add new methods/columns are forbidden, this task only *inserts rows* into the existing schema.
- Petrotal's `EmisorRfc` is fixed as `PET180213L66` (confirmed against 32 real invoices in this session) — hardcode it, don't look it up.
- All new SQL must use parameterized queries via `$this->sql->select/insert/update($query, $params)` — never string-concatenate user input (the old `buscar_facturas_proveedor` had SQL injection via `$searchTerm`; do not repeat that pattern).
- New permission id: **91** (`ver_conciliacion_petrotal`), confirmed free against `TG.dbo.tg_permissions` (max id in use is 90) — hardcode this exact id in `authorized()` calls and the sidebar `{% if %}`.
- Follow the existing modal/DataTable/JS conventions from `views/station_portal/mis_recepciones.html` + `_assets/js/station_portal.js` exactly (button-triggered DataTable init, no autoload, `alertify.myAlert` for errors, `loading` class toggling).

---

## File Structure

| File | Responsibility |
|---|---|
| `_assets/models/MovimientosTanModel.php` (modify) | Add `ProveedorRfc`/`ProveedorNombre` columns to the existing recepciones SP so the real supplier's RFC is available without an extra network call. |
| `_assets/models/PetrotalReconciliationModel.php` (new) | All matching logic: parse `txtref`, normalize remision/folio/producto, query `FacturasRecibidas` for supplier and Petrotal candidates, confirm/undo rows in `FacturasMovimientosTanques`. |
| `_assets/controllers/supply.php` (modify — append methods) | 4 new public methods: view, DataTables AJAX, confirm (batch), undo. |
| `views/supply/petrotal_reconciliation.html` (new) | Filter form + DataTable, following `mis_recepciones.html` layout. |
| `_assets/js/petrotal_reconciliation.js` (new) | DataTable config, batch/individual confirm, undo, manual-select for ambiguous product matches. |
| `views/layouts/sidebar.html` (modify) | Add sidebar entry under Operaciones/Abastos guarded by `authorized(91)`. |

SQL to run directly against `TG` (permission row) is a one-time setup step, not a file — captured as Task 1.

---

## Task 1: Create the permission row and verify DB access

**Files:**
- None (direct SQL against `TG.dbo.tg_permissions`)

**Interfaces:**
- Produces: permission id `91` exists in `TG.dbo.tg_permissions`, usable by `authorized(91)` in PHP and Twig.

- [ ] **Step 1: Run the INSERT**

Run this against `TG` (e.g. via `python -c` with `pyodbc` and `CONTROLGASTG_CONN_STR` from `ApiER/api/db_connections.py`, or any MSSQL client with the `cguser`/`sahei1712` credentials already used elsewhere in this codebase):

```sql
INSERT INTO TG.dbo.tg_permissions (id, action, department, description, status, updated_at, created_at)
VALUES (91, 'read', 'Abastos', 'Ver Conciliacion Petrotal', 1, GETDATE(), GETDATE());
```

- [ ] **Step 2: Verify the row exists**

```sql
SELECT id, action, department, description, status FROM TG.dbo.tg_permissions WHERE id = 91;
```

Expected: one row, `id=91`, `department='Abastos'`, `status=1`.

- [ ] **Step 3: Confirm no user currently has permission 91**

This is expected — it's brand new. No assertion needed, just don't assign it to any user yet; that's a manual step the user (Aldo) does later via `/it/permission_users` once the feature is verified.

No commit for this task (it's a DB change, not a code change).

---

## Task 2: Add supplier RFC/name to the recepciones SP

**Files:**
- Modify: `_assets/models/MovimientosTanModel.php:4-43`

**Interfaces:**
- Produces: each row returned by `sp_obtener_recepciones_combustible($fchtrn, $codgas, $codprd)` now also has `ProveedorRfc` (string) and `ProveedorNombre` (string), both possibly empty string if the OPENQUERY join found no provider.

- [ ] **Step 1: Read the current method to confirm exact line numbers before editing**

Run: view `_assets/models/MovimientosTanModel.php` lines 1-43. Confirm the `LEFT JOIN ... [Proveedores] P ON DC.codopr = P.cod` is at line 30 and the SELECT list ends with `P.nropcc ProveedorCRE` before `FROM`.

- [ ] **Step 2: Add the two columns to the SELECT list**

Change line 25 from:

```php
	                P.nropcc ProveedorCRE
```

to:

```php
	                P.nropcc ProveedorCRE, P.rfc ProveedorRfc, P.den ProveedorNombre
```

- [ ] **Step 3: Verify PHP syntax**

Run: `php -l _assets/models/MovimientosTanModel.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Manually verify against real data (Tecnológico station, 27-28 Aug 2026)**

Run this Python snippet (uses the same `CONTROLGAS_CONN_STR` from `ApiER/api/db_connections.py` — this project doesn't have a PHP test runner, so verifying the underlying OPENQUERY shape directly against SQL Server is the fastest confirmation that the join produces the expected columns before wiring it through PHP):

```python
import pyodbc
from api.db_connections import CONTROLGAS_CONN_STR
conn = pyodbc.connect(CONTROLGAS_CONN_STR)
cur = conn.cursor()
q = """
SELECT * FROM OPENQUERY([192.168.30.101], '
    SELECT DC.codopr ProveedorId, P.rfc ProveedorRfc, P.den ProveedorNombre
    FROM CG_1163.[dbo].[MovimientosTan] M
        LEFT JOIN CG_1163.[dbo].[Documentos] D ON M.nrodoc = D.nro AND M.codgas = D.codgas AND D.tip = 1 AND D.nroitm = 1
        LEFT JOIN CG_1163.[dbo].[DocumentosC] DC ON M.nrodoc = DC.nro AND M.codgas = DC.codgas AND DC.tip = 1
        LEFT JOIN CG_1163.[dbo].[Proveedores] P ON DC.codopr = P.cod
    WHERE M.nroitm NOT IN (0,1,3,4) AND M.tiptrn = 3 AND M.fchtrn = 46260 AND M.codgas = 22
')
"""
cur.execute(q)
for row in cur.fetchall():
    print(row)
```

Expected output includes a row with `ProveedorRfc='TMS1611162N5'` (Tesoro) — this confirms the join shape is correct before trusting the PHP wrapper.

- [ ] **Step 5: Commit**

```bash
git add _assets/models/MovimientosTanModel.php
git commit -m "feat: expose supplier RFC/name in recepciones combustible SP"
```

---

## Task 3: Create `PetrotalReconciliationModel` — parsing and normalization

**Files:**
- Create: `_assets/models/PetrotalReconciliationModel.php`

**Interfaces:**
- Consumes: nothing from other tasks yet (pure parsing functions + read-only queries).
- Produces:
  - `parse_txtref(?string $txtref): ?array` — returns `['folio' => string, 'remision' => string, 'vehiculo' => string]` or `null`.
  - `buscar_factura_proveedor(string $folioRef, string $remisionRef, string $emisorRfc): ?array` — returns `['factura' => array, 'confianza' => string]` or `null`. `confianza` is one of `'exacta_remision'`, `'exacta_folio'`.
  - `buscar_facturas_petrotal(string $permisoCRE, string $fechaDesde, string $fechaHasta): array` — array of rows with keys `Id, Folio, UUID, Total, Fecha, Destino, ReceptorNombre, Producto, Litros`.
  - `filtrar_por_producto(array $facturasPetrotal, string $productoRecepcion): array`.

This task builds and unit-verifies the pure logic (parsing/normalizing) without touching the DB-dependent methods yet — those are verified in Task 4 once the model is wired to real data.

- [ ] **Step 1: Create the file with the full class**

```php
<?php
class PetrotalReconciliationModel extends Model {

    // RFC fijo de Petrotal, confirmado contra 32 facturas reales en la
    // sesión de diseño (2026-09-01). No varía por estación ni por producto.
    const PETROTAL_RFC = 'PET180213L66';

    // Quita el prefijo "RP-" (visto en Premiergas) que txtref no trae.
    private function normalizar_remision(string $r): string {
        return preg_replace('/^RP-/i', '', trim($r));
    }

    // Quita ceros de relleno tras el prefijo alfabético ("FE-041741" -> "FE-41741").
    private function normalizar_folio(string $f): string {
        $f = trim($f);
        if (preg_match('/^([A-Za-z]*-?)0*(\d+)$/', $f, $m)) {
            return $m[1] . $m[2];
        }
        return $f;
    }

    // Normaliza nombre de producto a una palabra clave comparable entre el
    // tanque de ControlGas ("T-Super Premium") y el concepto del CFDI de
    // Petrotal ("T-SUPER PREMIUM" / "MAXIMA"): el litraje puede repetirse
    // entre productos distintos de la misma recepción, así que el producto
    // es la única llave confiable para separar varias facturas Petrotal.
    private function normalizar_producto(string $nombre): string {
        $n = strtoupper(trim($nombre));
        $n = preg_replace('/^T[\.\-]\s*/', '', $n);
        $n = preg_replace('/\s+REGULAR$/', '', $n);
        $n = str_replace('-', ' ', $n);
        return trim(preg_replace('/\s+/', ' ', $n));
    }

    function parse_txtref(?string $txtref): ?array {
        if (!$txtref) return null;
        if (!preg_match('/@F:([^@]*)@R:([^@]*)@V:([^@]*)/', $txtref, $m)) return null;
        return ['folio' => trim($m[1]), 'remision' => trim($m[2]), 'vehiculo' => trim($m[3])];
    }

    // Busca la factura del proveedor real por Remision (principal) con
    // fallback a Folio, ambos normalizados. No asume 1 factura por RFC:
    // trae todas las del emisor y compara en PHP porque el volumen por
    // proveedor/estación es bajo (decenas, no miles, por rango consultado).
    function buscar_factura_proveedor(string $folioRef, string $remisionRef, string $emisorRfc): ?array {
        $remNorm = $this->normalizar_remision($remisionRef);
        $folioNorm = $this->normalizar_folio($folioRef);

        $query = "SELECT Id, Folio, Remision, EmisorNombre, EmisorRfc, Fecha, Total
                   FROM TG.dbo.FacturasRecibidas WHERE EmisorRfc = :emisorRfc";
        $rows = $this->sql->select($query, ['emisorRfc' => $emisorRfc]);

        foreach ($rows as $row) {
            if ($row['Remision'] && $this->normalizar_remision($row['Remision']) === $remNorm) {
                return ['factura' => $row, 'confianza' => 'exacta_remision'];
            }
        }
        foreach ($rows as $row) {
            if ($this->normalizar_folio($row['Folio']) === $folioNorm) {
                return ['factura' => $row, 'confianza' => 'exacta_folio'];
            }
        }
        return null;
    }

    // Facturas de Petrotal candidatas para una estación/rango, cada una ya
    // con su producto (una factura Petrotal trae un solo concepto/producto,
    // confirmado en la muestra de 32 facturas).
    function buscar_facturas_petrotal(string $permisoCRE, string $fechaDesde, string $fechaHasta): array {
        $query = "
            SELECT fr.Id, fr.Folio, fr.UUID, fr.Total, fr.Fecha, fr.Destino, fr.ReceptorNombre,
                   fc.Descripcion AS Producto, fc.Cantidad AS Litros
            FROM TG.dbo.FacturasRecibidas fr
            LEFT JOIN TG.dbo.FacturasRecibidasConceptos fc ON fc.FacturaId = fr.Id
            WHERE fr.EmisorRfc = :petrotalRfc
              AND fr.Fecha BETWEEN :fechaDesde AND :fechaHasta
              AND fr.Destino LIKE :permiso
        ";
        return $this->sql->select($query, [
            'petrotalRfc' => self::PETROTAL_RFC,
            'fechaDesde' => $fechaDesde, 'fechaHasta' => $fechaHasta,
            'permiso' => '%' . $permisoCRE . '%',
        ]);
    }

    // Si ninguna candidata coincide en producto, se devuelven todas sin
    // filtrar: mejor mostrarlas para revisión manual que ocultar una
    // factura real por un nombre de producto que no matcheó.
    function filtrar_por_producto(array $facturasPetrotal, string $productoRecepcion): array {
        $prodNorm = $this->normalizar_producto($productoRecepcion);
        $filtradas = array_values(array_filter($facturasPetrotal, function ($f) use ($prodNorm) {
            return $this->normalizar_producto($f['Producto'] ?? '') === $prodNorm;
        }));
        return $filtradas ?: $facturasPetrotal;
    }

    function ya_asignada(int $facturaId): ?array {
        $query = "SELECT Id FROM TG.dbo.FacturasMovimientosTanques
                   WHERE (FacturaProveedorId = :id1 OR FacturaPetrotalId = :id2) AND Activo = 1";
        $r = $this->sql->select($query, ['id1' => $facturaId, 'id2' => $facturaId]);
        return $r[0] ?? null;
    }

    function confirmar_asignacion(int $nrotrn, int $codgas, int $facturaProveedorId, int $facturaPetrotalId, string $usuario): void {
        $query = "
            INSERT INTO TG.dbo.FacturasMovimientosTanques
                (nrotrn, codgas, TipoOperacion, FacturaProveedorId, FacturaPetrotalId,
                 FechaAsignacion, UsuarioAsignacion, Activo, Petrotal)
            VALUES (:nrotrn, :codgas, 2, :facturaProveedorId, :facturaPetrotalId,
                    GETDATE(), :usuario, 1, 1)
        ";
        $this->sql->insert($query, compact('nrotrn', 'codgas', 'facturaProveedorId', 'facturaPetrotalId', 'usuario'));
    }

    function deshacer_asignacion(int $id, string $usuario): void {
        $query = "UPDATE TG.dbo.FacturasMovimientosTanques
                   SET Activo = 0,
                       Observaciones = CONCAT(ISNULL(Observaciones,''), ' [Deshecho por ', :usuario, ' ', CONVERT(varchar, GETDATE(), 120), ']')
                   WHERE Id = :id";
        $this->sql->update($query, compact('id', 'usuario'));
    }
}
```

- [ ] **Step 2: Verify PHP syntax**

Run: `php -l _assets/models/PetrotalReconciliationModel.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Verify the private normalization/parsing logic with a standalone script**

Since there's no test framework, write a throwaway verification script (not committed) to exercise the private methods via reflection, matching the exact real-data cases validated during design:

```php
<?php
// scratch verification, not committed
require_once '_assets/models/Model.php';
require_once '_assets/classes/common/MySqlPdoHandler.class.php';
require_once '_assets/models/PetrotalReconciliationModel.php';

$model = new PetrotalReconciliationModel();
$ref = new ReflectionClass($model);

function call_private($model, $ref, $method, ...$args) {
    $m = $ref->getMethod($method);
    $m->setAccessible(true);
    return $m->invoke($model, ...$args);
}

// Casos reales validados en la sesión de diseño.
assert(call_private($model, $ref, 'normalizar_remision', 'RP-1239754') === '1239754');
assert(call_private($model, $ref, 'normalizar_remision', '451299544') === '451299544');
assert(call_private($model, $ref, 'normalizar_folio', 'FE-041741') === 'FE-41741');
assert(call_private($model, $ref, 'normalizar_folio', '028800289944') === '028800289944');
assert(call_private($model, $ref, 'normalizar_producto', 'T-Super Premium') === 'SUPER PREMIUM');
assert(call_private($model, $ref, 'normalizar_producto', 'T-SUPER PREMIUM') === 'SUPER PREMIUM');
assert(call_private($model, $ref, 'normalizar_producto', 'MAXIMA') === 'MAXIMA');
assert(call_private($model, $ref, 'normalizar_producto', 'T-Maxima Regular') === 'MAXIMA');

$parsed = $model->parse_txtref('@F:FE-41741@R:1239032@V:FZN5287');
assert($parsed === ['folio' => 'FE-41741', 'remision' => '1239032', 'vehiculo' => 'FZN5287']);
assert($model->parse_txtref(null) === null);
assert($model->parse_txtref('sin formato') === null);

echo "All parsing/normalization assertions passed.\n";
```

Run: `php this_scratch_script.php` (requires `MySqlPdoHandler` to be constructible without an active connection for these pure-function asserts — if the constructor requires a live DB connection, run this from a context where `_assets/classes/header.class.php` has already been included, same as any controller entry point).

Expected: `All parsing/normalization assertions passed.` with no assertion failures. Delete the scratch script when done — it is not part of the deliverable.

- [ ] **Step 4: Commit**

```bash
git add _assets/models/PetrotalReconciliationModel.php
git commit -m "feat: add PetrotalReconciliationModel with txtref parsing and normalization"
```

---

## Task 4: Verify the DB-backed methods against real data

**Files:**
- None (verification only, using the model created in Task 3)

**Interfaces:**
- Consumes: `PetrotalReconciliationModel::buscar_factura_proveedor`, `buscar_facturas_petrotal`, `filtrar_por_producto` from Task 3.

- [ ] **Step 1: Write a scratch PHP script that exercises the real DB queries**

```php
<?php
// scratch verification, not committed — run from a context with an active
// MySqlPdoHandler connection to TG (e.g. copy into a temp controller method,
// or run via the built-in PHP server route during manual testing).
require_once '_assets/classes/header.class.php'; // sets up DB config/session bootstrap
require_once '_assets/models/PetrotalReconciliationModel.php';

$model = new PetrotalReconciliationModel();

// Caso Tesoro (estación Tecnológico, recepción 27-ago-2026 fchtrn=46260,
// txtref real: @F:02-8800292059@R:451301503@V:TQ371 — validado en diseño
// que la factura de Tesoro con Remision=451301503 puede no existir aún;
// usar en su lugar un caso con match confirmado: Folio=028800289944,
// Remision=451299544, EmisorRfc=TMS1611162N5).
$match = $model->buscar_factura_proveedor('02-8800289944', '451299544', 'TMS1611162N5');
var_dump($match);
// Esperado: ['factura' => [...Id, Folio='028800289944', Remision='451299544'...], 'confianza' => 'exacta_remision']

// Caso Premiergas con normalización de prefijo/ceros (Folio 'FE-041741',
// Remision 'RP-1239032', vs txtref @F:FE-41741@R:1239032).
$match2 = $model->buscar_factura_proveedor('FE-41741', '1239032', 'PRE190706416');
var_dump($match2);
// Esperado: ['factura' => [...Id=68998, Folio='FE-041741'...], 'confianza' => 'exacta_remision']

// Caso Petrotal por Destino/PermisoCRE (estación Tecnológico).
$facturas = $model->buscar_facturas_petrotal('PL/9444/EXP/ES/2015', '2026-08-25', '2026-08-29');
var_dump($facturas);
// Esperado: 2 filas, Folio PET31506 (Producto contiene SUPER PREMIUM) y PET31507 (Producto MAXIMA)

$filtradasMaxima = $model->filtrar_por_producto($facturas, 'T-Maxima Regular 1');
var_dump(array_column($filtradasMaxima, 'Folio'));
// Esperado: ['PET31507'] (solo la de Maxima, no la de Super Premium)
```

- [ ] **Step 2: Run it and confirm each `var_dump` matches the documented expectation**

Run: `php scratch_verify_reconciliation.php` (or equivalent route-based execution if `header.class.php` requires web context — check how other one-off verification scripts in this project are run, e.g. the pattern used for `main.py`/backfill scripts, or simply add a temporary debug route in `supply.php` that echoes `var_dump` and hit it via browser/curl, then remove it).

Expected: all three assertions match as commented. If `buscar_factura_proveedor` for the Tesoro case returns `null`, re-check the exact `Folio`/`Remision` values are still current in `TG.dbo.FacturasRecibidas` (data may have shifted since design-time validation) and adjust the scratch script's inputs to a currently-valid pair before concluding the model is broken.

- [ ] **Step 3: Delete the scratch script**

This step has no commit — it's pure verification, not a deliverable.

---

## Task 5: Add controller methods to `Supply`

**Files:**
- Modify: `_assets/controllers/supply.php` (append new methods; do not touch existing ones)

**Interfaces:**
- Consumes: `PetrotalReconciliationModel` (Task 3), `MovimientosTanModel::sp_obtener_recepciones_combustible` (Task 2, now returning `ProveedorRfc`/`ProveedorNombre`), `GasolinerasModel::get_active_stations()` (already used elsewhere in `supply.php`), `json_output()` (global helper, already used throughout the codebase), `authorized()` (global helper).
- Produces: routes `/supply/petrotal_reconciliation`, `/supply/datatables_petrotal_reconciliation`, `/supply/confirmar_asignacion_petrotal`, `/supply/deshacer_asignacion_petrotal`.

- [ ] **Step 1: Add the model property and instantiate it in the constructor**

Find the constructor block in `supply.php` (near line 63-64, alongside `$this->gasolinerasModel = new GasolinerasModel;`) and add:

```php
public PetrotalReconciliationModel $petrotalReconciliationModel;
```

as a class property (next to `public GasolinerasModel $gasolinerasModel;`), and in the constructor body:

```php
$this->petrotalReconciliationModel = new PetrotalReconciliationModel;
```

- [ ] **Step 2: Add the 4 new public methods at the end of the class, before the closing `}`**

```php
    public function petrotal_reconciliation()
    {
        if (!authorized(91)) {
            echo "No autorizado";
            return;
        }
        $stations = $this->gasolinerasModel->get_active_stations();
        echo $this->twig->render($this->route . 'petrotal_reconciliation.html', compact('stations'));
    }

    public function datatables_petrotal_reconciliation()
    {
        header('Content-Type: application/json');

        if (!authorized(91)) {
            json_output(['data' => [], 'error' => 'No autorizado']);
            return;
        }

        $codgas = (int)($_POST['codgas'] ?? 0);
        $fechaDesde = $_POST['fecha_desde'] ?? date('Y-m-d');
        $fechaHasta = $_POST['fecha_hasta'] ?? date('Y-m-d');

        if (!$codgas) {
            json_output(['data' => [], 'error' => 'Selecciona una estación']);
            return;
        }

        $estacion = $this->gasolinerasModel->get_by_codgas($codgas);
        if (!$estacion || empty($estacion['PermisoCRE'])) {
            json_output(['data' => [], 'error' => 'Estación sin permiso CRE configurado']);
            return;
        }

        $filas = [];
        $fecha = strtotime($fechaDesde);
        $fin = strtotime($fechaHasta);

        while ($fecha <= $fin) {
            $fechaStr = date('Y-m-d', $fecha);
            $recepciones = $this->movimientosTanModel->sp_obtener_recepciones_combustible($fechaStr, $codgas, 0);

            foreach ($recepciones as $r) {
                $ref = $this->petrotalReconciliationModel->parse_txtref($r['txtref'] ?? null);
                if (!$ref || empty($r['ProveedorRfc'])) continue;

                $matchProveedor = $this->petrotalReconciliationModel->buscar_factura_proveedor(
                    $ref['folio'], $ref['remision'], $r['ProveedorRfc']
                );

                $facturasPetrotalCandidatas = $this->petrotalReconciliationModel->buscar_facturas_petrotal(
                    $estacion['PermisoCRE'], $fechaDesde, $fechaHasta
                );
                $facturasPetrotal = $this->petrotalReconciliationModel->filtrar_por_producto(
                    $facturasPetrotalCandidatas, $r['den'] ?? ''
                );

                $asignacionExistente = null;
                if ($matchProveedor) {
                    $asignacionExistente = $this->petrotalReconciliationModel->ya_asignada($matchProveedor['factura']['Id']);
                }

                $filas[] = [
                    'nrotrn' => (int)$r['nrotrn'],
                    'codgas' => $codgas,
                    'fecha' => $fechaStr,
                    'producto' => $r['den'] ?? '',
                    'litros' => $r['VolumenRecibido'] ?? 0,
                    'factura_proveedor' => $matchProveedor['factura'] ?? null,
                    'confianza' => $matchProveedor['confianza'] ?? null,
                    'facturas_petrotal' => $facturasPetrotal,
                    'ya_asignada' => $asignacionExistente !== null,
                    'asignacion_id' => $asignacionExistente['Id'] ?? null,
                ];
            }
            $fecha = strtotime('+1 day', $fecha);
        }

        json_output(['data' => $filas]);
    }

    public function confirmar_asignacion_petrotal()
    {
        header('Content-Type: application/json');

        if (!authorized(91)) {
            json_output(['success' => false, 'message' => 'No autorizado']);
            return;
        }

        $body = json_decode(file_get_contents('php://input'), true);
        $pares = $body['pares'] ?? [];

        if (!is_array($pares) || empty($pares)) {
            json_output(['success' => false, 'message' => 'Sin pares para confirmar']);
            return;
        }

        $usuario = $_SESSION['tg_user']['nombre'] ?? $_SESSION['tg_user']['id'] ?? 'desconocido';
        $confirmados = [];
        $omitidos = [];

        foreach ($pares as $par) {
            $facturaProveedorId = (int)($par['factura_proveedor_id'] ?? 0);
            $facturaPetrotalId = (int)($par['factura_petrotal_id'] ?? 0);
            $nrotrn = (int)($par['nrotrn'] ?? 0);
            $codgas = (int)($par['codgas'] ?? 0);

            if (!$facturaProveedorId || !$facturaPetrotalId || !$nrotrn || !$codgas) {
                $omitidos[] = $par;
                continue;
            }

            if ($this->petrotalReconciliationModel->ya_asignada($facturaProveedorId)
                || $this->petrotalReconciliationModel->ya_asignada($facturaPetrotalId)) {
                $omitidos[] = $par;
                continue;
            }

            $this->petrotalReconciliationModel->confirmar_asignacion(
                $nrotrn, $codgas, $facturaProveedorId, $facturaPetrotalId, $usuario
            );
            $confirmados[] = $par;
        }

        json_output([
            'success' => true,
            'confirmados' => count($confirmados),
            'omitidos' => count($omitidos),
        ]);
    }

    public function deshacer_asignacion_petrotal()
    {
        header('Content-Type: application/json');

        if (!authorized(91)) {
            json_output(['success' => false, 'message' => 'No autorizado']);
            return;
        }

        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            json_output(['success' => false, 'message' => 'Falta el id']);
            return;
        }

        $usuario = $_SESSION['tg_user']['nombre'] ?? $_SESSION['tg_user']['id'] ?? 'desconocido';
        $this->petrotalReconciliationModel->deshacer_asignacion($id, $usuario);

        json_output(['success' => true]);
    }
```

**Note on `$_SESSION['tg_user']['nombre']`:** confirm the exact session key for the logged-in user's display name by checking `_assets/includes/validate.inc.php` before finalizing — if the key differs (e.g. `usuario` instead of `nombre`), use the actual key used elsewhere in `supply.php` for `UsuarioAsignacion`-style fields.

**Note on `$this->gasolinerasModel->get_by_codgas($codgas)`:** verify this method exists on `GasolinerasModel` (grep the file) before relying on it; if it doesn't exist, add it following the same pattern as `get_active_stations()`, returning at minimum `PermisoCRE` for the given `codgas` from `TG.dbo.Estaciones`.

- [ ] **Step 3: Verify PHP syntax**

Run: `php -l _assets/controllers/supply.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Manual verification — hit the DataTables endpoint directly**

With the local dev server running (user starts it themselves per project convention — do not start it), use `curl` against `http://localhost:8000/supply/datatables_petrotal_reconciliation` with a valid session cookie, `codgas=22`, `fecha_desde=2026-08-25`, `fecha_hasta=2026-08-29`. Confirm the JSON response includes rows for `nrotrn` values seen in Task 4's manual verification, with `factura_proveedor` and `facturas_petrotal` populated as expected.

- [ ] **Step 5: Commit**

```bash
git add _assets/controllers/supply.php
git commit -m "feat: add Petrotal reconciliation endpoints to Supply controller"
```

---

## Task 6: Build the view and JS

**Files:**
- Create: `views/supply/petrotal_reconciliation.html`
- Create: `_assets/js/petrotal_reconciliation.js`

**Interfaces:**
- Consumes: `/supply/datatables_petrotal_reconciliation`, `/supply/confirmar_asignacion_petrotal`, `/supply/deshacer_asignacion_petrotal` (Task 5). Twig variables `stations` (array of `{cod, abr}` from `get_active_stations()`, same shape already used in `mis_recepciones.html`).

- [ ] **Step 1: Create the view**

```twig
{% extends "views/layouts/base.html" %}
{% block title %}Conciliación Petrotal{% endblock %}
{% block mycss %}{% endblock %}
{% block menutitle %}Conciliación Petrotal{% endblock %}
{% block content %}
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Filtros</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3">
                        <label for="codgas_conciliacion" class="form-label">Estación:</label>
                        <select class="selectpicker form-control" data-live-search="true" data-size="10" data-width="100%" data-container="body" id="codgas_conciliacion">
                            {% for station in stations %}
                            <option value="{{ station.cod }}">{{ station.abr }}</option>
                            {% endfor %}
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="fecha_desde" class="form-label">Desde:</label>
                        <input type="date" class="form-control" id="fecha_desde" value="{{ now | date('Y-m-d', '-7 days') }}">
                    </div>
                    <div class="col-md-2">
                        <label for="fecha_hasta" class="form-label">Hasta:</label>
                        <input type="date" class="form-control" id="fecha_hasta" value="{{ now | date('Y-m-d') }}">
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="button" class="btn btn-primary w-100" id="btnBuscarConciliacion">
                            <i data-feather="search"></i> Buscar
                        </button>
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button type="button" class="btn btn-success w-100" id="btnConfirmarLote" disabled>
                            <i data-feather="check-square"></i> Confirmar seleccionadas
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Recepciones</h5>
            </div>
            <div class="card-body h-100 table-responsive">
                <table id="datatables_conciliacion" class="table table-sm w-100">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="checkAllExactas"></th>
                            <th>FECHA</th>
                            <th>PRODUCTO</th>
                            <th>LITROS</th>
                            <th>FACTURA PROVEEDOR</th>
                            <th>FACTURA(S) PETROTAL</th>
                            <th>CONFIANZA</th>
                            <th>ESTADO</th>
                            <th>ACCIONES</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td colspan="9" class="text-center text-muted">Selecciona una estación y un rango, luego presiona Buscar</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
{% endblock %}
{% block myjs %}
<script src="/_assets/js/petrotal_reconciliation.js"></script>
{% endblock %}
```

- [ ] **Step 2: Create the JS**

```javascript
let datatables_conciliacion = null;

function construirConfigDataTable() {
    return {
        dom: '<"top"f>rt<"bottom"lip>',
        pageLength: 100,
        ajax: {
            url: '/supply/datatables_petrotal_reconciliation',
            data: function (d) {
                d.codgas = $('#codgas_conciliacion').val();
                d.fecha_desde = $('#fecha_desde').val();
                d.fecha_hasta = $('#fecha_hasta').val();
            },
            beforeSend: function () {
                $('#datatables_conciliacion').closest('.table-responsive').addClass('loading');
                $('#btnBuscarConciliacion').prop('disabled', true);
            },
            complete: function () {
                $('#datatables_conciliacion').closest('.table-responsive').removeClass('loading');
                $('#btnBuscarConciliacion').prop('disabled', false);
                actualizarBotonLote();
            },
            error: function () {
                alertify.myAlert('<div class="text-danger text-center"><p>No se pudieron cargar las recepciones.</p></div>');
            }
        },
        deferRender: true,
        order: [[1, 'desc']],
        columns: [
            {
                data: null,
                orderable: false,
                render: function (row) {
                    if (row.ya_asignada || !row.factura_proveedor || !row.confianza || row.facturas_petrotal.length !== 1) return '';
                    return `<input type="checkbox" class="chk-confirmar" data-nrotrn="${row.nrotrn}" data-codgas="${row.codgas}"
                        data-factura-proveedor="${row.factura_proveedor.Id}" data-factura-petrotal="${row.facturas_petrotal[0].Id}">`;
                }
            },
            { data: 'fecha' },
            { data: 'producto' },
            { data: 'litros', render: $.fn.dataTable.render.number(',', '.', 2) },
            {
                data: 'factura_proveedor',
                render: function (data) {
                    if (!data) return '<span class="badge bg-warning text-dark">Sin factura aún</span>';
                    return `${data.EmisorNombre}<br><small class="text-muted">${data.Folio} · $${Number(data.Total).toLocaleString('es-MX', {minimumFractionDigits: 2})}</small>`;
                }
            },
            {
                data: 'facturas_petrotal',
                render: function (data) {
                    if (!data || !data.length) return '<span class="text-muted">—</span>';
                    return data.map(f => `${f.Folio} · $${Number(f.Total).toLocaleString('es-MX', {minimumFractionDigits: 2})}`).join('<br>');
                }
            },
            {
                data: 'confianza',
                render: function (data) {
                    if (!data) return '';
                    const map = {
                        exacta_remision: '<span class="badge bg-success">Exacta (remisión)</span>',
                        exacta_folio: '<span class="badge bg-success">Exacta (folio)</span>',
                    };
                    return map[data] || `<span class="badge bg-secondary">${data}</span>`;
                }
            },
            {
                data: 'ya_asignada',
                render: function (data) {
                    return data
                        ? '<span class="badge bg-primary">Confirmada</span>'
                        : '<span class="badge bg-light text-dark border">Pendiente</span>';
                }
            },
            {
                data: null,
                orderable: false,
                render: function (row) {
                    if (row.ya_asignada) {
                        return `<button type="button" class="btn btn-sm btn-outline-danger btn-deshacer" data-id="${row.asignacion_id}">Deshacer</button>`;
                    }
                    if (!row.factura_proveedor || !row.facturas_petrotal || !row.facturas_petrotal.length) return '';

                    if (row.facturas_petrotal.length === 1) {
                        return `<button type="button" class="btn btn-sm btn-primary btn-confirmar-una"
                            data-nrotrn="${row.nrotrn}" data-codgas="${row.codgas}"
                            data-factura-proveedor="${row.factura_proveedor.Id}" data-factura-petrotal="${row.facturas_petrotal[0].Id}">
                            Confirmar</button>`;
                    }

                    const opciones = row.facturas_petrotal.map(f =>
                        `<option value="${f.Id}">${f.Folio} · $${Number(f.Total).toLocaleString('es-MX', {minimumFractionDigits: 2})}</option>`
                    ).join('');
                    return `<div class="input-group input-group-sm">
                        <select class="form-select form-select-sm select-petrotal-manual">${opciones}</select>
                        <button type="button" class="btn btn-primary btn-confirmar-select"
                            data-nrotrn="${row.nrotrn}" data-codgas="${row.codgas}"
                            data-factura-proveedor="${row.factura_proveedor.Id}">Confirmar</button>
                    </div>`;
                }
            },
        ],
    };
}

function rangoEsValido() {
    if (!$('#codgas_conciliacion').val()) {
        alertify.myAlert('<div class="text-danger text-center"><p>Selecciona una estación.</p></div>');
        return false;
    }
    const desde = $('#fecha_desde').val();
    const hasta = $('#fecha_hasta').val();
    if (!desde || !hasta) {
        alertify.myAlert('<div class="text-danger text-center"><p>Selecciona ambas fechas.</p></div>');
        return false;
    }
    if (desde > hasta) {
        alertify.myAlert('<div class="text-danger text-center"><p>La fecha "Desde" no puede ser posterior a "Hasta".</p></div>');
        return false;
    }
    return true;
}

function actualizarBotonLote() {
    const hayExactas = $('.chk-confirmar').length > 0;
    $('#btnConfirmarLote').prop('disabled', !hayExactas);
}

$('#btnBuscarConciliacion').on('click', function () {
    if (!rangoEsValido()) return;
    if (datatables_conciliacion === null) {
        datatables_conciliacion = $('#datatables_conciliacion').DataTable(construirConfigDataTable());
    } else {
        datatables_conciliacion.ajax.reload();
    }
});

$('#checkAllExactas').on('change', function () {
    $('.chk-confirmar').prop('checked', $(this).is(':checked'));
});

async function confirmarAsignaciones(pares) {
    try {
        const response = await fetch('/supply/confirmar_asignacion_petrotal', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ pares }),
            credentials: 'include',
        });
        const result = await response.json();
        if (result.success) {
            datatables_conciliacion.ajax.reload(null, false);
        } else {
            alertify.myAlert(`<div class="text-danger text-center"><p>${result.message}</p></div>`);
        }
    } catch (error) {
        alertify.myAlert('<div class="text-danger text-center"><p>Error al confirmar la asignación.</p></div>');
    }
}

$(document).on('click', '.btn-confirmar-una', function () {
    confirmarAsignaciones([{
        nrotrn: $(this).data('nrotrn'),
        codgas: $(this).data('codgas'),
        factura_proveedor_id: $(this).data('factura-proveedor'),
        factura_petrotal_id: $(this).data('factura-petrotal'),
    }]);
});

$(document).on('click', '.btn-confirmar-select', function () {
    const facturaPetrotalId = $(this).closest('.input-group').find('.select-petrotal-manual').val();
    confirmarAsignaciones([{
        nrotrn: $(this).data('nrotrn'),
        codgas: $(this).data('codgas'),
        factura_proveedor_id: $(this).data('factura-proveedor'),
        factura_petrotal_id: facturaPetrotalId,
    }]);
});

$('#btnConfirmarLote').on('click', function () {
    const pares = $('.chk-confirmar:checked').map(function () {
        return {
            nrotrn: $(this).data('nrotrn'),
            codgas: $(this).data('codgas'),
            factura_proveedor_id: $(this).data('factura-proveedor'),
            factura_petrotal_id: $(this).data('factura-petrotal'),
        };
    }).get();
    if (!pares.length) return;
    confirmarAsignaciones(pares);
});

$(document).on('click', '.btn-deshacer', async function () {
    const id = $(this).data('id');
    try {
        const response = await fetch('/supply/deshacer_asignacion_petrotal', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `id=${id}`,
            credentials: 'include',
        });
        const result = await response.json();
        if (result.success) {
            datatables_conciliacion.ajax.reload(null, false);
        } else {
            alertify.myAlert(`<div class="text-danger text-center"><p>${result.message}</p></div>`);
        }
    } catch (error) {
        alertify.myAlert('<div class="text-danger text-center"><p>Error al deshacer la asignación.</p></div>');
    }
});
```

- [ ] **Step 3: Verify Twig syntax by loading the page**

With the local dev server running (user-managed), navigate to `/supply/petrotal_reconciliation` while logged in as a user with permission 91 assigned. Confirm the page renders without Twig errors, the station selector populates, and no JS console errors appear on load.

- [ ] **Step 4: Manual end-to-end check**

Select estación "Tecnológico" (or whichever station code corresponds — check via `TG.dbo.Estaciones` for `Estacion='22 TECNOLOGICO'` or similar `abr`), set the date range to `2026-08-25`–`2026-08-29`, click Buscar. Confirm rows appear with a "Confirmar" button on the row matching `PET31507`/Tesoro-or-relevant-supplier, click it, confirm the row updates to "Confirmada" with a "Deshacer" button, click Deshacer, confirm it reverts to "Pendiente".

- [ ] **Step 5: Commit**

```bash
git add views/supply/petrotal_reconciliation.html _assets/js/petrotal_reconciliation.js
git commit -m "feat: add Petrotal reconciliation view and JS"
```

---

## Task 7: Wire up the sidebar entry

**Files:**
- Modify: `views/layouts/sidebar.html`

**Interfaces:**
- Consumes: permission id `91` (Task 1).

- [ ] **Step 1: Find the Abastos/Operaciones section and add the entry**

Locate the sidebar section containing Abastos-related links (search for `authorized(19)` — the Operaciones section header, or search for other supply-related entries like `/supply/`) and add, guarded by the new permission:

```twig
{% if authorized(91) %}
<li class="sidebar-item">
  <a class="sidebar-link" href="/supply/petrotal_reconciliation">
    <i data-feather="link"></i>
    <span class="align-middle">Conciliación Petrotal</span>
  </a>
</li>
{% endif %}
```

Place it near other Abastos-department links if a dedicated Abastos subsection exists; otherwise place it under the Operaciones section alongside `/supply/` entries, following whatever grouping convention the surrounding `{% if authorized(N) %}` blocks already use.

- [ ] **Step 2: Verify Twig syntax**

Load any page as a logged-in user (the sidebar renders on every page) and confirm no Twig error appears. As the test user won't yet have permission 91 (not assigned to anyone per Task 1), the link should NOT appear — confirm this too, since a link showing for everyone would indicate the `{% if %}` guard is broken.

- [ ] **Step 3: Commit**

```bash
git add views/layouts/sidebar.html
git commit -m "feat: add sidebar entry for Petrotal reconciliation"
```

---

## Final Self-Review Checklist (for whoever executes this plan)

- [ ] Permission 91 exists in `TG.dbo.tg_permissions` and is NOT auto-assigned to any user (that's a manual step for Aldo after verification).
- [ ] `FacturasMovimientosTanques` schema was never altered — only rows were inserted via `confirmar_asignacion`.
- [ ] The old `fuel_reconciliation`/`buscar_facturas_proveedor`/`buscar_facturas_petrotal`/`guardar_asignacion_completa` methods in `supply.php` are untouched and still unlinked from the sidebar.
- [ ] Every new SQL query uses `:paramName` placeholders via `$this->sql->select/insert/update($query, $params)` — no string concatenation of user input anywhere in `PetrotalReconciliationModel.php` or the new controller methods.
- [ ] `php -l` passes on all 3 modified/created PHP files.
- [ ] The two real-data cases from the design session (Tesoro exact-remision match, Premiergas normalized match) both resolve correctly through the live endpoint, not just the standalone scratch script.
