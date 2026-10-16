<?php
/**
 * Router estilo Django: rutas explícitas con parámetros tipados y nombres.
 *
 *   route('supply/payments/<int:id>/', [Supply::class, 'show'], name: 'supply.payment', methods: ['GET'])
 *
 * Convertidores: <int:x> (dígitos, se castea a int), <str:x> o <x> (sin "/"),
 * <slug:x> (letras, números, - y _), <path:x> (puede incluir "/").
 */
class Router {
    /** @var array<int,array> */
    private static array $routes = [];
    /** @var array<string,array> */
    private static array $named = [];

    private const CONVERTERS = [
        'int'  => '[0-9]+',
        'str'  => '[^/]+',
        'slug' => '[A-Za-z0-9_-]+',
        'path' => '.+',
    ];

    /** Vacía las rutas registradas (lo usan las pruebas). */
    public static function reset(): void {
        self::$routes = [];
        self::$named  = [];
    }

    /** Todas las definiciones registradas, alias incluidos (para herramientas). */
    public static function routes(): array {
        return self::$routes;
    }

    /** Rutas con nombre, listas para serializar a JSON y usar desde JS. */
    public static function export(): array {
        $out = [];
        foreach (self::$named as $name => $def) {
            $out[$name] = ['pattern' => $def['pattern'], 'params' => $def['params']];
        }
        return $out;
    }

    public static function load(array $definitions): void {
        foreach (self::flatten($definitions) as $def) {
            $def['regex'] = self::compile($def['pattern'], $def['params']);
            self::$routes[] = $def;
            if ($def['name'] !== null) {
                self::$named[$def['name']] = $def;
            }
        }
    }

    /**
     * Devuelve ['route' => def, 'args' => [...]] si hay coincidencia,
     * ['error' => 405, 'allowed' => [...]] si la ruta existe pero no el verbo,
     * o null si ninguna ruta coincide.
     */
    public static function match(string $path, string $verb): ?array {
        $path = self::normalize($path);
        $verb = strtoupper($verb);
        $allowed = [];

        foreach (self::$routes as $def) {
            if (!preg_match($def['regex'], $path, $m)) {
                continue;
            }
            if ($def['methods'] && !in_array($verb, $def['methods'], true)) {
                $allowed = array_merge($allowed, $def['methods']);
                continue;
            }
            $args = [];
            foreach ($def['params'] as $name => $type) {
                $args[] = $type === 'int' ? (int)$m[$name] : $m[$name];
            }
            return ['route' => $def, 'args' => $args];
        }

        return $allowed ? ['error' => 405, 'allowed' => array_values(array_unique($allowed))] : null;
    }

    /** Equivalente a reverse() de Django: url('supply.payment', ['id' => 15]) => /supply/payments/15 */
    public static function reverse(string $name, array $params = []): string {
        if (!isset(self::$named[$name])) {
            throw new InvalidArgumentException("Ruta no definida: '$name'");
        }
        $def = self::$named[$name];
        $url = preg_replace_callback('/<(?:(\w+):)?(\w+)>/', function ($m) use ($params, $def, $name) {
            $key = $m[2];
            if (!array_key_exists($key, $params)) {
                throw new InvalidArgumentException("Falta el parámetro '$key' para la ruta '$name'");
            }
            $value = (string)$params[$key];
            if (!preg_match('#^' . self::CONVERTERS[$def['params'][$key]] . '$#', $value)) {
                throw new InvalidArgumentException("Valor inválido '$value' para '$key' en la ruta '$name'");
            }
            return $def['params'][$key] === 'path' ? $value : rawurlencode($value);
        }, $def['pattern']);

        return '/' . self::normalize($url);
    }

    private static function normalize(string $path): string {
        return trim($path, '/');
    }

    private static function flatten(array $definitions, string $prefix = '', array $inherit = []): array {
        $out = [];
        foreach ($definitions as $def) {
            if (isset($def['children'])) {
                $out = array_merge($out, self::flatten(
                    $def['children'],
                    $prefix . $def['prefix'],
                    array_merge($inherit, $def['options'])
                ));
                continue;
            }
            // Las opciones del grupo rellenan solo lo que la ruta dejó en su valor por defecto.
            foreach ($inherit as $key => $value) {
                if ($def[$key] === null || $def[$key] === false || $def[$key] === []) {
                    $def[$key] = $key === 'methods' ? array_map('strtoupper', $value) : $value;
                }
            }
            $def['pattern']   = self::normalize($prefix . $def['pattern']);
            $def['params']    = self::paramTypes($def['pattern']);
            $def['is_alias']  = false;
            $def['canonical_params'] = array_keys($def['params']);
            $out[] = $def;

            // Cada alias es la misma ruta bajo una URL absoluta (normalmente la URL vieja).
            foreach ($def['alias'] as $aliasPattern) {
                $alias = $def;
                $alias['pattern']  = self::normalize($aliasPattern);
                $alias['params']   = self::paramTypes($alias['pattern']);
                $alias['name']     = null;
                $alias['alias']    = [];
                $alias['is_alias'] = true;
                $out[] = $alias;
            }
        }
        return $out;
    }

    private static function paramTypes(string $pattern): array {
        $types = [];
        preg_match_all('/<(?:(\w+):)?(\w+)>/', $pattern, $all, PREG_SET_ORDER);
        foreach ($all as $m) {
            $type = $m[1] !== '' ? $m[1] : 'str';
            if (!isset(self::CONVERTERS[$type])) {
                throw new InvalidArgumentException("Convertidor desconocido '$type' en '$pattern'");
            }
            $types[$m[2]] = $type;
        }
        return $types;
    }

    private static function compile(string $pattern, array $params): string {
        $parts = preg_split('/(<(?:\w+:)?\w+>)/', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE);
        $regex = '';
        foreach ($parts as $part) {
            if (preg_match('/^<(?:\w+:)?(\w+)>$/', $part, $m)) {
                $regex .= '(?P<' . $m[1] . '>' . self::CONVERTERS[$params[$m[1]]] . ')';
            } else {
                $regex .= preg_quote($part, '#');
            }
        }
        // Parte literal sin distinguir mayúsculas (la convención antigua pasaba la URL a minúsculas).
        return '#^' . $regex . '$#i';
    }
}

/**
 * Define una ruta. Opciones:
 *   name       nombre para url('nombre', [...])
 *   methods    verbos permitidos (vacío = todos)
 *   permission id de permiso requerido (authorized($id)); 403 si falta
 *   public     true = no requiere sesión
 *   alias      URLs viejas absolutas que apuntan a este mismo método (mismos parámetros)
 */
function route(string $pattern, array $handler, ?string $name = null, array $methods = [], ?int $permission = null, bool $public = false, array $alias = []): array {
    return [
        'pattern'    => $pattern,
        'handler'    => $handler,
        'name'       => $name,
        'methods'    => array_map('strtoupper', $methods),
        'permission' => $permission,
        'public'     => $public,
        'alias'      => $alias,
    ];
}

/** Agrupa rutas bajo un prefijo. Las opciones (permission, public, methods) se heredan. */
function group(string $prefix, array $routes, array $options = []): array {
    return ['prefix' => $prefix, 'children' => $routes, 'options' => $options];
}
