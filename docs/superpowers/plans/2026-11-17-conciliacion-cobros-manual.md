# Conciliación de cobros guardada + manual — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Guardar la conciliación de cobros por mes, permitir conciliar a mano (cobro ↔ movimientos ↔ facturas) con alertas y sin bloqueos, reintentar pendientes una vez al día en segundo plano y listar depósitos sin conciliar.

**Architecture:** Dos clases sin BD con pruebas (`ConciliacionCobrosGuardado`: cruce → grupos → filas; `ConciliacionAlertas`: alertas de un grupo) y un modelo TG (`ConciliacionCobrosGuardadoModel`) para las 3 tablas ya creadas. `income.php::conciliation_payments` pasa a "primera vez guarda / después lee". El modal es una vista parcial Twig vía fetch y su JS vive en un archivo propio.

**Tech Stack:** PHP 8 + PDO sqlsrv (`MySqlPdoHandler`), Twig, jQuery + DataTables + Bootstrap 5, alertify. Pruebas sin framework (`tests/support.php`: `check()`, `finish()`).

**Spec:** `docs/superpowers/specs/2026-11-17-conciliacion-cobros-manual-design.md`

## Global Constraints

- Tablas YA CREADAS en TG (no volver a crear): `dbo.conciliacion_cobros_mes`, `dbo.conciliacion_cobros_grupo`, `dbo.conciliacion_cobros_item` — DDL en `docs/sql/conciliacion_cobros_ligas_schema.sql`.
- Nunca escribir en SG12/ControlGas. No tocar `Conciliacion_V3_*`.
- No cambiar las reglas de `ConciliacionCobros::conciliar()`.
- Ninguna conciliación manual se rechaza por no cuadrar: siempre se guarda; las alertas solo informan.
- Permiso 14 en todas las rutas nuevas; rutas bajo `group('income/', …)` en `routes/income.php`, prefijo `conciliation/`.
- Usuario = `$_SESSION['tg_user']['Correo']`; auto = `'sistema'`.
- Mes = `'Y-m'` del POST (`conciliationRange()`); fechas ControlGas con `dateToInt()` sin +1.
- SG12 en compatibilidad 110: sin `STRING_SPLIT`; listas en línea saneadas con `intval` / `%.2f` (como `depositos()`).
- Estatus manual: `'Conciliado manual'`. Origen: `'auto'` | `'manual'`. Tipos de item: `'C'`, `'M'`, `'F'`.
- Estatus que se reintentan a diario: `Sin banco`, `Sin MOV`, `Diferencia`, `Cuadra por importe`, `Cuadra por importe (sin MOV)`.
- Escapar todo texto con `esc()`/`escHtml` en la vista; no levantar el servidor PHP (lo prueba el usuario en el navegador).
- Carga normal sin cobros nuevos: cero escrituras y sin consultar `depositos()`.

## Review Focus

1. **Dos usuarios abren un mes nuevo a la vez** → solo uno guarda; el otro lee lo guardado, sin grupos duplicados. Prueba en Task 3 (`reservarMes` dos veces → la segunda devuelve false).
2. **Un cobro de un grupo `auto` dado de baja por una conciliación manual** (por ejemplo, 9 de los 10 cobros de un MOV) → en la siguiente carga reaparece como nuevo y se cruza; no desaparece del tab. Prueba en Task 1 (`cobrosSinGrupo`).
3. **El movimiento de una "Diferencia" que también usa un "Cuadra"** → la manual que edita la Diferencia no da de baja el "Cuadra". Prueba en Task 1 (item M con `ocupa = 0`) y Task 3 (`gruposQueOcupan` ignora `ocupa = 0`).
4. **Cobro cancelado o con otro monto en ControlGas después de guardar** → la fila trae la alerta "cambió después de conciliar"; el estatus guardado no cambia. Prueba en Task 2.
5. **Cobro de otro mes dentro de una manual** → aparece en los dos meses y no se vuelve a cruzar en ninguno. Prueba en Task 1 (`filasDesdeGrupos` con un cobro que no está en `$cobros` del mes, pero sí en `$cobrosExtra`).

---

### Task 1: `ConciliacionCobrosGuardado` (sin BD)

