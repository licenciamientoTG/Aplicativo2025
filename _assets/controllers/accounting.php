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
    public GasolinerasModel $gasolinerasModel;
    public ProveedoresModel $proveedores;
    public XmlVsVentasModel $xmlVsVentasModel;
    public RenegociacionModel $RenegociacionModel;
    public RenegContactosModel $RenegContactosModel;
    public RenegEmailLogModel $RenegEmailLogModel;
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
        $this->gasolinerasModel       = new GasolinerasModel;
        $this->proveedores            = new ProveedoresModel();
        $this->xmlVsVentasModel       = new XmlVsVentasModel();
        $this->RenegociacionModel     = new RenegociacionModel();
        $this->RenegContactosModel    = new RenegContactosModel();
        $this->RenegEmailLogModel     = new RenegEmailLogModel();
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
     public function analysis_movement() : void {
        if (preg_match('/GET/i',$_SERVER['REQUEST_METHOD'])){
            echo $this->twig->render($this->route . 'analysis_movement.html');
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
                    'Descripcion'               => $invoice['Descripcion'],
                    'FormaPago'               => $invoice['FormaPago'],
                );
            }
        }
        json_output(array("data" => $data));
    }

    /**
     * Obtiene todas las facturas de la tabla Documentos para DataTables
     * Soporta filtrado por fechas y estación
     *
     * @return void
     */
    public function documentos_facturas_table(): void {
        if (!preg_match('/POST/i', $_SERVER['REQUEST_METHOD'])) {
            json_output(['error' => 'Método no permitido']);
            return;
        }
        
        ini_set('memory_limit', '512M');
        ini_set('max_execution_time', 300);

        $from = $_POST['from'] ?? null;
        $until = $_POST['until'] ?? null;
        $codgas = isset($_POST['codgas']) && $_POST['codgas'] !== '' ? (int)$_POST['codgas'] : null;
        $tipo_factura = $_POST['tipo_factura'] ?? null;

        try {
            // 🎯 Datos salen formateados directamente del modelo
            $facturas = $this->Documentos->get_all_facturas($from, $until, $codgas, $tipo_factura);
            json_output(['data' => $facturas ?: []]);
        } catch (Exception $e) {
            error_log("Error en documentos_facturas_table: " . $e->getMessage());
            json_output(['error' => 'Error al obtener las facturas', 'data' => []]);
        }
    }

    /**
     * Vista para mostrar el listado de facturas de Documentos
     *
     * @return void
     */
    public function documentos_facturas() {
        if (preg_match('/GET/i', $_SERVER['REQUEST_METHOD'])) {
            $first_date = date('Y-m-01'); // Primer día del mes actual
            $last_date = date('Y-m-d');   // Fecha actual
            $estaciones = $this->estacionesModel->get_select_stations() ?: [];
            echo $this->twig->render($this->route . 'documentos_facturas.html', compact('first_date', 'last_date', 'estaciones'));
        }
    }
    public function invoice_purchase() {
        if (preg_match('/GET/i', $_SERVER['REQUEST_METHOD'])) {
            $estaciones = $this->estacionesModel->get_select_stations() ?: [];
            $all_stations = $this->gasolinerasModel->get_stations();
    
            // Filtrar estaciones para quitar la que tiene cod = 0
            $stations = array_filter($all_stations, function($station) {
                return $station['cod'] != 0; // o !== '0' si cod es string
            });

            $companys = $this->gasolinerasModel->get_company();
            $proveedores = $this->proveedores->get_actives();
            echo $this->twig->render($this->route . 'invoice_purchase.html', compact('stations', 'companys', 'proveedores'));
        }
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
        $apiData = $this->_callApi('http://192.168.0.109:82/api/concentrado-resultados/', $postData);        

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
        $apiData = $this->_callApi('http://192.168.0.109:82/api/concentrado-anual/', $postData);

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
        $apiData = $this->_callApi('http://192.168.0.109:82/api/get_er_budget/', $postData);

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

        $apiData = $this->_callApi('http://192.168.0.3:388/api/pagos/get_pagos', $postData);
      
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
    

    

    public function getFileErrorMessage($errorCode = 0): string
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

        $apiData = $this->_callApi('http://192.168.0.109:82/api/er_petrotal/', $postData);

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
    public function xmlCre(){
        ini_set('max_execution_time', 5000);
        ini_set('memory_limit', '1024M');
        set_time_limit(0);
        header('Content-Type: application/json');
        
        $postData = [
            'codgas' => $_POST['codgas']
        ];
        
        $apiData = $this->_callApi('http://192.168.0.109:82/api/xmlCre/', $postData);
        
        $badges = [
            'XML_mensual' => '<span class="badge bg-primary">XML Mensual</span>',
            'DB_despachos' => '<span class="badge bg-success">Base de Datos</span>',
            'XML_diarios_consolidado' => '<span class="badge bg-info">XML Diarios</span>'
        ];
        
        // Mapeo de productos
        $mapeoProductos = [
            '07-1' => 'T-Maxima Regular',
            '07-2' => 'T-Super Premium',
            '03-3' => 'Diesel Automotriz'
        ];
        
        $data = [];
        $dataOriginal = []; // Mantener datos originales para los collapses
        
        if ($apiData['success'] == true) {
            // Procesar XML Mensual
            $dataOriginal['mensual'] = $apiData['mensual'];
            foreach ($apiData['mensual'] as $row) {
                $data[] = [
                    'Origen'                                => $badges['XML_mensual'],
                    'OrigenRaw'                             => 'XML_mensual',
                    'archivo'                               => $row['archivo'] ?? 'N/A',
                    'Estación'                              => $row['Estación'] ?? 'N/A',
                    'FechaYHoraReporteMes'                  => $row['FechaYHoraReporteMes'] ?? 'N/A',
                    'MarcaComercial'                        => $row['MarcaComercial'] ?? 'N/A',
                    'TotalEntregasMes'                      => $row['TotalEntregasMes'] ?? 0,
                    'SumaVolumenEntregadoMes_ValorNumerico' => $row['SumaVolumenEntregadoMes_ValorNumerico'] ?? 0,
                    'TotalDocumentosMes'                    => $row['TotalDocumentosMes'] ?? 0,
                    'ImporteTotalEntregasMes'               => $row['ImporteTotalEntregasMes'] ?? 0,
                    'SumaVolumenCFDIs'                      => $row['SumaVolumenCFDIs'] ?? 0,
                ];
            }
            
            // Procesar Despachos
            $dataOriginal['despachos'] = $apiData['despachos'];
            foreach ($apiData['despachos'] as $row) {
                $data[] = [
                    'Origen'                                => $badges['DB_despachos'],
                    'OrigenRaw'                             => 'DB_despachos',
                    'archivo'                               => 'N/A',
                    'Estación'                              => $row['Estación'] ?? 'N/A',
                    'FechaYHoraReporteMes'                  => 'N/A',
                    'MarcaComercial'                        => $row['MarcaComercial'] ?? $row['Producto'] ?? 'N/A',
                    'TotalEntregasMes'                      => $row['TotalEntregasMes'] ?? 0,
                    'SumaVolumenEntregadoMes_ValorNumerico' => $row['SumaVolumenEntregadoMes_ValorNumerico'] ?? 0,
                    'TotalDocumentosMes'                    => $row['TotalDocumentosMes'] ?? 0,
                    'ImporteTotalEntregasMes'               => $row['ImporteTotalEntregasMes'] ?? 0,
                    'SumaVolumenCFDIs'                      => $row['SumaVolumenCFDIs'] ?? 0,
                ];
            }
            
            // Procesar Diarios - AQUÍ ESTABA EL ERROR
            $dataOriginal['diarios'] = $apiData['diarios'];
            if (isset($apiData['diarios']['datos']) && is_array($apiData['diarios']['datos'])) {
                foreach ($apiData['diarios']['datos'] as $row) {
                    $claveProducto = $row['ClaveProducto'] ?? '';
                    $claveSubProducto = $row['ClaveSubProducto'] ?? '';
                    $claveCompleta = $claveProducto . '-' . $claveSubProducto;
                    $nombreProducto = $mapeoProductos[$claveCompleta] ?? 'Desconocido';
                    
                    $data[] = [
                        'Origen'                                => $badges['XML_diarios_consolidado'],
                        'OrigenRaw'                             => 'XML_diarios_consolidado',
                        'archivo'                               => 'N/A',
                        'Estación'                              => $row['Estación'] ?? 'N/A',
                        'FechaYHoraReporteMes'                  => 'N/A',
                        'MarcaComercial'                        => $nombreProducto,
                        'TotalEntregasMes'                      => $row['TotalTransaccionesMes'] ?? 0,
                        'SumaVolumenEntregadoMes_ValorNumerico' => $row['VolumenTotalMes'] ?? 0,
                        'TotalDocumentosMes'                    => $row['TotalTransaccionesMes'] ?? 0,
                        'ImporteTotalEntregasMes'               => $row['ImporteTotalMes'] ?? 0,
                        'SumaVolumenCFDIs'                      => 0, // Los diarios no tienen este campo
                    ];
                }
            }
        }
        
        $response = [
            "success" => $apiData['success'] ?? false,
            "periodo" => $apiData['periodo'] ?? null,
            "data" => $data,
            "dataOriginal" => $dataOriginal // Para usar en los collapses
        ];
        
        echo json_encode($response);
    }

    public function er_petrotal_concept(){
        ini_set('max_execution_time', 5000);
        ini_set('memory_limit', '1024M');
        set_time_limit(0);
        header('Content-Type: application/json');
        $postData = [
            'date' => $_POST['date'] // Usar la fecha del POST o una por defecto
        ];
        
        $apiData = $this->_callApi('http://192.168.0.109:82/api/er_petrotal_concept/', $postData);

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

        $from     = dateToInt($_POST['fromDate']);
        $until    = dateToInt($_POST['untilDate']);
        $codgas   = $_POST['codgas'];
        $supplier = $_POST['supplier'];

        $rows = $this->Documentos->movement_analysis_table($from, $until, $codgas, $supplier);

        $data = [];
        foreach ((array)$rows as $row) {
            $data[] = [
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
                'IVA'             => $row['I.V.A.'] + $row['iva_concepto'],
                'Recargos'        => $row['Recargos'],
                'TotalFactura'    => $row['TotalFactura'],
                'Estación'        => $row['Estación'],
                'UUID'            => $row['UUID'],
                'RFC'             => $row['RFC'],
                'Remision'        => $row['Remision'],
                'Vehiculo'        => $row['Vehiculo'],
                'Proveedor'       => $row['Proveedor'],
            ];
        }
        echo json_encode(["data" => $data]);
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

    function facturas_analysis_table() {
        set_time_limit(280);
        header('Content-Type: application/json');

        $facturas = $_POST['facturas'];

        // 1️⃣ Quitar espacios en blanco alrededor de todo
        $facturas = trim($facturas);

        // 2️⃣ Reemplazar comas dobles o triples por una sola
        $facturas = preg_replace('/,+/', ',', $facturas);

        // 3️⃣ Separar por comas
        $facturasArray = explode(',', $facturas);

        // 4️⃣ Eliminar elementos vacíos y espacios extra
        $facturasArray = array_filter(array_map('trim', $facturasArray), 'strlen');

        // 5️⃣ (Opcional) Eliminar duplicados
        $facturasArray = array_unique($facturasArray);

        // 6️⃣ (Opcional) Reordenar si querés que queden ordenados numéricamente
        sort($facturasArray, SORT_NUMERIC);

        // 7️⃣ Agregar comillas simples a cada elemento y unir
        $facturasLimpio = "'" . implode("','", $facturasArray) . "'";

        $data = [];
        if ($rows = $this->Documentos->movement_analysis_table4($facturasLimpio)) {
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
            // $suppliers = $this->Documentos->get_suppliers();
            $suppliers = $this->proveedores->get_rows();
            $codgas = $_GET['station'] ?? 0 ;
            $supplier = $_GET['supplier'] ?? 0 ;
            $stations = $this->estacionesModel->get_select_stations();
            echo $this->twig->render($this->route . 'movement_analysis.html', compact('from','until','stations','codgas','suppliers','supplier'));
        }
    }


    function print_purchase_receipts($from, $until, $codgas = 0, $supplier = 0) {
        $rows = $this->Documentos->movement_analysis_table(dateToInt($from), dateToInt($until), $codgas, $supplier);

        // --- Batch preload: evita N×2 queries individuales ---
        $pairs = array_map(fn($r) => ['codgas' => $r['codgas'], 'nro' => $r['Número']], $rows);

        // Indexar conceptos por "codgas-nro"
        $conceptosIdx = [];
        foreach ($this->Documentos->get_concepts_batch($pairs) as $c) {
            $conceptosIdx["{$c['codgas']}-{$c['nro']}"][] = $c;
        }

        // Indexar recepciones por "codgas-nro", priorizando tiptrn=3 sobre tiptrn=4
        $recepcionesPorTipo = [];
        foreach ($this->Documentos->get_receptions_batch($pairs) as $r) {
            $recepcionesPorTipo["{$r['codgas']}-{$r['nro']}"][$r['tiptrn']][] = $r;
        }
        $recepcionesIdx = [];
        foreach ($recepcionesPorTipo as $k => $porTipo) {
            $recepcionesIdx[$k] = $porTipo[3] ?? $porTipo[4] ?? [];
        }

        // Cadena estática convertida una sola vez fuera del loop
        $labelRecepcion = iconv('UTF-8', 'ISO-8859-1//TRANSLIT', 'Recepción');
        $labelEstConformidad = iconv('UTF-8', 'ISO-8859-1//TRANSLIT', 'Conformidad Estación');

        $pdf = new PDF_Code128();
        $pdf->SetMargins(5, 5, 5);
        $pdf->SetAutoPageBreak(true, 12);

        foreach ($rows as $row) {
            $pdf->AddPage('P');
            $pdf->SetFont('Arial', 'B', 9);

            $pdf->Cell(200, 11.5, '', 0, 1, 'C');
            $pdf->Cell(200, 3.9, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $row['Empresa']), 0, 1, 'C');
            $pdf->Cell(200, 3.9, $row['Domicilio'], 0, 1, 'C');
            $pdf->Cell(200, 3.9, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $row['Ciudad']), 0, 1, 'C');
            $pdf->Cell(200, 3.9, $row['RFC'], 0, 1, 'C');
            $pdf->Cell(200, 3.9, '', 0, 1, 'C');
            $pdf->Cell(200, 3.9, 'COMPROBANTE DE COMPRA', 0, 1, 'C');

            $pdf->SetFont('Arial', 'IB', 7);
            $pdf->Cell(200, 3, '', 0, 1, 'C');
            $pdf->Cell(23, 3.6, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $row['Estación']), 0, 0, 'l');
            $pdf->Cell(5, 3.6, ':', 0, 0, 'C');
            $pdf->Cell(176, 3.6, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $row['DocDenominacion'] . ' (' . $row['nropcc'] . ')'), 0, 1, 'L');
            $pdf->Cell(23, 3.6, 'Documento ', 0, 0, 'l'); $pdf->Cell(5, 3.6, ':', 0, 0, 'C'); $pdf->Cell(176, 3.6, $row['NroDocumento'], 0, 1, 'L');
            $pdf->Cell(23, 3.6, 'Fecha ', 0, 0, 'l'); $pdf->Cell(5, 3.6, ':', 0, 0, 'C'); $pdf->Cell(176, 3.6, $row['DocFecha'], 0, 1, 'L');
            $pdf->Cell(23, 3.6, 'Turno ', 0, 0, 'l'); $pdf->Cell(5, 3.6, ':', 0, 0, 'C'); $pdf->Cell(176, 3.6, $row['DocTurno'], 0, 1, 'L');
            $pdf->Cell(23, 3.6, 'Proveedor ', 0, 0, 'l'); $pdf->Cell(5, 3.6, ':', 0, 0, 'C'); $pdf->Cell(176, 3.6, $row['Proveedor'], 0, 1, 'L');

            $factura = !empty(trim($row['Factura'])) ? 'Factura ' . $row['Factura'] : '';
            $referencias = $factura . ' ' . iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $row['RemisionVehiculo']);
            $pdf->Cell(23, 3.6, 'Referencias ', 0, 0, 'l'); $pdf->Cell(5, 3.6, ':', 0, 0, 'C'); $pdf->Cell(176, 3.6, $referencias, 0, 1, 'L');
            $pdf->Cell(23, 3.6, 'Notas ', 0, 0, 'l'); $pdf->Cell(5, 3.6, ':', 0, 0, 'C'); $pdf->Cell(176, 3.6, '', 0, 1, 'L');

            // Sección de conceptos
            $pdf->Cell(200, 3.5, '', 0, 1, 'C');
            $pdf->Cell(40, 3.5, 'Concepto', 'TB', 0, 'L'); $pdf->Cell(63, 3.5, 'Producto', 'TB', 0, 'L'); $pdf->Cell(20, 3.5, 'Cantidad', 'TB', 0, 'L'); $pdf->Cell(20, 3.5, 'Precio', 'TB', 0, 'L'); $pdf->Cell(25, 3.5, 'Importe', 'TB', 0, 'L'); $pdf->Cell(32, 3.5, 'Destino', 'TB', 1, 'L');
            $pdf->SetFont('Arial', '', 7);
            $subtotal = 0;
            $iva_concepto = 0;
            $conceptos = $conceptosIdx["{$row['codgas']}-{$row['Número']}"] ?? [];
            foreach ($conceptos as $concepto) {
                $subtotal += $concepto['Monto'];
                if (str_contains($concepto['Concepto'], 'IVA')) {
                    $iva_concepto += $concepto['Monto'];
                }
                $pdf->Cell(40, 3.5, $concepto['Concepto'], 0, 0, 'L');
                $pdf->Cell(63, 3.5, $concepto['Producto'], 0, 0, 'L');
                $pdf->Cell(20, 3.5, number_format($concepto['Cantidad'] ?? 0, 3, '.', ','), 0, 0, 'L');
                $pdf->Cell(20, 3.5, number_format($concepto['Precio'] ?? 0, 5, '.', ','), 0, 0, 'L');
                $pdf->Cell(25, 3.5, number_format($concepto['Monto'] ?? 0, 2, '.', ','), 0, 0, 'L');
                $pdf->Cell(32, 3.5, $concepto['Producto'], 0, 1, 'L');
            }

            $pdf->SetFont('Arial', 'B', 7);
            $pdf->Cell(123, 3.5, 'SUBTOTAL', 'T', 0, 'L'); $pdf->Cell(20, 3.5, '', 'T', 0, 'L'); $pdf->Cell(25, 3.5, number_format(($row['Importe'] + $row['Recargos']), 2, '.', ','), 'T', 0, 'L'); $pdf->Cell(32, 3.5, '', 'T', 1, 'L');
            $pdf->Cell(123, 3.5, 'I.V.A.', 'B', 0, 'L'); $pdf->Cell(20, 3.5, '', 'B', 0, 'L'); $pdf->Cell(25, 3.5, number_format(($row['I.V.A.'] + $iva_concepto), 2, '.', ','), 'B', 0, 'L'); $pdf->Cell(32, 3.5, '', 'B', 1, 'L');
            $pdf->Cell(123, 3.5, 'TOTAL', 'TB', 0, 'L'); $pdf->Cell(20, 3.5, '', 'TB', 0, 'L'); $pdf->Cell(25, 3.5, number_format(($subtotal + $row['I.V.A.']), 2, '.', ','), 'TB', 0, 'L'); $pdf->Cell(32, 3.5, '', 'TB', 1, 'L');

            // Sección de recepciones
            $pdf->Cell(200, 10, '', 0, 1, 'L');
            $pdf->Cell(33.3, 3.5, $labelRecepcion, 'TB', 0, 'L');
            $pdf->Cell(33.3, 3.5, 'Tanque', 'TB', 0, 'L');
            $pdf->Cell(33.3, 3.5, 'Fecha', 'TB', 0, 'L');
            $pdf->Cell(33.3, 3.5, 'Hora', 'TB', 0, 'L');
            $pdf->Cell(33.3, 3.5, 'Volumen', 'TB', 0, 'L');
            $pdf->Cell(33.3, 3.5, 'Aplicado', 'TB', 1, 'L');
            $receptions = $recepcionesIdx["{$row['codgas']}-{$row['Número']}"] ?? [];
            if ($receptions) {
                $pdf->SetFont('Arial', '', 7);
                foreach ($receptions as $rec) {
                    $pdf->Cell(33.3, 3.5, $rec['nrotrn'], 'TB', 0, 'L'); $pdf->Cell(33.3, 3.5, $rec['Tanque'], 'TB', 0, 'L'); $pdf->Cell(33.3, 3.5, $rec['Fecha'], 'TB', 0, 'L'); $pdf->Cell(33.3, 3.5, $rec['hratrn'], 'TB', 0, 'L'); $pdf->Cell(33.3, 3.5, number_format($rec['VolumenRecibido'], 3, '.', ','), 'TB', 0, 'L'); $pdf->Cell(33.3, 3.5, number_format($rec['VolumenRecibido'], 3, '.', ','), 'TB', 1, 'L');
                }
            }

            $pdf->SetFont('Arial', '', 7);
            $pdf->Cell(40, 10, 'Conformidad Registro', 0, 0, 'L'); $pdf->Cell(5, 10, ':', 0, 0, 'C'); $pdf->Cell(159, 10, $row['LogRegistro'], 0, 1, 'L');
            $pdf->Cell(40, 10, $labelEstConformidad, 0, 0, 'L'); $pdf->Cell(5, 10, ':', 0, 0, 'C'); $pdf->Cell(159, 10, '', 0, 1, 'L');
            $pdf->Cell(40, 10, 'Conformidad Transportista', 0, 0, 'L'); $pdf->Cell(5, 10, ':', 0, 0, 'C'); $pdf->Cell(159, 10, '', 0, 1, 'L');

            $currentY = $pdf->GetY();
            $pdf->SetY(-18);
            $pdf->SetFont('Arial', 'I', 7);
            $pdf->Cell(200, 1, '', 'B', 1, 'L');
            $pdf->SetY($currentY);
        }

        $pdf->Output();
    }


    function print_purchase_receipts2($folios, $codgas) {

        // 1️⃣ Quitar espacios en blanco alrededor de todo
        $folios = trim($folios);

        // 2️⃣ Reemplazar comas dobles o triples por una sol
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
                $pdf->Cell(200, 3.9, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $row['Empresa']), 0, 1, 'C');
                $pdf->Cell(200, 3.9, $row['Domicilio'], 0, 1, 'C');
                $pdf->Cell(200, 3.9, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $row['Ciudad']), 0, 1, 'C');
                $pdf->Cell(200, 3.9, $row['RFC'], 0, 1, 'C');
                $pdf->Cell(200, 3.9, '', 0, 1, 'C');
                $pdf->Cell(200, 3.9, 'COMPROBANTE DE COMPRA', 0, 1, 'C');
                
                // Sección de recepción
                $pdf->SetFont('Arial', 'IB', 7);
                $pdf->Cell(200, 3, '', 0, 1, 'C');
                $pdf->Cell(23, 3.6, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $row['Estación']), 0, 0, 'l'); $pdf->Cell(5, 3.6, ':', 0, 0, 'C'); $pdf->Cell(
    176,
    3.6,
    iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $row['DocDenominacion'] . ' (' . $row['nropcc'] . ')'),
    0,
    1,
    'L'
);
                $pdf->Cell(23, 3.6, 'Documento ', 0, 0, 'l'); $pdf->Cell(5, 3.6, ':', 0, 0, 'C'); $pdf->Cell(176, 3.6, $row['NroDocumento'], 0, 1, 'L');
                $pdf->Cell(23, 3.6, 'Fecha ', 0, 0, 'l'); $pdf->Cell(5, 3.6, ':', 0, 0, 'C'); $pdf->Cell(176, 3.6, $row['DocFecha'], 0, 1, 'L');
                $pdf->Cell(23, 3.6, 'Turno ', 0, 0, 'l'); $pdf->Cell(5, 3.6, ':', 0, 0, 'C'); $pdf->Cell(176, 3.6, $row['DocTurno'], 0, 1, 'L');
                $pdf->Cell(23, 3.6, 'Proveedor ', 0, 0, 'l'); $pdf->Cell(5, 3.6, ':', 0, 0, 'C'); $pdf->Cell(176, 3.6, $row['Proveedor'], 0, 1, 'L');
                if ((!empty(trim($row['Factura'])))) {
                    $factura = "Factura " . $row['Factura'];
                } else {
                    $factura = "";
                }
                $pdf->Cell(23, 3.6, 'Referencias ', 0, 0, 'l'); $pdf->Cell(5, 3.6, ':', 0, 0, 'C'); $pdf->Cell(176, 3.6, $factura . ' ' . iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $row['RemisionVehiculo']), 0, 1, 'L');
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
                        $pdf->Cell(40, 3.5, $concepto['Concepto'], 0, 0, 'L');
                        $pdf->Cell(63, 3.5, $concepto['Producto'], 0, 0, 'L');
                        $pdf->Cell(20, 3.5, number_format($concepto['Cantidad'] ?? 0, 3, '.', ','), 0, 0, 'L');
                        $pdf->Cell(20, 3.5, number_format($concepto['Precio'] ?? 0, 5, '.', ','), 0, 0, 'L');
                        $pdf->Cell(25, 3.5, number_format($concepto['Monto'] ?? 0, 2, '.', ','), 0, 0, 'L');
                        $pdf->Cell(32, 3.5, $concepto['Producto'], 0, 1, 'L');
                    }
                }

                $pdf->SetFont('Arial', 'B', 7);
                $pdf->Cell(123, 3.5, 'SUBTOTAL', 'T', 0, 'L'); $pdf->Cell(20, 3.5, '', 'T', 0, 'L'); $pdf->Cell(25, 3.5, number_format(($row['Importe'] + $row['Recargos']), 2, '.', ','), 'T', 0, 'L'); $pdf->Cell(32, 3.5, '', 'T', 1, 'L');
                $pdf->Cell(123, 3.5, 'I.V.A.', 'B', 0, 'L'); $pdf->Cell(20, 3.5, '', 'B', 0, 'L'); $pdf->Cell(25, 3.5, number_format(($row['I.V.A.'] + $iva_concepto), 2, '.', ','), 'B', 0, 'L'); $pdf->Cell(32, 3.5, '', 'B', 1, 'L');
                $pdf->Cell(123, 3.5, 'TOTAL', 'TB', 0, 'L'); $pdf->Cell(20, 3.5, '', 'TB', 0, 'L'); $pdf->Cell(25, 3.5, number_format(($subtotal + $row['I.V.A.']), 2, '.', ','), 'TB', 0, 'L'); $pdf->Cell(32, 3.5, '', 'TB', 1, 'L');
                
                // Espacio
                $pdf->Cell(200, 10, '', 0, 1, 'L');
                $pdf->Cell(33.3, 3.5, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', 'Recepción'), 'TB', 0, 'L');
                $pdf->Cell(33.3, 3.5, 'Tanque', 'TB', 0, 'L');
                $pdf->Cell(33.3, 3.5, 'Fecha', 'TB', 0, 'L');
                $pdf->Cell(33.3, 3.5, 'Hora', 'TB', 0, 'L');
                $pdf->Cell(33.3, 3.5, 'Volumen', 'TB', 0, 'L');
                $pdf->Cell(33.3, 3.5, 'Aplicado', 'TB', 1, 'L');
                if ($receptions = $this->Documentos->get_receptions($row['codgas'], $row['Número'])) {
                    $pdf->SetFont('Arial', '', 7);
                    foreach ($receptions as $key => $rec) {
                        $pdf->Cell(33.3, 3.5, $rec['nrotrn'], 'TB', 0, 'L'); $pdf->Cell(33.3, 3.5, $rec['Tanque'], 'TB', 0, 'L'); $pdf->Cell(33.3, 3.5, $rec['Fecha'], 'TB', 0, 'L'); $pdf->Cell(33.3, 3.5, $rec['hratrn'], 'TB', 0, 'L'); $pdf->Cell(33.3, 3.5, number_format($rec['VolumenRecibido'], 3, '.', ','), 'TB', 0, 'L'); $pdf->Cell(33.3, 3.5, number_format($rec['VolumenRecibido'], 3, '.', ','), 'TB', 1, 'L'); 
                    }
                }

                
                
                $pdf->SetFont('Arial', '', 7);
                $pdf->Cell(40, 10, 'Conformidad Registro', 0, 0, 'L'); $pdf->Cell(5, 10, ':', 0, 0, 'C'); $pdf->Cell(159, 10, $row['LogRegistro'], 0, 1, 'L');
                $pdf->Cell(40, 10, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', 'Conformidad Estación'), 0, 0, 'L');
                $pdf->Cell(5, 10, ':', 0, 0, 'C');
                $pdf->Cell(159, 10, '', 0, 1, 'L');
                $pdf->Cell(40, 10, 'Conformidad Transportista', 0, 0, 'L'); $pdf->Cell(5, 10, ':', 0, 0, 'C'); $pdf->Cell(159, 10, '', 0, 1, 'L');
                
                // AGREGAR PIE DE PÁGINA MANUALMENTE
                // Guardar posición actual
                $currentY = $pdf->GetY();
                
                // Mover al final de la página (10mm desde el borde inferior)
                $pdf->SetY(-18);
                
                // Configurar fuente para el pie
                $pdf->SetFont('Arial', 'I', 7);
                $pdf->Cell(200, 1, '', 'B', 1, 'L');
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
                json_output(array("data" => $data, "uuids_encontrados" => $uuidsCadena, "uuids_procesados" => count($uuids)));

            } catch (Exception $e) {
                // Retornar error en formato JSON
                http_response_code(400);
                json_output(array("error" => $e->getMessage()));
            }
        }
    }

    function print_purchase_receipts3() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $uuids = $_POST['uuids_encontrados'] ?? '';
            if ($rows = $this->Documentos->movement_analysis_table3($uuids)) {
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
                    $pdf->Cell(200, 3.9, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $row['Empresa']), 0, 1, 'C');
                    $pdf->Cell(200, 3.9, $row['Domicilio'], 0, 1, 'C');
                    $pdf->Cell(200, 3.9, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $row['Ciudad']), 0, 1, 'C');
                    $pdf->Cell(200, 3.9, $row['RFC'], 0, 1, 'C');
                    $pdf->Cell(200, 3.9, '', 0, 1, 'C');
                    $pdf->Cell(200, 3.9, 'COMPROBANTE DE COMPRA', 0, 1, 'C');
                    
                    // Sección de recepción
                    $pdf->SetFont('Arial', 'IB', 7);
                    $pdf->Cell(200, 3, '', 0, 1, 'C');
                    $pdf->Cell(23, 3.6, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $row['Estación']), 0, 0, 'l'); $pdf->Cell(5, 3.6, ':', 0, 0, 'C'); $pdf->Cell(
    176,
    3.6,
    iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $row['DocDenominacion'] . ' (' . $row['nropcc'] . ')'),
    0,
    1,
    'L'
);
                    $pdf->Cell(23, 3.6, 'Documento ', 0, 0, 'l'); $pdf->Cell(5, 3.6, ':', 0, 0, 'C'); $pdf->Cell(176, 3.6, $row['NroDocumento'], 0, 1, 'L');
                    $pdf->Cell(23, 3.6, 'Fecha ', 0, 0, 'l'); $pdf->Cell(5, 3.6, ':', 0, 0, 'C'); $pdf->Cell(176, 3.6, $row['DocFecha'], 0, 1, 'L');
                    $pdf->Cell(23, 3.6, 'Turno ', 0, 0, 'l'); $pdf->Cell(5, 3.6, ':', 0, 0, 'C'); $pdf->Cell(176, 3.6, $row['DocTurno'], 0, 1, 'L');
                    $pdf->Cell(23, 3.6, 'Proveedor ', 0, 0, 'l'); $pdf->Cell(5, 3.6, ':', 0, 0, 'C'); $pdf->Cell(176, 3.6, $row['Proveedor'], 0, 1, 'L');
                    if ((!empty(trim($row['Factura'])))) {
                        $factura = "Factura " . $row['Factura'];
                    } else {
                        $factura = "";
                    }
                    $pdf->Cell(23, 3.6, 'Referencias ', 0, 0, 'l'); $pdf->Cell(5, 3.6, ':', 0, 0, 'C'); $pdf->Cell(176, 3.6, $factura . ' ' . iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $row['RemisionVehiculo']), 0, 1, 'L');
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
                            $pdf->Cell(40, 3.5, $concepto['Concepto'], 0, 0, 'L');
                            $pdf->Cell(63, 3.5, $concepto['Producto'], 0, 0, 'L');
                            $pdf->Cell(20, 3.5, number_format($concepto['Cantidad'] ?? 0, 3, '.', ','), 0, 0, 'L');
                            $pdf->Cell(20, 3.5, number_format($concepto['Precio'] ?? 0, 5, '.', ','), 0, 0, 'L');
                            $pdf->Cell(25, 3.5, number_format($concepto['Monto'] ?? 0, 2, '.', ','), 0, 0, 'L');
                            $pdf->Cell(32, 3.5, $concepto['Producto'], 0, 1, 'L');
                        }
                    }

                    $pdf->SetFont('Arial', 'B', 7);
                    $pdf->Cell(123, 3.5, 'SUBTOTAL', 'T', 0, 'L'); $pdf->Cell(20, 3.5, '', 'T', 0, 'L'); $pdf->Cell(25, 3.5, number_format(($row['Importe'] + $row['Recargos']), 2, '.', ','), 'T', 0, 'L'); $pdf->Cell(32, 3.5, '', 'T', 1, 'L');
                    $pdf->Cell(123, 3.5, 'I.V.A.', 'B', 0, 'L'); $pdf->Cell(20, 3.5, '', 'B', 0, 'L'); $pdf->Cell(25, 3.5, number_format(($row['I.V.A.'] + $iva_concepto), 2, '.', ','), 'B', 0, 'L'); $pdf->Cell(32, 3.5, '', 'B', 1, 'L');
                    $pdf->Cell(123, 3.5, 'TOTAL', 'TB', 0, 'L'); $pdf->Cell(20, 3.5, '', 'TB', 0, 'L'); $pdf->Cell(25, 3.5, number_format(($subtotal + $row['I.V.A.']), 2, '.', ','), 'TB', 0, 'L'); $pdf->Cell(32, 3.5, '', 'TB', 1, 'L');
                    
                    // Espacio
                    $pdf->Cell(200, 10, '', 0, 1, 'L');
                    $pdf->Cell(33.3, 3.5, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', 'Recepción'), 'TB', 0, 'L');
                    $pdf->Cell(33.3, 3.5, 'Tanque', 'TB', 0, 'L');
                    $pdf->Cell(33.3, 3.5, 'Fecha', 'TB', 0, 'L');
                    $pdf->Cell(33.3, 3.5, 'Hora', 'TB', 0, 'L');
                    $pdf->Cell(33.3, 3.5, 'Volumen', 'TB', 0, 'L');
                    $pdf->Cell(33.3, 3.5, 'Aplicado', 'TB', 1, 'L');
                    if ($receptions = $this->Documentos->get_receptions($row['codgas'], $row['Número'])) {
                        $pdf->SetFont('Arial', '', 7);
                        foreach ($receptions as $key => $rec) {
                            $pdf->Cell(33.3, 3.5, $rec['nrotrn'], 'TB', 0, 'L'); $pdf->Cell(33.3, 3.5, $rec['Tanque'], 'TB', 0, 'L'); $pdf->Cell(33.3, 3.5, $rec['Fecha'], 'TB', 0, 'L'); $pdf->Cell(33.3, 3.5, $rec['hratrn'], 'TB', 0, 'L'); $pdf->Cell(33.3, 3.5, number_format($rec['VolumenRecibido'], 3, '.', ','), 'TB', 0, 'L'); $pdf->Cell(33.3, 3.5, number_format($rec['VolumenRecibido'], 3, '.', ','), 'TB', 1, 'L'); 
                        }
                    }

                    
                    
                    $pdf->SetFont('Arial', '', 7);
                    $pdf->Cell(40, 10, 'Conformidad Registro', 0, 0, 'L'); $pdf->Cell(5, 10, ':', 0, 0, 'C'); $pdf->Cell(159, 10, $row['LogRegistro'], 0, 1, 'L');
                    $pdf->Cell(40, 10, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', 'Conformidad Estación'), 0, 0, 'L');
                    $pdf->Cell(5, 10, ':', 0, 0, 'C');
                    $pdf->Cell(159, 10, '', 0, 1, 'L');
                    $pdf->Cell(40, 10, 'Conformidad Transportista', 0, 0, 'L'); $pdf->Cell(5, 10, ':', 0, 0, 'C'); $pdf->Cell(159, 10, '', 0, 1, 'L');
                    
                    // AGREGAR PIE DE PÁGINA MANUALMENTE
                    // Guardar posición actual
                    $currentY = $pdf->GetY();
                    
                    // Mover al final de la página (10mm desde el borde inferior)
                    $pdf->SetY(-18);
                    
                    // Configurar fuente para el pie
                    $pdf->SetFont('Arial', 'I', 7);
                    $pdf->Cell(200, 1, '', 'B', 1, 'L');                    
                    // Restaurar la posición Y para el siguiente documento (si lo hay)
                    $pdf->SetY($currentY);
                }
                
                // Salida del PDF
                $pdf->Output();
            } else {
                echo '<pre>';
                var_dump("Error: 123456789");
                die();
            }
        }
    }

    function print_purchase_receipts4() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $facturas = $_POST['facturas'] ?? '';

            // 1️⃣ Quitar espacios en blanco alrededor de todo
            $facturas = trim($facturas);

            // 2️⃣ Reemplazar comas dobles o triples por una sola
            $facturas = preg_replace('/,+/', ',', $facturas);

            // 3️⃣ Separar por comas
            $facturasArray = explode(',', $facturas);

            // 4️⃣ Eliminar elementos vacíos y espacios extra
            $facturasArray = array_filter(array_map('trim', $facturasArray), 'strlen');

            // 5️⃣ (Opcional) Eliminar duplicados
            $facturasArray = array_unique($facturasArray);

            // 6️⃣ (Opcional) Reordenar si querés que queden ordenados numéricamente
            sort($facturasArray, SORT_NUMERIC);

            // 7️⃣ Agregar comillas simples a cada elemento y unir
            $facturasLimpio = "'" . implode("','", $facturasArray) . "'";
            
            if ($rows = $this->Documentos->movement_analysis_table4($facturasLimpio)) {
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
                    $pdf->Cell(200, 3.9, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $row['Empresa']), 0, 1, 'C');
                    $pdf->Cell(200, 3.9, $row['Domicilio'], 0, 1, 'C');
                    $pdf->Cell(200, 3.9, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $row['Ciudad']), 0, 1, 'C');
                    $pdf->Cell(200, 3.9, $row['RFC'], 0, 1, 'C');
                    $pdf->Cell(200, 3.9, '', 0, 1, 'C');
                    $pdf->Cell(200, 3.9, 'COMPROBANTE DE COMPRA', 0, 1, 'C');
                    
                    // Sección de recepción
                    $pdf->SetFont('Arial', 'IB', 7);
                    $pdf->Cell(200, 3, '', 0, 1, 'C');
                    $pdf->Cell(23, 3.6, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $row['Estación']), 0, 0, 'l'); $pdf->Cell(5, 3.6, ':', 0, 0, 'C'); $pdf->Cell(
                        176,
                        3.6,
                        iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $row['DocDenominacion'] . ' (' . $row['nropcc'] . ')'),
                        0,
                        1,
                        'L'
                    );
                    $pdf->Cell(23, 3.6, 'Documento ', 0, 0, 'l'); $pdf->Cell(5, 3.6, ':', 0, 0, 'C'); $pdf->Cell(176, 3.6, $row['NroDocumento'], 0, 1, 'L');
                    $pdf->Cell(23, 3.6, 'Fecha ', 0, 0, 'l'); $pdf->Cell(5, 3.6, ':', 0, 0, 'C'); $pdf->Cell(176, 3.6, $row['DocFecha'], 0, 1, 'L');
                    $pdf->Cell(23, 3.6, 'Turno ', 0, 0, 'l'); $pdf->Cell(5, 3.6, ':', 0, 0, 'C'); $pdf->Cell(176, 3.6, $row['DocTurno'], 0, 1, 'L');
                    $pdf->Cell(23, 3.6, 'Proveedor ', 0, 0, 'l'); $pdf->Cell(5, 3.6, ':', 0, 0, 'C'); $pdf->Cell(176, 3.6, $row['Proveedor'], 0, 1, 'L');
                    if ((!empty(trim($row['Factura'])))) {
                        $factura = "Factura " . $row['Factura'];
                    } else {
                        $factura = "";
                    }
                    $pdf->Cell(23, 3.6, 'Referencias ', 0, 0, 'l'); $pdf->Cell(5, 3.6, ':', 0, 0, 'C'); $pdf->Cell(176, 3.6, $factura . ' ' . iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $row['RemisionVehiculo']), 0, 1, 'L');
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
                            $pdf->Cell(40, 3.5, $concepto['Concepto'], 0, 0, 'L');
                            $pdf->Cell(63, 3.5, $concepto['Producto'], 0, 0, 'L');
                            $pdf->Cell(20, 3.5, number_format($concepto['Cantidad'] ?? 0, 3, '.', ','), 0, 0, 'L');
                            $pdf->Cell(20, 3.5, number_format($concepto['Precio'] ?? 0, 5, '.', ','), 0, 0, 'L');
                            $pdf->Cell(25, 3.5, number_format($concepto['Monto'] ?? 0, 2, '.', ','), 0, 0, 'L');
                            $pdf->Cell(32, 3.5, $concepto['Producto'], 0, 1, 'L');
                        }
                    }

                    $pdf->SetFont('Arial', 'B', 7);
                    $pdf->Cell(123, 3.5, 'SUBTOTAL', 'T', 0, 'L'); $pdf->Cell(20, 3.5, '', 'T', 0, 'L'); $pdf->Cell(25, 3.5, number_format(($row['Importe'] + $row['Recargos']), 2, '.', ','), 'T', 0, 'L'); $pdf->Cell(32, 3.5, '', 'T', 1, 'L');
                    $pdf->Cell(123, 3.5, 'I.V.A.', 'B', 0, 'L'); $pdf->Cell(20, 3.5, '', 'B', 0, 'L'); $pdf->Cell(25, 3.5, number_format(($row['I.V.A.'] + $iva_concepto), 2, '.', ','), 'B', 0, 'L'); $pdf->Cell(32, 3.5, '', 'B', 1, 'L');
                    $pdf->Cell(123, 3.5, 'TOTAL', 'TB', 0, 'L'); $pdf->Cell(20, 3.5, '', 'TB', 0, 'L'); $pdf->Cell(25, 3.5, number_format(($subtotal + $row['I.V.A.']), 2, '.', ','), 'TB', 0, 'L'); $pdf->Cell(32, 3.5, '', 'TB', 1, 'L');
                    
                    // Espacio
                    $pdf->Cell(200, 10, '', 0, 1, 'L');
                    $pdf->Cell(33.3, 3.5, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', 'Recepción'), 'TB', 0, 'L');
                    $pdf->Cell(33.3, 3.5, 'Tanque', 'TB', 0, 'L');
                    $pdf->Cell(33.3, 3.5, 'Fecha', 'TB', 0, 'L');
                    $pdf->Cell(33.3, 3.5, 'Hora', 'TB', 0, 'L');
                    $pdf->Cell(33.3, 3.5, 'Volumen', 'TB', 0, 'L');
                    $pdf->Cell(33.3, 3.5, 'Aplicado', 'TB', 1, 'L');
                    if ($receptions = $this->Documentos->get_receptions($row['codgas'], $row['Número'])) {
                        $pdf->SetFont('Arial', '', 7);
                        foreach ($receptions as $key => $rec) {
                            $pdf->Cell(33.3, 3.5, $rec['nrotrn'], 'TB', 0, 'L'); $pdf->Cell(33.3, 3.5, $rec['Tanque'], 'TB', 0, 'L'); $pdf->Cell(33.3, 3.5, $rec['Fecha'], 'TB', 0, 'L'); $pdf->Cell(33.3, 3.5, $rec['hratrn'], 'TB', 0, 'L'); $pdf->Cell(33.3, 3.5, number_format($rec['VolumenRecibido'], 3, '.', ','), 'TB', 0, 'L'); $pdf->Cell(33.3, 3.5, number_format($rec['VolumenRecibido'], 3, '.', ','), 'TB', 1, 'L'); 
                        }
                    }

                    
                    
                    $pdf->SetFont('Arial', '', 7);
                    $pdf->Cell(40, 10, 'Conformidad Registro', 0, 0, 'L'); $pdf->Cell(5, 10, ':', 0, 0, 'C'); $pdf->Cell(159, 10, $row['LogRegistro'], 0, 1, 'L');
                    $pdf->Cell(40, 10, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', 'Conformidad Estación'), 0, 0, 'L');
                    $pdf->Cell(5, 10, ':', 0, 0, 'C');
                    $pdf->Cell(159, 10, '', 0, 1, 'L');
                    $pdf->Cell(40, 10, 'Conformidad Transportista', 0, 0, 'L'); $pdf->Cell(5, 10, ':', 0, 0, 'C'); $pdf->Cell(159, 10, '', 0, 1, 'L');
                    
                    // AGREGAR PIE DE PÁGINA MANUALMENTE
                    // Guardar posición actual
                    $currentY = $pdf->GetY();
                    
                    // Mover al final de la página (10mm desde el borde inferior)
                    $pdf->SetY(-18);
                    
                    // Configurar fuente para el pie
                    $pdf->SetFont('Arial', 'I', 7);
                    $pdf->Cell(200, 1, '', 'B', 1, 'L');
                    // Restaurar la posición Y para el siguiente documento (si lo hay)
                    $pdf->SetY($currentY);
                }
                
                // Salida del PDF
                $pdf->Output();
            } else {
                echo '<pre>';
                var_dump("Error: 123456789");
                die();
            }
        }
    }


    public function analysis_movement_table() {
        set_time_limit(280);
        header('Content-Type: application/json');

        $from = dateToInt($_POST['fromDate']);
        $until = dateToInt($_POST['untilDate']);
        if ($rows = $this->Documentos->analysis_movement_table($from,$until)) {

            foreach ($rows as $row) {
                $data[] = array(
                    'fecha'   => $row['fecha'],
                    'factura' => $row['factura'],
                    'mtoapl'  => $row['mtoapl'],
                    'den'     => $row['den'],
                    'abr'     => $row['abr'],
                    'nro'     => $row['nro'],
                    'satuid'  => $row['satuid'],
                    'txtref'  => $row['txtref'],
                    'mov_n'   => $row['mov_n'],
                );
            }
            $data = array("data" => $data);
            echo json_encode($data);
        } else {
            echo json_encode(["data" => []]); // Devuelve un array vacío si no hay datos
        }
    }

    private function _callApi($url, $postData) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_POST, true);
    
        $response = curl_exec($ch);
        curl_close($ch);
        return json_decode($response, true);
    }

    public function invoice_puchase_table(){
        ini_set('max_execution_time', 5000);
        ini_set('memory_limit', '1024M');
        set_time_limit(0);
        header('Content-Type: application/json');
        $postData = [
            'from' => dateToInt($_POST['fromDate']),
            'until' => dateToInt($_POST['untilDate']),
            'codgas' => $_POST['codgas'] ? $_POST['codgas'] : '0',
            'proveedor' => $_POST['proveedor'] ? $_POST['proveedor'] : '0',
            'company' => $_POST['company'] ? $_POST['company'] : '0'
        ];

        $ch = curl_init('http://192.168.0.109:82/api/estacion_documentos_compra/');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_POST, true);

        // Ejecutar y obtener respuesta
        $response = curl_exec($ch);
        curl_close($ch);
        $apiData = json_decode($response, true);
        $data = [];
        if (isset($apiData) && is_array($apiData)) {
            foreach ($apiData as $row) {

                $status = $this->determineInvoiceStatus($row);
                $data[] = array(
                    'nro'              => $row['nro'],
                    'Factura'          => $row['Factura'],
                    'Remision'         => isset($row['Remision']) ? substr($row['Remision'], 0, 15) : '',
                    'fecha'            => $row['fecha'],
                    'fechaVto'         => $row['fechaVto'],
                    'producto'         => $row['producto'],
                    'proveedor'        => $row['proveedor'],
                    'proveedor_codigo' => $row['proveedor_codigo'],
                    'volrec'           => $row['volrec'],
                    'can'              => $row['can'],
                    'pre'              => $row['pre'],
                    'mto'              => $row['mto'],
                    'mtoiie'           => $row['mtoiie'],
                    'iva8'             => $row['iva8'],
                    'iva'              => $row['iva'],
                    'iva_total'        => $row['iva_total'],
                    'servicio'         => $row['servicio'],
                    'iva_servicio'     => $row['iva_servicio'],
                    'total_fac'        => $row['total_fac'],
                    'satuid'           => $row['satuid'],
                    'gasolinera'       => $row['gasolinera'],
                    'codgas'           => $row['codgas'],
                    'en_orden_pago'    => $row['en_orden_pago'],
                    'payment_status'   => $row['payment_status'],
                    'codigo_empresa'   => $row['codigo_empresa'],
                    'status'           => $status
                );
            }
        }
        json_output(array("data" => $data));
    }
    /**
     * Determina el estatus de una factura basado en los datos
     */
    private function determineInvoiceStatus($row) {
        // Sin factura y sin descarga
        if (empty($row['Factura']) && empty($row['volrec'])) {
            return 'Sin Factura y Descarga';
        }
        
        // Sin factura
        if (empty($row['Factura'])) {
            return 'Sin Factura';
        }
        
        // Sin descarga
        if (empty($row['volrec'])) {
            return 'Sin Descarga';
        }
        
        if (isset($row['can']) && isset($row['volrec'])) {
            $diferencia = abs($row['can'] - $row['volrec']);
            $tolerancia = $row['can'] * 0.12; // 12% de tolerancia
            
            if ($diferencia > $tolerancia) {
                return 'Diferencia Cantidad';
            }
        }
        
        return 'Correcto';
    }

    function xml_vs_ventas() {
        if (preg_match('/GET/i',$_SERVER['REQUEST_METHOD'])){
            echo $this->twig->render($this->route . 'xml_vs_ventas.html');
        }
    }

    function xml_vs_ventas_table() {
        $data = $this->xmlVsVentasModel->get_xml_vs_ventas();
        header('Content-Type: application/json');
        echo json_encode(['data' => $data]);
    }

    function xml_vs_ventas_mensuales_table() {
        $data = $this->xmlVsVentasModel->get_xml_vs_ventas_mensuales();
        header('Content-Type: application/json');
        echo json_encode(['data' => $data]);
    }

    function remisiones_petrotal() {
        if (preg_match('/GET/i', $_SERVER['REQUEST_METHOD'])) {
            echo $this->twig->render($this->route . 'remisiones_petrotal.html');
        }
    }

    // =========================================================
    // RENEGOCIACION DE PROVEEDORES
    // =========================================================

    public function renegociacion(): void {
        if (preg_match('/GET/i', $_SERVER['REQUEST_METHOD'])) {
            $latest_upload = $this->RenegociacionModel->get_latest_upload();
            $uploads       = $this->RenegociacionModel->get_uploads_history();
            echo $this->twig->render($this->route . 'renegociacion.html',
                compact('latest_upload', 'uploads'));
        }
    }

    public function reneg_import_excel(): void {
        header('Content-Type: application/json');
        if (!preg_match('/POST/i', $_SERVER['REQUEST_METHOD'])) {
            echo json_encode(['success' => false, 'message' => 'Método no permitido']); return;
        }

        if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => 'No se recibió ningún archivo']); return;
        }

        $ext = strtolower(pathinfo($_FILES['excel_file']['name'], PATHINFO_EXTENSION));
        if ($ext !== 'xlsx') {
            echo json_encode(['success' => false, 'message' => 'Solo se permiten archivos .xlsx']); return;
        }

        try {
            $spreadsheet = IOFactory::load($_FILES['excel_file']['tmp_name']);

            // Buscar la hoja PENDIENTE PAGO
            $sheet = null;
            foreach ($spreadsheet->getAllSheets() as $s) {
                if (stripos($s->getTitle(), 'PENDIENTE') !== false) {
                    $sheet = $s; break;
                }
            }
            if (!$sheet) $sheet = $spreadsheet->getActiveSheet();

            $allRows = $sheet->toArray(null, true, true, false);

            // Detectar fila de headers buscando "OC" en cualquier columna de las primeras 10 filas
            $headerRow  = -1;
            $colOffset  = 0; // columna donde empieza OC
            foreach (array_slice($allRows, 0, 10, true) as $idx => $row) {
                foreach ($row as $colIdx => $cell) {
                    if (strtoupper(trim((string)$cell)) === 'OC') {
                        $headerRow = $idx;
                        $colOffset = $colIdx;
                        break 2;
                    }
                }
            }
            if ($headerRow === -1) {
                echo json_encode(['success' => false, 'message' => 'No se encontró la fila de encabezados (columna OC). Verifica que la hoja se llame "PENDIENTE PAGO" y tenga los headers en las primeras 10 filas.']); return;
            }

            $parseDate = function($val): ?string {
                if (empty($val) || $val === '-' || $val === '$ -') return null;
                if (is_numeric($val)) {
                    try {
                        return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float)$val)
                            ->format('Y-m-d');
                    } catch (\Exception $e) { return null; }
                }
                foreach (['d/m/Y', 'Y-m-d', 'd-m-Y', 'm/d/Y'] as $fmt) {
                    $d = \DateTime::createFromFormat($fmt, trim((string)$val));
                    if ($d) return $d->format('Y-m-d');
                }
                return null;
            };

            $parseMoney = function($val): ?float {
                if ($val === null || $val === '' || $val === '-' || $val === '$ -') return null;
                $clean = preg_replace('/[^0-9.\-]/', '', str_replace(',', '', (string)$val));
                return $clean !== '' ? (float)$clean : null;
            };

            $c = $colOffset; // alias para legibilidad
            $rows = [];
            for ($i = $headerRow + 1; $i < count($allRows); $i++) {
                $r = $allRows[$i];
                // Saltar filas completamente vacías
                $vals = array_filter(array_map('trim', array_map('strval', $r)));
                if (empty($vals)) continue;

                $rows[] = [
                    'oc'               => isset($r[$c])    ? trim((string)$r[$c])    : null,
                    'req'              => isset($r[$c+1])  ? trim((string)$r[$c+1])  : null,
                    'fecha_aut'        => $parseDate($r[$c+2]  ?? null),
                    'rfc'              => isset($r[$c+3])  ? trim((string)$r[$c+3])  : null,
                    'proveedor'        => isset($r[$c+4])  ? trim((string)$r[$c+4])  : null,
                    'responsable'      => isset($r[$c+5])  ? trim((string)$r[$c+5])  : null,
                    'razon_social'     => isset($r[$c+6])  ? trim((string)$r[$c+6])  : null,
                    'factura'          => isset($r[$c+7])  ? trim((string)$r[$c+7])  : null,
                    'fecha_factura'    => $parseDate($r[$c+8]  ?? null),
                    'iva'              => (function($v) use ($parseMoney) {
                                             $n = $parseMoney($v);
                                             if ($n === null) return null;
                                             return $n > 1 ? round($n / 100, 4) : $n;
                                         })($r[$c+9]  ?? null),
                    'subtotal'         => $parseMoney($r[$c+10] ?? null),
                    'impt_iva'         => $parseMoney($r[$c+11] ?? null),
                    'descuento'        => $parseMoney($r[$c+12] ?? null),
                    'ret_4pct'         => $parseMoney($r[$c+13] ?? null),
                    'iva_ret'          => $parseMoney($r[$c+14] ?? null),
                    'isr_ret'          => $parseMoney($r[$c+15] ?? null),
                    'total'            => $parseMoney($r[$c+16] ?? null),
                    'importe_dlls'     => $parseMoney($r[$c+17] ?? null),
                    'cc'               => isset($r[$c+18]) && is_numeric($r[$c+18]) ? (int)$r[$c+18] : null,
                    'concepto'         => isset($r[$c+19]) ? trim((string)$r[$c+19]) : null,
                    'prov'             => isset($r[$c+20]) ? trim((string)$r[$c+20]) : null,
                    'autoriza_oc'      => isset($r[$c+21]) ? trim((string)$r[$c+21]) : null,
                    'metodo_pago'      => isset($r[$c+22]) ? trim((string)$r[$c+22]) : null,
                    'fecha_vencimiento'=> $parseDate($r[$c+23] ?? null),
                    'dias_vencimiento' => isset($r[$c+24]) && is_numeric($r[$c+24]) ? (int)$r[$c+24] : null,
                    'fecha_pago_real'  => $parseDate($r[$c+25] ?? null),
                ];
            }

            if (empty($rows)) {
                echo json_encode(['success' => false, 'message' => 'El archivo no contiene datos']); return;
            }

            $user_id   = $_SESSION['tg_user']['id'] ?? null;
            $upload_id = $this->RenegociacionModel->create_upload(
                $_FILES['excel_file']['name'], count($rows), $user_id
            );
            $total = $this->RenegociacionModel->insert_batch($rows, $upload_id);

            echo json_encode(['success' => true, 'total' => $total, 'upload_id' => $upload_id]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error al procesar el archivo: ' . $e->getMessage()]);
        }
    }

    public function reneg_get_pendientes(): void {
        header('Content-Type: application/json');
        $upload_id   = isset($_GET['upload_id'])   ? (int)$_GET['upload_id'] : 0;
        $responsable = $_GET['responsable']         ?? null;
        $renegociado = $_GET['renegociado']         ?? null;

        if (!$upload_id) {
            $latest    = $this->RenegociacionModel->get_latest_upload();
            $upload_id = $latest ? (int)$latest['id'] : 0;
        }
        if (!$upload_id) {
            echo json_encode(['success' => true, 'data' => [], 'upload_id' => 0]); return;
        }

        $data = $this->RenegociacionModel->get_all($upload_id, $responsable ?: null, $renegociado);
        echo json_encode(['success' => true, 'data' => $data ?: [], 'upload_id' => $upload_id]);
    }

    public function reneg_toggle_renegociado(): void {
        header('Content-Type: application/json');
        $id    = (int)($_POST['id']    ?? 0);
        $valor = (int)($_POST['valor'] ?? 0);
        if (!$id) { echo json_encode(['success' => false]); return; }
        $this->RenegociacionModel->toggle_renegociado($id, $valor);
        echo json_encode(['success' => true]);
    }

    public function reneg_get_responsables(): void {
        header('Content-Type: application/json');
        $upload_id = isset($_GET['upload_id']) ? (int)$_GET['upload_id'] : 0;
        if (!$upload_id) {
            $latest = $this->RenegociacionModel->get_latest_upload();
            $upload_id = $latest ? (int)$latest['id'] : 0;
        }
        $data = $upload_id ? $this->RenegociacionModel->get_responsables($upload_id) : [];
        echo json_encode(['success' => true, 'data' => $data ?: []]);
    }

    public function reneg_get_contactos(): void {
        header('Content-Type: application/json');
        $data = $this->RenegContactosModel->get_all();
        echo json_encode(['success' => true, 'data' => $data ?: []]);
    }

    public function reneg_save_contacto(): void {
        header('Content-Type: application/json');
        $responsable = trim($_POST['responsable'] ?? '');
        $correo      = trim($_POST['correo']      ?? '');

        if (!$responsable || !$correo) {
            echo json_encode(['success' => false, 'message' => 'Nombre y correo son requeridos']); return;
        }
        if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'Correo electrónico inválido']); return;
        }

        $this->RenegContactosModel->upsert($responsable, $correo);
        echo json_encode(['success' => true, 'message' => 'Contacto guardado']);
    }

    public function reneg_delete_contacto(): void {
        header('Content-Type: application/json');
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            echo json_encode(['success' => false, 'message' => 'ID inválido']); return;
        }
        $this->RenegContactosModel->delete($id);
        echo json_encode(['success' => true]);
    }

    public function reneg_send_emails(): void {
        header('Content-Type: application/json');
        $upload_id    = (int)($_POST['upload_id']    ?? 0);
        $responsables = $_POST['responsables']        ?? [];

        if (!$upload_id || empty($responsables)) {
            echo json_encode(['success' => false, 'message' => 'Datos incompletos']); return;
        }

        $user_id  = $_SESSION['tg_user']['id']     ?? null;
        $user_name= $_SESSION['tg_user']['usuario'] ?? 'Sistema';
        $from     = 'no-reply@totalgas.com';

        $enviados  = 0;
        $errores   = 0;
        $sin_correo= 0;
        $detalle   = [];

        foreach ($responsables as $resp) {
            $resp = trim($resp);
            $contacto = $this->RenegContactosModel->get_by_responsable($resp);
            if (!$contacto || !$contacto['activo']) {
                $sin_correo++;
                $detalle[] = ['responsable' => $resp, 'status' => 'sin_correo'];
                continue;
            }

            $facturas = $this->RenegociacionModel->get_facturas_responsable($upload_id, $resp);
            if (!$facturas) {
                $detalle[] = ['responsable' => $resp, 'status' => 'sin_facturas'];
                continue;
            }

            $monto_total = array_sum(array_column($facturas, 'total'));

            // Construir tabla HTML de facturas
            $filas_html = '';
            foreach ($facturas as $f) {
                $fecha_f = $f['fecha_factura'] ? date('d/m/Y', strtotime($f['fecha_factura'])) : '-';
                $total_f = $f['total'] !== null ? '$' . number_format((float)$f['total'], 2) : '-';
                $dlls_f  = $f['importe_dlls'] ? '$' . number_format((float)$f['importe_dlls'], 2) : '-';
                $filas_html .= "
                <tr>
                    <td style='padding:6px 10px;border-bottom:1px solid #e5e7eb;'>" . htmlspecialchars((string)($f['oc'] ?? '-')) . "</td>
                    <td style='padding:6px 10px;border-bottom:1px solid #e5e7eb;'>" . htmlspecialchars((string)($f['proveedor'] ?? '-')) . "</td>
                    <td style='padding:6px 10px;border-bottom:1px solid #e5e7eb;'>" . htmlspecialchars((string)($f['razon_social'] ?? '-')) . "</td>
                    <td style='padding:6px 10px;border-bottom:1px solid #e5e7eb;'>" . htmlspecialchars((string)($f['factura'] ?? '-')) . "</td>
                    <td style='padding:6px 10px;border-bottom:1px solid #e5e7eb;text-align:center;'>{$fecha_f}</td>
                    <td style='padding:6px 10px;border-bottom:1px solid #e5e7eb;text-align:right;'>{$total_f}</td>
                    <td style='padding:6px 10px;border-bottom:1px solid #e5e7eb;text-align:right;'>{$dlls_f}</td>
                </tr>";
            }

            $nombre    = htmlspecialchars($resp);
            $subtotal_fmt = '$' . number_format($monto_total, 2);

            $body = "
<!DOCTYPE html>
<html lang='es'>
<head><meta charset='UTF-8'></head>
<body style='font-family:Arial,sans-serif;color:#333;max-width:800px;margin:0 auto;padding:20px;'>
  <div style='background:#1e3a5f;padding:16px 24px;border-radius:6px 6px 0 0;'>
    <h2 style='color:#fff;margin:0;font-size:18px;'>TotalGas &mdash; Solicitud de Renegociaci&oacute;n con Proveedores</h2>
  </div>
  <div style='border:1px solid #e5e7eb;border-top:none;border-radius:0 0 6px 6px;padding:24px;'>
    <p>Buenos d&iacute;as <strong>{$nombre}</strong>,</p>
    <p>Kuwait solicita tu apoyo en la conclusi&oacute;n del proceso de configuraci&oacute;n del
    <strong>&ldquo;Archivo de renegociaci&oacute;n&rdquo;</strong>, cuyo requerimiento inicial fue enviado
    el jueves 16 de abril.</p>
    <p>La finalidad de este proyecto es habilitar el env&iacute;o autom&aacute;tico de pagos de forma
    semanal. Para ello es necesario que te acerques a la <strong>Jefatura de Contabilidad</strong> y
    lleves a cabo la renegociaci&oacute;n formal con tus proveedores.</p>
    <p>A continuaci&oacute;n encontrar&aacute;s el detalle de las facturas pendientes a tu cargo:</p>
    <table style='width:100%;border-collapse:collapse;font-size:13px;margin-top:12px;'>
      <thead>
        <tr style='background:#1e3a5f;color:#fff;'>
          <th style='padding:8px 10px;text-align:left;'>OC</th>
          <th style='padding:8px 10px;text-align:left;'>PROVEEDOR</th>
          <th style='padding:8px 10px;text-align:left;'>RAZ&Oacute;N SOCIAL</th>
          <th style='padding:8px 10px;text-align:left;'>FACTURA</th>
          <th style='padding:8px 10px;text-align:center;'>FECHA FACTURA</th>
          <th style='padding:8px 10px;text-align:right;'>TOTAL</th>
          <th style='padding:8px 10px;text-align:right;'>IMPORTE DLLS</th>
        </tr>
      </thead>
      <tbody>
        {$filas_html}
      </tbody>
      <tfoot>
        <tr style='background:#f3f4f6;font-weight:bold;'>
          <td colspan='5' style='padding:8px 10px;text-align:right;border-top:2px solid #1e3a5f;'>Total:</td>
          <td style='padding:8px 10px;text-align:right;border-top:2px solid #1e3a5f;'>{$subtotal_fmt}</td>
          <td style='padding:8px 10px;border-top:2px solid #1e3a5f;'></td>
        </tr>
      </tfoot>
    </table>
    <p style='margin-top:24px;color:#555;font-size:12px;'>
      Agradecemos tu atenci&oacute;n y apoyo.<br>
      <strong>Atentamente, &Aacute;rea de Contabilidad &mdash; TotalGas</strong>
    </p>
  </div>
</body>
</html>";

            $subject = 'Solicitud de Renegociación con Proveedores — TotalGas';
            // MODO PRUEBA: todos los correos van a alejandro.martinez@totalgas.com
            $ok = send_mail($subject, $body, ['alejandro.martinez@totalgas.com'], $from);

            $this->RenegEmailLogModel->log_send([
                'upload_id'      => $upload_id,
                'responsable'    => $resp,
                'correo'         => $contacto['correo'],
                'total_facturas' => count($facturas),
                'monto_total'    => $monto_total,
                'enviado_por'    => $user_id,
                'status'         => $ok ? 'sent' : 'error',
                'error_msg'      => $ok ? null : 'Error al enviar',
            ]);

            if ($ok) {
                $enviados++;
                $detalle[] = ['responsable' => $resp, 'correo' => $contacto['correo'], 'status' => 'sent'];
            } else {
                $errores++;
                $detalle[] = ['responsable' => $resp, 'correo' => $contacto['correo'], 'status' => 'error'];
            }
        }

        echo json_encode([
            'success'    => true,
            'enviados'   => $enviados,
            'errores'    => $errores,
            'sin_correo' => $sin_correo,
            'detalle'    => $detalle,
        ]);
    }

    public function reneg_get_resumen(): void {
        header('Content-Type: application/json');
        $upload_id = isset($_GET['upload_id']) ? (int)$_GET['upload_id'] : 0;
        if (!$upload_id) {
            $latest = $this->RenegociacionModel->get_latest_upload();
            $upload_id = $latest ? (int)$latest['id'] : 0;
        }
        if (!$upload_id) {
            echo json_encode(['success' => true, 'data' => [], 'upload_id' => 0]); return;
        }

        $resumen    = $this->RenegociacionModel->get_by_responsable($upload_id);
        $contactos  = $this->RenegContactosModel->get_all();
        $correos_map = [];
        foreach (($contactos ?: []) as $c) {
            $correos_map[strtoupper(trim($c['responsable']))] = $c;
        }

        $data = [];
        foreach (($resumen ?: []) as $r) {
            $key      = strtoupper(trim($r['responsable']));
            $contacto = $correos_map[$key] ?? null;
            $data[] = [
                'responsable'    => $r['responsable'],
                'total_facturas' => $r['total_facturas'],
                'monto_total'    => $r['monto_total'],
                'monto_dlls'     => $r['monto_dlls'],
                'correo'         => $contacto ? $contacto['correo'] : null,
                'tiene_correo'   => $contacto ? (bool)$contacto['activo'] : false,
                'contacto_id'    => $contacto ? $contacto['id'] : null,
            ];
        }

        echo json_encode(['success' => true, 'data' => $data, 'upload_id' => $upload_id]);
    }

    public function reneg_get_email_log(): void {
        header('Content-Type: application/json');
        $upload_id = isset($_GET['upload_id']) ? (int)$_GET['upload_id'] : null;
        $data = $this->RenegEmailLogModel->get_log($upload_id);
        echo json_encode(['success' => true, 'data' => $data ?: []]);
    }
}
