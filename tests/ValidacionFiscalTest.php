<?php
declare(strict_types=1);

/**
 * Reglas de RFC y correos (sin base de datos). Usa los mismos casos que
 * tests/validacion_fiscal_test.js para que PHP y JS no se desalineen:
 *
 *     php tests/ValidacionFiscalTest.php
 */
require __DIR__ . '/support.php';
require __DIR__ . '/../_assets/classes/ValidacionFiscal.class.php';

$casos = json_decode(file_get_contents(__DIR__ . '/fixtures/validacion_fiscal_casos.json'), true);
if (!is_array($casos) || empty($casos['rfc']) || empty($casos['correos'])) {
    fwrite(STDERR, "No se pudieron leer los casos de tests/fixtures/validacion_fiscal_casos.json\n");
    exit(1);
}

foreach ($casos['rfc'] as $c) {
    $r = ValidacionFiscal::rfc($c['valor']);
    $nombre = 'rfc ' . json_encode($c['valor'], JSON_UNESCAPED_UNICODE);
    check("{$nombre} → {$c['nivel']} (dio {$r['nivel']}: {$r['mensaje']})", $r['nivel'] === $c['nivel']);
    if (isset($c['contiene'])) {
        check("{$nombre} menciona «{$c['contiene']}»", str_contains($r['mensaje'], $c['contiene']));
    }
}

foreach ($casos['correos'] as $c) {
    $r = ValidacionFiscal::correos($c['valor']);
    $nombre = 'correos ' . json_encode($c['valor'], JSON_UNESCAPED_UNICODE);
    check("{$nombre} → {$c['nivel']} (dio {$r['nivel']}: {$r['mensaje']})", $r['nivel'] === $c['nivel']);
    if (isset($c['normalizado'])) {
        check("{$nombre} se normaliza a {$c['normalizado']}", $r['normalizado'] === $c['normalizado']);
    }
    if (isset($c['contiene'])) {
        check("{$nombre} menciona «{$c['contiene']}»", str_contains($r['mensaje'], $c['contiene']));
    }
}

foreach ($casos['regimen'] ?? [] as $c) {
    $r = ValidacionFiscal::regimen($c['valor'], $c['rfc']);
    $nombre = 'regimen ' . json_encode($c['valor'], JSON_UNESCAPED_UNICODE) . ' con RFC ' . json_encode($c['rfc']);
    check("{$nombre} → {$c['nivel']} (dio {$r['nivel']}: {$r['mensaje']})", $r['nivel'] === $c['nivel']);
    if (isset($c['contiene'])) {
        check("{$nombre} menciona «{$c['contiene']}»", str_contains($r['mensaje'], $c['contiene']));
    }
}
check('hay casos de régimen', !empty($casos['regimen']));
check('catálogo de régimen con 19 claves', count(ValidacionFiscal::REGIMENES) === 19);

$largo = implode(';', array_map(fn($i) => "correo{$i}@empresa.com.mx", range(1, 10)));
check('correos de más de 200 caracteres → error', ValidacionFiscal::correos($largo)['nivel'] === 'error');

finish();
