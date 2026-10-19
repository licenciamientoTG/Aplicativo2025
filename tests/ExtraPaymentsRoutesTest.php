<?php
declare(strict_types=1);

/**
 * Rutas de pagos extraordinarios (routes/extrapayments.php):  php tests/ExtraPaymentsRoutesTest.php
 *
 * Cada fila: [nombre, verbos, URL, handler, args, parámetros de reverse, alias de ejemplo o null]
 */
require __DIR__ . '/support.php';
require __DIR__ . '/../_assets/classes/Router.class.php';

Router::load(require __DIR__ . '/../routes.php');

$rows = [
    ['extrapayments.index',  ['GET'],  'pagos-extraordinarios',          ['ExtraPayments', 'index'],  [],     [],           null],
    ['extrapayments.new',    ['GET'],  'pagos-extraordinarios/new',      ['ExtraPayments', 'form'],   [],     [],           null],
    ['extrapayments.save',   ['POST'], 'pagos-extraordinarios/save',     ['ExtraPayments', 'save'],   [],     [],           null],
    ['extrapayments.edit',   ['GET'],  'pagos-extraordinarios/15/edit',  ['ExtraPayments', 'form'],   [15], ['id' => '15'], null],
    ['extrapayments.detail', ['GET'],  'pagos-extraordinarios/15',       ['ExtraPayments', 'detail'], [15], ['id' => '15'], null],
    ['extrapayments.cancel', ['POST'], 'pagos-extraordinarios/15/cancel', ['ExtraPayments', 'cancel'], [15], ['id' => '15'], null],
];

check_routes($rows);

check('lint limpio', Router::lint() === []);

finish();
