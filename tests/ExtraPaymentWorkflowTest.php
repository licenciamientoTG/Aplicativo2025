<?php
declare(strict_types=1);

/**
 * Pruebas de las reglas de flujo de pagos extraordinarios (sin BD):
 *
 *     php tests/ExtraPaymentWorkflowTest.php
 */
require __DIR__ . '/support.php';
require __DIR__ . '/../_assets/classes/ExtraPaymentWorkflow.class.php';

// Renglón completo de ejemplo
$linea = [
    'empresa_cod' => 1, 'centro_costos' => 'CC1', 'proveedor' => 'Proveedor SA',
    'descripcion' => 'Servicio', 'monto' => '100.50', 'fecha_pago_requerida' => '2026-11-01',
];
$header = ['reason_id' => 1, 'motivo_otros' => '', 'requiere_texto' => false, 'tipo' => 'individual'];

// Transiciones
check('aprobar 1 => 2', ExtraPaymentWorkflow::next_status_on_approve(1) === 2);
check('aprobar 2 => 3', ExtraPaymentWorkflow::next_status_on_approve(2) === 3);
check('aprobar 3 => null', ExtraPaymentWorkflow::next_status_on_approve(3) === null);
check('aprobar 4 => null', ExtraPaymentWorkflow::next_status_on_approve(4) === null);

// Permisos requeridos
check('permiso estado 1 = 107', ExtraPaymentWorkflow::required_permission(1) === 107);
check('permiso estado 2 = 108', ExtraPaymentWorkflow::required_permission(2) === 108);
check('permiso estado 3 = 109', ExtraPaymentWorkflow::required_permission(3) === 109);
check('permiso estado 4 = null', ExtraPaymentWorkflow::required_permission(4) === null);

// Edición y cancelación
check('can_edit(9)', ExtraPaymentWorkflow::can_edit(9) === true);
check('can_edit(1) false', ExtraPaymentWorkflow::can_edit(1) === false);
check('can_cancel(3) false', ExtraPaymentWorkflow::can_cancel(3) === false);
check('can_cancel(2)', ExtraPaymentWorkflow::can_cancel(2) === true);
check('can_cancel(1)', ExtraPaymentWorkflow::can_cancel(1) === true);
check('can_cancel(9)', ExtraPaymentWorkflow::can_cancel(9) === true);
check('can_edit(5) borrador', ExtraPaymentWorkflow::can_edit(5) === true);
check('can_cancel(5) borrador', ExtraPaymentWorkflow::can_cancel(5) === true);
check('borrador no se aprueba ni se paga', ExtraPaymentWorkflow::next_status_on_approve(5) === null && ExtraPaymentWorkflow::required_permission(5) === null);

// Validación
check('solicitud válida => []', ExtraPaymentWorkflow::validate_request($header, [$linea]) === []);
check('sin renglones => 1 error', count(ExtraPaymentWorkflow::validate_request($header, [])) === 1);
foreach ([0, -5, 'abc'] as $m) {
    $l = $linea; $l['monto'] = $m;
    check('monto ' . var_export($m, true) . ' => error', count(ExtraPaymentWorkflow::validate_request($header, [$l])) >= 1);
}
$h = $header; $h['requiere_texto'] = true;
check('requiere_texto sin motivo => error', count(ExtraPaymentWorkflow::validate_request($h, [$linea])) >= 1);
$h['motivo_otros'] = 'Detalle';
check('requiere_texto con motivo => []', ExtraPaymentWorkflow::validate_request($h, [$linea]) === []);
check('individual con 2 renglones => error', count(ExtraPaymentWorkflow::validate_request($header, [$linea, $linea])) >= 1);
$h = $header; $h['tipo'] = 'multiple';
check('multiple con 2 renglones => []', ExtraPaymentWorkflow::validate_request($h, [$linea, $linea]) === []);
foreach (['empresa_cod', 'proveedor', 'descripcion'] as $campo) {
    $l = $linea; $l[$campo] = '';
    check("renglón sin $campo => error", count(ExtraPaymentWorkflow::validate_request($header, [$l])) >= 1);
}

// Total
check('total 10.10 + 0.20 === 10.3', ExtraPaymentWorkflow::total([['monto' => '10.10'], ['monto' => '0.20']]) === 10.3);

// Total = suma de los renglones ya redondeados (encabezado == suma de lo guardado)
check('total 100.555 + 100.555 === 201.12', ExtraPaymentWorkflow::total([['monto' => '100.555'], ['monto' => '100.555']]) === 201.12);

finish();
