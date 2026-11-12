<?php
declare(strict_types=1);

/**
 * Pruebas de la lógica pura de la foto diaria de clientes crédito:
 *     php tests/CreditCierreTest.php
 * Spec: docs/superpowers/specs/2026-11-12-credit-clients-cierre-diario-design.md
 */
require __DIR__ . '/support.php';
require __DIR__ . '/../_assets/classes/DebitCierre.class.php';
require __DIR__ . '/../_assets/classes/CreditCierre.class.php';

// Cierre: cresdo sube con despachos (se restan los posteriores) y baja con abonos (se suman los posteriores)
$r = CreditCierre::reconstruirCredito(1000.0, -5000.0, 100.0, 30.0);
check('cierre sistema = lectura - desp post + abonos post', $r['saldo_fin_sistema'] == 930.0);
check('cierre vehiculos = lectura + desp post', $r['saldo_fin_vehiculos'] == -4900.0);
check('sin lectura => NULL', CreditCierre::reconstruirCredito(null, null, null, null)['saldo_fin_sistema'] === null);

$calc = ['codcli'=>7,'cliente'=>'C-X','debglo'=>0,'limite'=>2000.0,
         'cobros_dia'=>200.0,'notas_dia'=>50.0,'facturado_dia'=>800.0,
         'consumos_desp_dia'=>300.0,'consumos_cortes_dia'=>280.0,
         'lectura_cresdo'=>1000.0,'lectura_vehiculos'=>-5000.0,'consumos_post_cierre'=>100.0,'abonos_post_cierre'=>30.0,
         'lectura_at'=>'2026-11-12 08:00:00'];
$f = CreditCierre::armarFila($calc, null);
check('sin anterior: ini sistema NULL', $f['saldo_ini_sistema'] === null);
check('siembra desp: ini = fin sistema - desp + cobros + notas (930-300+250)', $f['saldo_ini_calc_desp'] == 880.0);
check('siembra cortes: ini = fin sistema - cortes + cobros + notas (930-280+250)', $f['saldo_ini_calc_cortes'] == 900.0);
check('dia de siembra: fin calc desp = fin sistema', $f['saldo_fin_calc_desp'] == 930.0);
check('dia de siembra: fin calc cortes = fin sistema', $f['saldo_fin_calc_cortes'] == 930.0);
check('desfase = desp - cortes', $f['desfase_dia'] == 20.0);
check('facturado se guarda sin mover el saldo', $f['facturado_dia'] == 800.0);
check('disponible = limite - fin sistema', $f['disponible'] == 1070.0);
check('sin limite => disponible NULL', CreditCierre::armarFila(array_merge($calc, ['limite'=>0.0]), null)['disponible'] === null);
check('conserva lecturas', $f['lectura_cresdo'] == 1000.0 && $f['abonos_post_cierre'] == 30.0 && $f['lectura_at'] === '2026-11-12 08:00:00');
check('sin lectura ni anterior => siembra en 0',
    CreditCierre::armarFila(array_merge($calc, ['lectura_cresdo'=>null,'lectura_vehiculos'=>null,'consumos_post_cierre'=>null,'abonos_post_cierre'=>null]), null)['saldo_ini_calc_desp'] == 0.0);

// Encadenado: el inicial calculado es el final calculado anterior (no el sistema)
$ant = array_merge($f, ['saldo_fin_calc_desp'=>900.0, 'saldo_fin_calc_cortes'=>910.0, 'saldo_fin_sistema'=>930.0]);
$f2 = CreditCierre::armarFila(array_merge($calc, ['lectura_cresdo'=>1500.0,'consumos_post_cierre'=>0.0,'abonos_post_cierre'=>0.0]), $ant);
check('encadenado: ini sistema = fin sistema anterior', $f2['saldo_ini_sistema'] == 930.0);
check('encadenado: ini calc desp = fin calc desp anterior', $f2['saldo_ini_calc_desp'] == 900.0);
check('encadenado: ini calc cortes = fin calc cortes anterior', $f2['saldo_ini_calc_cortes'] == 910.0);
check('encadenado: fin calc desp = 900+300-200-50', $f2['saldo_fin_calc_desp'] == 950.0);
check('encadenado: fin calc cortes = 910+280-200-50', $f2['saldo_fin_calc_cortes'] == 940.0);
check('encadenado: fin sistema no se forza al calculado', $f2['saldo_fin_sistema'] == 1500.0);

// Cambio y día en medio (heredados, con las columnas de crédito)
check('igual => sin cambio', CreditCierre::hayCambio($f2, $f2) === false);
check('cobro distinto => cambio', CreditCierre::hayCambio($f2, array_merge($f2, ['cobros_dia'=>200.01])) === true);
check('limite distinto => cambio', CreditCierre::hayCambio($f2, array_merge($f2, ['limite'=>2500.0])) === true);
check('facturado distinto => cambio', CreditCierre::hayCambio($f2, array_merge($f2, ['facturado_dia'=>0.0])) === true);
check('debito sigue sin ver columnas de credito', DebitCierre::hayCambio(['debglo'=>0], ['debglo'=>0]) === false);

$fila = $f2 + ['fecha_desde'=>'2026-11-05','fecha_hasta'=>null];
$dia = CreditCierre::filaParaDia($fila, '2026-11-07');
check('dia en medio: ini = fin', $dia['saldo_ini_calc_desp'] == 950.0 && $dia['saldo_ini_sistema'] == 1500.0 && $dia['saldo_ini_calc_cortes'] == 940.0);
check('dia en medio: movimientos de credito en 0', $dia['cobros_dia'] == 0 && $dia['notas_dia'] == 0 && $dia['facturado_dia'] == 0 && $dia['consumos_desp_dia'] == 0 && $dia['desfase_dia'] == 0);
check('dia en medio: limite y disponible se conservan', $dia['limite'] == 2000.0 && $dia['disponible'] == $fila['disponible']);
finish();
