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

echo $fallos === 0 ? "\nTodos los casos pasaron.\n" : "\n$fallos caso(s) fallaron.\n";
