# Reporte de Ventas Consolidado — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Una vista `/merma/ventas` que reemplaza el libro `VETS X EST X MARCA Y PROTS <Mes><Año>.xlsm`, mostrando ventas en litros por día × estación en cinco pestañas de producto, con las nueve filas de resumen del Excel y exportación a `.xlsx`.

**Architecture:** Una consulta a `TG.dbo.merma_diaria` agrupada por día × estación con las tres familias de producto en columnas; una clase pura sin BD (`VentasConsolidado`) que proyecta esa matriz en las cinco pestañas y calcula las filas de resumen; un método de controlador que las junta con el presupuesto de `TGV2.dbo.Budget` y las renderiza en una vista Twig con pestañas Bootstrap.

**Tech Stack:** PHP 8 (MVC propio, sin framework), PDO + driver `sqlsrv`, Twig 3, Bootstrap Material Design + jQuery, PhpSpreadsheet 2.2.

**Spec:** `docs/superpowers/specs/2026-07-28-reporte-ventas-consolidado-design.md`

## Global Constraints

- **No hay framework de tests en este repositorio** (ni PHPUnit, ni `tests/`, ni `composer test`). El ciclo de verificación de cada tarea es: un script PHP de línea de comandos que ejecuta aserciones reales y se guarda en el scratchpad (**no se commitea**), más una verificación en el navegador donde aplique. No inventes una infraestructura de tests nueva: no está en el alcance y el repositorio no la tiene.
- **Servidor local:** `php -S localhost:8001 router.php` desde la raíz del proyecto. La app en producción corre en IIS con `web.config`.
- **Conexión a BD para los scripts CLI:** `new PDO("sqlsrv:Server=192.168.0.6;Database=TG;TrustServerCertificate=yes;LoginTimeout=10", 'cguser', 'sahei1712')`. Son las mismas credenciales que usa `MySqlPdoHandler::connect()`.
- **Autoload:** `_assets/classes/<Nombre>.class.php` y `_assets/models/<Nombre>.php` se cargan solos (`index.php:85-95`). **No hay que tocar `index.php`** para agregar rutas ni clases: el controlador `merma` ya se autocarga y cualquier método público suyo queda expuesto en `/merma/<metodo>`.
- **Permiso:** todos los métodos nuevos usan `authorized(33)` vía la constante existente `Merma::PERM_VER`.
- **Familias de producto:** usar `MermaDiariaModel::FAMILIAS`, que ya existe: `'maxima' => [1,179,192]`, `'super' => [2,180,193]`, `'diesel' => [3,181]`. No redefinir estos códigos en otro lado.
- **Datos faltantes:** nunca renderizar `0` ni `#¡DIV/0!` cuando el insumo no existe. El valor interno es `null` y se muestra como `—`.
- **Idioma:** comentarios de código, textos de interfaz y mensajes de commit en español, como el resto del módulo.
- **Formato de commits:** `feat(merma): ...` / `fix(merma): ...`, sin `--no-verify`.

## File Structure

| Archivo | Responsabilidad |
|---|---|
| `_assets/models/MermaDiariaModel.php` *(modificar)* | Tres consultas nuevas: estaciones ordenadas, matriz de ventas del mes, totales mensuales por estación. Nada de cálculo. |
| `_assets/classes/VentasConsolidado.class.php` *(crear)* | Lógica pura, sin BD: definición de las 5 pestañas y construcción de la matriz + las 9 filas de resumen. Es el único lugar con fórmulas. |
| `_assets/controllers/merma.php` *(modificar)* | `ventas()` orquesta modelo + presupuesto + calculadora → vista. `ventas_excel()` reusa lo mismo → `.xlsx`. |
| `views/merma/ventas.html` *(crear)* | Renderizado: selector de periodo, 5 pestañas, tabla con columnas fijas. |
| `_assets/css/merma.css` *(modificar)* | Clases para la segunda columna fija (día de la semana) y las filas de resumen. |
| `views/layouts/sidebar.html` *(modificar)* | Enlace debajo de *Análisis de merma diaria*. |

El cálculo vive en `VentasConsolidado` y no en el controlador precisamente para que la Tarea 2 se pueda verificar con datos inventados, sin tocar la BD.

---

### Task 1: Consultas del modelo

**Files:**
- Modify: `_assets/models/MermaDiariaModel.php` (agregar tres métodos públicos al final de la clase)
- Verify: `<scratchpad>/verificar_task1.php` (no se commitea)

**Interfaces:**
- Consumes: `MermaDiariaModel::FAMILIAS`, `MermaDiariaModel::familiaCase(string $familia, string $columna): string` (método privado ya existente en la línea 34; devuelve `SUM(CASE WHEN codprd IN (...) THEN <columna> END)`).
- Produces:
  - `get_estaciones_ordenadas(): array` → lista de `['Codigo' => string, 'Nombre' => string, 'cveest' => ?string]` en orden de columna.
  - `get_ventas_mes(int $anio, int $mes): array` → `['YYYY-MM-DD' => [ (int)codgas => ['maxima' => ?float, 'super' => ?float, 'diesel' => ?float] ] ]`
  - `get_ventas_totales_mes(int $anio, int $mes): array` → `[ (int)codgas => ['maxima' => ?float, 'super' => ?float, 'diesel' => ?float] ]`

- [ ] **Step 1: Escribir el script de verificación (falla porque los métodos no existen)**

Crear `<scratchpad>/verificar_task1.php`:

