# Page Visits Dashboard — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Registrar cuántas veces cada usuario visita cada página del sistema y mostrar un dashboard de uso en SISTEMAS → Herramientas → Dashboard de uso.

**Architecture:** Se extiende `Twig\Environment` con una subclase `TgTwig` que intercepta `render()` para hacer un MERGE (upsert) en la tabla `[TG].[dbo].[tg_page_visits]`. El dashboard se agrega como un método nuevo en el controlador `It` con su vista Twig correspondiente. El tracking es completamente transparente para todos los controladores existentes.

**Tech Stack:** PHP 8, SQL Server (sqlsrv/PDO), Twig 3, Bootstrap 5, jQuery, DataTables

---

## File Map

| Acción | Archivo | Responsabilidad |
|--------|---------|-----------------|
| Crear | `_assets/classes/TgTwig.class.php` | Subclase de Twig que intercepta render() para trackear |
| Crear | `_assets/models/PageVisitsModel.php` | MERGE upsert + 4 queries del dashboard |
| Modificar | `_assets/classes/twig_functions.php` | Cambiar `new \Twig\Environment` por `new TgTwig` |
| Modificar | `index.php` | Asignar `$twig->trackController` y `$twig->trackMethod` |
| Modificar | `_assets/controllers/it.php` | Agregar método `page_visits_dashboard()` |
| Crear | `views/it/page_visits_dashboard.html` | Vista del dashboard con 4 paneles |
| Modificar | `views/layouts/sidebar.html` | Agregar link "Dashboard de uso" |

---

## Task 1: Crear la tabla SQL en la base de datos TG

**Files:**
- No hay archivo de código — es un script SQL que se ejecuta directamente en el servidor

- [ ] **Step 1: Ejecutar el script SQL en la base de datos TG**

Conectar a `192.168.0.6` → base de datos `TG` y ejecutar:

```sql
CREATE TABLE [dbo].[tg_page_visits] (
    id           BIGINT IDENTITY(1,1) PRIMARY KEY,
    user_id      INT           NOT NULL,
    username     VARCHAR(100)  NOT NULL,
    controller   VARCHAR(100)  NOT NULL,
    method       VARCHAR(100)  NOT NULL,
    visit_date   DATE          NOT NULL DEFAULT CAST(GETDATE() AS DATE),
    visit_count  INT           NOT NULL DEFAULT 1,
    CONSTRAINT uq_page_visit UNIQUE (user_id, controller, method, visit_date)
);
```

- [ ] **Step 2: Verificar que la tabla fue creada**

```sql
SELECT TOP 1 * FROM [TG].[dbo].[tg_page_visits];
-- Esperado: "The query returned no rows" (tabla vacía, sin error)
```

---

## Task 2: Crear `PageVisitsModel.php`

**Files:**
- Crear: `_assets/models/PageVisitsModel.php`

- [ ] **Step 1: Crear el archivo del modelo**

Crear `_assets/models/PageVisitsModel.php` con el siguiente contenido:

