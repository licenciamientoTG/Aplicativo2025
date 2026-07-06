# Capital de Trabajo base por sucursal — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Catálogo `arqueo_capital_base` con el Capital de Trabajo por sucursal que se copia automáticamente a `arqueo_concentrado_extras` al crear una sesión de arqueo, editable por sesión, con checkbox opcional en el modal para actualizar la base.

**Architecture:** PHP MVC propio (no Laravel). Controlador plano `Arqueo` (`_assets/controllers/arqueo.php`), modelos extienden `Model` (`$this->sql->...`), SQL Server (BD TG), vistas Twig. Spec: `docs/superpowers/specs/2026-07-06-capital-base-arqueo-design.md`.

**Tech Stack:** PHP 8 + PDO sqlsrv, Twig, Bootstrap/jQuery. Sin framework de tests: verificación = `php -l`, scripts de un solo uso en el scratchpad y prueba manual en navegador.

## Global Constraints

- **Footgun del proyecto:** instanciar un modelo (`new XxxModel()`) DENTRO de una transacción la rompe (reconexión en `Model.php`/`MySqlPdoHandler`). Todo modelo nuevo se instancia en el constructor del controlador.
- `MySqlPdoHandler::update()` devuelve `true` en cualquier ejecución exitosa sin importar filas afectadas → los upserts se hacen con un solo statement `IF EXISTS ... UPDATE ... ELSE INSERT`.
- El body AJAX se lee con el helper `input()` del controlador (JSON vía php://input), no `$_POST`.
- Permiso admin = constante `Arqueo::PERM_ADMIN` (id 73); todos los endpoints nuevos/afectados ya usan `$this->guard([self::PERM_ADMIN])` — no cambiar.
- NO levantar ni recargar el servidor PHP de desarrollo — el usuario lo gestiona él mismo.
- Scripts temporales de verificación van al scratchpad de la sesión, nunca al repo.

---

### Task 1: Tabla `arqueo_capital_base` + seed (SQL y BD)

**Files:**
- Modify: `docs/sql/arqueo_schema.sql` (agregar sección al final, antes del bloque de permisos si se prefiere orden, pero al final del archivo es aceptable)
- Create: `docs/sql/seed_capital_base.sql`

**Interfaces:**
- Produces: tabla `[TG].[dbo].[arqueo_capital_base]` con columnas `id, sucursal_id (UNIQUE), capital_trabajo, updated_by, updated_at`, sembrada con 13 filas.

- [ ] **Step 1: Agregar la tabla al schema**

Agregar al final de `docs/sql/arqueo_schema.sql`:

```sql
/* ---------------------------------------------------------------------------
   7) Capital de Trabajo BASE por sucursal.
   Catálogo que se copia a arqueo_concentrado_extras al crear cada sesión.
   Editable desde el modal del Concentrado (checkbox "actualizar base").
   --------------------------------------------------------------------------- */
IF OBJECT_ID('[TG].[dbo].[arqueo_capital_base]', 'U') IS NULL
BEGIN
    CREATE TABLE [TG].[dbo].[arqueo_capital_base] (
        [id]              INT IDENTITY(1,1) NOT NULL,
        [sucursal_id]     INT               NOT NULL,
        [capital_trabajo] DECIMAL(14,2)     NOT NULL
                          CONSTRAINT [DF_acb_capital] DEFAULT (0),
        [updated_by]      INT               NULL,
        [updated_at]      DATETIME          NOT NULL
                          CONSTRAINT [DF_acb_updated] DEFAULT (GETDATE()),
        CONSTRAINT [PK_arqueo_capital_base] PRIMARY KEY CLUSTERED ([id]),
        CONSTRAINT [UQ_arqueo_capital_base] UNIQUE ([sucursal_id])
    );
END
GO
```

- [ ] **Step 2: Crear el seed**

Crear `docs/sql/seed_capital_base.sql`:

```sql
/* ============================================================================
   Siembra el Capital de Trabajo BASE por sucursal (valores confirmados por
   negocio 2026-07-06; coinciden con el seed histórico de concentrado_extras).
   Requiere arqueo_capital_base (ver arqueo_schema.sql, sección 7).
   Seguro de re-ejecutar (NOT EXISTS evita duplicados; no pisa valores ya
   editados desde la aplicación).
   ============================================================================ */
USE [TG];
GO

DECLARE @base TABLE (sucursal_id INT, capital_trabajo DECIMAL(14,2));
INSERT INTO @base (sucursal_id, capital_trabajo) VALUES
    (1,  3090824.74), -- Waterfill
    (2,  390000.00),  -- Misiones
    (3,  300000.00),  -- Municipio
    (4,  350000.00),  -- Puerto de Palos
    (5,  300000.00),  -- Permuta
    (6,  280000.00),  -- Anapra
    (7,  250000.00),  -- Gomez Morin
    (8,  250000.00),  -- Lopez Mateos
    (9,  660000.00),  -- Villa Ahumada
    (10, 200000.00),  -- Km30
    (11, 650000.00),  -- Curva
    (12, 300000.00),  -- Custodia
    (13, 550000.00);  -- Perez Serna

INSERT INTO [TG].[dbo].[arqueo_capital_base] (sucursal_id, capital_trabajo)
SELECT b.sucursal_id, b.capital_trabajo
FROM @base b
WHERE NOT EXISTS (
    SELECT 1 FROM [TG].[dbo].[arqueo_capital_base] e
    WHERE e.sucursal_id = b.sucursal_id
);
GO

SELECT sucursal_id, capital_trabajo FROM [TG].[dbo].[arqueo_capital_base]
ORDER BY sucursal_id;
GO
```

- [ ] **Step 3: Ejecutar en la BD TG**

Ejecutar ambos bloques contra la BD usando un script PHP de un solo uso en el scratchpad que reutiliza la conexión de la app (misma técnica que `check_tabla_extras.php` de esta sesión: definir `_DONTCHECKSESSION`, `$_SERVER['DOCUMENT_ROOT']`, `REQUEST_URI`, `HTTP_HOST`, `chdir()` al repo, `require header.class.php` + `MySqlPdoHandler.class.php`, y ejecutar los statements con `$db->update(...)` — sin `GO`, separando los batches en llamadas distintas; el `DECLARE @base ... INSERT ... SELECT` del seed debe ir completo en UNA sola llamada porque la variable de tabla vive por batch).

NO usar sqlcmd con la contraseña en la línea de comandos (bloqueado por permisos).

- [ ] **Step 4: Verificar**

Con el mismo script (o uno de consulta):

```sql
SELECT COUNT(*) AS n FROM [TG].[dbo].[arqueo_capital_base];
```

Esperado: `n = 13`, y `sucursal_id = 1` con `capital_trabajo = 3090824.74`.

- [ ] **Step 5: Commit**

```bash
git add docs/sql/arqueo_schema.sql docs/sql/seed_capital_base.sql
git commit -m "feat(arqueo): tabla y seed de capital de trabajo base por sucursal"
```

---

### Task 2: `ArqueoCapitalBaseModel` + copia de la base en `crear_sesion()`

**Files:**
- Create: `_assets/models/ArqueoCapitalBaseModel.php`
- Modify: `_assets/controllers/arqueo.php` (propiedades/constructor ~líneas 67-84; `crear_sesion()` ~líneas 167-203)

**Interfaces:**
- Consumes: tabla `arqueo_capital_base` (Task 1); `ArqueoConcentradoExtrasModel::upsert(int $sesion_id, int $sucursal_id, array $d, ?int $usuario_id): bool` (existente).
- Produces: `ArqueoCapitalBaseModel::get_all(): array` (mapa `sucursal_id => float`) y `ArqueoCapitalBaseModel::upsert(int $sucursal_id, float $capital, ?int $usuario_id): bool`; propiedad `$this->capitalBaseModel` en el controlador (Task 3 la usa).

- [ ] **Step 1: Crear el modelo**

Crear `_assets/models/ArqueoCapitalBaseModel.php`:

```php
<?php

/**
 * Capital de Trabajo BASE por sucursal: catálogo que se copia a
 * arqueo_concentrado_extras al crear una sesión de arqueo.
 * Tabla: [TG].[dbo].[arqueo_capital_base]
 */
class ArqueoCapitalBaseModel extends Model
{
    /** Mapa sucursal_id => capital_trabajo (float). */
    public function get_all(): array
    {
        $rows = $this->sql->select(
            "SELECT sucursal_id, capital_trabajo FROM [TG].[dbo].[arqueo_capital_base];"
        ) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['sucursal_id']] = (float) $r['capital_trabajo'];
        }
        return $out;
    }

    /**
     * Upsert del capital base de una sucursal. Un solo statement
     * IF EXISTS...UPDATE...ELSE INSERT (ver ArqueoConcentradoExtrasModel).
     */
    public function upsert(int $sucursal_id, float $capital, ?int $usuario_id): bool
    {
        return (bool) $this->sql->update(
            "IF EXISTS (SELECT 1 FROM [TG].[dbo].[arqueo_capital_base]
                         WHERE sucursal_id = ?)
                UPDATE [TG].[dbo].[arqueo_capital_base] SET
                    capital_trabajo = ?, updated_by = ?, updated_at = GETDATE()
                WHERE sucursal_id = ?
             ELSE
                INSERT INTO [TG].[dbo].[arqueo_capital_base]
                    (sucursal_id, capital_trabajo, updated_by)
                VALUES (?, ?, ?);",
            [
                $sucursal_id,
                $capital, $usuario_id, $sucursal_id,
                $sucursal_id, $capital, $usuario_id,
            ]
        );
    }
}
```

- [ ] **Step 2: Instanciar el modelo en el constructor del controlador**

En `_assets/controllers/arqueo.php`, agregar la propiedad junto a las existentes:

```php
    public ArqueoConcentradoExtrasModel $concentradoExtrasModel;
    public ArqueoCapitalBaseModel $capitalBaseModel;
```

y en `__construct`:

```php
        $this->concentradoExtrasModel = new ArqueoConcentradoExtrasModel();
        $this->capitalBaseModel       = new ArqueoCapitalBaseModel();
```

(Se instancia aquí y NO dentro de `crear_sesion()` por el footgun de transacciones — ver Global Constraints.)

- [ ] **Step 3: Copiar la base al crear la sesión**

En `crear_sesion()`, la base se lee ANTES de abrir la transacción (un SELECT dentro no rompe nada, pero leerla antes mantiene la transacción corta) y las filas de extras se insertan dentro, deduplicando sucursales (SUCURSALES tiene 15 entradas / 13 sucursales: Waterfill y Perez Serna traen 2 cajas):

```php
        $base = $this->capitalBaseModel->get_all();

        $this->sql_begin();
        try {
            $sesion_id = $this->sesionesModel->create($nombre, $fecha, (int) $this->user_id());
            if (!$sesion_id) {
                throw new Exception('No se pudo crear la sesión.');
            }
            $sucursales_hechas = [];
            foreach (self::SUCURSALES as $s) {
                $this->cajasModel->create([
                    'sesion_id'       => $sesion_id,
                    'sucursal_id'     => $s['id'],
                    'sucursal_nombre' => $s['nombre'],
                    'caja_numero'     => $s['caja'],
                ]);
                if (isset($sucursales_hechas[$s['id']])) {
                    continue;
                }
                $sucursales_hechas[$s['id']] = true;
                $this->concentradoExtrasModel->upsert($sesion_id, (int) $s['id'], [
                    'capital_trabajo' => $base[$s['id']] ?? 0.0,
                    'gastos_tramite'  => 0.0,
                    'adeudo'          => 0.0,
                    'reinversion'     => 0.0,
                    'utilidad'        => 0.0,
                ], $this->user_id());
            }
            $this->sesionesModel->sql->commit();
            $this->json(['success' => true, 'sesion_id' => $sesion_id]);
        } catch (Exception $e) {
            $this->sesionesModel->sql->rollBack();
            $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
```

(Solo se agregan `$base = ...`, `$sucursales_hechas` y el bloque del upsert; el resto del método queda igual.)

- [ ] **Step 4: Verificar sintaxis**

Run: `php -l _assets/models/ArqueoCapitalBaseModel.php && php -l _assets/controllers/arqueo.php`
Esperado: `No syntax errors detected` en ambos.

- [ ] **Step 5: Verificar comportamiento contra la BD**

Script de un solo uso en el scratchpad (misma plantilla de conexión que Task 1 Step 3) que simule la copia sin pasar por HTTP:

```php
<?php
// Prueba: get_all() del modelo y copia a una sesión ficticia (rollback al final)
define('_DONTCHECKSESSION', true);
$base = 'C:/Users/alejandro.martinez/Desktop/codigo/AplicativoPhp';
$_SERVER['DOCUMENT_ROOT'] = $base;
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['HTTP_HOST'] = 'localhost';
chdir($base);
require_once $base . '/_assets/classes/header.class.php';
require_once $base . '/_assets/classes/common/MySqlPdoHandler.class.php';
require_once $base . '/_assets/models/Model.php';
require_once $base . '/_assets/models/ArqueoCapitalBaseModel.php';

$m = new ArqueoCapitalBaseModel();
$all = $m->get_all();
echo "Sucursales en base: " . count($all) . PHP_EOL;          // esperado: 13
echo "Waterfill: " . $all[1] . PHP_EOL;                        // esperado: 3090824.74
echo "Km30: " . $all[10] . PHP_EOL;                            // esperado: 200000
```

Run: `php <scratchpad>/test_capital_base.php`
Esperado: `Sucursales en base: 13`, `Waterfill: 3090824.74`, `Km30: 200000`.

La copia completa en `crear_sesion()` se valida manualmente en navegador (checklist final) — el usuario gestiona el servidor.

- [ ] **Step 6: Commit**

```bash
git add _assets/models/ArqueoCapitalBaseModel.php _assets/controllers/arqueo.php
git commit -m "feat(arqueo): copiar capital de trabajo base al crear sesion"
```

---

### Task 3: Checkbox "actualizar base" en el modal + endpoint

**Files:**
- Modify: `views/arqueo/concentrado.html` (modal `#modal_concentrado_extra` ~líneas 158-232)
- Modify: `_assets/controllers/arqueo.php` (`guardar_concentrado_extra()` ~líneas 407-429)

**Interfaces:**
- Consumes: `ArqueoCapitalBaseModel::upsert(int $sucursal_id, float $capital, ?int $usuario_id): bool` vía `$this->capitalBaseModel` (Task 2).
- Produces: el endpoint `/arqueo/guardar_concentrado_extra` acepta el campo JSON opcional `actualizar_base` (bool, default false).

- [ ] **Step 1: Checkbox en el modal**

En `views/arqueo/concentrado.html`, dentro del `mb-3` del Capital de Trabajo, después del `<input id="mx_capital_trabajo">`:

```html
        <div class="mb-3">
          <label class="form-label">Capital de Trabajo</label>
          <input type="number" step="0.01" class="form-control" id="mx_capital_trabajo">
          <div class="form-check mt-1">
            <input class="form-check-input" type="checkbox" id="mx_actualizar_base">
            <label class="form-check-label" for="mx_actualizar_base">
              Actualizar también la base para arqueos futuros
            </label>
          </div>
        </div>
```

- [ ] **Step 2: JS — resetear al abrir y enviar el flag**

En `abrirModalExtra(...)`, junto a las demás asignaciones (antes del `$('#modal_concentrado_extra').modal('show');`):

```js
    document.getElementById('mx_actualizar_base').checked = false;
```

En `guardarModalExtra()`, agregar al objeto `payload`:

```js
      actualizar_base: document.getElementById('mx_actualizar_base').checked,
```

- [ ] **Step 3: Endpoint — upsert opcional a la base**

En `guardar_concentrado_extra()` de `_assets/controllers/arqueo.php`, reemplazar el cierre del método:

```php
        $ok = $this->concentradoExtrasModel->upsert($sesion_id, $sucursal_id, $datos, $this->user_id());

        if ($ok && !empty($in['actualizar_base'])) {
            $this->capitalBaseModel->upsert(
                $sucursal_id,
                $datos['capital_trabajo'],
                $this->user_id()
            );
        }

        $this->json(['success' => $ok]);
```

Actualizar también el docblock del método agregando la línea:

```php
     * actualizar_base (bool, opcional): además guarda capital_trabajo como base.
```

- [ ] **Step 4: Verificar sintaxis**

Run: `php -l _assets/controllers/arqueo.php`
Esperado: `No syntax errors detected`.

El JS del modal vive inline en el Twig (no en arqueo.js), así que `node --check` no aplica; revisar visualmente que las llaves/comas del payload quedaron bien.

- [ ] **Step 5: Verificar el upsert de base contra la BD**

Script de un solo uso en scratchpad (misma plantilla): hacer `upsert(13, 555000.00, 1)`, leer con `get_all()` que Perez Serna ahora es 555000, y **restaurar** con `upsert(13, 550000.00, 1)`.

Esperado: valor cambia y se restaura; `COUNT(*)` sigue en 13 (no insertó fila nueva).

- [ ] **Step 6: Commit**

```bash
git add views/arqueo/concentrado.html _assets/controllers/arqueo.php
git commit -m "feat(arqueo): checkbox para actualizar capital base desde el modal del concentrado"
```

---

## Checklist de verificación manual final (navegador, la hace el usuario o guiado)

1. Crear una sesión nueva en `/arqueo` → abrir su concentrado → las 13 sucursales muestran su capital base sin capturar nada (Waterfill $3,090,824.74 … Perez Serna $550,000.00).
2. Editar el capital de una sucursal SIN marcar el checkbox → guarda; crear OTRA sesión → esa sucursal sigue saliendo con el valor base original.
3. Editar con el checkbox marcado → crear otra sesión → sale el valor nuevo.
4. Los otros 4 campos del modal (gastos, adeudo, reinversión, utilidad) nunca alteran la base.
