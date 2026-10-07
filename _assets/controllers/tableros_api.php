<?php

require_once __DIR__ . '/../models/TablerosModel.php';

/** JSON endpoints for board operations. */
class tableros_api {
    private PDO $pdo;
    private TablerosModel $model;
    private TablerosAccess $access;

    public function __construct($twig) {
        if (empty($_SESSION['tableros_csrf']) || !is_string($_SESSION['tableros_csrf'])) {
            $_SESSION['tableros_csrf'] = bin2hex(random_bytes(32));
        }
    }

    public function boards(): void {
        $this->handle('GET', function (int $userId): array {
            $isAdmin = $this->access->hasGlobalPermission('admin');
            return $this->model->getBoards($userId, $isAdmin);
        });
    }

    public function structure(): void {
        $this->handle('GET', function (int $userId): array {
            return $this->model->getWorkspaceStructure($userId, $this->access->hasGlobalPermission('admin'));
        });
    }

    public function create_workspace(): void {
        $this->handle('POST', function (int $userId, array $input): array {
            if (!$this->access->hasGlobalPermission('create')) {
                throw new TablerosApiException('forbidden', 'No tienes permiso para crear espacios de trabajo.', 403);
            }
            return $this->model->createWorkspace($userId, $input);
        });
    }

    public function create_folder(): void {
        $this->handle('POST', function (int $userId, array $input): array {
            if (!$this->access->hasGlobalPermission('create')) {
                throw new TablerosApiException('forbidden', 'No tienes permiso para crear carpetas.', 403);
            }
            $workspaceId = $this->positiveInt($input['workspace_id'] ?? null, 'workspace_id');
            $role = $this->access->workspaceRole($workspaceId);
            if ($role === null) {
                throw new TablerosApiException('forbidden', 'No tienes acceso al espacio de trabajo seleccionado.', 403);
            }
            return $this->model->createFolder($userId, $input);
        });
    }

    public function board($id): void {
        $this->handle('GET', function (int $userId) use ($id): array {
            $boardId = $this->positiveInt($id, 'board_id');
            $role = $this->requireBoardRole($boardId, ['owner', 'designer', 'editor', 'viewer']);
            $data = $this->model->getBoardData($boardId, $userId);
            $data['board']['role'] = $role;
            return $data;
        });
    }

    public function users(): void {
        $this->handle('GET', function (int $userId): array {
            $query = $_GET['q'] ?? '';
            if (isset($_GET['board_id'])) {
                $boardId = $this->positiveInt($_GET['board_id'], 'board_id');
                $this->requireBoardRole($boardId, ['owner', 'designer', 'editor', 'viewer']);
                $rawIds = $_GET['ids'] ?? '';
                $ids = is_string($rawIds) ? array_slice(array_filter(array_map('intval', explode(',', $rawIds)), static fn(int $id): bool => $id > 0), 0, 100) : [];
                return $this->access->activeUsersForBoard($boardId, is_string($query) ? $query : '', $ids);
            }
            return $this->model->activeUsers(is_string($query) ? $query : '');
        });
    }

    public function activity(): void {
        $this->handle('GET', function (int $userId): array {
            $boardId = $this->positiveInt($_GET['board_id'] ?? null, 'board_id');
            $this->requireBoardRole($boardId, ['owner', 'designer', 'editor', 'viewer']);
            $itemId = isset($_GET['item_id']) ? $this->positiveInt($_GET['item_id'], 'item_id') : null;
            $limit = isset($_GET['limit']) ? $this->positiveInt($_GET['limit'], 'limit') : 100;
            return $this->model->getActivity($boardId, $itemId, $limit);
        });
    }

    public function comments(): void {
        $this->handle('GET', function (int $userId): array {
            $boardId = $this->positiveInt($_GET['board_id'] ?? null, 'board_id');
            $itemId = $this->positiveInt($_GET['item_id'] ?? null, 'item_id');
            $this->requireBoardRole($boardId, ['owner', 'designer', 'editor', 'viewer']);
            return $this->model->getComments($boardId, $itemId);
        });
    }

