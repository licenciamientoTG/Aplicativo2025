<?php
/**
 * NotasCreditoConsumosModel — cruce de notas de crédito serie G (ControlGas)
 * contra los consumos de clientes débito.
 *
 * Cadena en SG12 (todo corporativo, codgas = 0):
 *   nota G (DocumentosC tip=6, nro 15xxxxxxxx)
 *     → DocumentosA.nroapl = factura E de consumos (13xxxxxxxx)
 *       → Despachos.nrofac = esa factura E
 *
 * Las consultas usan tablas temporales en vez de un solo CTE: con el CTE el
 * optimizador elige un plan que tarda 10–20 s según los valores del rango;
 * con temporales la búsqueda de despachos va por IX_DespachosFac (nrofac)
 * y queda en ~1 s.
 */
class NotasCreditoConsumosModel extends Model {

    /** flgcon de DocumentosC para un documento cancelado. */
    public const FLGCON_CANCELADA = 141;

    /**
     * Una fila por nota G con fecha en el rango: su valor (con IVA), las
     * facturas a las que se aplicó, lo aplicado y la suma de los despachos de
     * esas facturas. otras_notas = cuántas notas G vigentes más comparten
     * alguna de esas facturas (la suma de despachos ya no es solo de esta nota).
     */
    public function notas(int $from, int $until): array {
        $query = "
            SET NOCOUNT ON;
            DECLARE @d int = ?, @h int = ?;
            DROP TABLE IF EXISTS #g, #apl, #fac, #desp, #npf;

            SELECT c.nro, c.fch, c.codopr, c.satuid, c.flgcon, CAST(c.txtref AS nvarchar(500)) AS txtref
              INTO #g
              FROM SG12.dbo.DocumentosC c WITH (NOLOCK)
             WHERE c.codgas = 0 AND c.tip = 6
               AND c.nro BETWEEN 1500000000 AND 1599999999
               AND c.fch BETWEEN @d AND @h
            OPTION (RECOMPILE);

            SELECT a.nro, a.nroapl, -a.mtoapl / 100.0 AS aplicado
              INTO #apl
              FROM #g g
              JOIN SG12.dbo.DocumentosA a WITH (NOLOCK) ON a.nro = g.nro AND a.codgas = 0;

            SELECT DISTINCT nroapl INTO #fac FROM #apl;

            SELECT t.nrofac, COUNT(*) AS n, SUM(t.can) AS litros, SUM(t.mto) AS monto
              INTO #desp
              FROM #fac f
              JOIN SG12.dbo.Despachos t WITH (NOLOCK) ON t.nrofac = f.nroapl
             GROUP BY t.nrofac;

            SELECT o.nroapl, COUNT(DISTINCT o.nro) AS notas
              INTO #npf
              FROM #fac f
              JOIN SG12.dbo.DocumentosA o WITH (NOLOCK) ON o.nroapl = f.nroapl AND o.codgas = 0
              JOIN SG12.dbo.DocumentosC oc WITH (NOLOCK)
                ON oc.nro = o.nro AND oc.codgas = 0 AND oc.tip = 6 AND oc.flgcon <> " . self::FLGCON_CANCELADA . "
             GROUP BY o.nroapl;

            -- Las notas de aplicación de anticipo guardan el importe negativo y
            -- las de bonificación positivo; el valor de la nota es el absoluto.
            WITH tot AS (
                SELECT d.nro, ABS(SUM(d.mto + d.mtoiva)) / 100.0 AS total
                  FROM #g g
                  JOIN SG12.dbo.Documentos d WITH (NOLOCK) ON d.nro = g.nro AND d.codgas = 0 AND d.tip = 6
                 WHERE d.nroitm > 0
                 GROUP BY d.nro
            ),
            porNota AS (
                SELECT a.nro,
                       STRING_AGG(CAST(a.nroapl AS varchar(12)), ',') AS facturas,
                       SUM(a.aplicado)                    AS aplicado,
                       SUM(ISNULL(ds.n, 0))               AS despachos,
                       SUM(ISNULL(ds.litros, 0))          AS litros,
                       SUM(ISNULL(ds.monto, 0))           AS monto_despachos,
                       MAX(ISNULL(np.notas, 1) - 1)       AS otras_notas
                  FROM #apl a
                  LEFT JOIN #desp ds ON ds.nrofac = a.nroapl
                  LEFT JOIN #npf np  ON np.nroapl = a.nroapl
                 GROUP BY a.nro
            )
            SELECT g.nro,
                   CONVERT(varchar(10), DATEADD(day, g.fch - 1, '19000101'), 23) AS fecha,
                   g.codopr, cl.den AS cliente, g.satuid, g.flgcon, g.txtref,
                   ISNULL(t.total, 0)            AS total,
                   pn.facturas,
                   ISNULL(pn.aplicado, 0)        AS aplicado,
                   ISNULL(pn.despachos, 0)       AS despachos,
                   ISNULL(pn.litros, 0)          AS litros,
                   ISNULL(pn.monto_despachos, 0) AS monto_despachos,
                   ISNULL(pn.otras_notas, 0)     AS otras_notas
              FROM #g g
              LEFT JOIN tot t      ON t.nro = g.nro
              LEFT JOIN porNota pn ON pn.nro = g.nro
              LEFT JOIN SG12.dbo.Clientes cl WITH (NOLOCK) ON cl.cod = g.codopr
             ORDER BY g.fch DESC, g.nro DESC;

            DROP TABLE IF EXISTS #g, #apl, #fac, #desp, #npf;";
        return $this->sql->select($query, [$from, $until]) ?: [];
    }

