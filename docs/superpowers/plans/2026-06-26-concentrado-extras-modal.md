# Concentrado: columnas D-O del Excel + modal de captura manual — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Igualar la vista `/arqueo/concentrado/{sesion_id}` al formato real del Excel `NUEVO 17 JUN.xlsx` (colores, columnas C-O, bloque resumen inferior) y permitir capturar manualmente, por sucursal y por sesión, Capital de Trabajo, Gastos en trámite, Adeudo, Reinversión y Utilidad mediante un modal.

**Architecture:** Nueva tabla `arqueo_concentrado_extras` (sesión+sucursal → 5 campos manuales) con modelo `ArqueoConcentradoExtrasModel`. `Arqueo::concentrado()` hace LEFT JOIN lógico contra esa tabla y calcula en PHP las columnas derivadas D,E,F,G,H,L,N,O con las fórmulas exactas del Excel. La vista se reescribe a 13 columnas + colores reales + modal Bootstrap que llama a un endpoint nuevo `guardar_concentrado_extra`. Un script SQL one-off siembra el Capital de Trabajo de las 2 sesiones existentes.

**Tech Stack:** PHP 8 MVC, Twig, SQL Server vía PDO (`MySqlPdoHandler`), Bootstrap (modal nativo, jQuery), sin build step, sin test framework automatizado — verificación manual.

## Global Constraints

- Nunca iniciar, reiniciar ni recargar el servidor de desarrollo PHP — el usuario lo gestiona él mismo. Las instrucciones de prueba de cada tarea asumen que el servidor ya está corriendo (lo arranca el usuario).
- Todas las queries SQL usan `[TG].[dbo].[tabla]` con parámetros posicionales (`?`), igual que el resto del módulo `arqueo` — nunca interpolar valores directamente en la query.
- `MySqlPdoHandler::update($query, $params)` devuelve `true` en cualquier ejecución exitosa sin importar filas afectadas (no usar el patrón "UPDATE; si falla, INSERT" en dos llamadas separadas) — usar un único statement `IF EXISTS...UPDATE...ELSE INSERT`.
- El permiso que protege todo el módulo de Concentrado es `PERM_ADMIN` (id 73, acción `arqueo_admin`), constante ya definida en `_assets/controllers/arqueo.php:17`. El endpoint y el modal nuevos usan el mismo permiso — no se crea ningún permiso nuevo.
- Los nombres y orden de `SUCURSALES` en `_assets/controllers/arqueo.php:24-40` (sucursal_id 1-13) son la fuente de verdad para el mapeo sucursal→capital de trabajo del seed.
- Cambios de esquema SQL se aplican manualmente vía `sqlcmd` contra la base `TG` en `192.168.0.6` — esto lo ejecuta el usuario, el plan solo deja el script `.sql` listo.

---

## Task 1: Tabla `arqueo_concentrado_extras` y corrección del schema file

**Files:**
- Modify: `docs/sql/arqueo_schema.sql`
- Create: `docs/sql/seed_concentrado_extras.sql`

**Interfaces:**
- Produces: tabla `[TG].[dbo].[arqueo_concentrado_extras]` con columnas `id, sesion_id, sucursal_id, capital_trabajo, gastos_tramite, adeudo, reinversion, utilidad, updated_by, updated_at` y `UNIQUE(sesion_id, sucursal_id)` — Task 2 la consume desde `ArqueoConcentradoExtrasModel`.

- [ ] **Step 1: Corregir las columnas obsoletas de `arqueo_cajas` en el archivo de schema**

Abre `docs/sql/arqueo_schema.sql`. En la sección 2 (`arqueo_cajas`), las líneas actuales son:

```sql
        [go_exchange_dolares]   DECIMAL(14,2)     NULL,
        [go_exchange_mxn]       DECIMAL(14,2)     NULL,
        [tipo_cambio_venta]     DECIMAL(10,4)     NULL,
        [tipo_cambio_compra]    DECIMAL(10,4)     NULL,
```

Reemplázalas por (refleja el `sp_rename` y `DROP COLUMN` ya aplicados en vivo contra la BD en una sesión anterior):

```sql
        [go_exchange_dolares]   DECIMAL(14,2)     NULL,
        [go_exchange_mxn]       DECIMAL(14,2)     NULL,
        [costo_promedio]        DECIMAL(10,4)     NULL,
```

- [ ] **Step 2: Agregar la tabla `arqueo_concentrado_extras` al final del archivo, antes de la sección de permisos**

Inserta esta nueva sección 5 (y renumera la sección de permisos existente, hoy "5) Permisos nuevos...", a "6) Permisos nuevos..."), justo después del bloque de `arqueo_vales` (`GO` que cierra esa sección) y antes del comentario `/* 5) Permisos nuevos...`:

