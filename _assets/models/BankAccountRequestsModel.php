<?php
/**
 * Solicitudes de alta de cuenta bancaria de proveedores.
 * Cualquier usuario solicita; Tesorería (109) aprueba y la cuenta se inserta en
 * TG.dbo.CatalogosCuentasBancarias (Tipo = 'Terceros'). La carátula del estado
 * de cuenta se guarda en la misma fila; se sirve solo por BankAccountRequests::caratula.
 * Schema: docs/sql/alta_cuenta_bancaria_schema.sql
 */
class BankAccountRequestsModel extends Model
{
    const ST_PENDIENTE = 1;
    const ST_APROBADA  = 2;
    const ST_RECHAZADA = 3;
    const ST_BORRADOR  = 4; // guardada sin carátula; solo la ve su dueño hasta que la adjunta

    const UPLOAD_BASE = '_assets/uploads/alta_cuenta_bancaria/';
    const MAX_SIZE    = 10 * 1024 * 1024; // 10 MB
    const ALLOWED_EXT = ['pdf', 'jpg', 'jpeg', 'png'];

    /** Moneda de la solicitud => Divisa del catálogo de cuentas. */
    const DIVISAS = ['PESOS' => 'NUEVO PESO MEXICANO', 'DOLARES' => 'DOLAR AMERICANO'];
    const DEPARTAMENTOS = ['INGRESOS', 'NOMINAS', 'COMPRAS', 'ADMIN', 'PROYECTOS', 'CONTABILIDAD'];

    private const SELECT = 'SELECT r.*, e.den AS empresa, u.Nombre AS solicitante, d.Nombre AS decisor
        FROM [TG].[dbo].[bank_account_requests] r
        LEFT JOIN [SG12].[dbo].[Empresas] e ON e.cod = r.empresa_cod
        LEFT JOIN [TG].[dbo].[Usuario] u ON u.Id = r.user_id
        LEFT JOIN [TG].[dbo].[Usuario] d ON d.Id = r.decided_by';

    public function banks(): array
    {
        return $this->sql->select(
            'SELECT id, nombre, no_inst, clave_transfer FROM [TG].[dbo].[bank_account_banks] WHERE activo = 1 ORDER BY nombre;',
            []
        ) ?: [];
    }

    public function bank_by_name(string $nombre): ?array
    {
        $r = $this->sql->select(
            'SELECT TOP 1 id, nombre, no_inst, clave_transfer FROM [TG].[dbo].[bank_account_banks] WHERE activo = 1 AND nombre = ?;',
            [$nombre]
        );
        return $r ? $r[0] : null;
    }

    public function get(int $id): ?array
    {
        $r = $this->sql->select(self::SELECT . ' WHERE r.id = ?;', [$id]);
        return $r ? $r[0] : null;
    }

    /** Todas (con $ver_todas) o solo las del usuario; el borrador es siempre privado de su dueño. */
    public function list_visible(int $userId, bool $verTodas): array
    {
        $where  = $verTodas ? ' WHERE (r.status <> ' . self::ST_BORRADOR . ' OR r.user_id = ?)' : ' WHERE r.user_id = ?';
        $params = [$userId];
        return $this->sql->select(self::SELECT . $where . ' ORDER BY r.id DESC;', $params) ?: [];
    }

    /**
     * Avisos de duplicado para un número de cuenta: filas del catálogo (activas o
     * no) y otras solicitudes pendientes. No bloquea; solo informa.
     */
    public function duplicates(string $cuenta, int $exceptRequestId = 0): array
    {
        $cat = $this->sql->select(
            'SELECT Id, CuentaLocal, Descripcion, Banco, Activo FROM [TG].[dbo].[CatalogosCuentasBancarias] WHERE CuentaLocal = ?;',
            [$cuenta]
        ) ?: [];
        $sol = $this->sql->select(
            'SELECT id, proveedor, banco FROM [TG].[dbo].[bank_account_requests] WHERE num_cuenta = ? AND status = ? AND id <> ?;',
            [$cuenta, self::ST_PENDIENTE, $exceptRequestId]
        ) ?: [];
        return ['catalogo' => $cat, 'solicitudes' => $sol];
    }

