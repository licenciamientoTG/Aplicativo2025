<?php
class PlazasModel extends Model{
    public $id_plaza;
    public $nombre;
    public $fecha_agregado;
    
    /**
     * @return array|false
     * @throws Exception
     */
    public function get_rows() : array|false {
        $query = 'SELECT *  FROM [TGV2].[dbo].[Plaza]';
        $params = [];
        return ($this->sql->select($query,$params)) ?: false ;
    }
    public function get_row($Id_plaza) : array|false {
        $query = 'SELECT *  FROM [TGV2].[dbo].[Plaza] Where Id_plaza = ?';
        $params = [$Id_plaza];
        return ($rm = $this->sql->select($query,$params)) ? $rm[0] : false ;
    }

}