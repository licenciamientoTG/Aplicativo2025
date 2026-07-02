# API DataStudio (Looker Studio) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Publicar una API JSON de solo lectura, en una carpeta raíz nueva `DataStudio/` totalmente separada del MVC existente, con un primer reporte de Ventas, lista para ser consumida por un Community Connector de Looker Studio vía internet.

**Architecture:** `DataStudio/index.php` es un mini front-controller propio (no usa `index.php`, controllers ni models de la app). Valida un API key por header, despacha a un archivo de reporte en `DataStudio/v1/reports/`, y envuelve la salida en un contrato JSON fijo (`{"schema": [...], "rows": [...]}` en éxito, `{"error": "..."}` en fallo). Se agrega una regla de rewrite acotada solo a `/DataStudio/*` en `web.config` (y su equivalente en `router.php` para desarrollo local) para exponer URLs amigables (`/DataStudio/v1/ventas`) sin alterar el ruteo del resto del sitio.

**Tech Stack:** PHP 8, PDO driver `sqlsrv`, SQL Server (`SG12.dbo.Ventas`, `SG12.dbo.Islas`, `SG12.dbo.Gasolineras`), reutiliza `MySqlPdoHandler` (`_assets/classes/common/MySqlPdoHandler.class.php`) como única dependencia del resto del código de la app. Sin framework de tests — verificación con `php -l` y `curl` contra el servidor embebido de PHP.

## Global Constraints

- Separación total: ningún archivo dentro de `_assets/`, `views/` ni ningún controller/model existente se modifica. Todo el código nuevo vive en `DataStudio/`. Spec: `docs/superpowers/specs/2026-07-01-datastudio-api-design.md`.
- Los únicos cambios fuera de `DataStudio/` son `web.config` (una regla nueva, acotada a `/DataStudio/*`, colocada antes de la regla catch-all existente) y `router.php` (una rama nueva, acotada al mismo prefijo). Ninguno de los dos altera el comportamiento de rutas ya existentes.
- Autenticación vía header `X-Api-Key` comparado con la constante `DATASTUDIO_API_KEY` definida en `DataStudio/_bootstrap.php` (mismo patrón que `CRON_SECRET` en `_assets/classes/header.class.php:43`).
- Contrato de respuesta fijo en todo el API: éxito → `{"schema": [...], "rows": [...]}`; error → `{"error": "..."}` con código HTTP apropiado. Nunca un fatal error de PHP crudo.
- Las consultas a BD usan `MySqlPdoHandler::selectSafe()`, **no** `MySqlPdoHandler::select()` — `select()` hace `echo` + `die()` crudo ante cualquier error SQL (confirmado en `_assets/classes/common/MySqlPdoHandler.class.php:61-89`), lo que rompería el contrato JSON. `selectSafe()` registra el error con `error_log()` y devuelve `false`, permitiendo traducirlo a un error JSON controlado.
- El Community Connector (Apps Script del lado de Google) está fuera de alcance de este plan — solo se construye la API que ese connector va a consumir.

---

## File Structure

- **Create:** `DataStudio/_bootstrap.php` — define `DATASTUDIO_API_KEY`, conecta `MySqlPdoHandler` a `TG`. Único punto que toca algo de `_assets/` (un `require` directo al archivo de la clase, sin pasar por `header.class.php` ni el autoloader de la app).
- **Create:** `DataStudio/lib/ApiKeyAuth.php` — clase `ApiKeyAuth` con `check(): bool`.
- **Create:** `DataStudio/lib/JsonResponse.php` — clase `JsonResponse` con `success(array $schema, array $rows): void` y `error(string $message, int $httpCode): void`.
- **Create:** `DataStudio/index.php` — front-controller: auth, resolución de `version`/`report` desde querystring, dispatch, envelope, manejo de errores.
- **Create:** `DataStudio/v1/reports/ventas.php` — primer reporte: ventas por estación/fecha/producto, con filtros `desde`/`hasta`.
- **Modify:** `web.config` — nueva regla de rewrite acotada a `/DataStudio/*`.
- **Modify:** `router.php` — nueva rama acotada a `/DataStudio/*` para desarrollo local.

---

### Task 1: Bootstrap y helpers (`_bootstrap.php`, `ApiKeyAuth`, `JsonResponse`)

