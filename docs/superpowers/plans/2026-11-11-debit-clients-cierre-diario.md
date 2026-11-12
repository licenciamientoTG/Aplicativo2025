# Foto diaria de clientes débito v2 (cierre de ayer) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que la pestaña "Estado de Cuenta Foto" guarde y muestre, por cliente débito, el cierre del día de ayer con saldos iniciales (sistema / calculado por despachos / por cortes), movimientos por despachos y por cortes, desfase, ajuste retroactivo y saldos finales sistema/vehículos.

**Architecture:** Lógica pura (armar fila, detectar cambio, reconstruir cierre, leer un día, días pendientes) en una clase sin BD probada con `tests/`; un modelo nuevo hace la consulta agregada a SG12 y el guardado con vigencia en TG; el controlador `Income` y el cron existentes pasan a usar el modelo nuevo; la vista lee por fecha.

**Tech Stack:** PHP 8 (sin framework), SQL Server vía `MySqlPdoHandler`, Twig, jQuery + DataTables.

**Spec:** `docs/superpowers/specs/2026-11-11-debit-clients-cierre-diario-design.md`

## Global Constraints

- Tablas ya creadas (2026-11-11) con `docs/sql/debit_clients_cierre_schema.sql`: `TG.dbo.debit_clients_cierre` y `TG.dbo.debit_clients_cierre_runs`. No crear ni alterar tablas.
- La v1 `TG.dbo.debit_clients_snapshot` y `ClientesModel::refresh_debit_snapshot` / `get_debit_snapshot` no se borran ni se modifican.
- Universo: `SG12.dbo.Clientes WHERE tipval = 4 AND codest <> -1`.
- Anticipos: `DocumentosC t1 JOIN Documentos t2 ON t1.nro=t2.nro AND t1.codgas=t2.codgas AND t1.tip=t2.tip`, `t2.codopr` = cliente, `t2.mtoiva > 0`, `t2.mto > 100`, `t2.codprd NOT IN (1,2,3,-64,179,180,181,192,193)`, `ISNULL(t1.flgcon,0) <> 141`, monto `(t2.mtoori + t2.mtoiva)/100.0`, fecha `t1.fch`.
- Despachos: `SG12.dbo.Despachos`, `codcli`, fecha `fchtrn`, monto `mto` (pesos). Cortes: `SG12.dbo.ValesR`, `codcli`, `codval = 127`, fecha `fch`, monto `mto` (pesos).
- Fechas de ControlGas = serial entero (`dateToInt('Y-m-d')`); en TG las fechas son `DATE` `'Y-m-d'`.
- D = ayer siempre (`date('Y-m-d', strtotime('-1 day'))`); el endpoint de refresco sigue siendo solo CLI con `cron_token`, sin fecha arbitraria.
- Tolerancia de comparación de montos: `0.005`. Tope de días de recuperación: `31`.
- Fotos congeladas: nunca se recalcula una fila ya cerrada; saldos iniciales calculados = toda la historia en cada corrida.
- `_assets/controllers/income.php` y `_assets/models/ClientesModel.php` llevan BOM + CRLF: editar con Edit, nunca reescribir el archivo (verificar `head -c 3 archivo | xxd` = `efbbbf`).
- Pruebas sin framework: `require tests/support.php`, `check()`, `finish()`; se corren con `php tests/X.php`.
- No levantar el servidor PHP. No hacer commit salvo que el usuario lo pida. Escrituras en TG fuera de `procesar()` requieren autorización del usuario.
- Textos de la UI en español.

## Review Focus

