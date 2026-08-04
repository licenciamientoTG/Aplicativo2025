# Sync mensual del cron de merma + fecha de última actualización — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Mostrar en `/merma/analisis` la fecha/hora de la última sincronización exitosa junto al botón "Actualizar datos", y ampliar el cron diario para que cubra todo el mes en curso (día 1 → ayer) en vez de solo D-2/D-1.

**Architecture:** Un método nuevo de solo lectura en `MermaDiariaModel` alimenta el controlador y la vista existentes (sin endpoints nuevos). El cambio de rango del cron vive en `Merma::sync_diario()`, que ya es el único punto de entrada tanto para el script CLI como para cualquier llamada HTTP con `cron_token`.

**Tech Stack:** PHP 8 (sin framework de tests — ver `CLAUDE.md`), Twig, SQL Server vía PDO (`MySqlPdoHandler`).

## Global Constraints

- No existe framework de tests en este proyecto — la verificación de cada tarea es manual (ejecutar el script/endpoint y revisar salida o BD), no `pytest`/`phpunit`.
- No modificar `Merma::sync()` (botón manual) ni su tope de 40 días — spec fuera de alcance.
- No generar ni ejecutar `schtasks` — el usuario reprograma la tarea de Windows manualmente a las 6:00 am.
- Reutilizar `Merma::sync_diario()` como único punto de entrada del cron; no crear un endpoint nuevo.
- Spec: `docs/superpowers/specs/2026-08-04-merma-sync-mensual-y-ultima-actualizacion-design.md`

---

### Task 1: Modelo — `get_ultimo_sync_ok()`

**Files:**
- Modify: `_assets/models/MermaDiariaModel.php` (agregar método después de `add_sync_log`, ~línea 542)

**Interfaces:**
- Consumes: `$this->sql->select($query)` (heredado de `Model`, ya usado en todo el archivo, p.ej. línea 561).
- Produces: `MermaDiariaModel::get_ultimo_sync_ok(): ?array` — devuelve `['fecha_sync' => <string datetime>, 'origen' => <string>]` o `null` si nunca hubo un sync con `estaciones_ok > 0`.

- [ ] **Step 1: Agregar el método al modelo**

En `_assets/models/MermaDiariaModel.php`, inmediatamente después del cierre de `add_sync_log()` (línea 542, antes del bloque `/* Reporte de ventas consolidado */`):

```php
    /**
     * Última fila de merma_sync_log que sí trajo datos (estaciones_ok > 0).
     * Excluye intentos que fallaron por completo (ApiER caído, etc.) para
     * que "última actualización" no muestre un sync que no actualizó nada.
     */
    public function get_ultimo_sync_ok(): ?array
    {
        $rows = $this->sql->select(
            'SELECT TOP 1 fecha_sync, origen
             FROM [TG].[dbo].[merma_sync_log]
             WHERE estaciones_ok > 0
             ORDER BY id DESC;'
        );
        return $rows ? $rows[0] : null;
    }
```

- [ ] **Step 2: Verificar sintaxis**

Run: `php -l _assets/models/MermaDiariaModel.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add _assets/models/MermaDiariaModel.php
git commit -m "feat(merma): agrega get_ultimo_sync_ok al modelo"
```

---

### Task 2: Controlador — pasar `ultimoSync` a la vista

**Files:**
- Modify: `_assets/controllers/merma.php:76-131` (método `analisis()`)

**Interfaces:**
- Consumes: `MermaDiariaModel::get_ultimo_sync_ok(): ?array` (Task 1), ya disponible vía `$this->mermaModel` (propiedad existente del controlador, línea 27/33).
- Produces: variable Twig `ultimoSync` (mismo tipo que devuelve el modelo: array con `fecha_sync`/`origen`, o `null`) disponible en `views/merma/analisis.html`.

- [ ] **Step 1: Agregar la llamada y sumarla al `compact()`**

En `_assets/controllers/merma.php`, dentro de `analisis()`, justo antes del `echo $this->twig->render(...)` (línea 129), agregar:

```php
        $ultimoSync = $this->mermaModel->get_ultimo_sync_ok();
```

Y cambiar la línea 130-131 de:

```php
        echo $this->twig->render($this->route . 'analisis.html',
            compact('anio', 'mes', 'desde', 'hasta', 'maxHasta', 'filas', 'totales',
                    'syncDesde', 'syncHasta'));
```