```php
<?php
// Carga mínima del entorno de la app para poder instanciar el modelo desde CLI.
chdir('C:/Users/manue/OneDrive/Desktop/proyectos/aplicativoTG/Aplicativo2025');
$_SERVER['REQUEST_URI'] = '/'; // header.class.php define URI a partir de esto
require 'vendor/autoload.php';
require '_assets/classes/header.class.php';
require '_assets/classes/common/MySqlPdoHandler.class.php';
require '_assets/models/Model.php';
require '_assets/models/MermaDiariaModel.php';

$fallos = 0;
function check(string $nombre, bool $ok, string $detalle = ''): void {
    global $fallos;
    if (!$ok) { $fallos++; echo "FALLA: $nombre" . ($detalle ? " — $detalle" : '') . "\n"; }
    else      { echo "ok:    $nombre\n"; }
}

$m = new MermaDiariaModel();

// --- get_estaciones_ordenadas ---
$est = $m->get_estaciones_ordenadas();
check('estaciones: hay más de 30', count($est) > 30, 'devolvió ' . count($est));
check('estaciones: trae Codigo, Nombre, cveest',
      isset($est[0]['Codigo'], $est[0]['Nombre']) && array_key_exists('cveest', $est[0]));
$prefijos = array_map(fn($e) => (int) substr($e['Nombre'], 0, 2), $est);
$ordenado = $prefijos;
sort($ordenado, SORT_NUMERIC);
check('estaciones: ordenadas por prefijo numérico del Nombre', $prefijos === $ordenado,
      'primeras: ' . implode(',', array_slice($prefijos, 0, 6)));
check('estaciones: excluye códigos 0, 4 y 20',
      !array_intersect([0, 4, 20], array_map(fn($e) => (int) $e['Codigo'], $est)));

// --- get_ventas_mes --- (julio 2026 tiene 26 días con datos)
$v = $m->get_ventas_mes(2026, 7);
check('ventas_mes: 26 días con datos en julio 2026', count($v) === 26, 'devolvió ' . count($v));
check('ventas_mes: la llave es YYYY-MM-DD', isset($v['2026-07-01']), 'llaves: ' . implode(',', array_slice(array_keys($v), 0, 3)));
check('ventas_mes: no incluye días fuera del mes', !isset($v['2026-06-30']) && !isset($v['2026-08-01']));
$dia1 = $v['2026-07-01'];
check('ventas_mes: indexado por codgas entero', array_key_first($dia1) === (int) array_key_first($dia1));
$fila = reset($dia1);
check('ventas_mes: cada fila trae las 3 familias',
      array_keys($fila) === ['maxima', 'super', 'diesel'], implode(',', array_keys($fila)));

// La suma de la matriz debe cuadrar contra el total crudo de la tabla.
$sumaMatriz = 0.0;
foreach ($v as $porEst) {
    foreach ($porEst as $f) { $sumaMatriz += (float) $f['maxima'] + (float) $f['super'] + (float) $f['diesel']; }
}
$pdo = new PDO("sqlsrv:Server=192.168.0.6;Database=TG;TrustServerCertificate=yes;LoginTimeout=10", 'cguser', 'sahei1712');
$crudo = (float) $pdo->query(
    "SELECT SUM(ventas_reales) FROM TG.dbo.merma_diaria
     WHERE fecha >= '2026-07-01' AND fecha < '2026-08-01'"
)->fetchColumn();
check('ventas_mes: la matriz suma lo mismo que la tabla cruda',
      abs($sumaMatriz - $crudo) < 1.0, "matriz=$sumaMatriz crudo=$crudo");

// --- get_ventas_totales_mes ---
$t = $m->get_ventas_totales_mes(2026, 6);
check('totales_mes: junio 2026 trae 36 estaciones', count($t) === 36, 'devolvió ' . count($t));
$sumaTot = 0.0;
foreach ($t as $f) { $sumaTot += (float) $f['maxima'] + (float) $f['super'] + (float) $f['diesel']; }
$crudoJun = (float) $pdo->query(
    "SELECT SUM(ventas_reales) FROM TG.dbo.merma_diaria
     WHERE fecha >= '2026-06-01' AND fecha < '2026-07-01'"
)->fetchColumn();
check('totales_mes: cuadra contra la tabla cruda', abs($sumaTot - $crudoJun) < 1.0, "sum=$sumaTot crudo=$crudoJun");

// Un mes sin datos devuelve arreglo vacío, no error.
check('totales_mes: mes sin datos devuelve []', $m->get_ventas_totales_mes(2025, 7) === []);

echo $fallos === 0 ? "\nTODO OK\n" : "\n$fallos FALLA(S)\n";
exit($fallos === 0 ? 0 : 1);
```

- [ ] **Step 2: Correr el script para verificar que falla**

Run: `php <scratchpad>/verificar_task1.php`
Expected: FALLA con `Call to undefined method MermaDiariaModel::get_estaciones_ordenadas()`

- [ ] **Step 3: Implementar los tres métodos**

Agregar al final de la clase en `_assets/models/MermaDiariaModel.php`:

```php
    /* ===================================================================== */
    /* Reporte de ventas consolidado (/merma/ventas)                         */
    /* ===================================================================== */

    /**
     * Estaciones en orden de columna del reporte: por el número corporativo
     * que Nombre ya trae como prefijo ("02 Lerdo", "38 PRAXEDIS"). TRY_CAST
     * para que ordene como número y no como texto ("10" antes que "2").
     * Difiere de get_estaciones(), que ordena alfabéticamente.
     */
    public function get_estaciones_ordenadas(): array
    {
        $query = 'SELECT e.Codigo, e.Nombre, g.cveest
                  FROM [TG].[dbo].[Estaciones] e
                  LEFT JOIN [SG12].[dbo].[Gasolineras] g ON g.cod = e.Codigo
                  WHERE e.Codigo NOT IN (0, 4, 20)
                  ORDER BY TRY_CAST(LEFT(e.Nombre, 2) AS INT), e.Nombre;';
        return $this->sql->select($query) ?: [];
    }

    /**
     * Matriz día × estación del mes, con las tres familias en columnas.
     * ventas_reales es el mismo "VR" que el Excel jala de Formato<Mes><Año>.xlsm.
     *
     * @return array ['YYYY-MM-DD' => [codgas => ['maxima'=>?float,'super'=>?float,'diesel'=>?float]]]
     */
    public function get_ventas_mes(int $anio, int $mes): array
    {
        $primero = sprintf('%04d-%02d-01', $anio, $mes);
        $query = 'SELECT fecha, codgas,
                    ' . $this->familiaCase('maxima', 'ventas_reales') . ' AS maxima,
                    ' . $this->familiaCase('super', 'ventas_reales') . '  AS [super],
                    ' . $this->familiaCase('diesel', 'ventas_reales') . ' AS diesel
                  FROM [TG].[dbo].[merma_diaria]
                  WHERE fecha >= ? AND fecha < DATEADD(MONTH, 1, CAST(? AS DATE))
                  GROUP BY fecha, codgas
                  ORDER BY fecha, codgas;';
        $rows = $this->sql->select($query, [$primero, $primero]) ?: [];
        $out  = [];
        foreach ($rows as $r) {
            $fecha = substr((string) $r['fecha'], 0, 10);
            $out[$fecha][(int) $r['codgas']] = [
                'maxima' => $r['maxima'] === null ? null : (float) $r['maxima'],
                'super'  => $r['super']  === null ? null : (float) $r['super'],
                'diesel' => $r['diesel'] === null ? null : (float) $r['diesel'],
            ];
        }
        return $out;
    }

    /**
     * Totales del mes por estación y familia (sin desglose por día). Se usa
     * para los comparativos % M.A. y % A.A. del reporte de ventas.
     *
     * @return array [codgas => ['maxima'=>?float,'super'=>?float,'diesel'=>?float]]
     */
    public function get_ventas_totales_mes(int $anio, int $mes): array
    {
        $primero = sprintf('%04d-%02d-01', $anio, $mes);
        $query = 'SELECT codgas,
                    ' . $this->familiaCase('maxima', 'ventas_reales') . ' AS maxima,
                    ' . $this->familiaCase('super', 'ventas_reales') . '  AS [super],
                    ' . $this->familiaCase('diesel', 'ventas_reales') . ' AS diesel
                  FROM [TG].[dbo].[merma_diaria]
                  WHERE fecha >= ? AND fecha < DATEADD(MONTH, 1, CAST(? AS DATE))
                  GROUP BY codgas;';
        $rows = $this->sql->select($query, [$primero, $primero]) ?: [];
        $out  = [];
        foreach ($rows as $r) {
            $out[(int) $r['codgas']] = [
                'maxima' => $r['maxima'] === null ? null : (float) $r['maxima'],
                'super'  => $r['super']  === null ? null : (float) $r['super'],
                'diesel' => $r['diesel'] === null ? null : (float) $r['diesel'],
            ];
        }
        return $out;
    }
```

Nota: `super` va entre corchetes en el SQL porque es palabra reservada en T-SQL.

- [ ] **Step 4: Correr el script para verificar que pasa**

Run: `php <scratchpad>/verificar_task1.php`
Expected: `TODO OK`, código de salida 0

- [ ] **Step 5: Commit**

