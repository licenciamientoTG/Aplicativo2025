# Auditoría completa del módulo Arqueo D2GO

**Fecha:** 2026-07-07
**Estado:** aprobado por el usuario (diseño conversado en sesión)

## Problema

El módulo arqueo no registra quién captura cada caja ni qué cambia: `arqueo_cajas`
no tiene columna de usuario, cada guardado destruye el estado anterior
(denominaciones y vales se reemplazan con DELETE+INSERT), los extras del
concentrado solo conservan al último editor (`updated_by`) sin valores previos,
y abrir una sesión no queda registrado.

## Objetivo

Log de auditoría unificado por sesión: quién, cuándo, qué acción y qué cambió
(valores antes/después), consultable en una vista propia para administradores.
Replica el patrón existente de `TG.dbo.PaymentRequestAuditLog` /
`PaymentRequestAuditLogModel`.

## Diseño

### 1. Tabla `TG.dbo.arqueo_audit_log`

```
id                INT IDENTITY PK
sesion_id         INT NOT NULL            (index IX_aal_sesion)
caja_id           INT NULL                (acciones de caja)
sucursal_id       INT NULL                (acciones de caja/concentrado)
accion            VARCHAR(30) NOT NULL
usuario_id        INT NULL
usuario_nombre    NVARCHAR(120) NULL
datos_anteriores  NVARCHAR(MAX) NULL      (JSON)
datos_nuevos      NVARCHAR(MAX) NULL      (JSON)
fecha             DATETIME NOT NULL DEFAULT GETDATE()
```

- Sin FK / sin ON DELETE CASCADE: la auditoría sobrevive a cualquier borrado.
- Script idempotente agregado a `docs/sql/arqueo_schema.sql` (sección 8).

### 2. Modelo `_assets/models/ArqueoAuditLogModel.php`

Extiende `Model`. Constantes de acción:
`ACC_CREAR_SESION`, `ACC_ABRIR_SESION`, `ACC_CERRAR_SESION`,
`ACC_GUARDAR_CAJA`, `ACC_EDITAR_CONCENTRADO`, `ACC_EDITAR_CAPITAL_BASE`.

Métodos:
- `log(string $accion, int $sesion_id, ?int $caja_id, ?int $sucursal_id, ?array $antes, ?array $despues, ?int $usuario_id, ?string $usuario_nombre): bool`
  — INSERT parametrizado; `$antes`/`$despues` se serializan con
  `json_encode(..., JSON_UNESCAPED_UNICODE)`; null se guarda como NULL.
- `by_sesion(int $sesion_id): array` — todos los movimientos de la sesión,
  `ORDER BY fecha DESC, id DESC`.

Se instancia en el constructor del controlador `Arqueo` (footgun: `new XxxModel()`
dentro de una transacción rompe el singleton PDO).

### 3. Puntos de registro en `_assets/controllers/arqueo.php`

Helper nuevo `user_name(): ?string` → `$_SESSION['tg_user']['Nombre'] ?? $_SESSION['tg_user']['Usuario'] ?? null`
(mismo campo que usa la auditoría de pagos).

| Endpoint | Acción | datos_anteriores | datos_nuevos |
|---|---|---|---|
| `crear_sesion()` | CREAR_SESION | null | `{nombre, fecha}` |
| `abrir()` | ABRIR_SESION | `{estado: 'programado'}` | `{estado: 'abierto'}` |
| `cerrar()` | CERRAR_SESION | `{estado: 'abierto'}` | `{estado: 'cerrado'}` |
| `guardar_caja()` | GUARDAR_CAJA | snapshot completo previo | snapshot completo nuevo |
| `guardar_concentrado_extra()` | EDITAR_CONCENTRADO | fila de extras previa (5 campos) o null si no existía | los 5 campos nuevos |
| `guardar_concentrado_extra()` con `actualizar_base` | EDITAR_CAPITAL_BASE (registro adicional) | `{capital_trabajo: anterior}` o null | `{capital_trabajo: nuevo}` |