a:

```php
        echo $this->twig->render($this->route . 'analisis.html',
            compact('anio', 'mes', 'desde', 'hasta', 'maxHasta', 'filas', 'totales',
                    'syncDesde', 'syncHasta', 'ultimoSync'));
```

- [ ] **Step 2: Verificar sintaxis**

Run: `php -l _assets/controllers/merma.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add _assets/controllers/merma.php
git commit -m "feat(merma): pasa ultimoSync a la vista de analisis"
```

---

### Task 3: Vista — mostrar la fecha de última actualización

**Files:**
- Modify: `views/merma/analisis.html:27-31`

**Interfaces:**
- Consumes: variable Twig `ultimoSync` (Task 2) — `null` o array con clave `fecha_sync` (datetime string parseable por el filtro `date` de Twig, mismo patrón que `views/merma/detalle.html:135`).

- [ ] **Step 1: Editar el bloque del botón "Actualizar datos"**

En `views/merma/analisis.html`, reemplazar (líneas 27-31):

```twig
            <div class="col-auto ms-auto">
                <button type="button" class="btn btn-merma-sync" data-bs-toggle="modal" data-bs-target="#syncModal">
                    <i class="fas fa-sync"></i> Actualizar datos
                </button>
            </div>
```

por:

```twig
            <div class="col-auto ms-auto text-end">
                <button type="button" class="btn btn-merma-sync" data-bs-toggle="modal" data-bs-target="#syncModal">
                    <i class="fas fa-sync"></i> Actualizar datos
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

- [ ] **Step 2: Verificar visualmente**

El usuario gestiona su propio servidor de desarrollo (no levantar `php -S` — ver preferencia registrada). Pedir al usuario que recargue `/merma/analisis` y confirme que aparece el texto bajo el botón, con una fecha si `merma_sync_log` ya tiene una fila con `estaciones_ok > 0`, o "Sin sincronizar" si la tabla está vacía.

- [ ] **Step 3: Commit**

```bash
git add views/merma/analisis.html
git commit -m "feat(merma): muestra fecha de ultima actualizacion junto al boton sync"
```

---

### Task 4: Cron — ampliar el rango a "día 1 del mes → ayer"

**Files:**
- Modify: `_assets/controllers/merma.php:886-902` (método `sync_diario()`)
- Modify: `cron/merma_sync_diario.php` (comentario de cabecera)
- Modify: `docs/sql/merma_cron.md`

**Interfaces:**
- Consumes: `Merma::runSync(string $desde, string $hasta, int $codgas, string $origen, ?int $usuario): array` (ya existente, línea 762 — sin cambios de firma).
- Produces: sin cambio de firma pública; `Merma::sync_diario()` sigue siendo el único punto de entrada del cron (CLI y HTTP con `cron_token`).

- [ ] **Step 1: Cambiar el cálculo de rango en `sync_diario()`**

En `_assets/controllers/merma.php`, reemplazar (líneas 893-894):

```php
        $desde = date('Y-m-d', strtotime('-2 days'));
        $hasta = date('Y-m-d', strtotime('-1 day'));
```

por:

```php
        // Día 1 del mes en curso -> ayer, para que un fallo puntual de un
        // día no deje huecos permanentes: el cron del día siguiente lo
        // vuelve a cubrir. Si hoy es día 1, "ayer" cae en el mes anterior;
        // en ese caso se acota a solo ayer (mismo criterio que analisis()).
        $hasta = date('Y-m-d', strtotime('-1 day'));
        $desde = date('Y-m-01');
        if ($desde > $hasta) $desde = date('Y-m-01', strtotime($hasta));
```

- [ ] **Step 2: Verificar sintaxis**

Run: `php -l _assets/controllers/merma.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Verificar el caso borde manualmente**

Run:
```bash
php -r "
\$hasta = date('Y-m-d', strtotime('2026-08-01 -1 day'));
\$desde = date('Y-m-01', strtotime('2026-08-01'));
if (\$desde > \$hasta) \$desde = date('Y-m-01', strtotime(\$hasta));
echo \"desde=\$desde hasta=\$hasta\n\";
"
```
Expected: `desde=2026-07-01 hasta=2026-07-31` (si hoy fuera 2026-08-01, el rango cae completo en julio, no mezcla meses).

- [ ] **Step 4: Actualizar el comentario de cabecera del script CLI**

