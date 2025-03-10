<?php

class CreSubProductosMarcaModel extends Model{
    public $id;
    public $tipoSubProductoMarcaId;
    public $subProductoId;
    public $nombre;
    public $createdAt;

    function getRowsBySubProduct($subProductId) : array|false {
        $query = "SELECT * FROM [devTotalGas].[dbo].[creTipoSubProductoMarca] WHERE subProductoId = ?;";
        return ($this->sql->select($query, [$subProductId])) ?: false ;
    }
}