<?php

/**
 * Consulta de los CFDI que ApiTotal importa de la descarga masiva del SAT:
 * TGV2.dbo.FacturasRecibidas (la empresa es el receptor) y TGV2.dbo.Facturas
 * (emitidas: la empresa es el emisor). La "contraparte" es el proveedor en las
 * recibidas y el cliente en las emitidas.
 *
 * Todas las lecturas van WITH (NOLOCK): es solo consulta y la importación de ApiTotal
 * (varias consolas en paralelo) bloquearía la tabla por minutos en READ COMMITTED.
 *
 * No confundir con TG.dbo.FacturasRecibidas, que llena el flujo de correos:
 * "en correo" = el UUID de una recibida también existe ahí (en emitidas no aplica).
 *
 * Filtros ($f): sentido (R/E/ambas), rfc (empresa), desde/hasta (Y-m-d, obligatorios),
 * tipo (I/E/P/T), contraparte (RFC o parte del nombre), texto (UUID, folio o serie),
 * correo (si/no, solo recibidas).
 */
class SatFacturasModel extends Model
{
    public const TIPOS = ['I' => 'Ingreso', 'E' => 'Egreso', 'P' => 'Pago', 'T' => 'Traslado'];

    // R = recibidas, E = emitidas: tabla, columna de la empresa y de la contraparte
    private const FUENTES = [
        'R' => ['tabla' => 'FacturasRecibidas', 'empresa' => 'Receptor', 'contra' => 'Emisor'],
        'E' => ['tabla' => 'Facturas',          'empresa' => 'Emisor',   'contra' => 'Receptor'],
    ];

    // Columnas que la tabla puede ordenar (índice de DataTables => alias de la consulta unida)
    private const ORDEN = [
        0 => 'q.Fecha', 1 => 'q.sentido', 2 => 'q.empresa_rfc', 3 => 'q.contra_rfc', 4 => 'q.contra_nombre',
        5 => 'q.TipoDeComprobante', 6 => 'q.Serie', 7 => 'q.Folio', 8 => 'q.UUID', 9 => 'q.MetodoPago',
        10 => 'q.FormaPago', 11 => 'q.SubTotal', 12 => 'q.Total',
    ];

    // Columnas agregadas por ApiTotal/database/sql/2026-10-08-facturas-global-ruta.sql
    private const EXTRA = ['GlobalPeriodicidad', 'GlobalMeses', 'GlobalAnio', 'FechaImportacion', 'RutaArchivo'];
    private static ?bool $extraListas = null;

    /** Columnas de InformacionGlobal/importación; NULL mientras no se corra el DDL en TGV2. */
    private function columnasExtra(): string
    {
        if (self::$extraListas === null) {
            $r = $this->sql->select(
                "SELECT COUNT(*) AS n FROM [TGV2].sys.columns
                  WHERE object_id IN (OBJECT_ID('TGV2.dbo.Facturas'), OBJECT_ID('TGV2.dbo.FacturasRecibidas'))
                    AND name IN ('" . implode("','", self::EXTRA) . "')"
            );
            self::$extraListas = (int)($r[0]['n'] ?? 0) === count(self::EXTRA) * 2;
        }
        return implode('', array_map(
            fn($c) => self::$extraListas ? ", fr.{$c}" : ", CAST(NULL AS nvarchar(400)) AS {$c}",
            self::EXTRA
        ));
    }

    private static function tabla(string $s): string
    {
        return '[TGV2].[dbo].[' . self::FUENTES[$s]['tabla'] . ']';
    }

    private static function enCorreo(string $s): string
    {
        return $s === 'R'
            ? 'CASE WHEN EXISTS (SELECT 1 FROM [TG].[dbo].[FacturasRecibidas] tg WITH (NOLOCK) WHERE tg.UUID = fr.UUID) THEN 1 ELSE 0 END'
            : 'CAST(NULL AS int)';
    }

    /** Sentidos a consultar: el filtro "en correo" solo existe en las recibidas. */
    private static function sentidos(array $f): array
    {
        if (in_array($f['correo'] ?? '', ['si', 'no'], true)) return ['R'];
        return ($f['sentido'] ?? 'R') === 'ambas' ? ['R', 'E'] : [($f['sentido'] ?? 'R') === 'E' ? 'E' : 'R'];
    }

