<?php

class HumanCapital{
    public $twig;
    public $route;
    public $CapitalHumanoEmpleadosModel;
    public $CapitalHumanoPuestosModel;



    /**
     * @param $twig
     */
    public function __construct($twig) {
        $this->twig                  = $twig;
        $this->route                 = 'views/humancapital/';
        $this->CapitalHumanoEmpleadosModel = new CapitalHumanoEmpleadosModel;
        $this->CapitalHumanoPuestosModel =   new CapitalHumanoPuestosModel;
    }

    function employees() {
        echo $this->twig->render($this->route . 'employees.html');
    }

    public function datatables_employees(){
        $data=[];

        if ($employees= $this->CapitalHumanoEmpleadosModel->get_employess()) {

            foreach ($employees as $key => $employee) {
                $data[]=array(
                    'id'            => $employee['id'],
                    'Numero'        => $employee['Numero'],
                    'Nombre'        => $employee['Nombre'],
                    'Departamento'  => $employee['Departamento'],
                    'FechaIngreso'  => $employee['FechaIngreso'],
                    'Activo'        => $employee['Activo'],
                    'FechaBaja'     => $employee['FechaBaja'],
                    'Recontratar'   => $employee['Recontratar'],
                    'MotivoBaja'    => $employee['MotivoBaja'],
                    'Equipo'        => $employee['Equipo'],
                    'Antiguedad'    => $employee['Antiguedad'],
                    'BajaCorrecta'  => $employee['BajaCorrecta'],
                    'puesto_id'     => $employee['puesto_id'],
                    'empresa_id'    => $employee['empresa_id']
                );


            }

            $data=array("data" => $data);
            echo json_encode($data);
        }

    }

    public function import_file_reporte_empleados(){
        $nombreEmpresa = "";
        $empleados = [];
        $file = $_FILES['file_to_upload']['tmp_name'];
        if (($handle = fopen($file, 'r')) !== false) {
            $lineNumber = 0;

            while (($line = fgets($handle)) !== false) {
                $values = str_getcsv($line); // Utilizar str_getcsv para manejar comillas y comas

                // Eliminar las comas de los números
                foreach ($values as $key => $value) {
                    $values[$key] = str_replace(',', '', $value);
                }
                if ($lineNumber == 0) {
                    $nombreEmpresa = str_replace('"', '', $values[0]);
                } elseif ($lineNumber > 2) { // Cambiado a > 2 para ignorar las primeras tres líneas
                    $empleado = self::procesarEmpleado($nombreEmpresa, $values);
                    if ($empleado !== null) {
                        $empleados[] = $empleado;
                    } else {
                        echo 'Error en la línea ' . $lineNumber . ': ' . $line;
                    }
                }
                $lineNumber++;
            }
            fclose($handle);
        }

        $employees= $this->CapitalHumanoEmpleadosModel->get_employess();
        $puestos= $this->CapitalHumanoPuestosModel->get_puestos();

        $numerosEmpleadosExistentes = array_column($employees, 'Numero');

        // Filtrar los empleados que no están en la lista de empleados existentes
        $empleadosNuevos = array_filter($empleados, function($empleado) use ($numerosEmpleadosExistentes) {
            return !in_array($empleado['Numero'], $numerosEmpleadosExistentes);
        });
        echo '<pre>';
        var_dump($puestos);
        var_dump($numerosEmpleadosExistentes);
        var_dump($empleadosNuevos);
        var_dump($nombreEmpresa);
        die();
    }
    public function procesarEmpleado($nombreEmpresa, $values) {
        if ($nombreEmpresa == "Concentradora de vales") {
            return $this->procesarConcentradoraVales($values);
        } elseif ($nombreEmpresa == "Diaz Gas S.a. De C.v.") {
            return $this->procesarDiazGas($values);
        }
        return null; // Si la empresa no está soportada
    }

    private function procesarConcentradoraVales($values) {
        if (is_numeric($values[0])) {
            return [
                'Numero' => (int)$values[0],
                'nombre' => str_replace('"', '', $values[1]),
                'departamento' => $values[2],
                'puesto' => $values[3],
                'fechaIngreso' => self::parseFecha($values[4]),
                'activo' => $values[5] == "SI",
                'fechaBaja' => self::parseFecha($values[6]),
                'recontratar' => $values[7] == "SI",
                'motivoBaja' => $values[8],
                'equipo' => $values[10] ?? '', // Manejar si no existe el índice
                'antiguedad' => null,
            ];
        }
        return null; // Manejar el error en otro lugar
    }

    // Método para procesar empleados de Díaz Gas
    private function procesarDiazGas($values) {
        if (count($values) > 12) {
            $numeroString = str_replace('"', '', $values[0]) . str_replace('"', '', $values[1]);
            if (is_numeric($numeroString)) {
                return [
                    'Numero' => (int)$numeroString,
                    'nombre' => str_replace('"', '', $values[2]),
                    'departamento' => $values[3],
                    'puesto' => $values[4],
                    'fechaIngreso' => self::parseFecha($values[5]),
                    'activo' => $values[6] == "SI",
                    'recontratar' => $values[8] == "SI",
                    'motivoBaja' => $values[9],
                    'equipo' => $values[10],
                ];
            }
        } elseif (is_numeric($values[0])) {
            return [
                'Numero' => (int)$values[0],
                'nombre' => str_replace('"', '', $values[1]),
                'departamento' => $values[3],
                'puesto' => $values[4],
                'fechaIngreso' => self::parseFecha($values[5]),
                'activo' => $values[6] == "SI",
                'fechaBaja' => self::parseFecha($values[7]),
                'recontratar' => $values[8] == "SI",
                'motivoBaja' => $values[9],
                'equipo' => $values[11] ?? '',
            ];
        }
        return null;
    }

    public function parseFecha($fecha) {
        // Asumiendo que la fecha está en formato 'd/m/Y' y se quiere devolver en 'Y-m-d'
        $dateTime = DateTime::createFromFormat('d/m/Y', $fecha);
        return $dateTime ? $dateTime->format('Y-m-d') : null; // Retornar null si no se puede parsear
    }
    





}