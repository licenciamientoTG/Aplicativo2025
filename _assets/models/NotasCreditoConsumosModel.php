<?php
/**
 * NotasCreditoConsumosModel — cruce de notas de crédito serie G (ControlGas)
 * contra los consumos de clientes débito.
 *
 * Cadena en SG12:
 *   nota G (DocumentosC tip=6, nro 15xxxxxxxx, en codgas)
 *     → DocumentosA (nro, codgas) → (nroapl, gasapl) = factura E de consumos
 *       → Despachos (nrofac, gasfac) = esa factura E
 *
 * El folio solo NO identifica un documento: DIAZ GAS factura desde el
 * corporativo (codgas/gasfac = 0) y las demás razones sociales desde la propia
 * estación, y los folios se repiten entre ellas (p. ej. E 1300003841 existe en
 * codgas 0 y en codgas 24). Todo el cruce va por estación + folio.
 *
 * Las consultas usan tablas temporales en vez de un solo CTE: con el CTE el
 * optimizador elige un plan que tarda 10–20 s según los valores del rango;
 * con temporales la búsqueda de despachos va por IX_DespachosFac
 * (nrofac, gasfac) y queda en ~1–2 s.
 */
class NotasCreditoConsumosModel extends Model {

    /** flgcon de DocumentosC para un documento cancelado. */
    public const FLGCON_CANCELADA = 141;