```php
<?php
class PageVisitsModel extends Model {

    public static function upsertVisit(string $controller, string $method): void {
        if (!isset($_SESSION['tg_user'])) return;
        try {
            $sql = MySqlPdoHandler::getInstance();
            $sql->connect('SG12');
            $query = "
                MERGE INTO [TG].[dbo].[tg_page_visits] WITH (HOLDLOCK) AS target
                USING (
                    SELECT ? AS user_id, ? AS username, ? AS controller, ? AS method,
                           CAST(GETDATE() AS DATE) AS visit_date
                ) AS source
                ON  target.user_id    = source.user_id
                AND target.controller = source.controller
                AND target.method     = source.method
                AND target.visit_date = source.visit_date
                WHEN MATCHED THEN
                    UPDATE SET visit_count = target.visit_count + 1
                WHEN NOT MATCHED THEN
                    INSERT (user_id, username, controller, method, visit_date, visit_count)
                    VALUES (source.user_id, source.username, source.controller,
                            source.method, source.visit_date, 1);
            ";
            $sql->insert($query, [
                (int)$_SESSION['tg_user']['Id'],
                $_SESSION['tg_user']['Usuario'] ?? 'desconocido',
                $controller,
                $method,
            ]);
        } catch (\Throwable $e) {
            // Silencioso: no romper la app si falla el tracking
        }
    }

    public function getTopPages(string $from, string $to): array {
        $query = "
            SELECT controller, method,
                   SUM(visit_count)        AS total_visits,
                   COUNT(DISTINCT user_id) AS unique_users
            FROM [TG].[dbo].[tg_page_visits]
            WHERE visit_date BETWEEN ? AND ?
            GROUP BY controller, method
            ORDER BY total_visits DESC;
        ";
        return $this->sql->select($query, [$from, $to]) ?? [];
    }

    public function getTopUsers(string $from, string $to): array {
        $query = "
            SELECT user_id, username,
                   SUM(visit_count)        AS total_visits,
                   COUNT(DISTINCT CONCAT(controller, '/', method)) AS pages_visited
            FROM [TG].[dbo].[tg_page_visits]
            WHERE visit_date BETWEEN ? AND ?
            GROUP BY user_id, username
            ORDER BY total_visits DESC;
        ";
        return $this->sql->select($query, [$from, $to]) ?? [];
    }

    public function getPagesReach(string $from, string $to): array {
        $query = "
            SELECT controller, method,
                   COUNT(DISTINCT user_id) AS unique_users,
                   SUM(visit_count)        AS total_visits
            FROM [TG].[dbo].[tg_page_visits]
            WHERE visit_date BETWEEN ? AND ?
            GROUP BY controller, method
            ORDER BY unique_users DESC;
        ";
        return $this->sql->select($query, [$from, $to]) ?? [];
    }

    public function getUnusedInPeriod(string $from, string $to): array {
        $query = "
            SELECT controller, method,
                   MAX(visit_date)                                          AS last_visit,
                   DATEDIFF(day, MAX(visit_date), CAST(GETDATE() AS DATE)) AS days_inactive
            FROM [TG].[dbo].[tg_page_visits]
            WHERE CONCAT(controller, '/', method) NOT IN (
                SELECT CONCAT(controller, '/', method)
                FROM [TG].[dbo].[tg_page_visits]
                WHERE visit_date BETWEEN ? AND ?
                GROUP BY controller, method
            )
            GROUP BY controller, method
            ORDER BY days_inactive DESC;
        ";
        return $this->sql->select($query, [$from, $to, $from, $to]) ?? [];
    }
}
```

- [ ] **Step 2: Verificar sintaxis PHP**

```bash
php -l _assets/models/PageVisitsModel.php
```

Esperado: `No syntax errors detected in _assets/models/PageVisitsModel.php`

- [ ] **Step 3: Commit**

```bash
git add _assets/models/PageVisitsModel.php
git commit -m "feat: add PageVisitsModel with upsert and dashboard queries"
```

---

## Task 3: Crear `TgTwig` y actualizar `twig_functions.php`

**Files:**
- Crear: `_assets/classes/TgTwig.class.php`
- Modificar: `_assets/classes/twig_functions.php`

- [ ] **Step 1: Crear `_assets/classes/TgTwig.class.php`**

```php
<?php
class TgTwig extends \Twig\Environment {
    public string $trackController = '';
    public string $trackMethod     = '';

    public function render($name, array $context = []): string {
        if ($this->trackController !== '' && $this->trackMethod !== '') {
            PageVisitsModel::upsertVisit($this->trackController, $this->trackMethod);
        }
        return parent::render($name, $context);
    }
}
```

- [ ] **Step 2: Verificar sintaxis PHP**

```bash
php -l _assets/classes/TgTwig.class.php
```

