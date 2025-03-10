<?php
class PermissionsModel extends Model{
    public $id; // int
    public $action; // varchar(6)
    public $department; // varchar(15)
    public $description; // varchar(80)
    public $status; // tinyint
    public $updated_at; // datetime
    public $created_at; // datetime

    /**
     * @return array|false
     * @throws Exception
     */
    public function get_permissions() : array|false {
        $query = 'SELECT t1.id permission_id
                    ,CASE t1.action
                        WHEN \'read\' THEN \'Lectura\'
                        WHEN \'update\' THEN \'Actualización\'
                        WHEN \'delete\' THEN \'Eliminación\'
                        WHEN \'create\' THEN \'Creación\'
                        ELSE t1.action
                    END AS Accion
                    ,t1.department Departamento
                    ,t1.description Descripcion
                    ,t1.status Status
                    ,t1.updated_at
                    ,t1.created_at Fecha
                FROM [TG].[dbo].[tg_permissions] t1;';
        return $this->sql->select($query) ?: false;
    }

    function add($action, $department, $description, $status) : bool {
        $query = 'INSERT INTO [TG].[dbo].[tg_permissions] ([action],[department],[description],[status],[updated_at],[created_at]) VALUES (?,?,?,?,GETDATE(),GETDATE());';
        return (bool)$this->sql->insert($query, [$action, $department, $description, $status]);
    }
}