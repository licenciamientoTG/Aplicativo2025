<?php
/**
 * Valida la conciliación débito contra el Excel de septiembre 2026, DIAZ GAS (empresa 1), solo lectura:
 *   php tools/conciliacion_debito_validar.php
 * Usa las mismas clases que el controlador (modelo + ConciliacionDebito::clasificarFacturas / ventaPorCliente).
 * Sale con código 1 si alguna línea no cuadra (tolerancia 0.01).
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

function validar_ventas(): void {
    $mes = '2026-09';
    $empresa = 1;
    $from = dateToInt($mes . '-01');
    $until = dateToInt(date('Y-m-t', strtotime($mes . '-01')));

    $consumos = (new ClientesModel)->get_dispatches_summary_by_client($from, $until, 4, 0, $empresa, 'incluir') ?: [];
    $facturas = ConciliacionDebito::clasificarFacturas((new ConciliacionDebitoModel)->facturas_serie_e($from, $until, $empresa), $mes);
    $clientes = ConciliacionDebito::ventaPorCliente($consumos, $facturas);

    $enMes = fn($f) => substr($f['fecha'], 0, 7) === $mes;
    $vigentes = array_filter($facturas, fn($f) => $f['periodo'] !== 'Cancelada' && $enMes($f));
    $canceladas = array_filter($facturas, fn($f) => $f['periodo'] === 'Cancelada' && abs((float)$f['total']) >= 0.01);   // las de $0 no cuentan en el Excel
    $canceladasCero = array_filter($facturas, fn($f) => $f['periodo'] === 'Cancelada' && abs((float)$f['total']) < 0.01);
    $anterior = array_filter($facturas, fn($f) => $f['periodo'] === 'Mes anterior');
    $suma = fn(array $a, string $k) => array_sum(array_map(fn($x) => (float)$x[$k], $a));

    echo "== validar_ventas: $mes, empresa $empresa ==\n";
    // Venta = clientes de clase Venta con despachos o cortes (sin consumos propios ni bonificaciones)
    $venta = array_filter($clientes, fn($c) => $c['clase'] === 'Venta' && ($c['despachos'] > 0 || abs($c['cortes']) > 0));
    comparar('Venta (monto despachos)', $suma($venta, 'monto'), 31932212.69);
    comparar('Cortes', $suma($venta, 'cortes'), 31918014.67);
    comparar('Clientes de venta', count($venta), 774, 0);
    comparar('Facturas Serie E vigentes (cantidad)', count($vigentes), 759, 0);
    comparar('Facturas Serie E vigentes (importe)', $suma($vigentes, 'total'), 31201737.84);
    comparar('Facturas Serie E canceladas con importe (cantidad)', count($canceladas), 24, 0);
    comparar('Facturas Serie E canceladas (importe)', $suma($canceladas, 'total'), 786358.92);
    comparar('Facturas Mes anterior (importe)', $suma($anterior, 'total'), 185444.95);
    comparar('Por cliente: facturado mes + mes anterior = vigentes del mes', $suma($clientes, 'facturado_mes') + $suma($clientes, 'facturado_mes_anterior'), $suma($vigentes, 'total'));

    $pend = (new ConciliacionDebitoModel)->despachos_pendientes($from, $until, $empresa);
    echo "-- informativo: despachos pendientes de facturar (empresa $empresa): " . count($pend) . ", $" . number_format($suma($pend, 'monto'), 2) . "\n";

    $porEstatus = [];
    foreach ($clientes as $c) {
        $porEstatus[$c['estatus']]['n'] = ($porEstatus[$c['estatus']]['n'] ?? 0) + 1;
        $porEstatus[$c['estatus']]['dif'] = ($porEstatus[$c['estatus']]['dif'] ?? 0) + $c['diferencia'];
    }
    echo "-- informativo: canceladas con total 0 (no cuentan): " . count($canceladasCero) . "\n";
    echo "-- informativo: filas por cliente " . count($clientes) . ", Mes siguiente " . count(array_filter($facturas, fn($f) => $f['periodo'] === 'Mes siguiente')) . "\n";
    foreach ($porEstatus as $e => $v) printf("   %-32s %5d clientes  dif %16s\n", $e, $v['n'], number_format($v['dif'], 2));
}

validar_ventas();
echo $fallas ? "\n$fallas línea(s) no cuadran\n" : "\nTodo ok\n";
exit($fallas ? 1 : 0);
