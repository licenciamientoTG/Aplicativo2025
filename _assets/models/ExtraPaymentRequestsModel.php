<?php
/**
 * Solicitudes de pagos extraordinarios (TG.dbo.extra_payment_*).
 *
 * Todas las transiciones de estatus usan un UPDATE con guarda
 * "WHERE id = ? AND status = <esperado>" vía updateSafe() (devuelve filas
 * afectadas): solo se avanza si se afectó exactamente 1 fila, de modo que un
 * doble clic o dos pestañas no pueden avanzar dos veces ni pagar dos veces.
 */
class ExtraPaymentRequestsModel extends Model
{
    /** Encabezados para listados (solicitante, motivo, total, estatus...). */
    private function list_base_query() : string {
        return '
            SELECT
                r.id, r.fecha, r.tipo, r.total, r.status, r.user_id, r.reason_id,
                r.departamento_id, r.created_at, r.updated_at,
                u.Nombre AS solicitante,
                rs.nombre AS reason_nombre,
                d.Nombre AS departamento
            FROM [TG].[dbo].[extra_payment_requests] r
            LEFT JOIN [TG].[dbo].[Usuario] u ON u.Id = r.user_id
            LEFT JOIN [TG].[dbo].[Departamentos] d ON d.Id = r.departamento_id
            LEFT JOIN [TG].[dbo].[extra_payment_reasons] rs ON rs.id = r.reason_id
        ';
    }

