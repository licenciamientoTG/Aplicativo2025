# Portal de Estaciones — Vista "Mis Recepciones" Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Dar de alta la vista "Mis Recepciones" dentro de Operaciones, donde una estación ve sus recepciones de combustible de un día y sube/consulta/elimina (soft-delete) escaneos de remisión por recepción.

**Architecture:** Controlador nuevo `station_portal.php` (autoload por convención de nombre de archivo/clase, sin tocar `index.php`) + modelo nuevo `RecepcionRemisionesModel.php` (tabla nueva `TG.dbo.recepcion_remisiones`, soft-delete) + reutiliza `MovimientosTanModel::sp_obtener_recepciones_combustible` (ajustado para aceptar `codprd=0` = todos los productos) + 3 permisos nuevos vía el catálogo existente `tg_permissions`/`tg_permissions_users` + entrada de sidebar dentro de la sección Operaciones.

**Tech Stack:** PHP 8 (MVC propio), PDO/sqlsrv contra `TG` y `SG12`/estaciones vía linked server, Twig 3, jQuery + DataTables (server-side AJAX), Bootstrap Material Design.

## Global Constraints

- Toda tabla/columna nueva vive en `TG` — `SG12` y las BD de estación son SOLO LECTURA (spec, principios).
- Subida de archivos: máx. 10 MB, extensiones `pdf|jpg|jpeg|png` — mismos límites que `PaymentTransactionDocumentsModel` (spec, sección Modelo).
- El archivo físico de una remisión **nunca se borra** al hacer soft-delete (spec, sección Tabla nueva).
- `authorized($id)` siempre recibe un **id numérico** de `tg_permissions.id` — no hay slug/nombre en el catálogo (`PermissionsModel.php:2-9`, columnas `action`/`department`/`description`, sin columna `name`). Los nombres `ver_mis_recepciones` / `recepciones_todas_estaciones` / `recepciones_eliminar_remision` del spec son etiquetas de trabajo; en código se usan los ids reales asignados en Task 1.
- Regla de acceso estricta: si el usuario no tiene el permiso "todas las estaciones" Y no tiene `IdEstacion` en `$_SESSION['tg_user']`, se niega el acceso — nunca se defaultea a "ver todo" por ausencia de estación (spec, sección Permisos nuevos; distinto al patrón viejo de `operations.php:107`).
- `codgas` recibido del cliente en cualquier endpoint solo se respeta si el usuario tiene el permiso "todas las estaciones"; si no, se ignora y se fuerza siempre el de sesión (spec, sección Controlador).

---

## Task 1: Catálogo de permisos + tabla `recepcion_remisiones`

**Files:**
- Create: `docs/superpowers/plans/sql/2026-08-09-recepcion-remisiones.sql` (script de referencia, no se ejecuta por el engineer vía código — se corre a mano contra `TG` y se documenta aquí para trazabilidad)

**Interfaces:**
- Produces: tabla `[TG].[dbo].[recepcion_remisiones]` con columnas `id, nrotrn, codgas, fchtrn, file_path, file_extension, original_filename, file_size, created_by, created_at, is_deleted, deleted_at, deleted_by`. Produce también 3 filas nuevas en `[TG].[dbo].[tg_permissions]` cuyos `id` reales se usan literalmente en todas las tareas siguientes (Task 4, 6, 7, 8).

- [ ] **Step 1: Escribir el script SQL de la tabla nueva**

```sql
-- docs/superpowers/plans/sql/2026-08-09-recepcion-remisiones.sql
CREATE TABLE [TG].[dbo].[recepcion_remisiones] (
    id                 INT IDENTITY(1,1) PRIMARY KEY,
    nrotrn             INT NOT NULL,
    codgas             INT NOT NULL,
    fchtrn             INT NOT NULL,
    file_path          VARCHAR(500) NOT NULL,
    file_extension     VARCHAR(10) NOT NULL,
    original_filename  VARCHAR(255) NOT NULL,
    file_size          INT NOT NULL,
    created_by         INT NOT NULL,
    created_at         DATETIME NOT NULL DEFAULT GETDATE(),
    is_deleted         BIT NOT NULL DEFAULT 0,
    deleted_at         DATETIME NULL,
    deleted_by         INT NULL
);
GO

CREATE INDEX IX_recepcion_remisiones_recepcion
    ON [TG].[dbo].[recepcion_remisiones] (codgas, fchtrn, nrotrn)
    WHERE is_deleted = 0;
GO
```

- [ ] **Step 2: Ejecutar el script contra `TG`**

Correr el script de Step 1 contra la base `TG` (mismo mecanismo que ya usa el proyecto para migraciones ad-hoc — no hay runner de migraciones, se ejecuta manualmente vía SSMS o la herramienta que ya use el equipo). Confirmar con:

```sql
SELECT * FROM [TG].[dbo].[recepcion_remisiones];
-- Expected: 0 filas, sin error (tabla existe y está vacía)
```

