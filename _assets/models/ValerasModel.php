<?php
/**
 * ValerasModel — Ingresos > Valeras: consumo de valeras que declara el corte
 * (SG12.Ingresos), por día y razón social.
 *
 * Valeras = los conceptos de SG12.Valores que VentasModel::conceptosValeras()
 * clasifica como VALERAS, para que los totales cuadren con Venta por Rubro.
 * Fecha del corte: Ingresos.fch es serial de ControlGas; el día real es fch - 1.
 *
 * $empresa: 0 = todas, -1 = estaciones sin razón social, >0 = SG12.Empresas.cod
 * (razón social de la estación, igual que Ingresos > Conciliación).
 */
class ValerasModel extends Model {

    /**
     * Una fila por día, razón social, estación y concepto de valera: fecha (Y-m-d), empresa, estacion, concepto, monto.
     * $from / $until son enteros de ControlGas (dateToInt).
     */
    public function consumo_diario(int $from, int $until, int $empresa): array {
        $valeras = (new VentasModel)->conceptosValeras();
        $query = "
            SET NOCOUNT ON;
            DECLARE @d int = ?, @h int = ?, @emp int = ?;

            SELECT CONVERT(varchar(10), CONVERT(DATE, DATEADD(DAY, -1, i.fch)), 23) AS fecha,
                   ISNULL(EMP.den, N'(Sin razón social)') AS empresa,
                   E.Nombre AS estacion,
                   LTRIM(v.den) AS concepto,
                   SUM(i.mto) AS monto
              FROM SG12.dbo.Ingresos i WITH (NOLOCK)
              JOIN SG12.dbo.Valores v WITH (NOLOCK) ON v.cod = i.codval
              JOIN TG.dbo.Estaciones E WITH (NOLOCK) ON E.Codigo = i.codgas
              LEFT JOIN SG12.dbo.Empresas EMP WITH (NOLOCK) ON EMP.cod = E.codemp
             WHERE i.fch BETWEEN @d AND @h
               AND v.den IN ({$valeras})
               AND (@emp = 0 OR (@emp = -1 AND EMP.cod IS NULL) OR EMP.cod = @emp)
             GROUP BY CONVERT(DATE, DATEADD(DAY, -1, i.fch)), ISNULL(EMP.den, N'(Sin razón social)'), E.Nombre, LTRIM(v.den)
             ORDER BY 1, 2, 3, 4
            OPTION (RECOMPILE);";
        return $this->sql->select($query, [$from, $until, $empresa]) ?: [];
    }
}
