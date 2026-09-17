# Estado de Cuenta Foto — snapshot diario de clientes débito

Fecha: 2026-09-17

## Contexto

`/income/clients` tiene el tab "Edo. Cuenta Débito" (`views/income/clients.html`,
`income.php::account_statement_table`, `ClientesModel::get_account_summary_debit`
/ `get_initial_balance_debit` / `get_account_statement_debit`). Cuando el
usuario elige "Todos los clientes (resumen)", la consulta agregada recalcula
Anticipos/Consumos del periodo para **todos** los clientes débito activos en
una sola pasada (CTEs sobre `DocumentosC`/`Documentos`/`Despachos` de SG12).

Se midió el tiempo real contra SG12 (benchmark ad-hoc, 2026-09-17):

- Clientes débito activos (`tipval=4 AND codest=0`): **4,011**.
- Query agregada actual, solo clientes con movimiento en el rango (8.5 meses): **27s**.
- Misma query, todos los activos aunque no tengan movimiento: **33s**.
- Query extendida con Saldo Inicial histórico completo + Saldo Final: **48s**.

Conclusión: correr la consulta individual (la que ya usa el tab al elegir un
cliente específico) 4,011 veces sería muchísimo más lento que la agregada.
Pero incluso la agregada (30-48s) es demasiado lenta para esperar un clic en
el navegador. Se decide **materializar** el resultado en una tabla de TG,
poblada por un proceso batch (no por el usuario en el momento del clic).

El tab actual **no se modifica** — se agrega un tab nuevo e independiente.

## Objetivo

Un nuevo tab "Estado de Cuenta Foto" en `/income/clients` que muestre, de
forma instantánea, la última foto guardada de Saldo Inicial / Anticipos del
día / Consumos del día / Saldo Final / Saldo Sistema para todos los clientes
débito activos. La foto se genera una vez al día por una tarea programada de
Windows; hoy se genera manualmente una sola vez como backfill inicial con
histórico completo.

## Tabla `TG.dbo.debit_clients_snapshot`

Esquema de vigencia (tipo SCD2): una fila representa el estado de un cliente
durante un rango de fechas. Se abre una fila nueva solo cuando cambia algún
valor; si nada cambió respecto al día anterior, no se inserta nada.

```sql
CREATE TABLE dbo.debit_clients_snapshot (
    id             INT IDENTITY(1,1) PRIMARY KEY,
    codcli         INT            NOT NULL,
    cliente        NVARCHAR(255)  NOT NULL,
    fecha_desde    DATE           NOT NULL,   -- día en que empezó a ser válida esta fila
    fecha_hasta    DATE           NULL,       -- NULL = vigente; se cierra al día anterior al cambio
    saldo_inicial  DECIMAL(18,2)  NOT NULL,   -- histórico completo (anticipos - consumos) ANTES de fecha_desde
    anticipos_dia  DECIMAL(18,2)  NOT NULL,   -- anticipos SOLO del día fecha_desde
    consumos_dia   DECIMAL(18,2)  NOT NULL,   -- consumos (despachos) SOLO del día fecha_desde
    saldo_final    DECIMAL(18,2)  NOT NULL,   -- saldo_inicial + anticipos_dia - consumos_dia
    saldo_sistema  DECIMAL(18,2)  NOT NULL,   -- Clientes.debsdo al momento de generar
    updated_at     DATETIME       NOT NULL DEFAULT GETDATE(),
    CONSTRAINT UQ_debit_snapshot_cliente_desde UNIQUE (codcli, fecha_desde)
);
CREATE INDEX IX_debit_snapshot_vigente ON dbo.debit_clients_snapshot (codcli, fecha_hasta);
```

Nota de diseño clave: el "periodo" de cada corrida es **un solo día** (el día
de la corrida), no un acumulado desde el 1-enero. Si se acumulara desde
enero, Anticipos/Consumos cambiarían casi cada día para casi todos los
clientes activos y se perdería el ahorro de la vigencia. Con periodo = 1 día,
en días sin movimiento esos campos son 0 y la fila vigente no cambia.

## Cálculo (`ClientesModel::refresh_debit_snapshot(string $fecha)`)

1. Query agregada de una sola pasada para la fecha dada (`$hoy = dateToInt($fecha)`):
   - `SaldoInicialHist` = anticipos − consumos con `fch < $hoy` / `fchtrn < $hoy` (histórico completo, mismos filtros que ya usa `get_initial_balance_debit`: `mtoiva > 0`, `codprd NOT IN (...)`, `mto > 100`, `flgcon <> 141`).
   - `AnticiposDia` / `ConsumosDia` = mismos filtros pero con `fch = $hoy` / `fchtrn = $hoy`.
   - `SaldoFinal = SaldoInicialHist + AnticiposDia - ConsumosDia`.
   - `SaldoSistema = Clientes.debsdo`.
   - Universo: `Clientes WHERE tipval = 4 AND codest <> -1` (todos los activos/suspendidos, no solo los que tuvieron movimiento — para que la foto sea completa).
