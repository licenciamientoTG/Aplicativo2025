# Zonas en el Reporte de Ventas Consolidado — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Dividir `/merma/ventas` en 3 tabs de zona (MARCA Y PROTS, TSA AGS, ZONA 3), cada uno con
sus propias 5 pestañas de producto + Histórico, replicando los 3 libros Excel reales
(`VETS X EST X MARCA Y PROTS`, `VETS X EST X TSA AGS`, `VETS X EST X ZONA3`), en vez de la tabla
única de 38 estaciones que existe hoy.

**Architecture:** `TG.dbo.Estaciones.ZonaConso` ya clasifica las estaciones en las 3 zonas (salvo
Colosio, que se corrige con una migración). El controlador `Merma::armarReporte()` pasa de llamar
`VentasConsolidado::construir()` una vez sobre las 38 estaciones a llamarlo una vez por
zona×producto, cada vez con el subconjunto de `estaciones` de esa zona — `VentasConsolidado` no se
modifica internamente. La vista anida un segundo nivel de tabs (zona → producto/histórico) con IDs
prefijados por zona. El histórico y la exportación a Excel reciben un parámetro `zona` nuevo.

**Tech Stack:** PHP 8 sin framework, Twig, jQuery, Bootstrap Material Design (tabs nativos), SQL
Server vía PDO, PhpSpreadsheet para el .xlsx.

**Spec:** `docs/superpowers/specs/2026-09-08-zonas-reporte-ventas-consolidado-design.md`

## Global Constraints

- Las 3 claves de zona son fijas: `marca_prots`, `tsa_ags`, `zona3` — se usan literalmente en PHP,
  Twig, JS y en la query string (`zona=...`). No cambiar el nombre de ninguna sin actualizar los
  4 lugares.
- `VentasConsolidado::construir()` y `VentasConsolidado::construirHistorico()` NO se modifican —
  su firma y comportamiento interno quedan intactos; solo cambia qué `estaciones` reciben.
- Permiso de todos los endpoints: `authorized(self::PERM_VER)` (ya existente, sin cambios).
- Controles de mes/año y de histórico (desde/hasta/producto) son compartidos entre las 3 zonas —
  no se agregan controles independientes por zona.

---

## Task 1: Migración SQL — Colosio a TSA AGS

**Files:**
- Create: `docs/sql/2026-09-08-colosio-zonaconso.sql`

**Interfaces:**
- Produces: `TG.dbo.Estaciones.Codigo=199` con `ZonaConso=2`. Usado por Task 3 (filtrado por zona)
  para que Colosio aparezca en el tab TSA AGS.

- [ ] **Step 1: Escribir la migración idempotente**

Archivo `docs/sql/2026-09-08-colosio-zonaconso.sql`:

```sql
USE [TG];
GO

UPDATE [TG].[dbo].[Estaciones]
SET ZonaConso = 2
WHERE Codigo = 199 AND (ZonaConso IS NULL OR ZonaConso <> 2);
GO
```

- [ ] **Step 2: Ejecutar la migración**

Run:
```bash
cd "C:\Users\alejandro.martinez\Desktop\codigo\AplicativoPhp"
php -r "
\$_SERVER['DOCUMENT_ROOT'] = getcwd();
\$_SERVER['REQUEST_URI'] = '/';
require '_assets/classes/header.class.php';
require_once MODELS . 'Model.php';
\$db = MySqlPdoHandler::getInstance();
\$sql = file_get_contents('docs/sql/2026-09-08-colosio-zonaconso.sql');
foreach (array_filter(array_map('trim', explode('GO', \$sql))) as \$batch) {
    if (\$batch === '') continue;
    \$db->update(\$batch, []);
}
echo 'OK' . PHP_EOL;
"
```
Expected: imprime `OK` sin errores.

- [ ] **Step 3: Verificar el cambio**

Run:
```bash
php -r "
\$_SERVER['DOCUMENT_ROOT'] = getcwd();
\$_SERVER['REQUEST_URI'] = '/';
require '_assets/classes/header.class.php';
require_once MODELS . 'Model.php';
\$db = MySqlPdoHandler::getInstance();
\$rows = \$db->select('SELECT Codigo, Nombre, ZonaConso FROM TG.dbo.Estaciones WHERE Codigo = 199', []);
var_dump(\$rows);
"
```
Expected: una fila con `ZonaConso => "2"` (o `int(2)`, según cómo el driver tipe la columna).

- [ ] **Step 4: Commit**

```bash
git add docs/sql/2026-09-08-colosio-zonaconso.sql
git commit -m "Corregir ZonaConso de Colosio a TSA AGS (código 199)

Colosio no tenía ZonaConso asignado, pero pertenece al libro TSA AGS
según el Excel real de ventas. Sin este dato, el reporte de ventas
consolidado por zonas no podría clasificarlo en ningún tab.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_016qg8WdTLXpKWN1mLkuvAXh"
```

---

## Task 2: Constante `ZONAS` en `VentasConsolidado`

**Files:**
- Modify: `_assets/classes/VentasConsolidado.class.php`

**Interfaces:**
- Produces: `VentasConsolidado::ZONAS` — array `['marca_prots' => ['label' => string, 'zonaconso' => int[]], 'tsa_ags' => [...], 'zona3' => [...]]`. Usado por Tasks 3, 4, 5, 6, 7.

- [ ] **Step 1: Agregar la constante `ZONAS`**

En `_assets/classes/VentasConsolidado.class.php`, justo después de la constante `PESTANAS`
existente (línea 25, antes de `DIAS_SEMANA`):

```php
    /**
     * Las tres zonas de venta, cada una con su propio libro Excel real
     * (VETS X EST X MARCA Y PROTS / TSA AGS / ZONA3). Se clasifican por
     * TG.dbo.Estaciones.ZonaConso -- 'marca_prots' es todo lo que NO cae en
     * las otras dos (incluye NULL, que Twig/PHP tratan igual que 0 al
     * filtrar), así que su lista de zonaconso solo necesita el valor 0 --
     * ver clasificarZona() para cómo se resuelve NULL.
     */
    public const ZONAS = [
        'marca_prots' => ['label' => 'MARCA Y PROTS', 'zonaconso' => [0, 1]],
        'tsa_ags'     => ['label' => 'TSA AGS',        'zonaconso' => [2]],
        'zona3'       => ['label' => 'ZONA 3',         'zonaconso' => [3]],
    ];

    /**
     * Resuelve a qué zona pertenece una estación según su ZonaConso. NULL
     * (estación sin zona asignada en BD) se trata igual que 0 -- cae en
     * marca_prots por ser el grupo "todo lo demás", no un caso de error.
     */
    public static function clasificarZona(?int $zonaConso): string
    {
        $valor = $zonaConso ?? 0;
        foreach (self::ZONAS as $clave => $info) {
            if (in_array($valor, $info['zonaconso'], true)) return $clave;
        }
        return 'marca_prots';
    }
```

