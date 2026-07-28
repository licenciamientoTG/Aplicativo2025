<?php

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/**
 * Análisis de Merma Diaria (Abastos).
 *
 * Snapshot diario de inventarios por turno de todas las estaciones
 * (TG.dbo.merma_diaria) llenado vía ApiER en paralelo; vistas de resumen
 * mensual y detalle por estación; captura manual de merma s/d y comentarios.
 * El contable/diferencia del snapshot se recalcula tras cada sync con la
 * regla del libro amarillo (ver MermaDiariaModel::recalc_contable).
 *
 * Rutas: /merma/[metodo]  (autocargado por index.php)
 * Spec:  docs/superpowers/specs/2026-07-13-analisis-merma-diaria-design.md
 * Schema: docs/sql/merma_schema.sql
 */
class Merma
{
    private const PERM_VER = 33;   // Reportes de Abastos
    private const API_URL  = 'http://192.168.0.109:82/api/inventarios_turnos/';

    private $twig;
    private $route;
    private $mermaModel;

    public function __construct($twig)
    {
        $this->twig       = $twig;
        $this->route      = 'views/merma/';
        $this->mermaModel = new MermaDiariaModel();
    }

    /* ===================================================================== */
    /* Vistas                                                                */
    /* ===================================================================== */

    /** Resumen mensual (equivalente a la hoja MERMA MENSUAL del Excel). */
    public function analisis(): void
    {
        if (!authorized(self::PERM_VER)) {
            (new Errors())->get404();
            return;
        }
        // Rango desde/hasta. El día en curso nunca se considera (turnos
        // incompletos): "hasta" se topa en ayer, y el default es del 1° del
        // mes de ayer a ayer — el 1° del mes eso significa el mes anterior
        // completo. Compat: ?anio/?mes viejos derivan el mes completo.
        $ayer    = strtotime('yesterday');
        $ayerStr = date('Y-m-d', $ayer);
        $desde   = $_GET['desde'] ?? null;
        $hasta   = $_GET['hasta'] ?? null;
        $fmt     = '/^\d{4}-\d{2}-\d{2}$/';
        if (!$desde || !$hasta || !preg_match($fmt, $desde) || !preg_match($fmt, $hasta)) {
            if (isset($_GET['anio']) || isset($_GET['mes'])) {
                $a = (int)($_GET['anio'] ?? date('Y', $ayer));
                $m = (int)($_GET['mes'] ?? date('n', $ayer));
                if ($m < 1 || $m > 12) $m = (int)date('n', $ayer);
                $desde = sprintf('%04d-%02d-01', $a, $m);
                $hasta = date('Y-m-t', mktime(0, 0, 0, $m, 1, $a));
            } else {
                $desde = date('Y-m-01', $ayer);
                $hasta = $ayerStr;
            }
        }
        if ($hasta > $ayerStr) $hasta = $ayerStr;
        if ($desde > $hasta)   $desde = date('Y-m-01', strtotime($hasta));

        // La captura manual, el precio y los links a detalle siguen siendo
        // mensuales: se anclan al mes de "hasta"
        $anio = (int)substr($hasta, 0, 4);
        $mes  = (int)substr($hasta, 5, 2);

        $estaciones = $this->mermaModel->get_estaciones();
        $resumen    = $this->mermaModel->get_resumen_rango($desde, $hasta);
        $manual     = $this->mermaModel->get_manual($anio, $mes);
        $fechas     = $this->mermaModel->get_fechas_por_estacion($desde, $hasta);
        $corruptas  = $this->mermaModel->get_corruptas_por_estacion($desde, $hasta);

        // Días esperados: todos los del rango (hasta ya viene topado en ayer)
        $diasEsperados = [];
        for ($t = strtotime($desde); $t <= strtotime($hasta); $t = strtotime('+1 day', $t)) {
            $diasEsperados[] = date('Y-m-d', $t);
        }

        $filas   = [];
        $totales = ['maxima' => 0, 'super' => 0, 'diesel' => 0, 'total' => 0, 'venta' => 0,
                    'sd_maxima' => 0, 'sd_super' => 0, 'sd_diesel' => 0];
        foreach ($estaciones as $est) {
            $cod = (int)$est['Codigo'];
            $r   = $resumen[$cod] ?? null;
            $m   = $manual[$cod] ?? null;
            $faltantes = array_values(array_diff($diasEsperados, $fechas[$cod] ?? []));
            $fila = [
                'codgas'      => $cod,
                'nombre'      => $est['Nombre'],
                'cveest'      => $est['cveest'] ?? null,
                'maxima'      => $r['merma_maxima'] ?? null,
                'super'       => $r['merma_super'] ?? null,
                'diesel'      => $r['merma_diesel'] ?? null,
                'total'       => $r['merma_total'] ?? null,
                'venta'       => $r['venta_total'] ?? null,
                'pct'         => ($r && (float)$r['venta_total'] != 0)
                                 ? (float)$r['merma_total'] / (float)$r['venta_total'] * 100 : null,
                'sd_maxima'   => $m['merma_sd_maxima'] ?? null,
                'sd_super'    => $m['merma_sd_super'] ?? null,
                'sd_diesel'   => $m['merma_sd_diesel'] ?? null,
                'comentarios' => $m['comentarios'] ?? '',
                'faltantes'   => $faltantes,
                'corruptas'   => $corruptas[$cod] ?? [],
            ];
            $filas[] = $fila;
            foreach (['maxima', 'super', 'diesel', 'total', 'venta'] as $k) {
                $key = $k === 'venta' ? 'venta' : $k;
                $totales[$key] += (float)($fila[$k === 'venta' ? 'venta' : $k] ?? 0);
            }
            $totales['sd_maxima'] += (float)($fila['sd_maxima'] ?? 0);
            $totales['sd_super']  += (float)($fila['sd_super'] ?? 0);
            $totales['sd_diesel'] += (float)($fila['sd_diesel'] ?? 0);
        }

        // El modal de sync propone el mismo rango que se está viendo
        $syncDesde = $desde;
        $syncHasta = $hasta;
        $maxHasta  = $ayerStr;

        echo $this->twig->render($this->route . 'analisis.html',
            compact('anio', 'mes', 'desde', 'hasta', 'maxHasta', 'filas', 'totales',
                    'syncDesde', 'syncHasta'));
    }