```sql
/* ---------------------------------------------------------------------------
   5) Captura manual por sucursal+sesión para el Concentrado
   (Capital de Trabajo, Gastos en trámite, Adeudo, Reinversión, Utilidad).
   Ver hoja "Concentrado" de los Excel de referencia, columnas C, I, J, K, M.
   --------------------------------------------------------------------------- */
IF OBJECT_ID('[TG].[dbo].[arqueo_concentrado_extras]', 'U') IS NULL
BEGIN
    CREATE TABLE [TG].[dbo].[arqueo_concentrado_extras] (
        [id]              INT IDENTITY(1,1) NOT NULL,
        [sesion_id]       INT               NOT NULL,
        [sucursal_id]     INT               NOT NULL,
        [capital_trabajo] DECIMAL(14,2)     NOT NULL
                          CONSTRAINT [DF_ace_capital] DEFAULT (0),
        [gastos_tramite]  DECIMAL(14,2)     NOT NULL
                          CONSTRAINT [DF_ace_gastos]  DEFAULT (0),
        [adeudo]          DECIMAL(14,2)     NOT NULL
                          CONSTRAINT [DF_ace_adeudo]  DEFAULT (0),
        [reinversion]     DECIMAL(14,2)     NOT NULL
                          CONSTRAINT [DF_ace_reinv]   DEFAULT (0),
        [utilidad]        DECIMAL(14,2)     NOT NULL
                          CONSTRAINT [DF_ace_util]    DEFAULT (0),
        [updated_by]      INT               NULL,
        [updated_at]      DATETIME          NOT NULL
                          CONSTRAINT [DF_ace_updated] DEFAULT (GETDATE()),
        CONSTRAINT [PK_arqueo_concentrado_extras] PRIMARY KEY CLUSTERED ([id]),
        CONSTRAINT [FK_ace_sesion] FOREIGN KEY ([sesion_id])
            REFERENCES [TG].[dbo].[arqueo_sesiones] ([id]) ON DELETE CASCADE,
        CONSTRAINT [UQ_arqueo_concentrado_extras] UNIQUE ([sesion_id], [sucursal_id])
    );
    CREATE INDEX [IX_ace_sesion] ON [TG].[dbo].[arqueo_concentrado_extras] ([sesion_id]);
END
GO

```

- [ ] **Step 3: Crear el script de seed**

Crea `docs/sql/seed_concentrado_extras.sql` con este contenido exacto:

```sql
/* ============================================================================
   Siembra Capital de Trabajo (columna C del Excel "NUEVO 17 JUN.xlsx",
   hoja "Concentrado") para las sesiones de arqueo que ya existen.
   Gastos en trámite / Adeudo / Reinversión / Utilidad quedan en 0: se
   capturan desde el modal del Concentrado en adelante.

   Requiere que arqueo_concentrado_extras ya exista (ver arqueo_schema.sql).
   Seguro de re-ejecutar (NOT EXISTS evita duplicados).
   ============================================================================ */
USE [TG];
GO

DECLARE @capital TABLE (sucursal_id INT, capital_trabajo DECIMAL(14,2));
INSERT INTO @capital (sucursal_id, capital_trabajo) VALUES
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

INSERT INTO [TG].[dbo].[arqueo_concentrado_extras] (sesion_id, sucursal_id, capital_trabajo)
SELECT s.id, c.sucursal_id, c.capital_trabajo
FROM [TG].[dbo].[arqueo_sesiones] s
CROSS JOIN @capital c
WHERE NOT EXISTS (
    SELECT 1 FROM [TG].[dbo].[arqueo_concentrado_extras] e
    WHERE e.sesion_id = s.id AND e.sucursal_id = c.sucursal_id
);
GO

SELECT s.nombre AS sesion, e.sucursal_id, e.capital_trabajo
FROM [TG].[dbo].[arqueo_concentrado_extras] e
JOIN [TG].[dbo].[arqueo_sesiones] s ON s.id = e.sesion_id
ORDER BY s.id, e.sucursal_id;
GO
```

- [ ] **Step 4: Ejecutar ambos scripts contra la BD**

Estos dos scripts modifican la base de datos compartida `TG` — pide confirmación explícita al usuario antes de ejecutarlos, ya que es una acción con efecto sobre estado compartido fuera de este worktree. Una vez confirmado:

```bash
sqlcmd -S 192.168.0.6 -d TG -i docs/sql/arqueo_schema.sql
sqlcmd -S 192.168.0.6 -d TG -i docs/sql/seed_concentrado_extras.sql
```

Verifica en la salida del segundo comando que aparecen 26 filas (13 sucursales × 2 sesiones existentes) con los `capital_trabajo` esperados.

- [ ] **Step 5: Commit**

```bash
git add docs/sql/arqueo_schema.sql docs/sql/seed_concentrado_extras.sql
git commit -m "feat(arqueo): agregar tabla arqueo_concentrado_extras y seed de Capital de Trabajo"
```

---

## Task 2: `ArqueoConcentradoExtrasModel`

**Files:**
- Create: `_assets/models/ArqueoConcentradoExtrasModel.php`

**Interfaces:**
- Consumes: `$this->sql` (heredado de `Model`, instancia de `MySqlPdoHandler` ya conectada — ver `_assets/models/Model.php:9`), `$this->sql->select($query, $params)`, `$this->sql->update($query, $params)` (devuelve `true`/`false`, no cantidad de filas — ver Global Constraints).
- Produces: `ArqueoConcentradoExtrasModel::by_sesion(int $sesion_id): array` (array indexado por `sucursal_id`, cada valor es la fila completa de la tabla o ausente si nunca se capturó) y `ArqueoConcentradoExtrasModel::upsert(int $sesion_id, int $sucursal_id, array $d, ?int $usuario_id): bool` donde `$d` tiene las claves `capital_trabajo`, `gastos_tramite`, `adeudo`, `reinversion`, `utilidad` (floats) — Task 3 los consume.

