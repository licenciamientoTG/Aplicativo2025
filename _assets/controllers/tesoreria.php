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
    private $cuentasModel;
    private $proveedores;

    public function __construct($twig)
    {
        $this->twig         = $twig;
        $this->route        = 'views/tesoreria/';
        $this->movsModel    = new MovimientosBancariosModel();
        $this->cuentasModel = new CuentasBancariasModel();
        $this->proveedores  = new ProveedoresModel();
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

        // Saldo final al corte de "hasta": el de la cuenta filtrada, o la
        // suma del último saldo de cada cuenta si se ven todas
        $saldoFinal      = null;
        $saldoFinalFecha = null;
        $saldosFinales   = $this->movsModel->get_saldos_finales($hasta);
        if ($filtros['cuenta'] !== '') {
            foreach ($saldosFinales as $s) {
                if ($s['cuenta'] === $filtros['cuenta']) {
                    $saldoFinal      = (float)$s['saldo'];
                    $saldoFinalFecha = $s['fecha'];
                    break;
                }
            }
        } elseif (!empty($saldosFinales)) {
            $saldoFinal = 0.0;
            foreach ($saldosFinales as $s) $saldoFinal += (float)$s['saldo'];
        }

        echo $this->twig->render($this->route . 'movimientos_bancos.html', [
            'filtros'         => $filtros,
            'cuentas'         => $this->movsModel->get_cuentas(),
            'movimientos'     => $movimientos,
            'totales'         => $totales,
            'saldoFinal'      => $saldoFinal,
            'saldoFinalFecha' => $saldoFinalFecha,
        ]);
    }

    /**
     * GET AJAX: último saldo de cada cuenta al corte de ?hasta
     * (para el panel colapsable de saldos finales).
     */
    public function saldos_finales()
    {
        if (!authorized(self::PERM_VER)) {
            json_output(['success' => false, 'message' => 'Sin permiso']);
            return;
        }
        $hasta = $_GET['hasta'] ?? '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) $hasta = date('Y-m-d');

        $saldos = $this->movsModel->get_saldos_finales($hasta);
        $total  = 0.0;
        foreach ($saldos as $s) $total += (float)$s['saldo'];

        json_output(['success' => true, 'hasta' => $hasta, 'saldos' => $saldos, 'total' => $total]);
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

    /* ===================================================================== */
    /* Catálogo de cuentas bancarias (migrado de payment.php el 2026-07-23)  */
    /* ===================================================================== */

    /** Vista de administración del catálogo de cuentas bancarias. */
    public function bank_accounts()
    {
        if (!authorized(self::PERM_VER)) {
            (new Errors())->get404();
            return;
        }
        $proveedores = $this->proveedores->get_actives() ?: [];
        echo $this->twig->render($this->route . 'bank_accounts.html', compact('proveedores'));
    }

    /** JSON con todas las cuentas bancarias para el DataTable de administración. */
    public function bank_accounts_table()
    {
        header('Content-Type: application/json');
        if (!authorized(self::PERM_VER)) {
            json_output(['data' => [], 'error' => 'Sin permiso']);
            return;
        }
        $cuentas = $this->cuentasModel->get_cuentas_admin();
        json_output(['data' => $cuentas]);
    }

    /** Alta de cuenta bancaria desde el modal (id vacío en el form). */
    public function create_bank_account()
    {
        header('Content-Type: application/json');
        if (!authorized(self::PERM_VER)) {
            json_output(['success' => false, 'message' => 'Sin permiso']);
            return;
        }

        $cuenta_local = trim($_POST['CuentaLocal'] ?? '');
        if ($cuenta_local === '') {
            json_output(['success' => false, 'message' => 'La cuenta (CLABE/Cuenta) es obligatoria']);
            return;
        }

        $data = [
            'CuentaLocal'   => $cuenta_local,
            'Descripcion'   => trim($_POST['Descripcion'] ?? ''),
            'Banco'         => trim($_POST['Banco'] ?? ''),
            'TitularCuenta' => trim($_POST['TitularCuenta'] ?? ''),
            'Tipo'          => trim($_POST['Tipo'] ?? ''),
            'Divisa'        => trim($_POST['Divisa'] ?? ''),
            'emp_cod'       => trim($_POST['emp_cod'] ?? ''),
            'proveedor_cod' => trim($_POST['proveedor_cod'] ?? ''),
            'Activo'        => intval($_POST['Activo'] ?? 1),
        ];

        $user_id = $_SESSION['tg_user']['Id'] ?? 0;
        $res = $this->cuentasModel->create_admin($data, $user_id);

        if ($res === 2) {
            json_output(['success' => false, 'message' => 'Ya existe una cuenta con esa CLABE/Cuenta']);
            return;
        }
        json_output([
            'success' => $res === 1,
            'message' => $res === 1 ? 'Cuenta creada correctamente' : 'No se pudo crear la cuenta',
        ]);
    }

    /** Actualiza una cuenta bancaria desde el modal de edición. */
    public function update_bank_account()
    {
        header('Content-Type: application/json');
        if (!authorized(self::PERM_VER)) {
            json_output(['success' => false, 'message' => 'Sin permiso']);
            return;
        }

        $id = intval($_POST['id'] ?? 0);
        if (!$id) {
            json_output(['success' => false, 'message' => 'ID inválido']);
            return;
        }

        $cuenta = $this->cuentasModel->get_by_id($id);
        if (!$cuenta) {
            json_output(['success' => false, 'message' => 'La cuenta no existe']);
            return;
        }

        $cuenta_local = trim($_POST['CuentaLocal'] ?? '');
        if ($cuenta_local === '') {
            json_output(['success' => false, 'message' => 'La cuenta (CLABE/Cuenta) es obligatoria']);
            return;
        }

        $data = [
            'CuentaLocal'   => $cuenta_local,
            'Descripcion'   => trim($_POST['Descripcion'] ?? ''),
            'Banco'         => trim($_POST['Banco'] ?? ''),
            'TitularCuenta' => trim($_POST['TitularCuenta'] ?? ''),
            'Tipo'          => trim($_POST['Tipo'] ?? ''),
            'Divisa'        => trim($_POST['Divisa'] ?? ''),
            'emp_cod'       => trim($_POST['emp_cod'] ?? ''),
            'proveedor_cod' => trim($_POST['proveedor_cod'] ?? ''),
            'Activo'        => intval($_POST['Activo'] ?? 1),
        ];

        $user_id = $_SESSION['tg_user']['Id'] ?? 0;
        $ok = $this->cuentasModel->update_admin($id, $data, $user_id);

        json_output([
            'success' => (bool)$ok,
            'message' => $ok ? 'Cuenta actualizada correctamente' : 'No se pudo actualizar la cuenta',
        ]);
    }

    /** Activa o desactiva una cuenta bancaria. */
    public function toggle_bank_account()
    {
        header('Content-Type: application/json');
        if (!authorized(self::PERM_VER)) {
            json_output(['success' => false, 'message' => 'Sin permiso']);
            return;
        }

        $id     = intval($_POST['id'] ?? 0);
        $activo = intval($_POST['activo'] ?? 0) === 1 ? 1 : 0;
        if (!$id) {
            json_output(['success' => false, 'message' => 'ID inválido']);
            return;
        }

        $user_id = $_SESSION['tg_user']['Id'] ?? 0;
        $ok = $this->cuentasModel->set_activo($id, $activo, $user_id);

        json_output([
            'success' => (bool)$ok,
            'activo'  => $activo,
            'message' => $ok
                ? ($activo ? 'Cuenta activada' : 'Cuenta desactivada')
                : 'No se pudo cambiar el estado',
        ]);
    }
}
