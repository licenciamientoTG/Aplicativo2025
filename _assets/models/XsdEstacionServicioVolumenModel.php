<?php
class XsdEstacionServicioVolumenModel extends Model{
    public $id;
    public $xsdReportesVolumenesId;
    public $numeroPermisoCRE;
    public $rfc;
    public $imagenComercialId;
    public $estatusESId;
    public $createdAt;

    function getOrAddRow($reportId, $numeroPermisoCRE, $rfc) : array | false{
        // Consulta para verificar si ya existe el registro
        $querySelect = "SELECT * FROM [devTotalGas].[dbo].[xsdEstacionServicioVolumen] WHERE xsdReportesVolumenesId = {$reportId} AND numeroPermisoCRE = '{$numeroPermisoCRE}';";

        // Si el registro existe, devolverlo
        if ($result = $this->sql->select($querySelect)) {
            return $result[0];
        }

        // Si el registro no existe, insertarlo
        $queryInsert = "INSERT INTO [devTotalGas].[dbo].[xsdEstacionServicioVolumen] (xsdReportesVolumenesId,numeroPermisoCRE,rfc,imagenComercialId,estatusESId) VALUES (?,?,?,?,?);";
        $inserted = $this->sql->insert($queryInsert, [$reportId, $numeroPermisoCRE, $rfc, 26, 2]);

        // Si se inserta exitosamente, intentar obtener el registro nuevamente
        if ($inserted) {
            return ($rs = $this->sql->select($querySelect)) ? $rs[0] : false ;
        }

        // Si la inserción falla, devolver false
        return false;
    }

    function exists($from, $rfc, $creProductId, $creSubProductId, $numeroPermisoCRE) : bool {
        $query = "
        DECLARE @from DATE = '{$from}';
        SELECT
            t1.*,
            t2.fechaReporteEstadistico
        FROM
            [devTotalGas].[dbo].[xsdEstacionServicioVolumen] t1
            LEFT JOIN [devTotalGas].[dbo].[xsdReportesVolumenes] t2 ON t1.xsdReportesVolumenesId = t2.id
            LEFT JOIN [devTotalGas].[dbo].[xsdEstacionServicioVolumenVendidoInventarios] t3 ON t1.id = t3.xsdEstacionServicioVolumenId
        WHERE
            t1.rfc = '{$rfc}' AND t1.numeroPermisoCRE = '{$numeroPermisoCRE}' AND
            t2.fechaReporteEstadistico = @from AND
            t3.productoId = {$creProductId} AND
            t3.subProductoId = {$creSubProductId}
            ;
        ";
        return (bool)$this->sql->select($query);
    }

    function get_station($reportId, $codgas) : array | false {
        $query = "SELECT t2.* FROM [TG].[dbo].[Estaciones] t1 LEFT JOIN (SELECT * FROM [devTotalGas].[dbo].[xsdEstacionServicioVolumen] WHERE xsdReportesVolumenesId = {$reportId}) t2 ON t1.PermisoCRE = t2.numeroPermisoCRE WHERE t1.Codigo = {$codgas}";
        return ($rs=$this->sql->select($query)) ? $rs[0] : false ;
    }
}