<?php
declare(strict_types=1);

/**
 * Pruebas de la lógica pura de conciliación débito:  php tests/ConciliacionDebitoTest.php
 */
require __DIR__ . '/support.php';
require __DIR__ . '/../_assets/classes/ConciliacionDebito.class.php';

// periodoFactura
check('periodo: agosto facturado en septiembre = Mes anterior',
    ConciliacionDebito::periodoFactura('@N:Consumos Agosto 2026@t:GLOBAL', '2026-09', '2026-09-02') === 'Mes anterior');
check('periodo: septiembre facturado el 30 = Del mes',
    ConciliacionDebito::periodoFactura('@N:Consumos Septiembre 2026@t:GLOBAL', '2026-09', '2026-09-30') === 'Del mes');
check('periodo: septiembre facturado en octubre = Mes siguiente',
    ConciliacionDebito::periodoFactura('@N:Consumos Septiembre 2026@t:GLOBAL', '2026-09', '2026-10-03') === 'Mes siguiente');
check('periodo: con Diesel y doble espacio',
    ConciliacionDebito::periodoFactura('@N:Consumos Agosto  Diesel 2026@t:GLOBAL@c:7767', '2026-09', '2026-09-10') === 'Mes anterior');
check('periodo: mayúsculas',
    ConciliacionDebito::periodoFactura('@N:CONSUMOS SEPTIEMBRE 2026', '2026-09', '2026-09-10') === 'Del mes');
check('periodo: sin nota = Sin periodo',
    ConciliacionDebito::periodoFactura('Factura global sin nota', '2026-09', '2026-09-10') === 'Sin periodo');
check('periodo: diciembre 2026 facturado en enero 2027 = Mes anterior',
    ConciliacionDebito::periodoFactura('@N:Consumos Diciembre 2026', '2027-01', '2027-01-04') === 'Mes anterior');
check('periodo: enero 2027 facturado en febrero = Mes siguiente',
    ConciliacionDebito::periodoFactura('@N:Consumos Enero 2027', '2027-01', '2027-02-02') === 'Mes siguiente');
check('periodo: julio para septiembre = Sin periodo',
    ConciliacionDebito::periodoFactura('@N:Consumos Julio 2026', '2026-09', '2026-09-10') === 'Sin periodo');
check('periodo: mes anterior facturado fuera del mes = Sin periodo',
    ConciliacionDebito::periodoFactura('@N:Consumos Agosto 2026', '2026-09', '2026-10-02') === 'Sin periodo');

// facturasDeReferencia
check('referencia: D y E',
    ConciliacionDebito::facturasDeReferencia('Fac.D242940 ($3,500.00); Fac.E101872 ($26,389.99)')
    === [['nro' => 1200242940, 'importe' => 3500.0], ['nro' => 1300101872, 'importe' => 26389.99]]);
check('referencia: importes negativos',
    ConciliacionDebito::facturasDeReferencia('Fac.D1 ($-5.00); Fac.E2 (-$5.00)')
    === [['nro' => 1200000001, 'importe' => -5.0], ['nro' => 1300000002, 'importe' => -5.0]]);
check('referencia: Sin factura', ConciliacionDebito::facturasDeReferencia('Sin factura') === []);
check('referencia: serie G se omite', ConciliacionDebito::facturasDeReferencia('Fac.G55 ($10.00)') === []);

// estatusCliente
function cli(array $o): array {
    return $o + ['clase' => 'Venta', 'monto' => 0.0, 'cortes' => 0.0, 'facturado_mes' => 0.0, 'facturado_siguiente' => 0.0, 'cancelada' => 0.0];
}
function est(array $o): string { return ConciliacionDebito::estatusCliente(cli($o)); }

check('estatus: No se factura (consumo propio)',
    est(['clase' => 'Consumo propio', 'monto' => 100.0, 'cortes' => 100.0]) === 'No se factura');
check('estatus: No se factura (bonificación)',
    est(['clase' => 'Bonificación', 'monto' => 100.0, 'facturado_mes' => 100.0]) === 'No se factura');
