# Portal de Estaciones — Vista "Mis Recepciones" (Fase 3, recorte inicial)

**Fecha:** 2026-08-09
**Estado:** Aprobado — listo para plan de implementación.

## Contexto

Este es el arranque de la **Fase 3** del [plan maestro de pago a proveedores](2026-07-25-pago-proveedores-plan-maestro.md) ("Portal de estaciones"), pero recortado: las Fases 0 (guardar XML) y 1 (conciliación factura↔recepción) todavía no están implementadas, así que esta primera entrega **no incluye factura ni XML**. Solo cubre lo que ya es autosuficiente hoy: que la estación vea sus recepciones de combustible (aumentos) y suba el escaneo de su remisión directamente sobre cada una.

Cuando Fase 0/1 existan, se agrega la columna de factura/XML sobre esta misma vista sin rediseñarla.

## Objetivo

La estación entra a **Operaciones → Mis Recepciones**, elige un día, ve sus recepciones de combustible de ese día (todos los productos) y puede subir uno o más escaneos de remisión por recepción. Reemplaza el envío de escaneos por correo.

## Fuera de alcance (explícito)

- Factura asignada, estado de XML, estado "subida a ControlGas" — llegan con Fase 0/1.
- Conciliación (eso lo hace Abastos en Fase 1, no la estación).
- Notificaciones por correo/campana de disponibilidad de XML (no aplica todavía, no hay XML).

## Permisos nuevos

Se dan de alta en `tg_permissions` (vía `it/permissions`) y se asignan por usuario en `it/permission_users`:

| Permiso | Efecto |
|---|---|
| `ver_mis_recepciones` | Acceso a la vista. Sin este permiso, la entrada de sidebar no aparece y la ruta rechaza la petición. |
| `recepciones_todas_estaciones` | Habilita el `<select>` de estación en la vista. Sin él, la vista está forzada a la `IdEstacion` de la sesión del usuario, ignorando cualquier `codgas` que llegue del cliente. |
| `recepciones_eliminar_remision` | Habilita el botón de eliminar (soft-delete) sobre cualquier remisión ya subida. |

**Regla de acceso estricta (distinta al patrón viejo de `operations.php`):** si el usuario no tiene `recepciones_todas_estaciones` Y tampoco tiene `IdEstacion` en `$_SESSION['tg_user']` (caso: usuario corporativo sin ese permiso), se le niega el acceso a la vista — nunca se defaultea a "ver todas las estaciones" por ausencia de estación, como sí hace el tabulador de Operaciones hoy.

## Datos y modelo

### Identificación de una recepción

`MovimientosTan` (BD de estación, solo lectura) no tiene un ID propio único para "recepción" expuesto a `TG`. La llave natural que ya usa el resto del código (`MovimientosTanModel::sp_obtener_recepciones_combustible`) es la combinación **`nrotrn` + `codgas` + `fchtrn`**. Se usa esa misma combinación para ligar remisiones subidas a su recepción.

### Listado de recepciones

Se reutiliza `MovimientosTanModel::sp_obtener_recepciones_combustible($fchtrn, $codgas, $codprd)` (`_assets/models/MovimientosTanModel.php:4-43`). Hoy exige un `$codprd` exacto (filtra `T.codprd = @codprd` en el SQL, línea 36). Para esta vista se necesita "todos los productos" de un día — se ajusta el SQL de ese método para que `$codprd = 0` (o `null`) omita el filtro (`AND (@codprd = 0 OR T.codprd = @codprd)`), igual al patrón `@codgas = 0 OR ...` ya usado en `TabulatorModel::all()`. No se toca el resto del comportamiento del método ni sus otros llamadores (verificar quién más lo usa antes de tocar el SQL).

### Tabla nueva: `TG.dbo.recepcion_remisiones`

```sql
CREATE TABLE [TG].[dbo].[recepcion_remisiones] (
    id              INT IDENTITY(1,1) PRIMARY KEY,
    nrotrn          INT NOT NULL,
    codgas          INT NOT NULL,
    fchtrn          INT NOT NULL,       -- mismo serial de Excel que usa ControlGas (fchtrn)
    file_path       VARCHAR(500) NOT NULL,
    file_extension  VARCHAR(10) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    file_size       INT NOT NULL,
    created_by      INT NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT GETDATE(),
    is_deleted      BIT NOT NULL DEFAULT 0,
    deleted_at      DATETIME NULL,
    deleted_by      INT NULL
);
CREATE INDEX IX_recepcion_remisiones_recepcion ON [TG].[dbo].[recepcion_remisiones] (codgas, fchtrn, nrotrn) WHERE is_deleted = 0;
```

Soft-delete: al eliminar, solo se actualizan `is_deleted=1, deleted_at, deleted_by`. **El archivo físico nunca se borra del disco**, incluso tras el soft-delete (decisión explícita del usuario — conservar evidencia).

### Modelo nuevo: `RecepcionRemisionesModel.php`

Calca el patrón de `PaymentTransactionDocumentsModel::upload()` (`_assets/models/PaymentTransactionDocumentsModel.php:78-119`):

