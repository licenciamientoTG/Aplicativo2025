# Asignación de usuarios a cajas de arqueo — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** El admin asigna usuarios con permiso `arqueo_capturar` a cajas específicas desde `/arqueo/cajas/{id}`; el capturista solo ve/entra/guarda sus cajas asignadas; cajas sin asignar son solo para admins; todo queda auditado.

**Architecture:** PHP MVC propio (no Laravel), SQL Server (BD TG), Twig, jQuery/Bootstrap. Columna `asignado_user_id` en `arqueo_cajas` + enforcement en el controlador `Arqueo` + dropdown AJAX en la vista de cajas + acción nueva en el log de auditoría existente. Spec: `docs/superpowers/specs/2026-07-07-asignacion-cajas-arqueo-design.md`.

**Tech Stack:** PHP 8 + PDO sqlsrv, Twig, jQuery/fetch, FontAwesome. Sin framework de tests: `php -l`, lint Twig (`php <scratchpad>/twig_lint.php <vista>`) y scripts de un solo uso contra la BD real.

## Global Constraints

- **Footgun:** `new XxxModel()` dentro de una transacción rompe el singleton PDO → `UsuariosModel` se instancia SOLO en el constructor de `Arqueo`.
- Permisos: admin = `Arqueo::PERM_ADMIN` (73), capturista = `Arqueo::PERM_AUDITOR` (74); helper global `authorized($id)`.
- Regla de acceso (spec): admin entra a todo; capturista solo a cajas con `asignado_user_id == user_id()`; caja sin asignar → SOLO admin.
- El body AJAX se lee con `input()`, no `$_POST`.
- Auditoría: toda asignación registra `ACC_ASIGNAR_CAJA` vía `$this->auditLogModel->log(...)` con antes/después `{asignado_user_id, asignado_nombre}`.
- La lista de usuarios asignables NO filtra por correo: `get_users_by_permission(self::PERM_AUDITOR, false)`.
- NO levantar ni recargar el servidor PHP; scripts temporales solo en el scratchpad; no sqlcmd con contraseña en línea de comandos.
- Los scripts de BD deben dejar los datos como estaban (revertir asignaciones de prueba y borrar logs de prueba).

---

### Task 1: Columna SQL + modelos

**Files:**
- Modify: `docs/sql/arqueo_schema.sql` (sección 9 al final)
- Modify: `_assets/models/ArqueoCajasModel.php` (`by_sesion()` ~línea 12; métodos nuevos al final)
- Modify: `_assets/models/UsuariosModel.php` (`get_users_by_permission()` ~línea 133)
- Modify: `_assets/models/ArqueoAuditLogModel.php` (constante nueva)

**Interfaces:**
- Produces (Tasks 2-3 dependen, firmas exactas):
  - Columna `arqueo_cajas.asignado_user_id INT NULL` (ya ejecutada en la BD).
  - `ArqueoCajasModel::by_sesion(int $sesion_id): array` — ahora cada fila incluye `asignado_nombre` (NULL si no hay asignado).
  - `ArqueoCajasModel::by_sesion_asignadas(int $sesion_id, int $user_id): array` — mismas columnas, filtrado.
  - `ArqueoCajasModel::asignar(int $caja_id, ?int $user_id): bool`.
  - `UsuariosModel::get_users_by_permission($permission_id, bool $require_email = true): array` — filas con `Id, Usuario, Nombre, Correo, Estatus, Perfil`.
  - Constante `ArqueoAuditLogModel::ACC_ASIGNAR_CAJA = 'ASIGNAR_CAJA'`.

- [ ] **Step 1: Sección 9 del schema**

Al final de `docs/sql/arqueo_schema.sql`:

```sql
/* ---------------------------------------------------------------------------
   9) Asignación de usuario capturista por caja.
   NULL = sin asignar (solo administradores pueden capturar esa caja).
   --------------------------------------------------------------------------- */
IF COL_LENGTH('[TG].[dbo].[arqueo_cajas]', 'asignado_user_id') IS NULL
    ALTER TABLE [TG].[dbo].[arqueo_cajas] ADD [asignado_user_id] INT NULL;
GO
```

- [ ] **Step 2: Ejecutar el ALTER en la BD TG**

