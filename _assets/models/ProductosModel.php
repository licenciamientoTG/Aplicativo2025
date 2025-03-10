<?php
class ProductosModel extends Model{
    public $id_productos;
    public $nombre;
    public $fecha_agregado;
    
    /**
     * @return array|false
     * @throws Exception
     */
    public function get_rows() : array|false {
        $query = 'SELECT *  FROM [TGV2].[dbo].[Productos]';
        $params = [];
        return ($this->sql->select($query,$params)) ?: false ;
    }

}