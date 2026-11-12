<?php
/**
 * CreditClientsCierreModel — foto diaria de clientes crédito: cierre del día
 * de ayer por cliente en TG.dbo.credit_clients_cierre (+ bitácora
 * credit_clients_cierre_runs). El proceso (vigencias, bitácora, recuperación,
 * lectura por día) es el de DebitClientsCierreModel; aquí solo cambian las
 * tablas, las columnas, la lógica pura (CreditCierre) y el cálculo del día.
 * Spec: docs/superpowers/specs/2026-11-12-credit-clients-cierre-diario-design.md
 */
require_once __DIR__ . '/DebitClientsCierreModel.php';
require_once __DIR__ . '/../classes/CreditCierre.class.php';

class CreditClientsCierreModel extends DebitClientsCierreModel
{
    const TABLA      = '[TG].dbo.credit_clients_cierre';
    const TABLA_RUNS = '[TG].dbo.credit_clients_cierre_runs';
    const LOGICA     = 'CreditCierre';

    const COLUMNAS = [
        'codcli', 'cliente', 'debglo', 'limite',
        'saldo_ini_sistema', 'saldo_ini_calc_desp', 'saldo_ini_calc_cortes',
        'cobros_dia', 'notas_dia', 'facturado_dia',
        'consumos_desp_dia', 'consumos_cortes_dia', 'desfase_dia',
        'saldo_fin_calc_desp', 'saldo_fin_calc_cortes', 'saldo_fin_sistema', 'saldo_fin_vehiculos', 'disponible',
        'lectura_cresdo', 'lectura_vehiculos', 'consumos_post_cierre', 'abonos_post_cierre', 'lectura_at',
    ];

    const COLUMNAS_FOTO = [
        'limite',
        'saldo_ini_sistema', 'saldo_ini_calc_desp', 'saldo_ini_calc_cortes',
        'cobros_dia', 'notas_dia', 'facturado_dia',
        'consumos_desp_dia', 'consumos_cortes_dia', 'desfase_dia',
        'saldo_fin_calc_desp', 'saldo_fin_calc_cortes', 'saldo_fin_sistema', 'saldo_fin_vehiculos', 'disponible',
    ];

