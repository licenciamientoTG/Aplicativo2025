<?php
class UsuariosModel extends Model{
    public $Id;
    public $Usuario;
    public $Nombre;
    public $Password;
    public $Estatus;
    public $IdPerfil;
    public $Correo;
    public $FechaRegistro;
    public function get_users() : array|false {
        $query = 'SELECT
                        t1.Id, t1.Usuario, t1.Nombre, t1.Estatus, t1.IdPerfil, t1.Correo, t1.FechaRegistro, t2.Nombre Perfil, t4.Nombre Estacion, t5.Permissions
                    FROM
                        [TG].[dbo].[Usuario] t1
                        LEFT JOIN [TG].[dbo].[Perfil] t2 ON t1.IdPerfil = t2.Id
                        LEFT JOIN [TG].[dbo].[UsuarioEstacion] t3 ON t1.Id = t3.IdUsuario
                        LEFT JOIN [TG].[dbo].[Estaciones] t4 ON t3.IdEstacion = t4.Codigo
                        LEFT JOIN (SELECT user_id,
                                STUFF((SELECT \',\' + CONVERT(VARCHAR(255), permission_id)
                                       FROM [TG].[dbo].[tg_permissions_users] AS t2
                                       WHERE t2.user_id = t1.user_id
                                       FOR XML PATH(\'\')), 1, 1, \'\') AS Permissions
                            FROM [TG].[dbo].[tg_permissions_users] AS t1
                            GROUP BY user_id) t5 ON t1.Id = t5.user_id
                    ;';
        $params = [];
        return $this->sql->select($query, $params) ?: false;
    }

    /**
     * @param $id
     * @return array|false
     * @throws Exception
     */
    public function get_user($id) : array|false {
        $query = 'SELECT
                        t1.Id, t1.Usuario, t1.Nombre, t1.Estatus, t1.IdPerfil, t1.Correo, t1.FechaRegistro,
                        t2.Nombre Perfil,
                        t4.Nombre Estacion
                    FROM
                        [TG].[dbo].[Usuario] t1
                        LEFT JOIN [TG].[dbo].[Perfil] t2 ON t1.IdPerfil = t2.Id
                        LEFT JOIN [TG].[dbo].[UsuarioEstacion] t3 ON t1.Id = t3.IdUsuario
                        LEFT JOIN [TG].[dbo].[Estaciones] t4 ON t3.IdEstacion = t4.Codigo
                    WHERE t1.Id = ?;
                    ;';
        $params = [$id];
        return $this->sql->select($query, $params)[0] ?: false;
    }

    /**
     * @param $name
     * @param $username
     * @param $password
     * @param $profile_id
     * @param $email
     * @return int
     * @throws Exception
     */
    function add($name, $username, $password, $profile_id, $email) : int {
        if ($this->sql->select('SELECT TOP (1) * FROM [TG].[dbo].[Usuario] WHERE Usuario = ? AND Correo = ?;', [$username, $email])) {
            return 2; // Código para usuario duplicado
        } else {
            $query = 'INSERT INTO [TG].[dbo].[Usuario] (
                            Usuario,
                            Nombre,
                            Password,
                            Estatus,
                            IdPerfil,
                            Correo,
                            FechaRegistro
                        )
                        VALUES (?, ?, ENCRYPTBYPASSPHRASE(?, ?), 1, ?, ?, GETDATE());';
            $params = [$username, $name, $password, $password, $profile_id, $email];
            return ($this->sql->insert($query, $params)) ? 1 : 0 ;
        }
    }

    /**
     * @param $name
     * @param $profile_id
     * @param $email
     * @param $IdEstacion
     * @param $status
     * @param $id
     * @return bool
     * @throws Exception
     */
    function editUser($name, $profile_id, $email, $IdEstacion, $status, $id) : bool {
        $query = 'UPDATE [TG].[dbo].[Usuario] SET
                    [Nombre] = ?,[Estatus] = ?,[IdPerfil] = ?,[Correo] = ?
                WHERE
                    [Id] = ?;';
        if ($this->sql->update($query, [$name, $status, $profile_id, $email, $id])) { // Si logramos actualizar los datos del usuario
            if ($this->sql->select("SELECT * FROM [TG].[dbo].[UsuarioEstacion] WHERE IdUsuario = ?", [$id])) {
                if ($IdEstacion == 0) {
                    // Editamos ese registro
                    $this->sql->delete("DELETE FROM [TG].[dbo].[UsuarioEstacion] WHERE IdUsuario = ?", [$id]);
                } else {
                    // Editamos ese registro
                    $this->sql->update("UPDATE [TG].[dbo].[UsuarioEstacion] SET [IdEstacion] = ? WHERE IdUsuario = ?", [$IdEstacion, $id]);
                }
            } else {
                if ($IdEstacion != 0) {
                    // Creamos un nuevo registro
                    $this->sql->insert("INSERT INTO [TG].[dbo].[UsuarioEstacion] ([IdUsuario],[IdEstacion],[FechaRegistro]) VALUES (?,?,GETDATE());", [$id, $IdEstacion]);
                }
            }
            return true;
        } else {
            return false;
        }
    }

    /**
     * @param $id
     * @param $password
     * @return bool
     * @throws Exception
     */
    function changePassword($id, $password) : bool {
        $query = "UPDATE [TG].[dbo].Usuario SET [Password] = ENCRYPTBYPASSPHRASE(?, ?) WHERE Id = ?;";
        return (bool)$this->sql->update($query, [$password, $password, $id]);
    }

    /**
     * Obtener usuarios que tienen un permiso específico
     * 
     * @param int $permission_id ID del permiso a buscar
     * @return array Lista de usuarios con ese permiso
     */
    public function get_users_by_permission($permission_id, bool $require_email = true) : array {
        try {
            $filtro_correo = $require_email
                ? "AND t1.Correo IS NOT NULL
                    AND t1.Correo != ''"
                : "";
            $query = "
                SELECT DISTINCT
                    t1.Id,
                    t1.Usuario,
                    t1.Nombre,
                    t1.Correo,
                    t1.Estatus,
                    t2.Nombre as Perfil
                FROM [TG].[dbo].[Usuario] t1
                INNER JOIN [TG].[dbo].[tg_permissions_users] t3 ON t1.Id = t3.user_id
                LEFT JOIN [TG].[dbo].[Perfil] t2 ON t1.IdPerfil = t2.Id
                WHERE t3.permission_id = ?
                    AND t1.Estatus = 1
                    {$filtro_correo}
            ";

            $params = [$permission_id];
            $result = $this->sql->select($query, $params);
            
            return $result ?: [];
            
        } catch (Exception $e) {
            error_log("Error en get_users_by_permission: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Obtener correos de usuarios con un permiso específico
     * 
     * @param int $permission_id ID del permiso
     * @return array Lista de correos electrónicos
     */
    public function get_emails_by_permission($permission_id) : array {
        $users = $this->get_users_by_permission($permission_id);
        
        $emails = array_filter(
            array_map(function($user) {
                return trim($user['Correo'] ?? '');
            }, $users),
            function($email) {
                return !empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL);
            }
        );
        
        return array_values(array_unique($emails));
    }
}