- `const UPLOAD_BASE = '_assets/uploads/recepcion_remisiones/';`
- `const MAX_SIZE = 10 * 1024 * 1024;` (10 MB, igual que el patrón existente)
- `const ALLOWED_EXT = ['pdf', 'jpg', 'jpeg', 'png'];`
- `upload(int $nrotrn, int $codgas, int $fchtrn, array $file, int $user_id): array` — inserta fila (`file_path` vacío) → crea carpeta `YYYY/MM/` → mueve archivo a `{id}.{ext}` → actualiza `file_path`. Mismo orden que el patrón existente (insert primero para obtener el ID, luego mover el archivo).
- `get_by_recepcion(int $nrotrn, int $codgas, int $fchtrn): array` — remisiones activas (`is_deleted = 0`) de una recepción, para mostrarlas en la vista.
- `get_counts_by_day(int $codgas, int $fchtrn): array` — mapa `nrotrn → count` de remisiones activas, para pintar el badge en la tabla sin una query por fila.
- `soft_delete(int $id, int $user_id): array` — valida que el registro exista y no esté ya borrado, actualiza los 3 campos.

## Controlador nuevo: `_assets/controllers/station_portal.php`

| Método | Ruta | Qué hace |
|---|---|---|
| `mis_recepciones()` | `/station_portal/mis_recepciones` | Verifica `authorized('ver_mis_recepciones')`; si no tiene `recepciones_todas_estaciones` y no hay `IdEstacion` en sesión, corta el acceso. Renderiza la vista con lista de estaciones (solo si aplica el select) y productos. |
| `datatables_recepciones()` | `/station_portal/datatables_recepciones` (AJAX) | Recibe `fecha` y opcionalmente `codgas` (solo se respeta `codgas` del cliente si el usuario tiene el permiso de todas las estaciones; si no, se ignora y se usa el de sesión). Llama `sp_obtener_recepciones_combustible($fecha, $codgas, 0)` + `get_counts_by_day()`, arma la tabla. |
| `upload_remision()` | `/station_portal/upload_remision` (POST) | Recibe `nrotrn`, `codgas`, `fchtrn` + archivo. Valida que el `codgas` recibido coincida con el permitido (mismo criterio de permiso que arriba, para que no se pueda subir una remisión a nombre de otra estación vía POST manipulado). Llama `RecepcionRemisionesModel::upload()`. |
| `remisiones_by_recepcion()` | `/station_portal/remisiones_by_recepcion` (AJAX) | Devuelve las remisiones activas de una recepción (para el modal de "ver remisiones"). |
| `delete_remision()` | `/station_portal/delete_remision` (POST) | Requiere `authorized('recepciones_eliminar_remision')`. Llama `soft_delete()`. |

Rutas nuevas agregadas a `index.php` siguiendo el patrón existente de dispatch por controlador.

## Vista

`views/station_portal/mis_recepciones.html`:

- Selector de fecha (default: hoy, mismo datepicker que ya usan otras vistas de Operaciones).
- Selector de estación — **solo se renderiza** si `authorized('recepciones_todas_estaciones')`; si no tiene el permiso, se muestra el nombre de la estación de sesión como texto fijo (no editable).
- Tabla (DataTable, patrón `data-codgas="{{ tg_user['IdEstacion'] }}"` ya usado en el módulo de Operaciones): columnas Hora, Producto, Volumen Recibido, Remisión (badge "N subidas" o "Sin remisión" + botones Subir / Ver / Eliminar por fila, este último solo visible si `authorized('recepciones_eliminar_remision')`).
- Modal de subida y modal de "ver remisiones" (lista con link de descarga + botón eliminar): reutilizan el patrón ya documentado del proyecto — vista parcial Twig sin layout propio, el controlador hace `echo` del render, el JS la inyecta con `.html(content)` (ver memoria `patron-modales-vista-parcial`).

## Sidebar

En `views/layouts/sidebar.html`, dentro del bloque de la sección Operaciones (línea 353, `{% if authorized(19) %}`), se agrega:

```html
{% if authorized('ver_mis_recepciones') %}
<li class="sidebar-item">
  <a class="sidebar-link" href="/station_portal/mis_recepciones">
    <i data-feather="truck"></i>
    <span class="align-middle">Mis Recepciones</span>
  </a>
</li>
{% endif %}
```

(El id real del permiso será numérico una vez dado de alta en `tg_permissions`; aquí se usa el nombre por claridad — el plan de implementación resuelve el id concreto.)

## Errores y validaciones

- Archivo fuera de tamaño/extensión: mismo mensaje que ya usa `PaymentTransactionDocumentsModel` (reusar textos).
- `codgas` manipulado sin permiso de todas las estaciones: la petición se sirve igual pero ignorando el valor recibido, nunca un error visible — evita filtrar por respuesta de error qué estaciones existen.
- Recepción sin `nrotrn`/`codgas`/`fchtrn` válidos en el POST de subida: rechazo genérico, no debería ocurrir desde la UI normal.

## Testing

No hay framework de tests en el proyecto (confirmado en `CLAUDE.md`). Verificación manual: subir remisión como usuario de estación normal, confirmar que no ve el selector de otras estaciones; probar con un usuario con `recepciones_todas_estaciones` que sí lo ve y puede cambiar; soft-delete y confirmar que el archivo sigue en disco y la fila desaparece de la lista pero no de la BD.