    /** @return array{0:string,1:array} WHERE de un sentido y sus parámetros */
    private function where(string $s, array $f, array $rfcsValidos): array
    {
        $emp = self::FUENTES[$s]['empresa'];
        $con = self::FUENTES[$s]['contra'];
        $w = ['fr.Fecha >= ?', 'fr.Fecha < DATEADD(DAY, 1, ?)'];
        $p = [$f['desde'], $f['hasta']];

        // Siempre se limita a las empresas propias (en emitidas el SAT solo baja las nuestras,
        // pero así una empresa filtrada nunca trae CFDI de otra)
        $rfcs = array_values(array_intersect((array)($f['rfc'] ?? []), $rfcsValidos)) ?: $rfcsValidos;
        $w[] = "fr.{$emp}Rfc IN (" . implode(',', array_fill(0, count($rfcs), '?')) . ')';
        array_push($p, ...$rfcs);

        if (!empty($f['tipo']) && isset(self::TIPOS[$f['tipo']])) {
            $w[] = 'fr.TipoDeComprobante = ?';
            $p[] = $f['tipo'];
        }
        if (($f['contraparte'] ?? '') !== '') {
            $w[] = "(fr.{$con}Rfc = ? OR fr.{$con}Nombre LIKE ?)";
            array_push($p, $f['contraparte'], '%' . $f['contraparte'] . '%');
        }
        if (($f['texto'] ?? '') !== '') {
            $w[] = '(fr.UUID LIKE ? OR fr.Folio = ? OR fr.Serie + fr.Folio = ?)';
            array_push($p, '%' . $f['texto'] . '%', $f['texto'], $f['texto']);
        }
        if ($s === 'R' && in_array($f['correo'] ?? '', ['si', 'no'], true)) {
            $w[] = ($f['correo'] === 'no' ? 'NOT ' : '') . 'EXISTS (SELECT 1 FROM [TG].[dbo].[FacturasRecibidas] tg WITH (NOLOCK) WHERE tg.UUID = fr.UUID)';
        }
        return ['WHERE ' . implode(' AND ', $w), $p];
    }

    /**
     * Une los sentidos pedidos en una sola consulta con columnas comunes.
     * @param string $extra columnas adicionales (con alias fr.) que se necesiten
     * @return array{0:string,1:array}
     */
    private function union(array $f, array $rfcs, string $extra = ''): array
    {
        $partes = [];
        $params = [];
        foreach (self::sentidos($f) as $s) {
            $emp = self::FUENTES[$s]['empresa'];
            $con = self::FUENTES[$s]['contra'];
            [$where, $p] = $this->where($s, $f, $rfcs);
            $partes[] = "SELECT '{$s}' AS sentido, fr.Id, fr.Fecha, fr.{$emp}Rfc AS empresa_rfc, fr.{$con}Rfc AS contra_rfc,
                                fr.{$con}Nombre AS contra_nombre, fr.TipoDeComprobante, fr.Serie, fr.Folio, fr.UUID,
                                fr.MetodoPago, fr.FormaPago, fr.Moneda, fr.SubTotal, fr.Total, "
                        . self::enCorreo($s) . " AS en_correo{$extra}
                           FROM " . self::tabla($s) . " fr WITH (NOLOCK) {$where}";
            array_push($params, ...$p);
        }
        return ['(' . implode(' UNION ALL ', $partes) . ') q', $params];
    }

    public function contar(array $f, array $rfcs): int
    {
        [$q, $p] = $this->union($f, $rfcs);
        $r = $this->sql->select("SELECT COUNT(*) AS n FROM {$q}", $p);
        return (int)($r[0]['n'] ?? 0);
    }

    public function listar(array $f, array $rfcs, int $start, int $length, int $col, string $dir): array
    {
        [$q, $p] = $this->union($f, $rfcs);
        $orden  = self::ORDEN[$col] ?? 'q.Fecha';
        $dir    = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';
        $start  = max(0, $start);
        $length = min(500, max(1, $length));
        return $this->sql->select(
            "SELECT * FROM {$q} ORDER BY {$orden} {$dir}, q.sentido, q.Id DESC
              OFFSET {$start} ROWS FETCH NEXT {$length} ROWS ONLY",
            $p
        ) ?: [];
    }

    /** Totales por sentido y tipo, y las 10 contrapartes con más monto (solo ingresos). */
    public function resumen(array $f, array $rfcs): array
    {
        [$q, $p] = $this->union($f, $rfcs);
        $porTipo = $this->sql->select(
            "SELECT q.sentido, q.TipoDeComprobante AS tipo, COUNT(*) AS cantidad, SUM(q.Total) AS total, SUM(q.en_correo) AS en_correo
               FROM {$q} GROUP BY q.sentido, q.TipoDeComprobante",
            $p
        ) ?: [];
        $contrapartes = $this->sql->select(
            "SELECT TOP 10 q.sentido, q.contra_rfc AS rfc, MAX(q.contra_nombre) AS nombre, COUNT(*) AS cantidad, SUM(q.Total) AS total
               FROM {$q} WHERE q.TipoDeComprobante = 'I'
              GROUP BY q.sentido, q.contra_rfc
              ORDER BY SUM(q.Total) DESC",
            $p
        ) ?: [];
        return ['por_tipo' => $porTipo, 'contrapartes' => $contrapartes];
    }

    /** Filas para el Excel (sin paginar), con tope para no reventar memoria. */
    public function exportar(array $f, array $rfcs, int $tope): array
    {
        [$q, $p] = $this->union($f, $rfcs, ', fr.FechaTimbrado, fr.UsoCFDI, fr.TipoCambio, fr.TotalImpuestosTrasladados,
                                              fr.TotalImpuestosRetenidos, fr.EmisorRfc, fr.EmisorNombre, fr.ReceptorRfc, fr.ReceptorNombre'
                                              . $this->columnasExtra());
        return $this->sql->select("SELECT TOP ({$tope}) * FROM {$q} ORDER BY q.Fecha, q.sentido, q.Id", $p) ?: [];
    }