```bash
git add _assets/models/MermaDiariaModel.php
git commit -m "feat(merma): consultas de ventas por día y estación para el reporte consolidado

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 2: Calculadora de la matriz y las filas de resumen

**Files:**
- Create: `_assets/classes/VentasConsolidado.class.php`
- Verify: `<scratchpad>/verificar_task2.php` (no se commitea)

**Interfaces:**
- Consumes: nada. Clase pura, sin BD, sin estado. Recibe arreglos con la forma que produce la Tarea 1.
- Produces:
  - `VentasConsolidado::PESTANAS` → `array<string, array{label: string, familias: string[], codprd: int[]}>` con las llaves `total`, `reg_prem`, `regular`, `premium`, `diesel` **en ese orden** (define el orden de las pestañas en la vista y de las hojas del Excel).
  - `VentasConsolidado::construir(string $clave, array $ctx): array` → estructura documentada abajo.

- [ ] **Step 1: Escribir el script de verificación (falla porque la clase no existe)**

Crear `<scratchpad>/verificar_task2.php`:

```php
<?php
require 'C:/Users/manue/OneDrive/Desktop/proyectos/aplicativoTG/Aplicativo2025/_assets/classes/VentasConsolidado.class.php';

$fallos = 0;
function check(string $nombre, bool $ok, string $detalle = ''): void {
    global $fallos;
    if (!$ok) { $fallos++; echo "FALLA: $nombre" . ($detalle ? " — $detalle" : '') . "\n"; }
    else      { echo "ok:    $nombre\n"; }
}
function casi(?float $a, ?float $b, float $tol = 0.01): bool {
    if ($a === null || $b === null) return $a === $b;
    return abs($a - $b) < $tol;
}

// Escenario: 2 estaciones, abril 2026 (30 días), datos en los días 1..3.
// Est. 100 vende las 3 familias; est. 200 no vende diesel.
$ctx = [
    'estaciones' => [
        ['Codigo' => '100', 'Nombre' => '02 Uno', 'cveest' => 'E1'],
        ['Codigo' => '200', 'Nombre' => '03 Dos', 'cveest' => 'E2'],
    ],
    'ventas' => [
        '2026-04-01' => [
            100 => ['maxima' => 100.0, 'super' => 10.0, 'diesel' => 5.0],
            200 => ['maxima' => 200.0, 'super' => 20.0, 'diesel' => null],
        ],
        '2026-04-02' => [
            100 => ['maxima' => 110.0, 'super' => 12.0, 'diesel' => 6.0],
            200 => ['maxima' => 210.0, 'super' => 22.0, 'diesel' => null],
        ],
        '2026-04-03' => [
            100 => ['maxima' => 90.0,  'super' => 8.0,  'diesel' => 4.0],
            200 => ['maxima' => 190.0, 'super' => 18.0, 'diesel' => null],
        ],
    ],
    'presupuesto'   => [100 => [179 => 3000.0, 180 => 300.0, 181 => 150.0]], // la 200 no tiene
    'mes_anterior'  => [100 => ['maxima' => 2000.0, 'super' => 200.0, 'diesel' => 100.0]],
    'anio_anterior' => [],
    'anio' => 2026,
    'mes'  => 4,
];

// ---------- estructura ----------
$r = VentasConsolidado::construir('regular', $ctx);
check('30 filas de día en abril', count($r['dias']) === 30, 'hubo ' . count($r['dias']));
check('días con datos = 3', $r['dias_con_datos'] === 3);
check('días del mes = 30', $r['dias_del_mes'] === 30);
check('día 1 trae nombre de día de la semana', $r['dias'][0]['nombre'] !== '');
check('día 4 en blanco', $r['dias'][3]['celdas'][100] === null && $r['dias'][3]['total'] === null);

// ---------- proyección de producto ----------
check('regular día 1 est 100 = maxima', casi($r['dias'][0]['celdas'][100], 100.0));
check('regular día 1 total = 300', casi($r['dias'][0]['total'], 300.0));

$rp = VentasConsolidado::construir('reg_prem', $ctx);
check('reg_prem día 1 est 100 = 110', casi($rp['dias'][0]['celdas'][100], 110.0));

$t = VentasConsolidado::construir('total', $ctx);
check('total día 1 est 100 = 115', casi($t['dias'][0]['celdas'][100], 115.0));
check('total día 1 est 200 = 220 (sin diesel)', casi($t['dias'][0]['celdas'][200], 220.0));

$d = VentasConsolidado::construir('diesel', $ctx);
check('diesel: est 200 sin dato = null', $d['dias'][0]['celdas'][200] === null);
check('diesel día 1 total = 5', casi($d['dias'][0]['total'], 5.0));

// ---------- filas de resumen ----------
// regular: est100 = 100+110+90 = 300 ; est200 = 200+210+190 = 600 ; general = 900
check('TOTAL est 100 = 300', casi($r['resumen']['total']['celdas'][100], 300.0));
check('TOTAL general = 900',  casi($r['resumen']['total']['total'], 900.0));
check('% MIX est 100 = 33.33', casi($r['resumen']['mix']['celdas'][100], 33.3333, 0.001));
check('% MIX general = 100',   casi($r['resumen']['mix']['total'], 100.0));
// PROY = 300 / 3 * 30 = 3000
check('PROY est 100 = 3000', casi($r['resumen']['proy']['celdas'][100], 3000.0));
check('PROY general = 9000', casi($r['resumen']['proy']['total'], 9000.0));
// PPTO regular = codprd 179 + 192 = 3000
check('PPTO est 100 = 3000', casi($r['resumen']['ppto']['celdas'][100], 3000.0));
check('PPTO est 200 = null (sin presupuesto)', $r['resumen']['ppto']['celdas'][200] === null);
check('PPTO general = 3000 (suma lo que hay)', casi($r['resumen']['ppto']['total'], 3000.0));
check('DIFERENCIA est 100 = 0', casi($r['resumen']['dif']['celdas'][100], 0.0));
check('DIFERENCIA est 200 = null', $r['resumen']['dif']['celdas'][200] === null);
check('% PPTO est 100 = 0', casi($r['resumen']['pct_ppto']['celdas'][100], 0.0));
// % M.A. = 3000 / 2000 - 1 = 50%
check('% M.A. est 100 = 50', casi($r['resumen']['ma']['celdas'][100], 50.0));
check('% M.A. est 200 = null', $r['resumen']['ma']['celdas'][200] === null);
check('% A.A. est 100 = null (sin año anterior)', $r['resumen']['aa']['celdas'][100] === null);
check('VS SEMANA con 3 días de datos = null', $r['resumen']['vs_semana']['celdas'][100] === null);

// PPTO de la pestaña total = 179+180+181 = 3450
check('PPTO total est 100 = 3450', casi($t['resumen']['ppto']['celdas'][100], 3450.0));
// PPTO de premium = 180 + 193 = 300
$p = VentasConsolidado::construir('premium', $ctx);
check('PPTO premium est 100 = 300', casi($p['resumen']['ppto']['celdas'][100], 300.0));

// ---------- banderas ----------
check('sin_presupuesto = false cuando hay al menos uno', $r['sin_presupuesto'] === false);
$ctxSinPpto = $ctx; $ctxSinPpto['presupuesto'] = [];
$s = VentasConsolidado::construir('regular', $ctxSinPpto);
check('sin_presupuesto = true cuando no hay ninguno', $s['sin_presupuesto'] === true);
check('sin presupuesto, % PPTO es null', $s['resumen']['pct_ppto']['celdas'][100] === null);

