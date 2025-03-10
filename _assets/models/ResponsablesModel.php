<?php

class ResponsablesModel extends Model{
    public $cod;
    public $den;
    public $dom;
    public $col;
    public $del;
    public $ciu;
    public $est;
    public $tel;
    public $codgas;
    public $hab;
    public $pto;
    public $rsp;
    public $mtacan;
    public $mtamto;
    public $rfc;
    public $codext;
    public $fching;
    public $fchegr;
    public $sumval;
    public $tag;
    public $curp;
    public $nip;
    public $idc;
    public $idccod;
    public $idcpin;
    public $logusu;
    public $logfch;
    public $lognew;
    public $fchsyn;

    /**
     * @param $station_id
     * @return array|false
     * @throws Exception
     */
    public function get_responsables_by_station($station_id) {
        $query = 'SELECT cod Codigo, den Nombre, pto Puesto FROM [SG12].[dbo].[Responsables] WHERE codgas = ? AND hab = 1;';
        $params = [$station_id];
        return ($this->sql->select($query,$params)) ?: false ;
    }



    function get_responsable($responsable_id) {
        $query = "SELECT cod Codigo, den Nombre, pto Puesto, codext, hab Status, codgas FROM SG12.dbo.Responsables WHERE cod = {$responsable_id};";
        return ($rs=$this->sql->select($query)) ? $rs[0] : false ;
    }

    function get_all() : array | false {
        $query = "SELECT t2.abr Estacion, t2.cveest, t1.*, CASE 
            WHEN t1.hab = 0 THEN '-Inactivo-' 
            WHEN t1.hab = 1 THEN '-Activo-' 
        END AS Status FROM [SG12].[dbo].[Responsables] t1 LEFT JOIN  [SG12].[dbo].Gasolineras t2 ON t1.codgas = t2.cod;";
        return ($rs=$this->sql->select($query)) ? $rs : false ;
    }

    function update(array $data, int $codgas) : bool {
        return (bool)$this->sql->executeStoredProcedure('[TG].[dbo].[sp_actualizar_responsable]', [$_POST['codoriginal'], $data['Codigo'], $data['Nombre'], $data['Estacion'], $data['Status'], $data['NoReloj']]);
    }

    function insert(array $data) : bool {
        $query = "INSERT INTO [dbo].[Responsables] ([cod],[den],[codgas],[hab],[pto],[rsp],[codext],[logfch],[lognew],[fchsyn]) VALUES (?,?,?,?,?,?,?,GETDATE(),GETDATE(),GETDATE())";
        $params = [$data['Codigo'], $data['Nombre'], $data['Estacion'], $data['Status'], $data['Puesto'], 1, $data['NoReloj']];
        return (bool)$this->sql->insert($query,$params);
    }

