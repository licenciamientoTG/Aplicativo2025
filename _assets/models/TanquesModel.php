<?php
class TanquesModel extends Model{
    public $cod;
    public $den;
    public $codprd;
    public $codgas;
    public $capmax;
    public $capocu;
    public $graprd;
    public $nrotf1;
    public $nrotf2;
    public $nrotf3;
    public $nrotf4;
    public $capope;
    public $caputi;
    public $capfon;
    public $volmin;
    public $est;
    public $logfchCV1;
    public $loghraCV1;
    public $nroarcdef;
    public $nroarcest;
    public $codred;
    public $logusu;
    public $logfch;
    public $lognew;
    public $satdat;
    public $fchsyn;
    public $denamp;
    public $linked_server;
    public $short_databases;

    /**
     * @param $station_id
     * @return array|false
     * @throws Exception
     */
    function get_inventory() : array|false {
        ini_set('memory_limit', '256M');
        ini_set('max_execution_time', 300);
        return $this->sql->executeStoredProcedure('[TG].[dbo].[sp_obtener_inventarios_tanques_tiempo_real]');
    }

    function get_inventory_by_codgas($station_id) : array | false {
        ini_set('memory_limit', '256M');
        ini_set('max_execution_time', 300);
        $query = "SELECT * FROM OPENQUERY({$this->linked_server[$station_id]}, 'SELECT * FROM {$this->short_databases[$station_id]}.[vw_tank_info]')";
        return $this->sql->select($query);
    }

    function sp_obtener_inventarios_por_movimientos_tanque($from, $station_id) : array {
        ini_set('memory_limit', '256M');
        ini_set('max_execution_time', 300);
        return $this->sql->executeStoredProcedure('[TG].[dbo].[sp_obtener_inventarios_por_movimientos_tanque]', array('database' => $this->databases[$station_id], 'codgas' => $station_id, 'fchtrn' => dateToInt($from)));
    }
}