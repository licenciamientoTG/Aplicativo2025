<?php

use JetBrains\PhpStorm\NoReturn;

require_once('./_assets/classes/code128.php');

class Operations{
    public $twig;
    public $route;
    public int $todayInt;
    public TabulatorModel $tabulatorModel;
    public TabulatorDetailsModel $tabulatorDatailsModel;
    public IslasModel $islandModel;
    public ResponsablesModel $responsablesModel;
    public AsignacionesModel $assignacionesModel;
    public CotizacionesModel $cotizacionesModel;
    public AnticiposModel $anticiposModel;
    public TabuladorRecolectasModel $tabuladorRecolectasModel;
    public TabuladorDenominacionesModel $tabuladorDenominacionesModel;
    public DespachosModel $despachosModel;
    public LecturasModel $lecturasModel;
    public MedicionModel $medicionModel;
    public ValorButtModel $valorButtModel;
    public MovimientosTarModel $movimientosTarModel;
    public EstacionesModel $estacionesModel;
    public VentasModel $ventasModel;
    public GasolinerasModel $gasolinerasModel;
    public TabulatorHistoryModel $tabulatorHistoryModel;
    public BallotModel $ballotModel;
    public VentasModel $ventas;


    /**
     * @param $twig
     */
    public function __construct($twig) {
        $this->twig                         = $twig;
        $this->route                        = 'views/operations/';
        $this->todayInt                     = (new DateTime())->diff(new DateTime('1900-01-01'))->days + 1;
        $this->tabulatorModel               = new TabulatorModel();
        $this->tabulatorDatailsModel        = new TabulatorDetailsModel();
        $this->islandModel                  = new IslasModel();
        $this->responsablesModel            = new ResponsablesModel();
        $this->assignacionesModel           = new AsignacionesModel();
        $this->cotizacionesModel            = new CotizacionesModel();
        $this->anticiposModel               = new AnticiposModel();
        $this->tabuladorRecolectasModel     = new TabuladorRecolectasModel();
        $this->tabuladorDenominacionesModel = new TabuladorDenominacionesModel();
        $this->despachosModel               = new DespachosModel();
        $this->lecturasModel                = new LecturasModel();
        $this->medicionModel                = new MedicionModel();
        $this->valorButtModel               = new ValorButtModel();
        $this->movimientosTarModel          = new MovimientosTarModel();
        $this->estacionesModel              = new EstacionesModel();
        $this->ventasModel                  = new VentasModel();
        $this->gasolinerasModel             = new GasolinerasModel();
        $this->tabulatorHistoryModel        = new TabulatorHistoryModel();
        $this->ballotModel                  = new BallotModel();
        $this->ventas                       = new VentasModel;

    }

    /**
     * @return void
     */
    public function tabulator() : void {
        // Imprimimos el segmento de red del cliente
        $stations = $this->gasolinerasModel->get_active_stations();
        echo $this->twig->render($this->route . 'index.html', compact('stations'));
    }

    /**
     * @return void
     * @throws Exception
     */
    function datatables_top_tabulators() : void {
        $data = [];
        if ($tabulators = $this->tabulatorModel->all(3000, (isset($_SESSION['tg_user']['IdEstacion']) ? $_SESSION['tg_user']['IdEstacion'] : 0))) {
            $data = array_map(function ($tab) {
                $actions = "
                    <div class='dropdown'>
                        <button class='btn btn-primary btn-sm dropdown-toggle' type='button' data-bs-toggle='dropdown' aria-expanded='false'> Acciones</button>
                        <ul class='dropdown-menu' data-container='body'>
                            <li><a class='dropdown-item' href='/operations/tab_process/{$tab['Id']}'>Procesar</a></li>
                            <li><a class='dropdown-item' href='/operations/consult/{$tab['Id']}'>Consultar</a></li>
                            ";
                            if (authorized(30)) {
                                $actions .= "<li><a class='dropdown-item' href='/operations/tab_delete/{$tab['Id']}'>Eliminar</a></li>";
                            }
                            $actions .= "
                        </ul>
                    </div>
                ";
                // Extraer el turno (primer dígito)
                $turno_numero = substr($tab['Turno'], 0, 1);

                // Extraer el subcorte (segundo dígito)
                $subcorte_numero = substr($tab['Turno'], 1, 1);
                return [
                    'Id'       => $tab['Id'],
                    'Nombre'   => $tab['Nombre'],
                    'Turno'    => $turno_numero,
                    'Subcorte' => $subcorte_numero,
                    'Fecha'    => $tab['FechaTabular'],
                    'Usuario'  => $tab['Usuario'],
                    'Estatus'  => $tab['Estatus'],
                    'Productos'=> 0,
                    'Total'    => $tab['Total'],
                    'Acciones' => $actions
                ];
            }, $tabulators);
        }
        json_output(array("data" => $data));
    }

    /**
     * @return void
     * @throws Exception
     */
    function createTabulatorForm() : void {
        // Obtenemos los datos de la estación
        $station = $this->estacionesModel->get_station($_POST['Estacion']);
        // Obtenemos el tipo de cambio más reciente
        $exchange_now = $this->cotizacionesModel->get_last_exchange($_POST['Estacion']); // Para obtener el tipo de cambio más reciente

        if ($_POST['FechaTabular'] == date('Y-m-d') AND $_POST['Turno'] == '41' ) {
            setFlashMessage('error', 'No es posible crear un tabulador para el turno 4 del día de hoy. Por favor, seleccione otro turno o cambie la fecha tabular');
            redirect();
        }
        // Si logramos crear el tabulador retornamos una respuesta json
        $rs = $this->tabulatorModel->add(intval($_POST['Estacion']), $_POST['FechaTabular'], intval($_POST['Turno']), $_SESSION['tg_user']['Usuario'], $exchange_now, $station['LimiteFajilla'], $station['LimiteRecolecta']);
        switch ($rs) {
            case '0':
                setFlashMessage('error', 'No fue posible crear un nuevo tabulador');
                break;

            case '1':
                setFlashMessage('success', 'El tabulador se creo correctamente');
                break;

            case '2':
                setFlashMessage('warning', 'El tabulador ya existe');
                break;
        }
        redirect();
    }

    /**
     * @param $tabId
     * @return void
     * @throws Exception
     */
    function consult($tabId) : void {
        // Fajillas
        $tabulator = $this->tabulatorModel->get_tabulator($tabId);
        $wads      = $this->tabulatorDatailsModel->get_wads($tabId); // Para obtener las fajillas de un tabulador

        $contadorUSD = 0; // Inicializa el contador para USD en 0
        $contadorMXN = 0; // Inicializa el contador para MXN en 0

        if ($wads) {
            foreach ($wads as $elemento) {
                if ($elemento['Moneda'] === 'USD') {
                    $contadorUSD++;
                } elseif ($elemento['Moneda'] === 'MXN') {
                    $contadorMXN++;
                }
            }
        }
       

        echo $this->twig->render($this->route . 'consult.html', compact('wads', 'tabulator', 'contadorUSD', 'contadorMXN'));
    }

    /**
     * @param $tabId
     * @param bool $island
     * @return void
     * @throws Exception
     */
    function tab_process(int $tabId, $island = false) : void {
        ini_set('memory_limit', '256M');
        ini_set('max_execution_time', 300);
        if (preg_match('/GET/i',$_SERVER['REQUEST_METHOD'])){ // Si el método de envío es GET
            try {
                $blocked_tab = 0;
                if ($_SESSION['tg_user']['Id'] == "6177" || $_SESSION['tg_user']['Id'] == "6296") {
                    $blocked_tab = 0;
                }
                $tabulator = $this->tabulatorModel->get_tabulator($tabId);
                $all_islands = $this->getInitialReadingsByIslands3($tabulator['CodigoEstacion'], dateToInt($tabulator['FechaTabular']), $tabulator['Turno'], $tabulator['Islands'], $tabId);
                $samplings = $this->despachosModel->get_jarreos($tabulator['CodigoEstacion'], dateToInt($tabulator['FechaTabular']), $tabulator['Turno']);
                $islands = $this->islandModel->get_available_islands_by_tab($tabulator['CodigoEstacion'], $tabulator['Id']);
                $responsables = $this->responsablesModel->get_responsables_by_station($tabulator['CodigoEstacion']);
                $assignments = $this->assignacionesModel->get_assignations_by_tabulator($tabulator['CodigoEstacion'], $tabulator['FechaTabular'], $tabulator['Turno']);

                echo $this->twig->render($this->route . 'tab_process.html', compact('tabulator', 'islands', 'responsables', 'assignments', 'samplings', 'island', 'all_islands', 'blocked_tab'));
            } catch (\Throwable $th) {
                setFlashMessage('error', $th->getMessage()); // Mostramos un mensaje de error
                redirect(); // Redireccionamos a la misma página
            }
        } else { // Si el método de envío es POST
            switch ($_POST['action']) { // Dependiendo de la acción que se haya enviado
                case 'assignation': // Si la acción es asignación
                    if ($this->assignacionesModel->assignation($tabId, $_POST['CodigoEstacion'], $_POST['FechaTabular'], $_POST['Turno'], $_POST['island'], $_POST['responsable_id'])) { // Si logramos crear la asignación
                        setFlashMessage('success','Asignación creada correctamente'); // Mostramos un mensaje de éxito
                    } else { // Si no logramos crear la asignación
                        setFlashMessage('error','La asignación no es posible debido a que actualmente tiene personal asignado a esta isla. Elimine la asignación existente antes de proceder a generar una nueva.'); // Mostramos un mensaje de error
                    }
                    redirect(); // Redireccionamos a la misma página
                    break;

                case 'addWad': // Si la acción es agregar fajilla
                    $Total = floatval($_POST['Total']);
                    $params = [
                        'Id'             => $tabId, // TabuladorEncabezadoId
                        'Isla'           => intval($_POST['island_id']), // IslaId
                        'CodigoValor'    => ($_POST['currency'] == 'MXN') ? 6 : ($_POST['currency'] == 'USD' ? 5 : ($_POST['currency'] == 'MRL' ? 192 : 0 )), // 5 para MXN, 6 para USD
                        'Cantidad'       => floatval($_POST['amount']),
                        'Monto'          => floatval($_POST['amount']), // Monto de la fajilla
                        'Moneda'         => $_POST['currency'], // Moneda de la fajilla
                        'TipoCambio'     => floatval($_POST['exchange_now']), // Tipo de cambio al momento de generar la fajilla
                        'Valor'          => (in_array($_POST['currency'], ['MXN', 'MRL'])) ? floatval($_POST['amount']) : ($_POST['currency'] == 'USD' ? floatval($_POST['amount'] * $_POST['exchange_now']) : 0.00), // El mismo que el monto si la moneda es MXN. Si es USD, entonces monto * tipo de cambio
                        'Usuario'        => $_SESSION['tg_user']['Usuario'], // El nombre de usuario de la persona que genera la fajilla
                        'Turno'          => $_POST['Turno'], // El código el turno (11,21,31,41)
                        'CodigoEstacion' => $_POST['CodigoEstacion'] // El Id de la estación donde se realiza la fajilla
                    ];

                    if ($this->tabulatorDatailsModel->add($params)) { // Agregamos la fajilla
                        $totalMXN = $this->tabulatorDatailsModel->getPendingWads($tabId, 'MXN');
                        $totalUSD = $this->tabulatorDatailsModel->getPendingWads($tabId, 'USD');

                        // Vamos a agregar una respuesta donde se envie la isla, y el resultado
                        $json = [
                            'island' => $_POST['island_id'],
                            'result' => 1,
                            'message' => 'Fajilla agregada correctamente',
                            'totalMXN' => (isset($totalMXN['TotalMonto']) ? floatval($totalMXN['TotalMonto']) : 0.00),
                            'totalUSD' => (isset($totalUSD['TotalMonto']) ? floatval($totalUSD['TotalMonto']) : 0.00),
                            'totalTab' => floatval(($Total + $params['Valor'])),
                            'TotalPending' => (floatval($_POST['TotalPending']) + $params['Valor'])
                        ];
                        json_output($json);
                    } else {
                        $json = [
                            'island' => $_POST['island_id'],
                            'result' => 0,
                            'message' => 'No fue posible agregar la fajilla'
                        ];
                        json_output($json);
                    }
                    break;

                case 'addDeposit': // Si la acción es agregar depósito
                    $total = $this->tabulatorDatailsModel->getPendingWads($tabId, $_POST['currency']);

                    // Verificamos si existe un depósito pendiente de cerrar o mas bien activo
                    if ($total == 1) {
                        setFlashMessage('warning', 'Actualmente existe un depósito pendiente de cerrar');
                        redirect();
                    }

                    // Verificamos si existen fajillas pendientes en el tabulador
                    if ($total == 0) {
                        setFlashMessage('error', 'No existen fajillas pendientes en el tabulador');
                        redirect();
                    }

                    // Ahora vamos a crear el depósito
                    // Primero setamos los parámetros de la tabla
                    $params = [
                        'IdRecolecta'   => intval($total['consecutive']),
                        'IdTabulador'   => $tabId,
                        'Descripcion'   => "Depósito #{$total['consecutive']}",
                        'Total'         => $total['TotalValor'],
                        'CodigoValor'   => ($_POST['currency'] == 'MXN') ? 6 : ($_POST['currency'] == 'USD' ? 5 : 0),
                    ];
                    if (!$this->tabuladorRecolectasModel->add($params)) {
                        setFlashMessage('error', 'No fue posible realizar el depósito');
                        redirect();
                    }

                    // Si todo sale bien, generamos un mensaje flash de éxito y redireccionamos a la misma página
                    setFlashMessage('success', 'El depósito se abrió correctamente');
                    redirect();
                    break;

                default: // Si la acción no es ninguna de las anteriores
                    if (preg_match('/POST/i',$_SERVER['REQUEST_METHOD'])){
                        echo '<pre>';
                        var_dump($_POST);
                        die();
                    }
                    break;
            }
        }
    }

