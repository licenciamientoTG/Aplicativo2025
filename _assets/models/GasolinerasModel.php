<?php
class GasolinerasModel extends Model{
    public $cod;
    public $abr;
    public $den;
    public $dom;
    public $col;
    public $del;
    public $ciu;
    public $est;
    public $tel;
    public $fax;
    public $hraini1;
    public $hrafin1;
    public $hraini2;
    public $hrafin2;
    public $hraini3;
    public $hrafin3;
    public $hraini4;
    public $hrafin4;
    public $codalm;
    public $codext;
    public $dia1;
    public $dia2;
    public $dia3;
    public $dia4;
    public $codemp;
    public $cvecli;
    public $cveest;
    public $codred;
    public $fchact;
    public $turact;
    public $codpza;
    public $codcpo;
    public $dentdc;
    public $sersuc;
    public $facbdd;
    public $facusu;
    public $facpwd;
    public $facdir;
    public $codest;
    public $logusu;
    public $logfch;
    public $lognew;
    public $polcor;
    public $ultcor;
    public $geodat;
    public $geolat;
    public $geolng;
    public $codref;
    public $nropcc;
    public $prp;
    public $tipest;
    public $tiptdc;
    public $supter;
    public $supcon;
    public $fchini;
    public $cntemp;
    public $faccmp;
    public $datvar;
    public $correo;
    public $cto;
    public $codpos;
    public $paisat;
    public $nropic;
    public $codflg;
    public $prdcon;
    public $estgem;
    public $satdat;
    public $fchsyn;
    public $denamp;

    /**
     * @return array|false
     * @throws Exception
     */
    public function get_stations() : array|false {
        $query = 'SELECT cod, abr, den, dom, col FROM [SG12].[dbo].[Gasolineras] ORDER BY abr;';
        return ($this->sql->select($query)) ?: false ;
    }

    public function get_station_by_code($cod) : array|false {
        $query = 'SELECT TOP (1) cod, abr, den, dom, col FROM [SG12].[dbo].[Gasolineras] WHERE cod = ?;';
        return ($this->sql->select($query, [$cod])) ?: false ;
    }
    public function get_company(){
        $query = 'SELECT t1.codemp, t2.den
                    FROM [SG12].[dbo].[Gasolineras] t1
                    left join SG12.dbo.Empresas t2 on t1.codemp = t2.cod
                    group by t1.codemp,t2.den';
        $params = [];
        return ($this->sql->select($query)) ?: false ;
    }

    /**
     * @return array|false
     * @throws Exception
     */
    public function get_active_stations() : array|false {
        $query = 'SELECT cod, abr, den, dom, col FROM [SG12].[dbo].[Gasolineras] WHERE datvar = 0 ORDER BY abr;';
        return ($this->sql->select($query)) ?: false ;
    }

    function GetVentasLogistica($from, $until, $codgas, $product) {
        set_time_limit(0);
        ini_set('max_execution_time', 0);
        return ($this->sql->executeStoredProcedure('[TG].[dbo].[sp_obtener_inventarios_por_turno]', [$from, $until, $codgas, $product])) ?: false ;
    }

    public function get_active_station_TG() : array|false {
        $query = 'SELECT Codigo, Nombre, Estacion, iva, Servidor, BaseDatos, PermisoCRE FROM [TG].[dbo].[Estaciones] WHERE activa = 1 AND Codigo > 0;';
        $rs = $this->sql->select($query);
        $actives = [];
        if ($rs) {
            foreach ($rs as $key => $value) {
                // Vamos a revisar si el puertp 1433 esta abierto
                if (@fsockopen($value['Servidor'], 1433, $errno, $errstr, 1)) {
                    $actives[] = $value;
                }
            }
        }
        return ($actives) ?: false ;
    }

    function get_fuel_prices() : array|false {
        return ($this->sql->executeStoredProcedure('[TG].[dbo].[sp_obtener_precios_combustibles]')) ?: false ;
    }

    function capture_prices($codprd, $fch, $hour, $pre, $iva, $codgas) : void {
        $this->sql->executeStoredProcedure('[TG].[dbo].[sp_capture_prices]', [$codprd, $fch, $hour, $pre, $iva, $codgas, $this->databases[$codgas]]);
    }
    
    function get_estations_servidor() : array|false{
        $query = 'SELECT
                    t1.cod as codigo,
                    t1.abr as estacion_nombre,
                    t1.den as estacion_empresa,
                    t2.den as empresa,
                    t1.facusu as usuario,
                    t1.facpwd as contra,
                    t3.BaseDatos as base_datos,
                    t3.email as email,
                    t3.Servidor as servidor,
                    t3.vis_fac
                    from [SG12].dbo.Gasolineras t1
                    LEFT JOIN [sg12].dbo.Empresas t2 on t1.codemp = t2.cod
                    LEFT JOIN [TG].dbo.Estaciones t3 on t1.cod = t3.Codigo
                    Where
                    BaseDatos is not null
                    and BaseDatos != \'\' ';
        return $this->sql->select($query);
    }
    function get_estations_servidor_cod_gas($cod) : array|false{
        $query = 'SELECT
                    t1.cod as codigo,
                    t1.abr as estacion_nombre,
                    t1.den as estacion_empresa,
                    t2.den as empresa,
                    t1.facusu as usuario,
                    t1.facpwd as contra,
                    t3.BaseDatos as base_datos,
                    t3.email as email,
                    t3.Servidor as servidor,
                    t3.vis_fac
                    from [SG12].dbo.Gasolineras t1
                    LEFT JOIN [sg12].dbo.Empresas t2 on t1.codemp = t2.cod
                    LEFT JOIN [TG].dbo.Estaciones t3 on t1.cod = t3.Codigo
                    Where
                    BaseDatos is not null
                    and BaseDatos != \'\' 
                    and t1.cod = ?
                    ';
        $params = [$cod];
        return ($rm = $this->sql->select($query,$params)) ? $rm[0] : false ;
    }

    function get_fuel_prices_by_station($Servidor, $BaseDatos, $Codigo, $Estacion, $Nombre) {
        return ($this->sql->executeStoredProcedure('[TG].[dbo].[sp_obtener_precios_combustibles_estacion]', [$Servidor, $BaseDatos, $Codigo, $Estacion, $Nombre])) ?: false ;
    }
}