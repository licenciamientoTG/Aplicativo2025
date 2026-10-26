<?php

/**
 * Alta de cuenta bancaria de proveedores (cuelga de Pagos extraordinarios).
 *
 * El 106 (el mismo de Pagos extraordinarios) abre la sección, permite solicitar y ver
 * las solicitudes propias; Tesorería (109) aprueba o
 * rechaza y, al aprobar, la cuenta pasa a TG.dbo.CatalogosCuentasBancarias.
 * Un número de cuenta repetido solo avisa, nunca bloquea.
 *
 * Rutas: /pagos-extraordinarios/alta-cuenta/...  (routes/bankaccountrequests.php)
 * Schema: docs/sql/alta_cuenta_bancaria_schema.sql
 */
class BankAccountRequests
{
    /** Permiso de la sección Pagos extraordinarios: entrar, solicitar y ver lo propio. */
    private const PERM_SOLICITAR = 106;

    /** Permiso de Tesorería (mismo que el pago de pagos extraordinarios). */
    private const PERM_TESORERIA = 109;

    private const LIM_CUENTA    = 50;
    private const LIM_PROVEEDOR = 200;
    private const LIM_COMENTARIO = 400;


    private const ESTATUS = [
        BankAccountRequestsModel::ST_PENDIENTE => 'Pendiente de Tesorería',
        BankAccountRequestsModel::ST_APROBADA  => 'Aprobada',
        BankAccountRequestsModel::ST_RECHAZADA => 'Rechazada',
        BankAccountRequestsModel::ST_BORRADOR  => 'Borrador (falta carátula)',
    ];

    public $twig;
    public $route;
    public BankAccountRequestsModel $model;
    public ExtraPaymentCatalogModel $catalogModel;

    public function __construct($twig)
    {
        // Sin el 106 no se entra a ninguna ruta de la sección (ni siquiera con el 109).
        if (!authorized(self::PERM_SOLICITAR)) {
            $this->fail(403, 'No tiene permiso para acceder a este módulo.', $this->es_ajax() || $_SERVER['REQUEST_METHOD'] === 'POST');
        }
        $this->twig         = $twig;
        $this->route        = 'views/bankaccountrequests/';
        $this->model        = new BankAccountRequestsModel();
        $this->catalogModel = new ExtraPaymentCatalogModel();
    }

    /* ===================================================================== */
    /* Helpers                                                               */
    /* ===================================================================== */

    private function es_ajax(): bool
    {
        return (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');
    }

    private function user_id(): int
    {
        return (int) ($_SESSION['tg_user']['Id'] ?? 0);
    }

    private function es_tesoreria(): bool
    {
        return authorized(self::PERM_TESORERIA);
    }

    private function es_propia(array $req): bool
    {
        return (int) $req['user_id'] === $this->user_id();
    }

    /** Un borrador (sin carátula) es privado de su dueño, incluso para Tesorería. */
    private function puede_ver(array $req): bool
    {
        if ((int) $req['status'] === BankAccountRequestsModel::ST_BORRADOR) {
            return $this->es_propia($req);
        }
        return $this->es_tesoreria() || $this->es_propia($req);
    }

    /** Respuesta de error: JSON (AJAX/POST) o texto plano (vistas). */
    private function fail(int $code, string $message, bool $json): void
    {
        http_response_code($code);
        if ($json) {
            json_output(['success' => false, 'message' => $message]);
        }
        echo $message;
        exit;
    }

    private function texto($value, int $limite): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        return mb_substr(trim((string) $value), 0, $limite);
    }

    /* ===================================================================== */
    /* Vistas                                                                */
    /* ===================================================================== */

