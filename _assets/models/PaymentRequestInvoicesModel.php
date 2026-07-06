<?php
class PaymentRequestInvoicesModel extends Model
{
    public $id;
    public $payment_request_id;
    public $folio;
    public $invoice_number;
    public $codgas;
    public $amount;
    public $paid_amount;
    public $status;
    public $date_added;
    public $expiration_date;
    public $uuid;  // Agregar esta propiedad

    const STATUS_PENDING = 0;      // Pendiente de pago
    const STATUS_AUTHORIZED = 1;   // Autorizado pero no pagado
    const STATUS_PAID = 2;         // Pagado completamente
    const STATUS_PARTIAL = 3;      // Pago parcial realizado
    const STATUS_CANCELLED = 4;    // Cancelado



    /**
     * Obtiene las facturas de una solicitud de pago.
     * @param int $payment_request_id
     * @return array|false
     */
    public function get_invoices_by_request($payment_request_id) : array|false {
        $query = 'SELECT id, payment_request_id, folio, invoice_number, codgas, amount, paid_amount, status, date_added
                  FROM [TG].[dbo].[payment_request_invoices]
                  WHERE payment_request_id = ? AND is_deleted = 0
                  ORDER BY id;';
        return ($this->sql->select($query, [$payment_request_id])) ?: false;
    }

    /**
     * Facturas con saldo pendiente de un proveedor (para ligar anticipos).
     * @param string $provider_cod
     * @return array|false
     */
    public function get_pending_by_provider($provider_cod) : array|false {
        $query = "
            SELECT
                pri.id,
                pri.payment_request_id,
                pri.folio,
                pri.invoice_number,
                pri.amount,
                ISNULL(pri.paid_amount, 0) AS paid_amount,
                (pri.amount - ISNULL(pri.paid_amount, 0)) AS saldo,
                pri.uuid,
                g.abr AS estacion_nombre
            FROM [TG].[dbo].[payment_request_invoices] pri
            INNER JOIN [TG].[dbo].[payment_requests] pr ON pr.id = pri.payment_request_id
            LEFT JOIN [SG12].[dbo].Gasolineras g ON g.cod = pri.codgas
            WHERE pr.provider_cod = ?
              AND pri.is_deleted = 0
              AND pri.status IN (0, 1, 3)
              AND (pri.amount - ISNULL(pri.paid_amount, 0)) > 0
            ORDER BY pri.id DESC
        ";
        return ($this->sql->select($query, [$provider_cod])) ?: false;
    }

    /**
     * Inserta una factura para una solicitud de pago.
     * @param int $payment_request_id
     * @param string $folio
     * @param string $invoice_number
     * @param int $codgas
     * @param float $amount
     * @param float $paid_amount
     * @param string $status
     * @return bool
     */
    public function insert_invoice($payment_request_id, $folio, $invoice_number, $codgas, $amount, $paid_amount, $status) : bool {
        $query = 'INSERT INTO [TG].[dbo].[payment_request_invoices] 
                    (payment_request_id, folio, invoice_number, codgas, amount, paid_amount, status, date_added)
                  VALUES (?, ?, ?, ?, ?, ?, ?, GETDATE());';
        return $this->sql->insert($query, [
            $payment_request_id, $folio, $invoice_number, $codgas, $amount, $paid_amount, $status
        ]);
    }

    /**
     * Actualiza el monto pagado y el estado de una factura.
     * @param int $id
     * @param float $paid_amount
     * @param string $status
     * @return bool
     */
    public function update_invoice_payment($id, $paid_amount, $status) : bool {
        $query = 'UPDATE [TG].[dbo].[payment_request_invoices] 
                  SET paid_amount = ?, status = ? 
                  WHERE id = ?;';
        return $this->sql->update($query, [$paid_amount, $status, $id]);
    }

