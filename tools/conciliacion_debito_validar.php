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
    // El Excel traía 31,932,212.69. Hoy la BD da 31,921,675.84: el Excel tenía 33 despachos de GOLDEN TRAVELER
    // (196,068.48) y la BD tiene 32 (185,531.63 = sus cortes); se borró un despacho de exactamente 10,536.85
    // después de extraer el Excel.
    comparar('Venta (monto despachos)', $suma($venta, 'monto'), 31921675.84);
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

/** Tabs 7-9 y card B del Resumen: cobros prepago vs anticipos facturados (mismas clases que el controlador). */
function validar_anticipos(): void {
    $mes = '2026-09';
    $empresa = 1;
    $from = dateToInt($mes . '-01');
    $until = dateToInt(date('Y-m-t', strtotime($mes . '-01')));
    $model = new ConciliacionDebitoModel;

    $anticipos = $model->anticipos($from, $until, $empresa);
    $cobros = $model->cobros_prepago($from, $until, $empresa);
    $canceladas = $model->anticipos_cancelados($from, $until, $empresa);
    $porFactura = ConciliacionDebito::cobrosPorFactura($cobros, array_map(fn($a) => (int)$a['Factura'], $anticipos));
    $totalAnticipos = array_sum(array_map(fn($a) => (float)$a['Total'], $anticipos));
    $totalCobros = array_sum(array_map(fn($c) => (float)$c['monto'], $cobros));
    $p = ConciliacionDebito::partidasAnticipos($anticipos, $porFactura, count($canceladas), $totalCobros);

    echo "\n== validar_anticipos: $mes, empresa $empresa ==\n";
    comparar('Anticipos (cantidad)', count($anticipos), 1871, 0);
    comparar('Anticipos (importe)', $totalAnticipos, 32591067.05);
    comparar('Cobros prepago (cantidad)', count($cobros), 1861, 0);
    comparar('Cobros prepago (importe)', $totalCobros, 32605435.05);
    comparar('Cobros − anticipos', $p['diferencia'], 14368.00);
    comparar('Cobros aplicados a anticipos de otro mes', $p['otro_mes']['importe'], 17868.00);
    comparar('Cobros sin factura en referencia', $p['sin_factura']['importe'], 10000.00);
    comparar('Anticipos sin cobro aplicado', $p['anticipos_sin_cobro']['importe'], -13500.00);
    comparar('Sin explicar', $p['sin_explicar'], 0.00);

    $nros = fn(array $filas) => array_map(fn($f) => (int)$f['nro'], $filas);
    $esperado = fn(array $filas, array $esp) => count(array_diff($nros($filas), $esp)) + count(array_diff($esp, $nros($filas))) === 0;
    comparar('Otro mes = 1200242940, 1200242934, 1200089958', (float)$esperado($p['otro_mes']['filas'], [1200242940, 1200242934, 1200089958]), 1, 0);
    comparar('Sin cobro = 1200244867, 1200244401', (float)$esperado($p['anticipos_sin_cobro']['filas'], [1200244867, 1200244401]), 1, 0);

    // Las canceladas conservan número pero no importe. La BD marca 60 como canceladas en septiembre (empresa 1): son
    // cancelaciones re-emitidas dentro del mes (flgcon 141/5/7/13); el Excel solo mostraba las 2 sin cobro,
    // 1200243922 y 1200244351 (canceladas el 2026-10-31, después de extraerlo). Las 60 se listan en el tab 7.
    $nrosCanc = $nros($canceladas);
    comparar('Las 2 canceladas conocidas están en el listado', (float)(in_array(1200243922, $nrosCanc) && in_array(1200244351, $nrosCanc)), 1, 0);
    comparar('Anticipos cancelados del mes', count($canceladas), 60, 0);
    $tardias = array_filter($canceladas, fn($c) => $c['fecha_cancelacion'] > date('Y-m-t', strtotime($mes . '-01')));
    echo "-- informativo: canceladas con fecha de cancelación posterior al mes: " . count($tardias) . " (" . implode(', ', $nros($tardias)) . ")\n";
}

/**
 * Tabs 10-12: cruce de cobros prepago con 2791 / 0956 (ConciliacionDebitoModel::cruce_prepago y movimientos_clasificados,
 * las mismas llamadas que los endpoints payments/ y bank/). Imprime tiempos y los cobros sin banco para revisión.
 */
