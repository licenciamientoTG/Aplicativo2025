<?php
/**
 * ConciliacionDebitoModel — Ingresos > Conciliación débito (prepago): venta a clientes
 * de débito contra la facturación de consumos (Serie E) de ControlGas.
 * Diseño: docs/superpowers/specs/2026-11-08-conciliacion-debito-design.md
 *
 * Fechas: enteros de ControlGas (dateToInt), los mismos para Despachos.fchtrn y DocumentosC.fch.
 * $empresa: 0 = todas, -1 = sin razón social, >0 = SG12.Empresas.cod.
 * Consumos y consumos propios no se consultan aquí: usan ClientesModel (tipval 4).
 *
 * Tablas temporales + OPTION (RECOMPILE), igual que ConciliacionVentaModel.
 */
class ConciliacionDebitoModel extends Model {

    /** Serie E (facturación de consumos): DocumentosC.nro 1300000000–1399999999 (serie D = 12…). */
    public const SERIE_E_DESDE = 1300000000;
    public const SERIE_E_HASTA = 1399999999;

    /** Filtro de razón social sobre el alias de SG12.Empresas ya unido (EMP). */
    private const EMP_FILTER = "(@emp = 0 OR (@emp = -1 AND EMP.cod IS NULL) OR EMP.cod = @emp)";

    /**
     * Facturas Serie E con fecha entre $from y $until + 10 días (el controlador descarta las posteriores
     * a $until que no sean "Mes siguiente"). La razón social sale de DocumentosC.codemp. Una fila por factura:
     * total = SUM(Documentos.mto) / 100; cancelada = txtref '@N:CANCELAD%'.
     */
    public function facturas_serie_e(int $from, int $until, int $empresa): array {
        $emp = self::EMP_FILTER;
        $desde = self::SERIE_E_DESDE;
        $hasta = self::SERIE_E_HASTA;
        $query = "
            SET NOCOUNT ON;
            DECLARE @d int = ?, @h int = ?, @emp int = ?;
            DROP TABLE IF EXISTS #k, #t;
            CREATE TABLE #k (codgas int NOT NULL, nro int NOT NULL, PRIMARY KEY (codgas, nro));

            INSERT INTO #k (codgas, nro)
            SELECT c.codgas, c.nro
              FROM SG12.dbo.DocumentosC c WITH (NOLOCK)
              LEFT JOIN SG12.dbo.Empresas EMP WITH (NOLOCK) ON EMP.cod = c.codemp
             WHERE c.tip = 3 AND c.codgas = 0
               AND c.nro BETWEEN {$desde} AND {$hasta}
               AND c.fch BETWEEN @d AND @h + 10
               AND {$emp}
            OPTION (RECOMPILE);

            SELECT d.codgas, d.nro, SUM(d.mto) / 100.0 AS total
              INTO #t
              FROM #k k
              JOIN SG12.dbo.Documentos d WITH (NOLOCK) ON d.nro = k.nro AND d.codgas = k.codgas AND d.tip = 3
             GROUP BY d.codgas, d.nro;

            SELECT k.codgas, k.nro, c.fch,
                   CONVERT(varchar(10), DATEADD(day, c.fch - 1, '19000101'), 23) AS fecha,
                   c.codopr AS codcli, cl.den AS cliente, c.subope,
                   ISNULL(t.total, 0) AS total,
                   CASE WHEN CAST(c.txtref AS varchar(20)) LIKE '@N:CANCELAD%' THEN 1 ELSE 0 END AS cancelada,
                   c.satuid,
                   CAST(c.txtref AS nvarchar(500)) AS txtref,
                   c.codemp AS empresa
              FROM #k k
              JOIN SG12.dbo.DocumentosC c WITH (NOLOCK) ON c.nro = k.nro AND c.codgas = k.codgas AND c.tip = 3
              LEFT JOIN SG12.dbo.Clientes cl WITH (NOLOCK) ON cl.cod = c.codopr
              LEFT JOIN #t t ON t.codgas = k.codgas AND t.nro = k.nro
             ORDER BY c.fch, k.nro;

            DROP TABLE IF EXISTS #k, #t;";
        return $this->sql->select($query, [$from, $until, $empresa]) ?: [];
    }

    /**
     * Despachos de clientes de débito (tipval 4) del rango sin factura (nrofac = 0) y con monto.
     * Mismas condiciones que ClientesModel::get_dispatches_summary_by_client en débito: sin filtro
     * de terminal bancaria (solo aplica a crédito) y con los consumos propios incluidos (categoria los marca).
     */
    public function despachos_pendientes(int $from, int $until, int $empresa): array {
        $emp = self::EMP_FILTER;
        $query = "
            SET NOCOUNT ON;
            DECLARE @d int = ?, @h int = ?, @emp int = ?;
            DROP TABLE IF EXISTS #d;

            SELECT t.codgas, t.nrotrn, t.fchtrn, t.hratrn, t.codprd, t.codcli, t.can, t.mto
              INTO #d
              FROM SG12.dbo.Despachos t WITH (NOLOCK)
              JOIN SG12.dbo.Clientes c WITH (NOLOCK) ON c.cod = t.codcli AND c.tipval = 4
              LEFT JOIN TG.dbo.Estaciones E WITH (NOLOCK) ON E.Codigo = t.codgas
              LEFT JOIN SG12.dbo.Empresas EMP WITH (NOLOCK) ON EMP.cod = E.codemp
             WHERE t.fchtrn BETWEEN @d AND @h
               AND t.codcli > 0
               AND ISNULL(t.nrofac, 0) = 0
               AND t.mto <> 0
               AND {$emp}
            OPTION (RECOMPILE);

            SELECT CONVERT(varchar(10), DATEADD(day, d.fchtrn - 1, '19000101'), 23) AS fecha,
                   d.hratrn, d.nrotrn, g.abr AS estacion, p.den AS producto,
                   d.can AS litros, d.mto AS monto, d.codcli, cl.den AS cliente,
                   ISNULL(CP.categoria, '') AS categoria
              FROM #d d
              LEFT JOIN SG12.dbo.Gasolineras g WITH (NOLOCK) ON g.cod = d.codgas
              LEFT JOIN SG12.dbo.Productos p WITH (NOLOCK) ON p.cod = d.codprd
              LEFT JOIN SG12.dbo.Clientes cl WITH (NOLOCK) ON cl.cod = d.codcli
              LEFT JOIN TG.dbo.clientes_consumo_propio CP WITH (NOLOCK) ON CP.codcli = d.codcli AND CP.activo = 1
             ORDER BY d.fchtrn, d.hratrn, d.codgas
            OPTION (RECOMPILE);

            DROP TABLE IF EXISTS #d;";
        return $this->sql->select($query, [$from, $until, $empresa]) ?: [];
    }
}