**Files:**
- Create: `_assets/classes/ConciliacionCobrosGuardado.class.php`
- Test: `tests/ConciliacionCobrosGuardadoTest.php`

**Interfaces:**
- Consumes: filas de `ConciliacionCobros::conciliar()` (claves `codgas, nro, fecha, monto, mov, estatus, deposito_id, cancelado`) y depósitos de `ConciliacionVentaModel::depositos()`.
- Produces:
  - `const REINTENTAR = ['Sin banco','Sin MOV','Diferencia','Cuadra por importe','Cuadra por importe (sin MOV)'];`
  - `static gruposDesdeCruce(array $cruce, string $mes): array` → lista de `['origen'=>'auto','estatus'=>string,'items'=>[['tipo','codgas','nro','movimiento_id','monto','mes','ocupa'], …]]`. Agrupa por `deposito_id + estatus`. Cobros sin depósito: un grupo por cobro. Item M con `ocupa = 0` cuando el estatus es `Diferencia`; en los demás, `1`. `mes` del item C = `substr(fecha,0,7)`; el del item M = el mes del depósito (`fecha` del depósito; si falta, `$mes`).
  - `static claveDoc(int $codgas, int $nro): string` → `"$codgas|$nro"`.
  - `static cobrosSinGrupo(array $cobros, array $grupos): array` → cobros del mes cuya clave no está en ningún item C de `$grupos` (activos).
  - `static movimientosOcupados(array $grupos): array<int,true>` → ids de items M con `ocupa = 1`.
  - `static filasDesdeGrupos(array $grupos, array $cobrosPorClave, array $movPorId, array $facturasPorClave): array` → una fila por item C (misma forma que hoy arma `conciliation_payments` antes del mapeo de tags: `codgas, nro, estacion, empresa, fecha, codopr, cliente, tipo, txtref, satuid, monto, facturas, mov, estatus, deposito_id, banco, cuenta, fecha_banco, secuencia_banco, abono, contraparte, rfc_contraparte, detalle_banco, cobros_mov, monto_mov, diferencia, candidatos(null)`) más `grupo_id, origen, comentario, creado_por, items_guardados`. Los grupos sin items C dan una fila con las columnas del cobro vacías (`nro = null`) y `facturas` = folios de los items F. `diferencia` = suma de M ocupa=1 (o de la M de referencia si es Diferencia) − (suma de cobros, o de facturas si no hay cobros). Varios movimientos: `banco/cuenta/...` del primero, `abono` = suma, `secuencia_banco` = secuencias unidas con ", ".
  - `static cambiosReintento(array $gruposViejos, array $cruceNuevo): array` → lista de `['grupo_id'=>int, 'nuevo'=>grupo]` solo donde algún cobro cambió de estatus o de `deposito_id`.

- [ ] **Step 1: Write the failing tests** (`tests/ConciliacionCobrosGuardadoTest.php`, mismo estilo que `ConciliacionCobrosTest.php`)

```php
// gruposDesdeCruce: MOV 23289 con 2 cobros y 1 depósito → 1 grupo, 2 C + 1 M ocupa 1
$cruce = ConciliacionCobros::conciliar([cobro(64103, 23289, 2383.05, '2026-10-07'), cobro(64104, 23289, 18959.88, '2026-10-07')],
                                       [deposito(9001, 23289, 21342.93, '2026-10-07')]);
$g = ConciliacionCobrosGuardado::gruposDesdeCruce($cruce, '2026-10');
check('un grupo por depósito', count($g) === 1 && $g[0]['estatus'] === 'Cuadra' && $g[0]['origen'] === 'auto');
check('items 2C + 1M', count(array_filter($g[0]['items'], fn($i) => $i['tipo'] === 'C')) === 2
                    && count(array_filter($g[0]['items'], fn($i) => $i['tipo'] === 'M' && $i['ocupa'] === 1 && $i['movimiento_id'] === 9001)) === 1);
// Diferencia → item M ocupa 0
// Sin banco → un grupo por cobro, sin item M
// cobrosSinGrupo: 3 cobros, grupos con 2 → devuelve el tercero
// movimientosOcupados ignora ocupa 0
// filasDesdeGrupos: grupo manual con cobro 64448 (otro mes, en $cobrosPorClave) + M 9001 → fila con origen 'manual', estatus 'Conciliado manual', diferencia = abono − monto
// filasDesdeGrupos: grupo M 23673 ($2,000,000) + F I104614 sin cobros → fila nro null, diferencia = 2000000 − total factura
// cambiosReintento: 'Sin banco' que ahora 'Cuadra por importe' con deposito 9100 → 1 cambio; mismo estatus y depósito → 0
finish();
```

