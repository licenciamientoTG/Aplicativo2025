<?php
/**
 * Importación puntual (una sola corrida) de julio 2026 para Praxedis y
 * Colosio desde el Excel "libro amarillo" (FormatoJul2026), ya que ninguna
 * de las dos estaciones tiene sync automático vía ApiER hacia merma_diaria.
 *
 * Cada hoja de estación trae 31 días x 3 filas/día (cortes sin turno
 * etiquetado) empezando en la fila 7 (día N = filas 7+3(N-1) .. 9+3(N-1)).
 * Se colapsa a 1 fila/día (turno sintético 41, igual que el resto del flujo
 * manual de estas dos estaciones): ventas y compras se SUMAN entre las 3
 * filas del día (el Excel reparte las ventas del día entre los 3 cortes,
 * casi siempre 0/0/total); inv_fisico se toma de la 3ra fila (cierre del
 * día). InventarioInicial/Contable/Diferencia se dejan NULL: los calcula
 * MermaDiariaModel::recalc_contable() (regla libro amarillo), llamado
 * automáticamente dentro de replace_station_range().
 *
 * Hoja "10702" (Praxedis, codgas 40): MAXIMA en cols C-H, tercer producto en
 * J-O (este mes es DIESEL, sin 91 Octanos/SUPER). Hoja "22600 Colosio"
 * (codgas 199): MAXIMA en C-H, SUPER en J-O, sin Diesel. Layout: VR, %,
 * COMPRAS, INV.CONT, Inv.Fisico, Diferencia.
 *
 * Uso: php cron/import_libro_amarillo_julio2026.php
 */

$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
$_SERVER['REQUEST_URI']   = '/cron/import_libro_amarillo_julio2026';
chdir($_SERVER['DOCUMENT_ROOT']);

require '_assets/classes/header.class.php';
require '_assets/classes/php_functions.php';

spl_autoload_register(function ($class) {
    if (file_exists(CLASSES . $class . '.class.php')) {
        require CLASSES . $class . '.class.php';
    }
    if (file_exists(MODELS . $class . '.php')) {
        require MODELS . $class . '.php';
    }
});

require 'vendor/autoload.php';

const EXCEL_PATH = 'C:\\Users\\alejandro.martinez\\Desktop\\libro amarillo\\FormatoJul2026 (2) hoy.xlsm';
const ANIO  = 2026;
const MES   = 7;
const DESDE = '2026-07-01';
const HASTA = '2026-07-31';

// [hoja => [codgas, nombre_estacion, columnas por producto]]
// Columnas por producto: VR, COMPRAS, INV.FISICO (letras de columna en la hoja).
const ESTACIONES = [
    '10702' => [
        'codgas'   => 40,
        'estacion' => 'PRAXEDIS',
        'productos' => [
            1 => ['nombre' => 'MAXIMA', 'vr' => 'C', 'compras' => 'E', 'fisico' => 'G'],
            3 => ['nombre' => 'DIESEL', 'vr' => 'J', 'compras' => 'L', 'fisico' => 'N'],
        ],
    ],
    '22600 Colosio' => [
        'codgas'   => 199,
        'estacion' => 'COLOSIO',
        'productos' => [
            1 => ['nombre' => 'MAXIMA', 'vr' => 'C', 'compras' => 'E', 'fisico' => 'G'],
            2 => ['nombre' => 'SUPER',  'vr' => 'J', 'compras' => 'L', 'fisico' => 'N'],
        ],
    ],
];

echo "[" . date('Y-m-d H:i:s') . "] Importando julio 2026 desde libro amarillo\n";

$reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
$reader->setReadDataOnly(true);
$reader->setLoadSheetsOnly(array_keys(ESTACIONES));
$spreadsheet = $reader->load(EXCEL_PATH);

$mermaModel = new MermaDiariaModel();

foreach (ESTACIONES as $sheetName => $cfg) {
    $sheet = $spreadsheet->getSheetByName($sheetName);
    if (!$sheet) {
        echo "  ! Hoja '{$sheetName}' no encontrada, se omite\n";
        continue;
    }

    $filas = [];
    for ($dia = 1; $dia <= 31; $dia++) {
        $fecha = sprintf('%04d-%02d-%02d', ANIO, MES, $dia);
        $r1 = 7 + 3 * ($dia - 1);
        $r2 = $r1 + 1;
        $r3 = $r1 + 2;

        foreach ($cfg['productos'] as $codprd => $prod) {
            $vr = 0.0;
            foreach ([$r1, $r2, $r3] as $r) {
                $v = $sheet->getCell($prod['vr'] . $r)->getCalculatedValue();
                if (is_numeric($v)) $vr += (float)$v;
            }
            $compras = 0.0;
            foreach ([$r1, $r2, $r3] as $r) {
                $v = $sheet->getCell($prod['compras'] . $r)->getCalculatedValue();
                if (is_numeric($v)) $compras += (float)$v;
            }
            $fisico = $sheet->getCell($prod['fisico'] . $r3)->getCalculatedValue();
            if (!is_numeric($fisico)) {
                echo "  ! {$cfg['estacion']} {$fecha} {$prod['nombre']}: Inv. Fisico no numérico, se omite ese día/producto\n";
                continue;
            }

            $filas[] = [
                'Fecha'              => $fecha,
                'CodProducto'        => $codprd,
                'Producto'           => $prod['nombre'],
                'Turno'              => 41,
                'VentasReales'       => round($vr, 2),
                'Inventario'         => round((float)$fisico, 2),
                'CantidadCompra'     => round($compras, 2),
                'InventarioInicial'  => null,
                'InventarioContable' => null,
                'Diferencia'         => null,
            ];
        }
    }

    $insertadas = $mermaModel->replace_station_range(
        $cfg['codgas'], $cfg['estacion'], DESDE, HASTA, $filas
    );
    echo "  {$cfg['estacion']} (codgas {$cfg['codgas']}): {$insertadas} filas insertadas (" . count($filas) . " preparadas)\n";
}

echo "[" . date('Y-m-d H:i:s') . "] Import terminado\n";
