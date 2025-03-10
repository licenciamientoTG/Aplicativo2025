<?php
class XmlCreModel extends Model{
    public $Id;
    public $NumeroPermisoCRE;
    public $FechaYHoraCorte;
    public $RfcProveedorSw;
    public $Rfc;
    public $FechaCreacion;
    public $RutaXml;

    function get_pendings() : array|false {
        $query = "  DECLARE @StartDate DATE = DATEADD(day, -40, GETDATE());
                    DECLARE @EndDate DATE = DATEADD(DAY, -1, GETDATE());

                    WITH DateRange AS (
                        SELECT DATEADD(day, number, @StartDate) AS Date
                        FROM master..spt_values
                        WHERE type = 'P'
                        AND DATEADD(day, number, @StartDate) <= @EndDate
                    ),

                    Stations AS (
                        SELECT Codigo, Nombre, PermisoCRE, RutaVolumetricos FROM [TG].[dbo].[Estaciones] WHERE Codigo NOT IN(0,4,20) AND PermisoCRE IS NOT NULL AND PermisoCRE != ''
                    )

                    SELECT s.Codigo, s.Nombre, s.PermisoCRE, dr.Date AS Fecha, s.RutaVolumetricos
                    FROM DateRange dr
                    CROSS JOIN Stations s
                    LEFT JOIN [TG].[dbo].[XmlCre] x
                        ON CAST(x.FechaYHoraCorte AS DATE) = dr.Date
                        AND x.NumeroPermisoCRE = s.PermisoCRE
                    WHERE x.FechaYHoraCorte IS NULL
                    ORDER BY s.PermisoCRE, dr.Date DESC;";
        return ($this->sql->select($query)) ?: false ;
    }

    function get_estimulus($Inicial, $Final, $Est87, $Est91): array {
        return $this->sql->executeStoredProcedure('[TG].[dbo].[sp_ObtenerDespachoIncentivo]', [dateToInt($Inicial), dateToInt($Final), $Est87, $Est91]);
    }
}