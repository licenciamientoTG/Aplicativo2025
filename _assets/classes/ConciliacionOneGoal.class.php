<?php
/**
 * ConciliacionOneGoal — lógica pura de Conciliación One Goal ↔ ControlGas (sin base de datos).
 *
 * Llaves en One Goal (1G_TOTALGAS):
 *  - imp_vta_fac_enc_clie: el CFDI importado (UUID, cliente fiscal); serie/folio en el nombre del archivo
 *    "RFC_CFDI_D243350_20260904.xml". Se une a ControlGas por UUID.
 *  - imp_fac_enc: la factura con importes (sub, imptos, total), estatus (1 vigente, 3 cancelada, -1 sin timbrar) y
 *    mensaje de importación. imp_id_fac = imp_vta_fac_enc_clie.imp_id_fact e imp_id_fac_sust = DocumentosC.nro.
 */
class ConciliacionOneGoal {

    /** Serie D de ControlGas: DocumentosC.nro = 1200000000 + folio. */
    public const SERIE_D        = 1200000000;

    public const EN_AMBOS       = 'En ambos';
    public const FALTA          = 'Falta en One Goal';
    public const SIN_UUID       = 'Sin UUID';
    public const SIN_UUID_CG    = 'Timbrada en One Goal, sin UUID en ControlGas';
    public const SOLO_ONEGOAL   = 'Solo en One Goal';
    public const CANCELADA      = 'Cancelada';
    public const CANCELADA_OG   = 'Cancelada, vigente en One Goal';
    public const CANCELADA_EN_OG = 'Cancelada en One Goal';
    public const IMPORTE_DIST   = 'Importe distinto';

    /** imp_fac_enc.imp_status → texto. */
    public static function estatusOneGoal(mixed $status): string {
        return match ((string)$status) { '1' => 'Vigente', '3' => 'Cancelada', '-1' => 'Sin timbrar', default => (string)$status };
    }

    /** "DGA930823KD3_CFDI_D243350_20260904.xml" → ['serie' => 'D', 'folio' => 243350]; null si no cumple el formato. */
    public static function folioDeArchivo(string $archivo): ?array {
        if (!preg_match('/_CFDI_([A-Za-z]+)(\d+)_\d{8}\.xml$/i', trim($archivo), $m)) return null;
        return ['serie' => strtoupper($m[1]), 'folio' => (int)$m[2]];
    }