    /**
     * Una fila por nota G con fecha en el rango (corporativo y estaciones): su
     * valor (con IVA), las facturas a las que se aplicó, lo aplicado y la suma
     * de los despachos de esas facturas. otras_notas = cuántas notas G vigentes
     * más comparten alguna de esas facturas (la suma de despachos ya no es solo
     * de esta nota).
     * Razón social = empresa de la estación que emitió la nota; para las del
     * corporativo, la de las estaciones de sus despachos (como /income/clients).
     * $empresa: 0 = todas, -1 = sin razón social, >0 = SG12.Empresas.cod.
     */
    public function notas(int $from, int $until, int $empresa = 0): array {
        $query = "
            SET NOCOUNT ON;
            DECLARE @d int = ?, @h int = ?, @emp int = ?;
            DROP TABLE IF EXISTS #g, #apl, #fac, #desp, #npf, #nemp;

            SELECT c.codgas, c.nro, c.fch, c.codopr, c.satuid, c.flgcon, CAST(c.txtref AS nvarchar(500)) AS txtref
              INTO #g
              FROM SG12.dbo.DocumentosC c WITH (NOLOCK)
             WHERE c.tip = 6
               AND c.nro BETWEEN 1500000000 AND 1599999999
               AND c.fch BETWEEN @d AND @h
            OPTION (RECOMPILE);

            SELECT a.codgas, a.nro, a.gasapl, a.nroapl, -a.mtoapl / 100.0 AS aplicado
              INTO #apl
              FROM #g g
              JOIN SG12.dbo.DocumentosA a WITH (NOLOCK) ON a.nro = g.nro AND a.codgas = g.codgas;

            SELECT DISTINCT gasapl, nroapl INTO #fac FROM #apl;

            SELECT t.gasfac, t.nrofac, COUNT(*) AS n, SUM(t.can) AS litros, SUM(t.mto) AS monto
              INTO #desp
              FROM #fac f
              JOIN SG12.dbo.Despachos t WITH (NOLOCK) ON t.nrofac = f.nroapl AND t.gasfac = f.gasapl
             GROUP BY t.gasfac, t.nrofac;

            SELECT o.gasapl, o.nroapl, COUNT(*) AS notas
              INTO #npf
              FROM #fac f
              JOIN SG12.dbo.DocumentosA o WITH (NOLOCK) ON o.nroapl = f.nroapl AND o.gasapl = f.gasapl
              JOIN SG12.dbo.DocumentosC oc WITH (NOLOCK)
                ON oc.nro = o.nro AND oc.codgas = o.codgas AND oc.tip = 6 AND oc.flgcon <> " . self::FLGCON_CANCELADA . "
             GROUP BY o.gasapl, o.nroapl;

            -- Razones sociales de cada nota: la estación emisora, o (corporativo)
            -- las estaciones de sus despachos.
            SELECT DISTINCT x.codgas, x.nro, x.codemp
              INTO #nemp
              FROM (
                SELECT g.codgas, g.nro, E.codemp
                  FROM #g g
                  JOIN [TG].dbo.Estaciones E WITH (NOLOCK) ON E.Codigo = g.codgas
                 WHERE g.codgas <> 0
                UNION ALL
                SELECT a.codgas, a.nro, E.codemp
                  FROM #apl a
                  JOIN SG12.dbo.Despachos t WITH (NOLOCK) ON t.nrofac = a.nroapl AND t.gasfac = a.gasapl
                  JOIN [TG].dbo.Estaciones E WITH (NOLOCK) ON E.Codigo = t.codgas
                 WHERE a.codgas = 0
              ) x
             WHERE x.codemp IS NOT NULL;

            -- Las notas de aplicación de anticipo guardan el importe negativo y
            -- las de bonificación positivo; el valor de la nota es el absoluto.
            WITH tot AS (
                SELECT d.codgas, d.nro, ABS(SUM(d.mto + d.mtoiva)) / 100.0 AS total
                  FROM #g g
                  JOIN SG12.dbo.Documentos d WITH (NOLOCK) ON d.nro = g.nro AND d.codgas = g.codgas AND d.tip = 6
                 WHERE d.nroitm > 0
                 GROUP BY d.codgas, d.nro
            ),
            porNota AS (
                SELECT a.codgas, a.nro,
                       STRING_AGG(CAST(a.nroapl AS varchar(12)), ',') AS facturas,
                       SUM(a.aplicado)                    AS aplicado,
                       SUM(ISNULL(ds.n, 0))               AS despachos,
                       SUM(ISNULL(ds.litros, 0))          AS litros,
                       SUM(ISNULL(ds.monto, 0))           AS monto_despachos,
                       MAX(ISNULL(np.notas, 1) - 1)       AS otras_notas
                  FROM #apl a
                  LEFT JOIN #desp ds ON ds.nrofac = a.nroapl AND ds.gasfac = a.gasapl
                  LEFT JOIN #npf np  ON np.nroapl = a.nroapl AND np.gasapl = a.gasapl
                 GROUP BY a.codgas, a.nro
            )
            SELECT g.codgas, gs.abr AS estacion, g.nro,
                   CONVERT(varchar(10), DATEADD(day, g.fch - 1, '19000101'), 23) AS fecha,
                   g.codopr, cl.den AS cliente, g.satuid, g.flgcon, g.txtref,
                   ISNULL(t.total, 0)            AS total,
                   pn.facturas,
                   ISNULL(pn.aplicado, 0)        AS aplicado,
                   ISNULL(pn.despachos, 0)       AS despachos,
                   ISNULL(pn.litros, 0)          AS litros,
                   ISNULL(pn.monto_despachos, 0) AS monto_despachos,
                   ISNULL(pn.otras_notas, 0)     AS otras_notas,
                   (SELECT STRING_AGG(emp.den, ', ') FROM #nemp ne
                      JOIN SG12.dbo.Empresas emp WITH (NOLOCK) ON emp.cod = ne.codemp
                     WHERE ne.nro = g.nro AND ne.codgas = g.codgas) AS razon_social
              FROM #g g
              LEFT JOIN tot t      ON t.nro = g.nro AND t.codgas = g.codgas
              LEFT JOIN porNota pn ON pn.nro = g.nro AND pn.codgas = g.codgas
              LEFT JOIN SG12.dbo.Clientes cl WITH (NOLOCK) ON cl.cod = g.codopr
              LEFT JOIN SG12.dbo.Gasolineras gs ON gs.cod = g.codgas
             WHERE @emp = 0
                OR (@emp = -1 AND NOT EXISTS (SELECT 1 FROM #nemp ne WHERE ne.nro = g.nro AND ne.codgas = g.codgas))
                OR EXISTS (SELECT 1 FROM #nemp ne WHERE ne.nro = g.nro AND ne.codgas = g.codgas AND ne.codemp = @emp)
             ORDER BY g.fch DESC, g.codgas, g.nro DESC;

            DROP TABLE IF EXISTS #g, #apl, #fac, #desp, #npf, #nemp;";
        return $this->sql->select($query, [$from, $until, $empresa]) ?: [];
    }

    /**
     * Despachos de clientes débito (Clientes.tipval = 4) del rango con la nota
     * G vigente que los cubre. Mismo universo que /income/clients "Consumos
     * Débito" (ClientesModel::get_dispatches_by_client): codcli > 0, sin filtrar
     * monto, razón social = empresa de la estación. estado:
     *   MONTO_CERO   despacho en $0 (sin litros ni factura)
     *   SIN_FACTURA  despacho sin factura
     *   E_SIN_NOTA   facturado en una E que ninguna nota G vigente aplica
     *   OTRA_SERIE   facturado en otra serie (contado, global...)
     *   LIGADO       su factura E tiene nota G
     * $soloSinNota deja solo SIN_FACTURA y E_SIN_NOTA.
     * $empresa: 0 = todas, -1 = estaciones sin razón social, >0 = SG12.Empresas.cod.
     */
    public function despachos_debito(int $from, int $until, bool $soloSinNota, int $empresa = 0): array {
        $query = "
            SET NOCOUNT ON;
            DECLARE @d int = ?, @h int = ?, @solo bit = ?, @emp int = ?;
            DROP TABLE IF EXISTS #d, #fac, #n;

            SELECT t.codgas, t.nrotrn, t.fchtrn, t.hratrn, t.codcli, t.codprd, t.can, t.mto, t.nrofac, t.gasfac,
                   EMP.den AS razon_social
              INTO #d
              FROM SG12.dbo.Despachos t WITH (NOLOCK)
              JOIN SG12.dbo.Clientes c WITH (NOLOCK) ON c.cod = t.codcli AND c.tipval = 4
              LEFT JOIN [TG].dbo.Estaciones E WITH (NOLOCK) ON E.Codigo = t.codgas
              LEFT JOIN SG12.dbo.Empresas EMP WITH (NOLOCK) ON EMP.cod = E.codemp
             WHERE t.fchtrn BETWEEN @d AND @h AND t.codcli > 0
               AND (@emp = 0 OR (@emp = -1 AND EMP.cod IS NULL) OR EMP.cod = @emp)
            OPTION (RECOMPILE);

            SELECT DISTINCT gasfac, nrofac INTO #fac FROM #d WHERE nrofac BETWEEN 1300000000 AND 1399999999;

            SELECT a.gasapl, a.nroapl, STRING_AGG(CAST(a.nro AS varchar(12)), ',') AS notas
              INTO #n
              FROM #fac f
              JOIN SG12.dbo.DocumentosA a WITH (NOLOCK) ON a.nroapl = f.nrofac AND a.gasapl = f.gasfac
              JOIN SG12.dbo.DocumentosC g WITH (NOLOCK)
                ON g.nro = a.nro AND g.codgas = a.codgas AND g.tip = 6 AND g.flgcon <> " . self::FLGCON_CANCELADA . "
             GROUP BY a.gasapl, a.nroapl;

            SELECT x.*
              FROM (
                SELECT gs.abr AS estacion, d.codgas, d.nrotrn,
                       CONVERT(varchar(10), DATEADD(day, d.fchtrn - 1, '19000101'), 23) AS fecha,
                       d.hratrn, d.codcli, cl.den AS cliente, p.den AS producto,
                       d.can, d.mto, d.nrofac, n.notas, ISNULL(d.razon_social, '(Sin razón social)') AS razon_social,
                       CASE WHEN ISNULL(d.nrofac, 0) = 0 AND d.mto <= 0 THEN 'MONTO_CERO'
                            WHEN ISNULL(d.nrofac, 0) = 0 THEN 'SIN_FACTURA'
                            WHEN d.nrofac NOT BETWEEN 1300000000 AND 1399999999 THEN 'OTRA_SERIE'
                            WHEN n.notas IS NULL THEN 'E_SIN_NOTA'
                            ELSE 'LIGADO' END AS estado
                  FROM #d d
                  LEFT JOIN #n n ON n.nroapl = d.nrofac AND n.gasapl = d.gasfac
                  LEFT JOIN SG12.dbo.Gasolineras gs ON gs.cod = d.codgas
                  LEFT JOIN SG12.dbo.Clientes cl WITH (NOLOCK) ON cl.cod = d.codcli
                  LEFT JOIN SG12.dbo.Productos p ON p.cod = d.codprd
              ) x
             WHERE @solo = 0 OR x.estado IN ('SIN_FACTURA', 'E_SIN_NOTA')
             ORDER BY x.fecha DESC, x.cliente, x.hratrn;

            DROP TABLE IF EXISTS #d, #fac, #n;";
        return $this->sql->select($query, [$from, $until, $soloSinNota ? 1 : 0, $empresa]) ?: [];
    }
}