check('estatus: Facturado exacto', est(['monto' => 5000.0, 'cortes' => 5000.0, 'facturado_mes' => 5000.0]) === 'Facturado');
check('estatus: Facturado con 0.005 de diferencia', est(['monto' => 100.005, 'facturado_mes' => 100.0]) === 'Facturado');
check('estatus: Redondeo 100 vs 99.60', est(['monto' => 100.0, 'cortes' => 100.0, 'facturado_mes' => 99.60]) === 'Redondeo');
check('estatus: Redondeo en el límite 0.01', est(['monto' => 100.01, 'facturado_mes' => 100.0]) === 'Redondeo');
check('estatus: dif 1.00 ya no es Redondeo', est(['monto' => 101.0, 'cortes' => 101.0, 'facturado_mes' => 100.0]) === 'Facturado de menos');
check('estatus: Se factura al mes siguiente',
    est(['monto' => 5000.0, 'cortes' => 5000.0, 'facturado_mes' => 2000.0, 'facturado_siguiente' => 3000.0]) === 'Se factura al mes siguiente');
check('estatus: Factura cancelada',
    est(['monto' => 33000.0, 'cortes' => 33000.0, 'cancelada' => 33000.0]) === 'Factura cancelada');
check('estatus: Facturado con base en cortes',
    est(['monto' => 10600.0, 'cortes' => 10536.85, 'facturado_mes' => 10536.85]) === 'Facturado con base en cortes');
check('estatus: Consumo sin corte', est(['monto' => 6644.18]) === 'Consumo sin corte');
check('estatus: Consumo sin factura', est(['monto' => 20471.30, 'cortes' => 20471.30]) === 'Consumo sin factura');
check('estatus: monto 0 con cortes y sin factura = Consumo sin factura', est(['monto' => 0.0, 'cortes' => 300.0]) === 'Consumo sin factura');
check('estatus: monto 0.5, cortes 100, sin facturas = Consumo sin factura (no mes siguiente)',
    est(['monto' => 0.5, 'cortes' => 100.0]) === ConciliacionDebito::EST_SIN_FACTURA);
// Orden literal: dif = 0 se evalúa como Facturado antes que cualquier otra regla
check('estatus: todo en 0 = Facturado (orden literal)', est([]) === 'Facturado');
check('estatus: monto 0.5, sin cortes ni facturas = Redondeo', est(['monto' => 0.5]) === 'Redondeo');
check('estatus: mes siguiente con fs>0', est(['monto' => 1000.0, 'cortes' => 1000.0, 'facturado_mes' => 400.0, 'facturado_siguiente' => 600.0]) === 'Se factura al mes siguiente');
check('estatus: Facturado de menos',est(['monto' => 1000.0, 'cortes' => 800.0, 'facturado_mes' => 500.0]) === 'Facturado de menos');
check('estatus: Facturado de más (monto 0)', est(['monto' => 0.0, 'facturado_mes' => 500.0]) === 'Facturado de más');
check('estatus: Facturado de más', est(['monto' => 1000.0, 'cortes' => 1000.0, 'facturado_mes' => 1500.0]) === 'Facturado de más');

// clasePorCategoria / clasificarFacturas / ventaPorCliente
check('clase: vacío = Venta', ConciliacionDebito::clasePorCategoria('') === 'Venta');
check('clase: Bonificaciones', ConciliacionDebito::clasePorCategoria('Bonificaciones') === 'Bonificación');
check('clase: BONIFICACIÓN con acento', ConciliacionDebito::clasePorCategoria('BONIFICACIÓN MKT') === 'Bonificación');
check('clase: otra categoría = Consumo propio', ConciliacionDebito::clasePorCategoria('Dollar to go') === 'Consumo propio');

