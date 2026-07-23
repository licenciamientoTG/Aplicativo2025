<?php

/**
 * Tesorería.
 *
 * Sección Movimientos bancos: tabla consultable de los movimientos
 * bancarios diarios (TG.dbo.movimientos_bancarios) e importación del TXT
 * de movimientos de Enlace Santander (ancho fijo, 630 chars/línea).
 *
 * Rutas: /tesoreria/[metodo]  (autocargado por index.php)
 * Spec:  docs/superpowers/specs/2026-07-22-tesoreria-movimientos-bancos-design.md
 * Schema: docs/sql/tesoreria_schema.sql
 */
class Tesoreria
{
    private const PERM_VER      = 79;   // Ver módulo de Tesorería
    private const MAX_TXT_BYTES = 10 * 1024 * 1024;

    private $twig;
    private $route;
    private $movsModel;

    public function __construct($twig)
    {
        $this->twig      = $twig;
        $this->route     = 'views/tesoreria/';
        $this->movsModel = new MovimientosBancariosModel();
    }

    /** Vista principal: filtros + tabla de movimientos + botón de upload. */
    public function movimientos_bancos(): void
    {
        if (!authorized(self::PERM_VER)) {
            (new Errors())->get404();
            return;
        }
        $hoy   = date('Y-m-d');
        $fmt   = '/^\d{4}-\d{2}-\d{2}$/';
        $desde = $_GET['desde'] ?? '';
        $hasta = $_GET['hasta'] ?? '';
        if (!preg_match($fmt, $desde)) $desde = date('Y-m-d', strtotime('-7 days'));
        if (!preg_match($fmt, $hasta)) $hasta = $hoy;
        if ($desde > $hasta) $desde = $hasta;

        $filtros = [
            'desde'  => $desde,
            'hasta'  => $hasta,
            'cuenta' => trim($_GET['cuenta'] ?? ''),
            'tipo'   => in_array($_GET['tipo'] ?? '', ['cargo', 'abono']) ? $_GET['tipo'] : '',
            'q'      => trim($_GET['q'] ?? ''),
        ];

        $movimientos = $this->movsModel->get_movimientos($filtros);
        $totales = ['abonos' => 0.0, 'cargos' => 0.0, 'movs' => count($movimientos)];
        foreach ($movimientos as $m) {
            $totales['abonos'] += (float)($m['abono'] ?? 0);
            $totales['cargos'] += (float)($m['cargo'] ?? 0);
        }
        $totales['neto'] = $totales['abonos'] - $totales['cargos'];

        echo $this->twig->render($this->route . 'movimientos_bancos.html', [
            'filtros'     => $filtros,
            'cuentas'     => $this->movsModel->get_cuentas(),
            'movimientos' => $movimientos,
            'totales'     => $totales,
        ]);
    }

    /**
     * POST AJAX: recibe el TXT de Santander ($_FILES['txt_santander']),
     * lo parsea e inserta saltando duplicados. Responde JSON.
     */
    public function upload_santander(): void
    {
        if (!authorized(self::PERM_VER)) {
            json_output(['success' => false, 'message' => 'No tienes permiso para importar movimientos']);
            return;
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['txt_santander']['tmp_name'])) {
            json_output(['success' => false, 'message' => 'No se recibió ningún archivo']);
            return;
        }

        $file = $_FILES['txt_santander'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            json_output(['success' => false, 'message' => 'Error al subir el archivo (código ' . $file['error'] . ')']);
            return;
        }
        if ($file['size'] > self::MAX_TXT_BYTES) {
            json_output(['success' => false, 'message' => 'El archivo excede el tamaño máximo (10 MB)']);
            return;
        }
        if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'txt') {
            json_output(['success' => false, 'message' => 'El archivo debe ser un .TXT de Santander']);
            return;
        }

        $contenido = file_get_contents($file['tmp_name']);
        if ($contenido === false || trim($contenido) === '') {
            json_output(['success' => false, 'message' => 'El archivo está vacío o no se pudo leer']);
            return;
        }

        $parseo = MovimientosBancariosModel::parse_santander_txt($contenido);
        if (empty($parseo['movimientos'])) {
            json_output([
                'success' => false,
                'message' => 'El archivo no contiene movimientos con el layout de Santander',
                'errores' => array_slice($parseo['errores'], 0, 20),
            ]);
            return;
        }

        try {
            $res = $this->movsModel->insert_bulk(
                $parseo['movimientos'],
                mb_substr($file['name'], 0, 120),
                (int)($_SESSION['tg_user']['Id'] ?? 0) ?: null
            );
        } catch (Exception $e) {
            error_log('upload_santander: ' . $e->getMessage());
            json_output(['success' => false, 'message' => 'Error al guardar en base de datos, no se importó nada']);
            return;
        }

        $fechas = array_column($parseo['movimientos'], 'fecha');
        json_output([
            'success'    => true,
            'insertados' => $res['insertados'],
            'duplicados' => $res['duplicados'],
            'errores'    => array_slice($parseo['errores'], 0, 20),
            'fecha_min'  => min($fechas),
            'fecha_max'  => max($fechas),
        ]);
    }
}
