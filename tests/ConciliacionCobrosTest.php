<?php
declare(strict_types=1);

/**
 * Pruebas del cruce cobros vs banco:  php tests/ConciliacionCobrosTest.php
 * Los casos salen de agosto 2026 (Gemela Grande vs Banorte 1155446527).
 */
require __DIR__ . '/support.php';
require __DIR__ . '/../_assets/classes/ConciliacionCobros.class.php';

function cobro(int $nro, ?int $mov, float $monto, string $fecha = '2026-08-03', int $cancelado = 0): array {
    return ['nro' => $nro, 'codgas' => 2, 'fecha' => $fecha, 'monto' => $monto, 'mov' => $mov, 'cancelado' => $cancelado];
}
function deposito(int $id, ?int $sec, float $abono, string $fecha = '2026-08-03', string $cuenta = '1155446527'): array {
    return ['id' => $id, 'banco' => 'BANORTE', 'cuenta' => $cuenta, 'fecha' => $fecha, 'secuencia' => $sec, 'abono' => $abono,
            'nombre_contraparte' => 'X', 'rfc_contraparte' => 'XAXX010101000', 'detalle' => ''];
}
function porNro(array $rows): array {
    $out = [];
    foreach ($rows as $r) $out[$r['nro']] = $r;
    return $out;
}

// 1. Dos cobros del mismo MOV suman el depósito
$r = porNro(ConciliacionCobros::conciliar(
    [cobro(1, 100, 300.00), cobro(2, 100, 700.00)],
    [deposito(10, 100, 1000.00)]
));
check('MOV completo: cuadra', $r[1]['estatus'] === 'Cuadra' && $r[2]['estatus'] === 'Cuadra');
check('MOV completo: mismo depósito y suma', $r[1]['deposito_id'] === 10 && $r[2]['monto_mov'] === 1000.0 && $r[1]['diferencia'] === 0.0);

// 2. Centavos
$r = porNro(ConciliacionCobros::conciliar([cobro(1, 22288, 38434.92)], [deposito(10, 22288, 38434.94)]));
check('Centavos: cuadra (centavos) con diferencia 0.02', $r[1]['estatus'] === 'Cuadra (centavos)' && $r[1]['diferencia'] === 0.02);

// 3. MOV compartido (22197): 20,000 es el abono, 68,986.37 es otro depósito con secuencia distinta
$r = porNro(ConciliacionCobros::conciliar(
    [cobro(1, 22197, 20000.00), cobro(2, 22197, 68986.37)],
    [deposito(10, 22197, 20000.00), deposito(11, 22198, 68986.37)]
));
check('MOV compartido: el exacto se queda con el depósito', $r[1]['estatus'] === 'Cuadra (MOV compartido)' && $r[1]['deposito_id'] === 10);
check('MOV compartido: el otro cuadra por importe', $r[2]['estatus'] === 'Cuadra por importe' && $r[2]['deposito_id'] === 11);

// 3b. MOV compartido con subconjunto (22232): tres cobros suman el abono, el cuarto es otro depósito
$r = porNro(ConciliacionCobros::conciliar(
    [cobro(1, 22232, 57892.43), cobro(2, 22232, 40394.62), cobro(3, 22232, 14782.03), cobro(4, 22232, 1352.36)],
    [deposito(10, 22232, 56529.01), deposito(11, 22231, 57892.43)]
));
check('Subconjunto: los tres que suman el abono', $r[2]['estatus'] === 'Cuadra (MOV compartido)' && $r[3]['deposito_id'] === 10 && $r[4]['monto_mov'] === 56529.01);
check('Subconjunto: el otro por importe', $r[1]['estatus'] === 'Cuadra por importe' && $r[1]['deposito_id'] === 11);

// 3c. El resto del MOV cuadra después de que uno se fue por importe (22247)
$r = porNro(ConciliacionCobros::conciliar(
    array_merge([cobro(1, 22247, 14118.15)], array_map(fn($k) => cobro(10 + $k, 22247, 1000.00 + $k), range(1, 15))),
    [deposito(10, 22247, array_sum(array_map(fn($k) => 1000.00 + $k, range(1, 15)))), deposito(11, 22246, 14118.15)]
));
check('Resto del MOV cuadra al final', $r[11]['estatus'] === 'Cuadra' && $r[25]['deposito_id'] === 10 && $r[1]['deposito_id'] === 11);

// 3d. Depósito del MOV ya tomado por otro cobro: Sin banco (no una diferencia contra un depósito ajeno)
$r = porNro(ConciliacionCobros::conciliar(
    [cobro(1, 22534, 690191.00), cobro(2, 22534, 199393.12)],
    [deposito(10, 22534, 690191.00)]
));
check('MOV usado por otro: sin banco', $r[1]['deposito_id'] === 10 && $r[2]['estatus'] === 'Sin banco' && $r[2]['deposito_id'] === null);

