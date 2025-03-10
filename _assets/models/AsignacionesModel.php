<?php
class AsignacionesModel extends Model{
    public $Id;
    public $IdTabulador;
    public $Gasolinera;
    public $Fecha;
    public $Turno;
    public $Isla;
    public $Responsable;
    public $Nombre;
    public $FechaAsignacion;

    /**
     * @param $Gasolinera
     * @param $Fecha
     * @param $Turno
     * @param $isle_id
     * @param $responsable_id
     * @return bool
     * @throws Exception
     */
    public function assignation($IdTabulador, $Gasolinera, $Fecha, $Turno, $isle_id, $responsable_id) : bool {
        // Primero Verificamos que no exista una asignacion para la misma isla, turno
        if ($this->sql->select('SELECT * FROM [TG].[dbo].[Asignaciones] WHERE IdTabulador = ? AND Isla = ?;',[$IdTabulador, $isle_id])) {
            return false;
        }

        // Si no existe una asignacion para la misma isla y turno, entonces buscamos al responsable por su Id
        $responsable = $this->sql->select('SELECT * FROM [SG12].[dbo].[Responsables] WHERE cod = ?;',[$responsable_id])[0]['den'];

        // Ahora procedemos a crear la asignación en la base de datos
        $query = 'INSERT INTO [TG].[dbo].[Asignaciones] (IdTabulador, Gasolinera, Fecha, Turno, Isla, Responsable, Nombre, FechaAsignacion, IdUsuario) VALUES (?, ?, ?, ?, ?, ?, ?, GETDATE(), ?);';
        $params = [$IdTabulador, $Gasolinera, $Fecha, $Turno, $isle_id, $responsable_id, $responsable, $_SESSION['tg_user']['Id']];
        return (bool)$this->sql->insert($query, $params);
    }

    /**
     * @param $Gasolinera
     * @param $Fecha
     * @param $Turno
     * @return array|false
     * @throws Exception
     */
    function get_assignations_by_tabulator($Gasolinera, $Fecha, $Turno) : array|false {
        $query = 'SELECT
                    t1.*,
                    t2.abr Estacion,
                    t3.den IslaNombre
                FROM
                    [TG].[dbo].[Asignaciones] t1
                    LEFT JOIN [SG12].[dbo].[Gasolineras] t2 ON t1.Gasolinera = t2.cod
                    LEFT JOIN [SG12].[dbo].[Islas] t3 ON t1.Isla = t3.cod
                WHERE
                    t1.Gasolinera = ? AND t1.Fecha = ? AND t1.Turno = ?;';
        $params = [$Gasolinera, $Fecha, $Turno];
        return ($this->sql->select($query,$params)) ?: false ;
    }

    /**
     * @param $Gasolinera
     * @param $Fecha
     * @param $Turno
     * @param $Isla
     * @return bool
     * @throws Exception
     */
    function delete_assignment($Gasolinera, $Fecha, $Turno, $Isla) : bool {
        $query = 'DELETE FROM [TG].[dbo].[Asignaciones] WHERE Gasolinera = ? AND Fecha = ? AND Turno = ? AND Isla = ?;';
        $params = [$Gasolinera,$Fecha,$Turno,$Isla];
        return (bool)$this->sql->delete($query, $params);
    }

    /**
     * @param $gasolinera
     * @param $fecha
     * @param $turno
     * @param $isla
     * @return array|false
     * @throws Exception
     */
    function get_assignation_by_island($gasolinera, $fecha, $turno, $isla) : array|false {
        $rs = $this->sql->select("SELECT TOP (1) Responsable FROM [TG].[dbo].[Asignaciones] WHERE Gasolinera = ? AND Fecha = ? AND Isla = ? AND Turno = ?;", [$gasolinera, $fecha, $isla, $turno]);
        return $rs ?: false;
    }
}