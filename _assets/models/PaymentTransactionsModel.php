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

    public function process_bulk_payment($facturas,$user_id,$fecha_pago,$notes = null,$payment_reference = null,$payment_method = 'TRANSFERENCIA') : array {
        $this->sql->beginTransaction();
        try {
            $total_pagado = 0;
            $facturas_procesadas = 0;
            $errores = [];

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
                // 2. Calcular saldo disponible
                $ya_pagado = $this->get_total_paid_for_invoice($invoice_id);
                $saldo = $invoice_data['amount'] - $ya_pagado;

                // 3. Validar que no se exceda el saldo
                if ($monto_pagar > $saldo) {
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
                    $titular_proveedor
                );

                if (!$transaction_id) {
                    $errores[] = "Error al insertar transacción para factura $folio";
                    throw new Exception("Error al insertar transacción para factura $folio");
                }

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
            left join sg12.[dbo].DocumentosC t3 ON t1.codgas = t3.codgas  and t1.folio = t3.nro and t3.tip = 1
            LEFT JOIN SG12.dbo.Proveedores t4 on t3.codopr = t4.cod
            WHERE t1.id = ?";
        $result = $this->sql->select($query, [$invoice_id]);
        return $result ? $result[0] : false;
    }

    /**
     * Actualiza el paid_amount y status de una factura
     */
    private function update_invoice_paid_amount($invoice_id, $nuevo_paid_amount, $total_amount) : bool {
        // Determinar nuevo estado
        if ($nuevo_paid_amount >= $total_amount) {
            $nuevo_status = 2; // Pagado
        } elseif ($nuevo_paid_amount > 0) {
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
            LEFT JOIN [SG12].[dbo].[Estaciones] est ON pri.codgas = est.codgas
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
        $beneficiary_name = null
    ) : int|false {
        $query = '
            INSERT INTO [TG].[dbo].[payment_transactions]
            (payment_request_id, invoice_id, payment_amount, payment_date, 
             payment_method, payment_reference, notes, bank_account,
             beneficiary_account, beneficiary_name, created_by, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
            self::STATUS_PROCESSED
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
            LEFT  JOIN [SG12].[dbo].[Empresas]               emp  ON pr.emp_cod             = emp.codemp
            LEFT  JOIN [SG12].[dbo].[Estaciones]             est  ON pri.codgas             = est.codgas
            LEFT  JOIN [TG].[dbo].[Usuario]                  usr  ON pt.created_by          = usr.Id
            $where
            ORDER BY pt.payment_date DESC, pt.created_at DESC
        ";

        return $this->sql->select($query, $params ?: null) ?: [];
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