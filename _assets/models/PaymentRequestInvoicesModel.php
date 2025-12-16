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

   

    public function get_by_payment_request($payment_request_id) : array|false {
        $query = '
            SELECT 
                t1.*,
                t2.abr as estacion_nombre,
                --t3.*,
                t4.den as proveedor_nombre
            FROM [TG].[dbo].[payment_request_invoices] t1
            LEFT JOIN sg12.[dbo].[Gasolineras] t2 ON t1.codgas = t2.cod
            left join sg12.[dbo].DocumentosC t3 ON t1.codgas = t3.codgas  and t1.folio = t3.nro and t3.tip = 1
            LEFT JOIN SG12.dbo.Proveedores t4 on t3.codopr = t4.cod
            --LEFT JOIN sg12.[dbo].[Proveedores] p ON t1.cod = p.cod
            WHERE t1.payment_request_id = ?
            ORDER BY t1.date_added DESC
        ';
        return ($this->sql->select($query, [$payment_request_id])) ?: false;
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
     * Procesar pago de facturas (con pagos parciales)
     * @param int $payment_request_id - ID de la solicitud de pago
     * @param array $facturas - Array de facturas a pagar con sus montos
     * @param int $user_id - ID del usuario que procesa el pago
     * @param string $observaciones - Observaciones del pago
     * @param string $referencia - Referencia/folio del pago
     * @param string $fecha_pago - Fecha del pago
     * @return array - ['success' => bool, 'message' => string, 'facturas_procesadas' => int, 'total_pagado' => float]
     */
    public function process_payment($payment_request_id, $facturas, $user_id, $observaciones = '', $referencia = '', $fecha_pago = null) : array {
        try {
            if (empty($facturas)) {
                return [
                    'success' => false,
                    'message' => 'No se proporcionaron facturas para procesar'
                ];
            }

            $total_pagado = 0;
            $facturas_procesadas = 0;
            $fecha_pago = $fecha_pago ?: date('Y-m-d H:i:s');

            foreach ($facturas as $factura) {
                $invoice_id = $factura['invoice_id'] ?? null;
                $monto_pagar = floatval($factura['monto_pagar'] ?? 0);

                if (!$invoice_id || $monto_pagar <= 0) {
                    continue;
                }

                // Obtener datos actuales de la factura
                $query_select = "
                    SELECT amount, paid_amount, status 
                    FROM [TG].[dbo].[payment_request_invoices] 
                    WHERE id = ?
                ";

                $current = $this->sql->select($query_select, [$invoice_id]);

                if (!$current) {
                    return [
                        'success' => false,
                        'message' => "Factura con ID $invoice_id no encontrada"
                    ];
                }

                $amount = floatval($current[0]['amount']);
                $paid_amount = floatval($current[0]['paid_amount'] ?? 0);
                $saldo = $amount - $paid_amount;

                // Validar que el monto a pagar no exceda el saldo
                if ($monto_pagar > $saldo) {
                    return [
                        'success' => false,
                        'message' => "El monto a pagar ($" . number_format($monto_pagar, 2) . ") excede el saldo disponible ($" . number_format($saldo, 2) . ") de la factura ID: $invoice_id"
                    ];
                }

                // Calcular nuevo monto pagado
                $nuevo_paid_amount = $paid_amount + $monto_pagar;

                // Determinar nuevo estado
                // Si el nuevo monto pagado es igual o mayor al monto total, está PAGADO
                // Si es menor pero mayor a 0, está PARCIAL
                if ($nuevo_paid_amount >= $amount) {
                    $nuevo_status = self::STATUS_PAID;
                } else {
                    $nuevo_status = self::STATUS_PARTIAL;
                }

                // Actualizar la factura
                $query_update = "
                    UPDATE [TG].[dbo].[payment_request_invoices] 
                    SET 
                        paid_amount = ?,
                        status = ?,
                        paid_date = ?,
                        paid_by = ?,
                        payment_reference = ?,
                        payment_notes = ?
                    WHERE id = ?
                ";

                $params = [
                    $nuevo_paid_amount,
                    $nuevo_status,
                    $fecha_pago,
                    $user_id,
                    $referencia,
                    $observaciones,
                    $invoice_id
                ];

                if (!$this->sql->update($query_update, $params)) {
                    return [
                        'success' => false,
                        'message' => "Error al actualizar la factura ID: $invoice_id"
                    ];
                }

                $total_pagado += $monto_pagar;
                $facturas_procesadas++;
            }

            if ($facturas_procesadas === 0) {
                return [
                    'success' => false,
                    'message' => 'No se procesó ninguna factura válida'
                ];
            }

            return [
                'success' => true,
                'message' => "Pago procesado exitosamente: $facturas_procesadas factura(s) por $" . number_format($total_pagado, 2),
                'facturas_procesadas' => $facturas_procesadas,
                'total_pagado' => $total_pagado
            ];

        } catch (Exception $e) {
            error_log("Error en process_payment: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error al procesar el pago: ' . $e->getMessage()
            ];
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


}
