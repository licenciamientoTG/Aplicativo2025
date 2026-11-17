<?php
declare(strict_types=1);

/**
 * Pruebas de los grupos guardables del cruce de cobros:  php tests/ConciliacionCobrosGuardadoTest.php
 */
require __DIR__ . '/support.php';
require __DIR__ . '/../_assets/classes/ConciliacionCobros.class.php';
require __DIR__ . '/../_assets/classes/ConciliacionCobrosGuardado.class.php';

function c(int $nro, ?int $mov, float $monto, string $fecha = '2026-10-07', int $codgas = 2): array {
    return ['nro' => $nro, 'codgas' => $codgas, 'fecha' => $fecha, 'monto' => $monto, 'mov' => $mov, 'cancelado' => 0];
}
function d(int $id, ?int $sec, float $abono, string $fecha = '2026-10-07'): array {
    return ['id' => $id, 'banco' => 'BANORTE', 'cuenta' => '1155446527', 'fecha' => $fecha, 'secuencia' => $sec, 'abono' => $abono,
            'nombre_contraparte' => 'X', 'rfc_contraparte' => 'XAXX010101000', 'detalle' => 'det'];
}
function itemsTipo(array $g, string $t): array {
    return array_values(array_filter($g['items'], fn($i) => $i['tipo'] === $t));
}
function cobroFull(int $nro, float $monto, string $fecha, ?int $mov = null, int $codgas = 2): array {
    return ['codgas' => $codgas, 'estacion' => 'GG', 'empresa' => 'E', 'nro' => $nro, 'fecha' => $fecha, 'codopr' => 5, 'cliente' => 'CLI',
            'saldo_linea' => 0, 'tipo' => 'T', 'satuid' => 'U', 'txtref' => 'ref', 'cancelado' => 0, 'monto' => $monto, 'facturas' => 'F1', 'mov' => $mov];
}

// gruposDesdeCruce: MOV con 2 cobros y 1 depósito → 1 grupo, 2 C + 1 M ocupa 1
$cruce = ConciliacionCobros::conciliar([c(64103, 23289, 2383.05), c(64104, 23289, 18959.88)], [d(9001, 23289, 21342.93)]);
$g = ConciliacionCobrosGuardado::gruposDesdeCruce($cruce, '2026-10');
check('un grupo por depósito', count($g) === 1 && $g[0]['estatus'] === 'Cuadra' && $g[0]['origen'] === 'auto');
check('items 2C + 1M', count(itemsTipo($g[0], 'C')) === 2
    && count(array_filter($g[0]['items'], fn($i) => $i['tipo'] === 'M' && $i['ocupa'] === 1 && $i['movimiento_id'] === 9001)) === 1);
check('mes del item C', itemsTipo($g[0], 'C')[0]['mes'] === '2026-10' && itemsTipo($g[0], 'M')[0]['mes'] === '2026-10');

// Diferencia → M ocupa 0
$cruce = ConciliacionCobros::conciliar([c(1, 100, 500.00)], [d(9002, 100, 900.00)]);
$g = ConciliacionCobrosGuardado::gruposDesdeCruce($cruce, '2026-10');
check('Diferencia: M ocupa 0', $g[0]['estatus'] === 'Diferencia' && itemsTipo($g[0], 'M')[0]['ocupa'] === 0 && itemsTipo($g[0], 'C')[0]['ocupa'] === 1);

// Sin banco → un grupo por cobro, sin M
$cruce = ConciliacionCobros::conciliar([c(1, 777, 10.00), c(2, 778, 20.00)], []);
$g = ConciliacionCobrosGuardado::gruposDesdeCruce($cruce, '2026-10');
check('Sin banco: un grupo por cobro sin M', count($g) === 2 && $g[0]['estatus'] === 'Sin banco' && !itemsTipo($g[0], 'M') && count(itemsTipo($g[1], 'C')) === 1);