**Files:**
- Create: `DataStudio/_bootstrap.php`
- Create: `DataStudio/lib/ApiKeyAuth.php`
- Create: `DataStudio/lib/JsonResponse.php`

**Interfaces:**
- Produces: constante `DATASTUDIO_API_KEY` (string), disponible tras incluir `_bootstrap.php`.
- Produces: `MySqlPdoHandler::getInstance()` ya conectado a `TG` tras incluir `_bootstrap.php` (mismo singleton que usa el resto de la app).
- Produces: `ApiKeyAuth::check(): bool` — compara `$_SERVER['HTTP_X_API_KEY']` contra `DATASTUDIO_API_KEY` con `hash_equals()`.
- Produces: `JsonResponse::success(array $schema, array $rows): void` y `JsonResponse::error(string $message, int $httpCode): void` — ambas hacen `echo json_encode(...)` con `Content-Type: application/json; charset=utf-8` y el `http_response_code` correspondiente.

- [ ] **Step 1: Crear la carpeta y el bootstrap**

```bash
mkdir -p DataStudio/lib DataStudio/v1/reports
```

Crear `DataStudio/_bootstrap.php`:

```php
<?php
// Bootstrap standalone para la API de DataStudio. No depende de
// _assets/classes/header.class.php ni del autoloader de la app: solo
// carga la clase de conexión a BD directamente.

date_default_timezone_set('America/Mazatlan');

define('DATASTUDIO_API_KEY', 'TG_DATASTUDIO_2026_Hf83Kx01Qz');

require dirname(__DIR__) . '/_assets/classes/common/MySqlPdoHandler.class.php';

MySqlPdoHandler::getInstance()->connect('TG');
```

- [ ] **Step 2: Crear `ApiKeyAuth`**

Crear `DataStudio/lib/ApiKeyAuth.php`:

```php
<?php
class ApiKeyAuth
{
    public static function check(): bool
    {
        $provided = $_SERVER['HTTP_X_API_KEY'] ?? '';
        return $provided !== '' && hash_equals(DATASTUDIO_API_KEY, $provided);
    }
}
```

- [ ] **Step 3: Crear `JsonResponse`**

Crear `DataStudio/lib/JsonResponse.php`:

```php
<?php
class JsonResponse
{
    public static function success(array $schema, array $rows): void
    {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(200);
        echo json_encode(['schema' => $schema, 'rows' => $rows], JSON_UNESCAPED_UNICODE);
    }

    public static function error(string $message, int $httpCode): void
    {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($httpCode);
        echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    }
}
```

- [ ] **Step 4: Verificar sintaxis PHP de los tres archivos**

```bash
php -l DataStudio/_bootstrap.php
php -l DataStudio/lib/ApiKeyAuth.php
php -l DataStudio/lib/JsonResponse.php
```

Expected: `No syntax errors detected` para los tres.

- [ ] **Step 5: Verificar `ApiKeyAuth::check()` de forma aislada**

```bash
php -r '
define("DATASTUDIO_API_KEY", "secreto-de-prueba");
require "DataStudio/lib/ApiKeyAuth.php";
$_SERVER["HTTP_X_API_KEY"] = "secreto-de-prueba";
var_dump(ApiKeyAuth::check()); // true
$_SERVER["HTTP_X_API_KEY"] = "incorrecto";
var_dump(ApiKeyAuth::check()); // false
unset($_SERVER["HTTP_X_API_KEY"]);
var_dump(ApiKeyAuth::check()); // false
'
```

Expected: `bool(true)`, `bool(false)`, `bool(false)` en ese orden.

- [ ] **Step 6: Commit**

```bash
git add DataStudio/_bootstrap.php DataStudio/lib/ApiKeyAuth.php DataStudio/lib/JsonResponse.php
git commit -m "Agregar bootstrap y helpers base de la API DataStudio"
```

---

### Task 2: Front-controller `DataStudio/index.php`

**Files:**
- Create: `DataStudio/index.php`

