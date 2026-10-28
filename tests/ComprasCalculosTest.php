<?php
declare(strict_types=1);

/**
 * Pruebas de las columnas calculadas del registro de compras contra renglones
 * reales de COMPRAS 2026.xlsx (valores que calculó el propio Excel). Sin base de datos:
 *
 *     php tests/ComprasCalculosTest.php
 */
require __DIR__ . '/support.php';
require __DIR__ . '/../_assets/classes/ComprasCalculos.class.php';

function renglon(array $o): array
{
    return $o + [
        'fecha' => '2026-09-01', 'fecha_descarga' => '2026-09-01', 'producto' => 'REGULAR', 'litros' => 0.0, 'monto' => 0.0,
        'iva' => 0.16, 'zona' => '1', 'proveedor_final' => 'MGC', 'monto_pagado' => null, 'precio_venta' => null,
        'factura_petrotal' => null, 'monto_petrotal' => null, 'iva_cliente_final' => null, 'en_controlgas' => true,
    ];
}

function cerca($a, float $b, float $tol): bool
{
    return $a !== null && abs((float)$a - $b) <= $tol;
}

// Zona
check('zona: Aguascalientes es Foranea', ComprasCalculos::zona('aguascalientes', 2) === 'Foranea');
check('zona: Villa Ahumada es Foranea', ComprasCalculos::zona('ahumada', 1) === 'Foranea');
check('zona: Juarez lleva su número', ComprasCalculos::zona('Juarez', 3) === '3');
check('zona: Praxedis no es foránea', ComprasCalculos::zona('Praxedis', 4) === '4');
check('zona: sin número queda vacía', ComprasCalculos::zona('Juarez', null) === '');
check('zona: sin ciudad es null', ComprasCalculos::zona(null, 1) === null);

// Foráneo, IVA 16%, MGC regular (fila del Excel, 2026-09-01, estación 14946)
$r = ComprasCalculos::calcular(renglon(['zona' => 'Foranea', 'litros' => 9997.0, 'monto' => 218314.45, 'precio_venta' => 23.88]));
check('foraneo: estímulo 0', $r['estimulo'] === 0.0);
check('foraneo: IEPS regular 2026', $r['ieps'] === 0.59139);
check('foraneo: utilidad por litro = (U-Q-X)/1.16', cerca($r['utilidad_litro'], 1.7603479, 0.0001));
check('foraneo: utilidad factura', cerca($r['utilidad_factura'], 17598.2, 0.5));
check('foraneo: precio c/IVA 8% y IEPS', cerca($r['precio_c_iva8_ieps'], 20.3727132, 0.0001));
check('foraneo: monto sin IVA', cerca($r['monto_sin_iva'], 183105.45, 0.5));
check('foraneo: mes y año', $r['mes'] === 'SEPTIEMBRE' && $r['anio'] === 2026);

// Juárez zona 3, IVA 16%, estímulo regular 3.41, usa el precio al 8%
$r = ComprasCalculos::calcular(renglon(['zona' => '3', 'litros' => 19791.0, 'monto' => 415120.38, 'precio_venta' => 19.39, 'proveedor_final' => 'PREMIER GAS']));
check('juarez16: estímulo regular 3.41', $r['estimulo'] === 3.41);
check('juarez16: utilidad por litro', cerca($r['utilidad_litro'], 3.2438618, 0.0001));
check('juarez16: utilidad factura', cerca($r['utilidad_factura'], 64199.27, 0.5));

// Juárez, IVA 8%, super (estímulo 2.86)
$r = ComprasCalculos::calcular(renglon(['zona' => '1', 'producto' => 'SUPER', 'iva' => 0.08, 'litros' => 14623.0, 'monto' => 358332.44, 'precio_venta' => 23.45, 'proveedor_final' => 'TESORO']));
check('juarez8: estímulo super 2.86', $r['estimulo'] === 2.86);
check('juarez8: IEPS super', $r['ieps'] === 0.721605);
check('juarez8: utilidad por litro = (U-Q-X)/1.08+V', cerca($r['utilidad_litro'], 1.8834125, 0.0001));

// Petrotal diésel IVA 8%: almacenamiento 0.148887585 y sin estímulo (zona 4, diésel no tiene)
$r = ComprasCalculos::calcular(renglon(['zona' => '4', 'producto' => 'DIESEL', 'iva' => 0.08, 'litros' => 26830.92, 'monto' => 646484.56, 'precio_venta' => 25.39, 'proveedor_final' => 'PETROTAL']));
check('petrotal: costo almacenamiento diésel', $r['costo_almacen'] === 0.148887585);
check('petrotal: estímulo diésel 0', $r['estimulo'] === 0.0);
check('petrotal: utilidad por litro', cerca($r['utilidad_litro'], 1.061438, 0.0001));
check('petrotal: IEPS diésel', $r['ieps'] === 0.490817);

// Sin precio de venta no hay utilidad; con monto 0 la utilidad es 0
$r = ComprasCalculos::calcular(renglon(['litros' => 1000.0, 'monto' => 20000.0]));
check('sin precio de venta: utilidad null', $r['utilidad_litro'] === null);
$r = ComprasCalculos::calcular(renglon(['litros' => 1000.0, 'monto' => 0.0]));
check('monto 0: utilidad 0', $r['utilidad_litro'] === 0.0);

// Litros: facturados / pendientes / ControlGas
$r = ComprasCalculos::calcular(renglon(['litros' => 1000.0, 'monto' => 20000.0, 'factura_petrotal' => 'GASOMEX', 'en_controlgas' => false]));
check('litros: facturados = soporte si hay F/Factura Petrotal', $r['litros_facturados'] === 1000.0 && $r['litros_pend_facturar'] === 0.0);
check('litros: pendientes de ControlGas', $r['litros_controlgas'] === 0.0 && $r['litros_pend_controlgas'] === 1000.0);

// Petrotal: BF y BG
$r = ComprasCalculos::calcular(renglon(['litros' => 1000.0, 'monto' => 23200.0, 'monto_petrotal' => 24000.0, 'iva_cliente_final' => 0.16]));
$ieppsTotal = 0.59139 * 1000.0;
check('petrotal: monto Petrotal sin IVA', cerca($r['monto_petrotal_sin_iva'], (24000.0 - $ieppsTotal) / 1.16, 0.01));
check('petrotal: utilidad = AH - BE', cerca($r['utilidad_petrotal'], 24000.0 - (23200.0 - $ieppsTotal) / 1.16, 0.01));

finish();
