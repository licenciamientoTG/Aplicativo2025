<?php
class PaymentRequestsModel extends Model
{
    public $id;
    public $request_date;
    public $user_id;
    public $comment;
    public $status;
    public $provider_cod;
    public $date_added;

    const STATUS_PENDING = 0;
    const STATUS_AUTHORIZED = 1;
    const STATUS_PAID = 2;
    const STATUS_CANCELLED = 3;


    public function create_payment_with_invoices($user_id, $documents, $comment = 'Pago programado', $provider_cod = null, $empresa_cod = null) : array {
        if (empty($documents) || !is_array($documents)) {
            return [
                'success' => false,
                'message' => 'No hay documentos para procesar'
            ];
        }

        $this->sql->beginTransaction();

        try {
            // 1. Crear solicitud de pago con STATUS_PENDING (0)
            $request_date = date('Y-m-d H:i:s');
            $status = self::STATUS_PENDING;
            
            $query = 'INSERT INTO [TG].[dbo].[payment_requests] 
                    (request_date, user_id, comment, [status],  provider_cod, emp_cod)
                    VALUES (?, ?, ?, ?, ?, ?);';
            
            $payment_id = $this->sql->insert($query, [$request_date, $user_id, $comment, $status, $provider_cod, $empresa_cod]);

            if (!$payment_id) {
                throw new Exception('Error al crear la solicitud de pago');
            }

            // 2. Insertar facturas asociadas directamente
            $query2 = '
                INSERT INTO [TG].[dbo].[payment_request_invoices] 
                (payment_request_id, folio, invoice_number, codgas, amount, status, expiration_date,uuid)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ';

            foreach ($documents as $doc) {
                $folio = $doc['nro'] ?? null;
                $invoice_number = $doc['Factura'] ?? null;
                $codgas = $doc['codgas'] ?? null;
                $amount = $doc['total_fac'] ?? 0;
                $expiration_date = $doc['fechaVto'] ?? null;
                $status = self::STATUS_PENDING; // 0
                $uuid = $doc['satuid'] ?? null;

                $params = [
                    $payment_id,
                    $folio,
                    $invoice_number,
                    $codgas,
                    $amount,
                    $status,
                    $expiration_date,
                    $uuid
                ];

                if (!$this->sql->insert($query2, $params)) {
                    throw new Exception('Error al insertar factura');
                }
            }

            // 3. Commit
            $this->sql->commit();

            return [
                'success' => true,
                'payment_id' => $payment_id,
                'total_documents' => count($documents),
                'message' => 'Pago programado creado exitosamente'
            ];

        } catch (Exception $e) {
            $this->sql->rollback();
            
            return [
                'success' => false,
                'message' => 'Error al procesar el pago: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Obtiene una solicitud de pago por su ID.
     * @param int $id
     * @return array|false
     */
    public function get_request_by_id($id) : array|false {
        $query = 'SELECT id, request_date, user_id, comment, status, date_added, provider_cod FROM [TG].[dbo].[payment_requests] WHERE id = ?;';
        return ($this->sql->select($query, [$id])) ?: false;
    }

    /**
     * Inserta una nueva solicitud de pago.
     * @param string $request_date
     * @param int $user_id
     * @param string $comment
     * @param string $status
     * @return bool
     */
    public function insert_request($request_date, $user_id, $comment, $status) : int|false {
        $query = 'INSERT INTO 
                    [TG].[dbo].[payment_requests] 
                    (request_date, user_id, comment, [status])
                    VALUES (?, ?, ?, ?);';

        $insert = $this->sql->insert($query, [$request_date, $user_id, $comment, $status]);

        return $insert ?: false;
    }

    /**
     * Actualiza una solicitud de pago.
     * @param int $id
     * @param string $status
     * @param string|null $comment
     * @return bool
     */
    public function update_request_status($id, $status, $comment = null) : bool {
        $query = 'UPDATE [TG].[dbo].[payment_requests] SET status = ?, comment = ISNULL(?, comment) WHERE id = ?;';
        return $this->sql->update($query, [$status, $comment, $id]);
    }

    /**
     * Elimina una solicitud de pago por su ID.
     * @param int $id
     * @return bool
     */
    public function delete_request($id) : bool {
        $query = 'DELETE FROM [TG].[dbo].[payment_requests] WHERE id = ?;';
        return $this->sql->delete($query, [$id]);
    }

    public function delete_payment_complete($payment_id) : array {
        $this->sql->beginTransaction();

        try {
            // 1. Eliminar autorizaciones
            $authModel = new PaymentRequestAuthorizationsModel();
            $authModel->delete_by_payment_request($payment_id);

            // 2. Eliminar facturas
            $invoicesModel = new PaymentRequestInvoicesModel();
            $invoicesModel->delete_by_payment_request($payment_id);

            // 3. Eliminar solicitud
            $this->delete_request($payment_id);

            // 4. Commit
            $this->sql->commit();

            return [
                'success' => true,
                'message' => 'Pago eliminado exitosamente'
            ];

        } catch (Exception $e) {
            $this->sql->rollback();
            
            return [
                'success' => false,
                'message' => 'Error al eliminar el pago: ' . $e->getMessage()
            ];
        }
    }
    public function get_requests_with_summary($type, $status = 'all') : array|false {
        $whereClauses = [];
        $params = [];

        if ($status !== 'all') {
            $whereClauses[] = "pr.status = ?";
            $params[] = $status;
        }

        if($type === 'payment'){
            $whereClauses[] = "pr.tipo IN (0)"; // Pendiente y Autorizado
        } elseif($type === 'anticipos'){
            $whereClauses[] = "pr.tipo NOT IN (0)"; // Pagado y Cancelado
        }

        $whereSQL = !empty($whereClauses) ? "WHERE " . implode(" AND ", $whereClauses) : "";

        $query = "
            SELECT 
                pr.id,
                pr.user_id,
                pr.request_date,
                pr.status,
                pr.comment,
                u.Nombre as usuario_nombre,

                -- Resumen de facturas
                COUNT(DISTINCT pri.id) as total_invoices,
                ISNULL(SUM(pri.amount), 0) as total_amount,
                ISNULL(SUM(pri.paid_amount), 0) as total_paid,

                -- Autorizaciones por nivel
                MAX(CASE WHEN pra.permission_number = 66 THEN 1 ELSE 0 END) as auth_abastos,
                MAX(CASE WHEN pra.permission_number = 67 THEN 1 ELSE 0 END) as auth_admin,
                MAX(CASE WHEN pra.permission_number = 68 THEN 1 ELSE 0 END) as auth_tesoreria,

                -- Información de autorizadores
                MAX(CASE WHEN pra.permission_number = 66 THEN u2.Nombre ELSE NULL END) as auth_abastos_user,
                MAX(CASE WHEN pra.permission_number = 67 THEN u3.Nombre ELSE NULL END) as auth_admin_user,
                MAX(CASE WHEN pra.permission_number = 68 THEN u4.Nombre ELSE NULL END) as auth_tesoreria_user,

                -- Fechas de autorización
                MAX(CASE WHEN pra.permission_number = 66 THEN pra.authorization_date ELSE NULL END) as auth_abastos_date,
                MAX(CASE WHEN pra.permission_number = 67 THEN pra.authorization_date ELSE NULL END) as auth_admin_date,
                MAX(CASE WHEN pra.permission_number = 68 THEN pra.authorization_date ELSE NULL END) as auth_tesoreria_date,

                COUNT(DISTINCT pra.id) as total_authorizations,
                u5.den as [provider_name],
				u6.den as [emp_name]

            FROM [TG].[dbo].[payment_requests] pr
            LEFT JOIN [TG].[dbo].[Usuario] u ON pr.user_id = u.Id
            LEFT JOIN [TG].[dbo].[payment_request_invoices] pri ON pr.id = pri.payment_request_id
            LEFT JOIN [TG].[dbo].[payment_request_authorizations] pra ON pr.id = pra.payment_request_id
            LEFT JOIN [TG].[dbo].[Usuario] u2 ON pra.staff_user_id = u2.Id AND pra.permission_number = 66
            LEFT JOIN [TG].[dbo].[Usuario] u3 ON pra.staff_user_id = u3.Id AND pra.permission_number = 67
            LEFT JOIN [TG].[dbo].[Usuario] u4 ON pra.staff_user_id = u4.Id AND pra.permission_number = 68
            	LEFT JOIN [SG12].[dbo].Proveedores u5 on pr.provider_cod = u5.cod
			LEFT JOIN [SG12].[dbo].Empresas u6 on pr.emp_cod = u6.cod
            $whereSQL
            GROUP BY pr.id, pr.user_id, pr.request_date, pr.status, pr.comment, u.Nombre,u5.den,u6.den
            ORDER BY pr.request_date DESC
        ";
        return $this->sql->select($query, $params) ?: false;
    }

    public function get_all_requests() : array|false {
        $query = 'SELECT id, request_date, user_id, comment, status, date_added FROM [TG].[dbo].[payment_requests] ORDER BY request_date DESC;';
        return ($this->sql->select($query)) ?: false;
    }

    public static function getStatusText($status) : string {
        switch ($status) {
            case self::STATUS_PENDING:
                return 'Pendiente';
            case self::STATUS_AUTHORIZED:
                return 'Autorizado';
            case self::STATUS_PAID:
                return 'Pagado';
            case self::STATUS_CANCELLED:
                return 'Cancelado';
            default:
                return 'Desconocido';
        }
    }

    /**
     * Convierte código de estado a badge HTML
     */
    public static function getStatusBadge($status) : string {
        switch ($status) {
            case self::STATUS_PENDING:
                return '<span class="badge bg-warning text-dark">Pendiente</span>';
            case self::STATUS_AUTHORIZED:
                return '<span class="badge bg-info">Autorizado</span>';
            case self::STATUS_PAID:
                return '<span class="badge bg-success">Pagado</span>';
            case self::STATUS_CANCELLED:
                return '<span class="badge bg-danger">Cancelado</span>';
            default:
                return '<span class="badge bg-secondary">Desconocido</span>';
        }
    }
    
}
