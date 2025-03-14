<?php
class Accounting{
    public $twig;
    public $route;
    public XmlCreModel $xmlCreModel;
    public FacturasModel $facturas;
    public DocumentosModel $Documentos;

    /**
     * @param $twig
     */
    public function __construct($twig) {
        $this->twig         = $twig;
        $this->route        = 'views/accounting/';
        $this->xmlCreModel  = new XmlCreModel();
        $this->facturas     = new FacturasModel();
        $this->Documentos     = new DocumentosModel();
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
            echo $this->twig->render($this->route . 'purchase_invoice.html');
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
                );
            }
            $data = array("data" => $data);
            echo json_encode($data);
        } else {
            echo json_encode(["data" => []]); // Devuelve un array vacío si no hay datos
        }
    }
}