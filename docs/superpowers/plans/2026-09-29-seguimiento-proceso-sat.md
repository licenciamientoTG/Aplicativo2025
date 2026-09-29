# Seguimiento del proceso SAT — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Panel de salud del pipeline de descarga masiva SAT en `/it/llamadas_sat` con botones para Descargar, Descomprimir e Importar, y que los XML que fallan dejen de atorar la importación.

**Architecture:** ApiTotal (Laravel, vive junto a las carpetas en 192.168.0.3) expone `pipeline_status` (conteos por carpeta) y mueve los XML fallidos a `cfdis_error`; la lógica de archivos vive en un servicio nuevo `SatPipelineDirs` con pruebas PHPUnit. AplicativoPhp combina ese estado con datos de BD (`SatPeticionesModel`), calcula semáforos y proxea las acciones a endpoints existentes de ApiTotal; la vista repite lotes de importación desde el navegador.

**Tech Stack:** Laravel 11 + PHPUnit 11 (ApiTotal); PHP MVC propio + Twig 3 + Bootstrap 5 + jQuery + SweetAlert2 (AplicativoPhp); SQL Server (TGV2).

**Spec:** `docs/superpowers/specs/2026-09-29-seguimiento-proceso-sat-design.md`

## Global Constraints

- ApiTotal: `C:\Users\alejandro.martinez\Desktop\codigo\ApiTotal`. **No se despliega desde aquí**: el usuario sube los archivos por FTP. Al terminar, listar los archivos de ApiTotal tocados.
- AplicativoPhp se trabaja **en el working tree actual de `main`**, sin worktree: `views/it/llamadas_sat.html`, `views/it/modals/llamadas_sat_errores.html` y `_assets/models/SatPeticionesModel.php` aún no están en git y un worktree no los tendría.
- **Commits solo con autorización del usuario** (regla del usuario). Los pasos "Commit" son checkpoints: preguntar antes de ejecutarlos. Nunca incluir en un commit archivos ajenos (`views/layouts/sidebar.html` tiene cambios del usuario).
- No levantar ni reiniciar el servidor PHP de desarrollo (lo gestiona el usuario; corre en `localhost:8001`).
- No editar archivos con PowerShell `Get-Content/Set-Content` (rompe UTF-8); usar Edit/Write.
- Rutas de carpetas (verbatim del servidor): `C:/tareasprogramadas/invoice/invoice_new_emited/`, `…/invoice_new_received/`, `…/cfdis_emited/`, `…/cfdis_received/`, `…/cfdis_processed/`, **nueva** `…/cfdis_error/{emited|received}/`.
- Umbrales de semáforo (spec): tarjeta 1 rojo > 3 días, amarillo > 1 día; tarjetas 2 y 3 rojo si el más viejo > 1 día, amarillo si hay archivos (tarjeta 3 también si hay en `cfdis_error`); tarjeta 4 rojo > 3 días, amarillo > 1 día.
- Tarjeta 4 = `MAX(Fecha)` de los últimos 5,000 `Id` de `TGV2.dbo.Facturas` y de `TGV2.dbo.FacturasRecibidas`.
- Permiso de todas las acciones nuevas: `self::SAT_USERS` (igual que el resto de Llamadas SAT).
- Proxys de acciones: `set_time_limit(0)` y timeout de curl 600 s.

## Review Focus

1. **ApiTotal sin subir (404 en `pipeline_status`)** → el panel no truena: tarjetas 2 y 3 dicen "Falta subir ApiTotal (pipeline_status)", 1 y 4 funcionan. Prueba en Task 5 (Step 3).
2. **Lote de importación sin avance** (todos fallan con el `process_xml` viejo, o `rename` falla) → el ciclo del navegador se detiene y muestra errores, no queda en loop infinito. Prueba en Task 6 (Step 5).
3. **Carpeta inexistente** (`cfdis_error` aún no creada, o ruta mal) → `resumenCarpeta` regresa `existe=false, cantidad=0` sin excepción. Prueba en Task 1.
4. **Nombre de archivo repetido al mover a `cfdis_error`** (el mismo UUID falla dos veces tras regresarlo a mano) → no se pierde el archivo previo; se agrega sufijo. Prueba en Task 1.
5. **Descargar en un RFC sin pendientes** (ApiTotal responde 404 "No hay peticiones pendientes") → se muestra como aviso informativo, no como error rojo. Prueba en Task 6 (Step 4).

---

### Task 1: Servicio de carpetas del pipeline (ApiTotal)

**Files:**
- Create: `ApiTotal/app/Services/SatPipelineDirs.php`
- Test: `ApiTotal/tests/Unit/SatPipelineDirsTest.php`

**Interfaces:**
- Produces:
  - `new SatPipelineDirs(string $raiz = 'C:/tareasprogramadas/invoice/')`
  - `const CARPETAS = ['zip_emited' => 'invoice_new_emited', 'zip_received' => 'invoice_new_received', 'xml_emited' => 'cfdis_emited', 'xml_received' => 'cfdis_received', 'error_emited' => 'cfdis_error/emited', 'error_received' => 'cfdis_error/received']`
  - `ruta(string $clave): string` — ruta absoluta con `/` final.
  - `resumenCarpeta(string $ruta, string $patron = '*'): array` → `['existe' => bool, 'cantidad' => int, 'mas_viejo' => ?string 'Y-m-d H:i:s', 'mas_reciente' => ?string]`
  - `estado(): array` → `['generado' => 'Y-m-d H:i:s', 'carpetas' => [clave => resumenCarpeta + 'ruta']]` (patrón `*.zip` para zip_*, `*.xml` para el resto).
  - `moverAError(string $archivo, string $type): ?string` — `$type` `'emited'|'received'`; crea la carpeta si falta; si ya existe un archivo con ese nombre agrega sufijo `-2`, `-3`…; regresa la ruta destino o `null` si `rename` falla.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit;

use App\Services\SatPipelineDirs;
use PHPUnit\Framework\TestCase;

class SatPipelineDirsTest extends TestCase
{
    private string $raiz;

    protected function setUp(): void
    {
        $this->raiz = sys_get_temp_dir() . '/satpipe_' . uniqid() . '/';
        mkdir($this->raiz . 'cfdis_emited', 0777, true);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->raiz, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) { $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
        rmdir($this->raiz);
    }

    public function test_carpeta_inexistente_no_truena(): void
    {
        $r = (new SatPipelineDirs($this->raiz))->resumenCarpeta($this->raiz . 'no_existe/');
        $this->assertSame(['existe' => false, 'cantidad' => 0, 'mas_viejo' => null, 'mas_reciente' => null], $r);
    }

    public function test_cuenta_y_fechas_extremas(): void
    {
        $d = $this->raiz . 'cfdis_emited/';
        file_put_contents($d . 'a.xml', 'x'); touch($d . 'a.xml', strtotime('2026-09-01 10:00:00'));
        file_put_contents($d . 'b.xml', 'x'); touch($d . 'b.xml', strtotime('2026-09-20 08:30:00'));
        file_put_contents($d . 'nota.txt', 'x');

        $r = (new SatPipelineDirs($this->raiz))->resumenCarpeta($d, '*.xml');

        $this->assertTrue($r['existe']);
        $this->assertSame(2, $r['cantidad']);
        $this->assertSame('2026-09-01 10:00:00', $r['mas_viejo']);
        $this->assertSame('2026-09-20 08:30:00', $r['mas_reciente']);
    }

