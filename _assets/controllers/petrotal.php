<?php
class Petrotal {
    public $twig;
    public $route;
    public PetrotalObligacionModel $petrotalObligacionModel;
    public PetrotalFielModel $petrotalFielModel;

    public function __construct($twig) {
        $this->twig = $twig;
        $this->route = 'views/petrotal/';
        $this->petrotalObligacionModel = new PetrotalObligacionModel();
        $this->petrotalFielModel = new PetrotalFielModel();
    }

    // Sugiere la última semana lunes-domingo ya cerrada (hoy - 7 días desde
    // el lunes más reciente) como punto de partida del selector — el
    // usuario puede cambiarla libremente en la pantalla.
    private function semana_sugerida(): array {
        $hoy = new DateTime();
        $diaSemana = (int) $hoy->format('N'); // 1=lunes .. 7=domingo
        $lunesActual = (clone $hoy)->modify('-' . ($diaSemana - 1) . ' days');
        $lunesSemanaAnterior = (clone $lunesActual)->modify('-7 days');
        $domingoSemanaAnterior = (clone $lunesSemanaAnterior)->modify('+6 days');
        return [$lunesSemanaAnterior->format('Y-m-d'), $domingoSemanaAnterior->format('Y-m-d')];
    }

    public function volumetricos() {
        if (!authorized(98)) {
            echo "No autorizado";
            return;
        }
        [$desdeSugerido, $hastaSugerido] = $this->semana_sugerida();
        echo $this->twig->render($this->route . 'volumetricos.html', compact('desdeSugerido', 'hastaSugerido'));
    }

    public function preview_json() {
        header('Content-Type: application/json');
        if (!authorized(98)) {
            json_output(['error' => 'No autorizado']);
            return;
        }

        $desde = $_REQUEST['desde'] ?? '';
        $hasta = $_REQUEST['hasta'] ?? '';
        if (!$desde || !$hasta) {
            json_output(['error' => 'Selecciona un periodo (desde/hasta)']);
            return;
        }

        $reporte = $this->petrotalObligacionModel->construir_reporte($desde, $hasta);
        $envioExistente = $this->petrotalObligacionModel->buscar_envio_periodo($desde, $hasta);

        json_output([
            'ventas' => $reporte['ventas'],
            'compras' => $reporte['compras'],
            'advertencias' => $reporte['advertencias'],
            'envio_existente' => $envioExistente,
        ]);
    }

    public function generar_json() {
        header('Content-Type: application/json');
        if (!authorized(98)) {
            json_output(['error' => 'No autorizado']);
            return;
        }

        $desde = $_POST['desde'] ?? '';
        $hasta = $_POST['hasta'] ?? '';
        if (!$desde || !$hasta) {
            json_output(['error' => 'Selecciona un periodo (desde/hasta)']);
            return;
        }

        $reporte = $this->petrotalObligacionModel->construir_reporte($desde, $hasta);
        $json = PetrotalCneClient::armar_json('H/22730/COM/2019', $desde, $hasta, $reporte['ventas'], $reporte['compras']);

        $envioExistente = $this->petrotalObligacionModel->buscar_envio_periodo($desde, $hasta);
        $usuarioId = $_SESSION['tg_user']['id'] ?? 0;

        if ($envioExistente) {
            $envioId = $envioExistente['Id'];
        } else {
            $envioId = $this->petrotalObligacionModel->crear_envio($desde, $hasta, $usuarioId);
        }

        $nombreArchivo = "{$desde}_{$hasta}_{$envioId}.json";
        $rutaJson = ROOT . '_assets' . DS . 'uploads' . DS . 'petrotal' . DS . 'json' . DS . $nombreArchivo;
        file_put_contents($rutaJson, $json);

        $this->petrotalObligacionModel->actualizar_envio($envioId, [
            'Estado' => 'borrador',
            'RutaJson' => $rutaJson,
        ]);

        json_output(['json' => $json, 'envio_id' => $envioId]);
    }

    public function enviar_reporte() {
        header('Content-Type: application/json');
        if (!authorized(98)) {
            json_output(['error' => 'No autorizado']);
            return;
        }

        $envioId = (int) ($_POST['envio_id'] ?? 0);
        if (!$envioId) {
            json_output(['ok' => false, 'mensaje' => 'Falta envio_id']);
            return;
        }

        $envio = $this->petrotalObligacionModel->obtener_envio($envioId);
        if (!$envio || empty($envio['RutaJson'])) {
            json_output(['ok' => false, 'mensaje' => 'No se encontró el JSON generado para este envío. Genera el JSON primero.']);
            return;
        }

        $fielConfig = $this->petrotalFielModel->obtener_config();
        if (!$fielConfig) {
            json_output(['ok' => false, 'mensaje' => 'No hay una FIEL configurada. Ve a Configuración FIEL primero.']);
            return;
        }

        $resultado = PetrotalCneClient::enviar(
            $envio['RutaJson'],
            $fielConfig['ruta_cer'],
            $fielConfig['ruta_key'],
            $fielConfig['password'],
            'H/22730/COM/2019'
        );

        if ($resultado['error_conexion']) {
            $this->petrotalObligacionModel->actualizar_envio($envioId, [
                'Estado' => 'error',
                'RespuestaRaw' => json_encode($resultado),
                'FechaEnvio' => date('Y-m-d H:i:s'),
            ]);
            json_output(['ok' => false, 'mensaje' => $resultado['error_conexion']]);
            return;
        }

        if (!$resultado['ok']) {
            $this->petrotalObligacionModel->actualizar_envio($envioId, [
                'Estado' => 'error',
                'RespuestaRaw' => json_encode($resultado),
                'FechaEnvio' => date('Y-m-d H:i:s'),
            ]);
            json_output(['ok' => false, 'mensaje' => $resultado['msg']]);
            return;
        }

        $folioAcuse = null;
        $rutaAcuse = null;
        if (!empty($resultado['link_descarga'])) {
            $folioAcuse = basename(parse_url($resultado['link_descarga'], PHP_URL_PATH));
            $rutaAcuse = ROOT . '_assets' . DS . 'uploads' . DS . 'petrotal' . DS . 'acuse' . DS . "{$envioId}_{$folioAcuse}.pdf";
            PetrotalCneClient::descargar_acuse($resultado['link_descarga'], $rutaAcuse);
        }

        $this->petrotalObligacionModel->actualizar_envio($envioId, [
            'Estado' => $rutaAcuse ? 'acuse_recibido' : 'enviado',
            'RespuestaRaw' => json_encode($resultado),
            'FolioAcuse' => $folioAcuse,
            'RutaAcuse' => $rutaAcuse,
            'FechaEnvio' => date('Y-m-d H:i:s'),
        ]);

        json_output(['ok' => true, 'folio_acuse' => $folioAcuse]);
    }
}