**Snapshot completo de caja** (estructura idéntica en antes y después):
```json
{
  "cajero_nombre": "...", "encargado_revision": "...",
  "go_exchange_dolares": 0, "go_exchange_mxn": 0, "costo_promedio": 0,
  "totales": { "total_fisico_dolares": 0, "total_fisico_mxn": 0,
               "total_en_sistema": 0, "gran_total_vales_mxn": 0,
               "resultado_final": 0 },
  "denominaciones": [ {"seccion":"cajon","moneda":"USD","tipo":"billete",
                       "denominacion":100,"cantidad":5,"total":500}, ... ],
  "vales": [ {"numero_vale":"...","fecha":"...","concepto":"...",
              "dolares":0,"mxn":0}, ... ]
}
```
- El "antes" se construye con lo ya cargado en el endpoint (`$caja` via `find()`)
  más `denominacionesModel->by_caja()` y `valesModel->by_caja()` **antes** del
  DELETE. Primera captura: `denominaciones`/`vales` vacíos y encabezado null.
- El "después" se construye con `$denom_rows`, `$vales_in` y `$totales` ya
  calculados (sin releer la BD).
- El INSERT del log va **dentro de la transacción** de `guardar_caja()`: si el
  log falla, el guardado se revierte (no hay cambio sin rastro). Igual en
  `crear_sesion()`. En `abrir()`/`cerrar()`/`guardar_concentrado_extra()` (sin
  transacción hoy) el log se inserta inmediatamente después del cambio exitoso.

### 4. Vista `/arqueo/auditoria/{sesion_id}`

- Método `auditoria($sesion_id)` en el controlador: `guard([PERM_ADMIN])`,
  404 si la sesión no existe, render de `views/arqueo/auditoria.html` con
  `sesion` y `logs`.
- Template: tabla cronológica descendente — Fecha (d/m/Y H:i), Usuario
  (nombre, con id como title), Acción (badge de color: verde ABRIR, azul
  CERRAR, gris CREAR, rojo corporativo GUARDAR_CAJA, azul corporativo
  EDITAR_*), Sucursal/Caja, y botón "Ver cambios".
- "Ver cambios" abre un modal cuyo contenido se calcula en JS comparando los
  dos JSON (embebidos como data-attributes escapados): muestra SOLO lo que
  cambió, como filas "Campo | Antes | Después". Para `GUARDAR_CAJA` el diff
  incluye: campos del encabezado, totales, cada denominación cuya cantidad
  cambió (etiqueta "Cajón · Billete $100 USD: 50 → 45") y vales
  agregados/quitados/modificados (por índice). Si no hay diferencias
  (re-guardado idéntico) muestra "Sin cambios en los valores".
- Estilo consistente con el rediseño de `/arqueo` (badges pill, tabla limpia).
- Acceso: botón icono `fa-history` en la columna Acciones de la lista de
  arqueos (solo admin), y botón "Auditoría" junto a Regresar en el concentrado.

### 5. Sin cambios en tablas existentes

No se agregan columnas a `arqueo_cajas` ni `arqueo_sesiones`: el log unificado
responde quién abrió, quién capturó y qué cambió. `created_by`/`closed_by`
existentes se mantienen como están.

## Fuera de alcance

- Log de accesos/lecturas de páginas.
- Auditoría retroactiva (los movimientos previos a la implantación no existen).
- Purga/retención del log.
- Migrar la vista de auditoría de pagos a este patrón.

## Verificación

Sin framework de tests; verificación por scripts de un solo uso (scratchpad,
conexión de la app) + manual en navegador:

1. SQL: tabla creada; re-ejecución del schema no falla.
2. Script: insertar log de prueba con el modelo, leer con `by_sesion`, borrar.
3. Endpoints (manual, navegador): capturar una caja → aparece GUARDAR_CAJA con
   snapshot; recapturar cambiando una denominación → el diff del modal muestra
   solo esa denominación; editar concentrado inline y con checkbox → aparecen
   EDITAR_CONCENTRADO y EDITAR_CAPITAL_BASE; abrir/cerrar → registros con
   usuario correcto.
4. `php -l` en controlador y modelos; lint Twig de las vistas tocadas.
