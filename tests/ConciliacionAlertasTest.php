<?php
declare(strict_types=1);

/**
 * Pruebas de las alertas de la conciliación de cobros:  php tests/ConciliacionAlertasTest.php
 */
require __DIR__ . '/support.php';
require __DIR__ . '/../_assets/classes/ConciliacionCobrosGuardado.class.php';
require __DIR__ . '/../_assets/classes/ConciliacionAlertas.class.php';

function ic(int $nro, float $monto, int $codgas = 2, string $mes = '2026-10'): array {
    return ['tipo' => 'C', 'codgas' => $codgas, 'nro' => $nro, 'movimiento_id' => null, 'monto' => $monto, 'mes' => $mes, 'ocupa' => 1];
}
function im(int $id, float $monto, int $ocupa = 1, string $mes = '2026-10'): array {
    return ['tipo' => 'M', 'codgas' => null, 'nro' => null, 'movimiento_id' => $id, 'monto' => $monto, 'mes' => $mes, 'ocupa' => $ocupa];
}
function iff(int $codgas, int $nro, float $monto): array {
    return ['tipo' => 'F', 'codgas' => $codgas, 'nro' => $nro, 'movimiento_id' => null, 'monto' => $monto, 'mes' => '2026-10', 'ocupa' => 1];
}
function fc(int $nro, float $monto, ?int $mov = null, string $fecha = '2026-10-07', string $cliente = 'ACME SA DE CV', int $cancelado = 0, int $codgas = 2): array {
    return ['codgas' => $codgas, 'nro' => $nro, 'fecha' => $fecha, 'codopr' => 1, 'cliente' => $cliente, 'monto' => $monto, 'mov' => $mov, 'cancelado' => $cancelado];
}
function fm(int $id, float $abono, int $sec = 100, string $fecha = '2026-10-07', string $cuenta = '111', string $nombre = 'ACME SA DE CV'): array {
    return ['id' => $id, 'cuenta' => $cuenta, 'fecha' => $fecha, 'secuencia' => $sec, 'abono' => $abono, 'nombre_contraparte' => $nombre, 'rfc_contraparte' => 'XAXX010101000'];
}
function ctx(array $over = []): array {
    return array_merge(['cobros' => [], 'movs' => [], 'facturas' => [], 'otrosGrupos' => [], 'cuentasEstacion' => [], 'mes' => '2026-10'], $over);
}
function tiene(array $alertas, string $texto): bool {
    foreach ($alertas as $a) if (str_contains($a, $texto)) return true;
    return false;
}

$ok = ['origen' => 'manual', 'estatus' => 'Manual', 'items' => [ic(1, 1000.00), ic(2, 1383.05), im(9, 2383.05)]];
$ctxOk = ctx(['cobros' => ['2|1' => fc(1, 1000.00, 100), '2|2' => fc(2, 1383.05, 100)], 'movs' => [9 => fm(9, 2383.05)]]);
check('grupo cuadrado -> []', ConciliacionAlertas::deGrupo($ok, $ctxOk) === []);

// No cuadra
$g = ['origen' => 'manual', 'estatus' => 'Manual', 'items' => [ic(1, 1000.00), im(9, 2383.05)]];
$a = ConciliacionAlertas::deGrupo($g, ctx(['cobros' => ['2|1' => fc(1, 1000.00, 100)], 'movs' => [9 => fm(9, 2383.05)]]));
check('no cuadra con signo y miles', tiene($a, 'No cuadra: diferencia $1,383.05'));
$a = ConciliacionAlertas::deGrupo($g, ctx(['cobros' => ['2|1' => fc(1, 3000.00, 100)], 'movs' => [9 => fm(9, 2383.05)]]));
check('no cuadra negativa', tiene($a, 'No cuadra: diferencia $-616.95'));

// Diferencia usa el M ocupa=0 y no se alerta por faltar banco en auto
$gd = ['origen' => 'auto', 'estatus' => 'Diferencia', 'items' => [ic(1, 1000.00), im(9, 900.00, 0)]];
$a = ConciliacionAlertas::deGrupo($gd, ctx(['cobros' => ['2|1' => fc(1, 1000.00, 100)], 'movs' => [9 => fm(9, 900.00)]]));
check('diferencia auto: no cuadra', tiene($a, 'No cuadra: diferencia $-100.00'));
$gs = ['origen' => 'auto', 'estatus' => 'Sin banco', 'items' => [ic(1, 1000.00)]];
check('auto sin banco: sin alertas', ConciliacionAlertas::deGrupo($gs, ctx(['cobros' => ['2|1' => fc(1, 1000.00)]])) === []);

