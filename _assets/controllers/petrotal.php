<?php
class Petrotal {
    // Número de permiso CRE de Petrotal para reporte a la CNE (constante de negocio fija).
    private const NUMERO_PERMISO_PETROTAL = 'H/22730/COM/2019';

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
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) {
            json_output(['error' => 'Formato de fecha inválido']);
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
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) {
            json_output(['error' => 'Formato de fecha inválido']);
            return;
        }

        $reporte = $this->petrotalObligacionModel->construir_reporte($desde, $hasta);
        $json = PetrotalCneClient::armar_json(self::NUMERO_PERMISO_PETROTAL, $desde, $hasta, $reporte['ventas'], $reporte['compras']);

        $envioExistente = $this->petrotalObligacionModel->buscar_envio_periodo($desde, $hasta);
        $usuarioId = $_SESSION['tg_user']['id'] ?? 0;

        if ($envioExistente) {
            $envioId = $envioExistente['Id'];
        } else {
            $envioId = $this->petrotalObligacionModel->crear_envio($desde, $hasta, $usuarioId);
        }

        $nombreArchivo = "{$desde}_{$hasta}_{$envioId}.json";
        $rutaJson = ROOT . '_assets' . DS . 'uploads' . DS . 'petrotal' . DS . 'json' . DS . $nombreArchivo;
        if (file_put_contents($rutaJson, $json) === false) {
            json_output(['error' => 'No se pudo escribir el archivo JSON en el servidor.']);
            return;
        }

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
            self::NUMERO_PERMISO_PETROTAL
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
            $folioAcuseExtraido = substr(basename(parse_url($resultado['link_descarga'], PHP_URL_PATH)), 0, 50);
            if ($folioAcuseExtraido !== '') {
                $folioAcuse = $folioAcuseExtraido;
                $rutaAcuseIntentada = ROOT . '_assets' . DS . 'uploads' . DS . 'petrotal' . DS . 'acuse' . DS . "{$envioId}_{$folioAcuse}.pdf";
                $descargado = PetrotalCneClient::descargar_acuse($resultado['link_descarga'], $rutaAcuseIntentada);
                if ($descargado) {
                    $rutaAcuse = $rutaAcuseIntentada;
                }
            }
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

    public function configuracion_fiel() {
        if (!authorized(99)) {
            echo "No autorizado";
            return;
        }
        $configActual = $this->petrotalFielModel->obtener_config();
        echo $this->twig->render($this->route . 'configuracion_fiel.html', compact('configActual'));
    }

    public function guardar_fiel() {
        if (!authorized(99)) {
            setFlashMessage('error', 'No autorizado.');
            redirect('/petrotal/configuracion_fiel');
            return;
        }

        $password = $_POST['password'] ?? '';
        if (!$password) {
            setFlashMessage('error', 'Falta el password de la llave privada.');
            redirect('/petrotal/configuracion_fiel');
            return;
        }

        $uploadDir = ROOT . '_assets' . DS . 'uploads' . DS . 'petrotal' . DS . 'fiel' . DS;
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $cerOk = isset($_FILES['cerFile']) && $_FILES['cerFile']['error'] === UPLOAD_ERR_OK;
        $keyOk = isset($_FILES['keyFile']) && $_FILES['keyFile']['error'] === UPLOAD_ERR_OK;
        if (!$cerOk || !$keyOk) {
            setFlashMessage('error', 'Faltan los archivos .cer y/o .key.');
            redirect('/petrotal/configuracion_fiel');
            return;
        }

        $extCer = strtolower(pathinfo($_FILES['cerFile']['name'], PATHINFO_EXTENSION));
        $extKey = strtolower(pathinfo($_FILES['keyFile']['name'], PATHINFO_EXTENSION));
        if ($extCer !== 'cer' || $extKey !== 'key') {
            setFlashMessage('error', 'El archivo de certificado debe ser .cer y el de llave .key.');
            redirect('/petrotal/configuracion_fiel');
            return;
        }

        $rutaCer = $uploadDir . 'petrotal.cer';
        $rutaKey = $uploadDir . 'petrotal.key';

        if (!move_uploaded_file($_FILES['cerFile']['tmp_name'], $rutaCer) || !move_uploaded_file($_FILES['keyFile']['tmp_name'], $rutaKey)) {
            setFlashMessage('error', 'Error al guardar los archivos subidos.');
            redirect('/petrotal/configuracion_fiel');
            return;
        }

        $usuarioId = $_SESSION['tg_user']['id'] ?? 0;
        $guardado = $this->petrotalFielModel->guardar_config($rutaCer, $rutaKey, $password, $usuarioId);

        setFlashMessage($guardado ? 'success' : 'error', $guardado ? 'FIEL guardada correctamente.' : 'Error al guardar la configuración en base de datos.');
        redirect('/petrotal/configuracion_fiel');
    }

    public function historial() {
        if (!authorized(98)) {
            echo "No autorizado";
            return;
        }
        $envios = $this->petrotalObligacionModel->listar_envios();
        echo $this->twig->render($this->route . 'historial.html', compact('envios'));
    }

    public function descargar_json($id = null) {
        if (!authorized(98)) {
            echo "No autorizado";
            return;
        }
        $envio = $this->petrotalObligacionModel->obtener_envio((int) $id);
        if (!$envio || empty($envio['RutaJson']) || !file_exists($envio['RutaJson'])) {
            http_response_code(404);
            echo "Archivo no encontrado";
            return;
        }
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="' . basename($envio['RutaJson']) . '"');
        readfile($envio['RutaJson']);
    }

    public function descargar_acuse($id = null) {
        if (!authorized(98)) {
            echo "No autorizado";
            return;
        }
        $envio = $this->petrotalObligacionModel->obtener_envio((int) $id);
        if (!$envio || empty($envio['RutaAcuse']) || !file_exists($envio['RutaAcuse'])) {
            http_response_code(404);
            echo "Acuse no encontrado";
            return;
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . basename($envio['RutaAcuse']) . '"');
        readfile($envio['RutaAcuse']);
    }
}