// cobrosSinGrupo
$cobros = [c(1, null, 1), c(2, null, 1), c(3, null, 1)];
$grupos = [
    ['id' => 1, 'origen' => 'auto', 'estatus' => 'Cuadra', 'items' => [['tipo' => 'C', 'codgas' => 2, 'nro' => 1, 'movimiento_id' => null, 'monto' => 1.0, 'mes' => '2026-10', 'ocupa' => 1]]],
    ['id' => 2, 'origen' => 'auto', 'estatus' => 'Cuadra', 'items' => [['tipo' => 'C', 'codgas' => 2, 'nro' => 2, 'movimiento_id' => null, 'monto' => 1.0, 'mes' => '2026-10', 'ocupa' => 1]]],
];
$sin = ConciliacionCobrosGuardado::cobrosSinGrupo($cobros, $grupos);
check('cobrosSinGrupo devuelve el tercero', count($sin) === 1 && $sin[0]['nro'] === 3);
check('claveDoc', ConciliacionCobrosGuardado::claveDoc(2, 3) === '2|3');

// movimientosOcupados ignora ocupa 0
$grupos = [
    ['id' => 1, 'origen' => 'auto', 'estatus' => 'Cuadra', 'items' => [['tipo' => 'M', 'codgas' => null, 'nro' => null, 'movimiento_id' => 10, 'monto' => 1.0, 'mes' => '2026-10', 'ocupa' => 1]]],
    ['id' => 2, 'origen' => 'auto', 'estatus' => 'Diferencia', 'items' => [['tipo' => 'M', 'codgas' => null, 'nro' => null, 'movimiento_id' => 11, 'monto' => 1.0, 'mes' => '2026-10', 'ocupa' => 0]]],
];
$oc = ConciliacionCobrosGuardado::movimientosOcupados($grupos);
check('movimientosOcupados solo ocupa 1', isset($oc[10]) && !isset($oc[11]) && count($oc) === 1);

// filasDesdeGrupos: manual con cobro de otro mes
$grupo = ['id' => 5, 'origen' => 'manual', 'estatus' => 'Conciliado manual', 'comentario' => 'ok', 'creado_por' => 'a@b.c', 'items' => [
    ['tipo' => 'C', 'codgas' => 2, 'nro' => 64448, 'movimiento_id' => null, 'monto' => 1000.0, 'mes' => '2026-09', 'ocupa' => 1],
    ['tipo' => 'M', 'codgas' => null, 'nro' => null, 'movimiento_id' => 9001, 'monto' => 1050.0, 'mes' => '2026-10', 'ocupa' => 1],
]];
$filas = ConciliacionCobrosGuardado::filasDesdeGrupos([$grupo],
    ['2|64448' => cobroFull(64448, 1000.0, '2026-09-30', 123)],
    [9001 => d(9001, 555, 1050.0)], []);
$f = $filas[0];
check('manual: una fila con origen/estatus/grupo', count($filas) === 1 && $f['origen'] === 'manual' && $f['estatus'] === 'Conciliado manual' && $f['grupo_id'] === 5 && $f['nro'] === 64448);
check('manual: diferencia = abono - monto', $f['diferencia'] === 50.0 && $f['abono'] === 1050.0 && $f['monto_mov'] === 1000.0 && $f['cobros_mov'] === 1);
check('manual: datos del banco', $f['deposito_id'] === 9001 && $f['banco'] === 'BANORTE' && $f['secuencia_banco'] === '555' && $f['detalle_banco'] === 'det' && $f['candidatos'] === null);
check('manual: comentario/creado_por/items', $f['comentario'] === 'ok' && $f['creado_por'] === 'a@b.c' && $f['items_guardados'] === $grupo['items'] && $f['cliente'] === 'CLI' && $f['mov'] === 123);

// cobro borrado en ControlGas: usa monto guardado
$filas = ConciliacionCobrosGuardado::filasDesdeGrupos([$grupo], [], [9001 => d(9001, 555, 1050.0)], []);
check('cobro faltante: fila con monto guardado y cliente vacío', count($filas) === 1 && $filas[0]['nro'] === 64448 && $filas[0]['monto'] === 1000.0 && $filas[0]['cliente'] === '');