    /** Lista: las propias o, con Tesorería, todas. Los filtros corren en el navegador. */
    public function index(): void
    {
        $es_tesoreria = $this->es_tesoreria();
        $solicitudes  = $this->model->list_visible($this->user_id(), $es_tesoreria);
        foreach ($solicitudes as &$s) {
            $s['es_propia'] = $this->es_propia($s);
            $s['busqueda']  = mb_strtolower('#' . $s['id'] . ' ' . $s['proveedor'] . ' ' . $s['num_cuenta'] . ' ' . $s['banco'] . ' ' . $s['solicitante']);
        }
        unset($s);
        $estatus = self::ESTATUS;
        echo $this->twig->render($this->route . 'index.html', compact('solicitudes', 'es_tesoreria', 'estatus'));
    }

    /** Formulario de nueva solicitud. */
    public function form(): void
    {
        $empresas      = $this->catalogModel->empresas();
        $bancos        = $this->model->banks();
        $monedas       = array_keys(BankAccountRequestsModel::DIVISAS);
        $departamentos = BankAccountRequestsModel::DEPARTAMENTOS;
        $nombre        = $_SESSION['tg_user']['Nombre'] ?? ($_SESSION['tg_user']['Usuario'] ?? '');
        echo $this->twig->render($this->route . 'form.html', compact(
            'empresas', 'bancos', 'monedas', 'departamentos', 'nombre'
        ));
    }

    /** Detalle: datos, avisos de duplicado y, para Tesorería, aprobar / rechazar. */
    public function detail($id): void
    {
        $solicitud = $this->model->get((int) $id);
        if (!$solicitud || !$this->puede_ver($solicitud)) {
            // Misma respuesta para inexistente y ajena: no revela ids.
            $this->fail(403, 'No tiene acceso a esta solicitud.', false);
        }
        $duplicados   = $this->model->duplicates((string) $solicitud['num_cuenta'], (int) $solicitud['id']);
        $pendiente    = (int) $solicitud['status'] === BankAccountRequestsModel::ST_PENDIENTE;
        $puede_decidir = $pendiente && $this->es_tesoreria();
        $puede_subir   = (int) $solicitud['status'] === BankAccountRequestsModel::ST_BORRADOR && $this->es_propia($solicitud);
        $estatus      = self::ESTATUS;
        echo $this->twig->render($this->route . 'detail.html', compact('solicitud', 'duplicados', 'puede_decidir', 'puede_subir', 'estatus'));
    }

    /** JSON de aviso de duplicado mientras se captura la cuenta (no bloquea). */
    public function check(): void
    {
        $cuenta = $this->texto($_GET['cuenta'] ?? '', self::LIM_CUENTA);
        if ($cuenta === '') {
            json_output(['catalogo' => [], 'solicitudes' => []]);
        }
        $dup = $this->model->duplicates($cuenta);
        // Solo lo necesario para el aviso; sin ids de solicitudes ajenas.
        json_output([
            'catalogo' => array_map(fn($c) => [
                'descripcion' => (string) $c['Descripcion'],
                'banco'       => (string) $c['Banco'],
                'activo'      => (bool) $c['Activo'],
            ], $dup['catalogo']),
            'solicitudes' => count($dup['solicitudes']),
        ]);
    }

    /* ===================================================================== */
    /* Captura                                                               */
    /* ===================================================================== */

