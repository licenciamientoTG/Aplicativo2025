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
    Public FacturasModel $FacturasModel;

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
        $this->FacturasModel    = new FacturasModel;
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

// === Dentro de class Income ===

// Reemplaza/Agrega este método
// Reemplaza este método dentro de class Income
public function balance_age_send_mail(){
    if (!preg_match('/POST/i', $_SERVER['REQUEST_METHOD'])) {
        return json_output(['status'=>'error','message'=>'Método no permitido']);
    }

    // POST
    $sentTo   = $_POST['sentTo']   ?? '';
    $subject  = $_POST['subject']  ?? 'Balance por Cliente/Estación';
    $filename = $_POST['filename'] ?? '';
    $file_b64 = $_POST['file_b64'] ?? '';  // Excel en base64 (opcional)
    $cta      = (int)($_POST['cta'] ?? 0);
    $gas      = (int)($_POST['gas'] ?? 0);

    // 🔹 NUEVO: mensaje opcional del formulario
    $body     = (string)($_POST['body'] ?? ' ');

    // Normaliza y valida correos (acepta ; o ,) y restringe a @totalgas.com
    $rawList = str_replace(',', ';', (string)$sentTo);
    $to = array_values(array_unique(array_filter(
        array_map('trim', explode(';', $rawList)),
        function($e){ return $e && filter_var($e, FILTER_VALIDATE_EMAIL) && preg_match('/@totalgas\.com$/i', $e); }
    )));
    if (!$to) return json_output(['status'=>'error','message'=>'Ingrese al menos un correo @totalgas.com válido.']);

    // Mensaje dinámico fin de mes (sin cambios)
    $fechaActual   = new DateTime();
    $diaActual     = (int)$fechaActual->format('d');
    $ultimoDiaMes  = (int)$fechaActual->format('t');

    // Envío (con o sin adjunto)
    $from = 'totalgasdesarrollo@gmail.com';
    $ok   = false;
    $tmp  = null;

    // Si viene un dataURL, quítale el prefijo
    if (!empty($file_b64) && strpos($file_b64, 'base64,') !== false) {
        $file_b64 = explode('base64,', $file_b64, 2)[1] ?? $file_b64;
    }

    if (!empty($file_b64) && !empty($filename)) {
        $safeName = preg_replace('/[^\w\.\-]+/', '_', $filename);
        $tmp = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $safeName;
        $bytes = @file_put_contents($tmp, base64_decode($file_b64, true));
        if ($bytes === false) {
            return json_output(['status'=>'error','message'=>'No se pudo procesar el archivo adjunto.']);
        }

        // Captura la salida del PHPMailer para depurar sin romper el JSON
        ob_start();
        $ok = @send_mail2($subject, $body, $to, $from, $tmp);
        $mailerOut = trim(ob_get_clean());

        @unlink($tmp);
    } else {
        ob_start();
        $ok = @send_mail2($subject, $body, $to, $from);
        $mailerOut = trim(ob_get_clean());
    }

    if ($ok) {
        return json_output([
            'status'  => 'success',
            'message' => !empty($filename) ? 'Correo enviado correctamente con adjunto.' : 'Correo enviado correctamente.'
        ]);
    }

    // Error controlado (HTTP 200, pero con status=error)
    return json_output([
        'status'  => 'error',
        'message' => 'No se pudo enviar el correo.',
        'mailer'  => $mailerOut
    ]);
}

/**
 * Construye una tabla HTML compacta para el TAB "Totales"
 */




public function balance_age()
{
    // Catálogo de cuentas
    $cuentas = [
        101032000 => '101032000 - Facturas x cobrar',
        101032001 => '101032001 - Facturas X Cobrar Administrativas',
        101032004 => '101032004 - Facturas X Cobrar Arrendamiento',
    ];

    // === Filtrar gasolineras permitidas ===
    $permitidas = [2, 24, 26, 29, 31];

    // Lee todas y filtra por los códigos permitidos
    $gas_all = $this->clientesModel->get_gasolineras(); // [['cod'=>..,'den'=>..],...]
    $gasolineras = array_values(array_filter($gas_all, function ($g) use ($permitidas) {
        return in_array((int)$g['cod'], $permitidas, true);
    }));

    // Lee filtros SIN default (para no disparar consulta)
    $cta_sel = isset($_REQUEST['cta']) ? (int)$_REQUEST['cta'] : null;
    $gas_sel = isset($_REQUEST['gas']) ? (int)$_REQUEST['gas'] : null;

    // ¿El usuario ya dio "Consultar"?
    $submitted = ($cta_sel !== null && $gas_sel !== null);

    $rows     = [];
    $rows_det = [];   // <<-- NUEVO (para la pestaña Facturas)

    if ($submitted) {
        // Valida selección contra catálogos ya filtrados
        $validCta = array_key_exists($cta_sel, $cuentas);
        $validGas = in_array(
            $gas_sel,
            array_map('intval', array_column($gasolineras, 'cod')),
            true
        );

        if ($validCta && $validGas) {
            $rows     = $this->clientesModel->get_balance_age($cta_sel, $gas_sel) ?: [];
            $rows_det = $this->clientesModel->get_balance_age_detalle($cta_sel, $gas_sel) ?: []; // <<-- NUEVO
        } else {
            $submitted = false;
        }
    }

    $user_email = $_SESSION['tg_user']['Correo'] ?? '';

    echo $this->twig->render($this->route . 'balance_age.html', [
        'rows'        => $rows,
        'rows_det'    => $rows_det,     // <<-- NUEVO
        'cuentas'     => $cuentas,
        'gasolineras' => $gasolineras,
        'cta_sel'     => $cta_sel,
        'gas_sel'     => $gas_sel,
        'submitted'   => $submitted,
        'user_email'  => $user_email,  
    ]);
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

    $data  = [];
    $from  = dateToInt($_POST['from']);
    $until = dateToInt($_POST['until']);

    if ($rows = $this->despachosModel->cash_invoices_advance($from, $until)) {
        foreach ($rows as $r) {
            $data[] = [
                'codcli'       => $r['codcli'],
                'cliente'      => $r['den'],
                'n_despachos'  => (int)$r['n_despachos'],
                'monto'        => (float)$r['monto'],
            ];
        }
    }
    json_output(['data' => $data]);
}

function invoice_client_desp(){
    ini_set('memory_limit', '512M');
    set_time_limit(300);

    $data  = [];
    $from  = dateToInt($_POST['from']);
    $until = dateToInt($_POST['until']);

    if ($rows = $this->despachosModel->invoice_client_desp($from, $until)) {
        foreach ($rows as $r) {
        $data[] = [
            'fecha'       => $r['fecha'],
            'codcli'      => $r['codcli'],
            'cliente'     => $r['den'],
            'monto'       => (float)$r['monto'],
            'n_despachos' => (int)$r['n_despachos'],   // <<<<<< nuevo
            'estacion'    => $r['abr'],
            'factura'     => $r['factura'],
            'metodo_pago' => $r['metodo_pago'],
        ];

        }
    }
    json_output(['data' => $data]);
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

        public function anomalies()
    {
        // Puedes pasar valores por defecto si quieres
        echo $this->twig->render('views/income/invoiced_dispatches.html', [
            'until' => false
        ]);
    }



    // En tu Controller (IncomeController.php o similar)

public function anomalies_clients_visual()
{
    set_time_limit(300);
    ini_set('memory_limit', '512M'); 
    header('Content-Type: application/json');

    // RECIBIR DATOS DEL FRONTEND
    $mode  = $_POST['mode'] ?? 'month';
    $value = $_POST['target_value'] ?? date('Y-m');

    $fechaTargetInicio = '';
    $fechaTargetFin    = '';

    // LÓGICA DE FECHAS
    if ($mode === 'week') {
        // Formato esperado: "2025-W35"
        // Usamos DateTime para obtener el Lunes y Domingo de esa semana ISO
        try {
            $parts = explode('-W', $value);
            $year = $parts[0];
            $week = $parts[1];
            
            $dto = new DateTime();
            $dto->setISODate($year, $week); // Pone la fecha en el Lunes de esa semana
            
            $fechaTargetInicio = $dto->format('Y-m-d');
            $dto->modify('+6 days'); // Mueve al Domingo
            $fechaTargetFin    = $dto->format('Y-m-d');

        } catch (Exception $e) {
            // Fallback por si falla
            $fechaTargetInicio = date('Y-m-d');
            $fechaTargetFin    = date('Y-m-d');
        }

    } else {
        // MODO MENSUAL (Lógica original)
        $fechaTargetInicio = $value . '-01';
        $fechaTargetFin    = date("Y-m-t", strtotime($fechaTargetInicio));
    }

    // CALCULAR HISTÓRICO (Siempre 6 meses atrás desde el inicio del periodo evaluado)
    $fechaHistInicio = date("Y-m-01", strtotime("-6 months", strtotime($fechaTargetInicio)));
    $fechaHistFin    = date("Y-m-t", strtotime("-1 month", strtotime($fechaTargetInicio)));
    
    // Visualización fin de año
    $yearStr = date("Y", strtotime($fechaTargetInicio));
    $fechaVisualFin = $yearStr . '-12-31';

    try {
        $data = $this->despachosModel->get_anomalies_visual_data(
            $fechaTargetInicio, 
            $fechaTargetFin, 
            $fechaHistInicio, 
            $fechaHistFin,
            $fechaVisualFin
        );
        echo json_encode(['data' => $data]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage(), 'data' => []]);
    }
}


/**
     * Endpoint NUEVO para los modales de detalle (Pico, Días Críticos, Exceso).
     * Recibe el rango seleccionado y calcula automáticamente el rango histórico (6 meses atrás)
     * para enviárselo al modelo y que este pueda comparar (Media vs Real).
     */
    public function get_breakdown_ajax()
    {
        // 1. Validar sesión (opcional según tu framework)
        // if (!Auth::validate()) { header('Content-Type: application/json'); echo json_encode(['error' => 'Auth']); return; }

        // 2. Recibir parámetros del Frontend
        $codopr = $_POST['codopr'] ?? 0;
        $fini   = $_POST['fini'] ?? date('Y-m-01'); // Fecha inicio del periodo analizado
        $ffin   = $_POST['ffin'] ?? date('Y-m-t');  // Fecha fin del periodo analizado

        // 3. CALCULAR CONTEXTO HISTÓRICO (La clave de la detección)
        // Para saber si un día es crítico, necesitamos compararlo contra la media de los 6 meses previos.
        
        // Fecha Inicio Histórico = Fecha Inicio Análisis - 6 Meses
        $hist_ini = date("Y-m-01", strtotime("-6 months", strtotime($fini)));
        
        // Fecha Fin Histórico = El día anterior al inicio del análisis
        $hist_fin = date("Y-m-d", strtotime("-1 day", strtotime($fini)));

        // 4. Llamar al Modelo
        // Se asume que cargaste el modelo como $this->despachosModel
        $data = $this->despachosModel->get_client_anomaly_breakdown(
            $codopr, 
            $fini, 
            $ffin, 
            $hist_ini, 
            $hist_fin
        );

        // 5. Retornar JSON
        header('Content-Type: application/json');
        echo json_encode(['status' => 'success', 'data' => $data]);
    }

    /**
     * Endpoint ACTUALIZADO para obtener tickets.
     * Ahora calcula también el histórico para que el modelo pueda filtrar 
     * únicamente los tickets de los días que superaron 4 sigmas.
     */
    public function get_suspicious_details_ajax()
    {
        $codopr     = $_POST['codopr'] ?? 0;
        $month_date = $_POST['month_date'] ?? date('Y-m-01'); // Viene como '2025-11-01'

        // 1. Definir rango del mes seleccionado (Target)
        $t_ini = date("Y-m-01", strtotime($month_date));
        $t_fin = date("Y-m-t", strtotime($month_date));

        // 2. Definir rango histórico (6 meses atrás) para el cálculo de Sigma
        $h_ini = date("Y-m-01", strtotime("-6 months", strtotime($t_ini)));
        $h_fin = date("Y-m-t", strtotime("-1 month", strtotime($t_ini)));

        // 3. Llamar al modelo
        // Nota: Esta función del modelo ahora espera 5 parámetros para hacer el filtrado inteligente
        $data = $this->despachosModel->get_suspicious_tickets_details(
            $t_ini, 
            $t_fin, 
            $h_ini, 
            $h_fin, 
            $codopr
        );

        header('Content-Type: application/json');
        echo json_encode(['status' => 'success', 'data' => $data]);
    }








    public function anomalies_top_station()
{
    header('Content-Type: application/json; charset=UTF-8');
    while (ob_get_level()) { ob_end_clean(); }
    ob_start();

    try {
        ini_set('display_errors', '0');
        set_time_limit(300);

        $from  = $_POST['from']  ?? null;
        $until = $_POST['until'] ?? null;

        if (!$from || !$until) {
            http_response_code(400);
            echo json_encode([
                'data'    => [],
                'error'   => true,
                'message' => 'Parámetros inválidos'
            ]);
            return;
        }

        $desde_eval_i = dateToInt($from);
        $hasta_eval_i = dateToInt($until);

        if (!is_numeric($desde_eval_i) || !is_numeric($hasta_eval_i)) {
            http_response_code(400);
            echo json_encode([
                'data'    => [],
                'error'   => true,
                'message' => 'Fechas inválidas'
            ]);
            return;
        }

        $rows = $this->despachosModel->anomalies_top_station(
            (int)$desde_eval_i,
            (int)$hasta_eval_i
        );

        $data = [];
        foreach ($rows as $r) {
            $data[] = [
                'codgas'           => (int)($r['codgas'] ?? 0),
                // en la vista el alias es Estacion con mayúscula
                'estacion'         => $r['Estacion'] ?? ($r['estacion'] ?? ''),
                'tickets_anomalos' => (int)($r['tickets_anomalos'] ?? 0),
            ];
        }

        http_response_code(200);
        echo json_encode(['data' => $data], JSON_INVALID_UTF8_SUBSTITUTE);

    } catch (\Throwable $e) {
        error_log('ANOMALIES TOP STATION ERROR: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'data'    => [],
            'error'   => true,
            'message' => 'Error interno al calcular la estación con más tickets en días anómalos'
        ]);
    } finally {
        ob_end_flush();
    }
}