- [ ] **Step 1: Crear el modelo**

Crea `_assets/models/ArqueoConcentradoExtrasModel.php`:

```php
<?php

/**
 * Captura manual por sucursal+sesión para el Concentrado de arqueo.
 * Tabla: [TG].[dbo].[arqueo_concentrado_extras]
 */
class ArqueoConcentradoExtrasModel extends Model
{
    /**
     * Todas las filas de extras de una sesión, indexadas por sucursal_id.
     */
    public function by_sesion(int $sesion_id): array
    {
        $rows = $this->sql->select(
            "SELECT * FROM [TG].[dbo].[arqueo_concentrado_extras] WHERE sesion_id = ?;",
            [$sesion_id]
        ) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['sucursal_id']] = $r;
        }
        return $out;
    }

    /**
     * Upsert de los 5 campos manuales para sesion_id+sucursal_id.
     * Usa un único statement IF EXISTS...UPDATE...ELSE INSERT porque
     * MySqlPdoHandler::update() devuelve true en cualquier ejecución
     * exitosa, sin importar filas afectadas.
     */
    public function upsert(int $sesion_id, int $sucursal_id, array $d, ?int $usuario_id): bool
    {
        return (bool) $this->sql->update(
            "IF EXISTS (SELECT 1 FROM [TG].[dbo].[arqueo_concentrado_extras]
                         WHERE sesion_id = ? AND sucursal_id = ?)
                UPDATE [TG].[dbo].[arqueo_concentrado_extras] SET
                    capital_trabajo = ?, gastos_tramite = ?, adeudo = ?,
                    reinversion = ?, utilidad = ?, updated_by = ?, updated_at = GETDATE()
                WHERE sesion_id = ? AND sucursal_id = ?
             ELSE
                INSERT INTO [TG].[dbo].[arqueo_concentrado_extras]
                    (sesion_id, sucursal_id, capital_trabajo, gastos_tramite, adeudo, reinversion, utilidad, updated_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?);",
            [
                $sesion_id, $sucursal_id,
                $d['capital_trabajo'], $d['gastos_tramite'], $d['adeudo'],
                $d['reinversion'], $d['utilidad'], $usuario_id,
                $sesion_id, $sucursal_id,
                $sesion_id, $sucursal_id, $d['capital_trabajo'], $d['gastos_tramite'],
                $d['adeudo'], $d['reinversion'], $d['utilidad'], $usuario_id,
            ]
        );
    }
}
```

- [ ] **Step 2: Verificar sintaxis PHP**

```bash
php -l _assets/models/ArqueoConcentradoExtrasModel.php
```

Expected: `No syntax errors detected in _assets/models/ArqueoConcentradoExtrasModel.php`

- [ ] **Step 3: Verificar manualmente contra la BD (requiere que Task 1 ya esté aplicada en la BD)**

Crea un script temporal en el directorio scratchpad (NO en el repo) para probar el modelo de forma aislada, por ejemplo `C:\Users\ALEJAN~1.MAR\AppData\Local\Temp\claude\...\scratchpad\test_extras_model.php`:

```php
<?php
chdir('C:/Users/alejandro.martinez/Desktop/codigo/AplicativoPhp/.claude/worktrees/arqueo-simplificar-vista');
require_once 'vendor/autoload.php';
require_once '_assets/classes/common/MySqlPdoHandler.class.php';
require_once '_assets/models/Model.php';
require_once '_assets/models/ArqueoConcentradoExtrasModel.php';

$m = new ArqueoConcentradoExtrasModel();
$m->sql->connect('TG');

// Usa un sesion_id y sucursal_id reales que existan en tu BD de pruebas.
$sesionId = 1;
$sucursalId = 99; // id que no exista en SUCURSALES, para no chocar con datos reales

$ok = $m->upsert($sesionId, $sucursalId, [
    'capital_trabajo' => 1000.50,
    'gastos_tramite'  => 200,
    'adeudo'          => 0,
    'reinversion'     => 0,
    'utilidad'        => 500,
], 1);
var_dump($ok); // esperado: true

$rows = $m->by_sesion($sesionId);
var_dump($rows[$sucursalId] ?? 'no encontrado'); // esperado: la fila con los valores de arriba

// segunda llamada para confirmar que actualiza en vez de duplicar
$ok2 = $m->upsert($sesionId, $sucursalId, [
    'capital_trabajo' => 999,
    'gastos_tramite'  => 0,
    'adeudo'          => 0,
    'reinversion'     => 0,
    'utilidad'        => 0,
], 1);
var_dump($ok2); // esperado: true
$rows2 = $m->by_sesion($sesionId);
var_dump($rows2[$sucursalId]['capital_trabajo']); // esperado: "999.00" (actualizó, no duplicó)

// limpieza
$m->sql->update("DELETE FROM [TG].[dbo].[arqueo_concentrado_extras] WHERE sesion_id = ? AND sucursal_id = ?;", [$sesionId, $sucursalId]);
```

Ejecuta con `php test_extras_model.php` desde el scratchpad. Confirma ambos `var_dump` esperados y borra el script temporal al terminar.