1. Re-correr el mismo día D (cron disparado dos veces): la fila de D se actualiza in place y su `saldo_ini_sistema`/`ajuste_retro_*` se calculan contra la fila anterior a D, no contra la propia fila de D → test en Task 1 (`armarFila` recibe la anterior correcta) y verificación en Task 3.
2. Cliente con fila previa cuyo `saldo_fin_sistema` es NULL (día de recuperación) → `saldo_ini_sistema` NULL, sin error → test en Task 1.
3. Comparar NULL contra número cuenta como cambio y NULL contra NULL como igual → test en Task 1.
4. Leer un día anterior a la primera foto o un cliente sin fila para ese día → no aparece (no se inventan ceros) → verificación en Task 4.
5. Cliente que sale del universo → su vigente se cierra en `D − 1` y no aparece en días ≥ D → verificación en Task 3.

---

### Task 1: Lógica pura `DebitCierre`

**Files:**
- Create: `_assets/classes/DebitCierre.class.php`
- Test: `tests/DebitCierreTest.php`

**Interfaces:**
- Produces (todos `public static`, arreglos asociativos con las claves = nombres de columna de `debit_clients_cierre`):
  - `const MONTOS = ['saldo_ini_sistema','saldo_ini_calc_desp','saldo_ini_calc_cortes','ajuste_retro_desp','ajuste_retro_cortes','anticipos_dia','consumos_desp_dia','consumos_cortes_dia','desfase_dia','saldo_fin_calc_desp','saldo_fin_calc_cortes','saldo_fin_sistema','saldo_fin_vehiculos'];`
  - `reconstruir(?float $lecturaDebsdo, ?float $lecturaVeh, ?float $postCierre, int $debglo): array` → `['saldo_fin_sistema' => ?float, 'saldo_fin_vehiculos' => ?float]`. Sin lectura (NULL) → ambos NULL. `sistema = debsdo + post`; `vehiculos = debglo === 0 ? veh + post : veh` (regla a confirmar en Task 2).
  - `armarFila(array $calc, ?array $anterior): array` — `$calc` trae `codcli, cliente, debglo, saldo_ini_calc_desp, saldo_ini_calc_cortes, anticipos_dia, consumos_desp_dia, consumos_cortes_dia, lectura_debsdo, lectura_vehiculos, consumos_post_cierre, lectura_at` (las 4 últimas pueden ser NULL). Devuelve todas las columnas de datos (sin `id`, `fecha_*`, `updated_at`), montos redondeados a 2.
  - `hayCambio(array $vigente, array $nueva): bool` — compara `MONTOS` y `debglo`.
  - `filaParaDia(array $fila, string $dia): array`
  - `diasPendientes(?string $ultimoOk, string $ayer, int $tope = 31): array` → lista de `'Y-m-d'` ascendente.

- [ ] **Step 1: Write the failing tests** en `tests/DebitCierreTest.php` (require `support.php` y la clase). Casos, con estos valores:

```php
$calc = ['codcli'=>1,'cliente'=>'X','debglo'=>0,'saldo_ini_calc_desp'=>1000.0,'saldo_ini_calc_cortes'=>990.0,
         'anticipos_dia'=>500.0,'consumos_desp_dia'=>300.0,'consumos_cortes_dia'=>250.0,
         'lectura_debsdo'=>700.0,'lectura_vehiculos'=>700.0,'consumos_post_cierre'=>40.0,'lectura_at'=>'2026-11-12 08:00:00'];
$f = DebitCierre::armarFila($calc, null);
check('sin anterior: ini sistema NULL', $f['saldo_ini_sistema'] === null);
check('sin anterior: ajustes NULL', $f['ajuste_retro_desp'] === null && $f['ajuste_retro_cortes'] === null);
check('desfase = desp - cortes', $f['desfase_dia'] == 50.0);
check('fin calc desp = 1000+500-300', $f['saldo_fin_calc_desp'] == 1200.0);
check('fin calc cortes = 990+500-250', $f['saldo_fin_calc_cortes'] == 1240.0);
check('fin sistema = lectura + post', $f['saldo_fin_sistema'] == 740.0);
check('fin vehiculos sin bolsa global = lectura + post', $f['saldo_fin_vehiculos'] == 740.0);
check('bolsa global: vehiculos sin sumar post', DebitCierre::reconstruir(700.0, 50.0, 40.0, 1)['saldo_fin_vehiculos'] == 50.0);
check('sin lectura: fin sistema NULL', DebitCierre::reconstruir(null, null, null, 0)['saldo_fin_sistema'] === null);

$ant = $f + ['fecha_desde'=>'2026-11-10'];
$c2 = array_merge($calc, ['saldo_ini_calc_desp'=>1210.0,'saldo_ini_calc_cortes'=>1240.0]);
$f2 = DebitCierre::armarFila($c2, $ant);
check('ini sistema = fin sistema anterior', $f2['saldo_ini_sistema'] == 740.0);
check('ajuste desp = 1210 - 1200', $f2['ajuste_retro_desp'] == 10.0);
check('ajuste cortes = 0', $f2['ajuste_retro_cortes'] == 0.0);
check('anterior con fin sistema NULL => ini sistema NULL',
    DebitCierre::armarFila($c2, array_merge($ant, ['saldo_fin_sistema'=>null]))['saldo_ini_sistema'] === null);

check('igual => sin cambio', DebitCierre::hayCambio($f2, $f2) === false);
check('diferencia 0.004 => sin cambio', DebitCierre::hayCambio($f2, array_merge($f2, ['anticipos_dia'=>500.004])) === false);
check('diferencia 0.01 => cambio', DebitCierre::hayCambio($f2, array_merge($f2, ['anticipos_dia'=>500.01])) === true);
check('NULL vs numero => cambio', DebitCierre::hayCambio(array_merge($f2, ['saldo_fin_sistema'=>null]), $f2) === true);
check('NULL vs NULL => igual', DebitCierre::hayCambio(array_merge($f2, ['saldo_fin_sistema'=>null]), array_merge($f2, ['saldo_fin_sistema'=>null])) === false);
check('debglo distinto => cambio', DebitCierre::hayCambio($f2, array_merge($f2, ['debglo'=>1])) === true);

$fila = $f2 + ['fecha_desde'=>'2026-11-05','fecha_hasta'=>'2026-11-09'];
$dia = DebitCierre::filaParaDia($fila, '2026-11-07');
check('dia en medio: ini = fin', $dia['saldo_ini_calc_desp'] == $fila['saldo_fin_calc_desp'] && $dia['saldo_ini_sistema'] == $fila['saldo_fin_sistema']);
check('dia en medio: movimientos 0', $dia['anticipos_dia'] == 0 && $dia['consumos_desp_dia'] == 0 && $dia['consumos_cortes_dia'] == 0 && $dia['desfase_dia'] == 0);
check('dia en medio: ajustes 0', $dia['ajuste_retro_desp'] == 0 && $dia['ajuste_retro_cortes'] == 0);
check('dia = fecha_desde: sin cambios', DebitCierre::filaParaDia($fila, '2026-11-05') == $fila);

check('pendientes: sin corridas => solo ayer', DebitCierre::diasPendientes(null, '2026-11-11') === ['2026-11-11']);
check('pendientes: ultimo = ayer => ayer (re-corrida)', DebitCierre::diasPendientes('2026-11-11', '2026-11-11') === ['2026-11-11']);
check('pendientes: hueco de 2 dias', DebitCierre::diasPendientes('2026-11-08', '2026-11-11') === ['2026-11-09','2026-11-10','2026-11-11']);
check('pendientes: mas de 31 => solo ayer', DebitCierre::diasPendientes('2026-09-01', '2026-11-11') === ['2026-11-11']);
finish();
```

