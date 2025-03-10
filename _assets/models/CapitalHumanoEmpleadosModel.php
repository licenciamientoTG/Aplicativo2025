<?php
class CapitalHumanoEmpleadosModel extends Model{
    public $id;
    public $Numero;
    public $Nombre;
    public $Departamento;
    public $FechaIngreso;
    public $Activo;
    public $FechaBaja;
    public $Recontratar;
    public $MotivoBaja;
    public $Equipo;
    public $Antiguedad;
    public $BajaCorrecta;
    public $puesto_id;
    public $empresa_id;


    public function get_employess() : array|false {
        $query = 'SELECT  *  FROM [TGV2].[dbo].[CapitalHumanoEmpleados]';
        $params = [];
        return ($this->sql->select($query,$params)) ?: false ;
    }

    public function insert_new_employees_with_transaction(array $empleadosNuevos) : bool {
        try {
            $this->sql->beginTransaction(); // Inicia la transacción
            foreach ($empleadosNuevos as $empleado) {
                $query = 'INSERT INTO [TGV2].[dbo].[CapitalHumanoEmpleados]
                          ([Numero], [Nombre], [Departamento], [FechaIngreso], [Activo], [FechaBaja], 
                           [Recontratar], [MotivoBaja], [Equipo], [Antiguedad], [BajaCorrecta], 
                           [puesto_id], [empresa_id])
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
                $params = [
                    $empleado['Numero'],
                    $empleado['nombre'],
                    $empleado['departamento'],
                    $empleado['fechaIngreso'],
                    $empleado['activo'],
                    $empleado['fechaBaja'],
                    $empleado['recontratar'],
                    $empleado['motivoBaja'],
                    $empleado['equipo'],
                    $empleado['antiguedad'] ?? null, // Asegurar que existe o sea null
                    $empleado['bajaCorrecta'] ?? null, // Asegurar que existe o sea null
                    $empleado['puesto_id'] ?? null, // Asegurar que existe o sea null
                    $empleado['empresa_id'] ?? null // Asegurar que existe o sea null
                ];
                // Ejecutar la consulta dentro de la transacción
                if (!$this->sql->insert($query, $params)) {
                    // Si algo falla, realizar un rollback y devolver false
                    $this->sql->rollBack();
                    return false;
                }
            }
            $this->sql->commit(); // Confirmar la transacción
            return true;
        } catch (Exception $e) {
            // En caso de error, realizar un rollback
            $this->sql->rollBack();
            echo "Error: " . $e->getMessage();
            return false;
        }
    }
}