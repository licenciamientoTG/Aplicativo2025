<?php
class IngresosModel extends Model{
    public int $fch; // Llave primaria
    public int $codisl; // Llave primaria
    public int $nrotur; // Llave primaria
    public int $codval; // Llave primaria
    public float $can;
    public float $mto;
    public int $codgas;
    public $logexp;
    public int $logusu;
    public $logfch;
    public $lognew;

    public function get_corte($fch, $codgas) : array | false {
        $query = "SELECT
                        TOP(1) CAST(CONVERT(VARCHAR(100), CAST(t1.fch AS DATETIME) - 1, 23) AS VARCHAR(10)) Fecha
                        , t2.den Gasolinera
                        , LEFT(t1.nrotur, LEN(t1.nrotur) - 1) AS Turno,
                        RIGHT(t1.nrotur, 1) AS Subcorte
                    FROM
                        [SG12].[dbo].[Ingresos] t1
                        LEFT JOIN [SG12].[dbo].[Gasolineras] t2 ON t1.codgas = t2.cod
                    WHERE
                        t1.fch = $fch AND
                        t1.codgas = $codgas AND
                        t1.codval IN (28,127);";
        return $this->sql->select($query, []) ?: false;
    }
}