// varios movimientos
$g2 = ['id' => 6, 'origen' => 'manual', 'estatus' => 'Conciliado manual', 'items' => [
    ['tipo' => 'C', 'codgas' => 2, 'nro' => 1, 'movimiento_id' => null, 'monto' => 300.0, 'mes' => '2026-10', 'ocupa' => 1],
    ['tipo' => 'M', 'codgas' => null, 'nro' => null, 'movimiento_id' => 1, 'monto' => 100.0, 'mes' => '2026-10', 'ocupa' => 1],
    ['tipo' => 'M', 'codgas' => null, 'nro' => null, 'movimiento_id' => 2, 'monto' => 200.0, 'mes' => '2026-10', 'ocupa' => 1],
]];
$f = ConciliacionCobrosGuardado::filasDesdeGrupos([$g2], ['2|1' => cobroFull(1, 300.0, '2026-10-01')],
    [1 => d(1, 11, 100.0), 2 => d(2, 12, 200.0)], [])[0];
check('varios M: abono suma, secuencias unidas, diferencia 0', $f['abono'] === 300.0 && $f['secuencia_banco'] === '11, 12' && $f['diferencia'] === 0.0 && $f['deposito_id'] === 1);

// grupo M + F sin cobros
$g3 = ['id' => 7, 'origen' => 'manual', 'estatus' => 'Conciliado manual', 'items' => [
    ['tipo' => 'M', 'codgas' => null, 'nro' => null, 'movimiento_id' => 23673, 'monto' => 2000000.0, 'mes' => '2026-10', 'ocupa' => 1],
    ['tipo' => 'F', 'codgas' => 9, 'nro' => 104614, 'movimiento_id' => null, 'monto' => 1999000.0, 'mes' => '2026-10', 'ocupa' => 1],
]];
$filas = ConciliacionCobrosGuardado::filasDesdeGrupos([$g3], [], [23673 => d(23673, 77, 2000000.0)],
    ['9|104614' => ['folio' => 'I104614', 'total' => 1999500.0, 'cancelada' => 0, 'codopr' => 1, 'cliente' => 'Z']]);
$f = $filas[0];
check('M+F sin cobros: fila nro null con folios', count($filas) === 1 && $f['nro'] === null && $f['facturas'] === 'I104614');
check('M+F sin cobros: diferencia = abono - total factura', $f['diferencia'] === 500.0);
check('tiene_mov: con movimiento true, sin movimiento false', $f['tiene_mov'] === true
    && ConciliacionCobrosGuardado::filasDesdeGrupos([['id' => 8, 'origen' => 'manual', 'estatus' => 'Conciliado manual', 'items' => [
        ['tipo' => 'C', 'codgas' => 2, 'nro' => 1, 'movimiento_id' => null, 'monto' => 10.0, 'mes' => '2026-10', 'ocupa' => 1]]]],
        ['2|1' => cobroFull(1, 10.0, '2026-10-01')], [], [])[0]['tiene_mov'] === false);

// cobro cancelado: no suma en monto_mov ni en diferencia (igual que ConciliacionCobrosManual::totales)
$gCan = ['id' => 10, 'origen' => 'manual', 'estatus' => 'Conciliado manual', 'items' => [
    ['tipo' => 'C', 'codgas' => 2, 'nro' => 1, 'movimiento_id' => null, 'monto' => 100.0, 'mes' => '2026-10', 'ocupa' => 1],
    ['tipo' => 'C', 'codgas' => 2, 'nro' => 2, 'movimiento_id' => null, 'monto' => 50.0, 'mes' => '2026-10', 'ocupa' => 1],
    ['tipo' => 'M', 'codgas' => null, 'nro' => null, 'movimiento_id' => 1, 'monto' => 100.0, 'mes' => '2026-10', 'ocupa' => 1],
]];
$can = cobroFull(2, 50.0, '2026-10-01'); $can['cancelado'] = 1;
$filas = ConciliacionCobrosGuardado::filasDesdeGrupos([$gCan], ['2|1' => cobroFull(1, 100.0, '2026-10-01'), '2|2' => $can], [1 => d(1, 11, 100.0)], []);
check('cancelado: monto_mov y diferencia sin el cobro cancelado', $filas[0]['monto_mov'] === 100.0 && $filas[0]['diferencia'] === 0.0 && count($filas) === 2);

