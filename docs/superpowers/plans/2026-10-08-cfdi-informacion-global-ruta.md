# InformacionGlobal, FechaImportacion y RutaArchivo en CFDI de TGV2 — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Guardar el nodo `cfdi:InformacionGlobal` (Periodicidad, Meses, Año), la fecha de importación y la ruta del XML en `TGV2.dbo.Facturas` y `TGV2.dbo.FacturasRecibidas`, tanto en las importaciones nuevas como en las facturas que ya están en la BD (backfill desde `cfdis_processed`).

**Architecture:** Un script SQL idempotente agrega 5 columnas a cada tabla (+ índice en `FacturasRecibidas.UUID`). Una clase `CfdiInformacionGlobal` lee el nodo desde el `SimpleXMLElement` del importador o desde un archivo con `XMLReader` (se detiene antes de los conceptos: las globales traen miles). El importador (`FacturaController::procesarArchivosXML`) llena las columnas al insertar; un comando nuevo `sat:completar-datos-cfdi` recorre `cfdis_processed\{RFC}\{emitidas|recibidas}\AAAA\MM\UUID.xml` y actualiza en lotes las filas que aún no tienen `RutaArchivo`.

**Tech Stack:** Laravel 11 (ApiTotal, PHP 8.2), SQL Server 2019 Standard (TGV2 en 192.168.0.6), PHPUnit (`php artisan test`), AplicativoPhp (PHP + Twig) para mostrarlo.

**Spec:** este documento (sección *Contexto y decisiones*). Origen: conversación 2026-10-08 sobre el XML global `ANAPRAZ-4329` de DGA930823KD3.

## Contexto y decisiones

- `extraerDatosFactura()` (`ApiTotal/app/Http/Controllers/FacturaController.php`) no lee `InformacionGlobal`; ninguna tabla tiene columnas para ello. Hay **44,106** emitidas a `XAXX010101000` en `Facturas` (1.42 M filas desde 2023). `FacturasRecibidas` (96,808 filas) no tiene globales (solo las emite uno mismo), pero recibe las mismas columnas para que ambas tablas sigan iguales.
- **Columnas nuevas (ambas tablas):** `GlobalPeriodicidad nvarchar(2) NULL`, `GlobalMeses nvarchar(2) NULL`, `GlobalAnio smallint NULL`, `FechaImportacion datetime NULL DEFAULT GETDATE()`, `RutaArchivo nvarchar(400) NULL`.
  - El `DEFAULT GETDATE()` hace que cualquier insert (incluso el código viejo que hoy corre en 4 consolas) estampe la fecha **desde que corre el DDL**. Se usa la hora del SQL Server (local) y no la de PHP (ApiTotal corre en UTC).
  - Filas existentes: `FechaImportacion` queda **NULL** (no hay de dónde sacarla con verdad; la fecha del archivo es la de descompresión, no la de importación).
  - `RutaArchivo` = ruta completa con `/` (`C:/tareasprogramadas/invoice/cfdis_processed/DGA930823KD3/emitidas/2026/08/<UUID>.xml`). Intercompañía: cada tabla guarda la ruta de su propia copia.
- **Índice nuevo:** `IX_FacturasRecibidas_UUID` **no único** (hay 2 UUID duplicados en `FacturasRecibidas`). `Facturas` ya tiene `UX_Facturas_UUID`.
- **Cobertura del backfill:** solo hay XML en disco desde ~oct-2025 (lo anterior se borró con `delete_xml_processed`). Lo anterior queda NULL; el comando reporta cuántas filas cubrió. Re-descargar del SAT queda fuera de alcance.
- **Archivos en la raíz de `cfdis_processed`** (reorganización incompleta) se **omiten** con aviso: si se guardara esa ruta, `sat:reorganizar-procesadas` la dejaría obsoleta al moverlos.
- Catálogo SAT para mostrarlo: Periodicidad `01` Diario, `02` Semanal, `03` Quincenal, `04` Mensual, `05` Bimestral. Meses `01`–`12`, y `13` Ene-Feb … `18` Nov-Dic.

## Global Constraints

- Orden de despliegue obligatorio: **1) correr el SQL (Task 1) → 2) subir ApiTotal por FTP → 3) subir AplicativoPhp**. El código nuevo inserta columnas que no existen sin el DDL.
- El SQL debe poder correrse dos veces sin error (`IF COL_LENGTH(...) IS NULL`, `IF NOT EXISTS ... sys.indexes`).
- Lotes de UPDATE ≤ 300 filas × 5 parámetros (límite de 2100 parámetros de SQL Server).
- El backfill nunca sobrescribe: solo filas con `RutaArchivo IS NULL`; `Global*` con `COALESCE` (no pisa lo ya llenado por el importador).
- ApiTotal en el servidor no es git: al terminar, listar los archivos tocados para FTP (`C:\inetpub\wwwroot\ApiTotal`).
- Lecturas desde AplicativoPhp siguen con `WITH (NOLOCK)` (ver `SatFacturasModel`).

## Review Focus

1. **Código nuevo desplegado antes del DDL** → cada insert truena con "Invalid column name" y el importador aparta XML válidos a `cfdis_error`. Esperado: el importador y el comando se niegan a arrancar con un mensaje claro si faltan las columnas (guard `columnasListas()`, Task 3/4).
2. **Atributo `Año` con ñ** (nombre de atributo no ASCII en UTF-8) → debe leerse igual que `Periodicidad`/`Meses`; test explícito en Task 2.
3. **XML global gigante o cortado después del encabezado** (este ejemplo trae ~1,000 conceptos) → la lectura desde archivo debe salir antes de `Conceptos` y funcionar aunque el resto del XML esté truncado/malformado; test en Task 2.
4. **Archivos en la raíz o en `sin_clasificar`** → raíz: se omiten con aviso; `sin_clasificar`: se intenta en ambas tablas (el UPDATE solo pega donde existe el UUID); test en Task 4.
5. **Mismo UUID en `emitidas` y `recibidas`** (intercompañía) → cada tabla recibe la ruta de su copia, nunca la del otro lado; test en Task 4.

