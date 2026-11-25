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

check('con facturas manda la empresa del pago', NotasPago::empresaFinal(true, 5, 1) === 5);
check('sin facturas manda la de las notas', NotasPago::empresaFinal(false, 5, 1) === 1);
check('sin facturas y notas sin empresa usa el filtro', NotasPago::empresaFinal(false, 5, null) === 5);
check('sin nada -> null', NotasPago::empresaFinal(false, null, null) === null);
check('conflicto de empresa', NotasPago::empresaEnConflicto(5, 1) === true);
check('sin conflicto igual', NotasPago::empresaEnConflicto(1, 1) === false);
check('sin conflicto con nulo', NotasPago::empresaEnConflicto(null, 1) === false);
check('id repetido', NotasPago::idRepetido([3, 4, '3']) === 3);
check('sin ids repetidos', NotasPago::idRepetido([3, 4]) === null);
check('saldo cubre nota', NotasPago::saldoCubreNota(100.0, 100.0) === true);
check('saldo insuficiente', NotasPago::saldoCubreNota(0.0, 100.0) === false);

finish();
