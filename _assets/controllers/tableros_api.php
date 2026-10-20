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

    public function reorder_structure(): void {
        $this->handle('POST', function (int $userId, array $input): array {
            return $this->model->reorderStructure($userId, $this->access->hasGlobalPermission('admin'), $input);
        });
    }

    public function workspace_members(): void {
        $this->handle('GET', function (int $userId): array {
            $workspaceId = $this->positiveInt($_GET['workspace_id'] ?? null, 'workspace_id');
            $this->requireWorkspaceManager($workspaceId);
            return $this->model->getWorkspaceMembers($workspaceId);
        });
    }

    public function share_workspace(): void {
        $this->handle('POST', function (int $userId, array $input): array {
            $workspaceId = $this->positiveInt($input['workspace_id'] ?? null, 'workspace_id');
            $this->requireWorkspaceManager($workspaceId);
            return $this->model->shareWorkspace($workspaceId, $userId, $input);
        });
    }

    public function update_workspace_member(): void {
        $this->handle('POST', function (int $userId, array $input): array {
            $workspaceId = $this->positiveInt($input['workspace_id'] ?? null, 'workspace_id');
            $this->requireWorkspaceManager($workspaceId);
            return $this->model->updateWorkspaceMember($workspaceId, $userId, $input);
        });
    }

    public function revoke_workspace_member(): void {
        $this->handle('POST', function (int $userId, array $input): array {
            $workspaceId = $this->positiveInt($input['workspace_id'] ?? null, 'workspace_id');
            $this->requireWorkspaceManager($workspaceId);
            return $this->model->revokeWorkspaceMember($workspaceId, $userId, $input);
        });
    }

    public function trash(): void {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            $this->handle('GET', function (int $userId): array {
                $workspaceId = isset($_GET['workspace_id']) ? $this->positiveInt($_GET['workspace_id'], 'workspace_id') : null;
                return $this->model->getTrashEntries($userId, $this->access->hasGlobalPermission('admin'), $workspaceId, 'trash');
            });
            return;
        }
        $this->handle('POST', function (int $userId, array $input): array { return $this->model->trashEntity($userId, $input, 'trash'); });
    }

    public function restore_trash(): void {
        $this->handle('POST', function (int $userId, array $input): array {
            return $this->model->restoreTrashEntry($userId, $this->access->hasGlobalPermission('admin'), $this->positiveInt($input['entry_id'] ?? null, 'entry_id'), 'trash');
        });
    }

    public function permanently_delete_trash(): void {
        $this->handle('POST', function (int $userId, array $input): array {
            return $this->model->requestPermanentTrashDelete($userId, $this->access->hasGlobalPermission('admin'), $this->positiveInt($input['entry_id'] ?? null, 'entry_id'));
        });
    }

    public function archive(): void {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            $this->handle('GET', function (int $userId): array {
                $workspaceId = isset($_GET['workspace_id']) ? $this->positiveInt($_GET['workspace_id'], 'workspace_id') : null;
                return $this->model->getTrashEntries($userId, $this->access->hasGlobalPermission('admin'), $workspaceId, 'archive');
            });
            return;
        }
        $this->handle('POST', function (int $userId, array $input): array { return $this->model->trashEntity($userId, $input, 'archive'); });
    }

    public function restore_archive(): void {
        $this->handle('POST', function (int $userId, array $input): array {
            return $this->model->restoreTrashEntry($userId, $this->access->hasGlobalPermission('admin'), $this->positiveInt($input['entry_id'] ?? null, 'entry_id'), 'archive');
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
            $role = $this->requireBoardRole($boardId, ['owner', 'designer', 'editor', 'contributor', 'viewer']);
            $data = $this->model->getBoardData($boardId, $userId);
            $data['board']['role'] = $role;
            $access = $this->access->boardAccess($boardId);
            $data['board']['access_source'] = $access['source'] ?? null;
            if (($access['source'] ?? null) === 'admin') $this->model->auditAdminBoardOpen($userId, $boardId);
            return $data;
        });
    }

    public function users(): void {
        $this->handle('GET', function (int $userId): array {
            $query = $_GET['q'] ?? '';
            if (isset($_GET['board_id'])) {
                $boardId = $this->positiveInt($_GET['board_id'], 'board_id');
                if (filter_var($_GET['directory'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    $this->requireBoardRole($boardId, ['owner', 'designer']);
                    return $this->model->activeUsersWithDashboardAccess(is_string($query) ? $query : '');
                }
                $this->requireBoardRole($boardId, ['owner', 'designer', 'editor', 'contributor', 'viewer']);
                $rawIds = $_GET['ids'] ?? '';
                $ids = is_string($rawIds) ? array_slice(array_filter(array_map('intval', explode(',', $rawIds)), static fn(int $id): bool => $id > 0), 0, 100) : [];
                return $this->access->activeUsersForBoard($boardId, is_string($query) ? $query : '', $ids);
            }
            if (isset($_GET['workspace_id']) && filter_var($_GET['directory'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $workspaceId = $this->positiveInt($_GET['workspace_id'], 'workspace_id');
                $this->requireWorkspaceManager($workspaceId);
                return $this->access->activeUsersForWorkspace($workspaceId, is_string($query) ? $query : '');
            }
            return $this->model->activeUsers(is_string($query) ? $query : '', true);
        });
    }

    public function activity(): void {
        $this->handle('GET', function (int $userId): array {
            $boardId = $this->positiveInt($_GET['board_id'] ?? null, 'board_id');
            $this->requireBoardRole($boardId, ['owner', 'designer', 'editor', 'contributor', 'viewer']);
            $itemId = isset($_GET['item_id']) ? $this->positiveInt($_GET['item_id'], 'item_id') : null;
            $limit = isset($_GET['limit']) ? $this->positiveInt($_GET['limit'], 'limit') : 100;
            return $this->model->getActivity($boardId, $itemId, $limit);
        });
    }

    public function comments(): void {
        $this->handle('GET', function (int $userId): array {
            $boardId = $this->positiveInt($_GET['board_id'] ?? null, 'board_id');
            $itemId = $this->positiveInt($_GET['item_id'] ?? null, 'item_id');
            $this->requireBoardRole($boardId, ['owner', 'designer', 'editor', 'contributor', 'viewer']);
            return $this->model->getComments($boardId, $itemId);
        });
    }

    public function add_comment(): void {
        $this->handle('POST', function (int $userId, array $input): array {
            $boardId = $this->bodyBoardId($input);
            $this->requireBoardRole($boardId, ['owner', 'designer', 'editor', 'contributor']);
            return $this->model->addComment($boardId, $userId, $input);
        });
    }

    public function file_comments($id): void {
        $this->handle('GET', function (int $userId) use ($id): array {
            $fileId = $this->positiveInt($id, 'file_id');
            $boardId = $this->model->getFileBoardId($fileId);
            $this->requireBoardRole($boardId, ['owner', 'designer', 'editor', 'contributor', 'viewer']);
            return $this->model->getFileComments($fileId);
        });
    }

    public function add_file_comment(): void {
        $this->handle('POST', function (int $userId, array $input): array {
            $fileId = $this->positiveInt($input['file_id'] ?? null, 'file_id');
            $boardId = $this->model->getFileBoardId($fileId);
            $this->requireBoardRole($boardId, ['owner', 'designer', 'editor', 'contributor']);
            return $this->model->addFileComment($fileId, $userId, $input);
        });
    }

    public function stickers(): void {
        $this->handle('GET', function (int $userId): array {
            return $this->model->getStickers();
        });
    }

    public function upload_sticker(): void {
        $this->handle('POST', function (int $userId, array $input): array {
            if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
                throw new TablerosApiException('validation', 'Selecciona una imagen para subir.', 422);
            }
            return $this->model->uploadSticker($userId, $_FILES['file'], $input['name'] ?? null);
        });
    }

    public function sticker_file($id): void {
        try {
            $userId = $this->currentUserId();
            if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') throw new TablerosApiException('validation', 'Método HTTP no permitido.', 422);
            $this->initializeServices();
            if (!$this->access->userIsActive($userId) || !$this->access->hasGlobalPermission('access')) {
                throw new TablerosApiException('forbidden', 'No tienes permiso para esta operación.', 403);
            }
            $sticker = $this->model->getStickerDownload($this->positiveInt($id, 'sticker_id'));
            header('Content-Type: ' . (string)$sticker['content_type']);
            header('Content-Length: ' . (int)$sticker['byte_size']);
            header('Content-Disposition: ' . $this->contentDisposition((string)$sticker['name'] . '.' . (string)$sticker['extension'], true));
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, max-age=3600');
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
            readfile($sticker['path']);
            if (!empty($sticker['temporary']) && is_file($sticker['path'])) @unlink($sticker['path']);
            exit;
        } catch (TablerosApiException $e) {
            $this->sendJson($e->httpStatus, ['success' => false, 'code' => $e->apiCode, 'message' => $e->getMessage()]);
        } catch (Throwable $e) {
            error_log('Tableros sticker download error: ' . $e->getMessage());
            $this->sendJson(500, ['success' => false, 'code' => 'server', 'message' => 'Ocurrió un error al cargar el sticker.']);
        }
    }

    public function attach_comment_file(): void {
        $this->handle('POST', function (int $userId, array $input): array {
            $commentId = $this->positiveInt($input['file_comment_id'] ?? null, 'file_comment_id');
            $fileId = $this->positiveInt($input['file_id'] ?? null, 'file_id');
            $boardId = $this->model->getFileCommentBoardId($commentId);
            $this->requireBoardRole($boardId, ['owner', 'designer', 'editor', 'contributor']);
            $this->model->attachCommentFile($commentId, $fileId);
            return ['file_comment_id' => $commentId, 'file_id' => $fileId];
        });
    }

    public function notifications(): void {
        $this->handle('GET', function (int $userId): array {
            return $this->model->getNotifications($userId);
        });
    }

    public function mark_notification_read(): void {
        $this->handle('POST', function (int $userId, array $input): array {
            $notificationId = $this->positiveInt($input['notification_id'] ?? null, 'notification_id');
            return $this->model->markNotificationRead($userId, $notificationId);
        });
    }

    public function upload_file(): void {
        $this->handle('POST', function (int $userId, array $input): array {
            $boardId = $this->bodyBoardId($input);
            $this->requireBoardRole($boardId, ['owner', 'designer', 'editor', 'contributor']);
            if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
                throw new TablerosApiException('validation', 'Selecciona un archivo para subir.', 422);
            }
            return $this->model->uploadFile($boardId, $userId, $input, $_FILES['file']);
        });
    }

    public function file_versions($id): void {
        $this->handle('GET', function (int $userId) use ($id): array {
            $fileId = $this->positiveInt($id, 'file_id');
            $boardId = $this->model->getFileBoardId($fileId);
            $this->requireBoardRole($boardId, ['owner', 'designer', 'editor', 'contributor', 'viewer']);
            return $this->model->getFileVersions($boardId, $fileId);
        });
    }

    public function download_file($id): void {
        $file = null;
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
            $this->requireBoardRole($boardId, ['owner', 'designer', 'editor', 'contributor', 'viewer']);
            $versionId = isset($_GET['version_id']) ? $this->positiveInt($_GET['version_id'], 'version_id') : null;
            $rangeHeader = ($_GET['preview'] ?? '') === '1' && isset($_SERVER['HTTP_RANGE'])
                ? (string)$_SERVER['HTTP_RANGE'] : null;
            $file = $this->model->getFileDownload($fileId, $versionId, $rangeHeader);
            $contentType = strtolower((string)$file['content_type']);
            $isVideo = in_array($contentType, ['video/mp4', 'video/webm'], true);
            $previewableTypes = ['application/pdf', 'image/webp', 'image/jpeg', 'image/png', 'image/gif', 'text/plain', 'text/csv', 'video/mp4', 'video/webm'];
            $inlinePreview = ($_GET['preview'] ?? '') === '1' && in_array($contentType, $previewableTypes, true);
            $byteSize = (int)$file['byte_size'];
            $range = $isVideo && $inlinePreview
                ? (!empty($file['range_unsatisfiable']) ? false : ($file['range'] ?? null)) : null;
            if ($isVideo && $inlinePreview && $range === false) {
                http_response_code(416);
                header('Content-Range: bytes */' . $byteSize);
                header('Accept-Ranges: bytes');
                header('Content-Length: 0');
                header('X-Content-Type-Options: nosniff');
                if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
                if (!empty($file['temporary']) && is_file($file['path'])) @unlink($file['path']);
                exit;
            }
            header('Content-Type: ' . $file['content_type']);
            header('Content-Disposition: ' . $this->contentDisposition((string)$file['name'], $inlinePreview));
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, no-store, max-age=0');
            if ($isVideo && $inlinePreview) header('Accept-Ranges: bytes');
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            if ($range !== null) {
                [$start, $end] = $range;
                http_response_code(206);
                header('Content-Range: bytes ' . $start . '-' . $end . '/' . $byteSize);
                header('Content-Length: ' . ($end - $start + 1));
                $this->streamFileRange((string)$file['path'], $start, $end - $start + 1);
            } else {
                header('Content-Length: ' . $byteSize);
                readfile($file['path']);
            }
            if (!empty($file['temporary']) && is_file($file['path'])) @unlink($file['path']);
            exit;
        } catch (TablerosApiException $e) {
            if (is_array($file) && !empty($file['temporary']) && is_file((string)$file['path'])) @unlink((string)$file['path']);
            $this->sendJson($e->httpStatus, ['success' => false, 'code' => $e->apiCode, 'message' => $e->getMessage()]);
        } catch (Throwable $e) {
            if (is_array($file) && !empty($file['temporary']) && is_file((string)$file['path'])) @unlink((string)$file['path']);
            error_log('Tableros file download error: ' . $e->getMessage());
            $this->sendJson(500, ['success' => false, 'code' => 'server', 'message' => 'Ocurrió un error al descargar el archivo.']);
        }
    }

    public function thumbnail_file($id): void {
        $thumbnail = null;
        try {
            $userId = $this->currentUserId();
            if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') throw new TablerosApiException('validation', 'Método HTTP no permitido para esta operación.', 422);
            $this->initializeServices();
            if (!$this->access->userIsActive($userId) || !$this->access->hasGlobalPermission('access')) throw new TablerosApiException('forbidden', 'No tienes permiso para esta operación.', 403);
            $fileId = $this->positiveInt($id, 'file_id');
            $boardId = $this->model->getFileBoardId($fileId);
            $this->requireBoardRole($boardId, ['owner', 'designer', 'editor', 'contributor', 'viewer']);
            $versionId = isset($_GET['version_id']) ? $this->positiveInt($_GET['version_id'], 'version_id') : null;
            $thumbnail = $this->model->getFileThumbnail($fileId, $versionId);
            header('Content-Type: image/webp');
            header('Content-Length: ' . (int)$thumbnail['byte_size']);
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, max-age=900');
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
            readfile($thumbnail['path']);
            if (!empty($thumbnail['temporary']) && is_file($thumbnail['path'])) @unlink($thumbnail['path']);
            exit;
        } catch (TablerosApiException $e) {
            if (is_array($thumbnail) && !empty($thumbnail['temporary']) && is_file((string)$thumbnail['path'])) @unlink((string)$thumbnail['path']);
            $this->sendJson($e->httpStatus, ['success' => false, 'code' => $e->apiCode, 'message' => $e->getMessage()]);
        } catch (Throwable $e) {
            if (is_array($thumbnail) && !empty($thumbnail['temporary']) && is_file((string)$thumbnail['path'])) @unlink((string)$thumbnail['path']);
            error_log('Tableros thumbnail error: ' . $e->getMessage());
            $this->sendJson(500, ['success' => false, 'code' => 'server', 'message' => 'Ocurrió un error al cargar la miniatura.']);
        }
    }

    public function preview_image_file($id): void {
        $preview = null;
        try {
            $userId = $this->currentUserId();
            if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') throw new TablerosApiException('validation', 'Método HTTP no permitido para esta operación.', 422);
            $this->initializeServices();
            if (!$this->access->userIsActive($userId) || !$this->access->hasGlobalPermission('access')) throw new TablerosApiException('forbidden', 'No tienes permiso para esta operación.', 403);
            $fileId = $this->positiveInt($id, 'file_id');
            $boardId = $this->model->getFileBoardId($fileId);
            $this->requireBoardRole($boardId, ['owner', 'designer', 'editor', 'contributor', 'viewer']);
            $versionId = isset($_GET['version_id']) ? $this->positiveInt($_GET['version_id'], 'version_id') : null;
            $preview = $this->model->getFileImagePreview($fileId, $versionId);
            header('Content-Type: image/webp');
            header('Content-Length: ' . (int)$preview['byte_size']);
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, max-age=900');
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
            readfile($preview['path']);
            if (!empty($preview['temporary']) && is_file($preview['path'])) @unlink($preview['path']);
            exit;
        } catch (TablerosApiException $e) {
            if (is_array($preview) && !empty($preview['temporary']) && is_file((string)$preview['path'])) @unlink((string)$preview['path']);
            $this->sendJson($e->httpStatus, ['success' => false, 'code' => $e->apiCode, 'message' => $e->getMessage()]);
        } catch (Throwable $e) {
            if (is_array($preview) && !empty($preview['temporary']) && is_file((string)$preview['path'])) @unlink((string)$preview['path']);
            error_log('Tableros image preview error: ' . $e->getMessage());
            $this->sendJson(500, ['success' => false, 'code' => 'server', 'message' => 'Ocurrió un error al cargar la vista previa.']);
        }
    }

    public function download_files_zip(): void {
        $archivePath = null;
        $temporaryFiles = [];
        try {
            $userId = $this->currentUserId();
            if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                throw new TablerosApiException('validation', 'Método HTTP no permitido para esta operación.', 422);
            }
            $this->initializeServices();
            if (!$this->access->userIsActive($userId) || !$this->access->hasGlobalPermission('access')) {
                throw new TablerosApiException('forbidden', 'No tienes permiso para esta operación.', 403);
            }
            $input = $this->readInput();
            $this->verifyCsrf($input);
            $boardId = $this->positiveInt($input['board_id'] ?? null, 'board_id');
            $this->requireBoardRole($boardId, ['owner', 'designer', 'editor', 'contributor', 'viewer']);
            $rawIds = $input['file_ids'] ?? null;
            if (!is_array($rawIds) || !$rawIds || count($rawIds) > 200) {
                throw new TablerosApiException('validation', 'Selecciona entre 1 y 200 archivos para descargar.', 422);
            }
            if (!class_exists(ZipArchive::class)) {
                throw new TablerosApiException('server', 'La descarga ZIP no está disponible en este servidor.', 500);
            }
            $fileIds = [];
            foreach ($rawIds as $rawId) $fileIds[] = $this->positiveInt($rawId, 'file_id');
            $fileIds = array_values(array_unique($fileIds));
            if (!$fileIds) throw new TablerosApiException('validation', 'No hay archivos disponibles para descargar.', 422);

            $archivePath = tempnam(sys_get_temp_dir(), 'tableros-zip-');
            if ($archivePath === false) throw new TablerosApiException('server', 'No se pudo preparar el archivo ZIP.', 500);
            $archive = new ZipArchive();
            if ($archive->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new TablerosApiException('server', 'No se pudo crear el archivo ZIP.', 500);
            }
            try {
                foreach ($fileIds as $fileId) {
                    if ($this->model->getFileBoardId($fileId) !== $boardId) {
                        throw new TablerosApiException('forbidden', 'Todos los archivos deben pertenecer al tablero actual.', 403);
                    }
                    $file = $this->model->getFileDownload($fileId);
                    if (!empty($file['temporary'])) $temporaryFiles[] = $file['path'];
                    $baseName = basename(str_replace('\\', '/', (string)$file['name']));
                    $baseName = preg_replace('/[\\x00-\\x1F\\x7F\\\\\\/]+/u', '_', $baseName) ?: 'archivo-' . $fileId;
                    $entryName = (int)$file['item_id'] . '-' . $fileId . '-' . $baseName;
                    if (!$archive->addFile($file['path'], $entryName)) {
                        throw new TablerosApiException('server', 'No se pudo agregar un archivo al ZIP.', 500);
                    }
                }
            } catch (Throwable $e) {
                $archive->close();
                throw $e;
            }
            if (!$archive->close()) throw new TablerosApiException('server', 'No se pudo finalizar el archivo ZIP.', 500);
            header('Content-Type: application/zip');
            header('Content-Length: ' . (string)filesize($archivePath));
            header('Content-Disposition: ' . $this->contentDisposition('archivos-tablero-' . $boardId . '.zip'));
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, no-store, max-age=0');
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
            readfile($archivePath);
        } catch (TablerosApiException $e) {
            $this->sendJson($e->httpStatus, ['success' => false, 'code' => $e->apiCode, 'message' => $e->getMessage()]);
        } catch (Throwable $e) {
            error_log('Tableros ZIP download error: ' . $e->getMessage());
            $this->sendJson(500, ['success' => false, 'code' => 'server', 'message' => 'Ocurrió un error al preparar la descarga ZIP.']);
        } finally {
            foreach ($temporaryFiles as $path) if (is_file($path)) @unlink($path);
            if ($archivePath !== null && is_file($archivePath)) @unlink($archivePath);
        }
        exit;
    }

    private function renderDocxPrintPreview(string $path): string {
        if (!class_exists(ZipArchive::class) || !class_exists(DOMDocument::class)) {
            throw new TablerosApiException('validation', 'La vista previa de Word requiere las extensiones ZIP y DOM de PHP.', 503);
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) throw new TablerosApiException('validation', 'El documento Word no se pudo leer.', 422);
        try {
            $entry = $zip->statName('word/document.xml');
            if (!$entry || (int)$entry['size'] > 30 * 1024 * 1024 || $zip->numFiles > 5000) {
                throw new TablerosApiException('validation', 'El contenido del documento excede los límites de vista previa.', 413);
            }
            $xml = $zip->getFromName('word/document.xml');
        } finally { $zip->close(); }
        if (!is_string($xml) || strlen($xml) > 30 * 1024 * 1024) throw new TablerosApiException('validation', 'El documento Word no se pudo leer.', 422);
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            if (!$document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT)) throw new TablerosApiException('validation', 'El documento Word contiene XML no válido.', 422);
        } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $body = $xpath->query('/w:document/w:body')->item(0);
        if (!$body) throw new TablerosApiException('validation', 'El documento Word no contiene contenido visible.', 422);
        $content = '<main class="document-preview">';
        $pageBlocks = 0;
        foreach ($body->childNodes as $block) {
            if (!$block instanceof DOMElement) continue;
            $explicitPageBreak = $block->localName === 'p' && $xpath->query('.//w:br[@w:type="page"]', $block)->length > 0;
            if ($pageBlocks >= 32 || ($explicitPageBreak && $pageBlocks > 0)) {
                $content .= '</section>';
                $pageBlocks = 0;
            }
            if ($pageBlocks === 0) $content .= '<section class="page">';
            if ($block->localName === 'p') {
                $text = '';
                foreach ($xpath->query('.//w:t | .//w:tab | .//w:br', $block) as $part) {
                    $text .= $part->localName === 't' ? $part->textContent : ($part->localName === 'tab' ? "\t" : "\n");
                }
                $style = $xpath->query('./w:pPr/w:pStyle', $block)->item(0);
                $styleName = $style instanceof DOMElement ? $style->getAttribute('w:val') : '';
                $tag = preg_match('/^Title|^Heading[1-3]/i', $styleName) ? 'h2' : 'p';
                $content .= '<' . $tag . '>' . nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</' . $tag . '>';
                $pageBlocks++;
            } elseif ($block->localName === 'tbl') {
                $content .= '<table><tbody>';
                foreach ($xpath->query('./w:tr', $block) as $tr) {
                    $content .= '<tr>';
                    foreach ($xpath->query('./w:tc', $tr) as $tc) {
                        $text = [];
                        foreach ($xpath->query('.//w:t', $tc) as $part) $text[] = $part->textContent;
                        $content .= '<td>' . nl2br(htmlspecialchars(implode('', $text), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</td>';
                    }
                    $content .= '</tr>';
                }
                $content .= '</tbody></table>';
                $pageBlocks++;
            }
            if (strlen($content) > 8 * 1024 * 1024) throw new TablerosApiException('validation', 'El documento excede el tamaño permitido para vista previa.', 413);
        }
        $content .= $pageBlocks > 0 ? '</section>' : '';
        $content .= '</main>';
        return '<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Vista de impresión</title><style>*{box-sizing:border-box}body{margin:24px auto;padding:0;color:#202124;background:#e8eaed;font:11pt Arial,sans-serif}.notice{max-width:8.5in;margin:0 auto 12px;padding:10px 14px;color:#455a64;background:#fff3cd;border-radius:6px;font:13px Arial,sans-serif}.page{width:8.5in;min-height:11in;margin:0 auto 20px;padding:.75in;background:#fff;box-shadow:0 1px 8px #0002}p{white-space:pre-wrap;line-height:1.45;margin:0 0 10pt}h2{margin:12pt 0 8pt;font-size:16pt}table{width:100%;border-collapse:collapse;margin:12pt 0;page-break-inside:auto}tr{page-break-inside:avoid}td{border:1px solid #9aa0a6;padding:5pt;vertical-align:top}@media print{body{margin:0;background:#fff}.notice{display:none}.page{width:auto;min-height:0;margin:0;padding:0;box-shadow:none;break-after:page} @page{size:letter;margin:.75in}}</style><body><p class="notice">Vista aproximada para impresión. El formato original puede incluir imágenes, fuentes o elementos de diseño que aquí no se reproducen.</p>' . $content . '</body></html>';
    }

    public function preview_file($id): void {
        $file = null;
        $html = null;
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
            $this->requireBoardRole($boardId, ['owner', 'designer', 'editor', 'contributor', 'viewer']);
            $versionId = isset($_GET['version_id']) ? $this->positiveInt($_GET['version_id'], 'version_id') : null;
            $file = $this->model->getFileDownload($fileId, $versionId);
            $extension = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
            $isWord = $extension === 'docx';
            $readerTypes = ['xlsx' => 'Xlsx', 'xls' => 'Xls', 'xlsm' => 'Xlsx'];
            if (!$isWord && !isset($readerTypes[$extension])) {
                if ($extension === 'doc') throw new TablerosApiException('validation', 'Convierte el archivo .doc a .docx para previsualizarlo.', 415);
                throw new TablerosApiException('validation', 'Este tipo de archivo no tiene vista previa disponible.', 415);
            }
            if ((int)$file['byte_size'] > 25 * 1024 * 1024) {
                throw new TablerosApiException('validation', 'El archivo supera el límite de 25 MB para generar una vista previa.', 413);
            }

            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }

            if ($isWord) {
                $html = $this->renderDocxPrintPreview((string)$file['path']);
            } else {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader($readerTypes[$extension]);
            $reader->setReadDataOnly(false);
            $sheetNames = $reader->listWorksheetNames($file['path']);
            if ($sheetNames !== []) {
                $reader->setLoadSheetsOnly(array_slice($sheetNames, 0, 20));
            }
            $reader->setReadFilter(new class implements \PhpOffice\PhpSpreadsheet\Reader\IReadFilter {
                public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool {
                    return $row <= 500 && \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($columnAddress) <= 52;
                }
            });
            $spreadsheet = $reader->load($file['path']);
            try {
                $writer = new \PhpOffice\PhpSpreadsheet\Writer\Html($spreadsheet);
                $writer->setPreCalculateFormulas(false);
                $writer->writeAllSheets();
                $html = $writer->generateHtmlAll();
                // Cell text is escaped by the HTML writer. Remove generated hyperlinks so
                // workbook links cannot navigate the preview frame.
                $html = preg_replace('/<\/?a\b[^>]*>/i', '', $html) ?? $html;
                $html = preg_replace('/<\/head>/i', "<style>html,body{background:#e8eaed;color:#1f2937}body{margin:24px}@media print{@page{size:landscape;margin:12mm}html,body{background:#fff}body{margin:0}table{break-after:page;box-shadow:none!important}.sheet{break-after:page;page-break-after:always}}</style>\n</head>", $html, 1) ?? $html;
                $html = preg_replace('/<body[^>]*>/i', '$0<p style="font:14px sans-serif;color:#4b5563">La vista previa muestra hasta 20 hojas, 500 filas y 52 columnas por hoja.</p>', $html, 1) ?? $html;
            } finally {
                $spreadsheet->disconnectWorksheets();
            }
            }
        } catch (TablerosApiException $e) {
            $this->sendJson($e->httpStatus, ['success' => false, 'code' => $e->apiCode, 'message' => $e->getMessage()]);
            return;
        } catch (Throwable $e) {
            error_log('Tableros file preview error: ' . $e->getMessage());
            $this->sendJson(500, ['success' => false, 'code' => 'server', 'message' => 'Ocurrió un error al generar la vista previa.']);
            return;
        } finally {
            if (!empty($file['temporary']) && !empty($file['path']) && is_file($file['path'])) {
                @unlink($file['path']);
            }
        }

        header('Content-Type: text/html; charset=utf-8');
        header('Content-Length: ' . strlen((string)$html));
        header('Content-Disposition: ' . $this->contentDisposition((string)$file['name'] . '.html', true));
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: default-src 'none'; img-src data:; style-src 'unsafe-inline'; font-src data:; object-src 'none'; base-uri 'none'; form-action 'none'; sandbox");
        header('Cache-Control: private, no-store, max-age=0');
        echo $html;
        exit;
    }

    public function relations(): void {
        $this->handle('GET', function (int $userId): array {
            $boardId = $this->positiveInt($_GET['board_id'] ?? null, 'board_id');
            $itemId = $this->positiveInt($_GET['item_id'] ?? null, 'item_id');
            $this->requireBoardRole($boardId, ['owner', 'designer', 'editor', 'contributor', 'viewer']);
            return $this->model->getRelations($boardId, $itemId);
        });
    }

    public function add_relation(): void {
        $this->handle('POST', function (int $userId, array $input): array {
            $boardId = $this->bodyBoardId($input);
            $this->requireBoardRole($boardId, ['owner', 'designer', 'editor', 'contributor']);
            return $this->model->addRelation($boardId, $userId, $input);
        });
    }

    public function remove_relation(): void {
        $this->handle('POST', function (int $userId, array $input): array {
            $boardId = $this->bodyBoardId($input);
            $this->requireBoardRole($boardId, ['owner', 'designer', 'editor', 'contributor']);
            return $this->model->removeRelation($boardId, $userId, $input);
        });
    }

    public function dependencies(): void {
        $this->handle('GET', function (int $userId): array {
            $boardId = $this->positiveInt($_GET['board_id'] ?? null, 'board_id');
            $this->requireBoardRole($boardId, ['owner', 'designer', 'editor', 'contributor', 'viewer']);
            $itemId = isset($_GET['item_id']) ? $this->positiveInt($_GET['item_id'], 'item_id') : null;
            return $this->model->getDependencies($boardId, $itemId);
        });
    }

    public function add_dependency(): void {
        $this->handle('POST', function (int $userId, array $input): array {
            $boardId = $this->bodyBoardId($input);
            $this->requireBoardRole($boardId, ['owner', 'designer', 'editor', 'contributor']);
            return $this->model->addDependency($boardId, $userId, $input);
        });
    }

    public function remove_dependency(): void {
        $this->handle('POST', function (int $userId, array $input): array {
            $boardId = $this->bodyBoardId($input);
            $this->requireBoardRole($boardId, ['owner', 'designer', 'editor', 'contributor']);
            return $this->model->removeDependency($boardId, $userId, $input);
        });
    }

    public function set_subtask(): void {
        $this->handle('POST', function (int $userId, array $input): array {
            $boardId = $this->bodyBoardId($input);
            $this->requireBoardRole($boardId, ['owner', 'designer', 'editor', 'contributor']);
            return $this->model->setSubtask($boardId, $userId, $input);
        });
    }

    public function automations(): void {
        $this->handle('GET', function (int $userId): array {
            $boardId = $this->positiveInt($_GET['board_id'] ?? null, 'board_id');
            $this->requireBoardRole($boardId, ['owner', 'designer', 'editor', 'contributor', 'viewer']);
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
            $this->requireBoardRole($boardId, ['owner', 'designer', 'editor', 'contributor', 'viewer']);
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
            $this->requireBoardRole($boardId, ['owner', 'designer', 'editor', 'contributor']);
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
            $this->requireBoardRole($boardId, ['owner', 'designer', 'editor']);
            return $this->model->createGroup($boardId, $userId, $input);
        });
    }

    public function reorder_groups(): void {
        $this->handle('POST', function (int $userId, array $input): array {
            $boardId = $this->bodyBoardId($input);
            $this->requireBoardRole($boardId, ['owner', 'designer', 'editor']);
            return $this->model->reorderGroups($boardId, $userId, $input);
        });
    }

    public function create_column(): void {
        $this->handle('POST', function (int $userId, array $input): array {
            $boardId = $this->bodyBoardId($input);
            $this->requireBoardRole($boardId, ['owner', 'designer', 'editor']);
            return $this->model->createColumn($boardId, $userId, $input);
        });
    }

    public function update_column_options(): void {
        $this->handle('POST', function (int $userId, array $input): array {
            $boardId = $this->bodyBoardId($input);
            $this->requireBoardRole($boardId, ['owner', 'designer', 'editor']);
            return $this->model->updateColumnOptions($boardId, $userId, $input);
        });
    }

    public function create_item(): void {
        $this->handle('POST', function (int $userId, array $input): array {
            $boardId = $this->bodyBoardId($input);
            $this->requireBoardRole($boardId, ['owner', 'designer', 'editor', 'contributor']);
            return $this->model->createItem($boardId, $userId, $input);
        });
    }

    public function update_cell(): void {
        $this->handle('POST', function (int $userId, array $input): array {
            $boardId = $this->bodyBoardId($input);
            $this->requireBoardRole($boardId, ['owner', 'designer', 'editor', 'contributor']);
            return $this->model->updateCell($boardId, $userId, $input);
        });
    }

    public function move_item(): void {
        $this->handle('POST', function (int $userId, array $input): array {
            $boardId = $this->bodyBoardId($input);
            $this->requireBoardRole($boardId, ['owner', 'designer', 'editor', 'contributor']);
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

    public function duplicate_board(): void {
        $this->handle('POST', function (int $userId, array $input): array {
            $boardId = $this->bodyBoardId($input);
            $this->requireBoardRole($boardId, ['owner', 'designer']);
            if (!$this->access->hasGlobalPermission('create')) {
                throw new TablerosApiException('forbidden', 'No tienes permiso para crear tableros.', 403);
            }
            return $this->model->duplicateBoard($boardId, $userId, $input);
        });
    }

    /** Streams at most the requested byte count without buffering the complete media file. */
    private function streamFileRange(string $path, int $start, int $length): void {
        $stream = @fopen($path, 'rb');
        if (!is_resource($stream) || fseek($stream, $start) !== 0) {
            if (is_resource($stream)) fclose($stream);
            throw new TablerosApiException('server', 'No se pudo leer el rango solicitado del archivo.', 500);
        }
        try {
            $remaining = $length;
            while ($remaining > 0 && !feof($stream)) {
                $chunk = fread($stream, min(1024 * 1024, $remaining));
                if ($chunk === false || $chunk === '') break;
                echo $chunk;
                $remaining -= strlen($chunk);
            }
        } finally {
            fclose($stream);
        }
    }

    public function update_board_visibility(): void {
        $this->handle('POST', function (int $userId, array $input): array {
            $boardId = $this->bodyBoardId($input);
            $this->requireBoardRole($boardId, ['owner', 'designer']);
            return $this->model->updateBoardVisibility($boardId, $userId, $input);
        });
    }

    public function members(): void {
        $this->handle('GET', function (int $userId): array {
            $boardId = $this->positiveInt($_GET['board_id'] ?? null, 'board_id');
            $this->requireBoardRole($boardId, ['owner', 'designer', 'editor', 'contributor', 'viewer']);
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
            $role = $this->requireBoardRole($boardId, ['owner', 'designer', 'editor', 'contributor', 'viewer']);
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
        // Every API response is user- and state-specific. In particular, a
        // board reload after trashing an item must not reuse a cached GET.
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
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

    private function requireWorkspaceManager(int $workspaceId): void {
        if (!$this->model->getWorkspace($workspaceId)) throw new TablerosApiException('not_found', 'No se encontró el espacio de trabajo.', 404);
        $role = $this->access->workspaceRole($workspaceId);
        if ($role !== 'owner') throw new TablerosApiException('forbidden', 'Solo el propietario del espacio o un administrador puede administrar sus miembros.', 403);
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

    private function contentDisposition(string $filename, bool $inline = false): string {
        $filename = preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $filename))) ?? 'archivo';
        $ascii = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?? 'archivo';
        if ($ascii === '') $ascii = 'archivo';
        return ($inline ? 'inline' : 'attachment') . '; filename="' . addcslashes($ascii, '"\\') . '"; filename*=UTF-8\'\'' . rawurlencode($filename);
    }
}