Esperado: `No syntax errors detected in _assets/classes/TgTwig.class.php`

- [ ] **Step 3: Actualizar `twig_functions.php`**

Leer el archivo y encontrar la línea donde se instancia `\Twig\Environment`. Agregar el `require_once` antes de esa línea y cambiar la clase.

Buscar en `_assets/classes/twig_functions.php` la línea que contiene:
```php
$twig = new \Twig\Environment(
```

Reemplazarla por:
```php
require_once __DIR__ . '/TgTwig.class.php';
require_once __DIR__ . '/../models/PageVisitsModel.php';
$twig = new TgTwig(
```

- [ ] **Step 4: Verificar sintaxis del archivo modificado**

```bash
php -l _assets/classes/twig_functions.php
```

Esperado: `No syntax errors detected`

- [ ] **Step 5: Commit**

```bash
git add _assets/classes/TgTwig.class.php _assets/classes/twig_functions.php
git commit -m "feat: add TgTwig subclass for automatic page visit tracking"
```

---

## Task 4: Actualizar `index.php` para asignar controller y method al objeto twig

**Files:**
- Modificar: `index.php`

- [ ] **Step 1: Localizar el bloque correcto en `index.php`**

El bloque que nos interesa está entre las líneas ~72-91. Después de `define('CONTROLLER', ...)` y antes del `call_user_func`, se asignan las propiedades al objeto `$twig`.

- [ ] **Step 2: Editar `index.php`**

Buscar este bloque (alrededor de la línea 72):

```php
if (file_exists(CONTROLLERS . $controller . '.php')) {
    define('CONTROLLER', $controller . DS);
    $twig->addGlobal('CONTROLLER', $controller);
```

Agregar las dos líneas después de `$twig->addGlobal(...)`:

```php
if (file_exists(CONTROLLERS . $controller . '.php')) {
    define('CONTROLLER', $controller . DS);
    $twig->addGlobal('CONTROLLER', $controller);
    $twig->trackController = $controller;
    $twig->trackMethod     = $method;
```

- [ ] **Step 3: Verificar sintaxis**

```bash
php -l index.php
```

Esperado: `No syntax errors detected in index.php`

- [ ] **Step 4: Prueba de humo manual**

Iniciar el servidor de desarrollo:
```bash
php -S localhost:8000 router.php
```

Navegar a cualquier página del sistema (ej. `/it/date_to_int`). Luego verificar en SQL Server:

```sql
SELECT * FROM [TG].[dbo].[tg_page_visits] ORDER BY id DESC;
```

Esperado: un registro con `controller = 'it'`, `method = 'date_to_int'`, `visit_count = 1`.

Navegar a la misma página de nuevo y re-ejecutar la query. Esperado: `visit_count = 2`.

- [ ] **Step 5: Commit**

```bash
git add index.php
git commit -m "feat: wire controller/method tracking into TgTwig instance"
```

---

## Task 5: Agregar método `page_visits_dashboard` al controlador `It`

**Files:**
- Modificar: `_assets/controllers/it.php`

- [ ] **Step 1: Agregar la propiedad del modelo en el constructor de `It`**

En `_assets/controllers/it.php`, dentro de la declaración de propiedades de la clase (cerca de la línea 8), agregar:

```php
public PageVisitsModel $pageVisitsModel;
```

Y dentro del constructor (`__construct`), al final de las asignaciones, agregar:

```php
$this->pageVisitsModel = new PageVisitsModel;
```

- [ ] **Step 2: Agregar el método `page_visits_dashboard`**

Al final de la clase `It` (antes del cierre `}`), agregar:

```php
public function page_visits_dashboard(): void {
    $allowed = [6382, 6371, 6177, 6296, 6274];
    if (!in_array((int)$_SESSION['tg_user']['Id'], $allowed)) {
        (new Errors())->get404();
        return;
    }

    $to   = $_GET['to']   ?? date('Y-m-d');
    $from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));

    $top_pages    = $this->pageVisitsModel->getTopPages($from, $to);
    $top_users    = $this->pageVisitsModel->getTopUsers($from, $to);
    $pages_reach  = $this->pageVisitsModel->getPagesReach($from, $to);
    $unused_pages = $this->pageVisitsModel->getUnusedInPeriod($from, $to);

    echo $this->twig->render($this->route . 'page_visits_dashboard.html', compact(
        'top_pages', 'top_users', 'pages_reach', 'unused_pages', 'from', 'to'
    ));
}
```

- [ ] **Step 3: Verificar sintaxis**

```bash
php -l _assets/controllers/it.php
```

Esperado: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
git add _assets/controllers/it.php
git commit -m "feat: add page_visits_dashboard method to It controller"
```

---

## Task 6: Crear la vista del dashboard

**Files:**
- Crear: `views/it/page_visits_dashboard.html`

- [ ] **Step 1: Crear el archivo de vista**

Crear `views/it/page_visits_dashboard.html` con el siguiente contenido:

```twig
{% extends "views/layouts/base.html" %}
{% block title %}Dashboard de Uso{% endblock %}
{% block menutitle %}Dashboard de Uso del Sistema{% endblock %}