// ---------- VS SEMANA PREVIA con 14 días ----------
// 14 días: los primeros 7 valen 10, los últimos 7 valen 20 → +100%
$ctx14 = $ctx; $ctx14['ventas'] = [];
for ($i = 1; $i <= 14; $i++) {
    $ctx14['ventas'][sprintf('2026-04-%02d', $i)] = [
        100 => ['maxima' => $i <= 7 ? 10.0 : 20.0, 'super' => null, 'diesel' => null],
        200 => ['maxima' => 5.0, 'super' => null, 'diesel' => null],
    ];
}
$v14 = VentasConsolidado::construir('regular', $ctx14);
check('VS SEMANA est 100 = 100%', casi($v14['resumen']['vs_semana']['celdas'][100], 100.0));
check('VS SEMANA est 200 = 0%',   casi($v14['resumen']['vs_semana']['celdas'][200], 0.0));

// ---------- mes vacío ----------
$ctxVacio = $ctx; $ctxVacio['ventas'] = [];
$e = VentasConsolidado::construir('regular', $ctxVacio);
check('mes vacío: días con datos = 0', $e['dias_con_datos'] === 0);
check('mes vacío: TOTAL es null', $e['resumen']['total']['celdas'][100] === null);
check('mes vacío: PROY es null (no divide entre cero)', $e['resumen']['proy']['celdas'][100] === null);
check('mes vacío: % MIX es null', $e['resumen']['mix']['celdas'][100] === null);

// ---------- febrero bisiesto y no bisiesto ----------
$feb = $ctx; $feb['mes'] = 2; $feb['ventas'] = [];
check('febrero 2026 = 28 días', count(VentasConsolidado::construir('regular', $feb)['dias']) === 28);
$feb['anio'] = 2028;
check('febrero 2028 = 29 días', count(VentasConsolidado::construir('regular', $feb)['dias']) === 29);

// ---------- clave inválida ----------
try { VentasConsolidado::construir('inexistente', $ctx); check('clave inválida lanza', false); }
catch (InvalidArgumentException $ex) { check('clave inválida lanza InvalidArgumentException', true); }

echo $fallos === 0 ? "\nTODO OK\n" : "\n$fallos FALLA(S)\n";
exit($fallos === 0 ? 0 : 1);
```

- [ ] **Step 2: Correr el script para verificar que falla**

Run: `php <scratchpad>/verificar_task2.php`
Expected: FALLA con `Failed to open stream` o `Class "VentasConsolidado" not found`

- [ ] **Step 3: Implementar la clase**

Crear `_assets/classes/VentasConsolidado.class.php`:

```php
<?php

/**
 * Reporte de Ventas Consolidado — cálculo puro (sin BD).
 *
 * Reemplaza las cinco hojas vivas de "VETS X EST X MARCA Y PROTS <Mes><Año>.xlsm".
 * Recibe la matriz día × estación × familia que arma MermaDiariaModel y la
 * proyecta en la pestaña pedida, más las nueve filas de resumen del Excel.
 *
 * Spec: docs/superpowers/specs/2026-07-28-reporte-ventas-consolidado-design.md
 */
class VentasConsolidado
{
    /**
     * Las cinco hojas vivas del libro, en orden de pestaña.
     *  - familias: qué sumar de la matriz de ventas.
     *  - codprd:   qué sumar de TGV2.dbo.Budget. Los pares 179/192 (máxima) y
     *              180/193 (súper) conviven porque los años viejos usan los
     *              segundos; sumar ambos funciona en cualquier año.
     */
    public const PESTANAS = [
        'total'    => ['label' => 'LITROS DE COMBUSTIBLE', 'familias' => ['maxima', 'super', 'diesel'], 'codprd' => [179, 192, 180, 193, 181]],
        'reg_prem' => ['label' => 'REGULAR + PREMIUM',     'familias' => ['maxima', 'super'],           'codprd' => [179, 192, 180, 193]],
        'regular'  => ['label' => 'REGULAR',               'familias' => ['maxima'],                    'codprd' => [179, 192]],
        'premium'  => ['label' => 'PREMIUM',               'familias' => ['super'],                     'codprd' => [180, 193]],
        'diesel'   => ['label' => 'DIESEL',                'familias' => ['diesel'],                    'codprd' => [181]],
    ];

