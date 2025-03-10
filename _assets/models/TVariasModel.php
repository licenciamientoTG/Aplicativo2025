<?php
class TVariasModel extends Model{
    public $tabl;
    public $codl;
    public $codgasl;
    public $denl;
    public $abrl;
    public $ordl;
    public $tabsupl;
    public $codsupl;
    public $codusul;
    public $logusul;
    public $logfchl;
    public $lognewl;
    public $datrefl;

    function get_ieps() {
        // Vamos a obtener el año actual
        $anio = date('Y');
        $query = "
            SELECT *
            FROM (
                SELECT TOP 1 *, 'Diesel Automotriz' AS Producto
                FROM [SG12].[dbo].[TVarias]
                WHERE tab = 26 AND cod LIKE '{$anio}%' AND codgas IN (34015, 34006)
                ORDER BY abr DESC, codgas DESC
            ) AS DieselAutomotriz
            
            UNION ALL

            SELECT *
            FROM (
                SELECT TOP 1 *, 'T-Super Premium' AS Producto
                FROM [SG12].[dbo].[TVarias]
                WHERE tab = 26 AND cod LIKE '{$anio}%' AND codgas IN (32026, 32012, 23091)
                ORDER BY abr DESC, codgas DESC
            ) AS TSuperPremium
            
            UNION ALL
            
            SELECT *
            FROM (
                SELECT TOP 1 *, 'T-Maxima Regular' AS Producto
                FROM [SG12].[dbo].[TVarias]
                WHERE tab = 26 AND cod LIKE '{$anio}%' AND codgas IN (32025, 32011, 23079)
                ORDER BY abr DESC, codgas DESC
            ) AS TMaximaRegular;
        ";
        return $this->sql->select($query, []);
    }
}



