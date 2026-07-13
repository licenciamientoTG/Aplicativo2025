<?php

/**
 * Análisis de Merma Diaria (Abastos).
 *
 * Snapshot diario de inventarios por turno de todas las estaciones
 * (TG.dbo.merma_diaria) llenado vía ApiER en paralelo; vistas de resumen
 * mensual y detalle por estación; captura manual de merma s/d y comentarios.
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
            } catch (Exception $e) {
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
        json_output($this->runSync($desde, $hasta, 0, 'cron', null));
    }
}