- [ ] **Step 2: Verificar sintaxis**

Run: `php -l _assets/classes/VentasConsolidado.class.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Probar `clasificarZona` con los valores reales**

Run:
```bash
cd "C:\Users\alejandro.martinez\Desktop\codigo\AplicativoPhp"
php -r "
require '_assets/classes/VentasConsolidado.class.php';
var_dump(VentasConsolidado::clasificarZona(null));   // marca_prots
var_dump(VentasConsolidado::clasificarZona(0));      // marca_prots
var_dump(VentasConsolidado::clasificarZona(1));      // marca_prots
var_dump(VentasConsolidado::clasificarZona(2));      // tsa_ags
var_dump(VentasConsolidado::clasificarZona(3));      // zona3
"
```
Expected: `string(11) "marca_prots"`, `string(11) "marca_prots"`, `string(11) "marca_prots"`, `string(7) "tsa_ags"`, `string(5) "zona3"` en ese orden.

- [ ] **Step 4: Commit**

```bash
git add _assets/classes/VentasConsolidado.class.php
git commit -m "Agregar VentasConsolidado::ZONAS y clasificarZona()

Prepara la clasificación de estaciones en las 3 zonas de venta reales
(Marca y Prots / TSA AGS / Zona 3) a partir de ZonaConso, sin tocar
construir() ni construirHistorico().

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_016qg8WdTLXpKWN1mLkuvAXh"
```

---

## Task 3: Modelo — exponer `ZonaConso` en `get_estaciones_ordenadas()`

**Files:**
- Modify: `_assets/models/MermaDiariaModel.php:955-963`

**Interfaces:**
- Produces: `MermaDiariaModel::get_estaciones_ordenadas(): array` ahora devuelve filas con clave
  adicional `'ZonaConso' => int|null` (además de `Codigo`, `Nombre`, `cveest` ya existentes). Usado
  por Task 4 (`armarReporte`) y Task 5 (`armarHistorico`).

- [ ] **Step 1: Agregar `ZonaConso` al SELECT**

En `_assets/models/MermaDiariaModel.php`, reemplazar el método completo (líneas 955-963):

```php
    public function get_estaciones_ordenadas(): array
    {
        $query = 'SELECT e.Codigo, e.Nombre, e.ZonaConso, g.cveest
                  FROM [TG].[dbo].[Estaciones] e
                  LEFT JOIN [SG12].[dbo].[Gasolineras] g ON g.cod = e.Codigo
                  WHERE e.Codigo NOT IN (0, 4, 20)
                  ORDER BY TRY_CAST(LEFT(e.Nombre, 2) AS INT), e.Nombre;';
        return $this->sql->select($query) ?: [];
    }
```

(Único cambio: `e.ZonaConso` agregado al SELECT.)

- [ ] **Step 2: Verificar sintaxis**

Run: `php -l _assets/models/MermaDiariaModel.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Probar contra BD real**

Run:
```bash
cd "C:\Users\alejandro.martinez\Desktop\codigo\AplicativoPhp"
php -r "
\$_SERVER['DOCUMENT_ROOT'] = getcwd();
\$_SERVER['REQUEST_URI'] = '/';
require '_assets/classes/header.class.php';
require_once MODELS . 'Model.php';
require_once MODELS . 'MermaDiariaModel.php';
\$m = new MermaDiariaModel();
\$filas = \$m->get_estaciones_ordenadas();
echo count(\$filas) . ' estaciones' . PHP_EOL;
foreach (\$filas as \$f) {
    if ((int)\$f['Codigo'] === 199) { var_dump(\$f); break; }
}
"
```
Expected: `199 estaciones` (número real puede variar) y la fila de Colosio (Codigo 199) con
`ZonaConso` igual a `"2"` (confirma que la Task 1 ya corrió).

- [ ] **Step 4: Commit**

```bash
git add _assets/models/MermaDiariaModel.php
git commit -m "Exponer ZonaConso en MermaDiariaModel::get_estaciones_ordenadas()

Necesario para que el controlador pueda filtrar las estaciones por
zona sin una segunda consulta.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_016qg8WdTLXpKWN1mLkuvAXh"
```

---

## Task 4: Controlador — `armarReporte()` por zona

**Files:**
- Modify: `_assets/controllers/merma.php:564-605` (método `armarReporte`)
- Modify: `_assets/controllers/merma.php:294-334` (método `ventas`, solo el uso de `$reporte`)

**Interfaces:**
- Consumes: `VentasConsolidado::ZONAS`, `VentasConsolidado::clasificarZona(?int): string` (Task 2).
  `MermaDiariaModel::get_estaciones_ordenadas()` con `ZonaConso` (Task 3).
- Produces: `armarReporte(int $anio, int $mes): array` devuelve ahora
  `['anio' => int, 'mes' => int, 'zonas' => ['marca_prots' => ['label' => string, 'estaciones' => array, 'pestanas' => array, 'sin_presupuesto' => bool], 'tsa_ags' => [...], 'zona3' => [...]], 'sin_presupuesto' => bool]`
  (la clave `sin_presupuesto` de nivel superior se conserva igual que hoy, para el `{% if %}` de la
  vista). Ya NO devuelve `estaciones`/`pestanas` planos de nivel superior. Usado por Task 6 (vista)
  y Task 8 (exportación).

- [ ] **Step 1: Reescribir `armarReporte()`**

En `_assets/controllers/merma.php`, reemplazar el método completo (líneas 564-605):

