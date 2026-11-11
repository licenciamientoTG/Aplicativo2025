<?php
declare(strict_types=1);

/**
 * Pruebas de la lógica pura de Conciliación One Goal ↔ ControlGas:  php tests/ConciliacionOneGoalTest.php
 */
require __DIR__ . '/support.php';
require __DIR__ . '/../_assets/classes/ConciliacionOneGoal.class.php';

check('folio de archivo D',
    ConciliacionOneGoal::folioDeArchivo('DGA930823KD3_CFDI_D243350_20260904.xml') === ['serie' => 'D', 'folio' => 243350]);
check('folio de archivo inválido = null', ConciliacionOneGoal::folioDeArchivo('otra_cosa.xml') === null);

check('rangos consecutivos',
    ConciliacionOneGoal::rangos([1200243795, 1200243796, 1200243797, 1200243900])
    === [['desde' => 'D243795', 'hasta' => 'D243797', 'n' => 3], ['desde' => 'D243900', 'hasta' => 'D243900', 'n' => 1]]);
check('rangos vacío', ConciliacionOneGoal::rangos([]) === []);

$cg = [
    ['nro' => 1200000001, 'uuid' => 'AAAA', 'fecha' => '2026-09-01', 'codcli' => 1, 'cliente' => 'Uno', 'total' => 100.0, 'cancelada' => 0],
    ['nro' => 1200000002, 'uuid' => 'bbbb', 'fecha' => '2026-09-01', 'codcli' => 2, 'cliente' => 'Dos', 'total' => 200.0, 'cancelada' => 0],
    ['nro' => 1200000003, 'uuid' => 'cccc', 'fecha' => '2026-09-02', 'codcli' => 3, 'cliente' => 'Tres', 'total' => 300.0, 'cancelada' => 0],
    ['nro' => 1200000004, 'uuid' => '',     'fecha' => '2026-09-02', 'codcli' => 4, 'cliente' => 'Cuatro', 'total' => 50.0, 'cancelada' => 0],
    ['nro' => 1200000005, 'uuid' => 'dddd', 'fecha' => '2026-09-03', 'codcli' => 5, 'cliente' => 'Cinco', 'total' => 0.0, 'cancelada' => 1],
    ['nro' => 1200000006, 'uuid' => 'eeee', 'fecha' => '2026-09-03', 'codcli' => 6, 'cliente' => 'Seis', 'total' => 0.0, 'cancelada' => 1],
];
$og = [
    ['uuid' => 'aaaa', 'archivo' => 'X_CFDI_D1_20260901.xml', 'cliente' => 'UNO SA', 'rfc' => 'R1', 'fecha' => '2026-09-01'],
    ['uuid' => 'bbbb', 'archivo' => 'X_CFDI_D2_20260901.xml', 'cliente' => 'DOS SA', 'rfc' => 'R2', 'fecha' => '2026-09-01'],
    ['uuid' => 'eeee', 'archivo' => 'X_CFDI_D6_20260903.xml', 'cliente' => 'SEIS SA', 'rfc' => 'R6', 'fecha' => '2026-09-03'],
    ['uuid' => 'zzzz', 'archivo' => 'X_CFDI_D99_20260905.xml', 'cliente' => 'EXTRA SA', 'rfc' => 'R9', 'fecha' => '2026-09-05'],
];
$r = ConciliacionOneGoal::conciliar($cg, $og);
$por = [];
foreach ($r['filas'] as $f) $por[$f['nro'] ?: $f['uuid']] = $f['estatus'];

check('UUID en mayúsculas y minúsculas cuadra (En ambos)', $por[1200000001] === 'En ambos' && $por[1200000002] === 'En ambos');
check('vigente que One Goal no tiene = Falta en One Goal', $por[1200000003] === 'Falta en One Goal');
check('vigente sin UUID = Sin UUID', $por[1200000004] === 'Sin UUID');
check('cancelada que One Goal no tiene = Cancelada', $por[1200000005] === 'Cancelada');
check('cancelada (sin datos de factura) que sigue en One Goal', $por[1200000006] === 'Cancelada, vigente en One Goal');
check('One Goal sin ControlGas = Solo en One Goal', $por['zzzz'] === 'Solo en One Goal');
check('una fila por anticipo más los extras de One Goal', count($r['filas']) === 7);
check('resumen: Falta en One Goal 1 por $300', $r['resumen']['por_estatus']['Falta en One Goal'] === ['n' => 1, 'importe' => 300.0]);
check('resumen: rangos faltantes D3', $r['resumen']['rangos_faltantes'] === [['desde' => 'D3', 'hasta' => 'D3', 'n' => 1]]);
$f1 = array_values(array_filter($r['filas'], fn($f) => $f['nro'] === 1200000001))[0];
check('fila En ambos trae el folio y cliente de One Goal', $f1['og_folio'] === 'D1' && $f1['og_cliente'] === 'UNO SA' && $f1['factura'] === 'D1');