- [ ] **Step 2:** Run `php tests/DebitCierreTest.php` — Expected: fatal "Class DebitCierre not found" o FALLA.
- [ ] **Step 3:** Implementar la clase con las firmas de Interfaces (`declare(strict_types=1)`, mismo estilo que `ConciliacionDebito.class.php`). `filaParaDia`: si `$dia === $fila['fecha_desde']` devuelve la fila igual; si no, `saldo_ini_sistema = saldo_fin_sistema`, `saldo_ini_calc_desp = saldo_fin_calc_desp`, `saldo_ini_calc_cortes = saldo_fin_calc_cortes`, movimientos/desfase/ajustes = 0.
- [ ] **Step 4:** Run `php tests/DebitCierreTest.php` — Expected: `Todo bien`.

---

### Task 2: Consulta del día (`DebitClientsCierreModel::calcularDia`)

**Files:**
- Create: `_assets/models/DebitClientsCierreModel.php` (`class DebitClientsCierreModel extends Model`)
- Create (scratch, no se despliega): script de verificación en el scratchpad de la sesión

**Interfaces:**
- Consumes: `DebitCierre::reconstruir` (no se llama aquí; solo se confirma su regla).
- Produces: `calcularDia(string $dia, bool $conLectura): array` — una fila por cliente del universo con las claves de `$calc` de Task 1 (`lectura_*`, `consumos_post_cierre`, `lectura_at` NULL si `$conLectura` es false). `consumos_post_cierre` = despachos del cliente con `fchtrn > dateToInt($dia)`. `debglo` = `ISNULL(debglo,0)`.

- [ ] **Step 1:** Implementar `calcularDia` con una consulta a SG12 (CTEs o temporales + `OPTION (RECOMPILE)`): históricos antes de `$dia` (anticipos, despachos, cortes), del día (`= $dia`) y, con lectura, `Clientes.debsdo`, `SUM(ClientesVehiculos.debsdo)` y post-cierre. `CAST(... AS decimal(18,2))` en todos los montos (sqlsrv devuelve notación científica para `debsdo` chico — bug ya visto en la v1).
- [ ] **Step 2: Verificar contra la BD (solo lectura)** con un script CLI en el scratchpad que llame `calcularDia('2026-11-10', true)` y compare el cliente 1709379 contra consultas independientes. Expected: `consumos_desp_dia = 7268.62` y `consumos_cortes_dia = 7268.62`; `saldo_ini_calc_desp + anticipos_dia − consumos_desp_dia` = `saldo_ini_calc_desp` de `calcularDia('2026-11-11', false)`; número de filas = `SELECT COUNT(*) FROM Clientes WHERE tipval=4 AND codest<>-1`.
- [ ] **Step 3: Confirmar la regla de vehículos** (spec: "el builder debe verificar"): tomar 3 clientes con `debglo = 0` y 2 con `debglo = 1` que tengan despachos hoy; leer `debsdo` y suma de vehículos, y comparar contra la variación esperada por sus despachos entre dos lecturas separadas (o contra `ClientesVehiculos.debsdo` de la tarjeta del despacho). Si la regla de `DebitCierre::reconstruir` no se cumple, ajustarla, actualizar su test en Task 1 y anotar lo observado en el spec (sección "Reconstrucción del cierre").
- [ ] **Step 4: Medir** el tiempo de `calcularDia(ayer, true)`. Expected: < 5 min; si no, optimizar la consulta sin cambiar el método (spec, "Rendimiento").

---

### Task 3: Guardado con vigencia, bitácora y carga inicial (`procesar`)

**Files:**
- Modify: `_assets/models/DebitClientsCierreModel.php`

**Interfaces:**
- Consumes: `calcularDia` (Task 2); `DebitCierre::armarFila`, `hayCambio`, `diasPendientes` (Task 1).
- Produces: `procesar(string $ayer): array` → `['success'=>bool,'dias'=>[['fecha'=>'Y-m-d','nuevos'=>int,'actualizados'=>int,'sin_cambio'=>int,'cerrados'=>int], ...],'duracion_seg'=>float,'mensaje'=>?string]`.

