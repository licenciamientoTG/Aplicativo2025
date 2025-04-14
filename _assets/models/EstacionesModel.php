<?php
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class EstacionesModel extends Model{
    public $Codigo;
    public $RFC;
    public $Nombre;
    public $LugarExpedicion;
    public $Domicilio;
    public $RegimenFiscal;
    public $Estacion;
    public $CuentaTimbrado;
    public $UsuarioTimbrado;
    public $PasswordTimbrado;
    public $UrlTimbrado;
    public $Servidor;
    public $BaseDatos;
    public $PermisoCRE;
    public $Serie;
    public $RutaVolumetricos;
    public $Denominacion;
    public $Zona;
    public $ZonaConso;
    public $LimiteRecolecta;
    public $place_uid;
    public $activa;

    /**
     * @param $id
     * @return array|false
     * @throws Exception
     */
    public function get_station($id) : array|false {
        $query = 'SELECT * FROM [TG].[dbo].[Estaciones] WHERE Codigo = ? ORDER BY Codigo;';
        return ($this->sql->select($query, [$id])[0]) ?: false ;
    }


    /**
     * @return array|false
     * @throws Exception
     */
    public function get_stations() : array|false {
        $query = 'SELECT * FROM [TG].[dbo].[Estaciones] ORDER BY Codigo;';
        return ($this->sql->select($query)) ?: false ;
    }
    public function get_all_stations() : array|false {
        $query = 'SELECT [Codigo],[Nombre] FROM [TG].[dbo].[Estaciones] ORDER BY Codigo;';
        return ($this->sql->select($query)) ?: false ;
    }

    /**
     * @param $data
     * @return bool
     * @throws Exception
     */
    function add($data) : bool {
        // Obtenemos el máximo ID de la tabla llamado Codigo
        $query = 'SELECT MAX(Codigo) AS Codigo FROM [TG].[dbo].[Estaciones];';
        $rs = $this->sql->select($query);
        $data['Codigo'] = $rs[0]['Codigo'] + 1;

        // Insertamos el registro
        $query = 'INSERT INTO [TG].[dbo].[Estaciones]
                (Codigo
                ,Nombre
                ,Domicilio
                ,Estacion
                ,Servidor
                ,BaseDatos
                ,PermisoCRE
                ,Denominacion
                ,Zona
                ,activa)
        VALUES
                (?,?,?,?,?,?,?,?,?,1);';

        $params = [
            trim($data['Codigo']),
            trim($data['Nombre']),
            trim($data['Domicilio']),
            trim($data['Estacion']),
            trim($data['Servidor']),
            trim($data['BaseDatos']),
            trim($data['PermisoCRE']),
            trim($data['Denominacion']),
            trim($data['Zona'])
        ];
        return $this->sql->insert($query, $params);
    }

    function sp_monitor_tabulador($from) : array {
        return $this->sql->executeStoredProcedure('[TG].[dbo].[sp_monitor_tabulador]', [strval($from)]);
    }

    function get_iva($codgas) : int|false {
        $query = "SELECT iva FROM [TG].[dbo].[Estaciones] WHERE Codigo = ?";
        return ($this->sql->select($query, [$codgas])[0]['iva']) ?: false ;
    }

    function get_station_email($codgas) {
        $query = "SELECT TOP (1) [email] FROM [TG].[dbo].[Estaciones] WHERE Codigo = ?";
        return ($this->sql->select($query, [$codgas])[0]['email']) ?: false ;
    }

    function getCompanies() : array {
        return $this->sql->select("SELECT rfc,	CASE
                                                WHEN RFC = 'SVE200529DB9' THEN 'SMA VENTANAS S.A. DE C.V.'
                                                WHEN RFC = 'SSY940520271' THEN 'SERVICIO SYC S.A. DE C.V.'
                                                WHEN RFC = 'SPI200529SC7' THEN 'SMA PICACHOS S.A. DE C.V.'
                                                WHEN RFC = 'ECU0602287R6' THEN 'ESTACION CUSTODIA S.A. DE C.V.'
                                                WHEN RFC = 'DGA930823KD3' THEN 'DIAZ GAS'
                                                WHEN RFC = 'DGM880621FU5' THEN 'DISTRIBUIDORA GASO MEX'
                                                WHEN RFC = 'DCL880518UG2' THEN 'DISTRIBUIDORA CLARA'
                                                WHEN RFC = 'SJA880518PRA' THEN 'SERVICIO EL JARUDO'
                                                WHEN RFC = 'GVA9709154V2' THEN 'GASOLINERA VILLA AHUMADA'
                                                WHEN RFC = 'SGC1304129H9' THEN 'SERVICIOS GASOLINEROS EL CASTAÑO'
                                                WHEN RFC = 'GOG181220973' THEN 'GRUPO OPERADOR GASOLINERO TSA DEL CENTRO'
                                                ELSE RFC
                                            END AS denominacion
                                        FROM
                                            [TG].[dbo].[Estaciones]
                                        WHERE
                                            RFC != ''
                                        GROUP BY
                                            rfc,
                                            CASE
                                                WHEN RFC = 'SVE200529DB9' THEN 'SMA VENTANAS S.A. DE C.V.'
                                                WHEN RFC = 'SSY940520271' THEN 'SERVICIO SYC S.A. DE C.V.'
                                                WHEN RFC = 'SPI200529SC7' THEN 'SMA PICACHOS S.A. DE C.V.'
                                                WHEN RFC = 'ECU0602287R6' THEN 'ESTACION CUSTODIA S.A. DE C.V.'
                                                WHEN RFC = 'DGA930823KD3' THEN 'DIAZ GAS'
                                                WHEN RFC = 'DGM880621FU5' THEN 'DISTRIBUIDORA GASO MEX'
                                                WHEN RFC = 'DCL880518UG2' THEN 'DISTRIBUIDORA CLARA'
                                                WHEN RFC = 'SJA880518PRA' THEN 'SERVICIO EL JARUDO'
                                                WHEN RFC = 'GVA9709154V2' THEN 'GASOLINERA VILLA AHUMADA'
                                                WHEN RFC = 'SGC1304129H9' THEN 'SERVICIOS GASOLINEROS EL CASTAÑO'
                                                WHEN RFC = 'GOG181220973' THEN 'GRUPO OPERADOR GASOLINERO TSA DEL CENTRO'
                                                ELSE RFC
                                            END
                                           ORDER BY denominacion");
    }

    function getStationsByCompany($companies) {
        $query = "SELECT Codigo FROM [TG].[dbo].[Estaciones] WHERE RFC IN ('{$companies}') AND activa = 1 AND Codigo > 0 AND Codigo NOT IN (17, 38);";
        if ($rs = $this->sql->select($query)) {
            // Extraer los valores de "Codigo"
            $codigoArray = array_column($rs, "Codigo");

            // Convertir a string separado por comas
            $codigoString = implode(",", $codigoArray);

            return $codigoString;
        }
    }

    function get_actives_stations() {
        $query = "SELECT Codigo, RFC AS [Company], Nombre, Servidor AS [Ip], PermisoCRE FROM [TG].[dbo].[Estaciones] WHERE activa = 1 AND Codigo NOT IN (0);";
        return $this->sql->select($query);
    }

    function get_volumetrics($permisoCre, $from, $until) : array|false {
        $query = "SELECT
                COUNT(CASE WHEN name LIKE 'PL_%' THEN 1 END) AS Total_PL,
                COUNT(CASE WHEN name LIKE 'D_%' THEN 1 END) AS Total_D,
                COUNT(CASE WHEN name LIKE 'M_%' THEN 1 END) AS Total_M
            FROM [TG].[dbo].[VolumeticosXML]
            WHERE permisoCre = '{$permisoCre}'
            AND FileDate BETWEEN '{$from}' AND '{$until}';";

        return  (($rs = $this->sql->select($query))) ? $rs[0] : false ;
    }

    function delete_volumetrics($permisoCre, $from, $until) {
        $query = "DELETE FROM [TG].[dbo].[VolumeticosXML]
            WHERE permisoCre = ?
            AND FileDate BETWEEN '{$from}' AND '{$until}';";
        return $this->sql->delete($query, [$permisoCre]);
    }

    function download_volumetrics($permisoCre, $from, $until) : array|false {
        $query = "SELECT name, contentxml FROM [TG].[dbo].[VolumeticosXML]
            WHERE permisoCre = '{$permisoCre}'
            AND FileDate BETWEEN '{$from}' AND '{$until}';";
        return  (($rs = $this->sql->select($query))) ? $rs : false ;
    }

function sp_obtener_entregas_volumetricas_por_rango($permisoCRE, $fechaInicio, $fechaFin, $fileType)     {

    set_time_limit(0);
    ini_set('max_execution_time', 0);
    $spreadsheet = new Spreadsheet();
    $spreadsheet->removeSheetByIndex(0); // Elimina hoja por defecto

    try {
        $inicio = new DateTime($fechaInicio);
        $fin = new DateTime($fechaFin);
    } catch (Exception $e) {
        throw new Exception("Formato de fecha inválido");
    }

    if ($inicio > $fin) {
        throw new Exception("La fecha de inicio no puede ser mayor a la fecha de fin.");
    }

    while ($inicio <= $fin) {
        $fecha = $inicio->format('Y-m-d');

        try {
            $resultadoDia = $this->sp_obtener_entregas_volumetricas($permisoCRE, $fecha, $fileType);

            if (!empty($resultadoDia)) {
                $titulo = str_replace('-', '', $fecha);

                // Crear y activar hoja
                $hoja = new Worksheet($spreadsheet, $titulo);
                $spreadsheet->addSheet($hoja);
                $spreadsheet->setActiveSheetIndex($spreadsheet->getIndex($hoja));
                $hoja = $spreadsheet->getActiveSheet();

                // Encabezados
                $col = 1;
                $row = 1;
                foreach (array_keys($resultadoDia[0]) as $campo) {
                    $hoja->setCellValue([$col++, $row], $campo);
                }

                // Datos
                $row = 2;
                foreach ($resultadoDia as $fila) {
                    $col = 1;
                    foreach ($fila as $valor) {
                        $hoja->setCellValue([$col++, $row], $valor);
                    }
                    $row++;
                }
            }

        } catch (Exception $e) {
            error_log("Error en $fecha: " . $e->getMessage());
        }

        $inicio->modify('+1 day');
    }

    // Descargar el archivo Excel
    $writer = new Xlsx($spreadsheet);
    $fileName = "entregas_volumetricas_" . date('Ymd_His') . ".xlsx";

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header("Content-Disposition: attachment; filename=\"$fileName\"");
    $writer->save("php://output");
    exit;
}



    function sp_obtener_entregas_volumetricas($permisoCRE, $fecha, $fileType)
    {
        $params = [$permisoCRE, $fecha, $fileType];
        return $this->sql->executeStoredProcedure('[TG].[dbo].[sp_obtener_entregas_volumetricas]', $params);
    }


}