- [ ] **Step 3: Dar de alta los 3 permisos nuevos**

Insertar usando el mismo patrón que `PermissionsModel::add()` (`_assets/models/PermissionsModel.php:33-36`):

```sql
INSERT INTO [TG].[dbo].[tg_permissions] ([action],[department],[description],[status],[updated_at],[created_at])
VALUES ('read', 'Operaciones', 'Ver Mis Recepciones (portal estaciones)', 1, GETDATE(), GETDATE());

INSERT INTO [TG].[dbo].[tg_permissions] ([action],[department],[description],[status],[updated_at],[created_at])
VALUES ('read', 'Operaciones', 'Mis Recepciones: ver todas las estaciones', 1, GETDATE(), GETDATE());

INSERT INTO [TG].[dbo].[tg_permissions] ([action],[department],[description],[status],[updated_at],[created_at])
VALUES ('delete', 'Operaciones', 'Mis Recepciones: eliminar remisión', 1, GETDATE(), GETDATE());
```

(Alternativa equivalente: darlos de alta desde la UI en `/it/permissions`, que llama al mismo `PermissionsModel::add()` — cualquiera de las dos vías es válida, el resultado es el mismo insert.)

- [ ] **Step 4: Capturar los 3 ids generados**

```sql
SELECT id, description FROM [TG].[dbo].[tg_permissions]
WHERE description LIKE 'Mis Recepciones%' OR description LIKE 'Ver Mis Recepciones%'
ORDER BY id;
```

Anotar los 3 ids resultantes (ejemplo de nomenclatura para el resto del plan: `PERM_VER`, `PERM_TODAS_ESTACIONES`, `PERM_ELIMINAR`). **Estos ids reemplazan literalmente los placeholders `PERM_VER` / `PERM_TODAS_ESTACIONES` / `PERM_ELIMINAR` en todas las tareas siguientes** — antes de escribir código en Task 4/6/7/8, sustituir por el entero real.

- [ ] **Step 5: Asignar el permiso `PERM_VER` a un usuario de prueba**

Vía `/it/permission_users/{user_id}` (UI existente, `_assets/controllers/it.php:420-422` + `450-453`) o directo:

```sql
INSERT INTO [TG].[dbo].[tg_permissions_users] (user_id, permission_id)
VALUES (?, ?); -- ? = id de un usuario de prueba, PERM_VER
```

Esto es necesario para poder probar manualmente Task 4 en adelante.

---

## Task 2: `RecepcionRemisionesModel` — upload, listado, conteos, soft-delete

**Files:**
- Create: `_assets/models/RecepcionRemisionesModel.php`

**Interfaces:**
- Consumes: `Model::$sql` (heredado, `_assets/models/Model.php:2-9`, singleton `MySqlPdoHandler`).
- Produces:
  - `upload(int $nrotrn, int $codgas, int $fchtrn, array $file, int $user_id): array` → `['success' => bool, 'doc_id'?: int, 'message': string]`
  - `get_by_recepcion(int $nrotrn, int $codgas, int $fchtrn): array` → filas activas con columnas `id, original_filename, file_path, file_extension, file_size, created_at, created_by`
  - `get_counts_by_day(int $codgas, int $fchtrn): array` → `[nrotrn => count]`
  - `soft_delete(int $id, int $user_id): array` → `['success' => bool, 'message' => string]`

- [ ] **Step 1: Crear el modelo con `upload()`, calcando `PaymentTransactionDocumentsModel::upload()` (`_assets/models/PaymentTransactionDocumentsModel.php:78-127`)**

```php
<?php
class RecepcionRemisionesModel extends Model
{
    const UPLOAD_BASE = '_assets/uploads/recepcion_remisiones/';
    const MAX_SIZE    = 10 * 1024 * 1024; // 10 MB
    const ALLOWED_EXT = ['pdf', 'jpg', 'jpeg', 'png'];

    public function upload(int $nrotrn, int $codgas, int $fchtrn, array $file, int $user_id): array
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'message' => 'Error al recibir el archivo'];
        }

        if ($file['size'] > self::MAX_SIZE) {
            return ['success' => false, 'message' => 'El archivo excede el tamaño máximo de 10 MB'];
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXT)) {
            return ['success' => false, 'message' => 'Tipo de archivo no permitido. Use: PDF, JPG, PNG'];
        }

        $doc_id = $this->sql->insert(
            "INSERT INTO [TG].[dbo].[recepcion_remisiones]
                (nrotrn, codgas, fchtrn, file_path, file_extension, original_filename, file_size, created_by)
             VALUES (?, ?, ?, '', ?, ?, ?, ?)",
            [$nrotrn, $codgas, $fchtrn, $ext, $file['name'], $file['size'], $user_id]
        );

        if (!$doc_id) {
            return ['success' => false, 'message' => 'Error al registrar la remisión en BD'];
        }

        $subdir = self::UPLOAD_BASE . date('Y') . '/' . date('m') . '/';
        $fullDir = __DIR__ . '/../../' . $subdir;
        if (!is_dir($fullDir)) {
            mkdir($fullDir, 0755, true);
        }

        $filename   = $doc_id . '.' . $ext;
        $fullPath   = $fullDir . $filename;
        $storedPath = $subdir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
            $this->sql->delete("DELETE FROM [TG].[dbo].[recepcion_remisiones] WHERE id = ?", [$doc_id]);
            return ['success' => false, 'message' => 'Error al guardar el archivo en disco'];
        }

        $this->sql->update(
            "UPDATE [TG].[dbo].[recepcion_remisiones] SET file_path = ? WHERE id = ?",
            [$storedPath, $doc_id]
        );

        return ['success' => true, 'doc_id' => $doc_id, 'message' => 'Remisión subida correctamente'];
    }
}
```

