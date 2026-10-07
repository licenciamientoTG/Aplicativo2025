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
        $sql = "SELECT DISTINCT b.id, b.workspace_id, b.folder_id, b.name, b.description,
                       w.name AS workspace_name, w.visibility AS workspace_visibility,
                       f.name AS folder_name, f.parent_folder_id,
                       b.visibility, b.created_by, b.created_at, b.updated_at,
                        CASE WHEN b.created_by = ? THEN 'owner'
                            WHEN ? = 1 THEN 'designer'
                            WHEN m.role IS NOT NULL THEN m.role
                            WHEN wm.role = 'owner' AND b.visibility IN ('workspace', 'public') THEN 'owner'
                            WHEN wm.role IN ('editor', 'viewer') AND b.visibility IN ('workspace', 'public') THEN wm.role
                            WHEN b.visibility = 'public' OR (b.visibility = 'workspace' AND w.visibility IN ('workspace', 'public')) THEN 'viewer' END AS user_role
                FROM tb_board b
                INNER JOIN tb_workspace w ON w.id = b.workspace_id AND w.deleted_at IS NULL
                LEFT JOIN tb_folder f ON f.id = b.folder_id AND f.workspace_id = b.workspace_id AND f.deleted_at IS NULL
                LEFT JOIN tb_board_member m ON m.board_id = b.id AND m.user_id = ? AND m.deleted_at IS NULL
                LEFT JOIN tb_workspace_member wm ON wm.workspace_id = w.id AND wm.user_id = ? AND wm.deleted_at IS NULL
                WHERE b.deleted_at IS NULL
                  AND (b.created_by = ? OR m.user_id = ? OR ? = 1
                       OR b.visibility = 'public'
                       OR (b.visibility = 'workspace' AND (wm.user_id = ? OR w.visibility IN ('workspace', 'public'))))
                ORDER BY w.name, f.name, b.name, b.id";
        return $this->all($sql, [$userId, $isAdmin ? 1 : 0, $userId, $userId, $userId, $userId, $isAdmin ? 1 : 0, $userId]);
    }

    /** Flat hierarchy payload; the client builds the nested folder tree. */
    public function getWorkspaceStructure(int $userId, bool $isAdmin): array {
        $workspaces = $this->all(
            "SELECT w.id, w.name, w.description, w.visibility, w.created_by,
                    CASE WHEN w.created_by = ? THEN 'owner' WHEN ? = 1 THEN 'owner'
                         WHEN wm.role IS NOT NULL THEN wm.role
                         WHEN w.visibility IN ('workspace', 'public') THEN 'viewer' END AS user_role
             FROM tb_workspace w
             LEFT JOIN tb_workspace_member wm ON wm.workspace_id = w.id AND wm.user_id = ? AND wm.deleted_at IS NULL
             WHERE w.workspace_type = 'workspace' AND w.deleted_at IS NULL
               AND (w.created_by = ? OR wm.user_id = ? OR w.visibility IN ('workspace', 'public') OR ? = 1)
             ORDER BY w.name, w.id",
            [$userId, $isAdmin ? 1 : 0, $userId, $userId, $userId, $isAdmin ? 1 : 0]
        );
        $boards = $this->getBoards($userId, $isAdmin);
        $workspaceIds = array_fill_keys(array_map(static fn($workspace) => (string)$workspace['id'], $workspaces), true);
        foreach ($boards as $board) {
            $workspaceId = (string)$board['workspace_id'];
            if (!isset($workspaceIds[$workspaceId])) {
                $workspaces[] = [
                    'id' => (int)$board['workspace_id'], 'name' => (string)$board['workspace_name'],
                    'description' => '', 'visibility' => (string)$board['workspace_visibility'],
                    'created_by' => null, 'user_role' => $board['user_role'], 'shared_only' => true
                ];
                $workspaceIds[$workspaceId] = true;
            }
        }
        $folders = [];
        if ($workspaceIds) {
            $ids = array_keys($workspaceIds);
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $folders = $this->all(
                "SELECT id, workspace_id, parent_folder_id, name, color, sort_order, created_by
                 FROM tb_folder WHERE workspace_id IN ($marks) AND deleted_at IS NULL ORDER BY sort_order, name, id",
                array_map('intval', $ids)
            );
        }
        $folderById = [];
        $workspaceRoles = [];
        $workspaceVisibility = [];
        foreach ($folders as $folder) $folderById[(string)$folder['id']] = $folder;
        foreach ($workspaces as $workspace) {
            $workspaceRoles[(string)$workspace['id']] = (string)($workspace['user_role'] ?? '');
            $workspaceVisibility[(string)$workspace['id']] = (string)($workspace['visibility'] ?? '');
        }
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
                || ($folder['created_by'] === null && in_array($workspaceVisibility[(string)$folder['workspace_id']] ?? '', ['workspace', 'public'], true))) {
                $markFolderPath($folder);
            }
        }
        foreach ($boards as $board) {
            $markFolderPath($folderById[(string)($board['folder_id'] ?? '')] ?? null);
        }
        $folders = array_values(array_filter($folders, static fn($folder) => isset($allowed[(string)$folder['id']])));
        return ['workspaces' => $workspaces, 'folders' => $folders, 'boards' => $boards];
    }

    public function createWorkspace(int $userId, array $input): array {
        if (!$this->userIsActive($userId)) {
            throw new TablerosApiException('forbidden', 'La cuenta TG no está activa.', 403);
        }
        $name = $this->requiredString($input, 'name', 200);
        $description = $this->optionalString($input, 'description', 1000);
        $visibility = strtolower(trim((string)($input['visibility'] ?? 'private')));
        if (!in_array($visibility, ['private', 'workspace', 'public'], true)) {
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
        if (!$this->userIsActive($userId)) {
            throw new TablerosApiException('forbidden', 'La cuenta TG no está activa.', 403);
        }
        $workspaceId = $this->optionalPositiveInt($input['workspace_id'] ?? null, 'workspace_id');
        if ($workspaceId === null) {
            $defaultWorkspace = $this->one("SELECT id FROM tb_workspace WHERE workspace_key = 'general' AND deleted_at IS NULL");
            $workspaceId = $defaultWorkspace ? (int)$defaultWorkspace['id'] : 0;
        }
        if ($workspaceId <= 0) throw new TablerosApiException('validation', 'Selecciona un espacio de trabajo.', 422);
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
                && !$this->folderHasVisibleBoard($parentFolderId, $workspaceId)) {
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

    public function getBoard(int $boardId): ?array {
        return $this->one('SELECT * FROM tb_board WHERE id = ? AND deleted_at IS NULL', [$boardId]);
    }

    public function getBoardMembers(int $boardId): array {
        $board = $this->one('SELECT created_by FROM tb_board WHERE id = ? AND deleted_at IS NULL', [$boardId]);
        if (!$board) {
            throw new TablerosApiException('not_found', 'No se encontró el tablero.', 404);
        }
        $members = $this->all(
            'SELECT id, board_id, user_id, role, invited_by, created_at
             FROM tb_board_member
             WHERE board_id = ? AND deleted_at IS NULL AND user_id <> ?
             ORDER BY created_at, id',
            [$boardId, (int)$board['created_by']]
        );
        return [
            'board_id' => $boardId,
            'owner' => ['user_id' => (int)$board['created_by'], 'role' => 'owner'],
            'members' => $members,
        ];
    }

    public function revokeBoardMember(int $boardId, int $actorId, array $input): array {
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
            'SELECT id, board_id, group_id, parent_item_id, name, sort_order, version,
                    created_by, updated_by, created_at, updated_at
             FROM tb_item WHERE board_id = ? AND deleted_at IS NULL ORDER BY group_id, sort_order, id',
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
        foreach ($cells as &$cell) {
            $value = $this->cellValue($cell);
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

    public function userIsActive(int $userId): bool {
        return $this->access->userIsActive($userId);
    }

    public function getActivity(int $boardId, ?int $itemId = null, int $limit = 100): array {
        if ($itemId !== null) {
            $this->requireItemOnBoard($itemId, $boardId);
        }
        $limit = max(1, min(200, $limit));
        $sql = "SELECT TOP ($limit) id, board_id, item_id, actor_user_id, event_type, payload_json, created_at
                FROM tb_activity WHERE board_id = ?";
        $params = [$boardId];
        if ($itemId !== null) {
            $sql .= ' AND item_id = ?';
            $params[] = $itemId;
        }
        $sql .= ' ORDER BY created_at DESC, id DESC';
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
        return $this->all(
            'SELECT TOP (200) id, board_id, item_id, parent_comment_id, body, created_by, updated_by, created_at, updated_at
             FROM tb_comment WHERE board_id = ? AND item_id = ? AND deleted_at IS NULL
             ORDER BY created_at, id',
            [$boardId, $itemId]
        );
    }

    public function addComment(int $boardId, int $userId, array $input): array {
        $itemId = $this->positiveInt($input['item_id'] ?? null, 'item_id');
        $parentId = $this->optionalPositiveInt($input['parent_comment_id'] ?? null, 'parent_comment_id');
        $body = $this->requiredString($input, 'body', 10000);
        $this->requireItemOnBoard($itemId, $boardId);
        if ($parentId !== null && !$this->one(
            'SELECT id FROM tb_comment WHERE id = ? AND board_id = ? AND item_id = ? AND deleted_at IS NULL',
            [$parentId, $boardId, $itemId]
        )) {
            throw new TablerosApiException('validation', 'El comentario padre no pertenece a este elemento.', 422);
        }
        return $this->transaction(function () use ($boardId, $userId, $itemId, $parentId, $body): array {
            $id = $this->insertId(
                'INSERT INTO tb_comment (board_id, item_id, parent_comment_id, body, created_by, created_at, updated_at)
                 OUTPUT INSERTED.id VALUES (?, ?, ?, ?, ?, GETDATE(), GETDATE())',
                [$boardId, $itemId, $parentId, $body, $userId]
            );
            $this->writeActivity($boardId, $itemId, $userId, 'comment.created', ['comment_id' => $id]);
            return [
                'id' => $id, 'board_id' => $boardId, 'item_id' => $itemId,
                'parent_comment_id' => $parentId, 'body' => $body, 'created_by' => $userId,
            ];
        });
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
        if (!is_uploaded_file((string)$upload['tmp_name'])) {
            throw new TablerosApiException('validation', 'El archivo no proviene de una carga HTTP válida.', 422);
        }
        $size = (int)($upload['size'] ?? 0);
        $actualSize = filesize((string)$upload['tmp_name']);
        if ($size <= 0 || $actualSize === false || $actualSize !== $size || $size > 25 * 1024 * 1024) {
            throw new TablerosApiException('validation', 'El archivo debe pesar entre 1 byte y 25 MB.', 422);
        }
        $originalName = $this->safeFilename((string)($upload['name'] ?? 'archivo'));
        [$extension, $contentType] = $this->validateUploadedFile((string)$upload['tmp_name'], $originalName);
        $storageRoot = $this->privateStorageRoot();
        $now = new DateTimeImmutable('now');
        $objectHash = bin2hex(random_bytes(32));
        $objectKey = implode('/', [
            (string)$boardId,
            (string)$itemId,
            $now->format('Y'),
            $now->format('m'),
            substr($objectHash, 0, 2),
            $objectHash,
        ]);
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
        $source = @fopen((string)$upload['tmp_name'], 'rb');
        if (!is_resource($target) || !is_resource($source)) {
            if (is_resource($target)) fclose($target);
            if (is_resource($source)) fclose($source);
            @unlink($destination);
            throw new TablerosApiException('server', 'No se pudo guardar el archivo en almacenamiento privado.', 500);
        }
        $copied = stream_copy_to_stream($source, $target);
        fclose($source);
        fclose($target);
        if ($copied !== $size) {
            @unlink($destination);
            throw new TablerosApiException('server', 'No se pudo guardar el archivo completo.', 500);
        }
        @chmod($destination, 0600);

        try {
            $result = $this->transaction(function () use ($boardId, $userId, $itemId, $fileId, $columnId, $originalName, $size, $contentType, $objectKey): array {
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
                $hash = hash_file('sha256', $this->privateObjectPath($objectKey), true);
                $versionId = $this->insertId(
                    'INSERT INTO tb_file_version (file_id, version_number, original_name, content_type, byte_size,
                         storage_provider, storage_container, storage_object_key, sha256_hash, scan_status, created_by, created_at)
                     OUTPUT INSERTED.id VALUES (?, ?, ?, ?, ?, ?, ?, ?, CONVERT(VARBINARY(32), ?, 2), ?, ?, GETDATE())',
                    [$fileId, $versionNumber, $originalName, $contentType, $size, 'local', 'tableros', $objectKey, bin2hex($hash), 'pending', $userId]
                );
                $this->execute('UPDATE tb_file SET current_version_id = ?, name = ?, updated_at = GETDATE() WHERE id = ? AND board_id = ?', [$versionId, $originalName, $fileId, $boardId]);
                $this->writeActivity($boardId, $itemId, $userId, 'file.uploaded', [
                    'file_id' => $fileId, 'file_version_id' => $versionId, 'version_number' => $versionNumber,
                    'name' => $originalName, 'byte_size' => $size,
                ]);
                return [
                    'id' => $fileId, 'board_id' => $boardId, 'item_id' => $itemId, 'column_id' => $columnId,
                    'version_id' => $versionId, 'version_number' => $versionNumber, 'name' => $originalName,
                    'content_type' => $contentType, 'byte_size' => $size, 'scan_status' => 'pending',
                ];
            });
            return $result;
        } catch (Throwable $e) {
            @unlink($destination);
            throw $e;
        }
    }

    public function getFileVersions(int $boardId, int $fileId): array {
        $file = $this->one('SELECT id FROM tb_file WHERE id = ? AND board_id = ? AND deleted_at IS NULL', [$fileId, $boardId]);
        if (!$file) {
            throw new TablerosApiException('not_found', 'No se encontró el archivo en este tablero.', 404);
        }
        return $this->all(
            'SELECT v.id, v.file_id, v.version_number, v.original_name, v.content_type, v.byte_size,
                    v.scan_status, v.scanned_at, v.created_by, v.created_at,
                    CASE WHEN f.current_version_id = v.id THEN 1 ELSE 0 END AS is_current
             FROM tb_file_version v
             INNER JOIN tb_file f ON f.id = v.file_id AND f.board_id = ?
             WHERE v.file_id = ? AND v.deleted_at IS NULL ORDER BY v.version_number DESC',
            [$boardId, $fileId]
        );
    }

    public function approveFile(int $boardId, int $actorId, array $input): array {
        $fileId = $this->positiveInt($input['file_id'] ?? null, 'file_id');
        $versionId = $this->positiveInt($input['version_id'] ?? null, 'version_id');
        if (!$this->booleanInput($input, 'confirm_manual_review', false)) {
            throw new TablerosApiException('validation', 'Confirma la revisión manual antes de aprobar el archivo.', 422);
        }

        return $this->transaction(function () use ($boardId, $actorId, $fileId, $versionId): array {
            $file = $this->one(
                'SELECT id, current_version_id FROM tb_file WITH (UPDLOCK, HOLDLOCK)
                 WHERE id = ? AND board_id = ? AND deleted_at IS NULL',
                [$fileId, $boardId]
            );
            if (!$file || (int)($file['current_version_id'] ?? 0) !== $versionId) {
                throw new TablerosApiException('conflict', 'La versión ya no es la versión actual del archivo.', 409);
            }
            $version = $this->one(
                'SELECT id, scan_status FROM tb_file_version WITH (UPDLOCK, HOLDLOCK)
                 WHERE id = ? AND file_id = ? AND deleted_at IS NULL',
                [$versionId, $fileId]
            );
            if (!$version) {
                throw new TablerosApiException('not_found', 'No se encontró la versión actual del archivo.', 404);
            }
            if ((string)$version['scan_status'] === 'clean') {
                return ['file_id' => $fileId, 'version_id' => $versionId, 'scan_status' => 'clean', 'review_method' => 'manual'];
            }
            if ((string)$version['scan_status'] !== 'pending') {
                throw new TablerosApiException('conflict', 'Solo se pueden aprobar versiones pendientes de revisión.', 409);
            }
            $this->execute(
                "UPDATE tb_file_version SET scan_status = 'clean'
                 WHERE id = ? AND file_id = ? AND scan_status = 'pending' AND deleted_at IS NULL",
                [$versionId, $fileId]
            );
            $this->execute('UPDATE tb_file SET updated_at = GETDATE() WHERE id = ? AND board_id = ?', [$fileId, $boardId]);
            $this->writeActivity($boardId, null, $actorId, 'file.manually_approved', [
                'file_id' => $fileId, 'version_id' => $versionId, 'review_method' => 'manual',
            ]);
            return ['file_id' => $fileId, 'version_id' => $versionId, 'scan_status' => 'clean', 'review_method' => 'manual'];
        });
    }

    public function getFileBoardId(int $fileId): int {
        $file = $this->one('SELECT board_id FROM tb_file WHERE id = ? AND deleted_at IS NULL', [$fileId]);
        if (!$file) {
            throw new TablerosApiException('not_found', 'No se encontró el archivo.', 404);
        }
        return (int)$file['board_id'];
    }

    /** The caller must authorize the returned board before streaming the path. */
    public function getFileDownload(int $fileId, ?int $versionId = null): array {
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
            'SELECT id, file_id, version_number, original_name, content_type, byte_size, storage_provider, storage_container, storage_object_key, scan_status
             FROM tb_file_version WHERE id = ? AND file_id = ? AND deleted_at IS NULL',
            [$wantedVersion, $fileId]
        );
        if (!$version || (string)$version['storage_provider'] !== 'local' || (string)$version['storage_container'] !== 'tableros') {
            throw new TablerosApiException('not_found', 'No se encontró la versión del archivo.', 404);
        }
        if ((string)$version['scan_status'] !== 'clean') {
            throw new TablerosApiException('conflict', 'Esta versión de archivo requiere aprobación manual antes de descargarse.', 409);
        }
        $key = (string)$version['storage_object_key'];
        $path = $this->privateObjectPath($key);
        if (!is_file($path) || !is_readable($path)) {
            throw new TablerosApiException('not_found', 'El archivo no está disponible en el almacenamiento privado.', 404);
        }
        return [
            'file_id' => (int)$file['id'], 'board_id' => (int)$file['board_id'], 'item_id' => (int)$file['item_id'],
            'version_id' => (int)$version['id'], 'version_number' => (int)$version['version_number'],
            'name' => (string)$version['original_name'], 'content_type' => (string)$version['content_type'],
            'byte_size' => (int)$version['byte_size'], 'path' => $path,
        ];
    }

    public function createBoard(int $userId, array $input): array {
        if (!$this->userIsActive($userId)) {
            throw new TablerosApiException('forbidden', 'La cuenta TG no está activa.', 403);
        }
        $name = $this->requiredString($input, 'name', 120);
        $description = $this->optionalString($input, 'description', 2000);
        $workspaceId = $this->positiveInt($input['workspace_id'] ?? null, 'workspace_id');
        $folderId = $this->optionalPositiveInt($input['folder_id'] ?? null, 'folder_id');
        $visibility = strtolower(trim((string)($input['visibility'] ?? 'private')));
        if (!in_array($visibility, ['private', 'workspace', 'public'], true)) {
            throw new TablerosApiException('validation', 'La visibilidad del tablero no es válida.', 422);
        }

        return $this->transaction(function () use ($userId, $name, $description, $workspaceId, $folderId, $visibility): array {
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
                    && !$this->folderHasVisibleBoard($folderId, $workspaceId)) {
                    throw new TablerosApiException('forbidden', 'No tienes acceso a la carpeta seleccionada.', 403);
                }
            }
            $boardId = $this->insertId(
                'INSERT INTO tb_board (workspace_id, folder_id, name, description, visibility, created_by, created_at, updated_at)
                 OUTPUT INSERTED.id VALUES (?, ?, ?, ?, ?, ?, GETDATE(), GETDATE())',
                [$workspaceId, $folderId, $name, $description, $visibility, $userId]
            );
            $this->writeActivity($boardId, null, $userId, 'board.created', ['name' => $name]);
            return ['id' => $boardId, 'workspace_id' => $workspaceId, 'folder_id' => $folderId, 'name' => $name, 'description' => $description, 'visibility' => $visibility, 'role' => 'owner'];
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
        $required = $this->booleanInput($input, 'required', false);
        $enforceUnique = $this->booleanInput($input, 'enforce_unique', false);
        if ($enforceUnique && in_array($type, ['people', 'person'], true)) {
            throw new TablerosApiException('validation', 'Las columnas de personas no admiten valores únicos.', 422);
        }
        $position = $this->nextSortOrder('tb_column', $boardId);
        $id = $this->insertId(
            'INSERT INTO tb_column (board_id, name, type, options_json, required, sort_order, enforce_unique, created_by, created_at, updated_at)
             OUTPUT INSERTED.id VALUES (?, ?, ?, ?, ?, ?, ?, ?, GETDATE(), GETDATE())',
            [$boardId, $name, $type, json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $required ? 1 : 0, $position, $enforceUnique ? 1 : 0, $userId]
        );
        $this->writeActivity($boardId, null, $userId, 'column.created', ['column_id' => $id, 'type' => $type, 'name' => $name]);
        return ['id' => $id, 'board_id' => $boardId, 'name' => $name, 'type' => $type, 'options' => $options, 'required' => $required, 'enforce_unique' => $enforceUnique, 'sort_order' => $position];
    }

    public function createItem(int $boardId, int $userId, array $input): array {
        $groupId = $this->positiveInt($input['group_id'] ?? null, 'group_id');
        $this->requireGroupOnBoard($groupId, $boardId);
        $name = $this->optionalString($input, 'name', 500) ?? '';
        $parentId = $this->optionalPositiveInt($input['parent_item_id'] ?? null, 'parent_item_id');
        if ($parentId !== null) {
            $this->requireItemOnBoard($parentId, $boardId);
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
            $this->validateCellReferences((string)$column['type'], $value, $boardId);
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
        $item = $this->one('SELECT id, version FROM tb_item WHERE id = ? AND board_id = ? AND deleted_at IS NULL', [$itemId, $boardId]);
        if (!$item) {
            throw new TablerosApiException('not_found', 'No se encontró el elemento en este tablero.', 404);
        }
        if ($this->versionToken($item['version'] ?? null) !== $expectedVersion) {
            throw new TablerosApiException('conflict', 'El elemento cambió. Recarga el tablero e inténtalo de nuevo.', 409);
        }
        if ($position === null) {
            $position = $this->nextSortOrder('tb_item', $boardId, 'group_id', $groupId);
        }
        return $this->transaction(function () use ($boardId, $userId, $itemId, $groupId, $position, $expectedVersion): array {
            $updated = $this->returningOne(
                'UPDATE tb_item SET group_id = ?, sort_order = ?, updated_by = ?, updated_at = GETDATE()
                 OUTPUT INSERTED.version
                WHERE id = ? AND board_id = ? AND version = CONVERT(VARBINARY(8), ?, 2) AND deleted_at IS NULL',
                [$groupId, $position, $userId, $itemId, $boardId, $expectedVersion]
            );
            if (!$updated) {
                throw new TablerosApiException('conflict', 'El elemento cambió. Recarga el tablero e inténtalo de nuevo.', 409);
            }
            $this->writeActivity($boardId, $itemId, $userId, 'item.moved', ['group_id' => $groupId, 'sort_order' => $position]);
            return ['id' => $itemId, 'group_id' => $groupId, 'sort_order' => $position, 'version' => $this->versionToken($updated['version'] ?? null)];
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
            if (!in_array($role, ['designer', 'editor', 'viewer'], true)) {
                throw new TablerosApiException('validation', 'El rol de miembro no está permitido.', 422);
            }
            if ($targetId === $userId) {
                continue;
            }
            $normalized[$targetId] = $role;
        }
        foreach (array_keys($normalized) as $targetId) {
            if (!$this->userIsActive((int)$targetId)) {
                throw new TablerosApiException('validation', 'Todos los usuarios asignados deben estar activos en TG.', 422);
            }
        }

        return $this->transaction(function () use ($boardId, $userId, $normalized): array {
            $board = $this->one('SELECT created_by FROM tb_board WHERE id = ? AND deleted_at IS NULL', [$boardId]);
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
                if ($existing) {
                    $this->execute('UPDATE tb_board_member SET role = ?, invited_by = ?, updated_at = GETDATE(), deleted_at = NULL WHERE id = ?', [$role, $userId, $existing['id']]);
                } else {
                    $this->execute(
                        'INSERT INTO tb_board_member (board_id, user_id, role, invited_by, created_at) VALUES (?, ?, ?, ?, GETDATE())',
                        [$boardId, $targetId, $role, $userId]
                    );
                }
            }
            $this->writeActivity($boardId, null, $userId, 'board.shared', ['user_ids' => array_map('intval', array_keys($normalized))]);
            return ['board_id' => $boardId, 'members' => array_map(
                static fn($targetId, $role) => ['user_id' => (int)$targetId, 'role' => $role],
                array_keys($normalized), array_values($normalized)
            )];
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
                $maximum = (float)($options['max'] ?? 5);
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
        } elseif (in_array($type, ['people', 'person', 'team', 'status', 'dropdown', 'tags', 'timeline', 'file', 'country', 'link', 'location', 'board_relation', 'subtasks', 'dependency'], true)) {
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

    private function validateCellReferences(string $type, $value, int $boardId): void {
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
        if (!$legacyKey && !$hierarchicalKey) {
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
