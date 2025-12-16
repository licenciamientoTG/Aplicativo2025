<?php
class PaymentRequestAuthorizationsModel extends Model
{
    public $id;
    public $payment_request_id;
    public $staff_user_id;
    public $permission_number;
    public $authorization_date;


    const PERM_ABASTOS = 66;
    const PERM_ADMIN_FINANZAS = 67;
    const PERM_TESORERIA = 68;

    // Orden secuencial de autorizaciones
    const AUTHORIZATION_SEQUENCE = [
        1 => self::PERM_ABASTOS,
        2 => self::PERM_ADMIN_FINANZAS,
        3 => self::PERM_TESORERIA
    ];

    /**
     * Obtiene las autorizaciones de una solicitud de pago
     */
    public function get_by_payment_request($payment_request_id) : array|false {
        $query = '
            SELECT 
                pra.*,
                u.Nombre as autorizador_nombre,
                CASE 
                    WHEN pra.permission_number = ? THEN \'Abastos\'
                    WHEN pra.permission_number = ? THEN \'Administración y Finanzas\'
                    WHEN pra.permission_number = ? THEN \'Tesorería\'
                    ELSE \'Desconocido\'
                END as departamento
            FROM [TG].[dbo].[payment_request_authorizations] pra
            LEFT JOIN [TG].[dbo].[Usuario] u ON pra.staff_user_id = u.Id
            WHERE pra.payment_request_id = ?
            ORDER BY pra.authorization_date ASC
        ';
        return ($this->sql->select($query, [
            self::PERM_ABASTOS, 
            self::PERM_ADMIN_FINANZAS, 
            self::PERM_TESORERIA,
            $payment_request_id
        ])) ?: false;
    }

    /**
     * Inserta una nueva autorización
     */
    public function insert_authorization($payment_request_id, $staff_user_id, $permission_number) : int|false {
        $query = '
            INSERT INTO [TG].[dbo].[payment_request_authorizations]
            (payment_request_id, staff_user_id, permission_number, authorization_date)
            VALUES (?, ?, ?, GETDATE())
        ';
        return $this->sql->insert($query, [$payment_request_id, $staff_user_id, $permission_number]);
    }

    public function is_authorized_by_permission($payment_request_id, $permission_number) : bool {
        $query = '
            SELECT COUNT(*) as count
            FROM [TG].[dbo].[payment_request_authorizations]
            WHERE payment_request_id = ? AND permission_number = ?
        ';
        $result = $this->sql->select($query, [$payment_request_id, $permission_number]);
        return ($result && $result[0]['count'] > 0);
    }

    /**
     * Verifica si una solicitud ya está autorizada por un usuario
     */
    public function is_authorized_by_user($payment_request_id, $staff_user_id) : bool {
        $query = '
            SELECT COUNT(*) as count
            FROM [TG].[dbo].[payment_request_authorizations]
            WHERE payment_request_id = ? AND staff_user_id = ?
        ';
        $result = $this->sql->select($query, [$payment_request_id, $staff_user_id]);
        return ($result && $result[0]['count'] > 0);
    }

    /**
     * Cuenta cuántas autorizaciones tiene una solicitud
     */
    public function count_authorizations($payment_request_id) : int {
        $query = '
            SELECT COUNT(*) as count
            FROM [TG].[dbo].[payment_request_authorizations]
            WHERE payment_request_id = ?
        ';
        $result = $this->sql->select($query, [$payment_request_id]);
        return $result ? (int)$result[0]['count'] : 0;
    }

    /**
     * Elimina todas las autorizaciones de una solicitud
     */
    public function delete_by_payment_request($payment_request_id) : bool {
        $query = 'DELETE FROM [TG].[dbo].[payment_request_authorizations] WHERE payment_request_id = ?';
        return $this->sql->delete($query, [$payment_request_id]);
    }

     public function can_user_authorize($payment_request_id, $user_id, $user_permission) : array {
        // if ($this->is_authorized_by_user($payment_request_id, $user_id)) {
        //     return [
        //         'can_authorize' => false,
        //         'reason' => 'Ya has autorizado este pago'
        //     ];
        // }

        // Obtener el próximo nivel requerido
        $next_level = $this->get_next_authorization_level($payment_request_id);
        
        if ($next_level === null) {
            return [
                'can_authorize' => false,
                'reason' => 'Este pago ya tiene todas las autorizaciones necesarias'
            ];
        }

        // // Verificar si el usuario tiene el permiso correcto para el nivel actual
        // if ($user_permission !== $next_level) {
        //     $department_name = $this->get_department_name($next_level);
        //     return [
        //         'can_authorize' => false,
        //         'reason' => "Este pago requiere autorización de: $department_name"
        //     ];
        // }

        return [
            'can_authorize' => true,
            'level' => $next_level
        ];
    }
    public function get_department_name($permission_number) : string {
        switch ($permission_number) {
            case self::PERM_ABASTOS:
                return 'Abastos';
            case self::PERM_ADMIN_FINANZAS:
                return 'Administración y Finanzas';
            case self::PERM_TESORERIA:
                return 'Tesorería';
            default:
                return 'Desconocido';
        }
    }

    public function get_authorization_status($payment_request_id) : array {
        $status = [
            'abastos' => $this->is_authorized_by_permission($payment_request_id, self::PERM_ABASTOS),
            'admin_finanzas' => $this->is_authorized_by_permission($payment_request_id, self::PERM_ADMIN_FINANZAS),
            'tesoreria' => $this->is_authorized_by_permission($payment_request_id, self::PERM_TESORERIA),
            'next_level' => $this->get_next_authorization_level($payment_request_id),
            'completed' => $this->get_next_authorization_level($payment_request_id) === null
        ];

        return $status;
    }
    public function get_next_authorization_level($payment_request_id) : ?int {
        foreach (self::AUTHORIZATION_SEQUENCE as $level => $permission) {
            if (!$this->is_authorized_by_permission($payment_request_id, $permission)) {
                return $permission;
            }
        }
        return null; // Todas las autorizaciones completadas
    }
    

}