// filasDesdeGrupos con mes: solo filas de los cobros de ese mes; el cobro del mes que ya no existe sale con borrado
$gMes = ['id' => 11, 'origen' => 'manual', 'estatus' => 'Conciliado manual', 'items' => [
    ['tipo' => 'C', 'codgas' => 2, 'nro' => 1, 'movimiento_id' => null, 'monto' => 100.0, 'mes' => '2026-10', 'ocupa' => 1],
    ['tipo' => 'C', 'codgas' => 2, 'nro' => 2, 'movimiento_id' => null, 'monto' => 70.0, 'mes' => '2026-11', 'ocupa' => 1],
    ['tipo' => 'C', 'codgas' => 2, 'nro' => 3, 'movimiento_id' => null, 'monto' => 30.0, 'mes' => '2026-11', 'ocupa' => 1],
    ['tipo' => 'M', 'codgas' => null, 'nro' => null, 'movimiento_id' => 1, 'monto' => 200.0, 'mes' => '2026-11', 'ocupa' => 1],
]];
$filas = ConciliacionCobrosGuardado::filasDesdeGrupos([$gMes], ['2|1' => cobroFull(1, 100.0, '2026-10-30'), '2|2' => cobroFull(2, 70.0, '2026-11-01')],
    [1 => d(1, 11, 200.0, '2026-11-01')], [], '2026-11');
check('con mes: solo los cobros de noviembre', array_column($filas, 'nro') === [2, 3]);
check('con mes: la suma del grupo sigue contando el cobro de octubre', $filas[0]['monto_mov'] === 200.0 && $filas[0]['diferencia'] === 0.0);
check('con mes: el cobro que ya no existe sale borrado con su monto guardado', $filas[0]['borrado'] === false && $filas[1]['borrado'] === true && $filas[1]['monto'] === 30.0);
check('sin mes: todas las filas (como antes)', count(ConciliacionCobrosGuardado::filasDesdeGrupos([$gMes], [], [], [])) === 3);

// gruposDelMes: con cobro del mes; sin cobros, por su movimiento (o, sin movimientos, por sus facturas)
$gOct = ['id' => 20, 'items' => [
    ['tipo' => 'C', 'codgas' => 2, 'nro' => 1, 'movimiento_id' => null, 'monto' => 1.0, 'mes' => '2026-10', 'ocupa' => 1],
    ['tipo' => 'M', 'codgas' => null, 'nro' => null, 'movimiento_id' => 1, 'monto' => 1.0, 'mes' => '2026-11', 'ocupa' => 1]]];
$gDepNov = ['id' => 21, 'items' => [
    ['tipo' => 'M', 'codgas' => null, 'nro' => null, 'movimiento_id' => 2, 'monto' => 1.0, 'mes' => '2026-11', 'ocupa' => 1],
    ['tipo' => 'F', 'codgas' => 2, 'nro' => 5, 'movimiento_id' => null, 'monto' => 1.0, 'mes' => '2026-10', 'ocupa' => 1]]];
$gDepOct = ['id' => 22, 'items' => [
    ['tipo' => 'M', 'codgas' => null, 'nro' => null, 'movimiento_id' => 3, 'monto' => 1.0, 'mes' => '2026-10', 'ocupa' => 1],
    ['tipo' => 'F', 'codgas' => 2, 'nro' => 6, 'movimiento_id' => null, 'monto' => 1.0, 'mes' => '2026-11', 'ocupa' => 1]]];
