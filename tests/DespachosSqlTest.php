<?php
declare(strict_types=1);

/**
 * Pruebas de DespachosSql (reglas SQL compartidas del modelo de despachos).
 * No necesita base de datos ni framework:
 *
 *     php tests/DespachosSqlTest.php
 *
 * Termina con código 1 si alguna prueba falla.
 */
require __DIR__ . '/../_assets/models/DespachosSql.php';

$failures = 0;
function check(string $name, bool $condition): void
{
    global $failures;
    echo ($condition ? 'ok    ' : 'FALLA ') . $name . "\n";
    if (!$condition) {
        $failures++;
    }
}
function throws(callable $fn): bool
{
    try {
        $fn();
    } catch (InvalidArgumentException) {
        return true;
    }
    return false;
}
$squash = static fn (string $s): string => preg_replace('/\s+/', ' ', trim($s));

// --- folio: una serie por rango, el resto se deja como texto ---
$folio = $squash(DespachosSql::folio('t3.nro'));
foreach (['Z' => '2100000000 AND 2499999999', 'T' => '2000000000 AND 2099999999', 'K' => '1900000000 AND 1999999999',
          'C' => '1100000000 AND 1199999999', 'I' => '1700000000 AND 1799999999', 'E' => '1300000000 AND 1399999999'] as $serie => $rango) {
    check("folio reconoce la serie $serie", str_contains($folio, "WHEN t3.nro BETWEEN $rango THEN '$serie '"));
}
check('folio deja el número tal cual fuera de rango', str_contains($folio, 'ELSE CAST(t3.nro AS VARCHAR(10)) END'));

// --- tipo de cliente ---
check('tipoCliente (etiquetas): crédito antes que monedero',
    strpos(DespachosSql::tipoCliente(), "'Cliente Crédito'") < strpos(DespachosSql::tipoCliente(), "'Monedero'"));
check('tipoCliente aplicativo: monedero por tiptrn antes que crédito',
    strpos(DespachosSql::tipoCliente(false, true), "'Monedero'\nWHEN t10.codval = 28") !== false);
check('tipoCliente aplicativo incluye la lista de monederos', str_contains(DespachosSql::tipoCliente(false, true), "' Vale Sodexo'"));
check('tipoCliente (slug) usa slugs', str_contains(DespachosSql::tipoCliente(true), "'factura_global'"));

// --- filtros ---
check('filtroTimbrado 0 no filtra', DespachosSql::filtroTimbrado(0) === '');
check('filtroTimbrado 1 = sin timbrar', str_contains(DespachosSql::filtroTimbrado(1), 't1.satuid IS NULL AND t3.satuid IS NULL'));
check('filtroTimbrado 2 = timbrados', str_contains(DespachosSql::filtroTimbrado(2), 'IS NOT NULL'));
check('filtroFacturado 1 = sin facturar', str_contains(DespachosSql::filtroFacturado(1), 't1.nrofac = 0'));
check('filtroTipoCliente 0 / "0" / "" no filtra',
    DespachosSql::filtroTipoCliente(0) === '' && DespachosSql::filtroTipoCliente('0') === '' && DespachosSql::filtroTipoCliente('') === '');
check('filtroTipoCliente con slug válido compara contra el slug', str_contains(DespachosSql::filtroTipoCliente('contado'), "= 'contado'"));
check('filtroTipoCliente con slug desconocido no devuelve filas (sin inyección)',
    DespachosSql::filtroTipoCliente("x' OR 1=1 --") === ' AND 1 = 0');
check('filtrosReporte compone estación + timbrado + facturado + tipo',
    str_starts_with(DespachosSql::filtrosReporte(5, 1, 2, 'contado'), 'AND t1.codgas = 5'));
check('etiquetaTipoCliente', DespachosSql::etiquetaTipoCliente('cliente_debito') === 'Cliente Débito' && DespachosSql::etiquetaTipoCliente('nope') === null);

// --- búsqueda y orden (DataTables) ---
[$sql, $params] = DespachosSql::busqueda(DespachosSql::COLUMNAS_REPORTE, ['producto' => 'Max', 'rut' => '', 'malicioso' => 'x', 'placas' => ['a']], '');
check('busqueda por columna: solo columnas de la lista blanca, con parámetro',
    substr_count($sql, 'LIKE ?') === 1 && $params === ['%Max%']);
[$sql, $params] = DespachosSql::busqueda(DespachosSql::COLUMNAS_REPORTE, [], 'abc');
check('busqueda global: un LIKE por columna', substr_count($sql, 'LIKE ?') === count(DespachosSql::COLUMNAS_REPORTE) && count($params) === count(DespachosSql::COLUMNAS_REPORTE));
check('orden: columna desconocida cae en fecha ASC', DespachosSql::orden(DespachosSql::COLUMNAS_REPORTE, "x; DROP TABLE", 'raro') === ['fecha', 'ASC']);
check('orden: respeta desc', DespachosSql::orden(DespachosSql::COLUMNAS_REPORTE, 'importe', 'DESC') === ['importe', 'DESC']);

// --- validación de valores que se interpolan ---
check('listaEnteros desde texto', DespachosSql::listaEnteros('1, 2,3') === '1,2,3');
check('listaEnteros desde arreglo', DespachosSql::listaEnteros([4, '5']) === '4,5');
check('listaEnteros desde entero', DespachosSql::listaEnteros(7) === '7');
check('listaEnteros rechaza inyección', throws(static fn () => DespachosSql::listaEnteros('1); DROP TABLE x;--')));
check('listaEnteros rechaza vacío', throws(static fn () => DespachosSql::listaEnteros('')));
check('nombreSeguro acepta nombres reales', DespachosSql::nombreSeguro('SG12_11007+2020') === 'SG12_11007+2020' && DespachosSql::nombreSeguro('192.168.7.101') === '192.168.7.101');
check('nombreSeguro rechaza corchetes y comillas', throws(static fn () => DespachosSql::nombreSeguro("x]; DROP--")) && throws(static fn () => DespachosSql::nombreSeguro("a'b")));

exit($failures === 0 ? 0 : 1);
