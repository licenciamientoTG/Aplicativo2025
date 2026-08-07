# Carga manual de Balance de Producto (PDF) para Praxedis Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permitir cargar el corte diario de Praxedis (codgas 40) en `TG.dbo.merma_diaria` subiendo el PDF "Balance de Producto" que ControlGas genera para esa estación, ya que Praxedis no está incluida en el sync automático vía ApiER.

**Architecture:** Un parser nuevo (`BalanceProductoPdfParser`, mismo patrón que `NotaCreditoPdfParser`: pdftotext empaquetado + regex) extrae fecha/producto/inv_fisico/ventas/compras del PDF. Dos endpoints nuevos en `merma.php` (`preview_balance_praxedis`, `guardar_balance_praxedis`) siguen el patrón ya usado por `payment.php::preview_notas_credito`/`guardar_notas_credito`: preview sin persistir, luego confirmar persiste llamando a `MermaDiariaModel::replace_station_range()` (ya existente, sin modificar) con un turno sintético `41`. Un modal nuevo en `analisis.html` + JS en `merma.js` reproduce el flujo dropzone→preview→confirmar de `credit_notes.html`.

**Tech Stack:** PHP 8, pdftotext.exe (Poppler, empaquetado en `_assets/bin/poppler/`), jQuery + Bootstrap modals, SweetAlert2, MSSQL vía PDO.

## Global Constraints

- codgas de Praxedis = 40, nombre de estación en el snapshot = `'PRAXEDIS'` (spec §Mapeo de campos).
- Mapeo de producto: `87 Octanos → codprd 1`, `91 Octanos → codprd 2`, `Diesel → codprd 3` (spec §Mapeo de campos).
- Campos extraídos del PDF: `Inv Lec → inv_fisico`, `Ventas → ventas_reales`, `Compras Doc → compras`. NO se extraen ni insertan `inv_inicial`/`inv_contable`/`diferencia` — los calcula `recalc_contable()` (ya lo hace `replace_station_range`).
- Turno fijo `41` para toda fila insertada de Praxedis (spec §Turnos).
- Validación solo estructural: encabezado (`Fecha == Fecha Hasta`, `Estación == PRAXEDIS`, `Tipo == Diario`) + al menos una familia con datos numéricos. Sin umbral de diferencia.
- Re-cargar una fecha ya existente sobrescribe automáticamente (mismo comportamiento que `replace_station_range`: DELETE+INSERT), sin confirmación adicional server-side.
- Permiso requerido: `33` (mismo permiso que el resto del módulo merma, constante `Merma::PERM_VER`).
- No se persiste el PDF original (fuera de alcance, spec).
- No generalizar a otras estaciones — codgas 40 hardcodeado.

---

## File Structure

- **Create:** `_assets/classes/BalanceProductoPdfParser.class.php` — parser standalone, misma forma que `NotaCreditoPdfParser.class.php`.
- **Modify:** `_assets/controllers/merma.php` — agregar `preview_balance_praxedis()` y `guardar_balance_praxedis()`.
- **Modify:** `views/merma/analisis.html` — agregar botón + modal de carga.
- **Modify:** `_assets/js/merma.js` — agregar lógica de dropzone/preview/confirmar.

---

### Task 1: Parser del PDF `BalanceProductoPdfParser`

**Files:**
- Create: `_assets/classes/BalanceProductoPdfParser.class.php`
- Test manual: PDF de muestra en `C:\Users\alejandro.martinez\Desktop\libro amarillo\Balance de Producto (1).pdf`

**Interfaces:**
- Consumes: nada (usa el mismo binario `_assets/bin/poppler/pdftotext.exe` que `NotaCreditoPdfParser`, vía `proc_open` directo — no reutiliza código de esa clase porque es `private static`).
- Produces: `BalanceProductoPdfParser::parse(string $rutaPdf, string $nombreArchivo = ''): array` retornando:
  ```php
  [
      'archivo' => string,
      'ok'      => bool,
      'error'   => ?string,
      'fecha'   => string,   // 'YYYY-MM-DD', '' si ok=false
      'filas'   => [         // vacío si ok=false
          ['codprd' => int, 'producto' => string, 'inv_fisico' => float,
           'ventas_reales' => float, 'compras' => float],
          ...
      ],
  ]
  ```
  Task 2 (`preview_balance_praxedis`/`guardar_balance_praxedis`) consume exactamente esta forma.

Referencia de texto real que produce `pdftotext.exe -layout` sobre el PDF de muestra (para diseñar las regex):

