<?php

/**
 * Pagos extraordinarios — captura (solicitante).
 *
 * Alta, reenvío tras rechazo, listado "Mis solicitudes", detalle y cancelación.
 * La aprobación (CxP / Admón) y el pago (Tesorería) viven en otros controladores.
 *
 * Rutas: /pagos-extraordinarios/...  (routes/extrapayments.php)
 * Spec:  docs/superpowers/specs/2026-10-19-pagos-extraordinarios-design.md
 * Schema: docs/sql/pagos_extraordinarios_schema.sql
 */
class ExtraPayments
{
    /** Límites de columna (extra_payment_lines / extra_payment_requests). */
    private const LIM_CENTRO     = 80;
    private const LIM_PROVEEDOR  = 200;
    private const LIM_FACTURA    = 60;
    private const LIM_OC         = 60;
    private const LIM_DESC       = 400;
    private const LIM_URGENCIA   = 300;
    private const LIM_MOTIVO     = 200;
    private const MAX_RENGLONES  = 100;

    public $twig;
    public $route;
    public ExtraPaymentRequestsModel $requestsModel;
    public ExtraPaymentCatalogModel $catalogModel;
    public DepartamentosModel $departamentosModel;

    public function __construct($twig)
    {
        $this->twig               = $twig;
        $this->route              = 'views/extrapayments/';
        $this->requestsModel      = new ExtraPaymentRequestsModel();
        $this->catalogModel       = new ExtraPaymentCatalogModel();
        $this->departamentosModel = new DepartamentosModel();
    }

    /* ===================================================================== */
    /* Helpers                                                               */
    /* ===================================================================== */

    private function user_id(): int
    {
        return (int) ($_SESSION['tg_user']['Id'] ?? 0);
    }