    function addWadSales ($tabId) {
        $params = [
            'Id'             => $tabId, // TabuladorEncabezadoId
            'Isla'           => intval($_POST['island_id']), // IslaId
            'CodigoValor'    => ($_POST['currency'] == 'MXN') ? 6 : ($_POST['currency'] == 'USD' ? 5 : ($_POST['currency'] == 'MRL' ? 192 : 0 )), // 5 para MXN, 6 para USD
            'Cantidad'       => floatval($_POST['amount']),
            'Monto'          => floatval($_POST['amount']), // Monto de la fajilla
            'Moneda'         => $_POST['currency'], // Moneda de la fajilla
            'TipoCambio'     => floatval($_POST['exchange_now']), // Tipo de cambio al momento de generar la fajilla
            'Valor'          => (in_array($_POST['currency'], ['MXN', 'MRL'])) ? floatval($_POST['amount']) : ($_POST['currency'] == 'USD' ? floatval($_POST['amount'] * $_POST['exchange_now']) : 0.00), // El mismo que el monto si la moneda es MXN. Si es USD, entonces monto * tipo de cambio
            'Usuario'        => $_SESSION['tg_user']['Usuario'], // El nombre de usuario de la persona que genera la fajilla
            'Turno'          => $_POST['Turno'], // El código el turno (11,21,31,41)
            'CodigoEstacion' => $_POST['CodigoEstacion'] // El Id de la estación donde se realiza la fajilla
        ];

        if ($this->tabulatorDatailsModel->add($params)) { // Agregamos la fajilla
            // Vamos a agregar un flash message de éxito
            setFlashMessage('success','Fajilla agregada correctamente');
            redirect('/operations/tab_process/' . $tabId . '/' . $_POST['island_id']);
        }

        echo '<pre>';
        var_dump("No fue posible agregar fajilla");
        die();
    }

    function addWadSales2($tabId, $island) {
        
        if (isset($_POST['responsable_id'])) {
            // Vamos a asignar responsable a la isla
            $this->assignacionesModel->assignation($_POST['tabId'], $_POST['CodigoEstacion'], $_POST['FechaTabular'], $_POST['Turno'], $_POST['codisl'], $_POST['responsable_id']);
        }

        // Primero verificamos si la isla tiene responsables asignados
        if (!$this->assignacionesModel->get_assignation_by_island($_POST['CodigoEstacion'], $_POST['FechaTabular'], $_POST['Turno'], $island)) {
            json_output(['result' => 0, 'message' => 'No hay responsables asignados a la isla']);
        }

        // Seteamos las variables
        $codigoValor = ($_POST['currency'] == 'MXN') ? 6 : ($_POST['currency'] == 'USD' ? 5 : ($_POST['currency'] == 'MRL' ? 192 : 0 ));
        $valor = (in_array($_POST['currency'], ['MXN', 'MRL'])) ? floatval($_POST['amount']) : ($_POST['currency'] == 'USD' ? floatval($_POST['amount'] * $_POST['exchange_now']) : 0.00); // El mismo que el monto si la moneda es MXN. Si es USD, entonces monto * tipo de cambio
        $efectivo = ((in_array($codigoValor, [6, 192])) ? 1 : 0);
        $params = [
            intval($tabId), intval($island), intval($codigoValor), floatval($_POST['amount']), floatval($_POST['amount']),
            $_POST['currency'], floatval($_POST['exchange_now']), floatval($valor), $_SESSION['tg_user']['Usuario'], intval($efectivo),
            intval($_POST['FechaTabular']), $_POST['DBString'], intval($_POST['Turno']), intval($_SESSION['tg_user']['Id']), dateToInt($_POST['FechaTabular'])
        ];

        if ($this->tabulatorDatailsModel->insertIntoTabuladorDetalle2($params)) {
            json_output(['result' => 1, 'message' => 'Fajilla agregada correctamente']);
        } else {
            json_output(['result' => 0, 'message' => 'No fue posible agregar la fajilla']);
        }
    }

    function get_sales_in_isle($isle, $tabId, $turno, $fechatabular, $codigoestacion, $limiteFajilla, $islands) {
        $totals = $this->tabulatorModel->get_totals_comparison($tabId, $codigoestacion, dateToInt($fechatabular), $turno, $islands);

        $ventas = "";
        $valores  = $this->movimientosTarModel->get_total_islands(dateToInt($fechatabular), $codigoestacion, $isle, intval($turno), $tabId);
        $readings = $this->getInitialReadingsByIslands($codigoestacion, dateToInt($fechatabular), $turno, $isle);

        $ventas .= "<h5 class=\"card-title\">Ventas totales - {$valores[0]['Isla']}</h5>";
        $ventas .= "<table class=\"table table-sm table-striped table-hover table-values\" style=\"font-size: x-small\">";
        $ventas .= "<thead><tr><th>BOMBA</th><th>PRODUCTO</th><th>INICIAL</th><th>FINAL</th><th>VENDIDO</th><th>IMPORTE</th></tr></thead><tbody>";
        $total_sales = 0;
        foreach ($readings as $row) {
            $total_sales = ($total_sales + $row['finalAmount']);
            $ventas .= "<tr>
                        <td class=\"text-nowrap\">{$row['nrobom']}</td>
                        <td class=\"text-nowrap\">{$row['Producto']}</td>
                        <td class=\"text-nowrap\">". number_format($row['initialElectronicReading'], 3, '.', ',')."</td>
                        <td class=\"text-nowrap\">". number_format(($row['initialElectronicReading'] + $row['finalElectronicReading']), 3, '.', ',') ."</td>
                        <td class=\"text-nowrap\">". number_format($row['finalElectronicReading'], 3, '.',',')."</td>
                        <td class=\"text-nowrap\">$ " . number_format($row['finalAmount'], 2) . "</td>
                    </tr>";
        }
        $ventas .= "</tbody><tfoot><tr><th colspan=\"5\" class=\"text-end\">TOTAL</th><th class=\"text-nowrap\">$ ". number_format($total_sales, 2, '.', ',')."</th></tr></tfoot></table>";

        $total_amounts = 0;
        $sales = "<h5 class=\"card-title\">Formas de pago - {$valores[0]['Isla']}</h5><table class=\"table table-sm table-striped table-hover table-payments table1\" style=\"font-size: x-small\"><thead><tr><th>FORMA PAGO</th><th>MONTO</th></tr></thead><tbody>";
        foreach ($valores as $row) {
            $total_amounts = ($total_amounts + $row['Total']);
            $sales .= "<tr><td class=\"text-nowrap\">{$row['ValorDescripcion']}</td><td class=\"text-nowrap\">$ " . number_format($row['Total'], 2) . "</td></tr>";
        }
        $diff = $total_amounts - $total_sales;

        $sales .= "</tbody>";
        $sales .= "<tfoot
                        <tr>
                            <td class=\"text-end\">TOTAL</td>
                            <td class=\"text-nowrap\">$ ". number_format($total_amounts, 2) ."</td>
                        </tr>
                        <tr>
                            <th class=\"text-end\">Dif. Contado x Acreditar</th>
                            <th class=\"text-nowrap diff ". ($diff != 0 ? 'text-danger' : '' ) ."\">$ ". number_format($diff, 2, '.', ',') ."</th>
                        </tr>
                    </tfoot></table>";

        $calculo = number_format(($totals['total_ventas'] - $totals['total_ingresado']), 2, '.', ',');
        $diff_tab_span = "Diferencia: $" . $calculo;
        json_output(['sales' => $sales, 'ventas' => $ventas, 'diff_tab_span' => $diff_tab_span, 'difference' => $diff, 'limiteFajilla' => $limiteFajilla]);
    }

    function sales_tab() : void {

        $tabId          = $_GET['tabId'];
        $status         = $_GET['estatus'];
        $codigoestacion = $_GET['codigoestacion'];
        $fechatabular   = $_GET['fechatabular'];
        $turno          = $_GET['turno'];
        $islands        = $_GET['islands'];
        $LimiteFajilla  = $_GET['LimiteFajilla'];
        $Total          = $_GET['Total'];
        $exchange_now   = $_GET['exchange_now'];
        $TotalPending   = $_GET['TotalPending'];
        $DBString       = $_GET['DBString'];

        $valores  = $this->movimientosTarModel->get_total_islands(dateToInt($fechatabular), $codigoestacion, $islands, $turno, $_GET['tabId']);


        $valoresPorIsla = array_reduce($valores, function($grupos, $resultado) {
            $codisl = $resultado['codisl'];
            $grupos[$codisl][] = $resultado;
            return $grupos;
        }, []);
        
        if ($readings = $this->getInitialReadingsByIslands($codigoestacion, dateToInt($fechatabular), $turno, $islands)) {
            // if ($_SESSION['tg_user']['Id'] == "6296") {
            //     echo '<pre>';
            //     var_dump($readings);
            //     die();
            // }

            $lecturasPorIsla = array_reduce($readings, function($grupos, $resultado) {
                $codisl = $resultado['codisl'];
                $grupos[$codisl][] = $resultado;
                return $grupos;
            }, []);
           

            $json = [
                'html'  => $this->twig->render($this->route . 'salesTab.html', compact('tabId', 'codigoestacion', 'status', 'fechatabular', 'valoresPorIsla', 'lecturasPorIsla', 'LimiteFajilla', 'Total', 'turno', 'exchange_now', 'TotalPending', 'DBString', 'islands'))
            ];
        }
       
        json_output($json);

    }

    /**
     * @param $tabId
     * @return void
     * @throws Exception
     */
    function tab_delete($tabId) : void {
        $tabulator = $this->tabulatorModel->get_tabulator($tabId);
        // Primero verificamos que el tabulador no tenga asignaciones
        if ($this->assignacionesModel->get_assignations_by_tabulator($tabulator['CodigoEstacion'], $tabulator['FechaTabular'], $tabulator['Turno'])) {
            setFlashMessage('error','La eliminación del tabulador no es posible debido a que actualmente tiene personal asignado a las islas. Por favor, proceda a eliminar las asignaciones asociadas antes de intentar eliminar el tabulador.');
        } else {
            // Ahora verificamos si tiene fajillas asignadas
            if ($this->tabulatorDatailsModel->get_wads($tabId)) {
                setFlashMessage('error','No se puede eliminar el tabulador porque tiene fajillas asignadas. Elimine las fajillas primero');
            } else {
                // Si no tiene asignaciones ni fajillas asignadas, entonces lo eliminamos
                if ($this->deleteTabulator($tabId)) {
                    setFlashMessage('success','Tabulador eliminado correctamente');
                } else {
                    setFlashMessage('error','No se pudo eliminar el tabulador');
                }
            }
        }
        redirect();
    }

    /**
     * @param $tabId
     * @return bool
     * @throws Exception
     */
    private function deleteTabulator($tabId) : bool {
        return $this->tabulatorModel->delete_tabulator($tabId);
    }

    /**
     * @param $Gasolinera
     * @param $Fecha
     * @param $Turno
     * @param $Isla
     * @param $Responsable
     * @param $IdTabulador
     * @return void
     * @throws Exception
     */
    function delete_assignment($Gasolinera, $Fecha, $Turno, $Isla, $Responsable, $IdTabulador) : void {
        // Primero verificamos si el responsable tiene fajillas a su nombre
        if ($this->tabulatorDatailsModel->get_wads_by_responsable($Responsable, $IdTabulador)) {
            setFlashMessage('error','No se puede eliminar la asignación porque el responsable tiene fajillas a su nombre. Elimine las fajillas primero');
            redirect();
        }
        // Si el responsable no tiene fajillas a su nombre, entonces eliminamos la asignación
        if ($this->assignacionesModel->delete_assignment($Gasolinera,$Fecha,$Turno,$Isla)) {
            setFlashMessage('success','Asignación eliminada correctamente');
        } else {
            setFlashMessage('error','No se pudo eliminar la asignación');
        }
        redirect();
    }

    /**
     * @return void
     * @throws Exception
     */
    function wads() : void {
        $tabulator = $this->tabulatorModel->get_tabulator($_GET['tabId']); // Para obtener los datos del tabulador
        // $wads = $this->tabulatorDatailsModel->get_wads($_GET['tabId']); // Para obtener las fajillas de un tabulador
        $islands = $this->islandModel->get_wads_islands($_GET['tabId']); // Para obtener las islas de una estación

        $island = $_GET['island'] ?? false;

        $islands_wads = $this->tabulatorDatailsModel->get_wads_by_island($_GET['tabId']);

        $exchange_now = $tabulator['TipoCambio']; // Para obtener el tipo de cambio más reciente
        // Estas lineas son para obtener las fajillas pendientes de depositar
        $totalMXN = $tabulator['TotalPendingMXN'];
        $totalUSD = ($tabulator['TotalPendingUSD'] / $exchange_now);

        $json = [
            'html'  => $this->twig->render($this->route . 'wadsTab.html', compact('tabulator', 'islands', 'exchange_now', 'island', 'islands_wads', 'totalMXN', 'totalUSD'))
        ];

        json_output($json);
    }