---

### Task 1: Script SQL de columnas e índice

**Files:**
- Create: `ApiTotal/database/sql/2026-10-08-facturas-global-ruta.sql`

**Interfaces:**
- Produces: columnas `GlobalPeriodicidad`, `GlobalMeses`, `GlobalAnio`, `FechaImportacion`, `RutaArchivo` en `TGV2.dbo.Facturas` y `TGV2.dbo.FacturasRecibidas`; índice `IX_FacturasRecibidas_UUID`.

- [ ] **Step 1: Escribir el script**

```sql
-- 2026-10-08 · InformacionGlobal + FechaImportacion + RutaArchivo en CFDI de TGV2.
-- Idempotente. Agregar columnas NULL (con o sin DEFAULT) es solo metadatos en SQL 2019:
-- no reescribe la tabla, pero espera un instante a que terminen los inserts en curso.
USE TGV2;
SET XACT_ABORT ON;

IF COL_LENGTH('dbo.Facturas', 'GlobalPeriodicidad') IS NULL
    ALTER TABLE dbo.Facturas ADD
        GlobalPeriodicidad nvarchar(2)   NULL,
        GlobalMeses        nvarchar(2)   NULL,
        GlobalAnio         smallint      NULL,
        FechaImportacion   datetime      NULL CONSTRAINT DF_Facturas_FechaImportacion DEFAULT (GETDATE()),
        RutaArchivo        nvarchar(400) NULL;

IF COL_LENGTH('dbo.FacturasRecibidas', 'GlobalPeriodicidad') IS NULL
    ALTER TABLE dbo.FacturasRecibidas ADD
        GlobalPeriodicidad nvarchar(2)   NULL,
        GlobalMeses        nvarchar(2)   NULL,
        GlobalAnio         smallint      NULL,
        FechaImportacion   datetime      NULL CONSTRAINT DF_FacturasRecibidas_FechaImportacion DEFAULT (GETDATE()),
        RutaArchivo        nvarchar(400) NULL;

-- No único: FacturasRecibidas tiene UUID duplicados (2 al 2026-10-08)
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_FacturasRecibidas_UUID'
                AND object_id = OBJECT_ID('dbo.FacturasRecibidas'))
    CREATE NONCLUSTERED INDEX IX_FacturasRecibidas_UUID ON dbo.FacturasRecibidas (UUID);

-- Verificación
SELECT t.name AS tabla, c.name AS columna, TYPE_NAME(c.user_type_id) AS tipo, c.max_length
  FROM sys.columns c JOIN sys.tables t ON t.object_id = c.object_id
 WHERE t.name IN ('Facturas', 'FacturasRecibidas')
   AND c.name IN ('GlobalPeriodicidad', 'GlobalMeses', 'GlobalAnio', 'FechaImportacion', 'RutaArchivo')
 ORDER BY t.name, c.column_id;
```

- [ ] **Step 2: Dar el script al usuario para que lo corra** (es DDL en producción: requiere su autorización explícita; el harness bloquea escritura vía sqlcmd). Momento sugerido: con la importación detenida o entre lotes.

- [ ] **Step 3: Verificar (lectura, permitido)**

Run: `sqlcmd ... -d TGV2 -Q "SELECT COL_LENGTH('dbo.Facturas','RutaArchivo'), COL_LENGTH('dbo.FacturasRecibidas','RutaArchivo'), INDEXPROPERTY(OBJECT_ID('dbo.FacturasRecibidas'),'IX_FacturasRecibidas_UUID','IndexID')"`
Expected: `800 | 800 | <número>` (nvarchar(400) = 800 bytes)

- [ ] **Step 4: Commit**

```bash
git -C ../ApiTotal add database/sql/2026-10-08-facturas-global-ruta.sql
git -C ../ApiTotal commit -m "feat(sat): columnas InformacionGlobal, FechaImportacion y RutaArchivo en Facturas/FacturasRecibidas"
```

---

### Task 2: Lector `CfdiInformacionGlobal`

**Files:**
- Create: `ApiTotal/app/Services/CfdiInformacionGlobal.php`
- Test: `ApiTotal/tests/Unit/CfdiInformacionGlobalTest.php`

**Interfaces:**
- Produces:
  - `CfdiInformacionGlobal::VACIO` → `['GlobalPeriodicidad' => null, 'GlobalMeses' => null, 'GlobalAnio' => null]`
  - `CfdiInformacionGlobal::desdeXml(\SimpleXMLElement $xml): array` → mismas 3 llaves (namespace `cfdi` ya registrado, como en el importador)
  - `CfdiInformacionGlobal::desdeArchivo(string $ruta): ?array` → mismas 3 llaves; `null` si el archivo no se puede abrir o el encabezado está roto antes de encontrar `Emisor`

- [ ] **Step 1: Escribir los tests que fallan**