public function anomalies_client_summary()
{
    header('Content-Type: application/json; charset=UTF-8');
    while (ob_get_level()) { ob_end_clean(); }
    ob_start();

    try {
        ini_set('display_errors', '0');
        set_time_limit(300);

        $from   = $_POST['from']   ?? null;
        $until  = $_POST['until']  ?? null;
        $codopr = $_POST['codopr'] ?? null;

        if (!$from || !$until || !$codopr) {
            http_response_code(400);
            echo json_encode(['data_eval' => [], 'error' => true, 'message' => 'Parámetros inválidos']);
            return;
        }

        $desde_eval_i = dateToInt($from);
        $hasta_eval_i = dateToInt($until);

        if (!is_numeric($desde_eval_i) || !is_numeric($hasta_eval_i) || !is_numeric($codopr)) {
            http_response_code(400);
            echo json_encode(['data_eval' => [], 'error' => true, 'message' => 'Parámetros inválidos']);
            return;
        }

        $codoprInt = (int)$codopr;

        // 1) Totales del periodo evaluado
        $rowsEval = $this->despachosModel->anomalies_client_totals_period(
            (int)$desde_eval_i,
            (int)$hasta_eval_i,
            $codoprInt
        );

        // 2) Cálculo de los 3 meses históricos previos,
        // igual que en anomalies_by_client (3 meses completos anteriores)
        $desdeEvalDate = new \DateTime($from);

        // mes -3: primer día 3 meses antes
        $m1Start = (clone $desdeEvalDate)->modify('first day of -3 month');
        $m1End   = (clone $m1Start)->modify('last day of this month');

        // mes -2
        $m2Start = (clone $m1Start)->modify('first day of +1 month');
        $m2End   = (clone $m2Start)->modify('last day of this month');

        // mes -1
        $m3Start = (clone $m2Start)->modify('first day of +1 month');
        $m3End   = (clone $m3Start)->modify('last day of this month');

        // convertir a enteros fchtrn
        $m1Desde_i = dateToInt($m1Start->format('Y-m-d'));
        $m1Hasta_i = dateToInt($m1End->format('Y-m-d'));

        $m2Desde_i = dateToInt($m2Start->format('Y-m-d'));
        $m2Hasta_i = dateToInt($m2End->format('Y-m-d'));

        $m3Desde_i = dateToInt($m3Start->format('Y-m-d'));
        $m3Hasta_i = dateToInt($m3End->format('Y-m-d'));

        $rowsM1 = $this->despachosModel->anomalies_client_totals_period(
            (int)$m1Desde_i,
            (int)$m1Hasta_i,
            $codoprInt
        );

        $rowsM2 = $this->despachosModel->anomalies_client_totals_period(
            (int)$m2Desde_i,
            (int)$m2Hasta_i,
            $codoprInt
        );

        $rowsM3 = $this->despachosModel->anomalies_client_totals_period(
            (int)$m3Desde_i,
            (int)$m3Hasta_i,
            $codoprInt
        );

        // Labels sencillos (YYYY-MM)
        $m1Label = $m1Start->format('Y-m');
        $m2Label = $m2Start->format('Y-m');
        $m3Label = $m3Start->format('Y-m');

        // Formatear salida (si no hay filas, devolvemos arreglo vacío)
        $fmt = function(array $rows): array {
            if (empty($rows)) return [];
            $out = [];
            foreach ($rows as $r) {
                $out[] = [
                    'codopr'                  => (int)($r['codopr'] ?? 0),
                    'nombreCliente'           => $r['nombreCliente'] ?? '',
                    'n_despachos'             => (int)($r['n_despachos'] ?? 0),
                    'facturas_unicas'         => (int)($r['facturas_unicas'] ?? 0),
                    'facturas_codgas_unicas'  => (int)($r['facturas_codgas_unicas'] ?? 0),
                    'total_cant'              => (float)($r['total_cant'] ?? 0),
                    'total_mto'               => (float)($r['total_mto'] ?? 0),
                ];
            }
            return $out;
        };

        $dataEval = $fmt($rowsEval);
        $dataM1   = $fmt($rowsM1);
        $dataM2   = $fmt($rowsM2);
        $dataM3   = $fmt($rowsM3);

        http_response_code(200);
        echo json_encode([
            'error'      => false,
            'data_eval'  => $dataEval,
            'data_m1'    => $dataM1,
            'data_m2'    => $dataM2,
            'data_m3'    => $dataM3,
            'm1_label'   => $m1Label,
            'm2_label'   => $m2Label,
            'm3_label'   => $m3Label,
        ], JSON_INVALID_UTF8_SUBSTITUTE);

    } catch (\Throwable $e) {
        error_log('ANOMALIES CLIENT SUMMARY ERROR: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'data_eval' => [],
            'error'     => true,
            'message'   => 'Error interno al generar el resumen del cliente'
        ]);
    } finally {
        ob_end_flush();
    }
}

    
public function anomalies_clients_table()
{
    header('Content-Type: application/json; charset=UTF-8');
    while (ob_get_level()) { ob_end_clean(); }
    ob_start();

    try {
        ini_set('display_errors', '0');
        set_time_limit(300);

        $from  = $_POST['from']  ?? null;
        $until = $_POST['until'] ?? null;

        if (!$from || !$until) {
            http_response_code(400);
            echo json_encode(['data' => [], 'error' => true, 'message' => 'Parámetros inválidos']);
            return;
        }

        $desde_eval_i = dateToInt($from);
        $hasta_eval_i = dateToInt($until);

        if (!is_numeric($desde_eval_i) || !is_numeric($hasta_eval_i)) {
            http_response_code(400);
            echo json_encode(['data' => [], 'error' => true, 'message' => 'Fechas inválidas']);
            return;
        }

        // 1) Consulta de anomalías (ya existente)
        $rowsAnom = $this->despachosModel->anomalies_by_client(
            (int)$desde_eval_i,
            (int)$hasta_eval_i
        );

        // 2) Nueva consulta de totales (despachos / facturas / monto)
        $rowsTot = $this->despachosModel->anomalies_clients_totals(
            (int)$desde_eval_i,
            (int)$hasta_eval_i
        );

        // Indexar totales por codopr para merge rápido
        $totalesPorCodopr = [];
        foreach ($rowsTot as $t) {
            $cod = $t['codopr'] ?? null;
            if ($cod === null) continue;
            $totalesPorCodopr[$cod] = $t;
        }

        $data = [];
        foreach ($rowsAnom as $r) {
            $codopr = $r['codopr'] ?? null;

            $tot = $codopr !== null && isset($totalesPorCodopr[$codopr])
                ? $totalesPorCodopr[$codopr]
                : [
                    'n_despachos'     => 0,
                    'facturas_unicas' => 0,
                    'total_mto'       => 0,
                ];

            $data[] = [
                'codopr'       => $codopr,
                'cliente'      => $r['cliente'] ?? '',

                'dias_anomalos' => (int)($r['dias_anomalos'] ?? 0),
                'prom_zscore'   => (float)($r['prom_zscore'] ?? 0),

                // NUEVOS CAMPOS (de la segunda consulta)
                'n_despachos'     => (int)($tot['n_despachos'] ?? 0),
                'facturas_unicas' => (int)($tot['facturas_unicas'] ?? 0),
                'total_mto'       => (float)($tot['total_mto'] ?? 0),

                // Campos auxiliares para filtros
                'max_facturas_dia'             => (int)($r['max_facturas_dia'] ?? 0),
                'tiene_factura_multi_prod'     => (int)($r['tiene_factura_multi_prod'] ?? 0),
                'tiene_factura_montos_diferentes' => (int)($r['tiene_factura_montos_diferentes'] ?? 0),
            ];
        }

        http_response_code(200);
        echo json_encode(['data' => $data], JSON_INVALID_UTF8_SUBSTITUTE);

    } catch (\Throwable $e) {
        error_log('ANOMALIES TABLE ERROR: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'data' => [],
            'error' => true,
            'message' => 'Error interno al generar el reporte'
        ]);
    } finally {
        ob_end_flush();
    }
}



public function anomalies_client_days()
{
    header('Content-Type: application/json; charset=UTF-8');
    while (ob_get_level()) { ob_end_clean(); }
    ob_start();

    try {
        ini_set('display_errors', '0');
        set_time_limit(300);

        $from   = $_POST['from']   ?? null;
        $until  = $_POST['until']  ?? null;
        $codopr = $_POST['codopr'] ?? null;

        if (!$from || !$until || !$codopr) {
            http_response_code(400);
            echo json_encode(['data' => [], 'error' => true, 'message' => 'Parámetros inválidos']);
            return;
        }

        $desde_eval_i = dateToInt($from);
        $hasta_eval_i = dateToInt($until);

        if (!is_numeric($desde_eval_i) || !is_numeric($hasta_eval_i) || !is_numeric($codopr)) {
            http_response_code(400);
            echo json_encode(['data' => [], 'error' => true, 'message' => 'Parámetros inválidos']);
            return;
        }

        $rows = $this->despachosModel->anomalies_by_client_days(
            (int)$desde_eval_i,
            (int)$hasta_eval_i,
            (int)$codopr
        );

        $data = [];
        foreach ($rows as $r) {
            $data[] = [
                'codopr'                  => $r['codopr'] ?? null,
                'fecha'                   => $r['fecha'] ?? null,
                'facturas_dia'            => (int)($r['facturas_dia'] ?? 0),
                'baseline_media_hist'     => (float)($r['baseline_media_hist'] ?? 0),
                'baseline_desv_hist'      => (float)($r['baseline_desv_hist'] ?? 0),
                'q1_hist'                 => (float)($r['q1_hist'] ?? 0),
                'q3_hist'                 => (float)($r['q3_hist'] ?? 0),
                'zscore_hist'             => (float)($r['zscore_hist'] ?? 0),
                'incremento_pct_vs_hist'  => (float)($r['incremento_pct_vs_hist'] ?? 0),
                'es_anomalia'             => (int)($r['es_anomalia'] ?? 0),
            ];
        }

        http_response_code(200);
        echo json_encode(['data' => $data], JSON_INVALID_UTF8_SUBSTITUTE);

    } catch (\Throwable $e) {
        error_log('ANOMALIES CLIENT DAYS ERROR: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'data'    => [],
            'error'   => true,
            'message' => 'Error interno al generar el detalle de días'
        ]);
    } finally {
        ob_end_flush();
    }
}


