<?php
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

require_once('./_assets/classes/code128.php');

class Income{
    public $twig;
    public $route;
    public DespachosModel $despachosModel;
    public GasolinerasModel $gasolinerasModel;
    public EstacionesModel $estacionesModel;
    public ClientesVehiculosModel $vehiclesModel;
    public ClientesModel $clientesModel;
    public InterlogicPaymentsModel $kioskos;
    public IngresosModel $ingresosModel;
    public ValesRModel $valesR;
    public DocumentosModel $documentosModel;

    /**
     * @param $twig
     */
    public function __construct($twig) {
        $this->despachosModel   = new DespachosModel;
        $this->gasolinerasModel = new GasolinerasModel;
        $this->estacionesModel  = new EstacionesModel;
        $this->vehiclesModel    = new ClientesVehiculosModel;
        $this->kioskos          = new InterlogicPaymentsModel;
        $this->ingresosModel    = new IngresosModel;
        $this->valesR           = new ValesRModel;
        $this->documentosModel  = new DocumentosModel;
        $this->clientesModel    = new ClientesModel;
        $this->twig             = $twig;
        $this->route            = 'views/income/';

    }

    /**
     * @return void
     * @throws Exception
     */
    public function duplicate_dispatches() : void {
        if (preg_match('/GET/i',$_SERVER['REQUEST_METHOD'])) {
            $from = $_GET['from'] ?? false;
            $until = $_GET['until'] ?? false;
            $interval = $_GET['interval'] ?? false;
            $codgas = $_GET['codgas'] ?? 0;
            $clientName = $_GET['clientName'] ?? false;
            $stations = $this->gasolinerasModel->get_stations();
            echo $this->twig->render($this->route . 'duplicate_dispatches.html', compact('from', 'until', 'interval', 'codgas', 'clientName', 'stations'));
        }
    }
    function cash_sales(){
        if (preg_match('/GET/i', $_SERVER['REQUEST_METHOD'])) {
            $stations = $this->gasolinerasModel->get_active_stations();
            echo $this->twig->render($this->route . 'cash_sales.html', compact('stations'));
        }
    }

    function dolar_sales() {
        echo $this->twig->render($this->route . 'dolar_sales.html');
    }

    public function clients(){
        if (preg_match('/GET/i', $_SERVER['REQUEST_METHOD'])) {
            echo $this->twig->render($this->route . 'clients.html');
        }
    }
    public function salesxcard(){
        if (preg_match('/GET/i', $_SERVER['REQUEST_METHOD'])) {
            echo $this->twig->render($this->route . 'salesxcard.html');
        }
    }
    public function cash_sales_table() {
        $data = [];
        $from = dateToInt($_POST['fromDate']);   // Asume que es un entero tipo fecha (e.g. 45747)
        $until = dateToInt($_POST['untilDate']);
        if ($ventas = $this->ingresosModel->get_cash_sales($from, $until, $_POST['codgas'])) {
            foreach ($ventas as $venta) {
                $data[] = array(
                    'Fecha'              => $venta['Fecha'],
                    'Gasolinera'           => $venta['Gasolinera'],
                    'Turno'              => $venta['Turno'],
                    'Mn'                 => round($venta['Mn'], 2),
                    'Dolares'            => round($venta['Dolares'], 2),
                    'Dolares2'            => round($venta['Dolares2'], 2),
                    'Morralla'           => round($venta['Morralla'], 2),
                    'Cheques'             => round($venta['Cheques'], 2),
                    'INTERL - Efectivo'  => round($venta['INTERL - Efectivo'], 2),
                );
            }
        }
    
        json_output(array("data" => $data));
    }

    public function clients_debit_table() {
        $data = [];
        if ($clients = $this->clientesModel->get_clients_debit($_POST['status'])) {
            foreach ($clients as $client) {
                $data[] = array(
                    'cod'    => $client['cod'],
                    'den'    => $client['den'],
                    'status' => $client['status'],
                    'status' => $client['status'],
                    'dom'    => $client['dom'],
                    'rfc'    => $client['rfc'],
                    'debsdo'    => $client['debsdo'],
                   
                );
            }
        }
    
        json_output(array("data" => $data));
    }
   

    /**
     * @return void
     * @throws Exception
     */
    function datatables_duplicate_dispatches() : void {

        $data = [];
        $interval = $_REQUEST['interval'] ?? false;
        $client = isset($_REQUEST['client']) && trim($_REQUEST['client']) !== '' ? trim($_REQUEST['client']) : 0;

        $from = $this->createDateTime($_REQUEST['from']);

        $until = $this->createDateTime($_REQUEST['until']);

        $dispatches[] = $this->despachosModel->sp_obtener_despachos_duplicados(dateToInt($from->format('Y-m-d')), dateToInt($until->format('Y-m-d')), $interval, $_GET['codgas'], $client);

        foreach ($dispatches as $despachos) {
            // Variable para almacenar el índice de la fila anterior que necesita ser actualizada
            $indiceFilaAnterior = null;
            foreach ($despachos as $indice => $despacho) {
                $data[] = array(
                    'Fecha'          => $despacho['Fecha'],
                    'Hora'  => date("H:i", strtotime($despacho['hora_formateada'])),
                    'Despacho'       => $despacho['Despacho'],
                    'codcliente'     => $despacho['codcli'],
                    'Cliente'        => $despacho['Cliente'],
                    'Tipo'           => $despacho['Tipo'],
                    'Placas'         => $despacho['Placas'],
                    'Tarjeta'        => $despacho['Tarjeta'],
                    'Grupo'          => $despacho['Grupo'],
                    'Descripcion'    => $despacho['Descripcion'],
                    'Cant despacho'  => $despacho['can'],
                    'Monto despacho' => $despacho['mto'],
                    'Forma pago'     => $despacho['Tipo'],
                    'Producto'       => $despacho['Producto'],
                    'Estación'       => $despacho['Estacion'],
                    'Bomba'          => $despacho['Bomba'],
                    'Check'          => $despacho['check'],
                );

                // Comprobar si el valor del campo "Check" en esta iteración es 1
                if ($despacho['check'] == 1) {
                    // Actualizar el valor del campo "Check" en la fila anterior si existe
                    if ($indiceFilaAnterior !== null) {
                        $data[$indiceFilaAnterior]['Check'] = 1;
                    }
                }

                // Actualizar el índice de la fila anterior con el índice actual para la siguiente iteración
                $indiceFilaAnterior = $indice;
            }
        }
        json_output(array("data" => $data));
    }

    /**
     * @param $dateString
     * @return DateTime|null
     */
    function createDateTime($dateString): ?DateTime
    {
        try {
            return new DateTime($dateString);
        } catch (Exception $e) {
            echo 'Se produjo un error al crear el objeto DateTime: ' . $e->getMessage();
            return null; // Otra opción es lanzar una nueva excepción aquí en lugar de devolver null.
        }
    }

    /**
     * @return void
     * @throws Exception
     */
    function credit_debit_dispatches() : void {
        $from = $_GET['from'] ?? false;
        $until = $_GET['until'] ?? false;
        $codgas = $_GET['codgas'] ?? false;
        $client_type = $_GET['client_type'] ?? 0;
        $stations = $this->gasolinerasModel->get_stations();
        $clientName = $_GET['clientName'] ?? false;
        echo $this->twig->render($this->route . 'credit_debit_dispatches.html', compact('stations', 'from', 'until', 'codgas', 'clientName', 'client_type'));
    }
    function relation_invoice_advance(){
        if (preg_match('/GET/i', $_SERVER['REQUEST_METHOD'])) {
            echo $this->twig->render($this->route . 'relation_invoice_advance.html');
        }
    }
    function relation_invoice_advance_table(){
         ini_set('memory_limit', '512M');
        set_time_limit(300);
        $data = [];
        $from = dateToInt($_POST['from']);
        $until = dateToInt($_POST['until']);
    
        if ($facturas = $this->documentosModel->relation_invoice_advance($from, $until)) {

            foreach ($facturas as $factura) {
                $data[] = array(
                    'fecha'             => $factura['fecha'],
                    'vigencia'          => $factura['vigencia'],
                    'vencimiento'       => $factura['vencimiento'],
                    'factura'           => $factura['factura'],
                    'factura_anticipo'  => $factura['factura_anticipo'],
                    'monto_aplicado'    => round($factura['monto_aplicado'],2),
                    'client'            => $factura['client'],
                    'UUID'              => $factura['UUID'],
                    'uid_anticipo'      => $factura['uid_anticipo'],
                    'monto_original'    => round($factura['monto_original'],2),
                    'txt_anticipo'      => $factura['txt_anticipo'],
                    'monto'              => round($factura['monto'],2),
                    'mtoiva'             => round($factura['mtoiva'],2),
                    'mto_fact_e'          => round($factura['mto_fact_e'],2),
                    'mto_iva_e'          => round($factura['mto_iva_e'],2),
                    'mto_total_e'          => round($factura['mto_total_e'],2),
                    // 'concepto_anticipo' => $factura['concepto_anticipo'],
                );
            }
        }
        json_output(array("data" => $data));
    }

