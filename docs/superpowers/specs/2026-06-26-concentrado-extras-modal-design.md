# Concentrado: igualar al Excel + captura manual por sucursal (modal)

## Contexto

`/arqueo/concentrado/{sesion_id}` (`views/arqueo/concentrado.html` + `Arqueo::concentrado()`) muestra un consolidado por sucursal con solo 5 columnas: Sucursal, Total en Sistema M.N., Conteo Físico (sin vales), Vales Autorizados, Faltantes/Sobrantes. Esto es una versión simplificada del formato real que se usa en `NUEVO 17 JUN.xlsx` (hoja "Concentrado"), el cual tiene 13 sucursales × 15 columnas (A-O) y un bloque resumen inferior comparando contra los indicadores Dollar2Go.

El Excel real distingue claramente:
- **Columnas calculadas** (D, E, F, G, H, L, N, O): se derivan de fórmulas que referencian las hojas de detalle por caja (`'1. Waterfill Caja 1'!I77`, etc.) — estas ya tienen equivalente en nuestras columnas `arqueo_cajas.total_en_sistema`, `total_fisico_mxn`, `gran_total_vales_mxn`.
- **Columnas manuales** (C, I, J, K, M): se capturan a mano directamente en el Excel, sin fórmula. Hoy no existen en nuestro sistema en absoluto.

Análisis celda por celda de la hoja "Concentrado" (filas 7-44) y de `'1. Waterfill Caja 1'` (para confirmar el origen de D/E/G):

| Col | Encabezado Excel | Color header | Origen | Fórmula/origen real |
|---|---|---|---|---|
| A | # | rojo `#83160E`, texto blanco | fijo | índice de fila |
| B | Sucursal | rojo | fijo | nombre de sucursal |
| C | Capital de Trabajo Junio 2026 | rojo | **manual** | valor tecleado directo en Excel |
| D | Total en Sistemas M.N. considerando costo promedio | rojo | calculado | suma de `!I77` (TOTAL EN SISTEMA) de cada caja de la sucursal |
| E | Total Conteo en Físico sin Vales considerando costo promedio | azul `#1C4587`, texto blanco | calculado | suma de `!H25` (físico MXN convertido) de cada caja, menos vales ya contados en 2 sucursales |
| F | Faltantes/Sobrantes sin vales | azul | calculado | `=E-D` |
| G | Vales Autorizados | azul | calculado | suma de vales MXN de cada caja (`!I65` normalmente) |
| H | Faltante Real de Arqueo | azul | calculado | `=E-D+G` |
| I | Gastos en trámite o por cancelar | rojo | **manual** | valor tecleado directo |
| J | Adeudo O. Quiroz | rojo | **manual** | valor tecleado directo |
| K | Reinversión | rojo | **manual** | valor tecleado directo |
| L | Conteo Físico, Vales y Gastos | rojo | calculado | `=E+I+J+G` |
| M | Utilidad al 15 de Junio 2026 | rojo | **manual** | valor tecleado directo |
| N | Capital, Utilidad y Reinversión Reportada por D2GO | rojo | calculado | `=C+K+M` |
| O | Variación del Arqueo vs Indicadores D2GO | azul | calculado | `=L-N` |

Bloque resumen inferior (filas 29-44, fondo cian `#B0F5F5` en encabezados de sección, amarillo `#FFFF00` en los 3 totales):
- "Capital de Trabajo" = `SUM(C)`, "Utilidad" = `SUM(M)`, "Reinversión" = `SUM(K)` → Total #1 (amarillo)
- "Conteo Físico" = `SUM(E)`, "Vales" = `SUM(G)`, "Gastos" = `SUM(I)`, "Adeudo" = `SUM(J)` → Total #2 (amarillo)
- "Faltante" = Total #2 − Total #1 (amarillo) — debe coincidir con `SUM(O)`

**Problema:** nuestro `concentrado()` actual no captura ni persiste las 5 columnas manuales (C, I, J, K, M), no calcula H/L/N/O, y su fórmula de "Faltantes/Sobrantes" (`sistema - fisico - vales`) no coincide con ninguna fórmula real del Excel (ni F ni H). El usuario pidió igualar colores, columnas y origen de datos, y agregar un modal por sucursal para capturar Gastos en trámite, Adeudo, Reinversión y Utilidad (estos 4 son por arqueo/sesión). Capital de Trabajo también será por sesión en adelante, pero para las 2 sesiones que ya existen se sembrará con los valores actuales del Excel.

## Decisión de diseño

