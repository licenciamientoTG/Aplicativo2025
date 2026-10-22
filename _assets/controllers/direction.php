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


class Direction{
    public $twig;
    public $route;
    public HistoricoPreciosModel $HistoricoPreciosModel;
    public GruposModel $GruposModel;
    public ProductosModel $ProductosModel;
    public PlazasModel $PlazasModel;
    public DesabastoHorasModel $DesabastoHorasModel;
    public EstacionesModel $EstacionesModel;
    public MovimientosTarModel $movimientosTarModel;
    public CreDebMensualModel $CreDebMensualModel;
    public MetaVentaModel $MetaVentaModel;
    public VentasModel $VentasModel;
    public ValesRModel $valesr;
    public GasolinerasModel $GasolinerasModel;


    /**
     * @param $twig
     */
    public function __construct($twig) {
        $this->twig                  = $twig;
        $this->route                 = 'views/direction/';
        $this->HistoricoPreciosModel = new HistoricoPreciosModel();
        $this->GruposModel           = new GruposModel();
        $this->ProductosModel        = new ProductosModel();
        $this->PlazasModel           = new PlazasModel();
        $this->DesabastoHorasModel   = new DesabastoHorasModel();
        $this->EstacionesModel       = new EstacionesModel();
        $this->movimientosTarModel   = new MovimientosTarModel();
        $this->CreDebMensualModel    = new CreDebMensualModel();
        $this->MetaVentaModel        = new MetaVentaModel();
        $this->VentasModel        = new VentasModel();
        $this->valesr        = new ValesRModel();
        $this->GasolinerasModel   = new GasolinerasModel();

    }

    // function tg6() {
    //     $currentYear = (int)date('Y');
    //     $months =self::get_months_list();
    //     $meta_venta = $this->MetaVentaModel->get_mount_resumen_end();
    //     // $mun_jua = $this->CreDebMensualModel->get_cre_mun_jua();
    //     $months_resumen = [
    //         ['numero' => 1,  'name' => 'Ene','year'=>2024],
    //         ['numero' => 2,  'name' => 'Feb','year'=>2024],
    //         ['numero' => 3,  'name' => 'Mar','year'=>2024],
    //         ['numero' => 4,  'name' => 'Abr','year'=>2024],
    //         ['numero' => 5,  'name' => 'May','year'=>2024],
    //         ['numero' => 6,  'name' => 'Jun','year'=>2024],
    //         ['numero' => 7,  'name' => 'Jul','year'=>2024],
    //         ['numero' => 8,  'name' => 'Ago','year'=>2024],
    //         ['numero' => 9,  'name' => 'Sep','year'=>2024],
    //         ['numero' => 10, 'name' => 'Oct','year'=>2024],
    //         ['numero' => 11, 'name' => 'Nov','year'=>2024],
    //         ['numero' => 12, 'name' => 'Dic','year'=>2024],
    //     ];
    //     $months = array_reverse($months);

    //     // $total_sales = $this->VentasModel->get_month_sales();
    //     echo $this->twig->render($this->route . 'tg6/tg6.html', compact('months','currentYear','months_resumen','meta_venta'));
    // }

    function tg6() {
        $currentYear = (int)date('Y'); // Año actual
        $currentMonth = (int)date('m'); // Mes actual
        $months_resumen = [];
        $months_in_spanish = [
            1 => 'Ene',
            2 => 'Feb',
            3 => 'Mar',
            4 => 'Abr',
            5 => 'May',
            6 => 'Jun',
            7 => 'Jul',
            8 => 'Ago',
            9 => 'Sep',
            10 => 'Oct',
            11 => 'Nov',
            12 => 'Dic'
        ];
    
        // Generar meses desde enero de 2024 hasta el mes actual
        for ($year = 2024; $year <= $currentYear; $year++) {
            // Si estamos en el año actual, limitamos hasta el mes actual
            $startMonth = ($year == 2024) ? 1 : 1; // Enero de 2024
            $endMonth = ($year == $currentYear) ? $currentMonth : 12; // Mes actual para el año actual
    
            for ($month = $startMonth; $month <= $endMonth; $month++) {
                $months_resumen[] = [
                    'numero' => $month,
                    'name' => $months_in_spanish[$month], // Nombre del mes en español
                    'year' => $year
                ];
            }
        }

        // Invertir el arreglo de meses si es necesario
        // $months_resumen = array_reverse($months_resumen);

        // Obtener los otros datos necesarios
        $meta_venta = $this->MetaVentaModel->get_mount_resumen_end();
        $months = self::get_months_list(); // Asumiendo que esta función ya te da los meses
        $months = array_reverse($months); // Si también necesitas invertir los meses
        $years = array_values(array_unique(array_column($months_resumen, 'year')));
        echo $this->twig->render($this->route . 'tg6/tg6.html', compact('months', 'currentYear', 'months_resumen', 'meta_venta', 'years'));
    }
    function consult_consumption() {
        
        echo $this->twig->render($this->route . 'tg6/consult_consumption.html');
    }
    function tg6_product() {
        echo $this->twig->render($this->route . 'tg6/tg6_product.html');
    }

    function valeras() {
        $stations = $this->GasolinerasModel->get_active_stations();
        echo $this->twig->render($this->route . 'tg6/valeras.html', compact('stations'));
    }

    function valeras_table() {
        $from  = DateTime::createFromFormat('!Y-m-d', $_POST['fromDate'] ?? '');
        $until = DateTime::createFromFormat('!Y-m-d', $_POST['untilDate'] ?? '');
        $codgas = (int)($_POST['codgas'] ?? 0); // 0 = todas las estaciones
        if (!$from || !$until || $from > $until) {
            echo json_encode(['columns' => [], 'data' => [], 'base' => []]);
            return;
        }

        $months_in_spanish = [
            1 => 'Ene', 2 => 'Feb', 3 => 'Mar', 4 => 'Abr', 5 => 'May', 6 => 'Jun',
            7 => 'Jul', 8 => 'Ago', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dic'
        ];

        // Columnas dinámicas: un mes por cada mes del rango seleccionado
        $columns = [];
        $cursor = new DateTime($from->format('Y-m-01'));
        $end    = new DateTime($until->format('Y-m-01'));
        while ($cursor <= $end) {
            $year  = (int)$cursor->format('Y');
            $month = (int)$cursor->format('n');
            $columns[] = [
                'key'   => $year . '_' . $month,
                'label' => $months_in_spanish[$month] . ' ' . $year
            ];
            $cursor->modify('first day of next month');
        }

        $rows = $this->movimientosTarModel->get_valeras_report(
            dateToInt($from->format('Y-m-d')),
            dateToInt($until->format('Y-m-d')),
            $codgas
        );

        // Pivote: una fila por estación + denominación, respetando el orden del query
        // Base: las mismas filas sin pivotear (una por estación + denominación + año + mes)
        $data = [];
        $base = [];
        if ($rows) {
            foreach ($rows as $row) {
                $key = $row['abr'] . '|' . $row['denominacion'];
                if (!isset($data[$key])) {
                    $data[$key] = [
                        'estacion'     => trim($row['abr']),
                        'denominacion' => trim($row['denominacion']),
                        'total'        => 0
                    ];
                    foreach ($columns as $col) {
                        $data[$key][$col['key']] = 0;
                    }
                }
                $colKey = $row['anio'] . '_' . $row['mes'];
                if (array_key_exists($colKey, $data[$key])) {
                    $data[$key][$colKey] = (float)$row['total'];
                }
                $data[$key]['total'] = round($data[$key]['total'] + (float)$row['total'], 2);

                $base[] = [
                    'estacion'     => trim($row['abr']),
                    'denominacion' => trim($row['denominacion']),
                    'anio'         => (int)$row['anio'],
                    'mes'          => (int)$row['mes'],
                    'total'        => (float)$row['total']
                ];
            }
        }
        echo json_encode(['columns' => $columns, 'data' => array_values($data), 'base' => $base]);
    }

    public function credit_debit_product_table() {

        if ($rows = $this->valesr->GetCreditoProduct($_POST['fromDate'], $_POST['untilDate'], $_POST['tipo'])) {
            foreach ($rows as $row) {
                $data[] = array(
                    'CodigoCliente'        => $row['CodigoCliente'],
                    'Cliente'              => $row['Cliente'],
                    'Tipo'                 => $row['Tipo'],
                    'Diesel Automotriz'    => $row['Diesel Automotriz'],
                    'T-Maxima Regular'     => $row['T-Maxima Regular'],
                    'T-Super Premium'      => $row['T-Super Premium'],
                    'Total Litros'         => $row['Total Litros']
                );
            }
            echo json_encode(['data' => $data]);
        } else {
            echo json_encode(['data' => []]);
        }
    }