```php
    /**
     * Junta modelo + presupuesto + calculadora, UNA VEZ POR ZONA. Lo
     * comparten la vista y la exportación a Excel para que no se puedan
     * desincronizar.
     */
    private function armarReporte(int $anio, int $mes): array
    {
        // Codigo llega como string desde PDO; las llaves de las celdas que
        // arma VentasConsolidado son enteros. Se castea una sola vez aquí para
        // que la vista y el exportador indexen sin sorpresas.
        $estaciones = array_map(
            fn($e) => [
                'Codigo'    => (int) $e['Codigo'],
                'Nombre'    => $e['Nombre'],
                'cveest'    => $e['cveest'] ?? null,
                'ZonaConso' => isset($e['ZonaConso']) ? (int) $e['ZonaConso'] : null,
            ],
            $this->mermaModel->get_estaciones_ordenadas()
        );
        $ventas = $this->mermaModel->get_ventas_mes($anio, $mes);

        // Mes anterior y mismo mes del año pasado, para % M.A. y % A.A.
        $mesAnt  = $mes === 1 ? 12 : $mes - 1;
        $anioAnt = $mes === 1 ? $anio - 1 : $anio;

        // Presupuesto: hrms.dbo.incentives_presupuestoventa (equipo de Incentivos),
        // ya resuelto e indexado por estación y familia en el modelo.
        $presupuesto = (new IncentivesPresupuestoModel())->getPresupuesto($mes, $anio);

        $ctxBase = [
            'ventas'        => $ventas,
            'presupuesto'   => $presupuesto,
            'mes_anterior'  => $this->mermaModel->get_ventas_totales_mes($anioAnt, $mesAnt),
            'anio_anterior' => $this->mermaModel->get_ventas_totales_mes($anio - 1, $mes),
            'anio'          => $anio,
            'mes'           => $mes,
        ];

        $zonas = [];
        foreach (VentasConsolidado::ZONAS as $zonaClave => $zonaInfo) {
            $estacionesZona = array_values(array_filter(
                $estaciones,
                fn($e) => VentasConsolidado::clasificarZona($e['ZonaConso']) === $zonaClave
            ));

            $ctxZona = $ctxBase;
            $ctxZona['estaciones'] = $estacionesZona;

            $pestanas = [];
            foreach (array_keys(VentasConsolidado::PESTANAS) as $clave) {
                $pestanas[$clave] = VentasConsolidado::construir($clave, $ctxZona);
            }

            $zonas[$zonaClave] = [
                'label'           => $zonaInfo['label'],
                'estaciones'      => $estacionesZona,
                'pestanas'        => $pestanas,
                'sin_presupuesto' => $presupuesto === [],
            ];
        }

        return [
            'anio'            => $anio,
            'mes'             => $mes,
            'zonas'           => $zonas,
            'sin_presupuesto' => $presupuesto === [],
        ];
    }
```

- [ ] **Step 2: Actualizar `ventas()` para el nuevo shape de `$reporte`**

En `_assets/controllers/merma.php`, el método `ventas()` (líneas 294-334) ya hace
`echo $this->twig->render($this->route . 'ventas.html', $reporte + [...]);` — esto sigue
funcionando igual porque `$reporte` ahora trae `zonas` en vez de `estaciones`/`pestanas` planos, y
la vista (Task 6) se actualiza para leer `zonas`. No se requiere ningún cambio de código en
`ventas()` mismo — solo confirmar que el `+` de merge de arrays sigue intacto. Sin cambios en este
paso; es una verificación, no una edición.

- [ ] **Step 3: Verificar sintaxis**

Run: `php -l _assets/controllers/merma.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Probar `armarReporte()` de punta a punta vía CLI**

`armarReporte` es `private`, así que se prueba invocando el método público `ventas()` con
`$_SESSION` simulado, o reflejando el privado. Usar reflexión (más simple, no requiere sesión real
para este método interno ya que no vuelve a llamar `authorized()`):

```bash
cd "C:\Users\alejandro.martinez\Desktop\codigo\AplicativoPhp"
php -r "
\$_SERVER['DOCUMENT_ROOT'] = getcwd();
\$_SERVER['REQUEST_URI'] = '/';
require '_assets/classes/header.class.php';
require_once MODELS . 'Model.php';
require_once MODELS . 'MermaDiariaModel.php';
require_once MODELS . 'IncentivesPresupuestoModel.php';
require_once CLASSES . 'VentasConsolidado.class.php';
require_once CONTROLLERS . 'merma.php';

\$twigStub = new class {
    public function render(\$t, \$d) { return ''; }
};
\$controller = new Merma(\$twigStub);
\$refMethod = new ReflectionMethod(Merma::class, 'armarReporte');
\$refMethod->setAccessible(true);
\$anio = (int) date('Y', strtotime('yesterday'));
\$mes  = (int) date('n', strtotime('yesterday'));
\$reporte = \$refMethod->invoke(\$controller, \$anio, \$mes);

foreach (\$reporte['zonas'] as \$clave => \$zona) {
    echo \$clave . ' (' . \$zona['label'] . '): ' . count(\$zona['estaciones']) . ' estaciones' . PHP_EOL;
}
"
```
Expected: 3 líneas — `marca_prots (MARCA Y PROTS): N estaciones`, `tsa_ags (TSA AGS): 5 estaciones`
(4 + Colosio), `zona3 (ZONA 3): 7 estaciones`. Si el constructor de `Merma` requiere argumentos
adicionales o hace más trabajo que solo guardar `$twig`, ajustar el stub o instanciar los modelos
que falten según lo que el constructor real exija (revisar `_assets/controllers/merma.php` líneas
iniciales antes de correr esto).

- [ ] **Step 5: Commit**

```bash
git add _assets/controllers/merma.php
git commit -m "armarReporte() ahora arma el reporte por zona (marca_prots/tsa_ags/zona3)

