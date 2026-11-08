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
check('estatus: Facturado de menos', est(['monto' => 1000.0, 'cortes' => 800.0, 'facturado_mes' => 500.0]) === 'Facturado de menos');
check('estatus: Facturado de más (monto 0)', est(['monto' => 0.0, 'facturado_mes' => 500.0]) === 'Facturado de más');
check('estatus: Facturado de más', est(['monto' => 1000.0, 'cortes' => 1000.0, 'facturado_mes' => 1500.0]) === 'Facturado de más');

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

finish();