    function datatables_wads_tab() : void {
        if (preg_match('/GET/i',$_SERVER['REQUEST_METHOD'])) {
            $tabId = $_GET['tabId'];
            $wads = $this->tabulatorDatailsModel->get_wads($_GET['tabId']); // Para obtener las fajillas de un tabulador
            $data = [];
            if ($wads) {
                foreach ($wads as $key => $wad) {
                    $actions = "
                        <div class='dropdown'>
                            <button class='btn btn-primary btn-sm dropdown-toggle' type='button' data-bs-toggle='dropdown' aria-expanded='false'> Acciones</button>
                            <ul class='dropdown-menu' data-container='body'>";
                    if ($wad['IdRecolecta'] > 0) {
                        $actions .= '
                            <li><a class="dropdown-item" href="javascript:void(0);" onclick="toastr.warning(\'Esta fajilla ya fue depositada. No puede editar información\', \'¡Atención!\', { timeOut: 2000 });">Editar</a></li>
                            <li><a class="dropdown-item" href="javascript:void(0);" onclick="toastr.warning(\'Esta fajilla ya fue depositada. No puede eliminarla\', \'¡Atención!\', { timeOut: 2000 });">Eliminar</a></li>
                        ';
                    } else {
                        $actions .= '
                            <li><a class="dropdown-item" href="javascript:void(0);" data-bs-toggle="modal" data-bs-target="#editWadModal" data-tabId="'. $tabId .'" data-secuencial="'. $wad['Secuencial'] .'">Editar</a></li>
                            <li><a class="dropdown-item" href="javascript:void(0);" onclick="confirmDelete(\'' .$wad['Id'] . '\' , \'' . $wad['Secuencial'] . '\')">Eliminar</a></li>
                        ';
                    }
                    $actions .= "        </ul>
                        </div>
                    ";


                    $data[] = [
                        'SEC'         => $key + 1,
                        'RESPONSABLE' => $wad['Responsable'],
                        'ISLA'        => $wad['Isla'],
                        'MONEDA'      => ($wad['Moneda'] == 'MRL' ? 'MORRALLA' : $wad['Moneda']),
                        'MONTO'       => $wad['Monto'],
                        'CAMBIO'      => $wad['TipoCambio'],
                        'TOTAL'       => $wad['Valor'],
                        'ESTATUS'     => ($wad['IdRecolecta'] > 0 ? '<span class="badge bg-success">Deposito #'. $wad['IdRecolecta'] .'</span>' : '<span class="badge bg-warning">Pendiente</span>' ),
                        'HORA'        => $wad['Hora'],
                        'ACCIONES'    => $actions
                    ];
                }
            }
            json_output(array("data" => $data));
        }
    }

    function wads_table_total() {
        $data = [];

        if ($islands_wads = $this->tabulatorDatailsModel->get_wads_by_island($_GET['tabId'])) {
            foreach ($islands_wads as $island) {
                $data[] = [
                    'ISLA' => $island['den'],
                    'FAJILLAS' => $island['hits'],
                    'Monto_MXN' => $island['Monto_MXN'],
                    'Monto_USD' => $island['Monto_USD'],
                    'Monto_MRL' => $island['Monto_MRL'],
                ];
            }
        }
        json_output(array("data" => $data));
    }

    /**
     * @return void
     * @throws Exception
     */
    function finish() : void {
        $Estatus          = $_GET['estatus'];
        $TotalProductos   = $_GET['TotalProductos'];
        $Total            = $_GET['Total'];
        $Id               = $_GET['tabId'];
        $Turno            = $_GET['turno'];
        $FechaTabular     = $_GET['fechatabular'];
        $DBString         = $_GET['DBString'];
        $valores          = $this->movimientosTarModel->get_total_by_tabulator(dateToInt($_GET['fechatabular']), $_GET['codigoestacion'], $_GET['turno'], $_GET['tabId']);
        $dolares_quemados = $this->movimientosTarModel->get_total_dolares_by_tab($_GET['codigoestacion'], dateToInt($_GET['fechatabular']), $_GET['turno']);
        $totals_sales     = $this->despachosModel->get_turn_sales($_GET['codigoestacion'], dateToInt($_GET['fechatabular']), $_GET['turno']);

        $json = [
            'html'  => $this->twig->render($this->route . 'finishTab.html', compact( 'dolares_quemados', 'totals_sales', 'valores', 'Estatus', 'TotalProductos', 'Total', 'Id', 'Turno', 'FechaTabular', 'DBString'))
        ];

        json_output($json);
    }

    /**
     * @return void
     * @throws Exception
     */
    function deposit() : void {
        $tabulator = $this->tabulatorModel->get_tabulator($_GET['tabId']); // Para obtener los datos del tabulador

        // Estas lineas son para obtener las fajillas pendientes de depositar
        $totalMXN = $this->tabulatorDatailsModel->getPendingWads($_GET['tabId'], 'MXN');
        $totalUSD = $this->tabulatorDatailsModel->getPendingWads($_GET['tabId'], 'USD');

        // Vamos a extraer el total depositado por tabulador actual y por moneda
        $totalDepositedMXN = $tabulator['TotalDenominaciones'];

        $totalDepositedUSD = $this->tabuladorRecolectasModel->get_total_by_tab($_GET['tabId'], 'USD');

        $exchange_now = $tabulator['TipoCambio']; // Para obtener el tipo de cambio más reciente
        $deposit_open = false;

        $collects = $this->tabuladorRecolectasModel->get_tabulator_collects($_GET['tabId']); // Para obtener los depósitos de un tabulador

        $recolecta = (!empty($totalMXN['TieneRecolectas']) && $totalMXN['TieneRecolectas'] == 1) || (!empty($totalUSD['TieneRecolectas']) && $totalUSD['TieneRecolectas'] == 1);

        if ($recolecta) {
            $recolecta_abierta = array_filter($collects, function($collect) {
                return $collect['Estatus'] == "1";
            });
            $deposit_open = $recolecta_abierta[0];
        }

        $json = [
            'html'  => $this->twig->render($this->route . 'deposit.html', compact('tabulator', 'deposit_open', 'totalMXN', 'totalUSD', 'collects', 'exchange_now', 'totalDepositedMXN', 'totalDepositedUSD'))
        ];

        json_output($json);
    }

    /**
     * @param $tabId
     * @param $secuencial
     * @return void
     * @throws Exception
     */
    function delete_wad($tabId, $secuencial, $codgas) : void {

        $wad = $this->tabulatorDatailsModel->get_wad($tabId, $secuencial);
        if ($this->tabulatorDatailsModel->delete_wad($tabId, $secuencial)) {
            // Ahora vamos a ver is existe un registro en Anticipos con el secuencial de la fajilla que se acaba de eliminar
            if ($wad['secuencialAnticipo']) {
                // Si existe, entonces eliminamos el registro de Anticipos
                $this->anticiposModel->deleteAnticipo($codgas, dateToInt($wad['Fecha']), $wad['Turno'], $wad["IdIsla"], $wad['secuencialAnticipo'], $wad['Valor']);
            }
            setFlashMessage('success','Fajilla eliminada correctamente');
        } else {
            setFlashMessage('error','No se pudo eliminar la fajilla');
        }
        redirect();
    }

    /**
     * @param $tabId
     * @param $secuencial
     * @return void
     * @throws Exception
     */
    function editWadModal($tabId, $secuencial) : void {
        $tabulator = $this->tabulatorModel->get_tabulator($tabId); // Para obtener los datos del tabulador
        $wad = $this->tabulatorDatailsModel->get_wad($tabId, $secuencial); // Para obtener la fajilla solicitada
        if (preg_match('/GET/i',$_SERVER['REQUEST_METHOD'])){
            $islands = $this->islandModel->get_isles_by_station($tabulator['CodigoEstacion']); // Para obtener las islas de una estación

            $modal = [
                "title"    => "Editar fajilla",
                "size"     => "modal-sm",
                "position" => "modal-dialog-centered",
                "content"  => $this->twig->render($this->route . 'modals/editWadModal.html', compact('tabulator', 'wad', 'islands'))
            ];
            json_output($modal);
        } else {
            // Creamos un nuevo registro en TabuladorDetalle con los datos de la fajilla editada
            $newAmount = $_POST['amount'];
            $newValor = ($wad['Moneda'] === 'USD') ? ($newAmount * $wad['TipoCambio']) : $newAmount ;

            // Primero, editamos el WAD
            $this->tabulatorDatailsModel->edit($newAmount, $_POST['island_id'], $newValor, $tabId, $secuencial);

            // Luego, editamos el Anticipo de la estacion
            if ($wad['secuencialAnticipo']) {
                // Si existe, entonces eliminamos el registro de Anticipos
                $this->anticiposModel->editAnticipo($tabulator['CodigoEstacion'], dateToInt($wad['Fecha']), $wad['Turno'], $wad["IdIsla"], $wad['secuencialAnticipo'], $wad['Valor'], $newAmount, $_POST['island_id']);
            }

            // Primero vamos a calcular la diferencia entre el valor anterior y el nuevo valor
            $this->tabulatorModel->updateTabuladorTotal(['Monto' => ($newAmount - $wad['Monto']), 'Id' => $tabId]);

            setFlashMessage('success','Fajilla editada correctamente');
            redirect("/operations/tab_process/$tabId");
        }
    }

    /**
     * @return void
     * @throws Exception
     */
    function save_denominations_form() : void {
        // Obtenemos los datos del tabulador y del depósito activo
        $tabulator = $this->tabulatorModel->get_tabulator($_POST['IdTabulador']);
        $deposit = $this->tabuladorRecolectasModel->get_collect($_POST['IdTabulador']);

        if ($this->tabuladorDenominacionesModel->add($_POST, $deposit, $tabulator['TipoCambio'])) {

            // Despues de guardar las denominaciones, vamos a 'cerrar las fajillas' que se utilizaron para el depósito
            if ($this->tabulatorDatailsModel->close_wads($deposit['IdRecolecta'], $_POST['IdTabulador'], $deposit['CodigoValor'])) {
                // Si las fajillas se cerraron correctamente, entonces vamos a actualizar el campo Estatus en TabuladorRecolectas
                if ($this->tabuladorRecolectasModel->close_collect($_POST['IdTabulador'], $deposit['IdRecolecta'])) {
                    setFlashMessage('success','Denominaciones agregadas correctamente');
                } else {
                    setFlashMessage('error','No se pudo cerrar las fajillas');
                }
                setFlashMessage('success','Denominaciones agregadas correctamente');
            } else {
                setFlashMessage('error','No se pudo cerrar las fajillas');
            }
        } else {
            setFlashMessage('error','No se pudo agregar las denominaciones');
        }
    }

    /**
     * @param $IdRecolecta
     * @return void
     * @throws Exception
     */
    function cancel_collect($IdTabulator, $IdRecolecta) : void {
        if (preg_match('/GET/i',$_SERVER['REQUEST_METHOD'])){
            if ($this->tabuladorRecolectasModel->cancel_collect($IdTabulator, $IdRecolecta)) {
                setFlashMessage('success','Depósito cancelado correctamente');
                redirect();
            }
        }
    }

    /**
     * @return void
     * @throws Exception
     */
     public function save_reading() : never {
        $tabulator = $this->tabulatorModel->get_tabulator($_POST['tabId']);
        $CodigoEstacion         = $tabulator['CodigoEstacion'];
        $Fecha                  = date('Ymd');
        $LecturaInicialMecanica = $_POST['LecturaInicialMecanica'];

        // Primero verificamos si existe algun registro de esta isla en la tabla de Lecturas
        if ($id = $this->lecturasModel->exists($CodigoEstacion, $Fecha)) {
            // Si existe, entonces vamos a actualizar el registro
            if ($this->lecturasModel->update($id, $_POST)) {
                json_output(1);
            } else {
                json_output(0);
            }
        } else {
            // Si no existe, entonces vamos a crear el registro
            if ($this->lecturasModel->add($tabulator)) {
                json_output(1);
            } else {
                json_output(0);
            }
        }
        exit();
    }

    /**
     * @param $CodigoEstacion
     * @param $FechaTabular
     * @param $Turno
     * @param $Id
     * @return void
     * @throws Exception
     */
    function readings_table($CodigoEstacion, $FechaTabular, $Turno, $Id, $Estatus) : void {
        $data = [];

        // Obtenemos las lecturas del tabulador actual y poblamos la tabla de lecturas
        if ($readings = $this->lecturasModel->get_readings_by_tabulator($CodigoEstacion, str_replace('-', '', $FechaTabular), $Turno, $Id, $FechaTabular)) {
            $data = array_map(function ($reading) {
                return [
                    'CODISLA'       => $reading['CodIsla'],
                    'ISLA'          => $reading['Isla'],
                    'BOMBA'         => $reading['Bomba'],
                    'PRODUCTO'      => trim($reading['Producto']),
                    'CODPRODUCTO'   => trim($reading['CodProducto']),
                    'L_INI_ELECT'   => $reading['LecturaInicialElectronica'],
                    'L_FIN_ELECT'   => $reading['LecturaFinalElectronica'],
                    'LTS_VEN_ELECT' => ($reading['LecturaFinalElectronica'] - $reading['LecturaInicialElectronica']),
                    'L_INI_MEC'     => $reading['LecturaInicialMecanica'],
                    'L_FIN_MEC'     => $reading['LecturaFinalMecanica'],
                    'LTS_VEN_MEC'   => ($reading['LecturaFinalMecanica'] - $reading['LecturaInicialMecanica']),
                    'DIF_LITROS'    => ($reading['LecturaFinalElectronica'] - $reading['LecturaFinalMecanica']),
                    'tabId'         => $reading['Tabulador'],
                ];
            }, $readings);
        }
        json_output(array("data" => $data));
    }

    /**
     * @param $CodigoEstacion
     * @param $turno
     * @return void
     * @throws Exception
     */
    function dispatches_table($CodigoEstacion, $turno, $FechaTabulador) : void {
        $data = [];
        if ($dispatches = $this->despachosModel->sp_obtener_despachos_para_marcar($CodigoEstacion, dateToInt($FechaTabulador), $turno)) {

            $data = array_map(function ($dp) {
                if ($dp['Valor'] == '') {
                    $dp['Valor'] = $dp['trxcod'];
                }
                if ($dp['tar'] != 0) {
                    $dp['Valor'] .= '<br><span class="badge bg-warning-dark">Tarjeta</span> ';
                }

                return [
                    'CHECK'    => $dp['Despacho'],
                    'DESPACHO' => $dp['Despacho'],
                    'FECHA'    => $dp['Fecha'],
                    'CLIENTE'  => $dp['Cliente'],
                    'PRODUCTO' => $dp['Producto'],
                    'LITROS'   => $dp['Volumen'],
                    'MONTO'    => $dp['Monto'],
                    'ISLA'     => '<p class="text-nowrap border-light">'. $dp['Isla'] .'</p>',
                    'BOMBA'    => $dp['Bomba'],
                    'FACTURA'  => $dp['nrofac'],
                    'VALOR'    => (is_null($dp['Valor']) ? 'Contado' : $dp['Valor'] ),
                    'ACCIONES' => (is_null($dp['Valor']) ? '' : ( $dp['Valor'] == '<span class="badge bg-success">Dolares</span>' ? '' : ( $dp['tiptrn'] != "51" ? '<a href="javascript:void(0);" onClick="dismark_dispatch('. $dp['codgas'] . ',' . $dp['Despacho'] .')" class="text-primary"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-edit-2 align-middle"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"></path></svg></a>' : '' ) ) ),
                    'trxmsg'   => '<p class="text-danger text-center">'. $dp['trxmsg'] .'</p>'
                ];
            }, $dispatches);
        }
        json_output(array("data" => $data));
    }