    /** Días de la semana en español, indexados por date('w'). */
    private const DIAS_SEMANA = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];

    /**
     * @param string $clave  llave de self::PESTANAS
     * @param array  $ctx    [
     *   'estaciones'    => [['Codigo'=>string|int,'Nombre'=>string,'cveest'=>?string], ...] en orden de columna
     *   'ventas'        => ['YYYY-MM-DD' => [codgas => ['maxima'=>?float,'super'=>?float,'diesel'=>?float]]]
     *   'presupuesto'   => [codgas => [codprd => float]]
     *   'mes_anterior'  => [codgas => ['maxima'=>?float,'super'=>?float,'diesel'=>?float]]
     *   'anio_anterior' => [codgas => ['maxima'=>?float,'super'=>?float,'diesel'=>?float]]
     *   'anio'          => int
     *   'mes'           => int
     * ]
     * @return array [
     *   'label'           => string,
     *   'dias'            => [['dia'=>int,'nombre'=>string,'celdas'=>[codgas=>?float],'total'=>?float], ...],
     *   'resumen'         => [clave => ['celdas'=>[codgas=>?float], 'total'=>?float]] con las claves
     *                        total, mix, proy, ppto, dif, pct_ppto, vs_semana, ma, aa
     *   'dias_del_mes'    => int,
     *   'dias_con_datos'  => int,
     *   'sin_presupuesto' => bool,
     * ]
     * @throws InvalidArgumentException si $clave no es una pestaña conocida
     */
    public static function construir(string $clave, array $ctx): array
    {
        if (!isset(self::PESTANAS[$clave])) {
            throw new InvalidArgumentException("Pestaña desconocida: $clave");
        }
        $pestana  = self::PESTANAS[$clave];
        $familias = $pestana['familias'];
        $anio     = (int) $ctx['anio'];
        $mes      = (int) $ctx['mes'];
        $codgases = array_map(fn($e) => (int) $e['Codigo'], $ctx['estaciones']);

        $diasDelMes = (int) date('t', mktime(0, 0, 0, $mes, 1, $anio));

        // Fechas con al menos un registro, en orden. Es un escalar único para
        // todo el reporte (equivale a "DIAS LABORADOS" del Excel, celda C299),
        // no un conteo por estación.
        $fechasConDatos = array_keys($ctx['ventas']);
        sort($fechasConDatos);
        $diasConDatos = count($fechasConDatos);

        // --- filas de día ---
        $dias = [];
        for ($d = 1; $d <= $diasDelMes; $d++) {
            $fecha  = sprintf('%04d-%02d-%02d', $anio, $mes, $d);
            $delDia = $ctx['ventas'][$fecha] ?? [];
            $celdas = [];
            $total  = null;
            foreach ($codgases as $cod) {
                $valor = isset($delDia[$cod]) ? self::sumarFamilias($delDia[$cod], $familias) : null;
                $celdas[$cod] = $valor;
                if ($valor !== null) $total = ($total ?? 0.0) + $valor;
            }
            $dias[] = [
                'dia'    => $d,
                'nombre' => self::DIAS_SEMANA[(int) date('w', mktime(0, 0, 0, $mes, $d, $anio))],
                'celdas' => $celdas,
                'total'  => $total,
            ];
        }

        // --- TOTAL del mes por columna ---
        $total = self::filaVacia($codgases);
        foreach ($dias as $fila) {
            foreach ($codgases as $cod) {
                if ($fila['celdas'][$cod] !== null) {
                    $total['celdas'][$cod] = ($total['celdas'][$cod] ?? 0.0) + $fila['celdas'][$cod];
                }
            }
        }
        $total['total'] = self::sumarCeldas($total['celdas']);

        // --- % MIX ---
        $mix = self::filaVacia($codgases);
        foreach ($codgases as $cod) {
            $mix['celdas'][$cod] = ($total['total'] !== null && $total['total'] != 0.0 && $total['celdas'][$cod] !== null)
                ? $total['celdas'][$cod] / $total['total'] * 100 : null;
        }
        $mix['total'] = $total['total'] !== null && $total['total'] != 0.0 ? 100.0 : null;

        // --- PROY. MENSUAL = TOTAL / días con datos × días del mes ---
        $proy = self::filaVacia($codgases);
        foreach ($codgases as $cod) {
            $proy['celdas'][$cod] = ($diasConDatos > 0 && $total['celdas'][$cod] !== null)
                ? $total['celdas'][$cod] / $diasConDatos * $diasDelMes : null;
        }
        $proy['total'] = self::sumarCeldas($proy['celdas']);

        // --- PRESUPUESTO ---
        $ppto = self::filaVacia($codgases);
        foreach ($codgases as $cod) {
            $porPrd = $ctx['presupuesto'][$cod] ?? null;
            if ($porPrd === null) continue;
            $suma = null;
            foreach ($pestana['codprd'] as $prd) {
                if (isset($porPrd[$prd])) $suma = ($suma ?? 0.0) + (float) $porPrd[$prd];
            }
            $ppto['celdas'][$cod] = $suma;
        }
        $ppto['total'] = self::sumarCeldas($ppto['celdas']);

        // --- DIFERENCIA y % PRESUPUESTO ---
        $dif     = self::filaVacia($codgases);
        $pctPpto = self::filaVacia($codgases);
        foreach ($codgases as $cod) {
            $p = $proy['celdas'][$cod];
            $b = $ppto['celdas'][$cod];
            $dif['celdas'][$cod]     = ($p !== null && $b !== null) ? $p - $b : null;
            $pctPpto['celdas'][$cod] = self::pctCambio($p, $b);
        }
        $dif['total']     = ($proy['total'] !== null && $ppto['total'] !== null) ? $proy['total'] - $ppto['total'] : null;
        $pctPpto['total'] = self::pctCambio($proy['total'], $ppto['total']);

        // --- VS SEMANA PREVIA: últimos 7 días con dato contra los 7 anteriores.
        // El Excel usa filas fijas (33 vs 26); anclarlo al último día con dato
        // hace que también funcione a mitad de mes.
        $vsSemana = self::filaVacia($codgases);
        if ($diasConDatos >= 14) {
            $ultimos  = array_slice($fechasConDatos, -7);
            $previos  = array_slice($fechasConDatos, -14, 7);
            foreach ($codgases as $cod) {
                $a = self::sumarRango($ctx['ventas'], $ultimos, $cod, $familias);
                $b = self::sumarRango($ctx['ventas'], $previos, $cod, $familias);
                $vsSemana['celdas'][$cod] = self::pctCambio($a, $b);
            }
            $ta = self::sumarRangoTodas($ctx['ventas'], $ultimos, $codgases, $familias);
            $tb = self::sumarRangoTodas($ctx['ventas'], $previos, $codgases, $familias);
            $vsSemana['total'] = self::pctCambio($ta, $tb);
        }

        // --- % M.A. y % A.A.: proyección contra el total del mes de referencia ---
        $ma = self::filaVacia($codgases);
        $aa = self::filaVacia($codgases);
        $maTotal = null;
        $aaTotal = null;
        foreach ($codgases as $cod) {
            $refMa = isset($ctx['mes_anterior'][$cod])  ? self::sumarFamilias($ctx['mes_anterior'][$cod], $familias)  : null;
            $refAa = isset($ctx['anio_anterior'][$cod]) ? self::sumarFamilias($ctx['anio_anterior'][$cod], $familias) : null;
            $ma['celdas'][$cod] = self::pctCambio($proy['celdas'][$cod], $refMa);
            $aa['celdas'][$cod] = self::pctCambio($proy['celdas'][$cod], $refAa);
            if ($refMa !== null) $maTotal = ($maTotal ?? 0.0) + $refMa;
            if ($refAa !== null) $aaTotal = ($aaTotal ?? 0.0) + $refAa;
        }
        $ma['total'] = self::pctCambio($proy['total'], $maTotal);
        $aa['total'] = self::pctCambio($proy['total'], $aaTotal);

        return [
            'label'   => $pestana['label'],
            'dias'    => $dias,
            'resumen' => [
                'total'     => $total,
                'mix'       => $mix,
                'proy'      => $proy,
                'ppto'      => $ppto,
                'dif'       => $dif,
                'pct_ppto'  => $pctPpto,
                'vs_semana' => $vsSemana,
                'ma'        => $ma,
                'aa'        => $aa,
            ],
            'dias_del_mes'    => $diasDelMes,
            'dias_con_datos'  => $diasConDatos,
            'sin_presupuesto' => $ppto['total'] === null,
        ];
    }

    /** Fila de resumen con todas las celdas en null. */
    private static function filaVacia(array $codgases): array
    {
        return ['celdas' => array_fill_keys($codgases, null), 'total' => null];
    }

    /** Suma las familias de una fila; null si ninguna tiene dato. */
    private static function sumarFamilias(array $fila, array $familias): ?float
    {
        $suma = null;
        foreach ($familias as $f) {
            if (isset($fila[$f]) && $fila[$f] !== null) $suma = ($suma ?? 0.0) + (float) $fila[$f];
        }
        return $suma;
    }

    /** Suma las celdas no nulas; null si todas son null. */
    private static function sumarCeldas(array $celdas): ?float
    {
        $suma = null;
        foreach ($celdas as $v) {
            if ($v !== null) $suma = ($suma ?? 0.0) + $v;
        }
        return $suma;
    }

    /** Suma de una estación en un conjunto de fechas. */
    private static function sumarRango(array $ventas, array $fechas, int $codgas, array $familias): ?float
    {
        $suma = null;
        foreach ($fechas as $f) {
            if (!isset($ventas[$f][$codgas])) continue;
            $v = self::sumarFamilias($ventas[$f][$codgas], $familias);
            if ($v !== null) $suma = ($suma ?? 0.0) + $v;
        }
        return $suma;
    }

    /** Suma de todas las estaciones en un conjunto de fechas. */
    private static function sumarRangoTodas(array $ventas, array $fechas, array $codgases, array $familias): ?float
    {
        $suma = null;
        foreach ($codgases as $cod) {
            $v = self::sumarRango($ventas, $fechas, $cod, $familias);
            if ($v !== null) $suma = ($suma ?? 0.0) + $v;
        }
        return $suma;
    }

    /** Variación porcentual a/b - 1, en puntos porcentuales. null si no aplica. */
    private static function pctCambio(?float $a, ?float $b): ?float
    {
        if ($a === null || $b === null || $b == 0.0) return null;
        return ($a / $b - 1) * 100;
    }
}
```

Las llaves de `resumen` son las que consumen la vista (Tarea 3) y el exportador (Tarea 4): `total`, `mix`, `proy`, `ppto`, `dif`, `pct_ppto`, `vs_semana`, `ma`, `aa`. No renombrar ninguna.

- [ ] **Step 4: Correr el script para verificar que pasa**

Run: `php <scratchpad>/verificar_task2.php`
Expected: `TODO OK`, código de salida 0

- [ ] **Step 5: Commit**

```bash
git add _assets/classes/VentasConsolidado.class.php
git commit -m "feat(merma): calculadora del reporte de ventas consolidado