Reemplaza la tabla única de 38 estaciones por 3 subconjuntos, cada
uno calculado de forma independiente (TOTAL, %MIX, PROY, PPTO, etc.
propios de cada zona), igual que los 3 libros Excel reales.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_016qg8WdTLXpKWN1mLkuvAXh"
```

---

## Task 5: Controlador — histórico por zona

**Files:**
- Modify: `_assets/controllers/merma.php:342-354` (método `ventas_historico`)
- Modify: `_assets/controllers/merma.php:364-401` (métodos `periodoHistorico`, `armarHistorico`)

**Interfaces:**
- Consumes: `VentasConsolidado::ZONAS`, `VentasConsolidado::clasificarZona()` (Task 2).
- Produces: `periodoHistorico(): array{0:int,1:int,2:string,3:string}` — agrega un 4º elemento
  `$zona` (una de las 3 claves, default `marca_prots` si el parámetro es inválido o falta).
  `armarHistorico(int $desde, int $hasta, string $prod, string $zona): array{estaciones: array, hist: array}`
  — nuevo 4º parámetro `$zona`, filtra `estaciones` antes de llamar
  `VentasConsolidado::construirHistorico()`. Usado por Task 8 (`ventas_excel`).

- [ ] **Step 1: Agregar `$zona` a `periodoHistorico()`**

En `_assets/controllers/merma.php`, reemplazar el método completo (líneas 364-377):

```php
    /**
     * Valida desde/hasta/prod/zona de la pestaña histórica. Mismo criterio
     * que ventas(): piso duro en 2020 para que un parámetro manipulado no
     * pida un rango absurdo, aunque el piso del SELECTOR sea el primer año
     * que exista en la tabla (get_anio_min_historico), que es más alto.
     *
     * @return array{0:int,1:int,2:string,3:string}
     */
    private function periodoHistorico(): array
    {
        $anioActual = (int) date('Y');
        $desde = (int) ($_GET['desde'] ?? $anioActual - 2);
        $hasta = (int) ($_GET['hasta'] ?? $anioActual);
        if ($desde < 2020 || $desde > $anioActual) $desde = $anioActual - 2;
        if ($hasta < 2020 || $hasta > $anioActual) $hasta = $anioActual;
        if ($desde > $hasta) [$desde, $hasta] = [$hasta, $desde];

        $prod = (string) ($_GET['prod'] ?? 'total');
        if (!isset(VentasConsolidado::PESTANAS[$prod])) $prod = 'total';

        $zona = (string) ($_GET['zona'] ?? 'marca_prots');
        if (!isset(VentasConsolidado::ZONAS[$zona])) $zona = 'marca_prots';

        return [$desde, $hasta, $prod, $zona];
    }
```

- [ ] **Step 2: Filtrar estaciones en `armarHistorico()`**

En `_assets/controllers/merma.php`, reemplazar el método completo (líneas 387-401):

```php
    /**
     * Junta modelo + calculadora para la pestaña HISTÓRICO, filtrado a una
     * zona. Lo comparten la vista (ventas_historico) y la exportación
     * (ventas_excel) para que la tabla y su leyenda de cobertura no se
     * puedan desincronizar entre pantalla y .xlsx.
     *
     * @return array{estaciones: array, hist: array}
     */
    private function armarHistorico(int $desde, int $hasta, string $prod, string $zona): array
    {
        $estaciones = array_map(
            fn($e) => [
                'Codigo'    => (int) $e['Codigo'],
                'Nombre'    => $e['Nombre'],
                'ZonaConso' => isset($e['ZonaConso']) ? (int) $e['ZonaConso'] : null,
            ],
            $this->mermaModel->get_estaciones_ordenadas()
        );
        $estacionesZona = array_values(array_filter(
            $estaciones,
            fn($e) => VentasConsolidado::clasificarZona($e['ZonaConso']) === $zona
        ));

        $hist = VentasConsolidado::construirHistorico($prod, [
            'estaciones' => $estacionesZona,
            'historico'  => $this->mermaModel->get_historico_mensual($desde, $hasta),
            'desde'      => $desde,
            'hasta'      => $hasta,
        ]);

        return ['estaciones' => $estacionesZona, 'hist' => $hist];
    }
```

- [ ] **Step 3: Actualizar `ventas_historico()` para pasar `$zona`**

En `_assets/controllers/merma.php`, reemplazar el método completo (líneas 342-354):

```php
    /**
     * Pestaña HISTÓRICO de /merma/ventas: acumulado mensual por estación
     * sobre un rango de años, filtrado a una zona. Devuelve SOLO el HTML de
     * la tabla — la pestaña lo pide por AJAX para que sus controles no
     * colisionen con el selector de mes que gobierna las cinco pestañas
     * diarias.
     */
    public function ventas_historico(): void
    {
        if (!authorized(self::PERM_VER)) {
            (new Errors())->get404();
            return;
        }
        [$desde, $hasta, $prod, $zona] = $this->periodoHistorico();

        ['estaciones' => $estaciones, 'hist' => $hist] = $this->armarHistorico($desde, $hasta, $prod, $zona);

        echo $this->twig->render($this->route . 'ventas_historico.html',
            compact('estaciones', 'hist', 'desde', 'hasta', 'prod'));
    }
```

(Nota: `zona` NO se pasa a la vista parcial `ventas_historico.html` — esa plantilla no cambia,
Task 6 la reutiliza tal cual dentro de cada tab de zona.)

- [ ] **Step 4: Verificar sintaxis**

Run: `php -l _assets/controllers/merma.php`
Expected: `No syntax errors detected`

- [ ] **Step 5: Commit**

```bash
git add _assets/controllers/merma.php
git commit -m "Histórico de ventas ahora filtra por zona (parámetro zona=)

periodoHistorico() y armarHistorico() reciben/devuelven la zona
activa, consistente con armarReporte(). ventas_historico.html no
cambia -- sigue recibiendo solo las estaciones ya filtradas.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_016qg8WdTLXpKWN1mLkuvAXh"
```

---

## Task 6: Vista `ventas.html` — tabs anidados por zona

**Files:**
- Modify: `views/merma/ventas.html` (reescritura completa del `{% block content %}`)

**Interfaces:**
- Consumes: `reporte['zonas']` (Task 4) — cada zona con `label`, `estaciones`, `pestanas`,
  `sin_presupuesto`.
- Produces: IDs de tab con el patrón `tab-zona-{zonaClave}` (nivel 1) y
  `tab-{zonaClave}-{claveProducto}` / `tab-{zonaClave}-historico` (nivel 2). Usado por Task 7 (JS).

- [ ] **Step 1: Reescribir `views/merma/ventas.html`**

Reemplazar el archivo completo:

```html
{% extends "views/layouts/base.html" %}
{% block title %}Reporte de ventas consolidado{% endblock %}
{% block menutitle %}Reporte de ventas consolidado{% endblock %}

{% block mycss %}
<link href="/_assets/css/merma.css" rel="stylesheet">
{% endblock %}

{% block content %}

