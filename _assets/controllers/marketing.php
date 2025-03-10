<?php
require_once('./_assets/classes/code128.php');

class Marketing{
    public $twig;
    public $route;
    public DespachosModel $despachosModel;
    public int $todayInt;
    public ClientesModel $clientsModel;
    public ClientesVehiculosModel $clientsVehiclesModel;
    public ClientesChoferesModel $clientesChoferesModel;

    /**
     * @param $twig
     */
    public function __construct($twig) {
        $this->despachosModel        = new DespachosModel;
        $this->twig                  = $twig;
        $this->clientsModel          = new ClientesModel;
        $this->clientsVehiclesModel  = new ClientesVehiculosModel;
        $this->clientesChoferesModel = new ClientesChoferesModel;
        $this->todayInt              = (new DateTime())->diff(new DateTime('1900-01-01'))->days + 1;
        $this->route                 = 'views/marketing/';
    }

    /**
     * @return void
     */
    public function client_cards() : void {
        echo $this->twig->render($this->route . 'client_cards.html');
    }

    /**
     * @return void
     * @throws Exception
     */
    function findClientForm() : void {
        if ($rs = $this->clientsModel->findClientForm($_POST['codcli'], $_POST['tar'])) {
            json_output($rs);
        }
        json_output(0);
    }

    /**
     * @return void
     * @throws Exception
     */
    function findClientTable(): void {
        $codcli = $_POST['codcli'] ?? false;
        $client = $this->clientsModel->getClient($codcli);

        $client_vehicles = $this->clientsVehiclesModel->getVehiclesClient($codcli);
        $client_drivers = $this->clientesChoferesModel->getDriversClient($codcli);

        json_output($this->twig->render($this->route . 'findClientTable.html', compact('client', 'client_vehicles', 'client_drivers')));
    }