    /**
     * @param array $cg  ControlGas: nro, uuid, fecha (Y-m-d), codcli, cliente, total, cancelada (0/1)
     * @param array $og  CFDI de One Goal: uuid, archivo, cliente, rfc, fecha, id_fact
     * @param array $fac facturas de One Goal (imp_fac_enc): id_fac, nro (imp_id_fac_sust), fecha, sub, iva, total, status, status_imp, mensaje, id_cli
     * @return array{filas: array, resumen: array}
     */
    public static function conciliar(array $cg, array $og, array $fac = [], ?string $desde = null, ?string $hasta = null): array {
        $ogPorUuid = [];
        foreach ($og as $r) {
            $u = strtolower(trim((string)$r['uuid']));
            if ($u !== '') $ogPorUuid[$u] = $r;
        }
        [$facPorNro, $facPorId] = self::indiceFacturas($fac);
        $cfdiPorFact = [];
        foreach ($ogPorUuid as $c0) if ((int)($c0['id_fact'] ?? 0) > 0) $cfdiPorFact[(int)$c0['id_fact']] = $c0;

        $filas = [];
        $vistos = [];      // uuid de One Goal ya ligados a una fila de ControlGas
        $facUsadas = [];   // id_fac de One Goal ya ligadas
        foreach ($cg as $r) {
            $nro = (int)$r['nro'];
            $u = strtolower(trim((string)($r['uuid'] ?? '')));
            $c = $u !== '' && isset($ogPorUuid[$u]) ? $ogPorUuid[$u] : null;
            $f = $facPorNro[$nro] ?? ($c ? ($facPorId[(int)($c['id_fact'] ?? 0)] ?? null) : null);
            // ControlGas sin satuid pero One Goal sí tiene el CFDI de esa factura (se liga por la factura de One Goal).
            if (!$c && $f && isset($cfdiPorFact[$f['id_fac']])) $c = $cfdiPorFact[$f['id_fac']];
            if ($c) $vistos[strtolower(trim((string)$c['uuid']))] = true;
            if ($f) $facUsadas[$f['id_fac']] = true;
            $cancelada = !empty($r['cancelada']);
            $total = round((float)($r['total'] ?? 0), 2);
            $ogEstatus = $f ? self::estatusOneGoal($f['status']) : '';
            $enOg = $c !== null || $f !== null;

            if ($cancelada) {
                // Con datos de factura manda su estatus; sin ellos, basta con que el CFDI siga en One Goal.
                $sigue = $f ? (string)$f['status'] === '1' : $c !== null;
                $estatus = $sigue ? self::CANCELADA_OG : self::CANCELADA;
            } elseif ($u === '') {
                $estatus = $c ? self::SIN_UUID_CG : self::SIN_UUID;
            } elseif (!$enOg) {
                $estatus = self::FALTA;
            } elseif ($f && (string)$f['status'] === '3') {
                $estatus = self::CANCELADA_EN_OG;
            } elseif ($f && abs($total - $f['total']) > 0.01) {
                $estatus = self::IMPORTE_DIST;
            } else {
                $estatus = self::EN_AMBOS;
            }

            $folio = $c ? self::folioDeArchivo((string)$c['archivo']) : null;
            $filas[] = [
                'estatus'    => $estatus,
                'factura'    => 'D' . ($nro - self::SERIE_D),
                'nro'        => $nro,
                'fecha'      => $r['fecha'],
                'codcli'     => (int)($r['codcli'] ?? 0),
                'cliente'    => trim((string)($r['cliente'] ?? '')),
                'total'      => $total,
                'uuid'       => $u !== '' ? $u : ($c ? strtolower(trim((string)$c['uuid'])) : ''),
                'en_onegoal' => $enOg ? 'Sí' : 'No',
                'og_folio'   => $folio ? $folio['serie'] . $folio['folio'] : ($f ? 'D' . $f['numero'] : ''),
                'og_fecha'   => $c ? $c['fecha'] : ($f ? $f['fecha'] : ''),
                'og_cliente' => $c ? trim((string)$c['cliente']) : '',
                'og_total'   => $f ? $f['total'] : null,
                'og_estatus' => $ogEstatus,
                'diferencia' => $f && !$cancelada ? round($total - $f['total'], 2) : null,
                'og_mensaje' => $f ? $f['mensaje'] : '',
            ];
        }

        // Lo que One Goal tiene y ControlGas no: por CFDI (UUID) y, si no hay CFDI, por factura vigente del mes.
        $extra = function (array $base) use (&$filas) { $filas[] = $base; };
        foreach ($ogPorUuid as $u => $c) {
            if (isset($vistos[$u])) continue;
            $f = $facPorId[(int)($c['id_fact'] ?? 0)] ?? null;
            if ($f) $facUsadas[$f['id_fac']] = true;
            $folio = self::folioDeArchivo((string)$c['archivo']);
            $extra(self::filaSoloOneGoal($c['fecha'], $folio ? $folio['serie'] . $folio['folio'] : '', trim((string)$c['cliente']), $u, $f));
        }
        foreach ($fac as $f0) {
            $f = self::normalizarFactura($f0);
            if (isset($facUsadas[$f['id_fac']]) || $f['status'] !== '1') continue;
            if ($desde !== null && $hasta !== null && ($f['fecha'] < $desde || $f['fecha'] > $hasta)) continue;
            $extra(self::filaSoloOneGoal($f['fecha'], 'D' . $f['numero'], '', '', $f));
        }

        usort($filas, fn($a, $b) => [$a['fecha'], $a['nro'], $a['uuid']] <=> [$b['fecha'], $b['nro'], $b['uuid']]);
        return ['filas' => $filas, 'resumen' => self::resumen($filas)];
    }

    private static function filaSoloOneGoal(string $fecha, string $folio, string $cliente, string $uuid, ?array $f): array {
        return [
            'estatus' => self::SOLO_ONEGOAL, 'factura' => $folio, 'nro' => 0, 'fecha' => $fecha, 'codcli' => 0,
            'cliente' => $cliente, 'total' => null, 'uuid' => $uuid, 'en_onegoal' => 'Sí', 'og_folio' => $folio,
            'og_fecha' => $fecha, 'og_cliente' => $cliente, 'og_total' => $f ? $f['total'] : null,
            'og_estatus' => $f ? self::estatusOneGoal($f['status']) : '', 'diferencia' => null, 'og_mensaje' => $f ? $f['mensaje'] : '',
        ];
    }