$gSoloF = ['id' => 23, 'items' => [['tipo' => 'F', 'codgas' => 2, 'nro' => 7, 'movimiento_id' => null, 'monto' => 1.0, 'mes' => '2026-11', 'ocupa' => 1]]];
$gNov = ['id' => 24, 'items' => [['tipo' => 'C', 'codgas' => 2, 'nro' => 8, 'movimiento_id' => null, 'monto' => 1.0, 'mes' => '2026-11', 'ocupa' => 1]]];
check('gruposDelMes: sin el grupo de un cobro de octubre ni el depósito de octubre',
    array_column(ConciliacionCobrosGuardado::gruposDelMes([$gOct, $gDepNov, $gDepOct, $gSoloF, $gNov], '2026-11'), 'id') === [21, 23, 24]);

// cambiosReintento
$viejo = [['id' => 3, 'origen' => 'auto', 'estatus' => 'Sin banco', 'items' => [
    ['tipo' => 'C', 'codgas' => 2, 'nro' => 1, 'movimiento_id' => null, 'monto' => 800.0, 'mes' => '2026-10', 'ocupa' => 1]]]];
$nuevo = ConciliacionCobros::conciliar([c(1, null, 800.0)], [d(9100, 5, 800.0)]);
$ch = ConciliacionCobrosGuardado::cambiosReintento($viejo, $nuevo);
check('reintento: Sin banco → Cuadra por importe = 1 cambio',
    count($ch) === 1 && $ch[0]['grupo_id'] === 3 && $ch[0]['nuevo']['estatus'] === 'Cuadra por importe (sin MOV)'
    && itemsTipo($ch[0]['nuevo'], 'M')[0]['movimiento_id'] === 9100);
$igual = [['id' => 4, 'origen' => 'auto', 'estatus' => 'Cuadra por importe (sin MOV)', 'items' => [
    ['tipo' => 'C', 'codgas' => 2, 'nro' => 1, 'movimiento_id' => null, 'monto' => 800.0, 'mes' => '2026-10', 'ocupa' => 1],
    ['tipo' => 'M', 'codgas' => null, 'nro' => null, 'movimiento_id' => 9100, 'monto' => 800.0, 'mes' => '2026-10', 'ocupa' => 1]]]];
check('reintento: mismo estatus y depósito = 0 cambios', count(ConciliacionCobrosGuardado::cambiosReintento($igual, $nuevo)) === 0);

// bolsaReintento: sin los fijos; los depósitos actuales de los pendientes regresan aunque sean fijos (referencia de una Diferencia)
$bolsa = ConciliacionCobrosGuardado::bolsaReintento(
    [d(1, 11, 100.0), d(2, 12, 200.0), d(3, 13, 300.0)],
    [d(3, 13, 300.0), d(4, 14, 400.0)],
    [2 => true, 4 => true]
);
$idsBolsa = array_map(fn($x) => (int)$x['id'], $bolsa);
sort($idsBolsa);
check('bolsaReintento: quita fijos, regresa actuales sin duplicar', $idsBolsa === [1, 3, 4]);
check('bolsaReintento: sin fijos ni actuales = depósitos', count(ConciliacionCobrosGuardado::bolsaReintento([d(1, 11, 1.0)], [], [])) === 1);

