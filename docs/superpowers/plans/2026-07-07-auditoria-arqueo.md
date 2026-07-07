# Auditoría completa del módulo Arqueo — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Log de auditoría unificado del módulo arqueo (tabla `arqueo_audit_log` + modelo + registro en todos los endpoints de cambio + vista `/arqueo/auditoria/{sesion_id}` con diff de cambios).

**Architecture:** PHP MVC propio (no Laravel), SQL Server (BD TG), Twig, Bootstrap. Replica el patrón `PaymentRequestAuditLog`/`PaymentRequestAuditLogModel`. Spec: `docs/superpowers/specs/2026-07-07-auditoria-arqueo-design.md`.

**Tech Stack:** PHP 8 + PDO sqlsrv, Twig, jQuery/Bootstrap, FontAwesome. Sin framework de tests: `php -l`, lint Twig y scripts de un solo uso en scratchpad contra la BD real.

## Global Constraints

- **Footgun:** `new XxxModel()` dentro de una transacción rompe el singleton PDO → `ArqueoAuditLogModel` se instancia SOLO en el constructor del controlador.
- El body AJAX se lee con el helper `input()` del controlador, no `$_POST`.
- JSON de auditoría siempre con `json_encode(..., JSON_UNESCAPED_UNICODE)`.
- Nombre de usuario: `$_SESSION['tg_user']['Nombre'] ?? $_SESSION['tg_user']['Usuario'] ?? null` (mismo campo que la auditoría de pagos).
- La tabla NO lleva FK ni ON DELETE CASCADE (la auditoría sobrevive a borrados).
- El log de `guardar_caja()` y `crear_sesion()` va DENTRO de su transacción; si el insert del log devuelve false → `throw new Exception(...)` para revertir.
- NO levantar ni recargar el servidor PHP — el usuario lo gestiona él mismo.
- Scripts temporales solo en el scratchpad de la sesión, nunca en el repo. No usar sqlcmd con contraseña en línea de comandos.
- Verificación Twig: `php <scratchpad>/twig_lint.php views/arqueo/<archivo>.html` (script existente en el scratchpad de la sesión).

---

### Task 1: Tabla `arqueo_audit_log` + modelo `ArqueoAuditLogModel`

**Files:**
- Modify: `docs/sql/arqueo_schema.sql` (agregar sección 8 al final)
- Create: `_assets/models/ArqueoAuditLogModel.php`
- Modify: `_assets/controllers/arqueo.php` (propiedades/constructor ~líneas 67-88; helper `user_name()` junto a `user_id()` ~línea 92)

**Interfaces:**
- Produces (Task 2 y 3 dependen de esto, firmas exactas):
  - `ArqueoAuditLogModel::log(string $accion, int $sesion_id, ?int $caja_id, ?int $sucursal_id, ?array $antes, ?array $despues, ?int $usuario_id, ?string $usuario_nombre): bool`
  - `ArqueoAuditLogModel::by_sesion(int $sesion_id): array`
  - Constantes: `ACC_CREAR_SESION`, `ACC_ABRIR_SESION`, `ACC_CERRAR_SESION`, `ACC_GUARDAR_CAJA`, `ACC_EDITAR_CONCENTRADO`, `ACC_EDITAR_CAPITAL_BASE`
  - Propiedad `$this->auditLogModel` en el controlador `Arqueo`
  - Helper `Arqueo::user_name(): ?string` (private)

- [ ] **Step 1: Agregar la tabla al schema**

Al final de `docs/sql/arqueo_schema.sql`:

```sql
/* ---------------------------------------------------------------------------
   8) Log de auditoría del módulo arqueo.
   Quién, cuándo, qué acción y qué cambió (JSON antes/después).
   Sin FK a propósito: la auditoría sobrevive a cualquier borrado.
   --------------------------------------------------------------------------- */
IF OBJECT_ID('[TG].[dbo].[arqueo_audit_log]', 'U') IS NULL
BEGIN
    CREATE TABLE [TG].[dbo].[arqueo_audit_log] (
        [id]               INT IDENTITY(1,1) NOT NULL,
        [sesion_id]        INT               NOT NULL,
        [caja_id]          INT               NULL,
        [sucursal_id]      INT               NULL,
        [accion]           VARCHAR(30)       NOT NULL,
        [usuario_id]       INT               NULL,
        [usuario_nombre]   NVARCHAR(120)     NULL,
        [datos_anteriores] NVARCHAR(MAX)     NULL,
        [datos_nuevos]     NVARCHAR(MAX)     NULL,
        [fecha]            DATETIME          NOT NULL
                           CONSTRAINT [DF_aal_fecha] DEFAULT (GETDATE()),
        CONSTRAINT [PK_arqueo_audit_log] PRIMARY KEY CLUSTERED ([id])
    );
    CREATE INDEX [IX_aal_sesion] ON [TG].[dbo].[arqueo_audit_log] ([sesion_id]);
END
GO
```

- [ ] **Step 2: Ejecutar el DDL en la BD TG**

Script PHP de un solo uso en el scratchpad con la conexión de la app (boilerplate probado en tareas anteriores):

```php
<?php
define('_DONTCHECKSESSION', true);
$base = 'C:/Users/alejandro.martinez/Desktop/codigo/AplicativoPhp';
$_SERVER['DOCUMENT_ROOT'] = $base;
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['HTTP_HOST'] = 'localhost';
chdir($base);
require_once $base . '/_assets/classes/header.class.php';
require_once $base . '/_assets/classes/common/MySqlPdoHandler.class.php';
$db = MySqlPdoHandler::getInstance();
// Ejecutar el CREATE TABLE + CREATE INDEX (sin USE ni GO) con $db->query(...)
// OJO: $db->update() exige 2 argumentos y valida keyword; $db->query() es agnóstico.
```

Verificar: `SELECT OBJECT_ID('TG.dbo.arqueo_audit_log')` no es NULL.

- [ ] **Step 3: Crear el modelo**

Crear `_assets/models/ArqueoAuditLogModel.php`:

```php
<?php

/**
 * Log de auditoría del módulo arqueo: quién, cuándo, qué acción y qué
 * cambió (JSON antes/después). Patrón de PaymentRequestAuditLogModel.
 * Tabla: [TG].[dbo].[arqueo_audit_log]
 */
class ArqueoAuditLogModel extends Model
{
    const ACC_CREAR_SESION        = 'CREAR_SESION';
    const ACC_ABRIR_SESION        = 'ABRIR_SESION';
    const ACC_CERRAR_SESION       = 'CERRAR_SESION';
    const ACC_GUARDAR_CAJA        = 'GUARDAR_CAJA';
    const ACC_EDITAR_CONCENTRADO  = 'EDITAR_CONCENTRADO';
    const ACC_EDITAR_CAPITAL_BASE = 'EDITAR_CAPITAL_BASE';

    /**
     * Inserta un movimiento. $antes/$despues son arrays (o null) y se
     * serializan a JSON. Devuelve false si el INSERT falla.
     */
    public function log(
        string $accion,
        int $sesion_id,
        ?int $caja_id,
        ?int $sucursal_id,
        ?array $antes,
        ?array $despues,
        ?int $usuario_id,
        ?string $usuario_nombre
    ): bool {
        return (bool) $this->sql->insert(
            "INSERT INTO [TG].[dbo].[arqueo_audit_log]
                (sesion_id, caja_id, sucursal_id, accion, usuario_id,
                 usuario_nombre, datos_anteriores, datos_nuevos)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?);",
            [
                $sesion_id,
                $caja_id,
                $sucursal_id,
                $accion,
                $usuario_id,
                $usuario_nombre,
                $antes === null ? null : json_encode($antes, JSON_UNESCAPED_UNICODE),
                $despues === null ? null : json_encode($despues, JSON_UNESCAPED_UNICODE),
            ]
        );
    }

    /** Movimientos de una sesión, más reciente primero. */
    public function by_sesion(int $sesion_id): array
    {
        return $this->sql->select(
            "SELECT * FROM [TG].[dbo].[arqueo_audit_log]
             WHERE sesion_id = ?
             ORDER BY fecha DESC, id DESC;",
            [$sesion_id]
        ) ?: [];
    }
}
```

Nota: `Model::insert` de este proyecto rethrows excepciones en transacción; el
`(bool)` cubre el caso de guard-path false.

