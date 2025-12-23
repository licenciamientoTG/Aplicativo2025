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
                  WHERE payment_request_id = ?
                  ORDER BY id;';
        return ($this->sql->select($query, [$payment_request_id])) ?: false;
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
        $query = 'SELECT id FROM [TG].[dbo].[payment_request_invoices] WHERE uuid = ?';
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
            WHERE pri.uuid = ?
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
            WHERE payment_request_id = ?
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
                left join sg12.[dbo].DocumentosC t3 ON t1.codgas = t3.codgas  and t1.folio = t3.nro and t3.tip = 1
                LEFT JOIN SG12.dbo.Proveedores t4 on t3.codopr = t4.cod
                WHERE t1.payment_request_id = ?
                ORDER BY t1.date_added DESC
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
            WHERE pri.payment_request_id = ?
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
                left join sg12.[dbo].DocumentosC t3 ON t1.codgas = t3.codgas  and t1.folio = t3.nro and t3.tip = 1
                LEFT JOIN SG12.dbo.Proveedores t4 on t3.codopr = t4.cod
                WHERE t1.Id IN ($placeholders)
                ORDER BY t1.date_added DESC
        ";
        $result = $this->sql->select($query, $ids);
        return $result ?: false;

    }


}