// gruposReintentables
$gC = fn(int $id, string $estatus, array $nros, ?int $mov = null) => ['id' => $id, 'origen' => 'auto', 'estatus' => $estatus, 'items' => array_merge(
    array_map(fn($n) => ['tipo' => 'C', 'codgas' => 2, 'nro' => $n, 'movimiento_id' => null, 'monto' => 1.0, 'mes' => '2026-10', 'ocupa' => 1], $nros),
    $mov === null ? [] : [['tipo' => 'M', 'codgas' => null, 'nro' => null, 'movimiento_id' => $mov, 'monto' => 1.0, 'mes' => '2026-10', 'ocupa' => 0]]
)];
$pend = [$gC(1, 'Sin banco', [10]), $gC(2, 'Sin banco', [20, 21]), $gC(3, 'Sin banco', [30]), $gC(4, 'Diferencia', [40], 7), $gC(5, 'Sin banco', [50])];
$cobrosK = [
    '2|10' => ['cancelado' => 0], '2|20' => ['cancelado' => 0], '2|21' => ['cancelado' => 1],
    '2|30' => ['cancelado' => 0], '2|40' => ['cancelado' => 0], '2|50' => ['cancelado' => 0],
];
$cruceR = [
    ['codgas' => 2, 'nro' => 10, 'estatus' => 'Cuadra por importe (sin MOV)', 'deposito_id' => 8],
    ['codgas' => 2, 'nro' => 20, 'estatus' => 'Cuadra por importe (sin MOV)', 'deposito_id' => 9],
    ['codgas' => 2, 'nro' => 21, 'estatus' => 'Cancelado', 'deposito_id' => null],
    ['codgas' => 2, 'nro' => 30, 'estatus' => 'Cuadra por importe (sin MOV)', 'deposito_id' => 7],
    ['codgas' => 2, 'nro' => 40, 'estatus' => 'Diferencia', 'deposito_id' => 7],
];
unset($cobrosK['2|50']);   // el cobro 50 ya no existe
$ok = array_map(fn($g) => $g['id'], ConciliacionCobrosGuardado::gruposReintentables($pend, $cruceR, $cobrosK, [7 => true]));
check('gruposReintentables: solo el 1 y la Diferencia sobre un fijo', $ok === [1, 4]);

// gruposLibres: nuevos que no chocan con grupos activos ni movimientos ocupados
$activos = [$gC(1, 'Cuadra', [10])];
$nuevosG = [
    ['origen' => 'auto', 'estatus' => 'Sin banco', 'items' => [['tipo' => 'C', 'codgas' => 2, 'nro' => 10, 'movimiento_id' => null, 'monto' => 1.0, 'mes' => '2026-10', 'ocupa' => 1]]],
    ['origen' => 'auto', 'estatus' => 'Cuadra', 'items' => [
        ['tipo' => 'C', 'codgas' => 2, 'nro' => 11, 'movimiento_id' => null, 'monto' => 1.0, 'mes' => '2026-10', 'ocupa' => 1],
        ['tipo' => 'M', 'codgas' => null, 'nro' => null, 'movimiento_id' => 5, 'monto' => 1.0, 'mes' => '2026-10', 'ocupa' => 1]]],
    ['origen' => 'auto', 'estatus' => 'Diferencia', 'items' => [
        ['tipo' => 'C', 'codgas' => 2, 'nro' => 12, 'movimiento_id' => null, 'monto' => 1.0, 'mes' => '2026-10', 'ocupa' => 1],
        ['tipo' => 'M', 'codgas' => null, 'nro' => null, 'movimiento_id' => 5, 'monto' => 1.0, 'mes' => '2026-10', 'ocupa' => 0]]],
    ['origen' => 'auto', 'estatus' => 'Sin banco', 'items' => [['tipo' => 'C', 'codgas' => 2, 'nro' => 13, 'movimiento_id' => null, 'monto' => 1.0, 'mes' => '2026-10', 'ocupa' => 1]]],
];
$libres = ConciliacionCobrosGuardado::gruposLibres($nuevosG, $activos, [5 => true]);
check('gruposLibres: quita cobro ya agrupado y M ocupado; ocupa 0 no choca',
    array_map(fn($g) => $g['items'][0]['nro'], $libres) === [12, 13]);

