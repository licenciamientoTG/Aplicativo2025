<?php

class CreSuppliersModel extends Model{
    public $id;
    public $companyName;
    public $rfc;
    public $crePermissionSupplier;
    public $crePermitId;
    public $createdAt;

    function getRows() : array|false {
        $query = 'SELECT * FROM [devTotalGas].[dbo].[creSuppliers];';
        return ($this->sql->select($query)) ?: false ;
    }

    function exists($crePermissionSupplier) : bool {
        $query = "SELECT * FROM [devTotalGas].[dbo].[creSuppliers] WHERE crePermissionSupplier = '{$crePermissionSupplier}';";
        return ($this->sql->select($query)) ? true : false ;
    }

    function addRow($companyName, $rfc, $crePermissionSupplier) : bool {
        $query = "INSERT INTO [devTotalGas].[dbo].[creSuppliers] ([companyName],[rfc],[crePermissionSupplier]) VALUES (?,?,?);";
        return ($this->sql->insert($query, [$companyName, $rfc, $crePermissionSupplier])) ? true : false ;
    }

    function update($companyName, $rfc, $crePermissionSupplier, $id) : bool {
        $query = "UPDATE [devTotalGas].[dbo].[creSuppliers] SET companyName = ?, rfc = ?, crePermissionSupplier = ? WHERE id = ?;";
        return ($this->sql->update($query, [$companyName, $rfc, $crePermissionSupplier, $id])) ? true : false ;
    }
}