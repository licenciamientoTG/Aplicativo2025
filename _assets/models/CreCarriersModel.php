<?php

class CreCarriersModel extends Model{
    public $id;
    public $companyName;
    public $rfc;
    public $crePermissionCarrier;
    public $crePermitId;
    public $createdAt;

    function getRows() : array|false {
        $query = 'SELECT * FROM [devTotalGas].[dbo].[creCarriers];';
        return ($this->sql->select($query)) ?: false ;
    }

    function exists($crePermissionCarrier) : bool {
        $query = "SELECT * FROM [devTotalGas].[dbo].[creCarriers] WHERE crePermissionCarrier = '{$crePermissionCarrier}';";
        return ($this->sql->select($query)) ? true : false ;
    }

    function addRow($companyName, $rfc, $crePermissionCarrier) : bool {
        $query = "INSERT INTO [devTotalGas].[dbo].[creCarriers] ([companyName],[rfc],[crePermissionCarrier],[crePermitId]) VALUES (?,?,?,?);";
        return ($this->sql->insert($query, [$companyName, $rfc, $crePermissionCarrier, 1])) ? true : false ;
    }

    function update($companyName, $rfc, $crePermissionCarrier, $id) : bool {
        $query = "UPDATE [devTotalGas].[dbo].[creCarriers] SET companyName = ?, rfc = ?, crePermissionCarrier = ? WHERE id = ?;";
        return ($this->sql->update($query, [$companyName, $rfc, $crePermissionCarrier, $id])) ? true : false ;
    }
}