Lógica pura sin BD: proyecta la matriz día x estación en las cinco
pestañas del Excel y calcula las nueve filas de resumen.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 3: Controlador, vista y navegación

**Files:**
- Modify: `_assets/controllers/merma.php` (agregar `ventas()` después de `detalle()`, que termina en la línea 250)
- Create: `views/merma/ventas.html`
- Modify: `_assets/css/merma.css` (agregar bloque al final)
- Modify: `views/layouts/sidebar.html:207-209` (agregar `<li>` después del de merma/analisis)
- Verify: navegador en `http://localhost:8001/merma/ventas`

**Interfaces:**
- Consumes: `MermaDiariaModel::get_estaciones_ordenadas()`, `get_ventas_mes()`, `get_ventas_totales_mes()` (Tarea 1); `VentasConsolidado::PESTANAS`, `VentasConsolidado::construir()` (Tarea 2); `BudgetModel::getBudget($mes, $anio)` (ya existe, `_assets/models/BudgetModel.php`, devuelve filas planas con `codgas`, `codprd`, `budget_monthy`).
- Produces: `Merma::ventas(): void`; y el método privado `Merma::armarReporte(int $anio, int $mes): array` que la Tarea 4 reusa, con la forma `['anio'=>int,'mes'=>int,'estaciones'=>array,'pestanas'=>[clave=>resultado de construir()],'sin_presupuesto'=>bool]`.

- [ ] **Step 1: Agregar el método al controlador**

En `_assets/controllers/merma.php`, agregar el `use` de `BudgetModel` no hace falta (autoload). Insertar después de `detalle()` (línea 250), antes del bloque de comentario `/* Corrección de cortes físicos */`:

```php
    /**
     * Reporte de Ventas Consolidado — reemplaza el libro
     * "VETS X EST X MARCA Y PROTS <Mes><Año>.xlsm".
     *
     * Cinco pestañas de producto sobre la misma matriz día × estación de
     * merma_diaria.ventas_reales (el "VR" del Excel), más el presupuesto de
     * TGV2.dbo.Budget.
     */
    public function ventas(): void
    {
        if (!authorized(self::PERM_VER)) {
            (new Errors())->get404();
            return;
        }
        // Igual que analisis(): el día en curso nunca tiene turnos completos,
        // así que el mes por defecto es el de ayer.
        $ayer = strtotime('yesterday');
        $anio = (int) ($_GET['anio'] ?? date('Y', $ayer));
        $mes  = (int) ($_GET['mes']  ?? date('n', $ayer));
        if ($mes < 1 || $mes > 12)         $mes  = (int) date('n', $ayer);
        if ($anio < 2020 || $anio > 2100)  $anio = (int) date('Y', $ayer);

        $reporte = $this->armarReporte($anio, $mes);

        echo $this->twig->render($this->route . 'ventas.html', $reporte + [
            'anios'  => range((int) date('Y', $ayer), 2026),
            'meses'  => ['ENERO', 'FEBRERO', 'MARZO', 'ABRIL', 'MAYO', 'JUNIO',
                         'JULIO', 'AGOSTO', 'SEPTIEMBRE', 'OCTUBRE', 'NOVIEMBRE', 'DICIEMBRE'],
        ]);
    }

    /**
     * Junta modelo + presupuesto + calculadora. Lo comparten la vista y la
     * exportación a Excel para que no se puedan desincronizar.
     */
    private function armarReporte(int $anio, int $mes): array
    {
        // Codigo llega como string desde PDO; las llaves de las celdas que
        // arma VentasConsolidado son enteros. Se castea una sola vez aquí para
        // que la vista y el exportador indexen sin sorpresas.
        $estaciones = array_map(
            fn($e) => ['Codigo' => (int) $e['Codigo'], 'Nombre' => $e['Nombre'], 'cveest' => $e['cveest'] ?? null],
            $this->mermaModel->get_estaciones_ordenadas()
        );
        $ventas = $this->mermaModel->get_ventas_mes($anio, $mes);

        // Mes anterior y mismo mes del año pasado, para % M.A. y % A.A.
        $mesAnt  = $mes === 1 ? 12 : $mes - 1;
        $anioAnt = $mes === 1 ? $anio - 1 : $anio;

        // Presupuesto: BudgetModel devuelve filas planas; se indexa por estación y producto.
        $budget      = (new BudgetModel())->getBudget($mes, $anio) ?: [];
        $presupuesto = [];
        foreach ($budget as $b) {
            $presupuesto[(int) $b['codgas']][(int) $b['codprd']] = (float) $b['budget_monthy'];
        }

        $ctx = [
            'estaciones'    => $estaciones,
            'ventas'        => $ventas,
            'presupuesto'   => $presupuesto,
            'mes_anterior'  => $this->mermaModel->get_ventas_totales_mes($anioAnt, $mesAnt),
            'anio_anterior' => $this->mermaModel->get_ventas_totales_mes($anio - 1, $mes),
            'anio'          => $anio,
            'mes'           => $mes,
        ];

        $pestanas = [];
        foreach (array_keys(VentasConsolidado::PESTANAS) as $clave) {
            $pestanas[$clave] = VentasConsolidado::construir($clave, $ctx);
        }

        return [
            'anio'            => $anio,
            'mes'             => $mes,
            'estaciones'      => $estaciones,
            'pestanas'        => $pestanas,
            'sin_presupuesto' => $presupuesto === [],
        ];
    }
```

- [ ] **Step 2: Crear la vista**

Crear `views/merma/ventas.html`:

```twig
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
                <a href="/merma/ventas_excel?anio={{ anio }}&mes={{ mes }}" class="btn btn-merma-sync">
                    <i class="fas fa-file-excel"></i> Exportar a Excel
                </a>
            </div>
        </form>
    </div>
</div>

{% if sin_presupuesto %}
<div class="alert alert-warning py-2">
    Sin presupuesto cargado para {{ meses[mes - 1]|lower }} {{ anio }}.
    Cárgalo en Comercial &rarr; Importar presupuesto. Mientras tanto, las filas
    PRESUPUESTO, DIFERENCIA y % PRESUPUESTO salen vacías.
</div>
{% endif %}

<ul class="nav nav-tabs merma-tabs" role="tablist">
    {% for clave, p in pestanas %}
    <li class="nav-item">
        <a class="nav-link {{ loop.first ? 'active' : '' }}" data-bs-toggle="tab"
           href="#tab-{{ clave }}" role="tab">{{ p.label }}</a>
    </li>
    {% endfor %}
</ul>

<div class="tab-content">
    {% for clave, p in pestanas %}
    <div class="tab-pane fade {{ loop.first ? 'show active' : '' }}" id="tab-{{ clave }}" role="tabpanel">
        <div class="merma-tabla-wrap mb-3">
            <div class="merma-scroll">
                <table class="merma-tabla vc-tabla">
                    <thead>
                        <tr>
                            <th class="col-fecha">DÍA</th>
                            <th class="col-turno">&nbsp;</th>
                            {% for e in estaciones %}
                            <th>{{ e.Nombre }}</th>
                            {% endfor %}
                            <th class="th-grupo">TOTAL</th>
                        </tr>
                    </thead>
                    <tbody>
                        {% for d in p.dias %}
                        <tr>
                            <td class="col-fecha">{{ d.dia }}</td>
                            <td class="col-turno">{{ d.nombre }}</td>
                            {% for e in estaciones %}
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
                        <tr class="vc-resumen">
                            <td class="col-fecha">{{ r.l }}</td>
                            <td class="col-turno">&nbsp;</td>
                            {% for e in estaciones %}
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
        <p class="text-muted small">
            {{ p.dias_con_datos }} de {{ p.dias_del_mes }} días con datos.
            La proyección mensual extrapola el total a los {{ p.dias_del_mes }} días del mes.
        </p>
    </div>
    {% endfor %}
</div>

{% endblock %}
```

