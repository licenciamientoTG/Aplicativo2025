<?php
class EmpresasModel extends Model{
    public $cod;
    public $den;
   
    public $activa;

    /**
     * @param $id
     * @return array|false
     * @throws Exception
     */
    public function get_empresas($id) : array|false {
        $query = 'SELECT * FROM [sg12].[dbo].[[Empresas]] ';
        return ($this->sql->select($query, [$id])[0]) ?: false ;
    }


}