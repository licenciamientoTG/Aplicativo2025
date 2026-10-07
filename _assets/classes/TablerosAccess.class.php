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

    /** Lista personas activas con acceso efectivo al espacio del tablero. */
    public function activeUsersForBoard(int $boardId, string $search = '', array $includeIds = []): array
    {
        $search = mb_substr(trim($search), 0, 80, 'UTF-8');
        $includeIds = array_values(array_unique(array_filter(array_map('intval', $includeIds), static fn(int $id): bool => $id > 0)));
        $sql = 'SELECT TOP (130) u.Id, u.Nombre, u.Usuario, u.Correo
                FROM [TG].[dbo].[Usuario] u
                INNER JOIN dbo.tb_board b ON b.id = ? AND b.deleted_at IS NULL
                INNER JOIN dbo.tb_workspace w ON w.id = b.workspace_id AND w.deleted_at IS NULL
                WHERE u.Estatus = 1
                  AND (u.Id = b.created_by OR u.Id = w.created_by
                       OR EXISTS (SELECT 1 FROM dbo.tb_workspace_member wm
                                  WHERE wm.workspace_id = w.id AND wm.user_id = u.Id AND wm.deleted_at IS NULL)
                       OR EXISTS (SELECT 1 FROM dbo.tb_board_member bm
                                  WHERE bm.board_id = b.id AND bm.user_id = u.Id AND bm.deleted_at IS NULL))';
        $params = [$boardId];
        $matches = [];
        if ($search !== '') {
            $matches[] = '(u.Nombre LIKE ? OR u.Usuario LIKE ? OR u.Correo LIKE ?)';
            $term = '%' . $search . '%';
            array_push($params, $term, $term, $term);
        }
        if ($includeIds) {
            $matches[] = 'u.Id IN (' . implode(',', array_fill(0, count($includeIds), '?')) . ')';
            foreach ($includeIds as $id) $params[] = $id;
        }
        if ($matches) $sql .= ' AND (' . implode(' OR ', $matches) . ')';
        $sql .= ' ORDER BY u.Nombre, u.Usuario, u.Id';

        try {
            $stmt = $this->boardConnection()->prepare($sql);
            $stmt->execute($params);
            return array_map(static fn(array $row): array => [
                'id' => (int)$row['Id'],
                'name' => trim((string)($row['Nombre'] ?? '')) ?: (string)($row['Usuario'] ?? ''),
                'username' => (string)($row['Usuario'] ?? ''),
            ], $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
            error_log('TablerosAccess: no fue posible listar miembros del tablero: ' . $e->getMessage());
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
                'SELECT b.created_by, b.visibility, b.workspace_id, m.role AS board_role,
                        wm.role AS workspace_role, w.visibility AS workspace_visibility
                 FROM dbo.tb_board b
                 LEFT JOIN dbo.tb_board_member m
                   ON m.board_id = b.id AND m.user_id = ? AND m.deleted_at IS NULL
                 INNER JOIN dbo.tb_workspace w ON w.id = b.workspace_id AND w.deleted_at IS NULL
                 LEFT JOIN dbo.tb_workspace_member wm
                   ON wm.workspace_id = w.id AND wm.user_id = ? AND wm.deleted_at IS NULL
                 WHERE b.id = ? AND b.deleted_at IS NULL'
            );
            $stmt->execute([$userId, $userId, $boardId]);
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

            $role = strtolower((string)($row['board_role'] ?? ''));
            if (in_array($role, ['designer', 'editor', 'viewer'], true)) {
                return $role;
            }
            $workspaceRole = strtolower((string)($row['workspace_role'] ?? ''));
            if (in_array($workspaceRole, ['owner', 'editor', 'viewer'], true)
                && in_array(strtolower((string)$row['visibility']), ['workspace', 'public'], true)) {
                return $workspaceRole === 'owner' ? 'owner' : $workspaceRole;
            }
            if (strtolower((string)$row['visibility']) === 'public') {
                return 'viewer';
            }
            if (in_array(strtolower((string)$row['visibility']), ['workspace', 'public'], true)
                && in_array(strtolower((string)$row['workspace_visibility']), ['workspace', 'public'], true)) {
                return 'viewer';
            }
            return null;
        } catch (Throwable $e) {
            error_log('TablerosAccess: no fue posible validar acceso al tablero: ' . $e->getMessage());
            return null;
        }
    }

    public function canViewBoard(int $boardId): bool
    {
        return $this->boardRole($boardId) !== null;
    }

    /** Returns the user's effective workspace role, or null when it is private to others. */
    public function workspaceRole(int $workspaceId): ?string
    {
        $userId = $this->currentUserId();
        if ($workspaceId <= 0 || $userId <= 0) {
            return null;
        }

        try {
            $stmt = $this->boardConnection()->prepare(
                'SELECT w.created_by, w.visibility, m.role
                 FROM dbo.tb_workspace w
                 LEFT JOIN dbo.tb_workspace_member m
                   ON m.workspace_id = w.id AND m.user_id = ? AND m.deleted_at IS NULL
                 WHERE w.id = ? AND w.workspace_type = ? AND w.deleted_at IS NULL'
            );
            $stmt->execute([$userId, $workspaceId, 'workspace']);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }
            if ($this->hasGlobalPermission('admin')) {
                return 'owner';
            }
            if ((int)($row['created_by'] ?? 0) === $userId) {
                return 'owner';
            }
            $role = strtolower((string)($row['role'] ?? ''));
            if (in_array($role, ['owner', 'editor', 'viewer'], true)) {
                return $role;
            }
            return in_array(strtolower((string)$row['visibility']), ['workspace', 'public'], true) ? 'viewer' : null;
        } catch (Throwable $e) {
            error_log('TablerosAccess: no fue posible validar acceso al espacio de trabajo: ' . $e->getMessage());
            return null;
        }
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