`d.celdas[e.Codigo]` funciona porque `armarReporte()` ya casteó `Codigo` a entero, igual que las llaves que produce `VentasConsolidado`. Si alguna columna sale entera en `—` mientras la fila TOTAL sí trae número, revisa que ese casteo siga en su lugar.

- [ ] **Step 3: Agregar el CSS**

Agregar al final de `_assets/css/merma.css`:

```css
/* ---- Reporte de ventas consolidado (/merma/ventas) --------------------- */
/* Reusa .merma-tabla; solo redefine el ancho de las dos columnas fijas:
   la primera es el número de día y la segunda el día de la semana. */
.vc-tabla .col-fecha { width: 130px; min-width: 130px; max-width: 130px; font-weight: 600; }
.vc-tabla .col-turno { left: 130px; width: 90px; min-width: 90px; max-width: 90px;
                       text-align: left; text-transform: capitalize; }

/* Las 9 filas de resumen van en tfoot; a diferencia del detalle, aquí NO se
   pegan al fondo (son nueve, taparían media tabla). */
.vc-tabla tfoot td { position: static; }
.vc-tabla tfoot tr.vc-resumen td {
    background: #f8fafc; font-weight: 600; color: #0f172a;
    border-top: 1px solid #cbd5e1;
}
.vc-tabla tfoot tr.vc-resumen:first-child td { border-top: 2px solid #94a3b8; }
.vc-tabla tfoot tr.vc-resumen td.col-fecha,
.vc-tabla tfoot tr.vc-resumen td.col-turno {
    position: sticky; z-index: 20; background: #f1f5f9;
    font-size: .72rem; letter-spacing: .03em; text-transform: none;
}
```

- [ ] **Step 4: Agregar el enlace al sidebar**

En `views/layouts/sidebar.html`, después del `<li>` que contiene `/merma/analisis` (líneas 207-209):

```html
          <li class="sidebar-item">
            <a class="sidebar-link" href="/merma/ventas">Reporte de ventas consolidado</a>
          </li>
```

- [ ] **Step 5: Verificar en el navegador**

Levantar el servidor: `php -S localhost:8001 router.php`

Abrir `http://localhost:8001/merma/ventas` e ir revisando:

1. El sidebar muestra *Reporte de ventas consolidado* debajo de *Análisis de merma diaria*, y el enlace lleva a la vista.
2. El selector arranca en el mes de ayer (julio 2026).
3. Se ven las 5 pestañas en el orden LITROS DE COMBUSTIBLE → REGULAR + PREMIUM → REGULAR → PREMIUM → DIESEL, y cambiar de pestaña cambia los números.
4. Hay 31 filas de día; los días 27–31 salen en `—` (julio solo tiene 26 días sincronizados).
5. Al hacer scroll horizontal, las columnas DÍA y día-de-la-semana se quedan fijas; al hacer scroll vertical, el encabezado se queda fijo.
6. Sale el aviso amarillo de "Sin presupuesto cargado para julio 2026", y las filas PRESUPUESTO, DIFERENCIA y % PRESUPUESTO están todas en `—`.
7. `% M.A.` tiene números (junio 2026 existe); `% A.A.` está en `—` (no hay julio 2025).
8. `VS SEMANA PREVIA` tiene números (26 días ≥ 14).
9. Los porcentajes negativos salen en rojo y los positivos en azul.
10. **Cuadre contra el Excel:** en la pestaña LITROS DE COMBUSTIBLE, la celda TOTAL de la columna TOTAL debe quedar cerca de la celda `AE39` de la hoja `LITROS DE COMBUSTIBLE` del libro `VETS X EST X MARCA Y PROTS Jul2026.xlsm`. No van a ser idénticas — el libro tiene 27 estaciones y la vista tiene 37 — pero deben ser del mismo orden de magnitud (~21.5 millones de litros para julio). Si difieren por más del 20%, revisar días sin sincronizar antes de sospechar de la fórmula.
11. Probar `?anio=2026&mes=3` (mes sin datos): la tabla debe salir completa en `—` sin errores PHP ni división entre cero.
12. Revisar `logs/php_errors.log`: no debe haber entradas nuevas.

- [ ] **Step 6: Commit**

```bash
git add _assets/controllers/merma.php views/merma/ventas.html _assets/css/merma.css views/layouts/sidebar.html
git commit -m "feat(merma): vista del reporte de ventas consolidado

Cinco pestañas de producto sobre merma_diaria, con las nueve filas de
resumen del Excel y el presupuesto de TGV2.dbo.Budget.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 4: Exportación a Excel

**Files:**
- Modify: `_assets/controllers/merma.php` (agregar `use` de PhpSpreadsheet arriba del archivo y `ventas_excel()` después de `ventas()`)
- Verify: descarga desde el navegador + `<scratchpad>/verificar_task4.php`

**Interfaces:**
- Consumes: `Merma::armarReporte(int $anio, int $mes): array` (Tarea 3), `VentasConsolidado::PESTANAS` (Tarea 2).
- Produces: `Merma::ventas_excel(): void` — responde un `.xlsx` de 5 hojas.

- [ ] **Step 1: Implementar el método**

Agregar arriba de `_assets/controllers/merma.php`, antes de `class Merma`:

```php
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
```

Y después de `ventas()`:

```php
    /**
     * Descarga el reporte en .xlsx con las cinco hojas del libro original,
     * pero con valores en vez de fórmulas y referencias externas.
     */
    public function ventas_excel(): void
    {
        set_time_limit(0);
        if (!authorized(self::PERM_VER)) {
            (new Errors())->get404();
            return;
        }
        $ayer = strtotime('yesterday');
        $anio = (int) ($_GET['anio'] ?? date('Y', $ayer));
        $mes  = (int) ($_GET['mes']  ?? date('n', $ayer));
        if ($mes < 1 || $mes > 12)        $mes  = (int) date('n', $ayer);
        if ($anio < 2020 || $anio > 2100) $anio = (int) date('Y', $ayer);

        $reporte    = $this->armarReporte($anio, $mes);
        $estaciones = $reporte['estaciones'];

        $filasResumen = [
            'total'     => 'TOTAL',
            'mix'       => '% MIX',
            'proy'      => 'PROY. MENSUAL',
            'ppto'      => 'PRESUPUESTO',
            'dif'       => 'DIFERENCIA',
            'pct_ppto'  => '% PRESUPUESTO',
            'vs_semana' => 'VS SEMANA PREVIA',
            'ma'        => '% M.A.',
            'aa'        => '% A.A.',
        ];
        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);

        foreach ($reporte['pestanas'] as $clave => $p) {
            $sheet = $spreadsheet->createSheet();
            // El título de hoja de Excel tolera 31 caracteres; los labels caben.
            $sheet->setTitle($p['label']);

            $sheet->setCellValue('A1', 'DÍA');
            $sheet->setCellValue('B1', '');
            $col = 3;
            foreach ($estaciones as $e) {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($col) . '1', $e['Nombre']);
                $col++;
            }
            $colTotal = Coordinate::stringFromColumnIndex($col);
            $sheet->setCellValue($colTotal . '1', 'TOTAL');
            $sheet->getStyle('A1:' . $colTotal . '1')->getFont()->setBold(true);

            $fila = 2;
            foreach ($p['dias'] as $d) {
                $sheet->setCellValue('A' . $fila, $d['dia']);
                $sheet->setCellValue('B' . $fila, $d['nombre']);
                $col = 3;
                foreach ($estaciones as $e) {
                    $v = $d['celdas'][(int) $e['Codigo']];
                    if ($v !== null) {
                        $sheet->setCellValue(Coordinate::stringFromColumnIndex($col) . $fila, round($v, 2));
                    }
                    $col++;
                }
                if ($d['total'] !== null) {
                    $sheet->setCellValue($colTotal . $fila, round($d['total'], 2));
                }
                $fila++;
            }

            $fila++; // renglón en blanco entre días y resumen
            $inicioResumen = $fila;
            foreach ($filasResumen as $k => $etiqueta) {
                $r = $p['resumen'][$k];
                $sheet->setCellValue('A' . $fila, $etiqueta);
                $col = 3;
                foreach ($estaciones as $e) {
                    $v = $r['celdas'][(int) $e['Codigo']];
                    if ($v !== null) {
                        $sheet->setCellValue(Coordinate::stringFromColumnIndex($col) . $fila, round($v, 2));
                    }
                    $col++;
                }
                if ($r['total'] !== null) {
                    $sheet->setCellValue($colTotal . $fila, round($r['total'], 2));
                }
                $fila++;
            }
            $sheet->getStyle('A' . $inicioResumen . ':' . $colTotal . ($fila - 1))
                  ->getFont()->setBold(true);

            $sheet->getColumnDimension('A')->setWidth(20);
            $sheet->getColumnDimension('B')->setWidth(12);
            $sheet->freezePane('C2');
        }
        $spreadsheet->setActiveSheetIndex(0);

        $archivo = sprintf('ventas_consolidado_%04d_%02d.xlsx', $anio, $mes);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header("Content-Disposition: attachment;filename=\"{$archivo}\"");
        header('Cache-Control: max-age=0');
        (new Xlsx($spreadsheet))->save('php://output');
        exit;
    }