    function cash_invoices(){
        if (preg_match('/GET/i', $_SERVER['REQUEST_METHOD'])) {
            echo $this->twig->render($this->route . 'cash_invoices.html');
        }
    }

    function cash_invoices_table(){

        
         ini_set('memory_limit', '512M');
        set_time_limit(300);
        $data = [];
        $from = dateToInt($_POST['from']);
        $until = dateToInt($_POST['until']);
    
        if ($despachos = $this->despachosModel->cash_invoices_advance($from, $until)) {

            foreach ($despachos as $despachos) {
                 $data[] = array(
                     'codcli'             => $despachos["codcli"],
                     'cliente'          => $despachos["den"],
                     'monto'       => $despachos["monto"],
                 );
            }
        }
       json_output(array("data" => $data));
    }
    function invoice_client_desp(){

        
         ini_set('memory_limit', '512M');
        set_time_limit(300);
        $data = [];
        $from = dateToInt($_POST['from']);
        $until = dateToInt($_POST['until']);
    
        if ($despachos = $this->despachosModel->invoice_client_desp($from, $until)) {
            foreach ($despachos as $despachos) {
                 $data[] = array(
                    'fecha'    => $despachos["fecha"],
                    'codcli'   => $despachos["codcli"],
                    'cliente'  => $despachos["den"],
                    'monto'      => $despachos["monto"],
                    'estacion' => $despachos["abr"],
                    'factura'   => $despachos["factura"],
                 );
            }
        }
       json_output(array("data" => $data));
    }


    function relation_credit_table(){
        $data = [];
        $from = dateToInt($_POST['from']);
        $until = dateToInt($_POST['until']);
    
        if ($facturas = $this->documentosModel->relation_credit_table($from, $until)) {
             echo '<pre>';
             var_dump($facturas);
            die();
            foreach ($facturas as $factura) {
                $data[] = array(
                    'fecha'             => $factura['fecha'],
                    'vigencia'          => $factura['vigencia'],
                    'vencimiento'       => $factura['vencimiento'],
                    'factura'           => $factura['factura'],
                    'factura_anticipo'  => $factura['factura_anticipo'],
                    'monto_aplicado'    => round($factura['monto_aplicado'],2),
                    'client'            => $factura['client'],
                    'UUID'              => $factura['UUID'],
                    'uid_anticipo'      => $factura['uid_anticipo'],
                    'monto_original'    => round(floatval($factura['monto_original']),2),
                    'txt_anticipo'      => $factura['txt_anticipo'],
                    'txt_note_credit' => $factura['txt_note_credit'],
                    'monto_iva' => $factura['monto_iva'],
                    'monto_sub' => $factura['monto_sub'],
                );
            }
        }
        json_output(array("data" => $data));
    }
    function dispatches_clients_credit(){
        if (preg_match('/GET/i', $_SERVER['REQUEST_METHOD'])) {
            echo $this->twig->render($this->route . 'dispatches_clients_credit.html');
        }
    }
    function dispatches_credit_client_table(){
        $data = [];
        $from = $this->createDateTime($_POST['from']);
        $until = $this->createDateTime($_POST['until']);
        if ($despachos = $this->despachosModel->get_credit_dispatches($from->format('d-m-Y'), $until->format('d-m-Y'))) {
            foreach ($despachos as $despacho) {
                $data[] = array(
                    'date'       => $despacho['date'],
                    'station'    => $despacho['station'],
                    'cod_client' => $despacho['cod_client'],
                    'client'     => $despacho['client'],
                    'product'    => $despacho['product'],
                    'dispatch'   => $despacho['dispatch'],
                    'import'     => $despacho['import'],
                    'series'     => $despacho['series'],
                    'nrofac'     => $despacho['nrofac'],
                    'can'        => $despacho['can'],
                   
                );
            }
        }
        json_output(array("data" => $data));
    }

    /**
     * @return void
     * @throws Exception
     */
    function datatables_credit_debit() : void {
        $data = [];
        $from = $this->createDateTime($_REQUEST['from']);
        $until = $this->createDateTime($_REQUEST['until']);
        $codgas = $_REQUEST['codgas'];
        $client_type = $_REQUEST['client_type'];
        $client = isset($_REQUEST['client']) && trim($_REQUEST['client']) !== '' ? trim($_REQUEST['client']) : '0';

        if ($despachos = $this->despachosModel->get_credit_and_debit_dispatches(dateToInt($from->format('Y-m-d')), dateToInt($until->format('Y-m-d')), $codgas, $client, $client_type)) {
            foreach ($despachos as $despacho) {
                $data[] = array(
                    'Fecha'          => $despacho['Fecha'],
                    'Hora'           => date("H:i", strtotime($despacho['hora_formateada'])),
                    'Despacho'       => $despacho['Despacho'],
                    'codcliente'     => $despacho['codcli'],
                    'Cliente'        => $despacho['Cliente'],
                    'Tipo'           => $despacho['Tipo'],
                    'Placas'         => $despacho['Placas'],
                    'Tarjeta'        => $despacho['Tarjeta'],
                    'Grupo'          => $despacho['Grupo'],
                    'Descripcion'    => $despacho['Descripcion'],
                    'Cant despacho'  => $despacho['can'],
                    'Monto despacho' => $despacho['mto'],
                    'Forma pago'     => $despacho['Tipo'],
                    'Producto'       => $despacho['Producto'],
                    'Estación'       => $despacho['Estacion'],
                    'Bomba'          => $despacho['Bomba'],
                    'Factura'        => $despacho['Factura'],
                    'UUID'           => $despacho['UUID'],
                    'RFC'            => $despacho['RFC']
                );
            }
        }
        json_output(array("data" => $data));
    }

    /**
     * @return void
     */
    function vehicles() : void {
        echo $this->twig->render($this->route . 'vehicles.html');
    }

    /**
     * @return void
     * @throws Exception
     */
    function datatables_vehicles() : void {
        $data = [];
        if ($vehicles = $this->vehiclesModel->get_vehicles()) {
            foreach ($vehicles as $vehicle) {
                $data[] = array(
                    'CodCliente'  => ((is_null($vehicle['codcli']) or empty(trim($vehicle['codcli']))) ? '<b class="text-danger">Sin CodCliente</b>' : trim($vehicle['codcli']) ),
                    'Cliente'     => ((is_null($vehicle['Cliente']) or empty(trim($vehicle['Cliente']))) ? '<b class="text-danger">Sin Nombre</b>' : trim($vehicle['Cliente']) ),
                    'Tarjeta'     => ((is_null($vehicle['tar']) or empty(trim($vehicle['tar']))) ? '<b class="text-danger">Sin Tarjeta</b>' : trim($vehicle['tar']) ),
                    'Placas'      => ((is_null($vehicle['plc']) or empty(trim($vehicle['plc']))) ? '<b class="text-danger">Sin Placas</b>' : trim($vehicle['plc']) ),
                    'Económico'   => ((is_null($vehicle['nroeco']) or empty($vehicle['nroeco'])) ? '<b class="text-danger">Sin # Económico</b>' : trim($vehicle['nroeco']) ),
                    'Vehículo'    => $vehicle['nroveh'],
                    'Grupo'       => $vehicle['grp'],
                    'Descripcion' => $vehicle['den'],
                    'Status'      => $vehicle['est'],
                );
            }
        }
        json_output(array("data" => $data));
    }

    function kioskos() {
        if (preg_match('/GET/i',$_SERVER['REQUEST_METHOD'])){
            $from = isset($_GET['from']) ? $_GET['from'] : date('Y-m-d', strtotime('-1 day'));
            $until = isset($_GET['until']) ? $_GET['until'] : date('Y-m-d', strtotime('-1 day'));
            echo $this->twig->render($this->route . 'kioskos.html', compact('from', 'until'));
        } else {
            $from = $_POST['from'] ?? false;
            $until = $_POST['until'] ?? false;
            echo $this->twig->render($this->route . 'kioskos.html', compact('from', 'until'));
        }
    }

