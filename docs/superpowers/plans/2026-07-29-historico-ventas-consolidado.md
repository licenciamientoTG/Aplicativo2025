# Pestaña HISTÓRICO del Reporte de Ventas Consolidado — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Una sexta pestaña en `/merma/ventas` que muestre el acumulado mensual por estación sobre un rango de años seleccionable, con su propio selector de producto y carga por AJAX.

**Architecture:** Dos consultas nuevas sobre `TG.dbo.merma_diaria` en el modelo que ya es dueño de esa tabla; un método estático nuevo en la clase pura `VentasConsolidado` que arma las filas de mes y los subtotales anuales; un endpoint que devuelve solo el HTML de la tabla y una pestaña que lo pide por AJAX para que sus controles no colisionen con el selector de mes de las cinco pestañas diarias.

**Tech Stack:** PHP 8 (MVC propio), PDO + `sqlsrv` contra SQL Server, Twig 3, jQuery (ya cargado por el layout), PhpSpreadsheet 2.2.

**Spec:** `docs/superpowers/specs/2026-07-28-historico-ventas-consolidado-design.md`

## Global Constraints

- **Este repositorio NO tiene framework de tests** (ni PHPUnit, ni `tests/`, ni `composer test`). El ciclo de verificación de cada tarea es un script PHP de línea de comandos con aserciones reales, guardado en el scratchpad y **NO commiteado**, más verificación en navegador donde aplique. No montes infraestructura de tests nueva.
- **Scratchpad** (nada de esto va dentro del repo): `C:/Users/manue/AppData/Local/Temp/claude/C--Users-manue-OneDrive-Desktop-proyectos-aplicativoTG-Aplicativo2025/82d56f39-700d-449c-822e-05d0ddbe7c62/scratchpad`
- **Bootstrap de los scripts CLI** (la app exige sesión; este patrón ya funcionó en el reporte diario): definir `$_SERVER['DOCUMENT_ROOT']` y `$_SERVER['REQUEST_URI']`, `chdir` a la raíz del proyecto, cargar `vendor/autoload.php` y `_assets/classes/header.class.php` (que ya carga `MySqlPdoHandler` internamente — no lo vuelvas a requerir), y mockear `$_SESSION` si el método valida permisos. Referencia viva: `cron/merma_sync_diario.php`.
- **Servidor local:** `php -S localhost:8001 router.php` desde la raíz.
- **Fuente de datos: `TG.dbo.merma_diaria` únicamente.** No consultes `SG12.dbo.Ventas` aunque tenga más historia — la decisión del usuario es que todo el reporte salga de una sola tabla para que ninguna pestaña pueda contradecir a otra.
- **Códigos de producto:** siempre vía `MermaDiariaModel::FAMILIAS` y su método privado `familiaCase()`, o vía `VentasConsolidado::PESTANAS`. Nunca los escribas literales en código nuevo.
- **Celdas de esta pestaña: `0.0`, nunca `null`.** Es la excepción deliberada a la convención del resto de la vista (que usa `—`). Decisión explícita del usuario, documentada en el spec.
- **Autoload:** `_assets/classes/<Nombre>.class.php` y `_assets/models/<Nombre>.php` se cargan solos. **No toques `index.php`**: cualquier método público de `Merma` queda expuesto en `/merma/<metodo>`.
- **Permiso:** `authorized(self::PERM_VER)` (33) en todo método nuevo del controlador.
- **Idioma:** comentarios, textos de interfaz y mensajes de commit en español.
- **Commits:** `feat(merma): ...` / `fix(merma): ...`. Nunca `--no-verify`.
- **`logs/php_errors.log` no debe crecer** por código nuevo.
- **El árbol de trabajo puede tener cambios ajenos sin commitear.** Usa `git add` con rutas exactas; nunca `git add -A` ni `git add .`.

## File Structure

| Archivo | Responsabilidad |
|---|---|
| `_assets/models/MermaDiariaModel.php` *(modificar)* | Dos consultas nuevas: matriz año/mes/estación y el año mínimo disponible. Cero cálculo. |
| `_assets/classes/VentasConsolidado.class.php` *(modificar)* | `construirHistorico()`: proyecta la matriz al producto elegido y arma filas de mes + subtotales anuales. Única sede de las fórmulas. |
| `_assets/controllers/merma.php` *(modificar)* | `ventas_historico()` (endpoint AJAX) y los datos que `ventas()` pasa a la vista para pintar los controles. |
| `views/merma/ventas_historico.html` *(crear)* | Solo la tabla y su leyenda, sin layout. Es lo que devuelve el endpoint. |
| `views/merma/ventas.html` *(modificar)* | La sexta pestaña con sus controles y el contenedor vacío. |
| `_assets/js/merma_ventas.js` *(crear)* | Carga diferida y recarga al cambiar los controles. |
| `_assets/css/merma.css` *(modificar)* | Estilo de las filas de subtotal anual. |

---

### Task 1: Consultas del histórico en el modelo

**Files:**
- Modify: `_assets/models/MermaDiariaModel.php` (agregar dos métodos públicos al final de la clase, después de `get_ventas_totales_mes()`)
- Verify: `<scratchpad>/verificar_hist_task1.php` (no se commitea)

**Interfaces:**
- Consumes: el método privado `familiaCase(string $familia, string $columna): string` (ya existe, ~línea 34; devuelve `SUM(CASE WHEN codprd IN (...) THEN <columna> END)`).
- Produces:
  - `get_historico_mensual(int $desde, int $hasta): array` → `[ (int)anio => [ (int)mes => [ (int)codgas => ['maxima'=>?float,'super'=>?float,'diesel'=>?float] ] ] ]`
  - `get_anio_min_historico(): ?int` → primer año con datos, o `null` si la tabla está vacía.

- [ ] **Step 1: Escribir el script de verificación (falla porque los métodos no existen)**

Crear `<scratchpad>/verificar_hist_task1.php`:

```php
<?php
chdir('C:/Users/manue/OneDrive/Desktop/proyectos/aplicativoTG/Aplicativo2025');
$_SERVER['DOCUMENT_ROOT'] = getcwd();
$_SERVER['REQUEST_URI']   = '/';
require 'vendor/autoload.php';
require '_assets/classes/header.class.php';   // ya carga MySqlPdoHandler
require '_assets/models/Model.php';
require '_assets/models/MermaDiariaModel.php';

$fallos = 0;
function check(string $n, bool $ok, string $d = ''): void {
    global $fallos;
    if (!$ok) { $fallos++; echo "FALLA: $n" . ($d ? " — $d" : '') . "\n"; }
    else      { echo "ok:    $n\n"; }
}

$m = new MermaDiariaModel();

// --- get_anio_min_historico ---
// merma_diaria arranca en enero 2026 (verificado contra la BD el 2026-07-28).
check('anio_min = 2026', $m->get_anio_min_historico() === 2026,
      var_export($m->get_anio_min_historico(), true));

// --- get_historico_mensual ---
$h = $m->get_historico_mensual(2024, 2026);
check('solo trae el año 2026', array_keys($h) === [2026], implode(',', array_keys($h)));
check('llave de año es entero', array_key_first($h) === 2026);

$meses = array_keys($h[2026]);
sort($meses);
// Cobertura real: enero, mayo, junio y julio de 2026. Feb/mar/abr nunca se sincronizaron.
check('meses con datos = 1,5,6,7', $meses === [1, 5, 6, 7], implode(',', $meses));

$ene = $h[2026][1];
check('indexado por codgas entero', array_key_first($ene) === (int) array_key_first($ene));
check('enero 2026 trae 35 estaciones', count($ene) === 35, 'trae ' . count($ene));
$fila = reset($ene);
check('cada fila trae las 3 familias',
      array_keys($fila) === ['maxima', 'super', 'diesel'], implode(',', array_keys($fila)));

// La matriz debe cuadrar contra el total crudo de la tabla.
$pdo = new PDO("sqlsrv:Server=192.168.0.6;Database=TG;TrustServerCertificate=yes;LoginTimeout=10",
               'cguser', 'sahei1712');
$sumaMatriz = 0.0;
foreach ($h as $porMes) {
    foreach ($porMes as $porEst) {
        foreach ($porEst as $f) {
            $sumaMatriz += (float) $f['maxima'] + (float) $f['super'] + (float) $f['diesel'];
        }
    }
}
$crudo = (float) $pdo->query(
    "SELECT SUM(ventas_reales) FROM TG.dbo.merma_diaria
     WHERE fecha >= '2024-01-01' AND fecha < '2027-01-01'"
)->fetchColumn();
check('la matriz suma lo mismo que la tabla cruda', abs($sumaMatriz - $crudo) < 1.0,
      "matriz=$sumaMatriz crudo=$crudo");

// Un solo año: junio 2026 debe cuadrar contra el mes suelto.
$h26 = $m->get_historico_mensual(2026, 2026);
$junio = 0.0;
foreach ($h26[2026][6] as $f) { $junio += (float) $f['maxima'] + (float) $f['super'] + (float) $f['diesel']; }
$crudoJun = (float) $pdo->query(
    "SELECT SUM(ventas_reales) FROM TG.dbo.merma_diaria
     WHERE fecha >= '2026-06-01' AND fecha < '2026-07-01'"
)->fetchColumn();
check('junio 2026 cuadra', abs($junio - $crudoJun) < 1.0, "$junio vs $crudoJun");

// El rango cierra en el 31 de diciembre del año "hasta": pedir 2024..2025 no trae 2026.
check('rango 2024-2025 sale vacío', $m->get_historico_mensual(2024, 2025) === []);

echo $fallos === 0 ? "\nTODO OK\n" : "\n$fallos FALLA(S)\n";
exit($fallos === 0 ? 0 : 1);
```

- [ ] **Step 2: Correr el script para verificar que falla**

Run: `php -d memory_limit=1G <scratchpad>/verificar_hist_task1.php`
Expected: FALLA con `Call to undefined method MermaDiariaModel::get_anio_min_historico()`

- [ ] **Step 3: Implementar los dos métodos**

Agregar al final de la clase en `_assets/models/MermaDiariaModel.php`:

```php
    /* ===================================================================== */
    /* Histórico mensual del reporte de ventas (/merma/ventas, pestaña       */
    /* HISTÓRICO). Misma tabla que el reporte diario: el usuario pidió       */
    /* explícitamente que todo salga de merma_diaria y no de SG12.Ventas,    */
    /* para que ninguna pestaña pueda contradecir a otra.                    */
    /* ===================================================================== */

    /**
     * Acumulado mensual por estación y familia, de enero del año $desde a
     * diciembre del año $hasta.
     *
     * @return array [anio => [mes => [codgas => ['maxima'=>?float,'super'=>?float,'diesel'=>?float]]]]
     */
    public function get_historico_mensual(int $desde, int $hasta): array
    {
        $ini = sprintf('%04d-01-01', $desde);
        $fin = sprintf('%04d-01-01', $hasta);
        // El filtro va contra "fecha" sin envolverla en una función, para no
        // anular IX_merma_diaria_estacion. DATEADD sobre el parámetro cierra
        // el rango en el 31 de diciembre del año $hasta.
        $query = 'SELECT YEAR(fecha) AS anio, MONTH(fecha) AS mes, codgas,
                    ' . $this->familiaCase('maxima', 'ventas_reales') . ' AS maxima,
                    ' . $this->familiaCase('super', 'ventas_reales') . '  AS [super],
                    ' . $this->familiaCase('diesel', 'ventas_reales') . ' AS diesel
                  FROM [TG].[dbo].[merma_diaria]
                  WHERE fecha >= ? AND fecha < DATEADD(YEAR, 1, CAST(? AS DATE))
                  GROUP BY YEAR(fecha), MONTH(fecha), codgas
                  ORDER BY anio, mes, codgas;';
        $rows = $this->sql->select($query, [$ini, $fin]) ?: [];
        $out  = [];
        foreach ($rows as $r) {
            $out[(int) $r['anio']][(int) $r['mes']][(int) $r['codgas']] = [
                'maxima' => $r['maxima'] === null ? null : (float) $r['maxima'],
                'super'  => $r['super']  === null ? null : (float) $r['super'],
                'diesel' => $r['diesel'] === null ? null : (float) $r['diesel'],
            ];
        }
        return $out;
    }

    /**
     * Primer año con datos en el snapshot. Alimenta el piso de los selectores
     * de año de la pestaña histórica, de modo que el rango disponible crezca
     * solo conforme se sincronice más historia hacia atrás.
     */
    public function get_anio_min_historico(): ?int
    {
        $rows = $this->sql->select(
            'SELECT MIN(YEAR(fecha)) AS anio FROM [TG].[dbo].[merma_diaria];'
        ) ?: [];
        $v = $rows[0]['anio'] ?? null;
        return $v === null ? null : (int) $v;
    }
```

- [ ] **Step 4: Correr el script para verificar que pasa**

Run: `php -d memory_limit=1G <scratchpad>/verificar_hist_task1.php`
Expected: `TODO OK`, código de salida 0

- [ ] **Step 5: Commit**

