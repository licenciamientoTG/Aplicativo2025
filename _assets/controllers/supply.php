<?php
// Incluir la clase generadora (ajusta la ruta según tu estructura)
require_once $_SERVER['DOCUMENT_ROOT'] . '/_assets/classes/GeneradorXMLPrecios.php';

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


class Supply{
    public $twig;
    public $route;
    public GasolinerasModel $gasolinerasModel;
    public TanquesModel $tanquesModel;
    public TVariasModel $tvariasModel;
    public PreciosModel $preciosModel;
    public EstacionesModel $estacionesModel;
    public DocumentosModel $documentosModel;
    public FacturasRecibidasModel $facturasRecibidasModel;

    public BinnaclePricesModel $binnaclePricesModel;
    public CreProductsByStationsModel $creProductsByStationsModel;

    public CreProductsModel $creProductsModel;
    public CreSubProductosModel $creSubProductosModel;

    public creSubProductosMarcaModel $creSubProductosMarcaModel;
    public XsdReportesVolumenesModel $xsdReportesVolumenesModel;
    public XsdEstacionServicioVolumenVendidoInventariosModel $xsdEstacionServicioVolumenVendidoInventariosModel;
    public XsdEstacionServicioVolumenModel $xsdEstacionServicioVolumenModel;
    public CreSuppliersModel $creSuppliersModel;
    public CreCarriersModel $creCarriersModel;
    public XsdEstacionServicioVolumenCompradoModel $xsdEstacionServicioVolumenCompradoModel;
    public MovimientosTanModel $movimientosTanModel;
    public PaymentRequestsModel $paymentRequestsModel;
    public PaymentRequestInvoicesModel $paymentRequestInvoicesModel;
    public ProveedoresModel $proveedores;
    public FacturasMovimientosTanquesModel $facturasMovimientosTanquesModel;
    /**
     * @param $twig
     */
    public function __construct($twig) {
        $this->twig                                              = $twig;
        $this->route                                             = 'views/supply/';
        $this->gasolinerasModel                                  = new GasolinerasModel;
        $this->tanquesModel                                      = new TanquesModel();
        $this->tvariasModel                                      = new TVariasModel();
        $this->preciosModel                                      = new PreciosModel();
        $this->estacionesModel                                   = new EstacionesModel();
        $this->documentosModel                                   = new DocumentosModel();
        $this->binnaclePricesModel                               = new BinnaclePricesModel();
        $this->creProductsByStationsModel                        = new CreProductsByStationsModel();
        $this->creProductsModel                                  = new CreProductsModel();
        $this->creSubProductosModel                              = new CreSubProductosModel();
        $this->creSubProductosMarcaModel                         = new CreSubProductosMarcaModel();
        $this->xsdReportesVolumenesModel                         = new XsdReportesVolumenesModel();
        $this->xsdEstacionServicioVolumenModel                   = new XsdEstacionServicioVolumenModel();
        $this->xsdEstacionServicioVolumenVendidoInventariosModel = new XsdEstacionServicioVolumenVendidoInventariosModel();
        $this->creSuppliersModel                                 = new CreSuppliersModel();
        $this->creCarriersModel                                  = new CreCarriersModel();
        $this->xsdEstacionServicioVolumenCompradoModel           = new XsdEstacionServicioVolumenCompradoModel();
        $this->movimientosTanModel                               = new MovimientosTanModel();
        $this->paymentRequestsModel                               = new PaymentRequestsModel();
        $this->paymentRequestInvoicesModel                        = new PaymentRequestInvoicesModel();
        $this->proveedores                                       = new ProveedoresModel();
        $this->proveedores                                       = new ProveedoresModel();
        $this->facturasRecibidasModel                            = new FacturasRecibidasModel();
        $this->facturasMovimientosTanquesModel                   = new FacturasMovimientosTanquesModel();

    }

    /**
     * @return void
     * @throws Exception
     */
    function inventory() : void {
        $stations = $this->gasolinerasModel->get_active_stations();
        if (preg_match('/GET/i',$_SERVER['REQUEST_METHOD'])){
              echo $this->twig->render($this->route . 'inventory.html', compact('stations'));
        } else {
            $station_id = $_POST['station_id'] ?? 0;
            echo $this->twig->render($this->route . 'inventory.html', compact('stations', 'station_id'));
        }
    }

    function inventory_table($station_id) : void {
        $station_id = empty($station_id) ? 0 : $station_id;
        $data = [];
        if ($station_id == 0) {
            $inventories = $this->tanquesModel->get_inventory();
        } else {
            $inventories = $this->tanquesModel->get_inventory_by_codgas($station_id);
        }

        if ($inventories) {
            foreach ($inventories as $inventory) {
                $porcent_data = (($inventory['current_volume'] * 100) / $inventory['CapacidadOpe']);
                $porcent = "
                    <div class=\"d-flex flex-column w-100\">
                        <span class=\"me-2 mb-1 text-muted\">". number_format($porcent_data, 2, '.', ',') ."%</span>
                        <div class=\"progress progress-sm bg-". ($porcent_data < 10 ? 'danger' : ($porcent_data < 30 ? 'warning' : 'success' ) ) ."-light w-100\">
                            <div class=\"progress-bar bg-". ($porcent_data < 10 ? 'danger' : ($porcent_data < 30 ? 'warning' : 'success' ) ) ."\" role=\"progressbar\" style=\"width: ". $porcent_data ."%;\"></div>
                        </div>
                    </div>
                ";
                if ($inventory['average_daily_sales'] != 0) {
                    $inventory['diasinv'] = $inventory['current_volume'] / $inventory['average_daily_sales'];
                    $inventory['status'] = $inventory['diasinv'] > 3 ? '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-check-circle align-middle me-2 text-success"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>' : ($inventory['diasinv'] > 1 ? '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-alert-circle align-middle me-2 text-warning"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>' : '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-x-circle align-middle me-2 text-danger"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>');
                } else {
                    $inventory['diasinv'] = 0;
                    $inventory['status'] = 'N/A';
                }
                $data[] = [
                    'ESTACION' => $inventory['station_name'],
                    'PRODUCTO' => $inventory['product_name'],
                    'CAP' => $inventory['CapacidadOpe'],
                    'VOLUMEN' => number_format($inventory['current_volume'], 2, '.', ','),
                    'PORCENTAJE' => $porcent,
                    'VENTA' => is_null($inventory['total_sales']) ? 0 : number_format($inventory['total_sales'], 2, '.', ','), // $inventory['total_sales'],
                    'PROMEDIO' => is_null($inventory['average_daily_sales']) ? 0 : number_format($inventory['average_daily_sales'], 2, '.', ','), // $inventory['average_daily_sales'],
                    'DIASINV' => number_format($inventory['diasinv'], 1),
                    'STATUS' => $inventory['status'],
                ];
            }
        }
        json_output(array("data" => $data));
    }

    private function groupByStation($array): array
    {
        $groupedArray = [];
        foreach ($array as $item) {
            $station = $item['Estacion'];
            if (!isset($groupedArray[$station])) {
                    $groupedArray[$station] = [];
            }
            $groupedArray[$station][] = $item;
        }
        return $groupedArray;
    }

    public function inventory_mov() : void {
        //        Verificamos si date y station_id estan seteados
        $from = $_GET['from'] ?? date('Y-m-d');
        $station_id = $_GET['station_id'] ?? false;
        $stations = $this->gasolinerasModel->get_active_stations();
        echo $this->twig->render($this->route . 'inventory_mov.html', compact('from', 'station_id', 'stations'));
    }

    function inventory_mov_table($from, $station_id) : void {
        $data = [];
        if ($movements = $this->tanquesModel->sp_obtener_inventarios_por_movimientos_tanque($from, $station_id)) {
            foreach ($movements as $movement) {
                $data[] = [
                    'ESTACION'   => $movement['abr'],
                    'TURNO'      => $movement['Turno'],
                    'PRODUCTO'   => $movement['Tanque'],
                    'CAP'        => $movement['CapacidadOpe'],
                    'VOLUMEN'    => $movement['current_volume'],
                    'PORCENTAJE' => ( $movement['current_volume'] / $movement['CapacidadOpe'] ) * 100,
                ];
            }
        }
        json_output(array("data" => $data));
    }

    function fuel_prices() : void {
        $prices = $this->preciosModel->get_today_prices();
        binnacle_register_prices($_SESSION['tg_user']['Id'], 'Ingreso', 'Se ingresó a la pantalla de precios de combustibles', $_SERVER['REMOTE_ADDR'], 'supply.php', 'fuel_prices');
        $stations = $this->gasolinerasModel->get_active_station_TG();

        $mensajeFinal = 'No existe programación para cambio de precios de combustibles el día de hoy.';
        if ($todaySchedule = $this->preciosModel->get_today_schedules()) {
            // Construir el mensaje final
            $mensajeFinal = "<p class=\"d-inline-flex gap-1\">
                                  <a data-bs-toggle=\"collapse\" href=\"#collapseExample\" role=\"button\" aria-expanded=\"false\" aria-controls=\"collapseExample\">Notificación de actualización de precios de combustibles para el día de hoy <svg xmlns=\"http://www.w3.org/2000/svg\" width=\"24\" height=\"24\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\" class=\"feather feather-arrow-down align-middle me-2\"><line x1=\"12\" y1=\"5\" x2=\"12\" y2=\"19\"></line><polyline points=\"19 12 12 19 5 12\"></polyline></svg></a>
                                </p>
                                <div class=\"collapse\" id=\"collapseExample\">
                                  <div class=\"card card-body\">";
            $transformedArray = [];
            foreach ($todaySchedule as $key => $item) {
                $station = $item['Estacion'];

                // Si la estación no existe en el nuevo array, inicializarla
                if (!isset($transformedArray[$station])) {
                    $transformedArray[$station] = [];
                }

                // Agregar el producto a la estación
                $transformedArray[$station][] = [
                    "Producto" => $item["Producto"],
                    "Hora" => $item["Hora"],
                    "Precio" => $item["Precio"]
                ];
            }
            $mensajeFinal .= '<ul class="list-group">';
            foreach ($transformedArray as $station => $products) {
                $mensajeFinal .= '<li class="list-group-item d-flex justify-content-between align-items-start p-1"><div class="ms-2 me-auto" style="font-size: x-small"><b>'. $station .' ('. $products[0]['Hora'] .')</b> | ';
                foreach ($products as $product) { $mensajeFinal .= ' '. $product['Producto'] .' a $'. number_format($product['Precio'], 2) .''; }
                $mensajeFinal .= '</div></li>';
            }
            $mensajeFinal .= '</ul>';
            $mensajeFinal .= "</div></div>";
        }

        $mensajeFinal2 = '<b class="text-muted">No existe programación para cambio de precios de combustibles el día de mañana.</b>';
        if ($tomorrowSchedule = $this->preciosModel->getTomorrowSchedules()) {
            // Construir el mensaje final
            $mensajeFinal2 = "<p class=\"d-inline-flex gap-1\">
                                  <a data-bs-toggle=\"collapse\" href=\"#collapseExample\" role=\"button\" aria-expanded=\"false\" aria-controls=\"collapseExample\">Notificación de actualización de precios de combustibles para el día de mañana <svg xmlns=\"http://www.w3.org/2000/svg\" width=\"24\" height=\"24\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\" class=\"feather feather-arrow-down align-middle me-2\"><line x1=\"12\" y1=\"5\" x2=\"12\" y2=\"19\"></line><polyline points=\"19 12 12 19 5 12\"></polyline></svg></a>
                                </p>
                                <div class=\"collapse\" id=\"collapseExample\">
                                  <div class=\"card card-body\">";
            $transformedArray = [];
            foreach ($tomorrowSchedule as $key => $item) {
                $station = $item['Estacion'];

                // Si la estación no existe en el nuevo array, inicializarla
                if (!isset($transformedArray[$station])) {
                    $transformedArray[$station] = [];
                }

                // Agregar el producto a la estación
                $transformedArray[$station][] = [
                    "Producto" => $item["Producto"],
                    "Hora" => $item["Hora"],
                    "Precio" => $item["Precio"]
                ];
            }
            $mensajeFinal2 .= '<ul class="list-group">';
            foreach ($transformedArray as $station => $products) {
                $mensajeFinal2 .= '<li class="list-group-item d-flex justify-content-between align-items-start p-1"><div class="ms-2 me-auto" style="font-size: x-small"><b>'. $station .' ('. $products[0]['Hora'] .')</b> | ';
                foreach ($products as $product) { $mensajeFinal2 .= ' '. $product['Producto'] .' a $'. number_format($product['Precio'], 2) .''; }
                $mensajeFinal2 .= '</div></li>';
            }
            $mensajeFinal2 .= '</ul>';
            $mensajeFinal2 .= "</div></div>";
        }

        echo $this->twig->render($this->route . 'fuel_prices.html', compact('stations', 'mensajeFinal', 'mensajeFinal2', 'prices'));
    }

