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
        LEFT JOIN [TG].[dbo].[razones_sociales_pago] e ON e.cod = r.empresa_cod
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

    /**
     * Número con el que la solicitud vive en el catálogo (CuentaLocal): la CLABE en cuentas
     * mexicanas y el account number en las americanas. Las solicitudes anteriores a la
     * separación de campos solo tienen num_cuenta.
     */
    public static function catalog_key(array $req): string
    {
        $clabe = trim((string) ($req['clabe'] ?? ''));
        return $clabe !== '' ? $clabe : (string) $req['num_cuenta'];
    }

    /** Dígito verificador ABA de un routing number de 9 dígitos. */
    public static function aba_valido(string $r): bool
    {
        if (!preg_match('/^\d{9}$/', $r)) {
            return false;
        }
        $d = array_map('intval', str_split($r));
        return (3 * ($d[0] + $d[3] + $d[6]) + 7 * ($d[1] + $d[4] + $d[7]) + ($d[2] + $d[5] + $d[8])) % 10 === 0;
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
        // La CLABE puede estar en CuentaLocal (cuentas viejas) o en Clave (altas nuevas).
        $cat = $this->sql->select(
            'SELECT Id, CuentaLocal, Descripcion, Banco, Activo FROM [TG].[dbo].[CatalogosCuentasBancarias] WHERE CuentaLocal = ? OR Clave = ?;',
            [$cuenta, $cuenta]
        ) ?: [];
        $sol = $this->sql->select(
            'SELECT id, proveedor, banco FROM [TG].[dbo].[bank_account_requests]
             WHERE (clabe = ? OR (clabe IS NULL AND num_cuenta = ?)) AND status = ? AND id <> ?;',
            [$cuenta, $cuenta, self::ST_PENDIENTE, $exceptRequestId]
        ) ?: [];
        return ['catalogo' => $cat, 'solicitudes' => $sol];
    }

    /**
     * Motivo por el que la cuenta NO se puede dar de alta, o null si está libre.
     * Se revisan los DOS números capturados, cada uno contra los dos campos donde puede estar:
     *  - CLABE: en CuentaLocal o Clave del catálogo (cualquier banco); en clabe o num_cuenta de otra alta.
     *  - Cuenta / account number: igual, en cualquier banco (el mensaje dice de qué banco es la existente).
     * Catálogo: activa o no. Altas: pendiente, aprobada o borrador (las rechazadas no cuentan).
     */
    public function cuenta_registrada(string $clabe, string $cuenta, string $banco, int $exceptRequestId = 0): ?string
    {
        $clabe  = trim($clabe);
        $cuenta = trim($cuenta);
        $revisar = [];
        if ($clabe !== '') {
            $revisar[] = ['La CLABE ' . $clabe, $clabe, null];
        }
        if ($cuenta !== '' && $cuenta !== $clabe) {
            $revisar[] = ['La cuenta ' . $cuenta, $cuenta, null];
        }
        foreach ($revisar as [$que, $numero, $soloBanco]) {
            $filtroBanco = $soloBanco !== null ? ' AND Banco = ?' : '';
            $params = [$numero, $numero];
            if ($soloBanco !== null) { $params[] = $soloBanco; }
            $cat = $this->sql->select(
                'SELECT TOP 1 Id, Descripcion, Banco, Activo FROM [TG].[dbo].[CatalogosCuentasBancarias]
                  WHERE (CuentaLocal = ? OR Clave = ?)' . $filtroBanco . '
                  ORDER BY Activo DESC, Id DESC;',
                $params
            );
            if ($cat) {
                $c = $cat[0];
                return $que . ' ya está registrada en Cuentas bancarias (#' . $c['Id'] . ' ' . trim((string) $c['Descripcion'])
                    . ($c['Banco'] ? ' · ' . trim((string) $c['Banco']) : '') . ', '
                    . ((int) $c['Activo'] === 1 ? 'activa' : 'inactiva: pida a Tesorería que la reactive') . '). No se guardó la solicitud.';
            }
            $filtroBanco = $soloBanco !== null ? ' AND banco = ?' : '';
            $params = [$exceptRequestId, self::ST_PENDIENTE, self::ST_APROBADA, self::ST_BORRADOR, $numero, $numero];
            if ($soloBanco !== null) { $params[] = $soloBanco; }
            $sol = $this->sql->select(
                'SELECT TOP 1 id, proveedor, status FROM [TG].[dbo].[bank_account_requests]
                  WHERE id <> ? AND status IN (?, ?, ?) AND (clabe = ? OR num_cuenta = ?)' . $filtroBanco . '
                  ORDER BY id DESC;',
                $params
            );
            if ($sol) {
                $s = $sol[0];
                $estatus = [self::ST_PENDIENTE => 'pendiente de Tesorería', self::ST_APROBADA => 'aprobada', self::ST_BORRADOR => 'en borrador'][(int) $s['status']] ?? '';
                return $que . ' ya está en la solicitud de alta #' . $s['id'] . ' (' . $estatus . ')'
                    . ($s['proveedor'] ? ' de ' . trim((string) $s['proveedor']) : '') . '. No se guardó la solicitud.';
            }
        }
        return null;
    }

    /** Inserta la solicitud (sin archivo todavía). Devuelve el id o 0. */
    public function create(array $d, int $userId, int $status = self::ST_PENDIENTE): int
    {
        $id = $this->sql->insert(
            'INSERT INTO [TG].[dbo].[bank_account_requests]
                (user_id, empresa_cod, banco, clave_banco, moneda, tipo_cuenta, num_cuenta, clabe, routing_number, proveedor,
                 plaza_banxico, sucursal, tipo_cuenta_cod, benef_app_paterno, benef_app_materno, benef_log,
                 benef_direccion, benef_ciudad, departamento, fecha_solicitud, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CAST(GETDATE() AS DATE), ?);',
            [
                $userId, $d['empresa_cod'], $d['banco'], $d['clave_banco'], $d['moneda'], $d['tipo_cuenta'],
                $d['num_cuenta'], $d['clabe'] ?: null, $d['routing_number'] ?: null, $d['proveedor'], $d['plaza_banxico'], $d['sucursal'], $d['tipo_cuenta_cod'],
                $d['benef_app_paterno'] ?: null, $d['benef_app_materno'] ?: null, $d['benef_log'],
                $d['benef_direccion'] ?: null, $d['benef_ciudad'], $d['departamento'], $status,
            ]
        );
        $id = (int) $id;
        if ($id > 0) {
            foreach ($d['pagadores'] ?? [] as $empCod) {
                $this->sql->insert(
                    'INSERT INTO [TG].[dbo].[bank_account_request_payers] (request_id, emp_cod) VALUES (?, ?);',
                    [$id, (int) $empCod]
                );
            }
        }
        return $id;
    }

    /**
     * Razones sociales que pueden pagar, por solicitud: [request_id => [['cod' => .., 'den' => ..], ...]].
     */
    public function pagadores_por_solicitud(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }
        $rows = $this->sql->select(
            'SELECT p.request_id, p.emp_cod AS cod, e.den
               FROM [TG].[dbo].[bank_account_request_payers] p
               LEFT JOIN [TG].[dbo].[razones_sociales_pago] e ON e.cod = p.emp_cod
              WHERE p.request_id IN (' . implode(',', $ids) . ')
              ORDER BY e.den;',
            []
        ) ?: [];
        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r['request_id']][] = ['cod' => (int) $r['cod'], 'den' => (string) ($r['den'] ?? $r['cod'])];
        }
        return $map;
    }

    /**
     * Mensaje de qué script falta correr para usar razones sociales por cuenta, o null si está todo.
     */
    public function esquema_faltante(): ?string
    {
        $r = $this->sql->select(
            "SELECT COL_LENGTH('TG.dbo.CatalogosCuentasBancarias', 'Direccion') AS direccion,
                    OBJECT_ID('TG.dbo.bank_account_payers') AS payers,
                    OBJECT_ID('TG.dbo.bank_account_request_payers') AS req_payers;",
            []
        );
        $r = $r ? $r[0] : [];
        if (($r['direccion'] ?? null) === null) {
            return 'al catálogo de cuentas le falta la columna Direccion: pida a Sistemas correr docs/sql/catalogo_cuentas_direccion.sql.';
        }
        if (($r['payers'] ?? null) === null || ($r['req_payers'] ?? null) === null) {
            return 'faltan las tablas de razones sociales por cuenta: pida a Sistemas correr docs/sql/cuentas_proveedor_razones_sociales.sql.';
        }
        return null;
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
     * Aprueba: inserta la cuenta en CatalogosCuentasBancarias como 'Terceros' (cuenta en
     * CuentaLocal, CLABE en Clave y Direccion) o, si ya existe, la liga a la fila
     * existente (reactivándola y llenando solo Clave/Direccion vacíos). emp_cod NO se toca: en el
     * catálogo solo lo llevan las cuentas Propias. Las razones sociales que pueden pagar a la
     * cuenta se agregan a bank_account_payers (sin quitar las que ya tenía). Todo en una
     * transacción; el UPDATE de estatus es condicional para que dos aprobaciones
     * simultáneas no dupliquen la cuenta.
     * Devuelve ['success', 'message', 'catalogo_id'?, 'ligada'?].
     */
    public function approve(array $req, int $userId, ?string $comentario): array
    {
        $id = (int) $req['id'];

        // Sin las columnas/tablas nuevas el INSERT del catálogo truena: avisar qué script falta en vez de un error genérico.
        $faltante = $this->esquema_faltante();
        if ($faltante !== null) {
            return ['success' => false, 'message' => 'No se aprobó la solicitud #' . $id . ': ' . $faltante . ' La solicitud sigue pendiente.'];
        }

        // Paso en curso, para decir en el mensaje dónde falló.
        $paso = 'marcar la solicitud como aprobada';
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

            // Catálogo: CuentaLocal = cuenta (MXN) o account number (USD); Clave = CLABE (MXN).
            $cuenta    = trim((string) $req['num_cuenta']);
            $clabe     = trim((string) ($req['clabe'] ?? ''));
            $direccion = trim((string) ($req['benef_direccion'] ?? ''));

            // Ya existe si la CLABE está en CuentaLocal (cuentas viejas) o en Clave, o si la
            // misma cuenta ya está registrada en el mismo banco.
            $paso = 'buscar si la cuenta ya existe en el catálogo';
            $existente = $this->sql->select(
                'SELECT TOP 1 Id, Activo FROM [TG].[dbo].[CatalogosCuentasBancarias]
                 WHERE (? <> \'\' AND (CuentaLocal = ? OR Clave = ?)) OR (CuentaLocal = ? AND Banco = ?)
                 ORDER BY Activo DESC, Id ASC;',
                [$clabe, $clabe, $clabe, $cuenta, $req['banco']]
            );
            $ligada = false;
            if ($existente) {
                $catId  = (int) $existente[0]['Id'];
                $ligada = true;
                // Se reactiva y solo se completan los datos vacíos; lo capturado no se pisa.
                $paso = 'actualizar la cuenta existente #' . $catId . ' del catálogo';
                $this->sql->update(
                    'UPDATE [TG].[dbo].[CatalogosCuentasBancarias]
                     SET Activo = 1,
                         Clave     = COALESCE(NULLIF(Clave, \'\'), ?),
                         Direccion = COALESCE(NULLIF(Direccion, \'\'), ?),
                         FechaModificacion = GETDATE(), UsuarioModificacion = ?
                     WHERE Id = ?;',
                    [$clabe ?: null, $direccion ?: null, $userId, $catId]
                );
            } else {
                $paso = 'agregar la cuenta al catálogo de cuentas bancarias';
                $catId = (int) $this->sql->insert(
                    'INSERT INTO [TG].[dbo].[CatalogosCuentasBancarias]
                        (FechaAlta, CuentaLocal, Clave, Descripcion, TipoCuenta, Banco, Divisa,
                         TitularCuenta, Tipo, Direccion, Activo, FechaRegistro, UsuarioRegistro, EntidadCuentaLocalABASwift)
                     VALUES (CAST(GETDATE() AS DATE), ?, ?, ?, \'Otros Bancos Nacionales\', ?, ?, ?, \'Terceros\', ?, 1, GETDATE(), ?, ?);',
                    [
                        $cuenta, $clabe ?: null, $req['proveedor'], $req['banco'],
                        self::DIVISAS[$req['moneda']] ?? self::DIVISAS['PESOS'],
                        $req['proveedor'], $direccion ?: null, (string) $userId,
                        trim((string) ($req['routing_number'] ?? '')) ?: null,  // ABA / routing number (cuentas USD)
                    ]
                );
                if ($catId <= 0) {
                    $this->sql->rollBack();
                    return ['success' => false, 'message' => 'No se aprobó la solicitud #' . $id . ': el catálogo no devolvió el id de la cuenta nueva. No se guardó ningún cambio; avise a Sistemas.'];
                }
            }

            $paso = 'ligar la solicitud con la cuenta #' . $catId . ' del catálogo';
            $this->sql->update('UPDATE [TG].[dbo].[bank_account_requests] SET catalogo_cuenta_id = ? WHERE id = ?;', [$catId, $id]);

            // Razones sociales que pueden pagarle: las de la solicitud se suman a las que ya tuviera la cuenta.
            $paso = 'registrar las razones sociales que pueden pagar a la cuenta #' . $catId;
            // insert(), no update(): update() ignora en silencio los queries sin la palabra "update".
            $this->sql->insert(
                'INSERT INTO [TG].[dbo].[bank_account_payers] (catalogo_cuenta_id, emp_cod, created_by)
                 SELECT ?, p.emp_cod, ?
                   FROM [TG].[dbo].[bank_account_request_payers] p
                  WHERE p.request_id = ?
                    AND NOT EXISTS (SELECT 1 FROM [TG].[dbo].[bank_account_payers] x
                                     WHERE x.catalogo_cuenta_id = ? AND x.emp_cod = p.emp_cod);',
                [$catId, $userId, $id, $catId]
            );
            $paso = 'confirmar los cambios';
            $this->sql->commit();
            return ['success' => true, 'catalogo_id' => $catId, 'ligada' => $ligada,
                'message' => $ligada ? 'Solicitud aprobada: la cuenta ya existía en el catálogo y quedó ligada y activa.' : 'Solicitud aprobada: la cuenta se agregó al catálogo.'];
        } catch (Throwable $e) {
            $this->sql->rollBack();
            error_log('BankAccountRequestsModel::approve(#' . $id . ') al ' . $paso . ': ' . $e->getMessage());
            return ['success' => false, 'message' => 'No se aprobó la solicitud #' . $id . ': falló al ' . $paso . '. '
                . 'No se guardó ningún cambio y la solicitud sigue pendiente. Si se repite, avise a Sistemas '
                . '(el detalle técnico quedó en logs/php_errors.log con la hora del intento).'];
        }
    }
}