function validar_cobros(): void {
    $mes = '2026-09';
    $empresa = 1;
    $desde = $mes . '-01';
    $hasta = date('Y-m-t', strtotime($desde));
    $from = dateToInt($desde);
    $until = dateToInt($hasta);
    $model = new ConciliacionDebitoModel;
    $cta2791 = ConciliacionDebitoModel::CUENTAS_DEBITO[0];
    $cta0956 = ConciliacionDebitoModel::CUENTAS_DEBITO[1];

    echo "\n== validar_cobros: $mes, empresa $empresa ==\n";
    $t = microtime(true);
    $cruce = $model->cruce_prepago($from, $until, $desde, $hasta);
    printf("-- tiempo endpoint payments/ (cruce prepago, todas las razones sociales): %.1f s\n", microtime(true) - $t);
    $filas = array_values(array_filter($cruce, fn($r) => ($r['empresa'] === null ? -1 : (int)$r['empresa']) === $empresa));

    // Endpoint bank/ tal cual (en frío: recalcula el cruce prepago y el de crédito) y luego reutilizando el cruce ya calculado.
    $t = microtime(true);
    $movs2791 = $model->movimientos_clasificados($from, $until, $desde, $hasta, $cta2791);
    printf("-- tiempo endpoint bank/ 2791 (en frio: cruce prepago + cruce credito + clasificacion): %.1f s
", microtime(true) - $t);
    $t = microtime(true);
    $movs0956 = $model->movimientos_clasificados($from, $until, $desde, $hasta, $cta0956, $cruce);
    printf("-- tiempo bank/ 0956 reutilizando el cruce prepago (solo credito + clasificacion): %.1f s
", microtime(true) - $t);

    $abonos = array_filter($movs2791, fn($m) => $m['abono'] > 0);
    comparar('Banco 2791: abonos (cantidad)', count($abonos), 1925, 0);
    comparar('Banco 2791: abonos (importe)', array_sum(array_column($abonos, 'abono')), 37229115.24);
    comparar('Banco 0956: movimientos', count($movs0956), 2329, 0);

    $vivos = array_filter($filas, fn($r) => $r['estatus'] !== 'Cancelado');
    $total = array_sum(array_column($vivos, 'monto'));
    $ligados2791 = array_filter($vivos, fn($r) => $r['cuenta'] === $cta2791 && strncmp((string)$r['estatus_cruce'], 'Cuadra', 6) === 0);
    $ligados2791Mes = array_filter($ligados2791, fn($r) => $r['estatus'] !== ConciliacionDebito::COB_MES_ANTERIOR);
    $monto2791 = array_sum(array_column($ligados2791, 'monto'));
    comparar('Cobros prepago ligados a 2791 por el cruce (Excel 32,007,985.13)', $monto2791, 32007985.13, 50.00);

    $ligados = array_filter($vivos, fn($r) => $r['cuenta'] !== null && $r['cuenta'] !== '' && strncmp((string)$r['estatus_cruce'], 'Cuadra', 6) === 0);
    $pct = $total > 0 ? 100 * array_sum(array_column($ligados, 'monto')) / $total : 0;
    printf("%-4s %-52s obtenido %17.2f%%  esperado >= 97%%\n", $pct >= 97 ? 'ok' : 'FALLA', '% cuadrado (monto ligado a banco / cobros no cancelados)', $pct);
    global $fallas;
    if ($pct < 97) $fallas++;

    $porEstatus = [];
    foreach ($filas as $r) {
        $porEstatus[$r['estatus']]['n'] = ($porEstatus[$r['estatus']]['n'] ?? 0) + 1;
        $porEstatus[$r['estatus']]['monto'] = ($porEstatus[$r['estatus']]['monto'] ?? 0) + (float)$r['monto'];
    }
    ksort($porEstatus);
    echo "-- informativo: cobros empresa $empresa " . count($filas) . ", no cancelados " . count($vivos) . ", $" . number_format($total, 2) . "; ligados a 2791 en el mes " . count($ligados2791Mes) . "\n";
    foreach ($porEstatus as $e => $v) printf("   %-32s %5d cobros  $ %16s\n", $e, $v['n'], number_format($v['monto'], 2));

    $porClase = [];
    foreach (array_merge($movs2791, $movs0956) as $m) {
        $k = $m['cuenta'] . ' ' . $m['clasificacion'];
        $porClase[$k]['n'] = ($porClase[$k]['n'] ?? 0) + 1;
        $porClase[$k]['abono'] = ($porClase[$k]['abono'] ?? 0) + $m['abono'];
        $porClase[$k]['cargo'] = ($porClase[$k]['cargo'] ?? 0) + $m['cargo'];
    }
    ksort($porClase);
    echo "-- informativo: movimientos por clasificación\n";
    foreach ($porClase as $k => $v) printf("   %-40s %5d movs  abonos %16s  cargos %16s\n", $k, $v['n'], number_format($v['abono'], 2), number_format($v['cargo'], 2));

    echo "-- cobros sin banco (para revisión; empresa $empresa):\n";
    $sin = array_filter($filas, fn($r) => in_array($r['estatus'], [ConciliacionDebito::COB_NO_LOCALIZADO, 'Diferencia', 'Sin banco', 'Sin MOV'], true));
    foreach ($sin as $r) {
        printf("   %-26s est %-10s cobro %8d  %s  cod %-6s %-28s $ %12s  %s\n", $r['estatus'], trim((string)$r['estacion']), $r['nro'], $r['fecha'], $r['codopr'],
            mb_substr(trim((string)$r['cliente']), 0, 28), number_format((float)$r['monto'], 2), mb_substr(trim((string)$r['txtref']), 0, 60));
    }
    echo "   total sin banco: " . count($sin) . " cobros, $" . number_format(array_sum(array_column($sin, 'monto')), 2) . "\n";
    foreach ([ConciliacionDebito::COB_EFECTIVO, ConciliacionDebito::COB_CONSUMO_PROPIO, ConciliacionDebito::COB_MES_ANTERIOR] as $e) {
        $x = array_filter($filas, fn($r) => $r['estatus'] === $e);
        echo "   $e: " . count($x) . " cobros, $" . number_format(array_sum(array_column($x, 'monto')), 2) . "\n";
    }
}

validar_ventas();
validar_anticipos();
validar_cobros();
echo $fallas ? "\n$fallas línea(s) no cuadran\n" : "\nTodo ok\n";
exit($fallas ? 1 : 0);
