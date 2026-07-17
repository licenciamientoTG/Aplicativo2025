<?php
class CreditNoteApplicationsModel extends Model
{
    public $id;
    public $credit_note_id;
    public $payment_request_id;
    public $invoice_id;
    public $applied_amount;
    public $status;
    public $created_by;
    public $created_at;

    /**
     * Aplicar una nota de crédito/cargo a una factura dentro de un pago
     */
    public function applyNote($data) : int|false {
        $query = "
            INSERT INTO [tg].[dbo].credit_note_applications
                (credit_note_id, payment_request_id, invoice_id, applied_amount, created_by, status)
            VALUES (?, ?, ?, ?, ?, 1)";

        $params = [
            $data['credit_note_id'],
            $data['payment_request_id'],
            $data['invoice_id'],
            $data['applied_amount'],
            $data['created_by']
        ];

        $app_id = $this->sql->insert($query, $params);

        // Invariante: authorized_amount es el NETO a pagar. Tesorería autoriza
        // el saldo neto (ya descontadas las NC existentes), y la ejecución paga
        // authorized_amount - paid_amount sin volver a restar notas. Si la NC
        // llega DESPUÉS de autorizar, hay que bajar aquí lo autorizado o el
        // banco pagaría de más (caso req. 1271: restarla aparte en las vistas
        // la descontaba doble cuando ya venía dentro de lo autorizado).
        // Piso en paid_amount: dinero ya transferido no se des-autoriza.
        // Las notas de CARGO no suben authorized_amount: la diferencia
        // reaparece como saldo por autorizar y la aprueba Tesorería.
        if ($app_id) {
            $this->sql->update("
                UPDATE pri
                SET pri.authorized_amount = CASE
                    WHEN ISNULL(pri.authorized_amount, 0) - ? < ISNULL(pri.paid_amount, 0)
                        THEN ISNULL(pri.paid_amount, 0)
                    ELSE ISNULL(pri.authorized_amount, 0) - ?
                END
                FROM [TG].[dbo].[payment_request_invoices] pri
                INNER JOIN [tg].[dbo].invoice_credit_debit_notes n ON n.id = ?
                WHERE pri.id = ?
                  AND pri.payment_authorized = 1
                  AND n.note_type = 'CREDIT'",
                [
                    $data['applied_amount'],
                    $data['applied_amount'],
                    $data['credit_note_id'],
                    $data['invoice_id']
                ]
            );
        }

        return $app_id;
    }

    /**
     * Obtener todas las aplicaciones activas de un pago con detalle de nota y factura
     */
    public function getByPayment($paymentRequestId) : array|false {
        $query = "
            SELECT
                a.*,
                n.note_type,
                n.note_number,
                n.note_date,
                n.amount       as note_total_amount,
                n.description  as note_description,
                n.provider_id,
                u.Nombre       as created_by_name,
                pri.folio      as invoice_folio,
                pri.invoice_number,
                (SELECT COUNT(*) FROM [tg].[dbo].invoice_credit_debit_notes_doc d
                 WHERE d.credit_note_id = n.id AND d.file_path IS NOT NULL) as documents_count
            FROM [tg].[dbo].credit_note_applications a
            INNER JOIN [tg].[dbo].invoice_credit_debit_notes n ON a.credit_note_id = n.id
            LEFT JOIN [tg].[dbo].payment_request_invoices pri ON a.invoice_id = pri.id
            LEFT JOIN [TG].[dbo].[Usuario] u ON a.created_by = u.Id
            WHERE a.payment_request_id = ? AND a.status = 1
            ORDER BY a.created_at DESC";
        return $this->sql->select($query, [$paymentRequestId]);
    }

    /**
     * Calcular totales de crédito y cargo aplicados a un pago
     */
    public function getTotalsByPayment($paymentRequestId) : array {
        $query = "
            SELECT
                SUM(CASE WHEN n.note_type = 'CREDIT' THEN a.applied_amount ELSE 0 END) as total_credits,
                SUM(CASE WHEN n.note_type = 'DEBIT'  THEN a.applied_amount ELSE 0 END) as total_debits
            FROM [tg].[dbo].credit_note_applications a
            INNER JOIN [tg].[dbo].invoice_credit_debit_notes n ON a.credit_note_id = n.id
            WHERE a.payment_request_id = ? AND a.status = 1";

        $result = $this->sql->select($query, [$paymentRequestId]);

        $credits = (float)($result[0]['total_credits'] ?? 0);
        $debits  = (float)($result[0]['total_debits']  ?? 0);

        return [
            'total_credits'  => $credits,
            'total_debits'   => $debits,
            'net_adjustment' => $debits - $credits
        ];
    }

