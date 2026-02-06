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
use PhpOffice\PhpSpreadsheet\Collection\CellsFactory;


class Supply
{
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
    public PaymentRequestsModel $PaymentRequestsModel;
    public PaymentRequestInvoicesModel $paymentRequestInvoicesModel;
    public ProveedoresModel $proveedores;
    public FacturasMovimientosTanquesModel $facturasMovimientosTanquesModel;
    public PaymentRequestAuthorizationsModel  $paymentRequestAuthorizationsModel;
    public PaymentTransactionsModel $paymentTransactionsModel;
    public CuentasBancariasModel $CuentasBancariasModel;
    public UsuariosModel $UsuariosModel;
    public InvoiceCreditDebitNotesModel $InvoiceCreditDebitNotesModel;
    /**
     * @param $twig
     */
    public function __construct($twig)
    {
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
        $this->PaymentRequestsModel                               = new PaymentRequestsModel();
        $this->paymentRequestInvoicesModel                        = new PaymentRequestInvoicesModel();
        $this->proveedores                                       = new ProveedoresModel();
        $this->facturasRecibidasModel                            = new FacturasRecibidasModel();
        $this->facturasMovimientosTanquesModel                   = new FacturasMovimientosTanquesModel();
        $this->paymentRequestAuthorizationsModel                = new PaymentRequestAuthorizationsModel();
        $this->CuentasBancariasModel                            = new CuentasBancariasModel();
        $this->paymentTransactionsModel                        = new PaymentTransactionsModel();
        $this->UsuariosModel                                    = new UsuariosModel();
        $this->InvoiceCreditDebitNotesModel                     = new InvoiceCreditDebitNotesModel();
    }

    /**
     * @return void
     * @throws Exception
     */
    function inventory(): void
    {
        $stations = $this->gasolinerasModel->get_active_stations();
        if (preg_match('/GET/i', $_SERVER['REQUEST_METHOD'])) {
            echo $this->twig->render($this->route . 'inventory.html', compact('stations'));
        } else {
            $station_id = $_POST['station_id'] ?? 0;
            echo $this->twig->render($this->route . 'inventory.html', compact('stations', 'station_id'));
        }
    }

    function inventory_table($station_id): void
    {
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
                        <span class=\"me-2 mb-1 text-muted\">" . number_format($porcent_data, 2, '.', ',') . "%</span>
                        <div class=\"progress progress-sm bg-" . ($porcent_data < 10 ? 'danger' : ($porcent_data < 30 ? 'warning' : 'success')) . "-light w-100\">
                            <div class=\"progress-bar bg-" . ($porcent_data < 10 ? 'danger' : ($porcent_data < 30 ? 'warning' : 'success')) . "\" role=\"progressbar\" style=\"width: " . $porcent_data . "%;\"></div>
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

    public function inventory_mov(): void
    {
        //        Verificamos si date y station_id estan seteados
        $from = $_GET['from'] ?? date('Y-m-d');
        $station_id = $_GET['station_id'] ?? false;
        $stations = $this->gasolinerasModel->get_active_stations();
        echo $this->twig->render($this->route . 'inventory_mov.html', compact('from', 'station_id', 'stations'));
    }

    function inventory_mov_table($from, $station_id): void
    {
        $data = [];
        if ($movements = $this->tanquesModel->sp_obtener_inventarios_por_movimientos_tanque($from, $station_id)) {
            foreach ($movements as $movement) {
                $data[] = [
                    'ESTACION'   => $movement['abr'],
                    'TURNO'      => $movement['Turno'],
                    'PRODUCTO'   => $movement['Tanque'],
                    'CAP'        => $movement['CapacidadOpe'],
                    'VOLUMEN'    => $movement['current_volume'],
                    'PORCENTAJE' => ($movement['current_volume'] / $movement['CapacidadOpe']) * 100,
                ];
            }
        }
        json_output(array("data" => $data));
    }

    function fuel_prices(): void
    {
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
                $mensajeFinal .= '<li class="list-group-item d-flex justify-content-between align-items-start p-1"><div class="ms-2 me-auto" style="font-size: x-small"><b>' . $station . ' (' . $products[0]['Hora'] . ')</b> | ';
                foreach ($products as $product) {
                    $mensajeFinal .= ' ' . $product['Producto'] . ' a $' . number_format($product['Precio'], 2) . '';
                }
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
                $mensajeFinal2 .= '<li class="list-group-item d-flex justify-content-between align-items-start p-1"><div class="ms-2 me-auto" style="font-size: x-small"><b>' . $station . ' (' . $products[0]['Hora'] . ')</b> | ';
                foreach ($products as $product) {
                    $mensajeFinal2 .= ' ' . $product['Producto'] . ' a $' . number_format($product['Precio'], 2) . '';
                }
                $mensajeFinal2 .= '</div></li>';
            }
            $mensajeFinal2 .= '</ul>';
            $mensajeFinal2 .= "</div></div>";
        }

