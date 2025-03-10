<?php

class CreProductsModel extends Model{
    public $id;
    public $productoId;
    public $nombre;
    public $createdAt;
    function getRows() : array|false {
        $query = "SELECT * FROM [devTotalGas].[dbo].[creProductos];";
        return ($this->sql->select($query)) ?: false ;
    }
}