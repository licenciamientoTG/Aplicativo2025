<?php
declare(strict_types=1);

/**
 * Pruebas de Errors:  php tests/ErrorsTest.php
 */
require __DIR__ . '/support.php';
require __DIR__ . '/../_assets/controllers/errors.php';

$_SERVER['HTTP_X_REQUESTED_WITH'] = '';
$_SERVER['HTTP_ACCEPT'] = 'text/html';
check('wantsJson: navegador normal => false', Errors::wantsJson() === false);

$_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
check('wantsJson: XMLHttpRequest => true', Errors::wantsJson() === true);

$_SERVER['HTTP_X_REQUESTED_WITH'] = '';
$_SERVER['HTTP_ACCEPT'] = 'application/json, text/plain, */*';
check('wantsJson: Accept json => true', Errors::wantsJson() === true);

$json = json_decode(Errors::render404(true), true);
check('render404 json: trae error', ($json['error'] ?? '') === 'Ruta no encontrada');

$html = Errors::render404(false);
check('render404 html: documento completo', str_starts_with($html, '<!doctype html>') && str_contains($html, '404'));
check('render404 html: ya no usa var_dump', !str_contains($html, 'string('));

finish();
