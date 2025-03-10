<?php
class ValorButtModel extends Model{
    public $ValorButt_Id;
    public $ValorButt_Num;
    public $ValorButt_Descripcion;
    public $ValorButt_Estado;
    public $ValorButt_CodigoTar;

    /**
     * @param $id
     * @return array|false
     * @throws Exception
     */
    public function getRow($id) : array|false {
        $query = 'SELECT * FROM [TG].[dbo].[ValorButt] WHERE ValorButt_Id = ?;';
        return ($rs = $this->sql->select($query, [$id])) ? $rs[0] : false ;
    }
}