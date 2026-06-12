<?php
require __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

class FirstRowsFilter implements IReadFilter
{
    public function readCell($columnAddress, $row, $worksheetName = ''): bool
    {
        if ($row > 40) return false;
        $colIdx = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($columnAddress);
        return $colIdx <= 30;
    }
}

$jobs = [
    ['file' => 'C:\Users\alejandro.martinez\Desktop\pago a proveedores\LAYOUT SANTANDER GENERAL.xlsx', 'sheets' => ['PAGO A PROVEEDORES']],
    ['file' => 'C:\Users\alejandro.martinez\Desktop\pago a proveedores\LAYOUT BANORTE GENERAL.xlsx', 'sheets' => null],
    ['file' => 'C:\Users\alejandro.martinez\Desktop\pago a proveedores\TRANSFERENCIAS MIXTAS NUEVA.xls', 'sheets' => null],
];

foreach ($jobs as $job) {
    $file = $job['file'];
    echo "\n========== ARCHIVO: " . basename($file) . " ==========\n";
    try {
        $reader = IOFactory::createReaderForFile($file);
        $reader->setReadDataOnly(true);
        $reader->setReadFilter(new FirstRowsFilter());
        if ($job['sheets'] !== null) {
            $reader->setLoadSheetsOnly($job['sheets']);
        } else {
            $sheetNames = $reader->listWorksheetNames($file);
            echo "HOJAS: " . implode(' / ', $sheetNames) . "\n";
        }
        $spreadsheet = $reader->load($file);
        foreach ($spreadsheet->getSheetNames() as $sheetName) {
            $sheet = $spreadsheet->getSheetByName($sheetName);
            $maxRow = min($sheet->getHighestDataRow(), 40);
            echo "\n--- HOJA: {$sheetName} ---\n";
            for ($r = 1; $r <= $maxRow; $r++) {
                $cells = [];
                $hasData = false;
                for ($c = 1; $c <= 30; $c++) {
                    $coord = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c) . $r;
                    $val = $sheet->getCell($coord)->getFormattedValue();
                    if ($val !== '' && $val !== null) $hasData = true;
                    $cells[] = $val;
                }
                if ($hasData) {
                    echo "R{$r}: " . implode(' | ', $cells) . "\n";
                }
            }
        }
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
    } catch (Exception $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
    }
}
