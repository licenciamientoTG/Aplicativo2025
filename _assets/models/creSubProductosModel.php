<?php

class CreSubProductosModel extends Model{
    public $id;
    public $subProductoId;
    public $productoId;
    public $nombre;
    public $unidadMedida;
    public $unidadMedidaId;
    public $createdAt;

    function getRowsByProduct($productId) : array|false {
        $query = 'SELECT * FROM [devTotalGas].[dbo].[creSubProductos] WHERE productoId = ?;';
        return ($this->sql->select($query, [$productId])) ?: false ;
    }
}