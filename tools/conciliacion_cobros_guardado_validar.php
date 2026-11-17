<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * Valida que guardar el cruce de cobros no cambia lo que se ve, solo lectura (no escribe en TG):
 *   php tools/conciliacion_cobros_guardado_validar.php
 * Corre el cruce de octubre 2026 igual que Income::conciliation_payments, lo convierte en grupos
 * (ConciliacionCobrosGuardado::gruposDesdeCruce, con ids de mentira, en memoria) y los vuelve a
 * filas (filasDesdeGrupos). Por cobro compara estatus, depósito y diferencia contra el cruce directo,
 * y los totales de DIAZ GAS (empresa 1). Sale con código 1 si algo no cuadra.
 */
$_SERVER['DOCUMENT_ROOT'] = __DIR__ . '/..';
$_SERVER['REQUEST_URI'] = '/';
chdir($_SERVER['DOCUMENT_ROOT']);
require $_SERVER['DOCUMENT_ROOT'] . '/_assets/classes/header.class.php';
require $_SERVER['DOCUMENT_ROOT'] . '/_assets/classes/php_functions.php';
spl_autoload_register(function ($class) {
    if (file_exists(CLASSES . $class . '.class.php')) require CLASSES . $class . '.class.php';
    if (file_exists(MODELS . $class . '.php')) require MODELS . $class . '.php';
});

$fallas = 0;
function comparar(string $etiqueta, float $obtenido, float $esperado, float $tol = 0.01): void {
    global $fallas;
    $ok = abs($obtenido - $esperado) <= $tol;
    if (!$ok) $fallas++;
    printf("%-4s %-52s obtenido %18s  esperado %18s\n", $ok ? 'ok' : 'FALLA', $etiqueta,
        number_format($obtenido, 2), number_format($esperado, 2));
}

$mes = '2026-10';
$empresa = 1;
$desde = $mes . '-01';
$hasta = date('Y-m-t', strtotime($desde));
$from = dateToInt($desde);
$until = dateToInt($hasta);

// Mismo cruce que Income::conciliation_payments (primera vez)
$model = new ConciliacionVentaModel;
$cobros = array_map(function ($r) {
    $r['mov'] = ConciliacionCobros::movDeTxtref((string)$r['txtref']);
    return $r;
}, $model->cobros($from, $until));
$dias = fn(string $fecha, int $n) => date('Y-m-d', strtotime($fecha . sprintf(' %+d days', $n)));
$depositos = $model->depositos(
    array_column($cobros, 'mov'),
    array_column($cobros, 'monto'),
    $dias($desde, -ConciliacionCobros::DIAS_ANTES), $dias($hasta, ConciliacionCobros::DIAS_DESPUES),
    $dias($desde, -ConciliacionCobros::DIAS_IMPORTE), $dias($hasta, ConciliacionCobros::DIAS_IMPORTE)
);
// Como el controlador: sin los movimientos que ya ocupa un grupo guardado de cualquier mes (solo SELECT)
$ocupados = (new ConciliacionCobrosGuardadoModel)->movimientosOcupadosEntre(array_map(fn($d) => (int)$d['id'], $depositos));
if ($ocupados) $depositos = array_values(array_filter($depositos, fn($d) => !isset($ocupados[(int)$d['id']])));
echo "-- informativo: depósitos de la bolsa ya ocupados por grupos guardados: " . count($ocupados) . "\n";
$cruce = ConciliacionCobros::conciliar($cobros, $depositos);

// Guardado en memoria: grupos con ids de mentira → filas
$grupos = ConciliacionCobrosGuardado::gruposDesdeCruce($cruce, $mes);
foreach ($grupos as $i => &$g) $g['id'] = $i + 1;
unset($g);
$cobrosPorClave = [];
foreach ($cobros as $c) $cobrosPorClave[ConciliacionCobrosGuardado::claveDoc((int)$c['codgas'], (int)$c['nro'])] = $c;
$movPorId = [];
foreach ($depositos as $d) $movPorId[(int)$d['id']] = $d;
$filas = ConciliacionCobrosGuardado::filasDesdeGrupos($grupos, $cobrosPorClave, $movPorId, []);

echo "== $mes: " . count($cruce) . " cobros, " . count($depositos) . " depósitos en la bolsa, " . count($grupos) . " grupos ==\n";
comparar('Filas desde grupos = cobros del cruce', count($filas), count($cruce), 0);