```bash
git add _assets/models/MermaDiariaModel.php
git commit -m "feat(merma): consultas del histórico mensual del reporte de ventas

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 2: `VentasConsolidado::construirHistorico()`

**Files:**
- Modify: `_assets/classes/VentasConsolidado.class.php` (agregar una constante pública y un método estático público, más un helper privado)
- Verify: `<scratchpad>/verificar_hist_task2.php` (no se commitea)

**Interfaces:**
- Consumes: `VentasConsolidado::PESTANAS` (ya existe: llaves `total`, `reg_prem`, `regular`, `premium`, `diesel`, cada una con `label`, `familias`, `codprd`) y el helper privado `sumarFamilias(array $fila, array $familias): ?float` (ya existe).
- Produces:
  - `VentasConsolidado::MESES` → `['ENERO', …, 'DICIEMBRE']`, índice 0 = enero.
  - `VentasConsolidado::construirHistorico(string $clave, array $ctx): array`.

- [ ] **Step 1: Escribir el script de verificación (falla porque el método no existe)**

Crear `<scratchpad>/verificar_hist_task2.php`:

```php
<?php
require 'C:/Users/manue/OneDrive/Desktop/proyectos/aplicativoTG/Aplicativo2025/_assets/classes/VentasConsolidado.class.php';

$fallos = 0;
function check(string $n, bool $ok, string $d = ''): void {
    global $fallos;
    if (!$ok) { $fallos++; echo "FALLA: $n" . ($d ? " — $d" : '') . "\n"; }
    else      { echo "ok:    $n\n"; }
}
function casi(float $a, float $b, float $tol = 0.01): bool { return abs($a - $b) < $tol; }

// Escenario: 2 estaciones, rango 2025-2026.
// 2025 sin nada sincronizado. 2026 con enero y marzo.
$ctx = [
    'estaciones' => [
        ['Codigo' => 100, 'Nombre' => '02 Uno'],
        ['Codigo' => 200, 'Nombre' => '03 Dos'],
    ],
    'historico' => [
        2026 => [
            1 => [
                100 => ['maxima' => 100.0, 'super' => 10.0, 'diesel' => 5.0],
                200 => ['maxima' => 200.0, 'super' => 20.0, 'diesel' => null],
            ],
            3 => [
                100 => ['maxima' => 300.0, 'super' => 30.0, 'diesel' => 15.0],
            ],
        ],
    ],
    'desde' => 2025,
    'hasta' => 2026,
];

$r = VentasConsolidado::construirHistorico('total', $ctx);

// ---------- estructura ----------
// 2 años × (12 meses + 1 fila anual) = 26 filas
check('26 filas', count($r['filas']) === 26, 'hubo ' . count($r['filas']));
check('label correcto', $r['label'] === 'LITROS DE COMBUSTIBLE', $r['label']);
check('fila 0 = ENERO 2025', $r['filas'][0]['etiqueta'] === 'ENERO 2025', $r['filas'][0]['etiqueta']);
check('fila 0 es tipo mes', $r['filas'][0]['tipo'] === 'mes');
check('fila 12 = TOTAL 2025', $r['filas'][12]['etiqueta'] === 'TOTAL 2025', $r['filas'][12]['etiqueta']);
check('fila 12 es tipo anual', $r['filas'][12]['tipo'] === 'anual');
check('fila 13 = ENERO 2026', $r['filas'][13]['etiqueta'] === 'ENERO 2026', $r['filas'][13]['etiqueta']);
check('fila 25 = TOTAL 2026', $r['filas'][25]['etiqueta'] === 'TOTAL 2026', $r['filas'][25]['etiqueta']);
check('las filas de mes traen anio y mes', $r['filas'][13]['anio'] === 2026 && $r['filas'][13]['mes'] === 1);

// ---------- ceros, no null ----------
foreach ($r['filas'][0]['celdas'] as $cod => $v) {
    check("ENERO 2025 est $cod es 0.0 (no null)", $v === 0.0, var_export($v, true));
}
check('ENERO 2025 total = 0.0', $r['filas'][0]['total'] === 0.0);
check('TOTAL 2025 = 0.0', $r['filas'][12]['total'] === 0.0);
// La estación 200 no vende diesel: en marzo 2026 ni siquiera aparece → 0.0, no null
check('MARZO 2026 est 200 = 0.0', $r['filas'][15]['celdas'][200] === 0.0,
      var_export($r['filas'][15]['celdas'][200], true));

// ---------- suma por producto ----------
// total = maxima+super+diesel
check('ENERO 2026 est 100 = 115', casi($r['filas'][13]['celdas'][100], 115.0));
check('ENERO 2026 est 200 = 220 (sin diesel)', casi($r['filas'][13]['celdas'][200], 220.0));
check('ENERO 2026 total = 335', casi($r['filas'][13]['total'], 335.0));
// TOTAL 2026 = enero (335) + marzo (345) = 680
check('TOTAL 2026 est 100 = 460', casi($r['filas'][25]['celdas'][100], 460.0));
check('TOTAL 2026 est 200 = 220', casi($r['filas'][25]['celdas'][200], 220.0));
check('TOTAL 2026 general = 680', casi($r['filas'][25]['total'], 680.0));

// ---------- otras pestañas ----------
$reg = VentasConsolidado::construirHistorico('regular', $ctx);
check('regular ENERO 2026 est 100 = 100', casi($reg['filas'][13]['celdas'][100], 100.0));
$die = VentasConsolidado::construirHistorico('diesel', $ctx);
check('diesel ENERO 2026 est 200 = 0.0', $die['filas'][13]['celdas'][200] === 0.0);
check('diesel TOTAL 2026 = 20', casi($die['filas'][25]['total'], 20.0));
$rp = VentasConsolidado::construirHistorico('reg_prem', $ctx);
check('reg_prem ENERO 2026 est 100 = 110', casi($rp['filas'][13]['celdas'][100], 110.0));

// ---------- leyenda de cobertura ----------
// "Con datos" = el mes fue sincronizado, aunque el producto de la pestaña dé 0.
check('meses_del_rango = 24', $r['meses_del_rango'] === 24, (string) $r['meses_del_rango']);
check('meses_con_datos = ENERO 2026, MARZO 2026',
      $r['meses_con_datos'] === ['ENERO 2026', 'MARZO 2026'],
      implode(' | ', $r['meses_con_datos']));
check('la pestaña DIESEL reporta los mismos meses sincronizados',
      $die['meses_con_datos'] === ['ENERO 2026', 'MARZO 2026'],
      implode(' | ', $die['meses_con_datos']));

