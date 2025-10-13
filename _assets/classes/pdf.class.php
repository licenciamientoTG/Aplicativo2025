<?php
session_start();
ini_set('memory_limit', '1000M');

require('header.class.php');   // si tu header ya extiende FPDF, se respeta
require_once('php_functions.php');
require_once('code128.php');
require_once('fpdf.class.php'); // Asegúrate que aquí esté la clase FPDF

spl_autoload_register(function($class) {
    if (file_exists('../models/'.$class.'.php')) {
        require('../models/'.$class.'.php');
    }
});

// ================== PDF class ==================
class PDF extends FPDF {
    public $title = '';
    public $subtitle = '';
    public $period = '';

    function Header() {
        // Título
        $this->SetFont('Arial','B',12);
        $this->Cell(0,6, $this->title, 0,1,'L');
        $this->SetFont('Arial','',9);
        if ($this->subtitle) $this->Cell(0,5, $this->subtitle, 0,1,'L');
        if ($this->period)   $this->Cell(0,5, 'Periodo: '.$this->period, 0,1,'L');
        $this->Ln(2);

      
        $this->Ln();
    }

    function Footer(){
        $this->SetY(-12);
        $this->SetFont('Arial','I',8);
        $this->Cell(0,8,utf8_decode('Generado: ').date('Y-m-d H:i').'   Pagina '.$this->PageNo().'/{nb}',0,0,'R');
    }
}