```
                                                                                                                                                       Fecha Impresión
                                             Balance de Producto                                                                                      2026-08-07 08:41:45
Fecha 2026-08-06
Fecha Hasta 2026-08-06
Estación PRAXEDIS
Tipo Diario


87 Octanos

   Fecha      Inv Inicial Compras Lec Compras Doc         Ventas      Inv Lec      Inv Doc     Inv Final Dif Lec Dif Doc         Lec          Doc

 2026-08-06   37,999.00           0.00          0.00 12,333.01 25,665.99 25,665.99 25,625.00                 -40.99      -40.99 -0.16% -0.16%

 TOTAL        37,999.00           0.00          0.00 12,333.01 25,665.99 25,665.99 25,625.00                 -40.99      -40.99 -0.00% -0.00%




91 Octanos

  Fecha    Inv Inicial    Compras Lec     Compras Doc      Ventas      Inv Lec      Inv Doc     Inv Final     Dif Lec     Dif Doc    Lec      Doc

 TOTAL             null            null            null        null         null        null          null        null        null     null    null




Diesel

   Fecha      Inv Inicial Compras Lec Compras Doc       Ventas        Inv Lec      Inv Doc     Inv Final Dif Lec Dif Doc        Lec           Doc

 2026-08-06   26,771.00           0.00          0.00 6,347.73 20,423.27 20,423.27 20,348.00                  -75.27      -75.27 -0.37% -0.37%

 TOTAL        26,771.00           0.00          0.00 6,347.73 20,423.27 20,423.27 20,348.00                  -75.27      -75.27 -0.00% -0.00%
```

Nota: en el bloque `87 Octanos`/`Diesel` la fila de fecha (`2026-08-06 ...`) tiene solo 9 números después de la fecha (falta un espacio antes de `Dif Lec`, que pdftotext colapsa: `Inv Final` y `Dif Lec` quedan pegados sin separación clara salvo que `Dif Lec` es negativo con signo `-`). El orden de columnas SIEMPRE es: `InvInicial, ComprasLec, ComprasDoc, Ventas, InvLec, InvDoc, InvFinal, DifLec, DifDoc, Lec%, Doc%`. Se deben capturar los primeros 7 números tras la fecha (InvInicial..InvFinal) con una regex de números con comas/decimales, ignorando el resto de la fila.

- [ ] **Step 1: Escribir el esqueleto de la clase con extracción de texto (copiado de `NotaCreditoPdfParser`)**

