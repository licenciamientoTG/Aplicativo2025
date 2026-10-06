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
            $params = [$user_id, $permission_id];
        } else {
            // IF NOT EXISTS: doble clic o dos pestañas no deben duplicar la fila.
            $query = "IF NOT EXISTS (SELECT 1 FROM [TG].[dbo].[tg_permissions_users] WHERE [user_id] = ? AND [permission_id] = ?)
                        INSERT INTO [TG].[dbo].[tg_permissions_users] ([user_id], [permission_id], [updated_at], [created_at]) VALUES (?, ?, GETDATE(), GETDATE());";
            $params = [$user_id, $permission_id, $user_id, $permission_id];
        }
        return ($this->sql->query($query, $params)) ? 1 : 0 ;
    }

    /**
     * Todos los usuarios con la bandera Permitido (1/0) para un permiso dado.
     * Usado por el modal "Asignar a usuarios" de /it/permissions.
     * @param int $permission_id
     * @return array
     * @throws Exception
     */
    public function get_users_by_permission_assignment(int $permission_id) : array {
        $query = 'SELECT t1.Id, t1.Usuario, t1.Nombre, t1.Estatus, t2.Nombre Perfil, t4.Nombre Estacion
                        ,CASE WHEN t5.user_id IS NOT NULL THEN 1 ELSE 0 END AS Permitido
                    FROM [TG].[dbo].[Usuario] t1
                    LEFT JOIN [TG].[dbo].[Perfil] t2 ON t1.IdPerfil = t2.Id
                    OUTER APPLY (SELECT TOP (1) ue.IdEstacion FROM [TG].[dbo].[UsuarioEstacion] ue WHERE ue.IdUsuario = t1.Id ORDER BY ue.FechaRegistro DESC, ue.IdEstacion DESC) t3
                    LEFT JOIN [TG].[dbo].[Estaciones] t4 ON t3.IdEstacion = t4.Codigo
                    OUTER APPLY (SELECT TOP (1) pu.user_id FROM [TG].[dbo].[tg_permissions_users] pu WHERE pu.user_id = t1.Id AND pu.permission_id = ?) t5
                    ORDER BY Permitido DESC, t1.Nombre';
        return $this->sql->select($query, [$permission_id]) ?: [];
    }

    /**
     * @param int $permission_id
     * @return array|false
     * @throws Exception
     */
    public function get_permission(int $permission_id) : array|false {
        $rows = $this->sql->select('SELECT id, action, department, description, status FROM [TG].[dbo].[tg_permissions] WHERE id = ?', [$permission_id]);
        return $rows ? $rows[0] : false;
    }
}