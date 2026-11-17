<?php
declare(strict_types=1);

/**
 * Pruebas de la conciliación manual de cobros (validación de items, montos frescos, totales):
 *     php tests/ConciliacionCobrosManualTest.php
 */
require __DIR__ . '/support.php';
require __DIR__ . '/../_assets/classes/ConciliacionCobrosGuardado.class.php';
require __DIR__ . '/../_assets/classes/ConciliacionCobrosManual.class.php';

// items: JSON del POST → items normalizados y sin repetidos
$it = ConciliacionCobrosManual::items(json_encode([
    ['tipo' => 'C', 'codgas' => '2', 'nro' => '64100'],
    ['tipo' => 'M', 'movimiento_id' => 55],
    ['tipo' => 'F', 'codgas' => 2, 'nro' => 1700104614, 'movimiento_id' => 9],
    ['tipo' => 'C', 'codgas' => 2, 'nro' => 64100],
]));
check('items: 3 sin el repetido', count($it) === 3);
check('items: C con enteros', $it[0] === ['tipo' => 'C', 'codgas' => 2, 'nro' => 64100, 'movimiento_id' => null]);
check('items: M sin codgas/nro', $it[1] === ['tipo' => 'M', 'codgas' => null, 'nro' => null, 'movimiento_id' => 55]);
check('items: F ignora movimiento_id', $it[2] === ['tipo' => 'F', 'codgas' => 2, 'nro' => 1700104614, 'movimiento_id' => null]);
check('items: vacío o "" → []', ConciliacionCobrosManual::items('') === [] && ConciliacionCobrosManual::items('[]') === []);
check('items: JSON inválido lanza', throws(fn() => ConciliacionCobrosManual::items('{x')));
check('items: no lista lanza', throws(fn() => ConciliacionCobrosManual::items('{"a":1}')));
check('items: tipo desconocido lanza', throws(fn() => ConciliacionCobrosManual::items('[{"tipo":"X","movimiento_id":1}]')));
check('items: M sin id lanza', throws(fn() => ConciliacionCobrosManual::items('[{"tipo":"M"}]')));
check('items: C sin nro lanza', throws(fn() => ConciliacionCobrosManual::items('[{"tipo":"C","codgas":2}]')));
check('items: elemento no objeto lanza', throws(fn() => ConciliacionCobrosManual::items('[5]')));
$muchos = [];
for ($i = 1; $i <= 301; $i++) $muchos[] = ['tipo' => 'M', 'movimiento_id' => $i];
check('items: más de 300 lanza', throws(fn() => ConciliacionCobrosManual::items(json_encode($muchos))));
check('items: 300 pasa', count(ConciliacionCobrosManual::items(json_encode(array_slice($muchos, 0, 300)))) === 300);

// claves por tipo
$cl = ConciliacionCobrosManual::claves($it);
check('claves', $cl === ['C' => ['2|64100'], 'M' => [55], 'F' => ['2|1700104614']]);

// clave de item (la misma de ConciliacionCobrosContexto::otrosGrupos)
check('claveItem C / M / F', ConciliacionCobrosManual::claveItem($it[0]) === 'C|2|64100'
    && ConciliacionCobrosManual::claveItem($it[1]) === 'M|55' && ConciliacionCobrosManual::claveItem($it[2]) === 'F|2|1700104614');

// comentario
check('comentario vacío → null', ConciliacionCobrosManual::comentario('  ') === null && ConciliacionCobrosManual::comentario(null) === null);
check('comentario recortado', ConciliacionCobrosManual::comentario(' hola ') === 'hola');
check('comentario 500 pasa', ConciliacionCobrosManual::comentario(str_repeat('ñ', 500)) === str_repeat('ñ', 500));
check('comentario 501 lanza', throws(fn() => ConciliacionCobrosManual::comentario(str_repeat('a', 501))));

