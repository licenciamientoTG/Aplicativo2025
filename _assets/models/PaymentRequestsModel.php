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
    public $emp_cod;
    public $tipo; // 0: Pago, 1: Anticipo
    public $monto_total; // NUEVO CAMPO


    const STATUS_PENDING = 0;
    const STATUS_AUTHORIZED = 1;
    const STATUS_PAID = 2;
    const STATUS_CANCELLED = 3;


    public function create_payment_with_invoices($user_id, $documents, $comment = 'Pago programado', $provider_cod = null, $empresa_cod = null, $monto_total = 0) : array {
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
                    (request_date, user_id, comment, [status],  provider_cod, emp_cod,monto_total)
                    VALUES (?, ?, ?, ?, ?, ?, ?);';
            
            $payment_id = $this->sql->insert($query, [$request_date, $user_id, $comment, $status, $provider_cod, $empresa_cod, $monto_total]);

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

    public function get_request_by_id($id) : array|false {
        $query = 'SELECT 
                    t1.*,
                    t2.Nombre as [usuario_nombre]
                    FROM [TG].[dbo].[payment_requests] t1
                    left join TG.dbo.Usuario t2 on t1.[user_id] = t2.[Id]
                    WHERE t1.id = ?;';
        return ($rs =$this->sql->select($query, [$id])) ? $rs[0] : false;
    }


    public function insert_request($request_date, $user_id, $comment, $status) : int|false {
        $query = 'INSERT INTO 
                    [TG].[dbo].[payment_requests] 
                    (request_date, user_id, comment, [status])
                    VALUES (?, ?, ?, ?);';

        $insert = $this->sql->insert($query, [$request_date, $user_id, $comment, $status]);

        return $insert ?: false;
    }

    public function update_request_status($id, $status, $comment = null) : bool {
        $query = 'UPDATE [TG].[dbo].[payment_requests] SET status = ?, comment = ISNULL(?, comment) WHERE id = ?;';
        return $this->sql->update($query, [$status, $comment, $id]);
    }

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
    public function get_requests_with_summary($type, $status = 'all') : array|false
    {
        $whereClauses = [];
        $params = [];

        if ($status !== 'all') {
            $whereClauses[] = "t1.status = ?";
            $params[] = $status;
        }

        if ($type === 'payment') {
            $whereClauses[] = "t1.tipo IN (0)";
        } elseif ($type === 'anticipos') {
            $whereClauses[] = "t1.tipo NOT IN (0)";
        }

        $whereSQL = !empty($whereClauses)
            ? "WHERE " . implode(" AND ", $whereClauses)
            : "";

        $query = "
            SELECT 
                t1.id,
                t1.user_id,
                t1.request_date,
                t1.status,
                t1.comment,
                t3.Nombre AS usuario_nombre,
                -- Resumen de facturas
                ISNULL(t2.total_invoices, 0) AS total_invoices,
                ISNULL(t2.total_amount, 0)   AS total_amount,
                ISNULL(t2.total_paid, 0)     AS total_paid,
                ISNULL(t2.authorized_invoices_count, 0) AS authorized_invoices_count,
                ISNULL(t2.authorized_amount_total, 0)   AS authorized_amount_total,
                -- Autorizaciones por nivel
                ISNULL(t4.auth_abastos, 0)    AS auth_abastos,
                ISNULL(t4.auth_admin, 0)      AS auth_admin,
                ISNULL(t4.auth_tesoreria, 0)  AS auth_tesoreria,
                -- Información de autorizadores
                t4.auth_abastos_user,
                t4.auth_admin_user,
                t4.auth_tesoreria_user,
                -- Fechas de autorización
                t4.auth_abastos_date,
                t4.auth_admin_date,
                t4.auth_tesoreria_date,
                ISNULL(t4.total_authorizations, 0) AS total_authorizations,
                t5.den AS provider_name,
                t6.den AS emp_name
            FROM [TG].[dbo].[payment_requests] t1
            LEFT JOIN (
                SELECT 
                    payment_request_id,
                    COUNT(*) AS total_invoices,
                    SUM(amount) AS total_amount,
                    SUM(paid_amount) AS total_paid,
                    SUM(CASE WHEN payment_authorized = 1 THEN 1 ELSE 0 END) AS authorized_invoices_count,
                    SUM(CASE WHEN payment_authorized = 1 THEN ISNULL(authorized_amount, 0) ELSE 0 END) AS authorized_amount_total
                FROM tg.dbo.payment_request_invoices
                GROUP BY payment_request_id
            ) t2 ON t1.id = t2.payment_request_id
            LEFT JOIN [TG].[dbo].[Usuario] t3 ON t1.user_id = t3.Id
            -- === Agregado de autorizaciones ===
            LEFT JOIN (
                SELECT
                    pra.payment_request_id,
                    MAX(CASE WHEN pra.permission_number = 66 THEN 1 ELSE 0 END) AS auth_abastos,
                    MAX(CASE WHEN pra.permission_number = 67 THEN 1 ELSE 0 END) AS auth_admin,
                    MAX(CASE WHEN pra.permission_number = 68 THEN 1 ELSE 0 END) AS auth_tesoreria,
                    MAX(CASE WHEN pra.permission_number = 66 THEN u.Nombre END) AS auth_abastos_user,
                    MAX(CASE WHEN pra.permission_number = 67 THEN u.Nombre END) AS auth_admin_user,
                    MAX(CASE WHEN pra.permission_number = 68 THEN u.Nombre END) AS auth_tesoreria_user,
                    MAX(CASE WHEN pra.permission_number = 66 THEN pra.authorization_date END) AS auth_abastos_date,
                    MAX(CASE WHEN pra.permission_number = 67 THEN pra.authorization_date END) AS auth_admin_date,
                    MAX(CASE WHEN pra.permission_number = 68 THEN pra.authorization_date END) AS auth_tesoreria_date,
                    COUNT(*) AS total_authorizations
                FROM tg.dbo.payment_request_authorizations pra
                LEFT JOIN tg.dbo.Usuario u
                    ON pra.staff_user_id = u.Id
                GROUP BY pra.payment_request_id
            ) t4 ON t1.id = t4.payment_request_id
            LEFT JOIN [SG12].[dbo].Proveedores t5 ON t1.provider_cod = t5.cod
            LEFT JOIN [SG12].[dbo].Empresas t6 ON t1.emp_cod = t6.cod
            $whereSQL
            ORDER BY t1.request_date DESC
        ";
        return $this->sql->select($query, $params) ?: false;
    }


    public function create_anticipo($data) : array {
        // Validar datos requeridos
        if (empty($data['provider_cod']) || empty($data['empresa_cod']) || empty($data['monto_total'])) {
            return [
                'success' => false,
                'message' => 'Faltan datos requeridos para crear el anticipo'
            ];
        }
        $this->sql->beginTransaction();
        try {
            // Preparar datos para inserción
            $request_date = date('Y-m-d H:i:s');
            $user_id = $data['user_id'];
            $provider_cod = $data['provider_cod'];
            $empresa_cod = $data['empresa_cod'];
            $monto_total = $data['monto_total'];
            $nombre_request = $data['nombre_request'] ?? 'ANTICIPO';
            $comentario = $data['comentario'] ?? '';
            $tipo = 1; // 1 = Anticipo
            $status = self::STATUS_PENDING; // 0 = Pendiente
            
            // Query de inserción
            $query = 'INSERT INTO [TG].[dbo].[payment_requests] 
                    (request_date, user_id, comment, [status], provider_cod, emp_cod, tipo, monto_total)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?);';
            
            $params = [
                $request_date,
                $user_id,
                $comentario,
                $status,
                $provider_cod,
                $empresa_cod,
                $tipo,
                $monto_total  // NUEVO PARÁMETRO
            ];
            
            // Insertar anticipo
            $anticipo_id = $this->sql->insert($query, $params);
            
            if (!$anticipo_id) {
                throw new Exception('Error al insertar el anticipo en la base de datos');
            }
            
            // Commit de la transacción
            $this->sql->commit();
            
            return [
                'success' => true,
                'anticipo_id' => $anticipo_id,
                'message' => 'Anticipo creado exitosamente'
            ];
            
        } catch (Exception $e) {
            // Rollback en caso de error
            $this->sql->rollback();
            
            // Log del error
            error_log('Error en create_anticipo: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            
            return [
                'success' => false,
                'message' => 'Error al crear el anticipo: ' . $e->getMessage()
            ];
        }
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


    public static function getStatusBadge($status) : string {
        switch ($status) {
            case self::STATUS_PENDING:
                return '<span class="badge bg-warning text-dark">Pendiente</span>';
            case self::STATUS_AUTHORIZED:
                return '<span class="badge text-bg-dark">Autorizado</span>';
            case self::STATUS_PAID:
                return '<span class="badge text-bg-success">Pagado</span>';
            case self::STATUS_CANCELLED:
                return '<span class="badge bg-danger">Cancelado</span>';
            default:
                return '<span class="badge bg-secondary">Desconocido</span>';
        }
    }

    public function get_anticipos_with_summary( $type  ='payment', $status = 'all') : array|false {
        $whereClauses = ["t1.tipo = 1"]; // Solo anticipos
        $params = [];

        if ($status !== 'all') {
            $statusMap = [
                'pending' => self::STATUS_PENDING,
                'authorized' => self::STATUS_AUTHORIZED,
                'paid' => self::STATUS_PAID,
                'cancelled' => self::STATUS_CANCELLED
            ];
            
            if (isset($statusMap[$status])) {
                $whereClauses[] = "t1.status = ?";
                $params[] = $statusMap[$status];
            }
        }
        if($type === 'payment'){
            $whereClauses[] = "t1.tipo IN (0)"; // Pendiente y Autorizado
        } elseif($type === 'anticipos'){
            $whereClauses[] = "t1.tipo NOT IN (0)"; // Pagado y Cancelado
        }


        $whereSQL = "WHERE " . implode(" AND ", $whereClauses);

        $query = "
                        SELECT 
                t1.id,
                t1.user_id,
                t1.request_date,
                t1.status,
                t1.comment,
                t3.Nombre AS usuario_nombre,
                -- Resumen de facturas
                ISNULL(t2.total_invoices, 0) AS total_invoices,
                ISNULL(t2.total_amount, 0)   AS total_amount,
                ISNULL(t1.monto_total, 0)   AS monto_total,
                ISNULL(t2.total_paid, 0)     AS total_paid,
                ISNULL(t2.authorized_invoices_count, 0) AS authorized_invoices_count,
                ISNULL(t2.authorized_amount_total, 0)   AS authorized_amount_total,
                -- Autorizaciones por nivel
                ISNULL(t4.auth_abastos, 0)    AS auth_abastos,
                ISNULL(t4.auth_admin, 0)      AS auth_admin,
                ISNULL(t4.auth_tesoreria, 0)  AS auth_tesoreria,
                -- Información de autorizadores
                t4.auth_abastos_user,
                t4.auth_admin_user,
                t4.auth_tesoreria_user,
                -- Fechas de autorización
                t4.auth_abastos_date,
                t4.auth_admin_date,
                t4.auth_tesoreria_date,
                ISNULL(t4.total_authorizations, 0) AS total_authorizations,
                t5.den AS provider_name,
                t6.den AS emp_name
            FROM [TG].[dbo].[payment_requests] t1
            LEFT JOIN (
                SELECT 
                    payment_request_id,
                    COUNT(*) AS total_invoices,
                    SUM(amount) AS total_amount,
                    SUM(paid_amount) AS total_paid,
                    SUM(CASE WHEN payment_authorized = 1 THEN 1 ELSE 0 END) AS authorized_invoices_count,
                    SUM(CASE WHEN payment_authorized = 1 THEN ISNULL(authorized_amount, 0) ELSE 0 END) AS authorized_amount_total
                FROM tg.dbo.payment_request_invoices
                GROUP BY payment_request_id
            ) t2 ON t1.id = t2.payment_request_id
            LEFT JOIN [TG].[dbo].[Usuario] t3 ON t1.user_id = t3.Id
            -- === Agregado de autorizaciones ===
            LEFT JOIN (
                SELECT
                    pra.payment_request_id,
                    MAX(CASE WHEN pra.permission_number = 66 THEN 1 ELSE 0 END) AS auth_abastos,
                    MAX(CASE WHEN pra.permission_number = 67 THEN 1 ELSE 0 END) AS auth_admin,
                    MAX(CASE WHEN pra.permission_number = 68 THEN 1 ELSE 0 END) AS auth_tesoreria,
                    MAX(CASE WHEN pra.permission_number = 66 THEN u.Nombre END) AS auth_abastos_user,
                    MAX(CASE WHEN pra.permission_number = 67 THEN u.Nombre END) AS auth_admin_user,
                    MAX(CASE WHEN pra.permission_number = 68 THEN u.Nombre END) AS auth_tesoreria_user,
                    MAX(CASE WHEN pra.permission_number = 66 THEN pra.authorization_date END) AS auth_abastos_date,
                    MAX(CASE WHEN pra.permission_number = 67 THEN pra.authorization_date END) AS auth_admin_date,
                    MAX(CASE WHEN pra.permission_number = 68 THEN pra.authorization_date END) AS auth_tesoreria_date,
                    COUNT(*) AS total_authorizations
                FROM tg.dbo.payment_request_authorizations pra
                LEFT JOIN tg.dbo.Usuario u
                    ON pra.staff_user_id = u.Id
                GROUP BY pra.payment_request_id
            ) t4 ON t1.id = t4.payment_request_id
            LEFT JOIN [SG12].[dbo].Proveedores t5 ON t1.provider_cod = t5.cod
            LEFT JOIN [SG12].[dbo].Empresas t6 ON t1.emp_cod = t6.cod
            $whereSQL
            ORDER BY t1.request_date DESC
        ";
        return $this->sql->select($query, $params) ?: false;
    }

   
    public function get_anticipo_applications($anticipo_id) {
        try {
            $query = "
                 SELECT 
                    t1.id,
                    t1.anticipo_id,
                    t1.invoice_id,
                    t1.monto_aplicado,
                    t1.fecha_aplicacion,
                    t1.aplicado_por,
                    t1.notas,           
                    -- Información de la factura
                    t2.folio,
                    t2.invoice_number,
                    t2.amount as monto_factura,
                    t2.payment_request_id,            
                    -- Información del pago asociado a la factura
                    t3.id as pago_id,
                    t3.request_date as pago_fecha,
                    t3.status as pago_status,
                    t3.comment as pago_comentario,
                    -- Estación
                    t6.abr as estacion_nombre,
                    -- Usuario que lo aplicó
                    t4.Nombre as aplicado_por_nombre,
                    -- Proveedor
                    t7.den as proveedor_nombre,
                    -- Empresa
                    t5.den as empresa_nombre
                FROM [TG].[dbo].[anticipo_invoice_applications] t1
                LEFT JOIN [TG].[dbo].[payment_request_invoices] t2 ON t1.invoice_id = t2.id
                LEFT JOIN [TG].[dbo].[payment_requests] t3 ON t2.payment_request_id = t3.id
                LEFT JOIN TG.[dbo].[Usuario] t4 ON t1.aplicado_por = t4.Id
                LEFT JOIN [SG12].[dbo].[Empresas] t5 ON t3.emp_cod = t5.cod
                LEFT JOIN [SG12].[dbo].Gasolineras t6 ON t2.codgas = t6.cod
                LEFT JOIN [SG12].[dbo].[Proveedores] t7 ON t3.provider_cod = t7.cod
                WHERE t1.anticipo_id = ?
                ORDER BY t1.fecha_aplicacion DESC
            ";
            
            $params = [$anticipo_id];
            return $this->sql->select($query, $params) ?: [];
            
        } catch (Exception $e) {
            error_log("Error en get_anticipo_applications: " . $e->getMessage());
            return [];
        }
    }



    public function get_anticipo_summary($anticipo_id) {
        try {
            $query = "
                SELECT 
                    pr.id,
                    pr.monto_total as monto_original,
                    ISNULL(SUM(aa.monto_aplicado), 0) as total_aplicado,
                    (pr.monto_total - ISNULL(SUM(aa.monto_aplicado), 0)) as saldo_disponible,
                    COUNT(aa.id) as total_aplicaciones
                FROM [TG].[dbo].[payment_requests] pr
                LEFT JOIN [TG].[dbo].[anticipo_invoice_applications] aa ON pr.id = aa.anticipo_id
                WHERE pr.id = ?
                GROUP BY pr.id, pr.monto_total
            ";
            
            $params = [$anticipo_id];
            $result = $this->sql->select($query, $params);
            
            return $result ? $result[0] : null;
            
        } catch (Exception $e) {
            error_log("Error en get_anticipo_summary: " . $e->getMessage());
            return null;
        }
    }

    public function get_saldo_disponible($anticipo_id) {
        $summary = $this->get_anticipo_summary($anticipo_id);
        return $summary ? $summary['saldo_disponible'] : 0;
    }

    /**
     * Verificar si un anticipo tiene saldo disponible
     */
    public function anticipo_has_balance($anticipo_id, $monto_requerido = 0) {
        try {
            $summary = $this->get_anticipo_summary($anticipo_id);
            
            if (!$summary) {
                return false;
            }
            
            $saldo = $summary['saldo_disponible'];
            
            if ($monto_requerido > 0) {
                return $saldo >= $monto_requerido;
            }
            
            return $saldo > 0;
            
        } catch (Exception $e) {
            error_log("Error en anticipo_has_balance: " . $e->getMessage());
            return false;
        }
    }

    public function getPaymentAuthorizations($payment_id) {
        $query = "
            SELECT 
                pra.id,
                pra.payment_request_id,
                pra.staff_user_id,
                pra.permission_number,
                pra.authorization_date,
                u.Nombre as autorizador_nombre,
                CASE 
                    WHEN pra.permission_number = 66 THEN 'Abastos'
                    WHEN pra.permission_number = 67 THEN 'Administración y Finanzas'
                    WHEN pra.permission_number = 68 THEN 'Tesorería'
                    ELSE 'Desconocido'
                END as departamento
            FROM [TG].[dbo].[payment_request_authorizations] pra
            LEFT JOIN [TG].[dbo].[Usuario] u ON pra.staff_user_id = u.Id
            WHERE pra.payment_request_id = ?
            ORDER BY pra.permission_number ASC
        ";
        
        return $this->sql->select($query, [$payment_id]) ?: [];
    }

    /**
     * Obtener estado de autorizaciones
     */
    public function getAuthorizationStatus($payment_id) {
        $query = "
            SELECT 
                MAX(CASE WHEN permission_number = 66 THEN 1 ELSE 0 END) as abastos,
                MAX(CASE WHEN permission_number = 67 THEN 1 ELSE 0 END) as admin_finanzas,
                MAX(CASE WHEN permission_number = 68 THEN 1 ELSE 0 END) as tesoreria,
                CASE 
                    WHEN COUNT(*) >= 3 THEN 1
                    ELSE 0
                END as completed
            FROM [TG].[dbo].[payment_request_authorizations]
            WHERE payment_request_id = ?
        ";
        
        $result = $this->sql->select($query, [$payment_id]);
        
        if ($result && count($result) > 0) {
            $status = $result[0];
            
            // Determinar el siguiente nivel requerido
            if (!$status['abastos']) {
                $status['next_level'] = 66;
            } elseif (!$status['admin_finanzas']) {
                $status['next_level'] = 67;
            } elseif (!$status['tesoreria']) {
                $status['next_level'] = 68;
            } else {
                $status['next_level'] = null;
            }
            
            return $status;
        }
        
        return [
            'abastos' => 0,
            'admin_finanzas' => 0,
            'tesoreria' => 0,
            'completed' => 0,
            'next_level' => 66
        ];
    }

    /**
     * Obtener información de una autorización específica
     */
    public function getAuthorizationInfo($payment_id, $permission_number) {
        $query = "
            SELECT 
                pra.id,
                pra.authorization_date,
                pra.staff_user_id,
                u.Nombre as autorizador_nombre
            FROM [TG].[dbo].[payment_request_authorizations] pra
            LEFT JOIN [TG].[dbo].[Usuario] u ON pra.staff_user_id = u.Id
            WHERE pra.payment_request_id = ? AND pra.permission_number = ?
        ";
        
        $result = $this->sql->select($query, [$payment_id, $permission_number]);
        return $result ? $result[0] : null;
    }

    /**
     * Registrar aplicaciones de anticipo
     */
    public function register_anticipo_applications($anticipo_id, $aplicaciones, $user_id) {
        $this->sql->beginTransaction();
        
        try {
            // Validar saldo
            $saldo = $this->get_saldo_disponible($anticipo_id);
            $total_aplicar = array_sum(array_column($aplicaciones, 'monto'));
            
            if ($total_aplicar > $saldo) {
                throw new Exception('El monto a aplicar excede el saldo disponible');
            }
            
            // Fecha actual
            $fecha_aplicacion = date('Y-m-d H:i:s');
            
            // Insertar cada aplicación
            $query = "
                INSERT INTO [TG].[dbo].[anticipo_invoice_applications]
                (anticipo_id, payment_request_id, invoice_id, monto_aplicado, fecha_aplicacion, created_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ";
            
            foreach ($aplicaciones as $app) {
                $params = [
                    $anticipo_id,
                    $app['payment_request_id'] ?? null,
                    $app['invoice_id'] ?? null,
                    $app['monto'],
                    $fecha_aplicacion,
                    $user_id,
                    $fecha_aplicacion
                ];
                
                if (!$this->sql->insert($query, $params)) {
                    throw new Exception('Error al registrar aplicación');
                }
            }
            
            $this->sql->commit();
            
            return [
                'success' => true,
                'message' => 'Anticipo aplicado exitosamente'
            ];
            
        } catch (Exception $e) {
            $this->sql->rollback();
            error_log("Error en register_anticipo_applications: " . $e->getMessage());
            
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    // public function get_anticipos_para_layout(array $anticipo_ids) : array|false {
    //     if (empty($anticipo_ids)) {
    //         return false;
    //     }
        
    //     $placeholders = implode(',', array_fill(0, count($anticipo_ids), '?'));
        
    //     $query = "SELECT 
    //                 t1.id as payment_request_id,
    //                 t1.emp_cod as empresa_cod,
    //                 t1.provider_cod as proveedor_codigo,
    //                 t1.monto_total as monto_autorizado,
    //                 emp.den as empresa_nombre,
    //                 cb_propia.CuentaLocal AS cuenta_cargo_empresa,
    //                 cb_propia.TitularCuenta AS titular_cargo,
    //                 prov.den as proveedor_nombre,
    //                 cb_tercero.CuentaLocal AS clabe_beneficiario,
    //                 cb_tercero.Descripcion AS titular_beneficiario,
    //                 cb_tercero.Banco AS banco_beneficiario,
    //                 cb_tercero.Id AS cuenta_beneficiario_id,
    //                 'ANTICIPO' as tipo_pago,
    //                 'ANTICIPO #' + CAST(t1.id AS VARCHAR) as folio,
    //                 NULL as invoice_number,
    //                 t1.request_date as fecha_pago,
    //                 'Pago' as tipo_pago,
    //                 t1.comment as concepto
    //             FROM [TG].[dbo].[payment_requests] t1
    //             LEFT JOIN [SG12].[dbo].[Empresas] emp ON t1.emp_cod = emp.cod
    //             LEFT JOIN [SG12].[dbo].[Proveedores] prov ON t1.provider_cod = prov.cod
    //             LEFT JOIN [TG].[dbo].[CatalogosCuentasBancarias] cb_tercero 
    //                 ON cb_tercero.Tipo = 'Terceros'
    //                 --AND cb_tercero.Banco = 'SANTANDER'
    //                 AND cb_tercero.Divisa = 'NUEVO PESO MEXICANO'
    //                 AND cb_tercero.Activo = 1
    //                 AND (
    //                     cb_tercero.TitularCuenta LIKE '%' + RTRIM(LTRIM(SUBSTRING(prov.den, 1, CHARINDEX(' ', prov.den + ' ')))) + '%'
    //                     OR cb_tercero.Descripcion LIKE '%' + RTRIM(LTRIM(SUBSTRING(prov.den, 1, CHARINDEX(' ', prov.den + ' ')))) + '%'
    //                 )      
    //             LEFT JOIN [TG].[dbo].[CatalogosCuentasBancarias] cb_propia
    //                             ON cb_propia.emp_cod = emp.cod
    //                             AND cb_propia.Tipo = 'Propias'
    //                             AND cb_propia.Banco = 'SANTANDER'
    //                             AND cb_propia.Activo = 1
    //             WHERE t1.id IN ($placeholders)
    //                 AND t1.tipo = ?  -- Solo anticipos
    //                 AND t1.status = ?  -- Solo autorizados
    //             ORDER BY t1.emp_cod, t1.provider_cod
    //     ";
        
    //     $params = array_merge(
    //         $anticipo_ids, 
    //         [1, PaymentRequestsModel::STATUS_AUTHORIZED]  // tipo = 1 (anticipo), status = autorizado
    //     );
        
    //     return $this->sql->select($query, $params) ?: false;
    // }

    public function get_anticipos_para_layout(array $anticipo_ids) : array|false {
    if (empty($anticipo_ids)) {
        return false;
    }
    
    $placeholders = implode(',', array_fill(0, count($anticipo_ids), '?'));
    
    $query = "SELECT 
                t1.id as payment_request_id,
                t1.emp_cod as empresa_cod,
                t1.provider_cod as proveedor_codigo,
                t1.monto_total as monto_autorizado,
                emp.den as empresa_nombre,
                
                -- ✅ SANTANDER
                cb_propia_sant.CuentaLocal AS cuenta_cargo_empresa,
                cb_propia_sant.TitularCuenta AS titular_cargo,
                cb_tercero_sant.CuentaLocal AS clabe_beneficiario,
                cb_tercero_sant.Descripcion AS titular_beneficiario,
                cb_tercero_sant.Banco AS banco_beneficiario,
                cb_tercero_sant.Id AS cuenta_beneficiario_id,
                
                -- ✅ BANORTE
                cb_propia_banorte.CuentaLocal AS cuenta_cargo_banorte,
                cb_propia_banorte.TitularCuenta AS titular_cargo_banorte,
                
                prov.den as proveedor_nombre,
                'ANTICIPO' as tipo_pago,
                'ANTICIPO #' + CAST(t1.id AS VARCHAR) as folio,
                NULL as invoice_number,
                t1.request_date as fecha_pago,
                t1.comment as concepto
                
            FROM [TG].[dbo].[payment_requests] t1
            LEFT JOIN [SG12].[dbo].[Empresas] emp ON t1.emp_cod = emp.cod
            LEFT JOIN [SG12].[dbo].[Proveedores] prov ON t1.provider_cod = prov.cod
            
            -- TERCEROS SANTANDER
            LEFT JOIN [TG].[dbo].[CatalogosCuentasBancarias] cb_tercero_sant
                ON cb_tercero_sant.Tipo = 'Terceros'
                AND cb_tercero_sant.Divisa = 'NUEVO PESO MEXICANO'
                AND cb_tercero_sant.Activo = 1
                AND (
                    cb_tercero_sant.TitularCuenta LIKE '%' + RTRIM(LTRIM(SUBSTRING(prov.den, 1, CHARINDEX(' ', prov.den + ' ')))) + '%'
                    OR cb_tercero_sant.Descripcion LIKE '%' + RTRIM(LTRIM(SUBSTRING(prov.den, 1, CHARINDEX(' ', prov.den + ' ')))) + '%'
                )

            -- PROPIAS SANTANDER
            LEFT JOIN [TG].[dbo].[CatalogosCuentasBancarias] cb_propia_sant
                ON cb_propia_sant.emp_cod = emp.cod
                AND cb_propia_sant.Tipo = 'Propias'
                AND cb_propia_sant.Banco = 'SANTANDER'
                AND cb_propia_sant.Activo = 1
            
            -- PROPIAS BANORTE
            LEFT JOIN [TG].[dbo].[CatalogosCuentasBancarias] cb_propia_banorte
                ON cb_propia_banorte.emp_cod = emp.cod
                AND cb_propia_banorte.Tipo = 'Propias'
                AND cb_propia_banorte.Banco = 'BANORTE'
                AND cb_propia_banorte.Activo = 1
                
            WHERE t1.id IN ($placeholders)
                AND t1.tipo = ?  -- Solo anticipos
                AND t1.status = ?  -- Solo autorizados
            ORDER BY t1.emp_cod, t1.provider_cod
    ";
    
    $params = array_merge(
        $anticipo_ids, 
        [1, PaymentRequestsModel::STATUS_AUTHORIZED]
    );

    return $this->sql->select($query, $params) ?: false;
}
    


}