// ---------- rango de un solo año ----------
$uno = VentasConsolidado::construirHistorico('total', ['estaciones' => $ctx['estaciones'],
    'historico' => $ctx['historico'], 'desde' => 2026, 'hasta' => 2026]);
check('un año = 13 filas', count($uno['filas']) === 13, (string) count($uno['filas']));
check('un año: meses_del_rango = 12', $uno['meses_del_rango'] === 12);

// ---------- histórico vacío ----------
$vac = VentasConsolidado::construirHistorico('total', ['estaciones' => $ctx['estaciones'],
    'historico' => [], 'desde' => 2026, 'hasta' => 2026]);
check('sin datos: meses_con_datos vacío', $vac['meses_con_datos'] === []);
check('sin datos: todas las celdas en 0.0', $vac['filas'][0]['celdas'][100] === 0.0);
check('sin datos: total anual 0.0', $vac['filas'][12]['total'] === 0.0);

// ---------- clave inválida ----------
try {
    VentasConsolidado::construirHistorico('inexistente', $ctx);
    check('clave inválida lanza', false);
} catch (InvalidArgumentException $e) {
    check('clave inválida lanza InvalidArgumentException', true);
}

// ---------- la constante MESES ----------
check('MESES[0] = ENERO', VentasConsolidado::MESES[0] === 'ENERO');
check('MESES tiene 12', count(VentasConsolidado::MESES) === 12);

echo $fallos === 0 ? "\nTODO OK\n" : "\n$fallos FALLA(S)\n";
exit($fallos === 0 ? 0 : 1);
```

- [ ] **Step 2: Correr el script para verificar que falla**

Run: `php <scratchpad>/verificar_hist_task2.php`
Expected: FALLA con `Call to undefined method VentasConsolidado::construirHistorico()`

- [ ] **Step 3: Implementar la constante y el método**

En `_assets/classes/VentasConsolidado.class.php`, agregar la constante junto a `DIAS_SEMANA` (pública, porque la vista y el exportador la usan para rotular):

```php
    /** Meses en español, índice 0 = enero. */
    public const MESES = ['ENERO', 'FEBRERO', 'MARZO', 'ABRIL', 'MAYO', 'JUNIO',
                          'JULIO', 'AGOSTO', 'SEPTIEMBRE', 'OCTUBRE', 'NOVIEMBRE', 'DICIEMBRE'];
```

Y agregar el método al final de la clase, antes del cierre:

```php
    /**
     * Histórico mensual: una fila por mes del rango de años, más una fila de
     * subtotal después de diciembre de cada año.
     *
     * A diferencia de construir(), aquí las celdas son float y nunca null: un
     * mes sin sincronizar vale 0.0. Es una decisión explícita del usuario, y
     * la razón de que exista 'meses_con_datos' — sin esa lista, los ceros de
     * un mes no sincronizado son indistinguibles de un mes sin ventas.
     *
     * @param string $clave  llave de self::PESTANAS
     * @param array  $ctx    [
     *   'estaciones' => [['Codigo'=>int,'Nombre'=>string], ...] en orden de columna
     *   'historico'  => [anio => [mes => [codgas => ['maxima'=>?float,'super'=>?float,'diesel'=>?float]]]]
     *   'desde'      => int  (año)
     *   'hasta'      => int  (año)
     * ]
     * @return array [
     *   'label' => string,
     *   'filas' => [
     *     ['tipo'=>'mes',   'anio'=>int, 'mes'=>int, 'etiqueta'=>string,
     *      'celdas'=>[codgas=>float], 'total'=>float],
     *     ['tipo'=>'anual', 'anio'=>int, 'etiqueta'=>string,
     *      'celdas'=>[codgas=>float], 'total'=>float],
     *   ],
     *   'meses_con_datos' => string[],  // etiquetas de los meses sincronizados
     *   'meses_del_rango' => int,
     * ]
     * @throws InvalidArgumentException si $clave no es una pestaña conocida
     */
    public static function construirHistorico(string $clave, array $ctx): array
    {
        if (!isset(self::PESTANAS[$clave])) {
            throw new InvalidArgumentException("Pestaña desconocida: $clave");
        }
        $pestana  = self::PESTANAS[$clave];
        $familias = $pestana['familias'];
        $desde    = (int) $ctx['desde'];
        $hasta    = (int) $ctx['hasta'];
        $codgases = array_map(fn($e) => (int) $e['Codigo'], $ctx['estaciones']);

        $filas         = [];
        $mesesConDatos = [];
        $mesesDelRango = 0;

        for ($anio = $desde; $anio <= $hasta; $anio++) {
            $anual      = array_fill_keys($codgases, 0.0);
            $anualTotal = 0.0;

            for ($mes = 1; $mes <= 12; $mes++) {
                $mesesDelRango++;
                $etiqueta = self::MESES[$mes - 1] . ' ' . $anio;

                // "Con datos" = el mes fue sincronizado. Se mide por la
                // presencia del mes en el snapshot, no por el valor: en la
                // pestaña DIESEL un mes real puede sumar 0 y aun así estar
                // sincronizado.
                $sincronizado = isset($ctx['historico'][$anio][$mes]);
                if ($sincronizado) $mesesConDatos[] = $etiqueta;

                $delMes = $ctx['historico'][$anio][$mes] ?? [];
                $celdas = [];
                $total  = 0.0;
                foreach ($codgases as $cod) {
                    $v = isset($delMes[$cod])
                        ? (self::sumarFamilias($delMes[$cod], $familias) ?? 0.0)
                        : 0.0;
                    $celdas[$cod] = $v;
                    $total       += $v;
                    $anual[$cod] += $v;
                }
                $anualTotal += $total;

                $filas[] = ['tipo' => 'mes', 'anio' => $anio, 'mes' => $mes,
                            'etiqueta' => $etiqueta, 'celdas' => $celdas, 'total' => $total];
            }

            $filas[] = ['tipo' => 'anual', 'anio' => $anio, 'etiqueta' => 'TOTAL ' . $anio,
                        'celdas' => $anual, 'total' => $anualTotal];
        }

        return [
            'label'           => $pestana['label'],
            'filas'           => $filas,
            'meses_con_datos' => $mesesConDatos,
            'meses_del_rango' => $mesesDelRango,
        ];
    }
```

- [ ] **Step 4: Correr los dos scripts (el nuevo y el del reporte diario, para confirmar que no se rompió nada)**

Run: `php <scratchpad>/verificar_hist_task2.php && php <scratchpad>/verificar_task2.php`
Expected: ambos `TODO OK`, código de salida 0. El segundo es el del reporte diario, que ejercita `construir()` y debe seguir intacto.

- [ ] **Step 5: Commit**

```bash
git add _assets/classes/VentasConsolidado.class.php
git commit -m "feat(merma): construirHistorico para el acumulado mensual por año

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 3: Endpoint, vista parcial, pestaña y carga por AJAX