**Interfaces:**
- Consumes: `DATASTUDIO_API_KEY`, `MySqlPdoHandler::getInstance()` (Task 1, vía `_bootstrap.php`), `ApiKeyAuth::check(): bool` (Task 1), `JsonResponse::success()`/`JsonResponse::error()` (Task 1).
- Produces: contrato de dispatch — `GET DataStudio/index.php?version={v}&report={r}` busca `DataStudio/{v}/reports/{r}.php`, lo incluye con `require` esperando que retorne `['schema' => array, 'rows' => array]`, y lo envuelve en la respuesta JSON. Este contrato es lo que la Task 3 (y cualquier reporte futuro) debe cumplir.

- [ ] **Step 1: Crear el front-controller**

Crear `DataStudio/index.php`:

```php
<?php
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/lib/ApiKeyAuth.php';
require __DIR__ . '/lib/JsonResponse.php';

if (!ApiKeyAuth::check()) {
    JsonResponse::error('No autorizado', 401);
    exit;
}

$version = $_GET['version'] ?? '';
$report  = $_GET['report'] ?? '';

if (!is_string($version) || !is_string($report)) {
    JsonResponse::error('Parámetros version y report son requeridos', 400);
    exit;
}

$version = preg_replace('/[^a-z0-9]/', '', strtolower($version));
$report  = preg_replace('/[^a-z0-9_]/', '', strtolower($report));

if ($version === '' || $report === '') {
    JsonResponse::error('Parámetros version y report son requeridos', 400);
    exit;
}

$reportFile = __DIR__ . "/{$version}/reports/{$report}.php";

if (!is_file($reportFile)) {
    JsonResponse::error("Reporte no encontrado: {$version}/{$report}", 404);
    exit;
}

try {
    $result = require $reportFile;

    if (!is_array($result) || !isset($result['schema'], $result['rows'])) {
        throw new RuntimeException("El reporte {$report} no devolvió schema/rows válidos");
    }

    JsonResponse::success($result['schema'], $result['rows']);
} catch (Throwable $e) {
    error_log('[DataStudio] ' . $e->getMessage());
    JsonResponse::error('Error interno al generar el reporte', 500);
}
```

- [ ] **Step 2: Verificar sintaxis PHP**

```bash
php -l DataStudio/index.php
```

Expected: `No syntax errors detected in DataStudio/index.php`

- [ ] **Step 3: Levantar el servidor embebido y probar los casos que no requieren ningún reporte real**

En una terminal:

```bash
php -S localhost:8000 router.php
```

En otra terminal:

```bash
curl -s -o /dev/null -w "%{http_code}\n" "http://localhost:8000/DataStudio/index.php?version=v1&report=ventas"
```
Expected: `401` (sin header `X-Api-Key`).

```bash
curl -s "http://localhost:8000/DataStudio/index.php?version=v1&report=ventas" -H "X-Api-Key: TG_DATASTUDIO_2026_Hf83Kx01Qz"
```
Expected (sin que exista aún `v1/reports/ventas.php`): `{"error":"Reporte no encontrado: v1\/ventas"}` con código `404`. Verificar el código con:

```bash
curl -s -o /dev/null -w "%{http_code}\n" "http://localhost:8000/DataStudio/index.php?version=v1&report=ventas" -H "X-Api-Key: TG_DATASTUDIO_2026_Hf83Kx01Qz"
```
Expected: `404`.

```bash
curl -s -w "\n%{http_code}\n" "http://localhost:8000/DataStudio/index.php" -H "X-Api-Key: TG_DATASTUDIO_2026_Hf83Kx01Qz"
```
Expected: `{"error":"Parámetros version y report son requeridos"}` seguido de `400`.

Detener el servidor embebido (`Ctrl+C` en la terminal donde corre).

- [ ] **Step 4: Commit**

```bash
git add DataStudio/index.php
git commit -m "Agregar front-controller de DataStudio con auth, dispatch y envelope JSON"
```

---

### Task 3: Primer reporte — Ventas

**Files:**
- Create: `DataStudio/v1/reports/ventas.php`

**Interfaces:**
- Consumes: `MySqlPdoHandler::getInstance()->selectSafe(string $query, array $params): array|false` (ya conectado a `TG` por `_bootstrap.php`, Task 1).
- Produces: cumple el contrato de la Task 2 — retorna `['schema' => [...], 'rows' => [...]]`.

