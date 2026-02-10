<?php
class InvoiceCreditDebitNotesModel extends Model
{
    public $id;
    public $invoice_id;
    public $payment_request_id;
    public $provider_id;
    public $note_type;
    public $note_number;
    public $note_date;
    public $amount;
    public $currency;
    public $description;
    public $reason_code;
    public $file_path;
    public $original_filename;
    public $status;
    public $created_by;
    public $created_at;
    public $approved_by;
    public $approved_at;
    public $applied_to_payment;
    public $applied_at;

    /**
     * Obtener notas de crédito/cargo para una solicitud de pago
     */
    public function getCreditDebitNotes($paymentRequestId) : array|false {
        $query = 'SELECT 
                    t1.*,
                    t2.Nombre as created_by_name,
                    (SELECT COUNT(*) 
                    FROM [tg].[dbo].invoice_credit_debit_notes_doc 
                    WHERE credit_note_id = t1.id) as documents_count,
                    (SELECT TOP 1 id 
                    FROM [tg].[dbo].invoice_credit_debit_notes_doc 
                    WHERE credit_note_id = t1.id 
                    ORDER BY created_at DESC) as latest_doc_id
                FROM [tg].[dbo].invoice_credit_debit_notes t1
                LEFT JOIN [TG].[dbo].[Usuario] t2 ON t1.created_by = t2.Id
                WHERE t1.payment_request_id = ? AND t1.status = 1
                ORDER BY t1.created_at DESC';
        return $this->sql->select($query, [$paymentRequestId]);
    }

    /**
     * Agregar nota de crédito o cargo
     */
    public function addCreditDebitNote($data) : int|false {
        $query = "
            INSERT INTO [tg].[dbo].invoice_credit_debit_notes 
                (payment_request_id, provider_id, note_type, note_number, 
                note_date, amount, description, reason_code, created_by, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)";

        $params = [
            $data['payment_request_id'],
            $data['provider_id'],
            $data['note_type'],
            $data['note_number'] ?? null,
            $data['note_date'],
            $data['amount'],
            $data['description'],
            $data['reason_code'] ?? null,
            $data['created_by']
        ];

        $result = $this->sql->insert($query, $params);
        return $result;
    }

    /**
     * Eliminar nota de crédito/cargo
     */
    public function deleteCreditDebitNote($noteId) : bool {
        $query = "
            UPDATE [tg].[dbo].invoice_credit_debit_notes 
            SET status = 0
            WHERE id = ? 
              AND status = 1";
        return $this->sql->update($query, [$noteId]);
    }

    /**
     * Obtener una nota por ID
     */
    public function getNoteById($noteId) : array|false {
        $query = "
            SELECT * 
            FROM [tg].[dbo].invoice_credit_debit_notes 
            WHERE id = ?";
        $result = $this->sql->select($query, [$noteId]);
        return $result ? $result[0] : false;
    }

    /**
     * Calcular totales de notas de crédito y cargo
     */
    public function calculateNotesTotals($paymentRequestId) : array {
        $query = "
            SELECT 
                SUM(CASE WHEN note_type = 'CREDIT' THEN amount ELSE 0 END) as total_credits,
                SUM(CASE WHEN note_type = 'DEBIT' THEN amount ELSE 0 END) as total_debits
            FROM [tg].[dbo].invoice_credit_debit_notes
            WHERE payment_request_id = ? 
              AND status = 1";
        
        $result = $this->sql->select($query, [$paymentRequestId]);
        
        if ($result && count($result) > 0) {
            return [
                'total_credits' => $result[0]['total_credits'] ?? 0,
                'total_debits' => $result[0]['total_debits'] ?? 0,
                'net_adjustment' => ($result[0]['total_debits'] ?? 0) - ($result[0]['total_credits'] ?? 0)
            ];
        }
        
        return [
            'total_credits' => 0,
            'total_debits' => 0,
            'net_adjustment' => 0
        ];
    }
}