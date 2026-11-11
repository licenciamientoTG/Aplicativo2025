<?php
declare(strict_types=1);

/**
 * Pruebas de la lectura de pagos pegados como texto:  php tests/LayoutTextoTest.php
 */
require __DIR__ . '/support.php';
require __DIR__ . '/../_assets/classes/LayoutTexto.class.php';

$texto = "1948    ALTOS ENERGETICOS MEXICANOS    968,530.63    FORMULAGAS    \n"
    . "1951\tALTOS ENERGETICOS MEXICANOS\t738,266.90\tESTACION CUSTODIA\t\n"
    . "1952    ALTOS ENERGETICOS MEXICANOS    738,465.61    FORMULAGAS    \n"
    . "C1277    PREMIERGAS S.A. P. I. DE C.V.    709,057.79    DIAZ GAS    \n"
    . "1950    TESORO MEXICO SUPPLY & MARKETING S. DE R.L. DE C.V.    13,856,423.32    DIAZ GAS    \n"
    . "C1439     TESORO MEXICO SUPPLY & MARKETING    1,181,993.68    PETROTAL    \n"
    . "1907    PREMIERGAS S.A. P. I. DE C.V.    2,111,825.77    GASOMEX    \n"
    . "1884    PREMIERGAS S.A. P. I. DE C.V.    444,816.73    JARUDO    \n"
    . "1833    PREMIERGAS S.A. P. I. DE C.V.    442,227.25    CLARA    \n"
    . "1962    MGC MEXICO S.A. DE C.V.    2,118,708.94    GASOMEX    88400000001\n"
    . "\n"
    . "FORMULAGAS    1,706,996.24\n"
    . "ESTACION CUSTODIA    738,266.90\n"
    . "DIAZ GAS    14,565,481.11\n"
    . "PETROTAL    1,181,993.68\n"
    . "GASOMEX    4,230,534.71\n"
    . "JARUDO    444,816.73\n"
    . "CLARA    442,227.25\n";

$r = LayoutTexto::parse($texto);
$l = $r['lineas'];

check('parse: 10 líneas de pago', count($l) === 10);
check('parse: 7 totales por empresa', count($r['totales']) === 7);
check('parse: nada sin leer', count($r['no_leidas']) === 0);
check('parse: suma total 23,310,316.62', abs(array_sum(array_column($l, 'monto')) - 23310316.62) < 0.005);
check('parse: primera línea', $l[0]['numero'] === '1948' && $l[0]['proveedor'] === 'ALTOS ENERGETICOS MEXICANOS'
    && $l[0]['monto'] === 968530.63 && $l[0]['empresa'] === 'FORMULAGAS' && $l[0]['referencia'] === '');
check('parse: línea separada por tabs', $l[1]['numero'] === '1951' && $l[1]['empresa'] === 'ESTACION CUSTODIA');
check('parse: número con letra', $l[3]['numero'] === 'C1277' && $l[3]['empresa'] === 'DIAZ GAS');
check('parse: proveedor con & y razón social', $l[4]['proveedor'] === 'TESORO MEXICO SUPPLY & MARKETING S. DE R.L. DE C.V.'
    && $l[4]['monto'] === 13856423.32);
check('parse: referencia al final', $l[9]['empresa'] === 'GASOMEX' && $l[9]['referencia'] === '88400000001');
check('parse: total de empresa de dos palabras', $r['totales'][1]['empresa'] === 'ESTACION CUSTODIA'
    && $r['totales'][1]['monto'] === 738266.90);

// Variantes de escritura
$v = LayoutTexto::parse("c12 proveedor uno \$1,000.50 la 47\n[10/11/26, 9:15 a.m.] Susana: 77 PROV DOS 250.00 CLARA");
check('parse: minúsculas, signo de pesos y empresa con número', count($v['lineas']) === 2
    && $v['lineas'][0]['numero'] === 'C12' && $v['lineas'][0]['monto'] === 1000.50 && $v['lineas'][0]['empresa'] === 'la 47'
    && $v['lineas'][0]['referencia'] === '');
check('parse: quita el prefijo de WhatsApp', $v['lineas'][1]['numero'] === '77' && $v['lineas'][1]['empresa'] === 'CLARA');

$malas = LayoutTexto::parse("Buenos días, van los pagos\n1948 ALTOS ENERGETICOS 968,530.63\n");
check('parse: saludo va a no leídas', count($malas['lineas']) === 0 && count($malas['no_leidas']) === 2);
check('parse: línea sin empresa no se toma como total', count($malas['totales']) === 0
    && $malas['no_leidas'][1]['motivo'] === 'Falta la empresa después del monto');