    function consumption_customer_credit() {
        $months =self::get_months_list();
        echo $this->twig->render($this->route . 'tg6/consumption_customer_credit.html', compact('months'));
    }
    function consumption_account_credit() {
        $months =self::get_months_list();
        echo $this->twig->render($this->route . 'tg6/consumption_account_credit.html', compact('months'));
    }
    function consumption_debit() {
        $months =self::get_months_list();
        echo $this->twig->render($this->route . 'tg6/consumption_debit.html', compact('months'));
    }
    function monthly_dollar_sales_report(){
        echo $this->twig->render($this->route . 'monthly_dollar_sales_report.html');
    }
    function get_months_list() {
        $months = [];
        $currentMonth = (int)date('m');
        $currentYear = (int)date('Y');
        $names = [1 => 'Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
        for ($i = $currentMonth; $i >= 1; $i--) {
            $months[] = [
                'name' => sprintf('%s %04d', $names[$i], $currentYear),
                'formatted' => sprintf('%04d_%s', $currentYear, $i)
            ];
        }
        for ($i = 12; $i >= 1; $i--) {
            $months[] = [
                'name' => sprintf('%s %04d', $names[$i], $currentYear - 1),
                'formatted' => sprintf('%04d_%s', $currentYear - 1, $i)
            ];
        }
        return $months;
    }

    function generate_columns_and_pivot_clause(): array {
        $currentYear = date('Y');
        $previousYear = $currentYear - 1;
        $months = range(1, 12);  // Meses del 1 al 12
        $columns = [];
        $pivotInClause = [];
        $columns_name = [];
        foreach ([$previousYear, $currentYear] as $year) {
            foreach ($months as $month) {
                $formattedMonth = $month;
                $columns[] = "ISNULL([$year-$formattedMonth], 0) AS [$year"."_"."$formattedMonth]";
                $pivotInClause[] = "[$year-$formattedMonth]";
                $columns_name[] = $year . "_" . $formattedMonth;
                $columns2[] = "[".$year . "_" . $formattedMonth ."]";

            }
        }
        return [
            'columns' => implode(",\n            ", $columns),
            'columns2' => $columns2,
            'pivotInClause' => implode(', ', $pivotInClause),
            'columns_name' => $columns_name,
            'currentYear'=> $currentYear,
            'previousYear'=> $previousYear
        ];
    }

    // Año-mes ("2026-08"): ordena y filtra bien como texto
    private static function format_month_year($date): string {
        $ts = strtotime((string)$date);
        return $ts ? date('Y-m', $ts) : '';
    }

    function search_clients() {
        $q = trim((string)($_POST['q'] ?? ''));
        $tipo = ((int)($_POST['tipo'] ?? 28) === 127) ? 127 : 28;
        echo json_encode(['data' => $this->CreDebMensualModel->search_clients($q, $tipo)]);
    }

    function assign_advisor() {
        $ids = $_POST['client_ids'] ?? [];
        $advisor = (string)($_POST['advisor'] ?? '');
        $tipo = ((int)($_POST['tipo'] ?? 28) === 127) ? 127 : 28;
        if (!is_array($ids) || !$ids) {
            echo json_encode(['success' => false, 'message' => 'Selecciona al menos un cliente.']);
            return;
        }
        try {
            $updated = $this->CreDebMensualModel->assign_advisor($ids, $advisor, $tipo);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'No se pudo asignar el asesor.']);
            return;
        }
        echo json_encode(['success' => true, 'updated' => $updated]);
    }

    // Recalcula ConsumoCreditoDebitoMensual (crédito y débito) de un mes completo (o hasta hoy si es el mes en curso)
    // y responde qué cambió respecto a lo que había guardado
    function sync_consumption() {
        set_time_limit(300);
        $month = DateTime::createFromFormat('!Y-m', $_POST['month'] ?? '');
        $today = new DateTime('today');
        if (!$month) {
            echo json_encode(['success' => false, 'message' => 'Selecciona un mes válido.']);
            return;
        }
        $from  = new DateTime($month->format('Y-m-01'));
        $until = min(new DateTime($month->format('Y-m-t')), $today);
        if ($from > $today) {
            echo json_encode(['success' => false, 'message' => 'No se puede sincronizar un mes futuro.']);
            return;
        }
        $year = (int)$month->format('Y');
        $mes  = (int)$month->format('n');

        try {
            $before = $this->CreDebMensualModel->month_snapshot($year, $mes);
            $this->CreDebMensualModel->sync_month(
                dateToInt($from->format('Y-m-d')),
                dateToInt($until->format('Y-m-d')),
                $year,
                $mes
            );
            $after = $this->CreDebMensualModel->month_snapshot($year, $mes);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'No se pudo sincronizar: ' . $e->getMessage()]);
            return;
        }

        echo json_encode([
            'success' => true,
            'mes'     => $month->format('Y-m'),
            'desde'   => $from->format('Y-m-d'),
            'hasta'   => $until->format('Y-m-d'),
            'cambios' => self::diff_consumption($before, $after),
        ]);
    }

    // Compara dos fotos del mes por tipo (28 crédito, 127 débito) y cliente
    private static function diff_consumption(array $before, array $after): array {
        $index = function (array $rows) {
            $out = [];
            foreach ($rows as $r) {
                $out[$r['tipo_id'] . '|' . $r['cliente_id']] = $r;
            }
            return $out;
        };
        $b = $index($before);
        $a = $index($after);
        $tipos = [28 => 'Crédito', 127 => 'Débito'];
        $result = [];
        foreach ($tipos as $tipoId => $label) {
            $t = ['tipo' => $label, 'clientes_antes' => 0, 'clientes_despues' => 0,
                  'litros_antes' => 0.0, 'litros_despues' => 0.0,
                  'nuevos' => 0, 'eliminados' => 0, 'modificados' => 0, 'detalle' => []];
            foreach ($b as $k => $r) {
                if ((int)$r['tipo_id'] !== $tipoId) continue;
                $t['clientes_antes']++;
                $t['litros_antes'] += (float)$r['litros'];
            }
            foreach ($a as $k => $r) {
                if ((int)$r['tipo_id'] !== $tipoId) continue;
                $t['clientes_despues']++;
                $t['litros_despues'] += (float)$r['litros'];
                if (!isset($b[$k])) {
                    $t['nuevos']++;
                    $t['detalle'][] = ['cliente' => $r['cliente_id'], 'nombre' => trim((string)$r['nombre']), 'cambio' => 'Nuevo',
                                       'antes' => 0.0, 'despues' => round((float)$r['litros'], 3)];
                } elseif (abs((float)$r['litros'] - (float)$b[$k]['litros']) >= 0.0005) {
                    $t['modificados']++;
                    $t['detalle'][] = ['cliente' => $r['cliente_id'], 'nombre' => trim((string)$r['nombre']), 'cambio' => 'Modificado',
                                       'antes' => round((float)$b[$k]['litros'], 3), 'despues' => round((float)$r['litros'], 3)];
                }
            }
            foreach ($b as $k => $r) {
                if ((int)$r['tipo_id'] !== $tipoId || isset($a[$k])) continue;
                $t['eliminados']++;
                $t['detalle'][] = ['cliente' => $r['cliente_id'], 'nombre' => trim((string)$r['nombre']), 'cambio' => 'Eliminado',
                                   'antes' => round((float)$r['litros'], 3), 'despues' => 0.0];
            }
            // Los 15 cambios más grandes en litros
            usort($t['detalle'], fn($x, $y) => abs($y['despues'] - $y['antes']) <=> abs($x['despues'] - $x['antes']));
            $t['detalle_total'] = count($t['detalle']);
            $t['detalle'] = array_slice($t['detalle'], 0, 15);
            $t['litros_antes'] = round($t['litros_antes'], 3);
            $t['litros_despues'] = round($t['litros_despues'], 3);
            $result[] = $t;
        }
        return $result;
    }

    function comsumption_credit_count_table(){
        $type = (int)$_POST['type'];
        echo json_encode(array("data" => $this->consumption_rows($type), "meta" => array("year" => (int)date('Y'), "month" => (int)date('n'), "cantidad" => $this->MetaVentaModel->meta_month((int)date('Y'), (int)date('n'), $type))));
    }

    // Filas de la tabla; las usan la pantalla y la exportación a Excel
    private function consumption_rows(int $type): array {
        $currentYear = date('Y');
        $currentMonth = (int) date('m');
        $currentday = (int) date('d');
        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $currentMonth, $currentYear);
        $daysElapsed = max($currentday - 1, 1); // días cerrados del mes; evita división entre 0 el día 1
        // Último mes cerrado (el que usa el Excel para marcar "Sin Consumo" / "Nuevo")
        $lastClosedKey = ($currentMonth == 1)
            ? ($currentYear - 1) . "_12"
            : $currentYear . "_" . ($currentMonth - 1);
        $data = [];
        $columnData = $this->generate_columns_and_pivot_clause();
        $columns_name = $columnData['columns_name'];
        $rows = $this->CreDebMensualModel->comsumption_credit_count_table( $columnData,$type);
        foreach ($rows ?: [] as $key=> $row) {
            // if($key < 294 ){
                $clienteAbreviado = mb_strlen($row['Cliente'], 'UTF-8') > 25
                ? mb_substr($row['Cliente'], 0, 25, 'UTF-8') . '...'
                : $row['Cliente'];
                $lognew = self::format_month_year($row['lognew']);
                $maxValue = (float)($row['MaxValue'] ?? 0);
                $prediction = ((float)($row[$currentYear."_".$currentMonth] ?? 0) / $daysElapsed) * $daysInMonth;
                $pro_vs_max = ($maxValue != 0) ?  ((($prediction / $maxValue )-1)*100  ) : 0;
                $ratio = ($maxValue != 0) ? $prediction / $maxValue : 0;
                $estatus = ($ratio >= 0.9) ? 'Verde' : (($ratio >= 0.7) ? 'Naranja' : 'Rojo'); // umbrales del Excel: <70% rojo, 70-90% naranja, >=90% verde
                $lastClosed = (float)($row[$lastClosedKey] ?? 0);
                $max_label = '';
                if ($maxValue == 0) {
                    $max_label = ($lastClosed == 0) ? 'Sin Consumos' : 'Nuevo';
                } elseif ($lastClosed == 0) {
                    $max_label = 'Sin Consumo';
                }
                $entry = [
                    'CodigoCliente' => $row['CodigoCliente'],
                    'Cliente'       => $row['Cliente'],
                    'Cliente2'      => $clienteAbreviado,
                    'lognew'        => $lognew,
                    'nombre_asesor' => $row['nombre_asesor'],
                    'nombre_zona'   => $row['nombre_zona'],
                    'MaxValue'      => round($maxValue,3),
                    'max_label'     => $max_label,
                    'prediction'      => round($prediction,3),
                    'pro_vs_max'      => round($pro_vs_max),
                    'cumplimiento'    => round($ratio * 100),
                    'estatus'         => $estatus,


                ];
                foreach ($columns_name as $column) {
                    $entry[$column] = round((float)($row[$column] ?? 0),3);
                }
                // if ($key == 293) {
                //     echo '<pre>';
                //     var_dump($entry);
                //     die();
                // }
                $data[] = $entry;
            // };

        }

        return $data;
    }


    function comsumption_credit_client_table(){
        $type = (int)$_POST['type'];
        echo json_encode(array("data" => $this->client_rows($type), "meta" => array("year" => (int)date('Y'), "month" => (int)date('n'), "cantidad" => $this->MetaVentaModel->meta_month((int)date('Y'), (int)date('n'), $type))));
    }

    // Filas de la tabla; las usan la pantalla y la exportación a Excel
    private function client_rows(int $type): array {
        $currentYear = date('Y');
        $currentMonth = (int) date('m');
        $currentday = (int) date('d');
        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $currentMonth, $currentYear);
        $daysElapsed = max($currentday - 1, 1); // días cerrados del mes; evita división entre 0 el día 1
        // Último mes cerrado (el que usa el Excel para marcar "Sin Consumo" / "Nuevo")
        $lastClosedKey = ($currentMonth == 1)
            ? ($currentYear - 1) . "_12"
            : $currentYear . "_" . ($currentMonth - 1);
        $data = [];
        $columnData = $this->generate_columns_and_pivot_clause();
        $columns_name = $columnData['columns_name'];

        $rows = $this->CreDebMensualModel->comsumption_credit_client_table( $columnData,$type);
        foreach ($rows ?: [] as $key=> $row) {
                $clienteAbreviado = mb_strlen($row['Cliente'], 'UTF-8') > 25 
                ? mb_substr($row['Cliente'], 0, 25, 'UTF-8') . '...'
                : $row['Cliente'];
                $lognew = self::format_month_year($row['ingreso']); // primer ingreso entre las cuentas del RFC
                $maxValue = (float)($row['MaxValue'] ?? 0);
                $prediction = ((float)($row[$currentYear."_".$currentMonth] ?? 0) / $daysElapsed) * $daysInMonth;
                $pro_vs_max = ($maxValue != 0) ? ((($prediction / $maxValue) - 1) * 100) : 0;
                $ratio = ($maxValue != 0) ? $prediction / $maxValue : 0;
                $estatus = ($ratio >= 0.9) ? 'Verde' : (($ratio >= 0.7) ? 'Naranja' : 'Rojo'); // umbrales del Excel: <70% rojo, 70-90% naranja, >=90% verde
                $lastClosed = (float)($row[$lastClosedKey] ?? 0);
                $max_label = '';
                if ($maxValue == 0) {
                    $max_label = ($lastClosed == 0) ? 'Sin Consumos' : 'Nuevo';
                } elseif ($lastClosed == 0) {
                    $max_label = 'Sin Consumo';
                }
                $entry = [
                    'CodigoCliente' => 1,
                    'Cliente'       => $row['Cliente'],
                    'Cliente2'      => $clienteAbreviado,
                    'lognew'        => $lognew,
                    'nombre_asesor' => "",
                    'nombre_zona'   => '',
                    'MaxValue'      => round($maxValue,3),
                    'max_label'     => $max_label,
                    'prediction'      => round($prediction,3),
                    'pro_vs_max'      => round($pro_vs_max),
                    'cumplimiento'    => round($ratio * 100),
                    'estatus'         => $estatus,
    
    
                ];
                foreach ($columns_name as $column) {
                    $entry[$column] = round((float)($row[$column] ?? 0),3);
                }

                $data[] = $entry;
        }

        return $data;
    }


    function historic_shortage(){
        $all_stations = $this->EstacionesModel->get_stations();
        $stations = array_filter($all_stations, fn($station) => !in_array($station['Codigo'], [0, 20]));
        echo $this->twig->render($this->route . 'historic_shortage.html',compact('stations'));
    }
    public function monthly_summary_shortge() {
        $months = self::getMonthsArray(2); // 2 años atrás desde el mes actual
        echo $this->twig->render($this->route . 'monthly_summary_shortge.html', compact('months'));
    }
    
    function getMonthsArray($yearsBack) {
        $months = [];
        $currentYear = date('Y');
        $currentMonth = date('m');
        // Array con los nombres de los meses
        $monthNames = [
            '01' => 'Jan',
            '02' => 'Feb',
            '03' => 'Mar',
            '04' => 'Apr',
            '05' => 'May',
            '06' => 'Jun',
            '07' => 'Jul',
            '08' => 'Aug',
            '09' => 'Sep',
            '10' => 'Oct',
            '11' => 'Nov',
            '12' => 'Dec'
        ];
        // Obtener año y mes límite
        $endYear = $currentYear - $yearsBack;
        $endMonth = $currentMonth;
        for ($year = $currentYear; $year >= $endYear; $year--) {
            $monthStart = ($year == $currentYear) ? $currentMonth : 12;
            $monthEnd = ($year == $endYear) ? $endMonth : 1;
            for ($month = $monthStart; $month >= $monthEnd; $month--) {
                $formattedMonth = str_pad($month, 2, '0', STR_PAD_LEFT);
                $months[] = [
                    'month' => $formattedMonth,
                    'year' => $year,
                    'name' => $monthNames[$formattedMonth]
                ];
            }
        }
        return $months;
    }
    public function daily_summary_shortge($id_producto){
        $all_stations = $this->EstacionesModel->get_all_stations();
        $stations = array_filter($all_stations, fn($station) => !in_array($station['Codigo'], [0, 20]));
        echo $this->twig->render($this->route . 'daily_summary_shortge.html', compact('id_producto','stations'));
    }
    public function monthly_summary_shortge_table(){
        if (preg_match('/POST/i', $_SERVER['REQUEST_METHOD'])) {
            $rows = $this->DesabastoHorasModel->monthly_summary_shortge();
            $months = self ::getMonthsArray(2);
            $data = [];
            foreach($rows as $row) {
                $tempRow = [
                    'Denominacion' => $row['Denominacion_name'],
                    'nombre' => $row['nombre'],
                ];
                foreach ($months as $key => $month) {
                   $name_month = $month['name']. "-" .$month['year'];
                   $tempRow[$name_month] = round($row[$name_month], 2);
                }
                $data[] = $tempRow;
            }
            json_output(array("data" => $data));
        }

    }


    public function daily_summary_shortge_table(){
        if (preg_match('/POST/i', $_SERVER['REQUEST_METHOD'])) {
            $rows = $this->DesabastoHorasModel->daily_summary_shortge_table($_POST['id_producto']);
            $all_stations = $this->EstacionesModel->get_all_stations();
            $stations = array_filter($all_stations, fn($station) => !in_array($station['Codigo'], [0, 20]));

            $data = [];
            foreach($rows as $row) {
                $mesDateTime = DateTime::createFromFormat('Y-m', $row['mes']);
                $formattedMes = $mesDateTime->format('M y'); // Esto convierte el mes a "NombreMes Año"
                $tempRow = [
                    'mes' => $formattedMes,
                ];
                foreach ($stations as $key => $station) {
                   $station_name = $station['Nombre'];
                   $tempRow[$station_name] = round($row[$station_name], 2);
                }
                $data[] = $tempRow;
            }
            json_output(array("data" => $data));
        }
    }
    function graph_prices(){
        $plazas = $this->PlazasModel->get_rows();


        $from   = $_GET['from'] ?? false;
        $until  = $_GET['until'] ?? false;
        $plaza_id  = $_GET['plaza_id'] ?? false;
        echo $this->twig->render($this->route . 'graph_prices.html', compact("plazas", 'from','until','plaza_id'));

    }

    function graph_historic_prices($Id_plaza) {
        $plaza = $this->PlazasModel->get_row($Id_plaza);
        $name_plaza = ucwords(strtolower($plaza['nombre'])); // Convertir la primera letra de cada palabra a mayúscula
        $month1 = date('m') - 1;
        $month2 = date('m') - 2;
        $year1 = date('Y');
        $year2 = date('Y');

        if ($month1 == 0) {
            $month1 = 12;
            $year1 -= 1;
        }
        if ($month2 <= 0) {
            $month2 += 12;
            $year2 -= 1;
        }

        $firstDayOfMonth1 = sprintf('%04d-%02d-01', $year2, $month2);
        $lastDayOfMonth1 = sprintf('%04d-%02d-%02d', $year1, $month1, cal_days_in_month(CAL_GREGORIAN, $month1, $year1));
        $daysInMonth1 = cal_days_in_month(CAL_GREGORIAN, $month1, $year1);
        $daysInMonth2 = cal_days_in_month(CAL_GREGORIAN, $month2, $year2);

        $months = [1=>'Enero', 2=>'Febrero', 3=>'Marzo', 4=>'Abril', 5=>'Mayo', 6=>'Junio', 7=>'Julio', 8=>'Agosto', 9=>'Septiembre', 10=>'Octubre', 11=>'Noviembre', 12=>'Diciembre'];

        $daysArray = [];
        for ($day = 1; $day <= $daysInMonth2; $day++) {
            $formattedDay = sprintf('%d_%d', $month2, $day);
            $displayDay = $day . ' ' . $months[$month2];
            $daysArray[] = [
                'formatted' => $formattedDay,   // Formato original
                'display' => $displayDay        // Formato amigable para la vista
            ];
        }
        for ($day = 1; $day <= $daysInMonth1; $day++) {
            $formattedDay = sprintf('%d_%d', $month1, $day);
            $displayDay = $day . ' ' . $months[$month1];
            $daysArray[] = [
                'formatted' => $formattedDay,   // Formato original
                'display' => $displayDay        // Formato amigable para la vista
            ];
        }
        // Invertir el array para que comience con la fecha más actual
        $daysArray = array_reverse($daysArray);
        echo $this->twig->render($this->route . 'graph_historic_prices.html', compact("Id_plaza", 'daysArray', 'name_plaza'));
    }

    function historic_prices($from=false,$until=false) {
        $from   = $_POST['from'] ?? false;
        $until  = $_POST['until'] ?? false;
        $plaza_id  = $_POST['plaza_id'] ?? false;

        echo $this->twig->render($this->route . 'historic_prices.html', compact('from','until','plaza_id'));
    }

    function cumsumption_credit_table(){
        echo '<pre>';
        var_dump($_POST);
        die();

    }

    function historic_price_table() {
        $from        = $_POST['from'];
        $until       = $_POST['until'];
        $data = [];
        $rows = $this->HistoricoPreciosModel->get_rows($from . ' 00:00:00', $until  . ' 23:59:59');
        foreach ($rows as $row) {
            $data[] =  array(
                'fecha_precio' => $row['fecha_precio'],
                'grupo'        => $row['grupo'],
                'precios'       => $row['precios'],
                'producto'     => $row['producto'],
                'plaza'        => $row['plaza'],
                'id_historico' => $row['id_historico'],
            );
        }
        json_output(array("data" => $data));
    }
    function historic_price_table_pivot2() {
        // Obtener el año actual
        $currentYear = (int)date('Y');

        // Ajustar las fechas a este año si no coinciden
        $fromDateInput = $_POST['fromDate'];
        $untilDateInput = $_POST['untilDate'];

        $fromYear = (int)date('Y', strtotime($fromDateInput));
        $untilYear = (int)date('Y', strtotime($untilDateInput));

        // Si el año de la fecha 'from' no es el actual, ajustarlo
        if ($fromYear !== $currentYear) {
            $fromDateInput = $currentYear . '-' . date('m-d', strtotime($fromDateInput));
        }

        // Si el año de la fecha 'until' no es el actual, ajustarlo
        if ($untilYear !== $currentYear) {
            $untilDateInput = $currentYear . '-' . date('m-d', strtotime($untilDateInput));
        }
    
        // Continuar con el procesamiento de las fechas ajustadas
        $until = $untilDateInput . ' 23:59:59';
        $from = $fromDateInput . ' 00:00:01';
        $fromDate = new DateTime($fromDateInput);
        $untilDate = new DateTime($untilDateInput);
        $daysArray = self::generateDaysString($fromDate, $untilDate);
        $daysString = implode(', ', $daysArray);
        $rows = $this->HistoricoPreciosModel->get_price_table_pivot_v($from, $until, $daysString, $_POST['product'], $_POST['Id_plaza']);
    
        $data = [];
        foreach ($rows as $row) {
            $tempRow = ['grupo' => $row['grupo']];
            foreach ($daysArray as $dayKey) {
                $dayKey = str_replace(['[', ']'], '', $dayKey); // Eliminar corchetes para la clave de array
    
                $tempRow[$dayKey] = isset($row[$dayKey]) ? number_format((float)$row[$dayKey], 2, '.', '') : null;
            }
            $precios = array_slice($tempRow, 1, count($daysArray));
            $preciosFiltrados = array_filter($precios);
            $tempRow['average'] = !empty($preciosFiltrados) ? number_format(array_sum($preciosFiltrados) / count($preciosFiltrados), 2, '.', '') : null;
            $data[] = $tempRow;
        }
    
        json_output(array("data" => $data));
    }
    public  function generateDaysString($fromDate, $untilDate) {
        $daysArray = [];
        // Generar la lista de días entre fromDate y untilDate
        while ($fromDate <= $untilDate) {
            $monthDay = $fromDate->format('j_n'); // Formato MM_DD
            $daysArray[] = "[$monthDay]"; // Agrega el formato requerido para SQL Server
            $fromDate->modify('+1 day'); // Avanza al siguiente día
        }
        return  $daysArray;
    }

    function historic_price_table_pivot() {
        $month1 = date('m') - 1;
        $month2 = date('m') - 2;
        $year1 = date('Y');
        $year2 = date('Y');
        if ($month1 == 0) {
            $month1 = 12;
            $year1 -= 1;
        }
        if ($month2 <= 0) {
            $month2 += 12;
            $year2 -= 1;
        }
        $firstDayOfMonth1 = sprintf('%04d-%02d-01', $year2, $month2);
        $lastDayOfMonth1 = sprintf('%04d-%02d-%02d', $year1, $month1, cal_days_in_month(CAL_GREGORIAN, $month1, $year1));
        $daysInMonth1 = cal_days_in_month(CAL_GREGORIAN, $month1, $year1);
        $daysInMonth2 = cal_days_in_month(CAL_GREGORIAN, $month2, $year2);
        $daysArray = [];
        for ($day = 1; $day <= $daysInMonth2; $day++) {
            $daysArray[] = sprintf('[%d_%d]', $month2, $day);
        }
        for ($day = 1; $day <= $daysInMonth1; $day++) {
            $daysArray[] = sprintf('[%d_%d]', $month1, $day);
        }
        $daysString = implode(', ', $daysArray);
        $rows = $this->HistoricoPreciosModel->get_price_table_pivot($firstDayOfMonth1, $lastDayOfMonth1, $daysString, $_POST['product'], $_POST['Id_plaza']);

        $data = [];
        foreach ($rows as $row) {
            $tempRow = ['grupo' => $row['grupo']];
            foreach ($daysArray as $dayKey) {
                $dayKey = str_replace(['[', ']'], '', $dayKey); // Eliminar corchetes para la clave de array
                $tempRow[$dayKey] = isset($row[$dayKey]) ? number_format((float)$row[$dayKey], 2, '.', '') : null;
            }
            $precios = array_slice($tempRow, 1, count($daysArray));
            $preciosFiltrados = array_filter($precios);
            $tempRow['average'] = !empty($preciosFiltrados) ? number_format(array_sum($preciosFiltrados) / count($preciosFiltrados), 2, '.', '') : null;
            $data[] = $tempRow;
        }

        json_output(array("data" => $data));
    }
    public function Vta_vs_meta_canvas(){
        $year = (int)($_POST['year'] ?? date('Y'));
        // El resumen viene del más reciente al más antiguo; la gráfica va de enero a diciembre del año elegido
        $meta_venta = array_filter($this->MetaVentaModel->get_mount_resumen_end() ?: [], fn($row) => (int)$row['año'] === $year);
        usort($meta_venta, fn($a, $b) => (int)$a['mes'] <=> (int)$b['mes']);
        $month_names = [
            1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
            5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
            9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
        ];
        $response = [
            'months' => [],
            'sum_mouth_mun' => [],
            'sum_cre_mun' => [],
            'sum_deb' => [],
            'sum_meta_cre' => [],
            'sum_meta_deb' => [],
            'sum_meta_mouth' => [],
            'percentage_achieved' => []
        ];
        foreach ($meta_venta as $row) {
            $response['months'][] = $month_names[(int)$row['mes']]; // Convertimos el número del mes a su nombre
            $response['sum_mouth_mun'][] = floatval(str_replace(',', '', $row['sum_mouth_mun']));
            $response['sum_cre_mun'][] = floatval(str_replace(',', '', $row['sum_cre_mun']));
            $response['sum_deb'][] = floatval(str_replace(',', '', $row['sum_deb']));
            $response['sum_meta_cre'][] = floatval(str_replace(',', '', $row['sum_meta_cre']));
            $response['sum_meta_deb'][] = floatval(str_replace(',', '', $row['sum_meta_deb']));
            $response['sum_meta_mouth'][] = floatval(str_replace(',', '', $row['sum_meta_mouth']));
            $response['percentage_achieved'][] = floatval(str_replace(',', '', $row['percentage_achieved']));
        }
        echo json_encode($response);

    }

    public function week_graph() {
        $until = $_POST['untilDate'] . ' 23:59:59';
        $from = $_POST['fromDate'] . ' 00:00:01';
    
        // Obtener los datos de la consulta
        $rows = $this->HistoricoPreciosModel->get_price_week_pivot($_POST['product'], $_POST['Id_plaza'], $from, $until);
    
        // Inicializar arrays para grupos y fechas únicas
        $allGroups = [];
        $allDates = [];
    
        // Recorrer los datos para identificar todos los grupos y fechas únicos
        foreach ($rows as $dato) {
            $grupo = $dato['grupo'];
            $fecha = $dato['fecha_inicio_semana'];
            if (!in_array($grupo, $allGroups)) {
                $allGroups[] = $grupo;
            }
            if (!in_array($fecha, $allDates)) {
                $allDates[] = $fecha;
            }
        }
    
        // Ordenar las fechas para asegurar un orden cronológico
        sort($allDates);
    
        // Inicializar la estructura de datos con ceros para cada grupo y fecha
        $groupedData = [];
        foreach ($allGroups as $grupo) {
            $groupedData[$grupo] = [
                'fechas' => $allDates,
                'precios' => array_fill(0, count($allDates), 0) // Inicializar con ceros
            ];
        }
    
        // Llenar los datos existentes en la estructura
        foreach ($rows as $dato) {
            $grupo = $dato['grupo'];
            $fecha = $dato['fecha_inicio_semana'];
            $precio = $dato['max_precio'];
    
            // Buscar el índice de la fecha en allDates
            $dateIndex = array_search($fecha, $allDates);
            if ($dateIndex !== false) {
                $groupedData[$grupo]['precios'][$dateIndex] = $precio;
            }
        }
    
        // Reemplazar los ceros por el promedio de los valores antes y después
        foreach ($groupedData as $grupo => &$datos) {
            $precios = &$datos['precios'];
            for ($i = 0; $i < count($precios); $i++) {
                if ($precios[$i] == 0) {
                    $previo = ($i > 0 && $precios[$i - 1] != 0) ? $precios[$i - 1] : null;
                    $siguiente = ($i < count($precios) - 1) ? $precios[$i + 1] : null;
    
                    // Solo calcular el promedio si el valor previo no es cero
                    if ($previo !== null && $siguiente !== null && $siguiente != 0) {
                        $precios[$i] = ($previo + $siguiente) / 2;
                    } elseif ($previo !== null) {
                        $precios[$i] = $previo;
                    } elseif ($siguiente !== null && $siguiente != 0) {
                        $precios[$i] = $siguiente;
                    }
                }
            }
        }
    
    
        // Construir la respuesta final
        $response = [];
        foreach ($groupedData as $grupo => $datos) {
            $response[] = [
                'label' => $grupo,
                'fechas' => $datos['fechas'],
                'precios' => $datos['precios']
            ];
        }
    
        echo json_encode($response);
    }
    


    public function graph_month() {
        $until = $_POST['untilDate'].' 23:59:59';
        $from = $_POST['fromDate'].' 00:00:01';
        $rows = $this->HistoricoPreciosModel->get_price_month_pivot($_POST['product'], $_POST['Id_plaza'], $from, $until);
    
        // Primero, encontramos todas las fechas únicas
        $allDates = [];
        foreach ($rows as $dato) {
            $key = $dato['year_num'] . '-' . str_pad($dato['month_num'], 2, '0', STR_PAD_LEFT);
            $allDates[$key] = [
                'year_num' => $dato['year_num'],
                'month_num' => $dato['month_num']
            ];
        }
        // Ordenamos las fechas
        ksort($allDates);
    
        // Inicializamos el array agrupado
        $groupedData = [];
        
        // Primero creamos la estructura base para cada grupo
        foreach ($rows as $dato) {
            if (!isset($groupedData[$dato['grupo']])) {
                $groupedData[$dato['grupo']] = [
                    'year_num' => [],
                    'month_num' => [],
                    'precios' => []
                ];
            }
        }
    
        // Ahora llenamos los datos normalizados
        foreach ($groupedData as $grupo => &$datos) {
            foreach ($allDates as $dateKey => $dateInfo) {
                $found = false;
                
                // Buscamos si existe un dato para esta fecha en este grupo
                foreach ($rows as $row) {
                    if ($row['grupo'] === $grupo && 
                        $row['year_num'] === $dateInfo['year_num'] && 
                        $row['month_num'] === $dateInfo['month_num']) {
                        $datos['year_num'][] = $row['year_num'];
                        $datos['month_num'][] = $row['month_num'];
                        $datos['precios'][] = $row['max_precio'];
                        $found = true;
                        break;
                    }
                }
                
                // Si no se encontró dato para esta fecha, agregamos 0
                if (!$found) {
                    $datos['year_num'][] = $dateInfo['year_num'];
                    $datos['month_num'][] = $dateInfo['month_num'];
                    $datos['precios'][] = null;
                }
            }
        }
        unset($datos); // Limpiamos la referencia
    
        // Preparamos la respuesta en el formato deseado
        $response = [];
        foreach ($groupedData as $grupo => $datos) {
            $response[] = [
                'label' => $grupo,
                'year_num' => $datos['year_num'],
                'month_num' => $datos['month_num'],
                'precios' => $datos['precios']
            ];
        }
    
        echo json_encode($response);
    }

    function monthly_dollar_sales_report_table() {
        $data = [];
        $rows = $this->movimientosTarModel->monthly_dollar_sales_report_table();

        foreach ($rows as $row) {
            $data[] = array(
                'estacion'          => $row['estacion'],            // Estación
                'año'               => $row['año'],                 // Año
                'EneroDolares'      => round($row['EneroDolares']),        // Dólares en enero
                'EneroMontos'       => round($row['EneroMontos']),         // Montos en enero
                'FebreroDolares'    => round($row['FebreroDolares']),      // Dólares en febrero
                'FebreroMontos'     => round($row['FebreroMontos']),       // Montos en febrero
                'MarzoDolares'      => round($row['MarzoDolares']),        // Dólares en marzo
                'MarzoMontos'       => round($row['MarzoMontos']),         // Montos en marzo
                'AbrilDolares'      => round($row['AbrilDolares']),        // Dólares en abril
                'AbrilMontos'       => round($row['AbrilMontos']),         // Montos en abril
                'MayoDolares'       => round($row['MayoDolares']),         // Dólares en mayo
                'MayoMontos'        => round($row['MayoMontos']),          // Montos en mayo
                'JunioDolares'      => round($row['JunioDolares']),        // Dólares en junio
                'JunioMontos'       => round($row['JunioMontos']),         // Montos en junio
                'JulioDolares'      => round($row['JulioDolares']),        // Dólares en julio
                'JulioMontos'       => round($row['JulioMontos']),         // Montos en julio
                'AgostoDolares'     => round($row['AgostoDolares']),       // Dólares en agosto
                'AgostoMontos'      => round($row['AgostoMontos']),        // Montos en agosto
                'SeptiembreDolares' => round($row['SeptiembreDolares']),   // Dólares en septiembre
                'SeptiembreMontos'  => round($row['SeptiembreMontos']),    // Montos en septiembre
                'OctubreDolares'    => round($row['OctubreDolares']),      // Dólares en octubre
                'OctubreMontos'     => round($row['OctubreMontos']),       // Montos en octubre
                'NoviembreDolares'  => round($row['NoviembreDolares']),    // Dólares en noviembre
                'NoviembreMontos'   => round($row['NoviembreMontos']),     // Montos en noviembre
                'DiciembreDolares'  => round($row['DiciembreDolares']),    // Dólares en diciembre
                'DiciembreMontos'   => round($row['DiciembreMontos'])      // Montos en diciembre
            );
        }
        json_output(array("data" => $data));
    }


    function historic_shortage_table() {
        $data = [];
        $rows = $this->DesabastoHorasModel->get_rows();
        foreach ($rows as $row) {
            $options = "<span>";
            if ($row['id_user'] == $_SESSION['tg_user']["Id"]) {
               $options .= "<img src=\"\_assets\images\Delete.png\" class=\"icon_delete\" alt=\"...\" Onclick=\"delete_shortage(".$row['id_desabasto'].")\"> ";
            }
            $options .= "</span>";
            $data[] =  array(
                'id_desabasto'    => $row['id_desabasto'],
                'fecha_desabasto' => $row['fecha_desabasto'],
                'codigo_estacion' => $row['codigo_estacion'],
                'estacion'         => $row['estacion'],
                'producto'        => $row['producto'],
                'horas'           => round($row['horas'],2),
                'razon_social'    => $row['razon_social'],
                'options'    => $options,
            );
        }
        json_output(array("data" => $data));
    }
    function delete_shortage(){

        $response = 0;
        if($this->DesabastoHorasModel->delete_row($_POST['id_desabasto'])) {
            $response = 1;
        }
        echo json_encode($response);
    }
    function SaveHoursShortage(){
        if (preg_match('/POST/i', $_SERVER['REQUEST_METHOD'])) {
            $response = 0;
            $id_user =$_SESSION['tg_user']["Id"];
            $fecha_desabasto = date('Ymd', strtotime($_POST['fecha_desabasto']));
            $this->DesabastoHorasModel->horas           = $_POST['horas'];
            $this->DesabastoHorasModel->id_producto     = $_POST['id_producto'];
            $this->DesabastoHorasModel->fecha_desabasto = $fecha_desabasto;
            $this->DesabastoHorasModel->id_estacion     = $_POST['id_estacion'];
            $this->DesabastoHorasModel->id_user         = $id_user;
            if($this->DesabastoHorasModel->insert_row()){
                $response = 1;
            }
            echo json_encode($response);
        }
    }

    public function import_file_historic_price(){
        if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['file_to_upload'])) {
            $response = 0;

            $file = $_FILES['file_to_upload']['tmp_name'];
            $spreadsheet = IOFactory::load($file);
            $sheet = $spreadsheet->getActiveSheet();

            $fecha_precio = $sheet->getCell('G1')->getValue();
            if (!$fecha_precio) {
                $response = 2;
                echo json_encode($response);
                return;
            }

            if (\PhpOffice\PhpSpreadsheet\Shared\Date::isDateTime($sheet->getCell('G1'))) {
                $fecha_precio = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($fecha_precio)->format('Y-m-d');
            } else {
                $fecha_precio = (string) $fecha_precio; // Si no es una fecha válida, convierte el valor a string
            }

            $highestRow = (int) $sheet->getHighestRow();
            $maxRow     = min($highestRow, 400);

            // Filas específicas a procesar
            // $filas = [
            //         4,  14, 15, 16, 17, 18, 19, 20, 21, 22,23,
            //         42, 43, 44, 45, 46, 47, 48, 49, 51,
            //         61, 62, 63, 64, 65, 66, 67, 68, 69, 71,
            //         81, 82, 83, 84, 85, 86, 87, 88, 89, 90, 92,
            //         100, 101, 102, 104,
            //         114, 115, 116, 117, 118, 119, 120, 121, 123,
            //         132, 133, 134, 137,
            //         147, 148, 152,
            //         159, 160, 161, 164,
            //         174, 175, 176, 177, 178, 180,
            //         188, 189, 190, 191, 192, 195,
            //         202, 203, 204, 205, 207,
            //         214, 215, 216,217, 218,220,
            //         226, 227,228, 229,231
            //     ];

            $data = [];
            for ($row = 4; $row <= $maxRow; $row++) {
                // $id_grupo = $sheet->getCell("B$row_num")->getValue();
                // $Id_plaza = $sheet->getCell("C$row_num")->getValue();
                // $precios = [
                //     $sheet->getCell("E$row_num")->getValue(),
                //     $sheet->getCell("F$row_num")->getValue(),
                //     $sheet->getCell("G$row_num")->getValue()
                // ];
                $idGrupo = trim((string) $sheet->getCell("B{$row}")->getValue());
                if ($idGrupo === '') {
                    continue;
                }
                $idPlaza = $sheet->getCell("C{$row}")->getValue();
                $precios = [
                    $sheet->getCell("E{$row}")->getValue(),
                    $sheet->getCell("F{$row}")->getValue(),
                    $sheet->getCell("G{$row}")->getValue(),
                ];
                foreach ($precios as $index => $precio) {
                    if ($precio != NULL) {
                        $data[] = [
                            'fecha' => $fecha_precio,
                            'id_grupo' => $idGrupo,
                            'Id_plaza' => $idPlaza,
                            'precios' => round($precio, 2),
                            'id_productos' => $index + 1,
                        ];
                    }
                }
            }

            if ($this->HistoricoPreciosModel->insert_prices_with_transaction($data)) {
                $response = 1;
                echo json_encode($response);
            } else {
                echo json_encode($response);
            }
        }
    }

    // Excel de las 4 pestañas, una por hoja: histórico del año elegido y las tres tablas de consumo con el rango de meses elegido
    public function excel_tg6(){
        set_time_limit(300);
        ini_set('memory_limit', '512M');
        ini_set('display_errors', '0');   // un aviso impreso corrompe el .xlsx
        while (ob_get_level() > 0) { ob_end_clean(); }
        $currentYear = (int)date('Y');
        $year = (int)($_GET['year'] ?? $currentYear);
        $monthKey = fn($key) => preg_match('/^(\d{4})_(\d{1,2})$/', (string)$key, $m) ? ((int)$m[1] * 12 + (int)$m[2]) : null;
        $from = $monthKey($_GET['from'] ?? '') !== null ? $_GET['from'] : $currentYear . '_1';
        $to   = $monthKey($_GET['to'] ?? '') !== null ? $_GET['to'] : $currentYear . '_' . (int)date('n');
        // meses de la tabla (más antiguo primero) dentro del rango elegido
        $months = array_values(array_filter(array_reverse(self::get_months_list()), function ($m) use ($monthKey, $from, $to) {
            $k = $monthKey($m['formatted']);
            return $k >= $monthKey($from) && $k <= $monthKey($to);
        }));

        $spreadsheet = new Spreadsheet();
        $this->excel_historic_sheet($spreadsheet->getActiveSheet(), $year);
        $this->excel_consumption_sheet($spreadsheet->createSheet(), 'Cre Consumo Cuenta', $this->consumption_rows(28), $months, false, 'FFD9EAF7');
        $this->excel_consumption_sheet($spreadsheet->createSheet(), 'Cre Consumo Cliente', $this->client_rows(28), $months, true, 'FFD9F2E3');
        $this->excel_consumption_sheet($spreadsheet->createSheet(), 'Debito Consumos', $this->consumption_rows(127), $months, false, 'FFFFE0B3');
        $spreadsheet->setActiveSheetIndex(0);

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="TG6 Clientes Credito-Debito ' . date('d-m-Y') . '.xlsx"');
        header('Cache-Control: max-age=0');
        (new Xlsx($spreadsheet))->save('php://output');
        exit();
    }

    // Hoja "Vta vs Meta Emp Historico": mismas filas y meses que la pestaña, para el año elegido
    private function excel_historic_sheet($sheet, int $year): void {
        $sheet->setTitle('Vta vs Meta Emp Historico');
        $coord = fn($i) => \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
        $num = fn($v) => (float)str_replace(',', '', (string)$v);
        $byMonth = [];
        foreach ($this->MetaVentaModel->get_mount_resumen_end() ?: [] as $row) {
            if ((int)$row['año'] === $year) {
                $byMonth[(int)$row['mes']] = $row;
            }
        }
        $names = [1 => 'Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
        $lastMonth = ($year === (int)date('Y')) ? (int)date('n') : 12;
        $lastCol = $coord($lastMonth + 1);
        // [etiqueta, campo, tipo n=cantidad / p=porcentaje, negritas, relleno]
        $lines = [
            ['Venta Cre/Deb', 'sum_mouth_mun', 'n', true, 'FF90EE90'],
            ['Crédito', 'sum_cre_mun', 'n', false, null],
            ['Débito', 'sum_deb', 'n', false, null],
            null,
            ['Meta', 'sum_meta_mouth', 'n', true, 'FFADD8E6'],
            ['Crédito', 'sum_meta_cre', 'n', false, null],
            ['Débito', 'sum_meta_deb', 'n', false, null],
            null,
            ['% LOGRO', 'percentage_achieved', 'p', true, 'FFC9A27A'],
            ['Crédito', 'percentage_achieved_cred', 'p', false, null],
            ['Débito', 'percentage_achieved_deb', 'p', false, null],
            null,
            ['Venta Cre - Mun', 'cred_mun_jua', 'n', true, 'FFFFA500'],
            ['Crédito Municipal', 'cred_mun_jua', 'n', false, null],
            null,
            ['Venta Total', 'VentasReales', 'n', true, 'FFFFFF00'],
            ['Venta Cre/Deb', 'sum_mouth', 'n', false, null],
            ['Venta Contado', 'sales_cash', 'n', false, null],
        ];
        $sheet->setCellValue('A1', 'Año ' . $year);
        $sheet->getStyle('A1')->getFont()->setBold(true);
        for ($m = 1; $m <= $lastMonth; $m++) {
            $sheet->setCellValue($coord($m + 1) . '1', $names[$m] . ' ' . $year);
        }
        $sheet->getStyle("B1:{$lastCol}1")->getFont()->setBold(true);
        $sheet->getStyle("B1:{$lastCol}1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFF00');
        $r = 3;
        foreach ($lines as $line) {
            if ($line === null) { $r++; continue; }
            [$label, $field, $kind, $bold, $fill] = $line;
            $sheet->setCellValue('A' . $r, $label);
            for ($m = 1; $m <= $lastMonth; $m++) {
                $v = isset($byMonth[$m]) ? $num($byMonth[$m][$field] ?? 0) : 0;
                $sheet->setCellValue($coord($m + 1) . $r, $kind === 'p' ? $v / 100 : $v);
            }
            $sheet->getStyle("B{$r}:{$lastCol}{$r}")->getNumberFormat()->setFormatCode($kind === 'p' ? '0.0%' : '#,##0');
            if ($bold) { $sheet->getStyle("A{$r}:{$lastCol}{$r}")->getFont()->setBold(true); }
            if ($fill) { $sheet->getStyle("A{$r}:{$lastCol}{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($fill); }
            $r++;
        }
        $sheet->getColumnDimension('A')->setWidth(22);
        for ($m = 1; $m <= $lastMonth; $m++) {
            $sheet->getColumnDimension($coord($m + 1))->setWidth(14);
        }
        $sheet->freezePane('B2');
    }

    // Hoja de una tabla de consumo: fila 1 = suma de cada mes, fila 2 = encabezados, desde la 3 los clientes
    private function excel_consumption_sheet($sheet, string $title, array $rows, array $months, bool $byRfc, string $headerFill): void {
        $sheet->setTitle($title);
        $coord = fn($i) => \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
        $statusFill = ['Verde' => 'FFC6EFCE', 'Naranja' => 'FFFFEB9C', 'Rojo' => 'FFFFC7CE'];
        $text = \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING;

        // columnas fijas antes de los meses
        $lead = $byRfc
            ? [['Nombre', 'Cliente'], ['Ingreso', 'lognew']]
            : [['Cliente', 'CodigoCliente'], ['Nombre', 'Cliente'], ['Asesor', 'nombre_asesor'], ['Ingreso', 'lognew']];
        $headers = array_column($lead, 0);
        foreach ($months as $m) { $headers[] = $m['name']; }
        array_push($headers, 'Consumo Mayor', 'Proyección', 'Proy. VS Cons Mayor En 6 Meses', '% Cumpl.', 'Zona');

        $first = count($lead) + 1;                    // primera columna de mes
        $last  = $first + count($months) - 1;         // última columna de mes
        $cMax  = $last + 1; $cPred = $last + 2; $cVar = $last + 3; $cPct = $last + 4; $cZone = $last + 5;

        foreach ($headers as $i => $h) {
            $sheet->setCellValue($coord($i + 1) . '2', $h);
        }
        $sheet->setCellValue('A1', 'Suma');
        $sheet->getStyle('A1:' . $coord($cZone) . '2')->getFont()->setBold(true);
        $sheet->getStyle('A2:' . $coord($cZone) . '2')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($headerFill);
        $sheet->getStyle('A2:' . $coord($cZone) . '2')->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_BOTTOM);

        $r = 3;
        $sums = array_fill(0, count($months), 0.0);
        foreach ($rows as $row) {
            foreach ($lead as $i => $spec) {
                $sheet->setCellValueExplicit($coord($i + 1) . $r, (string)($row[$spec[1]] ?? ''), $text);
            }
            foreach ($months as $i => $m) {
                $v = (float)($row[$m['formatted']] ?? 0);
                $sums[$i] += $v;
                $sheet->setCellValue($coord($first + $i) . $r, $v);
            }
            $sheet->setCellValue($coord($cMax) . $r, ($row['max_label'] ?? '') !== '' ? $row['max_label'] : $row['MaxValue']);
            $sheet->setCellValue($coord($cPred) . $r, $row['prediction']);
            $sheet->setCellValue($coord($cVar) . $r, $row['pro_vs_max'] / 100);
            $sheet->setCellValue($coord($cPct) . $r, $row['cumplimiento'] / 100);
            $sheet->setCellValueExplicit($coord($cZone) . $r, (string)($row['nombre_zona'] ?? ''), $text);
            if (isset($statusFill[$row['estatus']])) {
                $sheet->getStyle($coord($cPct) . $r)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($statusFill[$row['estatus']]);
            }
            $r++;
        }
        foreach ($sums as $i => $s) {
            $sheet->setCellValue($coord($first + $i) . '1', $s);
        }
        $lastRow = max($r - 1, 3);
        if ($months) {
            $sheet->getStyle($coord($first) . '3:' . $coord($last) . $lastRow)->getNumberFormat()->setFormatCode('#,##0.000');
            $sheet->getStyle($coord($first) . '1:' . $coord($last) . '1')->getNumberFormat()->setFormatCode('#,##0');
        }
        $sheet->getStyle($coord($cMax) . '3:' . $coord($cPred) . $lastRow)->getNumberFormat()->setFormatCode('#,##0.000');
        $sheet->getStyle($coord($cVar) . '3:' . $coord($cPct) . $lastRow)->getNumberFormat()->setFormatCode('0%');
        $sheet->getStyle($coord($cMax) . '3:' . $coord($cMax) . $lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        $sheet->getColumnDimension($coord($byRfc ? 1 : 2))->setWidth(42);
        foreach ($months as $i => $m) { $sheet->getColumnDimension($coord($first + $i))->setWidth(13); }
        foreach ([$cMax, $cPred] as $c) { $sheet->getColumnDimension($coord($c))->setWidth(15); }
        $sheet->getColumnDimension($coord($cVar))->setWidth(16);
        $sheet->getColumnDimension($coord($cPct))->setWidth(10);
        $sheet->getColumnDimension($coord($cZone))->setWidth(14);
        $sheet->setAutoFilter('A2:' . $coord($cZone) . $lastRow);
        $sheet->freezePane($coord($byRfc ? 2 : 3) . '3');
    }

    public function download_format(){
        $file = 'C:\inetpub\wwwroot\TG_PHP\_assets\includes\documents/FormatoPrecio.xlsx';

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


    public function import_file_historic_price_horizontal(){
        if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['file_to_upload'])) {
            ini_set('max_execution_time', 5000);
            ini_set('memory_limit', '1024M');
            set_time_limit(0);
            $response = ['status' => 0, 'message' => ''];

            // Captura errores fatales que no atrapa el try/catch
            set_error_handler(function($errno, $errstr, $errfile, $errline) {
                throw new \ErrorException($errstr, $errno, $errno, $errfile, $errline);
            });

            try {
                $file = $_FILES['file_to_upload']['tmp_name'];
                $spreadsheet = IOFactory::load($file);
                $sheet = $spreadsheet->getActiveSheet();
                
                $highestRow = (int) $sheet->getHighestRow();
                $highestColumn = $sheet->getHighestColumn();
                $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);
                
                $data = [];
                $registros_por_fecha = [];
                
                // Detectar columnas de fechas (G, K, O, S, W, AA...)
                $columnas_fechas = [];
                for ($col = 7; $col <= $highestColumnIndex; $col += 4) {
                    $columnLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
                    $cell = $sheet->getCell("{$columnLetter}1");
                    $fecha_valor = $cell->getCalculatedValue();

                    if (!empty($fecha_valor)) {
                        if (\PhpOffice\PhpSpreadsheet\Shared\Date::isDateTime($cell) && is_numeric($fecha_valor)) {
                            $fecha = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($fecha_valor)->format('Y-m-d');
                        } else {
                            $fecha = date('Y-m-d', strtotime($fecha_valor));
                        }
                        
                        // Convertir índices de columna a letras
                        $col_regular_letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col - 2);
                        $col_91oct_letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col - 1);
                        $col_diesel_letter = $columnLetter;
                        
                        $columnas_fechas[] = [
                            'fecha' => $fecha,
                            'col_regular' => $col_regular_letter,   // Letra: E, I, M, Q...
                            'col_91oct' => $col_91oct_letter,       // Letra: F, J, N, R...
                            'col_diesel' => $col_diesel_letter      // Letra: G, K, O, S...
                        ];
                        
                        $registros_por_fecha[$fecha] = 0;
                    }
                }
                
                if (empty($columnas_fechas)) {
                    throw new Exception("No se encontraron fechas válidas en el archivo");
                }
                
                // Procesar filas
                for ($row = 4; $row <= $highestRow; $row++) {
                    $idGrupo = trim((string) $sheet->getCell("B{$row}")->getValue());
                    $idPlaza = $sheet->getCell("C{$row}")->getValue();
                    
                    if ($idGrupo === '' || empty($idPlaza)) {
                        continue;
                    }
                    
                    foreach ($columnas_fechas as $col_info) {
                        // Usar getCell() con coordenadas de letras (compatible con todas las versiones)
                        $precios = [
                            1 => $sheet->getCell($col_info['col_regular'] . $row)->getValue(),  // Regular
                            2 => $sheet->getCell($col_info['col_91oct'] . $row)->getValue(),    // 91 Octanos
                            3 => $sheet->getCell($col_info['col_diesel'] . $row)->getValue()    // Diesel
                        ];
                        
                        foreach ($precios as $id_producto => $precio) {
                            if ($precio !== NULL && $precio !== '' && $precio !== '-' && 
                                is_numeric($precio) && $precio > 0) {
                                
                                $data[] = [
                                    'fecha' => $col_info['fecha'],
                                    'id_grupo' => $idGrupo,
                                    'Id_plaza' => $idPlaza,
                                    'precios' => round($precio, 2),
                                    'id_productos' => $id_producto,
                                ];
                                
                                $registros_por_fecha[$col_info['fecha']]++;
                            }
                        }
                    }
                }
                
                if (empty($data)) {
                    throw new Exception("No se encontraron datos válidos para importar");
                }
                
                if ($this->HistoricoPreciosModel->insert_prices_with_transaction($data)) {
                    $response = [
                        'status' => 1,
                        'message' => "✓ Importación exitosa: " . count($data) . " registros",
                        'details' => [
                            'total_fechas' => count($columnas_fechas),
                            'total_registros' => count($data),
                            'fechas' => array_keys($registros_por_fecha),
                            'registros_por_fecha' => $registros_por_fecha
                        ]
                    ];
                } else {
                    throw new Exception("Error al guardar en base de datos");
                }
                
            } catch (\Throwable $e) {
                $response = [
                    'status' => 0,
                    'message' => "✗ Error: " . $e->getMessage(),
                    'debug'   => [
                        'type'  => get_class($e),
                        'file'  => $e->getFile(),
                        'line'  => $e->getLine(),
                        'trace' => substr($e->getTraceAsString(), 0, 800),
                    ]
                ];
            } finally {
                restore_error_handler();
            }

            echo json_encode($response);
        }
    }


    // public function import_file_historic_price(){
    //     if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['file_to_upload'])) {
    //         $response =0;

    //         $grupos = $this->GruposModel->get_rows();
    //         $productos = $this->ProductosModel->get_rows();
    //         $plazas = $this->PlazasModel->get_rows();
    //         // Crear un arreglo asociativo para mapear nombre de grupo a id_grupo
    //         $grupoMap = array_column($grupos, 'id_grupo', 'nombre');
    //         $productoMap = array_column($productos, 'id_productos', 'nombre');
    //         $plazaMap = array_column($plazas, 'Id_plaza', 'nombre'); // Mapeo de plazas

    //         $file = $_FILES['file_to_upload']['tmp_name'];
    //         $spreadsheet = IOFactory::load($file);
    //         $sheet = $spreadsheet->getActiveSheet();
    //         $data = [];

    //         foreach ($sheet->getRowIterator(2) as $row) {
    //             $cellIterator = $row->getCellIterator();
    //             $cellIterator->setIterateOnlyExistingCells(false); // Recorrer todas las celdas, incluso si están vacías
    //             $rowData = [];

    //             foreach ($cellIterator as $cell) {
    //                 $rowData[] = trim($cell->getValue()); // Obtiene y limpia el valor de la celda
    //             }

    //             if (empty($rowData[0])) {//Verificar si la primera celda está vacía
    //                 continue; // Si la primera celda está vacía, saltar la fila
    //             }
    //             if (array_filter($rowData)) {// Validar que la fila no esté completamente vacía
    //                 if (\PhpOffice\PhpSpreadsheet\Shared\Date::isDateTime($sheet->getCell('A'.$row->getRowIndex())) && is_numeric($rowData[0])) {
    //                     // Convierte la fecha a un string en formato 'Y-m-d'
    //                     $fecha = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($rowData[0])->format('Y-m-d');
    //                 } else {
    //                     $fecha = (string) $rowData[0];// Si no es una fecha válida, convierte el valor a string por si acaso
    //                 }
    //                 $id_grupo = $grupoMap[$rowData[1]] ?? null;
    //                 $id_producto = $productoMap[$rowData[3]] ?? null;
    //                 $Id_plaza = $plazaMap[strtolower($rowData[4])] ?? null;
    //                 if ($rowData[2] != "" && $rowData[2] != "-" ){
    //                    $price= $rowData[2];
    //                 }else{
    //                     $price = null;
    //                 }
    //                 $price =
    //                 $data[] = [
    //                     'fecha' => $fecha,
    //                     'grupo' => $rowData[1],
    //                     'id_grupo' => $id_grupo,
    //                     'precios' => $price,
    //                     'producto' => $rowData[3],
    //                     'id_productos' => $id_producto,
    //                     'plaza' => $rowData[4],
    //                     'Id_plaza' => $Id_plaza
    //                 ];
    //             }
    //         }

    //         if ($this->HistoricoPreciosModel->insert_prices_with_transaction($data)) {
    //             $response = array('status' => 1,'message' => 'Los precios se importaron correctamente');
    //           echo json_encode($response);
    //         } else {
    //             echo json_encode($response);
    //         }
    //     }
    // }



}