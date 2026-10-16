<?php
/**
 * Registro de peticiones que NO llegaron por una ruta nueva:
 *   alias     entraron por una URL vieja declarada como alias en routes/
 *   fallback  entraron por la convención antigua /controlador/método
 * Sirve para saber cuándo una URL vieja ya no se usa y puede retirarse.
 *
 * Formato (una línea por petición, separado por tabuladores):
 *   2026-10-16 09:32:01<TAB>fallback<TAB>GET<TAB>/income/list
 */
class RouteLog {
    private const MAX_PATH = 300;

    public static function write(string $file, string $kind, string $verb, string $path, ?string $when = null): void {
        // Sin caracteres de control: un path con tabuladores o saltos de línea no puede fabricar líneas falsas.
        $path = preg_replace('/[\x00-\x1f\x7f]/', '', $path);
        $path = '/' . ltrim(substr($path, 0, self::MAX_PATH), '/');

        $line = implode("\t", [$when ?? date('Y-m-d H:i:s'), $kind, strtoupper($verb), $path]) . "\n";

        // El log nunca debe romper la petición (carpeta inexistente, sin permisos, disco lleno…).
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    /** @return array<int,array{kind:string,verb:string,path:string,count:int,last:string}> */
    public static function summarize(string $file): array {
        if (!is_file($file)) {
            return [];
        }

        $groups = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $cols = explode("\t", $line);
            if (count($cols) !== 4) {
                continue;
            }
            [$when, $kind, $verb, $path] = $cols;
            $key = "$kind\t$verb\t$path";
            if (!isset($groups[$key])) {
                $groups[$key] = ['kind' => $kind, 'verb' => $verb, 'path' => $path, 'count' => 0, 'last' => $when];
            }
            $groups[$key]['count']++;
            if ($when > $groups[$key]['last']) {
                $groups[$key]['last'] = $when;
            }
        }

        $out = array_values($groups);
        usort($out, fn($a, $b) => $b['count'] <=> $a['count'] ?: strcmp($a['path'], $b['path']));
        return $out;
    }
}