Consulta basada en la lógica ya existente y verificada de `_assets/models/VentasModel.php::get_sales()` (líneas 16-94) y `get_month_sales()` (líneas 367-393): la tabla `[SG12].[dbo].[Ventas]` guarda la fecha en `fch` como días transcurridos desde `1900-01-01` (`DATEADD(DAY, fch - 1, '19000101')` reconstruye la fecha real; `DATEDIFF(dd, 0, '<fecha>') + 1` hace la conversión inversa), y los códigos de producto se agrupan así: Diesel = `(1, 181)`, Magna = `(2, 179, 192)`, Premium = `(3, 180, 193)`. A diferencia de `get_sales()` (que arma una tabla `PIVOT` con una columna por estación, pensada para una vista HTML), este reporte devuelve filas planas (una fila por estación/fecha/producto), que es el formato que Looker Studio espera.

- [ ] **Step 1: Crear el reporte**

Crear `DataStudio/v1/reports/ventas.php`:

```php
<?php
$desde = $_GET['desde'] ?? date('Y-m-01');
$hasta = $_GET['hasta'] ?? date('Y-m-d');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) {
    throw new InvalidArgumentException('Los parámetros desde/hasta deben tener formato YYYY-MM-DD');
}

$query = "
    SELECT
        g.abr AS estacion,
        CONVERT(varchar(10), DATEADD(DAY, v.fch - 1, '19000101'), 23) AS fecha,
        CASE
            WHEN v.codprd IN (1, 181) THEN 'Diesel Automotriz'
            WHEN v.codprd IN (2, 179, 192) THEN 'T-Maxima Regular'
            WHEN v.codprd IN (3, 180, 193) THEN 'T-Super Premium'
        END AS producto,
        SUM(v.canven) AS litros_vendidos
    FROM [SG12].[dbo].[Ventas] v
    INNER JOIN [SG12].[dbo].[Islas] isd ON v.codisl = isd.cod
    INNER JOIN [SG12].[dbo].[Gasolineras] g ON isd.codgas = g.cod
    WHERE
        v.fch BETWEEN (DATEDIFF(dd, 0, ?) + 1) AND (DATEDIFF(dd, 0, ?) + 1)
        AND v.codprd IN (1, 2, 3, 179, 180, 181, 192, 193)
    GROUP BY
        g.abr,
        DATEADD(DAY, v.fch - 1, '19000101'),
        CASE
            WHEN v.codprd IN (1, 181) THEN 'Diesel Automotriz'
            WHEN v.codprd IN (2, 179, 192) THEN 'T-Maxima Regular'
            WHEN v.codprd IN (3, 180, 193) THEN 'T-Super Premium'
        END
    ORDER BY fecha, estacion
";

$rows = MySqlPdoHandler::getInstance()->selectSafe($query, [$desde, $hasta]);

if ($rows === false) {
    throw new RuntimeException('Error al consultar ventas');
}

return [
    'schema' => [
        ['name' => 'estacion', 'dataType' => 'STRING'],
        ['name' => 'fecha', 'dataType' => 'DATE'],
        ['name' => 'producto', 'dataType' => 'STRING'],
        ['name' => 'litros_vendidos', 'dataType' => 'NUMBER'],
    ],
    'rows' => $rows,
];
```

- [ ] **Step 2: Verificar sintaxis PHP**

```bash
php -l DataStudio/v1/reports/ventas.php
```

Expected: `No syntax errors detected in DataStudio/v1/reports/ventas.php`

- [ ] **Step 3: Probar el reporte completo contra la base de datos real**

Requiere conectividad a `192.168.0.6` (host de `TG`/`SG12` según `CLAUDE.md`). Si el entorno donde se ejecuta este paso no tiene esa conectividad (p.ej. una máquina de desarrollo fuera de la red interna), documentar el resultado obtenido y dejar esta verificación pendiente para el entorno de despliegue (se retoma en la Task 5).

```bash
php -S localhost:8000 router.php &
sleep 1
curl -s "http://localhost:8000/DataStudio/index.php?version=v1&report=ventas&desde=2026-06-01&hasta=2026-06-30" -H "X-Api-Key: TG_DATASTUDIO_2026_Hf83Kx01Qz"
kill %1
```

Expected: JSON con código `200`, cuerpo con la forma `{"schema":[{"name":"estacion","dataType":"STRING"},...],"rows":[{"estacion":"...","fecha":"2026-06-...","producto":"...","litros_vendidos":...}, ...]}`. Si no hay ventas en ese rango, `"rows":[]` es una respuesta válida (no un error).

