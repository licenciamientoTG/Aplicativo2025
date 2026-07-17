<?php

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
        $anio = (int)($_GET['anio'] ?? date('Y'));
        $mes  = (int)($_GET['mes'] ?? date('n'));
        if ($mes < 1 || $mes > 12) $mes = (int)date('n');

        $estaciones = $this->mermaModel->get_estaciones();
        $resumen    = $this->mermaModel->get_resumen_mensual($anio, $mes);
        $manual     = $this->mermaModel->get_manual($anio, $mes);
        $fechas     = $this->mermaModel->get_fechas_por_estacion($anio, $mes);
        $precio     = $this->mermaModel->get_precio($anio, $mes);

        // Días esperados del mes: hasta ayer si es el mes en curso, o el mes completo
        $ultimoDia = ($anio == date('Y') && $mes == date('n'))
            ? (int)date('j') - 1
            : (int)date('t', mktime(0, 0, 0, $mes, 1, $anio));
        $diasEsperados = [];
        for ($d = 1; $d <= $ultimoDia; $d++) {
            $diasEsperados[] = sprintf('%04d-%02d-%02d', $anio, $mes, $d);
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

        // KPIs: promedio diario sobre días con datos, proyección y valorización
        $diasConDatos = count(array_unique(array_merge(...array_values($fechas ?: [[]]))));
        $diasDelMes   = (int)date('t', mktime(0, 0, 0, $mes, 1, $anio));
        $promedio     = $diasConDatos > 0 ? $totales['total'] / $diasConDatos : 0;
        $kpis = [
            'dias_con_datos' => $diasConDatos,
            'promedio'       => $promedio,
            'proyeccion'     => $promedio * $diasDelMes,
            'valorizacion'   => $promedio * $diasDelMes * $precio,
        ];

        echo $this->twig->render($this->route . 'analisis.html',
            compact('anio', 'mes', 'filas', 'totales', 'kpis', 'precio'));
    }

    /** Detalle día × turno de una estación (equivalente a la hoja del Excel). */
    public function detalle($codgas = 0): void
    {
        if (!authorized(self::PERM_VER)) {
            (new Errors())->get404();
            return;
        }
        $codgas = (int)$codgas;
        $anio   = (int)($_GET['anio'] ?? date('Y'));
        $mes    = (int)($_GET['mes'] ?? date('n'));

        $estacion = null;
        foreach ($this->mermaModel->get_estaciones() as $e) {
            if ((int)$e['Codigo'] === $codgas) { $estacion = $e; break; }
        }
        if (!$estacion) {
            (new Errors())->get404();
            return;
        }

        $rows = $this->mermaModel->get_detalle_mensual($codgas, $anio, $mes);

        // Acumulado de diferencia por familia (como las columnas I/P del Excel)
        $acum  = ['maxima' => 0.0, 'super' => 0.0, 'diesel' => 0.0];
        $filas = [];
        foreach ($rows as $r) {
            $fila = ['fecha' => substr($r['fecha'], 0, 10), 'turno' => (int)$r['turno']];
            foreach (array_keys(MermaDiariaModel::FAMILIAS) as $fam) {
                $dif = $r["dif_$fam"];
                if ($dif !== null) $acum[$fam] += (float)$dif;
                $fila[$fam] = [
                    'vr'      => $r["vr_$fam"],
                    'compras' => $r["compras_$fam"],
                    'cont'    => $r["cont_$fam"],
                    'fis'     => $r["fis_$fam"],
                    'dif'     => $dif,
                    'acum'    => $dif !== null ? $acum[$fam] : null,
                ];
            }
            $filas[] = $fila;
        }

        $resumenMes = $this->mermaModel->get_resumen_mensual($anio, $mes);
        $resumen    = $resumenMes[$codgas] ?? null;
        $invInicial = $this->mermaModel->get_inv_inicial_mes($codgas, $anio, $mes);

        echo $this->twig->render($this->route . 'detalle.html',
            compact('estacion', 'anio', 'mes', 'filas', 'resumen', 'invInicial'));
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