```php
<?php

namespace Tests\Unit;

use App\Services\CfdiInformacionGlobal;
use PHPUnit\Framework\TestCase;

class CfdiInformacionGlobalTest extends TestCase
{
    private const CABECERA = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" Version="4.0" Serie="ANAPRAZ" Folio="4329">';

    private function xml(string $cuerpo): \SimpleXMLElement
    {
        $x = simplexml_load_string(self::CABECERA . $cuerpo . '</cfdi:Comprobante>');
        $x->registerXPathNamespace('cfdi', 'http://www.sat.gob.mx/cfd/4');
        return $x;
    }

    private function archivo(string $contenido): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'cfdi');
        file_put_contents($ruta, $contenido);
        return $ruta;
    }

    public function test_lee_periodicidad_meses_y_anio_con_enie(): void
    {
        $x = $this->xml('<cfdi:InformacionGlobal Periodicidad="01" Meses="08" Año="2026"/><cfdi:Emisor Rfc="DGA930823KD3"/>');
        $this->assertSame(['GlobalPeriodicidad' => '01', 'GlobalMeses' => '08', 'GlobalAnio' => 2026],
            CfdiInformacionGlobal::desdeXml($x));
    }

    public function test_sin_nodo_regresa_vacio(): void
    {
        $x = $this->xml('<cfdi:Emisor Rfc="DGA930823KD3"/>');
        $this->assertSame(CfdiInformacionGlobal::VACIO, CfdiInformacionGlobal::desdeXml($x));
    }

    public function test_valores_invalidos_se_descartan(): void
    {
        $x = $this->xml('<cfdi:InformacionGlobal Periodicidad="1" Meses="AB" Año="26"/>');
        $this->assertSame(CfdiInformacionGlobal::VACIO, CfdiInformacionGlobal::desdeXml($x));
    }

    public function test_desde_archivo_aunque_el_resto_este_truncado(): void
    {
        // Simula una global enorme cortada a la mitad de los conceptos: no debe leerse completa
        $ruta = $this->archivo(self::CABECERA
            . '<cfdi:InformacionGlobal Periodicidad="04" Meses="13" Año="2025"/>'
            . '<cfdi:Emisor Rfc="DGA930823KD3"/><cfdi:Conceptos><cfdi:Concepto Importe="1');
        $this->assertSame(['GlobalPeriodicidad' => '04', 'GlobalMeses' => '13', 'GlobalAnio' => 2025],
            CfdiInformacionGlobal::desdeArchivo($ruta));
        unlink($ruta);
    }

    public function test_desde_archivo_sin_global_regresa_vacio(): void
    {
        $ruta = $this->archivo(self::CABECERA . '<cfdi:Emisor Rfc="X"/><cfdi:Receptor Rfc="Y"/></cfdi:Comprobante>');
        $this->assertSame(CfdiInformacionGlobal::VACIO, CfdiInformacionGlobal::desdeArchivo($ruta));
        unlink($ruta);
    }

    public function test_desde_archivo_ilegible_regresa_null(): void
    {
        $ruta = $this->archivo('esto no es xml');
        $this->assertNull(CfdiInformacionGlobal::desdeArchivo($ruta));
        unlink($ruta);
        $this->assertNull(CfdiInformacionGlobal::desdeArchivo(sys_get_temp_dir() . '/no-existe-' . uniqid() . '.xml'));
    }
}
```

- [ ] **Step 2: Correr y ver que fallan**

Run: `php artisan test --filter=CfdiInformacionGlobalTest` (en `ApiTotal`)
Expected: FAIL con `Class "App\Services\CfdiInformacionGlobal" not found`

- [ ] **Step 3: Implementar**

```php
<?php

namespace App\Services;

/**
 * Nodo cfdi:InformacionGlobal de las facturas a público en general (XAXX010101000):
 * Periodicidad (01 diario … 05 bimestral), Meses (01-12, 13-18 bimestres) y Año.
 * Va antes de cfdi:Emisor, así que desde archivo se lee solo el encabezado: las
 * globales traen un concepto por despacho (miles) y no hace falta cargarlas.
 */
class CfdiInformacionGlobal
{
    public const VACIO = ['GlobalPeriodicidad' => null, 'GlobalMeses' => null, 'GlobalAnio' => null];

    /** Desde el XML que ya cargó el importador (namespace cfdi registrado). */
    public static function desdeXml(\SimpleXMLElement $xml): array
    {
        $nodo = $xml->xpath('/cfdi:Comprobante/cfdi:InformacionGlobal');
        if (!$nodo) {
            return self::VACIO;
        }
        $a = $nodo[0]->attributes();
        return self::normalizar((string) ($a['Periodicidad'] ?? ''), (string) ($a['Meses'] ?? ''), (string) ($a['Año'] ?? ''));
    }

    /** @return array|null null si el archivo no se puede leer hasta el Emisor */
    public static function desdeArchivo(string $ruta): ?array
    {
        if (!is_file($ruta)) {
            return null;
        }
        $previo = libxml_use_internal_errors(true);
        $r = new \XMLReader();
        try {
            if (!@$r->open($ruta, null, LIBXML_NONET | LIBXML_COMPACT)) {
                return null;
            }
            while (@$r->read()) {
                if ($r->nodeType !== \XMLReader::ELEMENT) {
                    continue;
                }
                if ($r->localName === 'InformacionGlobal') {
                    return self::normalizar((string) $r->getAttribute('Periodicidad'),
                        (string) $r->getAttribute('Meses'), (string) $r->getAttribute('Año'));
                }
                if (in_array($r->localName, ['Emisor', 'Receptor', 'Conceptos'], true)) {
                    return self::VACIO; // ya pasó donde iría el nodo: no es global
                }
            }
            return null; // se acabó o se rompió antes del Emisor
        } finally {
            $r->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previo);
        }
    }

    private static function normalizar(string $periodicidad, string $meses, string $anio): array
    {
        if (preg_match('/^\d{2}$/', $periodicidad) !== 1 || preg_match('/^\d{2}$/', $meses) !== 1
            || preg_match('/^\d{4}$/', $anio) !== 1) {
            return self::VACIO;
        }
        return ['GlobalPeriodicidad' => $periodicidad, 'GlobalMeses' => $meses, 'GlobalAnio' => (int) $anio];
    }
}
```

- [ ] **Step 4: Correr y ver que pasan**

