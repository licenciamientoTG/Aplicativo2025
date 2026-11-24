<?php
/**
 * Sitio de descarga de APKs (/deploy). Sin login de usuario: solo un código de acceso.
 * Los APK se suben a uploads/deploy/ (bloqueado por web.config; solo se sirven desde aquí).
 */
class Deploy {
    private const ACCESS_CODE = '5645';
    private const MAX_ATTEMPTS = 5;
    private const LOCK_SECONDS = 300;

    public $twig;
    private string $dir;

    public function __construct($twig) {
        $this->twig = $twig;
        $this->dir  = ROOT . 'uploads' . DS . 'deploy' . DS;
    }

    private function unlocked(): bool {
        return !empty($_SESSION['deploy_ok']);
    }

    public function index(): void {
        $error = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$this->unlocked()) {
            if (($_SESSION['deploy_lock_until'] ?? 0) > time()) {
                $error = 'Demasiados intentos. Espera unos minutos.';
            } elseif (hash_equals(self::ACCESS_CODE, trim((string)($_POST['code'] ?? '')))) {
                session_regenerate_id(true);
                $_SESSION['deploy_ok'] = true;
                $_SESSION['deploy_fails'] = 0;
                header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
                exit;
            } else {
                $_SESSION['deploy_fails'] = ($_SESSION['deploy_fails'] ?? 0) + 1;
                if ($_SESSION['deploy_fails'] >= self::MAX_ATTEMPTS) {
                    $_SESSION['deploy_lock_until'] = time() + self::LOCK_SECONDS;
                    $_SESSION['deploy_fails'] = 0;
                }
                $error = 'Código incorrecto.';
            }
        }

        $files = $this->unlocked() ? $this->listApks() : [];
        echo $this->twig->render('views/deploy/index.html', [
            'unlocked' => $this->unlocked(),
            'error'    => $error,
            'files'    => $files,
        ]);
    }

    public function download(string $file): void {
        if (!$this->unlocked()) {
            http_response_code(403);
            echo 'Acceso no autorizado';
            return;
        }
        $name = basename($file);
        $path = $this->dir . $name;
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'apk' || !is_file($path)) {
            http_response_code(404);
            echo 'Archivo no encontrado';
            return;
        }
        session_write_close();
        while (ob_get_level()) { ob_end_clean(); }
        header('Content-Type: application/vnd.android.package-archive');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        readfile($path);
    }

    public function logout(): void {
        unset($_SESSION['deploy_ok']);
        header('Location: ' . Router::reverse('deploy.index'));
        exit;
    }

    private function listApks(): array {
        $out = [];
        foreach (glob($this->dir . '*.apk') ?: [] as $f) {
            $out[] = [
                'name' => basename($f),
                'size' => round(filesize($f) / 1048576, 1),
                'date' => date('d/m/Y H:i', filemtime($f)),
                'ts'   => filemtime($f),
            ];
        }
        usort($out, fn($a, $b) => $b['ts'] <=> $a['ts']);
        return $out;
    }
}