```php
<?php

/**
 * Extrae inventario/ventas/compras diarios por producto del reporte
 * "Balance de Producto" (ControlGas) para estaciones sin sync automático
 * de merma vía ApiER (hoy: Praxedis).
 *
 * Usa el binario pdftotext (Poppler) empaquetado en _assets/bin/poppler/,
 * mismo mecanismo que NotaCreditoPdfParser.
 *
 * Uso: BalanceProductoPdfParser::parse($rutaPdf, $nombreArchivo)
 */
class BalanceProductoPdfParser
{
    /** Secciones de producto esperadas -> codprd base (ver MermaDiariaModel::FAMILIAS). */
    const SECCIONES = [
        '87 Octanos' => ['codprd' => 1, 'producto' => 'MAXIMA'],
        '91 Octanos' => ['codprd' => 2, 'producto' => 'SUPER'],
        'Diesel'     => ['codprd' => 3, 'producto' => 'DIESEL'],
    ];

    public static function parse(string $rutaPdf, string $nombreArchivo = ''): array
    {
        $base = [
            'archivo' => $nombreArchivo,
            'ok'      => false,
            'error'   => null,
            'fecha'   => '',
            'filas'   => [],
        ];

        $texto = self::extraerTexto($rutaPdf);
        if ($texto === null) {
            $base['error'] = 'No se pudo leer el PDF (pdftotext)';
            return $base;
        }

        if (!preg_match('/Fecha\s+(\d{4}-\d{2}-\d{2})/', $texto, $mFecha)
            || !preg_match('/Fecha Hasta\s+(\d{4}-\d{2}-\d{2})/', $texto, $mFechaHasta)) {
            $base['error'] = 'No se encontraron las fechas del encabezado';
            return $base;
        }
        if ($mFecha[1] !== $mFechaHasta[1]) {
            $base['error'] = 'El PDF cubre un rango (Fecha != Fecha Hasta), se esperaba un solo día';
            return $base;
        }
        if (!preg_match('/Estación\s+(\S+)/u', $texto, $mEst) || strtoupper($mEst[1]) !== 'PRAXEDIS') {
            $base['error'] = 'El PDF no es de la estación PRAXEDIS';
            return $base;
        }
        if (!preg_match('/Tipo\s+(\S+)/', $texto, $mTipo) || strtolower($mTipo[1]) !== 'diario') {
            $base['error'] = 'El PDF no es de tipo Diario';
            return $base;
        }

        $fecha = $mFecha[1];
        $filas = [];
        foreach (self::SECCIONES as $titulo => $meta) {
            $fila = self::extraerFilaSeccion($texto, $titulo, $fecha);
            if ($fila !== null) {
                $filas[] = [
                    'codprd'        => $meta['codprd'],
                    'producto'      => $meta['producto'],
                    'inv_fisico'    => $fila['inv_lec'],
                    'ventas_reales' => $fila['ventas'],
                    'compras'       => $fila['compras_doc'],
                ];
            }
        }

        if (empty($filas)) {
            $base['error'] = 'El PDF no trae datos numéricos en ninguna familia de producto';
            return $base;
        }

        $base['ok']    = true;
        $base['fecha'] = $fecha;
        $base['filas'] = $filas;
        return $base;
    }

    /**
     * Ubica el bloque de una sección de producto (entre su título y el
     * siguiente título de sección, o fin de texto) y extrae los valores
     * numéricos de la fila que empieza con la fecha del reporte. Si esa
     * fila no existe (sección "null"), devuelve null.
     */
    private static function extraerFilaSeccion(string $texto, string $titulo, string $fecha): ?array
    {
        $tituloEsc = preg_quote($titulo, '/');
        if (!preg_match('/^' . $tituloEsc . '\s*$/m', $texto, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $inicio = $m[0][1] + strlen($m[0][0]);
        $bloque = substr($texto, $inicio, 1200); // suficiente para cubrir header + 2 filas

        $fechaEsc = preg_quote($fecha, '/');
        if (!preg_match('/^\s*' . $fechaEsc . '\s+(.+)$/m', $bloque, $mFila)) {
            return null; // sección "null" (estación no vendió ese producto ese día)
        }

        // Extrae todos los números (con separador de miles/decimales, signo opcional)
        // de la fila y toma los primeros 7: InvInicial, ComprasLec, ComprasDoc,
        // Ventas, InvLec, InvDoc, InvFinal.
        preg_match_all('/-?[\d,]+\.\d+/', $mFila[1], $mNums);
        $nums = array_map(fn($n) => (float) str_replace(',', '', $n), $mNums[0]);
        if (count($nums) < 7) {
            return null;
        }

        return [
            'inv_inicial' => $nums[0],
            'compras_lec' => $nums[1],
            'compras_doc' => $nums[2],
            'ventas'      => $nums[3],
            'inv_lec'     => $nums[4],
            'inv_doc'     => $nums[5],
            'inv_final'   => $nums[6],
        ];
    }

    private static function extraerTexto(string $rutaPdf): ?string
    {
        $bin = self::binarioPdftotext();
        if (!$bin || !is_file($rutaPdf)) {
            return null;
        }

        $cmd = '"' . $bin . '" -layout ' . escapeshellarg($rutaPdf) . ' -';
        $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($cmd, $desc, $pipes);
        if (!is_resource($proc)) {
            return null;
        }
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        return ($code === 0 && $out !== false && $out !== '') ? $out : null;
    }

    private static function binarioPdftotext(): ?string
    {
        $empaquetado = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR
            . 'bin' . DIRECTORY_SEPARATOR . 'poppler' . DIRECTORY_SEPARATOR . 'pdftotext.exe';
        if (is_file($empaquetado)) {
            return realpath($empaquetado);
        }
        return 'pdftotext';
    }
}
```

- [ ] **Step 2: Probar el parser manualmente contra el PDF de muestra con un script ad-hoc**

Crear un archivo temporal de prueba (no se commitea) en la carpeta scratchpad para validar el parser end-to-end antes de integrarlo:

```php
<?php
// scratchpad: probar_parser.php
require 'C:/Users/alejandro.martinez/Desktop/codigo/AplicativoPhp/_assets/classes/BalanceProductoPdfParser.class.php';
$r = BalanceProductoPdfParser::parse('C:/Users/alejandro.martinez/Desktop/libro amarillo/Balance de Producto (1).pdf', 'Balance de Producto (1).pdf');
var_export($r);
```

Run (PowerShell):
```
php "C:\Users\ALEJAN~1.MAR\AppData\Local\Temp\claude\...\scratchpad\probar_parser.php"
```

