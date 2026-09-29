# XML procesados por empresa / tipo / año / mes — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que `process_xml` guarde cada XML importado en `cfdis_processed\{RFC}\{emitidas|recibidas}\{AAAA}\{MM}\` y un comando Artisan reorganice retroactivamente los ~290k XML sueltos con la misma regla.

**Architecture:** Una sola regla de ruta (`SatPipelineDirs::rutaProcesado`) usada por el proceso y por el comando. El comando `sat:reorganizar-procesadas` clasifica cada archivo por su UUID contra la BD a través de una clase inyectable (`ProcesadasBuscadorBd`), para poder probarlo con datos fijos; deja bitácora CSV reversible y respeta el mismo candado que `process_xml`.

**Tech Stack:** Laravel 11, PHP 8.2, PHPUnit 11, SQL Server (conexión `sqlsrv`, BD TGV2).

**Spec:** `docs/superpowers/specs/2026-09-29-procesadas-por-empresa-design.md` (en el repo AplicativoPhp)

## Global Constraints

- Repo de trabajo: `C:\Users\alejandro.martinez\Desktop\codigo\ApiTotal`. **El usuario sube los archivos por FTP**; al terminar, listar los archivos tocados.
- **Sin commits** salvo autorización explícita del usuario: los pasos "Commit" son checkpoints — omitirlos. `FacturaController.php` trae cambios previos del usuario sin commitear que no se tocan.
- **Nunca** ejecutar contra el servidor real ni mover archivos de `C:\tareasprogramadas\invoice\` desde las pruebas; las pruebas usan carpetas temporales. Nada de SQL de escritura.
- Estructura destino (verbatim del spec): `C:\tareasprogramadas\invoice\cfdis_processed\{RFC empresa}\{emitidas|recibidas}\{AAAA}\{MM}\{UUID}.xml` y `…\cfdis_processed\sin_clasificar\{UUID}.xml`.
- Emitida (`$type === 'emited'`, tabla `Facturas`) → RFC = **emisor**; recibida (`'received'`, `FacturasRecibidas`) → RFC = **receptor**.
- `{AAAA}\{MM}` = año/mes de la **fecha de emisión** (`Fecha`).
- RFC saneado: mayúsculas, solo `[A-Z0-9&Ñ]`; vacío o fecha no parseable → `sin_clasificar`.
- Candado compartido con `process_xml`: `sys_get_temp_dir()/sat_process_xml.lock`, `LOCK_EX|LOCK_NB`.
- Bitácoras en `storage/logs/`: corrida real `reorganizar-procesadas-{Ymd-His}-{sufijo}.csv` (filas `accion,origen,destino,detalle`, acciones `move|copy|error`); dry-run `reorganizar-procesadas-dryrun-{Ymd-His}-{sufijo}.csv`.
- No usar PowerShell `Get-Content/Set-Content` para escribir archivos.

## Review Focus

1. **Carpeta destino que no se puede crear** (permisos, ruta inválida) durante una importación → el XML importado no debe ir a `cfdis_error` ni tronar el lote; `rutaProcesado` regresa la raíz de `cfdis_processed` (comportamiento de hoy). Prueba en Task 1.
2. **Dry-run no debe crear carpetas** (el usuario revisa el reporte antes de tocar nada) → `rutaProcesado(..., crear: false)`. Prueba en Task 1 y Task 4.
3. **Interrumpir y volver a correr** el comando → no duplica ni pierde archivos (solo toma sueltos de la raíz). Prueba en Task 4.
4. **UUID en mayúsculas vs minúsculas** (archivos y BD vienen en minúsculas; SQL Server compara sin distinguir) → la búsqueda normaliza a mayúsculas. Prueba en Task 4 (archivo en minúsculas, mapa en mayúsculas).
5. **Dos corridas en el mismo segundo** → bitácoras distintas (sufijo único), `--revertir` usa la correcta. Prueba en Task 4.

---

### Task 1: Regla de ruta de procesados y candado compartido

**Files:**
- Modify: `app/Services/SatPipelineDirs.php`
- Test: `tests/Unit/SatPipelineDirsRutaProcesadoTest.php`

**Interfaces:**
- Produces:
  - `const PROCESADAS = 'cfdis_processed'`
  - `__construct(string $raiz = 'C:/tareasprogramadas/invoice/', ?string $candado = null)` (el segundo parámetro es nuevo; default `sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sat_process_xml.lock'`)
  - `rutaCandado(): string`
  - `rutaProcesadas(): string` → `{raiz}cfdis_processed/`
  - `rutaProcesado(string $type, ?string $rfcEmisor, ?string $rfcReceptor, ?string $fecha, bool $crear = true): string` → carpeta destino con `/` final.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit;