    /** Fila de imp_fac_enc con tipos fijos. */
    private static function normalizarFactura(array $f): array {
        return [
            'id_fac'     => (int)$f['id_fac'],
            'nro'        => (int)($f['nro'] ?? 0),
            'numero'     => (int)($f['numero'] ?? 0),
            'fecha'      => substr((string)($f['fecha'] ?? ''), 0, 10),
            'sub'        => round((float)($f['sub'] ?? 0), 2),
            'iva'        => round((float)($f['iva'] ?? 0), 2),
            'total'      => round((float)($f['total'] ?? 0), 2),
            'status'     => (string)($f['status'] ?? ''),
            'status_imp' => (string)($f['status_imp'] ?? ''),
            'mensaje'    => trim((string)($f['mensaje'] ?? '')),
            'id_cli'     => (int)($f['id_cli'] ?? 0),
        ];
    }

    /** @return array{0: array, 1: array} facturas indexadas por imp_id_fac_sust (nro ControlGas) y por imp_id_fac */
    private static function indiceFacturas(array $fac): array {
        $porNro = $porId = [];
        foreach ($fac as $f0) {
            $f = self::normalizarFactura($f0);
            if ($f['nro'] > 0) $porNro[$f['nro']] = $f;
            $porId[$f['id_fac']] = $f;
        }
        return [$porNro, $porId];
    }

    /**
     * Lo que One Goal tiene de los anticipos: una fila por CFDI (forma fija, la tabla del tab "One Goal" exige todas las claves).
     * en_controlgas = Sí si su UUID está entre los anticipos de ControlGas del mes ($uuidsCg, en minúsculas).
     */
    public static function filasOneGoal(array $og, array $uuidsCg, array $fac = []): array {
        $cg = array_flip($uuidsCg);
        [, $facPorId] = self::indiceFacturas($fac);
        $filas = [];
        foreach ($og as $r) {
            $u = strtolower(trim((string)$r['uuid']));
            $folio = self::folioDeArchivo((string)($r['archivo'] ?? ''));
            $f = $facPorId[(int)($r['id_fact'] ?? 0)] ?? null;
            $filas[] = [
                'folio'         => $folio ? $folio['serie'] . $folio['folio'] : '',
                'fecha'         => $r['fecha'] ?? '',
                'id_clie'       => (int)($r['id_clie'] ?? 0),
                'cliente'       => trim((string)($r['cliente'] ?? '')),
                'rfc'           => trim((string)($r['rfc'] ?? '')),
                'cp'            => trim((string)($r['cp'] ?? '')),
                'uso_cfdi'      => trim((string)($r['uso_cfdi'] ?? '')),
                'metodo_pago'   => trim((string)($r['metodo_pago'] ?? '')),
                'forma_pago'    => trim((string)($r['forma_pago'] ?? '')),
                'regimen'       => trim((string)($r['regimen'] ?? '')),
                'correo'        => trim((string)($r['correo'] ?? '')),
                'estatus_cfdi'  => (string)($r['estatus_cfdi'] ?? ''),
                'id_fact'       => (int)($r['id_fact'] ?? 0),
                'uuid'          => $u,
                'en_controlgas' => isset($cg[$u]) ? 'Sí' : 'No',
                'archivo'       => trim((string)($r['archivo'] ?? '')),
                'sub'           => $f ? $f['sub'] : null,
                'iva'           => $f ? $f['iva'] : null,
                'total'         => $f ? $f['total'] : null,
                'estatus_og'    => $f ? self::estatusOneGoal($f['status']) : '',
                'id_cli_og'     => $f ? $f['id_cli'] : 0,
                'mensaje'       => $f ? $f['mensaje'] : '',
            ];
        }
        usort($filas, fn($a, $b) => [$a['fecha'], $a['folio']] <=> [$b['fecha'], $b['folio']]);
        return $filas;
    }

    /**
     * Conteo e importe por estatus, rangos de folios que faltan en One Goal y el cuadre de importes.
     * El importe de cada estatus es el de ControlGas (en "Solo en One Goal", el de One Goal).
     */
    public static function resumen(array $filas): array {
        $porEstatus = [];
        foreach ($filas as $f) {
            $e = $f['estatus'];
            $porEstatus[$e] = $porEstatus[$e] ?? ['n' => 0, 'importe' => 0.0];
            $porEstatus[$e]['n']++;
            $imp = $e === self::SOLO_ONEGOAL ? ($f['og_total'] ?? 0) : ($f['total'] ?? 0);
            $porEstatus[$e]['importe'] = round($porEstatus[$e]['importe'] + (float)$imp, 2);
        }
        $faltan = [];
        foreach ($filas as $f) if ($f['estatus'] === self::FALTA) $faltan[] = (int)$f['nro'];
        sort($faltan);
        return ['por_estatus' => $porEstatus, 'rangos_faltantes' => self::rangos($faltan), 'cuadre' => self::cuadre($filas)];
    }