    /** Inserta la solicitud (sin archivo todavía). Devuelve el id o 0. */
    public function create(array $d, int $userId, int $status = self::ST_PENDIENTE): int
    {
        $id = $this->sql->insert(
            'INSERT INTO [TG].[dbo].[bank_account_requests]
                (user_id, empresa_cod, banco, clave_banco, moneda, tipo_cuenta, num_cuenta, proveedor,
                 plaza_banxico, sucursal, tipo_cuenta_cod, benef_app_paterno, benef_app_materno, benef_log,
                 benef_direccion, benef_ciudad, departamento, fecha_solicitud, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CAST(GETDATE() AS DATE), ?);',
            [
                $userId, $d['empresa_cod'], $d['banco'], $d['clave_banco'], $d['moneda'], $d['tipo_cuenta'],
                $d['num_cuenta'], $d['proveedor'], $d['plaza_banxico'], $d['sucursal'], $d['tipo_cuenta_cod'],
                $d['benef_app_paterno'] ?: null, $d['benef_app_materno'] ?: null, $d['benef_log'],
                $d['benef_direccion'] ?: null, $d['benef_ciudad'], $d['departamento'], $status,
            ]
        );
        return (int) $id;
    }


    /** Valida y guarda la carátula. Devuelve null si todo bien o el mensaje de error. */
    public function save_caratula(int $id, array $file): ?string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return 'Error al recibir la carátula del estado de cuenta';
        }
        if ($file['size'] > self::MAX_SIZE) {
            return 'La carátula excede el tamaño máximo de 10 MB';
        }
        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXT, true)) {
            return 'Tipo de archivo no permitido. Usa: PDF, JPG, PNG';
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            return 'Error al recibir la carátula del estado de cuenta';
        }
        $err = ExtraPaymentDocumentsModel::validate_content($file['tmp_name'], $ext);
        if ($err !== null) {
            return $err;
        }

        $subdir  = self::UPLOAD_BASE . date('Y') . '/' . date('m') . '/';
        $fullDir = __DIR__ . '/../../' . $subdir;
        if (!is_dir($fullDir)) {
            mkdir($fullDir, 0755, true);
        }
        $stored = $subdir . $id . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $fullDir . $id . '.' . $ext)) {
            return 'Error al guardar la carátula en disco';
        }
        $nombre = mb_substr(basename(str_replace('\\', '/', (string) $file['name'])), 0, 255);
        $ok = $this->sql->update(
            'UPDATE [TG].[dbo].[bank_account_requests]
             SET file_path = ?, file_extension = ?, original_filename = ?, file_size = ? WHERE id = ?;',
            [$stored, $ext, $nombre, (int) $file['size'], $id]
        );
        return $ok ? null : 'Error al registrar la carátula en BD';
    }

    /** Deja una solicitud recién creada como borrador (la carátula no se pudo guardar). */
    public function to_borrador(int $id): void
    {
        $this->sql->updateSafe('UPDATE [TG].[dbo].[bank_account_requests] SET status = ? WHERE id = ? AND status = ?;',
            [self::ST_BORRADOR, $id, self::ST_PENDIENTE]);
    }

    /** Pasa un borrador (ya con carátula) a pendiente de Tesorería. false si ya no era borrador. */
    public function submit(int $id): bool
    {
        return (bool) $this->sql->updateSafe(
            'UPDATE [TG].[dbo].[bank_account_requests] SET status = ? WHERE id = ? AND status = ?;',
            [self::ST_PENDIENTE, $id, self::ST_BORRADOR]
        );
    }

    /**
     * Rechaza una solicitud pendiente. false si ya no estaba pendiente.
     */
    public function reject(int $id, int $userId, string $comentario): bool
    {
        return (bool) $this->sql->updateSafe(
            'UPDATE [TG].[dbo].[bank_account_requests]
             SET status = ?, decided_by = ?, decided_at = GETDATE(), comentario = ?
             WHERE id = ? AND status = ?;',
            [self::ST_RECHAZADA, $userId, $comentario, $id, self::ST_PENDIENTE]
        );
    }

    /**
     * Aprueba: inserta la cuenta en CatalogosCuentasBancarias como 'Terceros' o, si
     * la CLABE ya existe, la liga a la fila existente (reactivándola si estaba
     * inactiva; sus datos, incluida la clave de beneficiario, no se tocan). Todo en una
     * transacción; el UPDATE de estatus es condicional para que dos aprobaciones
     * simultáneas no dupliquen la cuenta.
     * Devuelve ['success', 'message', 'catalogo_id'?, 'ligada'?].
     */
    public function approve(array $req, int $userId, ?string $comentario): array
    {
        $this->sql->beginTransaction();
        try {
            $claimed = $this->sql->updateSafe(
                'UPDATE [TG].[dbo].[bank_account_requests]
                 SET status = ?, decided_by = ?, decided_at = GETDATE(), fecha_alta = CAST(GETDATE() AS DATE),
                     comentario = ?
                 WHERE id = ? AND status = ?;',
                [self::ST_APROBADA, $userId, $comentario, (int) $req['id'], self::ST_PENDIENTE]
            );
            if (!$claimed) {
                $this->sql->rollBack();
                return ['success' => false, 'message' => 'La solicitud ya cambió de estado, recargue la página.'];
            }

            $existente = $this->sql->select(
                'SELECT TOP 1 Id, Activo FROM [TG].[dbo].[CatalogosCuentasBancarias] WHERE CuentaLocal = ? ORDER BY Activo DESC, Id ASC;',
                [$req['num_cuenta']]
            );
            $ligada = false;
            if ($existente) {
                $catId  = (int) $existente[0]['Id'];
                $ligada = true;
                $this->sql->update(
                    'UPDATE [TG].[dbo].[CatalogosCuentasBancarias]
                     SET Activo = 1,
                         FechaModificacion = GETDATE(), UsuarioModificacion = ?
                     WHERE Id = ?;',
                    [$userId, $catId]
                );
            } else {
                $catId = (int) $this->sql->insert(
                    'INSERT INTO [TG].[dbo].[CatalogosCuentasBancarias]
                        (FechaAlta, CuentaLocal, Descripcion, TipoCuenta, Banco, Divisa,
                         TitularCuenta, Tipo, Activo, FechaRegistro, UsuarioRegistro)
                     VALUES (CAST(GETDATE() AS DATE), ?, ?, \'Otros Bancos Nacionales\', ?, ?, ?, \'Terceros\', 1, GETDATE(), ?);',
                    [
                        $req['num_cuenta'], $req['proveedor'], $req['banco'],
                        self::DIVISAS[$req['moneda']] ?? self::DIVISAS['PESOS'],
                        $req['proveedor'], (string) $userId,
                    ]
                );
                if ($catId <= 0) {
                    $this->sql->rollBack();
                    return ['success' => false, 'message' => 'No se pudo registrar la cuenta en el catálogo.'];
                }
            }

            $this->sql->update('UPDATE [TG].[dbo].[bank_account_requests] SET catalogo_cuenta_id = ? WHERE id = ?;', [$catId, (int) $req['id']]);
            $this->sql->commit();
            return ['success' => true, 'catalogo_id' => $catId, 'ligada' => $ligada,
                'message' => $ligada ? 'Solicitud aprobada: la cuenta ya existía en el catálogo y quedó ligada y activa.' : 'Solicitud aprobada: la cuenta se agregó al catálogo.'];
        } catch (Throwable $e) {
            $this->sql->rollBack();
            error_log('BankAccountRequestsModel::approve(#' . $req['id'] . '): ' . $e->getMessage());
            return ['success' => false, 'message' => 'No se pudo aprobar la solicitud.'];
        }
    }
}