    /**
     * Crea la solicitud (status 1) con renglones y auditoría CREATE.
     * @return int|false id de la solicitud
     */
    public function create(array $header, array $lines, int $userId) : int|false {
        if (count($lines) === 0) {
            return false;
        }
        $total = ExtraPaymentWorkflow::total($lines);

        $this->sql->beginTransaction();
        try {
            $reason = $this->sql->select(
                'SELECT id FROM [TG].[dbo].[extra_payment_reasons] WHERE id = ? AND activo = 1',
                [(int)($header['reason_id'] ?? 0)]
            );
            if (!$reason) {
                $this->sql->rollBack();
                return false;
            }

            $id = $this->sql->insert('
                INSERT INTO [TG].[dbo].[extra_payment_requests]
                    (fecha, user_id, departamento_id, tipo, reason_id, motivo_otros, total, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ', [
                $header['fecha'] ?? date('Y-m-d'),
                $userId,
                isset($header['departamento_id']) && $header['departamento_id'] !== '' ? (int)$header['departamento_id'] : null,
                (string)($header['tipo'] ?? ''),
                (int)$header['reason_id'],
                $this->nullable($header['motivo_otros'] ?? null),
                $total,
                ExtraPaymentWorkflow::ST_PEND_CXP,
            ]);
            $id = (int)$id;
            if ($id <= 0) {
                $this->sql->rollBack();
                return false;
            }

            $this->insert_lines($id, $lines);
            $this->audit($id, $userId, 'CREATE', 'Total ' . number_format($total, 2, '.', ''));

            $this->sql->commit();
            return $id;
        } catch (Throwable $e) {
            $this->sql->rollBack();
            error_log('ExtraPaymentRequestsModel::create: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Reenvío tras rechazo: solo si status = 9. Reemplaza renglones, vuelve a
     * status 1 e inactiva las aprobaciones previas.
     */
    public function update(int $id, array $header, array $lines, int $userId) : bool {
        if (count($lines) === 0) {
            return false;
        }
        $total = ExtraPaymentWorkflow::total($lines);

        $this->sql->beginTransaction();
        try {
            $reason = $this->sql->select(
                'SELECT id FROM [TG].[dbo].[extra_payment_reasons] WHERE id = ? AND activo = 1',
                [(int)($header['reason_id'] ?? 0)]
            );
            if (!$reason) {
                $this->sql->rollBack();
                return false;
            }

            $affected = $this->sql->updateSafe('
                UPDATE [TG].[dbo].[extra_payment_requests]
                SET status = ?, tipo = ?, reason_id = ?, motivo_otros = ?, total = ?,
                    departamento_id = ?, updated_at = GETDATE()
                WHERE id = ? AND status = ?
            ', [
                ExtraPaymentWorkflow::ST_PEND_CXP,
                (string)($header['tipo'] ?? ''),
                (int)$header['reason_id'],
                $this->nullable($header['motivo_otros'] ?? null),
                $total,
                isset($header['departamento_id']) && $header['departamento_id'] !== '' ? (int)$header['departamento_id'] : null,
                $id,
                ExtraPaymentWorkflow::ST_RECHAZADA,
            ]);
            if ($affected !== 1) {
                $this->sql->rollBack();
                return false;
            }

            // delete() devuelve true aunque borre 0 filas; false solo ante fallo.
            if ($this->sql->delete('DELETE FROM [TG].[dbo].[extra_payment_lines] WHERE request_id = ?', [$id]) === false) {
                $this->sql->rollBack();
                return false;
            }
            $this->insert_lines($id, $lines);
            // updateSafe() devuelve false ante error; 0 filas es válido (sin aprobaciones).
            if ($this->sql->updateSafe(
                'UPDATE [TG].[dbo].[extra_payment_approvals] SET is_active = 0 WHERE request_id = ? AND is_active = 1',
                [$id]
            ) === false) {
                $this->sql->rollBack();
                return false;
            }
            $this->audit($id, $userId, 'RESUBMIT', 'Total ' . number_format($total, 2, '.', ''));

            $this->sql->commit();
            return true;
        } catch (Throwable $e) {
            $this->sql->rollBack();
            error_log('ExtraPaymentRequestsModel::update: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Solicitud completa: encabezado + lines + approvals (activas) + payment + audit.
     */
    public function get(int $id) : ?array {
        $rows = $this->sql->select('
            SELECT
                r.*,
                rs.nombre AS reason_nombre,
                rs.requiere_texto AS reason_requiere_texto,
                u.Nombre AS solicitante,
                d.Nombre AS departamento
            FROM [TG].[dbo].[extra_payment_requests] r
            LEFT JOIN [TG].[dbo].[extra_payment_reasons] rs ON rs.id = r.reason_id
            LEFT JOIN [TG].[dbo].[Usuario] u ON u.Id = r.user_id
            LEFT JOIN [TG].[dbo].[Departamentos] d ON d.Id = r.departamento_id
            WHERE r.id = ?
        ', [$id]);
        if (!$rows) {
            return null;
        }
        $request = $rows[0];

        $request['lines'] = $this->sql->select('
            SELECT l.*, e.den AS empresa_nombre
            FROM [TG].[dbo].[extra_payment_lines] l
            LEFT JOIN [SG12].[dbo].[Empresas] e ON e.cod = l.empresa_cod
            WHERE l.request_id = ?
            ORDER BY l.id ASC
        ', [$id]) ?: [];

        $request['approvals'] = $this->sql->select('
            SELECT a.*, u.Nombre AS aprobador
            FROM [TG].[dbo].[extra_payment_approvals] a
            LEFT JOIN [TG].[dbo].[Usuario] u ON u.Id = a.user_id
            WHERE a.request_id = ? AND a.is_active = 1
            ORDER BY a.id ASC
        ', [$id]) ?: [];

        $payment = $this->sql->select('
            SELECT p.*, u.Nombre AS pagador
            FROM [TG].[dbo].[extra_payment_payments] p
            LEFT JOIN [TG].[dbo].[Usuario] u ON u.Id = p.user_id
            WHERE p.request_id = ?
        ', [$id]);
        $request['payment'] = $payment ? $payment[0] : null;

        $request['audit'] = $this->sql->select('
            SELECT l.*, u.Nombre AS usuario
            FROM [TG].[dbo].[extra_payment_audit_log] l
            LEFT JOIN [TG].[dbo].[Usuario] u ON u.Id = l.user_id
            WHERE l.request_id = ?
            ORDER BY l.id ASC
        ', [$id]) ?: [];

        return $request;
    }

    public function list_for_user(int $userId) : array {
        return $this->sql->select(
            $this->list_base_query() . ' WHERE r.user_id = ? ORDER BY r.id DESC',
            [$userId]
        ) ?: [];
    }

    /**
     * Arma el WHERE parametrizado de los filtros opcionales (status, empresa_cod,
     * reason_id, desde, hasta). Compartido por list_all y list_lines_for_export.
     * @return array{0:string,1:array} [cláusula WHERE (con espacio inicial o ''), parámetros]
     */
    private function build_filters(array $filters) : array {
        $where = [];
        $params = [];

        if (isset($filters['status']) && $filters['status'] !== '') {
            $where[] = 'r.status = ?';
            $params[] = (int)$filters['status'];
        }
        if (isset($filters['empresa_cod']) && $filters['empresa_cod'] !== '') {
            $where[] = 'EXISTS (SELECT 1 FROM [TG].[dbo].[extra_payment_lines] l WHERE l.request_id = r.id AND l.empresa_cod = ?)';
            $params[] = (int)$filters['empresa_cod'];
        }
        if (isset($filters['reason_id']) && $filters['reason_id'] !== '') {
            $where[] = 'r.reason_id = ?';
            $params[] = (int)$filters['reason_id'];
        }
        if (!empty($filters['desde'])) {
            $where[] = 'r.fecha >= ?';
            $params[] = (string)$filters['desde'];
        }
        if (!empty($filters['hasta'])) {
            $where[] = 'r.fecha <= ?';
            $params[] = (string)$filters['hasta'];
        }
        return [$where ? ' WHERE ' . implode(' AND ', $where) : '', $params];
    }

    /**
     * Filtros opcionales: status, empresa_cod, reason_id, desde, hasta.
     */
    public function list_all(array $filters = []) : array {
        [$where, $params] = $this->build_filters($filters);
        return $this->sql->select(
            $this->list_base_query() . $where . ' ORDER BY r.id DESC',
            $params
        ) ?: [];
    }

    /**
     * Una fila por renglón para el export, con los mismos filtros que list_all.
     * Con filtro de empresa solo salen los renglones de esa empresa.
     */
    public function list_lines_for_export(array $filters = []) : array {
        [$where, $params] = $this->build_filters($filters);
        if (isset($filters['empresa_cod']) && $filters['empresa_cod'] !== '') {
            $where .= ' AND l.empresa_cod = ?';
            $params[] = (int)$filters['empresa_cod'];
        }
        return $this->sql->select('
            SELECT
                r.id AS folio, r.fecha, r.status, r.tipo,
                rs.nombre AS reason_nombre, r.motivo_otros,
                e.den AS empresa_nombre,
                l.centro_costos, l.proveedor, l.factura, l.fecha_factura,
                l.descripcion, l.monto,
                p.fecha_pago
            FROM [TG].[dbo].[extra_payment_requests] r
            JOIN [TG].[dbo].[extra_payment_lines] l ON l.request_id = r.id
            LEFT JOIN [TG].[dbo].[extra_payment_reasons] rs ON rs.id = r.reason_id
            LEFT JOIN [SG12].[dbo].[Empresas] e ON e.cod = l.empresa_cod
            LEFT JOIN [TG].[dbo].[extra_payment_payments] p ON p.request_id = r.id
        ' . $where . ' ORDER BY r.id DESC, l.id ASC', $params) ?: [];
    }

    public function list_by_status(int $status) : array {
        return $this->sql->select(
            $this->list_base_query() . ' WHERE r.status = ? ORDER BY r.id DESC',
            [$status]
        ) ?: [];
    }

    /**
     * Aprueba el paso actual (1 CxP -> 2, 2 Admón -> 3). Falso si otro usuario
     * ya lo avanzó o el estatus no es aprobable.
     */
    public function approve(int $id, int $userId, ?string $comentario, ?int $expectedStatus = null) : bool {
        $this->sql->beginTransaction();
        try {
            $current = $this->current_status($id);
            $next = $current === null ? null : ExtraPaymentWorkflow::next_status_on_approve($current);
            // $expectedStatus: el estatus sobre el que el controlador validó el permiso;
            // si cambió entre la lectura y la transacción, no se aprueba otro paso.
            if ($next === null || ($expectedStatus !== null && $current !== $expectedStatus)) {
                $this->sql->rollBack();
                return false;
            }

            if (!$this->guarded_status_change($id, $current, $next)) {
                $this->sql->rollBack();
                return false;
            }

            $this->insert_approval($id, $current, $userId, 'aprobada', $comentario);
            $this->audit($id, $userId, 'APPROVE', 'Paso ' . $current . ' -> ' . $next);

            $this->sql->commit();
            return true;
        } catch (Throwable $e) {
            $this->sql->rollBack();
            error_log('ExtraPaymentRequestsModel::approve: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Rechaza (status 9) desde 1 o 2; el comentario es obligatorio.
     */
    public function reject(int $id, int $userId, string $comentario, ?int $expectedStatus = null) : bool {
        if (trim($comentario) === '') {
            return false;
        }

        $this->sql->beginTransaction();
        try {
            $current = $this->current_status($id);
            if (($current !== ExtraPaymentWorkflow::ST_PEND_CXP && $current !== ExtraPaymentWorkflow::ST_PEND_ADMON)
                || ($expectedStatus !== null && $current !== $expectedStatus)) {
                $this->sql->rollBack();
                return false;
            }

            if (!$this->guarded_status_change($id, $current, ExtraPaymentWorkflow::ST_RECHAZADA)) {
                $this->sql->rollBack();
                return false;
            }

            $this->insert_approval($id, $current, $userId, 'rechazada', $comentario);
            $this->audit($id, $userId, 'REJECT', $comentario);

            $this->sql->commit();
            return true;
        } catch (Throwable $e) {
            $this->sql->rollBack();
            error_log('ExtraPaymentRequestsModel::reject: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Cancela desde 1, 2 o 9.
     */
    public function cancel(int $id, int $userId) : bool {
        $this->sql->beginTransaction();
        try {
            $current = $this->current_status($id);
            if ($current === null || !ExtraPaymentWorkflow::can_cancel($current)) {
                $this->sql->rollBack();
                return false;
            }

            if (!$this->guarded_status_change($id, $current, ExtraPaymentWorkflow::ST_CANCELADA)) {
                $this->sql->rollBack();
                return false;
            }

            $this->audit($id, $userId, 'CANCEL', 'Cancelada desde estatus ' . $current);

            $this->sql->commit();
            return true;
        } catch (Throwable $e) {
            $this->sql->rollBack();
            error_log('ExtraPaymentRequestsModel::cancel: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Pago de Tesorería: exige status 3, pasa a 4 y registra el pago por el total.
     */
    public function register_payment(int $id, int $userId, int $bankAccountId, string $fecha, string $referencia) : bool {
        $this->sql->beginTransaction();
        try {
            $rows = $this->sql->select(
                'SELECT status, total FROM [TG].[dbo].[extra_payment_requests] WHERE id = ?',
                [$id]
            );
            if (!$rows || (int)$rows[0]['status'] !== ExtraPaymentWorkflow::ST_AUTORIZADA) {
                $this->sql->rollBack();
                return false;
            }

            if (!$this->guarded_status_change($id, ExtraPaymentWorkflow::ST_AUTORIZADA, ExtraPaymentWorkflow::ST_PAGADA)) {
                $this->sql->rollBack();
                return false;
            }

            $this->sql->insert('
                INSERT INTO [TG].[dbo].[extra_payment_payments]
                    (request_id, bank_account_id, fecha_pago, referencia, monto, user_id)
                VALUES (?, ?, ?, ?, ?, ?)
            ', [$id, $bankAccountId, $fecha, $referencia, (float)$rows[0]['total'], $userId]);

            $this->audit($id, $userId, 'PAY', 'Ref. ' . $referencia);

            $this->sql->commit();
            return true;
        } catch (Throwable $e) {
            $this->sql->rollBack();
            error_log('ExtraPaymentRequestsModel::register_payment: ' . $e->getMessage());
            return false;
        }
    }

    public function audit(int $id, int $userId, string $action, ?string $detail) : void {
        $this->sql->insert('
            INSERT INTO [TG].[dbo].[extra_payment_audit_log] (request_id, user_id, action, detail)
            VALUES (?, ?, ?, ?)
        ', [$id, $userId, $action, $detail === null ? null : mb_substr($detail, 0, 500)]);
    }

    // ---------------------------------------------------------------
    // Auxiliares privados
    // ---------------------------------------------------------------

    private function current_status(int $id) : ?int {
        $rows = $this->sql->select(
            'SELECT status FROM [TG].[dbo].[extra_payment_requests] WHERE id = ?',
            [$id]
        );
        return $rows ? (int)$rows[0]['status'] : null;
    }

    /**
     * UPDATE ... SET status = nuevo WHERE id = ? AND status = esperado.
     * Verdadero solo si se afectó exactamente una fila.
     */
    private function guarded_status_change(int $id, int $expected, int $new) : bool {
        $affected = $this->sql->updateSafe('
            UPDATE [TG].[dbo].[extra_payment_requests]
            SET status = ?, updated_at = GETDATE()
            WHERE id = ? AND status = ?
        ', [$new, $id, $expected]);
        return $affected === 1;
    }

    private function insert_approval(int $id, int $status, int $userId, string $decision, ?string $comentario) : void {
        $this->sql->insert('
            INSERT INTO [TG].[dbo].[extra_payment_approvals]
                (request_id, step, permission_number, user_id, decision, comentario)
            VALUES (?, ?, ?, ?, ?, ?)
        ', [
            $id,
            $status === ExtraPaymentWorkflow::ST_PEND_CXP ? 1 : 2,
            ExtraPaymentWorkflow::required_permission($status),
            $userId,
            $decision,
            $this->nullable($comentario),
        ]);
    }

    private function insert_lines(int $requestId, array $lines) : void {
        foreach ($lines as $line) {
            $this->sql->insert('
                INSERT INTO [TG].[dbo].[extra_payment_lines]
                    (request_id, empresa_cod, centro_costos, proveedor, factura, fecha_factura,
                     orden_compra, descripcion, monto, fecha_pago_requerida, comentario_urgencia)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ', [
                $requestId,
                (int)$line['empresa_cod'],
                (string)($line['centro_costos'] ?? ''),
                (string)$line['proveedor'],
                $this->nullable($line['factura'] ?? null),
                $this->nullable($line['fecha_factura'] ?? null),
                $this->nullable($line['orden_compra'] ?? null),
                (string)$line['descripcion'],
                round((float)$line['monto'], 2),
                (string)$line['fecha_pago_requerida'],
                $this->nullable($line['comentario_urgencia'] ?? null),
            ]);
        }
    }

    /** Cadena vacía o solo espacios -> NULL. */
    private function nullable($value) : ?string {
        if ($value === null) {
            return null;
        }
        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }
}