    /**
     * De lo vigente en One Goal al total de ControlGas, paso a paso. Cada línea es [concepto, importe, subtotal]; subtotal
     * es true en las que son un resultado (=). La última siempre es el total de ControlGas y debe coincidir con la suma de sus filas.
     */
    public static function cuadre(array $filas): array {
        $vigOg = 0.0; $cancCgVigOg = 0.0; $soloOg = 0.0; $difImporte = 0.0; $cgVigEnOgCancelada = 0.0; $sinUuidOg = 0.0;
        $falta = 0.0; $sinUuid = 0.0; $cgTotal = 0.0;
        foreach ($filas as $f) {
            $e = $f['estatus'];
            $ogv = $f['og_estatus'] === 'Vigente' ? (float)($f['og_total'] ?? 0) : 0.0;
            $vigOg += $ogv;
            $cgTotal += (float)($f['total'] ?? 0);
            if ($e === self::CANCELADA_OG)       $cancCgVigOg += $ogv;
            elseif ($e === self::SOLO_ONEGOAL)   $soloOg += $ogv;
            elseif ($e === self::IMPORTE_DIST)   $difImporte += (float)$f['total'] - $ogv;
            elseif ($e === self::CANCELADA_EN_OG) $cgVigEnOgCancelada += (float)$f['total'];
            elseif ($e === self::FALTA)          $falta += (float)$f['total'];
            elseif ($e === self::SIN_UUID)       $sinUuid += (float)$f['total'];
            elseif ($e === self::SIN_UUID_CG)  { $sinUuid += (float)$f['total']; $sinUuidOg += $ogv; }
        }
        $r = fn($n) => round($n, 2);
        $sub1 = $vigOg - $cancCgVigOg - $soloOg - $sinUuidOg + $difImporte;   // vigente en ambos lados, a importe de ControlGas
        $sub2 = $sub1 + $cgVigEnOgCancelada + $falta;
        return [
            ['concepto' => 'Vigente en One Goal',                                   'importe' => $r($vigOg),               'subtotal' => false],
            ['concepto' => 'Menos: canceladas en ControlGas, vigentes en One Goal',  'importe' => $r(-$cancCgVigOg),        'subtotal' => false],
            ['concepto' => 'Menos: solo en One Goal (ControlGas no los tiene)',      'importe' => $r(-$soloOg),             'subtotal' => false],
            ['concepto' => 'Menos: sin UUID en ControlGas, vigentes en One Goal',  'importe' => $r(-$sinUuidOg),          'subtotal' => false],
            ['concepto' => 'Más/menos: importe distinto entre sistemas',             'importe' => $r($difImporte),          'subtotal' => false],
            ['concepto' => '= Vigente en ambos, a importe de ControlGas',            'importe' => $r($sub1),                'subtotal' => true],
            ['concepto' => 'Más: vigentes en ControlGas, canceladas en One Goal',    'importe' => $r($cgVigEnOgCancelada),  'subtotal' => false],
            ['concepto' => 'Más: faltan en One Goal',                                'importe' => $r($falta),               'subtotal' => false],
            ['concepto' => '= Vigente en ControlGas con UUID',                       'importe' => $r($sub2),                'subtotal' => true],
            ['concepto' => 'Más: sin UUID en ControlGas',                              'importe' => $r($sinUuid),             'subtotal' => false],
            ['concepto' => '= Total ControlGas',                                     'importe' => $r($sub2 + $sinUuid),     'subtotal' => true],
            ['concepto' => 'Total ControlGas (suma de sus filas)',                   'importe' => $r($cgTotal),             'subtotal' => true],
        ];
    }

    /** [1200243795,…,1200243806, 1200243900] → [['desde'=>'D243795','hasta'=>'D243806','n'=>12], ['desde'=>'D243900','hasta'=>'D243900','n'=>1]] */
    public static function rangos(array $nros): array {
        $rangos = [];
        $ini = $fin = null;
        foreach (array_merge($nros, [null]) as $n) {
            if ($n !== null && $fin !== null && $n === $fin + 1) { $fin = $n; continue; }
            if ($ini !== null) {
                $rangos[] = ['desde' => 'D' . ($ini - self::SERIE_D), 'hasta' => 'D' . ($fin - self::SERIE_D), 'n' => $fin - $ini + 1];
            }
            $ini = $fin = $n;
        }
        return $rangos;
    }
}
