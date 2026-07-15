<?php
/**
 * Modelo para gestionar transacciones de pago individuales
 * Permite múltiples pagos (parciales) por factura
 */
class PaymentTransactionsModel extends Model
{
    public $id;
    public $payment_request_id;
    public $invoice_id;
    public $payment_amount;
    public $payment_date;
    public $payment_method;
    public $payment_reference;
    public $bank_account;
    public $bank_name;
    public $beneficiary_account;
    public $beneficiary_name;
    public $status;
    public $notes;
    public $created_by;
    public $created_at;
    public $confirmed_by;
    public $confirmed_at;

    // Estados de transacción
    const STATUS_PENDING = 0;
    const STATUS_PROCESSED = 1;
    const STATUS_CONFIRMED = 2;
    const STATUS_REJECTED = 3;

    public function process_bulk_payment($facturas,$user_id,$fecha_pago,$notes = null,$payment_reference = null,$payment_method = 'TRANSFERENCIA',$batch_id = null) : array {
        $this->sql->beginTransaction();
        try {
            $total_pagado = 0;
            $facturas_procesadas = 0;
            $errores = [];
            $transaction_ids = [];

            foreach ($facturas as $factura) {
                $invoice_id = intval($factura['invoice_id']);
                $monto_pagar = floatval($factura['monto_pagar']);
                $folio = $factura['folio'];
                $payment_request_id = intval($factura['payment_request_id']); // ✅ Obtener de cada factura


                if ($monto_pagar <= 0) {
                    continue;
                }

                // 1. Obtener datos de la factura
                $invoice_data = $this->get_invoice_payment_data($invoice_id);

                if (!$invoice_data) {
                    $errores[] = "Factura ID $invoice_id no encontrada";
                    throw new Exception("Factura ID $invoice_id no encontrada");
                }
                // 2. Calcular saldo disponible (neto de notas de crédito/cargo
                // y de anticipos aplicados: una nota o anticipo ya aplicado
                // reduce lo que realmente se puede pagar de esta factura)
                $ya_pagado = $this->get_total_paid_for_invoice($invoice_id);
                $notas_netas = $this->get_notas_netas_for_invoice($invoice_id);
                $anticipos = $this->get_anticipos_for_invoice($invoice_id);
                $saldo = $invoice_data['amount'] - $notas_netas - $anticipos - $ya_pagado;

                // 3. Validar que no se exceda el saldo
                if ($monto_pagar > $saldo + 0.01) {
                    $errores[] = "Factura $folio: monto excede saldo disponible";
                    throw new Exception("El monto de la factura $folio excede el saldo disponible");
                }
                $cuenta = $this->get_by_account_name($invoice_data['proveedor_nombre']);
                $cuenta_proveedor = $cuenta ? $cuenta['CuentaLocal'] : null;
                $titular_proveedor = $cuenta ? $cuenta['TitularCuenta'] : null;

                // 4. Insertar transacción de pago
                $transaction_id = $this->insert_transaction(
                    $payment_request_id,
                    $invoice_id,
                    $monto_pagar,
                    $fecha_pago,
                    $user_id,
                    $payment_method,
                    $payment_reference,
                    $notes,
                    null, // bank_account
                    $cuenta_proveedor,
                    $titular_proveedor,
                    $batch_id
                );

                if (!$transaction_id) {
                    $errores[] = "Error al insertar transacción para factura $folio";
                    throw new Exception("Error al insertar transacción para factura $folio");
                }
                $transaction_ids[] = $transaction_id;

                // 5. Actualizar paid_amount en payment_request_invoices
                $nuevo_paid_amount = $ya_pagado + $monto_pagar;

                if (!$this->update_invoice_paid_amount($invoice_id, $nuevo_paid_amount, $invoice_data['amount'])) {
                    $errores[] = "Error al actualizar factura $folio";
                    throw new Exception("Error al actualizar factura $folio");
                }

                $total_pagado += $monto_pagar;
                $facturas_procesadas++;
            }

            $this->sql->commit();

            return [
                'success'            => true,
                'message'            => "Pago procesado exitosamente: $facturas_procesadas factura(s) por $" . number_format($total_pagado, 2),
                'total_pagado'       => $total_pagado,
                'facturas_procesadas'=> $facturas_procesadas,
                'last_transaction_id'=> $transaction_id ?? null,
                'transaction_ids'    => $transaction_ids,
            ];

        } catch (Exception $e) {
            $this->sql->rollback();

            return [
                'success' => false,
                'message' => $e->getMessage(),
                'errores' => $errores
            ];
        }
    }
    public function get_by_account_name($name) : array|false {
        $query = 'SELECT * FROM [TG].[dbo].[CatalogosCuentasBancarias] WHERE Descripcion LIKE ?;';
        $params = ["%$name%"];
        $result = $this->sql->select($query, $params);
        return $result ? $result[0] : false;
    }