- [ ] **Step 2: Agregar `get_by_recepcion()`**

```php
    public function get_by_recepcion(int $nrotrn, int $codgas, int $fchtrn): array
    {
        $query = "
            SELECT id, original_filename, file_path, file_extension, file_size, created_at, created_by
            FROM [TG].[dbo].[recepcion_remisiones]
            WHERE nrotrn = ? AND codgas = ? AND fchtrn = ? AND is_deleted = 0
            ORDER BY created_at ASC
        ";
        return $this->sql->select($query, [$nrotrn, $codgas, $fchtrn]) ?: [];
    }
```

- [ ] **Step 3: Agregar `get_counts_by_day()`**

```php
    public function get_counts_by_day(int $codgas, int $fchtrn): array
    {
        $query = "
            SELECT nrotrn, COUNT(*) AS total
            FROM [TG].[dbo].[recepcion_remisiones]
            WHERE codgas = ? AND fchtrn = ? AND is_deleted = 0
            GROUP BY nrotrn
        ";
        $rows = $this->sql->select($query, [$codgas, $fchtrn]) ?: [];

        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r['nrotrn']] = (int)$r['total'];
        }
        return $out;
    }
```

- [ ] **Step 4: Agregar `soft_delete()`**

```php
    public function soft_delete(int $id, int $user_id): array
    {
        $existing = $this->sql->select(
            "SELECT id FROM [TG].[dbo].[recepcion_remisiones] WHERE id = ? AND is_deleted = 0",
            [$id]
        );

        if (!$existing) {
            return ['success' => false, 'message' => 'La remisión no existe o ya fue eliminada'];
        }

        $this->sql->update(
            "UPDATE [TG].[dbo].[recepcion_remisiones]
             SET is_deleted = 1, deleted_at = GETDATE(), deleted_by = ?
             WHERE id = ?",
            [$user_id, $id]
        );

        return ['success' => true, 'message' => 'Remisión eliminada correctamente'];
    }
}
```

(Nota: el `}` de cierre de clase se mueve al final de este último método — verificar que solo hay un `}` de cierre de clase en el archivo final.)

- [ ] **Step 5: Verificación manual del modelo**

No hay framework de tests en el proyecto (confirmado en `CLAUDE.md`). Verificar sintaxis PHP:

```bash
php -l _assets/models/RecepcionRemisionesModel.php
```

Expected: `No syntax errors detected`

- [ ] **Step 6: Commit**

```bash
git add _assets/models/RecepcionRemisionesModel.php
git commit -m "feat: agrega RecepcionRemisionesModel para portal de estaciones"
```

---

## Task 3: Ajustar `sp_obtener_recepciones_combustible` para aceptar "todos los productos"

**Files:**
- Modify: `_assets/models/MovimientosTanModel.php:4-43`

**Interfaces:**
- Consumes: nada nuevo.
- Produces: `sp_obtener_recepciones_combustible($fchtrn, $codgas, $codprd)` — mismo nombre y misma firma; `$codprd = 0` ahora significa "todos los productos" en vez de filtrar por un producto inexistente (cod 0). Sigue siendo compatible con el único llamador existente (`_assets/controllers/supply.php:648`, que siempre pasa un `controlGasProductId` real, nunca 0).

- [ ] **Step 1: Confirmar que no hay otros llamadores antes de tocar el SQL**

```bash
grep -rn "sp_obtener_recepciones_combustible" _assets/
```

Expected: solo `_assets/models/MovimientosTanModel.php:4` (definición) y `_assets/controllers/supply.php:648` (único llamador, pasa `$item['controlGasProductId']` — nunca 0/null). Si aparece algún otro llamador no documentado, detenerse y evaluar impacto antes de continuar.

- [ ] **Step 2: Modificar el filtro de producto en el SQL**

En `_assets/models/MovimientosTanModel.php`, línea 36, cambiar:

```php
                    AND T.codprd = " . $params['codprd'] . "
```

por:

```php
                    AND (" . $params['codprd'] . " = 0 OR T.codprd = " . $params['codprd'] . ")
```

- [ ] **Step 3: Verificación manual**