- [ ] **Step 2: Run** `php tests/ConciliacionCobrosGuardadoTest.php` → FALLA (clase no existe).
- [ ] **Step 3: Implement** la clase con las firmas de arriba (sin BD, sin `$_SESSION`).
- [ ] **Step 4: Run** `php tests/ConciliacionCobrosGuardadoTest.php` y `php tests/ConciliacionCobrosTest.php` → todo `ok`.
- [ ] **Step 5: Commit** `feat(conciliacion): grupos guardables del cruce de cobros`

### Task 2: `ConciliacionAlertas` (sin BD)

**Files:**
- Create: `_assets/classes/ConciliacionAlertas.class.php`
- Test: `tests/ConciliacionAlertasTest.php`

**Interfaces:**
- Produces: `static deGrupo(array $grupo, array $ctx): string[]`.
  - `$grupo`: `['items'=>[…], 'estatus'=>…, 'origen'=>…]` (items como en Task 1).
  - `$ctx`: `['cobros'=>clave=>fila cobro fresca|null, 'movs'=>id=>fila movimiento|null, 'facturas'=>clave=>['total','cancelada','codopr','cliente','pagada'(float)]|null, 'otrosGrupos'=>['C|clave'/'M|id'/'F|clave' => ['id','origen']], 'cuentasEstacion'=>codgas=>[cuenta=>n], 'mes'=>'Y-m']`.
- Mensajes (texto exacto, en español; `{x}` = valor):
  - `No cuadra: diferencia ${dif}` (|dif| ≥ 0.005; dif con 2 decimales y separador de miles)
  - `El MOV capturado {mov} del cobro {nro} no es ninguno de los movimientos elegidos`
  - `{n} días entre el cobro {nro} y el movimiento {sec}` (> 3)
  - `El ordenante del banco no se parece al cliente {cliente}` (sin palabras ≥ 4 letras en común entre `contraparte`/`rfc` y el cliente; RFC igual cuenta como coincidencia)
  - `Ya estaba en otra conciliación ({origen} #{id})`
  - `Movimiento de otro mes ({mes})` / `Cobro de otro mes ({mes})`
  - `Movimientos de cuentas distintas` / `Cuenta {cuenta} no habitual para la estación`
  - `Factura {folio} cancelada` / `Factura {folio} de otro cliente` / `Factura {folio} ya pagada por cobros (${pagada})`
  - `Cambió después de conciliar: cobro {nro} cancelado` / `… cobro {nro} ya no existe` / `… monto del cobro {nro} era ${a}, hoy ${b}` (lo mismo para movimiento y factura)
  - `Sin cobros ni facturas` / `Sin movimiento bancario` (este último solo para `origen = manual`)

- [ ] **Step 1: Write failing tests**, una por mensaje, con los datos de octubre: 64100 MOV 23289 elegido 23196 → alerta MOV; 64221 cancelado después → "cambió…"; grupo 23673 sin cobros con F I104614 → sin alerta de "Sin cobros ni facturas" y con "No cuadra" si los montos difieren; grupo cuadrado al centavo → `[]`.
- [ ] **Step 2: Run** `php tests/ConciliacionAlertasTest.php` → FALLA.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** → `ok`.
- [ ] **Step 5: Commit** `feat(conciliacion): alertas de conciliación de cobros`

### Task 3: `ConciliacionCobrosGuardadoModel` (TG)

**Files:**
- Create: `_assets/models/ConciliacionCobrosGuardadoModel.php` (extends `Model`; tablas con prefijo `TG.dbo.`)
- Modify: `_assets/models/ConciliacionVentaModel.php` (búsquedas y lecturas de SG12)
- Test: `tests/ConciliacionCobrosGuardadoModelTest.php` (contra TG real, **todo dentro de `beginTransaction()` … `rollBack()`**, mes ficticio `'1999-01'`)