    private function get_invoice_payment_data($invoice_id) : array|false {
        $query = "
             SELECT 
                t1.*,
                t4.den as [proveedor_nombre]
            FROM [TG].[dbo].[payment_request_invoices] t1
            left join sg12.[dbo].DocumentosC t3 ON t1.codgas = t3.codgas  and TRY_CAST(t1.folio AS int) = t3.nro and t3.tip = 1
            LEFT JOIN SG12.dbo.Proveedores t4 on t3.codopr = t4.cod
            WHERE t1.id = ? AND t1.is_deleted = 0";
        $result = $this->sql->select($query, [$invoice_id]);
        return $result ? $result[0] : false;
    }

    /**
     * Suma neta de notas de crédito/cargo activas (status=1) aplicadas a una
     * factura: las de crédito reducen lo adeudado, las de cargo lo aumentan.
     * Se usa para que el status (Pendiente/Parcial/Pagado) compare paid_amount
     * contra el saldo NETO, no contra el monto bruto de la factura — una
     * factura saldada por nota de crédito no debe quedar mostrada como
     * "Parcial" para siempre.
     */
    private function get_notas_netas_for_invoice($invoice_id) : float {
        $query = "
            SELECT ISNULL(SUM(
                CASE WHEN n.note_type = 'CREDIT' THEN ca.applied_amount
                     WHEN n.note_type = 'DEBIT' THEN -ca.applied_amount
                     ELSE 0 END
            ), 0) as notas_netas
            FROM [TG].[dbo].[credit_note_applications] ca
            INNER JOIN [TG].[dbo].[invoice_credit_debit_notes] n ON ca.credit_note_id = n.id
            WHERE ca.invoice_id = ? AND ca.status = 1
        ";
        $result = $this->sql->select($query, [$invoice_id]);
        return $result ? floatval($result[0]['notas_netas']) : 0.0;
    }

    /**
     * Total de anticipos aplicados a una factura: cuentan como cobertura al
     * decidir el status (Pagada/Parcial), igual que en recalculate_invoice_status.
     */
    private function get_anticipos_for_invoice($invoice_id) : float {
        $query = '
            SELECT ISNULL(SUM(monto_aplicado), 0) as anticipos
            FROM [TG].[dbo].[anticipo_invoice_applications]
            WHERE invoice_id = ?
        ';
        $result = $this->sql->select($query, [$invoice_id]);
        return $result ? floatval($result[0]['anticipos']) : 0.0;
    }