```bash
php -l _assets/models/MovimientosTanModel.php
```

Expected: `No syntax errors detected`

Prueba funcional (requiere acceso a una estación real): llamar `sp_obtener_recepciones_combustible($fecha_con_recepciones, $codgas_real, 0)` desde un script ad-hoc o vía la vista de Task 6 una vez lista, y confirmar que trae recepciones de más de un producto el mismo día (si la estación tuvo más de un producto recibido ese día).

- [ ] **Step 4: Commit**

```bash
git add _assets/models/MovimientosTanModel.php
git commit -m "fix: sp_obtener_recepciones_combustible acepta codprd=0 como todos los productos"
```

---

## Task 4: Controlador `station_portal.php` — vista y datatable de recepciones

**Files:**
- Create: `_assets/controllers/station_portal.php`

**Interfaces:**
- Consumes: `MovimientosTanModel::sp_obtener_recepciones_combustible($fchtrn, $codgas, $codprd)` (Task 3), `RecepcionRemisionesModel::get_counts_by_day(int $codgas, int $fchtrn): array` (Task 2), `GasolinerasModel::get_active_stations(): array|false` (`_assets/models/GasolinerasModel.php:105-108`), `dateToInt($date): int` (`_assets/classes/php_functions.php:319-327`), `authorized($permission_id): bool` (`_assets/classes/php_functions.php:359-361`), `json_output($json)` (`_assets/classes/php_functions.php:22`).
- Produces: clase `station_portal` con métodos `mis_recepciones(): void` y `datatables_recepciones(): void`, rutas `/station_portal/mis_recepciones` y `/station_portal/datatables_recepciones` (routing automático por convención de nombre de archivo/clase, `index.php:98-109` — no requiere cambios en `index.php`).

- [ ] **Step 1: Crear el esqueleto del controlador con el guard de acceso**

Sustituir los valores `101`/`102`/`103` de las constantes de clase por los ids reales capturados en Task 1 Step 4 (son placeholders de ejemplo, no ids garantizados — el autoincremental de `tg_permissions` depende de cuántas filas existan ya en esa tabla).

```php
<?php
class station_portal
{
    public $twig;
    public $route;
    public MovimientosTanModel $movimientosTanModel;
    public RecepcionRemisionesModel $recepcionRemisionesModel;
    public GasolinerasModel $gasolinerasModel;

    const PERM_VER              = 101; // reemplazar por el id real de Task 1 Step 4 (ver: permiso "Ver Mis Recepciones")
    const PERM_TODAS_ESTACIONES = 102; // reemplazar por el id real de Task 1 Step 4 (ver: "todas las estaciones")
    const PERM_ELIMINAR         = 103; // reemplazar por el id real de Task 1 Step 4 (delete: "eliminar remisión")

    public function __construct($twig)
    {
        $this->twig = $twig;
        $this->route = 'views/station_portal/';
        $this->movimientosTanModel = new MovimientosTanModel();
        $this->recepcionRemisionesModel = new RecepcionRemisionesModel();
        $this->gasolinerasModel = new GasolinerasModel();
    }

    /**
     * Estación efectiva para el usuario en sesión: si tiene el permiso de
     * "todas las estaciones" y mandó un codgas válido por request, se respeta;
     * si no, siempre se fuerza la IdEstacion de sesión. Devuelve null si el
     * usuario no puede resolver ninguna estación (sin permiso y sin IdEstacion).
     */
    private function resolveCodgas(): ?int
    {
        $hasIdEstacion = isset($_SESSION['tg_user']['IdEstacion']) && (int)$_SESSION['tg_user']['IdEstacion'] > 0;

        if (authorized(self::PERM_TODAS_ESTACIONES)) {
            if (isset($_REQUEST['codgas']) && (int)$_REQUEST['codgas'] > 0) {
                return (int)$_REQUEST['codgas'];
            }
            return $hasIdEstacion ? (int)$_SESSION['tg_user']['IdEstacion'] : 0; // 0 = todas
        }

        return $hasIdEstacion ? (int)$_SESSION['tg_user']['IdEstacion'] : null;
    }

    public function mis_recepciones(): void
    {
        if (!authorized(self::PERM_VER)) {
            header('Location: /home/index');
            exit;
        }

        $codgas = $this->resolveCodgas();
        if ($codgas === null) {
            setFlashMessage('danger', 'Tu usuario no tiene una estación asignada.');
            header('Location: /home/index');
            exit;
        }

        $showStationSelect = authorized(self::PERM_TODAS_ESTACIONES);
        $stations = $showStationSelect ? $this->gasolinerasModel->get_active_stations() : [];
        $canDelete = authorized(self::PERM_ELIMINAR);

        echo $this->twig->render($this->route . 'mis_recepciones.html', compact('stations', 'showStationSelect', 'canDelete'));
    }
}
```

- [ ] **Step 2: Verificación manual de sintaxis**

```bash
php -l _assets/controllers/station_portal.php
```

Expected: `No syntax errors detected`

