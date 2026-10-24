<?php
class Home{
    public $twig;
    public $route;
    public DespachosModel $despachosModel;
    public int $todayInt;

    // PRUEBA temporal (solo usuario monitor): descarga de archivos depositados en el NAS por
    // CorreoFacturasNas.py. Rutas fijas a propósito; no se lee nada de la BD.
    private const NAS_PRUEBA_SHARE = '\\\\192.168.0.15\\FacturasProveedores';
    private const NAS_PRUEBA_DIR = '\\\\192.168.0.15\\FacturasProveedores\\prueba\\premiergas';
    private const NAS_PRUEBA_ARCHIVOS = [
        '001-FE-00044042 AHUMADA MAGNA BOL 1258033.pdf',
        '001-FE-00044042 AHUMADA MAGNA BOL 1258033.xml',
        '001-FE-00044067 JARUDO MAGNA BOL 1258132.pdf',
        '001-FE-00044067 JARUDO MAGNA BOL 1258132.xml',
        '001-FE-00044068 GASO MEX MAGNA FUENTES BOL 1258135.pdf',
        '001-FE-00044068 GASO MEX MAGNA FUENTES BOL 1258135.xml',
        '001-FE-00044073 GASO MEX MAGNA EJERCITO BOL 1258166.pdf',
        '001-FE-00044073 GASO MEX MAGNA EJERCITO BOL 1258166.xml',
        '001-FE-00044074 GASO MEX MAGNA SANTIAGO BOL 1258167.pdf',
        '001-FE-00044074 GASO MEX MAGNA SANTIAGO BOL 1258167.xml',
        '002-FF-00001513 GASO MEX DIESEL FUENTES BOL 600786.pdf',
        '002-FF-00001513 GASO MEX DIESEL FUENTES BOL 600786.xml',
        '002-FF-00001514 GASO MEX DIESEL SATELITE BOL 600783.pdf',
        '002-FF-00001514 GASO MEX DIESEL SATELITE BOL 600783.xml',
    ];

    public function __construct($twig) {
        $this->despachosModel = new DespachosModel;
        $this->twig         = $twig;
        $this->todayInt     = (new DateTime())->diff(new DateTime('1900-01-01'))->days + 1;
        $this->route        = 'views/home/';
    }

    public function index() : void {
        $today_sales = $this->despachosModel->get_today_sales($this->todayInt);
        // Monitoreo de importaciones de facturas: solo para los usuarios de Payment::MONITOR_IMPORTACION_USER_IDS.
        $monitor = $this->esUsuarioMonitor();
        $nas_prueba = $monitor ? self::NAS_PRUEBA_ARCHIVOS : [];
        echo $this->twig->render($this->route . 'index.html', compact('today_sales', 'monitor', 'nas_prueba'));
    }

    private function esUsuarioMonitor(): bool {
        return in_array((int)($_SESSION['tg_user']['Id'] ?? 0), Payment::MONITOR_IMPORTACION_USER_IDS, true);
    }

    /** Si el pool de IIS no ve el share, intenta una conexión temporal con credenciales de entorno (nunca en el código). */
    private function conectarNasPrueba(): void {
        $usuario = (string)getenv('NAS_FACTURAS_USER');
        $clave = (string)getenv('NAS_FACTURAS_PASSWORD');
        if ($usuario === '' || $clave === '' || !function_exists('exec')) return;
        @exec('net use ' . escapeshellarg(self::NAS_PRUEBA_SHARE) . ' /user:' . escapeshellarg($usuario) . ' ' . escapeshellarg($clave) . ' /persistent:no 2>&1');
    }

    /** Descarga de prueba de un archivo del NAS por índice (la lista es fija, el índice no permite recorrer rutas). */
    public function nas_prueba($n): void {
        if (!$this->esUsuarioMonitor()) {
            http_response_code(403);
            echo 'Sin acceso';
            return;
        }
        $i = (int)$n;
        if ($i < 0 || $i >= count(self::NAS_PRUEBA_ARCHIVOS)) {
            http_response_code(404);
            echo 'Archivo no encontrado';
            return;
        }
        $nombre = self::NAS_PRUEBA_ARCHIVOS[$i];
        $ruta = self::NAS_PRUEBA_DIR . '\\' . $nombre;
        if (!@is_file($ruta)) $this->conectarNasPrueba();
        if (!@is_file($ruta) || !@is_readable($ruta)) {
            http_response_code(502);
            header('Content-Type: text/plain; charset=utf-8');
            $quien = function_exists('exec') ? trim((string)@exec('whoami')) : get_current_user();
            echo "No se pudo leer el archivo del NAS desde IIS.\n";
            echo "Usuario del proceso web: " . ($quien !== '' ? $quien : 'desconocido') . "\n";
            echo "Ruta: " . $ruta . "\n";
            echo ((string)getenv('NAS_FACTURAS_USER') === '')
                ? "Faltan las variables NAS_FACTURAS_USER / NAS_FACTURAS_PASSWORD en el proceso PHP del sitio, o la cuenta del pool no tiene permiso sobre el share.\n"
                : "Las credenciales de entorno existen pero no dieron acceso (revisa usuario/clave o permisos del share).\n";
            return;
        }
        $ext = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
        $tipo = $ext === 'pdf' ? 'application/pdf' : 'application/xml';
        $ascii = preg_replace('/[^A-Za-z0-9._ -]/', '_', $nombre);
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        header('Content-Type: ' . $tipo);
        header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($nombre));
        header('Content-Length: ' . filesize($ruta));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        readfile($ruta);
        exit;
    }
}
