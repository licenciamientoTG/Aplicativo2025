<?php
// tools/test_petrotal_generar_json.php
// Verificación dirigida para Task 12 (generar_json / enviar_reporte):
// ejercita el MISMO camino de datos que usará el controlador
// generar_json() (construir_reporte + armar_json) sin necesitar un
// servidor HTTP corriendo ni sesión real. NO arranca ningún servidor.
$_SERVER['DOCUMENT_ROOT'] = __DIR__ . '/..';
$_SERVER['REQUEST_URI'] = '/';
chdir($_SERVER['DOCUMENT_ROOT']);
require $_SERVER['DOCUMENT_ROOT'] . '/_assets/classes/header.class.php';
require $_SERVER['DOCUMENT_ROOT'] . '/_assets/classes/php_functions.php';
spl_autoload_register(function ($class) {
    if (file_exists(CLASSES . $class . '.class.php')) require CLASSES . $class . '.class.php';
    if (file_exists(MODELS . $class . '.php')) require MODELS . $class . '.php';
});

$fallos = 0;

$model = new PetrotalObligacionModel();
$desde = '2026-08-21';
$hasta = '2026-08-27';

echo "--- construir_reporte($desde, $hasta) ---\n";
$reporte = $model->construir_reporte($desde, $hasta);
echo "Ventas: " . count($reporte['ventas']) . " filas\n";
echo "Compras: " . count($reporte['compras']) . " filas\n";
echo "Advertencias: " . count($reporte['advertencias']) . "\n";

$ok = count($reporte['ventas']) > 0 && count($reporte['compras']) > 0;
echo ($ok ? "OK  " : "FAIL") . " construir_reporte devuelve ventas y compras no vacías\n";
if (!$ok) $fallos++;

echo "\n--- PetrotalCneClient::armar_json (mismo llamado que generar_json()) ---\n";
$json = PetrotalCneClient::armar_json('H/22730/COM/2019', $desde, $hasta, $reporte['ventas'], $reporte['compras']);

$okJson = is_string($json) && strlen($json) > 0;
echo ($okJson ? "OK  " : "FAIL") . " armar_json devuelve un string no vacío (" . strlen($json) . " bytes)\n";
if (!$okJson) $fallos++;

$decoded = json_decode($json, true);
$okDecode = $decoded !== null && json_last_error() === JSON_ERROR_NONE;
echo ($okDecode ? "OK  " : "FAIL") . " El JSON generado es válido (json_decode sin error)\n";
if (!$okDecode) $fallos++;

$okPermiso = ($decoded['Permiso']['Numero'] ?? null) === 'H/22730/COM/2019';
echo ($okPermiso ? "OK  " : "FAIL") . " Permiso.Numero == 'H/22730/COM/2019'\n";
if (!$okPermiso) $fallos++;

// Igual que hará generar_json(): escribir el JSON a un archivo temporal
// bajo _assets/uploads/petrotal/json/ para confirmar que el directorio es
// escribible y el patrón de nombre funciona (limpiamos al final).
$nombreArchivo = "{$desde}_{$hasta}_TEST12.json";
$rutaJson = ROOT . '_assets' . DS . 'uploads' . DS . 'petrotal' . DS . 'json' . DS . $nombreArchivo;
$bytesEscritos = file_put_contents($rutaJson, $json);
$okEscritura = $bytesEscritos !== false && file_exists($rutaJson);
echo ($okEscritura ? "OK  " : "FAIL") . " Escritura de archivo JSON en $rutaJson ($bytesEscritos bytes)\n";
if (!$okEscritura) $fallos++;
if ($okEscritura) {
    unlink($rutaJson);
    echo "    (archivo de prueba eliminado)\n";
}

echo "\n--- JSON generado (primeros 2000 caracteres) ---\n";
echo substr($json, 0, 2000) . "\n";

echo "\n" . ($fallos === 0 ? "Todos los casos pasaron.\n" : "$fallos caso(s) fallaron.\n");