    /** Detalle día × turno de una estación (equivalente a la hoja del Excel). */
    public function detalle($codgas = 0): void
    {
        if (!authorized(self::PERM_VER)) {
            (new Errors())->get404();
            return;
        }
        $codgas = (int)$codgas;
        // Misma regla que analisis(): el default se ancla a ayer, nunca a hoy
        $ayer    = strtotime('yesterday');
        $ayerStr = date('Y-m-d', $ayer);

        // Rango de días opcional (llega del buscador de analisis o del propio
        // detalle); sin rango válido se cae al mes completo de anio/mes
        $desde   = $_GET['desde'] ?? '';
        $hasta   = $_GET['hasta'] ?? '';
        $esFecha = fn($f) => is_string($f) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) && strtotime($f) !== false;
        if (!$esFecha($desde) || !$esFecha($hasta)) {
            $anio  = (int)($_GET['anio'] ?? date('Y', $ayer));
            $mes   = (int)($_GET['mes'] ?? date('n', $ayer));
            if ($mes < 1 || $mes > 12) $mes = (int)date('n', $ayer);
            $desde = sprintf('%04d-%02d-01', $anio, $mes);
            $hasta = date('Y-m-t', mktime(0, 0, 0, $mes, 1, $anio));
        }
        if ($desde > $hasta) [$desde, $hasta] = [$hasta, $desde];
        if ($hasta > $ayerStr) $hasta = $ayerStr;
        if ($desde > $hasta)   $desde = date('Y-m-01', strtotime($hasta));
        // Título, botón de sync e inv. inicial se anclan al mes de "hasta"
        // (misma convención que analisis)
        $anio = (int)substr($hasta, 0, 4);
        $mes  = (int)substr($hasta, 5, 2);

        $estacion = null;
        foreach ($this->mermaModel->get_estaciones() as $e) {
            if ((int)$e['Codigo'] === $codgas) { $estacion = $e; break; }
        }
        if (!$estacion) {
            (new Errors())->get404();
            return;
        }

        $rows = $this->mermaModel->get_detalle_rango($codgas, $desde, $hasta);