Run: `php artisan test --filter=CfdiInformacionGlobalTest`
Expected: PASS (6 tests)

- [ ] **Step 5: Probar contra un XML real del servidor (solo lectura)**

Run: `php -r 'require "vendor/autoload.php"; var_dump(App\Services\CfdiInformacionGlobal::desdeArchivo($argv[1]));' "//192.168.0.3/c$/tareasprogramadas/invoice/cfdis_processed/DGA930823KD3/emitidas/2026/08/<UUID de una XAXX>.xml"` — el UUID se obtiene con `SELECT TOP 1 UUID FROM TGV2.dbo.Facturas WITH (NOLOCK) WHERE ReceptorRfc='XAXX010101000' AND Fecha >= '2026-08-01' AND Fecha < '2026-09-01'`.
Expected: `GlobalPeriodicidad => "01"`, `GlobalMeses => "08"`, `GlobalAnio => 2026`

- [ ] **Step 6: Commit**

```bash
git -C ../ApiTotal add app/Services/CfdiInformacionGlobal.php tests/Unit/CfdiInformacionGlobalTest.php
git -C ../ApiTotal commit -m "feat(sat): lector de cfdi:InformacionGlobal desde XML cargado o archivo"
```

---

### Task 3: Actualizador en lote + guard de columnas

**Files:**
- Create: `ApiTotal/app/Services/FacturasExtraBd.php`

**Interfaces:**
- Consumes: columnas de Task 1.
- Produces:
  - `FacturasExtraBd::TABLAS = ['Facturas', 'FacturasRecibidas']`
  - `FacturasExtraBd::columnasListas(): bool` — true si ambas tablas tienen las 5 columnas (se consulta una vez por proceso y se cachea)
  - `FacturasExtraBd::completar(string $tabla, array $filas): int` — `$filas` = lista de `['uuid' => string, 'ruta' => string, 'GlobalPeriodicidad' => ?string, 'GlobalMeses' => ?string, 'GlobalAnio' => ?int]`; regresa filas actualizadas; lanza `\InvalidArgumentException` si `$tabla` no está en `TABLAS`

Sin test unitario propio (es SQL contra SQL Server, igual que `ProcesadasBuscadorBd`): se prueba por el comando con un fake (Task 4) y en el servidor (Task 4, Step 6).

- [ ] **Step 1: Implementar**

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Columnas agregadas el 2026-10-08 (database/sql/2026-10-08-facturas-global-ruta.sql):
 * InformacionGlobal, FechaImportacion y RutaArchivo. Aislado en su clase para que el
 * comando de backfill se pruebe sin BD.
 */
class FacturasExtraBd
{
    public const TABLAS = ['Facturas', 'FacturasRecibidas'];
    public const COLUMNAS = ['GlobalPeriodicidad', 'GlobalMeses', 'GlobalAnio', 'FechaImportacion', 'RutaArchivo'];
    private const LOTE = 300; // 5 parámetros por fila: 1,500 < 2,100 de SQL Server

    private static ?bool $listas = null;

    /** Evita que código nuevo corra contra una BD sin el DDL (apartaría XML válidos a cfdis_error). */
    public function columnasListas(): bool
    {
        if (self::$listas === null) {
            $n = DB::selectOne(
                "SELECT COUNT(*) AS n FROM sys.columns
                  WHERE object_id IN (OBJECT_ID('dbo.Facturas'), OBJECT_ID('dbo.FacturasRecibidas'))
                    AND name IN ('" . implode("','", self::COLUMNAS) . "')"
            )->n ?? 0;
            self::$listas = (int) $n === count(self::COLUMNAS) * count(self::TABLAS);
        }
        return self::$listas;
    }

    /** Llena RutaArchivo (y Global* si faltan) solo en filas que aún no tienen ruta. */
    public function completar(string $tabla, array $filas): int
    {
        if (!in_array($tabla, self::TABLAS, true)) {
            throw new \InvalidArgumentException("Tabla no permitida: {$tabla}");
        }
        $total = 0;
        foreach (array_chunk($filas, self::LOTE) as $lote) {
            $params = [];
            foreach ($lote as $f) {
                array_push($params, $f['uuid'], $f['ruta'], $f['GlobalPeriodicidad'], $f['GlobalMeses'], $f['GlobalAnio']);
            }
            $valores = implode(',', array_fill(0, count($lote), '(?, ?, ?, ?, ?)'));
            $total += DB::update(
                "UPDATE t SET
                        t.RutaArchivo        = v.ruta,
                        t.GlobalPeriodicidad = COALESCE(t.GlobalPeriodicidad, v.per),
                        t.GlobalMeses        = COALESCE(t.GlobalMeses, v.mes),
                        t.GlobalAnio         = COALESCE(t.GlobalAnio, CAST(v.anio AS smallint))
                   FROM dbo.[{$tabla}] t
                   JOIN (VALUES {$valores}) v (uuid, ruta, per, mes, anio) ON t.UUID = v.uuid
                  WHERE t.RutaArchivo IS NULL",
                $params
            );
        }
        return $total;
    }
}
```

- [ ] **Step 2: Lint**

Run: `php -l app/Services/FacturasExtraBd.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git -C ../ApiTotal add app/Services/FacturasExtraBd.php
git -C ../ApiTotal commit -m "feat(sat): actualizador en lote de RutaArchivo/InformacionGlobal y guard de columnas"
```

---

### Task 4: Comando `sat:completar-datos-cfdi` (backfill)

**Files:**
- Create: `ApiTotal/app/Console/Commands/CompletarDatosCfdi.php`
- Test: `ApiTotal/tests/Feature/CompletarDatosCfdiTest.php`

**Interfaces:**
- Consumes: `CfdiInformacionGlobal::desdeArchivo()` (Task 2), `FacturasExtraBd::columnasListas()` / `completar()` (Task 3), `SatPipelineDirs::rutaProcesadas()` (existente, regresa `.../cfdis_processed/` con `/`).
- Produces: `php artisan sat:completar-datos-cfdi [--dry-run] [--rfc=] [--tipo=ambos|emitidas|recibidas] [--limit=0] [--bloque=2000]`

- [ ] **Step 1: Escribir los tests que fallan**

```php
<?php

