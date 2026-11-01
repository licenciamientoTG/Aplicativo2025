<?php

/** A safe, API-facing exception with an HTTP status and stable error code. */
class TablerosApiException extends RuntimeException {
    public string $apiCode;
    public int $httpStatus;

    public function __construct(string $apiCode, string $message, int $httpStatus) {
        parent::__construct($message);
        $this->apiCode = $apiCode;
        $this->httpStatus = $httpStatus;
    }
}

/**
 * Persistence and validation for the Tableros API.
 * This deliberately does not extend Model: Tableros uses its own PDO.
 */
class TablerosModel {
    public const ALLOWED_TYPES = [
        'name', 'text', 'long_text', 'numbers', 'formula', 'progress', 'rating',
        'people', 'person', 'team', 'status', 'dropdown', 'tags', 'date',
        'timeline', 'hour', 'file', 'email', 'phone', 'country', 'link',
        'location', 'board_relation', 'subtasks', 'dependency', 'item_id',
    ];

    private PDO $pdo;
    private TablerosAccess $access;

    public function __construct(?PDO $pdo = null) {
        $this->pdo = $pdo ?? TablerosConnection::get();
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->access = new TablerosAccess($this->pdo);
    }

    public function getBoards(int $userId, bool $isAdmin): array {
        $sql = "SELECT DISTINCT b.id, b.workspace_id, b.folder_id, b.name, b.description, b.sort_order,
                       w.name AS workspace_name, w.visibility AS workspace_visibility,
                       f.name AS folder_name, f.parent_folder_id,
                       b.visibility, b.created_by, b.created_at, b.updated_at,
                        CASE WHEN b.created_by = ? THEN 'owner'
                            WHEN ? = 1 THEN 'designer'
                            WHEN m.role IS NOT NULL THEN m.role
                            WHEN wm.role IN ('owner','editor') AND b.visibility = 'workspace' THEN 'editor'
                            WHEN wm.role = 'viewer' AND b.visibility = 'workspace' THEN 'viewer'
                            WHEN b.visibility = 'workspace' AND w.visibility = 'open' THEN 'viewer'
                            WHEN b.visibility = 'organization' THEN 'viewer' END AS user_role,
                        CASE WHEN b.created_by = ? THEN 'owner'
                            WHEN m.role IS NOT NULL THEN 'board_invitation'
                            WHEN wm.role IS NOT NULL AND b.visibility = 'workspace' THEN 'workspace_membership'
                            WHEN b.visibility = 'workspace' AND w.visibility = 'open' THEN 'open_workspace'
                            WHEN b.visibility = 'organization' THEN 'organization'
                            WHEN ? = 1 THEN 'admin' END AS access_source
                FROM tb_board b
                INNER JOIN tb_workspace w ON w.id = b.workspace_id AND w.deleted_at IS NULL
                LEFT JOIN tb_folder f ON f.id = b.folder_id AND f.workspace_id = b.workspace_id AND f.deleted_at IS NULL
                LEFT JOIN tb_board_member m ON m.board_id = b.id AND m.user_id = ? AND m.deleted_at IS NULL
                LEFT JOIN tb_workspace_member wm ON wm.workspace_id = w.id AND wm.user_id = ? AND wm.deleted_at IS NULL
                WHERE b.deleted_at IS NULL
                  AND (b.created_by = ? OR m.user_id = ? OR ? = 1
                       OR b.visibility = 'organization'
                       OR (b.visibility = 'workspace' AND (wm.user_id = ? OR w.visibility = 'open')))
                ORDER BY w.name, f.name, b.sort_order, b.name, b.id";
        $boards = $this->all($sql, [$userId, $isAdmin ? 1 : 0, $userId, $isAdmin ? 1 : 0, $userId, $userId, $userId, $userId, $isAdmin ? 1 : 0, $userId]);
        foreach ($boards as &$board) {
            $board['can_reorder'] = in_array($board['user_role'], ['owner', 'designer', 'editor'], true);
        }
        unset($board);
        return $boards;
    }

    /** Flat hierarchy payload; the client builds the nested folder tree. */
    public function getWorkspaceStructure(int $userId, bool $isAdmin): array {
        $workspaces = $this->all(
            "SELECT w.id, w.name, w.description, w.visibility, w.created_by,
                    CASE WHEN w.created_by = ? THEN 'owner' WHEN ? = 1 THEN 'owner'
                         WHEN wm.role IS NOT NULL THEN wm.role
                         WHEN w.visibility = 'open' THEN 'viewer' END AS user_role,
                    CASE WHEN w.created_by = ? THEN 'owner' WHEN wm.role IS NOT NULL THEN 'workspace_membership'
                         WHEN w.visibility = 'open' THEN 'open_workspace' WHEN ? = 1 THEN 'admin' END AS access_source,
                    CASE WHEN w.created_by = ? OR ? = 1 OR wm.role = 'owner' THEN 1 ELSE 0 END AS can_manage_members
             FROM tb_workspace w
             LEFT JOIN tb_workspace_member wm ON wm.workspace_id = w.id AND wm.user_id = ? AND wm.deleted_at IS NULL
             WHERE w.workspace_type = 'workspace' AND w.deleted_at IS NULL
               AND (w.created_by = ? OR wm.user_id = ? OR w.visibility = 'open' OR ? = 1)
             ORDER BY w.name, w.id",
            [$userId, $isAdmin ? 1 : 0, $userId, $isAdmin ? 1 : 0, $userId, $isAdmin ? 1 : 0, $userId, $userId, $userId, $isAdmin ? 1 : 0]
        );
        $boards = $this->getBoards($userId, $isAdmin);
        $workspaceIds = array_fill_keys(array_map(static fn($workspace) => (string)$workspace['id'], $workspaces), true);
        foreach ($boards as $board) {
            $workspaceId = (string)$board['workspace_id'];
            if (!isset($workspaceIds[$workspaceId])) {
                $workspaces[] = [
                    'id' => (int)$board['workspace_id'], 'name' => (string)$board['workspace_name'],
                    'description' => '', 'visibility' => (string)$board['workspace_visibility'],
                    'created_by' => null, 'user_role' => $board['user_role'], 'access_source' => $board['access_source'], 'can_manage_members' => false, 'shared_only' => true
                ];
                $workspaceIds[$workspaceId] = true;
            }
        }
        $workspaceContentManagers = [];
        foreach ($boards as $board) {
            if (in_array(strtolower((string)($board['user_role'] ?? '')), ['owner', 'designer', 'editor'], true)) {
                $workspaceContentManagers[(string)$board['workspace_id']] = true;
            }
        }
        foreach ($workspaces as &$workspace) {
            $workspace['can_manage_content'] = !empty($workspace['can_manage_members'])
                || in_array(strtolower((string)($workspace['user_role'] ?? '')), ['owner', 'editor'], true)
                || !empty($workspaceContentManagers[(string)$workspace['id']]);
        }
        unset($workspace);
        $folders = [];
        if ($workspaceIds) {
            $ids = array_keys($workspaceIds);
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $folders = $this->all(
                "SELECT id, workspace_id, parent_folder_id, name, color, sort_order, created_by
                 FROM tb_folder WHERE workspace_id IN ($marks) AND deleted_at IS NULL ORDER BY sort_order, id",
                array_map('intval', $ids)
            );
        }
        $folderById = [];
        $workspaceRoles = [];
        $workspaceReorderRoles = [];
        $workspaceVisibility = [];
        foreach ($folders as $folder) $folderById[(string)$folder['id']] = $folder;
        foreach ($workspaces as $workspace) {
            $workspaceRoles[(string)$workspace['id']] = (string)($workspace['user_role'] ?? '');
            $workspaceReorderRoles[(string)$workspace['id']] = empty($workspace['shared_only']) ? (string)($workspace['user_role'] ?? '') : '';
            $workspaceVisibility[(string)$workspace['id']] = (string)($workspace['visibility'] ?? '');
        }
        foreach ($folders as &$folder) {
            $workspaceRole = $workspaceRoles[(string)$folder['workspace_id']] ?? '';
            $isCreator = (int)($folder['created_by'] ?? 0) === $userId;
            $folder['can_trash'] = $isAdmin || $workspaceRole === 'owner' || $isCreator;
            $folder['can_archive'] = $isAdmin || in_array($workspaceRole, ['owner', 'editor'], true) || $isCreator;
            $folder['can_reorder'] = $isAdmin || $isCreator || in_array($workspaceReorderRoles[(string)$folder['workspace_id']] ?? '', ['owner', 'editor'], true);
        }
        unset($folder);
        $allowed = [];
        $markFolderPath = static function ($folder) use (&$allowed, $folderById): void {
            while ($folder) {
                $allowed[(string)$folder['id']] = true;
                $folder = $folderById[(string)($folder['parent_folder_id'] ?? '')] ?? null;
            }
        };
        foreach ($folders as $folder) {
            if ((int)($folder['created_by'] ?? 0) === $userId
                || ($workspaceRoles[(string)$folder['workspace_id']] ?? '') === 'owner'
                || ($folder['created_by'] === null && ($workspaceVisibility[(string)$folder['workspace_id']] ?? '') === 'open')) {
                $markFolderPath($folder);
            }
        }
        foreach ($boards as $board) {
            $markFolderPath($folderById[(string)($board['folder_id'] ?? '')] ?? null);
        }
        $folders = array_values(array_filter($folders, static fn($folder) => isset($allowed[(string)$folder['id']])));
        return ['workspaces' => $workspaces, 'folders' => $folders, 'boards' => $boards];
    }

    /** Creates one recoverable entry and records every row changed by its cascade. */
    public function trashEntity(int $userId, array $input, string $kind = 'trash'): array {
        $type = strtolower(trim((string)($input['entity_type'] ?? '')));
        if ($type === 'subitem') $type = 'item';
        if (!in_array($type, ['workspace', 'folder', 'board', 'group', 'column', 'item', 'file'], true)) {
            throw new TablerosApiException('validation', 'El tipo de elemento no se puede eliminar.', 422);
        }
        if (!in_array($kind, ['trash', 'archive'], true) || ($kind === 'archive' && !in_array($type, ['workspace', 'folder', 'board', 'group', 'item', 'file'], true))) {
            throw new TablerosApiException('validation', 'El tipo de elemento no se puede archivar.', 422);
        }
        $ids = $input['entity_ids'] ?? [$input['entity_id'] ?? null];
        if (!is_array($ids) || !$ids || count($ids) > 100) throw new TablerosApiException('validation', 'Selecciona entre 1 y 100 elementos.', 422);
        $ids = array_values(array_unique(array_map(fn($id) => $this->positiveInt($id, 'entity_id'), $ids)));
        if ($type !== 'item' && count($ids) !== 1) throw new TablerosApiException('validation', 'Esta acción admite un elemento a la vez.', 422);
        return $this->transaction(function () use ($ids, $userId, $type, $kind): array {
            $entries = [];
            foreach ($ids as $id) $entries[] = $this->trashOneEntity($userId, $type, $id, $kind);
            return ['entries' => $entries, 'count' => count($entries)];
        });
    }