- [ ] **Step 3: Agregar `datatables_recepciones()`**

```php
    public function datatables_recepciones(): void
    {
        if (!authorized(self::PERM_VER)) {
            json_output(['data' => [], 'error' => 'No autorizado']);
            return;
        }

        $codgas = $this->resolveCodgas();
        if ($codgas === null) {
            json_output(['data' => [], 'error' => 'Sin estación asignada']);
            return;
        }

        $fecha = $_REQUEST['fecha'] ?? date('Y-m-d');
        $fchtrn = dateToInt($fecha);

        $recepciones = $this->movimientosTanModel->sp_obtener_recepciones_combustible($fecha, $codgas, 0) ?: [];
        $counts = $this->recepcionRemisionesModel->get_counts_by_day($codgas, $fchtrn);

        $data = array_map(function ($r) use ($counts) {
            $nrotrn = (int)$r['nrotrn'];
            $totalRemisiones = $counts[$nrotrn] ?? 0;

            return [
                'nrotrn'          => $nrotrn,
                'codgas'          => (int)$r['codgas'],
                'fchtrn'          => (int)$r['fchtrn'],
                'hora'            => $r['hora'],
                'producto'        => $r['den'],
                'volumen'         => $r['VolumenRecibido'],
                'total_remisiones'=> $totalRemisiones,
            ];
        }, $recepciones);

        json_output(['data' => $data]);
    }
}
```

(El `}` final de este step reemplaza el `}` de cierre de clase puesto provisionalmente en Step 1 — un solo cierre de clase al final del archivo.)

- [ ] **Step 4: Verificación manual**

```bash
php -l _assets/controllers/station_portal.php
```

Expected: `No syntax errors detected`

- [ ] **Step 5: Commit**

```bash
git add _assets/controllers/station_portal.php
git commit -m "feat: controlador station_portal con vista y datatable de Mis Recepciones"
```

---

## Task 5: Vista `mis_recepciones.html` + JS del datatable

**Files:**
- Create: `views/station_portal/mis_recepciones.html`
- Create: `_assets/js/station_portal.js`

**Interfaces:**
- Consumes: `showStationSelect: bool`, `stations: array` (filas `cod, abr, den, dom, col` de `GasolinerasModel::get_active_stations`), `canDelete: bool` (pasados por Task 4 Step 1), `tg_user['IdEstacion']` (global Twig, `index.php:49-51`), endpoint `/station_portal/datatables_recepciones` (Task 4).
- Produces: tabla HTML `#datatables_mis_recepciones` con columnas `hora, producto, volumen, total_remisiones` + columna de acciones (subir/ver, y eliminar si `canDelete`); inputs `#fecha_recepciones` y (condicional) `#codgas_recepciones`.

- [ ] **Step 1: Crear la vista Twig**

```html
{% extends "views/layouts/base.html" %}
{% block title %}Mis Recepciones{% endblock %}
{% block mycss %}{% endblock %}
{% block menutitle %}Mis Recepciones{% endblock %}
{% block content %}
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Mis Recepciones</h5>
            </div>
            <div class="card-body h-100 table-responsive">
                <div class="row mb-3">
                    <div class="col-auto">
                        <label for="fecha_recepciones" class="form-label">Fecha:</label>
                        <input type="date" class="form-control" id="fecha_recepciones" value="{{ now | date('Y-m-d') }}">
                    </div>
                    {% if showStationSelect %}
                    <div class="col-auto">
                        <label for="codgas_recepciones" class="form-label">Estación:</label>
                        <select class="selectpicker form-control" data-live-search="true" id="codgas_recepciones">
                            {% for station in stations %}
                            <option value="{{ station.cod }}">{{ station.abr }}</option>
                            {% endfor %}
                        </select>
                    </div>
                    {% endif %}
                </div>
                <table id="datatables_mis_recepciones" class="table table-sm w-100" data-codgas="{{ tg_user['IdEstacion'] }}" data-can-delete="{{ canDelete ? '1' : '0' }}">
                    <thead>
                        <tr>
                            <th>HORA</th>
                            <th>PRODUCTO</th>
                            <th>VOLUMEN RECIBIDO</th>
                            <th>REMISIÓN</th>
                            <th>ACCIONES</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalSubirRemision" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Subir remisión</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="formSubirRemision" enctype="multipart/form-data">
                    <input type="hidden" name="nrotrn" id="subir_nrotrn">
                    <input type="hidden" name="codgas" id="subir_codgas">
                    <input type="hidden" name="fchtrn" id="subir_fchtrn">
                    <div class="mb-3">
                        <label for="archivo_remision" class="form-label">Archivo (PDF, JPG, PNG - máx. 10 MB):</label>
                        <input type="file" class="form-control" name="archivo" id="archivo_remision" accept=".pdf,.jpg,.jpeg,.png" required>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" id="btnConfirmarSubirRemision">Subir</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalVerRemisiones" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Remisiones de la recepción</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="modalVerRemisionesContent"></div>
        </div>
    </div>
</div>
{% endblock %}
{% block myjs %}
<script src="/_assets/js/station_portal.js"></script>
{% endblock %}
```