{% block mycss %}
<style>
    .stat-card { border-left: 4px solid; }
    .stat-card.blue  { border-color: #3B7DDD; }
    .stat-card.green { border-color: #28a745; }
    .stat-card.orange{ border-color: #fd7e14; }
    .stat-card.red   { border-color: #dc3545; }
    .table th { font-size: 0.82rem; }
    .table td { font-size: 0.85rem; }
    .badge-page { font-family: monospace; font-size: 0.78rem; background: #e9ecef; color: #495057; padding: 2px 6px; border-radius: 4px; }
</style>
{% endblock %}

{% block content %}

{# ── Filtro de fechas ── #}
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" action="/it/page_visits_dashboard" class="row g-3 align-items-end">
            <div class="col-auto">
                <label class="form-label fw-semibold">Desde</label>
                <input type="date" name="from" class="form-control" value="{{ from }}">
            </div>
            <div class="col-auto">
                <label class="form-label fw-semibold">Hasta</label>
                <input type="date" name="to" class="form-control" value="{{ to }}">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary">Filtrar</button>
            </div>
            <div class="col-auto text-muted small align-self-center">
                Mostrando del <strong>{{ from }}</strong> al <strong>{{ to }}</strong>
            </div>
        </form>
    </div>
</div>

{# ── Tarjetas de resumen ── #}
<div class="row mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card blue">
            <div class="card-body">
                <div class="row">
                    <div class="col mt-0"><h5 class="card-title text-muted">Páginas únicas visitadas</h5></div>
                    <div class="col-auto"><i data-feather="bar-chart-2" class="text-primary" style="width:32px;height:32px"></i></div>
                </div>
                <h1 class="display-5 fw-normal mt-1 mb-3">{{ top_pages | length }}</h1>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card green">
            <div class="card-body">
                <div class="row">
                    <div class="col mt-0"><h5 class="card-title text-muted">Usuarios activos</h5></div>
                    <div class="col-auto"><i data-feather="users" class="text-success" style="width:32px;height:32px"></i></div>
                </div>
                <h1 class="display-5 fw-normal mt-1 mb-3">{{ top_users | length }}</h1>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card orange">
            <div class="card-body">
                <div class="row">
                    <div class="col mt-0"><h5 class="card-title text-muted">Total de visitas</h5></div>
                    <div class="col-auto"><i data-feather="activity" class="text-warning" style="width:32px;height:32px"></i></div>
                </div>
                {% set total = namespace(v=0) %}
                {% for p in top_pages %}{% set total.v = total.v + p.total_visits %}{% endfor %}
                <h1 class="display-5 fw-normal mt-1 mb-3">{{ total.v | number_format(0, '.', ',') }}</h1>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card red">
            <div class="card-body">
                <div class="row">
                    <div class="col mt-0"><h5 class="card-title text-muted">Páginas sin uso en período</h5></div>
                    <div class="col-auto"><i data-feather="alert-circle" class="text-danger" style="width:32px;height:32px"></i></div>
                </div>
                <h1 class="display-5 fw-normal mt-1 mb-3">{{ unused_pages | length }}</h1>
            </div>
        </div>
    </div>
</div>

<div class="row">

    {# ── Panel 1: Top páginas ── #}
    <div class="col-xl-6 mb-4">
        <div class="card h-100">
            <div class="card-header"><h5 class="card-title mb-0"><i data-feather="bar-chart-2" class="me-2"></i>Páginas más visitadas</h5></div>
            <div class="card-body p-0">
                <table class="table table-hover table-sm mb-0">
                    <thead class="table-light">
                        <tr><th>#</th><th>Página</th><th class="text-end">Visitas</th><th class="text-end">Usuarios únicos</th></tr>
                    </thead>
                    <tbody>
                        {% for p in top_pages %}
                        <tr>
                            <td class="text-muted">{{ loop.index }}</td>
                            <td><span class="badge-page">{{ p.controller }}/{{ p.method }}</span></td>
                            <td class="text-end fw-semibold">{{ p.total_visits | number_format(0, '.', ',') }}</td>
                            <td class="text-end">{{ p.unique_users }}</td>
                        </tr>
                        {% else %}
                        <tr><td colspan="4" class="text-center text-muted py-3">Sin datos para el período</td></tr>
                        {% endfor %}
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {# ── Panel 2: Usuarios más activos ── #}
    <div class="col-xl-6 mb-4">
        <div class="card h-100">
            <div class="card-header"><h5 class="card-title mb-0"><i data-feather="users" class="me-2"></i>Usuarios más activos</h5></div>
            <div class="card-body p-0">
                <table class="table table-hover table-sm mb-0">
                    <thead class="table-light">
                        <tr><th>#</th><th>Usuario</th><th class="text-end">Visitas totales</th><th class="text-end">Páginas distintas</th></tr>
                    </thead>
                    <tbody>
                        {% for u in top_users %}
                        <tr>
                            <td class="text-muted">{{ loop.index }}</td>
                            <td>{{ u.username }}</td>
                            <td class="text-end fw-semibold">{{ u.total_visits | number_format(0, '.', ',') }}</td>
                            <td class="text-end">{{ u.pages_visited }}</td>
                        </tr>
                        {% else %}
                        <tr><td colspan="4" class="text-center text-muted py-3">Sin datos para el período</td></tr>
                        {% endfor %}
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {# ── Panel 3: Páginas con más alcance ── #}
    <div class="col-xl-6 mb-4">
        <div class="card h-100">
            <div class="card-header"><h5 class="card-title mb-0"><i data-feather="globe" class="me-2"></i>Páginas con más alcance (usuarios únicos)</h5></div>
            <div class="card-body p-0">
                <table class="table table-hover table-sm mb-0">
                    <thead class="table-light">
                        <tr><th>#</th><th>Página</th><th class="text-end">Usuarios únicos</th><th class="text-end">Visitas totales</th></tr>
                    </thead>
                    <tbody>
                        {% for p in pages_reach %}
                        <tr>
                            <td class="text-muted">{{ loop.index }}</td>
                            <td><span class="badge-page">{{ p.controller }}/{{ p.method }}</span></td>
                            <td class="text-end fw-semibold">{{ p.unique_users }}</td>
                            <td class="text-end">{{ p.total_visits | number_format(0, '.', ',') }}</td>
                        </tr>
                        {% else %}
                        <tr><td colspan="4" class="text-center text-muted py-3">Sin datos para el período</td></tr>
                        {% endfor %}
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {# ── Panel 4: Páginas sin uso en el período ── #}
    <div class="col-xl-6 mb-4">
        <div class="card h-100">
            <div class="card-header"><h5 class="card-title mb-0"><i data-feather="alert-circle" class="me-2"></i>Páginas sin uso en el período seleccionado</h5></div>
            <div class="card-body p-0">
                <table class="table table-hover table-sm mb-0">
                    <thead class="table-light">
                        <tr><th>#</th><th>Página</th><th class="text-end">Última visita</th><th class="text-end">Días inactiva</th></tr>
                    </thead>
                    <tbody>
                        {% for p in unused_pages %}
                        <tr>
                            <td class="text-muted">{{ loop.index }}</td>
                            <td><span class="badge-page">{{ p.controller }}/{{ p.method }}</span></td>
                            <td class="text-end">{{ p.last_visit }}</td>
                            <td class="text-end">
                                <span class="badge {% if p.days_inactive > 30 %}bg-danger{% elseif p.days_inactive > 7 %}bg-warning text-dark{% else %}bg-secondary{% endif %}">
                                    {{ p.days_inactive }} días
                                </span>
                            </td>
                        </tr>
                        {% else %}
                        <tr><td colspan="4" class="text-center text-muted py-3">¡Todas las páginas fueron visitadas en este período!</td></tr>
                        {% endfor %}
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>
{% endblock %}

{% block myjs %}
{% endblock %}
```

- [ ] **Step 2: Verificar que la ruta funciona**

Con el servidor corriendo (`php -S localhost:8000 router.php`), navegar a:
```
http://localhost:8000/it/page_visits_dashboard
```

Esperado: la página carga sin errores con los 4 paneles y el filtro de fechas visible. Los paneles pueden estar vacíos si aún no hay datos — eso es correcto.

- [ ] **Step 3: Commit**

```bash
git add views/it/page_visits_dashboard.html
git commit -m "feat: add page visits dashboard view with 4 analytics panels"
```

---

## Task 7: Agregar link en el sidebar

**Files:**
- Modificar: `views/layouts/sidebar.html`

- [ ] **Step 1: Localizar el bloque correcto en sidebar.html**

Buscar el bloque de SISTEMAS → Herramientas. El bloque relevante está alrededor de la línea 656 y se ve así:

```html
{% if for_sistemas() %}
<li class="sidebar-item">
    <a class="sidebar-link" href="/it/retardos">Retardos</a>
</li>
{% endif %}
```

- [ ] **Step 2: Agregar el link del dashboard**

Después del link de Retardos (dentro del mismo bloque `{% if for_sistemas() %}`), agregar:

```html
{% if for_sistemas() %}
<li class="sidebar-item">
    <a class="sidebar-link" href="/it/retardos">Retardos</a>
</li>
<li class="sidebar-item">
    <a class="sidebar-link" href="/it/page_visits_dashboard">Dashboard de uso</a>
</li>
{% endif %}
```

- [ ] **Step 3: Verificar en el navegador**

Recargar cualquier página del sistema. En el sidebar, bajo `SISTEMAS → Herramientas`, debe aparecer el link **"Dashboard de uso"** (solo visible para usuarios con `for_sistemas()`).

- [ ] **Step 4: Commit final**

```bash
git add views/layouts/sidebar.html
git commit -m "feat: add Dashboard de uso link to SISTEMAS sidebar section"
```

---

## Verificación end-to-end

Una vez completadas todas las tareas:

- [ ] Navegar 3-4 páginas distintas del sistema
- [ ] Ir a `/it/page_visits_dashboard`
- [ ] Verificar que el Panel 1 (Top páginas) muestra las páginas visitadas con sus conteos
- [ ] Navegar la misma página 2 veces y confirmar que `visit_count` incrementa (no duplica filas)
- [ ] Cambiar el rango de fechas en el filtro y confirmar que los paneles se actualizan
- [ ] Verificar que el Panel 4 (sin uso) muestra páginas visitadas antes del rango seleccionado