Expected output: `'ok' => true`, `'fecha' => '2026-08-06'`, `'filas'` con 2 elementos (codprd 1 y codprd 3 — la sección `91 Octanos`/codprd 2 se omite porque viene `null`), con `inv_fisico` = 25665.99 (codprd 1) y 20423.27 (codprd 3), `ventas_reales` = 12333.01 y 6347.73, `compras` = 0.0 y 0.0.

- [ ] **Step 3: Ajustar regex si el output no coincide, re-correr hasta que pase**

Si `extraerFilaSeccion` no encuentra la fila (por ejemplo si `Estación` trae un carácter especial distinto, o el separador de columnas varía), imprimir `$texto` crudo (`var_dump($texto)`) y ajustar las regex de encabezado/fila en consecuencia. No continuar a Task 2 hasta que el Step 2 produzca el resultado esperado exacto.

- [ ] **Step 4: Borrar el script de prueba temporal y commitear el parser**

```bash
git add _assets/classes/BalanceProductoPdfParser.class.php
git commit -m "feat: parser de PDF Balance de Producto para carga manual de Praxedis"
```

---

### Task 2: Endpoints `preview_balance_praxedis` y `guardar_balance_praxedis`

**Files:**
- Modify: `_assets/controllers/merma.php`

**Interfaces:**
- Consumes: `BalanceProductoPdfParser::parse()` (Task 1); `MermaDiariaModel::replace_station_range(int $codgas, string $estacion, string $desde, string $hasta, array $filas): int` (ya existente, `_assets/models/MermaDiariaModel.php:54` — espera `$filas` como array de arrays con claves `Fecha, CodProducto, Producto, Turno, VentasReales, Inventario, CantidadCompra, InventarioInicial, InventarioContable, Diferencia`; `InventarioInicial`/`InventarioContable`/`Diferencia` pueden ir `null`, se recalculan después).
- Produces: rutas `POST /merma/preview_balance_praxedis` y `POST /merma/guardar_balance_praxedis`, consumidas por Task 4 (JS).

Ubicar los métodos nuevos después de `sync_diario()` (línea 888 en adelante) en `merma.php`, antes del cierre de la clase.

- [ ] **Step 1: Agregar la constante de codgas de Praxedis**

En `_assets/controllers/merma.php`, junto a las constantes existentes (línea 22-23):

```php
    private const PERM_VER = 33;   // Reportes de Abastos
    private const API_URL  = 'http://192.168.0.109:82/api/inventarios_turnos/';
    private const CODGAS_PRAXEDIS = 40;
```

- [ ] **Step 2: Escribir `preview_balance_praxedis()`**

```php
    /**
     * Preview de carga manual de "Balance de Producto" (Praxedis) — no persiste.
     * POST $_FILES['balances'][] (PDFs).
     */
    public function preview_balance_praxedis(): void
    {
        header('Content-Type: application/json');
        if (!authorized(self::PERM_VER)) {
            json_output(['success' => false, 'message' => 'No autorizado']);
            return;
        }
        if (empty($_FILES['balances']) || !is_array($_FILES['balances']['name'])) {
            json_output(['success' => false, 'message' => 'No se recibieron PDFs']);
            return;
        }

        $files = $_FILES['balances'];
        $total = count($files['name']);
        $resultados = [];
        $resumen = ['ok' => 0, 'error' => 0, 'total' => $total];

        for ($i = 0; $i < $total; $i++) {
            $nombre = $files['name'][$i];
            if ($files['error'][$i] !== UPLOAD_ERR_OK || strtolower(pathinfo($nombre, PATHINFO_EXTENSION)) !== 'pdf') {
                $resultados[] = ['archivo' => $nombre, 'ok' => false, 'error' => 'Archivo inválido', 'fecha' => '', 'filas' => []];
                $resumen['error']++;
                continue;
            }
            $r = BalanceProductoPdfParser::parse($files['tmp_name'][$i], $nombre);
            $resultados[] = $r;
            $r['ok'] ? $resumen['ok']++ : $resumen['error']++;
        }

        json_output(['success' => true, 'resumen' => $resumen, 'archivos' => $resultados]);
    }
```

- [ ] **Step 3: Escribir `guardar_balance_praxedis()`**