    /**
     * @throws Exception
     */
    function dismark_dispatch($codest, $nrotrn) : void {

        // Luego obtenemos los datos de la "Fajilla"
        $wad = $this->tabulatorDatailsModel->get_wad_by_dispatch($codest, $nrotrn);

        // Primero eliminamos el despacho de la tabla TabuladorDetalle
        $this->tabulatorDatailsModel->dismark_dispatch($codest, $nrotrn);

        // Luego vamos a actualizar la tabla de despachos de la estacion y de corporativo
        $this->despachosModel->dismark_dispatch_station($codest, $nrotrn);
        $this->despachosModel->dismark_dispatch_central($codest, $nrotrn);

        // Luego vamos a eliminar los registros de la tabla Anticipos de la estacion y de la base de datos central
         $this->anticiposModel->dismark_dispatch_station($codest,
             $wad['secuencialAnticipo']);
         $this->anticiposModel->dismark_dispatch_central($codest,
             $wad['secuencialAnticipo']);

        // Luego vamos a eliminar los registros de la tabla de movimientos de tarjetas de la estacion y de la base de datos central
        $this->movimientosTarModel->dismark_dispatch_station($codest, $nrotrn);
        $this->movimientosTarModel->dismark_dispatch_central($codest, $nrotrn);

        // Vamos a retornar 1 para indicar que todo salió bien
        json_output(1);

    }

    /**
     * @param $CodigoEstacion
     * @param $FechaTabular
     * @param $Turno
     * @return array
     * @throws Exception
     */
     public function get_readings_by_tab($CodigoEstacion, $FechaTabular, $Turno){
        $shifts = [11, 21, 31, 41];
        $index = array_search($Turno, $shifts);

        $previous_shift = $shifts[($index + count($shifts) - 1) % count($shifts)];

        if ($previous_shift == 41) {
            $previous_date = date('Ymd', strtotime('-1 day', strtotime($FechaTabular)));
        } else {
            $previous_date = $FechaTabular;
        }

        $previous_readings = $this->medicionModel->get_measurements_by_tabulator($CodigoEstacion, $previous_date, $previous_shift);
        $actual_readings = $this->medicionModel->get_measurements_by_tabulator($CodigoEstacion, str_replace('-', '', $FechaTabular), $Turno);

        foreach ($actual_readings as $key => $lectura) {
            $actual_readings[$key]['LecturaInicialElectronica'] = $previous_readings[$key]['LecturaFinalElectronica'];
        }

        return $actual_readings;
    }

    function getInitialReadings($CodigoEstacion, $FechaTabular, $Turno) {
        $shifts = [11, 21, 31, 41];
        $index = array_search($Turno, $shifts);

        $previous_shift = $shifts[($index + count($shifts) - 1) % count($shifts)];

        if ($previous_shift == 41) {
            $previous_date = date('Ymd', strtotime('-1 day', strtotime($FechaTabular)));
        } else {
            $previous_date = $FechaTabular;
        }
        return $this->medicionModel->getInitialReadings($CodigoEstacion, $previous_date, $previous_shift);
    }

    /**
     * @param $CodigoEstacion
     * @param $FechaTabular
     * @param $Turno
     * @param $isla
     * @return array|false
     * @throws Exception
     */
    function getInitialReadingsByIsland($CodigoEstacion, $FechaTabular, $Turno, $isla) {
        $shifts = [11, 21, 31, 41];
        $index = array_search($Turno, $shifts);

        $previous_shift = $shifts[($index + count($shifts) - 1) % count($shifts)];

        if ($previous_shift == 41) {
            $previous_date = date('Ymd', strtotime('-1 day', strtotime($FechaTabular)));
        } else {
            $previous_date = $FechaTabular;
        }
        return $this->medicionModel->getInitialReadingsByIsland($CodigoEstacion, $previous_date, $previous_shift, $isla);
    }

    /**
     * @param $CodigoEstacion
     * @param $FechaTabular
     * @param $Turno
     * @param $isla
     * @return array|false
     * @throws Exception
     */
    function getInitialReadingsByIslands($CodigoEstacion, $FechaTabular, $Turno, $Islands) {
        $shifts = [11, 21, 31, 41];
        $index = array_search($Turno, $shifts);

        $previous_shift = $shifts[($index + count($shifts) - 1) % count($shifts)];

        if ($previous_shift == 41) {
            $previous_date = ($FechaTabular - 1);
        } else {
            $previous_date = $FechaTabular;
        }
        return $this->medicionModel->getInitialReadingsByIslands($CodigoEstacion, $previous_date, $previous_shift, $Islands);
    }

    function getInitialReadingsByIslands2($CodigoEstacion, $FechaTabular, $Turno, $Islands) {
        $shifts = [11, 21, 31, 41];
        $index = array_search($Turno, $shifts);

        $previous_shift = $shifts[($index + count($shifts) - 1) % count($shifts)];

        if ($previous_shift == 41) {
            $previous_date = ($FechaTabular - 1);
        } else {
            $previous_date = $FechaTabular;
        }
        return $this->medicionModel->getInitialReadingsByIslands2($CodigoEstacion, $previous_date, $previous_shift, $Islands);
    }

    function getInitialReadingsByIslands3($CodigoEstacion, $FechaTabular, $Turno, $Islands, $tabId) {

        $shifts = [11, 21, 31, 41];
        $index = array_search($Turno, $shifts);

        $previous_shift = $shifts[($index + count($shifts) - 1) % count($shifts)];

        if ($previous_shift == 41) {
            $previous_date = ($FechaTabular - 1);
        } else {
            $previous_date = $FechaTabular;
        }
        return $this->despachosModel->get_saldos_islas($CodigoEstacion, $previous_date, $previous_shift, $Islands, $FechaTabular, $Turno, $tabId);
    }

    function get_totals_tab($tabId, $codgas, $fecha, $turno, $islands) {
        $shifts = [11, 21, 31, 41];
        $index = array_search($turno, $shifts);

        $previous_shift = $shifts[($index + count($shifts) - 1) % count($shifts)];

        if ($previous_shift == 41) {
            $previous_date = ($fecha - 1);
        } else {
            $previous_date = $fecha;
        }

        return $this->tabulatorModel->get_totals_tab($tabId, $codgas, $fecha, $turno, $islands, $previous_date, $previous_shift);
    }

    /**
     * @param $tabId
     * @return void
     * @throws Exception
     */
    function markDispatchesModal($tabId) : void {
        $tabular = $this->tabulatorModel->get_tabulator($tabId);
        $modal = [
            "title"    => "Marcación de despachos",
            "size"     => "modal-sm",
            "position" => "modal-dialog-centered",
            "content"  => $this->twig->render($this->route . 'modals/markDispatchesModal.html', compact('tabular'))
        ];
        json_output($modal);
    }



    function history($tabId) : void {
        $tabular = $this->tabulatorModel->get_tabulator($tabId);
        $history = $this->tabulatorHistoryModel->get_by_tabulator($tabId);
//        echo '<pre>';
//        foreach ($history as $key => $value) {
//            var_dump(substr($value['DatosAnteriores'], 0, 50));
//        }
//        die();

        echo $this->twig->render($this->route . 'history.html', compact('tabular', 'history'));
    }

    /**
     * @param $tabId
     * @return void
     * @throws Exception
     */
    function creditModal($tabId) : void {
        $tabular = $this->tabulatorModel->get_tabulator($tabId);
        $turnos = [
            '11' => ['hratrn_init' => '0000', 'hratrn_final' => '0600'],
            '21' => ['hratrn_init' => '0600', 'hratrn_final' => '1400'],
            '31' => ['hratrn_init' => '1400', 'hratrn_final' => '2200'],
            '41' => ['hratrn_init' => '2200', 'hratrn_final' => '0000'],
        ];

        $dispatches = $this->despachosModel->get_credit_dispatches_tabular(dateToInt($tabular['FechaTabular']), $turnos[$tabular['Turno']]['hratrn_init'], $turnos[$tabular['Turno']]['hratrn_final'], $tabular['CodigoEstacion']);

        $modal = [
            "title"    => "Despachos de crédito",
            "size"     => "modal-xl",
            "position" => "modal-dialog-centered",
            "content"  => $this->twig->render($this->route . 'modals/creditModal.html', compact('dispatches'))
        ];
        json_output($modal);
    }

    /**
     * @param $tabId
     * @return void
     * @throws Exception
     */
    function debitModal($tabId) : void {
        $tabular = $this->tabulatorModel->get_tabulator($tabId);
        $turnos = [
            '11' => ['hratrn_init' => '0000', 'hratrn_final' => '0600'],
            '21' => ['hratrn_init' => '0600', 'hratrn_final' => '1400'],
            '31' => ['hratrn_init' => '1400', 'hratrn_final' => '2200'],
            '41' => ['hratrn_init' => '2200', 'hratrn_final' => '0000'],
        ];

        $dispatches = $this->despachosModel->get_debit_dispatches_tabular(dateToInt($tabular['FechaTabular']), $turnos[$tabular['Turno']]['hratrn_init'], $turnos[$tabular['Turno']]['hratrn_final'], $tabular['CodigoEstacion']);

        $modal = [
            "title"    => "Despachos de débito",
            "size"     => "modal-xl",
            "position" => "modal-dialog-centered",
            "content"  => $this->twig->render($this->route . 'modals/debitModal.html', compact('dispatches'))
        ];
        json_output($modal);
    }

    /**
     * @return void
     * @throws Exception
     */
    function markDispatchesForm() : void {
        // Primero verificamos si nos enviaron despachos:
        if (!isset($_POST['dispatches']) OR empty($_POST['dispatches']) OR !isset($_POST['tabularId']) OR empty($_POST['tabularId'])) {
            setFlashMessage('error','No se recibieron despachos. Por favor, selecciona al menos un despacho de la tabla.');
            redirect();
        }

        // Obtenemos la informacion del tabular
        $tabular = $this->tabulatorModel->get_tabulator($_POST['tabularId']);

        // Obtenemos la información del valor butt
        $CodigoValor = $this->valorButtModel->getRow($_POST['CodigoValor']);

        // Vamos a guardar cada uno de los despachos como si fuera una fajilla
        foreach ($_POST['dispatches'] as $dispatch) {
            // Primero obtenemos la información del despacho que vamos a guardar
            $dispatchRow = $this->despachosModel->get_dispatch($tabular['CodigoEstacion'], $dispatch);
            // Vamos a obtener al responsable asignado a ese despacho
            if ($responsable = $this->assignacionesModel->get_assignation_by_island($tabular['CodigoEstacion'], $tabular['FechaTabular'], $tabular['Turno'], $dispatchRow['codisl'])) {
                // Luego agregamos el despacho como si fuera una fajilla a la tabla de TabuladorDetalle
                // Luego actualizamos la tabla de despachos de la estación

                // Esta función agrega un registro en [TG].[dbo].[TabuladorDetalle] y actualiza la tabla de despachos de la estación
                if ($this->despachosModel->sp_marcar_despacho_onegoal($tabular, $dispatchRow, $CodigoValor, $_POST['CodigoTar'], $_POST['CodigoRut'])) {
                    // Ahora vamos a actualizar la tabla de anticipos
                    if ($this->anticiposModel->sp_actualizar_tabulador_y_anticipos($tabular, $dispatchRow, $CodigoValor, $responsable[0]['Responsable'])) {
                        // Ahora insertamos en la tabla de MovimientosTar
                        $this->movimientosTarModel->add(dateToInt($tabular['FechaTabular']), $tabular['CodigoEstacion'], $CodigoValor["ValorButt_Id"], $dispatch, $dispatchRow['tar'], '001', $_POST['CodigoRut'], $_POST['CodigoRut'], $dispatchRow['mto'], $dispatchRow['nrotur'], $CodigoValor["ValorButt_Num"], $dispatchRow['codisl']);
                    } else {
                        setFlashMessage('error','No se pudo marcar el despacho '. $dispatch .' en Anticipos.');
                    }
                } else {
                    setFlashMessage('error','No se pudo marcar el despacho '. $dispatch .' en OneGoal.');
                }
            } else {
                $isle = $this->islandModel->get_isle($tabular['CodigoEstacion'], $dispatchRow['codisl']);
                setFlashMessage('error','No existe registro del responsable de la '. $isle['Isla'] .'. Asigne un responsable antes de marcar un despacho '. $dispatch .'.');
            }
        }
        redirect("/operations/tab_process/{$_POST['tabularId']}");
    }

    /**
     * @return void
     * @throws Exception
     */
    function delete_deposit() : void {
        $IdTabulador = $_POST['IdTabulador'];
        $IdRecolecta = $_POST['IdRecolecta'];
        if ($this->tabuladorRecolectasModel->sp_eliminar_deposito($IdRecolecta, $IdTabulador)) {
            json_output(1);
        } else {
            json_output(0);
        }
    }

    /**
     * @return void
     * @throws Exception
     */
    function compare_total_vs_denominations() : void {
        if (preg_match('/POST/i',$_SERVER['REQUEST_METHOD'])){
            $tabId = $_POST['tabId'];
            if ($tabulator = $this->tabulatorModel->get_tabulator($tabId)) {
                $total = floatval($tabulator['Total']);
                // Ahora obtendremos el total de las denominaciones del tabulador
                $denominations = $this->tabuladorDenominacionesModel->getTotalByTabular($tabId);
                if ($total == floatval($denominations)) {
                    $response = array('success' => true, 'message' => 'La suma de las fajillas ($'. number_format($total, 2).') coincide con las denominaciones($'. number_format($denominations, 2).').');
                } else {
                    $response = array('success' => false, 'message' => 'La suma de las fajillas ($'. number_format($total, 2).') no coincide con las denominaciones ($'. number_format($denominations, 2).').');
                }
            } else {
                $response = array('success' => false, 'message' => 'No existe un tabulador que coincida con este folio.');
            }
            json_output($response);
        }
    }