**Persistencia:** nueva tabla `arqueo_concentrado_extras`, una fila por combinación sesión+sucursal, con los 5 campos manuales (`capital_trabajo`, `gastos_tramite`, `adeudo`, `reinversion`, `utilidad`). Restricción `UNIQUE(sesion_id, sucursal_id)` para poder hacer upsert simple desde el modal. Se descartó una tabla genérica clave-valor (`campo`/`valor`) por ser más compleja de consultar sin necesidad real de flexibilidad futura (son exactamente 5 campos conocidos, ya definidos por el Excel real del negocio).

**Cálculo (`Arqueo::concentrado()`):** se reescribe para LEFT JOIN contra `arqueo_concentrado_extras` (fila ausente ⇒ los 5 manuales en 0) y calcular D-O exactamente con las fórmulas de la tabla de arriba. La columna `faltantes_sobrantes` actual (que no correspondía a ninguna fórmula real) se reemplaza por F y H como columnas separadas, igual que el Excel — se descartó quedarnos solo con H por pedido explícito del usuario de igualar el Excel.

**Modal de captura:** cada fila de la tabla del Concentrado tiene un botón que abre un modal Bootstrap con 5 inputs numéricos (Capital de Trabajo, Gastos en trámite, Adeudo, Reinversión, Utilidad), precargados con los valores actuales de esa sucursal+sesión. Al guardar, hace POST AJAX a un endpoint nuevo y recarga la página (más simple que recalcular las 8 columnas derivadas en JS, y consistente con que el resto del módulo de arqueo no usa actualización parcial de tabla vía JS). Protegido por el mismo permiso que ya protege toda la vista de Concentrado (`PERM_ADMIN`, id 73) — no se crea un permiso nuevo, ya que el Concentrado completo ya es una vista exclusiva de administrador.

**Seed de Capital de Trabajo:** script SQL one-off (`docs/sql/seed_concentrado_extras.sql`), no una migración automática. Inserta, para cada una de las sesiones existentes en `arqueo_sesiones` y cada sucursal de `SUCURSALES`, una fila con el `capital_trabajo` del Excel y los otros 4 campos en 0. Usa subconsultas (`SELECT id FROM arqueo_sesiones`) en vez de IDs hardcodeados, para no depender de que el operador conozca los IDs reales de antemano. Se ejecuta manualmente vía `sqlcmd`, igual que el resto de cambios de esquema de este proyecto.

## Comportamiento esperado

### Tabla nueva: `arqueo_concentrado_extras`

```sql
CREATE TABLE [TG].[dbo].[arqueo_concentrado_extras] (
    [id]              INT IDENTITY(1,1) NOT NULL,
    [sesion_id]       INT               NOT NULL,
    [sucursal_id]     INT               NOT NULL,
    [capital_trabajo] DECIMAL(14,2)     NOT NULL CONSTRAINT [DF_ace_capital] DEFAULT (0),
    [gastos_tramite]  DECIMAL(14,2)     NOT NULL CONSTRAINT [DF_ace_gastos]  DEFAULT (0),
    [adeudo]          DECIMAL(14,2)     NOT NULL CONSTRAINT [DF_ace_adeudo] DEFAULT (0),
    [reinversion]     DECIMAL(14,2)     NOT NULL CONSTRAINT [DF_ace_reinv]  DEFAULT (0),
    [utilidad]        DECIMAL(14,2)     NOT NULL CONSTRAINT [DF_ace_util]   DEFAULT (0),
    [updated_by]      INT               NULL,
    [updated_at]      DATETIME          NOT NULL CONSTRAINT [DF_ace_updated] DEFAULT (GETDATE()),
    CONSTRAINT [PK_arqueo_concentrado_extras] PRIMARY KEY CLUSTERED ([id]),
    CONSTRAINT [FK_ace_sesion] FOREIGN KEY ([sesion_id])
        REFERENCES [TG].[dbo].[arqueo_sesiones] ([id]) ON DELETE CASCADE,
    CONSTRAINT [UQ_arqueo_concentrado_extras] UNIQUE ([sesion_id], [sucursal_id])
);
```

Se agrega a `docs/sql/arqueo_schema.sql` (mismo archivo, mismo patrón `IF OBJECT_ID(...) IS NULL`). Se aprovecha para corregir las columnas `tipo_cambio_venta`/`tipo_cambio_compra` en la definición de `arqueo_cajas` dentro de ese archivo, ya que están obsoletas desde un cambio de esquema anterior (renombrado a `costo_promedio`, columna compra eliminada) que nunca se reflejó en este archivo estático.

