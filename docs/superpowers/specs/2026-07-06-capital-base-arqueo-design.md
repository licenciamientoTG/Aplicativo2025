# Capital de Trabajo base por sucursal (módulo Arqueo D2GO)

**Fecha:** 2026-07-06
**Estado:** aprobado por el usuario (diseño conversado en sesión)

## Problema

Hoy el Capital de Trabajo del concentrado vive solo en `arqueo_concentrado_extras`
(una fila por `sesion_id + sucursal_id`). Las sesiones existentes recibieron sus
valores mediante un seed manual (`docs/sql/seed_concentrado_extras.sql`), pero al
crear una sesión nueva ninguna fila se genera: el concentrado arranca con capital 0
y hay que capturar las 13 sucursales a mano en el modal.

## Objetivo

Que exista una **base** (catálogo por sucursal) de Capital de Trabajo que se copie
automáticamente a cada arqueo nuevo. El valor copiado sigue siendo editable por
sesión (modal actual) sin afectar la base; opcionalmente, al guardar desde el
modal se puede actualizar también la base para arqueos futuros.

## Diseño

### 1. Tabla nueva `TG.dbo.arqueo_capital_base`

```
id              INT IDENTITY PK
sucursal_id     INT NOT NULL UNIQUE
capital_trabajo DECIMAL(14,2) NOT NULL DEFAULT 0
updated_by      INT NULL
updated_at      DATETIME NOT NULL DEFAULT GETDATE()
```

- Script idempotente agregado a `docs/sql/arqueo_schema.sql` (mismo patrón
  `IF OBJECT_ID(...) IS NULL` del resto del módulo).
- Seed inicial (mismo archivo o script aparte en `docs/sql/`), valores confirmados
  por el usuario — idénticos al seed histórico:

| sucursal_id | Sucursal | Capital base |
|---|---|---|
| 1 | Waterfill | 3,090,824.74 |
| 2 | Misiones | 390,000.00 |
| 3 | Municipio | 300,000.00 |
| 4 | Puerto de Palos | 350,000.00 |
| 5 | Permuta | 300,000.00 |
| 6 | Anapra | 280,000.00 |
| 7 | Gomez Morin | 250,000.00 |
| 8 | Lopez Mateos | 250,000.00 |
| 9 | Villa Ahumada | 660,000.00 |
| 10 | Km30 | 200,000.00 |
| 11 | Curva | 650,000.00 |
| 12 | Custodia | 300,000.00 |
| 13 | Perez Serna | 550,000.00 |

### 2. Modelo nuevo `_assets/models/ArqueoCapitalBaseModel.php`

Extiende `Model`, patrón de los demás modelos arqueo:

- `get_all(): array` — mapa `sucursal_id => capital_trabajo`.
- `upsert(int $sucursal_id, float $capital, ?int $user_id): bool` — UPDATE, si no
  afectó filas INSERT (mismo estilo que `ArqueoConcentradoExtrasModel::upsert`).

### 3. Copia de la base al crear sesión (`Arqueo::crear_sesion()`)

Dentro de la transacción existente, después de crear las cajas:

- Obtener la base con `get_all()`.
- Insertar en `arqueo_concentrado_extras` una fila por **sucursal única** de
  `self::SUCURSALES` (OJO: la constante tiene 15 entradas pero 13 sucursales —
  Waterfill y Perez Serna aparecen dos veces por sus 2 cajas; hay que deduplicar
  por `id`), con `capital_trabajo` = valor de la base (0 si la sucursal no está
  en la base) y `gastos_tramite`, `adeudo`, `reinversion`, `utilidad` en 0.
- Reutilizar `ArqueoConcentradoExtrasModel::upsert()` para no duplicar SQL.
- **Footgun conocido:** instanciar modelos dentro de una transacción la rompe
  (reconexión en `Model.php`/`MySqlPdoHandler`). `ArqueoCapitalBaseModel` debe
  instanciarse en el constructor del controlador, junto a los demás.

### 4. Checkbox en el modal del concentrado

`views/arqueo/concentrado.html`, modal `#modal_concentrado_extra`:

- Checkbox nuevo bajo el campo Capital de Trabajo:
  *"Actualizar también la base para arqueos futuros"* — **desmarcado por defecto**,
  y se resetea a desmarcado cada vez que se abre el modal (`abrirModalExtra`).
- `guardarModalExtra()` envía `actualizar_base: true|false` en el JSON.

### 5. Endpoint `Arqueo::guardar_concentrado_extra()`

- Lee el flag `actualizar_base` del body.
- Tras el upsert por sesión (comportamiento actual intacto), si el flag es true
  hace `capitalBaseModel->upsert($sucursal_id, $capital, $user_id)`.
- Solo el capital toca la base; los otros 4 campos jamás.

## Fuera de alcance

- Pantalla de catálogo para la base (se descartó; la edición es vía checkbox).
- Migrar/tocar sesiones existentes (ya tienen sus valores del seed histórico).
- Cambios al cálculo de columnas D–O del concentrado.

## Verificación

Sin framework de tests; verificación manual (el usuario gestiona su servidor):

1. Correr el SQL de tabla + seed en TG; confirmar 13 filas.
2. Crear una sesión nueva → el concentrado debe mostrar los capitales base sin
   capturar nada.
3. Editar el capital de una sucursal en esa sesión SIN checkbox → cambia solo la
   sesión; crear otra sesión → sigue saliendo el valor base original.
4. Editar con checkbox marcado → crear otra sesión → sale el valor nuevo.
5. Sintaxis: `php -l` de controlador y modelos; `node --check` de arqueo.js si
   se toca.
