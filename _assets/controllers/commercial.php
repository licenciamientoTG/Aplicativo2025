<?php

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\NamedRange;
use PhpOffice\PhpSpreadsheet\Settings;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\Legend;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Title;
use PhpOffice\PhpSpreadsheet\Helper\Sample;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
class Commercial{
    public $twig;
    public $route;
    public VentasModel $ventas;
    public DespachosModel $despachosModel;
    public GasolinerasModel $gasolineras;
    public AuditoriaMysteryModel $auditoriaMysteryModel;
    public BudgetModel $budget;

    public function __construct($twig) {
        $this->twig                  = $twig;
        $this->route                 = 'views/commercial/';
        $this->ventas                = new VentasModel;
        $this->despachosModel        = new DespachosModel;
        $this->gasolineras           = new GasolinerasModel;
        $this->auditoriaMysteryModel = new AuditoriaMysteryModel;
        $this->budget = new BudgetModel;
    }

    public function sale_lubricants($from = null, $until = null) {
        $from = $_POST['from'] ?? null;
        $until = $_POST['until'] ?? null;
        echo $this->twig->render($this->route . 'sale_lubricants.html', compact('from', 'until'));
    }
    
    public function sale_lubricants_month($from = null, $until = null) {
        $from = $_POST['from'] ?? null;
        $until = $_POST['until'] ?? null;
        echo $this->twig->render($this->route . 'sale_lubricants.html', compact('from', 'until'));
    }

    public function  mystery_shopper(){
        echo $this->twig->render($this->route . 'mystery_shopper.html');
    }
    public function sale_month_turn(){
        echo $this->twig->render($this->route . 'sale_month_turn.html');
    }
    public function sale_week_zone(){
        echo $this->twig->render($this->route . 'sale_week_zone.html');
    }
    public function sales_indicators(){
        echo $this->twig->render($this->route . 'sales_indicators.html');
    }
    public function sale_type_payment(){
        $estations = $this->gasolineras->get_estations_servidor();
        $companys = $this->gasolineras->get_company();
        echo $this->twig->render($this->route . 'sale_type_payment.html', compact('companys','estations'));
    }
    function sale_month_turn_table(){
        $dinamicColumns = $_POST['dinamicColumns'];
        $rows = $this->ventas->getSalesMonthTotal($_POST['fromDate'], $_POST['untilDate'], $_POST['zona'],$_POST['turn'],$_POST['total']);
        
        $data=[];
        foreach ($rows as $key => $row) {
            $entry=[];
                foreach ($dinamicColumns as $key => $column) {
                    $colun_name = $column['data'];

                    $entry[$colun_name] = $row[$colun_name];

                }
            $data[] = $entry;
        }
        echo json_encode(array("data" => $data));
    }
    public function sale_month_turn_base_table() {
        if ($rows = $this->ventas->GetSalesMonthBase($_POST['fromDate'], $_POST['untilDate'], $_POST['zona'])) {
            foreach ($rows as $row) {
                $data[] = array(
                    'Año'            => $row['Año'],
                    'Mes'            => $row['Mes'],
                    'Turno'          => $row['Turno'],
                    'Producto'       => $row['Producto'],
                    'CodGasolinera'  => $row['CodGasolinera'],
                    'Estacion'       => $row['Estacion'],
                    'CodProducto'    => $row['CodProducto'],
                    'VentasReales'   => number_format($row['VentasReales'], 2),
                    'MontoVendido'   => number_format($row['MontoVendido'], 2),
                    'CodEmp'         => $row['CodEmp'],
                    'den'            => $row['den'],
                    'estructura'     => $row['estructura']
                );
            }
            $data = array("data" => $data);
            echo json_encode($data);
        } else {
            echo json_encode(["data" => []]); // Devuelve un array vacío si no hay datos
        }
    }

    
    function sales_indicators_table(){

        $dinamicColumns = $_POST['dinamicColumns'];
        $rows = $this->ventas->GetSalesIndicator($_POST['fromDate'], $_POST['untilDate'], $_POST['zona'],$_POST['total']);
        $data=[];

        foreach ($rows as $key => $row) {
            $entry=[];
                foreach ($dinamicColumns as $key => $column) {
                    $colun_name = $column['data'];

                    $entry[$colun_name] = $row[$colun_name];

                }
            $data[] = $entry;
        }
        echo json_encode(array("data" => $data));
    }
    function sales_type_payment_table(){
        $dinamicColumns = $_POST['dinamicColumns'];
        $rows = $this->ventas->getSalesTypePayment($_POST['fromDate'], $_POST['untilDate'], $_POST['zona'],$_POST['total']);
        $data=[];

        foreach ($rows as $key => $row) {
            $entry=[];
                foreach ($dinamicColumns as $key => $column) {
                    $colun_name = $column['data'];

                    $entry[$colun_name] = $row[$colun_name];

                }
            $data[] = $entry;
        }
        echo json_encode(array("data" => $data));
    }
    function mounth_group_table(){
        $dinamicColumns = $_POST['dinamicColumns'];
        $rows = $this->ventas->getMounthGruopPayment($_POST['fromDate'], $_POST['untilDate'],$_POST['grupo'], 0);
        $data=[];

        foreach ($rows as $key => $row) {
            $entry=[];
                foreach ($dinamicColumns as $key => $column) {
                    $colun_name = $column['data'];

                    $entry[$colun_name] = $row[$colun_name];

                }
            $data[] = $entry;
        }
        echo json_encode(array("data" => $data));
    }
    function mounth_company_table(){
        $dinamicColumns = $_POST['dinamicColumns'];
        $rows = $this->ventas->getMounthCompanyPayment($_POST['fromDate'], $_POST['untilDate'],$_POST['company'], 0);
        $data=[];
        foreach ($rows as $key => $row) {
            $entry=[];
                foreach ($dinamicColumns as $key => $column) {
                    $colun_name = $column['data'];
                    $entry[$colun_name] = $row[$colun_name];

                }
            $data[] = $entry;
        }
        echo json_encode(array("data" => $data));
    }
    function mounth_company_table2(){
        $dinamicColumnsJson = $_POST['dinamicColumns'];
        $dinamicColumns = json_decode($dinamicColumnsJson, true);

        $rows = $this->ventas->getMounthCompanyPayment($_POST['fromDate'], $_POST['untilDate'],$_POST['company'], 0);
       
        $data=[];
      
        foreach ($rows as $key => $row) {
            $entry=[];
                foreach ($dinamicColumns as $key => $column) {
                    $colun_name = $column['data'];
                    $entry[$colun_name] = $row[$colun_name];

                }
            $data[] = $entry;
        }
    
        echo json_encode(array("data" => $data));
    }
    function mounth_estation_table(){
        if($_POST['json'] == 1){
            $dinamicColumns = json_decode($_POST['dinamicColumns'], true);
        }else{
            $dinamicColumns = $_POST['dinamicColumns'];
        }
        $rows = $this->ventas->getMounthEstationPayment($_POST['fromDate'], $_POST['untilDate'],$_POST['estation'], 0);
        $data=[];
        foreach ($rows as $key => $row) {
            $entry=[];
                foreach ($dinamicColumns as $key => $column) {
                    $colun_name = $column['data'];
                    $entry[$colun_name] = $row[$colun_name];

                }
            $data[] = $entry;
        }
   
        echo json_encode(array("data" => $data));
    }

