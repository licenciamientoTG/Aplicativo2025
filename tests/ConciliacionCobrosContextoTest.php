<?php
declare(strict_types=1);

/**
 * Pruebas del contexto de alertas de la conciliación de cobros guardada:  php tests/ConciliacionCobrosContextoTest.php
 */
require __DIR__ . '/support.php';
require __DIR__ . '/../_assets/classes/ConciliacionCobrosGuardado.class.php';
require __DIR__ . '/../_assets/classes/ConciliacionCobrosContexto.class.php';

function itC(int $codgas, int $nro, float $monto = 100.0): array {
    return ['tipo' => 'C', 'codgas' => $codgas, 'nro' => $nro, 'movimiento_id' => null, 'monto' => $monto, 'mes' => '2026-10', 'ocupa' => 1];
}
function itM(int $id, int $ocupa = 1, float $monto = 100.0): array {
    return ['tipo' => 'M', 'codgas' => null, 'nro' => null, 'movimiento_id' => $id, 'monto' => $monto, 'mes' => '2026-10', 'ocupa' => $ocupa];
}
function itF(int $codgas, int $nro, float $monto = 100.0): array {
    return ['tipo' => 'F', 'codgas' => $codgas, 'nro' => $nro, 'movimiento_id' => null, 'monto' => $monto, 'mes' => '2026-10', 'ocupa' => 1];
}
function grp(int $id, string $estatus, array $items, string $origen = 'auto'): array {
    return ['id' => $id, 'origen' => $origen, 'estatus' => $estatus, 'items' => $items];
}

// cuentasEstacion: solo grupos Cuadra* o Conciliado manual, M con ocupa = 1, una vez por codgas del grupo
$movs = [
    1 => ['id' => 1, 'cuenta' => 'A'], 2 => ['id' => 2, 'cuenta' => 'B'], 3 => ['id' => 3, 'cuenta' => 'C'],
    4 => ['id' => 4, 'cuenta' => 'D'], 5 => ['id' => 5, 'cuenta' => 'A'],
];
$grupos = [
    grp(10, 'Cuadra', [itM(1), itC(2, 100), itC(2, 101), itC(7, 102)]),
    grp(11, 'Cuadra por importe', [itM(5), itC(2, 103)]),
    grp(12, 'Diferencia', [itM(2, 0), itC(2, 104)]),
    grp(13, 'Conciliado manual', [itM(3), itC(9, 105)], 'manual'),
    grp(14, 'Sin banco', [itC(2, 106)]),
    grp(15, 'Cuadra', [itM(99), itC(2, 107)]),   // movimiento que ya no existe: no cuenta
];
$ce = ConciliacionCobrosContexto::cuentasEstacion($grupos, $movs);
check('cuentasEstacion: estación 2 cuenta A en 2 grupos', ($ce[2]['A'] ?? 0) === 2);
check('cuentasEstacion: estación 7 cuenta A una vez', ($ce[7]['A'] ?? 0) === 1);
check('cuentasEstacion: Diferencia no cuenta', !isset($ce[2]['B']));
check('cuentasEstacion: manual cuenta', ($ce[9]['C'] ?? 0) === 1);
check('cuentasEstacion: sin otras estaciones', array_keys($ce) == [2, 7, 9]);

// otrosGrupos: vacío si nada se repite
check('otrosGrupos: nada repetido = vacío', ConciliacionCobrosContexto::otrosGrupos($grupos) === []);

// otrosGrupos: el mismo cobro / movimiento en 2 grupos activos
$rep = [
    grp(20, 'Cuadra', [itM(1), itC(2, 100)]),
    grp(21, 'Conciliado manual', [itM(1), itC(2, 100), itF(2, 900)], 'manual'),
    grp(22, 'Diferencia', [itM(1, 0), itC(2, 300)]),   // M con ocupa = 0: no se cuenta como repetido
    grp(23, 'Conciliado manual', [itF(2, 900), itC(3, 1)], 'manual'),
];
$og = ConciliacionCobrosContexto::otrosGrupos($rep);
check('otrosGrupos: grupo 20 ve al 21 por el cobro', ($og[20]['C|2|100'] ?? null) === ['id' => 21, 'origen' => 'manual']);
check('otrosGrupos: grupo 20 ve al 21 por el movimiento', ($og[20]['M|1'] ?? null) === ['id' => 21, 'origen' => 'manual']);
check('otrosGrupos: grupo 21 ve al 20', ($og[21]['C|2|100'] ?? null) === ['id' => 20, 'origen' => 'auto']);
check('otrosGrupos: factura repetida 21 ↔ 23', ($og[21]['F|2|900'] ?? null) === ['id' => 23, 'origen' => 'manual']
    && ($og[23]['F|2|900'] ?? null) === ['id' => 21, 'origen' => 'manual']);
check('otrosGrupos: Diferencia (ocupa 0) sin alerta', !isset($og[22]));
check('otrosGrupos: grupo 23 solo la factura', array_keys($og[23]) === ['F|2|900']);

// facturasDelGrupo: pagada sin los pagos de los cobros del propio grupo
$facturas = [
    '2|900' => ['codgas' => 2, 'nro' => 900, 'folio' => 'I900', 'total' => 500.0, 'cancelada' => 0, 'cliente' => 'X',
                'pagada' => 350.0, 'pagos' => '2|100:300.00,5|7:50.00'],
    '2|901' => ['codgas' => 2, 'nro' => 901, 'folio' => 'I901', 'total' => 80.0, 'cancelada' => 0, 'cliente' => 'X',
                'pagada' => 0.0, 'pagos' => null],
    '2|902' => ['codgas' => 2, 'nro' => 902, 'folio' => 'I902', 'total' => 80.0, 'cancelada' => 0, 'cliente' => 'X',
                'pagada' => 80.0, 'pagos' => '2|100:80.00'],
];
$g = grp(30, 'Conciliado manual', [itM(1), itC(2, 100), itF(2, 900), itF(2, 901), itF(2, 902), itF(2, 999)], 'manual');
$fg = ConciliacionCobrosContexto::facturasDelGrupo($g, $facturas);
check('facturasDelGrupo: descuenta el cobro propio', abs($fg['2|900']['pagada'] - 50.0) < 0.001);
check('facturasDelGrupo: sin pagos queda igual', abs($fg['2|901']['pagada']) < 0.001);
check('facturasDelGrupo: pagada solo por el propio = 0', abs($fg['2|902']['pagada']) < 0.001);
check('facturasDelGrupo: no inventa la que ya no existe', !array_key_exists('2|999', $fg));
check('facturasDelGrupo: solo las del grupo', array_keys($fg) === ['2|900', '2|901', '2|902']);
check('facturasDelGrupo: no toca el mapa original', abs($facturas['2|900']['pagada'] - 350.0) < 0.001);
$sinCobro = ConciliacionCobrosContexto::facturasDelGrupo(grp(31, 'Conciliado manual', [itM(1), itF(2, 900)], 'manual'), $facturas);
check('facturasDelGrupo: grupo sin cobros conserva lo pagado', abs($sinCobro['2|900']['pagada'] - 350.0) < 0.001);

finish();
