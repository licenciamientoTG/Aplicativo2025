<?php
// tools/test_petrotal_obligacion_model.php
$_SERVER['DOCUMENT_ROOT'] = __DIR__ . '/..';
$_SERVER['REQUEST_URI'] = '/';
chdir($_SERVER['DOCUMENT_ROOT']);
require $_SERVER['DOCUMENT_ROOT'] . '/_assets/classes/header.class.php';
require $_SERVER['DOCUMENT_ROOT'] . '/_assets/classes/php_functions.php';
spl_autoload_register(function ($class) {
    if (file_exists(CLASSES . $class . '.class.php')) require CLASSES . $class . '.class.php';
    if (file_exists(MODELS . $class . '.php')) require MODELS . $class . '.php';
});

$model = new PetrotalObligacionModel();

$casos = [
    'MAXIMA' => ['producto_id' => 7, 'subproducto_id' => 13],
    'T-SUPER PREMIUM' => ['producto_id' => 7, 'subproducto_id' => 14],
    'DIESEL' => ['producto_id' => 3, 'subproducto_id' => 62],
    'UNBRANDED REGULAR GAS H/19873/COM/2017' => ['producto_id' => 7, 'subproducto_id' => 13],
    'UNBRANDED PREMIUM GAS H/19873/COM/2017' => ['producto_id' => 7, 'subproducto_id' => 14],
    'Diésel ESSA' => ['producto_id' => 3, 'subproducto_id' => 62],
    'PEMEX MAGNA' => null,
];

$fallos = 0;
foreach ($casos as $desc => $esperado) {
    $resultado = $model->clasificar_producto($desc);
    $ok = $esperado === null
        ? $resultado === null
        : ($resultado && $resultado['producto_id'] === $esperado['producto_id'] && $resultado['subproducto_id'] === $esperado['subproducto_id']);
    echo ($ok ? "OK  " : "FAIL") . " clasificar_producto('$desc') => " . json_encode($resultado) . "\n";
    if (!$ok) $fallos++;
}

echo "\n--- resolver_cliente / resolver_proveedor (requiere BD) ---\n";
$casosCliente = [
    'ECU0602287R6' => 'multiple', // Estación Custodia, 2 permisos conocidos
    'GVA9709154V2' => 'PL/5114/EXP/ES/2015', // Villa Ahumada, único permiso
    'RFC_INEXISTENTE_XYZ' => null,
];
foreach ($casosCliente as $rfc => $esperado) {
    $resultado = $model->resolver_cliente($rfc);
    echo "resolver_cliente('$rfc') => " . json_encode($resultado) . "\n";
}

$casosProveedor = [
    'TMS1611162N5' => 'H/19873/COM/2017', // Tesoro
    'EFA1903122IA' => 'H/23183/COM/2020', // ESSA
    'RFC_INEXISTENTE_XYZ' => null,
];
foreach ($casosProveedor as $rfc => $esperado) {
    $resultado = $model->resolver_proveedor($rfc);
    $ok = $resultado === $esperado;
    echo ($ok ? "OK  " : "FAIL") . " resolver_proveedor('$rfc') => " . json_encode($resultado) . " (esperado: " . json_encode($esperado) . ")\n";
    if (!$ok) $fallos++;
}

echo "\n--- obtener_facturas_venta / obtener_facturas_compra (semana 21-27 ago 2026) ---\n";
$ventas = $model->obtener_facturas_venta('2026-08-21', '2026-08-27');
$compras = $model->obtener_facturas_compra('2026-08-21', '2026-08-27');
echo "Ventas encontradas: " . count($ventas) . " (esperado > 0)\n";
echo "Compras encontradas: " . count($compras) . " (esperado > 0)\n";
if (count($ventas) === 0 || count($compras) === 0) $fallos++;

echo $fallos === 0 ? "\nTodos los casos pasaron.\n" : "\n$fallos caso(s) fallaron.\n";