    private function es_ajax(): bool
    {
        return (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');
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

    /** Corta si el usuario no tiene NINGUNO de los permisos dados. */
    private function guard(array $permisos, bool $json = false): void
    {
        foreach ($permisos as $p) {
            if (authorized($p)) {
                return;
            }
        }
        $this->fail(403, 'No tiene permiso para acceder a este módulo.', $json);
    }

    private function es_propia(array $request): bool
    {
        return (int) $request['user_id'] === $this->user_id();
    }

    /** Recorta, quita espacios y trunca al límite de la columna. */
    private function texto($value, int $limite): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        return mb_substr(trim((string) $value), 0, $limite);
    }

    /** Fecha Y-m-d válida o null. */
    private function fecha($value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        $d = DateTime::createFromFormat('!Y-m-d', $value);
        $err = DateTime::getLastErrors();
        if (!$d || ($err && ($err['warning_count'] > 0 || $err['error_count'] > 0)) || $d->format('Y-m-d') !== $value) {
            return null;
        }
        return $value;
    }

    /* ===================================================================== */
    /* Vistas                                                                */
    /* ===================================================================== */

    /** Mis solicitudes. */
    public function index(): void
    {
        $this->guard([ExtraPaymentWorkflow::PERM_CREAR]);
        $solicitudes = $this->requestsModel->list_for_user($this->user_id());
        echo $this->twig->render($this->route . 'index.html', compact('solicitudes'));
    }

    /** Formulario de alta (sin id) o de edición tras rechazo (con id). */
    public function form($id = null): void
    {
        $this->guard([ExtraPaymentWorkflow::PERM_CREAR]);

        $solicitud = null;
        if ($id !== null) {
            $solicitud = $this->requestsModel->get((int) $id);
            if (!$solicitud || !$this->es_propia($solicitud)) {
                // Misma respuesta para inexistente y ajena: no revela ids.
                $this->fail(403, 'No tiene acceso a esta solicitud.', false);
            }
            if (!ExtraPaymentWorkflow::can_edit((int) $solicitud['status'])) {
                $this->fail(403, 'Esta solicitud ya no se puede editar.', false);
            }
        }

        $motivos  = $this->catalogModel->reasons();
        $empresas = $this->catalogModel->empresas();
        $area     = $this->departamentosModel->nombre_de_usuario($this->user_id());
        $nombre   = $_SESSION['tg_user']['Nombre'] ?? ($_SESSION['tg_user']['Usuario'] ?? '');
        echo $this->twig->render($this->route . 'form.html', compact('solicitud', 'motivos', 'empresas', 'area', 'nombre'));
    }

    /** Detalle: encabezado, renglones, firmas y auditoría. */
    public function detail($id): void
    {
        $this->guard([
            ExtraPaymentWorkflow::PERM_CREAR, ExtraPaymentWorkflow::PERM_CXP, ExtraPaymentWorkflow::PERM_ADMON,
            ExtraPaymentWorkflow::PERM_TESORERIA, ExtraPaymentWorkflow::PERM_VER_TODAS,
        ]);

        $solicitud = $this->requestsModel->get((int) $id);
        if (!$solicitud) {
            (new Errors())->get404();
            return;
        }
        // Sin 110 (ver todas) solo se ven las propias.
        if (!authorized(ExtraPaymentWorkflow::PERM_VER_TODAS) && !$this->es_propia($solicitud)) {
            $this->fail(403, 'No tiene acceso a esta solicitud.', false);
        }

        $status      = (int) $solicitud['status'];
        $es_propia   = $this->es_propia($solicitud);
        $puede_editar   = $es_propia && authorized(ExtraPaymentWorkflow::PERM_CREAR) && ExtraPaymentWorkflow::can_edit($status);
        $puede_cancelar = $es_propia && authorized(ExtraPaymentWorkflow::PERM_CREAR) && ExtraPaymentWorkflow::can_cancel($status);
        echo $this->twig->render($this->route . 'detail.html', compact('solicitud', 'puede_editar', 'puede_cancelar'));
    }

    /* ===================================================================== */
    /* Acciones JSON                                                         */
    /* ===================================================================== */

    /**
     * Alta, o reenvío si trae `id`. Todo lo que llega por POST se trata como
     * no confiable: se sanea, se re-deriva `requiere_texto` del catálogo y
     * usuario/departamento salen de la sesión.
     */
    public function save(): void
    {
        $this->guard([ExtraPaymentWorkflow::PERM_CREAR], true);
        $userId = $this->user_id();

        // Tipo
        $tipo = $_POST['tipo'] ?? '';
        if ($tipo !== 'individual' && $tipo !== 'multiple') {
            json_output(['success' => false, 'message' => 'Tipo de solicitud inválido.']);
        }

        // Motivo: debe existir y estar activo (reasons() solo trae activos).
        $reasonId = (int) ($_POST['reason_id'] ?? 0);
        $motivo = null;
        foreach ($this->catalogModel->reasons() as $r) {
            if ((int) $r['id'] === $reasonId) {
                $motivo = $r;
                break;
            }
        }
        if ($motivo === null) {
            json_output(['success' => false, 'message' => 'Seleccione un motivo válido.']);
        }
        $requiereTexto = !empty($motivo['requiere_texto']);

        // Renglones: deben ser un arreglo de arreglos.
        $rawLines = $_POST['lines'] ?? null;
        if (!is_array($rawLines)) {
            json_output(['success' => false, 'message' => 'Debe capturar al menos un renglón.']);
        }
        if (count($rawLines) > self::MAX_RENGLONES) {
            json_output(['success' => false, 'message' => 'Demasiados renglones (máximo ' . self::MAX_RENGLONES . ').']);
        }

        $empresasValidas = [];
        foreach ($this->catalogModel->empresas() as $e) {
            $empresasValidas[(int) $e['cod']] = true;
        }

        $errors = [];
        $lines  = [];
        $n = 0;
        foreach ($rawLines as $raw) {
            $n++;
            if (!is_array($raw)) {
                $errors[] = "Renglón $n: datos inválidos.";
                continue;
            }
            $fechaPago    = $this->fecha($raw['fecha_pago_requerida'] ?? null);
            $fechaFactura = trim((string) (is_scalar($raw['fecha_factura'] ?? null) ? $raw['fecha_factura'] : ''));
            $fechaFacturaOk = $fechaFactura === '' ? null : $this->fecha($fechaFactura);

            $empresa = is_scalar($raw['empresa_cod'] ?? null) ? trim((string) $raw['empresa_cod']) : '';
            if ($empresa !== '' && !(ctype_digit($empresa) && isset($empresasValidas[(int) $empresa]))) {
                $errors[] = "Renglón $n: empresa inválida.";
                $empresa = '';
            }
            $monto = $raw['monto'] ?? null;
            $monto = is_scalar($monto) ? str_replace([',', '$', ' '], '', (string) $monto) : '';

            $line = [
                'empresa_cod'          => $empresa,
                'centro_costos'        => $this->texto($raw['centro_costos'] ?? '', self::LIM_CENTRO),
                'proveedor'            => $this->texto($raw['proveedor'] ?? '', self::LIM_PROVEEDOR),
                'factura'              => $this->texto($raw['factura'] ?? '', self::LIM_FACTURA),
                'fecha_factura'        => $fechaFacturaOk ?? '',
                'orden_compra'         => $this->texto($raw['orden_compra'] ?? '', self::LIM_OC),
                'descripcion'          => $this->texto($raw['descripcion'] ?? '', self::LIM_DESC),
                'monto'                => $monto,
                'fecha_pago_requerida' => $fechaPago ?? '',
                'comentario_urgencia'  => $this->texto($raw['comentario_urgencia'] ?? '', self::LIM_URGENCIA),
            ];
            if ($line['centro_costos'] === '') {
                $errors[] = "Renglón $n: falta el centro de costos.";
            }
            if ($fechaPago === null) {
                $errors[] = "Renglón $n: la fecha de pago requerida es inválida.";
            }
            if ($fechaFactura !== '' && $fechaFacturaOk === null) {
                $errors[] = "Renglón $n: la fecha de la factura es inválida.";
            }
            $lines[] = $line;
        }

        $id = (int) ($_POST['id'] ?? 0);
        $header = [
            'tipo'           => $tipo,
            'reason_id'      => $reasonId,
            'motivo_otros'   => $this->texto($_POST['motivo_otros'] ?? '', self::LIM_MOTIVO),
            'requiere_texto' => $requiereTexto,
        ];
        if (!$requiereTexto) {
            $header['motivo_otros'] = ''; // no se guarda texto huérfano
        }

        $errors = array_merge($errors, ExtraPaymentWorkflow::validate_request($header, $lines));
        if ($errors) {
            json_output(['success' => false, 'message' => implode("\n", array_values(array_unique($errors)))]);
        }

        if ($id > 0) {
            // Reenvío tras rechazo: solo el dueño y solo desde status 9.
            $actual = $this->requestsModel->get($id);
            if (!$actual || !$this->es_propia($actual)) {
                $this->fail(403, 'No tiene acceso a esta solicitud.', true);
            }
            if (!ExtraPaymentWorkflow::can_edit((int) $actual['status'])) {
                json_output(['success' => false, 'message' => 'Esta solicitud ya no se puede editar.']);
            }
            // El departamento no se cambia desde el cliente: se conserva el de la solicitud.
            $header['departamento_id'] = $actual['departamento_id'] ?? null;
            $ok = $this->requestsModel->update($id, $header, $lines, $userId);
            if (!$ok) {
                json_output(['success' => false, 'message' => 'No se pudo reenviar la solicitud: cambió de estatus o hubo un error. Recargue e intente de nuevo.']);
            }
            json_output(['success' => true, 'message' => 'Solicitud reenviada.', 'id' => $id]);
        }

        $header['fecha']           = date('Y-m-d');
        $header['departamento_id'] = $this->departamento_id_usuario($userId);
        $newId = $this->requestsModel->create($header, $lines, $userId);
        if (!$newId) {
            json_output(['success' => false, 'message' => 'No se pudo guardar la solicitud. Intente de nuevo.']);
        }
        json_output(['success' => true, 'message' => 'Solicitud enviada.', 'id' => $newId]);
    }

    /** Cancela una solicitud propia (estatus 1, 2 o 9). */
    public function cancel($id): void
    {
        $this->guard([ExtraPaymentWorkflow::PERM_CREAR], true);
        $id = (int) $id;

        $solicitud = $this->requestsModel->get($id);
        if (!$solicitud || !$this->es_propia($solicitud)) {
            $this->fail(403, 'No tiene acceso a esta solicitud.', true);
        }
        if (!ExtraPaymentWorkflow::can_cancel((int) $solicitud['status'])) {
            json_output(['success' => false, 'message' => 'Esta solicitud ya no se puede cancelar.']);
        }
        if (!$this->requestsModel->cancel($id, $this->user_id())) {
            json_output(['success' => false, 'message' => 'No se pudo cancelar: el estatus cambió. Recargue e intente de nuevo.']);
        }
        json_output(['success' => true, 'message' => 'Solicitud cancelada.']);
    }

    /** Id del departamento del usuario en sesión (nunca viene de POST). */
    private function departamento_id_usuario(int $userId): ?int
    {
        $rows = $this->requestsModel->sql->select(
            'SELECT IdDepartamento FROM [TG].[dbo].[Usuario] WHERE Id = ?',
            [$userId]
        );
        $v = $rows[0]['IdDepartamento'] ?? null;
        return $v === null ? null : (int) $v;
    }
}
