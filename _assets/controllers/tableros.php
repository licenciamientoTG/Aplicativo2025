<?php

class Tableros
{
    private $twig;
    private string $route = 'views/general/tableros/';
    private TablerosAccess $access;

    public function __construct($twig)
    {
        $this->twig = $twig;
        $this->access = new TablerosAccess();
    }

    public function index(): void
    {
        if (!$this->requireAccess()) {
            return;
        }
        $this->render(null);
    }

    public function board($id): void
    {
        if (!$this->requireAccess()) {
            return;
        }

        $boardId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$boardId) {
            $this->notFound();
            return;
        }
        if (!$this->access->canViewBoard((int)$boardId)) {
            $this->notFound();
            return;
        }
        $this->render((int)$boardId);
    }

    private function requireAccess(): bool
    {
        if ($this->access->hasGlobalPermission('access')) {
            return true;
        }
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'No tienes permiso para acceder a Tableros.';
        return false;
    }

    private function render(?int $boardId): void
    {
        if (empty($_SESSION['tableros_csrf'])) {
            $_SESSION['tableros_csrf'] = bin2hex(random_bytes(32));
        }
        echo $this->twig->render($this->route . 'index.html', [
            'csrf_token'       => $_SESSION['tableros_csrf'],
            'can_create'       => $this->access->hasGlobalPermission('create'),
            'can_admin'        => $this->access->hasGlobalPermission('admin'),
            'initial_board_id' => $boardId,
        ]);
    }

    private function notFound(): void
    {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Tablero no encontrado.';
    }
}