    public function add_comment(): void {
        $this->handle('POST', function (int $userId, array $input): array {
            $boardId = $this->bodyBoardId($input);
            $this->requireBoardRole($boardId, ['owner', 'designer', 'editor']);
            return $this->model->addComment($boardId, $userId, $input);
        });
    }

    public function upload_file(): void {
        $this->handle('POST', function (int $userId, array $input): array {
            $boardId = $this->bodyBoardId($input);
            $this->requireBoardRole($boardId, ['owner', 'designer', 'editor']);
            if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
                throw new TablerosApiException('validation', 'Selecciona un archivo para subir.', 422);
            }
            return $this->model->uploadFile($boardId, $userId, $input, $_FILES['file']);
        });
    }

    public function approve_file(): void {
        $this->handle('POST', function (int $userId, array $input): array {
            $boardId = $this->bodyBoardId($input);
            $this->requireBoardRole($boardId, ['owner', 'designer']);
            return $this->model->approveFile($boardId, $userId, $input);
        });
    }

    public function file_versions($id): void {
        $this->handle('GET', function (int $userId) use ($id): array {
            $fileId = $this->positiveInt($id, 'file_id');
            $boardId = $this->model->getFileBoardId($fileId);
            $this->requireBoardRole($boardId, ['owner', 'designer', 'editor', 'viewer']);
            return $this->model->getFileVersions($boardId, $fileId);
        });
    }

    public function download_file($id): void {
        try {
            $userId = $this->currentUserId();
            if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
                throw new TablerosApiException('validation', 'Método HTTP no permitido para esta operación.', 422);
            }
            $this->initializeServices();
            if (!$this->access->userIsActive($userId) || !$this->access->hasGlobalPermission('access')) {
                throw new TablerosApiException('forbidden', 'No tienes permiso para esta operación.', 403);
            }
            $fileId = $this->positiveInt($id, 'file_id');
            $boardId = $this->model->getFileBoardId($fileId);
            $this->requireBoardRole($boardId, ['owner', 'designer', 'editor', 'viewer']);
            $versionId = isset($_GET['version_id']) ? $this->positiveInt($_GET['version_id'], 'version_id') : null;
            $file = $this->model->getFileDownload($fileId, $versionId);
            header('Content-Type: ' . $file['content_type']);
            header('Content-Length: ' . (int)$file['byte_size']);
            header('Content-Disposition: ' . $this->contentDisposition((string)$file['name']));
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, no-store, max-age=0');
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            readfile($file['path']);
            exit;
        } catch (TablerosApiException $e) {
            $this->sendJson($e->httpStatus, ['success' => false, 'code' => $e->apiCode, 'message' => $e->getMessage()]);
        } catch (Throwable $e) {
            error_log('Tableros file download error: ' . $e->getMessage());
            $this->sendJson(500, ['success' => false, 'code' => 'server', 'message' => 'Ocurrió un error al descargar el archivo.']);
        }
    }

    public function relations(): void {
        $this->handle('GET', function (int $userId): array {
            $boardId = $this->positiveInt($_GET['board_id'] ?? null, 'board_id');
            $itemId = $this->positiveInt($_GET['item_id'] ?? null, 'item_id');
            $this->requireBoardRole($boardId, ['owner', 'designer', 'editor', 'viewer']);
            return $this->model->getRelations($boardId, $itemId);
        });
    }

    public function add_relation(): void {
        $this->handle('POST', function (int $userId, array $input): array {
            $boardId = $this->bodyBoardId($input);
            $this->requireBoardRole($boardId, ['owner', 'designer', 'editor']);
            return $this->model->addRelation($boardId, $userId, $input);
        });
    }

    public function remove_relation(): void {
        $this->handle('POST', function (int $userId, array $input): array {
            $boardId = $this->bodyBoardId($input);
            $this->requireBoardRole($boardId, ['owner', 'designer', 'editor']);
            return $this->model->removeRelation($boardId, $userId, $input);
        });
    }

    public function dependencies(): void {
        $this->handle('GET', function (int $userId): array {
            $boardId = $this->positiveInt($_GET['board_id'] ?? null, 'board_id');
            $this->requireBoardRole($boardId, ['owner', 'designer', 'editor', 'viewer']);
            $itemId = isset($_GET['item_id']) ? $this->positiveInt($_GET['item_id'], 'item_id') : null;
            return $this->model->getDependencies($boardId, $itemId);
        });
    }

    public function add_dependency(): void {
        $this->handle('POST', function (int $userId, array $input): array {
            $boardId = $this->bodyBoardId($input);
            $this->requireBoardRole($boardId, ['owner', 'designer', 'editor']);
            return $this->model->addDependency($boardId, $userId, $input);
        });
    }

    public function remove_dependency(): void {
        $this->handle('POST', function (int $userId, array $input): array {
            $boardId = $this->bodyBoardId($input);
            $this->requireBoardRole($boardId, ['owner', 'designer', 'editor']);
            return $this->model->removeDependency($boardId, $userId, $input);
        });
    }

    public function set_subtask(): void {
        $this->handle('POST', function (int $userId, array $input): array {
            $boardId = $this->bodyBoardId($input);
            $this->requireBoardRole($boardId, ['owner', 'designer', 'editor']);
            return $this->model->setSubtask($boardId, $userId, $input);
        });
    }

    public function automations(): void {
        $this->handle('GET', function (int $userId): array {
            $boardId = $this->positiveInt($_GET['board_id'] ?? null, 'board_id');
            $this->requireBoardRole($boardId, ['owner', 'designer', 'editor', 'viewer']);
            return $this->model->getAutomations($boardId);
        });
    }

    public function create_automation(): void {
        $this->handle('POST', function (int $userId, array $input): array {
            $boardId = $this->bodyBoardId($input);
            $this->requireBoardRole($boardId, ['owner', 'designer']);
            return $this->model->createAutomation($boardId, $userId, $input);
        });
    }

    public function update_automation(): void {
        $this->handle('POST', function (int $userId, array $input): array {
            $boardId = $this->bodyBoardId($input);
            $this->requireBoardRole($boardId, ['owner', 'designer']);
            return $this->model->updateAutomation($boardId, $userId, $input);
        });
    }

    public function pause_automation(): void {
        $this->handle('POST', function (int $userId, array $input): array {
            $boardId = $this->bodyBoardId($input);
            $this->requireBoardRole($boardId, ['owner', 'designer']);
            return $this->model->setAutomationStatus($boardId, $userId, $input, 'paused');
        });
    }

    public function activate_automation(): void {
        $this->handle('POST', function (int $userId, array $input): array {
            $boardId = $this->bodyBoardId($input);
            $this->requireBoardRole($boardId, ['owner', 'designer']);
            return $this->model->setAutomationStatus($boardId, $userId, $input, 'active');
        });
    }

    public function delete_automation(): void {
        $this->handle('POST', function (int $userId, array $input): array {
            $boardId = $this->bodyBoardId($input);
            $this->requireBoardRole($boardId, ['owner', 'designer']);
            return $this->model->deleteAutomation($boardId, $userId, $input);
        });
    }

    public function automation_runs(): void {
        $this->handle('GET', function (int $userId): array {
            $boardId = $this->positiveInt($_GET['board_id'] ?? null, 'board_id');
            $this->requireBoardRole($boardId, ['owner', 'designer', 'editor', 'viewer']);
            $automationId = isset($_GET['automation_id']) ? $this->positiveInt($_GET['automation_id'], 'automation_id') : null;
            return $this->model->getAutomationRuns($boardId, $automationId);
        });
    }

    public function run_automation(): void {
        $this->handle('POST', function (int $userId, array $input): array {
            $boardId = $this->bodyBoardId($input);
            $this->requireBoardRole($boardId, ['owner', 'designer']);
            return $this->model->enqueueAutomationRun($boardId, $userId, $input);
        });
    }

    public function update_item_name(): void {
        $this->handle('POST', function (int $userId, array $input): array {
            $boardId = $this->bodyBoardId($input);
            $this->requireBoardRole($boardId, ['owner', 'designer', 'editor']);
            return $this->model->updateItemName($boardId, $userId, $input);
        });
    }

    public function create_board(): void {
        $this->handle('POST', function (int $userId, array $input): array {
            if (!$this->access->hasGlobalPermission('create')) {
                throw new TablerosApiException('forbidden', 'No tienes permiso para crear tableros.', 403);
            }
            $workspaceId = isset($input['workspace_id']) ? $this->positiveInt($input['workspace_id'], 'workspace_id') : 0;
            if ($workspaceId > 0 && $this->access->workspaceRole($workspaceId) === null) {
                throw new TablerosApiException('forbidden', 'No tienes acceso al espacio de trabajo seleccionado.', 403);
            }
            return $this->model->createBoard($userId, $input);
        });
    }

    public function create_group(): void {
        $this->handle('POST', function (int $userId, array $input): array {
            $boardId = $this->bodyBoardId($input);
            $this->requireBoardRole($boardId, ['owner', 'designer']);
            return $this->model->createGroup($boardId, $userId, $input);
        });
    }

    public function create_column(): void {
        $this->handle('POST', function (int $userId, array $input): array {
            $boardId = $this->bodyBoardId($input);
            $this->requireBoardRole($boardId, ['owner', 'designer']);
            return $this->model->createColumn($boardId, $userId, $input);
        });
    }

    public function create_item(): void {
        $this->handle('POST', function (int $userId, array $input): array {
            $boardId = $this->bodyBoardId($input);
            $this->requireBoardRole($boardId, ['owner', 'designer', 'editor']);
            return $this->model->createItem($boardId, $userId, $input);
        });
    }

    public function update_cell(): void {
        $this->handle('POST', function (int $userId, array $input): array {
            $boardId = $this->bodyBoardId($input);
            $this->requireBoardRole($boardId, ['owner', 'designer', 'editor']);
            return $this->model->updateCell($boardId, $userId, $input);
        });
    }

    public function move_item(): void {
        $this->handle('POST', function (int $userId, array $input): array {
            $boardId = $this->bodyBoardId($input);
            $this->requireBoardRole($boardId, ['owner', 'designer', 'editor']);
            return $this->model->moveItem($boardId, $userId, $input);
        });
    }

    public function share_board(): void {
        $this->handle('POST', function (int $userId, array $input): array {
            $boardId = $this->bodyBoardId($input);
            $this->requireBoardRole($boardId, ['owner', 'designer']);
            return $this->model->shareBoard($boardId, $userId, $input);
        });
    }

    public function members(): void {
        $this->handle('GET', function (int $userId): array {
            $boardId = $this->positiveInt($_GET['board_id'] ?? null, 'board_id');
            $this->requireBoardRole($boardId, ['owner', 'designer', 'editor', 'viewer']);
            return $this->model->getBoardMembers($boardId);
        });
    }

    public function revoke_member(): void {
        $this->handle('POST', function (int $userId, array $input): array {
            $boardId = $this->bodyBoardId($input);
            $this->requireBoardRole($boardId, ['owner', 'designer']);
            return $this->model->revokeBoardMember($boardId, $userId, $input);
        });
    }

    public function create_view(): void {
        $this->handle('POST', function (int $userId, array $input): array {
            $boardId = $this->bodyBoardId($input);
            $role = $this->requireBoardRole($boardId, ['owner', 'designer', 'editor', 'viewer']);
            if (filter_var($input['is_shared'] ?? false, FILTER_VALIDATE_BOOLEAN)
                && !in_array($role, ['owner', 'designer'], true)) {
                throw new TablerosApiException('forbidden', 'Solo el propietario o un diseñador puede compartir una vista.', 403);
            }
            return $this->model->createView($boardId, $userId, $input);
        });
    }

    /** Run a handler after checking authentication, module access, verb and CSRF. */
    private function handle(string $method, callable $callback): void {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $userId = $this->currentUserId();
            if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $method) {
                throw new TablerosApiException('validation', 'Método HTTP no permitido para esta operación.', 422);
            }
            $this->initializeServices();
            if (!$this->access->userIsActive($userId)) {
                throw new TablerosApiException('forbidden', 'La cuenta TG no está activa.', 403);
            }
            if (!$this->access->hasGlobalPermission('access')) {
                throw new TablerosApiException('forbidden', 'No tienes permiso para acceder a Tableros.', 403);
            }

            if ($method === 'POST') {
                $input = $this->readInput();
                $this->verifyCsrf($input);
                $result = $callback($userId, $input);
            } else {
                $result = $callback($userId);
            }

            $this->sendJson(200, ['success' => true, 'data' => $result]);
        } catch (TablerosApiException $e) {
            $this->sendJson($e->httpStatus, ['success' => false, 'code' => $e->apiCode, 'message' => $e->getMessage()]);
        } catch (Throwable $e) {
            if ((int)$e->getCode() === 403) {
                $this->sendJson(403, ['success' => false, 'code' => 'forbidden', 'message' => 'No tienes permiso para esta operación.']);
                return;
            }
            $sqlServerError = (int)($e instanceof PDOException ? ($e->errorInfo[1] ?? 0) : 0);
            if (in_array($sqlServerError, [2601, 2627], true)) {
                $this->sendJson(409, ['success' => false, 'code' => 'conflict', 'message' => 'El registro entra en conflicto con otro valor existente.']);
                return;
            }
            error_log('Tableros API error: ' . $e->getMessage());
            $this->sendJson(500, ['success' => false, 'code' => 'server', 'message' => 'Ocurrió un error al procesar la solicitud.']);
        }
    }

    private function initializeServices(): void {
        if (isset($this->model) && isset($this->access)) {
            return;
        }
        $this->pdo = TablerosConnection::get();
        $this->model = new TablerosModel($this->pdo);
        $this->access = new TablerosAccess($this->pdo);
    }

    private function currentUserId(): int {
        if (empty($_SESSION['tg_user']) || !isset($_SESSION['tg_user']['Id'])) {
            throw new TablerosApiException('forbidden', 'La sesión no está autenticada.', 403);
        }
        return $this->positiveInt($_SESSION['tg_user']['Id'], 'user_id');
    }

    private function requireBoardRole(int $boardId, array $allowedRoles): string {
        if (!$this->model->getBoard($boardId)) {
            throw new TablerosApiException('not_found', 'No se encontró el tablero.', 404);
        }
        $role = $this->access->boardRole($boardId);
        if ($role === null && $this->access->hasGlobalPermission('admin')) {
            $role = 'designer';
        }
        $role = strtolower((string)$role);
        if ($role === '' || !in_array($role, $allowedRoles, true)) {
            throw new TablerosApiException('forbidden', 'No tienes el rol necesario en este tablero.', 403);
        }
        return $role;
    }

    private function bodyBoardId(array $input): int {
        return $this->positiveInt($input['board_id'] ?? null, 'board_id');
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

    private function readInput(): array {
        if (!empty($_POST)) {
            return $_POST;
        }
        $raw = file_get_contents('php://input');
        if ($raw === '' || $raw === false) {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw new TablerosApiException('validation', 'El cuerpo JSON no es válido.', 422);
        }
        return $decoded;
    }

    private function verifyCsrf(array $input): void {
        $supplied = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? '');
        $expected = $_SESSION['tableros_csrf'] ?? '';
        if (!is_string($supplied) || !is_string($expected) || $expected === '' || !hash_equals($expected, $supplied)) {
            throw new TablerosApiException('forbidden', 'El token CSRF falta o no es válido.', 403);
        }
    }

    private function sendJson(int $status, array $payload): void {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function contentDisposition(string $filename): string {
        $filename = preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $filename))) ?? 'archivo';
        $ascii = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?? 'archivo';
        if ($ascii === '') $ascii = 'archivo';
        return 'attachment; filename="' . addcslashes($ascii, '"\\') . '"; filename*=UTF-8\'\'' . rawurlencode($filename);
    }
}
