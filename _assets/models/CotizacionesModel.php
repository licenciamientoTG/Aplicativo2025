<?php
class CotizacionesModel extends Model{
    public $codmda;
    public $codgas;
    public $fch;
    public $hra;
    public $ctz;
    public $ctzcom;
    public $ctzven;
    public $codpza;
    public $codcpo;
    public $logusu;
    public $logfch;
    public $lognew;

    /**
     * @param $codgas
     * @return float|false
     * @throws Exception
     */
    public function get_last_exchange($codgas): float|false {
        $query = "SELECT TOP 1 ctz AS last_exchange FROM {$this->databases[$codgas]}.Cotizaciones WHERE codgas = ? ORDER BY logfch DESC;";
        if (empty($rs=$this->sql->select($query, [$codgas]))) {
            $query = "SELECT TOP 1 ctz AS last_exchange FROM {$this->databases[$codgas]}.Cotizaciones ORDER BY logfch DESC;";
            return ($rs=$this->sql->select($query, [])) ? number_format($rs[0]['last_exchange'], 2) : false ;
        }
        return ($rs=$this->sql->select($query, [$codgas])) ? number_format($rs[0]['last_exchange'], 2) : false ;
    }

    function get_exchange_rates() : array|false {
        $query = "
        WITH cte AS (
            SELECT *,
                 CAST(CONVERT(VARCHAR(100), CAST(fch AS DATETIME) - 1, 23) AS VARCHAR(10)) AS Fecha
            FROM (
            
            SELECT TOP (1) [codmda], [codgas], [fch], CONCAT(RIGHT('00' + CAST(FLOOR(hra / 100) AS VARCHAR(2)), 2), ':', RIGHT('00' + CAST(hra % 100 AS VARCHAR(2)), 2)) AS hra_format, [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew], N'Gemela Grande' AS station_name, N'4188' AS no_station, N'Sin casa de cambio aledaña' AS description 
            FROM OPENQUERY([192.168.7.101], '
                SELECT TOP (1) [codmda], [codgas], [fch], [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew] FROM [SG12_41882020].[dbo].[Cotizaciones]
                WHERE codgas = 2 ORDER BY lognew DESC
            ')
            
            -- UNION ALL
            
            -- SELECT TOP (1) [codmda], [codgas], [fch], CONCAT(RIGHT('00' + CAST(FLOOR(hra / 100) AS VARCHAR(2)), 2), ':', RIGHT('00' + CAST(hra % 100 AS VARCHAR(2)), 2)) AS hra_format, [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew], N'Independencia' AS station_name, N'11007' AS no_station, N'Foranea' AS description
            -- FROM OPENQUERY([192.168.28.101], '
            --     SELECT TOP (1) [codmda], [codgas], [fch], [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew] FROM [Sg12_11007+2020].[dbo].[Cotizaciones]
            --     WHERE codgas = 3 ORDER BY lognew DESC
            -- ')
            
            UNION ALL
            
            SELECT TOP (1) [codmda], [codgas], [fch], CONCAT(RIGHT('00' + CAST(FLOOR(hra / 100) AS VARCHAR(2)), 2), ':', RIGHT('00' + CAST(hra % 100 AS VARCHAR(2)), 2)) AS hra_format, [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew], N'Lerdo' AS station_name, N'1149' AS no_station, N'Sin casa de cambio aledaña' AS description
            FROM OPENQUERY([192.168.2.101], '
                SELECT TOP (1) [codmda], [codgas], [fch], [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew] FROM [SG12_114912020].[dbo].[Cotizaciones]
                WHERE codgas = 5 ORDER BY lognew DESC
            ')
            
            UNION ALL
            
            SELECT TOP (1) [codmda], [codgas], [fch], CONCAT(RIGHT('00' + CAST(FLOOR(hra / 100) AS VARCHAR(2)), 2), ':', RIGHT('00' + CAST(hra % 100 AS VARCHAR(2)), 2)) AS hra_format, [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew], N'Lopez Mateos' AS station_name, N'2526' AS no_station, N'Casa de cambio terceros' AS description
            FROM OPENQUERY([192.168.5.101], '
                SELECT TOP (1) [codmda], [codgas], [fch], [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew] FROM [SG12_25262020].[dbo].[Cotizaciones]
                WHERE codgas = 6 ORDER BY lognew DESC
            ')
            
            UNION ALL
            
            SELECT TOP (1) [codmda], [codgas], [fch], CONCAT(RIGHT('00' + CAST(FLOOR(hra / 100) AS VARCHAR(2)), 2), ':', RIGHT('00' + CAST(hra % 100 AS VARCHAR(2)), 2)) AS hra_format, [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew], N'Gemela Chica' AS station_name, N'4179' AS no_station, N'Sin casa de cambio aledaña' AS description
            FROM OPENQUERY([192.168.6.101], '
                SELECT TOP (1) [codmda], [codgas], [fch], [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew] FROM [SG12_4179_20].[dbo].[Cotizaciones]
                WHERE codgas = 7 ORDER BY lognew DESC
            ')
            
            UNION ALL
            
            SELECT TOP (1) [codmda], [codgas], [fch], CONCAT(RIGHT('00' + CAST(FLOOR(hra / 100) AS VARCHAR(2)), 2), ':', RIGHT('00' + CAST(hra % 100 AS VARCHAR(2)), 2)) AS hra_format, [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew], N'Municipio Libre' AS station_name, N'5317' AS no_station, N'Casa de cambio Dollar2Go' AS description
            FROM OPENQUERY([192.168.9.101], '
                SELECT TOP (1) [codmda], [codgas], [fch], [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew] FROM [SG12_53172020].[dbo].[Cotizaciones]
                WHERE codgas = 8 ORDER BY lognew DESC
            ')
            
            UNION ALL
            
            SELECT TOP (1) [codmda], [codgas], [fch], CONCAT(RIGHT('00' + CAST(FLOOR(hra / 100) AS VARCHAR(2)), 2), ':', RIGHT('00' + CAST(hra % 100 AS VARCHAR(2)), 2)) AS hra_format, [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew], N'Aztecas' AS station_name, N'5465' AS no_station, N'Casa de cambio terceros' AS description
            FROM OPENQUERY([192.168.10.101], '
                SELECT TOP (1) [codmda], [codgas], [fch], [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew] FROM [SG12_5465].[dbo].[Cotizaciones]
                WHERE codgas = 9 ORDER BY lognew DESC
            ')
            
            UNION ALL
            
            SELECT TOP (1) [codmda], [codgas], [fch], CONCAT(RIGHT('00' + CAST(FLOOR(hra / 100) AS VARCHAR(2)), 2), ':', RIGHT('00' + CAST(hra % 100 AS VARCHAR(2)), 2)) AS hra_format, [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew], N'Misiones' AS station_name, N'6410' AS no_station, N'Casa de cambio Dollar2Go' AS description
            FROM OPENQUERY([192.168.11.101], '
                SELECT TOP (1) [codmda], [codgas], [fch], [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew] FROM [SG12_6410].[dbo].[Cotizaciones]
                WHERE codgas = 10 ORDER BY lognew DESC
            ')
            
            UNION ALL
            
            SELECT TOP (1) [codmda], [codgas], [fch], CONCAT(RIGHT('00' + CAST(FLOOR(hra / 100) AS VARCHAR(2)), 2), ':', RIGHT('00' + CAST(hra % 100 AS VARCHAR(2)), 2)) AS hra_format, [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew], N'Puerto de palos' AS station_name, N'6947' AS no_station, N'Casa de cambio Dollar2Go' AS description
            FROM OPENQUERY([192.168.19.101], '
                SELECT TOP (1) [codmda], [codgas], [fch], [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew] FROM [SG12_6947_2020].[dbo].[Cotizaciones]
                WHERE codgas = 11 ORDER BY lognew DESC
            ')
            
            UNION ALL
            
            SELECT TOP (1) [codmda], [codgas], [fch], CONCAT(RIGHT('00' + CAST(FLOOR(hra / 100) AS VARCHAR(2)), 2), ':', RIGHT('00' + CAST(hra % 100 AS VARCHAR(2)), 2)) AS hra_format, [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew], N'Miguel de la madrid' AS station_name, N'7167' AS no_station, N'Sin casa de cambio aledaña' AS description
            FROM OPENQUERY([192.168.13.101], '
                SELECT TOP (1) [codmda], [codgas], [fch], [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew] FROM [CG_7167].[dbo].[Cotizaciones]
                WHERE codgas = 12 ORDER BY lognew DESC
            ')
            
            UNION ALL
            
            SELECT TOP (1) [codmda], [codgas], [fch], CONCAT(RIGHT('00' + CAST(FLOOR(hra / 100) AS VARCHAR(2)), 2), ':', RIGHT('00' + CAST(hra % 100 AS VARCHAR(2)), 2)) AS hra_format, [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew], N'Permuta' AS station_name, N'8244' AS no_station, N'Casa de cambio Dollar2Go' AS description
            FROM OPENQUERY([192.168.14.101], '
                SELECT TOP (1) [codmda], [codgas], [fch], [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew] FROM [SG12_8244].[dbo].[Cotizaciones]
                WHERE codgas = 13 ORDER BY lognew DESC
            ')
            
            UNION ALL
            
            SELECT TOP (1) [codmda], [codgas], [fch], CONCAT(RIGHT('00' + CAST(FLOOR(hra / 100) AS VARCHAR(2)), 2), ':', RIGHT('00' + CAST(hra % 100 AS VARCHAR(2)), 2)) AS hra_format, [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew], N'Electrolux' AS station_name, N'9191' AS no_station, N'Sin casa de cambio aledaña' AS description
            FROM OPENQUERY([192.168.15.101], '
                SELECT TOP (1) [codmda], [codgas], [fch], [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew] FROM [SG12_9191].[dbo].[Cotizaciones]
                WHERE codgas = 14 ORDER BY lognew DESC
            ')
            
            UNION ALL
            
            SELECT TOP (1) [codmda], [codgas], [fch], CONCAT(RIGHT('00' + CAST(FLOOR(hra / 100) AS VARCHAR(2)), 2), ':', RIGHT('00' + CAST(hra % 100 AS VARCHAR(2)), 2)) AS hra_format, [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew], N'Aeronáutica' AS station_name, N'9235' AS no_station, N'Casa de cambio terceros' AS description
            FROM OPENQUERY([192.168.16.101], '
                SELECT TOP (1) [codmda], [codgas], [fch], [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew] FROM [SG12_92352020].[dbo].[Cotizaciones]
                WHERE codgas = 15 ORDER BY lognew DESC
            ')
            
            UNION ALL
            
            SELECT TOP (1) [codmda], [codgas], [fch], CONCAT(RIGHT('00' + CAST(FLOOR(hra / 100) AS VARCHAR(2)), 2), ':', RIGHT('00' + CAST(hra % 100 AS VARCHAR(2)), 2)) AS hra_format, [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew], N'Custodia' AS station_name, N'9885' AS no_station, N'Casa de cambio terceros' AS description
            FROM OPENQUERY([192.168.17.101], '
                SELECT TOP (1) [codmda], [codgas], [fch], [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew] FROM [SG12_98852020].[dbo].[Cotizaciones]
                WHERE codgas = 16 ORDER BY lognew DESC
            ')
            
            UNION ALL
            
            SELECT TOP (1) [codmda], [codgas], [fch], CONCAT(RIGHT('00' + CAST(FLOOR(hra / 100) AS VARCHAR(2)), 2), ':', RIGHT('00' + CAST(hra % 100 AS VARCHAR(2)), 2)) AS hra_format, [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew], N'Anapra' AS station_name, N'9893' AS no_station, N'Casa de cambio Dollar2Go' AS description
            FROM OPENQUERY([192.168.18.101], '
                SELECT TOP (1) [codmda], [codgas], [fch], [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew] FROM [SG12_9893].[dbo].[Cotizaciones]
                WHERE codgas = 17 ORDER BY lognew DESC
            ')
            
            --UNION ALL
            
            --SELECT TOP (1) [codmda], [codgas], [fch], CONCAT(RIGHT('00' + CAST(FLOOR(hra / 100) AS VARCHAR(2)), 2), ':', RIGHT('00' + CAST(hra % 100 AS VARCHAR(2)), 2)) AS hra_format, [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew], N'Parral' AS station_name, N'2172' AS no_station, N'Foranea' AS description
            --FROM OPENQUERY([192.168.4.101], '
            --    SELECT TOP (1) [codmda], [codgas], [fch], [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew] FROM [sg2172].[dbo].[Cotizaciones]
            --    WHERE codgas = 18 ORDER BY lognew DESC
            --')
            
            UNION ALL
            
            SELECT TOP (1) [codmda], [codgas], [fch], CONCAT(RIGHT('00' + CAST(FLOOR(hra / 100) AS VARCHAR(2)), 2), ':', RIGHT('00' + CAST(hra % 100 AS VARCHAR(2)), 2)) AS hra_format, [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew], N'Delicias' AS station_name, N'1376' AS no_station, N'Foranea' AS description
            FROM OPENQUERY([192.168.3.101], '
                SELECT TOP (1) [codmda], [codgas], [fch], [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew] FROM [CG_1376].[dbo].[Cotizaciones]
                WHERE codgas = 19 ORDER BY lognew DESC
            ')
            
            UNION ALL
            
            SELECT TOP (1) [codmda], [codgas], [fch], CONCAT(RIGHT('00' + CAST(FLOOR(hra / 100) AS VARCHAR(2)), 2), ':', RIGHT('00' + CAST(hra % 100 AS VARCHAR(2)), 2)) AS hra_format, [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew], N'Plutarco' AS station_name, N'5170' AS no_station, N'Casa de cambio Dollar2Go' AS description
            FROM OPENQUERY([192.168.8.101], '
                SELECT TOP (1) [codmda], [codgas], [fch], [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew] FROM [Custodia5170].[dbo].[Cotizaciones]
                WHERE codgas = 21 ORDER BY lognew DESC
            ')
            
            UNION ALL
            
            SELECT TOP (1) [codmda], [codgas], [fch], CONCAT(RIGHT('00' + CAST(FLOOR(hra / 100) AS VARCHAR(2)), 2), ':', RIGHT('00' + CAST(hra % 100 AS VARCHAR(2)), 2)) AS hra_format, [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew], N'Tecnológico' AS station_name, N'1163' AS no_station, N'Sin casa de cambio aledaña' AS description
            FROM OPENQUERY([192.168.30.101], '
                SELECT TOP (1) [codmda], [codgas], [fch], [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew] FROM [CG_1163].[dbo].[Cotizaciones]
                WHERE codgas = 22 ORDER BY lognew DESC
            ')
            
            UNION ALL
            
            SELECT TOP (1) [codmda], [codgas], [fch], CONCAT(RIGHT('00' + CAST(FLOOR(hra / 100) AS VARCHAR(2)), 2), ':', RIGHT('00' + CAST(hra % 100 AS VARCHAR(2)), 2)) AS hra_format, [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew], N'Ejército Nacional' AS station_name, N'9733' AS no_station, N'Alianza comercial' AS description
            FROM OPENQUERY([192.168.21.101], '
                SELECT TOP (1) [codmda], [codgas], [fch], [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew] FROM [CG_9733].[dbo].[Cotizaciones]
                WHERE codgas = 23 ORDER BY lognew DESC
            ')
            
            UNION ALL
            
            SELECT TOP (1) [codmda], [codgas], [fch], CONCAT(RIGHT('00' + CAST(FLOOR(hra / 100) AS VARCHAR(2)), 2), ':', RIGHT('00' + CAST(hra % 100 AS VARCHAR(2)), 2)) AS hra_format, [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew], N'Satélite' AS station_name, N'4457' AS no_station, N'Alianza comercial' AS description
            FROM OPENQUERY([192.168.22.101], '
                SELECT TOP (1) [codmda], [codgas], [fch], [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew] FROM [CG_4457].[dbo].[Cotizaciones]
                WHERE codgas = 24 ORDER BY lognew DESC
            ')
            
            UNION ALL
            
            SELECT TOP (1) [codmda], [codgas], [fch], CONCAT(RIGHT('00' + CAST(FLOOR(hra / 100) AS VARCHAR(2)), 2), ':', RIGHT('00' + CAST(hra % 100 AS VARCHAR(2)), 2)) AS hra_format, [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew], N'Las fuentes' AS station_name, N'1159' AS no_station, N'Alianza comercial' AS description
            FROM OPENQUERY([192.168.23.101], '
                SELECT TOP (1) [codmda], [codgas], [fch], [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew] FROM [cg_1159].[dbo].[Cotizaciones]
                WHERE codgas = 25 ORDER BY lognew DESC
            ')
            
            UNION ALL
            
            SELECT TOP (1) [codmda], [codgas], [fch], CONCAT(RIGHT('00' + CAST(FLOOR(hra / 100) AS VARCHAR(2)), 2), ':', RIGHT('00' + CAST(hra % 100 AS VARCHAR(2)), 2)) AS hra_format, [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew], N'Clara' AS station_name, N'1156' AS no_station, N'Alianza comercial' AS description
            FROM OPENQUERY([192.168.24.101], '
                SELECT TOP (1) [codmda], [codgas], [fch], [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew] FROM [CG_1156].[dbo].[Cotizaciones]
                WHERE codgas = 26 ORDER BY lognew DESC
            ')
            
            UNION ALL
            
            SELECT TOP (1) [codmda], [codgas], [fch], CONCAT(RIGHT('00' + CAST(FLOOR(hra / 100) AS VARCHAR(2)), 2), ':', RIGHT('00' + CAST(hra % 100 AS VARCHAR(2)), 2)) AS hra_format, [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew], N'Solis' AS station_name, N'10141' AS no_station, N'Alianza comercial' AS description
            FROM OPENQUERY([192.168.25.101], '
                SELECT TOP (1) [codmda], [codgas], [fch], [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew] FROM [CG_10141].[dbo].[Cotizaciones]
                WHERE codgas = 27 ORDER BY lognew DESC
            ')
            
            UNION ALL
            SELECT TOP (1) [codmda], [codgas], [fch], CONCAT(RIGHT('00' + CAST(FLOOR(hra / 100) AS VARCHAR(2)), 2), ':', RIGHT('00' + CAST(hra % 100 AS VARCHAR(2)), 2)) AS hra_format, [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew], N'Santiago Troncoso' AS station_name, N'12097' AS no_station, N'Alianza comercial' AS description
            FROM OPENQUERY([192.168.26.101], '
                SELECT TOP (1) [codmda], [codgas], [fch], [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew] FROM [SG12_12097].[dbo].[Cotizaciones]
                WHERE codgas = 28 ORDER BY lognew DESC
            ')
            UNION ALL
            SELECT TOP (1) [codmda], [codgas], [fch], CONCAT(RIGHT('00' + CAST(FLOOR(hra / 100) AS VARCHAR(2)), 2), ':', RIGHT('00' + CAST(hra % 100 AS VARCHAR(2)), 2)) AS hra_format, [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew], N'Jarudo' AS station_name, N'1148' AS no_station, N'Alianza comercial' AS description
            FROM OPENQUERY([192.168.27.101], '
                SELECT TOP (1) [codmda], [codgas], [fch], [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew] FROM [CG_1148].[dbo].[Cotizaciones]
                WHERE codgas = 29 ORDER BY lognew DESC
            ')
            UNION ALL
            SELECT TOP (1) [codmda], [codgas], [fch], CONCAT(RIGHT('00' + CAST(FLOOR(hra / 100) AS VARCHAR(2)), 2), ':', RIGHT('00' + CAST(hra % 100 AS VARCHAR(2)), 2)) AS hra_format, [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew], N'Hermanos Escobar' AS station_name, N'23214' AS no_station, N'Casa de cambio Dollar2Go' AS description
            FROM OPENQUERY([192.168.29.101], '
                SELECT TOP (1) [codmda], [codgas], [fch], [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew] FROM [CG_23214].[dbo].[Cotizaciones]
                WHERE codgas = 30 ORDER BY lognew DESC
            ')
            UNION ALL
            SELECT TOP (1) [codmda], [codgas], [fch], CONCAT(RIGHT('00' + CAST(FLOOR(hra / 100) AS VARCHAR(2)), 2), ':', RIGHT('00' + CAST(hra % 100 AS VARCHAR(2)), 2)) AS hra_format, [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew], N'Villa Ahumada' AS station_name, N'1242' AS no_station, N'Foranea' AS description
            FROM OPENQUERY([192.168.32.101], '
                SELECT TOP (1) [codmda], [codgas], [fch], [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew] FROM [CG_1242].[dbo].[Cotizaciones]
                WHERE codgas = 31 ORDER BY lognew DESC
            ')
            UNION ALL
            SELECT TOP (1) [codmda], [codgas], [fch], CONCAT(RIGHT('00' + CAST(FLOOR(hra / 100) AS VARCHAR(2)), 2), ':', RIGHT('00' + CAST(hra % 100 AS VARCHAR(2)), 2)) AS hra_format, [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew], N'El castaño' AS station_name, N'19190' AS no_station, N'Foranea' AS description
            FROM OPENQUERY([192.168.33.101], '
                SELECT TOP (1) [codmda], [codgas], [fch], [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew] FROM [CG_19190].[dbo].[Cotizaciones]
                WHERE codgas = 32 ORDER BY lognew DESC
            ')
            UNION ALL
            SELECT TOP (1) [codmda], [codgas], [fch], CONCAT(RIGHT('00' + CAST(FLOOR(hra / 100) AS VARCHAR(2)), 2), ':', RIGHT('00' + CAST(hra % 100 AS VARCHAR(2)), 2)) AS hra_format, [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew], N'Travel Center' AS station_name, N'24938' AS no_station, N'Casa de cambio Dollar2Go' AS description
            FROM OPENQUERY([192.168.31.101], '
                SELECT TOP (1) [codmda], [codgas], [fch], [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew] FROM [CG_24938].[dbo].[Cotizaciones]
                WHERE codgas = 33 ORDER BY lognew DESC
            ')
            UNION ALL
            SELECT TOP (1) [codmda], [codgas], [fch], CONCAT(RIGHT('00' + CAST(FLOOR(hra / 100) AS VARCHAR(2)), 2), ':', RIGHT('00' + CAST(hra % 100 AS VARCHAR(2)), 2)) AS hra_format, [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew], N'Picachos' AS station_name, N'24499' AS no_station, N'Foranea' AS description
            FROM OPENQUERY([192.168.34.101], '
                SELECT TOP (1) [codmda], [codgas], [fch], [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew] FROM [CG_24499].[dbo].[Cotizaciones]
                WHERE codgas = 34 ORDER BY lognew DESC
            ')
            UNION ALL
            SELECT TOP (1) [codmda], [codgas], [fch], CONCAT(RIGHT('00' + CAST(FLOOR(hra / 100) AS VARCHAR(2)), 2), ':', RIGHT('00' + CAST(hra % 100 AS VARCHAR(2)), 2)) AS hra_format, [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew], N'Ventanas' AS station_name, N'24500' AS no_station, N'Foranea' AS description
            FROM OPENQUERY([192.168.35.101], '
                SELECT TOP (1) [codmda], [codgas], [fch], [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew] FROM [CG_24500].[dbo].[Cotizaciones]
                WHERE codgas = 35 ORDER BY lognew DESC
            ')
            
            UNION ALL
            
            SELECT TOP (1) [codmda], [codgas], [fch], CONCAT(RIGHT('00' + CAST(FLOOR(hra / 100) AS VARCHAR(2)), 2), ':', RIGHT('00' + CAST(hra % 100 AS VARCHAR(2)), 2)) AS hra_format, [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew], N'San Rafael' AS station_name, N'14946' AS no_station, N'Foranea' AS description
            FROM OPENQUERY([192.168.36.101], '
                SELECT TOP (1) [codmda], [codgas], [fch], [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew] FROM [CG_14946].[dbo].[Cotizaciones]
                WHERE codgas = 36 ORDER BY lognew DESC
            ')
            
            UNION ALL
            
            SELECT TOP (1) [codmda], [codgas], [fch], CONCAT(RIGHT('00' + CAST(FLOOR(hra / 100) AS VARCHAR(2)), 2), ':', RIGHT('00' + CAST(hra % 100 AS VARCHAR(2)), 2)) AS hra_format, [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew], N'Puertecito' AS station_name, N'15071' AS no_station, N'Foranea' AS description
            FROM OPENQUERY([192.168.37.101], '
                SELECT TOP (1) [codmda], [codgas], [fch], [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew] FROM [CG_15071].[dbo].[Cotizaciones]
                WHERE codgas = 37 ORDER BY lognew DESC
            ')
             UNION ALL
            
            SELECT TOP (1) [codmda], [codgas], [fch], CONCAT(RIGHT('00' + CAST(FLOOR(hra / 100) AS VARCHAR(2)), 2), ':', RIGHT('00' + CAST(hra % 100 AS VARCHAR(2)), 2)) AS hra_format, [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew], N'Praxedis' AS station_name, N'10702' AS no_station, N'Foranea' AS description
            FROM OPENQUERY([192.168.40.101], '
                SELECT TOP (1) [codmda], [codgas], [fch], [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew] FROM [E10702].[dbo].[Cotizaciones]
                WHERE codgas = 40 ORDER BY lognew DESC
            ')
          ) AS inner_cte
        )
        SELECT * FROM cte;
        ";
        return $this->sql->select($query);
    }

    function insert($codmda, $codgas, $from, $hour, $cot, $tg_user) : bool {
        $query = "INSERT INTO [SG12].[dbo].[Cotizaciones] ([codmda],[codgas],[fch],[hra],[ctz],[ctzcom],[ctzven],[codpza],[codcpo],[logusu],[logfch],[lognew]) VALUES (?,?,?,?,?,?,?,0,0,?,GETDATE(),GETDATE());";
        $params = [$codmda, $codgas, $from, $hour, $cot, $cot, $cot, $tg_user];

        return (bool)$this->sql->insert($query, $params);
    }

    function insert_remote($codmda, $codgas, $from, $hour, $cot, $tg_user) : bool {
        $query = "INSERT INTO {$this->databases[$codgas]}.[Cotizaciones] ([codmda],[codgas],[fch],[hra],[ctz],[ctzcom],[ctzven],[codpza],[codcpo],[logusu],[logfch],[lognew])
                  VALUES ({$codmda},{$codgas},{$from},{$hour},{$cot},{$cot},{$cot},0,0,?,GETDATE(),GETDATE());";
        return (bool)$this->sql->insert($query, [$tg_user]);
    }

    function update($codmda, $codgas, $fch, $hra, $value) : bool {
        $query = "
            UPDATE [SG12].[dbo].[Cotizaciones]
            SET [ctz] = {$value}
                ,[ctzcom] = {$value}
                ,[ctzven] = {$value}
            WHERE codmda = {$codmda} AND codgas = {$codgas} AND fch = {$fch} AND hra = ?;
        ";

        return (bool)$this->sql->update($query, [$hra]);
    }

    function update_remote($codmda, $codgas, $fch, $hra, $value) : bool {
        $query = "
            UPDATE {$this->databases[$codgas]}.[Cotizaciones]
            SET [ctz] = {$value}
                ,[ctzcom] = {$value}
                ,[ctzven] = {$value}
            WHERE codmda = {$codmda} AND codgas = {$codgas} AND fch = {$fch} AND hra = ?;
        ";
        return (bool)$this->sql->update($query, [$hra]);
    }

    function delete($codmda, $codgas, $fch, $hra) : bool {
        $query = "DELETE FROM [SG12].[dbo].[Cotizaciones] WHERE codmda = {$codmda} AND codgas = {$codgas} AND fch = {$fch} AND hra = ?;";
        return (bool)$this->sql->delete($query, [$hra]);
    }

    function delete_remote($codmda, $codgas, $fch, $hra) : bool {
        $query = "DELETE FROM {$this->databases[$codgas]}.[Cotizaciones] WHERE codmda = {$codmda} AND codgas = {$codgas} AND fch = {$fch} AND hra = ?;";
        return (bool)$this->sql->delete($query, [$hra]);
    }
}