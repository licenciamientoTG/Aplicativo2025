<?php
declare(strict_types=1);

/**
 * Rutas de alta de cuenta bancaria (routes/bankaccountrequests.php):  php tests/BankAccountRequestsRoutesTest.php
 *
 * Cada fila: [nombre, verbos, URL, handler, args, parámetros de reverse, alias de ejemplo o null]
 */
require __DIR__ . '/support.php';
require __DIR__ . '/../_assets/classes/Router.class.php';

Router::load(require __DIR__ . '/../routes.php');

$rows = [
    ['bankaccounts.index',    ['GET'],  'pagos-extraordinarios/alta-cuenta',            ['BankAccountRequests', 'index'],    [],     [],           null],
    ['bankaccounts.new',      ['GET'],  'pagos-extraordinarios/alta-cuenta/new',        ['BankAccountRequests', 'form'],     [],     [],           null],
    ['bankaccounts.save',     ['POST'], 'pagos-extraordinarios/alta-cuenta/save',       ['BankAccountRequests', 'save'],     [],     [],           null],
    ['bankaccounts.check',    ['GET'],  'pagos-extraordinarios/alta-cuenta/check',      ['BankAccountRequests', 'check'],    [],     [],           null],
    ['bankaccounts.decision_modal', ['GET'], 'pagos-extraordinarios/alta-cuenta/15/decision-modal', ['BankAccountRequests', 'decision_modal'], [15], ['id' => '15'], null],
    ['bankaccounts.approve',  ['POST'], 'pagos-extraordinarios/alta-cuenta/15/approve', ['BankAccountRequests', 'approve'],  [15],   ['id' => '15'], null],
    ['bankaccounts.reject',   ['POST'], 'pagos-extraordinarios/alta-cuenta/15/reject',  ['BankAccountRequests', 'reject'],   [15],   ['id' => '15'], null],
    ['bankaccounts.upload',   ['POST'], 'pagos-extraordinarios/alta-cuenta/15/upload',  ['BankAccountRequests', 'upload'],   [15],   ['id' => '15'], null],
    ['bankaccounts.caratula', ['GET'],  'pagos-extraordinarios/alta-cuenta/15/caratula', ['BankAccountRequests', 'caratula'], [15],  ['id' => '15'], null],
    ['bankaccounts.detail',   ['GET'],  'pagos-extraordinarios/alta-cuenta/15',         ['BankAccountRequests', 'detail'],   [15],   ['id' => '15'], null],
];

check_routes($rows);

check('lint limpio', Router::lint() === []);

finish();