- [ ] **Step 1:** Implementar `procesar`: `ultimoOk = MAX(fecha_dia) FROM debit_clients_cierre_runs WHERE ok = 1`; para cada día de `diasPendientes(ultimoOk, $ayer)`: `calcularDia($dia, $dia === $ayer)`, luego en una transacción (conexión TG, igual que `refresh_debit_snapshot`): por cliente, `anterior` = fila vigente, salvo que la vigente tenga `fecha_desde = $dia` → entonces `anterior` = la fila con `fecha_hasta = $dia − 1` del cliente (o NULL) y se hace UPDATE in place; sin vigente → INSERT; `hayCambio` false → nada; true → cerrar vigente con `fecha_hasta = $dia − 1` + INSERT con `fecha_desde = $dia`. Vigentes de clientes que ya no vienen en el cálculo → `fecha_hasta = $dia − 1` (`cerrados`). Bitácora: INSERT/UPDATE en `debit_clients_cierre_runs` por `fecha_dia` con conteos, `fin`, `ok`, `mensaje` (en error: rollBack, `ok = 0`, mensaje, `success = false`, no seguir con días siguientes).
- [ ] **Step 2: Carga inicial** (autorizada por el diseño aprobado; escribe en TG): correr `procesar(date('Y-m-d', strtotime('-1 day')))` desde un script CLI en el scratchpad. Expected: `success = true`, un día, `nuevos` = número de clientes del universo, `actualizados = 0`.
- [ ] **Step 3: Re-corrida del mismo día:** correr de nuevo. Expected: `nuevos = 0`, `actualizados + sin_cambio` = universo, `COUNT(*)` de la tabla igual que tras Step 2, y `saldo_ini_sistema` sigue NULL en las filas (no se toma la propia fila de D como anterior).
- [ ] **Step 4: Verificar en SQL:** `SELECT COUNT(*) FROM debit_clients_cierre WHERE fecha_hasta IS NULL` = universo; ninguna fila con `ABS(desfase_dia - (consumos_desp_dia - consumos_cortes_dia)) > 0.005`; la bitácora tiene una fila `ok = 1` para ayer.

---

### Task 4: Controlador y cron

**Files:**
- Modify: `_assets/controllers/income.php` — métodos `debit_snapshot_refresh()` y `debit_snapshot_table()` (y el constructor si instancia modelos ahí)
- Modify: `cron/debit_snapshot_diario.php` — solo el comentario de cabecera (hora 08:00, qué hace)

**Interfaces:**
- Consumes: `DebitClientsCierreModel::procesar(string $ayer)`; nuevo método `fotoDelDia(string $dia): array` en el modelo.
- Produces: `POST /income/debit_snapshot_table` con `fecha` (`Y-m-d`, default ayer; inválida → `{"data":[],"fecha":null,"message":"Fecha inválida"}`) → `{"data":[...filas con las columnas de la tabla + codcli, cliente, debglo, fecha_desde...], "fecha":"Y-m-d", "ultima_corrida":"Y-m-d|null"}`.

- [ ] **Step 1:** Agregar `fotoDelDia(string $dia): array` al modelo: filas con `fecha_desde <= $dia AND (fecha_hasta IS NULL OR fecha_hasta >= $dia)`, pasadas por `DebitCierre::filaParaDia($fila, $dia)`, ordenadas por cliente; y `ultimaCorridaOk(): ?string`.
- [ ] **Step 2:** `debit_snapshot_refresh()`: mantener la guarda CLI + token; ignorar `fecha` de la petición; llamar `procesar(date('Y-m-d', strtotime('-1 day')))`; salida CLI igual que hoy (`echo json_encode` + `exit(success ? 0 : 1)`).
- [ ] **Step 3:** `debit_snapshot_table()`: leer `$_POST['fecha']`, validar `^\d{4}-\d{2}-\d{2}$`, responder el JSON de Interfaces.
- [ ] **Step 4: Verificar:** `php -l _assets/controllers/income.php`; BOM intacto (`head -c 3 ... | xxd`); script CLI en el scratchpad que llame `fotoDelDia(ayer)` → filas = universo; `fotoDelDia('2020-01-01')` → 0 filas (Review Focus 4); `php cron/debit_snapshot_diario.php` **no se corre** aquí (escribiría; ya se probó `procesar` en Task 3).

