<?php

class Supply{
    public $twig;
    public $route;
    public GasolinerasModel $gasolinerasModel;
    public TanquesModel $tanquesModel;
    public TVariasModel $tvariasModel;
    public PreciosModel $preciosModel;
    public EstacionesModel $estacionesModel;

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
    /**
     * @param $twig
     */
    public function __construct($twig) {
        $this->twig                         = $twig;
        $this->route                        = 'views/supply/';
        $this->gasolinerasModel             = new GasolinerasModel;
        $this->tanquesModel                 = new TanquesModel();
        $this->tvariasModel                 = new TVariasModel();
        $this->preciosModel                 = new PreciosModel();
        $this->estacionesModel              = new EstacionesModel();
        $this->binnaclePricesModel          = new BinnaclePricesModel();
        $this->creProductsByStationsModel   = new CreProductsByStationsModel();
        $this->creProductsModel             = new CreProductsModel();
        $this->creSubProductosModel         = new CreSubProductosModel();
        $this->creSubProductosMarcaModel    = new CreSubProductosMarcaModel();
        $this->xsdReportesVolumenesModel    = new XsdReportesVolumenesModel();
        $this->xsdEstacionServicioVolumenModel = new XsdEstacionServicioVolumenModel();
        $this->xsdEstacionServicioVolumenVendidoInventariosModel = new XsdEstacionServicioVolumenVendidoInventariosModel();
        $this->creSuppliersModel            = new CreSuppliersModel();
        $this->creCarriersModel             = new CreCarriersModel();
        $this->xsdEstacionServicioVolumenCompradoModel = new XsdEstacionServicioVolumenCompradoModel();
        $this->movimientosTanModel            = new MovimientosTanModel();
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

        echo $this->twig->render($this->route . 'fuel_prices.html', compact('stations', 'mensajeFinal', 'mensajeFinal2'));
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
        $fiveDaysAgo = (new DateTime('-5 days'))->format('Y-m-d');

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
        $twigVars = compact('from', 'yesterday', 'fiveDaysAgo', 'companies', 'companyRfc', 'stations', 'suppliers', 'carriers');

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
        $fiveDaysAgo = (new DateTime('-5 days'))->format('Y-m-d');

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
        $twigVars = compact('from', 'yesterday', 'fiveDaysAgo', 'companies', 'companyRfc', 'stations', 'suppliers', 'carriers');

        if (!empty($companyRfc)) {
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
}