**Interfaces (ConciliacionCobrosGuardadoModel):**
- `mesGuardado(string $mes): ?array` (fila de `_mes` o null)
- `reservarMes(string $mes, string $usuario): bool` — INSERT con `reintento_fecha = hoy`; si falla por PK → `false` (atrapar la excepción de `insert()`).
- `guardarGrupos(array $grupos, string $mes, string $usuario): array<int>` — inserta grupo + items, devuelve ids. Para ~800 items, inserts multi-renglón de hasta 200 filas.
- `gruposActivos(string $mes): array` — grupos activos con algún item de ese `mes`, cada uno con `items`.
- `gruposQueOcupan(array $clavesC, array $movIds, array $clavesF): array<int>` — ids de grupos activos con esos items y `ocupa = 1`.
- `darDeBaja(array $ids, string $usuario): void`
- `tomarReintento(string $mes): bool` — `updateSafe("UPDATE … SET reintento_fecha = CAST(GETDATE() AS date) WHERE mes = ? AND (reintento_fecha IS NULL OR reintento_fecha < CAST(GETDATE() AS date))")` → `> 0`.
- `bajaAutoDelMes(string $mes, string $usuario): void` (grupos `auto` con `grupo.mes = ?`) y `marcarRecalculado(string $mes, string $usuario)`.
- `movimientosPorId(array $ids): array<int,array>` (mismas columnas que `depositos()`)
- `buscarMovimientos(array $f): array` — `$f`: `importe, importe_min, importe_max, desde, hasta, cuenta, secuencia, texto`; `abono > 0`, TOP 200, más `grupo_id/origen/mes_grupo` del grupo activo que lo ocupa (LEFT JOIN).
- `depositosSinConciliar(string $desde, string $hasta, array $cuentas): array` — abonos > 0 de esas cuentas sin item M activo con `ocupa = 1`.

**Interfaces (ConciliacionVentaModel, nuevas):**
- `cobrosPorClave(array $claves): array` — mismas columnas que `cobros()` para pares codgas|nro de otros meses.
- `buscarCobros(array $f, int $from, int $until): array` — tip 4 subope 8 por `folio, cliente (texto), importe, mov`; TOP 200.
- `facturasPorClave(array $claves): array` y `buscarFacturas(array $f): array` — tip 3: `codgas, nro, folio (get_invoice_series), fecha, codopr, cliente, total, cancelada, pagada` (suma de `DocumentosA.mtoapl` tip 4 que la aplican).

- [ ] **Step 1: Write failing test**: en transacción → `reservarMes('1999-01')` true; segunda llamada false; `guardarGrupos` con 2 grupos → `gruposActivos('1999-01')` devuelve 2 con sus items; `gruposQueOcupan` ignora items `ocupa = 0`; `tomarReintento` false (ya es hoy); `darDeBaja` → 1 activo; `rollBack()`; al final `mesGuardado('1999-01') === null`.
- [ ] **Step 2: Run** `php tests/ConciliacionCobrosGuardadoModelTest.php` → FALLA.
- [ ] **Step 3: Implement** ambos modelos.
- [ ] **Step 4: Run** → `ok`, y verificar con sqlcmd `SELECT COUNT(*) FROM TG.dbo.conciliacion_cobros_mes WHERE mes='1999-01'` → 0.
- [ ] **Step 5: Commit** `feat(conciliacion): modelo de conciliación de cobros guardada`

### Task 4: Controlador — carga guardada, reintento, recalcular, depósitos sin conciliar

**Files:**
- Modify: `_assets/controllers/income.php` (`conciliation_payments` y métodos nuevos después de él)
- Modify: `routes/income.php`
- Create: `tools/conciliacion_cobros_guardado_validar.php` (CLI, solo lectura: corre el cruce de 2026-10 y compara `filasDesdeGrupos(gruposDesdeCruce(...))` contra el cruce directo: mismo estatus, depósito y diferencia por cobro)

