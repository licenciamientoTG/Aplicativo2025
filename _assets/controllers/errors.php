<?php
class Errors{

    public function fail(): void {
        echo 'Error';
    }

    /** Peticiones AJAX / fetch con Accept json esperan JSON, no HTML. */
    public static function wantsJson(): bool {
        return (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest')
            || (stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false);
    }

    public static function render404(bool $json): string {
        if ($json) {
            return json_encode(['error' => 'Ruta no encontrada'], JSON_UNESCAPED_UNICODE);
        }
        return '<!doctype html><html lang="es"><head><meta charset="utf-8"><title>404 - Ruta no encontrada</title></head>'
            . '<body style="font-family:sans-serif;text-align:center;margin-top:15vh">'
            . '<h1>404</h1><p>Ruta no encontrada</p><p><a href="/">Volver al inicio</a></p></body></html>';
    }

    public function get404(): void {
        $json = self::wantsJson();
        http_response_code(404);
        header('Content-Type: ' . ($json ? 'application/json' : 'text/html') . '; charset=utf-8');
        echo self::render404($json);
        exit;
    }
}
