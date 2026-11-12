<?php
declare(strict_types=1);

require_once __DIR__ . '/DebitCierre.class.php';

/**
 * Lógica pura de la foto diaria de clientes crédito (cierre del día de ayer).
 * Hereda de DebitCierre el cambio (hayCambio), el día en medio (filaParaDia)
 * y los días pendientes; cambia las columnas, la reconstrucción del cierre y
 * el saldo calculado, que aquí va ENCADENADO (el histórico no reconstruye
 * cresdo). Claves = columnas de TG.dbo.credit_clients_cierre.
 * Spec: docs/superpowers/specs/2026-11-12-credit-clients-cierre-diario-design.md
 */
class CreditCierre extends DebitCierre
{
    const MONTOS = [
        'limite',
        'saldo_ini_sistema', 'saldo_ini_calc_desp', 'saldo_ini_calc_cortes',
        'cobros_dia', 'notas_dia', 'facturado_dia',
        'consumos_desp_dia', 'consumos_cortes_dia', 'desfase_dia',
        'saldo_fin_calc_desp', 'saldo_fin_calc_cortes', 'saldo_fin_sistema', 'saldo_fin_vehiculos',
    ];

    const MOVIMIENTOS = [
        'cobros_dia', 'notas_dia', 'facturado_dia',
        'consumos_desp_dia', 'consumos_cortes_dia', 'desfase_dia',
    ];

    /**
     * Saldo sistema y contador de vehículos al cierre del día a partir de la
     * lectura de la mañana siguiente. cresdo sube con cada despacho (medido
     * 2026-11-12) y baja con cobros/notas: se restan los despachos posteriores
     * y se suman los abonos posteriores. El debsdo de vehículos baja con cada
     * despacho: se le suman los posteriores.
     */
    public static function reconstruirCredito(?float $lecturaCresdo, ?float $lecturaVeh, ?float $despPost, ?float $abonosPost): array
    {
        if ($lecturaCresdo === null) {
            return ['saldo_fin_sistema' => null, 'saldo_fin_vehiculos' => null];
        }
        $desp = $despPost ?? 0.0;
        return [
            'saldo_fin_sistema'   => round($lecturaCresdo - $desp + ($abonosPost ?? 0.0), 2),
            'saldo_fin_vehiculos' => round(($lecturaVeh ?? 0.0) + $desp, 2),
        ];
    }

    /**
     * Fila del cliente para el día. Con fila anterior, los iniciales calculados
     * son sus finales calculados; sin anterior se siembran con el saldo
     * sistema al inicio del día (así el día de siembra calculado = sistema),
     * o con 0 si no hay lectura.
     */
    public static function armarFila(array $calc, ?array $anterior): array
    {
        $num    = fn($v) => $v === null ? null : (float)$v;
        $cobros = (float)$calc['cobros_dia'];
        $notas  = (float)$calc['notas_dia'];
        $desp   = (float)$calc['consumos_desp_dia'];
        $cortes = (float)$calc['consumos_cortes_dia'];
        $limite = (float)$calc['limite'];

        $cierre = self::reconstruirCredito(
            $num($calc['lectura_cresdo'] ?? null),
            $num($calc['lectura_vehiculos'] ?? null),
            $num($calc['consumos_post_cierre'] ?? null),
            $num($calc['abonos_post_cierre'] ?? null)
        );
        $finSis = $cierre['saldo_fin_sistema'];

        if ($anterior) {
            $iniDesp   = (float)$anterior['saldo_fin_calc_desp'];
            $iniCortes = (float)$anterior['saldo_fin_calc_cortes'];
            $antFinSis = $num($anterior['saldo_fin_sistema'] ?? null);
        } else {
            $base      = $finSis === null ? null : $finSis + $cobros + $notas;
            $iniDesp   = $base === null ? 0.0 : $base - $desp;
            $iniCortes = $base === null ? 0.0 : $base - $cortes;
            $antFinSis = null;
        }

        return [
            'codcli'                => (int)$calc['codcli'],
            'cliente'               => (string)$calc['cliente'],
            'debglo'                => (int)$calc['debglo'],
            'limite'                => round($limite, 2),
            'saldo_ini_sistema'     => $antFinSis === null ? null : round($antFinSis, 2),
            'saldo_ini_calc_desp'   => round($iniDesp, 2),
            'saldo_ini_calc_cortes' => round($iniCortes, 2),
            'cobros_dia'            => round($cobros, 2),
            'notas_dia'             => round($notas, 2),
            'facturado_dia'         => round((float)$calc['facturado_dia'], 2),
            'consumos_desp_dia'     => round($desp, 2),
            'consumos_cortes_dia'   => round($cortes, 2),
            'desfase_dia'           => round($desp - $cortes, 2),
            'saldo_fin_calc_desp'   => round($iniDesp + $desp - $cobros - $notas, 2),
            'saldo_fin_calc_cortes' => round($iniCortes + $cortes - $cobros - $notas, 2),
            'saldo_fin_sistema'     => $finSis,
            'saldo_fin_vehiculos'   => $cierre['saldo_fin_vehiculos'],
            'disponible'            => ($limite > 0 && $finSis !== null) ? round($limite - $finSis, 2) : null,
            'lectura_cresdo'        => $num($calc['lectura_cresdo'] ?? null),
            'lectura_vehiculos'     => $num($calc['lectura_vehiculos'] ?? null),
            'consumos_post_cierre'  => $num($calc['consumos_post_cierre'] ?? null),
            'abonos_post_cierre'    => $num($calc['abonos_post_cierre'] ?? null),
            'lectura_at'            => $calc['lectura_at'] ?? null,
        ];
    }
}
