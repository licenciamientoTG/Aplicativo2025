<?php
class XsdReportesVolumenesModel extends Model{
    public $id;
    public $fechaReporteEstadistico;
    public $version;
    public $createdAt;

    function getOrAddRow($from) : array | false{
        // Consulta para verificar si ya existe el registro
        $querySelect = "
            DECLARE @fecha DATE = '$from';
            SELECT TOP (1) [id]
                  ,[fechaReporteEstadistico]
                  ,[version]
                  ,[createdAt]
            FROM [devTotalGas].[dbo].[xsdReportesVolumenes]
            WHERE fechaReporteEstadistico = @fecha;";

        // Intentar obtener el registro
        $result = $this->sql->select($querySelect);

        // Si el registro existe, devolverlo
        if ($result) {
            return $result[0];
        }

        // Si el registro no existe, insertarlo
        $queryInsert = "INSERT INTO [devTotalGas].[dbo].[xsdReportesVolumenes]([fechaReporteEstadistico],[version]) VALUES (?, ?);";
        $inserted = $this->sql->insert($queryInsert, [$from, 5]);

        // Si se inserta exitosamente, intentar obtener el registro nuevamente
        if ($inserted) {
            return ($rs = $this->sql->select($querySelect)) ? $rs[0] : false ;
        }

        // Si la inserción falla, devolver false
        return false;
    }

    function get_cabecera($from) {
        $query = "DECLARE @from DATE = '$from';
                  SELECT TOP(1) id, FORMAT(fechaReporteEstadistico, 'dd/MM/yyyy') AS fechaReporteEstadistico, version FROM [devTotalGas].[dbo].[xsdReportesVolumenes] WHERE fechaReporteEstadistico = @from;
                  ";
        return ($rs=$this->sql->select($query)) ? $rs[0] : false ;
    }

}