    function datatables_kioskos() {
        $data = [];
        $from = $_POST['from'] ?? false;
        $until = $_POST['until'] ?? false;
        if ($registers = $this->kioskos->get_rows($from, $until)) {
            foreach ($registers AS $register) {
                $actions = '<a href="javascript:void(0);" data-bs-toggle="modal" data-bs-target="#ticketModal" data-id="'. $register['id'] .'" class="btn btn-info btn-sm"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-printer align-middle me-2"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg> Ticket</a>';
                $data[] = array(
                    'FECHA'         => $register['fecha'],
                    'HORA'          => date('H:i:s', strtotime($register['hora'])),
                    'NO_DESPACHO'   => $register['numDespacho'],
                    'IMPORTE'       => $register['totalVenta'],
                    'REF_BANCARIA'  => $register['Referencia'],
                    'NO_TARJETA'    => $register['no_tarjeta'],
                    'AUTORIZACION'  => $register['Autorizacion'],
                    'AFI_BANCARIA'  => $register['afiliacion_bancaria'],
                    'ACCIONES'      => $actions
                );
            }
        }
        json_output(array("data" => $data));
    }

    function get_voucher($id) {
        $voucher = $this->kioskos->get_voucher($id);
        json_output(array("voucher" => $voucher['voucher_tarjeta'], "despacho" => $voucher['numDespacho']));
    }

//    Desarrollo del día 2024-03-06
    function diffs() : void {
        $from = $_GET['from'] ?? false;
        $until = $_GET['until'] ?? false;
        $codgas = $_GET['codgas'] ?? false;
        $stations = $this->gasolinerasModel->get_stations();
        echo $this->twig->render($this->route . 'diffs.html', compact('from', 'until', 'codgas', 'stations'));
    }

    function datatables_diffs($from, $until, $codgas) : void {
        $data = [];
        if ($rows = $this->despachosModel->sp_obtener_diferencias_por_valor(dateToInt($from), dateToInt($until), $codgas)) {
            foreach ($rows as $diff) {
                $actions = '<a href="/income/diff_analisys/'. $diff['fch'] .'/'. $diff['codgas'] .'/'. round($diff['totalCorte'], 2) .'/'. round($diff['totalDespachado'], 2) .'/'. round($diff['totalValesR'], 2) .'/'. $diff['totalDiff'] .'"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-eye align-middle me-2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg></a>';
                $data[] = array(
                    'FECHA'         => $diff['Fecha'],
                    'ESTACION'      => $diff['Gasolinera'],
                    'TOTALCORTE'    => round($diff['totalCorte'], 2),
                    'TOTALDESPACHOS'=> round($diff['totalDespachado'], 2),
                    'TOTALCONSUMOS' => round($diff['totalValesR'], 2),
                    'DIFERENCIA'    => $diff['totalDiff'],
                    'ACCIONES'      => $actions
                );
            }
        }
        json_output(array("data" => $data));
    }

    function diff_analisys($fch, $codgas, $totalCorte, $totalDespachado, $totalValesR, $real_diff) : void {
        $fecha = intToDate($fch);
        
        $station = str_replace(' ', '_', $this->gasolinerasModel->get_station_by_code($codgas)[0]['abr']);

        echo $this->twig->render($this->route . 'diff_analysis.html', compact(  'fch', 'codgas', 'totalCorte', 'totalDespachado', 'totalValesR', 'real_diff', 'fecha', 'station'));
    }

    function datatables_diff_analysis($fch, $codgas)
    {
        $data = [];
        if ($dispatches = $this->despachosModel->get_mark_dispatches_by_island_shift($fch, $codgas)) {
            foreach ($dispatches as $dispatch) {

                $factura = get_invoice_series($dispatch['Factura']);
                $data[] = array(
                    'DESPACHO'     => $dispatch['Despacho'],
                    'HORA'         => date("H:i", strtotime($dispatch['Hora'])),
                    'CLIENTE'      => $dispatch['Cliente'],
                    'TIPO'         => $dispatch['Tipo'],
                    'TARJETA'      => $dispatch['tar'],
                    'PRODUCTO'     => $dispatch['Producto'],
                    'FACTURA'      => $factura,
                    'PRECIO'       => number_format($dispatch['Precio'], 2),
                    'MONTO'        => $dispatch['Monto'],
                    'DATOS'        => (empty($dispatch['Valor']) ? 'N/A' : $dispatch['Valor']),
                    'TURNO'        => $dispatch['turno'],
                    'ISLA'        => $dispatch['Isla'],
                    'FECHA'        => $dispatch['Fecha'],
                    'ESTACIÓN'        => $dispatch['Estacion'],
                    'COINCIDENCIA' => ($dispatch['CoincidenciaEncontrada'] == 1 ? '-SI-' : '-NO-')
                );
            }
        }
        json_output(array("data" => $data));
    }

    function datatables_consumes($fch, $codgas)
    {
        $data = [];
        if ($dispatches = $this->valesR->get_consumes_by_island_shift($fch, $codgas)) {
            foreach ($dispatches as $dispatch) {
                $factura = get_invoice_series($dispatch['Factura']);
                $data[] = array(
                    'DESPACHO'     => abs($dispatch['sec']),
                    'TURNO'        => $dispatch['turno'],
                    'CLIENTE'      => $dispatch['Cliente'],
                    'TIPO'         => $dispatch['Tipo'],
                    'PRODUCTO'     => $dispatch['Producto'],
                    'FACTURA'      => $factura,
                    'PRECIO'       => number_format($dispatch['Precio'], 2),
                    'MONTO'        => $dispatch['Monto'],
                    'COINCIDENCIA' => ($dispatch['CoincidenciaEncontrada'] == 1 ? '-SI-' : '-NO-'),
                );
            }
        }
        json_output(array("data" => $data));
    }

    function pending_dispatches() : void {
        if (preg_match('/GET/i',$_SERVER['REQUEST_METHOD'])){
            $from = $_GET['from'] ?? false;
            $until = $_GET['until'] ?? false;
            $type = $_GET['type'] ?? false;
            $status = $_GET['status'] ?? false;
            echo $this->twig->render($this->route . 'pending_dispatches.html', compact('from', 'until', 'type', 'status'));
        }
    }

    function datatables_pending_dispatches_for_invoice($from, $until, $type, $status) : void {
        $data = [];
        if ($dispatches = $this->despachosModel->get_pending_dispatches_for_invoice(dateToInt($from),dateToInt($until), $type, $status)) {
            foreach ($dispatches as $dispatch) {
                $data[] = array(
                    'FECHA'     => $dispatch['Fecha'],
                    'DESPACHO'  => $dispatch['nrotrn'],
                    'ESTACIÓN'  => $dispatch['Estacion'],
                    'PRODUCTO'  => $dispatch['Producto'],
                    'CANTIDAD'  => $dispatch['Volumen'],
                    'MONTO'     => $dispatch['Monto'],
                    'CODCLIENTE' => $dispatch['codcli'],
                    'CLIENTE'   => $dispatch['Cliente'],
                    'TIPO'      => $dispatch['Tipo'],
                    'FACTURA'   => $dispatch['Factura'],
                    'UUID'      => $dispatch['UUID'],
                );
            }
        }
        json_output(array("data" => $data));
    }
    function invoice_unstamped(){
        $stations = $this->gasolinerasModel->get_active_stations();
        if (preg_match('/GET/i', $_SERVER['REQUEST_METHOD'])) {
            echo $this->twig->render($this->route . 'invoice_unstamped.html', compact('stations'));
        }
    }
    function invoiced_dispatched(){
        $stations = $this->gasolinerasModel->get_active_stations();
        if (preg_match('/GET/i', $_SERVER['REQUEST_METHOD'])) {
            echo $this->twig->render($this->route . 'invoiced_dispatched.html', compact('stations'));
        }
    }
    function overall_invoice(){
        $stations = $this->gasolinerasModel->get_active_stations();
        if (preg_match('/GET/i', $_SERVER['REQUEST_METHOD'])) {
            echo $this->twig->render($this->route . 'overall_invoice.html', compact('stations'));
        }
    }


