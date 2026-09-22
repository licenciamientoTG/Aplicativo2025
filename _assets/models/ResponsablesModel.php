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

    /**
     * Actualiza solo la copia central (SG12) y encola la propagación a las
     * 37 BDs de estación remotas en vez de esperar a que terminen los 37
     * UPDATEs secuenciales por linked server dentro del mismo request. La
     * cola la procesa en segundo plano cron/responsables_sync_queue.php
     * (ver process_sync_queue()).
     */
    function deactivate($cod, $hab) : bool {
        $query = "
            UPDATE [SG12].[dbo].[Responsables] SET [hab] = ? WHERE [cod] = ?;
            INSERT INTO [TG].[dbo].[responsables_sync_queue] ([cod],[hab]) VALUES (?, ?);
        ";
        return (bool)$this->sql->update($query, [$hab, $cod, $cod, $hab]);
    }

    /**
     * Propaga a las 37 BDs de estación remotas los cambios de estatus de
     * responsables pendientes en la cola. Pensado para correr desde el cron
     * (Task Scheduler) vía Operations::sync_responsables_queue(), no desde
     * una petición de usuario: recorrer 37 linked servers de forma
     * secuencial puede tardar decenas de segundos.
     *
     * Si un mismo responsable quedó con varias filas pendientes (se
     * activó/desactivó varias veces antes de que corriera el cron), solo se
     * propaga la más reciente; las anteriores se marcan 'superada' sin
     * tocar las estaciones. Las filas en 'error' se reintentan en la
     * siguiente corrida hasta 10 intentos.
     *
     * updateSafe() (a diferencia de update()) no aborta el proceso si una
     * estación falla, así que una estación caída no bloquea la propagación
     * al resto.
     *
     * @return array{procesados:int, ok:int, error:int, detalle:array}
     */
    function process_sync_queue() : array {
        $pendientes = $this->sql->select(
            "SELECT id, cod, hab FROM [TG].[dbo].[responsables_sync_queue]
             WHERE estado = 'pendiente' OR (estado = 'error' AND intentos < 10)
             ORDER BY id ASC"
        ) ?: [];

        if (!$pendientes) {
            return ['procesados' => 0, 'ok' => 0, 'error' => 0, 'detalle' => []];
        }

        // Deduplicar: solo nos interesa el último estado pedido por responsable.
        $ultimaPorCod = [];
        foreach ($pendientes as $fila) {
            $ultimaPorCod[$fila['cod']] = $fila;
        }
        foreach ($pendientes as $fila) {
            if ($fila['id'] != $ultimaPorCod[$fila['cod']]['id']) {
                $this->sql->update(
                    "UPDATE [TG].[dbo].[responsables_sync_queue] SET estado = 'superada', fecha_procesado = GETDATE() WHERE id = ?",
                    [$fila['id']]
                );
            }
        }

        $ok = 0;
        $error = 0;
        $detalle = [];
        foreach ($ultimaPorCod as $fila) {
            $fallos = [];
            foreach ($this->databases as $codgas => $db) {
                if ($codgas == 0) {
                    continue; // 0 = SG12, ya actualizado de forma sincrona en deactivate()
                }
                $resultado = $this->sql->updateSafe(
                    "UPDATE {$db}.[Responsables] SET [hab] = ? WHERE [cod] = ?",
                    [$fila['hab'], $fila['cod']]
                );
                if ($resultado === false) {
                    $fallos[] = $codgas;
                }
            }

            if (empty($fallos)) {
                $ok++;
                $this->sql->update(
                    "UPDATE [TG].[dbo].[responsables_sync_queue] SET estado = 'ok', fecha_procesado = GETDATE() WHERE id = ?",
                    [$fila['id']]
                );
            } else {
                $error++;
                $detalleFila = "cod {$fila['cod']}: estaciones " . implode(', ', $fallos);
                $detalle[] = $detalleFila;
                $this->sql->update(
                    "UPDATE [TG].[dbo].[responsables_sync_queue] SET estado = 'error', intentos = intentos + 1, fecha_procesado = GETDATE(), detalle_error = ? WHERE id = ?",
                    [$detalleFila, $fila['id']]
                );
            }
        }

        return ['procesados' => count($ultimaPorCod), 'ok' => $ok, 'error' => $error, 'detalle' => $detalle];
    }

    function delete($cod) : bool {
        return (bool)$this->sql->executeStoredProcedure('[TG].[dbo].[sp_eliminar_responsable]', [$cod]);
    }

}