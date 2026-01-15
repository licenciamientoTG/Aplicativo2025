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
                t1.authorized_amount,
                t1.payment_authorized,
                t1.authorized_by,
                t1.authorized_at,
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

    /**
     * Autorizar facturas para pago
     * @param int $payment_id
     * @param array $facturas - Array de facturas con invoice_id, monto_autorizado, saldo_anterior
     * @param int $user_id - Usuario que autoriza
     * @return array
     */
    public function authorize_invoices_for_payment($payment_id, $facturas, $user_id) : array {
        if (empty($facturas)) {
            return [
                'success' => false,
                'message' => 'No hay facturas para autorizar'
            ];
        }

        $this->sql->beginTransaction();
        
        try {
            $facturas_autorizadas = 0;
            $total_autorizado = 0;
            $errores = [];
            
            foreach ($facturas as $factura) {
                $invoice_id = $factura['invoice_id'] ?? null;
                $monto_autorizado = floatval($factura['monto_autorizado'] ?? 0);
                $saldo_anterior = floatval($factura['saldo_anterior'] ?? 0);
                $folio = $factura['folio'] ?? 'N/A';
                
                // Validar datos de la factura
                if (!$invoice_id || $monto_autorizado <= 0) {
                    $errores[] = "Factura {$folio}: datos inválidos";
                    continue;
                }
                
                // Validar que no exceda el saldo
                if ($monto_autorizado > $saldo_anterior) {
                    $errores[] = "Factura {$folio}: monto excede saldo disponible";
                    continue;
                }
                
                // Verificar que la factura pertenece a este payment_request y obtener su estado actual
                $query_check = "
                    SELECT id, amount, paid_amount, payment_authorized 
                    FROM [TG].[dbo].[payment_request_invoices]
                    WHERE id = ? AND payment_request_id = ?
                ";
                
                $invoice_data = $this->sql->select($query_check, [$invoice_id, $payment_id]);
                
                if (!$invoice_data || empty($invoice_data)) {
                    $errores[] = "Factura {$folio}: no pertenece a esta solicitud";
                    continue;
                }
                
                $invoice = $invoice_data[0];
                
                // Verificar que no esté ya autorizada
                if ($invoice['payment_authorized'] == 1) {
                    $errores[] = "Factura {$folio}: ya está autorizada para pago";
                    continue;
                }
                
                // Actualizar factura con autorización
                $query_update = "
                    UPDATE [TG].[dbo].[payment_request_invoices]
                    SET 
                        authorized_amount = ?,
                        payment_authorized = 1,
                        authorized_by = ?,
                        authorized_at = GETDATE()
                    WHERE id = ?
                ";
                
                $update_result = $this->sql->update(
                    $query_update,
                    [$monto_autorizado, $user_id, $invoice_id]
                );
                
                if ($update_result) {
                    $facturas_autorizadas++;
                    $total_autorizado += $monto_autorizado;
                } else {
                    $errores[] = "Factura {$folio}: error al autorizar en base de datos";
                }
            }
            
            // Verificar si se autorizó al menos una
            if ($facturas_autorizadas === 0) {
                throw new Exception('No se pudo autorizar ninguna factura. ' . implode(', ', $errores));
            }
            
            // Commit de la transacción
            $this->sql->commit();
            
            return [
                'success' => true,
                'facturas_autorizadas' => $facturas_autorizadas,
                'total_autorizado' => $total_autorizado,
                'errores' => $errores,
                'message' => "Se autorizaron {$facturas_autorizadas} factura(s) para pago por un total de $" . number_format($total_autorizado, 2)
            ];
            
        } catch (Exception $e) {
            $this->sql->rollback();
            error_log("Error en authorize_invoices_for_payment: " . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Error al autorizar facturas: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Obtener facturas autorizadas pendientes de pago
     * @param int $payment_id
     * @return array|false
     */
    public function get_authorized_pending_payment($payment_id) : array|false {
        $query = "
            SELECT 
                id,
                folio,
                invoice_number,
                codgas,
                amount,
                paid_amount,
                authorized_amount,
                status,
                uuid,
                (amount - ISNULL(paid_amount, 0)) as saldo
            FROM [TG].[dbo].[payment_request_invoices]
            WHERE payment_request_id = ?
            AND payment_authorized = 1
            AND status != ?
            ORDER BY id
        ";
        
        return $this->sql->select($query, [$payment_id, PaymentRequestsModel::STATUS_PAID]) ?: false;
    }

    /**
     * Verificar si una factura ya está autorizada
     * @param int $invoice_id
     * @return bool
     */
    public function is_invoice_authorized($invoice_id) : bool {
        $query = "
            SELECT payment_authorized
            FROM [TG].[dbo].[payment_request_invoices]
            WHERE id = ?
        ";
        
        $result = $this->sql->select($query, [$invoice_id]);
        
        if ($result && !empty($result)) {
            return $result[0]['payment_authorized'] == 1;
        }
        
        return false;
    }

    /**
     * Verificar si todas las facturas de un pago están completamente pagadas
     * @param int $payment_id
     * @return bool
     */
    public function all_invoices_paid($payment_id) : bool {
        $query = "
            SELECT COUNT(*) as total,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as pagadas
            FROM [TG].[dbo].[payment_request_invoices]
            WHERE payment_request_id = ?
        ";
        
        $result = $this->sql->select($query, [
            PaymentRequestsModel::STATUS_PAID,
            $payment_id
        ]);
        
        if ($result && !empty($result)) {
            return ($result[0]['total'] == $result[0]['pagadas'] && $result[0]['total'] > 0);
        }
        
        return false;
    }

    /**
     * Obtener resumen de pagos de una solicitud
     * @param int $payment_id
     * @return array|null
     */
    public function get_payment_execution_summary($payment_id) : array|null {
        $query = "
            SELECT 
                COUNT(*) as total_facturas,
                SUM(amount) as monto_total,
                SUM(ISNULL(paid_amount, 0)) as total_pagado,
                SUM(authorized_amount) as total_autorizado,
                SUM(CASE WHEN payment_authorized = 1 THEN 1 ELSE 0 END) as facturas_autorizadas,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as facturas_pagadas
            FROM [TG].[dbo].[payment_request_invoices]
            WHERE payment_request_id = ?
        ";
        
        $result = $this->sql->select($query, [
            PaymentRequestsModel::STATUS_PAID,
            $payment_id
        ]);
        
        return $result ? $result[0] : null;
    }

    /**
     * Obtener todas las facturas autorizadas pendientes de pago
     * Agrupadas por empresa y razón social
     * @return array|false
     */
    public function get_authorized_pending_invoices() : array|false {
        $query = "
            SELECT 
                pri.id,
                pri.payment_request_id,
                pri.folio,
                pri.invoice_number,
                pri.codgas,
                pri.amount,
                pri.paid_amount,
                pri.authorized_amount,
                pri.payment_authorized,
                pri.authorized_by,
                pri.authorized_at,
                pri.status,
                pri.expiration_date,
                pri.uuid,
                
                -- Saldo calculado
                (pri.amount - ISNULL(pri.paid_amount, 0)) as saldo,
                
                -- Información de payment_request
                pr.id as pago_id,
                pr.emp_cod,
                pr.provider_cod,
                pr.request_date as pago_fecha,
                pr.comment as pago_comentario,
                
                -- Información de la estación
                g.abr as estacion_nombre,
                g.den as estacion_completa,
                
                -- Usuario que autorizó
                u_auth.Nombre as authorized_by_name,
                
                -- Proveedor
                prov.den as proveedor_nombre,
                prov.rfc as proveedor_rfc,
                
                -- Empresa (razón social)
                emp.den as empresa_nombre,
                emp.rfc as empresa_rfc,
                
                -- Determinar banco según emp_cod
                CASE 
                    WHEN pr.emp_cod IN (1, 23, 17, 18) THEN 'Banorte'
                    WHEN pr.emp_cod IN (19, 20, 16, 10) THEN 'Santander'
                    ELSE 'Sin asignar'
                END as banco_asignado,
                
                -- Color para agrupar visualmente
                CASE 
                    WHEN pr.emp_cod IN (1, 23, 17, 18) THEN '#C9302C'
                    WHEN pr.emp_cod IN (19, 20, 16, 10) THEN '#EC1C24'
                    ELSE '#6c757d'
                END as banco_color
                
            FROM [TG].[dbo].[payment_request_invoices] pri
            
            INNER JOIN [TG].[dbo].[payment_requests] pr 
                ON pri.payment_request_id = pr.id
            
            LEFT JOIN [SG12].[dbo].[Gasolineras] g 
                ON pri.codgas = g.cod
            
            LEFT JOIN [TG].[dbo].[Usuario] u_auth 
                ON pri.authorized_by = u_auth.Id
            
            LEFT JOIN [SG12].[dbo].[Proveedores] prov 
                ON pr.provider_cod = prov.cod
            
            LEFT JOIN [SG12].[dbo].[Empresas] emp 
                ON pr.emp_cod = emp.cod
            
            WHERE pri.payment_authorized = 1  -- Solo autorizadas
            AND pri.status != ?              -- No pagadas completamente
            AND (pri.amount - ISNULL(pri.paid_amount, 0)) > 0  -- Con saldo pendiente
            AND pr.status = ?                -- Pago autorizado
            
            ORDER BY 
                banco_asignado,
                emp.den,
                prov.den,
                pri.expiration_date
        ";
        
        return $this->sql->select($query, [
            PaymentRequestsModel::STATUS_PAID,
            PaymentRequestsModel::STATUS_AUTHORIZED
        ]) ?: false;
    }

    /**
     * Obtener resumen por banco de facturas autorizadas pendientes
     * @return array|false
     */
    public function get_authorized_pending_summary_by_bank() : array|false {
        $query = "
            SELECT 
                CASE 
                    WHEN pr.emp_cod IN (1, 23, 17, 18) THEN 'Banorte'
                    WHEN pr.emp_cod IN (19, 20, 16) THEN 'Santander'
                    ELSE 'Sin asignar'
                END as banco,
                
                COUNT(DISTINCT pri.id) as total_facturas,
                COUNT(DISTINCT pr.provider_cod) as total_proveedores,
                SUM(pri.authorized_amount) as total_autorizado,
                MIN(pri.expiration_date) as vencimiento_mas_proximo
                
            FROM [TG].[dbo].[payment_request_invoices] pri
            INNER JOIN [TG].[dbo].[payment_requests] pr ON pri.payment_request_id = pr.id
            
            WHERE pri.payment_authorized = 1
            AND pri.status != ?
            AND (pri.amount - ISNULL(pri.paid_amount, 0)) > 0
            AND pr.status = ?
            
            GROUP BY 
                CASE 
                    WHEN pr.emp_cod IN (1, 23, 17, 18) THEN 'Banorte'
                    WHEN pr.emp_cod IN (19, 20, 16, 10) THEN 'Santander'
                    ELSE 'Sin asignar'
                END
            
            ORDER BY banco
        ";
        
        return $this->sql->select($query, [
            PaymentRequestsModel::STATUS_PAID,
            PaymentRequestsModel::STATUS_AUTHORIZED
        ]) ?: false;
    }

    /**
     * Obtener facturas autorizadas pendientes AGRUPADAS por empresa y proveedor
     * @return array|false
     */
    public function get_authorized_pending_grouped() : array|false {
        $query = "
            SELECT 
                -- Agrupación
                pr.emp_cod,
                pr.provider_cod,
                
                -- Información de empresa
                emp.den as empresa_nombre,
                emp.rfc as empresa_rfc,
                
                -- Información de proveedor
                prov.den as proveedor_nombre,
                prov.rfc as proveedor_rfc,
                
                -- Determinar banco según emp_cod
                CASE 
                    WHEN pr.emp_cod IN (1, 23, 17, 18) THEN 'Banorte'
                    WHEN pr.emp_cod IN (19, 20, 16, 10) THEN 'Santander'
                    ELSE 'Sin asignar'
                END as banco_asignado,
                
                -- Color para agrupar visualmente
                CASE 
                    WHEN pr.emp_cod IN (1, 23, 17, 18) THEN '#C9302C'
                    WHEN pr.emp_cod IN (19, 20, 16, 10) THEN '#EC1C24'
                    ELSE '#6c757d'
                END as banco_color,
                
                -- Agregaciones
                COUNT(DISTINCT pri.id) as total_facturas,
                SUM(pri.authorized_amount) as total_autorizado,
                SUM(pri.amount - ISNULL(pri.paid_amount, 0)) as total_saldo,
                MIN(pri.expiration_date) as vencimiento_mas_proximo,
                MAX(pri.expiration_date) as vencimiento_mas_lejano,
                
                -- Concatenar IDs de facturas para referencia
                STRING_AGG(CAST(pri.id AS VARCHAR), ',') as invoice_ids,
                STRING_AGG(pri.folio, ', ') as folios_list,
                
                -- Información del autorizador (tomar el más reciente)
                MAX(u_auth.Nombre) as authorized_by_name,
                MAX(pri.authorized_at) as ultima_autorizacion
                
            FROM [TG].[dbo].[payment_request_invoices] pri
            
            INNER JOIN [TG].[dbo].[payment_requests] pr 
                ON pri.payment_request_id = pr.id
            
            LEFT JOIN [TG].[dbo].[Usuario] u_auth 
                ON pri.authorized_by = u_auth.Id
            
            LEFT JOIN [SG12].[dbo].[Proveedores] prov 
                ON pr.provider_cod = prov.cod
            
            LEFT JOIN [SG12].[dbo].[Empresas] emp 
                ON pr.emp_cod = emp.cod
            
            WHERE pri.payment_authorized = 1  -- Solo autorizadas
            AND pri.status != ?              -- No pagadas completamente
            AND (pri.amount - ISNULL(pri.paid_amount, 0)) > 0  -- Con saldo pendiente
            AND pr.status = ?                -- Pago autorizado
            
            GROUP BY 
                pr.emp_cod,
                pr.provider_cod,
                emp.den,
                emp.rfc,
                prov.den,
                prov.rfc,
                CASE 
                    WHEN pr.emp_cod IN (1, 23, 17, 18) THEN 'Banorte'
                    WHEN pr.emp_cod IN (19, 20, 16, 10) THEN 'Santander'
                    ELSE 'Sin asignar'
                END,
                CASE 
                    WHEN pr.emp_cod IN (1, 23, 17, 18) THEN '#C9302C'
                    WHEN pr.emp_cod IN (19, 20, 16, 10) THEN '#EC1C24'
                    ELSE '#6c757d'
                END
            
            ORDER BY 
                banco_asignado,
                emp.den,
                prov.den
        ";
        
        return $this->sql->select($query, [
            PaymentRequestsModel::STATUS_PAID,
            PaymentRequestsModel::STATUS_AUTHORIZED
        ]) ?: false;
    }

    public function get_invoices_detail_by_ids($invoice_ids) : array|false {
    if (empty($invoice_ids)) {
        return false;
    }
    
    // Limpiar y validar IDs
    $ids = array_map('intval', explode(',', $invoice_ids));
    $ids_string = implode(',', $ids);
    
    $query = "
        SELECT 
            pri.id,
            pri.payment_request_id,
            pri.folio,
            pri.invoice_number,
            pri.codgas,
            pri.amount,
            pri.paid_amount,
            pri.authorized_amount,
            pri.payment_authorized,
            pri.authorized_by,
            pri.authorized_at,
            pri.status,
            pri.expiration_date,
            pri.uuid,
            
            -- Saldo calculado
            (pri.amount - ISNULL(pri.paid_amount, 0)) as saldo,
            
            -- Información de payment_request
            pr.id as pago_id,
            pr.emp_cod,
            pr.provider_cod,
            pr.request_date as pago_fecha,
            
            -- Información de la estación
            g.abr as estacion_nombre,
            g.den as estacion_completa,
            
            -- Usuario que autorizó
            u_auth.Nombre as authorized_by_name,
            
            -- Proveedor
            prov.den as proveedor_nombre,
            prov.rfc as proveedor_rfc,
            
            -- Empresa (razón social)
            emp.den as empresa_nombre,
            emp.rfc as empresa_rfc
            
        FROM [TG].[dbo].[payment_request_invoices] pri
        
        INNER JOIN [TG].[dbo].[payment_requests] pr 
            ON pri.payment_request_id = pr.id
        
        LEFT JOIN [SG12].[dbo].[Gasolineras] g 
            ON pri.codgas = g.cod
        
        LEFT JOIN [TG].[dbo].[Usuario] u_auth 
            ON pri.authorized_by = u_auth.Id
        
        LEFT JOIN [SG12].[dbo].[Proveedores] prov 
            ON pr.provider_cod = prov.cod
        
        LEFT JOIN [SG12].[dbo].[Empresas] emp 
            ON pr.emp_cod = emp.cod
        
        WHERE pri.id IN ($ids_string)
        
        ORDER BY pri.expiration_date, pri.folio
    ";
    
    return $this->sql->select($query) ?: false;
}


}
