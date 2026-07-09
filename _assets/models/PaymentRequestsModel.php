<?php
class PaymentRequestsModel extends Model
{
    public $id;
    public $request_date;
    public $user_id;
    public $comment;
    public $status;
    public $provider_cod;
    public $date_added;
    public $emp_cod;
    public $tipo; // 0: Pago, 1: Anticipo
    public $monto_total; // NUEVO CAMPO
    public $total_notas_credito;
    public $total_notas_cargo;
    public $scheduled_payment_date; // Fecha deseada de pago


    const STATUS_PENDING = 0;
    const STATUS_AUTHORIZED = 1;
    const STATUS_PAID = 2;
    const STATUS_CANCELLED = 3;


    public function create_payment_with_invoices($user_id, $documents, $comment = 'Pago programado', $provider_cod = null, $empresa_cod = null, $monto_total = 0, $scheduled_payment_date = null, $pending_notes = []): array
    {
        if ((empty($documents) || !is_array($documents)) && empty($pending_notes)) {
            return [
                'success' => false,
                'message' => 'No hay documentos ni notas de cargo para procesar'
            ];
        }
        if (!is_array($documents)) {
            $documents = [];
        }

        $this->sql->beginTransaction();

        try {
            // 1. Crear solicitud de pago con STATUS_PENDING (0)
            $request_date = date('Y-m-d H:i:s');
            $status = self::STATUS_PENDING;

            $query = 'INSERT INTO [TG].[dbo].[payment_requests]
                    (request_date, user_id, comment, [status],  provider_cod, emp_cod, monto_total, scheduled_payment_date)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?);';

            $payment_id = $this->sql->insert($query, [$request_date, $user_id, $comment, $status, $provider_cod, $empresa_cod, $monto_total, $scheduled_payment_date]);

            if (!$payment_id) {
                throw new Exception('Error al crear la solicitud de pago');
            }

            // 2. Insertar facturas asociadas directamente
            $query2 = '
                INSERT INTO [TG].[dbo].[payment_request_invoices]
                (payment_request_id, folio, invoice_number, codgas, amount, status, expiration_date, uuid, is_debit_note)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ';

            foreach ($documents as $doc) {
                $folio          = $doc['nro'] ?? null;
                $invoice_number = $doc['Factura'] ?? null;
                $codgas         = $doc['codgas'] ?? null;
                // Preferir el total efectivo (FacturasRecibidas si existe, si no ControlGas)
                $amount         = $doc['total_mostrar'] ?? ($doc['total_fac'] ?? 0);
                $expiration_date = $doc['fechaVto'] ?? null;
                $status         = self::STATUS_PENDING;
                $uuid           = $doc['satuid'] ?? null;

                if (!$this->sql->insert($query2, [
                    $payment_id, $folio, $invoice_number, $codgas,
                    $amount, $status, $expiration_date, $uuid, 0
                ])) {
                    throw new Exception('Error al insertar factura');
                }
            }

            // 2b. Insertar notas de cargo independientes (DEBIT sin invoice_temp_key) como filas de pago
            foreach ($pending_notes as $note) {
                if (($note['note_type'] ?? '') !== 'DEBIT') continue;
                if (($note['invoice_temp_key'] ?? null) !== null) continue;

                $note_number = $note['note_number'] ?? ('ND-' . $note['credit_note_id']);
                $amount      = (float)($note['applied_amount'] ?? 0);

                if (!$this->sql->insert($query2, [
                    $payment_id, $note_number, $note_number, null,
                    $amount, self::STATUS_PENDING, null, null, 1
                ])) {
                    throw new Exception('Error al insertar nota de cargo como factura');
                }
            }

            // 3. Aplicar notas de crédito/cargo si se enviaron
            if (!empty($pending_notes)) {
                // Construir mapa folio__codgas → invoice_id de las facturas recién insertadas
                $ids_query = "
                    SELECT id, folio, codgas
                    FROM [TG].[dbo].[payment_request_invoices]
                    WHERE payment_request_id = ? AND is_deleted = 0";
                $inserted_invoices = $this->sql->select($ids_query, [$payment_id]);
                $invoice_map = [];
                foreach ($inserted_invoices as $row) {
                    $invoice_map[$row['folio'] . '__' . $row['codgas']] = $row['id'];
                }

                $note_query = "
                    INSERT INTO [tg].[dbo].[credit_note_applications]
                        (credit_note_id, payment_request_id, invoice_id, applied_amount, created_by, status)
                    VALUES (?, ?, ?, ?, ?, 1)";

                foreach ($pending_notes as $note) {
                    $temp_key   = $note['invoice_temp_key'] ?? null;
                    $invoice_id = $temp_key ? ($invoice_map[$temp_key] ?? null) : null;

                    $this->sql->insert($note_query, [
                        $note['credit_note_id'],
                        $payment_id,
                        $invoice_id,
                        $note['applied_amount'],
                        $user_id
                    ]);
                }

                // Actualizar totales de notas y monto_total en el pago
                $totals_query = "
                    UPDATE [TG].[dbo].[payment_requests]
                    SET total_notas_credito = (
                        SELECT ISNULL(SUM(a.applied_amount), 0)
                        FROM [tg].[dbo].[credit_note_applications] a
                        INNER JOIN [tg].[dbo].[invoice_credit_debit_notes] n ON a.credit_note_id = n.id
                        WHERE a.payment_request_id = ? AND a.status = 1 AND n.note_type = 'CREDIT'
                    ),
                    total_notas_cargo = (
                        SELECT ISNULL(SUM(a.applied_amount), 0)
                        FROM [tg].[dbo].[credit_note_applications] a
                        INNER JOIN [tg].[dbo].[invoice_credit_debit_notes] n ON a.credit_note_id = n.id
                        WHERE a.payment_request_id = ? AND a.status = 1 AND n.note_type = 'DEBIT'
                    ),
                    monto_total = (
                        SELECT ISNULL(SUM(pri.amount), 0)
                        FROM [TG].[dbo].[payment_request_invoices] pri
                        WHERE pri.payment_request_id = ? AND pri.is_deleted = 0
                    )
                    WHERE id = ?";
                $this->sql->update($totals_query, [$payment_id, $payment_id, $payment_id, $payment_id]);
            }

            // 4. Commit
            $this->sql->commit();

            return [
                'success' => true,
                'payment_id' => $payment_id,
                'total_documents' => count($documents),
                'message' => 'Pago programado creado exitosamente'
            ];
        } catch (Exception $e) {
            $this->sql->rollback();

            return [
                'success' => false,
                'message' => 'Error al procesar el pago: ' . $e->getMessage()
            ];
        }
    }

    public function get_request_by_id($id): array|false
    {
        $query = 'SELECT 
                    t1.*,
                    t2.Nombre as [usuario_nombre]
                    FROM [TG].[dbo].[payment_requests] t1
                    left join TG.dbo.Usuario t2 on t1.[user_id] = t2.[Id]
                    WHERE t1.id = ?;';
        return ($rs = $this->sql->select($query, [$id])) ? $rs[0] : false;
    }


    public function insert_request($request_date, $user_id, $comment, $status): int|false
    {
        $query = 'INSERT INTO 
                    [TG].[dbo].[payment_requests] 
                    (request_date, user_id, comment, [status])
                    VALUES (?, ?, ?, ?);';

        $insert = $this->sql->insert($query, [$request_date, $user_id, $comment, $status]);

        return $insert ?: false;
    }

    public function update_request_status($id, $status, $comment = null): bool
    {
        $query = 'UPDATE [TG].[dbo].[payment_requests] SET status = ?, comment = ISNULL(?, comment) WHERE id = ?;';
        return $this->sql->update($query, [$status, $comment, $id]);
    }

    public function delete_request($id): bool
    {
        $query = 'DELETE FROM [TG].[dbo].[payment_requests] WHERE id = ?;';
        return $this->sql->delete($query, [$id]);
    }

    public function delete_payment_complete($payment_id): array
    {
        $this->sql->beginTransaction();

        try {
            // 1. Eliminar autorizaciones
            $authModel = new PaymentRequestAuthorizationsModel();
            $authModel->delete_by_payment_request($payment_id);

            // 2. Eliminar facturas
            $invoicesModel = new PaymentRequestInvoicesModel();
            $invoicesModel->delete_by_payment_request($payment_id);

            // 3. Eliminar solicitud
            $this->delete_request($payment_id);

            // 4. Commit
            $this->sql->commit();

            return [
                'success' => true,
                'message' => 'Pago eliminado exitosamente'
            ];
        } catch (Exception $e) {
            $this->sql->rollback();

            return [
                'success' => false,
                'message' => 'Error al eliminar el pago: ' . $e->getMessage()
            ];
        }
    }
    public function get_requests_with_summary($type, $status = 'all', $search = ''): array|false
    {
        $whereClauses = ["t1.is_deleted = 0"];
        $params = [];

        if ($status !== 'all') {
            $statusMap = [
                'pending' => self::STATUS_PENDING,
                'authorized' => self::STATUS_AUTHORIZED,
                'paid' => self::STATUS_PAID,
                'cancelled' => self::STATUS_CANCELLED
            ];

            if (isset($statusMap[$status])) {
                $whereClauses[] = "t1.status = ?";
                $params[] = $statusMap[$status];
            }
        }

        if ($type === 'payment') {
            $whereClauses[] = "t1.tipo IN (0)";
        } elseif ($type === 'anticipos') {
            $whereClauses[] = "t1.tipo NOT IN (0)";
        }
        // type === 'all' → sin filtro de tipo, devuelve pagos y anticipos

        $search = trim($search);
        if ($search !== '') {
            $whereClauses[] = "(CAST(t1.id AS VARCHAR(20)) LIKE ? OR t5.den LIKE ? OR t6.den LIKE ? OR t1.comment LIKE ?)";
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like);
        }

        $whereSQL = !empty($whereClauses)
            ? "WHERE " . implode(" AND ", $whereClauses)
            : "";

        $query = "
            SELECT
                t1.id,
                t1.user_id,
                t1.request_date,
                t1.scheduled_payment_date,
                t1.status,
                t1.comment,
                t1.tipo,
                ISNULL(t1.monto_total, 0) AS monto_total,
                t3.Nombre AS usuario_nombre,
                -- Pagado de anticipos: transacciones directas del request, sin factura
                ISNULL((
                    SELECT SUM(pt.payment_amount)
                    FROM [TG].[dbo].[payment_transactions] pt
                    WHERE pt.payment_request_id = t1.id
                      AND pt.invoice_id IS NULL
                      AND pt.status IN (1, 2)
                ), 0) AS anticipo_paid,
                -- Resumen de facturas
                ISNULL(t2.total_invoices, 0) AS total_invoices,
                ISNULL(t2.total_amount, 0)   AS total_amount,
                ISNULL(t2.total_paid, 0)     AS total_paid,
                ISNULL(t2.authorized_invoices_count, 0) AS authorized_invoices_count,
                ISNULL(t2.authorized_amount_total, 0)   AS authorized_amount_total,
                -- Notas de crédito y cargo
                ISNULL(t1.total_notas_credito, 0) AS total_notas_credito,
                ISNULL(t1.total_notas_cargo, 0)   AS total_notas_cargo,
                -- Indicador PDF:
                -- 'complete' = todas las facturas normales tienen PDF (notas de cargo cuentan como OK)
                -- 'missing'  = al menos una factura normal sin PDF
                -- 'no_invoices' = sin filas en payment_request_invoices
                CASE
                    WHEN ISNULL(t2.total_invoices, 0) = 0 THEN 'no_invoices'
                    WHEN EXISTS (
                        SELECT 1 FROM [TG].[dbo].[payment_request_invoices] pri
                        LEFT JOIN [TG].[dbo].[FacturasRecibidas] fr
                            ON pri.uuid COLLATE DATABASE_DEFAULT = fr.UUID COLLATE DATABASE_DEFAULT
                            AND fr.RutaArchivo IS NOT NULL AND fr.RutaArchivo != ''
                        WHERE pri.payment_request_id = t1.id
                          AND pri.is_debit_note = 0
                          AND pri.is_deleted = 0
                          AND fr.UUID IS NULL
                    ) THEN 'missing'
                    ELSE 'complete'
                END AS pdf_status,
                -- Autorizaciones por nivel
                ISNULL(t4.auth_abastos, 0)       AS auth_abastos,
                ISNULL(t4.auth_contabilidad, 0)  AS auth_contabilidad,
                ISNULL(t4.auth_admin, 0)         AS auth_admin,
                ISNULL(t4.auth_tesoreria, 0)     AS auth_tesoreria,
                -- Información de autorizadores
                t4.auth_abastos_user,
                t4.auth_contabilidad_user,
                t4.auth_admin_user,
                t4.auth_tesoreria_user,
                -- Fechas de autorización
                t4.auth_abastos_date,
                t4.auth_contabilidad_date,
                t4.auth_admin_date,
                t4.auth_tesoreria_date,
                ISNULL(t4.total_authorizations, 0) AS total_authorizations,
                t5.den AS provider_name,
                t6.den AS emp_name
            FROM [TG].[dbo].[payment_requests] t1
            LEFT JOIN (
                SELECT
                    payment_request_id,
                    COUNT(*) AS total_invoices,
                    SUM(amount) AS total_amount,
                    SUM(paid_amount) AS total_paid,
                    SUM(CASE WHEN payment_authorized = 1 THEN 1 ELSE 0 END) AS authorized_invoices_count,
                    SUM(CASE WHEN payment_authorized = 1 THEN ISNULL(authorized_amount, 0) ELSE 0 END) AS authorized_amount_total
                FROM tg.dbo.payment_request_invoices
                WHERE is_deleted = 0
                GROUP BY payment_request_id
            ) t2 ON t1.id = t2.payment_request_id
            LEFT JOIN [TG].[dbo].[Usuario] t3 ON t1.user_id = t3.Id
            -- === Agregado de autorizaciones ===
            LEFT JOIN (
                SELECT
                    pra.payment_request_id,
                    MAX(CASE WHEN pra.permission_number = 66 THEN 1 ELSE 0 END) AS auth_abastos,
                    MAX(CASE WHEN pra.permission_number = 70 THEN 1 ELSE 0 END) AS auth_contabilidad,
                    MAX(CASE WHEN pra.permission_number = 67 THEN 1 ELSE 0 END) AS auth_admin,
                    MAX(CASE WHEN pra.permission_number = 68 THEN 1 ELSE 0 END) AS auth_tesoreria,
                    MAX(CASE WHEN pra.permission_number = 66 THEN u.Nombre END) AS auth_abastos_user,
                    MAX(CASE WHEN pra.permission_number = 70 THEN u.Nombre END) AS auth_contabilidad_user,
                    MAX(CASE WHEN pra.permission_number = 67 THEN u.Nombre END) AS auth_admin_user,
                    MAX(CASE WHEN pra.permission_number = 68 THEN u.Nombre END) AS auth_tesoreria_user,
                    MAX(CASE WHEN pra.permission_number = 66 THEN pra.authorization_date END) AS auth_abastos_date,
                    MAX(CASE WHEN pra.permission_number = 70 THEN pra.authorization_date END) AS auth_contabilidad_date,
                    MAX(CASE WHEN pra.permission_number = 67 THEN pra.authorization_date END) AS auth_admin_date,
                    MAX(CASE WHEN pra.permission_number = 68 THEN pra.authorization_date END) AS auth_tesoreria_date,
                    COUNT(*) AS total_authorizations
                FROM tg.dbo.payment_request_authorizations pra
                LEFT JOIN tg.dbo.Usuario u
                    ON pra.staff_user_id = u.Id
                GROUP BY pra.payment_request_id
            ) t4 ON t1.id = t4.payment_request_id
            LEFT JOIN [SG12].[dbo].Proveedores t5 ON t1.provider_cod = t5.cod
            LEFT JOIN [SG12].[dbo].Empresas t6 ON t1.emp_cod = t6.cod
            $whereSQL
            ORDER BY t1.request_date DESC
        ";
        return $this->sql->select($query, $params) ?: false;
    }


    /**
     * Pagos listos para SOLICITAR su pago: status Pendiente (0), tipo pago (0),
     * con TODAS sus facturas con PDF recibido (equivalente al "dot verde").
     * Usado por el botón "Mandar pagos" y la futura tarea programada.
     */
    public function get_payments_ready_for_request(): array
    {
        $query = "
            SELECT
                t1.id,
                t1.request_date,
                t1.scheduled_payment_date,
                t1.comment,
                ISNULL(t2.total_invoices, 0)  AS total_invoices,
                ISNULL(t2.total_amount, 0)    AS total_amount,
                ISNULL(t3.total_credito, 0)   AS total_notas_credito,
                ISNULL(t3.total_cargo, 0)     AS total_notas_cargo,
                ISNULL(t2.total_amount, 0)
                    - ISNULL(t3.total_credito, 0)
                    + ISNULL(t3.total_cargo, 0) AS monto_neto,
                t5.den AS provider_name,
                t6.den AS emp_name
            FROM [TG].[dbo].[payment_requests] t1
            LEFT JOIN (
                SELECT payment_request_id, COUNT(*) AS total_invoices, SUM(amount) AS total_amount
                FROM [TG].[dbo].[payment_request_invoices]
                WHERE is_deleted = 0
                GROUP BY payment_request_id
            ) t2 ON t1.id = t2.payment_request_id
            LEFT JOIN (
                SELECT
                    cna.payment_request_id,
                    SUM(CASE WHEN n.note_type = 'CREDIT' THEN cna.applied_amount ELSE 0 END) AS total_credito,
                    SUM(CASE WHEN n.note_type = 'DEBIT'  THEN cna.applied_amount ELSE 0 END) AS total_cargo
                FROM [TG].[dbo].[credit_note_applications] cna
                JOIN [TG].[dbo].[invoice_credit_debit_notes] n ON cna.credit_note_id = n.id
                GROUP BY cna.payment_request_id
            ) t3 ON t1.id = t3.payment_request_id
            LEFT JOIN [SG12].[dbo].Proveedores t5 ON t1.provider_cod = t5.cod
            LEFT JOIN [SG12].[dbo].Empresas    t6 ON t1.emp_cod = t6.cod
            WHERE t1.tipo IN (0)
              AND t1.is_deleted = 0
              AND t1.status = " . self::STATUS_PENDING . "
              AND ISNULL(t2.total_invoices, 0) > 0
              -- Que NO exista ninguna factura normal sin archivo (notas de cargo is_debit_note=1 se omiten)
              AND NOT EXISTS (
                    SELECT 1 FROM [TG].[dbo].[payment_request_invoices] pri
                    LEFT JOIN [TG].[dbo].[FacturasRecibidas] fr
                        ON pri.uuid COLLATE DATABASE_DEFAULT = fr.UUID COLLATE DATABASE_DEFAULT
                        AND fr.RutaArchivo IS NOT NULL AND fr.RutaArchivo != ''
                    WHERE pri.payment_request_id = t1.id
                      AND pri.is_debit_note = 0
                      AND pri.is_deleted = 0
                      AND fr.UUID IS NULL
              )
            ORDER BY t1.request_date ASC";

        return $this->sql->select($query, []) ?: [];
    }


    public function create_anticipo($data): array
    {
        // Validar datos requeridos
        if (empty($data['provider_cod']) || empty($data['empresa_cod']) || empty($data['monto_total'])) {
            return [
                'success' => false,
                'message' => 'Faltan datos requeridos para crear el anticipo'
            ];
        }
        $this->sql->beginTransaction();
        try {
            // Preparar datos para inserción
            $request_date = date('Y-m-d H:i:s');
            $user_id = $data['user_id'];
            $provider_cod = $data['provider_cod'];
            $empresa_cod = $data['empresa_cod'];
            $monto_total = $data['monto_total'];
            $nombre_request = $data['nombre_request'] ?? 'ANTICIPO';
            $comentario = $data['comentario'] ?? '';
            $scheduled_payment_date = $data['scheduled_payment_date'] ?? null;
            $tipo = 1; // 1 = Anticipo
            $status = self::STATUS_PENDING; // 0 = Pendiente

            // Query de inserción
            $query = 'INSERT INTO [TG].[dbo].[payment_requests]
                    (request_date, user_id, comment, [status], provider_cod, emp_cod, tipo, monto_total, scheduled_payment_date)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?);';

            $params = [
                $request_date,
                $user_id,
                $comentario,
                $status,
                $provider_cod,
                $empresa_cod,
                $tipo,
                $monto_total,
                $scheduled_payment_date
            ];

            // Insertar anticipo
            $anticipo_id = $this->sql->insert($query, $params);

            if (!$anticipo_id) {
                throw new Exception('Error al insertar el anticipo en la base de datos');
            }

            // Commit de la transacción
            $this->sql->commit();

            return [
                'success' => true,
                'anticipo_id' => $anticipo_id,
                'message' => 'Anticipo creado exitosamente'
            ];
        } catch (Exception $e) {
            // Rollback en caso de error
            $this->sql->rollback();

            // Log del error
            error_log('Error en create_anticipo: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());

            return [
                'success' => false,
                'message' => 'Error al crear el anticipo: ' . $e->getMessage()
            ];
        }
    }

    public function get_all_requests(): array|false
    {
        $query = 'SELECT id, request_date, user_id, comment, status, date_added FROM [TG].[dbo].[payment_requests] WHERE is_deleted = 0 ORDER BY request_date DESC;';
        return ($this->sql->select($query)) ?: false;
    }

    public static function getStatusText($status): string
    {
        switch ($status) {
            case self::STATUS_PENDING:
                return 'Pendiente';
            case self::STATUS_AUTHORIZED:
                return 'Autorizado';
            case self::STATUS_PAID:
                return 'Pagado';
            case self::STATUS_CANCELLED:
                return 'Cancelado';
            default:
                return 'Desconocido';
        }
    }


    public static function getStatusBadge($status): string
    {
        switch ($status) {
            case self::STATUS_PENDING:
                return '<span class="badge" style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;font-weight:600;">Pendiente</span>';
            case self::STATUS_AUTHORIZED:
                return '<span class="badge" style="background:#dbeafe;color:#1e40af;border:1px solid #bfdbfe;font-weight:600;">Autorizado</span>';
            case self::STATUS_PAID:
                return '<span class="badge" style="background:#d1fae5;color:#065f46;border:1px solid #a7f3d0;font-weight:600;">Pagado</span>';
            case self::STATUS_CANCELLED:
                return '<span class="badge" style="background:#ffe4e6;color:#9f1239;border:1px solid #fecdd3;font-weight:600;">Cancelado</span>';
            default:
                return '<span class="badge" style="background:#f1f5f9;color:#475569;border:1px solid #cbd5e1;font-weight:600;">Desconocido</span>';
        }
    }

    public function get_anticipos_with_summary($type  = 'payment', $status = 'all'): array|false
    {
        $whereClauses = ["t1.tipo = 1", "t1.is_deleted = 0"]; // Solo anticipos activos
        $params = [];

        if ($status !== 'all') {
            $statusMap = [
                'pending' => self::STATUS_PENDING,
                'authorized' => self::STATUS_AUTHORIZED,
                'paid' => self::STATUS_PAID,
                'cancelled' => self::STATUS_CANCELLED
            ];

            if (isset($statusMap[$status])) {
                $whereClauses[] = "t1.status = ?";
                $params[] = $statusMap[$status];
            }
        }
        if ($type === 'payment') {
            $whereClauses[] = "t1.tipo IN (0)"; // Pendiente y Autorizado
        } elseif ($type === 'anticipos') {
            $whereClauses[] = "t1.tipo NOT IN (0)"; // Pagado y Cancelado
        }


        $whereSQL = "WHERE " . implode(" AND ", $whereClauses);

        $query = "
                        SELECT 
                t1.id,
                t1.user_id,
                t1.request_date,
                t1.status,
                t1.comment,
                t3.Nombre AS usuario_nombre,
                -- Resumen de facturas
                ISNULL(t2.total_invoices, 0) AS total_invoices,
                ISNULL(t2.total_amount, 0)   AS total_amount,
                ISNULL(t1.monto_total, 0)   AS monto_total,
                ISNULL(t2.total_paid, 0)     AS total_paid,
                ISNULL(t2.authorized_invoices_count, 0) AS authorized_invoices_count,
                ISNULL(t2.authorized_amount_total, 0)   AS authorized_amount_total,
                -- Autorizaciones por nivel
                ISNULL(t4.auth_abastos, 0)       AS auth_abastos,
                ISNULL(t4.auth_contabilidad, 0)  AS auth_contabilidad,
                ISNULL(t4.auth_admin, 0)         AS auth_admin,
                ISNULL(t4.auth_tesoreria, 0)     AS auth_tesoreria,
                -- Información de autorizadores
                t4.auth_abastos_user,
                t4.auth_contabilidad_user,
                t4.auth_admin_user,
                t4.auth_tesoreria_user,
                -- Fechas de autorización
                t4.auth_abastos_date,
                t4.auth_contabilidad_date,
                t4.auth_admin_date,
                t4.auth_tesoreria_date,
                ISNULL(t4.total_authorizations, 0) AS total_authorizations,
                t5.den AS provider_name,
                t6.den AS emp_name
            FROM [TG].[dbo].[payment_requests] t1
            LEFT JOIN (
                SELECT
                    payment_request_id,
                    COUNT(*) AS total_invoices,
                    SUM(amount) AS total_amount,
                    SUM(paid_amount) AS total_paid,
                    SUM(CASE WHEN payment_authorized = 1 THEN 1 ELSE 0 END) AS authorized_invoices_count,
                    SUM(CASE WHEN payment_authorized = 1 THEN ISNULL(authorized_amount, 0) ELSE 0 END) AS authorized_amount_total
                FROM tg.dbo.payment_request_invoices
                WHERE is_deleted = 0
                GROUP BY payment_request_id
            ) t2 ON t1.id = t2.payment_request_id
            LEFT JOIN [TG].[dbo].[Usuario] t3 ON t1.user_id = t3.Id
            -- === Agregado de autorizaciones ===
            LEFT JOIN (
                SELECT
                    pra.payment_request_id,
                    MAX(CASE WHEN pra.permission_number = 66 THEN 1 ELSE 0 END) AS auth_abastos,
                    MAX(CASE WHEN pra.permission_number = 70 THEN 1 ELSE 0 END) AS auth_contabilidad,
                    MAX(CASE WHEN pra.permission_number = 67 THEN 1 ELSE 0 END) AS auth_admin,
                    MAX(CASE WHEN pra.permission_number = 68 THEN 1 ELSE 0 END) AS auth_tesoreria,
                    MAX(CASE WHEN pra.permission_number = 66 THEN u.Nombre END) AS auth_abastos_user,
                    MAX(CASE WHEN pra.permission_number = 70 THEN u.Nombre END) AS auth_contabilidad_user,
                    MAX(CASE WHEN pra.permission_number = 67 THEN u.Nombre END) AS auth_admin_user,
                    MAX(CASE WHEN pra.permission_number = 68 THEN u.Nombre END) AS auth_tesoreria_user,
                    MAX(CASE WHEN pra.permission_number = 66 THEN pra.authorization_date END) AS auth_abastos_date,
                    MAX(CASE WHEN pra.permission_number = 70 THEN pra.authorization_date END) AS auth_contabilidad_date,
                    MAX(CASE WHEN pra.permission_number = 67 THEN pra.authorization_date END) AS auth_admin_date,
                    MAX(CASE WHEN pra.permission_number = 68 THEN pra.authorization_date END) AS auth_tesoreria_date,
                    COUNT(*) AS total_authorizations
                FROM tg.dbo.payment_request_authorizations pra
                LEFT JOIN tg.dbo.Usuario u
                    ON pra.staff_user_id = u.Id
                GROUP BY pra.payment_request_id
            ) t4 ON t1.id = t4.payment_request_id
            LEFT JOIN [SG12].[dbo].Proveedores t5 ON t1.provider_cod = t5.cod
            LEFT JOIN [SG12].[dbo].Empresas t6 ON t1.emp_cod = t6.cod
            $whereSQL
            ORDER BY t1.request_date DESC
        ";
        return $this->sql->select($query, $params) ?: false;
    }


    public function get_anticipo_applications($anticipo_id)
    {
        try {
            $query = "
                 SELECT 
                    t1.id,
                    t1.anticipo_id,
                    t1.invoice_id,
                    t1.monto_aplicado,
                    t1.fecha_aplicacion,
                    t1.aplicado_por,
                    t1.notas,           
                    -- Información de la factura
                    t2.folio,
                    t2.invoice_number,
                    t2.amount as monto_factura,
                    t2.payment_request_id,
                    -- Información del pago asociado a la factura
                    t3.id as pago_id,
                    t3.request_date as pago_fecha,
                    t3.status as pago_status,
                    t3.comment as pago_comentario,
                    -- Estación
                    t6.abr as estacion_nombre,
                    -- Usuario que lo aplicó
                    t4.Nombre as aplicado_por_nombre,
                    -- Proveedor
                    t7.den as proveedor_nombre,
                    -- Empresa
                    t5.den as empresa_nombre,
                    -- Archivo de la factura (CFDI recibido)
                    fr.Id as fr_id,
                    fr.NombreArchivo as nombre_archivo
                FROM [TG].[dbo].[anticipo_invoice_applications] t1
                LEFT JOIN [TG].[dbo].[payment_request_invoices] t2 ON t1.invoice_id = t2.id
                LEFT JOIN [TG].[dbo].[payment_requests] t3 ON t2.payment_request_id = t3.id
                LEFT JOIN TG.[dbo].[Usuario] t4 ON t1.aplicado_por = t4.Id
                LEFT JOIN [SG12].[dbo].[Empresas] t5 ON t3.emp_cod = t5.cod
                LEFT JOIN [SG12].[dbo].Gasolineras t6 ON t2.codgas = t6.cod
                LEFT JOIN [SG12].[dbo].[Proveedores] t7 ON t3.provider_cod = t7.cod
                LEFT JOIN [TG].[dbo].[FacturasRecibidas] fr
                    ON t2.uuid COLLATE DATABASE_DEFAULT = fr.UUID COLLATE DATABASE_DEFAULT
                    AND fr.RutaArchivo IS NOT NULL AND fr.RutaArchivo != ''
                WHERE t1.anticipo_id = ?
                ORDER BY t1.fecha_aplicacion DESC
            ";

            $params = [$anticipo_id];
            return $this->sql->select($query, $params) ?: [];
        } catch (Exception $e) {
            error_log("Error en get_anticipo_applications: " . $e->getMessage());
            return [];
        }
    }



    public function get_anticipo_summary($anticipo_id)
    {
        try {
            $query = "
                SELECT 
                    pr.id,
                    pr.monto_total as monto_original,
                    ISNULL(SUM(aa.monto_aplicado), 0) as total_aplicado,
                    (pr.monto_total - ISNULL(SUM(aa.monto_aplicado), 0)) as saldo_disponible,
                    COUNT(aa.id) as total_aplicaciones
                FROM [TG].[dbo].[payment_requests] pr
                LEFT JOIN [TG].[dbo].[anticipo_invoice_applications] aa ON pr.id = aa.anticipo_id
                WHERE pr.id = ?
                GROUP BY pr.id, pr.monto_total
            ";

            $params = [$anticipo_id];
            $result = $this->sql->select($query, $params);

            return $result ? $result[0] : null;
        } catch (Exception $e) {
            error_log("Error en get_anticipo_summary: " . $e->getMessage());
            return null;
        }
    }

    public function get_saldo_disponible($anticipo_id)
    {
        $summary = $this->get_anticipo_summary($anticipo_id);
        return $summary ? $summary['saldo_disponible'] : 0;
    }

    /**
     * Verificar si un anticipo tiene saldo disponible
     */
    public function anticipo_has_balance($anticipo_id, $monto_requerido = 0)
    {
        try {
            $summary = $this->get_anticipo_summary($anticipo_id);

            if (!$summary) {
                return false;
            }

            $saldo = $summary['saldo_disponible'];

            if ($monto_requerido > 0) {
                return $saldo >= $monto_requerido;
            }

            return $saldo > 0;
        } catch (Exception $e) {
            error_log("Error en anticipo_has_balance: " . $e->getMessage());
            return false;
        }
    }

    public function getPaymentAuthorizations($payment_id)
    {
        $query = "
            SELECT 
                pra.id,
                pra.payment_request_id,
                pra.staff_user_id,
                pra.permission_number,
                pra.authorization_date,
                u.Nombre as autorizador_nombre,
                CASE
                    WHEN pra.permission_number = 66 THEN 'Abastos'
                    WHEN pra.permission_number = 70 THEN 'Contabilidad'
                    WHEN pra.permission_number = 67 THEN 'Administración y Finanzas'
                    WHEN pra.permission_number = 68 THEN 'Tesorería'
                    ELSE 'Desconocido'
                END as departamento
            FROM [TG].[dbo].[payment_request_authorizations] pra
            LEFT JOIN [TG].[dbo].[Usuario] u ON pra.staff_user_id = u.Id
            WHERE pra.payment_request_id = ?
            ORDER BY pra.permission_number ASC
        ";

        return $this->sql->select($query, [$payment_id]) ?: [];
    }

    /**
     * Obtener estado de autorizaciones
     */
    public function getAuthorizationStatus($payment_id)
    {
        $query = "
            SELECT
                MAX(CASE WHEN permission_number = 66 THEN 1 ELSE 0 END) as abastos,
                MAX(CASE WHEN permission_number = 70 THEN 1 ELSE 0 END) as contabilidad,
                MAX(CASE WHEN permission_number = 67 THEN 1 ELSE 0 END) as admin_finanzas,
                MAX(CASE WHEN permission_number = 68 THEN 1 ELSE 0 END) as tesoreria,
                CASE
                    WHEN COUNT(*) >= 4 THEN 1
                    ELSE 0
                END as completed
            FROM [TG].[dbo].[payment_request_authorizations]
            WHERE payment_request_id = ?
        ";

        $result = $this->sql->select($query, [$payment_id]);

        if ($result && count($result) > 0) {
            $status = $result[0];

            // Determinar el siguiente nivel requerido
            if (!$status['abastos']) {
                $status['next_level'] = 66;
            } elseif (!$status['contabilidad']) {
                $status['next_level'] = 70;
            } elseif (!$status['admin_finanzas']) {
                $status['next_level'] = 67;
            } elseif (!$status['tesoreria']) {
                $status['next_level'] = 68;
            } else {
                $status['next_level'] = null;
            }

            return $status;
        }

        return [
            'abastos'       => 0,
            'contabilidad'  => 0,
            'admin_finanzas'=> 0,
            'tesoreria'     => 0,
            'completed'     => 0,
            'next_level'    => 66
        ];
    }

    /**
     * Obtener información de una autorización específica
     */
    public function getAuthorizationInfo($payment_id, $permission_number)
    {
        $query = "
            SELECT 
                pra.id,
                pra.authorization_date,
                pra.staff_user_id,
                u.Nombre as autorizador_nombre
            FROM [TG].[dbo].[payment_request_authorizations] pra
            LEFT JOIN [TG].[dbo].[Usuario] u ON pra.staff_user_id = u.Id
            WHERE pra.payment_request_id = ? AND pra.permission_number = ?
        ";

        $result = $this->sql->select($query, [$payment_id, $permission_number]);
        return $result ? $result[0] : null;
    }

    /**
     * Registrar aplicaciones de anticipo
     */
    /**
     * Liga documentos de compra DISPONIBLES a un anticipo: inserta cada uno
     * como factura del anticipo (payment_request_id = anticipo) y crea su
     * aplicación con el monto. La factura queda "en orden de pago" y deja de
     * aparecer en add_payment. NO toca monto_total/status/autorizaciones del
     * anticipo.
     *
     * Cada documento: nro, Factura, codgas, total, satuid, fecha_vencimiento, monto_aplicar.
     */
    public function attach_invoices_to_anticipo(int $anticipo_id, array $documents, int $user_id): array
    {
        if (empty($documents)) {
            return ['success' => false, 'message' => 'No hay documentos para ligar'];
        }

        $anticipo = $this->get_request_by_id($anticipo_id);
        if (!$anticipo || intval($anticipo['tipo'] ?? 0) !== 1) {
            return ['success' => false, 'message' => 'Anticipo no encontrado'];
        }
        if (intval($anticipo['status']) !== self::STATUS_PAID) {
            return ['success' => false, 'message' => 'El anticipo debe estar pagado para ligarle facturas'];
        }

        // Validar montos y duplicados antes de abrir la transacción
        $total_aplicar = 0.0;
        foreach ($documents as $doc) {
            $monto = floatval($doc['monto_aplicar'] ?? 0);
            $total = floatval($doc['total'] ?? 0);
            $folio = $doc['nro'] ?? '?';

            if ($monto <= 0) {
                return ['success' => false, 'message' => "Monto inválido en el documento $folio"];
            }
            if ($monto > $total + 0.01) {
                return ['success' => false, 'message' => "El monto a aplicar excede el total del documento $folio"];
            }
            if (empty($doc['satuid'])) {
                return ['success' => false, 'message' => "El documento $folio no tiene CFDI (satuid)"];
            }
            $existe = $this->sql->select(
                'SELECT id FROM [TG].[dbo].[payment_request_invoices] WHERE uuid = ? AND is_deleted = 0',
                [$doc['satuid']]
            );
            if (!empty($existe)) {
                return ['success' => false, 'message' => "El documento $folio ya está en una orden de pago o anticipo"];
            }
            $total_aplicar += $monto;
        }

        $saldo = floatval($this->get_saldo_disponible($anticipo_id));
        if ($total_aplicar > $saldo + 0.01) {
            return ['success' => false, 'message' => 'El total a aplicar ($' . number_format($total_aplicar, 2) . ') excede el saldo disponible del anticipo ($' . number_format($saldo, 2) . ')'];
        }

        $this->sql->beginTransaction();
        try {
            $query_invoice = '
                INSERT INTO [TG].[dbo].[payment_request_invoices]
                (payment_request_id, folio, invoice_number, codgas, amount, status, expiration_date, uuid, is_debit_note)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)
            ';
            $query_app = '
                INSERT INTO [TG].[dbo].[anticipo_invoice_applications]
                (anticipo_id, invoice_id, monto_aplicado, fecha_aplicacion, aplicado_por)
                VALUES (?, ?, ?, GETDATE(), ?)
            ';

            $ligadas = 0;
            foreach ($documents as $doc) {
                $monto = floatval($doc['monto_aplicar']);
                $total = floatval($doc['total']);
                // 2 = Pagado (cubierta completa por el anticipo), 3 = Pago Parcial
                $status_factura = abs($total - $monto) <= 0.01 ? 2 : 3;

                $invoice_id = $this->sql->insert($query_invoice, [
                    $anticipo_id,
                    $doc['nro'] ?? null,
                    $doc['Factura'] ?? null,
                    $doc['codgas'] ?? null,
                    $total,
                    $status_factura,
                    $doc['fecha_vencimiento'] ?? null,
                    $doc['satuid'],
                ]);
                if (!$invoice_id || !is_numeric($invoice_id)) {
                    throw new Exception('Error al insertar la factura ' . ($doc['nro'] ?? ''));
                }

                if (!$this->sql->insert($query_app, [$anticipo_id, $invoice_id, $monto, $user_id])) {
                    throw new Exception('Error al registrar la aplicación de la factura ' . ($doc['nro'] ?? ''));
                }
                $ligadas++;
            }

            $this->sql->commit();

            return [
                'success'         => true,
                'message'         => "Se ligaron $ligadas factura(s) al anticipo",
                'facturas_ligadas'=> $ligadas,
                'total_aplicado'  => $total_aplicar,
                'saldo_restante'  => $saldo - $total_aplicar,
            ];
        } catch (Exception $e) {
            $this->sql->rollback();
            error_log('Error en attach_invoices_to_anticipo: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    // public function get_anticipos_para_layout(array $anticipo_ids) : array|false {
    //     if (empty($anticipo_ids)) {
    //         return false;
    //     }

    //     $placeholders = implode(',', array_fill(0, count($anticipo_ids), '?'));

    //     $query = "SELECT 
    //                 t1.id as payment_request_id,
    //                 t1.emp_cod as empresa_cod,
    //                 t1.provider_cod as proveedor_codigo,
    //                 t1.monto_total as monto_autorizado,
    //                 emp.den as empresa_nombre,
    //                 cb_propia.CuentaLocal AS cuenta_cargo_empresa,
    //                 cb_propia.TitularCuenta AS titular_cargo,
    //                 prov.den as proveedor_nombre,
    //                 cb_tercero.CuentaLocal AS clabe_beneficiario,
    //                 cb_tercero.Descripcion AS titular_beneficiario,
    //                 cb_tercero.Banco AS banco_beneficiario,
    //                 cb_tercero.Id AS cuenta_beneficiario_id,
    //                 'ANTICIPO' as tipo_pago,
    //                 'ANTICIPO #' + CAST(t1.id AS VARCHAR) as folio,
    //                 NULL as invoice_number,
    //                 t1.request_date as fecha_pago,
    //                 'Pago' as tipo_pago,
    //                 t1.comment as concepto
    //             FROM [TG].[dbo].[payment_requests] t1
    //             LEFT JOIN [SG12].[dbo].[Empresas] emp ON t1.emp_cod = emp.cod
    //             LEFT JOIN [SG12].[dbo].[Proveedores] prov ON t1.provider_cod = prov.cod
    //             LEFT JOIN [TG].[dbo].[CatalogosCuentasBancarias] cb_tercero 
    //                 ON cb_tercero.Tipo = 'Terceros'
    //                 --AND cb_tercero.Banco = 'SANTANDER'
    //                 AND cb_tercero.Divisa = 'NUEVO PESO MEXICANO'
    //                 AND cb_tercero.Activo = 1
    //                 AND (
    //                     cb_tercero.TitularCuenta LIKE '%' + RTRIM(LTRIM(SUBSTRING(prov.den, 1, CHARINDEX(' ', prov.den + ' ')))) + '%'
    //                     OR cb_tercero.Descripcion LIKE '%' + RTRIM(LTRIM(SUBSTRING(prov.den, 1, CHARINDEX(' ', prov.den + ' ')))) + '%'
    //                 )      
    //             LEFT JOIN [TG].[dbo].[CatalogosCuentasBancarias] cb_propia
    //                             ON cb_propia.emp_cod = emp.cod
    //                             AND cb_propia.Tipo = 'Propias'
    //                             AND cb_propia.Banco = 'SANTANDER'
    //                             AND cb_propia.Activo = 1
    //             WHERE t1.id IN ($placeholders)
    //                 AND t1.tipo = ?  -- Solo anticipos
    //                 AND t1.status = ?  -- Solo autorizados
    //             ORDER BY t1.emp_cod, t1.provider_cod
    //     ";

    //     $params = array_merge(
    //         $anticipo_ids, 
    //         [1, PaymentRequestsModel::STATUS_AUTHORIZED]  // tipo = 1 (anticipo), status = autorizado
    //     );

    //     return $this->sql->select($query, $params) ?: false;
    // }

    public function get_anticipos_para_layout(array $anticipo_ids): array|false
    {
        if (empty($anticipo_ids)) {
            return false;
        }

        $placeholders = implode(',', array_fill(0, count($anticipo_ids), '?'));

        $query = "SELECT 
                    t1.id as payment_request_id,
                    t1.emp_cod as empresa_cod,
                    t1.provider_cod as proveedor_codigo,
                    t1.monto_total as monto_autorizado,
                    emp.den as empresa_nombre,
                    
                    -- ✅ SANTANDER
                    cb_propia_sant.CuentaLocal AS cuenta_cargo_empresa,
                    cb_propia_sant.TitularCuenta AS titular_cargo,
                    cb_tercero_sant.CuentaLocal AS clabe_beneficiario,
                    cb_tercero_sant.Descripcion AS titular_beneficiario,
                    cb_tercero_sant.Banco AS banco_beneficiario,
                    cb_tercero_sant.Id AS cuenta_beneficiario_id,
                    cb_tercero_sant.IdBeneficiario AS clave_id_beneficiario,
                    
                    -- ✅ BANORTE
                    cb_propia_banorte.CuentaLocal AS cuenta_cargo_banorte,
                    cb_propia_banorte.TitularCuenta AS titular_cargo_banorte,
                    
                    prov.den as proveedor_nombre,
                    prov.rfc as proveedor_rfc,
                    'ANTICIPO' as tipo_pago,
                    'ANTICIPO #' + CAST(t1.id AS VARCHAR) as folio,
                    NULL as invoice_number,
                    t1.request_date as fecha_pago,
                    t1.comment as concepto
                    
                FROM [TG].[dbo].[payment_requests] t1
                LEFT JOIN [SG12].[dbo].[Empresas] emp ON t1.emp_cod = emp.cod
                LEFT JOIN [SG12].[dbo].[Proveedores] prov ON t1.provider_cod = prov.cod
                
                -- ✅ Cuenta del beneficiario: UNA sola cuenta por proveedor.
                -- Prioridad: 1) vínculo exacto por proveedor_cod, 2) match por nombre (solo cuentas sin vincular).
                -- Entre varias candidatas se prefiere la CLABE de 18 dígitos.
                OUTER APPLY (
                    SELECT TOP 1 cb.Id, cb.CuentaLocal, cb.Descripcion, cb.Banco, cb.IdBeneficiario
                    FROM [TG].[dbo].[CatalogosCuentasBancarias] cb
                    WHERE cb.Tipo = 'Terceros'
                      AND cb.Divisa = 'NUEVO PESO MEXICANO'
                      AND cb.Activo = 1
                      AND (
                            cb.proveedor_cod = prov.cod
                            OR (
                                cb.proveedor_cod IS NULL
                                AND (
                                    cb.TitularCuenta LIKE '%' + RTRIM(LTRIM(SUBSTRING(prov.den, 1, CHARINDEX(' ', prov.den + ' ')))) + '%'
                                    OR cb.Descripcion LIKE '%' + RTRIM(LTRIM(SUBSTRING(prov.den, 1, CHARINDEX(' ', prov.den + ' ')))) + '%'
                                )
                            )
                      )
                    ORDER BY
                        CASE WHEN cb.proveedor_cod = prov.cod THEN 0 ELSE 1 END,
                        CASE WHEN LEN(cb.CuentaLocal) = 18 THEN 0 ELSE 1 END,
                        cb.Id DESC
                ) cb_tercero_sant

                -- PROPIAS SANTANDER
                LEFT JOIN [TG].[dbo].[CatalogosCuentasBancarias] cb_propia_sant
                    ON cb_propia_sant.emp_cod = emp.cod
                    AND cb_propia_sant.Tipo = 'Propias'
                    AND cb_propia_sant.Banco = 'SANTANDER'
                    AND cb_propia_sant.Activo = 1
                
                -- PROPIAS BANORTE
                LEFT JOIN [TG].[dbo].[CatalogosCuentasBancarias] cb_propia_banorte
                    ON cb_propia_banorte.emp_cod = emp.cod
                    AND cb_propia_banorte.Tipo = 'Propias'
                    AND cb_propia_banorte.Banco = 'BANORTE'
                    AND cb_propia_banorte.Activo = 1
                    
                WHERE t1.id IN ($placeholders)
                    AND t1.tipo = ?  -- Solo anticipos
                    AND t1.status = ?  -- Solo autorizados
                    AND t1.is_deleted = 0
                ORDER BY t1.emp_cod, t1.provider_cod
        ";

        $params = array_merge(
            $anticipo_ids,
            [1, PaymentRequestsModel::STATUS_AUTHORIZED]
        );

        return $this->sql->select($query, $params) ?: false;
    }

    public function getPendingPaymentsForBulkAuthorization($permission_number): array|false
    {
        try {
            $query = "
            SELECT 
                pr.id,
                pr.request_date,
                pr.user_id,
                pr.comment,
                pr.status,
                pr.provider_cod,
                pr.emp_cod,
                pr.tipo,
                pr.monto_total,
                ISNULL(pr.total_notas_credito, 0) as total_notas_credito,
                ISNULL(pr.total_notas_cargo, 0)   as total_notas_cargo,
                (pr.monto_total - ISNULL(pr.total_notas_credito, 0) + ISNULL(pr.total_notas_cargo, 0)) as monto_neto,

                -- Usuario que solicitó
                u.Nombre as usuario_nombre,
                
                -- Proveedor
                prov.den as proveedor_nombre,
                
                -- Empresa
                emp.den as empresa_nombre,
                emp.den as company_name,
                
                -- Resumen de facturas
                ISNULL(inv_summary.total_invoices, 0) as num_facturas,
                ISNULL(inv_summary.total_amount, 0) as total_amount,
                
                -- Autorizaciones ya realizadas
                ISNULL(auth_summary.auth_abastos, 0)      as auth_abastos,
                ISNULL(auth_summary.auth_contabilidad, 0) as auth_contabilidad,
                ISNULL(auth_summary.auth_admin, 0)        as auth_admin,
                ISNULL(auth_summary.auth_tesoreria, 0)    as auth_tesoreria,
                
                -- Fecha de vencimiento más cercana
                inv_summary.fecha_vencimiento_min as fecha_vencimiento,
                
                -- Días hasta vencimiento
                DATEDIFF(day, GETDATE(), inv_summary.fecha_vencimiento_min) as dias_vencimiento,
                
                -- Marcar si requiere revisión (monto alto)
                CASE 
                    WHEN pr.monto_total > 100000 THEN 1
                    ELSE 0
                END as requiere_revision,
                
                -- Marcar si es anticipo
                CASE WHEN pr.tipo = 1 THEN 1 ELSE 0 END as es_anticipo
                
            FROM [TG].[dbo].[payment_requests] pr
            
            LEFT JOIN [TG].[dbo].[Usuario] u ON pr.user_id = u.Id
            LEFT JOIN [SG12].[dbo].[Proveedores] prov ON pr.provider_cod = prov.cod
            LEFT JOIN [SG12].[dbo].[Empresas] emp ON pr.emp_cod = emp.cod
            
            -- Resumen de facturas
            LEFT JOIN (
                SELECT 
                    payment_request_id,
                    COUNT(*) as total_invoices,
                    SUM(amount) as total_amount,
                    MIN(expiration_date) as fecha_vencimiento_min
                FROM [TG].[dbo].[payment_request_invoices]
                WHERE is_deleted = 0
                GROUP BY payment_request_id
            ) inv_summary ON pr.id = inv_summary.payment_request_id
            
            -- Resumen de autorizaciones
            LEFT JOIN (
                SELECT
                    payment_request_id,
                    MAX(CASE WHEN permission_number = 66 THEN 1 ELSE 0 END) as auth_abastos,
                    MAX(CASE WHEN permission_number = 70 THEN 1 ELSE 0 END) as auth_contabilidad,
                    MAX(CASE WHEN permission_number = 67 THEN 1 ELSE 0 END) as auth_admin,
                    MAX(CASE WHEN permission_number = 68 THEN 1 ELSE 0 END) as auth_tesoreria
                FROM [TG].[dbo].[payment_request_authorizations]
                GROUP BY payment_request_id
            ) auth_summary ON pr.id = auth_summary.payment_request_id

            WHERE
                pr.status = ?  -- Solo pendientes (STATUS_PENDING = 0)
                AND pr.tipo = 0
                AND pr.is_deleted = 0
                -- Tesorería (68): ya agrupados en contabilidad y sin autorización de Tesorería aún
                AND ? = 68
                AND pr.accounting_group_id IS NOT NULL
                AND ISNULL(auth_summary.auth_tesoreria, 0) = 0

            ORDER BY
                CASE WHEN pr.monto_total > 100000 THEN 0 ELSE 1 END,
                inv_summary.fecha_vencimiento_min ASC,
                pr.request_date ASC
        ";

            $params = [
                self::STATUS_PENDING,
                $permission_number
            ];
            return $this->sql->select($query, $params) ?: [];
        } catch (Exception $e) {
            error_log("Error en getPendingPaymentsForBulkAuthorization: " . $e->getMessage());
            return false;
        }
    }

    public function get_pending_anticipos_for_authorization(): array|false
    {
        $query = "
            SELECT
                pr.id,
                pr.request_date,
                pr.user_id,
                pr.comment,
                pr.provider_cod,
                pr.emp_cod,
                ISNULL(pr.monto_total, 0) as monto_total,

                u.Nombre as usuario_nombre,
                prov.den as proveedor_nombre,
                emp.den as empresa_nombre

            FROM [TG].[dbo].[payment_requests] pr

            LEFT JOIN [TG].[dbo].[Usuario] u ON pr.user_id = u.Id
            LEFT JOIN [SG12].[dbo].[Proveedores] prov ON pr.provider_cod = prov.cod
            LEFT JOIN [SG12].[dbo].[Empresas] emp ON pr.emp_cod = emp.cod

            LEFT JOIN [TG].[dbo].[payment_request_authorizations] pra
                ON pr.id = pra.payment_request_id AND pra.permission_number = 68

            WHERE
                pr.status = ?
                AND pr.tipo = 1
                AND pr.is_deleted = 0
                AND pra.id IS NULL

            ORDER BY pr.request_date ASC
        ";

        return $this->sql->select($query, [self::STATUS_PENDING]) ?: false;
    }


    /**
     * Procesar aprobación masiva de pagos
     */
    public function processBulkAuthorization($paymentIds, $permissionNumber, $userId, $userName, $comentario = ''): array
    {
        try {
            // $this->sql->beginTransaction();

            // Crear registro de aprobación masiva (anticipo_count se actualizará al final)
            $bulkId = $this->crearRegistroBulkAuthorization($paymentIds, $permissionNumber, $userId, $comentario, 0);

            $aprobados = 0;
            $errores = 0;
            $montoTotal = 0;
            $anticipoCount = 0;
            $detallesErrores = [];

            // Modelo de autorizaciones
            $authModel = new PaymentRequestAuthorizationsModel();

            foreach ($paymentIds as $paymentId) {
                try {
                    $payment = $this->get_request_by_id($paymentId);
                    if (!$payment) {
                        $errores++;
                        $detallesErrores[] = "Pago ID $paymentId: no encontrado";
                        continue;
                    }

                    $monto = floatval($payment['monto_total'] ?? 0)
                           - floatval($payment['total_notas_credito'] ?? 0)
                           + floatval($payment['total_notas_cargo'] ?? 0);

                    // Contar anticipos aprobados
                    if (isset($payment['tipo']) && intval($payment['tipo']) === 1) {
                        $anticipoCount++;
                    }

                    // Insertar autorización
                    $authInserted = $authModel->insert_authorization($paymentId, $userId, $permissionNumber);

                    if (!$authInserted) {
                        throw new Exception("Error al insertar autorización para pago ID $paymentId");
                    }
                    $nextLevel = $authModel->get_next_authorization_level($paymentId);

                    if ($nextLevel === null) {
                        $this->update_request_status($paymentId, self::STATUS_AUTHORIZED);
                    }

                    $aprobados++;
                    $montoTotal += $monto;
                } catch (Exception $e) {
                    $errores++;
                    $detallesErrores[] = "Error en pago ID $paymentId: " . $e->getMessage();
                    error_log("Error aprobando pago $paymentId: " . $e->getMessage());
                }
            }

            // Actualizar registro bulk con totales incluyendo conteo de anticipos
            $this->actualizarRegistroBulk($bulkId, $aprobados, $errores, $montoTotal, $anticipoCount);
            // $this->sql->commit();

            return [
                'success' => true,
                'bulk_id' => $bulkId,
                'aprobados' => $aprobados,
                'errores' => $errores,
                'monto_total' => $montoTotal,
                'detalles' => $detallesErrores
            ];
        } catch (Exception $e) {
            // $this->sql->rollBack();
            error_log("Error en processBulkAuthorization: " . $e->getMessage());

            return [
                'success' => false,
                'message' => 'Error en la transacción: ' . $e->getMessage(),
                'detalles' => []
            ];
        }
    }

    /**
     * Crear registro de aprobación masiva
     */
    private function crearRegistroBulkAuthorization($paymentIds, $permissionNumber, $userId, $comentario = '', $anticipoCount = 0)
    {
        try {
            $query = "
                INSERT INTO [TG].[dbo].[payment_request_bulk_authorizations]
                (authorization_level, user_id, payment_ids, comment, anticipo_count, created_at)
                VALUES (?, ?, ?, ?, ?, GETDATE());";

            $paymentIdsJson = json_encode($paymentIds);

            $result = $this->sql->insert($query, [
                $permissionNumber,
                $userId,
                $paymentIdsJson,
                $comentario,
                $anticipoCount
            ]);

            if ($result) {
                return intval($result);
            }

            throw new Exception("No se pudo obtener el ID de la aprobación masiva");
        } catch (Exception $e) {
            error_log("Error en crearRegistroBulkAuthorization: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Actualizar registro bulk con totales
     */
    private function actualizarRegistroBulk($bulkId, $aprobados, $errores, $montoTotal, $anticipoCount = 0)
    {
        $query = "
            UPDATE [TG].[dbo].[payment_request_bulk_authorizations]
            SET
                approved_count = ?,
                error_count = ?,
                total_amount = ?,
                anticipo_count = ?,
                processed_at = GETDATE()
            WHERE id = ?
        ";

        return $this->sql->update($query, [$aprobados, $errores, $montoTotal, $anticipoCount, $bulkId]);
    }

    /**
     * Obtener contador de pagos pendientes para un nivel
     */
    public function getPendingAuthorizationCount($permissionNumber): int
    {
        try {
            $query = "
            SELECT COUNT(*) as total
            FROM [TG].[dbo].[payment_requests] pr

            LEFT JOIN (
                SELECT
                    payment_request_id,
                    MAX(CASE WHEN permission_number = 66 THEN 1 ELSE 0 END) as auth_abastos,
                    MAX(CASE WHEN permission_number = 70 THEN 1 ELSE 0 END) as auth_contabilidad,
                    MAX(CASE WHEN permission_number = 67 THEN 1 ELSE 0 END) as auth_admin,
                    MAX(CASE WHEN permission_number = 68 THEN 1 ELSE 0 END) as auth_tesoreria
                FROM [TG].[dbo].[payment_request_authorizations]
                GROUP BY payment_request_id
            ) auth_summary ON pr.id = auth_summary.payment_request_id

            WHERE
                pr.status = ?
                AND pr.is_deleted = 0
                AND (
                    (? = 66 AND ISNULL(auth_summary.auth_abastos, 0) = 0)
                    OR
                    (? = 70 AND auth_summary.auth_abastos = 1 AND ISNULL(auth_summary.auth_contabilidad, 0) = 0)
                    OR
                    (? = 67 AND auth_summary.auth_abastos = 1 AND auth_summary.auth_contabilidad = 1 AND ISNULL(auth_summary.auth_admin, 0) = 0)
                    OR
                    (? = 68 AND auth_summary.auth_abastos = 1 AND auth_summary.auth_contabilidad = 1 AND auth_summary.auth_admin = 1 AND ISNULL(auth_summary.auth_tesoreria, 0) = 0)
                )
        ";

            $params = [
                self::STATUS_PENDING,
                $permissionNumber,
                $permissionNumber,
                $permissionNumber,
                $permissionNumber
            ];

            $result = $this->sql->select($query, $params);

            return $result ? intval($result[0]['total']) : 0;
        } catch (Exception $e) {
            error_log("Error en getPendingAuthorizationCount: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Deshacer aprobación masiva (solo dentro de ventana de tiempo)
     */
    public function undoBulkAuthorization($bulkId, $userId): array
    {
        try {
            $this->sql->beginTransaction();

            // Verificar que la aprobación masiva existe y está dentro de la ventana de tiempo
            $query = "
            SELECT 
                ba.*, 
                DATEDIFF(minute, ba.created_at, GETDATE()) as minutos_transcurridos
            FROM [TG].[dbo].[payment_request_bulk_authorizations] ba
            WHERE ba.id = ? AND ba.user_id = ?
        ";

            $result = $this->sql->select($query, [$bulkId, $userId]);

            if (!$result || empty($result)) {
                throw new Exception("Aprobación masiva no encontrada o no tienes permisos");
            }

            $bulk = $result[0];

            // Ventana de 30 minutos para deshacer
            if ($bulk['minutos_transcurridos'] > 30) {
                throw new Exception("El tiempo para deshacer esta aprobación ha expirado (máximo 30 minutos)");
            }

            // Verificar que ningún pago haya sido completamente pagado
            $paymentIds = json_decode($bulk['payment_ids'], true);

            $placeholders = implode(',', array_fill(0, count($paymentIds), '?'));
            $queryCheck = "
            SELECT COUNT(*) as pagados
            FROM [TG].[dbo].[payment_requests]
            WHERE id IN ($placeholders)
            AND status = ?
        ";

            $params = array_merge($paymentIds, [self::STATUS_PAID]);
            $checkResult = $this->sql->select($queryCheck, $params);

            if ($checkResult && $checkResult[0]['pagados'] > 0) {
                throw new Exception("No se puede deshacer: algunos pagos ya han sido ejecutados");
            }

            // Eliminar autorizaciones del nivel correspondiente
            $authModel = new PaymentRequestAuthorizationsModel();
            $permissionNumber = $bulk['authorization_level'];

            foreach ($paymentIds as $paymentId) {
                // Eliminar la autorización específica
                $queryDelete = "
                DELETE FROM [TG].[dbo].[payment_request_authorizations]
                WHERE payment_request_id = ? AND permission_number = ?
            ";
                $this->sql->delete($queryDelete, [$paymentId, $permissionNumber]);

                // Actualizar bulk_authorization_id a NULL
                $queryUpdate = "
                UPDATE [TG].[dbo].[payment_requests]
                SET bulk_authorization_id = NULL
                WHERE id = ?
            ";
                $this->sql->update($queryUpdate, [$paymentId]);
            }

            // Marcar el bulk como deshecho
            $queryMarkUndone = "
            UPDATE [TG].[dbo].[payment_request_bulk_authorizations]
            SET 
                is_undone = 1,
                undone_at = GETDATE()
            WHERE id = ?
        ";

            $this->sql->update($queryMarkUndone, [$bulkId]);

            $this->sql->commit();

            return [
                'success' => true,
                'message' => 'Aprobación masiva deshecha exitosamente',
                'pagos_revertidos' => count($paymentIds)
            ];
        } catch (Exception $e) {
            $this->sql->rollBack();
            error_log("Error en undoBulkAuthorization: " . $e->getMessage());

            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Obtener detalles de aprobación masiva
     */
    public function getBulkAuthorizationDetails($bulkId): array|false
    {
        $query = "
            SELECT
                ba.*,
                u.Nombre as user_name,
                CASE
                    WHEN ba.authorization_level = 66 THEN 'Abastos'
                    WHEN ba.authorization_level = 67 THEN 'Administración y Finanzas'
                    WHEN ba.authorization_level = 68 THEN 'Tesorería'
                    ELSE 'Desconocido'
                END as nivel_nombre
            FROM [TG].[dbo].[payment_request_bulk_authorizations] ba
            LEFT JOIN [TG].[dbo].[Usuario] u ON ba.user_id = u.Id
            WHERE ba.id = ?
        ";

        $result = $this->sql->select($query, [$bulkId]);

        return $result ? $result[0] : false;
    }

    /**
     * Obtener detalle de pagos por IDs para correo de aprobación masiva
     */
    public function getBulkPaymentsDetail(array $paymentIds): array
    {
        if (empty($paymentIds)) return [];

        $placeholders = implode(',', array_fill(0, count($paymentIds), '?'));

        $query = "
            SELECT
                pr.id,
                pr.monto_total,
                ISNULL(pr.total_notas_credito, 0) as total_notas_credito,
                ISNULL(pr.total_notas_cargo, 0)   as total_notas_cargo,
                (pr.monto_total - ISNULL(pr.total_notas_credito, 0) + ISNULL(pr.total_notas_cargo, 0)) as monto_neto,
                pr.comment,
                prov.den as proveedor_nombre,
                emp.den as empresa_nombre,
                ISNULL(inv_sum.total_invoices, 0) as num_facturas
            FROM [TG].[dbo].[payment_requests] pr
            LEFT JOIN [SG12].[dbo].[Proveedores] prov ON pr.provider_cod = prov.cod
            LEFT JOIN [SG12].[dbo].[Empresas] emp ON pr.emp_cod = emp.cod
            LEFT JOIN (
                SELECT payment_request_id, COUNT(*) as total_invoices
                FROM [TG].[dbo].[payment_request_invoices]
                WHERE is_deleted = 0
                GROUP BY payment_request_id
            ) inv_sum ON pr.id = inv_sum.payment_request_id
            WHERE pr.id IN ($placeholders)
            ORDER BY pr.id
        ";

        return $this->sql->select($query, $paymentIds) ?: [];
    }

    /**
     * Recalcula el monto total de un pago basado en sus facturas
     * @param int $payment_request_id
     * @return array
     */
    public function recalculate_payment_total($payment_request_id) : array {
        try {
            // Obtener suma de facturas
            $query = "
                SELECT ISNULL(SUM(amount), 0) as total
                FROM [TG].[dbo].[payment_request_invoices]
                WHERE payment_request_id = ? AND is_deleted = 0
            ";

            $result = $this->sql->select($query, [$payment_request_id]);

            if (!$result) {
                return [
                    'success' => false,
                    'message' => 'Error al calcular total'
                ];
            }

            $new_total = $result[0]['total'];

            // Actualizar monto_total en payment_requests
            $query_update = "
                UPDATE [TG].[dbo].[payment_requests]
                SET monto_total = ?
                WHERE id = ?
            ";

            $update_result = $this->sql->update($query_update, [$new_total, $payment_request_id]);

            if ($update_result) {
                return [
                    'success' => true,
                    'new_total' => $new_total
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Error al actualizar el total'
                ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Reinicia las autorizaciones de un pago y lo vuelve a estado PENDING
     * @param int $payment_request_id
     * @return array
     */
    public function reset_authorizations($payment_request_id) : array {
        $this->sql->beginTransaction();

        try {
            // Verificar que el pago no esté completamente pagado
            $query_check = "
                SELECT status
                FROM [TG].[dbo].[payment_requests]
                WHERE id = ?
            ";

            $payment = $this->sql->select($query_check, [$payment_request_id]);

            if (!$payment || empty($payment)) {
                throw new Exception('Pago no encontrado');
            }

            if ($payment[0]['status'] == self::STATUS_PAID) {
                throw new Exception('No se pueden reiniciar autorizaciones de un pago ya ejecutado');
            }

            // Eliminar todas las autorizaciones
            $authModel = new PaymentRequestAuthorizationsModel();
            $authModel->delete_by_payment_request($payment_request_id);

            // Cambiar status a PENDING
            $this->update_request_status($payment_request_id, self::STATUS_PENDING);

            $this->sql->commit();

            return [
                'success' => true,
                'message' => 'Autorizaciones reiniciadas correctamente'
            ];
        } catch (Exception $e) {
            $this->sql->rollback();

            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * "Eliminar" una requisición = borrado LÓGICO: marca is_deleted en la
     * requisición y en sus facturas activas (así los documentos vuelven a estar
     * disponibles y no choca con la FK de anticipo_invoice_applications).
     * Autorizaciones y transacciones se conservan como historial; las
     * aplicaciones de notas de crédito sí se eliminan para regresar el saldo
     * a la nota.
     */
    public function delete_payment_by_id(int $payment_id, ?int $deleted_by = null): array
    {
        $this->sql->beginTransaction();
        try {
            $this->sql->delete("DELETE FROM [TG].[dbo].[credit_note_applications] WHERE payment_request_id = :id", [':id' => $payment_id]);
            // Liberar aplicaciones de anticipo sobre las facturas de esta requisición:
            // el saldo apartado regresa al anticipo (mismo criterio que al quitar una
            // factura individual). Si no hay anticipos ligados, borra 0 filas.
            $this->sql->delete("
                DELETE a FROM [TG].[dbo].[anticipo_invoice_applications] a
                INNER JOIN [TG].[dbo].[payment_request_invoices] pri ON pri.id = a.invoice_id
                WHERE pri.payment_request_id = :id", [':id' => $payment_id]);
            $this->sql->update("
                UPDATE [TG].[dbo].[payment_request_invoices]
                SET is_deleted = 1, deleted_at = GETDATE(), deleted_by = :uid
                WHERE payment_request_id = :id AND is_deleted = 0", [':uid' => $deleted_by, ':id' => $payment_id]);
            $this->sql->update("
                UPDATE [TG].[dbo].[payment_requests]
                SET is_deleted = 1, deleted_at = GETDATE(), deleted_by = :uid
                WHERE id = :id AND is_deleted = 0", [':uid' => $deleted_by, ':id' => $payment_id]);
            $this->sql->commit();
            return ['success' => true];
        } catch (Exception $e) {
            $this->sql->rollBack();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /** TEMPORAL PRUEBAS - quitar cuando terminen las pruebas */
    public function reset_all_test_data(): array
    {
        $this->sql->beginTransaction();
        try {
            $this->sql->delete("DELETE FROM [TG].[dbo].[credit_note_applications]",            []);
            $this->sql->delete("DELETE FROM [TG].[dbo].[payment_request_bulk_authorizations]", []);
            $this->sql->delete("DELETE FROM [TG].[dbo].[invoice_credit_debit_notes_doc]",      []);
            $this->sql->delete("DELETE FROM [TG].[dbo].[invoice_credit_debit_notes]",          []);
            $this->sql->delete("DELETE FROM [TG].[dbo].[payment_transactions]",                []);
            $this->sql->delete("DELETE FROM [TG].[dbo].[payment_request_authorizations]",      []);
            $this->sql->delete("DELETE FROM [TG].[dbo].[payment_request_invoices]",            []);
            $this->sql->delete("DELETE FROM [TG].[dbo].[payment_requests]",                    []);
            $this->sql->commit();
            return ['success' => true];
        } catch (Exception $e) {
            $this->sql->rollBack();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
