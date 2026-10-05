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

    /**
     * Inventario en tiempo real de UNA estación (vw_tank_info, una fila por
     * tanque) usando Servidor/BaseDatos de TG.dbo.Estaciones -- mismo origen
     * que sp_obtener_inventarios_tanques_tiempo_real, pero sin recorrer las
     * 37 estaciones en serie. Usado por el tab "Plan inventarios" de
     * /supply/scheduling, que pide varias estaciones en paralelo.
     *
     * @return array|null  null si la estación no tiene servidor configurado
     */
    function get_tank_info_estacion(int $codigo): ?array {
        $est = $this->sql->select(
            "SELECT Servidor, BaseDatos FROM [TG].[dbo].[Estaciones] WHERE Codigo = ?",
            [$codigo]
        );
        $servidor = trim($est[0]['Servidor'] ?? '');
        $baseDatos = trim($est[0]['BaseDatos'] ?? '');
        // Se interpolan en OPENQUERY (no admite parámetros): solo caracteres
        // de nombre de servidor/base válidos.
        $patron = '/^[A-Za-z0-9_.+\\\\-]+$/';
        if ($servidor === '' || $baseDatos === '' || !preg_match($patron, $servidor) || !preg_match($patron, $baseDatos)) {
            return null;
        }
        $query = "SELECT * FROM OPENQUERY([$servidor], 'SELECT * FROM [$baseDatos].[dbo].[vw_tank_info]')";
        // selectSafe: una estación caída no debe matar la petición con die();
        // se reporta como error de esa estación y el resto sigue.
        $rows = $this->sql->selectSafe($query);
        if ($rows === false) {
            throw new Exception('Sin conexión con el servidor de la estación');
        }
        return $rows;
    }

    function sp_obtener_inventarios_por_movimientos_tanque($from, $station_id) : array {
        ini_set('memory_limit', '256M');
        ini_set('max_execution_time', 300);
        return $this->sql->executeStoredProcedure('[TG].[dbo].[sp_obtener_inventarios_por_movimientos_tanque]', array('database' => $this->databases[$station_id], 'codgas' => $station_id, 'fchtrn' => dateToInt($from)));
    }
}