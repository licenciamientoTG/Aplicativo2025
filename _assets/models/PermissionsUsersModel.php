<?php

use PhpOffice\PhpSpreadsheet\Style\NumberFormat\Wizard\Number;

class PermissionsUsersModel extends Model{
    public $id;
    public $user_id;
    public $permission_id;
    public $updated_at;
    public $created_at;

    /**
     * @param $user_id
     * @return array|false
     * @throws Exception
     */
    public function get_permissions_users($user_id) : array|false {
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
                    ,CASE WHEN t2.user_id IS NOT NULL THEN \'1\' ELSE \'0\' END AS Permitido
                FROM [TG].[dbo].[tg_permissions] t1
                LEFT JOIN [TG].[dbo].[tg_permissions_users] t2 ON t1.id = t2.permission_id AND t2.user_id = ?
            ';
        return $this->sql->select($query, [$user_id]) ?: false;
    }

    /**
     * @param $user_id
     * @param $permission_id
     * @param $check
     * @return Int
     * @throws Exception
     */
    function assignPermission($user_id, $permission_id, $check) : Int {
        if ($check == 0) {
            $query = "DELETE FROM [TG].[dbo].[tg_permissions_users] WHERE [user_id] = ? AND [permission_id] = ?;";
        } else {
            $query = "INSERT INTO [TG].[dbo].[tg_permissions_users] ([user_id], [permission_id], [updated_at], [created_at]) VALUES (?, ?, GETDATE(), GETDATE());";
        }
        return ($this->sql->query($query, [$user_id, $permission_id])) ? 1 : 0 ;
    }
}