<div class="card">
    <div class="card-body">
        <form action="#" method="get" class="row align-items-end g-2">
            <div class="col-auto">
                <label for="mes">Mes:</label>
                <select class="form-control" name="mes" id="mes">
                    {% for i in 1..12 %}
                    <option value="{{ i }}" {{ i == mes ? 'selected' : '' }}>{{ meses[i - 1] }}</option>
                    {% endfor %}
                </select>
            </div>
            <div class="col-auto">
                <label for="anio">Año:</label>
                <select class="form-control" name="anio" id="anio">
                    {% for a in anios %}
                    <option value="{{ a }}" {{ a == anio ? 'selected' : '' }}>{{ a }}</option>
                    {% endfor %}
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-merma-neutro">Buscar</button>
            </div>
            <div class="col-auto ms-auto">
                <a href="/merma/ventas_excel?anio={{ anio }}&mes={{ mes }}&desde={{ histDesde }}&hasta={{ histHasta }}&prod=total&zona=marca_prots"
                   id="btn_exportar" class="btn btn-merma-sync">
                    <i class="fas fa-file-excel"></i> Exportar a Excel
                </a>
            </div>
        </form>
    </div>
</div>

{% if sin_presupuesto %}
<div class="alert alert-warning py-2">
    Sin presupuesto cargado para {{ meses[mes - 1]|lower }} {{ anio }} en
    incentives_presupuestoventa. Mientras tanto, las filas PRESUPUESTO,
    DIFERENCIA y % PRESUPUESTO salen vacías.
</div>
{% endif %}

<ul class="nav nav-tabs merma-tabs-zona" role="tablist">
    {% for zonaClave, zona in zonas %}
    <li class="nav-item">
        <a class="nav-link {{ loop.first ? 'active' : '' }}" data-bs-toggle="tab"
           href="#tab-zona-{{ zonaClave }}" role="tab" data-zona="{{ zonaClave }}">{{ zona.label }}</a>
    </li>
    {% endfor %}
</ul>

<div class="tab-content">
    {% for zonaClave, zona in zonas %}
    <div class="tab-pane fade {{ loop.first ? 'show active' : '' }}" id="tab-zona-{{ zonaClave }}" role="tabpanel">

        <ul class="nav nav-tabs merma-tabs" role="tablist">
            {% for clave, p in zona.pestanas %}
            <li class="nav-item">
                <a class="nav-link {{ loop.first ? 'active' : '' }}" data-bs-toggle="tab"
                   href="#tab-{{ zonaClave }}-{{ clave }}" role="tab">{{ p.label }}</a>
            </li>
            {% endfor %}
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#tab-{{ zonaClave }}-historico" role="tab"
                   id="tab-historico-link-{{ zonaClave }}">HISTÓRICO</a>
            </li>
        </ul>

        <div class="tab-content">
            {% for clave, p in zona.pestanas %}
            <div class="tab-pane fade {{ loop.first ? 'show active' : '' }}" id="tab-{{ zonaClave }}-{{ clave }}" role="tabpanel">
                <div class="merma-tabla-wrap mb-3">
                    <div class="merma-scroll">
                        <table class="merma-tabla vc-tabla">
                            <thead>
                                <tr>
                                    <th class="col-fecha">DÍA</th>
                                    {% for e in zona.estaciones %}
                                    <th>{{ e.Nombre }}</th>
                                    {% endfor %}
                                    <th class="th-grupo">TOTAL</th>
                                </tr>
                            </thead>
                            <tbody>
                                {% for d in p.dias %}
                                <tr>
                                    <td class="col-fecha">{{ d.dia }}-{{ d.nombre|capitalize }}</td>
                                    {% for e in zona.estaciones %}
                                    {% set v = d.celdas[e.Codigo] %}
                                    <td>{{ v is null ? '—' : v|number_format(0, '.', ',') }}</td>
                                    {% endfor %}
                                    <td class="col-total">{{ d.total is null ? '—' : d.total|number_format(0, '.', ',') }}</td>
                                </tr>
                                {% endfor %}
                            </tbody>
                            <tfoot>
                                {% for r in [
                                    {'k': 'total',     'l': 'TOTAL',            't': 'lts'},
                                    {'k': 'mix',       'l': '% MIX',            't': 'pct_plano'},
                                    {'k': 'proy',      'l': 'PROY. MENSUAL',    't': 'lts'},
                                    {'k': 'ppto',      'l': 'PRESUPUESTO',      't': 'lts'},
                                    {'k': 'dif',       'l': 'DIFERENCIA',       't': 'lts_signo'},
                                    {'k': 'pct_ppto',  'l': '% PRESUPUESTO',    't': 'pct'},
                                    {'k': 'vs_semana', 'l': 'VS SEMANA PREVIA', 't': 'pct'},
                                    {'k': 'ma',        'l': '% M.A.',           't': 'pct'},
                                    {'k': 'aa',        'l': '% A.A.',           't': 'pct'}
                                ] %}
                                {% set fila = p.resumen[r.k] %}
                                <tr class="vc-resumen vc-resumen-{{ r.k }}">
                                    <td class="col-fecha">{{ r.l }}</td>
                                    {% for e in zona.estaciones %}
                                    {% set v = fila.celdas[e.Codigo] %}
                                    <td class="{{ v is null or r.t == 'lts' or r.t == 'pct_plano' ? '' : (v < 0 ? 'pct-neg' : 'pct-pos') }}">
                                        {% if v is null %}—
                                        {% elseif r.t starts with 'pct' %}{{ v|number_format(2) }}%
                                        {% else %}{{ v|number_format(0, '.', ',') }}{% endif %}
                                    </td>
                                    {% endfor %}
                                    {% set vt = fila.total %}
                                    <td class="col-total {{ vt is null or r.t == 'lts' or r.t == 'pct_plano' ? '' : (vt < 0 ? 'pct-neg' : 'pct-pos') }}">
                                        {% if vt is null %}—
                                        {% elseif r.t starts with 'pct' %}{{ vt|number_format(2) }}%
                                        {% else %}{{ vt|number_format(0, '.', ',') }}{% endif %}
                                    </td>
                                </tr>
                                {% endfor %}
                            </tfoot>
                        </table>
                    </div>
                </div>
                <p class="text-muted small mb-1">
                    {{ p.dias_con_datos }} de {{ p.dias_del_mes }} días con datos.
                    La proyección mensual extrapola el total a los {{ p.dias_del_mes }} días del mes.
                </p>
                <details class="vc-leyenda">
                    <summary>¿Qué significa cada fila del resumen?</summary>
                    <dl class="vc-leyenda-grid">
                        <div><dt>TOTAL</dt><dd>Suma de litros despachados en el mes, por estación y general.</dd></div>
                        <div><dt>% MIX</dt><dd>Participación de cada estación sobre el total del grupo (suman 100%).</dd></div>
                        <div><dt>PROY. MENSUAL</dt><dd>Estimado a fin de mes: TOTAL ÷ días con datos × días del mes.</dd></div>
                        <div><dt>PRESUPUESTO</dt><dd>Meta de litros cargada en incentives_presupuestoventa para el mes.</dd></div>
                        <div><dt>DIFERENCIA</dt><dd>PROY. MENSUAL − PRESUPUESTO, en litros.</dd></div>
                        <div><dt>% PRESUPUESTO</dt><dd>PROY. MENSUAL como porcentaje del PRESUPUESTO.</dd></div>
                        <div><dt>VS SEMANA PREVIA</dt><dd>Variación % del total vs. la misma cantidad de días de la semana anterior.</dd></div>
                        <div><dt>% M.A.</dt><dd>Variación % vs. el mes anterior (mismos días transcurridos).</dd></div>
                        <div><dt>% A.A.</dt><dd>Variación % vs. el mismo mes del año anterior.</dd></div>
                    </dl>
                </details>
            </div>
            {% endfor %}
            <div class="tab-pane fade" id="tab-{{ zonaClave }}-historico" role="tabpanel">
                <div class="card mb-2">
                    <div class="card-body py-2">
                        <div class="row align-items-end g-2">
                            <div class="col-auto">
                                <label for="hist_desde-{{ zonaClave }}">Año desde:</label>
                                <select class="form-control hist-control" id="hist_desde-{{ zonaClave }}">
                                    {% for a in histAnios %}
                                    <option value="{{ a }}" {{ a == histDesde ? 'selected' : '' }}>{{ a }}</option>
                                    {% endfor %}
                                </select>
                            </div>
                            <div class="col-auto">
                                <label for="hist_hasta-{{ zonaClave }}">Año hasta:</label>
                                <select class="form-control hist-control" id="hist_hasta-{{ zonaClave }}">
                                    {% for a in histAnios %}
                                    <option value="{{ a }}" {{ a == histHasta ? 'selected' : '' }}>{{ a }}</option>
                                    {% endfor %}
                                </select>
                            </div>
                            <div class="col-auto">
                                <label for="hist_prod-{{ zonaClave }}">Producto:</label>
                                <select class="form-control hist-control" id="hist_prod-{{ zonaClave }}">
                                    {% for clave, p in histProds %}
                                    <option value="{{ clave }}" {{ clave == 'total' ? 'selected' : '' }}>{{ p.label }}</option>
                                    {% endfor %}
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                <div id="hist_contenido-{{ zonaClave }}" class="hist-contenido">
                    <p class="text-muted small">Cargando histórico…</p>
                </div>
            </div>
        </div>
    </div>
    {% endfor %}