    /**
     * @return void
     * @throws Exception
     */
    function check_pending_deposits() : void {
        if (preg_match('/POST/i',$_SERVER['REQUEST_METHOD'])){
            $tabId = $_POST['tabId'];
            if ($this->tabuladorRecolectasModel->get_active_collect($tabId)) {
                $response = array('success' => false, 'message' => 'Tiene depósitos pendientes por realizar');
            } else {
                $response = array('success' => true, 'message' => 'No existen depósitos pendientes');
            }
            json_output($response);
        }
    }

    function close_tabulator() : void {
        if ($this->tabulatorModel->close_tabulator($_POST['tabId'])) {
           json_output(array(
               'success' => true,
               'message' => 'Tabulador cerrado correctamente'
           ));
        } else {
            json_output(array(
                'success' => false,
                'message' => 'No se pudo cerrar el tabulador'
            ));
        }
    }

    /**
     * @param $tabId
     * @return void
     * @throws Exception
     */
    function download_format($tabId) : void {
        $tabulator = $this->tabulatorModel->get_tabulator($tabId);
        $station = $this->estacionesModel->get_station($tabulator['CodigoEstacion']);
        $samplings = $this->despachosModel->get_jarreos($tabulator['CodigoEstacion'], dateToInt($tabulator['FechaTabular']), $tabulator['Turno']);

        $pdf = new PDF_Code128();
        // Establecer el tamaño de la página en milimetros (Ancho x Alto)
        $pdf->AddPage('P');
        // Recuadro de la fotografia
        $pdf->Image($_SERVER['DOCUMENT_ROOT']. '/_assets/images/Plantilla.png', 0, 0, 210, 297);

        $pdf->Image($_SERVER['DOCUMENT_ROOT']. '/_assets/images/logos/logo_TotalGas_hor_azul.png', 5, 5, 60, 18);

        $pdf->SetFont('Arial','B',22);
        $pdf->SetTextColor(255,255,255);
        $pdf->Cell(65.5,10,'', 0, 0, 'C');
        $pdf->Cell(125.5,10,'FORMATO DE AUTOJARREO', 0, 1, 'C');
        $pdf->SetFont('Arial','',10);
        $pdf->Cell(183,5,'', 0, 1, 'R');
        $pdf->Cell((117 + 65.5),5,'Fecha: ' . date('Y-m-d'), 0, 1, 'R');
        $pdf->Cell(183,5,mb_convert_encoding('Número de estación: ' . $station['Estacion'], 'ISO-8859-1'), 0, 1, 'R');
        $pdf->Cell(183,5,mb_convert_encoding('Nombre de estación: ' . $station['Nombre'], 'ISO-8859-1'), 0, 1, 'R');

        $pdf->Ln(7);

        $pdf->SetFont('Arial','B',10);
        $pdf->SetTextColor(0,0,0);
        $pdf->Cell(38,7,'', 0, 0, 'R');$pdf->Cell(38,7,'', 0, 0, 'R'); $pdf->Cell(114,7,'LITROS', 1, 1, 'C');
        $pdf->Cell(38,7,mb_convert_encoding('POSICIÓN', 'ISO-8859-1'), 1, 0, 'C');$pdf->Cell(38,7,mb_convert_encoding('No DESPACHO', 'ISO-8859-1'), 1, 0, 'C'); $pdf->Cell(38,7,mb_convert_encoding('MÁXIMA', 'ISO-8859-1'), 1, 0, 'C'); $pdf->Cell(38,7,mb_convert_encoding('SUPER', 'ISO-8859-1'), 1, 0, 'C');$pdf->Cell(38,7,mb_convert_encoding('DIESEL', 'ISO-8859-1'), 1, 1, 'C');

        if ($samplings) {
            for ($i = 0; $i < 15; $i++) {
                if (isset($samplings[$i])) {
                    $pdf->Cell(38, 7, $samplings[$i]['Bomba'], 1,0,'C'); $pdf->Cell(38, 7, $samplings[$i]['Transaccion'], 1,0,'C'); $pdf->Cell(38, 7, $samplings[$i]['Transaccion'], 1,0,'C'); $pdf->Cell(38, 7, $samplings[$i]['Transaccion'], 1,0,'C'); $pdf->Cell(38, 7, $samplings[$i]['Transaccion'], 1,1,'C');
                } else {
                    $pdf->Cell(38, 7, '---', 1,0,'C'); $pdf->Cell(38, 7, '---', 1,0,'C'); $pdf->Cell(38, 7, '---', 1,0,'C'); $pdf->Cell(38, 7, '---', 1,0,'C'); $pdf->Cell(38, 7, '---', 1,1,'C');
                }
            }
        } else {
            for ($i = 0; $i < 15; $i++) {
                $pdf->Cell(38, 7, '---', 1,0,'C'); $pdf->Cell(38, 7, '---', 1,0,'C'); $pdf->Cell(38, 7, '---', 1,0,'C'); $pdf->Cell(38, 7, '---', 1,0,'C'); $pdf->Cell(38, 7, '---', 1,1,'C');
            }
    }

        $pdf->Ln(2);
        $pdf->Cell(76,7,mb_convert_encoding('TOTAL LITROS', 'ISO-8859-1'), 1, 0, 'C'); $pdf->Cell(38,7,'', 1, 0, 'C'); $pdf->Cell(38,7,'', 1, 0, 'C');$pdf->Cell(38,7,'', 1, 1, 'C');
        $pdf->Cell(76,7,mb_convert_encoding('PRECIO UNITARIO', 'ISO-8859-1'), 1, 0, 'C'); $pdf->Cell(38,7,'', 1, 0, 'C'); $pdf->Cell(38,7,'', 1, 0, 'C');$pdf->Cell(38,7,'', 1, 1, 'C');
        $pdf->Cell(76,7,mb_convert_encoding('IMPORTE TOTAL', 'ISO-8859-1'), 1, 0, 'C'); $pdf->Cell(38,7,'', 1, 0, 'C'); $pdf->Cell(38,7,'', 1, 0, 'C');$pdf->Cell(38,7,'', 1, 1, 'C');

        $pdf->Ln(4);
        $pdf->Cell(191,7,mb_convert_encoding('BREVE DESCRIPCIÓN: ', 'ISO-8859-1'), 'LTR', 1, 'L');
        $pdf->Cell(191,18,'', 'LBR', 1, 'L');

        $pdf->Ln(4);
        $pdf->Cell(85.5,15,'', 'B', 0, 'L'); $pdf->Cell(20,15,'', 0, 0, 'L'); $pdf->Cell(85.5,15,'', 'B', 1, 'L');
        $pdf->Cell(85.5,15,mb_convert_encoding(strtoupper($_SESSION['tg_user']['Nombre']), 'ISO-8859-1'), 'B', 0, 'C'); $pdf->Cell(20,15,'', 'B', 0, 'L'); $pdf->Cell(85.5,15,'PERSONAL AUTORIZADO', 'B', 1, 'C');

        $pdf->Ln(10);
        $pdf->SetFont('Arial','I',8);
        $pdf->MultiCell(191,3,mb_convert_encoding('El autojarreo es un procedimiento seguro y controlado que se realiza bajo estrictas normas de seguridad y calidad. Es importante recordar que el autojarreo incorrecto puede causar daños a la infraestructura de la gasolinera y al medio ambiente. Si detectas alguna irregularidad durante el autojarreo, por favor repórtalo inmediatamente a tu supervisor. TotalGas se reserva el derecho de tomar las medidas disciplinarias y/o legales que considere oportunas contra cualquier empleado que realice un autojarreo para beneficio propio, con fines de lucro o por negligencia.', 'ISO-8859-1'), 0, 'C');
        $pdf->Output();
    }

    /**
     * @return void
     * @throws Exception
     */
    function sales() : void {
        $today_sales = $this->despachosModel->get_today_sales($this->todayInt);
        if (preg_match('/GET/i',$_SERVER['REQUEST_METHOD'])){
            $from_date = isset($_GET['from']) ? $_GET['from'] : date('Y-m-d');
            $until_date = isset($_GET['until']) ? $_GET['until'] : date('Y-m-d');
            echo $this->twig->render($this->route . 'sales.html', compact('today_sales', 'from_date', 'until_date'));
        } else {
            $from        = dateToInt($_POST['from']);
            $until       = dateToInt($_POST['until']);
            $from_date   = $_POST['from'];
            $until_date  = $_POST['until'];
            echo $this->twig->render($this->route . 'sales.html', compact('today_sales', 'from', 'until', 'from_date', 'until_date'));
        }
    }

    function sales_day_turn(){
        echo $this->twig->render($this->route . 'reports/sales_day_turn.html');

    }

    function sales_stations() : void {
        $from = isset($_GET['from']) ? $_GET['from'] : date('Y-m-d');
        $until = isset($_GET['until']) ? $_GET['until'] : date('Y-m-d');
        $codgas = isset($_GET['codgas']) ? $_GET['codgas'] : 0;
        $codprd = isset($_GET['codprd']) ? $_GET['codprd'] : 0;

        $stations = $this->gasolinerasModel->get_active_stations();

        if (preg_match('/GET/i',$_SERVER['REQUEST_METHOD'])) {
            echo $this->twig->render($this->route . 'sales_stations.html', compact('from', 'until', 'codgas', 'stations', 'codprd'));
        } else {
            $data = [];
            if ($sales = $this->despachosModel->get_sales_stations(dateToInt($_POST['from']), dateToInt($_POST['until']), $_POST['codgas'], $_POST['codprd'])) {
                foreach ($sales as $sale) {
                    $actions = '<a href="/operations/get_sales_details/'. $sale['FechaCompleta'] .'/'. $sale['CodigoGasolinera'] .'/'. $sale['CodigoProducto'] .'" class="btn btn-primary btn-sm"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-eye align-middle"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg></a>';
                    $data[] = [
                        'Fecha'     => $sale['Fecha'],
                        'Estación'  => $sale['Gasolinera'],
                        'Producto'  => $sale['Producto'],
                        'Despachos' => $sale['TotalDespachos'],
                        'Volumen'   => $sale['Volumen'],
                        'Precio'    => $sale['Precio'],
                        'Importe'   => $sale['Importe'],
                        'Crédito'   => $sale['Credito'],
                        'Débito'    => $sale['Debito'],
                        'Acciones'  => $actions,
                    ];
                }
            }
            json_output(array("data" => $data));
        }
    }

    function get_sales_details($fch, $codgas, $prd) {
        if (preg_match('/GET/i',$_SERVER['REQUEST_METHOD'])) {
            $data = [];
            $despachos = $this->despachosModel->get_sales_details($fch, $codgas, $prd);
            echo $this->twig->render($this->route . 'sales_details.html', compact('despachos'));
        }
    }

    function responsables() : void {
        if (preg_match('/GET/i',$_SERVER['REQUEST_METHOD'])){
            echo $this->twig->render($this->route . 'responsables.html');
        } else {
            $data = [];
            if($responsables = $this->responsablesModel->get_all()) {
                // Hacemos un arraymap
                $data = array_map(function($responsable) {
                    $actions = "<a href=\"javascript:void(0);\" class=\"mx-1 text-primary\" data-bs-toggle=\"modal\" data-bs-target=\"#responsableModal\" data-id=\"{$responsable['cod']}\" data-codgas=\"{$responsable['codgas']}\"><svg xmlns=\"http://www.w3.org/2000/svg\" width=\"24\" height=\"24\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\" class=\"feather feather-edit-2 align-middle\"><path d=\"M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z\"></path></svg></a>";
                    $actions .= "<a href=\"javascript:void(0);\" class=\"mx-1 text-success\" onclick=\"deactivate_responsable({$responsable['cod']}, {$responsable['hab']})\"><svg xmlns=\"http://www.w3.org/2000/svg\" width=\"24\" height=\"24\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\" class=\"feather feather-refresh-cw align-middle me-2\"><polyline points=\"23 4 23 10 17 10\"></polyline><polyline points=\"1 20 1 14 7 14\"></polyline><path d=\"M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15\"></path></svg></a>";
                    $actions .= "<a href=\"javascript:void(0);\" class=\"text-danger\" onclick=\"delete_responsable({$responsable['cod']})\"><svg xmlns=\"http://www.w3.org/2000/svg\" width=\"24\" height=\"24\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\" class=\"feather feather-trash-2 align-middle me-2\"><polyline points=\"3 6 5 6 21 6\"></polyline><path d=\"M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2\"></path><line x1=\"10\" y1=\"11\" x2=\"10\" y2=\"17\"></line><line x1=\"14\" y1=\"11\" x2=\"14\" y2=\"17\"></line></svg></a>";
                    return [
                        'Nombre' => $responsable['den'],
                        'Codigo' => $responsable['cod'],
                        'NoReloj' => $responsable['codext'],
                        'Status' => $responsable['Status'],
                        'Puesto' => $responsable['pto'],
                        'Estacion' => $responsable['Estacion'] . ' (' . $responsable['cveest'] . ')',
                        'Alta' => $responsable['logfch'],
                        'Responsable' => $responsable['rsp'],
                        'Acciones' => $actions,
                    ];
                }, $responsables);
            }
            json_output(array("data" => $data));
        }

    }

    function islands() : void {
        echo $this->twig->render($this->route . 'islands.html');
    }

    function islands_table() : void {
        $data = [];
        if($islands = $this->islandModel->get_isles()) {
            foreach ($islands as $island) {
                $data[] = array(
                    'Id' => $island['cod'],
                    'Isla' => $island['Isla'],
                    'CodEst' => $island['codgas'],
                    'Estacion' => $island['Estacion'],
                    'Producto' => $island['FuelType'],
                );
            }
        }
        json_output(array("data" => $data));
    }

