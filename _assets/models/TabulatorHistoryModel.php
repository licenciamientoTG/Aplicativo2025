<?php
class TabulatorHistoryModel extends Model{
    public $Id;
    public $Tabla;
    public $Operacion;
    public $IdRegistro;
    public $IdTabulador;
    public $Fecha;
    public $UsuarioDB;
    public $UsuarioAplicativo;
    public $DatosAnteriores;
    public $DatosNuevos;


    function get_by_tabulator($IdTabulador) : array | false {
        $query = "
            SELECT
                t1.*,
                t2.Nombre,
                CASE 
                    WHEN t1.Operacion = 'Insert' THEN 'Nuevo registro'
                    WHEN t1.Operacion = 'Update' THEN 'Actualización de información'
                    WHEN t1.Operacion = 'Delete' THEN 'Eliminación'
                END AS TipoOperacion,
                CONVERT(VARCHAR(19), t1.Fecha, 120) AS Fecha_Formateada
            FROM
                [TG].[dbo].[TabuladorHistorial] t1
                LEFT JOIN [TG].[dbo].[Usuario] t2 ON t1.UsuarioAplicativo = t2.Id
            WHERE
                t1.IdTabulador = ?
        ";
        return ($rs=$this->sql->select($query,[$IdTabulador])) ? $rs : false ;
    }
}