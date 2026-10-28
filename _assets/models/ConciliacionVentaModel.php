<?php
/**
 * ConciliacionVentaModel — Ingresos > Conciliación: venta a clientes de crédito
 * contra la facturación de ControlGas, y la cobranza contra los bancos.
 * Diseño: docs/superpowers/specs/2026-10-24-conciliacion-venta-credito-design.md
 *
 * Fechas: enteros de ControlGas (dateToInt), los mismos para Despachos.fchtrn,
 * DocumentosC.fch y ValesR.fch (46234 = 2026-08-01).
 *
 * "Facturado" se mide por DESPACHO: un despacho del mes está facturado si su
 * factura (Despachos.nrofac/gasfac → DocumentosC tip=3) existe y no está
 * cancelada, sin importar la fecha de la factura. Así venta y facturado
 * comparan los mismos despachos y no hay que adivinar el periodo por el texto
 * "Consumos del…" de la factura.
 *
 * $empresa: 0 = todas, -1 = estaciones sin razón social, >0 = SG12.Empresas.cod
 * (razón social de la estación, igual que /income/clients).
 *
 * Las consultas usan tablas temporales + OPTION (RECOMPILE), como
 * NotasCreditoConsumosModel: con APPLY/CTE directo sobre Despachos el plan
 * tardaba minutos.
 */
class ConciliacionVentaModel extends Model {

    /** SG12.EfectosC: subope de "Facturacion Credito" (tip 3). */
    public const SUBOPE_FACTURA_CREDITO = 4;
    /** SG12.EfectosC: subope de "Cobranza a Clientes" (tip 4), lo que trae el reporte de cobros. */
    public const SUBOPE_COBRANZA = 8;
    /** ValesR.codval del crédito en los cortes. */
    public const CODVAL_CREDITO = 28;

    /** Filtro de razón social sobre el alias de SG12.Empresas ya unido (EMP). */
    private const EMP_FILTER = "(@emp = 0 OR (@emp = -1 AND EMP.cod IS NULL) OR EMP.cod = @emp)";

    /**
     * Una fila por cliente de crédito con despachos (o cortes) en el rango:
     * despachos, monto, cortes (ValesR) y cuánto de ese monto ya está facturado
     * (en el mes de la factura o después), sin factura o con factura cancelada.
     */
    public function venta_por_cliente(int $from, int $until, int $empresa): array {
        $emp = self::EMP_FILTER;
        $codval = self::CODVAL_CREDITO;
        $query = "
            SET NOCOUNT ON;
            DECLARE @d int = ?, @h int = ?, @emp int = ?;
            DROP TABLE IF EXISTS #d, #fk, #f, #r;

            SELECT t.codgas, t.nrotrn, t.codcli, t.can, t.mto, ISNULL(t.nrofac, 0) AS nrofac, t.gasfac
              INTO #d
              FROM SG12.dbo.Despachos t WITH (NOLOCK)
              JOIN SG12.dbo.Clientes c WITH (NOLOCK) ON c.cod = t.codcli AND c.tipval = 3
              LEFT JOIN TG.dbo.Estaciones E WITH (NOLOCK) ON E.Codigo = t.codgas
              LEFT JOIN SG12.dbo.Empresas EMP WITH (NOLOCK) ON EMP.cod = E.codemp
             WHERE t.fchtrn BETWEEN @d AND @h
               AND t.codcli > 0
               AND {$emp}
            OPTION (RECOMPILE);

            SELECT DISTINCT gasfac, nrofac INTO #fk FROM #d WHERE nrofac <> 0;

            SELECT c.codgas, c.nro, c.fch,
                   CASE WHEN CAST(c.txtref AS varchar(20)) LIKE '@N:CANCELAD%' THEN 1 ELSE 0 END AS cancelada
              INTO #f
              FROM #fk k
              JOIN SG12.dbo.DocumentosC c WITH (NOLOCK) ON c.nro = k.nrofac AND c.codgas = k.gasfac AND c.tip = 3;

            SELECT r.codcli, SUM(r.mto) AS cortes
              INTO #r
              FROM SG12.dbo.ValesR r WITH (NOLOCK)
              LEFT JOIN TG.dbo.Estaciones E WITH (NOLOCK) ON E.Codigo = r.codgas
              LEFT JOIN SG12.dbo.Empresas EMP WITH (NOLOCK) ON EMP.cod = E.codemp
             WHERE r.fch BETWEEN @d AND @h
               AND r.codval = {$codval}
               AND {$emp}
             GROUP BY r.codcli
            OPTION (RECOMPILE);

            WITH v AS (
                SELECT d.codcli,
                       COUNT(*)   AS despachos,
                       SUM(d.can) AS litros,
                       SUM(d.mto) AS monto,
                       SUM(CASE WHEN f.cancelada = 0 AND f.fch <= @h THEN d.mto ELSE 0 END) AS facturado_mes,
                       SUM(CASE WHEN f.cancelada = 0 AND f.fch >  @h THEN d.mto ELSE 0 END) AS facturado_despues,
                       SUM(CASE WHEN d.nrofac = 0 THEN d.mto ELSE 0 END)                    AS sin_factura,
                       SUM(CASE WHEN d.nrofac = 0 AND d.mto <> 0 THEN 1 ELSE 0 END)           AS n_sin_factura,
                       SUM(CASE WHEN d.nrofac <> 0 AND (f.nro IS NULL OR f.cancelada = 1) THEN d.mto ELSE 0 END) AS factura_cancelada,
                       COUNT(DISTINCT CASE WHEN f.cancelada = 0 THEN CAST(f.codgas AS varchar(6)) + '-' + CAST(f.nro AS varchar(12)) END) AS facturas
                  FROM #d d
                  LEFT JOIN #f f ON f.nro = d.nrofac AND f.codgas = d.gasfac
                 GROUP BY d.codcli
            )
            SELECT COALESCE(v.codcli, r.codcli) AS codcli,
                   ISNULL(cl.den, '(Cliente ' + CAST(COALESCE(v.codcli, r.codcli) AS varchar(12)) + ')') AS cliente,
                   ISNULL(CP.categoria, '')             AS categoria,
                   ISNULL(v.despachos, 0)               AS despachos,
                   ISNULL(v.litros, 0)                  AS litros,
                   ISNULL(v.monto, 0)                   AS monto,
                   ISNULL(r.cortes, 0)                  AS cortes,
                   ISNULL(v.facturado_mes, 0)           AS facturado_mes,
                   ISNULL(v.facturado_despues, 0)       AS facturado_despues,
                   ISNULL(v.sin_factura, 0)             AS sin_factura,
                   ISNULL(v.n_sin_factura, 0)           AS n_sin_factura,
                   ISNULL(v.factura_cancelada, 0)       AS factura_cancelada,
                   ISNULL(v.facturas, 0)                AS facturas
              FROM v
              FULL JOIN #r r ON r.codcli = v.codcli
              LEFT JOIN SG12.dbo.Clientes cl WITH (NOLOCK) ON cl.cod = COALESCE(v.codcli, r.codcli)
              LEFT JOIN TG.dbo.clientes_consumo_propio CP WITH (NOLOCK) ON CP.codcli = COALESCE(v.codcli, r.codcli) AND CP.activo = 1
             ORDER BY ISNULL(v.monto, 0) DESC;

            DROP TABLE IF EXISTS #d, #fk, #f, #r;";
        return $this->sql->select($query, [$from, $until, $empresa]) ?: [];
    }