    /**
     * @return void
     */
    function sales_table() : void {

        $data = [];
        $from   = $_GET['from'];
        $until  = $_GET['until'];

        $sales  = [];

        for ($i=$from; $i <= $until; $i++) {
            $response = $this->ventasModel->get_sales($i);

            $response[0]['Producto'] = 'Diesel Automotriz';
            $response[1]['Producto'] = 'T-Maxima Regular';
            $response[2]['Producto'] = 'T-Super Premium';
            $sales[] = $response;
        }
        foreach ($sales as $sale) {
            foreach ($sale as $s) {
                // Crear un objeto DateTime a partir de la cadena de fecha
                $date = DateTime::createFromFormat('Y-m-d H:i:s.u', $s['Fecha']);
                $data[] = array(
                    'Fecha'               => $date->format('Y-m-d'),
                    'Producto'            => (isset($s['Producto']) ? $s['Producto'] : 'N/A'),
                    '02 LERDO'            => $s["02 LERDO"],
                    '03 DELICIAS'         => $s["03 DELICIAS"],
                    '04 PARRAL'           => $s["04 PARRAL"],
                    '05 LOPEZ MATEOS'     => $s["05 LOPEZ MATEOS"],
                    '06 GEMELA CHICA'     => $s["06 GEMELA CHICA"],
                    '07 GEMEL GRANDE'     => $s["07 GEMEL GRANDE"],
                    '08 PLUTARCO'         => $s["08 PLUTARCO"],
                    '09 MPIO'             => $s["09 MPIO. LIBRE"],
                    '10 AZTECAS'          => $s["10 AZTECAS"],
                    '11 MISIONES'         => $s["11 MISIONES"],
                    '12 PTO DE PALOS'     => $s["12 PTO DE PALOS"],
                    '13 MIGUEL D MAD'     => $s["13 MIGUEL D MAD"],
                    '14 PERMUTA'          => $s["14 PERMUTA"],
                    '15 ELECTROLUX'       => $s["15 ELECTROLUX"],
                    '16 AERONAUTICA'      => $s["16 AERONAUTICA"],
                    '17 CUSTODIA'         => $s["17 CUSTODIA"],
                    '18 ANAPRA'           => $s["18 ANAPRA"],
                    '19 INDEPENDENCI'     => $s["19 INDEPENDENCI"],
                    '20 TECNOLOGICO'      => $s["20 TECNOLOGICO"],
                    '21 EJERCITO NAL'     => $s["21 EJERCITO NAL"],
                    '22 SATELITE'         => $s["22 SATELITE"],
                    '23 LAS FUENTES'      => $s["23 LAS FUENTES"],
                    '24 CLARA'            => $s["24 CLARA"],
                    '25 SOLIS'            => $s["25 SOLIS"],
                    '26 SANTIAGO TRO'     => $s["26 SANTIAGO TRO"],
                    '27 JARUDO'           => $s["27 JARUDO"],
                    '28 HERMANOS ESC'     => $s["28 HERMANOS ESC"],
                    '29 VILLA AHUMAD'     => $s["29 VILLA AHUMAD"],
                    '30 EL CASTAÑO'       => $s["30 EL CASTAÑO"],
                    '31 Travel Center'    => $s["31 TRAVEL CENTE"],
                    '32 Picachos'         => $s["32 Picachos"],
                    '33 Ventanas'         => $s["33 Ventanas"],
                    '34 SAN RAFAEL'       => $s["34 SAN RAFAEL"],
                    '35 PUERTECITO'       => $s["35 PUERTECITO"],
                    '36 JESUS MARIA'      => $s["36 JESUS MARIA"],
                    '37 GABRIELA MIS' => $s["37 GABRIELA MIS"],
                    '38 PRAXEDIS'         => $s["38 PRAXEDIS"],
                    'Total'               => ($s["02 LERDO"] + $s["03 DELICIAS"] + $s["04 PARRAL"] + $s["05 LOPEZ MATEOS"] + $s["06 GEMELA CHICA"] + $s["07 GEMEL GRANDE"] + $s["08 PLUTARCO"] + $s["09 MPIO. LIBRE"] + $s["10 AZTECAS"] + $s["11 MISIONES"] + $s["12 PTO DE PALOS"] + $s["13 MIGUEL D MAD"] + $s["14 PERMUTA"] + $s["15 ELECTROLUX"] + $s["16 AERONAUTICA"] + $s["17 CUSTODIA"] + $s["18 ANAPRA"] + $s["19 INDEPENDENCI"] + $s["20 TECNOLOGICO"] + $s["21 EJERCITO NAL"] + $s["22 SATELITE"] + $s["23 LAS FUENTES"] + $s["24 CLARA"] + $s["25 SOLIS"] + $s["26 SANTIAGO TRO"] + $s["27 JARUDO"] + $s["28 HERMANOS ESC"] + $s["29 VILLA AHUMAD"] + $s["30 EL CASTAÑO"] + $s["31 TRAVEL CENTE"] + $s["32 Picachos"] + $s["33 Ventanas"] + $s["34 SAN RAFAEL"] + $s["35 PUERTECITO"] + $s["36 JESUS MARIA"] + $s["37 GABRIELA MIS"] + $s["38 PRAXEDIS"]),
                );
            }
        }
        json_output(array("data" => $data));
    }


    /**
     * @param $codest
     * @return void
     */
    function consultDispatchModal($codest) : void {
        $modal = [
            "title"    => "Consulta de despacho",
            "size"     => "modal-xl",
            "position" => "modal-dialog-centered",
            "content"  => $this->twig->render($this->route . 'modals/consultDispatchModal.html', compact('codest'))
        ];
        json_output($modal);
    }

    /**
     * @return void
     * @throws Exception
     */
    function consultDispatchForm() : void {
        $dispatch = $this->despachosModel->get_dispatch_by_transaction($_POST['dispatch'], $_POST['codest']);
        json_output($dispatch);
    }

    /**
     * @param $IdTabulador
     * @param $IdRecolecta
     * @return void
     * @throws Exception
     */
    function details_deposit($IdTabulador, $IdRecolecta) : void {
        $tabular      = $this->tabulatorModel->get_tabulator($IdTabulador);
        $deposit      = $this->tabuladorRecolectasModel->get_deposit($IdRecolecta, $IdTabulador);
        $denominations = $this->tabuladorDenominacionesModel->getDenominationsByTabularDeposit($IdRecolecta, $IdTabulador);
        $modal = [
            "title"    => "Detalle de depósito",
            "size"     => "modal-md",
            "position" => "modal-dialog-centered",
            "content"  => $this->twig->render($this->route . 'modals/details_deposit.html', compact('tabular', 'deposit', 'denominations'))
        ];
        json_output($modal);
    }

    function print_deposit($IdTabulador, $IdRecolecta) : void {
        $tabulator = $this->tabulatorModel->get_tabulator($IdTabulador);
        $station = $this->estacionesModel->get_station($tabulator['CodigoEstacion']);
        $deposit      = $this->tabuladorRecolectasModel->get_deposit($IdRecolecta, $IdTabulador);
        $denominations = $this->tabuladorDenominacionesModel->getDenominationsByTabularDeposit($IdRecolecta, $IdTabulador);

        $pdf = new PDF_Code128();
        $pdf->AddPage('P');
        // Recuadro de la fotografia
        $pdf->Image($_SERVER['DOCUMENT_ROOT']. '/_assets/images/logos/logo_TotalGas_hor.png', 145, 8, 56, 15);

        $pdf->SetFont('Helvetica','B',22);
        $pdf->SetTextColor(21, 67, 145);
        // Agregaremos texto vertical
        $pdf->Cell(105,5,'', 0, 1, 'L');
        $pdf->Cell(105,12,mb_convert_encoding('Estación: ' . $station['Nombre'], 'ISO-8859-1'), 0, 1, 'L');
        $pdf->SetFont('Helvetica','',10);
        $pdf->SetTextColor(50, 50, 50);
        $pdf->Cell(105,5,mb_convert_encoding($tabulator['Domicilio'], 'ISO-8859-1'), 0, 1, 'L');

        // Ahora vamos a pintar una linea de color azul
        $pdf->SetDrawColor(21, 67, 145);
        $pdf->SetLineWidth(0.2);
        $pdf->Line(10, 40, 200, 40);

        $pdf->Ln(10);
        $pdf->SetFont('Helvetica','',12);
        $pdf->SetTextColor(21, 67, 145);
        $pdf->cell(66.66, 5, mb_convert_encoding('Turno ' . substr($tabulator['Turno'], 0, 1) . ' - Corte ' . substr($tabulator['Turno'], 1, 1), 'ISO-8859-1'), 0, 0, 'L');
        $pdf->cell(66.66, 5, 'Responsable', 0, 0, 'L');
        $pdf->cell(66.66, 5, 'Importes', 0, 1, 'L');

        $pdf->SetFont('Helvetica','',10);
        $pdf->SetTextColor(50, 50, 50);
        $pdf->cell(66.66, 5, mb_convert_encoding($deposit["Descripcion"], 'ISO-8859-1'), 0, 0, 'L');
        $pdf->cell(66.66, 5, mb_convert_encoding($_SESSION['tg_user']['Nombre'], 'ISO-8859-1'), 0, 0, 'L');
        if ($deposit['Moneda'] == 'MXN') {
            $pdf->cell(66.66, 5, 'Total: $' . number_format($deposit['Total'], 2), 0, 1, 'L');
        } else {
            $total = 0;
            foreach ($denominations AS $denomination) {
                $total += ($denomination['Cantidad'] * $denomination['Valor']);
            }
            $pdf->cell(66.66, 5, 'Total: $' . number_format($total, 2), 0, 1, 'L');
        }
        $pdf->cell(66.66, 5, $deposit['Fecha'], 0, 0, 'L');
        $pdf->cell(66.66, 5, 'Puesto: ' . mb_convert_encoding($_SESSION['tg_user']['profile'], 'ISO-8859-1'), 0, 0, 'L');
        $pdf->cell(66.66, 5, 'Moneda: ' . $deposit['Moneda'], 0, 1, 'L');

        // Ahora vamos a pintar una linea de color azul
        $pdf->SetDrawColor(21, 67, 145);
        $pdf->SetLineWidth(0.2);
        $pdf->Line(10, 59, 200, 59);


        $pdf->cell(190, 8, '', 0, 1, 'L');

        // Ahora vamos a pintar una linea de color azul
        $pdf->SetDrawColor(21, 67, 145);
        $pdf->SetLineWidth(0.2);
        $pdf->Line(10, 65, 200, 65);

        // Ajustamos el texto
        $pdf->SetFont('Helvetica','',12);
        $pdf->SetTextColor(21, 67, 145);
        $pdf->cell(48, 7, mb_convert_encoding('Descripción', 'ISO-8859-1'), 'TB', 0, 'L');
        $pdf->cell(33, 7, mb_convert_encoding('Moneda', 'ISO-8859-1'), 'TB', 0, 'L');
        $pdf->cell(38, 7, mb_convert_encoding('Denominación', 'ISO-8859-1'), 'TB', 0, 'L');
        $pdf->cell(33, 7, mb_convert_encoding('Cantidad', 'ISO-8859-1'), 'TB', 0, 'L');
        $pdf->cell(38, 7, mb_convert_encoding('Subtotal', 'ISO-8859-1'), 'TB', 1, 'L');

        foreach ($denominations AS $denomination) {
            $pdf->SetFont('Helvetica','',10);
            $pdf->SetTextColor(50, 50, 50);
            $pdf->cell(48, 7, mb_convert_encoding($denomination['Descripcion'], 'ISO-8859-1'), 0, 0, 'L');
            $pdf->cell(33, 7, mb_convert_encoding($denomination['Moneda'], 'ISO-8859-1'), 0, 0, 'L');
            $pdf->cell(38, 7, mb_convert_encoding($denomination['Valor'], 'ISO-8859-1'), 0, 0, 'L');
            $pdf->cell(33, 7, mb_convert_encoding($denomination['Cantidad'], 'ISO-8859-1'), 0, 0, 'L');
            $pdf->cell(38, 7, '$' . number_format(($denomination['Cantidad'] * $denomination['Valor']), 2), 0, 1, 'L');
        }
        // Agregamos una linea de color azul
        $pdf->cell(190, 3, '', 'B', 1, 'L');
        // Vamos a agregar el total
        $pdf->SetFont('Helvetica','B',12);
        $pdf->SetTextColor(21, 67, 145);
        $pdf->cell(48, 7, '', 0, 0, 'L');
        $pdf->cell(33, 7, '', 0, 0, 'L');
        $pdf->cell(38, 7, '', 0, 0, 'L');
        $pdf->cell(33, 7, 'Total', 0, 0, 'L');
        if ($deposit['Moneda'] == 'MXN') {
            $pdf->cell(38, 7, '$' . number_format($deposit['Total'], 2), 0, 1, 'L');
        } else {
            $pdf->cell(38, 7, '$' . number_format($total, 2), 0, 1, 'L');
        }


        // Fondo de la hoja vamos a agregar una linea para que el encargado firme
        $pdf->setxy(10, 250);
        $pdf->cell(50, 3, '', 0, 0, 'L');    // Agregamos una linea de color azul
        $pdf->cell(90, 8, mb_convert_encoding($_SESSION['tg_user']['Nombre'], 'ISO-8859-1'), 'T', 0, 'C');    // Agregamos una linea de color azul
        $pdf->cell(50, 3, '', 0, 1);    // Agregamos una linea de color azul

        // Ahora vamos a poner un mensaje legal
        $pdf->SetFont('Helvetica','',7);
        $pdf->SetTextColor(21, 67, 145);
        $pdf->setxy(10, 260);
        $pdf->multicell(190, 5, mb_convert_encoding('El responsable de esta transacción certifica la veracidad y exactitud de los depósitos realizados. Asimismo, se hace responsable de cualquier error o inconsistencia en la información proporcionada. Este documento es una muestra fiel de la transacción realizada y se utilizará como comprobante en caso de ser necesario. Cualquier falsificación o alteración de la información contenida en este documento será sancionada de acuerdo a las leyes y regulaciones aplicables.', 'ISO-8859-1'), 0, 'C');
        $pdf->Output();
    }

