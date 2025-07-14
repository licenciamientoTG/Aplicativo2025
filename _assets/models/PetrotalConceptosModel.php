<?php
class PetrotalConceptosModel extends Model {
    public $id;
    public $rubro;
    public $cuenta;
    public $valor;
    public $fecha;
    public $fecha_creacion;

    // Obtener registros por rango de fechas
    function getByFecha($from, $until) {
        $query = "
            SELECT *
            FROM [TGV2].[dbo].[ERPetrotal]
            WHERE [fecha] BETWEEN ? AND ?
        ";
        $params = [$from, $until];
        return ($this->sql->select($query, $params)) ?: false;
    }

    // Obtener los últimos N registros
    function getLastN($limit = 1000) {
        $query = "
            SELECT TOP ($limit) *
            FROM [TGV2].[dbo].[ERPetrotal]
            ORDER BY [id] DESC
        ";
        return ($this->sql->select($query)) ?: false;
    }

    // Insertar múltiples registros
    function insertPetrotal(array $data) {
        try {
            $this->sql->beginTransaction();
            $query = "
                INSERT INTO [TGV2].[dbo].[ERPetrotal] (
                    rubro, cuenta, valor, fecha
                ) VALUES (
                    ?, ?, ?, ?
                )
            ";
            foreach ($data as $row) {
                $params = [
                    $row['rubro'],
                    $row['cuenta'],
                    $row['valor'],
                    $row['fecha']
                ];
                $this->sql->insert($query, $params);
            }
            $this->sql->commit();
            return ['success' => true];
        } catch (\Exception $e) {
            $this->sql->rollBack();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    function get_row($date){
        $query = "
            SELECT  *
            FROM [TGV2].[dbo].[ERBalancePetrotal]
            WHERE [fecha] = ?
            ORDER BY [id] DESC
        ";
        $params = [$date];
        $rs = $this->sql->select($query, $params);

        return ($rs[0] ?? false);
    }
    function save_spend_petrotal($fecha,$gasto) {
        $query = "
            INSERT INTO [TGV2].[dbo].[ERBalancePetrotal] (fecha, gasto)
            VALUES (?, ?)";
        $params = [$fecha, $gasto];
        return $this->sql->insert($query, $params);
    }
    function update_spend_petrotal($fecha, $gasto, $id){
        $query = "
            UPDATE [TGV2].[dbo].[ERBalancePetrotal]
            SET gasto = ?
            WHERE id = ? 
        ";
        $params = [$gasto, $id];
        return $this->sql->update($query, $params);
    }
}