    function sales_type_payment_totals_table(){
        $dinamicColumns = $_POST['dinamicColumns'];
        $rows = $this->ventas->getSalesTypePaymentTotal($_POST['fromDate'], $_POST['untilDate'],$_POST['zona']);
        $data=[];

        foreach ($rows as $key => $row) {
            $entry=[];
                foreach ($dinamicColumns as $key => $column) {
                    $colun_name = $column['data'];

                    $entry[$colun_name] = $row[$colun_name];

                }
            $data[] = $entry;
        }
        echo json_encode(array("data" => $data));
    }

    function sale_week_zone_table(){
        $dinamicColumns = $_POST['dinamicColumns'];
        $rows = $this->ventas->getSaleWeekZone($_POST['fromDate'], $_POST['untilDate']);
        $data=[];
        foreach ($rows as $key => $row) {
            $entry=[];
                foreach ($dinamicColumns as $key => $column) {
                    $colun_name = $column['data'];
                    $entry[$colun_name] = $row[$colun_name];
                }
            $data[] = $entry;
        }
        echo json_encode(array("data" => $data));
    }
    function lubricants_table(){
        $dinamicColumns = $_POST['dinamicColumns'];
        $rows = $this->ventas->getLubricants($_POST['fromDate'], $_POST['untilDate']);
        $data=[];
        foreach ($rows as $key => $row) {
            $entry=[];
                foreach ($dinamicColumns as $key => $column) {
                    $colun_name = $column['data'];
                    $entry[$colun_name] = $row[$colun_name];
                }
            $data[] = $entry;
        }
        echo json_encode(array("data" => $data));
    }
    function lubricants_table_month(){
        $dinamicColumns = $_POST['dinamicColumns'];
        $rows = $this->ventas->getLubricantsMonth($_POST['fromDate'], $_POST['untilDate']);
        $data=[];
        foreach ($rows as $key => $row) {
            $entry=[];
                foreach ($dinamicColumns as $key => $column) {
                    $colun_name = $column['data'];
                    $entry[$colun_name] = $row[$colun_name];
                }
            $data[] = $entry;
        }
        echo json_encode(array("data" => $data));
    }
    function mistery_shopper_table(){
        $dinamicColumns = $_POST['dinamicColumns'];
        $rows = $this->auditoriaMysteryModel->getMysteryShopper($_POST['fromDate'], $_POST['untilDate']);
        $data=[];
        foreach ($rows as $key => $row) {
            $entry=[];
                foreach ($dinamicColumns as $key => $column) {
                    $colun_name = $column['data'];
                    $entry[$colun_name] = $row[$colun_name];

                }
            $data[] = $entry;
        }
        echo json_encode(array("data" => $data));
    }
    function date_report($date_mystery){
        $sunday = '';
        if (preg_match('/^(\d{4})-W(\d{2})$/', $date_mystery, $matches)) {
            $year = $matches[1]; // Año
            $week = $matches[2]; // Número de semana
        
            // Crear un objeto DateTime del primer día de esa semana (lunes)
            $datetime = new DateTime();
            $datetime->setISODate($year, $week); // Año y semana ISO
        
            // Cambiar la fecha al domingo de esa semana
            $datetime->modify('+6 days'); // Lunes + 6 días = Domingo
        
            // Formatear la fecha al formato deseado (por ejemplo, YYYY-MM-DD)
            $sunday = $datetime->format('Y-m-d');
        }
        return $sunday;
    }