// 4. MOV intercambiados (22369 / 22370)
$r = porNro(ConciliacionCobros::conciliar(
    [cobro(1, 22369, 47150.95), cobro(2, 22370, 3176.25)],
    [deposito(10, 22369, 3176.25), deposito(11, 22370, 47150.95)]
));
check('MOV intercambiados: cuadran como vecinos', $r[1]['estatus'] === 'Cuadra (MOV vecinos)' && $r[2]['estatus'] === 'Cuadra (MOV vecinos)');

// 4b. Cobros repartidos entre dos MOV (22203 + 22204)
$r = porNro(ConciliacionCobros::conciliar(
    [cobro(1, 22203, 500.00), cobro(2, 22203, 300.00), cobro(3, 22204, 200.00)],
    [deposito(10, 22203, 600.00), deposito(11, 22204, 400.00)]
));
check('MOV repartidos: los tres cuadran como vecinos', count(array_filter($r, fn($x) => $x['estatus'] === 'Cuadra (MOV vecinos)')) === 3);

// 5. Sin banco, diferencia, cancelado, sin MOV
$r = porNro(ConciliacionCobros::conciliar(
    [cobro(1, 21338, 2392.04, '2026-08-12'), cobro(2, 500, 100.00), cobro(3, null, 0.0, '2026-08-31', 1), cobro(4, null, 50.00)],
    [deposito(10, 500, 999.00)]
));
check('Sin banco', $r[1]['estatus'] === 'Sin banco' && $r[1]['deposito_id'] === null);
check('Diferencia: muestra el depósito y la diferencia', $r[2]['estatus'] === 'Diferencia' && $r[2]['diferencia'] === 899.0);
check('Cancelado', $r[3]['estatus'] === 'Cancelado');
check('Sin MOV', $r[4]['estatus'] === 'Sin MOV');

// Un depósito no se usa dos veces; fuera de ventana no cuenta
$r = porNro(ConciliacionCobros::conciliar(
    [cobro(1, 700, 100.00), cobro(2, 800, 100.00, '2026-08-04')],
    [deposito(10, 700, 100.00), deposito(11, 800, 100.00, '2026-01-10')]
));
check('Depósito usado una vez: el 2 no toma el depósito del 1', $r[1]['deposito_id'] === 10 && $r[2]['deposito_id'] !== 10);
check('Fuera de ventana: sin banco', $r[2]['estatus'] === 'Sin banco');

// Por importe sin MOV prefiere la cuenta… (sin MOV no hay cuenta) y la fecha más cercana
$r = porNro(ConciliacionCobros::conciliar(
    [cobro(1, null, 1234.56, '2026-08-10')],
    [deposito(10, 900, 1234.56, '2026-08-13'), deposito(11, 901, 1234.56, '2026-08-11')]
));
check('Sin MOV por importe: el más cercano en fecha', $r[1]['estatus'] === 'Cuadra por importe (sin MOV)' && $r[1]['deposito_id'] === 11);

// MOV desde el txtref: Gemela Grande escribe "MOV.n"; otras estaciones "Movimiento: n" o nada
check('movDeTxtref: MOV.22197', ConciliacionCobros::movDeTxtref('@B:X@N:MOV.22197@M:03') === 22197);
check('movDeTxtref: MOV. 22673 (con espacio)', ConciliacionCobros::movDeTxtref('@N:MOV. 22673@M:03') === 22673);
check('movDeTxtref: Movimiento: 93701', ConciliacionCobros::movDeTxtref('@N:Movimiento: 93701') === 93701);
check('movDeTxtref: MOV. vacío', ConciliacionCobros::movDeTxtref('@N:MOV.@M:04') === null);
check('movDeTxtref: Referencia no es MOV', ConciliacionCobros::movDeTxtref('@N:Referencia: 8735384') === null);
check('movDeTxtref: solo mira la nota (@N)', ConciliacionCobros::movDeTxtref('@B:MOVIL 123456@N:@M:03') === null);

// Por importe con varios candidatos: gana la cuenta que la estación ya usó en un candidato único
$c1 = cobro(1, null, 100.00, '2026-08-05'); $c1['codgas'] = 24;
$c2 = cobro(2, null, 500.00, '2026-08-05'); $c2['codgas'] = 24;
$r = porNro(ConciliacionCobros::conciliar(
    [$c2, $c1],
    [deposito(10, null, 100.00, '2026-08-05', 'SANT-24'),
     deposito(11, null, 500.00, '2026-08-05', 'OTRA'), deposito(12, null, 500.00, '2026-08-06', 'SANT-24')]
));
check('Por importe: único primero', $r[1]['deposito_id'] === 10 && $r[1]['candidatos'] === 1);
check('Por importe: desempata con la cuenta de la estación', $r[2]['deposito_id'] === 12 && $r[2]['candidatos'] === 2);

finish();
