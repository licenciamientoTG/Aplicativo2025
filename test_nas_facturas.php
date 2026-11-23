<?php
/**
 * TEMPORAL (plan migracion facturas a NAS, fase 0.4). BORRAR despues de la prueba.
 * Comprueba que IIS (pool TG_PHP) puede leer y escribir en
 * \\192.168.0.15\FacturasProveedores\combustible\ y que AttachmentsPath resuelve ahi.
 * Solo con sesion iniciada en el aplicativo. No muestra contenido de archivos.
 */
session_start();
header('Content-Type: text/plain; charset=utf-8');
if (!isset($_SESSION['tg_user'])) {
    http_response_code(403);
    exit("Inicia sesion en el aplicativo primero.\n");
}
require_once __DIR__ . '/_assets/classes/common/AttachmentsPath.php';

$raiz = AttachmentsPath::NAS_ROOT;              // \\192.168.0.15\FacturasProveedores\
$comb = $raiz . 'combustible';
$si = fn($b) => $b ? 'SI' : 'NO';

echo "Prueba NAS facturas  " . date('Y-m-d H:i:s') . "\n";
echo "Servidor: " . php_uname('n') . "   PHP " . PHP_VERSION . "\n";
$quien = function_exists('shell_exec') ? trim((string)@shell_exec('whoami')) : '';
echo "Identidad del proceso: " . ($quien !== '' ? $quien : '(whoami no disponible) ' . getenv('USERNAME')) . "\n\n";

$t = microtime(true);
$okRaiz = @is_dir($raiz);
printf("1. Raiz %s accesible: %s  (%.2f s)\n", $raiz, $si($okRaiz), microtime(true) - $t);
$okComb = @is_dir($comb);
echo "2. $comb existe: " . $si($okComb) . "\n";
if (!$okComb) {
    $e = error_get_last();
    exit("\nNo se puede seguir. Ultimo error PHP: " . ($e['message'] ?? 'ninguno') . "\n");
}
echo "   Legible: " . $si(is_readable($comb)) . "   Escribible (is_writable): " . $si(is_writable($comb)) . "\n";

$lista = @scandir($comb);
echo "3. Listar combustible\\: " . ($lista === false ? 'FALLA' : 'OK, ' . (count($lista) - 2) . ' entradas: ' . implode(', ', array_slice(array_diff($lista, ['.', '..']), 0, 15))) . "\n";

// 4. Escritura real: carpeta de prueba, .tmp -> rename, leer, resolver, borrar
$dir = $comb . '\\_prueba_iis';
$nombre = 'prueba_' . date('Ymd_His') . '.pdf';
$final = $dir . '\\' . $nombre;
$contenido = 'prueba IIS ' . uniqid();
$pasos = [];
$pasos['crear carpeta'] = is_dir($dir) || @mkdir($dir);
$pasos['escribir .tmp'] = $pasos['crear carpeta'] && @file_put_contents($final . '.tmp', $contenido) !== false;
$pasos['rename .tmp -> final'] = $pasos['escribir .tmp'] && @rename($final . '.tmp', $final);
$pasos['leer de vuelta'] = $pasos['rename .tmp -> final'] && @file_get_contents($final) === $contenido;
$res = $pasos['leer de vuelta'] ? AttachmentsPath::resolver('combustible\\_prueba_iis\\' . $nombre) : null;
$pasos['AttachmentsPath::resolver(combustible\\...)'] = $res !== null;
$pasos['borrar archivo'] = $pasos['rename .tmp -> final'] && @unlink($final);
$pasos['borrar carpeta'] = $pasos['crear carpeta'] && @rmdir($dir);
echo "4. Escritura (necesaria para la fase 4):\n";
foreach ($pasos as $k => $v) {
    echo "   - $k: " . ($v ? 'OK' : 'FALLA') . "\n";
}
if ($res !== null) {
    echo "   resolver devolvio: $res\n";
}
$e = error_get_last();
if ($e && in_array(false, $pasos, true)) {
    echo "   Ultimo error PHP: {$e['message']}\n";
}

echo "\nListo. Borra este archivo del servidor al terminar.\n";