    function datatable_product_prices() {
        binnacle_register_prices($_SESSION['tg_user']['Id'], 'Visualización', 'Se visualizo la tabla de precios de combustibles', $_SERVER['REMOTE_ADDR'], 'supply.php', 'binnacle_register_prices');
        $data = [];
        $stations = $this->gasolinerasModel->get_active_station_TG();

        $prices = [];
        foreach ($stations as $station) {
            $stationPrices = $this->gasolinerasModel->get_fuel_prices_by_station($station['Servidor'], $station['BaseDatos'], $station['Codigo'], $station['Estacion'], $station['Nombre']);
            // Añadir el permisoCre a cada precio de la estación
            $stationPrices[0]['permisoCre'] = $station['PermisoCRE'];
            $prices[] = $stationPrices;
        }
        if ($prices) {
            foreach ($prices as $item) {

                $options_maxima = '
                <div class="dropdown">
                    <a class="dropdown-toggle text-light" href="#" role="button" id="dropdownMenuLink" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                        $'. number_format($item[0]['pre_actual_codprd_179'], 2, '.', ',') .'
                    </a>
        
                    <div class="dropdown-menu" aria-labelledby="dropdownMenuLink">
                        <a class="dropdown-item" href="javascript:void(0);" onclick="update_price(179, '. $item[0]['codgas'] .', '. $item[0]['fch_actual_codprd_179'] .', '.$item[0]['hra_actual_codprd_179'].', '. number_format($item[0]['pre_actual_codprd_179'], 2, '.', ',') .')"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-edit-2 align-middle me-2"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"></path></svg> Editar</a>
                        <a class="dropdown-item" href="javascript:void(0);" onclick="delete_price(179, '. $item[0]['codgas'] .', '. $item[0]['fch_actual_codprd_179'] .', '.$item[0]['hra_actual_codprd_179'].')"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-trash-2 align-middle me-2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg> Eliminar</a>
                    </div>
                </div>
                ';

                $options_super = '
                <div class="dropdown">
                    <a class="dropdown-toggle text-light" href="#" role="button" id="dropdownMenuLink" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                        $'. number_format($item[0]['pre_actual_codprd_180'], 2, '.', ',') .'
                    </a>
        
                    <div class="dropdown-menu" aria-labelledby="dropdownMenuLink">
                        <a class="dropdown-item" href="javascript:void(0);" onclick="update_price(180, '. $item[0]['codgas'] .', '. $item[0]['fch_actual_codprd_180'] .', '.$item[0]['hra_actual_codprd_180'].', '. number_format($item[0]['pre_actual_codprd_180'], 2, '.', ',') .')"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-edit-2 align-middle me-2"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"></path></svg> Editar</a>
                        <a class="dropdown-item" href="javascript:void(0);" onclick="delete_price(180, '. $item[0]['codgas'] .', '. $item[0]['fch_actual_codprd_180'] .', '.$item[0]['hra_actual_codprd_180'].')"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-trash-2 align-middle me-2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg> Eliminar</a>
                    </div>
                </div>
                ';

                $options_diesel = '
                <div class="dropdown">
                    <a class="dropdown-toggle text-dark" href="#" role="button" id="dropdownMenuLink" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                        $'. number_format($item[0]['pre_actual_codprd_181'], 2, '.', ',') .'
                    </a>
        
                    <div class="dropdown-menu" aria-labelledby="dropdownMenuLink">
                        <a class="dropdown-item" href="javascript:void(0);" onclick="update_price(181, '. $item[0]['codgas'] .', '. $item[0]['fch_actual_codprd_181'] .', '.$item[0]['hra_actual_codprd_181'].', '. number_format($item[0]['pre_actual_codprd_181'], 2, '.', ',') .')"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-edit-2 align-middle me-2"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"></path></svg> Editar</a>
                        <a class="dropdown-item" href="javascript:void(0);" onclick="delete_price(181, '. $item[0]['codgas'] .', '. $item[0]['fch_actual_codprd_181'] .', '.$item[0]['hra_actual_codprd_181'].')"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-trash-2 align-middle me-2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg> Eliminar</a>
                    </div>
                </div>
                ';

                $data[] = array(
                    'CODEST'               => $item[0]['station'],
                    'ESTACION'             => $item[0]['station_name'] . '<p class="m-0 p-0 text-nowrap">'. $item[0]['permisoCre'] .'</p>',
                    'PRECIOANTERIORMAXIMA' => '<p class="m-0 p-0 text-center">$'. number_format($item[0]['pre_anterior_codprd_179'], 2, '.', ',') . '<p class="m-0 p-0 text-center">'. (intToDate($item[0]['fch_anterior_codprd_179'])) . '</p>',
                    'PRECIONUEVOMAXIMA'    => $options_maxima . '<p class="m-0 p-0 text-center" data-toggle="tooltip" title="Hora: '. $item[0]['hra_actual_codprd_179'] .'">'. (intToDate($item[0]['fch_actual_codprd_179'])) . '</p>',
                    'DIFERENCIAMAXIMA'     => (is_null($item[0]['pre_actual_codprd_179']) ? 'N/A' : ('$' . number_format($item[0]['pre_actual_codprd_179'] - $item[0]['pre_anterior_codprd_179'], 2, '.', ','))),
                    'PRECIOANTERIORSUPER'  => (is_null($item[0]['pre_anterior_codprd_180']) ? 'N/A' : ('<p class="m-0 p-0 text-center">$'. number_format($item[0]['pre_anterior_codprd_180'], 2, '.', ',') .'</p> <p class="m-0 p-0 text-center">'. (intToDate($item[0]['fch_anterior_codprd_180'])) . '</p>')),
                    'PRECIONUEVOSUPER'     => (is_null($item[0]['pre_actual_codprd_180']) ? 'N/A' : ( $options_super . '<p class="m-0 p-0 text-center" data-toggle="tooltip" title="Hora: '. $item[0]['hra_actual_codprd_180'] .'">'. (intToDate($item[0]['fch_actual_codprd_180'])) . '</p>')),
                    'DIFERENCIASUPER'      => (is_null($item[0]['pre_actual_codprd_180']) ? 'N/A' : ('$' . number_format($item[0]['pre_actual_codprd_180'] - $item[0]['pre_anterior_codprd_180'], 2, '.', ','))),
                    'PRECIOANTERIORDIESEL' => (is_null($item[0]['pre_anterior_codprd_181']) ? 'N/A' : ('<p class="m-0 p-0 text-center">$'. number_format($item[0]['pre_anterior_codprd_181'], 2, '.', ',') .'</p> <p class="m-0 p-0 text-center">'. (intToDate($item[0]['fch_anterior_codprd_181'])) . '</p>')),
                    'PRECIONUEVODIESEL'    => (is_null($item[0]['pre_actual_codprd_181']) ? 'N/A' : ( $options_diesel . '<p class="m-0 p-0 text-center" data-toggle="tooltip" title="Hora: '. $item[0]['hra_actual_codprd_181'] .'">'. (intToDate($item[0]['fch_actual_codprd_181'])) . '</p>')),
                    'DIFERENCIADIESEL'     => (is_null($item[0]['pre_actual_codprd_181']) ? 'N/A' : ('$' . number_format($item[0]['pre_actual_codprd_181'] - $item[0]['pre_anterior_codprd_181'], 2, '.', ',')))
                );
            }
        }
        json_output(array("data" => $data));
    }

    // Función para construir el mensaje de una estación


    function delete_price($codprd, $codgas, $fch, $hra) {

        binnacle_register_prices($_SESSION['tg_user']['Id'], 'Eliminación', "Se eliminó el siguiente registro: codprd: {$codprd}, codgas: {$codgas}, fch: {$fch}, hra: {$hra}.", $_SERVER['REMOTE_ADDR'], 'supply.php', 'delete_price');
        if ($this->preciosModel->delete_price($codprd, $codgas, $fch, $hra)) {
            setFlashMessage('success', 'Precio eliminado correctamente');
        } else {
            setFlashMessage('error', 'No se pudo eliminar el precio');
        }
        header('Location: /supply/fuel_prices');
    }

    function send_prices() {
        $pre = $_GET['pre'];
        $fch = $_GET['from'];
        $hour = str_replace(":", "", $_GET['hour']);
        $codprd = $_GET['product'];
        $stations = $_GET['codgas'];


        // Si precio es igual a cero, no se puede enviar
        if ($pre == 0) {
            setFlashMessage('error', 'El precio no puede ser cero');
            echo $this->twig->render($this->route . 'fuel_prices.html', ['error' => 'El precio no puede ser cero']);
            return;
        }

        $ieps = $this->tvariasModel->get_ieps();

        foreach ($stations as $codgas) {
            $iva = $this->estacionesModel->get_iva($codgas);
            switch ($codprd) {
                case 181:
                    $ieps_val = $ieps[0]['abr'];
                    break;
                case 180:
                    $ieps_val = $ieps[1]['abr'];
                    break;
                case 179:
                    $ieps_val = $ieps[2]['abr'];
                    break;
                case 192:
                    $ieps_val = $ieps[2]['abr'];
                    break;
                case 193:
                    $ieps_val = $ieps[1]['abr'];
                    break;
            }
            if (in_array($codgas, [33, 34, 35, 36, 37,38])) { // Travel, Picachos, Ventanas, San Rafael, Puertecito
                if ($codprd == 179) {
                    $codprd = 192;
                } elseif ($codprd == 180) {
                    $codprd = 193;
                }
            }

            // binnacle_register_prices($_SESSION['tg_user']['Id'], 'Creación', "Se creó un nuevo precio | codprd: {$codprd}, codgas: {$codgas}, fch: {$fch}, hra: {$hour}, pre: {$pre}, iva: {$iva}, ieps: {$ieps}.", $_SERVER['REMOTE_ADDR'], 'supply.php', 'send_prices');
            $this->preciosModel->capture_prices($codprd, dateToInt($fch), $hour, $pre, $iva, $codgas, $ieps_val);
        }
        setFlashMessage('success', 'Precios enviados correctamente');
        redirect('/supply/fuel_prices');
    }

    function get_ieps($codprd) {
        $ieps = $this->tvariasModel->get_ieps();
        switch ($codprd) {
            case 193:
                $ieps = $ieps[1];
                break;
            case 192:
                $ieps = $ieps[2];
                break;
            case 181:
                $ieps = $ieps[0];
                break;
            case 180:
                $ieps = $ieps[1];
                break;
            case 179:
                $ieps = $ieps[2];
                break;
        }
        json_output($ieps);
    }

    function update_price() {
        $codprd = $_POST['codprd'];
        $codgas = $_POST['codgas'];
        $fch = $_POST['fch'];
        $hra = $_POST['hra'];
        $pre = $_POST['pre'];

        // Vamos a comprobar que el precio no sea cero
        if ($pre == 0) {
            json_output(['status' => 'Error', 'message' => 'El precio no puede ser cero']);
        }
        // Vamos a comprobar que codgas no sea cero o null
        if ($codgas == 0 || is_null($codgas)) {
            json_output(['status' => 'Error', 'message' => 'La estación no es válida']);
        }

        // Vamos a comprobar que fch no sea cero o null
        if ($fch == 0 || is_null($fch)) {
            json_output(['status' => 'Error', 'message' => 'La fecha no es válida']);
        }
        // Vamos a comprobar que hra este entre 0 y 2359
        if ($hra < 0 || $hra > 2359) {
            json_output(['status' => 'Error', 'message' => 'La hora no es válida']);
        }

        // Vamos a comprobar que el producto sea 179, 180 o 181
        if ($codprd != 179 && $codprd != 180 && $codprd != 181) {
            json_output(['status' => 'Error', 'message' => 'El producto no es válido']);
        }

        // Vamos a modificar el precio
        $this->preciosModel->update_price($codprd, $codgas, $fch, $hra, $pre);

        binnacle_register_prices($_SESSION['tg_user']['Id'], 'Actualización', "Se actualizó el siguiente precio | codprd: {$codprd}, codgas: {$codgas}, fch: {$fch}, hra: {$hra}, pre: {$pre}.", $_SERVER['REMOTE_ADDR'], 'supply.php', 'send_prices');

        json_output(['status' => 'Success', 'message' => 'Precio actualizado correctamente']);
    }

    function get_binnacle() : void {
        $binnacle = $this->binnaclePricesModel->get_binnacle();
        echo $this->twig->render($this->route . 'binnacle.html', compact('binnacle'));
    }

    function changes() : void {
        echo $this->twig->render($this->route . 'changes.html');
    }

    function tgr01() {
        $stations = $this->gasolinerasModel->get_active_stations();
        isset($_GET['codgas']) ? $codgas = $_GET['codgas'] : $codgas = 7;
        isset($_GET['from']) ? $from = $_GET['from'] : $from = date('Y-m-d');
        isset($_GET['to']) ? $to = $_GET['to'] : $to = date('Y-m-d');
        isset($_GET['shift']) ? $shift = $_GET['shift'] : $shift = 0;
        isset($_GET['product']) ? $product = $_GET['product'] : $product = 0;

        $data = $this->gasolinerasModel->GetVentasLogistica(dateToInt($from), dateToInt($to), intval($codgas), intval($product));

        echo $this->twig->render($this->route . 'tgr01.html', compact('stations', 'from', 'to', 'codgas', 'shift', 'product', 'data'));
    }

    function creProducts() {
        $stations = $this->gasolinerasModel->get_active_station_TG();
        $products = $this->creProductsModel->getRows();
        echo $this->twig->render($this->route . 'creProducts.html', compact('stations', 'products'));
    }

    function datatable_creProducts() {
        $data = [];
        if ($products = $this->creProductsByStationsModel->getRows()) {
            foreach ($products as $product) {
                $actions = '<a href="javascript:void(0);" class="btn btn-sm btn-primary"><i class="fas fa-edit"></i></a>';
                $data[] = [
                    'ID'             => $product['id'],
                    'ESTACIÓN'       => $product['gasStationName'],
                    'CREPRODUCTO'    => $product['productName'],
                    'CRESUBPRODUCTO' => $product['subProductName'],
                    'CREMARCA'       => $product['subProductBrandName'],
                    'ALTA'           => $product['createdAt'],
                    'ACTIONS'        => $actions,
                ];
            }
        }

        json_output(array("data" => $data));
    }

    function getSubProducts($productId) {
        $subProducts = $this->creSubProductosModel->getRowsByProduct($productId);
        json_output($subProducts);
    }

    function getSubProductsBrand($subProductId) {
        $subProductsBrand = $this->creSubProductosMarcaModel->getRowsBySubProduct($subProductId);
        json_output($subProductsBrand);
    }

    function addCreProductForm() {
        $controlGasStationId = $_GET['controlGasStationId'];
        $creProductId = $_GET['creProductId'];
        $creSubProductId = $_GET['creSubProductId'];
        $creSubProductBrandId = $_GET['creSubProductBrandId'];
        if ($this->creProductsByStationsModel->addRow($controlGasStationId, $creProductId, $creSubProductId, $creSubProductBrandId)) {
            json_output(['status' => 'success', 'message' => 'Producto agregado correctamente']);
        } else {
            json_output(['status' => 'error', 'message' => 'El producto no pudo ser agregado']);
        }
    }

    function bulkUpload2() {

        ini_set('memory_limit', '256M');
        ini_set('max_execution_time', 300);

        // Fechas
        $yesterday = (new DateTime('yesterday'))->format('Y-m-d');
        $tenDaysAgo = (new DateTime('-10 days'))->format('Y-m-d');

        // Datos iniciales
        $companies = $this->estacionesModel->getCompanies();
        $suppliers = $this->creSuppliersModel->getRows();
        $carriers = $this->creCarriersModel->getRows();

        // Parámetros
        $from       = $_GET['from'] ?? date('Y-m-d', strtotime('-1 day'));
        $companyRfc = $_GET['company'] ?? '';

        // Obtiene gasolineras activas y filtra las que tengan "Codigo" == "38"
        $data = $this->gasolinerasModel->get_active_station_TG();
        $dataFiltered = array_filter($data, fn($item) => $item["Codigo"] !== "38");
        $stations = array_values($dataFiltered);

        // Arreglo común para renderizar la vista
        $twigVars = compact('from', 'yesterday', 'tenDaysAgo', 'companies', 'companyRfc', 'stations', 'suppliers', 'carriers');

        if (!empty($_GET['company'])) {
            // Vamos a verificar si existe un archivo en el servidor con el nombre $companyRfc_$from.xml
            $fileName = $_GET['company'] . '_' . $_GET['from'] . '.xml';
            $filePath = __DIR__ . '/../../_assets/uploads/creXMLs/' . $fileName;
            if (file_exists($filePath)) {
                $xmlloaded = 1;
            } else {
                $xmlloaded = 0;
            }

            // Ahora con un PDF
            $fileName = $_GET['company'] . '_' . $_GET['from'] . '.pdf';
            $filePath = __DIR__ . '/../../_assets/uploads/creAcuses/' . $fileName;
            if (file_exists($filePath)) {
                $pdfloaded = 1;
            } else {
                $pdfloaded = 0;
            }
            $twigVars['xmlloaded'] = $xmlloaded;
            $twigVars['pdfloaded'] = $pdfloaded;


            // Obtiene las estaciones asociadas a la compañía
            $codgas_string = $this->estacionesModel->getStationsByCompany($_GET['company']);
            $twigVars['codgas_string'] = $codgas_string;

            // Obtiene los productos asociados a las estaciones para la fecha indicada
            $codgas_products = $this->creProductsByStationsModel->getProductsByStations($codgas_string, dateToInt($from));
            // Obtiene el reporte de volumen una sola vez
            if ($reporteVolumenes = $this->xsdReportesVolumenesModel->getOrAddRow($from)) {
                $reportId = $reporteVolumenes['id'];
                // Procesa cada producto
                foreach ($codgas_products as $item) {
                    // Inserta o recupera el registro de la estación en la tabla de volumen
                    $estacionServicioVolumen = $this->xsdEstacionServicioVolumenModel->getOrAddRow($reportId, $item['numeroPermisoCRE'], $item['rfc']);
                    if (!is_null($item['controlGasProductId'])) {
                        echo '<pre>';
                        var_dump($item);
                        die();
                        if ($recepcion = $this->movimientosTanModel->sp_obtener_recepciones_combustible($from, $item['codgas'], $item['controlGasProductId'])) {
                            $satdat = $recepcion[0]['satdat'];

                            preg_match('/@t:([^@]*)/', $satdat, $matches);

                            $transportistaCRE = isset($matches[1]) ? $matches[1] : '-------PENDIENTE-------';
                            # Aqui vamos a almacenar las recepciones de combustibles
                            $this->xsdEstacionServicioVolumenCompradoModel->insertOrUpdateVolumenComprado(
                                $reportId,
                                $estacionServicioVolumen['id'],
                                $item['codgas'],
                                $item['controlGasProductId'],
                                $recepcion[0]['VolumenFacturado'],
                                $recepcion[0]['nrotrn'],
                                $transportistaCRE,
                                $recepcion[0]['ProveedorCRE'],
                                $recepcion[0]['pre']
                            );
                        }
                    }
                    // Si no existe el registro en la tabla de inventarios vendidos, lo inserta o actualiza
                    if (!$this->xsdEstacionServicioVolumenVendidoInventariosModel->exists($reportId, $item['controlGasStationId'], $item['controlGasProductId'])) {
                        $this->xsdEstacionServicioVolumenVendidoInventariosModel->insertOrUpdateRow(
                            $reportId,
                            $estacionServicioVolumen['id'],
                            $item['controlGasStationId'],
                            $item['controlGasProductId'],
                            $item['creProductId'],
                            $item['creSubProductId'],
                            $item['creSubProductBrandId'],
                            intval($item['SaldoInicial']),
                            intval($item['Ventas']),
                            intval($item['SaldoFinal']),
                            intval($item['Merma'])
                        );
                    }
                }

                // Obtiene los productos actualizados
                $products = $this->xsdEstacionServicioVolumenVendidoInventariosModel->getProductsByStations($codgas_string, $reportId);
                $groupedData = [];

                // Agrupa los productos por estación y agrega la información de compras
                foreach ($products as $item) {
                    $controlGasStationId = $item['controlGasStationId'];
                    if (!isset($groupedData[$controlGasStationId])) {
                        $groupedData[$controlGasStationId] = [];
                    }
                    $item['compras'] = $this->xsdEstacionServicioVolumenCompradoModel->getPurchaseByProduct2($item['xsdReportesVolumenesId'],$item['xsdEstacionServicioVolumenId'],$item['controlGasProductId']);
                    $groupedData[$controlGasStationId][] = $item;
                }
                $twigVars['groupedData'] = $groupedData;
            }
        } else {
            $twigVars['codgas_string'] = '';
        }

        // Renderiza la vista con todas las variables
        echo $this->twig->render($this->route . 'bulk_upload2.html', $twigVars);
    }

