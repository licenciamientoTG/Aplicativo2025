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
use PhpOffice\PhpSpreadsheet\Style\NumberFormat\Wizard\Duration;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;

require_once('./_assets/classes/code128.php');

class Accounting{
    public $twig;
    public $route;
    public XmlCreModel $xmlCreModel;
    public FacturasModel $facturas;
    public DocumentosModel $Documentos;
    public EstacionesModel $estacionesModel;
    public ComprasPetrotalModel $comprasPetrotalModel;
    public PetrotalConceptosModel $petrotalConceptosModel;
    public ERAjustesModel $eraJustesModel;
    public MovimientosTanModel $movimientosTanModel;
    /**
     * @param $twig
     */
    public function __construct($twig) {
        $this->twig                   = $twig;
        $this->route                  = 'views/accounting/';
        $this->xmlCreModel            = new XmlCreModel();
        $this->facturas               = new FacturasModel();
        $this->Documentos             = new DocumentosModel();
        $this->estacionesModel        = new EstacionesModel();
        $this->comprasPetrotalModel   = new ComprasPetrotalModel();
        $this->petrotalConceptosModel = new PetrotalConceptosModel();
        $this->eraJustesModel         = new ERAjustesModel();
        $this->movimientosTanModel    = new MovimientosTanModel();
    }

    /**
     * @return void
     */
    public function invoices() : void {
        if (preg_match('/GET/i',$_SERVER['REQUEST_METHOD'])){
            echo $this->twig->render($this->route . 'invoices.html');
        }
    }
    public function purchase_invoice() : void {
        if (preg_match('/GET/i',$_SERVER['REQUEST_METHOD'])){
            $first_date = date('Y-01-01');
            echo $this->twig->render($this->route . 'purchase_invoice.html', compact('first_date'));
        }
    }
    public function movement_analysis() : void {
        if (preg_match('/GET/i',$_SERVER['REQUEST_METHOD'])){
            echo $this->twig->render($this->route . 'movement_analysis.html');
        }
    }
    public function supplier_payments() : void {
        if (preg_match('/GET/i',$_SERVER['REQUEST_METHOD'])){
            $first_date = date('Y-01-01');
            echo $this->twig->render($this->route . 'supplier_payments.html', compact('first_date'));
        }
    }

    public function InvoiceConceptModal(){
        $invoice = $this->facturas->get_factura_by_uuid($_POST['uuid']);
        echo $this->twig->render($this->route . 'modals/invoice_concept_modal.html', compact('invoice'));


    }
    public function adjustmentModal(){
        echo $this->twig->render($this->route . 'modals/adjustmentModal.html');
    }

    public function income_statement() : void {
        if (preg_match('/GET/i',$_SERVER['REQUEST_METHOD'])){
            echo $this->twig->render($this->route . 'income_statement.html');
        }
    }
    public function form_save_adjustments(){
        if (preg_match('/POST/i',$_SERVER['REQUEST_METHOD'])){
            $data = $_POST;
            $data['fecha'] = date('Y-m-d', strtotime($data['fecha']));
            $data['fecha_agregado'] = date('Y-m-d H:i:s');
            if ($id = $this->eraJustesModel->add($data)) {
                $response = json_encode(array("status" => "success", "message" => "Ajuste agregado correctamente.", "id" => $id));
            } else {
                $response = json_encode(array("status" => "error", "message" => "Error al agregar el ajuste."));
            }
            echo $response;
        }
    }

    public function tax_stimulus() : void {
        if (preg_match('/GET/i',$_SERVER['REQUEST_METHOD'])){
            echo $this->twig->render($this->route . 'tax_stimulus.html');
        } else {
            $from = $_POST['from'];
            $until = $_POST['until'];
            $est87 = $_POST['est87'];
            $est91 = $_POST['est91'];
            echo $this->twig->render($this->route . 'tax_stimulus.html', compact('from', 'until', 'est87', 'est91'));
        }
    }

    /**
     * @param $rutaVolumetricos
     * @param $permisoCRE
     * @param $fecha
     * @return void
     */
    function getXmlFromPath($rutaVolumetricos, $permisoCRE, $fecha) : void {
        // Ruta al script de Python
        $script_path = "C:\\Users\\Administrador\\Desktop\\test.py";

        // Parámetros para el script de Python
        $parameters = ["arg1"];

        // Ejecuta el script de Python
        $output = shell_exec("python $script_path 'test'");

        // Imprime la salida del script de Python
        echo $output;
    }

    /**
     * @return void
     */
    function stimulus_table() : void  {
        $data = [];
        if ($estimulus = $this->xmlCreModel->get_estimulus(str_replace('-', '', $_GET['inicial']), str_replace('-', '', $_GET['final']), $_GET['est87'], $_GET['est91'])) {

            foreach ($estimulus as $est) {

                $dt = DateTime::createFromFormat('d/m/Y', $est['Fecha']);
                $tax_date = $dt ? $dt->format('Y-m-d') : null;

                $data[] = array(
                    'cveest'            => $est['cveest'],
                    'station'           => trim($est['Estacion']),
                    'tax_date'          => $tax_date,
                    'nropcc'            => $est['PermisoCRE'],
                    'product'           => trim($est['Producto']),
                    'Cve_Producto'      => $est['CveProducto'],
                    'less150'           => number_format($est['Menores'], 2),
                    'more150'           => number_format($est['Mayores'], 2),
                    'consumes'          => number_format($est['Internos'], 3),
                    'calibration'       => number_format($est['Jarreos'], 3),
                    'dues'              => number_format($est['IEPS'], 2),
                    'volume'            => $est['Volumen'],
                    'volume_controlgas' => (is_null($est['VolumenVolumetrico']) ? 0 : $est['VolumenVolumetrico']),
                    'difference'        => $est['Variacion'],
                    'amount'            => ($est['IEPS'] * $est['Menores']),
                );
            }
        }
        json_output(array("data" => $data));
    }

    function invoice_table() : void {
        $data = [];

        $from = date('Ymd H:i:s', strtotime($_POST['from'] . ' 00:00:00'));
        $until = date('Ymd H:i:s', strtotime($_POST['until'] . ' 23:59:59'));

        if ($invoices = $this->facturas->filter_facturas_by_date_range($from,$until, $_POST['rfc'])) {
            foreach ($invoices as $invoice) {
                $uuid = '<a href="javascript:void(0);" onClick="InvoiceConceptModal(\''. $invoice['UUID'] .'\' )">'. $invoice['UUID'].'<a>';
                $data[] = array(
                    'Fecha'                     => date('Y-m-d H:I:s', strtotime($invoice['Fecha'])  ),
                    'Folio'                     => $invoice['Folio'],
                    'Serie'                     => $invoice['Serie'],
                    'EmisorRfc'                 => $invoice['EmisorRfc'],
                    'ReceptorNombre'            => $invoice['ReceptorNombre'],
                    'ReceptorRfc'               => $invoice['ReceptorRfc'],
                    'SubTotal'                  => $invoice['SubTotal'],
                    'TotalImpuestosTrasladados' => $invoice['TotalImpuestosTrasladados'],
                    'Total'                     => $invoice['Total'],
                    'FechaTimbrado'             => date('Y-m-d H:I:s', strtotime($invoice['FechaTimbrado'])),
                    'MetodoPago'                => $invoice['MetodoPago'],
                    'UUID'                      => $uuid,
                );
            }
        }
        json_output(array("data" => $data));
    }

