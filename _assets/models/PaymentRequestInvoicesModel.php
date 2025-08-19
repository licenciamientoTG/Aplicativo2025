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

    public function insertInvoicesBulk($data, $payment_request_id) : array {

        try {
            $this->sql->beginTransaction();
            $query = "INSERT INTO [TG].[dbo].[payment_request_invoices]
                        ([payment_request_id], [folio], [invoice_number], [codgas], [amount], [paid_amount], [status], [expiration_date])
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            foreach ($data as $row) {
                $this->sql->insert($query, [
                    $payment_request_id,
                    $row['nro'],
                    $row['Factura'],
                    $row['codgas'],
                    $row['total_fac'],
                    0,
                    1,
                    $row['expiration_date']
                ]);
            }
            $this->sql->commit();
            return ['success' => true];
        } catch (Exception $e) {
            $this->sql->rollBack();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }


}