- [ ] **Step 4: Commit**

```bash
git add _assets/models/ArqueoConcentradoExtrasModel.php
git commit -m "feat(arqueo): agregar ArqueoConcentradoExtrasModel con upsert por sesion+sucursal"
```

---

## Task 3: Reescribir `Arqueo::concentrado()` y agregar `guardar_concentrado_extra()`

**Files:**
- Modify: `_assets/controllers/arqueo.php`

**Interfaces:**
- Consumes: `ArqueoConcentradoExtrasModel::by_sesion()` y `::upsert()` (Task 2), `$this->cajasModel->by_sesion()` (ya existente), `$this->input()`, `$this->guard()`, `$this->json()`, `$this->user_id()` (helpers ya existentes en la clase).
- Produces: cada elemento de `$concentrado` pasado a la vista tendrá las claves `sucursal_id`, `sucursal`, `capital_trabajo`, `D`, `E`, `F`, `G`, `H`, `gastos_tramite`, `adeudo`, `L`, `reinversion`, `utilidad`, `N`, `O` — Task 4 (vista) las consume con esos nombres exactos. Ruta nueva: `POST /arqueo/guardar_concentrado_extra`.

- [ ] **Step 1: Agregar la propiedad del modelo nuevo al constructor**

En `_assets/controllers/arqueo.php`, localiza el bloque de propiedades (líneas 67-72) y el constructor (líneas 74-82). Cambia:

```php
    public $twig;
    public $route;
    public ArqueoSesionesModel $sesionesModel;
    public ArqueoCajasModel $cajasModel;
    public ArqueoDenominacionesModel $denominacionesModel;
    public ArqueoValesModel $valesModel;

    public function __construct($twig)
    {
        $this->twig                = $twig;
        $this->route               = 'views/arqueo/';
        $this->sesionesModel       = new ArqueoSesionesModel();
        $this->cajasModel          = new ArqueoCajasModel();
        $this->denominacionesModel = new ArqueoDenominacionesModel();
        $this->valesModel          = new ArqueoValesModel();
    }
```

a:

```php
    public $twig;
    public $route;
    public ArqueoSesionesModel $sesionesModel;
    public ArqueoCajasModel $cajasModel;
    public ArqueoDenominacionesModel $denominacionesModel;
    public ArqueoValesModel $valesModel;
    public ArqueoConcentradoExtrasModel $concentradoExtrasModel;

    public function __construct($twig)
    {
        $this->twig                   = $twig;
        $this->route                  = 'views/arqueo/';
        $this->sesionesModel          = new ArqueoSesionesModel();
        $this->cajasModel             = new ArqueoCajasModel();
        $this->denominacionesModel    = new ArqueoDenominacionesModel();
        $this->valesModel             = new ArqueoValesModel();
        $this->concentradoExtrasModel = new ArqueoConcentradoExtrasModel();
    }
```

- [ ] **Step 2: Reescribir el método `concentrado()`**

Localiza el método actual (líneas 345-385):

```php
    public function concentrado($sesion_id): void
    {
        $this->guard([self::PERM_ADMIN]);

        $sesion = $this->sesionesModel->find((int) $sesion_id);
        if (!$sesion) {
            (new Errors())->get404();
            return;
        }
        $cajas = $this->cajasModel->by_sesion((int) $sesion_id);

        $grupos = [];
        foreach ($cajas as $c) {
            $sid = (int) $c['sucursal_id'];
            if (!isset($grupos[$sid])) {
                $grupos[$sid] = [
                    'sucursal_id'                   => $sid,
                    'sucursal'                      => $c['sucursal_nombre'],
                    'total_en_sistema_mn'           => 0,
                    'total_conteo_fisico_sin_vales' => 0,
                    'vales_autorizados'             => 0,
                ];
            }
            $grupos[$sid]['total_en_sistema_mn']           += (float) $c['total_en_sistema'];
            // "Conteo físico sin vales" = arqueo físico en MXN sin sumar vales.
            $grupos[$sid]['total_conteo_fisico_sin_vales'] += (float) $c['total_fisico_mxn'];
            $grupos[$sid]['vales_autorizados']             += (float) $c['gran_total_vales_mxn'];
        }

        // faltantes_sobrantes = sistema - fisico_sin_vales - vales_autorizados
        foreach ($grupos as &$g) {
            $g['faltantes_sobrantes'] =
                $g['total_en_sistema_mn']
                - $g['total_conteo_fisico_sin_vales']
                - $g['vales_autorizados'];
        }
        unset($g);

        $concentrado = array_values($grupos);
        echo $this->twig->render($this->route . 'concentrado.html', compact('sesion', 'concentrado'));
    }
```

Reemplázalo por:

```php
    public function concentrado($sesion_id): void
    {
        $this->guard([self::PERM_ADMIN]);

        $sesion = $this->sesionesModel->find((int) $sesion_id);
        if (!$sesion) {
            (new Errors())->get404();
            return;
        }
        $cajas  = $this->cajasModel->by_sesion((int) $sesion_id);
        $extras = $this->concentradoExtrasModel->by_sesion((int) $sesion_id);

        $grupos = [];
        foreach ($cajas as $c) {
            $sid = (int) $c['sucursal_id'];
            if (!isset($grupos[$sid])) {
                $grupos[$sid] = [
                    'sucursal_id' => $sid,
                    'sucursal'    => $c['sucursal_nombre'],
                    'D'           => 0, // Total en Sistemas M.N.
                    'E'           => 0, // Total Conteo Físico sin Vales
                    'G'           => 0, // Vales Autorizados
                ];
            }
            $grupos[$sid]['D'] += (float) $c['total_en_sistema'];
            $grupos[$sid]['E'] += (float) $c['total_fisico_mxn'];
            $grupos[$sid]['G'] += (float) $c['gran_total_vales_mxn'];
        }

        foreach ($grupos as $sid => &$g) {
            $ex = $extras[$sid] ?? null;
            $g['capital_trabajo'] = (float) ($ex['capital_trabajo'] ?? 0);
            $g['gastos_tramite']  = (float) ($ex['gastos_tramite']  ?? 0);
            $g['adeudo']          = (float) ($ex['adeudo']          ?? 0);
            $g['reinversion']     = (float) ($ex['reinversion']     ?? 0);
            $g['utilidad']        = (float) ($ex['utilidad']        ?? 0);

            // F: Faltantes/Sobrantes sin vales = E - D
            $g['F'] = $g['E'] - $g['D'];
            // H: Faltante Real de Arqueo = E - D + G
            $g['H'] = $g['E'] - $g['D'] + $g['G'];
            // L: Conteo Físico, Vales y Gastos = E + Gastos + Adeudo + G
            $g['L'] = $g['E'] + $g['gastos_tramite'] + $g['adeudo'] + $g['G'];
            // N: Capital, Utilidad y Reinversión = Capital + Reinversión + Utilidad
            $g['N'] = $g['capital_trabajo'] + $g['reinversion'] + $g['utilidad'];
            // O: Variación del Arqueo vs Indicadores D2GO = L - N
            $g['O'] = $g['L'] - $g['N'];
        }
        unset($g);

        $concentrado = array_values($grupos);
        echo $this->twig->render($this->route . 'concentrado.html', compact('sesion', 'concentrado'));
    }
```

- [ ] **Step 3: Agregar el método `guardar_concentrado_extra()`**

Inmediatamente después del método `concentrado()` que acabas de reescribir (antes del bloque `/* === Exportar (admin) === */`), agrega:

```php
    /**
     * Guarda (upsert) los 5 valores manuales de una sucursal en una sesión:
     * Capital de Trabajo, Gastos en trámite, Adeudo, Reinversión, Utilidad.
     * POST JSON: sesion_id, sucursal_id, capital_trabajo, gastos_tramite,
     * adeudo, reinversion, utilidad.
     */
    public function guardar_concentrado_extra(): void
    {
        $this->guard([self::PERM_ADMIN]);
        header('Content-Type: application/json');

        $in          = $this->input();
        $sesion_id   = (int) ($in['sesion_id']   ?? 0);
        $sucursal_id = (int) ($in['sucursal_id'] ?? 0);
        if ($sesion_id <= 0 || $sucursal_id <= 0) {
            $this->json(['success' => false, 'message' => 'Sesión y sucursal son obligatorias.']);
        }

        $datos = [
            'capital_trabajo' => (float) ($in['capital_trabajo'] ?? 0),
            'gastos_tramite'  => (float) ($in['gastos_tramite']  ?? 0),
            'adeudo'          => (float) ($in['adeudo']          ?? 0),
            'reinversion'     => (float) ($in['reinversion']     ?? 0),
            'utilidad'        => (float) ($in['utilidad']        ?? 0),
        ];

        $ok = $this->concentradoExtrasModel->upsert($sesion_id, $sucursal_id, $datos, $this->user_id());
        $this->json(['success' => $ok]);
    }
```

- [ ] **Step 4: Verificar sintaxis PHP**

```bash
php -l _assets/controllers/arqueo.php
```

Expected: `No syntax errors detected in _assets/controllers/arqueo.php`

- [ ] **Step 5: Commit**

```bash
git add _assets/controllers/arqueo.php
git commit -m "feat(arqueo): calcular columnas D-O del Excel en concentrado() y agregar guardar_concentrado_extra()"
```

---

## Task 4: Reescribir `views/arqueo/concentrado.html` (colores, 13 columnas, resumen inferior, modal)

**Files:**
- Modify: `views/arqueo/concentrado.html`

**Interfaces:**
- Consumes: `concentrado` (array de filas con claves `sucursal_id`, `sucursal`, `capital_trabajo`, `D`, `E`, `F`, `G`, `H`, `gastos_tramite`, `adeudo`, `L`, `reinversion`, `utilidad`, `N`, `O` — producidas por Task 3), `sesion` (con `id`, `nombre`, `fecha`).
- Produces: POST AJAX a `/arqueo/guardar_concentrado_extra` con body JSON `{sesion_id, sucursal_id, capital_trabajo, gastos_tramite, adeudo, reinversion, utilidad}` (consumido por el endpoint de Task 3).

- [ ] **Step 1: Reescribir el archivo completo**

Reemplaza todo el contenido de `views/arqueo/concentrado.html` por:

```html
{# views/arqueo/concentrado.html — Consolidado de la sesión #}
{% extends "views/layouts/base.html" %}
{% block title %}Concentrado — {{ sesion.nombre }}{% endblock %}
{% block mycss %}
<style>
    .num { font-variant-numeric:tabular-nums; text-align:right; white-space:nowrap; }
    .falt-negativo { color:#dc3545; font-weight:600; }
    .falt-positivo { color:#198754; font-weight:600; }
    #tabla_concentrado tfoot th { background:#f1f3f5; }
    .th-rojo { background:#83160E !important; color:#fff !important; }
    .th-azul { background:#1C4587 !important; color:#fff !important; }
    .resumen-card { max-width:520px; }
    .resumen-titulo { background:#B0F5F5; font-weight:600; padding:.4rem .75rem; }
    .resumen-total { background:#FFFF00; font-weight:700; }
    .resumen-row td { padding:.3rem .75rem; }
</style>
{% endblock %}
{% block menutitle %}
<div style="display:flex; justify-content:space-between; align-items:center;">
    <span>Concentrado — {{ sesion.nombre }} ({{ sesion.fecha|date('d/m/Y') }})</span>
    <a href="/arqueo" class="btn btn-primary text-light small" style="border-radius:15px;">
        <i class="fas fa-backward"></i> Regresar
    </a>
</div>
{% endblock %}
{% block content %}

<div class="card mb-3">
  <div class="card-body table-responsive">
    <table id="tabla_concentrado" class="table table-bordered table-hover table-sm" style="width:100%;">
      <thead>
        <tr>
          <th>Sucursal</th>
          <th class="text-end th-rojo">Capital de Trabajo</th>
          <th class="text-end th-rojo">Total en Sistema M.N.</th>
          <th class="text-end th-azul">Conteo Físico sin Vales</th>
          <th class="text-end th-azul">Faltantes(-)/Sobrantes(+) sin Vales</th>
          <th class="text-end th-azul">Vales Autorizados</th>
          <th class="text-end th-azul">Faltante Real de Arqueo</th>
          <th class="text-end th-rojo">Gastos en Trámite</th>
          <th class="text-end th-rojo">Adeudo</th>
          <th class="text-end th-rojo">Reinversión</th>
          <th class="text-end th-rojo">Conteo Físico, Vales y Gastos</th>
          <th class="text-end th-rojo">Utilidad</th>
          <th class="text-end th-rojo">Capital, Utilidad y Reinversión</th>
          <th class="text-end th-azul">Variación vs Indicadores D2GO</th>
          <th>Editar</th>
        </tr>
      </thead>
      <tbody>
        {% set t_capital = 0 %}
        {% set t_D = 0 %}
        {% set t_E = 0 %}
        {% set t_F = 0 %}
        {% set t_G = 0 %}
        {% set t_H = 0 %}
        {% set t_gastos = 0 %}
        {% set t_adeudo = 0 %}
        {% set t_L = 0 %}
        {% set t_reinv = 0 %}
        {% set t_util = 0 %}
        {% set t_N = 0 %}
        {% set t_O = 0 %}
        {% for g in concentrado %}
        {% set t_capital = t_capital + g.capital_trabajo %}
        {% set t_D = t_D + g.D %}
        {% set t_E = t_E + g.E %}
        {% set t_F = t_F + g.F %}
        {% set t_G = t_G + g.G %}
        {% set t_H = t_H + g.H %}
        {% set t_gastos = t_gastos + g.gastos_tramite %}
        {% set t_adeudo = t_adeudo + g.adeudo %}
        {% set t_L = t_L + g.L %}
        {% set t_reinv = t_reinv + g.reinversion %}
        {% set t_util = t_util + g.utilidad %}
        {% set t_N = t_N + g.N %}
        {% set t_O = t_O + g.O %}
        <tr>
          <td>{{ g.sucursal }}</td>
          <td class="num">{{ g.capital_trabajo|number_format(2,'.',',') }}</td>
          <td class="num">{{ g.D|number_format(2,'.',',') }}</td>
          <td class="num">{{ g.E|number_format(2,'.',',') }}</td>
          <td class="num {{ g.F < 0 ? 'falt-negativo' : 'falt-positivo' }}">{{ g.F|number_format(2,'.',',') }}</td>
          <td class="num">{{ g.G|number_format(2,'.',',') }}</td>
          <td class="num {{ g.H < 0 ? 'falt-negativo' : 'falt-positivo' }}">{{ g.H|number_format(2,'.',',') }}</td>
          <td class="num">{{ g.gastos_tramite|number_format(2,'.',',') }}</td>
          <td class="num">{{ g.adeudo|number_format(2,'.',',') }}</td>
          <td class="num">{{ g.reinversion|number_format(2,'.',',') }}</td>
          <td class="num">{{ g.L|number_format(2,'.',',') }}</td>
          <td class="num">{{ g.utilidad|number_format(2,'.',',') }}</td>
          <td class="num">{{ g.N|number_format(2,'.',',') }}</td>
          <td class="num {{ g.O < 0 ? 'falt-negativo' : 'falt-positivo' }}">{{ g.O|number_format(2,'.',',') }}</td>
          <td class="text-center">
            <button type="button" class="btn btn-sm btn-outline-primary"
                    onclick="abrirModalExtra({{ g.sucursal_id }}, '{{ g.sucursal|e('js') }}', {{ g.capital_trabajo }}, {{ g.gastos_tramite }}, {{ g.adeudo }}, {{ g.reinversion }}, {{ g.utilidad }})">
              <i class="fas fa-edit"></i>
            </button>
          </td>
        </tr>
        {% endfor %}
      </tbody>
      <tfoot>
        <tr>
          <th>Totales</th>
          <th class="num">{{ t_capital|number_format(2,'.',',') }}</th>
          <th class="num">{{ t_D|number_format(2,'.',',') }}</th>
          <th class="num">{{ t_E|number_format(2,'.',',') }}</th>
          <th class="num {{ t_F < 0 ? 'falt-negativo' : 'falt-positivo' }}">{{ t_F|number_format(2,'.',',') }}</th>
          <th class="num">{{ t_G|number_format(2,'.',',') }}</th>
          <th class="num {{ t_H < 0 ? 'falt-negativo' : 'falt-positivo' }}">{{ t_H|number_format(2,'.',',') }}</th>
          <th class="num">{{ t_gastos|number_format(2,'.',',') }}</th>
          <th class="num">{{ t_adeudo|number_format(2,'.',',') }}</th>
          <th class="num">{{ t_reinv|number_format(2,'.',',') }}</th>
          <th class="num">{{ t_L|number_format(2,'.',',') }}</th>
          <th class="num">{{ t_util|number_format(2,'.',',') }}</th>
          <th class="num">{{ t_N|number_format(2,'.',',') }}</th>
          <th class="num {{ t_O < 0 ? 'falt-negativo' : 'falt-positivo' }}">{{ t_O|number_format(2,'.',',') }}</th>
          <th></th>
        </tr>
      </tfoot>
    </table>
  </div>
</div>

<div class="card resumen-card">
  <div class="card-body p-0">
    <table class="table table-borderless table-sm mb-0">
      <tr class="resumen-row"><td colspan="2" class="resumen-titulo">Resumen del arqueo vs Indicadores D2GO — {{ sesion.fecha|date('d/m/Y') }}</td></tr>
      <tr class="resumen-row"><td>Capital de Trabajo</td><td class="num">{{ t_capital|number_format(2,'.',',') }}</td></tr>
      <tr class="resumen-row"><td>Utilidad</td><td class="num">{{ t_util|number_format(2,'.',',') }}</td></tr>
      <tr class="resumen-row"><td>Reinversión</td><td class="num">{{ t_reinv|number_format(2,'.',',') }}</td></tr>
      {% set total1 = t_capital + t_reinv + t_util %}
      <tr class="resumen-row resumen-total"><td>Total</td><td class="num">{{ total1|number_format(2,'.',',') }}</td></tr>

      <tr class="resumen-row"><td colspan="2" class="resumen-titulo">Resultado de Arqueo</td></tr>
      <tr class="resumen-row"><td>Conteo Físico</td><td class="num">{{ t_E|number_format(2,'.',',') }}</td></tr>
      <tr class="resumen-row"><td>Vales</td><td class="num">{{ t_G|number_format(2,'.',',') }}</td></tr>
      <tr class="resumen-row"><td>Gastos</td><td class="num">{{ t_gastos|number_format(2,'.',',') }}</td></tr>
      <tr class="resumen-row"><td>Adeudo</td><td class="num">{{ t_adeudo|number_format(2,'.',',') }}</td></tr>
      {% set total2 = t_E + t_G + t_gastos + t_adeudo %}
      <tr class="resumen-row resumen-total"><td>Total</td><td class="num">{{ total2|number_format(2,'.',',') }}</td></tr>

      <tr class="resumen-row"><td colspan="2" class="resumen-titulo">Diferencia</td></tr>
      {% set faltante = total2 - total1 %}
      <tr class="resumen-row resumen-total"><td>Faltante</td><td class="num {{ faltante < 0 ? 'falt-negativo' : 'falt-positivo' }}">{{ faltante|number_format(2,'.',',') }}</td></tr>
    </table>
  </div>
</div>

<!-- Modal de captura manual -->
<div class="modal fade" id="modal_concentrado_extra" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Editar valores — <span id="mx_sucursal_nombre"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="mx_sucursal_id">
        <div class="mb-3">
          <label class="form-label">Capital de Trabajo</label>
          <input type="number" step="0.01" class="form-control" id="mx_capital_trabajo">
        </div>
        <div class="mb-3">
          <label class="form-label">Gastos en trámite o por cancelar</label>
          <input type="number" step="0.01" class="form-control" id="mx_gastos_tramite">
        </div>
        <div class="mb-3">
          <label class="form-label">Adeudo</label>
          <input type="number" step="0.01" class="form-control" id="mx_adeudo">
        </div>
        <div class="mb-3">
          <label class="form-label">Reinversión</label>
          <input type="number" step="0.01" class="form-control" id="mx_reinversion">
        </div>
        <div class="mb-3">
          <label class="form-label">Utilidad</label>
          <input type="number" step="0.01" class="form-control" id="mx_utilidad">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-primary" onclick="guardarModalExtra()">Guardar</button>
      </div>
    </div>
  </div>
</div>

{% endblock %}
{% block myjs %}
<script>
  $(function () {
    $('#tabla_concentrado').DataTable({
      paging: false,
      searching: false,
      info: false,
      ordering: true,
      dom: 'Bfrtip',
      buttons: ['excel', 'print']
    });
  });

  function abrirModalExtra(sucursalId, sucursalNombre, capital, gastos, adeudo, reinversion, utilidad) {
    document.getElementById('mx_sucursal_id').value = sucursalId;
    document.getElementById('mx_sucursal_nombre').textContent = sucursalNombre;
    document.getElementById('mx_capital_trabajo').value = capital;
    document.getElementById('mx_gastos_tramite').value = gastos;
    document.getElementById('mx_adeudo').value = adeudo;
    document.getElementById('mx_reinversion').value = reinversion;
    document.getElementById('mx_utilidad').value = utilidad;
    $('#modal_concentrado_extra').modal('show');
  }

  function guardarModalExtra() {
    const payload = {
      sesion_id: {{ sesion.id }},
      sucursal_id: parseInt(document.getElementById('mx_sucursal_id').value, 10),
      capital_trabajo: parseFloat(document.getElementById('mx_capital_trabajo').value) || 0,
      gastos_tramite: parseFloat(document.getElementById('mx_gastos_tramite').value) || 0,
      adeudo: parseFloat(document.getElementById('mx_adeudo').value) || 0,
      reinversion: parseFloat(document.getElementById('mx_reinversion').value) || 0,
      utilidad: parseFloat(document.getElementById('mx_utilidad').value) || 0,
    };
    fetch('/arqueo/guardar_concentrado_extra', {
      method: 'POST',
      headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
      body: JSON.stringify(payload)
    })
      .then(r => r.json())
      .then(d => { if (d.success) location.reload(); else alert(d.message || 'No se pudo guardar.'); })
      .catch(() => alert('Error de red al guardar.'));
  }
</script>
{% endblock %}
```