$fo = ConciliacionOneGoal::filasOneGoal($og, ['aaaa', 'bbbb']);
check('One Goal: una fila por CFDI', count($fo) === 4);
$porU = []; foreach ($fo as $f) $porU[$f['uuid']] = $f;
check('One Goal: en_controlgas Sí/No', $porU['aaaa']['en_controlgas'] === 'Sí' && $porU['zzzz']['en_controlgas'] === 'No');
check('One Goal: folio y claves completas', $porU['zzzz']['folio'] === 'D99' && array_key_exists('total', $porU['zzzz']) && array_key_exists('mensaje', $porU['zzzz']));

// Con facturas de One Goal (imp_fac_enc): importes y estatus
$fac = [
    ['id_fac' => 1, 'nro' => 1200000001, 'numero' => 1, 'fecha' => '2026-09-01', 'sub' => 86.21, 'iva' => 13.79, 'total' => 100.0, 'status' => 1, 'status_imp' => 34, 'mensaje' => 'sin despachos', 'id_cli' => 1],
    ['id_fac' => 2, 'nro' => 1200000002, 'numero' => 2, 'fecha' => '2026-09-01', 'sub' => 0, 'iva' => 0, 'total' => 250.0, 'status' => 1, 'status_imp' => 34, 'mensaje' => '', 'id_cli' => 2],
    ['id_fac' => 5, 'nro' => 1200000005, 'numero' => 5, 'fecha' => '2026-09-03', 'sub' => 0, 'iva' => 0, 'total' => 80.0, 'status' => 3, 'status_imp' => 39, 'mensaje' => '', 'id_cli' => 5],
    ['id_fac' => 6, 'nro' => 1200000006, 'numero' => 6, 'fecha' => '2026-09-03', 'sub' => 0, 'iva' => 0, 'total' => 900.0, 'status' => 1, 'status_imp' => 34, 'mensaje' => '', 'id_cli' => 6],
    ['id_fac' => 7, 'nro' => 1200000004, 'numero' => 4, 'fecha' => '2026-09-02', 'sub' => 0, 'iva' => 0, 'total' => 0, 'status' => -1, 'status_imp' => 49, 'mensaje' => 'sin timbre', 'id_cli' => 4],
    ['id_fac' => 8, 'nro' => 0, 'numero' => 77, 'fecha' => '2026-09-10', 'sub' => 0, 'iva' => 0, 'total' => 40.0, 'status' => 1, 'status_imp' => 34, 'mensaje' => '', 'id_cli' => 9],
];
$cg2 = $cg; $cg2[] = ['nro' => 1200000007, 'uuid' => 'ffff', 'fecha' => '2026-09-04', 'codcli' => 7, 'cliente' => 'Siete', 'total' => 10.0, 'cancelada' => 0];
$cg2[0]['total'] = 100.0;
$r2 = ConciliacionOneGoal::conciliar($cg2, $og, $fac, '2026-09-01', '2026-09-30');
$p2 = []; foreach ($r2['filas'] as $f) $p2[$f['nro'] ?: ('og' . $f['og_folio'])] = $f;
check('importe distinto: ControlGas 200 vs One Goal 250', $p2[1200000002]['estatus'] === 'Importe distinto' && $p2[1200000002]['diferencia'] === -50.0);
check('En ambos con importe igual trae total y estatus de One Goal', $p2[1200000001]['estatus'] === 'En ambos' && $p2[1200000001]['og_total'] === 100.0 && $p2[1200000001]['og_estatus'] === 'Vigente');
check('vigente en CG y cancelada en One Goal: no aplica a la 3 (CG vigente sin match por factura)', $p2[1200000003]['estatus'] === 'Falta en One Goal');
check('cancelada en CG y cancelada en One Goal = Cancelada', $p2[1200000005]['estatus'] === 'Cancelada');
check('cancelada en CG y vigente en One Goal', $p2[1200000006]['estatus'] === 'Cancelada, vigente en One Goal' && $p2[1200000006]['og_total'] === 900.0);
check('sin UUID con factura Sin timbrar', $p2[1200000004]['estatus'] === 'Sin UUID' && $p2[1200000004]['og_estatus'] === 'Sin timbrar');
check('factura vigente de One Goal sin CG = Solo en One Goal con su importe', $p2['ogD77']['estatus'] === 'Solo en One Goal' && $p2['ogD77']['og_total'] === 40.0);