// ============== Helpers de métricas ==============
function build_agents_metrics(array $rows, $fecha_ini, $fecha_fin) {
    $agentes = [];
    $startBound = $fecha_ini . ' 00:00:00';
    $endBound   = $fecha_fin . ' 23:59:59';

    $shortName = function (?string $full): string {
        $full = trim(preg_replace('/\s+/', ' ', (string)$full));
        if ($full === '') return '';
        $titles = ['sr','sra','srta','ing','lic','dra','dr','mtro','mtra','arq','cp','c','c.','--'];
        $parts  = preg_split('/\s+/', $full);
        while ($parts && in_array(mb_strtolower(rtrim($parts[0], '.'), 'UTF-8'), $titles, true)) array_shift($parts);
        if (!$parts) return '';
        $firstName = $parts[0];
        $surname = '';
        $connectors = ['de','del','la','las','los','da','das','do','dos','di','van','von','y','e','della','dalla','du','le','san','santa','mac','mc'];
        if (isset($parts[1])) {
            $i = 1; $s = [];
            while ($i < count($parts) && in_array(mb_strtolower($parts[$i], 'UTF-8'), $connectors, true)) { $s[] = $parts[$i]; $i++; }
            if (!empty($s) && isset($parts[$i])) { $s[] = $parts[$i]; $surname = implode(' ', $s); }
            else { $surname = $parts[1]; }
        }
        return trim($firstName.' '.$surname);
    };

    foreach ($rows as $ticket) {
        $id   = (int)($ticket['assigned_to_id'] ?? 0);
        $name = $ticket['assigned_to_name'] ?? ($id === 0 ? 'Sin asignar' : 'Agente #'.$id);

        if (!isset($agentes[$id])) {
            $agentes[$id] = [
                'id'                   => $id,
                'name'                 => $name,
                'short_name'           => ($id === 0 ? 'Sin asignar' : $shortName($name)),
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

        $agentes[$id]['total_tickets']++;

        $estatus    = $ticket['estatus'] ?? '';
        $created_on = $ticket['created_on'] ?? null;
        $solved_on  = $ticket['solved_on'] ?? null;

        if ($estatus === 'Cerrado' && $solved_on >= $startBound && $solved_on <= $endBound) {
            $agentes[$id]['total_cerrados']++;
        }

        if (
            ($estatus === 'Abierto' && $created_on <= $endBound) ||
            ($created_on <= $endBound && $solved_on >= $endBound) ||
            ($created_on <= $endBound && $solved_on == null)
        ) {
            $agentes[$id]['total_abiertos']++;
        }

        $hora_tot = (float)($ticket['hora_tot'] ?? 0.0);
        $agentes[$id]['tiempo_total'] += $hora_tot;

        $priority_id = isset($ticket['priority_id']) ? (int)$ticket['priority_id'] : null;
        if ($priority_id === 10 || $priority_id === 20) {
            $agentes[$id]['tickets_urgente']++;
            $agentes[$id]['tiempo_total_urgente'] += $hora_tot;
        } elseif ($priority_id === 30 || $priority_id === 40) {
            $agentes[$id]['tickets_normal']++;
            $agentes[$id]['tiempo_total_normal'] += $hora_tot;
        }
    }

    foreach ($agentes as $aid => $data) {
        $agentes[$aid]['promedio_normal'] = ($data['tickets_normal'] > 0)
            ? round($data['tiempo_total_normal'] / $data['tickets_normal'], 2) : 0.0;
        $agentes[$aid]['promedio_urgente'] = ($data['tickets_urgente'] > 0)
            ? round($data['tiempo_total_urgente'] / $data['tickets_urgente'], 2) : 0.0;
    }

    return $agentes;
}

/**
 * Dibuja:
 * - Una línea con el NOMBRE del agente (fuera de la tabla)
 * - Debajo, UNA FILA de tabla con todas las métricas (sin columna de nombre)
 */
function draw_agents_table(PDF $pdf, array $agentes) {
    // Definimos las columnas con key y su label
    $cols = [
        ['w'=>18, 'align'=>'R', 'key'=>'total_tickets',        'fmt'=>'int', 'label'=>'Total'],
        ['w'=>20, 'align'=>'R', 'key'=>'total_abiertos',       'fmt'=>'int', 'label'=>'Abiertos'],
        ['w'=>20, 'align'=>'R', 'key'=>'total_cerrados',       'fmt'=>'int', 'label'=>'Cerrados'],
        ['w'=>22, 'align'=>'R', 'key'=>'tickets_normal',       'fmt'=>'int', 'label'=>'T. Normal'],
        ['w'=>28, 'align'=>'R', 'key'=>'tiempo_total_normal',  'fmt'=>'dec', 'label'=>'Tiempo Normal (h)'],
        ['w'=>28, 'align'=>'R', 'key'=>'promedio_normal',      'fmt'=>'dec', 'label'=>'Prom. Normal (h)'],
        ['w'=>22, 'align'=>'R', 'key'=>'tickets_urgente',      'fmt'=>'int', 'label'=>'T. Urgentes'],
        ['w'=>28, 'align'=>'R', 'key'=>'tiempo_total_urgente', 'fmt'=>'dec', 'label'=>'Tiempo Urg. (h)'],
        ['w'=>28, 'align'=>'R', 'key'=>'promedio_urgente',     'fmt'=>'dec', 'label'=>'Prom. Urg. (h)'],
        ['w'=>28, 'align'=>'R', 'key'=>'tiempo_total',         'fmt'=>'dec', 'label'=>'Tiempo Total (h)'],
        ['w'=>25, 'align'=>'R', 'key'=>'_prom_global',         'fmt'=>'dec', 'label'=>'Prom. Global (h)'],
    ];

    $rowHeight    = 7;  // alto de fila de datos
    $nameHeight   = 6;  // alto de la línea del nombre de agente
    $headerHeight = 8;  // alto del encabezado de tabla

    foreach ($agentes as $row) {
        $prom_global = (!empty($row['total_tickets']))
            ? ($row['tiempo_total'] / $row['total_tickets']) : 0.0;
        $row['_prom_global'] = $prom_global;

        $needed = $nameHeight + $headerHeight + $rowHeight + 4;

        if ($pdf->GetY() + $needed > ($pdf->GetPageHeight() - 15)) {
            $pdf->AddPage('L','A4'); // <-- fuerza Landscape siempre

        }

        // Nombre del agente
        $pdf->SetFont('Arial','B',9);
        $pdf->Cell(0,$nameHeight, utf8_decode("Agente: ".$row['short_name']), 0, 1, 'L');
        $pdf->Ln(1);

        // Encabezados para este agente
        $pdf->SetFont('Arial','B',8);
        $pdf->SetFillColor(240,240,240);
        $pdf->SetDrawColor(150,150,150);
        foreach ($cols as $c) {
            $pdf->Cell($c['w'], $headerHeight, utf8_decode($c['label']), 1, 0, 'C', true);
        }
        $pdf->Ln();

        // Fila de datos
        $pdf->SetFont('Arial','',8);
        foreach ($cols as $c) {
            $val = $row[$c['key']] ?? '';
            if ($c['fmt'] === 'int') $val = number_format((int)$val, 0, '.', ',');
            elseif ($c['fmt'] === 'dec') $val = number_format((float)$val, 2, '.', ',');
            $pdf->Cell($c['w'], $rowHeight, (string)$val, 1, 0, $c['align']);
        }
        $pdf->Ln(10); // espacio entre agentes
    }

    // Totales generales (coinciden con mismas columnas)
    $tot = [
        'total_tickets'        => 0,
        'total_abiertos'       => 0,
        'total_cerrados'       => 0,
        'tickets_normal'       => 0,
        'tiempo_total_normal'  => 0.0,
        'tickets_urgente'      => 0,
        'tiempo_total_urgente' => 0.0,
        'tiempo_total'         => 0.0,
    ];
    foreach ($agentes as $row) {
        $tot['total_tickets']        += (int)($row['total_tickets'] ?? 0);
        $tot['total_abiertos']       += (int)($row['total_abiertos'] ?? 0);
        $tot['total_cerrados']       += (int)($row['total_cerrados'] ?? 0);
        $tot['tickets_normal']       += (int)($row['tickets_normal'] ?? 0);
        $tot['tiempo_total_normal']  += (float)($row['tiempo_total_normal'] ?? 0.0);
        $tot['tickets_urgente']      += (int)($row['tickets_urgente'] ?? 0);
        $tot['tiempo_total_urgente'] += (float)($row['tiempo_total_urgente'] ?? 0.0);
        $tot['tiempo_total']         += (float)($row['tiempo_total'] ?? 0.0);
    }
    $tot_prom_normal = ($tot['tickets_normal'] > 0) ? ($tot['tiempo_total_normal'] / $tot['tickets_normal']) : 0.0;
    $tot_prom_urg    = ($tot['tickets_urgente'] > 0) ? ($tot['tiempo_total_urgente'] / $tot['tickets_urgente']) : 0.0;
    $tot_prom_global = ($tot['total_tickets']  > 0) ? ($tot['tiempo_total'] / $tot['total_tickets']) : 0.0;

    // Salto si no cabe la fila de totales
    if ($pdf->GetY() + 8 > ($pdf->GetPageHeight() - 15)) $pdf->AddPage('L','A4'); // <-- fuerza Landscape siempre


    $pdf->SetFont('Arial','B',8);
    $pdf->SetFillColor(230,230,230);

    // Fila de totales (ocupa exactamente los mismos anchos/orden)
    $values = [
        number_format($tot['total_tickets'],0,'.',','),
        number_format($tot['total_abiertos'],0,'.',','),
        number_format($tot['total_cerrados'],0,'.',','),
        number_format($tot['tickets_normal'],0,'.',','),
        number_format($tot['tiempo_total_normal'],2,'.',','),
        number_format($tot_prom_normal,2,'.',','),
        number_format($tot['tickets_urgente'],0,'.',','),
        number_format($tot['tiempo_total_urgente'],2,'.',','),
        number_format($tot_prom_urg,2,'.',','),
        number_format($tot['tiempo_total'],2,'.',','),
        number_format($tot_prom_global,2,'.',','),
    ];

    // Etiqueta "Totales" alineada a la izquierda, ocupando el ancho de la primera columna (18 mm)
    $pdf->Cell($cols[0]['w'],8,utf8_decode('Totales'),1,0,'L',true);
    // El resto de columnas con los valores
    for ($i=1; $i<count($cols); $i++) {
        $pdf->Cell($cols[$i]['w'],8,$values[$i],1,0,'R',true);
    }
}

// ================== ACTION ==================
if (isset($_GET['action'])) {
    switch ($_GET['action']) {
        case 'ecv_agents_pdf':
            $period         = isset($_GET['period']) ? $_GET['period'] : date('Y-m');
            $ticket_form_id = isset($_GET['ticket_form_id']) ? (int)$_GET['ticket_form_id'] : 51598;
            $assigned_to_id = isset($_GET['assigned_to_id']) ? (int)$_GET['assigned_to_id'] : 0;

            if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) $period = date('Y-m');

            $dt = DateTime::createFromFormat('Y-m-d', $period . '-01');
            $fecha_ini = $dt->format('Y-m-01');
            $fecha_fin = $dt->format('Y-m-t');

            // Traer datos del modelo
            $model = new MojoTicketsModel(); // ajusta si tu clase tiene otro nombre
            $rows = $model->ecv_model($fecha_ini, $fecha_fin, $ticket_form_id, $assigned_to_id) ?: [];

            $agentes = build_agents_metrics($rows, $fecha_ini, $fecha_fin);

            // === Render PDF ===
            $pdf = new PDF();
            $pdf->AliasNbPages();
            $pdf->SetAutoPageBreak(true, 15);
            $pdf->SetMargins(10, 12, 10);

            // Títulos ANTES del AddPage (los usa Header)
            $dept = ($ticket_form_id == 51598) ? 'Tickets Sistemas' : (($ticket_form_id == 57328) ? 'Tickets Mantenimiento' : 'Formulario #'.$ticket_form_id);
            $agentLabel = ($assigned_to_id === 0) ? 'Todos' : 'Agente #'.$assigned_to_id;
            $pdf->title    = utf8_decode('Reporte por agente');
            $pdf->subtitle = utf8_decode("Formulario: $dept | Agente: $agentLabel");
            $pdf->period   = $period . " ($fecha_ini a $fecha_fin)";

            $pdf->AddPage('L','A4'); // Header auto
            draw_agents_table($pdf, $agentes);

            $filename = sprintf('agentes_%s_form_%s_%s.pdf', $period, (int)$ticket_form_id, date('Ymd_His'));
            $pdf->Output('D', $filename);
            exit;

        default:
            http_response_code(400);
            echo 'Acción no soportada.';
            exit;
    }
}