    /**
     * @throws Exception
     */
    function control_dispatches() : void {
        $stations = $this->gasolinerasModel->get_active_stations();
        // $stations = array_filter($stations, fn($station) => !in_array($station['cod'], [ 20]));
        if (preg_match('/GET/i', $_SERVER['REQUEST_METHOD'])) {
            echo $this->twig->render($this->route . 'control_dispatches.html', compact('stations'));

        } else {

            $from = $_POST['from'] ?? date('Y-m-d');
            $until = $_POST['until'] ?? date('Y-m-d');
            $codgas = $_POST['codgas'] == "" ? 0 : $_POST['codgas'] ;
            echo $this->twig->render($this->route . 'control_dispatches.html', compact('from', 'until', 'codgas', 'stations'));

        }
    }

    function overal_invoice_out_table(){
        ini_set('memory_limit', '512M'); // o más si lo necesitas, como '1024M'
        set_time_limit(300); // 300 segundos = 5 minutos. Puedes subirlo más si hace falta.
        $data = [];
        $codgas = $_POST['codgas'];
        $status = $_POST['status'];
        $estations= $this->gasolinerasModel->get_estations_servidor();
        if ($codgas != 0) {
            // Filtrar estaciones para quedarse solo con la que coincide con el codgas
            $estations = array_filter($estations, function($station) use ($codgas) {
                return $station['codigo'] == $codgas;
            });
        }
        if ($invoices = $this->despachosModel->overal_invoice_out_table(dateToInt($_POST['from']), dateToInt($_POST['until']), $estations, $status)) {
            foreach ($invoices as $invoice) {
                $fechasConcatenadas = explode(', ', $invoice['FechasConcatenadas']);  // Convierte las fechas concatenadas en un arreglo
                $fechaFactura = $invoice['vigencia'];  // Suponiendo que 'vigencia' es una fecha en formato 'YYYY-MM-DD'
                $fechasConColor = '';
                foreach ($fechasConcatenadas as $fecha) {
                    if (date('Y-m', strtotime($fecha)) !== date('Y-m', strtotime($fechaFactura))) {
                        $colorClass = 'fecha-roja';
                    } else {
                        $colorClass = 'fecha-normal';
                    }
                    $fechasConColor .= '<span class=" ' . $colorClass . '">' . $fecha . '</span> <br>';
                }
                $data[] = array(
                    'nro'                => $invoice['nro'],
                    'factura'            => $invoice['factura'],
                    'satuid'             => $invoice['satuid'],
                    'tip'                => $invoice['tip'],
                    'fecha'              => $invoice['fecha'],
                    'vigencia'           => $invoice['vigencia'],
                    'FechasConcatenadas' => $fechasConColor,  // Cadena HTML con fechas únicas
                    // 'FechasConcatenadas' => $invoice['FechasConcatenadas'],  // Cadena HTML con fechas únicas
                    'txtref'             => $invoice['txtref'],
                    'TipoPago'           => $invoice['TipoPago'],
                    'NrotrnConcatenados' => $invoice['NrotrnConcatenados'] ,
                    'estacion'           => $invoice['estacion'],
                    'estado'           => $invoice['estado'],
                    'estacion'           => $invoice['estacion']
                );
            }
        }
        json_output(array("data" => $data));
    }

    function datatables_dispatches() : void {
        ini_set('memory_limit', '512M');
        set_time_limit(300);
        $data = [];
        $codgas = $_POST['codgas'];
        $billed = $_POST['billed'];
        $tipo_cliente=0;

        if ($dispatches = $this->despachosModel->control_dispatches2(dateToInt($_POST['from']), dateToInt($_POST['until']), $codgas,$_POST['uuid'],$tipo_cliente,$billed)) {
            foreach ($dispatches as $dispatch) {
                $data[] = array(
                   'fecha'                    => $dispatch['fecha'],
                    'hora_formateada'         => date("H:i", strtotime($dispatch['hora_formateada'])),
                    'turno'                   => $dispatch['turno'],
                    'despacho'                => $dispatch['despacho'],
                    'producto'                => $dispatch['producto'],
                    'estacion'                => $dispatch['estacion'],
                    'empresa'                 => $dispatch['empresa'],
                    'cliente_des'             => $dispatch['cliente_des'],
                    'cliente_fac'             => $dispatch['cliente_fac']??$dispatch['cliente_des'],
                    'FechaFactura'                => $dispatch['FechaFactura'],
                    'cantidad'                => $dispatch['cantidad'],
                    'importe'                 => $dispatch['importe'],
                    'precio'                  => $dispatch['precio'],
                    'despachador'             => $dispatch['despachador'],
                    'factura'                 => $dispatch['factura']??$dispatch['factura_desp'],
                    'UUID'                    => $dispatch['UUID']??".",
                    'rut'                     => $dispatch['rut'],
                    'txtref'                  => $dispatch['txtref'],
                    'denominacion'            => $dispatch['denominacion'],
                    'codigo_cliente'          => ($dispatch['codigo_cliente'] < 0 ? "" : $dispatch['codigo_cliente']),
                    'codval'                  => $dispatch['codval'],
                    'tipo_cliente'            => $dispatch['tipo_cliente'],
                    'tipo_cliente_aplicativo' => $dispatch['tipo_cliente_aplicativo'],
                    'vehiculo'                => $dispatch['vehiculo'],
                    'placas'                  => $dispatch['placas'],
                    'tipo_pago'               => $dispatch['tipo_pago']??$dispatch['tipo_pago_despacho'],
                    'tipo_pago_despacho'      => $dispatch['tipo_pago_despacho'],
                );
            }
        }
        json_output(array("data" => $data));
    }

