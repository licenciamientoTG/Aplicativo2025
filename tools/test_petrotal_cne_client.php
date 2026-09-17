<?php
// tools/test_petrotal_cne_client.php
$_SERVER['DOCUMENT_ROOT'] = __DIR__ . '/..';
$_SERVER['REQUEST_URI'] = '/';
chdir($_SERVER['DOCUMENT_ROOT']);
require $_SERVER['DOCUMENT_ROOT'] . '/_assets/classes/header.class.php';
require $_SERVER['DOCUMENT_ROOT'] . '/_assets/classes/php_functions.php';
spl_autoload_register(function ($class) {
    if (file_exists(CLASSES . $class . '.class.php')) require CLASSES . $class . '.class.php';
    if (file_exists(MODELS . $class . '.php')) require MODELS . $class . '.php';
});

$ventas = [
    [
        'fecha' => '2026-08-21', 'producto_id' => 7, 'subproducto_id' => 13,
        'permiso_cre' => 'PL/2060/EXP/ES/2015', 'volumen_bbl' => 88.88, 'precio' => 3301.47,
    ],
];
$compras = [
    [
        'fecha' => '2026-08-21', 'producto_id' => 7, 'subproducto_id' => 13,
        'permiso_cre' => 'H/19873/COM/2017', 'volumen_bbl' => 88.88, 'precio' => 3215.62,
    ],
];

$json = PetrotalCneClient::armar_json('H/22730/COM/2019', '2026-08-21', '2026-08-27', $ventas, $compras);
echo $json . "\n\n";

$decoded = json_decode($json, true);
$fallos = 0;

$ok = $decoded['Permiso']['Numero'] === 'H/22730/COM/2019';
echo ($ok ? "OK  " : "FAIL") . " Permiso.Numero correcto\n";
if (!$ok) $fallos++;

$ok2 = count($decoded['Permiso']['Fecha']) === 1 && $decoded['Permiso']['Fecha'][0]['Diaareportar'] === '2026-08-21';
echo ($ok2 ? "OK  " : "FAIL") . " Una sola fecha agrupada correctamente\n";
if (!$ok2) $fallos++;

$producto = $decoded['Permiso']['Fecha'][0]['Producto'][0];
$ok3 = $producto['ProductoId'] === 7 && $producto['SubProductoId'] === 13;
echo ($ok3 ? "OK  " : "FAIL") . " ProductoId/SubProductoId correctos\n";
if (!$ok3) $fallos++;

$ok4 = isset($producto['VentasNacional'][0]['PermisionarioCRECliente'][0]['VolumenVendido'])
    && $producto['VentasNacional'][0]['PermisionarioCRECliente'][0]['VolumenVendido'] === 88.88;
echo ($ok4 ? "OK  " : "FAIL") . " VentasNacional.PermisionarioCRECliente.VolumenVendido correcto\n";
if (!$ok4) $fallos++;

$ok5 = isset($producto['ComprasNacional'][0]['PermisionarioCREProveedor'][0]['VolumenComprado'])
    && $producto['ComprasNacional'][0]['PermisionarioCREProveedor'][0]['VolumenComprado'] === 88.88;
echo ($ok5 ? "OK  " : "FAIL") . " ComprasNacional.PermisionarioCREProveedor.VolumenComprado correcto\n";
if (!$ok5) $fallos++;

echo $fallos === 0 ? "\nTodos los casos pasaron.\n" : "\n$fallos caso(s) fallaron.\n";