```php
    /**
     * Confirma la carga: re-parsea los PDFs recibidos, agrupa por fecha y
     * reemplaza el snapshot de Praxedis en TG.dbo.merma_diaria (turno
     * sintético 41 — el "Balance de Producto" no trae desglose por turno).
     * POST $_FILES['balances'][] (los mismos PDFs del preview).
     */
    public function guardar_balance_praxedis(): void
    {
        header('Content-Type: application/json');
        if (!authorized(self::PERM_VER)) {
            json_output(['success' => false, 'message' => 'No autorizado']);
            return;
        }
        if (empty($_FILES['balances']) || !is_array($_FILES['balances']['name'])) {
            json_output(['success' => false, 'message' => 'No se recibieron PDFs']);
            return;
        }

        $files = $_FILES['balances'];
        $total = count($files['name']);

        // Agrupar filas válidas por fecha (un PDF = un día; el lote puede traer varios días)
        $porFecha = [];
        $resultados = [];
        for ($i = 0; $i < $total; $i++) {
            $nombre = $files['name'][$i];
            if ($files['error'][$i] !== UPLOAD_ERR_OK || strtolower(pathinfo($nombre, PATHINFO_EXTENSION)) !== 'pdf') {
                $resultados[] = ['archivo' => $nombre, 'success' => false, 'message' => 'Archivo inválido'];
                continue;
            }
            $r = BalanceProductoPdfParser::parse($files['tmp_name'][$i], $nombre);
            if (!$r['ok']) {
                $resultados[] = ['archivo' => $nombre, 'success' => false, 'message' => $r['error']];
                continue;
            }
            $porFecha[$r['fecha']] = $r['filas']; // último archivo de esa fecha gana si hay duplicado en el mismo lote
            $resultados[] = ['archivo' => $nombre, 'success' => true, 'message' => "Fecha {$r['fecha']} lista"];
        }

        if (empty($porFecha)) {
            json_output(['success' => false, 'message' => 'Ningún PDF válido para guardar', 'resultados' => $resultados]);
            return;
        }

        $filasInsertadas = 0;
        $fechasOk = [];
        foreach ($porFecha as $fecha => $filasProducto) {
            $filas = array_map(fn($f) => [
                'Fecha'               => $fecha,
                'CodProducto'         => $f['codprd'],
                'Producto'            => $f['producto'],
                'Turno'               => 41,
                'VentasReales'        => $f['ventas_reales'],
                'Inventario'          => $f['inv_fisico'],
                'CantidadCompra'      => $f['compras'],
                'InventarioInicial'   => null,
                'InventarioContable'  => null,
                'Diferencia'          => null,
            ], $filasProducto);

            try {
                $filasInsertadas += $this->mermaModel->replace_station_range(
                    self::CODGAS_PRAXEDIS, 'PRAXEDIS', $fecha, $fecha, $filas
                );
                $fechasOk[] = $fecha;
            } catch (Throwable $e) {
                $resultados[] = ['archivo' => "fecha {$fecha}", 'success' => false, 'message' => $e->getMessage()];
            }
        }

        json_output([
            'success'     => count($fechasOk) > 0,
            'fechas'      => $fechasOk,
            'filas'       => $filasInsertadas,
            'resultados'  => $resultados,
        ]);
    }
```

- [ ] **Step 4: Verificar sintaxis PHP**

Run: `php -l "C:\Users\alejandro.martinez\Desktop\codigo\AplicativoPhp\_assets\controllers\merma.php"`
Expected: `No syntax errors detected`

- [ ] **Step 5: Prueba manual end-to-end contra la BD real**

Con el servidor de desarrollo corriendo (el usuario lo levanta manualmente — no ejecutar `php -S`), y sesión iniciada con permiso 33:

```bash
curl -X POST http://localhost:8000/merma/preview_balance_praxedis \
  -F "balances[]=@C:\Users\alejandro.martinez\Desktop\libro amarillo\Balance de Producto (1).pdf" \
  -b "<cookie de sesión>"
```

Expected: JSON con `success: true`, `resumen.ok: 1`, `archivos[0].fecha: "2026-08-06"`, `archivos[0].filas` con 2 elementos.

Luego:
```bash
curl -X POST http://localhost:8000/merma/guardar_balance_praxedis \
  -F "balances[]=@C:\Users\alejandro.martinez\Desktop\libro amarillo\Balance de Producto (1).pdf" \
  -b "<cookie de sesión>"
```

Expected: JSON con `success: true`, `fechas: ["2026-08-06"]`, `filas: 2`. Confirmar en BD:
```sql
SELECT * FROM TG.dbo.merma_diaria WHERE codgas = 40 AND fecha = '2026-08-06';
```
Debe haber 2 filas (codprd 1 y 3), turno 41, `inv_fisico` = 25665.99 y 20423.27, `ventas_reales` = 12333.01 y 6347.73, y `inv_inicial`/`inv_contable`/`diferencia` pobladas por `recalc_contable()` (no NULL si había un corte previo del día anterior; NULL si es el primer día con datos).