- [ ] **Step 4: Instanciar en el constructor y agregar helper `user_name()`**

En `_assets/controllers/arqueo.php`, junto a las propiedades existentes:

```php
    public ArqueoCapitalBaseModel $capitalBaseModel;
    public ArqueoAuditLogModel $auditLogModel;
```

en `__construct`:

```php
        $this->capitalBaseModel       = new ArqueoCapitalBaseModel();
        $this->auditLogModel          = new ArqueoAuditLogModel();
```

y debajo de `user_id()` (~línea 92):

```php
    /** Nombre visible del usuario logueado (mismo campo que la auditoría de pagos). */
    private function user_name(): ?string
    {
        return $_SESSION['tg_user']['Nombre']
            ?? $_SESSION['tg_user']['Usuario']
            ?? null;
    }
```

- [ ] **Step 5: Verificar**

`php -l _assets/models/ArqueoAuditLogModel.php && php -l _assets/controllers/arqueo.php` → sin errores.

Script de un solo uso en scratchpad (mismo boilerplate + `require _assets/models/Model.php` y `_assets/models/ArqueoAuditLogModel.php` si no autocargan): insertar un log de prueba `log(ArqueoAuditLogModel::ACC_ABRIR_SESION, 999999, null, null, ['estado'=>'programado'], ['estado'=>'abierto'], 1, 'PRUEBA')`, leer con `by_sesion(999999)` (1 fila, JSON correcto con acentos si se incluye uno), y borrar con `DELETE FROM TG.dbo.arqueo_audit_log WHERE sesion_id = 999999`.

- [ ] **Step 6: Commit**

```bash
git add docs/sql/arqueo_schema.sql _assets/models/ArqueoAuditLogModel.php _assets/controllers/arqueo.php
git commit -m "feat(arqueo): tabla y modelo de log de auditoria"
```

---

### Task 2: Registrar movimientos en los endpoints

**Files:**
- Modify: `_assets/controllers/arqueo.php` — `crear_sesion()` (~línea 167), `abrir()` (~224), `cerrar()` (~245), `guardar_caja()` (~305), `guardar_concentrado_extra()` (~425), y un helper privado nuevo `snapshot_caja()` junto a `calcular_totales_caja()`.

**Interfaces:**
- Consumes: `ArqueoAuditLogModel::log(...)` y constantes vía `$this->auditLogModel` (Task 1); `$this->user_name()` (Task 1).
- Produces: registros en `arqueo_audit_log` que la vista (Task 3) leerá con `by_sesion()`. Estructura del snapshot de caja (Task 3 la compara en JS): claves `cajero_nombre, encargado_revision, go_exchange_dolares, go_exchange_mxn, costo_promedio, totales{total_fisico_dolares,total_fisico_mxn,total_en_sistema,gran_total_vales_mxn,resultado_final}, denominaciones[{seccion,moneda,tipo,denominacion,cantidad,total}], vales[{numero_vale,fecha,concepto,dolares,mxn}]`.

- [ ] **Step 1: Helper `snapshot_caja()`**

Agregar en la sección "Lógica de cálculo y entrada" (antes de `calcular_totales_caja()`):

```php
    /**
     * Snapshot uniforme de una caja para auditoría. $enc acepta tanto la fila
     * de arqueo_cajas como el array de totales calculados (mismas claves).
     */
    private function snapshot_caja(array $enc, array $denominaciones, array $vales): array
    {
        return [
            'cajero_nombre'       => $enc['cajero_nombre'] ?? null,
            'encargado_revision'  => $enc['encargado_revision'] ?? null,
            'go_exchange_dolares' => (float) ($enc['go_exchange_dolares'] ?? 0),
            'go_exchange_mxn'     => (float) ($enc['go_exchange_mxn'] ?? 0),
            'costo_promedio'      => (float) ($enc['costo_promedio'] ?? 0),
            'totales' => [
                'total_fisico_dolares' => (float) ($enc['total_fisico_dolares'] ?? 0),
                'total_fisico_mxn'     => (float) ($enc['total_fisico_mxn'] ?? 0),
                'total_en_sistema'     => (float) ($enc['total_en_sistema'] ?? 0),
                'gran_total_vales_mxn' => (float) ($enc['gran_total_vales_mxn'] ?? 0),
                'resultado_final'      => (float) ($enc['resultado_final'] ?? 0),
            ],
            'denominaciones' => array_values(array_map(fn($d) => [
                'seccion'      => $d['seccion'],
                'moneda'       => $d['moneda'],
                'tipo'         => $d['tipo'],
                'denominacion' => (float) $d['denominacion'],
                'cantidad'     => (int) ($d['cantidad'] ?? 0),
                'total'        => (float) ($d['total'] ?? 0),
            ], $denominaciones)),
            'vales' => array_values(array_map(fn($v) => [
                'numero_vale' => $v['numero_vale'] ?? null,
                'fecha'       => $v['fecha'] ?? null,
                'concepto'    => $v['concepto'] ?? null,
                'dolares'     => (float) ($v['dolares'] ?? 0),
                'mxn'         => (float) ($v['mxn'] ?? 0),
            ], $vales)),
        ];
    }
```

