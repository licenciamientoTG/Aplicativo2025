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

    // arqueo: ids <int:x> (el controlador castea con (int)); '/arqueo' es el alias de index (menú)
    ['arqueo.index', ['GET'], 'arqueo/index', ['Arqueo', 'index'], [], [], 'Arqueo/'],
    ['arqueo.cajas', ['GET'], 'arqueo/cajas/7', ['Arqueo', 'cajas'], [7], ['sesion_id' => 7], null],
    ['arqueo.asignar_caja', ['POST'], 'arqueo/asignar-caja', ['Arqueo', 'asignar_caja'], [], [], 'arqueo/asignar_caja'],
    ['arqueo.crear_sesion', ['POST'], 'arqueo/crear-sesion', ['Arqueo', 'crear_sesion'], [], [], 'arqueo/crear_sesion'],
    ['arqueo.abrir', ['POST'], 'arqueo/abrir/7', ['Arqueo', 'abrir'], [7], ['sesion_id' => 7], null],
    ['arqueo.cerrar', ['POST'], 'arqueo/cerrar/7', ['Arqueo', 'cerrar'], [7], ['sesion_id' => 7], null],
    ['arqueo.caja', ['GET'], 'arqueo/caja/9', ['Arqueo', 'caja'], [9], ['caja_id' => 9], null],
    ['arqueo.imprimir_caja', ['GET'], 'arqueo/imprimir-caja/9', ['Arqueo', 'imprimir_caja'], [9], ['caja_id' => 9], 'arqueo/imprimir_caja/9'],
    ['arqueo.guardar_caja', ['POST'], 'arqueo/guardar-caja/9', ['Arqueo', 'guardar_caja'], [9], ['caja_id' => 9], 'arqueo/guardar_caja/9'],
    ['arqueo.concentrado', ['GET'], 'arqueo/concentrado/7', ['Arqueo', 'concentrado'], [7], ['sesion_id' => 7], null],
    ['arqueo.auditoria', ['GET'], 'arqueo/auditoria/7', ['Arqueo', 'auditoria'], [7], ['sesion_id' => 7], null],
    ['arqueo.guardar_concentrado_extra', ['POST'], 'arqueo/guardar-concentrado-extra', ['Arqueo', 'guardar_concentrado_extra'], [], [], 'arqueo/guardar_concentrado_extra'],
    ['arqueo.subir_imagen_concentrado', ['POST'], 'arqueo/subir-imagen-concentrado', ['Arqueo', 'subir_imagen_concentrado'], [], [], 'arqueo/subir_imagen_concentrado'],
    ['arqueo.imagenes_concentrado', ['GET'], 'arqueo/imagenes-concentrado', ['Arqueo', 'imagenes_concentrado'], [], [], 'arqueo/imagenes_concentrado'],
    ['arqueo.ver_imagen_concentrado', ['GET'], 'arqueo/ver-imagen-concentrado/3', ['Arqueo', 'ver_imagen_concentrado'], [3], ['imagen_id' => 3], 'arqueo/ver_imagen_concentrado/3'],
    ['arqueo.eliminar_imagen_concentrado', ['POST'], 'arqueo/eliminar-imagen-concentrado', ['Arqueo', 'eliminar_imagen_concentrado'], [], [], 'arqueo/eliminar_imagen_concentrado'],
    ['arqueo.exportar_cajas', ['GET'], 'arqueo/exportar-cajas/7', ['Arqueo', 'exportar_cajas'], [7], ['sesion_id' => 7], 'arqueo/exportar_cajas/7'],
];

check_routes($rows);

check('lint limpio', Router::lint() === []);

finish();