    public function invoice_purchase_table() {
        set_time_limit(280);
        header('Content-Type: application/json');
        if ($rows = $this->Documentos->GetInvoicePurchase($_POST['fromDate'], $_POST['untilDate'], $_POST['product'])) {
            foreach ($rows as $row) {
                $data[] = array(
                    'Fecha'             => $row['Fecha'],
                    'Fecha_vencimiento' => $row['Fecha_vencimiento'],
                    'cod_proveedor'     => $row['cod_proveedor'],
                    'proveedor'         => $row['proveedor'],
                    'Factura'           => $row['Factura'],
                    'txtref'            => $row['txtref'],
                    'codgas'            => $row['codgas'],
                    'Estacion'          => $row['Estacion'],
                    'producto'          => $row['producto'],
                    'Empresa'           => $row['Empresa'],
                    'satuid'            => $row['satuid'],
                    'can'               => $row['can'],
                    'pre'               => $row['pre'],
                    'mto'               => $row['mto'],
                    'mtoori'            => $row['mtoori'],
                    'mtoiva'            => $row['mtoiva'],
                    'mtoiie'            => $row['mtoiie'],
                    'Subtotal'          => $row['Subtotal'],
                    'Total'             => $row['Total'],
                    'IvaImporte'        => $row['IvaImporte'],
                    'cantidad'          => $row['cantidad'],
                    'precio'            => $row['precio'],
                    'importe'           => $row['importe'],
                    'IEPS'              => $row['IEPS'],
                    'imp_des_pro'       => $row['imp_des_pro'],
                    'imp_id_otr_sis_pro'=> $row['imp_id_otr_sis_pro'],
                    'folio_dr'          => $row['folio_dr'],
                    'num_parc_dr'       => $row['num_parc_dr'],
                    'id_pag_det'        => $row['id_pag_det'],
                    'Ref_Numerica'      => $row['Ref_Numerica'],
                    'fecha_pago'        => $row['fecha_pago'],
                    'monto_pago'        => $row['monto_pago'],
                    'monto_pago_fac'    => $row['monto_pago_fac'],
                    'cuenta'            => $row['cuenta'],
                    'banco'             => $row['banco'],
                    'num_factura_OG'    => $row['num_factura_OG'],
                    'Numero_pago_OG'    => $row['Numero_pago_OG'],
                );
            }
            $data = array("data" => $data);
            echo json_encode($data);
        } else {
            echo json_encode(["data" => []]); // Devuelve un array vacío si no hay datos
        }
    }
    public function income_statement_table(){
        ini_set('max_execution_time', 5000);
        ini_set('memory_limit', '1024M');
        set_time_limit(0);
        header('Content-Type: application/json');
        $postData = [
            'year' => $_POST['year']
        ];
        $ch = curl_init('http://192.168.0.109:82/api/concentrado-resultados/');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_POST, true);

        // Ejecutar y obtener respuesta
        $response = curl_exec($ch);
        curl_close($ch);
        $apiData = json_decode($response, true);
        

