<?php
declare(strict_types=1);

/**
 * Pruebas de la página Conciliación débito (lectura de archivos):  php tests/ConciliacionDebitoViewTest.php
 * Rutas usadas por el JS, ids de la vista, sidebar y permiso 14 en todas las rutas.
 */
require __DIR__ . '/support.php';

$raiz   = __DIR__ . '/..';
$read   = fn(string $rel) => is_file("$raiz/$rel") ? (string)file_get_contents("$raiz/$rel") : '';
$js     = $read('_assets/js/conciliation_debit.js');
$vista  = $read('views/income/conciliation_debit.html');
$rutas  = $read('routes/income.php');
$side   = $read('views/layouts/sidebar.html');

check('existe conciliation_debit.js', $js !== '');
check('existe conciliation_debit.html', $vista !== '');

// pedir('ruta') es el envoltorio de routeUrl() del JS (mismo patrón que la vista de crédito)
preg_match_all("/(?:routeUrl|pedir)\(\s*'(income\.conciliation_debit[a-z_]*)'/", $js, $m);
$usadas = array_unique($m[1]);
check('el JS usa rutas income.conciliation_debit*', count($usadas) >= 5);
foreach ($usadas as $nombre) {
    check("ruta declarada: $nombre", str_contains($rutas, "name: '$nombre'"));
}
foreach (['sales', 'consumption', 'own', 'invoices', 'pending', 'anticipos', 'anticipos_clients', 'payment_invoices'] as $k) {
    check("el JS pide income.conciliation_debit_$k", in_array("income.conciliation_debit_$k", $usadas, true));
}

foreach (['cvMes', 'cvEmpresa', 'cvFiltros', 'cvTabs', 'modalAyuda', 'tablaVenta', 'tablaApp', 'tablaPropio', 'tablaFacturas', 'tablaPendientes', 'tablaAnticipos', 'tablaAnticiposCli', 'tablaCobrosFactura'] as $id) {
    check("la vista tiene id=\"$id\"", str_contains($vista, "id=\"$id\""));
}
check('la vista tiene los 12 tabs', substr_count($vista, 'data-bs-toggle="tab" data-bs-target="#tab') >= 12);
check('el sidebar enlaza income.conciliation_debit', str_contains($side, "url('income.conciliation_debit')"));

preg_match_all("/^.*conciliation-debit\/.*$/m", $rutas, $lineas);
check('hay rutas conciliation-debit/', count($lineas[0]) >= 9);
foreach (['anticipos', 'anticipos_cli', 'cobros_factura'] as $k) {
    check("ayuda: AYUDA tiene la llave $k", str_contains($js, "$k: ['"));
    check("ayuda: la vista tiene data-ayuda=\"$k\"", str_contains($vista, "data-ayuda=\"$k\""));
}
preg_match('/var CONSULTAS = \[(.*?)\n  \];/s', $js, $mc);
$consultas = $mc[1] ?? '';
foreach (['anticipos', 'anticipos_cli', 'cobros_factura'] as $k) {
    check("CONSULTAS registra la llave '$k'", (bool)preg_match("/\\['$k',\\s*'[^']+',\\s*'#tab\\w+',\\s*'#cnt\\w+'\\]/", $consultas));
}
foreach (['anticipos', 'anticipos_clients', 'payment_invoices'] as $k) {
    check("el JS pide income.conciliation_debit_$k", str_contains($js, "income.conciliation_debit_$k"));
}