public function anomalies_client_tickets()
{
    header('Content-Type: application/json; charset=UTF-8');
    while (ob_get_level()) { ob_end_clean(); }
    ob_start();

    try {
        ini_set('display_errors', '0');
        set_time_limit(300);

        $from   = $_POST['from']   ?? null;
        $until  = $_POST['until']  ?? null;
        $codopr = $_POST['codopr'] ?? null;

        if (!$from || !$until || !$codopr) {
            http_response_code(400);
            echo json_encode(['data' => [], 'error' => true, 'message' => 'Parámetros inválidos']);
            return;
        }

        $desde_eval_i = dateToInt($from);
        $hasta_eval_i = dateToInt($until);

        if (!is_numeric($desde_eval_i) || !is_numeric($hasta_eval_i) || !is_numeric($codopr)) {
            http_response_code(400);
            echo json_encode(['data' => [], 'error' => true, 'message' => 'Parámetros inválidos']);
            return;
        }

        $rows = $this->despachosModel->anomalies_by_client_tickets(
            (int)$desde_eval_i,
            (int)$hasta_eval_i,
            (int)$codopr
        );

        $data = [];
        foreach ($rows as $r) {
            $data[] = [
                'fecha'          => $r['fecha'] ?? null,
                'hratrn'         => $r['hratrn'] ?? null,
                'nrotrn'         => (int)($r['nrotrn'] ?? 0),
                'nrofac'         => (int)($r['nrofac'] ?? 0),
                'factura'        => (int)($r['factura'] ?? 0),
                'serie'          => $r['serie'] ?? '',
                'conceptofac'    => $r['conceptofac'] ?? '',
                'codgas'         => (int)($r['codgas'] ?? 0),
                'estacion'       => $r['estacion'] ?? '',
                'codprd'         => (int)($r['codprd'] ?? 0),
                'nomPrd'         => $r['nomPrd'] ?? '',
                'can'            => (float)($r['can'] ?? 0),
                'mto'            => (float)($r['mto'] ?? 0),
                'codcli_d'       => (int)($r['codcli_d'] ?? 0),
                'nombreCliente'  => $r['nombreCliente'] ?? '',
                'codopr_f'       => (int)($r['codopr_f'] ?? 0),
                'isla'           => $r['isla'] ?? '',
                'responsable'    => $r['responsable'] ?? '',
                'tiptrn'         => $r['tiptrn'] ?? '',
                'datref'         => $r['datref'] ?? '',
                'satuid'         => $r['satuid'] ?? '',
                'satrfc'         => $r['satrfc'] ?? '',
                'turno'          => isset($r['nrotur']) ? substr((string)$r['nrotur'], 0, 1) : null,
            ];
        }

        http_response_code(200);
        echo json_encode(['data' => $data], JSON_INVALID_UTF8_SUBSTITUTE);

    } catch (\Throwable $e) {
        error_log('ANOMALIES CLIENT TICKETS ERROR: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'data'    => [],
            'error'   => true,
            'message' => 'Error interno al generar la lista de tickets'
        ]);
    } finally {
        ob_end_flush();
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


        if ($dispatches = $this->despachosModel->invoiced_dispatches_data(dateToInt($_POST['from']), dateToInt($_POST['until']), $codgas,$_POST['uuid'],$tipo_cliente,$billed)) {

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

    public function balance_age_get_user_email(): void
    {
        // Toma el correo del usuario autenticado desde la sesión
        $user_mail = $_SESSION['tg_user']['Correo'] ?? '';

        // Devuelve JSON (usa tu helper global)
        json_output([
            'ok' => true,
            'user_mail' => $user_mail
        ]);
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


    // =========================================================================
    // CARGA DE REPORTES BANCARIOS (Manual)
    // =========================================================================
    public function upload_bank_reports() {
        echo $this->twig->render($this->route . 'upload_reports.html');
    }

  private function sanitizar_nombre_columna_php($nombre, $bankType, $coreMap) {
        if (!$nombre) return "SinNombre";
        $orig = trim((string)$nombre);

        // 1. Quitar BOM y normalizar espacios (incluyendo espacios no divisibles \xA0)
        $orig = str_replace(["\xEF\xBB\xBF", "\xFE\xFF", "\xFF\xFE", "\xC2\xA0"], ' ', $orig);
        $orig = trim(preg_replace('/\s+/', ' ', $orig));

        // 2. REPARACIÓN DE CODIFICACIÓN
        if (preg_match('/[\xC2\xC3][\x80-\xBF]/', $orig)) {
            $intento = @utf8_decode($orig);
            if ($intento && mb_check_encoding($intento, 'UTF-8')) {
                $orig = $intento;
            }
        }

        // 3. Revisar en el Mapa (Chequeo exacto)
        if (isset($coreMap[$bankType][$orig])) {
            return $coreMap[$bankType][$orig];
        }

        // 3.1. FUZZY MATCH ROBUSTO (Regex)
        $norm = mb_strtoupper($orig, 'UTF-8');
        $norm = str_replace(['Á', 'É', 'Í', 'Ó', 'Ú', 'Ü'], ['A', 'E', 'I', 'O', 'U', 'U'], $norm);
        
        if (preg_match('/FECHA/i', $norm)) {
            if (preg_match('/DEPOSITO|APLICACION/i', $norm)) {
                return 'Fecha_Deposito';
            }
            if (preg_match('/TRANSACCION/i', $norm)) {
                return 'Fecha_Transaccion';
            }
        }

        // 4. Limpieza estándar (Fallback)
        // iconv elimina acentos: 'Aplicación' -> 'Aplicacion'
        $s = iconv('UTF-8', 'ASCII//TRANSLIT', $orig);
        $s = preg_replace('/[^a-zA-Z0-9_]/', '_', $s);
        $s = preg_replace('/_+/', '_', $s);
        
        return trim($s, '_');
    }
    private function asegurar_columnas_php($conn, $tabla, $cleanCols) {
        // FUNCIÓN DESACTIVADA PARA MANTENER ESTÁNDAR DE TABLAS
        return;
    }

    private function obtener_ajuste_juarez_php($fecha_trans) {
        if (!$fecha_trans) return -1;
        $dt = ($fecha_trans instanceof \DateTime) ? $fecha_trans : new \DateTime($fecha_trans);
        $year = (int)$dt->format('Y');
        
        // 2do domingo de Marzo
        $mar1 = new \DateTime("$year-03-01");
        $dias_al_primero_mar = (7 - (int)$mar1->format('N')) % 7;
        $segundo_dom_mar = $mar1->modify("+" . ($dias_al_primero_mar + 7) . " days");
        
        // 1er domingo de Noviembre
        $nov1 = new \DateTime("$year-11-01");
        $dias_al_primero_nov = (7 - (int)$nov1->format('N')) % 7;
        $primer_dom_nov = $nov1->modify("+" . $dias_al_primero_nov . " days");
        
        // Horario Verano (UTC-6) vs Invierno (UTC-7)
        if ($dt >= $segundo_dom_mar && $dt < $primer_dom_nov) {
            return 0;
        } else {
            return -1;
        }
    }

    public function process_bank_upload() {
        set_time_limit(0);
        ini_set('memory_limit', '-1');
        ob_clean();
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['status' => 'error', 'message' => 'Método no permitido']);
            exit;
        }

        $bankType = $_POST['bank_type'] ?? 'OTROS';
        $filePath = '';
        $isTempFile = false;
        
        // Estructura de carpetas: _assets/uploads/BANCO/AÑO/MES/
        $baseUploadsDir = __DIR__ . '/../uploads/';
        $subPath = $bankType . '/' . date('Y') . '/' . date('m') . '/';
        $targetDir = $baseUploadsDir . $subPath;

        if (!file_exists($targetDir)) {
            mkdir($targetDir, 0777, true);
        }

        // Nombre de archivo seguro
        $originalName = $_POST['file_name'] ?? ($_FILES['report_file']['name'] ?? 'archivo.tmp');
        $safeName = date('His') . '_' . basename($originalName);
        $targetFile = $targetDir . $safeName;
        
        // RUTA RELATIVA PARA LA BASE DE DATOS (Trazabilidad)
        $dbFilePath = $subPath . $safeName;

        // Soporte para datos en Base64
        if (!empty($_POST['file_data'])) {
            if (file_put_contents($targetFile, base64_decode($_POST['file_data'])) === false) {
                echo json_encode(['status' => 'error', 'message' => 'Error al guardar el archivo']);
                exit;
            }
            $filePath = $targetFile;
        } 
        // Soporte para subida tradicional
        elseif (isset($_FILES['report_file']) && $_FILES['report_file']['error'] === UPLOAD_ERR_OK) {
            if (move_uploaded_file($_FILES['report_file']['tmp_name'], $targetFile)) {
                $filePath = $targetFile;
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Error al mover el archivo subido']);
                exit;
            }
        }

        if (empty($filePath)) {
            $errCode = $_FILES['report_file']['error'] ?? 'NO_FILE';
            echo json_encode(['status' => 'error', 'message' => "Error al recibir el archivo (Code: $errCode)."]);
            exit;
        }

        $extension = pathinfo($_POST['file_name'] ?? ($_FILES['report_file']['name'] ?? $filePath), PATHINFO_EXTENSION);

        $server = "192.168.0.6";
        $db = "TG";
        $user = "cguser";
        $pass = "sahei1712";

        $coreMap = [
            'BANORTE' => [
                'Afiliación' => 'Afiliacion',
                'Afiliacion' => 'Afiliacion',
                'Nombre de Afiliación' => 'Nombre_Afiliacion',
                'Nombre de Afiliacion' => 'Nombre_Afiliacion',
                'Moneda' => 'Moneda',
                'Estatus de Transacción' => 'Estatus',
                'Estatus de Transaccion' => 'Estatus',
                'Tipo transaccion' => 'Tipo_Transaccion',
                'Tipo de Transacción' => 'Tipo_Transaccion',
                'Tipo de Transaccion' => 'Tipo_Transaccion',
                'Número de Control' => 'ID_Externo',          
                'Numero de Control' => 'ID_Externo',
                'Número de Tarjeta' => 'Tarjeta',
                'Numero de Tarjeta' => 'Tarjeta',
                'Tipo de Tarjeta' => 'Tipo_Tarjeta',
                'Monto de Transacción Signo' => 'Monto',
                'Monto de Transaccion Signo' => 'Monto',
                'Fecha Transacción' => 'Fecha_Transaccion',
                'Fecha Transaccion' => 'Fecha_Transaccion',
                'Código Autorización' => 'Codigo_Autorizacion',
                'Codigo Autorizacion' => 'Codigo_Autorizacion',
                'Referencia' => 'Referencia_Pago',
                'Terminal ID' => 'Terminal',                  
                'Terminal' => 'Terminal',
                'Lote de Transacción' => 'Lote',
                'Lote' => 'Lote',
                'Hora de Transacción' => 'Hora',
                'Hora Transacción' => 'Hora',
                'Hora' => 'Hora',
                'Referencia Interbancaria' => 'Referencia',
                'Fecha Depósito' => 'Fecha_Deposito',
                'Fecha Deposito' => 'Fecha_Deposito',
                'Fecha DepÃ³sito' => 'Fecha_Deposito',
                'Fecha de Depósito' => 'Fecha_Deposito',
                'Fecha de Deposito' => 'Fecha_Deposito',
                'Fecha Aplicación' => 'Fecha_Deposito',
                'Fecha Aplicacion' => 'Fecha_Deposito',
                'Fecha AplicaciÃ³n' => 'Fecha_Deposito',
                'Fecha de Aplicación' => 'Fecha_Deposito',
                'Fecha de Aplicacion' => 'Fecha_Deposito',
                'Fecha de AplicaciÃ³n' => 'Fecha_Deposito',
            ],
            'SANTANDER' => [
                'ID movimiento' => 'ID_Externo',              
                'Fecha Transacción' => 'Fecha_Transaccion',
                'Hora de Transacción' => 'Hora',
                'Hora Transacción' => 'Hora',
                'Afiliación' => 'Afiliacion',
                'Nombre del comercio' => 'Comercio',
                'Tipo de Transacción' => 'Tipo_Transaccion',
                'Tipo Transacción' => 'Tipo_Transaccion',
                'Tarjeta' => 'Tarjeta',
                'Cod. Terminal' => 'Terminal',                
                'Terminal ID' => 'Terminal',
                'Operación' => 'Operacion',
                'Tipo de Tarjeta' => 'Tipo_Tarjeta',
                'Tipo Tarjeta' => 'Tipo_Tarjeta',
                'Número de Tarjeta' => 'Tarjeta_Numero',
                'Tarjeta Número' => 'Tarjeta_Numero',
                'Código Autorización' => 'Codigo_Autorizacion',
                'Cod. Aut' => 'Codigo_Autorizacion',
                'Total' => 'Monto',                           
                'Monto de Transacción Signo' => 'Monto',
                'Comisión' => 'Comision',
                'Referencia' => 'Referencia',
                'Fecha Depósito' => 'Fecha_Deposito',
                'Fecha Deposito' => 'Fecha_Deposito',
                'Fecha DepÃ³sito' => 'Fecha_Deposito',
                'Fecha de Depósito' => 'Fecha_Deposito',
                'Fecha de Deposito' => 'Fecha_Deposito',
                'Fecha Aplicación' => 'Fecha_Deposito',
                'Fecha Aplicacion' => 'Fecha_Deposito',
                'Fecha AplicaciÃ³n' => 'Fecha_Deposito',
                'Fecha de Aplicación' => 'Fecha_Deposito',
                'Fecha de Aplicacion' => 'Fecha_Deposito',
                'Fecha de AplicaciÃ³n' => 'Fecha_Deposito',
            ]
        ];

        // COLUMNAS OFICIALES PERMITIDAS (Definición Global)
        $columnas_oficiales = [
            'ID_Externo', 'Afiliacion', 'Fecha_Transaccion', 'Hora', 
            'Monto', 'Codigo_Autorizacion', 'Terminal', 'Referencia', 'Fecha_Deposito', 'Nombre_Archivo'
        ];

        try {
            $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $inserted = 0;
            $skipped = 0;

            if ($bankType === 'BANORTE') {
                $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
                $sheet = $spreadsheet->getActiveSheet();
                $rows = $sheet->toArray();
                $rawHeader = array_shift($rows);

                $mappedIndices = [];
                foreach ($rawHeader as $i => $h) {
                    $stdName = $this->sanitizar_nombre_columna_php($h, 'BANORTE', $coreMap);
                    if (in_array($stdName, $columnas_oficiales)) {
                        $mappedIndices[$stdName] = $i;
                    }
                }

                // --- VALIDACION 100% FECHA DEPOSITO ---
                if (!isset($mappedIndices['Fecha_Deposito'])) {
                    echo json_encode(['status' => 'error', 'message' => 'No se encontro la columna de Fecha de Deposito/Aplicacion en el archivo.']);
                    exit;
                }
                $idxDepo = $mappedIndices['Fecha_Deposito'];
                foreach ($rows as $row) {
                    if (empty(array_filter($row))) continue;
                    $valDepo = $row[$idxDepo] ?? null;
                    if (empty($valDepo)) {
                        echo json_encode(['status' => 'error', 'message' => 'El archivo no cuenta con el 100% de las fechas de deposito/aplicacion. No se proceso ningun registro.']);
                        exit;
                    }
                }

                // Huellas para duplicados (8 CAMPOS CLAVE + FECHA DEPOSITO)
                $stmtH = $conn->query("SELECT Afiliacion, ID_Externo, Fecha_Transaccion, Monto, Hora, Codigo_Autorizacion, Referencia, Terminal, Fecha_Deposito FROM banco_banorte");
                $huellas = [];
                while ($r = $stmtH->fetch(PDO::FETCH_ASSOC)) {
                    $fch = ($r['Fecha_Transaccion'] instanceof DateTime) ? $r['Fecha_Transaccion']->format('Y-m-d') : substr((string)$r['Fecha_Transaccion'], 0, 10);
                    $fch_dep = ($r['Fecha_Deposito'] instanceof DateTime) ? $r['Fecha_Deposito']->format('Y-m-d') : substr((string)$r['Fecha_Deposito'], 0, 10);
                    $key = trim($r['Afiliacion']??'') . '|' . trim($r['ID_Externo']??'') . '|' . $fch . '|' . number_format((float)$r['Monto'], 2, '.', '') . '|' . trim($r['Hora']??'') . '|' . trim($r['Codigo_Autorizacion']??'') . '|' . trim($r['Referencia']??'') . '|' . trim($r['Terminal']??'') . '|' . $fch_dep;
                    $huellas[$key] = true;
                }

                $sqlIns = "INSERT INTO banco_banorte (".implode(",", $columnas_oficiales).") VALUES (".implode(",", array_fill(0, count($columnas_oficiales), "?")).")";
                $ins = $conn->prepare($sqlIns);

                foreach ($rows as $row) {
                    if (empty(array_filter($row))) continue;

                    $dataRow = [];
                    foreach($columnas_oficiales as $col) {
                        if ($col === 'Nombre_Archivo') { $dataRow[$col] = $dbFilePath; continue; }
                        $val = isset($mappedIndices[$col]) ? $row[$mappedIndices[$col]] : null;
                        
                        if ($col === 'Monto') $val = (float)str_replace(['$', ','], '', $val ?? 0);
                        if ($col === 'Fecha_Transaccion' || $col === 'Fecha_Deposito') {
                            if ($val) {
                                $d = \DateTime::createFromFormat('d/m/Y', $val);
                                if (!$d) $d = new \DateTime($val);
                                $val = $d ? $d->format('Y-m-d') : null;
                            }
                        }
                        if ($col === 'Afiliacion') $val = ltrim(trim($val ?? ''), '0');
                        if ($col === 'Hora' && $val && isset($dataRow['Fecha_Transaccion'])) {
                            $ajuste = $this->obtener_ajuste_juarez_php($dataRow['Fecha_Transaccion']);
                            if ($ajuste !== 0) {
                                try {
                                    $dt_full = new \DateTime($dataRow['Fecha_Transaccion'] . " " . $val);
                                    $dt_full->modify("$ajuste hours");
                                    $val = $dt_full->format('H:i:s');
                                    $dataRow['Fecha_Transaccion'] = $dt_full->format('Y-m-d');
                                } catch(\Exception $e) {}
                            }
                        }
                        $dataRow[$col] = ($val === null || $val === '') ? null : $val;
                    }

                    $keyRow = trim($dataRow['Afiliacion']??'') . '|' . trim($dataRow['ID_Externo']??'') . '|' . ($dataRow['Fecha_Transaccion']??'') . '|' . number_format((float)($dataRow['Monto']??0), 2, '.', '') . '|' . trim($dataRow['Hora']??'') . '|' . trim($dataRow['Codigo_Autorizacion']??'') . '|' . trim($dataRow['Referencia']??'') . '|' . trim($dataRow['Terminal']??'') . '|' . ($dataRow['Fecha_Deposito']??'');
                    
                    if (($dataRow['Monto'] ?? 0) <= 0) { $skipped++; continue; }
                    if (isset($huellas[$keyRow])) { $skipped++; continue; }

                    $ins->execute(array_values($dataRow));
                    $inserted++;
                }
                
                            } elseif ($bankType === 'SANTANDER') {
                                $handle = fopen($filePath, "r");
                                $rawHeader = fgetcsv($handle, 0, ",");
                                
                                // 1. Mapear Índices
                                $mappedIndices = [];
                                foreach ($rawHeader as $i => $h) {
                                    $stdName = $this->sanitizar_nombre_columna_php($h, 'SANTANDER', $coreMap);
                                    if (in_array($stdName, $columnas_oficiales)) {
                                        $mappedIndices[$stdName] = $i;
                                    }
                                }

                                // --- VALIDACION 100% FECHA DEPOSITO ---
                                if (!isset($mappedIndices['Fecha_Deposito'])) {
                                    echo json_encode(['status' => 'error', 'message' => 'No se encontro la columna de Fecha de Deposito en el archivo CSV.']);
                                    exit;
                                }
                                $idxDepo = $mappedIndices['Fecha_Deposito'];
                                $allRows = [];
                                while (($row = fgetcsv($handle, 0, ",")) !== FALSE) {
                                    if (empty(array_filter($row))) continue;
                                    if (empty($row[$idxDepo] ?? null)) {
                                        echo json_encode(['status' => 'error', 'message' => 'El archivo no cuenta con el 100% de las fechas de deposito. No se proceso ningun registro.']);
                                        exit;
                                    }
                                    $allRows[] = $row;
                                }
                                fclose($handle);
                
                                // 2. Huellas para duplicados (8 CAMPOS CLAVE + FECHA DEPOSITO)
                                $stmt = $conn->query("SELECT Afiliacion, ID_Externo, Fecha_Transaccion, Monto, Hora, Codigo_Autorizacion, Referencia, Terminal, Fecha_Deposito FROM banco_getnet");
                                $huellas = [];
                                while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                                    $fch = ($r['Fecha_Transaccion'] instanceof DateTime) ? $r['Fecha_Transaccion']->format('Y-m-d') : substr((string)$r['Fecha_Transaccion'], 0, 10);
                                    $fch_dep = ($r['Fecha_Deposito'] instanceof DateTime) ? $r['Fecha_Deposito']->format('Y-m-d') : substr((string)$r['Fecha_Deposito'], 0, 10);
                                    $key = trim($r['Afiliacion'] ?? '') . '|' . 
                                           trim($r['ID_Externo'] ?? '') . '|' . 
                                           $fch . '|' . 
                                           number_format((float)$r['Monto'], 2, '.', '') . '|' .
                                           trim($r['Hora'] ?? '') . '|' .
                                           trim($r['Codigo_Autorizacion'] ?? '') . '|' .
                                           trim($r['Referencia'] ?? '') . '|' .
                                           trim($r['Terminal'] ?? '') . '|' .
                                           $fch_dep;
                                    $huellas[$key] = true;
                                }
                
                                // 3. Preparar SQL Estándar
                                $sqlIns = "INSERT INTO banco_getnet (".implode(",", $columnas_oficiales).") VALUES (".implode(",", array_fill(0, count($columnas_oficiales), "?")).")";
                                $ins = $conn->prepare($sqlIns);
                
                                // Ruta relativa para la DB
                                $dbFilePath = $subPath . $safeName;
                
                                foreach ($allRows as $row) {
                                    $dataRow = [];
                                    foreach($columnas_oficiales as $col) {
                                        if ($col === 'Nombre_Archivo') { $dataRow[$col] = $dbFilePath; continue; }
                                        $val = isset($mappedIndices[$col]) ? ($row[$mappedIndices[$col]] ?? null) : null;
                                        
                                        if ($col === 'Monto') $val = (float)str_replace(['$', ','], '', $val ?? 0);
                                        if ($col === 'Fecha_Transaccion' || $col === 'Fecha_Deposito') {
                                            if ($val) {
                                                $d = \DateTime::createFromFormat('d/m/Y', $val);
                                                if (!$d) $d = new \DateTime($val);
                                                $val = $d ? $d->format('Y-m-d') : null;
                                            }
                                        }
                                        if ($col === 'Afiliacion') $val = ltrim(trim($val ?? ''), '0');
                                        if ($col === 'Hora' && $val) {
                                            $h_clean = strtolower(trim($val));
                                            
                                            // Normalización robusta: quitar puntos, asegurar un solo espacio antes de am/pm
                                            $h_clean = str_replace('.', '', $h_clean);
                                            $h_clean = preg_replace('/([ap])\s*m/', '$1m', $h_clean); // unir a m -> am
                                            $h_clean = str_replace(['am', 'pm'], [' am', ' pm'], $h_clean);
                                            $h_clean = preg_replace('/\s+/', ' ', $h_clean);
                                            $h_clean = trim($h_clean);

                                            if (strpos($h_clean, 'am') !== false || strpos($h_clean, 'pm') !== false) {
                                                $d_h = \DateTime::createFromFormat('h:i:s a', $h_clean);
                                                if (!$d_h) $d_h = \DateTime::createFromFormat('g:i:s a', $h_clean);
                                                if (!$d_h) $d_h = \DateTime::createFromFormat('h:i a', $h_clean);
                                                if (!$d_h) $d_h = \DateTime::createFromFormat('g:i a', $h_clean);
                                                
                                                if ($d_h) {
                                                    $val = $d_h->format('H:i:s');
                                                }
                                            }
                                            
                                            // Asegurar ceros a la izquierda si ya es 24h pero falta el cero (ej: 9:05:00)
                                            if (preg_match('/^\d{1,2}:\d{2}:\d{2}$/', $val)) {
                                                $parts = explode(':', $val);
                                                $val = sprintf("%02d:%02d:%02d", $parts[0], $parts[1], $parts[2]);
                                            }
                                            
                                            // AJUSTE HORARIO JUAREZ
                                            if (isset($dataRow['Fecha_Transaccion'])) {
                                                $ajuste = $this->obtener_ajuste_juarez_php($dataRow['Fecha_Transaccion']);
                                                if ($ajuste !== 0) {
                                                    try {
                                                        $dt_full = new \DateTime($dataRow['Fecha_Transaccion'] . " " . $val);
                                                        $dt_full->modify("$ajuste hours");
                                                        $val = $dt_full->format('H:i:s');
                                                        $dataRow['Fecha_Transaccion'] = $dt_full->format('Y-m-d');
                                                    } catch(\Exception $e) {}
                                                }
                                            }
                                        }
                                        $dataRow[$col] = ($val === null || $val === '') ? null : $val;
                                    }
                
                                    // Huella para saltar duplicados (+ FECHA DEPOSITO)
                                    $huella = trim($dataRow['Afiliacion']??'') . '|' . trim($dataRow['ID_Externo']??'') . '|' . ($dataRow['Fecha_Transaccion']??'') . '|' . number_format((float)($dataRow['Monto']??0), 2, '.', '') . '|' . trim($dataRow['Hora']??'') . '|' . trim($dataRow['Codigo_Autorizacion']??'') . '|' . trim($dataRow['Referencia']??'') . '|' . trim($dataRow['Terminal']??'') . '|' . ($dataRow['Fecha_Deposito']??'');
                                    
                                    if (($dataRow['Monto'] ?? 0) <= 0) { $skipped++; continue; }
                                    if (isset($huellas[$huella])) { $skipped++; continue; }
                
                                    $ins->execute(array_values($dataRow));
                                    $inserted++;
                                }
                                fclose($handle);
                            }

            // Limpiar logs de debug previos
            if (file_exists('debug_santander_upload.log')) @unlink('debug_santander_upload.log');
            if (file_exists('conciliacion_debug.log')) @unlink('conciliacion_debug.log');

            // Limpiar archivo temporal si se creó desde Base64
            if ($isTempFile && file_exists($filePath)) {
                @unlink($filePath);
            }

            echo json_encode([
                'status' => 'success', 
                'inserted' => $inserted, 
                'skipped' => $skipped
            ]);

        } catch (Exception $e) {
            if ($isTempFile && file_exists($filePath)) @unlink($filePath);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    // =========================================================================
    public function conc_test() {
        $this->setup_conciliacion_v2(true); // Auto-migration V2 (Silencioso)
        echo $this->twig->render($this->route . 'test.html');
    }

    // =========================================================================
    // 1. OBTENER CATÁLOGO DE ESTACIONES (Para ControlGas) - UNIFICADO CON AFIL
    // =========================================================================
    public function get_estaciones_catalogo() {
        ob_clean();
        header('Content-Type: application/json');
        
        $server = "192.168.0.6"; 
        $db = "TG"; 
        $user = "cguser"; 
        $pass = "sahei1712"; 
        
        try {
            $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // JOIN con Tesoreria_afil para usar EL MISMO criterio de RFC que los bancos
            $sql = "SELECT DISTINCT 
                        T1.Codigo, 
                        T1.Nombre, 
                        ISNULL(T2.rfc, 'FORANEAS') as RFC 
                    FROM Estaciones T1
                    LEFT JOIN Tesoreria_afil T2 ON T1.Codigo = T2.estacion_id
                    ORDER BY T1.Nombre";

            $stmt = $conn->query($sql);
            $result = [];
            while($row = $stmt->fetch(PDO::FETCH_ASSOC)){
                $rfc = trim($row['RFC']);
                // Normalizar valores vacíos
                if($rfc === '' || $rfc === 'NULL') {
                    $rfc = 'FORANEAS';
                }
                $row['RFC'] = $rfc;
                $result[] = $row;
            }
            
            echo json_encode(["status" => "success", "respuesta" => $result]);

        } catch (PDOException $e) {
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
        exit;
    }

    // =========================================================================
    // 2. OBTENER VENTAS LOCAL (Reemplazo de API externa)
    // =========================================================================
    public function get_ventas_local() {
        ob_clean();
        header('Content-Type: application/json');

        // 1. Leer el JSON entrante (Misma estructura que enviaba el JS a la API vieja)
        $input = json_decode(file_get_contents('php://input'), true);
        
        // Validar datos básicos
        if (!isset($input['Datos']['FechaInicial']) || !isset($input['Datos']['Gasolinera'])) {
            echo json_encode(["status" => "error", "message" => "Faltan parámetros"]);
            exit;
        }

        $fIniStr = $input['Datos']['FechaInicial']; // YYYYMMDD
        $fFinStr = $input['Datos']['FechaFinal'];   // YYYYMMDD
        $codGas  = intval($input['Datos']['Gasolinera']);

        // Configuración BD (Usar tus credenciales)
        $server = "192.168.0.6"; 
        $db = "TG"; 
        $user = "cguser"; 
        $pass = "sahei1712"; 

        try {
            $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // 2. Query SQL optimizado
            $sql = "
                DECLARE @fInicio INT = DATEDIFF(dd, 0, :fIni) + 1;
                DECLARE @fFin INT    = DATEDIFF(dd, 0, :fFin) + 1;

                SELECT 
                    -- ID ÚNICO
                    CAST(i.fch AS VARCHAR) + '-' + 
                    CAST(i.codisl AS VARCHAR) + '-' + 
                    CAST(i.nrotur AS VARCHAR) + '-' + 
                    CAST(i.codval AS VARCHAR) AS ID_Unico,

                    -- DATOS GENERALES
                    CONVERT(VARCHAR, CONVERT(SMALLDATETIME, i.fch - 1, 106), 103) AS FechaVisual,
                    i.fch, 
                    i.nrotur AS Turno,
                    v.den AS Concepto,
                    CAST(i.mto AS FLOAT) AS Total,
                    
                    -- COLUMNAS QUE FALTABAN
                    i.codgas AS CodEstacion,  -- <--- FALTABA ESTO
                    g.abr AS Estacion,

                    -- LÓGICA DE TIPO (RESTAURADA)
                    CASE
                        WHEN i.codval = 6 THEN 'EFECTIVO'
                        WHEN i.codval = 192 THEN 'MORALLA'
                        WHEN i.codval = 5 THEN 'BILLETE'
                        WHEN i.codval IN (-3001, 194, 197, -3002, 204, 167, 28, 127, 207, 196, 198, 211, 203, 206, 212, 201, 210, 209, 205) THEN 'VALES/TARJETAS'
                        WHEN i.codval = 28 THEN 'CLTCREDITO'
                        WHEN i.codval = 127 THEN 'CLTDEBITO'
                        WHEN i.codval = 145 THEN 'PROMOCIONES MKT'
                        WHEN i.codval = 216 THEN 'ULTRAGAS'
                        ELSE 'OTROS'
                    END AS Tipo

                FROM [SG12].[dbo].[Ingresos] i
                INNER JOIN [SG12].[dbo].[Gasolineras] g ON i.codgas = g.cod
                INNER JOIN [SG12].[dbo].[Valores] v ON i.codval = v.cod

                WHERE 
                    i.fch BETWEEN @fInicio AND @fFin
                    AND i.codgas = :codGas
                
                ORDER BY i.fch, i.nrotur, i.codval
            ";

            $stmt = $conn->prepare($sql);
            $stmt->bindParam(':fIni', $fIniStr);
            $stmt->bindParam(':fFin', $fFinStr);
            $stmt->bindParam(':codGas', $codGas);
            $stmt->execute();

            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 3. Estructura de respuesta compatible con tu JS actual
            // El JS espera: { respuesta: [ ...array... ] } (Basado en tu código anterior)
            echo json_encode([
                "status" => "success", 
                "respuesta" => $data 
            ]);

        } catch (PDOException $e) {
            echo json_encode(["status" => "error", "message" => "SQL Error: " . $e->getMessage()]);
        }
        exit;
    }

    // =========================================================================
    // TESORERIA GENERAL (0956)
    // =========================================================================
    public function get_tesoreria_data() {
        ob_clean();
        header('Content-Type: application/json');

        $server = "192.168.0.6";
        $db = "TG";
        $user = "cguser";
        $pass = "sahei1712";
        
        $year = $_GET['year'] ?? date('Y');
        $month = $_GET['month'] ?? date('m');

        try {
            $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $sql = "SELECT TOP 4000 Fecha, Referencia, Descripcion, Sucursal, Depositos, Retiros, Saldo 
                    FROM Tesoreria_0956 
                    WHERE YEAR(Fecha) = ? AND MONTH(Fecha) = ?
                    ORDER BY Fecha ASC";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute([$year, $month]);
            
            $result = [];

            while($row = $stmt->fetch(PDO::FETCH_ASSOC)){
                $fechaVal = $row['Fecha'];
                if ($fechaVal instanceof DateTime) {
                    $row['Fecha'] = $fechaVal->format('Y-m-d');
                } else {
                    $row['Fecha'] = substr((string)$fechaVal, 0, 10);
                }

                $row['Depositos'] = (float)$row['Depositos'];
                $row['Retiros']   = (float)$row['Retiros'];
                $row['Saldo']     = (float)$row['Saldo'];
                
                $result[] = $row;
            }

            echo json_encode(["status" => "success", "data" => $result]);

        } catch (PDOException $e) {
            echo json_encode(["status" => "error", "message" => "Error BD: " . $e->getMessage()]);
        }
        exit;
    }

    // =========================================================================
    // 2. TESORERIA BANORTE
    // =========================================================================
    public function get_tesoreria_banorte() {
        ob_clean();
        header('Content-Type: application/json');
        $server = "192.168.0.6"; $db = "TG"; $user = "cguser"; $pass = "sahei1712";
        $year = $_GET['year'] ?? date('Y');
        $month = $_GET['month'] ?? date('m');

        try {
            $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Traemos A.rfc
            $sqlAfil = "SELECT A.afiliacion, 
                               ISNULL(S.Nombre, V.Nombre) as Estacion,
                               ISNULL(A.rfc, 'FORANEAS') as RFC 
                        FROM Tesoreria_afil A
                        LEFT JOIN Estaciones S ON A.estacion_id = S.Codigo
                        LEFT JOIN Tesoreria_Estaciones_Virtuales V ON A.estacion_id = V.Codigo
                        WHERE A.entidad_id = 4 
                        AND LEN(ISNULL(A.afiliacion,'')) > 0
                        AND (S.Nombre IS NOT NULL OR V.Nombre IS NOT NULL)";
            
            $stmtAfil = $conn->query($sqlAfil);
            $catalogo = [];
            
            while($r = $stmtAfil->fetch(PDO::FETCH_ASSOC)){
                $catalogo[] = [
                    'afiliacion' => trim($r['afiliacion']),
                    'Estacion'   => $r['Estacion'],
                    'RFC'        => trim($r['RFC'])
                ];
            }

            usort($catalogo, function($a, $b) {
                $res = strcmp($a['Estacion'], $b['Estacion']);
                return ($res == 0) ? strcmp($a['afiliacion'], $b['afiliacion']) : $res;
            });

            $sqlMovs = "SELECT Fecha, Descripcion, Depositos FROM Tesoreria_0956 
                        WHERE Depositos > 0 AND YEAR(Fecha) = ? AND MONTH(Fecha) = ?";
            $stmtMovs = $conn->prepare($sqlMovs);
            $stmtMovs->execute([$year, $month]);
            
            $agrupado = [];
            while($row = $stmtMovs->fetch(PDO::FETCH_ASSOC)){
                $desc = trim($row['Descripcion']);
                if (stripos($desc, 'TOTAL GAS') !== 0 && stripos($desc, 'TotalGas') !== 0 && stripos($desc, 'DIAZ GAS') !== 0) continue;

                $fechaVal = $row['Fecha'];
                $fecha = ($fechaVal instanceof DateTime) ? $fechaVal->format('Y-m-d') : substr((string)$fechaVal, 0, 10);
                $monto = (float)$row['Depositos'];

                foreach ($catalogo as $afilItem) {
                    $afiliacionStr = $afilItem['afiliacion'];
                    if (strpos($desc, $afiliacionStr) !== false) {
                        $key = $fecha . '_' . $afiliacionStr;
                        if (!isset($agrupado[$key])) {
                            $agrupado[$key] = [
                                'Fecha' => $fecha, 'Afiliacion' => $afiliacionStr, 'Estacion' => $afilItem['Estacion'], 'Total' => 0
                            ];
                        }
                        $agrupado[$key]['Total'] += $monto;
                        break; 
                    }
                }
            }

            $resultado = array_values($agrupado);
            usort($resultado, function($a, $b) { return strcmp($a['Fecha'], $b['Fecha']); });

            echo json_encode(["status" => "success", "data" => $resultado, "catalog" => $catalogo]);

        } catch (PDOException $e) {
            echo json_encode(["status" => "error", "message" => "Error BD: " . $e->getMessage()]);
        }
        exit;
    }

    // =========================================================================
    // 3. TESORERIA SANTANDER
    // =========================================================================
    public function get_tesoreria_santander() {
        ob_clean();
        header('Content-Type: application/json');
        $server = "192.168.0.6"; $db = "TG"; $user = "cguser"; $pass = "sahei1712";
        $year = $_GET['year'] ?? date('Y');
        $month = $_GET['month'] ?? date('m');

        try {
            $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $sqlAfil = "SELECT A.afiliacion, S.Nombre as Estacion, ISNULL(A.rfc, 'FORANEAS') as RFC 
                        FROM Tesoreria_afil A
                        INNER JOIN Estaciones S ON A.estacion_id = S.Codigo
                        WHERE A.entidad_id = 1 AND LEN(ISNULL(A.afiliacion,'')) > 0";
            
            $stmtAfil = $conn->query($sqlAfil);
            $catalogo = [];
            while($r = $stmtAfil->fetch(PDO::FETCH_ASSOC)){
                $catalogo[] = [
                    'afiliacion' => trim($r['afiliacion']),
                    'Estacion'   => $r['Estacion'],
                    'RFC'        => trim($r['RFC'])
                ];
            }
            usort($catalogo, function($a, $b) {
                $res = strcmp($a['Estacion'], $b['Estacion']);
                return ($res == 0) ? strcmp($a['afiliacion'], $b['afiliacion']) : $res;
            });

            $tablas = ['Tesoreria_5117', 'Tesoreria_8973'];
            $movimientosRaw = [];

            foreach ($tablas as $tabla) {
                $check = $conn->query("SELECT count(*) FROM information_schema.tables WHERE table_name = '$tabla'");
                if($check->fetchColumn() > 0) {
                    $sql = "SELECT Fecha, Referencia, Descripcion, Depositos FROM $tabla WHERE Depositos > 0 AND YEAR(Fecha) = ? AND MONTH(Fecha) = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([$year, $month]);
                    while($r = $stmt->fetch(PDO::FETCH_ASSOC)) $movimientosRaw[] = $r;
                }
            }
            
            $agrupado = [];
            foreach($movimientosRaw as $row){
                $ref = trim($row['Referencia']);
                $fechaVal = $row['Fecha'];
                $fecha = ($fechaVal instanceof DateTime) ? $fechaVal->format('Y-m-d') : substr((string)$fechaVal, 0, 10);
                $monto = (float)$row['Depositos'];

                foreach ($catalogo as $afilItem) {
                    $afiliacionStr = $afilItem['afiliacion'];
                    if (stripos($ref, $afiliacionStr) !== false) {
                        $key = $fecha . '_' . $afiliacionStr;
                        if (!isset($agrupado[$key])) {
                            $agrupado[$key] = [
                                'Fecha' => $fecha, 'Afiliacion' => $afiliacionStr, 'Estacion' => $afilItem['Estacion'], 'Total' => 0
                            ];
                        }
                        $agrupado[$key]['Total'] += $monto;
                        break; 
                    }
                }
            }

            $resultado = array_values($agrupado);
            usort($resultado, function($a, $b) { return strcmp($a['Fecha'], $b['Fecha']); });

            echo json_encode(["status" => "success", "data" => $resultado, "catalog" => $catalogo]);
        } catch (PDOException $e) {
            echo json_encode(["status" => "error", "message" => "Error BD: " . $e->getMessage()]);
        }
        exit;
    }

    // =========================================================================
    // 4. TESORERIA AMEX
    // =========================================================================
    public function get_tesoreria_amex() {
        ob_clean();
        header('Content-Type: application/json');
        $server = "192.168.0.6"; $db = "TG"; $user = "cguser"; $pass = "sahei1712";
        $year = $_GET['year'] ?? date('Y');
        $month = $_GET['month'] ?? date('m');

        try {
            $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $sqlAfil = "SELECT A.afiliacion, 
                               ISNULL(S.Nombre, V.Nombre) as Estacion,
                               ISNULL(A.rfc, 'FORANEAS') as RFC 
                        FROM Tesoreria_afil A
                        LEFT JOIN Estaciones S ON A.estacion_id = S.Codigo
                        LEFT JOIN Tesoreria_Estaciones_Virtuales V ON A.estacion_id = V.Codigo
                        WHERE A.entidad_id = 3 
                        AND LEN(ISNULL(A.afiliacion,'')) > 0
                        AND (S.Nombre IS NOT NULL OR V.Nombre IS NOT NULL)";
            
            $stmtAfil = $conn->query($sqlAfil);
            $catalogo = [];
            while($r = $stmtAfil->fetch(PDO::FETCH_ASSOC)){
                $catalogo[] = [
                    'afiliacion' => trim($r['afiliacion']),
                    'Estacion'   => $r['Estacion'],
                    'RFC'        => trim($r['RFC'])
                ];
            }
            usort($catalogo, function($a, $b) {
                $res = strcmp($a['Estacion'], $b['Estacion']);
                return ($res == 0) ? strcmp($a['afiliacion'], $b['afiliacion']) : $res;
            });

            $agrupado = [];
            
            // Fuente 5117
            try {
                $sql5117 = "SELECT Fecha, Concepto, Depositos FROM Tesoreria_5117 WHERE YEAR(Fecha) = ? AND MONTH(Fecha) = ?";
                $stmt = $conn->prepare($sql5117); $stmt->execute([$year, $month]);
                while($row = $stmt->fetch(PDO::FETCH_ASSOC)){
                    $concepto = trim($row['Concepto'] ?? '');
                    if ($concepto === '') continue;
                    $monto = (float)$row['Depositos'];
                    $fecha = ($row['Fecha'] instanceof DateTime) ? $row['Fecha']->format('Y-m-d') : substr((string)$row['Fecha'], 0, 10);
                    foreach ($catalogo as $afilItem) {
                        if (stripos($concepto, $afilItem['afiliacion']) !== false) {
                            $key = $fecha . '_' . $afilItem['afiliacion'];
                            if (!isset($agrupado[$key])) $agrupado[$key] = ['Fecha'=>$fecha,'Afiliacion'=>$afilItem['afiliacion'],'Estacion'=>$afilItem['Estacion'],'Total'=>0];
                            $agrupado[$key]['Total'] += $monto;
                            break;
                        }
                    }
                }
            } catch(Exception $e){}

            // Fuente 0956
            try {
                $sql0956 = "SELECT Fecha, DescripcionDetallada, Depositos FROM Tesoreria_0956 WHERE DescripcionDetallada IS NOT NULL AND YEAR(Fecha) = ? AND MONTH(Fecha) = ?";
                $stmt = $conn->prepare($sql0956); $stmt->execute([$year, $month]);
                while($row = $stmt->fetch(PDO::FETCH_ASSOC)){
                    $detalle = trim($row['DescripcionDetallada']);
                    if ($detalle === '') continue;
                    $monto = (float)$row['Depositos'];
                    $fecha = ($row['Fecha'] instanceof DateTime) ? $row['Fecha']->format('Y-m-d') : substr((string)$row['Fecha'], 0, 10);
                    foreach ($catalogo as $afilItem) {
                        if (stripos($detalle, $afilItem['afiliacion']) !== false) {
                            $key = $fecha . '_' . $afilItem['afiliacion'];
                            if (!isset($agrupado[$key])) $agrupado[$key] = ['Fecha'=>$fecha,'Afiliacion'=>$afilItem['afiliacion'],'Estacion'=>$afilItem['Estacion'],'Total'=>0];
                            $agrupado[$key]['Total'] += $monto;
                            break;
                        }
                    }
                }
            } catch(Exception $e){}

            $resultado = array_values($agrupado);
            usort($resultado, function($a, $b) { return strcmp($a['Fecha'], $b['Fecha']); });
            echo json_encode(["status" => "success", "data" => $resultado, "catalog" => $catalogo]);

        } catch (PDOException $e) { echo json_encode(["status" => "error", "message" => $e->getMessage()]); }
        exit;
    }

    // =========================================================================
    // 5. TESORERIA AFIRME
    // =========================================================================
    public function get_tesoreria_afirme() {
        ob_clean();
        header('Content-Type: application/json');
        $server = "192.168.0.6"; $db = "TG"; $user = "cguser"; $pass = "sahei1712";
        $id_afirme = 13;
        $year = $_GET['year'] ?? date('Y');
        $month = $_GET['month'] ?? date('m'); 

        try {
            $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $sqlAfil = "SELECT A.afiliacion, 
                               ISNULL(S.Nombre, V.Nombre) as Estacion,
                               ISNULL(A.rfc, 'FORANEAS') as RFC 
                        FROM Tesoreria_afil A
                        LEFT JOIN Estaciones S ON A.estacion_id = S.Codigo
                        LEFT JOIN Tesoreria_Estaciones_Virtuales V ON A.estacion_id = V.Codigo
                        WHERE A.entidad_id = $id_afirme 
                        AND LEN(ISNULL(A.afiliacion,'')) > 0
                        AND (S.Nombre IS NOT NULL OR V.Nombre IS NOT NULL)";
            
            $stmtAfil = $conn->query($sqlAfil);
            $catalogo = [];
            while($r = $stmtAfil->fetch(PDO::FETCH_ASSOC)){
                $catalogo[] = [
                    'afiliacion' => trim($r['afiliacion']),
                    'Estacion'   => $r['Estacion'],
                    'RFC'        => trim($r['RFC'])
                ];
            }
            usort($catalogo, function($a, $b) {
                $res = strcmp($a['Estacion'], $b['Estacion']);
                return ($res == 0) ? strcmp($a['afiliacion'], $b['afiliacion']) : $res;
            });

            $sql = "SELECT Fecha, Descripcion, Depositos FROM Tesoreria_Afirme WHERE Depositos > 0 AND Descripcion LIKE '%VENTA%' AND YEAR(Fecha) = ? AND MONTH(Fecha) = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$year, $month]);
            $agrupado = [];

            while($row = $stmt->fetch(PDO::FETCH_ASSOC)){
                $descripcion = trim($row['Descripcion'] ?? '');
                $fechaVal = $row['Fecha'];
                $fecha = ($fechaVal instanceof DateTime) ? $fechaVal->format('Y-m-d') : substr((string)$fechaVal, 0, 10);
                $monto = (float)$row['Depositos'];

                foreach ($catalogo as $afilItem) {
                    if (stripos($descripcion, $afilItem['afiliacion']) !== false) {
                        $key = $fecha . '_' . $afilItem['afiliacion'];
                        if (!isset($agrupado[$key])) $agrupado[$key] = ['Fecha'=>$fecha,'Afiliacion'=>$afilItem['afiliacion'],'Estacion'=>$afilItem['Estacion'],'Total'=>0];
                        $agrupado[$key]['Total'] += $monto;
                        break; 
                    }
                }
            }

            $resultado = array_values($agrupado);
            usort($resultado, function($a, $b) { return strcmp($a['Fecha'], $b['Fecha']); });
            echo json_encode(["status" => "success", "data" => $resultado, "catalog" => $catalogo]);

        } catch (PDOException $e) { echo json_encode(["status" => "error", "message" => $e->getMessage()]); }
        exit;
    }

    // =========================================================================
    // 2. OBTENER TRANSACCIONES DEL BANCO (GETNET / BANORTE)
    // =========================================================================
    public function get_transacciones_banco() {
        ob_clean();
        header('Content-Type: application/json');

        $eid = $_GET['entidad_id'] ?? null;
        $afiliacion = $_GET['afiliacion'] ?? null;
        $year = $_GET['year'] ?? date('Y');
        $month = $_GET['month'] ?? date('m');

        if (!$eid || !$afiliacion) {
            echo json_encode(["status" => "error", "message" => "Faltan parámetros"]);
            exit;
        }

        $server = "192.168.0.6"; $db = "TG"; $user = "cguser"; $pass = "sahei1712";

        try {
            $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $tabla = ($eid == 1) ? "banco_getnet" : (($eid == 4) ? "banco_banorte" : "");
            
            if (empty($tabla)) {
                echo json_encode(["status" => "success", "data" => []]);
                exit;
            }

            // QUERY ESTANDARIZADA
            $sql = "SELECT 
                        ID_Externo,
                        Fecha_Transaccion, 
                        Monto, 
                        Afiliacion,
                        Terminal,
                        Hora,
                        Codigo_Autorizacion,
                        Referencia,
                        Nombre_Archivo,
                        Fecha_Deposito
                    FROM $tabla
                    WHERE YEAR(Fecha_Transaccion) = ? 
                      AND MONTH(Fecha_Transaccion) = ? 
                      AND Afiliacion = ?
                    ORDER BY Fecha_Transaccion ASC";

            $stmt = $conn->prepare($sql);
            $stmt->execute([$year, $month, $afiliacion]);
            
            $result = [];
            while($row = $stmt->fetch(PDO::FETCH_ASSOC)){
                // GENERACIÓN DE ID DETERMINISTA ABSOLUTA (Huella Digital de 8 campos)
                // Usamos hash siempre, ya que ID_Externo en Santander puede venir duplicado.
                $hashData = 
                    (string)($row['Afiliacion'] ?? '') . 
                    (string)($row['Fecha_Transaccion'] ?? '') . 
                    (string)($row['Hora'] ?? '') . 
                    (string)($row['Monto'] ?? '') . 
                    (string)($row['Codigo_Autorizacion'] ?? '') .
                    (string)($row['Terminal'] ?? '') .
                    (string)($row['Referencia'] ?? '') .
                    (string)($row['ID_Externo'] ?? ''); 
                
                $idTransaccion = 'tx_' . md5($hashData);
                
                $fechaVal = $row['Fecha_Transaccion'];
                $fechaIso = ($fechaVal instanceof DateTime) ? $fechaVal->format('Y-m-d') : substr((string)$fechaVal, 0, 10);
                
                $fechaDepoVal = $row['Fecha_Deposito'];
                $fechaDepoIso = null;
                if ($fechaDepoVal) {
                    $fechaDepoIso = ($fechaDepoVal instanceof DateTime) ? $fechaDepoVal->format('Y-m-d') : substr((string)$fechaDepoVal, 0, 10);
                }

                $result[] = [
                    'IdTransaccion' => $idTransaccion,
                    'ID_Externo' => $row['ID_Externo'], 
                    'FechaTransaccion' => $fechaIso,
                    'FechaAplicacion' => $fechaDepoIso ?? $fechaIso,
                    'FechaConciliacion' => $fechaIso,
                    'Total' => (float)$row['Monto'],
                    'Concepto' => 'Venta',
                    'Afiliacion' => $row['Afiliacion'],
                    'Terminal_ID' => $row['Terminal'],
                    'Hora' => $row['Hora'],
                    'Codigo_Autorizacion' => $row['Codigo_Autorizacion'],
                    'Referencia' => $row['Referencia'],
                    'Nombre_Archivo' => $row['Nombre_Archivo'] // TRAZABILIDAD
                ];
            }

            echo json_encode(["status" => "success", "data" => $result]);

        } catch (PDOException $e) { echo json_encode(["status" => "error", "message" => $e->getMessage()]); }
        exit;
    }

    public function get_transacciones_por_deposito() {
        ob_clean();
        header('Content-Type: application/json');

        $eid = $_GET['entidad_id'] ?? null;
        $afiliacion = $_GET['afiliacion'] ?? null;
        $year = $_GET['year'] ?? date('Y');
        $month = $_GET['month'] ?? date('m');

        if (!$eid || !$afiliacion) {
            echo json_encode(["status" => "error", "message" => "Faltan parámetros"]);
            exit;
        }

        $server = "192.168.0.6"; $db = "TG"; $user = "cguser"; $pass = "sahei1712";

        try {
            $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $tabla = ($eid == 1) ? "banco_getnet" : (($eid == 4) ? "banco_banorte" : "");
            
            if (empty($tabla)) {
                echo json_encode(["status" => "success", "data" => []]);
                exit;
            }

            // BUSQUEDA POR FECHA DE DEPOSITO
            $sql = "SELECT 
                        ID_Externo,
                        Fecha_Transaccion, 
                        Monto, 
                        Afiliacion,
                        Terminal,
                        Hora,
                        Codigo_Autorizacion,
                        Referencia,
                        Nombre_Archivo,
                        Fecha_Deposito
                    FROM $tabla
                    WHERE YEAR(Fecha_Deposito) = ? 
                      AND MONTH(Fecha_Deposito) = ? 
                      AND Afiliacion = ?
                    ORDER BY Fecha_Deposito ASC";

            $stmt = $conn->prepare($sql);
            $stmt->execute([$year, $month, $afiliacion]);
            
            $result = [];
            while($row = $stmt->fetch(PDO::FETCH_ASSOC)){
                $hashData = 
                    (string)($row['Afiliacion'] ?? '') . 
                    (string)($row['Fecha_Transaccion'] ?? '') . 
                    (string)($row['Hora'] ?? '') . 
                    (string)($row['Monto'] ?? '') . 
                    (string)($row['Codigo_Autorizacion'] ?? '') .
                    (string)($row['Terminal'] ?? '') .
                    (string)($row['Referencia'] ?? '') .
                    (string)($row['ID_Externo'] ?? ''); 
                
                $idTransaccion = 'tx_' . md5($hashData);
                
                $fechaVal = $row['Fecha_Transaccion'];
                $fechaIso = ($fechaVal instanceof DateTime) ? $fechaVal->format('Y-m-d') : substr((string)$fechaVal, 0, 10);
                
                $fechaDepoVal = $row['Fecha_Deposito'];
                $fechaDepoIso = ($fechaDepoVal instanceof DateTime) ? $fechaDepoVal->format('Y-m-d') : substr((string)$fechaDepoVal, 0, 10);

                $result[] = [
                    'IdTransaccion' => $idTransaccion,
                    'ID_Externo' => $row['ID_Externo'], 
                    'FechaTransaccion' => $fechaIso,
                    'Fecha_Deposito' => $fechaDepoIso,
                    'Total' => (float)$row['Monto'],
                    'Afiliacion' => $row['Afiliacion']
                ];
            }

            echo json_encode(["status" => "success", "data" => $result]);

        } catch (PDOException $e) { echo json_encode(["status" => "error", "message" => $e->getMessage()]); }
        exit;
    }

    // FUNCIÓN PARA SERVIR ARCHIVOS DEL BANCO
    public function view_bank_file() {
        while (ob_get_level()) ob_end_clean();

        $file = $_GET['file'] ?? '';
        if (empty($file)) { http_response_code(400); exit("Archivo no especificado"); }

        $file = str_replace(['../', '..\\'], '', $file);
        
        // 1. Intentar ruta local del proyecto
        $baseDirLocal = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR;
        $fullPath = $baseDirLocal . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $file);

        if (!file_exists($fullPath)) {
            // 2. Fallback a la ruta absoluta del IIS (Donde el bot guarda)
            $baseDirIIS = "C:\\inetpub\\wwwroot\\TG_PHP\\_assets\\uploads\\";
            $fullPath = $baseDirIIS . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $file);
        }

        if (!file_exists($fullPath)) {
            http_response_code(404);
            exit("El reporte original no se encuentra en ninguna de las rutas configuradas: " . $file);
        }

        $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
        $mimes = [
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'xls'  => 'application/vnd.ms-excel',
            'csv'  => 'text/csv',
            'pdf'  => 'application/pdf'
        ];
        $ctype = $mimes[$ext] ?? 'application/octet-stream';

        // Configurar Headers para descarga forzada
        header('Content-Description: File Transfer');
        header('Content-Type: ' . $ctype);
        header('Content-Disposition: attachment; filename="' . basename($fullPath) . '"');
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');
        header('Content-Length: ' . filesize($fullPath));
        
        // Leer archivo y terminar ejecución
        readfile($fullPath);
        exit;
    }

    // =========================================================================
    // ACTUALIZAR FECHA DE TRANSACCIÓN (MOVER A OTRO DÍA)
    // =========================================================================
    public function update_transaction_date() {
        ob_clean();
        header('Content-Type: application/json');

        $json = file_get_contents('php://input');
        $data = json_decode($json, true);

        if (!isset($data['id']) || !isset($data['new_date']) || !isset($data['entidad_id'])) {
            echo json_encode(['status' => 'error', 'message' => 'Datos incompletos']);
            exit;
        }

        $server = "192.168.0.6"; $db = "TG"; $user = "cguser"; $pass = "sahei1712";

        try {
            $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $tabla = "";
            $colId = "";
            $colFecha = "Fecha_Transaccion";

            if ($data['entidad_id'] == 1) { // Santander
                $tabla = "banco_getnet";
                $colId = "ID_Externo";
            } else if ($data['entidad_id'] == 4) { // Banorte
                $tabla = "banco_banorte";
                $colId = "ID_Externo";
            }

            $sql = "UPDATE $tabla SET $colFecha = ? WHERE $colId = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$data['new_date'], $data['id']]);

            echo json_encode(['status' => 'success']);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    // =========================================================================
    // CONFIGURACIÓN INICIAL V2 (TABLAS)
    // =========================================================================
    public function setup_conciliacion_v2($silent = false) {
        if (!$silent) {
            ob_clean();
            header('Content-Type: application/json');
        }
        
        $server = "192.168.0.6"; 
        $db = "TG"; 
        $user = "cguser"; 
        $pass = "sahei1712"; 

        try {
            $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Tabla de Grupos (Headers de conciliación)
            $sqlGrupos = "
                IF NOT EXISTS (SELECT * FROM sysobjects WHERE name='Conciliacion_V2_Grupos' AND xtype='U')
                CREATE TABLE Conciliacion_V2_Grupos (
                    id INT IDENTITY(1,1) PRIMARY KEY,
                    uuid UNIQUEIDENTIFIER DEFAULT NEWID(),
                    fecha_creacion DATETIME DEFAULT GETDATE(),
                    fecha_operativa DATE,
                    total_sistema DECIMAL(18,2) DEFAULT 0,
                    total_banco DECIMAL(18,2) DEFAULT 0,
                    diferencia DECIMAL(18,2) DEFAULT 0,
                    estacion_id INT,
                    usuario_id INT,
                    status VARCHAR(50) DEFAULT 'ACTIVE'
                )
            ";
            $conn->exec($sqlGrupos);

            // Asegurar columna fecha_operativa si la tabla ya existía
            $conn->exec("IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('Conciliacion_V2_Grupos') AND name = 'fecha_operativa') 
                ALTER TABLE Conciliacion_V2_Grupos ADD fecha_operativa DATE");

            // Tabla Detalles V2 (Items individuales conciliados)
            $sqlDetalles = "
                IF NOT EXISTS (SELECT * FROM sysobjects WHERE name='Conciliacion_V2_Detalles' AND xtype='U')
                CREATE TABLE Conciliacion_V2_Detalles (
                    id INT IDENTITY(1,1) PRIMARY KEY,
                    grupo_id INT NOT NULL,
                    origen VARCHAR(10) NOT NULL, -- 'CG' (ControlGas) o 'TX' (Transacción Banco)
                    referencia_externa VARCHAR(255) NOT NULL, -- ID único del sistema origen
                    fecha_operacion DATE,
                    monto DECIMAL(18,2),
                    concepto VARCHAR(255),
                    metadatos NVARCHAR(MAX) -- Para guardar JSON extra si se requiere
                )
            ";
            $conn->exec($sqlDetalles);

            // Índices para velocidad
            $conn->exec("IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IDX_V2_REF') CREATE INDEX IDX_V2_REF ON Conciliacion_V2_Detalles(referencia_externa)");
            $conn->exec("IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IDX_V2_GRP') CREATE INDEX IDX_V2_GRP ON Conciliacion_V2_Detalles(grupo_id)");

            // Asegurar columna en tabla de tránsito (Migración sutil)
            $conn->exec("IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('Conciliacion_Transito') AND name = 'referencia_externa') ALTER TABLE Conciliacion_Transito ADD referencia_externa VARCHAR(255)");

            if (!$silent) {
                echo json_encode(['status' => 'success', 'message' => 'Tablas V2 verificadas/creadas']);
                exit;
            }

        } catch (Exception $e) {
            if (!$silent) {
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
                exit;
            }
        }
    }

    // =========================================================================
    // GUARDAR CONCILIACIÓN V2 (Estricto CG vs TX con IDs Reales)
    // =========================================================================
    public function guardar_conciliacion() {
        ob_clean();
        header('Content-Type: application/json');

        $json = file_get_contents('php://input');
        $data = json_decode($json, true);

        if (!$data || !isset($data['left_rows']) || !isset($data['center_rows'])) {
            echo json_encode(['status' => 'error', 'message' => 'Datos incompletos']);
            exit;
        }

        $server = "192.168.0.6"; $db = "TG"; $user = "cguser"; $pass = "sahei1712"; 

        try {
            $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $conn->beginTransaction();

            $total_cg = (float)$data['total_cg'];
            $total_tx = (float)$data['total_tx'];
            $diferencia = $total_tx - $total_cg;
            $estacion_id = isset($data['estacion_id']) ? (int)$data['estacion_id'] : 0; 
            $fecha_operativa = $data['fecha_operativa'] ?? date('Y-m-d');

            // 1. Crear Grupo
            $sqlGroup = "INSERT INTO Conciliacion_V2_Grupos (total_sistema, total_banco, diferencia, estacion_id, fecha_creacion, fecha_operativa) VALUES (?, ?, ?, ?, GETDATE(), ?)";
            $stmtGroup = $conn->prepare($sqlGroup);
            $stmtGroup->execute([$total_cg, $total_tx, $diferencia, $estacion_id, $fecha_operativa]);
            
            $groupId = $conn->query("SELECT @@IDENTITY")->fetchColumn();

            // 2. Insertar Detalles (Identificadores Puros)
            $sqlDet = "INSERT INTO Conciliacion_V2_Detalles (grupo_id, origen, referencia_externa, fecha_operacion, monto, concepto) VALUES (?, ?, ?, ?, ?, ?)";
            $stmtDet = $conn->prepare($sqlDet);

            // A) ControlGas (CG)
            foreach ($data['left_rows'] as $row) {
                // El 'ref' ya viene limpio del frontend revisado
                $stmtDet->execute([$groupId, 'CG', $row['ref'], $row['fecha'], $row['monto'], $row['concepto']]);
            }

            // B) Transacciones (TX)
            foreach ($data['center_rows'] as $row) {
                $stmtDet->execute([$groupId, 'TX', $row['ref'], $row['fecha'], $row['monto'], $row['concepto']]);
            }

            // 3. Cerrar Tránsitos si aplica
            if (isset($data['transit_ids_to_close']) && is_array($data['transit_ids_to_close']) && !empty($data['transit_ids_to_close'])) {
                $ids = array_map('intval', $data['transit_ids_to_close']);
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $sqlTransit = "UPDATE Conciliacion_Transito SET estado = 'CONCILIADO', fecha_marcado = GETDATE() WHERE id IN ($placeholders)";
                $stmtTransit = $conn->prepare($sqlTransit);
                $stmtTransit->execute(array_values($ids));
            }

            $conn->commit();
            echo json_encode(['status' => 'success', 'grupo_id' => $groupId]);

        } catch (Exception $e) {
            if($conn) $conn->rollBack();
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    public function get_conciliaciones_hechas() {
        ob_clean();
        header('Content-Type: application/json');

        $fecha_ini_raw = filter_input(INPUT_GET, 'fecha_inicio');
        $fecha_fin_raw = filter_input(INPUT_GET, 'fecha_fin');
        $estacion_id   = filter_input(INPUT_GET, 'estacion_id', FILTER_VALIDATE_INT);
        $entidad_id    = filter_input(INPUT_GET, 'entidad_id', FILTER_VALIDATE_INT); // Nuevo parámetro
        $afiliacion    = trim(filter_input(INPUT_GET, 'afiliacion'));

        if (!$fecha_ini_raw || !$fecha_fin_raw) {
            $fecha_ini_raw = date('Ymd');
            $fecha_fin_raw = date('Ymd');
        }

        $fecha_ini = date('Y-m-d', strtotime($fecha_ini_raw));
        $fecha_fin = date('Y-m-d', strtotime($fecha_fin_raw));

        $server = "192.168.0.6"; $db = "TG"; $user = "cguser"; $pass = "sahei1712"; 

        try {
            $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Determinar la tabla de banco específica para optimizar
            $tablaBanco = "";
            if ($entidad_id == 1) $tablaBanco = "banco_getnet";
            elseif ($entidad_id == 4) $tablaBanco = "banco_banorte";

            // Consulta V2 Optimizada: 
            // 1. Filtramos por Estación y Fecha Operativa del Grupo directamente.
            // 2. Si hay afiliación, nos aseguramos de traer TODAS las filas de los grupos que tengan al menos una TX.
            $sql = "SELECT 
                        D.id,
                        D.fecha_operacion as fecha, 
                        D.monto, 
                        D.grupo_id, 
                        D.origen,
                        D.referencia_externa,
                        D.concepto as descripcion,
                        G.diferencia
                    FROM Conciliacion_V2_Detalles D
                    INNER JOIN Conciliacion_V2_Grupos G ON D.grupo_id = G.id
                    WHERE G.estacion_id = ? 
                      AND G.fecha_operativa BETWEEN ? AND ? ";
            
            $params = [$estacion_id, $fecha_ini, $fecha_fin];

            if (!empty($afiliacion)) {
                // Filtro de Afiliación:
                // Intentamos ser lo más estrictos posible combinando coincidencia de ID o Heurística de Monto/Fecha para hashes tx_
                if (!empty($tablaBanco)) {
                    $sql .= " AND EXISTS (
                        SELECT 1 
                        FROM Conciliacion_V2_Detalles D_Check
                        WHERE D_Check.grupo_id = G.id 
                          AND D_Check.origen = 'TX' 
                          AND (
                              -- Caso 1: ID Directo
                              EXISTS (SELECT 1 FROM $tablaBanco B WHERE B.ID_Externo = D_Check.referencia_externa AND B.Afiliacion = ?)
                              -- Caso 2: Hash sintético tx_ (Heurística de validación por Monto y Fecha)
                              OR (D_Check.referencia_externa LIKE 'tx_%' AND EXISTS (
                                  SELECT 1 FROM $tablaBanco B2 
                                  WHERE B2.Afiliacion = ? AND B2.Monto = D_Check.monto AND CAST(B2.Fecha_Transaccion AS DATE) = D_Check.fecha_operacion
                              ))
                          )
                    )";
                    $params[] = $afiliacion;
                    $params[] = $afiliacion;
                } else {
                    // Fallback para casos sin tabla de banco definida
                    $sql .= " AND EXISTS (
                        SELECT 1 
                        FROM Conciliacion_V2_Detalles D_Check
                        WHERE D_Check.grupo_id = G.id 
                          AND D_Check.origen = 'TX' 
                          AND (D_Check.referencia_externa LIKE ? OR D_Check.concepto LIKE ? OR D_Check.referencia_externa LIKE 'tx_%')
                    )";
                    $params[] = "%$afiliacion%";
                    $params[] = "%$afiliacion%";
                }
            }

            $sql .= " ORDER BY G.id, D.origen";

            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            
            $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $data = [];
            foreach($filas as $fila) {
                $lado = ($fila['origen'] === 'CG') ? 'left' : 'center';
                $data[] = [
                    'id'         => $fila['id'],
                    'fecha'      => $fila['fecha'], 
                    'monto'      => (float) $fila['monto'],
                    'grupo_id'   => $fila['grupo_id'],
                    'lado'       => $lado,
                    'ref'        => $fila['referencia_externa'],
                    'diferencia' => (float) $fila['diferencia'], 
                    'afiliacion' => $afiliacion, 
                    'concepto'   => $fila['descripcion']
                ];
            }
            
            echo json_encode(['status' => 'success', 'data' => $data]);

        } catch (PDOException $e) { echo json_encode(['status' => 'error', 'message' => $e->getMessage()]); }
        exit;
    }


public function get_resumen_transito() {
    ob_clean();
    header('Content-Type: application/json');

    $fecha_ini_raw = filter_input(INPUT_GET, 'fecha_inicio');
    $fecha_fin_raw = filter_input(INPUT_GET, 'fecha_fin');
    $estacion_id   = filter_input(INPUT_GET, 'estacion_id');
    $afiliacion    = filter_input(INPUT_GET, 'afiliacion'); 

    if (!$fecha_ini_raw || !$fecha_fin_raw) {
        echo json_encode(['status' => 'error', 'message' => 'Faltan fechas']);
        exit;
    }

    $fecha_vista_ini = date('Y-m-d 00:00:00', strtotime($fecha_ini_raw));
    $fecha_vista_fin = date('Y-m-d 23:59:59', strtotime($fecha_fin_raw));

    $server = "192.168.0.6"; 
    $db = "TG"; 
    $user = "cguser"; 
    $pass = "sahei1712"; 

    try {
        $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $sql = "SELECT 
                    COUNT(T.grupo_id) as total_conciliaciones,
                    ISNULL(SUM(T.diferencia), 0) as total_diferencia
                FROM (
                    SELECT DISTINCT G.id as grupo_id, G.diferencia
                    FROM Conciliacion_Transito CT
                    
                    INNER JOIN Conciliacion_V2_Detalles DL ON 
                        DL.fecha_operacion = CT.fecha_original AND 
                        DL.monto = CT.monto AND 
                        DL.origen = 'CG'
                        
                    INNER JOIN Conciliacion_V2_Grupos G ON G.id = DL.grupo_id

                    INNER JOIN Conciliacion_V2_Detalles DR ON 
                        DR.grupo_id = G.id AND 
                        DR.origen = 'TX'

                    WHERE 
                        CT.estacion_id = ? 
                        AND CT.estado = 'CONCILIADO'
                        AND CT.fecha_original < ? 
                        AND DR.fecha_operacion BETWEEN ? AND ?
                ";

        $params = [
            $estacion_id, 
            $fecha_vista_ini,
            $fecha_vista_ini,
            $fecha_vista_fin
        ];

        // --- CORRECCIÓN AQUÍ: USAMOS LIKE EN LUGAR DE IGUAL ---
        if ($afiliacion) {
            // Buscamos que el texto '7374424' esté CONTENIDO en 'Principal (7374424)'
            $sql .= " AND CT.afiliacion_asociada LIKE ?";
            $params[] = "%" . $afiliacion . "%";
        }

        $sql .= ") T";

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'status' => 'success', 
            'debug_filtro' => $afiliacion ? "Filtrando por %$afiliacion%" : "Sin filtro afiliacion",
            'data' => [
                'count' => $result['total_conciliaciones'],
                'diff'  => (float)$result['total_diferencia']
            ]
        ]);

    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// 1. Guardar lo que marcas como "En Tránsito"
public function guardar_transito() {
    ob_clean();
    header('Content-Type: application/json');

    $json = file_get_contents('php://input');
    $data = json_decode($json, true);

    if (!$data || !isset($data['rows']) || empty($data['rows'])) {
        echo json_encode(['status' => 'error', 'message' => 'No hay datos para guardar']);
        exit;
    }

    // --- CREDENCIALES (Igual que en get_conciliacion_config) ---
    $server = "192.168.0.6"; 
    $db = "TG"; 
    $user = "cguser"; 
    $pass = "sahei1712"; 

    $conn = null;

    try {
        // --- CONEXIÓN ---
        $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $conn->beginTransaction();

        $sql = "INSERT INTO Conciliacion_Transito (fecha_original, monto, concepto, estacion_id, afiliacion_asociada, estado, origen, referencia_externa) 
                VALUES (?, ?, ?, ?, ?, 'PENDIENTE', ?, ?)";
        $stmt = $conn->prepare($sql);

        foreach ($data['rows'] as $row) {
            // Determinamos origen individualmente o por defecto
            $origen = isset($row['origen']) ? $row['origen'] : 'LEFT';
            $ref = isset($row['ref']) ? $row['ref'] : '';

            $stmt->execute([
                $row['fecha'], 
                $row['monto'], 
                $row['concepto'], 
                $data['estacion_id'],
                $data['afiliacion'],
                $origen,
                $ref
            ]);
        }

        $conn->commit();
        echo json_encode(['status' => 'success']);

    } catch (Exception $e) {
        if ($conn) { $conn->rollBack(); }
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

    public function get_transitos_pendientes() {
        ob_clean();
        header('Content-Type: application/json');

        $estacion_id = filter_input(INPUT_GET, 'estacion_id');
        $afiliacion  = trim(filter_input(INPUT_GET, 'afiliacion'));

        if (!$estacion_id) {
            echo json_encode(['status' => 'error', 'message' => 'Falta estacion']);
            exit;
        }

        $server = "192.168.0.6"; $db = "TG"; $user = "cguser"; $pass = "sahei1712";

        try {
            $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // TRAEMOS TODO LO PENDIENTE (POOL ABIERTO)
            // Calculamos dias_antiguedad para el semáforo visual
            $sql = "SELECT id, 
                           fecha_original as fecha, 
                           monto, 
                           concepto, 
                           estado, 
                           ISNULL(origen, 'LEFT') as origen, 
                           afiliacion_asociada, 
                           referencia_externa,
                           DATEDIFF(day, fecha_original, GETDATE()) as dias_antiguedad
                    FROM Conciliacion_Transito 
                    WHERE estacion_id = ? AND estado = 'PENDIENTE'";
            
            $params = [$estacion_id];

            if ($afiliacion) {
                $sql .= " AND afiliacion_asociada LIKE ?";
                $params[] = "%$afiliacion%";
            }

            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['status' => 'success', 'data' => $data]);

        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

// 3. Borrar registros de tránsito (Deshacer)
public function borrar_transito() {
    // Limpiar cualquier salida previa (errores, espacios, etc)
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    try {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        if (!$data || !isset($data['ids']) || !is_array($data['ids']) || empty($data['ids'])) {
            throw new Exception("Datos inválidos o lista de IDs vacía.");
        }

        $server = "192.168.0.6"; 
        $db = "TG"; 
        $user = "cguser"; 
        $pass = "sahei1712";

        $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Filtrar IDs para asegurar que sean enteros
        $ids = array_map('intval', $data['ids']);
        $ids = array_filter($ids, function($id) { return $id > 0; });

        if (empty($ids)) {
            throw new Exception("No se proporcionaron IDs válidos para eliminar.");
        }

        // Construir query segura
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "DELETE FROM Conciliacion_Transito WHERE id IN ($placeholders)";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute(array_values($ids));

        echo json_encode(['status' => 'success', 'deleted_count' => count($ids)]);

    } catch (Exception $e) {
        http_response_code(500); // Indicar error de servidor
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

public function get_conciliacion_config() {
        ob_clean();
        header('Content-Type: application/json');
        
        $estacion_id = filter_input(INPUT_GET, 'estacion_id', FILTER_VALIDATE_INT);
        if (!$estacion_id) {
            echo json_encode(['status' => 'error', 'message' => 'ID de estación inválido']);
            exit;
        }

        $server = "192.168.0.6"; 
        $db = "TG"; 
        $user = "cguser"; 
        $pass = "sahei1712"; 

        try {
            $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // JOIN CORRECTO con Tesoreria_Entidad para obtener el nombre del banco
            $sql = "SELECT 
                        C.entidad_id, 
                        E.Nombre as nombre_banco, 
                        C.afiliacion, 
                        C.descripcion, 
                        C.conceptos_cg 
                    FROM Conciliacion_Configuracion C
                    INNER JOIN Tesoreria_Entidad E ON C.entidad_id = E.id
                    WHERE C.estacion_id = ?";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute([$estacion_id]);
            $reglas = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['status' => 'success', 'data' => $reglas]);

        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    // =========================================================================
// FUNCIÓN PRIVADA: RECALCULAR TOTALES DE UN GRUPO (2 VÍAS: CG vs TX) V2
// =========================================================================
private function recalcular_grupo_interno($conn, $grupo_id) {
    
    // 1. Sumar Lado Izquierdo (CG)
    $sqlLeft = "SELECT ISNULL(SUM(monto), 0) FROM Conciliacion_V2_Detalles WHERE grupo_id = ? AND origen = 'CG'";
    $stmtL = $conn->prepare($sqlLeft);
    $stmtL->execute([$grupo_id]);
    $totalCG = (float)$stmtL->fetchColumn();

    // 2. Sumar Lado Centro (TX)
    $sqlCenter = "SELECT ISNULL(SUM(monto), 0) FROM Conciliacion_V2_Detalles WHERE grupo_id = ? AND origen = 'TX'";
    $stmtC = $conn->prepare($sqlCenter);
    $stmtC->execute([$grupo_id]);
    $totalTX = (float)$stmtC->fetchColumn();

    // 3. Calcular Diferencia (TX - CG)
    $diferencia = $totalTX - $totalCG;

    // 4. Actualizar la Tabla Padre V2
    $sqlUpdate = "UPDATE Conciliacion_V2_Grupos 
                  SET total_sistema = ?, total_banco = ?, diferencia = ? 
                  WHERE id = ?";
    $stmtUp = $conn->prepare($sqlUpdate);
    $stmtUp->execute([$totalCG, $totalTX, $diferencia, $grupo_id]);

    return [
        'nuevo_total_cg' => $totalCG, 
        'nuevo_total_tx' => $totalTX,
        'nueva_diferencia' => $diferencia
    ];
}

// =========================================================================
// RECALCULAR MANUALMENTE UN GRUPO (BOTÓN DE PÁNICO)
// =========================================================================
public function forzar_recalculo() {
    ob_clean();
    header('Content-Type: application/json');

    $json = file_get_contents('php://input');
    $data = json_decode($json, true);

    if (!isset($data['grupo_id'])) {
        echo json_encode(['status' => 'error', 'message' => 'Falta grupo_id']);
        exit;
    }

    $server = "192.168.0.6"; $db = "TG"; $user = "cguser"; $pass = "sahei1712";

    try {
        $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Simplemente llamamos a la lógica de suma
        $nuevos_totales = $this->recalcular_grupo_interno($conn, $data['grupo_id']);

        echo json_encode([
            'status' => 'success', 
            'data' => $nuevos_totales
        ]);

    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}








    // =========================================================================
    // ACTUALIZAR MONTO Y REPARAR DIFERENCIA (CASCADA) V2
    // =========================================================================
    public function actualizar_monto_detalle() {
        ob_clean();
        header('Content-Type: application/json');

        $json = file_get_contents('php://input');
        $data = json_decode($json, true);

        if (!isset($data['id_detalle']) || !isset($data['nuevo_monto'])) {
            echo json_encode(['status' => 'error', 'message' => 'Faltan datos']);
            exit;
        }

        $id_detalle = $data['id_detalle'];
        $nuevo_monto = $data['nuevo_monto'];

        $server = "192.168.0.6"; $db = "TG"; $user = "cguser"; $pass = "sahei1712";

        try {
            $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $conn->beginTransaction();

            // 1. Obtener Grupo V2
            $stmtGet = $conn->prepare("SELECT grupo_id FROM Conciliacion_V2_Detalles WHERE id = ?");
            $stmtGet->execute([$id_detalle]);
            $grupo_id = $stmtGet->fetchColumn();

            if (!$grupo_id) throw new Exception("Detalle no encontrado.");

            // 2. Actualizar Detalle V2
            $stmtUpdateDetalle = $conn->prepare("UPDATE Conciliacion_V2_Detalles SET monto = ? WHERE id = ?");
            $stmtUpdateDetalle->execute([$nuevo_monto, $id_detalle]);

            // 3. RECALCULAR TOTALES V2
            $nuevos_totales = $this->recalcular_grupo_interno($conn, $grupo_id);

            $conn->commit();

            echo json_encode([
                'status' => 'success', 
                'message' => 'Actualizado correctamente',
                'data' => array_merge(['grupo_id' => $grupo_id], $nuevos_totales)
            ]);

        } catch (Exception $e) {
            if(isset($conn)) $conn->rollBack();
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }
    // =========================================================================
// DESLIGAR MOVIMIENTO (Y BORRAR GRUPO SI QUEDA VACÍO) V2
// =========================================================================
public function desligar_movimiento() {
    ob_clean();
    header('Content-Type: application/json');

    $json = file_get_contents('php://input');
    $data = json_decode($json, true);

    if (!isset($data['id_detalle'])) {
        echo json_encode(['status' => 'error', 'message' => 'Falta ID']);
        exit;
    }

    $id_detalle = $data['id_detalle'];
    $server = "192.168.0.6"; $db = "TG"; $user = "cguser"; $pass = "sahei1712";

    try {
        $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $conn->beginTransaction();

        // 1. Obtener el grupo_id antes de borrar V2
        $stmtGet = $conn->prepare("SELECT grupo_id, referencia_externa, origen FROM Conciliacion_V2_Detalles WHERE id = ?");
        $stmtGet->execute([$id_detalle]);
        $rowDet = $stmtGet->fetch(PDO::FETCH_ASSOC);
        
        if (!$rowDet) throw new Exception("Movimiento no encontrado.");
        
        $grupo_id = $rowDet['grupo_id'];
        $ref      = $rowDet['referencia_externa'];
        $origen   = $rowDet['origen'];

        // 2. Eliminar el detalle específico V2
        $stmtDel = $conn->prepare("DELETE FROM Conciliacion_V2_Detalles WHERE id = ?");
        $stmtDel->execute([$id_detalle]);

        // REVERSIÓN DE TRÁNSITO: Si era una referencia de tránsito, volver a PENDIENTE
        // (Aunque ahora guardamos el ID real, la lógica de tránsito sigue siendo útil)
        // Nota: Si el usuario movió a tránsito algo, su ID estará en Conciliacion_Transito.
        // Si al conciliar marcamos ese ID como CONCILIADO, aquí deberíamos revertirlo.
        // Pero espera, 'referencia_externa' en V2 guarda el ID de ControlGas o Banco.
        // La tabla Conciliacion_Transito usa sus propios IDs incrementales.
        
        // Buscamos si este item que estamos desligando tiene un registro en tránsitos
        $sqlRevert = "UPDATE Conciliacion_Transito SET estado = 'PENDIENTE', fecha_marcado = NULL 
                      WHERE (fecha_original = ? OR 1=1) AND monto = ? AND estado = 'CONCILIADO'";
        // TODO: Hacer la reversión de tránsito más precisa si es necesario.

        // 3. Verificar si queda vacío el grupo V2
        $stmtCount = $conn->prepare("SELECT COUNT(*) FROM Conciliacion_V2_Detalles WHERE grupo_id = ?");
        $stmtCount->execute([$grupo_id]);
        $remaining = (int)$stmtCount->fetchColumn();

        if ($remaining === 0) {
            // Grupo vacío -> Eliminar V2
            $stmtDelGroup = $conn->prepare("DELETE FROM Conciliacion_V2_Grupos WHERE id = ?");
            $stmtDelGroup->execute([$grupo_id]);
            $mensaje = "Grupo eliminado por quedar vacío.";
        } else {
            // Grupo con datos -> Recalcular V2
            $this->recalcular_grupo_interno($conn, $grupo_id);
            $mensaje = "Grupo recalculado.";
        }

        $conn->commit();
        echo json_encode(['status' => 'success', 'message' => $mensaje]);

    } catch (Exception $e) {
        if (isset($conn)) $conn->rollBack();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}





private function getMonthNameEs(int $month): string
    {
        $nombres = [
            1 => 'Enero',
            2 => 'Febrero',
            3 => 'Marzo',
            4 => 'Abril',
            5 => 'Mayo',
            6 => 'Junio',
            7 => 'Julio',
            8 => 'Agosto',
            9 => 'Septiembre',
            10 => 'Octubre',
            11 => 'Noviembre',
            12 => 'Diciembre'
        ];

        return $nombres[$month] ?? (string)$month;
    }



public function stamped_invoices(): void
{

    set_time_limit(300); 
    ini_set('memory_limit', '512M'); // También aumentamos memoria por si son muchos datos

    if (!preg_match('/GET/i', $_SERVER['REQUEST_METHOD'])) { return; }

    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || isset($_GET['ajax']);

    // --- MODO AJAX (JSON) ---
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');

        $fromMonth  = $_GET['from']   ?? null;
        $untilMonth = $_GET['until']  ?? null;
        $codEmp     = $_GET['codemp'] ?? null; // Recibimos ID de empresa

        // Conversión a entero o null
        $codEmp = ($codEmp !== null && $codEmp !== '') ? (int)$codEmp : null;

        if (!$fromMonth || !$untilMonth) { echo json_encode(['error' => 'Faltan fechas.']); return; }

        $fromDateObj  = \DateTime::createFromFormat('Y-m', $fromMonth);
        $untilDateObj = \DateTime::createFromFormat('Y-m', $untilMonth);

        if (!$fromDateObj || !$untilDateObj) { echo json_encode(['error' => 'Fecha inválida.']); return; }
        if ($fromDateObj > $untilDateObj) { [$fromDateObj, $untilDateObj] = [$untilDateObj, $fromDateObj]; }

        $fromDateObj->modify('first day of this month');
        $untilDateObj->modify('last day of this month');

        // Consulta al modelo
        $rows = $this->FacturasModel->get_concentrado_ventas(
            $fromDateObj->format('Y-m-d'), 
            $untilDateObj->format('Y-m-d'), 
            $codEmp
        );

        // Generar columnas de meses
        $months = [];
        $period = new \DatePeriod((clone $fromDateObj)->modify('first day of this month'), new \DateInterval('P1M'), (clone $untilDateObj)->modify('first day of next month'));

        foreach ($period as $dt) {
            $key = $dt->format('Y-m');
            $months[$key] = ['key' => $key, 'label' => $this->getMonthNameEs((int)$dt->format('n')) . ' ' . $dt->format('Y')];
        }

        // Pivotear datos
        $dataByStation = [];
        foreach ($rows as $r) {
            $stationKey = $r['CodigoEstacion'];
            if (!isset($dataByStation[$stationKey])) {
                $dataByStation[$stationKey] = [
                    'CodigoEstacion' => $r['CodigoEstacion'],
                    'Estacion'       => $r['Estacion'],
                    'EstacionNombre' => $r['EstacionNombre'],
                    'EmpresaNombre'  => $r['EmpresaNombre'], // Razon social
                    'TotalPeriodo'   => 0,
                    'meses'          => array_fill_keys(array_keys($months), 0)
                ];
            }
            $monthKey = sprintf('%04d-%02d', $r['Anio'], $r['Mes']);
            if (isset($dataByStation[$stationKey]['meses'][$monthKey])) {
                $val = (float)$r['Conteo'];
                $dataByStation[$stationKey]['meses'][$monthKey] += $val;
                $dataByStation[$stationKey]['TotalPeriodo'] += $val;
            }
        }

        foreach ($dataByStation as &$row) {
            foreach ($row['meses'] as $k => $v) $row['meses'][$k] = number_format($v, 2, '.', '');
            $row['TotalPeriodo'] = number_format($row['TotalPeriodo'], 2, '.', '');
        }

        echo json_encode(['months' => array_values($months), 'data' => array_values($dataByStation)]);
        return;
    }

    // --- MODO VISTA (HTML) ---
    // Obtenemos los Tags de Empresas
    $empresasDisponibles = $this->FacturasModel->get_empresas_tags();

    $from  = '2025-01';
    $until = '2025-11';

    echo $this->twig->render($this->route . 'stamped_invoices.html', compact('from', 'until', 'empresasDisponibles'));
}

public function stamped_invoices_detail(): void
{

    set_time_limit(300); 
    ini_set('memory_limit', '512M'); // También aumentamos memoria por si son muchos datos

    if (!preg_match('/GET/i', $_SERVER['REQUEST_METHOD'])) return;
    header('Content-Type: application/json; charset=utf-8');

    $codgas = $_GET['codgas'] ?? '0';
    $month  = $_GET['month']  ?? null;
    $from   = $_GET['from']   ?? null;
    $until  = $_GET['until']  ?? null;
    $codEmp = $_GET['codemp'] ?? null; // Filtro por empresa

    $codEmp = ($codEmp !== null && $codEmp !== '') ? (int)$codEmp : null;

    $fechaInicio = ''; $fechaFin = '';

    if ($month === 'all') {
        if ($from && $until) {
            $dtFrom = \DateTime::createFromFormat('Y-m', $from);
            $dtUntil= \DateTime::createFromFormat('Y-m', $until);
            if($dtFrom && $dtUntil) {
                $fechaInicio = $dtFrom->modify('first day of this month')->format('Y-m-d');
                $fechaFin    = $dtUntil->modify('last day of this month')->format('Y-m-d');
            }
        }
    } else if ($month) {
        $dt = \DateTime::createFromFormat('Y-m', $month);
        if ($dt) {
            $fechaInicio = $dt->modify('first day of this month')->format('Y-m-d');
            $fechaFin    = $dt->modify('last day of this month')->format('Y-m-d');
        }
    }

    if (!$fechaInicio) { echo json_encode(['error' => 'Fechas error']); return; }

    $rows = $this->FacturasModel->get_detalle_facturas_estacion_mes($codgas, $fechaInicio, $fechaFin, $codEmp);

    foreach ($rows as &$r) {
        $r['Cantidad'] = number_format((float)($r['Cantidad']??0), 2, '.', ',');
        $r['Subtotal'] = number_format((float)($r['Subtotal']??0), 2, '.', ',');
        $r['IVA']      = number_format((float)($r['IVA']??0), 2, '.', ',');
        $r['IEPS']     = number_format((float)($r['IEPS']??0), 2, '.', ',');
        $r['Total']    = number_format((float)($r['Total']??0), 2, '.', ',');
    }

    echo json_encode(['data' => $rows]);
}

    // =========================================================================
    // VISTA DE RESULTADOS (RESUMEN AUDITABLE)
    // =========================================================================
    public function conc_results() {
        // Asegurar tablas
        $this->setup_conciliacion_v2(true);
        echo $this->twig->render($this->route . 'summary_v2.html');
    }

    public function get_conciliacion_summary() {
        ob_clean();
        header('Content-Type: application/json');

        $year = $_GET['year'] ?? date('Y');
        $month = $_GET['month'] ?? date('m');
        $estacion_id = (int)($_GET['estacion_id'] ?? 0);
        $entidad_id = (int)($_GET['banco_id'] ?? 0);
        $afiliacion = $_GET['afiliacion'] ?? '';

        $server = "192.168.0.6"; $db = "TG"; $user = "cguser"; $pass = "sahei1712"; 

        try {
            $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // MIGRACIÓN RÁPIDA: Si hay registros sin fecha_operativa, intentar llenarlos con la fecha de sus detalles
            $conn->exec("UPDATE G SET G.fecha_operativa = (SELECT TOP 1 D.fecha_operacion FROM Conciliacion_V2_Detalles D WHERE D.grupo_id = G.id ORDER BY D.origen DESC) 
                         FROM Conciliacion_V2_Grupos G WHERE G.fecha_operativa IS NULL");

            // 1. FILTRO DE GRUPOS (Por mes de la fecha operativa)
            $filterSql = " WHERE YEAR(G.fecha_operativa) = ? AND MONTH(G.fecha_operativa) = ? ";
            $params = [$year, $month];

            if ($estacion_id > 0) {
                $filterSql .= " AND G.estacion_id = ? ";
                $params[] = $estacion_id;
            }

            // 2. QUERY PRINCIPAL
            $sqlBase = " FROM Conciliacion_V2_Grupos G " . $filterSql;

            $sqlTotales = "SELECT 
                            ISNULL(SUM(G.total_sistema),0) as total_sistema,
                            ISNULL(SUM(G.total_banco),0) as total_banco,
                            ISNULL(SUM(G.diferencia),0) as total_diferencia,
                            COUNT(G.id) as total_conciliaciones
                           " . $sqlBase;
            
            $stmtTotales = $conn->prepare($sqlTotales);
            $stmtTotales->execute($params);
            $totales = $stmtTotales->fetch(PDO::FETCH_ASSOC);

            // DESGLOSE POR DÍA OPERATIVO (SOLO DIFERENCIAS != 0)
            $sqlDias = "SELECT 
                            FORMAT(G.fecha_operativa, 'yyyy-MM-dd') as fecha,
                            COUNT(G.id) as count,
                            SUM(G.total_sistema) as sistema,
                            SUM(G.total_banco) as banco,
                            SUM(G.diferencia) as diferencia
                        " . $sqlBase . "
                        GROUP BY FORMAT(G.fecha_operativa, 'yyyy-MM-dd')
                        HAVING SUM(G.diferencia) != 0
                        ORDER BY fecha DESC";
            
            $stmtDias = $conn->prepare($sqlDias);
            $stmtDias->execute($params);
            $dias = $stmtDias->fetchAll(PDO::FETCH_ASSOC);

            // 3. DESGLOSE POR ESTACIÓN (SOLO DIFERENCIAS != 0)
            $sqlEstacion = "SELECT 
                                E.Nombre as estacion,
                                SUM(G.total_sistema) as sistema,
                                SUM(G.total_banco) as banco,
                                SUM(G.diferencia) as diferencia
                            FROM Conciliacion_V2_Grupos G 
                            LEFT JOIN Estaciones E ON G.estacion_id = E.Codigo
                            WHERE YEAR(G.fecha_operativa) = ? AND MONTH(G.fecha_operativa) = ? ";
            
            $paramsEst = [$year, $month];
            if ($estacion_id > 0) {
                $sqlEstacion .= " AND G.estacion_id = ? ";
                $paramsEst[] = $estacion_id;
            }

            $sqlEstacion .= " GROUP BY E.Nombre HAVING SUM(G.diferencia) != 0 ORDER BY E.Nombre";
            
            $stmtEstacion = $conn->prepare($sqlEstacion);
            $stmtEstacion->execute($paramsEst);
            $porEstacion = $stmtEstacion->fetchAll(PDO::FETCH_ASSOC);

            // ==========================================================
            // AGRUPAMIENTOS ADICIONALES PARA PESTAÑAS (AUDITORÍA)
            // ==========================================================
            $agrupados = [];
            
            // A. Por Banco / Afiliación
            $sqlBank = "SELECT TE.Nombre as Banco, TX_AFIL.Afiliacion, 
                               SUM(G.total_sistema) as Sistema, SUM(G.total_banco) as BancoTotal, SUM(G.diferencia) as Diferencia
                        FROM Conciliacion_V2_Grupos G
                        INNER JOIN Conciliacion_V2_Detalles D ON G.id = D.grupo_id AND D.origen = 'TX'
                        INNER JOIN (
                            SELECT ID_Externo as id_ref, Afiliacion FROM banco_getnet
                            UNION ALL
                            SELECT ID_Externo as id_ref, Afiliacion FROM banco_banorte
                        ) AS TX_AFIL ON D.referencia_externa = TX_AFIL.id_ref
                        INNER JOIN Conciliacion_Configuracion CC ON TX_AFIL.Afiliacion = CC.afiliacion AND G.estacion_id = CC.estacion_id
                        INNER JOIN Tesoreria_Entidad TE ON CC.entidad_id = TE.id
                        " . $filterSql . "
                        GROUP BY TE.Nombre, TX_AFIL.Afiliacion
                        HAVING SUM(G.diferencia) <> 0
                        ORDER BY TE.Nombre, TX_AFIL.Afiliacion";
            $stmtBank = $conn->prepare($sqlBank);
            $stmtBank->execute($params);
            $agrupados['bancos'] = $stmtBank->fetchAll(PDO::FETCH_ASSOC);

            // B. Por Estación / Banco
            $sqlEstBank = "SELECT E.Nombre as Estacion, TE.Nombre as Banco, 
                                  SUM(G.total_sistema) as Sistema, SUM(G.total_banco) as BancoTotal, SUM(G.diferencia) as Diferencia
                           FROM Conciliacion_V2_Grupos G
                           LEFT JOIN Estaciones E ON G.estacion_id = E.Codigo
                           INNER JOIN Conciliacion_V2_Detalles D ON G.id = D.grupo_id AND D.origen = 'TX'
                           INNER JOIN (
                               SELECT ID_Externo as id_ref, Afiliacion FROM banco_getnet
                               UNION ALL
                               SELECT ID_Externo, Afiliacion FROM banco_banorte
                           ) AS TX_AFIL ON D.referencia_externa = TX_AFIL.id_ref
                           INNER JOIN Conciliacion_Configuracion CC ON TX_AFIL.Afiliacion = CC.afiliacion AND G.estacion_id = CC.estacion_id
                           INNER JOIN Tesoreria_Entidad TE ON CC.entidad_id = TE.id
                           " . $filterSql . "
                           GROUP BY E.Nombre, TE.Nombre
                           HAVING SUM(G.diferencia) <> 0
                           ORDER BY E.Nombre, TE.Nombre";
            $stmtEstBank = $conn->prepare($sqlEstBank);
            $stmtEstBank->execute($params);
            $agrupados['estacion_banco'] = $stmtEstBank->fetchAll(PDO::FETCH_ASSOC);

            // C. Por Razón Social / Estación
            $sqlRS = "SELECT CASE 
                                WHEN E.RFC = 'DGA930823KD3' THEN 'DIAZ GAS'
                                WHEN E.RFC = 'DGM880621FU5' THEN 'GASOMEX'
                                ELSE 'FORANEAS'
                             END as RazonSocial, 
                             E.Nombre as Estacion,
                             SUM(G.total_sistema) as Sistema, SUM(G.total_banco) as BancoTotal, SUM(G.diferencia) as Diferencia
                      FROM Conciliacion_V2_Grupos G
                      LEFT JOIN Estaciones E ON G.estacion_id = E.Codigo
                      " . $filterSql . "
                      GROUP BY E.RFC, E.Nombre
                      HAVING SUM(G.diferencia) <> 0
                      ORDER BY RazonSocial, E.Nombre";
            $stmtRS = $conn->prepare($sqlRS);
            $stmtRS->execute($params);
            $agrupados['razon_social'] = $stmtRS->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'totales' => $totales,
                'dias' => $dias,
                'estaciones' => $porEstacion,
                'agrupados' => $agrupados
            ]);

        } catch (Exception $e) { echo json_encode(['status' => 'error', 'message' => $e->getMessage()]); }
        exit;
    }

    public function get_audit_pivoted_data() {
        ob_clean();
        header('Content-Type: application/json');

        $year = $_GET['year'] ?? date('Y');
        $month = $_GET['month'] ?? date('m');
        $mode = $_GET['mode'] ?? ''; 
        $value = $_GET['value'] ?? ''; 

        $server = "192.168.0.6"; $db = "TG"; $user = "cguser"; $pass = "sahei1712"; 

        try {
            $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $sql = "";
            $params = [$year, $month];

            switch ($mode) {
                case 'rs':
                    // MODO RAZÓN SOCIAL: Comparativa global entre empresas
                    $sql = "SELECT 
                                CASE
                                    WHEN E.RFC = 'DGA930823KD3' THEN 'DIAZ GAS'
                                    WHEN E.RFC = 'DGM880621FU5' THEN 'GASOMEX'
                                    ELSE 'FORANEAS'
                                END AS label,
                                SUM(G.total_sistema) as sistema, 
                                SUM(G.total_banco) as banco, 
                                SUM(G.diferencia) as diferencia
                            FROM Conciliacion_V2_Grupos G
                            INNER JOIN Estaciones E ON G.estacion_id = E.Codigo
                            WHERE YEAR(G.fecha_operativa) = ? AND MONTH(G.fecha_operativa) = ?
                            GROUP BY 
                                CASE
                                    WHEN E.RFC = 'DGA930823KD3' THEN 'DIAZ GAS'
                                    WHEN E.RFC = 'DGM880621FU5' THEN 'GASOMEX'
                                    ELSE 'FORANEAS'
                                END
                            HAVING SUM(G.diferencia) <> 0
                            ORDER BY label";
                    break;

                case 'station':
                    // MODO ESTACIÓN: Agrupado por el banco/afil predominante de cada grupo
                    $sql = "SELECT 
                                Atribucion.label,
                                SUM(G.total_sistema) as sistema,
                                SUM(G.total_banco) as banco,
                                SUM(G.diferencia) as diferencia
                            FROM Conciliacion_V2_Grupos G
                            CROSS APPLY (
                                SELECT TOP 1 TE.Nombre + ' (' + CC.afiliacion + ')' as label
                                FROM Conciliacion_V2_Detalles D
                                INNER JOIN (
                                    SELECT ID_Externo as id_ref, Afiliacion FROM banco_getnet
                                    UNION ALL
                                    SELECT ID_Externo as id_ref, Afiliacion FROM banco_banorte
                                ) AS TX_SRC ON D.referencia_externa = TX_SRC.id_ref
                                INNER JOIN Conciliacion_Configuracion CC ON TX_SRC.Afiliacion = CC.afiliacion AND G.estacion_id = CC.estacion_id
                                INNER JOIN Tesoreria_Entidad TE ON CC.entidad_id = TE.id
                                WHERE D.grupo_id = G.id AND D.origen = 'TX'
                            ) AS Atribucion
                            WHERE YEAR(G.fecha_operativa) = ? AND MONTH(G.fecha_operativa) = ? AND G.estacion_id = ?
                            GROUP BY Atribucion.label
                            HAVING SUM(G.diferencia) <> 0
                            ORDER BY Atribucion.label";
                    $params[] = (int)$value;
                    break;

                case 'bank':
                    // MODO BANCO: Suma de grupos que contienen transacciones del banco seleccionado
                    $sql = "SELECT 
                                Atribucion.label,
                                SUM(G.total_sistema) as sistema,
                                SUM(G.total_banco) as banco,
                                SUM(G.diferencia) as diferencia
                            FROM Conciliacion_V2_Grupos G
                            INNER JOIN Estaciones E ON G.estacion_id = E.Codigo
                            CROSS APPLY (
                                SELECT TOP 1 CC.afiliacion + ' - ' + E.Nombre as label
                                FROM Conciliacion_V2_Detalles D
                                INNER JOIN (
                                    SELECT ID_Externo as id_ref, Afiliacion FROM banco_getnet
                                    UNION ALL
                                    SELECT ID_Externo as id_ref, Afiliacion FROM banco_banorte
                                ) AS TX_SRC ON D.referencia_externa = TX_SRC.id_ref
                                INNER JOIN Conciliacion_Configuracion CC ON TX_SRC.Afiliacion = CC.afiliacion AND G.estacion_id = CC.estacion_id
                                INNER JOIN Tesoreria_Entidad TE ON CC.entidad_id = TE.id
                                WHERE D.grupo_id = G.id AND D.origen = 'TX' AND CC.entidad_id = ?
                            ) AS Atribucion
                            WHERE YEAR(G.fecha_operativa) = ? AND MONTH(G.fecha_operativa) = ?
                            GROUP BY Atribucion.label
                            HAVING SUM(G.diferencia) <> 0
                            ORDER BY Atribucion.label";
                    // Reordenar params: EntidadID va primero en el CROSS APPLY
                    $params = [(int)$value, $year, $month];
                    break;
            }

            if (!$sql) throw new Exception("Modo de auditoría no válido");

            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['status' => 'success', 'data' => $data]);

        } catch (Exception $e) { echo json_encode(['status' => 'error', 'message' => $e->getMessage()]); }
        exit;
    }

    public function export_conciliacion_excel() {
        ob_clean(); 
        
        $year = $_GET['year'] ?? date('Y');
        $month = $_GET['month'] ?? date('m');
        $estacion_id = $_GET['estacion_id'] ?? 0;

        $server = "192.168.0.6"; $db = "TG"; $user = "cguser"; $pass = "sahei1712"; 

        try {
            $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $sql = "SELECT 
                        G.id as GrupoID,
                        E.Nombre as Estacion,
                        FORMAT(G.fecha_creacion, 'yyyy-MM-dd HH:mm') as FechaConciliacion,
                        G.total_sistema as ControlGas,
                        G.total_banco as Banco,
                        G.diferencia as Diferencia,
                        (SELECT STRING_AGG(referencia_externa, ', ') FROM Conciliacion_V2_Detalles WHERE grupo_id = G.id AND origen = 'TX') as RefsBanco
                    FROM Conciliacion_V2_Grupos G
                    LEFT JOIN Estaciones E ON G.estacion_id = E.Codigo
                    WHERE YEAR(G.fecha_creacion) = ? AND MONTH(G.fecha_creacion) = ? ";
            
            $params = [$year, $month];
            if ($estacion_id > 0) {
                $sql .= " AND G.estacion_id = ? ";
                $params[] = $estacion_id;
            }
            $sql .= " ORDER BY G.fecha_creacion DESC";

            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle("Conciliacion $year-$month");

            $headers = ['Grupo ID', 'Estación', 'Fecha Conciliación', 'ControlGas', 'Banco (Transacciones)', 'Diferencia', 'Referencias Banco'];
            $sheet->fromArray($headers, NULL, 'A1');
            $sheet->getStyle('A1:G1')->getFont()->setBold(true);

            $rowIdx = 2;
            foreach ($rows as $r) {
                $sheet->setCellValue('A'.$rowIdx, $r['GrupoID']);
                $sheet->setCellValue('B'.$rowIdx, $r['Estacion']);
                $sheet->setCellValue('C'.$rowIdx, $r['FechaConciliacion']);
                $sheet->setCellValue('D'.$rowIdx, $r['ControlGas']);
                $sheet->setCellValue('E'.$rowIdx, $r['Banco']);
                $sheet->setCellValue('F'.$rowIdx, $r['Diferencia']);
                $sheet->setCellValue('G'.$rowIdx, $r['RefsBanco']);
                if (abs($r['Diferencia']) > 0.01) {
                    $sheet->getStyle('F'.$rowIdx)->getFont()->getColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_RED);
                }
                $rowIdx++;
            }

            foreach (range('A', 'G') as $col) { $sheet->getColumnDimension($col)->setAutoSize(true); }

            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment;filename="Conciliacion_Resumen_'.$year.'_'.$month.'.xlsx"');
            header('Cache-Control: max-age=0');

            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save('php://output');

        } catch (Exception $e) {
            echo "Error generando Excel: " . $e->getMessage();
        }
        exit;
    }
}