- [ ] **Step 2: Verificar el nombre del bloque JS usado por el layout base**

```bash
grep -n "block myjs\|block mycss\|block content" views/layouts/base.html
```

Confirmar que el nombre del bloque de scripts en `base.html` coincide con `myjs` usado arriba; si el layout usa otro nombre (p. ej. `scripts`), ajustar el bloque de la vista para que coincida exactamente.

- [ ] **Step 3: Crear el JS del datatable, calcando `_assets/js/operations.js:5-100`**

```js
let datatables_mis_recepciones = $('#datatables_mis_recepciones').DataTable({
    dom: '<"top"f>rt<"bottom"lip>',
    pageLength: 100,
    ajax: {
        url: '/station_portal/datatables_recepciones',
        data: function (d) {
            d.fecha = $('#fecha_recepciones').val();
            const codgasSelect = $('#codgas_recepciones');
            if (codgasSelect.length) {
                d.codgas = codgasSelect.val();
            }
        },
        error: function() {
            alertify.myAlert(
                `<div class="container text-center text-danger">
                    <h4 class="mt-2 text-danger">¡Error!</h4>
                </div>
                <div class="text-dark">
                    <p class="text-center">No se pudieron cargar las recepciones. Intente nuevamente.</p>
                </div>`
            );
        }
    },
    deferRender: true,
    columns: [
        { data: 'hora' },
        { data: 'producto' },
        { data: 'volumen', render: $.fn.dataTable.render.number(',', '.', 2) },
        {
            data: 'total_remisiones',
            render: function (data) {
                return data > 0
                    ? `<span class="badge bg-success">${data} subida(s)</span>`
                    : `<span class="badge bg-warning text-dark">Sin remisión</span>`;
            }
        },
        {
            data: null,
            render: function (row) {
                const canDelete = $('#datatables_mis_recepciones').data('can-delete') == 1;
                let html = `<button type="button" class="btn btn-sm btn-primary btn-subir-remision" data-nrotrn="${row.nrotrn}" data-codgas="${row.codgas}" data-fchtrn="${row.fchtrn}">Subir</button> `;
                if (row.total_remisiones > 0) {
                    html += `<button type="button" class="btn btn-sm btn-secondary btn-ver-remisiones" data-nrotrn="${row.nrotrn}" data-codgas="${row.codgas}" data-fchtrn="${row.fchtrn}">Ver</button>`;
                }
                return html;
            }
        },
    ],
});

$('#fecha_recepciones').on('change', function () {
    datatables_mis_recepciones.ajax.reload();
});

$('#codgas_recepciones').on('change', function () {
    datatables_mis_recepciones.ajax.reload();
});

$(document).on('click', '.btn-subir-remision', function () {
    $('#subir_nrotrn').val($(this).data('nrotrn'));
    $('#subir_codgas').val($(this).data('codgas'));
    $('#subir_fchtrn').val($(this).data('fchtrn'));
    $('#formSubirRemision')[0].reset();
    $('#modalSubirRemision').modal('show');
});

$('#btnConfirmarSubirRemision').on('click', async function () {
    const formData = new FormData($('#formSubirRemision')[0]);

    try {
        const response = await fetch('/station_portal/upload_remision', {
            method: 'POST',
            body: formData,
            credentials: 'include',
        });
        const result = await response.json();

        if (result.success) {
            $('#modalSubirRemision').modal('hide');
            datatables_mis_recepciones.ajax.reload(null, false);
        } else {
            alertify.myAlert(`<div class="text-danger text-center"><p>${result.message}</p></div>`);
        }
    } catch (error) {
        alertify.myAlert('<div class="text-danger text-center"><p>Error al subir el archivo.</p></div>');
    }
});

$(document).on('click', '.btn-ver-remisiones', async function () {
    const nrotrn = $(this).data('nrotrn');
    const codgas = $(this).data('codgas');
    const fchtrn = $(this).data('fchtrn');

    try {
        const response = await fetch(`/station_portal/remisiones_by_recepcion?nrotrn=${nrotrn}&codgas=${codgas}&fchtrn=${fchtrn}`, {
            method: 'GET',
            credentials: 'include',
        });
        const content = await response.text();
        $('#modalVerRemisionesContent').html(content);
        $('#modalVerRemisiones').modal('show');
    } catch (error) {
        alertify.myAlert('<div class="text-danger text-center"><p>Error al cargar las remisiones.</p></div>');
    }
});

$(document).on('click', '.btn-eliminar-remision', async function () {
    const id = $(this).data('id');

    try {
        const response = await fetch('/station_portal/delete_remision', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `id=${id}`,
            credentials: 'include',
        });
        const result = await response.json();

        if (result.success) {
            $(this).closest('.remision-row').remove();
            datatables_mis_recepciones.ajax.reload(null, false);
        } else {
            alertify.myAlert(`<div class="text-danger text-center"><p>${result.message}</p></div>`);
        }
    } catch (error) {
        alertify.myAlert('<div class="text-danger text-center"><p>Error al eliminar la remisión.</p></div>');
    }
});
```

