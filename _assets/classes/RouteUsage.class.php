<?php
/**
 * Encuentra qué rutas con nombre usan las vistas y el JS, para que el linter
 * detecte nombres que no existen antes de que fallen en producción.
 */
class RouteUsage {
    /** url('nombre') dentro de {{ ... }} o {% ... %} (así no se confunde con url() de CSS). */
    public static function inTwig(string $source): array {
        preg_match_all('/\{[{%][^}]*?\burl\(\s*[\'"]([\w.\-]+)[\'"]/', $source, $m);
        return $m[1];
    }

    /** routeUrl('nombre') en archivos JS. */
    public static function inJs(string $source): array {
        preg_match_all('/\brouteUrl\(\s*[\'"]([\w.\-]+)[\'"]/', $source, $m);
        return $m[1];
    }
}