        echo $this->twig->render($this->route . 'fuel_prices.html', compact('stations', 'mensajeFinal', 'mensajeFinal2', 'prices'));
    }

    function datatable_product_prices()
    {
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
                        $' . number_format($item[0]['pre_actual_codprd_179'], 2, '.', ',') . '
                    </a>
        
                    <div class="dropdown-menu" aria-labelledby="dropdownMenuLink">
                        <a class="dropdown-item" href="javascript:void(0);" onclick="update_price(179, ' . $item[0]['codgas'] . ', ' . $item[0]['fch_actual_codprd_179'] . ', ' . $item[0]['hra_actual_codprd_179'] . ', ' . number_format($item[0]['pre_actual_codprd_179'], 2, '.', ',') . ')"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-edit-2 align-middle me-2"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"></path></svg> Editar</a>
                        <a class="dropdown-item" href="javascript:void(0);" onclick="delete_price(179, ' . $item[0]['codgas'] . ', ' . $item[0]['fch_actual_codprd_179'] . ', ' . $item[0]['hra_actual_codprd_179'] . ')"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-trash-2 align-middle me-2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg> Eliminar</a>
                    </div>
                </div>
                ';

                $options_super = '
                <div class="dropdown">
                    <a class="dropdown-toggle text-light" href="#" role="button" id="dropdownMenuLink" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                        $' . number_format($item[0]['pre_actual_codprd_180'], 2, '.', ',') . '
                    </a>
        
                    <div class="dropdown-menu" aria-labelledby="dropdownMenuLink">
                        <a class="dropdown-item" href="javascript:void(0);" onclick="update_price(180, ' . $item[0]['codgas'] . ', ' . $item[0]['fch_actual_codprd_180'] . ', ' . $item[0]['hra_actual_codprd_180'] . ', ' . number_format($item[0]['pre_actual_codprd_180'], 2, '.', ',') . ')"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-edit-2 align-middle me-2"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"></path></svg> Editar</a>
                        <a class="dropdown-item" href="javascript:void(0);" onclick="delete_price(180, ' . $item[0]['codgas'] . ', ' . $item[0]['fch_actual_codprd_180'] . ', ' . $item[0]['hra_actual_codprd_180'] . ')"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-trash-2 align-middle me-2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg> Eliminar</a>
                    </div>
                </div>
                ';

                $options_diesel = '
                <div class="dropdown">
                    <a class="dropdown-toggle text-dark" href="#" role="button" id="dropdownMenuLink" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                        $' . number_format($item[0]['pre_actual_codprd_181'], 2, '.', ',') . '
                    </a>
        
                    <div class="dropdown-menu" aria-labelledby="dropdownMenuLink">
                        <a class="dropdown-item" href="javascript:void(0);" onclick="update_price(181, ' . $item[0]['codgas'] . ', ' . $item[0]['fch_actual_codprd_181'] . ', ' . $item[0]['hra_actual_codprd_181'] . ', ' . number_format($item[0]['pre_actual_codprd_181'], 2, '.', ',') . ')"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-edit-2 align-middle me-2"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"></path></svg> Editar</a>
                        <a class="dropdown-item" href="javascript:void(0);" onclick="delete_price(181, ' . $item[0]['codgas'] . ', ' . $item[0]['fch_actual_codprd_181'] . ', ' . $item[0]['hra_actual_codprd_181'] . ')"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-trash-2 align-middle me-2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg> Eliminar</a>
                    </div>
                </div>
                ';

                $data[] = array(
                    'CODEST'               => $item[0]['station'],
                    'ESTACION'             => $item[0]['station_name'] . '<p class="m-0 p-0 text-nowrap">' . $item[0]['permisoCre'] . '</p>',
                    'PRECIOANTERIORMAXIMA' => '<p class="m-0 p-0 text-center">$' . number_format($item[0]['pre_anterior_codprd_179'], 2, '.', ',') . '<p class="m-0 p-0 text-center">' . (intToDate($item[0]['fch_anterior_codprd_179'])) . '</p>',
                    'PRECIONUEVOMAXIMA'    => $options_maxima . '<p class="m-0 p-0 text-center" data-toggle="tooltip" title="Hora: ' . $item[0]['hra_actual_codprd_179'] . '">' . (intToDate($item[0]['fch_actual_codprd_179'])) . '</p>',
                    'DIFERENCIAMAXIMA'     => (is_null($item[0]['pre_actual_codprd_179']) ? 'N/A' : ('$' . number_format($item[0]['pre_actual_codprd_179'] - $item[0]['pre_anterior_codprd_179'], 2, '.', ','))),
                    'PRECIOANTERIORSUPER'  => (is_null($item[0]['pre_anterior_codprd_180']) ? 'N/A' : ('<p class="m-0 p-0 text-center">$' . number_format($item[0]['pre_anterior_codprd_180'], 2, '.', ',') . '</p> <p class="m-0 p-0 text-center">' . (intToDate($item[0]['fch_anterior_codprd_180'])) . '</p>')),
                    'PRECIONUEVOSUPER'     => (is_null($item[0]['pre_actual_codprd_180']) ? 'N/A' : ($options_super . '<p class="m-0 p-0 text-center" data-toggle="tooltip" title="Hora: ' . $item[0]['hra_actual_codprd_180'] . '">' . (intToDate($item[0]['fch_actual_codprd_180'])) . '</p>')),
                    'DIFERENCIASUPER'      => (is_null($item[0]['pre_actual_codprd_180']) ? 'N/A' : ('$' . number_format($item[0]['pre_actual_codprd_180'] - $item[0]['pre_anterior_codprd_180'], 2, '.', ','))),
                    'PRECIOANTERIORDIESEL' => (is_null($item[0]['pre_anterior_codprd_181']) ? 'N/A' : ('<p class="m-0 p-0 text-center">$' . number_format($item[0]['pre_anterior_codprd_181'], 2, '.', ',') . '</p> <p class="m-0 p-0 text-center">' . (intToDate($item[0]['fch_anterior_codprd_181'])) . '</p>')),
                    'PRECIONUEVODIESEL'    => (is_null($item[0]['pre_actual_codprd_181']) ? 'N/A' : ($options_diesel . '<p class="m-0 p-0 text-center" data-toggle="tooltip" title="Hora: ' . $item[0]['hra_actual_codprd_181'] . '">' . (intToDate($item[0]['fch_actual_codprd_181'])) . '</p>')),
                    'DIFERENCIADIESEL'     => (is_null($item[0]['pre_actual_codprd_181']) ? 'N/A' : ('$' . number_format($item[0]['pre_actual_codprd_181'] - $item[0]['pre_anterior_codprd_181'], 2, '.', ',')))
                );
            }
        }
        json_output(array("data" => $data));
    }

    // Función para construir el mensaje de una estación


    function delete_price($codprd, $codgas, $fch, $hra)
    {

        binnacle_register_prices($_SESSION['tg_user']['Id'], 'Eliminación', "Se eliminó el siguiente registro: codprd: {$codprd}, codgas: {$codgas}, fch: {$fch}, hra: {$hra}.", $_SERVER['REMOTE_ADDR'], 'supply.php', 'delete_price');
        if ($this->preciosModel->delete_price($codprd, $codgas, $fch, $hra)) {
            setFlashMessage('success', 'Precio eliminado correctamente');
        } else {
            setFlashMessage('error', 'No se pudo eliminar el precio');
        }
        header('Location: /supply/fuel_prices');
    }

    function send_prices()
    {
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
            if (in_array($codgas, [33, 34, 35, 36, 37, 38])) { // Travel, Picachos, Ventanas, San Rafael, Puertecito
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

    function get_ieps($codprd)
    {
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

    function update_price()
    {
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

    function get_binnacle(): void
    {
        $binnacle = $this->binnaclePricesModel->get_binnacle();
        echo $this->twig->render($this->route . 'binnacle.html', compact('binnacle'));
    }

    function changes(): void
    {
        echo $this->twig->render($this->route . 'changes.html');
    }

    function tgr01()
    {
        $stations = $this->gasolinerasModel->get_active_stations();
        isset($_GET['codgas']) ? $codgas = $_GET['codgas'] : $codgas = 7;
        isset($_GET['from']) ? $from = $_GET['from'] : $from = date('Y-m-d');
        isset($_GET['to']) ? $to = $_GET['to'] : $to = date('Y-m-d');
        isset($_GET['shift']) ? $shift = $_GET['shift'] : $shift = 0;
        isset($_GET['product']) ? $product = $_GET['product'] : $product = 0;

        $data = $this->gasolinerasModel->GetVentasLogistica(dateToInt($from), dateToInt($to), intval($codgas), intval($product));

        echo $this->twig->render($this->route . 'tgr01.html', compact('stations', 'from', 'to', 'codgas', 'shift', 'product', 'data'));
    }

    function creProducts()
    {
        $stations = $this->gasolinerasModel->get_active_station_TG();
        $products = $this->creProductsModel->getRows();
        echo $this->twig->render($this->route . 'creProducts.html', compact('stations', 'products'));
    }

    function datatable_creProducts()
    {
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

    function getSubProducts($productId)
    {
        $subProducts = $this->creSubProductosModel->getRowsByProduct($productId);
        json_output($subProducts);
    }

    function getSubProductsBrand($subProductId)
    {
        $subProductsBrand = $this->creSubProductosMarcaModel->getRowsBySubProduct($subProductId);
        json_output($subProductsBrand);
    }

    function addCreProductForm()
    {
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

    function bulkUpload2()
    {

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
                    $item['compras'] = $this->xsdEstacionServicioVolumenCompradoModel->getPurchaseByProduct2($item['xsdReportesVolumenesId'], $item['xsdEstacionServicioVolumenId'], $item['controlGasProductId']);
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

    function bulkUpload()
    {
        ini_set('memory_limit', '256M');
        ini_set('max_execution_time', 300);

        // Fechas
        $yesterday = (new DateTime('yesterday'))->format('Y-m-d');
        $tenDaysAgo = (new DateTime('-40 days'))->format('Y-m-d');

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

    function creSuppliers()
    {
        if (preg_match('/GET/i', $_SERVER['REQUEST_METHOD'])) {
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

    function creCarriers()
    {
        if (preg_match('/GET/i', $_SERVER['REQUEST_METHOD'])) {
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

    function updateForm()
    {
        if (preg_match('/POST/i', $_SERVER['REQUEST_METHOD'])) {
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

    function updateForm2()
    {
        if (preg_match('/POST/i', $_SERVER['REQUEST_METHOD'])) {
            $creProductId = $_POST['creProductId'];
            $creSubProductId = $_POST['creSubProductId'];
            $controlGasProductId = $_POST['controlGasProductId'];

            $cabecera = $this->xsdReportesVolumenesModel->get_cabecera($_POST['from']);
            $station = $this->xsdEstacionServicioVolumenModel->get_station($cabecera['id'], $_POST['codgas']);

            $fchInt = dateToInt($_POST['from']);
            if ($station_inventory = $this->xsdEstacionServicioVolumenVendidoInventariosModel->get_inventory_product($station['id'], $creProductId, $creSubProductId)) {
                $data = $this->xsdEstacionServicioVolumenVendidoInventariosModel->update_inventory_product2($station_inventory['id'], $_POST['InventarioInicial'], $_POST['InventarioFinal'], $_POST['codgas'], $controlGasProductId, $fchInt);
                json_output(['status' => 'success', 'message' => 'Datos actualizados correctamente', 'data' => $data]);
            }
        }
    }

    function frmCapturaProveedor()
    {
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

    function frmCapturaProveedor2()
    {
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


    function getPurchaseData($id)
    {
        if ($data = $this->xsdEstacionServicioVolumenCompradoModel->getRow($id)) {
            json_output(['status' => 'success', 'data' => $data]);
        } else {
            json_output(['status' => 'error', 'message' => 'No se encontraron datos']);
        }
    }

    function deletePurchase($id)
    {
        if ($this->xsdEstacionServicioVolumenCompradoModel->delete($id)) {
            // Vamos a enviar un mensaje flash
            setFlashMessage('success', 'Compra eliminada correctamente');
            redirect();
        }
    }

    function addCarrierModal()
    {
        if (preg_match('/POST/i', $_SERVER['REQUEST_METHOD'])) {
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

    function editCarrierModal()
    {
        if (preg_match('/POST/i', $_SERVER['REQUEST_METHOD'])) {
            if ($this->creCarriersModel->update($_POST['companyName'], $_POST['rfc'], $_POST['crePermissionCarrier'], $_POST['id'])) {
                json_output(['status' => 'success', 'message' => 'Transportista actualizado correctamente']);
            } else {
                json_output(['status' => 'error', 'message' => 'No se pudo actualizar el transportista']);
            }
        }
    }

    function addSupplierModal()
    {
        if (preg_match('/POST/i', $_SERVER['REQUEST_METHOD'])) {
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

    function editSupplierModal()
    {
        if (preg_match('/POST/i', $_SERVER['REQUEST_METHOD'])) {
            if ($this->creSuppliersModel->update($_POST['companyName'], $_POST['rfc'], $_POST['crePermissionSupplier'], $_POST['id'])) {
                json_output(['status' => 'success', 'message' => 'Proveedor actualizado correctamente']);
            } else {
                json_output(['status' => 'error', 'message' => 'No se pudo actualizar el proveedor']);
            }
        }
    }

    function frmCapturaCompra()
    {
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
            $html = $this->twig->render($this->route . 'modals/frmCapturaCompra.html', compact('codgas', 'creProductId', 'creSubProductId', 'creSubProductBrandId', 'rowid', 'controlGasProductId', 'suppliers', 'reception', 'carriers', 'from'));
            return json_output(['success' => true, 'html' => $html]);
        } else {
            return json_output(['success' => false, 'message' => 'No se encontró la compra']);
        }
    }

    function uploadXml()
    {
        $uploadDir = __DIR__ . '/../../_assets/uploads/creXMLs/';

        // Asegurarse que el directorio exista
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // Validar que llegue el archivo y las variables necesarias
        if (
            isset($_FILES['xmlFile']) && $_FILES['xmlFile']['error'] === UPLOAD_ERR_OK
            && isset($_POST['companyDenominacion']) && isset($_POST['from'])
        ) {

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

    function fuel_payments()
    {
        $stations = $this->gasolinerasModel->get_active_stations();
        echo $this->twig->render($this->route . 'fuel_payments.html', compact('stations'));
    }

    function fuel_reconciliation()
    {
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


    function prices_xml()
    {
        echo $this->twig->render($this->route . 'prices_xml.html');
    }

    function generar_xml_precios()
    {
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
    function responderJSON($success, $message, $data = [])
    {
        // Configuración de headers para respuestas JSON
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array_merge([
            'success' => $success,
            'message' => $message,
            'timestamp' => date('Y-m-d H:i:s')
        ], $data), JSON_UNESCAPED_UNICODE);
        exit;
    }

    function shops_fuel()
    {
        $stations = $this->gasolinerasModel->get_active_stations();

        echo $this->twig->render($this->route . 'shops_fuel.html', compact('stations'));
    }
    function providers()
    {

        echo $this->twig->render($this->route . 'providers.html');
    }

    function add_payment()
    {
        $all_stations = $this->gasolinerasModel->get_stations();

        // Filtrar estaciones para quitar la que tiene cod = 0
        $stations = array_filter($all_stations, function ($station) {
            return $station['cod'] != 0; // o !== '0' si cod es string
        });

        $companys = $this->gasolinerasModel->get_company();
        $proveedores = $this->proveedores->get_actives();
        echo $this->twig->render($this->route . 'add_payment.html', compact('stations', 'companys', 'proveedores'));
    }


    public function payment_control_table()
    {
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
        $hoy = date('Y-m-d');
        if (isset($apiData) && is_array($apiData)) {
            foreach ($apiData as $row) {
                if (empty($row['satuid'])) {
                    continue; // Skip rows with empty 'nro'
                }
                $estaVencida = !empty($row['fechaVto']) && $row['fechaVto'] < $hoy;
                $statusLabel = 'Pendiente';
                if ($row['payment_status'] == '0') {
                    $statusLabel = '<span class="badge bg-light text-dark">Enviado</span>';
                } elseif ($row['payment_status'] == '1') {
                    $statusLabel = '<span class="badge bg-secondary">Autorizado</span>';
                } elseif ($row['payment_status'] == '2') {
                    $statusLabel = '<span class="badge bg-success">Pagado</span>';
                } elseif ($estaVencida) {
                    $statusLabel = '<span class="badge bg-danger">Vencido</span>';
                }
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
                    'statusLabel'      => $statusLabel
                );
            }
        }
        json_output(array("data" => $data));
    }


    function uploadPdf()
    {
        $uploadDir = __DIR__ . '/../../_assets/uploads/creAcuses/';

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        if (
            isset($_FILES['pdfFile']) && $_FILES['pdfFile']['error'] === UPLOAD_ERR_OK
            && isset($_POST['companyDenominacion']) && isset($_POST['from'])
        ) {
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


    public function providers_table()
    {
        $data = [];
        if ($providers = $this->proveedores->get_rows()) {

            foreach ($providers as $row) {
                if ($row['total_facturado'] != 0) {

                    $data[] = array(
                        'id'               => $row['id'],
                        'id_control_gas'   => $row['id_control_gas'],
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

    function descargar_facturas()
    {
        echo $this->twig->render($this->route . 'descargar_facturas.html');
    }

    function procesar_uuids_facturas()
    {
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
                    } else if (!preg_match('/^[A-F0-9]{8}[-_][A-F0-9]{4}[-_][A-F0-9]{4}[-_][A-F0-9]{4}[-_][A-F0-9]{12}$/i', $uuid)) {
                        // UUID inválido - formato incorrecto
                        $uuidsInvalidos[] = [
                            'fila' => $row,
                            'uuid' => $uuid,
                            'estado' => 'formato_invalido',
                            'error' => 'UUID con formato inválido (no cumple patrón 8-4-4-4-12 caracteres)'
                        ];
                    } else {
                        // UUID válido
                        // IMPORTANTE: Aunque el Regex ahora acepte guiones bajos,
                        // tu base de datos seguramente usa guiones medios.
                        // Convertimos a formato estándar para buscarlo correctamente:
                        $uuidsValidos[] = str_replace('_', '-', $uuid);
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
    function descargar_factura($id)
    {
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
    function descargar_facturas_zip()
    {
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
    function download_zip($zipFileName)
    {
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
    function limpiar_zips_antiguos()
    {
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

    public function resumen_payment_table()
    {
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
    public function buscar_facturas_disponibles()
    {
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
    public function asignar_factura_movimiento()
    {
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
    public function relacionar_factura_movimiento()
    {
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
    public function buscar_facturas_proveedor()
    {
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
    public function buscar_facturas_petrotal()
    {
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
    public function guardar_asignacion_completa()
    {
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
    public function eliminar_asignacion_factura()
    {
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
    public function obtener_detalle_asignacion()
    {
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
    public function reporte_margenes_petrotal()
    {
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

    public function compras_facturas_table()
    {
        if ($rows = $this->facturasRecibidasModel->compras_facturas_table($_POST['fromDate'], $_POST['untilDate'], $_POST['codgas'], $_POST['proveedor'], $_POST['company'])) {
            $data = [];
            foreach ($rows as $row) {

                $litros = is_numeric($row['LitrosDocumentoSoporte'] ?? null) ? floatval($row['LitrosDocumentoSoporte']) : 0.0;
                $monto = is_numeric($row['MontoFactura'] ?? null) ? floatval($row['MontoFactura']) : 0.0;
                $precioPorLitro = ($litros > 0) ? $monto / $litros : 0.0;
                $proveedor = $this->normalizarProveedor((string) ($row['ProveedorOriginal'] ?? ''));
                $saldoFactura = is_numeric($row['SaldoFactura'] ?? null) ? floatval($row['SaldoFactura']) : 0.0;

                $numeroEstacion = ($row['numero_estacion'] != "" ? $row['numero_estacion'] : '<span class="badge bg-warning text-dark">' . $row['Destino'] . '</span>');
                $producto = ($row['producto_tanque_nombre'] != "" ? $row['producto_tanque_nombre'] : '<span class="badge bg-warning text-dark">' . $row['producto_tanque'] . '</span>');

                // Número de estación
                // $numeroEstacion = $row['numero_estacion'] ?? '00';
                // if ($numeroEstacion == '00' && !empty($row['CodigoEstacion'])) {
                //     $numeroEstacion = str_pad($row['CodigoEstacion'], 2, '0', STR_PAD_LEFT);
                // }
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
    private function normalizarProducto($producto)
    {
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

    private function normalizarProveedor($proveedor)
    {
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



    public function ModalinvoicePdf()
    {
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
    public function invoiceFile()
    {
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

    function payment_list()
    {
        $stations = $this->gasolinerasModel->get_active_stations();
        $companys = $this->gasolinerasModel->get_company();
        $proveedores = $this->proveedores->get_actives();

        echo $this->twig->render($this->route . 'payment_list.html', compact('stations', 'companys', 'proveedores'));
    }

    /**
     * Endpoint para obtener lista de pagos programados (DataTable)
     */
    function payment_list_table()
    {
        header('Content-Type: application/json');

        $status = isset($_POST['status']) ? $_POST['status'] : 'all';
        $type = isset($_POST['type']) ? $_POST['type'] : 'payment';

        try {
            // Obtener datos del modelo
            $results = $this->PaymentRequestsModel->get_requests_with_summary($type, $status);

            if (!$results) {
                json_output(['data' => []]);
                return;
            }

            $data = [];
            foreach ($results as $row) {
                $statusBadge = $this->getStatusBadge($row['status']);
                $authIndicator = $this->buildAuthorizationIndicator(
                    $row['auth_abastos'],
                    $row['auth_admin'],
                    $row['auth_tesoreria'],
                    $row['auth_abastos_user'],
                    $row['auth_admin_user'],
                    $row['auth_tesoreria_user'],
                    $row['auth_abastos_date'],
                    $row['auth_admin_date'],
                    $row['auth_tesoreria_date']
                );

                $actions = '
                    <div class="btn-group btn-group-sm">
                        <a href="/supply/payment_detail/' . $row['id'] . '" class="btn btn-info" title="Ver detalle"><i class="fas fa-eye"></i></a>
                        <button class="btn btn-danger" onclick="deletePayment(' . $row['id'] . ')" title="Eliminar"><i class="fas fa-trash"></i>
                        </button>
                    </div>
                ';

                $data[] = [
                    'id'             => $row['id'],
                    'request_date'   => date('d/m/Y H:i', strtotime($row['request_date'])),
                    'usuario'        => $row['usuario_nombre'],
                    'provider_name'  => $row['provider_name'],
                    'emp_name'       => $row['emp_name'],
                    'total_invoices' => $row['total_invoices'],
                    'total_amount'   => '$' . number_format($row['total_amount'], 2),
                    'total_paid'     => '$' . number_format($row['total_paid'], 2),
                    'authorized_invoices_count' => $row['authorized_invoices_count'],
                    'authorized_amount_total' => '$' . number_format($row['authorized_amount_total'], 2),
                    'status'         => $statusBadge,
                    'authorizations' => $authIndicator,
                    'comment'        => $row['comment'] ?: '-',
                    'actions'        => $actions
                ];
            }

            echo json_encode(['data' => $data]);
        } catch (Exception $e) {
            echo json_encode(['error' => true, 'message' => $e->getMessage()]);
        }
    }


    function loadAnticiposList()
    {
        header('Content-Type: application/json');

        $status = isset($_POST['status']) ? $_POST['status'] : 'all';
        $type = isset($_POST['type']) ? $_POST['type'] : 'anticipo'; // ✅ CAMBIO

        try {
            $results = $this->PaymentRequestsModel->get_anticipos_with_summary($type, $status);

            if (!$results) {
                json_output(['data' => []]);
                return;
            }

            $data = [];
            foreach ($results as $row) {
                $statusBadge = $this->getStatusBadge($row['status']);
                $authIndicator = $this->buildAuthorizationIndicator(
                    $row['auth_abastos'],
                    $row['auth_admin'],
                    $row['auth_tesoreria'],
                    $row['auth_abastos_user'],
                    $row['auth_admin_user'],
                    $row['auth_tesoreria_user'],
                    $row['auth_abastos_date'],
                    $row['auth_admin_date'],
                    $row['auth_tesoreria_date']
                );

                // ✅ CALCULAR SALDO
                $monto_total = floatval($row['monto_total']);
                // $monto_aplicado = floatval($row['monto_aplicado']);
                $monto_aplicado = '0';
                $saldo = $monto_total - $monto_aplicado;

                // ✅ ACCIONES ESPECÍFICAS PARA ANTICIPOS
                $actions = '
                    <div class="btn-group btn-group-sm">
                        <a href="/supply/anticipo_detail/' . $row['id'] . '" class="btn btn-info" title="Ver detalle">
                            <i class="fas fa-eye"></i>
                        </a>';

                $actions .= '
                        <button class="btn btn-danger" onclick="deletePayment(' . $row['id'] . ')" title="Eliminar">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                ';

                $data[] = [
                    'id'                => $row['id'],
                    'request_date'      => date('d/m/Y H:i', strtotime($row['request_date'])),
                    'usuario'           => $row['usuario_nombre'],
                    'provider_name'     => $row['provider_name'],
                    'emp_name'          => $row['emp_name'],
                    'total_invoices'    => $row['total_invoices'], // Ahora es "aplicaciones"
                    'total_amount'      => '$' . number_format($monto_total, 2),
                    'total_aplicado'    => '$' . number_format($monto_aplicado, 2), // ✅ NUEVO
                    'saldo_disponible'  => '$' . number_format($saldo, 2), // ✅ NUEVO
                    'status'            => $statusBadge,
                    'authorizations'    => $authIndicator,
                    'comment'           => $row['comment'] ?: '-',
                    'actions'           => $actions
                ];
            }

            echo json_encode(['data' => $data]);
        } catch (Exception $e) {
            echo json_encode(['error' => true, 'message' => $e->getMessage()]);
        }
    }
    private function buildAuthorizationIndicator(
        $abastos,
        $admin,
        $tesoreria,
        $abastos_user,
        $admin_user,
        $tesoreria_user,
        $abastos_date,
        $admin_date,
        $tesoreria_date
    ) {

        $html = '<div class="d-flex gap-1 align-items-center justify-content-center">';

        // Determinar el estado de cada nivel
        $nextLevel = null;
        if (!$abastos) {
            $nextLevel = 1;
        } elseif (!$admin) {
            $nextLevel = 2;
        } elseif (!$tesoreria) {
            $nextLevel = 3;
        }

        // NIVEL 1 - ABASTOS
        if ($abastos) {
            // Autorizado
            $tooltip = "Abastos ✓\n" . ($abastos_user ?: 'N/A') . "\n" . ($abastos_date ? date('d/m/Y H:i', strtotime($abastos_date)) : '');
            $html .= '<div class="auth-box bg-success" title="' . htmlspecialchars($tooltip) . '" data-bs-toggle="tooltip">
                        <i class="fas fa-check text-white"></i>
                    </div>';
        } elseif ($nextLevel === 1) {
            // Esperando autorización
            $html .= '<div class="auth-box bg-warning" title="Esperando: Abastos" data-bs-toggle="tooltip">
                        <i class="fas fa-clock text-white"></i>
                    </div>';
        } else {
            // Bloqueado
            $html .= '<div class="auth-box bg-secondary" title="Pendiente: Abastos" data-bs-toggle="tooltip">
                        <i class="fas fa-lock text-white"></i>
                    </div>';
        }

        // NIVEL 2 - ADMIN Y FINANZAS
        if ($admin) {
            // Autorizado
            $tooltip = "Admin y Finanzas ✓\n" . ($admin_user ?: 'N/A') . "\n" . ($admin_date ? date('d/m/Y H:i', strtotime($admin_date)) : '');
            $html .= '<div class="auth-box bg-success" title="' . htmlspecialchars($tooltip) . '" data-bs-toggle="tooltip">
                        <i class="fas fa-check text-white"></i>
                    </div>';
        } elseif ($nextLevel === 2) {
            // Esperando autorización
            $html .= '<div class="auth-box bg-warning" title="Esperando: Admin y Finanzas" data-bs-toggle="tooltip">
                        <i class="fas fa-clock text-white"></i>
                    </div>';
        } else {
            // Bloqueado
            $html .= '<div class="auth-box bg-secondary" title="Pendiente: Admin y Finanzas" data-bs-toggle="tooltip">
                        <i class="fas fa-lock text-white"></i>
                    </div>';
        }

        // NIVEL 3 - TESORERÍA
        if ($tesoreria) {
            // Autorizado
            $tooltip = "Tesorería ✓\n" . ($tesoreria_user ?: 'N/A') . "\n" . ($tesoreria_date ? date('d/m/Y H:i', strtotime($tesoreria_date)) : '');
            $html .= '<div class="auth-box bg-success" title="' . htmlspecialchars($tooltip) . '" data-bs-toggle="tooltip">
                        <i class="fas fa-check text-white"></i>
                    </div>';
        } elseif ($nextLevel === 3) {
            // Esperando autorización
            $html .= '<div class="auth-box bg-info" title="Esperando: Tesorería" data-bs-toggle="tooltip">
                        <i class="fas fa-clock text-white"></i>
                    </div>';
        } else {
            // Bloqueado
            $html .= '<div class="auth-box bg-secondary" title="Pendiente: Tesorería" data-bs-toggle="tooltip">
                        <i class="fas fa-lock text-white"></i>
                    </div>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Endpoint para generar pago (ya existente pero mejorado)
     */
    function generate_payment()
    {
        header('Content-Type: application/json');
        try {
            $json = file_get_contents('php://input');
            $data = json_decode($json, true);

            if ($data === null) {
                json_output(['success' => false, 'detail' => 'JSON inválido']);
                return;
            }

            $payment = $data['total_amount'] ?? null;
            $documents = $data['documentos'] ?? null;
            $user = $_SESSION['tg_user']['Id'] ?? null;
            $comment = $data['comment'] ?? 'Pago programado';
            $provider_cod = $data['provider_cod'] ?? null; // ✅ RECIBIR
            $provider_name = $data['provider_name'] ?? null; // ✅ OPCIONAL
            $empresa_cod = $data['empresa_cod'] ?? null; // ✅ OPCIONAL


            if (!$user) {
                json_output(['success' => false, 'detail' => 'Usuario no autenticado']);
                return;
            }

            if (!$documents || count($documents) === 0) {
                json_output(['success' => false, 'detail' => 'No hay documentos para procesar']);
                return;
            }
            if (!$provider_cod) {
                json_output(['success' => false, 'detail' => 'Código de proveedor requerido']);
                return;
            }
            $total_reques = 0;
            foreach ($documents as $doc) {
                $total_reques += $doc['total_fac'];
            }

            // Llamar al modelo para crear el pago con transacción
            $result = $this->PaymentRequestsModel->create_payment_with_invoices($user, $documents, $comment, $provider_cod, $empresa_cod, $total_reques);

            if ($result['success']) {
                // $this->enviar_notificacion_nuevo_pago($result['payment_id'],$provider_name ?? 'Proveedor',$result['total_documents'],$payment,$comment,$_SESSION['tg_user']['Nombre'] ?? 'Usuario');
                //se cambiara a uno pro dia

                json_output([
                    'success' => true,
                    'message' => $result['message'],
                    'payment_id' => $result['payment_id'],
                    'total_documents' => $result['total_documents'],
                    'total_amount' => $payment
                ]);
            } else {
                json_output([
                    'success' => false,
                    'detail' => $result['message']
                ]);
            }
        } catch (Exception $e) {
            error_log("Error en generate_payment: " . $e->getMessage());
            json_output([
                'success' => false,
                'detail' => 'Error al procesar el pago: ' . $e->getMessage()
            ]);
        }
    }

    function payment_detail($payment_id)
    {
        // try {
        $payment = $this->PaymentRequestsModel->get_request_by_id($payment_id);
        if (!$payment) {
            setFlashMessage('error', 'Pago no encontrado');
            redirect('/supply/payment_list');
            return;
        }
        // $payment = $payment[0];

        // ✅ Obtener facturas con cálculos desde el modelo
        $invoices = $this->paymentRequestInvoicesModel->get_by_payment_request_with_transactions($payment_id);
        $facturas_autorizadas = 0;
        $total_monto_autorizado = 0;
        // Obtener autorizaciones
        $authorizations = $this->paymentRequestAuthorizationsModel->get_by_payment_request($payment_id);
        $authorization_status = $this->paymentRequestAuthorizationsModel->get_authorization_status($payment_id);
        $invoice_credit_debit_notes = $this->InvoiceCreditDebitNotesModel->getCreditDebitNotes($payment_id);
        $notes_totals = $this->InvoiceCreditDebitNotesModel->calculateNotesTotals($payment_id);
        // Crear array con información de cada autorización
        $auth_info = [
            'abastos' => null,
            'admin_finanzas' => null,
            'tesoreria' => null
        ];
        if ($authorizations) {
            foreach ($authorizations as $auth) {
                if ($auth['permission_number'] == 66) {
                    $auth_info['abastos'] = $auth;
                } elseif ($auth['permission_number'] == 67) {
                    $auth_info['admin_finanzas'] = $auth;
                } elseif ($auth['permission_number'] == 68) {
                    $auth_info['tesoreria'] = $auth;
                }
            }
        }
        $transactions = $this->paymentTransactionsModel->get_by_payment_request($payment_id);

        // ✅ Obtener resumen desde el modelo
        $summary = $this->paymentRequestInvoicesModel->get_payment_summary_from_transactions($payment_id);
        $payment_calculation = [
            'invoice_total' => $summary['total_amount'] ?? 0,
            'advance_total' => $summary['total_advances'] ?? 0,
            'credit_notes_total' => $notes_totals['total_credits'],
            'debit_notes_total' => $notes_totals['total_debits'],
            'net_adjustment' => $notes_totals['net_adjustment'],
            'final_amount' => max(
                0,
                ($summary['total_amount'] ?? 0) -
                    ($summary['total_advances'] ?? 0) -
                    $notes_totals['total_credits'] +
                    $notes_totals['total_debits']
            )
        ];
        echo $this->twig->render($this->route . 'payment_detail.html', compact(
            'payment',
            'invoices',
            'authorizations',
            'authorization_status',
            'auth_info',
            'summary',
            'transactions',
            'facturas_autorizadas',
            'total_monto_autorizado',
            'invoice_credit_debit_notes',
            'notes_totals',
            'payment_calculation'
        ));
        // } catch (Exception $e) {
        //     setFlashMessage('error', 'Error al cargar el detalle: ' . $e->getMessage());
        //     redirect('/supply/payment_list');
        // }
    }
    function addNoteModal()
    {
        $payment_request_id = $_POST['payment_request_id'] ?? null;
        $payment_request = $this->PaymentRequestsModel->get_request_by_id($payment_request_id);
        echo $this->twig->render($this->route . 'modals/addNoteModal.html', compact('payment_request_id', 'payment_request'));
    }

    public function addCreditDebitNote()
    {
        try {
            // Validar que se recibió archivo
            if (!isset($_FILES['note_file']) || $_FILES['note_file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('No se recibió el archivo o hubo un error en la carga');
            }

            // Validar tipo de archivo
            $fileType = mime_content_type($_FILES['note_file']['tmp_name']);
            if ($fileType !== 'application/pdf') {
                throw new Exception('Solo se permiten archivos PDF');
            }

            // Validar tamaño (10MB máximo)
            if ($_FILES['note_file']['size'] > 10 * 1024 * 1024) {
                throw new Exception('El archivo no debe exceder 10MB');
            }

            // Validar datos requeridos
            $requiredFields = ['note_type', 'note_date', 'amount', 'description', 'payment_request_id'];
            foreach ($requiredFields as $field) {
                if (empty($_POST[$field])) {
                    throw new Exception("El campo {$field} es requerido");
                }
            }

            // Validar tipo de nota
            if (!in_array($_POST['note_type'], ['CREDIT', 'DEBIT'])) {
                throw new Exception('Tipo de nota inválido');
            }

            // Crear directorio para almacenar archivos
            $uploadDir = __DIR__ . '/../uploads/credit_debit_notes/' . date('Y') . '/' . date('m') . '/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            // Generar nombre único para el archivo
            $originalFilename = $_FILES['note_file']['name'];
            $extension = pathinfo($originalFilename, PATHINFO_EXTENSION);
            $newFilename = uniqid('note_') . '_' . time() . '.' . $extension;
            $filePath = $uploadDir . $newFilename;

            // Mover archivo
            if (!move_uploaded_file($_FILES['note_file']['tmp_name'], $filePath)) {
                throw new Exception('Error al guardar el archivo');
            }

            // Preparar datos para el modelo
            $data = [
                'payment_request_id' => $_POST['payment_request_id'],
                'provider_id' => $_POST['provider_id'],
                'note_type' => $_POST['note_type'],
                'note_number' => $_POST['note_number'] ?? null,
                'note_date' => $_POST['note_date'],
                'amount' => $_POST['amount'],
                'description' => $_POST['description'],
                'reason_code' => $_POST['reason_code'] ?? null,
                'file_path' => str_replace(__DIR__ . '/../', '', $filePath), // Ruta relativa
                'original_filename' => $originalFilename,
                'created_by' => $_SESSION['tg_user']['Id']
            ];

            // Guardar en base de datos usando el modelo
            $noteId = $this->InvoiceCreditDebitNotesModel->addCreditDebitNote($data);
            if (!$noteId) {
                throw new Exception('Error al guardar la nota en la base de datos');
            }

            echo json_encode([
                'success' => true,
                'message' => 'Nota guardada correctamente',
                'note_id' => $noteId
            ]);
        } catch (Exception $e) {
            // Si hubo error y se subió archivo, eliminarlo
            if (isset($filePath) && file_exists($filePath)) {
                unlink($filePath);
            }

            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * Eliminar nota de crédito/cargo (soft delete)
     */
    public function deleteCreditDebitNote($noteId)
    {
        try {
            // Verificar que la nota existe y obtener su información
            $note = $this->InvoiceCreditDebitNotesModel->getNoteById($noteId);

            if (!$note) {
                throw new Exception('Nota no encontrada o ya fue eliminada');
            }

            // Soft delete: cambiar status a 0
            $deleted = $this->InvoiceCreditDebitNotesModel->deleteCreditDebitNote(
                $noteId,
                $_SESSION['tg_user']['Id']
            );

            if (!$deleted) {
                throw new Exception('Error al eliminar la nota');
            }

            echo json_encode([
                'success' => true,
                'message' => 'Nota eliminada correctamente'
            ]);
        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    function delete_payment()
    {
        header('Content-Type: application/json');

        try {
            $payment_id = $_POST['payment_id'] ?? null;
            if (!$payment_id) {
                json_output(['success' => false, 'message' => 'ID de pago requerido']);
                return;
            }
            // Llamar al modelo para eliminar con transacción
            $result = $this->PaymentRequestsModel->delete_payment_complete($payment_id);
            json_output($result);
        } catch (Exception $e) {
            json_output(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    private function getStatusBadge($status)
    {
        return PaymentRequestsModel::getStatusBadge($status);
    }

    /**
     * Autorizar un pago
     */
    function authorize_payment()
    {
        header('Content-Type: application/json');
        try {
            $payment_id = $_POST['payment_id'] ?? null;
            $permission = $_POST['permission'] ?? null; // Permiso específico del botón
            $user_id = $_SESSION['tg_user']['Id'] ?? null;

            if (!$payment_id || !$user_id || !$permission) {
                json_output(['success' => false, 'message' => 'Datos incompletos']);
                return;
            }

            // Verificar que el usuario tenga el permiso que está intentando usar
            if (!authorized($permission)) {
                json_output(['success' => false, 'message' => 'No tienes permiso para autorizar en este nivel']);
                return;
            }


            // Verificar si puede autorizar con ese permiso específico
            $can_authorize = $this->paymentRequestAuthorizationsModel->can_user_authorize(
                $payment_id,
                $user_id,
                $permission
            );

            if (!$can_authorize['can_authorize']) {
                json_output(['success' => false, 'message' => $can_authorize['reason']]);
                return;
            }

            // Insertar autorización
            $auth_id = $this->paymentRequestAuthorizationsModel->insert_authorization(
                $payment_id,
                $user_id,
                $permission
            );

            if (!$auth_id) {
                json_output(['success' => false, 'message' => 'Error al registrar autorización']);
                return;
            }

            // Verificar si ya están todas las autorizaciones
            $next_level = $this->paymentRequestAuthorizationsModel->get_next_authorization_level($payment_id);

            if ($next_level === null) {
                // Todas las autorizaciones completadas - cambiar estado a AUTHORIZED
                $this->PaymentRequestsModel->update_request_status(
                    $payment_id,
                    PaymentRequestsModel::STATUS_AUTHORIZED
                );
                $message = '✅ Pago completamente autorizado. Tesorería puede proceder al pago.';
            } else {
                // $this->enviar_notificacion_autorizacion_pendiente($payment_id, $next_level, $permission,$user_id);
                //se mandara una diaria

                // Aún faltan autorizaciones
                $department_name = match ($next_level) {
                    66 => 'Abastos',
                    67 => 'Administración y Finanzas',
                    68 => 'Tesorería',
                    default => 'Desconocido'
                };
                $message = "✅ Autorización registrada exitosamente. Esperando autorización de: $department_name";
            }

            json_output([
                'success' => true,
                'message' => $message,
                'next_level' => $next_level,
                'all_authorized' => $next_level === null
            ]);
        } catch (Exception $e) {
            json_output(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
    }

    /**
     * Método auxiliar para get_department_name (también en el modelo)
     */
    public function get_department_name($permission_number)
    {
        return match ($permission_number) {
            66 => 'Abastos',
            67 => 'Administración y Finanzas',
            68 => 'Tesorería',
            default => 'Desconocido'
        };
    }

    function authorize_payment_execution()
    {
        header('Content-Type: application/json');

        try {
            // Obtener datos JSON
            $json = file_get_contents('php://input');
            $data = json_decode($json, true);

            if ($data === null) {
                json_output(['success' => false, 'message' => 'Datos JSON inválidos']);
                return;
            }

            $payment_id = $data['payment_id'] ?? null;
            $facturas = $data['facturas'] ?? [];
            $user_id = $_SESSION['tg_user']['Id'] ?? null;

            // ========================================
            // VALIDACIONES
            // ========================================

            if (!$payment_id || !$user_id) {
                json_output(['success' => false, 'message' => 'Datos incompletos']);
                return;
            }

            if (empty($facturas)) {
                json_output(['success' => false, 'message' => 'Debe seleccionar al menos una factura']);
                return;
            }

            // Verificar que el pago exista y esté AUTORIZADO (status = 1)
            $payment = $this->PaymentRequestsModel->get_request_by_id($payment_id);

            if (!$payment) {
                json_output(['success' => false, 'message' => 'Solicitud de pago no encontrada']);
                return;
            }

            if ($payment['status'] != PaymentRequestsModel::STATUS_AUTHORIZED) {
                json_output([
                    'success' => false,
                    'message' => 'El pago debe estar completamente autorizado por los 3 niveles antes de autorizar facturas individuales'
                ]);
                return;
            }

            // Verificar que el usuario tenga permiso de Tesorería (68)
            if (!authorized(68)) {
                json_output(['success' => false, 'message' => 'Solo Tesorería puede autorizar facturas para ejecución de pago']);
                return;
            }

            // ========================================
            // PROCESAR AUTORIZACIONES USANDO EL MODELO
            // ========================================

            $result = $this->paymentRequestInvoicesModel->authorize_invoices_for_payment(
                $payment_id,
                $facturas,
                $user_id
            );

            // Responder con el resultado del modelo
            if ($result['success']) {
                // Construir mensaje con detalles
                $mensaje = $result['message'];

                if (!empty($result['errores'])) {
                    $mensaje .= "\n\nAdvertencias:\n" . implode("\n", $result['errores']);
                }

                json_output([
                    'success' => true,
                    'message' => $mensaje,
                    'facturas_autorizadas' => $result['facturas_autorizadas'],
                    'total_autorizado' => number_format($result['total_autorizado'], 2, '.', ''),
                    'errores' => $result['errores'] ?? []
                ]);
            } else {
                json_output(['success' => false, 'message' => $result['message']]);
            }
        } catch (Exception $e) {
            error_log("Error en authorize_payment_execution: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            json_output(['success' => false, 'message' => 'Error del servidor: ' . $e->getMessage()]);
        }
    }


    function execute_authorized_payments()
    {
        header('Content-Type: application/json');
        try {
            $invoice_ids = json_decode($_POST['invoice_ids'] ?? '[]', true);
            $fecha_pago = $_POST['fecha_pago'] ?? null;
            $referencia_bancaria = $_POST['referencia_bancaria'] ?? null;
            $observaciones = $_POST['observaciones'] ?? '';
            $user_id = $_SESSION['tg_user']['Id'] ?? null;


            if (empty($invoice_ids)) {
                return $this->responderJSON(false, 'No se proporcionaron facturas');
            }
            if (!$fecha_pago || !$referencia_bancaria) {
                return $this->responderJSON(false, 'Faltan datos obligatorios: fecha y referencia bancaria');
            }

            if (!$user_id) {
                return $this->responderJSON(false, 'Usuario no identificado');
            }
            if (!authorized(68)) {
                return $this->responderJSON(false, 'Solo Tesorería puede ejecutar pagos');
            }

            // $facturas_autorizadas = $this->paymentRequestInvoicesModel->get_authorized_pending_payment($payment_id);
            $facturas_data = $this->paymentRequestInvoicesModel->get_facturas_autorizadas_by_ids($invoice_ids);


            if (!$facturas_data || empty($facturas_data)) {
                json_output(['success' => false, 'message' => 'No hay facturas autorizadas pendientes de pago']);
                return;
            }

            // ✅ PREPARAR DATOS PARA PROCESAR (formato que espera el modelo)
            $facturas_procesar = [];
            $payment_request_ids_unicos = [];
            foreach ($facturas_data as $factura) {
                if ($factura['payment_authorized'] != 1) {
                    return $this->responderJSON(false, "La factura {$factura['folio']} no está autorizada");
                }
                $facturas_procesar[] = [
                    'invoice_id' => $factura['id'],
                    'folio' => $factura['folio'],
                    'monto_pagar' => $factura['authorized_amount'], // ✅ Usar monto autorizado
                    'saldo_anterior' => $factura['saldo'],
                    'payment_request_id' => $factura['payment_request_id'] // ✅ Incluir para el proceso

                ];

                $payment_request_ids_unicos[$factura['payment_request_id']] = true;
            }
            // ✅ EJECUTAR PAGO MASIVO=====================================

            $result = $this->paymentTransactionsModel->process_bulk_payment(
                $facturas_procesar,
                $user_id,
                $fecha_pago,
                $observaciones,
                $referencia_bancaria,
                'TRANSFERENCIA'
            );

            // ========================================
            // PROCESAR RESULTADO
            // ========================================

            // ✅ PROCESAR RESULTADO
            if ($result['success']) {
                // ✅ REVISAR CADA PAYMENT_REQUEST_ID ÚNICO
                $solicitudes_completadas = 0;
                foreach (array_keys($payment_request_ids_unicos) as $payment_request_id) {
                    $all_paid = $this->paymentTransactionsModel->check_all_invoices_paid($payment_request_id);

                    if ($all_paid) {
                        $this->PaymentRequestsModel->update_request_status(
                            $payment_request_id,
                            PaymentRequestsModel::STATUS_PAID,
                            "Pago ejecutado el " . date('d/m/Y', strtotime($fecha_pago)) . " - Ref: $referencia_bancaria"
                        );
                        $solicitudes_completadas++;
                    }
                }

                return $this->responderJSON(true, $result['message'], [
                    'facturas_procesadas' => $result['facturas_procesadas'],
                    'total_pagado' => $result['total_pagado'],
                    'fecha_pago' => date('d/m/Y', strtotime($fecha_pago)),
                    'referencia_bancaria' => $referencia_bancaria,
                    'solicitudes_completadas' => $solicitudes_completadas,
                    'total_solicitudes' => count($payment_request_ids_unicos)
                ]);
            } else {
                return $this->responderJSON(false, $result['message']);
            }
        } catch (Exception $e) {
            error_log("Error en execute_authorized_payments: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            json_output(['success' => false, 'message' => 'Error del servidor: ' . $e->getMessage()]);
        }
    }



    // function process_payment()
    // {
    //     header('Content-Type: application/json');
    //     try {
    //         // Obtener datos JSON
    //         $json = file_get_contents('php://input');
    //         $data = json_decode($json, true);
    //         if ($data === null) {
    //             json_output(['success' => false, 'message' => 'Datos JSON inválidos']);
    //             return;
    //         }

    //         $payment_id = $data['payment_id'] ?? null;
    //         $facturas = $data['facturas'] ?? [];
    //         $observaciones = $data['observaciones'] ?? '';
    //         $referencia = $data['referencia'] ?? '';
    //         $fecha_pago = $data['fecha_pago'] ?? date('Y-m-d');
    //         $user_id = $_SESSION['tg_user']['Id'] ?? null;

    //         // Validaciones
    //         if (!$payment_id || !$user_id) {
    //             json_output(['success' => false, 'message' => 'Datos incompletos']);
    //             return;
    //         }

    //         if (empty($facturas)) {
    //             json_output(['success' => false, 'message' => 'Debe seleccionar al menos una factura']);
    //             return;
    //         }

    //         // Verificar que el pago esté autorizado
    //         $payment = $this->PaymentRequestsModel->get_request_by_id($payment_id);

    //         if (!$payment || $payment['status'] != PaymentRequestsModel::STATUS_AUTHORIZED) {
    //             json_output(['success' => false, 'message' => 'El pago debe estar completamente autorizado']);
    //             return;
    //         }

    //         // Verificar que el usuario tenga permiso de Tesorería
    //         if (!authorized(68)) {
    //             json_output(['success' => false, 'message' => 'Solo Tesorería puede procesar pagos']);
    //             return;
    //         }

    //         // Procesar pago usando el modelo
    //         $result = $this->paymentTransactionsModel->process_bulk_payment(
    //             $payment_id,
    //             $facturas,
    //             $user_id,
    //             $fecha_pago,
    //             $observaciones,
    //             $referencia
    //         );

    //         if ($result['success']) {
    //             // Si todas las facturas están completamente pagadas, cambiar estado del pago
    //             if ($result['all_paid']) {
    //                 $this->PaymentRequestsModel->update_request_status($payment_id,PaymentRequestsModel::STATUS_PAID);
    //             }

    //             json_output([
    //                 'success' => true,
    //                 'message' => $result['message'],
    //                 'facturas_procesadas' => $result['facturas_procesadas'],
    //                 'total_pagado' => $result['total_pagado']
    //             ]);
    //         } else {
    //             json_output(['success' => false, 'message' => $result['message']]);
    //         }
    //     } catch (Exception $e) {
    //         error_log("Error en process_payment: " . $e->getMessage());
    //         json_output(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    //     }
    // }
    function get_payment_history()
    {
        header('Content-Type: application/json');

        $invoice_id = $_GET['invoice_id'] ?? null;

        if (!$invoice_id) {
            json_output(['success' => false, 'message' => 'ID de factura requerido']);
            return;
        }

        try {
            $transactions = $this->paymentTransactionsModel->get_payment_history($invoice_id);

            if ($transactions) {
                json_output([
                    'success' => true,
                    'data' => $transactions
                ]);
            } else {
                json_output([
                    'success' => true,
                    'data' => [],
                    'message' => 'No hay pagos registrados'
                ]);
            }
        } catch (Exception $e) {
            json_output([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ]);
        }
    }
    public function modalCrearAnticipo()
    {
        try {
            $proveedores = $this->proveedores->get_actives();
            $companys = $this->gasolinerasModel->get_company();

            // Renderizar vista Twig
            echo $this->twig->render($this->route . 'modals/crear_anticipo.html', compact('proveedores', 'companys'));
        } catch (Exception $e) {
            error_log('Error en modalCrearAnticipo: ' . $e->getMessage());
            echo '<div class="modal-body">
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i>
                        Error al cargar el formulario: ' . htmlspecialchars($e->getMessage()) . '
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                </div>';
        }
    }
    /**
     * Crear un nuevo anticipo (pago sin factura)
     */
    public function create_anticipo()
    {
        header('Content-Type: application/json');
        try {
            $json = file_get_contents('php://input');
            $data = json_decode($json, true);
            $user_id = $_SESSION['tg_user']['Id'] ?? null;
            // Validar que el usuario esté autenticado
            if (!isset($user_id)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Usuario no autenticado'
                ]);
                return;
            }
            // Extraer y validar datos
            $provider_cod = $data['provider_cod'] ?? null;
            $empresa_cod = $data['empresa_cod'] ?? null;
            $monto = $data['monto'] ?? 0;
            $comentario = trim($data['comentario'] ?? '');


            // Validaciones
            if (empty($provider_cod)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Debe seleccionar un proveedor'
                ]);
                return;
            }

            if (empty($empresa_cod)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Debe seleccionar una empresa'
                ]);
                return;
            }

            if (!is_numeric($monto) || $monto <= 0) {
                echo json_encode([
                    'success' => false,
                    'message' => 'El monto debe ser mayor a cero'
                ]);
                return;
            }

            if (strlen($comentario) < 10) {
                echo json_encode([
                    'success' => false,
                    'message' => 'La justificación debe tener al menos 10 caracteres'
                ]);
                return;
            }

            // Obtener nombre del proveedor
            $proveedor = $this->proveedores->get_by_id($provider_cod);
            if (!$proveedor) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Proveedor no encontrado'
                ]);
                return;
            }

            // Preparar datos para el modelo
            $anticipo_data = [
                'provider_cod' => $provider_cod,
                'empresa_cod' => $empresa_cod,
                'monto_total' => $monto,
                'nombre_request' => 'ANTICIPO - ' . $proveedor['den'],
                'comentario' => $comentario,
                'user_id' => $user_id
            ];

            // Crear anticipo usando el modelo
            $result = $this->PaymentRequestsModel->create_anticipo($anticipo_data);

            if ($result['success']) {
                // Log de auditoría
                error_log("ANTICIPO CREADO: ID={$result['anticipo_id']}, Provider=$provider_cod, Empresa=$empresa_cod, Monto=$monto, User=$user_id");
            }

            // Retornar resultado
            echo json_encode($result);
        } catch (Exception $e) {
            error_log('Error en create_anticipo: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());

            echo json_encode([
                'success' => false,
                'message' => 'Error al procesar la solicitud: ' . $e->getMessage()
            ]);
        }
    }

    function download_layout($filename)
    {
        // ✅ Sanitizar filename (seguridad básica)
        $filename = basename($filename); // Elimina path traversal
        $filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $filename); // Solo caracteres seguros

        // ✅ Verificar extensión
        if (!str_ends_with($filename, '.xls')) {
            http_response_code(400);
            exit('Extensión no permitida');
        }

        $file = __DIR__ . '/../../_assets/temp/layouts/' . $filename;

        if (file_exists($file)) {
            header('Content-Description: File Transfer');
            header('Content-Type: application/vnd.ms-excel');
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
            exit('Archivo no encontrado');
        }
    }
    function delete_layout()
    {
        header('Content-Type: application/json');

        $filename = $_POST['filename'] ?? '';
        $filename = basename($filename); // Seguridad

        if (empty($filename)) {
            json_output(['success' => false, 'message' => 'Filename requerido']);
            return;
        }

        if (pathinfo($filename, PATHINFO_EXTENSION) !== 'xls') {
            json_output(['success' => false, 'message' => 'Extensión no válida']);
            return;
        }

        $file = __DIR__ . '/../../_assets/temp/layouts/' . $filename;

        if (file_exists($file)) {
            if (unlink($file)) {
                error_log("Archivo eliminado: $filename");
                json_output(['success' => true, 'message' => 'Archivo eliminado']);
            } else {
                error_log("ERROR: No se pudo eliminar: $filename");
                json_output(['success' => false, 'message' => 'Error al eliminar']);
            }
        } else {
            json_output(['success' => false, 'message' => 'Archivo no existe']);
        }
    }

    public function configLayoutModal()
    {
        try {
            $payment_id = isset($_POST['payment_id']) ? intval($_POST['payment_id']) : 0;

            if (!$payment_id) {
                echo '<div class="alert alert-danger">ID de pago requerido</div>';
                return;
            }

            // Obtener datos del pago
            $payment = $this->PaymentRequestsModel->get_request_by_id($payment_id);
            if (!$payment || count($payment) === 0) {
                echo '<div class="alert alert-danger">Pago no encontrado</div>';
                return;
            }
            $payment = $payment[0];

            // Obtener datos del proveedor
            $proveedor = $this->proveedores->get_by_id($payment['provider_cod']);
            if (!$proveedor) {
                echo '<div class="alert alert-danger">Proveedor no encontrado</div>';
                return;
            }

            // Obtener TODAS las cuentas bancarias del proveedor
            $cuentas_bancarias = $this->CuentasBancariasModel->get_cuentas($proveedor['den']);

            if (!$cuentas_bancarias || count($cuentas_bancarias) === 0) {
                echo '<div class="alert alert-warning">' .
                    '<i class="fas fa-exclamation-triangle"></i> ' .
                    'No se encontraron cuentas bancarias para el proveedor: <strong>' . htmlspecialchars($proveedor['den']) . '</strong>' .
                    '</div>';
                return;
            }

            // Obtener facturas del pago
            $facturas = $this->paymentRequestInvoicesModel->get_by_payment_request_with_transactions($payment_id);

            // Renderizar vista Twig
            echo $this->twig->render($this->route . 'modals/configLayoutModal.html', [
                'proveedor' => [
                    'codigo' => $payment['provider_cod'],
                    'nombre' => $proveedor['den']
                ],
                'cuentas_bancarias' => $cuentas_bancarias,
                'facturas' => $facturas
            ]);
        } catch (Exception $e) {
            error_log('Error en configLayoutModal: ' . $e->getMessage());
            echo '<div class="alert alert-danger">' .
                '<i class="fas fa-exclamation-circle"></i> ' .
                'Error al cargar la configuración: ' . htmlspecialchars($e->getMessage()) .
                '</div>';
        }
    }


    public function anticipo_detail($anticipo_id)
    {
        //  try {
        // Obtener datos del anticipo - USAR MÉTODO CORRECTO
        $anticipo = $this->PaymentRequestsModel->get_request_by_id($anticipo_id);
        if (!$anticipo || $anticipo['tipo'] != 1) { // tipo 1 = anticipo
            $_SESSION['error'] = 'Anticipo no encontrado';
            header('Location: /supply/payment_list');
            return;
        }
        // Obtener aplicaciones del anticipo
        $aplicaciones = $this->PaymentRequestsModel->get_anticipo_applications($anticipo_id);
        // Obtener resumen (totales)
        $summary = $this->PaymentRequestsModel->get_anticipo_summary($anticipo_id);
        // Si no hay summary, crear uno vacío
        if (!$summary) {
            $summary = [
                'id' => $anticipo_id,
                'monto_original' => $anticipo['monto_total'],
                'total_aplicado' => 0,
                'saldo_disponible' => $anticipo['monto_total'],
                'total_aplicaciones' => 0
            ];
        }
        // Obtener autorizaciones
        $authorizations = $this->PaymentRequestsModel->getPaymentAuthorizations($anticipo_id);
        // Estado de autorizaciones
        $auth_status = $this->PaymentRequestsModel->getAuthorizationStatus($anticipo_id);
        // Renderizar vista
        // echo $this->twig->render($this->route . 'anticipo_detail.html', compact());

        echo $this->twig->render($this->route . 'anticipo_detail.html', [
            'anticipo' => $anticipo,
            'aplicaciones' => $aplicaciones,
            'summary' => $summary,
            'authorizations' => $authorizations,
            'authorization_status' => $auth_status,
            'auth_info' => [
                'abastos' => $this->PaymentRequestsModel->getAuthorizationInfo($anticipo_id, 66),
                'admin_finanzas' => $this->PaymentRequestsModel->getAuthorizationInfo($anticipo_id, 67),
                'tesoreria' => $this->PaymentRequestsModel->getAuthorizationInfo($anticipo_id, 68)
            ]
        ]);
        // } catch (Exception $e) {
        //     error_log("Error en anticipo_detail: " . $e->getMessage());
        //     $_SESSION['error'] = 'Error al cargar el detalle del anticipo';
        //     header('Location: /supply/payment_list');

        //     // $this->redirectTo('supply/payment_list');
        // }
    }

    public function apply_anticipo_to_invoices()
    {
        header('Content-Type: application/json');

        try {
            $anticipo_id = $_POST['anticipo_id'] ?? null;
            $aplicaciones = json_decode($_POST['aplicaciones'] ?? '[]', true);

            if (!$anticipo_id || empty($aplicaciones)) {
                json_output(['success' => false, 'message' => 'Datos incompletos']);
                return;
            }

            // Validar saldo disponible
            $anticipo = $this->PaymentRequestsModel->get_request_by_id($anticipo_id);

            if (!$anticipo || $anticipo['tipo'] != 1) {
                json_output(['success' => false, 'message' => 'Anticipo no válido']);
                return;
            }

            $saldo = $this->PaymentRequestsModel->get_saldo_disponible($anticipo_id);
            $total_aplicar = array_sum(array_column($aplicaciones, 'monto'));

            if ($total_aplicar > $saldo) {
                json_output(['success' => false, 'message' => 'Excede saldo disponible']);
                return;
            }

            // Registrar aplicaciones
            $result = $this->PaymentRequestsModel->register_anticipo_applications(
                $anticipo_id,
                $aplicaciones,
                $_SESSION['tg_user']['Id']
            );

            json_output($result);
        } catch (Exception $e) {
            error_log("Error en apply_anticipo_to_invoices: " . $e->getMessage());
            json_output(['success' => false, 'message' => 'Error del servidor']);
        }
    }

    private function enviar_notificacion_nuevo_pago($payment_id, $provider_name, $total_documents, $total_amount, $comment, $created_by)
    {
        try {
            // Obtener correos de usuarios con permiso de Abastos (66)
            $emails = $this->UsuariosModel->get_emails_by_permission(66);

            if (empty($emails)) {
                error_log("No hay usuarios con permiso de Abastos para notificar");
                return;
            }
            $emails = array_filter($emails, function ($email) {
                return strtolower(trim($email)) !== 'kuwait.valenzuela@totalgas.com';
            });

            // Crear el cuerpo del correo
            $subject = "Nuevo Pago Creado - ID #{$payment_id}";
            $body = $this->generar_html_notificacion_pago(
                $payment_id,
                $provider_name,
                $total_documents,
                $total_amount,
                $comment,
                $created_by
            );

            // Enviar correo
            $from = 'totalgasdesarrollo@gmail.com';

            // Capturar salida para evitar problemas con JSON
            ob_start();
            $resultado = @send_mail2($subject, $body, $emails, $from);
            ob_get_clean();

            if ($resultado) {
                error_log("Notificación de pago #{$payment_id} enviada a: " . implode(', ', $emails));
            } else {
                error_log("Error al enviar notificación de pago #{$payment_id}");
            }
        } catch (Exception $e) {
            error_log("Error en enviar notificacion_nuevo_pago: " . $e->getMessage());
        }
    }

    private function generar_html_notificacion_pago($payment_id, $provider_name, $total_documents, $total_amount, $comment, $created_by)
    {
        $fecha = date('d/m/Y H:i:s');
        $total_formatted = number_format($total_amount, 2, '.', ',');

        // URL del detalle del pago (ajustar según tu dominio)
        $url_detalle = "http://totalgasonline.net:400/supply/payment_detail/{$payment_id}";

        return "
        <!DOCTYPE html>
        <html lang='es'>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <style>
                body {
                    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                    background-color: #f4f4f4;
                    margin: 0;
                    padding: 20px;
                }
                .container {
                    max-width: 600px;
                    margin: 0 auto;
                    background: white;
                    border-radius: 10px;
                    overflow: hidden;
                    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                }
                .header {
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    color: white;
                    padding: 30px;
                    text-align: center;
                }
                .header h1 {
                    margin: 0;
                    font-size: 24px;
                }
                .header p {
                    margin: 5px 0 0 0;
                    opacity: 0.9;
                }
                .content {
                    padding: 30px;
                }
                .badge {
                    display: inline-block;
                    background: #667eea;
                    color: white;
                    padding: 5px 15px;
                    border-radius: 20px;
                    font-size: 14px;
                    margin-bottom: 20px;
                }
                .info-row {
                    display: flex;
                    justify-content: space-between;
                    padding: 15px 0;
                    border-bottom: 1px solid #eee;
                }
                .info-row:last-child {
                    border-bottom: none;
                }
                .info-label {
                    color: #666;
                    font-weight: 500;
                }
                .info-value {
                    color: #333;
                    font-weight: 600;
                }
                .total-box {
                    background: #f8f9fa;
                    padding: 20px;
                    border-radius: 8px;
                    margin: 20px 0;
                    text-align: center;
                }
                .total-label {
                    color: #666;
                    font-size: 14px;
                    margin-bottom: 5px;
                }
                .total-amount {
                    color: #28a745;
                    font-size: 32px;
                    font-weight: bold;
                }
                .comment-box {
                    background: #fff3cd;
                    border-left: 4px solid #ffc107;
                    padding: 15px;
                    margin: 20px 0;
                    border-radius: 4px;
                }
                .comment-label {
                    font-weight: 600;
                    color: #856404;
                    margin-bottom: 5px;
                }
                .comment-text {
                    color: #856404;
                }
                .button {
                    display: inline-block;
                    background: #667eea;
                    color: white;
                    padding: 12px 30px;
                    text-decoration: none;
                    border-radius: 5px;
                    margin: 20px 0;
                    font-weight: 600;
                }
                .button:hover {
                    background: #5568d3;
                }
                .footer {
                    background: #f8f9fa;
                    padding: 20px;
                    text-align: center;
                    color: #666;
                    font-size: 12px;
                }
                .icon {
                    display: inline-block;
                    width: 20px;
                    height: 20px;
                    margin-right: 8px;
                    vertical-align: middle;
                }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>✓ Nuevo Pago Creado</h1>
                    <p>Sistema de Gestión de Pagos - TotalGas</p>
                </div>
                
                <div class='content'>
                    <div class='badge'>🔔 Notificación - Departamento de Abastos</div>
                    
                    <p style='color: #333; line-height: 1.6;'>
                        Se ha creado un nuevo pago que requiere autorización del departamento de Abastos.
                    </p>
                    
                    <div style='margin: 20px 0;'>
                        <div class='info-row'>
                            <span class='info-label'>📋 ID de Pago:</span>
                            <span class='info-value'>#{$payment_id}</span>
                        </div>
                        <div class='info-row'>
                            <span class='info-label'>🏢 Proveedor:</span>
                            <span class='info-value'>{$provider_name}</span>
                        </div>
                        <div class='info-row'>
                            <span class='info-label'>📄 Documentos:</span>
                            <span class='info-value'>{$total_documents}</span>
                        </div>
                        <div class='info-row'>
                            <span class='info-label'>👤 Creado por:</span>
                            <span class='info-value'>{$created_by}</span>
                        </div>
                        <div class='info-row'>
                            <span class='info-label'>📅 Fecha:</span>
                            <span class='info-value'>{$fecha}</span>
                        </div>
                    </div>
                    
                    <div class='total-box'>
                        <div class='total-label'>Monto Total del Pago</div>
                        <div class='total-amount'>{$total_formatted}</div>
                    </div>
                    
                    " . (!empty($comment) ? "
                    <div class='comment-box'>
                        <div class='comment-label'>💬 Comentario:</div>
                        <div class='comment-text'>{$comment}</div>
                    </div>
                    " : "") . "
                    
                    <div style='text-align: center; margin-top: 30px;'>
                        <a href='{$url_detalle}' class='button'>
                            Ver Detalle del Pago →
                        </a>
                    </div>
                    
                    <p style='color: #666; font-size: 14px; margin-top: 30px;'>
                        Este pago requiere su autorización para poder continuar con el proceso de pago. 
                        Por favor, revise los documentos y autorice según corresponda.
                    </p>
                </div>
                
                <div class='footer'>
                    <p><strong>TotalGas - Sistema de Gestión de Pagos</strong></p>
                    <p>Este es un correo automático, por favor no responda a este mensaje.</p>
                    <p style='margin-top: 10px; font-size: 11px;'>
                        © " . date('Y') . " TotalGas. Todos los derechos reservados.
                    </p>
                </div>
            </div>
        </body>
        </html>
        ";
    }
    private function enviar_notificacion_autorizacion_pendiente($payment_id, $next_level_permission, $authorized_permission, $user_id)
    {
        try {
            // Obtener correos del siguiente nivel
            $emails = $this->UsuariosModel->get_emails_by_permission($next_level_permission);
            if (empty($emails)) {
                error_log("No hay usuarios con permiso {$next_level_permission} para notificar");
                return;
            }

            // ============================================================
            // 🚧 BLOQUE TEMPORAL PARA PRUEBAS - REMOVER AL TERMINAR 🚧
            // ============================================================
            $emails = array_filter($emails, function ($email) {
                return strtolower(trim($email)) !== 'kuwait.valenzuela@totalgas.com';
            });

            $emails = array_values($emails);
            if (empty($emails)) {
                error_log("⚠️ No hay correos disponibles después del filtro de pruebas");
                return;
            }
            // ============================================================
            // Obtener información del pago
            $payment = $this->PaymentRequestsModel->get_request_by_id($payment_id);
            if (!$payment) {
                error_log("Pago #{$payment_id} no encontrado");
                return;
            }

            // Obtener nombre del proveedor
            $proveedor = $this->proveedores->get_by_id($payment['provider_cod']);
            $provider_name = $proveedor ? $proveedor['den'] : 'Proveedor';

            // Obtener nombre del siguiente nivel
            $next_department = match ($next_level_permission) {
                66 => 'Abastos',
                67 => 'Administración y Finanzas',
                68 => 'Tesorería',
                default => 'Desconocido'
            };

            // Crear el cuerpo del correo
            $subject = "Pago #{$payment_id} requiere tu autorización - {$next_department}";
            $body = $this->generar_html_notificacion_autorizacion(
                $payment_id,
                $provider_name,
                $payment['total_invoices'] ?? 0,
                $payment['monto_total'] ?? 0,
                $user_id,
                $next_department,
                $payment['comment'] ?? ''
            );

            // Enviar correo
            $from = 'totalgasdesarrollo@gmail.com';

            ob_start();
            $resultado = @send_mail2($subject, $body, $emails, $from);
            ob_get_clean();

            if ($resultado) {
                error_log("Notificación de autorización pendiente para pago #{$payment_id} enviada a {$next_department}: " . implode(', ', $emails));
            } else {
                error_log("Error al enviar notificación de autorización pendiente para pago #{$payment_id}");
            }
        } catch (Exception $e) {
            error_log("Error en enviar notificacion_autorizacion_pendiente: " . $e->getMessage());
        }
    }

    private function generar_html_notificacion_autorizacion(
        $payment_id,
        $provider_name,
        $total_documents,
        $total_amount,
        $authorized_department,
        $next_department,
        $comment
    ) {
        $fecha = date('d/m/Y H:i:s');
        $total_formatted = number_format($total_amount, 2, '.', ',');
        $url_detalle = "http://totalgasonline.net:400/supply/payment_detail/{$payment_id}";

        return "
        <!DOCTYPE html>
        <html lang='es'>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <style>
                body {
                    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                    background-color: #f4f4f4;
                    margin: 0;
                    padding: 20px;
                }
                .container {
                    max-width: 600px;
                    margin: 0 auto;
                    background: white;
                    border-radius: 10px;
                    overflow: hidden;
                    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                }
                .header {
                    background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
                    color: white;
                    padding: 30px;
                    text-align: center;
                }
                .header h1 {
                    margin: 0;
                    font-size: 24px;
                }
                .header p {
                    margin: 5px 0 0 0;
                    opacity: 0.9;
                }
                .content {
                    padding: 30px;
                }
                .badge {
                    display: inline-block;
                    background: #17a2b8;
                    color: white;
                    padding: 5px 15px;
                    border-radius: 20px;
                    font-size: 14px;
                    margin-bottom: 20px;
                }
                .status-box {
                    background: #d1ecf1;
                    border: 1px solid #bee5eb;
                    border-radius: 8px;
                    padding: 15px;
                    margin: 20px 0;
                }
                .status-box .status-label {
                    font-weight: 600;
                    color: #0c5460;
                    margin-bottom: 8px;
                }
                .status-item {
                    display: flex;
                    align-items: center;
                    padding: 8px 0;
                }
                .status-item i {
                    margin-right: 10px;
                    font-size: 18px;
                }
                .status-completed {
                    color: #28a745;
                }
                .status-pending {
                    color: #ffc107;
                }
                .info-row {
                    display: flex;
                    justify-content: space-between;
                    padding: 15px 0;
                    border-bottom: 1px solid #eee;
                }
                .info-row:last-child {
                    border-bottom: none;
                }
                .info-label {
                    color: #666;
                    font-weight: 500;
                }
                .info-value {
                    color: #333;
                    font-weight: 600;
                }
                .total-box {
                    background: #f8f9fa;
                    padding: 20px;
                    border-radius: 8px;
                    margin: 20px 0;
                    text-align: center;
                }
                .total-label {
                    color: #666;
                    font-size: 14px;
                    margin-bottom: 5px;
                }
                .total-amount {
                    color: #17a2b8;
                    font-size: 32px;
                    font-weight: bold;
                }
                .comment-box {
                    background: #fff3cd;
                    border-left: 4px solid #ffc107;
                    padding: 15px;
                    margin: 20px 0;
                    border-radius: 4px;
                }
                .comment-label {
                    font-weight: 600;
                    color: #856404;
                    margin-bottom: 5px;
                }
                .comment-text {
                    color: #856404;
                }
                .button {
                    display: inline-block;
                    background: #17a2b8;
                    color: white;
                    padding: 12px 30px;
                    text-decoration: none;
                    border-radius: 5px;
                    margin: 20px 0;
                    font-weight: 600;
                }
                .button:hover {
                    background: #138496;
                }
                .footer {
                    background: #f8f9fa;
                    padding: 20px;
                    text-align: center;
                    color: #666;
                    font-size: 12px;
                }
                .alert-box {
                    background: #d4edda;
                    border: 1px solid #c3e6cb;
                    color: #155724;
                    padding: 15px;
                    border-radius: 8px;
                    margin: 20px 0;
                }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>⏳ Autorización Requerida</h1>
                    <p>Sistema de Gestión de Pagos - TotalGas</p>
                </div>

                <div class='content'>
                    <div class='badge'>🔔 Notificación - {$next_department}</div>

                    <div class='alert-box'>
                        <br>Se ha autorizado el pago.<br>
                        Ahora requiere tu autorización para continuar con el proceso.
                    </div>

                    <div style='margin: 20px 0;'>
                        <div class='info-row'>
                            <span class='info-label'>📋 ID de Pago:</span>
                            <span class='info-value'>#{$payment_id}</span>
                        </div>
                        <div class='info-row'>
                            <span class='info-label'>🏢 Proveedor:</span>
                            <span class='info-value'>{$provider_name}</span>
                        </div>
                        <div class='info-row'>
                            <span class='info-label'>📄 Documentos:</span>
                            <span class='info-value'>{$total_documents}</span>
                        </div>
                        <div class='info-row'>
                            <span class='info-label'>📅 Fecha:</span>
                            <span class='info-value'>{$fecha}</span>
                        </div>
                    </div>

                    <div class='total-box'>
                        <div class='total-label'>Monto Total del Pago</div>
                        <div class='total-amount'>\${$total_formatted}</div>
                    </div>

                    <div class='status-box'>
                        <div class='status-label'>📊 Estado de Autorizaciones:</div>
                        <div class='status-item status-completed'>
                            <i class='fas fa-check-circle'></i>
                            <span><strong>{$authorized_department}:</strong> Autorizado</span>
                        </div>
                        <div class='status-item status-pending'>
                            <i class='fas fa-clock'></i>
                            <span><strong>{$next_department}:</strong> Pendiente (Tu autorización)</span>
                        </div>
                    </div>

                    " . (!empty($comment) ? "
                    <div class='comment-box'>
                        <div class='comment-label'>💬 Comentario:</div>
                        <div class='comment-text'>{$comment}</div>
                    </div>
                    " : "") . "

                    <div style='text-align: center; margin-top: 30px;'>
                        <a href='{$url_detalle}' class='button'>
                            Revisar y Autorizar Pago →
                        </a>
                    </div>

                    <p style='color: #666; font-size: 14px; margin-top: 30px; text-align: center;'>
                        <strong>⚠️ Acción Requerida:</strong><br>
                        Este pago necesita tu autorización para continuar con el flujo de aprobación.
                    </p>
                </div>

                <div class='footer'>
                    <p><strong>TotalGas - Sistema de Gestión de Pagos</strong></p>
                    <p>Este es un correo automático, por favor no responda a este mensaje.</p>
                    <p style='margin-top: 10px; font-size: 11px;'>
                        © " . date('Y') . " TotalGas. Todos los derechos reservados.
                    </p>
                </div>
            </div>
        </body>
        </html>
        ";
    }

    function authorized_pending_invoices()
    {
        try {
            // Obtener facturas autorizadas pendientes
            $invoices = $this->paymentRequestInvoicesModel->get_authorized_pending_invoices();

            // Obtener resumen por banco
            $summary_by_bank = $this->paymentRequestInvoicesModel->get_authorized_pending_summary_by_bank();

            echo $this->twig->render($this->route . 'payment_list.html', compact(
                'invoices',
                'summary_by_bank'
            ));
        } catch (Exception $e) {
            setFlashMessage('error', 'Error al cargar facturas: ' . $e->getMessage());
            redirect('/supply/payment_list');
        }
    }


    function authorized_pending_invoices_grouped_table()
    {
        header('Content-Type: application/json');

        try {
            $invoices = $this->paymentRequestInvoicesModel->get_authorized_pending_grouped();

            if (!$invoices) {
                json_output(['data' => []]);
                return;
            }

            $data = [];
            foreach ($invoices as $invoice) {
                $data[] = [
                    'emp_cod' => $invoice['emp_cod'],
                    'provider_cod' => $invoice['provider_cod'],
                    'empresa_nombre' => $invoice['empresa_nombre'] ?? 'N/A',
                    'empresa_rfc' => $invoice['empresa_rfc'] ?? 'N/A',
                    'proveedor_nombre' => $invoice['proveedor_nombre'] ?? 'N/A',
                    'proveedor_rfc' => $invoice['proveedor_rfc'] ?? 'N/A',
                    'banco_asignado' => $invoice['banco_asignado'],
                    'banco_color' => $invoice['banco_color'],
                    'total_facturas' => $invoice['total_facturas'],
                    'total_autorizado' => $invoice['total_autorizado'],
                    'total_saldo' => $invoice['total_saldo'],
                    'vencimiento_mas_proximo' => $invoice['vencimiento_mas_proximo'],
                    'vencimiento_mas_lejano' => $invoice['vencimiento_mas_lejano'],
                    'invoice_ids' => $invoice['invoice_ids'],
                    'folios_list' => $invoice['folios_list'],
                    'authorized_by_name' => $invoice['authorized_by_name'] ?? 'N/A',
                    'ultima_autorizacion' => $invoice['ultima_autorizacion'],
                    'tipo_registro' => $invoice['tipo_registro'],  // NUEVO
                    'payment_request_id' => $invoice['payment_request_id']  // NUEVO (solo para anticipos)
                ];
            }

            json_output(['data' => $data]);
        } catch (Exception $e) {
            error_log("Error en authorized_pending_invoices_grouped_table: " . $e->getMessage());
            json_output(['data' => [], 'error' => $e->getMessage()]);
        }
    }

    function get_invoices_detail()
    {
        header('Content-Type: application/json');
        try {
            $invoice_ids = $_POST['invoice_ids'] ?? '';
            if (empty($invoice_ids)) {
                json_output(['success' => false, 'message' => 'IDs de facturas requeridos']);
                return;
            }

            // Obtener facturas individuales
            $invoices = $this->paymentRequestInvoicesModel->get_invoices_detail_by_ids($invoice_ids);

            if (!$invoices) {
                json_output(['success' => false, 'message' => 'No se encontraron facturas']);
                return;
            }

            $data = [];
            foreach ($invoices as $invoice) {
                $data[] = [
                    'id' => $invoice['id'],
                    'payment_request_id' => $invoice['payment_request_id'],
                    'folio' => $invoice['folio'],
                    'invoice_number' => $invoice['invoice_number'],
                    'estacion_nombre' => $invoice['estacion_nombre'] ?? 'N/A',
                    'amount' => $invoice['amount'],
                    'paid_amount' => $invoice['paid_amount'] ?? 0,
                    'authorized_amount' => $invoice['authorized_amount'],
                    'saldo' => $invoice['saldo'],
                    'expiration_date' => $invoice['expiration_date'],
                    'authorized_by_name' => $invoice['authorized_by_name'] ?? 'N/A',
                    'authorized_at' => $invoice['authorized_at'],
                    'empresa_nombre' => $invoice['empresa_nombre'] ?? 'N/A',
                    'proveedor_nombre' => $invoice['proveedor_nombre'] ?? 'N/A',
                    'uuid' => $invoice['uuid']
                ];
            }

            json_output(['success' => true, 'data' => $data]);
        } catch (Exception $e) {
            error_log("Error en get_invoices_detail: " . $e->getMessage());
            json_output(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
    }
    public function generate_santander_layout()
    {
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            $invoice_ids = $input['invoice_ids'] ?? [];
            $anticipo_ids = $input['anticipo_ids'] ?? [];


            if (empty($invoice_ids) && empty($anticipo_ids)) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'No se proporcionaron facturas ni anticipos']);
                return;
            }
            $todos_los_datos = [];


            if (!empty($invoice_ids)) {
                $facturas_data = $this->paymentRequestInvoicesModel->get_facturas_para_layout($invoice_ids);
                if ($facturas_data) {
                    $todos_los_datos = array_merge($todos_los_datos, $facturas_data);
                }
            }
            // ✅ OBTENER ANTICIPOS si hay
            if (!empty($anticipo_ids)) {
                $anticipos_data = $this->PaymentRequestsModel->get_anticipos_para_layout($anticipo_ids);
                if ($anticipos_data) {
                    $todos_los_datos = array_merge($todos_los_datos, $anticipos_data);
                }
            }

            if (empty($todos_los_datos)) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'No se encontraron datos válidos para generar el layout']);
                return;
            }

            // ✅ VALIDACIONES
            $sin_cuenta_cargo = [];
            $sin_clabe = [];

            foreach ($todos_los_datos as $pago) {
                if (!$pago['cuenta_cargo_empresa'] || strlen($pago['cuenta_cargo_empresa']) != 11) {
                    $sin_cuenta_cargo[] = "Empresa: {$pago['empresa_nombre']} (emp_cod: {$pago['empresa_cod']})";
                }
                if (!$pago['clabe_beneficiario'] || strlen($pago['clabe_beneficiario']) != 18) {
                    $tipo = $pago['tipo_pago'] ?? 'FACTURA';
                    $referencia = $pago['tipo_pago'] === 'ANTICIPO'
                        ? "Anticipo #{$pago['payment_request_id']}"
                        : "Folio {$pago['folio']}";
                    $sin_clabe[] = "{$referencia} - {$pago['proveedor_nombre']}";
                }
            }

            if (!empty($sin_cuenta_cargo) || !empty($sin_clabe)) {
                $mensaje = '<strong>No se puede generar el layout:</strong><br><br>';
                if (!empty($sin_cuenta_cargo)) {
                    $mensaje .= '<strong class="text-danger">❌ Empresas sin cuenta Santander PROPIA:</strong><br>';
                    $mensaje .= implode('<br>', array_unique($sin_cuenta_cargo)) . '<br><br>';
                }
                if (!empty($sin_clabe)) {
                    $mensaje .= '<strong class="text-warning">⚠️ Proveedores sin cuenta TERCERO:</strong><br>';
                    $mensaje .= implode('<br>', array_unique($sin_clabe)) . '<br><br>';
                }
                $mensaje .= '<small class="text-muted">Configure las cuentas faltantes en el catálogo de cuentas bancarias.</small>';

                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => $mensaje]);
                return;
            }

            // ✅ GENERAR LAYOUT
            $layout_content = $this->generar_layout_santander_multi_empresa(
                $todos_los_datos,
                'SUSANA.PANTOJA@TOTALGAS.COM'
            );

            // ✅ GENERAR NOMBRE DE ARCHIVO
            $empresas_unicas = array_unique(array_column($todos_los_datos, 'empresa_nombre'));
            $empresa_label = count($empresas_unicas) === 1
                ? $empresas_unicas[0]
                : 'MULTI_EMPRESAS';

            $filename = 'LAYOUT_SANTANDER_' . str_replace(' ', '_', $empresa_label) . '_' . date('YmdHis') . '.txt';

            // ✅ ENVIAR ARCHIVO
            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($layout_content));
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
            echo $layout_content;
            exit;
        } catch (Exception $e) {
            error_log('Error en generate_santander_layout: ' . $e->getMessage());
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Error al generar layout: ' . $e->getMessage()]);
            exit;
        }
    }

    private function generar_layout_santander_multi_empresa($pagos, $email_notificacion)
    {
        $lineas = [];
        $consolidados = [];
        foreach ($pagos as $pago) {
            $key = $pago['cuenta_cargo_empresa'] . '|' . $pago['proveedor_codigo'];

            if (!isset($consolidados[$key])) {
                $consolidados[$key] = [
                    'cuenta_cargo' => $pago['cuenta_cargo_empresa'],
                    'clabe_beneficiario' => $pago['clabe_beneficiario'],
                    'titular_beneficiario' => $pago['titular_beneficiario'] ?: $pago['proveedor_nombre'],
                    'proveedor_nombre' => $pago['proveedor_nombre'],
                    'proveedor_codigo' => $pago['proveedor_codigo'],
                    'monto_total' => 0,
                    'referencias' => [],
                    'es_anticipo' => false
                ];
            }
            $consolidados[$key]['monto_total'] += floatval($pago['monto_autorizado']);
            // ✅ Manejar referencias según tipo
            if ($pago['tipo_pago'] === 'ANTICIPO') {
                $consolidados[$key]['referencias'][] = $pago['folio']; // "ANTICIPO #55"
                $consolidados[$key]['es_anticipo'] = true;
            } else {
                $consolidados[$key]['referencias'][] = $pago['invoice_number'] ?? $pago['folio'];
            }
        }
        // ✅ GENERAR LÍNEAS
        foreach ($consolidados as $grupo) {
            $codigo_banco = $this->obtener_codigo_banco_desde_clabe($grupo['clabe_beneficiario']);
            $monto_centavos = intval($grupo['monto_total'] * 100);
            $monto_con_plaza = str_pad($monto_centavos, 19, '0', STR_PAD_LEFT) . '901';
            $nombre_beneficiario = $this->limpiar_texto_layout($grupo['titular_beneficiario'], 40);

            // ✅ Concepto adaptado
            $cantidad_refs = count($grupo['referencias']);
            $primera_ref = $grupo['referencias'][0];

            if ($grupo['es_anticipo']) {
                // Para anticipos: "ANTICIPO #55 NOMBRE PROVEEDOR"
                $concepto_texto = $primera_ref . ' ' . $grupo['proveedor_nombre'];
            } else if ($cantidad_refs === 1) {
                $concepto_texto = $primera_ref . ' ' . $grupo['proveedor_nombre'];
            } else {
                $concepto_texto = 'C' . $primera_ref . ' ' . $grupo['proveedor_nombre'];
            }

            $concepto = $this->limpiar_texto_layout($concepto_texto, 40);

            $linea = sprintf(
                "LTX05 %-11s       %-18s %-5s%-40s    1234%s  %-40s 00 00  %-28s",
                $grupo['cuenta_cargo'],
                $grupo['clabe_beneficiario'],
                $codigo_banco,
                $nombre_beneficiario,
                $monto_con_plaza,
                $concepto,
                substr($email_notificacion, 0, 28)
            );

            $lineas[] = $linea;
        }

        return implode("\r\n", $lineas);
    }

    private function obtener_codigo_banco_desde_clabe($clabe)
    {
        $codigo_banco_clabe = substr($clabe, 0, 3);

        $mapeo_bancos = [
            '002' => 'BANCO', // Banxico
            '006' => 'BCEXT', // Bancomext
            '009' => 'BOBRA', // Banobras
            '012' => 'BACOM', // BBVA México
            '014' => 'BANME', // Santander
            '019' => 'BEJER', // Banjercito
            '021' => 'BITAL', // HSBC
            '030' => 'BAJIO', // Bajío
            '036' => 'BINBU', // Inbursa
            '042' => 'MIFEL', // Banca Mifel
            '044' => 'COMER', // Scotia Bank
            '058' => 'BANRE', // Banregio
            '059' => 'BINVE', // Invex
            '060' => 'BANSI', // Bansi
            '062' => 'BAFIR', // Afirme
            '072' => 'BBANO', // Banorte
            '106' => 'BAMSA', // Bank of America
            '108' => 'MUFG',  // MUFG Bank
            '110' => 'CHASE', // JP Morgan
            '112' => 'CMCA',  // Bmonex
            '113' => 'DRESD', // Ve por Mas
            '124' => 'DEUTB', // CBM Banco
            '127' => 'BAZTE', // Azteca
            '128' => 'BAUTO', // Banco Autofin
            '129' => 'BARCL', // Barclays
            '130' => 'BCOMP', // Compartamos
            '132' => 'MULTI', // Multiva
            '133' => 'PRUDE', // Actinver
            '136' => 'REGIO', // Intercam
            '137' => 'COPEL', // Bancoppel
            '138' => 'AMIGO', // ABC Capital
            '140' => 'FACIL', // Consubanco
            '141' => 'VOLKS', // Volkswagen
            '143' => 'CONSU', // CI Banco
            '145' => 'BBASE', // Bbase
            '147' => 'AGROF', // Bankaool
            '148' => 'PTODO', // Pagatodo
            '150' => 'INMOB', // Inmobiliario
            '151' => 'DONDE', // Donde
            '152' => 'BCREA', // Bancrea
            '154' => 'COVAL', // Covalto
            '155' => 'ICBCH', // ICBC
            '156' => 'SABAD', // Sabadell
            '157' => 'SHINH', // Shinhan
            '158' => 'MISUO', // Mizuho
            '159' => 'BOCHI', // Bank of China
            '160' => 'BCOS3', // Banco S3
            '166' => 'BANSE', // Bansefi
            '168' => 'HIFED', // Hipotecaria Federal
        ];

        return $mapeo_bancos[$codigo_banco_clabe] ?? 'BACOM';
    }

    private function limpiar_texto_layout($texto, $max_length)
    {
        $texto = strtoupper($texto);
        $texto = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto);
        $texto = preg_replace('/[^A-Z0-9 ]/', '', $texto);
        $texto = preg_replace('/\s+/', ' ', trim($texto));
        return substr($texto, 0, $max_length);
    }

    public function generate_banorte_layout()
    {
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            $invoice_ids = $input['invoice_ids'] ?? [];
            $anticipo_ids = $input['anticipo_ids'] ?? [];

            if (empty($invoice_ids) && empty($anticipo_ids)) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'No se proporcionaron facturas ni anticipos']);
                return;
            }

            $todos_los_datos = [];

            // ✅ Obtener facturas
            if (!empty($invoice_ids)) {
                $facturas_data = $this->paymentRequestInvoicesModel->get_facturas_para_layout($invoice_ids);
                if ($facturas_data) {
                    $todos_los_datos = array_merge($todos_los_datos, $facturas_data);
                }
            }

            // ✅ Obtener anticipos
            if (!empty($anticipo_ids)) {
                $anticipos_data = $this->PaymentRequestsModel->get_anticipos_para_layout($anticipo_ids);
                if ($anticipos_data) {
                    $todos_los_datos = array_merge($todos_los_datos, $anticipos_data);
                }
            }

            if (empty($todos_los_datos)) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'No se encontraron datos válidos para generar el layout']);
                return;
            }

            // ✅ VALIDACIONES
            $sin_cuenta_cargo = [];
            $sin_cuenta_abono = [];


            foreach ($todos_los_datos as $pago) {
                // Validar cuenta cargo de la empresa (debe ser de 10 dígitos)
                if (!$pago['cuenta_cargo_banorte'] || strlen($pago['cuenta_cargo_banorte']) != 10) {
                    $sin_cuenta_cargo[] = "Empresa: {$pago['empresa_nombre']} (emp_cod: {$pago['empresa_cod']})";
                }

                // Validar cuenta abono del proveedor (puede ser CLABE de 18 o cuenta de 10)
                if (!$pago['clabe_beneficiario']) {
                    $tipo = $pago['tipo_pago'] ?? 'FACTURA';
                    $referencia = $pago['tipo_pago'] === 'ANTICIPO'
                        ? "Anticipo #{$pago['payment_request_id']}"
                        : "Folio {$pago['folio']}";
                    $sin_cuenta_abono[] = "{$referencia} - {$pago['proveedor_nombre']}";
                } else {
                    // Si es CLABE de 18, validar que se pueda extraer cuenta de 10
                    $longitud = strlen($pago['clabe_beneficiario']);
                    if ($longitud != 10 && $longitud != 18) {
                        $tipo = $pago['tipo_pago'] ?? 'FACTURA';
                        $referencia = $pago['tipo_pago'] === 'ANTICIPO'
                            ? "Anticipo #{$pago['payment_request_id']}"
                            : "Folio {$pago['folio']}";
                        $sin_cuenta_abono[] = "{$referencia} - {$pago['proveedor_nombre']} (cuenta inválida: {$longitud} dígitos)";
                    }
                }
            }

            if (!empty($sin_cuenta_cargo) || !empty($sin_cuenta_abono)) {
                $mensaje = '<strong>No se puede generar el layout de Banorte:</strong><br><br>';
                if (!empty($sin_cuenta_cargo)) {
                    $mensaje .= '<strong class="text-danger">❌ Empresas sin cuenta Banorte PROPIA (10 dígitos):</strong><br>';
                    $mensaje .= implode('<br>', array_unique($sin_cuenta_cargo)) . '<br><br>';
                }
                if (!empty($sin_cuenta_abono)) {
                    $mensaje .= '<strong class="text-warning">⚠️ Proveedores sin cuenta válida:</strong><br>';
                    $mensaje .= implode('<br>', array_unique($sin_cuenta_abono)) . '<br><br>';
                }
                $mensaje .= '<small class="text-muted">Configure las cuentas faltantes en el catálogo de cuentas bancarias.</small>';

                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => $mensaje]);
                return;
            }

            // ✅ GENERAR LAYOUT
            $layout_content = $this->generar_layout_banorte_multi_empresa($todos_los_datos);

            // ✅ GENERAR NOMBRE DE ARCHIVO
            $empresas_unicas = array_unique(array_column($todos_los_datos, 'empresa_nombre'));
            $empresa_label = count($empresas_unicas) === 1
                ? $empresas_unicas[0]
                : 'MULTI_EMPRESAS';

            $filename = 'LAYOUT_BANORTE_' . str_replace(' ', '_', $empresa_label) . '_' . date('YmdHis') . '.txt';

            // ✅ ENVIAR ARCHIVO
            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($layout_content));
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
            echo $layout_content;
            exit;
        } catch (Exception $e) {
            error_log('Error en generate_banorte_layout: ' . $e->getMessage());
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Error al generar layout: ' . $e->getMessage()]);
            exit;
        }
    }

    private function generar_layout_banorte_multi_empresa($pagos)
    {
        $lineas = [];
        $consolidados = [];

        // ✅ Consolidar por cuenta cargo + proveedor
        foreach ($pagos as $pago) {
            $key = $pago['cuenta_cargo_banorte'] . '|' . $pago['proveedor_codigo'];

            if (!isset($consolidados[$key])) {
                // ✅ Obtener cuenta de 10 dígitos del beneficiario
                $cuenta_abono = $this->extraer_cuenta_banorte($pago['clabe_beneficiario']);

                $consolidados[$key] = [
                    'cuenta_cargo' => $pago['cuenta_cargo_banorte'],
                    'cuenta_abono' => $cuenta_abono,
                    'proveedor_nombre' => $pago['proveedor_nombre'],
                    'proveedor_codigo' => $pago['proveedor_codigo'],
                    'monto_total' => 0,
                    'referencias' => [],
                    'es_anticipo' => false
                ];
            }

            $consolidados[$key]['monto_total'] += floatval($pago['monto_autorizado']);

            // ✅ Manejar referencias según tipo
            if ($pago['tipo_pago'] === 'ANTICIPO') {
                $consolidados[$key]['referencias'][] = $pago['folio']; // "ANTICIPO #55"
                $consolidados[$key]['es_anticipo'] = true;
            } else {
                $consolidados[$key]['referencias'][] = $pago['invoice_number'] ?? $pago['folio'];
            }
        }

        // ✅ GENERAR LÍNEAS
        $fecha_operacion = date('dmY'); // Formato DDMMAAAA

        foreach ($consolidados as $grupo) {
            // Monto en centavos (sin decimales)
            $monto_centavos = intval($grupo['monto_total'] * 100);

            // ✅ Concepto adaptado
            $cantidad_refs = count($grupo['referencias']);
            $primera_ref = $grupo['referencias'][0];

            if ($grupo['es_anticipo']) {
                $concepto_texto = $primera_ref . ' ' . $grupo['proveedor_nombre'];
            } else if ($cantidad_refs === 1) {
                $concepto_texto = $primera_ref . ' ' . $grupo['proveedor_nombre'];
            } else {
                $concepto_texto = 'C' . $primera_ref . ' ' . $grupo['proveedor_nombre'];
            }

            $concepto = $this->limpiar_texto_layout($concepto_texto, 30);

            // ✅ Formato Banorte:
            // 01[TAB][TAB]cuenta_cargo[TAB]cuenta_abono[TAB]monto[TAB]19[TAB]concepto[TAB][TAB]0[TAB]fecha[TAB]concepto
            $linea = sprintf(
                "01\t\t%s\t%s\t%d\t19\t%s\t\t0\t%s\t%s",
                $grupo['cuenta_cargo'],
                $grupo['cuenta_abono'],
                $monto_centavos,
                $concepto,
                $fecha_operacion,
                $concepto
            );

            $lineas[] = $linea;
        }

        return implode("\r\n", $lineas);
    }

    private function extraer_cuenta_banorte($clabe_o_cuenta)
    {
        $longitud = strlen($clabe_o_cuenta);

        if ($longitud == 18) {
            // Es CLABE, extraer los 10 dígitos centrales (posición 3 a 12)
            return substr($clabe_o_cuenta, 3, 10);
        } else if ($longitud == 10) {
            // Ya es cuenta de 10 dígitos
            return $clabe_o_cuenta;
        }

        // Si no es ninguno de los dos, devolver tal cual (fallará en validación)
        return $clabe_o_cuenta;
    }

    public function get_pending_counts_all()
    {
        header('Content-Type: application/json');

        try {
            $userPermissions = $_SESSION['tg_user']['permissions'] ?? '';

            // Convertir a array si es string
            if (is_string($userPermissions)) {
                $userPermissions = explode(',', $userPermissions);
                $userPermissions = array_map('trim', $userPermissions);
                $userPermissions = array_map('intval', $userPermissions);
            }

            // Obtener contadores para cada nivel
            $countAbastos = $this->PaymentRequestsModel->getPendingAuthorizationCount(66);
            $countAdmin = $this->PaymentRequestsModel->getPendingAuthorizationCount(67);
            $countTesoreria = $this->PaymentRequestsModel->getPendingAuthorizationCount(68);
            echo json_encode([
                'success' => true,
                'abastos' => $countAbastos,
                'admin' => $countAdmin,
                'tesoreria' => $countTesoreria,
                'user_permissions' => $userPermissions
            ]);
        } catch (Exception $e) {
            error_log("Error en getPendingCountsAll: " . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'Error al obtener contadores'
            ]);
        }
    }


    public function get_pending_bulk_authorization()
    {
        header('Content-Type: application/json');

        try {
            // Obtener el nivel solicitado desde la petición
            $permissionNumber = isset($_GET['permission']) ? intval($_GET['permission']) : null;

            if (!$permissionNumber || !in_array($permissionNumber, [66, 67, 68])) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Nivel de autorización inválido'
                ]);
                return;
            }

            // Verificar que el usuario tiene ese permiso
            $userPermissions = $_SESSION['tg_user']['permissions'] ?? '';
            if (is_string($userPermissions)) {
                $userPermissions = explode(',', $userPermissions);
                $userPermissions = array_map('trim', $userPermissions);
                $userPermissions = array_map('intval', $userPermissions);
            }
            if (!in_array($permissionNumber, $userPermissions)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'No tienes permisos para este nivel de autorización'
                ]);
                return;
            }

            $pagosPendientes = $this->PaymentRequestsModel->getPendingPaymentsForBulkAuthorization($permissionNumber);
            echo json_encode([
                'success' => true,
                'data' => $pagosPendientes ?: [],
                'nivel_autorizacion' => $this->getNombrePermiso($permissionNumber),
                'permission_number' => $permissionNumber
            ]);
        } catch (Exception $e) {
            error_log("Error en getPendingBulkAuthorization: " . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'Error al obtener pagos pendientes: ' . $e->getMessage()
            ]);
        }
    }
    public function process_bulk_authorization()
    {
        header('Content-Type: application/json');
        try {

            // Validar datos de entrada
            $paymentIds = $_POST['payment_ids'] ?? [];
            $permissionNumber = isset($_POST['permission_number']) ? intval($_POST['permission_number']) : null;
            $comentario = $_POST['comentario'] ?? '';

            if (empty($paymentIds) || !is_array($paymentIds)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'No se recibieron pagos para aprobar'
                ]);
                return;
            }

            if (!$permissionNumber || !in_array($permissionNumber, [66, 67, 68])) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Nivel de autorización inválido'
                ]);
                return;
            }

            // Limpiar y validar IDs
            $paymentIds = array_map('intval', $paymentIds);
            $paymentIds = array_filter($paymentIds, function ($id) {
                return $id > 0;
            });

            if (empty($paymentIds)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'IDs de pago inválidos'
                ]);
                return;
            }


            // Obtener información del usuario
            $userId = $_SESSION['tg_user']['Id'] ?? 0;
            $userName = $_SESSION['tg_user']['name'] ?? 'Unknown';
            $userPermissions = $_SESSION['tg_user']['permissions'] ?? '';

            // Convertir a array si es string
            if (is_string($userPermissions)) {
                $userPermissions = explode(',', $userPermissions);
                $userPermissions = array_map('trim', $userPermissions);
                $userPermissions = array_map('intval', $userPermissions);
            }

            // Verificar que el usuario tiene el permiso solicitado
            if (!in_array($permissionNumber, $userPermissions)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'No tienes permisos para autorizar en este nivel'
                ]);
                return;
            }

            // Procesar aprobación masiva
            $resultado = $this->PaymentRequestsModel->processBulkAuthorization(
                $paymentIds,
                $permissionNumber,
                $userId,
                $userName,
                $comentario
            );
            if ($resultado['success']) {
                // Enviar notificaciones si es necesario
                $this->enviarNotificacionesAprobacionMasiva(
                    $resultado['bulk_id'],
                    $paymentIds,
                    $permissionNumber
                );

                echo json_encode([
                    'success' => true,
                    'message' => 'Aprobación masiva completada exitosamente',
                    'resumen' => [
                        'aprobados' => $resultado['aprobados'],
                        'errores' => $resultado['errores'],
                        'monto_total' => number_format($resultado['monto_total'], 2),
                        'bulk_id' => $resultado['bulk_id']
                    ]
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => $resultado['message'],
                    'detalles' => $resultado['detalles'] ?? []
                ]);
            }
        } catch (Exception $e) {
            error_log("Error en processBulkAuthorization: " . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'Error al procesar aprobación masiva: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Obtener nombre legible del permiso
     */
    private function getNombrePermiso($permissionNumber)
    {
        $permisos = [
            66 => 'Abastos',
            67 => 'Administración y Finanzas',
            68 => 'Tesorería'
        ];
        return $permisos[$permissionNumber] ?? 'Desconocido';
    }

    /**
     * Enviar notificaciones de aprobación masiva
     */
    private function enviarNotificacionesAprobacionMasiva($bulkId, $paymentIds, $permissionNumber)
    {
        try {
            $paymentModel = new PaymentRequestsModel();
            $detalles = $paymentModel->getBulkAuthorizationDetails($bulkId);

            if (!$detalles) {
                return;
            }

            // Log de la acción
            error_log("Aprobación masiva registrada - ID: {$bulkId}, Usuario: {$detalles['user_name']}, Nivel: {$detalles['nivel_nombre']}, Pagos: " . count($paymentIds));

            // Aquí puedes implementar envío de emails si lo necesitas
            // Ejemplo:
            /*
        $to = $this->getEmailSiguienteNivel($permissionNumber);
        $subject = 'Nueva Aprobación Masiva - ' . $detalles['nivel_nombre'];
        $message = "Se ha realizado una aprobación masiva:\n\n";
        $message .= "Usuario: " . $detalles['user_name'] . "\n";
        $message .= "Nivel: " . $detalles['nivel_nombre'] . "\n";
        $message .= "Pagos aprobados: " . $detalles['approved_count'] . "\n";
        $message .= "Monto total: $" . number_format($detalles['total_amount'], 2) . "\n";
        
        $this->sendEmail($to, $subject, $message);
        */
        } catch (Exception $e) {
            error_log("Error al enviar notificaciones: " . $e->getMessage());
        }
    }

    /**
     * Obtener historial de aprobaciones masivas
     */
    public function getBulkAuthorizationHistory()
    {
        header('Content-Type: application/json');

        try {
            $userId = $_SESSION['tg_user']['id'] ?? 0;
            $permissionNumber = isset($_GET['permission']) ? intval($_GET['permission']) : null;

            $whereClause = "ba.user_id = ?";
            $params = [$userId];

            // Filtrar por nivel si se especifica
            if ($permissionNumber && in_array($permissionNumber, [66, 67, 68])) {
                $whereClause .= " AND ba.authorization_level = ?";
                $params[] = $permissionNumber;
            }

            $query = "
            SELECT 
                ba.*,
                u.Nombre as user_name,
                CASE 
                    WHEN ba.authorization_level = 66 THEN 'Abastos'
                    WHEN ba.authorization_level = 67 THEN 'Administración y Finanzas'
                    WHEN ba.authorization_level = 68 THEN 'Tesorería'
                    ELSE 'Desconocido'
                END as nivel_nombre,
                DATEDIFF(minute, ba.created_at, GETDATE()) as minutos_desde_creacion,
                CASE 
                    WHEN ba.is_undone = 1 THEN 'Deshecha'
                    WHEN ba.processed_at IS NOT NULL THEN 'Completada'
                    ELSE 'En proceso'
                END as estado
            FROM [TG].[dbo].[payment_request_bulk_authorizations] ba
            LEFT JOIN [TG].[dbo].[Usuario] u ON ba.user_id = u.Id
            WHERE $whereClause
            ORDER BY ba.created_at DESC
        ";

            $paymentModel = new PaymentRequestsModel();
            $historial = $paymentModel->sql->select($query, $params);

            echo json_encode([
                'success' => true,
                'data' => $historial ?: []
            ]);
        } catch (Exception $e) {
            error_log("Error en getBulkAuthorizationHistory: " . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'Error al obtener historial'
            ]);
        }
    }


    public function undoBulkAuthorization()
    {
        header('Content-Type: application/json');

        try {
            $bulkId = $_POST['bulk_id'] ?? 0;
            $userId = $_SESSION['tg_user']['id'] ?? 0;

            if (!$bulkId) {
                echo json_encode([
                    'success' => false,
                    'message' => 'ID de aprobación masiva no válido'
                ]);
                return;
            }

            $paymentModel = new PaymentRequestsModel();
            $resultado = $paymentModel->undoBulkAuthorization($bulkId, $userId);

            echo json_encode($resultado);
        } catch (Exception $e) {
            error_log("Error en undoBulkAuthorization: " . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'Error al deshacer aprobación masiva: ' . $e->getMessage()
            ]);
        }
    }
}