    /** Valida la captura. Devuelve [datos, error]. */
    private function validar(): array
    {
        $d = [
            'empresa_cod'       => (int) ($_POST['empresa_cod'] ?? 0),
            'banco'             => $this->texto($_POST['banco'] ?? '', 100),
            'moneda'            => $this->texto($_POST['moneda'] ?? '', 10),
            'num_cuenta'        => preg_replace('/\s+/', '', $this->texto($_POST['num_cuenta'] ?? '', self::LIM_CUENTA)),
            'proveedor'         => $this->texto($_POST['proveedor'] ?? '', self::LIM_PROVEEDOR),
            'departamento'      => $this->texto($_POST['departamento'] ?? '', 40),
        ];

        $empresas = array_column($this->catalogModel->empresas(), 'cod');
        if ($d['empresa_cod'] <= 0 || !in_array($d['empresa_cod'], array_map('intval', $empresas), true)) {
            return [$d, 'Seleccione la razón social.'];
        }
        $banco = $this->model->bank_by_name($d['banco']);
        if (!$banco) {
            return [$d, 'Seleccione un banco del catálogo.'];
        }
        $d['clave_banco'] = (string) $banco['clave_transfer'];
        if (!isset(BankAccountRequestsModel::DIVISAS[$d['moneda']])) {
            return [$d, 'Seleccione la moneda.'];
        }
        // El tipo de cuenta ya no se pide: siempre cuenta externa.
        $d['tipo_cuenta'] = 'EXTRNA';
        if ($d['num_cuenta'] === '' || !preg_match('/^\d{6,50}$/', $d['num_cuenta'])) {
            return [$d, 'El número de cuenta debe ser numérico (CLABE de 18 dígitos, tarjeta o cuenta).'];
        }
        if ($d['proveedor'] === '') {
            return [$d, 'Escriba el nombre del proveedor.'];
        }
        // Los datos del layout bancario (beneficiario, plaza, sucursal…) ya no se piden al solicitante;
        // las columnas siguen en la tabla y se llenan con los valores fijos del Excel.
        $d += [
            'plaza_banxico' => '01001', 'sucursal' => '1234', 'tipo_cuenta_cod' => '40',
            'benef_app_paterno' => '', 'benef_app_materno' => '', 'benef_log' => $d['proveedor'],
            'benef_direccion' => '', 'benef_ciudad' => 'MEXICO',
        ];
        if (!in_array($d['departamento'], BankAccountRequestsModel::DEPARTAMENTOS, true)) {
            return [$d, 'Seleccione el departamento.'];
        }
        return [$d, null];
    }

