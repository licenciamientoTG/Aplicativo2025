<?php
/**
 * Catálogo de departamentos organizacionales (TG.dbo.Departamentos).
 * Separado de PerfilModel/TG.dbo.Perfil, que mezcla roles de acceso con
 * áreas de trabajo — ver docs/sql/departamentos.sql.
 */
class DepartamentosModel extends Model
{
    public $Id;
    public $Nombre;
    public $Activo;

    /** @return array|false */
    function all() : array|false {
        $query = 'SELECT Id, Nombre, Activo FROM [TG].[dbo].[Departamentos] WHERE Activo = 1 ORDER BY Nombre;';
        return $this->sql->select($query) ?: false;
    }

    /**
     * Nombre del departamento de un usuario, resuelto aparte de la sesión:
     * no depende de que sp_usuario_login (fuera de este repo) ya incluya el
     * join a Departamentos.
     */
    function nombre_de_usuario(int $userId): ?string
    {
        $r = $this->sql->select(
            'SELECT d.Nombre FROM [TG].[dbo].[Usuario] u
             JOIN [TG].[dbo].[Departamentos] d ON d.Id = u.IdDepartamento
             WHERE u.Id = ?;',
            [$userId]
        );
        return $r[0]['Nombre'] ?? null;
    }
}
