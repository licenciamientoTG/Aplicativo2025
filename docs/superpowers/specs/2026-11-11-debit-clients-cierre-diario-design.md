# Foto diaria de clientes débito v2 — cierre del día de ayer

Fecha: 2026-11-11
Antecesor: `docs/superpowers/specs/2026-09-17-debit-clients-snapshot-design.md` (v1, tabla `TG.dbo.debit_clients_snapshot`).

## Intención

La pestaña "Estado de Cuenta Foto" de `/income/clients` guarda una foto diaria
de los ~4,800 clientes débito para no recalcular en vivo (30–48 s). El usuario
quiere que la foto guarde **más información para ir viendo la evolución de cada
cliente**, en particular:

- Distinguir el saldo inicial **calculado** (anticipos − consumos de toda la
  historia) del saldo inicial **sistema** (con cuánto arrancó el cliente el día
  en ControlGas = el saldo sistema de cierre de la foto anterior).
- Ver los dos saldos que maneja ControlGas: **global** (`Clientes.debsdo`) y
  **vehículos** (`SUM(ClientesVehiculos.debsdo)`), y si el cliente **maneja
  bolsa global** (`Clientes.debglo`).
- Ver los consumos del día por **dos caminos** — despachos y cortes (ValesR) —
  para detectar desfases, con su saldo final calculado por cada camino.

Por ahora es solo de consulta: el usuario no va a tomar acciones sobre los
clientes con desfase todavía. La foto de **crédito** se hará después en otra
tabla con el mismo patrón (fuera de alcance aquí).

## Qué cambia respecto a la v1

| v1 | v2 |
|---|---|
| Corre 06:00 y toma "hoy" (00:00–06:00, día a medias) | Corre 06:00 y toma **el día de ayer completo** |
| Un saldo inicial (calculado) | Saldo inicial sistema + calculado por despachos + calculado por cortes |
| Solo consumos por despachos | Consumos por despachos **y** por cortes, con desfase |
| Saldo sistema = lectura en vivo a la hora de la corrida | Saldo sistema **reconstruido al cierre de ayer** + la lectura cruda |
| Sin `debglo` | Guarda `debglo` |

Se conserva (decisión del usuario): **solo se guarda un renglón cuando algo
cambia** (vigencia `fecha_desde`/`fecha_hasta`) y una **carga inicial completa**.

## Tabla nueva `TG.dbo.debit_clients_cierre`

La v1 tiene semántica distinta (día a medias), así que no se mezcla: se crea
tabla nueva y `debit_clients_snapshot` queda como respaldo, sin tocarla
(*decisión mía*). El cron de la v1 deja de llenarla cuando entre la v2.

Columnas (montos `DECIMAL(18,2)` NOT NULL salvo donde se indica):

| Columna | Significado |
|---|---|
| `id` | INT IDENTITY PK |
| `codcli`, `cliente` | Código y nombre (`Clientes.cod`, `den`) |
| `debglo` | BIT/TINYINT: el cliente maneja bolsa global (`Clientes.debglo`, NULL→0) |
| `fecha_desde`, `fecha_hasta` | DATE. Vigencia; `fecha_hasta` NULL = vigente. UNIQUE (codcli, fecha_desde) |
| `saldo_ini_sistema` | **NULL permitido.** Saldo sistema de cierre de la foto vigente anterior del cliente; NULL en la carga inicial (ControlGas no guarda historia de `debsdo`) |
| `saldo_ini_calc_desp` | Anticipos − despachos de toda la historia **antes** del día |
| `saldo_ini_calc_cortes` | Anticipos − cortes (ValesR) de toda la historia **antes** del día |
| `anticipos_dia` | Facturas de anticipo del día |
| `consumos_desp_dia` | Despachos del día (por fecha del despacho) |
| `consumos_cortes_dia` | Vales de cortes débito del día (por fecha del corte) |
| `desfase_dia` | `consumos_desp_dia − consumos_cortes_dia` |
| `ajuste_retro_desp` | **NULL permitido.** `saldo_ini_calc_desp` del día − `saldo_fin_calc_desp` de la fila vigente anterior. ≠ 0 cuando entró un movimiento con fecha atrasada (anticipo capturado tarde, cancelación, despacho re-asignado). NULL si no hay fila previa |
| `ajuste_retro_cortes` | Igual, para el camino de cortes |
| `saldo_fin_calc_desp` | `saldo_ini_calc_desp + anticipos_dia − consumos_desp_dia` |
| `saldo_fin_calc_cortes` | `saldo_ini_calc_cortes + anticipos_dia − consumos_cortes_dia` |
| `saldo_fin_sistema` | Saldo global reconstruido al cierre del día (ver abajo) |
| `saldo_fin_vehiculos` | Suma de saldos de vehículos reconstruida al cierre del día |
| `lectura_debsdo`, `lectura_vehiculos` | Lecturas crudas de `debsdo` / suma vehículos al momento de la corrida (auditoría de la reconstrucción) |
| `consumos_post_cierre` | Despachos del cliente entre 00:00 y la hora de la corrida, usados para reconstruir |
| `lectura_at` | DATETIME de la lectura |
| `updated_at` | DATETIME DEFAULT GETDATE() |

