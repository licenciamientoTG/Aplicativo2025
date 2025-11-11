<?php

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\Legend;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\Title;

class Administration{
    public $twig;
    public $route;
    public CotizacionesModel $cotizacionesModel;
    public MojoTicketsModel $mojoTicketsModel;
    public EstacionesModel $estacionesModel;
    public BinnacleModel $binnacleModel;
    public DocumentosModel $documentosModel;
    public DespachosModel $despachosModel;
    public GasolinerasModel $gasolineras;
    public ClientesModel $clientesModel;


    /**
     * @param $twig
     */
    public function __construct($twig) {
        $this->twig              = $twig;
        $this->route             = 'views/administration/';
        $this->mojoTicketsModel  = new MojoTicketsModel();
        $this->cotizacionesModel = new CotizacionesModel();
        $this->estacionesModel   = new EstacionesModel();
        $this->binnacleModel     = new BinnacleModel();
        $this->documentosModel   = new DocumentosModel();
        $this->despachosModel    = new DespachosModel();
        $this->clientesModel     = new ClientesModel();
        $this->gasolineras       = new GasolinerasModel;

    }

    /**
     * @return void
     */
    public function monthly_dispatches() : void {
        echo $this->twig->render($this->route . 'monthly_dispatches.html');
    }
    public function relation_corpo_estaciones() : void {
        $estations = $this->gasolineras->get_estations_servidor();
        echo $this->twig->render($this->route . 'relation_corpo_estaciones.html', compact('estations'));
    }
    function list_tickets() : void {
        $from = $_GET['from'] ?? date('Y-m-d');
        $until = $_GET['until'] ?? date('Y-m-d');
        echo $this->twig->render($this->route . 'list_tickets.html', compact('from', 'until'));
    }