**Interfaces:**
- `conciliation_payments()` (POST mes, empresa): flujo del spec. Primera vez: `reservarMes` → cruce → `guardarGrupos` en transacción (rollback + camino de lectura si `reservarMes` es false). Después: `gruposActivos` + `cobrosSinGrupo` → solo si hay nuevos: `depositos()` para ellos menos `movimientosOcupados` → `conciliar` → `guardarGrupos`. Luego `filasDesdeGrupos` + `ConciliacionAlertas::deGrupo` por grupo → el mismo mapeo de hoy (tags, `forma_pago`, `facturas` con serie) + `grupo_id, origen, alertas, comentario`. Respuesta: `['data'=>…, 'guardado'=>['en','por','recalculado_en','recalculado_por']]`.
- `conciliation_payments_retry()` → `['cambios'=>int,'detalle'=>[['nro','antes','despues'], …]]`. Si `tomarReintento` es false → `cambios 0` sin consultas. Si no: los grupos auto activos con estatus en `REINTENTAR` → sus cobros; bolsa = `depositos()` de esos cobros − ocupados + sus depósitos actuales; `conciliar` → `cambiosReintento` → en transacción `darDeBaja` + `guardarGrupos`.
- `conciliation_recalculate()` → `bajaAutoDelMes` + recálculo de todos los cobros del mes que no están en manuales + `marcarRecalculado`; `['ok'=>true]`.
- `conciliation_unmatched_deposits()` (POST mes, `cuentas[]`) → `['data'=>depositosSinConciliar(...)]`.
- Rutas (permiso 14, POST): `conciliation/payments/retry/` → `income.conciliation_payments_retry`; `conciliation/recalculate/` → `income.conciliation_recalculate`; `conciliation/unmatched-deposits/` → `income.conciliation_unmatched_deposits`.

- [ ] **Step 1:** Escribir `tools/conciliacion_cobros_guardado_validar.php` (guardado con `if (PHP_SAPI !== 'cli')`, como `tools/conciliacion_debito_validar.php`); esperado para 2026-10 empresa 1: 579 cobros, $51,449,604.56, 0 diferencias de estatus.
- [ ] **Step 2: Run** `php tools/conciliacion_cobros_guardado_validar.php` → `ok` en todas las líneas.
- [ ] **Step 3: Implement** los métodos y las rutas.
- [ ] **Step 4: Run** `php tools/routes.php lint` → sin errores; `php -l _assets/controllers/income.php`.
- [ ] **Step 5: Commit** `feat(conciliacion): cobros guardados, reintento diario y recalcular`

### Task 5: Conciliación manual — búsquedas, modal, guardar, deshacer

**Files:**
- Modify: `_assets/controllers/income.php`, `routes/income.php`
- Create: `views/income/modals/conciliation_manual.html` (vista parcial: 3 secciones Cobros · Movimientos · Facturas con buscador y seleccionados, totales, `#cmAlertas`, comentario, botones Guardar / Deshacer / Cancelar)

**Interfaces (POST, permiso 14):**
- `conciliation_manual_modal()` → HTML (patrón vista parcial vía fetch). POST `grupo_id` opcional o `movimiento_id` opcional: precarga los items del grupo o el movimiento.
- `conciliation_search_movements()` → `{data}` de `buscarMovimientos`.
- `conciliation_search_payments()` → `{data}` de `buscarCobros` (mes ±1) + `grupo_id/origen` si está en un grupo activo.
- `conciliation_search_invoices()` → `{data}` de `buscarFacturas`.
- `conciliation_manual_preview()` (POST `items` JSON, `grupo_id` opcional) → `{alertas, totales:{cobros,facturas,movimientos,diferencia}}`. No escribe nada.
- `conciliation_manual_save()` (POST `items`, `comentario`, `grupo_id` opcional, `mes`) → transacción: `gruposQueOcupan(items) ∪ {grupo_id}` → `darDeBaja`; `guardarGrupos([manual])` con `ocupa = 1` en todos los items y `estatus 'Conciliado manual'` → `{ok, grupo_id, alertas}`. Montos de los items: frescos de BD, no del POST.
- `conciliation_manual_undo()` (POST `grupo_id`) → `darDeBaja` solo si `origen = 'manual'`; si no → 400 `{error}`.
- Rutas: `conciliation/manual/modal/`, `conciliation/manual/search-movements/`, `…/search-payments/`, `…/search-invoices/`, `…/preview/`, `…/save/`, `…/undo/` con nombres `income.conciliation_manual_*`.
- Validar entrada: `items` máximo 300; `tipo` en C/M/F; enteros con `intval`; comentario ≤ 500.