### Modelo nuevo: `ArqueoConcentradoExtrasModel`

`_assets/models/ArqueoConcentradoExtrasModel.php`, extiende `Model`:

```php
/** Todas las filas de extras de una sesión, indexadas por sucursal_id. */
public function by_sesion(int $sesion_id): array
{
    $rows = $this->sql->select(
        "SELECT * FROM [TG].[dbo].[arqueo_concentrado_extras] WHERE sesion_id = ?;",
        [$sesion_id]
    ) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $out[(int) $r['sucursal_id']] = $r;
    }
    return $out;
}

/** Upsert de los 5 campos manuales para sesion_id+sucursal_id. */
public function upsert(int $sesion_id, int $sucursal_id, array $d, ?int $usuario_id): bool
{
    $update = $this->sql->update(
        "UPDATE [TG].[dbo].[arqueo_concentrado_extras] SET
            capital_trabajo = ?, gastos_tramite = ?, adeudo = ?,
            reinversion = ?, utilidad = ?, updated_by = ?, updated_at = GETDATE()
         WHERE sesion_id = ? AND sucursal_id = ?;",
        [
            $d['capital_trabajo'], $d['gastos_tramite'], $d['adeudo'],
            $d['reinversion'], $d['utilidad'], $usuario_id,
            $sesion_id, $sucursal_id,
        ]
    );
    if ($update) {
        return true;
    }
    return (bool) $this->sql->insert(
        "INSERT INTO [TG].[dbo].[arqueo_concentrado_extras]
            (sesion_id, sucursal_id, capital_trabajo, gastos_tramite, adeudo, reinversion, utilidad, updated_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?);",
        [
            $sesion_id, $sucursal_id, $d['capital_trabajo'], $d['gastos_tramite'],
            $d['adeudo'], $d['reinversion'], $d['utilidad'], $usuario_id,
        ]
    );
}
```

`update()` en `MySqlPdoHandler` debe devolver la cantidad de filas afectadas (o algo truthy/falsy según filas afectadas) para que el patrón "UPDATE; si 0 filas, INSERT" funcione — se confirma este comportamiento al implementar revisando `MySqlPdoHandler::update()`; si en cambio siempre devuelve `true` con 0 filas afectadas, el upsert se reescribe usando `IF EXISTS (...) UPDATE ... ELSE INSERT ...` en un solo statement T-SQL en vez de dos llamadas PHP.

### Controlador: `Arqueo::concentrado()` reescrito

```php
public function concentrado($sesion_id): void
{
    $this->guard([self::PERM_ADMIN]);

    $sesion = $this->sesionesModel->find((int) $sesion_id);
    if (!$sesion) {
        (new Errors())->get404();
        return;
    }
    $cajas  = $this->cajasModel->by_sesion((int) $sesion_id);
    $extras = $this->concentradoExtrasModel->by_sesion((int) $sesion_id);

    $grupos = [];
    foreach ($cajas as $c) {
        $sid = (int) $c['sucursal_id'];
        if (!isset($grupos[$sid])) {
            $grupos[$sid] = [
                'sucursal_id' => $sid,
                'sucursal'    => $c['sucursal_nombre'],
                'D'           => 0, // total en sistema M.N.
                'E'           => 0, // conteo físico sin vales
                'G'           => 0, // vales autorizados
            ];
        }
        $grupos[$sid]['D'] += (float) $c['total_en_sistema'];
        $grupos[$sid]['E'] += (float) $c['total_fisico_mxn'];
        $grupos[$sid]['G'] += (float) $c['gran_total_vales_mxn'];
    }

    foreach ($grupos as $sid => &$g) {
        $ex = $extras[$sid] ?? null;
        $g['capital_trabajo'] = (float) ($ex['capital_trabajo'] ?? 0);
        $g['gastos_tramite']  = (float) ($ex['gastos_tramite']  ?? 0);
        $g['adeudo']          = (float) ($ex['adeudo']          ?? 0);
        $g['reinversion']     = (float) ($ex['reinversion']     ?? 0);
        $g['utilidad']        = (float) ($ex['utilidad']        ?? 0);

        $g['F'] = $g['E'] - $g['D'];
        $g['H'] = $g['E'] - $g['D'] + $g['G'];
        $g['L'] = $g['E'] + $g['gastos_tramite'] + $g['adeudo'] + $g['G'];
        $g['N'] = $g['capital_trabajo'] + $g['reinversion'] + $g['utilidad'];
        $g['O'] = $g['L'] - $g['N'];
    }
    unset($g);

    $concentrado = array_values($grupos);
    echo $this->twig->render($this->route . 'concentrado.html', compact('sesion', 'concentrado'));
}
```