// MOV capturado distinto (64100 MOV 23289, elegido 23196)
$g = ['origen' => 'manual', 'estatus' => 'Manual', 'items' => [ic(64100, 500.00), im(5, 500.00)]];
$a = ConciliacionAlertas::deGrupo($g, ctx(['cobros' => ['2|64100' => fc(64100, 500.00, 23289)], 'movs' => [5 => fm(5, 500.00, 23196)]]));
check('MOV capturado no elegido', tiene($a, 'El MOV capturado 23289 del cobro 64100 no es ninguno de los movimientos elegidos'));

// Días
$a = ConciliacionAlertas::deGrupo($g, ctx(['cobros' => ['2|64100' => fc(64100, 500.00, 23196, '2026-10-01')], 'movs' => [5 => fm(5, 500.00, 23196, '2026-10-09')]]));
check('8 días', tiene($a, '8 días entre el cobro 64100 y el movimiento 23196'));
$a = ConciliacionAlertas::deGrupo($g, ctx(['cobros' => ['2|64100' => fc(64100, 500.00, 23196, '2026-10-06')], 'movs' => [5 => fm(5, 500.00, 23196, '2026-10-09')]]));
check('3 días no alerta', !tiene($a, 'días entre'));

// Ordenante
$a = ConciliacionAlertas::deGrupo($g, ctx(['cobros' => ['2|64100' => fc(64100, 500.00, 23196, '2026-10-07', 'TRANSPORTES DEL NORTE')], 'movs' => [5 => fm(5, 500.00, 23196, '2026-10-07', '111', 'JUAN PEREZ')]]));
check('ordenante distinto', tiene($a, 'El ordenante del banco no se parece al cliente TRANSPORTES DEL NORTE'));
$a = ConciliacionAlertas::deGrupo($g, ctx(['cobros' => ['2|64100' => fc(64100, 500.00, 23196, '2026-10-07', 'Transportés del Norte')], 'movs' => [5 => fm(5, 500.00, 23196, '2026-10-07', '111', 'TRANSPORTES NORTE SA')]]));
check('ordenante parecido sin acentos', !tiene($a, 'ordenante'));
$a = ConciliacionAlertas::deGrupo($g, ctx(['cobros' => ['2|64100' => fc(64100, 500.00, 23196, '2026-10-07', 'ZZZ')], 'movs' => [5 => fm(5, 500.00, 23196, '2026-10-07', '111', '')]]));
check('contraparte vacía no alerta', !tiene($a, 'ordenante'));
// Ordenante en auto: solo "Cuadra por importe…" y "Sin MOV"
$ctxOrd = ctx(['cobros' => ['2|64100' => fc(64100, 500.00, 23196, '2026-10-07', 'TRANSPORTES DEL NORTE')], 'movs' => [5 => fm(5, 500.00, 23196, '2026-10-07', '111', 'JUAN PEREZ')]]);
$gAuto = fn(string $estatus) => ['origen' => 'auto', 'estatus' => $estatus, 'items' => [ic(64100, 500.00), im(5, 500.00)]];
check('ordenante: auto Cuadra no alerta', !tiene(ConciliacionAlertas::deGrupo($gAuto('Cuadra'), $ctxOrd), 'ordenante'));
check('ordenante: auto Cuadra (MOV compartido) no alerta', !tiene(ConciliacionAlertas::deGrupo($gAuto('Cuadra (MOV compartido)'), $ctxOrd), 'ordenante'));
check('ordenante: auto Cuadra por importe alerta', tiene(ConciliacionAlertas::deGrupo($gAuto('Cuadra por importe'), $ctxOrd), 'ordenante'));
check('ordenante: auto Cuadra por importe (sin MOV) alerta', tiene(ConciliacionAlertas::deGrupo($gAuto('Cuadra por importe (sin MOV)'), $ctxOrd), 'ordenante'));
check('ordenante: auto Sin MOV alerta', tiene(ConciliacionAlertas::deGrupo($gAuto('Sin MOV'), $ctxOrd), 'ordenante'));