- [ ] **Step 6: Commit**

```bash
git add _assets/controllers/merma.php
git commit -m "feat: endpoints de carga manual de Balance de Producto para Praxedis"
```

---

### Task 3: Modal de carga en `views/merma/analisis.html`

**Files:**
- Modify: `views/merma/analisis.html`

**Interfaces:**
- Consumes: rutas `preview_balance_praxedis`/`guardar_balance_praxedis` (Task 2); estructura JSON documentada ahí.
- Produces: elementos DOM `#balancePraxedisModal`, `#balanceDropzone`, `#inputBalances`, `#balanceArchivosSel`, `#balanceResumen`, `#balanceOk`, `#balanceErr`, `#balanceLoading`, `#tablaBalancePreview`, `#btnGuardarBalance` — consumidos por Task 4 (JS).

- [ ] **Step 1: Agregar el botón junto a "Actualizar datos"**

En `views/merma/analisis.html`, dentro del `<div class="col-auto ms-auto text-end">` (línea 27-38), después del botón existente de sync:

```html
            <div class="col-auto ms-auto text-end">
                <button type="button" class="btn btn-merma-sync" data-bs-toggle="modal" data-bs-target="#syncModal">
                    <i class="fas fa-sync"></i> Actualizar datos
                </button>
                <button type="button" class="btn btn-merma-neutro" onclick="abrirModalBalancePraxedis()">
                    <i class="fas fa-file-upload"></i> Cargar Balance PDF (Praxedis)
                </button>
                <div class="small text-muted mt-1">
                    {% if ultimoSync %}
                        Última actualización: {{ ultimoSync.fecha_sync|date('d-m-Y H:i') }}
                    {% else %}
                        Sin sincronizar
                    {% endif %}
                </div>
            </div>
```

- [ ] **Step 2: Agregar el modal completo, después del `<!-- Modal de sincronización -->` existente (antes de `{% endblock %}` en línea 145)**

```html
<!-- Modal de carga manual: Balance de Producto (Praxedis) -->
<div class="modal fade" id="balancePraxedisModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-file-upload"></i> Cargar Balance de Producto — Praxedis</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="balanceDropzone" style="border:2px dashed #9ca3af;border-radius:.75rem;padding:1.75rem;text-align:center;background:#f9fafb;cursor:pointer;">
                    <i class="fas fa-cloud-upload-alt" style="font-size:2rem;color:#4b5563;"></i>
                    <p class="mb-1 mt-2 fw-semibold">Arrastra aquí el/los PDF "Balance de Producto" o haz clic para seleccionar</p>
                    <small class="text-muted">Praxedis no tiene sync automático · Un PDF = un día · No se guarda nada hasta que confirmes.</small>
                </div>
                <input type="file" id="inputBalances" accept="application/pdf" multiple style="display:none;">
                <div id="balanceArchivosSel" class="mt-2 small text-muted"></div>

                <div id="balanceResumen" class="row g-2 mt-2" style="display:none;">
                    <div class="col"><div style="background:#ecfdf5;border:1px solid #6ee7b7;border-radius:.5rem;padding:.5rem;text-align:center;"><div style="font-size:.65rem;color:#047857;text-transform:uppercase;font-weight:700;">Listos</div><div style="color:#065f46;font-size:1.2rem;font-weight:700;" id="balanceOk">0</div></div></div>
                    <div class="col"><div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:.5rem;padding:.5rem;text-align:center;"><div style="font-size:.65rem;color:#b91c1c;text-transform:uppercase;font-weight:700;">Con error</div><div style="color:#991b1b;font-size:1.2rem;font-weight:700;" id="balanceErr">0</div></div></div>
                </div>

                <div id="balanceLoading" class="text-center py-4" style="display:none;">
                    <i class="fas fa-spinner fa-spin" style="font-size:1.5rem;"></i>
                    <p class="text-muted mt-2 mb-0">Leyendo PDFs…</p>
                </div>

                <div class="table-responsive mt-3" id="balanceTablaWrap" style="display:none;">
                    <table class="table table-sm" id="tablaBalancePreview">
                        <thead>
                            <tr>
                                <th>Archivo</th>
                                <th>Fecha</th>
                                <th>Producto</th>
                                <th class="text-end">Inv. Físico</th>
                                <th class="text-end">Ventas</th>
                                <th class="text-end">Compras</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-merma-neutro" id="btnGuardarBalance" disabled onclick="guardarBalancePraxedis()">
                    <i class="fas fa-save"></i> Confirmar carga
                </button>
            </div>
        </div>
    </div>
</div>
```

