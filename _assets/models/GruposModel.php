<?php
class GruposModel extends Model{
    public $id_grupo;
    public $nombre;
    public $fecha_agregado;
    
    /**
     * @return array|false
     * @throws Exception
     */
    public function get_rows() : array|false {
        $query = 'SELECT *  FROM [TGV2].[dbo].[Grupo]';
        $params = [];
        return ($this->sql->select($query,$params)) ?: false ;
    }

}