**Files:**
- Modify: `_assets/controllers/merma.php` (agregar `ventas_historico()` después de `ventas()`, y ampliar lo que `ventas()` pasa a la vista)
- Create: `views/merma/ventas_historico.html`
- Modify: `views/merma/ventas.html` (sexta pestaña + bloque `myjs`)
- Create: `_assets/js/merma_ventas.js`
- Modify: `_assets/css/merma.css` (agregar al final)
- Verify: script CLI + navegador

**Interfaces:**
- Consumes: `MermaDiariaModel::get_historico_mensual(int,int)` y `get_anio_min_historico()` (Tarea 1); `VentasConsolidado::construirHistorico(string,array)`, `VentasConsolidado::PESTANAS`, `VentasConsolidado::MESES` (Tarea 2); `MermaDiariaModel::get_estaciones_ordenadas()` (ya existe).
- Produces: `Merma::ventas_historico(): void` — responde el HTML de la tabla. La Tarea 4 reusa el mismo trío de parámetros validados.

- [ ] **Step 1: Agregar el endpoint al controlador**

En `_assets/controllers/merma.php`, insertar justo después del cierre de `ventas()` y antes de `armarReporte()`:

```php
    /**
     * Pestaña HISTÓRICO de /merma/ventas: acumulado mensual por estación
     * sobre un rango de años. Devuelve SOLO el HTML de la tabla — la pestaña
     * lo pide por AJAX para que sus controles no colisionen con el selector
     * de mes que gobierna las cinco pestañas diarias.
     */
    public function ventas_historico(): void
    {
        if (!authorized(self::PERM_VER)) {
            (new Errors())->get404();
            return;
        }
        [$desde, $hasta, $prod] = $this->periodoHistorico();

        $estaciones = array_map(
            fn($e) => ['Codigo' => (int) $e['Codigo'], 'Nombre' => $e['Nombre']],
            $this->mermaModel->get_estaciones_ordenadas()
        );
        $hist = VentasConsolidado::construirHistorico($prod, [
            'estaciones' => $estaciones,
            'historico'  => $this->mermaModel->get_historico_mensual($desde, $hasta),
            'desde'      => $desde,
            'hasta'      => $hasta,
        ]);

        echo $this->twig->render($this->route . 'ventas_historico.html',
            compact('estaciones', 'hist', 'desde', 'hasta', 'prod'));
    }

    /**
     * Valida desde/hasta/prod de la pestaña histórica. Mismo criterio que
     * ventas(): piso duro en 2020 para que un parámetro manipulado no pida un
     * rango absurdo, aunque el piso del SELECTOR sea el primer año que exista
     * en la tabla (get_anio_min_historico), que es más alto.
     *
     * @return array{0:int,1:int,2:string}
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

        return [$desde, $hasta, $prod];
    }
```

- [ ] **Step 2: Pasar a la vista principal los datos de los controles**

En `_assets/controllers/merma.php`, dentro de `ventas()`, sustituir el bloque `echo $this->twig->render(...)` por:

```php
        // Piso de los selectores de la pestaña histórica: el primer año que
        // exista en merma_diaria, de modo que el rango disponible crezca solo
        // conforme se sincronice más historia hacia atrás.
        $anioMinHist = $this->mermaModel->get_anio_min_historico() ?? $anioAyer;
        if ($anioMinHist > $anioAyer) $anioMinHist = $anioAyer;

        echo $this->twig->render($this->route . 'ventas.html', $reporte + [
            'anios'  => range($anioAyer, $anioAyer - 2),
            'meses'  => ['ENERO', 'FEBRERO', 'MARZO', 'ABRIL', 'MAYO', 'JUNIO',
                         'JULIO', 'AGOSTO', 'SEPTIEMBRE', 'OCTUBRE', 'NOVIEMBRE', 'DICIEMBRE'],
            // Controles de la pestaña histórica
            'histAnios' => range($anioAyer, $anioMinHist),
            'histDesde' => max($anioMinHist, $anioAyer - 2),
            'histHasta' => $anioAyer,
            'histProds' => VentasConsolidado::PESTANAS,
        ]);
```

- [ ] **Step 3: Crear la vista parcial**

Crear `views/merma/ventas_historico.html`:

```twig
<div class="merma-tabla-wrap mb-2">
    <div class="merma-scroll">
        <table class="merma-tabla vc-tabla vch-tabla">
            <thead>
                <tr>
                    <th class="col-fecha">MES</th>
                    {% for e in estaciones %}
                    <th>{{ e.Nombre }}</th>
                    {% endfor %}
                    <th class="th-grupo">TOTAL</th>
                </tr>
            </thead>
            <tbody>
                {% for f in hist.filas %}
                <tr class="{{ f.tipo == 'anual' ? 'vch-anual' : '' }}">
                    <td class="col-fecha">{{ f.etiqueta }}</td>
                    {% for e in estaciones %}
                    <td>{{ f.celdas[e.Codigo]|number_format(0, '.', ',') }}</td>
                    {% endfor %}
                    <td class="col-total">{{ f.total|number_format(0, '.', ',') }}</td>
                </tr>
                {% endfor %}
            </tbody>
        </table>
    </div>
</div>

<p class="text-muted small">
    {% if hist.meses_con_datos|length > 0 %}
    Con datos: <strong>{{ hist.meses_con_datos|join(', ')|lower }}</strong>
    ({{ hist.meses_con_datos|length }} de {{ hist.meses_del_rango }} meses del rango).
    Los meses sin sincronizar aparecen en cero.
    {% else %}
    <strong>Ningún mes del rango está sincronizado</strong>
    ({{ hist.meses_del_rango }} meses, todos en cero).
    {% endif %}
    <a href="/merma/analisis">Sincronizar los faltantes</a>
</p>
```

Nota: la vista **no** lleva la segunda columna fija (`col-turno`) que sí tienen las pestañas diarias — aquí la etiqueta del mes ocupa una sola columna. El CSS del Step 5 ajusta el ancho.

- [ ] **Step 4: Agregar la pestaña a la vista principal**

En `views/merma/ventas.html`, después del `{% endfor %}` que cierra la lista de pestañas (`<ul class="nav nav-tabs merma-tabs">`), agregar el sexto `<li>` **antes** del `</ul>`:

```twig
    <li class="nav-item">
        <a class="nav-link" data-bs-toggle="tab" href="#tab-historico" role="tab"
           id="tab-historico-link">HISTÓRICO</a>
    </li>
```

Y después del `{% endfor %}` que cierra `<div class="tab-content">`, agregar el sexto panel **antes** del `</div>` que cierra `tab-content`:

```twig
    <div class="tab-pane fade" id="tab-historico" role="tabpanel">
        <div class="card mb-2">
            <div class="card-body py-2">
                <div class="row align-items-end g-2">
                    <div class="col-auto">
                        <label for="hist_desde">Año desde:</label>
                        <select class="form-control" id="hist_desde">
                            {% for a in histAnios %}
                            <option value="{{ a }}" {{ a == histDesde ? 'selected' : '' }}>{{ a }}</option>
                            {% endfor %}
                        </select>
                    </div>
                    <div class="col-auto">
                        <label for="hist_hasta">Año hasta:</label>
                        <select class="form-control" id="hist_hasta">
                            {% for a in histAnios %}
                            <option value="{{ a }}" {{ a == histHasta ? 'selected' : '' }}>{{ a }}</option>
                            {% endfor %}
                        </select>
                    </div>
                    <div class="col-auto">
                        <label for="hist_prod">Producto:</label>
                        <select class="form-control" id="hist_prod">
                            {% for clave, p in histProds %}
                            <option value="{{ clave }}">{{ p.label }}</option>
                            {% endfor %}
                        </select>
                    </div>
                </div>
            </div>
        </div>
        <div id="hist_contenido">
            <p class="text-muted small">Cargando histórico…</p>
        </div>
    </div>
```

Y al final del archivo, después de `{% endblock %}` del bloque `content`, agregar:

```twig
{% block myjs %}
<script src="/_assets/js/merma_ventas.js"></script>
{% endblock %}
```

- [ ] **Step 5: Crear el JavaScript**

Crear `_assets/js/merma_ventas.js`:

```javascript
/**
 * Pestaña HISTÓRICO de /merma/ventas.
 *
 * Se carga por AJAX en vez de venir en el render inicial para que sus
 * selectores de año y producto no colisionen con el selector de mes que
 * gobierna las cinco pestañas diarias: si vivieran en el mismo formulario,
 * cambiarlos recargaría la página y arrastraría al otro control.
 */
$(function () {
    var cargado = false;

    function cargarHistorico() {
        var params = {
            desde: $('#hist_desde').val(),
            hasta: $('#hist_hasta').val(),
            prod:  $('#hist_prod').val()
        };
        $('#hist_contenido').html('<p class="text-muted small">Cargando histórico…</p>');
        $.get('/merma/ventas_historico', params)
            .done(function (html) {
                $('#hist_contenido').html(html);
                cargado = true;
            })
            .fail(function () {
                $('#hist_contenido').html(
                    '<div class="alert alert-danger py-2">No se pudo cargar el histórico. ' +
                    'Vuelve a intentarlo o revisa la conexión.</div>'
                );
            });
    }

    // Primera vez que se abre la pestaña
    $('#tab-historico-link').on('shown.bs.tab', function () {
        if (!cargado) cargarHistorico();
    });

    // Cualquier cambio de control recarga solo la tabla
    $('#hist_desde, #hist_hasta, #hist_prod').on('change', cargarHistorico);
});
```

- [ ] **Step 6: Agregar el CSS**

Agregar al final de `_assets/css/merma.css`:

```css
/* ---- Pestaña HISTÓRICO (/merma/ventas) -------------------------------- */
/* Una sola columna fija (la etiqueta del mes), a diferencia de las pestañas
   diarias que congelan día + día de la semana. */
.vch-tabla .col-fecha { width: 165px; min-width: 165px; max-width: 165px; }

/* Filas de subtotal anual: negritas y separadas del bloque de meses. */
.vch-tabla tbody tr.vch-anual td,
.vch-tabla tbody tr.vch-anual td.col-fecha {
    background: #f1f5f9; font-weight: 700; color: #0f172a;
    border-top: 2px solid #94a3b8; border-bottom: 2px solid #94a3b8;
}
.vch-tabla tbody tr.vch-anual:hover td { background: #e2e8f0; }
```

- [ ] **Step 7: Verificar por script CLI**

Crear `<scratchpad>/verificar_hist_task3.php`, que llama al endpoint real y comprueba el HTML renderizado:

```php
<?php
chdir('C:/Users/manue/OneDrive/Desktop/proyectos/aplicativoTG/Aplicativo2025');
$_SERVER['DOCUMENT_ROOT'] = getcwd();
$_SERVER['REQUEST_URI']   = '/merma/ventas_historico';
require 'vendor/autoload.php';
require '_assets/classes/header.class.php';

$fallos = 0;
function check(string $n, bool $ok, string $d = ''): void {
    global $fallos;
    if (!$ok) { $fallos++; echo "FALLA: $n" . ($d ? " — $d" : '') . "\n"; }
    else      { echo "ok:    $n\n"; }
}

// Sesión mockeada con el permiso 33 (Reportes de Abastos)
session_start();
$_SESSION['tg_user']  = ['Id' => 1];
$_SESSION['permisos'] = [33];

// twig_functions.php inicializa $twig por sí mismo (ver index.php:20).
require '_assets/classes/twig_functions.php';
$twig->trackController = 'merma';
$twig->trackMethod     = 'ventas_historico';

require '_assets/controllers/errors.php';
require '_assets/controllers/merma.php';

$_GET = ['desde' => '2026', 'hasta' => '2026', 'prod' => 'total'];
ob_start();
(new Merma($twig))->ventas_historico();
$html = ob_get_clean();

check('devuelve HTML', strlen($html) > 500, strlen($html) . ' bytes');
check('trae la tabla', str_contains($html, 'vch-tabla'));
check('encabezado MES', str_contains($html, '>MES<'));
check('12 filas de mes + 1 anual', substr_count($html, '<tr') === 14, // 1 thead + 12 meses + 1 anual
      substr_count($html, '<tr') . ' filas <tr>');
check('fila anual presente', str_contains($html, 'TOTAL 2026'));
check('clase de fila anual', str_contains($html, 'vch-anual'));
check('leyenda de cobertura', str_contains($html, 'meses del rango'));
check('enlace a sincronizar', str_contains($html, '/merma/analisis'));
// merma_diaria tiene enero, mayo, junio y julio de 2026 (verificado contra la BD)
check('leyenda menciona 4 de 12', str_contains($html, '4 de 12 meses'), 'no encontró "4 de 12 meses"');
check('febrero aparece en cero', str_contains($html, 'FEBRERO 2026'));
check('sin em-dash (esta pestaña usa ceros)', !str_contains($html, '—'));

// Producto inválido cae a 'total'
$_GET = ['desde' => '2026', 'hasta' => '2026', 'prod' => 'inexistente'];
ob_start(); (new Merma($twig))->ventas_historico(); $h2 = ob_get_clean();
check('prod inválido no revienta', strlen($h2) > 500);

// desde > hasta se intercambia
$_GET = ['desde' => '2026', 'hasta' => '2024', 'prod' => 'total'];
ob_start(); (new Merma($twig))->ventas_historico(); $h3 = ob_get_clean();
check('desde>hasta se intercambia (trae 2024..2026)', str_contains($h3, 'TOTAL 2024') && str_contains($h3, 'TOTAL 2026'));

echo $fallos === 0 ? "\nTODO OK\n" : "\n$fallos FALLA(S)\n";
exit($fallos === 0 ? 0 : 1);
```