- [ ] **Step 4: Commit**

```bash
git add views/station_portal/mis_recepciones.html _assets/js/station_portal.js
git commit -m "feat: vista y JS de Mis Recepciones"
```

---

## Task 6: Endpoints de subida, consulta y borrado de remisiones

**Files:**
- Modify: `_assets/controllers/station_portal.php` (agregar métodos)
- Create: `views/station_portal/modals/remisiones_list.html`

**Interfaces:**
- Consumes: `RecepcionRemisionesModel::upload/get_by_recepcion/soft_delete` (Task 2), `$_SESSION['tg_user']['Id']` (id del usuario en sesión, ya poblado por el login existente).
- Produces: métodos `upload_remision(): void`, `remisiones_by_recepcion(): void`, `delete_remision(): void` en `station_portal`, rutas `/station_portal/upload_remision`, `/station_portal/remisiones_by_recepcion`, `/station_portal/delete_remision`.

- [ ] **Step 1: Agregar `upload_remision()`**

Insertar antes del `}` de cierre de clase en `_assets/controllers/station_portal.php`:

```php
    public function upload_remision(): void
    {
        if (!authorized(self::PERM_VER)) {
            json_output(['success' => false, 'message' => 'No autorizado']);
            return;
        }

        $nrotrn = (int)($_POST['nrotrn'] ?? 0);
        $codgasPost = (int)($_POST['codgas'] ?? 0);
        $fchtrn = (int)($_POST['fchtrn'] ?? 0);

        if ($nrotrn <= 0 || $codgasPost <= 0 || $fchtrn <= 0 || !isset($_FILES['archivo'])) {
            json_output(['success' => false, 'message' => 'Datos incompletos']);
            return;
        }

        // El codgas del POST solo se respeta si el usuario tiene el permiso de
        // todas las estaciones; si no, se ignora por completo y se fuerza el de
        // sesión, sin importar qué haya mandado el cliente.
        $hasIdEstacion = isset($_SESSION['tg_user']['IdEstacion']) && (int)$_SESSION['tg_user']['IdEstacion'] > 0;
        if (authorized(self::PERM_TODAS_ESTACIONES)) {
            $codgasEfectivo = $codgasPost;
        } elseif ($hasIdEstacion) {
            $codgasEfectivo = (int)$_SESSION['tg_user']['IdEstacion'];
        } else {
            json_output(['success' => false, 'message' => 'No autorizado para esta estación']);
            return;
        }

        $userId = (int)$_SESSION['tg_user']['Id'];
        $result = $this->recepcionRemisionesModel->upload($nrotrn, $codgasEfectivo, $fchtrn, $_FILES['archivo'], $userId);

        json_output($result);
    }
```

- [ ] **Step 2: Verificación manual de sintaxis**

```bash
php -l _assets/controllers/station_portal.php
```

Expected: `No syntax errors detected`

- [ ] **Step 3: Agregar `remisiones_by_recepcion()`**

```php
    public function remisiones_by_recepcion(): void
    {
        if (!authorized(self::PERM_VER)) {
            http_response_code(403);
            exit;
        }

        $nrotrn = (int)($_GET['nrotrn'] ?? 0);
        $codgas = (int)($_GET['codgas'] ?? 0);
        $fchtrn = (int)($_GET['fchtrn'] ?? 0);
        $canDelete = authorized(self::PERM_ELIMINAR);

        $remisiones = $this->recepcionRemisionesModel->get_by_recepcion($nrotrn, $codgas, $fchtrn);

        echo $this->twig->render($this->route . 'modals/remisiones_list.html', compact('remisiones', 'canDelete'));
    }
```

- [ ] **Step 4: Crear la vista parcial del modal**

Siguiendo el patrón ya documentado del proyecto (vista parcial Twig sin `{% extends %}`, el controlador hace `echo` del render, el JS la inyecta con `.html(content)` — memoria `patron-modales-vista-parcial`):

```html
{% if remisiones is empty %}
<p class="text-center text-muted">No hay remisiones subidas para esta recepción.</p>
{% else %}
<ul class="list-group remisiones-list">
    {% for r in remisiones %}
    <li class="list-group-item d-flex justify-content-between align-items-center remision-row">
        <a href="/{{ r.file_path }}" target="_blank">{{ r.original_filename }}</a>
        {% if canDelete %}
        <button type="button" class="btn btn-sm btn-danger btn-eliminar-remision" data-id="{{ r.id }}">Eliminar</button>
        {% endif %}
    </li>
    {% endfor %}
</ul>
{% endif %}
```

- [ ] **Step 5: Agregar `delete_remision()`**