    /**
     * @param $codest
     * @return void
     * @throws Exception
     */
    function registerDismissModal($codest, $mojo_access_key) : void {
        $station = $this->estacionesModel->get_station($codest);
        $responsables = $this->responsablesModel->get_responsables_by_station($codest);
        $modal = [
            "title"    => "Alta/Baja de colaborador",
            "size"     => "modal-md",
            "position" => "modal-dialog-centered",
            "content"  => $this->twig->render($this->route . 'modals/registerDismissModal.html', compact('codest', 'responsables', 'station', 'mojo_access_key'))
        ];
        json_output($modal);
    }

    function tabInfoModal($codest, $tabId) : void {
        $station = $this->estacionesModel->get_station($codest);
        $tabulator = $this->tabulatorModel->get_tabulator($tabId);

        $tabulator['FechaInt'] = dateToInt($tabulator['FechaTabular']);
        $modal = [
            "title"    => "Información del tabulador",
            "size"     => "modal-sm",
            "position" => "modal-dialog-centered",
            "content"  => $this->twig->render($this->route . 'modals/tabInfoModal.html', compact( 'tabulator', 'station'))
        ];
        json_output($modal);
    }

    function islandInfoModal($tabId, $islandId) : void {

        $station_server     = $_GET['station_server'];
        $fechaTabularInt    = $_GET['fechaTabularInt'];
        $CodigoEstacion     = $_GET['CodigoEstacion'];
        $Turno              = $_GET['Turno'];

        $data = $this->movimientosTarModel->get_total_islands($fechaTabularInt, $CodigoEstacion, $islandId, $Turno, $tabId);

        $readings = $this->getInitialReadingsByIslands($CodigoEstacion, $fechaTabularInt, $Turno, $islandId);

        $modal = [
            "title"    => "Ventas de la isla",
            "size"     => "modal-xl",
            "position" => "modal-dialog-centered",
            "content"  => $this->twig->render($this->route . 'modals/islandInfoModal.html', compact( 'data', 'readings'))
        ];
        json_output($modal);
    }

    function registerForm() : void {
        $body = '
        <h4>Registro de Nuevo Empleado</h4>
        <p>Se realiza la solicitud para el ingreso del siguiente empleado en el sistema Tabulador y ControlGas.</p>
        <ul class="list-group">
            <li class="list-group-item">Nombre: '. $_POST['clock'] . ' ' . $_POST['name'] .' '. $_POST['lastname'] .'</li>
            <li class="list-group-item">No de reloj: '. $_POST['clock'] .'</li>
            <li class="list-group-item">Estación: Id '. $_POST['codgas'] .' - '. $_POST['station'] .'</li>
            <li class="list-group-item">Fecha solicitud: '. date('Y-m-d H:i:s') .'</li>
            <li class="list-group-item">Comentarios: '. $_POST['comments'] .'</li>
        </ul>
        ';

        $body .= '<p>Agradecemos su atención y colaboración en este proceso. Saludos cordiales</p>';

        $this->open_mojo_ticket('Registro de responsable de bombas', $body, $_POST['email']);

        if (send_mail('Alta de empleado',$body,['aldo.ochoa@totalgas.com'],'totalgasdesarrollo@gmail.com')) {
            setFlashMessage('success','Se ha enviado un correo electrónico al departamento de Sistemas para que se dé de alta al colaborador. El alta se realizará en un plazo de 24 horas.');
        } else {
            setFlashMessage('error','No se pudo enviar el correo electrónico. Por favor, intente más tarde.');
        }
        redirect();
    }

    function open_mojo_ticket($title, $description, $user) {
        // Datos para la solicitud
        $data = array(
            'title' => $title,
            'description' => $description,
            'ticket_queue_id' => '53551', // Sistemas
            'priority_id' => '30', // Normal
            'assigned_to_id' => '5061558', // Aldo Ochoa
            'suppress_user_notification' => true,
            'ticket_form_id' => '51598',
            'user' => array(
                'email' => $user
            ),
            'custom_field_solicitante' => $user,
            'custom_field_area_o_departamento' => $user,
            'custom_field_problema' => 'Tabulador - Registro Colaborador',
        );

        // Convertir datos a formato JSON
        $jsonData = json_encode($data);

        // URL de la API y clave de acceso
        $url = 'https://totalgas.mojohelpdesk.com/api/v2/tickets?access_key=f68cddda794b0bf9582c23b7b3099011d95c60ce';

        // Configurar la solicitud cURL
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        // Ejecutar la solicitud y obtener la respuesta
        $response = curl_exec($ch);
        // Verificar si hubo errores
        if ($response === false) {
            echo 'Error al realizar la solicitud: ' . curl_error($ch);
        }
        curl_close($ch);
    }

    function dismissForm() : void {
        $employee = $this->responsablesModel->get_responsable($_POST['responsable_id']);
        $body = '
        <h3>Baja de empleado</h3>
        <h4>Se realiza la solicitud para la baja del siguiente empleado en el Aplicativo web y el sistema ControlGas.</h4>
        <ul class="list-group">
            <li class="list-group-item">Nombre: '. $employee['Nombre'] .'</li>
            <li class="list-group-item">Id del empleado: '. $employee['Codigo'] .'</li>
            <li class="list-group-item">Estación: Id '. $_POST['codgas'] .' - '. $_POST['station'] .'</li>
            <li class="list-group-item">Fecha solicitud: '. date('Y-m-d H:i:s') .'</li>
            <li class="list-group-item">Comentarios: '. $_POST['comments'] .'</li>
        </ul>
        ';
        $body .= '<p>Agradecemos su atención y colaboración en este proceso. Saludos cordiales.</p>';

        $ticket_id = $this->open_mojo_ticket('Baja de responsable de bombas', $body, $_POST['email']);

        if (send_mail('Baja de empleado',$body,['aldo.ochoa@totalgas.com'],'totalgasdesarrollo@gmail.com')) {
            setFlashMessage('success','Se ha enviado un correo electrónico al departamento de Sistemas para que se dé de baja al colaborador. La baja se realizará en un plazo de 24 horas.');
        } else {
            setFlashMessage('error','No se pudo enviar el correo electrónico. Por favor, intente más tarde.');
        }
        redirect();
    }

    // function getExchange() : void {
    //     $exchange = $this->tabulatorModel->get_exchange();
    //     json_output($exchange);
    // }

    function responsableModal($cod = 0, $codgas = 0) : void {
        if (preg_match('/GET/i',$_SERVER['REQUEST_METHOD'])){
            $stations = $this->gasolinerasModel->get_active_stations();
            $responsable = ($cod == 0 ? false : $this->responsablesModel->get_responsable($cod));
            $modal = [
                "title"    => "Alta de responsable",
                "size"     => "modal-xl",
                "position" => "modal-dialog-centered",
                "content"  => $this->twig->render($this->route . 'modals/responsableModal.html', compact( 'stations', 'responsable'))
            ];
            json_output($modal);
        } else {
            // Vamos a recopilar la información:
            $data = [
                'Nombre' => strtolower($_POST['clock'] . ' ' . trim($_POST['name'])), 'Codigo' => $_POST['codext'], 'NoReloj' => $_POST['clock'], 'Puesto' => $_POST['pto'], 'Estacion' => $_POST['IdEstacion'], 'Status' => $_POST['status']
            ];
            if ($_POST['action'] == 'editar') {
                if ($this->responsablesModel->update($data, intval($codgas))) {
                    setFlashMessage('success','Se ha actualizado al responsable correctamente');
                } else {
                    setFlashMessage('error','No se pudo actualizar al responsable');
                }
            } else {
                if ($this->responsablesModel->insert($data)) {
                    setFlashMessage('success','Se ha registrado al responsable correctamente');
                } else {
                    setFlashMessage('error','No se pudo registrar al responsable');
                }
            }
            redirect();
        }
    }

    /**
     * @param $cod
     * @return void
     */
    function deactivate_responsable($cod, $hab) : void {
        // Si la variable $hab es 1 lo pasamos a cero, si es cero lo pasamos a uno
        $hab = ($hab == 1 ? 0 : 1);
        if ($this->responsablesModel->deactivate($cod, $hab)) {
            json_output(['status' => 'success', 'message' => 'Se ha desactivado al responsable correctamente']);
        } else {
            json_output(['status' => 'error', 'message' => 'No se pudo desactivar al responsable']);
        }
    }

    /**
     * @param $cod
     * @return void
     */
    function delete_responsable($cod) : void {

        if ($this->responsablesModel->delete($cod)) {
            json_output(['status' => 'success', 'message' => 'Se ha eliminado el registro del responsable correctamente']);
        } else {
            json_output(['status' => 'error', 'message' => 'No se pudo eliminar el registro del responsable']);
        }
    }

    function monitor() :void {
        $from = $_GET['from'] ?? date('Y-m-d');
        echo $this->twig->render($this->route . 'monitor.html', compact('from'));
    }

    function monitor_table() :void {
        if (preg_match('/POST/i',$_SERVER['REQUEST_METHOD'])){
            $data = [];
            if ($estaciones = $this->estacionesModel->sp_monitor_tabulador($_POST['from'])) {
                foreach ($estaciones as $estacion) {
                    $data[] = [
                        'Fecha' => strval($_POST['from']),
                        'Zona' => $estacion['Zona'],
                        'Estacion' => $estacion['NombreEstacion'],
                        'Turno_11' => "<p class='m-0 p-0 fw-bold ". ($estacion['Turno_11'] == 'Si' ? 'text-success' : 'text-danger') ."'>{$estacion['Turno_11']}</p><p class='m-0 p-0'>Hora: {$estacion['FechaCreacion_11']}</p><p class='m-0 p-0'>Tabulado: $". number_format($estacion['Total_11'], 2) ."</p>",
                        'Turno_21' => "<p class='m-0 p-0 fw-bold ". ($estacion['Turno_21'] == 'Si' ? 'text-success' : 'text-danger') ."'>{$estacion['Turno_21']}</p><p class='m-0 p-0'>Hora: {$estacion['FechaCreacion_21']}</p><p class='m-0 p-0'>Tabulado: $". number_format($estacion['Total_21'], 2) ."</p>",
                        'Turno_31' => "<p class='m-0 p-0 fw-bold ". ($estacion['Turno_31'] == 'Si' ? 'text-success' : 'text-danger') ."'>{$estacion['Turno_31']}</p><p class='m-0 p-0'>Hora: {$estacion['FechaCreacion_31']}</p><p class='m-0 p-0'>Tabulado: $". number_format($estacion['Total_31'], 2) ."</p>",
                        'Turno_41' => "<p class='m-0 p-0 fw-bold ". ($estacion['Turno_41'] == 'Si' ? 'text-success' : 'text-danger') ."'>{$estacion['Turno_41']}</p><p class='m-0 p-0'>Hora: {$estacion['FechaCreacion_41']}</p><p class='m-0 p-0'>Tabulado: $". number_format($estacion['Total_41'], 2) ."</p>",
                    ];
                }
            }
            json_output(array("data" => $data));
        }
    }

    function open_tabulator() : void {
        $tabId           = $_POST['tabulator_id'];
        $turno           = $_POST['turno'];
        $fecha           = $_POST['fecha_tabular'];
        $db_string       = $_POST['db_string'];

        // Vamos a verificar si el tabulador ya fue cerrado
        if ($this->tabulatorModel->is_closed_controlgas($turno, dateToInt($fecha), $db_string)) {
            json_output(['status' => 'error', 'message' => 'No es posible abrir el tabulador. El turno ya fue cerrado en ControlGas']);
        } else {
            // Vamos a abrir el tabulador
            if ($this->tabulatorModel->open_tabulator($tabId)) {
                json_output(['status' => 'success', 'message' => 'El tabulador ha sido abierto']);
            } else {
                json_output(['status' => 'error', 'message' => 'No se pudo abrir el tabulador']);
            }
        }
    }

    function get_responsables($codgas) {
        echo json_encode($this->responsablesModel->get_responsables_by_station($codgas));
    }

    function assign_responsable($responsable_id) {

    }

    function getInitialReadingsByIslandsAjax($CodigoEstacion, $FechaTabular, $Turno, $Isle, $tabId) {

        $FechaTabular = dateToInt($FechaTabular);
        $shifts = [11, 21, 31, 41];
        $index = array_search($Turno, $shifts);

        $previous_shift = $shifts[($index + count($shifts) - 1) % count($shifts)];

        if ($previous_shift == 41) {
            $previous_date = ($FechaTabular - 1);
        } else {
            $previous_date = $FechaTabular;
        }

        $data = [];
        $results = $this->despachosModel->get_saldos_isla($CodigoEstacion, $previous_date, $previous_shift, $Isle, $FechaTabular, $Turno, $tabId);

        // Ahora vamos a obtener los saldos iniciales de la isla que se va a tabular
        foreach ($results as $key => $value) {
            if ($value['codisl'] == $Isle) {
                $data['Diferencia'] = number_format($value['Diferencia'], 2, '.', ',');
                $data['Isla'] = $value['Isla'];
            }
        }

        // Vamos a returnar los resultados en formato json
        echo json_encode($data);
    }

    function check_turno_status($Turno, $FechaTabular, $CodigoEstacion) {
        if (in_array($Turno, [21,31,41])) {
            $status = $this->tabulatorModel->check_turno_status((intval($Turno)-10), $FechaTabular, $CodigoEstacion);
        } else {
            $Turno = 41;
            $FechaAyer = date('Y-m-d', strtotime($FechaTabular . ' - 1 day'));
            $status = $this->tabulatorModel->check_turno_status($Turno, $FechaAyer, $CodigoEstacion);
        }

        if ($status) {
            json_output(['status' => 'open']);
        } else {
            json_output(['status' => 'closed']);
        }
    }

    function inventories() : void {
        // Primero verificamos si la variable $_GET['from'] está definida
        $from = $_GET['from'] ?? date('Y-m-d', strtotime('-1 day'));
        $until = $_GET['until'] ?? date('Y-m-d', strtotime('-1 day'));

        echo $this->twig->render($this->route . 'inventories.html', compact('from', 'until'));
    }

