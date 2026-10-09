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
                if (filter_var($_GET['directory'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    $this->requireBoardRole($boardId, ['owner', 'designer']);
                    return $this->model->activeUsers(is_string($query) ? $query : '');
                }
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

    public function file_comments($id): void {
        $this->handle('GET', function (int $userId) use ($id): array {
            $fileId = $this->positiveInt($id, 'file_id');
            $boardId = $this->model->getFileBoardId($fileId);
            $this->requireBoardRole($boardId, ['owner', 'designer', 'editor', 'viewer']);
            return $this->model->getFileComments($fileId);
        });
    }

    public function add_file_comment(): void {
        $this->handle('POST', function (int $userId, array $input): array {
            $fileId = $this->positiveInt($input['file_id'] ?? null, 'file_id');
            $boardId = $this->model->getFileBoardId($fileId);
            $this->requireBoardRole($boardId, ['owner', 'designer', 'editor']);
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
            header('Content-Type: image/webp');
            header('Content-Length: ' . (int)$sticker['byte_size']);
            header('Content-Disposition: ' . $this->contentDisposition((string)$sticker['name'] . '.webp', true));
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
            $this->requireBoardRole($boardId, ['owner', 'designer', 'editor']);
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
            $this->requireBoardRole($boardId, ['owner', 'designer', 'editor']);
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
            $previewableTypes = ['application/pdf', 'image/webp', 'image/jpeg', 'image/png', 'image/gif', 'text/plain', 'text/csv'];
            $inlinePreview = ($_GET['preview'] ?? '') === '1' && in_array(strtolower((string)$file['content_type']), $previewableTypes, true);
            header('Content-Disposition: ' . $this->contentDisposition((string)$file['name'], $inlinePreview));
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, no-store, max-age=0');
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            readfile($file['path']);
            if (!empty($file['temporary']) && is_file($file['path'])) @unlink($file['path']);
            exit;
        } catch (TablerosApiException $e) {
            $this->sendJson($e->httpStatus, ['success' => false, 'code' => $e->apiCode, 'message' => $e->getMessage()]);
        } catch (Throwable $e) {
            error_log('Tableros file download error: ' . $e->getMessage());
            $this->sendJson(500, ['success' => false, 'code' => 'server', 'message' => 'Ocurrió un error al descargar el archivo.']);
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
            $this->requireBoardRole($boardId, ['owner', 'designer', 'editor', 'viewer']);
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
            $this->requireBoardRole($boardId, ['owner', 'designer', 'editor', 'viewer']);
            $versionId = isset($_GET['version_id']) ? $this->positiveInt($_GET['version_id'], 'version_id') : null;
            $file = $this->model->getFileDownload($fileId, $versionId);
            $extension = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
            $readerTypes = ['xlsx' => 'Xlsx', 'xls' => 'Xls', 'xlsm' => 'Xlsx'];
            if (!isset($readerTypes[$extension])) {
                throw new TablerosApiException('validation', 'Este tipo de archivo no tiene vista previa disponible.', 415);
            }
            if ((int)$file['byte_size'] > 25 * 1024 * 1024) {
                throw new TablerosApiException('validation', 'El archivo supera el límite de 25 MB para generar una vista previa.', 413);
            }

            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }

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
                $html = preg_replace('/<\/head>/i', "<style>html,body{background:#f3f4f6;color:#1f2937}body{margin:24px}table{background:#fff;box-shadow:0 1px 5px #0002}</style>\n</head>", $html, 1) ?? $html;
                $html = preg_replace('/<body[^>]*>/i', '$0<p style="font:14px sans-serif;color:#4b5563">La vista previa muestra hasta 20 hojas, 500 filas y 52 columnas por hoja.</p>', $html, 1) ?? $html;
            } finally {
                $spreadsheet->disconnectWorksheets();
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

    public function update_column_options(): void {
        $this->handle('POST', function (int $userId, array $input): array {
            $boardId = $this->bodyBoardId($input);
            $this->requireBoardRole($boardId, ['owner', 'designer']);
            return $this->model->updateColumnOptions($boardId, $userId, $input);
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