// Otra conciliación
$a = ConciliacionAlertas::deGrupo($ok, array_merge($ctxOk, ['otrosGrupos' => ['C|2|1' => ['id' => 7, 'origen' => 'auto'], 'M|9' => ['id' => 7, 'origen' => 'auto'], 'C|2|2' => ['id' => 8, 'origen' => 'manual']]]));
check('otra conciliación una vez por grupo', tiene($a, 'Ya estaba en otra conciliación (auto #7)') && tiene($a, '(manual #8)')
    && count(array_filter($a, fn($x) => str_contains($x, '#7'))) === 1);

// Otro mes
$a = ConciliacionAlertas::deGrupo($ok, array_merge($ctxOk, ['movs' => [9 => fm(9, 2383.05, 100, '2026-09-30')], 'cobros' => ['2|1' => fc(1, 1000.00, 100, '2026-11-01'), '2|2' => fc(2, 1383.05, 100)]]));
check('movimiento de otro mes', tiene($a, 'Movimiento de otro mes (2026-09)'));
check('cobro de otro mes', tiene($a, 'Cobro de otro mes (2026-11)'));

// Cuentas
$g2 = ['origen' => 'manual', 'estatus' => 'Manual', 'items' => [ic(1, 300.00), im(5, 100.00), im(6, 200.00)]];
$a = ConciliacionAlertas::deGrupo($g2, ctx(['cobros' => ['2|1' => fc(1, 300.00, 100)], 'movs' => [5 => fm(5, 100.00, 100, '2026-10-07', '111'), 6 => fm(6, 200.00, 100, '2026-10-07', '222')],
    'cuentasEstacion' => [2 => ['111' => 4]]]));
check('cuentas distintas', tiene($a, 'Movimientos de cuentas distintas'));
check('cuenta no habitual', tiene($a, 'Cuenta 222 no habitual para la estación') && !tiene($a, 'Cuenta 111 no habitual'));
$a = ConciliacionAlertas::deGrupo($g2, ctx(['cobros' => ['2|1' => fc(1, 300.00, 100)], 'movs' => [5 => fm(5, 100.00), 6 => fm(6, 200.00)]]));
check('sin historial de cuentas no alerta', !tiene($a, 'no habitual'));

// Facturas: grupo 23673 sin cobros con F I104614
$gf = ['origen' => 'manual', 'estatus' => 'Manual', 'items' => [im(9, 1000.00), iff(2, 104614, 900.00)]];
$ff = ['folio' => 'I104614', 'total' => 900.00, 'cancelada' => 0, 'codopr' => 1, 'cliente' => 'ACME', 'pagada' => 0.0];
$a = ConciliacionAlertas::deGrupo($gf, ctx(['movs' => [9 => fm(9, 1000.00)], 'facturas' => ['2|104614' => $ff]]));
check('factura sin cobros: no "Sin cobros ni facturas"', !tiene($a, 'Sin cobros ni facturas'));
check('factura sin cobros: no cuadra', tiene($a, 'No cuadra: diferencia $100.00'));
$gf9 = ['origen' => 'manual', 'estatus' => 'Manual', 'items' => [im(9, 900.00), iff(2, 104614, 900.00)]];
$a = ConciliacionAlertas::deGrupo($gf9, ctx(['movs' => [9 => fm(9, 900.00)], 'facturas' => ['2|104614' => $ff]]));
check('factura cuadra', $a === []);
$a = ConciliacionAlertas::deGrupo($gf, ctx(['movs' => [9 => fm(9, 900.00)], 'facturas' => ['2|104614' => array_merge($ff, ['cancelada' => 1, 'pagada' => 450.5])]]));
check('factura cancelada y pagada', tiene($a, 'Factura I104614 cancelada') && tiene($a, 'Factura I104614 ya pagada por cobros ($450.50)'));
$gfc = ['origen' => 'manual', 'estatus' => 'Manual', 'items' => [ic(1, 900.00), im(9, 900.00), iff(2, 104614, 900.00)]];
$a = ConciliacionAlertas::deGrupo($gfc, ctx(['cobros' => ['2|1' => fc(1, 900.00, 100, '2026-10-07', 'OTRO CLIENTE')], 'movs' => [9 => fm(9, 900.00, 100, '2026-10-07', '111', 'OTRO CLIENTE')],
    'facturas' => ['2|104614' => array_merge($ff, ['cliente' => 'ACME'])]]));