Letras de columna (`D`, `E`, `F`, `G`, `H`, `L`, `N`, `O`) usadas como claves de array para que la correspondencia con el Excel sea evidente en el código; la vista las lee con esos mismos nombres.

### Endpoint nuevo: `Arqueo::guardar_concentrado_extra()`

```php
public function guardar_concentrado_extra(): void
{
    $this->guard([self::PERM_ADMIN]);
    header('Content-Type: application/json');

    $in          = $this->input();
    $sesion_id   = (int) ($in['sesion_id']   ?? 0);
    $sucursal_id = (int) ($in['sucursal_id'] ?? 0);
    if ($sesion_id <= 0 || $sucursal_id <= 0) {
        $this->json(['success' => false, 'message' => 'Sesión y sucursal son obligatorias.']);
    }

    $datos = [
        'capital_trabajo' => (float) ($in['capital_trabajo'] ?? 0),
        'gastos_tramite'  => (float) ($in['gastos_tramite']  ?? 0),
        'adeudo'          => (float) ($in['adeudo']          ?? 0),
        'reinversion'     => (float) ($in['reinversion']     ?? 0),
        'utilidad'        => (float) ($in['utilidad']        ?? 0),
    ];

    $ok = $this->concentradoExtrasModel->upsert($sesion_id, $sucursal_id, $datos, $this->user_id());
    $this->json(['success' => $ok]);
}
```

Ruta: `/arqueo/guardar_concentrado_extra` (POST, JSON body — sigue el mismo patrón que `guardar_caja()`). Se instancia `concentradoExtrasModel` en el constructor de `Arqueo` junto a los demás modelos.

### Vista: `concentrado.html`

Tabla con 13 columnas (B-O, sin contar el índice "#" que ya provee DataTables) más una columna final "Editar":

```
Sucursal | Capital Trabajo | Total Sistema | Conteo Físico s/Vales | Falt/Sobr s/Vales |
Vales Aut. | Faltante Real | Gastos Trámite | Adeudo | Reinversión |
Conteo+Vales+Gastos | Utilidad | Capital+Util+Reinv | Variación vs D2GO | Editar
```

Encabezados con `background:#83160E;color:#fff` (C, D, I, J, K, L, M, N) y `background:#1C4587;color:#fff` (E, F, G, H, O), igualando los colores reales del Excel. Cada celda numérica mantiene la clase `.num` ya existente; las columnas F, H y O usan `.falt-negativo`/`.falt-positivo` según signo, igual que hoy.

Bajo la tabla principal, bloque resumen inferior (encabezados de sección `background:#B0F5F5`, totales `background:#FFFF00;font-weight:600`):

```
Capital de Trabajo:  {{ SUM(C) }}
Utilidad:             {{ SUM(M) }}
Reinversión:          {{ SUM(K) }}
Total:                {{ SUM(C)+SUM(K)+SUM(M) }}

Conteo Físico:        {{ SUM(E) }}
Vales:                {{ SUM(G) }}
Gastos:                {{ SUM(I) }}
Adeudo:                {{ SUM(J) }}
Total:                {{ SUM(E)+SUM(G)+SUM(I)+SUM(J) }}

Faltante:              {{ Total2 - Total1 }}
```

Estos totales se calculan en Twig acumulando dentro del mismo `{% for %}` que ya recorre `concentrado` (igual que hoy se acumula `t_sistema`, `t_fisico`, etc.), no requieren cambios en el controlador.

**Modal:** un único `<div class="modal" id="modal_concentrado_extra">` reutilizado por todas las filas (patrón estándar Bootstrap: el botón de cada fila llama a una función JS que llena los inputs y el `sesion_id`/`sucursal_id` ocultos, luego abre el modal). Botón en cada fila:

```html
<button type="button" class="btn btn-sm btn-outline-primary"
        onclick="abrirModalExtra({{ g.sucursal_id }}, '{{ g.sucursal|e('js') }}', {{ g.capital_trabajo }}, {{ g.gastos_tramite }}, {{ g.adeudo }}, {{ g.reinversion }}, {{ g.utilidad }})">
  <i class="fas fa-edit"></i>
</button>
```

JS (`{% block myjs %}`, inline — no se crea archivo nuevo, el volumen no lo justifica):

