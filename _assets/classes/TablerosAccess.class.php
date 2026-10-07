<?php

/** Permisos del módulo resueltos por nombre en TG y ACL local por tablero. */
class TablerosAccess
{
    private const PERMISSIONS = [
        'access' => ['read', 'Tableros - Acceso'],
        'create' => ['create', 'Tableros - Crear'],
        'admin'  => ['update', 'Tableros - Administrar'],
    ];

    private ?PDO $boardDb;
    private static ?PDO $tgDb = null;

    public function __construct(?PDO $boardDb = null)
    {
        $this->boardDb = $boardDb;
    }

    public function hasGlobalPermission(string $key): bool
    {
        $userId = $this->currentUserId();
        if ($userId <= 0) {
            return false;
        }

        $keys = match ($key) {
            'access' => ['access', 'create', 'admin'],
            'create' => ['create', 'admin'],
            'admin' => ['admin'],
            default => [],
        };
        if ($keys === []) {
            return false;
        }

        try {
            $pdo = $this->tgConnection();
            foreach ($keys as $permissionKey) {
                [$action, $description] = self::PERMISSIONS[$permissionKey];
                $stmt = $pdo->prepare(
                    "SELECT TOP (1) 1
                     FROM [TG].[dbo].[tg_permissions] p
                     INNER JOIN [TG].[dbo].[tg_permissions_users] pu
                         ON pu.permission_id = p.id
                     WHERE pu.user_id = ? AND p.department = ?
                       AND p.action = ? AND p.description = ? AND p.status = 1"
                );
                $stmt->execute([$userId, 'General', $action, $description]);
                if ($stmt->fetchColumn()) {
                    return true;
                }
            }
        } catch (Throwable $e) {
            error_log('TablerosAccess: no fue posible validar permisos TG: ' . $e->getMessage());
        }

        return false;
    }

    public function requireGlobal(string $key): void
    {
        if (!$this->hasGlobalPermission($key)) {
            throw new RuntimeException('No tienes permiso para esta acción.', 403);
        }
    }

    public function userIsActive(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        try {
            $stmt = $this->tgConnection()->prepare(
                'SELECT TOP (1) 1 FROM [TG].[dbo].[Usuario] WHERE Id = ? AND Estatus = 1'
            );
            $stmt->execute([$userId]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('TablerosAccess: no fue posible validar usuario TG: ' . $e->getMessage());
            return false;
        }
    }

    /** @return array<int, array{id:int,name:string,username:string,email:string}> */
    public function activeUsers(string $search = ''): array
    {
        $search = mb_substr(trim($search), 0, 80, 'UTF-8');
        $sql = 'SELECT TOP (30) Id, Nombre, Usuario, Correo
                FROM [TG].[dbo].[Usuario]
                WHERE Estatus = 1';
        $params = [];
        if ($search !== '') {
            $sql .= ' AND (Nombre LIKE ? OR Usuario LIKE ? OR Correo LIKE ?)';
            $term = '%' . $search . '%';
            $params = [$term, $term, $term];
        }
        $sql .= ' ORDER BY Nombre, Id';

        try {
            $stmt = $this->tgConnection()->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return array_map(static fn(array $row): array => [
                'id'       => (int)$row['Id'],
                'name'     => (string)($row['Nombre'] ?? ''),
                'username' => (string)($row['Usuario'] ?? ''),
                'email'    => (string)($row['Correo'] ?? ''),
            ], $rows);
        } catch (Throwable $e) {
            error_log('TablerosAccess: no fue posible listar usuarios TG: ' . $e->getMessage());
            return [];
        }
    }

    /** Devuelve owner, designer, editor, viewer o null si no tiene acceso. */
    public function boardRole(int $boardId): ?string
    {
        $userId = $this->currentUserId();
        if ($boardId <= 0 || $userId <= 0) {
            return null;
        }

        try {
            $stmt = $this->boardConnection()->prepare(
                'SELECT b.created_by, m.role
                 FROM dbo.tb_board b
                 LEFT JOIN dbo.tb_board_member m
                   ON m.board_id = b.id AND m.user_id = ? AND m.deleted_at IS NULL
                 WHERE b.id = ? AND b.deleted_at IS NULL'
            );
            $stmt->execute([$userId, $boardId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }
            if ((int)$row['created_by'] === $userId) {
                return 'owner';
            }
            if ($this->hasGlobalPermission('admin')) {
                return 'designer';
            }

            $role = strtolower((string)($row['role'] ?? ''));
            return in_array($role, ['designer', 'editor', 'viewer'], true) ? $role : null;
        } catch (Throwable $e) {
            error_log('TablerosAccess: no fue posible validar acceso al tablero: ' . $e->getMessage());
            return null;
        }
    }

    public function canViewBoard(int $boardId): bool
    {
        return $this->boardRole($boardId) !== null;
    }

    private function currentUserId(): int
    {
        return (int)($_SESSION['tg_user']['Id'] ?? 0);
    }

    private function boardConnection(): PDO
    {
        return $this->boardDb ?? TablerosConnection::get();
    }

    private function tgConnection(): PDO
    {
        if (self::$tgDb instanceof PDO) {
            return self::$tgDb;
        }
        self::$tgDb = MySqlPdoHandler::getInstance()->createIsolatedConnection('TG');
        return self::$tgDb;
    }
}