Índice para la vigente: `(codcli, fecha_hasta)`, igual que la v1.

## Reglas de cálculo (día D = ayer)

Universo: `SG12.dbo.Clientes WHERE tipval = 4 AND codest <> -1` (igual que v1).

- **Anticipos**: mismas reglas que la v1 y que Edo. Cuenta Débito —
  `DocumentosC` ⋈ `Documentos` (nro, codgas, tip), `Documentos.codopr` = cliente,
  `mtoiva > 0`, `mto > 100`, `codprd NOT IN (1,2,3,-64,179,180,181,192,193)`,
  `ISNULL(flgcon,0) <> 141`, monto `(mtoori + mtoiva)/100.0`, fecha `DocumentosC.fch`.
- **Despachos**: `SG12.dbo.Despachos`, `codcli` = cliente, fecha `fchtrn`, monto `mto` (ya en pesos).
- **Cortes**: `SG12.dbo.ValesR`, `codcli` = cliente, `codval = 127` (débito),
  fecha `fch` (fecha del corte), monto `mto` (pesos). Verificado: PETRO PAC
  2026-11-10 despachos 7,268.62 = cortes 7,268.62.
- Fechas internas de ControlGas son seriales enteros (`dateToInt`).
- **Reconstrucción del cierre** (*decisión mía*): ControlGas solo da el saldo
  del momento. A la hora de la corrida (~06:00 de D+1):
  - `saldo_fin_sistema = lectura_debsdo + consumos_post_cierre`
    (despachos del cliente con `fchtrn = D+1`, hasta la lectura).
  - `saldo_fin_vehiculos = lectura_vehiculos + consumos_post_cierre`, con o
    sin bolsa global. **Verificado en vivo el 2026-11-11** con dos lecturas
    separadas 6 y 10 min: en 3 de 3 clientes (2 con `debglo = 0`, 1 con
    `debglo = 1`, ASEO MUNICIPAL) el despacho bajó la bolsa y la suma de
    vehículos exactamente por su monto. (La hipótesis original, que con bolsa
    global la tarjeta no se movía, no se cumplió.)
  - Se ignoran recargas de bolsa hechas entre 00:00 y la corrida (raras); por
    eso se guardan las lecturas crudas para poder auditar.
- **Saldo inicial sistema**: `saldo_fin_sistema` de la fila vigente anterior
  del cliente; NULL si no hay fila previa.
- **Fotos congeladas** (decisión del usuario): una foto ya guardada nunca se
  recalcula. Los saldos iniciales calculados se recalculan **con toda la
  historia en cada corrida** (como la v1), de modo que un movimiento con fecha
  atrasada aparece como `ajuste_retro_*` en el día en que se detecta. No usar
  el atajo "fila anterior + movimientos del día", porque escondería esas
  correcciones.

## Turnos y hora de corrida (verificado en SG12, 2026-11-11)

| Turno (`nrotur`) | Horario | `fchcor` |
|---|---|---|
| 11 | 00:00–06:00 | mismo día |
| 21 | 06:00–14:00 | mismo día |
| 31 | 14:00–22:00 | mismo día |
| 41 | 22:00–23:59 | mismo día |

El turno nocturno real (22:00–06:00) se parte a medianoche: 41 cuenta para el
día que termina y 11 para el siguiente. Los vales entran a ValesR al cerrar el
corte; el turno 41 del día D se cierra junto con el 11 a las **06:00 de D+1**.
Por eso la corrida va a las **08:00** (decisión del usuario), con 2 h de
margen. La reconstrucción del saldo sistema usa los despachos 00:00–08:00 de D+1.

## Guardado (vigencia)

Igual que la v1 (`ClientesModel::refresh_debit_snapshot`), sobre la tabla nueva:

- Sin fila vigente → INSERT.
- Todos los montos iguales (tolerancia 0.005; comparar todas las columnas de
  monto excepto lecturas crudas, `consumos_post_cierre` y `lectura_at`) → nada.
  `debglo` distinto también cuenta como cambio.
- Distinto → cerrar vigente (`fecha_hasta = D − 1`) + INSERT con `fecha_desde = D`.
- Si la vigente ya es de D (segunda corrida para el mismo día) → UPDATE in place.
- Todo en una transacción.

Consecuencia aceptada: un día con movimiento abre renglón y el siguiente día
"en calma" abre otro (los consumos del día vuelven a 0).

**Leer un día X que cae en medio de una vigencia** (fila con
`fecha_desde < X ≤ fecha_hasta`): los saldos finales de la fila valen para X,
pero los movimientos del día (`anticipos_dia`, `consumos_*_dia`, `desfase_dia`)
son **0** y los saldos iniciales de X = los saldos finales de la fila. Solo en
`X = fecha_desde` se muestran los movimientos e iniciales guardados. Esto se
resuelve en la consulta de lectura.

