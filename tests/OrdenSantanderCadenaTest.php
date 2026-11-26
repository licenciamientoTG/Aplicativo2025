<?php
declare(strict_types=1);

/**
 * Orden de un día de Santander según la cadena de saldos:
 *   php tests/OrdenSantanderCadenaTest.php
 * (caso real: 3/11/2026, CSV de fin de semana intercalados por hora con el TXT)
 */
require __DIR__ . '/support.php';
if (!class_exists('Model')) { class Model {} }
require __DIR__ . '/../_assets/models/MovimientosBancariosModel.php';

function fila(int $id, ?float $cargo, ?float $abono, float $saldo): array
{
    return ['id' => $id, 'cargo' => $cargo, 'abono' => $abono, 'saldo' => $saldo];
}
function ids(array $filas): array { return array_column($filas, 'id'); }

// Cuenta 65500563281, 3/11/2026 (recortado): cierre previo 158,938.04.
// Orden real: 74897, 74898, 74899, 74900, 74901 (CSV), 75667, 75668 (TXT), 75669...
$cierre = 158938.04;
$real = [
    fila(74897, null, 300.00, 159238.04),
    fila(74898, null, 477.80, 159715.84),
    fila(74899, null, 5900.00, 165615.84),
    fila(74900, null, 3500.00, 169115.84),
    fila(74901, null, 50000.00, 219115.84),
    fila(75667, null, 8118.69, 227234.53),
    fila(75668, null, 9990.00, 237224.53),
    fila(75669, null, 76500.00, 313724.53),
    fila(75670, null, 150580.00, 464304.53),
];
// Orden "por hora" que dejó el sistema: CSV y TXT intercalados.
$porHora = [$real[7], $real[8], $real[0], $real[3], $real[4], $real[5], $real[1], $real[2], $real[6]];

$r = MovimientosBancariosModel::orden_por_cadena_saldos($porHora, $cierre);
check('reconstruye el orden real de la cadena', ids($r) === ids($real));

$r = MovimientosBancariosModel::orden_por_cadena_saldos($real, $cierre);
check('un día ya ordenado queda igual', ids($r) === ids($real));

$r = MovimientosBancariosModel::orden_por_cadena_saldos([$real[2], $real[0], $real[1]], null);
check('sin saldo previo arranca en la fila que no tiene antecesora', ids($r) === [74897, 74898, 74899]);

// Cargo y abono mezclados: 100 -> +50 -> -30 -> +5
$mix = [fila(3, null, 5.0, 125.0), fila(1, null, 50.0, 150.0), fila(2, 30.0, null, 120.0)];
$r = MovimientosBancariosModel::orden_por_cadena_saldos($mix, 100.0);
check('mezcla de cargos y abonos', ids($r) === [1, 2, 3]);

// Falta un movimiento (74899 no está): no se pierde ninguna fila y cada tramo queda en orden.
$hueco = [$real[5], $real[3], $real[0], $real[1], $real[4]];
$r = MovimientosBancariosModel::orden_por_cadena_saldos($hueco, $cierre);
check('con hueco conserva todas las filas', count($r) === 5 && count(array_unique(ids($r))) === 5);
check('con hueco ordena cada tramo', ids($r) === [74897, 74898, 74900, 74901, 75667]);

check('lista vacía', MovimientosBancariosModel::orden_por_cadena_saldos([], 10.0) === []);

finish();