Script PHP de un solo uso en el scratchpad
(`C:\Users\ALEJAN~1.MAR\AppData\Local\Temp\claude\C--Users-alejandro-martinez-Desktop-codigo-AplicativoPhp\148fa2dd-6361-4c4f-923e-e19df330768b\scratchpad\`),
boilerplate probado:

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
$db->query("IF COL_LENGTH('[TG].[dbo].[arqueo_cajas]', 'asignado_user_id') IS NULL
    ALTER TABLE [TG].[dbo].[arqueo_cajas] ADD [asignado_user_id] INT NULL;");
$r = $db->select("SELECT COL_LENGTH('[TG].[dbo].[arqueo_cajas]', 'asignado_user_id') AS c");
echo $r[0]['c'] !== null ? "COLUMNA OK\n" : "FALTA COLUMNA\n";
```

Esperado: `COLUMNA OK`.

- [ ] **Step 3: `ArqueoCajasModel` — JOIN y métodos nuevos**

Reemplazar `by_sesion()`:

```php
    /**
     * Todas las cajas de una sesión, con el nombre del usuario asignado.
     */
    public function by_sesion(int $sesion_id): array
    {
        $query = "
            SELECT c.*, u.Nombre AS asignado_nombre
            FROM [TG].[dbo].[arqueo_cajas] c
            LEFT JOIN [TG].[dbo].[Usuario] u ON u.Id = c.asignado_user_id
            WHERE c.sesion_id = ?
            ORDER BY c.sucursal_id, c.caja_numero;
        ";
        return $this->sql->select($query, [$sesion_id]) ?: [];
    }
```

Agregar al final de la clase:

```php
    /**
     * Cajas de la sesión asignadas a un usuario (vista del capturista).
     */
    public function by_sesion_asignadas(int $sesion_id, int $user_id): array
    {
        $query = "
            SELECT c.*, u.Nombre AS asignado_nombre
            FROM [TG].[dbo].[arqueo_cajas] c
            LEFT JOIN [TG].[dbo].[Usuario] u ON u.Id = c.asignado_user_id
            WHERE c.sesion_id = ? AND c.asignado_user_id = ?
            ORDER BY c.sucursal_id, c.caja_numero;
        ";
        return $this->sql->select($query, [$sesion_id, $user_id]) ?: [];
    }

    /**
     * Asigna (o desasigna con NULL) el usuario capturista de una caja.
     */
    public function asignar(int $caja_id, ?int $user_id): bool
    {
        return (bool) $this->sql->update(
            "UPDATE [TG].[dbo].[arqueo_cajas] SET asignado_user_id = ?, updated_at = GETDATE()
             WHERE id = ?;",
            [$user_id, $caja_id]
        );
    }
```

- [ ] **Step 4: `UsuariosModel::get_users_by_permission` — parámetro opcional**

Reemplazar la firma y el query (el resto del método queda igual):

```php
    public function get_users_by_permission($permission_id, bool $require_email = true) : array {
        try {
            $filtro_correo = $require_email
                ? "AND t1.Correo IS NOT NULL
                    AND t1.Correo != ''"
                : "";
            $query = "
                SELECT DISTINCT
                    t1.Id,
                    t1.Usuario,
                    t1.Nombre,
                    t1.Correo,
                    t1.Estatus,
                    t2.Nombre as Perfil
                FROM [TG].[dbo].[Usuario] t1
                INNER JOIN [TG].[dbo].[tg_permissions_users] t3 ON t1.Id = t3.user_id
                LEFT JOIN [TG].[dbo].[Perfil] t2 ON t1.IdPerfil = t2.Id
                WHERE t3.permission_id = ? 
                    AND t1.Estatus = 1
                    {$filtro_correo}
            ";
```

(Las llamadas existentes no pasan el segundo argumento → comportamiento idéntico.)

- [ ] **Step 5: Constante de auditoría**

En `_assets/models/ArqueoAuditLogModel.php`, junto a las demás constantes:

```php
    const ACC_ASIGNAR_CAJA        = 'ASIGNAR_CAJA';
```

- [ ] **Step 6: Verificar**

- `php -l` en los 3 modelos → sin errores.
- Script scratchpad contra la BD: `by_sesion(4)` trae la clave `asignado_nombre` (NULL); `asignar(<primera caja de sesion 4>, <un Id real de TG.dbo.Usuario con permiso 74 — obtenerlo con get_users_by_permission(74, false)>)` → `by_sesion_asignadas(4, ese_id)` devuelve 1 fila con `asignado_nombre` correcto → `asignar(caja, null)` revierte → `by_sesion_asignadas` devuelve 0. `get_users_by_permission(74, false)` devuelve al menos las columnas Id/Nombre. Dejar la BD como estaba.

- [ ] **Step 7: Commit**

```bash
git add docs/sql/arqueo_schema.sql _assets/models/ArqueoCajasModel.php _assets/models/UsuariosModel.php _assets/models/ArqueoAuditLogModel.php
git commit -m "feat(arqueo): columna asignado_user_id y metodos de asignacion de cajas"
```

---

### Task 2: Enforcement y endpoint de asignación en el controlador

**Files:**
- Modify: `_assets/controllers/arqueo.php` — constructor (~77-88), helper nuevo tras `user_name()` (~105), `cajas()` (~158-169), `caja()` (~313-331), `guardar_caja()` (~341-360), endpoint nuevo `asignar_caja()` después de `cajas()`.

**Interfaces:**
- Consumes (Task 1): `by_sesion_asignadas(int,int)`, `asignar(int,?int)`, `get_users_by_permission($id, false)`, `ACC_ASIGNAR_CAJA`, y lo existente (`auditLogModel->log(...)`, `user_id()`, `user_name()`, `guard()`, `json()`, `input()`).
- Produces (Task 3): variables de template — `cajas.html` recibe `sesion, cajas, es_admin, usuarios` (usuarios = [] para no-admin; filas con Id y Nombre); endpoint `POST /arqueo/asignar_caja` con JSON `{caja_id: int, user_id: int|null}` → `{success: bool, message?: string, asignado_nombre?: string|null}`.

- [ ] **Step 1: Instanciar `UsuariosModel` en el constructor**

Propiedad junto a las demás:

```php
    public ArqueoAuditLogModel $auditLogModel;
    public UsuariosModel $usuariosModel;
```

y en `__construct`:

```php
        $this->auditLogModel          = new ArqueoAuditLogModel();
        $this->usuariosModel          = new UsuariosModel();
```

- [ ] **Step 2: Helper `puede_capturar()`**

Después de `user_name()`:

```php
    /**
     * ¿El usuario actual puede entrar/guardar esta caja?
     * Admin: siempre. Capturista: solo si la caja está asignada a él.
     * Caja sin asignar: solo admin.
     */
    private function puede_capturar(array $caja): bool
    {
        if (authorized(self::PERM_ADMIN)) {
            return true;
        }
        $asignado = (int) ($caja['asignado_user_id'] ?? 0);
        return $asignado > 0 && $asignado === (int) $this->user_id();
    }
```

- [ ] **Step 3: `cajas()` — filtrar por asignación y cargar usuarios**

Reemplazar el cuerpo después del 404:

```php
        $es_admin = authorized(self::PERM_ADMIN);
        if ($es_admin) {
            $cajas    = $this->cajasModel->by_sesion((int) $sesion_id);
            $usuarios = $this->usuariosModel->get_users_by_permission(self::PERM_AUDITOR, false);
        } else {
            $cajas    = $this->cajasModel->by_sesion_asignadas((int) $sesion_id, (int) $this->user_id());
            $usuarios = [];
        }
        echo $this->twig->render($this->route . 'cajas.html', compact('sesion', 'cajas', 'es_admin', 'usuarios'));
```

- [ ] **Step 4: Candado en `caja()` y `guardar_caja()`**

En `caja()`, después del bloque 404 (`if (!$caja) {...}`):

```php
        if (!$this->puede_capturar($caja)) {
            http_response_code(403);
            echo 'No tienes asignada esta caja. Pide a un administrador que te la asigne.';
            return;
        }
```

En `guardar_caja()`, después de su bloque `if (!$caja) {...}`:

```php
        if (!$this->puede_capturar($caja)) {
            $this->json(['success' => false, 'message' => 'No tienes asignada esta caja.']);
        }
```

- [ ] **Step 5: Endpoint `asignar_caja()`**

Después de `cajas()`:

```php
    /**
     * Asigna (o desasigna) el usuario capturista de una caja. Solo admin.
     * POST JSON: caja_id, user_id (null o 0 = desasignar).
     */
    public function asignar_caja(): void
    {
        $this->guard([self::PERM_ADMIN]);
        header('Content-Type: application/json');

        $in      = $this->input();
        $caja_id = (int) ($in['caja_id'] ?? 0);
        $user_id = isset($in['user_id']) && (int) $in['user_id'] > 0 ? (int) $in['user_id'] : null;

        if ($caja_id <= 0) {
            $this->json(['success' => false, 'message' => 'Caja inválida.']);
        }
        $caja = $this->cajasModel->find($caja_id);
        if (!$caja) {
            $this->json(['success' => false, 'message' => 'Caja no encontrada.']);
        }
        $sesion = $this->sesionesModel->find((int) $caja['sesion_id']);
        if (!$sesion || $sesion['estado'] === 'cerrado') {
            $this->json(['success' => false, 'message' => 'La sesión está cerrada; no se puede reasignar.']);
        }

        $asignado_nombre = null;
        if ($user_id !== null) {
            $capturistas = $this->usuariosModel->get_users_by_permission(self::PERM_AUDITOR, false);
            foreach ($capturistas as $u) {
                if ((int) $u['Id'] === $user_id) {
                    $asignado_nombre = $u['Nombre'];
                    break;
                }
            }
            if ($asignado_nombre === null) {
                $this->json(['success' => false, 'message' => 'El usuario no tiene el permiso de captura de arqueos.']);
            }
        }

        // Nombre del asignado anterior (para auditoría)
        $anterior_id     = isset($caja['asignado_user_id']) ? (int) $caja['asignado_user_id'] : 0;
        $anterior_nombre = $anterior_id > 0 ? $this->sql_nombre_usuario($anterior_id) : null;

        $ok = $this->cajasModel->asignar($caja_id, $user_id);
        if ($ok) {
            $this->auditLogModel->log(
                ArqueoAuditLogModel::ACC_ASIGNAR_CAJA,
                (int) $caja['sesion_id'], $caja_id, (int) $caja['sucursal_id'],
                $anterior_id > 0 ? ['asignado_user_id' => $anterior_id, 'asignado_nombre' => $anterior_nombre] : null,
                $user_id !== null ? ['asignado_user_id' => $user_id, 'asignado_nombre' => $asignado_nombre] : null,
                $this->user_id(), $this->user_name()
            );
        }
        $this->json(['success' => (bool) $ok, 'asignado_nombre' => $asignado_nombre]);
    }

    /** Nombre de un usuario por Id (para auditoría de reasignaciones). */
    private function sql_nombre_usuario(int $user_id): ?string
    {
        $rows = $this->cajasModel->sql->select(
            "SELECT Nombre FROM [TG].[dbo].[Usuario] WHERE Id = ?;",
            [$user_id]
        );
        return $rows ? ($rows[0]['Nombre'] ?? null) : null;
    }
```

- [ ] **Step 6: Verificar**

- `php -l _assets/controllers/arqueo.php` → sin errores.
- Script scratchpad simulando el flujo a nivel modelo (sin HTTP): asignar la primera caja de la sesión 4 a un usuario con permiso 74, insertar el log ASIGNAR_CAJA con `log(...)` (misma estructura que el endpoint), leer `by_sesion(4)` y confirmar `asignado_nombre`; luego desasignar, borrar el log de prueba y confirmar BD limpia.

- [ ] **Step 7: Commit**

```bash
git add _assets/controllers/arqueo.php
git commit -m "feat(arqueo): candado por asignacion en cajas y endpoint asignar_caja"
```

---

### Task 3: Vistas — dropdown de asignación y auditoría

**Files:**
- Modify: `views/arqueo/cajas.html` (columna "Asignado a" + JS)
- Modify: `views/arqueo/auditoria.html` (etiqueta, badge y campos de ASIGNAR_CAJA)

**Interfaces:**
- Consumes (Task 2): template vars `es_admin` (bool), `usuarios` (filas con `Id`, `Nombre`), filas de `cajas` con `asignado_user_id`/`asignado_nombre`; endpoint `POST /arqueo/asignar_caja` JSON `{caja_id, user_id}` → `{success, message?, asignado_nombre?}`.

- [ ] **Step 1: Columna "Asignado a" en `views/arqueo/cajas.html`**

En `<thead>`, entre `<th>Cajero</th>` y `<th class="text-center">Estado</th>`:

```twig
          <th>Asignado a</th>
```

En el `<tbody>`, entre la celda de Cajero y la de Estado:

```twig
          <td>
            {% if es_admin and sesion.estado != 'cerrado' %}
            <select class="form-select form-select-sm select-asignar" data-caja-id="{{ c.id }}" style="min-width:170px;">
              <option value="0">— Sin asignar —</option>
              {% for u in usuarios %}
              <option value="{{ u.Id }}" {{ c.asignado_user_id == u.Id ? 'selected' : '' }}>{{ u.Nombre }}</option>
              {% endfor %}
            </select>
            {% else %}
            {{ c.asignado_nombre ?: '—' }}
            {% endif %}
          </td>
```

Aviso para capturista sin cajas (justo antes del `<div class="card">`):

```twig
{% if not es_admin and cajas is empty %}
<div class="alert alert-warning">
    <i class="fas fa-user-slash"></i> No tienes cajas asignadas en este arqueo.
    Pide a un administrador que te asigne.
</div>
{% endif %}
```

- [ ] **Step 2: JS de asignación (bloque `myjs` nuevo al final de cajas.html)**

```twig
{% block myjs %}
<script>
  $(document).on('change', '.select-asignar', function () {
    var sel = this;
    var anterior = sel.getAttribute('data-anterior') ?? String(sel.value);
    var userId = parseInt(sel.value, 10) || 0;
    sel.disabled = true;
    fetch('/arqueo/asignar_caja', {
      method: 'POST',
      headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
      body: JSON.stringify({ caja_id: parseInt(sel.getAttribute('data-caja-id'), 10), user_id: userId })
    })
      .then(r => r.json())
      .then(d => {
        sel.disabled = false;
        if (d.success) {
          sel.setAttribute('data-anterior', String(sel.value));
          sel.classList.add('is-valid');
          setTimeout(() => sel.classList.remove('is-valid'), 1200);
        } else {
          alert(d.message || 'No se pudo asignar.');
          sel.value = anterior;
        }
      })
      .catch(() => { sel.disabled = false; alert('Error de red al asignar.'); sel.value = anterior; });
  });

  // Guardar el valor inicial para poder revertir en caso de error
  document.querySelectorAll('.select-asignar').forEach(function (s) {
    s.setAttribute('data-anterior', String(s.value));
  });
</script>
{% endblock %}
```

(Nota: `cajas.html` hoy NO tiene bloque `myjs`; jQuery está disponible desde el layout.)

- [ ] **Step 3: Auditoría — etiqueta, badge y campos**

En `views/arqueo/auditoria.html`:

- Al hash `etiquetas` de Twig agregar: `'ASIGNAR_CAJA': 'Asignó caja'` (con coma en el elemento previo).
- Al CSS agregar: `.acc-ASIGNAR_CAJA { background:rgb(180 83 9 / 12%); color:#B45309; }`
- Al objeto JS `ETIQUETAS_CAMPO` agregar:

```js
    asignado_user_id: 'Usuario asignado (id)', asignado_nombre: 'Usuario asignado',
```

- [ ] **Step 4: Verificar**

- Lint Twig de ambas vistas: `php <scratchpad>/twig_lint.php views/arqueo/cajas.html` y `views/arqueo/auditoria.html` → `TWIG OK`.
- Revisión visual del JS (llaves/comas); no hay linter para JS inline.

- [ ] **Step 5: Commit**

```bash
git add views/arqueo/cajas.html views/arqueo/auditoria.html
git commit -m "feat(arqueo): dropdown de asignacion en cajas y accion ASIGNAR_CAJA en auditoria"
```

---

## Checklist de verificación manual final (navegador, la hace el usuario)

1. Como admin en `/arqueo/cajas/{id}` de un arqueo abierto: asignar un usuario a una caja con el dropdown → check verde; la auditoría muestra "Asignó caja" con antes/después.
2. Como ese capturista: entrar a `/arqueo/cajas/{id}` → solo ve su caja; el enlace Capturar funciona.
3. Como capturista, URL directa a otra caja (`/arqueo/caja/{otra}`) → 403 "No tienes asignada esta caja".
4. Caja sin asignar → capturista no la ve ni puede entrar; admin sí.
5. Desasignar (— Sin asignar —) → el capturista pierde acceso; queda registro en auditoría.
6. Sesión cerrada → el dropdown desaparece (texto plano) y el endpoint rechaza reasignar.
7. ⚠️ Recordar: en los arqueos ya abiertos hay que asignar las cajas, o los capturistas no podrán entrar (decisión de diseño).
