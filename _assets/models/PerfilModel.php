<?php
class PerfilModel extends Model{
    public $Id;
    public $Nombre;
    public $SuperUser;
    public $AdminUser;
    public $FechaRegistro;
    public $PermiteConsultarUnaEstacion;
    public $PermiteConsultarMultiplesEstaciones;
    public $PermiteConsultarTodasEstaciones;

    /**
     * @return array|false
     * @throws Exception
     */
    function all() : array|false {
        $query = "SELECT
                    Id
                    ,Nombre
                    ,SuperUser
                    ,AdminUser
                    ,FechaRegistro
                FROM [TG].[dbo].[Perfil];";
        return $this->sql->select($query) ?: false;
    }
}