    /**
     * Despachos de clientes débito (Clientes.tipval = 4) del rango con la nota
     * G vigente que los cubre. estado:
     *   SIN_FACTURA  despacho sin factura
     *   E_SIN_NOTA   facturado en una E que ninguna nota G vigente aplica
     *   OTRA_SERIE   facturado en otra serie (contado, global...)
     *   LIGADO       su factura E tiene nota G
     * $soloSinNota deja solo SIN_FACTURA y E_SIN_NOTA.
     */
    public function despachos_debito(int $from, int $until, bool $soloSinNota): array {
        $query = "
            SET NOCOUNT ON;
            DECLARE @d int = ?, @h int = ?, @solo bit = ?;
            DROP TABLE IF EXISTS #d, #fac, #n;

            SELECT t.codgas, t.nrotrn, t.fchtrn, t.hratrn, t.codcli, t.codprd, t.can, t.mto, t.nrofac
              INTO #d
              FROM SG12.dbo.Despachos t WITH (NOLOCK)
              JOIN SG12.dbo.Clientes c WITH (NOLOCK) ON c.cod = t.codcli AND c.tipval = 4
             WHERE t.fchtrn BETWEEN @d AND @h AND t.mto > 0
            OPTION (RECOMPILE);

            SELECT DISTINCT nrofac INTO #fac FROM #d WHERE nrofac BETWEEN 1300000000 AND 1399999999;

            SELECT a.nroapl, STRING_AGG(CAST(a.nro AS varchar(12)), ',') AS notas
              INTO #n
              FROM #fac f
              JOIN SG12.dbo.DocumentosA a WITH (NOLOCK) ON a.nroapl = f.nrofac AND a.codgas = 0
              JOIN SG12.dbo.DocumentosC g WITH (NOLOCK)
                ON g.nro = a.nro AND g.codgas = 0 AND g.tip = 6 AND g.flgcon <> " . self::FLGCON_CANCELADA . "
             GROUP BY a.nroapl;

            SELECT x.*
              FROM (
                SELECT gs.abr AS estacion, d.codgas, d.nrotrn,
                       CONVERT(varchar(10), DATEADD(day, d.fchtrn - 1, '19000101'), 23) AS fecha,
                       d.hratrn, d.codcli, cl.den AS cliente, p.den AS producto,
                       d.can, d.mto, d.nrofac, n.notas,
                       CASE WHEN ISNULL(d.nrofac, 0) = 0 THEN 'SIN_FACTURA'
                            WHEN d.nrofac NOT BETWEEN 1300000000 AND 1399999999 THEN 'OTRA_SERIE'
                            WHEN n.notas IS NULL THEN 'E_SIN_NOTA'
                            ELSE 'LIGADO' END AS estado
                  FROM #d d
                  LEFT JOIN #n n ON n.nroapl = d.nrofac
                  LEFT JOIN SG12.dbo.Gasolineras gs ON gs.cod = d.codgas
                  LEFT JOIN SG12.dbo.Clientes cl WITH (NOLOCK) ON cl.cod = d.codcli
                  LEFT JOIN SG12.dbo.Productos p ON p.cod = d.codprd
              ) x
             WHERE @solo = 0 OR x.estado IN ('SIN_FACTURA', 'E_SIN_NOTA')
             ORDER BY x.fecha DESC, x.cliente, x.hratrn;

            DROP TABLE IF EXISTS #d, #fac, #n;";
        return $this->sql->select($query, [$from, $until, $soloSinNota ? 1 : 0]) ?: [];
    }
}
