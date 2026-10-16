<?php
declare(strict_types=1);

/**
 * Rutas de la Fase 2 (routes/*.php reales):  php tests/RoutesFase2Test.php
 *
 * Formato de fila: ver check_routes() en support.php.
 */
require __DIR__ . '/support.php';
require __DIR__ . '/../_assets/classes/Router.class.php';

Router::load(require __DIR__ . '/../routes.php');

$rows = [
    // petrotal: ids <int:id> (el controlador castea con (int))
    ['petrotal.volumetricos', ['GET'], 'petrotal/volumetricos', ['Petrotal', 'volumetricos'], [], [], null],
    ['petrotal.preview_json', ['GET'], 'petrotal/preview-json', ['Petrotal', 'preview_json'], [], [], 'petrotal/preview_json'],
    ['petrotal.generar_json', ['POST'], 'petrotal/generar-json', ['Petrotal', 'generar_json'], [], [], 'petrotal/generar_json'],
    ['petrotal.enviar_reporte', ['POST'], 'petrotal/enviar-reporte', ['Petrotal', 'enviar_reporte'], [], [], 'petrotal/enviar_reporte'],
    ['petrotal.configuracion_fiel', ['GET'], 'petrotal/configuracion-fiel', ['Petrotal', 'configuracion_fiel'], [], [], 'petrotal/configuracion_fiel'],
    ['petrotal.guardar_fiel', ['POST'], 'petrotal/guardar-fiel', ['Petrotal', 'guardar_fiel'], [], [], 'petrotal/guardar_fiel'],
    ['petrotal.historial', ['GET'], 'petrotal/historial', ['Petrotal', 'historial'], [], [], null],
    ['petrotal.descargar_json', ['GET'], 'petrotal/descargar-json/15', ['Petrotal', 'descargar_json'], [15], ['id' => 15], 'petrotal/descargar_json/15'],
    ['petrotal.descargar_acuse', ['GET'], 'petrotal/descargar-acuse/15', ['Petrotal', 'descargar_acuse'], [15], ['id' => 15], 'Petrotal/Descargar_Acuse/15/'],
];

check_routes($rows);

check('lint limpio', Router::lint() === []);

finish();