    function bulkUpload() {
        ini_set('memory_limit', '256M');
        ini_set('max_execution_time', 300);

        // Fechas
        $yesterday = (new DateTime('yesterday'))->format('Y-m-d');
        $tenDaysAgo = (new DateTime('-10 days'))->format('Y-m-d');

        // Datos iniciales
        $companies = $this->estacionesModel->getCompanies();
        $suppliers = $this->creSuppliersModel->getRows();
        $carriers = $this->creCarriersModel->getRows();

        // Parámetros
        $from       = $_GET['from'] ?? date('Y-m-d', strtotime('-1 day'));
        $companyRfc = $_GET['company'] ?? '';

        // Obtiene gasolineras activas y filtra las que tengan "Codigo" == "38"
        $data = $this->gasolinerasModel->get_active_station_TG();
        $dataFiltered = array_filter($data, fn($item) => $item["Codigo"] !== "38");
        $stations = array_values($dataFiltered);

        // Arreglo común para renderizar la vista
        $twigVars = compact('from', 'yesterday', 'tenDaysAgo', 'companies', 'companyRfc', 'stations', 'suppliers', 'carriers');

        if (!empty($companyRfc)) {
            // Obtiene las estaciones asociadas a la compañía
            $codgas_string = $this->estacionesModel->getStationsByCompany($_GET['company']);
            $twigVars['codgas_string'] = $codgas_string;

            // Obtiene los productos asociados a las estaciones para la fecha indicada
            $codgas_products = $this->creProductsByStationsModel->getProductsByStations($codgas_string, dateToInt($from));
            // $codgas_products = array_values(array_filter(
            //     $codgas_products,
            //     function ($row) {
            //         // Soporta array u objeto
            //         $val = is_array($row) ? ($row['codgas'] ?? null) : ($row->codgas ?? null);
            //         return (int)$val !== 18;
            //     }
            // ));

            // Obtiene el reporte de volumen una sola vez
            if ($reporteVolumenes = $this->xsdReportesVolumenesModel->getOrAddRow($from)) {
                $reportId = $reporteVolumenes['id'];

                // Procesa cada producto
                foreach ($codgas_products as $item) {
                    // Inserta o recupera el registro de la estación en la tabla de volumen
                    $estacionServicioVolumen = $this->xsdEstacionServicioVolumenModel->getOrAddRow($reportId, $item['numeroPermisoCRE'], $item['rfc']);

                    // Si no existe el registro en la tabla de inventarios vendidos, lo inserta o actualiza
                    if (!$this->xsdEstacionServicioVolumenVendidoInventariosModel->exists($reportId, $item['controlGasStationId'], $item['controlGasProductId'])) {
                        $this->xsdEstacionServicioVolumenVendidoInventariosModel->insertOrUpdateRow(
                            $reportId,
                            $estacionServicioVolumen['id'],
                            $item['controlGasStationId'],
                            $item['controlGasProductId'],
                            $item['creProductId'],
                            $item['creSubProductId'],
                            $item['creSubProductBrandId'],
                            intval($item['SaldoInicial']),
                            intval($item['Ventas']),
                            intval($item['SaldoFinal']),
                            intval($item['Merma'])
                        );
                    }
                }

                // Obtiene los productos actualizados
                $products = $this->xsdEstacionServicioVolumenVendidoInventariosModel->getProductsByStations($codgas_string, $reportId);
                $groupedData = [];

                // Agrupa los productos por estación y agrega la información de compras
                foreach ($products as $item) {
                    $controlGasStationId = $item['controlGasStationId'];
                    if (!isset($groupedData[$controlGasStationId])) {
                        $groupedData[$controlGasStationId] = [];
                    }
                    $item['compras'] = $this->xsdEstacionServicioVolumenCompradoModel->getPurchaseByProduct(
                        $item['xsdReportesVolumenesId'],
                        $item['xsdEstacionServicioVolumenId'],
                        $item['controlGasProductId']
                    );
                    $groupedData[$controlGasStationId][] = $item;
                }
                $twigVars['groupedData'] = $groupedData;
            }
        } else {
            $twigVars['codgas_string'] = '';
        }

        // Renderiza la vista con todas las variables
        echo $this->twig->render($this->route . 'bulk_upload.html', $twigVars);
    }

    function creSuppliers() {
        if (preg_match('/GET/i',$_SERVER['REQUEST_METHOD'])) {
            echo $this->twig->render($this->route . 'creSuppliers.html');
        } else {
            $data = [];
            if ($rows = $this->creSuppliersModel->getRows()) {
                foreach ($rows as $row) {
                    $data[] = array(
                        'id' => $row['id'],
                        'name' => $row['companyName'],
                        'rfc' => $row['rfc'],
                        'cre' => $row['crePermissionSupplier'],
                    );
                }
            }
            json_output(array("data" => $data));
        }
    }

    function creCarriers() {
        if (preg_match('/GET/i',$_SERVER['REQUEST_METHOD'])) {
            echo $this->twig->render($this->route . 'creCarriers.html');
        } else {
            $data = [];
            if ($carriers = $this->creCarriersModel->getRows()) {
                foreach ($carriers as $row) {
                    $data[] = array(
                        'id' => $row['id'],
                        'name' => $row['companyName'],
                        'rfc' => $row['rfc'],
                        'cre' => $row['crePermissionCarrier'],
                    );
                }
            }
            json_output(array("data" => $data));
        }
    }

    function updateForm() {
        if (preg_match('/POST/i',$_SERVER['REQUEST_METHOD'])) {
            $creProductId = $_POST['creProductId'];
            $creSubProductId = $_POST['creSubProductId'];
            $creSubProductBrandId = $_POST['creSubProductBrandId'];

            $cabecera = $this->xsdReportesVolumenesModel->get_cabecera($_POST['from']);
            $station = $this->xsdEstacionServicioVolumenModel->get_station($cabecera['id'], $_POST['codgas']);

            if ($station_inventory = $this->xsdEstacionServicioVolumenVendidoInventariosModel->get_inventory_product($station['id'], $creProductId, $creSubProductId)) {
                $data = $this->xsdEstacionServicioVolumenVendidoInventariosModel->update_inventory_product($station_inventory['id'], $_POST['InventarioInicial'], $_POST['VolumenVendido'], $_POST['InventarioFinal'], $_POST['Merma']);
                json_output(['status' => 'success', 'message' => 'Datos actualizados correctamente', 'data' => $data]);
            }
        }
    }

    function updateForm2() {
        if (preg_match('/POST/i',$_SERVER['REQUEST_METHOD'])) {
            $creProductId = $_POST['creProductId'];
            $creSubProductId = $_POST['creSubProductId'];
            $controlGasProductId = $_POST['controlGasProductId'];

            $cabecera = $this->xsdReportesVolumenesModel->get_cabecera($_POST['from']);
            $station = $this->xsdEstacionServicioVolumenModel->get_station($cabecera['id'], $_POST['codgas']);

            $fchInt = dateToInt($_POST['from']);
            if ($station_inventory = $this->xsdEstacionServicioVolumenVendidoInventariosModel->get_inventory_product($station['id'], $creProductId, $creSubProductId)) {
                $data = $this->xsdEstacionServicioVolumenVendidoInventariosModel->update_inventory_product2($station_inventory['id'], $_POST['InventarioInicial'], $_POST['InventarioFinal'], $_POST['codgas'], $controlGasProductId,$fchInt);
                json_output(['status' => 'success', 'message' => 'Datos actualizados correctamente', 'data' => $data]);
            }
        }
    }

    function frmCapturaProveedor() {
        // Verifica si la petición es de tipo POST
        if (preg_match('/POST/i', $_SERVER['REQUEST_METHOD'])) {
            // Recibe los datos del formulario (productos, proveedor, precios, etc.)
            $controlGasStationId      = $_POST['codgas'];
            $ProductoId               = $_POST['creProductId'];
            $SubProductoId            = $_POST['creSubProductId'];
            $creSubProductBrandId     = $_POST['creSubProductBrandId'];
            $TipoCompra               = $_POST['TipoCompra'];
            $TipoDocumento            = $_POST['TipoDocumento'];
            $PermisoProveedorCRE      = $_POST['PermisoProveedorCRE'];
            $VolumenComprado          = $_POST['VolumenComprado'];
            $PrecioCompraSinDescuento = $_POST['PrecioCompraSinDescuento'];
            $RecibioDescuento         = $_POST['RecibioDescuento'];
            $PagoServicioFlete        = $_POST['PagoServicioFlete'];
            $PermisoTransportistaCRE  = $_POST['PermisoTransportistaCRE'];
            $controlGasProductId      = $_POST['controlGasProductId'];

            // Obtiene la cabecera del reporte por fecha
            $cabecera = $this->xsdReportesVolumenesModel->get_cabecera($_POST['from']);
            // Obtiene el ID de la estación dentro del reporte
            $station = $this->xsdEstacionServicioVolumenModel->get_station($cabecera['id'], $_POST['codgas']);

            // Determina el costo del flete, si aplica
            if ($PagoServicioFlete) {
                $CostoFlete = $_POST['CostoFlete'];
            } else {
                $CostoFlete = 0;
            }

            // Si recibió descuento, se guarda con datos adicionales
            if ($RecibioDescuento == 1) {
                $TipoDescuentoId = $_POST['TipoDescuentoId'];
                $OtroTipoDescuento = "";  // Campo fijo vacío
                $PrecioCompraConDescuento = $_POST['PrecioCompraConDescuento'];

                // Guarda los datos usando el método para compras con descuento
                if ($rs = $this->xsdEstacionServicioVolumenCompradoModel->save(
                    $cabecera['id'],
                    $station['id'],
                    $controlGasStationId,
                    $controlGasProductId,
                    $ProductoId,
                    $SubProductoId,
                    $creSubProductBrandId,
                    $TipoCompra,
                    $TipoDocumento,
                    $PermisoProveedorCRE,
                    $VolumenComprado,
                    $PrecioCompraSinDescuento,
                    $RecibioDescuento,
                    $TipoDescuentoId,
                    $OtroTipoDescuento,
                    $PrecioCompraConDescuento,
                    $PagoServicioFlete,
                    $CostoFlete,
                    $PermisoTransportistaCRE,
                    $controlGasProductId
                )) {
                    // Respuesta de éxito con los datos guardados
                    json_output([
                        'status' => 'success',
                        'message' => 'Datos guardados correctamente',
                        'data' => $rs,
                        'rowid' => $_POST['rowid']
                    ]);
                } else {
                    // Error al guardar
                    json_output(['status' => 'error', 'message' => 'No se pudieron guardar los datos']);
                }
            } else {
                // Si NO recibió descuento, se guarda con otra función
                if ($rs = $this->xsdEstacionServicioVolumenCompradoModel->save_no_discount(
                    $cabecera['id'],
                    $station['id'],
                    $controlGasStationId,
                    $controlGasProductId,
                    $ProductoId,
                    $SubProductoId,
                    $creSubProductBrandId,
                    $TipoCompra,
                    $TipoDocumento,
                    $PermisoProveedorCRE,
                    $VolumenComprado,
                    $PrecioCompraSinDescuento,
                    $RecibioDescuento,
                    $PagoServicioFlete,
                    $CostoFlete,
                    $PermisoTransportistaCRE,
                    $controlGasProductId
                )) {
                    // Respuesta de éxito
                    json_output([
                        'status' => 'success',
                        'message' => 'Datos guardados correctamente',
                        'data' => $rs,
                        'rowid' => $_POST['rowid']
                    ]);
                } else {
                    // Error al guardar sin descuento
                    json_output(['status' => 'error', 'message' => 'No se pudieron guardar los datos']);
                }
            }
        }
    }

    function frmCapturaProveedor2() {
        // Verifica si la petición es de tipo POST
        if (preg_match('/POST/i', $_SERVER['REQUEST_METHOD'])) {
            // Recibe los datos del formulario (productos, proveedor, precios, etc.)
            $controlGasStationId      = $_POST['codgas'];
            $ProductoId               = $_POST['creProductId'];
            $SubProductoId            = $_POST['creSubProductId'];
            $creSubProductBrandId     = $_POST['creSubProductBrandId'];
            $TipoCompra               = $_POST['TipoCompra'];
            $TipoDocumento            = $_POST['TipoDocumento'];
            $PermisoProveedorCRE      = $_POST['PermisoProveedorCRE'];
            $PrecioCompraSinDescuento = $_POST['PrecioCompraSinDescuento'];
            $RecibioDescuento         = $_POST['RecibioDescuento'];
            $PagoServicioFlete        = $_POST['PagoServicioFlete'];
            $PermisoTransportistaCRE  = $_POST['PermisoTransportistaCRE'];
            $controlGasProductId      = $_POST['controlGasProductId'];

            $id = $_POST['rowid'];

            // Obtiene la cabecera del reporte por fecha
            $cabecera = $this->xsdReportesVolumenesModel->get_cabecera($_POST['from']);
            // Obtiene el ID de la estación dentro del reporte
            $station = $this->xsdEstacionServicioVolumenModel->get_station($cabecera['id'], $_POST['codgas']);

            // Determina el costo del flete, si aplica
            if ($PagoServicioFlete) {
                $CostoFlete = $_POST['CostoFlete'];
            } else {
                $CostoFlete = 0;
            }

            // Si NO recibió descuento, se guarda con otra función
            if ($rs = $this->xsdEstacionServicioVolumenCompradoModel->update_volumen_comprado(
                $cabecera['id'],
                $station['id'],
                $controlGasStationId,
                $controlGasProductId,
                $ProductoId,
                $SubProductoId,
                $creSubProductBrandId,
                $TipoCompra,
                $TipoDocumento,
                $PermisoProveedorCRE,
                $PrecioCompraSinDescuento,
                $RecibioDescuento,
                $PagoServicioFlete,
                $CostoFlete,
                $PermisoTransportistaCRE,
                $id
            )) {
                // Respuesta de éxito
                json_output([
                    'status' => 'success',
                    'message' => 'Datos guardados correctamente',
                    'data' => $rs,
                    'rowid' => $_POST['rowid']
                ]);
            } else {
                // Error al guardar sin descuento
                json_output(['status' => 'error', 'message' => 'No se pudieron guardar los datos']);
            }
        }
    }