function fac(string $fecha, string $txtref, float $total, int $cli, int $canc = 0, int $nro = 1): array {
    return ['fecha' => $fecha, 'txtref' => $txtref, 'total' => $total, 'codcli' => $cli, 'cliente' => "C$cli", 'cancelada' => $canc, 'nro' => $nro];
}
$facs = ConciliacionDebito::clasificarFacturas([
    fac('2026-08-31', '@N:Consumos Agosto 2026', 1, 1),                  // antes del mes: se descarta
    fac('2026-09-02', '@N:Consumos Agosto 2026@t:GLOBAL', 100, 1),       // Mes anterior
    fac('2026-09-15', '@N:Consumos Septiembre 2026', 200, 1),            // Del mes
    fac('2026-09-20', 'sin nota', 50, 1),                                // Sin periodo (cuenta del mes)
    fac('2026-09-30', '@N:CANCELAD', 300, 2, 1),                         // Cancelada
    fac('2026-10-03', '@N:Consumos Septiembre 2026', 400, 1),            // Mes siguiente
    fac('2026-10-05', '@N:Consumos Agosto 2026', 9, 1),                  // después del mes y no Mes siguiente: se descarta
    fac('2026-10-04', 'sin nota', 9, 1),                                 // se descarta
], '2026-09');
check('facturas: 5 conservadas', count($facs) === 5);
check('facturas: periodos', array_column($facs, 'periodo') === ['Mes anterior', 'Del mes', 'Sin periodo', 'Cancelada', 'Mes siguiente']);

$cons = [
    ['codcli' => 1, 'Cliente' => 'UNO', 'Despachos' => 3, 'Litros' => '10.500', 'Monto' => '250.00', 'MontoCortes' => '250.00', 'Categoria' => ''],
    ['codcli' => 3, 'Cliente' => 'TRES', 'Despachos' => 1, 'Litros' => '1.000', 'Monto' => '40.00', 'MontoCortes' => '40.00', 'Categoria' => 'Bonificaciones'],
];
$venta = ConciliacionDebito::ventaPorCliente($cons, $facs);
$por = array_column($venta, null, 'codcli');
check('venta: 3 clientes (uno solo con facturas)', count($venta) === 3);
check('venta: orden por monto desc', $venta[0]['codcli'] === 1);
check('venta: cliente 1 facturado mes 250 (Del mes + Sin periodo)', $por[1]['facturado_mes'] === 250.0);
check('venta: cliente 1 mes anterior 100', $por[1]['facturado_mes_anterior'] === 100.0);
check('venta: cliente 1 mes siguiente 400', $por[1]['facturado_siguiente'] === 400.0);
check('venta: cliente 1 facturas = 4 (sin la cancelada)', $por[1]['facturas'] === 4);
check('venta: cliente 1 Facturado', $por[1]['estatus'] === 'Facturado' && $por[1]['diferencia'] === 0.0);
check('venta: cliente 2 solo cancelada: monto 0, cancelada 300', $por[2]['monto'] === 0.0 && $por[2]['cancelada'] === 300.0 && $por[2]['facturado_mes'] === 0.0);
check('venta: bonificación = No se factura', $por[3]['clase'] === 'Bonificación' && $por[3]['estatus'] === 'No se factura');
// clientes con todo en cero (p. ej. solo una factura cancelada de $0) no se listan
$facsCero = ConciliacionDebito::clasificarFacturas([fac('2026-09-30', '@N:CANCELAD', 0, 9, 1)], '2026-09');
$consCero = [['codcli' => 8, 'Cliente' => 'OCHO', 'Despachos' => 0, 'Litros' => '0', 'Monto' => '0', 'MontoCortes' => '0', 'Categoria' => '']];
check('venta: cliente con todo en cero no se lista', ConciliacionDebito::ventaPorCliente($consCero, $facsCero) === []);

