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
class Accounting{
    public $twig;
    public $route;
    public XmlCreModel $xmlCreModel;
    public FacturasModel $facturas;
    public DocumentosModel $Documentos;
    public EstacionesModel $estacionesModel;

    /**
     * @param $twig
     */
    public function __construct($twig) {
        $this->twig         = $twig;
        $this->route        = 'views/accounting/';
        $this->xmlCreModel  = new XmlCreModel();
        $this->facturas     = new FacturasModel();
        $this->Documentos     = new DocumentosModel();
        $this->estacionesModel = new EstacionesModel();
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

    public function income_statement() : void {
        if (preg_match('/GET/i',$_SERVER['REQUEST_METHOD'])){
            echo $this->twig->render($this->route . 'income_statement.html');
        }
    }

   

    public function tax_stimulus() : void {
        // $pending_volumetrics = $this->xmlCreModel->get_pendings();
        // foreach ($pending_volumetrics as $item) {
        //     $this->getXmlFromPath($item['RutaVolumetricos'], str_replace('/', '_', $item['PermisoCRE']), date('Ymd', strtotime($item['Fecha'])));
        // }
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
                $data[] = array(
                    'cveest' => $est['cveest'],
                    'station' => trim($est['Estacion']),
                    'tax_date' => date("Y-m-d", strtotime($est['Fecha'])),
                    'nropcc' => $est['PermisoCRE'],
                    'product' => trim($est['Producto']),
                    'Cve_Producto' => $est['CveProducto'],
                    'less150' => number_format($est['Menores'], 3),
                    'more150' => number_format($est['Mayores'], 3),
                    'consumes' => number_format($est['Internos'], 3),
                    'calibration' => number_format($est['Jarreos'], 3),
                    'dues' => number_format($est['IEPS'], 2),
                    'volume' => $est['Volumen'],
                    'volume_controlgas' => (is_null($est['VolumenVolumetrico']) ? 0 : $est['VolumenVolumetrico']),
                    'difference' => $est['Variacion'],
                    'amount' => ($est['IEPS'] * $est['Menores']),
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
                    'num_factura_OG'            => $row['num_factura_OG'],
                    'Numero_pago_OG'            => $row['Numero_pago_OG'],
                );
            }
            $data = array("data" => $data);
            echo json_encode($data);
        } else {
            echo json_encode(["data" => []]); // Devuelve un array vacío si no hay datos
        }
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
    

}