    /**
     * Mismo resultado que ClientesModel::get_dispatches_summary_by_client($from, $until, 3, 0, $empresa, 'incluir')
     * (hoja "Venta aplicativo"), pero con tablas temporales: la versión original con CTE y
     * OR sobre Despachos tarda minutos y bloquea las otras consultas del módulo.
     * Incluye los despachos de clientes que hoy no son de crédito pero cuyo corte cobró con
     * el vale de crédito (ValesR.sec = -nrotrn).
     */
    public function consumos_por_cliente(int $from, int $until, int $empresa): array {
        $emp = self::EMP_FILTER;
        $codval = self::CODVAL_CREDITO;
        $query = "
            SET NOCOUNT ON;
            DECLARE @d int = ?, @h int = ?, @emp int = ?;
            DROP TABLE IF EXISTS #vx, #d, #r;

            SELECT DISTINCT vr.codgas, -vr.sec AS nrotrn
              INTO #vx
              FROM SG12.dbo.ValesR vr WITH (NOLOCK)
              JOIN SG12.dbo.Clientes vc WITH (NOLOCK) ON vc.cod = vr.codcli
             WHERE vr.fch BETWEEN @d - 1 AND @h + 1
               AND vr.codval = {$codval}
               AND vc.tipval <> 3
            OPTION (RECOMPILE);

            SELECT t1.codcli, t1.codgas, EMP.cod AS codemp, t1.can, t1.mto, t1.fchtrn
              INTO #d
              FROM SG12.dbo.Despachos t1 WITH (NOLOCK)
              JOIN SG12.dbo.Clientes t2 WITH (NOLOCK) ON t2.cod = t1.codcli
              LEFT JOIN TG.dbo.Estaciones E WITH (NOLOCK) ON E.Codigo = t1.codgas
              LEFT JOIN SG12.dbo.Empresas EMP WITH (NOLOCK) ON EMP.cod = E.codemp
             WHERE t1.fchtrn BETWEEN @d AND @h
               AND t1.codcli > 0
               AND {$emp}
               AND (t2.tipval = 3 OR EXISTS (SELECT 1 FROM #vx vx WHERE vx.codgas = t1.codgas AND vx.nrotrn = t1.nrotrn))
            OPTION (RECOMPILE);

            SELECT r.codcli, SUM(r.mto) AS MontoCortes
              INTO #r
              FROM SG12.dbo.ValesR r WITH (NOLOCK)
              LEFT JOIN TG.dbo.Estaciones E WITH (NOLOCK) ON E.Codigo = r.codgas
              LEFT JOIN SG12.dbo.Empresas EMP WITH (NOLOCK) ON EMP.cod = E.codemp
             WHERE r.fch BETWEEN @d AND @h
               AND r.codval = {$codval}
               AND {$emp}
             GROUP BY r.codcli
            OPTION (RECOMPILE);

            WITH d AS (
                SELECT codcli,
                       COUNT(DISTINCT codemp) AS RazonesSociales,
                       COUNT(DISTINCT codgas) AS Estaciones,
                       COUNT(*)  AS Despachos,
                       SUM(can)  AS Litros,
                       SUM(mto)  AS Monto,
                       MIN(fchtrn) AS PrimerFch,
                       MAX(fchtrn) AS UltimoFch
                  FROM #d
                 GROUP BY codcli
            )
            SELECT COALESCE(d.codcli, r.codcli) AS codcli,
                   ISNULL(c.den, '(Cliente ' + CAST(COALESCE(d.codcli, r.codcli) AS VARCHAR(12)) + ')') AS Cliente,
                   ISNULL(d.RazonesSociales, 0) AS RazonesSociales,
                   ISNULL(d.Estaciones, 0) AS Estaciones,
                   ISNULL(d.Despachos, 0) AS Despachos,
                   CAST(ISNULL(d.Litros, 0) AS decimal(18,3)) AS Litros,
                   CAST(ISNULL(d.Monto, 0) AS decimal(18,2)) AS Monto,
                   CAST(ISNULL(r.MontoCortes, 0) AS decimal(18,2)) AS MontoCortes,
                   CAST(ISNULL(d.Monto, 0) - ISNULL(r.MontoCortes, 0) AS decimal(18,2)) AS Diferencia,
                   CONVERT(VARCHAR(10), CAST(d.PrimerFch AS DATETIME) - 1, 23) AS PrimerConsumo,
                   CONVERT(VARCHAR(10), CAST(d.UltimoFch AS DATETIME) - 1, 23) AS UltimoConsumo,
                   ISNULL(CP.categoria, '') AS Categoria
              FROM d
              FULL JOIN #r r ON r.codcli = d.codcli
              LEFT JOIN SG12.dbo.Clientes c WITH (NOLOCK) ON c.cod = COALESCE(d.codcli, r.codcli)
              LEFT JOIN TG.dbo.clientes_consumo_propio CP WITH (NOLOCK) ON CP.codcli = COALESCE(d.codcli, r.codcli) AND CP.activo = 1
             ORDER BY ISNULL(d.Monto, 0) DESC
            OPTION (RECOMPILE);

            DROP TABLE IF EXISTS #vx, #d, #r;";
        return $this->sql->select($query, [$from, $until, $empresa]) ?: [];
    }

    /** Despachos de un cliente en el rango con su factura y el estado de ésta (modal del desglose). */
    public function despachos_cliente(int $from, int $until, int $empresa, int $codcli): array {
        $emp = self::EMP_FILTER;
        $query = "
            SET NOCOUNT ON;
            DECLARE @d int = ?, @h int = ?, @emp int = ?, @cli int = ?;
            SELECT g.abr AS estacion, t.nrotrn,
                   CONVERT(varchar(10), DATEADD(day, t.fchtrn - 1, '19000101'), 23) AS fecha,
                   t.hratrn, t.nrotur, p.den AS producto, t.can AS litros, t.mto AS monto,
                   ISNULL(t.nrofac, 0) AS nrofac, gf.abr AS estacion_factura,
                   CONVERT(varchar(10), DATEADD(day, c.fch - 1, '19000101'), 23) AS fecha_factura,
                   c.satuid,
                   CASE WHEN c.nro IS NULL THEN NULL
                        WHEN CAST(c.txtref AS varchar(20)) LIKE '@N:CANCELAD%' THEN 1 ELSE 0 END AS cancelada
              FROM SG12.dbo.Despachos t WITH (NOLOCK)
              LEFT JOIN TG.dbo.Estaciones E WITH (NOLOCK) ON E.Codigo = t.codgas
              LEFT JOIN SG12.dbo.Empresas EMP WITH (NOLOCK) ON EMP.cod = E.codemp
              LEFT JOIN SG12.dbo.Gasolineras g WITH (NOLOCK) ON g.cod = t.codgas
              LEFT JOIN SG12.dbo.Gasolineras gf WITH (NOLOCK) ON gf.cod = t.gasfac
              LEFT JOIN SG12.dbo.Productos p WITH (NOLOCK) ON p.cod = t.codprd
              LEFT JOIN SG12.dbo.DocumentosC c WITH (NOLOCK) ON c.nro = t.nrofac AND c.codgas = t.gasfac AND c.tip = 3
             WHERE t.fchtrn BETWEEN @d AND @h
               AND t.codcli = @cli
               AND NOT (t.mto = 0 AND t.can = 0)
               AND {$emp}
             ORDER BY t.fchtrn, t.hratrn
            OPTION (RECOMPILE);";
        return $this->sql->select($query, [$from, $until, $empresa, $codcli]) ?: [];
    }

    /**
     * Despachos de un cliente contra sus vales de crédito del corte (ValesR), uno a uno.
     * El vale se liga al despacho por estación + folio (ValesR.sec = -Despachos.nrotrn).
     * Salen también los vales del cliente sin despacho y los despachos sin vale.
     */
    public function despachos_vs_cortes(int $from, int $until, int $empresa, int $codcli): array {
        $emp = self::EMP_FILTER;
        $codval = self::CODVAL_CREDITO;
        $query = "
            SET NOCOUNT ON;
            DECLARE @d int = ?, @h int = ?, @emp int = ?, @cli int = ?;
            DROP TABLE IF EXISTS #k, #kd, #v;

            CREATE TABLE #k (codgas int NOT NULL, nrotrn int NOT NULL, PRIMARY KEY (codgas, nrotrn));

            INSERT INTO #k (codgas, nrotrn)
            SELECT DISTINCT t.codgas, ABS(t.nrotrn)
              FROM SG12.dbo.Despachos t WITH (NOLOCK)
              LEFT JOIN TG.dbo.Estaciones E WITH (NOLOCK) ON E.Codigo = t.codgas
              LEFT JOIN SG12.dbo.Empresas EMP WITH (NOLOCK) ON EMP.cod = E.codemp
             WHERE t.fchtrn BETWEEN @d AND @h AND t.codcli = @cli AND {$emp}
            OPTION (RECOMPILE);

            SELECT codgas, nrotrn INTO #kd FROM #k;

            INSERT INTO #k (codgas, nrotrn)
            SELECT DISTINCT r.codgas, -r.sec
              FROM SG12.dbo.ValesR r WITH (NOLOCK)
              LEFT JOIN TG.dbo.Estaciones E WITH (NOLOCK) ON E.Codigo = r.codgas
              LEFT JOIN SG12.dbo.Empresas EMP WITH (NOLOCK) ON EMP.cod = E.codemp
             WHERE r.fch BETWEEN @d AND @h AND r.codcli = @cli AND r.codval = {$codval} AND {$emp}
               AND NOT EXISTS (SELECT 1 FROM SG12.dbo.Despachos t WITH (NOLOCK)
                                WHERE t.codgas = r.codgas AND ABS(t.nrotrn) = -r.sec AND t.fchtrn BETWEEN @d AND @h AND t.codcli = @cli)
            OPTION (RECOMPILE);

            -- Un renglón por vale (estación + folio + corte). Los vales manuales repiten folio (1, 2...) en cada corte,
            -- así que sin fecha y turno se sumarían años de vales. Un vale de otro cliente cuenta si liga con un despacho de éste.
            SELECT r.codgas, -r.sec AS nrotrn, r.fch, r.nrotur, COUNT(*) AS n, SUM(r.mto) AS mto
              INTO #v
              FROM SG12.dbo.ValesR r WITH (NOLOCK)
              JOIN #k ON #k.codgas = r.codgas AND #k.nrotrn = -r.sec
             WHERE r.codval = {$codval} AND r.fch BETWEEN @d - 10 AND @h + 10
               AND (r.codcli = @cli OR EXISTS (SELECT 1 FROM #kd WHERE #kd.codgas = r.codgas AND #kd.nrotrn = -r.sec))
               AND (r.fch BETWEEN @d AND @h OR EXISTS (SELECT 1 FROM #kd WHERE #kd.codgas = r.codgas AND #kd.nrotrn = -r.sec))
             GROUP BY r.codgas, r.sec, r.fch, r.nrotur;

            SELECT g.abr AS estacion, COALESCE(t.nrotrn, k.nrotrn) AS nrotrn,
                   CONVERT(varchar(10), DATEADD(day, COALESCE(t.fchtrn, v.fch) - 1, '19000101'), 23) AS fecha,
                   t.hratrn, p.den AS producto,
                   t.can AS litros, t.mto AS monto_despacho, t.codcli AS cli_despacho, t.nrotur AS turno_despacho,
                   v.nrotur AS turno, v.n AS n_vales, v.mto AS monto_vale
              FROM #k k
              LEFT JOIN SG12.dbo.Despachos t WITH (NOLOCK) ON t.codgas = k.codgas AND t.nrotrn IN (k.nrotrn, -k.nrotrn)
                                                     AND t.fchtrn BETWEEN @d - 10 AND @h + 10
              LEFT JOIN #v v ON v.codgas = k.codgas AND v.nrotrn = k.nrotrn
              LEFT JOIN SG12.dbo.Gasolineras g WITH (NOLOCK) ON g.cod = k.codgas
              LEFT JOIN SG12.dbo.Productos p WITH (NOLOCK) ON p.cod = t.codprd
             ORDER BY COALESCE(t.fchtrn, v.fch), t.hratrn, k.nrotrn
            OPTION (RECOMPILE);

            DROP TABLE IF EXISTS #k, #kd, #v;";
        return $this->sql->select($query, [$from, $until, $empresa, $codcli]) ?: [];
    }

    /**
     * Cortes con diferencia: despachos de clientes de crédito contra los vales de crédito del corte
     * (ValesR), ligados por estación + folio (ValesR.sec = -Despachos.nrotrn). Devuelve solo los
     * folios que no cuadran, para ubicar el corte (estación + fecha + turno) del problema.
     * monto_despacho / monto_vale son los reales; diferencia_mes solo cuenta lo que cae en el rango
     * (despacho por fecha del despacho, vale por fecha del corte), así que su suma explica "Monto − Cortes".
     */
    public function cortes_con_diferencia(int $from, int $until, int $empresa): array {
        $emp = self::EMP_FILTER;
        $codval = self::CODVAL_CREDITO;
        $query = "
            SET NOCOUNT ON;
            DECLARE @d int = ?, @h int = ?, @emp int = ?;
            DROP TABLE IF EXISTS #d, #v, #o, #x;

            SELECT t.codgas, t.nrotrn, ABS(t.nrotrn) AS dkey, t.codcli, t.fchtrn, t.hratrn, t.fchcor, t.nrotur, t.codprd, t.mto
              INTO #d
              FROM SG12.dbo.Despachos t WITH (NOLOCK)
              JOIN SG12.dbo.Clientes c WITH (NOLOCK) ON c.cod = t.codcli AND c.tipval = 3
              LEFT JOIN TG.dbo.Estaciones E WITH (NOLOCK) ON E.Codigo = t.codgas
              LEFT JOIN SG12.dbo.Empresas EMP WITH (NOLOCK) ON EMP.cod = E.codemp
             WHERE t.fchtrn BETWEEN @d AND @h AND t.codcli > 0 AND {$emp}
            OPTION (RECOMPILE);
            CREATE CLUSTERED INDEX ix_d ON #d (codgas, dkey);

            -- vales del rango y una semana alrededor (un corte cierra días después del despacho).
            -- El folio del despacho es -sec (en algunas estaciones el despacho trae folio negativo: se compara en valor absoluto);
            -- un vale con sec >= 0 es siempre manual y nunca liga por folio.
            SELECT r.codgas, -r.sec AS nrotrn, r.sec, r.codcli, r.fch AS fch, r.nrotur AS nrotur,
                   MAX(CASE WHEN r.codprd = 0 THEN 1 ELSE 0 END) AS manual, COUNT(*) AS n, SUM(r.mto) AS mto
              INTO #v
              FROM SG12.dbo.ValesR r WITH (NOLOCK)
              LEFT JOIN TG.dbo.Estaciones E WITH (NOLOCK) ON E.Codigo = r.codgas
              LEFT JOIN SG12.dbo.Empresas EMP WITH (NOLOCK) ON EMP.cod = E.codemp
             WHERE r.codval = {$codval} AND r.fch BETWEEN @d - 7 AND @h + 7 AND {$emp}
             GROUP BY r.codgas, r.sec, r.codcli, r.fch, r.nrotur
            OPTION (RECOMPILE);

            -- fuera del rango solo interesan los que ligan con un despacho del rango
            DELETE v FROM #v v
             WHERE (v.fch < @d OR v.fch > @h)
               AND NOT EXISTS (SELECT 1 FROM #d d WHERE d.codgas = v.codgas AND d.dkey = v.nrotrn);

            -- vales sin despacho en el rango: ¿existe el despacho en otro mes o de otro cliente?
            SELECT v.codgas, v.nrotrn, t.nrotrn AS folio, t.fchtrn, t.hratrn, t.codcli, t.codprd, t.mto
              INTO #o
              FROM #v v
              JOIN SG12.dbo.Despachos t WITH (NOLOCK) ON t.codgas = v.codgas AND t.nrotrn = v.nrotrn
             WHERE v.nrotrn > 0 AND t.fchtrn BETWEEN v.fch - 10 AND v.fch + 10
               AND NOT EXISTS (SELECT 1 FROM #d d WHERE d.codgas = v.codgas AND d.dkey = v.nrotrn)
            UNION ALL
            SELECT v.codgas, v.nrotrn, t.nrotrn, t.fchtrn, t.hratrn, t.codcli, t.codprd, t.mto
              FROM #v v
              JOIN SG12.dbo.Despachos t WITH (NOLOCK) ON t.codgas = v.codgas AND t.nrotrn = -v.nrotrn
             WHERE v.nrotrn > 0 AND t.fchtrn BETWEEN v.fch - 10 AND v.fch + 10
               AND NOT EXISTS (SELECT 1 FROM #d d WHERE d.codgas = v.codgas AND d.dkey = v.nrotrn)
            OPTION (RECOMPILE);

            SELECT COALESCE(d.codgas, v.codgas) AS codgas, COALESCE(d.nrotrn, o.folio, v.sec) AS nrotrn,
                   COALESCE(d.codcli, v.codcli) AS codcli,
                   COALESCE(d.fchcor, v.fch) AS fch_corte, COALESCE(d.nrotur, v.nrotur) AS turno,
                   v.fch AS fch_vale, v.nrotur AS turno_vale,
                   COALESCE(d.fchtrn, o.fchtrn) AS fch_despacho, COALESCE(d.hratrn, o.hratrn) AS hra, COALESCE(d.codprd, o.codprd) AS codprd,
                   d.mto AS mto_d, o.mto AS mto_o, v.mto AS mto_v,
                   CASE WHEN d.nrotrn IS NULL THEN 0 ELSE 1 END AS en_rango_d,
                   CASE WHEN v.nrotrn IS NULL THEN 0 WHEN v.fch BETWEEN @d AND @h THEN 1 ELSE 0 END AS en_rango_v,
                   v.n AS n_vales, v.manual
              INTO #x
              FROM #d d
              FULL JOIN #v v ON v.codgas = d.codgas AND v.nrotrn = d.dkey
              LEFT JOIN #o o ON o.codgas = v.codgas AND o.nrotrn = v.nrotrn
             WHERE d.nrotrn IS NULL OR v.nrotrn IS NULL OR v.n > 1 OR ABS(d.mto - v.mto) >= 0.01
                   OR v.fch NOT BETWEEN @d AND @h OR d.fchcor <> v.fch OR d.nrotur <> v.nrotur;

            SELECT g.abr AS estacion, x.nrotrn, x.codcli, c.den AS cliente,
                   CONVERT(varchar(10), DATEADD(day, x.fch_corte - 1, '19000101'), 23) AS fecha_corte, x.turno,
                   CASE WHEN x.fch_vale IS NULL THEN NULL
                        ELSE CONVERT(varchar(10), DATEADD(day, x.fch_vale - 1, '19000101'), 23) END AS fecha_vale, x.turno_vale,
                   CONVERT(varchar(10), DATEADD(day, x.fch_despacho - 1, '19000101'), 23) AS fecha_despacho, x.hra,
                   p.den AS producto, x.mto_d, x.mto_o, x.mto_v, x.en_rango_d, x.en_rango_v, x.n_vales, x.manual
              FROM #x x
              LEFT JOIN SG12.dbo.Gasolineras g WITH (NOLOCK) ON g.cod = x.codgas
              LEFT JOIN SG12.dbo.Clientes c WITH (NOLOCK) ON c.cod = x.codcli
              LEFT JOIN SG12.dbo.Productos p WITH (NOLOCK) ON p.cod = x.codprd
             ORDER BY x.fch_corte, g.abr, x.turno, x.nrotrn
            OPTION (RECOMPILE);

            DROP TABLE IF EXISTS #d, #v, #o, #x;";
        return $this->sql->select($query, [$from, $until, $empresa]) ?: [];
    }

    /**
     * Facturas de crédito del periodo: las de "Facturación Crédito" fechadas en
     * el rango (estación de la factura en la razón social) más las de cualquier
     * fecha que contengan despachos de crédito del rango. Por factura: total,
     * despachos del rango y de otros meses.
     */
    public function facturas(int $from, int $until, int $empresa): array {
        $emp = self::EMP_FILTER;
        $subope = self::SUBOPE_FACTURA_CREDITO;
        $query = "
            SET NOCOUNT ON;
            DECLARE @d int = ?, @h int = ?, @emp int = ?;
            DROP TABLE IF EXISTS #k, #t, #x;
            CREATE TABLE #k (codgas int NOT NULL, nro int NOT NULL, PRIMARY KEY (codgas, nro));

            INSERT INTO #k (codgas, nro)
            SELECT c.codgas, c.nro
              FROM SG12.dbo.DocumentosC c WITH (NOLOCK)
              LEFT JOIN TG.dbo.Estaciones E WITH (NOLOCK) ON E.Codigo = c.codgas
              LEFT JOIN SG12.dbo.Empresas EMP WITH (NOLOCK) ON EMP.cod = E.codemp
             WHERE c.tip = 3 AND c.subope = {$subope}
               AND c.fch BETWEEN @d AND @h
               AND {$emp}
            OPTION (RECOMPILE);

            INSERT INTO #k (codgas, nro)
            SELECT DISTINCT t.gasfac, t.nrofac
              FROM SG12.dbo.Despachos t WITH (NOLOCK)
              JOIN SG12.dbo.Clientes cl WITH (NOLOCK) ON cl.cod = t.codcli AND cl.tipval = 3
              LEFT JOIN TG.dbo.Estaciones E WITH (NOLOCK) ON E.Codigo = t.codgas
              LEFT JOIN SG12.dbo.Empresas EMP WITH (NOLOCK) ON EMP.cod = E.codemp
             WHERE t.fchtrn BETWEEN @d AND @h
               AND t.codcli > 0
               AND ISNULL(t.nrofac, 0) <> 0
               AND t.gasfac IS NOT NULL
               AND {$emp}
               AND NOT EXISTS (SELECT 1 FROM #k k WHERE k.codgas = t.gasfac AND k.nro = t.nrofac)
            OPTION (RECOMPILE);

            SELECT d.codgas, d.nro, SUM(d.mto) / 100.0 AS total
              INTO #t
              FROM #k k
              JOIN SG12.dbo.Documentos d WITH (NOLOCK) ON d.nro = k.nro AND d.codgas = k.codgas AND d.tip = 3
             GROUP BY d.codgas, d.nro;

            SELECT t.gasfac, t.nrofac,
                   COUNT(*)   AS despachos,
                   SUM(t.mto) AS monto_despachos,
                   SUM(CASE WHEN t.fchtrn BETWEEN @d AND @h THEN 1 ELSE 0 END)     AS despachos_mes,
                   SUM(CASE WHEN t.fchtrn BETWEEN @d AND @h THEN t.mto ELSE 0 END) AS monto_mes,
                   MIN(t.fchtrn) AS fch_min, MAX(t.fchtrn) AS fch_max
              INTO #x
              FROM #k k
              JOIN SG12.dbo.Despachos t WITH (NOLOCK) ON t.nrofac = k.nro AND t.gasfac = k.codgas
             GROUP BY t.gasfac, t.nrofac
            OPTION (RECOMPILE);

            SELECT k.codgas, g.abr AS estacion, k.nro, c.fch,
                   CONVERT(varchar(10), DATEADD(day, c.fch - 1, '19000101'), 23) AS fecha,
                   ef.den AS tipo, c.codopr, cl.den AS cliente, c.satuid,
                   CAST(c.txtref AS nvarchar(500)) AS txtref,
                   CASE WHEN c.nro IS NULL THEN NULL
                        WHEN CAST(c.txtref AS varchar(20)) LIKE '@N:CANCELAD%' THEN 1 ELSE 0 END AS cancelada,
                   ISNULL(t.total, 0)           AS total,
                   ISNULL(x.despachos, 0)       AS despachos,
                   ISNULL(x.monto_despachos, 0) AS monto_despachos,
                   ISNULL(x.despachos_mes, 0)   AS despachos_mes,
                   ISNULL(x.monto_mes, 0)       AS monto_mes,
                   CONVERT(varchar(10), DATEADD(day, x.fch_min - 1, '19000101'), 23) AS despacho_desde,
                   CONVERT(varchar(10), DATEADD(day, x.fch_max - 1, '19000101'), 23) AS despacho_hasta
              FROM #k k
              LEFT JOIN SG12.dbo.DocumentosC c WITH (NOLOCK) ON c.nro = k.nro AND c.codgas = k.codgas AND c.tip = 3
              LEFT JOIN SG12.dbo.EfectosC ef WITH (NOLOCK) ON ef.tipope = 3 AND ef.subope = c.subope
              LEFT JOIN SG12.dbo.Clientes cl WITH (NOLOCK) ON cl.cod = c.codopr
              LEFT JOIN SG12.dbo.Gasolineras g WITH (NOLOCK) ON g.cod = k.codgas
              LEFT JOIN #t t ON t.codgas = k.codgas AND t.nro = k.nro
              LEFT JOIN #x x ON x.gasfac = k.codgas AND x.nrofac = k.nro
             ORDER BY c.fch, k.codgas, k.nro;

            DROP TABLE IF EXISTS #k, #t, #x;";
        return $this->sql->select($query, [$from, $until, $empresa]) ?: [];
    }

    /**
     * Cobros del rango (tip 4) de TODAS las estaciones, con monto, facturas que
     * paga y txtref (de ahí sale el MOV: ConciliacionCobros::movDeTxtref). Son
     * todas porque un MOV puede juntar cobros de varias estaciones; el cruce con
     * el banco (ConciliacionCobros) va completo y la razón social se filtra
     * después con la columna empresa.
     */
    public function cobros(int $from, int $until): array {
        $subopes = self::SUBOPE_COBRANZA;
        $query = "
            SET NOCOUNT ON;
            DECLARE @d int = ?, @h int = ?;
            DROP TABLE IF EXISTS #c, #m, #a;

            SELECT c.codgas, c.nro, c.fch, c.codopr, c.subope, c.satuid, x.t,
                   CASE WHEN x.t LIKE '@N:CANCELAD%' THEN 1 ELSE 0 END AS cancelado
              INTO #c
              FROM SG12.dbo.DocumentosC c WITH (NOLOCK)
             CROSS APPLY (SELECT REPLACE(REPLACE(CAST(c.txtref AS varchar(1000)), CHAR(13), ' '), CHAR(10), ' ') AS t) x
             WHERE c.tip = 4
               AND c.subope IN ({$subopes})
               AND c.fch BETWEEN @d AND @h
            OPTION (RECOMPILE);

            SELECT d.codgas, d.nro, SUM(d.mto) / 100.0 AS monto
              INTO #m
              FROM #c c
              JOIN SG12.dbo.Documentos d WITH (NOLOCK) ON d.nro = c.nro AND d.codgas = c.codgas AND d.tip = 4
             GROUP BY d.codgas, d.nro;

            SELECT a.codgas, a.nro,
                   STRING_AGG(CAST(a.nroapl AS varchar(12)) + ':' + CAST(CAST(a.mtoapl / 100.0 AS decimal(18,2)) AS varchar(20)), ',') AS facturas
              INTO #a
              FROM #c c
              JOIN SG12.dbo.DocumentosA a WITH (NOLOCK) ON a.nro = c.nro AND a.codgas = c.codgas AND a.tip = 4
             GROUP BY a.codgas, a.nro;

            SELECT c.codgas, gs.abr AS estacion, EMP.cod AS empresa, c.nro,
                   CONVERT(varchar(10), DATEADD(day, c.fch - 1, '19000101'), 23) AS fecha,
                   c.codopr, cl.den AS cliente, ef.den AS tipo, c.satuid, c.t AS txtref, c.cancelado,
                   ISNULL(m.monto, 0) AS monto, a.facturas
              FROM #c c
              LEFT JOIN #m m ON m.codgas = c.codgas AND m.nro = c.nro
              LEFT JOIN #a a ON a.codgas = c.codgas AND a.nro = c.nro
              LEFT JOIN SG12.dbo.Clientes cl WITH (NOLOCK) ON cl.cod = c.codopr
              LEFT JOIN SG12.dbo.EfectosC ef WITH (NOLOCK) ON ef.tipope = 4 AND ef.subope = c.subope
              LEFT JOIN SG12.dbo.Gasolineras gs WITH (NOLOCK) ON gs.cod = c.codgas
              LEFT JOIN TG.dbo.Estaciones E WITH (NOLOCK) ON E.Codigo = c.codgas
              LEFT JOIN SG12.dbo.Empresas EMP WITH (NOLOCK) ON EMP.cod = E.codemp
             ORDER BY c.fch, c.codgas, c.nro;

            DROP TABLE IF EXISTS #c, #m, #a;";
        return $this->sql->select($query, [$from, $until]) ?: [];
    }

    /**
     * Depósitos (abonos) de todas las cuentas de Movimientos Bancos que pueden
     * ser de algún cobro: los que tienen la secuencia de un MOV ($movs, entre
     * $desde y $hasta) y los del mismo importe que un cobro ($montos, entre
     * $desdeImporte y $hastaImporte). Es la bolsa contra la que cruza
     * ConciliacionCobros; traer todos los abonos de la ventana (~25 mil) tardaba 10 s.
     * Fechas 'Y-m-d'. Las listas van en línea ya saneadas (intval / %.2f):
     * SG12 está en nivel de compatibilidad 110, sin STRING_SPLIT.
     */
    public function depositos(array $movs, array $montos, string $desde, string $hasta, string $desdeImporte, string $hastaImporte): array {
        $movs   = array_values(array_unique(array_filter(array_map('intval', $movs), fn($m) => $m > 0)));
        $montos = array_values(array_unique(array_map(fn($m) => sprintf('%.2f', (float)$m), array_filter($montos, fn($m) => (float)$m > 0))));
        if (!$movs && !$montos) return [];
        $porMov    = $movs   ? 'secuencia IN (' . implode(',', $movs) . ')' : '1 = 0';
        $porImporte = $montos ? '(fecha BETWEEN ? AND ? AND abono IN (' . implode(',', $montos) . '))' : '1 = 0';
        $query = "SELECT id, banco, cuenta, CONVERT(varchar(10), fecha, 23) AS fecha, secuencia, abono,
                         nombre_contraparte, rfc_contraparte,
                         LEFT(COALESCE(NULLIF(descripcion_larga, ''), NULLIF(concepto, ''), descripcion), 300) AS detalle
                    FROM TG.dbo.movimientos_bancarios WITH (NOLOCK)
                   WHERE fecha BETWEEN ? AND ?
                     AND abono > 0
                     AND ({$porMov} OR {$porImporte})
                   ORDER BY cuenta, fecha, secuencia";
        return $this->sql->select($query, $montos ? [$desde, $hasta, $desdeImporte, $hastaImporte] : [$desde, $hasta]) ?: [];
    }

    /** Movimientos (abonos y cargos) de las cuentas indicadas entre dos fechas 'Y-m-d'. */
    public function movimientos_banco(string $desde, string $hasta, array $cuentas): array {
        $cuentas = array_values(array_unique(array_filter(array_map('strval', $cuentas), fn($c) => $c !== '')));
        if (!$cuentas) return [];
        $marcas = implode(',', array_fill(0, count($cuentas), '?'));
        $query = "SELECT id, banco, cuenta, CONVERT(varchar(10), fecha, 23) AS fecha,
                         CONVERT(varchar(10), fecha_operacion, 23) AS fecha_operacion, secuencia,
                         descripcion, referencia, ISNULL(abono, 0) AS abono, ISNULL(cargo, 0) AS cargo, saldo,
                         nombre_contraparte, rfc_contraparte,
                         LEFT(COALESCE(NULLIF(descripcion_larga, ''), NULLIF(concepto, ''), ''), 300) AS detalle
                    FROM TG.dbo.movimientos_bancarios WITH (NOLOCK)
                   WHERE fecha BETWEEN ? AND ?
                     AND cuenta IN ({$marcas})
                   ORDER BY cuenta, fecha, secuencia, id";
        return $this->sql->select($query, array_merge([$desde, $hasta], $cuentas)) ?: [];
    }
}
