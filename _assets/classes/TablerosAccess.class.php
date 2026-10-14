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

    /** Un usuario con permiso de acceso, creación o administración puede usar Tableros. */
    public function userHasModuleAccess(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        try {
            $stmt = $this->tgConnection()->prepare(
                "SELECT TOP (1) 1
                 FROM [TG].[dbo].[tg_permissions] p
                 INNER JOIN [TG].[dbo].[tg_permissions_users] pu ON pu.permission_id = p.id
                 WHERE pu.user_id = ? AND p.department = ? AND p.status = 1
                   AND ((p.action = ? AND p.description = ?)
                     OR (p.action = ? AND p.description = ?)
                     OR (p.action = ? AND p.description = ?))"
            );
            $stmt->execute([$userId, 'General', 'read', 'Tableros - Acceso', 'create', 'Tableros - Crear', 'update', 'Tableros - Administrar']);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('TablerosAccess: no fue posible validar acceso al módulo para usuario: ' . $e->getMessage());
            return false;
        }
    }

    /** @return array<int, array{id:int,name:string,username:string,email:string}> */
    public function activeUsers(string $search = '', bool $requireModuleAccess = false, bool $explicitAccessOnly = false): array
    {
        $search = mb_substr(trim($search), 0, 80, 'UTF-8');
        $sql = 'SELECT TOP (30) u.Id, u.Nombre, u.Usuario, u.Correo
                FROM [TG].[dbo].[Usuario] u
                WHERE u.Estatus = 1';
        if ($requireModuleAccess) {
            $permissionFilter = $explicitAccessOnly
                ? "p.action = 'read' AND p.description = 'Tableros - Acceso'"
                : "((p.action = 'read' AND p.description = 'Tableros - Acceso')
                    OR (p.action = 'create' AND p.description = 'Tableros - Crear')
                    OR (p.action = 'update' AND p.description = 'Tableros - Administrar'))";
            $sql .= " AND EXISTS (
                SELECT 1 FROM [TG].[dbo].[tg_permissions] p
                INNER JOIN [TG].[dbo].[tg_permissions_users] pu ON pu.permission_id = p.id
                WHERE pu.user_id = u.Id AND p.department = 'General' AND p.status = 1
                  AND $permissionFilter
            )";
        }
        $params = [];
        if ($search !== '') {
            $sql .= ' AND (u.Nombre LIKE ? OR u.Usuario LIKE ? OR u.Correo LIKE ?)';
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
                  AND (u.Id = b.created_by
                       OR EXISTS (SELECT 1 FROM dbo.tb_board_member bm
                                  WHERE bm.board_id = b.id AND bm.user_id = u.Id AND bm.deleted_at IS NULL)
                       OR b.visibility = \'organization\'
                       OR (b.visibility = \'workspace\' AND (
                           u.Id = w.created_by
                           OR EXISTS (SELECT 1 FROM dbo.tb_workspace_member wm
                                      WHERE wm.workspace_id = w.id AND wm.user_id = u.Id AND wm.deleted_at IS NULL)
                           OR w.visibility = \'open\')))
                  AND EXISTS (SELECT 1 FROM [TG].[dbo].[tg_permissions] p
                              INNER JOIN [TG].[dbo].[tg_permissions_users] pu ON pu.permission_id=p.id
                              WHERE pu.user_id=u.Id AND p.department=\'General\' AND p.status=1
                                AND ((p.action=\'read\' AND p.description=\'Tableros - Acceso\')
                                  OR (p.action=\'create\' AND p.description=\'Tableros - Crear\')
                                  OR (p.action=\'update\' AND p.description=\'Tableros - Administrar\')))';
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

    /** Active module users who may be invited to this workspace. */
    public function activeUsersForWorkspace(int $workspaceId, string $search = ''): array
    {
        $search = mb_substr(trim($search), 0, 80, 'UTF-8');
        $sql = "SELECT TOP (30) u.Id, u.Nombre, u.Usuario
                FROM [TG].[dbo].[Usuario] u
                WHERE u.Estatus=1
                  AND EXISTS (SELECT 1 FROM [TG].[dbo].[tg_permissions] p
                              INNER JOIN [TG].[dbo].[tg_permissions_users] pu ON pu.permission_id=p.id
                              WHERE pu.user_id=u.Id AND p.department='General' AND p.status=1
                                AND ((p.action='read' AND p.description='Tableros - Acceso')
                                  OR (p.action='create' AND p.description='Tableros - Crear')
                                  OR (p.action='update' AND p.description='Tableros - Administrar')))
                  AND NOT EXISTS (SELECT 1 FROM dbo.tb_workspace_member wm WHERE wm.workspace_id=? AND wm.user_id=u.Id AND wm.deleted_at IS NULL)
                  AND NOT EXISTS (SELECT 1 FROM dbo.tb_workspace w WHERE w.id=? AND w.created_by=u.Id AND w.deleted_at IS NULL)";
        $params = [$workspaceId, $workspaceId];
        if ($search !== '') {
            $sql .= ' AND (u.Nombre LIKE ? OR u.Usuario LIKE ?)';
            $term = '%' . $search . '%';
            $params[] = $term; $params[] = $term;
        }
        $sql .= ' ORDER BY u.Nombre,u.Usuario,u.Id';
        try {
            $stmt = $this->boardConnection()->prepare($sql);
            $stmt->execute($params);
            return array_map(static fn(array $row): array => ['id'=>(int)$row['Id'],'name'=>trim((string)($row['Nombre'] ?? '')) ?: (string)($row['Usuario'] ?? ''),'username'=>(string)($row['Usuario'] ?? '')], $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
            error_log('TablerosAccess: no fue posible listar candidatos de espacio: ' . $e->getMessage());
            return [];
        }
    }

    /** Resolve the canonical effective board role and the source that granted it. */
    public function boardAccess(int $boardId): ?array
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
                return ['role' => 'owner', 'source' => 'owner'];
            }
            $role = strtolower((string)($row['board_role'] ?? ''));
            $natural = null;
            if (in_array($role, ['designer', 'editor', 'contributor', 'viewer'], true)) $natural = ['role' => $role, 'source' => 'board_invitation'];
            $workspaceRole = strtolower((string)($row['workspace_role'] ?? ''));
            $boardVisibility = strtolower((string)$row['visibility']);
            $workspaceVisibility = strtolower((string)$row['workspace_visibility']);
            if ($natural === null && $boardVisibility === 'workspace' && in_array($workspaceRole, ['owner', 'editor', 'viewer'], true)) {
                $natural = ['role' => in_array($workspaceRole, ['owner', 'editor'], true) ? 'editor' : 'viewer', 'source' => 'workspace_membership'];
            }
            if ($natural === null && $boardVisibility === 'workspace' && $workspaceVisibility === 'open') {
                $natural = ['role' => 'viewer', 'source' => 'open_workspace'];
            }
            if ($natural === null && $boardVisibility === 'organization') {
                $natural = ['role' => 'viewer', 'source' => 'organization'];
            }
            if ($this->hasGlobalPermission('admin')) return ['role' => 'designer', 'source' => $natural['source'] ?? 'admin'];
            return $natural;
        } catch (Throwable $e) {
            error_log('TablerosAccess: no fue posible validar acceso al tablero: ' . $e->getMessage());
            return null;
        }
    }

    /** Backwards-compatible role-only accessor. */
    public function boardRole(int $boardId): ?string
    {
        return $this->boardAccess($boardId)['role'] ?? null;
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
            if ((int)($row['created_by'] ?? 0) === $userId) return 'owner';
            if ($this->hasGlobalPermission('admin')) return 'owner';
            $role = strtolower((string)($row['role'] ?? ''));
            if (in_array($role, ['owner', 'editor', 'viewer'], true)) {
                return $role;
            }
            if (strtolower((string)$row['visibility']) === 'open') return 'viewer';
            return $this->hasGlobalPermission('admin') ? 'owner' : null;
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
