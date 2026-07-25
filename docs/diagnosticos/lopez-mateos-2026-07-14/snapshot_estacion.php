<?php
/**
 * Snapshot de diagnóstico de una estación (vía OPENQUERY desde el central).
 * Uso: php snapshot_estacion.php <etiqueta>   (ej. pre | post)
 * Caso: López Mateos (codgas 6), corte corrupto 2026-07-14 turno 41.
 */

$LABEL   = $argv[1] ?? 'pre';
$SERVER  = '192.168.5.101';        // linked server de la estación
$DB      = 'SG12_25262020';        // BaseDatos de la estación
$CODGAS  = 6;                      // Lopez Mateos
$FCH_INI = 46215;                  // 2026-07-13
$FCH_FIN = 46217;                  // 2026-07-15
$PRDS    = '1,2,3,179,180,181,192,193';
$OUTDIR  = 'C:\\Users\\alejandro.martinez\\Desktop\\codigo\\AplicativoPhp\\docs\\diagnosticos\\lopez-mateos-2026-07-14';

$pdo = new PDO(
    'sqlsrv:Server=192.168.0.6;Database=TG;TrustServerCertificate=yes;LoginTimeout=15',
    'cguser', 'sahei1712',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

function openquery(PDO $pdo, string $server, string $inner): array {
    $sql = sprintf("SELECT * FROM OPENQUERY([%s], '%s')", $server, str_replace("'", "''", $inner));
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

/* Consultas en la ESTACIÓN (clave => SQL interno). Las que fallen se anotan. */
$estacionQueries = [
    'StockReal' => "SELECT * FROM [$DB].dbo.StockReal
                    WHERE fch BETWEEN $FCH_INI AND $FCH_FIN AND codprd IN ($PRDS) ORDER BY fch, codprd, nrotur, codtan",
    'Ventas_agrupadas' => "SELECT fch, nrotur, codprd, COUNT(*) AS filas, SUM(canven) AS litros, SUM(mtoven) AS importe
                    FROM [$DB].dbo.Ventas WHERE fch BETWEEN $FCH_INI AND $FCH_FIN AND codprd IN ($PRDS)
                    GROUP BY fch, nrotur, codprd ORDER BY fch, codprd, nrotur",
    'Movimientos' => "SELECT * FROM [$DB].dbo.Movimientos
                    WHERE fch BETWEEN $FCH_INI AND $FCH_FIN AND codprd IN ($PRDS) ORDER BY fch, codprd, nrotur",
    'MovimientosTan' => "SELECT * FROM [$DB].dbo.MovimientosTan
                    WHERE fchtrn BETWEEN $FCH_INI AND $FCH_FIN ORDER BY fchtrn, hratrn",
    'Medicion' => "SELECT * FROM [$DB].dbo.Medicion
                    WHERE fch BETWEEN $FCH_INI AND $FCH_FIN ORDER BY fch",
    'Tanques' => "SELECT * FROM [$DB].dbo.Tanques",
];

$snapshot = [
    'etiqueta'  => $LABEL,
    'generado'  => date('Y-m-d H:i:s'),
    'estacion'  => ['codgas' => $CODGAS, 'servidor' => $SERVER, 'bd' => $DB],
    'rango_fch' => [$FCH_INI, $FCH_FIN],
    'tablas'    => [],
    'errores'   => [],
];

foreach ($estacionQueries as $tabla => $inner) {
    try {
        $rows = openquery($pdo, $SERVER, $inner);
        $snapshot['tablas']["estacion.$tabla"] = ['filas' => count($rows), 'datos' => $rows];
        echo "OK  estacion.$tabla: " . count($rows) . " filas\n";
    } catch (Throwable $e) {
        $snapshot['errores']["estacion.$tabla"] = $e->getMessage();
        echo "ERR estacion.$tabla: " . substr($e->getMessage(), 0, 160) . "\n";
    }
}

/* Contexto en el CENTRAL: réplica SG12 y snapshot de merma */
$centralQueries = [
    'SG12.StockReal' => "SELECT * FROM [SG12].dbo.StockReal
        WHERE codgas = $CODGAS AND fch BETWEEN $FCH_INI AND $FCH_FIN AND codprd IN ($PRDS)
        ORDER BY fch, codprd, nrotur, codtan",
    'TG.merma_diaria' => "SELECT * FROM [TG].dbo.merma_diaria
        WHERE codgas = $CODGAS AND fecha BETWEEN '2026-07-13' AND '2026-07-15'
        ORDER BY fecha, codprd, turno",
];
foreach ($centralQueries as $tabla => $sql) {
    try {
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $snapshot['tablas']["central.$tabla"] = ['filas' => count($rows), 'datos' => $rows];
        echo "OK  central.$tabla: " . count($rows) . " filas\n";
    } catch (Throwable $e) {
        $snapshot['errores']["central.$tabla"] = $e->getMessage();
        echo "ERR central.$tabla: " . substr($e->getMessage(), 0, 160) . "\n";
    }
}

if (!is_dir($OUTDIR)) mkdir($OUTDIR, 0777, true);
$file = $OUTDIR . "\\snapshot_$LABEL.json";
file_put_contents($file, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "\nSnapshot guardado en: $file\n";