        if (count($apiData) > 0) {
            foreach ($apiData as $row) {
                $origin = $row['origin'];
                if ($origin == 'ajustes'){
                    $origin = $origin . ' <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-trash" viewBox="0 0 16 16">
                        <path d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5m2.5 0a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5m3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0z"/>
                        <path d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1H6a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1h3.5a1 1 0 0 1 1 1zM4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4zM2.5 3h11V2h-11z"/>
                        </svg>';
                }
                $data[] = [
                    'Empresa'        => $row['Empresa'],
                    'CentroCosto'    => $row['CentroCosto'],
                    'CatCentroCosto' => $row['CatCentroCosto'],
                    'NoCuenta'       => $row['NoCuenta'],
                    'Rubro'          => $row['Rubro'],
                    'Concepto'       => $row['Concepto'],
                    'Enero'          => $row['Enero'],
                    'Febrero'        => $row['Febrero'],
                    'Marzo'          => $row['Marzo'],
                    'Abril'          => $row['Abril'],
                    'Mayo'           => $row['Mayo'],
                    'Junio'          => $row['Junio'],
                    'Julio'          => $row['Julio'],
                    'Agosto'         => $row['Agosto'],
                    'Septiembre'     => $row['Septiembre'],
                    'Octubre'        => $row['Octubre'],
                    'Noviembre'      => $row['Noviembre'],
                    'Diciembre'      => $row['Diciembre'],
                    'origin'         => $row['origin'],
                    'origin_text'   => $origin,
                ];
            }
            $data = array("data" => $data);
            echo json_encode($data);
        } else {
            echo json_encode(["data" => []]); // Devuelve un array vacío si no hay datos
        }

    }

    public function drawAnnualTable(){

        ini_set('max_execution_time', 5000);
        ini_set('memory_limit', '1024M');
        set_time_limit(0);
        header('Content-Type: application/json');
        $postData = [
            'year' => $_POST['year']
        ];
        $ch = curl_init('http://192.168.0.109:82/api/concentrado-anual/');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_POST, true);

        // Ejecutar y obtener respuesta
        $response = curl_exec($ch);
        curl_close($ch);
        $apiData = json_decode($response, true);

        echo json_encode($apiData);
    }
    

    public function get_er_budget(){

        ini_set('max_execution_time', 5000);
        ini_set('memory_limit', '1024M');
        set_time_limit(0);
        header('Content-Type: application/json');
        $postData = [
            'year' => $_POST['year']
        ];
        $ch = curl_init('http://192.168.0.109:82/api/get_er_budget/');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_POST, true);

        // Ejecutar y obtener respuesta
        $response = curl_exec($ch);
        curl_close($ch);
        $apiData = json_decode($response, true);

        echo json_encode($apiData);
    }
    public function payments_table() {
        set_time_limit(280);
        header('Content-Type: application/json');
        $fromDate = $_POST['fromDate'];
        $untilDate = $_POST['untilDate'];

        // Preparar los datos para enviar a la API externa
        $postData = [
            'fromDate' => $fromDate,
            'untilDate' => $untilDate
        ];
        $ch = curl_init('http://192.168.0.3:388/api/pagos/get_pagos');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_POST, true);

        // Ejecutar y obtener respuesta
        $response = curl_exec($ch);
        curl_close($ch);

        $apiData = json_decode($response, true);
      
        if (count($apiData) > 0) {
            foreach ($apiData as $row) {
                $total        = intval(floatval($row['total']));         // e.g. 123.99 → 123
                $totalControl = intval(floatval($row['total_control'])); // e.g. 123.01 → 123
                // 2) Definimos el sufijo SI/NO
                $status = ($total === $totalControl) ? 'SI' : 'NO';

                // 3) Concatenamos al control original
                $controlText = $row['control'] . ' ' . $status;
                $data[] = array(
                    'num_doc'           => $row['num_doc'],
                    'clave'             => $row['clave'],
                    'id_prov'           => $row['id_prov'],
                    'nom1'              => $row['nom1'],
                    'cuenta'            => $row['cuenta'],
                    'banco'             => $row['banco'],
                    'Ref_num'           => $row['Ref_num'],
                    'ref_ben'           => $row['ref_ben'],
                    'fecha'             => $row['fecha'],
                    'monto'             => $row['monto'],
                    'cargo'             => $row['cargo'],
                    'folio'             => $row['folio'],
                    'fec_doc'           => $row['fec_doc'],
                    'importe'           => $row['importe'],
                    'imptos'            => $row['imptos'],
                    'total'             => $row['total'],
                    'aplicado'          => $row['aplicado'],
                    'ptg_apl'           => $row['ptg_apl'],
                    'uuid_i'            => $row['uuid_i'],
                    'folio_dr'            => $row['folio_dr'],
                    'control'           => $controlText,
                    'control_estado'    => $status,
                    'Fecha_control'     => $row['Fecha_control'],
                    'Fecha_vencimiento'=> $row['Fecha_vencimiento'],
                    'can'               => $row['can'],
                    'pre'               => $row['pre'],
                    'mto'               => $row['mto'],
                    'mtoiva'            => $row['mtoiva'],
                    'total_control'     => $row['total_control'],
                    'codgas'            => $row['codgas'],
                    'codprd'            => $row['codprd'],
                    'mtoori'            => $row['mtoori'],
                    'producto'          => $row['producto'],
                    'estacion'          => $row['estacion'],
                    'Factura'          => $row['Factura'],
                    'documento'          => $row['documento'],
                );
            }
            $data = array("data" => $data);
            echo json_encode($data);
        } else {
            echo json_encode(["data" => []]); // Devuelve un array vacío si no hay datos
        }
    }

    function volumetrics() {
        if (preg_match('/GET/i',$_SERVER['REQUEST_METHOD'])){
            // La fecha inicial sera el primer dia del mes anterior
            $from = date('Y-m-01', strtotime('-1 month'));
            // La fecha final sera el ultimo dia del mes anterior
            $until = date('Y-m-t', strtotime('-1 month'));
            echo $this->twig->render($this->route . 'volumetrics.html', compact('from', 'until'));
        }
    }

    function volumetrics_comparator() {
        $stations = $this->estacionesModel->get_actives_stations();
        echo $this->twig->render($this->route . 'volumetrics_comparator.html' , compact('stations'));
    }

    function volumetrics_table() {
        $data = [];
        $from = date('Y-m-01', strtotime('-1 month'));
        $until = date('Y-m-t', strtotime('-1 month'));
    
        $stations = $this->estacionesModel->get_actives_stations();
        foreach ($stations as $key => $station) {
            $volumetrics_data = $this->estacionesModel->get_volumetrics($station['PermisoCRE'], $from, $until);
            
            // // Ejecutar script PSEXEC
            // $psexec_result = $this->execute_volumetrics_script($station['Ip']);
    
            $actions = '
            <div class="btn-group" role="group" aria-label="Basic mixed styles example">
                <button type="button" class="btn btn-success" onclick="executeScript(\'' . $station['Ip'] . '\')">Generar</button>
                <form method="post" action="/accounting/download_volumetrics/'. $from .'/'. $until .'">
                    <input type="hidden" name="permisoCre" value="'. $station['PermisoCRE'] .'">
                    <button type="input" class="btn btn-primary">Descargar</button>
                </form>
                <form method="post" action="/accounting/delete_volumetrics/'. $from .'/'. $until .'">
                    <input type="hidden" name="permisoCre" value="'. $station['PermisoCRE'] .'">
                    <button type="input" class="btn btn-danger">Eliminar</button>
                </form>
            </div>
            ';
    
            $data[] = array(
                "name" => $station['Nombre'],
                "permission_cre" => $station['PermisoCRE'],
                "company" => $station['Company'],
                "ip" => $station['Ip'],
                "status" => ((@fsockopen($station['Ip'], 1433, $errno, $errstr, 2)) ? "✅" : "❌"),
                "notes" => "<p class=\"text-nowrap m-0 p-0\">Archivos PL: {$volumetrics_data['Total_PL']}</p><p class=\"text-nowrap m-0 p-0\">Archivos D: {$volumetrics_data['Total_D']}</p><p class=\"text-nowrap m-0 p-0\">Archivos M: {$volumetrics_data['Total_M']}</p>",
                "actions" => $actions
            );
        }
    
        json_output(array("data" => $data));
    }

    function delete_volumetrics($from, $until) {
        $permisoCre = $_POST['permisoCre'];
        $this->estacionesModel->delete_volumetrics($permisoCre, $from, $until);
        redirect('/accounting/volumetrics');
    }

    function download_volumetrics($from, $until) {
        $permisoCre = $_POST['permisoCre'];
        if ($files = $this->estacionesModel->download_volumetrics($permisoCre, $from, $until)) {
            // Carpeta temporal
            $tempDir = sys_get_temp_dir() . '/volumetrics_' . uniqid();
            mkdir($tempDir, 0777, true);
            foreach ($files as $index => $contenido) {
                $filePath = $tempDir . '/' . $contenido['name'];
                file_put_contents($filePath, $contenido['contentxml']);
            }
        }

        // Crear el archivo ZIP
        $zipFile = $tempDir . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($zipFile, ZipArchive::CREATE) === TRUE) {
            foreach (glob("$tempDir/*.xml") as $file) {
                $zip->addFile($file, basename($file));
            }
            $zip->close();
        } else {
            die("No se pudo crear el archivo ZIP.");
        }

        // Descargar el archivo
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="volumetricos_' . date('Ymd_His') . '.zip"');
        header('Content-Length: ' . filesize($zipFile));
        readfile($zipFile);

        // Limpieza
        array_map('unlink', glob("$tempDir/*.xml"));
        rmdir($tempDir);
        unlink($zipFile);
        // Tenemos que redirigir la pagina
        redirect('/accounting/volumetrics');
    }

    function execute_volumetrics_script() {
        if (preg_match('/POST/i',$_SERVER['REQUEST_METHOD'])){
            // Leer el contenido del cuerpo de la petición
            $json = file_get_contents('php://input');

            // Decodificar el JSON recibido a un array asociativo
            $data = json_decode($json, true);

            // Acceder a los datos enviados
            $remoteIP = isset($data['ip']) ? $data['ip'] : null;
            $user = isset($data['user']) ? $data['user'] : null;
            $password = isset($data['password']) ? $data['password'] : null;

            // Ruta completa al ejecutable C# compilado
            $exePath = 'C:\\Software\\Scripts\\ExecSGCV\\bin\\Release\\net9.0\\win-x64\\ExecSGCV.exe';

            // Construir el comando usando escapeshellarg para cada parte
            $cmd = escapeshellarg($exePath) . ' ' . escapeshellarg($remoteIP) . ' ' . escapeshellarg($user) . ' ' . escapeshellarg($password) . ' 2>&1';

            // Ejecuta el comando y captura la salida en un array y el código de retorno
            $output = [];
            $returnVar = 0;
            exec($cmd, $output, $returnVar);

            echo "<pre>";
            echo "Comando ejecutado: " . $cmd . "\n\n";
            echo "Código de retorno: " . $returnVar . "\n\n";
            echo "Salida:\n" . implode("\n", $output);
            echo "</pre>";

            return $output;
        } else {
            echo "No se ha recibido ningún POST.";
        }
    }

    function excel_volumetrics($from, $until) {
        ini_set('memory_limit', '512M'); // puedes subir a 1024M si hace falta
        set_time_limit(0);
        ini_set('max_execution_time', 0);

        $permisoCre = $_POST['permisoCre'] ?? null;
        if (!$permisoCre) {
            http_response_code(400);
            echo "Falta el permiso CRE";
            return;
        }

        try {
            $spreadsheet = $this->estacionesModel->sp_obtener_entregas_volumetricas_por_rango(
                $permisoCre, $from, $until, 'D'
            );

            if (!$spreadsheet instanceof Spreadsheet) {
                throw new Exception("The function sp_obtener_entregas_volumetricas_por_rango did not return a valid Spreadsheet object.");
            }

            $writer = new Xlsx($spreadsheet);
            $fileName = "entregas_" . date('Ymd_His') . ".xlsx";
            $filePath = __DIR__ . "/../../../tmp_excel/" . $fileName;

            // Asegúrate de que exista la carpeta tmp_excel y tenga permisos
            if (!is_dir(dirname($filePath))) {
                mkdir(dirname($filePath), 0777, true);
            }

            // Guardar archivo en disco primero
            $writer->save($filePath);

            // Enviar archivo al navegador
            header("Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
            header("Content-Disposition: attachment; filename=\"$fileName\"");
            header("Content-Length: " . filesize($filePath));

            readfile($filePath);
            unlink($filePath); // opcional: eliminar archivo después de descargar
            exit;

        } catch (Exception $e) {
            http_response_code(500);
            echo "Error generando Excel: " . $e->getMessage();
        }
    }

    public function download_format_sales_petrotal(){
        $file = 'C:\inetpub\wwwroot\TG_PHP\_assets\includes\documents/FormatoVentasPetrotal.xlsx';

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
    function import_file_sales_petrotal(){
        try {
            ini_set('memory_limit', '512M');
            ini_set('max_execution_time', 300);

            if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['file_to_upload']['tmp_name'])) {
                throw new Exception('No se ha subido ningún archivo.');
            }

            $file = $_FILES['file_to_upload'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('Error al subir el archivo: ' . $this->getFileErrorMessage($file['error']));
            }

            $reader = IOFactory::createReaderForFile($file['tmp_name']);
            $reader->setReadDataOnly(true);
            $reader->setReadEmptyCells(false);
            $spreadsheet = $reader->load($file['tmp_name']);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray();

            if (count($rows) <= 1) {
                throw new Exception('El archivo no contiene datos válidos.');
            }

            $data = [];
            foreach ($rows as $i => $r) {
                if ($i === 0) continue; // Skip header
                $utilidad = trim($r[24] ?? '');
                if ($utilidad === '') continue;

                // Fecha
                $valueFecha = trim($r[2] ?? '');
                if ($valueFecha === '') {
                    $dtFecha = null;
                } elseif (is_numeric($valueFecha)) {
                    $dtFecha = Date::excelToDateTimeObject($valueFecha);
                } else {
                    try {
                        $dtFecha = new \DateTime($valueFecha);
                    } catch (\Exception $e) {
                        $dtFecha = null;
                    }
                }
                $fecha = $dtFecha ? $dtFecha->format('Y-m-d') : null;


                // Fecha descarga
                $fd = trim($r[8] ?? '');
                if ($fd === '') {
                    $descFecha = null;
                } elseif (is_numeric($fd)) {
                    $descFecha = Date::excelToDateTimeObject($fd);
                } else {
                    $descFecha = null;
                }
                $fechaDescarga = $descFecha ? $descFecha->format('Y-m-d') : null;


                $valuePago = trim($r[27] ?? '');
                if ($valuePago === '') {
                    $dtPago = null;
                } elseif (is_numeric($valuePago)) {
                    $dtPago = Date::excelToDateTimeObject($valuePago);
                } else {
                    try {
                        $dtPago = new \DateTime($valuePago);
                    } catch (\Exception $e) {
                        $dtPago = null;
                    }
                }
                $fechaPago = $dtPago ? $dtPago->format('Y-m-d') : null;

                $data[] = [
                    'anio'              => (int)$r[0],
                    'mes_deuda'         => $r[1],
                    'fecha'             => $fecha,
                    'factura'           => $r[3],
                    'num_estacion'      => $r[4],
                    'razon_social'      => $r[5],
                    'estacion'          => $r[6],
                    'cre_estacion'      => $r[7],
                    'fecha_descarga'    => $fechaDescarga,
                    'proveedor'         => $r[9],
                    'codigo_proveedor'  => $r[10],
                    'cre_proveedor'     => $r[11],
                    'combustible'       => $r[12],
                    'factor_ieps'       => (float)$r[13],
                    'litros'            => (float)$r[14],
                    'precio'            => (float)$r[15],
                    'precio_litro'      => (float)$r[16],
                    'subtotal_con_ieps' => (float)$r[17],
                    'ieps'              => (float)$r[18],
                    'subtotal_sin_ieps' => (float)$r[19],
                    'iva'               => (float)$r[20],
                    'total'             => (float)$r[21],
                    'costo'             => (float)$r[22],
                    'factura_compra'    => $r[23],
                    'utilidad_perdida'  => (float)$r[24],
                    'monto_pagado'      => (float)$r[25],
                    'iva_pagado'        => (float)$r[26],
                    'fecha_pago'        => $fechaPago,
                    'uuid'              => $r[28]?? '',
                    'tasa_iva'          => $r[29],
                    'indicador_1'       => $r[33],
                ];
            }


            // Enviar a tu modelo (ComprasPetrotalModel)
            $result = $this->comprasPetrotalModel->insertCompras($data);
            // echo '<pre>';
            // var_dump($result);
            // die();
            if (!$result['success']) {
                throw new Exception($result['message']);
            }
            
            echo json_encode([
                'success' => true, 
                'message' => 'Importación exitosa.'
            ]);
            return;

        } catch (\Exception $e) {
           echo json_encode([
                'success' => false,
                'message' => 'Error al importar los datos.'
            ]);
        }
    }

    function import_file_concept_petrotal(){
        try {

            $fechaObj = DateTime::createFromFormat('Y-m', $_POST['date']);
            $fechaCompleta = $fechaObj->format('Y-m-01'); // "2025-01-01"

            ini_set('memory_limit', '512M');
            ini_set('max_execution_time', 300);

            if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['file_to_upload']['tmp_name'])) {
                throw new Exception('No se ha subido ningún archivo.');
            }

            $file = $_FILES['file_to_upload'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('Error al subir el archivo: ' . $this->getFileErrorMessage($file['error']));
            }


            $reader = IOFactory::createReaderForFile($file['tmp_name']);
            $reader->setReadDataOnly(true);
            $reader->setReadEmptyCells(false);
            $spreadsheet = $reader->load($file['tmp_name']);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray();

            if (count($rows) <= 1) {
                throw new Exception('El archivo no contiene datos válidos.');
            }

            $data = [];
            foreach ($rows as $i => $r) {
                if ($i === 0) continue; // Skip header
                $data[] = [
                    'rubro'  => $r[0],
                    'cuenta' => $r[1],
                    'valor'  => $r[2],
                    'fecha'  => $fechaCompleta,
                ];
            }
            // Enviar a tu modelo (ComprasPetrotalModel)
            $result = $this->petrotalConceptosModel->insertPetrotal($data);

            if (!$result['success']) {
                throw new Exception($result['message']);
            }

            echo json_encode([
                'success' => true, 
                'message' => 'Importación exitosa.'
            ]);
            return;

        } catch (\Exception $e) {
           echo json_encode([
                'success' => false,
                'message' => 'Error al importar los datos.'
            ]);
        }
    }
    public function save_spend_petrotal(){
        $fecha  = $_POST['fecha']. '-01'; // Aseguramos que la fecha tenga el formato correcto
        $gasto  = $_POST['gasto'];
        $spend = $this->petrotalConceptosModel->get_row($fecha);

        if(!$spend) {
            $response= $this->petrotalConceptosModel->save_spend_petrotal($fecha, $gasto);
        }else {
            $response = $this->petrotalConceptosModel->update_spend_petrotal($fecha, $gasto, $spend['id']);
        }

        if ($response) {
            echo json_encode([
                'success' => true,
                'message' => 'Gasto guardado exitosamente.'
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Error al guardar el gasto.'
            ]);
        }

    }
    function spend_real(){
        $fecha  = $_POST['fecha']. '-01';
        $spend = $this->petrotalConceptosModel->get_row($fecha);

        $spend =  $spend['gasto'] ?? 0; // Si no hay gasto, asignamos 0
        echo json_encode([
            'success' => true,
            'spend' => $spend
        ]);

    }

    public function getFileErrorMessage($errorCode)
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

    public function sales_petrotal_table() {

        $from = $_POST['fromDate'] ?? null;
        $until = $_POST['untilDate'] ?? null;
        if ($rows = $this->comprasPetrotalModel->get_compras_by_fecha($from, $until)) {
            foreach ($rows as $row) {
                $data[] = [
                    'anio'                 => $row['anio'],
                    'mes_deuda'            => $row['mes_deuda'],
                    'fecha'                => $row['fecha'],
                    'factura'              => $row['factura'],
                    'num_estacion'         => $row['num_estacion'],
                    'razon_social'         => $row['razon_social'],
                    'estacion'             => $row['estacion'],
                    'cre_estacion'         => $row['cre_estacion'],
                    'fecha_descarga'       => $row['fecha_descarga'],
                    'proveedor'            => $row['proveedor'],
                    'codigo_proveedor'     => $row['codigo_proveedor'],
                    'cre_proveedor'        => $row['cre_proveedor'],
                    'combustible'          => $row['combustible'],
                    'factor_ieps'          => $row['factor_ieps'],
                    'litros'               => $row['litros'],
                    'precio'               => $row['precio'],
                    'precio_litro'         => $row['precio_litro'],
                    'subtotal_con_ieps'    => $row['subtotal_con_ieps'],
                    'ieps'                 => $row['ieps'],
                    'subtotal_sin_ieps'    => $row['subtotal_sin_ieps'],
                    'iva'                  => $row['iva'],
                    'total'                => $row['total'],
                    'costo'                => $row['costo'],
                    'factura_compra'       => $row['factura_compra'],
                    'utilidad_perdida'     => $row['utilidad_perdida'],
                    'monto_pagado'         => $row['monto_pagado'],
                    'iva_pagado'           => $row['iva_pagado'],
                    'fecha_pago'           => $row['fecha_pago'],
                    'uuid'                 => $row['uuid'],
                    'tasa_iva'             => $row['tasa_iva'],
                    'indicador_1'          => $row['indicador_1']
                ];
            }
            $data = array("data" => $data);
            echo json_encode($data);
        } else {
            echo json_encode(["data" => []]); // Devuelve un array vacío si no hay datos
        }
    } 
    public function er_petrotal_table() {
        ini_set('max_execution_time', 5000);
        ini_set('memory_limit', '1024M');
        set_time_limit(0);
        header('Content-Type: application/json');
        $date = '2025-04-01'; // Fecha de ejemplo, puedes cambiarla según tus necesidades
        $postData = [
            'date' => $_POST['fromDate'] ?? $date, // Usar la fecha del POST o una por defecto
        ];
        $ch = curl_init('http://192.168.0.109:82/api/er_petrotal/');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_POST, true);

        // Ejecutar y obtener respuesta
        $response = curl_exec($ch);
        curl_close($ch);
        $apiData = json_decode($response, true);

        if (count($apiData) > 0) {
            foreach ($apiData as $row) {
                $data[] = [
                    'estacion'           => $row['estacion'],
                    'etiqueta'           => $row['Etiquetas de fila'], // Ajusta al nombre exacto
                    'diesel'             => $row['DIESEL'],
                    'premium'            => $row['PREMIUM'],
                    'regular'            => $row['REGULAR'],
                    'premium_porcent'    => (round($row['premium_porcentaje'],2)).' %',
                    'regular_porcent'    => (round($row['regular_porcentaje'],2)).' %',
                    'diesel_porcent'    => (round($row['diesel_porcentaje'],2)).' %',
                    'diesel_utilidad'    => $row['diesel_utilidad'],
                    'premium_utilidad'   => $row['premium_utilidad'],
                    'regular_utilidad'   => $row['regular_utilidad'],
                    'total'              => ($row['diesel_utilidad'] +$row['premium_utilidad'] +$row['regular_utilidad']),
    
                ];
            }
        }
        $data = array("data" => $data);
        echo json_encode($data);

    } 

    public function er_petrotal_concept(){
        ini_set('max_execution_time', 5000);
        ini_set('memory_limit', '1024M');
        set_time_limit(0);
        header('Content-Type: application/json');
        $postData = [
            'date' => $_POST['date'] // Usar la fecha del POST o una por defecto
        ];
        $ch = curl_init('http://192.168.0.109:82/api/er_petrotal_concept/');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_POST, true);

        // Ejecutar y obtener respuesta
        $response = curl_exec($ch);
        curl_close($ch);
        $apiData = json_decode($response, true);
        echo json_encode($apiData);

    }
     public function download_format_concept_petrotal(){
        $file = 'C:\inetpub\wwwroot\TG_PHP\_assets\includes\documents/FormatoConceptosPetrotal.xlsx';

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


    public function movement_analysis_table() {
        set_time_limit(280);
        header('Content-Type: application/json');

        $from = dateToInt($_POST['fromDate']);
        $until = dateToInt($_POST['untilDate']);
        $codgas = $_POST['codgas'];
        $supplier = $_POST['supplier'];

        if ($rows = $this->Documentos->movement_analysis_table($from,$until,$codgas,$supplier)) {

            foreach ($rows as $row) {
                $data[] = array(
                    'Número'          => $row['Número'],
                    'Factura'         => $row['Factura'],
                    'Orden de Compra' => $row['Orden de Compra'],
                    'Fecha'           => $row['Fecha'],
                    'Vencimiento'     => $row['Vencimiento'],
                    'Producto'        => $row['Producto'],
                    'VolumenRecibido' => $row['VolumenRecibido'],
                    'Facturado'       => $row['Facturado'],
                    'Importe'         => $row['Importe'],
                    'IEPS'            => $row['I.E.P.S'],
                    'IVA'             => ($row['I.V.A.'] + $row['iva_concepto']),
                    'Recargos'        => $row['Recargos'],
                    'TotalFactura'    => $row['TotalFactura'],
                    'Estación'        => $row['Estación'],
                    'UUID'            => $row['UUID'],
                    'RFC'             => $row['RFC'],
                    'Remision'        => $row['Remision'],
                    'Vehiculo'        => $row['Vehiculo'],
                    'Proveedor'       => $row['Proveedor'],
                );
            }
            $data = array("data" => $data);
            echo json_encode($data);
        } else {
            echo json_encode(["data" => []]); // Devuelve un array vacío si no hay datos
        }
    }

    function folio_analysis_table() {
        set_time_limit(280);
        header('Content-Type: application/json');

        $folios = $_POST['folios'];
        $codgas = $_POST['codgas2'];


        // 1️⃣ Quitar espacios en blanco alrededor de todo
        $folios = trim($folios);

        // 2️⃣ Reemplazar comas dobles o triples por una sola
        $folios = preg_replace('/,+/', ',', $folios);

        // 3️⃣ Separar por comas
        $foliosArray = explode(',', $folios);

        // 4️⃣ Eliminar elementos vacíos y espacios extra
        $foliosArray = array_filter(array_map('trim', $foliosArray), 'strlen');

        // 5️⃣ (Opcional) Eliminar duplicados
        $foliosArray = array_unique($foliosArray);

        // 6️⃣ (Opcional) Reordenar si querés que queden ordenados numéricamente
        sort($foliosArray, SORT_NUMERIC);

        // 7️⃣ Si necesitás devolverlo como string limpio:
        $foliosLimpio = implode(',', $foliosArray);

        $data = [];
        if ($rows = $this->Documentos->movement_analysis_table2($foliosLimpio,$codgas)) {
            foreach ($rows as $row) {
                $data[] = array(
                    'Número'          => $row['Número'],
                    'Factura'         => $row['Factura'],
                    'Orden de Compra' => $row['Orden de Compra'],
                    'Fecha'           => $row['Fecha'],
                    'Vencimiento'     => $row['Vencimiento'],
                    'Producto'        => $row['Producto'],
                    'VolumenRecibido' => $row['VolumenRecibido'],
                    'Facturado'       => $row['Facturado'],
                    'Importe'         => $row['Importe'],
                    'IEPS'            => $row['I.E.P.S'],
                    'IVA'             => ($row['I.V.A.'] + $row['iva_concepto']),
                    'Recargos'        => $row['Recargos'],
                    'TotalFactura'    => $row['TotalFactura'],
                    'Estación'        => $row['Estación'],
                    'UUID'            => $row['UUID'],
                    'RFC'             => $row['RFC'],
                    'Remision'        => $row['Remision'],
                    'Vehiculo'        => $row['Vehiculo'],
                    'Proveedor'       => $row['Proveedor'],
                );
            }
        }
        $data = array("data" => $data);
        echo json_encode($data);
        
    }

    function fuel_purchases() {
        if (preg_match('/GET/i',$_SERVER['REQUEST_METHOD'])){
            $from = $_GET['from'] ?? date('Y-m-d', strtotime('-1 day'));
            $until = $_GET['until'] ?? date('Y-m-d', strtotime('-1 day'));
            $suppliers = $this->Documentos->get_suppliers();
            $codgas = $_GET['station'] ?? 0 ;
            $supplier = $_GET['supplier'] ?? 0 ;
            $stations = $this->estacionesModel->get_select_stations();
            echo $this->twig->render($this->route . 'movement_analysis.html', compact('from','until','stations','codgas','suppliers','supplier'));
        }
    }


    function print_purchase_receipts($from, $until, $codgas = 0, $supplier = 0) {
        if ($rows = $this->Documentos->movement_analysis_table(dateToInt($from), dateToInt($until), $codgas, $supplier)) {
            // Crear una instancia de FPDF
            $pdf = new PDF_Code128();
            
            // Establecer los márgenes
            $pdf->SetMargins(5, 5, 5);  // Margen izquierdo, margen superior, margen derecho
            
            // Establecer el margen inferior
            $pdf->SetAutoPageBreak(true, 12);  // Aumentado a 12 mm para el footer
            
            $pageNumber = 0; // Contador de páginas
            
            foreach ($rows as $key => $row) {
                // Agregar página en formato horizontal de 85x54mm (tamaño tarjeta)
                $pdf->AddPage('P');
                $pageNumber++; // Incrementar contador
                
                // Configurar fuente para el encabezado
                $pdf->SetFont('Arial', 'B', 9);
                
                // TCabecera
                $pdf->Cell(200, 11.5, '', 0, 1, 'C');
                $pdf->Cell(200, 3.9, utf8_decode($row['Empresa']), 0, 1, 'C');
                $pdf->Cell(200, 3.9, $row['Domicilio'], 0, 1, 'C');
                $pdf->Cell(200, 3.9, utf8_decode($row['Ciudad']), 0, 1, 'C');
                $pdf->Cell(200, 3.9, $row['RFC'], 0, 1, 'C');
                $pdf->Cell(200, 3.9, '', 0, 1, 'C');
                $pdf->Cell(200, 3.9, 'COMPROBANTE DE COMPRA', 0, 1, 'C');
                
                // Sección de recepción
                $pdf->SetFont('Arial', 'IB', 7);
                $pdf->Cell(200, 3, '', 0, 1, 'C');
                $pdf->Cell(23, 3.6, utf8_decode('Estación'), 0, 0, 'l'); $pdf->Cell(5, 3.6, ':', 0, 0, 'C'); $pdf->Cell(176, 3.6, utf8_decode($row['DocDenominacion'] . ' (' .$row['nropcc']. ')'), 0, 1, 'L');
                $pdf->Cell(23, 3.6, 'Documento ', 0, 0, 'l'); $pdf->Cell(5, 3.6, ':', 0, 0, 'C'); $pdf->Cell(176, 3.6, $row['NroDocumento'], 0, 1, 'L');
                $pdf->Cell(23, 3.6, 'Fecha ', 0, 0, 'l'); $pdf->Cell(5, 3.6, ':', 0, 0, 'C'); $pdf->Cell(176, 3.6, $row['DocFecha'], 0, 1, 'L');
                $pdf->Cell(23, 3.6, 'Turno ', 0, 0, 'l'); $pdf->Cell(5, 3.6, ':', 0, 0, 'C'); $pdf->Cell(176, 3.6, $row['DocTurno'], 0, 1, 'L');
                $pdf->Cell(23, 3.6, 'Proveedor ', 0, 0, 'l'); $pdf->Cell(5, 3.6, ':', 0, 0, 'C'); $pdf->Cell(176, 3.6, $row['Proveedor'], 0, 1, 'L');
                if ((!empty(trim($row['Factura'])))) {
                    $factura = "Factura " . $row['Factura'];
                } else {
                    $factura = "";
                }
                $pdf->Cell(23, 3.6, 'Referencias ', 0, 0, 'l'); $pdf->Cell(5, 3.6, ':', 0, 0, 'C'); $pdf->Cell(176, 3.6, $factura . utf8_decode($row['RemisionVehiculo']), 0, 1, 'L');
                $pdf->Cell(23, 3.6, 'Notas ', 0, 0, 'l'); $pdf->Cell(5, 3.6, ':', 0, 0, 'C'); $pdf->Cell(176, 3.6, '', 0, 1, 'L');

                // Sección de tabla
                $pdf->Cell(200, 3.5, '', 0, 1, 'C');
                $pdf->Cell(40, 3.5, 'Concepto', 'TB', 0, 'L'); $pdf->Cell(63, 3.5, 'Producto', 'TB', 0, 'L'); $pdf->Cell(20, 3.5, 'Cantidad', 'TB', 0, 'L'); $pdf->Cell(20, 3.5, 'Precio', 'TB', 0, 'L'); $pdf->Cell(25, 3.5, 'Importe', 'TB', 0, 'L'); $pdf->Cell(32, 3.5, 'Destino', 'TB', 1, 'L');
                $pdf->SetFont('Arial', '', 7);
                $subtotal = 0;
                $iva_concepto = 0;
                if ($conceptos = $this->Documentos->get_concepts($row['codgas'], $row['Número'])) {
                    foreach ($conceptos as $key => $concepto) {
                        $subtotal += $concepto['Monto'];
                        if (str_contains($concepto['Concepto'], 'IVA')) {
                            $iva_concepto += $concepto['Monto'];
                        }
                        $pdf->Cell(40, 3.5, $concepto['Concepto'], 0, 0, 'L'); $pdf->Cell(63, 3.5, $concepto['Producto'], 0, 0, 'L'); $pdf->Cell(20, 3.5, number_format($concepto['Cantidad'], 3, '.', ','), 0, 0, 'L'); $pdf->Cell(20, 3.5, number_format($concepto['Precio'], 5, '.', ','), 0, 0, 'L'); $pdf->Cell(25, 3.5, number_format($concepto['Monto'], 2, '.', ','), 0, 0, 'L'); $pdf->Cell(32, 3.5, $concepto['Producto'], 0, 1, 'L');
                    }
                }

                $pdf->SetFont('Arial', 'B', 7);
                $pdf->Cell(123, 3.5, 'SUBTOTAL', 'T', 0, 'L'); $pdf->Cell(20, 3.5, '', 'T', 0, 'L'); $pdf->Cell(25, 3.5, number_format(($row['Importe'] + $row['Recargos']), 2, '.', ','), 'T', 0, 'L'); $pdf->Cell(32, 3.5, '', 'T', 1, 'L');
                $pdf->Cell(123, 3.5, 'I.V.A.', 'B', 0, 'L'); $pdf->Cell(20, 3.5, '', 'B', 0, 'L'); $pdf->Cell(25, 3.5, number_format(($row['I.V.A.'] + $iva_concepto), 2, '.', ','), 'B', 0, 'L'); $pdf->Cell(32, 3.5, '', 'B', 1, 'L');
                $pdf->Cell(123, 3.5, 'TOTAL', 'TB', 0, 'L'); $pdf->Cell(20, 3.5, '', 'TB', 0, 'L'); $pdf->Cell(25, 3.5, number_format(($subtotal + $row['I.V.A.']), 2, '.', ','), 'TB', 0, 'L'); $pdf->Cell(32, 3.5, '', 'TB', 1, 'L');
                
                // Espacio
                $pdf->Cell(200, 10, '', 0, 1, 'L');
                $pdf->Cell(33.3, 3.5, utf8_decode('Recepción'), 'TB', 0, 'L'); $pdf->Cell(33.3, 3.5, 'Tanque', 'TB', 0, 'L'); $pdf->Cell(33.3, 3.5, 'Fecha', 'TB', 0, 'L'); $pdf->Cell(33.3, 3.5, 'Hora', 'TB', 0, 'L'); $pdf->Cell(33.3, 3.5, 'Volumen', 'TB', 0, 'L'); $pdf->Cell(33.3, 3.5, 'Aplicado', 'TB', 1, 'L'); 
                if ($receptions = $this->Documentos->get_receptions($row['codgas'], $row['Número'])) {
                    $pdf->SetFont('Arial', '', 7);
                    foreach ($receptions as $key => $rec) {
                        $pdf->Cell(33.3, 3.5, $rec['nrotrn'], 'TB', 0, 'L'); $pdf->Cell(33.3, 3.5, $rec['Tanque'], 'TB', 0, 'L'); $pdf->Cell(33.3, 3.5, $rec['Fecha'], 'TB', 0, 'L'); $pdf->Cell(33.3, 3.5, $rec['hratrn'], 'TB', 0, 'L'); $pdf->Cell(33.3, 3.5, number_format($rec['VolumenRecibido'], 3, '.', ','), 'TB', 0, 'L'); $pdf->Cell(33.3, 3.5, number_format($rec['VolumenRecibido'], 3, '.', ','), 'TB', 1, 'L'); 
                    }
                }

                
                
                $pdf->SetFont('Arial', '', 7);
                $pdf->Cell(40, 10, 'Conformidad Registro', 0, 0, 'L'); $pdf->Cell(5, 10, ':', 0, 0, 'C'); $pdf->Cell(159, 10, $row['LogRegistro'], 0, 1, 'L');
                $pdf->Cell(40, 10, utf8_decode('Conformidad Estación'), 0, 0, 'L'); $pdf->Cell(5, 10, ':', 0, 0, 'C'); $pdf->Cell(159, 10, '', 0, 1, 'L');
                $pdf->Cell(40, 10, 'Conformidad Transportista', 0, 0, 'L'); $pdf->Cell(5, 10, ':', 0, 0, 'C'); $pdf->Cell(159, 10, '', 0, 1, 'L');
                
                // AGREGAR PIE DE PÁGINA MANUALMENTE
                // Guardar posición actual
                $currentY = $pdf->GetY();
                
                // Mover al final de la página (10mm desde el borde inferior)
                $pdf->SetY(-18);
                
                // Configurar fuente para el pie
                $pdf->SetFont('Arial', 'I', 7);
                $pdf->Cell(200, 1, '', 'B', 1, 'L');
                // Agregar los textos del pie
                $pdf->Cell(100, 5, 'Generado por Aplicativo TotalGas | ' . date('d/m/Y H:i:s'), 0, 0, 'L');
                $pdf->Cell(100, 5, utf8_decode('Página ') . $pageNumber, 0, 0, 'R');
                
                // Restaurar la posición Y para el siguiente documento (si lo hay)
                $pdf->SetY($currentY);
            }
            
            // Salida del PDF
            $pdf->Output();
        } else {
            // Manejar el caso cuando no hay datos
            echo '<pre>';
            var_dump("Algo malio sal");
            die();
        }
    }


    function print_purchase_receipts2($folios, $codgas) {

        // 1️⃣ Quitar espacios en blanco alrededor de todo
        $folios = trim($folios);

        // 2️⃣ Reemplazar comas dobles o triples por una sola
        $folios = preg_replace('/,+/', ',', $folios);

        // 3️⃣ Separar por comas
        $foliosArray = explode(',', $folios);

        // 4️⃣ Eliminar elementos vacíos y espacios extra
        $foliosArray = array_filter(array_map('trim', $foliosArray), 'strlen');

        // 5️⃣ (Opcional) Eliminar duplicados
        $foliosArray = array_unique($foliosArray);

        // 6️⃣ (Opcional) Reordenar si querés que queden ordenados numéricamente
        sort($foliosArray, SORT_NUMERIC);

        // 7️⃣ Si necesitás devolverlo como string limpio:
        $foliosLimpio = implode(',', $foliosArray);

        if ($rows = $this->Documentos->movement_analysis_table2($foliosLimpio, $codgas)) {
            // Crear una instancia de FPDF
            $pdf = new PDF_Code128();
            
            // Establecer los márgenes
            $pdf->SetMargins(5, 5, 5);  // Margen izquierdo, margen superior, margen derecho
            
            // Establecer el margen inferior
            $pdf->SetAutoPageBreak(true, 12);  // Aumentado a 12 mm para el footer
            
            $pageNumber = 0; // Contador de páginas
            
            foreach ($rows as $key => $row) {
                // Agregar página en formato horizontal de 85x54mm (tamaño tarjeta)
                $pdf->AddPage('P');
                $pageNumber++; // Incrementar contador
                
                // Configurar fuente para el encabezado
                $pdf->SetFont('Arial', 'B', 9);
                
                // TCabecera
                $pdf->Cell(200, 11.5, '', 0, 1, 'C');
                $pdf->Cell(200, 3.9, utf8_decode($row['Empresa']), 0, 1, 'C');
                $pdf->Cell(200, 3.9, $row['Domicilio'], 0, 1, 'C');
                $pdf->Cell(200, 3.9, utf8_decode($row['Ciudad']), 0, 1, 'C');
                $pdf->Cell(200, 3.9, $row['RFC'], 0, 1, 'C');
                $pdf->Cell(200, 3.9, '', 0, 1, 'C');
                $pdf->Cell(200, 3.9, 'COMPROBANTE DE COMPRA', 0, 1, 'C');
                
                // Sección de recepción
                $pdf->SetFont('Arial', 'IB', 7);
                $pdf->Cell(200, 3, '', 0, 1, 'C');
                $pdf->Cell(23, 3.6, utf8_decode('Estación'), 0, 0, 'l'); $pdf->Cell(5, 3.6, ':', 0, 0, 'C'); $pdf->Cell(176, 3.6, utf8_decode($row['DocDenominacion'] . ' (' .$row['nropcc']. ')'), 0, 1, 'L');
                $pdf->Cell(23, 3.6, 'Documento ', 0, 0, 'l'); $pdf->Cell(5, 3.6, ':', 0, 0, 'C'); $pdf->Cell(176, 3.6, $row['NroDocumento'], 0, 1, 'L');
                $pdf->Cell(23, 3.6, 'Fecha ', 0, 0, 'l'); $pdf->Cell(5, 3.6, ':', 0, 0, 'C'); $pdf->Cell(176, 3.6, $row['DocFecha'], 0, 1, 'L');
                $pdf->Cell(23, 3.6, 'Turno ', 0, 0, 'l'); $pdf->Cell(5, 3.6, ':', 0, 0, 'C'); $pdf->Cell(176, 3.6, $row['DocTurno'], 0, 1, 'L');
                $pdf->Cell(23, 3.6, 'Proveedor ', 0, 0, 'l'); $pdf->Cell(5, 3.6, ':', 0, 0, 'C'); $pdf->Cell(176, 3.6, $row['Proveedor'], 0, 1, 'L');
                if ((!empty(trim($row['Factura'])))) {
                    $factura = "Factura " . $row['Factura'];
                } else {
                    $factura = "";
                }
                $pdf->Cell(23, 3.6, 'Referencias ', 0, 0, 'l'); $pdf->Cell(5, 3.6, ':', 0, 0, 'C'); $pdf->Cell(176, 3.6, $factura . utf8_decode($row['RemisionVehiculo']), 0, 1, 'L');
                $pdf->Cell(23, 3.6, 'Notas ', 0, 0, 'l'); $pdf->Cell(5, 3.6, ':', 0, 0, 'C'); $pdf->Cell(176, 3.6, '', 0, 1, 'L');

                // Sección de tabla
                $pdf->Cell(200, 3.5, '', 0, 1, 'C');
                $pdf->Cell(40, 3.5, 'Concepto', 'TB', 0, 'L'); $pdf->Cell(63, 3.5, 'Producto', 'TB', 0, 'L'); $pdf->Cell(20, 3.5, 'Cantidad', 'TB', 0, 'L'); $pdf->Cell(20, 3.5, 'Precio', 'TB', 0, 'L'); $pdf->Cell(25, 3.5, 'Importe', 'TB', 0, 'L'); $pdf->Cell(32, 3.5, 'Destino', 'TB', 1, 'L');
                $pdf->SetFont('Arial', '', 7);
                $subtotal = 0;
                $iva_concepto = 0;
                if ($conceptos = $this->Documentos->get_concepts($row['codgas'], $row['Número'])) {
                    foreach ($conceptos as $key => $concepto) {
                        $subtotal += $concepto['Monto'];
                        if (str_contains($concepto['Concepto'], 'IVA')) {
                            $iva_concepto += $concepto['Monto'];
                        }
                        $pdf->Cell(40, 3.5, $concepto['Concepto'], 0, 0, 'L'); $pdf->Cell(63, 3.5, $concepto['Producto'], 0, 0, 'L'); $pdf->Cell(20, 3.5, number_format($concepto['Cantidad'], 3, '.', ','), 0, 0, 'L'); $pdf->Cell(20, 3.5, number_format($concepto['Precio'], 5, '.', ','), 0, 0, 'L'); $pdf->Cell(25, 3.5, number_format($concepto['Monto'], 2, '.', ','), 0, 0, 'L'); $pdf->Cell(32, 3.5, $concepto['Producto'], 0, 1, 'L');
                    }
                }

                $pdf->SetFont('Arial', 'B', 7);
                $pdf->Cell(123, 3.5, 'SUBTOTAL', 'T', 0, 'L'); $pdf->Cell(20, 3.5, '', 'T', 0, 'L'); $pdf->Cell(25, 3.5, number_format(($row['Importe'] + $row['Recargos']), 2, '.', ','), 'T', 0, 'L'); $pdf->Cell(32, 3.5, '', 'T', 1, 'L');
                $pdf->Cell(123, 3.5, 'I.V.A.', 'B', 0, 'L'); $pdf->Cell(20, 3.5, '', 'B', 0, 'L'); $pdf->Cell(25, 3.5, number_format(($row['I.V.A.'] + $iva_concepto), 2, '.', ','), 'B', 0, 'L'); $pdf->Cell(32, 3.5, '', 'B', 1, 'L');
                $pdf->Cell(123, 3.5, 'TOTAL', 'TB', 0, 'L'); $pdf->Cell(20, 3.5, '', 'TB', 0, 'L'); $pdf->Cell(25, 3.5, number_format(($subtotal + $row['I.V.A.']), 2, '.', ','), 'TB', 0, 'L'); $pdf->Cell(32, 3.5, '', 'TB', 1, 'L');
                
                // Espacio
                $pdf->Cell(200, 10, '', 0, 1, 'L');
                $pdf->Cell(33.3, 3.5, utf8_decode('Recepción'), 'TB', 0, 'L'); $pdf->Cell(33.3, 3.5, 'Tanque', 'TB', 0, 'L'); $pdf->Cell(33.3, 3.5, 'Fecha', 'TB', 0, 'L'); $pdf->Cell(33.3, 3.5, 'Hora', 'TB', 0, 'L'); $pdf->Cell(33.3, 3.5, 'Volumen', 'TB', 0, 'L'); $pdf->Cell(33.3, 3.5, 'Aplicado', 'TB', 1, 'L'); 
                if ($receptions = $this->Documentos->get_receptions($row['codgas'], $row['Número'])) {
                    $pdf->SetFont('Arial', '', 7);
                    foreach ($receptions as $key => $rec) {
                        $pdf->Cell(33.3, 3.5, $rec['nrotrn'], 'TB', 0, 'L'); $pdf->Cell(33.3, 3.5, $rec['Tanque'], 'TB', 0, 'L'); $pdf->Cell(33.3, 3.5, $rec['Fecha'], 'TB', 0, 'L'); $pdf->Cell(33.3, 3.5, $rec['hratrn'], 'TB', 0, 'L'); $pdf->Cell(33.3, 3.5, number_format($rec['VolumenRecibido'], 3, '.', ','), 'TB', 0, 'L'); $pdf->Cell(33.3, 3.5, number_format($rec['VolumenRecibido'], 3, '.', ','), 'TB', 1, 'L'); 
                    }
                }

                
                
                $pdf->SetFont('Arial', '', 7);
                $pdf->Cell(40, 10, 'Conformidad Registro', 0, 0, 'L'); $pdf->Cell(5, 10, ':', 0, 0, 'C'); $pdf->Cell(159, 10, $row['LogRegistro'], 0, 1, 'L');
                $pdf->Cell(40, 10, utf8_decode('Conformidad Estación'), 0, 0, 'L'); $pdf->Cell(5, 10, ':', 0, 0, 'C'); $pdf->Cell(159, 10, '', 0, 1, 'L');
                $pdf->Cell(40, 10, 'Conformidad Transportista', 0, 0, 'L'); $pdf->Cell(5, 10, ':', 0, 0, 'C'); $pdf->Cell(159, 10, '', 0, 1, 'L');
                
                // AGREGAR PIE DE PÁGINA MANUALMENTE
                // Guardar posición actual
                $currentY = $pdf->GetY();
                
                // Mover al final de la página (10mm desde el borde inferior)
                $pdf->SetY(-18);
                
                // Configurar fuente para el pie
                $pdf->SetFont('Arial', 'I', 7);
                $pdf->Cell(200, 1, '', 'B', 1, 'L');
                // Agregar los textos del pie
                $pdf->Cell(100, 5, 'Generado por Aplicativo TotalGas | ' . date('d/m/Y H:i:s'), 0, 0, 'L');
                $pdf->Cell(100, 5, utf8_decode('Página ') . $pageNumber, 0, 0, 'R');
                
                // Restaurar la posición Y para el siguiente documento (si lo hay)
                $pdf->SetY($currentY);
            }
            
            // Salida del PDF
            $pdf->Output();
        } else {
            // Manejar el caso cuando no hay datos
            echo '<pre>';
            var_dump("Algo malio sal");
            die();
        }
    }

    function purchases_vs_receptions() {
        if (preg_match('/GET/i',$_SERVER['REQUEST_METHOD'])){
            echo $this->twig->render($this->route . 'purchases_vs_receptions.html');
        } else {
            // Aumentar límite de memoria
            ini_set('memory_limit', '1024M');
            
            try {
                // 1. Verificar que se haya subido un archivo
                if (!isset($_FILES['excel']) || $_FILES['excel']['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception('No se ha subido ningún archivo o hubo un error en la carga.');
                }

                $file = $_FILES['excel'];

                // 2. Verificar que sea un archivo Excel (.xlsx)
                $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if ($fileExtension !== 'xlsx') {
                    throw new Exception('El archivo debe ser de formato .xlsx');
                }

                // 3. Crear lector con configuración para lectura eficiente
                $reader = new XlsxReader();
                
                // Configurar para leer solo datos (sin formato, sin imágenes, etc.)
                $reader->setReadDataOnly(true);
                
                // 4. Cargar el archivo Excel
                $spreadsheet = $reader->load($file['tmp_name']);
                
                // 5. Obtener la primera hoja
                $worksheet = $spreadsheet->getActiveSheet();
                
                // 6. Obtener los encabezados (primera fila)
                $headers = [];
                $highestColumn = $worksheet->getHighestColumn();
                $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);
                
                // Leer encabezados
                for ($col = 1; $col <= $highestColumnIndex; $col++) {
                    $columnLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
                    $headers[] = trim($worksheet->getCell($columnLetter . '1')->getValue());
                }

                // 7. Verificar que exista la columna UUID
                $uuidColumnIndex = array_search('UUID', $headers);
                
                if ($uuidColumnIndex === false) {
                    throw new Exception('El archivo no contiene la columna "UUID".');
                }

                // Convertir índice a letra de columna (A, B, C, etc.)
                $uuidColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($uuidColumnIndex + 1);
                
                // 8. Obtener todos los valores de la columna UUID
                $uuids = [];
                $highestRow = $worksheet->getHighestRow();
                
                for ($row = 2; $row <= $highestRow; $row++) {
                    $uuid = trim($worksheet->getCell($uuidColumn . $row)->getValue());
                    
                    // Solo agregar si no está vacío
                    if (!empty($uuid)) {
                        $uuids[] = $uuid;
                    }
                    
                    // Liberar memoria cada 1000 filas
                    if ($row % 1000 == 0) {
                        $worksheet->garbageCollect();
                    }
                }

                // 9. Liberar memoria
                $spreadsheet->disconnectWorksheets();
                unset($spreadsheet);

                // 10. Verificar que se hayan encontrado UUIDs
                if (empty($uuids)) {
                    throw new Exception('No se encontraron UUIDs en el archivo.');
                }

                // 11. Buscar registros en la base de datos
                $uuidsCadena = "'" . implode("','", $uuids) . "'";

                $data = [];
                if ($resultados = $this->movimientosTanModel->buscarPorUUID($uuidsCadena)) {
                    foreach ($resultados as $key => $row) {
                        $data[] = array(
                            'proveedor'        => $row['Proveedor'],
                            'estacion'         => $row['Estacion'],
                            'factura'          => str_replace(':', '', $row['Factura']),
                            'remision'         => str_replace(':', '', $row['Remision']),
                            'documento'        => $row['Documento'],
                            'uuid'             => $row['satuid'],
                            'fecha'            => ($row['Fecha'] . ' (' . $row['fch'] . ')'),
                            'volumen_recibido' => floatval($row['VolRecibido']),
                        );
                    }
                }

                // 12. Retornar JSON
                json_output(array("data" => $data));

            } catch (Exception $e) {
                // Retornar error en formato JSON
                http_response_code(400);
                json_output(array("error" => $e->getMessage()));
            }
        }
    }

}
