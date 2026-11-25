<?php
declare(strict_types=1);

/**
 * Pruebas de NotasPago (empresa única de las notas de cargo de un pago):
 *   php tests/NotasPagoTest.php
 */
require __DIR__ . '/support.php';
require __DIR__ . '/../_assets/classes/NotasPago.class.php';

check('misma empresa -> 1', NotasPago::empresaUnica([['emp_cod' => 1], ['emp_cod' => 1]]) === 1);
check('una con empresa y una nula -> 1', NotasPago::empresaUnica([['emp_cod' => 1], ['emp_cod' => null]]) === 1);
check('solo nula -> null', NotasPago::empresaUnica([['emp_cod' => null]]) === null);
check('vacío -> null', NotasPago::empresaUnica([]) === null);
check('emp_cod como string numérico -> int', NotasPago::empresaUnica([['emp_cod' => '21']]) === 21);

$msg = null;
try {
    NotasPago::empresaUnica([['emp_cod' => 1], ['emp_cod' => 21]]);
} catch (InvalidArgumentException $e) {
    $msg = $e->getMessage();
}
check('empresas distintas lanza excepción con mensaje exacto',
    $msg === 'Las notas de cargo son de empresas distintas: crea un pago por empresa');

finish();