- [ ] **Step 3: Commit**

```bash
git add views/merma/analisis.html
git commit -m "feat: modal de carga de Balance de Producto en vista de merma"
```

---

### Task 4: JS del modal (`merma.js`)

**Files:**
- Modify: `_assets/js/merma.js`

**Interfaces:**
- Consumes: rutas `/merma/preview_balance_praxedis`, `/merma/guardar_balance_praxedis` (Task 2); IDs del DOM de Task 3.
- Produces: funciones globales `abrirModalBalancePraxedis()`, `guardarBalancePraxedis()` (referenciadas por `onclick` en Task 3).

- [ ] **Step 1: Agregar el bloque de JS al final de `_assets/js/merma.js`, dentro del mismo `$(document).ready(...)` (antes del `});` de cierre en línea 90)**

```javascript
    // ---- Carga manual: Balance de Producto (Praxedis) ---------------------
    let balanceFiles = [];
    let balancePreview = [];

    window.abrirModalBalancePraxedis = function () {
        $('#inputBalances').val('');
        $('#balanceArchivosSel').html('');
        $('#balanceResumen').hide();
        $('#balanceTablaWrap').hide();
        $('#balanceLoading').hide();
        $('#tablaBalancePreview tbody').empty();
        $('#btnGuardarBalance').prop('disabled', true);
        balanceFiles = [];
        balancePreview = [];
        $('#balancePraxedisModal').modal('show');
    };

    $(document).on('click', '#balanceDropzone', function () { $('#inputBalances').click(); });
    $(document).on('change', '#inputBalances', function () {
        if (this.files && this.files.length) subirBalancePreview(this.files);
    });
    $(document).on('dragover', '#balanceDropzone', function (e) { e.preventDefault(); $(this).css('background', '#eef2ff'); });
    $(document).on('dragleave drop', '#balanceDropzone', function (e) { e.preventDefault(); $(this).css('background', '#f9fafb'); });
    $(document).on('drop', '#balanceDropzone', function (e) {
        e.preventDefault();
        const files = e.originalEvent.dataTransfer.files;
        if (files && files.length) subirBalancePreview(files);
    });

    function subirBalancePreview(fileList) {
        const files = Array.from(fileList).filter(f => f.type === 'application/pdf' || f.name.toLowerCase().endsWith('.pdf'));
        if (files.length === 0) { Swal.fire({ icon: 'warning', title: 'Selecciona PDFs' }); return; }

        balanceFiles = files;
        $('#balanceArchivosSel').html('<i class="fas fa-paperclip"></i> ' + files.length + ' archivo(s) seleccionado(s)');
        $('#balanceLoading').show();
        $('#balanceTablaWrap').hide();
        $('#balanceResumen').hide();

        const fd = new FormData();
        files.forEach(f => fd.append('balances[]', f));

        fetch('/merma/preview_balance_praxedis', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                $('#balanceLoading').hide();
                if (!res.success) { Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Error al procesar' }); return; }
                balancePreview = res.archivos || [];
                $('#balanceOk').text(res.resumen.ok || 0);
                $('#balanceErr').text(res.resumen.error || 0);
                $('#balanceResumen').show();
                renderBalanceTabla(balancePreview);
                $('#btnGuardarBalance').prop('disabled', res.resumen.ok === 0);
            })
            .catch(err => { $('#balanceLoading').hide(); Swal.fire({ icon: 'error', title: 'Conexión', text: err.message }); });
    }

    function renderBalanceTabla(archivos) {
        let html = '';
        archivos.forEach(function (a) {
            if (!a.ok) {
                html += '<tr style="background:#fef2f2;"><td><small>' + a.archivo + '</small></td>'
                    + '<td colspan="5"><small class="text-danger">' + (a.error || 'Error') + '</small></td>'
                    + '<td><span class="badge bg-danger">Error</span></td></tr>';
                return;
            }
            a.filas.forEach(function (f, idx) {
                html += '<tr style="background:#ecfdf5;">'
                    + (idx === 0 ? '<td rowspan="' + a.filas.length + '"><small>' + a.archivo + '</small></td>'
                                   + '<td rowspan="' + a.filas.length + '">' + a.fecha + '</td>' : '')
                    + '<td>' + f.producto + '</td>'
                    + '<td class="text-end">' + Number(f.inv_fisico).toLocaleString('es-MX', {minimumFractionDigits: 2}) + '</td>'
                    + '<td class="text-end">' + Number(f.ventas_reales).toLocaleString('es-MX', {minimumFractionDigits: 2}) + '</td>'
                    + '<td class="text-end">' + Number(f.compras).toLocaleString('es-MX', {minimumFractionDigits: 2}) + '</td>'
                    + (idx === 0 ? '<td rowspan="' + a.filas.length + '"><span class="badge bg-success">Listo</span></td>' : '')
                    + '</tr>';
            });
        });
        $('#tablaBalancePreview tbody').html(html);
        $('#balanceTablaWrap').show();
    }

    window.guardarBalancePraxedis = function () {
        if (balanceFiles.length === 0) { Swal.fire({ icon: 'warning', title: 'Sin archivos' }); return; }

        Swal.fire({
            icon: 'question', title: 'Confirmar carga',
            html: 'Vas a guardar el corte de Praxedis para las fechas leídas. Si ya existía un corte para alguna de esas fechas, se sobrescribirá.',
            showCancelButton: true, confirmButtonText: 'Sí, guardar', cancelButtonText: 'Cancelar',
        }).then(function (r) {
            if (!r.isConfirmed) return;

            const fd = new FormData();
            balanceFiles.forEach(f => fd.append('balances[]', f));

            $('#btnGuardarBalance').prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Guardando...');
            fetch('/merma/guardar_balance_praxedis', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(function (res) {
                    $('#btnGuardarBalance').html('<i class="fas fa-save"></i> Confirmar carga');
                    const detalle = (res.resultados || []).map(x =>
                        '<li>' + (x.success ? '✅' : '❌') + ' <strong>' + x.archivo + '</strong>: ' + x.message + '</li>').join('');
                    Swal.fire({
                        icon: res.success ? 'success' : 'error',
                        title: 'Resultado',
                        html: '<div class="alert alert-' + (res.success ? 'success' : 'danger') + '">'
                            + (res.filas || 0) + ' filas guardadas en ' + ((res.fechas || []).length) + ' fecha(s)</div>'
                            + '<ul style="text-align:left;font-size:.85rem;max-height:300px;overflow:auto;">' + detalle + '</ul>',
                    });
                    if (res.success) {
                        $('#balancePraxedisModal').modal('hide');
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        $('#btnGuardarBalance').prop('disabled', false);
                    }
                })
                .catch(function (err) {
                    $('#btnGuardarBalance').prop('disabled', false).html('<i class="fas fa-save"></i> Confirmar carga');
                    Swal.fire({ icon: 'error', title: 'Conexión', text: err.message });
                });
        });
    };
```

