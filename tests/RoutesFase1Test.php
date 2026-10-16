<?php
declare(strict_types=1);

/**
 * Rutas de la Fase 1 (routes/*.php reales):  php tests/RoutesFase1Test.php
 *
 * Cada fila: [nombre, verbos, URL nueva, handler, args, parámetros de reverse, alias de ejemplo o null]
 */
require __DIR__ . '/support.php';
require __DIR__ . '/../_assets/classes/Router.class.php';

Router::load(require __DIR__ . '/../routes.php');

$rows = [
    // xml: la URL no cambia (generatexml no tiene '_'), sin alias
    ['xml.generatexml', ['GET'], 'xml/generatexml', ['Xml', 'generatexml'], [], [], null],

    // tableros: '/tableros' es el alias de index (tableros.js hacía pushState a esa URL)
    ['tableros.index', ['GET'], 'tableros/index', ['Tableros', 'index'], [], [], 'tableros/'],
    ['tableros.manage', ['GET'], 'tableros/manage', ['Tableros', 'manage'], [], [], null],
    ['tableros.manage_workspaces', ['GET'], 'tableros/manage-workspaces', ['Tableros', 'manage_workspaces'], [], [], 'TABLEROS/Manage_Workspaces'],
    ['tableros.board', ['GET'], 'tableros/board/15', ['Tableros', 'board'], ['15'], ['id' => '15'], null],

    // humancapital: sin ruta para procesarEmpleado ni parseFecha (D11)
    ['humancapital.employees', ['GET'], 'humancapital/employees', ['HumanCapital', 'employees'], [], [], null],
    ['humancapital.datatables_employees', ['POST'], 'humancapital/datatables-employees', ['HumanCapital', 'datatables_employees'], [], [], 'humancapital/datatables_employees'],
    ['humancapital.import_file_reporte_empleados', ['POST'], 'humancapital/import-file-reporte-empleados', ['HumanCapital', 'import_file_reporte_empleados'], [], [], 'humancapital/import_file_reporte_empleados'],

    // marketing: todos los parámetros <str:x> (tarjetas y códigos pueden traer letras o ceros a la izquierda)
    ['marketing.client_cards', ['GET'], 'marketing/client-cards', ['Marketing', 'client_cards'], [], [], 'Marketing/Client_Cards/'],
    ['marketing.client_cards_nexus', ['GET'], 'marketing/client-cards-nexus', ['Marketing', 'client_cards_nexus'], [], [], 'marketing/client_cards_nexus'],
    ['marketing.mqg_cards', ['GET', 'POST'], 'marketing/mqg-cards', ['Marketing', 'mqg_cards'], [], [], 'marketing/mqg_cards'],
    ['marketing.findClientForm', ['POST'], 'marketing/find-client-form', ['Marketing', 'findClientForm'], [], [], 'marketing/findClientForm'],
    ['marketing.findClientTable', ['POST'], 'marketing/find-client-table', ['Marketing', 'findClientTable'], [], [], 'marketing/findClientTable'],
    ['marketing.stickerModal', ['POST'], 'marketing/sticker-modal', ['Marketing', 'stickerModal'], [], [], 'marketing/stickerModal'],
    ['marketing.print_card', ['GET'], 'marketing/print-card/ABC123/77', ['Marketing', 'print_card'], ['ABC123', '77'], ['card' => 'ABC123', 'codcli' => '77'], 'marketing/print_card/ABC123/77'],
    ['marketing.print_card_2', ['GET'], 'marketing/print-card-2/ABC123/77', ['Marketing', 'print_card_2'], ['ABC123', '77'], ['card' => 'ABC123', 'codcli' => '77'], 'marketing/print_card_2/ABC123/77'],
    ['marketing.print_uber_card', ['GET'], 'marketing/print-uber-card/ABC123/77', ['Marketing', 'print_uber_card'], ['ABC123', '77'], ['card' => 'ABC123', 'codcli' => '77'], 'marketing/print_uber_card/ABC123/77'],
    ['marketing.print_sticker_2', ['GET'], 'marketing/print-sticker-2/ABC123/77', ['Marketing', 'print_sticker_2'], ['ABC123', '77'], ['card' => 'ABC123', 'codcli' => '77'], 'marketing/print_sticker_2/ABC123/77'],
    ['marketing.print_driver_card', ['GET'], 'marketing/print-driver-card/77/3', ['Marketing', 'print_driver_card'], ['77', '3'], ['codcli' => '77', 'nrocho' => '3'], 'marketing/print_driver_card/77/3'],
    ['marketing.print_card_nexus', ['GET'], 'marketing/print-card-nexus', ['Marketing', 'print_card_nexus'], [], [], 'marketing/print_card_nexus'],
    ['marketing.print_sticker', ['GET'], 'marketing/print-sticker/station/1/2', ['Marketing', 'print_sticker'], ['station', '1', '2'], ['station' => 'station', 'from' => '1', 'until' => '2'], 'marketing/print_sticker/station/1/2/'],
];

// Review Focus 3: la ruta ya no pasa los parámetros a minúsculas (el fallback viejo sí lo hacía)
check('marketing: el alias entrega el parámetro tal cual (sin strtolower)',
    (Router::match('marketing/print_card/ABC123/77', 'GET')['args'] ?? null) === ['ABC123', '77']);

check_routes($rows);

check('lint limpio', Router::lint() === []);

finish();