- [ ] **Step 2: Log en `crear_sesion()`**

Dentro del `try`, después del `foreach` y antes del `commit`:

```php
            $ok_log = $this->auditLogModel->log(
                ArqueoAuditLogModel::ACC_CREAR_SESION,
                (int) $sesion_id, null, null,
                null,
                ['nombre' => $nombre, 'fecha' => $fecha],
                $this->user_id(), $this->user_name()
            );
            if (!$ok_log) {
                throw new Exception('No se pudo registrar la auditoría.');
            }
```

- [ ] **Step 3: Log en `abrir()` y `cerrar()`**

En `abrir()`, reemplazar el cierre:

```php
        $ok = $this->sesionesModel->set_estado((int) $sesion_id, 'abierto');
        if ($ok) {
            $this->auditLogModel->log(
                ArqueoAuditLogModel::ACC_ABRIR_SESION,
                (int) $sesion_id, null, null,
                ['estado' => 'programado'], ['estado' => 'abierto'],
                $this->user_id(), $this->user_name()
            );
        }
        $this->json(['success' => (bool) $ok]);
```

En `cerrar()`, reemplazar el cierre:

```php
        $ok = $this->sesionesModel->set_estado((int) $sesion_id, 'cerrado', $this->user_id());
        if ($ok) {
            $this->auditLogModel->log(
                ArqueoAuditLogModel::ACC_CERRAR_SESION,
                (int) $sesion_id, null, null,
                ['estado' => 'abierto'], ['estado' => 'cerrado'],
                $this->user_id(), $this->user_name()
            );
        }
        $this->json(['success' => (bool) $ok]);
```

- [ ] **Step 4: Log con snapshot completo en `guardar_caja()`**

Después de `$in = $this->input();` y de calcular `$denom_rows`/`$vales_in`/`$go`/`$totales` (el código existente no cambia), y ANTES de `$this->sql_begin();`, construir el snapshot previo (la caja `$caja` ya está cargada; leer denominaciones y vales actuales ANTES del DELETE):

```php
        $snap_antes = $this->snapshot_caja(
            $caja,
            $this->denominacionesModel->by_caja((int) $caja_id),
            $this->valesModel->by_caja((int) $caja_id)
        );
```

Dentro del `try`, después de `$this->cajasModel->update_totales(...)` y antes del `commit`:

```php
            $snap_despues = $this->snapshot_caja($totales, $denom_rows, $vales_in);
            $ok_log = $this->auditLogModel->log(
                ArqueoAuditLogModel::ACC_GUARDAR_CAJA,
                (int) $caja['sesion_id'], (int) $caja_id, (int) $caja['sucursal_id'],
                $snap_antes, $snap_despues,
                $this->user_id(), $this->user_name()
            );
            if (!$ok_log) {
                throw new Exception('No se pudo registrar la auditoría de la caja.');
            }
```

(Nota: `$totales` ya incluye `cajero_nombre` y `encargado_revision` porque el
código existente se los asigna antes de la transacción.)

- [ ] **Step 5: Log en `guardar_concentrado_extra()`**

Reemplazar el cuerpo desde la línea del upsert (conservando validaciones y `$datos` como están):