    /**
     * Una fila por cliente crédito (tipval 3, codest <> -1) con los
     * movimientos DEL día $dia y, con $conLectura, la lectura actual de cresdo,
     * la suma de vehículos y lo posterior al día (despachos y abonos) para
     * reconstruir el cierre. No recorre el histórico: el calculado va
     * encadenado (CreditCierre::armarFila).
     */
    public function calcularDia(string $dia, bool $conLectura): array
    {
        $query = "
            SET NOCOUNT ON;
            DECLARE @d int = ?, @lec bit = ?;
            DROP TABLE IF EXISTS #u, #des, #cor, #mov, #fac;

            SELECT C.cod, C.den, CAST(ISNULL(C.debglo, 0) AS tinyint) AS debglo,
                   CAST(C.cresdo AS decimal(18,2)) AS cresdo,
                   CAST(ISNULL(C.mtoasg, 0) AS decimal(18,2)) AS limite
              INTO #u
              FROM [SG12].dbo.Clientes C WITH (NOLOCK)
             WHERE C.tipval = 3 AND C.codest <> -1;
            CREATE UNIQUE CLUSTERED INDEX ix ON #u (cod);

            -- Despachos del día y posteriores (cresdo ya los trae a la hora de la lectura)
            SELECT d.codcli AS cod,
                   SUM(CASE WHEN d.fchtrn = @d THEN d.mto ELSE 0 END) AS dia,
                   SUM(CASE WHEN d.fchtrn > @d THEN d.mto ELSE 0 END) AS post
              INTO #des
              FROM [SG12].dbo.Despachos d WITH (NOLOCK)
              JOIN #u ON #u.cod = d.codcli
             WHERE d.fchtrn >= @d
             GROUP BY d.codcli
            OPTION (RECOMPILE);

            -- Cortes: vales crédito (codval 28) por fecha del corte
            SELECT r.codcli AS cod, SUM(r.mto) AS dia
              INTO #cor
              FROM [SG12].dbo.ValesR r WITH (NOLOCK)
              JOIN #u ON #u.cod = r.codcli
             WHERE r.codval = 28 AND r.fch = @d
             GROUP BY r.codcli
            OPTION (RECOMPILE);

            -- Cobros (tipope 4) y notas de crédito (tipope 6): el movimiento (tipmov 1),
            -- no sus aplicaciones (tipmov 2). Vienen negativos en centavos.
            SELECT m.codopr AS cod,
                   -SUM(CASE WHEN m.tipope = 4 AND m.fchope = @d THEN m.mtoopeori ELSE 0 END) / 100.0 AS cobros,
                   -SUM(CASE WHEN m.tipope = 6 AND m.fchope = @d THEN m.mtoopeori ELSE 0 END) / 100.0 AS notas,
                   -SUM(CASE WHEN m.fchope > @d THEN m.mtoopeori ELSE 0 END) / 100.0 AS post
              INTO #mov
              FROM [SG12].dbo.MovimientosAnl m WITH (NOLOCK)
              JOIN #u ON #u.cod = m.codopr
             WHERE m.tipopr = 1 AND m.tipmov = 1 AND m.tipope IN (4, 6) AND m.fchope >= @d
             GROUP BY m.codopr
            OPTION (RECOMPILE);

            -- Facturas del día (tip 3, sin canceladas): informativo, no mueven el saldo
            SELECT t2.codopr AS cod, SUM(t2.mtoori + t2.mtoiva) / 100.0 AS dia
              INTO #fac
              FROM [SG12].dbo.DocumentosC t1 WITH (NOLOCK)
              JOIN [SG12].dbo.Documentos t2 WITH (NOLOCK)
                ON t1.nro = t2.nro AND t1.codgas = t2.codgas AND t1.tip = t2.tip
              JOIN #u ON #u.cod = t2.codopr
             WHERE t1.tip = 3 AND t1.fch = @d AND ISNULL(t1.flgcon, 0) <> 141
             GROUP BY t2.codopr
            OPTION (RECOMPILE);

            SELECT u.cod AS codcli, u.den AS cliente, u.debglo, u.limite,
                   CAST(ISNULL(mv.cobros, 0) AS decimal(18,2)) AS cobros_dia,
                   CAST(ISNULL(mv.notas, 0)  AS decimal(18,2)) AS notas_dia,
                   CAST(ISNULL(f.dia, 0)     AS decimal(18,2)) AS facturado_dia,
                   CAST(ISNULL(de.dia, 0)    AS decimal(18,2)) AS consumos_desp_dia,
                   CAST(ISNULL(co.dia, 0)    AS decimal(18,2)) AS consumos_cortes_dia,
                   CASE WHEN @lec = 1 THEN u.cresdo END AS lectura_cresdo,
                   CASE WHEN @lec = 1 THEN ISNULL(v.s, 0) END AS lectura_vehiculos,
                   CASE WHEN @lec = 1 THEN CAST(ISNULL(de.post, 0) AS decimal(18,2)) END AS consumos_post_cierre,
                   CASE WHEN @lec = 1 THEN CAST(ISNULL(mv.post, 0) AS decimal(18,2)) END AS abonos_post_cierre,
                   CASE WHEN @lec = 1 THEN CONVERT(varchar(19), GETDATE(), 120) END AS lectura_at
              FROM #u u
              LEFT JOIN #des de ON de.cod = u.cod
              LEFT JOIN #cor co ON co.cod = u.cod
              LEFT JOIN #mov mv ON mv.cod = u.cod
              LEFT JOIN #fac f  ON f.cod  = u.cod
              LEFT JOIN (SELECT codcli, CAST(SUM(debsdo) AS decimal(18,2)) AS s
                           FROM [SG12].dbo.ClientesVehiculos WITH (NOLOCK)
                          GROUP BY codcli) v ON v.codcli = u.cod
             ORDER BY u.cod;

            DROP TABLE IF EXISTS #u, #des, #cor, #mov, #fac;";
        return $this->sql->select($query, [dateToInt($dia), $conLectura ? 1 : 0]) ?: [];
    }
}
