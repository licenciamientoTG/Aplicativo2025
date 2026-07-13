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
            return ['success' => false, 'message' => "No se pudo contactar ApiER: $curlErr"];
        }
        $api = json_decode($response, true);
        if (!isset($api['resultados'])) {
            $detail = $api['detail'] ?? substr((string)$response, 0, 200);
            return ['success' => false, 'message' => "Respuesta inesperada de ApiER: $detail"];
        }

        $filasTotal = 0;
        foreach ($api['resultados'] as $est) {
            $filasTotal += $this->mermaModel->replace_station_range(
                (int)$est['Codigo'], $est['Nombre'], $desde, $hasta, $est['filas']
            );
        }

        $errores  = $api['errores'] ?? [];
        $duracion = round(microtime(true) - $inicio, 1);
        $detalle  = $errores
            ? implode('; ', array_map(fn($e) => $e['Nombre'] . ': ' . substr($e['error'], 0, 150), $errores))
            : '';

        $this->mermaModel->add_sync_log(
            $origen, $usuario, $desde, $hasta, $codgas,
            count($api['resultados']), count($errores), $detalle, $duracion
        );

        return [
            'success'          => true,
            'message'          => count($api['resultados']) . ' estaciones sincronizadas'
                                  . ($errores ? ', ' . count($errores) . ' sin conexión' : ''),
            'estaciones_ok'    => count($api['resultados']),
            'estaciones_error' => count($errores),
            'errores'          => array_map(fn($e) => $e['Nombre'], $errores),
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