check('factura de otro cliente', tiene($a, 'Factura I104614 de otro cliente'));

// Cambió después de conciliar
$g = ['origen' => 'manual', 'estatus' => 'Manual', 'items' => [ic(64221, 500.00), im(5, 500.00)]];
$a = ConciliacionAlertas::deGrupo($g, ctx(['cobros' => ['2|64221' => fc(64221, 500.00, 100, '2026-10-07', 'ACME SA DE CV', 1)], 'movs' => [5 => fm(5, 500.00)]]));
check('cobro cancelado después', tiene($a, 'Cambió después de conciliar: cobro 64221 cancelado'));
$gCan = ['origen' => 'auto', 'estatus' => 'Cancelado', 'items' => [ic(64221, 500.00)]];
$a = ConciliacionAlertas::deGrupo($gCan, ctx(['cobros' => ['2|64221' => fc(64221, 500.00, 100, '2026-10-07', 'ACME SA DE CV', 1)]]));
check('grupo Cancelado: cobro cancelado no alerta', !tiene($a, 'cancelado'));
$a = ConciliacionAlertas::deGrupo($g, ctx(['cobros' => ['2|64221' => null], 'movs' => [5 => fm(5, 500.00)]]));
check('cobro ya no existe', tiene($a, 'Cambió después de conciliar: cobro 64221 ya no existe'));
$a = ConciliacionAlertas::deGrupo($g, ctx(['cobros' => ['2|64221' => fc(64221, 450.00, 100)], 'movs' => [5 => fm(5, 500.00)]]));
check('monto del cobro cambió', tiene($a, 'Cambió después de conciliar: monto del cobro 64221 era $500.00, hoy $450.00'));
$a = ConciliacionAlertas::deGrupo($g, ctx(['cobros' => ['2|64221' => fc(64221, 500.00, 100)], 'movs' => [5 => null]]));
check('movimiento ya no existe', tiene($a, 'Cambió después de conciliar: movimiento 5 ya no existe'));
$a = ConciliacionAlertas::deGrupo($g, ctx(['cobros' => ['2|64221' => fc(64221, 500.00, 100)], 'movs' => [5 => fm(5, 480.00)]]));
check('monto del movimiento cambió', tiene($a, 'Cambió después de conciliar: monto del movimiento 5 era $500.00, hoy $480.00'));
$a = ConciliacionAlertas::deGrupo($gf, ctx(['movs' => [9 => fm(9, 1000.00)], 'facturas' => ['2|104614' => null]]));
check('factura ya no existe', tiene($a, 'Cambió después de conciliar: factura 2|104614 ya no existe'));

// Vacíos
$a = ConciliacionAlertas::deGrupo(['origen' => 'manual', 'estatus' => 'Manual', 'items' => [im(9, 10.00)]], ctx(['movs' => [9 => fm(9, 10.00)]]));
check('sin cobros ni facturas', tiene($a, 'Sin cobros ni facturas'));
$a = ConciliacionAlertas::deGrupo(['origen' => 'manual', 'estatus' => 'Manual', 'items' => [ic(1, 10.00)]], ctx(['cobros' => ['2|1' => fc(1, 10.00)]]));
check('manual sin movimiento', tiene($a, 'Sin movimiento bancario') && !tiene($a, 'No cuadra'));

// Mismo nro en dos estaciones: cada cobro se revisa por su clave (codgas|nro), no se pisan
$g = ['origen' => 'manual', 'estatus' => 'Manual', 'items' => [ic(5, 100.00, 1), ic(5, 200.00, 2), im(9, 300.00)]];
$a = ConciliacionAlertas::deGrupo($g, ctx(['cobros' => ['1|5' => fc(5, 100.00, 999, codgas: 1), '2|5' => fc(5, 200.00, 100, codgas: 2)],
    'movs' => [9 => fm(9, 300.00, 100)]]));
check('mismo nro en dos estaciones: MOV del cobro de la estación 1 revisado', tiene($a, 'El MOV capturado 999 del cobro 5'));
check('mismo nro en dos estaciones: suma los dos cobros (cuadra)', !tiene($a, 'No cuadra'));

finish();
