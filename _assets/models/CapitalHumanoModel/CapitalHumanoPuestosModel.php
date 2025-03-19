<?php
class CapitalHumanoPuestosModel extends Model{
    public $id;
    public $nombre_puesto;

    public function get_puestos() : array|false {
        $query = 'SELECT  *  FROM [TGV2].[dbo].[CapitalHumanoPuestos]';
        $params = [];
        return ($this->sql->select($query,$params)) ?: false ;
    }


}