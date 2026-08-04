<?php
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use OpenSpout\Common\Entity\Row as SpoutRow;
use OpenSpout\Common\Entity\Style\Style as SpoutStyle;
use OpenSpout\Writer\XLSX\Options as SpoutXlsxOptions;
use OpenSpout\Writer\XLSX\Writer as SpoutXlsxWriter;

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
            // $gasolineras    = $this->clientesModel->get_gasolineras();
            $clientes_debit = $this->clientesModel->get_debit_clients_list();
            $clientes_credit = $this->clientesModel->get_credit_clients_list();
            echo $this->twig->render($this->route . 'clients.html', compact('clientes_debit','clientes_credit'));
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

    // NUEVO: mensaje opcional del formulario
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
        $ok = @send_mail_with_fallback($subject, $body, $to, $from, $tmp);
        $mailerOut = trim(ob_get_clean());

        @unlink($tmp);
    } else {
        ob_start();
        $ok = @send_mail_with_fallback($subject, $body, $to, $from);
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
                    'dom'    => $client['dom'],
                    'rfc'    => $client['rfc'],
                    'debsdo' => $client['debsdo'],
                );
            }
        }
        json_output(array("data" => $data));
    }

    public function clients_credit_table() : void {
        $data = [];
        if ($clients = $this->clientesModel->get_clients_credit($_POST['status'] ?? 0)) {
            foreach ($clients as $client) {
                $data[] = [
                    'cod'     => $client['cod'],
                    'den'     => $client['den'],
                    'status'  => $client['status'],
                    'dom'     => $client['dom'],
                    'rfc'     => $client['rfc'],
                    'cresdo'  => $client['cresdo'],
                    'mtoasg'  => $client['mtoasg'],
                    'cndpag'  => $client['cndpag'],
                ];
            }
        }
        json_output(["data" => $data]);
    }

    public function clients_list() : void {
        $rows = $this->clientesModel->search_credit_and_debits_clients();
        json_output($rows ?: []);
    }

    public function clients_dispatches_table() : void {
        $from   = $this->createDateTime($_POST['from']  ?? date('Y-m-d', strtotime('-30 days')));
        $until  = $this->createDateTime($_POST['until'] ?? date('Y-m-d'));
        $tipval = (int)($_POST['tipval']  ?? 4);
        $codcli = (int)($_POST['codcli']  ?? 0);

        $data = [];
        if ($rows = $this->clientesModel->get_dispatches_by_client(
            dateToInt($from->format('Y-m-d')),
            dateToInt($until->format('Y-m-d')),
            $tipval,
            $codcli
        )) {
            foreach ($rows as $r) {
                $data[] = [
                    'Cliente'  => $r['Cliente'],
                    'codcli'   => $r['codcli'],
                    'Fecha'    => $r['Fecha'],
                    'hratrn'   => $r['hratrn'],
                    'nrotrn'   => $r['nrotrn'],
                    'Litros'   => $r['Litros'],
                    'Monto'    => $r['Monto'],
                    'Estacion' => $r['Estacion'],
                    'producto' => $r['producto'],
                ];
            }
        }
        json_output(["data" => $data]);
    }

    public function client_anticipos_table() : void {
        $codcli = (int)($_POST['codcli'] ?? 0);
        $from   = $this->createDateTime($_POST['from']  ?? date('Y-01-01'));
        $until  = $this->createDateTime($_POST['until'] ?? date('Y-m-d'));

        if ($codcli <= 0) {
            json_output(['data' => []]);
            return;
        }

        $rows = $this->clientesModel->get_client_anticipos(
            dateToInt($from->format('Y-m-d')),
            dateToInt($until->format('Y-m-d')),
            $codcli
        );
        json_output(['data' => $rows ?: []]);
    }

    public function client_overdue_table() : void {
        $codcli = (int)($_POST['codcli'] ?? 0);
        if ($codcli <= 0) {
            json_output(['data' => []]);
            return;
        }
        json_output(['data' => $this->clientesModel->get_client_overdue($codcli) ?: []]);
    }

    public function account_statement_table() : void {
        $tipo   = ($_POST['tipo'] ?? 'credit') === 'debit' ? 'debit' : 'credit';
        $codcli = (int)($_POST['codcli'] ?? 0);
        $from   = $this->createDateTime($_POST['from']  ?? date('Y-01-01'));
        $until  = $this->createDateTime($_POST['until'] ?? date('Y-m-d'));

        if ($codcli < 0 || !isset($_POST['codcli'])) {
            json_output(['data' => [], 'summary' => null]);
            return;
        }

        $fromInt  = dateToInt($from->format('Y-m-d'));
        $untilInt = dateToInt($until->format('Y-m-d'));

        // codcli = 0 → resumen de todos los clientes con movimientos en el periodo
        if ($codcli === 0) {
            $rows = ($tipo === 'debit')
                ? $this->clientesModel->get_account_summary_debit($fromInt, $untilInt)
                : $this->clientesModel->get_account_summary_credit($fromInt, $untilInt);
            json_output(['data' => $rows ?: [], 'summary' => null]);
            return;
        }

        if ($tipo === 'debit') {
            $ini  = $this->clientesModel->get_initial_balance_debit($fromInt, $codcli);
            $rows = $this->clientesModel->get_account_statement_debit($fromInt, $untilInt, $codcli) ?: [];
        } else {
            $ini  = $this->clientesModel->get_initial_balance_credit($fromInt, $codcli);
            $rows = $this->clientesModel->get_account_statement_credit($fromInt, $untilInt, $codcli) ?: [];
        }

        $saldo  = $ini ? (float)$ini['SaldoInicial'] : 0.0;
        $cargos = $abonos = 0.0;
        $data   = [];
        foreach ($rows as $r) {
            $monto = (float)($r['Importe'] ?? $r['Monto']);
            $esCargo = ($tipo === 'debit')
                ? strpos($r['Movimiento'], 'CARGO') === 0
                : (int)($r['tipope'] ?? 0) === 3;
            if ($esCargo) { $cargos += $monto; } else { $abonos += $monto; }
            $saldo += $monto;
            $r['SaldoCorrido'] = round($saldo, 2);
            unset($r['tipope']);
            $data[] = $r;
        }

        json_output([
            'data'    => $data,
            'summary' => [
                'cliente'       => $ini['Cliente'] ?? '',
                'saldo_inicial' => $ini ? round((float)$ini['SaldoInicial'], 2) : 0,
                'cargos'        => round($cargos, 2),
                'abonos'        => round($abonos, 2),
                'saldo_final'   => round($saldo, 2),
                'saldo_sistema' => $ini ? round((float)$ini['SaldoSistema'], 2) : 0,
            ],
        ]);
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
                    'Estación'       => $despacho['Estación'],
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
        ini_set('memory_limit', '1024M');
        set_time_limit(300);
        $codgas = $_POST['codgas'];
        $billed = $_POST['billed'];
        $tipo_cliente=0;

        $dispatches = $this->despachosModel->control_dispatches2(dateToInt($_POST['from']), dateToInt($_POST['until']), $codgas,$_POST['uuid'],$tipo_cliente,$billed);
        if (!$dispatches) {
            json_output(array("data" => []));
            return;
        }

        // Transformar cada fila EN SITIO (por referencia) para no mantener
        // una segunda copia completa del dataset en memoria.
        foreach ($dispatches as &$dispatch) {
            $dispatch['hora_formateada'] = date("H:i", strtotime($dispatch['hora_formateada']));
            $dispatch['cliente_fac']     = $dispatch['cliente_fac']   ?? $dispatch['cliente_des'];
            $dispatch['factura']         = $dispatch['factura']       ?? $dispatch['factura_desp'];
            $dispatch['UUID']            = $dispatch['UUID']          ?? ".";
            $dispatch['codigo_cliente']  = ($dispatch['codigo_cliente'] < 0 ? "" : $dispatch['codigo_cliente']);
            $dispatch['tipo_pago']       = $dispatch['tipo_pago']     ?? $dispatch['tipo_pago_despacho'];

            // Liberar columnas auxiliares que el frontend no utiliza.
            unset(
                $dispatch['gasfac'],
                $dispatch['nrofac'],
                $dispatch['factura_desp'],
                $dispatch['UUID_fac'],
                $dispatch['UUID_dep'],
                $dispatch['tipval']
            );
        }
        unset($dispatch); // romper la referencia del último elemento

        json_output(array("data" => $dispatches));
    }

    /**
     * Versión server-side (paginada) del reporte diario corporativo.
     * Sustituye la carga client-side que reventaba la memoria con rangos amplios:
     * solo procesa y devuelve la página visible. Responde el formato que espera
     * DataTables: {draw, recordsTotal, recordsFiltered, data}.
     */
    function datatables_dispatches_paginated() : void {
        ini_set('memory_limit', '512M');
        set_time_limit(300);

        $draw   = isset($_POST['draw'])   ? (int) $_POST['draw']   : 0;
        $start  = isset($_POST['start'])  ? (int) $_POST['start']  : 0;
        $length = isset($_POST['length']) ? (int) $_POST['length'] : 100;

        $codgas = (int) ($_POST['codgas'] ?? 0);
        $billed = $_POST['billed'] ?? 0;
        $uuid   = $_POST['uuid'] ?? 0;
        $tipo_cliente = 0;

        // Orden solicitado por DataTables.
        $orderColIdx = isset($_POST['order'][0]['column']) ? (int) $_POST['order'][0]['column'] : 0;
        $orderDir    = $_POST['order'][0]['dir'] ?? 'asc';
        $orderColKey = $_POST['columns'][$orderColIdx]['data'] ?? 'fecha';

        // Búsquedas por columna + búsqueda global.
        $columnSearches = [];
        if (!empty($_POST['columns']) && is_array($_POST['columns'])) {
            foreach ($_POST['columns'] as $col) {
                if (isset($col['data'], $col['search']['value']) && $col['search']['value'] !== '') {
                    $columnSearches[$col['data']] = $col['search']['value'];
                }
            }
        }
        $globalSearch = $_POST['search']['value'] ?? '';

        $result = $this->despachosModel->control_dispatches2_paginated(
            dateToInt($_POST['from']), dateToInt($_POST['until']),
            $codgas, $uuid, $tipo_cliente, $billed,
            $start, $length, $orderColKey, $orderDir, $columnSearches, $globalSearch
        );

        // Transformar SOLO la página actual (decenas de filas, no todo el dataset).
        foreach ($result['data'] as &$dispatch) {
            $dispatch['hora_formateada'] = date("H:i", strtotime($dispatch['hora_formateada']));
            $dispatch['cliente_fac']     = $dispatch['cliente_fac']   ?? $dispatch['cliente_des'];
            $dispatch['factura']         = $dispatch['factura']       ?? $dispatch['factura_desp'];
            $dispatch['UUID']            = $dispatch['UUID']          ?? ".";
            $dispatch['codigo_cliente']  = ($dispatch['codigo_cliente'] < 0 ? "" : $dispatch['codigo_cliente']);
            $dispatch['tipo_pago']       = $dispatch['tipo_pago']     ?? $dispatch['tipo_pago_despacho'];
        }
        unset($dispatch);

        json_output([
            'draw'            => $draw,
            'recordsTotal'    => $result['recordsTotal'],
            'recordsFiltered' => $result['recordsFiltered'],
            'data'            => $result['data'],
        ]);
    }

    /**
     * Exporta a Excel TODAS las filas que cumplen los filtros/búsquedas actuales
     * del reporte Control Despachos - Corporativo (no solo la página visible).
     * Recibe el mismo payload que arma DataTables (table.ajax.params()), por lo
     * que respeta los mismos filtros que ve el usuario en pantalla.
     *
     * Escribe con OpenSpout en streaming: cada fila viaja del cursor de la BD
     * directo al XLSX sin materializar el dataset en memoria, por lo que no hay
     * límite de registros (antes PhpSpreadsheet armaba todo el archivo en RAM y
     * obligaba a rechazar rangos de más de 30,000 filas). Si se rebasa el máximo
     * de filas por hoja de Excel (1,048,576), OpenSpout continúa en otra hoja.
     */
    function export_dispatches_excel() : void {
        ini_set('memory_limit', '512M');
        set_time_limit(0);

        $codgas = (int) ($_POST['codgas'] ?? 0);
        $billed = $_POST['billed'] ?? 0;
        $uuid   = $_POST['uuid'] ?? 0;
        $tipo_cliente = 0;

        $orderColIdx = isset($_POST['order'][0]['column']) ? (int) $_POST['order'][0]['column'] : 0;
        $orderDir    = $_POST['order'][0]['dir'] ?? 'asc';
        $orderColKey = $_POST['columns'][$orderColIdx]['data'] ?? 'fecha';

        $columnSearches = [];
        if (!empty($_POST['columns']) && is_array($_POST['columns'])) {
            foreach ($_POST['columns'] as $col) {
                if (isset($col['data'], $col['search']['value']) && $col['search']['value'] !== '') {
                    $columnSearches[$col['data']] = $col['search']['value'];
                }
            }
        }
        $globalSearch = $_POST['search']['value'] ?? '';
        $from = dateToInt($_POST['from']);
        $until = dateToInt($_POST['until']);

        // Mismo orden/etiquetas que el encabezado de la tabla en pantalla.
        $headers = [
            'Fecha', 'Hora', 'Turno', 'Despacho', 'Producto', 'Estacion', 'Empresa',
            'Cliente', 'Cantidad', 'Importe', 'Precio', 'Despachador', 'Pago',
            'Factura', 'Fecha Factura', 'UUID', 'Notas', 'Rut', 'Denominacion',
            'Codigo', 'Tipo', 'Tipo Aplicativo', 'Vehiculo', 'Placas',
        ];
        $fields = [
            'fecha', 'hora_formateada', 'turno', 'despacho', 'producto', 'estacion', 'empresa',
            'cliente_fac', 'cantidad', 'importe', 'precio', 'despachador', 'tipo_pago',
            'factura', 'FechaFactura', 'UUID', 'txtref', 'rut', 'denominacion',
            'codigo_cliente', 'tipo_cliente', 'tipo_cliente_aplicativo', 'vehiculo', 'placas',
        ];

        $dispatches = $this->despachosModel->stream_dispatches2_all(
            $from, $until, $codgas, $uuid, $tipo_cliente, $billed,
            $orderColKey, $orderDir, $columnSearches, $globalSearch
        );
        // Ejecutar la consulta (hasta la primera fila) ANTES de enviar headers:
        // si la BD falla, todavía podemos responder JSON de error en vez de
        // mandar un archivo truncado con status 200.
        try {
            $dispatches->valid();
        } catch (Throwable $e) {
            http_response_code(500);
            json_output(['error' => 'No se pudo generar el Excel. Intentelo nuevamente.']);
            return;
        }

        // OpenSpout arma el XLSX en archivos temporales antes de enviarlo. En
        // IIS el usuario del app pool no puede escribir en C:\Windows\TEMP (la
        // carpeta temporal por defecto), así que usamos una propia dentro de
        // la aplicación.
        $tempFolder = ROOT . 'temp';
        if (!is_dir($tempFolder)) {
            @mkdir($tempFolder, 0775, true);
        }
        if (!is_dir($tempFolder) || !is_writable($tempFolder)) {
            http_response_code(500);
            json_output(['error' => 'No se pudo generar el Excel: la carpeta temporal "' . $tempFolder .
                                    '" no existe o no tiene permisos de escritura para el usuario del servidor web.']);
            return;
        }

        $options = new SpoutXlsxOptions();
        $options->setTempFolder($tempFolder);
        $options->DEFAULT_COLUMN_WIDTH = 16;
        $writer = new SpoutXlsxWriter($options);
        $writer->openToBrowser('Control_Despachos_Corporativo.xlsx');
        $writer->getCurrentSheet()->setName('Control Despachos');

        $bold = (new SpoutStyle())->setFontBold();
        $writer->addRow(SpoutRow::fromValues($headers, $bold));

        foreach ($dispatches as $d) {
            $d['hora_formateada'] = date("H:i", strtotime($d['hora_formateada']));
            $d['cliente_fac']     = $d['cliente_fac']   ?? $d['cliente_des'];
            $d['factura']         = $d['factura']       ?? $d['factura_desp'];
            $d['UUID']            = $d['UUID']          ?? ".";
            $d['codigo_cliente']  = ($d['codigo_cliente'] < 0 ? "" : $d['codigo_cliente']);
            $d['tipo_pago']       = $d['tipo_pago']     ?? $d['tipo_pago_despacho'];

            $values = [];
            foreach ($fields as $field) {
                $v = $d[$field] ?? '';
                // sqlsrv entrega los numéricos como texto; convertirlos para que
                // Excel los trate como números (igual que hacía PhpSpreadsheet).
                $values[] = is_numeric($v) ? $v + 0 : $v;
            }
            $writer->addRow(SpoutRow::fromValues($values));
        }

        $writer->close();
        exit;
    }

    /**
     * Resumen de Factura Global para los collapses del reporte. Se calcula con un
     * GROUP BY ligero en SQL (no trae el detalle), reemplazando el agruparPorFactura
     * que recorría todo el dataset en el navegador.
     */
    function factura_global_summary() : void {
        $codgas = (int) ($_POST['codgas'] ?? 0);
        $billed = $_POST['billed'] ?? 0;
        $uuid   = $_POST['uuid'] ?? 0;
        $rows = $this->despachosModel->factura_global_summary(
            dateToInt($_POST['from']), dateToInt($_POST['until']),
            $codgas, $uuid, 0, $billed
        );
        json_output(array("data" => $rows));
    }

    function datatables_dispatches_est() : void {
        ini_set('max_execution_time', 5000);
        ini_set('memory_limit', '1024M');
        set_time_limit(0); // sin límite
        $codgas = $_POST['codgas'];
        $billed = $_POST['billed'];
        $tipo_cliente=0;
        $from  = dateToInt($_POST['from']);
        $until = dateToInt($_POST['until']);
        $estation= $this->gasolinerasModel->get_estations_servidor_cod_gas($codgas);
        if (empty($estation)) {
            json_output(array("data" => [], "error" => "No se encontró la estación seleccionada (codgas $codgas)."));
            return;
        }

        // Camino principal: conexión directa al SQL Server de la estación
        // (sin API intermedio: evita el doble viaje decode/encode de ~20MB de JSON).
        $rows = null;
        try {
            $rows = $this->despachosModel->control_dispatches_est_direct($from, $until, $_POST['uuid'], $billed, $estation);
        } catch (\Throwable $e) {
            error_log("datatables_dispatches_est: conexión directa a {$estation['servidor']} falló: " . $e->getMessage());
        }

        // Respaldo: OPENQUERY a través del servidor central (linked server).
        if ($rows === null) {
            try {
                // El modelo espera una LISTA de estaciones; aquí solo se consulta una.
                $rows = $this->despachosModel->control_dispatches_est($from, $until, $codgas, $_POST['uuid'], $tipo_cliente, $billed, [$estation]) ?: [];
            } catch (\Throwable $e) {
                error_log("datatables_dispatches_est: consulta por linked server falló: " . $e->getMessage());
                json_output(array("data" => [], "error" => "No se pudo obtener la información de despachos. Intente de nuevo."));
                return;
            }
        }

        if (empty($rows)) {
            json_output(array("data" => []));
            return;
        }

        // Sin recorridos en PHP: el SQL ya entrega las 24 columnas finales con
        // hora HH:mm, coalesce de cliente/factura/UUID/pago y código de cliente.
        json_output_gzip(array("data" => $rows));
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
        if ($dispatch_type == 'dbito' || $dispatch_type == 'Débito' || $dispatch_type == 'd%c3%a9bito') {
            $dispatches = $this->despachosModel->get_debit_dispatches_to_release($from, $codgas, $shift);
        } elseif ($dispatch_type == 'crdito' || $dispatch_type == 'Crédito' || $dispatch_type == 'c%c3%a9dito') {
            $dispatches = $this->despachosModel->get_credit_dispatches_to_release($from, $codgas, $shift);
        } elseif ($dispatch_type == 'payworks' || $dispatch_type == 'Payworks') {
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
        } else if ($dispatch_type == 'crdito' || $dispatch_type == 'c%c3%a9dito' || $dispatch_type == 'Crédito') {
            $dispatches = $this->despachosModel->get_credit_dispatches_just_to_release($from, $codgas, $shift);
        } else if ($dispatch_type == 'dbito' || $dispatch_type == 'd%c3%a9bito' || $dispatch_type == 'Débito') {
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
        $payment_type = $_POST['dispatch_type'] ?? '';

        // Verificamos que el despacho exista
        if ($dispatch = $this->despachosModel->check_dispatch(intval($nrotrn), $codgas, $fch)) {
            $tipval = intval($dispatch[0]['tipval'] ?? 0);

            // Hotfix: normalizamos el tipo recibido para evitar falsos positivos por acentos/codificación.
            $normalized_type = trim((string)$payment_type);
            $normalized_type = rawurldecode($normalized_type);
            $normalized_type = urldecode($normalized_type);
            $normalized_type = strtolower($normalized_type);
            $normalized_type = str_replace(
                array('á', 'é', 'í', 'ó', 'ú', 'á', 'é', 'í', 'ó', 'ú'),
                array('a', 'e', 'i', 'o', 'u', 'a', 'e', 'i', 'o', 'u'),
                $normalized_type
            );
            $normalized_type = str_replace(' ', '', $normalized_type);

            $expected_tipval = null;
            if (strpos($normalized_type, 'credito') !== false || strpos($normalized_type, 'crdito') !== false) {
                $expected_tipval = 3;
            } elseif (strpos($normalized_type, 'debito') !== false || strpos($normalized_type, 'dbito') !== false) {
                $expected_tipval = 4;
            }

            if (!is_null($expected_tipval) && $tipval !== $expected_tipval) {
                json_output(array("status" => "error", "message" => "Este despacho no puede ser liberado por no ser del tipo correcto."));
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

        if (send_mail_with_fallback('Solicitud de tickets faltantes ' . $fch,$body,explode(';', $sentTo),'totalgasdesarrollo@gmail.com')) {
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
        // Limpiar cualquier output previo del buffer para no corromper el archivo
        while (ob_get_level()) {
            ob_end_clean();
        }

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

     public function ejecutar_robot_manual() {
        ob_clean();
        header("Content-Type: application/json");

        $banco = $_POST["banco"] ?? "";
        $fecha = $_POST["fecha"] ?? "";
        $estacion = $_POST["estacion"] ?? ""; 

        if (empty($banco) || empty($fecha)) {
            echo json_encode(["status" => "error", "message" => "Faltan parámetros."]);
            exit;
        }

        $exe_path = "C:\\Software\\TareasProgramadas\\conc\\dist\\bancos_manual.exe";

        $cmd = "\"$exe_path\" --banco \"$banco\" --fecha \"$fecha\"" . ($estacion ? " --estacion \"$estacion\"" : "") . " 2>&1";
        
        set_time_limit(300);
        $output_lines = []; $result_code = -1;
        exec($cmd, $output_lines, $result_code);
        $output = implode("\n", $output_lines);

        if (empty(trim($output))) {
            echo json_encode(["status" => "error", "message" => "Robot falló (vacío)", "cmd" => $cmd, "code" => $result_code]);
            exit;
        }

        $start_pos = strpos($output, "{");
        if ($start_pos !== false) {
            echo substr($output, $start_pos);
        } else {
            echo json_encode(["status" => "error", "message" => "Error robot", "output" => $output, "cmd" => $cmd]);
        }
        exit;
    }

    public function insertar_correccion_manual() {
        ob_clean();
        header('Content-Type: application/json');

        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input || empty($input['data']) || empty($input['banco'])) {
            echo json_encode(["status" => "error", "message" => "Datos incompletos."]);
            exit;
        }

        $banco = $input['banco'];
        $datos = $input['data'];

        $server = "192.168.0.6"; $db = "TG"; $user = "cguser"; $pass = "sahei1712";

        try {
            $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $tabla = ($banco == 'BANORTE') ? 'banco_banorte' : 'banco_getnet';
            // Obtener huellas existentes
            $stmt = $conn->query("SELECT Afiliacion, ID_Externo, Fecha_Transaccion, Monto, Hora, Codigo_Autorizacion, Referencia, Terminal FROM $tabla");
            $huellas = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $afil_db   = ltrim(trim((string)$row['Afiliacion']), '0');
                $id_ext_db = trim((string)$row['ID_Externo']);
                $fch_db    = $row['Fecha_Transaccion'] ? substr((string)$row['Fecha_Transaccion'], 0, 10) : '';
                $monto_db  = number_format((float)$row['Monto'], 2, '.', '');
                $hora_db   = trim((string)$row['Hora']);
                $auth_db   = trim((string)$row['Codigo_Autorizacion']);
                $ref_db    = trim((string)$row['Referencia']);
                $term_db   = trim((string)$row['Terminal']);

                $key = "$afil_db|$id_ext_db|$fch_db|$monto_db|$hora_db|$auth_db|$ref_db|$term_db";
                $huellas[$key] = true;
            }

            $conn->beginTransaction();

            $sqlDet = "INSERT INTO $tabla 
                        (ID_Externo, Afiliacion, Fecha_Transaccion, Hora, Monto, Codigo_Autorizacion, Terminal, Referencia, Fecha_Deposito, Nombre_Archivo) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmtDet = $conn->prepare($sqlDet);

            $insertados = 0;
            foreach ($datos as $row) {
                $afil = ltrim(trim((string)$row['Afiliacion']), '0');
                $id_ext = trim((string)$row['ID_Externo']);
                $fch = $row['Fecha_Transaccion'];
                $monto = number_format((float)$row['Monto'], 2, '.', '');
                $hora = trim((string)$row['Hora']);
                $auth = trim((string)$row['Codigo_Autorizacion']);
                $ref = trim((string)$row['Referencia']);
                $term = trim((string)$row['Terminal']);

                $key = "$afil|$id_ext|$fch|$monto|$hora|$auth|$ref|$term";

                if (isset($huellas[$key])) {
                    continue; // Skip duplicate
                }

                $stmtDet->execute([
                    $id_ext,
                    $row['Afiliacion'],
                    $row['Fecha_Transaccion'],
                    $row['Hora'],
                    $row['Monto'],
                    $row['Codigo_Autorizacion'],
                    $row['Terminal'],
                    $row['Referencia'],
                    $row['Fecha_Deposito'],
                    $row['Nombre_Archivo']
                ]);
                $huellas[$key] = true;
                $insertados++;
            }

            $conn->commit();
            echo json_encode(["status" => "success", "message" => "Se insertaron $insertados registros correctamente."]);

        } catch (Exception $e) {
            if ($conn) $conn->rollBack();
            echo json_encode(["status" => "error", "message" => "Error al insertar: " . $e->getMessage()]);
        }
        exit;
    }

  private function sanitizar_nombre_columna_php($nombre, $bankType, $coreMap) {
        if (!$nombre) return "SinNombre";

        // 1. Limpieza inicial y normalización de espacios y BOM
        $orig = str_replace(["\xEF\xBB\xBF", "\xFE\xFF", "\xFF\xFE", "\xC2\xA0"], ' ', (string)$nombre);
        $orig = trim(preg_replace('/\s+/', ' ', $orig));

        // 2. Intento de reparación de codificación (Caracteres dobles)
        if (preg_match('/[\xC2\xC3][\x80-\xBF]/', $orig)) {
            $repaired = @utf8_decode($orig);
            if ($repaired && mb_check_encoding($repaired, 'UTF-8')) {
                $orig = $repaired;
            }
        }

        // 3. Búsqueda en el mapa de columnas estándar (la vía más rápida)
        if (isset($coreMap[$bankType][$orig])) {
            return $coreMap[$bankType][$orig];
        }

        // 4. FUZZY MATCH: Normalizar a ASCII mayúsculas para comparaciones flexibles
        $norm = mb_strtoupper($orig, 'UTF-8');
        // Reemplazo de vocales con acentos y caracteres especiales
        $norm = str_replace(
            ['À','Á','Â','Ã','Ä','È','É','Ê','Ë','Ì','Í','Î','Ï','Ò','Ó','Ô','Õ','Ö','Ù','Ú','Û','Ü','Ó','É','Ó'],
            ['A','A','A','A','A','E','E','E','E','I','I','I','I','O','O','O','O','O','U','U','U','U','O','E','O'],
            $norm
        );
        // Quitar caracteres no alfanuméricos para simplificar la comparación
        $norm = preg_replace('/[^A-Z0-9\s]/', '', $norm);

        // Reglas de Fuzzy Matching específicas
        // HORA debe evaluarse ANTES que TRANSACCION para que "Hora Transaccion" no se mapee a ID_Externo
        if (strpos($norm, 'HORA') !== false) return 'Hora';
        if (strpos($norm, 'TRANSACCION') !== false || (strpos($norm, 'TRANS') !== false && $bankType === 'AFIRME')) {
            if (strpos($norm, 'FECHA') !== false) return 'Fecha_Transaccion';
            return ($bankType === 'AFIRME') ? 'Referencia' : 'ID_Externo';
        }
        if (strpos($norm, 'FECHA') !== false) {
            if (strpos($norm, 'DEPOSITO') !== false || strpos($norm, 'APLICACION') !== false || strpos($norm, 'PAGO') !== false) return 'Fecha_Deposito';
        }
        if (strpos($norm, 'ID MOVIMIENTO') !== false || (strpos($norm, 'REFERENCIA') !== false && strpos($norm, 'CARGO') !== false)) return 'ID_Externo';
        if (strpos($norm, 'AFILIACION') !== false || strpos($norm, 'ESTABLECIMIENTO') !== false || (strpos($norm, 'COMERCIO') !== false && strpos($norm, 'NOMBRE') === false)) return 'Afiliacion';
        if (strpos($norm, 'TARJETA') !== false) return 'Tarjeta';
        if (strpos($norm, 'TERMINAL') !== false) return 'Terminal';
        if ($norm === 'TOTAL' || $norm === 'MONTO' || $norm === 'IMPORTE' || (strpos($norm, 'MONTO') !== false && strpos($norm, 'CARGO') !== false && strpos($norm, 'RETIRO') === false)) return 'Monto';
        // Excluir columnas tipo "Tipo de autorizacion de switch" que no son codigo de autorizacion real
        if ((strpos($norm, 'COD') !== false && strpos($norm, 'AUT') !== false) || (strpos($norm, 'AUTORIZACION') !== false && strpos($norm, 'TIPO') === false)) return 'Codigo_Autorizacion';
        if (strpos($norm, 'COD') !== false && strpos($norm, 'TERMINAL') !== false) return 'Terminal';
        if (strpos($norm, 'REFERENCIA') !== false) return 'Referencia';

        // 5. Fallback: si todo falla, limpiar el nombre original para usarlo como último recurso
        $fallback = preg_replace('/[^a-zA-Z0-9_]/', '_', $orig);
        return trim(preg_replace('/_+/', '_', $fallback), '_');
    }
    private function asegurar_columnas_php($conn, $tabla, $cleanCols) {
        // FUNCIÓN DESACTIVADA PARA MANTENER ESTÁNDAR DE TABLAS
        return;
    }

    private function obtener_ajuste_juarez_php($fecha_trans) {
        if (!$fecha_trans || trim((string)$fecha_trans) === '-') return -1;
        try {
            $dt = ($fecha_trans instanceof \DateTime) ? $fecha_trans : new \DateTime($fecha_trans);
            $year = (int)$dt->format('Y');
        } catch (\Throwable $e) {
            return -1;
        }
        
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

    /**
     * Determina, según la afiliación bancaria, si se debe aplicar el ajuste
     * de horario Juárez (CDMX -> Juárez) o si se debe conservar la hora tal
     * como viene del banco (CDMX).
     *
     * Regla solicitada por negocio:
     *  - Foráneas y Parral: conservar hora original del banco (CDMX).
     *  - Demás estaciones: aplicar ajuste Juárez (invierno -1 hora).
     *
     * La detección se hace a partir de Tesoreria_afil + Estaciones /
     * Tesoreria_Estaciones_Virtuales, y se cachea por petición.
     */
    private function debe_ajustar_juarez_por_afiliacion(?string $afiliacion, string $bankType): bool {
        static $cachePoliticas = [];

        if (!$afiliacion) {
            // Sin afiliación clara, se mantiene el comportamiento anterior
            return true;
        }

        $bankKey = strtoupper($bankType);
        if (!isset($cachePoliticas[$bankKey])) {
            $cachePoliticas[$bankKey] = [];

            $entidadId = null;
            if ($bankKey === 'BANORTE') {
                $entidadId = 4;
            } elseif ($bankKey === 'SANTANDER') {
                $entidadId = 1;
            }

            if ($entidadId === null) {
                // Otros bancos no usan este mapeo por ahora
                $cachePoliticas[$bankKey] = [];
            } else {
                $server = "192.168.0.6";
                $db     = "TG";
                $user   = "cguser";
                $pass   = "sahei1712";

                try {
                    $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
                    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                    $sqlAfil = "SELECT 
                                    A.afiliacion,
                                    ISNULL(S.Nombre, V.Nombre) as Estacion,
                                    ISNULL(A.rfc, 'FORANEAS') as RFC
                                FROM Tesoreria_afil A
                                LEFT JOIN Estaciones S ON A.estacion_id = S.Codigo
                                LEFT JOIN Tesoreria_Estaciones_Virtuales V ON A.estacion_id = V.Codigo
                                WHERE A.entidad_id = :entidad
                                  AND LEN(ISNULL(A.afiliacion,'')) > 0
                                  AND (S.Nombre IS NOT NULL OR V.Nombre IS NOT NULL)";

                    $stmtAfil = $conn->prepare($sqlAfil);
                    $stmtAfil->execute([':entidad' => $entidadId]);

                    $map = [];
                    while ($r = $stmtAfil->fetch(PDO::FETCH_ASSOC)) {
                        $af        = ltrim(trim((string)($r['afiliacion'] ?? '')), '0');
                        if ($af === '') continue;

                        $estacion  = strtoupper(trim((string)($r['Estacion'] ?? '')));
                        $rfc       = strtoupper(trim((string)($r['RFC'] ?? '')));

                        $esForanea = ($rfc === '' || $rfc === 'FORANEAS');
                        $esParral  = (strpos($estacion, 'PARRAL') !== false);
                        $esClara   = (strpos($estacion, 'CLARA') !== false);

                        // CDMX = conservar hora; JUAREZ = aplicar ajuste horario
                        $map[$af] = ($esForanea || $esParral || $esClara) ? 'CDMX' : 'JUAREZ';
                    }

                    $cachePoliticas[$bankKey] = $map;
                } catch (\Throwable $e) {
                    // En caso de error de conexión/consulta, se deja vacío para no romper la carga
                    $cachePoliticas[$bankKey] = [];
                }
            }
        }

        $map = $cachePoliticas[$bankKey] ?? [];
        if (empty($map)) {
            // Sin configuración específica, mantener comportamiento actual
            return true;
        }

        $afTrim   = trim($afiliacion);
        $afLimpia = ltrim($afTrim, '0');

        // Buscar primero por valor tal cual, luego sin ceros a la izquierda
        $zona = null;
        if (isset($map[$afTrim])) {
            $zona = $map[$afTrim];
        } elseif (isset($map[$afLimpia])) {
            $zona = $map[$afLimpia];
        }

        if ($zona === null) {
            // Afiliación no mapeada explícitamente => ajustar (comportamiento anterior)
            return true;
        }

        // Sólo ajustamos para estaciones clasificadas como "JUAREZ"
        return ($zona === 'JUAREZ');
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

        $extension = strtolower(pathinfo($_POST['file_name'] ?? ($_FILES['report_file']['name'] ?? $filePath), PATHINFO_EXTENSION));

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
                // Versiones UTF-8 correctas (PhpSpreadsheet retorna UTF-8 real, no mojibake)
                "Afiliaci\xC3\xB3n"               => 'Afiliacion',
                "Nombre de Afiliaci\xC3\xB3n"     => 'Nombre_Afiliacion',
                "Estatus de Transacci\xC3\xB3n"   => 'Estatus',
                "Tipo de Transacci\xC3\xB3n"      => 'Tipo_Transaccion',
                "N\xC3\xBAmero de Control"         => 'ID_Externo',
                "N\xC3\xBAmero de Tarjeta"         => 'Tarjeta',
                "Monto de Transacci\xC3\xB3n Signo" => 'Monto',
                "Fecha Transacci\xC3\xB3n"         => 'Fecha_Transaccion',
                "C\xC3\xB3digo Autorizaci\xC3\xB3n" => 'Codigo_Autorizacion',
                "Hora de Transacci\xC3\xB3n"       => 'Hora',
                "Hora Transacci\xC3\xB3n"          => 'Hora',
                "Lote de Transacci\xC3\xB3n"       => 'Lote',
                "Fuente de Transacci\xC3\xB3n"     => 'Fuente_Transaccion',
                'Referencia Cliente 1'             => 'Referencia_Cliente_1',
                'Referencia Cliente 2'             => 'Referencia_Cliente_2',
                'Referencia Cliente 3'             => 'Referencia_Cliente_3',
                "Fecha Aplicaci\xC3\xB3n"          => 'Fecha_Deposito',
                "Fecha de Aplicaci\xC3\xB3n"       => 'Fecha_Deposito',
                'Fecha Depósito' => 'Fecha_Deposito',
                'Fecha Deposito' => 'Fecha_Deposito',
                'Fecha de Depósito' => 'Fecha_Deposito',
                'Fecha de Deposito' => 'Fecha_Deposito',
                'Fecha Aplicación' => 'Fecha_Deposito',
                'Fecha Aplicacion' => 'Fecha_Deposito',
                'Fecha de Aplicación' => 'Fecha_Deposito',
                'Fecha de Aplicacion' => 'Fecha_Deposito',
                'Fecha Transacción' => 'Fecha_Transaccion',
                'Fecha Transaccion' => 'Fecha_Transaccion',
                'Fecha Depósito' => 'Fecha_Deposito',
                'Fecha Deposito' => 'Fecha_Deposito',
                'Fecha Depósito' => 'Fecha_Deposito',
                'Fecha de Depósito' => 'Fecha_Deposito',
                'Fecha de Deposito' => 'Fecha_Deposito',
                'Fecha Aplicación' => 'Fecha_Deposito',
                'Fecha Aplicacion' => 'Fecha_Deposito',
                'Fecha Aplicación' => 'Fecha_Deposito',
                'Fecha de Aplicación' => 'Fecha_Deposito',
                'Fecha de Aplicacion' => 'Fecha_Deposito',
                'Fecha de Aplicación' => 'Fecha_Deposito',
            ],
            'SANTANDER' => [
                'ID movimiento' => 'ID_Externo',
                'ID Movimiento' => 'ID_Externo',
                'Fecha Transacción' => 'Fecha_Transaccion',
                'Fecha Transaccion' => 'Fecha_Transaccion',
                'Hora de Transacción' => 'Hora',
                'Hora de Transaccion' => 'Hora',
                'Hora Transacción' => 'Hora',
                'Hora Transaccion' => 'Hora',
                'Afiliación' => 'Afiliacion',
                'Afiliacion' => 'Afiliacion',
                'Cod. Terminal' => 'Terminal',
                'Terminal ID' => 'Terminal',
                'Código Autorización' => 'Codigo_Autorizacion',
                'Codigo Autorizacion' => 'Codigo_Autorizacion',
                'Cod. Aut' => 'Codigo_Autorizacion',
                'Total' => 'Monto',
                'Monto de Transacción Signo' => 'Monto',
                'Monto de Transaccion Signo' => 'Monto',
                'Referencia' => 'Referencia',
                'Fecha Depósito' => 'Fecha_Deposito',
                'Fecha Deposito' => 'Fecha_Deposito',
                'Fecha de Depósito' => 'Fecha_Deposito',
                'Fecha de Deposito' => 'Fecha_Deposito',
                'Fecha Aplicación' => 'Fecha_Deposito',
                'Fecha Aplicacion' => 'Fecha_Deposito',
                'Fecha de Aplicación' => 'Fecha_Deposito',
                'Fecha de Aplicacion' => 'Fecha_Deposito'
            ],
            'AMEX' => [
                'Fecha de la transacción' => 'Fecha_Transaccion',
                'Fecha de la transaccion' => 'Fecha_Transaccion',
                'Fecha de pago' => 'Fecha_Deposito',
                'Monto del cargo' => 'Monto',
                'Monto del pago' => 'Monto_Pago',
                'Número de establecimiento que envía' => 'Afiliacion',
                'Numero de establecimiento que envia' => 'Afiliacion',
                'Número de tarjeta' => 'Tarjeta',
                'Numero de tarjeta' => 'Tarjeta',
                'Número de referencia del cargo' => 'ID_Externo',
                'Numero de referencia del cargo' => 'ID_Externo',
                'Número de terminal' => 'Terminal',
                'Numero de terminal' => 'Terminal',
                'Tipo de autorización' => 'Codigo_Autorizacion',
                'Tipo de autorizacion' => 'Codigo_Autorizacion'
            ],
            'AFIRME' => [
                'Comercio' => 'Afiliacion',
                'Número de Tarjeta' => 'Tarjeta',
                'Numero de Tarjeta' => 'Tarjeta',
                'Fecha Venta' => 'Fecha_Transaccion',
                'Hora' => 'Hora',
                'Terminal' => 'Terminal',
                'Transacción' => 'Referencia',
                'Transaccion' => 'Referencia',
                'Autorización' => 'Codigo_Autorizacion',
                'Autorizacion' => 'Codigo_Autorizacion',
                'Importe' => 'Monto',
                'Fecha Depósito' => 'Fecha_Deposito',
                'Fecha Deposito' => 'Fecha_Deposito'
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
                $rows = [];
                $rawHeader = [];

                if ($extension === 'xlsx' || $extension === 'xls') {
                    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
                    $sheet = $spreadsheet->getActiveSheet();
                    $rows = $sheet->toArray();
                    $rawHeader = array_shift($rows);
                } else {
                    $handle = fopen($filePath, "r");
                    $firstLine = fgets($handle);
                    $delim = (strpos($firstLine, ";") !== false && strpos($firstLine, ",") === false) ? ";" : ",";
                    rewind($handle);
                    $rawHeader = fgetcsv($handle, 0, $delim);
                    while (($row = fgetcsv($handle, 0, $delim)) !== FALSE) {
                        $rows[] = $row;
                    }
                    fclose($handle);
                }

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

                // Huellas para duplicados (8 CAMPOS CLAVE — sin Fecha_Deposito, igual que Python)
                $stmtH = $conn->query("SELECT Afiliacion, ID_Externo, Fecha_Transaccion, Monto, Hora, Codigo_Autorizacion, Referencia, Terminal FROM banco_banorte");
                $huellas = [];
                while ($r = $stmtH->fetch(PDO::FETCH_ASSOC)) {
                    $fch = ($r['Fecha_Transaccion'] instanceof DateTime) ? $r['Fecha_Transaccion']->format('Y-m-d') : substr((string)$r['Fecha_Transaccion'], 0, 10);
                    $hora_db = trim($r['Hora'] ?? '');
                    // SQL Server time type returns HH:MM:SS.NNNNNNN — strip fractional part before normalizing
                    if (preg_match('/^(\d{1,2}):(\d{2}):(\d{2})/', $hora_db, $hm)) {
                        $hora_db = sprintf("%02d:%02d:%02d", $hm[1], $hm[2], $hm[3]);
                    }
                    $afil_db  = ltrim(trim($r['Afiliacion']??''), '0');
                    $idext_db = trim($r['ID_Externo']??'');
                    if (preg_match('/^="(.*)"$/', $idext_db, $em)) $idext_db = $em[1];
                    $monto_db = number_format((float)$r['Monto'], 2, '.', '');
                    $auth_db  = trim($r['Codigo_Autorizacion']??'');
                    if (preg_match('/^="(.*)"$/', $auth_db, $em)) $auth_db = $em[1];
                    $term_db  = trim($r['Terminal']??'');
                    if (preg_match('/^="(.*)"$/', $term_db, $em)) $term_db = $em[1];
                    // Normalizar Referencia: quitar sufijo ".0" de valores numéricos guardados desde CSV
                    $ref_db = trim($r['Referencia']??'');
                    if (preg_match('/^="(.*)"$/', $ref_db, $em)) $ref_db = $em[1];
                    if (preg_match('/^\d+\.0$/', $ref_db)) $ref_db = (string)((int)((float)$ref_db));
                    // Clave 8 campos
                    $key = "$afil_db|$idext_db|$fch|$monto_db|$hora_db|$auth_db|$ref_db|$term_db";
                    $huellas[$key] = true;
                    // Clave 7 campos (sin Referencia): compatibilidad con registros viejos que tienen
                    // Referencia_Pago en lugar de Referencia_Interbancaria
                    $key7 = "7f:$afil_db|$idext_db|$fch|$monto_db|$hora_db|$auth_db|$term_db";
                    $huellas[$key7] = true;
                }

                $sqlIns = "INSERT INTO banco_banorte (".implode(",", $columnas_oficiales).") VALUES (".implode(",", array_fill(0, count($columnas_oficiales), "?")).")";
                $ins = $conn->prepare($sqlIns);

                foreach ($rows as $row) {
                    if (empty(array_filter($row))) continue;

                    $dataRow = [];
                    foreach($columnas_oficiales as $col) {
                        if ($col === 'Nombre_Archivo') { $dataRow[$col] = $dbFilePath; continue; }
                        $val = isset($mappedIndices[$col]) ? $row[$mappedIndices[$col]] : null;
                        // Strip Excel formula notation: ="VALUE" → VALUE
                        if (is_string($val) && preg_match('/^="(.*)"$/', $val, $em)) $val = $em[1];

                        if ($col === 'Monto') $val = (float)str_replace(['$', ','], '', $val ?? 0);
                        if ($col === 'Fecha_Transaccion' || $col === 'Fecha_Deposito') {
                            if ($val && trim((string)$val) !== '-') {
                                try {
                                    $d = \DateTime::createFromFormat('d/m/Y', $val);
                                    if (!$d) $d = new \DateTime($val);
                                    $val = $d ? $d->format('Y-m-d') : null;
                                } catch (\Throwable $e) {
                                    $val = null;
                                }
                            } else {
                                $val = null;
                            }
                        }
                        if ($col === 'Afiliacion') $val = ltrim(trim($val ?? ''), '0');
                        if ($col === 'Hora' && $val && trim((string)$val) !== '-' && isset($dataRow['Fecha_Transaccion'])) {
                            // Sólo aplicar ajuste horario para estaciones configuradas como "JUAREZ"
                            $debeAjustar = $this->debe_ajustar_juarez_por_afiliacion($dataRow['Afiliacion'] ?? null, $bankType);
                            if ($debeAjustar) {
                                $ajuste = $this->obtener_ajuste_juarez_php($dataRow['Fecha_Transaccion']);
                                if ($ajuste !== 0) {
                                    try {
                                        $dt_full = new \DateTime($dataRow['Fecha_Transaccion'] . " " . $val);
                                        $dt_full->modify("$ajuste hours");
                                        $val = $dt_full->format('H:i:s');
                                        $dataRow['Fecha_Transaccion'] = $dt_full->format('Y-m-d');
                                    } catch(\Throwable $e) {}
                                }
                            }
                        }
                        $dataRow[$col] = ($val === null || $val === '') ? null : $val;
                    }

                    $hora_row = trim($dataRow['Hora'] ?? '');
                    if (preg_match('/^\d{1,2}:\d{2}:\d{2}$/', $hora_row)) {
                        $parts = explode(':', $hora_row);
                        $hora_row = sprintf("%02d:%02d:%02d", $parts[0], $parts[1], $parts[2]);
                    }
                    $afil_row  = trim($dataRow['Afiliacion']??'');
                    $idext_row = trim($dataRow['ID_Externo']??'');
                    $fecha_row = $dataRow['Fecha_Transaccion']??'';
                    $monto_row = number_format((float)($dataRow['Monto']??0), 2, '.', '');
                    $auth_row  = trim($dataRow['Codigo_Autorizacion']??'');
                    $ref_row   = trim($dataRow['Referencia']??'');
                    $term_row  = trim($dataRow['Terminal']??'');
                    $keyRow  = "$afil_row|$idext_row|$fecha_row|$monto_row|$hora_row|$auth_row|$ref_row|$term_row";
                    $keyRow7 = "7f:$afil_row|$idext_row|$fecha_row|$monto_row|$hora_row|$auth_row|$term_row";

                    if (($dataRow['Monto'] ?? 0) <= 0) { $skipped++; continue; }
                    if (isset($huellas[$keyRow]) || isset($huellas[$keyRow7])) { $skipped++; continue; }

                    $ins->execute(array_values($dataRow));
                    $inserted++;
                }
                
                            } elseif ($bankType === 'SANTANDER') {
                                $allRows = [];
                                $rawHeader = [];

                                if ($extension === 'xlsx' || $extension === 'xls') {
                                    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
                                    $sheet = $spreadsheet->getActiveSheet();
                                    $allRows = $sheet->toArray();
                                    $rawHeader = array_shift($allRows);
                                } else {
                                    $handle = fopen($filePath, "r");
                                    $firstLine = fgets($handle);
                                    $delim = (strpos($firstLine, ";") !== false && strpos($firstLine, ",") === false) ? ";" : ",";
                                    rewind($handle);
                                    $rawHeader = fgetcsv($handle, 0, $delim);
                                    // Santander sometimes has a displaced header (empty first col)
                                    $shifted = false;
                                    if (empty(trim((string)($rawHeader[0] ?? '')))) {
                                        array_shift($rawHeader);
                                        $shifted = true;
                                    }
                                    while (($row = fgetcsv($handle, 0, $delim)) !== FALSE) {
                                        if ($shifted) array_shift($row);
                                        $allRows[] = $row;
                                    }
                                    fclose($handle);
                                }
                                
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
                                    echo json_encode(['status' => 'error', 'message' => 'No se encontro la columna de Fecha de Deposito en el archivo.']);
                                    exit;
                                }
                                $idxDepo = $mappedIndices['Fecha_Deposito'];
                                
                                // Validar que todas las filas tengan fecha de deposito
                                foreach ($allRows as $rowIndex => $row) {
                                    if (empty(array_filter($row))) {
                                        unset($allRows[$rowIndex]);
                                        continue;
                                    }
                                    if (empty($row[$idxDepo] ?? null)) {
                                        echo json_encode(['status' => 'error', 'message' => 'El archivo no cuenta con el 100% de las fechas de deposito. No se proceso ningun registro.']);
                                        exit;
                                    }
                                }
                
                                // 2. Huellas para duplicados (8 CAMPOS CLAVE - ALINEADO CON PYTHON)
                                $stmt = $conn->query("SELECT Afiliacion, ID_Externo, Fecha_Transaccion, Monto, Hora, Codigo_Autorizacion, Referencia, Terminal FROM banco_getnet");
                                $huellas = [];
                                while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                                    $fch = ($r['Fecha_Transaccion'] instanceof DateTime) ? $r['Fecha_Transaccion']->format('Y-m-d') : substr((string)$r['Fecha_Transaccion'], 0, 10);
                                    
                                    // Normalizar HORA de la DB para que coincida con la del CSV
                                    $hora_db = trim($r['Hora'] ?? '');
                                    if (preg_match('/^\d{1,2}:\d{2}:\d{2}$/', $hora_db)) {
                                        $parts = explode(':', $hora_db);
                                        $hora_db = sprintf("%02d:%02d:%02d", $parts[0], $parts[1], $parts[2]);
                                    }

                                    $key = ltrim(trim($r['Afiliacion'] ?? ''), '0') . '|' . 
                                           trim($r['ID_Externo'] ?? '') . '|' . 
                                           $fch . '|' . 
                                           number_format((float)$r['Monto'], 2, '.', '') . '|' .
                                           $hora_db . '|' .
                                           trim($r['Codigo_Autorizacion'] ?? '') . '|' .
                                           trim($r['Referencia'] ?? '') . '|' .
                                           trim($r['Terminal'] ?? '');
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
                                            if ($val && trim((string)$val) !== '-') {
                                                try {
                                                    $d = \DateTime::createFromFormat('d/m/Y', $val);
                                                    if (!$d) $d = new \DateTime($val);
                                                    $val = $d ? $d->format('Y-m-d') : null;
                                                } catch (\Throwable $e) {
                                                    $val = null;
                                                }
                                            } else {
                                                $val = null;
                                            }
                                        }
                                        if ($col === 'Afiliacion') $val = ltrim(trim($val ?? ''), '0');
                                        if ($col === 'Hora' && $val && trim((string)$val) !== '-') {
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
                                            
                                            // AJUSTE HORARIO JUAREZ (solo estaciones tipo JUAREZ)
                                            if (isset($dataRow['Fecha_Transaccion'])) {
                                                $debeAjustar = $this->debe_ajustar_juarez_por_afiliacion($dataRow['Afiliacion'] ?? null, $bankType);
                                                if ($debeAjustar) {
                                                    $ajuste = $this->obtener_ajuste_juarez_php($dataRow['Fecha_Transaccion']);
                                                    if ($ajuste !== 0) {
                                                        try {
                                                            $dt_full = new \DateTime($dataRow['Fecha_Transaccion'] . " " . $val);
                                                            $dt_full->modify("$ajuste hours");
                                                            $val = $dt_full->format('H:i:s');
                                                            $dataRow['Fecha_Transaccion'] = $dt_full->format('Y-m-d');
                                                        } catch(\Throwable $e) {}
                                                    }
                                                }
                                            }
                                        }
                                        $dataRow[$col] = ($val === null || $val === '') ? null : $val;
                                    }

                                    // EVITAR REGISTROS FANTASMA (SIN ID O MONTO) - ALINEADO CON PYTHON
                                    $id_ext = trim($dataRow['ID_Externo'] ?? '');
                                    if (empty($id_ext) || $id_ext === '-' || ($dataRow['Monto'] ?? 0) <= 0) {
                                        $skipped++;
                                        continue;
                                    }
                
                                    // Huella para saltar duplicados (8 campos) - GENERADA CON DATOS FINALES
                                    $huella = trim($dataRow['Afiliacion'] ?? '') . '|' . 
                                              trim($dataRow['ID_Externo'] ?? '') . '|' . 
                                              trim($dataRow['Fecha_Transaccion'] ?? '') . '|' . 
                                              number_format((float)($dataRow['Monto'] ?? 0), 2, '.', '') . '|' . 
                                              trim($dataRow['Hora'] ?? '') . '|' . 
                                              trim($dataRow['Codigo_Autorizacion'] ?? '') . '|' . 
                                              trim($dataRow['Referencia'] ?? '') . '|' . 
                                              trim($dataRow['Terminal'] ?? '');
                                    
                                    if (isset($huellas[$huella])) { 
                                        $skipped++; 
                                        continue; 
                                    }
                
                                    $ins->execute(array_values($dataRow));
                                    $inserted++;
                                    $huellas[$huella] = true; // Prevenir duplicados en el mismo archivo
                                }
                            } elseif ($bankType === 'AMEX') {
                                $allRows = [];
                                $rawHeader = [];

                                                                                                  $handle = fopen($filePath, "r");
                                                                                                  // AMEX CSV usually has 9 lines of header before the actual "Transactions" header
                                                                                                  for($i=0; $i<9; $i++) fgets($handle); 
                                                                                                  
                                                                                                  // Buscamos la primera línea que no esté vacía para el header
                                                                                                  while (($headerLine = fgetcsv($handle, 0, ",")) !== FALSE) {
                                                                                                      if (empty(array_filter($headerLine))) continue;
                                                                                                      $rawHeader = $headerLine;
                                                                                                      break;
                                                                                                  }
                                                                 
                                                                                                  while (($row = fgetcsv($handle, 0, ",")) !== FALSE) {                                    if (empty(array_filter($row))) continue;
                                    $allRows[] = $row;
                                }
                                fclose($handle);

                                // 1. Mapear Índices
                                $mappedIndices = [];
                                foreach ($rawHeader as $i => $h) {
                                    $stdName = $this->sanitizar_nombre_columna_php($h, 'AMEX', $coreMap);
                                    if (in_array($stdName, $columnas_oficiales) || $stdName === 'Monto_Pago' || $stdName === 'Tarjeta') {
                                        $mappedIndices[$stdName] = $i;
                                    }
                                }

                                // VALIDACION FECHA PAGO (AMEX)
                                if (!isset($mappedIndices['Fecha_Deposito'])) {
                                    echo json_encode(['status' => 'error', 'message' => 'No se encontro la columna de Fecha de Pago en el archivo AMEX.']);
                                    exit;
                                }

                                // 2. Huellas AMEX (LLAVE COMPUESTA: Afiliacion, F.Trans, F.Pago, Monto, Monto_Pago, Tarjeta, Terminal, ID_Externo)
                                $stmt = $conn->query("SELECT Afiliacion, Fecha_Transaccion, Monto, Terminal, Fecha_Deposito, Monto_Pago, Tarjeta, ID_Externo FROM banco_amex");
                                $huellas = [];
                                while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                                    $fch = ($r['Fecha_Transaccion'] instanceof DateTime) ? $r['Fecha_Transaccion']->format('Y-m-d') : substr((string)$r['Fecha_Transaccion'], 0, 10);
                                    $fch_dep = ($r['Fecha_Deposito'] instanceof DateTime) ? $r['Fecha_Deposito']->format('Y-m-d') : substr((string)$r['Fecha_Deposito'], 0, 10);
                                    
                                    $key = trim($r['Afiliacion'] ?? '') . '|' . 
                                           $fch . '|' . 
                                           $fch_dep . '|' . 
                                           number_format((float)$r['Monto'], 2, '.', '') . '|' . 
                                           number_format((float)($r['Monto_Pago'] ?? 0), 2, '.', '') . '|' . 
                                           trim($r['Tarjeta'] ?? '') . '|' . 
                                           trim($r['Terminal'] ?? '') . '|' . 
                                           trim($r['ID_Externo'] ?? '');
                                    $huellas[$key] = true;
                                }

                                $columnas_amex = array_merge($columnas_oficiales, ['Monto_Pago', 'Tarjeta']);
                                $sqlIns = "INSERT INTO banco_amex (".implode(",", $columnas_amex).") VALUES (".implode(",", array_fill(0, count($columnas_amex), "?")).")";
                                $ins = $conn->prepare($sqlIns);

                                foreach ($allRows as $row) {
                                    $dataRow = [];
                                    foreach($columnas_amex as $col) {
                                        if ($col === 'Nombre_Archivo') { $dataRow[$col] = $dbFilePath; continue; }
                                        $val = isset($mappedIndices[$col]) ? ($row[$mappedIndices[$col]] ?? null) : null;
                                        
                                        if ($col === 'Monto' || $col === 'Monto_Pago') {
                                            $val = (float)str_replace(['MXN', '$', ',', ' '], '', $val ?? 0);
                                        }

                                        if ($col === 'Fecha_Transaccion' || $col === 'Fecha_Deposito') {
                                            if ($val && trim((string)$val) !== '-') {
                                                try {
                                                    $d = \DateTime::createFromFormat('j/n/Y', $val); // Soporta 1/2/2026
                                                    if (!$d) $d = \DateTime::createFromFormat('d/m/Y', $val);
                                                    if (!$d) $d = new \DateTime($val);
                                                    $val = $d ? $d->format('Y-m-d') : null;
                                                } catch (\Throwable $e) { $val = null; }
                                            } else { $val = null; }
                                        }
                                        if ($col === 'Afiliacion') $val = ltrim(trim($val ?? ''), '0');
                                        
                                        $dataRow[$col] = ($val === null || $val === '') ? null : $val;
                                    }

                                    // Para AMEX, si no tenemos Referencia pero sí ID_Externo, lo usamos como Referencia
                                    if (empty($dataRow['Referencia']) && !empty($dataRow['ID_Externo'])) {
                                        $dataRow['Referencia'] = $dataRow['ID_Externo'];
                                    }

                                    if (($dataRow['Monto'] ?? 0) <= 0) { $skipped++; continue; }

                                    // LLAVE COMPUESTA AMEX (8 campos)
                                    $huellaAMEX = trim($dataRow['Afiliacion'] ?? '') . '|' . 
                                                  ($dataRow['Fecha_Transaccion'] ?? '') . '|' . 
                                                  ($dataRow['Fecha_Deposito'] ?? '') . '|' . 
                                                  number_format((float)($dataRow['Monto'] ?? 0), 2, '.', '') . '|' . 
                                                  number_format((float)($dataRow['Monto_Pago'] ?? 0), 2, '.', '') . '|' . 
                                                  trim($dataRow['Tarjeta'] ?? '') . '|' . 
                                                  trim($dataRow['Terminal'] ?? '') . '|' . 
                                                  trim($dataRow['ID_Externo'] ?? '');

                                    if (isset($huellas[$huellaAMEX])) {
                                        $skipped++;
                                        continue;
                                    }

                                    $ins->execute(array_values($dataRow));
                                    $inserted++;
                                    $huellas[$huellaAMEX] = true;
                                }
                            } elseif ($bankType === 'AFIRME') {
                                $allRows = [];
                                $rawHeader = [];

                                $handle = fopen($filePath, "r");
                                // Afirme CSV starts directly with headers
                                $headerLine = fgetcsv($handle, 0, ",");
                                if ($headerLine && count($headerLine) > 1) {
                                    $rawHeader = $headerLine;
                                }

                                while (($row = fgetcsv($handle, 0, ",")) !== FALSE) {
                                    if (empty(array_filter($row))) continue;
                                    $allRows[] = $row;
                                }
                                fclose($handle);

                                // 1. Mapear Índices
                                $mappedIndices = [];
                                foreach ($rawHeader as $i => $h) {
                                    $stdName = $this->sanitizar_nombre_columna_php($h, 'AFIRME', $coreMap);
                                    if (in_array($stdName, $columnas_oficiales) || $stdName === 'Tarjeta') {
                                        $mappedIndices[$stdName] = $i;
                                    }
                                }

                                // 2. Huellas AFIRME (LLAVE COMPUESTA: Afiliacion, Tarjeta, F.Trans, Hora, Monto, Auth, Terminal, ID_Externo)
                                $stmt = $conn->query("SELECT Afiliacion, Tarjeta, Fecha_Transaccion, Hora, Monto, Codigo_Autorizacion, Terminal, ID_Externo FROM banco_afirme");
                                $huellas = [];
                                while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                                    $fch = ($r['Fecha_Transaccion'] instanceof DateTime) ? $r['Fecha_Transaccion']->format('Y-m-d') : substr((string)$r['Fecha_Transaccion'], 0, 10);
                                    
                                    // Normalizar HORA
                                    $hora_db = trim($r['Hora'] ?? '');
                                    if (preg_match('/^\d{1,2}:\d{2}:\d{2}$/', $hora_db)) {
                                        $parts = explode(':', $hora_db);
                                        $hora_db = sprintf("%02d:%02d:%02d", $parts[0], $parts[1], $parts[2]);
                                    }

                                    $key = trim($r['Afiliacion'] ?? '') . '|' . 
                                           trim($r['Tarjeta'] ?? '') . '|' . 
                                           $fch . '|' . 
                                           $hora_db . '|' . 
                                           number_format((float)$r['Monto'], 2, '.', '') . '|' . 
                                           trim($r['Codigo_Autorizacion'] ?? '') . '|' . 
                                           trim($r['Terminal'] ?? '') . '|' . 
                                           trim($r['ID_Externo'] ?? '');
                                    $huellas[$key] = true;
                                }

                                $sqlIns = "INSERT INTO banco_afirme (".implode(",", $columnas_oficiales).", Tarjeta) VALUES (".implode(",", array_fill(0, count($columnas_oficiales), "?")).", ?)";
                                $ins = $conn->prepare($sqlIns);

                                foreach ($allRows as $row) {
                                    $dataRow = [];
                                    $rawTarjeta = '';

                                    foreach($columnas_oficiales as $col) {
                                        if ($col === 'Nombre_Archivo') { $dataRow[$col] = $dbFilePath; continue; }
                                        $val = isset($mappedIndices[$col]) ? ($row[$mappedIndices[$col]] ?? null) : null;
                                        
                                        if ($col === 'Monto') $val = (float)str_replace(['$', ',', ' '], '', $val ?? 0);
                                        if ($col === 'Fecha_Transaccion' || $col === 'Fecha_Deposito') {
                                            if ($val && trim((string)$val) !== '-') {
                                                try {
                                                    $d = \DateTime::createFromFormat('d/m/Y', $val);
                                                    if (!$d) $d = new \DateTime($val);
                                                    $val = $d ? $d->format('Y-m-d') : null;
                                                } catch (\Throwable $e) { $val = null; }
                                            } else { $val = null; }
                                        }
                                        if ($col === 'Afiliacion') $val = ltrim(trim($val ?? ''), '0');
                                        
                                        if ($col === 'Hora' && $val && trim((string)$val) !== '-' && isset($dataRow['Fecha_Transaccion'])) {
                                            // AJUSTE HORARIO JUAREZ
                                            $debeAjustar = $this->debe_ajustar_juarez_por_afiliacion($dataRow['Afiliacion'] ?? null, $bankType);
                                            if ($debeAjustar) {
                                                $ajuste = $this->obtener_ajuste_juarez_php($dataRow['Fecha_Transaccion']);
                                                if ($ajuste !== 0) {
                                                    try {
                                                        $dt_full = new \DateTime($dataRow['Fecha_Transaccion'] . " " . $val);
                                                        $dt_full->modify("$ajuste hours");
                                                        $val = $dt_full->format('H:i:s');
                                                        $dataRow['Fecha_Transaccion'] = $dt_full->format('Y-m-d');
                                                    } catch(\Throwable $e) {}
                                                }
                                            }
                                        }

                                        $dataRow[$col] = ($val === null || $val === '') ? null : $val;
                                    }

                                    $rawTarjeta = isset($mappedIndices['Tarjeta']) ? trim($row[$mappedIndices['Tarjeta']] ?? '') : '';
                                    
                                    // Asegurar Referencia (Transaccion) explícitamente para Afirme
                                    if (empty($dataRow['Referencia']) && isset($mappedIndices['Referencia'])) {
                                        $dataRow['Referencia'] = trim($row[$mappedIndices['Referencia']] ?? '');
                                    }

                                    // Para Afirme, si el monto es negativo, lo ponemos en 0 (ajuste solicitado)
                                    if (($dataRow['Monto'] ?? 0) < 0) {
                                        $dataRow['Monto'] = 0;
                                    }

                                    // LLAVE COMPUESTA AFIRME (8 campos) para unicidad
                                    $huellaAFIRME = trim($dataRow['Afiliacion'] ?? '') . '|' . 
                                                    trim($rawTarjeta) . '|' . 
                                                    ($dataRow['Fecha_Transaccion'] ?? '') . '|' . 
                                                    ($dataRow['Hora'] ?? '') . '|' . 
                                                    number_format((float)($dataRow['Monto'] ?? 0), 2, '.', '') . '|' . 
                                                    trim($dataRow['Codigo_Autorizacion'] ?? '') . '|' . 
                                                    trim($dataRow['Terminal'] ?? '') . '|' . 
                                                    trim($dataRow['Referencia'] ?? ''); 

                                    // Asignamos la llave compuesta al ID_Externo como se solicitó
                                    $dataRow['ID_Externo'] = $huellaAFIRME;

                                    if (isset($huellas[$huellaAFIRME])) {
                                        $skipped++;
                                        continue;
                                    }

                                    // Parametros para SQL: columnas oficiales + Tarjeta
                                    $params = array_values($dataRow);
                                    $params[] = $rawTarjeta;

                                    $ins->execute($params);
                                    $inserted++;
                                    $huellas[$huellaAFIRME] = true;
                                }
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
            
            // INNER JOIN con Tesoreria_afil para traer SOLO las estaciones que tienen configuración/afiliación
            // RFC se toma de Estaciones (fuente autoritativa), no de Tesoreria_afil (puede variar por entidad)
            $sql = "SELECT DISTINCT
                        T1.Codigo,
                        T1.Nombre,
                        ISNULL(T1.RFC, 'FORANEAS') as RFC
                    FROM Estaciones T1
                    INNER JOIN Tesoreria_afil T2 ON T1.Codigo = T2.estacion_id
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

            // INYECCIÓN MANUAL COLOSIO (Si no viene de BD)
            $foundColosio = false;
            foreach($result as $r) { if($r['Codigo'] == 333) $foundColosio = true; }

            if(!$foundColosio) {
                $result[] = [
                    'Codigo' => 333,
                    'Nombre' => 'COLOSIO',
                    'RFC'    => 'FORANEAS'
                ];
                // Reordenar alfabéticamente
                usort($result, function($a, $b) { return strcmp($a['Nombre'], $b['Nombre']); });
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
                    CAST(i.mto AS DECIMAL(18,2)) AS Total,
                    
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
                foreach (preg_split('/[,\/\-]+/', trim($r['afiliacion'])) as $token) {
                    $token = ltrim(trim($token), '0') ?: trim($token);
                    if ($token === '') continue;
                    $catalogo[] = ['afiliacion' => $token, 'Estacion' => $r['Estacion'], 'RFC' => trim($r['RFC'])];
                }
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

            // Tablas Banorte con afiliación embebida en Referencia/Concepto/Descripcion
            foreach (['Tesoreria_A9475', 'Tesoreria_8876'] as $tablaRef) {
                try {
                    $check = $conn->query("SELECT count(*) FROM information_schema.tables WHERE table_name = '$tablaRef'");
                    if ($check->fetchColumn() == 0) continue;
                    $sqlRef = "SELECT Fecha, Referencia, Descripcion, Depositos FROM $tablaRef WHERE Depositos > 0 AND YEAR(Fecha) = ? AND MONTH(Fecha) = ?";
                    $stmt = $conn->prepare($sqlRef); $stmt->execute([$year, $month]);
                    while($row = $stmt->fetch(PDO::FETCH_ASSOC)){
                        $ref      = trim($row['Referencia']  ?? '');
                        $concepto = '';
                        $desc     = trim($row['Descripcion'] ?? '');
                        if ($ref === '' && $desc === '') continue;
                        $monto = (float)$row['Depositos'];
                        $fecha = ($row['Fecha'] instanceof DateTime) ? $row['Fecha']->format('Y-m-d') : substr((string)$row['Fecha'], 0, 10);
                        foreach ($catalogo as $afilItem) {
                            $afiliacionStr = $afilItem['afiliacion'];
                            if (stripos($ref, $afiliacionStr) !== false || stripos($concepto, $afiliacionStr) !== false || stripos($desc, $afiliacionStr) !== false) {
                                $key = $fecha . '_' . $afiliacionStr;
                                if (!isset($agrupado[$key])) $agrupado[$key] = ['Fecha'=>$fecha,'Afiliacion'=>$afiliacionStr,'Estacion'=>$afilItem['Estacion'],'Total'=>0];
                                $agrupado[$key]['Total'] += $monto;
                                break;
                            }
                        }
                    }
                } catch(Exception $e){}
            }

            // Tablas Banorte dedicadas (todos sus depósitos pertenecen a una afiliación fija)
            $tablasDedicadas = [
                'Tesoreria_FG4113' => '9662848',
            ];
            foreach ($tablasDedicadas as $tablaRef => $afilFija) {
                // Buscar el item del catálogo correspondiente a esta afiliación
                $afilItem = null;
                foreach ($catalogo as $c) {
                    if ($c['afiliacion'] === $afilFija) { $afilItem = $c; break; }
                }
                if (!$afilItem) continue;
                try {
                    $check = $conn->query("SELECT count(*) FROM information_schema.tables WHERE table_name = '$tablaRef'");
                    if ($check->fetchColumn() == 0) continue;
                    $stmt = $conn->prepare("SELECT Fecha, Depositos, Descripcion FROM $tablaRef WHERE Depositos > 0 AND YEAR(Fecha) = ? AND MONTH(Fecha) = ?");
                    $stmt->execute([$year, $month]);
                    while($row = $stmt->fetch(PDO::FETCH_ASSOC)){
                        $desc = trim($row['Descripcion'] ?? '');
                        if (stripos($desc, $afilFija) === false && stripos($desc, 'FORMULA GAS') === false) continue;
                        $monto = (float)$row['Depositos'];
                        $fecha = ($row['Fecha'] instanceof DateTime) ? $row['Fecha']->format('Y-m-d') : substr((string)$row['Fecha'], 0, 10);
                        $key = $fecha . '_' . $afilFija;
                        if (!isset($agrupado[$key])) $agrupado[$key] = ['Fecha'=>$fecha,'Afiliacion'=>$afilFija,'Estacion'=>$afilItem['Estacion'],'Total'=>0];
                        $agrupado[$key]['Total'] += $monto;
                    }
                } catch(Exception $e){}
            }

            $resultado = array_values($agrupado);
            usort($resultado, function($a, $b) { return strcmp($a['Fecha'], $b['Fecha']); });

            echo json_encode(["status" => "success", "data" => $resultado, "catalog" => $catalogo]);

        } catch (PDOException $e) {
            echo json_encode(["status" => "error", "message" => "Error BD: " . $e->getMessage()]);
        }
        exit;
    }

    /**
     * Fuente común para Santander y Afirme en Conciliación V3. La tabla de
     * movimientos no conoce afiliaciones: se preserva la regla histórica de
     * encontrarlas en los textos del movimiento contra Tesoreria_afil.
     */
    private function emitir_tesoreria_movimientos_bancarios(int $entidadId): void {
        ob_clean();
        header('Content-Type: application/json');

        $bancos = [1 => 'SANTANDER', 13 => 'AFIRME'];
        $banco = $bancos[$entidadId] ?? null;
        $year  = (int)($_GET['year'] ?? date('Y'));
        $month = (int)($_GET['month'] ?? date('m'));
        if (!$banco || $month < 1 || $month > 12) {
            echo json_encode(['status' => 'error', 'message' => 'Entidad o periodo inválido']);
            exit;
        }

        try {
            $conn = $this->v3_conn();
            $stmtCatalogo = $conn->prepare(
                "SELECT A.afiliacion, ISNULL(S.Nombre, V.Nombre) AS Estacion, ISNULL(A.rfc, 'FORANEAS') AS RFC
                 FROM Tesoreria_afil A
                 LEFT JOIN Estaciones S ON A.estacion_id = S.Codigo
                 LEFT JOIN Tesoreria_Estaciones_Virtuales V ON A.estacion_id = V.Codigo
                 WHERE A.entidad_id = ? AND LEN(ISNULL(A.afiliacion,'')) > 0
                   AND (S.Nombre IS NOT NULL OR V.Nombre IS NOT NULL)"
            );
            $stmtCatalogo->execute([$entidadId]);
            $catalogo = [];
            while ($r = $stmtCatalogo->fetch(PDO::FETCH_ASSOC)) {
                foreach (preg_split('/[,\/\-]+/', trim($r['afiliacion'])) as $token) {
                    $original = trim($token);
                    $token = ltrim($original, '0') ?: $original;
                    if ($token !== '') $catalogo[] = ['afiliacion' => $token, 'Estacion' => $r['Estacion'], 'RFC' => trim($r['RFC'])];
                }
            }
            usort($catalogo, fn($a, $b) => [ $a['Estacion'], $a['afiliacion'] ] <=> [ $b['Estacion'], $b['afiliacion'] ]);

            $filtro = '';
            if ($banco === 'AFIRME') {
                $filtro = " AND descripcion LIKE '%VENTA%'";
            } else {
                // Antigua hoja 5117: excluir DCC, IVA y bonificaciones; la
                // cuenta conserva el último bloque de dígitos de dicha hoja.
                $filtro = " AND (cuenta <> '65505675117' OR descripcion LIKE '%DEPOSITO VENTAS DEL DIA%' OR descripcion LIKE '%DEPOSITO VTAS%')";
            }
            $stmtMovimientos = $conn->prepare(
                "SELECT id, fecha, abono, cuenta, referencia, concepto, descripcion, descripcion_larga
                 FROM [TG].[dbo].[movimientos_bancarios]
                 WHERE banco = ? AND abono > 0 AND YEAR(fecha) = ? AND MONTH(fecha) = ?$filtro
                 ORDER BY fecha, id"
            );
            $stmtMovimientos->execute([$banco, $year, $month]);

            $resultado = [];
            while ($mov = $stmtMovimientos->fetch(PDO::FETCH_ASSOC)) {
                $texto = implode(' ', [
                    (string)($mov['referencia'] ?? ''), (string)($mov['concepto'] ?? ''),
                    (string)($mov['descripcion'] ?? ''), (string)($mov['descripcion_larga'] ?? ''),
                ]);
                foreach ($catalogo as $afil) {
                    if (stripos($texto, $afil['afiliacion']) === false) continue;
                    $fecha = $mov['fecha'] instanceof DateTime
                        ? $mov['fecha']->format('Y-m-d')
                        : substr((string)$mov['fecha'], 0, 10);
                    $resultado[] = [
                        'Fecha'        => $fecha,
                        'Afiliacion'   => $afil['afiliacion'],
                        'Estacion'     => $afil['Estacion'],
                        'Total'        => (float)$mov['abono'],
                        'MovimientoId' => 'mb_' . (int)$mov['id'],
                        'Descripcion'  => trim((string)($mov['descripcion'] ?: $mov['concepto'] ?: '')),
                    ];
                    break;
                }
            }

            echo json_encode(['status' => 'success', 'data' => $resultado, 'catalog' => $catalogo]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Error BD: ' . $e->getMessage()]);
        }
        exit;
    }

    // =========================================================================
    // 3. TESORERIA SANTANDER
    // =========================================================================
    public function get_tesoreria_santander() {
        $this->emitir_tesoreria_movimientos_bancarios(1);
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
                foreach (preg_split('/[,\/\-]+/', trim($r['afiliacion'])) as $token) {
                    $token = ltrim(trim($token), '0') ?: trim($token);
                    if ($token === '') continue;
                    $catalogo[] = ['afiliacion' => $token, 'Estacion' => $r['Estacion'], 'RFC' => trim($r['RFC'])];
                }
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

            // Fuentes con DescripcionDetallada: 0956, 8520
            foreach (['Tesoreria_0956', 'Tesoreria_8520'] as $tablaDD) {
            try {
                $sql0956 = "SELECT Fecha, DescripcionDetallada, Depositos FROM $tablaDD WHERE DescripcionDetallada IS NOT NULL AND YEAR(Fecha) = ? AND MONTH(Fecha) = ?";
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
            } // fin foreach tablaDD

            // Fuentes por Referencia/Concepto: 8504, 8492, 4638, 4777, 5247, 7291, 7533, 5791, A6115, 4547, 8214, 4669
            foreach (['Tesoreria_8504', 'Tesoreria_8492', 'Tesoreria_4638', 'Tesoreria_4777',
                      'Tesoreria_5247', 'Tesoreria_7291', 'Tesoreria_7533', 'Tesoreria_5791',
                      'Tesoreria_A6115', 'Tesoreria_4547', 'Tesoreria_8214', 'Tesoreria_4669'] as $tablaRef) {
                try {
                    $check = $conn->query("SELECT count(*) FROM information_schema.tables WHERE table_name = '$tablaRef'");
                    if ($check->fetchColumn() == 0) continue;
                    $sqlRef = "SELECT Fecha, Referencia, Concepto, Depositos FROM $tablaRef WHERE Depositos > 0 AND YEAR(Fecha) = ? AND MONTH(Fecha) = ?";
                    $stmt = $conn->prepare($sqlRef); $stmt->execute([$year, $month]);
                    while($row = $stmt->fetch(PDO::FETCH_ASSOC)){
                        $ref      = trim($row['Referencia'] ?? '');
                        $concepto = trim($row['Concepto']   ?? '');
                        if ($ref === '' && $concepto === '') continue;
                        $monto = (float)$row['Depositos'];
                        $fecha = ($row['Fecha'] instanceof DateTime) ? $row['Fecha']->format('Y-m-d') : substr((string)$row['Fecha'], 0, 10);
                        foreach ($catalogo as $afilItem) {
                            if (stripos($ref, $afilItem['afiliacion']) !== false || stripos($concepto, $afilItem['afiliacion']) !== false) {
                                $key = $fecha . '_' . $afilItem['afiliacion'];
                                if (!isset($agrupado[$key])) $agrupado[$key] = ['Fecha'=>$fecha,'Afiliacion'=>$afilItem['afiliacion'],'Estacion'=>$afilItem['Estacion'],'Total'=>0];
                                $agrupado[$key]['Total'] += $monto;
                                break;
                            }
                        }
                    }
                } catch(Exception $e){}
            }

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
        $this->emitir_tesoreria_movimientos_bancarios(13);
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

            $tabla = "";
            if ($eid == 1) $tabla = "banco_getnet";
            elseif ($eid == 3) $tabla = "banco_amex";
            elseif ($eid == 4) $tabla = "banco_banorte";
            elseif ($eid == 5) $tabla = "banco_bbva";
            elseif ($eid == 13) $tabla = "banco_afirme";
            
            if (empty($tabla)) {
                echo json_encode(["status" => "success", "data" => []]);
                exit;
            }

            // SOPORTE MULTI-AFILIACIÓN: Soporta Coma (,) y Diagonal (/)
            $afil_parts = array_map('trim', preg_split('/[,\/]/', $afiliacion));
            $placeholders = implode(',', array_fill(0, count($afil_parts), '?'));

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
                      AND LTRIM(RTRIM(Afiliacion)) IN ($placeholders)
                    ORDER BY Fecha_Transaccion ASC";

            $stmt = $conn->prepare($sql);
            $stmt->execute(array_merge([$year, $month], $afil_parts));
            
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

            $tabla = "";
            if ($eid == 1) $tabla = "banco_getnet";
            elseif ($eid == 3) $tabla = "banco_amex";
            elseif ($eid == 4) $tabla = "banco_banorte";
            elseif ($eid == 5) $tabla = "banco_bbva";
            elseif ($eid == 13) $tabla = "banco_afirme";
            
            if (empty($tabla)) {
                echo json_encode(["status" => "success", "data" => []]);
                exit;
            }

            // SOPORTE MULTI-AFILIACIÓN: Soporta Coma (,) y Diagonal (/)
            $afil_parts = array_map('trim', preg_split('/[,\/]/', $afiliacion));
            $placeholders = implode(',', array_fill(0, count($afil_parts), '?'));

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
                      AND LTRIM(RTRIM(Afiliacion)) IN ($placeholders)
                    ORDER BY Fecha_Deposito ASC";

            $stmt = $conn->prepare($sql);
            $stmt->execute(array_merge([$year, $month], $afil_parts));
            
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

            // NUEVO: Asegurar columnas de banco y afiliación en el Grupo
            $conn->exec("IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('Conciliacion_V2_Grupos') AND name = 'entidad_id') 
                ALTER TABLE Conciliacion_V2_Grupos ADD entidad_id INT");
            $conn->exec("IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('Conciliacion_V2_Grupos') AND name = 'afiliacion') 
                ALTER TABLE Conciliacion_V2_Grupos ADD afiliacion VARCHAR(50)");

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
            $entidad_id  = isset($data['entidad_id']) ? (int)$data['entidad_id'] : 0;
            $afiliacion  = isset($data['afiliacion']) ? trim($data['afiliacion']) : '';
            $fecha_operativa = $data['fecha_operativa'] ?? date('Y-m-d');

            // 1. Crear Grupo
            $sqlGroup = "INSERT INTO Conciliacion_V2_Grupos (total_sistema, total_banco, diferencia, estacion_id, entidad_id, afiliacion, fecha_creacion, fecha_operativa) VALUES (?, ?, ?, ?, ?, ?, GETDATE(), ?)";
            $stmtGroup = $conn->prepare($sqlGroup);
            $stmtGroup->execute([$total_cg, $total_tx, $diferencia, $estacion_id, $entidad_id, $afiliacion, $fecha_operativa]);
            
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
                // SOPORTE MULTI-AFILIACION (Coma, Guion, Diagonal)
                $afil_parts = array_map('trim', preg_split('/[,\/\-]/', $afiliacion));
                $placeholders = implode(',', array_fill(0, count($afil_parts), '?'));
                
                // Construir condiciones LIKE dinamicas para el fallback
                $likeConditions = [];
                $likeParams = [];
                foreach ($afil_parts as $part) {
                    $likeConditions[] = "(D2.concepto LIKE ? OR D2.referencia_externa LIKE ?)";
                    $likeParams[] = "%$part%";
                    $likeParams[] = "%$part%";
                }
                $fallbackSql = implode(" OR ", $likeConditions);

                // Logica Hibrida Multi-Afil: 
                // Buscamos coincidencia exacta en el grupo O coincidencia parcial via detalles
                $sql .= " AND (
                            G.afiliacion IN ($placeholders) 
                            OR G.afiliacion = ?
                            OR (G.afiliacion IS NULL AND EXISTS (
                                SELECT 1 FROM Conciliacion_V2_Detalles D2 
                                WHERE D2.grupo_id = G.id 
                                  AND D2.origen = 'TX' 
                                  AND ($fallbackSql)
                            ))
                        )";
                
                $params = array_merge($params, $afil_parts, [$afiliacion], $likeParams);
            }

            if ($entidad_id > 0) {
                // Solo filtrar por entidad si el registro la tiene definida
                $sql .= " AND (G.entidad_id = ? OR G.entidad_id IS NULL)";
                $params[] = $entidad_id;
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
                // SOPORTE MULTI-AFILIACIÓN
                $afil_parts = array_map('trim', explode('/', $afiliacion));
                $likeConditions = [];
                $likeParams = [];
                foreach ($afil_parts as $part) {
                    $likeConditions[] = "afiliacion_asociada LIKE ?";
                    $likeParams[] = "%$part%";
                }
                $sql .= " AND (" . implode(" OR ", $likeConditions) . ")";
                $params = array_merge($params, $likeParams);
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

            // INYECCIÓN MANUAL COLOSIO (ID 333)
            if ($estacion_id == 333) {
                // Verificar si ya existe para no duplicar (aunque es improbable si no está en BD)
                $existe = false;
                foreach($reglas as $r) { if($r['afiliacion'] == '9274246') $existe = true; }
                
                if(!$existe) {
                    $reglas[] = [
                        'entidad_id'   => 1,
                        'nombre_banco' => 'SANTANDER',
                        'afiliacion'   => '9274246',
                        'descripcion'  => 'Cuenta 9274246 (Manual)',
                        'conceptos_cg' => 'VENTA,DEPOSITO'
                    ];
                }
            }
            
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

      public function actualizar_batch_detalles() {
        ob_clean();
        header('Content-Type: application/json');
        $json = file_get_contents('php://input');
        $data = json_decode($json, true);
        if (!isset($data['actualizaciones']) || !is_array($data['actualizaciones'])) {
            echo json_encode(['status' => 'error', 'message' => 'Faltan datos']);
            exit;
        }
        $server = "192.168.0.6"; $db = "TG"; $user = "cguser"; $pass = "sahei1712";
        try {
            $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $conn->beginTransaction();
            $grupos_afectados = [];
            foreach ($data['actualizaciones'] as $item) {
                $id_detalle = $item['id_detalle'];
                $nuevo_monto = $item['nuevo_monto'];
                $stmtGet = $conn->prepare("SELECT grupo_id FROM Conciliacion_V2_Detalles WHERE id = ?");
                $stmtGet->execute([$id_detalle]);
                $grupo_id = $stmtGet->fetchColumn();
                if ($grupo_id) {
                    $stmtUpdateDetalle = $conn->prepare("UPDATE Conciliacion_V2_Detalles SET monto = ? WHERE id = ?");
                    $stmtUpdateDetalle->execute([$nuevo_monto, $id_detalle]);
                    $grupos_afectados[$grupo_id] = true;
                }
            }
            foreach (array_keys($grupos_afectados) as $gid) {
                $this->recalcular_grupo_interno($conn, $gid);
            }
            $conn->commit();
            echo json_encode(['status' => 'success', 'message' => count($data['actualizaciones']) . ' registros actualizados']);
        } catch (Exception $e) {
            if(isset($conn)) $conn->rollBack();
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }
    // =========================================================================
// DESLIGAR MOVIMIENTO (Y BORRAR GRUPO SI QUEDA VACÍO) V2
// =========================================================================
    public function eliminar_grupo_conciliacion() {
        ob_clean();
        header('Content-Type: application/json');

        $json = file_get_contents('php://input');
        $data = json_decode($json, true);

        if (!isset($data['grupo_id'])) {
            echo json_encode(['status' => 'error', 'message' => 'Falta ID de Grupo']);
            exit;
        }

        $grupo_id = (int)$data['grupo_id'];
        $server = "192.168.0.6"; $db = "TG"; $user = "cguser"; $pass = "sahei1712";

        try {
            $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $conn->beginTransaction();

            // 1. Revertir Tránsitos asociados a los detalles de este grupo
            $stmtRefs = $conn->prepare("SELECT referencia_externa FROM Conciliacion_V2_Detalles WHERE grupo_id = ?");
            $stmtRefs->execute([$grupo_id]);
            $refs = $stmtRefs->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($refs)) {
                $placeholders = implode(',', array_fill(0, count($refs), '?'));
                $sqlRevert = "UPDATE Conciliacion_Transito SET estado = 'PENDIENTE', fecha_marcado = NULL 
                              WHERE referencia_externa IN ($placeholders) AND estado = 'CONCILIADO'";
                $stmtRevert = $conn->prepare($sqlRevert);
                $stmtRevert->execute($refs);
            }

            // 2. Eliminar detalles
            $stmtDelDet = $conn->prepare("DELETE FROM Conciliacion_V2_Detalles WHERE grupo_id = ?");
            $stmtDelDet->execute([$grupo_id]);

            // 3. Eliminar grupo
            $stmtDelGrp = $conn->prepare("DELETE FROM Conciliacion_V2_Grupos WHERE id = ?");
            $stmtDelGrp->execute([$grupo_id]);

            $conn->commit();
            echo json_encode(['status' => 'success', 'message' => 'Grupo eliminado y movimientos liberados.']);

        } catch (Exception $e) {
            if (isset($conn)) $conn->rollBack();
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    public function eliminar_grupos_mes() {
        ob_clean();
        header('Content-Type: application/json');

        $json = file_get_contents('php://input');
        $data = json_decode($json, true);

        if (!isset($data['estacion_id'], $data['afiliacion'], $data['year'], $data['month'])) {
            echo json_encode(['status' => 'error', 'message' => 'Faltan parámetros']);
            exit;
        }

        $sid   = (int)$data['estacion_id'];
        $afil  = trim($data['afiliacion']);
        $year  = (int)$data['year'];
        $month = (int)$data['month'];

        $server = "192.168.0.6"; $db = "TG"; $user = "cguser"; $pass = "sahei1712";

        try {
            $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $conn->beginTransaction();

            // 1. Encontrar todos los IDs de grupo para ese mes, estación y afiliación
            // Nota: El mes/año se filtra por la fecha_operativa en Conciliacion_V2_Grupos
            $sqlFind = "SELECT id FROM Conciliacion_V2_Grupos 
                        WHERE estacion_id = ? AND afiliacion = ? 
                        AND YEAR(fecha_operativa) = ? AND MONTH(fecha_operativa) = ?";
            $stmtFind = $conn->prepare($sqlFind);
            $stmtFind->execute([$sid, $afil, $year, $month]);
            $groupIds = $stmtFind->fetchAll(PDO::FETCH_COLUMN);

            if (empty($groupIds)) {
                $conn->rollBack();
                echo json_encode(['status' => 'success', 'message' => 'No se encontraron grupos para eliminar.', 'grupos_eliminados' => 0]);
                exit;
            }

            // BATCHING para evitar el límite de 2100 parámetros de SQL Server
            $batchSize = 1000;
            $groupIdChunks = array_chunk($groupIds, $batchSize);

            foreach ($groupIdChunks as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));

                // 2. Revertir Tránsitos asociados a los detalles de estos grupos
                $sqlRefs = "SELECT referencia_externa FROM Conciliacion_V2_Detalles WHERE grupo_id IN ($placeholders)";
                $stmtRefs = $conn->prepare($sqlRefs);
                $stmtRefs->execute($chunk);
                $refs = $stmtRefs->fetchAll(PDO::FETCH_COLUMN);

                if (!empty($refs)) {
                    $refChunks = array_chunk($refs, $batchSize);
                    foreach ($refChunks as $refChunk) {
                        $refPlaceholders = implode(',', array_fill(0, count($refChunk), '?'));
                        $sqlRevert = "UPDATE Conciliacion_Transito SET estado = 'PENDIENTE', fecha_marcado = NULL 
                                      WHERE referencia_externa IN ($refPlaceholders) AND estado = 'CONCILIADO'";
                        $stmtRevert = $conn->prepare($sqlRevert);
                        $stmtRevert->execute($refChunk);
                    }
                }

                // 3. Eliminar detalles de los grupos del chunk
                $sqlDelDet = "DELETE FROM Conciliacion_V2_Detalles WHERE grupo_id IN ($placeholders)";
                $stmtDelDet = $conn->prepare($sqlDelDet);
                $stmtDelDet->execute($chunk);

                // 4. Eliminar los grupos del chunk
                $sqlDelGrp = "DELETE FROM Conciliacion_V2_Grupos WHERE id IN ($placeholders)";
                $stmtDelGrp = $conn->prepare($sqlDelGrp);
                $stmtDelGrp->execute($chunk);
            }

            $conn->commit();
            echo json_encode([
                'status' => 'success', 
                'message' => 'Conciliaciones del mes eliminadas.', 
                'grupos_eliminados' => count($groupIds)
            ]);

        } catch (Exception $e) {
            if (isset($conn)) $conn->rollBack();
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    public function desligar_movimiento() {    ob_clean();
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
        $afiliacion = trim($_GET['afiliacion'] ?? '');

        $server = "192.168.0.6"; $db = "TG"; $user = "cguser"; $pass = "sahei1712"; 

        try {
            $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // 1. FILTRO DE GRUPOS (Lógica Híbrida similar a get_conciliaciones_hechas)
            $filterSql = " WHERE YEAR(G.fecha_operativa) = ? AND MONTH(G.fecha_operativa) = ? ";
            $params = [$year, $month];

            if ($estacion_id > 0) {
                $filterSql .= " AND G.estacion_id = ? ";
                $params[] = $estacion_id;
            }

            if ($entidad_id > 0) {
                $filterSql .= " AND (G.entidad_id = ? OR G.entidad_id IS NULL) ";
                $params[] = $entidad_id;
            }

            if (!empty($afiliacion)) {
                // SOPORTE MULTI-AFILIACION (Coma, Guion, Diagonal)
                $afil_parts = array_map('trim', preg_split('/[,\/\-]/', $afiliacion));
                $placeholders = implode(',', array_fill(0, count($afil_parts), '?'));
                
                // Construir condiciones LIKE dinamicas para el fallback
                $likeConditions = [];
                $likeParams = [];
                foreach ($afil_parts as $part) {
                    $likeConditions[] = "(D_Check.concepto LIKE ? OR D_Check.referencia_externa LIKE ?)";
                    $likeParams[] = "%$part%";
                    $likeParams[] = "%$part%";
                }
                $fallbackSql = implode(" OR ", $likeConditions);

                $filterSql .= " AND (
                                    G.afiliacion IN ($placeholders) 
                                    OR G.afiliacion = ?
                                    OR (G.afiliacion IS NULL AND EXISTS (
                                        SELECT 1 FROM Conciliacion_V2_Detalles D_Check 
                                        WHERE D_Check.grupo_id = G.id 
                                          AND D_Check.origen = 'TX' 
                                          AND ($fallbackSql)
                                    ))
                                ) ";
                $params = array_merge($params, $afil_parts, [$afiliacion], $likeParams);
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
                            " . $filterSql;
            
            $sqlEstacion .= " GROUP BY E.Nombre HAVING SUM(G.diferencia) != 0 ORDER BY E.Nombre";
            
            $stmtEstacion = $conn->prepare($sqlEstacion);
            $stmtEstacion->execute($params);
            $porEstacion = $stmtEstacion->fetchAll(PDO::FETCH_ASSOC);

            // ==========================================================
            // AGRUPAMIENTOS ADICIONALES PARA PESTAÑAS (AUDITORÍA)
            // ==========================================================
            $agrupados = [];
            
            // A. Por Banco / Afiliación (Simplificado con nuevas columnas)
            $sqlBank = "SELECT TE.Nombre as Banco, ISNULL(G.afiliacion, 'Sin Afil.') as Afiliacion, 
                               SUM(G.total_sistema) as Sistema, SUM(G.total_banco) as BancoTotal, SUM(G.diferencia) as Diferencia
                        FROM Conciliacion_V2_Grupos G
                        LEFT JOIN Tesoreria_Entidad TE ON G.entidad_id = TE.id
                        " . $filterSql . "
                        GROUP BY TE.Nombre, G.afiliacion
                        HAVING SUM(G.diferencia) <> 0
                        ORDER BY TE.Nombre, G.afiliacion";
            $stmtBank = $conn->prepare($sqlBank);
            $stmtBank->execute($params);
            $agrupados['bancos'] = $stmtBank->fetchAll(PDO::FETCH_ASSOC);

            // B. Por Estación / Banco
            $sqlEstBank = "SELECT E.Nombre as Estacion, TE.Nombre as Banco, 
                                  SUM(G.total_sistema) as Sistema, SUM(G.total_banco) as BancoTotal, SUM(G.diferencia) as Diferencia
                           FROM Conciliacion_V2_Grupos G
                           LEFT JOIN Estaciones E ON G.estacion_id = E.Codigo
                           LEFT JOIN Tesoreria_Entidad TE ON G.entidad_id = TE.id
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
                    // MODO ESTACIÓN: Agrupado por el banco/afil predominante de cada grupo (Ahora directo en G)
                    $sql = "SELECT 
                                TE.Nombre + ' (' + ISNULL(G.afiliacion, 'Sin Afil.') + ')' as label,
                                SUM(G.total_sistema) as sistema,
                                SUM(G.total_banco) as banco,
                                SUM(G.diferencia) as diferencia
                            FROM Conciliacion_V2_Grupos G
                            LEFT JOIN Tesoreria_Entidad TE ON G.entidad_id = TE.id
                            WHERE YEAR(G.fecha_operativa) = ? AND MONTH(G.fecha_operativa) = ? AND G.estacion_id = ?
                            GROUP BY TE.Nombre, G.afiliacion
                            HAVING SUM(G.diferencia) <> 0
                            ORDER BY label";
                    $params[] = (int)$value;
                    break;

                case 'bank':
                    // MODO BANCO: Suma de grupos que contienen transacciones del banco seleccionado
                    $sql = "SELECT 
                                ISNULL(G.afiliacion, 'Sin Afil.') + ' - ' + E.Nombre as label,
                                SUM(G.total_sistema) as sistema,
                                SUM(G.total_banco) as banco,
                                SUM(G.diferencia) as diferencia
                            FROM Conciliacion_V2_Grupos G
                            INNER JOIN Estaciones E ON G.estacion_id = E.Codigo
                            WHERE YEAR(G.fecha_operativa) = ? AND MONTH(G.fecha_operativa) = ? AND G.entidad_id = ?
                            GROUP BY G.afiliacion, E.Nombre
                            HAVING SUM(G.diferencia) <> 0
                            ORDER BY label";
                    $params[] = (int)$value;
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
        $estacion_id = (int)($_GET['estacion_id'] ?? 0);
        $entidad_id = (int)($_GET['entidad_id'] ?? 0);
        $afiliacion = trim($_GET['afiliacion'] ?? '');

        $server = "192.168.0.6"; $db = "TG"; $user = "cguser"; $pass = "sahei1712"; 

        try {
            $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $sql = "SELECT 
                        G.id as GrupoID,
                        E.Nombre as Estacion,
                        TE.Nombre as Banco,
                        ISNULL(G.afiliacion, 'Sin Afil.') as Afiliacion,
                        FORMAT(G.fecha_operativa, 'yyyy-MM-dd') as FechaOperativa,
                        G.total_sistema as ControlGas,
                        G.total_banco as BancoTX,
                        G.diferencia as Diferencia
                    FROM Conciliacion_V2_Grupos G
                    LEFT JOIN Estaciones E ON G.estacion_id = E.Codigo
                    LEFT JOIN Tesoreria_Entidad TE ON G.entidad_id = TE.id
                    WHERE YEAR(G.fecha_operativa) = ? AND MONTH(G.fecha_operativa) = ? ";
            
            $params = [$year, $month];
            if ($estacion_id > 0) {
                $sql .= " AND G.estacion_id = ? ";
                $params[] = $estacion_id;
            }
            if ($entidad_id > 0) {
                $sql .= " AND G.entidad_id = ? ";
                $params[] = $entidad_id;
            }
            if (!empty($afiliacion)) {
                // SOPORTE MULTI-AFILIACION (Coma, Guion, Diagonal)
                $afil_parts = array_map('trim', preg_split('/[,\/\-]/', $afiliacion));
                $placeholders = implode(',', array_fill(0, count($afil_parts), '?'));
                
                $sql .= " AND (G.afiliacion IN ($placeholders) OR G.afiliacion = ?) ";
                $params = array_merge($params, $afil_parts, [$afiliacion]);
            }

            $sql .= " ORDER BY G.fecha_operativa DESC, G.id DESC";

            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle("Conciliacion $year-$month");

            $headers = ['Grupo ID', 'Estación', 'Banco', 'Afiliación', 'Fecha Operativa', 'ControlGas', 'Bancos (TX)', 'Diferencia'];
            $sheet->fromArray($headers, NULL, 'A1');
            $sheet->getStyle('A1:H1')->getFont()->setBold(true);

            $rowIdx = 2;
            foreach ($rows as $r) {
                $sheet->setCellValue('A'.$rowIdx, $r['GrupoID']);
                $sheet->setCellValue('B'.$rowIdx, $r['Estacion']);
                $sheet->setCellValue('C'.$rowIdx, $r['Banco']);
                $sheet->setCellValue('D'.$rowIdx, $r['Afiliacion']);
                $sheet->setCellValue('E'.$rowIdx, $r['FechaOperativa']);
                $sheet->setCellValue('F'.$rowIdx, $r['ControlGas']);
                $sheet->setCellValue('G'.$rowIdx, $r['BancoTX']);
                $sheet->setCellValue('H'.$rowIdx, $r['Diferencia']);
                if (abs($r['Diferencia']) > 0.01) {
                    $sheet->getStyle('H'.$rowIdx)->getFont()->getColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_RED);
                }
                $rowIdx++;
            }

            foreach (range('A', 'H') as $col) { $sheet->getColumnDimension($col)->setAutoSize(true); }

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

    public function export_detalle_diferencias() {
        ob_clean();
        
        $year = $_GET['year'] ?? date('Y');
        $month = $_GET['month'] ?? date('m');
        $rs_label = $_GET['rs'] ?? 'DIAZ GAS'; 

        $server = "192.168.0.6"; $db = "TG"; $user = "cguser"; $pass = "sahei1712"; 

        try {
            $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $rfc_filter = "";
            if ($rs_label === 'DIAZ GAS') {
                $rfc_filter = "AND E.RFC = 'DGA930823KD3'";
            } else if ($rs_label === 'GASOMEX') {
                $rfc_filter = "AND E.RFC = 'DGM880621FU5'";
            } else {
                $rfc_filter = "AND (E.RFC NOT IN ('DGA930823KD3', 'DGM880621FU5') OR E.RFC IS NULL)";
            }

            // QUERY DETALLADA: Solo grupos con diferencia > 0.01
            $sql = "
                SELECT 
                    E.Nombre as Estacion,
                    FORMAT(G.fecha_operativa, 'yyyy-MM-dd') as Fecha,
                    TE.Nombre as Banco,
                    ISNULL(G.afiliacion, 'N/A') as Afiliacion,
                    G.total_sistema as ControlGas,
                    G.total_banco as BancoTX,
                    G.diferencia as Diferencia
                FROM Conciliacion_V2_Grupos G
                INNER JOIN Estaciones E ON G.estacion_id = E.Codigo
                LEFT JOIN Tesoreria_Entidad TE ON G.entidad_id = TE.id
                WHERE YEAR(G.fecha_operativa) = ? 
                  AND MONTH(G.fecha_operativa) = ?
                  AND ABS(G.diferencia) > 0.009
                  $rfc_filter
                ORDER BY E.Nombre ASC, G.fecha_operativa ASC";

            $stmt = $conn->prepare($sql);
            $stmt->execute([$year, $month]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle("Detalle Diferencias $month-$year");

            // Estilos
            $headerStyle = [
                'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
                'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFC0392B']],
                'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER]
            ];

            $headers = ['ESTACIÓN', 'FECHA OPERATIVA', 'BANCO', 'AFILIACIÓN', 'CONTROLGAS ($)', 'BANCOS TX ($)', 'DIFERENCIA ($)'];
            $sheet->fromArray($headers, NULL, 'A1');
            $sheet->getStyle('A1:G1')->applyFromArray($headerStyle);

            $rowIdx = 2;
            $totalDif = 0;
            foreach ($rows as $r) {
                $sheet->setCellValue('A'.$rowIdx, $r['Estacion']);
                $sheet->setCellValue('B'.$rowIdx, $r['Fecha']);
                $sheet->setCellValue('C'.$rowIdx, $r['Banco']);
                $sheet->setCellValue('D'.$rowIdx, $r['Afiliacion']);
                $sheet->setCellValue('E'.$rowIdx, $r['ControlGas']);
                $sheet->setCellValue('F'.$rowIdx, $r['BancoTX']);
                $sheet->setCellValue('G'.$rowIdx, $r['Diferencia']);
                
                // Formato numérico
                $sheet->getStyle('E'.$rowIdx.':G'.$rowIdx)->getNumberFormat()->setFormatCode('#,##0.00');
                
                // Color rojo si es diferencia negativa, negrita siempre
                $sheet->getStyle('G'.$rowIdx)->getFont()->setBold(true);
                if ($r['Diferencia'] < -0.01) {
                    $sheet->getStyle('G'.$rowIdx)->getFont()->getColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_RED);
                }

                $totalDif += $r['Diferencia'];
                $rowIdx++;
            }

            // Pie de página con total
            $sheet->setCellValue('F'.$rowIdx, 'TOTAL DIFERENCIAS:');
            $sheet->setCellValue('G'.$rowIdx, $totalDif);
            $sheet->getStyle('F'.$rowIdx.':G'.$rowIdx)->getFont()->setBold(true);
            $sheet->getStyle('G'.$rowIdx)->getNumberFormat()->setFormatCode('#,##0.00');

            foreach (range('A', 'G') as $col) { $sheet->getColumnDimension($col)->setAutoSize(true); }

            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment;filename="Detalle_Diferencias_'.$rs_label.'_'.$year.'_'.$month.'.xlsx"');
            header('Cache-Control: max-age=0');

            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save('php://output');

        } catch (Exception $e) {
            echo "Error: " . $e->getMessage();
        }
        exit;
    }

    public function export_resumen_general() {
        ob_clean();
        
        $year = $_GET['year'] ?? date('Y');
        $month = $_GET['month'] ?? date('m');
        $rs_label = $_GET['rs'] ?? 'DIAZ GAS'; 

        $server = "192.168.0.6"; $db = "TG"; $user = "cguser"; $pass = "sahei1712"; 

        try {
            $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $rfc_filter = "";
            $rfc_filter_afil = "";
            if ($rs_label === 'DIAZ GAS') {
                $rfc_filter = "AND E.RFC = 'DGA930823KD3'";
                $rfc_filter_afil = "AND A.rfc = 'DGA930823KD3'";
            } else if ($rs_label === 'GASOMEX') {
                $rfc_filter = "AND E.RFC = 'DGM880621FU5'";
                $rfc_filter_afil = "AND A.rfc = 'DGM880621FU5'";
            } else {
                $rfc_filter = "AND (E.RFC NOT IN ('DGA930823KD3', 'DGM880621FU5') OR E.RFC IS NULL)";
                $rfc_filter_afil = "AND (A.rfc NOT IN ('DGA930823KD3', 'DGM880621FU5') OR A.rfc IS NULL)";
            }

            // Query Completa: Grupos Reconciliados + Afiliaciones Pendientes (Pool Maestro)
            $sql = "
                -- Parte 1: Grupos ya conciliados (incluye multi-afiliación como una sola fila)
                SELECT 
                    TE.Nombre as Banco,
                    G.afiliacion as Afiliacion,
                    E.Nombre as Estacion,
                    SUM(G.total_sistema) as Sistema,
                    SUM(G.total_banco) as Bancos,
                    SUM(G.diferencia) as Diferencia
                FROM Conciliacion_V2_Grupos G
                INNER JOIN Estaciones E ON G.estacion_id = E.Codigo
                INNER JOIN Tesoreria_Entidad TE ON G.entidad_id = TE.id
                WHERE YEAR(G.fecha_operativa) = ? AND MONTH(G.fecha_operativa) = ?
                  AND G.entidad_id IN (1, 3, 4, 13)
                  $rfc_filter
                GROUP BY TE.Nombre, G.afiliacion, E.Nombre

                UNION ALL

                -- Parte 2: Afiliaciones existentes en el catálogo que NO tienen conciliaciones este mes
                SELECT 
                    TE.Nombre as Banco,
                    A.afiliacion as Afiliacion,
                    E.Nombre as Estacion,
                    0 as Sistema,
                    0 as Bancos,
                    0 as Diferencia
                FROM Tesoreria_afil A
                INNER JOIN Estaciones E ON A.estacion_id = E.Codigo
                INNER JOIN Tesoreria_Entidad TE ON A.entidad_id = TE.id
                WHERE A.entidad_id IN (1, 3, 4, 13)
                  $rfc_filter_afil
                  AND NOT EXISTS (
                      SELECT 1 FROM Conciliacion_V2_Grupos G
                      WHERE G.estacion_id = A.estacion_id
                        AND G.entidad_id = A.entidad_id
                        AND (G.afiliacion = A.afiliacion OR G.afiliacion LIKE '%' + A.afiliacion + '%')
                        AND YEAR(G.fecha_operativa) = ? AND MONTH(G.fecha_operativa) = ?
                  )
                ORDER BY Banco, Estacion";

            $stmt = $conn->prepare($sql);
            // Parámetros: Parte 1 (Year, Month), Parte 2 (Year, Month)
            $stmt->execute([$year, $month, $year, $month]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Agrupar por Banco
            $dataByBank = [];
            foreach ($rows as $r) {
                $dataByBank[$r['Banco']][] = $r;
            }

            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle("Resumen General $rs_label");

            // Título superior
            $sheet->setCellValue('A1', "RESUMEN GENERAL DE CONCILIACIÓN - $rs_label");
            $sheet->mergeCells('A1:E1');
            $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
            $sheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

            $sheet->setCellValue('A2', "Periodo: " . $this->getMonthNameEs((int)$month) . " $year");
            $sheet->mergeCells('A2:E2');
            $sheet->getStyle('A2')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

            $rowIdx = 4;
            
            // Estilos base
            $headerStyle = [
                'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF3485A8']],
                'font' => ['bold' => true, 'color' => ['argb' => \PhpOffice\PhpSpreadsheet\Style\Color::COLOR_WHITE]],
                'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['argb' => 'FFDEE2E6']]]
            ];
            
            $totalRowStyle = [
                'font' => ['bold' => true],
                'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE9ECEF']],
                'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['argb' => 'FFDEE2E6']]]
            ];

            foreach ($dataByBank as $bankName => $bankRows) {
                // Header del Banco
                $sheet->setCellValue('A' . $rowIdx, "INSTITUCIÓN: " . $bankName);
                $sheet->mergeCells('A'.$rowIdx.':E'.$rowIdx);
                $sheet->getStyle('A'.$rowIdx)->getFont()->setBold(true)->setSize(12);
                $rowIdx++;

                // Headers de tabla
                $headers = ['AFILIACIÓN', 'ESTACIÓN', 'SISTEMA (CG)', 'BANCOS (TX)', 'DIFERENCIA'];
                $sheet->fromArray($headers, NULL, 'A' . $rowIdx);
                $sheet->getStyle('A'.$rowIdx.':E'.$rowIdx)->applyFromArray($headerStyle);
                $rowIdx++;

                $bankStartRow = $rowIdx;
                $sumSis = 0; $sumBan = 0; $sumDif = 0;

                foreach ($bankRows as $r) {
                    $sheet->setCellValue('A'.$rowIdx, $r['Afiliacion']);
                    $sheet->setCellValue('B'.$rowIdx, $r['Estacion']);
                    $sheet->setCellValue('C'.$rowIdx, $r['Sistema']);
                    $sheet->setCellValue('D'.$rowIdx, $r['Bancos']);
                    $sheet->setCellValue('E'.$rowIdx, $r['Diferencia']);
                    
                    $sheet->getStyle('C'.$rowIdx.':E'.$rowIdx)->getNumberFormat()->setFormatCode('$#,##0.00');
                    if (abs($r['Diferencia']) > 0.01) {
                        $sheet->getStyle('E'.$rowIdx)->getFont()->getColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_RED);
                    }

                    $sumSis += $r['Sistema'];
                    $sumBan += $r['Bancos'];
                    $sumDif += $r['Diferencia'];
                    $rowIdx++;
                }

                // Totales por Banco
                $sheet->setCellValue('A'.$rowIdx, 'SUBTOTAL ' . $bankName);
                $sheet->mergeCells('A'.$rowIdx.':B'.$rowIdx);
                $sheet->setCellValue('C'.$rowIdx, $sumSis);
                $sheet->setCellValue('D'.$rowIdx, $sumBan);
                $sheet->setCellValue('E'.$rowIdx, $sumDif);
                
                $sheet->getStyle('A'.$rowIdx.':E'.$rowIdx)->applyFromArray($totalRowStyle);
                $sheet->getStyle('C'.$rowIdx.':E'.$rowIdx)->getNumberFormat()->setFormatCode('$#,##0.00');
                if (abs($sumDif) > 0.01) {
                    $sheet->getStyle('E'.$rowIdx)->getFont()->getColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_RED);
                }

                $rowIdx += 2; // Espacio entre tablas
            }

            foreach (range('A', 'E') as $col) { $sheet->getColumnDimension($col)->setAutoSize(true); }

            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment;filename="Resumen_General_'.str_replace(' ','_',$rs_label).'_'.$year.'_'.$month.'.xlsx"');
            header('Cache-Control: max-age=0');

            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save('php://output');

        } catch (Exception $e) {
            echo "Error generando Excel: " . $e->getMessage();
        }
        exit;
    }

    // =========================================================================
    // ▓▓▓  C O N C I L I A C I Ó N   V 3  —  CG  vs  T E S O R E R Í A  ▓▓▓
    // =========================================================================
    // Paradigma nuevo: CG (ControlGas) se concilia contra depósitos reales
    // en Tesorería (estados de cuenta bancarios). Las transacciones de terminal
    // (banco_getnet / banco_banorte) son informativas, no el target.
    //
    // Tablas: Conciliacion_V3_Grupos, Conciliacion_V3_Detalles,
    //         Conciliacion_V3_Transito, Conciliacion_V3_CierreMes
    // Vista: Tesoreria_V3_Unificada para fuentes históricas; Santander y
    // Afirme en test_v3 se leen directamente de movimientos_bancarios.
    // =========================================================================

    // ── VISTAS ────────────────────────────────────────────────────────────────

    public function test_v3(): void {
        echo $this->twig->render($this->route . 'test_v3.html');
    }

    public function summary_v3(): void {
        echo $this->twig->render($this->route . 'summary_v3.html');
    }

    // ── DASHBOARD V3 — GET /income/get_dashboard_v3 ───────────────────────────
    public function get_dashboard_v3(): void {
        ob_clean();
        header('Content-Type: application/json');

        $year        = (int)($_GET['year']         ?? 0);
        $month       = (int)($_GET['month']        ?? 0);
        $banco       = trim($_GET['banco']          ?? '');
        $rs          = trim($_GET['razon_social']   ?? '');
        $estacion_id = (int)($_GET['estacion_id']   ?? 0);
        $afiliacion  = trim($_GET['afiliacion']     ?? '');

        $rsCASE = "CASE WHEN E.RFC='DGA930823KD3' THEN 'DIAZ GAS'
                        WHEN E.RFC='DGM880621FU5' THEN 'GASOMEX'
                        ELSE 'FORANEAS' END";

        try {
            $conn = $this->v3_conn();

            $where  = "WHERE C.estado = 'CERRADO'";
            $params = [];

            if ($year > 0 && $month > 0) {
                $where .= " AND C.mes = ?";
                $params[] = sprintf("%04d-%02d", $year, $month);
            } elseif ($year > 0) {
                $where .= " AND C.mes LIKE ?";
                $params[] = "$year-%";
            }

            if ($banco !== '')    { $where .= " AND TE.Nombre = ?";              $params[] = $banco;       }
            if ($estacion_id > 0) { $where .= " AND C.estacion_id = ?";         $params[] = $estacion_id; }
            if ($afiliacion !== ''){ $where .= " AND C.afiliacion = ?";         $params[] = $afiliacion;  }
            if ($rs === 'DIAZ GAS')  { $where .= " AND E.RFC = 'DGA930823KD3'"; }
            elseif ($rs === 'GASOMEX')  { $where .= " AND E.RFC = 'DGM880621FU5'"; }
            elseif ($rs === 'FORANEAS') { $where .= " AND (E.RFC NOT IN ('DGA930823KD3','DGM880621FU5') OR E.RFC IS NULL)"; }

            $from = "FROM Conciliacion_V3_CierreMes C
                     LEFT JOIN Estaciones E ON E.Codigo = C.estacion_id
                     LEFT JOIN Tesoreria_Entidad TE ON TE.id = C.entidad_id
                     $where";

            // KPIs
            $r = $conn->prepare("SELECT ISNULL(SUM(C.total_cg),0) AS total_cg,
                    ISNULL(SUM(C.total_depositado),0) AS total_depositado,
                    ISNULL(SUM(C.total_transito),0) AS total_transito,
                    ISNULL(SUM(C.total_diferencias),0) AS total_diferencias,
                    COUNT(*) AS n_cierres $from");
            $r->execute($params); $kpis = $r->fetch(PDO::FETCH_ASSOC);

            // Tabla de cierres (resumen)
            $r = $conn->prepare("SELECT C.mes,
                    ISNULL(TE.Nombre,'Banco '+CAST(C.entidad_id AS VARCHAR)) AS banco,
                    C.afiliacion,
                    ISNULL(E.Nombre,'Est.'+CAST(C.estacion_id AS VARCHAR)) AS estacion,
                    $rsCASE AS razon_social,
                    C.total_cg, C.total_depositado, C.total_transito,
                    ISNULL(C.total_diferencias,0) AS total_diferencias,
                    ISNULL(C.items_pendientes,0) AS items_pendientes,
                    ISNULL(C.nota_cierre,'') AS nota_cierre,
                    CONVERT(VARCHAR(10),C.fecha_cierre,120) AS fecha_cierre
                $from ORDER BY C.mes DESC, banco, C.afiliacion");
            $r->execute($params); $meses = $r->fetchAll(PDO::FETCH_ASSOC);

            // Trend (CG vs Depositado vs Diferencias por mes, para gráficas)
            if ($year > 0 && $month > 0) {
                // Tendencia DIARIA si hay un mes seleccionado
                $mesBusqueda = sprintf("%04d-%02d", $year, $month);
                $whereG = "WHERE G.estado = 'CERRADO' AND G.mes_cierre = ?";
                $paramsG = [$mesBusqueda];
                
                if ($banco !== '')    { $whereG .= " AND TE.Nombre = ?";      $paramsG[] = $banco;       }
                if ($estacion_id > 0) { $whereG .= " AND G.estacion_id = ?"; $paramsG[] = $estacion_id; }
                if ($afiliacion !== ''){ $whereG .= " AND G.afiliacion = ?";  $paramsG[] = $afiliacion;  }
                if ($rs === 'DIAZ GAS')  { $whereG .= " AND E.RFC = 'DGA930823KD3'"; }
                elseif ($rs === 'GASOMEX')  { $whereG .= " AND E.RFC = 'DGM880621FU5'"; }
                elseif ($rs === 'FORANEAS') { $whereG .= " AND (E.RFC NOT IN ('DGA930823KD3','DGM880621FU5') OR E.RFC IS NULL)"; }

                $rTrend = $conn->prepare("SELECT G.fecha_operativa AS mes,
                        ISNULL(SUM(G.total_sistema),0) AS cg,
                        ISNULL(SUM(G.total_tesoreria),0) AS depositado,
                        ISNULL(SUM(G.diferencia),0) AS diferencias
                    FROM Conciliacion_V3_Grupos G
                    LEFT JOIN Estaciones E ON E.Codigo = G.estacion_id
                    LEFT JOIN Tesoreria_Entidad TE ON TE.id = G.entidad_id
                    $whereG GROUP BY G.fecha_operativa ORDER BY G.fecha_operativa ASC");
                $rTrend->execute($paramsG); $trend = $rTrend->fetchAll(PDO::FETCH_ASSOC);
            } else {
                // Tendencia MENSUAL si no hay mes o solo hay año
                $rTrend = $conn->prepare("SELECT C.mes,
                        ISNULL(SUM(C.total_cg),0) AS cg,
                        ISNULL(SUM(C.total_depositado),0) AS depositado,
                        ISNULL(SUM(C.total_diferencias),0) AS diferencias
                    $from GROUP BY C.mes ORDER BY C.mes ASC");
                $rTrend->execute($params); $trend = $rTrend->fetchAll(PDO::FETCH_ASSOC);
            }

            // Por razón social
            $r = $conn->prepare("SELECT $rsCASE AS razon_social,
                    ISNULL(SUM(C.total_cg),0) AS total_cg,
                    ISNULL(SUM(C.total_depositado),0) AS total_depositado,
                    ISNULL(SUM(C.total_transito),0) AS total_transito,
                    ISNULL(SUM(C.total_diferencias),0) AS total_diferencias,
                    COUNT(*) AS n_cierres
                $from GROUP BY $rsCASE ORDER BY razon_social");
            $r->execute($params); $por_rs = $r->fetchAll(PDO::FETCH_ASSOC);

            // ── Resumen por combinación (catálogo completo LEFT JOIN cierres) ──────
            // Las condiciones de fecha van en el ON del LEFT JOIN (no en WHERE)
            // para que aparezcan todas las combinaciones aunque no tengan cierres.
            $joinCond  = "C.estacion_id = TA.estacion_id AND C.entidad_id = TA.entidad_id
                          AND C.afiliacion = TA.afiliacion AND C.estado = 'CERRADO'";
            $joinParams = [];
            if ($year > 0 && $month > 0) {
                $joinCond .= " AND C.mes = ?";
                $joinParams[] = sprintf("%04d-%02d", $year, $month);
            } elseif ($year > 0) {
                $joinCond .= " AND C.mes LIKE ?";
                $joinParams[] = "$year-%";
            }

            $catWhere  = "WHERE LEN(ISNULL(TA.afiliacion,'')) > 0";
            $catParams = [];
            if ($estacion_id > 0) { $catWhere .= " AND TA.estacion_id = ?"; $catParams[] = $estacion_id; }
            if ($banco !== '')    { $catWhere .= " AND TE.Nombre = ?";      $catParams[] = $banco;        }
            if ($rs === 'DIAZ GAS')    { $catWhere .= " AND ISNULL(E.RFC,'') = 'DGA930823KD3'"; }
            elseif ($rs === 'GASOMEX') { $catWhere .= " AND ISNULL(E.RFC,'') = 'DGM880621FU5'"; }
            elseif ($rs === 'FORANEAS'){ $catWhere .= " AND ISNULL(E.RFC,'') NOT IN ('DGA930823KD3','DGM880621FU5')"; }

            $r = $conn->prepare("
                SELECT
                    ISNULL(E.Nombre,'Est.'+CAST(TA.estacion_id AS VARCHAR)) AS estacion,
                    ISNULL(TE.Nombre,'Banco '+CAST(TA.entidad_id AS VARCHAR)) AS banco,
                    TA.afiliacion,
                    CASE WHEN ISNULL(E.RFC,'') = 'DGA930823KD3' THEN 'DIAZ GAS'
                         WHEN ISNULL(E.RFC,'') = 'DGM880621FU5' THEN 'GASOMEX'
                         ELSE 'FORANEAS' END AS razon_social,
                    ISNULL(SUM(C.total_cg),0)            AS total_cg,
                    ISNULL(SUM(C.total_depositado),0)     AS total_depositado,
                    ISNULL(SUM(C.total_transito),0)       AS total_transito,
                    ISNULL(SUM(ISNULL(C.total_diferencias,0)),0) AS total_diferencias,
                    COUNT(C.id)                           AS n_cierres
                FROM Tesoreria_afil TA
                LEFT JOIN Estaciones E        ON E.Codigo  = TA.estacion_id
                LEFT JOIN Tesoreria_Entidad TE ON TE.id   = TA.entidad_id
                LEFT JOIN Conciliacion_V3_CierreMes C ON $joinCond
                $catWhere
                GROUP BY TA.estacion_id, TA.entidad_id, TA.afiliacion, E.Nombre, E.RFC, TE.Nombre
                ORDER BY estacion, banco, TA.afiliacion
            ");
            $r->execute(array_merge($joinParams, $catParams));
            $por_combinacion = $r->fetchAll(PDO::FETCH_ASSOC);

            foreach ($por_combinacion as &$row) {
                $row['total_cg']          = (float)$row['total_cg'];
                $row['total_depositado']  = (float)$row['total_depositado'];
                $row['total_transito']    = (float)$row['total_transito'];
                $row['total_diferencias'] = (float)$row['total_diferencias'];
                $row['n_cierres']         = (int)$row['n_cierres'];
            }

            // Floats
            foreach (['total_cg','total_depositado','total_transito','total_diferencias'] as $f) {
                $kpis[$f] = (float)$kpis[$f];
            }
            foreach ($meses as &$m) {
                $m['total_cg'] = (float)$m['total_cg'];
                $m['total_depositado'] = (float)$m['total_depositado'];
                $m['total_transito'] = (float)$m['total_transito'];
                $m['total_diferencias'] = (float)$m['total_diferencias'];
            }

            echo json_encode([
                'status' => 'success',
                'kpis' => $kpis, 'meses' => $meses, 'trend' => $trend,
                'por_rs' => $por_rs, 'por_combinacion' => $por_combinacion,
            ]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    // ── DASHBOARD V3 — TRÁNSITOS  GET /income/get_transitos_dashboard_v3 ──────
    public function get_transitos_dashboard_v3(): void {
        ob_clean();
        header('Content-Type: application/json');

        $year        = (int)($_GET['year']         ?? 0);
        $month       = (int)($_GET['month']        ?? 0);
        $banco       = trim($_GET['banco']          ?? '');
        $rs          = trim($_GET['razon_social']   ?? '');
        $estacion_id = (int)($_GET['estacion_id']   ?? 0);
        $afiliacion  = trim($_GET['afiliacion']     ?? '');

        try {
            $conn = $this->v3_conn();

            $where  = "WHERE T.estado != 'CANCELADO' AND (T.monto_efectivo IS NULL OR T.monto_efectivo < 0.01)";
            $params = [];

            if ($year > 0)     { $where .= " AND YEAR(T.cg_fecha) = ?";   $params[] = $year;   }
            if ($month > 0)    { $where .= " AND MONTH(T.cg_fecha) = ?";  $params[] = $month;  }
            if ($banco !== '') { $where .= " AND TE.Nombre = ?";           $params[] = $banco;  }
            if ($estacion_id > 0) { $where .= " AND T.estacion_id = ?";     $params[] = $estacion_id; }
            if ($afiliacion !== ''){ $where .= " AND T.afiliacion = ?";     $params[] = $afiliacion;  }
            if ($rs === 'DIAZ GAS')   { $where .= " AND E.RFC = 'DGA930823KD3'"; }
            elseif ($rs === 'GASOMEX')   { $where .= " AND E.RFC = 'DGM880621FU5'"; }
            elseif ($rs === 'FORANEAS')  { $where .= " AND (E.RFC NOT IN ('DGA930823KD3','DGM880621FU5') OR E.RFC IS NULL)"; }

            $stmt = $conn->prepare(
                "SELECT T.id, T.mes_origen, T.mes_destino,
                        CONVERT(VARCHAR(10),T.cg_fecha,120) AS cg_fecha,
                        T.concepto, T.descripcion,
                        ISNULL(T.monto_transito,0) AS monto_transito, T.estado,
                        DATEDIFF(day, T.cg_fecha, GETDATE()) AS dias_antiguedad,
                        ISNULL(E.Nombre,'Est.'+CAST(T.estacion_id AS VARCHAR)) AS estacion,
                        ISNULL(TE.Nombre,'Banco '+CAST(T.entidad_id AS VARCHAR)) AS banco,
                        T.afiliacion
                 FROM CV3_Transito T
                 LEFT JOIN Estaciones E ON E.Codigo = T.estacion_id
                 LEFT JOIN Tesoreria_Entidad TE ON TE.id = T.entidad_id
                 $where
                 ORDER BY T.cg_fecha ASC"
            );
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $aging = ['lt30' => 0, 'bt30_60' => 0, 'gt60' => 0, 'total' => 0, 'monto' => 0.0];
            foreach ($rows as &$row) {
                $row['monto_transito']  = (float)$row['monto_transito'];
                $row['dias_antiguedad'] = (int)$row['dias_antiguedad'];
                $aging['total']++;
                $aging['monto'] += $row['monto_transito'];
                if ($row['dias_antiguedad'] < 30)      $aging['lt30']++;
                elseif ($row['dias_antiguedad'] <= 60) $aging['bt30_60']++;
                else                                    $aging['gt60']++;
            }

            echo json_encode(['status' => 'success', 'data' => $rows, 'aging' => $aging]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    // ── EXPORT V3 — Detalle Diferencias  GET /income/export_diferencias_v3 ────
    public function export_diferencias_v3(): void {
        ob_clean();
        $year        = (int)($_GET['year']         ?? 0);
        $month       = (int)($_GET['month']        ?? 0);
        $banco_id    = (int)($_GET['banco_id']      ?? 0);
        $rs          = trim($_GET['razon_social']   ?? '');
        $estacion_id = (int)($_GET['estacion_id']   ?? 0);
        $afiliacion  = trim($_GET['afiliacion']     ?? '');

        try {
            $conn = $this->v3_conn();

            $where  = "WHERE C.estado='CERRADO' AND ABS(ISNULL(C.total_diferencias,0)) > 0.01";
            $params = [];

            if ($year > 0 && $month > 0) {
                $where .= " AND C.mes = ?";
                $params[] = sprintf("%04d-%02d", $year, $month);
            } elseif ($year > 0) {
                $where .= " AND C.mes LIKE ?";
                $params[] = "$year-%";
            }

            if ($banco_id > 0)    { $where .= " AND C.entidad_id=?";         $params[] = $banco_id; }
            if ($estacion_id > 0) { $where .= " AND C.estacion_id=?";        $params[] = $estacion_id; }
            if ($afiliacion !== ''){ $where .= " AND C.afiliacion=?";        $params[] = $afiliacion; }
            if ($rs === 'DIAZ GAS')   { $where .= " AND E.RFC='DGA930823KD3'"; }
            elseif ($rs === 'GASOMEX')   { $where .= " AND E.RFC='DGM880621FU5'"; }
            elseif ($rs === 'FORANEAS')  { $where .= " AND (E.RFC NOT IN ('DGA930823KD3','DGM880621FU5') OR E.RFC IS NULL)"; }

            $stmt = $conn->prepare(
                "SELECT C.mes,
                        ISNULL(E.Nombre,'Est.'+CAST(C.estacion_id AS VARCHAR)) AS estacion,
                        CASE WHEN E.RFC='DGA930823KD3' THEN 'DIAZ GAS'
                             WHEN E.RFC='DGM880621FU5' THEN 'GASOMEX' ELSE 'FORANEAS' END AS razon_social,
                        ISNULL(TE.Nombre,'Banco '+CAST(C.entidad_id AS VARCHAR)) AS banco,
                        C.afiliacion, C.total_cg, C.total_depositado, C.total_transito,
                        C.total_diferencias, ISNULL(C.nota_cierre,'') AS nota_cierre,
                        CONVERT(VARCHAR(10),C.fecha_cierre,120) AS fecha_cierre
                 FROM Conciliacion_V3_CierreMes C
                 LEFT JOIN Estaciones E ON E.Codigo=C.estacion_id
                 LEFT JOIN Tesoreria_Entidad TE ON TE.id=C.entidad_id
                 $where ORDER BY C.mes DESC, estacion, banco"
            );
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $sp  = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sh  = $sp->getActiveSheet(); $sh->setTitle('Diferencias V3');

            $hStyle = ['font'=>['bold'=>true,'color'=>['argb'=>'FFFFFFFF']],
                       'fill'=>['fillType'=>\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,'startColor'=>['argb'=>'FFC0392B']],
                       'alignment'=>['horizontal'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER]];

            $headers = ['MES','ESTACIÓN','RAZÓN SOCIAL','BANCO','AFILIACIÓN','CG ($)','DEPOSITADO ($)','TRÁNSITO ($)','DIFERENCIA ($)','NOTA','FECHA CIERRE'];
            $sh->fromArray($headers, null, 'A1');
            $sh->getStyle('A1:K1')->applyFromArray($hStyle);

            $row = 2; $totalDif = 0;
            foreach ($rows as $r) {
                $sh->setCellValue("A$row", $r['mes']);
                $sh->setCellValue("B$row", $r['estacion']);
                $sh->setCellValue("C$row", $r['razon_social']);
                $sh->setCellValue("D$row", $r['banco']);
                $sh->setCellValue("E$row", $r['afiliacion']);
                $sh->setCellValue("F$row", (float)$r['total_cg']);
                $sh->setCellValue("G$row", (float)$r['total_depositado']);
                $sh->setCellValue("H$row", (float)$r['total_transito']);
                $sh->setCellValue("I$row", (float)$r['total_diferencias']);
                $sh->setCellValue("J$row", $r['nota_cierre']);
                $sh->setCellValue("K$row", $r['fecha_cierre']);
                $sh->getStyle("F$row:I$row")->getNumberFormat()->setFormatCode('$#,##0.00');
                $sh->getStyle("I$row")->getFont()->setBold(true);
                if ((float)$r['total_diferencias'] < -0.01)
                    $sh->getStyle("I$row")->getFont()->getColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_RED);
                $totalDif += (float)$r['total_diferencias'];
                $row++;
            }
            $sh->setCellValue("H$row", 'TOTAL:');
            $sh->setCellValue("I$row", $totalDif);
            $sh->getStyle("H$row:I$row")->getFont()->setBold(true);
            $sh->getStyle("I$row")->getNumberFormat()->setFormatCode('$#,##0.00');
            foreach (range('A','K') as $c) $sh->getColumnDimension($c)->setAutoSize(true);

            $label = $rs ?: 'TODOS';
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment;filename="Diferencias_V3_'.$label.'.xlsx"');
            header('Cache-Control: max-age=0');
            (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($sp))->save('php://output');

        } catch (\Exception $e) { echo "Error: " . $e->getMessage(); }
        exit;
    }

    // ── EXPORT V3 — Resumen General  GET /income/export_resumen_v3 ────────────
    public function export_resumen_v3(): void {
        ob_clean();
        $year        = (int)($_GET['year']         ?? 0);
        $month       = (int)($_GET['month']        ?? 0);
        $banco_id    = (int)($_GET['banco_id']      ?? 0);
        $rs          = trim($_GET['razon_social']   ?? '');
        $estacion_id = (int)($_GET['estacion_id']   ?? 0);
        $afiliacion  = trim($_GET['afiliacion']     ?? '');

        try {
            $conn = $this->v3_conn();

            $where  = "WHERE C.estado='CERRADO'";
            $params = [];

            if ($year > 0 && $month > 0) {
                $where .= " AND C.mes = ?";
                $params[] = sprintf("%04d-%02d", $year, $month);
            } elseif ($year > 0) {
                $where .= " AND C.mes LIKE ?";
                $params[] = "$year-%";
            }

            if ($banco_id > 0)    { $where .= " AND C.entidad_id=?";         $params[] = $banco_id; }
            if ($estacion_id > 0) { $where .= " AND C.estacion_id=?";        $params[] = $estacion_id; }
            if ($afiliacion !== ''){ $where .= " AND C.afiliacion=?";        $params[] = $afiliacion; }
            if ($rs === 'DIAZ GAS')   { $where .= " AND E.RFC='DGA930823KD3'"; }
            elseif ($rs === 'GASOMEX')   { $where .= " AND E.RFC='DGM880621FU5'"; }
            elseif ($rs === 'FORANEAS')  { $where .= " AND (E.RFC NOT IN ('DGA930823KD3','DGM880621FU5') OR E.RFC IS NULL)"; }

            $stmt = $conn->prepare(
                "SELECT ISNULL(TE.Nombre,'Banco '+CAST(C.entidad_id AS VARCHAR)) AS banco,
                        C.afiliacion,
                        ISNULL(E.Nombre,'Est.'+CAST(C.estacion_id AS VARCHAR)) AS estacion,
                        CASE WHEN E.RFC='DGA930823KD3' THEN 'DIAZ GAS'
                             WHEN E.RFC='DGM880621FU5' THEN 'GASOMEX' ELSE 'FORANEAS' END AS razon_social,
                        SUM(C.total_cg) AS total_cg,
                        SUM(C.total_depositado) AS total_depositado,
                        ISNULL(SUM(C.total_transito),0) AS total_transito,
                        ISNULL(SUM(C.total_diferencias),0) AS total_diferencias,
                        COUNT(*) AS meses_cerrados
                 FROM Conciliacion_V3_CierreMes C
                 LEFT JOIN Estaciones E ON E.Codigo=C.estacion_id
                 LEFT JOIN Tesoreria_Entidad TE ON TE.id=C.entidad_id
                 $where
                 GROUP BY TE.Nombre, C.entidad_id, C.afiliacion, E.Nombre, C.estacion_id, E.RFC
                 ORDER BY banco, estacion"
            );
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $byBank = [];
            foreach ($rows as $r) $byBank[$r['banco']][] = $r;

            $sp = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sh = $sp->getActiveSheet(); $sh->setTitle('Resumen General V3');

            $titulo = 'RESUMEN GENERAL CONCILIACIÓN V3' . ($rs ? " — $rs" : '') . ($year ? " — $year" : '');
            $sh->setCellValue('A1', $titulo);
            $sh->mergeCells('A1:H1');
            $sh->getStyle('A1')->getFont()->setBold(true)->setSize(14);
            $sh->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

            $hStyle = ['fill'=>['fillType'=>\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,'startColor'=>['argb'=>'FF3485A8']],
                       'font'=>['bold'=>true,'color'=>['argb'=>'FFFFFFFF']]];
            $totStyle = ['fill'=>['fillType'=>\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,'startColor'=>['argb'=>'FFE9ECEF']],
                         'font'=>['bold'=>true]];

            $rowIdx = 3;
            foreach ($byBank as $bankName => $bankRows) {
                $sh->setCellValue("A$rowIdx", "INSTITUCIÓN: $bankName");
                $sh->mergeCells("A$rowIdx:H$rowIdx");
                $sh->getStyle("A$rowIdx")->getFont()->setBold(true)->setSize(11);
                $rowIdx++;

                $sh->fromArray(['AFILIACIÓN','ESTACIÓN','RAZÓN SOCIAL','CG ($)','DEPOSITADO ($)','TRÁNSITO ($)','DIFERENCIA ($)','MESES'], null, "A$rowIdx");
                $sh->getStyle("A$rowIdx:H$rowIdx")->applyFromArray($hStyle);
                $rowIdx++;

                $sC=0; $sD=0; $sT=0; $sDif=0;
                foreach ($bankRows as $r) {
                    $sh->setCellValue("A$rowIdx", $r['afiliacion']);
                    $sh->setCellValue("B$rowIdx", $r['estacion']);
                    $sh->setCellValue("C$rowIdx", $r['razon_social']);
                    $sh->setCellValue("D$rowIdx", (float)$r['total_cg']);
                    $sh->setCellValue("E$rowIdx", (float)$r['total_depositado']);
                    $sh->setCellValue("F$rowIdx", (float)$r['total_transito']);
                    $sh->setCellValue("G$rowIdx", (float)$r['total_diferencias']);
                    $sh->setCellValue("H$rowIdx", (int)$r['meses_cerrados']);
                    $sh->getStyle("D$rowIdx:G$rowIdx")->getNumberFormat()->setFormatCode('$#,##0.00');
                    if (abs((float)$r['total_diferencias']) > 0.01)
                        $sh->getStyle("G$rowIdx")->getFont()->getColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_RED);
                    $sC += (float)$r['total_cg']; $sD += (float)$r['total_depositado'];
                    $sT += (float)$r['total_transito']; $sDif += (float)$r['total_diferencias'];
                    $rowIdx++;
                }
                $sh->setCellValue("C$rowIdx", "SUBTOTAL $bankName");
                $sh->setCellValue("D$rowIdx", $sC); $sh->setCellValue("E$rowIdx", $sD);
                $sh->setCellValue("F$rowIdx", $sT); $sh->setCellValue("G$rowIdx", $sDif);
                $sh->getStyle("A$rowIdx:H$rowIdx")->applyFromArray($totStyle);
                $sh->getStyle("D$rowIdx:G$rowIdx")->getNumberFormat()->setFormatCode('$#,##0.00');
                $rowIdx += 2;
            }
            foreach (range('A','H') as $c) $sh->getColumnDimension($c)->setAutoSize(true);

            $label = ($rs ?: 'TODOS') . ($year ? "_$year" : '');
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment;filename="Resumen_V3_'.$label.'.xlsx"');
            header('Cache-Control: max-age=0');
            (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($sp))->save('php://output');

        } catch (\Exception $e) { echo "Error: " . $e->getMessage(); }
        exit;
    }

    // ── HELPERS ───────────────────────────────────────────────────────────────

    private function v3_conn(): PDO {
        $conn = new PDO('sqlsrv:Server=192.168.0.6;Database=TG', 'cguser', 'sahei1712');
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $conn;
    }

    // -------------------------------------------------------------------------
    // 1. DEPÓSITOS DE TESORERÍA para el panel central
    //    GET /income/get_tesoreria_v3?entidad_id=&year=&month=&estacion_id=&afiliacion=
    // -------------------------------------------------------------------------
    public function get_tesoreria_v3(): void {
        ob_clean();
        header('Content-Type: application/json');

        $entidad_id  = (int)($_GET['entidad_id'] ?? 0);
        $year        = (int)($_GET['year']        ?? date('Y'));
        $month       = (int)($_GET['month']       ?? date('m'));
        $afiliacion  = trim($_GET['afiliacion']   ?? '');

        if (!$entidad_id) {
            echo json_encode(['status' => 'error', 'message' => 'Falta entidad_id']);
            exit;
        }

        try {
            $conn = $this->v3_conn();

            $sql = "SELECT id_origen, tabla_origen, entidad_id,
                           Fecha, Referencia, Descripcion, Sucursal,
                           Depositos, Retiros, Saldo, MovimientoID
                    FROM Tesoreria_V3_Unificada
                    WHERE entidad_id = ?
                      AND YEAR(Fecha)  = ?
                      AND MONTH(Fecha) = ?
                      AND Depositos    > 0
                    ORDER BY Fecha ASC";

            $stmt = $conn->prepare($sql);
            $stmt->execute([$entidad_id, $year, $month]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $result = [];
            foreach ($rows as $r) {
                $fecha = ($r['Fecha'] instanceof DateTime)
                    ? $r['Fecha']->format('Y-m-d')
                    : substr((string)$r['Fecha'], 0, 10);

                $result[] = [
                    'id_origen'    => $r['id_origen'],
                    'tabla_origen' => $r['tabla_origen'],
                    'fecha'        => $fecha,
                    'referencia'   => $r['Referencia'],
                    'descripcion'  => $r['Descripcion'],
                    'sucursal'     => $r['Sucursal'],
                    'monto'        => (float)$r['Depositos'],
                    'retiros'      => (float)$r['Retiros'],
                    'movimiento_id'=> $r['MovimientoID'],
                ];
            }

            echo json_encode(['status' => 'success', 'data' => $result]);

        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    // -------------------------------------------------------------------------
    // 2. CONCILIACIONES V3 YA HECHAS (para pintar items conciliados en pantalla)
    //    GET /income/get_conciliaciones_v3_hechas
    // -------------------------------------------------------------------------
    public function get_conciliaciones_v3_hechas(): void {
        ob_clean();
        header('Content-Type: application/json');

        $estacion_id = (int)(  $_GET['estacion_id'] ?? 0);
        $entidad_id  = (int)(  $_GET['entidad_id']  ?? 0);
        $afiliacion  = trim(   $_GET['afiliacion']  ?? '');
        $year        = (int)(  $_GET['year']         ?? date('Y'));
        $month       = (int)(  $_GET['month']        ?? date('m'));

        if (!$estacion_id || !$entidad_id) {
            echo json_encode(['status' => 'error', 'message' => 'Faltan parámetros']);
            exit;
        }

        try {
            $conn   = $this->v3_conn();
            $params = [$estacion_id, $entidad_id, $year, $month];

            $afil_filter = '';
            if ($afiliacion !== '') {
                // Una estación puede tener varias afiliaciones y sus grupos se
                // guardan como "A / B / C". La comparación exacta ocultaba
                // esos grupos al consultar una afiliación individual.
                $afil_parts = array_values(array_filter(array_map(
                    'trim', preg_split('/[,\/]+/', $afiliacion)
                ), fn($part) => $part !== ''));

                $afil_conditions = [];
                foreach ($afil_parts as $part) {
                    $afil_conditions[] = '(G.afiliacion = ? OR G.afiliacion LIKE ?)';
                    $params[] = $part;
                    $params[] = '%' . $part . '%';
                }
                if ($afil_conditions) {
                    $afil_filter = ' AND (' . implode(' OR ', $afil_conditions) . ')';
                }
            }

            $sql = "SELECT D.id, D.grupo_id, D.origen,
                           D.referencia_externa, D.fecha_operacion,
                           D.monto, D.concepto,
                           G.diferencia, G.estado, G.mes_cierre
                    FROM Conciliacion_V3_Detalles D
                    INNER JOIN Conciliacion_V3_Grupos G ON G.id = D.grupo_id
                    WHERE G.estacion_id  = ?
                      AND G.entidad_id   = ?
                      AND YEAR(G.fecha_operativa)  = ?
                      AND MONTH(G.fecha_operativa) = ?
                      $afil_filter
                    ORDER BY G.id, D.origen";

            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $data = [];
            foreach ($rows as $r) {
                $data[] = [
                    'id'         => (int)$r['id'],
                    'grupo_id'   => (int)$r['grupo_id'],
                    'origen'     => $r['origen'],          // 'CG' o 'TES'
                    'ref'        => $r['referencia_externa'],
                    'fecha'      => substr((string)$r['fecha_operacion'], 0, 10),
                    'monto'      => (float)$r['monto'],
                    'concepto'   => $r['concepto'],
                    'diferencia' => (float)$r['diferencia'],
                    'estado'     => $r['estado'],
                    'mes_cierre' => $r['mes_cierre'],
                ];
            }

            echo json_encode(['status' => 'success', 'data' => $data]);

        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    // -------------------------------------------------------------------------
    // 3. GUARDAR CONCILIACIÓN V3  (acción UNIR: CG + depósito Tesorería)
    //    POST /income/guardar_conciliacion_v3
    //    Body JSON: { estacion_id, entidad_id, afiliacion, fecha_operativa,
    //                 fecha_deposito, total_cg, total_tes,
    //                 left_rows:[{ref,fecha,monto,concepto}],
    //                 tes_rows: [{ref,fecha,monto,concepto}] }
    // -------------------------------------------------------------------------
    public function guardar_conciliacion_v3(): void {
        ob_clean();
        header('Content-Type: application/json');

        $data = json_decode(file_get_contents('php://input'), true);

        if (!$data
            || !isset($data['left_rows'], $data['tes_rows'])
            || empty($data['left_rows'])
            || empty($data['tes_rows'])) {
            echo json_encode(['status' => 'error', 'message' => 'Datos incompletos']);
            exit;
        }

        try {
            $conn = $this->v3_conn();
            $conn->beginTransaction();

            $total_cg  = (float)$data['total_cg'];
            $total_tes = (float)$data['total_tes'];
            $diferencia = round($total_tes - $total_cg, 2);

            // 1. Crear grupo
            $conn->prepare(
                "INSERT INTO Conciliacion_V3_Grupos
                    (estacion_id, entidad_id, afiliacion,
                     fecha_operativa, fecha_deposito,
                     total_sistema, total_tesoreria, diferencia)
                 VALUES (?,?,?,?,?,?,?,?)"
            )->execute([
                (int)$data['estacion_id'],
                (int)$data['entidad_id'],
                trim($data['afiliacion']    ?? ''),
                $data['fecha_operativa']    ?? date('Y-m-d'),
                $data['fecha_deposito']     ?? date('Y-m-d'),
                $total_cg,
                $total_tes,
                $diferencia,
            ]);

            $grupo_id = (int)$conn->query("SELECT @@IDENTITY")->fetchColumn();

            // 2. Insertar detalles
            $stmtDet = $conn->prepare(
                "INSERT INTO Conciliacion_V3_Detalles
                    (grupo_id, origen, referencia_externa, fecha_operacion, monto, concepto)
                 VALUES (?,?,?,?,?,?)"
            );

            // IDs de CV3_Transito que se reconcilian en este grupo
            $transitoDestIds = [];   // fake transit items (monto_transito)
            $transitoOrigRefs = [];  // CG items con transit activo (monto_efectivo)

            // IDs de CV3_Diferido que se reconcilian en este grupo
            $diferidoDestIds = [];   // fakes diferidos siendo conciliados en día destino
            $diferidoOrigIds = [];   // diferidos cuyo corte origen se está conciliando

            foreach ($data['left_rows'] as $row) {
                $tipo = $row['tipo'] ?? 'CG';
                if ($tipo === 'TRANSITO') {
                    // Item fake de tránsito — origen = TRANSITO
                    $stmtDet->execute([
                        $grupo_id, 'TRANSITO',
                        $row['ref']      ?? '',
                        $row['fecha']    ?? date('Y-m-d'),
                        (float)$row['monto'],
                        $row['concepto'] ?? '',
                    ]);
                    if (!empty($row['transito_id'])) {
                        $transitoDestIds[] = (int)$row['transito_id'];
                    }
                } elseif ($tipo === 'DIFERIDO') {
                    // Fake diferido — origen = DIFERIDO
                    $stmtDet->execute([
                        $grupo_id, 'DIFERIDO',
                        $row['ref']      ?? '',
                        $row['fecha']    ?? date('Y-m-d'),
                        (float)$row['monto'],
                        $row['concepto'] ?? '',
                    ]);
                    // Acepta diferido_ids (array) o diferido_id (legacy)
                    if (!empty($row['diferido_ids']) && is_array($row['diferido_ids'])) {
                        foreach ($row['diferido_ids'] as $did) {
                            $diferidoDestIds[] = (int)$did;
                        }
                    } elseif (!empty($row['diferido_id'])) {
                        $diferidoDestIds[] = (int)$row['diferido_id'];
                    }
                } else {
                    $stmtDet->execute([
                        $grupo_id, 'CG',
                        $row['ref']     ?? '',
                        $row['fecha']   ?? date('Y-m-d'),
                        (float)$row['monto'],
                        $row['concepto'] ?? '',
                    ]);
                    if (!empty($row['transito_orig_id'])) {
                        $transitoOrigRefs[] = (int)$row['transito_orig_id'];
                    }
                    if (!empty($row['diferido_orig_ids']) && is_array($row['diferido_orig_ids'])) {
                        foreach ($row['diferido_orig_ids'] as $did) {
                            $diferidoOrigIds[] = (int)$did;
                        }
                    }
                }
            }

            foreach ($data['tes_rows'] as $row) {
                $stmtDet->execute([
                    $grupo_id, 'TES',
                    $row['ref']     ?? '',
                    $row['fecha']   ?? date('Y-m-d'),
                    (float)$row['monto'],
                    $row['concepto'] ?? '',
                ]);
            }

            // 3a. Marcar fakes de tránsito como CONCILIADO (mes destino)
            if (!empty($transitoDestIds)) {
                $ph  = implode(',', array_fill(0, count($transitoDestIds), '?'));
                $conn->prepare(
                    "UPDATE CV3_Transito SET estado = 'CONCILIADO', conciliacion_id_dest = ?
                     WHERE id IN ($ph)"
                )->execute(array_merge([$grupo_id], $transitoDestIds));
            }

            // 3b. Marcar monto_efectivo de cortes en tránsito como conciliado (mes origen)
            if (!empty($transitoOrigRefs)) {
                $ph  = implode(',', array_fill(0, count($transitoOrigRefs), '?'));
                $conn->prepare(
                    "UPDATE CV3_Transito SET conciliacion_id_orig = ?
                     WHERE id IN ($ph)"
                )->execute(array_merge([$grupo_id], $transitoOrigRefs));
            }

            // 3c. Marcar fakes de diferido como CONCILIADO (día destino)
            if (!empty($diferidoDestIds)) {
                $ph = implode(',', array_fill(0, count($diferidoDestIds), '?'));
                $conn->prepare(
                    "UPDATE CV3_Diferido SET estado = 'CONCILIADO', conciliacion_id_dest = ?
                     WHERE id IN ($ph)"
                )->execute(array_merge([$grupo_id], $diferidoDestIds));
            }

            // 3d. Marcar conciliacion_id_orig en diferidos cuyo origen se concilió
            if (!empty($diferidoOrigIds)) {
                $ph = implode(',', array_fill(0, count($diferidoOrigIds), '?'));
                $conn->prepare(
                    "UPDATE CV3_Diferido SET conciliacion_id_orig = ?
                     WHERE id IN ($ph)"
                )->execute(array_merge([$grupo_id], $diferidoOrigIds));
            }

            // 3e. Si viene de un tránsito previo (sistema antiguo), cerrarlo
            if (!empty($data['transit_ids_to_close']) && is_array($data['transit_ids_to_close'])) {
                $ids  = array_map('intval', $data['transit_ids_to_close']);
                $ph   = implode(',', array_fill(0, count($ids), '?'));
                $upd  = $conn->prepare(
                    "UPDATE Conciliacion_V3_Transito
                     SET estado = 'COBRADO', grupo_id_cierre = ?, fecha_cierre = GETDATE()
                     WHERE id IN ($ph)"
                );
                $upd->execute(array_merge([$grupo_id], $ids));
            }

            $conn->commit();
            echo json_encode(['status' => 'success', 'grupo_id' => $grupo_id, 'diferencia' => $diferencia]);

        } catch (Exception $e) {
            if (isset($conn)) $conn->rollBack();
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    // -------------------------------------------------------------------------
    // 4. DESHACER CONCILIACIÓN V3
    //    POST /income/deshacer_conciliacion_v3   Body: { grupo_id }
    // -------------------------------------------------------------------------
    public function deshacer_conciliacion_v3(): void {
        ob_clean();
        header('Content-Type: application/json');

        $data     = json_decode(file_get_contents('php://input'), true);
        $grupo_id = (int)($data['grupo_id'] ?? 0);

        if (!$grupo_id) {
            echo json_encode(['status' => 'error', 'message' => 'Falta grupo_id']);
            exit;
        }

        try {
            $conn = $this->v3_conn();
            $conn->beginTransaction();

            // Verificar que el mes no esté cerrado
            $stmt = $conn->prepare(
                "SELECT estado, mes_cierre FROM Conciliacion_V3_Grupos WHERE id = ?"
            );
            $stmt->execute([$grupo_id]);
            $grupo = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$grupo) {
                $conn->rollBack();
                echo json_encode(['status' => 'error', 'message' => 'Grupo no encontrado']);
                exit;
            }
            if ($grupo['estado'] === 'CERRADO') {
                $conn->rollBack();
                echo json_encode(['status' => 'error',
                    'message' => "El mes {$grupo['mes_cierre']} ya está cerrado. No se puede deshacer."]);
                exit;
            }

            // Reabrir tránsitos que este grupo haya cerrado (sistema antiguo)
            $conn->prepare(
                "UPDATE Conciliacion_V3_Transito
                 SET estado = 'PENDIENTE', grupo_id_cierre = NULL, fecha_cierre = NULL
                 WHERE grupo_id_cierre = ?"
            )->execute([$grupo_id]);

            // Revertir CV3_Transito: fakes reconciliados en este grupo (mes destino)
            $conn->prepare(
                "UPDATE CV3_Transito SET estado = 'PENDIENTE', conciliacion_id_dest = NULL
                 WHERE conciliacion_id_dest = ?"
            )->execute([$grupo_id]);

            // Revertir CV3_Transito: monto_efectivo reconciliado en este grupo (mes origen)
            $conn->prepare(
                "UPDATE CV3_Transito SET conciliacion_id_orig = NULL
                 WHERE conciliacion_id_orig = ?"
            )->execute([$grupo_id]);

            // Revertir CV3_Diferido: fakes reconciliados en este grupo (día destino)
            $conn->prepare(
                "UPDATE CV3_Diferido SET estado = 'PENDIENTE', conciliacion_id_dest = NULL
                 WHERE conciliacion_id_dest = ?"
            )->execute([$grupo_id]);

            // Revertir CV3_Diferido: monto_diferido reconciliado en este grupo (día origen)
            $conn->prepare(
                "UPDATE CV3_Diferido SET conciliacion_id_orig = NULL
                 WHERE conciliacion_id_orig = ?"
            )->execute([$grupo_id]);

            // Borrar detalles y grupo (FK CASCADE borra detalles)
            $conn->prepare("DELETE FROM Conciliacion_V3_Grupos WHERE id = ?")->execute([$grupo_id]);

            $conn->commit();
            echo json_encode(['status' => 'success']);

        } catch (PDOException $e) {
            if (isset($conn)) $conn->rollBack();
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    // -------------------------------------------------------------------------
    // 4a. ACTUALIZAR MONTO DE UN DETALLE V3 Y RECALCULAR GRUPO
    //     POST /income/actualizar_detalle_v3
    //     Body: { id_detalle, nuevo_monto }
    // -------------------------------------------------------------------------
    public function actualizar_detalle_v3(): void {
        ob_clean();
        header('Content-Type: application/json');

        $data = json_decode(file_get_contents('php://input'), true);
        if (!isset($data['id_detalle'], $data['nuevo_monto'])) {
            echo json_encode(['status' => 'error', 'message' => 'Faltan datos']); exit;
        }

        $id_detalle  = (int)$data['id_detalle'];
        $nuevo_monto = (float)$data['nuevo_monto'];

        try {
            $conn = $this->v3_conn();
            $conn->beginTransaction();

            // 1. Obtener grupo_id
            $stmt = $conn->prepare("SELECT grupo_id FROM Conciliacion_V3_Detalles WHERE id = ?");
            $stmt->execute([$id_detalle]);
            $grupo_id = $stmt->fetchColumn();
            if (!$grupo_id) throw new Exception('Detalle no encontrado.');

            // 2. Verificar que el mes no esté cerrado
            $stmt = $conn->prepare("SELECT estado FROM Conciliacion_V3_Grupos WHERE id = ?");
            $stmt->execute([$grupo_id]);
            $estado = $stmt->fetchColumn();
            if ($estado === 'CERRADO') throw new Exception('El mes ya está cerrado.');

            // 3. Actualizar monto
            $conn->prepare("UPDATE Conciliacion_V3_Detalles SET monto = ? WHERE id = ?")
                 ->execute([$nuevo_monto, $id_detalle]);

            // 4. Recalcular totales del grupo
            $stmt = $conn->prepare(
                "SELECT SUM(CASE WHEN origen IN ('CG','TRANSITO','DIFERIDO') THEN monto ELSE 0 END) as total_cg,
                        SUM(CASE WHEN origen='TES' THEN monto ELSE 0 END) as total_tes
                 FROM Conciliacion_V3_Detalles WHERE grupo_id = ?"
            );
            $stmt->execute([$grupo_id]);
            $totales = $stmt->fetch(PDO::FETCH_ASSOC);
            $total_cg  = (float)$totales['total_cg'];
            $total_tes = (float)$totales['total_tes'];
            $diferencia = $total_tes - $total_cg;

            $conn->prepare(
                "UPDATE Conciliacion_V3_Grupos
                 SET total_sistema = ?, total_tesoreria = ?, diferencia = ?
                 WHERE id = ?"
            )->execute([$total_cg, $total_tes, $diferencia, $grupo_id]);

            $conn->commit();
            echo json_encode(['status' => 'success', 'diferencia' => $diferencia]);
        } catch (Exception $e) {
            if (isset($conn)) $conn->rollBack();
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    // -------------------------------------------------------------------------
    // 4b. DESHACER TODAS LAS CONCILIACIONES DEL MES V3
    //     POST /income/deshacer_mes_v3
    //     Body: { estacion_id, entidad_id, afiliacion, year, month }
    // -------------------------------------------------------------------------
    public function deshacer_mes_v3(): void {
        ob_clean();
        header('Content-Type: application/json');

        $data = json_decode(file_get_contents('php://input'), true);
        if (!isset($data['estacion_id'], $data['entidad_id'], $data['afiliacion'], $data['year'], $data['month'])) {
            echo json_encode(['status' => 'error', 'message' => 'Faltan parámetros']); exit;
        }

        $sid   = (int)$data['estacion_id'];
        $eid   = (int)$data['entidad_id'];
        $afil  = trim($data['afiliacion']);
        $year  = (int)$data['year'];
        $month = (int)$data['month'];

        try {
            $conn = $this->v3_conn();
            $conn->beginTransaction();

            // Obtener todos los grupos del mes (excluye CERRADOS)
            $stmt = $conn->prepare(
                "SELECT id FROM Conciliacion_V3_Grupos
                 WHERE estacion_id = ? AND entidad_id = ? AND afiliacion = ?
                   AND YEAR(fecha_operativa) = ? AND MONTH(fecha_operativa) = ?
                   AND estado <> 'CERRADO'"
            );
            $stmt->execute([$sid, $eid, $afil, $year, $month]);
            $groupIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (empty($groupIds)) {
                $conn->rollBack();
                echo json_encode(['status' => 'success', 'message' => 'No hay grupos para deshacer.', 'grupos_eliminados' => 0]);
                exit;
            }

            $chunks = array_chunk($groupIds, 500);
            foreach ($chunks as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));

                // 1. Reabrir tránsitos cerrados por estos grupos (sistema antiguo)
                $conn->prepare(
                    "UPDATE Conciliacion_V3_Transito
                     SET estado = 'PENDIENTE', grupo_id_cierre = NULL, fecha_cierre = NULL
                     WHERE grupo_id_cierre IN ($placeholders)"
                )->execute($chunk);

                // 2. Revertir CV3_Transito (mes destino)
                $conn->prepare(
                    "UPDATE CV3_Transito SET estado = 'PENDIENTE', conciliacion_id_dest = NULL
                     WHERE conciliacion_id_dest IN ($placeholders)"
                )->execute($chunk);

                // 3. Revertir CV3_Transito (mes origen)
                $conn->prepare(
                    "UPDATE CV3_Transito SET conciliacion_id_orig = NULL
                     WHERE conciliacion_id_orig IN ($placeholders)"
                )->execute($chunk);

                // 4. Revertir CV3_Diferido (día destino)
                $conn->prepare(
                    "UPDATE CV3_Diferido SET estado = 'PENDIENTE', conciliacion_id_dest = NULL
                     WHERE conciliacion_id_dest IN ($placeholders)"
                )->execute($chunk);

                // 5. Revertir CV3_Diferido (día origen)
                $conn->prepare(
                    "UPDATE CV3_Diferido SET conciliacion_id_orig = NULL
                     WHERE conciliacion_id_orig IN ($placeholders)"
                )->execute($chunk);

                // 6. Borrar grupos (FK CASCADE elimina detalles)
                $conn->prepare("DELETE FROM Conciliacion_V3_Grupos WHERE id IN ($placeholders)")->execute($chunk);
            }

            $conn->commit();
            echo json_encode(['status' => 'success', 'grupos_eliminados' => count($groupIds)]);
        } catch (PDOException $e) {
            if (isset($conn)) $conn->rollBack();
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    // -------------------------------------------------------------------------
    // 5. CERRAR MES V3
    //    POST /income/cerrar_mes_v3
    //    Body: { estacion_id, entidad_id, afiliacion, mes (YYYY-MM),
    //            items_pendientes (int), nota_cierre (string) }
    //
    //    Lógica:
    //      a) Verifica que no exista cierre previo
    //      b) Si items_pendientes > 0 y nota_cierre vacía → error
    //      c) Totales de grupos conciliados del mes
    //      d) Total en tránsito desde CV3_Transito (mes_origen = mes, no CANCELADO)
    //      e) Inserta/actualiza Conciliacion_V3_CierreMes con nuevos campos
    //      f) Marca grupos del mes como CERRADO
    // -------------------------------------------------------------------------
    public function cerrar_mes_v3(): void {
        ob_clean();
        header('Content-Type: application/json');

        $data             = json_decode(file_get_contents('php://input'), true);
        $estacion_id      = (int)($data['estacion_id']     ?? 0);
        $entidad_id       = (int)($data['entidad_id']      ?? 0);
        $afiliacion       = trim($data['afiliacion']       ?? '');
        $mes              = trim($data['mes']              ?? '');
        $items_pendientes = (int)($data['items_pendientes'] ?? 0);
        $nota_cierre      = trim($data['nota_cierre']      ?? '');

        if (!$estacion_id || !$entidad_id || !$mes || !preg_match('/^\d{4}-\d{2}$/', $mes)) {
            echo json_encode(['status' => 'error', 'message' => 'Parámetros inválidos']);
            exit;
        }

        // b) Si hay pendientes sin nota → rechazar
        if ($items_pendientes > 0 && $nota_cierre === '') {
            echo json_encode(['status' => 'error', 'message' => 'Hay ítems pendientes. Debes agregar una nota explicando el cierre.']);
            exit;
        }

        [$year, $month] = explode('-', $mes);

        try {
            $conn = $this->v3_conn();

            // Agregar columnas nuevas si no existen (migración segura)
            $conn->exec("IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id=OBJECT_ID('Conciliacion_V3_CierreMes') AND name='total_diferencias')
                         ALTER TABLE Conciliacion_V3_CierreMes ADD total_diferencias DECIMAL(18,2) NULL");
            $conn->exec("IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id=OBJECT_ID('Conciliacion_V3_CierreMes') AND name='items_pendientes')
                         ALTER TABLE Conciliacion_V3_CierreMes ADD items_pendientes INT NULL DEFAULT 0");
            $conn->exec("IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id=OBJECT_ID('Conciliacion_V3_CierreMes') AND name='nota_cierre')
                         ALTER TABLE Conciliacion_V3_CierreMes ADD nota_cierre NVARCHAR(500) NULL");

            $conn->beginTransaction();

            // a) Verificar cierre previo
            $stmtCheck = $conn->prepare(
                "SELECT id FROM Conciliacion_V3_CierreMes
                 WHERE estacion_id=? AND entidad_id=? AND afiliacion=? AND mes=?"
            );
            $stmtCheck->execute([$estacion_id, $entidad_id, $afiliacion, $mes]);
            if ($stmtCheck->fetch()) {
                $conn->rollBack();
                echo json_encode(['status' => 'error', 'message' => "El mes $mes ya fue cerrado."]);
                exit;
            }

            // c) Totales de grupos conciliados del mes
            $stmtTot = $conn->prepare(
                "SELECT ISNULL(SUM(total_sistema),0)        AS total_cg,
                        ISNULL(SUM(total_tesoreria),0)      AS total_tes,
                        ISNULL(SUM(CASE WHEN ABS(diferencia) > 0.01 THEN diferencia ELSE 0 END),0) AS total_diferencias
                 FROM Conciliacion_V3_Grupos
                 WHERE estacion_id=? AND entidad_id=?
                   AND (afiliacion=? OR (afiliacion IS NULL AND ?=''))
                   AND YEAR(fecha_operativa)=? AND MONTH(fecha_operativa)=?
                   AND estado='ACTIVO'"
            );
            $stmtTot->execute([$estacion_id, $entidad_id, $afiliacion, $afiliacion, $year, $month]);
            $totales = $stmtTot->fetch(PDO::FETCH_ASSOC);

            $total_cg         = (float)$totales['total_cg'];
            $total_tes        = (float)$totales['total_tes'];
            $total_diferencias = (float)$totales['total_diferencias'];

            // d) Total en tránsito desde CV3_Transito (tránsitos manuales de este mes)
            $stmtTr = $conn->prepare(
                "SELECT ISNULL(SUM(monto_transito),0) AS total_transito
                 FROM CV3_Transito
                 WHERE estacion_id=? AND entidad_id=? AND afiliacion=?
                   AND mes_origen=? AND estado != 'CANCELADO'"
            );
            $stmtTr->execute([$estacion_id, $entidad_id, $afiliacion, $mes]);
            $total_transito = (float)$stmtTr->fetchColumn();

            // e) Insertar registro de cierre
            $conn->prepare(
                "INSERT INTO Conciliacion_V3_CierreMes
                    (estacion_id, entidad_id, afiliacion, mes,
                     total_cg, total_depositado, total_transito,
                     total_diferencias, items_pendientes, nota_cierre)
                 VALUES (?,?,?,?,?,?,?,?,?,?)"
            )->execute([
                $estacion_id, $entidad_id, $afiliacion, $mes,
                $total_cg, $total_tes, $total_transito,
                $total_diferencias, $items_pendientes,
                $nota_cierre ?: null,
            ]);

            // f) Marcar grupos del mes como CERRADO
            $conn->prepare(
                "UPDATE Conciliacion_V3_Grupos
                 SET estado='CERRADO', mes_cierre=?
                 WHERE estacion_id=? AND entidad_id=?
                   AND (afiliacion=? OR (afiliacion IS NULL AND ?=''))
                   AND YEAR(fecha_operativa)=? AND MONTH(fecha_operativa)=?
                   AND estado='ACTIVO'"
            )->execute([$mes, $estacion_id, $entidad_id, $afiliacion, $afiliacion, $year, $month]);

            $conn->commit();

            echo json_encode([
                'status'           => 'success',
                'mes'              => $mes,
                'total_cg'         => $total_cg,
                'total_tes'        => $total_tes,
                'total_transito'   => $total_transito,
                'total_diferencias'=> $total_diferencias,
                'items_pendientes' => $items_pendientes,
            ]);

        } catch (Exception $e) {
            if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    // -------------------------------------------------------------------------
    // 6. TRÁNSITOS V3 PENDIENTES (aging para el mes actual)
    //    GET /income/get_transitos_v3_pendientes?estacion_id=&entidad_id=&afiliacion=
    // -------------------------------------------------------------------------
    public function get_transitos_v3_pendientes(): void {
        ob_clean();
        header('Content-Type: application/json');

        $estacion_id = (int)($_GET['estacion_id'] ?? 0);
        $entidad_id  = (int)($_GET['entidad_id']  ?? 0);
        $afiliacion  = trim($_GET['afiliacion']   ?? '');

        if (!$estacion_id) {
            echo json_encode(['status' => 'error', 'message' => 'Falta estacion_id']);
            exit;
        }

        try {
            $conn   = $this->v3_conn();
            $params = [$estacion_id];

            $sql = "SELECT id, mes_origen, fecha_cg, referencia_cg,
                           concepto, monto_pendiente, estado,
                           DATEDIFF(day, fecha_cg, GETDATE()) AS dias_antiguedad
                    FROM Conciliacion_V3_Transito
                    WHERE estacion_id = ? AND estado = 'PENDIENTE'";

            if ($entidad_id > 0) {
                $sql     .= ' AND entidad_id = ?';
                $params[] = $entidad_id;
            }
            if ($afiliacion !== '') {
                $sql     .= ' AND afiliacion = ?';
                $params[] = $afiliacion;
            }

            $sql .= ' ORDER BY fecha_cg ASC';

            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as &$r) {
                $r['fecha_cg']        = substr((string)$r['fecha_cg'], 0, 10);
                $r['monto_pendiente'] = (float)$r['monto_pendiente'];
                $r['dias_antiguedad'] = (int)$r['dias_antiguedad'];
            }

            echo json_encode(['status' => 'success', 'data' => $rows]);

        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    // -------------------------------------------------------------------------
    // 7. SUMMARY V3 para el dashboard
    //    GET /income/get_summary_v3?year=&month=&estacion_id=&banco_id=&afiliacion=
    // -------------------------------------------------------------------------
    public function get_summary_v3(): void {
        ob_clean();
        header('Content-Type: application/json');

        $year        = (int)($_GET['year']        ?? date('Y'));
        $month       = (int)($_GET['month']       ?? date('m'));
        $estacion_id = (int)($_GET['estacion_id'] ?? 0);
        $entidad_id  = (int)($_GET['banco_id']    ?? 0);
        $afiliacion  = trim($_GET['afiliacion']   ?? '');

        try {
            $conn = $this->v3_conn();

            // ── filtro base ──────────────────────────────────────────────────
            $where  = " WHERE YEAR(G.fecha_operativa) = ? AND MONTH(G.fecha_operativa) = ? ";
            $params = [$year, $month];

            if ($estacion_id > 0) { $where .= ' AND G.estacion_id = ? '; $params[] = $estacion_id; }
            if ($entidad_id  > 0) { $where .= ' AND G.entidad_id  = ? '; $params[] = $entidad_id;  }
            if ($afiliacion !== '') { $where .= ' AND G.afiliacion = ? '; $params[] = $afiliacion;  }

            $from = " FROM Conciliacion_V3_Grupos G $where";

            // ── KPIs ─────────────────────────────────────────────────────────
            $totales = $conn->prepare(
                "SELECT ISNULL(SUM(total_sistema),0)   AS total_sistema,
                        ISNULL(SUM(total_tesoreria),0) AS total_tesoreria,
                        ISNULL(SUM(diferencia),0)      AS total_diferencia,
                        COUNT(G.id)                    AS total_grupos
                 $from"
            );
            $totales->execute($params);
            $kpis = $totales->fetch(PDO::FETCH_ASSOC);

            // ── Por día (solo días con diferencia) ───────────────────────────
            $stmtDias = $conn->prepare(
                "SELECT FORMAT(G.fecha_operativa,'yyyy-MM-dd') AS fecha,
                        COUNT(G.id)                AS count,
                        SUM(G.total_sistema)       AS sistema,
                        SUM(G.total_tesoreria)     AS tesoreria,
                        SUM(G.diferencia)          AS diferencia
                 $from
                 GROUP BY FORMAT(G.fecha_operativa,'yyyy-MM-dd')
                 HAVING SUM(G.diferencia) <> 0
                 ORDER BY fecha DESC"
            );
            $stmtDias->execute($params);
            $dias = $stmtDias->fetchAll(PDO::FETCH_ASSOC);

            // ── Por estación ─────────────────────────────────────────────────
            $stmtEst = $conn->prepare(
                "SELECT E.Nombre AS estacion,
                        SUM(G.total_sistema)   AS sistema,
                        SUM(G.total_tesoreria) AS tesoreria,
                        SUM(G.diferencia)      AS diferencia
                 FROM Conciliacion_V3_Grupos G
                 LEFT JOIN Estaciones E ON E.Codigo = G.estacion_id
                 $where
                 GROUP BY E.Nombre
                 HAVING SUM(G.diferencia) <> 0
                 ORDER BY E.Nombre"
            );
            $stmtEst->execute($params);
            $estaciones = $stmtEst->fetchAll(PDO::FETCH_ASSOC);

            // ── Por banco ────────────────────────────────────────────────────
            $stmtBanco = $conn->prepare(
                "SELECT TE.Nombre AS banco,
                        ISNULL(G.afiliacion,'Sin Afil.') AS afiliacion,
                        SUM(G.total_sistema)   AS sistema,
                        SUM(G.total_tesoreria) AS tesoreria,
                        SUM(G.diferencia)      AS diferencia
                 FROM Conciliacion_V3_Grupos G
                 LEFT JOIN Tesoreria_Entidad TE ON TE.id = G.entidad_id
                 $where
                 GROUP BY TE.Nombre, G.afiliacion
                 HAVING SUM(G.diferencia) <> 0
                 ORDER BY TE.Nombre"
            );
            $stmtBanco->execute($params);
            $bancos = $stmtBanco->fetchAll(PDO::FETCH_ASSOC);

            // ── Estado de cierre del mes ─────────────────────────────────────
            $stmtCierre = $conn->prepare(
                "SELECT mes, total_cg, total_depositado, total_transito, estado, fecha_cierre
                 FROM Conciliacion_V3_CierreMes
                 WHERE estacion_id = ? AND YEAR(fecha_cierre) = ? AND MONTH(fecha_cierre) = ?
                   AND entidad_id = ?"
            );
            $stmtCierre->execute([$estacion_id, $year, $month, $entidad_id]);
            $cierre = $stmtCierre->fetch(PDO::FETCH_ASSOC) ?: null;

            echo json_encode([
                'status'     => 'success',
                'totales'    => $kpis,
                'dias'       => $dias,
                'estaciones' => $estaciones,
                'bancos'     => $bancos,
                'cierre_mes' => $cierre,
            ]);

        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    // -------------------------------------------------------------------------
    // 8. PREVIEW ANTES DE CERRAR MES (cuánto iría a tránsito)
    //    GET /income/preview_cierre_v3?estacion_id=&entidad_id=&afiliacion=&mes=YYYY-MM
    // -------------------------------------------------------------------------
    public function preview_cierre_v3(): void {
        ob_clean();
        header('Content-Type: application/json');

        $estacion_id = (int)($_GET['estacion_id'] ?? 0);
        $entidad_id  = (int)($_GET['entidad_id']  ?? 0);
        $afiliacion  = trim($_GET['afiliacion']   ?? '');
        $mes         = trim($_GET['mes']           ?? '');

        if (!$estacion_id || !$entidad_id || !$mes) {
            echo json_encode(['status' => 'error', 'message' => 'Faltan parámetros']);
            exit;
        }

        [$year, $month] = explode('-', $mes);

        try {
            $conn = $this->v3_conn();

            // ¿Ya cerrado?
            $stmtC = $conn->prepare(
                "SELECT id FROM Conciliacion_V3_CierreMes
                 WHERE estacion_id=? AND entidad_id=? AND afiliacion=? AND mes=?"
            );
            $stmtC->execute([$estacion_id, $entidad_id, $afiliacion, $mes]);
            $ya_cerrado = (bool)$stmtC->fetch();

            // Totales de grupos conciliados del mes
            $stmtT = $conn->prepare(
                "SELECT ISNULL(SUM(total_sistema),0)        AS total_cg,
                        ISNULL(SUM(total_tesoreria),0)      AS total_tes,
                        ISNULL(SUM(CASE WHEN ABS(diferencia) > 0.01 THEN diferencia ELSE 0 END),0) AS total_diferencias,
                        COUNT(id)                           AS total_grupos
                 FROM Conciliacion_V3_Grupos
                 WHERE estacion_id=? AND entidad_id=?
                   AND (afiliacion=? OR (afiliacion IS NULL AND ?=''))
                   AND YEAR(fecha_operativa)=? AND MONTH(fecha_operativa)=?
                   AND estado='ACTIVO'"
            );
            $stmtT->execute([$estacion_id, $entidad_id, $afiliacion, $afiliacion, $year, $month]);
            $t = $stmtT->fetch(PDO::FETCH_ASSOC);

            // Total en tránsito desde CV3_Transito
            $stmtTr = $conn->prepare(
                "SELECT ISNULL(SUM(monto_transito),0) AS total_transito,
                        COUNT(id)                     AS n_transitos
                 FROM CV3_Transito
                 WHERE estacion_id=? AND entidad_id=? AND afiliacion=?
                   AND mes_origen=? AND estado != 'CANCELADO'"
            );
            $stmtTr->execute([$estacion_id, $entidad_id, $afiliacion, $mes]);
            $tr = $stmtTr->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'status'           => 'success',
                'ya_cerrado'       => $ya_cerrado,
                'total_cg'         => (float)$t['total_cg'],
                'total_tes'        => (float)$t['total_tes'],
                'total_diferencias'=> (float)$t['total_diferencias'],
                'total_grupos'     => (int)$t['total_grupos'],
                'total_transito'   => (float)$tr['total_transito'],
                'n_transitos'      => (int)$tr['n_transitos'],
            ]);

        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    // -------------------------------------------------------------------------
    // 9. LISTADO DE CIERRES DE MES V3
    //    GET /income/get_cierres_v3?estacion_id=&entidad_id=&afiliacion=
    // -------------------------------------------------------------------------
    public function get_cierres_v3(): void {
        ob_clean();
        header('Content-Type: application/json');

        $estacion_id = (int)($_GET['estacion_id'] ?? 0);
        $entidad_id  = (int)($_GET['entidad_id']  ?? 0);
        $afiliacion  = trim($_GET['afiliacion']   ?? '');

        if (!$estacion_id) {
            echo json_encode(['status' => 'error', 'message' => 'Falta estacion_id']);
            exit;
        }

        try {
            $conn   = $this->v3_conn();
            $where  = 'WHERE C.estacion_id = ?';
            $params = [$estacion_id];

            if ($entidad_id > 0) { $where .= ' AND C.entidad_id = ?'; $params[] = $entidad_id; }
            if ($afiliacion !== '') { $where .= ' AND C.afiliacion = ?'; $params[] = $afiliacion; }

            $sql = "SELECT C.mes, C.afiliacion,
                           ISNULL(TE.Nombre, 'Banco ' + CAST(C.entidad_id AS VARCHAR)) AS banco,
                           C.total_cg, C.total_depositado, C.total_transito,
                           C.estado, C.fecha_cierre
                    FROM Conciliacion_V3_CierreMes C
                    LEFT JOIN Tesoreria_Entidad TE ON TE.id = C.entidad_id
                    $where
                    ORDER BY C.mes DESC, TE.Nombre, C.afiliacion";

            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $data = [];
            foreach ($rows as $r) {
                $data[] = [
                    'mes'             => $r['mes'],
                    'banco'           => $r['banco'],
                    'afiliacion'      => $r['afiliacion'],
                    'total_cg'        => (float)$r['total_cg'],
                    'total_depositado'=> (float)$r['total_depositado'],
                    'total_transito'  => (float)$r['total_transito'],
                    'estado'          => $r['estado'],
                    'fecha_cierre'    => substr((string)$r['fecha_cierre'], 0, 10),
                ];
            }

            echo json_encode(['status' => 'success', 'data' => $data]);

        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    // -------------------------------------------------------------------------
    // 14. CREAR TRÁNSITO PARA DÍA COMPLETO (batch)
    //     POST /income/crear_transito_dia_v3
    //     Body: { estacion_id, entidad_id, afiliacion, mes_origen, mes_destino,
    //             monto_transito_total, descripcion,
    //             cortes: [{ref, monto_corte, concepto, cg_fecha}, ...] }
    //     Distribuye monto_transito_total proporcionalmente entre los cortes.
    // -------------------------------------------------------------------------
    public function crear_transito_dia_v3(): void {
        ob_clean();
        header('Content-Type: application/json');

        $data = json_decode(file_get_contents('php://input'), true);

        $estacion_id        = (int)($data['estacion_id']         ?? 0);
        $entidad_id         = (int)($data['entidad_id']          ?? 0);
        $afiliacion         = trim($data['afiliacion']           ?? '');
        $mes_origen         = trim($data['mes_origen']           ?? '');
        $mes_destino        = trim($data['mes_destino']          ?? '');
        $monto_trans_total  = (float)($data['monto_transito_total'] ?? 0);
        $descripcion        = trim($data['descripcion']          ?? '');
        $cortes             = $data['cortes']                    ?? [];

        if (!$estacion_id || !$entidad_id || !$mes_origen || !$mes_destino || empty($cortes) || $monto_trans_total <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Datos incompletos']);
            exit;
        }

        $total_cortes = array_sum(array_map(fn($c) => (float)($c['monto_corte'] ?? 0), $cortes));
        if ($total_cortes <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Total de cortes inválido']);
            exit;
        }

        try {
            $conn = $this->v3_conn();
            $this->v3_ensure_transito_table($conn);

            // Verificar mes origen no cerrado
            $cm = $conn->prepare("
                SELECT id FROM Conciliacion_V3_CierreMes
                WHERE estacion_id = ? AND entidad_id = ?
                  AND (afiliacion = ? OR (afiliacion IS NULL AND ? = ''))
                  AND mes = ?
            ");
            $cm->execute([$estacion_id, $entidad_id, $afiliacion, $afiliacion, $mes_origen]);
            if ($cm->fetch()) {
                echo json_encode(['status' => 'error', 'message' => 'El mes origen ya está cerrado']);
                exit;
            }

            $conn->beginTransaction();

            $stmt = $conn->prepare("
                INSERT INTO CV3_Transito
                    (estacion_id, entidad_id, afiliacion, corte_ref_id, cg_fecha,
                     mes_origen, mes_destino, monto_corte, monto_transito, monto_efectivo,
                     concepto, descripcion, estado)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'PENDIENTE')
            ");

            // Pre-filtrar cortes válidos (sin tránsito activo) para poder identificar el último
            $cortes_validos = [];
            foreach ($cortes as $corte) {
                $ref         = trim($corte['ref']       ?? '');
                $monto_corte = (float)($corte['monto_corte'] ?? 0);
                if (!$ref || $monto_corte <= 0) continue;

                $ck = $conn->prepare("
                    SELECT id FROM CV3_Transito
                    WHERE estacion_id = ? AND entidad_id = ? AND afiliacion = ?
                      AND corte_ref_id = ? AND estado != 'CANCELADO'
                ");
                $ck->execute([$estacion_id, $entidad_id, $afiliacion, $ref]);
                if ($ck->fetch()) continue;

                $cortes_validos[] = $corte;
            }

            $ids_creados        = [];
            $suma_transito      = 0.0;
            $n_validos          = count($cortes_validos);

            foreach ($cortes_validos as $i => $corte) {
                $ref          = trim($corte['ref']       ?? '');
                $monto_corte  = (float)($corte['monto_corte'] ?? 0);
                $concepto     = trim($corte['concepto']  ?? '');
                $cg_fecha     = trim($corte['cg_fecha']  ?? '');

                if ($i === $n_validos - 1) {
                    // Último ítem: asignar el resto exacto para que la suma sea perfecta
                    $monto_transito = round($monto_trans_total - $suma_transito, 2);
                } else {
                    $proporcion     = $monto_corte / $total_cortes;
                    $monto_transito = round($monto_trans_total * $proporcion, 2);
                }

                // Asegurar que monto_transito no supere el corte individual
                if ($monto_transito > $monto_corte) { $monto_transito = $monto_corte; }
                $monto_transito = round($monto_transito, 2);
                $monto_efectivo = round($monto_corte - $monto_transito, 2);
                $suma_transito  = round($suma_transito + $monto_transito, 2);

                $stmt->execute([
                    $estacion_id, $entidad_id, $afiliacion, $ref, $cg_fecha,
                    $mes_origen, $mes_destino, $monto_corte, $monto_transito, $monto_efectivo,
                    $concepto, $descripcion
                ]);

                $ids_creados[] = (int)$conn->query("SELECT @@IDENTITY")->fetchColumn();
            }

            $conn->commit();
            echo json_encode(['status' => 'success', 'creados' => count($ids_creados), 'ids' => $ids_creados]);

        } catch (PDOException $e) {
            if (isset($conn)) $conn->rollBack();
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    // -------------------------------------------------------------------------
    // HELPER PRIVADO: Crea tabla CV3_Transito si no existe
    // -------------------------------------------------------------------------
    private function v3_ensure_transito_table(PDO $conn): void {
        $conn->exec("
            IF NOT EXISTS (SELECT * FROM sys.objects WHERE name = 'CV3_Transito' AND type = 'U')
            CREATE TABLE CV3_Transito (
                id                   INT IDENTITY(1,1) PRIMARY KEY,
                estacion_id          INT NOT NULL,
                entidad_id           INT NOT NULL,
                afiliacion           VARCHAR(50) NOT NULL,
                corte_ref_id         VARCHAR(200) NOT NULL,
                cg_fecha             DATE NOT NULL,
                mes_origen           VARCHAR(7) NOT NULL,
                mes_destino          VARCHAR(7) NOT NULL,
                monto_corte          DECIMAL(18,2) NOT NULL,
                monto_transito       DECIMAL(18,2) NOT NULL,
                monto_efectivo       DECIMAL(18,2) NOT NULL,
                concepto             VARCHAR(200),
                descripcion          VARCHAR(300),
                estado               VARCHAR(20) NOT NULL DEFAULT 'PENDIENTE',
                conciliacion_id_orig INT NULL,
                conciliacion_id_dest INT NULL,
                created_at           DATETIME DEFAULT GETDATE(),
                created_by           INT NULL
            )
        ");
    }

    // -------------------------------------------------------------------------
    // 10b. REABRIR MES V3
    //      POST /income/reabrir_mes_v3
    //      Body: { estacion_id, entidad_id, afiliacion, mes (YYYY-MM) }
    //      Lógica:
    //        a) Verifica que el mes esté cerrado
    //        b) Reactiva grupos CERRADO → ACTIVO, borra mes_cierre
    //        c) Elimina registro de Conciliacion_V3_CierreMes
    //        No toca CV3_Transito ni conciliaciones individuales
    // -------------------------------------------------------------------------
    public function reabrir_mes_v3(): void {
        ob_clean();
        header('Content-Type: application/json');

        $data        = json_decode(file_get_contents('php://input'), true);
        $estacion_id = (int)($data['estacion_id'] ?? 0);
        $entidad_id  = (int)($data['entidad_id']  ?? 0);
        $afiliacion  = trim($data['afiliacion']   ?? '');
        $mes         = trim($data['mes']           ?? '');

        if (!$estacion_id || !$entidad_id || !$mes || !preg_match('/^\d{4}-\d{2}$/', $mes)) {
            echo json_encode(['status' => 'error', 'message' => 'Parámetros inválidos']);
            exit;
        }

        [$year, $month] = explode('-', $mes);

        try {
            $conn = $this->v3_conn();
            $conn->beginTransaction();

            // a) Verificar que esté cerrado
            $stmtC = $conn->prepare(
                "SELECT id FROM Conciliacion_V3_CierreMes
                 WHERE estacion_id=? AND entidad_id=? AND afiliacion=? AND mes=?"
            );
            $stmtC->execute([$estacion_id, $entidad_id, $afiliacion, $mes]);
            if (!$stmtC->fetch()) {
                $conn->rollBack();
                echo json_encode(['status' => 'error', 'message' => "El mes $mes no está cerrado."]);
                exit;
            }

            // b) Reactivar grupos
            $stmtUp = $conn->prepare(
                "UPDATE Conciliacion_V3_Grupos
                 SET estado='ACTIVO', mes_cierre=NULL
                 WHERE estacion_id=? AND entidad_id=?
                   AND (afiliacion=? OR (afiliacion IS NULL AND ?=''))
                   AND YEAR(fecha_operativa)=? AND MONTH(fecha_operativa)=?
                   AND estado='CERRADO'"
            );
            $stmtUp->execute([$estacion_id, $entidad_id, $afiliacion, $afiliacion, $year, $month]);
            $gruposReactivados = $stmtUp->rowCount();

            // c) Eliminar registro de cierre
            $conn->prepare(
                "DELETE FROM Conciliacion_V3_CierreMes
                 WHERE estacion_id=? AND entidad_id=? AND afiliacion=? AND mes=?"
            )->execute([$estacion_id, $entidad_id, $afiliacion, $mes]);

            $conn->commit();
            echo json_encode([
                'status'              => 'success',
                'mes'                 => $mes,
                'grupos_reactivados'  => $gruposReactivados,
            ]);

        } catch (Exception $e) {
            if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    // -------------------------------------------------------------------------
    // 10. TRÁNSITOS CG V3 — OBTENER PARA MES
    //     GET /income/get_transitos_cg_v3
    //     Params: estacion_id, entidad_id, afiliacion, year, month
    //     Returns:
    //       en_transito: cortes del mes origen que ya tienen tránsito creado
    //       fakes:       registros fake del mes destino (para mostrar en panel CG)
    // -------------------------------------------------------------------------
    public function get_transitos_cg_v3(): void {
        ob_clean();
        header('Content-Type: application/json');

        $estacion_id = (int)($_GET['estacion_id'] ?? 0);
        $entidad_id  = (int)($_GET['entidad_id']  ?? 0);
        $afiliacion  = trim($_GET['afiliacion']   ?? '');
        $year        = (int)($_GET['year']        ?? date('Y'));
        $month       = (int)($_GET['month']       ?? date('m'));

        if (!$estacion_id) {
            echo json_encode(['status' => 'error', 'message' => 'Falta estacion_id']);
            exit;
        }

        $mes = sprintf('%04d-%02d', $year, $month);

        try {
            $conn = $this->v3_conn();
            $this->v3_ensure_transito_table($conn);

            $af = $afiliacion !== '' ? ' AND afiliacion = ?' : '';

            // Cortes con tránsito en el mes origen actual
            $p1 = [$estacion_id, $entidad_id];
            if ($afiliacion !== '') $p1[] = $afiliacion;
            $p1[] = $mes;

            $s1 = $conn->prepare("
                SELECT id, corte_ref_id, cg_fecha, mes_origen, mes_destino,
                       monto_corte, monto_transito, monto_efectivo,
                       concepto, descripcion, estado,
                       conciliacion_id_orig, conciliacion_id_dest
                FROM CV3_Transito
                WHERE estacion_id = ? AND entidad_id = ? $af
                  AND mes_origen = ? AND estado != 'CANCELADO'
                ORDER BY cg_fecha ASC
            ");
            $s1->execute($p1);
            $enTransito = $s1->fetchAll(PDO::FETCH_ASSOC);

            // Fake CG items para el mes destino actual
            $p2 = [$estacion_id, $entidad_id];
            if ($afiliacion !== '') $p2[] = $afiliacion;
            $p2[] = $mes;

            $s2 = $conn->prepare("
                SELECT id, corte_ref_id, cg_fecha, mes_origen, mes_destino,
                       monto_corte, monto_transito, monto_efectivo,
                       concepto, descripcion, estado,
                       conciliacion_id_orig, conciliacion_id_dest
                FROM CV3_Transito
                WHERE estacion_id = ? AND entidad_id = ? $af
                  AND mes_destino = ? AND estado != 'CANCELADO'
                ORDER BY cg_fecha ASC
            ");
            $s2->execute($p2);
            $fakes = $s2->fetchAll(PDO::FETCH_ASSOC);

            $fmtRow = function (&$r) {
                $r['cg_fecha']       = substr((string)$r['cg_fecha'], 0, 10);
                $r['monto_corte']    = (float)$r['monto_corte'];
                $r['monto_transito'] = (float)$r['monto_transito'];
                $r['monto_efectivo'] = (float)$r['monto_efectivo'];
            };
            foreach ($enTransito as &$r) $fmtRow($r);
            foreach ($fakes    as &$r) $fmtRow($r);

            echo json_encode(['status' => 'success', 'en_transito' => $enTransito, 'fakes' => $fakes]);

        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    // -------------------------------------------------------------------------
    // 11. CALCULAR SUGERENCIA DE MONTO DE TRÁNSITO
    //     GET /income/calcular_transito_v3
    //     Params: entidad_id, afiliacion, cg_fecha (YYYY-MM-DD), mes_destino (YYYY-MM)
    //     Consulta banco_* para transacciones cuyo Fecha_Transaccion = cg_fecha
    //     y cuyo Fecha_Deposito está en mes_destino.
    // -------------------------------------------------------------------------
    public function calcular_transito_v3(): void {
        ob_clean();
        header('Content-Type: application/json');

        $entidad_id  = (int)($_GET['entidad_id']  ?? 0);
        $afiliacion  = trim($_GET['afiliacion']   ?? '');
        $cg_fecha    = trim($_GET['cg_fecha']     ?? '');
        $mes_destino = trim($_GET['mes_destino']  ?? '');

        if (!$entidad_id || !$afiliacion || !$cg_fecha || !$mes_destino) {
            echo json_encode(['status' => 'error', 'message' => 'Faltan parámetros']);
            exit;
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $cg_fecha) || !preg_match('/^\d{4}-\d{2}$/', $mes_destino)) {
            echo json_encode(['status' => 'error', 'message' => 'Formato de fecha/mes inválido']);
            exit;
        }

        [$yd, $md] = explode('-', $mes_destino);

        $tablaMap = [1 => 'banco_getnet', 3 => 'banco_amex', 4 => 'banco_banorte', 5 => 'banco_bbva', 13 => 'banco_afirme'];
        $tabla = $tablaMap[$entidad_id] ?? null;

        if (!$tabla) {
            echo json_encode(['status' => 'error', 'message' => 'Entidad no soportada']);
            exit;
        }

        try {
            $conn = $this->v3_conn();

            $afil_parts   = array_map('trim', preg_split('/[,\/]/', $afiliacion));
            $placeholders = implode(',', array_fill(0, count($afil_parts), '?'));
            $params       = array_merge([$cg_fecha, (int)$yd, (int)$md], $afil_parts);

            $sql = "SELECT Monto, Fecha_Transaccion, Fecha_Deposito, Afiliacion, Terminal, Hora, Codigo_Autorizacion
                    FROM $tabla
                    WHERE CONVERT(DATE, Fecha_Transaccion) = ?
                      AND YEAR(Fecha_Deposito)             = ?
                      AND MONTH(Fecha_Deposito)            = ?
                      AND LTRIM(RTRIM(Afiliacion)) IN ($placeholders)
                    ORDER BY Fecha_Transaccion ASC";

            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $total   = 0.0;
            $detalle = [];
            foreach ($rows as $r) {
                $m = (float)$r['Monto'];
                $total += $m;
                $detalle[] = [
                    'monto'     => $m,
                    'fecha_tx'  => substr((string)$r['Fecha_Transaccion'], 0, 10),
                    'fecha_dep' => substr((string)$r['Fecha_Deposito'],   0, 10),
                    'terminal'  => $r['Terminal'],
                    'hora'      => $r['Hora'],
                    'auth'      => $r['Codigo_Autorizacion'],
                ];
            }

            echo json_encode(['status' => 'success', 'total' => round($total, 2), 'n_tx' => count($rows), 'detalle' => $detalle]);

        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    // -------------------------------------------------------------------------
    // 12. CREAR TRÁNSITO CG V3
    //     POST /income/crear_transito_v3
    //     Body: { estacion_id, entidad_id, afiliacion, corte_ref_id, cg_fecha,
    //             mes_origen, mes_destino, monto_corte, monto_transito, concepto, descripcion }
    // -------------------------------------------------------------------------
    public function crear_transito_v3(): void {
        ob_clean();
        header('Content-Type: application/json');

        $data = json_decode(file_get_contents('php://input'), true);

        $required = ['estacion_id','entidad_id','afiliacion','corte_ref_id','cg_fecha',
                     'mes_origen','mes_destino','monto_corte','monto_transito'];
        foreach ($required as $k) {
            if (!isset($data[$k]) || $data[$k] === '') {
                echo json_encode(['status' => 'error', 'message' => "Falta campo: $k"]);
                exit;
            }
        }

        $estacion_id    = (int)$data['estacion_id'];
        $entidad_id     = (int)$data['entidad_id'];
        $afiliacion     = trim($data['afiliacion']);
        $corte_ref_id   = trim($data['corte_ref_id']);
        $cg_fecha       = trim($data['cg_fecha']);
        $mes_origen     = trim($data['mes_origen']);
        $mes_destino    = trim($data['mes_destino']);
        $monto_corte    = (float)$data['monto_corte'];
        $monto_transito = (float)$data['monto_transito'];
        $concepto       = trim($data['concepto']    ?? '');
        $descripcion    = trim($data['descripcion'] ?? '');

        if ($monto_transito <= 0 || $monto_transito > $monto_corte + 0.01) {
            echo json_encode(['status' => 'error', 'message' => 'Monto de tránsito inválido']);
            exit;
        }

        $monto_efectivo = round($monto_corte - $monto_transito, 2);

        try {
            $conn = $this->v3_conn();
            $this->v3_ensure_transito_table($conn);

            // Verificar que no existe ya un tránsito activo para este corte
            $ck = $conn->prepare("
                SELECT id FROM CV3_Transito
                WHERE estacion_id = ? AND entidad_id = ? AND afiliacion = ?
                  AND corte_ref_id = ? AND estado != 'CANCELADO'
            ");
            $ck->execute([$estacion_id, $entidad_id, $afiliacion, $corte_ref_id]);
            if ($ck->fetch()) {
                echo json_encode(['status' => 'error', 'message' => 'Este corte ya tiene un tránsito activo']);
                exit;
            }

            // Verificar que el mes origen no está cerrado
            $cm = $conn->prepare("
                SELECT id FROM Conciliacion_V3_CierreMes
                WHERE estacion_id = ? AND entidad_id = ?
                  AND (afiliacion = ? OR (afiliacion IS NULL AND ? = ''))
                  AND mes = ?
            ");
            $cm->execute([$estacion_id, $entidad_id, $afiliacion, $afiliacion, $mes_origen]);
            if ($cm->fetch()) {
                echo json_encode(['status' => 'error', 'message' => 'El mes origen ya está cerrado']);
                exit;
            }

            $conn->prepare("
                INSERT INTO CV3_Transito
                    (estacion_id, entidad_id, afiliacion, corte_ref_id, cg_fecha,
                     mes_origen, mes_destino, monto_corte, monto_transito, monto_efectivo,
                     concepto, descripcion, estado)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'PENDIENTE')
            ")->execute([
                $estacion_id, $entidad_id, $afiliacion, $corte_ref_id, $cg_fecha,
                $mes_origen, $mes_destino, $monto_corte, $monto_transito, $monto_efectivo,
                $concepto, $descripcion
            ]);

            $id = (int)$conn->query("SELECT @@IDENTITY")->fetchColumn();

            echo json_encode(['status' => 'success', 'id' => $id, 'monto_efectivo' => $monto_efectivo]);

        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    // -------------------------------------------------------------------------
    // 13. CANCELAR TRÁNSITO CG V3
    //     POST /income/cancelar_transito_v3   Body: { id }
    // -------------------------------------------------------------------------
    public function cancelar_transito_v3(): void {
        ob_clean();
        header('Content-Type: application/json');

        $data = json_decode(file_get_contents('php://input'), true);
        $id   = (int)($data['id'] ?? 0);

        if (!$id) {
            echo json_encode(['status' => 'error', 'message' => 'Falta id']);
            exit;
        }

        try {
            $conn = $this->v3_conn();

            $s = $conn->prepare("SELECT * FROM CV3_Transito WHERE id = ?");
            $s->execute([$id]);
            $t = $s->fetch(PDO::FETCH_ASSOC);

            if (!$t) {
                echo json_encode(['status' => 'error', 'message' => 'Tránsito no encontrado']);
                exit;
            }
            if ($t['estado'] !== 'PENDIENTE') {
                echo json_encode(['status' => 'error', 'message' => 'Solo se puede cancelar un tránsito PENDIENTE']);
                exit;
            }

            // Verificar que ningún mes involucrado está cerrado
            $ck = $conn->prepare("
                SELECT id FROM Conciliacion_V3_CierreMes
                WHERE estacion_id = ? AND entidad_id = ?
                  AND (afiliacion = ? OR (afiliacion IS NULL AND ? = ''))
                  AND mes IN (?,?)
            ");
            $ck->execute([(int)$t['estacion_id'], (int)$t['entidad_id'], $t['afiliacion'], $t['afiliacion'], $t['mes_origen'], $t['mes_destino']]);
            if ($ck->fetch()) {
                echo json_encode(['status' => 'error', 'message' => 'No se puede cancelar: uno de los meses ya está cerrado']);
                exit;
            }

            $conn->prepare("UPDATE CV3_Transito SET estado = 'CANCELADO' WHERE id = ?")->execute([$id]);

            echo json_encode(['status' => 'success']);

        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    // =========================================================================
    // D I F E R I D O S   C G   V 3
    // =========================================================================

    private function v3_ensure_diferido_table(PDO $conn): void {
        $conn->exec("
            IF NOT EXISTS (SELECT * FROM sys.objects WHERE name = 'CV3_Diferido' AND type = 'U')
            CREATE TABLE CV3_Diferido (
                id                   INT IDENTITY(1,1) PRIMARY KEY,
                estacion_id          INT NOT NULL,
                entidad_id           INT NOT NULL,
                afiliacion           VARCHAR(50) NOT NULL,
                corte_ref_id         VARCHAR(200) NOT NULL,
                cg_fecha_origen      DATE NOT NULL,
                cg_fecha_destino     DATE NOT NULL,
                mes                  VARCHAR(7) NOT NULL,
                monto_corte          DECIMAL(18,2) NOT NULL,
                monto_diferido       DECIMAL(18,2) NOT NULL,
                concepto             VARCHAR(200),
                descripcion          VARCHAR(300),
                estado               VARCHAR(20) NOT NULL DEFAULT 'PENDIENTE',
                conciliacion_id_orig INT NULL,
                conciliacion_id_dest INT NULL,
                created_at           DATETIME DEFAULT GETDATE(),
                created_by           INT NULL
            )
        ");
    }

    // -------------------------------------------------------------------------
    //     GET /income/get_diferidos_cg_v3
    // -------------------------------------------------------------------------
    public function get_diferidos_cg_v3(): void {
        ob_clean();
        header('Content-Type: application/json');

        $estacion_id = (int)($_GET['estacion_id'] ?? 0);
        $entidad_id  = (int)($_GET['entidad_id']  ?? 0);
        $afiliacion  = trim($_GET['afiliacion']   ?? '');
        $year        = (int)($_GET['year']        ?? date('Y'));
        $month       = (int)($_GET['month']       ?? date('m'));

        if (!$estacion_id) {
            echo json_encode(['status' => 'error', 'message' => 'Falta estacion_id']);
            exit;
        }

        $mes = sprintf('%04d-%02d', $year, $month);
        $af  = $afiliacion !== '' ? ' AND afiliacion = ?' : '';

        try {
            $conn = $this->v3_conn();
            $this->v3_ensure_diferido_table($conn);

            $p = [$estacion_id, $entidad_id];
            if ($afiliacion !== '') $p[] = $afiliacion;
            $p[] = $mes;

            $s = $conn->prepare("
                SELECT id, corte_ref_id,
                       CONVERT(VARCHAR(10), cg_fecha_origen,  23) AS cg_fecha_origen,
                       CONVERT(VARCHAR(10), cg_fecha_destino, 23) AS cg_fecha_destino,
                       mes, monto_corte, monto_diferido,
                       concepto, descripcion, estado,
                       conciliacion_id_orig, conciliacion_id_dest
                FROM CV3_Diferido
                WHERE estacion_id = ? AND entidad_id = ? $af
                  AND mes = ? AND estado != 'CANCELADO'
                ORDER BY cg_fecha_origen ASC, id ASC
            ");
            $s->execute($p);
            $rows = $s->fetchAll(PDO::FETCH_ASSOC);

            $enDiferido = [];
            $fakes      = [];

            foreach ($rows as $r) {
                $r['id']             = (int)$r['id'];
                $r['monto_corte']    = (float)$r['monto_corte'];
                $r['monto_diferido'] = (float)$r['monto_diferido'];
                $ref = $r['corte_ref_id'];
                if (!isset($enDiferido[$ref])) $enDiferido[$ref] = [];
                $enDiferido[$ref][] = $r;
                $fakes[] = $r;
            }

            echo json_encode(['status' => 'success', 'en_diferido' => $enDiferido, 'fakes' => $fakes]);

        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    // -------------------------------------------------------------------------
    //     POST /income/crear_diferido_v3   Body: { estacion_id, entidad_id,
    //       afiliacion, corte_ref_id, cg_fecha_origen, cg_fecha_destino, mes,
    //       monto_corte, monto_diferido, concepto?, descripcion? }
    // -------------------------------------------------------------------------
    public function crear_diferido_v3(): void {
        ob_clean();
        header('Content-Type: application/json');

        $data = json_decode(file_get_contents('php://input'), true);

        $required = ['estacion_id','entidad_id','afiliacion','corte_ref_id',
                     'cg_fecha_origen','cg_fecha_destino','mes','monto_corte','monto_diferido'];
        foreach ($required as $k) {
            if (!isset($data[$k]) || $data[$k] === '') {
                echo json_encode(['status' => 'error', 'message' => "Falta campo: $k"]);
                exit;
            }
        }

        $estacion_id      = (int)$data['estacion_id'];
        $entidad_id       = (int)$data['entidad_id'];
        $afiliacion       = trim($data['afiliacion']);
        $corte_ref_id     = trim($data['corte_ref_id']);
        $cg_fecha_origen  = trim($data['cg_fecha_origen']);
        $cg_fecha_destino = trim($data['cg_fecha_destino']);
        $mes              = trim($data['mes']);
        $monto_corte      = (float)$data['monto_corte'];
        $monto_diferido   = round((float)$data['monto_diferido'], 2);
        $concepto         = trim($data['concepto']    ?? '');
        $descripcion      = trim($data['descripcion'] ?? '');

        if ($monto_diferido <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Monto diferido inválido']);
            exit;
        }
        if (substr($cg_fecha_origen,  0, 7) !== $mes ||
            substr($cg_fecha_destino, 0, 7) !== $mes) {
            echo json_encode(['status' => 'error', 'message' => 'Las fechas deben pertenecer al mes activo']);
            exit;
        }
        if ($cg_fecha_destino === $cg_fecha_origen) {
            echo json_encode(['status' => 'error', 'message' => 'La fecha destino debe ser diferente a la fecha origen']);
            exit;
        }

        try {
            $conn = $this->v3_conn();
            $this->v3_ensure_diferido_table($conn);

            // Mes no cerrado
            $cm = $conn->prepare("
                SELECT id FROM Conciliacion_V3_CierreMes
                WHERE estacion_id = ? AND entidad_id = ?
                  AND (afiliacion = ? OR (afiliacion IS NULL AND ? = ''))
                  AND mes = ?
            ");
            $cm->execute([$estacion_id, $entidad_id, $afiliacion, $afiliacion, $mes]);
            if ($cm->fetch()) {
                echo json_encode(['status' => 'error', 'message' => 'El mes ya está cerrado']);
                exit;
            }

            // Suma ya diferida para este corte
            $sd = $conn->prepare("
                SELECT ISNULL(SUM(monto_diferido), 0)
                FROM CV3_Diferido
                WHERE estacion_id = ? AND entidad_id = ? AND afiliacion = ?
                  AND corte_ref_id = ? AND estado != 'CANCELADO'
            ");
            $sd->execute([$estacion_id, $entidad_id, $afiliacion, $corte_ref_id]);
            $sumaDiferida = (float)$sd->fetchColumn();

            if ($sumaDiferida + $monto_diferido > $monto_corte + 0.005) {
                echo json_encode(['status' => 'error', 'message' => 'El monto diferido supera el disponible del corte']);
                exit;
            }

            $conn->prepare("
                INSERT INTO CV3_Diferido
                    (estacion_id, entidad_id, afiliacion, corte_ref_id,
                     cg_fecha_origen, cg_fecha_destino, mes,
                     monto_corte, monto_diferido, concepto, descripcion, estado)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,'PENDIENTE')
            ")->execute([
                $estacion_id, $entidad_id, $afiliacion, $corte_ref_id,
                $cg_fecha_origen, $cg_fecha_destino, $mes,
                $monto_corte, $monto_diferido, $concepto, $descripcion,
            ]);

            $id             = (int)$conn->query("SELECT @@IDENTITY")->fetchColumn();
            $monto_efectivo = round($monto_corte - $sumaDiferida - $monto_diferido, 2);

            echo json_encode(['status' => 'success', 'id' => $id, 'monto_efectivo' => $monto_efectivo]);

        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    // -------------------------------------------------------------------------
    //     POST /income/crear_diferido_dia_v3   Body: { estacion_id, entidad_id,
    //       afiliacion, mes, cg_fecha_destino, monto_diferido_total,
    //       descripcion?, cortes:[{ref,cg_fecha,monto_corte,concepto}] }
    // -------------------------------------------------------------------------
    public function crear_diferido_dia_v3(): void {
        ob_clean();
        header('Content-Type: application/json');

        $data = json_decode(file_get_contents('php://input'), true);

        $estacion_id      = (int)($data['estacion_id']          ?? 0);
        $entidad_id       = (int)($data['entidad_id']           ?? 0);
        $afiliacion       = trim($data['afiliacion']            ?? '');
        $mes              = trim($data['mes']                   ?? '');
        $cg_fecha_destino = trim($data['cg_fecha_destino']      ?? '');
        $monto_dif_total  = round((float)($data['monto_diferido_total'] ?? 0), 2);
        $descripcion      = trim($data['descripcion']           ?? '');
        $cortes           = $data['cortes']                     ?? [];

        if (!$estacion_id || !$entidad_id || !$mes || !$cg_fecha_destino || empty($cortes) || $monto_dif_total <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Datos incompletos']);
            exit;
        }
        if (substr($cg_fecha_destino, 0, 7) !== $mes) {
            echo json_encode(['status' => 'error', 'message' => 'Fecha destino fuera del mes activo']);
            exit;
        }

        try {
            $conn = $this->v3_conn();
            $this->v3_ensure_diferido_table($conn);

            $cm = $conn->prepare("
                SELECT id FROM Conciliacion_V3_CierreMes
                WHERE estacion_id = ? AND entidad_id = ?
                  AND (afiliacion = ? OR (afiliacion IS NULL AND ? = ''))
                  AND mes = ?
            ");
            $cm->execute([$estacion_id, $entidad_id, $afiliacion, $afiliacion, $mes]);
            if ($cm->fetch()) {
                echo json_encode(['status' => 'error', 'message' => 'El mes ya está cerrado']);
                exit;
            }

            // Filtrar cortes válidos (fecha origen ≠ destino y monto > 0)
            $cortes_validos = array_values(array_filter($cortes, function ($c) use ($cg_fecha_destino) {
                return !empty($c['ref'])
                    && (float)($c['monto_corte'] ?? 0) > 0
                    && trim($c['cg_fecha'] ?? '') !== $cg_fecha_destino;
            }));

            if (empty($cortes_validos)) {
                echo json_encode(['status' => 'error', 'message' => 'No hay cortes válidos para diferir']);
                exit;
            }

            $total_cortes = array_sum(array_map(fn($c) => (float)($c['monto_corte'] ?? 0), $cortes_validos));

            $conn->beginTransaction();

            $stmt = $conn->prepare("
                INSERT INTO CV3_Diferido
                    (estacion_id, entidad_id, afiliacion, corte_ref_id,
                     cg_fecha_origen, cg_fecha_destino, mes,
                     monto_corte, monto_diferido, concepto, descripcion, estado)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,'PENDIENTE')
            ");

            $ids_creados   = [];
            $suma_diferido = 0.0;
            $n_validos     = count($cortes_validos);

            foreach ($cortes_validos as $i => $corte) {
                $ref          = trim($corte['ref']       ?? '');
                $monto_corte  = (float)($corte['monto_corte'] ?? 0);
                $concepto     = trim($corte['concepto']  ?? '');
                $cg_fecha_org = trim($corte['cg_fecha']  ?? '');

                if ($i === $n_validos - 1) {
                    $monto_diferido = round($monto_dif_total - $suma_diferido, 2);
                } else {
                    $monto_diferido = round($monto_dif_total * ($monto_corte / $total_cortes), 2);
                }
                if ($monto_diferido > $monto_corte) $monto_diferido = $monto_corte;
                $monto_diferido = round($monto_diferido, 2);
                $suma_diferido  = round($suma_diferido + $monto_diferido, 2);

                $stmt->execute([
                    $estacion_id, $entidad_id, $afiliacion, $ref,
                    $cg_fecha_org, $cg_fecha_destino, $mes,
                    $monto_corte, $monto_diferido, $concepto, $descripcion,
                ]);
                $ids_creados[] = (int)$conn->query("SELECT @@IDENTITY")->fetchColumn();
            }

            $conn->commit();
            echo json_encode(['status' => 'success', 'creados' => count($ids_creados), 'ids' => $ids_creados]);

        } catch (PDOException $e) {
            if (isset($conn)) $conn->rollBack();
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    // -------------------------------------------------------------------------
    //     POST /income/mover_diferido_v3   Body: { id, nueva_fecha_destino }
    // -------------------------------------------------------------------------
    public function mover_diferido_v3(): void {
        ob_clean();
        header('Content-Type: application/json');

        $data             = json_decode(file_get_contents('php://input'), true);
        $id               = (int)($data['id']                ?? 0);
        $nueva_fecha_dest = trim($data['nueva_fecha_destino'] ?? '');

        if (!$id || !$nueva_fecha_dest) {
            echo json_encode(['status' => 'error', 'message' => 'Faltan parámetros']);
            exit;
        }

        try {
            $conn = $this->v3_conn();

            $s = $conn->prepare("SELECT * FROM CV3_Diferido WHERE id = ?");
            $s->execute([$id]);
            $d = $s->fetch(PDO::FETCH_ASSOC);

            if (!$d) {
                echo json_encode(['status' => 'error', 'message' => 'Diferido no encontrado']);
                exit;
            }
            if ($d['estado'] !== 'PENDIENTE') {
                echo json_encode(['status' => 'error', 'message' => 'Solo se puede mover un diferido PENDIENTE']);
                exit;
            }

            $mes = (string)$d['mes'];
            if (substr($nueva_fecha_dest, 0, 7) !== $mes) {
                echo json_encode(['status' => 'error', 'message' => 'La nueva fecha debe pertenecer al mismo mes']);
                exit;
            }

            $cg_fecha_origen = substr((string)$d['cg_fecha_origen'], 0, 10);
            if ($nueva_fecha_dest === $cg_fecha_origen) {
                echo json_encode(['status' => 'error', 'message' => 'La fecha destino no puede ser igual a la fecha origen']);
                exit;
            }

            $cm = $conn->prepare("
                SELECT id FROM Conciliacion_V3_CierreMes
                WHERE estacion_id = ? AND entidad_id = ?
                  AND (afiliacion = ? OR (afiliacion IS NULL AND ? = ''))
                  AND mes = ?
            ");
            $cm->execute([(int)$d['estacion_id'], (int)$d['entidad_id'], $d['afiliacion'], $d['afiliacion'], $mes]);
            if ($cm->fetch()) {
                echo json_encode(['status' => 'error', 'message' => 'El mes ya está cerrado']);
                exit;
            }

            $conn->prepare("UPDATE CV3_Diferido SET cg_fecha_destino = ? WHERE id = ?")
                 ->execute([$nueva_fecha_dest, $id]);

            echo json_encode(['status' => 'success']);

        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    // -------------------------------------------------------------------------
    //     POST /income/cancelar_diferido_v3   Body: { id }
    // -------------------------------------------------------------------------
    public function cancelar_diferido_v3(): void {
        ob_clean();
        header('Content-Type: application/json');

        $data = json_decode(file_get_contents('php://input'), true);
        $id   = (int)($data['id'] ?? 0);

        if (!$id) {
            echo json_encode(['status' => 'error', 'message' => 'Falta id']);
            exit;
        }

        try {
            $conn = $this->v3_conn();

            $s = $conn->prepare("SELECT * FROM CV3_Diferido WHERE id = ?");
            $s->execute([$id]);
            $d = $s->fetch(PDO::FETCH_ASSOC);

            if (!$d) {
                echo json_encode(['status' => 'error', 'message' => 'Diferido no encontrado']);
                exit;
            }
            if ($d['estado'] !== 'PENDIENTE') {
                echo json_encode(['status' => 'error', 'message' => 'Solo se puede cancelar un diferido PENDIENTE']);
                exit;
            }

            $mes = (string)$d['mes'];
            $cm  = $conn->prepare("
                SELECT id FROM Conciliacion_V3_CierreMes
                WHERE estacion_id = ? AND entidad_id = ?
                  AND (afiliacion = ? OR (afiliacion IS NULL AND ? = ''))
                  AND mes = ?
            ");
            $cm->execute([(int)$d['estacion_id'], (int)$d['entidad_id'], $d['afiliacion'], $d['afiliacion'], $mes]);
            if ($cm->fetch()) {
                echo json_encode(['status' => 'error', 'message' => 'El mes ya está cerrado']);
                exit;
            }

            $conn->prepare("UPDATE CV3_Diferido SET estado = 'CANCELADO' WHERE id = ?")
                 ->execute([$id]);

            echo json_encode(['status' => 'success']);

        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    // =========================================================================
    // DETECTAR / SINCRONIZAR CAMBIOS EN TESORERÍA
    // =========================================================================

    public function detectar_cambios_tes(): void {
        ob_clean();
        header('Content-Type: application/json');

        $estacion_id = (int)($_GET['estacion_id'] ?? 0);
        $entidad_id  = (int)($_GET['entidad_id']  ?? 0);
        $afiliacion  = trim($_GET['afiliacion']   ?? '');
        $year        = (int)($_GET['year']         ?? date('Y'));
        $month       = (int)($_GET['month']        ?? date('m'));

        if (!$estacion_id || !$entidad_id) {
            echo json_encode(['status' => 'error', 'message' => 'Faltan parámetros']);
            exit;
        }

        try {
            $conn = $this->v3_conn();

            $sql = "
                SELECT
                    d.id                          AS detalle_id,
                    d.grupo_id,
                    d.referencia_externa,
                    d.monto                       AS monto_concil,
                    CAST(COALESCE(m.abono, t.Depositos) AS DECIMAL(18,2)) AS monto_actual,
                    d.concepto,
                    CONVERT(VARCHAR(10), g.fecha_operativa, 23) AS fecha_operativa
                FROM Conciliacion_V3_Detalles d
                JOIN Conciliacion_V3_Grupos g   ON d.grupo_id = g.id
                LEFT JOIN [TG].[dbo].[movimientos_bancarios] m
                       ON d.referencia_externa LIKE 'mb[_]%'
                      AND m.id = TRY_CONVERT(INT, SUBSTRING(d.referencia_externa, 4, 50))
                LEFT JOIN Tesoreria_V3_Unificada t
                       ON d.referencia_externa NOT LIKE 'mb[_]%'
                      AND t.id_origen = d.referencia_externa
                WHERE d.origen       = 'TES'
                  AND g.estacion_id  = ?
                  AND g.entidad_id   = ?
                  AND (g.afiliacion  = ? OR (g.afiliacion IS NULL AND ? = ''))
                  AND YEAR(g.fecha_operativa)  = ?
                  AND MONTH(g.fecha_operativa) = ?
                  AND (m.id IS NOT NULL OR t.id_origen IS NOT NULL)
                  AND ABS(d.monto - CAST(COALESCE(m.abono, t.Depositos) AS DECIMAL(18,2))) > 0.001
            ";

            $stmt = $conn->prepare($sql);
            $stmt->execute([$estacion_id, $entidad_id, $afiliacion, $afiliacion, $year, $month]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $data = array_map(function($r) {
                return [
                    'detalle_id'      => (int)$r['detalle_id'],
                    'grupo_id'        => (int)$r['grupo_id'],
                    'referencia_externa' => $r['referencia_externa'],
                    'monto_concil'    => (float)$r['monto_concil'],
                    'monto_actual'    => (float)$r['monto_actual'],
                    'concepto'        => $r['concepto'],
                    'fecha_operativa' => $r['fecha_operativa'],
                ];
            }, $rows);

            echo json_encode(['status' => 'success', 'data' => $data]);

        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    public function sincronizar_tes_montos(): void {
        ob_clean();
        header('Content-Type: application/json');

        $data = json_decode(file_get_contents('php://input'), true);
        $ids  = array_filter(array_map('intval', $data['ids'] ?? []));

        if (empty($ids)) {
            echo json_encode(['status' => 'error', 'message' => 'Sin IDs']);
            exit;
        }

        try {
            $conn = $this->v3_conn();
            $conn->beginTransaction();

            $ph   = implode(',', array_fill(0, count($ids), '?'));

            // 1. Obtener montos actuales desde la fuente histórica o
            // movimientos_bancarios cuando la referencia es mb_<id>.
            $rows = $conn->prepare("
                SELECT d.id, d.grupo_id, CAST(COALESCE(m.abono, t.Depositos) AS DECIMAL(18,2)) AS monto_nuevo
                FROM Conciliacion_V3_Detalles d
                LEFT JOIN [TG].[dbo].[movimientos_bancarios] m
                       ON d.referencia_externa LIKE 'mb[_]%'
                      AND m.id = TRY_CONVERT(INT, SUBSTRING(d.referencia_externa, 4, 50))
                LEFT JOIN Tesoreria_V3_Unificada t
                       ON d.referencia_externa NOT LIKE 'mb[_]%'
                      AND t.id_origen = d.referencia_externa
                WHERE d.id IN ($ph) AND (m.id IS NOT NULL OR t.id_origen IS NOT NULL)
            ");
            $rows->execute($ids);
            $items = $rows->fetchAll(PDO::FETCH_ASSOC);

            // 2. Actualizar cada detalle
            $stmtUpd = $conn->prepare("UPDATE Conciliacion_V3_Detalles SET monto = ? WHERE id = ?");
            $gruposAfectados = [];
            foreach ($items as $item) {
                $stmtUpd->execute([(float)$item['monto_nuevo'], (int)$item['id']]);
                $gruposAfectados[(int)$item['grupo_id']] = true;
            }

            // 3. Recalcular total_tesoreria y diferencia en cada grupo afectado
            $stmtGrp = $conn->prepare("
                UPDATE Conciliacion_V3_Grupos
                SET total_tesoreria = (
                        SELECT ISNULL(SUM(monto), 0)
                        FROM Conciliacion_V3_Detalles
                        WHERE grupo_id = ? AND origen = 'TES'
                    ),
                    diferencia = (
                        SELECT ISNULL(SUM(monto), 0)
                        FROM Conciliacion_V3_Detalles
                        WHERE grupo_id = ? AND origen = 'TES'
                    ) - total_sistema
                WHERE id = ?
            ");
            foreach (array_keys($gruposAfectados) as $gid) {
                $stmtGrp->execute([$gid, $gid, $gid]);
            }

            $conn->commit();
            echo json_encode(['status' => 'success', 'actualizados' => count($items)]);

        } catch (PDOException $e) {
            $conn->rollBack();
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    // =========================================================================
    // FIN  C O N C I L I A C I Ó N   V 3
    // =========================================================================

    // =========================================================================
    // BUG REPORTER
    // =========================================================================
    public function report_bug_v3(): void
    {
        header('Content-Type: application/json');

        if (!preg_match('/POST/i', $_SERVER['REQUEST_METHOD'])) {
            echo json_encode(['status' => 'error', 'message' => 'Método no permitido']);
            exit;
        }

        $data        = json_decode(file_get_contents('php://input'), true) ?? [];
        $titulo      = htmlspecialchars(trim($data['titulo']      ?? ''), ENT_QUOTES, 'UTF-8');
        $descripcion = nl2br(htmlspecialchars(trim($data['descripcion'] ?? ''), ENT_QUOTES, 'UTF-8'));
        $reporter    = htmlspecialchars(trim($data['reporter']    ?? 'Usuario desconocido'), ENT_QUOTES, 'UTF-8');
        $pagina      = htmlspecialchars(trim($data['pagina']      ?? ''), ENT_QUOTES, 'UTF-8');
        $screenshots = array_slice((array)($data['screenshots'] ?? []), 0, 10);

        if (!$titulo && !trim($data['descripcion'] ?? '')) {
            echo json_encode(['status' => 'error', 'message' => 'Ingrese un título o descripción.']);
            exit;
        }

        $fecha      = date('d/m/Y H:i:s');
        $numCapt    = count($screenshots);
        $captInfo   = $numCapt > 0 ? "{$numCapt} captura(s) adjunta(s)" : 'Sin capturas';

        $body = "
        <div style='font-family:Arial,sans-serif;max-width:640px;margin:0 auto;border-radius:10px;overflow:hidden;border:1px solid #dee2e6;'>
            <div style='background:#c0392b;color:white;padding:18px 24px;'>
                <h2 style='margin:0;font-size:20px;'>&#x1F41E; Bug Report — Conciliación V3</h2>
            </div>
            <div style='background:#f8f9fa;padding:20px 24px;'>
                <table style='width:100%;border-collapse:collapse;font-size:14px;'>
                    <tr><td style='padding:8px 10px;font-weight:bold;color:#495057;width:150px;background:#fff;border:1px solid #dee2e6;'>Título</td><td style='padding:8px 10px;background:#fff;border:1px solid #dee2e6;'>{$titulo}</td></tr>
                    <tr><td style='padding:8px 10px;font-weight:bold;color:#495057;background:#f8f9fa;border:1px solid #dee2e6;'>Reportado por</td><td style='padding:8px 10px;background:#f8f9fa;border:1px solid #dee2e6;'>{$reporter}</td></tr>
                    <tr><td style='padding:8px 10px;font-weight:bold;color:#495057;background:#fff;border:1px solid #dee2e6;'>Página</td><td style='padding:8px 10px;background:#fff;border:1px solid #dee2e6;'>{$pagina}</td></tr>
                    <tr><td style='padding:8px 10px;font-weight:bold;color:#495057;background:#f8f9fa;border:1px solid #dee2e6;'>Fecha / Hora</td><td style='padding:8px 10px;background:#f8f9fa;border:1px solid #dee2e6;'>{$fecha}</td></tr>
                    <tr><td style='padding:8px 10px;font-weight:bold;color:#495057;background:#fff;border:1px solid #dee2e6;'>Capturas</td><td style='padding:8px 10px;background:#fff;border:1px solid #dee2e6;'>{$captInfo}</td></tr>
                </table>
                <div style='margin-top:16px;'>
                    <strong style='color:#495057;font-size:14px;'>Descripción:</strong>
                    <div style='background:#fff;border:1px solid #dee2e6;border-radius:4px;padding:12px;margin-top:8px;font-size:14px;color:#212529;line-height:1.6;'>{$descripcion}</div>
                </div>
            </div>
            <div style='background:#e9ecef;padding:10px 24px;text-align:center;font-size:11px;color:#6c757d;'>
                TotalGas &mdash; Sistema de Gestión &nbsp;|&nbsp; Reporte automático
            </div>
        </div>";

        // Guardar capturas en /tmp
        $tmpFiles = [];
        $tmpDir   = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        foreach ($screenshots as $i => $dataUrl) {
            if (empty($dataUrl) || strpos($dataUrl, 'base64,') === false) continue;
            $b64 = explode('base64,', $dataUrl, 2)[1] ?? '';
            if (!$b64) continue;
            $path = $tmpDir . 'bugreport_' . time() . '_' . $i . '.png';
            if (@file_put_contents($path, base64_decode($b64, true)) !== false) {
                $tmpFiles[] = $path;
            }
        }

        // Enviar con PHPMailer directamente (sin límite de adjuntos)
        $ok = false;
        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->SMTPDebug  = \PHPMailer\PHPMailer\SMTP::DEBUG_OFF;
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port       = 465;
            $mail->Username   = 'totalgasdesarrollo@gmail.com';
            $mail->Password   = 'bdppgxrwzhmyfrmf';
            $mail->CharSet    = 'UTF-8';
            $mail->Encoding   = 'base64';
            $mail->isHTML(true);
            $mail->setLanguage('es');
            $mail->setFrom('totalgasdesarrollo@gmail.com', 'TotalGas | Bug Reporter');
            $mail->addAddress('daniel.ramirez@totalgas.com');
            $mail->Subject = "Bug Report: {$titulo}";
            $mail->Body    = $body;
            $mail->AltBody = strip_tags($body);
            foreach ($tmpFiles as $i => $path) {
                $mail->addAttachment($path, 'captura_' . ($i + 1) . '.png');
            }
            $ok = $mail->send();
        } catch (\Exception $e) {
            error_log('BugReporter mailer error: ' . $e->getMessage());
        }

        foreach ($tmpFiles as $path) { @unlink($path); }

        echo json_encode($ok
            ? ['status' => 'success', 'message' => 'Reporte enviado correctamente.' . ($tmpFiles ? ' (' . count($tmpFiles) . ' captura(s) adjunta(s))' : '')]
            : ['status' => 'error',   'message' => 'No se pudo enviar el reporte.']
        );
        exit;
    }

    // =========================================================================
    // AMEX COMISIONES — Carga del reporte de envíos (CSV / XLSX)
    // =========================================================================

    public function upload_amex_comisiones() {
        set_time_limit(0);
        ini_set('memory_limit', '-1');
        ob_clean();
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['status' => 'error', 'message' => 'Método no permitido']); exit;
        }

        // ── Recibir archivo (base64 o upload tradicional) ──────────────────
        $originalName = $_POST['file_name'] ?? ($_FILES['report_file']['name'] ?? 'amex_comisiones.csv');
        $extension    = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        $targetDir = __DIR__ . '/../uploads/AMEX_COMISIONES/' . date('Y') . '/' . date('m') . '/';
        if (!file_exists($targetDir)) mkdir($targetDir, 0777, true);

        $baseName = basename((string)$originalName);
        // Normaliza nombre para evitar problemas con caracteres especiales en filesystem/IIS
        $baseName = preg_replace('/[^A-Za-z0-9._-]/', '_', $baseName);
        if ($baseName === '' || $baseName === null) {
            $baseName = 'amex_comisiones.' . ($extension ?: 'csv');
        }
        $safeName  = date('His') . '_' . $baseName;
        $filePath  = $targetDir . $safeName;

        if (!empty($_POST['file_data'])) {
            if (file_put_contents($filePath, base64_decode($_POST['file_data'])) === false) {
                echo json_encode(['status' => 'error', 'message' => 'Error al guardar el archivo']); exit;
            }
        } elseif (isset($_FILES['report_file']) && $_FILES['report_file']['error'] === UPLOAD_ERR_OK) {
            if (!move_uploaded_file($_FILES['report_file']['tmp_name'], $filePath)) {
                echo json_encode(['status' => 'error', 'message' => 'Error al mover el archivo']); exit;
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'No se recibió ningún archivo']); exit;
        }

        // ── Parsear filas del reporte ───────────────────────────────────────
        $rows    = [];
        $headers = [];

        if (in_array($extension, ['xlsx', 'xls'])) {
            // Leer con PhpSpreadsheet
            try {
                $spreadsheet = IOFactory::load($filePath);
                $sheet       = $spreadsheet->getActiveSheet();
                $allRows     = $sheet->toArray(null, true, true, false);
                // Buscar fila de encabezados: contiene "Número de establecimiento que envía"
                $headerIdx = null;
                foreach ($allRows as $i => $row) {
                    foreach ($row as $cell) {
                        if ($cell !== null && stripos((string)$cell, 'establecimiento') !== false) {
                            $headerIdx = $i; break 2;
                        }
                    }
                }
                if ($headerIdx === null) {
                    echo json_encode(['status' => 'error', 'message' => 'No se encontró la fila de encabezados en el archivo']); exit;
                }
                $headers  = array_values($allRows[$headerIdx]);
                $dataRows = array_slice($allRows, $headerIdx + 1);
                foreach ($dataRows as $row) {
                    if (empty(array_filter($row, fn($v) => $v !== null && $v !== ''))) continue;
                    $rows[] = array_values($row);
                }
            } catch (\Exception $e) {
                echo json_encode(['status' => 'error', 'message' => 'Error leyendo el archivo Excel: ' . $e->getMessage()]); exit;
            }
        } else {
            // Leer CSV — detectar encabezados en la fila que empiece con "Número de establecimiento"
            $handle = fopen($filePath, 'r');
            if (!$handle) {
                echo json_encode(['status' => 'error', 'message' => 'No se pudo abrir el archivo']); exit;
            }
            $headerFound = false;
            while (($line = fgetcsv($handle, 0, ',')) !== false) {
                if (!$headerFound) {
                    // Detectar fila de encabezados buscando "establecimiento" en cualquier celda
                    $isHeader = false;
                    foreach ($line as $cell) {
                        if (stripos((string)$cell, 'establecimiento') !== false) {
                            $isHeader = true; break;
                        }
                    }
                    if ($isHeader) {
                        $headerFound = true;
                        $headers = $line;  // capturar fila de encabezados
                    }
                    continue;
                }
                if (empty(array_filter($line, fn($v) => trim($v) !== ''))) continue;
                $rows[] = $line;
            }
            fclose($handle);
        }

        if (empty($rows)) {
            echo json_encode(['status' => 'error', 'message' => 'No se encontraron filas de datos en el archivo']); exit;
        }

        // ── Mapa de columnas por nombre (tolera orden diferente y columnas extra) ──
        // Palabras clave para identificar cada columna necesaria:
        //   establecimiento → 'establecimiento'
        //   fecha transacción → 'transac'
        //   cargos totales → 'cargos'
        //   fecha de pago → 'pago' (sin 'monto')
        //   monto del pago → 'monto' + 'pago'
        //   monto del descuento / comisión → 'descuento' o 'comisi'
        //   IVA → 'iva'
        $colMap = [
            'establecimiento' => null,
            'fecha_trans'     => null,
            'cargos'          => null,
            'fecha_pago'      => null,
            'monto_pago'      => null,
            'comision'        => null,
            'iva'             => null,
            'numero_factura'  => null,
        ];
        foreach ($headers as $idx => $h) {
            $h = trim((string)$h);
            $h = function_exists('mb_strtolower') ? mb_strtolower($h) : strtolower($h);
            if (stripos($h, 'factura') !== false)                                         $colMap['numero_factura']  = $idx;
            elseif (stripos($h, 'establecimiento') !== false)                             $colMap['establecimiento'] = $idx;
            elseif (stripos($h, 'transac') !== false)                                     $colMap['fecha_trans']     = $idx;
            elseif (stripos($h, 'cargos') !== false)                                      $colMap['cargos']          = $idx;
            elseif (stripos($h, 'monto') !== false && stripos($h, 'pago') !== false)      $colMap['monto_pago']      = $idx;
            elseif (stripos($h, 'pago') !== false)                                        $colMap['fecha_pago']      = $idx;
            elseif (stripos($h, 'descuento') !== false || stripos($h, 'comisi') !== false) $colMap['comision']       = $idx;
            elseif (stripos($h, 'iva') !== false)                                         $colMap['iva']             = $idx;
        }
        // Para AMEX Comisiones exigimos encabezados reales (sin fallback por posición)
        if (in_array(null, $colMap, true)) {
            $faltantes = [];
            foreach ($colMap as $k => $v) {
                if ($v === null) $faltantes[] = $k;
            }
            echo json_encode([
                'status'  => 'error',
                'message' => 'No se detectaron columnas requeridas en el archivo AMEX: ' . implode(', ', $faltantes)
            ]);
            exit;
        }

        // ── Helper: parsear montos AMEX ─────────────────────────────────────
        // Formatos: "MXN$ 2,190.19" | "(MXN$ 24.09)" | MXN$ 879.26
        $parseMonto = function(string $raw): float {
            $s = preg_replace('/[^0-9.()\-]/', '', str_replace(',', '', $raw));
            // Paréntesis = positivo (son descuentos que sumamos como positivos)
            $s = trim($s, '()');
            return (float)$s;
        };

        // ── Helper: parsear fecha DD/M/YYYY o DD/MM/YYYY → Y-m-d ───────────
        $parseFecha = function(string $raw): ?string {
            $raw = trim($raw);
            if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $raw, $m)) {
                return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
            }
            return null;
        };

        // ── Helper: normalizar establecimiento (quitar ceros líderes) ───────
        $normalizeAfil = function(string $raw): string {
            $t = trim($raw);
            return ltrim($t, '0') ?: $t;
        };
        $normalizeFactura = function(string $raw): string {
            return trim($raw);
        };

        // ── Conexión BD ─────────────────────────────────────────────────────
        try {
            $conn = new PDO("sqlsrv:Server=192.168.0.6;Database=TG", "cguser", "sahei1712");
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (\Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'Error de conexión a BD: ' . $e->getMessage()]); exit;
        }

        try {
            // ── Insertar log de upload ───────────────────────────────────────
            $uploadedBy = $_SESSION['tg_user']['Id'] ?? null;
            $conn->prepare("INSERT INTO AMEX_Envios_Uploads (nombre_archivo, anio, mes, total_filas, uploaded_by)
                            VALUES (?, ?, ?, 0, ?)")
                 ->execute([$originalName, date('Y'), date('m'), $uploadedBy]);
            $uploadId = (int)$conn->lastInsertId();

            // ── Procesar e insertar filas ────────────────────────────────────
            $stmtCheck = $conn->prepare(
                "SELECT COUNT(*) FROM AMEX_Envios
                 WHERE establecimiento = ? AND fecha_transaccion = ? AND fecha_pago = ? AND cargos_totales = ? AND numero_factura = ?"
            );
            $stmtIns = $conn->prepare(
                "INSERT INTO AMEX_Envios
                    (upload_id, establecimiento, fecha_transaccion, fecha_pago, cargos_totales, monto_pago, comision, iva, numero_factura)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );

            $inserted = 0;
            $skipped  = 0;
            $errors   = 0;

            foreach ($rows as $row) {
                // Columnas leídas por nombre de encabezado (orden flexible, columnas extra ignoradas)
                $establecimiento  = $normalizeAfil((string)($row[$colMap['establecimiento']] ?? ''));
                $fechaTransStr    = (string)($row[$colMap['fecha_trans']]     ?? '');
                $cargosStr        = (string)($row[$colMap['cargos']]          ?? '');
                $fechaPagoStr     = (string)($row[$colMap['fecha_pago']]      ?? '');
                $montoPagoStr     = (string)($row[$colMap['monto_pago']]      ?? '');
                $comisionStr      = (string)($row[$colMap['comision']]        ?? '');
                $ivaStr           = (string)($row[$colMap['iva']]             ?? '');
                $numeroFacturaRaw = (string)($row[$colMap['numero_factura']]  ?? '');

                if ($establecimiento === '') { $errors++; continue; }
                $numeroFactura = $normalizeFactura($numeroFacturaRaw);
                if ($numeroFactura === '') { $errors++; continue; }

                $fechaTrans = $parseFecha($fechaTransStr);
                $fechaPago  = $parseFecha($fechaPagoStr);
                if (!$fechaTrans || !$fechaPago) { $errors++; continue; }

                $cargos   = $parseMonto($cargosStr);
                $montoPago = $parseMonto($montoPagoStr);
                $comision = $parseMonto($comisionStr);
                $iva      = $parseMonto($ivaStr);

                if ($cargos <= 0 && $comision <= 0 && $iva <= 0) { $skipped++; continue; } // fila vacía sin valores monetarios

                // Antiduplicado
                $stmtCheck->execute([$establecimiento, $fechaTrans, $fechaPago, $cargos, $numeroFactura]);
                if ((int)$stmtCheck->fetchColumn() > 0) { $skipped++; continue; }

                try {
                    $stmtIns->execute([$uploadId, $establecimiento, $fechaTrans, $fechaPago, $cargos, $montoPago, $comision, $iva, $numeroFactura]);
                    $inserted++;
                } catch (\Exception $e) {
                    // Violación de índice único (race condition) — tratar como duplicado
                    $skipped++;
                }
            }

            // Actualizar total_filas en el log
            $conn->prepare("UPDATE AMEX_Envios_Uploads SET total_filas = ? WHERE id = ?")
                 ->execute([$inserted, $uploadId]);

            echo json_encode([
                'status'   => 'success',
                'inserted' => $inserted,
                'skipped'  => $skipped,
                'errors'   => $errors,
                'message'  => "$inserted registros nuevos insertados. $skipped duplicados omitidos."
                            . ($errors > 0 ? " $errors filas con errores de formato." : '')
            ]);
            exit;
        } catch (\Throwable $e) {
            echo json_encode(['status' => 'error', 'message' => 'Error AMEX comisiones: ' . $e->getMessage()]);
            exit;
        }
    }

    // =========================================================================
    // AMEX COMISIONES — Consulta agrupada por establecimiento + fecha_pago
    // =========================================================================

    public function get_amex_comisiones() {
        ob_clean();
        header('Content-Type: application/json');

        $year  = $_GET['year']  ?? date('Y');
        $month = $_GET['month'] ?? date('m');

        try {
            $conn = new PDO("sqlsrv:Server=192.168.0.6;Database=TG", "cguser", "sahei1712");
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Verificar si hay datos para este mes (para saber si se subió el archivo)
            $stmtCheck = $conn->prepare(
                "SELECT COUNT(*) FROM AMEX_Envios
                 WHERE YEAR(fecha_pago) = ? AND MONTH(fecha_pago) = ?"
            );
            $stmtCheck->execute([$year, $month]);
            $totalRegistros = (int)$stmtCheck->fetchColumn();

            // Datos agrupados: un registro por establecimiento + fecha_pago
            $stmt = $conn->prepare(
                "SELECT
                    establecimiento,
                    CONVERT(VARCHAR(10), fecha_pago, 23) AS fecha_pago,
                    SUM(cargos_totales) AS total_bruto,
                    SUM(monto_pago)     AS total_neto,
                    SUM(comision)       AS total_comision,
                    SUM(iva)            AS total_iva
                 FROM AMEX_Envios
                 WHERE YEAR(fecha_pago) = ? AND MONTH(fecha_pago) = ?
                 GROUP BY establecimiento, fecha_pago
                 ORDER BY fecha_pago, establecimiento"
            );
            $stmt->execute([$year, $month]);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Último upload del mes
            $stmtUpload = $conn->prepare(
                "SELECT TOP 1 nombre_archivo, created_at, total_filas
                 FROM AMEX_Envios_Uploads
                 WHERE anio = ? AND mes = ?
                 ORDER BY created_at DESC"
            );
            $stmtUpload->execute([$year, $month]);
            $ultimoUpload = $stmtUpload->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'status'         => 'success',
                'data'           => $data,
                'total_registros'=> $totalRegistros,
                'ultimo_upload'  => $ultimoUpload ?: null
            ]);
        } catch (\Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage(), 'data' => []]);
        }
        exit;
    }
}
