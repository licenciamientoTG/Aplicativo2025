<?php
declare(strict_types=1);

/**
 * Lógica pura de la foto diaria v2 de clientes débito (cierre del día de ayer):
 * arma la fila de un cliente, decide si cambió respecto a la vigente, la
 * proyecta a un día que cae en medio de su vigencia y calcula qué días faltan.
 * Sin BD. Las claves son los nombres de columna de TG.dbo.debit_clients_cierre.
 * Spec: docs/superpowers/specs/2026-11-11-debit-clients-cierre-diario-design.md
 */
class DebitCierre
{
    /** Columnas de monto que se comparan para decidir si se abre fila nueva. */
    const MONTOS = [
        'saldo_ini_sistema', 'saldo_ini_calc_desp', 'saldo_ini_calc_cortes',
        'ajuste_retro_desp', 'ajuste_retro_cortes',
        'anticipos_dia', 'consumos_desp_dia', 'consumos_cortes_dia', 'desfase_dia',
        'saldo_fin_calc_desp', 'saldo_fin_calc_cortes', 'saldo_fin_sistema', 'saldo_fin_vehiculos',
    ];

    /** Movimientos del día: valen 0 en los días posteriores a fecha_desde. */
    const MOVIMIENTOS = [
        'ajuste_retro_desp', 'ajuste_retro_cortes',
        'anticipos_dia', 'consumos_desp_dia', 'consumos_cortes_dia', 'desfase_dia',
    ];

    const TOLERANCIA = 0.005;
    const TOPE_DIAS = 31;

    /**
     * Saldo sistema y de vehículos al cierre del día, a partir de la lectura de
     * la mañana siguiente: se regresan los despachos hechos entre 00:00 y la
     * lectura. Medido en vivo el 2026-11-11 (3 de 3 clientes, con y sin bolsa
     * global): cada despacho baja la bolsa Y la tarjeta, así que a los dos se
     * les regresa. $debglo se conserva en la firma por si eso cambia.
     */
    public static function reconstruir(?float $lecturaDebsdo, ?float $lecturaVeh, ?float $postCierre, int $debglo): array
    {
        if ($lecturaDebsdo === null) {
            return ['saldo_fin_sistema' => null, 'saldo_fin_vehiculos' => null];
        }
        $post = $postCierre ?? 0.0;
        $veh  = $lecturaVeh ?? 0.0;
        return [
            'saldo_fin_sistema'   => round($lecturaDebsdo + $post, 2),
            'saldo_fin_vehiculos' => round($veh + $post, 2),
        ];
    }

    /** Fila completa del cliente para el día, contra su fila anterior (o null). */
    public static function armarFila(array $calc, ?array $anterior): array
    {
        $num = fn($v) => $v === null ? null : (float)$v;
        $iniDesp   = (float)$calc['saldo_ini_calc_desp'];
        $iniCortes = (float)$calc['saldo_ini_calc_cortes'];
        $ant       = (float)$calc['anticipos_dia'];
        $desp      = (float)$calc['consumos_desp_dia'];
        $cortes    = (float)$calc['consumos_cortes_dia'];
        $debglo    = (int)$calc['debglo'];

        $cierre = self::reconstruir(
            $num($calc['lectura_debsdo'] ?? null),
            $num($calc['lectura_vehiculos'] ?? null),
            $num($calc['consumos_post_cierre'] ?? null),
            $debglo
        );

        $antFinSis = $anterior ? $num($anterior['saldo_fin_sistema'] ?? null) : null;

        return [
            'codcli'                => (int)$calc['codcli'],
            'cliente'               => (string)$calc['cliente'],
            'debglo'                => $debglo,
            'saldo_ini_sistema'     => $antFinSis === null ? null : round($antFinSis, 2),
            'saldo_ini_calc_desp'   => round($iniDesp, 2),
            'saldo_ini_calc_cortes' => round($iniCortes, 2),
            'ajuste_retro_desp'     => $anterior ? round($iniDesp - (float)$anterior['saldo_fin_calc_desp'], 2) : null,
            'ajuste_retro_cortes'   => $anterior ? round($iniCortes - (float)$anterior['saldo_fin_calc_cortes'], 2) : null,
            'anticipos_dia'         => round($ant, 2),
            'consumos_desp_dia'     => round($desp, 2),
            'consumos_cortes_dia'   => round($cortes, 2),
            'desfase_dia'           => round($desp - $cortes, 2),
            'saldo_fin_calc_desp'   => round($iniDesp + $ant - $desp, 2),
            'saldo_fin_calc_cortes' => round($iniCortes + $ant - $cortes, 2),
            'saldo_fin_sistema'     => $cierre['saldo_fin_sistema'],
            'saldo_fin_vehiculos'   => $cierre['saldo_fin_vehiculos'],
            'lectura_debsdo'        => $num($calc['lectura_debsdo'] ?? null),
            'lectura_vehiculos'     => $num($calc['lectura_vehiculos'] ?? null),
            'consumos_post_cierre'  => $num($calc['consumos_post_cierre'] ?? null),
            'lectura_at'            => $calc['lectura_at'] ?? null,
        ];
    }

    /** ¿Cambió algún monto o la bandera de bolsa global? NULL ≠ número, NULL = NULL. */
    public static function hayCambio(array $vigente, array $nueva): bool
    {
        if ((int)$vigente['debglo'] !== (int)$nueva['debglo']) {
            return true;
        }
        // static:: para que CreditCierre compare sus propias columnas
        foreach (static::MONTOS as $col) {
            $a = $vigente[$col] ?? null;
            $b = $nueva[$col] ?? null;
            if ($a === null || $b === null) {
                if ($a !== $b) return true;
                continue;
            }
            if (abs((float)$a - (float)$b) >= static::TOLERANCIA) {
                return true;
            }
        }
        return false;
    }

    /**
     * Fila vista en el día $dia. Si $dia cae después de fecha_desde, ese día no
     * tuvo movimientos: los iniciales son los finales de la fila y los
     * movimientos/ajustes son 0.
     */
    public static function filaParaDia(array $fila, string $dia): array
    {
        if ($dia === $fila['fecha_desde']) {
            return $fila;
        }
        $fila['saldo_ini_sistema']     = $fila['saldo_fin_sistema'];
        $fila['saldo_ini_calc_desp']   = $fila['saldo_fin_calc_desp'];
        $fila['saldo_ini_calc_cortes'] = $fila['saldo_fin_calc_cortes'];
        foreach (static::MOVIMIENTOS as $col) {
            $fila[$col] = 0.0;
        }
        return $fila;
    }

    /**
     * Días a procesar en orden: del siguiente al último día OK hasta $ayer.
     * Sin corridas previas, o con el último = $ayer (re-corrida), solo $ayer.
     * Con más de $tope días pendientes, solo $ayer.
     */
    public static function diasPendientes(?string $ultimoOk, string $ayer, int $tope = self::TOPE_DIAS): array
    {
        if ($ultimoOk === null || $ultimoOk >= $ayer) {
            return [$ayer];
        }
        $dias = [];
        $d = new DateTime($ultimoOk);
        $fin = new DateTime($ayer);
        while (true) {
            $d->modify('+1 day');
            if ($d > $fin) break;
            $dias[] = $d->format('Y-m-d');
            if (count($dias) > $tope) {
                return [$ayer];
            }
        }
        return $dias;
    }
}