        // Acumulado de diferencia por familia (como las columnas I/P del Excel)
        $acum    = ['maxima' => 0.0, 'super' => 0.0, 'diesel' => 0.0];
        $compras = ['maxima' => 0.0, 'super' => 0.0, 'diesel' => 0.0];
        $ventas  = ['maxima' => 0.0, 'super' => 0.0, 'diesel' => 0.0];
        $filas   = [];
        foreach ($rows as $r) {
            $fila = ['fecha' => substr($r['fecha'], 0, 10), 'turno' => (int)$r['turno']];
            foreach (array_keys(MermaDiariaModel::FAMILIAS) as $fam) {
                $dif = $r["dif_$fam"];
                if ($dif !== null) $acum[$fam] += (float)$dif;
                $compras[$fam] += (float)($r["compras_$fam"] ?? 0);
                $ventas[$fam]  += (float)($r["vr_$fam"] ?? 0);
                $fis = $r["fis_$fam"];
                $fila[$fam] = [
                    'vr'      => $r["vr_$fam"],
                    'compras' => $r["compras_$fam"],
                    'cont'    => $r["cont_$fam"],
                    'fis'     => $fis,
                    // Lectura fuera de rango plausible: se muestra marcada en
                    // rojo pero no participa en contable/diferencia
                    'fis_corrupta' => $fis !== null
                        && ($fis < MermaDiariaModel::INV_FISICO_MIN
                            || $fis > MermaDiariaModel::INV_FISICO_MAX),
                    'dif'     => $dif,
                    'acum'    => $dif !== null ? $acum[$fam] : null,
                ];
            }
            $filas[] = $fila;
        }

        // Agregado por día (tab "Diario", como el Análisis de Mermas de
        // ControlGas): merma del día = suma de diferencias de sus turnos
        // (la cadena del libro amarillo telescopia), saldo real = último
        // corte físico válido del día, contable = real - merma, e
        // inicial = contable + ventas - compras (= real del día anterior)
        $diasMap = [];
        foreach ($filas as $f) {
            $fecha = $f['fecha'];
            if (!isset($diasMap[$fecha])) {
                $diasMap[$fecha] = ['fecha' => $fecha, 'turnos' => []];
            }
            $diasMap[$fecha]['turnos'][] = $f;
            foreach (array_keys(MermaDiariaModel::FAMILIAS) as $fam) {
                $b = $f[$fam];
                $d = $diasMap[$fecha][$fam] ?? ['vr' => null, 'compras' => null, 'dif' => null, 'fis' => null, 'corrupta' => false];
                if ($b['vr'] !== null)      $d['vr']      = ($d['vr'] ?? 0) + (float)$b['vr'];
                if ($b['compras'] !== null) $d['compras'] = ($d['compras'] ?? 0) + (float)$b['compras'];
                if ($b['dif'] !== null)     $d['dif']     = ($d['dif'] ?? 0) + (float)$b['dif'];
                if ($b['fis'] !== null && !$b['fis_corrupta']) $d['fis'] = (float)$b['fis'];
                if ($b['fis_corrupta']) $d['corrupta'] = true;
                $diasMap[$fecha][$fam] = $d;
            }
        }
        $dias = [];
        foreach ($diasMap as $dia) {
            foreach (array_keys(MermaDiariaModel::FAMILIAS) as $fam) {
                $d = $dia[$fam] ?? ['vr' => null, 'compras' => null, 'dif' => null, 'fis' => null, 'corrupta' => false];
                $d['cont'] = ($d['fis'] !== null && $d['dif'] !== null) ? $d['fis'] - $d['dif'] : null;
                $d['ini']  = $d['cont'] !== null ? $d['cont'] + ($d['vr'] ?? 0) - ($d['compras'] ?? 0) : null;
                $d['pct']  = ($d['dif'] !== null && ($d['vr'] ?? 0) != 0) ? $d['dif'] / $d['vr'] * 100 : null;
                $dia[$fam] = $d;
            }
            $dias[] = $dia;
        }

        // KPIs sobre el mismo rango filtrado (el snapshot nunca trae el día en
        // curso, así que no hace falta topar en ayer)
        $resumenRango = $this->mermaModel->get_resumen_rango($desde, $hasta);
        $resumen      = $resumenRango[$codgas] ?? null;
        // El inv. inicial sigue siendo el del arranque del mes de "desde"
        $invInicial = $this->mermaModel->get_inv_inicial_mes(
            $codgas, (int)substr($desde, 0, 4), (int)substr($desde, 5, 2));