- [ ] **Step 2: Prueba manual en navegador**

Con el servidor de desarrollo corriendo (gestionado por el usuario), abrir `/merma/analisis`, click en "Cargar Balance PDF (Praxedis)", arrastrar el PDF de muestra, verificar que la tabla de preview muestre 2 filas (MAXIMA y DIESEL) con los valores esperados (25,665.99 / 12,333.01 / 0.00 y 20,423.27 / 6,347.73 / 0.00), click en "Confirmar carga", verificar el SweetAlert de éxito y que la tabla principal se recargue.

- [ ] **Step 3: Commit**

```bash
git add _assets/js/merma.js
git commit -m "feat: JS de carga de Balance de Producto (dropzone, preview, confirmar)"
```

---

## Self-Review Notes

- **Spec coverage:** parser (Task 1) ✓, endpoints preview/guardar (Task 2) ✓, botón+modal (Task 3) ✓, turno sintético 41 (Task 2 Step 3) ✓, mapeo 87→1/91→2/Diesel→3 (Task 1) ✓, Inv Lec→inv_fisico / Compras Doc→compras (Task 1) ✓, sobrescritura automática vía `replace_station_range` (Task 2) ✓, validación solo estructural (Task 1 Step 1: encabezado + al menos una familia) ✓. "No se persiste el PDF original" — cumplido por omisión (ningún step guarda el archivo en disco).
- **Placeholder scan:** sin TBD/TODO; todos los steps de código traen el código completo.
- **Type consistency:** `replace_station_range` firma verificada contra `_assets/models/MermaDiariaModel.php:54` y claves de `$filas` verificadas contra `MermaDiariaModel.php:63-76` (`Fecha, CodProducto, Producto, Turno, VentasReales, Inventario, CantidadCompra, InventarioInicial, InventarioContable, Diferencia`). `BalanceProductoPdfParser::parse()` retorna las mismas claves (`ok, error, fecha, filas[].codprd/producto/inv_fisico/ventas_reales/compras`) consistentemente entre Task 1, Task 2 y el JSON consumido por Task 4.
