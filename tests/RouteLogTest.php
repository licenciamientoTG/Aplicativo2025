<?php
declare(strict_types=1);

/**
 * Pruebas de RouteLog:  php tests/RouteLogTest.php
 */
require __DIR__ . '/support.php';
require __DIR__ . '/../_assets/classes/RouteLog.class.php';

$file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'routelog_test_' . getmypid() . '.log';
@unlink($file);

RouteLog::write($file, 'fallback', 'get', 'income/list', '2026-10-16 09:00:00');
RouteLog::write($file, 'fallback', 'GET', '/income/list', '2026-10-16 09:05:00');
RouteLog::write($file, 'alias', 'POST', 'supply/show_pay/15', '2026-10-16 09:10:00');

$lines = file($file, FILE_IGNORE_NEW_LINES);
check('escribe una línea por petición', count($lines) === 3);
check('formato fecha/kind/VERBO/path', $lines[0] === "2026-10-16 09:00:00\tfallback\tGET\t/income/list");

$s = RouteLog::summarize($file);
check('summarize agrupa por kind+verbo+path', count($s) === 2);
check('summarize ordena por conteo', $s[0]['path'] === '/income/list' && $s[0]['count'] === 2);
check('summarize guarda la última fecha', $s[0]['last'] === '2026-10-16 09:05:00');
check('summarize archivo inexistente => []', RouteLog::summarize($file . '.no') === []);

// Review Focus 5: tabuladores / saltos de línea no pueden fabricar líneas falsas
@unlink($file);
RouteLog::write($file, 'fallback', 'GET', "x\t\nfalso\talias\tGET\t/otra", '2026-10-16 10:00:00');
$lines = file($file, FILE_IGNORE_NEW_LINES);
check('inyección: sigue siendo una sola línea', count($lines) === 1);
check('inyección: cuatro columnas exactas', count(explode("\t", $lines[0])) === 4);

// Path enorme se trunca
@unlink($file);
RouteLog::write($file, 'fallback', 'GET', str_repeat('a', 5000), '2026-10-16 10:00:00');
check('path largo se trunca', strlen(explode("\t", file($file, FILE_IGNORE_NEW_LINES)[0])[3]) <= 301);

// Review Focus 5: carpeta inexistente / sin permiso no rompe la petición
$threw = false;
try {
    RouteLog::write(sys_get_temp_dir() . '/no_existe_' . getmypid() . '/x.log', 'fallback', 'GET', 'a');
} catch (Throwable) {
    $threw = true;
}
check('carpeta inexistente no lanza excepción', !$threw);

@unlink($file);
finish();