```php
    public function delete_remision(): void
    {
        if (!authorized(self::PERM_ELIMINAR)) {
            json_output(['success' => false, 'message' => 'No autorizado']);
            return;
        }

        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            json_output(['success' => false, 'message' => 'Id inválido']);
            return;
        }

        $userId = (int)$_SESSION['tg_user']['Id'];
        $result = $this->recepcionRemisionesModel->soft_delete($id, $userId);

        json_output($result);
    }
}
```

(Este es el `}` de cierre de clase definitivo del archivo.)

- [ ] **Step 6: Verificación manual final**

```bash
php -l _assets/controllers/station_portal.php
```

Expected: `No syntax errors detected`

- [ ] **Step 7: Commit**

```bash
git add _assets/controllers/station_portal.php views/station_portal/modals/remisiones_list.html
git commit -m "feat: endpoints de subida, consulta y soft-delete de remisiones"
```

---

## Task 7: Entrada en el sidebar

**Files:**
- Modify: `views/layouts/sidebar.html:353-361`

**Interfaces:**
- Consumes: `authorized($permission_id)` (Twig function, `_assets/classes/twig_functions.php:92-97`), id real de `PERM_VER` (Task 1 Step 4).

- [ ] **Step 1: Insertar la entrada dentro del bloque de Operaciones**

En `views/layouts/sidebar.html`, inmediatamente después del bloque `{% if authorized(21) %}...Tabulador...{% endif %}` (líneas 355-361), agregar. `authorized()` en Twig (`_assets/classes/twig_functions.php:92-97`) espera el id entero literal, no una constante PHP — sustituir `101` por el id real de `PERM_VER` (Task 1 Step 4):

```html
{% if authorized(101) %}
<li class="sidebar-item">
  <a class="sidebar-link" href="/station_portal/mis_recepciones">
    <i data-feather="truck"></i>
    <span class="align-middle">Mis Recepciones</span>
  </a>
</li>
{% endif %}
```

- [ ] **Step 2: Verificación manual**

Con el permiso `PERM_VER` asignado al usuario de prueba (Task 1 Step 5), iniciar sesión en el navegador y confirmar que la entrada "Mis Recepciones" aparece bajo la sección OPERACIONES del sidebar y que el link navega a `/station_portal/mis_recepciones` sin error 404/500.

- [ ] **Step 3: Commit**

```bash
git add views/layouts/sidebar.html
git commit -m "feat: entrada de sidebar para Mis Recepciones en Operaciones"
```

---

## Task 8: Verificación end-to-end manual

**Files:** ninguno (solo verificación)

- [ ] **Step 1: Probar como usuario de estación (sin `PERM_TODAS_ESTACIONES`)**

Con un usuario que tenga `PERM_VER` y una `IdEstacion` real en sesión (login normal): confirmar que la vista NO muestra el `<select>` de estación, que la tabla carga las recepciones del día actual para su propia estación, y que manipular manualmente el parámetro `codgas` en la petición AJAX (vía devtools) no cambia los resultados (el backend lo ignora).

- [ ] **Step 2: Probar como usuario con `PERM_TODAS_ESTACIONES`**

Asignar también ese permiso al usuario de prueba (Task 1, mismo patrón del Step 5). Confirmar que el `<select>` de estación aparece, que cambiarlo recarga la tabla con recepciones de la estación elegida, y que sin elegir nada explícito se comporta razonablemente (usa la de sesión si la tiene, o "todas" si no).

- [ ] **Step 3: Subir una remisión**

Elegir una recepción con `Sin remisión`, subir un PDF de prueba (<10MB). Confirmar que el badge cambia a "1 subida(s)" tras recargar la tabla, y que el archivo aparece en `_assets/uploads/recepcion_remisiones/{año}/{mes}/`.

- [ ] **Step 4: Ver y subir una segunda remisión sobre la misma recepción**

Confirmar que "Ver" muestra la primera remisión con link de descarga funcional, y que subir una segunda no reemplaza la primera (ambas coexisten — comportamiento acordado con el usuario).

- [ ] **Step 5: Probar soft-delete**

Con `PERM_ELIMINAR` asignado, eliminar una de las dos remisiones desde el modal "Ver". Confirmar que desaparece de la lista y que el badge de conteo baja en 1, pero que el archivo sigue presente en disco (`_assets/uploads/recepcion_remisiones/...`) y la fila sigue en `TG.dbo.recepcion_remisiones` con `is_deleted = 1`.

- [ ] **Step 6: Probar sin `PERM_ELIMINAR`**

Quitar el permiso de eliminar a un usuario que sí tiene `PERM_VER`. Confirmar que el botón "Eliminar" no aparece en el modal "Ver" para ese usuario.

- [ ] **Step 7: Probar el guard de acceso sin ningún permiso**

Con un usuario sin `PERM_VER`: confirmar que la entrada de sidebar no aparece y que navegar directo a `/station_portal/mis_recepciones` redirige a `/home/index` en vez de mostrar la vista.
