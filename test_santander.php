<?php
// Load CSV header
$csvPath = '_assets/temporal/statement_25022026.csv';
$handle = fopen($csvPath, 'r');
if (!$handle) {
    die("Cannot open CSV file\n");
}
$firstLine = fgets($handle);
rewind($handle);
$header = fgetcsv($handle, 0, ',');
if ($header === false) {
    die("Failed to read CSV header\n");
}
fclose($handle);

// Simulate calling sanitizar_nombre_columna_php (needs coreMap for Santander)
$bankType = 'SANTANDER';
$coreMap = [];
// Minimal map with relevant entries
$coreMap[$bankType] = [
    'Fecha Depósito' => 'Fecha_Deposito',
    'Fecha de Depósito' => 'Fecha_Deposito',
    'Fecha Aplicación' => 'Fecha_Deposito',
    'Fecha de Aplicación' => 'Fecha_Deposito',
    'Fecha Deposito' => 'Fecha_Deposito',
    'Fecha Deposito' => 'Fecha_Deposito',
];

$income = new class{
    public function sanitizar_nombre_columna_php($nombre, $bankType, $coreMap)
    {
        if (!$nombre)
            return "SinNombre";
        $orig = trim((string)$nombre);
        // 1. Quitar BOM (eliminar) y comillas residuales
        $orig = str_replace(["\xEF\xBB\xBF", "\xFE\xFF", "\xFF\xFE", "\xC2\xA0"], '', $orig);
        $orig = trim($orig, " \t\n\r\0\x0B\"'");
        $orig = trim(preg_replace('/\s+/', ' ', $orig));
        // 2. Reparación de codificación
        if (preg_match('/[\xC2\xC3][\x80-\xBF]/', $orig)) {
            $intento = @utf8_decode($orig);
            if ($intento && mb_check_encoding($intento, 'UTF-8')) {
                $orig = $intento;
            }
        }
        // 3. Revisar en el mapa
        if (isset($coreMap[$bankType][$orig])) {
            return $coreMap[$bankType][$orig];
        }
        // 3.1 Fuzzy match robusto
        $norm = mb_strtoupper($orig, 'UTF-8');
        $norm = str_replace(
        ['À', 'Á', 'Â', 'Ã', 'Ä', 'È', 'É', 'Ê', 'Ë', 'Ì', 'Í', 'Î', 'Ï', 'Ò', 'Ó', 'Ô', 'Õ', 'Ö', 'Ù', 'Ú', 'Û', 'Ü'],
        ['A', 'A', 'A', 'A', 'A', 'E', 'E', 'E', 'E', 'I', 'I', 'I', 'I', 'O', 'O', 'O', 'O', 'O', 'U', 'U', 'U', 'U'],
            $norm
        );
        if (preg_match('/FECHA/i', $norm)) {
            if (preg_match('/DEPOSITO|APLICACION/i', $norm)) {
                return 'Fecha_Deposito';
            }
            if (preg_match('/TRANSACCION/i', $norm)) {
                return 'Fecha_Transaccion';
            }
        }
        // fallback
        return 'UNKNOWN';
    }
};

foreach ($header as $col) {
    $result = $income->sanitizar_nombre_columna_php($col, $bankType, $coreMap);
    echo "Column: [$col] => $result\n";
}
?>