```php
        $previos = $this->concentradoExtrasModel->by_sesion($sesion_id)[$sucursal_id] ?? null;
        $antes = $previos === null ? null : [
            'capital_trabajo' => (float) $previos['capital_trabajo'],
            'gastos_tramite'  => (float) $previos['gastos_tramite'],
            'adeudo'          => (float) $previos['adeudo'],
            'reinversion'     => (float) $previos['reinversion'],
            'utilidad'        => (float) $previos['utilidad'],
        ];

        $ok = $this->concentradoExtrasModel->upsert($sesion_id, $sucursal_id, $datos, $this->user_id());

        if ($ok) {
            $this->auditLogModel->log(
                ArqueoAuditLogModel::ACC_EDITAR_CONCENTRADO,
                $sesion_id, null, $sucursal_id,
                $antes, $datos,
                $this->user_id(), $this->user_name()
            );
        }

        if ($ok && !empty($in['actualizar_base'])) {
            $base_anterior = $this->capitalBaseModel->get_all()[$sucursal_id] ?? null;
            $this->capitalBaseModel->upsert(
                $sucursal_id,
                $datos['capital_trabajo'],
                $this->user_id()
            );
            $this->auditLogModel->log(
                ArqueoAuditLogModel::ACC_EDITAR_CAPITAL_BASE,
                $sesion_id, null, $sucursal_id,
                $base_anterior === null ? null : ['capital_trabajo' => $base_anterior],
                ['capital_trabajo' => $datos['capital_trabajo']],
                $this->user_id(), $this->user_name()
            );
        }

        $this->json(['success' => $ok]);
```

- [ ] **Step 6: Verificar**

`php -l _assets/controllers/arqueo.php` → sin errores.

Script de un solo uso en scratchpad contra la BD real que simule los flujos SIN HTTP (instanciar los modelos directamente y reproducir la secuencia de `guardar_concentrado_extra`: leer previos → upsert con los MISMOS valores actuales → log) sobre la sesión 1, sucursal 2, y verificar que `by_sesion(1)` devuelve el registro EDITAR_CONCENTRADO con `datos_anteriores` = `datos_nuevos` (sin cambio real de datos). Borrar el registro de prueba al final (`DELETE FROM TG.dbo.arqueo_audit_log WHERE id = <id insertado>`). Los flujos completos de caja se validan en navegador (checklist final del feature).

- [ ] **Step 7: Commit**

```bash
git add _assets/controllers/arqueo.php
git commit -m "feat(arqueo): registrar auditoria en crear/abrir/cerrar, captura de caja y concentrado"
```

---

### Task 3: Vista `/arqueo/auditoria/{sesion_id}` con diff de cambios

**Files:**
- Modify: `_assets/controllers/arqueo.php` (método nuevo `auditoria()` después de `concentrado()`)
- Create: `views/arqueo/auditoria.html`
- Modify: `views/arqueo/index.html` (botón Auditoría en columna Acciones, solo admin)
- Modify: `views/arqueo/concentrado.html` (botón Auditoría junto a Regresar en `menutitle`)

**Interfaces:**
- Consumes: `$this->auditLogModel->by_sesion(int): array` (filas con `id, sesion_id, caja_id, sucursal_id, accion, usuario_id, usuario_nombre, datos_anteriores, datos_nuevos, fecha`); constantes de acción (Task 1); estructura del snapshot de caja (Task 2).

- [ ] **Step 1: Método `auditoria()` en el controlador**

Después de `concentrado()`:

```php
    /** Historial de auditoría de una sesión (solo admin). */
    public function auditoria($sesion_id): void
    {
        $this->guard([self::PERM_ADMIN]);

        $sesion = $this->sesionesModel->find((int) $sesion_id);
        if (!$sesion) {
            (new Errors())->get404();
            return;
        }
        $logs = $this->auditLogModel->by_sesion((int) $sesion_id);

        // Mapa sucursal_id => nombre para mostrar en la tabla
        $sucursales = [];
        foreach (self::SUCURSALES as $s) {
            $sucursales[$s['id']] = $s['nombre'];
        }

        echo $this->twig->render($this->route . 'auditoria.html', compact('sesion', 'logs', 'sucursales'));
    }
```

- [ ] **Step 2: Template `views/arqueo/auditoria.html`**