    function stats_tickets() : void {
        $date_range = $_GET['date_range'] ?? null;
        $ticket_form = $_GET['ticket_form'] ?? null;
        $tickets_forms = $this->mojoTicketsModel->get_tickets_forms();
        $from = null;
        $until = null;
        $supportTypes = null;
        $supportTypes_labels = null;
        $supportTypes_values = null;
        $ticket_users = null;
        $ticket_users_labels = null;
        $ticket_users_values = null;
        $normal_tickets = null;
        $normal_tickets_labels = null;
        $normal_tickets_values = null;
        $urgent_tickets = null;
        $urgent_tickets_labels = null;
        $urgent_tickets_values = null;
        $agents_total_tickets = null;
        $agents_solved_tickets = null;
        $agents_pending_tickets = null;
        $agents_urgent_tickets = null;
        $agents_normal_tickets = null;
        $groupedResultsMonths = null;
        $ticket_groups = null;
        $ticket_groups_labels = null;
        $ticket_groups_values = null;
        $ticket_departments = null;
        $ticket_departments_labels = null;
        $ticket_departments_values = null;

        $groupedResults = [];
        $monthNames = [1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'];
        if (!is_null($ticket_form)) {
            switch ($date_range) {
                case 'semanal':
                    $from = $this->getMondayFromWeek($_GET['from']);
                    $until = $this->getSundayFromWeek($_GET['until']);
                    $results = $this->mojoTicketsModel->get_tickets_by_form_and_week($from . 'T00:00:00',$until . 'T23:59:59',$ticket_form);
                    $groupedResults = [];
                    foreach ($results as $result) {
                        $year = $result['year'];
                        $week_number = 'Sem ' . $result['week_number'];
                        if (!isset($groupedResults[$year][$week_number])) {
                            $groupedResults[$year][$week_number] = [];
                        }
                        $groupedResults[$year][$week_number][] = $result;
                    }

                    // Eliminar el último arreglo dentro del arreglo principal
                    array_pop($groupedResults[$year]);

                    // Aqui vamos a trabajar sobre la pestaña de Atencion Urgente
                    $urgent_tickets = $this->mojoTicketsModel->get_urgent_tickets($from . 'T00:00:00',$until . 'T23:59:59', $ticket_form);
                    // Preparar etiquetas y valores
                    $urgent_tickets_labels = json_encode(array_column($urgent_tickets, 'week_number'));
                    $urgent_tickets_values = json_encode(array_column($urgent_tickets, 'avg_hours_elapsed'));

                    $normal_tickets = $this->mojoTicketsModel->get_normal_tickets($from . 'T00:00:00',$until . 'T23:59:59', $ticket_form);

                    // Preparar etiquetas y valores
                    $normal_tickets_labels = json_encode(array_column($normal_tickets, 'week_number'));
                    $normal_tickets_values = json_encode(array_column($normal_tickets, 'avg_hours_elapsed'));

                    // Ahora los datos de los agentes
                    $agents_total_tickets = $this->mojoTicketsModel->get_agents_tickets_total($from . 'T00:00:00',$until . 'T23:59:59', $ticket_form);
                    // Vamos a agrupar por el valor de la columna `AssignedUserName`
                    $agents_total_tickets = array_reduce($agents_total_tickets, function ($carry, $item) {
                        $carry[$item['AssignedUserName']][] = $item;
                        return $carry;
                    }, []);
                    break;
                case 'mensual':
                    $from = $this->getFirstDayOfMonth($_GET['from']);
                    $until = $this->getLastDayOfMonth($_GET['until']);

                    $results = $this->mojoTicketsModel->get_tickets_by_form_and_month($from . 'T00:00:00',$until . 'T23:59:59',$ticket_form);
                    $groupedResults = [];
                    foreach ($results as $result) {
                        $year = $result['year'];
                        $month = $result['month'];
                        $result['month_name'] = $monthNames[$month];
                        if (!isset($groupedResults[$year][$result['month_name']])) {
                            $groupedResults[$year][$result['month_name']] = [];
                        }
                        $groupedResults[$year][$result['month_name']][] = $result;
                    }

                    // Aqui vamos a trabajar sobre la pestaña de Atencion Urgente
                    $urgent_tickets = $this->mojoTicketsModel->get_urgent_tickets_months($from . 'T00:00:00',$until . 'T23:59:59', $ticket_form);

                    // Preparar etiquetas y valores
                    $urgent_tickets_labels = json_encode(array_column($urgent_tickets, 'month_name'));
                    $urgent_tickets_values = json_encode(array_column($urgent_tickets, 'avg_hours_elapsed'));

                    $normal_tickets = $this->mojoTicketsModel->get_normal_tickets_month($from . 'T00:00:00',$until . 'T23:59:59', $ticket_form);

                    // Preparar etiquetas y valores
                    $normal_tickets_labels = json_encode(array_column($normal_tickets, 'month_name'));
                    $normal_tickets_values = json_encode(array_column($normal_tickets, 'avg_hours_elapsed'));

                    // Ahora los datos de los agentes
                    $agents_total_tickets = $this->mojoTicketsModel->get_agents_tickets_total_month($from . 'T00:00:00',$until . 'T23:59:59', $ticket_form);

                    // Vamos a agrupar por el valor de la columna `AssignedUserName`
                    $agents_total_tickets = array_reduce($agents_total_tickets, function ($carry, $item) {
                        $carry[$item['AssignedUserName']][] = $item;
                        return $carry;
                    }, []);

                    break;

                case 'anual':
                    $from = $this->getFirstDayOfYear($_GET['from']);
                    $until = $this->getLastDayOfYear($_GET['until']);

                    $results = $this->mojoTicketsModel->get_tickets_by_form_and_year($from . 'T00:00:00',$until . 'T23:59:59',$ticket_form);
                    $groupedResults = [];
                    foreach ($results as $result) {
                        $year = $result['year'];
                        if (!isset($groupedResults[$year])) {
                            $groupedResults[$year] = [];
                        }
                        $groupedResults[$year][] = $result;
                    }

                    // Aqui vamos a trabajar sobre la pestaña de Atencion Urgente
                    $urgent_tickets = $this->mojoTicketsModel->get_urgent_tickets_years($from . 'T00:00:00',$until . 'T23:59:59', $ticket_form);

                    // Preparar etiquetas y valores
                    $urgent_tickets_labels = json_encode(array_column($urgent_tickets, 'year'));
                    $urgent_tickets_values = json_encode(array_column($urgent_tickets, 'avg_hours_elapsed'));


                    $normal_tickets = $this->mojoTicketsModel->get_normal_tickets_years($from . 'T00:00:00',$until . 'T23:59:59', $ticket_form);

                    // Preparar etiquetas y valores
                    $normal_tickets_labels = json_encode(array_column($normal_tickets, 'year'));
                    $normal_tickets_values = json_encode(array_column($normal_tickets, 'avg_hours_elapsed'));

                    // Ahora los datos de los agentes
                    $agents_total_tickets = $this->mojoTicketsModel->get_agents_tickets_total_year($from . 'T00:00:00',$until . 'T23:59:59', $ticket_form);

                    // Vamos a agrupar por el valor de la columna `AssignedUserName`
                    $agents_total_tickets = array_reduce($agents_total_tickets, function ($carry, $item) {
                        $carry[$item['AssignedUserName']][] = $item;
                        return $carry;
                    }, []);

                    break;
            }

            // Aqui vamos a trabajar la parte de Tipos Soporte
            $supportTypes = $this->mojoTicketsModel->get_support_types($from . 'T00:00:00',$until . 'T23:59:59', $ticket_form);
            // Preparar etiquetas y valores
            $supportTypes_labels = json_encode(array_column($supportTypes, 'problem'));
            $supportTypes_values = json_encode(array_column($supportTypes, 'total'));


            // Aqui vamos a trabajar la parte de Clientes
            $ticket_users = $this->mojoTicketsModel->get_ticket_users($from . 'T00:00:00',$until . 'T23:59:59', $ticket_form);

            // Preparar etiquetas y valores
            $ticket_users_labels = json_encode(array_column($ticket_users, 'full_name'));
            $ticket_users_values = json_encode(array_column($ticket_users, 'total'));

            // Aqui vamos a trabajar la parte de Clientes
            $ticket_groups = $this->mojoTicketsModel->get_ticket_groups($from, $until, $ticket_form);

            // Preparar etiquetas y valores
            $ticket_groups_labels = json_encode(array_column($ticket_groups, 'full_name'));
            $ticket_groups_values = json_encode(array_column($ticket_groups, 'total'));

            // Aqui vamos a trabajar la parte de Clientes
            $ticket_departments = $this->mojoTicketsModel->get_ticket_departments($from, $until, $ticket_form);

            // Preparar etiquetas y valores
            $ticket_departments_labels = json_encode(array_column($ticket_departments, 'full_name'));
            $ticket_departments_values = json_encode(array_column($ticket_departments, 'total'));

            $monthly_results = $this->mojoTicketsModel->get_tickets_by_form_and_month(date("Y-m-01", strtotime("first day of -1 year")),date("Y-m-t"),$ticket_form);
            $groupedResultsMonths = [];
            foreach ($monthly_results as $result) {
                $year = $result['year'];
                $month = $result['month'];
                $result['month_name'] = $monthNames[$month];
                if (!isset($groupedResultsMonths[$year][$result['month_name']])) {
                    $groupedResultsMonths[$year][$result['month_name']] = [];
                }
                $groupedResultsMonths[$year][$result['month_name']][] = $result;
            }
        }

        $input_from = $_GET['from'] ?? (date('Y') . '-W' . date('W'));
        $input_until = $_GET['until'] ?? (date('Y') . '-W' . date('W'));

        echo $this->twig->render($this->route . 'stats_tickets.html', compact('date_range', 'from', 'input_from', 'until', 'input_until', 'tickets_forms', 'ticket_form', 'groupedResults', 'supportTypes', 'supportTypes_labels', 'supportTypes_values', 'ticket_users', 'ticket_users_labels', 'ticket_users_values', 'normal_tickets', 'normal_tickets_labels','normal_tickets_values', 'urgent_tickets', 'urgent_tickets_labels','urgent_tickets_values','agents_total_tickets', 'agents_solved_tickets','agents_pending_tickets', 'agents_urgent_tickets','agents_normal_tickets','groupedResultsMonths', 'ticket_groups', 'ticket_groups_labels', 'ticket_groups_values', 'ticket_departments', 'ticket_departments_values', 'ticket_departments_labels'));
    }

function ecv_calc() {

    $consulted = isset($_GET['submitted']);

    // 1) Parámetros base
    $ticket_form_id = isset($_GET['ticket_form_id']) ? (int)$_GET['ticket_form_id'] : 51598;

    // 2) Período y fechas (siempre válidos)
    $period = $_GET['period'] ?? date('Y-m');
    if (!is_string($period) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
        $period = date('Y-m');
    }
    $dt = DateTime::createFromFormat('Y-m-d', $period . '-01');
    if (!$dt) {
        $dt = new DateTime('first day of this month');
        $period = $dt->format('Y-m');
    }
    $fecha_ini = $dt->format('Y-m-01');
    $fecha_fin = $dt->format('Y-m-t');

    $startBound = $fecha_ini . ' 00:00:00';
    $endBound   = $fecha_fin . ' 23:59:59';

    // 3) Estructuras
    $rows = [];
    $total_tickets = 0;
    $total_tickets_abiertos = 0;
    $total_tickets_cerrados = 0;
    $tiempo_total = 0.0;
    $promedio = '0.00';
    $agentes = [];
    $lista_agentes = []; // UI: -1 = "Sin asignar" (no existe "Todos" aquí)

    // Helper: nombre corto
    $shortName = function (?string $full): string {
        $full = trim(preg_replace('/\s+/', ' ', (string)$full));
        if ($full === '') return '';
        $titles = ['sr','sra','srta','ing','lic','dra','dr','mtro','mtra','arq','cp','c','c.','--'];
        $parts  = preg_split('/\s+/', $full);
        while ($parts && in_array(mb_strtolower(rtrim($parts[0], '.'), 'UTF-8'), $titles, true)) {
            array_shift($parts);
        }
        if (!$parts) return '';
        $firstName = $parts[0];
        $surname = '';
        $connectors = ['de','del','la','las','los','da','das','do','dos','di','van','von','y','e','della','dalla','du','le','san','santa','mac','mc'];
        if (isset($parts[1])) {
            $i = 1; $s = [];
            while ($i < count($parts) && in_array(mb_strtolower($parts[$i], 'UTF-8'), $connectors, true)) {
                $s[] = $parts[$i]; $i++;
            }
            if (!empty($s) && isset($parts[$i])) { $s[] = $parts[$i]; $surname = implode(' ', $s); }
            else { $surname = $parts[1]; }
        }
        return trim($firstName . ' ' . $surname);
    };

    // 4) Construir SIEMPRE el listado del combo (incluye -1 = "Sin asignar")
    //    Usamos [0] para que el modelo devuelva todo el período; de ahí armamos el combo.
    $rows_combo = $this->mojoTicketsModel->ecv_calc_var($fecha_ini, $fecha_fin, $ticket_form_id, [0]) ?: [];
    $visto_ids = [];
    foreach ($rows_combo as $t) {
        $raw = $t['assigned_to_id'] ?? 0;
        $id  = (int)$raw;
        // Mapear 0/null del modelo a -1 (UI)
        $id_ui = ($id === 0) ? -1 : $id;

        if (!isset($lista_agentes[$id_ui])) {
            $label =
                ($t['assigned_to_name'] ?? null)
                ?? ($t['agent'] ?? null)
                ?? ($id_ui === -1 ? 'Sin asignar' : 'Agente #'.$id_ui);

            // Si es -1, aseguramos el texto exacto
            if ($id_ui === -1) $label = 'Sin asignar';

            $lista_agentes[$id_ui] = $label;
        }
        $visto_ids[$id_ui] = true;
    }
    // Asegurar que "Sin asignar" exista aunque no haya en los datos
    if (!isset($lista_agentes[-1])) {
        $lista_agentes[-1] = 'Sin asignar';
    }
    if (!empty($lista_agentes)) {
        asort($lista_agentes, SORT_NATURAL | SORT_FLAG_CASE);
    }

    // 5) Leer selección del request (UI usa -1 para "Sin asignar")
    $assigned_param = $_GET['assigned_to_id'] ?? null;
    if (is_string($assigned_param)) {
        $assigned_param = array_filter(array_map('trim', explode(',', $assigned_param)), 'strlen');
    }
    if ($assigned_param !== null && !is_array($assigned_param)) {
        $assigned_param = [$assigned_param];
    }
    $selected_ids = ($assigned_param === null)
        ? []  // sin selección => “Todos”
        : array_values(array_unique(array_map('intval', $assigned_param)));

    // Conjunto completo disponible (UI IDs, incluye -1)
    $all_ids = array_map('intval', array_keys($lista_agentes));
    sort($all_ids);

    $selected_sorted = $selected_ids;
    sort($selected_sorted);

    // ¿Es “todos”? (sin selección o selección == todos los ítems del combo)
    $is_all = (empty($selected_sorted) || $selected_sorted === $all_ids);

    // Para UI/URLs (PDF): mantenemos '0' cuando es “todos”; si no, CSV de selección (puede incluir -1)
    $assigned_to_ids = $is_all ? $all_ids : $selected_sorted;
    $assigned_to_csv = $is_all ? '0' : implode(',', $assigned_to_ids);

    // 6) Consulta de datos
    if ($consulted) {

        $incluye_sin_asignar = in_array(-1, $assigned_to_ids, true);
        $ids_reales = array_values(array_filter($assigned_to_ids, fn($x) => $x !== -1));

        if ($is_all) {
            // TODOS: que el modelo traiga todo
            $rows = $this->mojoTicketsModel->ecv_calc_var($fecha_ini, $fecha_fin, $ticket_form_id, [0]) ?: [];
        } else {
            if ($incluye_sin_asignar) {
                // Caso mixto (con “Sin asignar”): obtener TODO y filtrar en PHP
                // (si quieres optimizar, podrías hacer dos llamadas y unir, pero esta es la más robusta)
                $rows_all = $this->mojoTicketsModel->ecv_calc_var($fecha_ini, $fecha_fin, $ticket_form_id, [0]) ?: [];
                $rows = array_values(array_filter($rows_all, function($t) use ($ids_reales) {
                    $rid = (int)($t['assigned_to_id'] ?? 0);
                    if (in_array($rid, [0, null], true)) return true;             // sin asignar
                    if (!empty($ids_reales) && in_array($rid, $ids_reales, true)) return true; // agentes seleccionados
                    return false;
                }));
            } else {
                // Solo agentes reales
                if (!empty($ids_reales)) {
                    $rows = $this->mojoTicketsModel->ecv_calc_var($fecha_ini, $fecha_fin, $ticket_form_id, $ids_reales) ?: [];
                } else {
                    // Selección vacía, equivalente a TODOS
                    $rows = $this->mojoTicketsModel->ecv_calc_var($fecha_ini, $fecha_fin, $ticket_form_id, [0]) ?: [];
                }
            }
        }

        // Agregaciones
        $total_tickets = count($rows);

        foreach ($rows as $ticket) {
            $rid_raw = $ticket['assigned_to_id'] ?? 0;
            $rid     = (int)$rid_raw;
            $id_ui   = ($rid === 0) ? -1 : $rid; // clave UI

            $name = $ticket['assigned_to_name'] ?? ($id_ui === -1 ? 'Sin asignar' : 'Agente #'.$id_ui);

            if (!isset($agentes[$id_ui])) {
                $agentes[$id_ui] = [
                    'id'                   => $id_ui,
                    'name'                 => $name,
                    'short_name'           => ($id_ui === -1 ? 'Sin asignar' : $shortName($name)),
                    'total_cerrados'       => 0,
                    'total_abiertos'       => 0,
                    'tiempo_total'         => 0.0,
                    'total_tickets'        => 0,
                    'tickets_normal'       => 0,
                    'tiempo_total_normal'  => 0.0,
                    'promedio_normal'      => 0.0,
                    'tickets_urgente'      => 0,
                    'tiempo_total_urgente' => 0.0,
                    'promedio_urgente'     => 0.0,
                ];
            }

            $agentes[$id_ui]['total_tickets']++;

            $estatus    = $ticket['estatus'] ?? '';
            $created_on = $ticket['created_on'] ?? null;
            $solved_on  = $ticket['solved_on'] ?? null;

            if ($estatus === 'Cerrado' && $solved_on >= $startBound && $solved_on <= $endBound) {
                $agentes[$id_ui]['total_cerrados']++;
                $total_tickets_cerrados++;
            }

            if (
                ($estatus === 'Abierto' && $created_on <= $endBound) ||
                ($created_on <= $endBound && $solved_on >= $endBound) ||
                ($created_on <= $endBound && $solved_on == null)
            ) {
                $agentes[$id_ui]['total_abiertos']++;
                $total_tickets_abiertos++;
            }

            $hora_tot = (float)($ticket['hora_tot'] ?? 0.0);
            $agentes[$id_ui]['tiempo_total'] += $hora_tot;
            $tiempo_total += $hora_tot;

            $priority_id = isset($ticket['priority_id']) ? (int)$ticket['priority_id'] : null;
            if ($priority_id === 10 || $priority_id === 20) {
                $agentes[$id_ui]['tickets_urgente']++;
                $agentes[$id_ui]['tiempo_total_urgente'] += $hora_tot;
            } elseif ($priority_id === 30 || $priority_id === 40) {
                $agentes[$id_ui]['tickets_normal']++;
                $agentes[$id_ui]['tiempo_total_normal'] += $hora_tot;
            }
        }

        foreach ($agentes as $aid => $data) {
            $agentes[$aid]['promedio_normal'] = ($data['tickets_normal'] > 0)
                ? round($data['tiempo_total_normal'] / $data['tickets_normal'], 2) : 0.0;
            $agentes[$aid]['promedio_urgente'] = ($data['tickets_urgente'] > 0)
                ? round($data['tiempo_total_urgente'] / $data['tickets_urgente'], 2) : 0.0;
        }

        $promedio = $total_tickets > 0 ? number_format($tiempo_total / $total_tickets, 2, '.', ',') : '0.00';
    }

    // 7) Render
    echo $this->twig->render($this->route . 'ecv_calc.html', compact(
        'consulted',
        'total_tickets','total_tickets_abiertos','total_tickets_cerrados','promedio',
        'agentes','period','fecha_ini','fecha_fin','ticket_form_id',
        'assigned_to_ids','assigned_to_csv','lista_agentes'
    ));
}


function filtered_statistics($action, $period, $ticket_form_id, $agent_id = 0) {
    // 1) Período seguro
    if (!is_string($period) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
        $period = date('Y-m');
    }
    $dt = DateTime::createFromFormat('Y-m-d', $period . '-01');
    if (!$dt) {
        $dt = new DateTime('first day of this month');
        $period = $dt->format('Y-m');
    }
    $fecha_ini = $dt->format('Y-m-01');
    $fecha_fin = $dt->format('Y-m-t');

    // 2) Normaliza "agent_id" para aceptar: 0 (todos), -1 (sin asignar), entero, o CSV "12,34,-1"
    $agent_param = $agent_id;

    // Si llega como CSV en la ruta (p.ej. '12,34' o '0' o '-1,12')
    if (is_string($agent_param) && strpos($agent_param, ',') !== false) {
        $ids = array_filter(array_map('trim', explode(',', $agent_param)), 'strlen');
    } else {
        // único valor (string o int)
        $ids = [$agent_param];
    }

    // Limpia a enteros únicos
    $ids = array_values(array_unique(array_map('intval', $ids)));

    // Flags y grupos
    $has_all         = in_array(0, $ids, true);     // 0 => TODOS (convención del modelo)
    $has_unassigned  = in_array(-1, $ids, true);    // -1 => SIN ASIGNAR (nueva convención de UI)
    $positive_ids    = array_values(array_filter($ids, static fn($v) => $v > 0));

    // Determina cómo consultar al modelo y si hay filtrado posterior
    // Casos:
    // - (vacío) o incluye 0 => TODOS -> modelo [0], sin post-filtro
    // - solo -1 => SOLO SIN ASIGNAR -> modelo [0], post-filtro unassigned
    // - -1 + algunos ids > 0 => modelo [0], post-filtro (unassigned OR in positive_ids)
    // - solo ids > 0 => modelo [ids], sin post-filtro
    $need_post_filter = false;
    $post_filter = null;

    if ($has_all || empty($ids)) {
        $filter_for_model = [0]; // TODOS
    } else {
        if ($has_unassigned && empty($positive_ids)) {
            // Solo sin asignar
            $filter_for_model = [0]; // traemos todos y luego filtramos en PHP
            $need_post_filter = true;
            $post_filter = static function(array $t): bool {
                $v = $t['assigned_to_id'] ?? null;
                return $v === null || $v === '' || (int)$v === 0;
            };
        } elseif ($has_unassigned && !empty($positive_ids)) {
            // Sin asignar + algunos agentes específicos
            $targets = array_flip($positive_ids);
            $filter_for_model = [0]; // traemos todos y filtramos combinación
            $need_post_filter = true;
            $post_filter = static function(array $t) use ($targets): bool {
                $v = $t['assigned_to_id'] ?? null;
                $is_unassigned = ($v === null || $v === '' || (int)$v === 0);
                $is_specific   = isset($targets[(int)$v]);
                return $is_unassigned || $is_specific;
            };
        } else {
            // Solo agentes específicos
            $filter_for_model = $positive_ids;
        }
    }

    // 3) Trae filas desde el modelo (el modelo ya respeta ALL / lista de IDs)
    $rows = $this->mojoTicketsModel->ecv_calc_var($fecha_ini, $fecha_fin, (int)$ticket_form_id, $filter_for_model) ?: [];

    // Si se requiere filtrado posterior (para sin asignar y/o combinación con agentes)
    if ($need_post_filter && $post_filter) {
        $rows = array_values(array_filter($rows, $post_filter));
    }

    // (Opcional) Override por query param ?only_unassigned=1
    // Se mantiene por compatibilidad, aunque ya no es necesario si usas -1.
    $only_unassigned = isset($_GET['only_unassigned']) && (int)$_GET['only_unassigned'] === 1;
    if ($only_unassigned) {
        $rows = array_values(array_filter($rows, static function(array $t) {
            $v = $t['assigned_to_id'] ?? null;
            return $v === null || $v === '' || (int)$v === 0;
        }));
    }

    // 4) Ventanas
    $startBound = $fecha_ini . ' 00:00:00';
    $endBound   = $fecha_fin . ' 23:59:59';

    // 5) Normalizador
    $normalize = static function(array $ticket): array {
        if (isset($ticket['solicitante'])) {
            $ticket['solicitante'] = ucwords(strtolower(trim($ticket['solicitante'], '-')));
        }
        foreach (['created_on', 'solved_on'] as $field) {
            if (!empty($ticket[$field])) {
                if (substr($ticket[$field], -4) === '.000') {
                    $ticket[$field] = substr($ticket[$field], 0, -4);
                }
            }
        }
        return $ticket;
    };

    // 6) Acciones
    switch ($action) {
        case 'total_tickets': {
            $result = [];
            foreach ($rows as $key => $ticket) {
                $result[$key] = $normalize($ticket);
            }
            echo $this->twig->render($this->route . 'filtered_statistics.html', compact('result'));
            break;
        }

        case 'open_tickets': {
            $result = [];
            foreach ($rows as $key => $ticket) {
                if (
                    (($ticket['estatus'] ?? '') === 'Abierto' && ($ticket['created_on'] ?? '') <= $endBound)
                    || (($ticket['created_on'] ?? '') <= $endBound && ($ticket['solved_on'] ?? '') >= $endBound)
                    || (($ticket['created_on'] ?? '') <= $endBound && ($ticket['solved_on'] ?? null) === null)
                ) {
                    $result[$key] = $normalize($ticket);
                }
            }
            echo $this->twig->render($this->route . 'filtered_statistics.html', compact('result'));
            break;
        }

        case 'closed_tickets': {
            $result = [];
            foreach ($rows as $key => $ticket) {
                $solved = $ticket['solved_on'] ?? '';
                if (($ticket['estatus'] ?? '') === 'Cerrado' && $solved >= $startBound && $solved <= $endBound) {
                    $result[$key] = $normalize($ticket);
                }
            }
            echo $this->twig->render($this->route . 'filtered_statistics.html', compact('result'));
            break;
        }

        default:
            var_dump("Revisar");
            break;
    }
}




    function getMondayFromWeek($weekString) {
        // Parse the year and week from the input string
        list($year, $week) = sscanf($weekString, '%4d-W%2d');

        // Create a DateTime object for the first day of the given year
        $date = new DateTime();
        $date->setISODate($year, $week);

        // Return the date in 'Y-m-d' format
        return $date->format('Y-m-d');
    }

    function getSundayFromWeek($weekString) {
        // Get the Monday of the week
        $monday = $this->getMondayFromWeek($weekString);

        // Create a DateTime object for Monday
        $date = new DateTime($monday);

        // Add 6 days to get Sunday
        $date->modify('+6 days');

        // Return the date in 'Y-m-d' format
        return $date->format('Y-m-d');
    }

    function getFirstDayOfMonth($monthString) {
        // Parse the year and month from the input string
        list($year, $month) = sscanf($monthString, '%4d-%2d');

        // Create a DateTime object for the first day of the given month
        $date = new DateTime();
        $date->setDate($year, $month, 1);

        // Return the date in 'Y-m-d' format
        return $date->format('Y-m-d');
    }

    function getLastDayOfMonth($monthString) {
        // Parse the year and month from the input string
        list($year, $month) = sscanf($monthString, '%4d-%2d');

        // Create a DateTime object for the first day of the next month
        $date = new DateTime();
        $date->setDate($year, $month + 1, 1);
        // Subtract one day to get the last day of the current month
        $date->modify('-1 day');

        // Return the date in 'Y-m-d' format
        return $date->format('Y-m-d');
    }

    function getFirstDayOfYear($yearString) {
        $date = new DateTime();
        $date->setDate($yearString, 1, 1);
        return $date->format('Y-m-d');
    }

    function getLastDayOfYear($yearString) {
        $date = new DateTime();
        $date->setDate($yearString, 12, 31);
        return $date->format('Y-m-d');
    }

    /**
     * @param $codgas
     * @param $from
     * @param $until
     * @return void
     * @throws Exception
     */
    public function datatables_monthly_dispatches($codgas = null, $from, $until) : void {
        $dispatchesModel = new DespachosModel();
        // Convertir el arreglo de datos a formato JSON
            $data = [];
            if ($dispatches = $dispatchesModel->get_rows(is_null($codgas) ? 2 : $codgas, $from, $until)) {

                $data = array_map(function ($dispatche) {
                    return [
                        'Fecha'    => $dispatche['Fecha'],
                        'Estacion' => $dispatche['abr'],
                        'Despacho' => $dispatche['Despacho'],
                        'Posicion' => $dispatche['Posicion'],
                        'Producto' => $dispatche['Producto'],
                        'Cantidad' => $dispatche['Cantidad'],
                        'Precio'   => $dispatche['Precio'],
                        'Importe'  => $dispatche['Importe'],
                        'Nota'     => $dispatche['Nota'],
                        'Factura'  => $dispatche['Factura'],
                        'UUID'     => $dispatche['UUID'],
                        'Cliente'  => $dispatche['Cliente'],
                        'Codigo'   => $dispatche['Codigo'],
                        'Vehiculo' => $dispatche['Vehiculo'],
                        'Placas'   => $dispatche['Despacho'],
                        'tiptrn'   => $dispatche['tiptrn'],
                        'nrotur'   => $dispatche['nrotur']
                    ];
                }, $dispatches);
            }
        json_output(array("data" => $data));
    }

    function datatables_tickets() {
        $from = $_POST['from'] ?? date('Y-m-d', strtotime('-1 day'));
        $until = $_POST['until'] ?? date('Y-m-d', strtotime('-1 day'));

        // Obtener los tickets de la base de datos
        $tickets = $this->mojoTicketsModel->get_tickets($from . ' 00:00:00', $until  . ' 23:59:59');
        $data = array_map(function ($ticket) {
            // $actions = '<a href="javascript:void(0);" class="btn btn-sm btn-primary"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-center feather feather-eye align-middle"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg></a>';
            $actions = '<a href="javascript:void(0);" onclick="delete_ticket('. $ticket['id_mojo'] .');" class="btn btn-sm btn-danger"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-center feather feather-trash-2 align-middle"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg></a>';

            $agent = ($ticket['agent'] != 'Sin asignar') ? $ticket['agent'] : "<a href='javascript:void();' onclick='updateMojoTicket({$ticket['id_mojo']});'>{$ticket['agent']}</a>";
            return [
                'ID'               => "<a href='https://totalgas.mojohelpdesk.com/ma/#/tickets/search?query_string={$ticket['id_mojo']}&page=1' target='_blank'>{$ticket['id_mojo']}</a>",
                'TIPO'             => $ticket['ticket_type'],
                'FORMULARIO'       => $ticket['ticket_form'],
                'GRUPO'            => $ticket['company'],
                'TÍTULO'           => $ticket['truncated_title'],
                'DESCRIPCIÓN'      => $ticket['truncated_description'],
                'CREADO'           => $ticket['created_on'],
                'RESUELTO'         => $ticket['solved_on'],
                'TIEMPO_RESPUESTA' => $ticket['hours_to_resolve'],
                'USUARIO'          => $ticket['username'],
                'AGENTE'           => $agent,
                'STATUS'           => $ticket['status'],
                'PRIORIDAD'        => $ticket['priority'],
                'COLA'             => $ticket['queue'],
                'ASIGNADO'         => $ticket['assigned_on'],
                'ACTUALIZADO'      => $ticket['updated_on'],
                'CALIFICACIÓN'     => $ticket['rating'],
                'DEPARTAMENTO'     => $ticket['requesting_department'],
                'SOLICITANTE'      => $ticket['applicants_name'],
                'PROBLEMA'         => $ticket['problem'],
                'ACCIONES'         => $actions
            ];
        }, $tickets);
        json_output(array("data" => $data));
    }

    function datatables_tickets_2() {
        $date_range = $_POST['date_range'] ?? null;
        $ticket_form = $_POST['ticket_form'] ?? null;
        switch ($date_range) {
            case 'semanal':
                $from = $this->getMondayFromWeek($_POST['from']);
                $until = $this->getSundayFromWeek($_POST['until']);
                break;
            case 'mensual':
                $from = $this->getFirstDayOfMonth($_POST['from']);
                $until = $this->getLastDayOfMonth($_POST['until']);
                break;
            case 'anual':
                $from = $this->getFirstDayOfYear($_POST['from']);
                $until = $this->getLastDayOfYear($_POST['until']);
                break;
        }

        // Obtener los tickets de la base de datos
        $tickets = $this->mojoTicketsModel->get_tickets_report($from . ' 00:00:00', $until  . ' 23:59:59', $ticket_form);
        $data = array_map(function ($ticket) {
            $actions = '<a href="javascript:void(0);" onclick="delete_ticket('. $ticket['id_mojo'] .');" class="btn btn-sm btn-danger"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-center feather feather-trash-2 align-middle"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg></a>';
            // $ticket['first_day_hours'], $ticket['middle_full_days_hours'], $ticket['last_day_hours'], $ticket['dia_semana_creacion'], $ticket['dia_semana_solucion']
            return [
                'ID'               => "<a href='https://totalgas.mojohelpdesk.com/ma/#/tickets/search?query_string={$ticket['id_mojo']}&page=1' target='_blank'>{$ticket['id_mojo']}</a>",
                'TIPO'             => $ticket['ticket_type'],
                'FORMULARIO'       => $ticket['ticket_form'],
                'GRUPO'            => $ticket['company'],
                'TÍTULO'           => $ticket['truncated_title'],
                'DESCRIPCIÓN'      => $ticket['truncated_description'],
                'CREADO'           => $ticket['created_on'],
                'RESUELTO'         => $ticket['solved_on'],
                'TIEMPO_RESPUESTA' => '<a href="javascript:void(0);" onclick="mostrarDetalleHoras('. htmlspecialchars(json_encode([
                        'first'      => number_format(((is_null($ticket['first_day_hours'])) ? 0 : $ticket['first_day_hours']), 2, '.', ',') . ' horas',
                        'middle'     => number_format(((is_null($ticket['middle_full_days_hours'])) ? 0 : $ticket['middle_full_days_hours']), 2, '.', ',') . ' horas',
                        'last'       => number_format(((is_null($ticket['last_day_hours'])) ? 0 : $ticket['last_day_hours']), 2, '.', ',') . ' horas',
                        'dia_crea'   => $ticket['dia_semana_creacion'],
                        'dia_res'    => $ticket['dia_semana_solucion']
                    ]), ENT_QUOTES, 'UTF-8') . ')">' . $ticket['hours_to_resolve'] . '</a>',
                'USUARIO'          => $ticket['username'],
                'AGENTE'           => $ticket['agent'],
                'STATUS'           => $ticket['status'],
                'PRIORIDAD'        => $ticket['priority'],
                'COLA'             => $ticket['queue'],
                'ASIGNADO'         => $ticket['assigned_on'],
                'ACTUALIZADO'      => $ticket['updated_on'],
                'CALIFICACIÓN'     => $ticket['rating'],
                'DEPARTAMENTO'     => $ticket['requesting_department'],
                'SOLICITANTE'      => $ticket['applicants_name'],
                'PROBLEMA'         => $ticket['problem'],
                'ACCIONES'         => $actions
            ];
        }, $tickets);
        json_output(array("data" => $data));
    }

    function update_mojo() {
        ini_set('memory_limit', '256M');
            ini_set('max_execution_time', 300);
        $data = $this->get_latest_tickets(1);
        foreach ($data as $ticket) {
            $ticket = $this->get_ticket($ticket['id']);
            $this->mojoTicketsModel->update_ticket($ticket);
        }
        // Actualizamos la información de los grupos, colas, usuarios, etiquetas, formularios y tipos de tickets
        $this->update_groups();

        $this->update_ticket_queues();

        $this->update_users();

        $this->update_ticket_tags();

        $this->update_ticket_forms();

        $this->update_ticket_types();

        return json_output(array("success" => true, "message" => "Tickets actualizados correctamente"));
    }

    function get_latest_tickets($page) {
        // Establecer la URL y los parámetros
        $url = 'https://totalgas.mojohelpdesk.com/api/v3/tickets/search';

        // Parámetros de la consulta
        $params = [
            'access_key' => 'f68cddda794b0bf9582c23b7b3099011d95c60ce',
            'per_page'   => 100,
            'page'       => $page, // Asegúrate de que $page esté definido correctamente
            'sf'         => 'updated_on'
        ];

        // Construir la URL con los parámetros
        $queryString = http_build_query($params);
        $fullUrl = $url . '?' . $queryString;

        // Inicializar cURL
        $ch = curl_init();

        // Establecer opciones de cURL
        curl_setopt($ch, CURLOPT_URL, $fullUrl); // Esta opción establece la URL a la cual se va a realizar la solicitud cURL.
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Esta opción indica que se debe devolver la respuesta de la solicitud como una cadena en lugar de directamente imprimirla en la salida.
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Esta opción desactiva la verificación del certificado SSL del servidor.
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); // Esta opción desactiva la verificación del nombre del host en el certificado SSL del servidor.

        // Ejecutar la solicitud y obtener la respuesta
        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            echo 'Error:' . curl_error($ch);
        } else {
            // Procesar la respuesta (por ejemplo, decodificar JSON)
            $data = json_decode($response, true);
            curl_close($ch);
            return $data['result'];
        }
    }

    function get_ticket($id) {
        // Establecer la URL y los parámetros
        $url = 'https://totalgas.mojohelpdesk.com/api/v3/tickets/' . $id;
        $params = [
            'access_key' => 'f68cddda794b0bf9582c23b7b3099011d95c60ce'
        ];

        // Construir la URL con los parámetros
        $queryString = http_build_query($params);
        $fullUrl = $url . '?' . $queryString;

        // Inicializar cURL
        $ch = curl_init();

        // Establecer opciones de cURL
        curl_setopt($ch, CURLOPT_URL, $fullUrl); // Esta opción establece la URL a la cual se va a realizar la solicitud cURL.
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Esta opción indica que se debe devolver la respuesta de la solicitud como una cadena en lugar de directamente imprimirla en la salida.
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Esta opción desactiva la verificación del certificado SSL del servidor.
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); // Esta opción desactiva la verificación del nombre del host en el certificado SSL del servidor.

        // Ejecutar la solicitud y obtener la respuesta
        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            echo 'Error:' . curl_error($ch);
        } else {
            // Procesar la respuesta (por ejemplo, decodificar JSON)
            $data = json_decode($response, true);
            curl_close($ch);
            return $data;
        }
    }

    function update_groups() {
        // Establecer la URL y los parámetros
        $url = 'https://totalgas.mojohelpdesk.com/api/v2/groups';
        $params = [
            'access_key' => 'f68cddda794b0bf9582c23b7b3099011d95c60ce'
        ];

        // Construir la URL con los parámetros
        $queryString = http_build_query($params);
        $fullUrl = $url . '?' . $queryString;

        // Inicializar cURL
        $ch = curl_init();

        // Establecer opciones de cURL
        curl_setopt($ch, CURLOPT_URL, $fullUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Esta opción desactiva la verificación del certificado SSL del servidor.
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); // Esta opción desactiva la verificación del nombre del host en el certificado SSL del servidor.

        // Ejecutar la solicitud y obtener la respuesta
        $response = curl_exec($ch);

        // Verificar si hubo un error
        if (curl_errno($ch)) {
            echo 'Error:' . curl_error($ch);
        } else {
            // Decodificar la respuesta JSON
            $groups = json_decode($response, true);

            foreach ($groups as $group) {
                $this->mojoTicketsModel->update_group($group);
            }
        }
        // Cerrar cURL
        curl_close($ch);
    }

    function update_ticket_queues() {
        // Establecer la URL y los parámetros
        $url = 'https://totalgas.mojohelpdesk.com/api/v2/ticket_queues';
        $params = [
            'access_key' => 'f68cddda794b0bf9582c23b7b3099011d95c60ce',
            'per_page' => 100,
            'page' => 1,
        ];

        // Construir la URL con los parámetros
        $queryString = http_build_query($params);
        $fullUrl = $url . '?' . $queryString;

        // Inicializar cURL
        $ch = curl_init();

        // Establecer opciones de cURL
        curl_setopt($ch, CURLOPT_URL, $fullUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Esta opción desactiva la verificación del certificado SSL del servidor.
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); // Esta opción desactiva la verificación del nombre del host en el certificado SSL del servidor.

        // Ejecutar la solicitud y obtener la respuesta
        $response = curl_exec($ch);

        // Verificar si hubo un error
        if (curl_errno($ch)) {
            echo 'Error:' . curl_error($ch);
        } else {
            // Decodificar la respuesta JSON
            $queues = json_decode($response, true);

            foreach ($queues as $queue) {
                $this->mojoTicketsModel->update_queue($queue);
            }
        }
        // Cerrar cURL
        curl_close($ch);
    }

    function update_users() {
        for ($page = 1; $page <= 3; $page++) {
            // Establecer la URL y los parámetros
            $url = 'https://totalgas.mojohelpdesk.com/api/v2/users';
            $params = [
                'access_key' => 'f68cddda794b0bf9582c23b7b3099011d95c60ce',
                'per_page' => 100,
                'page' => $page,
            ];

            // Construir la URL con los parámetros
            $queryString = http_build_query($params);
            $fullUrl = $url . '?' . $queryString;

            // Inicializar cURL
            $ch = curl_init();

            // Establecer opciones de cURL
            curl_setopt($ch, CURLOPT_URL, $fullUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Esta opción desactiva la verificación del certificado SSL del servidor.
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); // Esta opción desactiva la verificación del nombre del host en el certificado SSL del servidor.

            // Ejecutar la solicitud y obtener la respuesta
            $response = curl_exec($ch);

            // Verificar si hubo un error
            if (curl_errno($ch)) {
                echo 'Error:' . curl_error($ch);
            } else {
                // Decodificar la respuesta JSON
                $users = json_decode($response, true);

                foreach ($users as $user) {
                    $this->mojoTicketsModel->update_user($user);
                }
            }
            // Cerrar cURL
            curl_close($ch);
        }
    }

    function update_ticket_tags() {
        // Establecer la URL y los parámetros
        $url = 'https://app.mojohelpdesk.com/api/v2/tags';
        $params = [
            'access_key' => 'f68cddda794b0bf9582c23b7b3099011d95c60ce',
            'per_page' => 100
        ];

        // Construir la URL con los parámetros
        $queryString = http_build_query($params);
        $fullUrl = $url . '?' . $queryString;

        // Inicializar cURL
        $ch = curl_init();

        // Establecer opciones de cURL
        curl_setopt($ch, CURLOPT_URL, $fullUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Esta opción desactiva la verificación del certificado SSL del servidor.
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); // Esta opción desactiva la verificación del nombre del host en el certificado SSL del servidor.

        // Ejecutar la solicitud y obtener la respuesta
        $response = curl_exec($ch);

        // Verificar si hubo un error
        if (curl_errno($ch)) {
            echo 'Error:' . curl_error($ch);
        } else {
            // Decodificar la respuesta JSON
            $ticket_tags = json_decode($response, true);

            foreach ($ticket_tags as $tag) {
                $this->mojoTicketsModel->update_tag($tag);
            }
        }
        // Cerrar cURL
        curl_close($ch);
    }

    function update_ticket_forms() {
        // Establecer la URL y los parámetros
        $url = 'https://totalgas.mojohelpdesk.com/api/v2/ticket_forms';
        $params = [
            'access_key' => 'f68cddda794b0bf9582c23b7b3099011d95c60ce',
            'per_page' => 100
        ];

        // Construir la URL con los parámetros
        $queryString = http_build_query($params);
        $fullUrl = $url . '?' . $queryString;

        // Inicializar cURL
        $ch = curl_init();

        // Establecer opciones de cURL
        curl_setopt($ch, CURLOPT_URL, $fullUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Esta opción desactiva la verificación del certificado SSL del servidor.
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); // Esta opción desactiva la verificación del nombre del host en el certificado SSL del servidor.

        // Ejecutar la solicitud y obtener la respuesta
        $response = curl_exec($ch);

        // Verificar si hubo un error
        if (curl_errno($ch)) {
            echo 'Error:' . curl_error($ch);
        } else {
            // Decodificar la respuesta JSON
            $ticket_forms = json_decode($response, true);

            foreach ($ticket_forms as $form) {
                $this->mojoTicketsModel->update_form($form);
            }
        }
        // Cerrar cURL
        curl_close($ch);
    }

    function update_ticket_types() {
        // Establecer la URL y los parámetros
        $url = 'https://totalgas.mojohelpdesk.com/api/v2/ticket_types';
        $params = [
            'access_key' => 'f68cddda794b0bf9582c23b7b3099011d95c60ce',
            'per_page' => 100
        ];

        // Construir la URL con los parámetros
        $queryString = http_build_query($params);
        $fullUrl = $url . '?' . $queryString;

        // Inicializar cURL
        $ch = curl_init();

        // Establecer opciones de cURL
        curl_setopt($ch, CURLOPT_URL, $fullUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Esta opción desactiva la verificación del certificado SSL del servidor.
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); // Esta opción desactiva la verificación del nombre del host en el certificado SSL del servidor.

        // Ejecutar la solicitud y obtener la respuesta
        $response = curl_exec($ch);

        // Verificar si hubo un error
        if (curl_errno($ch)) {
            echo 'Error:' . curl_error($ch);
        } else {
            // Decodificar la respuesta JSON
            $ticket_types = json_decode($response, true);

            foreach ($ticket_types as $type) {
                $this->mojoTicketsModel->update_type($type);
            }
        }
        // Cerrar cURL
        curl_close($ch);
    }

    function delete_ticket($ticket_id) {
        if ($this->mojoTicketsModel->delete_ticket($ticket_id)) {
            json_output(array("success" => true, "message" => "Ticket eliminado correctamente"));
        } else {
            json_output(array("success" => false, "message" => "Error al eliminar el ticket"));
        }
    }

    function get_priority($from, $until, $ticket_form, $priority, $user_id = 0) {
        echo $this->twig->render($this->route . 'get_priority.html', compact('from', 'until', 'ticket_form', 'priority', 'user_id'));
    }

    function datatables_urgentes_tickets() {
        $from        = $_POST['from'];
        $until       = $_POST['until'];
        $ticket_form = $_POST['ticket_form'];
        $data = [];

        // Obtener los tickets de la base de datos
        $tickets = $this->mojoTicketsModel->get_tickets_report($from . ' 00:00:00', $until  . ' 23:59:59', $ticket_form);
        foreach ($tickets as $ticket) {
            if (in_array($ticket['priority_id'], [10,20])) {
                // $actions = '<a href="javascript:void(0);" class="btn btn-sm btn-primary"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-center feather feather-eye align-middle"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg></a>';
                $actions = '<a href="javascript:void(0);" onclick="delete_ticket('. $ticket['id_mojo'] .');" class="btn btn-sm btn-danger"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-center feather feather-trash-2 align-middle"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg></a>';

                $data[] =  array(
                    'ID'               => "<a href='https://totalgas.mojohelpdesk.com/ma/#/tickets/search?query_string={$ticket['id_mojo']}&page=1' target='_blank'>{$ticket['id_mojo']}</a>",
                    'TIPO'             => $ticket['ticket_type'],
                    'FORMULARIO'       => $ticket['ticket_form'],
                    'GRUPO'            => $ticket['company'],
                    'TÍTULO'           => $ticket['truncated_title'],
                    'DESCRIPCIÓN'      => $ticket['truncated_description'],
                    'CREADO'           => $ticket['created_on'],
                    'RESUELTO'         => $ticket['solved_on'],
                    'TIEMPO_RESPUESTA' => $ticket['hours_to_resolve'],
                    'USUARIO'          => $ticket['username'],
                    'AGENTE'           => $ticket['agent'],
                    'STATUS'           => $ticket['status'],
                    'PRIORIDAD'        => $ticket['priority'],
                    'COLA'             => $ticket['queue'],
                    'ASIGNADO'         => $ticket['assigned_on'],
                    'ACTUALIZADO'      => $ticket['updated_on'],
                    'CALIFICACIÓN'     => $ticket['rating'],
                    'DEPARTAMENTO'     => $ticket['requesting_department'],
                    'SOLICITANTE'      => $ticket['applicants_name'],
                    'PROBLEMA'         => $ticket['problem'],
                    'ACCIONES'         => $actions
                );
            }
        }
        json_output(array("data" => $data));
    }


    function datatables_normales_tickets() {
        $from        = $_POST['from'];
        $until       = $_POST['until'];
        $ticket_form = $_POST['ticket_form'];
        $data = [];

        // Obtener los tickets de la base de datos
        $tickets = $this->mojoTicketsModel->get_tickets_report($from . ' 00:00:00', $until  . ' 23:59:59', $ticket_form);
        foreach ($tickets as $ticket) {
            if (in_array($ticket['priority_id'], [30,40])) {
                // $actions = '<a href="javascript:void(0);" class="btn btn-sm btn-primary"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-center feather feather-eye align-middle"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg></a>';
                $actions = '<a href="javascript:void(0);" onclick="delete_ticket('. $ticket['id_mojo'] .');" class="btn btn-sm btn-danger"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-center feather feather-trash-2 align-middle"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg></a>';

                $data[] =  array(
                    'ID'               => "<a href='https://totalgas.mojohelpdesk.com/ma/#/tickets/search?query_string={$ticket['id_mojo']}&page=1' target='_blank'>{$ticket['id_mojo']}</a>",
                    'TIPO'             => $ticket['ticket_type'],
                    'FORMULARIO'       => $ticket['ticket_form'],
                    'GRUPO'            => $ticket['company'],
                    'TÍTULO'           => $ticket['truncated_title'],
                    'DESCRIPCIÓN'      => $ticket['truncated_description'],
                    'CREADO'           => $ticket['created_on'],
                    'RESUELTO'         => $ticket['solved_on'],
                    'TIEMPO_RESPUESTA' => $ticket['hours_to_resolve'],
                    'USUARIO'          => $ticket['username'],
                    'AGENTE'           => $ticket['agent'],
                    'STATUS'           => $ticket['status'],
                    'PRIORIDAD'        => $ticket['priority'],
                    'COLA'             => $ticket['queue'],
                    'ASIGNADO'         => $ticket['assigned_on'],
                    'ACTUALIZADO'      => $ticket['updated_on'],
                    'CALIFICACIÓN'     => $ticket['rating'],
                    'DEPARTAMENTO'     => $ticket['requesting_department'],
                    'SOLICITANTE'      => $ticket['applicants_name'],
                    'PROBLEMA'         => $ticket['problem'],
                    'ACCIONES'         => $actions
                );
            }
        }
        json_output(array("data" => $data));
    }

    function datatables_abiertos_tickets() {
        $from        = $_POST['from'];
        $until       = $_POST['until'];
        $ticket_form = $_POST['ticket_form'];
        $data = [];

        // Obtener los tickets de la base de datos
        $tickets = $this->mojoTicketsModel->get_tickets_report($from . ' 00:00:00', $until  . ' 23:59:59', $ticket_form);
        foreach ($tickets as $ticket) {
            if (in_array($ticket['status_id'], [10,20,30,40])) {
                // $actions = '<a href="javascript:void(0);" class="btn btn-sm btn-primary"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-center feather feather-eye align-middle"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg></a>';
                $actions = '<a href="javascript:void(0);" onclick="delete_ticket('. $ticket['id_mojo'] .');" class="btn btn-sm btn-danger"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-center feather feather-trash-2 align-middle"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg></a>';

                $data[] =  array(
                    'ID'               => "<a href='https://totalgas.mojohelpdesk.com/ma/#/tickets/search?query_string={$ticket['id_mojo']}&page=1' target='_blank'>{$ticket['id_mojo']}</a>",
                    'TIPO'             => $ticket['ticket_type'],
                    'FORMULARIO'       => $ticket['ticket_form'],
                    'GRUPO'            => $ticket['company'],
                    'TÍTULO'           => $ticket['truncated_title'],
                    'DESCRIPCIÓN'      => $ticket['truncated_description'],
                    'CREADO'           => $ticket['created_on'],
                    'RESUELTO'         => $ticket['solved_on'],
                    'TIEMPO_RESPUESTA' => $ticket['hours_to_resolve'],
                    'USUARIO'          => $ticket['username'],
                    'AGENTE'           => $ticket['agent'],
                    'STATUS'           => $ticket['status'],
                    'PRIORIDAD'        => $ticket['priority'],
                    'COLA'             => $ticket['queue'],
                    'ASIGNADO'         => $ticket['assigned_on'],
                    'ACTUALIZADO'      => $ticket['updated_on'],
                    'CALIFICACIÓN'     => $ticket['rating'],
                    'DEPARTAMENTO'     => $ticket['requesting_department'],
                    'SOLICITANTE'      => $ticket['applicants_name'],
                    'PROBLEMA'         => $ticket['problem'],
                    'ACCIONES'         => $actions
                );
            }
        }
        json_output(array("data" => $data));
    }

    function datatables_resueltos_tickets() {
        $from        = $_POST['from'];
        $until       = $_POST['until'];
        $ticket_form = $_POST['ticket_form'];
        $data = [];

        // Obtener los tickets de la base de datos
        $tickets = $this->mojoTicketsModel->get_tickets_report($from . ' 00:00:00', $until  . ' 23:59:59', $ticket_form);
        foreach ($tickets as $ticket) {
            if (in_array($ticket['status_id'], [50,60])) {
                // $actions = '<a href="javascript:void(0);" class="btn btn-sm btn-primary"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-center feather feather-eye align-middle"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg></a>';
                $actions = '<a href="javascript:void(0);" onclick="delete_ticket('. $ticket['id_mojo'] .');" class="btn btn-sm btn-danger"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-center feather feather-trash-2 align-middle"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg></a>';

                $data[] =  array(
                    'ID'               => "<a href='https://totalgas.mojohelpdesk.com/ma/#/tickets/search?query_string={$ticket['id_mojo']}&page=1' target='_blank'>{$ticket['id_mojo']}</a>",
                    'TIPO'             => $ticket['ticket_type'],
                    'FORMULARIO'       => $ticket['ticket_form'],
                    'GRUPO'            => $ticket['company'],
                    'TÍTULO'           => $ticket['truncated_title'],
                    'DESCRIPCIÓN'      => $ticket['truncated_description'],
                    'CREADO'           => $ticket['created_on'],
                    'RESUELTO'         => $ticket['solved_on'],
                    'TIEMPO_RESPUESTA' => $ticket['hours_to_resolve'],
                    'USUARIO'          => $ticket['username'],
                    'AGENTE'           => $ticket['agent'],
                    'STATUS'           => $ticket['status'],
                    'PRIORIDAD'        => $ticket['priority'],
                    'COLA'             => $ticket['queue'],
                    'ASIGNADO'         => $ticket['assigned_on'],
                    'ACTUALIZADO'      => $ticket['updated_on'],
                    'CALIFICACIÓN'     => $ticket['rating'],
                    'DEPARTAMENTO'     => $ticket['requesting_department'],
                    'SOLICITANTE'      => $ticket['applicants_name'],
                    'PROBLEMA'         => $ticket['problem'],
                    'ACCIONES'         => $actions
                );
            }
        }
        json_output(array("data" => $data));
    }

    function datatables_usuarios_tickets() {
        $from        = $_POST['from'];
        $until       = $_POST['until'];
        $ticket_form = $_POST['ticket_form'];
        $data = [];

        // Obtener los tickets de la base de datos
        $tickets = $this->mojoTicketsModel->get_tickets_report($from . ' 00:00:00', $until  . ' 23:59:59', $ticket_form);
        foreach ($tickets as $ticket) {
            if ($ticket['user_id'] == $_POST['user_id']) {
                // $actions = '<a href="javascript:void(0);" class="btn btn-sm btn-primary"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-center feather feather-eye align-middle"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg></a>';
                $actions = '<a href="javascript:void(0);" onclick="delete_ticket('. $ticket['id_mojo'] .');" class="btn btn-sm btn-danger"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-center feather feather-trash-2 align-middle"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg></a>';

                $data[] =  array(
                    'ID'               => "<a href='https://totalgas.mojohelpdesk.com/ma/#/tickets/search?query_string={$ticket['id_mojo']}&page=1' target='_blank'>{$ticket['id_mojo']}</a>",
                    'TIPO'             => $ticket['ticket_type'],
                    'FORMULARIO'       => $ticket['ticket_form'],
                    'GRUPO'            => $ticket['company'],
                    'TÍTULO'           => $ticket['truncated_title'],
                    'DESCRIPCIÓN'      => $ticket['truncated_description'],
                    'CREADO'           => $ticket['created_on'],
                    'RESUELTO'         => $ticket['solved_on'],
                    'TIEMPO_RESPUESTA' => $ticket['hours_to_resolve'],
                    'USUARIO'          => $ticket['username'],
                    'AGENTE'           => $ticket['agent'],
                    'STATUS'           => $ticket['status'],
                    'PRIORIDAD'        => $ticket['priority'],
                    'COLA'             => $ticket['queue'],
                    'ASIGNADO'         => $ticket['assigned_on'],
                    'ACTUALIZADO'      => $ticket['updated_on'],
                    'CALIFICACIÓN'     => $ticket['rating'],
                    'DEPARTAMENTO'     => $ticket['requesting_department'],
                    'SOLICITANTE'      => $ticket['applicants_name'],
                    'PROBLEMA'         => $ticket['problem'],
                    'ACCIONES'         => $actions
                );
            }
        }
        json_output(array("data" => $data));
    }

    function datatables_grupos_tickets() {
        $from        = $_POST['from'];
        $until       = $_POST['until'];
        $ticket_form = $_POST['ticket_form'];
        $data = [];

        // Obtener los tickets de la base de datos
        $tickets = $this->mojoTicketsModel->get_tickets_report($from . ' 00:00:00', $until  . ' 23:59:59', $ticket_form);
        foreach ($tickets as $ticket) {
            if ($ticket['company_id'] == $_POST['user_id']) {
                // $actions = '<a href="javascript:void(0);" class="btn btn-sm btn-primary"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-center feather feather-eye align-middle"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg></a>';
                $actions = '<a href="javascript:void(0);" onclick="delete_ticket('. $ticket['id_mojo'] .');" class="btn btn-sm btn-danger"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-center feather feather-trash-2 align-middle"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg></a>';

                $data[] =  array(
                    'ID'               => "<a href='https://totalgas.mojohelpdesk.com/ma/#/tickets/search?query_string={$ticket['id_mojo']}&page=1' target='_blank'>{$ticket['id_mojo']}</a>",
                    'TIPO'             => $ticket['ticket_type'],
                    'FORMULARIO'       => $ticket['ticket_form'],
                    'GRUPO'            => $ticket['company'],
                    'TÍTULO'           => $ticket['truncated_title'],
                    'DESCRIPCIÓN'      => $ticket['truncated_description'],
                    'CREADO'           => $ticket['created_on'],
                    'RESUELTO'         => $ticket['solved_on'],
                    'TIEMPO_RESPUESTA' => $ticket['hours_to_resolve'],
                    'USUARIO'          => $ticket['username'],
                    'AGENTE'           => $ticket['agent'],
                    'STATUS'           => $ticket['status'],
                    'PRIORIDAD'        => $ticket['priority'],
                    'COLA'             => $ticket['queue'],
                    'ASIGNADO'         => $ticket['assigned_on'],
                    'ACTUALIZADO'      => $ticket['updated_on'],
                    'CALIFICACIÓN'     => $ticket['rating'],
                    'DEPARTAMENTO'     => $ticket['requesting_department'],
                    'SOLICITANTE'      => $ticket['applicants_name'],
                    'PROBLEMA'         => $ticket['problem'],
                    'ACCIONES'         => $actions
                );
            }
        }
        json_output(array("data" => $data));
    }

    function exchange_rate() : void {
        // Obtener la hora actual
        $horaActual = new \DateTime();

        // Sumar una hora
        $horaActual->modify('+1 hour');

        // Formatear solo la hora en formato HH:MM
        $horaFormateada = $horaActual->format('H:') . '00';

        binnacle_register($_SESSION['tg_user']['Id'], 'Ingreso', 'Se ingresó a la pantalla de Tipo de Cambio', $_SERVER['REMOTE_ADDR'], 'administration.php', 'exchange_rate');



        echo $this->twig->render($this->route . 'exchange_rate.html', compact('horaFormateada'));
    }

    function datatable_exchange_rate() : void {
        $data = [];
        if ($rows = $this->cotizacionesModel->get_exchange_rates()) {
            foreach ($rows as $row) {
                $station_name = $row['station_name'];
                $fecha_y_hora = $row['Fecha'] . ' ' . $row['hra_format'];
                $exchange = number_format($row['ctz'], 2, '.','');
                $uniqueId = $row['codmda'] . ',' . $row['codgas'] . ',' . $row['fch'] . ',' . $row['hra'];
                $checkbox = '<input type="checkbox" class="form-check-input" name="check" value="'.$uniqueId.'">';
                $actions = "<button type=\"button\" class=\"btn btn-primary btn-sm mx-1\" onclick=\"update_exchange('". $station_name ."', '". $fecha_y_hora ."', '". $exchange ."', '". $uniqueId ."')\"><svg xmlns=\"http://www.w3.org/2000/svg\" width=\"24\" height=\"24\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\" class=\"feather feather-edit-2 align-middle\"><path d=\"M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z\"></path></svg></button>";
                $actions .= "<button type=\"button\" class=\"btn btn-danger btn-sm mx-1\" onclick=\"delete_exchange('". $uniqueId ."')\"><svg xmlns=\"http://www.w3.org/2000/svg\" width=\"24\" height=\"24\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\" class=\"feather feather-trash-2 align-middle\"><polyline points=\"3 6 5 6 21 6\"></polyline><path d=\"M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2\"></path><line x1=\"10\" y1=\"11\" x2=\"10\" y2=\"17\"></line><line x1=\"14\" y1=\"11\" x2=\"14\" y2=\"17\"></line></svg></button>";
                $data[] = array(
                    'CHECK' => $checkbox,
                    'DESCRIPCION' => $row['description'],
                    'NO_ESTACION' => $row['no_station'],
                    'ESTACION' => $row['station_name'],
                    'FECHA' => $row['Fecha'],
                    'HORA' => $row['hra_format'],
                    'CAMBIO' => number_format($row['ctz'], 2),
                    'ACCIONES' => $actions
                );
            }
        }
        json_output(array("data" => $data));
    }

    function exchange_rate_process() {
        if (preg_match('/POST/i',$_SERVER['REQUEST_METHOD']) && isset($_POST['rows'])) {
            $from = dateToInt($_POST['from']);
            $hour = intval(str_replace(':', '', $_POST['hour']));
            $cot = floatval($_POST['cot']);
            $rows = $_POST['rows'];

            foreach ($rows as $row) {
                list($codmda, $codgas, $fch, $hra) = explode(',', $row);
                $station = $this->estacionesModel->get_station($codgas);
                // Primero vamos a insertar en la tabla de SG12 (Corporativo)
                if ($corporate_success = $this->cotizacionesModel->insert($codmda, $codgas, $from, $hour, $cot, $_SESSION['tg_user']['Id'])) {
                    binnacle_register($_SESSION['tg_user']['Id'], 'Ingreso', "Se registró un nuevo tipo de cambio en Base de datos de Corporativo para la estación: {$station['Nombre']} por el tipo de cambio {$cot}, programado el día ". intToDate($from) ." a las {$_POST['hour']}", $_SERVER['REMOTE_ADDR'], 'administration.php', 'exchange_rate');
                } else {
                    binnacle_register($_SESSION['tg_user']['Id'], 'Falla al ingresar', "Error al registrar tipo de cambio en Base de datos de Corporativo para la estación: {$station['Nombre']} por el tipo de cambio {$cot}, programado el día ". intToDate($from) ." a las {$_POST['hour']}", $_SERVER['REMOTE_ADDR'], 'administration.php', 'exchange_rate');
                }

                // Luego insertamos en estacion correspondiente
                if ($station_success = $this->cotizacionesModel->insert_remote($codmda, $codgas, $from, $hour, $cot, $_SESSION['tg_user']['Id'])) {
                    binnacle_register($_SESSION['tg_user']['Id'], 'Ingreso', "Se registró un nuevo tipo de cambio en la estación: {$station['Nombre']} por el tipo de cambio {$cot}, programado el día ". intToDate($from) ." a las {$_POST['hour']}", $_SERVER['REMOTE_ADDR'], 'administration.php', 'exchange_rate');
                } else {
                    binnacle_register($_SESSION['tg_user']['Id'], 'Falla al ingresar', "Error al registrar tipo de cambio en la estación: {$station['Nombre']} por el tipo de cambio {$cot}, programado el día ". intToDate($from) ." a las {$_POST['hour']}", $_SERVER['REMOTE_ADDR'], 'administration.php', 'exchange_rate');
                }

                // Preparamos la respuesta
                $row_response = array(
                    'codmda'            => $codmda,
                    'codgas'            => $codgas,
                    'fch'               => $from,
                    'hour'              => $hour,
                    'corporate_success' => $corporate_success,
                    'station_success'   => $station_success
                );

                // Append row response to the main response array
                $response[] = $row_response;
            }
            echo json_encode($response);
        } else {
            echo json_encode(array('status' => 'error', 'message' => 'Solicitud inválida.'));
        }
    }

    function update_exchange() {
        if (preg_match('/POST/i',$_SERVER['REQUEST_METHOD'])){
            list($codmda, $codgas, $fch, $hra) = explode(',', $_POST['unique_id']);

            $station = $this->estacionesModel->get_station($codgas);

            // Vamos a actualizar en la tabla de SG12 (Corporativo)
            if ($corporate_success = $this->cotizacionesModel->update($codmda, $codgas, $fch, $hra, $_POST['exchange'])) {
                binnacle_register($_SESSION['tg_user']['Id'], 'Actualización', "Se actualizó el tipo de cambio en Base de datos de Corporativo para la estación: {$station['Nombre']} por el tipo de cambio {$_POST['exchange']}, programado el día ". intToDate($fch) ." a las {$hra}", $_SERVER['REMOTE_ADDR'], 'administration.php', 'exchange_rate');
            }

            // Luego actualizamos en estacion correspondiente
            if ($station_success = $this->cotizacionesModel->update_remote($codmda, $codgas, $fch, $hra, $_POST['exchange'])) {
                binnacle_register($_SESSION['tg_user']['Id'], 'Actualización', "Se actualizó el tipo de cambio en la estación: {$station['Nombre']} por el tipo de cambio {$_POST['exchange']}, programado el día ". intToDate($fch) ." a las {$hra}", $_SERVER['REMOTE_ADDR'], 'administration.php', 'exchange_rate');
            }

            json_output(array('corporate_success' => $corporate_success, 'station_success' => $station_success));
        }
    }

    function delete_exchange() {
        if (preg_match('/POST/i',$_SERVER['REQUEST_METHOD'])){
            list($codmda, $codgas, $fch, $hra) = explode(',', $_POST['unique_id']);

            $station = $this->estacionesModel->get_station($codgas);

            // Vamos a eliminar en la tabla de SG12 (Corporativo)
            if ($corporate_success = $this->cotizacionesModel->delete($codmda, $codgas, $fch, $hra)) {
                binnacle_register($_SESSION['tg_user']['Id'], 'Eliminación', "Se eliminó el tipo de cambio en Base de datos de Corporativo para la estación: {$station['Nombre']} programado el día ". intToDate($fch) ." a las {$hra}", $_SERVER['REMOTE_ADDR'], 'administration.php', 'exchange_rate');
            }

            // Luego eliminamos en estacion correspondiente
            if ($station_success = $this->cotizacionesModel->delete_remote($codmda, $codgas, $fch, $hra)) {
                binnacle_register($_SESSION['tg_user']['Id'], 'Eliminación', "Se eliminó el tipo de cambio en la estación {$station['Nombre']} programado el día ". intToDate($fch) ." a las {$hra}", $_SERVER['REMOTE_ADDR'], 'administration.php', 'exchange_rate');
            }

            json_output(array('corporate_success' => $corporate_success, 'station_success' => $station_success));
        }
    }

    function get_binnacle() : void {
        $binnacle = $this->binnacleModel->get_binnacle();
        echo $this->twig->render($this->route . 'binnacle.html', compact('binnacle'));
    }

    function update_ticket_db($ticket_id) {
        // Ahora vamos a actualizar en la base de datos de Mojo
        $response = $this->mojoTicketsModel->update_ticket_db($ticket_id);
        echo json_encode($response);
    }


    function datatables_departments_tickets() {
        $from        = $_POST['from'];
        $until       = $_POST['until'];
        $ticket_form = $_POST['ticket_form'];

        $data = [];


        // Obtener los tickets de la base de datos
        $tickets = $this->mojoTicketsModel->get_tickets_report($from . ' 00:00:00', $until  . ' 23:59:59', $ticket_form);
        foreach ($tickets as $ticket) {
            if (strtolower(str_replace([' ', 'á', 'é', 'í', 'ó', 'ú'], '', $ticket['requesting_department'])) == strtolower($_POST['user_id'])) {
                // $actions = '<a href="javascript:void(0);" class="btn btn-sm btn-primary"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-center feather feather-eye align-middle"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg></a>';
                $actions = '<a href="javascript:void(0);" onclick="delete_ticket('. $ticket['id_mojo'] .');" class="btn btn-sm btn-danger"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-center feather feather-trash-2 align-middle"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg></a>';

                $data[] =  array(
                    'ID'               => "<a href='https://totalgas.mojohelpdesk.com/ma/#/tickets/search?query_string={$ticket['id_mojo']}&page=1' target='_blank'>{$ticket['id_mojo']}</a>",
                    'TIPO'             => $ticket['ticket_type'],
                    'FORMULARIO'       => $ticket['ticket_form'],
                    'GRUPO'            => $ticket['company'],
                    'TÍTULO'           => $ticket['truncated_title'],
                    'DESCRIPCIÓN'      => $ticket['truncated_description'],
                    'CREADO'           => $ticket['created_on'],
                    'RESUELTO'         => $ticket['solved_on'],
                    'TIEMPO_RESPUESTA' => $ticket['hours_to_resolve'],
                    'USUARIO'          => $ticket['username'],
                    'AGENTE'           => $ticket['agent'],
                    'STATUS'           => $ticket['status'],
                    'PRIORIDAD'        => $ticket['priority'],
                    'COLA'             => $ticket['queue'],
                    'ASIGNADO'         => $ticket['assigned_on'],
                    'ACTUALIZADO'      => $ticket['updated_on'],
                    'CALIFICACIÓN'     => $ticket['rating'],
                    'DEPARTAMENTO'     => $ticket['requesting_department'],
                    'SOLICITANTE'      => $ticket['applicants_name'],
                    'PROBLEMA'         => $ticket['problem'],
                    'ACCIONES'         => $actions
                );
            }
        }
        json_output(array("data" => $data));
    }

    function datatables_supports_tickets() {
        $from        = $_POST['from'];
        $until       = $_POST['until'];
        $ticket_form = $_POST['ticket_form'];

        $data = [];

        // Obtener los tickets de la base de datos
        $tickets = $this->mojoTicketsModel->get_tickets_report($from . ' 00:00:00', $until  . ' 23:59:59', $ticket_form);
        foreach ($tickets as $ticket) {
            if (strtolower(str_replace([' ', 'á', 'é', 'í', 'ó', 'ú'], '', $ticket['problem'])) == strtolower($_POST['user_id'])) {
                $actions = '<a href="javascript:void(0);" onclick="delete_ticket('. $ticket['id_mojo'] .');" class="btn btn-sm btn-danger"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-center feather feather-trash-2 align-middle"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg></a>';

                $data[] =  array(
                    'ID'               => "<a href='https://totalgas.mojohelpdesk.com/ma/#/tickets/search?query_string={$ticket['id_mojo']}&page=1' target='_blank'>{$ticket['id_mojo']}</a>",
                    'TIPO'             => $ticket['ticket_type'],
                    'FORMULARIO'       => $ticket['ticket_form'],
                    'GRUPO'            => $ticket['company'],
                    'TÍTULO'           => $ticket['truncated_title'],
                    'DESCRIPCIÓN'      => $ticket['truncated_description'],
                    'CREADO'           => $ticket['created_on'],
                    'RESUELTO'         => $ticket['solved_on'],
                    'TIEMPO_RESPUESTA' => $ticket['hours_to_resolve'],
                    'USUARIO'          => $ticket['username'],
                    'AGENTE'           => $ticket['agent'],
                    'STATUS'           => $ticket['status'],
                    'PRIORIDAD'        => $ticket['priority'],
                    'COLA'             => $ticket['queue'],
                    'ASIGNADO'         => $ticket['assigned_on'],
                    'ACTUALIZADO'      => $ticket['updated_on'],
                    'CALIFICACIÓN'     => $ticket['rating'],
                    'DEPARTAMENTO'     => $ticket['requesting_department'],
                    'SOLICITANTE'      => $ticket['applicants_name'],
                    'PROBLEMA'         => $ticket['problem'],
                    'ACCIONES'         => $actions
                );
            }
        }
        json_output(array("data" => $data));
    }

    function in_process() {
        $input_from = isset($_GET['input_from']) ? $_GET['input_from'] : null;
        $input_until = isset($_GET['input_until']) ? $_GET['input_until'] : null;

        $from = null;
        $until = null;

        $anticipos_cards = null;
        $anticipos_cards_labels = null;
        $anticipos_cards_values = null;

        $anticipos_graph = null;
        $anticipos_graph_labels = null;
        $anticipos_graph_values = null;

        $total_80 = null;
        $total_20 = null;
        $total_100 = null;

        $count_80 = null;
        $count_20 = null;
        $count_100 = null;

        if ($input_from && $input_until) {
            $today = new DateTime();
            $from = $this->getFirstDayOfMonth($input_from);
            $until = $this->getLastDayOfMonth($input_until);

            $anticipos_graph = $this->documentosModel->get_month_anticipos((new DateTime())->modify('-1 year')->format('Y-m-d'), (new DateTime())->format('Y-m-d'));
            
            $anticipos_graph_labels = json_encode(array_column($anticipos_graph, 'NombreDelMes'));
            // Formatea cada valor 'Total' a dos decimales
            $anticipos_graph_values = array_map(function($item) {
                return number_format($item['Total'], 2, '.', '');
            }, $anticipos_graph);

            // Convierte el array formateado a JSON
            $anticipos_graph_values = json_encode($anticipos_graph_values);

            $anticipos_cards = $this->documentosModel->get_month_anticipos($from, $until);
            $anticipos_cards_labels = json_encode(array_column($anticipos_cards, 'NombreDelMes'));
            // Formatea cada valor 'Total' a dos decimales
            $anticipos_cards_values = array_map(function($item) {
                return number_format($item['Total'], 2, '.', '');
            }, $anticipos_cards);

            // Convierte el array formateado a JSON
            $anticipos_cards_values = json_encode($anticipos_cards_values);

            $anticipos_customers_80_amount = $this->documentosModel->get_anticipos_customer_80($from, $until);

            $count_80 = number_format(count($anticipos_customers_80_amount), 0, '.', ',');
            $total_80 = number_format(end($anticipos_customers_80_amount)['TotalAcumulado'], 2, '.',',');

            $anticipos_customers_20_amount = $this->documentosModel->get_anticipos_customer_20($from, $until);
            $count_20 = number_format(count($anticipos_customers_20_amount), 0, '.', ',');
            $total_20 = number_format((end($anticipos_customers_20_amount)['TotalAcumulado'] - end($anticipos_customers_80_amount)['TotalAcumulado']), 2, '.',',');

            $total_100 = number_format((end($anticipos_customers_80_amount)['TotalAcumulado'] + (end($anticipos_customers_20_amount)['TotalAcumulado'] - end($anticipos_customers_80_amount)['TotalAcumulado'])), 2, '.', ',');
            $count_100 = number_format((count($anticipos_customers_20_amount) + count($anticipos_customers_80_amount)), 0, '.', ',');
        }
        echo $this->twig->render($this->route . 'cd_report.html', compact('input_from', 'input_until', 'from', 'until', 'anticipos_cards', 'anticipos_cards_labels', 'anticipos_cards_values', 'anticipos_graph', 'anticipos_graph_labels', 'anticipos_graph_values', 'count_100', 'total_80', 'count_20', 'total_20', 'count_80', 'total_100'));
    }

    function dashboard_debit_credit() {

        // Si $from es nulo, establecer la fecha de hoy

        $from = isset($_GET['from']) ? $_GET['from'] : date('Y-m');
        $until = isset($_GET['until']) ? $_GET['until'] : date('Y-m');

        $clients = (empty($_GET['cliente'])) ? 0 : implode(",", $_GET['cliente']);

        $data = $this->despachosModel->get_dashboard_debit_credit(dateToInt($this->getFirstDayOfMonth($from)), dateToInt($this->getLastDayOfMonth($until)), $clients);

        $graph1 = $this->despachosModel->get_graph1(dateToInt($this->getFirstDayOfMonth($from)), dateToInt($this->getLastDayOfMonth($until)), $clients);
        $labels_graph1 = json_encode(array_column($graph1, 'MonthName'));
        $values_graph1_debitos = json_encode(array_column($graph1, 'MontosDebito'));
        $values_graph1_creditos = json_encode(array_column($graph1, 'MontosCredito'));

        $debits80 = [];
        $debits20 = [];
        $debits = [];
        $credits = [];

        foreach ($data as $row) {
            if ($row['Tipos'] == 'Débito') {
                if ($row['Acumulado'] <= 80) {
                    $debits80[] = $row;
                } else {
                    $debits20[] = $row;
                }
                $debits[] = $row;
            } else {
                $credits[] = $row;
            }
        }

        echo $this->twig->render($this->route . 'dashboard_debit_credit.html', compact('from', 'until', 'debits', 'credits', 'labels_graph1', 'values_graph1_debitos', 'values_graph1_creditos', 'debits80', 'debits20'));
    }

    function datatables_anticipos($from, $until) {

        $data = [];
        if ($anticipos = $this->documentosModel->get_anticipos($from, $until)) {

            foreach ($anticipos as $anticipo) {
                $data[] = array(
                    'FACTURA' => $anticipo['Factura'],
                    'TIPO' => $anticipo['Tipo'],
                    'PRODUCTO' => $anticipo['Producto'],
                    'CUENTA' => $anticipo['nrocta'],
                    'MONTO' => $anticipo['Monto'],
                    'IVA' => $anticipo['IVA'],
                    'TOTAL' => $anticipo['Total'],
                    'CFDI' => $anticipo['satuso'],
                    'PAGO' => $anticipo['satmdp'],
                    'CLIENTE' => $anticipo['Cliente'],
                    'ESTACION' => $anticipo['Estacion'],
                    'REGISTRO' => $anticipo['FechaRegistro'],
                    'FECHA' => $anticipo['Fecha']
                );
            }
        }
        json_output(array("data" => $data));
    }

    function datatables_customer_anticipos($from, $until) {
        $data = [];
        if ($anticipos = $this->documentosModel->get_anticipos_customer($from, $until)) {
            foreach ($anticipos as $anticipo) {
                $actions = '<a href="/administration/get_advance_clients_details/'. $anticipo['cod'] .'/'. $from .'/'. $until .'" target="_blank" class="btn btn-sm btn-primary"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-eye align-middle"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg></a>';
                $data[] = array(
                    'CODCLIENTE'     => $anticipo['cod'],
                    'CLIENTE'        => $anticipo['Cliente'],
                    'STATUS'         => $anticipo['status'],
                    'RFC'            => $anticipo['rfc'],
                    'TIPO'           => $anticipo['Tipo'],
                    'PRODUCTO'       => $anticipo['Producto'],
                    'TOTAL'          => $anticipo['AnticiposPeriodo'],
                    'CONSUMOS'       => $anticipo['ConsumosPeriodo'],
                    'SALDOCALCULADO' => $anticipo['Diferencia'],
                    'SALDOINGRESADO' => $anticipo['SaldoIngresos'],
                    'DIFERENCIA'     => ($anticipo['Diferencia'] - $anticipo['SaldoIngresos']),
                    'ULTIMODEPOSITO' => "$" . number_format($anticipo['UltimoAnticipoMonto'], 2, '.', ',') . "<p>({$anticipo['FechaUltimoAnticipo']})</p>",
                    'ULTIMOCONSUMO'  => "$" . number_format($anticipo['UltimoConsumoMonto'], 2, '.', ',') . "<p>({$anticipo['FechaUltimoConsumo']})</p>",
                    'ACCIONES'       => $actions,
                );
            }
        }

        json_output(array("data" => $data));
    }

    function get_advances($from, $until) {
        echo $this->twig->render($this->route . 'get_advances.html', compact('from', 'until'));
    }

    function get_advance_clients($percent,$from, $until) {
        echo $this->twig->render($this->route . 'get_advance_clients.html', compact('percent', 'from', 'until'));
    }

    function datatables_customer_anticipos2($percent, $from, $until) {

        if ($percent == 80) {
            $anticipos = $this->documentosModel->get_anticipos_customer_80($from, $until);
        } else {
            $anticipos = $this->documentosModel->get_anticipos_customer_20($from, $until);
        }
        $data = [];
        if ($anticipos) {
            foreach ($anticipos as $anticipo) {
                $actions = '<a href="/administration/get_advance_clients_details/'. $anticipo['cod'] .'/'. $from .'/'. $until .'" target="_blank" class="btn btn-sm btn-primary"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-eye align-middle"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg></a>';
                $data[] = array(
                    'CODCLIENTE' => $anticipo['cod'],
                    'CLIENTE'    => $anticipo['Cliente'],
                    'RFC'        => $anticipo['rfc'],
                    'TIPO'       => $anticipo['Tipo'],
                    'PRODUCTO'   => trim($anticipo['Producto']),
                    'MONTO'      => $anticipo['Monto'],
                    'IVA'        => $anticipo['IVA'],
                    'TOTAL'      => $anticipo['Total'],
                    'CONSUMOS'   => $anticipo['Consumos'],
                    'DIFERENCIA' => $anticipo['Diferencia'],
                    'ACCIONES'   => $actions,
                );
            }
        }

        json_output(array("data" => $data));
    }

    function get_advance_clients_details($codcli, $from, $until) {
        echo $this->twig->render($this->route . 'get_advance_clients_details.html', compact('codcli', 'from', 'until'));
    }

    function datatables_customer_advances_details($codcli, $from, $until) {
        $data = [];
        if ($anticipos = $this->documentosModel->get_anticipos_customer_details($codcli, $from, $until)) {
            foreach ($anticipos as $anticipo) {
                $data[] = array(
                    'CODCLIENTE'    => $anticipo['codcli'],
                    'CLIENTE'       => $anticipo['Cliente'],
                    'RFC'           => $anticipo['rfc'],
                    'TIPO'          => $anticipo['Tipo'],
                    'FACDESP'       => $anticipo['FactDesp'],
                    'PRODUCTO'      => trim($anticipo['Producto']),
                    'MONTOANTICIPO' => $anticipo['MontoAnticipo'],
                    'MONTOCONSUMO'  => $anticipo['MontoConsumo'],
                    'SALDO'         => $anticipo['Saldo'],
                    'ESTACION'      => $anticipo['Estacion'],
                    'FECHA'         => $anticipo['Fecha'],
                );
            }
        }
        json_output(array("data" => $data));
    }

    function search_credit_and_debits_clients()
    {
        $clients = $this->clientesModel->search_credit_and_debits_clients();

        echo json_encode($clients);
    }

    function get_statistics($user_id, $from, $until) : void {
        try {
            // Crear una nueva hoja de cálculo
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Reporte de Tickets');

            // Agregar encabezados de la tabla
            $sheet->setCellValue('A1', 'Agente');
            $sheet->setCellValue('B1', 'Total Tickets');
            $sheet->setCellValue('C1', 'Tickets Abiertos');
            $sheet->setCellValue('D1', 'Tickets Cerrados');

            // Ejemplo de datos (reemplazar con tus datos reales)
            $data = [
                ['Juan Pérez', 100, 30, 70],
                ['Ana García', 120, 40, 80],
                ['Luis Fernández', 90, 20, 70],
            ];

            // Rellenar la hoja con los datos y aplicar formato
            $row = 2;
            foreach ($data as $entry) {
                $sheet->setCellValue("A{$row}", $entry[0]);
                $sheet->setCellValue("B{$row}", $entry[1]);
                $sheet->setCellValue("C{$row}", $entry[2]);
                $sheet->setCellValue("D{$row}", $entry[3]);

                // Aplicar formato de número a las columnas de tickets
                $sheet->getStyle("B{$row}:D{$row}")->getNumberFormat()->setFormatCode('#,##0');

                $row++;
            }

            // Configurar la descarga
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment;filename="reporte_tickets.xlsx"');
            header('Cache-Control: max-age=0');

            // Crear el escritor y guardar el archivo
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        } catch (Exception $e) {
            echo 'Error al generar el archivo Excel: ' . $e->getMessage();
        }
    }

    function get_agent_report($agent, $from, $until, $date_range, $ticket_form) {
        switch ($date_range) {
            case 'semanal':
                $agents_total_tickets = $this->mojoTicketsModel->get_agents_tickets_total($from . 'T00:00:00', $until . 'T23:59:59', $ticket_form);
                $data = [];
                foreach ($agents_total_tickets as $ticket) {
                    if ($ticket['assigned_to_id'] == $agent) {
                        $data[] = $ticket;
                    } else {
                        // Si el ticket no corresponde al agente lo ingnoramos
                        continue;
                    }
                }
                // Ahora vamos a tomar los datos de los tickets para hacer un graficas
                $tickets_labels = json_encode(array_column($data, 'WeekISO'));
                $tickets_values = json_encode(array_column($data, 'TotalTickets'));
                break;
            case 'mensual':
                // Ahora los datos de los agentes
                $agents_total_tickets = $this->mojoTicketsModel->get_agents_tickets_total_month($from . 'T00:00:00', $until . 'T23:59:59', $ticket_form);
                $data = [];
                foreach ($agents_total_tickets as $ticket) {
                    if ($ticket['assigned_to_id'] == $agent) {
                        $data[] = $ticket;
                    }
                }
                // Ahora vamos a tomar los datos de los tickets para hacer un graficas
                $tickets_labels = json_encode(array_column($data, 'MonthName'));
                $tickets_values = json_encode(array_column($data, 'TotalTickets'));

                break;
            case 'anual':
                // Ahora los datos de los agentes
                $agents_total_tickets = $this->mojoTicketsModel->get_agents_tickets_total_year($from . 'T00:00:00', $until . 'T23:59:59', $ticket_form);
                $data = [];
                foreach ($agents_total_tickets as $ticket) {
                    if ($ticket['assigned_to_id'] == $agent) {
                        $data[] = $ticket;
                    }
                }
                // Ahora vamos a tomar los datos de los tickets para hacer un graficas
                $tickets_labels = json_encode(array_column($data, 'Year'));
                $tickets_values = json_encode(array_column($data, 'TotalTickets'));
                break;
        }


        echo $this->twig->render($this->route . 'agent_report.html', compact('data', 'from', 'until', 'date_range', 'tickets_labels', 'tickets_values'));
    }

    function doc_agujita() {
        echo $this->twig->render($this->route . 'doc_agujita.html');
    }

    function binnacle_adjustments() {
        $from = (isset($_GET['from'])) ? $_GET['from'] : date('Y-m-d', strtotime('-3 months')) ;
        $until = (isset($_GET['until'])) ? $_GET['until'] : date('Y-m-d') ;
        $station_selected = (isset($_GET['station_selected'])) ? $_GET['station_selected'] : 0 ;
        $stations = $this->estacionesModel->get_actives_stations();

        echo $this->twig->render($this->route . 'binnacle_adjustments.html', compact('from','until','stations','station_selected'));
    }

    function tablaAuditoria($from, $until, $codgas) {
        $from_int = dateToInt($from);
        $until_int = dateToInt($until);

        $data = [];
        if ($rows = $this->mojoTicketsModel->get_binnacle($from_int, $until_int, $codgas)) {
            foreach ($rows as $key => $row) {
                $data[] = array(
                    'Id'        => $row['id'],
                    'Fecha'        => intToDate($row['fchcor']),
                    'Usuario'      => $row['usuario'],
                    'Estación'     => $row['Estacion'],
                    'Producto'     => $row['Producto'],
                    'Turno'        => $row['nrotur'],
                    'Despacho'     => $row['nrotrn'],
                    'Can anterior' => $row['can_anterior'],
                    'Can nueva'    => $row['can_nuevo'],
                    'Mto anterior' => $row['mto_anterior'],
                    'Mto nueva'    => $row['mto_nuevo'],
                    'Can agregada' => $row['can_agregado'],
                );
            }
        }
        json_output(array("data" => $data));
    }

    function tablaTickets($from, $until, $codgas) {
        $from_int = dateToInt($from);
        $until_int = dateToInt($until);

        $data = [];
        if ($rows = $this->mojoTicketsModel->get_tabla_tickets($from_int, $until_int, $codgas)) {
            foreach ($rows as $key => $row) {
                $data[] = array(
                    'Fecha' => intToDate($row['fch']),
                    'Estación' => $row['Estacion'],
                    'Producto' => $row['Producto'],
                    'Turno' => $row['turno'],
                    'Diferencia' => $row['diferencia'],
                    'Ticket ID' => '<a href="https://totalgas.mojohelpdesk.com/mc/tickets/'. $row['ticket_id'] .'" target="_blank">'. $row['ticket_id'] .'</a>',
                );
            }
        }
        json_output(array("data" => $data));
    }

    function porcent_estacion_info(){
        ini_set('max_execution_time', 5000);
        ini_set('memory_limit', '1024M');
        set_time_limit(0);
        header('Content-Type: application/json');
        $postData = [
            'estacion' => $_POST['estacion'],
            'from' => $_POST['from'],
            'until' => $_POST['until']
        ];
        // $ch = curl_init('http://192.168.0.109:82/api/estacion_porcentaje/');
        $ch = curl_init('http://192.168.0.109:82/api/estacion_despachos_porcentaje/');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_POST, true);

        // Ejecutar y obtener respuesta
        $response = curl_exec($ch);
        curl_close($ch);
        $apiData = json_decode($response, true);
        echo json_encode($apiData);
    }


    function porcent_estacion_facturados_info(){

        ini_set('max_execution_time', 5000);
        ini_set('memory_limit', '1024M');
        set_time_limit(0);
        header('Content-Type: application/json');
        $postData = [
            'estacion' => $_POST['estacion'],
            'from' => $_POST['from'],
            'until' => $_POST['until']
        ];
        $ch = curl_init('http://192.168.0.109:82/api/estacion_despachos_facturados_porcentaje/');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_POST, true);

        // Ejecutar y obtener respuesta
        $response = curl_exec($ch);
        curl_close($ch);
        $apiData = json_decode($response, true);
        echo json_encode($apiData);
    }

    function porcent_facturas_info(){
        ini_set('max_execution_time', 5000);
        ini_set('memory_limit', '1024M');
        set_time_limit(0);
        header('Content-Type: application/json');
        $postData = [
            'estacion' => $_POST['estacion'],
            'from' => $_POST['from'],
            'until' => $_POST['until']
        ];
        $ch = curl_init('http://192.168.0.109:82/api/estacion_comparacion_series/');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_POST, true);

        // Ejecutar y obtener respuesta
        $response = curl_exec($ch);
        curl_close($ch);
        $apiData = json_decode($response, true);
        usort($apiData, function($a, $b) {
            return $a['Estacion'] <=> $b['Estacion'];
        });
        echo json_encode($apiData);
    }

    function close_ticket($ticket_id) {
        $access_key = 'f68cddda794b0bf9582c23b7b3099011d95c60ce'; // Reemplaza con tu clave real
        $api_url = "https://app.mojohelpdesk.com/api/v2/tickets/{$ticket_id}?access_key={$access_key}";

        // Datos a enviar (cerrar el ticket)
        $data = json_encode([
            "status_id" => 60
        ]);

        // Inicializar cURL
        $ch = curl_init($api_url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Esta opción desactiva la verificación del certificado SSL del servidor.
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); // Esta opción desactiva la verificación del nombre del host en el certificado SSL del servidor.
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($data)
        ]);


        // Ejecutar y obtener respuesta
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        // Si la actualización fue exitosa (HTTP 200)
        if ($http_code == 200) {
            // Redirigir al panel
            $panel_url = "https://totalgas.mojohelpdesk.com/mc/up/my-tickets/{$ticket_id}";
            header("Location: {$panel_url}");
            exit;
        } else {
            // Mostrar error
            http_response_code(500);
            echo "<h2>Error al cerrar el ticket</h2>";
            echo "<p>Código HTTP: {$http_code}</p>";
            echo "<p>Mensaje: " . htmlspecialchars($response ?: $error) . "</p>";
            exit;
        }
    }
}