    function getPurchaseData($id) {
        if ($data = $this->xsdEstacionServicioVolumenCompradoModel->getRow($id)) {
            json_output(['status' => 'success', 'data' => $data]);
        } else {
            json_output(['status' => 'error', 'message' => 'No se encontraron datos']);
        }
    }

    function deletePurchase($id) {
        if ($this->xsdEstacionServicioVolumenCompradoModel->delete($id)) {
            // Vamos a enviar un mensaje flash
            setFlashMessage('success', 'Compra eliminada correctamente');
            redirect();
        }
    }

    function addCarrierModal() {
        if (preg_match('/POST/i',$_SERVER['REQUEST_METHOD'])) {
            $companyName = $_POST['companyName'];
            $rfc = $_POST['rfc'];
            $crePermissionCarrier = $_POST['crePermissionCarrier'];

            // Vamos a verificar si ya existe el registro
            if ($this->creCarriersModel->exists($crePermissionCarrier)) {
                json_output(['status' => 'error', 'message' => 'El permiso CRE ingresado ya existe en la base de datos']);
            } else {
                if ($this->creCarriersModel->addRow($companyName, $rfc, $crePermissionCarrier)) {
                    json_output(['status' => 'success', 'message' => 'Transportista agregado correctamente']);
                } else {
                    json_output(['status' => 'error', 'message' => 'No se pudo agregar el transportista']);
                }
            }
        }
    }

    function editCarrierModal() {
        if (preg_match('/POST/i',$_SERVER['REQUEST_METHOD'])) {
            if ($this->creCarriersModel->update($_POST['companyName'], $_POST['rfc'], $_POST['crePermissionCarrier'], $_POST['id'])) {
                json_output(['status' => 'success', 'message' => 'Transportista actualizado correctamente']);
            } else {
                json_output(['status' => 'error', 'message' => 'No se pudo actualizar el transportista']);
            }
        }
    }

    function addSupplierModal() {
        if (preg_match('/POST/i',$_SERVER['REQUEST_METHOD'])) {
            $companyName = $_POST['companyName'];
            $rfc = $_POST['rfc'];
            $crePermissionSupplier = $_POST['crePermissionSupplier'];

            // Vamos a verificar si ya existe el registro
            if ($this->creSuppliersModel->exists($crePermissionSupplier)) {
                json_output(['status' => 'error', 'message' => 'El permiso CRE ingresado ya existe en la base de datos']);
            } else {
                if ($this->creSuppliersModel->addRow($companyName, $rfc, $crePermissionSupplier)) {
                    json_output(['status' => 'success', 'message' => 'Proveedor agregado correctamente']);
                } else {
                    json_output(['status' => 'error', 'message' => 'No se pudo agregar el proveedor']);
                }
            }
        }
    }

    function editSupplierModal() {
        if (preg_match('/POST/i',$_SERVER['REQUEST_METHOD'])) {
            if ($this->creSuppliersModel->update($_POST['companyName'], $_POST['rfc'], $_POST['crePermissionSupplier'], $_POST['id'])) {
                json_output(['status' => 'success', 'message' => 'Proveedor actualizado correctamente']);
            } else {
                json_output(['status' => 'error', 'message' => 'No se pudo actualizar el proveedor']);
            }
        }
    }

    function frmCapturaCompra() {
        // Variables
        $codgas = $_POST['codgas'];
        $creProductId = $_POST['creProductId'];
        $creSubProductId = $_POST['creSubProductId'];
        $creSubProductBrandId = $_POST['creSubProductBrandId'];
        $rowid = $_POST['rowid'];
        $controlGasProductId = $_POST['controlGasProductId'];
        $carriers = $this->creCarriersModel->getRows();
        $from = $_POST['from'];

        if ($reception = $this->xsdEstacionServicioVolumenCompradoModel->get_purchase($rowid)) {

            $suppliers = $this->creSuppliersModel->getRows();
            $html = $this->twig->render($this->route . 'modals/frmCapturaCompra.html', compact('codgas','creProductId','creSubProductId','creSubProductBrandId','rowid','controlGasProductId','suppliers','reception','carriers','from'));
            return json_output(['success' => true, 'html' => $html]);
        } else {
            return json_output(['success' => false,'message' => 'No se encontró la compra']);
        }
    }

    function uploadXml() {
        $uploadDir = __DIR__ . '/../../_assets/uploads/creXMLs/';

        // Asegurarse que el directorio exista
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // Validar que llegue el archivo y las variables necesarias
        if (isset($_FILES['xmlFile']) && $_FILES['xmlFile']['error'] === UPLOAD_ERR_OK
            && isset($_POST['companyDenominacion']) && isset($_POST['from'])) {

            $fileTmpPath = $_FILES['xmlFile']['tmp_name'];
            $fileExtension = strtolower(pathinfo($_FILES['xmlFile']['name'], PATHINFO_EXTENSION));

            // Validar la extensión
            if ($fileExtension !== 'xml') {
                die('Error: Solo se permiten archivos XML.');
            }

            // Tomar las variables y formar el nombre
            $companyDenominacion = preg_replace('/[^a-zA-Z0-9]/', '', $_POST['companyDenominacion']); // Solo letras y números
            $from = preg_replace('/[^0-9\-]/', '', $_POST['from']); // Solo números y guiones

            $newFileName = "{$companyDenominacion}_{$from}.xml";

            $destPath = $uploadDir . $newFileName;

            if (file_exists($destPath)) {
                die('Error: Ya existe un archivo con ese nombre.');
            }

            if (move_uploaded_file($fileTmpPath, $destPath)) {
                setFlashMessage('success', 'El archivo XML se subió correctamente.');
                redirect();
            } else {
                echo "Error al mover el archivo XML.";
            }
        } else {
            echo "Error: Datos incompletos o problema en la subida.";
        }
    }

    function fuel_payments() {
        $stations = $this->gasolinerasModel->get_active_stations();
        echo $this->twig->render($this->route . 'fuel_payments.html', compact('stations'));
    }

    function fuel_reconciliation() {
        $stations = $this->gasolinerasModel->get_active_stations();
        $proveedores = [
            ['cod' => 'TESORO', 'den' => 'TESORO'],
            ['cod' => 'MGC', 'den' => 'MGC'],
            ['cod' => 'LOBO', 'den' => 'LOBO'],
            ['cod' => 'PETROTAL', 'den' => 'PETROTAL'],
            ['cod' => 'ESSAFUEL', 'den' => 'ESSAFUEL'],
            ['cod' => 'PREMIERGAS', 'den' => 'PREMIERGAS'],
            ['cod' => 'ENEREY', 'den' => 'ENEREY'],
            ['cod' => 'AEMSA', 'den' => 'AEMSA']
        ];
        $empresas = [
            ['cod' => '1', 'den' => 'TotalGas'],
            ['cod' => '2', 'den' => 'Petrotal']
        ];
        echo $this->twig->render($this->route . 'fuel_reconciliation.html', compact('stations', 'proveedores', 'empresas'));
    }


    function prices_xml() {
        echo $this->twig->render($this->route . 'prices_xml.html');
    }

    function generar_xml_precios() {
        try {
            // Verificar que sea una petición POST
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                $this->responderJSON(false, 'Método no permitido. Use POST.');
            }
            
            // Obtener y validar la hora
            $hora = isset($_POST['hora']) ? trim($_POST['hora']) : '';
            
            if (empty($hora)) {
                $this->responderJSON(false, 'La hora es requerida');
            }
            
            // Validar formato de hora (HH:MM)
            if (!preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $hora)) {
                $this->responderJSON(false, 'Formato de hora inválido. Use HH:MM (ej: 14:00)');
            }

            // Correos adicionales
            $emailsExtra = isset($_POST['emails_extra']) ? (array)$_POST['emails_extra'] : [];
            $emailsExtra = array_filter(array_map('trim', $emailsExtra)); // limpia vacíos

            // Validar formato de correo (simple y suficiente)
            foreach ($emailsExtra as $email) {
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $this->responderJSON(false, "El correo '$email' no es válido");
                }
            }
            
            // Crear instancia y ejecutar
            $generador = new GeneradorXMLPrecios();

            // Pasar correos adicionales a la clase
            if (!empty($emailsExtra)) {
                $generador->setEmailsAdicionales($emailsExtra);
            }

            $resultado = $generador->generarYEnviarXML($hora);
            
            if ($resultado) {
                // Contar archivos generados
                $archivosXML = glob('xml_output/*.xml');
                $totalArchivos = count($archivosXML);
                
                $this->responderJSON(
                    true, 
                    "XMLs generados y enviados correctamente con hora de aplicación: $hora",
                    [
                        'archivos_generados' => $totalArchivos,
                        'hora_aplicacion' => $hora,
                        'archivos' => array_map('basename', $archivosXML)
                    ]
                );
            } else {
                $errores = $generador->getErrores(); // Necesitarás agregar este método
                $this->responderJSON(
                    false, 
                    'Error al generar o enviar los XMLs',
                    ['errores' => $errores]
                );
            }
            
        } catch (Exception $e) {
            // Log del error (opcional)
            error_log("Error en generar_xml.php: " . $e->getMessage());
            
            $this->responderJSON(
                false,
                'Error del servidor: ' . $e->getMessage(),
                ['error_details' => $e->getTraceAsString()]
            );
        }
    }

    // Función para responder en formato JSON
    function responderJSON($success, $message, $data = []) {
        // Configuración de headers para respuestas JSON
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array_merge([
            'success' => $success,
            'message' => $message,
            'timestamp' => date('Y-m-d H:i:s')
        ], $data), JSON_UNESCAPED_UNICODE);
        exit;
    }

    function shops_fuel() {
        $stations = $this->gasolinerasModel->get_active_stations();

        echo $this->twig->render($this->route . 'shops_fuel.html', compact('stations'));
    }
    function providers() {

        echo $this->twig->render($this->route . 'providers.html');
    }

    function add_payment(){
       $all_stations = $this->gasolinerasModel->get_stations();
    
        // Filtrar estaciones para quitar la que tiene cod = 0
        $stations = array_filter($all_stations, function($station) {
            return $station['cod'] != 0; // o !== '0' si cod es string
        });

        $companys = $this->gasolinerasModel->get_company();
        $proveedores = $this->proveedores->get_actives();
        echo $this->twig->render($this->route . 'add_payment.html', compact('stations', 'companys', 'proveedores'));

    }
    public function payment_control_table(){

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
                if (empty($row['satuid'])) {
                    continue; // Skip rows with empty 'nro'
                }
                $data[] = array(
                    'nro'          => $row['nro'],
                    'Factura'      => $row['Factura'],
                    'Remision'     => isset($row['Remision']) ? substr($row['Remision'], 0, 15) : '',
                    'fecha'        => $row['fecha'],
                    'fechaVto'     => $row['fechaVto'],
                    'producto'     => $row['producto'],
                    'proveedor'    => $row['proveedor'],
                    'volrec'       => $row['volrec'],
                    'can'          => $row['can'],
                    'pre'          => $row['pre'],
                    'mto'          => $row['mto'],
                    'mtoiie'       => $row['mtoiie'],
                    'iva8'         => $row['iva8'],
                    'iva'          => $row['iva'],
                    'iva_total'    => $row['iva_total'],
                    'servicio'     => $row['servicio'],
                    'iva_servicio' => $row['iva_servicio'],
                    'total_fac'    => $row['total_fac'],
                    'satuid'       => $row['satuid'],
                    'gasolinera'       => $row['gasolinera'],
                    'codgas'       => $row['codgas']
                );
            }
        }
        json_output(array("data" => $data));
    }

    function generate_payment(){
        $json = file_get_contents('php://input');
        $data = json_decode($json, true);
        $payment = $data['total_amount'] ?? null;
        $documents = $data['documentos'] ?? null;
        $user = $_SESSION['tg_user']['Id'] ?? null;

        $request_date = date('Y-d-m H:i:s');
        $status = 1;
        $comment = 'comentario de prueba'; // Puedes cambiar esto por un comentario real
        $payment_id = $this->paymentRequestsModel->insert_request($request_date, $user,$comment,$status);

        if($payment_id) {
            
            $documents_inserted = $this->paymentRequestInvoicesModel->insertInvoicesBulk($documents, $payment_id);
            
        } else {
            // Error al insertar el pago
        }


        echo '<pre>';
        var_dump($payment_id);
        var_dump($payment);
        var_dump($data);
        // var_dump($documents);
        die();
        if ($data === null) {
            // Error de formato
            http_response_code(400);
            echo json_encode(['detail' => 'JSON inválido']);
            exit;
        }
    }

    function uploadPdf() {
        $uploadDir = __DIR__ . '/../../_assets/uploads/creAcuses/';

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        if (isset($_FILES['pdfFile']) && $_FILES['pdfFile']['error'] === UPLOAD_ERR_OK
            && isset($_POST['companyDenominacion']) && isset($_POST['from'])) {
            // Validar que llegue el archivo y las variables necesarias
            $fileTmpPath = $_FILES['pdfFile']['tmp_name'];
            $fileExtension = strtolower(pathinfo($_FILES['pdfFile']['name'], PATHINFO_EXTENSION));
            // Validar la extensión
            if ($fileExtension !== 'pdf') {
                die('Error: Solo se permiten archivos PDF.');
            }
            // Tomar las variables y formar el nombre
            $companyDenominacion = preg_replace('/[^a-zA-Z0-9]/', '', $_POST['companyDenominacion']);
            $from = preg_replace('/[^0-9\-]/', '', $_POST['from']);

            $newFileName = "{$companyDenominacion}_{$from}.pdf";

            $destPath = $uploadDir . $newFileName;

            if (file_exists($destPath)) {
                die('Error: Ya existe un archivo con ese nombre.');
            }

            if (move_uploaded_file($fileTmpPath, $destPath)) {
                setFlashMessage('success', 'El archivo PDF se subió correctamente.');
                redirect();
            } else {
                echo "Error al mover el archivo PDF.";
            }
        } else {
            echo "Error: Faltan los siguientes datos o hay un problema en la subida.";
            if (!isset($_FILES['pdfFile'])) {
                echo " - Archivo PDF";
            }
            if (!isset($_POST['companyDenominacion'])) {
                echo " - Denominación de la empresa";
            }
            if (!isset($_POST['from'])) {
                echo " - Fecha";
            }
            if (isset($_FILES['pdfFile']) && $_FILES['pdfFile']['error'] !== UPLOAD_ERR_OK) {
                echo " - Error en la subida del archivo PDF: " . $_FILES['pdfFile']['error'];
            }
        }
    }


    public function providers_table(){
        $data = [];
        if ( $providers = $this->proveedores->get_rows()) {

            foreach ($providers as $row) {
                if($row['total_facturado'] != 0){
                    
                    $data[] = array(
                        'id'               => $row['id'],
                        'id_control_gas'               => $row['id_control_gas'],
                        'proveedor'        => $row['den'],
                        'dias_credito'     => $row['dias_credito'],
                        'limite_credito'   => is_null($row['limite_credito']) ? 0 : $row['limite_credito'],
                        'condiciones_pago' => $row['condiciones_pago'],
                        'total_facturado'  => is_null($row['total_facturado']) ? 0 : $row['total_facturado'],
                        'observaciones'    => $row['observaciones'],
                        'activo'           => $row['activo'],
                    );
                }
            }
        }
        json_output(array("data" => $data));
    }

    function descargar_facturas() {
        echo $this->twig->render($this->route . 'descargar_facturas.html');
    }

   function procesar_uuids_facturas() {
    header('Content-Type: application/json');
    
    try {
        // Validar que llegue el archivo
        if (!isset($_FILES['archivo_excel']) || $_FILES['archivo_excel']['error'] !== UPLOAD_ERR_OK) {
            json_output(['success' => false, 'message' => 'No se recibió el archivo o hubo un error']);
            return;
        }

        $archivo = $_FILES['archivo_excel']['tmp_name'];
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($archivo);
        $sheet = $spreadsheet->getActiveSheet();
        
        $uuidsValidos = [];
        $uuidsInvalidos = [];
        $highestRow = $sheet->getHighestRow();
        
        // Leer TODOS los UUIDs de la primera columna
        for ($row = 2; $row <= $highestRow; $row++) {
            $uuid = $sheet->getCell('A' . $row)->getValue();
            if (!empty($uuid)) {
                $uuid = trim($uuid);
                $uuidOriginal = $uuid;
                $uuid = strtoupper($uuid);
                
                // Validar formato UUID
                if (strlen($uuid) !== 36) {
                    // UUID inválido - longitud incorrecta
                    $uuidsInvalidos[] = [
                        'fila' => $row,
                        'uuid' => $uuid,
                        'estado' => 'formato_invalido',
                        'error' => 'UUID con formato inválido (longitud: ' . strlen($uuid) . ', debe ser 36 caracteres)'
                    ];
                } else if (!preg_match('/^[A-F0-9]{8}-[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{12}$/i', $uuid)) {
                    // UUID inválido - formato incorrecto
                    $uuidsInvalidos[] = [
                        'fila' => $row,
                        'uuid' => $uuid,
                        'estado' => 'formato_invalido',
                        'error' => 'UUID con formato inválido (no cumple patrón XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX)'
                    ];
                } else {
                    // UUID válido
                    $uuidsValidos[] = $uuid;
                }
            }
        }

        $totalSolicitados = count($uuidsValidos) + count($uuidsInvalidos);
        if ($totalSolicitados === 0) {
            json_output(['success' => false, 'message' => 'No se encontraron UUIDs en el archivo']);
            return;
        }
        
        // Buscar facturas en la base de datos (solo con UUIDs válidos)
        $facturas = [];
        if (!empty($uuidsValidos)) {
            $facturas = $this->facturasRecibidasModel->buscarPorUUIDs($uuidsValidos);
        }

        // CAMBIO CLAVE: Usar arrays separados desde el inicio
        $facturasExitosas = [];
        $facturasFallidas = [];
        $uuidsEncontrados = [];

        if ($facturas) {
            foreach ($facturas as $factura) {
                // ✅ SOLUCIÓN: Guardar UUID en MAYÚSCULAS para comparación consistente
                $uuidBD = strtoupper($factura['UUID']);
                $uuidsEncontrados[] = $uuidBD;

                // Verificar que el archivo exista ANTES de agregarlo como exitosa
                if (!empty($factura['RutaArchivo']) && file_exists($factura['RutaArchivo'])) {
                    // ✅ Factura completa y disponible
                    $facturasExitosas[] = [
                        'id' => $factura['Id'],
                        'uuid' => $factura['UUID'],
                        'nombre_archivo' => $factura['NombreArchivo'] ?? basename($factura['RutaArchivo']),
                        'folio' => $factura['Folio'],
                        'emisor' => $factura['EmisorNombre'],
                        'total' => $factura['Total'],
                        'estado' => 'success'
                    ];
                } else {
                    // ❌ Factura en BD pero archivo no existe
                    $facturasFallidas[] = [
                        'uuid' => $factura['UUID'],
                        'folio' => $factura['Folio'],
                        'estado' => 'archivo_no_existe',
                        'error' => 'Factura encontrada en BD pero archivo físico no existe: ' . 
                                   ($factura['NombreArchivo'] ?? basename($factura['RutaArchivo'] ?? 'desconocido'))
                    ];
                }
            }
        }
        
        // Identificar UUIDs válidos que NO se encontraron en la BD
        // ✅ SOLUCIÓN: Ahora la comparación es case-insensitive (ambos en MAYÚSCULAS)
        foreach ($uuidsValidos as $uuid) {
            if (!in_array($uuid, $uuidsEncontrados)) {
                $facturasFallidas[] = [
                    'uuid' => $uuid,
                    'folio' => null,
                    'estado' => 'no_encontrado_bd',
                    'error' => 'UUID no encontrado en la base de datos'
                ];
            }
        }
        
        // Agregar UUIDs con formato inválido a fallidas
        $facturasFallidas = array_merge($facturasFallidas, $uuidsInvalidos);
        
        // Resultado final
        json_output([
            'success' => true,
            'facturas' => array_values($facturasExitosas),
            'facturas_fallidas' => array_values($facturasFallidas),
            'total_solicitados' => $totalSolicitados,
            'total_encontrados' => count($facturasExitosas),
            'total_fallidos' => count($facturasFallidas)
        ]);
        
    } catch (Exception $e) {
        json_output([
            'success' => false,
            'message' => 'Error al procesar el archivo: ' . $e->getMessage()
        ]);
    }
}

    /**
     * Descargar archivo de factura individual
     */
    function descargar_factura($id) {
        try {
            $factura = $this->facturasRecibidasModel->obtenerPorId($id);
            
            if (!$factura) {
                http_response_code(404);
                echo json_encode(['message' => 'Factura no encontrada']);
                return;
            }
            
            if (empty($factura['RutaArchivo']) || !file_exists($factura['RutaArchivo'])) {
                http_response_code(404);
                echo json_encode(['message' => 'Archivo no encontrado en el servidor']);
                return;
            }
            
            $nombreArchivo = $factura['NombreArchivo'] ?? basename($factura['RutaArchivo']);
            $rutaArchivo = $factura['RutaArchivo'];
            
            // Establecer headers para descarga
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
            header('Content-Length: ' . filesize($rutaArchivo));
            header('Cache-Control: no-cache, must-revalidate');
            header('Pragma: public');
            
            // Limpiar buffer de salida
            ob_clean();
            flush();
            
            // Leer y enviar el archivo
            readfile($rutaArchivo);
            exit;
            
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['message' => 'Error al descargar: ' . $e->getMessage()]);
        }
    }

    /**
 * Descargar múltiples facturas en un archivo ZIP
 */