- [ ] **Step 4: Probar el manejo de fecha inválida**

```bash
curl -s -w "\n%{http_code}\n" "http://localhost:8000/DataStudio/index.php?version=v1&report=ventas&desde=no-es-fecha" -H "X-Api-Key: TG_DATASTUDIO_2026_Hf83Kx01Qz"
```
Expected: `{"error":"Error interno al generar el reporte"}` con código `500` (el `InvalidArgumentException` del reporte es capturado por el `try/catch` de `index.php` y traducido al envelope de error, sin exponer el detalle interno).

- [ ] **Step 5: Commit**

```bash
git add DataStudio/v1/reports/ventas.php
git commit -m "Agregar primer reporte de DataStudio: ventas por estacion/fecha/producto"
```

---

### Task 4: URLs amigables (`web.config` + `router.php`)

**Files:**
- Modify: `web.config`
- Modify: `router.php`

**Interfaces:**
- Consumes: `DataStudio/index.php` (Task 2) tal como está — este task solo agrega una forma alternativa de invocarlo, sin cambiar su contrato de `$_GET['version']`/`$_GET['report']`.

- [ ] **Step 1: Agregar la regla de rewrite en `web.config`**

Reemplazar el bloque `<rules>` actual (líneas 5-14 de `web.config`):

```xml
      <rules>
        <rule name="Rewrite to index.php" stopProcessing="true">
          <match url="^(.*)$" />
          <conditions logicalGrouping="MatchAll">
            <add input="{REQUEST_FILENAME}" matchType="IsFile" negate="true" />
            <add input="{REQUEST_FILENAME}" matchType="IsDirectory" negate="true" />
          </conditions>
          <action type="Rewrite" url="index.php?url={R:1}" />
        </rule>
      </rules>
```

por:

```xml
      <rules>
        <rule name="DataStudio API rewrite" stopProcessing="true">
          <match url="^DataStudio/([^/]+)/([^/]+)$" />
          <conditions logicalGrouping="MatchAll">
            <add input="{REQUEST_FILENAME}" matchType="IsFile" negate="true" />
          </conditions>
          <action type="Rewrite" url="DataStudio/index.php?version={R:1}&amp;report={R:2}" />
        </rule>
        <rule name="Rewrite to index.php" stopProcessing="true">
          <match url="^(.*)$" />
          <conditions logicalGrouping="MatchAll">
            <add input="{REQUEST_FILENAME}" matchType="IsFile" negate="true" />
            <add input="{REQUEST_FILENAME}" matchType="IsDirectory" negate="true" />
          </conditions>
          <action type="Rewrite" url="index.php?url={R:1}" />
        </rule>
      </rules>
```

La regla nueva va **antes** de la regla catch-all (IIS evalúa en orden y ambas tienen `stopProcessing="true"`, así que si la catch-all fuera primero, capturaría también las rutas de `/DataStudio/...`).

- [ ] **Step 2: Agregar la rama equivalente en `router.php` (para `php -S`)**

Reemplazar el contenido completo de `router.php`:

```php
<?php
// router.php

// Ruta solicitada (sin query string)
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Si el archivo existe físicamente (CSS, JS, imágenes, etc.), que lo sirva el servidor embebido
if ($path !== '/' && file_exists(__DIR__ . $path)) {
    return false;
}

// URLs amigables de DataStudio: /DataStudio/{version}/{report} -> DataStudio/index.php?version=&report=
if (preg_match('#^/DataStudio/([^/]+)/([^/]+)$#', $path, $m)) {
    $_GET['version'] = $m[1];
    $_GET['report']  = $m[2];
    require __DIR__ . '/DataStudio/index.php';
    return;
}

// Simular lo que hacía web.config: index.php?url={R:1}
$_GET['url'] = ltrim($path, '/'); // ejemplo: "/administration/doc_agujita" -> "administration/doc_agujita"

// Cargar tu front controller
require __DIR__ . '/index.php';
```

- [ ] **Step 3: Verificar sintaxis PHP de `router.php`**

```bash
php -l router.php
```

Expected: `No syntax errors detected in router.php`