// Normalización
check('normalizar: quita razón social y puntuación',
    LayoutTexto::normalizar('TESORO MEXICO SUPPLY & MARKETING S. DE R.L. DE C.V.') === 'TESORO MEXICO SUPPLY MARKETING');
check('normalizar: acentos', LayoutTexto::normalizar('La 47 de Juárez, S.A. de C.V.') === 'LA 47 JUAREZ');

// Resolución contra catálogos
$empresas = [
    1 => 'DIAZ GAS', 3 => 'La 47 de Juárez, S.A. de C.V.', 6 => 'Estación Independencia, S.A. de C.V.',
    8 => 'Alejandro Diaz Villalobos', 9 => 'Estación Libre, S.A. de C.V.', 10 => 'ESTACION CUSTODIA',
    14 => 'DISTRIBUIDORA CLARA', 15 => 'SERVICIO EL JARUDO', 16 => 'DISTRIBUIDORA GASO MEX', 22 => 'FORMULAGAS',
];
check('empresa: exacta', LayoutTexto::resolver('DIAZ GAS', $empresas)['cod'] === 1);
check('empresa: GASOMEX -> DISTRIBUIDORA GASO MEX', LayoutTexto::resolver('GASOMEX', $empresas)['cod'] === 16);
check('empresa: CLARA', LayoutTexto::resolver('CLARA', $empresas)['cod'] === 14);
check('empresa: JARUDO', LayoutTexto::resolver('jarudo', $empresas)['cod'] === 15);
check('empresa: ESTACION CUSTODIA', LayoutTexto::resolver('ESTACION CUSTODIA', $empresas)['cod'] === 10);
check('empresa: FORMULAGAS', LayoutTexto::resolver('FORMULAGAS', $empresas)['cod'] === 22);
$pet = LayoutTexto::resolver('PETROTAL', $empresas);
check('empresa: PETROTAL no existe', $pet['cod'] === null && $pet['motivo'] === 'no existe en el catálogo');
$amb = LayoutTexto::resolver('ESTACION', $empresas);
check('empresa: ambigua no se adivina', $amb['cod'] === null && str_starts_with((string)$amb['motivo'], 'coincide con varios'));

$proveedores = [
    56 => 'TESORO MEXICO SUPPLY & MARKETING S. DE R.L. DE C.V.', 71 => 'PREMIERGAS S.A. P. I. DE C.V.',
    72 => 'MGC MEXICO S.A. DE C.V.', 83 => 'ENEREY LATINOAMERICA', 96 => 'ALTOS ENERGETICOS MEXICANOS',
];
check('proveedor: Tesoro con razón social', LayoutTexto::resolver('TESORO MEXICO SUPPLY & MARKETING S. DE R.L. DE C.V.', $proveedores)['cod'] === 56);
check('proveedor: Tesoro abreviado', LayoutTexto::resolver('TESORO MEXICO SUPPLY & MARKETING', $proveedores)['cod'] === 56);
check('proveedor: Premiergas', LayoutTexto::resolver('PREMIERGAS S.A. P. I. DE C.V.', $proveedores)['cod'] === 71);
check('proveedor: MGC', LayoutTexto::resolver('MGC MEXICO S.A. DE C.V.', $proveedores)['cod'] === 72);
check('proveedor: nombre más largo que el catálogo', LayoutTexto::resolver('ENEREY LATINOAMERICA S DE RL DE CV NORTE', $proveedores)['cod'] === 83);
check('proveedor: desconocido', LayoutTexto::resolver('PROVEEDOR INVENTADO', $proveedores)['cod'] === null);

// Totales
check('totales: el ejemplo cuadra', LayoutTexto::verificarTotales($l, $r['totales']) === []);
$tot = $r['totales'];
$tot[0]['monto'] = 1706996.00;
$av = LayoutTexto::verificarTotales($l, $tot);
check('totales: avisa diferencia', count($av) === 1 && str_contains($av[0], 'FORMULAGAS'));
array_pop($tot);
$tot[0]['monto'] = 1706996.24;
$av = LayoutTexto::verificarTotales($l, $tot);
check('totales: avisa empresa sin total', count($av) === 1 && str_contains($av[0], 'CLARA'));
check('totales: sin bloque de totales no avisa', LayoutTexto::verificarTotales($l, []) === []);

finish();