```javascript
function abrirModalExtra(sucursalId, sucursalNombre, capital, gastos, adeudo, reinversion, utilidad) {
  document.getElementById('mx_sucursal_id').value = sucursalId;
  document.getElementById('mx_sucursal_nombre').textContent = sucursalNombre;
  document.getElementById('mx_capital_trabajo').value = capital;
  document.getElementById('mx_gastos_tramite').value = gastos;
  document.getElementById('mx_adeudo').value = adeudo;
  document.getElementById('mx_reinversion').value = reinversion;
  document.getElementById('mx_utilidad').value = utilidad;
  $('#modal_concentrado_extra').modal('show');
}

function guardarModalExtra() {
  const payload = {
    sesion_id: {{ sesion.id }},
    sucursal_id: parseInt(document.getElementById('mx_sucursal_id').value, 10),
    capital_trabajo: parseFloat(document.getElementById('mx_capital_trabajo').value) || 0,
    gastos_tramite: parseFloat(document.getElementById('mx_gastos_tramite').value) || 0,
    adeudo: parseFloat(document.getElementById('mx_adeudo').value) || 0,
    reinversion: parseFloat(document.getElementById('mx_reinversion').value) || 0,
    utilidad: parseFloat(document.getElementById('mx_utilidad').value) || 0,
  };
  fetch('/arqueo/guardar_concentrado_extra', {
    method: 'POST',
    headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
    body: JSON.stringify(payload)
  })
    .then(r => r.json())
    .then(d => { if (d.success) location.reload(); else alert(d.message || 'No se pudo guardar.'); });
}
```

Se recarga la página completa al guardar (en vez de actualizar la fila/totales vía JS) porque guardar un extra cambia 8 columnas derivadas (F, H, L, N, O y los 3 totales del resumen inferior) — recalcularlas todas en el cliente duplicaría la lógica de negocio que ya vive en PHP, mientras que una recarga es instantánea dado que la página no tiene estado de formulario que perder (es de solo lectura salvo el modal).

### Seed: `docs/sql/seed_concentrado_extras.sql`

Script documentado, ejecución manual con `sqlcmd`, no automática:

```sql
/* Siembra Capital de Trabajo (col. C del Excel "NUEVO 17 JUN.xlsx", hoja Concentrado)
   para las sesiones de arqueo ya existentes. Gastos/Adeudo/Reinversión/Utilidad
   quedan en 0 (se capturan desde el modal del Concentrado en adelante). */
USE [TG];
GO

DECLARE @capital TABLE (sucursal_id INT, capital_trabajo DECIMAL(14,2));
INSERT INTO @capital (sucursal_id, capital_trabajo) VALUES
    (1,  3090824.74), -- Waterfill
    (2,  390000.00),  -- Misiones
    (3,  300000.00),  -- Municipio
    (4,  350000.00),  -- Puerto de Palos
    (5,  300000.00),  -- Permuta
    (6,  280000.00),  -- Anapra
    (7,  250000.00),  -- Gomez Morin
    (8,  250000.00),  -- Lopez Mateos
    (9,  660000.00),  -- Villa Ahumada
    (10, 200000.00),  -- Km30
    (11, 650000.00),  -- Curva
    (12, 300000.00),  -- Custodia
    (13, 550000.00);  -- Perez Serna

INSERT INTO [TG].[dbo].[arqueo_concentrado_extras] (sesion_id, sucursal_id, capital_trabajo)
SELECT s.id, c.sucursal_id, c.capital_trabajo
FROM [TG].[dbo].[arqueo_sesiones] s
CROSS JOIN @capital c
WHERE NOT EXISTS (
    SELECT 1 FROM [TG].[dbo].[arqueo_concentrado_extras] e
    WHERE e.sesion_id = s.id AND e.sucursal_id = c.sucursal_id
);
GO
```

El `CROSS JOIN` cubre automáticamente **todas** las sesiones existentes en `arqueo_sesiones` en el momento de ejecutarlo (actualmente 2), sin necesitar IDs hardcodeados. El `NOT EXISTS` lo hace seguro de re-ejecutar.

## No-Goals / Decisiones descartadas

- **No** se hace Capital de Trabajo editable por sesión todavía (aunque la tabla ya lo soporta por sesión+sucursal) — el modal incluye el campo igual que los otros 4, así que en sesiones *futuras* ya queda editable de la misma forma; solo las 2 sesiones actuales se siembran con el valor del Excel como punto de partida.
- **No** se crea un permiso nuevo para el modal — usa `PERM_ADMIN`, igual que toda la vista de Concentrado.
- **No** se recalculan F/H/L/N/O en el cliente — se recarga la página tras guardar.
- **No** se modifica `exportar()` (sigue como stub 501) — fuera de alcance de esta solicitud.