- [ ] **Step 4: Probar la URL amigable y compararla con la URL por querystring**

```bash
php -S localhost:8000 router.php &
sleep 1
curl -s "http://localhost:8000/DataStudio/v1/ventas?desde=2026-06-01&hasta=2026-06-30" -H "X-Api-Key: TG_DATASTUDIO_2026_Hf83Kx01Qz" > /tmp/friendly.json
curl -s "http://localhost:8000/DataStudio/index.php?version=v1&report=ventas&desde=2026-06-01&hasta=2026-06-30" -H "X-Api-Key: TG_DATASTUDIO_2026_Hf83Kx01Qz" > /tmp/querystring.json
diff /tmp/friendly.json /tmp/querystring.json
kill %1
```

Expected: `diff` no muestra diferencias (mismo JSON en ambas formas de invocar la API).

- [ ] **Step 5: Confirmar que las rutas existentes de la app no se rompieron**

```bash
php -S localhost:8000 router.php &
sleep 1
curl -s -o /dev/null -w "%{http_code}\n" "http://localhost:8000/"
kill %1
```

Expected: mismo código de respuesta que antes de este cambio (típicamente `200` mostrando el login, ya que no hay sesión activa) — confirma que la rama nueva de `router.php` no interfiere con el ruteo normal.

- [ ] **Step 6: Commit**

```bash
git add web.config router.php
git commit -m "Agregar URLs amigables para DataStudio (web.config + router.php)"
```

---

### Task 5: Verificación manual end-to-end

**Files:** ninguno (solo pruebas manuales contra la app corriendo).

**Interfaces:** ninguna nueva — valida el comportamiento conjunto de las Tasks 1-4.

- [ ] **Step 1: Matriz completa de casos, en local (`php -S`)**

```bash
php -S localhost:8000 router.php &
sleep 1

echo "Sin API key:"
curl -s -o /dev/null -w "%{http_code}\n" "http://localhost:8000/DataStudio/v1/ventas"

echo "API key incorrecta:"
curl -s -o /dev/null -w "%{http_code}\n" "http://localhost:8000/DataStudio/v1/ventas" -H "X-Api-Key: incorrecta"

echo "Reporte inexistente:"
curl -s -w "\n%{http_code}\n" "http://localhost:8000/DataStudio/index.php?version=v1&report=noexiste" -H "X-Api-Key: TG_DATASTUDIO_2026_Hf83Kx01Qz"

echo "Happy path, URL amigable:"
curl -s -w "\n%{http_code}\n" "http://localhost:8000/DataStudio/v1/ventas?desde=2026-06-01&hasta=2026-06-30" -H "X-Api-Key: TG_DATASTUDIO_2026_Hf83Kx01Qz"

kill %1
```

Expected:
- Sin API key → `401`.
- API key incorrecta → `401`.
- Reporte inexistente → `404` con `{"error":"Reporte no encontrado: v1\/noexiste"}`.
- Happy path → `200` con `schema`/`rows` poblados (o `rows: []` si no hubo ventas en el rango, lo cual es válido).

- [ ] **Step 2: Confirmar accesibilidad pública una vez desplegado en el servidor de producción**

Una vez subidos estos archivos al servidor donde ya vive el aplicativo (mismo dominio/IIS que ya es accesible desde internet, según confirmó el usuario), probar desde **fuera de la red interna** (por ejemplo, con datos móviles en vez de wifi de oficina):

```bash
curl -s -w "\n%{http_code}\n" "https://<dominio-publico>/DataStudio/v1/ventas?desde=2026-06-01&hasta=2026-06-30" -H "X-Api-Key: TG_DATASTUDIO_2026_Hf83Kx01Qz"
```

Expected: mismo `200` con `schema`/`rows` que en local — confirma que la API es alcanzable desde internet, que es el requisito para que el Community Connector de Looker Studio (que corre en servidores de Google) pueda consumirla.

- [ ] **Step 3: Confirmar que no hay regresión en el resto del sitio**

Navegar manualmente a 2-3 rutas existentes de la app (ej. login, algún módulo ya usado) y confirmar que funcionan igual que antes de este cambio — el único riesgo de regresión son `web.config`/`router.php`, ya cubiertos en la Task 4, pero vale la pena una confirmación visual final.