// ControlGas sin UUID pero One Goal con el CFDI de esa factura (por id_fact)
$cg4 = $cg2; $cg4[3]['nro'] = 1200000004;
$og4 = array_merge($og, [['uuid' => 'gggg', 'archivo' => 'X_CFDI_D4_20260902.xml', 'cliente' => 'CUATRO SA', 'rfc' => 'R4', 'fecha' => '2026-09-02', 'id_fact' => 70]]);
$fac4 = $fac; $fac4[4] = ['id_fac' => 70, 'nro' => 1200000004, 'numero' => 4, 'fecha' => '2026-09-02', 'sub' => 0, 'iva' => 0, 'total' => 50.0, 'status' => 1, 'status_imp' => 34, 'mensaje' => '', 'id_cli' => 4];
$r4 = ConciliacionOneGoal::conciliar($cg4, $og4, $fac4, '2026-09-01', '2026-09-30');
$p4 = []; foreach ($r4['filas'] as $f) $p4[$f['nro'] ?: ('og' . $f['og_folio'])] = $f;
check('sin UUID en CG pero CFDI en One Goal', $p4[1200000004]['estatus'] === 'Timbrada en One Goal, sin UUID en ControlGas' && $p4[1200000004]['uuid'] === 'gggg');
check('ese CFDI no se repite como Solo en One Goal', !isset($p4['ogD4']) && count(array_filter($r4['filas'], fn($f) => $f['uuid'] === 'gggg')) === 1);
$cu4 = $r4['resumen']['cuadre'];
check('cuadre con sin UUID timbrada en One Goal cierra', end($cu4)['importe'] === $cu4[count($cu4) - 2]['importe']);

// El cuadre cierra: One Goal vigente → total ControlGas
$cu = $r2['resumen']['cuadre'];
check('cuadre: la línea "= Total ControlGas" coincide con la suma de filas', end($cu)['importe'] === $cu[count($cu) - 2]['importe']);

$fac2 = $fac; $fac2[0]['status'] = 3;
$r3 = ConciliacionOneGoal::conciliar($cg2, $og, $fac2, '2026-09-01', '2026-09-30');
$p3 = []; foreach ($r3['filas'] as $f) $p3[$f['nro'] ?: ('og' . $f['og_folio'])] = $f;
check('vigente en CG y cancelada en One Goal', $p3[1200000001]['estatus'] === 'Cancelada en One Goal');
$cu3 = $r3['resumen']['cuadre'];
check('cuadre con cancelada en One Goal también cierra', end($cu3)['importe'] === $cu3[count($cu3) - 2]['importe']);

// Cada columna de DataTables tiene su clave en las filas que arma el endpoint (error 'unknown parameter' del débito)
$js = file_get_contents(__DIR__ . '/../_assets/js/conciliation_onegoal.js');
preg_match_all("/initTabla\('#(tablaOg|tablaOgSolo)', \[(.*?)\], '/s", $js, $mm, PREG_SET_ORDER);
$filasPorTabla = ['tablaOg' => $r2['filas'][0], 'tablaOgSolo' => ConciliacionOneGoal::filasOneGoal($og, ['aaaa'], $fac)[0]];
$faltan = [];
foreach ($mm as $m) {
    preg_match_all("/data: '(\w+)'/", $m[2], $ks);
    foreach ($ks[1] as $k) if (!array_key_exists($k, $filasPorTabla[$m[1]])) $faltan[] = $m[1] . '.' . $k;
}
check('columnas de las 2 tablas existen en las filas (' . implode(',', $faltan) . ')', count($mm) === 2 && !$faltan);
foreach ($r2['filas'] as $f) if (array_keys($f) !== array_keys($r2['filas'][0])) { $faltan[] = 'forma distinta ' . $f['estatus']; }
check('todas las filas de conciliación tienen las mismas claves', !$faltan);

finish();
