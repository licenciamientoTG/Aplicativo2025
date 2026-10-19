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
    private const LIM_COMENTARIO = 400;
    private const LIM_REFERENCIA = 80;
    private const MSG_CAMBIO_ESTADO = 'La solicitud ya cambió de estado, recargue la página.';

    /** etapa => [permiso, estatus que se atiende, título]. */
    private const ETAPAS = [
        'cxp'       => [ExtraPaymentWorkflow::PERM_CXP,       ExtraPaymentWorkflow::ST_PEND_CXP,   'VoBo Cuentas por pagar'],
        'admon'     => [ExtraPaymentWorkflow::PERM_ADMON,     ExtraPaymentWorkflow::ST_PEND_ADMON, 'Autorización Administración'],
        'tesoreria' => [ExtraPaymentWorkflow::PERM_TESORERIA, ExtraPaymentWorkflow::ST_AUTORIZADA, 'Pago Tesorería'],
    ];

    public $twig;
    public $route;
    public ExtraPaymentRequestsModel $requestsModel;
    public ExtraPaymentCatalogModel $catalogModel;
    public DepartamentosModel $departamentosModel;
    public ExtraPaymentDocumentsModel $docsModel;

    public function __construct($twig)
    {
        $this->twig               = $twig;
        $this->route              = 'views/extrapayments/';
        $this->requestsModel      = new ExtraPaymentRequestsModel();
        $this->catalogModel       = new ExtraPaymentCatalogModel();
        $this->departamentosModel = new DepartamentosModel();
        $this->docsModel          = new ExtraPaymentDocumentsModel();
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

    /**
     * Ve cualquier solicitud quien tenga 110 (ver todas) o algún permiso de
     * bandeja (107 CxP / 108 Admón / 109 Tesorería); los demás, solo las propias.
     */
    private function es_privilegiado(): bool
    {
        return authorized(ExtraPaymentWorkflow::PERM_VER_TODAS)
            || authorized(ExtraPaymentWorkflow::PERM_CXP)
            || authorized(ExtraPaymentWorkflow::PERM_ADMON)
            || authorized(ExtraPaymentWorkflow::PERM_TESORERIA);
    }

    private function puede_ver(array $solicitud): bool
    {
        return $this->es_privilegiado() || $this->es_propia($solicitud);
    }

    /** Estatus en los que cada clase de adjunto se puede subir/eliminar. */
    private function estatus_adjuntos(string $kind): array
    {
        return $kind === 'soporte'
            ? [ExtraPaymentWorkflow::ST_PEND_CXP, ExtraPaymentWorkflow::ST_RECHAZADA]
            : [ExtraPaymentWorkflow::ST_AUTORIZADA, ExtraPaymentWorkflow::ST_PAGADA];
    }

    /**
     * ¿Puede el usuario subir adjuntos de esta clase en esta solicitud?
     * soporte: el solicitante (106) con la solicitud en 1 (pendiente CxP) o 9 (rechazada).
     * comprobante: Tesorería (109) con la solicitud en 3 (autorizada) o 4 (pagada).
     */
    private function puede_adjuntar(array $solicitud, string $kind): bool
    {
        if ($kind !== 'soporte' && $kind !== 'comprobante') {
            return false;
        }
        if (!in_array((int) $solicitud['status'], $this->estatus_adjuntos($kind), true)) {
            return false;
        }
        return $kind === 'soporte'
            ? $this->es_propia($solicitud) && authorized(ExtraPaymentWorkflow::PERM_CREAR)
            : authorized(ExtraPaymentWorkflow::PERM_TESORERIA);
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
        // Alta: sin id no hay adjuntos; se agregan en el detalle tras el primer guardado.
        $adjuntos = $solicitud ? $this->datos_adjuntos($solicitud) : [];
        echo $this->twig->render($this->route . 'form.html', array_merge(
            compact('solicitud', 'motivos', 'empresas', 'area', 'nombre'),
            $adjuntos
        ));
    }

    /** Detalle: encabezado, renglones, firmas y auditoría. */
    public function detail($id): void
    {
        $this->guard([
            ExtraPaymentWorkflow::PERM_CREAR, ExtraPaymentWorkflow::PERM_CXP, ExtraPaymentWorkflow::PERM_ADMON,
            ExtraPaymentWorkflow::PERM_TESORERIA, ExtraPaymentWorkflow::PERM_VER_TODAS,
        ]);

        // Regla de visibilidad: ve cualquier solicitud quien tenga 110 (ver todas) o
        // algún permiso de bandeja (107 CxP / 108 Admón / 109 Tesorería), porque
        // necesita abrir el detalle de lo que aprueba o paga. Los demás (solo 106)
        // ven únicamente las propias. Para estos últimos, inexistente y ajena
        // responden el mismo 403 (no se revela qué ids existen).
        $privilegiado = $this->es_privilegiado();

        $solicitud = $this->requestsModel->get((int) $id);
        if (!$solicitud) {
            if (!$privilegiado) {
                $this->fail(403, 'No tiene acceso a esta solicitud.', false);
            }
            (new Errors())->get404();
            return;
        }
        if (!$privilegiado && !$this->es_propia($solicitud)) {
            $this->fail(403, 'No tiene acceso a esta solicitud.', false);
        }

        $status      = (int) $solicitud['status'];
        $es_propia   = $this->es_propia($solicitud);
        $puede_editar   = $es_propia && authorized(ExtraPaymentWorkflow::PERM_CREAR) && ExtraPaymentWorkflow::can_edit($status);
        $puede_cancelar = $es_propia && authorized(ExtraPaymentWorkflow::PERM_CREAR) && ExtraPaymentWorkflow::can_cancel($status);
        echo $this->twig->render($this->route . 'detail.html', array_merge(
            compact('solicitud', 'puede_editar', 'puede_cancelar'),
            $this->datos_adjuntos($solicitud)
        ));
    }

    /* ===================================================================== */
    /* Impresión y reporte                                                   */
    /* ===================================================================== */

    /** Etiquetas de estatus (mismas de index.html). */
    private const ESTATUS = [
        1 => 'Pendiente CxP', 2 => 'Pendiente Admón', 3 => 'Autorizada',
        4 => 'Pagada', 8 => 'Cancelada', 9 => 'Rechazada',
    ];

    /** Formato imprimible (réplica del Excel) sin layout; misma visibilidad que detail(). */
    public function print($id): void
    {
        $this->guard([
            ExtraPaymentWorkflow::PERM_CREAR, ExtraPaymentWorkflow::PERM_CXP, ExtraPaymentWorkflow::PERM_ADMON,
            ExtraPaymentWorkflow::PERM_TESORERIA, ExtraPaymentWorkflow::PERM_VER_TODAS,
        ]);

        $privilegiado = $this->es_privilegiado();
        $solicitud = $this->requestsModel->get((int) $id);
        if (!$solicitud) {
            if (!$privilegiado) {
                $this->fail(403, 'No tiene acceso a esta solicitud.', false);
            }
            (new Errors())->get404();
            return;
        }
        if (!$privilegiado && !$this->es_propia($solicitud)) {
            $this->fail(403, 'No tiene acceso a esta solicitud.', false);
        }

        $estatus_label = self::ESTATUS[(int) $solicitud['status']] ?? (string) $solicitud['status'];

        // Firmas: aprobación activa y aprobada de cada paso (1 CxP, 2 Admón y Finanzas).
        $firmas = [1 => null, 2 => null];
        foreach ($solicitud['approvals'] as $a) {
            $step = (int) $a['step'];
            if (array_key_exists($step, $firmas) && $a['decision'] === 'aprobada') {
                $firmas[$step] = $a;
            }
        }

        // Cuenta de pago (solo informativa; si el catálogo falla, se omite).
        $cuenta = null;
        if (!empty($solicitud['payment']['bank_account_id'])) {
            try {
                $c = (new CuentasBancariasModel())->get_by_id((int) $solicitud['payment']['bank_account_id']);
                if ($c) {
                    $cuenta = trim(($c['Banco'] ?? '') . ' — ' . ($c['Descripcion'] ?? '') . ' (' . ($c['CuentaLocal'] ?? '') . ')');
                }
            } catch (Throwable $e) {
                error_log('ExtraPayments::print cuenta: ' . $e->getMessage());
            }
        }

        $motivos = $this->catalogModel->reasons();
        // Si el motivo elegido ya fue desactivado, la vista lo agrega marcado.
        $motivo_en_catalogo = in_array((int) $solicitud['reason_id'], array_map(fn($m) => (int) $m['id'], $motivos), true);
        echo $this->twig->render($this->route . 'print.html', compact('solicitud', 'firmas', 'cuenta', 'motivos', 'motivo_en_catalogo', 'estatus_label'));
    }

    /** Filtros del reporte, saneados (GET no es confiable). */
    private function filtros_reporte(): array
    {
        $f = ['status' => '', 'empresa_cod' => '', 'reason_id' => '', 'desde' => '', 'hasta' => ''];
        $st = $_GET['status'] ?? '';
        if (is_scalar($st) && ctype_digit((string) $st) && isset(self::ESTATUS[(int) $st])) {
            $f['status'] = (int) $st;
        }
        foreach (['empresa_cod', 'reason_id'] as $k) {
            $v = $_GET[$k] ?? '';
            if (is_scalar($v) && ctype_digit((string) $v) && strlen((string) $v) <= 9) {
                $f[$k] = (int) $v;
            }
        }
        foreach (['desde', 'hasta'] as $k) {
            $f[$k] = $this->fecha($_GET[$k] ?? null) ?? '';
        }
        return $f;
    }

    /** Reporte general con filtros (permiso 110). */
    public function report(): void
    {
        $this->guard([ExtraPaymentWorkflow::PERM_VER_TODAS]);
        $filtros = $this->filtros_reporte();
        $solicitudes = $this->requestsModel->list_all($filtros);
        $total = 0.0;
        foreach ($solicitudes as $s) {
            $total += (float) $s['total'];
        }
        $query = http_build_query(array_filter($filtros, fn($v) => $v !== ''));
        echo $this->twig->render($this->route . 'report.html', [
            'solicitudes' => $solicitudes,
            'filtros'     => $filtros,
            'motivos'     => $this->catalogModel->reasons(),
            'empresas'    => $this->catalogModel->empresas(),
            'estatus'     => self::ESTATUS,
            'total'       => $total,
            'query'       => $query,
        ]);
    }

    /** Exporta el reporte a .xlsx, una fila por renglón (permiso 110). */
    public function report_export(): void
    {
        $this->guard([ExtraPaymentWorkflow::PERM_VER_TODAS]);
        $filas = $this->requestsModel->list_lines_for_export($this->filtros_reporte());
        $libro = $this->libro_reporte($filas);

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="pagos_extraordinarios_' . date('Ymd_His') . '.xlsx"');
        header('Cache-Control: max-age=0');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($libro))->save('php://output');
        exit;
    }

    /** Texto de usuario como cadena explícita (evita inyección de fórmulas =,+,-,@). */
    private function celda_texto(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $hoja, int $col, int $fila, $valor): void
    {
        $hoja->setCellValueExplicit([$col, $fila], (string) ($valor ?? ''), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    }

    /** Arma el libro del reporte (separado del endpoint para poder revisarlo sin descarga). */
    private function libro_reporte(array $filas): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        $libro = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $hoja  = $libro->getActiveSheet();
        $hoja->setTitle('Pagos extraordinarios');

        $cab = ['Folio', 'Fecha', 'Empresa', 'Centro de costos', 'Proveedor', 'Factura', 'Fecha factura',
                'Descripción', 'Monto', 'Motivo', 'Estatus', 'Fecha de pago'];
        foreach ($cab as $i => $t) {
            $this->celda_texto($hoja, $i + 1, 1, $t);
        }
        $hoja->getStyle('A1:L1')->getFont()->setBold(true);

        $fmt = function ($d): string {
            if (!$d) {
                return '';
            }
            $d = $d instanceof \DateTimeInterface ? $d->format('Y-m-d') : (string) $d;
            $t = strtotime($d);
            return $t ? date('d/m/Y', $t) : '';
        };
        $r = 2;
        foreach ($filas as $f) {
            $motivo = (string) ($f['reason_nombre'] ?? '');
            if (!empty($f['motivo_otros'])) {
                $motivo .= ': ' . $f['motivo_otros'];
            }
            $this->celda_texto($hoja, 1, $r, '#' . (int) $f['folio']);
            $this->celda_texto($hoja, 2, $r, $fmt($f['fecha'] ?? null));
            $this->celda_texto($hoja, 3, $r, $f['empresa_nombre'] ?? '');
            $this->celda_texto($hoja, 4, $r, $f['centro_costos'] ?? '');
            $this->celda_texto($hoja, 5, $r, $f['proveedor'] ?? '');
            $this->celda_texto($hoja, 6, $r, $f['factura'] ?? '');
            $this->celda_texto($hoja, 7, $r, $fmt($f['fecha_factura'] ?? null));
            $this->celda_texto($hoja, 8, $r, $f['descripcion'] ?? '');
            $hoja->setCellValue([9, $r], (float) ($f['monto'] ?? 0)); // numérico
            $this->celda_texto($hoja, 10, $r, $motivo);
            $this->celda_texto($hoja, 11, $r, self::ESTATUS[(int) $f['status']] ?? (string) $f['status']);
            $this->celda_texto($hoja, 12, $r, $fmt($f['fecha_pago'] ?? null));
            $r++;
        }
        if ($r > 2) {
            $hoja->getStyle('I2:I' . ($r - 1))->getNumberFormat()->setFormatCode('#,##0.00');
        }
        foreach (range('A', 'L') as $col) {
            $hoja->getColumnDimension($col)->setAutoSize(true);
        }
        return $libro;
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
            // Formato estricto: hasta 13 enteros y 2 decimales, mayor a cero (sin notación científica ni signo).
            if (!preg_match('/^\d{1,13}(\.\d{1,2})?$/', $monto) || (float) $monto <= 0) {
                $errors[] = "Renglón $n: el monto debe ser un número mayor a cero con máximo 2 decimales.";
            }

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
            $this->notify($id, 'pago_extra_cxp', 'Pago extraordinario #' . $id . ' pendiente de VoBo de Cuentas por pagar');
            json_output(['success' => true, 'message' => 'Solicitud reenviada.', 'id' => $id]);
        }

        $header['fecha']           = date('Y-m-d');
        $header['departamento_id'] = $this->departamento_id_usuario($userId);
        $newId = $this->requestsModel->create($header, $lines, $userId);
        if (!$newId) {
            json_output(['success' => false, 'message' => 'No se pudo guardar la solicitud. Intente de nuevo.']);
        }
        $this->notify((int) $newId, 'pago_extra_cxp', 'Pago extraordinario #' . $newId . ' pendiente de VoBo de Cuentas por pagar');
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

    /* ===================================================================== */
    /* Adjuntos                                                              */
    /* ===================================================================== */

    /** Eliminar: solo quien subió el adjunto, mientras el estatus aún permita esa clase. */
    private function puede_eliminar_doc(array $solicitud, array $doc, int $uid): bool
    {
        if (!$this->puede_ver($solicitud)) {
            return false;
        }
        if ((int) $doc['created_by'] !== $uid) {
            return false;
        }
        return in_array((int) $solicitud['status'], $this->estatus_adjuntos((string) $doc['kind']), true);
    }

    /** Variables Twig del panel de adjuntos (views/extrapayments/partials/documentos.html). */
    private function datos_adjuntos(array $solicitud): array
    {
        $uid = $this->user_id();
        $documentos = $this->docsModel->get_by_request((int) $solicitud['id']);
        foreach ($documentos as &$d) {
            $d['puede_eliminar'] = $this->puede_eliminar_doc($solicitud, $d, $uid); // doc_delete lo re-valida
        }
        unset($d);
        return [
            'documentos'             => $documentos,
            'can_upload_soporte'     => $this->puede_adjuntar($solicitud, 'soporte'),
            'can_upload_comprobante' => $this->puede_adjuntar($solicitud, 'comprobante'),
        ];
    }

    /** Bitácora de adjuntos; un fallo de bitácora no revierte la operación ya hecha. */
    private function audit_doc(int $requestId, string $accion, string $detalle): void
    {
        try {
            $this->requestsModel->audit($requestId, $this->user_id(), $accion, $detalle);
        } catch (Throwable $e) {
            error_log('ExtraPayments::audit_doc(' . $accion . ', #' . $requestId . '): ' . $e->getMessage());
        }
    }

    /** Sube uno o más adjuntos (campo `documento[]`, `kind` = soporte|comprobante). */
    public function doc_upload($id): void
    {
        $this->guard([ExtraPaymentWorkflow::PERM_CREAR, ExtraPaymentWorkflow::PERM_TESORERIA], true);
        $id = (int) $id;

        $solicitud = $this->requestsModel->get($id);
        if (!$solicitud || !$this->puede_ver($solicitud)) {
            $this->fail(403, 'No tiene acceso a esta solicitud.', true);
        }
        $kind = (string) ($_POST['kind'] ?? '');
        if (!in_array($kind, ExtraPaymentDocumentsModel::KINDS, true)) {
            json_output(['success' => false, 'message' => 'Tipo de documento inválido.']);
        }
        if (!$this->puede_adjuntar($solicitud, $kind)) {
            json_output(['success' => false, 'message' => 'No puede adjuntar este tipo de documento en el estatus actual de la solicitud.']);
        }
        if (empty($_FILES['documento']) || !is_array($_FILES['documento']['name'])) {
            json_output(['success' => false, 'message' => 'No se recibieron archivos.']);
        }

        $files   = $_FILES['documento'];
        $subidos = 0;
        $errores = [];
        $activos = $this->docsModel->count_active($id);
        foreach (array_keys($files['name']) as $i) {
            $nombre = (string) $files['name'][$i];
            if ($activos >= ExtraPaymentDocumentsModel::MAX_ACTIVOS) {
                $errores[] = $nombre . ': se alcanzó el máximo de ' . ExtraPaymentDocumentsModel::MAX_ACTIVOS . ' adjuntos por solicitud';
                continue;
            }
            try {
                $res = $this->docsModel->upload($id, $kind, [
                    'name'     => $nombre,
                    'tmp_name' => $files['tmp_name'][$i],
                    'error'    => $files['error'][$i],
                    'size'     => $files['size'][$i],
                ], $this->user_id());
            } catch (Throwable $e) {
                error_log('ExtraPayments::doc_upload(#' . $id . '): ' . $e->getMessage());
                $res = ['success' => false, 'message' => 'No se pudo guardar el documento'];
            }
            if ($res['success']) {
                $subidos++;
                $activos++;
                $this->audit_doc($id, 'DOC_UPLOAD', $nombre . ' (' . $kind . ')');
            } else {
                $errores[] = $nombre . ': ' . $res['message'];
            }
        }

        json_output([
            'success' => $subidos > 0,
            'subidos' => $subidos,
            'errores' => $errores,
            'message' => $subidos > 0
                ? "$subidos archivo(s) subido(s)" . ($errores ? ' (con error: ' . implode('; ', $errores) . ')' : '')
                : implode("\n", $errores ?: ['No se pudo subir ningún archivo.']),
        ]);
    }

    /** Sirve un adjunto solo a quien puede ver la solicitud; nunca como estático. */
    public function doc_view($doc_id): void
    {
        $this->guard([
            ExtraPaymentWorkflow::PERM_CREAR, ExtraPaymentWorkflow::PERM_CXP, ExtraPaymentWorkflow::PERM_ADMON,
            ExtraPaymentWorkflow::PERM_TESORERIA, ExtraPaymentWorkflow::PERM_VER_TODAS,
        ]);

        $doc = $this->docsModel->get_by_id((int) $doc_id);
        $solicitud = ($doc && (int) $doc['is_deleted'] === 0) ? $this->requestsModel->get((int) $doc['request_id']) : null;
        if (!$solicitud) {
            $this->fail(404, 'Documento no encontrado', false);
        }
        if (!$this->puede_ver($solicitud)) {
            // Misma respuesta que un documento inexistente: no revela qué ids existen.
            $this->fail(404, 'Documento no encontrado', false);
        }

        $raiz = realpath(__DIR__ . '/../../' . ExtraPaymentDocumentsModel::UPLOAD_BASE);
        $ruta = realpath(__DIR__ . '/../../' . $doc['file_path']);
        if (!$raiz || !$ruta || !is_file($ruta) || strncmp($ruta, $raiz . DIRECTORY_SEPARATOR, strlen($raiz) + 1) !== 0) {
            $this->fail(404, 'Archivo no encontrado', false);
        }

        $ext  = strtolower((string) $doc['file_extension']);
        $mime = match ($ext) {
            'pdf'         => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png'         => 'image/png',
            'xml'         => 'application/xml',
            default       => 'application/octet-stream',
        };
        // El XML se descarga: visto inline podría interpretarse como documento activo.
        $disposicion = $ext === 'xml' ? 'attachment' : 'inline';
        $nombre = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $doc['original_filename']) ?: ('documento.' . $ext);

        header('Content-Type: ' . $mime);
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: ' . $disposicion . '; filename="' . $nombre . '"');
        header('Content-Length: ' . filesize($ruta));
        readfile($ruta);
        exit;
    }

    /** Soft-delete de un adjunto (ver puede_eliminar_doc). */
    public function doc_delete($doc_id): void
    {
        $this->guard([
            ExtraPaymentWorkflow::PERM_CREAR, ExtraPaymentWorkflow::PERM_TESORERIA,
        ], true);

        $doc = $this->docsModel->get_by_id((int) $doc_id);
        $solicitud = ($doc && (int) $doc['is_deleted'] === 0) ? $this->requestsModel->get((int) $doc['request_id']) : null;
        if (!$solicitud) {
            $this->fail(403, 'No tiene acceso a este documento.', true);
        }
        if (!$this->puede_eliminar_doc($solicitud, $doc, $this->user_id())) {
            json_output(['success' => false, 'message' => 'No puede eliminar este documento (no es suyo o la solicitud ya no lo permite).']);
        }
        if (!$this->docsModel->soft_delete((int) $doc['id'], $this->user_id())) {
            json_output(['success' => false, 'message' => 'No se pudo eliminar (¿ya estaba eliminado?)']);
        }
        $this->audit_doc((int) $solicitud['id'], 'DOC_DELETE', (string) $doc['original_filename'] . ' (' . $doc['kind'] . ')');
        json_output(['success' => true, 'message' => 'Archivo eliminado']);
    }

    /* ===================================================================== */
    /* Bandejas de aprobación y pago                                        */
    /* ===================================================================== */

    /** Bandeja por etapa (cxp | admon | tesoreria). */
    public function inbox($stage): void
    {
        $stage = (string) $stage;
        if (!isset(self::ETAPAS[$stage])) {
            (new Errors())->get404();
            return;
        }
        [$permiso, $status, $titulo] = self::ETAPAS[$stage];
        $this->guard([$permiso]);

        $solicitudes = $this->requestsModel->list_by_status($status);
        echo $this->twig->render($this->route . 'inbox.html', compact('solicitudes', 'stage', 'titulo', 'status'));
    }

    /** Parcial (sin layout) del modal Aprobar / Rechazar. */
    public function decision_modal($id): void
    {
        $solicitud = $this->solicitud_para_decidir((int) $id, false);
        echo $this->twig->render($this->route . 'modals/decision.html', compact('solicitud'));
    }

    /** Parcial (sin layout) del modal de pago de Tesorería. */
    public function pay_modal($id): void
    {
        $this->guard([ExtraPaymentWorkflow::PERM_TESORERIA]);
        $solicitud = $this->requestsModel->get((int) $id);
        if (!$solicitud || (int) $solicitud['status'] !== ExtraPaymentWorkflow::ST_AUTORIZADA) {
            $this->fail(409, 'La solicitud ya no está pendiente de pago. Recargue la bandeja.', false);
        }
        $cuentas = $this->cuentas_pago();
        $hoy = date('Y-m-d');
        echo $this->twig->render($this->route . 'modals/pay.html', compact('solicitud', 'cuentas', 'hoy'));
    }

    public function approve($id): void
    {
        $id = (int) $id;
        $solicitud = $this->solicitud_para_decidir($id, true);
        $status = (int) $solicitud['status'];

        $comentario = $this->texto($_POST['comentario'] ?? '', self::LIM_COMENTARIO);
        // $status viaja al modelo: si cambió entre esta lectura y la transacción, no avanza otro paso.
        $ok = $this->requestsModel->approve($id, $this->user_id(), $comentario === '' ? null : $comentario, $status);
        if (!$ok) {
            json_output(['success' => false, 'message' => self::MSG_CAMBIO_ESTADO]);
        }

        $siguiente = $status === ExtraPaymentWorkflow::ST_PEND_CXP
            ? ['pago_extra_admon', 'pendiente de autorización de Administración']
            : ['pago_extra_tesoreria', 'autorizado y pendiente de pago en Tesorería'];
        $this->notify($id, $siguiente[0], 'Pago extraordinario #' . $id . ' ' . $siguiente[1]);

        json_output(['success' => true, 'message' => 'Solicitud #' . $id . ' aprobada.']);
    }

    public function reject($id): void
    {
        $id = (int) $id;
        $solicitud = $this->solicitud_para_decidir($id, true);
        $status = (int) $solicitud['status'];

        $comentario = $this->texto($_POST['comentario'] ?? '', self::LIM_COMENTARIO);
        if ($comentario === '') {
            json_output(['success' => false, 'message' => 'El comentario es obligatorio para rechazar.']);
        }
        if (!$this->requestsModel->reject($id, $this->user_id(), $comentario, $status)) {
            json_output(['success' => false, 'message' => self::MSG_CAMBIO_ESTADO]);
        }

        $this->notify_solicitante($solicitud, $comentario);
        json_output(['success' => true, 'message' => 'Solicitud #' . $id . ' rechazada.']);
    }

    public function pay($id): void
    {
        $this->guard([ExtraPaymentWorkflow::PERM_TESORERIA], true);
        $id = (int) $id;

        $solicitud = $this->requestsModel->get($id);
        if (!$solicitud || (int) $solicitud['status'] !== ExtraPaymentWorkflow::ST_AUTORIZADA) {
            json_output(['success' => false, 'message' => self::MSG_CAMBIO_ESTADO]);
        }

        $cuentaId = (int) ($_POST['bank_account_id'] ?? 0);
        $cuenta = null;
        foreach ($this->cuentas_pago() as $c) {
            if ((int) $c['Id'] === $cuentaId) {
                $cuenta = $c;
                break;
            }
        }
        if ($cuenta === null) {
            json_output(['success' => false, 'message' => 'Seleccione una cuenta bancaria activa.']);
        }
        $fecha = $this->fecha($_POST['fecha_pago'] ?? null);
        if ($fecha === null) {
            json_output(['success' => false, 'message' => 'La fecha de pago es inválida.']);
        }
        $referencia = $this->texto($_POST['referencia'] ?? '', self::LIM_REFERENCIA);
        if ($referencia === '') {
            json_output(['success' => false, 'message' => 'La referencia del pago es obligatoria.']);
        }

        if (!$this->requestsModel->register_payment($id, $this->user_id(), $cuentaId, $fecha, $referencia)) {
            json_output(['success' => false, 'message' => self::MSG_CAMBIO_ESTADO]);
        }
        // Se devuelve el id para que Tesorería llegue al detalle y adjunte el comprobante (la bandeja solo lista estatus 3).
        json_output([
            'success' => true,
            'message' => 'Pago de la solicitud #' . $id . ' registrado. Suba el comprobante en el detalle.',
            'id'      => $id,
        ]);
    }

    /**
     * Carga la solicitud y re-valida en servidor que el usuario tenga el permiso
     * del estatus ACTUAL (leído de la BD; jamás de POST). Solo 1 y 2 son
     * aprobables/rechazables. Corta con 403/409 si no procede.
     */
    private function solicitud_para_decidir(int $id, bool $json): array
    {
        $solicitud = $this->requestsModel->get($id);
        if (!$solicitud) {
            // Mismo 403 que una sin permiso: no revela qué ids existen.
            $this->fail(403, 'No tiene permiso para decidir sobre esta solicitud.', $json);
        }
        $status = (int) $solicitud['status'];
        $requerido = ExtraPaymentWorkflow::required_permission($status);
        if ($requerido === null || $requerido === ExtraPaymentWorkflow::PERM_TESORERIA) {
            // Ya no está en una etapa de decisión (la pagó/rechazó/canceló alguien más).
            if (!authorized(ExtraPaymentWorkflow::PERM_CXP) && !authorized(ExtraPaymentWorkflow::PERM_ADMON)) {
                $this->fail(403, 'No tiene permiso para decidir sobre esta solicitud.', $json);
            }
            $this->fail(409, self::MSG_CAMBIO_ESTADO, $json);
        }
        if (!authorized($requerido)) {
            $this->fail(403, 'No tiene permiso para decidir sobre esta solicitud.', $json);
        }
        return $solicitud;
    }

    /** Cuentas propias y activas desde las que Tesorería puede pagar. */
    private function cuentas_pago(): array
    {
        $todas = (new CuentasBancariasModel())->get_cuentas() ?: [];
        return array_values(array_filter($todas, fn($c) => ($c['Tipo'] ?? '') === 'Propias'));
    }

    /* ===================================================================== */
    /* Correos                                                               */
    /* ===================================================================== */

    /**
     * Avisa por correo a los destinatarios activos del evento. Un fallo se
     * registra pero nunca revierte ni rompe la transición ya confirmada.
     */
    private function notify(int $id, string $evento, string $asunto): void
    {
        try {
            $destinatarios = (new PaymentNotificationRecipientsModel())->get_active_emails($evento);
            if (!$destinatarios) {
                return;
            }
            $solicitud = $this->requestsModel->get($id);
            if (!$solicitud) {
                return;
            }
            $this->enviar($destinatarios, $asunto, $solicitud, '');
        } catch (Throwable $e) {
            error_log('ExtraPayments::notify(' . $evento . ', #' . $id . '): ' . $e->getMessage());
        }
    }

    /** Avisa al solicitante del rechazo (Usuario.Correo; sin correo válido se omite). */
    private function notify_solicitante(array $solicitud, string $motivoRechazo): void
    {
        try {
            $rows = $this->requestsModel->sql->select(
                'SELECT Correo FROM [TG].[dbo].[Usuario] WHERE Id = ?',
                [(int) $solicitud['user_id']]
            );
            $correo = trim((string) ($rows[0]['Correo'] ?? ''));
            if ($correo === '' || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                return;
            }
            $this->enviar(
                [$correo],
                'Pago extraordinario #' . $solicitud['id'] . ' rechazado',
                $solicitud,
                '<p><strong>Motivo del rechazo:</strong> ' . htmlspecialchars($motivoRechazo, ENT_QUOTES, 'UTF-8') . '</p>'
                . '<p>Puede corregirla y reenviarla desde el detalle.</p>'
            );
        } catch (Throwable $e) {
            error_log('ExtraPayments::notify_solicitante(#' . ($solicitud['id'] ?? '?') . '): ' . $e->getMessage());
        }
    }

    /** Arma y envía el correo; todo texto de usuario va escapado. */
    private function enviar(array $destinatarios, string $asunto, array $solicitud, string $extraHtml): void
    {
        $e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $link = rtrim(URL, '/\\') . Router::reverse('extrapayments.detail', ['id' => (int) $solicitud['id']]);
        $motivo = $e($solicitud['reason_nombre'] ?? '');
        if (!empty($solicitud['motivo_otros'])) {
            $motivo .= ': ' . $e($solicitud['motivo_otros']);
        }
        $body = '<p>Pago extraordinario <strong>#' . (int) $solicitud['id'] . '</strong></p>'
            . '<ul>'
            . '<li>Solicitante: ' . $e($solicitud['solicitante'] ?? '') . '</li>'
            . '<li>Total: $' . number_format((float) $solicitud['total'], 2) . '</li>'
            . '<li>Motivo: ' . $motivo . '</li>'
            . '</ul>'
            . $extraHtml
            . '<p><a href="' . $e($link) . '">Abrir la solicitud</a></p>';

        $err = null;
        if (!send_mail_with_fallback($asunto, $body, $destinatarios, 'totalgasdesarrollo@gmail.com', false, false, $err)) {
            error_log('ExtraPayments: no se pudo enviar el correo "' . $asunto . '": ' . (is_scalar($err) ? $err : ''));
        }
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