---

### Task 5: Pestaña "Estado de Cuenta Foto"

**Files:**
- Modify: `views/income/clients.html` — card del botón (≈ línea 995), tabla `#debit_snapshot_table` (≈ 1022), modal `#modal_info_debit_snapshot` (≈ 1237)
- Modify: `_assets/js/income.js` — `debit_snapshot_table()` (≈ 3634)

**Interfaces:**
- Consumes: endpoint de Task 4.

- [ ] **Step 1:** Vista: input `type="date"` `#debit_snapshot_dia` (default ayer, `{{ 'now'|date_modify('-1 day')|date('Y-m-d') }}`) junto al botón "Consultar Foto"; texto "Última corrida: `#debit_snapshot_ultima`". Encabezados de la tabla, en este orden: Código, Cliente, Bolsa global, Saldo ini. sistema, Saldo ini. calc. (desp.), Ajuste retro. (desp.), Anticipos, Consumos despachos, Consumos cortes, Desfase, Saldo fin calc. (desp.), Saldo fin calc. (cortes), Saldo fin sistema, Saldo fin vehículos, Saldo ini. calc. (cortes), Ajuste retro. (cortes), Vigente desde. `tfoot` con el mismo número de celdas.
- [ ] **Step 2:** Modal de ayuda: reemplazar la tabla de columnas por el origen de cada columna nueva (texto tomado del spec: "Reglas de cálculo", "Turnos y hora de corrida", "Fotos congeladas"), y mencionar `TG.dbo.debit_clients_cierre` y la corrida diaria 08:00.
- [ ] **Step 3:** JS: POST `fecha: $('#debit_snapshot_dia').val()`; columnas en el orden del Step 1; `debglo` se muestra "Sí"/"No"; montos con `$.fn.dataTable.render.number(',', '.', 2, '$')` y NULL como `—`; las dos últimas columnas de cortes y "Vigente desde" ocultas (`visible: false`) con botón `colvis` si DataTables Buttons lo tiene cargado en la página, si no, solo ocultas; totales del pie en todas las columnas de monto (ignorando NULL); `json.fecha` y `json.ultima_corrida` en sus etiquetas.
- [ ] **Step 4: Verificar:** `php "$TMP/twigcheck.php" income/clients.html` (o el check de Twig del proyecto) → `OK`; `node -e "require('fs').readFileSync('_assets/js/income.js','utf8')" && node --check _assets/js/income.js` → sin error. Prueba en navegador: la hace el usuario.

---

### Task 6: Despliegue

**Files:**
- Modify: `docs/superpowers/specs/2026-11-11-debit-clients-cierre-diario-design.md` (solo si Task 2 cambió la regla de vehículos — ya hecho ahí)

- [ ] **Step 1:** Listar al usuario los archivos a subir: `_assets/classes/DebitCierre.class.php`, `_assets/models/DebitClientsCierreModel.php`, `_assets/controllers/income.php`, `cron/debit_snapshot_diario.php`, `views/income/clients.html`, `_assets/js/income.js`, `docs/sql/debit_clients_cierre_schema.sql`.
- [ ] **Step 2:** Después de que el usuario confirme que subió los archivos: cambiar el disparador de `sya_debit_snapshot_diario` en 192.168.0.3 a **08:00** (CIM sobre DCOM, `Set-ScheduledTask -Trigger (New-ScheduledTaskTrigger -Daily -At '08:00')`; pedir la contraseña al usuario, no guardarla). Expected: `NextRunTime` = mañana 08:00.
- [ ] **Step 3:** Actualizar la memoria `snapshot-diario-clientes-debito.md` con el estado final.
