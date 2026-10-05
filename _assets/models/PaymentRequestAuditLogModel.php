<?php

class PaymentRequestAuditLogModel extends Model {

    const OP_ADD_INVOICE         = 'ADD_INVOICE';
    const OP_REMOVE_INVOICE      = 'REMOVE_INVOICE';
    const OP_UNAUTHORIZE_INVOICE = 'UNAUTHORIZE_INVOICE';
    const OP_DELETE_PAYMENT      = 'DELETE_PAYMENT';

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

    /**
     * Registra la desautorización de una factura (Tesorería la regresa a la cola).
     * Guarda el snapshot de la factura ANTES de limpiar sus campos de autorización,
     * de modo que quede el rastro del autorizador original (authorized_by/authorized_at).
     * @param int         $payment_id
     * @param array       $invoice_snapshot  Fila de payment_request_invoices antes de poner NULL
     * @param int|null    $user_id           Usuario que desautoriza
     * @param string|null $user_name
     * @param int|null    $accounting_group_id
     */
    public function log_unauthorize_invoice($payment_id, array $invoice_snapshot, $user_id, $user_name, $accounting_group_id): bool {
        return $this->insert_log(
            $payment_id,
            $invoice_snapshot['id'] ?? null,
            self::OP_UNAUTHORIZE_INVOICE,
            $user_id,
            $user_name,
            json_encode($invoice_snapshot, JSON_UNESCAPED_UNICODE),
            null,
            $accounting_group_id
        );
    }

    /**
     * Registra la eliminación de un pago ejecutado (transacción) de una factura.
     * @param int         $payment_id
     * @param int|null    $invoice_id
     * @param array       $snapshot  motivo + transacción + lote + comprobantes antes del DELETE
     * @param int|null    $user_id
     * @param string|null $user_name
     * @param int|null    $accounting_group_id
     */
    public function log_delete_payment($payment_id, $invoice_id, array $snapshot, $user_id, $user_name, $accounting_group_id): bool {
        return $this->insert_log(
            $payment_id,
            $invoice_id,
            self::OP_DELETE_PAYMENT,
            $user_id,
            $user_name,
            json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            null,
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
     * Pagos ejecutados eliminados (todas las requisiciones), más reciente primero.
     */
    public function get_deleted_payments(int $limit = 500): array {
        $query = "
            SELECT TOP ($limit)
                l.Id, l.PaymentRequestId, l.InvoiceId, l.Fecha,
                l.UsuarioAplicativo, l.UsuarioNombre, l.DatosAnteriores, l.AccountingGroupId,
                prov.den AS proveedor
            FROM [TG].[dbo].[PaymentRequestAuditLog] l
            LEFT JOIN [TG].[dbo].[payment_requests] pr ON pr.id = l.PaymentRequestId
            LEFT JOIN [SG12].[dbo].[Proveedores] prov ON prov.cod = pr.provider_cod
            WHERE l.Operacion = ?
            ORDER BY l.Fecha DESC, l.Id DESC
        ";

        return ($rs = $this->sql->select($query, [self::OP_DELETE_PAYMENT])) ? $rs : [];
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
