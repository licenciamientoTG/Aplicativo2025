<?php
class InvoiceCreditDebitNotesModel extends Model
{
    public $id;
    public $provider_id;
    public $note_type;
    public $note_number;
    public $note_date;
    public $amount;
    public $currency;
    public $description;
    public $reason_code;
    public $status;
    public $created_by;
    public $created_at;

    /**
     * Obtener todas las notas activas de un proveedor (con saldo disponible calculado)
     */
    public function getNotesByProvider($providerId) : array|false {
        if (!$providerId || $providerId == 1) {
            $where  = 'WHERE t1.status = 1';
            $params = [];
        } else {
            $where  = 'WHERE t1.provider_id = ? AND t1.status = 1';
            $params = [$providerId];
        }

        $query = "
            SELECT
                t1.*,
                t2.Nombre as created_by_name,
                t3.den as proveedor,
                ISNULL((
                    SELECT SUM(a.applied_amount)
                    FROM [tg].[dbo].credit_note_applications a
                    WHERE a.credit_note_id = t1.id AND a.status = 1
                ), 0) as total_applied,
                t1.amount - ISNULL((
                    SELECT SUM(a.applied_amount)
                    FROM [tg].[dbo].credit_note_applications a
                    WHERE a.credit_note_id = t1.id AND a.status = 1
                ), 0) as available_balance,
                (SELECT COUNT(*)
                    FROM [tg].[dbo].invoice_credit_debit_notes_doc
                    WHERE credit_note_id = t1.id) as documents_count
            FROM [tg].[dbo].invoice_credit_debit_notes t1
            LEFT JOIN [TG].[dbo].[Usuario] t2 ON t1.created_by = t2.Id
            LEFT JOIN SG12.dbo.Proveedores t3 on t3.cod = t1.provider_id
                $where
            ORDER BY t1.created_at DESC";
        return $this->sql->select($query, $params);
    }

    /**
     * Obtener notas de un proveedor con saldo disponible > 0 (para aplicar en un pago)
     */
    public function getAvailableNotesByProvider($providerId) : array|false {
        $allProviders = (empty($providerId) || $providerId == '0');

        $whereProvider = $allProviders ? '' : 'AND t1.provider_id = ?';
        $params = $allProviders ? [] : [$providerId];

        $query = "
            SELECT
                t1.*,
                t2.Nombre as created_by_name,
                t3.den as provider_name,
                ISNULL((
                    SELECT SUM(a.applied_amount)
                    FROM [tg].[dbo].credit_note_applications a
                    WHERE a.credit_note_id = t1.id AND a.status = 1
                ), 0) as total_applied,
                t1.amount - ISNULL((
                    SELECT SUM(a.applied_amount)
                    FROM [tg].[dbo].credit_note_applications a
                    WHERE a.credit_note_id = t1.id AND a.status = 1
                ), 0) as available_balance
            FROM [tg].[dbo].invoice_credit_debit_notes t1
            LEFT JOIN [TG].[dbo].[Usuario] t2 ON t1.created_by = t2.Id
            LEFT JOIN SG12.dbo.Proveedores t3 ON t3.cod = t1.provider_id
            WHERE t1.status = 1
              $whereProvider
              AND t1.amount > ISNULL((
                    SELECT SUM(a.applied_amount)
                    FROM [tg].[dbo].credit_note_applications a
                    WHERE a.credit_note_id = t1.id AND a.status = 1
                ), 0)
              -- Excluir notas de cargo ya cobradas como fila de pago
              -- (is_debit_note=1, falsos fletes): esas no generan aplicación,
              -- su consumo es la propia fila (folio = note_number, mismo proveedor)
              AND NOT EXISTS (
                    SELECT 1
                    FROM [TG].[dbo].[payment_request_invoices] pri
                    INNER JOIN [TG].[dbo].[payment_requests] pr ON pr.id = pri.payment_request_id
                    WHERE pri.is_debit_note = 1
                      AND pri.is_deleted = 0
                      AND pr.is_deleted = 0
                      AND pri.folio = t1.note_number
                      AND pr.provider_cod = t1.provider_id
                )
            ORDER BY t1.note_date ASC";
        return $this->sql->select($query, $params);
    }