    public function save(): void
    {
        [$d, $error] = $this->validar();
        if ($error !== null) {
            json_output(['success' => false, 'message' => $error]);
        }
        $hayArchivo = !empty($_FILES['caratula']) && is_array($_FILES['caratula'])
            && ($_FILES['caratula']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

        // Sin carátula se guarda como borrador; con ella pasa directo a Tesorería.
        $id = $this->model->create($d, $this->user_id(), $hayArchivo ? BankAccountRequestsModel::ST_PENDIENTE : BankAccountRequestsModel::ST_BORRADOR);
        if ($id <= 0) {
            json_output(['success' => false, 'message' => 'No se pudo guardar la solicitud.']);
        }
        if (!$hayArchivo) {
            json_output(['success' => true, 'id' => $id, 'message' => 'Solicitud guardada como borrador. Suba la carátula para enviarla a Tesorería.']);
        }
        $err = $this->guardar_caratula($id, $_FILES['caratula']);
        if ($err !== null) {
            // No se pierde lo capturado: queda como borrador y se avisa del archivo.
            $this->model->to_borrador($id);
            json_output(['success' => true, 'id' => $id, 'warning' => true,
                'message' => 'Solicitud guardada como borrador, pero la carátula no se pudo subir: ' . $err]);
        }
        json_output(['success' => true, 'id' => $id, 'message' => 'Solicitud enviada a Tesorería.']);
    }

    /** Guarda el archivo tolerando excepciones. Devuelve null o el mensaje de error. */
    private function guardar_caratula(int $id, array $file): ?string
    {
        try {
            return $this->model->save_caratula($id, $file);
        } catch (Throwable $e) {
            error_log('BankAccountRequests::guardar_caratula(#' . $id . '): ' . $e->getMessage());
            return 'No se pudo guardar la carátula.';
        }
    }

    /** Sube la carátula de un borrador propio y lo envía a Tesorería. */
    public function upload($id): void
    {
        $req = $this->model->get((int) $id);
        if (!$req || !$this->es_propia($req)) {
            $this->fail(403, 'No tiene acceso a esta solicitud.', true);
        }
        if ((int) $req['status'] !== BankAccountRequestsModel::ST_BORRADOR) {
            $this->fail(409, 'La solicitud ya no es un borrador, recargue la página.', true);
        }
        if (empty($_FILES['caratula']) || ($_FILES['caratula']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            json_output(['success' => false, 'message' => 'Seleccione la carátula del estado de cuenta.']);
        }
        $err = $this->guardar_caratula((int) $req['id'], $_FILES['caratula']);
        if ($err !== null) {
            json_output(['success' => false, 'message' => $err]);
        }
        if (!$this->model->submit((int) $req['id'])) {
            $this->fail(409, 'La solicitud ya cambió de estado, recargue la página.', true);
        }
        json_output(['success' => true, 'message' => 'Carátula adjunta: la solicitud se envió a Tesorería.']);
    }

    /* ===================================================================== */
    /* Tesorería                                                             */
    /* ===================================================================== */

    /** Carga la solicitud y exige 109 + estatus pendiente. */
    private function solicitud_para_decidir(int $id)
    {
        if (!$this->es_tesoreria()) {
            $this->fail(403, 'No tiene permiso para aprobar o rechazar altas de cuenta.', true);
        }
        $req = $this->model->get($id);
        if (!$req) {
            $this->fail(404, 'La solicitud no existe.', true);
        }
        if ((int) $req['status'] !== BankAccountRequestsModel::ST_PENDIENTE) {
            $this->fail(409, 'La solicitud ya cambió de estado, recargue la página.', true);
        }
        return $req;
    }

    /** Parcial (sin layout) del modal Aprobar / Rechazar que se abre desde la lista. */
    public function decision_modal($id): void
    {
        $solicitud  = $this->solicitud_para_decidir((int) $id);
        $duplicados = $this->model->duplicates((string) $solicitud['num_cuenta'], (int) $solicitud['id']);
        echo $this->twig->render($this->route . 'modals/decision.html', compact('solicitud', 'duplicados'));
    }

    public function approve($id): void
    {
        $req = $this->solicitud_para_decidir((int) $id);

        $comentario = $this->texto($_POST['comentario'] ?? '', self::LIM_COMENTARIO);

        $res = $this->model->approve($req, $this->user_id(), $comentario !== '' ? $comentario : null);
        if (!$res['success']) {
            http_response_code(409);
        }
        json_output($res);
    }

    public function reject($id): void
    {
        $req = $this->solicitud_para_decidir((int) $id);

        $comentario = $this->texto($_POST['comentario'] ?? '', self::LIM_COMENTARIO);
        if ($comentario === '') {
            json_output(['success' => false, 'message' => 'Escriba el motivo del rechazo.']);
        }
        if (!$this->model->reject((int) $req['id'], $this->user_id(), $comentario)) {
            $this->fail(409, 'La solicitud ya cambió de estado, recargue la página.', true);
        }
        json_output(['success' => true, 'message' => 'Solicitud rechazada.']);
    }

    /** Sirve la carátula solo a quien puede ver la solicitud; nunca como estático. */
    public function caratula($id): void
    {
        $req = $this->model->get((int) $id);
        if (!$req || !$this->puede_ver($req) || (string) $req['file_path'] === '') {
            $this->fail(404, 'Documento no encontrado', false);
        }

        $raiz = realpath(__DIR__ . '/../../' . BankAccountRequestsModel::UPLOAD_BASE);
        $ruta = realpath(__DIR__ . '/../../' . $req['file_path']);
        if (!$raiz || !$ruta || !is_file($ruta) || strncmp($ruta, $raiz . DIRECTORY_SEPARATOR, strlen($raiz) + 1) !== 0) {
            $this->fail(404, 'Archivo no encontrado', false);
        }

        $ext  = strtolower((string) $req['file_extension']);
        $mime = match ($ext) {
            'pdf'         => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png'         => 'image/png',
            default       => 'application/octet-stream',
        };
        $nombre = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $req['original_filename']) ?: ('caratula.' . $ext);

        header('Content-Type: ' . $mime);
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: inline; filename="' . $nombre . '"');
        header('Content-Length: ' . filesize($ruta));
        readfile($ruta);
        exit;
    }
}