En `cron/merma_sync_diario.php`, reemplazar (líneas 1-13):

```php
<?php
/**
 * Tarea programada: sincronización diaria del snapshot de merma (D-2 y D-1).
 * Equivale al botón "Actualizar datos" de /merma/analisis para todas las
 * estaciones. Consulta ApiER en paralelo y reemplaza TG.dbo.merma_diaria.
 *
 * Configurar en Programador de Tareas de Windows a las 05:00 AM:
 *   Programa:   php
 *   Argumentos: C:\ruta\AplicativoPhp\cron\merma_sync_diario.php
 *
 * Nota: la ruta HTTP /merma/sync_diario NO sirve para el cron porque
 * index.php exige sesión antes de despachar al controlador.
 */
```

por:

```php
<?php
/**
 * Tarea programada: sincronización diaria del snapshot de merma, cubriendo
 * el mes en curso completo (día 1 -> ayer) para no dejar huecos si algún
 * día falla. Equivale al botón "Actualizar datos" de /merma/analisis para
 * todas las estaciones. Consulta ApiER en paralelo y reemplaza
 * TG.dbo.merma_diaria.
 *
 * Configurar en Programador de Tareas de Windows a las 06:00 AM:
 *   Programa:   php
 *   Argumentos: C:\ruta\AplicativoPhp\cron\merma_sync_diario.php
 *
 * Nota: la ruta HTTP /merma/sync_diario NO sirve para el cron porque
 * index.php exige sesión antes de despachar al controlador.
 */
```

- [ ] **Step 5: Actualizar `docs/sql/merma_cron.md`**

Reemplazar el contenido completo del archivo por:

```markdown
# Cron de merma diaria

Sincroniza el mes en curso completo (día 1 -> ayer) de todas las estaciones
cada madrugada (6:00 am). Cubrir el mes completo, no solo D-2/D-1, evita que
un fallo puntual de un día dilate el hueco: el cron del día siguiente vuelve
a intentar todo el mes. Se programa como script CLI (mismo patrón que
cron/auto_group_payments.php); la ruta HTTP /merma/sync_diario NO sirve para
el cron porque index.php exige sesión antes de despachar al controlador.

Programar en el servidor donde corren los demás crons del aplicativo
(Programador de Tareas de Windows):

    schtasks /create /tn "TG merma sync diario" /tr "php C:\ruta\AplicativoPhp\cron\merma_sync_diario.php" /sc daily /st 06:00

(Ajustar C:\ruta\ a la ruta real del working copy en ese servidor.)

Verificación: SELECT TOP 5 * FROM TG.dbo.merma_sync_log ORDER BY id DESC;
debe aparecer una fila origen='cron' cada día, con desde=día 1 del mes en
curso y hasta=ayer. Las fallas quedan con el mensaje en detalle_errores.
```

- [ ] **Step 6: Commit**

```bash
git add _assets/controllers/merma.php cron/merma_sync_diario.php docs/sql/merma_cron.md
git commit -m "feat(merma): cron diario sincroniza el mes en curso completo (dia 1 -> ayer)"
```

---

### Task 5: Verificación end-to-end manual

**Files:** ninguno (solo verificación)

- [ ] **Step 1: Ejecutar el script cron manualmente contra el entorno del usuario**

El usuario ejecuta (no levantar el servidor de desarrollo — lo gestiona él mismo):

```bash
php cron/merma_sync_diario.php
```

Expected: imprime `[<fecha>] Iniciando sincronización de merma diaria` y luego un JSON con `"success":true`, `estaciones_ok` > 0, y `filas` > 0.

- [ ] **Step 2: Confirmar el rango sincronizado en BD**

```sql
SELECT TOP 1 * FROM TG.dbo.merma_sync_log ORDER BY id DESC;
```
Expected: `origen='cron'`, `desde` = día 1 del mes en curso, `hasta` = ayer.

- [ ] **Step 3: Confirmar que la vista muestra la fecha**

El usuario recarga `/merma/analisis` y confirma que bajo "Actualizar datos" aparece `Última actualización: <fecha/hora del paso anterior>`.

- [ ] **Step 4: Reprogramar la tarea de Windows (acción manual del usuario)**

El usuario ajusta la tarea existente en el Programador de Tareas de Windows de 05:00 a 06:00 am (fuera del alcance de este repo/plan — no se ejecuta ningún comando `schtasks` desde aquí).