- [ ] **Step 1:** Implementar endpoints y la vista parcial.
- [ ] **Step 2: Run** `php tools/routes.php lint`, `php -l _assets/controllers/income.php`.
- [ ] **Step 3: Run** prueba CLI de humo dentro de una transacción con `rollBack` (añadir al final de `tests/ConciliacionCobrosGuardadoModelTest.php`): guardar manual sobre un grupo auto de 2 cobros eligiendo 1 → el auto queda inactivo y `cobrosSinGrupo` devuelve el otro.
- [ ] **Step 4: Commit** `feat(conciliacion): conciliación manual de cobros`

### Task 6: Vista

**Files:**
- Modify: `views/income/conciliation.html` (tabla `#tablaCruce`, chips, apartado Depósitos sin conciliar, botones de cabecera, ayuda `AYUDA.cruce`)
- Create: `_assets/js/conciliation_manual.js` (modal: abrir/fetch, buscadores con debounce de 300 ms, seleccionar/quitar, `preview` en cada cambio, guardar, deshacer; expone `window.ConciliacionManual.abrir({grupo_id|movimiento_id})` y dispara el evento `conciliacion:guardada`)

**Cambios:**
- Columnas nuevas en `#tablaCruce`: **Origen** (después de Estatus; badge Auto/Manual) y una última columna con botón **Conciliar** + icono ⚠ con tooltip de `alertas` (unidas con salto de línea). Actualizar `COL_ESTATUS_CRUCE` si cambia el índice.
- `agruparCruce`: llave = `'g' + grupo_id + '|' + cobro + '|' + estacion`; `cobros_dep` = cobros del mismo `grupo_id`; las filas sin cobro (`cobro === null`) se agrupan por `grupo_id`.
- Chip **"Con alertas"** (filtra filas con `alertas.length > 0`) y estatus `Conciliado manual` en los chips existentes.
- Cabecera del tab: botones **Conciliar desde banco** (abre el modal vacío) y **Recalcular automático** (`confirm`, POST, recarga cobros) y la leyenda `Guardado el … por …` (y `Recalculado …`) de `r.guardado`.
- Después de pintar cobros: POST `income.conciliation_payments_retry`; si `cambios > 0` → `alertify.success('Se conciliaron ' + n + ' cobros pendientes con movimientos nuevos del banco')` y volver a pedir solo `income.conciliation_payments` (y depósitos sin conciliar).
- Apartado **Depósitos sin conciliar** debajo de la tabla de cobros: tabla `#tablaDepSin` (cuenta, fecha, secuencia, abono, ordenante, RFC, detalle, botón Conciliar) + chip conteo/total; se pide a `income.conciliation_unmatched_deposits` con las cuentas distintas de las filas de cobros, después de pintarlas.
- En `conciliacion:guardada`: recargar cobros y depósitos sin conciliar.

- [ ] **Step 1:** Implementar.
- [ ] **Step 2: Run** `node tests/conciliation_common_test.js` y `php tools/routes.php lint` → ok (las llamadas `routeUrl('income.…')` nuevas deben existir).
- [ ] **Step 3: Commit** `feat(conciliacion): vista de conciliación manual y depósitos sin conciliar`

### Task 7: Cierre

**Files:**
- Modify: `docs/superpowers/specs/2026-11-17-conciliacion-cobros-manual-design.md` (sección "Estado" con lo hecho y lo pendiente del navegador)

- [ ] **Step 1: Run** todas las pruebas: `php tests/ConciliacionCobrosTest.php`, `php tests/ConciliacionCobrosGuardadoTest.php`, `php tests/ConciliacionAlertasTest.php`, `php tests/ConciliacionCobrosGuardadoModelTest.php`, `php tools/conciliacion_cobros_guardado_validar.php`, `php tools/routes.php lint`, `node tests/conciliation_common_test.js` → todo ok.
- [ ] **Step 2:** Revisión con el agente `revisor` (pagos/BD/XSS) antes del commit final.
- [ ] **Step 3:** Entregar al usuario la lista de archivos tocados y la checklist de prueba en el navegador: abrir octubre por primera vez (se guarda), recargar (no escribe), conciliar a mano 64100 → MOV 23196, deshacer, ligar MOV 23673 a facturas, Recalcular, revisar "Depósitos sin conciliar".