    function deactivate($cod, $hab) : bool {
        $query = "
            DECLARE @cod INT = {$cod};
            DECLARE @hab INT = {$hab};
            -- Corporativo
            UPDATE [SG12].[dbo].[Responsables] SET [hab] = @hab WHERE [cod] = @cod;
            -- Gemela Grande
            UPDATE [192.168.7.101].[SG12_41882020].[dbo].[Responsables] SET [hab] = @hab WHERE [cod] = @cod;
            -- Aguascalientes
            UPDATE [192.168.28.101].[SG12_11007+2020].[dbo].[Responsables] SET [hab] = @hab WHERE [cod] = @cod;
            -- Lerdo
            UPDATE [192.168.2.101].[SG12_114912020].[dbo].[Responsables] SET [hab] = @hab WHERE [cod] = @cod;
            -- Lopez Mateos
            UPDATE [192.168.5.101].[SG12_25262020].[dbo].[Responsables] SET [hab] = @hab WHERE [cod] = @cod;
            -- Gemela Chica
            UPDATE [192.168.6.101].[SG12_4179_20].[dbo].[Responsables] SET [hab] = @hab WHERE [cod] = @cod;
            -- Municipio Libre
            UPDATE [192.168.9.101].[SG12_53172020].[dbo].[Responsables] SET [hab] = @hab WHERE [cod] = @cod;
            -- Aztecas
            UPDATE [192.168.10.101].[SG12_5465].[dbo].[Responsables] SET [hab] = @hab WHERE [cod] = @cod;
            -- Misiones
            UPDATE [192.168.11.101].[SG12_6410].[dbo].[Responsables] SET [hab] = @hab WHERE [cod] = @cod;
            -- Puerto de palos
            UPDATE [192.168.19.101].[SG12_6947_2020].[dbo].[Responsables] SET [hab] = @hab WHERE [cod] = @cod;
            -- Miguel de la madrid
            UPDATE [192.168.13.101].[CG_7167].[dbo].[Responsables] SET [hab] = @hab WHERE [cod] = @cod;
            -- Permuta
            UPDATE [192.168.14.101].[SG12_8244].[dbo].[Responsables] SET [hab] = @hab WHERE [cod] = @cod;
            -- Electrolux
            UPDATE [192.168.15.101].[SG12_9191].[dbo].[Responsables] SET [hab] = @hab WHERE [cod] = @cod;
            -- Aeronáutica
            UPDATE [192.168.16.101].[SG12_92352020].[dbo].[Responsables] SET [hab] = @hab WHERE [cod] = @cod;
            -- Custodia
            UPDATE [192.168.17.101].[SG12_98852020].[dbo].[Responsables] SET [hab] = @hab WHERE [cod] = @cod;
            -- Anapra
            UPDATE [192.168.18.101].[SG12_9893].[dbo].[Responsables] SET [hab] = @hab WHERE [cod] = @cod;
            -- Parral
            UPDATE [192.168.4.101].[sg2172].[dbo].[Responsables] SET [hab] = @hab WHERE [cod] = @cod;
            -- Delicias
            UPDATE [192.168.3.101].[CG_1376].[dbo].[Responsables] SET [hab] = @hab WHERE [cod] = @cod;
            -- Plutarco
            UPDATE [192.168.8.101].[Custodia5170].[dbo].[Responsables] SET [hab] = @hab WHERE [cod] = @cod;
            -- Tecnológico
            UPDATE [192.168.30.101].[CG_1163].[dbo].[Responsables] SET [hab] = @hab WHERE [cod] = @cod;
            -- Ejercito
            UPDATE [192.168.21.101].[CG_9733].[dbo].[Responsables] SET [hab] = @hab WHERE [cod] = @cod;
            -- Satelite
            UPDATE [192.168.22.101].[CG_4457].[dbo].[Responsables] SET [hab] = @hab WHERE [cod] = @cod;
            -- Fuentes
            UPDATE [192.168.23.101].[cg_1159].[dbo].[Responsables] SET [hab] = @hab WHERE [cod] = @cod;
            -- Clara
            UPDATE [192.168.24.101].[CG_1156].[dbo].[Responsables] SET [hab] = @hab WHERE [cod] = @cod;
            -- Solis
            UPDATE [192.168.25.101].[CG_10141].[dbo].[Responsables] SET [hab] = @hab WHERE [cod] = @cod;
            -- Santiago Troncoso
            UPDATE [192.168.26.101].[SG12_12097].[dbo].[Responsables] SET [hab] = @hab WHERE [cod] = @cod;
            -- Jarudo
            UPDATE [192.168.27.101].[CG_1148].[dbo].[Responsables] SET [hab] = @hab WHERE [cod] = @cod;
            -- Hermanos
            UPDATE [192.168.29.101].[CG_23214].[dbo].[Responsables] SET [hab] = @hab WHERE [cod] = @cod;
            -- Villa Ahumada
            UPDATE [192.168.32.101].[CG_1242].[dbo].[Responsables] SET [hab] = @hab WHERE [cod] = @cod;
            -- El castaño
            UPDATE [192.168.33.101].[CG_19190].[dbo].[Responsables] SET [hab] = @hab WHERE [cod] = @cod;
            -- Travel Center
            UPDATE [192.168.31.101].[CG_24938].[dbo].[Responsables] SET [hab] = @hab WHERE [cod] = @cod;
            -- Picachos
            UPDATE [192.168.34.101].[CG_24499].[dbo].[Responsables] SET [hab] = @hab WHERE [cod] = @cod;
            -- Ventanas
            UPDATE [192.168.35.101].[CG_24500].[dbo].[Responsables] SET [hab] = @hab WHERE [cod] = @cod;
            -- San Rafael
            UPDATE [192.168.36.101].[CG_14946].[dbo].[Responsables] SET [hab] = @hab WHERE [cod] = @cod;
            -- Puertecito
            UPDATE [192.168.37.101].[CG_15071].[dbo].[Responsables] SET [hab] = @hab WHERE [cod] = @cod;
            -- Jesus Maria
            UPDATE [192.168.38.101].[CG_15901].[dbo].[Responsables] SET [hab] = @hab WHERE [cod] = @cod;
        ";
        return (bool)$this->sql->update($query,[$cod]);
    }

    function delete($cod) : bool {
        return (bool)$this->sql->executeStoredProcedure('[TG].[dbo].[sp_eliminar_responsable]', [$cod]);
    }

}