    /**
     * Obtener todas las aplicaciones activas de una factura específica
     */
    public function getByInvoice($invoiceId) : array|false {
        $query = "
            SELECT
                a.*,
                n.note_type,
                n.note_number,
                n.note_date,
                n.amount       as note_total_amount,
                n.description  as note_description,
                u.Nombre       as created_by_name,
                (SELECT COUNT(*) FROM [tg].[dbo].invoice_credit_debit_notes_doc d
                 WHERE d.credit_note_id = n.id AND d.file_path IS NOT NULL) as documents_count
            FROM [tg].[dbo].credit_note_applications a
            INNER JOIN [tg].[dbo].invoice_credit_debit_notes n ON a.credit_note_id = n.id
            LEFT JOIN [TG].[dbo].[Usuario] u ON a.created_by = u.Id
            WHERE a.invoice_id = ? AND a.status = 1
            ORDER BY a.created_at DESC";
        return $this->sql->select($query, [$invoiceId]);
    }

    /**
     * Obtener todas las aplicaciones de una nota (en qué pagos/facturas fue usada)
     */
    public function getByNote($creditNoteId) : array|false {
        $query = "
            SELECT
                a.*,
                pri.folio          as invoice_folio,
                pri.invoice_number,
                pr.request_date    as payment_date
            FROM [tg].[dbo].credit_note_applications a
            LEFT JOIN [tg].[dbo].payment_request_invoices pri ON a.invoice_id = pri.id
            INNER JOIN [tg].[dbo].payment_requests pr ON a.payment_request_id = pr.id
            WHERE a.credit_note_id = ? AND a.status = 1
            ORDER BY a.created_at DESC";
        return $this->sql->select($query, [$creditNoteId]);
    }

    /**
     * Aplicaciones activas de todas las notas activas (opcionalmente de un proveedor),
     * con folio de factura y requisición. Para pintar "Asignado a" en el catálogo de notas.
     */
    public function getActiveApplicationsByProvider($providerId = null) : array|false {
        if (!$providerId || $providerId == 1) {
            $whereProvider = '';
            $params = [];
        } else {
            $whereProvider = 'AND n.provider_id = ?';
            $params = [$providerId];
        }

        $query = "
            SELECT
                a.credit_note_id,
                a.payment_request_id,
                a.invoice_id,
                a.applied_amount,
                pri.folio          as invoice_folio,
                pri.invoice_number
            FROM [tg].[dbo].credit_note_applications a
            INNER JOIN [tg].[dbo].invoice_credit_debit_notes n ON a.credit_note_id = n.id AND n.status = 1
            LEFT JOIN [tg].[dbo].payment_request_invoices pri ON a.invoice_id = pri.id
            WHERE a.status = 1
              $whereProvider
            ORDER BY a.credit_note_id, a.created_at ASC";
        return $this->sql->select($query, $params);
    }

    /**
     * Soft-delete de una aplicación (libera el saldo de la nota)
     */
    public function removeApplication($applicationId) : bool {
        $query = "
            UPDATE [tg].[dbo].credit_note_applications
            SET status = 0
            WHERE id = ? AND status = 1";
        return $this->sql->update($query, [$applicationId]);
    }

    /**
     * Recalcula y actualiza total_notas_credito y total_notas_cargo en payment_requests
     */
    public function updatePaymentNoteTotals($paymentRequestId) : bool {
        $totals = $this->getTotalsByPayment($paymentRequestId);

        $query = "
            UPDATE [TG].[dbo].[payment_requests]
            SET total_notas_credito = ?,
                total_notas_cargo = ?
            WHERE id = ?";

        return $this->sql->update($query, [
            $totals['total_credits'],
            $totals['total_debits'],
            $paymentRequestId
        ]);
    }

    /**
     * Obtener una aplicación por ID
     */
    public function getById($applicationId) : array|false {
        $query = "
            SELECT *
            FROM [tg].[dbo].credit_note_applications
            WHERE id = ?";
        $result = $this->sql->select($query, [$applicationId]);
        return $result ? $result[0] : false;
    }
}
