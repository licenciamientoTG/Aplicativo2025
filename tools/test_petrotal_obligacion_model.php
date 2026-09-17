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

echo "\n--- resolver_cliente con desambiguación por Destino ---\n";
$casosClienteDestino = [
    ['ECU0602287R6', 'ESTACION PLUTARCO PL/2060/EXP/ES/2015', 'PL/2060/EXP/ES/2015'],
    ['ECU0602287R6', null, null],
    ['DGA930823KD3', 'ESTACION TECNOLOGICO PL/9444/EXP/ES/2015', 'PL/9444/EXP/ES/2015'],
    ['GVA9709154V2', 'algo irrelevante', 'PL/5114/EXP/ES/2015'],
];
foreach ($casosClienteDestino as [$rfc, $destino, $esperado]) {
    $resultado = $model->resolver_cliente($rfc, $destino);
    $obtenido = $resultado['permiso_cre'] ?? null;
    $ok = $obtenido === $esperado;
    $destinoTxt = $destino === null ? 'null' : "'$destino'";
    echo ($ok ? "OK  " : "FAIL") . " resolver_cliente('$rfc', $destinoTxt) => " . json_encode($resultado) . " (esperado permiso_cre: " . json_encode($esperado) . ")\n";
    if (!$ok) $fallos++;
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

echo "\n--- construir_reporte (semana 21-27 ago 2026, comparar contra acuse real) ---\n";
$reporte = $model->construir_reporte('2026-08-21', '2026-08-27');
echo "Ventas: " . count($reporte['ventas']) . " filas\n";
echo "Compras: " . count($reporte['compras']) . " filas\n";
echo "Advertencias: " . count($reporte['advertencias']) . "\n";
foreach ($reporte['advertencias'] as $a) echo "  [{$a['tipo']}] {$a['mensaje']}\n";

$sumaRegularVentas = array_sum(array_map(fn($v) => $v['producto_label'] === 'Regular' ? $v['volumen_bbl'] : 0, $reporte['ventas']));
echo "Suma Regular ventas: $sumaRegularVentas (acuse semana 21-27ago declaró 1460.73 bbl)\n";

echo $fallos === 0 ? "\nTodos los casos pasaron.\n" : "\n$fallos caso(s) fallaron.\n";