    /**
     * Actualiza el paid_amount y status de una factura.
     *
     * Autorizar y pagar son pasos independientes: authorized_amount es el
     * acumulado histórico de todo lo que Tesorería ha autorizado (se haya
     * pagado ya o no) y NO se modifica aquí — pagar no "deshace" una
     * autorización ya otorgada. El pendiente por autorizar (amount -
     * authorized_amount) sigue el flujo normal de get_all_pending_payment_invoices
     * sin verse afectado por este pago.
     *
     * El status SÍ se compara contra el saldo neto (amount - NC + ND) y
     * cuenta también los anticipos aplicados como cobertura: una factura cuyo
     * remanente queda cubierto por nota de crédito o por anticipo debe quedar
     * como Pagada, no Parcial (la requisición 1241 quedó pegada en la lista
     * de autorizadas porque el pago ignoraba el anticipo). paid_amount en
     * cambio sigue siendo solo dinero real transferido (no se mezcla con
     * notas ni anticipos), para no romper la conciliación bancaria ni el
     * cálculo de Faltante/Saldo contra authorized_amount.
     */
    private function update_invoice_paid_amount($invoice_id, $nuevo_paid_amount, $total_amount) : bool {
        $notas_netas = $this->get_notas_netas_for_invoice($invoice_id);
        $anticipos = $this->get_anticipos_for_invoice($invoice_id);
        $saldo_neto = $total_amount - $notas_netas;

        // Determinar nuevo estado contra el saldo neto (cobertura = pagos + anticipos)
        if ($nuevo_paid_amount + $anticipos >= $saldo_neto - 0.01) {
            $nuevo_status = 2; // Pagado (incluye cubierto por nota de crédito/anticipo)
        } elseif ($nuevo_paid_amount + $anticipos > 0) {
            $nuevo_status = 3; // Parcial
        } else {
            $nuevo_status = 0; // Pendiente
        }

        $query = "
            UPDATE [TG].[dbo].[payment_request_invoices]
            SET paid_amount = ?,
                status = ?
            WHERE id = ?
        ";

        return $this->sql->update($query, [$nuevo_paid_amount, $nuevo_status, $invoice_id]);
    }

    /**
     * Verifica si todas las facturas de una solicitud están completamente pagadas
     */
    public function check_all_invoices_paid($payment_request_id) : bool {
        $query = "
            SELECT COUNT(*) as pendientes
            FROM [TG].[dbo].[payment_request_invoices]
            WHERE payment_request_id = ?
            AND status IN (0, 1, 3)
            AND is_deleted = 0
        ";
        
        $result = $this->sql->select($query, [$payment_request_id]);
        return $result ? ($result[0]['pendientes'] == 0) : false;
    }

    /**
     * Obtiene historial de pagos de una factura
     */
    public function get_payment_history($invoice_id) : array|false {
        $query = '
            SELECT 
                pt.*,
                u.Nombre as created_by_name,
                u2.Nombre as confirmed_by_name
            FROM [TG].[dbo].[payment_transactions] pt
            LEFT JOIN [TG].[dbo].[Usuario] u ON pt.created_by = u.Id
            LEFT JOIN [TG].[dbo].[Usuario] u2 ON pt.confirmed_by = u2.Id
            WHERE pt.invoice_id = ?
            AND pt.status IN (1, 2)
            ORDER BY pt.payment_date DESC, pt.created_at DESC
        ';
        return ($this->sql->select($query, [$invoice_id])) ?: false;
    }

    /**
     * Obtiene todas las transacciones de una solicitud de pago con detalles
     */
    public function get_transactions_with_details($payment_request_id) : array|false {
        $query = '
            SELECT 
                pt.*,
                pri.folio,
                pri.invoice_number,
                pri.amount as invoice_amount,
                est.nombre as estacion_nombre,
                u.Nombre as created_by_name
            FROM [TG].[dbo].[payment_transactions] pt
            INNER JOIN [TG].[dbo].[payment_request_invoices] pri ON pt.invoice_id = pri.id
            LEFT JOIN [TG].[dbo].[Estaciones] est ON pri.codgas = est.Codigo
            LEFT JOIN [TG].[dbo].[Usuario] u ON pt.created_by = u.Id
            WHERE pt.payment_request_id = ?
            ORDER BY pt.payment_date DESC, pt.created_at DESC
        ';
        return ($this->sql->select($query, [$payment_request_id])) ?: false;
    }