// completar: monto y mes de BD, ocupa = 1; si falta alguno lanza
$cobros   = ['2|64100' => ['codgas' => 2, 'nro' => 64100, 'fecha' => '2026-10-05', 'monto' => '1500.50', 'cancelado' => 0]];
$movs     = [55 => ['id' => 55, 'fecha' => '2026-11-02', 'abono' => '1000.00']];
$facturas = ['2|1700104614' => ['codgas' => 2, 'nro' => 1700104614, 'fecha' => '2026-09-30', 'total' => '600.25', 'cancelada' => 0]];
$comp = ConciliacionCobrosManual::completar($it, $cobros, $movs, $facturas);
check('completar: C', $comp[0]['monto'] === 1500.5 && $comp[0]['mes'] === '2026-10' && $comp[0]['ocupa'] === 1);
check('completar: M', $comp[1]['monto'] === 1000.0 && $comp[1]['mes'] === '2026-11' && $comp[1]['movimiento_id'] === 55);
check('completar: F', $comp[2]['monto'] === 600.25 && $comp[2]['mes'] === '2026-09');
check('completar: falta cobro lanza', throws(fn() => ConciliacionCobrosManual::completar($it, [], $movs, $facturas)));
check('completar: falta movimiento lanza', throws(fn() => ConciliacionCobrosManual::completar($it, $cobros, [], $facturas)));
check('completar: falta factura lanza', throws(fn() => ConciliacionCobrosManual::completar($it, $cobros, $movs, [])));
try {
    ConciliacionCobrosManual::completar($it, [], [], $facturas);
    $msg = '';
} catch (InvalidArgumentException $e) {
    $msg = $e->getMessage();
}
check('completar: el error dice cuáles', str_contains($msg, 'cobro 64100') && str_contains($msg, 'movimiento 55'));

// grupo manual
$g = ConciliacionCobrosManual::grupo($comp, 'ok');
check('grupo manual', $g['origen'] === 'manual' && $g['estatus'] === 'Conciliado manual' && $g['comentario'] === 'ok' && count($g['items']) === 3);

// meses a bloquear: el del POST + los de los items elegidos + los del grupo editado, ordenados y sin repetir
$editado = ['id' => 9, 'mes' => '2026-08', 'items' => [['tipo' => 'C', 'mes' => '2026-08'], ['tipo' => 'M', 'mes' => '2026-10']]];
check('mesesABloquear con grupo editado', ConciliacionCobrosManual::mesesABloquear('2026-10', $comp, $editado) === ['2026-08', '2026-09', '2026-10', '2026-11']);
check('mesesABloquear sin grupo', ConciliacionCobrosManual::mesesABloquear('2026-10', $comp, null) === ['2026-09', '2026-10', '2026-11']);
check('mesesABloquear ignora meses inválidos', ConciliacionCobrosManual::mesesABloquear('2026-10', [['mes' => ''], ['mes' => 'x']], null) === ['2026-10']);
check('mesesABloquear sin mes del POST (deshacer)', ConciliacionCobrosManual::mesesABloquear('', $editado['items'], null) === ['2026-08', '2026-10']);

// totales: con cobros, las facturas no se restan (solo referencia); sin cobros, sí
$t = ConciliacionCobrosManual::totales($comp, $cobros, $movs, $facturas);
check('totales con cobros', $t === ['cobros' => 1500.5, 'facturas' => 600.25, 'movimientos' => 1000.0, 'diferencia' => -500.5]);
$sinC = array_values(array_filter($comp, fn($x) => $x['tipo'] !== 'C'));
$t = ConciliacionCobrosManual::totales($sinC, $cobros, $movs, $facturas);
check('totales sin cobros', $t === ['cobros' => 0.0, 'facturas' => 600.25, 'movimientos' => 1000.0, 'diferencia' => 399.75]);
$cobros['2|64100']['cancelado'] = 1;
$t = ConciliacionCobrosManual::totales($comp, $cobros, $movs, $facturas);
check('totales: cobro cancelado no suma', $t['cobros'] === 0.0 && $t['diferencia'] === 1000.0);
check('totales vacío', ConciliacionCobrosManual::totales([], [], [], []) === ['cobros' => 0.0, 'facturas' => 0.0, 'movimientos' => 0.0, 'diferencia' => 0.0]);

finish();
