<?php
declare(strict_types=1);

/**
 * Herramienta de rutas.
 *
 *   php tools/routes.php list         tabla de rutas y alias
 *   php tools/routes.php lint         conflictos, handlers rotos y url('x') inexistentes (sale con 1 si hay problemas)
 *   php tools/routes.php legacy-hits  resumen de logs/legacy_routes.log (qué URLs viejas siguen en uso)
 */
chdir(dirname(__DIR__));
$_SERVER['REQUEST_URI'] ??= '/';
define('DS', DIRECTORY_SEPARATOR);
define('ROOT', getcwd() . DS);
define('ASSETS', ROOT . '_assets' . DS);
define('CLASSES', ASSETS . 'classes' . DS);
define('CONTROLLERS', ASSETS . 'controllers' . DS);
define('MODELS', ASSETS . 'models' . DS);

require ROOT . '_assets/classes/autoload.php';
require ROOT . '_assets/classes/Router.class.php';
Router::load(require ROOT . 'routes.php');

function files_in(string $dir, string $extension): array
{
    $found = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $name = $file->getFilename();
        if ($file->getExtension() === $extension && !str_ends_with($name, '.min.' . $extension)) {
            $found[] = $file->getPathname();
        }
    }
    return $found;
}

$command = $argv[1] ?? 'list';

switch ($command) {
    case 'list':
        printf("%-6s %-28s %-8s %-8s %s\n", 'TIPO', 'URL', 'VERBOS', 'PERMISO', 'NOMBRE -> HANDLER');
        foreach (Router::routes() as $r) {
            printf(
                "%-6s %-28s %-8s %-8s %s -> %s::%s\n",
                $r['is_alias'] ? 'alias' : 'ruta',
                '/' . $r['pattern'],
                $r['methods'] ? implode(',', $r['methods']) : '*',
                $r['permission'] ?? ($r['public'] ? 'pública' : '-'),
                $r['name'] ?? '-',
                $r['handler'][0],
                $r['handler'][1]
            );
        }
        break;

    case 'lint':
        $problems = Router::lint(true);
        $known = array_keys(Router::export());

        $scan = [
            ['views', 'html', 'inTwig'],
            ['views', 'html', 'inJs'],      // routeUrl() dentro de <script> en vistas (D14)
            ['_assets/js', 'js', 'inJs'],
        ];
        foreach ($scan as [$dir, $ext, $method]) {
            foreach (files_in(ROOT . $dir, $ext) as $path) {
                foreach (array_unique(RouteUsage::$method((string)file_get_contents($path))) as $name) {
                    if (!in_array($name, $known, true)) {
                        $problems[] = str_replace(ROOT, '', $path) . ": usa la ruta '$name' que no existe";
                    }
                }
            }
        }

        if ($problems) {
            echo implode("\n", $problems), "\n\n", count($problems), " problema(s)\n";
            exit(1);
        }
        echo count(Router::routes()), " rutas revisadas, sin problemas\n";
        break;

    case 'legacy-hits':
        $rows = RouteLog::summarize(ROOT . 'logs' . DS . 'legacy_routes.log');
        if (!$rows) {
            echo "Sin registros todavía en logs/legacy_routes.log\n";
            break;
        }
        printf("%-9s %-6s %6s  %-19s %s\n", 'TIPO', 'VERBO', 'VECES', 'ÚLTIMA', 'URL');
        foreach ($rows as $r) {
            printf("%-9s %-6s %6d  %-19s %s\n", $r['kind'], $r['verb'], $r['count'], $r['last'], $r['path']);
        }
        break;

    default:
        fwrite(STDERR, "Uso: php tools/routes.php list|lint|legacy-hits\n");
        exit(2);
}
