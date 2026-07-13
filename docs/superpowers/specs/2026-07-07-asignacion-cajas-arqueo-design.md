# Asignación de usuarios a cajas de arqueo

**Fecha:** 2026-07-07
**Estado:** aprobado por el usuario (diseño conversado en sesión)

## Problema

Cualquier usuario con permiso `arqueo_capturar` (74) puede entrar y modificar
cualquier caja de cualquier arqueo. El capturista además ve las 15 cajas y
tiene que buscar la suya.

## Objetivo

El admin asigna usuarios capturistas a cajas específicas de cada arqueo, desde
la vista de cajas. El capturista solo ve y puede modificar sus cajas asignadas.
Los admins (`arqueo_admin`, 73) ven y entran a todo. Decisiones del usuario:

- Un usuario puede tener **varias** cajas asignadas (Waterfill/Perez Serna
  tienen 2 cajas).
- Caja **sin asignar → solo administradores** pueden entrar/capturar (modo
  estricto). Consecuencia asumida: en arqueos ya abiertos, los capturistas no
  entran a nada hasta que el admin asigne.
- La asignación vive **dentro de la vista de cajas** (`/arqueo/cajas/{id}`),
  no en una pantalla aparte.

## Diseño

### 1. Columna nueva en `arqueo_cajas`

```sql
IF COL_LENGTH('[TG].[dbo].[arqueo_cajas]', 'asignado_user_id') IS NULL
    ALTER TABLE [TG].[dbo].[arqueo_cajas] ADD [asignado_user_id] INT NULL;
```

Sección 9 de `docs/sql/arqueo_schema.sql` (idempotente). NULL = sin asignar.
No se persiste el nombre: JOIN con `TG.dbo.Usuario` al listar.

### 2. Modelo `ArqueoCajasModel`

- `by_sesion(int $sesion_id)`: el SELECT cambia a
  `SELECT c.*, u.Nombre AS asignado_nombre FROM [TG].[dbo].[arqueo_cajas] c
   LEFT JOIN [TG].[dbo].[Usuario] u ON u.Id = c.asignado_user_id ...`
  (mismo ORDER BY). Todos los consumidores actuales siguen funcionando
  (columna extra inocua; `concentrado()` usa claves específicas).
- Método nuevo `asignar(int $caja_id, ?int $user_id): bool` — UPDATE de
  `asignado_user_id` (NULL para desasignar).
- Método nuevo `by_sesion_asignadas(int $sesion_id, int $user_id): array` —
  igual que `by_sesion` pero `WHERE c.sesion_id = ? AND c.asignado_user_id = ?`.

### 3. Usuarios asignables

`UsuariosModel::get_users_by_permission($permission_id, $require_email = true)`
gana el parámetro opcional: con `false` omite los filtros de Correo (mantiene
`Estatus = 1`). Llamadas existentes no cambian de comportamiento. El controlador
usa `get_users_by_permission(74, false)`; el id 74 se referencia vía la
constante existente `Arqueo::PERM_AUDITOR`.

### 4. Controlador `Arqueo`

- Constructor: instanciar `UsuariosModel` (footgun de transacciones: siempre
  en el constructor).
- Helper privado nuevo `puede_capturar(array $caja): bool`:
  `authorized(PERM_ADMIN) || ((int)($caja['asignado_user_id'] ?? 0) > 0 &&
  (int)$caja['asignado_user_id'] === (int)$this->user_id())`.
- `cajas($sesion_id)`:
  - admin → todas las cajas (`by_sesion`) + `usuarios` (lista permiso 74) para
    los dropdowns;
  - capturista → solo `by_sesion_asignadas($sesion_id, $user_id)`; `usuarios`
    no se envía.
  - pasa `es_admin` al template.
- `caja($caja_id)` y `guardar_caja($caja_id)`: después de cargar `$caja`,
  si `!$this->puede_capturar($caja)` → 403. En `caja()` (vista HTML): mensaje
  "No tienes asignada esta caja." vía `guard`-style exit; en `guardar_caja()`
  (AJAX): `json(['success'=>false,'message'=>'No tienes asignada esta caja.'])`.
  Excepción: con la sesión cerrada, `caja()` permite VER (solo lectura) al
  capturista asignado y a admins — misma regla, sin cambio extra.
- Endpoint nuevo `asignar_caja()` — POST JSON `{caja_id, user_id}` (`user_id`
  null/0 = desasignar). `guard([PERM_ADMIN])`. Validaciones: caja existe;
  sesión no cerrada; si `user_id` no es null, debe estar en la lista de
  usuarios con permiso 74 (validado con `get_users_by_permission(74, false)`).
  Tras el UPDATE exitoso registra auditoría (acción nueva `ASIGNAR_CAJA`):
  `datos_anteriores = {asignado_user_id, asignado_nombre}` previos,
  `datos_nuevos` = los nuevos (nombre tomado de la lista de usuarios; null al
  desasignar). Respuesta `{success: bool}`.

### 5. Auditoría

- `ArqueoAuditLogModel`: constante nueva `ACC_ASIGNAR_CAJA = 'ASIGNAR_CAJA'`.
- `views/arqueo/auditoria.html`: etiqueta `'ASIGNAR_CAJA': 'Asignó caja'` y
  badge `.acc-ASIGNAR_CAJA { background:rgb(180 83 9 / 12%); color:#B45309; }`;
  etiquetas de campo `asignado_user_id: 'Usuario asignado (id)'`,
  `asignado_nombre: 'Usuario asignado'`.

### 6. Vista `views/arqueo/cajas.html`

- Columna nueva "Asignado a" (entre Cajero y Estado):
  - admin y sesión no cerrada → `<select>` por caja con "— Sin asignar —" +
    usuarios (value = Id, texto = Nombre), seleccionado el actual;
    `onchange` hace `fetch('/arqueo/asignar_caja', ...)` y al éxito marca
    visualmente (sin recargar); al fallo `alert` y revierte el select.
  - admin y sesión cerrada → texto plano del nombre asignado (o "—").
  - capturista → texto plano (su propio nombre; solo ve sus cajas).
- Aviso para capturista sin cajas asignadas: alerta "No tienes cajas
  asignadas en este arqueo. Pide a un administrador que te asigne." cuando la
  lista queda vacía y no es admin.

## Fuera de alcance

- Notificar por correo al usuario asignado.
- Asignaciones por defecto/plantilla entre arqueos (se asigna en cada sesión).
- Restringir la vista del concentrado (sigue siendo solo admin como hoy).
- Cambiar la lista de sesiones (`/arqueo`) — el capturista sigue viendo todas
  las sesiones y al entrar a una ve solo sus cajas.

## Verificación

Sin framework de tests; `php -l`, lint Twig y scripts de un solo uso
(scratchpad, conexión de la app) + manual en navegador:

1. SQL: columna creada; re-ejecución del schema no falla.
2. Script BD: `asignar(caja, user)` + `by_sesion` trae `asignado_nombre`;
   `by_sesion_asignadas` filtra; desasignar deja NULL. Log ASIGNAR_CAJA
   insertado por el flujo del endpoint (simulado a nivel modelo). Limpiar al
   final (dejar asignaciones como estaban).
3. Manual (navegador): admin asigna desde el dropdown → capturista entra y ve
   solo su caja → intenta URL directa de otra caja → 403; caja sin asignar →
   capturista bloqueado, admin entra; asignación aparece en auditoría.