    /** CFDI con todo su detalle para el modal. $s = 'R' recibida, 'E' emitida. */
    public function detalle(string $s, int $id): ?array
    {
        if (!isset(self::FUENTES[$s])) return null;
        $T   = self::tabla($s);
        $pre = '[TGV2].[dbo].[' . self::FUENTES[$s]['tabla'];   // prefijo de las tablas de detalle

        $f = $this->sql->select(
            'SELECT fr.Id, fr.Folio, fr.Serie, fr.Fecha, fr.FormaPago, fr.MetodoPago, fr.TipoCambio, fr.Moneda, fr.SubTotal,
                    fr.Total, fr.TipoDeComprobante, fr.LugarExpedicion, fr.EmisorNombre, fr.EmisorRfc, fr.EmisorRegimenFiscal,
                    fr.ReceptorNombre, fr.ReceptorRfc, fr.ReceptorRegimenFiscal, fr.UsoCFDI, fr.FechaTimbrado, fr.UUID,
                    fr.TotalImpuestosTrasladados, fr.TotalImpuestosRetenidos, '
                    . self::enCorreo($s) . ' AS en_correo' . $this->columnasExtra() . "
               FROM {$T} fr WITH (NOLOCK) WHERE fr.Id = ?",
            [$id]
        );
        if (!$f) return null;
        $f = $f[0];
        $f['sentido'] = $s;
        $uuid = $f['UUID'];

        $f['conceptos'] = $this->sql->select(
            "SELECT TOP 500 Cantidad, ClaveProdServ, ClaveUnidad, Unidad, NoIdentificacion, Descripcion, ValorUnitario, Importe,
                    Impuesto, TasaOCuota, ImporteImpuesto
               FROM {$pre}Conceptos] WITH (NOLOCK) WHERE FacturaId = ? ORDER BY Id",
            [$id]
        ) ?: [];
        $f['impuestos'] = $this->sql->select(
            "SELECT TipoImpuesto, Impuesto, TipoFactor, TasaOCuota, Base, Importe
               FROM {$pre}Impuestos] WITH (NOLOCK) WHERE FacturaId = ? ORDER BY Id",
            [$id]
        ) ?: [];
        // CFDI que este relaciona y los del mismo sentido que lo relacionan a él (p. ej. notas de crédito)
        $f['relacionados'] = $this->sql->select(
            "SELECT r.TipoRelacion, r.UUID, o.Id AS otro_id, o.TipoDeComprobante, o.Serie, o.Folio, o.Fecha, o.Total
               FROM {$pre}CfdiRelacionados] r WITH (NOLOCK)
               LEFT JOIN {$T} o WITH (NOLOCK) ON o.UUID = r.UUID
              WHERE r.FacturaId = ?",
            [$id]
        ) ?: [];
        $f['relacionada_por'] = $this->sql->select(
            "SELECT r.TipoRelacion, o.Id AS otro_id, o.UUID, o.TipoDeComprobante, o.Serie, o.Folio, o.Fecha, o.Total
               FROM {$pre}CfdiRelacionados] r WITH (NOLOCK)
               JOIN {$T} o WITH (NOLOCK) ON o.Id = r.FacturaId
              WHERE r.UUID = ?",
            [$uuid]
        ) ?: [];
        // Complementos de pago (tipo P) que abonan a este CFDI
        $f['abonos'] = $this->sql->select(
            "SELECT p.FechaPago, p.FormaDePagoP, d.NumParcialidad, d.ImpSaldoAnt, d.ImpPagado, d.ImpSaldoInsoluto,
                    o.Id AS otro_id, o.UUID, o.Serie, o.Folio
               FROM {$pre}PagosDoctosRelacionados] d WITH (NOLOCK)
               JOIN {$pre}Pagos] p WITH (NOLOCK) ON p.Id = d.PagoId
               JOIN {$T} o WITH (NOLOCK) ON o.Id = p.FacturaId
              WHERE d.IdDocumento = ?
              ORDER BY p.FechaPago, d.NumParcialidad",
            [$uuid]
        ) ?: [];
        // Si es un complemento de pago: qué pagó
        $f['pagos'] = $f['TipoDeComprobante'] === 'P' ? ($this->sql->select(
            "SELECT p.FechaPago, p.FormaDePagoP, p.MonedaP, p.Monto, p.NumOperacion, d.IdDocumento, d.Serie, d.Folio,
                    d.NumParcialidad, d.ImpSaldoAnt, d.ImpPagado, d.ImpSaldoInsoluto, o.Id AS otro_id
               FROM {$pre}Pagos] p WITH (NOLOCK)
               LEFT JOIN {$pre}PagosDoctosRelacionados] d WITH (NOLOCK) ON d.PagoId = p.Id
               LEFT JOIN {$T} o WITH (NOLOCK) ON o.UUID = d.IdDocumento
              WHERE p.FacturaId = ?
              ORDER BY p.FechaPago, p.Id, d.Id",
            [$id]
        ) ?: []) : [];
        return $f;
    }
}