    function inventories_table() {
        $data = [];
        if ($inventories = $this->ventasModel->get_inventories(dateToInt($_GET['from']), dateToInt($_GET['until']))) {
            foreach ($inventories as $row) {
                $actions = '<a class="btn btn-primary" href="/operations/details/'. dateToInt($_GET['from']) .'/'. dateToInt($_GET['until']) .'/'. $row['codgas'] .'/'. $row['codprd'] .'" role="button" target="_blank"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-eye align-middle"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg></a>';
                $data[] = [
                    'ESTACION'     => $row['Estacion'],
                    'PRODUCTO'     => $row['Producto'],
                    'SALDOINICIAL' => $row['SaldoInicial'],
                    'COMPRAS'      => $row['Compras'],
                    'VENTAS'       => $row['Ventas'],
                    'SALDOFINAL'   => $row['SaldoFinal'],
                    'SALDOREAL'    => $row['SaldoReal'],
                    'MERMA'        => $row['Merma'],
                    'ACCIONES'     => $actions,
                ];
            }
        }

        json_output(array("data" => $data));
    }

    function details($from, $until, $codgas, $codprd) : void {
        echo $this->twig->render($this->route . 'details.html', compact('from', 'until', 'codgas', 'codprd'));
    }

    function inventories_details_table() {
        $data = [];
        $inventory = $this->ventasModel->get_details($_GET['from'], $_GET['until'], $_GET['codgas'], $_GET['codprd']);
        unset($inventory[0]);
        if ($inventory) {
            foreach($inventory as $row) {
                $data[] = array(
                    'FECHA'        => $row['Fecha'],
                    'ESTACION'     => $row['Estacion'],
                    'PRODUCTO'     => $row['Producto'],
                    'SALDOINICIAL' => $row['SdoInicial'],
                    'COMPRAS'      => $row['Compras'],
                    'VENTAS'       => $row['Ventas'],
                    'SALDO'        => $row['Saldo_Final'],
                    'SALDOREAL'    => $row['SaldoReal'],
                    'MERMA'        => $row['Merma']
                );
            }
        }
        json_output(array("data" => $data));
    }
    public function SalesHourCash() : void {
        // Imprimimos el segmento de red del cliente
        $stations = $this->gasolinerasModel->get_active_stations();
        $stations= array_filter($stations, function($station) {
            return $station['cod'] != '0';
        });
        $stations = array_values($stations); // Reindexar el array después de filtrar
       
        echo $this->twig->render($this->route . 'SalesHourCash.html', compact('stations'));
    }

    function sales_cash_hour_table(){
        $dinamicColumns = $_POST['dinamicColumns'];
        $rows = $this->despachosModel->sales_cash_hour_table($_POST['fromDate'], $_POST['untilDate'], $_POST['codgas']);
        
        $data=[];
        foreach ($rows as $key => $row) {
            $entry=[];
                foreach ($dinamicColumns as $key => $column) {
                    $colun_name = $column['data'];

                    $entry[$colun_name] =  round($row[$colun_name],2);

                }
            $data[] = $entry;
        }
        echo json_encode(array("data" => $data));
    }

    public function get_estations() {
        $estations = $this->gasolinerasModel->get_estations_servidor();
        echo json_encode($estations);
    }
    public function sales_day_table(){
        $data = [];
        $from = $_POST['fromDate'];
        $until = $_POST['untilDate'];
        $shift = $_POST['shift'];
        $id_producto= $_POST['id_producto'];
        $estaciones = $this->gasolinerasModel->get_estations_servidor();
        $day=0;
        if ( $sales = $this->ventasModel->get_sales_day_product($from, $until,$shift,$id_producto,$estaciones)) {
            foreach($sales as $row) {
                $entry = [
                    'year'    => $row['year'],
                    'mounth'  => $row['mounth'],
                    'day'     => $row['day1'],
                    'turn'    => $row['turn'],
                    'product' => $row['product'],
                    'codprd'  => $row['codprd'],
                ];
                foreach ($estaciones as $estacion) {
                    $cod = $estacion['codigo'];

                    $entry[$cod] = round($row[$cod],3)  ?? 0;
                }
                $data[] = $entry;

            }
        }
        json_output(array("data" => $data));
    }
    public function sale_day_base_table() {
        if ($rows = $this->ventas->GetSalesDayTurnBase($_POST['fromDate'], $_POST['untilDate'], $_POST['zona'])) {
            foreach ($rows as $row) {
                $data[] = array(
                    'Fecha'         => $row['Fecha'],
                    'year'          => $row['year'],
                    'mounth'        => $row['mounth'],
                    'day'           => $row['day'],
                    'CodGasolinera' => $row['CodGasolinera'],
                    'turn'          => $row['turn'],
                    'VentasReales'  => number_format($row['VentasReales'], 2),
                    'Producto'      => $row['Producto'],
                    'Estacion'      => $row['Estacion'],
                );
            }
            $data = array("data" => $data);
            echo json_encode($data);
        } else {
            echo json_encode(["data" => []]); // Devuelve un array vacío si no hay datos
        }
    }
    public function sales_day_table_shif(){
        $data = [];
        $from = $_POST['fromDate'];
        $until = $_POST['untilDate'];
        $shift = $_POST['shift'];
        $id_producto= $_POST['id_producto'];
        $estaciones = $this->gasolinerasModel->get_estations_servidor();
        $day=0;
        if ( $sales = $this->ventasModel->get_sales_day_trn($from, $until,$shift,$id_producto,$estaciones)) {
            foreach($sales as $row) {
                $entry = [
                    'year'    => $row['year'],
                    'mounth'  => $row['mounth'],
                    'day'     => $row['day1'],
                    'turn'    => $row['turn'],
                ];
                foreach ($estaciones as $estacion) {
                    $cod = $estacion['codigo'];

                    $entry[$cod] = round($row[$cod],3)  ?? 0;
                }
                $data[] = $entry;

            }
        }
        json_output(array("data" => $data));
    }

    function ballot() {
        $codest = $_GET['codigoestacion'];
        $fecha = $_GET['fechatabular'];
        $turno = $_GET['turno'];
        $exchange_now = $_GET['exchange_now'];
        $tabId = $_GET['tabId'];
        $json = [
            'html'  => $this->twig->render($this->route . 'ballotTab.html', compact('codest', 'fecha', 'turno', 'exchange_now', 'tabId'))
        ];


        json_output($json);
    }

    function formBallot() {
        $rem = $_POST['rem'];
        $tipo = $_POST['tipo'];
        $cambio = $_POST['cambio'];
        $sello = $_POST['sello'];
        $fajilla = $_POST['fajilla'];
        $fecha = $_POST['fecha'];
        $turno = $_POST['turno'];
        $tabId = $_POST['tabId'];
        $codest = $_POST['codest'];

        $this->ballotModel->IdTabulador = $tabId;
        $this->ballotModel->NoRemision = $rem;
        $this->ballotModel->Moneda = $tipo;
        $this->ballotModel->CodEstacion = $codest;
        $this->ballotModel->TipoCambio = $cambio;
        $this->ballotModel->SeguriSello = $sello;
        $this->ballotModel->CantidadFajillas = $fajilla;
        $this->ballotModel->FechaCreacion = $fecha;
        $this->ballotModel->Turno = $turno;
        $this->ballotModel->Usuario = $_SESSION['tg_user']['Id'];

        if ($this->ballotModel->save()) {
            setFlashMessage('success', 'Se ha creado la papeleta correctamente');
        } else {
            setFlashMessage('error', 'No fue posible crear la papaleta');
        }
        redirect();
    }

    function getvalues ($tipo, $codest) {
        $res = $this->ballotModel->getvalues($tipo, $codest);
    }

    function ballot_table($tabId) {
        $data = [];
        $ballots = $this->ballotModel->get_ballots($tabId);

        // Por cada ballot
        foreach ($ballots as $ballot) {
            $data[] = [
                'NoRemision' => $ballot['NoRemision'],
                'Tipo' => $ballot['Moneda'],
                'Segurisello' => $ballot['SeguriSello'],
                'Fajillas' => $ballot['CantidadFajillas'],
                'Monto' => $ballot['Monto'],
                'Acciones' => '<a href="/operations/print_ballot/'. $ballot['Id'] .'" class="btn btn-sm btn-primary">Imprimir</a>'
            ];
        }

        return json_output(array("data" => $data));
    }
    
    function print_ballot($id) {
        $ballot = $this->ballotModel->get_ballot_by_id($id);
        $datosEstacion = $this->ballotModel->get_station_data($ballot[0]['CodEstacion']);
        $tabulator = $this->tabulatorModel->get_tabulator($ballot[0]['IdTabulador']);
        $date = $ballot[0]['FechaCreacion'];
        $formatDate = (new DateTime($date))->format('Y-m-d');
        $formatDate2 = (new DateTime($tabulator['FechaTabular']))->format('Ymd');
        $turno = strval($ballot[0]['Turno'])[0];

        $valores = $this->ballotModel->get_ballot_values($formatDate2, $turno, $ballot[0]['CodEstacion']);

        $moneda = ($ballot[0]['Moneda'] == 'MXN') ? $valores[0] : $valores[1];

        function numeroALetras($numero) {
            $formatter = new NumberFormatter("es", NumberFormatter::SPELLOUT);
            return ucfirst($formatter->format($numero));
        }

        $monto = $moneda['Monto'];
        $tipoCambio = $ballot[0]['TipoCambio'];
        $montoDolares = $monto / $tipoCambio;

        //si el monto es usd imprimir monto en letra de dlls
        if ($ballot[0]['Moneda'] == 'MXN') {
            $partes = explode('.', number_format($monto, 2, '.', ''));
            $parteEntera = (int) $partes[0];
            $parteDecimal = isset($partes[1]) ? $partes[1] : '00';
            $montoLetra = numeroALetras($parteEntera) . " con " . $parteDecimal . "/100 MN";
        } else {
            $partes = explode('.', number_format($montoDolares, 2, '.', ''));
            $parteEntera = (int) $partes[0];
            $parteDecimal = isset($partes[1]) ? $partes[1] : '00';
            $montoLetra = numeroALetras($parteEntera) . " con " . $parteDecimal . "/100 USD";
        }

        $pdf = new PDF_Code128();
        $pdf->AddPage('P');
        //$pdf->Image('_assets/images/papeletaDE.jpg', 0, 0, 210, 297, 'JPG');
        $pdf->SetFont('Arial','',9);
        $pdf->SetTextColor(0,0,0);

        $pdf->setxy(12, 27);
        $pdf->Cell(65.5,10,$datosEstacion[0]['NoCuenta'], 0, 0, 'L');

        if (in_array($ballot[0]['Turno'], [11,21])) {
            $pdf->setxy(73, 18);
            $pdf->Cell(65.5,10,'X', 0, 0, 'L');//MAT
        } elseif (in_array($ballot[0]['Turno'], [31,41])) {
            $pdf->setxy(97, 18);
            $pdf->Cell(65.5,10,'X', 0, 0, 'L');//VESP
        }

        $pdf->setxy(11, 18);
        $pdf->Cell(65.5, 10, $formatDate, 0, 0, 'L');
        $pdf->setxy(40, 18);
        $pdf->Cell(65.5, 10, substr($ballot[0]['FechaCreacion'], 11, 5), 0, 0, 'L');
        $pdf->setxy(18, 36);
        $pdf->Cell(65.5,10,$datosEstacion[0]['EstacionNombre'], 0, 0, 'L');
        $pdf->setxy(17, 44);
        $pdf->Cell(65.5,10, utf8_decode($datosEstacion[0]['Domicilio']), 0, 0, 'L');

        if ($ballot[0]['Moneda'] == 'MXN') {
            $pdf->setxy(58, 54);
            $pdf->Cell(65.5,10,"X", 0, 0, 'L');//MN
        } else {
            $pdf->setxy(80, 54);
            $pdf->Cell(65.5,10,"X", 0, 0, 'L');//USD
        }

        $pdf->setxy(52, 18);
        $pdf->Cell(65.5,10,"Turno:" . strval($ballot[0]['Turno'])[0], 0, 0, 'L');
        $pdf->setxy(140, 36);
        $pdf->Cell(65.5,10,"TC:" . number_format($tipoCambio, 2), 0, 0, 'L');
        $pdf->setxy(138, 29);
        $pdf->Cell(65.5,10,$ballot[0]['Id'], 0, 0, 'L');
        $pdf->setxy(15, 92);
        $pdf->Cell(65.5,10,$datosEstacion[0]['BancoNombre'], 0, 0, 'L');
        $pdf->setxy(15, 102);
        $pdf->Cell(150, 10, $datosEstacion[0]['Direccion'], 0, 0, 'L');
        $pdf->setxy(60, 120);
        $pdf->Cell(65.5,10,$ballot[0]['SeguriSello'], 0, 0, 'L');
        $pdf->setxy(20, 85);
        $pdf->Cell(65.5,10,$ballot[0]['CantidadFajillas'], 0, 0, 'L');
        $pdf->setxy(15, 74);
        $pdf->Cell(65.5, 10,"$" . number_format($monto, 2) . "MN", 0, 0, 'L');
        if ($ballot[0]['Moneda'] != 'MXN') {
            $pdf->setxy(50, 74);
            $pdf->Cell(65.5, 10,"$" . number_format($montoDolares, 2) . "USD", 0, 0, 'L');
        }
        $pdf->setxy(15, 62);
        $pdf->Cell(65.5, 10, utf8_decode($montoLetra), 0, 0, 'L');
        $pdf->setxy(15, 133);
        $pdf->Cell(65.5,10,$_SESSION['tg_user']['Nombre'], 0, 0, 'L');
        $pdf->setxy(15, 137);
        $pdf->Cell(65.5,10,date('Y-m-d'), 0, 0, 'L');
        $pdf->setxy(15, 141);
        $pdf->Cell(65.5,10,date('H:i'), 0, 0, 'L');
        $pdf->Output();
    }

}