Crear el archivo completo:

```twig
{# views/arqueo/auditoria.html — Historial de auditoría de una sesión #}
{% extends "views/layouts/base.html" %}
{% block title %}Auditoría — {{ sesion.nombre }}{% endblock %}
{% block mycss %}
<style>
    #tabla_auditoria thead th { text-transform:uppercase; font-size:.72rem;
        letter-spacing:.04em; color:#6c757d; }
    #tabla_auditoria tbody td { vertical-align:middle; padding:.55rem .75rem; }
    .badge-accion { display:inline-flex; align-items:center; gap:.45em;
        font-size:.75rem; font-weight:600; padding:.3em .8em; border-radius:999px; }
    .badge-accion::before { content:''; width:.5em; height:.5em;
        border-radius:50%; background:currentColor; }
    .acc-CREAR_SESION        { background:rgb(108 117 125 / 12%); color:#5c636a; }
    .acc-ABRIR_SESION        { background:rgb(25 135 84 / 12%);   color:#198754; }
    .acc-CERRAR_SESION       { background:rgb(28 69 135 / 10%);   color:#1C4587; }
    .acc-GUARDAR_CAJA        { background:rgb(131 22 14 / 10%);   color:#83160E; }
    .acc-EDITAR_CONCENTRADO  { background:rgb(28 69 135 / 10%);   color:#1C4587; }
    .acc-EDITAR_CAPITAL_BASE { background:rgb(180 83 9 / 12%);    color:#B45309; }
    .fecha-log { white-space:nowrap; font-variant-numeric:tabular-nums; }
    #tabla_diff td, #tabla_diff th { font-size:.85rem; }
    #tabla_diff .val-antes  { color:#dc3545; }
    #tabla_diff .val-despues{ color:#198754; font-weight:600; }
</style>
{% endblock %}
{% block menutitle %}
<div style="display:flex; justify-content:space-between; align-items:center;">
    <span>Auditoría — {{ sesion.nombre }} ({{ sesion.fecha|date('d/m/Y') }})</span>
    <a href="/arqueo" class="btn btn-primary text-light small" style="border-radius:15px;">
        <i class="fas fa-backward"></i> Regresar
    </a>
</div>
{% endblock %}
{% block content %}

{% set etiquetas = {
    'CREAR_SESION': 'Creó el arqueo',
    'ABRIR_SESION': 'Abrió el arqueo',
    'CERRAR_SESION': 'Cerró el arqueo',
    'GUARDAR_CAJA': 'Guardó captura de caja',
    'EDITAR_CONCENTRADO': 'Editó concentrado',
    'EDITAR_CAPITAL_BASE': 'Actualizó capital base'
} %}

<div class="card">
  <div class="card-body table-responsive">
    <table id="tabla_auditoria" class="table table-hover" style="width:100%;">
      <thead>
        <tr>
          <th>Fecha</th>
          <th>Usuario</th>
          <th>Acción</th>
          <th>Sucursal / Caja</th>
          <th class="text-end">Detalle</th>
        </tr>
      </thead>
      <tbody>
        {% for l in logs %}
        <tr>
          <td class="fecha-log">{{ l.fecha|date('d/m/Y H:i') }}</td>
          <td title="ID {{ l.usuario_id }}">{{ l.usuario_nombre ?: ('Usuario ' ~ l.usuario_id) }}</td>
          <td><span class="badge-accion acc-{{ l.accion }}">{{ etiquetas[l.accion] ?? l.accion }}</span></td>
          <td>
            {% if l.sucursal_id %}{{ sucursales[l.sucursal_id] ?? ('Sucursal ' ~ l.sucursal_id) }}{% endif %}
            {% if l.caja_id %} <span class="text-muted small">(caja #{{ l.caja_id }})</span>{% endif %}
          </td>
          <td class="text-end">
            {% if l.datos_anteriores or l.datos_nuevos %}
            <button type="button" class="btn btn-sm btn-outline-secondary btn-ver-cambios"
                    data-antes="{{ l.datos_anteriores|e('html_attr') }}"
                    data-despues="{{ l.datos_nuevos|e('html_attr') }}">
              <i class="fas fa-exchange-alt"></i> Ver cambios
            </button>
            {% endif %}
          </td>
        </tr>
        {% endfor %}
      </tbody>
    </table>
  </div>
</div>

<!-- Modal de diff -->
<div class="modal fade" id="modal_diff" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Cambios del movimiento</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <table class="table table-sm" id="tabla_diff">
          <thead><tr><th>Campo</th><th>Antes</th><th>Después</th></tr></thead>
          <tbody></tbody>
        </table>
        <p id="diff_sin_cambios" class="text-muted mb-0" style="display:none;">
          Sin cambios en los valores (se guardó lo mismo).
        </p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>

{% endblock %}
{% block myjs %}
<script>
  var ETIQUETAS_CAMPO = {
    cajero_nombre: 'Cajero', encargado_revision: 'Encargado de revisión',
    go_exchange_dolares: 'Go Exchange USD', go_exchange_mxn: 'Go Exchange MXN',
    costo_promedio: 'Costo promedio',
    total_fisico_dolares: 'Total físico USD', total_fisico_mxn: 'Total físico MXN',
    total_en_sistema: 'Total en sistema', gran_total_vales_mxn: 'Total vales',
    resultado_final: 'Resultado final',
    capital_trabajo: 'Capital de Trabajo', gastos_tramite: 'Gastos en trámite',
    adeudo: 'Adeudo', reinversion: 'Reinversión', utilidad: 'Utilidad',
    estado: 'Estado', nombre: 'Nombre', fecha: 'Fecha'
  };
  var SECCIONES = { cajon: 'Cajón', morrallero: 'Morrallero',
    caja_fuerte: 'Caja Fuerte', morrallero_cf: 'Morrallero CF' };

  function fmtVal(v) {
    if (v === null || v === undefined || v === '') return '—';
    if (typeof v === 'number') return v.toLocaleString('es-MX', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    return String(v);
  }

  function filaDiff(campo, antes, despues) {
    return '<tr><td>' + campo + '</td>' +
      '<td class="val-antes">' + fmtVal(antes) + '</td>' +
      '<td class="val-despues">' + fmtVal(despues) + '</td></tr>';
  }

  // Compara escalares planos de dos objetos (ignora sub-objetos/arrays)
  function diffPlano(a, b, prefijo) {
    var filas = '';
    var claves = {};
    Object.keys(a || {}).concat(Object.keys(b || {})).forEach(function (k) { claves[k] = true; });
    Object.keys(claves).forEach(function (k) {
      var va = a ? a[k] : undefined, vb = b ? b[k] : undefined;
      if (typeof va === 'object' && va !== null) return;
      if (typeof vb === 'object' && vb !== null) return;
      if (String(va ?? '') !== String(vb ?? '')) {
        filas += filaDiff((prefijo || '') + (ETIQUETAS_CAMPO[k] || k), va, vb);
      }
    });
    return filas;
  }

  function claveDenom(d) { return d.seccion + '|' + d.moneda + '|' + d.tipo + '|' + d.denominacion; }
  function etiquetaDenom(d) {
    return (SECCIONES[d.seccion] || d.seccion) + ' · ' +
      (d.tipo.charAt(0).toUpperCase() + d.tipo.slice(1)) + ' $' + d.denominacion + ' ' + d.moneda;
  }

  function construirDiff(antes, despues) {
    var filas = diffPlano(antes, despues, '');
    if (antes && despues && (antes.totales || despues.totales)) {
      filas += diffPlano((antes || {}).totales, (despues || {}).totales, '');
    }
    // Denominaciones: comparar cantidades por clave seccion|moneda|tipo|denominacion
    if ((antes && antes.denominaciones) || (despues && despues.denominaciones)) {
      var mapA = {}, mapB = {};
      ((antes && antes.denominaciones) || []).forEach(function (d) { mapA[claveDenom(d)] = d; });
      ((despues && despues.denominaciones) || []).forEach(function (d) { mapB[claveDenom(d)] = d; });
      var claves = {};
      Object.keys(mapA).concat(Object.keys(mapB)).forEach(function (k) { claves[k] = true; });
      Object.keys(claves).sort().forEach(function (k) {
        var ca = mapA[k] ? mapA[k].cantidad : 0;
        var cb = mapB[k] ? mapB[k].cantidad : 0;
        if (Number(ca) !== Number(cb)) {
          filas += filaDiff(etiquetaDenom(mapA[k] || mapB[k]), ca, cb);
        }
      });
    }
    // Vales: comparar por posición
    if ((antes && antes.vales) || (despues && despues.vales)) {
      var va = (antes && antes.vales) || [], vb = (despues && despues.vales) || [];
      var n = Math.max(va.length, vb.length);
      for (var i = 0; i < n; i++) {
        var f = diffPlano(va[i] || {}, vb[i] || {}, 'Vale ' + (i + 1) + ' · ');
        filas += f;
      }
    }
    return filas;
  }

  $(document).on('click', '.btn-ver-cambios', function () {
    var antes = null, despues = null;
    try { antes = JSON.parse($(this).attr('data-antes') || 'null'); } catch (e) {}
    try { despues = JSON.parse($(this).attr('data-despues') || 'null'); } catch (e) {}
    var filas = construirDiff(antes, despues);
    $('#tabla_diff tbody').html(filas);
    $('#tabla_diff').toggle(filas !== '');
    $('#diff_sin_cambios').toggle(filas === '');
    $('#modal_diff').modal('show');
  });
</script>
{% endblock %}
```