</div>

{% endblock %}
{% block myjs %}
<script src="/_assets/js/merma_ventas.js"></script>
{% endblock %}
```

Nota: los controles de histórico YA NO son compartidos entre zonas (cada zona tiene su propio
`hist_desde-{zonaClave}` etc.), porque anidarlos como un único set global fuera de los tabs de zona
rompería la semántica de "un histórico por tab" que pide `armarHistorico(..., $zona)`. Esto es una
desviación menor de la Sección 2 del spec (que proponía controles de histórico compartidos): se
opta por controles de histórico duplicados por zona (mismo valor por defecto, pero cada uno vive
dentro de su propio tab-pane) porque es más simple de cablear en Bootstrap tabs nativos y evita que
JS tenga que re-parentar selects entre tabs al cambiar de zona. Los controles de MES/AÑO de arriba
SÍ siguen compartidos (fuera de los tabs de zona), tal como se acordó.

- [ ] **Step 2: Verificar que Twig no tira error al renderizar**

Run (requiere sesión de navegador real para `authorized()` — verificar manualmente abriendo
`http://localhost:8001/merma/ventas` en el navegador tras levantar el servidor). Si no puedes abrir
el navegador en este paso, al menos verificar que el archivo no tiene syntax de Twig rota:

```bash
cd "C:\Users\alejandro.martinez\Desktop\codigo\AplicativoPhp"
php -r "
require 'vendor/autoload.php';
\$loader = new \Twig\Loader\FilesystemLoader('.');
\$twig = new \Twig\Environment(\$loader);
try {
    \$twig->parse(\$twig->tokenize(new \Twig\Source(file_get_contents('views/merma/ventas.html'), 'ventas.html')));
    echo 'Twig OK' . PHP_EOL;
} catch (\Twig\Error\SyntaxError \$e) {
    echo 'ERROR: ' . \$e->getMessage() . PHP_EOL;
}
"
```
Expected: `Twig OK`.

- [ ] **Step 3: Commit**

```bash
git add views/merma/ventas.html
git commit -m "Vista de ventas consolidado: tabs anidados zona > producto/histórico

Cada una de las 3 zonas (Marca y Prots/TSA AGS/Zona 3) tiene su
propio bloque de 5 pestañas de producto + Histórico, con IDs
prefijados por zona. Controles de mes/año se quedan compartidos
arriba; los de histórico se duplican por zona (uno por tab).

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_016qg8WdTLXpKWN1mLkuvAXh"
```

---

## Task 7: JS — histórico y exportación por zona

**Files:**
- Modify: `_assets/js/merma_ventas.js` (reescritura completa)

**Interfaces:**
- Consumes: DOM ids `tab-historico-link-{zonaClave}`, `hist_desde-{zonaClave}`,
  `hist_hasta-{zonaClave}`, `hist_prod-{zonaClave}`, `hist_contenido-{zonaClave}` (Task 6), y los
  links de zona nivel 1 con `data-zona="{zonaClave}"` (Task 6).
- Produces: fetch a `/merma/ventas_historico?desde=&hasta=&prod=&zona=` (consumido por
  `Merma::ventas_historico()`, Task 5). Actualiza `href` de `#btn_exportar` con
  `zona={zonaActiva}` además de `desde`/`hasta`/`prod` ya existentes.