use App\Services\SatPipelineDirs;
use PHPUnit\Framework\TestCase;

class SatPipelineDirsRutaProcesadoTest extends TestCase
{
    private string $raiz;

    protected function setUp(): void
    {
        $this->raiz = sys_get_temp_dir() . '/satproc_' . uniqid() . '/';
        mkdir($this->raiz, 0777, true);
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

    public function test_emitida_usa_rfc_emisor_y_crea_carpeta(): void
    {
        $d = new SatPipelineDirs($this->raiz);
        $ruta = $d->rutaProcesado('emited', 'DGA930823KD3', 'XAXX010101000', '2026-09-28T23:02:32');
        $this->assertSame($this->raiz . 'cfdis_processed/DGA930823KD3/emitidas/2026/09/', $ruta);
        $this->assertDirectoryExists($ruta);
    }

    public function test_recibida_usa_rfc_receptor(): void
    {
        $d = new SatPipelineDirs($this->raiz);
        $ruta = $d->rutaProcesado('received', 'PROV010101AAA', 'DGM880621FU5', '2026-01-05 10:00:00.000');
        $this->assertSame($this->raiz . 'cfdis_processed/DGM880621FU5/recibidas/2026/01/', $ruta);
    }

    public function test_rfc_se_sanea(): void
    {
        $d = new SatPipelineDirs($this->raiz);
        $ruta = $d->rutaProcesado('emited', ' dga930823-kd3/.. ', null, '2026-09-01', false);
        $this->assertSame($this->raiz . 'cfdis_processed/DGA930823KD3/emitidas/2026/09/', $ruta);
    }

    public function test_sin_rfc_o_fecha_invalida_va_a_sin_clasificar(): void
    {
        $d = new SatPipelineDirs($this->raiz);
        $esperado = $this->raiz . 'cfdis_processed/sin_clasificar/';
        $this->assertSame($esperado, $d->rutaProcesado('emited', null, 'X', '2026-09-01', false));
        $this->assertSame($esperado, $d->rutaProcesado('emited', '---', 'X', '2026-09-01', false));
        $this->assertSame($esperado, $d->rutaProcesado('received', 'X', 'DGM880621FU5', null, false));
        $this->assertSame($esperado, $d->rutaProcesado('received', 'X', 'DGM880621FU5', 'basura', false));
        $this->assertSame($esperado, $d->rutaProcesado('received', 'X', 'DGM880621FU5', '2026-13-40', false));
    }

    public function test_crear_false_no_crea_carpetas(): void
    {
        $d = new SatPipelineDirs($this->raiz);
        $ruta = $d->rutaProcesado('emited', 'DGA930823KD3', null, '2026-09-01', false);
        $this->assertDirectoryDoesNotExist($ruta);
    }

    public function test_si_no_se_puede_crear_regresa_la_raiz_de_procesadas(): void
    {
        $d = new SatPipelineDirs($this->raiz);
        mkdir($this->raiz . 'cfdis_processed', 0777, true);
        // Un ARCHIVO con el nombre de la carpeta del RFC impide crear la ruta
        file_put_contents($this->raiz . 'cfdis_processed/DGA930823KD3', 'x');
        set_error_handler(function ($no, $str) { throw new \ErrorException($str, 0, $no); }); // como Laravel
        try {
            $ruta = $d->rutaProcesado('emited', 'DGA930823KD3', null, '2026-09-01');
        } finally {
            restore_error_handler();
        }
        $this->assertSame($this->raiz . 'cfdis_processed/', $ruta);
    }

    public function test_candado_default_y_personalizado(): void
    {
        $this->assertStringEndsWith('sat_process_xml.lock', (new SatPipelineDirs($this->raiz))->rutaCandado());
        $this->assertSame('X:/otro.lock', (new SatPipelineDirs($this->raiz, 'X:/otro.lock'))->rutaCandado());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/Unit/SatPipelineDirsRutaProcesadoTest.php`
Expected: errores `Call to undefined method App\Services\SatPipelineDirs::rutaProcesado()` / `rutaCandado()`.

- [ ] **Step 3: Implementación**

En `SatPipelineDirs`: agregar debajo de `CARPETAS`:

```php
    public const PROCESADAS = 'cfdis_processed';
```

Reemplazar la propiedad y el constructor por:

```php
    private string $raiz;
    private string $candado;

    public function __construct(string $raiz = 'C:/tareasprogramadas/invoice/', ?string $candado = null)
    {
        $this->raiz = rtrim(str_replace('\\', '/', $raiz), '/') . '/';
        // Mismo candado que process_xml: evita que el comando de reorganización y
        // una importación muevan archivos al mismo tiempo
        $this->candado = $candado ?? sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sat_process_xml.lock';
    }

    public function rutaCandado(): string
    {
        return $this->candado;
    }

    public function rutaProcesadas(): string
    {
        return $this->raiz . self::PROCESADAS . '/';
    }

    /**
     * Carpeta donde queda un XML ya importado: {RFC}/{emitidas|recibidas}/{AAAA}/{MM}/.
     * El RFC es el de la empresa del grupo según cómo se descargó: emisor en las
     * emitidas, receptor en las recibidas. Sin RFC o fecha válidos → sin_clasificar/.
     * Si la carpeta no se puede crear regresa la raíz de procesadas (lo de antes),
     * para que un XML ya importado nunca termine apartado como error.
     */
    public function rutaProcesado(string $type, ?string $rfcEmisor, ?string $rfcReceptor, ?string $fecha, bool $crear = true): string
    {
        $emitida = $type === 'emited';
        $rfc = preg_replace('/[^A-Z0-9&Ñ]/u', '', mb_strtoupper(trim((string) ($emitida ? $rfcEmisor : $rfcReceptor))));
        $fechaOk = preg_match('/^(\d{4})-(\d{2})-(\d{2})/', (string) $fecha, $m) === 1
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);

        $relativa = ($rfc !== '' && $fechaOk)
            ? $rfc . '/' . ($emitida ? 'emitidas' : 'recibidas') . '/' . $m[1] . '/' . $m[2] . '/'
            : 'sin_clasificar/';
        $dir = $this->rutaProcesadas() . $relativa;

        if ($crear) {
            try {
                if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
                    return $this->rutaProcesadas();
                }
            } catch (\Throwable $e) {
                return $this->rutaProcesadas();
            }
        }
        return $dir;
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php vendor/bin/phpunit tests/Unit/SatPipelineDirsRutaProcesadoTest.php && php vendor/bin/phpunit`
Expected: `OK (7 tests, …)` y la suite completa en verde.

- [ ] **Step 5: Commit (checkpoint — solo con autorización del usuario)**

```bash
git add app/Services/SatPipelineDirs.php tests/Unit/SatPipelineDirsRutaProcesadoTest.php
git commit -m "feat(sat): regla de ruta de procesados por empresa/tipo/año/mes"
```

---

### Task 2: El proceso guarda en la nueva estructura y se elimina `delete_xml_processed`

**Files:**
- Modify: `app/Http/Controllers/FacturaController.php` (`process_xml` ~686-743, `procesarArchivosXML` ~745-1000, `delete_xml_processed` ~1244-1268)
- Modify: `routes/api.php` (línea `Route::post('/delete_xml_processed', 'delete_xml_processed');`)

**Interfaces:**
- Consumes: `SatPipelineDirs::rutaProcesado(string $type, ?string $rfcEmisor, ?string $rfcReceptor, ?string $fecha, bool $crear = true): string`, `SatPipelineDirs::rutaCandado(): string` (Task 1).

- [ ] **Step 1: Candado desde el servicio**

En `process_xml`, reemplazar:

```php
        $lock = fopen(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sat_process_xml.lock', 'c');
```
por:
```php
        $lock = fopen(app(SatPipelineDirs::class)->rutaCandado(), 'c');
```

- [ ] **Step 2: Rename de "ya procesado"**

En `procesarArchivosXML`, dentro de `if ($existingFactura) {`, reemplazar:

```php
                    $newLocation = $directory_final . basename($file);
                    $moveResult = rename($file, $newLocation);
```
por:
```php
                    $destinoDir = $dirs->rutaProcesado($type, $facturaData['EmisorRfc'] ?? null, $facturaData['ReceptorRfc'] ?? null, $facturaData['Fecha'] ?? null);
                    $newLocation = $destinoDir . basename($file);
                    $moveResult = rename($file, $newLocation);
```
y en ese mismo bloque `'destination' => $directory_final,` → `'destination' => $destinoDir,`.

- [ ] **Step 3: Rename tras importar**

Después de `DB::commit();`, reemplazar el bloque desde `$newLocation = $directory_final . basename($file);` hasta el cierre del `if/else` por:

```php
                $destinoDir = $dirs->rutaProcesado($type, $facturaData['EmisorRfc'] ?? null, $facturaData['ReceptorRfc'] ?? null, $facturaData['Fecha'] ?? null);
                $newLocation = $destinoDir . basename($file);
                if (rename($file, $newLocation)) {
                    $processLog['logs'][] = [
                        'file' => basename($file),
                        'status' => 'moved',
                        'message' => "File moved successfully to {$destinoDir}",
                        'destination' => $destinoDir
                    ];
                } else {
                    $processLog['logs'][] = [
                        'file' => basename($file),
                        'status' => 'error',
                        'message' => "Failed to move file to {$destinoDir}",
                        'destination' => $destinoDir
                    ];
                }
```

`$dirs` ya existe al inicio de `procesarArchivosXML` (`$dirs = app(SatPipelineDirs::class);`). Borrar la línea `$directory_final = 'C:/tareasprogramadas/invoice/cfdis_processed/';` de `procesarArchivosXML` si ya no se usa ahí (verificar con búsqueda dentro del método).

- [ ] **Step 4: Eliminar `delete_xml_processed`**

Borrar el método completo `public function delete_xml_processed(){ … }` de `FacturaController.php` y la línea `Route::post('/delete_xml_processed', 'delete_xml_processed');` de `routes/api.php`.

- [ ] **Step 5: Verificar**

Run:
```bash
php -l app/Http/Controllers/FacturaController.php
grep -n "directory_final\|delete_xml_processed\|sat_process_xml.lock" app/Http/Controllers/FacturaController.php routes/api.php
php vendor/bin/phpunit
php artisan route:list --path=facturas
```
Expected: sin errores de sintaxis; el `grep` no devuelve nada; suite en verde; `route:list` ya no muestra `delete_xml_processed` y sí `process_xml` y `pipeline_status`.

- [ ] **Step 6: Commit (checkpoint — solo con autorización del usuario)**

```bash
git add app/Http/Controllers/FacturaController.php routes/api.php
git commit -m "feat(sat): process_xml guarda procesados por empresa/tipo/mes; quita delete_xml_processed"
```

---

### Task 3: Buscador de UUID en BD

**Files:**
- Create: `app/Services/ProcesadasBuscadorBd.php`

**Interfaces:**
- Produces: `App\Services\ProcesadasBuscadorBd::buscar(array $uuids): array` — recibe UUID (cualquier caso) y regresa
  `[UUID_EN_MAYUSCULAS => ['emitida' => ['rfc' => string, 'fecha' => string]?, 'recibida' => ['rfc' => string, 'fecha' => string]?]]`
  (solo los UUID encontrados; si hay varias filas en una tabla, la de menor `Id`).

- [ ] **Step 1: Implementación**

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Dice cómo se descargó cada XML procesado: estar en Facturas = se bajó como
 * emitida (RFC = emisor); en FacturasRecibidas = como recibida (RFC = receptor).
 * Aislado en su clase para que el comando se pruebe sin BD.
 */
class ProcesadasBuscadorBd
{
    /** @param string[] $uuids máx. ~2000 por llamada (límite de parámetros de SQL Server) */
    public function buscar(array $uuids): array
    {
        $uuids = array_values(array_unique(array_map('strtoupper', $uuids)));
        $r = [];
        if (!$uuids) {
            return $r;
        }
        $emitidas = DB::table('Facturas')->whereIn('UUID', $uuids)->orderBy('Id')->get(['UUID', 'EmisorRfc', 'Fecha']);
        foreach ($emitidas as $f) {
            $r[strtoupper($f->UUID)]['emitida'] ??= ['rfc' => (string) $f->EmisorRfc, 'fecha' => (string) $f->Fecha];
        }
        $recibidas = DB::table('FacturasRecibidas')->whereIn('UUID', $uuids)->orderBy('Id')->get(['UUID', 'ReceptorRfc', 'Fecha']);
        foreach ($recibidas as $f) {
            $r[strtoupper($f->UUID)]['recibida'] ??= ['rfc' => (string) $f->ReceptorRfc, 'fecha' => (string) $f->Fecha];
        }
        return $r;
    }
}
```

- [ ] **Step 2: Verificar contra la BD real (solo lectura)**

Run:
```bash
php -l app/Services/ProcesadasBuscadorBd.php
php artisan tinker --execute="dump(app(App\Services\ProcesadasBuscadorBd::class)->buscar(['fd41210a-6720-43e6-85cc-6f0ccd5d13a1','00000000-0000-0000-0000-000000000000']));"
```
Expected: un solo elemento con llave `FD41210A-6720-43E6-85CC-6F0CCD5D13A1` que trae `emitida.rfc = DCL880518UG2` y `recibida.rfc = DGM880621FU5`, ambas con fecha `2026-09-01 10:07:41…`; el UUID de ceros no aparece.

- [ ] **Step 3: Commit (checkpoint — solo con autorización del usuario)**

```bash
git add app/Services/ProcesadasBuscadorBd.php
git commit -m "feat(sat): buscador de UUID procesados en Facturas/FacturasRecibidas"
```

---

### Task 4: Comando `sat:reorganizar-procesadas`

**Files:**
- Create: `app/Console/Commands/ReorganizarProcesadas.php`
- Test: `tests/Feature/ReorganizarProcesadasTest.php`

**Interfaces:**
- Consumes: `SatPipelineDirs::{rutaProcesadas(), rutaProcesado(...), rutaCandado()}` (Task 1); `ProcesadasBuscadorBd::buscar(array $uuids): array` (Task 3).
- Produces: comando `sat:reorganizar-procesadas {--dry-run} {--limit=0} {--revertir=} {--bitacora-dir=}`; exit 0 en éxito, 1 si el candado está ocupado o la bitácora a revertir no existe.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Services\ProcesadasBuscadorBd;
use App\Services\SatPipelineDirs;
use Tests\TestCase;

class ReorganizarProcesadasTest extends TestCase
{
    private string $tmp;
    private string $proc;
    private string $bitacoras;
    private string $candado;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/reorg_' . uniqid() . '/';
        $this->proc = $this->tmp . 'cfdis_processed/';
        $this->bitacoras = $this->tmp . 'logs';
        $this->candado = $this->tmp . 'test.lock';
        mkdir($this->proc, 0777, true);
        mkdir($this->bitacoras, 0777, true);

        // A: emitida · B: en ambas tablas (intercompañía) · C: en ninguna
        file_put_contents($this->proc . 'aaaa-1.xml', 'A');
        file_put_contents($this->proc . 'bbbb-2.xml', 'B');
        file_put_contents($this->proc . 'cccc-3.xml', 'C');

        $this->app->instance(SatPipelineDirs::class, new SatPipelineDirs($this->tmp, $this->candado));
        $this->app->instance(ProcesadasBuscadorBd::class, new class extends ProcesadasBuscadorBd {
            public function buscar(array $uuids): array
            {
                $mapa = [
                    'AAAA-1' => ['emitida' => ['rfc' => 'DGA930823KD3', 'fecha' => '2026-09-10 08:00:00.000']],
                    'BBBB-2' => ['emitida' => ['rfc' => 'DCL880518UG2', 'fecha' => '2026-08-01 10:00:00.000'],
                                 'recibida' => ['rfc' => 'DGM880621FU5', 'fecha' => '2026-08-01 10:00:00.000']],
                ];
                return array_intersect_key($mapa, array_flip(array_map('strtoupper', $uuids)));
            }
        });
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) { $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
        rmdir($this->tmp);
        parent::tearDown();
    }

    private function correr(array $opciones = []): \Illuminate\Testing\PendingCommand
    {
        return $this->artisan('sat:reorganizar-procesadas', $opciones + ['--bitacora-dir' => $this->bitacoras]);
    }

    private function bitacorasReales(): array
    {
        $b = glob($this->bitacoras . '/reorganizar-procesadas-2*.csv');
        sort($b);
        return $b;
    }

    public function test_dry_run_no_mueve_ni_crea_carpetas_y_deja_reporte(): void
    {
        $this->correr(['--dry-run' => true])->assertExitCode(0);

        $this->assertFileExists($this->proc . 'aaaa-1.xml');
        $this->assertDirectoryDoesNotExist($this->proc . 'DGA930823KD3');
        $reporte = glob($this->bitacoras . '/reorganizar-procesadas-dryrun-*.csv');
        $this->assertCount(1, $reporte);
        $csv = file_get_contents($reporte[0]);
        $this->assertStringContainsString('DGA930823KD3/emitidas/2026/09', $csv);
        $this->assertStringContainsString('DCL880518UG2/emitidas/2026/08', $csv);
        $this->assertStringContainsString('DGM880621FU5/recibidas/2026/08', $csv);
        $this->assertStringContainsString('sin_clasificar', $csv);
        $this->assertStringContainsString('cccc-3', $csv);
    }

    public function test_corrida_real_ordena_copia_intercompania_y_manda_huerfanos_a_sin_clasificar(): void
    {
        $this->correr()->assertExitCode(0);

        $this->assertSame('A', file_get_contents($this->proc . 'DGA930823KD3/emitidas/2026/09/aaaa-1.xml'));
        $this->assertSame('B', file_get_contents($this->proc . 'DCL880518UG2/emitidas/2026/08/bbbb-2.xml'));
        $this->assertSame('B', file_get_contents($this->proc . 'DGM880621FU5/recibidas/2026/08/bbbb-2.xml'));
        $this->assertSame('C', file_get_contents($this->proc . 'sin_clasificar/cccc-3.xml'));
        $this->assertSame([], glob($this->proc . '*.xml'));

        $filas = array_map('str_getcsv', file($this->bitacorasReales()[0], FILE_IGNORE_NEW_LINES));
        $acciones = array_count_values(array_column(array_slice($filas, 1), 0));
        $this->assertSame(['move' => 3, 'copy' => 1], $acciones);
    }

    public function test_segunda_corrida_no_hace_nada(): void
    {
        $this->correr()->assertExitCode(0);
        $this->correr()->expectsOutputToContain('Vistos: 0')->assertExitCode(0);
        $this->assertCount(2, $this->bitacorasReales()); // nombres distintos aunque sea el mismo segundo
    }

    public function test_revertir_deja_la_raiz_como_estaba(): void
    {
        $this->correr()->assertExitCode(0);
        $bitacora = $this->bitacorasReales()[0];

        $this->correr(['--revertir' => $bitacora])->assertExitCode(0);

        foreach (['aaaa-1' => 'A', 'bbbb-2' => 'B', 'cccc-3' => 'C'] as $uuid => $contenido) {
            $this->assertSame($contenido, file_get_contents($this->proc . "$uuid.xml"));
        }
        // Solo deben quedar los 3 de la raíz: ni originales en subcarpetas ni la copia intercompañía
        $total = 0;
        $todos = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->proc, \FilesystemIterator::SKIP_DOTS));
        foreach ($todos as $f) {
            if ($f->isFile()) $total++;
        }
        $this->assertCount(3, glob($this->proc . '*.xml'));
        $this->assertSame(3, $total);
    }

    public function test_limit_procesa_solo_n(): void
    {
        $this->correr(['--limit' => 1])->assertExitCode(0);
        $this->assertCount(2, glob($this->proc . '*.xml'));
    }

    public function test_candado_ocupado_sale_sin_mover(): void
    {
        $h = fopen($this->candado, 'c');
        flock($h, LOCK_EX);
        try {
            $this->correr()->assertExitCode(1);
        } finally {
            flock($h, LOCK_UN);
            fclose($h);
        }
        $this->assertCount(3, glob($this->proc . '*.xml'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/Feature/ReorganizarProcesadasTest.php`
Expected: FAIL — `The command "sat:reorganizar-procesadas" does not exist.`

- [ ] **Step 3: Implementación**

```php
<?php

namespace App\Console\Commands;

use App\Services\ProcesadasBuscadorBd;
use App\Services\SatPipelineDirs;
use Illuminate\Console\Command;

/**
 * Ordena los XML sueltos de cfdis_processed en {RFC}/{emitidas|recibidas}/{AAAA}/{MM}/
 * con la misma regla que process_xml. Solo toma archivos de la raíz, así que se puede
 * interrumpir y volver a correr; cada corrida real deja una bitácora que --revertir deshace.
 */
class ReorganizarProcesadas extends Command
{
    protected $signature = 'sat:reorganizar-procesadas
                            {--dry-run : Solo reporta a dónde iría cada archivo; no mueve ni crea carpetas}
                            {--limit=0 : Máximo de archivos a procesar (0 = todos)}
                            {--revertir= : Bitácora CSV de una corrida real para deshacerla}
                            {--bitacora-dir= : Carpeta de bitácoras (default storage/logs)}';

    protected $description = 'Reorganiza los XML procesados por empresa, tipo, año y mes de emisión';

    private const LOTE = 1000;

    public function handle(SatPipelineDirs $dirs, ProcesadasBuscadorBd $buscador): int
    {
        $lock = @fopen($dirs->rutaCandado(), 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            $this->error('Hay una importación en curso; intenta más tarde.');
            return self::FAILURE;
        }
        try {
            if ($this->option('revertir')) {
                return $this->revertir((string) $this->option('revertir'));
            }
            return $this->reorganizar($dirs, $buscador, (bool) $this->option('dry-run'), max(0, (int) $this->option('limit')));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function reorganizar(SatPipelineDirs $dirs, ProcesadasBuscadorBd $buscador, bool $dryRun, int $limit): int
    {
        $raiz = $dirs->rutaProcesadas();
        $archivos = [];
        if (is_dir($raiz)) {
            foreach (new \DirectoryIterator($raiz) as $f) {
                if ($f->isFile() && strtolower($f->getExtension()) === 'xml') {
                    $archivos[] = $raiz . $f->getFilename();
                    if ($limit > 0 && count($archivos) >= $limit) {
                        break;
                    }
                }
            }
        }

        $bitacora = $this->abrirBitacora($dryRun ? 'dryrun-' : '');
        fputcsv($bitacora['h'], $dryRun ? ['tipo', 'clave', 'cantidad'] : ['accion', 'origen', 'destino', 'detalle']);

        $cont = ['vistos' => 0, 'movidos' => 0, 'copiados' => 0, 'sin_clasificar' => 0, 'errores' => 0];
        $grupos = [];
        $huerfanos = [];

        foreach (array_chunk($archivos, self::LOTE) as $lote) {
            $info = $buscador->buscar(array_map(fn ($a) => strtoupper(pathinfo($a, PATHINFO_FILENAME)), $lote));
            foreach ($lote as $archivo) {
                $cont['vistos']++;
                $uuid = strtoupper(pathinfo($archivo, PATHINFO_FILENAME));
                $destinos = $this->destinos($dirs, $info[$uuid] ?? [], !$dryRun);
                if (!isset($info[$uuid])) {
                    $cont['sin_clasificar']++;
                    $huerfanos[] = pathinfo($archivo, PATHINFO_FILENAME);
                }

                if ($dryRun) {
                    foreach ($destinos as $d) {
                        $clave = rtrim(substr($d, strlen($raiz)), '/');
                        $grupos[$clave] = ($grupos[$clave] ?? 0) + 1;
                    }
                    continue;
                }
                $this->mover($archivo, $destinos, $raiz, $bitacora['h'], $cont);
            }
        }

        if ($dryRun) {
            ksort($grupos);
            foreach ($grupos as $clave => $n) {
                fputcsv($bitacora['h'], ['grupo', $clave, $n]);
            }
            foreach ($huerfanos as $u) {
                fputcsv($bitacora['h'], ['sin_clasificar', $u, '']);
            }
        }
        fclose($bitacora['h']);

        $this->info(sprintf('Vistos: %d · movidos: %d · copiados: %d · sin_clasificar: %d · errores: %d%s',
            $cont['vistos'], $cont['movidos'], $cont['copiados'], $cont['sin_clasificar'], $cont['errores'],
            $dryRun ? ' (dry-run: no se movió nada)' : ''));
        $this->line(($dryRun ? 'Reporte: ' : 'Bitácora: ') . $bitacora['ruta']);
        return self::SUCCESS;
    }

    /** Carpetas destino según cómo se descargó; ninguna fila en BD → sin_clasificar. */
    private function destinos(SatPipelineDirs $dirs, array $fila, bool $crear): array
    {
        $d = [];
        if (isset($fila['emitida'])) {
            $d[] = $dirs->rutaProcesado('emited', $fila['emitida']['rfc'], null, $fila['emitida']['fecha'], $crear);
        }
        if (isset($fila['recibida'])) {
            $d[] = $dirs->rutaProcesado('received', null, $fila['recibida']['rfc'], $fila['recibida']['fecha'], $crear);
        }
        return $d ?: [$dirs->rutaProcesado('emited', null, null, null, $crear)]; // sin_clasificar/
    }

    /** Copia a todos los destinos menos el último y mueve al último; nunca borra sin copiar/mover antes. */
    private function mover(string $archivo, array $destinos, string $raiz, $bitacora, array &$cont): void
    {
        $nombre = basename($archivo);
        foreach ($destinos as $d) {
            if ($d === $raiz) { // no se pudo crear la carpeta destino
                fputcsv($bitacora, ['error', $archivo, $d, 'no se pudo crear la carpeta destino']);
                $cont['errores']++;
                return;
            }
        }
        $ultimo = array_pop($destinos);
        foreach ($destinos as $d) {
            try {
                if (!copy($archivo, $d . $nombre)) {
                    throw new \RuntimeException('copy devolvió false');
                }
                fputcsv($bitacora, ['copy', $archivo, $d . $nombre, '']);
                $cont['copiados']++;
            } catch (\Throwable $e) {
                fputcsv($bitacora, ['error', $archivo, $d . $nombre, $e->getMessage()]);
                $cont['errores']++;
                return;
            }
        }
        try {
            if (!rename($archivo, $ultimo . $nombre)) {
                throw new \RuntimeException('rename devolvió false');
            }
            fputcsv($bitacora, ['move', $archivo, $ultimo . $nombre, '']);
            $cont['movidos']++;
        } catch (\Throwable $e) {
            fputcsv($bitacora, ['error', $archivo, $ultimo . $nombre, $e->getMessage()]);
            $cont['errores']++;
        }
    }

    private function revertir(string $ruta): int
    {
        if (!is_file($ruta)) {
            $this->error("No existe la bitácora: {$ruta}");
            return self::FAILURE;
        }
        $filas = array_map('str_getcsv', file($ruta, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        array_shift($filas); // encabezado
        $regresados = 0;
        $borradas = 0;
        $errores = 0;
        foreach (array_reverse($filas) as [$accion, $origen, $destino]) {
            try {
                if ($accion === 'move' && is_file($destino) && !file_exists($origen)) {
                    rename($destino, $origen) ? $regresados++ : $errores++;
                } elseif ($accion === 'copy' && is_file($destino)) {
                    unlink($destino) ? $borradas++ : $errores++;
                }
            } catch (\Throwable $e) {
                $errores++;
                $this->warn("{$accion} {$destino}: {$e->getMessage()}");
            }
        }
        $this->info("Revertido: {$regresados} regresados a la raíz · {$borradas} copias borradas · {$errores} errores");
        return self::SUCCESS;
    }

    private function abrirBitacora(string $prefijo): array
    {
        $dir = rtrim((string) ($this->option('bitacora-dir') ?: storage_path('logs')), '/\\');
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $ruta = $dir . DIRECTORY_SEPARATOR . 'reorganizar-procesadas-' . $prefijo . date('Ymd-His') . '-' . substr(uniqid(), -6) . '.csv';
        return ['ruta' => $ruta, 'h' => fopen($ruta, 'w')];
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php vendor/bin/phpunit tests/Feature/ReorganizarProcesadasTest.php && php vendor/bin/phpunit && php artisan list sat`
Expected: `OK (6 tests, …)`; suite completa en verde; `php artisan list sat` muestra `sat:reorganizar-procesadas`.

- [ ] **Step 5: Commit (checkpoint — solo con autorización del usuario)**

```bash
git add app/Console/Commands/ReorganizarProcesadas.php tests/Feature/ReorganizarProcesadasTest.php
git commit -m "feat(sat): comando sat:reorganizar-procesadas con dry-run, bitácora y revertir"
```

---

### Task 5: Entrega (controlador)

- [ ] **Step 1:** Lista para FTP a `C:\inetpub\wwwroot\ApiTotal\`: `app/Services/SatPipelineDirs.php`, `app/Services/ProcesadasBuscadorBd.php` (nuevo), `app/Console/Commands/ReorganizarProcesadas.php` (nuevo), `app/Http/Controllers/FacturaController.php`, `routes/api.php`. Después: `php artisan route:clear` y `php artisan list sat` en el servidor.
- [ ] **Step 2:** Instrucciones de ejecución: `php artisan sat:reorganizar-procesadas --dry-run` → revisar el reporte → corrida real (fuera de 01:15–02:00, sin importación en curso); `--limit` para ir por partes; `--revertir=<bitácora>` para deshacer.
- [ ] **Step 3:** Actualizar la memoria `llamadas-sat-descarga-masiva.md` con la nueva estructura y el comando.
