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
    private const PERM_VER      = 33;  // Ver sección de Reportes (Abastos)
    private const PERM_CORREGIR = 83;  // Corregir físico y compras (Merma)
    private const API_URL  = 'http://192.168.0.109:82/api/inventarios_turnos/';
    private const CODGAS_PRAXEDIS = 40;
    private const CODGAS_COLOSIO  = 199;

    private $twig;
    private $route;
    private $mermaModel;
    private $evidenciaModel;

    public function __construct($twig)
    {
        $this->twig           = $twig;
        $this->route          = 'views/merma/';
        $this->mermaModel     = new MermaDiariaModel();
        $this->evidenciaModel = new MermaFisicoEvidenciaModel();
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

        $ultimoSync = $this->mermaModel->get_ultimo_sync_ok();

        echo $this->twig->render($this->route . 'analisis.html',
            compact('anio', 'mes', 'desde', 'hasta', 'maxHasta', 'filas', 'totales',
                    'syncDesde', 'syncHasta', 'ultimoSync'));
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
        $corregidos = $this->mermaModel->get_turnos_corregidos($codgas, $desde, $hasta);

        // Acumulado de diferencia por familia (como las columnas I/P del Excel)
        $acum    = ['maxima' => 0.0, 'super' => 0.0, 'diesel' => 0.0];
        $compras = ['maxima' => 0.0, 'super' => 0.0, 'diesel' => 0.0];
        $ventas  = ['maxima' => 0.0, 'super' => 0.0, 'diesel' => 0.0];
        $filas   = [];
        foreach ($rows as $r) {
            $fechaFila = substr($r['fecha'], 0, 10);
            $turnoFila = (int)$r['turno'];
            $fila = ['fecha' => $fechaFila, 'turno' => $turnoFila];
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
                    // Ya se corrigió antes (aunque ya no esté corrupta): la
                    // celda sigue siendo editable y se marca con "!" en vez
                    // del triángulo rojo de corrupta.
                    'fis_corregido' => isset($corregidos[$fechaFila . '-' . $fam . '-' . $turnoFila]),
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

        // Piso de los selectores de la pestaña histórica: el primer año que
        // exista en merma_diaria, de modo que el rango disponible crezca solo
        // conforme se sincronice más historia hacia atrás.
        $anioMinHist = $this->mermaModel->get_anio_min_historico() ?? $anioAyer;
        if ($anioMinHist > $anioAyer) $anioMinHist = $anioAyer;
        // No puede caer por debajo del piso que periodoHistorico() valida,
        // o el selector ofrecería un año que el propio validador reescribe.
        $anioMinHist = max(2020, $anioMinHist);

        echo $this->twig->render($this->route . 'ventas.html', $reporte + [
            'anios'  => range($anioAyer, $anioAyer - 2),
            'meses'  => ['ENERO', 'FEBRERO', 'MARZO', 'ABRIL', 'MAYO', 'JUNIO',
                         'JULIO', 'AGOSTO', 'SEPTIEMBRE', 'OCTUBRE', 'NOVIEMBRE', 'DICIEMBRE'],
            // Controles de la pestaña histórica
            'histAnios' => range($anioAyer, $anioMinHist),
            'histDesde' => max($anioMinHist, $anioAyer - 2),
            'histHasta' => $anioAyer,
            'histProds' => VentasConsolidado::PESTANAS,
        ]);
    }

    /**
     * Pestaña HISTÓRICO de /merma/ventas: acumulado mensual por estación
     * sobre un rango de años. Devuelve SOLO el HTML de la tabla — la pestaña
     * lo pide por AJAX para que sus controles no colisionen con el selector
     * de mes que gobierna las cinco pestañas diarias.
     */
    public function ventas_historico(): void
    {
        if (!authorized(self::PERM_VER)) {
            (new Errors())->get404();
            return;
        }
        [$desde, $hasta, $prod] = $this->periodoHistorico();

        ['estaciones' => $estaciones, 'hist' => $hist] = $this->armarHistorico($desde, $hasta, $prod);

        echo $this->twig->render($this->route . 'ventas_historico.html',
            compact('estaciones', 'hist', 'desde', 'hasta', 'prod'));
    }

    /**
     * Valida desde/hasta/prod de la pestaña histórica. Mismo criterio que
     * ventas(): piso duro en 2020 para que un parámetro manipulado no pida un
     * rango absurdo, aunque el piso del SELECTOR sea el primer año que exista
     * en la tabla (get_anio_min_historico), que es más alto.
     *
     * @return array{0:int,1:int,2:string}
     */
    private function periodoHistorico(): array
    {
        $anioActual = (int) date('Y');
        $desde = (int) ($_GET['desde'] ?? $anioActual - 2);
        $hasta = (int) ($_GET['hasta'] ?? $anioActual);
        if ($desde < 2020 || $desde > $anioActual) $desde = $anioActual - 2;
        if ($hasta < 2020 || $hasta > $anioActual) $hasta = $anioActual;
        if ($desde > $hasta) [$desde, $hasta] = [$hasta, $desde];

        $prod = (string) ($_GET['prod'] ?? 'total');
        if (!isset(VentasConsolidado::PESTANAS[$prod])) $prod = 'total';

        return [$desde, $hasta, $prod];
    }

    /**
     * Junta modelo + calculadora para la pestaña HISTÓRICO. Lo comparten la
     * vista (ventas_historico) y la exportación (ventas_excel) para que la
     * tabla y su leyenda de cobertura no se puedan desincronizar entre
     * pantalla y .xlsx.
     *
     * @return array{estaciones: array, hist: array}
     */
    private function armarHistorico(int $desde, int $hasta, string $prod): array
    {
        $estaciones = array_map(
            fn($e) => ['Codigo' => (int) $e['Codigo'], 'Nombre' => $e['Nombre']],
            $this->mermaModel->get_estaciones_ordenadas()
        );
        $hist = VentasConsolidado::construirHistorico($prod, [
            'estaciones' => $estaciones,
            'historico'  => $this->mermaModel->get_historico_mensual($desde, $hasta),
            'desde'      => $desde,
            'hasta'      => $hasta,
        ]);

        return ['estaciones' => $estaciones, 'hist' => $hist];
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

        // Sexta hoja: el histórico mensual, con el mismo rango y producto que
        // la pestaña tenga seleccionados (o los valores por defecto si el
        // usuario nunca la abrió).
        [$hDesde, $hHasta, $hProd] = $this->periodoHistorico();
        ['estaciones' => $estacionesHist, 'hist' => $hist] = $this->armarHistorico($hDesde, $hHasta, $hProd);

        $hoja = $spreadsheet->createSheet();
        $hoja->setTitle('HISTÓRICO');
        $hoja->setCellValue('A1', 'MES');
        $col = 2;
        foreach ($estacionesHist as $e) {
            $hoja->setCellValue(Coordinate::stringFromColumnIndex($col) . '1', $e['Nombre']);
            $col++;
        }
        $colTotalHist = Coordinate::stringFromColumnIndex($col);
        $hoja->setCellValue($colTotalHist . '1', 'TOTAL');
        $hoja->setCellValue(Coordinate::stringFromColumnIndex($col + 2) . '1',
                            'Producto: ' . $hist['label']);
        $hoja->getStyle('A1:' . $colTotalHist . '1')->getFont()->setBold(true);

        $fila = 2;
        foreach ($hist['filas'] as $f) {
            $hoja->setCellValue('A' . $fila, $f['etiqueta']);
            $col = 2;
            foreach ($estacionesHist as $e) {
                $hoja->setCellValue(Coordinate::stringFromColumnIndex($col) . $fila,
                                    round($f['celdas'][(int) $e['Codigo']], 2));
                $col++;
            }
            $hoja->setCellValue($colTotalHist . $fila, round($f['total'], 2));
            if ($f['tipo'] === 'anual') {
                $hoja->getStyle('A' . $fila . ':' . $colTotalHist . $fila)->getFont()->setBold(true);
            }
            $fila++;
        }

        // Leyenda de cobertura: en pantalla es la única señal que impide leer
        // los ceros de los meses no sincronizados como una caída real de
        // ventas, y el .xlsx es justo lo que se reenvía por correo a alguien
        // que nunca vio la pantalla — sin esto, ese lector no tiene forma de
        // saberlo.
        $fila++; // renglón en blanco entre los datos y la leyenda
        $mesesConDatos = $hist['meses_con_datos'];
        $leyenda = $mesesConDatos
            ? sprintf('Con datos: %d de %d meses del rango (%s). Los meses sin sincronizar aparecen en cero.',
                       count($mesesConDatos), $hist['meses_del_rango'], implode(', ', $mesesConDatos))
            : sprintf('Ningún mes del rango está sincronizado (%d meses, todos en cero).',
                      $hist['meses_del_rango']);
        $hoja->setCellValue('A' . $fila, $leyenda);

        $hoja->getColumnDimension('A')->setWidth(20);
        $hoja->freezePane('B2');

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

        // Presupuesto: hrms.dbo.incentives_presupuestoventa (equipo de Incentivos),
        // ya resuelto e indexado por estación y familia en el modelo.
        $presupuesto = (new IncentivesPresupuestoModel())->getPresupuesto($mes, $anio);

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
        if (!authorized(self::PERM_CORREGIR)) {
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

    /**
     * Vista parcial con la bitácora de correcciones de cortes físicos
     * (merma_fisico_log) de una estación en un rango — botón "Cambios"
     * junto a "Validar compras" en el detalle.
     */
    public function cambios_fisico(): void
    {
        if (!authorized(self::PERM_VER)) {
            echo '<div class="modal-body"><div class="alert alert-danger mb-0">No autorizado</div></div>';
            return;
        }
        $codgas = (int)($_POST['codgas'] ?? 0);
        $desde  = $_POST['desde'] ?? '';
        $hasta  = $_POST['hasta'] ?? '';
        if (!$codgas || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) {
            echo '<div class="modal-body"><div class="alert alert-danger mb-0">Parámetros inválidos</div></div>';
            return;
        }
        $cambios = $this->mermaModel->get_bitacora_fisico($codgas, $desde, $hasta);
        echo $this->twig->render($this->route . 'modals/cambios_fisico.html', compact('cambios', 'desde', 'hasta'));
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
        if (!authorized(self::PERM_CORREGIR)) {
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
        if (!authorized(self::PERM_CORREGIR)) {
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
        if (!authorized(self::PERM_CORREGIR)) {
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

    /**
     * Corrige varios cortes físicos del modal en un solo clic (uno o más
     * tanques/turnos del mismo día). Cada fila válida se corrige en la
     * estación como corregir_fisico(); el día se resincroniza UNA sola vez
     * al final del lote, no por cada fila. POST filas = JSON array de
     * {codprd, nrotur, codtan, valor}.
     */
    public function corregir_fisico_lote(): void
    {
        set_time_limit(0);
        if (!authorized(self::PERM_CORREGIR)) {
            json_output(['success' => false, 'message' => 'No autorizado']);
            return;
        }
        $codgas = (int)($_POST['codgas'] ?? 0);
        $fecha  = $_POST['fecha'] ?? '';
        $filas  = json_decode($_POST['filas'] ?? '[]', true);
        if (!$codgas || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || !is_array($filas) || empty($filas)) {
            json_output(['success' => false, 'message' => 'Parámetros inválidos']);
            return;
        }

        $usuario   = (int)($_SESSION['tg_user']['Id'] ?? 0);
        $resultados = [];
        $corregidas = 0;

        foreach ($filas as $f) {
            $codprd = (int)($f['codprd'] ?? 0);
            $nrotur = (int)($f['nrotur'] ?? 0);
            $codtan = (int)($f['codtan'] ?? 0);
            $valor  = $f['valor'] ?? '';
            if (!$codprd || !$nrotur || !$codtan || !is_numeric($valor) || (float)$valor < 0) {
                $resultados[] = ['codprd' => $codprd, 'nrotur' => $nrotur, 'codtan' => $codtan,
                                  'success' => false, 'message' => 'Fila inválida, se omitió'];
                continue;
            }
            try {
                $res = $this->mermaModel->update_corte_fisico(
                    $codgas, $fecha, $codprd, $nrotur, $codtan, (float)$valor, $usuario);
            } catch (Throwable $e) {
                $res = ['success' => false, 'message' => 'Error actualizando en la estación: ' . $e->getMessage()];
            }
            $res['codprd'] = $codprd; $res['nrotur'] = $nrotur; $res['codtan'] = $codtan;
            $resultados[] = $res;
            if ($res['success']) $corregidas++;
        }

        // Un solo resync del día, después de aplicar todas las correcciones
        // del lote — evita resincronizar N veces si hay varias filas.
        $sync = $corregidas > 0 ? $this->runSync($fecha, $fecha, $codgas, 'correccion', $usuario) : null;

        json_output([
            'success'     => $corregidas > 0,
            'corregidas'  => $corregidas,
            'total'       => count($filas),
            'resultados'  => $resultados,
            'message'     => $corregidas > 0
                ? "{$corregidas} corte(s) corregido(s)"
                    . ($sync && $sync['success'] ? '; día resincronizado.'
                                                  : ($sync ? '; pero la resincronización falló: ' . $sync['message'] : ''))
                : 'Ningún corte válido para corregir',
        ]);
    }

    /* ===================================================================== */
    /* Evidencia de corrección de corte físico                              */
    /* ===================================================================== */

    /** Lista la evidencia (imagen/PDF) ya subida para una celda fecha+producto+turno. */
    public function evidencia_fisico(): void
    {
        header('Content-Type: application/json');
        if (!authorized(self::PERM_CORREGIR)) {
            json_output(['success' => false, 'message' => 'No autorizado']);
            return;
        }
        $codgas = (int)($_POST['codgas'] ?? 0);
        $fecha  = $_POST['fecha'] ?? '';
        $codprd = (int)($_POST['codprd'] ?? 0);
        $turno  = (int)($_POST['turno'] ?? 0);
        if (!$codgas || !$codprd || !$turno || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            json_output(['success' => false, 'message' => 'Parámetros inválidos']);
            return;
        }
        $archivos = $this->evidenciaModel->get_by_celda($codgas, $fecha, $codprd, $turno);
        json_output(['success' => true, 'archivos' => $archivos]);
    }

    /** Sube uno o más archivos de evidencia para una celda fecha+producto+turno. */
    public function subir_evidencia_fisico(): void
    {
        header('Content-Type: application/json');
        if (!authorized(self::PERM_CORREGIR)) {
            json_output(['success' => false, 'message' => 'No autorizado']);
            return;
        }
        $codgas = (int)($_POST['codgas'] ?? 0);
        $fecha  = $_POST['fecha'] ?? '';
        $codprd = (int)($_POST['codprd'] ?? 0);
        $turno  = (int)($_POST['turno'] ?? 0);
        if (!$codgas || !$codprd || !$turno || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            json_output(['success' => false, 'message' => 'Parámetros inválidos']);
            return;
        }
        if (empty($_FILES['evidencia']) || !is_array($_FILES['evidencia']['name'])) {
            json_output(['success' => false, 'message' => 'No se recibieron archivos']);
            return;
        }

        $usuario  = (int)($_SESSION['tg_user']['Id'] ?? 0);
        $files    = $_FILES['evidencia'];
        $total    = count($files['name']);
        $subidos  = 0;
        $errores  = [];

        for ($i = 0; $i < $total; $i++) {
            $file = [
                'name'     => $files['name'][$i],
                'type'     => $files['type'][$i],
                'tmp_name' => $files['tmp_name'][$i],
                'error'    => $files['error'][$i],
                'size'     => $files['size'][$i],
            ];
            $res = $this->evidenciaModel->upload($codgas, $fecha, $codprd, $turno, $file, $usuario);
            if ($res['success']) $subidos++;
            else $errores[] = $file['name'] . ': ' . $res['message'];
        }

        json_output([
            'success'  => $subidos > 0,
            'subidos'  => $subidos,
            'errores'  => $errores,
            'message'  => $subidos > 0
                ? "{$subidos} archivo(s) subido(s)" . ($errores ? ' (' . count($errores) . ' con error)' : '')
                : 'No se pudo subir ningún archivo',
        ]);
    }

    /** Soft-delete de un archivo de evidencia: se oculta de la lista, no se borra de disco. */
    public function eliminar_evidencia_fisico(): void
    {
        header('Content-Type: application/json');
        if (!authorized(self::PERM_CORREGIR)) {
            json_output(['success' => false, 'message' => 'No autorizado']);
            return;
        }
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            json_output(['success' => false, 'message' => 'Parámetros inválidos']);
            return;
        }
        $usuario = (int)($_SESSION['tg_user']['Id'] ?? 0);
        $ok = $this->evidenciaModel->soft_delete($id, $usuario);
        json_output($ok
            ? ['success' => true, 'message' => 'Archivo eliminado']
            : ['success' => false, 'message' => 'No se pudo eliminar (¿ya estaba eliminado?)']);
    }

    /** Sirve un archivo de evidencia (imagen/PDF). GET /merma/view_evidencia_fisico/ID */
    public function view_evidencia_fisico($id): void
    {
        if (!authorized(self::PERM_CORREGIR)) {
            http_response_code(403);
            echo 'No autorizado';
            return;
        }
        $doc = $this->evidenciaModel->get_by_id((int)$id);
        if (!$doc) {
            http_response_code(404);
            echo 'Documento no encontrado';
            return;
        }

        $fullPath = realpath(__DIR__ . '/../../' . $doc['file_path']);
        $base     = realpath(__DIR__ . '/../../' . MermaFisicoEvidenciaModel::UPLOAD_BASE);

        if (!$fullPath || !$base || !str_starts_with($fullPath, $base) || !file_exists($fullPath)) {
            http_response_code(404);
            echo 'Archivo no encontrado';
            return;
        }

        $mime = match ($doc['file_extension']) {
            'pdf'        => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png'        => 'image/png',
            default      => 'application/octet-stream',
        };

        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . basename($fullPath) . '"');
        header('Content-Length: ' . filesize($fullPath));
        readfile($fullPath);
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
     * Cron de madrugada: sincroniza el mes en curso (día 1 -> ayer) de todas las estaciones.
     * GET/POST /merma/sync_diario?cron_token=CRON_SECRET
     */
    public function sync_diario(): void
    {
        set_time_limit(0);
        if (!$this->isCron()) {
            json_output(['success' => false, 'message' => 'No autorizado']);
            return;
        }
        // Día 1 del mes en curso -> ayer, para que un fallo puntual de un
        // día no deje huecos permanentes: el cron del día siguiente lo
        // vuelve a cubrir. Si hoy es día 1, "ayer" cae en el mes anterior;
        // en ese caso se sincroniza ese mes anterior completo — mismo
        // criterio que el default de analisis().
        $hasta = date('Y-m-d', strtotime('-1 day'));
        $desde = date('Y-m-01');
        if ($desde > $hasta) $desde = date('Y-m-01', strtotime($hasta));
        $res = $this->runSync($desde, $hasta, 0, 'cron', null);
        if (PHP_SAPI === 'cli') {
            // Corrida por Task Scheduler: el exit code es la única señal externa
            echo json_encode($res) . PHP_EOL;
            exit(($res['success'] && ($res['estaciones_error'] ?? 0) == 0) ? 0 : 1);
        }
        json_output($res);
    }

    /**
     * Preview de carga manual de "Balance de Producto" (Praxedis) — no persiste.
     * POST $_FILES['balances'][] (PDFs).
     */
    public function preview_balance_praxedis(): void
    {
        header('Content-Type: application/json');
        if (!authorized(self::PERM_VER)) {
            json_output(['success' => false, 'message' => 'No autorizado']);
            return;
        }
        if (empty($_FILES['balances']) || !is_array($_FILES['balances']['name'])) {
            $maxUploads = (int) ini_get('max_file_uploads') ?: 20;
            // Si se excede max_file_uploads, PHP descarta $_FILES y deja el warning.
            json_output(['success' => false, 'message' => "No se recibieron PDFs (¿superaste el máximo de {$maxUploads} archivos por carga? Súbelos en grupos más pequeños)"]);
            return;
        }

        $files = $_FILES['balances'];
        $total = count($files['name']);

        $maxUploads = (int) ini_get('max_file_uploads') ?: 20;
        if ($total > $maxUploads) {
            json_output(['success' => false, 'message' => "Enviaste {$total} archivos; el máximo por carga es {$maxUploads}. Súbelos en grupos."]);
            return;
        }

        $resultados = [];
        $resumen = ['ok' => 0, 'error' => 0, 'total' => $total];

        for ($i = 0; $i < $total; $i++) {
            $nombre = $files['name'][$i];
            if ($files['error'][$i] !== UPLOAD_ERR_OK || strtolower(pathinfo($nombre, PATHINFO_EXTENSION)) !== 'pdf') {
                $resultados[] = ['archivo' => $nombre, 'ok' => false, 'error' => 'Archivo inválido', 'fecha' => '', 'filas' => []];
                $resumen['error']++;
                continue;
            }
            $r = BalanceProductoPdfParser::parse($files['tmp_name'][$i], $nombre);
            $resultados[] = $r;
            $r['ok'] ? $resumen['ok']++ : $resumen['error']++;
        }

        json_output(['success' => true, 'resumen' => $resumen, 'archivos' => $resultados]);
    }

    /**
     * Confirma la carga: re-parsea los PDFs recibidos, agrupa por fecha y
     * reemplaza el snapshot de Praxedis en TG.dbo.merma_diaria (turno
     * sintético 41 — el "Balance de Producto" no trae desglose por turno).
     * POST $_FILES['balances'][] (los mismos PDFs del preview).
     */
    public function guardar_balance_praxedis(): void
    {
        header('Content-Type: application/json');
        if (!authorized(self::PERM_VER)) {
            json_output(['success' => false, 'message' => 'No autorizado']);
            return;
        }
        if (empty($_FILES['balances']) || !is_array($_FILES['balances']['name'])) {
            $maxUploads = (int) ini_get('max_file_uploads') ?: 20;
            // Si se excede max_file_uploads, PHP descarta $_FILES y deja el warning.
            json_output(['success' => false, 'message' => "No se recibieron PDFs (¿superaste el máximo de {$maxUploads} archivos por carga? Súbelos en grupos más pequeños)"]);
            return;
        }

        $files = $_FILES['balances'];
        $total = count($files['name']);

        $maxUploads = (int) ini_get('max_file_uploads') ?: 20;
        if ($total > $maxUploads) {
            json_output(['success' => false, 'message' => "Enviaste {$total} archivos; el máximo por carga es {$maxUploads}. Súbelos en grupos."]);
            return;
        }

        // Agrupar filas válidas por fecha (un PDF = un día; el lote puede traer varios días)
        $porFecha = [];
        $resultados = [];
        for ($i = 0; $i < $total; $i++) {
            $nombre = $files['name'][$i];
            if ($files['error'][$i] !== UPLOAD_ERR_OK || strtolower(pathinfo($nombre, PATHINFO_EXTENSION)) !== 'pdf') {
                $resultados[] = ['archivo' => $nombre, 'success' => false, 'message' => 'Archivo inválido'];
                continue;
            }
            $r = BalanceProductoPdfParser::parse($files['tmp_name'][$i], $nombre);
            if (!$r['ok']) {
                $resultados[] = ['archivo' => $nombre, 'success' => false, 'message' => $r['error']];
                continue;
            }
            if (isset($porFecha[$r['fecha']])) {
                $resultados[] = ['archivo' => $nombre, 'success' => false,
                    'message' => "Fecha {$r['fecha']} duplicada en este lote; se ignoró este archivo"];
                continue;
            }
            $porFecha[$r['fecha']] = $r['filas'];
            $resultados[] = ['archivo' => $nombre, 'success' => true, 'message' => "Fecha {$r['fecha']} lista"];
        }

        if (empty($porFecha)) {
            json_output(['success' => false, 'message' => 'Ningún PDF válido para guardar', 'resultados' => $resultados]);
            return;
        }

        $filasInsertadas = 0;
        $fechasOk = [];
        foreach ($porFecha as $fecha => $filasProducto) {
            $filas = array_map(fn($f) => [
                'Fecha'               => $fecha,
                'CodProducto'         => $f['codprd'],
                'Producto'            => $f['producto'],
                'Turno'               => 41,
                'VentasReales'        => $f['ventas_reales'],
                'Inventario'          => $f['inv_fisico'],
                'CantidadCompra'      => $f['compras'],
                'InventarioInicial'   => null,
                'InventarioContable'  => null,
                'Diferencia'          => null,
            ], $filasProducto);

            // Si no hay ningún corte físico previo para este producto, el
            // LAG de recalc_contable no tiene de dónde encadenar y el día
            // queda en s/d. Se siembra el día anterior con el "Inv Inicial"
            // que trae el propio PDF (el cierre real del día previo según
            // ControlGas), solo si ese día anterior no tiene ya un dato.
            $fechaAnterior = date('Y-m-d', strtotime($fecha . ' -1 day'));
            $filasSemilla = [];
            foreach ($filasProducto as $f) {
                if ($f['inv_inicial'] === null) continue;
                if ($this->mermaModel->existe_fisico_previo(self::CODGAS_PRAXEDIS, $f['codprd'], $fecha)) continue;
                $filasSemilla[] = [
                    'Fecha'               => $fechaAnterior,
                    'CodProducto'         => $f['codprd'],
                    'Producto'            => $f['producto'],
                    'Turno'               => 41,
                    'VentasReales'        => null,
                    'Inventario'          => $f['inv_inicial'],
                    'CantidadCompra'      => null,
                    'InventarioInicial'   => null,
                    'InventarioContable'  => null,
                    'Diferencia'          => null,
                ];
            }

            try {
                if ($filasSemilla) {
                    $this->mermaModel->replace_station_range(
                        self::CODGAS_PRAXEDIS, 'PRAXEDIS', $fechaAnterior, $fechaAnterior, $filasSemilla
                    );
                }
                $filasInsertadas += $this->mermaModel->replace_station_range(
                    self::CODGAS_PRAXEDIS, 'PRAXEDIS', $fecha, $fecha, $filas
                );
                $fechasOk[] = $fecha;
            } catch (Throwable $e) {
                $resultados[] = ['archivo' => "fecha {$fecha}", 'success' => false, 'message' => $e->getMessage()];
            }
        }

        json_output([
            'success'     => count($fechasOk) > 0,
            'fechas'      => $fechasOk,
            'filas'       => $filasInsertadas,
            'resultados'  => $resultados,
        ]);
    }

    /**
     * Captura manual (sin PDF) del corte diario de Colosio (Repsol,
     * Aguascalientes) — estación que TotalGas administra pero que no está
     * en ControlGas; la información llega por otro medio y se transcribe
     * aquí a mano. Mismo esquema que la carga de Praxedis: turno sintético
     * 41, inv_inicial/inv_contable/diferencia los calcula recalc_contable().
     * POST fecha (YYYY-MM-DD), y por familia (maxima/super/diesel) los
     * campos <familia>_fisico/<familia>_ventas/<familia>_compras (vacíos si
     * la estación no vendió ese producto ese día).
     */
    public function guardar_captura_manual_merma(): void
    {
        header('Content-Type: application/json');
        if (!authorized(self::PERM_VER)) {
            json_output(['success' => false, 'message' => 'No autorizado']);
            return;
        }
        $fecha = $_POST['fecha'] ?? '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            json_output(['success' => false, 'message' => 'Fecha inválida']);
            return;
        }

        $familias = MermaDiariaModel::FAMILIAS_CAPTURA_MANUAL;
        $filas = [];
        foreach ($familias as $familia => $meta) {
            $fisico  = $_POST[$familia . '_fisico'] ?? '';
            $ventas  = $_POST[$familia . '_ventas'] ?? '';
            $compras = $_POST[$familia . '_compras'] ?? '';
            if ($fisico === '' && $ventas === '' && $compras === '') continue; // familia no capturada ese día
            if (!is_numeric($fisico) || !is_numeric($ventas)) {
                json_output(['success' => false, 'message' => "Inv. físico y ventas de {$meta['producto']} deben ser numéricos"]);
                return;
            }
            $filas[] = [
                'Fecha'               => $fecha,
                'CodProducto'         => $meta['codprd'],
                'Producto'            => $meta['producto'],
                'Turno'               => 41,
                'VentasReales'        => (float)$ventas,
                'Inventario'          => (float)$fisico,
                'CantidadCompra'      => $compras === '' ? 0 : (float)$compras,
                'InventarioInicial'   => null,
                'InventarioContable'  => null,
                'Diferencia'          => null,
            ];
        }

        if (empty($filas)) {
            json_output(['success' => false, 'message' => 'Captura al menos una familia de producto']);
            return;
        }

        try {
            $insertadas = $this->mermaModel->replace_station_range(
                self::CODGAS_COLOSIO, 'COLOSIO', $fecha, $fecha, $filas
            );
        } catch (Throwable $e) {
            json_output(['success' => false, 'message' => $e->getMessage()]);
            return;
        }

        json_output(['success' => true, 'message' => "Fecha {$fecha} guardada", 'filas' => $insertadas]);
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