    /**
     * @param $card
     * @param $codcli
     * @return void
     * @throws Exception
     */
    function print_card($card, $codcli) : void {

        $card_info = $this->clientsVehiclesModel->getVehiclesInfo($card, $codcli);

        $zone1_2 = [4,5,6,8,10,11,12,13,18,22,30];
        $zone3 = [23,24,25,26,27,28];
        $found = false;
        $zone = '';

        // Divide el string en un array de números
        $stations_assigned = explode(", ", $card_info['codgas_concatenados']);

        // Itera sobre las estaciones asignadas en el string
        foreach ($stations_assigned as $station) {
            // Convierte la estación a entero
            $station = intval($station);

            // Verifica si el número está en el arreglo $zone1_2
            if (in_array($station, $zone1_2)) {
                $found = true;
                $zone = 'ZONA 1 | ZONA 2';
                break; // Puedes salir del bucle una vez que encuentres una coincidencia
            }
        }

        // Si no es de zona 1 o zona 2, entonces buscamos en zona 3
        if (!$found) {
            // Itera sobre las estaciones asignadas en el string
            foreach ($stations_assigned as $station) {
                // Convierte la estación a entero
                $station = intval($station);

                // Verifica si el número está en el arreglo $zone1_2
                if (in_array($station, $zone3)) {
                    $found = true;
                    $zone = 'ZONA 3';
                    break; // Puedes salir del bucle una vez que encuentres una coincidencia
                }
            }
        }

        $pdf = new PDF_Code128();
        // Establecer el tamaño de la página en milimetros (Ancho x Alto)
        $pdf->AddPage('L', array(85, 54));
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false);
        // Recuadro de la fotografia
        $pdf->Image($_SERVER['DOCUMENT_ROOT']. '/_assets/images/Tarjeta Pago Electronico.jpg', 0, 0, 85, 54);
        $pdf->SetFont('Arial','',8);
        // cAMBIAR COLOR DE TEXTO
        $pdf->SetTextColor(255,255,255);
        // $pdf->SetTextColor(0,0,0);
        // Tipo
        $pdf->SetXY(0,19);
        $pdf->multiCell(50, 4, mb_convert_encoding(strtoupper($card_info['Tipo']), 'ISO-8859-1'), 0, 'C');
        // ZONA
        $pdf->SetXY(0,15);
        $pdf->multiCell(50, 4, mb_convert_encoding(strtoupper($zone), 'ISO-8859-1'), 0, 'C');
        // Tarjeta
        $pdf->SetXY(30,12);
        $pdf->multiCell(50, 4, $card_info['Tarjeta'] , 0, 'R');
        // Placas
        $pdf->SetXY(30,16);
        $pdf->multiCell(50, 4, $card_info['Placas'] , 0, 'R');
        // Placas
        $pdf->SetXY(30,20);
        $pdf->multiCell(50, 4, $card_info['Descripcion'] , 0, 'R');
        // Nombre
        $pdf->SetFont('Arial','b',8);
        $pdf->SetXY(0,38);
        $pdf->multiCell(85, 4, mb_convert_encoding($card_info['Cliente'], 'ISO-8859-1'), 0, 'C');
        $pdf->SetFont('Arial','',7);
        $pdf->multiCell(85, 4, mb_convert_encoding('PRESENTAR ESTA TARJETA ANTES DE INICIAR SU CARGA', 'ISO-8859-1'), 0, 'C');
        $pdf->Output();
    }

    /**
     * @param $card
     * @param $codcli
     * @return void
     */
    function print_card_2($card, $codcli) : void {
        echo '<pre>';
        var_dump($card, $codcli);
        die();
    }

    /**
     * @param $codcli
     * @param $nrocho
     * @return void
     * @throws Exception
     */
    function print_driver_card($codcli, $nrocho) : void {
        $driver_info = $this->clientesChoferesModel->getDriverInfo($codcli, $nrocho);
        $pdf = new PDF_Code128();
        // Establecer el tamaño de la página en milimetros (Ancho x Alto)
        $pdf->AddPage('L', array(85, 54));
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false);

        // Fondo de tarjeta de chofer
        $pdf->Image($_SERVER['DOCUMENT_ROOT']. '/_assets/images/driver.jpg', 0, 0, 85, 54);


        $pdf->SetFont('Arial','B',14);
        // cAMBIAR COLOR DE TEXTO
        $pdf->SetTextColor(255,255,255);
        // $pdf->SetTextColor(0,0,0);
        // Tipo
        $pdf->SetXY(55,5);
        $pdf->multiCell(30, 4, mb_convert_encoding(strtoupper($driver_info['Tipo']), 'ISO-8859-1'), 0, 'C');

        $pdf->SetFont('Arial','',10);
        $pdf->SetXY(0,17);
        $pdf->multiCell(60, 4, mb_convert_encoding(strtoupper($driver_info['Cliente']), 'ISO-8859-1'), 0, 'C');

        $pdf->SetFont('Arial','',10);
        $pdf->SetXY(3,35);
        $pdf->multiCell(57, 4, mb_convert_encoding('Cliente: ' . strtoupper($driver_info['codcli']), 'ISO-8859-1'), 0, 'L');

        $pdf->SetFont('Arial','',10);
        $pdf->SetXY(3,40);
        $pdf->multiCell(57, 4, mb_convert_encoding('Chofer: ' . strtoupper($driver_info['den']), 'ISO-8859-1'), 0, 'L');

        $pdf->SetFont('Arial','',10);
        // CAMBIAR COLOR DE TEXTO
        $pdf->SetTextColor(0,0,0);
        $pdf->SetXY(61,40);
        $pdf->multiCell(20, 4, mb_convert_encoding(date('d/m/Y'), 'ISO-8859-1'), 0, 'L');

        $pdf->Output();
    }

    /**
     * @param $card
     * @param $codcli
     * @return void
     * @throws Exception
     */
    function print_sticker($station, $from, $until, $image = false) : void {

        // Crear una instancia de FPDF
        $pdf = new PDF_Code128();

        // Establecer los márgenes
        $pdf->SetMargins(5, 5, 5);  // Margen izquierdo, margen superior, margen derecho

        // Establecer el margen inferior
        $pdf->SetAutoPageBreak(true, 5);  // Activar los saltos automáticos de página y establecer el margen inferior a 5 mm

        // Creamos un ciclo for
        for ($i = $from; $i <= $until; $i++) {
            // Establecer el tamaño de la página en milimetros (Ancho x Alto)
            $pdf->AddPage('L', array(74, 48));

            if ($image) {
                // Fondo
                $pdf->Image($_SERVER['DOCUMENT_ROOT']. '/_assets/images/'. $image .'.png', 0, 0, 48, 48);
            } else {
                // Establecer el tamaño de la letra y el tipo de letra
                $pdf->SetFont('Arial','B',7);
            }

        // Logo
        $pdf->SetXY(3,3);
        $pdf->multiCell(25, 10, '', 1, 'R');
        $pdf->Image($_SERVER['DOCUMENT_ROOT']. '/_assets/images/logo BN.jpg', 3.5 ,3.5, 24, 9);


        $pdf->Code128(8,26,'321321321',50,15);

        // Generar el archivo PDF
        $pdf->Output();
    }

    /**
     * @return void
     * @throws Exception
     */
    function stickerModal() : void {
        $months = array(
            1 => 'enero',
            2 => 'febrero',
            3 => 'marzo',
            4 => 'abril',
            5 => 'mayo',
            6 => 'junio',
            7 => 'julio',
            8 => 'agosto',
            9 => 'septiembre',
            10 => 'octubre',
            11 => 'noviembre',
            12 => 'diciembre'
        );
        $tarjetaArray = array();
        $engomadoArray = array();

        foreach ($_POST as $key => $value) {
            if (str_starts_with($key, "tarjeta_")) {
                $NV = substr($key, strlen("tarjeta_"));
                $tarjetaArray[] = $NV;

            } elseif (str_starts_with($key, "engomado_")) {
                $NV = substr($key, strlen("engomado_"));
                $engomadoArray[] = $NV;
            }
        }

        $nips_string = '';
        $cards_string = '';
        foreach ($tarjetaArray as $nroveh) {
            $card = $this->clientsVehiclesModel->getCard($_POST['codcli'], $nroveh);
            $cards_string .= $card['tar'] . '('. $card['nip_decrypted'] .'),';
            if ($card['nip_decrypted']  > 0) {
                $nips_string .= $card['nip_decrypted'] . ',';
            }
        }

        $stickers_string = '';
        foreach ($engomadoArray as $nroveh) {
            $sticker = $this->clientsVehiclesModel->getStickers($_POST['codcli'], $nroveh);
            $stickers_string .= $sticker['Economico'] . '('. $sticker['nip_decrypted'] .'),';
            if ($sticker['nip_decrypted']  > 0) {
                $nips_string .= $sticker['nip_decrypted'] . ',';
            }
        }

        $cards_string    = substr($cards_string, 0, -1);
        $stickers_string = substr($stickers_string, 0, -1);
        $nips_string     = substr($nips_string, 0, -1);

        $folio = $this->clientsVehiclesModel->getFolio();
        $folioString = str_pad((string)$folio, 7, '0', STR_PAD_LEFT);

        $pdf = new PDF_Code128();

        // Establecer el tamaño de la página en milimetros (Ancho x Alto)
        $pdf->AddPage('P');

        // Fondo
        $pdf->SetXY(0,0);
        $pdf->Image($_SERVER['DOCUMENT_ROOT']. '/_assets/images/fondo_politica.png', 0, 0, 210, 297);

        // Vamos a ir ingresando los datos
        // Establecer el tamaño de la letra y el tipo de letra
        $pdf->SetFont('Arial','B',10);
        $pdf->SetTextColor(25, 80, 200);

        // Lugar y fecha
        $pdf->SetXY(25,45);
        $pdf->multiCell(156, 10, mb_convert_encoding('Ciudad Juárez, Chihuahua, México, a ', 'ISO-8859-1') . date('d') . ' de ' . $months[date('n')] . ' del ' . date('Y'), 0, 'R');

        // A quien corresponda
        $pdf->SetFont('Arial','B',10);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetXY(35,80);
        $pdf->multiCell(156, 10, mb_convert_encoding('A quien corresponda: ', 'ISO-8859-1'), 0, 'L');

        // Por medio de la presente
        $pdf->SetFont('Arial','',10);
        $pdf->SetXY(35,95);
        $pdf->multiCell(143, 6, mb_convert_encoding("Por medio de la presente se realiza la entrega de los siguientes engomados para uso y resguardo del cliente " . strtoupper(trim($_POST['client_name'])), 'ISO-8859-1'), 0, 'L');


        $pdf->Ln(10); // Salto de línea

        // Ancho total de las celdas
        $cellWidth = 138;

        $pdf->SetFont('Arial', 'B', 9);
        // Celda de encabezado
        $pdf->Cell(25, 5, '', 0, 0, 'C');$pdf->Cell($cellWidth, 7, 'TARJETAS: ', 1, 1, 'C');
        // Celda de contenido
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(25, 5, '', 0, 0, 'C');$pdf->MultiCell($cellWidth, 5, $cards_string, 1, 'C');
        $pdf->Ln(3); // Salto de línea adicional para separar tablas
        $pdf->Cell(25, 5, '', 0, 0, 'C');$pdf->SetFont('Arial', 'B', 9); // Restablecer la fuente a negrita para el siguiente encabezado

        // Celda de encabezado
        $pdf->Cell($cellWidth, 7, 'ENGOMADOS: ', 1, 1, 'C');
        // Celda de contenido
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(25, 5, '', 0, 0, 'C');$pdf->MultiCell($cellWidth, 5, $stickers_string, 1, 'C');
        $pdf->Ln(3); // Salto de línea adicional para separar tablas
        $pdf->Cell(25, 5, '', 0, 0, 'C');$pdf->SetFont('Arial', 'B', 9); // Restablecer la fuente a negrita para el siguiente encabezado

        // Celda de encabezado
        $pdf->Cell($cellWidth, 7, 'NIPS: ', 1, 1, 'C');
        // Celda de contenido
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(25, 5, '', 0, 0, 'C');$pdf->MultiCell($cellWidth, 5, $nips_string, 1, 'C');
        $pdf->Ln(10); // Salto de línea adicional para separar tablas
        $pdf->Cell(25, 5, '', 0, 0, 'C');$pdf->SetFont('Arial', 'B', 10); // Restablecer la fuente a negrita para el siguiente encabezado

        // Celda para "NIP:"
        $pdf->Cell(138, 10);
        $pdf->Ln(10); // Salto de línea
        $pdf->Cell(26, 10);
        $pdf->multiCell(138, 6, mb_convert_encoding('Por este medio se hace del conocimiento del cliente que las tarjetas y/o Engomados que el cliente recibe a partir de la firma de este documento quedan bajo resguardo y responsabilidad en el uso y manejo de los mismos, por lo anterior Díaz Gas S.A. de C.V., se deslinda de cualquier responsabilidad que pueda suscitarse por el mal uso de las tecnologías entregadas.', 'ISO-8859-1'), 0, 0);
        $pdf->Ln(); // Salto de línea


        $pdf->Cell(26, 10);
        $pdf->Ln(10); // Salto de línea
        $pdf->Cell(26, 10);
        $pdf->Cell(138, 10, 'Recibe de conformidad', 'T', 0, 'C');
        $pdf->Ln(10); // Salto de línea
        $pdf->Cell(26, 10);

        $pdf->Cell(26, 5, '', 'B');
        $pdf->Cell(85, 8, strtoupper($_SESSION['tg_user']['Nombre']), 0, 0, 'C');
        $pdf->Cell(26, 5, '', 'B');
        $pdf->Ln(); // Salto de línea
        $pdf->Cell(26, 10);
        $pdf->Cell(138, 10, 'Entrega CXC', 0, 0, 'C');


        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetXY(160,270);
        $pdf->multiCell(143, 6, 'Folio. ' . $folioString, 0, 'L');

        // Agregar una página para la política
        $pdf->AddPage();
        $pdf->SetTextColor(0, 0, 0);

        // Fondo
        $pdf->SetXY(0,0);
        $pdf->Image($_SERVER['DOCUMENT_ROOT']. '/_assets/images/fondo_politica.png', 0, 0, 210, 297);

        // Lugar y fecha
        $pdf->SetFont('Arial','B',13);
        $pdf->SetXY(25,60);
        $pdf->multiCell(156, 10, mb_convert_encoding('Políticas de Engomados TOTALGAS®', 'ISO-8859-1'), 0, 'C');

        $pdf->SetFont('Arial','',8);
        // Celda para "NIP:"
        $pdf->Cell(26, 10, '', 0, 1);
        $pdf->Cell(26, 10);
        $pdf->multiCell(138, 6, mb_convert_encoding('Para reposición de Engomados TOTALGAS® deberá enviarse un escrito y mencionarnos el motivo de la reposición y deberá arribar la unidad a nuestras oficinas para que le sea colocado el engomado.', 'ISO-8859-1'), 0, 'C');

        $pdf->SetFont('Arial','B',8);
        $pdf->Cell(26, 4, '', 0, 1);
        $pdf->Cell(26, 10);
        $pdf->multiCell(138, 6, mb_convert_encoding('Los engomados TOTALGAS® deberán permanecer siempre colocados en la unidad y no se cargará a el vehículo si no viene de esta manera, el Engomado es único para cada unidad asignada.', 'ISO-8859-1'), 0, 'C');

        $pdf->SetFont('Arial','',8);
        $pdf->Cell(26, 4, '', 0, 1);
        $pdf->Cell(26, 10);
        $pdf->multiCell(138, 6, mb_convert_encoding('Las unidades con Engomado TOTALGAS® no podrán cargar en recipientes ni se podrá cargar a otra unidad que nosea la información que indique su engomado, si requiere cargar en recipientes y galones deberá solicitar por escrito una tarjeta para estos fines.', 'ISO-8859-1'), 0, 'C');

        $pdf->SetFont('Arial','',8);
        $pdf->Cell(26, 4, '', 0, 1);
        $pdf->Cell(26, 10);
        $pdf->multiCell(138, 6, mb_convert_encoding('Los engomados serán colocados única y exclusivamente por personal de TOTALGAS®, por lo que al iniciar el trámite deberán acercarse las unidades a nuestras oficinas. Si desea que se le entreguen para ser colocados por su personal, deberán solicitarlo por escrito.', 'ISO-8859-1'), 0, 'C');

        $pdf->SetFont('Arial','',8);
        $pdf->Cell(26, 4, '', 0, 1);
        $pdf->Cell(26, 10);
        $pdf->multiCell(138, 6, mb_convert_encoding('Si cambió de placas y cuenta con un engomado, deberá notificar a este departamento para que sea actualizada la base de datos y le sea generado otro engomado con la información de sus nuevas placas.', 'ISO-8859-1'), 0, 'C');

        $pdf->SetFont('Arial','',8);
        $pdf->Cell(26, 4, '', 0, 1);
        $pdf->Cell(26, 10);
        $pdf->multiCell(138, 6, mb_convert_encoding('Los saldos de los Engomados de CRÉDITO TOTALGAS® son intransferibles, a excepción de que el engomado haya sido cancelado por el cliente y deberá hacerse por medio de un escrito firmado y sellado (en su caso).', 'ISO-8859-1'), 0, 'C');

        $pdf->SetFont('Arial','B',8);
        $pdf->Cell(26, 4, '', 0, 1);
        $pdf->Cell(26, 10);
        $pdf->multiCell(138, 6, mb_convert_encoding('El cliente tiene un plazo de 30 días a partir de la facturación que reciba para hacer aclaraciones sobre las cargas que aparezcan, vencido el plazo se considerarán definitivas.', 'ISO-8859-1'), 0, 'C');

        $pdf->SetFont('Arial','',8);
        $pdf->Cell(26, 4, '', 0, 1);
        $pdf->Cell(26, 10);
        $pdf->multiCell(138, 6, mb_convert_encoding('Es responsabilidad del Cliente el uso correcto del Engomado TOTALGAS® si se llegara a presentar cualquier tipo de ilícito por parte de los usuarios, esta empresa no se hace responsable.', 'ISO-8859-1'), 0, 'C');

        $pdf->SetFont('Arial', 'B', 10);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetXY(160,270);
        $pdf->multiCell(143, 6, 'Folio. ' . $folioString, 0, 'L');

        // Generar el archivo PDF
        $pdf->Output();
    }

    function mqg_cards() : void{
        if (preg_match('/GET/i',$_SERVER['REQUEST_METHOD'])){
            echo $this->twig->render($this->route . 'mqg_cards.html');
        } else {
            $pdf = new PDF_Code128();
            // Establecer el tamaño de la página en milimetros (Ancho x Alto)
            $pdf->AddPage('L', array(85, 54));
            $pdf->SetMargins(0, 0, 0);
            $pdf->SetAutoPageBreak(false);
            // Recuadro de la fotografia
            $pdf->Image($_SERVER['DOCUMENT_ROOT']. '/_assets/images/mqg_1.jpg', 0, 0, 85, 54);
            $pdf->SetFont('Arial','',8);
            // cAMBIAR COLOR DE TEXTO
            $pdf->SetTextColor(255,255,255);
            // Establecer el tamaño de la página en milimetros (Ancho x Alto)
            $pdf->AddPage('L', array(85, 54));
            $pdf->SetMargins(0, 0, 0);
            $pdf->SetAutoPageBreak(false);
            // Recuadro de la fotografia
            $pdf->Image($_SERVER['DOCUMENT_ROOT']. '/_assets/images/mqg_2.jpg', 0, 0, 85, 54);
            $pdf->SetFont('Arial','',8);
            // cAMBIAR COLOR DE TEXTO
            $pdf->SetTextColor(255,255,255);
            // $pdf->SetTextColor(0,0,0);
            // Tipo
            // Vamos a craer un multicell con fondo blanco para que se vea el texto
            $pdf->SetFillColor(25, 35, 72);
            $pdf->SetXY(16,28);
            $pdf->multiCell(38, 4, mb_convert_encoding(strtoupper($_POST['client_name']), 'ISO-8859-1'), 0, 'C', 1);

            // Vamos a craer un multicell con fondo blanco para que se vea el texto
            $pdf->SetFont('Arial','',4);
            $pdf->SetTextColor(10,10,10);
            $pdf->SetFillColor(43, 145, 213);
            $pdf->SetXY(21,22.7);
            $pdf->multiCell(10, 2.5, mb_convert_encoding(strtoupper($_POST['card_number']), 'ISO-8859-1'), 0, 'C', 1);
            // Ahora un numero de cuenta
            $pdf->SetFillColor(238, 239, 239);
            $pdf->SetXY(34.7,22.8);
            $pdf->multiCell(14.6, 2.4, mb_convert_encoding(strtoupper($_POST['account_number']), 'ISO-8859-1'), 0, 'C', 1);
            $pdf->Output();
        }
    }
    }
}
    