// planReintento: dos cobros del mismo MOV guardados como 2 "Sin banco"; llega el depósito → 1 grupo nuevo, 2 bajas
$cobrosDup = [c(1, 500, 100.0), c(2, 500, 200.0)];
$g0 = ConciliacionCobrosGuardado::gruposDesdeCruce(ConciliacionCobros::conciliar($cobrosDup, []), '2026-10');
foreach ($g0 as $i => &$gg) $gg['id'] = $i + 1;
unset($gg);
$plan = ConciliacionCobrosGuardado::planReintento($g0, $cobrosDup, [d(9, 500, 300.0)], [], [], '2026-10');
$comps = $plan['componentes'];
$n0 = $comps[0]['nuevos'][0] ?? ['items' => [], 'estatus' => ''];
check('planReintento: un solo componente con las 2 bajas', count($comps) === 1 && $comps[0]['bajas'] === [1, 2]);
check('planReintento: un grupo Cuadra con los 2 cobros y un M 9 ocupa 1',
    count($comps[0]['nuevos']) === 1 && $n0['estatus'] === 'Cuadra' && count(itemsTipo($n0, 'C')) === 2
    && count(itemsTipo($n0, 'M')) === 1 && itemsTipo($n0, 'M')[0]['movimiento_id'] === 9 && itemsTipo($n0, 'M')[0]['ocupa'] === 1);
$sinCambio = ConciliacionCobrosGuardado::planReintento($g0, $cobrosDup, [], [], [], '2026-10');
check('planReintento: sin depósitos nuevos = sin componentes', $sinCambio['componentes'] === []);

// planReintento: el depósito de un pendiente que no se rehace (cobro cancelado) queda fijo
$cCan = c(10, null, 300.0); $cCan['cancelado'] = 1;
$pendFijo = [
    ['id' => 1, 'origen' => 'auto', 'estatus' => 'Cuadra por importe (sin MOV)', 'items' => [
        ['tipo' => 'C', 'codgas' => 2, 'nro' => 10, 'movimiento_id' => null, 'monto' => 300.0, 'mes' => '2026-10', 'ocupa' => 1],
        ['tipo' => 'M', 'codgas' => null, 'nro' => null, 'movimiento_id' => 7, 'monto' => 300.0, 'mes' => '2026-10', 'ocupa' => 1]]],
    ['id' => 2, 'origen' => 'auto', 'estatus' => 'Sin banco', 'items' => [
        ['tipo' => 'C', 'codgas' => 2, 'nro' => 11, 'movimiento_id' => null, 'monto' => 300.0, 'mes' => '2026-10', 'ocupa' => 1]]],
];
$plan = ConciliacionCobrosGuardado::planReintento($pendFijo, [$cCan, c(11, 999, 300.0)], [d(7, 70, 300.0)], [7 => d(7, 70, 300.0)], [], '2026-10');
$sinFijo = ConciliacionCobros::conciliar([c(11, 999, 300.0)], [d(7, 70, 300.0)]);
check('control: sin la regla, el cobro 11 sí tomaría el depósito 7', $sinFijo[0]['deposito_id'] === 7);
check('planReintento: el cobro 11 no toma el depósito del grupo que no se rehace', $plan['componentes'] === []);

// componentesSinConflicto
$comp = fn(array $bajas, array $movs, array $viejosM = []) => ['bajas' => $bajas, 'viejosM' => $viejosM, 'nuevos' => [['origen' => 'auto', 'estatus' => 'Cuadra', 'items' => array_map(
    fn($m) => ['tipo' => 'M', 'codgas' => null, 'nro' => null, 'movimiento_id' => $m, 'monto' => 1.0, 'mes' => '2026-10', 'ocupa' => 1], $movs)]]];
$r = ConciliacionCobrosGuardado::componentesSinConflicto([$comp([1], [9]), $comp([2], [9]), $comp([3], [4])], []);
check('componentesSinConflicto: el mismo M en dos componentes tira los dos', array_column($r, 'bajas') === [[3]]);
$r = ConciliacionCobrosGuardado::componentesSinConflicto([$comp([1], [5], [7]), $comp([2], [7]), $comp([3], [8])], [5 => true]);
check('componentesSinConflicto: M ocupado tira el componente y su depósito viejo sigue ocupado (cascada)', array_column($r, 'bajas') === [[3]]);

finish();
