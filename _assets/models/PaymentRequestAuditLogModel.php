<?php

class PaymentRequestAuditLogModel extends Model {

    const OP_ADD_INVOICE    = 'ADD_INVOICE';
    const OP_REMOVE_INVOICE = 'REMOVE_INVOICE';

    /**
     * Registra el borrado de una factura de una requisición.
     * @param int        $payment_id
     * @param array      $invoice_snapshot  Fila completa de payment_request_invoices antes del DELETE
     * @param int|null   $user_id
     * @param string|null $user_name
     * @param int|null   $accounting_group_id  accounting_group_id del pago al momento del movimiento
     */
    public function log_remove_invoice($payment_id, array $invoice_snapshot, $user_id, $user_name, $accounting_group_id): bool {
        return $this->insert_log(
            $payment_id,
            $invoice_snapshot['id'] ?? null,
            self::OP_REMOVE_INVOICE,
            $user_id,
            $user_name,
            json_encode($invoice_snapshot, JSON_UNESCAPED_UNICODE),
            null,
            $accounting_group_id
        );
    }

    /**
     * Registra el alta de una factura en una requisición.
     * @param int        $payment_id
     * @param array      $invoice_data      Datos insertados (folio, invoice_number, codgas, amount, uuid, ...)
     * @param int|null   $invoice_id        Id generado por el INSERT, si se conoce
     * @param int|null   $user_id
     * @param string|null $user_name
     * @param int|null   $accounting_group_id
     */
    public function log_add_invoice($payment_id, array $invoice_data, $invoice_id, $user_id, $user_name, $accounting_group_id): bool {
        return $this->insert_log(
            $payment_id,
            $invoice_id,
            self::OP_ADD_INVOICE,
            $user_id,
            $user_name,
            null,
            json_encode($invoice_data, JSON_UNESCAPED_UNICODE),
            $accounting_group_id
        );
    }

    private function insert_log($payment_id, $invoice_id, $operacion, $user_id, $user_name, $datos_anteriores, $datos_nuevos, $accounting_group_id): bool {
        $query = "
            INSERT INTO [TG].[dbo].[PaymentRequestAuditLog]
            (PaymentRequestId, InvoiceId, Operacion, UsuarioAplicativo, UsuarioNombre, DatosAnteriores, DatosNuevos, AccountingGroupId)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ";

        return (bool)$this->sql->insert($query, [
            $payment_id,
            $invoice_id,
            $operacion,
            $user_id,
            $user_name,
            $datos_anteriores,
            $datos_nuevos,
            $accounting_group_id
        ]);
    }

    /**
     * Historial de movimientos de una requisición, más reciente primero.
     */
    public function get_by_payment($payment_id): array {
        $query = "
            SELECT
                Id, PaymentRequestId, InvoiceId, Operacion, Fecha,
                UsuarioAplicativo, UsuarioNombre, DatosAnteriores, DatosNuevos, AccountingGroupId
            FROM [TG].[dbo].[PaymentRequestAuditLog]
            WHERE PaymentRequestId = ?
            ORDER BY Fecha DESC
        ";

        return ($rs = $this->sql->select($query, [$payment_id])) ? $rs : [];
    }
}