**Clientes que salen del universo** (*decisión mía*): si un cliente con fila
vigente ya no está en el universo (`codest = -1` o `tipval <> 4`), se cierra su
vigencia (`fecha_hasta = D − 1`) y no se le abre fila nueva. Si vuelve a
entrar, se le abre fila nueva normal (con `saldo_ini_sistema` NULL si no hay
cierre del día anterior).

**Bitácora de corridas** (*decisión mía*): tabla `TG.dbo.debit_clients_cierre_runs`
(id, fecha_dia DATE UNIQUE, inicio, fin, nuevos, actualizados, sin_cambio,
cerrados, ok BIT, mensaje). Va en el mismo script SQL de `docs/sql/`. Es la
fuente para saber qué días ya se procesaron (no `MAX(fecha_desde)`, que no
avanza en días sin cambios).

**Días que el cron no corrió** (*decisión mía*): la corrida procesa en orden
cada día pendiente desde el día siguiente al último `fecha_dia` con `ok = 1`
en la bitácora hasta D. Para los días intermedios no hay lectura de saldo sistema: se guardan
`saldo_fin_sistema`, `saldo_fin_vehiculos`, lecturas y `consumos_post_cierre`
como NULL (columnas NULL permitidas para este caso) y solo D lleva la
reconstrucción. Tope de días de recuperación: 31; si hay más, procesa solo D y
lo reporta.

## Carga inicial

Una corrida para D = ayer sin filas previas: todos los clientes entran con sus
saldos iniciales calculados de toda la historia y `saldo_ini_sistema` NULL.
Desde el día siguiente, `saldo_ini_sistema` sale del cierre anterior.

## Corrida diaria

- La tarea `sya_debit_snapshot_diario` en 192.168.0.3 (creada 2026-11-11,
  06:00, `cmd /c C:\php\php.exe cron\debit_snapshot_diario.php >> logs\debit_snapshot_diario.log 2>&1`,
  dir `C:\inetpub\wwwroot\TG_PHP`) se reutiliza: el script pasa a llamar al
  cálculo v2 con D = ayer, y su disparador se cambia a **08:00** al desplegar
  la v2 (antes de eso se deja a las 06:00 llenando la v1).
- El endpoint `/income/debit_snapshot_refresh` se queda **solo para CLI con
  token**, como hoy (*decisión mía*): una corrida a media tarde reconstruiría
  el cierre con muchos despachos posteriores. Siempre procesa D = ayer; no
  acepta fecha arbitraria.
- Rendimiento: la v1 tardó 7–100 s. Los totales históricos de ValesR (~5.4 M
  filas) agregan carga; el builder debe medir. Si pasa de ~5 min, optimizar la
  consulta (índices existentes `IX_ValesRCli (codcli, fch, codgas)`,
  `IX_DespachosCli (codcli, fchtrn)`, tablas temporales), **sin** cambiar al
  atajo de fila anterior (ver "Fotos congeladas").

## Pantalla

Pestaña "Estado de Cuenta Foto" de `/income/clients` (hoy la única visible ahí):

- Lee de la tabla nueva.
- Selector de fecha (default = ayer) y botón Consultar; muestra el estado de
  cada cliente en esa fecha con la regla de "día en medio de una vigencia".
- Columnas: Código, Cliente, Bolsa global, Saldo ini. sistema, Saldo ini.
  calc. (desp.), Ajuste retro. (desp.), Anticipos, Consumos despachos, Consumos cortes, Desfase,
  Saldo fin calc. (desp.), Saldo fin calc. (cortes), Saldo fin sistema, Saldo
  fin vehículos. Las de cortes del saldo inicial pueden ir ocultas por
  default (*decisión mía*).
- Filtros por columna, Excel, totales en el pie (patrón actual de la tabla).
- El modal de ayuda (`#modal_info_debit_snapshot`) se actualiza con de dónde
  sale cada columna.

## Fuera de alcance

- Foto de crédito (tabla propia después, mismo patrón).
- Acciones o alertas sobre clientes con desfase.
- Explicar la diferencia entre saldo calculado y saldo sistema (anticipos
  facturados vs recargas a la bolsa); la foto solo la deja ver.
- Borrar o migrar `debit_clients_snapshot` (v1).

## Restricciones del proyecto

- SQL Server SG12/TG en 192.168.0.6, `MySqlPdoHandler`; crear la tabla con un
  script en `docs/sql/` que el usuario ejecuta (escrituras de DDL las corre él).
- Archivos PHP del proyecto con BOM + CRLF (`_assets/controllers/income.php`):
  editar sin reescribir el archivo.
- Rutas: endpoints nuevos en `routes/income.php` con permiso 14.
- No levantar el servidor PHP; el usuario prueba en el navegador.
- Deploy manual: al terminar, listar archivos tocados.

## Lo que queda al builder

Nombres internos de métodos, la forma exacta de la consulta (CTEs, tablas
temporales), el tipo exacto de `debglo`, el diseño visual del selector de
fecha y si las columnas ocultas se muestran con un botón de columnas.