        $maxHasta = $ayerStr;
        echo $this->twig->render($this->route . 'detalle.html',
            compact('estacion', 'anio', 'mes', 'desde', 'hasta', 'maxHasta',
                    'filas', 'dias', 'resumen', 'invInicial', 'compras', 'ventas'));
    }

    /**
     * Reporte de Ventas Consolidado — reemplaza el libro
     * "VETS X EST X MARCA Y PROTS <Mes><Año>.xlsm".
     *
     * Cinco pestañas de producto sobre la misma matriz día × estación de
     * merma_diaria.ventas_reales (el "VR" del Excel), más el presupuesto de
     * TGV2.dbo.Budget.
     */
    public function ventas(): void
    {
        if (!authorized(self::PERM_VER)) {
            (new Errors())->get404();
            return;
        }
        // Igual que analisis(): el día en curso nunca tiene turnos completos,
        // así que el mes por defecto es el de ayer.
        $ayer = strtotime('yesterday');
        $anio = (int) ($_GET['anio'] ?? date('Y', $ayer));
        $mes  = (int) ($_GET['mes']  ?? date('n', $ayer));
        if ($mes < 1 || $mes > 12)         $mes  = (int) date('n', $ayer);
        if ($anio < 2020 || $anio > 2100)  $anio = (int) date('Y', $ayer);

        $reporte = $this->armarReporte($anio, $mes);

        // Selector de año: los últimos 3 años (el actual y los dos previos),
        // más reciente primero. Los años sin datos en merma_diaria salen en
        // "—" (la vista ya lo maneja), así que no hace falta filtrarlos aquí.
        $anioAyer = (int) date('Y', $ayer);

        echo $this->twig->render($this->route . 'ventas.html', $reporte + [
            'anios'  => range($anioAyer, $anioAyer - 2),
            'meses'  => ['ENERO', 'FEBRERO', 'MARZO', 'ABRIL', 'MAYO', 'JUNIO',
                         'JULIO', 'AGOSTO', 'SEPTIEMBRE', 'OCTUBRE', 'NOVIEMBRE', 'DICIEMBRE'],
        ]);
    }

    /**
     * Descarga el reporte en .xlsx con las cinco hojas del libro original,
     * pero con valores en vez de fórmulas y referencias externas.
     */
    public function ventas_excel(): void
    {
        set_time_limit(0);
        if (!authorized(self::PERM_VER)) {
            (new Errors())->get404();
            return;
        }
        $ayer = strtotime('yesterday');
        $anio = (int) ($_GET['anio'] ?? date('Y', $ayer));
        $mes  = (int) ($_GET['mes']  ?? date('n', $ayer));
        if ($mes < 1 || $mes > 12)        $mes  = (int) date('n', $ayer);
        if ($anio < 2020 || $anio > 2100) $anio = (int) date('Y', $ayer);

        $reporte    = $this->armarReporte($anio, $mes);
        $estaciones = $reporte['estaciones'];

        $filasResumen = [
            'total'     => 'TOTAL',
            'mix'       => '% MIX',
            'proy'      => 'PROY. MENSUAL',
            'ppto'      => 'PRESUPUESTO',
            'dif'       => 'DIFERENCIA',
            'pct_ppto'  => '% PRESUPUESTO',
            'vs_semana' => 'VS SEMANA PREVIA',
            'ma'        => '% M.A.',
            'aa'        => '% A.A.',
        ];
        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);

        foreach ($reporte['pestanas'] as $p) {
            $sheet = $spreadsheet->createSheet();
            // El título de hoja de Excel tolera 31 caracteres; los labels caben.
            $sheet->setTitle($p['label']);

            $sheet->setCellValue('A1', 'DÍA');
            $sheet->setCellValue('B1', '');
            $col = 3;
            foreach ($estaciones as $e) {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($col) . '1', $e['Nombre']);
                $col++;
            }
            $colTotal = Coordinate::stringFromColumnIndex($col);
            $sheet->setCellValue($colTotal . '1', 'TOTAL');
            $sheet->getStyle('A1:' . $colTotal . '1')->getFont()->setBold(true);

            $fila = 2;
            foreach ($p['dias'] as $d) {
                $sheet->setCellValue('A' . $fila, $d['dia']);
                $sheet->setCellValue('B' . $fila, $d['nombre']);
                $col = 3;
                foreach ($estaciones as $e) {
                    $v = $d['celdas'][(int) $e['Codigo']];
                    if ($v !== null) {
                        $sheet->setCellValue(Coordinate::stringFromColumnIndex($col) . $fila, round($v, 2));
                    }
                    $col++;
                }
                if ($d['total'] !== null) {
                    $sheet->setCellValue($colTotal . $fila, round($d['total'], 2));
                }
                $fila++;
            }

            $fila++; // renglón en blanco entre días y resumen
            $inicioResumen = $fila;
            foreach ($filasResumen as $k => $etiqueta) {
                $r = $p['resumen'][$k];
                $sheet->setCellValue('A' . $fila, $etiqueta);
                $col = 3;
                foreach ($estaciones as $e) {
                    $v = $r['celdas'][(int) $e['Codigo']];
                    if ($v !== null) {
                        $sheet->setCellValue(Coordinate::stringFromColumnIndex($col) . $fila, round($v, 2));
                    }
                    $col++;
                }
                if ($r['total'] !== null) {
                    $sheet->setCellValue($colTotal . $fila, round($r['total'], 2));
                }
                $fila++;
            }
            $sheet->getStyle('A' . $inicioResumen . ':' . $colTotal . ($fila - 1))
                  ->getFont()->setBold(true);

            $sheet->getColumnDimension('A')->setWidth(20);
            $sheet->getColumnDimension('B')->setWidth(12);
            $sheet->freezePane('C2');
        }
        $spreadsheet->setActiveSheetIndex(0);

        $archivo = sprintf('ventas_consolidado_%04d_%02d.xlsx', $anio, $mes);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header("Content-Disposition: attachment;filename=\"{$archivo}\"");
        header('Cache-Control: max-age=0');
        (new Xlsx($spreadsheet))->save('php://output');
        exit;
    }

    /**
     * Junta modelo + presupuesto + calculadora. Lo comparten la vista y la
     * exportación a Excel para que no se puedan desincronizar.
     */
    private function armarReporte(int $anio, int $mes): array
    {
        // Codigo llega como string desde PDO; las llaves de las celdas que
        // arma VentasConsolidado son enteros. Se castea una sola vez aquí para
        // que la vista y el exportador indexen sin sorpresas.
        $estaciones = array_map(
            fn($e) => ['Codigo' => (int) $e['Codigo'], 'Nombre' => $e['Nombre'], 'cveest' => $e['cveest'] ?? null],
            $this->mermaModel->get_estaciones_ordenadas()
        );
        $ventas = $this->mermaModel->get_ventas_mes($anio, $mes);

        // Mes anterior y mismo mes del año pasado, para % M.A. y % A.A.
        $mesAnt  = $mes === 1 ? 12 : $mes - 1;
        $anioAnt = $mes === 1 ? $anio - 1 : $anio;

        // Presupuesto: BudgetModel devuelve filas planas; se indexa por estación y producto.
        $budget      = (new BudgetModel())->getBudget($mes, $anio) ?: [];
        $presupuesto = [];
        foreach ($budget as $b) {
            $presupuesto[(int) $b['codgas']][(int) $b['codprd']] = (float) $b['budget_monthy'];
        }

        $ctx = [
            'estaciones'    => $estaciones,
            'ventas'        => $ventas,
            'presupuesto'   => $presupuesto,
            'mes_anterior'  => $this->mermaModel->get_ventas_totales_mes($anioAnt, $mesAnt),
            'anio_anterior' => $this->mermaModel->get_ventas_totales_mes($anio - 1, $mes),
            'anio'          => $anio,
            'mes'           => $mes,
        ];

        $pestanas = [];
        foreach (array_keys(VentasConsolidado::PESTANAS) as $clave) {
            $pestanas[$clave] = VentasConsolidado::construir($clave, $ctx);
        }

        return [
            'anio'            => $anio,
            'mes'             => $mes,
            'estaciones'      => $estaciones,
            'pestanas'        => $pestanas,
            'sin_presupuesto' => $presupuesto === [],
        ];
    }

    /* ===================================================================== */
    /* Corrección de cortes físicos (StockReal en la estación)               */
    /* ===================================================================== */

    /** Filas crudas de StockReal de un día, para el modal de corrección. */
    public function cortes_fisicos(): void
    {
        if (!authorized(self::PERM_VER)) {
            json_output(['success' => false, 'message' => 'No autorizado']);
            return;
        }
        $codgas  = (int)($_POST['codgas'] ?? 0);
        $fecha   = $_POST['fecha'] ?? '';
        $familia = $_POST['familia'] ?? '';
        $turno   = (int)($_POST['turno'] ?? 0);
        if (!in_array($familia, ['maxima', 'super', 'diesel'], true)) $familia = null;
        if (!in_array($turno, [11, 21, 41], true)) $turno = null;
        if (!$codgas || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            json_output(['success' => false, 'message' => 'Parámetros inválidos']);
            return;
        }
        try {
            $cortes = $this->mermaModel->get_cortes_fisicos($codgas, $fecha, $familia, $turno);
        } catch (Throwable $e) {
            json_output(['success' => false, 'message' => 'Error consultando la estación: ' . $e->getMessage()]);
            return;
        }
        json_output(['success' => true, 'cortes' => $cortes,
                     'min' => MermaDiariaModel::INV_FISICO_MIN,
                     'max' => MermaDiariaModel::INV_FISICO_MAX]);
    }

    /** Cruce compras vs recepción física de la estación (modal Validar compras). */
    public function compras_recepcion(): void
    {
        if (!authorized(self::PERM_VER)) {
            json_output(['success' => false, 'message' => 'No autorizado']);
            return;
        }
        $codgas = (int)($_POST['codgas'] ?? 0);
        $desde  = $_POST['desde'] ?? '';
        $hasta  = $_POST['hasta'] ?? '';
        if (!$codgas || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) {
            json_output(['success' => false, 'message' => 'Parámetros inválidos']);
            return;
        }
        try {
            $compras = $this->mermaModel->get_compras_vs_recepcion($codgas, $desde, $hasta);
        } catch (Throwable $e) {
            json_output(['success' => false, 'message' => 'Error consultando la estación: ' . $e->getMessage()]);
            return;
        }
        json_output(['success' => true, 'compras' => $compras]);
    }

    /** Excluye un doc de compra del reporte (solo en TG) y re-sincroniza el día. */
    public function excluir_compra(): void
    {
        set_time_limit(0);
        if (!authorized(self::PERM_VER)) {
            json_output(['success' => false, 'message' => 'No autorizado']);
            return;
        }
        $codgas = (int)($_POST['codgas'] ?? 0);
        $fecha  = $_POST['fecha'] ?? '';
        $codprd = (int)($_POST['codprd'] ?? 0);
        $turno  = (int)($_POST['turno'] ?? 0);
        $nro    = (int)($_POST['nro'] ?? 0);
        $litros = $_POST['litros'] ?? '';
        $motivo = trim((string)($_POST['motivo'] ?? ''));
        if (!$codgas || !$codprd || !$nro || !in_array($turno, [11, 21, 41])
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || !is_numeric($litros) || (float)$litros <= 0) {
            json_output(['success' => false, 'message' => 'Parámetros inválidos']);
            return;
        }
        $usuario = (int)($_SESSION['tg_user']['Id'] ?? 0);
        try {
            $ok = $this->mermaModel->excluir_compra($codgas, $fecha, $codprd, $turno, $nro, (float)$litros, $motivo, $usuario);
        } catch (Throwable $e) {
            json_output(['success' => false, 'message' => 'No se pudo excluir (¿tabla merma_compras_excluidas creada? ¿ya estaba excluido?): ' . $e->getMessage()]);
            return;
        }
        if (!$ok) {
            json_output(['success' => false, 'message' => 'No se pudo registrar la exclusión']);
            return;
        }
        $sync = $this->runSync($fecha, $fecha, $codgas, 'exclusion', $usuario);
        json_output(['success' => true,
                     'message' => 'Doc ' . $nro . ' excluido del reporte'
                                  . ($sync['success'] ? ' y día resincronizado.' : '; resincroniza manualmente: ' . $sync['message'])]);
    }

    /** Quita la exclusión de un doc y re-sincroniza el día. */
    public function incluir_compra(): void
    {
        set_time_limit(0);
        if (!authorized(self::PERM_VER)) {
            json_output(['success' => false, 'message' => 'No autorizado']);
            return;
        }
        $codgas = (int)($_POST['codgas'] ?? 0);
        $fecha  = $_POST['fecha'] ?? '';
        $codprd = (int)($_POST['codprd'] ?? 0);
        $nro    = (int)($_POST['nro'] ?? 0);
        if (!$codgas || !$codprd || !$nro || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            json_output(['success' => false, 'message' => 'Parámetros inválidos']);
            return;
        }
        $usuario = (int)($_SESSION['tg_user']['Id'] ?? 0);
        try {
            $this->mermaModel->incluir_compra($codgas, $fecha, $codprd, $nro);
        } catch (Throwable $e) {
            json_output(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
            return;
        }
        $sync = $this->runSync($fecha, $fecha, $codgas, 'exclusion', $usuario);
        json_output(['success' => true,
                     'message' => 'Doc ' . $nro . ' vuelve a contar en el reporte'
                                  . ($sync['success'] ? '; día resincronizado.' : '; resincroniza manualmente: ' . $sync['message'])]);
    }

    /** Corrige un corte físico en la estación y re-sincroniza ese día. */
    public function corregir_fisico(): void
    {
        set_time_limit(0);
        if (!authorized(self::PERM_VER)) {
            json_output(['success' => false, 'message' => 'No autorizado']);
            return;
        }
        $codgas = (int)($_POST['codgas'] ?? 0);
        $fecha  = $_POST['fecha'] ?? '';
        $codprd = (int)($_POST['codprd'] ?? 0);
        $nrotur = (int)($_POST['nrotur'] ?? 0);
        $codtan = (int)($_POST['codtan'] ?? 0);
        $valor  = $_POST['valor'] ?? '';
        if (!$codgas || !$codprd || !$nrotur || !$codtan
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)
            || !is_numeric($valor) || (float)$valor < 0) {
            json_output(['success' => false, 'message' => 'Parámetros inválidos']);
            return;
        }
        $usuario = (int)($_SESSION['tg_user']['Id'] ?? 0);

        try {
            $res = $this->mermaModel->update_corte_fisico(
                $codgas, $fecha, $codprd, $nrotur, $codtan, (float)$valor, $usuario);
        } catch (Throwable $e) {
            json_output(['success' => false, 'message' => 'Error actualizando en la estación: ' . $e->getMessage()]);
            return;
        }
        if (!$res['success']) {
            json_output($res);
            return;
        }

        // Reflejar la corrección en el snapshot de inmediato
        $sync = $this->runSync($fecha, $fecha, $codgas, 'correccion', $usuario);
        json_output([
            'success'  => true,
            'anterior' => $res['anterior'],
            'message'  => 'Corte corregido en la estación'
                          . (($res['sg12'] ?? false) ? ' y en SG12' : ' (la réplica SG12 no tenía la fila)')
                          . ($sync['success'] ? '; día resincronizado.'
                                              : '; pero la resincronización falló: ' . $sync['message']),
        ]);
    }

    /* ===================================================================== */
    /* Sincronización                                                        */
    /* ===================================================================== */

    /** ¿La petición viene del cron con token válido? */
    private function isCron(): bool
    {
        $token = $_POST['cron_token'] ?? $_GET['cron_token'] ?? null;
        return defined('CRON_SECRET') && $token === CRON_SECRET;
    }

    /**
     * Consulta ApiER y reemplaza el snapshot del rango. Regresa el resumen
     * que responden tanto /merma/sync como /merma/sync_diario.
     */
    private function runSync(string $desde, string $hasta, int $codgas, string $origen, ?int $usuario): array
    {
        $inicio = microtime(true);

        $ch = curl_init(self::API_URL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'from' => $desde, 'to' => $hasta, 'codgas' => $codgas,
        ]));
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        $response = curl_exec($ch);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            $duracion = round(microtime(true) - $inicio, 1);
            $mensaje  = "No se pudo contactar ApiER: $curlErr";
            $this->mermaModel->add_sync_log(
                $origen, $usuario, $desde, $hasta, $codgas, 0, 0, $mensaje, $duracion
            );
            return [
                'success'          => false,
                'message'          => $mensaje,
                'estaciones_ok'    => 0,
                'estaciones_error' => 0,
                'errores'          => [],
                'filas'            => 0,
                'duracion_seg'     => $duracion,
            ];
        }
        $api = json_decode($response, true);
        if (!isset($api['resultados']) || !is_array($api['resultados'])) {
            $detail   = $api['detail'] ?? substr((string)$response, 0, 200);
            $duracion = round(microtime(true) - $inicio, 1);
            $mensaje  = "Respuesta inesperada de ApiER: $detail";
            $this->mermaModel->add_sync_log(
                $origen, $usuario, $desde, $hasta, $codgas, 0, 0, $mensaje, $duracion
            );
            return [
                'success'          => false,
                'message'          => $mensaje,
                'estaciones_ok'    => 0,
                'estaciones_error' => 0,
                'errores'          => [],
                'filas'            => 0,
                'duracion_seg'     => $duracion,
            ];
        }

        $filasTotal    = 0;
        $estacionesOk  = 0;
        $fallosLocales = [];
        foreach ($api['resultados'] as $est) {
            $codigo  = (int)($est['Codigo'] ?? 0);
            $nombre  = $est['Nombre'] ?? '';
            $filas   = $est['filas'] ?? [];
            try {
                $filasTotal += $this->mermaModel->replace_station_range(
                    $codigo, $nombre, $desde, $hasta, $filas
                );
                $estacionesOk++;
            } catch (Throwable $e) {
                $fallosLocales[] = ['Nombre' => $nombre, 'error' => $e->getMessage()];
            }
        }

        $errores      = $api['errores'] ?? [];
        $todosErrores = array_merge($errores, $fallosLocales);
        $duracion     = round(microtime(true) - $inicio, 1);
        $detalle      = $todosErrores
            ? implode('; ', array_map(fn($e) => $e['Nombre'] . ': ' . substr($e['error'], 0, 150), $todosErrores))
            : '';

        $this->mermaModel->add_sync_log(
            $origen, $usuario, $desde, $hasta, $codgas,
            $estacionesOk, count($todosErrores), $detalle, $duracion
        );

        return [
            'success'          => true,
            'message'          => $estacionesOk . ' estaciones sincronizadas'
                                  . ($todosErrores ? ', ' . count($todosErrores) . ' con error' : ''),
            'estaciones_ok'    => $estacionesOk,
            'estaciones_error' => count($todosErrores),
            'errores'          => array_map(fn($e) => $e['Nombre'], $todosErrores),
            'filas'            => $filasTotal,
            'duracion_seg'     => $duracion,
        ];
    }

    /**
     * Botón "Actualizar datos" (POST from, to, codgas) — permiso 33 o cron_token.
     */
    public function sync(): void
    {
        set_time_limit(0);
        if (!$this->isCron() && !authorized(self::PERM_VER)) {
            json_output(['success' => false, 'message' => 'No autorizado']);
            return;
        }
        $desde  = $_POST['from'] ?? null;
        $hasta  = $_POST['to'] ?? null;
        $codgas = (int)($_POST['codgas'] ?? 0);
        if (!$desde || !$hasta
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)
            || $desde > $hasta) {
            json_output(['success' => false, 'message' => 'Rango de fechas inválido']);
            return;
        }
        // Tope de 40 días por sync para no tumbar las estaciones
        if ((strtotime($hasta) - strtotime($desde)) / 86400 > 40) {
            json_output(['success' => false, 'message' => 'El rango máximo por sincronización es de 40 días']);
            return;
        }
        $usuario = $_SESSION['tg_user']['Id'] ?? null;
        json_output($this->runSync($desde, $hasta, $codgas, $this->isCron() ? 'cron' : 'manual', $usuario));
    }

    /**
     * Cron de madrugada: sincroniza D-2 y D-1 de todas las estaciones.
     * GET/POST /merma/sync_diario?cron_token=CRON_SECRET
     */
    public function sync_diario(): void
    {
        set_time_limit(0);
        if (!$this->isCron()) {
            json_output(['success' => false, 'message' => 'No autorizado']);
            return;
        }
        $desde = date('Y-m-d', strtotime('-2 days'));
        $hasta = date('Y-m-d', strtotime('-1 day'));
        $res = $this->runSync($desde, $hasta, 0, 'cron', null);
        if (PHP_SAPI === 'cli') {
            // Corrida por Task Scheduler: el exit code es la única señal externa
            echo json_encode($res) . PHP_EOL;
            exit(($res['success'] && ($res['estaciones_error'] ?? 0) == 0) ? 0 : 1);
        }
        json_output($res);
    }

    /* ===================================================================== */
    /* Captura manual                                                        */
    /* ===================================================================== */

    /** Guarda merma s/d o comentario de una estación/mes (permiso 33). */
    public function guardar_manual(): void
    {
        if (!authorized(self::PERM_VER)) {
            json_output(['success' => false, 'message' => 'No autorizado']);
            return;
        }
        $codgas = (int)($_POST['codgas'] ?? 0);
        $anio   = (int)($_POST['anio'] ?? 0);
        $mes    = (int)($_POST['mes'] ?? 0);
        $campo  = $_POST['campo'] ?? '';
        $valor  = $_POST['valor'] ?? '';
        if (!$codgas || $anio < 2020 || $mes < 1 || $mes > 12) {
            json_output(['success' => false, 'message' => 'Parámetros inválidos']);
            return;
        }
        if ($campo !== 'comentarios' && $valor !== '' && !is_numeric($valor)) {
            json_output(['success' => false, 'message' => 'El valor debe ser numérico']);
            return;
        }
        $ok = $this->mermaModel->save_manual(
            $codgas, $anio, $mes, $campo, $valor, (int)($_SESSION['tg_user']['Id'] ?? 0)
        );
        json_output(['success' => $ok, 'message' => $ok ? 'Guardado' : 'Campo inválido']);
    }

    /** Guarda el precio por litro del mes para la valorización (permiso 33). */
    public function guardar_precio(): void
    {
        if (!authorized(self::PERM_VER)) {
            json_output(['success' => false, 'message' => 'No autorizado']);
            return;
        }
        $anio   = (int)($_POST['anio'] ?? 0);
        $mes    = (int)($_POST['mes'] ?? 0);
        $precio = $_POST['precio'] ?? '';
        if ($anio < 2020 || $mes < 1 || $mes > 12 || !is_numeric($precio) || (float)$precio <= 0) {
            json_output(['success' => false, 'message' => 'Parámetros inválidos']);
            return;
        }
        $ok = $this->mermaModel->save_precio(
            $anio, $mes, (float)$precio, (int)($_SESSION['tg_user']['Id'] ?? 0)
        );
        json_output(['success' => $ok]);
    }
}