// Tabs 10-12: Conciliación cobros y bancos 2791 / 0956
foreach (['tablaCruce', 'tablaBanco2791', 'tablaBanco0956'] as $id) {
    check("la vista tiene id=\"$id\"", str_contains($vista, "id=\"$id\""));
}
foreach (['payments' => 'payments/', 'bank' => 'bank/'] as $k => $ruta) {
    $nombre = "income.conciliation_debit_$k";
    check("ruta $nombre en conciliation-debit/$ruta", (bool)preg_match("/route\\('conciliation-debit\\/" . preg_quote($ruta, '/') . "'.*name: '" . preg_quote($nombre, '/') . "'.*permission: 14/", $rutas));
    check("el JS pide $nombre", str_contains($js, $nombre));
}
foreach (['cruce', 'banco2791', 'banco0956'] as $k) {
    check("ayuda: AYUDA tiene la llave $k", str_contains($js, "$k: ['"));
    check("ayuda: la vista tiene data-ayuda=\"$k\"", str_contains($vista, "data-ayuda=\"$k\""));
}
check("CONSULTAS registra la llave 'cobros'", (bool)preg_match("/\\['cobros',\\s*'[^']+',\\s*'#tabCruce',\\s*'#cntCruce'\\]/", $consultas));
check('el JS guarda estado.cobros, banco2791 y banco0956', str_contains($js, 'banco2791: []') && str_contains($js, 'banco0956: []') && str_contains($js, 'cobros: []'));
check('el JS pinta los estatus nuevos', str_contains($js, "'Depositado en mes anterior'") && str_contains($js, "'Consumo propio'") && str_contains($js, "'No localizado'") && str_contains($js, "'Traspaso entre cuentas'"));

// Resumen (3 cuadros) y textos de ayuda
foreach (['resVenta', 'resAnticipos', 'resCobros', 'resDepositos', 'resumenVacio', 'resumenContenido'] as $id) {
    check("la vista tiene id=\"$id\"", str_contains($vista, "id=\"$id\""));
}
foreach (['resVenta', 'resAnticipos', 'resCobros', 'resDepositos'] as $id) {
    check("$id es una tabla cv-cascada", (bool)preg_match('/<table class="cv-cascada" id="' . $id . '"/', $vista));
}
check('el JS define pintarResumen', str_contains($js, 'function pintarResumen('));
check('pintarResumen se dispara al terminar las cinco consultas', (bool)preg_match("/CONSULTAS_RESUMEN\s*=\s*\['venta',\s*'facturas',\s*'anticipos',\s*'cobros_factura',\s*'cobros'\]/", $js) && str_contains($js, 'pintarResumen()'));
foreach (['filtros', 'resumen', 'res_venta', 'res_anticipos', 'res_cobros', 'venta', 'app', 'propio', 'facturas', 'pendientes', 'anticipos',
          'anticipos_cli', 'cobros_factura', 'cruce', 'banco2791', 'banco0956'] as $k) {
    check("ayuda: AYUDA tiene la llave $k", str_contains($js, "    $k: ['"));
    check("ayuda: la vista tiene data-ayuda=\"$k\"", str_contains($vista, "data-ayuda=\"$k\""));
}
check('ayuda: menciona la Serie E cancelada que contaba el Excel', str_contains($js, '24 Serie E canceladas el 30/09'));
check('el Total del cruce excluye los cancelados', str_contains($js, "if (cancelado) return;"));

foreach ($lineas[0] as $l) {
    check('permission: 14 en ' . trim(substr($l, 0, 60)), str_contains($l, 'permission: 14'));
}

// Cada columna `data` de tablaAnticipos debe venir en las filas del endpoint (vigentes Y canceladas), si no DataTables lanza "unknown parameter"
$ctrl = $read('_assets/controllers/income.php');
preg_match("/initTabla\('#tablaAnticipos', \[(.*?)\], 'Anticipos debito'/s", $js, $mt);
preg_match_all("/data: '(\w+)'/", $mt[1] ?? '', $cols);
preg_match("/function conciliation_debit_anticipos\(\).*?usort\(/s", $ctrl, $fn);
foreach ($cols[1] as $col) {
    check("anticipos: el endpoint trae la columna '$col' en vigentes y canceladas", substr_count($fn[0] ?? '', "'$col'") >= 2);
}

finish();