    public function import_file_mystery_shopper(){
        $date_report = self::date_report($_POST['date_mystery']);
        $existe_report = $this->auditoriaMysteryModel->getMysteryShopperByDate($date_report);
        if($existe_report){
            echo json_encode([
                'success' => false,
                'message' => 'Ya existe un reporte para la fecha seleccionada.'
            ]);
            return;
        }
        $data   = self:: import_data();
        if (!$data['success']) { // Validar éxito
            echo json_encode($data); // Devuelve el error directamente
            return;
        }
        $insert = $this->auditoriaMysteryModel->insertMysteryShopper($data['data'],$date_report);
        if($insert){
            echo json_encode([
                'success' => true,
                'message' => 'Datos importados correctamente.'
            ]);
        }else{
            echo json_encode([
                'success' => false,
                'message' => 'Error al importar los datos.'
            ]);
        }
    }
    
    public function import_data(){
        try {
            ini_set('memory_limit', '256M');
            ini_set('max_execution_time', 300);
            if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['file_to_upload'])) {
                throw new Exception('No se ha subido ningún archivo.');
            }
            $file = $_FILES['file_to_upload'];

            if ($file['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('Error al subir el archivo: ' . $this->getFileErrorMessage($file['error']));
            }
            $inputFileType = 'Xlsx';
            $sheetname = 'PROMEDIOS';
            $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xls();

            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader($inputFileType);
            $reader->setReadDataOnly(true);
            $reader->setReadEmptyCells(false);

            $spreadsheet = $reader->load($file['tmp_name']);
            $worksheet = $spreadsheet->getSheetByName($sheetname);
            if (!$worksheet) {
                throw new Exception("No se encontró la hoja '{$sheetname}' en el archivo.");
            }
            $datos = [];
            foreach ($worksheet->getRowIterator() as $row) {
                $fila = $row->getRowIndex(); // Número de fila actual
                $codigo = $worksheet->getCell("a{$fila}")->getValue(); // Estación
                $estacion = $worksheet->getCell("B{$fila}")->getValue(); // Estación
                $calificacion = $worksheet->getCell("C{$fila}")->getCalculatedValue(); // Calificación calculada
                // Solo guardar si ambas columnas tienen valores
                if (!empty($estacion) && !empty($calificacion) && !empty($codigo)) {
                    $datos[] = [
                        'codigo' => $codigo,
                        'estacion' => $estacion,
                        'calificacion' => $calificacion,
                    ];
                }
            }
            if (empty($datos)) {
                throw new Exception('El archivo no contiene datos válidos.');
            }
    
            return [
                'success' => true,
                'data' => $datos
            ];


        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    private function getFileErrorMessage($errorCode)
    {
        switch ($errorCode) {
            case UPLOAD_ERR_INI_SIZE:
                return 'El archivo excede el tamaño máximo permitido.';
            case UPLOAD_ERR_FORM_SIZE:
                return 'El archivo excede el tamaño máximo permitido por el formulario.';
            case UPLOAD_ERR_PARTIAL:
                return 'El archivo solo se subió parcialmente.';
            case UPLOAD_ERR_NO_FILE:
                return 'No se subió ningún archivo.';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'Falta la carpeta temporal.';
            case UPLOAD_ERR_CANT_WRITE:
                return 'Error al escribir el archivo en el disco.';
            case UPLOAD_ERR_EXTENSION:
                return 'Una extensión de PHP detuvo la subida del archivo.';
            default:
                return 'Error desconocido al subir el archivo.';
        }
    }
    function import_file_budget(){
        $mouth = date('m', strtotime($_POST['date_budget']));
        $year = date('Y', strtotime($_POST['date_budget']));
        $budget = $this->budget->getBudget($mouth,$year);
        if($budget){
            echo json_encode([
                'success' => false, 
                'message' => 'Ya existe un presupuesto para la fecha seleccionada.'
            ]);
            return;
        }
        $data  = self:: import_data_budget();
        if (!$data['success']) { // Validar éxito
            echo json_encode($data); // Devuelve el error directamente
            return;
        }
        $insert = $this->budget->insertBudgetData($data['data']);
        if($insert){
            echo json_encode([
                'success' => true,
                'message' => 'Datos importados correctamente.'
            ]);
        }else{
            echo json_encode([
                'success' => false,
                'message' => 'Error al importar los datos.'
            ]);
        }
       
    }
    function import_data_budget(){
        try {
            ini_set('memory_limit', '256M');
            ini_set('max_execution_time', 300);
            if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['file_to_upload'])) {
                throw new Exception('No se ha subido ningún archivo.');
            }
            $file = $_FILES['file_to_upload'];

            if ($file['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('Error al subir el archivo: ' . $this->getFileErrorMessage($file['error']));
            }
            $maxima192=[33,34,35,36,37,38];
            $inputFileType = 'Xlsx';
            $sheetname = 'PRESUPUESTO';
            $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xls();

            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader($inputFileType);
            $reader->setReadDataOnly(true);
            $reader->setReadEmptyCells(false);

            $spreadsheet = $reader->load($file['tmp_name']);
            $worksheet = $spreadsheet->getSheetByName($sheetname);
            if (!$worksheet) {
                throw new Exception("No se encontró la hoja '{$sheetname}' en el archivo.");
            }
            $datos = [];
            foreach ($worksheet->getRowIterator() as $row) {
                $fila = $row->getRowIndex(); // Número de fila actual
                $codgas = $worksheet->getCell("a{$fila}")->getValue(); // Estación
                $maxima = $worksheet->getCell("D{$fila}")->getValue(); // Estación
                $super = $worksheet->getCell("E{$fila}")->getValue(); // Estación
                $diesel = $worksheet->getCell("F{$fila}")->getValue(); // Estación
                $codprd = 0;
                // Solo guardar si ambas columnas tienen valores
                if (!empty($codgas) && !empty($maxima)) {
                    $codprd = 179;
                    if(in_array($codgas,$maxima192)){
                        $codprd = 192;
                    }
                    $datos[] = [
                        'codgas' => $codgas,
                        'codprd' => $codprd,
                        'budget_monthy' => $maxima,
                        'date_budget' => (new DateTime($_POST['date_budget'] . '-01'))->format('Y-m-d H:i:s') . '.000',
                        'date_added' => date('Y-m-d H:i:s'),
                        'year' => date('Y', strtotime($_POST['date_budget'])),
                        'month' => date('m', strtotime($_POST['date_budget'])),
                    ];
                }
                if (!empty($codgas) && !empty($super)) {
                    $codprd = 180;
                    if(in_array($codgas,$maxima192)){
                        $codprd = 193;
                    }
                    $datos[] = [
                        'codgas' => $codgas,
                        'codprd' => $codprd,
                        'budget_monthy' => $super,
                        'date_budget' => (new DateTime($_POST['date_budget'] . '-01'))->format('Y-m-d H:i:s') . '.000',
                        'date_added' => date('Y-m-d H:i:s'),
                        'year' => date('Y', strtotime($_POST['date_budget'])),
                        'month' => date('m', strtotime($_POST['date_budget'])),
                    ];
                }
                if (!empty($codgas) && !empty($diesel)) {
                    $codprd = 181;

                    $datos[] = [
                        'codgas' => $codgas,
                        'codprd' => $codprd,
                        'budget_monthy' => $diesel,
                        'date_budget' => (new DateTime($_POST['date_budget'] . '-01'))->format('Y-m-d H:i:s') . '.000',
                        'date_added' => date('Y-m-d H:i:s'),
                        'year' => date('Y', strtotime($_POST['date_budget'])),
                        'month' => date('m', strtotime($_POST['date_budget'])),
                    ];
                }
            }
            if (empty($datos)) {
                throw new Exception('El archivo no contiene datos válidos.');
            }
    
            return [
                'success' => true,
                'data' => $datos
            ];


        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    public function download_format_budget(){
        $file = 'C:\inetpub\wwwroot\TG_PHP\_assets\includes\documents/budgetDocumento.xlsx';

        if (file_exists($file)) {
            header('Content-Description: File Transfer');
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename=' . basename($file));
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . filesize($file));
            ob_clean();
            flush();
            readfile($file);
            exit;
        } else {

            http_response_code(404);
            echo 'El archivo no fue encontrado.';
        }
    }
   

}