namespace Tests\Feature;

use App\Services\FacturasExtraBd;
use App\Services\SatPipelineDirs;
use Tests\TestCase;

class CompletarDatosCfdiTest extends TestCase
{
    private string $tmp;
    private string $proc;
    /** @var array<int,array{tabla:string,filas:array}> */
    public array $llamadas = [];
    public bool $listas = true;

    private const GLOBAL_XML = '<?xml version="1.0" encoding="UTF-8"?><cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4">'
        . '<cfdi:InformacionGlobal Periodicidad="01" Meses="08" Año="2026"/><cfdi:Emisor Rfc="DGA930823KD3"/></cfdi:Comprobante>';
    private const NORMAL_XML = '<?xml version="1.0" encoding="UTF-8"?><cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4">'
        . '<cfdi:Emisor Rfc="DCL880518UG2"/></cfdi:Comprobante>';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = str_replace('\\', '/', sys_get_temp_dir()) . '/completar_' . uniqid() . '/';
        $this->proc = $this->tmp . 'cfdis_processed/';
        $this->escribir('DGA930823KD3/emitidas/2026/08/AAAA-1.xml', self::GLOBAL_XML);
        // intercompañía: misma factura en emitidas de DCL y recibidas de DGM
        $this->escribir('DCL880518UG2/emitidas/2026/08/BBBB-2.xml', self::NORMAL_XML);
        $this->escribir('DGM880621FU5/recibidas/2026/08/BBBB-2.xml', self::NORMAL_XML);
        $this->escribir('sin_clasificar/CCCC-3.xml', 'roto');
        $this->escribir('DDDD-4.xml', self::NORMAL_XML); // raíz: reorganización pendiente