function descargar_facturas_zip() {
    header('Content-Type: application/json');
    
    try {
        // Recibir los IDs de las facturas a descargar
        $input = json_decode(file_get_contents('php://input'), true);
        $ids = $input['ids'] ?? [];
        
        if (empty($ids)) {
            json_output(['success' => false, 'message' => 'No se proporcionaron IDs de facturas']);
            return;
        }
        
        // Crear directorio temporal si no existe
        $tempDir = __DIR__ . '/../temp/';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0777, true);
        }
        
        // Nombre único para el archivo ZIP
        $zipFileName = 'facturas_' . date('YmdHis') . '_' . uniqid() . '.zip';
        $zipPath = $tempDir . $zipFileName;
        
        // Crear archivo ZIP
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE) !== TRUE) {
            json_output(['success' => false, 'message' => 'No se pudo crear el archivo ZIP']);
            return;
        }
        
        $archivosAgregados = 0;
        $archivosNoEncontrados = [];
        
        // Agregar cada factura al ZIP
        foreach ($ids as $id) {
            $factura = $this->facturasRecibidasModel->obtenerPorId($id);
            
            if ($factura && !empty($factura['RutaArchivo']) && file_exists($factura['RutaArchivo'])) {
                $nombreArchivo = $factura['NombreArchivo'] ?? basename($factura['RutaArchivo']);
                
                // Agregar archivo al ZIP
                if ($zip->addFile($factura['RutaArchivo'], $nombreArchivo)) {
                    $archivosAgregados++;
                } else {
                    $archivosNoEncontrados[] = $nombreArchivo;
                }
            } else {
                $archivosNoEncontrados[] = 'Factura ID: ' . $id;
            }
        }
        
        $zip->close();
        
        // Verificar que se agregó al menos un archivo
        if ($archivosAgregados === 0) {
            unlink($zipPath); // Eliminar ZIP vacío
            json_output([
                'success' => false, 
                'message' => 'No se encontraron archivos para descargar',
                'archivos_no_encontrados' => $archivosNoEncontrados
            ]);
            return;
        }
        
        // Retornar información del ZIP creado
        json_output([
            'success' => true,
            'zip_file' => $zipFileName,
            'archivos_agregados' => $archivosAgregados,
            'archivos_no_encontrados' => $archivosNoEncontrados,
            'download_url' => '/supply/download_zip/' . $zipFileName
        ]);
        
    } catch (Exception $e) {
        json_output([
            'success' => false,
            'message' => 'Error al crear ZIP: ' . $e->getMessage()
        ]);
    }
}

/**
 * Descargar el archivo ZIP generado
 */