Run: `php -d memory_limit=1G <scratchpad>/verificar_hist_task3.php`
Expected: `TODO OK`, código de salida 0

Si el bootstrap falla, léelo contra `index.php` y ajústalo a lo que ese archivo hace realmente — pero **no cambies ninguna aserción** para que pase. Si una aserción falla, arregla el código, o repórtalo como concern si crees que la aserción está mal.

- [ ] **Step 8: Verificar en el navegador**

Levantar `php -S localhost:8001 router.php` y abrir `http://localhost:8001/merma/ventas`:

1. Se ve una sexta pestaña **HISTÓRICO** después de DIESEL.
2. Al cargar la página, la pestaña histórica NO dispara ninguna consulta (revisa la pestaña Red del navegador: no debe haber petición a `/merma/ventas_historico` hasta que le des clic).
3. Al primer clic aparece "Cargando histórico…" y luego la tabla.
4. Los selectores muestran solo 2026 en ambos años (es el único año en `merma_diaria`) y las cinco opciones de producto.
5. Hay 12 filas de mes y una fila `TOTAL 2026` en negritas con borde.
6. Enero, mayo, junio y julio traen números; los demás meses salen en `0`.
7. La leyenda dice "4 de 12 meses del rango" y el enlace lleva a `/merma/analisis`.
8. Cambiar el producto recarga solo la tabla — la página no se recarga y el selector de mes de arriba NO cambia.
9. Cambiar el selector de mes de arriba y darle Buscar recarga la página; al volver a la pestaña histórica, esta se vuelve a cargar por AJAX sin errores.
10. Al hacer scroll horizontal la columna MES se queda fija; al hacer scroll vertical el encabezado se queda pegado arriba (no a 32 px del borde).
11. Las otras cinco pestañas siguen viéndose igual que antes.
12. `logs/php_errors.log` sin entradas nuevas.

- [ ] **Step 9: Commit**

```bash
git add _assets/controllers/merma.php views/merma/ventas_historico.html \
        views/merma/ventas.html _assets/js/merma_ventas.js _assets/css/merma.css
git commit -m "feat(merma): pestaña HISTÓRICO en el reporte de ventas consolidado

Acumulado mensual por estación sobre un rango de años, cargada por AJAX
para que sus controles no colisionen con el selector de mes de las cinco
pestañas diarias.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 4: Hoja HISTÓRICO en la exportación a Excel

**Files:**
- Modify: `_assets/controllers/merma.php` (dentro de `ventas_excel()`, después del bucle de las cinco pestañas)
- Modify: `views/merma/ventas.html` (el enlace de exportar arrastra los tres parámetros del histórico)
- Modify: `_assets/js/merma_ventas.js` (mantener el enlace sincronizado con los controles)
- Verify: `<scratchpad>/verificar_hist_task4.php` (no se commitea)

**Interfaces:**
- Consumes: `Merma::periodoHistorico(): array{0:int,1:int,2:string}` (Tarea 3), `VentasConsolidado::construirHistorico()` (Tarea 2), `MermaDiariaModel::get_historico_mensual()` (Tarea 1).
- Produces: una sexta hoja llamada `HISTÓRICO` en el `.xlsx` que descarga `/merma/ventas_excel`.

- [ ] **Step 1: Agregar la hoja al exportador**

En `_assets/controllers/merma.php`, dentro de `ventas_excel()`, justo **después** del `foreach ($reporte['pestanas'] as $p) { … }` y **antes** de `$spreadsheet->setActiveSheetIndex(0);`:

```php
        // Sexta hoja: el histórico mensual, con el mismo rango y producto que
        // la pestaña tenga seleccionados (o los valores por defecto si el
        // usuario nunca la abrió).
        [$hDesde, $hHasta, $hProd] = $this->periodoHistorico();
        $hist = VentasConsolidado::construirHistorico($hProd, [
            'estaciones' => $estaciones,
            'historico'  => $this->mermaModel->get_historico_mensual($hDesde, $hHasta),
            'desde'      => $hDesde,
            'hasta'      => $hHasta,
        ]);

        $hoja = $spreadsheet->createSheet();
        $hoja->setTitle('HISTÓRICO');
        $hoja->setCellValue('A1', 'MES');
        $col = 2;
        foreach ($estaciones as $e) {
            $hoja->setCellValue(Coordinate::stringFromColumnIndex($col) . '1', $e['Nombre']);
            $col++;
        }
        $colTotalHist = Coordinate::stringFromColumnIndex($col);
        $hoja->setCellValue($colTotalHist . '1', 'TOTAL');
        $hoja->setCellValue(Coordinate::stringFromColumnIndex($col + 2) . '1',
                            'Producto: ' . $hist['label']);
        $hoja->getStyle('A1:' . $colTotalHist . '1')->getFont()->setBold(true);

        $fila = 2;
        foreach ($hist['filas'] as $f) {
            $hoja->setCellValue('A' . $fila, $f['etiqueta']);
            $col = 2;
            foreach ($estaciones as $e) {
                $hoja->setCellValue(Coordinate::stringFromColumnIndex($col) . $fila,
                                    round($f['celdas'][(int) $e['Codigo']], 2));
                $col++;
            }
            $hoja->setCellValue($colTotalHist . $fila, round($f['total'], 2));
            if ($f['tipo'] === 'anual') {
                $hoja->getStyle('A' . $fila . ':' . $colTotalHist . $fila)->getFont()->setBold(true);
            }
            $fila++;
        }
        $hoja->getColumnDimension('A')->setWidth(20);
        $hoja->freezePane('B2');