    /**
     * Tab "Estado por Proveedor": saldo disponible de notas de crédito activas
     * por proveedor (monto de la nota menos lo ya aplicado).
     */
    public function getAvailableCreditByProvider() : array|false {
        // La subconsulta va en CROSS APPLY: SUM() directo sobre una
        // subconsulta no es válido en SQL Server.
        $query = "
            SELECT
                n.provider_id AS provider_cod,
                SUM(n.amount - calc.aplicado) AS disponible
            FROM [tg].[dbo].invoice_credit_debit_notes n
            CROSS APPLY (
                SELECT ISNULL((
                    SELECT SUM(a.applied_amount)
                    FROM [tg].[dbo].credit_note_applications a
                    WHERE a.credit_note_id = n.id AND a.status = 1
                ), 0) AS aplicado
            ) calc
            WHERE n.status = 1
              AND n.note_type = 'CREDIT'
              AND n.amount - calc.aplicado > 0.01
            GROUP BY n.provider_id";
        return $this->sql->selectSafe($query);
    }

    /**
     * Agregar nota de crédito o cargo (sin ligar a pago ni factura)
     */
    public function addCreditDebitNote($data) : int|false {
        $query = "
            INSERT INTO [tg].[dbo].invoice_credit_debit_notes
                (provider_id, note_type, note_number,
                note_date, amount, description, reason_code, created_by, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)";

        $params = [
            $data['provider_id'],
            $data['note_type'],
            $data['note_number'] ?? null,
            $data['note_date'],
            $data['amount'],
            $data['description'],
            $data['reason_code'] ?? null,
            $data['created_by']
        ];

        return $this->sql->insert($query, $params);
    }

    /**
     * Soft delete de una nota
     */
    public function deleteCreditDebitNote($noteId) : bool {
        $query = "
            UPDATE [tg].[dbo].invoice_credit_debit_notes
            SET status = 0
            WHERE id = ? AND status = 1";
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
     * Busca el proveedor (cod) por RFC en el catálogo de SG12.
     */
    public function getProviderByRfc($rfc) : array|false {
        $query = "SELECT cod, den, rfc FROM SG12.dbo.Proveedores WHERE rfc = ?";
        $result = $this->sql->select($query, [$rfc]);
        return $result ? $result[0] : false;
    }

    /**
     * Verifica si ya existe una nota activa para un proveedor con ese número.
     * Sirve para detectar duplicados en la carga masiva.
     */
    public function existsNote($providerId, $noteNumber) : bool {
        $query = "
            SELECT TOP 1 id
            FROM [tg].[dbo].invoice_credit_debit_notes
            WHERE provider_id = ? AND note_number = ? AND status = 1";
        $result = $this->sql->select($query, [$providerId, $noteNumber]);
        return !empty($result);
    }

    /**
     * Obtener saldo disponible de una nota
     */
    public function getAvailableBalance($noteId) : float {
        $query = "
            SELECT
                CASE WHEN EXISTS (
                    -- Nota de cargo ya cobrada como fila de pago (is_debit_note=1):
                    -- consumida por la propia fila, sin aplicación de por medio
                    SELECT 1
                    FROM [TG].[dbo].[payment_request_invoices] pri
                    INNER JOIN [TG].[dbo].[payment_requests] pr ON pr.id = pri.payment_request_id
                    WHERE pri.is_debit_note = 1
                      AND pri.is_deleted = 0
                      AND pr.is_deleted = 0
                      AND pri.folio = n.note_number
                      AND pr.provider_cod = n.provider_id
                ) THEN 0
                ELSE n.amount - ISNULL((
                    SELECT SUM(a.applied_amount)
                    FROM [tg].[dbo].credit_note_applications a
                    WHERE a.credit_note_id = n.id AND a.status = 1
                ), 0)
                END as available_balance
            FROM [tg].[dbo].invoice_credit_debit_notes n
            WHERE n.id = ? AND n.status = 1";
        $result = $this->sql->select($query, [$noteId]);
        return $result ? (float)$result[0]['available_balance'] : 0.0;
    }
}