- [ ] **Step 1: Reescribir `_assets/js/merma_ventas.js`**

```javascript
/**
 * Pestaña HISTÓRICO de /merma/ventas, repetida una vez por cada tab de
 * zona (marca_prots/tsa_ags/zona3). Se carga por AJAX en vez de venir en
 * el render inicial para que sus selectores de año y producto no
 * colisionen con el selector de mes que gobierna las cinco pestañas
 * diarias: si vivieran en el mismo formulario, cambiarlos recargaría la
 * página y arrastraría al otro control.
 */
$(function () {
    var cargadas = {};   // zonaClave -> bool, para no recargar el histórico de una zona ya vista
    var zonaActiva = $('.merma-tabs-zona .nav-link.active').data('zona') || 'marca_prots';

    function controlesDe(zonaClave) {
        return {
            desde: $('#hist_desde-' + zonaClave),
            hasta: $('#hist_hasta-' + zonaClave),
            prod:  $('#hist_prod-' + zonaClave),
            contenido: $('#hist_contenido-' + zonaClave)
        };
    }

    // El enlace de exportación arrastra el rango, producto y zona activa,
    // para que la hoja HISTÓRICO del .xlsx (y el resto del libro) reflejen
    // lo que está en pantalla.
    function sincronizarEnlaceExportar() {
        var $a = $('#btn_exportar');
        if (!$a.length) return;
        var c = controlesDe(zonaActiva);
        var url = new URL($a.attr('href'), window.location.origin);
        url.searchParams.set('desde', c.desde.val());
        url.searchParams.set('hasta', c.hasta.val());
        url.searchParams.set('prod',  c.prod.val());
        url.searchParams.set('zona',  zonaActiva);
        $a.attr('href', url.pathname + url.search);
    }

    function cargarHistorico(zonaClave) {
        var c = controlesDe(zonaClave);
        var params = {
            desde: c.desde.val(),
            hasta: c.hasta.val(),
            prod:  c.prod.val(),
            zona:  zonaClave
        };
        c.contenido.html('<p class="text-muted small">Cargando histórico…</p>');
        $.get('/merma/ventas_historico', params)
            .done(function (html) {
                c.contenido.html(html);
                cargadas[zonaClave] = true;
            })
            .fail(function () {
                c.contenido.html(
                    '<div class="alert alert-danger py-2">No se pudo cargar el histórico. ' +
                    'Vuelve a intentarlo o revisa la conexión.</div>'
                );
            });
        if (zonaClave === zonaActiva) sincronizarEnlaceExportar();
    }

    // Primera vez que se abre la pestaña HISTÓRICO de cada zona
    $('[id^="tab-historico-link-"]').on('shown.bs.tab', function () {
        var zonaClave = this.id.replace('tab-historico-link-', '');
        if (!cargadas[zonaClave]) cargarHistorico(zonaClave);
    });

    // Cambiar de tab de ZONA actualiza cuál es la zona activa (para el
    // enlace de exportación) y sincroniza el enlace con los controles de
    // esa zona, ya estén cargados o con los valores por defecto del render.
    $('.merma-tabs-zona .nav-link').on('shown.bs.tab', function () {
        zonaActiva = $(this).data('zona');
        sincronizarEnlaceExportar();
    });

    // Cualquier cambio de control de histórico recarga SU tabla y
    // re-sincroniza el enlace si es la zona actualmente activa.
    $('.hist-control').on('change', function () {
        var zonaClave = this.id.replace(/^hist_(desde|hasta|prod)-/, '');
        cargarHistorico(zonaClave);
    });

    // El navegador restaura el valor de los <select> en un F5 o un
    // atrás/adelante SIN disparar "change" (a diferencia de un cambio hecho
    // por el usuario). Sin esto, el enlace de exportación queda apuntando a
    // los valores por defecto que el servidor renderizó, mientras la
    // pestaña —cuando se abra— usará los valores restaurados por el
    // navegador: se rompe en silencio.
    sincronizarEnlaceExportar();
});
```

- [ ] **Step 2: Verificar sintaxis JS**

Run: `node --check "_assets/js/merma_ventas.js"`
Expected: sin salida (exit code 0). Si `node` no está disponible en el PATH, abrir el archivo en
el navegador vía la Task 8 y confirmar en la consola de DevTools que no hay errores de parseo al
cargar `/merma/ventas`.

- [ ] **Step 3: Commit**

```bash
git add _assets/js/merma_ventas.js
git commit -m "JS de ventas consolidado: histórico y exportar por zona

Cada zona carga su propio histórico de forma perezosa (al abrir su
tab), y el enlace de exportación arrastra la zona actualmente activa
además de desde/hasta/prod.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_016qg8WdTLXpKWN1mLkuvAXh"
```

---

## Task 8: `ventas_excel()` — exportar solo la zona activa

**Files:**
- Modify: `_assets/controllers/merma.php:407-535` (método `ventas_excel`, section de apertura y
  de generación de hojas de producto — NO la hoja HISTÓRICO, que ya se actualiza en Task 5)

**Interfaces:**
- Consumes: `armarReporte()` con shape `zonas[...]` (Task 4), `armarHistorico(..., $zona)` (Task 5).
- Produces: `ventas_excel()` sin cambio de firma pública (sigue siendo un endpoint GET sin
  parámetros de método), pero ahora lee `$_GET['zona']`, valida contra `VentasConsolidado::ZONAS`
  (default `marca_prots`), y genera el `.xlsx` de esa única zona. Nombre de archivo cambia a
  `ventas_{zonaClave}_{anio}_{mes}.xlsx`.

- [ ] **Step 1: Leer y validar `zona`, filtrar `$reporte['zonas']`**

En `_assets/controllers/merma.php`, dentro de `ventas_excel()`, después de las líneas que resuelven
`$anio`/`$mes` (justo antes de `$reporte = $this->armarReporte($anio, $mes);`, aproximadamente
línea 419), agregar:

```php
        $zonaClave = (string) ($_GET['zona'] ?? 'marca_prots');
        if (!isset(VentasConsolidado::ZONAS[$zonaClave])) $zonaClave = 'marca_prots';
```

Luego, reemplazar:

```php
        $reporte    = $this->armarReporte($anio, $mes);
        $estaciones = $reporte['estaciones'];
```

por:

```php
        $reporte    = $this->armarReporte($anio, $mes);
        $zonaReporte = $reporte['zonas'][$zonaClave];
        $estaciones  = $zonaReporte['estaciones'];
```

- [ ] **Step 2: Usar `$zonaReporte['pestanas']` en el loop de hojas**

Localizar `foreach ($reporte['pestanas'] as $p) {` (dentro de `ventas_excel()`, poco después de los
cambios del Step 1) y reemplazar por:

```php
        foreach ($zonaReporte['pestanas'] as $p) {
```

(El resto del cuerpo del loop no cambia — ya opera sobre `$p` y `$estaciones`, ambos ya filtrados.)

- [ ] **Step 3: Pasar `$zonaClave` a `armarHistorico()` y ajustar el nombre de archivo**

Localizar `[$hDesde, $hHasta, $hProd] = $this->periodoHistorico();` y
`['estaciones' => $estacionesHist, 'hist' => $hist] = $this->armarHistorico($hDesde, $hHasta, $hProd);`
(alrededor de la línea 500-501) y reemplazar por:

```php
        [$hDesde, $hHasta, $hProd, $hZona] = $this->periodoHistorico();
        ['estaciones' => $estacionesHist, 'hist' => $hist] = $this->armarHistorico($hDesde, $hHasta, $hProd, $hZona);
```

Nota: `$hZona` viene de `periodoHistorico()` (que lee `$_GET['zona']` igual que `ventas_historico()`),
así que ya coincide con `$zonaClave` resuelto en el Step 1 siempre que el JS (Task 7) mande el mismo
`zona=` en ambos parámetros del link de exportar — cosa que ya hace. No hace falta forzar
`$hZona = $zonaClave` explícitamente porque ambos parsers leen el mismo `$_GET['zona']`.

Localizar la línea que arma el nombre de archivo (buscar `sprintf('ventas_consolidado_%04d_%02d.xlsx'`
alrededor de la línea 552) y reemplazar:

```php
        $archivo = sprintf('ventas_consolidado_%04d_%02d.xlsx', $anio, $mes);
```

por:

```php
        $archivo = sprintf('ventas_%s_%04d_%02d.xlsx', $zonaClave, $anio, $mes);
```

- [ ] **Step 4: Verificar sintaxis**

Run: `php -l _assets/controllers/merma.php`
Expected: `No syntax errors detected`

- [ ] **Step 5: Verificar manualmente en navegador**

Requiere sesión real — no simulable por CLI porque `authorized()` depende de `$_SESSION`. Con el
servidor de desarrollo corriendo (el usuario lo gestiona, no lo levantes tú — ver
`feedback_no_levantar_servidor` en memoria), navegar a `http://localhost:8001/merma/ventas`,
verificar:
1. Los 3 tabs de zona aparecen (MARCA Y PROTS, TSA AGS, ZONA 3) con el número correcto de columnas
   de estación en cada uno.
2. TSA AGS incluye Colosio.
3. Cambiar de tab de zona no pierde el mes/año seleccionado arriba.
4. Abrir el tab HISTÓRICO de cada zona carga su propia tabla (solo sus estaciones).
5. El botón "Exportar a Excel" descarga un archivo `ventas_{zona}_{anio}_{mes}.xlsx` con las
   estaciones de la zona actualmente visible.

- [ ] **Step 6: Commit**

```bash
git add _assets/controllers/merma.php
git commit -m "ventas_excel() exporta solo la zona activa (parámetro zona=)

Reemplaza el archivo consolidado de 38 estaciones por un .xlsx por
zona, replicando exactamente uno de los 3 libros Excel reales.
Nombre de archivo: ventas_{zona}_{anio}_{mes}.xlsx.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_016qg8WdTLXpKWN1mLkuvAXh"
```

---

## Task 9: Verificación final end-to-end

**Files:** ninguno (solo verificación manual)

- [ ] **Step 1: Confirmar los 3 conteos de estaciones contra los Excel originales**

Con el servidor corriendo y sesión iniciada, abrir cada tab de zona en
`http://localhost:8001/merma/ventas` y contar las columnas de estación visibles en la tabla de
"LITROS DE COMBUSTIBLE". Comparar contra:
- MARCA Y PROTS: 27 estaciones (ver `docs/superpowers/specs/2026-09-08-zonas-reporte-ventas-consolidado-design.md`)
- TSA AGS: 5 estaciones (4 + Colosio)
- ZONA 3: 7 estaciones

Expected: los 3 conteos calzan exactamente.

- [ ] **Step 2: Confirmar que ninguna estación quedó fuera o duplicada entre zonas**

Run:
```bash
cd "C:\Users\alejandro.martinez\Desktop\codigo\AplicativoPhp"
php -r "
\$_SERVER['DOCUMENT_ROOT'] = getcwd();
\$_SERVER['REQUEST_URI'] = '/';
require '_assets/classes/header.class.php';
require_once MODELS . 'Model.php';
require_once MODELS . 'MermaDiariaModel.php';
require_once CLASSES . 'VentasConsolidado.class.php';
\$m = new MermaDiariaModel();
\$estaciones = \$m->get_estaciones_ordenadas();
\$totalPorZona = ['marca_prots' => 0, 'tsa_ags' => 0, 'zona3' => 0];
foreach (\$estaciones as \$e) {
    \$zona = VentasConsolidado::clasificarZona(isset(\$e['ZonaConso']) ? (int)\$e['ZonaConso'] : null);
    \$totalPorZona[\$zona]++;
}
echo 'Total estaciones: ' . count(\$estaciones) . PHP_EOL;
foreach (\$totalPorZona as \$z => \$n) echo \"\$z: \$n\" . PHP_EOL;
echo 'Suma zonas: ' . array_sum(\$totalPorZona) . PHP_EOL;
"
```
Expected: `Suma zonas` es exactamente igual a `Total estaciones` (ninguna estación se pierde ni se
duplica al clasificar).

- [ ] **Step 3: Confirmar que no queda ninguna referencia rota al shape viejo de `armarReporte()`**

Run:
```bash
cd "C:\Users\alejandro.martinez\Desktop\codigo\AplicativoPhp"
grep -n "reporte\['estaciones'\]\|reporte\['pestanas'\]" _assets/controllers/merma.php
```
Expected: cero resultados (todo el código ya lee `reporte['zonas'][...]`).
