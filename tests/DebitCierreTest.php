<?php
declare(strict_types=1);

/**
 * Pruebas de la lógica pura de la foto diaria v2 de clientes débito:
 *     php tests/DebitCierreTest.php
 * Spec: docs/superpowers/specs/2026-11-11-debit-clients-cierre-diario-design.md
 */
require __DIR__ . '/support.php';
require __DIR__ . '/../_assets/classes/DebitCierre.class.php';

$calc = ['codcli'=>1,'cliente'=>'X','debglo'=>0,'saldo_ini_calc_desp'=>1000.0,'saldo_ini_calc_cortes'=>990.0,
         'anticipos_dia'=>500.0,'consumos_desp_dia'=>300.0,'consumos_cortes_dia'=>250.0,
         'lectura_debsdo'=>700.0,'lectura_vehiculos'=>700.0,'consumos_post_cierre'=>40.0,'lectura_at'=>'2026-11-12 08:00:00'];
$f = DebitCierre::armarFila($calc, null);
check('sin anterior: ini sistema NULL', $f['saldo_ini_sistema'] === null);
check('sin anterior: ajustes NULL', $f['ajuste_retro_desp'] === null && $f['ajuste_retro_cortes'] === null);
check('desfase = desp - cortes', $f['desfase_dia'] == 50.0);
check('fin calc desp = 1000+500-300', $f['saldo_fin_calc_desp'] == 1200.0);
check('fin calc cortes = 990+500-250', $f['saldo_fin_calc_cortes'] == 1240.0);
check('fin sistema = lectura + post', $f['saldo_fin_sistema'] == 740.0);
check('fin vehiculos sin bolsa global = lectura + post', $f['saldo_fin_vehiculos'] == 740.0);
check('bolsa global: vehiculos tambien suman post (medido 2026-11-11)', DebitCierre::reconstruir(700.0, 50.0, 40.0, 1)['saldo_fin_vehiculos'] == 90.0);
check('sin lectura: fin sistema NULL', DebitCierre::reconstruir(null, null, null, 0)['saldo_fin_sistema'] === null);
check('conserva lecturas crudas', $f['lectura_debsdo'] == 700.0 && $f['consumos_post_cierre'] == 40.0 && $f['lectura_at'] === '2026-11-12 08:00:00');

$ant = $f + ['fecha_desde'=>'2026-11-10'];
$c2 = array_merge($calc, ['saldo_ini_calc_desp'=>1210.0,'saldo_ini_calc_cortes'=>1240.0]);
$f2 = DebitCierre::armarFila($c2, $ant);
check('ini sistema = fin sistema anterior', $f2['saldo_ini_sistema'] == 740.0);
check('ajuste desp = 1210 - 1200', $f2['ajuste_retro_desp'] == 10.0);
check('ajuste cortes = 0', $f2['ajuste_retro_cortes'] == 0.0);
check('anterior con fin sistema NULL => ini sistema NULL',
    DebitCierre::armarFila($c2, array_merge($ant, ['saldo_fin_sistema'=>null]))['saldo_ini_sistema'] === null);

check('igual => sin cambio', DebitCierre::hayCambio($f2, $f2) === false);
check('diferencia 0.004 => sin cambio', DebitCierre::hayCambio($f2, array_merge($f2, ['anticipos_dia'=>500.004])) === false);
check('diferencia 0.01 => cambio', DebitCierre::hayCambio($f2, array_merge($f2, ['anticipos_dia'=>500.01])) === true);
check('NULL vs numero => cambio', DebitCierre::hayCambio(array_merge($f2, ['saldo_fin_sistema'=>null]), $f2) === true);
check('NULL vs NULL => igual', DebitCierre::hayCambio(array_merge($f2, ['saldo_fin_sistema'=>null]), array_merge($f2, ['saldo_fin_sistema'=>null])) === false);
check('debglo distinto => cambio', DebitCierre::hayCambio($f2, array_merge($f2, ['debglo'=>1])) === true);
check('montos como string de la BD => sin cambio', DebitCierre::hayCambio($f2, array_map(fn($v) => is_float($v) ? number_format($v, 2, '.', '') : $v, $f2)) === false);

$fila = $f2 + ['fecha_desde'=>'2026-11-05','fecha_hasta'=>'2026-11-09'];
$dia = DebitCierre::filaParaDia($fila, '2026-11-07');
check('dia en medio: ini = fin', $dia['saldo_ini_calc_desp'] == $fila['saldo_fin_calc_desp'] && $dia['saldo_ini_sistema'] == $fila['saldo_fin_sistema']);
check('dia en medio: ini cortes = fin cortes', $dia['saldo_ini_calc_cortes'] == $fila['saldo_fin_calc_cortes']);
check('dia en medio: movimientos 0', $dia['anticipos_dia'] == 0 && $dia['consumos_desp_dia'] == 0 && $dia['consumos_cortes_dia'] == 0 && $dia['desfase_dia'] == 0);
check('dia en medio: ajustes 0', $dia['ajuste_retro_desp'] == 0 && $dia['ajuste_retro_cortes'] == 0);
check('dia en medio: saldos finales iguales', $dia['saldo_fin_calc_desp'] == $fila['saldo_fin_calc_desp'] && $dia['saldo_fin_sistema'] == $fila['saldo_fin_sistema']);
check('dia = fecha_desde: sin cambios', DebitCierre::filaParaDia($fila, '2026-11-05') == $fila);

check('pendientes: sin corridas => solo ayer', DebitCierre::diasPendientes(null, '2026-11-11') === ['2026-11-11']);
check('pendientes: ultimo = ayer => ayer (re-corrida)', DebitCierre::diasPendientes('2026-11-11', '2026-11-11') === ['2026-11-11']);
check('pendientes: hueco de 2 dias', DebitCierre::diasPendientes('2026-11-08', '2026-11-11') === ['2026-11-09','2026-11-10','2026-11-11']);
check('pendientes: cambio de mes', DebitCierre::diasPendientes('2026-10-30', '2026-11-01') === ['2026-10-31','2026-11-01']);
check('pendientes: mas de 31 => solo ayer', DebitCierre::diasPendientes('2026-09-01', '2026-11-11') === ['2026-11-11']);
finish();
