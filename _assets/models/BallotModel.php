<?php
class BallotModel extends Model{
    public $Id;
    public $IdTabulador;
    public $Turno;
    public $CodEstacion;
    public $Moneda;
    public $TipoCambio;
    public $NoRemision;
    public $SeguriSello;
    public $CantidadFajillas;
    public $Monto;
    public $Usuario;
    public $FechaCreacion;
    public $FechaActualizacion;


    function save() {

        // Debes validar que no exista otra papeleta creada con el mismo IdTabulador, y Moneda
        $query = "SELECT * FROM [TG].[dbo].[ballot] WHERE [IdTabulador] = ? AND [Moneda] = ?;";
        $params = array($this->IdTabulador, $this->Moneda);
        $result = $this->sql->select($query, $params);
        if (count($result) > 0) {
            return false;
        }

        $query = "INSERT INTO [TG].[dbo].[ballot] 
            ([IdTabulador], [Turno], [CodEstacion], [Moneda], [TipoCambio], [NoRemision],[SeguriSello], [CantidadFajillas], [Monto], [Usuario], [FechaCreacion], [FechaActualizacion])
        VALUES 
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE(), NULL);
        ";
        $params = array($this->IdTabulador, $this->Turno, $this->CodEstacion, $this->Moneda, $this->TipoCambio, $this->NoRemision, $this->SeguriSello, $this->CantidadFajillas, 1, $this->Usuario);
        return $this->sql->insert($query, $params);

    }

    function getvalues($tipo, $codest) {
        $query = "
        DECLARE @estacion INT;
        SET @estacion = ?;
        SELECT
            v.cod AS CodigoValor,
            CASE
                WHEN v.cod = 6 THEN v.den ELSE 'Efectivo USD/Dolares a MN' END
            AS DescMoneda,
            ISNULL(SUM(i.can), 0) AS Cantidad,
            ISNULL(SUM(i.mto), 0) AS Monto,
            CONVERT(DATETIME,i.fch - 1) AS FechaValores,
            ISNULL((SELECT TOP 1 ctz FROM [192.168.7.101].[SG12_41882020].[dbo].Cotizaciones WHERE codgas = @estacion ORDER BY logfch DESC), 0) AS TipoCambio
        FROM
            [192.168.7.101].[SG12_41882020].[dbo].Valores v
            INNER JOIN [192.168.7.101].[SG12_41882020].[dbo].Ingresos i ON v.cod = i.codval
        WHERE
            i.codgas = @estacion AND
            i.nrotur BETWEEN 40 AND 48 AND
            i.fch =45704 AND
            v.tip IN ( 0, 1 ) AND v.cod IN ( 6, 5, -1128)
        GROUP BY v.cod, v.den, i.fch ORDER BY 4 DESC";
        return $this->sql->select($query, [$codest]);
    }

    function get_ballots($IdTabulador) {
        $query = "SELECT * FROM [TG].[dbo].[ballot] WHERE IdTabulador = ?;";
        $params = [$IdTabulador];
        return $this->sql->select($query, $params);
    }

    function get_ballot_by_id($id) {
        $query = "SELECT * FROM [TG].[dbo].[ballot] WHERE Id = ?;";
        $params = [$id];
        return $this->sql->select($query, $params);
    }

    function get_station_data($codest) {
        $query = "SELECT
            t1.*, t2.*,
            t1.Nombre AS BancoNombre,
	        t2.Nombre AS EstacionNombre
        FROM
            [TG].[dbo].[BancoEstacion] t1
            LEFT JOIN [TG].[dbo].[Estaciones] t2 ON t1.CodigoEstacion = t2.Codigo
        WHERE
            t1.CodigoEstacion = ?;";
        $params = [$codest];
        return $this->sql->select($query, $params);
    }

    function get_ballot_values($fecha, $turno, $CodEstacion) {
        $params = [$fecha, $turno, $CodEstacion];
        return $this->sql->executeStoredProcedure('[TG].[dbo].[sp_consulta_papeleta_valores]', $params);
    }
}