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
            -- PARTE 1: Facturas autorizadas pendientes (consulta original)
            SELECT 
                pr.emp_cod,
                pr.provider_cod,
                emp.den as empresa_nombre,
                emp.rfc as empresa_rfc,
                prov.den as proveedor_nombre,
                prov.rfc as proveedor_rfc,
                CASE 
                    WHEN pr.emp_cod IN (1, 23, 17, 18) THEN 'Banorte'
                    WHEN pr.emp_cod IN (19, 20, 16, 10) THEN 'Santander'
                    ELSE 'Sin asignar'
                END as banco_asignado,
                CASE 
                    WHEN pr.emp_cod IN (1, 23, 17, 18) THEN '#C9302C'
                    WHEN pr.emp_cod IN (19, 20, 16, 10) THEN '#EC1C24'
                    ELSE '#6c757d'
                END as banco_color,
                COUNT(DISTINCT pri.id) as total_facturas,
                SUM(pri.authorized_amount) as total_autorizado,
                SUM(pri.amount - ISNULL(pri.paid_amount, 0)) as total_saldo,
                MIN(pri.expiration_date) as vencimiento_mas_proximo,
                MAX(pri.expiration_date) as vencimiento_mas_lejano,
                STRING_AGG(CAST(pri.id AS VARCHAR), ',') as invoice_ids,
                STRING_AGG(pri.folio, ', ') as folios_list,
                MAX(u_auth.Nombre) as authorized_by_name,
                MAX(pri.authorized_at) as ultima_autorizacion,
                'FACTURAS' as tipo_registro,
                NULL as payment_request_id
            FROM [TG].[dbo].[payment_request_invoices] pri
            INNER JOIN [TG].[dbo].[payment_requests] pr 
                ON pri.payment_request_id = pr.id
            LEFT JOIN [TG].[dbo].[Usuario] u_auth 
                ON pri.authorized_by = u_auth.Id
            LEFT JOIN [SG12].[dbo].[Proveedores] prov 
                ON pr.provider_cod = prov.cod
            LEFT JOIN [SG12].[dbo].[Empresas] emp 
                ON pr.emp_cod = emp.cod
            WHERE pri.payment_authorized = 1
                AND pri.status != ?
                AND (pri.amount - ISNULL(pri.paid_amount, 0)) > 0
                AND pr.status = ?
            GROUP BY 
                pr.emp_cod,
                pr.provider_cod,
                emp.den,
                emp.rfc,
                prov.den,
                prov.rfc
            
            UNION ALL
            
            -- PARTE 2: Anticipos pendientes
            SELECT 
                pr.emp_cod,
                pr.provider_cod,
                emp.den as empresa_nombre,
                emp.rfc as empresa_rfc,
                prov.den as proveedor_nombre,
                prov.rfc as proveedor_rfc,
                CASE 
                    WHEN pr.emp_cod IN (1, 23, 17, 18) THEN 'Banorte'
                    WHEN pr.emp_cod IN (19, 20, 16, 10) THEN 'Santander'
                    ELSE 'Sin asignar'
                END as banco_asignado,
                CASE 
                    WHEN pr.emp_cod IN (1, 23, 17, 18) THEN '#C9302C'
                    WHEN pr.emp_cod IN (19, 20, 16, 10) THEN '#EC1C24'
                    ELSE '#6c757d'
                END as banco_color,
                0 as total_facturas,
                pr.monto_total as total_autorizado,
                pr.monto_total as total_saldo,
                pr.request_date as vencimiento_mas_proximo,
                pr.request_date as vencimiento_mas_lejano,
                NULL as invoice_ids,
                'ANTICIPO #' + CAST(pr.id AS VARCHAR) as folios_list,
                u.Nombre as authorized_by_name,
                pr.date_added as ultima_autorizacion,
                'ANTICIPO' as tipo_registro,
                pr.id as payment_request_id
            FROM [TG].[dbo].[payment_requests] pr
            LEFT JOIN [TG].[dbo].[Usuario] u 
                ON pr.user_id = u.Id
            LEFT JOIN [SG12].[dbo].[Proveedores] prov 
                ON pr.provider_cod = prov.cod
            LEFT JOIN [SG12].[dbo].[Empresas] emp 
                ON pr.emp_cod = emp.cod
            WHERE pr.tipo = 1
                AND pr.status = ?  -- Autorizados
            
            ORDER BY 
                banco_asignado,
                empresa_nombre,
                proveedor_nombre,
                tipo_registro DESC  -- FACTURAS primero, ANTICIPO después
        ";
        
        return $this->sql->select($query, [
            PaymentRequestsModel::STATUS_PAID,
            PaymentRequestsModel::STATUS_AUTHORIZED,
            PaymentRequestsModel::STATUS_AUTHORIZED  // Para los anticipos
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

    // public function get_facturas_para_layout($facturas_ids) {
    //     if (empty($facturas_ids)) return [];
        
    //     $placeholders = implode(',', array_fill(0, count($facturas_ids), '?'));
        
    //     $query = "
    //         SELECT 
    //             inv.id,
    //             inv.folio,
    //             inv.invoice_number,
    //             inv.amount AS monto_original,
    //             inv.authorized_amount AS monto_autorizado,
    //             inv.uuid,
    //             -- Datos del proveedor
    //             prov.cod AS proveedor_codigo,
    //             prov.den AS proveedor_nombre,
    //             prov.rfc AS proveedor_rfc,
    //             -- ✅ CLABE del proveedor desde cuentas TERCEROS
    //             cb_tercero.CuentaLocal AS clabe_beneficiario,
    //             cb_tercero.Descripcion AS titular_beneficiario,
    //             cb_tercero.Banco AS banco_beneficiario,
    //             cb_tercero.Id AS cuenta_beneficiario_id,
    //             -- Datos de la empresa
    //             emp.den AS empresa_nombre,
    //             emp.cod AS empresa_cod,
    //             'FACTURA' as tipo_pago,
    //             -- ✅ Cuenta PROPIA de la empresa (para validación)
    //             cb_propia.CuentaLocal AS cuenta_cargo_empresa,
    //             cb_propia.TitularCuenta AS titular_cargo
    //         FROM [TG].[dbo].[payment_request_invoices] inv
    //         INNER JOIN [SG12].[dbo].DocumentosC cg ON cg.nro = inv.folio and inv.codgas = cg.codgas and cg.tip = 1
    //         INNER JOIN [SG12].[dbo].[Proveedores] prov ON prov.cod = cg.codopr
    //         -- ✅ JOIN con cuentas TERCEROS (beneficiarios)
    //         LEFT JOIN [TG].[dbo].[CatalogosCuentasBancarias] cb_tercero 
    //             ON cb_tercero.Tipo = 'Terceros'
    //             --AND cb_tercero.Banco = 'SANTANDER'
    //             AND cb_tercero.Divisa = 'NUEVO PESO MEXICANO'
    //             AND cb_tercero.Activo = 1
    //             AND (
    //                 cb_tercero.TitularCuenta LIKE '%' + RTRIM(LTRIM(SUBSTRING(prov.den, 1, CHARINDEX(' ', prov.den + ' ')))) + '%'
    //                 OR cb_tercero.Descripcion LIKE '%' + RTRIM(LTRIM(SUBSTRING(prov.den, 1, CHARINDEX(' ', prov.den + ' ')))) + '%'
    //             )
    //         LEFT JOIN TG.dbo.payment_requests pr on inv.payment_request_id =pr.id
    //         INNER JOIN sg12.[dbo].[Empresas] emp ON emp.cod = pr.emp_cod
    //         -- ✅ JOIN con cuentas PROPIAS (ordenantes)
    //         LEFT JOIN [TG].[dbo].[CatalogosCuentasBancarias] cb_propia
    //             ON cb_propia.emp_cod = emp.cod
    //             AND cb_propia.Tipo = 'Propias'
    //             AND cb_propia.Banco = 'SANTANDER'
    //             AND cb_propia.Activo = 1
    //         WHERE inv.id IN ($placeholders)
    //         AND inv.authorized_amount > 0

    //         ORDER BY prov.den, inv.folio
    //     ";

    //     return $this->sql->select($query, $facturas_ids) ?: [];
    // }

    public function get_facturas_para_layout($facturas_ids) {
    if (empty($facturas_ids)) return [];
    
    $placeholders = implode(',', array_fill(0, count($facturas_ids), '?'));
    
    $query = "
        SELECT 
            inv.id,
            inv.folio,
            inv.invoice_number,
            inv.amount AS monto_original,
            inv.authorized_amount AS monto_autorizado,
            inv.uuid,
            -- Datos del proveedor
            prov.cod AS proveedor_codigo,
            prov.den AS proveedor_nombre,
            prov.rfc AS proveedor_rfc,
            
            -- ✅ SANTANDER: CLABE del proveedor desde cuentas TERCEROS
            cb_tercero_sant.CuentaLocal AS clabe_beneficiario,
            cb_tercero_sant.Descripcion AS titular_beneficiario,
            cb_tercero_sant.Banco AS banco_beneficiario,
            cb_tercero_sant.Id AS cuenta_beneficiario_id,

            -- Datos de la empresa
            emp.den AS empresa_nombre,
            emp.cod AS empresa_cod,
            'FACTURA' as tipo_pago,

            -- ✅ SANTANDER: Cuenta PROPIA de la empresa (CLABE)
            cb_propia_sant.CuentaLocal AS cuenta_cargo_empresa,
            cb_propia_sant.TitularCuenta AS titular_cargo,

            -- ✅ BANORTE: Cuenta PROPIA de la empresa (10 dígitos)
            cb_propia_banorte.CuentaLocal AS cuenta_cargo_banorte,
            cb_propia_banorte.TitularCuenta AS titular_cargo_banorte

        FROM [TG].[dbo].[payment_request_invoices] inv
        INNER JOIN [SG12].[dbo].DocumentosC cg ON cg.nro = inv.folio and inv.codgas = cg.codgas and cg.tip = 1
        INNER JOIN [SG12].[dbo].[Proveedores] prov ON prov.cod = cg.codopr

        -- ✅ JOIN con cuentas TERCEROS SANTANDER (beneficiarios)
        LEFT JOIN [TG].[dbo].[CatalogosCuentasBancarias] cb_tercero_sant
            ON cb_tercero_sant.Tipo = 'Terceros'
            AND cb_tercero_sant.Divisa = 'NUEVO PESO MEXICANO'
            AND cb_tercero_sant.Activo = 1
            AND (
                cb_tercero_sant.TitularCuenta LIKE '%' + RTRIM(LTRIM(SUBSTRING(prov.den, 1, CHARINDEX(' ', prov.den + ' ')))) + '%'
                OR cb_tercero_sant.Descripcion LIKE '%' + RTRIM(LTRIM(SUBSTRING(prov.den, 1, CHARINDEX(' ', prov.den + ' ')))) + '%'
            )
        LEFT JOIN TG.dbo.payment_requests pr on inv.payment_request_id = pr.id
        INNER JOIN sg12.[dbo].[Empresas] emp ON emp.cod = pr.emp_cod
        -- ✅ JOIN con cuentas PROPIAS SANTANDER (ordenantes)
        LEFT JOIN [TG].[dbo].[CatalogosCuentasBancarias] cb_propia_sant
            ON cb_propia_sant.emp_cod = emp.cod
            AND cb_propia_sant.Tipo = 'Propias'
            AND cb_propia_sant.Banco = 'SANTANDER'
            AND cb_propia_sant.Activo = 1
        
        -- ✅ JOIN con cuentas PROPIAS BANORTE (ordenantes)
        LEFT JOIN [TG].[dbo].[CatalogosCuentasBancarias] cb_propia_banorte
            ON cb_propia_banorte.emp_cod = emp.cod
            AND cb_propia_banorte.Tipo = 'Propias'
            AND cb_propia_banorte.Banco = 'BANORTE'
            AND cb_propia_banorte.Activo = 1
            
        WHERE inv.id IN ($placeholders)
        AND inv.authorized_amount > 0

        ORDER BY prov.den, inv.folio
    ";

 

    return $this->sql->select($query, $facturas_ids) ?: [];
}

    public function get_empresas_from_invoices($facturas_ids) {
        if (empty($facturas_ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($facturas_ids), '?'));
        $query = "SELECT DISTINCT  
                        t3.den AS nombre,
                        t2.emp_cod AS emp_cod
                    FROM [TG].[dbo].[payment_request_invoices] t1
                    LEFT JOIN TG.dbo.payment_requests t2 ON t1.payment_request_id = t2.id
                    INNER JOIN SG12.[dbo].[Empresas] t3 ON t3.cod = t2.emp_cod
                    WHERE t1.id IN ($placeholders)
                    ORDER BY t3.den
                ";

        return $this->sql->select($query, $facturas_ids) ?: [];
    }

    public function validar_cuentas_para_layout($facturas_ids) {
        if (empty($facturas_ids)) {
            return [
                'success' => false,
                'message' => 'No se proporcionaron facturas'
            ];
        }
        
        $facturas = $this->get_facturas_para_layout($facturas_ids);
        
        if (empty($facturas)) {
            return [
                'success' => false,
                'message' => 'No se encontraron facturas válidas'
            ];
        }
        
        $sin_cuenta_cargo = [];
        $sin_cuenta_beneficiario = [];
        
        foreach ($facturas as $factura) {
            // Validar cuenta de cargo (empresa)
            if (!$factura['cuenta_cargo_empresa']) {
                $sin_cuenta_cargo[] = [
                    'empresa' => $factura['empresa_nombre'],
                    'emp_cod' => $factura['empresa_cod']
                ];
            }
            
            // Validar CLABE beneficiario (proveedor)
            if (!$factura['clabe_beneficiario'] || strlen($factura['clabe_beneficiario']) != 18) {
                $sin_cuenta_beneficiario[] = [
                    'folio' => $factura['folio'],
                    'proveedor' => $factura['proveedor_nombre'],
                    'rfc' => $factura['proveedor_rfc']
                ];
            }
        }
        
        // Eliminar duplicados de empresas
        $sin_cuenta_cargo = array_values(array_unique($sin_cuenta_cargo, SORT_REGULAR));
        
        if (!empty($sin_cuenta_cargo) || !empty($sin_cuenta_beneficiario)) {
            return [
                'success' => false,
                'sin_cuenta_cargo' => $sin_cuenta_cargo,
                'sin_cuenta_beneficiario' => $sin_cuenta_beneficiario,
                'message' => 'Algunas facturas no tienen cuentas bancarias configuradas'
            ];
        }
        
        return [
            'success' => true,
            'message' => 'Todas las facturas tienen cuentas configuradas',
            'total_facturas' => count($facturas)
        ];
    }

    /**
     * ✅ Obtiene el detalle de facturas por sus IDs (para modal de desglose)
     * @param array $facturas_ids
     * @return array
     */
    public function get_invoices_detail($facturas_ids) {
        if (empty($facturas_ids)) {
            return [];
        }
        
        $placeholders = implode(',', array_fill(0, count($facturas_ids), '?'));
        
        $query = "
            SELECT 
                inv.id,
                inv.folio,
                inv.invoice_number,
                inv.amount,
                inv.paid_amount,
                inv.authorized_amount,
                (inv.amount - inv.paid_amount) AS saldo,
                inv.expiration_date,
                inv.authorized_at,
                inv.payment_request_id,
                
                -- Estación
                est.nombre AS estacion_nombre,
                
                -- Usuario que autorizó
                usr.nombre AS authorized_by_name
                
            FROM [TG].[dbo].[payment_request_invoices] inv
            
            LEFT JOIN [TG].[dbo].[Estaciones] est 
                ON est.codgas = inv.codgas
            
            LEFT JOIN [TG].[dbo].[Usuarios] usr 
                ON usr.id = inv.authorized_by
            
            WHERE inv.id IN ($placeholders)
            
            ORDER BY inv.expiration_date ASC, inv.folio ASC
        ";
        
        return $this->sql->select($query, $facturas_ids) ?: [];
    }

    public function get_facturas_autorizadas_by_ids($invoice_ids) : array|false {
        if (empty($invoice_ids)) {
            return false;
        }

        $ids_str = implode(',', array_map('intval', $invoice_ids));

        $query = "
            SELECT
                id,
                payment_request_id,
                folio,
                invoice_number,
                codgas,
                amount,
                paid_amount,
                authorized_amount,
                payment_authorized,
                status,
                uuid,
                (amount - ISNULL(paid_amount, 0)) as saldo
            FROM [TG].[dbo].[payment_request_invoices]
            WHERE id IN ($ids_str)
            AND payment_authorized = 1
            AND status != ?
            ORDER BY payment_request_id, id
        ";
        return $this->sql->select($query, [PaymentRequestsModel::STATUS_PAID]) ?: false;
    }

    /**
     * Agrega una factura a un pago existente
     * @param int $payment_request_id
     * @param array $document - Datos de la factura (nro, Factura, codgas, total_fac, fechaVto, satuid)
     * @return array
     */
    public function add_invoice_to_payment($payment_request_id, $document) : array {
        try {
            $folio = $document['nro'] ?? null;
            $invoice_number = $document['Factura'] ?? null;
            $codgas = $document['codgas'] ?? null;
            $amount = $document['total_fac'] ?? 0;
            $expiration_date = $document['fechaVto'] ?? null;
            $uuid = $document['satuid'] ?? null;

            // Validar UUID
            if (empty($uuid)) {
                return [
                    'success' => false,
                    'message' => 'La factura no tiene UUID SAT'
                ];
            }

            // Verificar si ya existe en algún pago
            if ($this->invoice_exists_by_uuid($uuid)) {
                return [
                    'success' => false,
                    'message' => 'Esta factura ya está incluida en otro pago'
                ];
            }

            $query = '
                INSERT INTO [TG].[dbo].[payment_request_invoices]
                (payment_request_id, folio, invoice_number, codgas, amount, status, expiration_date, uuid)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ';

            $result = $this->sql->insert($query, [
                $payment_request_id,
                $folio,
                $invoice_number,
                $codgas,
                $amount,
                self::STATUS_PENDING,
                $expiration_date,
                $uuid
            ]);

            if ($result) {
                return [
                    'success' => true,
                    'message' => 'Factura agregada correctamente',
                    'invoice_id' => $result
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Error al agregar la factura'
                ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Quita una factura de un pago (solo si no ha sido pagada)
     * @param int $invoice_id
     * @return array
     */
    public function remove_invoice_from_payment($invoice_id) : array {
        try {
            // Verificar que la factura no tenga pagos
            $query_check = "
                SELECT paid_amount, folio
                FROM [TG].[dbo].[payment_request_invoices]
                WHERE id = ?
            ";

            $invoice = $this->sql->select($query_check, [$invoice_id]);

            if (!$invoice || empty($invoice)) {
                return [
                    'success' => false,
                    'message' => 'Factura no encontrada'
                ];
            }

            if ($invoice[0]['paid_amount'] > 0) {
                return [
                    'success' => false,
                    'message' => 'No se puede quitar una factura que ya tiene pagos aplicados'
                ];
            }

            // Eliminar la factura
            $query_delete = "DELETE FROM [TG].[dbo].[payment_request_invoices] WHERE id = ?";
            $result = $this->sql->delete($query_delete, [$invoice_id]);

            if ($result) {
                return [
                    'success' => true,
                    'message' => 'Factura eliminada del pago correctamente'
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Error al eliminar la factura'
                ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ];
        }
    }

}