// clasificarMovimiento
$propias = ['0601500947', '1155446527', '123'];
function mov(array $o): array {
    return $o + ['id' => 1, 'abono' => 100.0, 'cargo' => 0.0, 'detalle' => '', 'contraparte' => '', 'rfc' => ''];
}
function cla(array $o, array $pre = [], array $cre = [], array $prop = []): string {
    return ConciliacionDebito::clasificarMovimiento(mov($o), $pre, $cre, $prop);
}
check('mov: Retiro', cla(['abono' => 0.0, 'cargo' => 50.0], [1]) === 'Retiro');
check('mov: Cobro prepago', cla(['id' => 7], [7], [7]) === 'Cobro prepago');
check('mov: Cobro crédito', cla(['id' => 8], [7], [8]) === 'Cobro crédito');
check('mov: Traspaso por cuenta en detalle', cla(['detalle' => 'TRASPASO A 0601500947 CTA'], [], [], $propias) === 'Traspaso entre cuentas');
check('mov: Traspaso por cuenta en contraparte', cla(['contraparte' => '1155446527'], [], [], $propias) === 'Traspaso entre cuentas');
check('mov: cuenta de 4 dígitos no cuenta', cla(['detalle' => 'REF 123 PAGO'], [], [], ['1234', '123']) === 'No identificado');
check('mov: No identificado', cla(['detalle' => 'DEPOSITO'], [], [], $propias) === 'No identificado');

// cobrosPorFactura: una fila por factura (DocumentosA solo series D/E; el vale 325938 no cuenta)
function cobroPrepago(int $nro, float $monto, string $facturas, string $txtref = ''): array {
    return ['nro' => $nro, 'fecha' => '2026-09-01', 'codopr' => 7, 'cliente' => 'D- X', 'monto' => $monto, 'facturas' => $facturas, 'txtref' => $txtref];
}
$cf = ConciliacionDebito::cobrosPorFactura([
    cobroPrepago(1, 3500.0, '1200242940:3500.00,325938:3500.00'),
    cobroPrepago(2, 9000.0, '1200000001:4000.00,1200000002:5000.00'),
    cobroPrepago(3, 8368.0, '1200089958:8368.00,277475:8368.00'),
    cobroPrepago(4, 10000.0, '304421:10000.00'),
    cobroPrepago(5, 700.0, '', '@N:Fac.D100 ($700.00)'),
], [1200242940, 1200000001, 1200000002]);
check('cobrosPorFactura: 1+2+1+1+1 filas', count($cf) === 6);
check('cobrosPorFactura: Sí contra anticipos del mes', $cf[0]['existe_en_anticipos'] === 'Sí' && $cf[0]['nro_factura'] === 1200242940 && $cf[0]['importe'] === 3500.0);
check('cobrosPorFactura: dos facturas = dos filas con su importe', $cf[1]['importe'] === 4000.0 && $cf[2]['importe'] === 5000.0 && $cf[2]['cobro'] === 2);
check('cobrosPorFactura: anticipo de otro mes = No', $cf[3]['existe_en_anticipos'] === 'No' && $cf[3]['nro_factura'] === 1200089958);
check('cobrosPorFactura: sin factura = una fila con el monto del cobro', $cf[4]['existe_en_anticipos'] === 'Sin factura en referencia' && $cf[4]['nro_factura'] === null && $cf[4]['importe'] === 10000.0);
check('cobrosPorFactura: sin DocumentosA usa la Referencia', $cf[5]['nro_factura'] === 1200000100 && $cf[5]['existe_en_anticipos'] === 'No');

// Serie E (consumos) no es un anticipo: no cuenta como 'No' ni entra en otro_mes
$cf = ConciliacionDebito::cobrosPorFactura([cobroPrepago(1, 500.0, '1300000999:500.00')], [1200000001]);
check('serie E en DocumentosA: Sin factura en referencia con el monto del cobro',
    count($cf) === 1 && $cf[0]['existe_en_anticipos'] === 'Sin factura en referencia' && $cf[0]['importe'] === 500.0 && $cf[0]['nro_factura'] === null);
$cf = ConciliacionDebito::cobrosPorFactura([cobroPrepago(2, 600.0, '', '@N:Fac.E55 ($600.00)')], [1200000001]);
check('serie E en Referencia: Sin factura en referencia', count($cf) === 1 && $cf[0]['existe_en_anticipos'] === 'Sin factura en referencia' && $cf[0]['importe'] === 600.0);
$cf = ConciliacionDebito::cobrosPorFactura([cobroPrepago(3, 900.0, '1200000001:400.00,1300000999:500.00')], [1200000001]);
check('una D y una E: solo la fila D', count($cf) === 1 && $cf[0]['nro_factura'] === 1200000001 && $cf[0]['existe_en_anticipos'] === 'Sí');
$p = ConciliacionDebito::partidasAnticipos([], ConciliacionDebito::cobrosPorFactura([cobroPrepago(1, 500.0, '1300000999:500.00')], []), 0, 500.0);
check('serie E no suma a otro_mes', $p['otro_mes']['importe'] === 0.0 && $p['sin_factura']['importe'] === 500.0);

