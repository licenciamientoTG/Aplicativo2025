<?php
class DesabastoEstacionesModel extends Model{
    public $id_estaciones;
    public $codigo;
    public $nombre;
    public $razon_social;
    public $zona;



    public function get_station($id) : array|false {
        $query = 'SELECT * FROM [TGV2].[dbo].[DesabastoEstaciones] WHERE codigo = ? ORDER BY codigo;';
        return ($this->sql->select($query, [$id])[0]) ?: false ;
    }

    /**
     * @return array|false
     * @throws Exception
     */
    public function get_stations() : array|false {
        $query = 'SELECT * FROM [TGV2].[dbo].[DesabastoEstaciones] ORDER BY codigo;';
        return ($this->sql->select($query)) ?: false ;
    }


}