```

Los porcentajes se escriben como número en puntos porcentuales (`2.52` = 2.52%), igual que se muestran en pantalla, no como fracción — así el archivo se lee igual que la vista sin que Excel reinterprete el formato.

- [ ] **Step 2: Descargar y verificar el archivo**

Con el servidor levantado, abrir `http://localhost:8001/merma/ventas_excel?anio=2026&mes=7`. Debe descargar `ventas_consolidado_2026_07.xlsx`.

Crear `<scratchpad>/verificar_task4.php` apuntando al archivo descargado:

```php
<?php
require 'C:/Users/manue/OneDrive/Desktop/proyectos/aplicativoTG/Aplicativo2025/vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

$ruta = $argv[1] ?? 'C:/Users/manue/Downloads/ventas_consolidado_2026_07.xlsx';
$fallos = 0;
function check(string $n, bool $ok, string $d = ''): void {
    global $fallos;
    if (!$ok) { $fallos++; echo "FALLA: $n" . ($d ? " — $d" : '') . "\n"; } else { echo "ok:    $n\n"; }
}

$ss = IOFactory::load($ruta);
$nombres = $ss->getSheetNames();
check('5 hojas', count($nombres) === 5, implode(' | ', $nombres));
check('nombres y orden correctos', $nombres === [
    'LITROS DE COMBUSTIBLE', 'REGULAR + PREMIUM', 'REGULAR', 'PREMIUM', 'DIESEL'
], implode(' | ', $nombres));

$h = $ss->getSheetByName('LITROS DE COMBUSTIBLE');
check('A1 = DÍA', $h->getCell('A1')->getValue() === 'DÍA');
check('C1 tiene nombre de estación', is_string($h->getCell('C1')->getValue()) && $h->getCell('C1')->getValue() !== '');
check('día 1 en A2', (int) $h->getCell('A2')->getValue() === 1);
check('día 31 en A32', (int) $h->getCell('A32')->getValue() === 31);
check('C2 es numérico', is_numeric($h->getCell('C2')->getValue()), (string) $h->getCell('C2')->getValue());

// El bloque de resumen arranca en la fila 34 (32 días + 1 blanco).
check('A34 = TOTAL', $h->getCell('A34')->getValue() === 'TOTAL');
check('A35 = % MIX', $h->getCell('A35')->getValue() === '% MIX');
check('A42 = % A.A.', $h->getCell('A42')->getValue() === '% A.A.');

// LITROS = REGULAR + PREMIUM + DIESEL, celda a celda en el día 1.
$tot = (float) $h->getCell('C2')->getValue();
$reg = (float) $ss->getSheetByName('REGULAR')->getCell('C2')->getValue();
$pre = (float) $ss->getSheetByName('PREMIUM')->getCell('C2')->getValue();
$die = (float) $ss->getSheetByName('DIESEL')->getCell('C2')->getValue();
check('LITROS = REGULAR + PREMIUM + DIESEL', abs($tot - ($reg + $pre + $die)) < 0.05,
      "$tot vs " . ($reg + $pre + $die));

$rp = (float) $ss->getSheetByName('REGULAR + PREMIUM')->getCell('C2')->getValue();
check('REG+PREM = REGULAR + PREMIUM', abs($rp - ($reg + $pre)) < 0.05, "$rp vs " . ($reg + $pre));

echo $fallos === 0 ? "\nTODO OK\n" : "\n$fallos FALLA(S)\n";
exit($fallos === 0 ? 0 : 1);
```

Run: `php <scratchpad>/verificar_task4.php "C:/Users/manue/Downloads/ventas_consolidado_2026_07.xlsx"`
Expected: `TODO OK`

Además, abrir el archivo en Excel y confirmar que al hacer scroll las columnas A y B se quedan fijas (`freezePane('C2')`).

- [ ] **Step 3: Commit**

```bash
git add _assets/controllers/merma.php
git commit -m "feat(merma): exportar el reporte de ventas consolidado a Excel

Genera las cinco hojas del libro original con valores, sin fórmulas ni
referencias externas a Formato<Mes><Año>.xlsm.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Verificación final

Después de la Tarea 4, correr los tres scripts de verificación en orden y confirmar que los tres terminan en `TODO OK`:

```bash
php <scratchpad>/verificar_task1.php
php <scratchpad>/verificar_task2.php
php <scratchpad>/verificar_task4.php "C:/Users/manue/Downloads/ventas_consolidado_2026_07.xlsx"
```

Y confirmar que `git status` no deja archivos del scratchpad dentro del repositorio.

## Pendientes de datos (fuera de este plan)

Estos dos no bloquean la implementación, pero el reporte no queda completo sin ellos. Están documentados en el spec y **no son tareas de este plan**:

1. **Presupuesto 2026 en `TGV2.dbo.Budget`.** Última carga: mayo 2025. Se sube con el importador existente en `/commercial/import_file_budget`. Sin esto, tres de las nueve filas de resumen salen vacías y la vista muestra el aviso amarillo.
2. **Huecos en `merma_diaria`:** faltan febrero, marzo y abril de 2026. Se llenan con el botón *Actualizar datos* de `/merma/analisis`, que topa en 40 días por corrida (≈3 pasadas). Sin febrero, el `% M.A.` de marzo no se puede calcular.