$porClave = [];
foreach ($filas as $f) $porClave[ConciliacionCobrosGuardado::claveDoc((int)$f['codgas'], (int)$f['nro'])][] = $f;
$difEstatus = $difDeposito = $difDiferencia = $faltan = 0;
$ejemplos = [];
foreach ($cruce as $r) {
    $k = ConciliacionCobrosGuardado::claveDoc((int)$r['codgas'], (int)$r['nro']);
    $f = $porClave[$k][0] ?? null;
    if ($f === null || count($porClave[$k]) !== 1) { $faltan++; $ejemplos[] = "$k: " . count($porClave[$k] ?? []) . " filas"; continue; }
    $depR = $r['deposito_id'] === null ? null : (int)$r['deposito_id'];
    $difR = $r['diferencia'] === null ? null : round((float)$r['diferencia'], 2);
    $difF = $f['diferencia'] === null ? null : round((float)$f['diferencia'], 2);
    if ($f['estatus'] !== $r['estatus']) { $difEstatus++; $ejemplos[] = "$k estatus {$r['estatus']} → {$f['estatus']}"; }
    if ($f['deposito_id'] !== $depR) { $difDeposito++; $ejemplos[] = "$k depósito " . var_export($depR, true) . ' → ' . var_export($f['deposito_id'], true); }
    if (($difR === null) !== ($difF === null) || ($difR !== null && abs($difR - $difF) >= 0.005)) {
        $difDiferencia++;
        $ejemplos[] = "$k [{$r['estatus']}] diferencia " . var_export($difR, true) . ' → ' . var_export($difF, true);
    }
}
comparar('Cobros sin fila única', $faltan, 0, 0);
comparar('Cobros con otro estatus', $difEstatus, 0, 0);
comparar('Cobros con otro depósito', $difDeposito, 0, 0);
comparar('Cobros con otra diferencia', $difDiferencia, 0, 0);
foreach (array_slice($ejemplos, 0, 20) as $e) echo "   $e\n";

$deEmpresa = fn(array $r) => (int)($r['empresa'] ?? -1) === $empresa;
$cruceEmp = array_filter($cruce, $deEmpresa);
$filasEmp = array_filter($filas, $deEmpresa);
comparar("Cobros empresa $empresa (cruce)", count($cruceEmp), 579, 0);
comparar("Monto empresa $empresa (cruce)", array_sum(array_column($cruceEmp, 'monto')), 51449604.56);
comparar("Cobros empresa $empresa (grupos)", count($filasEmp), 579, 0);
comparar("Monto empresa $empresa (grupos)", array_sum(array_column($filasEmp, 'monto')), 51449604.56);

$porEstatus = [];
foreach ($cruceEmp as $r) $porEstatus[$r['estatus']] = ($porEstatus[$r['estatus']] ?? 0) + 1;
ksort($porEstatus);
echo "-- informativo: estatus empresa $empresa\n";
foreach ($porEstatus as $e => $n) printf("   %-32s %5d\n", $e, $n);

// Informativo: alertas que verá la vista con el mismo contexto que el controlador (sin facturas: los grupos auto no llevan F)
$otros = ConciliacionCobrosContexto::otrosGrupos($grupos);
$cuentas = ConciliacionCobrosContexto::cuentasEstacion($grupos, $movPorId);
$conAlertas = 0;
$porTipo = [];
foreach ($grupos as $g) {
    $al = ConciliacionAlertas::deGrupo($g, [
        'cobros' => $cobrosPorClave, 'movs' => $movPorId, 'facturas' => [],
        'otrosGrupos' => $otros[$g['id']] ?? [], 'cuentasEstacion' => $cuentas, 'mes' => $mes,
    ]);
    if ($al) $conAlertas++;
    foreach ($al as $a) {
        $tipo = preg_replace('/[\d$.,]+/', '#', $a);
        $tipo = preg_replace('/(cliente|cuenta) .*/i', '$1 …', $tipo);
        $porTipo[$tipo] = ($porTipo[$tipo] ?? 0) + 1;
    }
}
arsort($porTipo);
echo "-- informativo: grupos con alertas $conAlertas de " . count($grupos) . ", grupos con items repetidos " . count($otros) . "\n";
foreach (array_slice($porTipo, 0, 12, true) as $t => $n) printf("   %5d  %s\n", $n, $t);

echo $fallas === 0 ? "\nTodo bien\n" : "\n$fallas falla(s)\n";
exit($fallas === 0 ? 0 : 1);