    private function trashOneEntity(int $userId, string $type, int $id, string $kind): array {
        $config = [
            'workspace' => ['tb_workspace', 'id', 'name'], 'folder' => ['tb_folder', 'id', 'name'],
            'board' => ['tb_board', 'id', 'name'], 'group' => ['tb_group', 'id', 'name'],
            'column' => ['tb_column', 'id', 'name'], 'item' => ['tb_item', 'id', 'name'], 'file' => ['tb_file', 'id', 'name'],
        ][$type];
        return $this->transaction(function () use ($userId, $type, $id, $kind, $config): array {
            [$table, $pk, $nameCol] = $config;
            $row = $this->one("SELECT * FROM $table WITH (UPDLOCK, HOLDLOCK) WHERE $pk = ? AND deleted_at IS NULL", [$id]);
            if (!$row) throw new TablerosApiException('not_found', 'No se encontró el elemento activo.', 404);
            if ($type === 'workspace' && strtolower((string)($row['workspace_type'] ?? '')) !== 'workspace') {
                throw new TablerosApiException('not_found', 'No se encontró el espacio de trabajo activo.', 404);
            }
            $boardId = isset($row['board_id']) ? (int)$row['board_id'] : null;
            $workspaceId = isset($row['workspace_id']) ? (int)$row['workspace_id'] : null;
            if ($type === 'workspace') $workspaceId = $id;
            if ($type === 'board') $boardId = $id;
            if ($type === 'file') $boardId = (int)$row['board_id'];
            if ($type === 'group' || $type === 'column' || $type === 'item') $boardId = (int)$row['board_id'];
            if ($type === 'folder') $workspaceId = (int)$row['workspace_id'];
            if ($type === 'board') $workspaceId = (int)$row['workspace_id'];
            $location = '';
            if ($boardId) {
                $board = $this->one('SELECT name, workspace_id FROM tb_board WHERE id = ?', [$boardId]);
                $location = (string)($board['name'] ?? '');
                $workspaceId = (int)($board['workspace_id'] ?? $workspaceId);
            }
            if ($workspaceId) {
                $workspace = $this->one('SELECT name FROM tb_workspace WHERE id = ?', [$workspaceId]);
                $workspaceName = (string)($workspace['name'] ?? '');
                if ($workspaceName !== '') $location = $boardId ? $workspaceName . ' / ' . $location : $workspaceName;
            } elseif ($type === 'workspace') $location = 'Espacios de trabajo';
            if ($boardId && !$this->access->hasGlobalPermission('admin')) {
                $boardAccess = $this->access->boardAccess($boardId);
                $role = $boardAccess['role'] ?? null;
                if ($type === 'board' && $kind === 'trash' && $role === 'editor'
                    && ($boardAccess['source'] ?? '') === 'board_invitation'
                    && $this->access->workspaceRole((int)$workspaceId) !== 'owner') {
                    throw new TablerosApiException('forbidden', 'El rol Editor no puede eliminar el tablero.', 403);
                }
                $allowed = in_array($type, ['item', 'file'], true)
                    ? ['owner', 'designer', 'editor', 'contributor']
                    : ['owner', 'designer', 'editor'];
                if ($role === null || !in_array($role, $allowed, true)) throw new TablerosApiException('forbidden', 'No tienes permiso para eliminar este elemento.', 403);
            } elseif (!$boardId && !$this->access->hasGlobalPermission('admin')) {
                $workspaceRole = $this->access->workspaceRole((int)$workspaceId);
                $canManageFolderArchive = $kind === 'archive' && $type === 'folder' && $workspaceRole === 'editor';
                if ($workspaceRole !== 'owner' && !$canManageFolderArchive && (int)($row['created_by'] ?? 0) !== $userId) throw new TablerosApiException('forbidden', 'Solo el propietario del espacio o un administrador puede eliminarlo.', 403);
            }
            $entryId = $this->insertId(
                'INSERT INTO tb_trash_entry (entry_kind, entity_type, entity_id, entity_name, location, workspace_id, board_id, deleted_by, deleted_at, expires_at)
                 OUTPUT INSERTED.id VALUES (?, ?, ?, ?, ?, ?, ?, ?, SYSUTCDATETIME(), CASE WHEN ? = \'trash\' THEN DATEADD(DAY, 30, SYSUTCDATETIME()) ELSE NULL END)',
                [$kind, $type, $id, (string)$row[$nameCol], $location, $workspaceId, $boardId, $userId, $kind]
            );
            $this->markEntityBundle($entryId, $type, $id, $boardId, $workspaceId);
            $this->auditAccess($userId, 'admin.entity_trashed', $type, $id, ['kind' => $kind]);
            return ['id' => $entryId, 'entity_type' => $type, 'entity_id' => $id, 'entity_name' => (string)$row[$nameCol], 'entry_kind' => $kind];
        });
    }

    private function markEntityBundle(int $entryId, string $type, int $id, ?int $boardId, ?int $workspaceId): void {
        // Create these temp tables in the session's outer SQL scope. PDO::prepare
        // runs SQL Server batches through sp_executesql, whose temp tables are
        // dropped when that prepared statement ends and cannot be reused below.
        $this->pdo->exec("IF OBJECT_ID('tempdb..#tableros_deleted') IS NOT NULL DROP TABLE #tableros_deleted; IF OBJECT_ID('tempdb..#tableros_target') IS NOT NULL DROP TABLE #tableros_target; CREATE TABLE #tableros_deleted (table_name SYSNAME NOT NULL, entity_id BIGINT NOT NULL); CREATE TABLE #tableros_target (id BIGINT NOT NULL PRIMARY KEY)");
        if ($type === 'item') {
            $this->execute(';WITH item_tree AS (SELECT id FROM tb_item WHERE id = ? AND board_id = ? AND deleted_at IS NULL UNION ALL SELECT child.id FROM tb_item child JOIN item_tree parent ON child.parent_item_id = parent.id WHERE child.board_id = ? AND child.deleted_at IS NULL) INSERT INTO #tableros_target (id) SELECT id FROM item_tree OPTION (MAXRECURSION 100)', [$id, $boardId, $boardId]);
        } elseif ($type === 'group') {
            $this->execute(';WITH item_tree AS (SELECT id FROM tb_item WHERE group_id = ? AND board_id = ? AND deleted_at IS NULL UNION ALL SELECT child.id FROM tb_item child JOIN item_tree parent ON child.parent_item_id = parent.id WHERE child.board_id = ? AND child.deleted_at IS NULL) INSERT INTO #tableros_target (id) SELECT id FROM item_tree OPTION (MAXRECURSION 100)', [$id, $boardId, $boardId]);
        } elseif ($type === 'board') {
            $this->execute('INSERT INTO #tableros_target SELECT id FROM tb_item WHERE board_id = ? AND deleted_at IS NULL', [$boardId]);
        } elseif ($type === 'workspace') {
            $this->execute('INSERT INTO #tableros_target SELECT id FROM tb_item WHERE board_id IN (SELECT id FROM tb_board WHERE workspace_id = ?) AND deleted_at IS NULL', [$workspaceId]);
        } elseif ($type === 'folder') {
            $this->execute(';WITH folder_tree AS (SELECT id FROM tb_folder WHERE id = ? AND deleted_at IS NULL UNION ALL SELECT f.id FROM tb_folder f JOIN folder_tree p ON f.parent_folder_id = p.id WHERE f.deleted_at IS NULL) INSERT INTO #tableros_target SELECT i.id FROM tb_item i WHERE i.board_id IN (SELECT b.id FROM tb_board b WHERE b.folder_id IN (SELECT id FROM folder_tree)) AND i.deleted_at IS NULL OPTION (MAXRECURSION 100)', [$id]);
        }
        $mark = function (string $table, string $where, array $params = []) use ($entryId): void {
            $allowed = ['tb_workspace','tb_workspace_member','tb_folder','tb_board','tb_board_member','tb_group','tb_column','tb_item','tb_cell','tb_item_person','tb_comment','tb_file','tb_file_version','tb_file_comment','tb_view','tb_automation','tb_item_relation','tb_item_dependency'];
            if (!in_array($table, $allowed, true)) throw new LogicException('Invalid trash table');
            $this->execute("UPDATE $table SET deleted_at = SYSUTCDATETIME() OUTPUT '" . $table . "', INSERTED.id INTO #tableros_deleted WHERE deleted_at IS NULL AND ($where)", $params);
        };
        if ($type === 'workspace') {
            $mark('tb_workspace', 'id = ?', [$id]);
            $mark('tb_folder', 'workspace_id = ?', [$id]);
            $mark('tb_board', 'workspace_id = ?', [$id]);
            $mark('tb_group', 'board_id IN (SELECT id FROM tb_board WHERE workspace_id = ?)', [$id]);
            $mark('tb_column', 'board_id IN (SELECT id FROM tb_board WHERE workspace_id = ?)', [$id]);
            $mark('tb_item', 'board_id IN (SELECT id FROM tb_board WHERE workspace_id = ?)', [$id]);
            $mark('tb_cell', 'board_id IN (SELECT id FROM tb_board WHERE workspace_id = ?)', [$id]);
            $mark('tb_item_person', 'board_id IN (SELECT id FROM tb_board WHERE workspace_id = ?)', [$id]);
            $mark('tb_comment', 'board_id IN (SELECT id FROM tb_board WHERE workspace_id = ?)', [$id]);
            $mark('tb_file', 'board_id IN (SELECT id FROM tb_board WHERE workspace_id = ?)', [$id]);
            $mark('tb_file_version', 'file_id IN (SELECT id FROM tb_file WHERE board_id IN (SELECT id FROM tb_board WHERE workspace_id = ?))', [$id]);
            $mark('tb_file_comment', 'file_id IN (SELECT id FROM tb_file WHERE board_id IN (SELECT id FROM tb_board WHERE workspace_id = ?))', [$id]);
            $mark('tb_view', 'board_id IN (SELECT id FROM tb_board WHERE workspace_id = ?)', [$id]);
            $mark('tb_automation', 'board_id IN (SELECT id FROM tb_board WHERE workspace_id = ?)', [$id]);
            $mark('tb_item_relation', 'source_board_id IN (SELECT id FROM tb_board WHERE workspace_id = ?) OR target_board_id IN (SELECT id FROM tb_board WHERE workspace_id = ?)', [$id, $id]);
            $mark('tb_item_dependency', 'board_id IN (SELECT id FROM tb_board WHERE workspace_id = ?)', [$id]);
        } elseif ($type === 'folder') {
            $this->execute(';WITH folder_tree AS (SELECT id FROM tb_folder WHERE id = ? AND deleted_at IS NULL UNION ALL SELECT child.id FROM tb_folder child JOIN folder_tree parent ON child.parent_folder_id = parent.id WHERE child.deleted_at IS NULL) UPDATE f SET deleted_at = SYSUTCDATETIME() OUTPUT \'tb_folder\', INSERTED.id INTO #tableros_deleted FROM tb_folder f JOIN folder_tree t ON t.id = f.id WHERE f.deleted_at IS NULL OPTION (MAXRECURSION 100)', [$id]);
            $this->execute('UPDATE b SET deleted_at = SYSUTCDATETIME() OUTPUT \'tb_board\', INSERTED.id INTO #tableros_deleted FROM tb_board b WHERE b.folder_id IN (SELECT entity_id FROM #tableros_deleted WHERE table_name = \'tb_folder\') AND b.deleted_at IS NULL');
            foreach (['tb_group','tb_column','tb_item','tb_cell','tb_item_person','tb_comment','tb_file','tb_view','tb_automation','tb_item_dependency'] as $table) $mark($table, 'board_id IN (SELECT entity_id FROM #tableros_deleted WHERE table_name = \'tb_board\')');
            $mark('tb_file_version', 'file_id IN (SELECT entity_id FROM #tableros_deleted WHERE table_name = \'tb_file\')');
            $mark('tb_file_comment', 'file_id IN (SELECT entity_id FROM #tableros_deleted WHERE table_name = \'tb_file\')');
            $mark('tb_item_relation', 'source_board_id IN (SELECT entity_id FROM #tableros_deleted WHERE table_name = \'tb_board\') OR target_board_id IN (SELECT entity_id FROM #tableros_deleted WHERE table_name = \'tb_board\')');
        } elseif ($type === 'board') {
            $mark('tb_board', 'id = ?', [$id]);
            foreach (['tb_group','tb_column','tb_item','tb_cell','tb_item_person','tb_comment','tb_file','tb_view','tb_automation','tb_item_dependency'] as $table) $mark($table, 'board_id = ?', [$id]);
            $mark('tb_file_version', 'file_id IN (SELECT id FROM tb_file WHERE board_id = ?)', [$id]);
            $mark('tb_file_comment', 'file_id IN (SELECT id FROM tb_file WHERE board_id = ?)', [$id]);
            $mark('tb_item_relation', 'source_board_id = ? OR target_board_id = ?', [$id, $id]);
        } elseif ($type === 'group') {
            $mark('tb_group', 'id = ? AND board_id = ?', [$id, $boardId]);
            $mark('tb_item', 'id IN (SELECT id FROM #tableros_target)');
            $mark('tb_cell', 'board_id = ? AND item_id IN (SELECT id FROM #tableros_target)', [$boardId]);
            $mark('tb_item_person', 'board_id = ? AND item_id IN (SELECT id FROM #tableros_target)', [$boardId]);
            $mark('tb_comment', 'board_id = ? AND item_id IN (SELECT id FROM #tableros_target)', [$boardId]);
            $mark('tb_file', 'board_id = ? AND item_id IN (SELECT id FROM #tableros_target)', [$boardId]);
            $mark('tb_file_version', 'file_id IN (SELECT entity_id FROM #tableros_deleted WHERE table_name = \'tb_file\')');
            $mark('tb_file_comment', 'file_id IN (SELECT entity_id FROM #tableros_deleted WHERE table_name = \'tb_file\')');
            $mark('tb_item_dependency', 'board_id = ? AND (predecessor_item_id IN (SELECT id FROM #tableros_target) OR successor_item_id IN (SELECT id FROM #tableros_target))', [$boardId]);
            $mark('tb_item_relation', 'source_item_id IN (SELECT id FROM #tableros_target) OR target_item_id IN (SELECT id FROM #tableros_target)');
        } elseif ($type === 'column') {
            $mark('tb_column', 'id = ? AND board_id = ?', [$id, $boardId]);
            $mark('tb_cell', 'column_id = ? AND board_id = ?', [$id, $boardId]);
            $mark('tb_item_person', 'column_id = ? AND board_id = ?', [$id, $boardId]);
            $mark('tb_item_relation', 'column_id = ? AND source_board_id = ?', [$id, $boardId]);
            $mark('tb_item_dependency', 'column_id = ? AND board_id = ?', [$id, $boardId]);
            $mark('tb_file', 'column_id = ? AND board_id = ?', [$id, $boardId]);
            $mark('tb_file_version', 'file_id IN (SELECT id FROM tb_file WHERE column_id = ? AND board_id = ?)', [$id, $boardId]);
            $mark('tb_file_comment', 'file_id IN (SELECT id FROM tb_file WHERE column_id = ? AND board_id = ?)', [$id, $boardId]);
        } elseif ($type === 'item') {
            $mark('tb_item', 'id IN (SELECT id FROM #tableros_target)');
            foreach (['tb_cell','tb_item_person','tb_comment','tb_file'] as $table) $mark($table, 'board_id = ? AND item_id IN (SELECT id FROM #tableros_target)', [$boardId]);
            $mark('tb_file_version', 'file_id IN (SELECT entity_id FROM #tableros_deleted WHERE table_name = \'tb_file\')');
            $mark('tb_file_comment', 'file_id IN (SELECT entity_id FROM #tableros_deleted WHERE table_name = \'tb_file\')');
            $mark('tb_item_dependency', 'board_id = ? AND (predecessor_item_id IN (SELECT id FROM #tableros_target) OR successor_item_id IN (SELECT id FROM #tableros_target))', [$boardId]);
            $mark('tb_item_relation', 'source_item_id IN (SELECT id FROM #tableros_target) OR target_item_id IN (SELECT id FROM #tableros_target)');
        } elseif ($type === 'file') {
            $mark('tb_file', 'id = ? AND board_id = ?', [$id, $boardId]);
            $mark('tb_file_version', 'file_id = ?', [$id]);
            $mark('tb_file_comment', 'file_id = ?', [$id]);
        }
        $this->execute('INSERT INTO tb_trash_entity (entry_id, table_name, entity_id) SELECT ?, table_name, entity_id FROM #tableros_deleted', [$entryId]);
        $this->pdo->exec('DROP TABLE #tableros_target; DROP TABLE #tableros_deleted');
        // Preserve physical file objects until permanent deletion; the manifest includes versions for the purge worker.
    }

    public function getTrashEntries(int $userId, bool $isAdmin, ?int $workspaceId = null, string $kind = 'trash'): array {
        $sql = "SELECT t.id, t.entry_kind, t.entity_type, t.entity_id, t.entity_name, t.location, t.workspace_id, t.board_id, t.deleted_by, t.deleted_at, t.expires_at,
                       COALESCE(NULLIF(LTRIM(RTRIM(u.Nombre)), ''), NULLIF(LTRIM(RTRIM(u.Usuario)), ''), CONVERT(NVARCHAR(20), t.deleted_by)) AS deleted_by_name
                FROM tb_trash_entry t LEFT JOIN [TG].[dbo].[Usuario] u ON u.Id = t.deleted_by
                WHERE t.entry_kind = ? AND t.restored_at IS NULL AND (? = 1 OR t.deleted_by = ?)
                  AND (t.entry_kind <> 'trash' OR t.purge_requested_at IS NULL)";
        $params = [$kind, ($isAdmin || $kind === 'archive') ? 1 : 0, $userId];
        if ($workspaceId !== null) { $sql .= ' AND t.workspace_id = ?'; $params[] = $workspaceId; }
        $sql .= ' ORDER BY t.deleted_at DESC, t.id DESC';
        $entries = $this->all($sql, $params);
        if ($kind === 'archive' && !$isAdmin) $entries = array_values(array_filter($entries, fn(array $entry): bool => $this->canEditTrashEntry($entry, $userId)));
        return $entries;
    }

    public function restoreTrashEntry(int $userId, bool $isAdmin, int $entryId, string $kind = 'trash'): array {
        return $this->transaction(function () use ($userId, $isAdmin, $entryId, $kind): array {
            // Serialize restoration with the worker's purge claim. Once claimed,
            // physical object deletion may already have started and restoration is unsafe.
            $this->one('SELECT id FROM tb_trash_entry WITH (UPDLOCK, HOLDLOCK) WHERE id = ?', [$entryId]);
            $entry = $this->trashEntryForActor($entryId, $userId, $isAdmin, $kind);
            if ($kind === 'trash' && $entry['purge_started_at'] !== null) throw new TablerosApiException('conflict', 'La eliminación permanente ya comenzó y no se puede restaurar.', 409);
            if ($kind === 'trash' && strtotime((string)$entry['expires_at']) <= time()) throw new TablerosApiException('conflict', 'El periodo de recuperación de 30 días terminó.', 409);
            $entities = $this->all('SELECT table_name, entity_id FROM tb_trash_entity WHERE entry_id = ?', [$entryId]);
            $allowed = ['tb_workspace','tb_workspace_member','tb_folder','tb_board','tb_board_member','tb_group','tb_column','tb_item','tb_cell','tb_item_person','tb_comment','tb_file','tb_file_version','tb_file_comment','tb_view','tb_automation','tb_item_relation','tb_item_dependency'];
            foreach ($entities as $entity) {
                $table = (string)$entity['table_name'];
                if (!in_array($table, $allowed, true)) continue;
                $this->execute("UPDATE $table SET deleted_at = NULL WHERE id = ? AND deleted_at IS NOT NULL", [(int)$entity['entity_id']]);
            }
            $this->execute('UPDATE tb_trash_entry SET restored_at = SYSUTCDATETIME() WHERE id = ?', [$entryId]);
            $this->auditAccess($userId, 'admin.entity_restored', (string)$entry['entity_type'], (int)$entry['entity_id'], ['kind' => $kind]);
            return ['entry_id' => $entryId, 'restored' => true];
        });
    }

    public function requestPermanentTrashDelete(int $userId, bool $isAdmin, int $entryId): array {
        $entry = $this->trashEntryForActor($entryId, $userId, $isAdmin, 'trash');
        $this->execute('UPDATE tb_trash_entry SET purge_requested_at = COALESCE(purge_requested_at, SYSUTCDATETIME()) WHERE id = ?', [$entryId]);
        $this->auditAccess($userId, 'admin.purge_requested', (string)$entry['entity_type'], (int)$entry['entity_id']);
        return ['entry_id' => $entryId, 'purge_queued' => true];
    }

    /** Enqueue expired trash, remove stored objects with retries, then hard-delete its relational bundle. */
    public function purgeTrash(int $limit = 50): array {
        $limit = max(1, min(200, $limit));
        $entries = $this->all(";WITH candidates AS (
                                   SELECT TOP ($limit) id FROM tb_trash_entry WITH (UPDLOCK, READPAST, ROWLOCK)
                                   WHERE entry_kind = 'trash' AND restored_at IS NULL
                                     AND (purge_requested_at IS NOT NULL OR expires_at <= SYSUTCDATETIME())
                                   ORDER BY COALESCE(purge_requested_at, expires_at), id
                               )
                               UPDATE t SET purge_started_at = COALESCE(t.purge_started_at, SYSUTCDATETIME())
                               OUTPUT INSERTED.id
                               FROM tb_trash_entry t JOIN candidates c ON c.id = t.id");
        $result = ['entries_purged' => 0, 'files_removed' => 0, 'failures' => 0];
        foreach ($entries as $entryRow) {
            $entryId = (int)$entryRow['id'];
            try {
                $this->transaction(function () use ($entryId): void {
                    $this->execute("INSERT INTO tb_storage_cleanup (entry_id, storage_provider, storage_container, storage_object_key)
                        SELECT ?, v.storage_provider, v.storage_container, v.storage_object_key
                        FROM tb_trash_entity e JOIN tb_file_version v ON e.table_name = 'tb_file_version' AND v.id = e.entity_id
                        WHERE e.entry_id = ? AND NOT EXISTS (SELECT 1 FROM tb_storage_cleanup c WHERE c.entry_id = ? AND c.storage_provider = v.storage_provider AND c.storage_container = v.storage_container AND c.storage_object_key = v.storage_object_key)", [$entryId, $entryId, $entryId]);
                });
                $jobs = $this->all('SELECT id, storage_provider, storage_container, storage_object_key FROM tb_storage_cleanup WHERE entry_id = ? AND completed_at IS NULL ORDER BY id', [$entryId]);
                foreach ($jobs as $job) {
                    try {
                        $this->removeStoredObject((string)$job['storage_provider'], (string)$job['storage_container'], (string)$job['storage_object_key']);
                        $this->execute('UPDATE tb_storage_cleanup SET completed_at = SYSUTCDATETIME(), last_error = NULL WHERE id = ?', [(int)$job['id']]);
                        $result['files_removed']++;
                    } catch (Throwable $e) {
                        $message = substr($e->getMessage(), 0, 900);
                        $this->execute('UPDATE tb_storage_cleanup SET attempts = attempts + 1, last_error = ? WHERE id = ?', [$message, (int)$job['id']]);
                        $result['failures']++;
                    }
                }
                $pending = (int)($this->one('SELECT COUNT(*) AS total FROM tb_storage_cleanup WHERE entry_id = ? AND completed_at IS NULL', [$entryId])['total'] ?? 0);
                if ($pending > 0) continue;
                $this->hardDeleteTrashBundle($entryId);
                $result['entries_purged']++;
            } catch (Throwable $e) {
                error_log('Tableros trash purge failed for entry ' . $entryId . ': ' . $e->getMessage());
                $result['failures']++;
            }
        }
        return $result;
    }

    private function removeStoredObject(string $provider, string $container, string $key): void {
        if ($container !== 'tableros' || !preg_match('~^(?:[1-9][0-9]*/[1-9][0-9]*/[0-9]{4}/(?:0[1-9]|1[0-2])/[a-f0-9]{2}/[a-f0-9]{64}|stickers/[1-9][0-9]*/[0-9]{4}/(?:0[1-9]|1[0-2])/[a-f0-9]{2}/[a-f0-9]{64}|[a-f0-9]{64})$~', $key)) {
            throw new RuntimeException('Invalid stored object reference');
        }
        if ($provider === 'nas_service') {
            try { $this->nasServiceRequest('DELETE', $key); }
            catch (TablerosApiException $e) { if ($e->apiCode !== 'not_found') throw $e; }
            $this->removeThumbnailForOriginal($provider, $key);
            return;
        }
        if ($provider !== 'local') throw new RuntimeException('Unknown storage provider');
        $root = $this->privateStorageRoot();
        $relative = preg_match('/^[a-f0-9]{64}$/', $key) ? substr($key, 0, 2) . DIRECTORY_SEPARATOR . $key : str_replace('/', DIRECTORY_SEPARATOR, $key);
        $path = $root . DIRECTORY_SEPARATOR . $relative;
        $realPath = realpath($path);
        if ($realPath === false && preg_match('/^[a-f0-9]{64}$/', $key)) {
            $previousRoot = dirname(dirname(__DIR__, 2)) . DIRECTORY_SEPARATOR . 'tableros-private';
            $previousPath = $previousRoot . DIRECTORY_SEPARATOR . substr($key, 0, 2) . DIRECTORY_SEPARATOR . $key;
            $previousRealRoot = realpath($previousRoot);
            $previousRealPath = realpath($previousPath);
            if ($previousRealRoot !== false && $previousRealPath !== false && $this->pathIsWithin($previousRealPath, $previousRealRoot)
                && is_file($previousRealPath) && @unlink($previousRealPath)) {
                $this->removeThumbnailForOriginal($provider, $key);
                return;
            }
        }
        if ($realPath !== false && (!$this->pathIsWithin($realPath, $root) || !is_file($realPath) || !@unlink($realPath))) throw new RuntimeException('Could not remove local object');
        $this->removeThumbnailForOriginal($provider, $key);
    }

    private function removeThumbnailForOriginal(string $provider, string $key): void {
        if (str_starts_with($key, 'stickers/')) return;
        foreach ([$this->thumbnailObjectKey($key), $this->imagePreviewObjectKey($key)] as $derivativeKey) {
            try {
                $this->deleteStoredObject($derivativeKey, $provider === 'nas_service');
            } catch (TablerosApiException $e) {
                if ($e->apiCode !== 'not_found') throw $e;
            }
        }
    }

    private function hardDeleteTrashBundle(int $entryId): void {
        $this->transaction(function () use ($entryId): void {
            $state = $this->one('SELECT restored_at, purge_started_at FROM tb_trash_entry WITH (UPDLOCK, HOLDLOCK) WHERE id = ?', [$entryId]);
            if (!$state || $state['restored_at'] !== null || $state['purge_started_at'] === null) return;
            $rows = $this->all('SELECT table_name, entity_id FROM tb_trash_entity WHERE entry_id = ?', [$entryId]);
            $byTable = [];
            foreach ($rows as $row) $byTable[(string)$row['table_name']][] = (int)$row['entity_id'];
            $ids = static fn(string $table): array => array_values(array_unique($byTable[$table] ?? []));
            $deleteIds = function (string $table, string $column, array $values): void {
                foreach (array_chunk(array_values(array_unique($values)), 500) as $chunk) {
                    if (!$chunk) continue;
                    $marks = implode(',', array_fill(0, count($chunk), '?'));
                    $this->execute("DELETE FROM $table WHERE $column IN ($marks)", $chunk);
                }
            };
            $fileIds = $ids('tb_file'); $fileCommentIds = $ids('tb_file_comment'); $commentIds = $ids('tb_comment'); $itemIds = $ids('tb_item');
            $boardIds = $ids('tb_board'); $workspaceIds = $ids('tb_workspace');
            $deleteIds('tb_file_comment_sticker', 'file_comment_id', $fileCommentIds);
            $deleteIds('tb_file_comment_attachment', 'file_comment_id', $fileCommentIds);
            $deleteIds('tb_file_comment_attachment', 'file_id', $fileIds);
            $deleteIds('tb_comment_sticker', 'comment_id', $commentIds);
            $deleteIds('tb_comment_attachment', 'comment_id', $commentIds);
            $deleteIds('tb_comment_attachment', 'file_id', $fileIds);
            $deleteIds('tb_notification', 'file_comment_id', $fileCommentIds);
            $deleteIds('tb_notification', 'comment_id', $commentIds);
            $deleteIds('tb_notification', 'file_id', $fileIds);
            $deleteIds('tb_notification', 'item_id', $itemIds);
            $deleteIds('tb_notification', 'board_id', $boardIds);
            $deleteIds('tb_automation_run', 'automation_id', $ids('tb_automation'));
            $deleteIds('tb_item_relation', 'id', $ids('tb_item_relation'));
            $deleteIds('tb_item_dependency', 'id', $ids('tb_item_dependency'));
            $deleteIds('tb_activity', 'item_id', $itemIds);
            $deleteIds('tb_activity', 'board_id', $boardIds);
            $deleteIds('tb_activity', 'workspace_id', $workspaceIds);
            $deleteIds('tb_cell', 'id', $ids('tb_cell'));
            $deleteIds('tb_item_person', 'id', $ids('tb_item_person'));
            $deleteIds('tb_file_comment', 'id', $fileCommentIds);
            $deleteIds('tb_comment', 'id', $commentIds);
            if ($fileIds) $this->execute('UPDATE tb_file SET current_version_id = NULL WHERE id IN (' . implode(',', array_fill(0, count($fileIds), '?')) . ')', $fileIds);
            $deleteIds('tb_file_version', 'id', $ids('tb_file_version'));
            $deleteIds('tb_file', 'id', $fileIds);
            $deleteIds('tb_view', 'id', $ids('tb_view'));
            $deleteIds('tb_automation', 'id', $ids('tb_automation'));
            $deleteIds('tb_item', 'id', $itemIds);
            $deleteIds('tb_group', 'id', $ids('tb_group'));
            $deleteIds('tb_column', 'id', $ids('tb_column'));
            $deleteIds('tb_board_member', 'id', $ids('tb_board_member'));
            $deleteIds('tb_workspace_member', 'id', $ids('tb_workspace_member'));
            $deleteIds('tb_board_member', 'board_id', $boardIds);
            $deleteIds('tb_workspace_member', 'workspace_id', $workspaceIds);
            $deleteIds('tb_board', 'id', $boardIds);
            $deleteIds('tb_folder', 'id', $ids('tb_folder'));
            $deleteIds('tb_workspace', 'id', $workspaceIds);
            $this->execute('DELETE FROM tb_trash_entity WHERE entry_id = ?', [$entryId]);
            $this->execute('DELETE FROM tb_storage_cleanup WHERE entry_id = ?', [$entryId]);
            $this->execute('DELETE FROM tb_trash_entry WHERE id = ?', [$entryId]);
        });
    }

    private function trashEntryForActor(int $entryId, int $userId, bool $isAdmin, string $kind): array {
        $entry = $this->one('SELECT * FROM tb_trash_entry WHERE id = ? AND entry_kind = ? AND restored_at IS NULL', [$entryId, $kind]);
        if (!$entry || $entry['purge_requested_at'] !== null) throw new TablerosApiException('not_found', 'No se encontró el elemento en esta sección.', 404);
        if (!$isAdmin && $kind === 'trash' && (int)$entry['deleted_by'] !== $userId) throw new TablerosApiException('forbidden', 'Solo quien eliminó el elemento o un administrador puede realizar esta acción.', 403);
        if (!$isAdmin && !$this->canEditTrashEntry($entry, $userId)) throw new TablerosApiException('forbidden', 'Necesitas permisos de edición vigentes para restaurar este elemento.', 403);
        return $entry;
    }

    private function canEditTrashEntry(array $entry, int $userId): bool {
        if ($entry['board_id'] !== null) {
            $row = $this->one('SELECT b.created_by, b.workspace_id, b.visibility, m.role AS board_role, wm.role AS workspace_role, w.visibility AS workspace_visibility
                FROM tb_board b LEFT JOIN tb_board_member m ON m.board_id = b.id AND m.user_id = ? AND m.deleted_at IS NULL
                LEFT JOIN tb_workspace_member wm ON wm.workspace_id = b.workspace_id AND wm.user_id = ? AND wm.deleted_at IS NULL
                LEFT JOIN tb_workspace w ON w.id = b.workspace_id WHERE b.id = ?', [$userId, $userId, (int)$entry['board_id']]);
            if (!$row) return false;
            if ((int)($row['created_by'] ?? 0) === $userId) return true;
            if ($entry['entity_type'] === 'board') {
                // Match the current delete rule: board owners/designers/editors
                // may restore boards they deleted, and workspace owners/editors
                // inherit that ability only when the board inherits workspace access.
                if (in_array(strtolower((string)($row['board_role'] ?? '')), ['owner', 'designer', 'editor'], true)) return true;
                return in_array(strtolower((string)($row['workspace_role'] ?? '')), ['owner', 'editor'], true)
                    && strtolower((string)$row['visibility']) === 'workspace';
            }
            if (in_array(strtolower((string)($row['board_role'] ?? '')), ['owner','designer','editor','contributor'], true)) return true;
            return in_array(strtolower((string)($row['workspace_role'] ?? '')), ['owner','editor'], true)
                && strtolower((string)$row['visibility']) === 'workspace';
        }
        $workspaceId = (int)($entry['workspace_id'] ?? 0);
        if ($workspaceId <= 0) return false;
        $member = $this->one("SELECT w.created_by, wm.role FROM tb_workspace w LEFT JOIN tb_workspace_member wm ON wm.workspace_id = w.id AND wm.user_id = ? AND wm.deleted_at IS NULL WHERE w.id = ? AND w.workspace_type = 'workspace'", [$userId, $workspaceId]);
        if (!$member) return false;
        if ((int)($member['created_by'] ?? 0) === $userId) return true;
        if ($entry['entity_type'] === 'workspace') {
            if (strtolower((string)($member['role'] ?? '')) === 'owner') return true;
            // Workspace cascades soft-delete membership rows. Consult the saved
            // manifest so only an owner at archive time can still see/restore it.
            return (bool)$this->one(
                "SELECT TOP (1) e.entity_id FROM tb_trash_entity e
                 INNER JOIN tb_workspace_member wm ON wm.id = e.entity_id
                 WHERE e.entry_id = ? AND e.table_name = 'tb_workspace_member'
                   AND wm.workspace_id = ? AND wm.user_id = ? AND wm.role = 'owner'",
                [(int)$entry['id'], $workspaceId, $userId]
            );
        }
        if (in_array(strtolower((string)($member['role'] ?? '')), ['owner','editor'], true)) return true;
        if ($entry['entity_type'] === 'folder') {
            $folder = $this->one('SELECT created_by FROM tb_folder WHERE id = ?', [(int)$entry['entity_id']]);
            return (int)($folder['created_by'] ?? 0) === $userId;
        }
        return false;
    }

    public function createWorkspace(int $userId, array $input): array {
        if (!$this->access->hasGlobalPermission('create')) throw new TablerosApiException('forbidden', 'No tienes permiso para crear espacios de trabajo.', 403);
        if (!$this->userIsActive($userId)) {
            throw new TablerosApiException('forbidden', 'La cuenta TG no está activa.', 403);
        }
        $name = $this->requiredString($input, 'name', 200);
        $description = $this->optionalString($input, 'description', 1000);
        $visibility = strtolower(trim((string)($input['visibility'] ?? 'closed')));
        if (!in_array($visibility, ['open', 'closed'], true)) {
            throw new TablerosApiException('validation', 'La visibilidad del espacio no es válida.', 422);
        }
        return $this->transaction(function () use ($userId, $name, $description, $visibility): array {
            $key = bin2hex(random_bytes(20));
            $id = $this->insertId(
                'INSERT INTO tb_workspace (workspace_key, workspace_type, name, description, visibility, created_by, created_at, updated_at)
                 OUTPUT INSERTED.id VALUES (?, ?, ?, ?, ?, ?, GETDATE(), GETDATE())',
                [$key, 'workspace', $name, $description, $visibility, $userId]
            );
            $this->execute(
                'INSERT INTO tb_workspace_member (workspace_id, user_id, role, invited_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, GETDATE(), GETDATE())',
                [$id, $userId, 'owner', $userId]
            );
            return ['id' => $id, 'name' => $name, 'description' => $description, 'visibility' => $visibility, 'user_role' => 'owner'];
        });
    }

    public function createFolder(int $userId, array $input): array {
        if (!$this->access->hasGlobalPermission('create') && !$this->access->canManageWorkspaceContent((int)($input['workspace_id'] ?? 0))) throw new TablerosApiException('forbidden', 'No tienes permiso para crear carpetas.', 403);
        if (!$this->userIsActive($userId)) {
            throw new TablerosApiException('forbidden', 'La cuenta TG no está activa.', 403);
        }
        $workspaceId = $this->optionalPositiveInt($input['workspace_id'] ?? null, 'workspace_id');
        if ($workspaceId === null) {
            $defaultWorkspace = $this->one("SELECT id FROM tb_workspace WHERE workspace_key = 'general' AND deleted_at IS NULL");
            $workspaceId = $defaultWorkspace ? (int)$defaultWorkspace['id'] : 0;
        }
        if ($workspaceId <= 0) throw new TablerosApiException('validation', 'Selecciona un espacio de trabajo.', 422);
        if ($this->access->workspaceRole($workspaceId) === null && !$this->access->canManageWorkspaceContent($workspaceId)) throw new TablerosApiException('forbidden', 'No tienes acceso al espacio de trabajo seleccionado.', 403);
        $parentFolderId = $this->optionalPositiveInt($input['parent_folder_id'] ?? null, 'parent_folder_id');
        $name = $this->requiredString($input, 'name', 200);
        $color = $this->optionalString($input, 'color', 32);
        if ($color !== null && !preg_match('/^#[0-9a-f]{3,8}$/i', $color)) {
            throw new TablerosApiException('validation', 'El color de la carpeta no es válido.', 422);
        }
        if (!$this->one("SELECT id FROM tb_workspace WHERE id = ? AND workspace_type = 'workspace' AND deleted_at IS NULL", [$workspaceId])) {
            throw new TablerosApiException('not_found', 'No se encontró el espacio de trabajo.', 404);
        }
        if ($parentFolderId !== null) {
            $parent = $this->one('SELECT id, parent_folder_id, created_by FROM tb_folder WHERE id = ? AND workspace_id = ? AND deleted_at IS NULL', [$parentFolderId, $workspaceId]);
            if (!$parent) {
                throw new TablerosApiException('validation', 'La carpeta seleccionada no pertenece a este espacio.', 422);
            }
            $workspaceRole = $this->access->workspaceRole($workspaceId);
            $systemFolderVisible = $parent['created_by'] === null && $workspaceRole !== null;
            if ((int)($parent['created_by'] ?? 0) !== $userId && $workspaceRole !== 'owner' && !$systemFolderVisible
                && !$this->folderHasVisibleBoard($parentFolderId, $workspaceId)
                && !$this->access->canManageWorkspaceContent($workspaceId)) {
                throw new TablerosApiException('forbidden', 'Solo puedes crear una subcarpeta en una carpeta que puedes ver.', 403);
            }
            $depth = 1;
            $cursor = $parent;
            while ($cursor && $cursor['parent_folder_id'] !== null) {
                $cursor = $this->one('SELECT id, parent_folder_id FROM tb_folder WHERE id = ? AND workspace_id = ? AND deleted_at IS NULL', [(int)$cursor['parent_folder_id'], $workspaceId]);
                $depth++;
            }
            if ($depth >= 3) {
                throw new TablerosApiException('validation', 'Monday permite hasta tres niveles de carpetas dentro del espacio de trabajo.', 422);
            }
        }
        $position = (int)($this->one('SELECT COALESCE(MAX(sort_order), -1) + 1 AS next_order FROM tb_folder WHERE workspace_id = ? AND ((parent_folder_id = ?) OR (parent_folder_id IS NULL AND ? IS NULL)) AND deleted_at IS NULL', [$workspaceId, $parentFolderId, $parentFolderId])['next_order'] ?? 0);
        $id = $this->insertId(
            'INSERT INTO tb_folder (workspace_id, parent_folder_id, folder_key, name, color, sort_order, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, GETDATE(), GETDATE());
             SELECT CONVERT(INT, SCOPE_IDENTITY()) AS id',
            [$workspaceId, $parentFolderId, bin2hex(random_bytes(20)), $name, $color, $position, $userId]
        );
        return ['id' => $id, 'workspace_id' => $workspaceId, 'parent_folder_id' => $parentFolderId, 'name' => $name, 'color' => $color, 'sort_order' => $position];
    }

    /** Reorder visible siblings only; hidden entities retain their stored order. */
    public function reorderStructure(int $userId, bool $isAdmin, array $input): array {
        $type = $input['entity_type'] ?? null;
        if (!in_array($type, ['folder', 'board'], true)) {
            throw new TablerosApiException('validation', 'Selecciona una carpeta o un tablero.', 422);
        }
        $id = $this->positiveInt($input['entity_id'] ?? null, 'entity_id');
        if (array_key_exists('target_parent_id', $input)) {
            return $this->moveStructureEntity($userId, $isAdmin, $type, $id, $input);
        }
        $anchorId = $this->positiveInt($input['anchor_id'] ?? null, 'anchor_id');
        $placement = $input['placement'] ?? null;
        if ($id === $anchorId || !in_array($placement, ['before', 'after'], true)) {
            throw new TablerosApiException('validation', 'La posición solicitada no es válida.', 422);
        }
        return $this->transaction(function () use ($userId, $isAdmin, $type, $id, $anchorId, $placement): array {
            $table = $type === 'folder' ? 'tb_folder' : 'tb_board';
            $parentKey = $type === 'folder' ? 'parent_folder_id' : 'folder_id';
            $collection = $type === 'folder' ? 'folders' : 'boards';
            $structure = $this->getWorkspaceStructure($userId, $isAdmin);
            $visible = array_column($structure[$collection], null, 'id');
            $moved = $visible[$id] ?? null;
            $anchor = $visible[$anchorId] ?? null;
            if (!$moved || !$anchor) {
                throw new TablerosApiException('not_found', 'No se encontró la carpeta o el tablero visible.', 404);
            }
            if (empty($moved['can_reorder']) || empty($anchor['can_reorder'])) {
                throw new TablerosApiException('forbidden', 'No tienes permiso para cambiar este orden.', 403);
            }
            if ((int)$moved['workspace_id'] !== (int)$anchor['workspace_id']
                || ($moved[$parentKey] === null ? null : (int)$moved[$parentKey]) !== ($anchor[$parentKey] === null ? null : (int)$anchor[$parentKey])) {
                throw new TablerosApiException('validation', 'Solo puedes reordenar elementos dentro de la misma carpeta y espacio.', 422);
            }
            $workspaceId = (int)$moved['workspace_id'];
            $parentId = $moved[$parentKey] === null ? null : (int)$moved[$parentKey];
            $siblings = $this->all(
                "SELECT id, sort_order FROM $table WITH (UPDLOCK, HOLDLOCK)
                 WHERE workspace_id = ? AND (($parentKey = ?) OR ($parentKey IS NULL AND ? IS NULL))
                   AND deleted_at IS NULL ORDER BY sort_order, id",
                [$workspaceId, $parentId, $parentId]
            );
            foreach ($siblings as $sibling) {
                $visibleSibling = $visible[(int)$sibling['id']] ?? null;
                if ($visibleSibling && empty($visibleSibling['can_reorder'])) {
                    throw new TablerosApiException('forbidden', 'Necesitas permiso para reordenar todos los elementos visibles de esta carpeta.', 403);
                }
            }
            $siblings = array_values(array_filter($siblings, static fn(array $row): bool => isset($visible[(int)$row['id']])));
            $order = array_map(static fn(array $row): int => (int)$row['id'], $siblings);
            if (!in_array($id, $order, true) || !in_array($anchorId, $order, true)) {
                throw new TablerosApiException('conflict', 'La estructura cambió. Actualiza e inténtalo de nuevo.', 409);
            }
            $order = array_values(array_filter($order, static fn(int $siblingId): bool => $siblingId !== $id));
            $anchorIndex = array_search($anchorId, $order, true);
            array_splice($order, $anchorIndex + ($placement === 'after' ? 1 : 0), 0, [$id]);
            $previousPosition = null;
            foreach ($order as $index => $siblingId) {
                // Preserve existing visible slots where possible, making tied legacy slots distinct.
                $position = max((int)$siblings[$index]['sort_order'], $previousPosition === null ? PHP_INT_MIN : $previousPosition + 1);
                if ($position > 2147483647) {
                    throw new TablerosApiException('conflict', 'El orden excede el rango permitido.', 409);
                }
                $this->execute(
                    "UPDATE $table SET sort_order = ?, updated_at = GETDATE()
                     WHERE id = ? AND workspace_id = ? AND deleted_at IS NULL",
                    [$position, $siblingId, $workspaceId]
                );
                $previousPosition = $position;
            }
            if ($type === 'board') {
                $this->writeActivity($id, null, $userId, 'board.reordered', ['anchor_id' => $anchorId, 'placement' => $placement]);
            }
            return $this->getWorkspaceStructure($userId, $isAdmin);
        });
    }

    /** Move an entity between folder/root lists, or within one using an optional anchor. */
    private function moveStructureEntity(int $userId, bool $isAdmin, string $type, int $id, array $input): array {
        $targetParentId = $this->optionalPositiveInt($input['target_parent_id'], 'target_parent_id');
        $anchorId = $this->optionalPositiveInt($input['anchor_id'] ?? null, 'anchor_id');
        $placement = $input['placement'] ?? 'after';
        if (!in_array($placement, ['before', 'after'], true) || ($anchorId !== null && $anchorId === $id)) {
            throw new TablerosApiException('validation', 'La posición solicitada no es válida.', 422);
        }
        return $this->transaction(function () use ($userId, $isAdmin, $type, $id, $targetParentId, $anchorId, $placement): array {
            $table = $type === 'folder' ? 'tb_folder' : 'tb_board';
            $parentKey = $type === 'folder' ? 'parent_folder_id' : 'folder_id';
            $collection = $type === 'folder' ? 'folders' : 'boards';
            $structure = $this->getWorkspaceStructure($userId, $isAdmin);
            $visible = array_column($structure[$collection], null, 'id');
            $visibleFolders = array_column($structure['folders'], null, 'id');
            $moved = $visible[$id] ?? null;
            if (!$moved) throw new TablerosApiException('not_found', 'No se encontró el elemento visible.', 404);
            if (empty($moved['can_reorder'])) throw new TablerosApiException('forbidden', 'No tienes permiso para mover este elemento.', 403);
            $workspaceId = (int)$moved['workspace_id'];
            if ($type === 'folder' && $targetParentId !== null) {
                if ($targetParentId === $id) throw new TablerosApiException('validation', 'Una carpeta no puede contenerse a sí misma.', 422);
                $dest = $visibleFolders[$targetParentId] ?? null;
                if (!$dest || (int)$dest['workspace_id'] !== $workspaceId) throw new TablerosApiException('validation', 'La carpeta destino no pertenece a este espacio.', 422);
                if (empty($dest['can_reorder'])) throw new TablerosApiException('forbidden', 'No tienes permiso para modificar la carpeta destino.', 403);
                $cycle = $this->one('WITH descendants AS (SELECT id FROM tb_folder WHERE id = ? AND workspace_id = ? AND deleted_at IS NULL UNION ALL SELECT f.id FROM tb_folder f JOIN descendants d ON f.parent_folder_id = d.id WHERE f.workspace_id = ? AND f.deleted_at IS NULL) SELECT id FROM descendants WHERE id = ? OPTION (MAXRECURSION 100)', [$id, $workspaceId, $workspaceId, $targetParentId]);
                if ($cycle) throw new TablerosApiException('validation', 'No puedes mover una carpeta dentro de sí misma o de una subcarpeta.', 422);
            }
            if ($targetParentId !== null) {
                $dest = $visibleFolders[$targetParentId] ?? null;
                if (!$dest || (int)$dest['workspace_id'] !== $workspaceId || empty($dest['can_reorder'])) throw new TablerosApiException('forbidden', 'No tienes permiso para usar la carpeta destino.', 403);
            }
            if ($type === 'folder') {
                $depth = (int)($this->one('WITH tree AS (SELECT id, parent_folder_id, 1 AS lvl FROM tb_folder WHERE id = ? AND workspace_id = ? AND deleted_at IS NULL UNION ALL SELECT f.id, f.parent_folder_id, t.lvl + 1 FROM tb_folder f JOIN tree t ON f.parent_folder_id = t.id WHERE f.workspace_id = ? AND f.deleted_at IS NULL) SELECT COALESCE(MAX(lvl), 0) AS depth FROM tree OPTION (MAXRECURSION 100)', [$id, $workspaceId, $workspaceId])['depth'] ?? 0);
                $destDepth = 0; $cursorId = $targetParentId;
                while ($cursorId !== null) {
                    $cursor = $this->one('SELECT parent_folder_id FROM tb_folder WITH (UPDLOCK, HOLDLOCK) WHERE id = ? AND workspace_id = ? AND deleted_at IS NULL', [$cursorId, $workspaceId]);
                    if (!$cursor) throw new TablerosApiException('validation', 'La carpeta destino ya no está disponible.', 422);
                    $destDepth++; $cursorId = $cursor['parent_folder_id'] === null ? null : (int)$cursor['parent_folder_id'];
                    if ($destDepth > 3) throw new TablerosApiException('conflict', 'La jerarquía de carpetas no es válida.', 409);
                }
                if ($destDepth + $depth > 3) throw new TablerosApiException('validation', 'Monday permite hasta tres niveles de carpetas dentro del espacio de trabajo.', 422);
            }
            $oldParent = $moved[$parentKey] === null ? null : (int)$moved[$parentKey];
            if ($anchorId !== null) {
                $anchor = $visible[$anchorId] ?? null;
                if (!$anchor || (int)$anchor['workspace_id'] !== $workspaceId || ($anchor[$parentKey] === null ? null : (int)$anchor[$parentKey]) !== $targetParentId) throw new TablerosApiException('validation', 'El elemento de referencia no está en la lista destino.', 422);
                if (empty($anchor['can_reorder'])) throw new TablerosApiException('forbidden', 'No tienes permiso para reordenar el elemento de referencia.', 403);
            }
            $lists = [];
            foreach (array_unique([$oldParent, $targetParentId], SORT_REGULAR) as $parentId) {
                $rows = $this->all("SELECT id, sort_order FROM $table WITH (UPDLOCK, HOLDLOCK) WHERE workspace_id = ? AND (($parentKey = ?) OR ($parentKey IS NULL AND ? IS NULL)) AND deleted_at IS NULL ORDER BY sort_order, id", [$workspaceId, $parentId, $parentId]);
                foreach ($rows as $row) if (isset($visible[(int)$row['id']]) && empty($visible[(int)$row['id']]['can_reorder'])) throw new TablerosApiException('forbidden', 'Necesitas permiso para reordenar todos los elementos visibles de estas listas.', 403);
                $lists[(string)($parentId ?? 'root')] = array_values(array_filter($rows, static fn($r) => isset($visible[(int)$r['id']])));
            }
            $oldKey = (string)($oldParent ?? 'root'); $newKey = (string)($targetParentId ?? 'root');
            $source = array_values(array_filter($lists[$oldKey] ?? [], static fn($r) => (int)$r['id'] !== $id));
            $target = $oldKey === $newKey ? $source : ($lists[$newKey] ?? []);
            if ($anchorId !== null) {
                $idx = array_search($anchorId, array_map(static fn($r) => (int)$r['id'], $target), true);
                if ($idx === false) throw new TablerosApiException('conflict', 'La estructura cambió. Actualiza e inténtalo de nuevo.', 409);
                array_splice($target, $idx + ($placement === 'after' ? 1 : 0), 0, [['id' => $id, 'sort_order' => (int)$moved['sort_order']]]);
            } else {
                $target[] = ['id' => $id, 'sort_order' => (int)$moved['sort_order']];
            }
            $this->execute("UPDATE $table SET $parentKey = ?, updated_at = GETDATE() WHERE id = ? AND workspace_id = ? AND deleted_at IS NULL", [$targetParentId, $id, $workspaceId]);
            $write = function (array $rows, array $originalRows) use ($table, $parentKey, $workspaceId): void {
                $slots = array_map(static fn($row) => (int)$row['sort_order'], $originalRows);
                sort($slots, SORT_NUMERIC);
                while (count($slots) < count($rows)) $slots[] = ($slots ? max($slots) : -1) + 1;
                $previousPosition = null;
                foreach ($rows as $index => $row) {
                    $position = (int)$slots[$index];
                    if ($previousPosition !== null) {
                        if ($previousPosition >= 2147483647) throw new TablerosApiException('conflict', 'El orden excede el rango permitido.', 409);
                        $position = max($position, $previousPosition + 1);
                    }
                    if ($position > 2147483647) throw new TablerosApiException('conflict', 'El orden excede el rango permitido.', 409);
                    $this->execute("UPDATE $table SET sort_order = ?, updated_at = GETDATE() WHERE id = ? AND workspace_id = ? AND deleted_at IS NULL", [$position, (int)$row['id'], $workspaceId]);
                    $previousPosition = $position;
                }
            };
            if ($oldKey !== $newKey) $write($source, $lists[$oldKey] ?? []);
            $write($target, $lists[$newKey] ?? []);
            if ($type === 'board') $this->writeActivity($id, null, $userId, 'board.reordered', ['target_parent_id' => $targetParentId, 'anchor_id' => $anchorId, 'placement' => $placement]);
            return $this->getWorkspaceStructure($userId, $isAdmin);
        });
    }

    private function nextBoardSortOrder(int $workspaceId, ?int $folderId): int {
        $row = $this->one(
            'SELECT COALESCE(MAX(sort_order), -1) + 1 AS next_order FROM tb_board WITH (UPDLOCK, HOLDLOCK)
             WHERE workspace_id = ? AND ((folder_id = ?) OR (folder_id IS NULL AND ? IS NULL)) AND deleted_at IS NULL',
            [$workspaceId, $folderId, $folderId]
        );
        return (int)($row['next_order'] ?? 0);
    }

    public function getBoard(int $boardId): ?array {
        return $this->one('SELECT * FROM tb_board WHERE id = ? AND deleted_at IS NULL', [$boardId]);
    }

    public function getWorkspace(int $workspaceId): ?array {
        return $this->one("SELECT * FROM tb_workspace WHERE id=? AND workspace_type='workspace' AND deleted_at IS NULL", [$workspaceId]);
    }

    public function auditAdminBoardOpen(int $actorId, int $boardId): void {
        $this->auditAccess($actorId, 'board.admin_opened', 'board', $boardId, null, true);
    }

    public function getBoardMembers(int $boardId): array {
        $board = $this->one(
            'SELECT b.created_by, b.workspace_id, b.visibility, w.visibility AS workspace_visibility,
                    u.Nombre AS name, u.Usuario AS username, u.Correo AS email
             FROM tb_board b
             INNER JOIN tb_workspace w ON w.id = b.workspace_id AND w.deleted_at IS NULL
             LEFT JOIN [TG].[dbo].[Usuario] u ON u.Id = b.created_by
             WHERE b.id = ? AND b.deleted_at IS NULL',
            [$boardId]
        );
        if (!$board) {
            throw new TablerosApiException('not_found', 'No se encontró el tablero.', 404);
        }
        $members = $this->all(
            'SELECT m.id, m.board_id, m.user_id, m.role, m.invited_by, m.created_at,
                    u.Nombre AS name, u.Usuario AS username, u.Correo AS email
             FROM tb_board_member m
             LEFT JOIN [TG].[dbo].[Usuario] u ON u.Id = m.user_id
             INNER JOIN [TG].[dbo].[Usuario] active_user ON active_user.Id = m.user_id AND active_user.Estatus = 1
             WHERE m.board_id = ? AND m.deleted_at IS NULL AND m.user_id <> ?
               AND EXISTS (SELECT 1 FROM [TG].[dbo].[tg_permissions] p INNER JOIN [TG].[dbo].[tg_permissions_users] pu ON pu.permission_id=p.id
                           WHERE pu.user_id=m.user_id AND p.department=\'General\' AND p.status=1
                             AND ((p.action=\'read\' AND p.description=\'Tableros - Acceso\') OR (p.action=\'create\' AND p.description=\'Tableros - Crear\') OR (p.action=\'update\' AND p.description=\'Tableros - Administrar\')))
             ORDER BY m.created_at, m.id',
            [$boardId, (int)$board['created_by']]
        );
        foreach ($members as &$member) {
            $member['residual_access'] = null;
            // Direct invitations are represented separately. Workspace/open/organization grants remain after revocation.
            $residual = $this->residualBoardAccess($boardId, (int)$member['user_id']);
            if ($residual !== null) $member['residual_access'] = $residual;
        }
        unset($member);
        return [
            'board_id' => $boardId,
            'owner' => [
                'user_id' => (int)$board['created_by'], 'role' => 'owner',
                'name' => trim((string)($board['name'] ?? '')),
                'username' => (string)($board['username'] ?? ''), 'email' => (string)($board['email'] ?? ''),
            ],
            'members' => $members,
        ];
    }

    public function getWorkspaceMembers(int $workspaceId): array {
        $this->requireWorkspaceManager($workspaceId);
        $workspace = $this->one('SELECT w.id, w.created_by, u.Nombre AS name, u.Usuario AS username FROM tb_workspace w LEFT JOIN [TG].[dbo].[Usuario] u ON u.Id = w.created_by WHERE w.id = ? AND w.workspace_type = ? AND w.deleted_at IS NULL', [$workspaceId, 'workspace']);
        if (!$workspace) throw new TablerosApiException('not_found', 'No se encontró el espacio de trabajo.', 404);
        $members = $this->all(
            "SELECT m.user_id, COALESCE(NULLIF(LTRIM(RTRIM(u.Nombre)), ''), u.Usuario) AS name,
                    u.Usuario AS username, m.role
             FROM tb_workspace_member m
             INNER JOIN [TG].[dbo].[Usuario] u ON u.Id = m.user_id AND u.Estatus = 1
             WHERE m.workspace_id = ? AND m.deleted_at IS NULL AND m.user_id <> ?
               AND EXISTS (SELECT 1 FROM [TG].[dbo].[tg_permissions] p INNER JOIN [TG].[dbo].[tg_permissions_users] pu ON pu.permission_id = p.id
                           WHERE pu.user_id = m.user_id AND p.department = 'General' AND p.status = 1
                             AND ((p.action='read' AND p.description='Tableros - Acceso') OR (p.action='create' AND p.description='Tableros - Crear') OR (p.action='update' AND p.description='Tableros - Administrar')))
             ORDER BY CASE WHEN m.role='owner' THEN 0 ELSE 1 END, name, m.user_id",
            [$workspaceId, (int)$workspace['created_by']]
        );
        return ['workspace_id' => $workspaceId, 'owner' => ['user_id' => (int)$workspace['created_by'], 'name' => trim((string)($workspace['name'] ?? '')) ?: (string)($workspace['username'] ?? ''), 'username' => (string)($workspace['username'] ?? ''), 'role' => 'owner'], 'members' => $members];
    }

    public function shareWorkspace(int $workspaceId, int $actorId, array $input): array {
        $this->requireWorkspaceManager($workspaceId);
        $targetId = $this->positiveInt($input['user_id'] ?? null, 'user_id');
        $role = strtolower(trim((string)($input['role'] ?? '')));
        if (!in_array($role, ['owner','editor','viewer'], true)) throw new TablerosApiException('validation', 'El rol del espacio no es válido.', 422);
        $this->assertWorkspaceTarget($targetId);
        return $this->transaction(function () use ($workspaceId, $actorId, $targetId, $role): array {
            $workspace = $this->one('SELECT created_by FROM tb_workspace WITH (UPDLOCK, HOLDLOCK) WHERE id = ? AND workspace_type = ? AND deleted_at IS NULL', [$workspaceId, 'workspace']);
            if (!$workspace) throw new TablerosApiException('not_found', 'No se encontró el espacio de trabajo.', 404);
            if ((int)$workspace['created_by'] === $targetId) throw new TablerosApiException('validation', 'El creador conserva el rol propietario.', 422);
            $old = $this->one('SELECT TOP (1) id FROM tb_workspace_member WITH (UPDLOCK, HOLDLOCK) WHERE workspace_id=? AND user_id=? ORDER BY id DESC', [$workspaceId,$targetId]);
            if ($old) $this->execute('UPDATE tb_workspace_member SET role=?, invited_by=?, updated_at=GETDATE(), deleted_at=NULL WHERE id=?', [$role,$actorId,$old['id']]);
            else $this->execute('INSERT INTO tb_workspace_member (workspace_id,user_id,role,invited_by,created_at,updated_at) VALUES (?,?,?,?,GETDATE(),GETDATE())', [$workspaceId,$targetId,$role,$actorId]);
            $this->auditAccess($actorId, 'workspace.member_added', 'workspace', $workspaceId, ['user_id'=>$targetId,'role'=>$role]);
            return ['workspace_id'=>$workspaceId,'user_id'=>$targetId,'role'=>$role];
        });
    }

    public function updateWorkspaceMember(int $workspaceId, int $actorId, array $input): array {
        $this->requireWorkspaceManager($workspaceId);
        $targetId = $this->positiveInt($input['user_id'] ?? null, 'user_id');
        $this->assertWorkspaceTarget($targetId);
        $role = strtolower(trim((string)($input['role'] ?? '')));
        if (!in_array($role, ['owner','editor','viewer'], true)) throw new TablerosApiException('validation', 'El rol del espacio no es válido.', 422);
        return $this->transaction(function () use ($workspaceId,$actorId,$targetId,$role): array {
            $workspace = $this->one('SELECT created_by FROM tb_workspace WITH (UPDLOCK, HOLDLOCK) WHERE id=? AND workspace_type=? AND deleted_at IS NULL', [$workspaceId,'workspace']);
            if (!$workspace) throw new TablerosApiException('not_found','No se encontró el espacio de trabajo.',404);
            if ((int)$workspace['created_by'] === $targetId && $role !== 'owner') throw new TablerosApiException('validation','El creador debe conservar el rol propietario.',422);
            $member = $this->one('SELECT id,role FROM tb_workspace_member WITH (UPDLOCK,HOLDLOCK) WHERE workspace_id=? AND user_id=? AND deleted_at IS NULL',[$workspaceId,$targetId]);
            if (!$member) throw new TablerosApiException('not_found','No se encontró el miembro activo.',404);
            if ($member['role']==='owner' && $role!=='owner' && (int)($this->one("SELECT COUNT(*) AS n FROM tb_workspace_member WHERE workspace_id=? AND role='owner' AND deleted_at IS NULL",[$workspaceId])['n'] ?? 0) <= 1) throw new TablerosApiException('validation','No se puede quitar al último propietario.',422);
            $this->execute('UPDATE tb_workspace_member SET role=?,updated_at=GETDATE() WHERE id=?',[$role,$member['id']]);
            $this->auditAccess($actorId,'workspace.member_role_changed','workspace',$workspaceId,['user_id'=>$targetId,'role'=>$role]);
            return ['workspace_id'=>$workspaceId,'user_id'=>$targetId,'role'=>$role];
        });
    }

    public function revokeWorkspaceMember(int $workspaceId, int $actorId, array $input): array {
        $this->requireWorkspaceManager($workspaceId);
        $targetId = $this->positiveInt($input['user_id'] ?? null, 'user_id');
        return $this->transaction(function () use ($workspaceId,$actorId,$targetId): array {
            $workspace = $this->one('SELECT created_by FROM tb_workspace WITH (UPDLOCK,HOLDLOCK) WHERE id=? AND workspace_type=? AND deleted_at IS NULL',[$workspaceId,'workspace']);
            if (!$workspace) throw new TablerosApiException('not_found','No se encontró el espacio de trabajo.',404);
            if ((int)$workspace['created_by'] === $targetId) throw new TablerosApiException('validation','No se puede revocar al creador del espacio.',422);
            $member = $this->one('SELECT id,role FROM tb_workspace_member WITH (UPDLOCK,HOLDLOCK) WHERE workspace_id=? AND user_id=? AND deleted_at IS NULL',[$workspaceId,$targetId]);
            if (!$member) throw new TablerosApiException('not_found','No se encontró el miembro activo.',404);
            if ($member['role']==='owner' && (int)($this->one("SELECT COUNT(*) AS n FROM tb_workspace_member WHERE workspace_id=? AND role='owner' AND deleted_at IS NULL",[$workspaceId])['n'] ?? 0) <= 1) throw new TablerosApiException('validation','No se puede revocar al último propietario.',422);
            $this->execute('UPDATE tb_workspace_member SET deleted_at=GETDATE(),updated_at=GETDATE() WHERE id=?',[$member['id']]);
            $this->auditAccess($actorId,'workspace.member_revoked','workspace',$workspaceId,['user_id'=>$targetId]);
            return ['workspace_id'=>$workspaceId,'user_id'=>$targetId,'revoked'=>true];
        });
    }

    private function assertWorkspaceTarget(int $userId): void {
        if (!$this->userIsActive($userId) || !$this->access->userHasModuleAccess($userId)) throw new TablerosApiException('validation','El usuario debe tener una cuenta activa y acceso al módulo Tableros.',422);
    }

    private function requireWorkspaceManager(int $workspaceId): void {
        if (!$this->getWorkspace($workspaceId)) throw new TablerosApiException('not_found', 'No se encontró el espacio de trabajo.', 404);
        if ($this->access->workspaceRole($workspaceId) !== 'owner') throw new TablerosApiException('forbidden', 'Solo el propietario del espacio o un administrador puede administrar sus miembros.', 403);
    }

    private function requireBoardAllowed(int $boardId, array $roles): void {
        $role = $this->access->boardRole($boardId);
        if ($role === null || !in_array($role, $roles, true)) throw new TablerosApiException('forbidden', 'No tienes el rol necesario en este tablero.', 403);
    }

    private function residualBoardAccess(int $boardId, int $userId): ?array {
        $row = $this->one('SELECT b.visibility,w.visibility AS workspace_visibility,wm.role AS workspace_role FROM tb_board b INNER JOIN tb_workspace w ON w.id=b.workspace_id LEFT JOIN tb_workspace_member wm ON wm.workspace_id=w.id AND wm.user_id=? AND wm.deleted_at IS NULL WHERE b.id=? AND b.deleted_at IS NULL',[$userId,$boardId]);
        if (!$row) return null;
        if ($row['visibility']==='workspace' && in_array($row['workspace_role'] ?? '',['owner','editor','viewer'],true)) return ['role'=>in_array($row['workspace_role'],['owner','editor'],true)?'editor':'viewer','source'=>'workspace_membership'];
        if ($row['visibility']==='workspace' && $row['workspace_visibility']==='open') return ['role'=>'viewer','source'=>'open_workspace'];
        if ($row['visibility']==='organization') return ['role'=>'viewer','source'=>'organization'];
        return null;
    }

    private function auditAccess(int $actorId, string $action, string $resourceType, ?int $resourceId, ?array $metadata = null, bool $force = false): void {
        if (!$force && !$this->access->hasGlobalPermission('admin')) return;
        $identity = session_id() !== '' ? session_id() : 'user-' . $actorId;
        $json = $metadata === null ? null : json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->execute('INSERT INTO dbo.tb_access_audit (identity_key,actor_user_id,[action],resource_type,resource_id,metadata_json) VALUES (?,?,?,?,?,?)',
            [substr($identity, 0, 200), $actorId, $action, $resourceType, $resourceId, $json]);
    }

    public function revokeBoardMember(int $boardId, int $actorId, array $input): array {
        $this->requireBoardAllowed($boardId, ['owner','designer','editor']);
        $targetUserId = $this->positiveInt($input['user_id'] ?? null, 'user_id');
        return $this->transaction(function () use ($boardId, $actorId, $targetUserId): array {
            $board = $this->one(
                'SELECT created_by FROM tb_board WITH (UPDLOCK, HOLDLOCK) WHERE id = ? AND deleted_at IS NULL',
                [$boardId]
            );
            if (!$board) {
                throw new TablerosApiException('not_found', 'No se encontró el tablero.', 404);
            }
            if ($targetUserId === (int)$board['created_by']) {
                throw new TablerosApiException('validation', 'No se puede quitar el acceso del propietario.', 422);
            }
            $member = $this->one(
                'SELECT id FROM tb_board_member WITH (UPDLOCK, HOLDLOCK)
                 WHERE board_id = ? AND user_id = ? AND deleted_at IS NULL',
                [$boardId, $targetUserId]
            );
            if (!$member) {
                throw new TablerosApiException('not_found', 'No se encontró el miembro activo del tablero.', 404);
            }
            $this->execute(
                'UPDATE tb_board_member SET deleted_at = GETDATE(), updated_at = GETDATE() WHERE id = ? AND deleted_at IS NULL',
                [(int)$member['id']]
            );
            $this->auditAccess($actorId, 'board.member_revoked', 'board', $boardId, ['user_id' => $targetUserId]);
            $this->writeActivity($boardId, null, $actorId, 'board.member_revoked', ['user_id' => $targetUserId]);
            return ['board_id' => $boardId, 'user_id' => $targetUserId, 'revoked' => true];
        });
    }

    /** Return all data needed to render one board after access was checked. */
    public function getBoardData(int $boardId, int $userId): array {
        $board = $this->getBoard($boardId);
        if (!$board) {
            throw new TablerosApiException('not_found', 'No se encontró el tablero.', 404);
        }
        $board['version'] = $this->versionToken($board['version'] ?? null);

        $groups = $this->all(
            'SELECT id, board_id, name, color, sort_order, created_by, created_at, updated_at
             FROM tb_group WHERE board_id = ? AND deleted_at IS NULL ORDER BY sort_order, id',
            [$boardId]
        );
        $columns = $this->all(
            'SELECT id, board_id, name, type, options_json, required, sort_order, enforce_unique, created_by, created_at, updated_at
             FROM tb_column WHERE board_id = ? AND deleted_at IS NULL ORDER BY sort_order, id',
            [$boardId]
        );
        foreach ($columns as &$column) {
            $column['required'] = (bool)$column['required'];
            $column['enforce_unique'] = (bool)$column['enforce_unique'];
            $column['options'] = $this->decodeJson($column['options_json'] ?? null, []);
            unset($column['options_json']);
        }
        unset($column);

        $items = $this->all(
            'SELECT i.id, i.board_id, i.group_id, i.parent_item_id, i.name, i.sort_order, i.version,
                    i.created_by, i.updated_by, i.created_at, i.updated_at,
                    (SELECT COUNT(*) FROM tb_comment c WHERE c.item_id = i.id AND c.board_id = i.board_id AND c.deleted_at IS NULL) AS comment_count
             FROM tb_item i WHERE i.board_id = ? AND i.deleted_at IS NULL ORDER BY i.group_id, i.sort_order, i.id',
            [$boardId]
        );
        $cells = $this->all(
            'SELECT c.id, c.item_id, c.column_id, col.type, c.value_text, c.value_number,
                    c.value_date, c.value_datetime, c.value_boolean, c.value_json,
                    c.version, c.updated_by, c.updated_at
             FROM tb_cell c
             INNER JOIN tb_item i ON i.id = c.item_id AND i.board_id = ? AND i.deleted_at IS NULL
             INNER JOIN tb_column col ON col.id = c.column_id AND col.board_id = i.board_id
             WHERE col.deleted_at IS NULL AND c.deleted_at IS NULL
             ORDER BY c.item_id, c.column_id',
            [$boardId]
        );
        $itemPositions = [];
        foreach ($items as $index => &$item) {
            $item['version'] = $this->versionToken($item['version'] ?? null);
            $item['cells'] = [];
            foreach ($columns as $column) {
                if (in_array((string)$column['type'], ['people', 'person'], true)) {
                    $item['cells'][(string)$column['id']] = [];
                }
            }
            $itemPositions[(string)$item['id']] = $index;
        }
        unset($item);
        $activeFileIds = array_fill_keys(array_map(
            static fn(array $file): string => (string)$file['id'],
            $this->all('SELECT id FROM tb_file WHERE board_id = ? AND deleted_at IS NULL', [$boardId])
        ), true);
        $filterActiveFiles = static function (array $entries) use ($activeFileIds): array {
            return array_values(array_filter($entries, static function ($entry) use ($activeFileIds): bool {
                if (!is_array($entry)) return false;
                $fileId = $entry['file_id'] ?? $entry['id'] ?? null;
                return $fileId !== null && isset($activeFileIds[(string)$fileId]);
            }));
        };
        foreach ($cells as &$cell) {
            $value = $this->cellValue($cell);
            if ((string)$cell['type'] === 'file' && is_array($value)) {
                if (isset($value['files']) && is_array($value['files'])) {
                    $value['files'] = $filterActiveFiles($value['files']);
                } elseif (array_is_list($value)) {
                    $value = $filterActiveFiles($value);
                } elseif (isset($value['file_id']) || isset($value['id'])) {
                    $value = $filterActiveFiles([$value]);
                    $value = $value[0] ?? [];
                }
            }
            $itemIndex = $itemPositions[(string)$cell['item_id']] ?? null;
            if ($itemIndex !== null) {
                $items[$itemIndex]['cells'][(string)$cell['column_id']] = $value;
            }
            $cell['value'] = $value;
        }
        unset($cell);

        $people = $this->all(
            "SELECT p.item_id, p.column_id, p.user_id
             FROM tb_item_person p
             INNER JOIN tb_item i ON i.id = p.item_id AND i.board_id = ? AND i.deleted_at IS NULL
             INNER JOIN tb_column col ON col.id = p.column_id AND col.board_id = i.board_id
             WHERE p.board_id = ? AND p.deleted_at IS NULL AND col.deleted_at IS NULL
               AND col.type IN ('people', 'person')
             ORDER BY p.item_id, p.column_id, p.position, p.id",
            [$boardId, $boardId]
        );
        foreach ($people as $person) {
            $itemIndex = $itemPositions[(string)$person['item_id']] ?? null;
            if ($itemIndex === null) {
                continue;
            }
            $columnKey = (string)$person['column_id'];
            $items[$itemIndex]['cells'][$columnKey] ??= [];
            $items[$itemIndex]['cells'][$columnKey][] = (int)$person['user_id'];
        }

        $views = $this->all(
            'SELECT id, board_id, name, view_type, config_json, owner_user_id, is_shared, created_at, updated_at
             FROM tb_view
             WHERE board_id = ? AND deleted_at IS NULL AND (is_shared = 1 OR owner_user_id = ?)
             ORDER BY is_shared DESC, created_at, id',
            [$boardId, $userId]
        );
        foreach ($views as &$view) {
            $view['config'] = $this->decodeJson($view['config_json'] ?? null, []);
            $view['is_shared'] = (bool)$view['is_shared'];
            unset($view['config_json']);
        }
        unset($view);

        $board['views'] = $views;
        return ['board' => $board, 'groups' => $groups, 'columns' => $columns, 'items' => $items, 'views' => $views];
    }

    public function activeUsers(string $search = ''): array {
        return $this->access->activeUsers($search);
    }

    public function activeUsersWithDashboardAccess(string $search = ''): array {
        return $this->access->activeUsers($search, true, true);
    }

    public function userIsActive(int $userId): bool {
        return $this->access->userIsActive($userId);
    }

    public function getActivity(int $boardId, ?int $itemId = null, int $limit = 100): array {
        if ($itemId !== null) {
            $this->requireItemOnBoard($itemId, $boardId);
        }
        $limit = max(1, min(200, $limit));
        $sql = "SELECT TOP ($limit) a.id, a.board_id, a.item_id, a.actor_user_id,
                       COALESCE(NULLIF(LTRIM(RTRIM(u.Nombre)), ''), NULLIF(LTRIM(RTRIM(u.Usuario)), '')) AS actor_display_name,
                       a.event_type, a.payload_json, a.created_at
                FROM tb_activity a
                LEFT JOIN [TG].[dbo].[Usuario] u ON u.Id = a.actor_user_id
                WHERE a.board_id = ?";
        $params = [$boardId];
        if ($itemId !== null) {
            $sql .= ' AND a.item_id = ?';
            $params[] = $itemId;
        }
        $sql .= ' ORDER BY a.created_at DESC, a.id DESC';
        $events = $this->all($sql, $params);
        foreach ($events as &$event) {
            $event['payload'] = $this->decodeJson($event['payload_json'] ?? null, []);
            unset($event['payload_json']);
        }
        unset($event);
        return $events;
    }

    public function getComments(int $boardId, int $itemId): array {
        $this->requireItemOnBoard($itemId, $boardId);
        $comments = $this->all(
            "SELECT TOP (200) c.id, c.board_id, c.item_id, c.parent_comment_id, c.body, c.created_by, c.updated_by,
                    COALESCE(NULLIF(LTRIM(RTRIM(u.Nombre)), ''), NULLIF(LTRIM(RTRIM(u.Usuario)), '')) AS created_by_display_name,
                    c.created_at, c.updated_at
             FROM tb_comment c
             LEFT JOIN [TG].[dbo].[Usuario] u ON u.Id = c.created_by
             WHERE c.board_id = ? AND c.item_id = ? AND c.deleted_at IS NULL
             ORDER BY c.created_at, c.id",
            [$boardId, $itemId]
        );
        foreach ($comments as &$comment) {
            $comment['stickers'] = $this->all('SELECT s.id, s.name FROM tb_comment_sticker l INNER JOIN tb_user_sticker s ON s.id = l.sticker_id WHERE l.comment_id = ? ORDER BY s.id', [(int)$comment['id']]);
            $comment['attachments'] = $this->all('SELECT f.id AS file_id, f.name FROM tb_comment_attachment a INNER JOIN tb_file f ON f.id = a.file_id WHERE a.comment_id = ? AND f.deleted_at IS NULL ORDER BY f.name, f.id', [(int)$comment['id']]);
        }
        unset($comment);
        return $comments;
    }

    public function addComment(int $boardId, int $userId, array $input): array {
        $itemId = $this->positiveInt($input['item_id'] ?? null, 'item_id');
        $parentId = $this->optionalPositiveInt($input['parent_comment_id'] ?? null, 'parent_comment_id');
        $rawBody = isset($input['body']) && is_string($input['body']) ? trim($input['body']) : '';
        $body = $this->sanitizeCommentBody($rawBody);
        $stickerIds = $input['stickers'] ?? [];
        if (!is_array($stickerIds) || count($stickerIds) > 20) throw new TablerosApiException('validation', 'La lista de stickers no es válida.', 422);
        $stickerIds = array_values(array_unique(array_map(fn($id): int => $this->positiveInt($id, 'stickers'), $stickerIds)));
        $attachmentIds = $input['file_ids'] ?? [];
        if (!is_array($attachmentIds) || count($attachmentIds) > 20) throw new TablerosApiException('validation', 'La lista de archivos adjuntos no es válida.', 422);
        $attachmentIds = array_values(array_unique(array_map(fn($id): int => $this->positiveInt($id, 'file_ids'), $attachmentIds)));
        if (trim(strip_tags($body)) === '' && !$stickerIds && !$attachmentIds) throw new TablerosApiException('validation', 'Escribe un comentario o agrega un sticker o archivo.', 422);
        if (mb_strlen($body, 'UTF-8') > 10000) throw new TablerosApiException('validation', 'El comentario excede el límite de 10000 caracteres.', 422);
        $this->requireItemOnBoard($itemId, $boardId);
        if ($parentId !== null && !$this->one(
            'SELECT id FROM tb_comment WHERE id = ? AND board_id = ? AND item_id = ? AND deleted_at IS NULL',
            [$parentId, $boardId, $itemId]
        )) {
            throw new TablerosApiException('validation', 'El comentario padre no pertenece a este elemento.', 422);
        }
        return $this->transaction(function () use ($boardId, $userId, $itemId, $parentId, $body, $stickerIds, $attachmentIds): array {
            if ($stickerIds) {
                $marks = implode(',', array_fill(0, count($stickerIds), '?'));
                if ((int)$this->one("SELECT COUNT(*) AS total FROM tb_user_sticker WHERE id IN ($marks) AND deleted_at IS NULL", $stickerIds)['total'] !== count($stickerIds)) throw new TablerosApiException('validation', 'Uno o más stickers ya no están disponibles.', 422);
            }
            if ($attachmentIds) {
                $marks = implode(',', array_fill(0, count($attachmentIds), '?'));
                $params = array_merge([$boardId, $itemId], $attachmentIds);
                if ((int)$this->one("SELECT COUNT(*) AS total FROM tb_file WHERE board_id = ? AND item_id = ? AND deleted_at IS NULL AND id IN ($marks)", $params)['total'] !== count($attachmentIds)) throw new TablerosApiException('validation', 'Uno o más archivos no pertenecen a este elemento o ya no están disponibles.', 422);
            }
            $id = $this->insertId(
                'INSERT INTO tb_comment (board_id, item_id, parent_comment_id, body, created_by, created_at, updated_at)
                 OUTPUT INSERTED.id VALUES (?, ?, ?, ?, ?, GETDATE(), GETDATE())',
                [$boardId, $itemId, $parentId, $body, $userId]
            );
            foreach ($stickerIds as $stickerId) $this->execute('INSERT INTO tb_comment_sticker (comment_id, sticker_id, created_by, created_at) VALUES (?, ?, ?, GETDATE())', [$id, $stickerId, $userId]);
            foreach ($attachmentIds as $fileId) $this->execute('INSERT INTO tb_comment_attachment (comment_id, file_id, created_at) VALUES (?, ?, GETDATE())', [$id, $fileId]);
            $this->writeActivity($boardId, $itemId, $userId, 'comment.created', ['comment_id' => $id]);
            return [
                'id' => $id, 'board_id' => $boardId, 'item_id' => $itemId,
                'parent_comment_id' => $parentId, 'body' => $body, 'created_by' => $userId,
                'created_by_display_name' => $this->userDisplayName($userId),
                'stickers' => $stickerIds ? $this->all('SELECT id, name FROM tb_user_sticker WHERE id IN (' . implode(',', array_fill(0, count($stickerIds), '?')) . ')', $stickerIds) : [],
                'attachments' => $attachmentIds ? $this->all('SELECT f.id AS file_id, f.name FROM tb_file f WHERE f.id IN (' . implode(',', array_fill(0, count($attachmentIds), '?')) . ') ORDER BY f.name, f.id', $attachmentIds) : [],
            ];
        });
    }

    private function sanitizeCommentBody(string $body): string {
        if ($body === '') return '';
        $body = preg_replace('~<(script|style|iframe|object|svg|math)\\b[^>]*>.*?</\\1\\s*>~is', '', $body) ?? '';
        return preg_replace_callback('~</?([a-zA-Z][a-zA-Z0-9]*)\\b([^>]*)>~', static function (array $match): string {
            $tag = strtolower($match[1]); $closing = str_starts_with($match[0], '</');
            $allowed = ['b'=>'b','strong'=>'strong','i'=>'i','em'=>'em','u'=>'u','span'=>'span','br'=>'br','p'=>'p','div'=>'div'];
            if (!isset($allowed[$tag])) return '';
            if ($closing) return in_array($tag, ['br'], true) ? '' : '</' . $allowed[$tag] . '>';
            if ($tag !== 'span' || !preg_match('/\\bstyle\\s*=\\s*(["\'])(.*?)\\1/i', $match[2], $style)) return '<' . $allowed[$tag] . '>';
            if (!preg_match('/^\\s*color\\s*:\\s*(#[0-9a-f]{3}(?:[0-9a-f]{3})?|[a-z]{1,20})\\s*;?\\s*$/i', $style[2], $color)) return '<span>';
            return '<span style="color:' . strtolower($color[1]) . '">';
        }, $body) ?? '';
    }

    public function attachItemCommentFile(int $commentId, int $fileId): int {
        return $this->transaction(function () use ($commentId, $fileId): int {
            $comment = $this->one('SELECT board_id, item_id FROM tb_comment WHERE id = ? AND deleted_at IS NULL', [$commentId]);
            if (!$comment) throw new TablerosApiException('not_found', 'No se encontró el comentario.', 404);
            $file = $this->one('SELECT id FROM tb_file WHERE id = ? AND board_id = ? AND item_id = ? AND deleted_at IS NULL', [$fileId, $comment['board_id'], $comment['item_id']]);
            if (!$file) throw new TablerosApiException('validation', 'El archivo debe pertenecer al mismo tablero y elemento del comentario.', 422);
            $this->execute('IF NOT EXISTS (SELECT 1 FROM tb_comment_attachment WHERE comment_id = ? AND file_id = ?) INSERT INTO tb_comment_attachment (comment_id, file_id, created_at) VALUES (?, ?, GETDATE())', [$commentId, $fileId, $commentId, $fileId]);
            return (int)$comment['board_id'];
        });
    }

    public function getItemCommentBoardId(int $commentId): int {
        $row = $this->one('SELECT board_id FROM tb_comment WHERE id = ? AND deleted_at IS NULL', [$commentId]);
        if (!$row) throw new TablerosApiException('not_found', 'No se encontró el comentario.', 404);
        return (int)$row['board_id'];
    }

    public function getStickers(): array {
        return $this->all(
            "SELECT s.id, s.name, s.content_type, s.byte_size,
                    COALESCE(NULLIF(LTRIM(RTRIM(u.Nombre)), ''), NULLIF(LTRIM(RTRIM(u.Usuario)), '')) AS created_by_display_name
             FROM tb_user_sticker s LEFT JOIN [TG].[dbo].[Usuario] u ON u.Id = s.user_id
             WHERE s.deleted_at IS NULL
             ORDER BY CASE WHEN s.name = N'Sticker predeterminado' THEN 0 ELSE 1 END, s.created_at DESC, s.id DESC
             OFFSET 0 ROWS FETCH NEXT 100 ROWS ONLY"
        );
    }

    public function uploadSticker(int $userId, array $upload, $requestedName = null): array {
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($upload['tmp_name'])
            || !is_uploaded_file((string)$upload['tmp_name'])) {
            throw new TablerosApiException('validation', 'Selecciona una imagen válida.', 422);
        }
        $source = (string)$upload['tmp_name'];
        $sourceSize = filesize($source);
        if ($sourceSize === false || $sourceSize < 1 || $sourceSize > 2 * 1024 * 1024 || (int)($upload['size'] ?? 0) !== $sourceSize) {
            throw new TablerosApiException('validation', 'La imagen debe pesar máximo 2 MB.', 422);
        }
        if ($requestedName !== null && !is_scalar($requestedName)) throw new TablerosApiException('validation', 'El nombre del sticker no es válido.', 422);
        $name = trim((string)($requestedName ?? ''));
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\\x00-\\x1F\\x7F]/u', '', $name) ?? '';
        $name = trim($name);
        if ($name === '') $name = 'Sticker';
        if (preg_match('//u', $name) !== 1) throw new TablerosApiException('validation', 'El nombre del sticker no es válido.', 422);
        $characters = preg_split('//u', $name, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($characters)) throw new TablerosApiException('validation', 'El nombre del sticker no es válido.', 422);
        $name = implode('', array_slice($characters, 0, 80));
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($source);
        $allowed = ['image/png' => IMAGETYPE_PNG, 'image/jpeg' => IMAGETYPE_JPEG, 'image/webp' => IMAGETYPE_WEBP];
        $info = @getimagesize($source);
        if (!is_array($info) || !isset($allowed[$mime]) || (int)$info[2] !== $allowed[$mime]
            || (string)($info['mime'] ?? '') !== $mime) {
            throw new TablerosApiException('validation', 'Solo se aceptan imágenes PNG, JPEG o WebP válidas.', 422);
        }
        $width = (int)$info[0]; $height = (int)$info[1];
        if ($width < 1 || $height < 1 || $width > 4096 || $height > 4096 || $width * $height > 12000000) {
            throw new TablerosApiException('validation', 'Las dimensiones de la imagen no son válidas.', 422);
        }
        $hash = hash_file('sha256', $source, true);
        if (!is_string($hash)) throw new TablerosApiException('server', 'No se pudo verificar el sticker.', 500);
        $useNas = $this->nasServiceEnabled();
        $now = new DateTimeImmutable('now'); $objectHash = bin2hex(random_bytes(32));
        $key = implode('/', ['stickers', (string)$userId, $now->format('Y'), $now->format('m'), substr($objectHash, 0, 2), $objectHash]);
        $destination = null;
        if ($useNas) $this->nasServiceRequest('PUT', $key, $source, $sourceSize, $mime);
        else $destination = $this->writeStickerLocal($key, $source);
        try {
            return $this->transaction(function () use ($userId, $key, $sourceSize, $hash, $useNas, $name, $mime): array {
                if (!$this->userIsActive($userId)) throw new TablerosApiException('forbidden', 'La cuenta TG no está activa.', 403);
                $this->all('SELECT id FROM tb_user_sticker WITH (UPDLOCK, HOLDLOCK) WHERE user_id = ?', [$userId]);
                $count = (int)($this->one('SELECT COUNT(*) AS total FROM tb_user_sticker WHERE user_id = ? AND deleted_at IS NULL', [$userId])['total'] ?? 0);
                if ($count >= 50) throw new TablerosApiException('conflict', 'Cada usuario puede tener hasta 50 stickers activos.', 409);
                $id = $this->insertId(
                    "INSERT INTO tb_user_sticker (user_id, name, content_type, byte_size, sha256_hash, storage_provider, storage_container, storage_object_key, created_at)
                     OUTPUT INSERTED.id VALUES (?, ?, ?, ?, CONVERT(VARBINARY(32), ?, 2), ?, ?, ?, GETDATE())",
                    [$userId, $name, $mime, $sourceSize, bin2hex($hash), $useNas ? 'nas_service' : 'local', 'tableros', $key]
                );
                return ['id' => $id, 'name' => $name, 'content_type' => $mime, 'byte_size' => $sourceSize, 'created_by_display_name' => $this->userDisplayName($userId)];
            });
        } catch (Throwable $e) {
            if ($useNas) { try { $this->nasServiceRequest('DELETE', $key); } catch (Throwable $cleanup) { error_log('Tableros sticker cleanup failed: ' . $cleanup->getMessage()); } }
            elseif ($destination !== null) @unlink($destination);
            throw $e;
        }
    }

    /** The controller authorizes the active user before calling this method. */
    public function getStickerDownload(int $stickerId): array {
        $row = $this->one(
            "SELECT s.id, s.name, s.content_type, s.byte_size, s.sha256_hash, s.storage_provider, s.storage_container, s.storage_object_key,
                    CONVERT(VARCHAR(64), s.sha256_hash, 2) AS sha256_hex
             FROM tb_user_sticker s WHERE s.id = ? AND (s.deleted_at IS NULL OR EXISTS (SELECT 1 FROM tb_file_comment_sticker l WHERE l.sticker_id = s.id) OR EXISTS (SELECT 1 FROM tb_comment_sticker l WHERE l.sticker_id = s.id))",
            [$stickerId]
        );
        $contentType = (string)($row['content_type'] ?? '');
        if (!$row || !in_array((string)$row['storage_provider'], ['local', 'nas_service'], true) || $row['storage_container'] !== 'tableros'
            || !in_array($contentType, ['image/png', 'image/jpeg', 'image/webp'], true)) throw new TablerosApiException('not_found', 'No se encontró el sticker.', 404);
        $extension = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$contentType];
        $key = (string)$row['storage_object_key'];
        if ($row['storage_provider'] === 'nas_service') {
            $path = tempnam(sys_get_temp_dir(), 'sticker-');
            if ($path === false) throw new TablerosApiException('server', 'No se pudo preparar el sticker.', 500);
            try {
                $this->nasServiceRequest('GET', $key, null, null, null, $path, (int)$row['byte_size']);
                $this->verifyStickerFile($path, $row);
                return ['id' => (int)$row['id'], 'name' => (string)$row['name'], 'extension' => $extension, 'content_type' => $contentType, 'byte_size' => (int)$row['byte_size'], 'path' => $path, 'temporary' => true];
            } catch (Throwable $e) { @unlink($path); throw $e; }
        }
        $path = $this->privateObjectPath($key);
        $this->verifyStickerFile($path, $row);
        return ['id' => (int)$row['id'], 'name' => (string)$row['name'], 'extension' => $extension, 'content_type' => $contentType, 'byte_size' => (int)$row['byte_size'], 'path' => $path, 'temporary' => false];
    }

    private function verifyStickerFile(string $path, array $row): void {
        $size = filesize($path); $hash = hash_file('sha256', $path);
        if ($size !== (int)$row['byte_size'] || !is_string($hash) || !hash_equals(strtolower((string)$row['sha256_hex']), strtolower($hash))) {
            throw new TablerosApiException('server', 'El sticker almacenado no coincide con sus datos de integridad.', 500);
        }
    }

    private function writeStickerLocal(string $key, string $source): string {
        $root = $this->privateStorageRoot();
        $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $key);
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) throw new TablerosApiException('server', 'No se pudo preparar el almacenamiento privado.', 500);
        if (!$this->pathIsWithin((string)realpath($dir), $root)) throw new TablerosApiException('server', 'Ruta de almacenamiento no válida.', 500);
        $input = fopen($source, 'rb'); $output = @fopen($path, 'xb');
        if (!is_resource($input) || !is_resource($output)) { if (is_resource($input)) fclose($input); if (is_resource($output)) fclose($output); @unlink($path); throw new TablerosApiException('server', 'No se pudo guardar el sticker.', 500); }
        $copied = stream_copy_to_stream($input, $output); fclose($input); fclose($output); @chmod($path, 0600);
        if ($copied !== filesize($source)) { @unlink($path); throw new TablerosApiException('server', 'No se pudo guardar el sticker completo.', 500); }
        return $path;
    }

    public function getFileComments(int $fileId): array {
        $file = $this->one('SELECT id, board_id, item_id FROM tb_file WHERE id = ? AND deleted_at IS NULL', [$fileId]);
        if (!$file) {
            throw new TablerosApiException('not_found', 'No se encontró el archivo.', 404);
        }
        $comments = $this->all(
            "SELECT TOP (200) c.id, c.file_id, c.body, c.created_by,
                    COALESCE(NULLIF(LTRIM(RTRIM(u.Nombre)), ''), NULLIF(LTRIM(RTRIM(u.Usuario)), '')) AS created_by_display_name,
                    c.created_at, c.updated_at, c.mentions_json
             FROM tb_file_comment c
             LEFT JOIN [TG].[dbo].[Usuario] u ON u.Id = c.created_by
             WHERE c.file_id = ? AND c.deleted_at IS NULL
             ORDER BY c.created_at, c.id",
            [$fileId]
        );
        $commentIds = array_map(static fn(array $comment): int => (int)$comment['id'], $comments);
        $stickerByComment = [];
        if ($commentIds) {
            $marks = implode(',', array_fill(0, count($commentIds), '?'));
            $stickers = $this->all(
                "SELECT l.file_comment_id, s.id, s.name, s.content_type, s.user_id AS created_by,
                        COALESCE(NULLIF(LTRIM(RTRIM(u.Nombre)), ''), NULLIF(LTRIM(RTRIM(u.Usuario)), '')) AS created_by_display_name
                 FROM tb_file_comment_sticker l
                 INNER JOIN tb_user_sticker s ON s.id = l.sticker_id
                 LEFT JOIN [TG].[dbo].[Usuario] u ON u.Id = s.user_id
                 WHERE l.file_comment_id IN ($marks) ORDER BY l.created_at, s.id",
                $commentIds
            );
            foreach ($stickers as $sticker) {
                $stickerByComment[(string)$sticker['file_comment_id']][] = [
                    'id' => (int)$sticker['id'], 'name' => (string)$sticker['name'],
                    'created_by' => (int)$sticker['created_by'],
                    'created_by_display_name' => (string)($sticker['created_by_display_name'] ?? ''),
                    'url' => '/tableros-api/sticker-file/' . (int)$sticker['id'],
                ];
            }
        }
        foreach ($comments as &$comment) {
            $comment['mentions'] = json_decode((string)($comment['mentions_json'] ?? '[]'), true) ?: [];
            unset($comment['mentions_json']);
            $comment['stickers'] = $stickerByComment[(string)$comment['id']] ?? [];
            $comment['attachments'] = $this->all(
                'SELECT f.id AS file_id, f.name, a.created_at FROM tb_file_comment_attachment a
                 INNER JOIN tb_file f ON f.id = a.file_id AND f.board_id = ? AND f.item_id = ? AND f.deleted_at IS NULL
                 WHERE a.file_comment_id = ? ORDER BY a.created_at, f.id',
                [(int)$file['board_id'], (int)$file['item_id'], (int)$comment['id']]
            );
        }
        unset($comment);
        return $comments;
    }

    public function addFileComment(int $fileId, int $userId, array $input): array {
        $stickerIds = $input['stickers'] ?? [];
        if (!is_array($stickerIds) || count($stickerIds) > 20) {
            throw new TablerosApiException('validation', 'La lista de stickers no es válida.', 422);
        }
        $stickerIds = array_values(array_unique(array_map(fn($id): int => $this->positiveInt($id, 'stickers'), $stickerIds)));
        if (array_key_exists('body', $input) && $input['body'] !== null && !is_scalar($input['body'])) {
            throw new TablerosApiException('validation', 'El campo body no es válido.', 422);
        }
        $body = isset($input['body']) && trim((string)$input['body']) !== ''
            ? $this->requiredString($input, 'body', 10000) : '';
        if ($body === '' && !$stickerIds) throw new TablerosApiException('validation', 'Escribe un comentario o agrega un sticker.', 422);
        $mentions = $input['mentions'] ?? [];
        if (!is_array($mentions) || count($mentions) > 100) {
            throw new TablerosApiException('validation', 'La lista de menciones no es válida.', 422);
        }
        $mentionIds = [];
        foreach ($mentions as $mention) $mentionIds[] = $this->positiveInt($mention, 'mentions');
        $mentionIds = array_values(array_unique($mentionIds));
        return $this->transaction(function () use ($fileId, $userId, $body, $mentionIds, $stickerIds): array {
            $file = $this->one('SELECT id, board_id, item_id, name, created_by FROM tb_file WITH (UPDLOCK, HOLDLOCK) WHERE id = ? AND deleted_at IS NULL', [$fileId]);
            if (!$file) {
                throw new TablerosApiException('not_found', 'No se encontró el archivo.', 404);
            }
            if ($stickerIds) {
                $marks = implode(',', array_fill(0, count($stickerIds), '?'));
                $active = $this->all("SELECT id FROM tb_user_sticker WHERE id IN ($marks) AND deleted_at IS NULL", $stickerIds);
                if (count($active) !== count($stickerIds)) throw new TablerosApiException('validation', 'Uno o más stickers ya no están disponibles.', 422);
            }
            $recipients = $this->validatedMentionRecipients((int)$file['board_id'], $mentionIds, $userId);
            $id = $this->insertId(
                'INSERT INTO tb_file_comment (file_id, body, created_by, mentions_json, created_at, updated_at)
                 OUTPUT INSERTED.id VALUES (?, ?, ?, ?, GETDATE(), GETDATE())',
                [$fileId, $body, $userId, json_encode($mentionIds, JSON_UNESCAPED_UNICODE)]
            );
            foreach ($stickerIds as $stickerId) {
                $this->execute('INSERT INTO tb_file_comment_sticker (file_comment_id, sticker_id, created_by, created_at) VALUES (?, ?, ?, GETDATE())', [$id, $stickerId, $userId]);
            }
            $comment = $this->one(
                "SELECT c.id, c.file_id, c.body, c.created_by,
                        COALESCE(NULLIF(LTRIM(RTRIM(u.Nombre)), ''), NULLIF(LTRIM(RTRIM(u.Usuario)), '')) AS created_by_display_name,
                        c.created_at, c.updated_at
                 FROM tb_file_comment c
                 LEFT JOIN [TG].[dbo].[Usuario] u ON u.Id = c.created_by
                 WHERE c.id = ? AND c.deleted_at IS NULL",
                [$id]
            );
            if (!$comment) {
                throw new RuntimeException('No se pudo recuperar el comentario recién creado.');
            }
            $comment['mentions'] = $mentionIds;
            $comment['sticker_ids'] = $stickerIds;
            $notificationRecipients = [];
            $uploaderId = (int)$file['created_by'];
            if ($uploaderId > 0 && $uploaderId !== $userId) $notificationRecipients[$uploaderId] = 'file.comment.created';
            foreach ($recipients as $recipientId) {
                // A mention is the more useful signal when the same person also uploaded the file.
                $notificationRecipients[$recipientId] = 'file.comment.mention';
            }
            foreach ($notificationRecipients as $recipientId => $eventType) {
                $this->createNotification((int)$recipientId, $userId, (int)$file['board_id'], (int)$file['item_id'], $fileId, null, $id, $eventType, ['file_name' => (string)($file['name'] ?? '')], 'file-comment:' . $id . ':recipient:' . $recipientId);
            }
            return $comment;
        });
    }

    public function attachCommentFile(int $commentId, int $fileId): int {
        return $this->transaction(function () use ($commentId, $fileId): int {
            $comment = $this->one('SELECT c.file_id, f.board_id, f.item_id FROM tb_file_comment c INNER JOIN tb_file f ON f.id = c.file_id WHERE c.id = ? AND c.deleted_at IS NULL AND f.deleted_at IS NULL', [$commentId]);
            if (!$comment) throw new TablerosApiException('not_found', 'No se encontró el comentario.', 404);
            $file = $this->one('SELECT id FROM tb_file WHERE id = ? AND board_id = ? AND item_id = ? AND deleted_at IS NULL', [$fileId, $comment['board_id'], $comment['item_id']]);
            if (!$file) throw new TablerosApiException('validation', 'El archivo debe pertenecer al mismo tablero y elemento del comentario.', 422);
            $this->execute('IF NOT EXISTS (SELECT 1 FROM tb_file_comment_attachment WHERE file_comment_id = ? AND file_id = ?) INSERT INTO tb_file_comment_attachment (file_comment_id, file_id) VALUES (?, ?)', [$commentId, $fileId, $commentId, $fileId]);
            return (int)$comment['board_id'];
        });
    }

    public function getFileCommentBoardId(int $commentId): int {
        $row = $this->one('SELECT f.board_id FROM tb_file_comment c INNER JOIN tb_file f ON f.id = c.file_id WHERE c.id = ? AND c.deleted_at IS NULL AND f.deleted_at IS NULL', [$commentId]);
        if (!$row) throw new TablerosApiException('not_found', 'No se encontró el comentario.', 404);
        return (int)$row['board_id'];
    }

    public function getNotifications(int $userId): array {
        $rows = $this->all("SELECT TOP (200) n.id, n.actor_user_id, COALESCE(NULLIF(LTRIM(RTRIM(u.Nombre)), ''), NULLIF(LTRIM(RTRIM(u.Usuario)), '')) AS actor_display_name,
                    n.board_id, n.item_id, n.file_id, n.comment_id, n.file_comment_id, n.event_type, n.payload_json, n.created_at, n.read_at
             FROM tb_notification n LEFT JOIN [TG].[dbo].[Usuario] u ON u.Id = n.actor_user_id
             WHERE n.user_id = ? ORDER BY n.created_at DESC, n.id DESC", [$userId]);
        $rows = array_values(array_filter($rows, fn(array $row): bool => !empty($row['board_id']) && $this->access->canViewBoard((int)$row['board_id'])));
        foreach ($rows as &$row) $row['payload'] = json_decode((string)($row['payload_json'] ?? '{}'), true) ?: [];
        unset($row);
        $notificationRows = [];
        $commentNotificationIndexes = [];
        foreach ($rows as $row) {
            $commentId = $row['file_comment_id'] ?? null;
            $isFileCommentNotification = $commentId !== null && in_array($row['event_type'], ['file.comment.created', 'file.comment.mention'], true);
            if (!$isFileCommentNotification) {
                $notificationRows[] = $row;
                continue;
            }
            $key = (string)$commentId;
            if (!array_key_exists($key, $commentNotificationIndexes)) {
                $commentNotificationIndexes[$key] = count($notificationRows);
                $notificationRows[] = $row;
            } else {
                $existingIndex = $commentNotificationIndexes[$key];
                $existing = $notificationRows[$existingIndex];
                $rowIsUnread = empty($row['read_at']);
                $existingIsUnread = empty($existing['read_at']);
                if (($rowIsUnread && !$existingIsUnread)
                    || ($rowIsUnread === $existingIsUnread && $row['event_type'] === 'file.comment.mention' && $existing['event_type'] !== 'file.comment.mention')) {
                    $notificationRows[$existingIndex] = $row;
                }
            }
        }
        $rows = array_slice($notificationRows, 0, 100);

        $unreadBoards = $this->all('SELECT DISTINCT board_id FROM tb_notification WHERE user_id = ? AND read_at IS NULL AND board_id IS NOT NULL', [$userId]);
        $accessibleBoardIds = [];
        foreach ($unreadBoards as $board) {
            $boardId = (int)$board['board_id'];
            if ($this->access->canViewBoard($boardId)) $accessibleBoardIds[] = $boardId;
        }
        $unreadCount = 0;
        if ($accessibleBoardIds) {
            $placeholders = implode(',', array_fill(0, count($accessibleBoardIds), '?'));
            $unreadCount = (int)($this->one(
                "SELECT COUNT(*) AS total FROM (
                    SELECT CASE
                        WHEN file_comment_id IS NOT NULL AND event_type IN ('file.comment.created', 'file.comment.mention') THEN 'file-comment:' + CAST(file_comment_id AS NVARCHAR(20))
                        ELSE 'notification:' + CAST(id AS NVARCHAR(20))
                    END AS notification_key
                    FROM tb_notification
                    WHERE user_id = ? AND read_at IS NULL AND board_id IN ($placeholders)
                    GROUP BY CASE
                        WHEN file_comment_id IS NOT NULL AND event_type IN ('file.comment.created', 'file.comment.mention') THEN 'file-comment:' + CAST(file_comment_id AS NVARCHAR(20))
                        ELSE 'notification:' + CAST(id AS NVARCHAR(20))
                    END
                ) unread_notifications",
                array_merge([$userId], $accessibleBoardIds)
            )['total'] ?? 0);
        }
        return ['items' => $rows, 'unread_count' => $unreadCount];
    }

    public function markNotificationRead(int $userId, int $notificationId): array {
        $notification = $this->one('SELECT file_comment_id, event_type FROM tb_notification WHERE id = ? AND user_id = ?', [$notificationId, $userId]);
        if ($notification && !empty($notification['file_comment_id']) && in_array($notification['event_type'], ['file.comment.created', 'file.comment.mention'], true)) {
            $this->execute(
                "UPDATE tb_notification SET read_at = SYSUTCDATETIME()
                 WHERE user_id = ? AND read_at IS NULL AND file_comment_id = ?
                   AND event_type IN ('file.comment.created', 'file.comment.mention')",
                [$userId, $notification['file_comment_id']]
            );
        } else {
            $this->execute('UPDATE tb_notification SET read_at = SYSUTCDATETIME() WHERE id = ? AND user_id = ? AND read_at IS NULL', [$notificationId, $userId]);
        }
        return ['notification_id' => $notificationId, 'read' => true];
    }

    private function validatedMentionRecipients(int $boardId, array $ids, int $actorId): array {
        if (!$ids) return [];
        $users = $this->access->activeUsersForBoard($boardId, '', $ids);
        $byId = [];
        foreach ($users as $user) $byId[(int)$user['id']] = $user;
        foreach ($ids as $id) {
            if (!isset($byId[$id]) || !$this->userIsActive($id)) throw new TablerosApiException('validation', 'Las menciones deben ser usuarios activos con acceso al tablero.', 422);
        }
        return array_values(array_filter($ids, static fn(int $id): bool => $id !== $actorId));
    }

    private function createNotification(int $recipientId, int $actorId, int $boardId, ?int $itemId, ?int $fileId, ?int $commentId, ?int $fileCommentId, string $type, array $payload, string $dedupe): void {
        if ($recipientId <= 0 || $recipientId === $actorId) return;
        $this->execute('IF NOT EXISTS (SELECT 1 FROM tb_notification WHERE user_id = ? AND dedupe_key = ?) INSERT INTO tb_notification (user_id, actor_user_id, board_id, item_id, file_id, comment_id, file_comment_id, event_type, payload_json, created_at, dedupe_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, SYSUTCDATETIME(), ?)', [$recipientId, $dedupe, $recipientId, $actorId, $boardId, $itemId, $fileId, $commentId, $fileCommentId, $type, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $dedupe]);
    }

    public function uploadFile(int $boardId, int $userId, array $input, array $upload): array {
        $itemId = $this->optionalPositiveInt($input['item_id'] ?? null, 'item_id');
        $fileId = $this->optionalPositiveInt($input['file_id'] ?? null, 'file_id');
        $columnId = $this->optionalPositiveInt($input['column_id'] ?? null, 'column_id');
        if ($fileId !== null && $itemId === null) {
            $existingFile = $this->one(
                'SELECT item_id FROM tb_file WHERE id = ? AND board_id = ? AND deleted_at IS NULL',
                [$fileId, $boardId]
            );
            if (!$existingFile) {
                throw new TablerosApiException('not_found', 'No se encontró el archivo en este tablero.', 404);
            }
            $itemId = (int)$existingFile['item_id'];
        }
        if ($itemId === null) {
            throw new TablerosApiException('validation', 'item_id es obligatorio para crear un archivo.', 422);
        }
        $this->requireItemOnBoard($itemId, $boardId);
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($upload['tmp_name'])) {
            throw new TablerosApiException('validation', 'No se recibió un archivo válido.', 422);
        }
        $cliImportKey = $upload['_tableros_import_object_key'] ?? null;
        $trustedCliImport = PHP_SAPI === 'cli' && is_string($cliImportKey) && $cliImportKey !== '';
        if (!is_uploaded_file((string)$upload['tmp_name']) && !$trustedCliImport) {
            throw new TablerosApiException('validation', 'El archivo no proviene de una carga HTTP válida.', 422);
        }
        $size = (int)($upload['size'] ?? 0);
        $actualSize = filesize((string)$upload['tmp_name']);
        if ($size <= 0 || $actualSize === false || $actualSize !== $size || $size > 500 * 1024 * 1024) {
            throw new TablerosApiException('validation', 'El archivo debe pesar entre 1 byte y 500 MB.', 422);
        }
        $originalName = $this->safeFilename((string)($upload['name'] ?? 'archivo'));
        [$extension, $contentType] = $this->validateUploadedFile((string)$upload['tmp_name'], $originalName);
        $processingPath = (string)$upload['tmp_name'];
        // Board attachments retain their original bytes, filename, and detected MIME type.
        $scanStatus = 'unscanned';
        $scannedAt = null;
        $useNasService = $this->nasServiceEnabled();
        $storageRoot = $useNasService ? null : $this->privateStorageRoot();
        $now = new DateTimeImmutable('now');
        $objectHash = bin2hex(random_bytes(32));
        $objectKey = implode('/', [(string)$boardId, (string)$itemId, $now->format('Y'), $now->format('m'), substr($objectHash, 0, 2), $objectHash]);
        if ($trustedCliImport) {
            if (!preg_match('~^' . preg_quote((string)$boardId, '~') . '/' . preg_quote((string)$itemId, '~') . '/[0-9]{4}/(?:0[1-9]|1[0-2])/([a-f0-9]{2})/([a-f0-9]{64})$~', $cliImportKey, $keyMatch)
                || $keyMatch[1] !== substr($keyMatch[2], 0, 2)) {
                throw new TablerosApiException('validation', 'La clave determinista del archivo importado no es válida.', 422);
            }
            $objectKey = $cliImportKey;
            $objectHash = $keyMatch[2];
        }
        $destination = null;
        if ($useNasService) {
            try {
                $this->nasServiceRequest('PUT', $objectKey, $processingPath, $size, $contentType);
            } catch (TablerosApiException $putError) {
                if (!$trustedCliImport || $putError->apiCode !== 'conflict') throw $putError;
                $existing = tempnam(sys_get_temp_dir(), 'tableros-import-verify-');
                if ($existing === false) throw new TablerosApiException('server', 'No se pudo verificar el archivo importado existente.', 500);
                try {
                    $this->nasServiceRequest('GET', $objectKey, null, null, null, $existing, 500 * 1024 * 1024);
                    if (filesize($existing) !== $size || !hash_equals((string)hash_file('sha256', $processingPath), (string)hash_file('sha256', $existing))) {
                        throw new TablerosApiException('conflict', 'La clave idempotente del archivo ya contiene bytes distintos.', 409);
                    }
                } finally { @unlink($existing); }
            }
        } else {
        $storageDir = $storageRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, dirname($objectKey));
        if (!is_dir($storageDir) && !@mkdir($storageDir, 0700, true) && !is_dir($storageDir)) {
            throw new TablerosApiException('server', 'No se pudo preparar el almacenamiento privado de archivos.', 500);
        }
        $securedDir = $storageDir;
        while ($securedDir !== $storageRoot && $this->pathIsWithin($securedDir, $storageRoot)) {
            @chmod($securedDir, 0700);
            $securedDir = dirname($securedDir);
        }
        if (!is_writable($storageDir)) {
            throw new TablerosApiException('server', 'El almacenamiento privado de archivos no tiene permisos de escritura.', 500);
        }
        $destination = $storageRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $objectKey);
        $target = @fopen($destination, 'xb');
        if (!is_resource($target) && $trustedCliImport && is_file($destination)) {
            if (filesize($destination) !== $size || !hash_equals((string)hash_file('sha256', $processingPath), (string)hash_file('sha256', $destination))) {
                throw new TablerosApiException('conflict', 'La clave idempotente local ya contiene bytes distintos.', 409);
            }
            $target = false;
        }
        $source = @fopen($processingPath, 'rb');
        if (($target !== false && !is_resource($target)) || !is_resource($source)) {
            if (is_resource($target)) fclose($target);
            if (is_resource($source)) fclose($source);
            @unlink($destination);
            throw new TablerosApiException('server', 'No se pudo guardar el archivo en almacenamiento privado.', 500);
        }
        $copied = $target === false ? $size : stream_copy_to_stream($source, $target);
        fclose($source);
        if (is_resource($target)) fclose($target);
        if ($copied !== $size) {
            @unlink($destination);
            throw new TablerosApiException('server', 'No se pudo guardar el archivo completo.', 500);
        }
        @chmod($destination, 0600);
        }
        $thumbnailKey = null;
        $previewKey = null;
        if (str_starts_with($contentType, 'image/')) {
            $thumbnailKey = $this->thumbnailObjectKey($objectKey);
            try {
                $this->createStoredThumbnail($processingPath, $objectKey, $thumbnailKey, $useNasService);
                $previewKey = $this->imagePreviewObjectKey($objectKey);
                $this->createStoredImagePreview($processingPath, $previewKey, $useNasService);
            } catch (Throwable $derivativeError) {
                foreach ([$thumbnailKey, $previewKey] as $derivativeKey) {
                    if ($derivativeKey === null) continue;
                    try { $this->deleteStoredObject($derivativeKey, $useNasService); } catch (Throwable $cleanupError) { error_log('Tableros image derivative cleanup failed: ' . $cleanupError->getMessage()); }
                }
                if ($useNasService && !$trustedCliImport) {
                    try { $this->nasServiceRequest('DELETE', $objectKey); } catch (Throwable $cleanupError) { error_log('Tableros NAS cleanup failed: ' . $cleanupError->getMessage()); }
                } elseif (!$useNasService && !$trustedCliImport && $destination !== null) @unlink($destination);
                throw $derivativeError;
            }
        }
        $sourceHash = hash_file('sha256', $processingPath, true);
        if ($sourceHash === false) {
            foreach ([$thumbnailKey, $previewKey] as $derivativeKey) {
                if ($derivativeKey === null) continue;
                try { $this->deleteStoredObject($derivativeKey, $useNasService); } catch (Throwable $cleanupError) { error_log('Tableros image derivative cleanup failed: ' . $cleanupError->getMessage()); }
            }
            if ($useNasService) $this->nasServiceRequest('DELETE', $objectKey);
            elseif ($destination !== null) @unlink($destination);
            throw new TablerosApiException('server', 'No se pudo verificar el archivo cargado.', 500);
        }

        try {
            $result = $this->transaction(function () use ($boardId, $userId, $itemId, $fileId, $columnId, $originalName, $size, $contentType, $objectKey, $scanStatus, $scannedAt, $useNasService, $sourceHash): array {
                if ($fileId !== null) {
                    $file = $this->one(
                        'SELECT id, board_id, item_id, column_id, name FROM tb_file WITH (UPDLOCK, HOLDLOCK)
                         WHERE id = ? AND board_id = ? AND deleted_at IS NULL',
                        [$fileId, $boardId]
                    );
                    if (!$file) {
                        throw new TablerosApiException('not_found', 'No se encontró el archivo en este tablero.', 404);
                    }
                    if ($itemId !== null && (int)$file['item_id'] !== $itemId) {
                        throw new TablerosApiException('validation', 'El archivo no pertenece al elemento indicado.', 422);
                    }
                    if ($columnId !== null && (int)$file['column_id'] !== $columnId) {
                        throw new TablerosApiException('validation', 'El archivo no pertenece a la columna indicada.', 422);
                    }
                    $itemId = (int)$file['item_id'];
                    $columnId = $file['column_id'] === null ? null : (int)$file['column_id'];
                } else {
                    if ($itemId === null) {
                        throw new TablerosApiException('validation', 'item_id es obligatorio para crear un archivo.', 422);
                    }
                    $this->requireItemOnBoard($itemId, $boardId);
                    if ($columnId !== null) {
                        $column = $this->one('SELECT id, type FROM tb_column WHERE id = ? AND board_id = ? AND deleted_at IS NULL', [$columnId, $boardId]);
                        if (!$column || (string)$column['type'] !== 'file') {
                            throw new TablerosApiException('validation', 'La columna debe ser de tipo archivo y pertenecer al tablero.', 422);
                        }
                    }
                    $fileId = $this->insertId(
                        'INSERT INTO tb_file (board_id, item_id, column_id, name, created_by, created_at, updated_at)
                         OUTPUT INSERTED.id VALUES (?, ?, ?, ?, ?, GETDATE(), GETDATE())',
                        [$boardId, $itemId, $columnId, $originalName, $userId]
                    );
                }

                $versionRow = $this->one(
                    'SELECT COALESCE(MAX(version_number), 0) + 1 AS next_version FROM tb_file_version WITH (UPDLOCK, HOLDLOCK) WHERE file_id = ?',
                    [$fileId]
                );
                $versionNumber = (int)($versionRow['next_version'] ?? 1);
                $versionId = $this->insertId(
                    'INSERT INTO tb_file_version (file_id, version_number, original_name, content_type, byte_size,
                         storage_provider, storage_container, storage_object_key, sha256_hash, scan_status, scanned_at, created_by, created_at)
                     OUTPUT INSERTED.id VALUES (?, ?, ?, ?, ?, ?, ?, ?, CONVERT(VARBINARY(32), ?, 2), ?, ?, ?, GETDATE())',
                    [$fileId, $versionNumber, $originalName, $contentType, $size, $useNasService ? 'nas_service' : 'local', 'tableros', $objectKey, bin2hex($sourceHash), $scanStatus, $scannedAt, $userId]
                );
                $this->execute('UPDATE tb_file SET current_version_id = ?, name = ?, updated_at = GETDATE() WHERE id = ? AND board_id = ?', [$versionId, $originalName, $fileId, $boardId]);
                $this->writeActivity($boardId, $itemId, $userId, 'file.uploaded', [
                    'file_id' => $fileId, 'file_version_id' => $versionId, 'version_number' => $versionNumber,
                    'name' => $originalName, 'byte_size' => $size,
                ]);
                return [
                    'id' => $fileId, 'board_id' => $boardId, 'item_id' => $itemId, 'column_id' => $columnId,
                    'version_id' => $versionId, 'version_number' => $versionNumber, 'name' => $originalName,
                    'content_type' => $contentType, 'byte_size' => $size, 'scan_status' => $scanStatus,
                ];
            });
            return $result;
        } catch (Throwable $e) {
            if ($thumbnailKey !== null) {
                try { $this->deleteStoredObject($thumbnailKey, $useNasService); } catch (Throwable $cleanupError) { error_log('Tableros thumbnail cleanup failed: ' . $cleanupError->getMessage()); }
            }
            if ($previewKey !== null) {
                try { $this->deleteStoredObject($previewKey, $useNasService); } catch (Throwable $cleanupError) { error_log('Tableros preview cleanup failed: ' . $cleanupError->getMessage()); }
            }
            if ($useNasService && !$trustedCliImport) {
                try { $this->nasServiceRequest('DELETE', $objectKey); } catch (Throwable $cleanupError) { error_log('Tableros NAS cleanup failed: ' . $cleanupError->getMessage()); }
            } elseif (!$useNasService && !$trustedCliImport && $destination !== null) @unlink($destination);
            throw $e;
        }
    }

    public function getFileVersions(int $boardId, int $fileId): array {
        $file = $this->one('SELECT id FROM tb_file WHERE id = ? AND board_id = ? AND deleted_at IS NULL', [$fileId, $boardId]);
        if (!$file) {
            throw new TablerosApiException('not_found', 'No se encontró el archivo en este tablero.', 404);
        }
        return $this->all(
            "SELECT v.id, v.file_id, v.version_number, v.original_name, v.content_type, v.byte_size,
                    v.scan_status, v.scanned_at, v.created_by,
                    COALESCE(NULLIF(LTRIM(RTRIM(u.Nombre)), ''), NULLIF(LTRIM(RTRIM(u.Usuario)), '')) AS created_by_display_name,
                    v.created_at,
                    CASE WHEN f.current_version_id = v.id THEN 1 ELSE 0 END AS is_current
             FROM tb_file_version v
             INNER JOIN tb_file f ON f.id = v.file_id AND f.board_id = ?
             LEFT JOIN [TG].[dbo].[Usuario] u ON u.Id = v.created_by
             WHERE v.file_id = ? AND v.deleted_at IS NULL ORDER BY v.version_number DESC",
            [$boardId, $fileId]
        );
    }

    public function getFileBoardId(int $fileId): int {
        $file = $this->one('SELECT board_id FROM tb_file WHERE id = ? AND deleted_at IS NULL', [$fileId]);
        if (!$file) {
            throw new TablerosApiException('not_found', 'No se encontró el archivo.', 404);
        }
        return (int)$file['board_id'];
    }

    /** The caller must authorize the returned board before streaming the path. */
    public function getFileDownload(int $fileId, ?int $versionId = null, ?string $rangeHeader = null): array {
        $file = $this->one(
            'SELECT id, board_id, item_id, name, current_version_id FROM tb_file WHERE id = ? AND deleted_at IS NULL',
            [$fileId]
        );
        if (!$file) {
            throw new TablerosApiException('not_found', 'No se encontró el archivo.', 404);
        }
        $wantedVersion = $versionId ?? (int)$file['current_version_id'];
        if ($wantedVersion <= 0) {
            throw new TablerosApiException('not_found', 'El archivo no tiene una versión disponible.', 404);
        }
        $version = $this->one(
            'SELECT id, file_id, version_number, original_name, content_type, byte_size, storage_provider, storage_container, storage_object_key, scan_status,
                    CONVERT(VARCHAR(64), sha256_hash, 2) AS sha256_hex
             FROM tb_file_version WHERE id = ? AND file_id = ? AND deleted_at IS NULL',
            [$wantedVersion, $fileId]
        );
        if (!$version || !in_array((string)$version['storage_provider'], ['local', 'nas_service'], true) || (string)$version['storage_container'] !== 'tableros') {
            throw new TablerosApiException('not_found', 'No se encontró la versión del archivo.', 404);
        }
        if (!in_array((string)$version['scan_status'], ['clean', 'unscanned'], true)) {
            throw new TablerosApiException('conflict', 'Esta versión no está disponible para descarga.', 409);
        }
        $isVideo = in_array(strtolower((string)$version['content_type']), ['video/mp4', 'video/webm'], true);
        if (!$isVideo) $rangeHeader = null;
        $range = $rangeHeader === null ? null : $this->parseFileByteRange($rangeHeader, (int)$version['byte_size']);
        $base = [
            'file_id' => (int)$file['id'], 'board_id' => (int)$file['board_id'], 'item_id' => (int)$file['item_id'],
            'version_id' => (int)$version['id'], 'version_number' => (int)$version['version_number'],
            'name' => (string)$version['original_name'], 'content_type' => (string)$version['content_type'],
            'byte_size' => (int)$version['byte_size'],
        ];
        if ($rangeHeader !== null && $range === false) return $base + ['range_unsatisfiable' => true];
        $key = (string)$version['storage_object_key'];
        if ((string)$version['storage_provider'] === 'nas_service') {
            $tempPath = tempnam(sys_get_temp_dir(), 'tableros-');
            if ($tempPath === false) throw new TablerosApiException('server', 'No se pudo preparar la descarga del archivo.', 500);
            try {
                if ($range !== null) {
                    [$rangeStart, $rangeEnd] = $range;
                    $this->nasServiceRequest('GET', $key, null, null, null, $tempPath, $rangeEnd - $rangeStart + 1, [$rangeStart, $rangeEnd], (int)$version['byte_size']);
                    $actualSize = filesize($tempPath);
                    if ($actualSize !== $rangeEnd - $rangeStart + 1) {
                        throw new TablerosApiException('server', 'El rango recibido del almacenamiento no coincide con lo solicitado.', 502);
                    }
                    return $base + ['path' => $tempPath, 'temporary' => true, 'range' => $range];
                }
                $this->nasServiceRequest('GET', $key, null, null, null, $tempPath, (int)$version['byte_size']);
                $actualSize = filesize($tempPath);
                $actualHash = hash_file('sha256', $tempPath);
                if ($actualSize !== (int)$version['byte_size'] || !is_string($actualHash)
                    || !hash_equals(strtolower((string)$version['sha256_hex']), strtolower($actualHash))) {
                    throw new TablerosApiException('server', 'El archivo almacenado no coincide con sus datos de integridad.', 500);
                }
                return $base + ['path' => $tempPath, 'temporary' => true];
            } catch (Throwable $e) { @unlink($tempPath); throw $e; }
        }
        $path = $this->privateObjectPath($key);
        if (!is_file($path) || !is_readable($path)) {
            throw new TablerosApiException('not_found', 'El archivo no está disponible en el almacenamiento privado.', 404);
        }
        return $base + ['path' => $path, 'range' => $range];
    }

    /** Returns the WebP thumbnail created during upload/import. */
    public function getFileThumbnail(int $fileId, ?int $versionId = null): array {
        $file = $this->one('SELECT id, board_id, item_id, name, current_version_id FROM tb_file WHERE id = ? AND deleted_at IS NULL', [$fileId]);
        if (!$file) throw new TablerosApiException('not_found', 'No se encontró el archivo.', 404);
        $wantedVersion = $versionId ?? (int)$file['current_version_id'];
        $row = $this->one(
            'SELECT id AS version_id, file_id, version_number, original_name, content_type, byte_size, storage_provider, storage_container, storage_object_key, scan_status,
                    CONVERT(VARCHAR(64), sha256_hash, 2) AS sha256_hex
             FROM tb_file_version WHERE id = ? AND file_id = ? AND deleted_at IS NULL',
            [$wantedVersion, $fileId]
        );
        if (!$row || !in_array((string)$row['storage_provider'], ['local', 'nas_service'], true) || (string)$row['storage_container'] !== 'tableros') {
            throw new TablerosApiException('not_found', 'No se encontró la versión del archivo.', 404);
        }
        if (!in_array((string)$row['scan_status'], ['clean', 'unscanned'], true)) throw new TablerosApiException('conflict', 'Esta versión no está disponible para miniatura.', 409);
        $type = strtolower((string)$row['content_type']);
        if (!in_array($type, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) throw new TablerosApiException('validation', 'Este archivo no es una imagen compatible.', 415);
        $key = (string)$row['storage_object_key'];
        $thumbKey = $this->thumbnailObjectKey($key);
        $useNas = (string)$row['storage_provider'] === 'nas_service';
        $cachedPath = $this->readStoredThumbnail($thumbKey, $useNas);
        if ($cachedPath !== null) return ['path' => $cachedPath, 'temporary' => $useNas, 'content_type' => 'image/webp', 'byte_size' => (int)filesize($cachedPath)];
        throw new TablerosApiException('not_found', 'La miniatura WebP no existe; debe regenerarse al importar o volver a cargar el archivo.', 404);
    }

    /** Returns a cached, larger WebP derivative for the full image viewer. */
    public function getFileImagePreview(int $fileId, ?int $versionId = null): array {
        $file = $this->one('SELECT id, board_id, item_id, name, current_version_id FROM tb_file WHERE id = ? AND deleted_at IS NULL', [$fileId]);
        if (!$file) throw new TablerosApiException('not_found', 'No se encontró el archivo.', 404);
        $wantedVersion = $versionId ?? (int)$file['current_version_id'];
        $row = $this->one(
            'SELECT id AS version_id, file_id, version_number, original_name, content_type, byte_size, storage_provider, storage_container, storage_object_key, scan_status
             FROM tb_file_version WHERE id = ? AND file_id = ? AND deleted_at IS NULL',
            [$wantedVersion, $fileId]
        );
        if (!$row || !in_array((string)$row['storage_provider'], ['local', 'nas_service'], true) || (string)$row['storage_container'] !== 'tableros') {
            throw new TablerosApiException('not_found', 'No se encontró la versión del archivo.', 404);
        }
        if (!in_array((string)$row['scan_status'], ['clean', 'unscanned'], true)) throw new TablerosApiException('conflict', 'Esta versión no está disponible para vista previa.', 409);
        if (!in_array(strtolower((string)$row['content_type']), ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) throw new TablerosApiException('validation', 'Este archivo no es una imagen compatible.', 415);
        $key = (string)$row['storage_object_key'];
        $previewKey = $this->imagePreviewObjectKey($key);
        $useNas = (string)$row['storage_provider'] === 'nas_service';
        $cachedPath = $this->readStoredThumbnail($previewKey, $useNas, 20 * 1024 * 1024);
        if ($cachedPath !== null) return ['path' => $cachedPath, 'temporary' => $useNas, 'content_type' => 'image/webp', 'byte_size' => (int)filesize($cachedPath)];
        throw new TablerosApiException('not_found', 'La vista previa WebP no existe; debe regenerarse al importar o volver a cargar el archivo.', 404);
    }

    private function imagePreviewObjectKey(string $originalKey): string {
        if (preg_match('/^[a-f0-9]{64}$/', $originalKey)) {
            $hash = hash('sha256', 'tableros-image-preview-webp-v1:' . $originalKey);
            return '1/1/1970/01/' . substr($hash, 0, 2) . '/' . $hash;
        }
        if (!preg_match('~^([1-9][0-9]*/[1-9][0-9]*/[0-9]{4}/(?:0[1-9]|1[0-2]))/[a-f0-9]{2}/[a-f0-9]{64}$~', $originalKey, $m)) {
            throw new TablerosApiException('not_found', 'No se encontró el archivo.', 404);
        }
        $hash = hash('sha256', 'tableros-image-preview-webp-v1:' . $originalKey);
        return $m[1] . '/' . substr($hash, 0, 2) . '/' . $hash;
    }

    private function ensureStoredImagePreview(string $sourcePath, string $previewKey, bool $useNas): string {
        $lockPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tableros-preview-' . hash('sha256', $previewKey) . '.lock';
        $lock = @fopen($lockPath, 'c');
        if (!is_resource($lock)) throw new TablerosApiException('server', 'No se pudo bloquear la generación de vista previa.', 500);
        if (!flock($lock, LOCK_EX)) { fclose($lock); throw new TablerosApiException('server', 'No se pudo bloquear la generación de vista previa.', 500); }
        try {
            $existing = $this->readStoredThumbnail($previewKey, $useNas, 20 * 1024 * 1024);
            if ($existing !== null) return $existing;
            $webp = $this->makeWebpImagePreview($sourcePath);
            if ($webp === null) throw new TablerosApiException('server', 'La generación de vista previa WebP no está disponible en este servidor.', 503);
            try {
                if ($useNas) $this->nasServiceRequest('PUT', $previewKey, $webp, (int)filesize($webp), 'image/webp');
                else $this->writePrivateObject($previewKey, $webp);
            } catch (Throwable $writeError) {
                $existing = $this->readStoredThumbnail($previewKey, $useNas, 20 * 1024 * 1024);
                if ($existing === null) throw $writeError;
                return $existing;
            } finally { @unlink($webp); }
            return $this->readStoredThumbnail($previewKey, $useNas, 20 * 1024 * 1024) ?? throw new TablerosApiException('server', 'No se pudo leer la vista previa guardada.', 500);
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function thumbnailObjectKey(string $originalKey): string {
        // Keep legacy hash-only originals addressable while deriving a key that
        // still matches the hierarchical format accepted by storage services.
        if (preg_match('/^[a-f0-9]{64}$/', $originalKey)) {
            $hash = hash('sha256', 'tableros-thumbnail-webp-v1:' . $originalKey);
            return '1/1/1970/01/' . substr($hash, 0, 2) . '/' . $hash;
        }
        if (!preg_match('~^([1-9][0-9]*/[1-9][0-9]*/[0-9]{4}/(?:0[1-9]|1[0-2]))/[a-f0-9]{2}/[a-f0-9]{64}$~', $originalKey, $m)) {
            throw new TablerosApiException('not_found', 'No se encontró el archivo.', 404);
        }
        $hash = hash('sha256', 'tableros-thumbnail-webp-v1:' . $originalKey);
        return $m[1] . '/' . substr($hash, 0, 2) . '/' . $hash;
    }

    private function ensureStoredThumbnail(string $sourcePath, string $originalKey, string $thumbnailKey, bool $useNas): string {
        $lockPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tableros-thumb-' . hash('sha256', $thumbnailKey) . '.lock';
        $lock = @fopen($lockPath, 'c');
        if (!is_resource($lock)) throw new TablerosApiException('server', 'No se pudo bloquear la generación de miniatura.', 500);
        if (!flock($lock, LOCK_EX)) { fclose($lock); throw new TablerosApiException('server', 'No se pudo bloquear la generación de miniatura.', 500); }
        try {
            $existing = $this->readStoredThumbnail($thumbnailKey, $useNas);
            if ($existing !== null) return $existing;
            $webp = $this->makeWebpThumbnail($sourcePath);
            if ($webp === null) throw new TablerosApiException('server', 'La generación de miniaturas WebP no está disponible en este servidor.', 503);
            try {
                if ($useNas) $this->nasServiceRequest('PUT', $thumbnailKey, $webp, (int)filesize($webp), 'image/webp');
                else $this->writePrivateObject($thumbnailKey, $webp);
            } catch (Throwable $writeError) {
                // Another worker may have won the deterministic-key race.
                $existing = $this->readStoredThumbnail($thumbnailKey, $useNas);
                if ($existing === null) throw $writeError;
                return $existing;
            } finally { @unlink($webp); }
            return $this->readStoredThumbnail($thumbnailKey, $useNas) ?? throw new TablerosApiException('server', 'No se pudo leer la miniatura guardada.', 500);
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function readStoredThumbnail(string $key, bool $useNas, int $maxBytes = 2 * 1024 * 1024): ?string {
        if (!$useNas) {
            try {
                $path = $this->privateObjectPath($key);
                return is_file($path) && is_readable($path) ? $path : null;
            } catch (TablerosApiException $e) {
                if ($e->apiCode === 'not_found') return null;
                throw $e;
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'tableros-thumb-');
        if ($path === false) return null;
        try {
            $this->nasServiceRequest('GET', $key, null, null, null, $path, $maxBytes);
            return $path;
        } catch (TablerosApiException $e) {
            @unlink($path);
            if ($e->apiCode === 'not_found') return null;
            throw $e;
        } catch (Throwable $e) { @unlink($path); throw $e; }
    }

    private function createStoredThumbnail(string $sourcePath, string $originalKey, string $thumbnailKey, bool $useNas): void {
        $path = $this->ensureStoredThumbnail($sourcePath, $originalKey, $thumbnailKey, $useNas);
        if ($useNas && is_file($path)) @unlink($path);
    }

    private function createStoredImagePreview(string $sourcePath, string $previewKey, bool $useNas): void {
        $path = $this->ensureStoredImagePreview($sourcePath, $previewKey, $useNas);
        if ($useNas && is_file($path)) @unlink($path);
    }

    private function makeWebpThumbnail(string $sourcePath): ?string {
        return $this->makeWebpDerivative($sourcePath, 320, 72, 'tableros-webp-');
    }

    private function makeWebpImagePreview(string $sourcePath): ?string {
        return $this->makeWebpDerivative($sourcePath, 1920, 82, 'tableros-preview-webp-');
    }

    private function makeWebpDerivative(string $sourcePath, int $maxEdge, int $quality, string $tempPrefix): ?string {
        if (!function_exists('imagewebp')) return null;
        $sourceSize = @filesize($sourcePath);
        if ($sourceSize === false || $sourceSize <= 0) throw new TablerosApiException('validation', 'No se pudo leer la imagen original.', 422);
        if ($sourceSize > 500 * 1024 * 1024) throw new TablerosApiException('validation', 'La imagen supera el límite de 500 MiB para generar derivados.', 413);

        // All workers on this host share this lock, so only one large GD decode runs at a time.
        $lock = @fopen(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tableros-webp-decode.lock', 'c');
        if (!is_resource($lock)) throw new TablerosApiException('server', 'No se pudo bloquear la generación de imágenes.', 500);
        if (!flock($lock, LOCK_EX)) { fclose($lock); throw new TablerosApiException('server', 'No se pudo bloquear la generación de imágenes.', 500); }
        $image = null; $thumb = null; $path = false; $success = false;
        $previousMemoryLimit = (string)ini_get('memory_limit');
        try {
            $info = @getimagesize($sourcePath);
            if (!is_array($info) || !in_array((string)($info['mime'] ?? ''), ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
                throw new TablerosApiException('validation', 'La imagen original está dañada o no es compatible.', 422);
            }
            $sourceWidth = (int)($info[0] ?? 0); $sourceHeight = (int)($info[1] ?? 0);
            if ($sourceWidth < 1 || $sourceHeight < 1 || $sourceWidth > 50000 || $sourceHeight > 50000) {
                throw new TablerosApiException('validation', 'Las dimensiones de la imagen no son compatibles.', 422);
            }
            $pixels = $sourceWidth * $sourceHeight;
            if ($pixels > 300000000) throw new TablerosApiException('validation', 'La imagen supera el límite de 300 megapíxeles para generar derivados.', 413);

            $memoryLimit = trim((string)ini_get('memory_limit'));
            $limitBytes = -1;
            if (preg_match('/^([0-9]+)\s*([KMG])?B?$/i', $memoryLimit, $match)) {
                $limitBytes = (int)$match[1] * match (strtoupper($match[2] ?? '')) { 'G' => 1073741824, 'M' => 1048576, 'K' => 1024, default => 1 };
            }
            // Decoding directly from the file avoids holding a second full copy of
            // the compressed input in PHP memory. Raise IIS's 512 MiB worker limit
            // for this serialized operation, with a 2 GiB per-worker hard ceiling.
            $estimatedBytes = memory_get_usage(true) + ($pixels * 6) + (128 * 1024 * 1024);
            $safeCeiling = 2 * 1024 * 1024 * 1024;
            if ($estimatedBytes > $safeCeiling) {
                throw new TablerosApiException('validation', 'La imagen requiere más memoria que el límite seguro para generar derivados.', 413);
            }
            if ($limitBytes > 0 && $estimatedBytes > $limitBytes) {
                $newLimit = (int)(ceil($estimatedBytes / (1024 * 1024) / 256) * 256) . 'M';
                if (@ini_set('memory_limit', $newLimit) === false) {
                    throw new TablerosApiException('validation', 'No se pudo ampliar la memoria disponible para generar derivados.', 413);
                }
            }

            $decoder = match ((string)$info['mime']) {
                'image/jpeg' => 'imagecreatefromjpeg',
                'image/png' => 'imagecreatefrompng',
                'image/gif' => 'imagecreatefromgif',
                'image/webp' => 'imagecreatefromwebp',
                default => null,
            };
            if ($decoder === null || !function_exists($decoder)) return null;
            $image = @$decoder($sourcePath);
            if (!$image) throw new TablerosApiException('validation', 'La imagen original está dañada o no se puede decodificar.', 422);
            $width = imagesx($image); $height = imagesy($image);
            $scale = min(1, $maxEdge / max($width, $height));
            $newWidth = max(1, (int)round($width * $scale)); $newHeight = max(1, (int)round($height * $scale));
            $thumb = @imagecreatetruecolor($newWidth, $newHeight);
            if (!$thumb) return null;
            imagealphablending($thumb, false); imagesavealpha($thumb, true);
            $transparent = imagecolorallocatealpha($thumb, 0, 0, 0, 127); imagefilledrectangle($thumb, 0, 0, $newWidth, $newHeight, $transparent);
            imagecopyresampled($thumb, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            $path = tempnam(sys_get_temp_dir(), $tempPrefix);
            if ($path === false || !@imagewebp($thumb, $path, $quality)) return null;
            $success = true;
            return $path;
        } finally {
            if ($image) imagedestroy($image);
            if ($thumb) imagedestroy($thumb);
            if ($path !== false && !$success) @unlink($path);
            if ($previousMemoryLimit !== '') @ini_set('memory_limit', $previousMemoryLimit);
            flock($lock, LOCK_UN); fclose($lock);
        }
    }

    private function writePrivateObject(string $key, string $source): void {
        $validKey = (bool)preg_match('~^(?:[1-9][0-9]*/[1-9][0-9]*/[0-9]{4}/(?:0[1-9]|1[0-2])/[a-f0-9]{2}/[a-f0-9]{64}|stickers/[1-9][0-9]*/[0-9]{4}/(?:0[1-9]|1[0-2])/[a-f0-9]{2}/[a-f0-9]{64}|[a-f0-9]{64})$~', $key);
        if (!$validKey) throw new TablerosApiException('not_found', 'No se encontró el archivo.', 404);
        $root = $this->privateStorageRoot();
        $dir = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, dirname($key));
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) throw new TablerosApiException('server', 'No se pudo preparar el almacenamiento privado de miniaturas.', 500);
        $realDir = realpath($dir);
        if ($realDir === false || !$this->pathIsWithin($realDir, $root) || !is_writable($realDir)) {
            throw new TablerosApiException('server', 'La ruta de miniaturas no es segura o no permite escritura.', 500);
        }
        $destination = $realDir . DIRECTORY_SEPARATOR . basename(str_replace('/', DIRECTORY_SEPARATOR, $key));
        if (is_link($destination)) throw new TablerosApiException('server', 'La ruta de miniaturas contiene un enlace no permitido.', 500);
        if (is_file($destination)) return;
        $temp = $realDir . DIRECTORY_SEPARATOR . '.thumb-' . bin2hex(random_bytes(12)) . '.tmp';
        $input = @fopen($source, 'rb');
        $output = @fopen($temp, 'xb');
        if (!is_resource($input) || !is_resource($output)) {
            if (is_resource($input)) fclose($input);
            if (is_resource($output)) fclose($output);
            @unlink($temp);
            throw new TablerosApiException('server', 'No se pudo preparar la escritura de miniatura.', 500);
        }
        $copied = stream_copy_to_stream($input, $output);
        fclose($input); fclose($output);
        if ($copied === false || $copied <= 0) { @unlink($temp); throw new TablerosApiException('server', 'No se pudo escribir la miniatura.', 500); }
        @chmod($temp, 0600);
        if (!@rename($temp, $destination)) {
            @unlink($temp);
            if (is_link($destination)) throw new TablerosApiException('server', 'La ruta de miniaturas contiene un enlace no permitido.', 500);
            if (!is_file($destination)) throw new TablerosApiException('server', 'No se pudo guardar la miniatura.', 500);
        }
    }

    private function deleteStoredObject(string $key, bool $useNas): void {
        if ($useNas) $this->nasServiceRequest('DELETE', $key);
        else { $path = $this->privateObjectPath($key); if (is_file($path)) @unlink($path); }
    }

    /** Returns [start, end] or false for malformed/unsatisfiable single byte ranges. */
    private function parseFileByteRange(string $header, int $size) {
        if ($size <= 0 || !preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $matches)
            || ($matches[1] === '' && $matches[2] === '')) return false;
        if ($matches[1] === '') {
            $suffix = (int)$matches[2];
            if ($suffix <= 0) return false;
            return [max(0, $size - $suffix), $size - 1];
        }
        $start = (int)$matches[1];
        $end = $matches[2] === '' ? $size - 1 : (int)$matches[2];
        if ($start >= $size || $end < $start) return false;
        return [$start, min($end, $size - 1)];
    }

    public function createBoard(int $userId, array $input): array {
        if (!$this->access->hasGlobalPermission('create') && !$this->access->canManageWorkspaceContent((int)($input['workspace_id'] ?? 0))) throw new TablerosApiException('forbidden', 'No tienes permiso para crear tableros.', 403);
        if (!$this->userIsActive($userId)) {
            throw new TablerosApiException('forbidden', 'La cuenta TG no está activa.', 403);
        }
        $name = $this->requiredString($input, 'name', 120);
        $description = $this->optionalString($input, 'description', 2000);
        $workspaceId = $this->positiveInt($input['workspace_id'] ?? null, 'workspace_id');
        $folderId = $this->optionalPositiveInt($input['folder_id'] ?? null, 'folder_id');
        $visibility = strtolower(trim((string)($input['visibility'] ?? 'private')));
        if (!in_array($visibility, ['private', 'workspace', 'organization'], true)) {
            throw new TablerosApiException('validation', 'La visibilidad del tablero no es válida.', 422);
        }

        return $this->transaction(function () use ($userId, $name, $description, $workspaceId, $folderId, $visibility): array {
            if ($this->access->workspaceRole($workspaceId) === null && !$this->access->canManageWorkspaceContent($workspaceId)) throw new TablerosApiException('forbidden', 'No tienes acceso al espacio de trabajo seleccionado.', 403);
            $workspace = $this->one("SELECT id, visibility FROM tb_workspace WHERE id = ? AND workspace_type = 'workspace' AND deleted_at IS NULL", [$workspaceId]);
            if (!$workspace) {
                throw new TablerosApiException('not_found', 'No se encontró el espacio de trabajo.', 404);
            }
            if ($folderId !== null) {
                $folder = $this->one('SELECT id, created_by FROM tb_folder WHERE id = ? AND workspace_id = ? AND deleted_at IS NULL', [$folderId, $workspaceId]);
                if (!$folder) {
                    throw new TablerosApiException('validation', 'La carpeta seleccionada no pertenece a este espacio de trabajo.', 422);
                }
                $workspaceRole = $this->access->workspaceRole($workspaceId);
                $systemFolderVisible = $folder['created_by'] === null && $workspaceRole !== null;
                if ((int)($folder['created_by'] ?? 0) !== $userId && $workspaceRole !== 'owner' && !$systemFolderVisible
                    && !$this->folderHasVisibleBoard($folderId, $workspaceId)
                    && !$this->access->canManageWorkspaceContent($workspaceId)) {
                    throw new TablerosApiException('forbidden', 'No tienes acceso a la carpeta seleccionada.', 403);
                }
            }
            $sortOrder = $this->nextBoardSortOrder($workspaceId, $folderId);
            $boardId = $this->insertId(
                'INSERT INTO tb_board (workspace_id, folder_id, name, description, visibility, sort_order, created_by, created_at, updated_at)
                 OUTPUT INSERTED.id VALUES (?, ?, ?, ?, ?, ?, ?, GETDATE(), GETDATE())',
                [$workspaceId, $folderId, $name, $description, $visibility, $sortOrder, $userId]
            );
            $this->writeActivity($boardId, null, $userId, 'board.created', ['name' => $name]);
            return ['id' => $boardId, 'workspace_id' => $workspaceId, 'folder_id' => $folderId, 'name' => $name, 'description' => $description, 'visibility' => $visibility, 'sort_order' => $sortOrder, 'role' => 'owner'];
        });
    }

    /** Clone a board with new IDs. No files, relations, dependencies, or automation state are copied. */
    public function duplicateBoard(int $sourceBoardId, int $userId, array $input): array {
        if (!$this->userIsActive($userId)) throw new TablerosApiException('forbidden', 'La cuenta TG no está activa.', 403);
        $name = $this->requiredString($input, 'name', 120);
        $mode = strtolower(trim((string)($input['mode'] ?? '')));
        if (!in_array($mode, ['structure', 'items', 'updates'], true)) {
            throw new TablerosApiException('validation', 'El modo de duplicación no es válido.', 422);
        }
        $keepSubscribers = $this->booleanInput($input, 'keep_subscribers', false);
        $destinationWorkspaceId = $this->optionalPositiveInt($input['workspace_id'] ?? null, 'workspace_id');

        return $this->transaction(function () use ($sourceBoardId, $userId, $name, $mode, $keepSubscribers, $destinationWorkspaceId): array {
            $source = $this->one('SELECT * FROM tb_board WITH (UPDLOCK, HOLDLOCK) WHERE id = ? AND deleted_at IS NULL', [$sourceBoardId]);
            if (!$source) throw new TablerosApiException('not_found', 'No se encontró el tablero.', 404);

            $workspaceId = $destinationWorkspaceId ?? (int)$source['workspace_id'];
            if ($destinationWorkspaceId === null
                && !$this->access->canCreateBoardInWorkspace($workspaceId)) {
                $ownWorkspace = $this->one("SELECT TOP (1) id FROM tb_workspace WHERE created_by = ? AND workspace_type = 'workspace' AND deleted_at IS NULL ORDER BY id", [$userId]);
                if (!$ownWorkspace) throw new TablerosApiException('forbidden', 'No tienes un espacio de trabajo disponible para crear el tablero.', 403);
                $workspaceId = (int)$ownWorkspace['id'];
            }
            $workspace = $this->one("SELECT id FROM tb_workspace WHERE id = ? AND workspace_type = 'workspace' AND deleted_at IS NULL", [$workspaceId]);
            if (!$workspace || !$this->access->canCreateBoardInWorkspace($workspaceId)) {
                throw new TablerosApiException('forbidden', 'No tienes acceso al espacio de trabajo destino.', 403);
            }
            $folderId = null;
            if ($workspaceId === (int)$source['workspace_id'] && !empty($source['folder_id'])) {
                $folder = $this->one('SELECT id, created_by FROM tb_folder WHERE id = ? AND workspace_id = ? AND deleted_at IS NULL', [(int)$source['folder_id'], $workspaceId]);
                $role = $this->access->workspaceRole($workspaceId);
                if ($folder && ((int)($folder['created_by'] ?? 0) === $userId || $role === 'owner' || $folder['created_by'] === null || $this->access->canManageWorkspaceContent($workspaceId))) {
                    $folderId = (int)$folder['id'];
                }
            }
            $sortOrder = $this->nextBoardSortOrder($workspaceId, $folderId);
            $boardId = $this->insertId(
                'INSERT INTO tb_board (workspace_id, folder_id, name, description, visibility, sort_order, created_by, created_at, updated_at)
                 OUTPUT INSERTED.id VALUES (?, ?, ?, ?, ?, ?, ?, GETDATE(), GETDATE())',
                [$workspaceId, $folderId, $name, $source['description'] ?? null, 'private', $sortOrder, $userId]
            );

            $groupMap = [];
            foreach ($this->all('SELECT id, name, color, sort_order FROM tb_group WHERE board_id = ? AND deleted_at IS NULL ORDER BY sort_order, id', [$sourceBoardId]) as $group) {
                $groupMap[(string)$group['id']] = $this->insertId(
                    'INSERT INTO tb_group (board_id, name, color, sort_order, created_by, created_at, updated_at) OUTPUT INSERTED.id VALUES (?, ?, ?, ?, ?, GETDATE(), GETDATE())',
                    [$boardId, $group['name'], $group['color'], $group['sort_order'], $userId]
                );
            }
            $columnMap = [];
            foreach ($this->all('SELECT id, name, type, options_json, required, sort_order, enforce_unique FROM tb_column WHERE board_id = ? AND deleted_at IS NULL ORDER BY sort_order, id', [$sourceBoardId]) as $column) {
                $columnMap[(string)$column['id']] = $this->insertId(
                    'INSERT INTO tb_column (board_id, name, type, options_json, required, sort_order, enforce_unique, created_by, created_at, updated_at) OUTPUT INSERTED.id VALUES (?, ?, ?, ?, ?, ?, ?, ?, GETDATE(), GETDATE())',
                    [$boardId, $column['name'], $column['type'], $column['options_json'], $column['required'], $column['sort_order'], $column['enforce_unique'], $userId]
                );
            }

            $itemMap = [];
            if ($mode !== 'structure') {
                $items = $this->all('SELECT id, group_id, parent_item_id, name, sort_order FROM tb_item WHERE board_id = ? AND deleted_at IS NULL ORDER BY group_id, sort_order, id', [$sourceBoardId]);
                // Topologically insert hierarchy so parent foreign keys always target the new board.
                $pending = $items;
                while ($pending) {
                    $progress = false;
                    foreach ($pending as $index => $item) {
                        $parentId = $item['parent_item_id'] === null ? null : ($itemMap[(string)$item['parent_item_id']] ?? null);
                        if ($item['parent_item_id'] !== null && $parentId === null) continue;
                        $groupId = $groupMap[(string)$item['group_id']] ?? null;
                        if ($groupId === null) throw new RuntimeException('Source item has no active group.');
                        $itemMap[(string)$item['id']] = $this->insertId(
                            'INSERT INTO tb_item (board_id, group_id, parent_item_id, name, sort_order, created_by, updated_by, created_at, updated_at) OUTPUT INSERTED.id VALUES (?, ?, ?, ?, ?, ?, ?, GETDATE(), GETDATE())',
                            [$boardId, $groupId, $parentId, $item['name'], $item['sort_order'], $userId, $userId]
                        );
                        unset($pending[$index]);
                        $progress = true;
                    }
                    if (!$progress) throw new RuntimeException('Source item hierarchy is invalid or cyclic.');
                }
            }

            if ($mode === 'updates' && $itemMap) {
                // Copy scalar cell payloads only. Reference-bearing/special fields are intentionally blank.
                $excludedTypes = ['people', 'person', 'team', 'file', 'board_relation', 'subtasks', 'dependency'];
                $cells = $this->all(
                    'SELECT c.item_id, c.column_id, c.value_text, c.value_number, c.value_date, c.value_datetime, c.value_boolean, c.value_json, CONVERT(VARCHAR(64), c.unique_value_hash, 2) AS unique_hash_hex, col.type
                     FROM tb_cell c INNER JOIN tb_column col ON col.id = c.column_id AND col.board_id = c.board_id
                     WHERE c.board_id = ? AND c.deleted_at IS NULL AND col.deleted_at IS NULL', [$sourceBoardId]
                );
                foreach ($cells as $cell) {
                    if (in_array((string)$cell['type'], $excludedTypes, true)) continue;
                    $newItem = $itemMap[(string)$cell['item_id']] ?? null;
                    $newColumn = $columnMap[(string)$cell['column_id']] ?? null;
                    if ($newItem === null || $newColumn === null) continue;
                    $valueNumber = $cell['value_number'];
                    $uniqueHash = $cell['unique_hash_hex'];
                    if ((string)$cell['type'] === 'item_id') {
                        $valueNumber = $itemMap[(string)(int)$valueNumber] ?? null;
                        if ($valueNumber === null) continue;
                        if ($uniqueHash !== null) $uniqueHash = $this->canonicalValueHash('item_id', (int)$valueNumber);
                    }
                    $this->execute(
                        'INSERT INTO tb_cell (item_id, column_id, board_id, value_text, value_number, value_date, value_datetime, value_boolean, value_json, unique_value_hash, created_by, updated_by, updated_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CONVERT(VARBINARY(32), ?, 2), ?, ?, GETDATE())',
                        [$newItem, $newColumn, $boardId, $cell['value_text'], $valueNumber, $cell['value_date'], $cell['value_datetime'], $cell['value_boolean'], $cell['value_json'], $uniqueHash, $userId, $userId]
                    );
                }
                $comments = $this->all('SELECT id, item_id, parent_comment_id, body FROM tb_comment WHERE board_id = ? AND deleted_at IS NULL ORDER BY created_at, id', [$sourceBoardId]);
                $commentMap = [];
                $pendingComments = $comments;
                while ($pendingComments) {
                    $progress = false;
                    foreach ($pendingComments as $index => $comment) {
                        $newItem = $itemMap[(string)$comment['item_id']] ?? null;
                        $parent = $comment['parent_comment_id'] === null ? null : ($commentMap[(string)$comment['parent_comment_id']] ?? null);
                        if ($newItem === null || ($comment['parent_comment_id'] !== null && $parent === null)) continue;
                        $commentMap[(string)$comment['id']] = $this->insertId(
                            'INSERT INTO tb_comment (board_id, item_id, parent_comment_id, body, created_by, updated_by, created_at, updated_at) OUTPUT INSERTED.id VALUES (?, ?, ?, ?, ?, ?, GETDATE(), GETDATE())',
                            [$boardId, $newItem, $parent, $comment['body'], $userId, $userId]
                        );
                        unset($pendingComments[$index]);
                        $progress = true;
                    }
                    if (!$progress) throw new RuntimeException('Source comment thread is invalid.');
                }
            }

            if ($keepSubscribers) {
                foreach ($this->all("SELECT user_id, role FROM tb_board_member WHERE board_id = ? AND deleted_at IS NULL AND user_id <> ?", [$sourceBoardId, $userId]) as $member) {
                    $this->execute('INSERT INTO tb_board_member (board_id, user_id, role, invited_by, created_at, updated_at) VALUES (?, ?, ?, ?, GETDATE(), GETDATE())', [$boardId, $member['user_id'], $member['role'], $userId]);
                }
            }
            $this->writeActivity($boardId, null, $userId, 'board.created', ['name' => $name, 'source_board_id' => $sourceBoardId, 'duplicate_mode' => $mode]);
            return ['id' => $boardId, 'workspace_id' => $workspaceId, 'folder_id' => $folderId, 'name' => $name, 'description' => $source['description'] ?? null, 'visibility' => 'private', 'sort_order' => $sortOrder, 'role' => 'owner'];
        });
    }

    public function createGroup(int $boardId, int $userId, array $input): array {
        $name = $this->requiredString($input, 'name', 120);
        $color = $this->optionalString($input, 'color', 32);
        $position = $this->nextSortOrder('tb_group', $boardId);
        $id = $this->insertId(
            'INSERT INTO tb_group (board_id, name, color, sort_order, created_by, created_at, updated_at)
             OUTPUT INSERTED.id VALUES (?, ?, ?, ?, ?, GETDATE(), GETDATE())',
            [$boardId, $name, $color, $position, $userId]
        );
        $this->writeActivity($boardId, null, $userId, 'group.created', ['group_id' => $id, 'name' => $name]);
        return ['id' => $id, 'board_id' => $boardId, 'name' => $name, 'color' => $color, 'sort_order' => $position];
    }

    public function reorderGroups(int $boardId, int $userId, array $input): array {
        $groupIds = $input['group_ids'] ?? null;
        if (!is_array($groupIds) || !array_is_list($groupIds)) {
            throw new TablerosApiException('validation', 'El orden debe incluir la lista completa de grupos.', 422);
        }
        $ids = [];
        foreach ($groupIds as $value) {
            $id = $this->positiveInt($value, 'group_id');
            if (isset($ids[$id])) {
                throw new TablerosApiException('validation', 'No se permiten grupos repetidos.', 422);
            }
            $ids[$id] = $id;
        }
        return $this->transaction(function () use ($boardId, $userId, $ids): array {
            $groups = $this->all(
                'SELECT id FROM tb_group WITH (UPDLOCK, HOLDLOCK) WHERE board_id = ? AND deleted_at IS NULL ORDER BY sort_order, id',
                [$boardId]
            );
            $activeIds = array_map(static fn(array $group): int => (int)$group['id'], $groups);
            if (count($activeIds) !== count($ids) || array_diff($activeIds, array_values($ids))) {
                throw new TablerosApiException('validation', 'El orden debe incluir exactamente los grupos activos de este tablero. Actualiza el tablero e inténtalo de nuevo.', 422);
            }
            $ordered = [];
            foreach (array_values($ids) as $index => $id) {
                $position = $index * 10;
                $this->execute(
                    'UPDATE tb_group SET sort_order = ?, updated_at = GETDATE() WHERE id = ? AND board_id = ? AND deleted_at IS NULL',
                    [$position, $id, $boardId]
                );
                $ordered[] = ['id' => $id, 'sort_order' => $position];
            }
            $this->writeActivity($boardId, null, $userId, 'group.reordered', ['group_ids' => array_values($ids)]);
            return ['groups' => $ordered];
        });
    }

    public function reorderColumns(int $boardId, int $userId, array $input): array {
        $columnIds = $input['column_ids'] ?? null;
        if (!is_array($columnIds) || !array_is_list($columnIds)) {
            throw new TablerosApiException('validation', 'El orden debe incluir la lista completa de columnas.', 422);
        }
        $ids = [];
        foreach ($columnIds as $value) {
            $id = $this->positiveInt($value, 'column_id');
            if (isset($ids[$id])) {
                throw new TablerosApiException('validation', 'No se permiten columnas repetidas en el orden.', 422);
            }
            $ids[$id] = $id;
        }
        $viewId = $this->optionalPositiveInt($input['view_id'] ?? null, 'view_id');

        return $this->transaction(function () use ($boardId, $userId, $ids, $viewId): array {
            $columns = $this->all(
                'SELECT id FROM tb_column WITH (UPDLOCK, HOLDLOCK) WHERE board_id = ? AND deleted_at IS NULL ORDER BY sort_order, id',
                [$boardId]
            );
            $activeIds = array_map(static fn(array $column): int => (int)$column['id'], $columns);
            if (count($activeIds) !== count($ids) || array_diff($activeIds, array_values($ids))) {
                throw new TablerosApiException('validation', 'El orden debe incluir exactamente las columnas activas de este tablero. Actualiza el tablero e inténtalo de nuevo.', 422);
            }

            if ($viewId !== null) {
                $view = $this->one(
                    'SELECT id, config_json, owner_user_id, is_shared FROM tb_view WITH (UPDLOCK, HOLDLOCK) WHERE id = ? AND board_id = ? AND deleted_at IS NULL',
                    [$viewId, $boardId]
                );
                if (!$view) {
                    throw new TablerosApiException('not_found', 'No se encontró la vista guardada de este tablero.', 404);
                }
                if ((int)($view['owner_user_id'] ?? 0) !== $userId && empty($view['is_shared'])) {
                    throw new TablerosApiException('forbidden', 'No tienes permiso para cambiar el orden de esta vista.', 403);
                }
                $config = $this->decodeJson($view['config_json'] ?? null, []);
                if (!is_array($config) || ($config !== [] && array_is_list($config))) $config = [];
                $config['column_order'] = array_map('strval', array_values($ids));
                $configJson = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($configJson === false) {
                    throw new TablerosApiException('validation', 'No se pudo guardar la configuración de la vista.', 422);
                }
                $this->execute(
                    'UPDATE tb_view SET config_json = ?, updated_at = GETDATE() WHERE id = ? AND board_id = ? AND deleted_at IS NULL',
                    [$configJson, $viewId, $boardId]
                );
            }

            $ordered = [];
            foreach (array_values($ids) as $index => $id) {
                $position = $index * 10;
                $this->execute(
                    'UPDATE tb_column SET sort_order = ?, updated_at = GETDATE() WHERE id = ? AND board_id = ? AND deleted_at IS NULL',
                    [$position, $id, $boardId]
                );
                $ordered[] = ['id' => $id, 'sort_order' => $position];
            }
            $this->writeActivity($boardId, null, $userId, 'column.reordered', ['column_ids' => array_values($ids), 'view_id' => $viewId]);
            return ['columns' => $ordered, 'view_id' => $viewId];
        });
    }

    public function createColumn(int $boardId, int $userId, array $input): array {
        $name = $this->requiredString($input, 'name', 120);
        if (!is_string($input['type'] ?? null)) {
            throw new TablerosApiException('validation', 'El tipo de columna es obligatorio.', 422);
        }
        $type = strtolower(trim($input['type']));
        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            throw new TablerosApiException('validation', 'El tipo de columna no está permitido.', 422);
        }
        $options = $input['options'] ?? [];
        if (!is_array($options) || strlen((string)json_encode($options, JSON_UNESCAPED_UNICODE)) > 16000) {
            throw new TablerosApiException('validation', 'Las opciones de la columna no son válidas.', 422);
        }
        if ($type === 'rating') {
            $display = array_key_exists('display', $options) ? $options['display'] : 'stars';
            if (!in_array($display, ['stars', 'percentage'], true)) {
                throw new TablerosApiException('validation', 'La calificación debe mostrarse como estrellas o porcentaje.', 422);
            }
            $options['display'] = $display;
            $maximum = $display === 'percentage' ? 100 : ($options['max'] ?? 5);
            if (!is_numeric($maximum) || !is_finite((float)$maximum) || (float)$maximum <= 0) {
                throw new TablerosApiException('validation', 'El máximo de la calificación debe ser un número positivo.', 422);
            }
            $options['max'] = (float)$maximum;
        }
        $required = $this->booleanInput($input, 'required', false);
        $enforceUnique = $this->booleanInput($input, 'enforce_unique', false);
        if ($enforceUnique && in_array($type, ['people', 'person'], true)) {
            throw new TablerosApiException('validation', 'Las columnas de personas no admiten valores únicos.', 422);
        }
        return $this->transaction(function () use ($boardId, $userId, $name, $type, $options, $required, $enforceUnique, $input): array {
        $columns = $this->all('SELECT id, sort_order FROM tb_column WITH (UPDLOCK, HOLDLOCK) WHERE board_id = ? AND deleted_at IS NULL ORDER BY sort_order, id', [$boardId]);
        $afterId = $this->optionalPositiveInt($input['insert_after_column_id'] ?? null, 'insert_after_column_id');
        $positionIndex = count($columns);
        if ($afterId !== null) {
            $found = false;
            foreach ($columns as $index => $existingColumn) if ((int)$existingColumn['id'] === $afterId) { $positionIndex = $index + 1; $found = true; break; }
            if (!$found) throw new TablerosApiException('validation', 'La columna de referencia no pertenece a este tablero.', 422);
        }
        array_splice($columns, $positionIndex, 0, [['id' => null]]);
        foreach ($columns as $index => $entry) if ($entry['id'] !== null) $this->execute('UPDATE tb_column SET sort_order = ?, updated_at = GETDATE() WHERE id = ? AND board_id = ?', [$index * 10, $entry['id'], $boardId]);
        $position = $positionIndex * 10;
        $id = $this->insertId(
            'INSERT INTO tb_column (board_id, name, type, options_json, required, sort_order, enforce_unique, created_by, created_at, updated_at)
             OUTPUT INSERTED.id VALUES (?, ?, ?, ?, ?, ?, ?, ?, GETDATE(), GETDATE())',
            [$boardId, $name, $type, json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $required ? 1 : 0, $position, $enforceUnique ? 1 : 0, $userId]
        );
        $this->writeActivity($boardId, null, $userId, 'column.created', ['column_id' => $id, 'type' => $type, 'name' => $name]);
        return ['id' => $id, 'board_id' => $boardId, 'name' => $name, 'type' => $type, 'options' => $options, 'required' => $required, 'enforce_unique' => $enforceUnique, 'sort_order' => $position];
        });
    }

    public function renameColumn(int $boardId, int $userId, array $input): array {
        $columnId = $this->positiveInt($input['column_id'] ?? null, 'column_id');
        $name = $this->requiredString($input, 'name', 120);
        return $this->transaction(function () use ($boardId, $userId, $columnId, $name): array {
            $column = $this->one('SELECT id FROM tb_column WITH (UPDLOCK, HOLDLOCK) WHERE id = ? AND board_id = ? AND deleted_at IS NULL', [$columnId, $boardId]);
            if (!$column) throw new TablerosApiException('not_found', 'No se encontró la columna en este tablero.', 404);
            $this->execute('UPDATE tb_column SET name = ?, updated_at = GETDATE() WHERE id = ? AND board_id = ?', [$name, $columnId, $boardId]);
            $this->writeActivity($boardId, null, $userId, 'column.renamed', ['column_id' => $columnId, 'name' => $name]);
            return ['column_id' => $columnId, 'name' => $name];
        });
    }

    /** Duplicate the column definition only; users can populate the new column explicitly. */
    public function duplicateColumn(int $boardId, int $userId, array $input): array {
        $columnId = $this->positiveInt($input['column_id'] ?? null, 'column_id');
        $copyData = $this->booleanInput($input, 'copy_data', false);
        return $this->transaction(function () use ($boardId, $userId, $columnId, $copyData): array {
            $source = $this->one('SELECT * FROM tb_column WITH (UPDLOCK, HOLDLOCK) WHERE id = ? AND board_id = ? AND deleted_at IS NULL', [$columnId, $boardId]);
            if (!$source) throw new TablerosApiException('not_found', 'No se encontró la columna en este tablero.', 404);
            if ($copyData && in_array((string)$source['type'], ['people','person','board_relation','subtasks','dependency','file'], true)) {
                throw new TablerosApiException('validation', 'No se pueden copiar automáticamente las relaciones auxiliares de este tipo de columna.', 422);
            }
            $name = $this->requiredString(['name' => mb_substr((string)$source['name'] . ' (copia)', 0, 120)], 'name', 120);
            $position = $this->nextSortOrder('tb_column', $boardId);
            $id = $this->insertId('INSERT INTO tb_column (board_id,name,type,options_json,required,sort_order,enforce_unique,created_by,created_at,updated_at) OUTPUT INSERTED.id VALUES (?,?,?,?,?,?,?,?,GETDATE(),GETDATE())', [$boardId,$name,$source['type'],$source['options_json'],$source['required'],$position,$source['enforce_unique'],$userId]);
            $copiedCount = 0;
            if ($copyData) {
                $copiedCount = $this->execute('INSERT INTO tb_cell (item_id,column_id,board_id,value_text,value_number,value_date,value_datetime,value_boolean,value_json,unique_value_hash,created_by,updated_by,updated_at)
                    SELECT c.item_id,?,c.board_id,c.value_text,c.value_number,c.value_date,c.value_datetime,c.value_boolean,c.value_json,c.unique_value_hash,?, ?,GETDATE()
                    FROM tb_cell c INNER JOIN tb_item i ON i.id=c.item_id AND i.board_id=c.board_id AND i.deleted_at IS NULL
                    WHERE c.board_id=? AND c.column_id=? AND c.deleted_at IS NULL', [$id,$userId,$userId,$boardId,$columnId]);
            }
            $this->writeActivity($boardId, null, $userId, 'column.duplicated', ['source_column_id' => $columnId, 'column_id' => $id]);
            return ['id' => $id, 'board_id' => $boardId, 'name' => $name, 'type' => $source['type'], 'options' => $this->decodeJson($source['options_json'] ?? null, []), 'required' => (bool)$source['required'], 'enforce_unique' => (bool)$source['enforce_unique'], 'sort_order' => $position, 'copied_count' => $copiedCount];
        });
    }

    public function previewColumnTypeChange(int $boardId, array $input): array {
        $columnId = $this->positiveInt($input['column_id'] ?? null, 'column_id');
        $target = strtolower(trim((string)($input['type'] ?? '')));
        if (!in_array($target, self::ALLOWED_TYPES, true)) throw new TablerosApiException('validation', 'El tipo de columna no está permitido.', 422);
        $column = $this->one('SELECT id,type,options_json,required,enforce_unique FROM tb_column WHERE id = ? AND board_id = ? AND deleted_at IS NULL', [$columnId,$boardId]);
        if (!$column) throw new TablerosApiException('not_found', 'No se encontró la columna en este tablero.', 404);
        return $this->inspectColumnTypeChange($boardId, $column, $target);
    }

    public function changeColumnType(int $boardId, int $userId, array $input): array {
        $columnId = $this->positiveInt($input['column_id'] ?? null, 'column_id');
        $target = strtolower(trim((string)($input['type'] ?? '')));
        if (!in_array($target, self::ALLOWED_TYPES, true)) throw new TablerosApiException('validation', 'El tipo de columna no está permitido.', 422);
        $options = $input['options'] ?? [];
        if (!is_array($options)) throw new TablerosApiException('validation', 'Las opciones no son válidas.', 422);
        return $this->transaction(function () use ($boardId,$userId,$columnId,$target,$options): array {
            $column = $this->one('SELECT id,type,options_json,required,enforce_unique FROM tb_column WITH (UPDLOCK,HOLDLOCK) WHERE id = ? AND board_id = ? AND deleted_at IS NULL', [$columnId,$boardId]);
            if (!$column) throw new TablerosApiException('not_found', 'No se encontró la columna en este tablero.', 404);
            $report = $this->inspectColumnTypeChange($boardId, $column, $target, $options);
            if ($report['invalid_count'] > 0) throw new TablerosApiException('validation', 'Hay valores que no pueden convertirse de forma segura. No se modificó la columna.', 422);
            if ($report['unique_conflict_count'] > 0) throw new TablerosApiException('conflict', 'La conversión produciría valores únicos duplicados. No se modificó la columna.', 409);
            $cells = $this->all('SELECT c.id,c.item_id,c.value_text,c.value_number,c.value_date,c.value_datetime,c.value_boolean,c.value_json FROM tb_cell c WITH (UPDLOCK,HOLDLOCK) INNER JOIN tb_item i ON i.id=c.item_id AND i.board_id=? AND i.deleted_at IS NULL WHERE c.board_id=? AND c.column_id=? AND c.deleted_at IS NULL', [$boardId,$boardId,$columnId]);
            foreach ($cells as $cell) {
                $value = $this->cellValue($cell);
                if ($value === null || $value === '') continue;
                $converted = $this->validateCellValue($target, $value, $options);
                $storage = $this->cellStorage($target, $converted);
                $hash = !empty($column['enforce_unique']) && $converted !== null ? $this->canonicalValueHash($target, $converted) : null;
                $this->execute('UPDATE tb_cell SET value_text=?,value_number=?,value_date=?,value_datetime=?,value_boolean=?,value_json=?,unique_value_hash=CONVERT(VARBINARY(32),?,2),updated_by=?,updated_at=GETDATE() WHERE id=?', array_merge($storage,[$hash,$userId,$cell['id']]));
            }
            $json = json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false || strlen($json) > 16000) throw new TablerosApiException('validation','Las opciones de la columna no son válidas.',422);
            $this->execute('UPDATE tb_column SET type=?,options_json=?,updated_at=GETDATE() WHERE id=? AND board_id=?', [$target,$json,$columnId,$boardId]);
            $this->writeActivity($boardId,null,$userId,'column.type_changed',['column_id'=>$columnId,'type'=>$target]);
            return ['column_id'=>$columnId,'type'=>$target,'converted_count'=>$report['valid_count']];
        });
    }

    private function inspectColumnTypeChange(int $boardId, array $column, string $target, array $options = []): array {
        $source = (string)$column['type'];
        // Side tables encode semantics beyond tb_cell and must never be orphaned by a type edit.
        $sideTableTypes = ['people','person','file','board_relation','subtasks','dependency','item_id','team','timeline','location','country','link'];
        if (in_array($source,$sideTableTypes,true) || in_array($target,$sideTableTypes,true)) throw new TablerosApiException('validation','Este tipo usa relaciones o datos auxiliares y no admite conversión automática.',422);
        if (in_array($target, ['status','dropdown','tags'], true) && !$this->optionValues($options)) throw new TablerosApiException('validation','Configura opciones antes de convertir a un tipo con etiquetas.',422);
        $cells = $this->all('SELECT c.id,c.item_id,c.value_text,c.value_number,c.value_date,c.value_datetime,c.value_boolean,c.value_json FROM tb_item i WITH (UPDLOCK,HOLDLOCK) LEFT JOIN tb_cell c WITH (UPDLOCK,HOLDLOCK) ON c.item_id=i.id AND c.board_id=i.board_id AND c.column_id=? AND c.deleted_at IS NULL WHERE i.board_id=? AND i.deleted_at IS NULL', [$column['id'],$boardId]);
        $invalid = []; $valid = 0; $values = [];
        foreach ($cells as $cell) {
            $value = $this->cellValue($cell);
            if ($value === null || $value === '') {
                if ((bool)$column['required']) { if (count($invalid)<5) $invalid[]=['item_id'=>(int)$cell['item_id'],'reason'=>'required']; }
                else $valid++;
                continue;
            }
            try { $converted = $this->validateCellValue($target,$value,$options); $valid++; if ((bool)$column['enforce_unique'] && $converted !== null) { $key = $this->canonicalValueHash($target,$converted); $values[$key] = ($values[$key] ?? 0)+1; } }
            catch (Throwable $e) { if (count($invalid)<5) $invalid[]=['item_id'=>(int)$cell['item_id'],'reason'=>'invalid_value']; }
        }
        $uniqueConflicts = count(array_filter($values, static fn($n) => $n > 1));
        return ['column_id'=>(int)$column['id'],'from_type'=>$source,'to_type'=>$target,'value_count'=>count($cells),'valid_count'=>$valid,'invalid_count'=>max(0,count($cells)-$valid),'invalid_examples'=>$invalid,'unique_conflict_count'=>$uniqueConflicts,'can_commit'=>!$invalid && !$uniqueConflicts];
    }

    public function updateColumnOptions(int $boardId, int $userId, array $input): array {
        $columnId = $this->positiveInt($input['column_id'] ?? null, 'column_id');
        $options = $input['options'] ?? null;
        if (!is_array($options) || !array_is_list($options) || count($options) > 100) {
            throw new TablerosApiException('validation', 'La lista de etiquetas no es válida.', 422);
        }

        $normalized = [];
        $seen = [];
        foreach ($options as $option) {
            if (!is_array($option)) {
                throw new TablerosApiException('validation', 'Cada etiqueta debe incluir nombre, valor y color.', 422);
            }
            $value = $this->requiredString($option, 'value', 120);
            $label = $this->requiredString($option, 'label', 120);
            $color = $this->requiredString($option, 'color', 7);
            if (!preg_match('/^#[0-9a-f]{6}$/i', $color)) {
                throw new TablerosApiException('validation', 'El color debe ser hexadecimal de seis dígitos.', 422);
            }
            $key = mb_strtolower($value, 'UTF-8');
            if (isset($seen[$key])) {
                throw new TablerosApiException('validation', 'No se permiten valores internos repetidos.', 422);
            }
            $seen[$key] = true;
            $normalized[] = ['value' => $value, 'label' => $label, 'color' => strtoupper($color)];
        }

        return $this->transaction(function () use ($boardId, $userId, $columnId, $normalized): array {
            $column = $this->one(
                'SELECT id, name, type, options_json FROM tb_column WITH (UPDLOCK, HOLDLOCK)
                 WHERE id = ? AND board_id = ? AND deleted_at IS NULL',
                [$columnId, $boardId]
            );
            if (!$column) {
                throw new TablerosApiException('not_found', 'No se encontró la columna en este tablero.', 404);
            }
            if (!in_array((string)$column['type'], ['status', 'dropdown', 'tags'], true)) {
                throw new TablerosApiException('validation', 'Esta columna no tiene etiquetas configurables.', 422);
            }
            $oldOptions = $this->decodeJson($column['options_json'] ?? null, []);
            foreach ($this->optionValues(is_array($oldOptions) ? $oldOptions : []) as $oldValue) {
                $preserved = false;
                foreach ($normalized as $option) {
                    if ((string)$option['value'] === (string)$oldValue) { $preserved = true; break; }
                }
                if (!$preserved) {
                    throw new TablerosApiException('validation', 'Conserva los valores existentes para no perder las asignaciones actuales.', 422);
                }
            }
            if (!$normalized) {
                throw new TablerosApiException('validation', 'Agrega al menos una etiqueta.', 422);
            }
            $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false || strlen($json) > 16000) {
                throw new TablerosApiException('validation', 'La configuración de etiquetas es demasiado grande.', 422);
            }
            $this->execute(
                'UPDATE tb_column SET options_json = ?, updated_at = GETDATE()
                 WHERE id = ? AND board_id = ? AND deleted_at IS NULL',
                [$json, $columnId, $boardId]
            );
            $this->writeActivity($boardId, null, $userId, 'column.options_updated', [
                'column_id' => $columnId,
                'column_name' => (string)$column['name'],
                'option_count' => count($normalized),
            ]);
            return ['column_id' => $columnId, 'options' => $normalized];
        });
    }

    public function createItem(int $boardId, int $userId, array $input): array {
        $groupId = $this->positiveInt($input['group_id'] ?? null, 'group_id');
        $name = $this->optionalString($input, 'name', 500) ?? '';
        $parentId = $this->optionalPositiveInt($input['parent_item_id'] ?? null, 'parent_item_id');
        return $this->transaction(function () use ($boardId, $userId, $groupId, $name, $parentId): array {
            if (!$this->one('SELECT id FROM tb_group WITH (UPDLOCK, HOLDLOCK) WHERE id = ? AND board_id = ? AND deleted_at IS NULL', [$groupId, $boardId])) {
                throw new TablerosApiException('validation', 'El grupo no pertenece a este tablero.', 422);
            }
            if ($parentId !== null) {
                $parent = $this->one('SELECT id, group_id FROM tb_item WITH (UPDLOCK, HOLDLOCK) WHERE id = ? AND board_id = ? AND deleted_at IS NULL', [$parentId, $boardId]);
                if (!$parent) {
                    throw new TablerosApiException('validation', 'El elemento relacionado no pertenece a este tablero.', 422);
                }
                if ((int)$parent['group_id'] !== $groupId) {
                    throw new TablerosApiException('validation', 'El subelemento debe estar en el mismo grupo que su elemento principal.', 422);
                }
            }
            $position = $this->nextSortOrder('tb_item', $boardId, 'group_id', $groupId);
            $item = $this->insertRow(
                'INSERT INTO tb_item (board_id, group_id, parent_item_id, name, sort_order, created_by, updated_by, created_at, updated_at)
                 OUTPUT INSERTED.id, INSERTED.version VALUES (?, ?, ?, ?, ?, ?, ?, GETDATE(), GETDATE())',
                [$boardId, $groupId, $parentId, $name, $position, $userId, $userId]
            );
            $this->writeActivity($boardId, (int)$item['id'], $userId, 'item.created', ['group_id' => $groupId, 'parent_item_id' => $parentId]);
            return [
                'id' => (int)$item['id'], 'board_id' => $boardId, 'group_id' => $groupId,
                'parent_item_id' => $parentId, 'name' => $name, 'sort_order' => $position,
                'version' => $this->versionToken($item['version'] ?? null),
            ];
        });
    }

    public function updateItemName(int $boardId, int $userId, array $input): array {
        $itemId = $this->positiveInt($input['item_id'] ?? null, 'item_id');
        $name = $this->requiredString($input, 'name', 500);
        $expectedVersion = $this->versionInput($input['version'] ?? null);
        $this->requireItemOnBoard($itemId, $boardId);
        return $this->transaction(function () use ($boardId, $userId, $itemId, $name, $expectedVersion): array {
            $updated = $this->returningOne(
                'UPDATE tb_item SET name = ?, updated_by = ?, updated_at = GETDATE()
                 OUTPUT INSERTED.version
                 WHERE id = ? AND board_id = ? AND version = CONVERT(VARBINARY(8), ?, 2) AND deleted_at IS NULL',
                [$name, $userId, $itemId, $boardId, $expectedVersion]
            );
            if (!$updated) {
                throw new TablerosApiException('conflict', 'El elemento cambió. Recarga el tablero e inténtalo de nuevo.', 409);
            }
            $this->writeActivity($boardId, $itemId, $userId, 'item.name_changed', ['name' => $name]);
            return ['id' => $itemId, 'name' => $name, 'version' => $this->versionToken($updated['version'] ?? null)];
        });
    }

    public function updateCell(int $boardId, ?int $userId, array $input): array {
        $itemId = $this->positiveInt($input['item_id'] ?? null, 'item_id');
        $columnId = $this->positiveInt($input['column_id'] ?? null, 'column_id');
        $expectedVersion = $this->versionInput($input['version'] ?? null);
        if (!array_key_exists('value', $input)) {
            throw new TablerosApiException('validation', 'Falta el valor de la celda.', 422);
        }

        return $this->transaction(function () use ($boardId, $userId, $itemId, $columnId, $expectedVersion, $input): array {
            $column = $this->one(
                'SELECT id, type, options_json, required, enforce_unique FROM tb_column WHERE id = ? AND board_id = ? AND deleted_at IS NULL',
                [$columnId, $boardId]
            );
            if (!$column) {
                throw new TablerosApiException('not_found', 'No se encontró la columna en este tablero.', 404);
            }
            $item = $this->one(
                'SELECT id, version FROM tb_item WHERE id = ? AND board_id = ? AND deleted_at IS NULL',
                [$itemId, $boardId]
            );
            if (!$item) {
                throw new TablerosApiException('not_found', 'No se encontró el elemento en este tablero.', 404);
            }
            if ($this->versionToken($item['version'] ?? null) !== $expectedVersion) {
                throw new TablerosApiException('conflict', 'El elemento cambió. Recarga el tablero e inténtalo de nuevo.', 409);
            }

            $value = $this->validateCellValue((string)$column['type'], $input['value'], $this->decodeJson($column['options_json'] ?? null, []));
            if (in_array((string)$column['type'], ['people', 'person'], true)) {
                foreach ($value as $assignedUserId) {
                    $this->ensureAssignedUserBoardAccess((int)$assignedUserId, $boardId, $itemId, $userId);
                }
            }
            $this->validateCellReferences((string)$column['type'], $value, $boardId, $itemId, $columnId);
            if ((bool)$column['required'] && ($value === null || $value === '' || $value === [])) {
                throw new TablerosApiException('validation', 'Esta columna requiere un valor.', 422);
            }

            $updatedItem = $this->returningOne(
                'UPDATE tb_item SET updated_by = ?, updated_at = GETDATE()
                 OUTPUT INSERTED.version
                 WHERE id = ? AND board_id = ? AND version = CONVERT(VARBINARY(8), ?, 2) AND deleted_at IS NULL',
                [$userId, $itemId, $boardId, $expectedVersion]
            );
            if (!$updatedItem) {
                throw new TablerosApiException('conflict', 'El elemento cambió. Recarga el tablero e inténtalo de nuevo.', 409);
            }

            $storage = $this->cellStorage((string)$column['type'], $value);
            $uniqueHash = null;
            if ((bool)$column['enforce_unique'] && $value !== null) {
                $uniqueHash = $this->canonicalValueHash((string)$column['type'], $value);
                $duplicate = $this->one(
                    'SELECT id FROM tb_cell WHERE column_id = ? AND item_id <> ? AND unique_value_hash = CONVERT(VARBINARY(32), ?, 2) AND deleted_at IS NULL',
                    [$columnId, $itemId, $uniqueHash]
                );
                if ($duplicate) {
                    throw new TablerosApiException('conflict', 'Ese valor ya está en uso en otra fila.', 409);
                }
            }
            $existing = $this->one('SELECT id FROM tb_cell WHERE item_id = ? AND column_id = ?', [$itemId, $columnId]);
            if ($existing) {
                $this->execute(
                    'UPDATE tb_cell SET board_id = ?, value_text = ?, value_number = ?, value_date = ?, value_datetime = ?,
                         value_boolean = ?, value_json = ?, unique_value_hash = CONVERT(VARBINARY(32), ?, 2),
                         updated_by = ?, updated_at = GETDATE(), deleted_at = NULL
                     WHERE item_id = ? AND column_id = ?',
                    array_merge([$boardId], $storage, [$uniqueHash, $userId, $itemId, $columnId])
                );
                $cellId = (int)$existing['id'];
            } else {
                $cellId = $this->insertId(
                    'INSERT INTO tb_cell (item_id, column_id, board_id, value_text, value_number, value_date, value_datetime,
                         value_boolean, value_json, unique_value_hash, created_by, updated_by, updated_at)
                     OUTPUT INSERTED.id VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CONVERT(VARBINARY(32), ?, 2), ?, ?, GETDATE())',
                    array_merge([$itemId, $columnId, $boardId], $storage, [$uniqueHash, $userId, $userId])
                );
            }
            if (in_array((string)$column['type'], ['people', 'person'], true)) {
                $this->replaceItemPeople($boardId, $itemId, $columnId, $userId, $value);
            }
            $this->writeActivity($boardId, $itemId, $userId, 'cell.updated', [
                'column_id' => $columnId,
                'column_type' => (string)$column['type'],
            ]);
            return [
                'id' => $cellId, 'item_id' => $itemId, 'column_id' => $columnId,
                'value' => $value, 'version' => $this->versionToken($updatedItem['version'] ?? null),
            ];
        });
    }

    public function moveItem(int $boardId, int $userId, array $input): array {
        $itemId = $this->positiveInt($input['item_id'] ?? null, 'item_id');
        $groupId = $this->positiveInt($input['group_id'] ?? null, 'group_id');
        $expectedVersion = $this->versionInput($input['version'] ?? null);
        $position = isset($input['sort_order']) ? $this->integerValue($input['sort_order'], 'sort_order') : null;
        if ($position !== null && $position < 0) {
            throw new TablerosApiException('validation', 'El orden debe ser cero o mayor.', 422);
        }
        $this->requireGroupOnBoard($groupId, $boardId);
        return $this->transaction(function () use ($boardId, $userId, $itemId, $groupId, $position, $expectedVersion): array {
            // Lock the board's active item range so concurrent hierarchy changes cannot
            // add or move descendants midway through this operation.
            $rows = $this->all('SELECT id, parent_item_id, group_id, sort_order, version FROM tb_item WITH (UPDLOCK, HOLDLOCK) WHERE board_id = ? AND deleted_at IS NULL', [$boardId]);
            $byId = [];
            foreach ($rows as $row) { $byId[(int)$row['id']] = $row; }
            if (!isset($byId[$itemId])) {
                throw new TablerosApiException('not_found', 'No se encontró el elemento en este tablero.', 404);
            }
            if ($this->versionToken($byId[$itemId]['version'] ?? null) !== $expectedVersion) {
                throw new TablerosApiException('conflict', 'El elemento cambió. Recarga el tablero e inténtalo de nuevo.', 409);
            }
            $parentId = $byId[$itemId]['parent_item_id'] === null ? null : (int)$byId[$itemId]['parent_item_id'];
            if ($parentId !== null) {
                if (!isset($byId[$parentId])) {
                    throw new TablerosApiException('validation', 'El elemento principal no está disponible en este tablero.', 422);
                }
                if ($position !== null && (int)$byId[$itemId]['group_id'] !== $groupId) {
                    throw new TablerosApiException('validation', 'Un subelemento solo puede reordenarse dentro del grupo de su elemento principal.', 422);
                }
                if ((int)$byId[$parentId]['group_id'] !== $groupId) {
                    throw new TablerosApiException('validation', 'Un subelemento debe permanecer en el mismo grupo que su elemento principal.', 422);
                }
            }
            // Reconcile the complete subtree for roots and subtasks alike. Legacy
            // rows may already contain descendants in a different group.
            $descendants = [$itemId];
            $seen = [$itemId => true];
            $childrenByParent = [];
            foreach ($rows as $row) {
                if ($row['parent_item_id'] !== null) {
                    $childrenByParent[(int)$row['parent_item_id']][] = (int)$row['id'];
                }
            }
            for ($index = 0; $index < count($descendants); $index++) {
                $currentId = $descendants[$index];
                foreach ($childrenByParent[$currentId] ?? [] as $childId) {
                    if (!isset($seen[$childId])) { $seen[$childId] = true; $descendants[] = $childId; }
                }
            }
            if ($position === null) {
                $position = $this->nextSortOrder('tb_item', $boardId, 'group_id', $groupId);
            }
            $placeholders = implode(',', array_fill(0, count($descendants), '?'));
            $affected = $this->all(
                'UPDATE tb_item SET group_id = ?, updated_by = ?, updated_at = GETDATE(), sort_order = CASE WHEN id = ? THEN ? ELSE sort_order END
                 OUTPUT INSERTED.id, INSERTED.group_id, INSERTED.version
                 WHERE board_id = ? AND id IN (' . $placeholders . ') AND deleted_at IS NULL',
                [$groupId, $userId, $itemId, $position, $boardId, ...$descendants]
            );
            $affectedItems = [];
            $rootVersion = null;
            foreach ($affected as $row) {
                $entry = ['id' => (int)$row['id'], 'group_id' => (int)$row['group_id'], 'version' => $this->versionToken($row['version'] ?? null)];
                $affectedItems[] = $entry;
                if ((int)$row['id'] === $itemId) { $rootVersion = $entry['version']; }
            }
            if ($rootVersion === null || count($affectedItems) !== count($descendants)) {
                throw new TablerosApiException('conflict', 'El elemento cambió. Recarga el tablero e inténtalo de nuevo.', 409);
            }
            if ($position !== null && $parentId === null) {
                $affectedSet = array_fill_keys($descendants, true);
                $groupsToOrder = array_values(array_unique([(int)$byId[$itemId]['group_id'], $groupId]));
                $childrenByParent = [];
                foreach ($rows as $row) {
                    if ($row['parent_item_id'] !== null) $childrenByParent[(int)$row['parent_item_id']][] = (int)$row['id'];
                }
                foreach ($childrenByParent as &$childIds) {
                    usort($childIds, static function (int $left, int $right) use ($byId): int {
                        return ((int)$byId[$left]['sort_order'] <=> (int)$byId[$right]['sort_order']) ?: ($left <=> $right);
                    });
                }
                unset($childIds);
                foreach ($groupsToOrder as $orderedGroupId) {
                    $groupRows = array_values(array_filter($rows, static function (array $row) use ($orderedGroupId, $groupId, $affectedSet): bool {
                        $rowId = (int)$row['id'];
                        $rowGroupId = isset($affectedSet[$rowId]) ? $groupId : (int)$row['group_id'];
                        return $rowGroupId === $orderedGroupId;
                    }));
                    $rootIds = [];
                    foreach ($groupRows as $row) {
                        if ($row['parent_item_id'] === null && !isset($affectedSet[(int)$row['id']])) $rootIds[] = (int)$row['id'];
                    }
                    usort($rootIds, static function (int $left, int $right) use ($byId): int {
                        return ((int)$byId[$left]['sort_order'] <=> (int)$byId[$right]['sort_order']) ?: ($left <=> $right);
                    });
                    if ($orderedGroupId === $groupId) {
                        $insertAt = min(max(0, $position), count($rootIds));
                        array_splice($rootIds, $insertAt, 0, [$itemId]);
                    }
                    $orderedIds = [];
                    $seen = [];
                    $appendTree = function (int $currentId) use (&$appendTree, &$orderedIds, &$seen, $childrenByParent): void {
                        if (isset($seen[$currentId])) return;
                        $seen[$currentId] = true;
                        $orderedIds[] = $currentId;
                        foreach ($childrenByParent[$currentId] ?? [] as $childId) $appendTree($childId);
                    };
                    foreach ($rootIds as $rootId) $appendTree($rootId);
                    usort($groupRows, static function (array $left, array $right): int {
                        return ((int)$left['sort_order'] <=> (int)$right['sort_order']) ?: ((int)$left['id'] <=> (int)$right['id']);
                    });
                    foreach ($groupRows as $row) $appendTree((int)$row['id']);
                    foreach ($orderedIds as $sortOrder => $orderedItemId) {
                        $this->execute('UPDATE tb_item SET sort_order = ? WHERE board_id = ? AND id = ? AND deleted_at IS NULL', [$sortOrder, $boardId, $orderedItemId]);
                    }
                }
            }
            $this->writeActivity($boardId, $itemId, $userId, 'item.moved', ['group_id' => $groupId, 'sort_order' => $position, 'affected_count' => count($affectedItems)]);
            return ['id' => $itemId, 'group_id' => $groupId, 'sort_order' => $position, 'version' => $rootVersion, 'affected_items' => $affectedItems];
        });
    }

    public function setSubtask(int $boardId, int $userId, array $input): array {
        $itemId = $this->positiveInt($input['item_id'] ?? null, 'item_id');
        $parentId = $this->optionalPositiveInt($input['parent_item_id'] ?? null, 'parent_item_id');
        $expectedVersion = $this->versionInput($input['version'] ?? null);
        $this->requireItemOnBoard($itemId, $boardId);
        if ($parentId !== null) {
            $this->requireItemOnBoard($parentId, $boardId);
            if ($parentId === $itemId) {
                throw new TablerosApiException('validation', 'Un elemento no puede ser su propio subelemento.', 422);
            }
        }

        return $this->transaction(function () use ($boardId, $userId, $itemId, $parentId, $expectedVersion): array {
            $item = $this->one('SELECT id, group_id FROM tb_item WITH (UPDLOCK, HOLDLOCK) WHERE id = ? AND board_id = ? AND deleted_at IS NULL', [$itemId, $boardId]);
            if (!$item) {
                throw new TablerosApiException('validation', 'El elemento relacionado no pertenece a este tablero.', 422);
            }
            if ($parentId !== null) {
                $parent = $this->one('SELECT id, group_id FROM tb_item WITH (UPDLOCK, HOLDLOCK) WHERE id = ? AND board_id = ? AND deleted_at IS NULL', [$parentId, $boardId]);
                if (!$parent) {
                    throw new TablerosApiException('validation', 'El elemento relacionado no pertenece a este tablero.', 422);
                }
                if ((int)$item['group_id'] !== (int)$parent['group_id']) {
                    throw new TablerosApiException('validation', 'El subelemento y su elemento principal deben estar en el mismo grupo.', 422);
                }
            }
            $cursor = $parentId;
            $visited = [];
            while ($cursor !== null) {
                if ($cursor === $itemId) {
                    throw new TablerosApiException('conflict', 'La jerarquía crearía un ciclo de subelementos.', 409);
                }
                if (isset($visited[$cursor])) {
                    throw new TablerosApiException('conflict', 'La jerarquía actual contiene un ciclo.', 409);
                }
                $visited[$cursor] = true;
                $row = $this->one(
                    'SELECT parent_item_id FROM tb_item WITH (UPDLOCK, HOLDLOCK) WHERE id = ? AND board_id = ? AND deleted_at IS NULL',
                    [$cursor, $boardId]
                );
                $cursor = $row && $row['parent_item_id'] !== null ? (int)$row['parent_item_id'] : null;
            }
            $updated = $this->returningOne(
                'UPDATE tb_item SET parent_item_id = ?, updated_by = ?, updated_at = GETDATE()
                 OUTPUT INSERTED.version
                 WHERE id = ? AND board_id = ? AND version = CONVERT(VARBINARY(8), ?, 2) AND deleted_at IS NULL',
                [$parentId, $userId, $itemId, $boardId, $expectedVersion]
            );
            if (!$updated) {
                throw new TablerosApiException('conflict', 'El elemento cambió. Recarga el tablero e inténtalo de nuevo.', 409);
            }
            $this->writeActivity($boardId, $itemId, $userId, 'subtask.parent_changed', ['parent_item_id' => $parentId]);
            return ['id' => $itemId, 'parent_item_id' => $parentId, 'version' => $this->versionToken($updated['version'] ?? null)];
        });
    }

    public function getRelations(int $boardId, int $itemId): array {
        $this->requireItemOnBoard($itemId, $boardId);
        $rows = $this->all(
            'SELECT id, source_board_id, source_item_id, target_board_id, target_item_id, column_id, relation_type, created_by, created_at
             FROM tb_item_relation WHERE source_board_id = ? AND source_item_id = ? AND deleted_at IS NULL
             ORDER BY created_at, id',
            [$boardId, $itemId]
        );
        return array_values(array_filter($rows, fn(array $row): bool => $this->access->boardRole((int)$row['target_board_id']) !== null));
    }

    public function addRelation(int $boardId, int $userId, array $input): array {
        $sourceItemId = $this->positiveInt($input['source_item_id'] ?? null, 'source_item_id');
        $targetBoardId = $this->optionalPositiveInt($input['target_board_id'] ?? null, 'target_board_id') ?? $boardId;
        $targetItemId = $this->positiveInt($input['target_item_id'] ?? null, 'target_item_id');
        $columnId = $this->optionalPositiveInt($input['column_id'] ?? null, 'column_id');
        $relationType = strtolower(trim((string)($input['relation_type'] ?? 'related')));
        if (!in_array($relationType, ['related', 'blocks', 'blocked_by', 'duplicate', 'connects'], true)) {
            throw new TablerosApiException('validation', 'El tipo de relación no está permitido.', 422);
        }
        $this->requireItemOnBoard($sourceItemId, $boardId);
        if (!$this->getBoard($targetBoardId)) {
            throw new TablerosApiException('not_found', 'No se encontró el tablero relacionado.', 404);
        }
        if ($this->access->boardRole($targetBoardId) === null) {
            throw new TablerosApiException('forbidden', 'No tienes acceso al tablero relacionado.', 403);
        }
        $this->requireItemOnBoard($targetItemId, $targetBoardId);
        if ($sourceItemId === $targetItemId && $boardId === $targetBoardId) {
            throw new TablerosApiException('validation', 'Un elemento no puede relacionarse consigo mismo.', 422);
        }
        if ($columnId !== null && !$this->one('SELECT id FROM tb_column WHERE id = ? AND board_id = ? AND deleted_at IS NULL', [$columnId, $boardId])) {
            throw new TablerosApiException('validation', 'La columna no pertenece a este tablero.', 422);
        }

        return $this->transaction(function () use ($boardId, $userId, $sourceItemId, $targetBoardId, $targetItemId, $columnId, $relationType): array {
            $id = $this->insertId(
                'INSERT INTO tb_item_relation (source_board_id, source_item_id, target_board_id, target_item_id, column_id, relation_type, created_by, created_at)
                 OUTPUT INSERTED.id VALUES (?, ?, ?, ?, ?, ?, ?, GETDATE())',
                [$boardId, $sourceItemId, $targetBoardId, $targetItemId, $columnId, $relationType, $userId]
            );
            $this->writeActivity($boardId, $sourceItemId, $userId, 'relation.created', [
                'relation_id' => $id, 'target_board_id' => $targetBoardId, 'target_item_id' => $targetItemId,
                'relation_type' => $relationType,
            ]);
            return ['id' => $id, 'source_item_id' => $sourceItemId, 'target_board_id' => $targetBoardId, 'target_item_id' => $targetItemId, 'relation_type' => $relationType];
        });
    }

    public function removeRelation(int $boardId, int $userId, array $input): array {
        $relationId = $this->positiveInt($input['relation_id'] ?? null, 'relation_id');
        return $this->transaction(function () use ($boardId, $userId, $relationId): array {
            $relation = $this->one(
                'SELECT id, source_item_id, target_board_id FROM tb_item_relation WITH (UPDLOCK, HOLDLOCK)
                 WHERE id = ? AND source_board_id = ? AND deleted_at IS NULL',
                [$relationId, $boardId]
            );
            if (!$relation) {
                throw new TablerosApiException('not_found', 'No se encontró la relación.', 404);
            }
            if ($this->access->boardRole((int)$relation['target_board_id']) === null) {
                throw new TablerosApiException('forbidden', 'No tienes acceso al tablero relacionado.', 403);
            }
            $this->execute('UPDATE tb_item_relation SET deleted_at = GETDATE() WHERE id = ?', [$relationId]);
            $this->writeActivity($boardId, (int)$relation['source_item_id'], $userId, 'relation.deleted', ['relation_id' => $relationId]);
            return ['id' => $relationId, 'deleted' => true];
        });
    }

    public function getDependencies(int $boardId, ?int $itemId = null): array {
        if ($itemId !== null) {
            $this->requireItemOnBoard($itemId, $boardId);
        }
        $sql = 'SELECT id, board_id, predecessor_item_id, successor_item_id, column_id, dependency_type, lag_minutes, created_by, created_at
                FROM tb_item_dependency WHERE board_id = ? AND deleted_at IS NULL';
        $params = [$boardId];
        if ($itemId !== null) {
            $sql .= ' AND (predecessor_item_id = ? OR successor_item_id = ?)';
            $params[] = $itemId;
            $params[] = $itemId;
        }
        $sql .= ' ORDER BY created_at, id';
        return $this->all($sql, $params);
    }

    public function addDependency(int $boardId, int $userId, array $input): array {
        $predecessorId = $this->positiveInt($input['predecessor_item_id'] ?? null, 'predecessor_item_id');
        $successorId = $this->positiveInt($input['successor_item_id'] ?? null, 'successor_item_id');
        $columnId = $this->optionalPositiveInt($input['column_id'] ?? null, 'column_id');
        $type = strtolower(trim((string)($input['dependency_type'] ?? 'finish_to_start')));
        if (!in_array($type, ['finish_to_start', 'start_to_start', 'finish_to_finish', 'start_to_finish'], true)) {
            throw new TablerosApiException('validation', 'El tipo de dependencia no está permitido.', 422);
        }
        $lag = isset($input['lag_minutes']) ? $this->integerValue($input['lag_minutes'], 'lag_minutes') : 0;
        if ($lag < -525600 || $lag > 525600) {
            throw new TablerosApiException('validation', 'El desfase debe estar dentro de un año.', 422);
        }
        $this->requireItemOnBoard($predecessorId, $boardId);
        $this->requireItemOnBoard($successorId, $boardId);
        if ($predecessorId === $successorId) {
            throw new TablerosApiException('validation', 'Un elemento no puede depender de sí mismo.', 422);
        }
        if ($columnId !== null && !$this->one('SELECT id FROM tb_column WHERE id = ? AND board_id = ? AND deleted_at IS NULL', [$columnId, $boardId])) {
            throw new TablerosApiException('validation', 'La columna no pertenece a este tablero.', 422);
        }

        return $this->transaction(function () use ($boardId, $userId, $predecessorId, $successorId, $columnId, $type, $lag): array {
            $edges = $this->all(
                'SELECT predecessor_item_id, successor_item_id FROM tb_item_dependency WITH (UPDLOCK, HOLDLOCK)
                 WHERE board_id = ? AND deleted_at IS NULL',
                [$boardId]
            );
            $graph = [];
            foreach ($edges as $edge) {
                $graph[(int)$edge['predecessor_item_id']][] = (int)$edge['successor_item_id'];
            }
            if ($this->pathExists($graph, $successorId, $predecessorId)) {
                throw new TablerosApiException('conflict', 'La dependencia crearía un ciclo.', 409);
            }
            $id = $this->insertId(
                'INSERT INTO tb_item_dependency (board_id, predecessor_item_id, successor_item_id, column_id, dependency_type, lag_minutes, created_by, created_at)
                 OUTPUT INSERTED.id VALUES (?, ?, ?, ?, ?, ?, ?, GETDATE())',
                [$boardId, $predecessorId, $successorId, $columnId, $type, $lag, $userId]
            );
            $this->writeActivity($boardId, $predecessorId, $userId, 'dependency.created', [
                'dependency_id' => $id, 'successor_item_id' => $successorId, 'dependency_type' => $type,
            ]);
            return ['id' => $id, 'predecessor_item_id' => $predecessorId, 'successor_item_id' => $successorId, 'dependency_type' => $type, 'lag_minutes' => $lag];
        });
    }

    public function removeDependency(int $boardId, int $userId, array $input): array {
        $dependencyId = $this->positiveInt($input['dependency_id'] ?? null, 'dependency_id');
        return $this->transaction(function () use ($boardId, $userId, $dependencyId): array {
            $dependency = $this->one(
                'SELECT id, predecessor_item_id FROM tb_item_dependency WITH (UPDLOCK, HOLDLOCK)
                 WHERE id = ? AND board_id = ? AND deleted_at IS NULL',
                [$dependencyId, $boardId]
            );
            if (!$dependency) {
                throw new TablerosApiException('not_found', 'No se encontró la dependencia.', 404);
            }
            $this->execute('UPDATE tb_item_dependency SET deleted_at = GETDATE() WHERE id = ?', [$dependencyId]);
            $this->writeActivity($boardId, (int)$dependency['predecessor_item_id'], $userId, 'dependency.deleted', ['dependency_id' => $dependencyId]);
            return ['id' => $dependencyId, 'deleted' => true];
        });
    }

    public function shareBoard(int $boardId, int $userId, array $input): array {
        $this->requireBoardAllowed($boardId, ['owner','designer','editor']);
        $members = $input['members'] ?? null;
        if ($members === null && isset($input['user_id'])) {
            $members = [['user_id' => $input['user_id'], 'role' => $input['role'] ?? 'viewer']];
        }
        if (!is_array($members) || count($members) > 200) {
            throw new TablerosApiException('validation', 'La lista de usuarios no es válida.', 422);
        }
        $normalized = [];
        foreach ($members as $member) {
            if (!is_array($member)) {
                throw new TablerosApiException('validation', 'Cada miembro debe incluir un ID y un rol.', 422);
            }
            $targetId = $this->positiveInt($member['user_id'] ?? null, 'user_id');
            if (!is_string($member['role'] ?? 'viewer')) {
                throw new TablerosApiException('validation', 'El rol de miembro no es válido.', 422);
            }
            $role = strtolower(trim((string)($member['role'] ?? 'viewer')));
            if (!in_array($role, ['designer', 'editor', 'contributor', 'viewer'], true)) {
                throw new TablerosApiException('validation', 'El rol de miembro no está permitido.', 422);
            }
            if ($targetId === $userId) {
                continue;
            }
            $normalized[$targetId] = $role;
        }
        foreach (array_keys($normalized) as $targetId) {
            if (!$this->userIsActive((int)$targetId) || !$this->access->userHasModuleAccess((int)$targetId)) {
                throw new TablerosApiException('validation', 'Todos los usuarios asignados deben estar activos y tener acceso al módulo Tableros.', 422);
            }
        }

        return $this->transaction(function () use ($boardId, $userId, $normalized): array {
            $board = $this->one('SELECT created_by, name FROM tb_board WHERE id = ? AND deleted_at IS NULL', [$boardId]);
            if (!$board) {
                throw new TablerosApiException('not_found', 'No se encontró el tablero.', 404);
            }
            foreach ($normalized as $targetId => $role) {
                $existing = $this->one(
                    'SELECT TOP (1) id, role, deleted_at FROM tb_board_member WITH (UPDLOCK, HOLDLOCK) WHERE board_id = ? AND user_id = ? ORDER BY id DESC',
                    [$boardId, $targetId]
                );
                if ((int)$board['created_by'] === (int)$targetId) {
                    continue;
                }
                $wasActiveMember = $existing && $existing['deleted_at'] === null;
                if ($existing) {
                    $this->execute('UPDATE tb_board_member SET role = ?, invited_by = ?, updated_at = GETDATE(), deleted_at = NULL WHERE id = ?', [$role, $userId, $existing['id']]);
                } else {
                    $this->execute(
                        'INSERT INTO tb_board_member (board_id, user_id, role, invited_by, created_at) VALUES (?, ?, ?, ?, GETDATE())',
                        [$boardId, $targetId, $role, $userId]
                    );
                }
                if (!$wasActiveMember) {
                    $this->createNotification((int)$targetId, $userId, $boardId, null, null, null, null, 'board.member.added', ['board_name' => (string)$board['name'], 'role' => $role], 'board-member:' . $boardId . ':' . $targetId . ':' . bin2hex(random_bytes(8)));
                }
                $this->auditAccess($userId, 'board.member_shared', 'board', $boardId, ['user_id' => (int)$targetId, 'role' => $role]);
            }
            $this->writeActivity($boardId, null, $userId, 'board.shared', ['user_ids' => array_map('intval', array_keys($normalized))]);
            return ['board_id' => $boardId, 'members' => array_map(
                static fn($targetId, $role) => ['user_id' => (int)$targetId, 'role' => $role],
                array_keys($normalized), array_values($normalized)
            )];
        });
    }

    public function updateBoardVisibility(int $boardId, int $userId, array $input): array {
        if (!is_string($input['visibility'] ?? null)) {
            throw new TablerosApiException('validation', 'La visibilidad del tablero no es válida.', 422);
        }
        $visibility = strtolower(trim($input['visibility']));
        if (!in_array($visibility, ['private', 'workspace', 'organization'], true)) {
            throw new TablerosApiException('validation', 'La visibilidad del tablero no está permitida.', 422);
        }

        return $this->transaction(function () use ($boardId, $userId, $visibility): array {
            $board = $this->one(
                'SELECT created_by, visibility FROM tb_board WITH (UPDLOCK, HOLDLOCK)
                 WHERE id = ? AND deleted_at IS NULL',
                [$boardId]
            );
            if (!$board) {
                throw new TablerosApiException('not_found', 'No se encontró el tablero.', 404);
            }
            $member = $this->one(
                'SELECT TOP (1) role FROM tb_board_member WITH (UPDLOCK, HOLDLOCK)
                 WHERE board_id = ? AND user_id = ? AND deleted_at IS NULL ORDER BY id DESC',
                [$boardId, $userId]
            );
            $isOwner = (int)$board['created_by'] === $userId;
            $isDesigner = strtolower((string)($member['role'] ?? '')) === 'designer';
            $isEditor = strtolower((string)($member['role'] ?? '')) === 'editor';
            $isAdmin = $this->access->hasGlobalPermission('admin');
            if (!$isOwner && !$isDesigner && !$isEditor && !$isAdmin) {
                throw new TablerosApiException('forbidden', 'Solo el propietario, el diseñador o el editor puede cambiar la visibilidad del tablero.', 403);
            }
            $previousVisibility = (string)$board['visibility'];
            if ($previousVisibility !== $visibility) {
                $this->execute('UPDATE tb_board SET visibility = ?, updated_at = GETDATE() WHERE id = ?', [$visibility, $boardId]);
                $this->writeActivity($boardId, null, $userId, 'board.visibility_changed', [
                    'from' => $previousVisibility,
                    'to' => $visibility,
                ]);
            }
            return ['id' => $boardId, 'visibility' => $visibility];
        });
    }

    public function createView(int $boardId, int $userId, array $input): array {
        $name = $this->requiredString($input, 'name', 120);
        if (!is_string($input['view_type'] ?? 'table')) {
            throw new TablerosApiException('validation', 'El tipo de vista no es válido.', 422);
        }
        $viewType = strtolower(trim((string)($input['view_type'] ?? 'table')));
        if (!in_array($viewType, ['table', 'kanban', 'calendar', 'timeline', 'dashboard'], true)) {
            throw new TablerosApiException('validation', 'El tipo de vista no está permitido.', 422);
        }
        $config = $input['config'] ?? [];
        if (!is_array($config) || strlen((string)json_encode($config, JSON_UNESCAPED_UNICODE)) > 64000) {
            throw new TablerosApiException('validation', 'La configuración de la vista no es válida.', 422);
        }
        $shared = $this->booleanInput($input, 'is_shared', false);
        $id = $this->insertId(
            'INSERT INTO tb_view (board_id, name, view_type, config_json, owner_user_id, is_shared, created_at, updated_at)
             OUTPUT INSERTED.id VALUES (?, ?, ?, ?, ?, ?, GETDATE(), GETDATE())',
            [$boardId, $name, $viewType, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $userId, $shared ? 1 : 0]
        );
        return ['id' => $id, 'board_id' => $boardId, 'name' => $name, 'view_type' => $viewType, 'config' => $config, 'owner_user_id' => $userId, 'is_shared' => $shared];
    }

    public function getAutomations(int $boardId): array {
        $automations = $this->all(
            'SELECT id, board_id, name, status, definition_json, created_by, updated_by, created_at, updated_at, version
             FROM tb_automation WHERE board_id = ? AND deleted_at IS NULL ORDER BY created_at, id',
            [$boardId]
        );
        foreach ($automations as &$automation) {
            $automation['definition'] = $this->decodeJson($automation['definition_json'] ?? null, []);
            $automation['version'] = $this->versionToken($automation['version'] ?? null);
            unset($automation['definition_json']);
        }
        unset($automation);
        return $automations;
    }

    public function createAutomation(int $boardId, int $userId, array $input): array {
        $name = $this->requiredString($input, 'name', 200);
        $definition = $input['definition'] ?? null;
        if (!is_array($definition)) {
            throw new TablerosApiException('validation', 'La definición de automatización debe ser un objeto JSON.', 422);
        }
        $definition = $this->validateAutomationDefinition($boardId, $definition);
        $json = json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || strlen($json) > 64000) {
            throw new TablerosApiException('validation', 'La definición de automatización es demasiado grande o no es válida.', 422);
        }
        $id = $this->insertId(
            'INSERT INTO tb_automation (board_id, name, status, definition_json, created_by, updated_by, created_at, updated_at)
             OUTPUT INSERTED.id VALUES (?, ?, ?, ?, ?, ?, GETDATE(), GETDATE())',
            [$boardId, $name, 'draft', $json, $userId, $userId]
        );
        $this->writeActivity($boardId, null, $userId, 'automation.created', ['automation_id' => $id, 'name' => $name]);
        return ['id' => $id, 'board_id' => $boardId, 'name' => $name, 'status' => 'draft', 'definition' => $definition];
    }

    public function updateAutomation(int $boardId, int $userId, array $input): array {
        $automationId = $this->positiveInt($input['automation_id'] ?? null, 'automation_id');
        $current = $this->one(
            'SELECT id, name, definition_json, version FROM tb_automation WHERE id = ? AND board_id = ? AND deleted_at IS NULL',
            [$automationId, $boardId]
        );
        if (!$current) {
            throw new TablerosApiException('not_found', 'No se encontró la automatización.', 404);
        }
        $expectedVersion = $this->versionInput($input['version'] ?? $this->versionToken($current['version'] ?? null));
        $name = array_key_exists('name', $input) ? $this->requiredString($input, 'name', 200) : (string)$current['name'];
        $rawDefinition = array_key_exists('definition', $input) ? $input['definition'] : $this->decodeJson($current['definition_json'] ?? null, []);
        if (!is_array($rawDefinition)) {
            throw new TablerosApiException('validation', 'La definición de automatización debe ser un objeto JSON.', 422);
        }
        $definition = $this->validateAutomationDefinition($boardId, $rawDefinition);
        $json = json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || strlen($json) > 64000) {
            throw new TablerosApiException('validation', 'La definición de automatización es demasiado grande o no es válida.', 422);
        }
        $updated = $this->returningOne(
            'UPDATE tb_automation SET name = ?, definition_json = ?, updated_by = ?, updated_at = GETDATE()
             OUTPUT INSERTED.version
             WHERE id = ? AND board_id = ? AND version = CONVERT(VARBINARY(8), ?, 2) AND deleted_at IS NULL',
            [$name, $json, $userId, $automationId, $boardId, $expectedVersion]
        );
        if (!$updated) {
            throw new TablerosApiException('conflict', 'La automatización cambió. Recarga e inténtalo de nuevo.', 409);
        }
        $this->writeActivity($boardId, null, $userId, 'automation.updated', ['automation_id' => $automationId]);
        return ['id' => $automationId, 'board_id' => $boardId, 'name' => $name, 'definition' => $definition, 'version' => $this->versionToken($updated['version'] ?? null)];
    }

    public function setAutomationStatus(int $boardId, int $userId, array $input, string $status): array {
        if (!in_array($status, ['active', 'paused'], true)) {
            throw new LogicException('Invalid automation status');
        }
        $automationId = $this->positiveInt($input['automation_id'] ?? null, 'automation_id');
        $current = $this->one(
            'SELECT id, status, definition_json, version FROM tb_automation WHERE id = ? AND board_id = ? AND deleted_at IS NULL',
            [$automationId, $boardId]
        );
        if (!$current) {
            throw new TablerosApiException('not_found', 'No se encontró la automatización.', 404);
        }
        if ($status === 'active') {
            $definition = $this->decodeJson($current['definition_json'] ?? null, []);
            if (!is_array($definition)) {
                throw new TablerosApiException('validation', 'La definición de automatización no es válida.', 422);
            }
            $this->validateAutomationDefinition($boardId, $definition);
        }
        $expected = $this->versionInput($input['version'] ?? $this->versionToken($current['version'] ?? null));
        $updated = $this->returningOne(
            'UPDATE tb_automation SET status = ?, updated_by = ?, updated_at = GETDATE()
             OUTPUT INSERTED.version
             WHERE id = ? AND board_id = ? AND version = CONVERT(VARBINARY(8), ?, 2) AND deleted_at IS NULL',
            [$status, $userId, $automationId, $boardId, $expected]
        );
        if (!$updated) {
            throw new TablerosApiException('conflict', 'La automatización cambió. Recarga e inténtalo de nuevo.', 409);
        }
        $this->writeActivity($boardId, null, $userId, 'automation.' . $status, ['automation_id' => $automationId]);
        return ['id' => $automationId, 'status' => $status, 'version' => $this->versionToken($updated['version'] ?? null)];
    }

    public function deleteAutomation(int $boardId, int $userId, array $input): array {
        $automationId = $this->positiveInt($input['automation_id'] ?? null, 'automation_id');
        $affected = $this->execute(
            'UPDATE tb_automation SET status = ?, updated_by = ?, updated_at = GETDATE(), deleted_at = GETDATE()
             WHERE id = ? AND board_id = ? AND deleted_at IS NULL',
            ['disabled', $userId, $automationId, $boardId]
        );
        if ($affected === 0) {
            throw new TablerosApiException('not_found', 'No se encontró la automatización.', 404);
        }
        $this->writeActivity($boardId, null, $userId, 'automation.deleted', ['automation_id' => $automationId]);
        return ['id' => $automationId, 'deleted' => true];
    }

    public function getAutomationRuns(int $boardId, ?int $automationId = null, int $limit = 100): array {
        $limit = max(1, min(200, $limit));
        $sql = "SELECT TOP ($limit) id, automation_id, board_id, status, idempotency_key, initiated_by,
                       started_at, finished_at, result_json, error_message, created_at
                FROM tb_automation_run WHERE board_id = ?";
        $params = [$boardId];
        if ($automationId !== null) {
            $sql .= ' AND automation_id = ?';
            $params[] = $automationId;
        }
        $sql .= ' ORDER BY created_at DESC, id DESC';
        $runs = $this->all($sql, $params);
        foreach ($runs as &$run) {
            $run['result'] = $this->decodeJson($run['result_json'] ?? null, null);
            unset($run['result_json']);
        }
        unset($run);
        return $runs;
    }

    public function enqueueAutomationRun(int $boardId, int $userId, array $input): array {
        $automationId = $this->positiveInt($input['automation_id'] ?? null, 'automation_id');
        $automation = $this->one(
            'SELECT id, status, definition_json FROM tb_automation WHERE id = ? AND board_id = ? AND deleted_at IS NULL',
            [$automationId, $boardId]
        );
        if (!$automation) {
            throw new TablerosApiException('not_found', 'No se encontró la automatización.', 404);
        }
        if ((string)$automation['status'] !== 'active') {
            throw new TablerosApiException('conflict', 'Solo se pueden ejecutar automatizaciones activas.', 409);
        }
        $definition = $this->decodeJson($automation['definition_json'] ?? null, []);
        if (!is_array($definition) || (($definition['trigger']['type'] ?? null) !== 'manual')) {
            throw new TablerosApiException('conflict', 'Esta automatización no está configurada para ejecución manual.', 409);
        }
        $key = $input['idempotency_key'] ?? ('manual:' . bin2hex(random_bytes(16)));
        if (!is_string($key) || !str_starts_with($key, 'manual:') || trim($key) === '' || strlen($key) > 200 || !preg_match('/^[A-Za-z0-9_.:-]+$/', $key)) {
            throw new TablerosApiException('validation', 'La clave de idempotencia no es válida.', 422);
        }
        return $this->insertAutomationRun($automationId, $boardId, $userId, $key);
    }

    /** Queue due schedule ticks. The caller holds the worker-wide DB application lock. */
    public function queueDueAutomations(?int $epoch = null): int {
        $epoch = $epoch ?? time();
        $minute = intdiv($epoch, 60);
        $automations = $this->all(
            "SELECT id, board_id, definition_json FROM tb_automation WHERE status = 'active' AND deleted_at IS NULL ORDER BY id",
            []
        );
        $queued = 0;
        foreach ($automations as $automation) {
            $definition = $this->decodeJson($automation['definition_json'] ?? null, []);
            $trigger = is_array($definition) ? ($definition['trigger'] ?? []) : [];
            if (!is_array($trigger) || ($trigger['type'] ?? '') !== 'schedule') {
                continue;
            }
            $interval = (int)($trigger['every_minutes'] ?? 0);
            if ($interval <= 0 || $minute % $interval !== 0) {
                continue;
            }
            $slot = intdiv($minute, $interval);
            $result = $this->insertAutomationRun((int)$automation['id'], (int)$automation['board_id'], null, 'schedule:' . $slot);
            if (empty($result['duplicate'])) {
                $queued++;
            }
        }
        return $queued;
    }

    /** Claim and execute pending run records. Atomic row claims allow more than one worker. */
    public function processAutomationQueue(int $limit = 50): array {
        $limit = max(1, min(500, $limit));
        $counts = ['claimed' => 0, 'succeeded' => 0, 'failed' => 0, 'cancelled' => 0];
        while ($counts['claimed'] < $limit) {
            $runId = $this->transaction(function (): ?int {
                $candidate = $this->one(
                    "SELECT TOP (1) id FROM tb_automation_run WITH (UPDLOCK, READPAST, ROWLOCK)
                     WHERE status = 'pending' ORDER BY created_at, id"
                );
                if (!$candidate) {
                    return null;
                }
                $claimed = $this->returningOne(
                    "UPDATE tb_automation_run SET status = 'running', started_at = GETDATE()
                     OUTPUT INSERTED.id WHERE id = ? AND status = 'pending'",
                    [(int)$candidate['id']]
                );
                return $claimed ? (int)$claimed['id'] : null;
            });
            if ($runId === null) {
                break;
            }
            $counts['claimed']++;
            $status = $this->executeAutomationRun($runId);
            if (isset($counts[$status])) {
                $counts[$status]++;
            }
        }
        return $counts;
    }

    public function expireStaleAutomationRuns(int $staleMinutes = 30): int {
        $staleMinutes = max(5, min(1440, $staleMinutes));
        return $this->transaction(function () use ($staleMinutes): int {
            $staleRuns = $this->all(
                "SELECT TOP (5000) id, automation_id
                 FROM tb_automation_run WITH (UPDLOCK, READPAST, ROWLOCK)
                 WHERE status = 'running' AND started_at < DATEADD(MINUTE, CAST(? AS int), GETDATE())
                 ORDER BY started_at, id",
                [-$staleMinutes]
            );
            $affected = 0;
            foreach ($staleRuns as $run) {
                $updated = $this->execute(
                    "UPDATE tb_automation_run SET status = 'failed', finished_at = GETDATE(),
                            error_message = 'La ejecución se interrumpió antes de finalizar.'
                     WHERE id = ? AND status = 'running'",
                    [(int)$run['id']]
                );
                if ($updated === 0) {
                    continue;
                }
                $affected += $updated;
                $this->execute(
                    "UPDATE tb_automation SET status = 'error', updated_at = GETDATE()
                     WHERE id = ? AND status = 'active'",
                    [(int)$run['automation_id']]
                );
            }
            return $affected;
        });
    }

    private function validateCellValue(string $type, $value, array $options) {
        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            throw new TablerosApiException('validation', 'El tipo de columna no está permitido.', 422);
        }
        if ($value === null || $value === '') {
            if (in_array($type, ['people', 'person'], true)) {
                return [];
            }
            return null;
        }
        if (in_array($type, ['people', 'person'], true)) {
            if ($type === 'person' && is_array($value) && (isset($value['id']) || isset($value['user_id']))) {
                $users = [$value];
            } elseif ($type === 'person' && !is_array($value)) {
                $users = [$value];
            } elseif ($type === 'person' && is_array($value) && array_is_list($value)) {
                $users = $value;
            } else {
                $users = is_array($value) ? $value : null;
            }
            if ($users === null) {
                throw new TablerosApiException('validation', 'El valor de personas debe ser una lista de usuarios.', 422);
            }
            if (count($users) > 100 || ($type === 'person' && count($users) > 1)) {
                throw new TablerosApiException('validation', 'La cantidad de personas asignadas no es válida.', 422);
            }
            $ids = [];
            foreach ($users as $person) {
                $id = is_array($person) ? ($person['id'] ?? $person['user_id'] ?? null) : $person;
                $id = $this->positiveInt($id, 'person_id');
                if (!$this->userIsActive($id)) {
                    throw new TablerosApiException('validation', 'Solo se pueden asignar usuarios activos de TG.', 422);
                }
                $ids[] = $id;
            }
            return array_values(array_unique($ids));
        }
        if (in_array($type, ['numbers', 'progress', 'rating', 'item_id'], true)) {
            if ($type === 'item_id') {
                return $this->positiveInt($value, 'item_id');
            }
            if (!is_numeric($value) || !is_finite((float)$value)) {
                throw new TablerosApiException('validation', 'El valor debe ser numérico.', 422);
            }
            $number = (float)$value;
            if ($type === 'progress' && ($number < 0 || $number > 100)) {
                throw new TablerosApiException('validation', 'El progreso debe estar entre 0 y 100.', 422);
            }
            if ($type === 'rating') {
                $maximum = ($options['display'] ?? 'stars') === 'percentage' ? 100 : (float)($options['max'] ?? 5);
                if ($number < 0 || $number > $maximum) {
                    throw new TablerosApiException('validation', 'La calificación está fuera del rango permitido.', 422);
                }
            }
            return $number;
        }
        if ($type === 'date') {
            $date = is_array($value) ? ($value['date'] ?? null) : $value;
            if (!is_string($date) || !$this->validDate($date)) {
                throw new TablerosApiException('validation', 'La fecha debe usar el formato YYYY-MM-DD.', 422);
            }
            return $date;
        }
        if ($type === 'hour') {
            $hour = is_array($value) ? ($value['time'] ?? null) : $value;
            if (!is_string($hour) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $hour)) {
                throw new TablerosApiException('validation', 'La hora debe usar el formato HH:MM o HH:MM:SS.', 422);
            }
            return $hour;
        }
        if ($type === 'formula') {
            $formula = is_string($value) ? trim($value) : '';
            if (strlen($formula) > 2000 || !$this->validFormula($formula)) {
                throw new TablerosApiException('validation', 'La fórmula contiene sintaxis no permitida.', 422);
            }
            return $formula;
        }
        if ($type === 'numbers') {
            return (float)$value;
        }

        if (in_array($type, ['status', 'dropdown', 'tags'], true)) {
            $allowed = $this->optionValues($options);
            $values = $type === 'tags' ? (is_array($value) ? $value : null) : [$value];
            if ($values === null) {
                throw new TablerosApiException('validation', 'Las etiquetas deben ser una lista.', 422);
            }
            if ($allowed) {
                foreach ($values as $selected) {
                    $candidate = is_array($selected) ? ($selected['value'] ?? $selected['label'] ?? $selected['id'] ?? $selected['name'] ?? null) : $selected;
                    if (!in_array((string)$candidate, $allowed, true)) {
                        throw new TablerosApiException('validation', 'El valor no coincide con las opciones de la columna.', 422);
                    }
                }
            }
            return $type === 'tags' ? array_values($values) : reset($values);
        }

        if ($type === 'link') {
            $url = is_array($value) ? (string)($value['url'] ?? $value['value'] ?? '') : (is_scalar($value) ? (string)$value : '');
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                throw new TablerosApiException('validation', 'El enlace no es válido.', 422);
            }
            if (is_array($value)) {
                $value['url'] = $url;
                return $value;
            }
            return ['url' => $url];
        }

        if (in_array($type, ['text', 'name', 'long_text', 'email', 'phone'], true)) {
            if (!is_scalar($value)) {
                throw new TablerosApiException('validation', 'El valor debe ser texto.', 422);
            }
            $string = (string)$value;
            $max = $type === 'long_text' ? 20000 : 2000;
            if ($this->textLength($string) > $max) {
                throw new TablerosApiException('validation', 'El valor excede el límite de caracteres.', 422);
            }
            if ($type === 'email' && !filter_var($string, FILTER_VALIDATE_EMAIL)) {
                throw new TablerosApiException('validation', 'El correo electrónico no es válido.', 422);
            }
            return $string;
        }

        if (in_array($type, ['team', 'timeline', 'file', 'country', 'location', 'board_relation', 'subtasks', 'dependency'], true)) {
            if (!is_array($value) && !is_string($value)) {
                throw new TablerosApiException('validation', 'El valor estructurado debe ser JSON u otro texto válido.', 422);
            }
            if (in_array($type, ['board_relation', 'subtasks', 'dependency'], true) && !is_array($value)) {
                throw new TablerosApiException('validation', 'Las relaciones deben enviarse como una lista u objeto JSON.', 422);
            }
            if (strlen((string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) > 64000) {
                throw new TablerosApiException('validation', 'El valor estructurado es demasiado grande.', 422);
            }
            if ($type === 'timeline' && is_array($value)) {
                foreach (['start', 'end'] as $field) {
                    if (isset($value[$field]) && (!is_string($value[$field]) || strtotime($value[$field]) === false)) {
                        throw new TablerosApiException('validation', 'El intervalo de fechas no es válido.', 422);
                    }
                }
                if (isset($value['start'], $value['end']) && strtotime($value['end']) < strtotime($value['start'])) {
                    throw new TablerosApiException('validation', 'La fecha final debe ser posterior a la fecha inicial.', 422);
                }
            }
            return $value;
        }

        throw new TablerosApiException('validation', 'El valor no es compatible con el tipo de columna.', 422);
    }

    private function userHasBoardAccess(int $userId, int $boardId): bool {
        return $this->one(
            'SELECT TOP (1) u.Id
             FROM [TG].[dbo].[Usuario] u
             INNER JOIN dbo.tb_board b ON b.id = ? AND b.deleted_at IS NULL
             INNER JOIN dbo.tb_workspace w ON w.id = b.workspace_id AND w.deleted_at IS NULL
             WHERE u.Id = ? AND u.Estatus = 1
               AND (u.Id = b.created_by
                    OR EXISTS (SELECT 1 FROM dbo.tb_board_member bm
                               WHERE bm.board_id = b.id AND bm.user_id = u.Id AND bm.deleted_at IS NULL)
                    OR b.visibility = \'organization\'
                    OR (b.visibility = \'workspace\' AND (
                        u.Id = w.created_by
                        OR EXISTS (SELECT 1 FROM dbo.tb_workspace_member wm
                                   WHERE wm.workspace_id = w.id AND wm.user_id = u.Id AND wm.deleted_at IS NULL)
                        OR w.visibility = \'open\')))',
            [$boardId, $userId]
        ) !== null;
    }

    private function ensureAssignedUserBoardAccess(int $targetUserId, int $boardId, int $itemId, ?int $actorId): void {
        if (!$this->userIsActive($targetUserId) || !$this->access->userHasModuleAccess($targetUserId)) {
            throw new TablerosApiException('validation', 'Las personas asignadas deben tener una cuenta activa y acceso al módulo Tableros.', 422);
        }
        if ($this->userHasBoardAccess($targetUserId, $boardId)) {
            return;
        }

        // The caller is already inside updateCell's transaction. Lock the board/member
        // key so concurrent assignments cannot create duplicate direct memberships.
        $board = $this->one(
            'SELECT created_by, name FROM tb_board WITH (UPDLOCK, HOLDLOCK) WHERE id = ? AND deleted_at IS NULL',
            [$boardId]
        );
        if (!$board) {
            throw new TablerosApiException('not_found', 'No se encontró el tablero.', 404);
        }
        if ((int)$board['created_by'] === $targetUserId || $this->userHasBoardAccess($targetUserId, $boardId)) {
            return;
        }

        $existing = $this->one(
            'SELECT TOP (1) id, role FROM tb_board_member WITH (UPDLOCK, HOLDLOCK)
             WHERE board_id = ? AND user_id = ? ORDER BY id DESC',
            [$boardId, $targetUserId]
        );
        if ($existing) {
            // Item assignment grants reader access only. Do not restore a revoked elevated role.
            $this->execute(
                'UPDATE tb_board_member SET role = ?, invited_by = ?, updated_at = GETDATE(), deleted_at = NULL WHERE id = ?',
                ['viewer', $actorId, $existing['id']]
            );
            $role = 'viewer';
        } else {
            $this->execute(
                'INSERT INTO tb_board_member (board_id, user_id, role, invited_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, GETDATE(), GETDATE())',
                [$boardId, $targetUserId, 'viewer', $actorId]
            );
            $role = 'viewer';
        }

        $auditActorId = $actorId ?? (int)$board['created_by'];
        $accessMetadata = [
            'user_id' => $targetUserId,
            'role' => $role,
            'source' => 'item_assignment',
            'item_id' => $itemId,
        ];
        $this->createNotification(
            $targetUserId,
            $auditActorId,
            $boardId,
            $itemId,
            null,
            null,
            null,
            'board.member.added',
            ['board_name' => (string)$board['name'], 'role' => $role, 'source' => 'item_assignment', 'item_id' => $itemId],
            'board-member-assignment:' . $boardId . ':' . $targetUserId . ':' . bin2hex(random_bytes(8))
        );
        $this->auditAccess($auditActorId, 'board.member_shared', 'board', $boardId, $accessMetadata);
        $this->writeActivity($boardId, $itemId, $auditActorId, 'board.member_shared', $accessMetadata);
    }

    private function cellStorage(string $type, $value): array {
        $storage = [null, null, null, null, null, null];
        if ($value === null) {
            return $storage;
        }
        if (in_array($type, ['people', 'person'], true)) {
            return $storage;
        }
        if (in_array($type, ['numbers', 'progress', 'rating', 'item_id'], true)) {
            $storage[1] = $value;
        } elseif ($type === 'date') {
            $storage[2] = $value;
        } elseif ($type === 'hour') {
            $storage[0] = $value;
        } elseif (in_array($type, ['status', 'dropdown'], true)) {
            $storage[0] = is_scalar($value) ? (string)$value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } elseif (in_array($type, ['people', 'person', 'team', 'tags', 'timeline', 'file', 'country', 'link', 'location', 'board_relation', 'subtasks', 'dependency'], true)) {
            $storage[5] = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            $storage[0] = $value;
        }
        return $storage;
    }

    private function cellValue(array $cell) {
        if ($cell['value_json'] !== null) {
            return $this->decodeJson($cell['value_json'], $cell['value_json']);
        }
        if ($cell['value_number'] !== null) {
            return (float)$cell['value_number'];
        }
        if ($cell['value_date'] !== null) {
            return $cell['value_date'];
        }
        if ($cell['value_datetime'] !== null) {
            return $cell['value_datetime'];
        }
        if ($cell['value_boolean'] !== null) {
            return (bool)$cell['value_boolean'];
        }
        return $cell['value_text'];
    }

    private function optionValues(array $options): array {
        if ($options === [] || array_keys($options) === range(0, count($options) - 1)) {
            $source = $options;
        } else {
            $source = null;
        }
        foreach (['options', 'choices', 'labels', 'values'] as $key) {
            if (isset($options[$key]) && is_array($options[$key])) {
                $source = $options[$key];
                break;
            }
        }
        if ($source === null) {
            return [];
        }
        $values = [];
        foreach ($source as $option) {
            if (is_array($option)) {
                $value = $option['value'] ?? $option['label'] ?? $option['id'] ?? $option['name'] ?? null;
            } else {
                $value = $option;
            }
            if (is_scalar($value)) {
                $values[] = (string)$value;
            }
        }
        return $values;
    }

    private function validFormula(string $formula): bool {
        if ($formula === '' || !preg_match('/^=?[A-Za-z0-9_ .\[\](),+\-*\/%^<>=!&|?:]+$/', $formula)) {
            return false;
        }
        if (preg_match('/\b(SELECT|INSERT|UPDATE|DELETE|DROP|ALTER|EXEC|EXECUTE|UNION|FROM|WHERE|INTO|CREATE|TRUNCATE)\b/i', $formula)) {
            return false;
        }
        preg_match_all('/\b([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $formula, $calls);
        $functions = ['SUM', 'AVERAGE', 'MIN', 'MAX', 'IF', 'ROUND', 'COUNT', 'COUNTA', 'ABS', 'CONCAT', 'TODAY'];
        foreach ($calls[1] as $function) {
            if (!in_array(strtoupper($function), $functions, true)) {
                return false;
            }
        }
        return substr_count($formula, '(') === substr_count($formula, ')');
    }

    private function requireGroupOnBoard(int $groupId, int $boardId): void {
        if (!$this->one('SELECT id FROM tb_group WHERE id = ? AND board_id = ? AND deleted_at IS NULL', [$groupId, $boardId])) {
            throw new TablerosApiException('validation', 'El grupo no pertenece a este tablero.', 422);
        }
    }

    private function requireItemOnBoard(int $itemId, int $boardId): void {
        if (!$this->one('SELECT id FROM tb_item WHERE id = ? AND board_id = ? AND deleted_at IS NULL', [$itemId, $boardId])) {
            throw new TablerosApiException('validation', 'El elemento relacionado no pertenece a este tablero.', 422);
        }
    }

    private function validateCellReferences(string $type, $value, int $boardId, int $itemId, int $columnId): void {
        if ($value === null) {
            return;
        }
        if ($type === 'item_id') {
            $this->requireItemOnBoard((int)$value, $boardId);
            return;
        }
        if (in_array($type, ['subtasks', 'dependency'], true)) {
            $itemIds = [];
            $this->collectReferenceIds($value, $itemIds, ['item_id', 'itemId', 'id', 'parent_item_id', 'predecessor_item_id', 'successor_item_id']);
            foreach (array_unique($itemIds) as $itemId) {
                $this->requireItemOnBoard((int)$itemId, $boardId);
            }
            return;
        }
        if ($type === 'file') {
            $entries = is_array($value) && array_is_list($value)
                ? $value
                : (is_array($value) && is_array($value['files'] ?? null)
                    ? $value['files']
                    : (is_array($value) && (isset($value['file_id']) || isset($value['id'])) ? [$value] : []));
            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    throw new TablerosApiException('validation', 'Cada referencia de archivo debe ser un objeto válido.', 422);
                }
                $rawId = $entry['file_id'] ?? $entry['id'] ?? null;
                if ($rawId === null) {
                    continue;
                }
                $fileId = $this->positiveInt($rawId, 'file_id');
                $file = $this->one(
                    'SELECT id FROM tb_file WHERE id = ? AND board_id = ? AND item_id = ? AND column_id = ? AND deleted_at IS NULL',
                    [$fileId, $boardId, $itemId, $columnId]
                );
                if (!$file) {
                    throw new TablerosApiException('validation', 'El archivo debe pertenecer a este elemento y columna.', 422);
                }
            }
            return;
        }
        if ($type !== 'board_relation') {
            return;
        }

        $references = [];
        $this->collectBoardReferences($value, $references, $boardId);
        if ($value !== [] && $references === []) {
            throw new TablerosApiException('validation', 'Las relaciones deben incluir un tablero o elemento válido.', 422);
        }
        foreach ($references as $reference) {
            $targetBoardId = $reference['board_id'];
            if ($this->access->boardRole($targetBoardId) === null) {
                throw new TablerosApiException('forbidden', 'No tienes acceso al tablero relacionado.', 403);
            }
            if ($reference['item_id'] !== null) {
                $this->requireItemOnBoard($reference['item_id'], $targetBoardId);
            }
        }
    }

    private function replaceItemPeople(int $boardId, int $itemId, int $columnId, ?int $actorId, array $userIds): void {
        $this->execute(
            'UPDATE tb_item_person SET deleted_at = GETDATE(), updated_at = GETDATE()
             WHERE item_id = ? AND board_id = ? AND column_id = ? AND deleted_at IS NULL',
            [$itemId, $boardId, $columnId]
        );
        foreach (array_values(array_unique($userIds)) as $position => $targetUserId) {
            $existing = $this->one(
                'SELECT TOP (1) id FROM tb_item_person WITH (UPDLOCK, HOLDLOCK)
                 WHERE item_id = ? AND board_id = ? AND column_id = ? AND user_id = ? ORDER BY id DESC',
                [$itemId, $boardId, $columnId, $targetUserId]
            );
            if ($existing) {
                $this->execute(
                    'UPDATE tb_item_person SET position = ?, updated_at = GETDATE(), deleted_at = NULL WHERE id = ?',
                    [$position, $existing['id']]
                );
            } else {
                $this->execute(
                    'INSERT INTO tb_item_person (item_id, board_id, column_id, user_id, position, created_by, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, GETDATE(), GETDATE())',
                    [$itemId, $boardId, $columnId, $targetUserId, $position, $actorId]
                );
            }
        }
    }

    private function validateAutomationDefinition(int $boardId, array $definition): array {
        $trigger = $definition['trigger'] ?? null;
        if (!is_array($trigger) || !in_array(($trigger['type'] ?? null), ['manual', 'schedule'], true)) {
            throw new TablerosApiException('validation', 'La automatización requiere un disparador manual o programado.', 422);
        }
        if ($trigger['type'] === 'schedule') {
            $interval = $this->positiveInt($trigger['every_minutes'] ?? null, 'every_minutes');
            if (!in_array($interval, [1, 5, 10, 15, 30, 60, 120, 360, 720, 1440], true)) {
                throw new TablerosApiException('validation', 'El intervalo debe ser una opción programada permitida.', 422);
            }
            $normalizedTrigger = ['type' => 'schedule', 'every_minutes' => $interval];
        } else {
            $normalizedTrigger = ['type' => 'manual'];
        }

        $filters = $definition['filters'] ?? null;
        if (!is_array($filters) || $filters === [] || count($filters) > 20) {
            throw new TablerosApiException('validation', 'Agrega entre 1 y 20 condiciones para limitar los elementos afectados.', 422);
        }
        $normalizedFilters = [];
        foreach ($filters as $filter) {
            if (!is_array($filter)) {
                throw new TablerosApiException('validation', 'Cada condición de automatización debe ser un objeto.', 422);
            }
            if (array_key_exists('group_id', $filter)) {
                $groupId = $this->positiveInt($filter['group_id'], 'group_id');
                $this->requireGroupOnBoard($groupId, $boardId);
                $normalizedFilters[] = ['group_id' => $groupId];
                continue;
            }
            $columnId = $this->positiveInt($filter['column_id'] ?? null, 'column_id');
            if (!$this->one('SELECT id FROM tb_column WHERE id = ? AND board_id = ? AND deleted_at IS NULL', [$columnId, $boardId])) {
                throw new TablerosApiException('validation', 'Una condición usa una columna ajena al tablero.', 422);
            }
            $operator = strtolower(trim((string)($filter['operator'] ?? '')));
            if (!in_array($operator, ['equals', 'not_equals', 'contains', 'is_empty', 'not_empty', 'before', 'after', 'greater_than', 'less_than'], true)) {
                throw new TablerosApiException('validation', 'El operador de una condición no está permitido.', 422);
            }
            $normalized = ['column_id' => $columnId, 'operator' => $operator];
            if (!in_array($operator, ['is_empty', 'not_empty'], true)) {
                if (!array_key_exists('value', $filter) || (!is_scalar($filter['value']) && !is_array($filter['value']))) {
                    throw new TablerosApiException('validation', 'La condición requiere un valor válido.', 422);
                }
                $normalized['value'] = $filter['value'];
            }
            $normalizedFilters[] = $normalized;
        }

        $actions = $definition['actions'] ?? null;
        if (!is_array($actions) || $actions === [] || count($actions) > 10) {
            throw new TablerosApiException('validation', 'Agrega entre 1 y 10 acciones permitidas.', 422);
        }
        $normalizedActions = [];
        foreach ($actions as $action) {
            if (!is_array($action)) {
                throw new TablerosApiException('validation', 'Cada acción debe ser un objeto.', 422);
            }
            $type = strtolower(trim((string)($action['type'] ?? '')));
            if ($type === 'set_status') {
                $columnId = $this->positiveInt($action['column_id'] ?? null, 'column_id');
                $column = $this->one('SELECT id, type, options_json FROM tb_column WHERE id = ? AND board_id = ? AND deleted_at IS NULL', [$columnId, $boardId]);
                if (!$column || !in_array((string)$column['type'], ['status', 'dropdown'], true) || !array_key_exists('value', $action)) {
                    throw new TablerosApiException('validation', 'set_status requiere una columna Estado o Lista del tablero y un valor.', 422);
                }
                $value = $this->validateCellValue((string)$column['type'], $action['value'], $this->decodeJson($column['options_json'] ?? null, []));
                $normalizedActions[] = ['type' => 'set_status', 'column_id' => $columnId, 'value' => $value];
            } elseif ($type === 'set_date') {
                $columnId = $this->positiveInt($action['column_id'] ?? null, 'column_id');
                $column = $this->one('SELECT id, type FROM tb_column WHERE id = ? AND board_id = ? AND deleted_at IS NULL', [$columnId, $boardId]);
                if (!$column || (string)$column['type'] !== 'date') {
                    throw new TablerosApiException('validation', 'set_date requiere una columna de fecha del tablero.', 422);
                }
                if (array_key_exists('offset_days', $action)) {
                    $offset = $this->integerValue($action['offset_days'], 'offset_days');
                    if ($offset < -3650 || $offset > 3650) {
                        throw new TablerosApiException('validation', 'El desfase de fecha debe estar dentro de diez años.', 422);
                    }
                    $normalizedActions[] = ['type' => 'set_date', 'column_id' => $columnId, 'offset_days' => $offset];
                } else {
                    $value = $action['value'] ?? null;
                    if (!is_string($value) || !$this->validDate($value)) {
                        throw new TablerosApiException('validation', 'set_date requiere una fecha YYYY-MM-DD o offset_days.', 422);
                    }
                    $normalizedActions[] = ['type' => 'set_date', 'column_id' => $columnId, 'value' => $value];
                }
            } elseif ($type === 'reminder') {
                $message = $this->requiredString(['message' => $action['message'] ?? null], 'message', 500);
                $normalizedActions[] = ['type' => 'reminder', 'message' => $message];
            } else {
                throw new TablerosApiException('validation', 'La acción de automatización no está permitida.', 422);
            }
        }
        return ['trigger' => $normalizedTrigger, 'filters' => $normalizedFilters, 'actions' => $normalizedActions];
    }

    private function insertAutomationRun(int $automationId, int $boardId, ?int $userId, string $idempotencyKey): array {
        return $this->transaction(function () use ($automationId, $boardId, $userId, $idempotencyKey): array {
            $automation = $this->one(
                'SELECT definition_json FROM tb_automation WITH (UPDLOCK, HOLDLOCK) WHERE id = ? AND board_id = ? AND deleted_at IS NULL',
                [$automationId, $boardId]
            );
            if (!$automation) {
                throw new TablerosApiException('not_found', 'No se encontró la automatización.', 404);
            }
            $existing = $this->one(
                'SELECT TOP (1) id, status, created_at FROM tb_automation_run WITH (UPDLOCK, HOLDLOCK)
                 WHERE automation_id = ? AND idempotency_key = ?',
                [$automationId, $idempotencyKey]
            );
            if ($existing) {
                return ['id' => (int)$existing['id'], 'automation_id' => $automationId, 'status' => $existing['status'], 'duplicate' => true];
            }
            $snapshot = json_encode(
                ['definition_snapshot' => $this->decodeJson($automation['definition_json'] ?? null, [])],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            $id = $this->insertId(
                'INSERT INTO tb_automation_run (automation_id, board_id, status, idempotency_key, initiated_by, result_json, created_at)
                 OUTPUT INSERTED.id VALUES (?, ?, ?, ?, ?, ?, GETDATE())',
                [$automationId, $boardId, 'pending', $idempotencyKey, $userId, $snapshot]
            );
            return ['id' => $id, 'automation_id' => $automationId, 'status' => 'pending', 'duplicate' => false];
        });
    }

    private function executeAutomationRun(int $runId): string {
        try {
            return $this->transaction(function () use ($runId): string {
                $run = $this->one('SELECT id, automation_id, board_id, initiated_by, result_json FROM tb_automation_run WHERE id = ?', [$runId]);
                if (!$run) {
                    return 'failed';
                }
                $automation = $this->one(
                    'SELECT id, status, definition_json, created_by FROM tb_automation WHERE id = ? AND board_id = ? AND deleted_at IS NULL',
                    [$run['automation_id'], $run['board_id']]
                );
                if (!$automation || (string)$automation['status'] !== 'active') {
                    $this->execute("UPDATE tb_automation_run SET status = 'cancelled', finished_at = GETDATE(), result_json = ? WHERE id = ?", ['{"reason":"automation_inactive"}', $runId]);
                    return 'cancelled';
                }
                $runSnapshot = $this->decodeJson($run['result_json'] ?? null, []);
                $definition = is_array($runSnapshot) && isset($runSnapshot['definition_snapshot'])
                    ? $runSnapshot['definition_snapshot']
                    : $this->decodeJson($automation['definition_json'] ?? null, []);
                if (!is_array($definition)) {
                    throw new RuntimeException('Automation definition is invalid');
                }
                $definition = $this->validateAutomationDefinition((int)$run['board_id'], $definition);
                $boardData = $this->getBoardData((int)$run['board_id'], (int)($run['initiated_by'] ?? 0));
                $items = array_values(array_filter($boardData['items'], fn(array $item): bool => $this->automationItemMatches($item, $definition['filters'])));
                if (count($items) > 10000) {
                    throw new RuntimeException('Automation item limit exceeded');
                }
                $actorRaw = $run['initiated_by'] ?? $automation['created_by'] ?? null;
                $actorId = $actorRaw === null ? null : (int)$actorRaw;
                $changed = 0;
                $reminders = 0;
                foreach ($items as &$item) {
                    foreach ($definition['actions'] as $action) {
                        if ($action['type'] === 'set_status' || $action['type'] === 'set_date') {
                            $value = $action['value'] ?? gmdate('Y-m-d', time() + ((int)($action['offset_days'] ?? 0) * 86400));
                            $result = $this->updateCell((int)$run['board_id'], $actorId, [
                                'item_id' => (int)$item['id'],
                                'column_id' => (int)$action['column_id'],
                                'value' => $value,
                                'version' => $item['version'],
                            ]);
                            $item['version'] = $result['version'];
                            $item['cells'][(string)$action['column_id']] = $value;
                            $changed++;
                        } else {
                            $this->writeActivity((int)$run['board_id'], (int)$item['id'], $actorId, 'automation.reminder', [
                                'automation_id' => (int)$automation['id'], 'run_id' => $runId, 'message' => $action['message'],
                            ]);
                            $reminders++;
                        }
                    }
                }
                unset($item);
                $result = ['matched_items' => count($items), 'cell_updates' => $changed, 'reminder_events' => $reminders];
                $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $this->execute("UPDATE tb_automation_run SET status = 'succeeded', finished_at = GETDATE(), result_json = ?, error_message = NULL WHERE id = ?", [$json, $runId]);
                return 'succeeded';
            });
        } catch (Throwable $e) {
            error_log('Tableros automation run ' . $runId . ' failed: ' . $e->getMessage());
            $this->execute("UPDATE tb_automation_run SET status = 'failed', finished_at = GETDATE(), error_message = ? WHERE id = ? AND status = 'running'", ['La automatización no pudo completarse. Revisa la configuración y los datos del tablero.', $runId]);
            $this->execute("UPDATE tb_automation SET status = 'error', updated_at = GETDATE() WHERE id = (SELECT automation_id FROM tb_automation_run WHERE id = ?) AND status = 'active'", [$runId]);
            return 'failed';
        }
    }

    private function automationItemMatches(array $item, array $filters): bool {
        foreach ($filters as $filter) {
            if (isset($filter['group_id']) && (int)$item['group_id'] !== (int)$filter['group_id']) {
                return false;
            }
            if (!isset($filter['column_id'])) {
                continue;
            }
            $value = $item['cells'][(string)$filter['column_id']] ?? null;
            $operator = $filter['operator'];
            if ($operator === 'is_empty') {
                if (!($value === null || $value === '' || $value === [])) return false;
                continue;
            }
            if ($operator === 'not_empty') {
                if ($value === null || $value === '' || $value === []) return false;
                continue;
            }
            $expected = $filter['value'] ?? null;
            $actualString = is_scalar($value) ? (string)$value : (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $expectedString = is_scalar($expected) ? (string)$expected : (string)json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($operator === 'equals' && $this->lowerText($actualString) !== $this->lowerText($expectedString)) return false;
            if ($operator === 'not_equals' && $this->lowerText($actualString) === $this->lowerText($expectedString)) return false;
            if ($operator === 'contains' && $this->findText($actualString, $expectedString) === false) return false;
            if ($operator === 'before' && (!is_string($value) || strtotime($value) === false || strtotime($value) >= strtotime($expectedString))) return false;
            if ($operator === 'after' && (!is_string($value) || strtotime($value) === false || strtotime($value) <= strtotime($expectedString))) return false;
            if ($operator === 'greater_than' && (!is_numeric($value) || !is_numeric($expected) || (float)$value <= (float)$expected)) return false;
            if ($operator === 'less_than' && (!is_numeric($value) || !is_numeric($expected) || (float)$value >= (float)$expected)) return false;
        }
        return true;
    }

    private function writeActivity(int $boardId, ?int $itemId, ?int $actorId, string $eventType, array $payload = []): void {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new TablerosApiException('validation', 'No se pudo registrar la actividad.', 422);
        }
        $this->execute(
            'INSERT INTO tb_activity (board_id, item_id, actor_user_id, event_type, payload_json, created_at)
             VALUES (?, ?, ?, ?, ?, GETDATE())',
            [$boardId, $itemId, $actorId, $eventType, $json]
        );
        if ($actorId !== null && !str_starts_with($eventType, 'board.member_') && $eventType !== 'board.shared' && $this->access->hasGlobalPermission('admin')) {
            $this->auditAccess($actorId, 'admin.mutation', 'board', $boardId, ['event_type' => $eventType]);
        }
    }

    private function pathExists(array $graph, int $start, int $target): bool {
        $pending = [$start];
        $visited = [];
        while ($pending !== []) {
            $node = array_pop($pending);
            if ($node === $target) {
                return true;
            }
            if (isset($visited[$node])) {
                continue;
            }
            $visited[$node] = true;
            foreach ($graph[$node] ?? [] as $next) {
                if (!isset($visited[$next])) {
                    $pending[] = $next;
                }
            }
        }
        return false;
    }

    private function safeFilename(string $name): string {
        $name = str_replace('\\', '/', $name);
        $name = basename($name);
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = trim($name);
        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'archivo';
        }
        if ($this->textLength($name) > 260) {
            $extension = pathinfo($name, PATHINFO_EXTENSION);
            $stem = pathinfo($name, PATHINFO_FILENAME);
            $budget = max(1, 255 - $this->textLength($extension));
            $name = $this->textSlice($stem, $budget) . ($extension !== '' ? '.' . $extension : '');
        }
        return $name;
    }

    private function validateUploadedFile(string $tmpPath, string $originalName): array {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowed = [
            'pdf' => ['application/pdf'],
            'png' => ['image/png'],
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'gif' => ['image/gif'],
            'webp' => ['image/webp'],
            'mp4' => ['video/mp4'],
            'webm' => ['video/webm'],
            'txt' => ['text/plain'],
            'csv' => ['text/csv', 'text/plain', 'application/vnd.ms-excel'],
            'doc' => ['application/msword', 'application/x-ole-storage'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
            'xls' => ['application/vnd.ms-excel', 'application/x-ole-storage'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
            'ppt' => ['application/vnd.ms-powerpoint', 'application/x-ole-storage'],
            'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
        ];
        if (!isset($allowed[$extension])) {
            throw new TablerosApiException('validation', 'La extensión del archivo no está permitida.', 422);
        }
        if (!class_exists('finfo')) {
            throw new TablerosApiException('server', 'No está disponible la validación de contenido de archivos.', 500);
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $contentType = $finfo->file($tmpPath);
        if (!is_string($contentType) || !in_array($contentType, $allowed[$extension], true)) {
            throw new TablerosApiException('validation', 'El contenido no coincide con el tipo de archivo permitido.', 422);
        }
        return [$extension, $contentType];
    }

    private function privateStorageRoot(): string {
        $configured = getenv('TABLEROS_PRIVATE_STORAGE');
        if (!is_string($configured) || trim($configured) === '') {
            $configured = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'tableros';
        }
        $configured = trim($configured);
        if (!$this->isAbsolutePath($configured)) {
            throw new TablerosApiException('server', 'TABLEROS_PRIVATE_STORAGE debe apuntar a una ruta absoluta.', 500);
        }
        if (!is_dir($configured) && !@mkdir($configured, 0700, true) && !is_dir($configured)) {
            throw new TablerosApiException('server', 'No se pudo crear el almacenamiento privado de archivos.', 500);
        }
        @chmod($configured, 0700);
        $root = realpath($configured);
        if ($root === false || !is_writable($root)) {
            throw new TablerosApiException('server', 'El almacenamiento privado de archivos no tiene permisos de escritura.', 500);
        }
        $documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath((string)$_SERVER['DOCUMENT_ROOT']) : false;
        $applicationRoot = realpath(dirname(__DIR__, 2));
        $defaultPrivateRoot = $applicationRoot === false
            ? false
            : realpath($applicationRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'tableros');
        $isDefaultPrivateRoot = $defaultPrivateRoot !== false && $root === $defaultPrivateRoot;
        if ($documentRoot !== false && $this->pathIsWithin($root, $documentRoot) && !$isDefaultPrivateRoot) {
            throw new TablerosApiException('server', 'El almacenamiento de archivos debe estar fuera de la raíz pública.', 500);
        }
        if ($applicationRoot !== false && $this->pathIsWithin($root, $applicationRoot) && !$isDefaultPrivateRoot) {
            throw new TablerosApiException('server', 'El almacenamiento de archivos debe estar fuera del directorio de la aplicación.', 500);
        }
        return $root;
    }

    private function privateObjectPath(string $objectKey): string {
        // Preserve compatibility with legacy hashed keys while new files use
        // board/item/year/month/shard/hash to keep project assets organized.
        $legacyKey = (bool)preg_match('/^[a-f0-9]{64}$/', $objectKey);
        $hierarchicalKey = (bool)preg_match('~^[1-9][0-9]*/[1-9][0-9]*/[0-9]{4}/(?:0[1-9]|1[0-2])/[a-f0-9]{2}/[a-f0-9]{64}$~', $objectKey);
        $stickerKey = (bool)preg_match('~^stickers/[1-9][0-9]*/[0-9]{4}/(?:0[1-9]|1[0-2])/[a-f0-9]{2}/[a-f0-9]{64}$~', $objectKey);
        if (!$legacyKey && !$hierarchicalKey && !$stickerKey) {
            throw new TablerosApiException('not_found', 'No se encontró el archivo.', 404);
        }
        $root = $this->privateStorageRoot();
        if ($legacyKey) {
            $directory = $root . DIRECTORY_SEPARATOR . substr($objectKey, 0, 2);
            $path = $directory . DIRECTORY_SEPARATOR . $objectKey;
            // The previous default was a sibling directory named tableros-private.
            // Keep reads working there during the transition; new writes use uploads/tableros.
            if (!is_file($path)) {
                $previousRoot = dirname(dirname(__DIR__, 2)) . DIRECTORY_SEPARATOR . 'tableros-private';
                $previousPath = $previousRoot . DIRECTORY_SEPARATOR . substr($objectKey, 0, 2) . DIRECTORY_SEPARATOR . $objectKey;
                if (is_file($previousPath)) {
                    $previousRealRoot = realpath($previousRoot);
                    $previousRealPath = realpath($previousPath);
                    if ($previousRealRoot !== false && $previousRealPath !== false
                        && $this->pathIsWithin($previousRealPath, $previousRealRoot)) {
                        return $previousRealPath;
                    }
                }
            }
        } else {
            $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $objectKey);
            $directory = dirname($path);
        }
        $realDirectory = realpath($directory);
        $realPath = realpath($path);
        if ($realDirectory === false || !$this->pathIsWithin($realDirectory, $root)
            || $realPath === false || !$this->pathIsWithin($realPath, $root)) {
            throw new TablerosApiException('not_found', 'No se encontró el archivo.', 404);
        }
        return $realPath;
    }

    private function nasServiceEnabled(): bool {
        $driver = strtolower(trim((string)getenv('TABLEROS_STORAGE_DRIVER')));
        if ($driver === '' || $driver === 'local') return false;
        if ($driver !== 'nas_service') throw new TablerosApiException('server', 'TABLEROS_STORAGE_DRIVER tiene un valor no válido.', 500);
        return true;
    }

    /** Authenticated server-to-server request. The browser never receives this token. */
    private function nasServiceRequest(string $method, string $objectKey, ?string $uploadPath = null, ?int $size = null, ?string $contentType = null, ?string $downloadPath = null, ?int $downloadLimit = null, ?array $downloadRange = null, ?int $expectedTotalSize = null): string {
        $url = rtrim((string)getenv('TABLEROS_STORAGE_SERVICE_URL'), '/');
        $token = (string)getenv('TABLEROS_STORAGE_SERVICE_TOKEN');
        if ($url === '' || strlen($token) < 32) throw new TablerosApiException('server', 'Falta configurar el servicio de almacenamiento Tableros con un token de al menos 32 caracteres.', 500);
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        $host = strtolower(trim((string)parse_url($url, PHP_URL_HOST), '[]'));
        $loopbackHttp = $scheme === 'http' && in_array($host, ['127.0.0.1', '::1'], true);
        if (($scheme !== 'https' && !$loopbackHttp) || $host === '') {
            throw new TablerosApiException('server', 'El servicio de almacenamiento Tableros debe usar HTTPS; HTTP solo se permite en loopback.', 500);
        }
        if (!function_exists('curl_init')) throw new TablerosApiException('server', 'La extensión PHP cURL es necesaria para el almacenamiento Tableros.', 500);
        if (!preg_match('~^(?:[1-9][0-9]*/[1-9][0-9]*/[0-9]{4}/(?:0[1-9]|1[0-2])/[a-f0-9]{2}/[a-f0-9]{64}|stickers/[1-9][0-9]*/[0-9]{4}/(?:0[1-9]|1[0-2])/[a-f0-9]{2}/[a-f0-9]{64}|[a-f0-9]{64})$~', $objectKey)) {
            throw new TablerosApiException('not_found', 'No se encontró el archivo.', 404);
        }
        $handle = curl_init($url . '?key=' . rawurlencode($objectKey));
        if ($handle === false) throw new TablerosApiException('server', 'No se pudo iniciar la conexión al almacenamiento Tableros.', 500);
        $headers = ['Authorization: Bearer ' . $token];
        $output = null;
        $responseContentRange = null;
        $written = 0;
        try {
            curl_setopt($handle, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($handle, CURLOPT_TIMEOUT, 600);
            curl_setopt($handle, CURLOPT_FAILONERROR, false);
            if ($method === 'PUT') {
                if ($uploadPath === null || $size === null || !is_file($uploadPath)) throw new TablerosApiException('server', 'No se encontró el archivo temporal de carga.', 500);
                $input = fopen($uploadPath, 'rb');
                if (!is_resource($input)) throw new TablerosApiException('server', 'No se pudo leer el archivo para almacenamiento.', 500);
                $headers[] = 'Content-Type: ' . ($contentType ?: 'application/octet-stream');
                curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($handle, CURLOPT_UPLOAD, true);
                curl_setopt($handle, CURLOPT_INFILE, $input);
                curl_setopt($handle, CURLOPT_INFILESIZE, $size);
            } else $input = null;
            if ($method === 'GET') {
                if ($downloadRange !== null) {
                    [$rangeStart, $rangeEnd] = $downloadRange;
                    $headers[] = 'Range: bytes=' . $rangeStart . '-' . $rangeEnd;
                    curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
                }
                $output = fopen((string)$downloadPath, 'wb');
                if (!is_resource($output)) throw new TablerosApiException('server', 'No se pudo preparar la descarga temporal.', 500);
                curl_setopt($handle, CURLOPT_WRITEFUNCTION, static function ($curl, string $chunk) use ($output, &$written, $downloadLimit): int {
                    $length = strlen($chunk);
                    if ($downloadLimit === null || $length > $downloadLimit - $written) return 0;
                    $count = fwrite($output, $chunk);
                    if ($count === false) return 0;
                    $written += $count;
                    return $count;
                });
                curl_setopt($handle, CURLOPT_HEADERFUNCTION, static function ($curl, string $line) use (&$responseContentRange): int {
                    if (stripos($line, 'Content-Range:') === 0) $responseContentRange = trim(substr($line, strlen('Content-Range:')));
                    return strlen($line);
                });
            } else curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($handle);
            $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if (is_resource($input)) fclose($input);
            if (is_resource($output)) { fclose($output); $output = null; }
            if ($response === false || $status < 200 || $status >= 300) {
                if ($response !== false && $status === 404) throw new TablerosApiException('not_found', 'No se encontró el objeto en el almacenamiento Tableros.', 404);
                if ($response !== false && $status === 409) throw new TablerosApiException('conflict', 'El objeto ya existe en el almacenamiento Tableros.', 409);
                throw new TablerosApiException('server', 'El servicio de almacenamiento Tableros no pudo completar la operación.', 502);
            }
            if ($method === 'GET' && $downloadRange !== null) {
                [$rangeStart, $rangeEnd] = $downloadRange;
                $expectedRange = 'bytes ' . $rangeStart . '-' . $rangeEnd . '/' . (int)$expectedTotalSize;
                if ($status !== 206 || $responseContentRange !== $expectedRange
                    || $written !== $rangeEnd - $rangeStart + 1) {
                    throw new TablerosApiException('server', 'El servicio de almacenamiento devolvió un rango incompleto o no válido.', 502);
                }
            }
            return is_string($response) ? $response : '';
        } finally {
            if (is_resource($output)) fclose($output);
            curl_close($handle);
        }
    }

    private function isAbsolutePath(string $path): bool {
        return (bool)preg_match('/^(?:[A-Za-z]:[\\\\\/]|\\\\\\\\|\/)/', $path);
    }

    private function pathIsWithin(string $path, string $root): bool {
        $path = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
        $root = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $root), DIRECTORY_SEPARATOR);
        if (DIRECTORY_SEPARATOR === '\\') {
            $path = strtolower($path);
            $root = strtolower($root);
        }
        return $path === $root || str_starts_with($path, $root . DIRECTORY_SEPARATOR);
    }

    private function collectReferenceIds($value, array &$ids, array $keys): void {
        if (!is_array($value)) {
            if (is_scalar($value) && filter_var($value, FILTER_VALIDATE_INT) !== false && (int)$value > 0) {
                $ids[] = (int)$value;
            }
            return;
        }
        foreach ($value as $key => $nested) {
            if (in_array((string)$key, $keys, true) && is_scalar($nested)
                && filter_var($nested, FILTER_VALIDATE_INT) !== false && (int)$nested > 0) {
                $ids[] = (int)$nested;
            } elseif (is_array($nested)) {
                $this->collectReferenceIds($nested, $ids, $keys);
            } elseif (is_int($key) && is_scalar($nested)
                && filter_var($nested, FILTER_VALIDATE_INT) !== false && (int)$nested > 0) {
                $ids[] = (int)$nested;
            }
        }
    }

    private function collectBoardReferences($value, array &$references, int $defaultBoardId): void {
        if (!is_array($value)) {
            if (is_scalar($value) && filter_var($value, FILTER_VALIDATE_INT) !== false && (int)$value > 0) {
                $references[] = ['board_id' => $defaultBoardId, 'item_id' => (int)$value];
            }
            return;
        }
        $boardId = $value['board_id'] ?? $value['target_board_id'] ?? $value['boardId'] ?? null;
        $itemId = $value['item_id'] ?? $value['target_item_id'] ?? $value['itemId'] ?? null;
        if ($boardId !== null || $itemId !== null) {
            $boardId = $boardId === null ? $defaultBoardId : $this->positiveInt($boardId, 'target_board_id');
            $itemId = $itemId === null ? null : $this->positiveInt($itemId, 'target_item_id');
            $references[] = ['board_id' => $boardId, 'item_id' => $itemId];
            return;
        }
        foreach ($value as $nested) {
            $this->collectBoardReferences($nested, $references, $defaultBoardId);
        }
    }

    private function nextSortOrder(string $table, int $boardId, ?string $extraColumn = null, ?int $extraValue = null): int {
        $allowedTables = ['tb_group', 'tb_column', 'tb_item'];
        if (!in_array($table, $allowedTables, true) || ($extraColumn !== null && $extraColumn !== 'group_id')) {
            throw new LogicException('Invalid internal sort target');
        }
        $where = 'board_id = ? AND deleted_at IS NULL';
        $params = [$boardId];
        if ($extraColumn !== null) {
            $where .= ' AND ' . $extraColumn . ' = ?';
            $params[] = $extraValue;
        }
        $row = $this->one("SELECT COALESCE(MAX(sort_order), -10) + 10 AS next_order FROM $table WHERE $where", $params);
        return (int)($row['next_order'] ?? 0);
    }

    private function validDate(string $date): bool {
        $parsed = DateTime::createFromFormat('!Y-m-d', $date);
        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }

    private function requiredString(array $input, string $key, int $max): string {
        if (!isset($input[$key]) || !is_scalar($input[$key])) {
            throw new TablerosApiException('validation', 'El campo ' . $key . ' es obligatorio.', 422);
        }
        $value = trim((string)$input[$key]);
        if ($value === '' || $this->textLength($value) > $max) {
            throw new TablerosApiException('validation', 'El campo ' . $key . ' es obligatorio y debe tener como máximo ' . $max . ' caracteres.', 422);
        }
        return $value;
    }

    private function optionalString(array $input, string $key, int $max): ?string {
        if (!isset($input[$key]) || $input[$key] === '') {
            return null;
        }
        if (!is_scalar($input[$key])) {
            throw new TablerosApiException('validation', 'El campo ' . $key . ' no es válido.', 422);
        }
        $value = trim((string)$input[$key]);
        if ($this->textLength($value) > $max) {
            throw new TablerosApiException('validation', 'El campo ' . $key . ' excede el límite permitido.', 422);
        }
        return $value;
    }

    private function positiveInt($value, string $name): int {
        if (!is_int($value) && !is_string($value)) {
            throw new TablerosApiException('validation', 'El campo ' . $name . ' debe ser un entero positivo.', 422);
        }
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int)$value <= 0) {
            throw new TablerosApiException('validation', 'El campo ' . $name . ' debe ser un entero positivo.', 422);
        }
        return (int)$value;
    }

    private function integerValue($value, string $name): int {
        if (!is_int($value) && !is_string($value)) {
            throw new TablerosApiException('validation', 'El campo ' . $name . ' debe ser entero.', 422);
        }
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new TablerosApiException('validation', 'El campo ' . $name . ' debe ser entero.', 422);
        }
        return (int)$value;
    }

    private function optionalPositiveInt($value, string $name): ?int {
        if ($value === null || $value === '') {
            return null;
        }
        return $this->positiveInt($value, $name);
    }

    private function booleanInput(array $input, string $key, bool $default): bool {
        if (!array_key_exists($key, $input)) {
            return $default;
        }
        $value = filter_var($input[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($value === null) {
            throw new TablerosApiException('validation', 'El campo ' . $key . ' debe ser booleano.', 422);
        }
        return $value;
    }

    private function decodeJson($json, $default) {
        if ($json === null || $json === '') {
            return $default;
        }
        $decoded = json_decode((string)$json, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
    }

    private function textLength(string $value): int {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    private function textSlice(string $value, int $length): string {
        return function_exists('mb_substr') ? mb_substr($value, 0, $length, 'UTF-8') : substr($value, 0, $length);
    }

    private function lowerText(string $value): string {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    private function findText(string $haystack, string $needle) {
        return function_exists('mb_stripos') ? mb_stripos($haystack, $needle, 0, 'UTF-8') : stripos($haystack, $needle);
    }

    private function folderHasVisibleBoard(int $folderId, int $workspaceId): bool {
        $boards = $this->all(
            ';WITH folder_tree AS (
                 SELECT id FROM tb_folder WHERE id = ? AND workspace_id = ? AND deleted_at IS NULL
                 UNION ALL
                 SELECT child.id FROM tb_folder child
                 INNER JOIN folder_tree parent ON child.parent_folder_id = parent.id
                 WHERE child.workspace_id = ? AND child.deleted_at IS NULL
             )
             SELECT b.id FROM tb_board b
             INNER JOIN folder_tree f ON f.id = b.folder_id
             WHERE b.deleted_at IS NULL',
            [$folderId, $workspaceId, $workspaceId]
        );
        foreach ($boards as $board) {
            if ($this->access->canViewBoard((int)$board['id'])) return true;
        }
        return false;
    }

    private function versionInput($value): string {
        if (!is_string($value) || !preg_match('/^[a-f0-9]{16}$/i', $value)) {
            throw new TablerosApiException('validation', 'La versión del elemento no es válida.', 422);
        }
        return strtolower($value);
    }

    private function versionToken($value): string {
        if (is_resource($value)) {
            $value = stream_get_contents($value);
        }
        $raw = (string)$value;
        if (strlen($raw) === 8) {
            return bin2hex($raw);
        }
        if (preg_match('/^[a-f0-9]{16}$/i', $raw)) {
            return strtolower($raw);
        }
        $decoded = base64_decode($raw, true);
        if ($decoded !== false && strlen($decoded) === 8) {
            return bin2hex($decoded);
        }
        throw new RuntimeException('The database returned an invalid rowversion token');
    }

    private function canonicalValueHash(string $type, $value): string {
        $normalized = $this->canonicalize($value);
        $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if ($json === false) {
            throw new TablerosApiException('validation', 'El valor no se puede normalizar.', 422);
        }
        return bin2hex(hash('sha256', $type . "\0" . $json, true));
    }

    private function canonicalize($value) {
        if (!is_array($value)) {
            return $value;
        }
        if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $nested) {
            $value[$key] = $this->canonicalize($nested);
        }
        return $value;
    }

    private function transaction(callable $callback) {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $result = $callback();
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
            return $result;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function one(string $sql, array $params = []): ?array {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        return $row ?: null;
    }

    private function userDisplayName(int $userId): ?string {
        $row = $this->one(
            "SELECT COALESCE(NULLIF(LTRIM(RTRIM(Nombre)), ''), NULLIF(LTRIM(RTRIM(Usuario)), '')) AS display_name
             FROM [TG].[dbo].[Usuario] WHERE Id = ?",
            [$userId]
        );
        return $row['display_name'] ?? null;
    }

    private function returningOne(string $sql, array $params = [], array $binaryPositions = []): ?array {
        $stmt = $this->pdo->prepare($sql);
        foreach (array_values($params) as $index => $value) {
            if (in_array($index, $binaryPositions, true)) {
                $stmt->bindValue($index + 1, $value, PDO::PARAM_LOB);
            } elseif ($value === null) {
                $stmt->bindValue($index + 1, null, PDO::PARAM_NULL);
            } elseif (is_int($value)) {
                $stmt->bindValue($index + 1, $value, PDO::PARAM_INT);
            } elseif (is_bool($value)) {
                $stmt->bindValue($index + 1, $value, PDO::PARAM_BOOL);
            } else {
                $stmt->bindValue($index + 1, $value, PDO::PARAM_STR);
            }
        }
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        return $row ?: null;
    }

    private function all(string $sql, array $params = []): array {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        return $rows;
    }

    private function execute(string $sql, array $params = []): int {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $count = $stmt->rowCount();
        $stmt->closeCursor();
        return $count;
    }

    private function insertId(string $sql, array $params = []): int {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $id = false;
        do {
            if ($stmt->columnCount() > 0) {
                $candidate = $stmt->fetchColumn();
                if (is_numeric($candidate)) {
                    $id = $candidate;
                    break;
                }
            }
        } while ($stmt->nextRowset());
        $stmt->closeCursor();
        if (!is_numeric($id) || (int)$id <= 0) {
            throw new RuntimeException('Insert did not return an id');
        }
        return (int)$id;
    }

    private function insertRow(string $sql, array $params = []): array {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        if (!$row || !isset($row['id'])) {
            throw new RuntimeException('Insert did not return its row');
        }
        return $row;
    }
}