    function datatables_dispatches_est() : void {
        ini_set('max_execution_time', 5000);
        ini_set('memory_limit', '1024M');
        set_time_limit(0); // sin límite
        $data = [];
        $codgas = $_POST['codgas'];
        $billed = $_POST['billed'];
        $tipo_cliente=0;
        $estation= $this->gasolinerasModel->get_estations_servidor_cod_gas($codgas);
       

        $postData = [
            'from' => dateToInt($_POST['from']),
            'until' => dateToInt($_POST['until']),
            'codgas' => $codgas,
            'uuid' => $_POST['uuid'],
            'tipo_cliente' => $tipo_cliente,
            'billed' => $billed,
            'estation' => $estation,
        ];
        $ch = curl_init('http://192.168.0.3:388/api/control_despachos/getDispatches');
        curl_setopt($ch, CURLOPT_TIMEOUT, 300); // Espera máxima de 5 minutos
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10); // Espera para establecer conexión
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_POST, true);   

        // Ejecutar y obtener respuesta
        $response = curl_exec($ch);
        curl_close($ch);

        // $apiData = json_decode($response, true);
        if ($apiData = json_decode($response, true)) {
            
            foreach ($apiData['data'] as $dispatch) {
                $data[] = array(
                   'fecha'                    => $dispatch['fecha'],
                    'hora_formateada'         => date("H:i", strtotime($dispatch['hora_formateada'])),
                    'turno'                   => $dispatch['turno'],
                    'despacho'                => $dispatch['despacho'],
                    'producto'                => $dispatch['producto'],
                    'estacion'                => $dispatch['estacion'],
                    'empresa'                 => $dispatch['empresa'],
                    'cliente_des'             => $dispatch['cliente_des'],
                    'cliente_fac'             => $dispatch['cliente_fac']??$dispatch['cliente_des'],
                    'FechaFactura'            => $dispatch['FechaFactura'],
                    'cantidad'                => $dispatch['cantidad'],
                    'importe'                 => $dispatch['importe'],
                    'precio'                  => $dispatch['precio'],
                    'despachador'             => $dispatch['despachador'],
                    'factura'                 => $dispatch['factura']??$dispatch['factura_desp'],
                    'UUID'                    => $dispatch['UUID']??".",
                    'rut'                     => $dispatch['rut'],
                    'rut'                     => $dispatch['rut'],
                    'txtref'                  => $dispatch['txtref'],
                    'denominacion'            => $dispatch['denominacion'],
                    'codigo_cliente'          => ($dispatch['codigo_cliente'] < 0 ? "" : $dispatch['codigo_cliente']),
                    'codval'                  => $dispatch['codval'],
                    'tipo_cliente'            => $dispatch['tipo_cliente'],
                    'tipo_cliente_aplicativo' => $dispatch['tipo_cliente_aplicativo'],
                    'vehiculo'                => $dispatch['vehiculo'],
                    'placas'                  => $dispatch['placas'],
                    'tipo_pago'               => $dispatch['tipo_pago']??$dispatch['tipo_pago_despacho'],
                    'tipo_pago_despacho'      => $dispatch['tipo_pago_despacho'],
                );
            }
        }
        json_output(array("data" => $data));
    }

   

    function pivot_daily_dispatches_table() : void {
        $data = [];
        $dates = [];
        $codgas = $_POST['codgas'];
        if ($dispatches = $this->despachosModel->pivot_daily_dispatches_table(dateToInt($_POST['from']), dateToInt($_POST['until']), $codgas)) {
            foreach ($dispatches as $dispatch) {
                $estacion = $dispatch['estacion'];
                $codgas = $dispatch['codgas'];
                $fecha = $dispatch['fecha'];
                if (!isset($data[$estacion])) { $data[$estacion] = ['estacion' => $estacion]; } // Si la estación no existe en el array, inicialízala
                if (!in_array($fecha, $dates)) { $dates[] = $fecha; } // Guardar las fechas para crear dinámicamente las columnas
                $factura_global_value = number_format($dispatch['factura_global'], 2, '.', ',');
                $factura_global_class = ($dispatch['factura_global'] == null || $dispatch['factura_global'] == 0) ? 'bg-danger text-white' : '';
                $data[$estacion][$fecha . '_cliente_credito'] = '<a href="javascript:void(0);" onClick="DispachesTypeModal(\''. $dispatch['fecha'] .'\',\''.$dispatch['codgas'].'\',\'cliente_credito\' )">'. number_format($dispatch['cliente_credito'], 2, '.', ','). '<a>';
                $data[$estacion][$fecha . '_cliente_debito']  = '<a href="javascript:void(0);" onClick="DispachesTypeModal(\''. $dispatch['fecha'] .'\',\''.$dispatch['codgas'].'\',\'cliente_debito\' )">'. number_format($dispatch['cliente_debito'], 2, '.', ','). '<a>';
                $data[$estacion][$fecha . '_monedero']        = '<a href="javascript:void(0);" onClick="DispachesTypeModal(\''. $dispatch['fecha'] .'\',\''.$dispatch['codgas'].'\',\'monedero\' )">'. number_format($dispatch['monedero'], 2, '.', ','). '<a>';
                $data[$estacion][$fecha . '_contado']         = '<a href="javascript:void(0);" onClick="DispachesTypeModal(\''. $dispatch['fecha'] .'\',\''.$dispatch['codgas'].'\',\'contado\' )">'. number_format($dispatch['contado'], 2, '.', ','). '<a>';
                $data[$estacion][$fecha . '_factura_global']  = '<a href="javascript:void(0);" onClick="DispachesTypeModal(\''. $dispatch['fecha'] .'\',\''.$dispatch['codgas'].'\',\'factura_global\' )" class="'.$factura_global_class.'">'. $factura_global_value . '<a>';
                $data[$estacion][$fecha . '_NA']              = '<a href="javascript:void(0);" onClick="DispachesTypeModal(\''. $dispatch['fecha'] .'\',\''.$dispatch['codgas'].'\',\'N/A\' )">'. number_format($dispatch['N/A'], 2, '.', ','). '<a>';
            }
        }
        // Enviar la respuesta en formato JSON
        json_output([
            "data" => array_values($data),  // Convierte los datos en un array de valores
            "dates" => $dates  // Devuelve el array de fechas para las columnas dinámicas
        ]);
    }


    function pivot_facturacion_diaria_table(){
        $data = [];
        $codgas = $_POST['codgas'];
        $estations= $this->gasolinerasModel->get_estations_servidor();
        if ($codgas != 0) {
            // Filtrar estaciones para quedarse solo con la que coincide con el codgas
            $estations = array_filter($estations, function($station) use ($codgas) {
                return $station['codigo'] == $codgas;
            });
        }
        // $estations = array_filter($estations, function($station) {
        //     return self::checkServerConnectivity($station['servidor']);
        // });

        if ($dispatches = $this->despachosModel->pivot_facturacion_diaria_table(dateToInt($_POST['from']), dateToInt($_POST['until']),$_POST['from'],$_POST['until'],$estations)) {


            foreach ($dispatches as $dispatch) {
                $data[] = array(
                    'fecha'         => $dispatch['fecha'],
                    'lerdo'         => self::format_value(isset($dispatch['02_LERDO']) ? $dispatch['02_LERDO'] : 0),
                    'delicias'      => self::format_value(isset($dispatch['03_DELICIAS']) ? $dispatch['03_DELICIAS'] : 0),
                    'parral'        => self::format_value(isset($dispatch['04_PARRAL']) ? $dispatch['04_PARRAL'] : 0),
                    'lopez_mateos'  => self::format_value(isset($dispatch['05_LOPEZ_MATEOS']) ? $dispatch['05_LOPEZ_MATEOS'] : 0),
                    'gemela_chica'  => self::format_value(isset($dispatch['06_GEMELA_CHICA']) ? $dispatch['06_GEMELA_CHICA'] : 0),
                    'gemel_grande'  => self::format_value(isset($dispatch['07_GEMEL_GRANDE']) ? $dispatch['07_GEMEL_GRANDE'] : 0),
                    'plutarco'      => self::format_value(isset($dispatch['08_PLUTARCO']) ? $dispatch['08_PLUTARCO'] : 0),
                    'mpio_libre'    => self::format_value(isset($dispatch['09_MPIO._LIBRE']) ? $dispatch['09_MPIO._LIBRE'] : 0),
                    'aztecas'       => self::format_value(isset($dispatch['10_AZTECAS']) ? $dispatch['10_AZTECAS'] : 0),
                    'misiones'      => self::format_value(isset($dispatch['11_MISIONES']) ? $dispatch['11_MISIONES'] : 0),
                    'pto_de_palos'  => self::format_value(isset($dispatch['12_PTO_DE_PALOS']) ? $dispatch['12_PTO_DE_PALOS'] : 0),
                    'miguel_d_mad'  => self::format_value(isset($dispatch['13_MIGUEL_D_MAD']) ? $dispatch['13_MIGUEL_D_MAD'] : 0),
                    'permuta'       => self::format_value(isset($dispatch['14_PERMUTA']) ? $dispatch['14_PERMUTA'] : 0),
                    'electrolux'    => self::format_value(isset($dispatch['15_ELECTROLUX']) ? $dispatch['15_ELECTROLUX'] : 0),
                    'aeronautica'   => self::format_value(isset($dispatch['16_AERONAUTICA']) ? $dispatch['16_AERONAUTICA'] : 0),
                    'custodia'      => self::format_value(isset($dispatch['17_CUSTODIA']) ? $dispatch['17_CUSTODIA'] : 0),
                    'anapra'        => self::format_value(isset($dispatch['18_ANAPRA']) ? $dispatch['18_ANAPRA'] : 0),
                    'independenci'  => self::format_value(isset($dispatch['19_INDEPENDENCI']) ? $dispatch['19_INDEPENDENCI'] : 0),
                    'tecnologico'   => self::format_value(isset($dispatch['20_TECNOLOGICO']) ? $dispatch['20_TECNOLOGICO'] : 0),
                    'ejercito_nal'  => self::format_value(isset($dispatch['21_EJERCITO_NAL']) ? $dispatch['21_EJERCITO_NAL'] : 0),
                    'satellite'     => self::format_value(isset($dispatch['22_SATELITE']) ? $dispatch['22_SATELITE'] : 0),
                    'las_fuentes'   => self::format_value(isset($dispatch['23_LAS_FUENTES']) ? $dispatch['23_LAS_FUENTES'] : 0),
                    'clara'         => self::format_value(isset($dispatch['24_CLARA']) ? $dispatch['24_CLARA'] : 0),
                    'solis'         => self::format_value(isset($dispatch['25_SOLIS']) ? $dispatch['25_SOLIS'] : 0),
                    'santiago_tro'  => self::format_value(isset($dispatch['26_SANTIAGO_TRO']) ? $dispatch['26_SANTIAGO_TRO'] : 0),
                    'jarudo'        => self::format_value(isset($dispatch['27_JARUDO']) ? $dispatch['27_JARUDO'] : 0),
                    'hermanos_esc'  => self::format_value(isset($dispatch['28_HERMANOS_ESC']) ? $dispatch['28_HERMANOS_ESC'] : 0),
                    'villa_ahumad'  => self::format_value(isset($dispatch['29_VILLA_AHUMAD']) ? $dispatch['29_VILLA_AHUMAD'] : 0),
                    'el_castano'    => self::format_value(isset($dispatch['30_EL_CASTAÑO']) ? $dispatch['30_EL_CASTAÑO'] : 0),
                    'travel_cente'  => self::format_value(isset($dispatch['31_TRAVEL_CENTE']) ? $dispatch['31_TRAVEL_CENTE'] : 0),
                    'picachos'      => self::format_value(isset($dispatch['32_PICACHOS']) ? $dispatch['32_PICACHOS'] : 0),
                    'ventanas'      => self::format_value(isset($dispatch['33_VENTANAS']) ? $dispatch['33_VENTANAS'] : 0),
                    'san_rafael'    => self::format_value(isset($dispatch['34_SAN_RAFAEL']) ? $dispatch['34_SAN_RAFAEL'] : 0),
                    'puertcito'     => self::format_value(isset($dispatch['35_PUERTECITO']) ? $dispatch['35_PUERTECITO'] : 0),
                );
            }
        }
        json_output(array("data" => $data));
    }
    function format_value($value) {
        if ($value == 0) {
            return '<span class="text-danger  text-end p-1">' . number_format($value, 2, '.', ',') . '</span>';
        }
        return '<span class="text-end">' . number_format($value, 2, '.', ',') . '</span>';
    }
    function pivot_dispatches_table() : void {
        $data = [];
        $codgas = $_POST['codgas'];
        if ($dispatches = $this->despachosModel->pivot_dispatches(dateToInt($_POST['from']), dateToInt($_POST['until']), $codgas)) {
            foreach ($dispatches as $dispatch) {
                $data[] = array(
                   'estacion'            => $dispatch['estacion'],
                    'cliente_credito'    => $dispatch['cliente_credito'],
                    'cliente_debito'     => $dispatch['cliente_debito'],
                    'monedero'           => $dispatch['monedero'],
                    'contado'            => $dispatch['contado'],
                    'factura_global'     => $dispatch['factura_global'],
                    'N_A'                => $dispatch['N/A'],
                    'total'               => $dispatch['total'],
                );
            }
        }

        echo json_encode(array("data" => $data));

    }


    function datatables_dispatches_invoiced() : void {
        $data = [];
        $codgas = $_POST['codgas'];
        $billed = $_POST['billed'];
        $tipo_cliente=0;


        if ($dispatches = $this->despachosModel->control_dispatches_invoiced(dateToInt($_POST['from']), dateToInt($_POST['until']), $codgas,$_POST['uuid'],$tipo_cliente,$billed)) {

            foreach ($dispatches as $dispatch) {
                $data[] = array(
                   'fecha'                    => $dispatch['fecha'],
                    'hora_formateada'         => date("H:i", strtotime($dispatch['hora_formateada'])),
                    'turno'                   => $dispatch['turno'],
                    'despacho'                => $dispatch['despacho'],
                    'producto'                => $dispatch['producto'],
                    'estacion'                => $dispatch['estacion'],
                    'empresa'                 => $dispatch['empresa'],
                    'cliente_des'             => $dispatch['cliente_des'],
                    'cliente_fac'             => $dispatch['cliente_fac']??$dispatch['cliente_des'],
                    'cantidad'                => $dispatch['cantidad'],
                    'importe'                 => $dispatch['importe'],
                    'precio'                  => $dispatch['precio'],
                    'UUID_sat'                => $dispatch['UUID_sat'],
                    'FechaTimbrado'           => $dispatch['FechaTimbrado'],
                    'factura'                 => $dispatch['factura']??$dispatch['factura_desp'],
                    'UUID'                    => $dispatch['UUID']??".",
                    'rut'                     => $dispatch['rut'],
                    'txtref'                  => $dispatch['txtref'],
                    'denominacion'            => $dispatch['denominacion'],
                    'codigo_cliente'          => ($dispatch['codigo_cliente'] < 0 ? "" : $dispatch['codigo_cliente']),
                    'codval'                  => $dispatch['codval'],
                    'tipo_cliente'            => $dispatch['tipo_cliente'],
                    'tipo_cliente_aplicativo' => $dispatch['tipo_cliente_aplicativo'],
                    'tipo_pago'               => $dispatch['tipo_pago']??$dispatch['tipo_pago_despacho'],
                    'tipo_pago_despacho'      => $dispatch['tipo_pago_despacho'],
                );
            }
        }
        json_output(array("data" => $data));
    }

    function DispachesTypeModal(){

        $codgas = $_POST['codgas'];
        $from = $_POST['fecha'];
        $until = $_POST['fecha'];
        $tipo_cliente = $_POST['tipo_client'];
        $uuid = 0;
        $billed=0;
        $dispatches = $this->despachosModel->control_dispatches2(dateToInt($from), dateToInt($until), $codgas,$uuid,$tipo_cliente,$billed);
        echo $this->twig->render($this->route . 'modals/dispaches_modal.html', compact('dispatches'));

    }

    function checking_tickets() :void {
        // Vamos a comprobar si $_GET['from'] y $_GET['codgas'] están definidos
        if (isset($_GET['from'])) {
            $from = $_GET['from'];
        } else {
            // Si no hay fecha definida, vamos a tomar la fecha actual y restarle un dia
            $from = date('Y-m-d', strtotime(date('Y-m-d') . ' -1 day'));
        }
        $codgas = $_GET['codgas'] ?? 0;
        $shift = $_GET['shift'] ?? 0;
        $dispatch_type = $_GET['dispatch_type'] ?? 'Crédito';

        $stations = $this->gasolinerasModel->get_active_station_TG();

        echo $this->twig->render($this->route . 'checking_tickets.html', compact('stations', 'from', 'codgas', 'shift', 'dispatch_type'));
    }

    function print_labels() {
        if (preg_match('/GET/i',$_SERVER['REQUEST_METHOD'])) {
            echo $this->twig->render($this->route . 'print_labels.html');
        } else {
            $station = $_POST['station'];
            $from = $_POST['from'];
            $until = $_POST['until'];
            $barcode = '';

            // Crear una instancia de FPDF
            $pdf = new PDF_Code128();

            // Establecer los márgenes
            $pdf->SetMargins(3, 3, 3);  // Margen izquierdo, margen superior, margen derecho

            // Establecer el margen inferior
            $pdf->SetAutoPageBreak(true, 5);  // Activar los saltos automáticos de página y establecer el margen inferior a 5 mm

            // Creamos un ciclo for
            for ($i = $from; $i <= $until; $i++) {
                $barcode = $station . '-' . $i + 10000;
                // Establecer el tamaño de la página en milimetros (Ancho x Alto)
                $pdf->AddPage('L', array(51, 36));

                // Establecer el tamaño de la letra y el tipo de letra
                $pdf->SetFont('Arial', 'B', 7);

                // Logo
                $pdf->SetXY(3, 3);
                $pdf->multiCell(23, 8, '', 0, 'C');
                $pdf->Image($_SERVER['DOCUMENT_ROOT'] . '/_assets/images/logo BN.jpg', 3.5, 3.5, 20, 6);

                $pdf->Code128(3, 13, $barcode, 45, 12);
                // Vamos a agregar el folio del ticket en la parte de abajo del código de barras
                $pdf->SetXY(3, 25);
                $pdf->Cell(45, 5, $barcode, 0, 0, 'C');
            }
            $pdf->Output();
        }
    }

    function all_dispatches_table($from, $codgas, $shift, $dispatch_type) : void {

        if ($dispatch_type == 'dbito') {
            $dispatches = $this->despachosModel->get_debit_dispatches_to_release($from, $codgas, $shift);
        } elseif ($dispatch_type == 'crdito') {
            $dispatches = $this->despachosModel->get_credit_dispatches_to_release($from, $codgas, $shift);
        } elseif ($dispatch_type == 'payworks') {
            $dispatches = $this->despachosModel->get_payworks_dispatches_to_release($codgas, dateToInt($from), $shift);
        }

        $data = [];
        if ($dispatches) {
            foreach ($dispatches as $dispatch) {
                $actions = '';
                if ($dispatch['Verificador'] != 'Sin verificar') {
                    $actions .= '<a href="javascript:void(0);" class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#notesModal" data-id="'. $dispatch['id'] .'" data-despacho="'. $dispatch['Despacho'] .'" data-estacion="'. $dispatch['Estacion'] .'" data-comentario="'. $dispatch['notes'] .'"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-feather align-middle"><path d="M20.24 12.24a6 6 0 0 0-8.49-8.49L5 10.5V19h8.5z"></path><line x1="16" y1="8" x2="2" y2="22"></line><line x1="17.5" y1="15" x2="9" y2="15"></line></svg> Notas</a>';
                }
                $data[] = array(
                    'DESPACHO'     => $dispatch['Despacho'],
                    'ESTACION'     => $dispatch['Estacion'],
                    'ISLA'         => $dispatch['Isla'],
                    'CODCLIENTE'   => $dispatch['codcli'],
                    'CLIENTE'      => $dispatch['Cliente'],
                    'VOLUMEN'      => $dispatch['Volumen'],
                    'MONTO'        => $dispatch['Monto'],
                    'TIPO'         => $dispatch['Tipo'],
                    'TURNO'        => $dispatch['turno'],
                    'FECHA'        => $from . ' ' . $dispatch['hora_formateada'],
                    'PRODUCTO'     => trim($dispatch['Producto']),
                    'STATUS'       => trim($dispatch['Verificador']),
                    'COMENTARIO'   => trim($dispatch['notes']),
                    'INCIDENCIA'   => $dispatch['incidencia'],
                    'CASOESPECIAL' => ((($dispatch['rut'] == '' || $dispatch['rut'] == null) AND $dispatch['nroveh'] > 0 ) ? 0 : 1),
                    'ACCIONES'     => $actions
                );
            }
        }
        json_output(array("data" => $data));
    }

    function checked_dispatches_table($from, $codgas, $shift) {
        $data = [];
        if ($dispatches = $this->despachosModel->get_credit_and_debit_dispatches_released($from, $codgas, $shift, $_GET['dispatch_type'])) {
            foreach ($dispatches as $dispatch) {
                $actions = '';
                if ($dispatch['Verificador'] != 'Sin verificar') {
                    $actions .= '<a href="javascript:void(0);" class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#notesModal" data-id="'. $dispatch['id'] .'" data-despacho="'. $dispatch['Despacho'] .'" data-estacion="'. $dispatch['Estacion'] .'" data-comentario="'. $dispatch['notes'] .'"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-feather align-middle"><path d="M20.24 12.24a6 6 0 0 0-8.49-8.49L5 10.5V19h8.5z"></path><line x1="16" y1="8" x2="2" y2="22"></line><line x1="17.5" y1="15" x2="9" y2="15"></line></svg> Notas</a>';
                }
                $data[] = array(
                    'DESPACHO'   => $dispatch['Despacho'],
                    'ESTACION'   => $dispatch['Estacion'],
                    'ISLA'       => $dispatch['Isla'],
                    'CODCLIENTE' => $dispatch['codcli'],
                    'CLIENTE'    => $dispatch['Cliente'],
                    'VOLUMEN'    => $dispatch['Volumen'],
                    'MONTO'      => $dispatch['Monto'],
                    'TIPO'       => $dispatch['Tipo'],
                    'TURNO'      => $dispatch['turno'],
                    'FECHA'      => $from . ' ' . $dispatch['hora_formateada'],
                    'PRODUCTO'   => trim($dispatch['Producto']),
                    'STATUS'=> trim($dispatch['Verificador']),
                    'INCIDENCIA' => $dispatch['incidencia'],
                    'ACCIONES'   => $actions
                );
            }
        }
        json_output(array("data" => $data));
    }

    function pending_dispatches_table($from, $codgas, $shift, $dispatch_type) : void {

        if ($dispatch_type == 'payworks') {
            $data = $this->despachosModel->get_payworks_dispatches_to_release($codgas, dateToInt($from), $shift);

            foreach ($data as $key => $value) {
                if ($value['Verificador'] == 'Sin verificar') {
                    $dispatches[] = $value;
                }
            }
        } else if ($dispatch_type == 'crdito') {
            $dispatches = $this->despachosModel->get_credit_dispatches_just_to_release($from, $codgas, $shift);
        } else if ($dispatch_type == 'dbito') {
            $dispatches = $this->despachosModel->get_debit_dispatches_just_to_release($from, $codgas, $shift);
        }
        $data = [];
        if ($dispatches) {
            foreach ($dispatches as $dispatch) {
                $actions = '';
                if ($dispatch['Verificador'] != 'Sin verificar') {
                    $actions .= '<a href="javascript:void(0);" class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#notesModal" data-id="'. $dispatch['id'] .'" data-despacho="'. $dispatch['Despacho'] .'" data-estacion="'. $dispatch['Estacion'] .'" data-comentario="'. $dispatch['notes'] .'"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-feather align-middle"><path d="M20.24 12.24a6 6 0 0 0-8.49-8.49L5 10.5V19h8.5z"></path><line x1="16" y1="8" x2="2" y2="22"></line><line x1="17.5" y1="15" x2="9" y2="15"></line></svg> Notas</a>';
                }
                $data[] = array(
                    'DESPACHO'   => $dispatch['Despacho'],
                    'ESTACION'   => $dispatch['Estacion'],
                    'ISLA'       => $dispatch['Isla'],
                    'CODCLIENTE' => $dispatch['codcli'],
                    'CLIENTE'    => $dispatch['Cliente'],
                    'VOLUMEN'    => $dispatch['Volumen'],
                    'MONTO'      => $dispatch['Monto'],
                    'TIPO'       => $dispatch['Tipo'],
                    'TURNO'      => $dispatch['turno'],
                    'FECHA'      => $from . ' ' . $dispatch['hora_formateada'],
                    'PRODUCTO'   => trim($dispatch['Producto']),
                    'INCIDENCIA' => $dispatch['incidencia'],
                    'STATUS'=> trim($dispatch['Verificador'])
                );
            }
        }
        json_output(array("data" => $data));
    }

    function form_find($nrotrn, $fch, $codgas, $shift) : void {
        $fch = dateToInt($fch);
        $payment_type = $_POST['dispatch_type'];

        // Verificamos que el despacho exista
        if ($dispatch = $this->despachosModel->check_dispatch(intval($nrotrn), $codgas, $fch)) {

            if (($payment_type == "Débito" AND $dispatch[0]['tipval'] == 3) || ($payment_type == "Crédito" AND $dispatch[0]['tipval'] == 4)) {
                json_output(array("status" => "warning", "message" => "Este despacho no puede ser liberado por este medio."));
            }
            // Ahora vamos a verificar si este despacho puede tratarse de un error de venta
            if ((($dispatch[0]['rut'] != '' && $dispatch[0]['rut'] != null) AND $dispatch[0]['nroveh'] < 1 )) {
                json_output(array("status" => "warning", "message" => "Este despacho puede tratarse de un error de clasificación. Favor de verificar."));
            } else {
                // Ahora vamos a verificar que el registro no exista en la tabla de [TG].[dbo].[despachos_liberados]
                if ($this->despachosModel->check_dispatch_released(intval($nrotrn), $codgas)) {
                    json_output(array("status" => "warning", "message" => "Este despacho ya se encuentra liberado"));
                } else {
                    // Ahora vamos a liberar el despacho que es equivalente a ingresar el despacho en la tabla de [TG].[dbo].[despachos_liberados]
                    if ($this->despachosModel->release_dispatch_TG($dispatch[0])) {
                        // Ahora con json_output  vamos a lanzar un status y un mensaje
                        json_output(array("status" => "success", "message" => "Despacho liberado correctamente."));
                    } else {
                        json_output(array("status" => "error", "message" => "No se pudo liberar el despacho."));
                    }
                }
            }
        } else {
            // Vamos a verificar si el despacho existe en el día dado pero en otra estación o turno
            if ($row = $this->despachosModel->get_dispatch_by_nrotrn_and_date(intval($nrotrn), $fch)) {
                json_output(array("status" => "warning", "message" => "Despacho encontrado en otra estación o turno.", "station" => $row['Estacion'], "shift" => $row['nrotur'], "codgas" => $row['codgas']));
            } else {
                // Sí el despacho no existe, vamos a lanzar un mensaje de error
                json_output(array("status" => "error", "message" => "Despacho no encontrado en la estación especificada."));
            }
        }
    }

    function register_dispatch($nrotrn, $codgas, $fch) {
        $fch = dateToInt($fch);
        if ($dispatch = $this->despachosModel->check_dispatch($nrotrn, $codgas, $fch)) { // Si existe un despacho con el número de transacción
            // Ahora vamos a verificar si este despacho puede tratarse de un error de venta
            if ((($dispatch[0]['rut'] != '' && $dispatch[0]['rut'] != null) AND $dispatch[0]['nroveh'] < 1 )) {
                json_output(array("status" => "warning", "message" => "Este despacho puede tratarse de un error de clasificación. Favor de verificar."));
            } else {
                // Ahora vamos a verificar que el registro no exista en la tabla de [TG].[dbo].[despachos_liberados]
                if ($this->despachosModel->check_dispatch_released(intval($nrotrn), $codgas)) {
                    json_output(array("status" => "warning", "message" => "Este despacho ya se encuentra liberado"));
                } else {
                    // Ahora vamos a liberar el despacho que es equivalente a ingresar el despacho en la tabla de [TG].[dbo].[despachos_liberados]
                    if ($this->despachosModel->release_dispatch_TG($dispatch[0])) {
                        // Ahora con json_output  vamos a lanzar un status y un mensaje
                        json_output(array("status" => "success", "message" => "Despacho liberado correctamente."));
                    } else {
                        json_output(array("status" => "error", "message" => "No se pudo liberar el despacho."));
                    }
                }
            }
        } else {
            // Vamos a verificar si el despacho existe en el día dado pero en otra estación o turno
            if ($row = $this->despachosModel->get_dispatch_by_nrotrn_and_date(intval($nrotrn), $fch)) {
                json_output(array("status" => "warning", "message" => "Despacho encontrado en otra estación o turno.", "station" => $row['Estacion'], "shift" => $row['nrotur'], "codgas" => $row['codgas']));
            } else {
                // Sí el despacho no existe, vamos a lanzar un mensaje de error
                json_output(array("status" => "error", "message" => "Despacho no encontrado en la estación especificada."));
            }
        }
    }

    function save_notes() : void {
        if (preg_match('/POST/i',$_SERVER['REQUEST_METHOD'])) {
            $this->despachosModel->save_notes($_POST['id'], $_POST['input_notes']);
            redirect();
        }
    }

    function send_mail($fch, $codgas, $shift, $dispatch_type, $sentTo) {

        if ($dispatch_type == 'payworks') { // Verificado OK
            $data = $this->despachosModel->get_payworks_dispatches_to_release($codgas, dateToInt($fch), $shift);
            foreach ($data as $key => $value) {
                if ($value['Verificador'] == 'Sin verificar') {
                    $dispatches[] = $value;
                }
            }
        } else if ($dispatch_type == 'crdito') {
            $dispatches = $this->despachosModel->get_credit_dispatches_just_to_release($fch, $codgas, $shift);
        } else if ($dispatch_type == 'dbito') {
            $dispatches = $this->despachosModel->get_debit_dispatches_just_to_release($fch, $codgas, $shift);
        }

        // Fecha actual
        $fechaActual = new DateTime();
        $diaActual = $fechaActual->format('d');

        // Último día del mes
        $ultimoDiaMes = $fechaActual->format('t');

        // Días restantes para el fin de mes
        $diasRestantes = $ultimoDiaMes - $diaActual;

        // Contenido dinámico
        if ($diasRestantes >= 3) {
            $mensajeDinamico = "<p>Agradecemos que puedan enviarnos los tickets pendientes en un plazo no mayor a 72 horas.</p>";
        } else {
            $mensajeDinamico = "<p>Es imprescindible que envíen los tickets de manera inmediata, ya que faltan menos de 3 días para el cierre de mes.</p>";
        }

        $body = '
        <p>Estimados compañeros,</p>
        <p>Se les solicita amablemente que envíen los tickets de venta faltantes o aquellos que no cuenten con la firma correspondiente de los clientes.</p>';
        $body .= $mensajeDinamico;
        $body .= '
        <table border="1" cellpadding="5" cellspacing="0">
          <thead>
            <tr style="background-color: #add8e6;">
              <th>Despacho</th>
              <th>Fecha</th>
              <th>Isla</th>
              <th>Turno</th>
              <th>Hora</th>
              <th>Cliente</th>
              <th>Tipo</th>
              <th>Producto</th>
              <th>Volumen</th>
              <th>Monto</th>
            </tr>
          </thead>';
        foreach ($dispatches as $dispatch) {
            $body .= '
            <tr>
              <td>'. $dispatch['Despacho'] .'</td>
              <td>'. $dispatch['Fecha'] .'</td>
              <td>'. $dispatch['Isla'] .'</td>
              <td>'. $dispatch['turno'] .'</td>
              <td>'. $dispatch['hora_formateada'] .'</td>
              <td>'. $dispatch['Cliente'] .'</td>
              <td>'. $dispatch['Tipo'] .'</td>
              <td>'. trim($dispatch['Producto']) .'</td>
              <td>'. number_format($dispatch['Volumen'], 3, '.',',') .'</td>
              <td>$'. number_format($dispatch['Monto'], 2, '.', ',') .'</td>
            </tr>';
        }
        $body .= '
        </table>
        <p>Es importante cumplir con esta solicitud, ya que, de lo contrario, los tickets faltantes o sin firma serán enviados a egresos como faltantes. Si tienen dudas o necesitan apoyo, favor de dirigirse a la jefatura de ingresos.</p>
        <p>Agradecemos su atención y colaboración. Quedamos pendientes de sus comentarios.</p>
        ';

        if (send_mail('Solicitud de tickets faltantes ' . $fch,$body,explode(';', $sentTo),'totalgasdesarrollo@gmail.com')) {
            json_output(array("status" => "success", "message" => "Correo enviado correctamente."));
        } else {
            json_output(array("status" => "error", "message" => "No se pudo enviar el correo."));
        }
    }

    function get_users_emails() {
        $user_mail = $_SESSION['tg_user']['Correo'];
        $station_mail = $this->estacionesModel->get_station_email($_GET['codgas']);

        json_output(array("user_mail" => $user_mail, "station_mail" => $station_mail));
    }

    function generateExcel($fecha) {
        // Crear el objeto de hoja de cálculo
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $fch = dateToInt($fecha);

        $dispatches = $this->despachosModel->get_all_dispatches_just_to_release($fch);
        $columnIndex = 'A';
        $sheet->setCellValue('A1', 'DESPACHO');
        $sheet->setCellValue('B1', 'ESTACIÓN');
        $sheet->setCellValue('C1', 'ISLA');
        $sheet->setCellValue('D1', 'CODCLIENTE');
        $sheet->setCellValue('E1', 'CLIENTE');
        $sheet->setCellValue('F1', 'VOLUMEN');
        $sheet->setCellValue('G1', 'MONTO');
        $sheet->setCellValue('H1', 'TIPO');
        $sheet->setCellValue('I1', 'TURNO');
        $sheet->setCellValue('J1', 'FECHA');
        $sheet->setCellValue('K1', 'PRODUCTO');
        $sheet->setCellValue('L1', 'STATUS');

        // Vamos a meter un setCellValue con negrita
        $sheet->getStyle('A1:L1')->getFont()->setBold(true);

        $rowIndex = 2;
        foreach ($dispatches as $station) {
            $sheet->setCellValue('A' . $rowIndex, $station['Despacho']);
            $sheet->setCellValue('B' . $rowIndex, $station['Estacion']);
            $sheet->setCellValue('C' . $rowIndex, $station['Isla']);
            $sheet->setCellValue('D' . $rowIndex, $station['codcli']);
            $sheet->setCellValue('E' . $rowIndex, $station['Cliente']);
            $sheet->setCellValue('F' . $rowIndex, $station['Volumen']);
            $sheet->setCellValue('G' . $rowIndex, $station['Monto']);
            $sheet->setCellValue('H' . $rowIndex, $station['Tipo']);
            $sheet->setCellValue('I' . $rowIndex, $station['turno']);
            $sheet->setCellValue('J' . $rowIndex, $station['Fecha']);
            $sheet->setCellValue('K' . $rowIndex, $station['Producto']);
            $sheet->setCellValue('L' . $rowIndex, 'Pendiente');
            $rowIndex++;
        }

        // Configurar encabezados HTTP para descargar el archivo
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="Pendientes'. $fecha .'.xlsx"');

        // Crear y enviar el archivo Excel al navegador
        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }
}