2. Trae en un solo `SELECT` todas las filas **vigentes** actuales (`fecha_hasta IS NULL`) indexadas por `codcli` en PHP.
3. Para cada cliente calculado en el paso 1:
   - Sin fila vigente previa → `INSERT` con `fecha_desde = $fecha, fecha_hasta = NULL`.
   - Con fila vigente y los 5 valores numéricos iguales (redondeo a 2 decimales) → no hacer nada.
   - Con fila vigente y algún valor distinto → `UPDATE` de la vigente (`fecha_hasta = $fecha - 1 día`) + `INSERT` de la nueva vigente.
4. Todo dentro de `beginTransaction()/commit()`. Si algo falla, `rollBack()`.
5. Devuelve resumen: `{nuevos, actualizados, sin_cambio, duracion_seg}`.

Idempotencia: si el proceso se corre dos veces el mismo día (reintento manual
o el cron se disparó dos veces), la segunda corrida debe comportarse como
"sin cambio" para todos los clientes — la fila vigente ya tiene
`fecha_desde = $fecha` con los valores recién calculados, así que el paso 3
cae en el caso "valores iguales" y no intenta un INSERT duplicado contra el
`UNIQUE (codcli, fecha_desde)`.

Backfill inicial: se llama `refresh_debit_snapshot(fecha_de_hoy)` una sola vez
manualmente. Como no hay filas previas, todo cae en el primer caso (INSERT) —
el `saldo_inicial` de cada cliente ya integra su histórico completo, así que
es la "foto desde el inicio de los tiempos" pedida. A partir de ahí, cada
corrida diaria solo abre filas nuevas para quien tuvo cambio real.

## Endpoint y cron

- `POST /income/debit_snapshot_refresh` (`income.php`): autoriza por
  `cron_token` (patrón `Merma::isCron()`, usa la constante `CRON_SECRET` ya
  existente en `header.class.php`) **o** por sesión con el mismo permiso que
  ya protege `/income/clients` (permite correrlo a mano hoy sin crear un
  permiso nuevo). Llama a `refresh_debit_snapshot(date('Y-m-d'))` y regresa el
  resumen como JSON.
- `cron/debit_snapshot_diario.php`: script CLI, mismo patrón que
  `cron/merma_sync_diario.php` (autoload manual, `$_GET['cron_token'] =
  CRON_SECRET`, invoca el controlador). Programado en el Programador de
  Tareas de Windows a las 06:00 AM.
- `POST /income/debit_snapshot_table`: lectura simple, sin cálculo —
  `SELECT * FROM TG.dbo.debit_clients_snapshot WHERE fecha_hasta IS NULL
  ORDER BY cliente`. Protegido por el mismo permiso de sesión que
  `/income/clients` (sin `cron_token`, es de uso normal desde el navegador).

## UI — nuevo tab, sin tocar el existente

`views/income/clients.html`: nuevo `<li>` en el `nav nav-tabs` ya existente
(después de "Edo. Cuenta Débito"), apuntando a un `<div class="tab-pane"
id="edo_debit_foto">` nuevo. El tab `#edo_debit` actual (detalle + resumen
por rango de fechas) no se modifica en absoluto.

Contenido del tab nuevo:
- Card con un botón único **"Consultar Foto"** (sin selector de cliente ni
  de fechas — el backend siempre regresa el snapshot vigente completo).
- Texto con la fecha de la foto más reciente (derivado de `MAX(fecha_desde)`
  entre las filas devueltas).
- DataTable (mismo patrón visual que las demás tablas del tab: filtros por
  columna, export a Excel) con columnas: Código, Cliente, Saldo Inicial,
  Anticipos (día), Consumos (día), Saldo Final, Saldo Sistema, Fecha Foto.

`_assets/js/income.js`: función nueva `debit_snapshot_table()` — DataTable
con `ajax` POST a `/income/debit_snapshot_table`, sin parámetros de fecha o
cliente. Se conecta al botón nuevo por su propio `id`, sin tocar
`account_statement_table` ni ninguna de sus tablas/IDs existentes.

## Fuera de alcance

- No se guarda detalle de movimientos individuales (cada anticipo/despacho)
  en el snapshot — solo los agregados por cliente/día. El drill-down
  detallado sigue siendo exclusivo del tab "Edo. Cuenta Débito" existente.
- No hay purga/retención de histórico por ahora (se decide guardar todo,
  ~4,011 clientes con filas solo cuando cambian — volumen esperado bajo).
- No hay selector de fecha de foto en la UI por ahora (siempre la vigente
  más reciente); se puede agregar después si se necesita ver una fecha
  pasada, ya que el esquema de vigencia ya lo soporta a nivel de datos.
