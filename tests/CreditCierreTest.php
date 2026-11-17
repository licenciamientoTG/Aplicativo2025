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

// Movimientos con fecha atrasada: la fila guarda lo acumulado hasta su día (acum_*);
// al día siguiente lo histórico de hoy (hist_*) contra ese acumulado da el ajuste retro.
$calcA = array_merge($calc, ['hist_desp'=>10000.0,'hist_cortes'=>9000.0,'hist_abonos'=>4000.0,
                             'acum_desp'=>10300.0,'acum_cortes'=>9280.0,'acum_abonos'=>4250.0]);
$s = CreditCierre::armarFila($calcA, null);
check('siembra: ajustes retro NULL', $s['ajuste_retro_desp'] === null && $s['ajuste_retro_cortes'] === null);
check('siembra: guarda acumulados del dia', $s['acum_desp'] == 10300.0 && $s['acum_cortes'] == 9280.0 && $s['acum_abonos'] == 4250.0);

$antA = array_merge($ant, ['acum_desp'=>10000.0,'acum_cortes'=>9000.0,'acum_abonos'=>4000.0]);
$sinRetro = CreditCierre::armarFila($calcA, $antA);
check('sin atrasados: ajustes en 0', $sinRetro['ajuste_retro_desp'] == 0.0 && $sinRetro['ajuste_retro_cortes'] == 0.0);
check('sin atrasados: ini = fin anterior', $sinRetro['saldo_ini_calc_desp'] == 900.0 && $sinRetro['saldo_ini_calc_cortes'] == 910.0);

// Cobro atrasado de 500 (hist_abonos 4500 vs 4000 que conocía la foto anterior)
$cobroTarde = CreditCierre::armarFila(array_merge($calcA, ['hist_abonos'=>4500.0,'acum_abonos'=>4750.0]), $antA);
check('cobro atrasado: ajuste desp = -500', $cobroTarde['ajuste_retro_desp'] == -500.0);
check('cobro atrasado: ajuste cortes = -500', $cobroTarde['ajuste_retro_cortes'] == -500.0);
check('cobro atrasado: ini desp = 900 - 500', $cobroTarde['saldo_ini_calc_desp'] == 400.0);
check('cobro atrasado: fin desp = 400+300-250', $cobroTarde['saldo_fin_calc_desp'] == 450.0);

// Despacho que se quitó después (-120) y corte que llegó tarde (+75)
$movTarde = CreditCierre::armarFila(array_merge($calcA, ['hist_desp'=>9880.0,'hist_cortes'=>9075.0]), $antA);
check('despacho quitado: ajuste desp = -120', $movTarde['ajuste_retro_desp'] == -120.0);
check('corte tarde: ajuste cortes = +75', $movTarde['ajuste_retro_cortes'] == 75.0);
check('ajuste desp no toca cortes', $movTarde['saldo_ini_calc_cortes'] == 985.0 && $movTarde['saldo_ini_calc_desp'] == 780.0);

// Fila anterior sin acumulados (antes del cambio): no se puede comparar, sin ajuste
$sinAcum = CreditCierre::armarFila(array_merge($calcA, ['hist_abonos'=>99999.0]), $ant);
check('anterior sin acum: ajuste NULL', $sinAcum['ajuste_retro_desp'] === null && $sinAcum['ajuste_retro_cortes'] === null);
check('anterior sin acum: ini = fin anterior', $sinAcum['saldo_ini_calc_desp'] == 900.0);
check('anterior sin acum: empieza a guardar acum', $sinAcum['acum_abonos'] == 4250.0);

// Diferencias de redondeo bajo medio centavo no son ajuste
$ruido = CreditCierre::armarFila(array_merge($calcA, ['hist_desp'=>10000.004]), $antA);
check('ruido < 0.005: ajuste 0', $ruido['ajuste_retro_desp'] == 0.0);

check('ajuste retro => cambio', CreditCierre::hayCambio($sinRetro, $cobroTarde) === true);
check('acum distinto => cambio', CreditCierre::hayCambio($sinRetro, array_merge($sinRetro, ['acum_abonos'=>1.0])) === true);
$diaA = CreditCierre::filaParaDia($cobroTarde + ['fecha_desde'=>'2026-11-05','fecha_hasta'=>null], '2026-11-07');
check('dia en medio: ajustes retro en 0', $diaA['ajuste_retro_desp'] == 0 && $diaA['ajuste_retro_cortes'] == 0);
finish();