- [ ] **Step 3: Botones de acceso**

En `views/arqueo/index.html`, dentro de `{% if es_admin %}` de la columna Acciones, después del bloque Abrir/Cerrar (donde estaba el botón de exportar):

```twig
                                <a href="/arqueo/auditoria/{{ s.id }}" class="btn btn-sm btn-outline-secondary" title="Auditoría de la sesión">
                                    <i class="fas fa-history"></i>
                                </a>
```

En `views/arqueo/concentrado.html`, en el bloque `menutitle`, antes del botón Regresar:

```twig
    <span>
        <a href="/arqueo/auditoria/{{ sesion.id }}" class="btn btn-outline-light small" style="border-radius:15px; border-color:#fff;">
            <i class="fas fa-history"></i> Auditoría
        </a>
        <a href="/arqueo" class="btn btn-primary text-light small" style="border-radius:15px;">
            <i class="fas fa-backward"></i> Regresar
        </a>
    </span>
```

(reemplazando el `<a>` de Regresar existente por este `<span>` con ambos botones).

- [ ] **Step 4: Verificar**

- `php -l _assets/controllers/arqueo.php` → sin errores.
- Lint Twig de las tres vistas: `php <scratchpad>/twig_lint.php views/arqueo/auditoria.html` (y `index.html`, `concentrado.html`) → `TWIG OK`.
- Script scratchpad: insertar 2 logs de prueba en una sesión real (uno EDITAR_CONCENTRADO con cambio de capital 300000→350000 y uno GUARDAR_CAJA con snapshot mínimo donde solo cambia una denominación), validar que `by_sesion` los devuelve y que sus JSON parsean con `json_decode`. Borrar los registros de prueba al final. La validación visual del diff (modal en navegador) queda en el checklist manual — no hay forma de renderizar la página sin el servidor del usuario.

- [ ] **Step 5: Commit**

```bash
git add _assets/controllers/arqueo.php views/arqueo/auditoria.html views/arqueo/index.html views/arqueo/concentrado.html
git commit -m "feat(arqueo): vista de auditoria por sesion con diff de cambios"
```

---

## Checklist de verificación manual final (navegador, la hace el usuario)

1. Capturar una caja de un arqueo abierto → en `/arqueo/auditoria/{id}` aparece "Guardó captura de caja" con tu usuario; "Ver cambios" muestra el conteo completo como nuevo.
2. Recapturar la misma caja cambiando UNA denominación → el diff muestra solo esa denominación (ej. "Cajón · Billete $100 USD: 50 → 45") y los totales afectados.
3. Editar un campo del concentrado (inline y con modal) → aparece "Editó concentrado" con antes/después solo del campo cambiado.
4. Editar capital con checkbox de base → aparecen DOS registros: "Editó concentrado" y "Actualizó capital base".
5. Abrir y cerrar un arqueo → registros con el usuario correcto.
6. El botón de auditoría solo lo ve el admin (permiso 73).