- [ ] **Step 2: Validar sintaxis Twig**

Crea un script temporal en el scratchpad (NO en el repo), por ejemplo `C:\Users\ALEJAN~1.MAR\AppData\Local\Temp\claude\...\scratchpad\check_twig.php`:

```php
<?php
chdir('C:/Users/alejandro.martinez/Desktop/codigo/AplicativoPhp/.claude/worktrees/arqueo-simplificar-vista');
require_once 'vendor/autoload.php';

$loader = new \Twig\Loader\FilesystemLoader('.');
$twig = new \Twig\Environment($loader);

$src = file_get_contents('views/arqueo/concentrado.html');
try {
    $twig->parse($twig->tokenize(new \Twig\Source($src, 'concentrado.html')));
    echo "OK: sintaxis Twig valida\n";
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
```

Ejecuta `php check_twig.php` desde el scratchpad. Expected: `OK: sintaxis Twig valida`.

- [ ] **Step 3: Probar en navegador (el usuario ya tiene el servidor corriendo)**

No inicies ni reinicies el servidor — pide al usuario que confirme que ya está corriendo, o navega asumiendo que está activo. Pasos de prueba manual:
1. Ir a `/arqueo/concentrado/{sesion_id}` con un `sesion_id` real que tenga cajas con datos.
2. Confirmar que la tabla muestra 14 columnas + "Editar", con encabezados rojos en Capital/Gastos/Adeudo/Reinversión/Conteo+Vales+Gastos/Utilidad/Capital+Util+Reinv, y azules en Conteo Físico/Faltantes sin vales/Vales/Faltante Real/Variación.
3. Click en el botón "Editar" de una fila — el modal debe abrir con el nombre de sucursal correcto y los 5 campos precargados (0 si nunca se capturó, o el valor sembrado si es una de las 2 sesiones existentes).
4. Cambiar los 5 valores y dar "Guardar" — la página debe recargar y mostrar los nuevos valores en la fila, además de recalcular F, H, L, N, O y los totales del resumen inferior.
5. Confirmar que el resumen inferior muestra los 3 bloques (Capital/Utilidad/Reinversión, Conteo Físico/Vales/Gastos/Adeudo, Faltante) y que el último "Faltante" coincide con el total de la columna "Variación vs Indicadores D2GO".

- [ ] **Step 4: Commit**

```bash
git add views/arqueo/concentrado.html
git commit -m "feat(arqueo): igualar vista Concentrado a columnas/colores del Excel y agregar modal de captura manual"
```

---

## Self-Review Notes

- **Spec coverage:** tabla nueva (Task 1), modelo (Task 2), fórmulas D-O + endpoint (Task 3), vista/colores/modal/resumen inferior (Task 4), seed de Capital de Trabajo para las 2 sesiones existentes (Task 1, Step 3-4). Todo lo descrito en el spec `docs/superpowers/specs/2026-06-26-concentrado-extras-modal-design.md` tiene tarea correspondiente.
- **Placeholder scan:** sin TBD/TODO; cada paso de código trae el código completo a escribir.
- **Type consistency:** las claves `D, E, F, G, H, L, N, O, capital_trabajo, gastos_tramite, adeudo, reinversion, utilidad, sucursal_id, sucursal` se usan exactamente igual en Task 3 (controlador) y Task 4 (vista).
- **No-Goals respetados:** no se crea permiso nuevo, no se recalculan columnas en JS (se recarga la página), no se toca `exportar()`.