    /**
     * Inserta una nueva transacción de pago
     */
    public function insert_transaction(
        $payment_request_id,
        $invoice_id,
        $payment_amount,
        $payment_date,
        $created_by,
        $payment_method = 'TRANSFERENCIA',
        $payment_reference = null,
        $notes = null,
        $bank_account = null,
        $beneficiary_account = null,
        $beneficiary_name = null,
        $batch_id = null
    ) : int|false {
        $query = '
            INSERT INTO [TG].[dbo].[payment_transactions]
            (payment_request_id, invoice_id, payment_amount, payment_date,
             payment_method, payment_reference, notes, bank_account,
             beneficiary_account, beneficiary_name, created_by, status, batch_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ';

        return $this->sql->insert($query, [
            $payment_request_id,
            $invoice_id,
            $payment_amount,
            $payment_date,
            $payment_method,
            $payment_reference,
            $notes,
            $bank_account,
            $beneficiary_account,
            $beneficiary_name,
            $created_by,
            self::STATUS_PROCESSED,
            $batch_id
        ]);
    }

    /**
     * Obtiene todas las transacciones de una factura
     */
    public function get_by_invoice($invoice_id) : array|false {
        $query = '
            SELECT 
                pt.*,
                u.Nombre as created_by_name
            FROM [TG].[dbo].[payment_transactions] pt
            LEFT JOIN [TG].[dbo].[Usuario] u ON pt.created_by = u.Id
            WHERE pt.invoice_id = ?
            ORDER BY pt.payment_date DESC, pt.created_at DESC
        ';
        return ($this->sql->select($query, [$invoice_id])) ?: false;
    }

    /**
     * Obtiene todas las transacciones de una solicitud de pago
     */
    public function get_by_payment_request($payment_request_id) : array|false {
        $query = '
            SELECT 
                pt.*,
                pri.folio,
                pri.invoice_number,
                u.Nombre as created_by_name
            FROM [TG].[dbo].[payment_transactions] pt
            INNER JOIN [TG].[dbo].[payment_request_invoices] pri ON pt.invoice_id = pri.id
            LEFT JOIN [TG].[dbo].[Usuario] u ON pt.created_by = u.Id
            WHERE pt.payment_request_id = ?
            ORDER BY pt.payment_date DESC, pt.created_at DESC
        ';
        return ($this->sql->select($query, [$payment_request_id])) ?: false;
    }

    /**
     * Calcula el total pagado de una factura (suma de transacciones)
     */
    public function get_total_paid_for_invoice($invoice_id) : float {
        $query = '
            SELECT ISNULL(SUM(payment_amount), 0) as total_paid
            FROM [TG].[dbo].[payment_transactions]
            WHERE invoice_id = ? AND status IN (1, 2)
        ';
        $result = $this->sql->select($query, [$invoice_id]);
        return $result ? floatval($result[0]['total_paid']) : 0.0;
    }

    /**
     * Actualiza el estado de una transacción
     */
    public function update_status($id, $status, $confirmed_by = null) : bool {
        if ($confirmed_by) {
            $query = '
                UPDATE [TG].[dbo].[payment_transactions]
                SET status = ?, confirmed_by = ?, confirmed_at = GETDATE()
                WHERE id = ?
            ';
            return $this->sql->update($query, [$status, $confirmed_by, $id]);
        } else {
            $query = '
                UPDATE [TG].[dbo].[payment_transactions]
                SET status = ?
                WHERE id = ?
            ';
            return $this->sql->update($query, [$status, $id]);
        }
    }

    /**
     * Obtiene todas las transacciones con filtros para la vista "Todos los Pagos"
     */
    public function get_all_with_filters($from = null, $until = null, $provider = null, $company = null, $status = null) : array
    {
        $where  = "WHERE 1=1";
        $params = [];

        if ($from) {
            $where .= " AND CAST(pt.payment_date AS DATE) >= ?";
            $params[] = $from;
        }
        if ($until) {
            $where .= " AND CAST(pt.payment_date AS DATE) <= ?";
            $params[] = $until;
        }
        if ($provider && $provider !== '0') {
            $where .= " AND pr.provider_cod = ?";
            $params[] = intval($provider);
        }
        if ($company && $company !== '0') {
            $where .= " AND pr.emp_cod = ?";
            $params[] = intval($company);
        }
        if ($status !== null && $status !== '') {
            $where .= " AND pt.status = ?";
            $params[] = intval($status);
        }

        $query = "
            SELECT
                pt.id,
                pt.payment_request_id,
                pri.folio,
                pri.invoice_number,
                prov.den        AS proveedor,
                emp.den         AS empresa,
                est.nombre      AS estacion,
                pt.payment_amount,
                pt.payment_date,
                pt.payment_method,
                pt.payment_reference,
                pt.beneficiary_name,
                pt.beneficiary_account,
                pt.status,
                pt.notes,
                pt.created_at,
                usr.Nombre      AS creado_por
            FROM  [TG].[dbo].[payment_transactions]          pt
            INNER JOIN [TG].[dbo].[payment_request_invoices] pri  ON pt.invoice_id          = pri.id
            INNER JOIN [TG].[dbo].[payment_requests]         pr   ON pt.payment_request_id  = pr.id
            LEFT  JOIN [SG12].[dbo].[Proveedores]            prov ON pr.provider_cod        = prov.cod
            LEFT  JOIN [SG12].[dbo].[Empresas]               emp  ON pr.emp_cod             = emp.cod
            LEFT  JOIN [TG].[dbo].[Estaciones]               est  ON pri.codgas             = est.Codigo
            LEFT  JOIN [TG].[dbo].[Usuario]                  usr  ON pt.created_by          = usr.Id
            $where
            ORDER BY pt.payment_date DESC, pt.created_at DESC
        ";

        return $this->sql->select($query, $params ?: null) ?: [];
    }

    /**
     * Pagos agrupados por lote (payment_date + payment_reference + proveedor + empresa)
     * para la vista "Todos los Pagos" nivel 1 (fila padre).
     */
    public function get_lotes_with_filters($from = null, $until = null, $provider = null, $company = null) : array
    {
        $where  = "WHERE 1=1";
        $params = [];

        if ($from) {
            $where .= " AND CAST(pt.payment_date AS DATE) >= ?";
            $params[] = $from;
        }
        if ($until) {
            $where .= " AND CAST(pt.payment_date AS DATE) <= ?";
            $params[] = $until;
        }
        if ($provider && $provider !== '0') {
            $where .= " AND pr.provider_cod = ?";
            $params[] = intval($provider);
        }
        if ($company && $company !== '0') {
            $where .= " AND pr.emp_cod = ?";
            $params[] = intval($company);
        }

        $query = "
            SELECT
                MIN(pt.id)                               AS id,
                CAST(pt.payment_date AS DATE)            AS payment_date,
                pt.payment_reference,
                pt.payment_method,
                prov.den                                 AS proveedor,
                emp.den                                  AS empresa,
                pt.beneficiary_name,
                COUNT(*)                                 AS total_facturas,
                SUM(pt.payment_amount)                   AS total_monto,
                MIN(pt.status)                           AS status,
                MAX(CAST(pt.notes AS VARCHAR(MAX)))      AS notes,
                MIN(pt.created_at)                       AS created_at,
                usr.Nombre                               AS creado_por,
                -- IDs de las transacciones del lote para el child row
                STRING_AGG(CAST(pt.id AS VARCHAR), ',')  AS transaction_ids
            FROM  [TG].[dbo].[payment_transactions]          pt
            INNER JOIN [TG].[dbo].[payment_request_invoices] pri  ON pt.invoice_id         = pri.id
            INNER JOIN [TG].[dbo].[payment_requests]         pr   ON pt.payment_request_id = pr.id
            LEFT  JOIN [SG12].[dbo].[Proveedores]            prov ON pr.provider_cod       = prov.cod
            LEFT  JOIN [SG12].[dbo].[Empresas]               emp  ON pr.emp_cod            = emp.cod
            LEFT  JOIN [TG].[dbo].[Usuario]                  usr  ON pt.created_by         = usr.Id
            $where
            GROUP BY
                CAST(pt.payment_date AS DATE),
                pt.payment_reference,
                pt.payment_method,
                prov.den,
                emp.den,
                pt.beneficiary_name,
                usr.Nombre
            ORDER BY payment_date DESC, MIN(pt.created_at) DESC
        ";

        return $this->sql->select($query, $params ?: null) ?: [];
    }

    /**
     * Detalle de facturas de un lote (por lista de transaction IDs).
     * Incluye si la transacción tiene comprobante adjunto.
     */
    public function get_lote_detail(array $transaction_ids) : array
    {
        if (empty($transaction_ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($transaction_ids), '?'));

        $query = "
            SELECT
                pt.id,
                pt.payment_request_id,
                pri.folio,
                pri.invoice_number,
                est.Nombre          AS estacion,
                pt.payment_amount,
                pt.status,
                pt.notes,
                pt.created_at,
                doc.id              AS doc_id,
                doc.file_extension  AS doc_ext
            FROM  [TG].[dbo].[payment_transactions]          pt
            INNER JOIN [TG].[dbo].[payment_request_invoices] pri ON pt.invoice_id = pri.id
            LEFT  JOIN [TG].[dbo].[Estaciones]               est ON pri.codgas    = est.Codigo
            OUTER APPLY (
                SELECT TOP 1 id, file_extension
                FROM [TG].[dbo].[payment_transaction_documents]
                -- El comprobante se liga al lote (batch); fallback a la transacción
                -- para registros antiguos sin batch_id.
                WHERE (pt.batch_id IS NOT NULL AND batch_id = pt.batch_id)
                   OR transaction_id = pt.id
                ORDER BY created_at ASC
            ) doc
            WHERE pt.id IN ($placeholders)
            ORDER BY pt.created_at ASC
        ";

        return $this->sql->select($query, $transaction_ids) ?: [];
    }

    /**
     * Elimina una transacción (soft delete cambiando a REJECTED)
     */
    public function delete_transaction($id) : bool {
        return $this->update_status($id, self::STATUS_REJECTED);
    }

    /**
     * Obtiene resumen de pagos por solicitud
     */
    public function get_payment_summary($payment_request_id) : array|false {
        $query = '
            SELECT 
                COUNT(DISTINCT pt.id) as total_transactions,
                COUNT(DISTINCT pt.invoice_id) as invoices_with_payments,
                SUM(pt.payment_amount) as total_paid_amount,
                MIN(pt.payment_date) as first_payment_date,
                MAX(pt.payment_date) as last_payment_date
            FROM [TG].[dbo].[payment_transactions] pt
            WHERE pt.payment_request_id = ? AND pt.status IN (1, 2)
        ';
        $result = $this->sql->select($query, [$payment_request_id]);
        return $result ? $result[0] : false;
    }

    /**
     * Obtiene el texto del estado
     */
    public static function getStatusText($status) : string {
        return match($status) {
            self::STATUS_PENDING => 'Pendiente',
            self::STATUS_PROCESSED => 'Procesado',
            self::STATUS_CONFIRMED => 'Confirmado',
            self::STATUS_REJECTED => 'Rechazado',
            default => 'Desconocido'
        };
    }

    /**
     * Obtiene el badge HTML del estado
     */
    public static function getStatusBadge($status) : string {
        return match($status) {
            self::STATUS_PENDING => '<span class="badge bg-warning text-dark">Pendiente</span>',
            self::STATUS_PROCESSED => '<span class="badge bg-info">Procesado</span>',
            self::STATUS_CONFIRMED => '<span class="badge bg-success">Confirmado</span>',
            self::STATUS_REJECTED => '<span class="badge bg-danger">Rechazado</span>',
            default => '<span class="badge bg-secondary">Desconocido</span>'
        };
    }

}