    public function test_estado_incluye_las_seis_carpetas(): void
    {
        $e = (new SatPipelineDirs($this->raiz))->estado();
        $this->assertSame(array_keys(SatPipelineDirs::CARPETAS), array_keys($e['carpetas']));
        $this->assertTrue($e['carpetas']['xml_emited']['existe']);
        $this->assertFalse($e['carpetas']['error_emited']['existe']);
    }

    public function test_mover_a_error_crea_carpeta_y_no_pisa_repetidos(): void
    {
        $dirs = new SatPipelineDirs($this->raiz);
        $origen = $this->raiz . 'cfdis_emited/';

        file_put_contents($origen . 'U1.xml', 'primero');
        $destino1 = $dirs->moverAError($origen . 'U1.xml', 'emited');

        file_put_contents($origen . 'U1.xml', 'segundo');
        $destino2 = $dirs->moverAError($origen . 'U1.xml', 'emited');

        $this->assertSame($dirs->ruta('error_emited') . 'U1.xml', $destino1);
        $this->assertSame($dirs->ruta('error_emited') . 'U1-2.xml', $destino2);
        $this->assertSame('primero', file_get_contents($destino1));
        $this->assertSame('segundo', file_get_contents($destino2));
        $this->assertFileDoesNotExist($origen . 'U1.xml');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run (en `C:\Users\alejandro.martinez\Desktop\codigo\ApiTotal`): `php vendor/bin/phpunit tests/Unit/SatPipelineDirsTest.php`
Expected: FAIL / Error `Class "App\Services\SatPipelineDirs" not found`

- [ ] **Step 3: Write minimal implementation**

```php
<?php

namespace App\Services;

/**
 * Carpetas del pipeline de descarga masiva SAT en el servidor:
 * ZIP descargados → XML descomprimidos → importados (cfdis_processed)
 * o apartados por error (cfdis_error) para que no atoren la cola.
 */
class SatPipelineDirs
{
    public const CARPETAS = [
        'zip_emited'     => 'invoice_new_emited',
        'zip_received'   => 'invoice_new_received',
        'xml_emited'     => 'cfdis_emited',
        'xml_received'   => 'cfdis_received',
        'error_emited'   => 'cfdis_error/emited',
        'error_received' => 'cfdis_error/received',
    ];

    private string $raiz;

    public function __construct(string $raiz = 'C:/tareasprogramadas/invoice/')
    {
        $this->raiz = rtrim(str_replace('\\', '/', $raiz), '/') . '/';
    }

    public function ruta(string $clave): string
    {
        return $this->raiz . self::CARPETAS[$clave] . '/';
    }

    /** Conteo y fechas (mtime) extremas de los archivos que cumplen el patrón. */
    public function resumenCarpeta(string $ruta, string $patron = '*'): array
    {
        if (!is_dir($ruta)) {
            return ['existe' => false, 'cantidad' => 0, 'mas_viejo' => null, 'mas_reciente' => null];
        }
        $cantidad = 0;
        $min = null;
        $max = null;
        foreach (new \DirectoryIterator($ruta) as $f) {
            if (!$f->isFile() || !fnmatch($patron, $f->getFilename())) {
                continue;
            }
            $cantidad++;
            $t = $f->getMTime();
            $min = $min === null ? $t : min($min, $t);
            $max = $max === null ? $t : max($max, $t);
        }
        return [
            'existe'       => true,
            'cantidad'     => $cantidad,
            'mas_viejo'    => $min === null ? null : date('Y-m-d H:i:s', $min),
            'mas_reciente' => $max === null ? null : date('Y-m-d H:i:s', $max),
        ];
    }

    public function estado(): array
    {
        $carpetas = [];
        foreach (array_keys(self::CARPETAS) as $clave) {
            $patron = str_starts_with($clave, 'zip_') ? '*.zip' : '*.xml';
            $carpetas[$clave] = ['ruta' => $this->ruta($clave)] + $this->resumenCarpeta($this->ruta($clave), $patron);
        }
        return ['generado' => date('Y-m-d H:i:s'), 'carpetas' => $carpetas];
    }

    /**
     * Aparta un XML que no se pudo importar para que no vuelva a encabezar
     * cada lote. Nunca pisa un archivo previo con el mismo nombre.
     */
    public function moverAError(string $archivo, string $type): ?string
    {
        $dir = $this->ruta($type === 'emited' ? 'error_emited' : 'error_received');
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            return null;
        }
        $base = pathinfo($archivo, PATHINFO_FILENAME);
        $ext  = pathinfo($archivo, PATHINFO_EXTENSION);
        $destino = $dir . $base . '.' . $ext;
        for ($n = 2; file_exists($destino); $n++) {
            $destino = $dir . "{$base}-{$n}.{$ext}";
        }
        return rename($archivo, $destino) ? $destino : null;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php vendor/bin/phpunit tests/Unit/SatPipelineDirsTest.php`
Expected: `OK (4 tests, …)`

- [ ] **Step 5: Commit (checkpoint: pedir autorización)**

```bash
cd C:/Users/alejandro.martinez/Desktop/codigo/ApiTotal
git add app/Services/SatPipelineDirs.php tests/Unit/SatPipelineDirsTest.php
git commit -m "feat(sat): servicio SatPipelineDirs para carpetas del pipeline"
```

---

### Task 2: `pipeline_status` + `process_xml` con `cfdis_error` y restantes (ApiTotal)

**Files:**
- Modify: `ApiTotal/routes/api.php` (grupo `facturas`)
- Modify: `ApiTotal/app/Http/Controllers/FacturaController.php` (`process_xml` ~677-719, `procesarArchivosXML` ~721-953, método nuevo `pipeline_status`)
- Test: `ApiTotal/tests/Feature/PipelineStatusTest.php`

**Interfaces:**
- Consumes: `SatPipelineDirs` (Task 1): `estado()`, `ruta()`, `resumenCarpeta()`, `moverAError()`.
- Produces:
  - `GET /api/facturas/pipeline_status` → JSON `SatPipelineDirs::estado()`.
  - `POST /api/facturas/process_xml` → `summary` agrega `moved_to_error` (int), `remaining_emited` (int), `remaining_received` (int).
  - Cada XML apartado se registra en `ApiFailures` con `Method='process_xml'`, `ResponseData='xml_error'`, `RequestData={"archivo","type","destino"}`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Services\SatPipelineDirs;
use Tests\TestCase;

class PipelineStatusTest extends TestCase
{
    public function test_pipeline_status_regresa_las_carpetas(): void
    {
        $raiz = sys_get_temp_dir() . '/satpipe_feat_' . uniqid() . '/';
        mkdir($raiz . 'cfdis_received', 0777, true);
        file_put_contents($raiz . 'cfdis_received/X.xml', 'x');
        $this->app->instance(SatPipelineDirs::class, new SatPipelineDirs($raiz));

        $res = $this->getJson('/api/facturas/pipeline_status');

        $res->assertOk()
            ->assertJsonPath('carpetas.xml_received.cantidad', 1)
            ->assertJsonPath('carpetas.xml_received.existe', true)
            ->assertJsonPath('carpetas.zip_emited.existe', false);

        unlink($raiz . 'cfdis_received/X.xml');
        rmdir($raiz . 'cfdis_received');
        rmdir($raiz);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/Feature/PipelineStatusTest.php`
Expected: FAIL — 404 en `/api/facturas/pipeline_status`.

- [ ] **Step 3: Ruta y endpoint**

En `routes/api.php`, dentro de `Route::prefix('facturas')…group(`, después de `Route::post('/process_xml', 'process_xml');`:

```php
    Route::get('/pipeline_status', 'pipeline_status');
```

En `FacturaController.php` agregar `use App\Services\SatPipelineDirs;` junto a los demás `use` y, justo antes de `public function process_xml(){`:

```php
    /** Conteos por carpeta del pipeline (solo lectura) para el panel del aplicativo. */
    public function pipeline_status(SatPipelineDirs $dirs)
    {
        return response()->json($dirs->estado());
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php vendor/bin/phpunit tests/Feature/PipelineStatusTest.php`
Expected: `OK (1 test, 3 assertions)`

- [ ] **Step 5: `procesarArchivosXML` aparta los XML que fallan**

En `procesarArchivosXML`, agregar al inicio del método (después de `$directory_n = …;`):

```php
        $dirs = app(SatPipelineDirs::class);
        $processLog['moved_to_error'] = $processLog['moved_to_error'] ?? 0;
        // Un XML que no se puede importar se aparta a cfdis_error: si se quedara en la
        // carpeta, glob() lo volvería a tomar primero en cada lote y la cola se atoraría
        $apartar = function (string $file, string $motivo) use ($dirs, $type, &$processLog) {
            $destino = $dirs->moverAError($file, $type);
            if ($destino !== null) {
                $processLog['moved_to_error']++;
            }
            $this->apiErrorController->logError('process_xml', 'facturas', $motivo, 422,
                ['archivo' => basename($file), 'type' => $type, 'destino' => $destino], 'xml_error');
        };
```

Reemplazar los 4 puntos donde un archivo se queda en la carpeta:

a) XML ilegible — sustituir el bloque `if ($xml === false) { … continue; }` por:

```php
                if ($xml === false) {
                    $processLog['failed_files']++;
                    $processLog['logs'][] = [
                        'file' => basename($file),
                        'status' => 'error',
                        'message' => 'Failed to load XML file'
                    ];
                    $apartar($file, 'XML ilegible (simplexml_load_file falló)');
                    continue;
                }
```

b) UUID inválido — en el bloque `if (!$uuid) {`, antes de su `continue;` agregar:

```php
                    $apartar($file, 'XML sin UUID de TimbreFiscalDigital');
```

c) Tipo no soportado — en el bloque `if( !in_array($facturaData['TipoDeComprobante'], ['I','P','E','T']) ) {`, antes de su `continue;` agregar:

```php
                    $apartar($file, 'TipoDeComprobante no soportado: ' . $facturaData['TipoDeComprobante']);
```

d) Excepción — en el `catch (\Throwable $e) {` del ciclo, después del `$processLog['logs'][] = [ … 'message' => $e->getMessage() ];` agregar:

```php
                $apartar($file, 'Error al importar: ' . $e->getMessage());
```

Y reemplazar el comentario de la transacción (`// Si algo truena, se hace rollback y el XML se queda en la carpeta para reintentarse.`) por:

```php
                // Si algo truena, se hace rollback y el XML se aparta a cfdis_error con el motivo en ApiFailures.
```

- [ ] **Step 6: `process_xml` informa apartados y restantes**

En `process_xml`, en el arreglo inicial `$processLog` agregar `'moved_to_error' => 0,` y reemplazar el `'summary' => [ … ]` por:

```php
                'summary' => [
                    'total_processed' => $processLog['total_files_processed'],
                    'successful' => $processLog['successful_files'],
                    'failed' => $processLog['failed_files'],
                    'skipped' => $processLog['skipped_files'],
                    'moved_to_error' => $processLog['moved_to_error'],
                    'remaining_emited' => count(glob($directory_cfdis_emited . '*.xml')),
                    'remaining_received' => count(glob($directory_cfdis_received . '*.xml')),
                ],
```

- [ ] **Step 7: Verificar sintaxis y suite completa**

Run: `php -l app/Http/Controllers/FacturaController.php && php vendor/bin/phpunit`
Expected: `No syntax errors detected` y suite en verde (Unit + Feature). Si `tests/Feature/ExampleTest.php` falla por la ruta `/` preexistente, reportarlo sin tocarlo.

- [ ] **Step 8: Commit (checkpoint: pedir autorización)**

```bash
cd C:/Users/alejandro.martinez/Desktop/codigo/ApiTotal
git add routes/api.php app/Http/Controllers/FacturaController.php tests/Feature/PipelineStatusTest.php
git commit -m "feat(sat): pipeline_status y XML fallidos a cfdis_error en process_xml"
```

Nota: `FacturaController.php` ya traía cambios sin commitear del usuario (los mismos que corren en el servidor). Mostrar `git diff --stat` al usuario antes de commitear para que decida si van juntos.

---

### Task 3: Datos de BD y semáforos (AplicativoPhp)

**Files:**
- Modify: `_assets/models/SatPeticionesModel.php`
- Test: `tools/test_sat_pipeline_model.php` (script CLI de verificación, solo lectura)

**Interfaces:**
- Produces:
  - `SatPeticionesModel::get_resumen_pendientes(): array` → `['pendientes' => int, 'mas_vieja' => ?string 'Y-m-d H:i:s']`
  - `SatPeticionesModel::get_ultima_importacion(): array` → `['emitidas' => ?string, 'recibidas' => ?string]` (`Y-m-d H:i:s`)
  - `static SatPeticionesModel::edad_dias(?string $fecha, ?int $ahora = null): ?float`
  - `static SatPeticionesModel::semaforo(?float $edadDias, float $umbralAmarillo, float $umbralRojo): string` → `'verde'|'amarillo'|'rojo'`; `null` ⇒ `'verde'`; `> rojo` ⇒ rojo; `>= amarillo` ⇒ amarillo.

- [ ] **Step 1: Write the failing test**

Crear `tools/test_sat_pipeline_model.php`:

```php
<?php
// Verificación CLI de SatPeticionesModel (solo lectura contra la BD real).
// Uso: php tools/test_sat_pipeline_model.php
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
$_SERVER['REQUEST_URI']   = '/cli/test_sat_pipeline_model';
chdir($_SERVER['DOCUMENT_ROOT']);
require '_assets/classes/header.class.php';
require '_assets/classes/php_functions.php';
spl_autoload_register(function ($c) {
    foreach ([CLASSES . $c . '.class.php', MODELS . $c . '.php'] as $f) if (file_exists($f)) require $f;
});

$fallas = 0;
$ok = function (bool $cond, string $msg) use (&$fallas) {
    echo ($cond ? 'OK   ' : 'FAIL ') . $msg . "\n";
    if (!$cond) $fallas++;
};

$ahora = strtotime('2026-09-29 12:00:00');
$ok(SatPeticionesModel::edad_dias(null, $ahora) === null, 'edad_dias(null) = null');
$ok(abs(SatPeticionesModel::edad_dias('2026-09-27 12:00:00', $ahora) - 2.0) < 0.001, 'edad_dias 2 días');
$ok(SatPeticionesModel::semaforo(null, 1, 3) === 'verde', 'semaforo null = verde');
$ok(SatPeticionesModel::semaforo(0.5, 1, 3) === 'verde', 'semaforo 0.5 = verde');
$ok(SatPeticionesModel::semaforo(2, 1, 3) === 'amarillo', 'semaforo 2 = amarillo');
$ok(SatPeticionesModel::semaforo(4, 1, 3) === 'rojo', 'semaforo 4 = rojo');
$ok(SatPeticionesModel::semaforo(0, 0, 1) === 'amarillo', 'umbral amarillo 0: cualquier archivo = amarillo');

$m = new SatPeticionesModel;
$p = $m->get_resumen_pendientes();
$ok(is_int($p['pendientes']), 'pendientes es int (' . $p['pendientes'] . ')');
$ok($p['pendientes'] === 0 ? $p['mas_vieja'] === null : is_string($p['mas_vieja']), 'mas_vieja coherente (' . var_export($p['mas_vieja'], true) . ')');

$u = $m->get_ultima_importacion();
$ok(is_string($u['emitidas']) && is_string($u['recibidas']), "ultima importacion: {$u['emitidas']} / {$u['recibidas']}");

echo $fallas ? "\n$fallas fallas\n" : "\nTodo OK\n";
exit($fallas ? 1 : 0);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tools/test_sat_pipeline_model.php`
Expected: Fatal `Call to undefined method SatPeticionesModel::edad_dias()`

- [ ] **Step 3: Write minimal implementation**

Agregar a `SatPeticionesModel` (después de `get_errores`, antes de `fecha_real`):

```php
    /** Peticiones que ApiTotal sigue consultando (estado 1) y la más vieja. */
    public function get_resumen_pendientes(): array
    {
        $rows = $this->sql->select(
            "SELECT COUNT(*) AS pendientes, MIN(fecha) AS mas_vieja
               FROM [TGV2].[dbo].[FacturasPeticiones] WHERE estado = ?",
            [self::ESTADO_PENDIENTE]
        ) ?: [];
        $r = $rows[0] ?? ['pendientes' => 0, 'mas_vieja' => null];
        return [
            'pendientes' => (int)$r['pendientes'],
            'mas_vieja'  => $r['mas_vieja'] ? date('Y-m-d H:i:s', strtotime($r['mas_vieja'])) : null,
        ];
    }

    /**
     * Fecha del CFDI más reciente entre los últimos 5,000 importados (orden de
     * Id = orden de inserción); evita escanear las tablas completas.
     */
    public function get_ultima_importacion(): array
    {
        $ultima = function (string $tabla): ?string {
            $rows = $this->sql->select(
                "SELECT MAX(Fecha) AS ultima FROM (SELECT TOP 5000 Fecha FROM [TGV2].[dbo].[{$tabla}] ORDER BY Id DESC) t"
            ) ?: [];
            $f = $rows[0]['ultima'] ?? null;
            return $f ? date('Y-m-d H:i:s', strtotime($f)) : null;
        };
        return ['emitidas' => $ultima('Facturas'), 'recibidas' => $ultima('FacturasRecibidas')];
    }

    public static function edad_dias(?string $fecha, ?int $ahora = null): ?float
    {
        if ($fecha === null || $fecha === '') return null;
        return (($ahora ?? time()) - strtotime($fecha)) / 86400;
    }

    public static function semaforo(?float $edadDias, float $umbralAmarillo, float $umbralRojo): string
    {
        if ($edadDias === null)          return 'verde';
        if ($edadDias > $umbralRojo)     return 'rojo';
        if ($edadDias >= $umbralAmarillo) return 'amarillo';
        return 'verde';
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tools/test_sat_pipeline_model.php && php -l _assets/models/SatPeticionesModel.php`
Expected: todas las líneas `OK`, `Todo OK`, `No syntax errors detected`. Con datos de hoy: `ultima importacion: 2026-06-30 … / 2026-06-30 …`.

- [ ] **Step 5: Commit (checkpoint: pedir autorización)**

```bash
git add _assets/models/SatPeticionesModel.php tools/test_sat_pipeline_model.php
git commit -m "feat(sat): resumen de pendientes, ultima importacion y semaforos"
```

---

### Task 4: Endpoints del aplicativo (estado + acciones)

**Files:**
- Modify: `_assets/controllers/it.php` (constantes junto a `SAT_API_URL` ~línea 1134; métodos nuevos después de `llamadas_sat_marcar_error`)
- Test: `tools/test_sat_pipeline_estado.php` (CLI)

**Interfaces:**
- Consumes: Task 3 (`get_resumen_pendientes`, `get_ultima_importacion`, `edad_dias`, `semaforo`); `$this->_sat_api(string $method, string $endpoint, ?array $payload, int $timeout): array` (existente; regresa `['success','status','body','message'?]`).
- Produces (todos JSON vía `json_output`, permiso `SAT_USERS`):
  - `GET /it/llamadas_sat_estado` → `{success:true, tarjetas:{peticiones, zip, xml, importado}}`; cada tarjeta: `{semaforo:'verde'|'amarillo'|'rojo'|'sin_datos', ...datos}`:
    - `peticiones`: `{pendientes, mas_vieja}`
    - `zip`: `{disponible:bool, emitidas, recibidas, mas_viejo, mensaje?}`
    - `xml`: `{disponible:bool, emitidas, recibidas, en_error, mas_viejo, mensaje?}`
    - `importado`: `{emitidas, recibidas}` (fechas)
  - `POST /it/llamadas_sat_descargar {razon_social}` → `{success, sin_pendientes?:bool, results:[{requestId,status,message}], message?}`
  - `POST /it/llamadas_sat_descomprimir` → `{success, processed, failed, errores:[{mensaje,cantidad,ejemplos[]}], message?}`
  - `POST /it/llamadas_sat_importar_lote` → `{success, summary:{total_processed,successful,failed,skipped,moved_to_error,remaining}, errores:[{mensaje,cantidad,ejemplos[]}], message?}`; `summary.remaining` = `remaining_emited + remaining_received` o `null` si ApiTotal aún no los manda.
  - `private function _sat_agrupar_errores(array $items, string $campoArchivo, string $campoMensaje): array` → top 10 `{mensaje, cantidad, ejemplos (≤3)}`.

- [ ] **Step 1: Write the failing test**

Crear `tools/test_sat_pipeline_estado.php` (llama al endpoint real en CLI con sesión simulada):

```php
<?php
// Uso: php tools/test_sat_pipeline_estado.php  (solo lectura)
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
$_SERVER['REQUEST_URI']   = '/it/llamadas_sat_estado';
chdir($_SERVER['DOCUMENT_ROOT']);
require '_assets/classes/header.class.php';
require '_assets/classes/php_functions.php';
spl_autoload_register(function ($c) {
    foreach ([CLASSES . $c . '.class.php', CONTROLLERS . strtolower($c) . '.php', MODELS . $c . '.php'] as $f) if (file_exists($f)) require $f;
});
$_SESSION['tg_user'] = ['Id' => 6296];   // usuario de SAT_USERS
ob_start();
register_shutdown_function(function () {
    $json = json_decode(ob_get_clean(), true);
    $t = $json['tarjetas'] ?? [];
    $okAll = ($json['success'] ?? false)
        && isset($t['peticiones']['semaforo'], $t['zip']['semaforo'], $t['xml']['semaforo'], $t['importado']['semaforo']);
    print_r($json);
    echo $okAll ? "\nOK estructura\n" : "\nFAIL estructura\n";
});
(new It($twig))->llamadas_sat_estado();
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tools/test_sat_pipeline_estado.php`
Expected: Fatal `Call to undefined method It::llamadas_sat_estado()`

- [ ] **Step 3: Implementación**

Junto a `private const SAT_API_URL = …;` agregar:

```php
    // Semáforo del panel "Estado del proceso" (días)
    private const SAT_UMBRAL_PETICION_AMARILLO = 1;
    private const SAT_UMBRAL_PETICION_ROJO     = 3;
    private const SAT_UMBRAL_ARCHIVO_ROJO      = 1;  // ZIP/XML esperando
    private const SAT_UMBRAL_IMPORT_AMARILLO   = 1;
    private const SAT_UMBRAL_IMPORT_ROJO       = 3;
```

Después de `llamadas_sat_marcar_error()` agregar:

```php
    /** Panel "Estado del proceso": combina carpetas (ApiTotal) con datos de BD. */
    public function llamadas_sat_estado(): void {
        if (!in_array((int)$_SESSION['tg_user']['Id'], self::SAT_USERS)) {
            json_output(['success' => false, 'message' => 'Sin permisos']);
        }
        $model = new SatPeticionesModel;

        $pend = $model->get_resumen_pendientes();
        $peticiones = $pend + ['semaforo' => SatPeticionesModel::semaforo(
            SatPeticionesModel::edad_dias($pend['mas_vieja']),
            self::SAT_UMBRAL_PETICION_AMARILLO, self::SAT_UMBRAL_PETICION_ROJO)];

        $imp = $model->get_ultima_importacion();
        $fechaImp = max($imp['emitidas'] ?? '', $imp['recibidas'] ?? '') ?: null;
        $importado = $imp + ['semaforo' => $fechaImp === null ? 'sin_datos' : SatPeticionesModel::semaforo(
            SatPeticionesModel::edad_dias($fechaImp),
            self::SAT_UMBRAL_IMPORT_AMARILLO, self::SAT_UMBRAL_IMPORT_ROJO)];

        $res = $this->_sat_api('GET', 'pipeline_status', null, 60);
        $c = ($res['success'] && is_array($res['body'])) ? ($res['body']['carpetas'] ?? null) : null;
        if ($c === null) {
            $mensaje = ($res['status'] ?? 0) === 404
                ? 'Falta subir ApiTotal (pipeline_status)'
                : ($res['message'] ?? 'No se pudo leer el estado de las carpetas');
            $zip = ['disponible' => false, 'semaforo' => 'sin_datos', 'mensaje' => $mensaje];
            $xml = ['disponible' => false, 'semaforo' => 'sin_datos', 'mensaje' => $mensaje];
        } else {
            $masViejo = function (array ...$cs): ?string {
                $fechas = array_filter(array_column($cs, 'mas_viejo'));
                return $fechas ? min($fechas) : null;
            };
            $semArchivo = function (int $cantidad, ?string $viejo): string {
                if ($cantidad === 0) return 'verde';
                return SatPeticionesModel::semaforo(SatPeticionesModel::edad_dias($viejo), 0, self::SAT_UMBRAL_ARCHIVO_ROJO);
            };

            $zipViejo = $masViejo($c['zip_emited'], $c['zip_received']);
            $zipCant  = $c['zip_emited']['cantidad'] + $c['zip_received']['cantidad'];
            $zip = ['disponible' => true, 'emitidas' => $c['zip_emited']['cantidad'],
                    'recibidas' => $c['zip_received']['cantidad'], 'mas_viejo' => $zipViejo,
                    'semaforo' => $semArchivo($zipCant, $zipViejo)];

            $xmlViejo = $masViejo($c['xml_emited'], $c['xml_received']);
            $xmlCant  = $c['xml_emited']['cantidad'] + $c['xml_received']['cantidad'];
            $enError  = $c['error_emited']['cantidad'] + $c['error_received']['cantidad'];
            $semXml   = $semArchivo($xmlCant, $xmlViejo);
            if ($semXml === 'verde' && $enError > 0) $semXml = 'amarillo';
            $xml = ['disponible' => true, 'emitidas' => $c['xml_emited']['cantidad'],
                    'recibidas' => $c['xml_received']['cantidad'], 'en_error' => $enError,
                    'mas_viejo' => $xmlViejo, 'semaforo' => $semXml];
        }

        json_output(['success' => true, 'tarjetas' => compact('peticiones') + ['zip' => $zip, 'xml' => $xml, 'importado' => $importado]]);
    }

    /** Descarga ahora las pendientes terminadas de un RFC (lo que hace la tarea de cada 4 h). */
    public function llamadas_sat_descargar(): void {
        if (!in_array((int)$_SESSION['tg_user']['Id'], self::SAT_USERS)) {
            json_output(['success' => false, 'message' => 'Sin permisos']);
        }
        set_time_limit(0);
        $rfc = $_POST['razon_social'] ?? '';
        if (!isset(self::SAT_RFCS[$rfc])) {
            json_output(['success' => false, 'message' => 'Razón social inválida']);
        }
        $res = $this->_sat_api('POST', 'consult_pending', ['razon_social' => $rfc], 600);
        if (($res['status'] ?? 0) === 404) {
            json_output(['success' => true, 'sin_pendientes' => true, 'results' => [],
                         'message' => 'No hay peticiones pendientes para ' . self::SAT_RFCS[$rfc]]);
        }
        if (!$res['success']) json_output($res);
        json_output(['success' => true, 'results' => $res['body']['results'] ?? []]);
    }

    /** Descomprime todos los ZIP descargados (process_downloaded_packages). */
    public function llamadas_sat_descomprimir(): void {
        if (!in_array((int)$_SESSION['tg_user']['Id'], self::SAT_USERS)) {
            json_output(['success' => false, 'message' => 'Sin permisos']);
        }
        set_time_limit(0);
        $res = $this->_sat_api('POST', 'process_downloaded_packages', [], 600);
        if (!$res['success']) json_output($res);
        $body = $res['body'];
        $fallidos = array_filter($body['results'] ?? [], fn($r) => ($r['status'] ?? '') !== 'success');
        json_output([
            'success'   => true,
            'processed' => (int)($body['processed'] ?? 0),
            'failed'    => (int)($body['failed'] ?? 0),
            'errores'   => $this->_sat_agrupar_errores(array_values($fallidos), 'file', 'message'),
        ]);
    }

    /** Un lote de importación (process_xml: ≤400 emitidas + ≤400 recibidas). */
    public function llamadas_sat_importar_lote(): void {
        if (!in_array((int)$_SESSION['tg_user']['Id'], self::SAT_USERS)) {
            json_output(['success' => false, 'message' => 'Sin permisos']);
        }
        set_time_limit(0);
        $res = $this->_sat_api('POST', 'process_xml', [], 600);
        if (!$res['success'] || !is_array($res['body'])) {
            json_output(['success' => false, 'message' => $res['message'] ?? 'Respuesta inesperada de process_xml']);
        }
        $s = $res['body']['summary'] ?? [];
        $errores = array_values(array_filter($res['body']['logs'] ?? [], fn($l) => ($l['status'] ?? '') === 'error'));
        json_output([
            'success' => true,
            'summary' => [
                'total_processed' => (int)($s['total_processed'] ?? 0),
                'successful'      => (int)($s['successful'] ?? 0),
                'failed'          => (int)($s['failed'] ?? 0),
                'skipped'         => (int)($s['skipped'] ?? 0),
                'moved_to_error'  => (int)($s['moved_to_error'] ?? 0),
                'remaining'       => isset($s['remaining_emited'], $s['remaining_received'])
                    ? (int)$s['remaining_emited'] + (int)$s['remaining_received'] : null,
            ],
            'errores' => $this->_sat_agrupar_errores($errores, 'file', 'message'),
        ]);
    }

    /** Agrupa errores por mensaje: top 10 con cantidad y hasta 3 archivos de ejemplo. */
    private function _sat_agrupar_errores(array $items, string $campoArchivo, string $campoMensaje): array {
        $grupos = [];
        foreach ($items as $it) {
            $msg = mb_substr((string)($it[$campoMensaje] ?? 'Error sin mensaje'), 0, 300);
            $grupos[$msg] ??= ['mensaje' => $msg, 'cantidad' => 0, 'ejemplos' => []];
            $grupos[$msg]['cantidad']++;
            if (count($grupos[$msg]['ejemplos']) < 3 && !empty($it[$campoArchivo])) {
                $grupos[$msg]['ejemplos'][] = $it[$campoArchivo];
            }
        }
        usort($grupos, fn($a, $b) => $b['cantidad'] <=> $a['cantidad']);
        return array_slice($grupos, 0, 10);
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php -l _assets/controllers/it.php && php tools/test_sat_pipeline_estado.php`
Expected: `No syntax errors detected`, `OK estructura`. Antes de subir ApiTotal: `zip`/`xml` con `semaforo: sin_datos` y `mensaje: Falta subir ApiTotal (pipeline_status)`; `importado.semaforo: rojo` (última 2026-06-30).

- [ ] **Step 5: Commit (checkpoint: pedir autorización)**

```bash
git add _assets/controllers/it.php tools/test_sat_pipeline_estado.php
git commit -m "feat(sat): endpoints de estado del pipeline y acciones descargar/descomprimir/importar"
```

Nota: `it.php` ya trae los cambios previos de Llamadas SAT sin commitear; mostrar `git diff --stat` al usuario antes.

---

### Task 5: Panel "Estado del proceso" en la vista

**Files:**
- Modify: `views/it/llamadas_sat.html` (entre la card "Nueva petición" y la card de pendientes; y el bloque `<script>`)

**Interfaces:**
- Consumes: `GET /it/llamadas_sat_estado` (Task 4).
- Produces: función JS `cargarEstado()` (global dentro del `$(document).ready`), usada por Task 6 para refrescar tras cada acción; botones `#btnDescomprimir` y `#btnImportar` (handlers en Task 6).

- [ ] **Step 1: Markup del panel**

Insertar antes de la card que contiene `id="btnRefresh"`:

```html
<div class="card" id="panelEstado">
    <div class="card-header pb-0">
        <div class="card-actions float-end">
            <button type="button" class="btn btn-sm btn-outline-secondary" id="btnEstadoRefresh"><i data-feather="refresh-cw"></i> Actualizar</button>
        </div>
        <h5 class="card-title mb-0">Estado del proceso</h5>
    </div>
    <div class="card-body">
        <div class="row g-2">
            <div class="col-12 col-md-6 col-xl-3">
                <div class="border rounded p-2 h-100" data-tarjeta="peticiones">
                    <div class="d-flex align-items-center gap-2"><span class="sem"></span><b>1. Peticiones SAT</b></div>
                    <div class="small mt-1 cuerpo text-muted">Cargando…</div>
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-3">
                <div class="border rounded p-2 h-100" data-tarjeta="zip">
                    <div class="d-flex align-items-center gap-2"><span class="sem"></span><b>2. ZIP por descomprimir</b></div>
                    <div class="small mt-1 cuerpo text-muted">Cargando…</div>
                    <button type="button" class="btn btn-sm btn-outline-primary mt-2" id="btnDescomprimir" disabled>Descomprimir</button>
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-3">
                <div class="border rounded p-2 h-100" data-tarjeta="xml">
                    <div class="d-flex align-items-center gap-2"><span class="sem"></span><b>3. XML por importar</b></div>
                    <div class="small mt-1 cuerpo text-muted">Cargando…</div>
                    <button type="button" class="btn btn-sm btn-outline-primary mt-2" id="btnImportar" disabled>Importar</button>
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-3">
                <div class="border rounded p-2 h-100" data-tarjeta="importado">
                    <div class="d-flex align-items-center gap-2"><span class="sem"></span><b>4. Importado a la BD</b></div>
                    <div class="small mt-1 cuerpo text-muted">Cargando…</div>
                </div>
            </div>
        </div>
        <small class="text-muted d-block mt-2" id="estadoGenerado"></small>
    </div>
</div>
```

En el bloque `{% block mycss %}` agregar:

```html
<style>
    #panelEstado .sem { width: 12px; height: 12px; border-radius: 50%; display: inline-block; background: #adb5bd; flex: 0 0 auto; }
    #panelEstado .sem.verde    { background: #198754; }
    #panelEstado .sem.amarillo { background: #ffc107; }
    #panelEstado .sem.rojo     { background: #dc3545; }
</style>
```

- [ ] **Step 2: JS de carga del panel**

Dentro del `$(document).ready(...)`, antes de `feather.replace();`:

```js
    // ---- Panel "Estado del proceso" ----
    var ultimoEstado = null;
    function fmtN(n) { return Number(n || 0).toLocaleString('es-MX'); }
    function fmtF(f) { return f ? f.substring(0, 16).replace('T', ' ') : '—'; }
    function pintarTarjeta(clave, t, html) {
        var box = $('#panelEstado [data-tarjeta="' + clave + '"]');
        box.find('.sem').attr('class', 'sem ' + (t.semaforo || ''));
        box.find('.cuerpo').removeClass('text-muted').html(html);
    }
    function cargarEstado() {
        return $.getJSON('/it/llamadas_sat_estado')
            .done(function (res) {
                if (!res.success) { mostrarError('No se pudo cargar el estado del proceso', mensajeError(res)); return; }
                var t = res.tarjetas, esc = function (v) { return $('<div>').text(String(v)).html(); };
                ultimoEstado = t;
                pintarTarjeta('peticiones', t.peticiones, t.peticiones.pendientes
                    ? fmtN(t.peticiones.pendientes) + ' pendiente(s)<br>La más vieja: ' + fmtF(t.peticiones.mas_vieja)
                    : 'Sin pendientes');
                pintarTarjeta('zip', t.zip, t.zip.disponible
                    ? 'Emitidas: ' + fmtN(t.zip.emitidas) + ' · Recibidas: ' + fmtN(t.zip.recibidas) + '<br>Más viejo: ' + fmtF(t.zip.mas_viejo)
                    : '<span class="text-danger">' + esc(t.zip.mensaje) + '</span>');
                pintarTarjeta('xml', t.xml, t.xml.disponible
                    ? 'Emitidas: ' + fmtN(t.xml.emitidas) + ' · Recibidas: ' + fmtN(t.xml.recibidas) + '<br>Más viejo: ' + fmtF(t.xml.mas_viejo)
                      + (t.xml.en_error ? '<br><span class="text-danger">En cfdis_error: ' + fmtN(t.xml.en_error) + '</span>' : '')
                    : '<span class="text-danger">' + esc(t.xml.mensaje) + '</span>');
                pintarTarjeta('importado', t.importado,
                    'Emitidas: ' + fmtF(t.importado.emitidas) + '<br>Recibidas: ' + fmtF(t.importado.recibidas));
                $('#btnDescomprimir').prop('disabled', !(t.zip.disponible && (t.zip.emitidas + t.zip.recibidas) > 0));
                // Importar se permite aunque falte pipeline_status (process_xml existe desde antes)
                $('#btnImportar').prop('disabled', t.xml.disponible && (t.xml.emitidas + t.xml.recibidas) === 0);
                $('#estadoGenerado').text('Actualizado: ' + new Date().toLocaleString());
            })
            .fail(function (xhr, textStatus) { errorAjax('No se pudo cargar el estado del proceso', xhr, textStatus); });
    }
    $('#btnEstadoRefresh').on('click', cargarEstado);
    cargarEstado();
```

- [ ] **Step 3: Verificar en navegador (el usuario abre `http://localhost:8001/it/llamadas_sat`)**

Expected con ApiTotal aún sin subir: tarjeta 1 con 1 pendiente (16158) o "Sin pendientes"; tarjetas 2 y 3 en gris con "Falta subir ApiTotal (pipeline_status)" en rojo, Descomprimir deshabilitado, Importar habilitado; tarjeta 4 en rojo con 30/06/2026. Sin errores en consola. En ancho de teléfono las tarjetas se apilan sin scroll horizontal.

- [ ] **Step 4: Commit (checkpoint: pedir autorización)**

```bash
git add views/it/llamadas_sat.html
git commit -m "feat(sat): panel Estado del proceso en Llamadas SAT"
```

---

### Task 6: Acciones Descargar, Descomprimir e Importar (vista)

**Files:**
- Modify: `views/it/llamadas_sat.html` (render de la columna ACCIONES y bloque `<script>`)

**Interfaces:**
- Consumes: Task 4 endpoints; Task 5 `cargarEstado()`, `#btnDescomprimir`, `#btnImportar`; helpers existentes `mostrarError(titulo, texto)`, `errorAjax(titulo, xhr, textStatus)`, `mensajeError(res)`, `table` (DataTable).

- [ ] **Step 1: Botón Descargar por fila**

En el `render` de la columna de acciones, dentro del `btn-group`, entre Verificar y Marcar error, agregar:

```js
                        + '<button type="button" class="btn btn-outline-success btn-descargar" data-rfc="' + rfc + '">Descargar</button>'
```

- [ ] **Step 2: Helper para mostrar errores agrupados**

Antes de `feather.replace();`:

```js
    function htmlErrores(errores) {
        if (!errores || !errores.length) return '';
        var esc = function (v) { return $('<div>').text(String(v)).html(); };
        return '<div class="text-start small mt-2"><b>Errores:</b><ul class="mb-0">' + errores.map(function (e) {
            return '<li>' + esc(e.mensaje) + ' <b>×' + e.cantidad + '</b>'
                + (e.ejemplos.length ? '<br><small class="text-muted">' + e.ejemplos.map(esc).join(', ') + '</small>' : '') + '</li>';
        }).join('') + '</ul></div>';
    }
```

- [ ] **Step 3: Handlers Descargar y Descomprimir**

```js
    $('#datatables_llamadas_sat').on('click', '.btn-descargar', function () {
        var btn = $(this), rfc = btn.data('rfc');
        Swal.fire({ title: '¿Descargar ahora?', html: 'Revisa hasta 5 pendientes de <b>' + $('<div>').text(rfc).html() + '</b> y baja los paquetes de las que el SAT ya terminó.',
                    icon: 'question', showCancelButton: true, confirmButtonText: 'Descargar', cancelButtonText: 'Cancelar' })
        .then(function (r) {
            if (!r.isConfirmed) return;
            Swal.fire({ title: 'Descargando del SAT…', allowOutsideClick: false, didOpen: function () { Swal.showLoading(); } });
            $.post('/it/llamadas_sat_descargar', { razon_social: rfc }, null, 'json')
                .done(function (res) {
                    if (!res.success) { mostrarError('Descargar ' + rfc, mensajeError(res)); return; }
                    if (res.sin_pendientes) { Swal.fire('Sin pendientes', res.message, 'info'); return; }
                    var esc = function (v) { return $('<div>').text(String(v)).html(); };
                    var html = res.results.map(function (x) {
                        return '<b>' + esc(x.requestId) + '</b><br>' + esc(x.status) + ': ' + esc(x.message || '');
                    }).join('<hr>');
                    var algunError = res.results.some(function (x) { return ['finished', 'in_progress'].indexOf(x.status) === -1; });
                    Swal.fire({ title: 'Resultado de la descarga', html: html || 'Sin resultados', icon: algunError ? 'warning' : 'success' });
                })
                .fail(function (xhr, textStatus) { errorAjax('Descargar ' + rfc, xhr, textStatus); })
                .always(function () { table.ajax.reload(); cargarEstado(); });
        });
    });

    $('#btnDescomprimir').on('click', function () {
        Swal.fire({ title: '¿Descomprimir los ZIP?', text: 'Pasa los XML de todos los ZIP descargados a las carpetas de importación.',
                    icon: 'question', showCancelButton: true, confirmButtonText: 'Descomprimir', cancelButtonText: 'Cancelar' })
        .then(function (r) {
            if (!r.isConfirmed) return;
            Swal.fire({ title: 'Descomprimiendo…', allowOutsideClick: false, didOpen: function () { Swal.showLoading(); } });
            $.post('/it/llamadas_sat_descomprimir', {}, null, 'json')
                .done(function (res) {
                    if (!res.success) { mostrarError('Descomprimir', mensajeError(res)); return; }
                    Swal.fire({ title: 'Descompresión terminada', icon: res.failed ? 'warning' : 'success',
                                html: 'ZIP descomprimidos: <b>' + res.processed + '</b> · con error: <b>' + res.failed + '</b>' + htmlErrores(res.errores) });
                })
                .fail(function (xhr, textStatus) { errorAjax('Descomprimir', xhr, textStatus); })
                .always(cargarEstado);
        });
    });
```

- [ ] **Step 4: Verificar Descargar (petición real 16158, ya `finished` en el SAT)**

En navegador: clic en **Descargar** de la fila Dias Gas. Expected: resultado `finished`, la fila desaparece de pendientes (estado 0) y la tarjeta 1 baja a "Sin pendientes". Con ApiTotal ya subido, la tarjeta 2 muestra el ZIP. Luego **Descargar** otra vez en esa empresa (sin pendientes) → aviso azul "Sin pendientes", no rojo.
Comprobación en BD: `sqlcmd … -d TGV2 -Q "SELECT id, estado FROM FacturasPeticiones WHERE id = 16158"` → `estado = 0`.

- [ ] **Step 5: Ciclo de Importar con progreso y Detener**

```js
    var importando = false, detenerImport = false;
    $('#btnImportar').on('click', function () {
        if (importando) return;
        var inicial = ultimoEstado && ultimoEstado.xml.disponible ? (ultimoEstado.xml.emitidas + ultimoEstado.xml.recibidas) : null;
        Swal.fire({ title: '¿Importar XML a la BD?', icon: 'question', showCancelButton: true,
                    html: (inicial !== null ? 'Hay <b>' + fmtN(inicial) + '</b> XML esperando. ' : '')
                        + 'Se importan en lotes de hasta 800; puedes detener entre lotes.',
                    confirmButtonText: 'Importar', cancelButtonText: 'Cancelar' })
        .then(function (r) { if (r.isConfirmed) importarLotes(inicial); });
    });

    function importarLotes(inicial) {
        importando = true; detenerImport = false;
        var tot = { lotes: 0, ok: 0, omitidos: 0, fallidos: 0, apartados: 0 }, errores = {}, restante = inicial;
        Swal.fire({ title: 'Importando…', html: '<div id="impProgreso">Iniciando…</div>', allowOutsideClick: false,
                    showConfirmButton: false, showCancelButton: true, cancelButtonText: 'Detener',
                    didOpen: function () { Swal.showLoading(); } })
        .then(function (r) { if (r.dismiss === Swal.DismissReason.cancel) detenerImport = true; });

        // El resumen se arma de los totales (no del DOM): al dar Detener el modal de progreso ya se cerró
        function resumenHtml() {
            return 'Lotes: <b>' + tot.lotes + '</b><br>Importados: <b>' + fmtN(tot.ok) + '</b> · ya existían: ' + fmtN(tot.omitidos)
                + '<br>Con error: <b>' + fmtN(tot.fallidos) + '</b> (apartados a cfdis_error: ' + fmtN(tot.apartados) + ')'
                + (restante !== null ? '<br>Quedan: <b>' + fmtN(restante) + '</b>' : '');
        }
        function pintar() { $('#impProgreso').html(resumenHtml()); }
        function terminar(motivo, icono) {
            importando = false;
            var lista = Object.keys(errores).map(function (k) { return errores[k]; })
                .sort(function (a, b) { return b.cantidad - a.cantidad; }).slice(0, 10);
            Swal.fire({ title: 'Importación ' + motivo, icon: icono, html: resumenHtml() + htmlErrores(lista) });
            cargarEstado();
        }
        function lote() {
            if (detenerImport) { pintar(); terminar('detenida', 'info'); return; }
            $.post('/it/llamadas_sat_importar_lote', {}, null, 'json')
                .done(function (res) {
                    if (!res.success) { importando = false; mostrarError('Importar', mensajeError(res)); cargarEstado(); return; }
                    var s = res.summary;
                    tot.lotes++; tot.ok += s.successful; tot.omitidos += s.skipped; tot.fallidos += s.failed; tot.apartados += s.moved_to_error;
                    (res.errores || []).forEach(function (e) {
                        errores[e.mensaje] = errores[e.mensaje] || { mensaje: e.mensaje, cantidad: 0, ejemplos: e.ejemplos };
                        errores[e.mensaje].cantidad += e.cantidad;
                    });
                    restante = s.remaining !== null ? s.remaining : (restante !== null ? Math.max(0, restante - s.total_processed) : null);
                    pintar();
                    var avance = s.successful + s.skipped + s.moved_to_error;
                    if (s.total_processed === 0 || restante === 0) { terminar('terminada', tot.fallidos ? 'warning' : 'success'); return; }
                    // Sin avance: todos fallaron y siguen en la carpeta (ApiTotal viejo o rename fallido)
                    if (avance === 0) { terminar('detenida: el lote no avanzó', 'error'); return; }
                    lote();
                })
                .fail(function (xhr, textStatus) { importando = false; errorAjax('Importar (lote ' + (tot.lotes + 1) + ')', xhr, textStatus); cargarEstado(); });
        }
        lote();
    }
```

- [ ] **Step 6: Verificar Importar y Descomprimir en navegador**

1. Con ApiTotal **sin subir**: clic en Importar → corre al menos un lote. Si los XML fallan siempre (sospecha del paro de julio) → termina con "detenida: el lote no avanzó" y la lista de errores reales. Si importa → "Importados" sube y la tarjeta 4 cambia de fecha.
2. Con ApiTotal **subido**: Descomprimir convierte el ZIP de la 16158 en XML (tarjeta 2 a 0, tarjeta 3 +N); Importar avanza lote a lote mostrando "Quedan"; los fallidos aparecen en "En cfdis_error". **Detener** corta al terminar el lote en curso.
3. Si un lote corta por timeout de IIS (`errorAjax` con HTTP 500/502 tras varios minutos), anotarlo: mitigación del spec = bajar el `limit` de `process_xml`.

- [ ] **Step 7: Commit (checkpoint: pedir autorización)**

```bash
git add views/it/llamadas_sat.html
git commit -m "feat(sat): acciones Descargar, Descomprimir e Importar por lotes"
```

---

### Task 7: Cierre — lista de despliegue y memoria

**Files:**
- Modify: `C:\Users\alejandro.martinez\.claude\projects\C--Users-alejandro-martinez-Desktop-codigo-AplicativoPhp\memory\llamadas-sat-descarga-masiva.md`

- [ ] **Step 1: Entregar al usuario la lista de archivos de ApiTotal a subir por FTP** (a `C:\inetpub\wwwroot\ApiTotal\` en 192.168.0.3):
  - `app/Services/SatPipelineDirs.php` (nuevo)
  - `app/Http/Controllers/FacturaController.php`
  - `app/Http/Controllers/ApiErrorController.php` (del cambio anterior de fechas del log, si aún no se subió)
  - `routes/api.php`
  - Tras subir: `php artisan route:clear` en el servidor si las rutas están cacheadas, y verificar con `curl http://192.168.0.3:388/api/facturas/pipeline_status`.

- [ ] **Step 2: Actualizar la memoria** con: panel de estado y acciones existentes, `cfdis_error/{emited,received}` como destino de XML fallidos (motivo en `ApiFailures` con `ResponseData='xml_error'`), y el resultado de la primera importación (causa del paro de julio, si apareció).