```

Nota: a diferencia de las cinco hojas de producto, aquí **sí** se escribe el `0` — las celdas del histórico son `float`, nunca `null`, por la decisión del usuario.

- [ ] **Step 2: Hacer que el enlace de exportar arrastre los parámetros**

En `views/merma/ventas.html`, sustituir el enlace de exportación por uno con `id`:

```twig
                <a href="/merma/ventas_excel?anio={{ anio }}&mes={{ mes }}&desde={{ histDesde }}&hasta={{ histHasta }}&prod=total"
                   id="btn_exportar" class="btn btn-merma-sync">
                    <i class="fas fa-file-excel"></i> Exportar a Excel
                </a>
```

Y en `_assets/js/merma_ventas.js`, dentro del `$(function () { … })`, agregar al final:

```javascript
    // El enlace de exportación arrastra el rango y producto del histórico,
    // para que la hoja HISTÓRICO del .xlsx refleje lo que está en pantalla.
    function sincronizarEnlaceExportar() {
        var $a = $('#btn_exportar');
        if (!$a.length) return;
        var url = new URL($a.attr('href'), window.location.origin);
        url.searchParams.set('desde', $('#hist_desde').val());
        url.searchParams.set('hasta', $('#hist_hasta').val());
        url.searchParams.set('prod',  $('#hist_prod').val());
        $a.attr('href', url.pathname + url.search);
    }
    $('#hist_desde, #hist_hasta, #hist_prod').on('change', sincronizarEnlaceExportar);
```

- [ ] **Step 3: Verificar el .xlsx generado**

Copiar el generador del reporte diario (`<scratchpad>/generar_task4_xlsx.php`) a `<scratchpad>/generar_hist_xlsx.php` y cambiar su línea de `$_GET` por:

```php
$_GET = ['anio' => '2026', 'mes' => '7', 'desde' => '2026', 'hasta' => '2026', 'prod' => 'total'];
```

Correrlo para producir el `.xlsx`, y luego crear `<scratchpad>/verificar_hist_task4.php`:

```php
<?php
require 'C:/Users/manue/OneDrive/Desktop/proyectos/aplicativoTG/Aplicativo2025/vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

$ruta = $argv[1] ?? 'C:/Users/manue/Downloads/ventas_consolidado_2026_07.xlsx';
$fallos = 0;
function check(string $n, bool $ok, string $d = ''): void {
    global $fallos;
    if (!$ok) { $fallos++; echo "FALLA: $n" . ($d ? " — $d" : '') . "\n"; }
    else      { echo "ok:    $n\n"; }
}

$ss = IOFactory::load($ruta);
$nombres = $ss->getSheetNames();
check('6 hojas', count($nombres) === 6, implode(' | ', $nombres));
check('nombres y orden correctos', $nombres === [
    'LITROS DE COMBUSTIBLE', 'REGULAR + PREMIUM', 'REGULAR', 'PREMIUM', 'DIESEL', 'HISTÓRICO'
], implode(' | ', $nombres));

$h = $ss->getSheetByName('HISTÓRICO');
check('A1 = MES', $h->getCell('A1')->getValue() === 'MES');
check('B1 tiene nombre de estación', is_string($h->getCell('B1')->getValue()) && $h->getCell('B1')->getValue() !== '');
check('A2 = ENERO 2026', $h->getCell('A2')->getValue() === 'ENERO 2026', (string) $h->getCell('A2')->getValue());
check('A13 = DICIEMBRE 2026', $h->getCell('A13')->getValue() === 'DICIEMBRE 2026', (string) $h->getCell('A13')->getValue());
check('A14 = TOTAL 2026', $h->getCell('A14')->getValue() === 'TOTAL 2026', (string) $h->getCell('A14')->getValue());
check('B2 es numérico', is_numeric($h->getCell('B2')->getValue()));
// Febrero 2026 no está sincronizado: cero, no vacío
check('A4 = FEBRERO 2026', $h->getCell('A4')->getValue() === 'FEBRERO 2026');
check('febrero escribe 0, no celda vacía', $h->getCell('B4')->getValue() === 0
      || $h->getCell('B4')->getValue() === 0.0, var_export($h->getCell('B4')->getValue(), true));
check('freeze pane en B2', $h->getFreezePane() === 'B2', (string) $h->getFreezePane());

// El TOTAL 2026 debe ser la suma de sus 12 meses, columna por columna.
$sumaB = 0.0;
for ($f = 2; $f <= 13; $f++) { $sumaB += (float) $h->getCell('B' . $f)->getValue(); }
check('TOTAL 2026 col B = suma de sus 12 meses',
      abs($sumaB - (float) $h->getCell('B14')->getValue()) < 0.05,
      $sumaB . ' vs ' . $h->getCell('B14')->getValue());

echo $fallos === 0 ? "\nTODO OK\n" : "\n$fallos FALLA(S)\n";
exit($fallos === 0 ? 0 : 1);
```

Run: `php -d memory_limit=2G <scratchpad>/verificar_hist_task4.php "<ruta al .xlsx generado>"`
Expected: `TODO OK`

Además, correr el verificador del reporte diario (`<scratchpad>/verificar_task4.php`) contra el mismo archivo para confirmar que las cinco hojas originales no cambiaron.

- [ ] **Step 4: Commit**

```bash
git add _assets/controllers/merma.php views/merma/ventas.html _assets/js/merma_ventas.js
git commit -m "feat(merma): hoja HISTÓRICO en la exportación del reporte de ventas

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Verificación final

Correr los cuatro scripts y confirmar que los cuatro terminan en `TODO OK`:

```bash
php -d memory_limit=1G <scratchpad>/verificar_hist_task1.php
php <scratchpad>/verificar_hist_task2.php
php -d memory_limit=1G <scratchpad>/verificar_hist_task3.php
php -d memory_limit=2G <scratchpad>/verificar_hist_task4.php "<ruta al .xlsx>"
```

Y los dos del reporte diario, para confirmar que nada se rompió:

```bash
php <scratchpad>/verificar_task2.php
php -d memory_limit=2G <scratchpad>/verificar_task4.php "<ruta al .xlsx>"
```

Confirmar que `git status` no deja archivos del scratchpad dentro del repositorio.

## Nota de expectativa

La pestaña nace casi vacía y eso es correcto: `merma_diaria` solo tiene enero, mayo, junio y julio de 2026. Hoy el rango por defecto es un solo año (recortado al primer año disponible), con 4 de 12 meses poblados. La leyenda de cobertura es lo que impide que esos ocho ceros se lean como una caída de ventas. La tabla se llena sola conforme se sincronicen más meses desde `/merma/analisis`, que cubre hasta 40 días por corrida.