    public function insertInvoicesBulk($documents, $payment_request_id) : bool {
        try {
            $query = '
                INSERT INTO [TG].[dbo].[payment_request_invoices] 
                (payment_request_id, folio, invoice_number, codgas, amount, status, expiration_date, uuid)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ';

            $inserted = 0;
            $skipped = 0;

            foreach ($documents as $doc) {
                $folio = $doc['nro'] ?? null;
                $invoice_number = $doc['Factura'] ?? null;
                $codgas = $doc['codgas'] ?? null;
                $amount = $doc['total_fac'] ?? 0;
                $expiration_date = $doc['fechaVto'] ?? null;
                $uuid = $doc['satuid'] ?? null;

                // Validación 1: UUID obligatorio
                if (empty($uuid)) {
                    error_log("Factura sin UUID (Folio: $folio, Factura: $invoice_number) - OMITIDA");
                    $skipped++;
                    continue;
                }

                // Validación 2: Verificar duplicados
                if ($this->invoice_exists_by_uuid($uuid)) {
                    error_log("UUID duplicado: $uuid (Factura: $invoice_number) - OMITIDA");
                    $skipped++;
                    continue;
                }

                $status = self::STATUS_PENDING;

                $params = [
                    $payment_request_id,
                    $folio,
                    $invoice_number,
                    $codgas,
                    $amount,
                    $status,
                    $expiration_date,
                    $uuid
                ];

                if ($this->sql->insert($query, $params)) {
                    $inserted++;
                } else {
                    error_log("Error insertando factura UUID: $uuid");
                    return false;
                }
            }

            error_log("InsertInvoicesBulk completado: $inserted insertadas, $skipped omitidas");
            return $inserted > 0;

        } catch (Exception $e) {
            error_log("Error en insertInvoicesBulk: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Verifica si una factura ya existe en alguna orden de pago (por UUID)
     */
    public function invoice_exists_by_uuid($uuid) : bool {
        $query = 'SELECT id FROM [TG].[dbo].[payment_request_invoices] WHERE uuid = ? AND is_deleted = 0';
        $result = $this->sql->select($query, [$uuid]);
        return !empty($result);
    }

    public function get_by_uuid($uuid) : array|false {
        $query = '
            SELECT 
                pri.*,
                pr.id as payment_request_id,
                pr.status as payment_status,
                pr.request_date,
                u.Nombre as usuario_nombre
            FROM [TG].[dbo].[payment_request_invoices] pri
            LEFT JOIN [TG].[dbo].[payment_requests] pr ON pri.payment_request_id = pr.id
            LEFT JOIN [TG].[dbo].[Usuario] u ON pr.user_id = u.Id
            WHERE pri.uuid = ? AND pri.is_deleted = 0
        ';
        $result = $this->sql->select($query, [$uuid]);
        return $result ? $result[0] : false;
    }

    /**
     * Actualiza el estado de una factura
     */
    public function update_invoice_status($id, $status) : bool {
        $query = 'UPDATE [TG].[dbo].[payment_request_invoices] SET status = ? WHERE id = ?';
        return $this->sql->update($query, [$status, $id]);
    }

    /**
     * Actualiza el monto pagado
     */
    public function update_paid_amount($id, $paid_amount) : bool {
        $query = 'UPDATE [TG].[dbo].[payment_request_invoices] SET paid_amount = ?, status = ? WHERE id = ?';
        return $this->sql->update($query, [$paid_amount, self::STATUS_PAID, $id]);
    }

    /**
     * Obtiene el total de facturas de una solicitud
     */
    public function get_payment_summary($payment_request_id) : array|false {
        $query = '
            SELECT 
                COUNT(*) as total_invoices,
                SUM(amount) as total_amount,
                SUM(ISNULL(paid_amount, 0)) as total_paid,
                SUM(amount) - SUM(ISNULL(paid_amount, 0)) as total_pending
            FROM [TG].[dbo].[payment_request_invoices]
            WHERE payment_request_id = ? AND is_deleted = 0
        ';
        $result = $this->sql->select($query, [$payment_request_id]);
        return $result ? $result[0] : false;
    }

    /**
     * Elimina todas las facturas de una solicitud (para cancelar)
     */
    public function delete_by_payment_request($payment_request_id) : bool {
        $query = 'DELETE FROM [TG].[dbo].[payment_request_invoices] WHERE payment_request_id = ?';
        return $this->sql->delete($query, [$payment_request_id]);
    }

    public static function getStatusText($status) : string {
        switch ($status) {
            case self::STATUS_PENDING:
                return 'Pendiente';
            case self::STATUS_AUTHORIZED:
                return 'Autorizado';
            case self::STATUS_PAID:
                return 'Pagado';
            case self::STATUS_PARTIAL:
                return 'Pago Parcial';
            case self::STATUS_CANCELLED:
                return 'Cancelado';
            default:
                return 'Desconocido';
        }
    }
    /**
     * Obtiene el badge HTML para mostrar el estado
     */
    public static function getStatusBadge($status) : string {
        switch ($status) {
            case self::STATUS_PENDING:
                return '<span class="badge bg-warning text-dark">Pendiente</span>';
            case self::STATUS_AUTHORIZED:
                return '<span class="badge bg-info">Autorizado</span>';
            case self::STATUS_PAID:
                return '<span class="badge bg-success">Pagado</span>';
            case self::STATUS_PARTIAL:
                return '<span class="badge bg-primary">Pago Parcial</span>';
            case self::STATUS_CANCELLED:
                return '<span class="badge bg-danger">Cancelado</span>';
            default:
                return '<span class="badge bg-secondary">Desconocido</span>';
        }
    }

    public function get_by_payment_request_with_transactions($payment_request_id) : array|false {
        $query = '
           SELECT
                t1.id,
                t1.payment_request_id,
                t1.folio,
                t1.invoice_number,
                t1.codgas,
                t1.amount,
                t1.is_debit_note,
                --t1.[status],
                t1.expiration_date,
                t1.date_added,
                t1.uuid,
                t1.authorized_amount,
                t1.payment_authorized,
                t1.authorized_by,
                t1.authorized_at,
                -- Para facturas normales: proveedor via DocumentosC; para notas de cargo: via payment_requests
                COALESCE(t4.den, prov_nd.den) as proveedor_nombre,
                t2.abr as estacion_nombre,
                -- Para filas is_debit_note=1: datos de la nota de cargo
                nota_nd.id AS nota_id,
                -- Calcular paid_amount desde payment_transactions
                 ISNULL((
                    SELECT SUM(payment_amount)
                    FROM [TG].[dbo].[payment_transactions] t5
                    WHERE t5.invoice_id = t1.id
                    AND t1.status IN (1, 2)
                ), 0) as paid_amount,
                -- Calcular status dinámicamente
                CASE
                    WHEN ISNULL((
                        SELECT SUM(payment_amount)
                        FROM [TG].[dbo].[payment_transactions] t5
                        WHERE t5.invoice_id = t1.id
                        AND t1.status IN (1, 2)
                    ), 0) = 0 THEN 0  -- Pendiente
                    WHEN ISNULL((
                        SELECT SUM(payment_amount)
                        FROM [TG].[dbo].[payment_transactions] t5
                        WHERE t5.invoice_id = t1.id
                        AND t1.status IN (1, 2)
                    ), 0) < t1.amount THEN 3  -- Parcial
                    ELSE 2  -- Pagado
                END as status,
                -- Notas de crédito aplicadas a esta factura
                ISNULL((
                    SELECT SUM(CASE WHEN n.note_type = \'CREDIT\' THEN ca.applied_amount ELSE 0 END)
                    FROM [TG].[dbo].[credit_note_applications] ca
                    INNER JOIN [TG].[dbo].[invoice_credit_debit_notes] n ON ca.credit_note_id = n.id
                    WHERE ca.invoice_id = t1.id AND ca.status = 1
                ), 0) as total_notas_credito,
                -- Notas de cargo aplicadas a esta factura
                ISNULL((
                    SELECT SUM(CASE WHEN n.note_type = \'DEBIT\' THEN ca.applied_amount ELSE 0 END)
                    FROM [TG].[dbo].[credit_note_applications] ca
                    INNER JOIN [TG].[dbo].[invoice_credit_debit_notes] n ON ca.credit_note_id = n.id
                    WHERE ca.invoice_id = t1.id AND ca.status = 1
                ), 0) as total_notas_cargo,
                -- Conteo de notas aplicadas
                ISNULL((
                    SELECT COUNT(*)
                    FROM [TG].[dbo].[credit_note_applications] ca
                    WHERE ca.invoice_id = t1.id AND ca.status = 1
                ), 0) as notas_count,
                -- Comprobantes de pago (documentos) ligados por transacción o por lote
                (
                    SELECT STRING_AGG(CAST(d.id AS VARCHAR), \',\')
                    FROM [TG].[dbo].[payment_transactions] t5b
                    INNER JOIN [TG].[dbo].[payment_transaction_documents] d
                        ON d.transaction_id = t5b.id
                        OR (t5b.batch_id IS NOT NULL AND d.batch_id = t5b.batch_id)
                    WHERE t5b.invoice_id = t1.id
                ) as comprobante_doc_ids
                FROM [TG].[dbo].[payment_request_invoices] t1
                LEFT JOIN sg12.[dbo].[Gasolineras] t2 ON t1.codgas = t2.cod
                LEFT JOIN sg12.[dbo].DocumentosC t3 ON t1.is_debit_note = 0 AND t1.codgas = t3.codgas AND TRY_CAST(t1.folio AS int) = t3.nro AND t3.tip = 1
                LEFT JOIN SG12.dbo.Proveedores t4 ON t1.is_debit_note = 0 AND t3.codopr = t4.cod
                -- Para notas de cargo: proveedor desde payment_requests
                LEFT JOIN [TG].[dbo].[payment_requests] pr_nd ON t1.is_debit_note = 1 AND pr_nd.id = t1.payment_request_id
                LEFT JOIN SG12.dbo.Proveedores prov_nd ON t1.is_debit_note = 1 AND prov_nd.cod = pr_nd.provider_cod
                -- Para notas de cargo: datos de invoice_credit_debit_notes (buscando por note_number = folio)
                LEFT JOIN [TG].[dbo].[invoice_credit_debit_notes] nota_nd ON t1.is_debit_note = 1 AND nota_nd.note_number = t1.folio AND nota_nd.note_type = \'DEBIT\'
                WHERE t1.payment_request_id = ? AND t1.is_deleted = 0
                ORDER BY t1.is_debit_note ASC, t1.date_added DESC
        ';
        return ($this->sql->select($query, [$payment_request_id])) ?: false;
    }

    /**
     * Obtiene resumen de facturas con cálculos desde payment_transactions
     */
    public function get_payment_summary_from_transactions($payment_request_id) : array|false {
        $query = '
             WITH InvoicePayments AS (
            SELECT 
                pri.id,
                pri.amount,
                ISNULL(SUM(pt.payment_amount), 0) as paid_amount
            FROM [TG].[dbo].[payment_request_invoices] pri
            LEFT JOIN [TG].[dbo].[payment_transactions] pt
                ON pt.invoice_id = pri.id
                AND pt.status IN (1, 2)
            WHERE pri.payment_request_id = ? AND pri.is_deleted = 0
            GROUP BY pri.id, pri.amount
        )
        SELECT 
            COUNT(*) as total_invoices,
            SUM(amount) as total_amount,
            SUM(paid_amount) as total_paid,
            SUM(amount - paid_amount) as total_pending
        FROM InvoicePayments
        ';
        $result = $this->sql->select($query, [$payment_request_id]);
        return $result ? $result[0] : false;
    }
    public function get_by_ids($ids) {
        if (empty($ids)) {
            return [];
        }
        $ids = array_map('intval', $ids);

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        
        $query = "
            SELECT 
                t1.id,
                t1.payment_request_id,
                t1.folio,
                t1.invoice_number,
                t1.codgas,
                t1.amount,
                --t1.[status],
                t1.expiration_date,
                t1.date_added,
                t1.uuid,
                t4.den as proveedor_nombre,
                t2.abr as estacion_nombre,
                -- Calcular paid_amount desde payment_transactions
                 ISNULL((
                    SELECT SUM(payment_amount)
                    FROM [TG].[dbo].[payment_transactions] t5
                    WHERE t5.invoice_id = t1.id
                    AND t1.status IN (1, 2)
                ), 0) as paid_amount,
                -- Calcular status dinámicamente
                CASE 
                    WHEN ISNULL((
                        SELECT SUM(payment_amount)
                        FROM [TG].[dbo].[payment_transactions] t5
                        WHERE t5.invoice_id = t1.id
                        AND t1.status IN (1, 2)
                    ), 0) = 0 THEN 0  -- Pendiente
                    WHEN ISNULL((
                        SELECT SUM(payment_amount)
                        FROM [TG].[dbo].[payment_transactions] t5
                        WHERE t5.invoice_id = t1.id
                        AND t1.status IN (1, 2)
                    ), 0) < t1.amount THEN 3  -- Parcial
                    ELSE 2  -- Pagado
                END as status
                FROM [TG].[dbo].[payment_request_invoices] t1
                LEFT JOIN sg12.[dbo].[Gasolineras] t2 ON t1.codgas = t2.cod
                left join sg12.[dbo].DocumentosC t3 ON t1.codgas = t3.codgas  and TRY_CAST(t1.folio AS int) = t3.nro and t3.tip = 1
                LEFT JOIN SG12.dbo.Proveedores t4 on t3.codopr = t4.cod
                WHERE t1.Id IN ($placeholders) AND t1.is_deleted = 0
                ORDER BY t1.date_added DESC
        ";
        $result = $this->sql->select($query, $ids);
        return $result ?: false;

    }

    /**
     * Autorizar facturas para pago
     * @param int $payment_id
     * @param array $facturas - Array de facturas con invoice_id, monto_autorizado, saldo_anterior
     * @param int $user_id - Usuario que autoriza
     * @return array
     */
    public function authorize_invoices_for_payment($payment_id, $facturas, $user_id) : array {
        if (empty($facturas)) {
            return [
                'success' => false,
                'message' => 'No hay facturas para autorizar'
            ];
        }

        $this->sql->beginTransaction();
        
        try {
            $facturas_autorizadas = 0;
            $total_autorizado = 0;
            $errores = [];
            
            foreach ($facturas as $factura) {
                $invoice_id = $factura['invoice_id'] ?? null;
                $monto_autorizado = floatval($factura['monto_autorizado'] ?? 0);
                $saldo_anterior = floatval($factura['saldo_anterior'] ?? 0);
                $folio = $factura['folio'] ?? 'N/A';
                
                // Validar datos de la factura
                if (!$invoice_id || $monto_autorizado <= 0) {
                    $errores[] = "Factura {$folio}: datos inválidos";
                    continue;
                }
                
                // Validar que no exceda el saldo
                if ($monto_autorizado > $saldo_anterior) {
                    $errores[] = "Factura {$folio}: monto excede saldo disponible";
                    continue;
                }
                
                // Verificar que la factura pertenece a este payment_request y obtener su estado actual
                $query_check = "
                    SELECT id, amount, paid_amount, authorized_amount, payment_authorized
                    FROM [TG].[dbo].[payment_request_invoices]
                    WHERE id = ? AND payment_request_id = ? AND is_deleted = 0
                ";

                $invoice_data = $this->sql->select($query_check, [$invoice_id, $payment_id]);

                if (!$invoice_data || empty($invoice_data)) {
                    $errores[] = "Factura {$folio}: no pertenece a esta solicitud";
                    continue;
                }

                $invoice = $invoice_data[0];

                // Autorizaciones acumulables: cada ronda suma al monto ya autorizado
                // (que puede tener saldo pendiente de una ronda anterior si aún no se
                // pagó, o si ya se pagó una parte y se autoriza el resto). Se bloquea
                // solo si ya no queda nada por autorizar.
                $authorized_amount_actual = floatval($invoice['authorized_amount'] ?? 0);
                $pendiente_por_autorizar = floatval($invoice['amount']) - $authorized_amount_actual;

                if ($pendiente_por_autorizar <= 0.01) {
                    $errores[] = "Factura {$folio}: ya está autorizada en su totalidad";
                    continue;
                }

                if ($monto_autorizado > $pendiente_por_autorizar + 0.01) {
                    $errores[] = "Factura {$folio}: el monto excede lo pendiente por autorizar (\${$pendiente_por_autorizar})";
                    continue;
                }

                $nuevo_authorized_amount = $authorized_amount_actual + $monto_autorizado;

                // Actualizar factura con autorización (acumulada)
                $query_update = "
                    UPDATE [TG].[dbo].[payment_request_invoices]
                    SET
                        authorized_amount = ?,
                        payment_authorized = 1,
                        authorized_by = ?,
                        authorized_at = GETDATE()
                    WHERE id = ?
                ";

                $update_result = $this->sql->update(
                    $query_update,
                    [$nuevo_authorized_amount, $user_id, $invoice_id]
                );
                
                if ($update_result) {
                    $facturas_autorizadas++;
                    $total_autorizado += $monto_autorizado;
                } else {
                    $errores[] = "Factura {$folio}: error al autorizar en base de datos";
                }
            }
            
            // Verificar si se autorizó al menos una
            if ($facturas_autorizadas === 0) {
                throw new Exception('No se pudo autorizar ninguna factura. ' . implode(', ', $errores));
            }
            
            // Commit de la transacción
            $this->sql->commit();
            
            return [
                'success' => true,
                'facturas_autorizadas' => $facturas_autorizadas,
                'total_autorizado' => $total_autorizado,
                'errores' => $errores,
                'message' => "Se autorizaron {$facturas_autorizadas} factura(s) para pago por un total de $" . number_format($total_autorizado, 2)
            ];
            
        } catch (Exception $e) {
            $this->sql->rollback();
            error_log("Error en authorize_invoices_for_payment: " . $e->getMessage());

            return [
                'success' => false,
                'message' => 'Error al autorizar facturas: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Desautoriza ("limpia") facturas autorizadas para pago: revierte
     * payment_authorized a 0 y authorized_amount a NULL, de modo que la factura
     * sale de la tab "Facturas Autorizadas" y regresa a la cola de autorización
     * de Tesorería.
     *
     * Reglas:
     *  - Solo facturas con payment_authorized = 1.
     *  - NO se desautoriza ninguna que ya tenga pagos (paid_amount > 0), para no
     *    dejar transacciones de pago huérfanas.
     *  - Si una requisición queda sin ninguna factura autorizada y su status era
     *    AUTORIZADO (1), se regresa a PENDIENTE (0) para que vuelva al flujo normal.
     *
     * @param array $invoice_ids
     * @return array
     */
    public function unauthorize_invoices(array $invoice_ids, $user_id = null, $user_name = null) : array {
        $invoice_ids = array_values(array_unique(array_map('intval', $invoice_ids)));
        $invoice_ids = array_filter($invoice_ids, fn($v) => $v > 0);

        if (empty($invoice_ids)) {
            return ['success' => false, 'message' => 'No se proporcionaron facturas válidas'];
        }

        $placeholders = implode(',', array_fill(0, count($invoice_ids), '?'));

        // Instanciar ANTES de abrir la transacción: el constructor de cualquier
        // Model llama a MySqlPdoHandler::connect(), que reemplaza la conexión PDO
        // singleton. Si se hiciera dentro del try, destruiría la conexión con la
        // transacción abierta, dejando los UPDATE posteriores en autocommit y el
        // rollback como no-op.
        $auditLog = new PaymentRequestAuditLogModel();

        $this->sql->beginTransaction();
        try {
            // 1. Traer estado actual de las facturas solicitadas
            $rows = $this->sql->select("
                SELECT pri.id, pri.payment_request_id, pri.folio, pri.payment_authorized,
                       pri.authorized_amount, pri.authorized_by, pri.authorized_at,
                       ISNULL(pri.paid_amount, 0) AS paid_amount,
                       pr.accounting_group_id
                FROM [TG].[dbo].[payment_request_invoices] pri
                LEFT JOIN [TG].[dbo].[payment_requests] pr ON pr.id = pri.payment_request_id
                WHERE pri.id IN ($placeholders) AND pri.is_deleted = 0
            ", $invoice_ids);

            if (!$rows) {
                throw new Exception('No se encontraron las facturas');
            }

            $a_limpiar       = [];
            $errores         = [];
            $payment_req_ids = [];

            foreach ($rows as $r) {
                if ((int)$r['payment_authorized'] !== 1) {
                    $errores[] = "Factura {$r['folio']}: no está autorizada";
                    continue;
                }
                if ((float)$r['paid_amount'] > 0) {
                    $errores[] = "Factura {$r['folio']}: tiene pagos registrados y no puede desautorizarse";
                    continue;
                }

                // Registrar auditoría con el snapshot ANTES de limpiar la autorización.
                $snapshot = [
                    'id'                => (int)$r['id'],
                    'folio'             => $r['folio'],
                    'authorized_amount' => $r['authorized_amount'],
                    'authorized_by'     => $r['authorized_by'],
                    'authorized_at'     => $r['authorized_at'],
                ];
                $logged = $auditLog->log_unauthorize_invoice(
                    (int)$r['payment_request_id'],
                    $snapshot,
                    $user_id,
                    $user_name,
                    $r['accounting_group_id'] ?? null
                );
                if (!$logged) {
                    throw new Exception("No se pudo registrar la auditoría de la factura {$r['folio']}");
                }

                $a_limpiar[] = (int)$r['id'];
                $payment_req_ids[(int)$r['payment_request_id']] = true;
            }

            if (empty($a_limpiar)) {
                throw new Exception(empty($errores)
                    ? 'No hay facturas para desautorizar'
                    : implode('. ', $errores));
            }

            // 2. Limpiar la autorización
            $ph2 = implode(',', array_fill(0, count($a_limpiar), '?'));
            $ok = $this->sql->update("
                UPDATE [TG].[dbo].[payment_request_invoices]
                SET payment_authorized = 0,
                    authorized_amount  = NULL,
                    authorized_by      = NULL,
                    authorized_at      = NULL
                WHERE id IN ($ph2)
            ", $a_limpiar);

            if ($ok === false) {
                throw new Exception('Error al desautorizar las facturas');
            }

            // 3. Si alguna requisición quedó sin facturas autorizadas y estaba en
            //    estado AUTORIZADO, regresarla a PENDIENTE.
            foreach (array_keys($payment_req_ids) as $prid) {
                $restantes = $this->sql->select("
                    SELECT COUNT(*) AS n
                    FROM [TG].[dbo].[payment_request_invoices]
                    WHERE payment_request_id = ? AND payment_authorized = 1 AND is_deleted = 0
                ", [$prid]);

                if ($restantes && (int)$restantes[0]['n'] === 0) {
                    $this->sql->update("
                        UPDATE [TG].[dbo].[payment_requests]
                        SET status = ?
                        WHERE id = ? AND status = ?
                    ", [
                        PaymentRequestsModel::STATUS_PENDING,
                        $prid,
                        PaymentRequestsModel::STATUS_AUTHORIZED,
                    ]);
                }
            }

            $this->sql->commit();

            return [
                'success'        => true,
                'desautorizadas' => count($a_limpiar),
                'errores'        => $errores,
                'message'        => count($a_limpiar) . ' factura(s) regresada(s) a la cola de Tesorería'
                    . (empty($errores) ? '' : '. Omitidas: ' . implode('; ', $errores)),
            ];
        } catch (Exception $e) {
            $this->sql->rollback();
            error_log("Error en unauthorize_invoices: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Obtener facturas autorizadas pendientes de pago
     * @param int $payment_id
     * @return array|false
     */
    public function get_authorized_pending_payment($payment_id) : array|false {
        $query = "
            SELECT 
                id,
                folio,
                invoice_number,
                codgas,
                amount,
                paid_amount,
                authorized_amount,
                status,
                uuid,
                (amount - ISNULL(paid_amount, 0)) as saldo
            FROM [TG].[dbo].[payment_request_invoices]
            WHERE payment_request_id = ?
            AND payment_authorized = 1
            AND status != ?
            AND is_deleted = 0
            ORDER BY id
        ";
        
        return $this->sql->select($query, [$payment_id, PaymentRequestsModel::STATUS_PAID]) ?: false;
    }

    /**
     * Verificar si una factura ya está autorizada
     * @param int $invoice_id
     * @return bool
     */
    public function is_invoice_authorized($invoice_id) : bool {
        $query = "
            SELECT payment_authorized
            FROM [TG].[dbo].[payment_request_invoices]
            WHERE id = ? AND is_deleted = 0
        ";
        
        $result = $this->sql->select($query, [$invoice_id]);
        
        if ($result && !empty($result)) {
            return $result[0]['payment_authorized'] == 1;
        }
        
        return false;
    }

    /**
     * Verificar si todas las facturas de un pago están completamente pagadas
     * @param int $payment_id
     * @return bool
     */
    public function all_invoices_paid($payment_id) : bool {
        $query = "
            SELECT COUNT(*) as total,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as pagadas
            FROM [TG].[dbo].[payment_request_invoices]
            WHERE payment_request_id = ? AND is_deleted = 0
        ";
        
        $result = $this->sql->select($query, [
            PaymentRequestsModel::STATUS_PAID,
            $payment_id
        ]);
        
        if ($result && !empty($result)) {
            return ($result[0]['total'] == $result[0]['pagadas'] && $result[0]['total'] > 0);
        }
        
        return false;
    }

    /**
     * Obtener resumen de pagos de una solicitud
     * @param int $payment_id
     * @return array|null
     */
    public function get_payment_execution_summary($payment_id) : array|null {
        $query = "
            SELECT 
                COUNT(*) as total_facturas,
                SUM(amount) as monto_total,
                SUM(ISNULL(paid_amount, 0)) as total_pagado,
                SUM(authorized_amount) as total_autorizado,
                SUM(CASE WHEN payment_authorized = 1 THEN 1 ELSE 0 END) as facturas_autorizadas,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as facturas_pagadas
            FROM [TG].[dbo].[payment_request_invoices]
            WHERE payment_request_id = ? AND is_deleted = 0
        ";
        
        $result = $this->sql->select($query, [
            PaymentRequestsModel::STATUS_PAID,
            $payment_id
        ]);
        
        return $result ? $result[0] : null;
    }

    /**
     * Obtener todas las facturas autorizadas pendientes de pago
     * Agrupadas por empresa y razón social
     * @return array|false
     */
    public function get_authorized_pending_invoices() : array|false {
        $query = "
            SELECT 
                pri.id,
                pri.payment_request_id,
                pri.folio,
                pri.invoice_number,
                pri.codgas,
                pri.amount,
                pri.paid_amount,
                pri.authorized_amount,
                pri.payment_authorized,
                pri.authorized_by,
                pri.authorized_at,
                pri.status,
                pri.expiration_date,
                pri.uuid,
                
                -- Saldo calculado
                (pri.amount - ISNULL(pri.paid_amount, 0)) as saldo,
                
                -- Información de payment_request
                pr.id as pago_id,
                pr.emp_cod,
                pr.provider_cod,
                pr.request_date as pago_fecha,
                pr.comment as pago_comentario,
                
                -- Información de la estación
                g.abr as estacion_nombre,
                g.den as estacion_completa,
                
                -- Usuario que autorizó
                u_auth.Nombre as authorized_by_name,
                
                -- Proveedor
                prov.den as proveedor_nombre,
                prov.rfc as proveedor_rfc,
                
                -- Empresa (razón social)
                emp.den as empresa_nombre,
                emp.rfc as empresa_rfc,
                
                -- Determinar banco según emp_cod
                CASE
                    WHEN pr.emp_cod IN (1, 10, 17, 18, 21, 23) THEN 'Banorte'
                    WHEN pr.emp_cod IN (11, 14, 15, 16, 19, 20) THEN 'Santander'
                    ELSE 'Sin asignar'
                END as banco_asignado,

                -- Color para agrupar visualmente
                CASE
                    WHEN pr.emp_cod IN (1, 10, 17, 18, 21, 23) THEN '#C9302C'
                    WHEN pr.emp_cod IN (11, 14, 15, 16, 19, 20) THEN '#EC1C24'
                    ELSE '#6c757d'
                END as banco_color
                
            FROM [TG].[dbo].[payment_request_invoices] pri
            
            INNER JOIN [TG].[dbo].[payment_requests] pr 
                ON pri.payment_request_id = pr.id
            
            LEFT JOIN [SG12].[dbo].[Gasolineras] g 
                ON pri.codgas = g.cod
            
            LEFT JOIN [TG].[dbo].[Usuario] u_auth 
                ON pri.authorized_by = u_auth.Id
            
            LEFT JOIN [SG12].[dbo].[Proveedores] prov 
                ON pr.provider_cod = prov.cod
            
            LEFT JOIN [SG12].[dbo].[Empresas] emp 
                ON pr.emp_cod = emp.cod
            
            WHERE pri.payment_authorized = 1  -- Solo autorizadas
            AND pri.status != ?              -- No pagadas completamente
            AND (pri.amount - ISNULL(pri.paid_amount, 0)) > 0  -- Con saldo pendiente
            AND pr.status = ?                -- Pago autorizado
            AND pri.is_deleted = 0
            
            ORDER BY 
                banco_asignado,
                emp.den,
                prov.den,
                pri.expiration_date
        ";
        
        return $this->sql->select($query, [
            PaymentRequestsModel::STATUS_PAID,
            PaymentRequestsModel::STATUS_AUTHORIZED
        ]) ?: false;
    }

    /**
     * Obtener resumen por banco de facturas autorizadas pendientes
     * @return array|false
     */
    public function get_authorized_pending_summary_by_bank() : array|false {
        $query = "
            SELECT 
                CASE 
                    WHEN pr.emp_cod IN (1, 10, 17, 18, 21, 23) THEN 'Banorte'
                    WHEN pr.emp_cod IN (11, 14, 15, 16, 19, 20) THEN 'Santander'
                    ELSE 'Sin asignar'
                END as banco,
                
                COUNT(DISTINCT pri.id) as total_facturas,
                COUNT(DISTINCT pr.provider_cod) as total_proveedores,
                SUM(pri.authorized_amount) as total_autorizado,
                MIN(pri.expiration_date) as vencimiento_mas_proximo
                
            FROM [TG].[dbo].[payment_request_invoices] pri
            INNER JOIN [TG].[dbo].[payment_requests] pr ON pri.payment_request_id = pr.id
            
            WHERE pri.payment_authorized = 1
            AND pri.status != ?
            AND (pri.amount - ISNULL(pri.paid_amount, 0)) > 0
            AND pr.status = ?
            AND pri.is_deleted = 0

            GROUP BY
                CASE
                    WHEN pr.emp_cod IN (1, 10, 17, 18, 21, 23) THEN 'Banorte'
                    WHEN pr.emp_cod IN (11, 14, 15, 16, 19, 20) THEN 'Santander'
                    ELSE 'Sin asignar'
                END
            
            ORDER BY banco
        ";
        
        return $this->sql->select($query, [
            PaymentRequestsModel::STATUS_PAID,
            PaymentRequestsModel::STATUS_AUTHORIZED
        ]) ?: false;
    }

    /**
     * Obtener facturas autorizadas pendientes AGRUPADAS por empresa y proveedor
     * @return array|false
     */
    public function get_authorized_pending_grouped() : array|false {
        $query = "
            -- PARTE 1: Facturas autorizadas pendientes (consulta original)
            SELECT
                pr.emp_cod,
                pr.provider_cod,
                emp.den as empresa_nombre,
                emp.rfc as empresa_rfc,
                prov.den as proveedor_nombre,
                prov.rfc as proveedor_rfc,
                CASE
                    WHEN pr.emp_cod IN (1, 10, 17, 18, 21, 23) THEN 'Banorte'
                    WHEN pr.emp_cod IN (11, 14, 15, 16, 19, 20) THEN 'Santander'
                    ELSE 'Sin asignar'
                END as banco_asignado,
                CASE
                    WHEN pr.emp_cod IN (1, 10, 17, 18, 21, 23) THEN '#C9302C'
                    WHEN pr.emp_cod IN (11, 14, 15, 16, 19, 20) THEN '#EC1C24'
                    ELSE '#6c757d'
                END as banco_color,
                pr.id as payment_request_id,
                pr.request_date,
                pr.scheduled_payment_date,
                COUNT(DISTINCT pri.id) as total_facturas,
                SUM(pri.authorized_amount) as total_autorizado,
                SUM(ISNULL(notas.total_credito, 0)) as total_notas_credito,
                SUM(ISNULL(notas.total_cargo, 0))   as total_notas_cargo,
                -- total_saldo = lo que este comprobante debe cubrir: el monto
                -- AUTORIZADO pendiente de pago (no el monto total de la factura,
                -- que puede exceder lo autorizado si la autorización fue parcial).
                SUM(
                    (ISNULL(pri.authorized_amount, 0) - ISNULL(pri.paid_amount, 0))
                    - ISNULL(notas.total_credito, 0)
                    + ISNULL(notas.total_cargo, 0)
                ) as total_saldo,
                MIN(pri.expiration_date) as vencimiento_mas_proximo,
                MAX(pri.expiration_date) as vencimiento_mas_lejano,
                STRING_AGG(CAST(pri.id AS VARCHAR), ',') as invoice_ids,
                STRING_AGG(pri.folio, ', ') as folios_list,
                MAX(u_auth.Nombre) as authorized_by_name,
                MAX(pri.authorized_at) as ultima_autorizacion,
                'FACTURAS' as tipo_registro
            FROM [TG].[dbo].[payment_request_invoices] pri
            INNER JOIN [TG].[dbo].[payment_requests] pr
                ON pri.payment_request_id = pr.id
            LEFT JOIN [TG].[dbo].[Usuario] u_auth
                ON pri.authorized_by = u_auth.Id
            LEFT JOIN [SG12].[dbo].[Proveedores] prov
                ON pr.provider_cod = prov.cod
            LEFT JOIN [SG12].[dbo].[Empresas] emp
                ON pr.emp_cod = emp.cod
            LEFT JOIN (
                SELECT
                    a.invoice_id,
                    SUM(CASE WHEN n.note_type = 'CREDIT' THEN a.applied_amount ELSE 0 END) as total_credito,
                    SUM(CASE WHEN n.note_type = 'DEBIT'  THEN a.applied_amount ELSE 0 END) as total_cargo
                FROM [tg].[dbo].credit_note_applications a
                INNER JOIN [tg].[dbo].invoice_credit_debit_notes n ON a.credit_note_id = n.id
                WHERE a.status = 1
                GROUP BY a.invoice_id
            ) notas ON pri.id = notas.invoice_id
            WHERE pri.payment_authorized = 1
                AND pri.status != ?
                AND (pri.amount - ISNULL(pri.paid_amount, 0)) > 0
                AND pr.status = ?
                AND pri.is_deleted = 0
            GROUP BY
                pr.id,
                pr.request_date,
                pr.scheduled_payment_date,
                pr.emp_cod,
                pr.provider_cod,
                emp.den,
                emp.rfc,
                prov.den,
                prov.rfc
            
            UNION ALL
            
            -- PARTE 2: Anticipos pendientes
            SELECT
                pr.emp_cod,
                pr.provider_cod,
                emp.den as empresa_nombre,
                emp.rfc as empresa_rfc,
                prov.den as proveedor_nombre,
                prov.rfc as proveedor_rfc,
                CASE
                    WHEN pr.emp_cod IN (1, 10, 17, 18, 21, 23) THEN 'Banorte'
                    WHEN pr.emp_cod IN (11, 14, 15, 16, 19, 20) THEN 'Santander'
                    ELSE 'Sin asignar'
                END as banco_asignado,
                CASE
                    WHEN pr.emp_cod IN (1, 10, 17, 18, 21, 23) THEN '#C9302C'
                    WHEN pr.emp_cod IN (11, 14, 15, 16, 19, 20) THEN '#EC1C24'
                    ELSE '#6c757d'
                END as banco_color,
                pr.id as payment_request_id,
                pr.request_date,
                pr.scheduled_payment_date,
                0 as total_facturas,
                pr.monto_total as total_autorizado,
                0 as total_notas_credito,
                0 as total_notas_cargo,
                pr.monto_total as total_saldo,
                pr.request_date as vencimiento_mas_proximo,
                pr.request_date as vencimiento_mas_lejano,
                NULL as invoice_ids,
                'ANTICIPO #' + CAST(pr.id AS VARCHAR) as folios_list,
                u.Nombre as authorized_by_name,
                pr.date_added as ultima_autorizacion,
                'ANTICIPO' as tipo_registro
            FROM [TG].[dbo].[payment_requests] pr
            LEFT JOIN [TG].[dbo].[Usuario] u
                ON pr.user_id = u.Id
            LEFT JOIN [SG12].[dbo].[Proveedores] prov
                ON pr.provider_cod = prov.cod
            LEFT JOIN [SG12].[dbo].[Empresas] emp
                ON pr.emp_cod = emp.cod
            WHERE pr.tipo = 1
                AND pr.status = ?  -- Autorizados
            
            ORDER BY 
                banco_asignado,
                empresa_nombre,
                proveedor_nombre,
                tipo_registro DESC  -- FACTURAS primero, ANTICIPO después
        ";
        
        return $this->sql->select($query, [
            PaymentRequestsModel::STATUS_PAID,
            PaymentRequestsModel::STATUS_AUTHORIZED,
            PaymentRequestsModel::STATUS_AUTHORIZED  // Para los anticipos
        ]) ?: false;
    }

    /**
     * Empareja comprobantes de pago (ya parseados con ComprobantePagoParser)
     * contra los grupos de facturas autorizadas pendientes (empresa+proveedor).
     *
     * Criterio: RFC empresa (ordenante) + RFC proveedor (beneficiario) + monto
     * (contra el saldo pendiente, no el autorizado, para soportar pagos
     * parciales) con tolerancia. Para comprobantes Santander, donde el RFC
     * beneficiario suele venir vacío, se cae a un fallback por RFC empresa + monto.
     *
     * NO persiste nada: solo construye la relación para mostrarla en pantalla.
     *
     * @param array $comprobantes Lista de structs de ComprobantePagoParser::parse()
     * @param float $tolerancia   Diferencia máxima de monto permitida (comisiones/redondeo)
     * @return array{grupos: array, comprobantes: array} Grupos disponibles + cada
     *         comprobante con su grupo sugerido y estado (matched|ambiguo|unmatched).
     */
    /**
     * Resuelve el proveedor_cod a partir de una cuenta de abono (CuentaLocal o
     * CLABE) buscándola en el catálogo de cuentas de terceros. Devuelve un mapa
     * cuenta(normalizada) => proveedor_cod para las cuentas recibidas.
     */
    private function resolver_provedores_por_cuenta(array $cuentas) : array {
        $cuentas = array_values(array_unique(array_filter(array_map(
            fn($c) => preg_replace('/\D/', '', (string)$c), $cuentas
        ))));
        if (empty($cuentas)) return [];

        // Comparar contra CuentaLocal y contra los 10 dígitos centrales de una CLABE.
        $variantes = [];
        foreach ($cuentas as $c) {
            $variantes[$c] = true;
            if (strlen($c) === 18) $variantes[substr($c, 3, 10)] = true; // cuenta dentro de CLABE
        }
        $lista = array_keys($variantes);
        $ph = implode(',', array_fill(0, count($lista), '?'));

        $query = "
            SELECT proveedor_cod, CuentaLocal
            FROM [TG].[dbo].[CatalogosCuentasBancarias]
            WHERE Tipo = 'Terceros' AND Activo = 1 AND proveedor_cod IS NOT NULL
              AND REPLACE(CuentaLocal, ' ', '') IN ($ph)
        ";
        $rows = $this->sql->select($query, $lista) ?: [];

        // Mapear cada cuenta original (y su variante) al proveedor_cod encontrado.
        $mapa = [];
        foreach ($rows as $r) {
            $catCuenta = preg_replace('/\D/', '', (string)$r['CuentaLocal']);
            foreach ($cuentas as $orig) {
                if ($orig === $catCuenta || (strlen($orig) === 18 && substr($orig, 3, 10) === $catCuenta)) {
                    $mapa[$orig] = (int)$r['proveedor_cod'];
                }
            }
        }
        return $mapa;
    }

    public function match_comprobantes_con_grupos(array $comprobantes, float $tolerancia = 1.00) : array {
        $grupos = $this->get_authorized_pending_grouped() ?: [];

        // Resolver proveedor por cuenta de abono (para comprobantes sin RFC de
        // beneficiario, ej. Santander). Es un dato fuerte y exacto.
        $cuentas_abono = array_map(fn($c) => $c['cuenta_abono'] ?? '', $comprobantes);
        $cuenta_a_proveedor = $this->resolver_provedores_por_cuenta($cuentas_abono);

        // Normalizar grupos a una forma ligera para el front + indexar.
        $grupos_norm = [];
        foreach ($grupos as $i => $g) {
            $grupos_norm[] = [
                'idx'             => $i,
                'payment_request_id' => $g['payment_request_id'] ?? null,
                'scheduled_payment_date' => $g['scheduled_payment_date'] ?? null,
                'emp_cod'         => $g['emp_cod'] ?? null,
                'provider_cod'    => $g['provider_cod'] ?? null,
                'empresa_nombre'  => $g['empresa_nombre'] ?? '',
                'empresa_rfc'     => strtoupper(trim($g['empresa_rfc'] ?? '')),
                'proveedor_nombre'=> $g['proveedor_nombre'] ?? '',
                'proveedor_rfc'   => strtoupper(trim($g['proveedor_rfc'] ?? '')),
                'banco_asignado'  => $g['banco_asignado'] ?? '',
                'total_autorizado'=> (float)($g['total_autorizado'] ?? 0),
                'total_saldo'     => (float)($g['total_saldo'] ?? ($g['total_autorizado'] ?? 0)),
                'total_facturas'  => (int)($g['total_facturas'] ?? 0),
                'invoice_ids'     => $g['invoice_ids'] ?? '',
                'tipo_registro'   => $g['tipo_registro'] ?? 'FACTURAS',
            ];
        }

        $usados = [];   // idx de grupo ya asignado, para no repetir
        $resultado = [];

        foreach ($comprobantes as $c) {
            $rfc_emp = strtoupper(trim($c['rfc_ordenante'] ?? ''));
            $rfc_prov = strtoupper(trim($c['rfc_beneficiario'] ?? ''));
            $nombre_prov = strtoupper(trim($c['nombre_beneficiario'] ?? ''));
            $importe = (float)($c['importe'] ?? 0);
            // Proveedor resuelto por la cuenta de abono del comprobante (si la hay)
            $cuenta_abono_norm = preg_replace('/\D/', '', (string)($c['cuenta_abono'] ?? ''));
            $prov_cod_por_cuenta = $cuenta_a_proveedor[$cuenta_abono_norm] ?? null;

            $candidatos = [];
            foreach ($grupos_norm as $g) {
                if (isset($usados[$g['idx']])) continue;
                if ($g['empresa_rfc'] === '' || $g['empresa_rfc'] !== $rfc_emp) continue;
                if (abs($g['total_saldo'] - $importe) > $tolerancia) continue;

                // Calcular fuerza del match
                if ($rfc_prov !== '' && $g['proveedor_rfc'] === $rfc_prov) {
                    $score = 3; // empresa + proveedor(RFC) + monto
                } elseif ($prov_cod_por_cuenta !== null && (int)$g['provider_cod'] === $prov_cod_por_cuenta) {
                    $score = 3; // empresa + proveedor(cuenta de abono) + monto — match fuerte
                } elseif ($rfc_prov !== '' && $g['proveedor_rfc'] !== $rfc_prov) {
                    continue;   // RFC proveedor presente pero no coincide → descartar
                } elseif ($prov_cod_por_cuenta !== null && (int)$g['provider_cod'] !== $prov_cod_por_cuenta) {
                    continue;   // cuenta resolvió otro proveedor → descartar
                } elseif ($nombre_prov !== '' &&
                          self::nombresCoinciden($nombre_prov, strtoupper($g['proveedor_nombre']))) {
                    $score = 2; // empresa + proveedor(nombre) + monto
                } else {
                    $score = 1; // empresa + monto (proveedor sin confirmar)
                }
                $candidatos[] = ['g' => $g, 'score' => $score];
            }

            // Ordenar por score desc
            usort($candidatos, fn($a, $b) => $b['score'] <=> $a['score']);

            $estado = 'unmatched';
            $grupo_sugerido = null;
            if (count($candidatos) >= 1) {
                $mejor = $candidatos[0];
                $empate = count($candidatos) > 1 && $candidatos[1]['score'] === $mejor['score'];
                $grupo_sugerido = $mejor['g'];
                if ($mejor['score'] >= 3 && !$empate) {
                    $estado = 'matched';
                } else {
                    $estado = 'ambiguo'; // score bajo (Santander) o varios candidatos: revisar
                }
                $usados[$mejor['g']['idx']] = true;
            }

            $resultado[] = [
                'comprobante'    => $c,
                'estado'         => $estado,
                'grupo_sugerido' => $grupo_sugerido,
            ];
        }

        return ['grupos' => $grupos_norm, 'comprobantes' => $resultado];
    }

    /**
     * Coincidencia laxa de nombres de proveedor (primeras 2-3 palabras), para
     * el fallback de comprobantes sin RFC de beneficiario.
     */
    private static function nombresCoinciden(string $a, string $b): bool {
        $norm = function($s) {
            $s = preg_replace('/\b(SA|SAPI|DE|CV|RL|S|C|V)\b/u', '', $s);
            return trim(preg_replace('/\s+/', ' ', $s));
        };
        $a = $norm($a); $b = $norm($b);
        if ($a === '' || $b === '') return false;
        $primeraA = strtok($a, ' ');
        return $primeraA !== false && $primeraA !== '' && strpos($b, $primeraA) !== false;
    }

    public function get_invoices_detail_by_ids($invoice_ids) : array|false {
        if (empty($invoice_ids)) {
            return false;
        }
        
        // Limpiar y validar IDs
        $ids = array_map('intval', explode(',', $invoice_ids));
        $ids_string = implode(',', $ids);
        
        $query = "
            SELECT
                pri.id,
                pri.payment_request_id,
                pri.folio,
                pri.invoice_number,
                pri.codgas,
                pri.amount,
                pri.paid_amount,
                pri.authorized_amount,
                pri.payment_authorized,
                pri.authorized_by,
                pri.authorized_at,
                pri.status,
                pri.expiration_date,
                pri.uuid,

                -- Saldo calculado
                (pri.amount - ISNULL(pri.paid_amount, 0)) as saldo,

                -- Notas de crédito y cargo por factura
                ISNULL(notas.total_notas_credito, 0) as total_notas_credito,
                ISNULL(notas.total_notas_cargo, 0)   as total_notas_cargo,
                ISNULL(notas.notas_count, 0)          as notas_count,

                -- Saldo neto (saldo - NC + ND)
                (pri.amount - ISNULL(pri.paid_amount, 0))
                    - ISNULL(notas.total_notas_credito, 0)
                    + ISNULL(notas.total_notas_cargo, 0) as saldo_neto,

                -- Información de payment_request
                pr.id as pago_id,
                pr.emp_cod,
                pr.provider_cod,
                pr.request_date as pago_fecha,

                -- Información de la estación
                g.abr as estacion_nombre,
                g.den as estacion_completa,

                -- Usuario que autorizó
                u_auth.Nombre as authorized_by_name,

                -- Proveedor
                prov.den as proveedor_nombre,
                prov.rfc as proveedor_rfc,

                -- Empresa (razón social)
                emp.den as empresa_nombre,
                emp.rfc as empresa_rfc

            FROM [TG].[dbo].[payment_request_invoices] pri

            INNER JOIN [TG].[dbo].[payment_requests] pr
                ON pri.payment_request_id = pr.id

            LEFT JOIN [SG12].[dbo].[Gasolineras] g
                ON pri.codgas = g.cod

            LEFT JOIN [TG].[dbo].[Usuario] u_auth
                ON pri.authorized_by = u_auth.Id

            LEFT JOIN [SG12].[dbo].[Proveedores] prov
                ON pr.provider_cod = prov.cod

            LEFT JOIN [SG12].[dbo].[Empresas] emp
                ON pr.emp_cod = emp.cod

            LEFT JOIN (
                SELECT
                    a.invoice_id,
                    SUM(CASE WHEN n.note_type = 'CREDIT' THEN a.applied_amount ELSE 0 END) as total_notas_credito,
                    SUM(CASE WHEN n.note_type = 'DEBIT'  THEN a.applied_amount ELSE 0 END) as total_notas_cargo,
                    COUNT(*) as notas_count
                FROM [tg].[dbo].credit_note_applications a
                INNER JOIN [tg].[dbo].invoice_credit_debit_notes n ON a.credit_note_id = n.id
                WHERE a.status = 1
                GROUP BY a.invoice_id
            ) notas ON pri.id = notas.invoice_id

            WHERE pri.id IN ($ids_string) AND pri.is_deleted = 0

            ORDER BY pri.expiration_date, pri.folio
        ";

        return $this->sql->select($query) ?: false;
    }

    /**
     * Obtiene TODAS las facturas pendientes de autorización de pago
     * de TODOS los payment_requests con status AUTHORIZED (1).
     * Para el modal de autorización masiva de pago en payment_list.
     */
    public function get_all_pending_payment_invoices() : array|false {
        $query = "
            SELECT
                pri.id,
                pri.payment_request_id,
                pri.folio,
                pri.invoice_number,
                pri.codgas,
                pri.amount,
                pri.paid_amount,
                pri.authorized_amount,
                pri.payment_authorized,
                pri.authorized_by,
                pri.authorized_at,
                pri.status,
                pri.expiration_date,
                pri.uuid,

                -- Saldo pendiente POR AUTORIZAR (no por pagar): resta lo ya
                -- autorizado en rondas anteriores, para que el modal de
                -- autorización muestre y limite solo el monto que aún falta.
                (pri.amount - ISNULL(pri.authorized_amount, 0)) as saldo,

                -- Notas de crédito y cargo por factura
                ISNULL(notas.total_notas_credito, 0) as total_notas_credito,
                ISNULL(notas.total_notas_cargo, 0)   as total_notas_cargo,
                ISNULL(notas.notas_count, 0)          as notas_count,

                -- Saldo neto pendiente por autorizar (saldo - NC + ND)
                (pri.amount - ISNULL(pri.authorized_amount, 0))
                    - ISNULL(notas.total_notas_credito, 0)
                    + ISNULL(notas.total_notas_cargo, 0) as saldo_neto,

                -- Información de payment_request
                pr.id as pago_id,
                pr.emp_cod,
                pr.provider_cod,
                pr.request_date as pago_fecha,
                CASE WHEN pr.tipo = 1 THEN 1 ELSE 0 END as es_anticipo,

                -- Información de la estación
                g.abr as estacion_nombre,
                g.den as estacion_completa,

                -- Usuario que autorizó
                u_auth.Nombre as authorized_by_name,

                -- Proveedor
                prov.den as proveedor_nombre,
                prov.rfc as proveedor_rfc,

                -- Empresa (razón social)
                emp.den as empresa_nombre,
                emp.rfc as empresa_rfc

            FROM [TG].[dbo].[payment_request_invoices] pri

            INNER JOIN [TG].[dbo].[payment_requests] pr
                ON pri.payment_request_id = pr.id

            LEFT JOIN [SG12].[dbo].[Gasolineras] g
                ON pri.codgas = g.cod

            LEFT JOIN [TG].[dbo].[Usuario] u_auth
                ON pri.authorized_by = u_auth.Id

            LEFT JOIN [SG12].[dbo].[Proveedores] prov
                ON pr.provider_cod = prov.cod

            LEFT JOIN [SG12].[dbo].[Empresas] emp
                ON pr.emp_cod = emp.cod

            LEFT JOIN (
                SELECT
                    a.invoice_id,
                    SUM(CASE WHEN n.note_type = 'CREDIT' THEN a.applied_amount ELSE 0 END) as total_notas_credito,
                    SUM(CASE WHEN n.note_type = 'DEBIT'  THEN a.applied_amount ELSE 0 END) as total_notas_cargo,
                    COUNT(*) as notas_count
                FROM [tg].[dbo].credit_note_applications a
                INNER JOIN [tg].[dbo].invoice_credit_debit_notes n ON a.credit_note_id = n.id
                WHERE a.status = 1
                GROUP BY a.invoice_id
            ) notas ON pri.id = notas.invoice_id

            WHERE pr.status IN (?, ?)
                AND pr.accounting_group_id IS NOT NULL
                AND pri.amount > ISNULL(pri.authorized_amount, 0) + 0.01
                AND pri.status != ?
                AND (pri.amount - ISNULL(pri.paid_amount, 0)) > 0
                AND pri.is_deleted = 0

            ORDER BY pr.id, pri.expiration_date, pri.folio
        ";

        return $this->sql->select($query, [
            0,  // STATUS_PENDING (aprobación de Tesorería implícita al autorizar)
            1,  // STATUS_AUTHORIZED
            4   // STATUS_CANCELLED
        ]) ?: false;
    }

    // public function get_facturas_para_layout($facturas_ids) {
    //     if (empty($facturas_ids)) return [];
        
    //     $placeholders = implode(',', array_fill(0, count($facturas_ids), '?'));
        
    //     $query = "
    //         SELECT 
    //             inv.id,
    //             inv.folio,
    //             inv.invoice_number,
    //             inv.amount AS monto_original,
    //             inv.authorized_amount AS monto_autorizado,
    //             inv.uuid,
    //             -- Datos del proveedor
    //             prov.cod AS proveedor_codigo,
    //             prov.den AS proveedor_nombre,
    //             prov.rfc AS proveedor_rfc,
    //             -- ✅ CLABE del proveedor desde cuentas TERCEROS
    //             cb_tercero.CuentaLocal AS clabe_beneficiario,
    //             cb_tercero.Descripcion AS titular_beneficiario,
    //             cb_tercero.Banco AS banco_beneficiario,
    //             cb_tercero.Id AS cuenta_beneficiario_id,
    //             -- Datos de la empresa
    //             emp.den AS empresa_nombre,
    //             emp.cod AS empresa_cod,
    //             'FACTURA' as tipo_pago,
    //             -- ✅ Cuenta PROPIA de la empresa (para validación)
    //             cb_propia.CuentaLocal AS cuenta_cargo_empresa,
    //             cb_propia.TitularCuenta AS titular_cargo
    //         FROM [TG].[dbo].[payment_request_invoices] inv
    //         INNER JOIN [SG12].[dbo].DocumentosC cg ON cg.nro = inv.folio and inv.codgas = cg.codgas and cg.tip = 1
    //         INNER JOIN [SG12].[dbo].[Proveedores] prov ON prov.cod = cg.codopr
    //         -- ✅ JOIN con cuentas TERCEROS (beneficiarios)
    //         LEFT JOIN [TG].[dbo].[CatalogosCuentasBancarias] cb_tercero 
    //             ON cb_tercero.Tipo = 'Terceros'
    //             --AND cb_tercero.Banco = 'SANTANDER'
    //             AND cb_tercero.Divisa = 'NUEVO PESO MEXICANO'
    //             AND cb_tercero.Activo = 1
    //             AND (
    //                 cb_tercero.TitularCuenta LIKE '%' + RTRIM(LTRIM(SUBSTRING(prov.den, 1, CHARINDEX(' ', prov.den + ' ')))) + '%'
    //                 OR cb_tercero.Descripcion LIKE '%' + RTRIM(LTRIM(SUBSTRING(prov.den, 1, CHARINDEX(' ', prov.den + ' ')))) + '%'
    //             )
    //         LEFT JOIN TG.dbo.payment_requests pr on inv.payment_request_id =pr.id
    //         INNER JOIN sg12.[dbo].[Empresas] emp ON emp.cod = pr.emp_cod
    //         -- ✅ JOIN con cuentas PROPIAS (ordenantes)
    //         LEFT JOIN [TG].[dbo].[CatalogosCuentasBancarias] cb_propia
    //             ON cb_propia.emp_cod = emp.cod
    //             AND cb_propia.Tipo = 'Propias'
    //             AND cb_propia.Banco = 'SANTANDER'
    //             AND cb_propia.Activo = 1
    //         WHERE inv.id IN ($placeholders)
    //         AND inv.authorized_amount > 0

    //         ORDER BY prov.den, inv.folio
    //     ";

    //     return $this->sql->select($query, $facturas_ids) ?: [];
    // }

    public function get_facturas_para_layout($facturas_ids) {
    if (empty($facturas_ids)) return [];
    
    $placeholders = implode(',', array_fill(0, count($facturas_ids), '?'));
    
    $query = "
        SELECT
            inv.id,
            inv.payment_request_id,
            inv.folio,
            inv.invoice_number,
            inv.amount AS monto_original,
            inv.authorized_amount AS monto_autorizado,
            inv.uuid,
            -- Datos del proveedor (cg para facturas normales, pr para notas de cargo is_debit_note=1)
            COALESCE(prov_cg.cod, prov_nd.cod) AS proveedor_codigo,
            COALESCE(prov_cg.den, prov_nd.den) AS proveedor_nombre,
            COALESCE(prov_cg.rfc, prov_nd.rfc) AS proveedor_rfc,
            inv.is_debit_note,

            -- ✅ SANTANDER: CLABE del proveedor desde cuentas TERCEROS
            cb_tercero_sant.CuentaLocal AS clabe_beneficiario,
            cb_tercero_sant.Descripcion AS titular_beneficiario,
            cb_tercero_sant.Banco AS banco_beneficiario,
            cb_tercero_sant.Id AS cuenta_beneficiario_id,
            cb_tercero_sant.IdBeneficiario AS clave_id_beneficiario,

            -- Datos de la empresa
            emp.den AS empresa_nombre,
            emp.cod AS empresa_cod,
            CASE WHEN inv.is_debit_note = 1 THEN 'NOTA_CARGO' ELSE 'FACTURA' END as tipo_pago,

            -- ✅ SANTANDER: Cuenta PROPIA de la empresa (CLABE)
            cb_propia_sant.CuentaLocal AS cuenta_cargo_empresa,
            cb_propia_sant.TitularCuenta AS titular_cargo,

            -- ✅ BANORTE: Cuenta PROPIA de la empresa (10 dígitos)
            cb_propia_banorte.CuentaLocal AS cuenta_cargo_banorte,
            cb_propia_banorte.TitularCuenta AS titular_cargo_banorte

        FROM [TG].[dbo].[payment_request_invoices] inv
        -- Para facturas normales: JOIN con DocumentosC y su proveedor
        LEFT JOIN [SG12].[dbo].DocumentosC cg ON inv.is_debit_note = 0 AND cg.nro = inv.folio AND inv.codgas = cg.codgas AND cg.tip = 1
        LEFT JOIN [SG12].[dbo].[Proveedores] prov_cg ON inv.is_debit_note = 0 AND prov_cg.cod = cg.codopr
        LEFT JOIN TG.dbo.payment_requests pr on inv.payment_request_id = pr.id
        -- Para notas de cargo (is_debit_note=1): proveedor desde payment_requests.provider_cod
        LEFT JOIN [SG12].[dbo].[Proveedores] prov_nd ON inv.is_debit_note = 1 AND prov_nd.cod = pr.provider_cod

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
                    cb.proveedor_cod = COALESCE(prov_cg.cod, prov_nd.cod)
                    OR (
                        cb.proveedor_cod IS NULL
                        AND (
                            cb.TitularCuenta LIKE '%' + RTRIM(LTRIM(SUBSTRING(COALESCE(prov_cg.den, prov_nd.den), 1, CHARINDEX(' ', COALESCE(prov_cg.den, prov_nd.den) + ' ')))) + '%'
                            OR cb.Descripcion LIKE '%' + RTRIM(LTRIM(SUBSTRING(COALESCE(prov_cg.den, prov_nd.den), 1, CHARINDEX(' ', COALESCE(prov_cg.den, prov_nd.den) + ' ')))) + '%'
                        )
                    )
              )
            ORDER BY
                CASE WHEN cb.proveedor_cod = COALESCE(prov_cg.cod, prov_nd.cod) THEN 0 ELSE 1 END,
                CASE WHEN LEN(cb.CuentaLocal) = 18 THEN 0 ELSE 1 END,
                cb.Id DESC
        ) cb_tercero_sant
        INNER JOIN sg12.[dbo].[Empresas] emp ON emp.cod = pr.emp_cod
        -- ✅ JOIN con cuentas PROPIAS SANTANDER (ordenantes)
        LEFT JOIN [TG].[dbo].[CatalogosCuentasBancarias] cb_propia_sant
            ON cb_propia_sant.emp_cod = emp.cod
            AND cb_propia_sant.Tipo = 'Propias'
            AND cb_propia_sant.Banco = 'SANTANDER'
            AND cb_propia_sant.Activo = 1
        
        -- ✅ JOIN con cuentas PROPIAS BANORTE (ordenantes)
        LEFT JOIN [TG].[dbo].[CatalogosCuentasBancarias] cb_propia_banorte
            ON cb_propia_banorte.emp_cod = emp.cod
            AND cb_propia_banorte.Tipo = 'Propias'
            AND cb_propia_banorte.Banco = 'BANORTE'
            AND cb_propia_banorte.Activo = 1
            
        WHERE inv.id IN ($placeholders)
        AND inv.authorized_amount > 0
        AND inv.is_deleted = 0

        ORDER BY COALESCE(prov_cg.den, prov_nd.den), inv.folio
    ";

 

    return $this->sql->select($query, $facturas_ids) ?: [];
}

    public function get_empresas_from_invoices($facturas_ids) {
        if (empty($facturas_ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($facturas_ids), '?'));
        $query = "SELECT DISTINCT  
                        t3.den AS nombre,
                        t2.emp_cod AS emp_cod
                    FROM [TG].[dbo].[payment_request_invoices] t1
                    LEFT JOIN TG.dbo.payment_requests t2 ON t1.payment_request_id = t2.id
                    INNER JOIN SG12.[dbo].[Empresas] t3 ON t3.cod = t2.emp_cod
                    WHERE t1.id IN ($placeholders) AND t1.is_deleted = 0
                    ORDER BY t3.den
                ";

        return $this->sql->select($query, $facturas_ids) ?: [];
    }

    public function validar_cuentas_para_layout($facturas_ids) {
        if (empty($facturas_ids)) {
            return [
                'success' => false,
                'message' => 'No se proporcionaron facturas'
            ];
        }
        
        $facturas = $this->get_facturas_para_layout($facturas_ids);
        
        if (empty($facturas)) {
            return [
                'success' => false,
                'message' => 'No se encontraron facturas válidas'
            ];
        }
        
        $sin_cuenta_cargo = [];
        $sin_cuenta_beneficiario = [];
        
        foreach ($facturas as $factura) {
            // Validar cuenta de cargo (empresa)
            if (!$factura['cuenta_cargo_empresa']) {
                $sin_cuenta_cargo[] = [
                    'empresa' => $factura['empresa_nombre'],
                    'emp_cod' => $factura['empresa_cod']
                ];
            }
            
            // Validar CLABE beneficiario (proveedor)
            if (!$factura['clabe_beneficiario'] || strlen($factura['clabe_beneficiario']) != 18) {
                $sin_cuenta_beneficiario[] = [
                    'folio' => $factura['folio'],
                    'proveedor' => $factura['proveedor_nombre'],
                    'rfc' => $factura['proveedor_rfc']
                ];
            }
        }
        
        // Eliminar duplicados de empresas
        $sin_cuenta_cargo = array_values(array_unique($sin_cuenta_cargo, SORT_REGULAR));
        
        if (!empty($sin_cuenta_cargo) || !empty($sin_cuenta_beneficiario)) {
            return [
                'success' => false,
                'sin_cuenta_cargo' => $sin_cuenta_cargo,
                'sin_cuenta_beneficiario' => $sin_cuenta_beneficiario,
                'message' => 'Algunas facturas no tienen cuentas bancarias configuradas'
            ];
        }
        
        return [
            'success' => true,
            'message' => 'Todas las facturas tienen cuentas configuradas',
            'total_facturas' => count($facturas)
        ];
    }

    /**
     * ✅ Obtiene el detalle de facturas por sus IDs (para modal de desglose)
     * @param array $facturas_ids
     * @return array
     */
    public function get_invoices_detail($facturas_ids) {
        if (empty($facturas_ids)) {
            return [];
        }
        
        $placeholders = implode(',', array_fill(0, count($facturas_ids), '?'));
        
        $query = "
            SELECT 
                inv.id,
                inv.folio,
                inv.invoice_number,
                inv.amount,
                inv.paid_amount,
                inv.authorized_amount,
                (inv.amount - inv.paid_amount) AS saldo,
                inv.expiration_date,
                inv.authorized_at,
                inv.payment_request_id,
                
                -- Estación
                est.nombre AS estacion_nombre,
                
                -- Usuario que autorizó
                usr.nombre AS authorized_by_name
                
            FROM [TG].[dbo].[payment_request_invoices] inv
            
            LEFT JOIN [TG].[dbo].[Estaciones] est 
                ON est.codgas = inv.codgas
            
            LEFT JOIN [TG].[dbo].[Usuarios] usr 
                ON usr.id = inv.authorized_by
            
            WHERE inv.id IN ($placeholders) AND inv.is_deleted = 0

            ORDER BY inv.expiration_date ASC, inv.folio ASC
        ";
        
        return $this->sql->select($query, $facturas_ids) ?: [];
    }

    public function get_facturas_autorizadas_by_ids($invoice_ids) : array|false {
        if (empty($invoice_ids)) {
            return false;
        }

        $ids_str = implode(',', array_map('intval', $invoice_ids));

        $query = "
            SELECT
                id,
                payment_request_id,
                folio,
                invoice_number,
                codgas,
                amount,
                paid_amount,
                authorized_amount,
                payment_authorized,
                status,
                uuid,
                (amount - ISNULL(paid_amount, 0)) as saldo
            FROM [TG].[dbo].[payment_request_invoices]
            WHERE id IN ($ids_str)
            AND payment_authorized = 1
            AND status != ?
            AND is_deleted = 0
            ORDER BY payment_request_id, id
        ";
        return $this->sql->select($query, [PaymentRequestsModel::STATUS_PAID]) ?: false;
    }

    /**
     * Agrega una factura a un pago existente
     * @param int $payment_request_id
     * @param array $document - Datos de la factura (nro, Factura, codgas, total_fac, fechaVto, satuid)
     * @return array
     */
    public function add_invoice_to_payment($payment_request_id, $document) : array {
        try {
            $folio = $document['nro'] ?? null;
            $invoice_number = $document['Factura'] ?? null;
            $codgas = $document['codgas'] ?? null;
            // Preferir el total efectivo (FacturasRecibidas si existe, si no ControlGas)
            $amount = $document['total_mostrar'] ?? ($document['total_fac'] ?? 0);
            $expiration_date = $document['fechaVto'] ?? null;
            $uuid = $document['satuid'] ?? null;

            // Validar UUID
            if (empty($uuid)) {
                return [
                    'success' => false,
                    'message' => 'La factura no tiene UUID SAT'
                ];
            }

            // Verificar si ya existe en algún pago
            if ($this->invoice_exists_by_uuid($uuid)) {
                return [
                    'success' => false,
                    'message' => 'Esta factura ya está incluida en otro pago'
                ];
            }

            $query = '
                INSERT INTO [TG].[dbo].[payment_request_invoices]
                (payment_request_id, folio, invoice_number, codgas, amount, status, expiration_date, uuid)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ';

            $result = $this->sql->insert($query, [
                $payment_request_id,
                $folio,
                $invoice_number,
                $codgas,
                $amount,
                self::STATUS_PENDING,
                $expiration_date,
                $uuid
            ]);

            if ($result) {
                return [
                    'success' => true,
                    'message' => 'Factura agregada correctamente',
                    'invoice_id' => $result
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Error al agregar la factura'
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
     * Trae una factura puntual (no eliminada) por su id, con su estado de
     * autorización. Usado para decidir si el guard de requisición agrupada
     * aplica a esta factura específica.
     */
    public function get_invoice_by_id($invoice_id): array|false
    {
        $query = "
            SELECT id, payment_request_id, payment_authorized, folio
            FROM [TG].[dbo].[payment_request_invoices]
            WHERE id = ? AND is_deleted = 0
        ";

        return ($rs = $this->sql->select($query, [$invoice_id])) ? $rs[0] : false;
    }

    /**
     * Quita una factura de un pago (solo si no ha sido pagada)
     * @param int $invoice_id
     * @return array
     */
    public function remove_invoice_from_payment($invoice_id, $deleted_by = null) : array {
        try {
            // Verificar que la factura no tenga pagos. Se trae la fila completa para
            // poder dejar un snapshot en la auditoría antes de borrarla.
            $query_check = "
                SELECT id, payment_request_id, folio, invoice_number, codgas, amount,
                       paid_amount, status, expiration_date, uuid
                FROM [TG].[dbo].[payment_request_invoices]
                WHERE id = ? AND is_deleted = 0
            ";

            $invoice = $this->sql->select($query_check, [$invoice_id]);

            if (!$invoice || empty($invoice)) {
                return [
                    'success' => false,
                    'message' => 'Factura no encontrada'
                ];
            }

            if ($invoice[0]['paid_amount'] > 0) {
                return [
                    'success' => false,
                    'message' => 'No se puede quitar una factura que ya tiene pagos aplicados'
                ];
            }

            // Soft-delete: se conserva la fila para auditoría/restauración en vez de borrarla físicamente.
            $query_delete = "
                UPDATE [TG].[dbo].[payment_request_invoices]
                SET is_deleted = 1, deleted_at = GETDATE(), deleted_by = ?
                WHERE id = ? AND is_deleted = 0
            ";
            $result = $this->sql->update($query_delete, [$deleted_by, $invoice_id]);

            if ($result) {
                return [
                    'success' => true,
                    'message' => 'Factura eliminada del pago correctamente',
                    'invoice_snapshot' => $invoice[0]
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Error al eliminar la factura'
                ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ];
        }
    }

}