function download_zip($zipFileName) {
    try {
        // Validar nombre de archivo (seguridad)
        if (!preg_match('/^facturas_\d{14}_[a-z0-9]+\.zip$/', $zipFileName)) {
            http_response_code(400);
            die('Nombre de archivo inválido');
        }
        
        $zipPath = __DIR__ . '/../temp/' . $zipFileName;
        
        if (!file_exists($zipPath)) {
            http_response_code(404);
            die('Archivo ZIP no encontrado');
        }
        
        // Limpiar buffer
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        // Headers para descarga
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $zipFileName . '"');
        header('Content-Length: ' . filesize($zipPath));
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: public');
        
        // Enviar archivo
        readfile($zipPath);
        
        // Eliminar archivo temporal después de enviarlo
        unlink($zipPath);
        exit;
        
    } catch (Exception $e) {
        http_response_code(500);
        die('Error al descargar ZIP: ' . $e->getMessage());
    }
}

    /**
     * Limpiar archivos ZIP antiguos (ejecutar periódicamente)
     */
    function limpiar_zips_antiguos() {
        $tempDir = __DIR__ . '/../temp/';
        
        if (!is_dir($tempDir)) {
            return;
        }
        
        $archivos = glob($tempDir . 'facturas_*.zip');
        $horaLimite = time() - (3600 * 2); // 2 horas
        $eliminados = 0;
        
        foreach ($archivos as $archivo) {
            if (filemtime($archivo) < $horaLimite) {
                unlink($archivo);
                $eliminados++;
            }
        }
        
        json_output([
            'success' => true,
            'archivos_eliminados' => $eliminados
        ]);
    }

    


    // public function resumen_payment_table() {
    //     ini_set('max_execution_time', 5000);
    //     ini_set('memory_limit', '1024M');
    //     set_time_limit(0);
    //     header('Content-Type: application/json');
        
    //     $postData = [
    //         'from' => isset($_POST['fromDate']) ? dateToInt($_POST['fromDate']) : null,
    //         'until' => isset($_POST['untilDate']) ? dateToInt($_POST['untilDate']) : null,
    //         'codgas' => isset($_POST['codgas']) ? $_POST['codgas'] : '0',
    //         'proveedor' => isset($_POST['proveedor']) ? $_POST['proveedor'] : '0',
    //         'company' => isset($_POST['company']) ? $_POST['company'] : '0'
    //     ];

    //     if (!$postData['from'] || !$postData['until']) {
    //         json_output(['error' => true, 'message' => 'Fechas requeridas', 'data' => []]);
    //         return;
    //     }

    //     try {
    //         $ch = curl_init('http://192.168.0.109:82/api/resumen_movimientos_tanques/');
    //         curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    //         curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    //         curl_setopt($ch, CURLOPT_POST, true);
    //         curl_setopt($ch, CURLOPT_TIMEOUT, 600);
    //         curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);

    //         $response = curl_exec($ch);
    //         $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
    //         if (curl_errno($ch)) {
    //             throw new Exception('Error de cURL: ' . curl_error($ch));
    //         }
            
    //         curl_close($ch);

    //         if ($httpCode !== 200) {
    //             throw new Exception("Error HTTP: $httpCode");
    //         }

    //         $apiData = json_decode($response, true);

    //         if (json_last_error() !== JSON_ERROR_NONE) {
    //             throw new Exception('Error al decodificar JSON: ' . json_last_error_msg());
    //         }

    //        // ========== NUEVA LÓGICA: Obtener facturas asignadas ==========
    //         $facturasAsignadas = $this->facturasRecibidasModel->obtener_facturas_asignadas();
    //         $data = [];

    //         if (isset($apiData) && is_array($apiData)) {
    //             foreach ($apiData as $row) {
    //                 if (empty($row['nrotrn'])) {
    //                     continue;
    //                 }

    //                 // Normalizar combustible
    //                 $raw = isset($row['combustible']) ? trim($row['combustible']) : '';
    //                 $norm = mb_strtolower($raw, 'UTF-8');
    //                 $norm = str_replace(['.', '-', '_'], ' ', $norm);
    //                 $norm = preg_replace('/\s+/', ' ', $norm);
    //                 $norm = strtr($norm, "áéíóúÁÉÍÓÚñÑ", "aeiouAEIOUnN");

    //                 $combustible = $raw;
    //                 if (preg_match('/\b(regular|menor a 91|91 octanos|t ?maxima|maxima regular|t ?maxima regular)\b/i', $norm)) {
    //                     $combustible = 'Regular';
    //                 } elseif (preg_match('/\b(diesel|diesel automotriz)\b/i', $norm)) {
    //                     $combustible = 'Diesel';
    //                 } elseif (preg_match('/\b(premium|super premium|mayor o igual a 91|91 octanos)\b/i', $norm)) {
    //                     $combustible = 'Premium';
    //                 } else {
    //                     $combustible = mb_convert_case($norm, MB_CASE_TITLE, "UTF-8"); 
    //                 }

    //                 // Normalizar proveedor
    //                 $proveedor_controlgas = $row['proveedor_controlgas'];
    //                 if ($row['proveedor_controlgas'] == 'TESORO MEXICO SUPPLY & MARKETING S. DE R.L. DE C.V.') {
    //                     $proveedor_controlgas = 'TESORO';
    //                 }
    //                 if ($row['proveedor_controlgas'] == 'PREMIERGAS S.A. P. I. DE C.V.') {
    //                     $proveedor_controlgas = 'PREMIERGAS';
    //                 }
    //                 if ($row['proveedor_controlgas'] == 'MGC MEXICO S.A. DE C.V.') {
    //                     $proveedor_controlgas = 'MGC';
    //                 }

    //                 // ========== BUSCAR FACTURA ASIGNADA ==========
    //                 $nrotrn = $row['nrotrn'];
    //                 $codgas = $row['numero_estacion']; // Usar numero_estacion como codgas
                    
    //                 $facturaAsignada = null;
    //                 $uuidAsignado = '';
    //                 $folioAsignado = '';
    //                 $tieneFactura = false;
                    
    //                 // Buscar en el array de facturas asignadas
    //                 $key = $nrotrn . '_' . $codgas;
    //                 if (isset($facturasAsignadas[$key])) {
    //                     $facturaAsignada = $facturasAsignadas[$key];
    //                     $uuidAsignado = $facturaAsignada['UUID'];
    //                     $folioAsignado = $facturaAsignada['Folio'];
    //                     $tieneFactura = true;
    //                 }

    //                 $data[] = [
    //                     'fecha'                       => $row['fecha'] ?? '',
    //                     'hora'                        => $row['hora_formateada'] ?? '',
    //                     'nrotrn'                      => $nrotrn,
    //                     'estacion'                    => $row['estacion'] ?? '',
    //                     'numero_estacion'             => $codgas,
    //                     'proveedor_original'          => $proveedor_controlgas,
    //                     'num_fac_proveedor'           => $folioAsignado, // Folio de la factura asignada
    //                     'proveedor_final'             => $proveedor_controlgas,
    //                     'combustible'                 => $combustible,
    //                     'capmax'                      => $row['capmax'] ?? 0,
    //                     'recaudado'                   => $row['recaudado'] ?? 0,
    //                     'fac_rec'                     => $row['fac_rec'] ?? 0,
    //                     'nro_fac'                     => $row['nro_fac'] ?? '',
    //                     'uuid'                        => $uuidAsignado, // UUID de la factura asignada
    //                     'proveedor_controlgas'        => $proveedor_controlgas,
    //                     'monto_factura_controlgas'    => $row['monto_factura_controlgas'] ?? 0,
    //                     'cantidad_factura_controlgas' => $row['cantidad_factura_controlgas'] ?? 0,
    //                     'precio_factura_controlgas'   => $row['precio_factura_controlgas'] ?? 0,
    //                     'graprd'                      => $row['graprd'] ?? '',
    //                     'tiene_factura'               => $tieneFactura, // Flag para la UI
    //                     'factura_id'                  => $tieneFactura ? $facturaAsignada['Id'] : null
    //                 ];
    //             }
    //         }

    //         json_output(['data' => $data]);

    //     } catch (Exception $e) {
    //         error_log("Error en resumen_payment_table: " . $e->getMessage());
    //         json_output(['error' => true, 'message' => $e->getMessage(), 'data' => []]);
    //     }
    // }

    public function resumen_payment_table() {
    ini_set('max_execution_time', 5000);
    ini_set('memory_limit', '1024M');
    set_time_limit(0);
    header('Content-Type: application/json');

    $postData = [
        'from' => isset($_POST['fromDate']) ? dateToInt($_POST['fromDate']) : null,
        'until' => isset($_POST['untilDate']) ? dateToInt($_POST['untilDate']) : null,
        'codgas' => isset($_POST['codgas']) ? $_POST['codgas'] : '0',
        'proveedor' => isset($_POST['proveedor']) ? $_POST['proveedor'] : '0',
        'company' => isset($_POST['company']) ? $_POST['company'] : '0'
    ];

    if (!$postData['from'] || !$postData['until']) {
        json_output(['error' => true, 'message' => 'Fechas requeridas', 'data' => []]);
        return;
    }

    try {
        $ch = curl_init('http://192.168.0.109:82/api/resumen_movimientos_tanques/');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 600);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            throw new Exception('Error de cURL: ' . curl_error($ch));
        }
        
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception("Error HTTP: $httpCode");
        }

        $apiData = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Error al decodificar JSON: ' . json_last_error_msg());
        }

        $data = [];

        if (isset($apiData) && is_array($apiData)) {
            foreach ($apiData as $row) {
                if (empty($row['nrotrn'])) {
                    continue;
                }

                // Normalizar combustible
                $raw = isset($row['combustible']) ? trim($row['combustible']) : '';
                $norm = mb_strtolower($raw, 'UTF-8');
                $norm = str_replace(['.', '-', '_'], ' ', $norm);
                $norm = preg_replace('/\s+/', ' ', $norm);
                $norm = strtr($norm, "áéíóúÁÉÍÓÚñÑ", "aeiouAEIOUnN");

                $combustible = $raw;
                if (preg_match('/\b(regular|menor a 91|91 octanos|t ?maxima|maxima regular|t ?maxima regular)\b/i', $norm)) {
                    $combustible = 'Regular';
                } elseif (preg_match('/\b(diesel|diesel automotriz)\b/i', $norm)) {
                    $combustible = 'Diesel';
                } elseif (preg_match('/\b(premium|super premium|mayor o igual a 91|91 octanos)\b/i', $norm)) {
                    $combustible = 'Premium';
                } else {
                    $combustible = mb_convert_case($norm, MB_CASE_TITLE, "UTF-8"); 
                }

                // Normalizar proveedor
                $proveedor_controlgas = $row['proveedor_controlgas'];
                if ($row['proveedor_controlgas'] == 'TESORO MEXICO SUPPLY & MARKETING S. DE R.L. DE C.V.') {
                    $proveedor_controlgas = 'TESORO';
                }
                if ($row['proveedor_controlgas'] == 'PREMIERGAS S.A. P. I. DE C.V.') {
                    $proveedor_controlgas = 'PREMIERGAS';
                }
                if ($row['proveedor_controlgas'] == 'MGC MEXICO S.A. DE C.V.') {
                    $proveedor_controlgas = 'MGC';
                }

                // ========== DATOS YA VIENEN CON LA FACTURA ASIGNADA ==========
                $tieneFactura = (bool)($row['tiene_factura_asignada'] ?? 0);
                $uuidMostrar = $tieneFactura ? ($row['uuid_asignado'] ?? '') : ($row['uuid_original'] ?? '');
                $folioMostrar = $tieneFactura ? ($row['folio_asignado'] ?? '') : ($row['nro_fac'] ?? '');

                $data[] = [
                    'fecha'                       => $row['fecha'] ?? '',
                    'hora'                        => $row['hora_formateada'] ?? '',
                    'nrotrn'                      => $row['nrotrn'],
                    'codgas'                      => $row['codgas'] ?? '',
                    'estacion'                    => $row['estacion'] ?? '',
                    'numero_estacion'             => $row['numero_estacion'] ?? '',
                    'proveedor_original'          => $proveedor_controlgas,
                    'num_fac_proveedor'           => $folioMostrar,
                    'proveedor_final'             => $proveedor_controlgas,
                    'combustible'                 => $combustible,
                    'capmax'                      => $row['capmax'] ?? 0,
                    'recaudado'                   => $row['recaudado'] ?? 0,
                    'fac_rec'                     => $row['fac_rec'] ?? 0,
                    'nro_fac'                     => $row['nro_fac'] ?? '',
                    'uuid'                        => $uuidMostrar,
                    'proveedor_controlgas'        => $proveedor_controlgas,
                    'monto_factura_controlgas'    => $row['monto_factura_controlgas'] ?? 0,
                    'cantidad_factura_controlgas' => $row['cantidad_factura_controlgas'] ?? 0,
                    'precio_factura_controlgas'   => $row['precio_factura_controlgas'] ?? 0,
                    'graprd'                      => $row['graprd'] ?? '',
                    
                    // ========== INFORMACIÓN DE LA FACTURA ASIGNADA ==========
                    'tiene_factura'               => $tieneFactura,
                    'factura_id'                  => $tieneFactura ? ($row['factura_asignacion_id'] ?? null) : null,
                    'fecha_asignacion'            => $tieneFactura ? ($row['fecha_asignacion'] ?? null) : null,
                    'usuario_asignacion'          => $tieneFactura ? ($row['usuario_asignacion'] ?? null) : null,
                    'observaciones_asignacion'    => $tieneFactura ? ($row['observaciones_asignacion'] ?? null) : null,
                    'total_factura_asignada'      => $tieneFactura ? ($row['total_factura_asignada'] ?? 0) : 0,
                    'emisor_factura_asignada'     => $tieneFactura ? ($row['emisor_factura_asignada'] ?? '') : '',
                    'destino_factura'             => $tieneFactura ? ($row['destino_factura_asignada'] ?? '') : '',
                    'remision_factura'            => $tieneFactura ? ($row['remision_factura_asignada'] ?? '') : ''
                ];
            }
        }

        json_output(['data' => $data]);

    } catch (Exception $e) {
        error_log("Error en resumen_payment_table: " . $e->getMessage());
        json_output(['error' => true, 'message' => $e->getMessage(), 'data' => []]);
    }
    }



    // ========== NUEVO ENDPOINT: Buscar facturas disponibles ==========
    public function buscar_facturas_disponibles() {
        header('Content-Type: application/json');
        
        $searchTerm = isset($_POST['search']) ? $_POST['search'] : '';
        $fechaInicio = isset($_POST['fecha_inicio']) ? $_POST['fecha_inicio'] : '';
        $fechaFin = isset($_POST['fecha_fin']) ? $_POST['fecha_fin'] : '';
        
        try {
            

            $facturas_recibidas = $this->facturasRecibidasModel->buscar_facturas_disponibles($searchTerm, $fechaInicio, $fechaFin);

            $facturas = [];
            foreach ($facturas_recibidas as $key => $row) {
                $facturas[] = [
                    'id' => $row['Id'],
                    'uuid' => $row['UUID'],
                    'folio' => $row['Folio'],
                    'fecha' => $row['Fecha'],
                    'total' => $row['Total'],
                    'emisor_nombre' => $row['EmisorNombre'],
                    'emisor_rfc' => $row['EmisorRfc'],
                    'destino' => $row['Destino'],
                    'remision' => $row['Remision'],
                    'ya_asignada' => $row['YaAsignada']
                ];
            }


            json_output(['success' => true, 'data' => $facturas]);

        } catch (Exception $e) {
            error_log("Error en buscar_facturas_disponibles: " . $e->getMessage());
            json_output(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    // ========== NUEVO ENDPOINT: Asignar factura a movimiento ==========
    public function asignar_factura_movimiento() {
        header('Content-Type: application/json');
        
        $facturaId = isset($_POST['factura_id']) ? intval($_POST['factura_id']) : 0;
        $nrotrn = isset($_POST['nrotrn']) ? intval($_POST['nrotrn']) : 0;
        $codgas = isset($_POST['codgas']) ? intval($_POST['codgas']) : 0;
        $observaciones = isset($_POST['observaciones']) ? $_POST['observaciones'] : '';
        $usuario = isset($_SESSION['usuario']) ? $_SESSION['usuario'] : 'Sistema';
        
        if ($facturaId == 0 || $nrotrn == 0 || $codgas == 0) {
            json_output(['success' => false, 'message' => 'Parámetros inválidos']);
            return;
        }
        
        try {
            $conn_str = 'DRIVER={ODBC Driver 17 for SQL Server};SERVER=192.168.0.6;DATABASE=TG;UID=cguser;PWD=sahei1712';
            $conn = odbc_connect($conn_str, '', '');
            
            if (!$conn) {
                throw new Exception("Error al conectar con la base de datos");
            }

            // Obtener UUID de la factura
            $queryUUID = "SELECT UUID FROM FacturasRecibidas WHERE Id = $facturaId";
            $resultUUID = odbc_exec($conn, $queryUUID);
            
            if (!$resultUUID || !($rowUUID = odbc_fetch_array($resultUUID))) {
                throw new Exception("Factura no encontrada");
            }
            
            $uuid = $rowUUID['UUID'];

            // Verificar si ya existe una asignación
            $queryCheck = "SELECT Id FROM FacturasMovimientosTanques WHERE nrotrn = $nrotrn AND codgas = $codgas";
            $resultCheck = odbc_exec($conn, $queryCheck);
            
            if ($rowCheck = odbc_fetch_array($resultCheck)) {
                // Ya existe, actualizar
                $queryUpdate = "
                    UPDATE FacturasMovimientosTanques 
                    SET FacturaId = $facturaId, 
                        UUID = '$uuid',
                        FechaAsignacion = GETDATE(),
                        UsuarioAsignacion = '$usuario',
                        Observaciones = '$observaciones',
                        Activo = 1
                    WHERE nrotrn = $nrotrn AND codgas = $codgas
                ";
                
                if (!odbc_exec($conn, $queryUpdate)) {
                    throw new Exception("Error al actualizar: " . odbc_errormsg($conn));
                }
                
                $message = "Asignación actualizada correctamente";
            } else {
                // No existe, insertar
                $queryInsert = "
                    INSERT INTO FacturasMovimientosTanques 
                    (FacturaId, UUID, nrotrn, codgas, UsuarioAsignacion, Observaciones)
                    VALUES ($facturaId, '$uuid', $nrotrn, $codgas, '$usuario', '$observaciones')
                ";
                
                if (!odbc_exec($conn, $queryInsert)) {
                    throw new Exception("Error al insertar: " . odbc_errormsg($conn));
                }
                
                $message = "Factura asignada correctamente";
            }

            odbc_close($conn);
            json_output(['success' => true, 'message' => $message]);

        } catch (Exception $e) {
            error_log("Error en asignar_factura_movimiento: " . $e->getMessage());
            json_output(['success' => false, 'message' => $e->getMessage()]);
        }
    }


    /**
     * Relacionar factura con movimiento de tanque
     * Endpoint que utiliza el modelo FacturasMovimientosTanquesModel
     */
    public function relacionar_factura_movimiento() {
        header('Content-Type: application/json');

        // Obtener parámetros del POST
        $nrotrn = isset($_POST['nrotrn']) ? intval($_POST['nrotrn']) : 0;
        $codgas = isset($_POST['codgas']) ? $_POST['codgas'] : '';
        $facturaProveedorId = isset($_POST['factura_proveedor_id']) ? intval($_POST['factura_proveedor_id']) : 0;
        $uuidProveedor = isset($_POST['uuid_proveedor']) ? $_POST['uuid_proveedor'] : '';
        $folioProveedor = isset($_POST['folio_proveedor']) ? $_POST['folio_proveedor'] : '';
        $montoProveedor = isset($_POST['monto_proveedor']) ? floatval($_POST['monto_proveedor']) : 0;
        $litrosProveedor = isset($_POST['litros_proveedor']) ? floatval($_POST['litros_proveedor']) : 0;
        $precioProveedor = isset($_POST['precio_proveedor']) ? floatval($_POST['precio_proveedor']) : 0;
        $observaciones = isset($_POST['observaciones']) ? $_POST['observaciones'] : '';
        $tipoOperacion = isset($_POST['tipo_operacion']) ? intval($_POST['tipo_operacion']) : 1;
        $petrotal = isset($_POST['petrotal']) ? intval($_POST['petrotal']) : 0;
        $usuario = isset($_SESSION['usuario']) ? $_SESSION['usuario'] : 'Sistema';

        // Llamar al modelo para relacionar la factura
        $resultado = $this->facturasMovimientosTanquesModel->relacionarFacturaMovimiento(
            $nrotrn,
            $codgas,
            $facturaProveedorId,
            $uuidProveedor,
            $folioProveedor,
            $montoProveedor,
            $litrosProveedor,
            $precioProveedor,
            $observaciones,
            $tipoOperacion,
            $petrotal,
            $usuario
        );

        // Retornar resultado como JSON
        json_output($resultado);
    }

    // ========== NUEVO ENDPOINT: Buscar facturas de PROVEEDOR (excluir Petrotal) ==========
    public function buscar_facturas_proveedor() {
        header('Content-Type: application/json');
        
        $searchTerm = isset($_POST['search']) ? $_POST['search'] : '';
        $fechaInicio = isset($_POST['fecha_inicio']) ? $_POST['fecha_inicio'] : '';
        $fechaFin = isset($_POST['fecha_fin']) ? $_POST['fecha_fin'] : '';
        
        try {
            $conn_str = 'DRIVER={ODBC Driver 17 for SQL Server};SERVER=192.168.0.6;DATABASE=TG;UID=cguser;PWD=sahei1712';
            $conn = odbc_connect($conn_str, '', '');
            
            if (!$conn) {
                throw new Exception("Error al conectar con la base de datos");
            }

            $whereClauses = [];
            
            // Excluir facturas de Petrotal (emisor)
            $whereClauses[] = "fr.EmisorNombre NOT LIKE '%PETROTAL%'";
            
            if (!empty($searchTerm)) {
                $whereClauses[] = "(fr.UUID LIKE '%$searchTerm%' OR fr.Folio LIKE '%$searchTerm%' OR fr.EmisorNombre LIKE '%$searchTerm%')";
            }
            
            if (!empty($fechaInicio) && !empty($fechaFin)) {
                $whereClauses[] = "fr.Fecha BETWEEN '$fechaInicio' AND '$fechaFin'";
            }
            
            $whereSQL = !empty($whereClauses) ? "WHERE " . implode(" AND ", $whereClauses) : "";

            $query = "
                SELECT TOP 50
                    fr.Id,
                    fr.UUID,
                    fr.Folio,
                    fr.Fecha,
                    fr.Total,
                    fr.EmisorNombre,
                    fr.EmisorRfc,
                    fr.Destino,
                    fr.Remision,
                    -- Sumar litros de los conceptos
                    ISNULL((
                        SELECT SUM(Cantidad) 
                        FROM FacturasRecibidasConceptos 
                        WHERE FacturaId = fr.Id
                    ), 0) as litros,
                    CASE 
                        WHEN fmt.Id IS NOT NULL THEN 1 
                        ELSE 0 
                    END AS YaAsignada
                FROM FacturasRecibidas fr
                LEFT JOIN FacturasMovimientosTanques fmt 
                    ON (fr.Id = fmt.FacturaProveedorId OR fr.Id = fmt.FacturaPetrotalId) 
                    AND fmt.Activo = 1
                $whereSQL
                ORDER BY fr.Fecha DESC
            ";
            
            $result = odbc_exec($conn, $query);
            
            if (!$result) {
                throw new Exception("Error en la consulta: " . odbc_errormsg($conn));
            }

            $facturas = [];
            while ($row = odbc_fetch_array($result)) {
                $facturas[] = [
                    'id' => $row['Id'],
                    'uuid' => $row['UUID'],
                    'folio' => $row['Folio'],
                    'fecha' => $row['Fecha'],
                    'total' => $row['Total'],
                    'emisor_nombre' => $row['EmisorNombre'],
                    'emisor_rfc' => $row['EmisorRfc'],
                    'destino' => $row['Destino'],
                    'remision' => $row['Remision'],
                    'litros' => $row['litros'],
                    'ya_asignada' => $row['YaAsignada']
                ];
            }

            odbc_close($conn);
            json_output(['success' => true, 'data' => $facturas]);

        } catch (Exception $e) {
            error_log("Error en buscar_facturas_proveedor: " . $e->getMessage());
            json_output(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    // ========== NUEVO ENDPOINT: Buscar facturas de PETROTAL específicamente ==========
    public function buscar_facturas_petrotal() {
        header('Content-Type: application/json');
        
        $searchTerm = isset($_POST['search']) ? $_POST['search'] : '';
        $fechaInicio = isset($_POST['fecha_inicio']) ? $_POST['fecha_inicio'] : '';
        $fechaFin = isset($_POST['fecha_fin']) ? $_POST['fecha_fin'] : '';
        
        try {
            $conn_str = 'DRIVER={ODBC Driver 17 for SQL Server};SERVER=192.168.0.6;DATABASE=TG;UID=cguser;PWD=sahei1712';
            $conn = odbc_connect($conn_str, '', '');
            
            if (!$conn) {
                throw new Exception("Error al conectar con la base de datos");
            }

            $whereClauses = [];
            
            // SOLO facturas emitidas por Petrotal
            $whereClauses[] = "fr.EmisorNombre LIKE '%PETROTAL%'";
            
            // Y que sean facturas recibidas por TotalGas (receptor)
            $whereClauses[] = "fr.ReceptorNombre LIKE '%TOTAL%GAS%'";
            
            if (!empty($searchTerm)) {
                $whereClauses[] = "(fr.UUID LIKE '%$searchTerm%' OR fr.Folio LIKE '%$searchTerm%')";
            }
            
            if (!empty($fechaInicio) && !empty($fechaFin)) {
                $whereClauses[] = "fr.Fecha BETWEEN '$fechaInicio' AND '$fechaFin'";
            }
            
            $whereSQL = !empty($whereClauses) ? "WHERE " . implode(" AND ", $whereClauses) : "";

            $query = "
                SELECT TOP 50
                    fr.Id,
                    fr.UUID,
                    fr.Folio,
                    fr.Fecha,
                    fr.Total,
                    fr.ReceptorNombre,
                    fr.Destino,
                    fr.Remision,
                    -- Sumar litros de los conceptos
                    ISNULL((
                        SELECT SUM(Cantidad) 
                        FROM FacturasRecibidasConceptos 
                        WHERE FacturaId = fr.Id
                    ), 0) as litros,
                    CASE 
                        WHEN fmt.Id IS NOT NULL THEN 1 
                        ELSE 0 
                    END AS YaAsignada
                FROM FacturasRecibidas fr
                LEFT JOIN FacturasMovimientosTanques fmt 
                    ON fr.Id = fmt.FacturaPetrotalId 
                    AND fmt.Activo = 1
                $whereSQL
                ORDER BY fr.Fecha DESC
            ";
            
            $result = odbc_exec($conn, $query);
            
            if (!$result) {
                throw new Exception("Error en la consulta: " . odbc_errormsg($conn));
            }

            $facturas = [];
            while ($row = odbc_fetch_array($result)) {
                $facturas[] = [
                    'id' => $row['Id'],
                    'uuid' => $row['UUID'],
                    'folio' => $row['Folio'],
                    'fecha' => $row['Fecha'],
                    'total' => $row['Total'],
                    'receptor_nombre' => $row['ReceptorNombre'],
                    'destino' => $row['Destino'],
                    'remision' => $row['Remision'],
                    'litros' => $row['litros'],
                    'ya_asignada' => $row['YaAsignada']
                ];
            }

            odbc_close($conn);
            json_output(['success' => true, 'data' => $facturas]);

        } catch (Exception $e) {
            error_log("Error en buscar_facturas_petrotal: " . $e->getMessage());
            json_output(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    // ========== NUEVO ENDPOINT: Guardar asignación completa (directo o con Petrotal) ==========
    public function guardar_asignacion_completa() {
        header('Content-Type: application/json');
        
        // Obtener datos del POST
        $nrotrn = isset($_POST['nrotrn']) ? intval($_POST['nrotrn']) : 0;
        $codgas = isset($_POST['codgas']) ? intval($_POST['codgas']) : 0;
        $tipoOperacion = isset($_POST['tipo_operacion']) ? intval($_POST['tipo_operacion']) : 1;
        $observaciones = isset($_POST['observaciones']) ? $_POST['observaciones'] : '';
        $usuario = isset($_SESSION['usuario']) ? $_SESSION['usuario'] : 'Sistema';
        
        // Factura Proveedor (obligatoria)
        $facturaProveedorId = isset($_POST['factura_proveedor_id']) ? intval($_POST['factura_proveedor_id']) : 0;
        $uuidProveedor = isset($_POST['uuid_proveedor']) ? $_POST['uuid_proveedor'] : '';
        $folioProveedor = isset($_POST['folio_proveedor']) ? $_POST['folio_proveedor'] : '';
        $montoProveedor = isset($_POST['monto_proveedor']) ? floatval($_POST['monto_proveedor']) : 0;
        $litrosProveedor = isset($_POST['litros_proveedor']) ? floatval($_POST['litros_proveedor']) : 0;
        $precioProveedor = isset($_POST['precio_proveedor']) ? floatval($_POST['precio_proveedor']) : 0;
        
        // Factura Petrotal (opcional, solo si tipoOperacion = 2)
        $facturaPetrotalId = isset($_POST['factura_petrotal_id']) ? intval($_POST['factura_petrotal_id']) : null;
        $uuidPetrotal = isset($_POST['uuid_petrotal']) ? $_POST['uuid_petrotal'] : null;
        $folioPetrotal = isset($_POST['folio_petrotal']) ? $_POST['folio_petrotal'] : null;
        $montoPetrotal = isset($_POST['monto_petrotal']) ? floatval($_POST['monto_petrotal']) : null;
        $litrosPetrotal = isset($_POST['litros_petrotal']) ? floatval($_POST['litros_petrotal']) : null;
        $precioPetrotal = isset($_POST['precio_petrotal']) ? floatval($_POST['precio_petrotal']) : null;
        
        // Validaciones
        if ($nrotrn == 0 || $codgas == 0 || $facturaProveedorId == 0) {
            json_output(['success' => false, 'message' => 'Datos incompletos (nrotrn, codgas o factura proveedor)']);
            return;
        }
        
        // Si es operación con Petrotal, validar que tenga la factura de Petrotal
        if ($tipoOperacion == 2 && ($facturaPetrotalId == 0 || empty($facturaPetrotalId))) {
            json_output(['success' => false, 'message' => 'Para operación con Petrotal debe seleccionar la factura de Petrotal']);
            return;
        }
        
        try {
            $conn_str = 'DRIVER={ODBC Driver 17 for SQL Server};SERVER=192.168.0.6;DATABASE=TG;UID=cguser;PWD=sahei1712';
            $conn = odbc_connect($conn_str, '', '');
            
            if (!$conn) {
                throw new Exception("Error al conectar con la base de datos");
            }

            // Verificar si ya existe una asignación
            $queryCheck = "SELECT Id FROM FacturasMovimientosTanques WHERE nrotrn = ? AND codgas = ?";
            $stmtCheck = odbc_prepare($conn, $queryCheck);
            
            if (!odbc_execute($stmtCheck, [$nrotrn, $codgas])) {
                throw new Exception("Error al verificar asignación existente");
            }
            
            $existeAsignacion = odbc_fetch_array($stmtCheck);
            
            if ($existeAsignacion) {
                // Actualizar asignación existente
                $queryUpdate = "
                    UPDATE FacturasMovimientosTanques 
                    SET 
                        TipoOperacion = ?,
                        FacturaProveedorId = ?,
                        UUIDProveedor = ?,
                        FolioProveedor = ?,
                        MontoProveedor = ?,
                        LitrosProveedor = ?,
                        PrecioProveedor = ?,
                        FacturaPetrotalId = ?,
                        UUIDPetrotal = ?,
                        FolioPetrotal = ?,
                        MontoPetrotal = ?,
                        LitrosPetrotal = ?,
                        PrecioPetrotal = ?,
                        FechaAsignacion = GETDATE(),
                        UsuarioAsignacion = ?,
                        Observaciones = ?,
                        Activo = 1
                    WHERE nrotrn = ? AND codgas = ?
                ";
                
                $stmtUpdate = odbc_prepare($conn, $queryUpdate);
                
                $params = [
                    $tipoOperacion,
                    $facturaProveedorId,
                    $uuidProveedor,
                    $folioProveedor,
                    $montoProveedor,
                    $litrosProveedor,
                    $precioProveedor,
                    $facturaPetrotalId,
                    $uuidPetrotal,
                    $folioPetrotal,
                    $montoPetrotal,
                    $litrosPetrotal,
                    $precioPetrotal,
                    $usuario,
                    $observaciones,
                    $nrotrn,
                    $codgas
                ];
                
                if (!odbc_execute($stmtUpdate, $params)) {
                    throw new Exception("Error al actualizar asignación: " . odbc_errormsg($conn));
                }
                
                $message = "Asignación actualizada correctamente";
                
            } else {
                // Insertar nueva asignación
                $queryInsert = "
                    INSERT INTO FacturasMovimientosTanques 
                    (nrotrn, codgas, TipoOperacion, 
                    FacturaProveedorId, UUIDProveedor, FolioProveedor, MontoProveedor, LitrosProveedor, PrecioProveedor,
                    FacturaPetrotalId, UUIDPetrotal, FolioPetrotal, MontoPetrotal, LitrosPetrotal, PrecioPetrotal,
                    UsuarioAsignacion, Observaciones)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ";
                
                $stmtInsert = odbc_prepare($conn, $queryInsert);
                
                $params = [
                    $nrotrn,
                    $codgas,
                    $tipoOperacion,
                    $facturaProveedorId,
                    $uuidProveedor,
                    $folioProveedor,
                    $montoProveedor,
                    $litrosProveedor,
                    $precioProveedor,
                    $facturaPetrotalId,
                    $uuidPetrotal,
                    $folioPetrotal,
                    $montoPetrotal,
                    $litrosPetrotal,
                    $precioPetrotal,
                    $usuario,
                    $observaciones
                ];
                
                if (!odbc_execute($stmtInsert, $params)) {
                    throw new Exception("Error al insertar asignación: " . odbc_errormsg($conn));
                }
                
                $message = "Asignación guardada correctamente";
            }

            odbc_close($conn);
            json_output(['success' => true, 'message' => $message]);

        } catch (Exception $e) {
            error_log("Error en guardar_asignacion_completa: " . $e->getMessage());
            json_output(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    // ========== ACTUALIZAR ENDPOINT: Eliminar asignación (ya existente pero actualizado) ==========
    public function eliminar_asignacion_factura() {
        header('Content-Type: application/json');
        
        $nrotrn = isset($_POST['nrotrn']) ? intval($_POST['nrotrn']) : 0;
        $codgas = isset($_POST['codgas']) ? intval($_POST['codgas']) : 0;
        
        if ($nrotrn == 0 || $codgas == 0) {
            json_output(['success' => false, 'message' => 'Parámetros inválidos']);
            return;
        }
        
        try {
            $conn_str = 'DRIVER={ODBC Driver 17 for SQL Server};SERVER=192.168.0.6;DATABASE=TG;UID=cguser;PWD=sahei1712';
            $conn = odbc_connect($conn_str, '', '');
            
            if (!$conn) {
                throw new Exception("Error al conectar con la base de datos");
            }

            // Marcar como inactivo (soft delete)
            $query = "
                UPDATE FacturasMovimientosTanques 
                SET Activo = 0
                WHERE nrotrn = ? AND codgas = ?
            ";
            
            $stmt = odbc_prepare($conn, $query);
            
            if (!odbc_execute($stmt, [$nrotrn, $codgas])) {
                throw new Exception("Error al eliminar: " . odbc_errormsg($conn));
            }

            odbc_close($conn);
            json_output(['success' => true, 'message' => 'Asignación eliminada correctamente']);

        } catch (Exception $e) {
            error_log("Error en eliminar_asignacion_factura: " . $e->getMessage());
            json_output(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    // ========== ENDPOINT ADICIONAL: Obtener detalle de asignación ==========
    public function obtener_detalle_asignacion() {
        header('Content-Type: application/json');
        
        $nrotrn = isset($_GET['nrotrn']) ? intval($_GET['nrotrn']) : 0;
        $codgas = isset($_GET['codgas']) ? intval($_GET['codgas']) : 0;
        
        if ($nrotrn == 0 || $codgas == 0) {
            json_output(['success' => false, 'message' => 'Parámetros inválidos']);
            return;
        }
        
        try {
            $conn_str = 'DRIVER={ODBC Driver 17 for SQL Server};SERVER=192.168.0.6;DATABASE=TG;UID=cguser;PWD=sahei1712';
            $conn = odbc_connect($conn_str, '', '');
            
            if (!$conn) {
                throw new Exception("Error al conectar con la base de datos");
            }

            $query = "
                SELECT 
                    fmt.*,
                    frProv.Folio as FolioProveedorCompleto,
                    frProv.EmisorNombre as EmisorProveedor,
                    frProv.Total as TotalProveedor,
                    frPetro.Folio as FolioPetrotalCompleto,
                    frPetro.Total as TotalPetrotal
                FROM FacturasMovimientosTanques fmt
                LEFT JOIN FacturasRecibidas frProv ON fmt.FacturaProveedorId = frProv.Id
                LEFT JOIN FacturasRecibidas frPetro ON fmt.FacturaPetrotalId = frPetro.Id
                WHERE fmt.nrotrn = ? AND fmt.codgas = ? AND fmt.Activo = 1
            ";
            
            $stmt = odbc_prepare($conn, $query);
            
            if (!odbc_execute($stmt, [$nrotrn, $codgas])) {
                throw new Exception("Error al consultar detalle");
            }
            
            $detalle = odbc_fetch_array($stmt);
            
            if (!$detalle) {
                json_output(['success' => false, 'message' => 'No se encontró asignación']);
                return;
            }

            odbc_close($conn);
            json_output(['success' => true, 'data' => $detalle]);

        } catch (Exception $e) {
            error_log("Error en obtener_detalle_asignacion: " . $e->getMessage());
            json_output(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    // ========== ENDPOINT ADICIONAL: Reporte de márgenes Petrotal ==========
    public function reporte_margenes_petrotal() {
        header('Content-Type: application/json');
        
        $fechaInicio = isset($_POST['fecha_inicio']) ? $_POST['fecha_inicio'] : '';
        $fechaFin = isset($_POST['fecha_fin']) ? $_POST['fecha_fin'] : '';
        
        try {
            $conn_str = 'DRIVER={ODBC Driver 17 for SQL Server};SERVER=192.168.0.6;DATABASE=TG;UID=cguser;PWD=sahei1712';
            $conn = odbc_connect($conn_str, '', '');
            
            if (!$conn) {
                throw new Exception("Error al conectar con la base de datos");
            }

            $whereSQL = "";
            if (!empty($fechaInicio) && !empty($fechaFin)) {
                $whereSQL = "AND frProv.Fecha BETWEEN '$fechaInicio' AND '$fechaFin'";
            }

            $query = "
                SELECT 
                    fmt.nrotrn,
                    fmt.codgas,
                    frProv.Folio as FolioProveedor,
                    frProv.EmisorNombre as ProveedorOriginal,
                    frProv.Fecha as FechaCompra,
                    fmt.LitrosProveedor,
                    fmt.PrecioProveedor,
                    fmt.MontoProveedor,
                    frPetro.Folio as FolioPetrotal,
                    frPetro.Fecha as FechaVenta,
                    fmt.LitrosPetrotal,
                    fmt.PrecioPetrotal,
                    fmt.MontoPetrotal,
                    -- Cálculos de margen
                    (fmt.PrecioPetrotal - fmt.PrecioProveedor) as DiferenciaPrecio,
                    ((fmt.PrecioPetrotal - fmt.PrecioProveedor) / fmt.PrecioProveedor * 100) as MargenPorcentual,
                    (fmt.MontoPetrotal - fmt.MontoProveedor) as GananciaNeta
                FROM FacturasMovimientosTanques fmt
                INNER JOIN FacturasRecibidas frProv ON fmt.FacturaProveedorId = frProv.Id
                INNER JOIN FacturasRecibidas frPetro ON fmt.FacturaPetrotalId = frPetro.Id
                WHERE fmt.TipoOperacion = 2 
                    AND fmt.Activo = 1
                    $whereSQL
                ORDER BY frProv.Fecha DESC
            ";
            
            $result = odbc_exec($conn, $query);
            
            if (!$result) {
                throw new Exception("Error en la consulta: " . odbc_errormsg($conn));
            }

            $reportes = [];
            while ($row = odbc_fetch_array($result)) {
                $reportes[] = $row;
            }

            odbc_close($conn);
            json_output(['success' => true, 'data' => $reportes]);

        } catch (Exception $e) {
            error_log("Error en reporte_margenes_petrotal: " . $e->getMessage());
            json_output(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    // public function compras_facturas_table() {
    //     ini_set('max_execution_time', 5000);
    //     ini_set('memory_limit', '1024M');
    //     set_time_limit(0);
    //     header('Content-Type: application/json');
        
    //     $postData = [
    //         'from' => isset($_POST['fromDate']) ? $_POST['fromDate'] : null,
    //         'until' => isset($_POST['untilDate']) ? $_POST['untilDate'] : null,
    //         'codgas' => isset($_POST['codgas']) ? $_POST['codgas'] : '0',
    //         'proveedor' => isset($_POST['proveedor']) ? $_POST['proveedor'] : '0',
    //         'company' => isset($_POST['company']) ? $_POST['company'] : '0'
    //     ];

    //     if (!$postData['from'] || !$postData['until']) {
    //         json_output(['error' => true, 'message' => 'Fechas requeridas', 'data' => []]);
    //         return;
    //     }
    //     try {
    //         $ch = curl_init('http://192.168.0.109:82/api/compras_facturas_base/');
    //         curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    //         curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    //         curl_setopt($ch, CURLOPT_POST, true);
    //         curl_setopt($ch, CURLOPT_TIMEOUT, 600);
    //         curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);

    //         $response = curl_exec($ch);
    //         $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
    //         if (curl_errno($ch)) {
    //             throw new Exception('Error de cURL: ' . curl_error($ch));
    //         }
            
    //         curl_close($ch);

    //         if ($httpCode !== 200) {
    //             throw new Exception("Error HTTP: $httpCode");
    //         }

    //         $apiData = json_decode($response, true);
    //         // echo '<pre>';
    //         // var_dump($apiData);
    //         // var_dump($response);

    //         if (json_last_error() !== JSON_ERROR_NONE) {
    //             throw new Exception('Error al decodificar JSON: ' . json_last_error_msg());
    //         }

    //         // Los datos ya vienen procesados desde la API
    //         json_output(['data' => $apiData]);

    //     } catch (Exception $e) {
    //         error_log("Error en compras_facturas_table: " . $e->getMessage());
    //         json_output(['error' => true, 'message' => $e->getMessage(), 'data' => []]);
    //     }
    // }

    public function compras_facturas_table() {
        if ($rows = $this->facturasRecibidasModel->compras_facturas_table($_POST['fromDate'], $_POST['untilDate'], $_POST['codgas'], $_POST['proveedor'], $_POST['company'])) {
            $data = [];
            foreach ($rows as $row) {

                $litros = is_numeric($row['LitrosDocumentoSoporte'] ?? null) ? floatval($row['LitrosDocumentoSoporte']) : 0.0;
                $monto = is_numeric($row['MontoFactura'] ?? null) ? floatval($row['MontoFactura']) : 0.0;
                $precioPorLitro = ($litros > 0) ? $monto / $litros : 0.0;
                $producto = $this->normalizarProducto((string) ($row['Producto'] ?? ''));
                $proveedor = $this->normalizarProveedor((string) ($row['ProveedorOriginal'] ?? ''));
                $numeroEstacion = $row['numero_estacion'] ?? str_pad((string) ($row['CodigoEstacion'] ?? '0'), 2, '0', STR_PAD_LEFT);
                $saldoFactura = is_numeric($row['SaldoFactura'] ?? null) ? floatval($row['SaldoFactura']) : 0.0;

                // Número de estación
                $numeroEstacion = $row['numero_estacion'] ?? '00';
                if ($numeroEstacion == '00' && !empty($row['CodigoEstacion'])) {
                    $numeroEstacion = str_pad($row['CodigoEstacion'], 2, '0', STR_PAD_LEFT);
                }
            
                // Nombre de estación
                $nombreEstacion = $row['estacion_control_gas'] ?? '';
                $data[] = array(
                    'FacturaId'                      => $row['FacturaId'],
                    'FechaRecepcion'                 => $row['FechaRecepcion'],
                    'NumeroEstacion'                 => $numeroEstacion,
                    'NombreEstacion'                 => $nombreEstacion,
                    'Empresa'                        => $row['Empresa'],
                    'ProveedorOriginal'              => $row['ProveedorOriginal'],
                    'ProveedorNormalizado'           => $proveedor,
                    'ProductoNormalizado'            => $producto,
                    'NumeroFacturaProveedorOriginal' => $row['NumeroFacturaProveedorOriginal'],
                    'LitrosDocumentoSoporte'         => round($litros, 4),
                    'MontoFactura'                   => round($monto, 2),
                    'SaldoFactura'                   => round($saldoFactura, 2),
                    'PrecioPorLitro'                 => round($precioPorLitro, 6),
                    'PrecioCotizado'                 => 'PENDIENTE',
                    'Diferencia'                     => 0,
                    'PrecioFacturaCotizadoPetrotal'  => 0,
                    'NumeroFacturaPetrotal'          => $row['NumeroFacturaPetrotal'] ?? '',
                    'EstadoAsignacion'               => $row['EstadoAsignacion'],
                    'UUID'                           => $row['UUID'],
                    'RutaArchivo'                    => $row['RutaArchivo'],
                    'NombreArchivo'                  => $row['NombreArchivo'],
                    'TipoOperacion'                  => $row['TipoOperacion'],
                    'NumeroTransaccion'              => $row['NumeroTransaccion'],
                    'CodigoEstacion'                 => $row['CodigoEstacion'],

                );
            }
            echo json_encode(['data' => $data]);
        } else {
            echo json_encode(['data' => []]);
        }
    }
    private function normalizarProducto($producto) {
        if (empty($producto)) return 'N/A';
        
        $prod = strtoupper($producto);
        
        if (preg_match('/\b(REGULAR|MAGNA|87)\b/i', $prod)) {
            return 'Regular';
        } elseif (preg_match('/\b(PREMIUM|SUPER|91|93)\b/i', $prod)) {
            return 'Premium';
        } elseif (preg_match('/\b(DIESEL)\b/i', $prod)) {
            return 'Diesel';
        }
        
        return substr($producto, 0, 50);
    }

    private function normalizarProveedor($proveedor) {
        if (empty($proveedor)) return 'N/A';
        
        $prov = strtoupper($proveedor);
        
        if (strpos($prov, 'TESORO') !== false) return 'TESORO';
        if (strpos($prov, 'MGC') !== false) return 'MGC';
        if (strpos($prov, 'LOBO') !== false) return 'LOBO';
        if (strpos($prov, 'PETROTAL') !== false) return 'PETROTAL';
        if (strpos($prov, 'ESSAFUEL') !== false || strpos($prov, 'ESSA') !== false) return 'ESSAFUEL';
        if (strpos($prov, 'PREMIER') !== false) return 'PREMIERGAS';
        if (strpos($prov, 'ENEREY') !== false) return 'ENEREY';
        if (strpos($prov, 'AEMSA') !== false || strpos($prov, 'ALTOS') !== false) return 'AEMSA';
        
        return substr($proveedor, 0, 30);
    }
    // public function ver_factura_pdf() {
    //     // Cambiar a POST
    //     $facturaId = $_POST['id'] ?? null;
        
    //     if (!$facturaId) {
    //         header('HTTP/1.1 400 Bad Request');
    //         header('Content-Type: application/json');
    //         echo json_encode(['error' => 'ID de factura requerido']);
    //         return;
    //     }
        
    //     // Obtener ruta del archivo
    //     $query = "SELECT RutaArchivo, NombreArchivo, Folio FROM TG.dbo.FacturasRecibidas WHERE Id = ?";
    //     $result = $this->sql->select($query, [$facturaId]);
        
    //     if (empty($result)) {
    //         header('HTTP/1.1 404 Not Found');
    //         header('Content-Type: application/json');
    //         echo json_encode(['error' => 'Factura no encontrada']);
    //         return;
    //     }
        
    //     $rutaArchivo = $result[0]['RutaArchivo'];
    //     $nombreArchivo = $result[0]['NombreArchivo'] ?? 'factura.pdf';
        
    //     // Verificar que el archivo existe
    //     if (!file_exists($rutaArchivo)) {
    //         header('HTTP/1.1 404 Not Found');
    //         header('Content-Type: application/json');
    //         echo json_encode(['error' => 'Archivo PDF no encontrado en el servidor', 'ruta' => $rutaArchivo]);
    //         return;
    //     }
        
    //     // Leer el archivo y convertirlo a base64
    //     $pdfContent = file_get_contents($rutaArchivo);
    //     $pdfBase64 = base64_encode($pdfContent);
        
    //     // Devolver JSON con el PDF en base64
    //     header('Content-Type: application/json');
    //     echo json_encode([
    //         'success' => true,
    //         'pdf' => $pdfBase64,
    //         'nombre' => $nombreArchivo,
    //         'folio' => $result[0]['Folio'],
    //         'size' => filesize($rutaArchivo)
    //     ]);
    // }

//     public function descargar_factura_pdf() {
//         $facturaId = $_POST['id'] ?? null;
        
//         if (!$facturaId) {
//             header('HTTP/1.1 400 Bad Request');
//             header('Content-Type: application/json');
//             echo json_encode(['error' => 'ID de factura requerido']);
//             return;
//         }
        
//         // Obtener ruta del archivo
//         $query = "SELECT RutaArchivo, NombreArchivo, Folio FROM TG.dbo.FacturasRecibidas WHERE Id = ?";
//         $result = $this->sql->select($query, [$facturaId]);
        
//         if (empty($result)) {
//             header('HTTP/1.1 404 Not Found');
//             header('Content-Type: application/json');
//             echo json_encode(['error' => 'Factura no encontrada']);
//             return;
//         }
        
//         $rutaArchivo = $result[0]['RutaArchivo'];
//         $folio = $result[0]['Folio'] ?? 'sin_folio';
        
//         // Verificar que el archivo existe
//         if (!file_exists($rutaArchivo)) {
//             header('HTTP/1.1 404 Not Found');
//             header('Content-Type: application/json');
//             echo json_encode(['error' => 'Archivo PDF no encontrado']);
//             return;
//         }
        
//         // Nombre de archivo amigable
//         $nombreDescarga = 'Factura_' . $folio . '_' . date('Ymd') . '.pdf';
        
//         // Headers para forzar descarga
//         header('Content-Type: application/pdf');
//         header('Content-Disposition: attachment; filename="' . $nombreDescarga . '"');
//         header('Content-Length: ' . filesize($rutaArchivo));
//         header('Cache-Control: private, max-age=0, must-revalidate');
//         header('Pragma: public');
        
//         readfile($rutaArchivo);
//         exit;
//     }
// public function buscar_movimiento_por_nrotrn() {
//     $nrotrn = $_POST['nrotrn'] ?? null;
//     $codgas = $_POST['codgas'] ?? null;
    
//     if (!$nrotrn || !$codgas) {
//         json_output(['success' => false, 'message' => 'Parámetros incompletos']);
//         return;
//     }
    
//     // Buscar en sg12 (o donde tengas los movimientos)
//     $query = "
//         SELECT 
//             nrotrn,
//             codgas,
//             fecha,
//             producto,
//             litros,
//             -- otros campos necesarios
//         FROM sg12.dbo.MovimientosTanques 
//         WHERE nrotrn = ? AND codgas = ?
//     ";
    
//     $result = $this->sql->select($query, [$nrotrn, $codgas]);
    
//     if (!empty($result)) {
//         json_output(['success' => true, 'data' => $result[0]]);
//     } else {
//         json_output(['success' => false, 'message' => 'Movimiento no encontrado']);
//     }
// }


    public function ModalinvoicePdf(){
        $facturaId = $_POST['FacturaId'] ?? null;
        if (!$facturaId) {
            echo '<div class="modal-body">Factura no especificada.</div>';
            return;
        }

        $factura = $this->facturasRecibidasModel->obtenerPorId($facturaId);
        if (!$factura) {
            echo '<div class="modal-body">Factura no encontrada.</div>';
            return;
        }

        // Renderiza un partial twig que contiene el iframe
        echo $this->twig->render($this->route . 'modals/invoice_pdf.html', compact('factura'));
    }
    public function invoiceFile(){
        // Acepta GET o POST según tu preferencia
        $facturaId = $_GET['FacturaId'] ?? $_POST['FacturaId'] ?? null;
        if (!$facturaId) {
            header("HTTP/1.1 400 Bad Request");
            echo "FacturaId requerido";
            exit;
        }

        $factura = $this->facturasRecibidasModel->obtenerPorId($facturaId);
        if (!$factura) {
            header("HTTP/1.1 404 Not Found");
            echo "Factura no encontrada";
            exit;
        }

        $ruta = $factura['RutaArchivo']; // ruta almacenada en DB

        // Normaliza separadores (Windows)
        $ruta = str_replace(['/', '\\\\'], DIRECTORY_SEPARATOR, $ruta);
        // opcional: si la DB almacena con barras invertidas dobles, limpiarlas:
        $ruta = str_replace('\\\\', DIRECTORY_SEPARATOR, $ruta);

        // Seguridad: restringir a un directorio base permitido
        $baseAllowed = realpath('C:\\Software\\TareasProgramadas\\Facturas_proveedores'); // ajusta si hace falta
        $real = realpath($ruta);

        if ($real === false || strpos($real, $baseAllowed) !== 0) {
            header("HTTP/1.1 403 Forbidden");
            echo "Acceso al archivo denegado.";
            exit;
        }

        if (!file_exists($real) || !is_readable($real)) {
            header("HTTP/1.1 404 Not Found");
            echo "Archivo no encontrado o no legible.";
            exit;
        }

        // Enviar headers para visualizar inline en el navegador
        header('Content-Type: application/pdf');
        // inline para mostrar en el navegador; attachment para forzar descarga
        header('Content-Disposition: inline; filename="' . basename($real) . '"');
        header('Content-Length: ' . filesize($real));
        // Evitar que PHP añada buffering adicional
        readfile($real);
        exit;
    }





}