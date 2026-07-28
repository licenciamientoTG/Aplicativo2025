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

    /** Grupo de saldos para cuentas que no están en CatalogosCuentasBancarias. */
    private const GRUPO_SIN_CATALOGO = 'SIN CATÁLOGO';

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

    /**
     * Vista principal: filtros + tablas vacías + botón de upload.
     *
     * NO consulta movimientos. Los datos los pide el JS a movimientos_table()
     * cuando el usuario presiona "Buscar", mismo patrón que /income/clients:
     * entrar al módulo no debe disparar una consulta de miles de filas que
     * nadie pidió, y buscar de nuevo no debe recargar la página (se perdían el
     * panel de saldos abierto, el tab activo y el scroll).
     */
    public function movimientos_bancos(): void
    {
        if (!authorized(self::PERM_VER)) {
            (new Errors())->get404();
            return;
        }

        echo $this->twig->render($this->route . 'movimientos_bancos.html', [
            'filtros' => $this->filtros_movimientos($_GET),
            'cuentas' => $this->movsModel->get_cuentas(),
        ]);
    }

    /**
     * POST AJAX: movimientos del rango, ya partidos por banco, más los KPIs
     * de la cabecera. Una sola consulta alimenta las dos tablas y las tarjetas.
     */
    public function movimientos_table(): void
    {
        header('Content-Type: application/json');
        if (!authorized(self::PERM_VER)) {
            json_output(['success' => false, 'message' => 'Sin permiso']);
            return;
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            json_output(['success' => false, 'message' => 'Método no permitido']);
            return;
        }

        $filtros = $this->filtros_movimientos($_POST);

        try {
            $movimientos = $this->movsModel->get_movimientos($filtros);
        } catch (Exception $e) {
            error_log('movimientos_table: ' . $e->getMessage());
            json_output(['success' => false, 'message' => 'Error al consultar los movimientos']);
            return;
        }

        // Un tab (tabla independiente) por banco: el export a Excel de cada
        // uno alimenta el Drive de tesorería, por eso no se mezclan.
        // porBanco alimenta el desglose de los KPI cards.
        $santander = [];
        $afirme    = [];
        $totales   = ['abonos' => 0.0, 'cargos' => 0.0, 'movs' => count($movimientos)];
        $porBanco  = [
            'SANTANDER' => ['abonos' => 0.0, 'cargos' => 0.0, 'movs' => 0],
            'AFIRME'    => ['abonos' => 0.0, 'cargos' => 0.0, 'movs' => 0],
        ];
        foreach ($movimientos as $m) {
            $totales['abonos'] += (float)($m['abono'] ?? 0);
            $totales['cargos'] += (float)($m['cargo'] ?? 0);
            $b = $m['banco'] === 'AFIRME' ? 'AFIRME' : 'SANTANDER';
            $porBanco[$b]['abonos'] += (float)($m['abono'] ?? 0);
            $porBanco[$b]['cargos'] += (float)($m['cargo'] ?? 0);
            $porBanco[$b]['movs']++;
            if ($b === 'AFIRME') $afirme[]    = $m;
            else                 $santander[] = $m;
        }
        $totales['neto'] = $totales['abonos'] - $totales['cargos'];
        foreach ($porBanco as $b => $t) {
            $porBanco[$b]['neto'] = $t['abonos'] - $t['cargos'];
        }

        json_output([
            'success'   => true,
            'filtros'   => $filtros,
            'santander' => $santander,
            'afirme'    => $afirme,
            'kpis'      => [
                'totales'  => $totales,
                'porBanco' => $porBanco,
                'saldo'    => $this->kpi_saldo($filtros),
            ],
        ]);
    }

    /**
     * Normaliza y valida los filtros de movimientos, vengan de $_GET (la vista)
     * o de $_POST (el ajax de la tabla), para que ambos apliquen las mismas
     * reglas: fechas con formato válido, rango por defecto de 7 días y tipo
     * restringido a cargo/abono.
     */
    private function filtros_movimientos(array $src): array
    {
        $fmt   = '/^\d{4}-\d{2}-\d{2}$/';
        $desde = $src['desde'] ?? '';
        $hasta = $src['hasta'] ?? '';
        if (!preg_match($fmt, $desde)) $desde = date('Y-m-d', strtotime('-7 days'));
        if (!preg_match($fmt, $hasta)) $hasta = date('Y-m-d');
        if ($desde > $hasta) $desde = $hasta;

        return [
            'desde'  => $desde,
            'hasta'  => $hasta,
            'cuenta' => trim($src['cuenta'] ?? ''),
            'tipo'   => in_array($src['tipo'] ?? '', ['cargo', 'abono']) ? $src['tipo'] : '',
            'q'      => trim($src['q'] ?? ''),
        ];
    }

    /**
     * KPI de saldo al corte de "hasta": el de la cuenta filtrada, o la suma
     * del último saldo de cada cuenta si se ven todas. saldoBanco es la
     * posición global por banco, independiente del filtro de cuenta.
     */
    private function kpi_saldo(array $filtros): array
    {
        $final      = null;
        $finalFecha = null;
        $porBanco   = ['SANTANDER' => 0.0, 'AFIRME' => 0.0];
        $saldos     = $this->movsModel->get_saldos_finales($filtros['hasta']);

        if ($filtros['cuenta'] !== '') {
            foreach ($saldos as $s) {
                if ($s['cuenta'] === $filtros['cuenta']) {
                    $final      = (float)$s['saldo'];
                    $finalFecha = substr((string)$s['fecha'], 0, 10);
                    break;
                }
            }
        } elseif (!empty($saldos)) {
            $final = 0.0;
            foreach ($saldos as $s) $final += (float)$s['saldo'];
        }

        foreach ($saldos as $s) {
            $b = $s['banco'] === 'AFIRME' ? 'AFIRME' : 'SANTANDER';
            $porBanco[$b] += (float)$s['saldo'];
        }

        return ['final' => $final, 'fecha' => $finalFecha, 'porBanco' => $porBanco];
    }

    /**
     * GET AJAX: último saldo de cada cuenta al corte de ?hasta, agrupado por
     * empresa (Descripcion del catálogo de cuentas), para el panel colapsable
     * de saldos finales. Tesorería razona por empresa, no por cuenta suelta.
     *
     * Los subtotales se separan por moneda: 5 de las cuentas son en dólares y
     * sumarlas con las de pesos da un número que no existe. Se agrupa aquí y
     * no en la vista para que el JS solo pinte.
     *
     * Spec: docs/superpowers/specs/2026-07-28-tesoreria-saldos-por-empresa-design.md
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

        json_output([
            'success' => true,
            'hasta'   => $hasta,
            'grupos'  => $this->agrupar_saldos_por_empresa($saldos),
            'totales' => $this->totalizar_por_moneda($saldos),
            // contrato plano anterior, por si otro consumidor lo usa
            'saldos'  => $saldos,
            'total'   => $total,
        ]);
    }

    /**
     * Agrupa los saldos finales por empresa (Descripcion del catálogo).
     * Las cuentas sin match caen en SIN CATÁLOGO, que siempre va al final:
     * son cuentas por dar de alta y conviene que se vean.
     * Los grupos se ordenan por subtotal en pesos descendente.
     */
    private function agrupar_saldos_por_empresa(array $saldos): array
    {
        $grupos = [];
        foreach ($saldos as $s) {
            $desc = trim((string)($s['descripcion'] ?? ''));
            $key  = $desc !== '' ? $desc : self::GRUPO_SIN_CATALOGO;

            if (!isset($grupos[$key])) {
                $grupos[$key] = [
                    'descripcion'  => $key,
                    'sin_catalogo' => $desc === '',
                    'totales'      => [],
                    'cuentas'      => [],
                ];
            }

            $moneda = self::moneda($s['divisa'] ?? null);
            $saldo  = (float)$s['saldo'];

            $grupos[$key]['cuentas'][] = [
                'cuenta' => trim((string)$s['cuenta']),
                'banco'  => $s['banco'],
                'fecha'  => substr((string)$s['fecha'], 0, 10),
                'saldo'  => $saldo,
                'moneda' => $moneda,
                'tipo'   => $s['tipo'] ?? null,
            ];
            $grupos[$key]['totales'][$moneda]['n']     = ($grupos[$key]['totales'][$moneda]['n'] ?? 0) + 1;
            $grupos[$key]['totales'][$moneda]['saldo'] = ($grupos[$key]['totales'][$moneda]['saldo'] ?? 0.0) + $saldo;
        }

        foreach ($grupos as &$g) {
            usort($g['cuentas'], fn($a, $b) => $b['saldo'] <=> $a['saldo']);
        }
        unset($g);

        // SIN CATÁLOGO al final sin importar su saldo; el resto por MXN desc
        uasort($grupos, function ($a, $b) {
            if ($a['sin_catalogo'] !== $b['sin_catalogo']) return $a['sin_catalogo'] ? 1 : -1;
            return ($b['totales']['MXN']['saldo'] ?? 0) <=> ($a['totales']['MXN']['saldo'] ?? 0);
        });

        return array_values($grupos);
    }

    /** Totales generales separados por moneda (no se suman divisas distintas). */
    private function totalizar_por_moneda(array $saldos): array
    {
        $totales = [];
        foreach ($saldos as $s) {
            $m = self::moneda($s['divisa'] ?? null);
            $totales[$m]['n']     = ($totales[$m]['n'] ?? 0) + 1;
            $totales[$m]['saldo'] = ($totales[$m]['saldo'] ?? 0.0) + (float)$s['saldo'];
        }
        return $totales;
    }

    /**
     * Moneda de una cuenta a partir de la Divisa del catálogo. Solo
     * "DOLAR AMERICANO" es USD; el resto (incluidos NULL y el 'peso' suelto
     * que hay en un registro del catálogo) se trata como MXN.
     */
    private static function moneda($divisa): string
    {
        return stripos((string)$divisa, 'DOLAR') !== false ? 'USD' : 'MXN';
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

    /**
     * POST AJAX: recibe el export de movimientos de Afirme (un ".xls" que en
     * realidad es texto separado por tabs) y lo importa saltando duplicados.
     * Responde JSON con el mismo contrato que upload_santander, más "avisos"
     * si alguna cuenta del archivo no está en el catálogo de cuentas.
     */
    public function upload_afirme(): void
    {
        if (!authorized(self::PERM_VER)) {
            json_output(['success' => false, 'message' => 'No tienes permiso para importar movimientos']);
            return;
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['archivo_afirme']['tmp_name'])) {
            json_output(['success' => false, 'message' => 'No se recibió ningún archivo']);
            return;
        }

        $file = $_FILES['archivo_afirme'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            json_output(['success' => false, 'message' => 'Error al subir el archivo (código ' . $file['error'] . ')']);
            return;
        }
        if ($file['size'] > self::MAX_TXT_BYTES) {
            json_output(['success' => false, 'message' => 'El archivo excede el tamaño máximo (10 MB)']);
            return;
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['xls', 'txt', 'tsv', 'csv'])) {
            json_output(['success' => false, 'message' => 'El archivo debe ser el export de Afirme (.xls/.txt/.tsv)']);
            return;
        }

        $contenido = file_get_contents($file['tmp_name']);
        if ($contenido === false || trim($contenido) === '') {
            json_output(['success' => false, 'message' => 'El archivo está vacío o no se pudo leer']);
            return;
        }

        $parseo = MovimientosBancariosModel::parse_afirme_tsv($contenido);
        if (empty($parseo['movimientos'])) {
            json_output([
                'success' => false,
                'message' => 'El archivo no contiene movimientos con el formato de Afirme',
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
            error_log('upload_afirme: ' . $e->getMessage());
            json_output(['success' => false, 'message' => 'Error al guardar en base de datos, no se importó nada']);
            return;
        }

        // Aviso (no bloqueante) si alguna cuenta del archivo no está dada de
        // alta en el catálogo de cuentas bancarias
        $avisos    = [];
        $catalogo  = array_column($this->cuentasModel->get_cuentas_admin(), 'CuentaLocal');
        foreach (array_unique(array_column($parseo['movimientos'], 'cuenta')) as $cta) {
            if (!in_array($cta, $catalogo)) {
                $avisos[] = "La cuenta $cta no está registrada en el catálogo de cuentas bancarias";
            }
        }

        $fechas = array_column($parseo['movimientos'], 'fecha');
        json_output([
            'success'    => true,
            'insertados' => $res['insertados'],
            'duplicados' => $res['duplicados'],
            'errores'    => array_slice($parseo['errores'], 0, 20),
            'avisos'     => $avisos,
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