// partidasAnticipos: cobros − anticipos = otro mes + sin factura + anticipos sin cobro + sin explicar
$ant = [
    ['Factura' => 1, 'Total' => 3500.0, 'cobro_aplicado' => 3500.0, 'estatus' => 'Con cobro'],
    ['Factura' => 2, 'Total' => 3500.0, 'cobro_aplicado' => 0.0, 'estatus' => 'Sin cobro'],
    ['Factura' => 3, 'Total' => 10000.0, 'cobro_aplicado' => 0.0, 'estatus' => 'Sin cobro'],
    ['Factura' => 4, 'Total' => 1000.0, 'cobro_aplicado' => 0.0, 'estatus' => 'Cobro en otro mes'],
];
$cfp = [
    ['cobro' => 10, 'nro_factura' => 1, 'importe' => 3500.0, 'existe_en_anticipos' => 'Sí'],
    ['cobro' => 11, 'nro_factura' => 99, 'importe' => 8368.0, 'existe_en_anticipos' => 'No'],
    ['cobro' => 12, 'nro_factura' => null, 'importe' => 10000.0, 'existe_en_anticipos' => 'Sin factura en referencia'],
];
$p = ConciliacionDebito::partidasAnticipos($ant, $cfp, 2, 21868.0);
check('partidas: anticipos 18,000 y diferencia 3,868', $p['anticipos'] === 18000.0 && $p['diferencia'] === 3868.0);
check('partidas: otro mes 8,368 (1 cobro)', $p['otro_mes']['importe'] === 8368.0 && $p['otro_mes']['n'] === 1);
check('partidas: sin factura 10,000 y sin cobro −13,500', $p['sin_factura']['importe'] === 10000.0 && $p['anticipos_sin_cobro']['importe'] === -13500.0 && $p['anticipos_sin_cobro']['n'] === 2);
check('partidas: cobrado en otro mes −1,000 (1 anticipo) aparte del sin cobro', $p['anticipos_cobrados_otro_mes']['importe'] === -1000.0 && $p['anticipos_cobrados_otro_mes']['n'] === 1);
check('partidas: sin explicar 0 y canceladas 2', $p['sin_explicar'] === 0.0 && $p['canceladas'] === 2);

// estatusCruce: estatus extra de los cobros prepago
$ec = fn(array $f) => ConciliacionDebito::estatusCruce($f + ['estatus' => 'Sin banco', 'cuenta' => null, 'fecha_banco' => null, 'txtref' => '', 'codopr' => 1], [900], '2026-09-01');
check('estatusCruce: Cancelado se respeta', $ec(['estatus' => 'Cancelado']) === 'Cancelado');
check('estatusCruce: Cuadra en el mes se respeta', $ec(['estatus' => 'Cuadra', 'cuenta' => 'X', 'fecha_banco' => '2026-09-05']) === 'Cuadra');
check('estatusCruce: depósito anterior al mes', $ec(['estatus' => 'Cuadra por importe', 'cuenta' => 'X', 'fecha_banco' => '2026-08-31']) === 'Depositado en mes anterior');
check('estatusCruce: cliente de consumo propio', $ec(['codopr' => 900]) === 'Consumo propio');
check('estatusCruce: efectivo @M:01', $ec(['txtref' => '@N:x@M:01@B:y']) === 'Efectivo');
check('estatusCruce: sin depósito = No localizado', $ec(['estatus' => 'Sin MOV']) === 'No localizado');
check('estatusCruce: Diferencia se respeta', $ec(['estatus' => 'Diferencia', 'txtref' => '@M:03']) === 'Diferencia');
check('estatusCruce: transferencia sin depósito = No localizado', $ec(['txtref' => '@M:03']) === 'No localizado');

finish();