        $this->app->instance(SatPipelineDirs::class, new SatPipelineDirs($this->tmp, $this->tmp . 'test.lock'));
        $test = $this;
        $this->app->instance(FacturasExtraBd::class, new class($test) extends FacturasExtraBd {
            public function __construct(private $t) {}
            public function columnasListas(): bool { return $this->t->listas; }
            public function completar(string $tabla, array $filas): int
            {
                $this->t->llamadas[] = ['tabla' => $tabla, 'filas' => $filas];
                return count($filas);
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

    private function escribir(string $relativa, string $contenido): void
    {
        $ruta = $this->proc . $relativa;
        @mkdir(dirname($ruta), 0777, true);
        file_put_contents($ruta, $contenido);
    }

    /** @return array<string,array> uuid => fila, de las llamadas a una tabla */
    private function filas(string $tabla): array
    {
        $r = [];
        foreach ($this->llamadas as $l) {
            if ($l['tabla'] === $tabla) {
                foreach ($l['filas'] as $f) { $r[$f['uuid']] = $f; }
            }
        }
        return $r;
    }

    public function test_manda_cada_archivo_a_su_tabla_con_su_ruta(): void
    {
        $this->artisan('sat:completar-datos-cfdi')->assertSuccessful();

        $f = $this->filas('Facturas');
        $r = $this->filas('FacturasRecibidas');
        $this->assertSame($this->proc . 'DGA930823KD3/emitidas/2026/08/AAAA-1.xml', $f['AAAA-1']['ruta']);
        $this->assertSame(['01', '08', 2026], [$f['AAAA-1']['GlobalPeriodicidad'], $f['AAAA-1']['GlobalMeses'], $f['AAAA-1']['GlobalAnio']]);
        // intercompañía: cada tabla con la ruta de su copia
        $this->assertSame($this->proc . 'DCL880518UG2/emitidas/2026/08/BBBB-2.xml', $f['BBBB-2']['ruta']);
        $this->assertSame($this->proc . 'DGM880621FU5/recibidas/2026/08/BBBB-2.xml', $r['BBBB-2']['ruta']);
        $this->assertNull($f['BBBB-2']['GlobalPeriodicidad']);
        $this->assertArrayNotHasKey('AAAA-1', $r);
    }

    public function test_sin_clasificar_va_a_ambas_tablas_y_la_raiz_se_omite(): void
    {
        $this->artisan('sat:completar-datos-cfdi')
            ->expectsOutputToContain('1 XML en la raíz')
            ->assertSuccessful();

        $this->assertArrayHasKey('CCCC-3', $this->filas('Facturas'));
        $this->assertArrayHasKey('CCCC-3', $this->filas('FacturasRecibidas'));
        $this->assertArrayNotHasKey('DDDD-4', $this->filas('Facturas') + $this->filas('FacturasRecibidas'));
    }

    public function test_dry_run_no_escribe(): void
    {
        $this->artisan('sat:completar-datos-cfdi', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame([], $this->llamadas);
    }

    public function test_filtra_por_rfc_y_tipo(): void
    {
        $this->artisan('sat:completar-datos-cfdi', ['--rfc' => 'DGM880621FU5', '--tipo' => 'recibidas'])->assertSuccessful();
        $this->assertSame([], $this->filas('Facturas'));
        $this->assertSame(['BBBB-2'], array_keys($this->filas('FacturasRecibidas')));
    }

    public function test_se_niega_si_faltan_las_columnas(): void
    {
        $this->listas = false;
        $this->artisan('sat:completar-datos-cfdi')
            ->expectsOutputToContain('2026-10-08-facturas-global-ruta.sql')
            ->assertFailed();
        $this->assertSame([], $this->llamadas);
    }
}
```

- [ ] **Step 2: Correr y ver que fallan**

Run: `php artisan test --filter=CompletarDatosCfdiTest`
Expected: FAIL (`The command "sat:completar-datos-cfdi" does not exist.`)

- [ ] **Step 3: Implementar**

```php
<?php

namespace App\Console\Commands;

use App\Services\CfdiInformacionGlobal;
use App\Services\FacturasExtraBd;
use App\Services\SatPipelineDirs;
use Illuminate\Console\Command;

/**
 * Backfill de RutaArchivo e InformacionGlobal para facturas ya importadas, recorriendo
 * cfdis_processed\{RFC}\{emitidas|recibidas}\AAAA\MM\UUID.xml (el nombre es el UUID).
 * Solo toca filas sin RutaArchivo: se puede cortar y volver a correr.
 */
class CompletarDatosCfdi extends Command
{
    protected $signature = 'sat:completar-datos-cfdi
                            {--dry-run : Solo cuenta; no escribe en la BD}
                            {--rfc= : Solo esta empresa}
                            {--tipo=ambos : emitidas, recibidas o ambos}
                            {--limit=0 : Máximo de archivos (0 = todos)}
                            {--bloque=2000 : Archivos por envío a la BD}';

    protected $description = 'Llena RutaArchivo e InformacionGlobal de las facturas ya importadas desde cfdis_processed';

    private const TABLA = ['emitidas' => ['Facturas'], 'recibidas' => ['FacturasRecibidas'],
                           'sin_clasificar' => ['Facturas', 'FacturasRecibidas']];

    public function handle(SatPipelineDirs $dirs, FacturasExtraBd $bd): int
    {
        if (!$bd->columnasListas()) {
            $this->error('Faltan columnas en TGV2: corre antes database/sql/2026-10-08-facturas-global-ruta.sql');
            return self::FAILURE;
        }
        $tipo = (string) $this->option('tipo');
        if (!in_array($tipo, ['ambos', 'emitidas', 'recibidas'], true)) {
            $this->error('--tipo debe ser emitidas, recibidas o ambos');
            return self::FAILURE;
        }
        $seco   = (bool) $this->option('dry-run');
        $rfc    = strtoupper(trim((string) $this->option('rfc')));
        $limit  = max(0, (int) $this->option('limit'));
        $bloque = max(1, (int) $this->option('bloque'));
        $raiz   = $dirs->rutaProcesadas();

        $enRaiz = count(glob($raiz . '*.xml') ?: []);
        if ($enRaiz > 0) {
            $this->warn("{$enRaiz} XML en la raíz de cfdis_processed se omiten: corre sat:reorganizar-procesadas y vuelve a correr este comando.");
        }

        $t = ['archivos' => 0, 'globales' => 0, 'ilegibles' => 0, 'actualizadas' => 0];
        $pendientes = []; // tabla => filas
        $enviar = function (bool $forzar) use (&$pendientes, &$t, $bd, $seco, $bloque) {
            foreach ($pendientes as $tabla => $filas) {
                if ($filas && ($forzar || count($filas) >= $bloque)) {
                    $t['actualizadas'] += $seco ? 0 : $bd->completar($tabla, $filas);
                    $pendientes[$tabla] = [];
                }
            }
        };

        foreach ($this->carpetas($raiz, $rfc, $tipo) as [$carpeta, $tablas]) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($carpeta, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $archivo) {
                if ($limit > 0 && $t['archivos'] >= $limit) {
                    break 2;
                }
                if (strtolower($archivo->getExtension()) !== 'xml') {
                    continue;
                }
                $ruta = str_replace('\\', '/', $archivo->getPathname());
                $global = CfdiInformacionGlobal::desdeArchivo($ruta);
                if ($global === null) {
                    $t['ilegibles']++;
                    $global = CfdiInformacionGlobal::VACIO; // la ruta sí se guarda
                } elseif ($global['GlobalPeriodicidad'] !== null) {
                    $t['globales']++;
                }
                $t['archivos']++;
                foreach ($tablas as $tabla) {
                    $pendientes[$tabla][] = ['uuid' => $archivo->getBasename('.' . $archivo->getExtension()), 'ruta' => $ruta] + $global;
                }
                $enviar(false);
                if ($t['archivos'] % 10000 === 0) {
                    $this->line(sprintf('  %s archivos · %s globales · %s filas actualizadas',
                        number_format($t['archivos']), number_format($t['globales']), number_format($t['actualizadas'])));
                }
            }
        }
        $enviar(true);

        $this->info(sprintf('%s%s archivos, %s con InformacionGlobal, %s ilegibles, %s filas actualizadas%s',
            $seco ? '[dry-run] ' : '', $t['archivos'], $t['globales'], $t['ilegibles'], $t['actualizadas'],
            $seco ? '' : ' (las que ya tenían ruta no se tocan)'));
        return self::SUCCESS;
    }

    /** @return array<int,array{0:string,1:string[]}> carpeta => tablas destino */
    private function carpetas(string $raiz, string $rfc, string $tipo): array
    {
        $r = [];
        foreach (glob($raiz . '*', GLOB_ONLYDIR) ?: [] as $dir) {
            $nombre = basename($dir);
            if ($nombre === 'sin_clasificar') {
                if ($rfc === '' && $tipo === 'ambos') {
                    $r[] = [$dir, self::TABLA['sin_clasificar']];
                }
                continue;
            }
            if ($rfc !== '' && strtoupper($nombre) !== $rfc) {
                continue;
            }
            foreach (['emitidas', 'recibidas'] as $sub) {
                if (($tipo === 'ambos' || $tipo === $sub) && is_dir("{$dir}/{$sub}")) {
                    $r[] = ["{$dir}/{$sub}", self::TABLA[$sub]];
                }
            }
        }
        return $r;
    }
}
```

- [ ] **Step 4: Correr y ver que pasan**

Run: `php artisan test --filter=CompletarDatosCfdiTest`
Expected: PASS (5 tests). Luego `php artisan test` completo: todos en verde.

- [ ] **Step 5: Commit**

```bash
git -C ../ApiTotal add app/Console/Commands/CompletarDatosCfdi.php tests/Feature/CompletarDatosCfdiTest.php
git -C ../ApiTotal commit -m "feat(sat): comando sat:completar-datos-cfdi para backfill de ruta e InformacionGlobal"
```

- [ ] **Step 6: Verificar en el servidor (después de Task 1 y del FTP)**

1. `php artisan sat:completar-datos-cfdi --dry-run` → anota archivos / globales / ilegibles.
2. `php artisan sat:completar-datos-cfdi --rfc=DGA930823KD3 --tipo=emitidas --limit=500` y revisar:
   `SELECT COUNT(*) FROM TGV2.dbo.Facturas WITH (NOLOCK) WHERE RutaArchivo IS NOT NULL` (≈500) y
   `SELECT TOP 5 UUID, GlobalPeriodicidad, GlobalMeses, GlobalAnio, RutaArchivo FROM TGV2.dbo.Facturas WITH (NOLOCK) WHERE GlobalAnio IS NOT NULL`.
3. Volver a correr el mismo comando → `0 filas actualizadas` (idempotente).
4. Correr completo: `php artisan sat:completar-datos-cfdi`.
5. Cobertura final: `SELECT COUNT(*) total, SUM(CASE WHEN RutaArchivo IS NULL THEN 1 END) sin_ruta, SUM(CASE WHEN ReceptorRfc='XAXX010101000' AND GlobalAnio IS NULL THEN 1 END) globales_sin_dato FROM TGV2.dbo.Facturas WITH (NOLOCK)` (esperado: sin_ruta ≈ todo lo anterior a oct-2025).

---

### Task 5: El importador llena las columnas nuevas

**Files:**
- Modify: `ApiTotal/app/Http/Controllers/FacturaController.php` (`process_xml` ~línea 686, `procesarArchivosXML` ~líneas 750-1010, `extraerDatosFactura` ~línea 1018)
- Modify: `ApiTotal/app/Console/Commands/ImportarXml.php` (`handle`)

**Interfaces:**
- Consumes: `CfdiInformacionGlobal::desdeXml()` (Task 2), `FacturasExtraBd::columnasListas()` (Task 3).
- Produces: cada factura nueva sale con `GlobalPeriodicidad/GlobalMeses/GlobalAnio` (si es global), `RutaArchivo` (ruta final en `cfdis_processed`) y `FechaImportacion` (DEFAULT del servidor).

- [ ] **Step 1: `extraerDatosFactura` agrega InformacionGlobal** — al final, antes del `return`:

```php
        // Facturas globales a público en general (2026-10-08)
        $facturaData += \App\Services\CfdiInformacionGlobal::desdeXml($xml);
        return $facturaData;
```

- [ ] **Step 2: Insert con la ruta final ya calculada.** En `procesarArchivosXML`, reemplazar el bloque desde `DB::beginTransaction();` hasta el `if (rename(...))` del éxito para que la ruta destino se calcule **antes** del insert:

```php
                // La carpeta final se calcula antes del insert para guardar la ruta en la misma fila
                $destinoDir = $dirs->rutaProcesado($type, $facturaData['EmisorRfc'] ?? null, $facturaData['ReceptorRfc'] ?? null, $facturaData['Fecha'] ?? null);
                $newLocation = $destinoDir . basename($file);
                $facturaData['RutaArchivo'] = $newLocation;

                // Transacción: la factura y todos sus detalles se guardan completos o no se guarda nada.
                // Si algo truena, se hace rollback y el XML se aparta a cfdis_error con el motivo en ApiFailures.
                DB::beginTransaction();
                $facturaId = DB::table($tableName)->insertGetId($facturaData);
```

y, después del `DB::commit();`, quitar el cálculo duplicado de `$destinoDir`/`$newLocation` y, si el `rename` falla, corregir la ruta guardada:

```php
                DB::commit();

                if (rename($file, $newLocation)) {
                    $processLog['logs'][] = [
                        'file' => basename($file),
                        'status' => 'moved',
                        'message' => "File moved successfully to {$destinoDir}",
                        'destination' => $destinoDir
                    ];
                } else {
                    // El XML se quedó donde estaba: la ruta guardada debe decir eso
                    DB::table($tableName)->where('Id', $facturaId)->update(['RutaArchivo' => str_replace('\\', '/', $file)]);
                    $processLog['logs'][] = [
                        'file' => basename($file),
                        'status' => 'error',
                        'message' => "Failed to move file to {$destinoDir}",
                        'destination' => $destinoDir
                    ];
                }
```

- [ ] **Step 3: Rama "archivo ya procesado" completa la ruta si falta.** Después de `$moveResult = rename($file, $newLocation);` en esa rama:

```php
                    if ($moveResult) {
                        DB::table($tableName)->where('UUID', $uuid)->whereNull('RutaArchivo')
                            ->update(['RutaArchivo' => $newLocation]);
                    }
```

- [ ] **Step 4: Guard al arrancar** — en `process_xml`, justo después de tomar el candado (dentro del `try`, antes de `$limit = ...`):

```php
            if (!app(\App\Services\FacturasExtraBd::class)->columnasListas()) {
                return response()->json(['status' => 'error',
                    'message' => 'Faltan columnas en TGV2: corre database/sql/2026-10-08-facturas-global-ruta.sql'], 500);
            }
```

y en `ImportarXml::handle`, después de tomar los candados y antes del `foreach ($tipos ...)`:

```php
        if (!app(\App\Services\FacturasExtraBd::class)->columnasListas()) {
            $this->error('Faltan columnas en TGV2: corre database/sql/2026-10-08-facturas-global-ruta.sql');
            flock($lock, LOCK_UN);
            fclose($lock);
            if ($lockParte) { flock($lockParte, LOCK_UN); fclose($lockParte); }
            return self::FAILURE;
        }
```

- [ ] **Step 5: Lint y suite**

Run: `php -l app/Http/Controllers/FacturaController.php && php -l app/Console/Commands/ImportarXml.php && php artisan test`
Expected: sin errores de sintaxis; todos los tests en verde.

- [ ] **Step 6: Verificar en el servidor (después de Task 1 y del FTP)**

`php artisan sat:importar-xml --limit=50` y luego:
`SELECT TOP 10 UUID, ReceptorRfc, GlobalPeriodicidad, GlobalMeses, GlobalAnio, FechaImportacion, RutaArchivo FROM TGV2.dbo.Facturas WITH (NOLOCK) ORDER BY Id DESC`
Expected: `FechaImportacion` = hora local de hoy, `RutaArchivo` con el archivo existente (`Test-Path`), y las XAXX con `Global*` llenos.

- [ ] **Step 7: Commit**

```bash
git -C ../ApiTotal add app/Http/Controllers/FacturaController.php app/Console/Commands/ImportarXml.php
git -C ../ApiTotal commit -m "feat(sat): el importador guarda InformacionGlobal y RutaArchivo; guard de columnas"
```

---

### Task 6: Mostrarlo en el tab "Facturas SAT" (AplicativoPhp)

**Files:**
- Modify: `AplicativoPhp/_assets/models/SatFacturasModel.php` (`detalle`, `exportar`)
- Modify: `AplicativoPhp/views/it/modals/llamadas_sat_factura.html`
- Modify: `AplicativoPhp/_assets/controllers/it.php` (`llamadas_sat_facturas_excel`)

**Interfaces:**
- Consumes: columnas de Task 1 (desplegar **después** del SQL: sin las columnas el modal y el Excel truenan).

- [ ] **Step 1: Modelo** — en `detalle()` agregar al SELECT de la factura `fr.GlobalPeriodicidad, fr.GlobalMeses, fr.GlobalAnio, fr.FechaImportacion, fr.RutaArchivo,`; en `exportar()` agregar las mismas 5 al `$extra` de `union()`.

- [ ] **Step 2: Modal** — después de la fila de Subtotal/Total:

```twig
        {% set periodicidades = {'01': 'Diaria', '02': 'Semanal', '03': 'Quincenal', '04': 'Mensual', '05': 'Bimestral'} %}
        {% set bimestres = {'13': 'Ene-Feb', '14': 'Mar-Abr', '15': 'May-Jun', '16': 'Jul-Ago', '17': 'Sep-Oct', '18': 'Nov-Dic'} %}
        {% if f.GlobalAnio %}
        <div class="col-md-6"><div class="text-muted">Factura global</div>
            {{ periodicidades[f.GlobalPeriodicidad] ?? f.GlobalPeriodicidad }} · {{ bimestres[f.GlobalMeses] ?? ('Mes ' ~ f.GlobalMeses) }} {{ f.GlobalAnio }}</div>
        {% endif %}
        <div class="col-md-3"><div class="text-muted">Importada</div>{{ f.FechaImportacion ? m.fecha(f.FechaImportacion) : 'antes del 2026-10-08' }}</div>
        <div class="col-12"><div class="text-muted">Archivo XML</div><code class="user-select-all small">{{ f.RutaArchivo ?: 'sin ruta registrada' }}</code></div>
```

- [ ] **Step 3: Excel** — agregar al final de `$enc`: `'Global periodicidad', 'Global meses', 'Global año', 'Fecha importación', 'Ruta XML'`; a cada fila: `$r['GlobalPeriodicidad'], $r['GlobalMeses'], $r['GlobalAnio'], substr((string) $r['FechaImportacion'], 0, 19), $r['RutaArchivo']`; extender los rangos `A1:V1`/`A1:V{$ultima}` y el `range('A', 'V')` a `'AA'` (usar `\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex` para iterar 1..27).

- [ ] **Step 4: Verificar** con el script de prueba de la sesión (scratchpad `t2.php`, modo `detalle`) sobre una XAXX ya rellenada: el texto debe incluir `Factura global Diaria · Mes 08 2026` y la ruta. Twig: parse check de la plantilla.

- [ ] **Step 5: Commit**

```bash
git add _assets/models/SatFacturasModel.php views/it/modals/llamadas_sat_factura.html _assets/controllers/it.php
git commit -m "feat(it): mostrar InformacionGlobal, fecha de importación y ruta del XML en Facturas SAT"
```

---

## Despliegue (resumen para el usuario)

1. Correr `ApiTotal/database/sql/2026-10-08-facturas-global-ruta.sql` en 192.168.0.6 (tú; DDL).
2. FTP a `C:\inetpub\wwwroot\ApiTotal`: `app/Services/CfdiInformacionGlobal.php`, `app/Services/FacturasExtraBd.php`, `app/Console/Commands/CompletarDatosCfdi.php`, `app/Console/Commands/ImportarXml.php`, `app/Http/Controllers/FacturaController.php`.
3. Detener y volver a lanzar las consolas de `sat:importar-xml` (para que tomen el código nuevo).
4. `php artisan sat:completar-datos-cfdi --dry-run`, luego completo (Task 4, Step 6).
5. Subir los 3 archivos de AplicativoPhp (Task 6).
