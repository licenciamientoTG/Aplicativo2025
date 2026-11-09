# Conciliación débito (prepago) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Nueva sección Ingresos → "Conciliación débito" (12 tabs, una por hoja del Excel de prepago) que reproduce `Conciliacion prepago Diaz Gas septiembre_2026.xlsx` con solo elegir mes y razón social; la actual pasa a llamarse "Conciliación crédito".

**Architecture:** Mismo patrón que crédito: modelo con tablas temporales (SG12/TG), lógica de clasificación en una clase pura con pruebas sin BD, endpoints POST en `Income` bajo el permiso 14, una vista Twig + un JS propio. El CSS `cv-*` y las funciones comunes de crédito se extraen a un parcial y a `conciliation_common.js` para no duplicarlos. El cruce cobros↔banco reutiliza `ConciliacionCobros`.

**Tech Stack:** PHP 8.2 MVC propio, SQL Server (PDO sqlsrv, SG12 en compat. 110), Twig, jQuery + DataTables + Bootstrap 5, pruebas sin framework (`php tests/X.php`, `node tests/X.js`).

**Spec:** `docs/superpowers/specs/2026-11-08-conciliacion-debito-design.md` (leerlo completo: llaves de BD, tabs, estatus, validación).

## Global Constraints

- Permiso **14** en todas las rutas; sin permiso nuevo. Rutas nuevas en `routes/income.php`, URL bajo `conciliation-debit/`, en Twig/JS siempre `url()` / `routeUrl()` (nunca URLs a mano).
- Fechas = enteros de ControlGas (`dateToInt()`; 2026-09-01 = 46265, 2026-09-30 = 46294). Nunca YYYYMMDD ni `+1`.
- `$empresa`: 0 = todas, -1 = sin razón social, >0 = `SG12.Empresas.cod`.
- Serie E = `SG12.DocumentosC` tip 3, `nro` 1300000000–1399999999, razón social por `DocumentosC.codemp`; Serie D (anticipos) = 12…; cancelada = `txtref LIKE '@N:CANCELAD%'`.
- Cobros prepago = tip 4 **subope 19**; cobros de crédito = subope 8. Cortes de débito = `ValesR.codval = 127`.
- Cuentas: BANORTE `0186932791` (tab "Banco 2791") y `0601500956` (tab "Banco 0956").
- Tablas temporales + `OPTION (RECOMPILE)`; SG12 no tiene `STRING_SPLIT` (listas en línea saneadas con `intval` / `%.2f`).
- Los modelos heredan de `Model` (`$this->sql->select($q, $params)`).
- Ids de filtros **idénticos a crédito**: `#cvMes`, `#cvEmpresa`, `#cvFiltros`, `#cvTabs`, `#modalAyuda`.
- Tabs sin "(hoja Excel)" ni "(Control Gas)". Turno = primer dígito de `nrotur`. Facturado **por cliente**; canceladas excluidas y mostradas como partida "Factura cancelada". No ligar por monto. No ocultar facturas raras.
- No levantar el servidor PHP; no editar archivos con PowerShell `Set-Content` (mojibake: usar Edit/Write). Todo texto de UI en español. **No hacer commit**: el usuario sube los cambios.

## Review Focus

Entradas que el spec implica y ninguna tarea de datos ejercita sola (cada una tiene su prueba en la tarea indicada):

1. Factura Serie E sin nota "Consumos <Mes> <año>" (periodo no legible) → no truena; cuenta como "Sin periodo" = del mes (Task 2).
2. Cruce de año: factura "Consumos Diciembre 2026" emitida en enero 2027 → "Mes anterior"; y la de enero emitida en febrero con periodo enero → "Mes siguiente" (Task 2).
3. Mes o razón social sin Serie E / sin cobros → tablas vacías y "% cuadrado" sin división entre cero; el Resumen no truena (Task 6).
4. Cobro cuya Referencia no trae factura, o trae una de otra serie → "Sin factura en referencia", nunca factura inventada (Task 2).
5. Cliente con facturado pero sin consumo (monto 0) o solo con cortes → estatus coherente, sin NaN (Task 2); nombres de cliente con `<`/`&` → siempre `esc()` en la vista (Task 3).

---

### Task 1: Extraer CSS y JS comunes de crédito + renombrar sidebar

**Files:**
- Create: `views/income/_conciliation_styles.html` (todo el `<style>` de `conciliation.html` líneas 6-133, sin cambios)
- Create: `_assets/js/conciliation_common.js`
- Create: `tests/conciliation_common_test.js`
- Modify: `views/income/conciliation.html` (usar parcial y `Conc`), `views/layouts/sidebar.html:500`

**Interfaces:**
- Produces (`window.Conc`, también `module.exports` para node): `fmtMonto(n)`, `fmtLitros(n)`, `fmtFecha(iso)`, `esc(s)`, `suma(filas, campo, filtro?)`, `mesTexto(valorMes: 'Y-m') → 'septiembre 2026'`, renderers `monto/montoDif/montoSigno/litros/badge/fecha/texto/textoLineas(d, type)`, `ESTATUS_CLASE` (objeto extensible: `Conc.ESTATUS_CLASE['X'] = 'cv-ok'`), `initTabla(sel, columnas, titulo, extra)`, `pintarChips(contenedor, api, filas, campoEstatus, colEstatus, campoMonto, campoPend)`, `bindAyuda(AYUDA)`.
  `initTabla` y `mesTexto` leen `#cvMes` / `#cvEmpresa` (mismo comportamiento que hoy).
- Consumes: nada.

- [ ] **Step 1: Write the failing test** `tests/conciliation_common_test.js` (`node tests/conciliation_common_test.js`), usando `node:assert/strict` y `require('../_assets/js/conciliation_common.js')`:
  `fmtMonto(1234.5) === '1,234.50'`, `fmtMonto(null) === '0.00'`, `fmtLitros(2) === '2.000'`, `fmtFecha('2026-09-05') === '05/09/2026'`, `esc('<a&"x">') === '&lt;a&amp;&quot;x&quot;&gt;'`, `suma([{m:1},{m:2.5},{m:'x'}],'m') === 3.5`, `suma(filas,'m', r=>r.m>1) === 2.5`, `mesTexto('2026-09') === 'septiembre 2026'`, `mesTexto('') === ''`, `badge('Conciliado','display')` contiene `cv-ok`, `badge('Algo raro','display')` contiene `cv-muted`, `monto(null,'display') === ''`.
- [ ] **Step 2:** Run `node tests/conciliation_common_test.js` → FAIL (módulo no existe).
- [ ] **Step 3:** Crear `_assets/js/conciliation_common.js` moviendo desde `conciliation.html` las funciones de las líneas 595-686 y 688-714 (`fmt*`, `esc`, `suma`, renderers, `ESTATUS_CLASE`, `mesTexto`, `initTabla`, `pintarChips`) y el handler de ayuda (líneas 563-570) como `bindAyuda(AYUDA)`. Envolver en IIFE que asigna `window.Conc` y hace `module.exports` si existe `module`. `mesTexto` pasa a recibir el valor del mes (los llamadores pasan `$('#cvMes').val()`); dentro de `initTabla` el título del Excel usa `Conc.mesTexto($('#cvMes').val())`. Código que dependa de jQuery/DataTables solo se toca al llamarse (el módulo se carga en node sin jQuery).
- [ ] **Step 4:** Run `node tests/conciliation_common_test.js` → PASS.
- [ ] **Step 5:** `conciliation.html`: reemplazar el `<style>` por `{% include "views/income/_conciliation_styles.html" %}` dentro de `{% block mycss %}`; en `{% block myjs %}` cargar `<script src="{{ js_v('conciliation_common.js') }}"></script>` antes del script inline y sustituir las definiciones movidas por alias locales (`var fmtMonto = Conc.fmtMonto, …`, `var initTabla = Conc.initTabla`, etc.; `mesTexto = function(){ return Conc.mesTexto($('#cvMes').val()); }`). Sin cambiar ninguna otra lógica. Si `{% include %}` con esa ruta no resuelve en el loader de Twig, usar la misma forma de ruta que `{% extends "views/layouts/base.html" %}`.
- [ ] **Step 6:** `views/layouts/sidebar.html:500`: texto del enlace `Conciliación` → `Conciliación crédito`; no agregar aún el de débito.
- [ ] **Step 7:** Verificar: `php -l` no aplica a html; correr `php tests/LayoutTest.php`, `php tools/routes.php lint`, `node tests/route_url_test.js` (siguen verdes). **Pedir al usuario** abrir `/income/conciliation`, buscar agosto 2026 y confirmar que se ve y calcula igual (Resumen, Desglose venta, Excel, ayuda `?`, progreso n/6). No avanzar a Task 3 sin ese visto bueno (Tasks 2 puede hacerse mientras).

---

### Task 2: Lógica pura `ConciliacionDebito` + pruebas

**Files:**
- Create: `_assets/classes/ConciliacionDebito.class.php`
- Test: `tests/ConciliacionDebitoTest.php` (`require __DIR__.'/support.php'` y la clase; termina con `finish()`)

**Interfaces (todas `public static`, sin BD):**
- `periodoFactura(string $txtref, string $mes, string $fechaFactura): string` — `$mes` = `'Y-m'` solicitado, `$fechaFactura` = `'Y-m-d'`. Lee del `txtref` la nota `@N:Consumos <Mes en español> [Diesel] <año>` (mes sin importar mayúsculas/acentos). Devuelve exactamente: `'Del mes'` (periodo = $mes y factura dentro de $mes), `'Mes siguiente'` (periodo = $mes y factura posterior a $mes), `'Mes anterior'` (periodo = mes previo a $mes y factura dentro de $mes), `'Sin periodo'` (nota ilegible/ausente o periodo ajeno a los anteriores; cuenta como del mes). La cancelación **no** se decide aquí.
- `facturasDeReferencia(string $referencia): array` — de `Fac.D242940 ($3,500.00); Fac.E101872 ($26,389.99)` devuelve `[['nro'=>1200242940,'importe'=>3500.0],['nro'=>1300101872,'importe'=>26389.99]]`: serie D → 1200000000 + dígitos, serie E → 1300000000 + dígitos; otras letras o sin dígitos se omiten.
- `estatusCliente(array $c): string` — `$c` con `clase` ('Venta'|'Consumo propio'|'Bonificación'), `monto`, `cortes`, `facturado_mes` (vigentes Del mes + Sin periodo), `facturado_siguiente`, `cancelada` (floats). `dif = monto − facturado_mes`. Se evalúa en este orden y devuelve el primero que aplique: `'No se factura'` (clase ≠ Venta) · `'Facturado'` (|dif|<0.01) · `'Redondeo'` (|dif|<1) · `'Se factura al mes siguiente'` (dif>0 y |dif − facturado_siguiente|<1) · `'Factura cancelada'` (cancelada>0 y dif≥1) · `'Facturado con base en cortes'` (|facturado_mes − cortes|<0.01, facturado_mes>0 y |monto−cortes|≥0.01) · `'Consumo sin corte'` (cortes=0 y facturado_mes=0) · `'Consumo sin factura'` (facturado_mes=0) · `'Facturado de menos'` (dif>0) · `'Facturado de más'`.
- `clasificarMovimiento(array $mov, array $idsPrepago, array $idsCredito, array $cuentasPropias): string` — `$mov` con `id`, `abono`, `cargo`, `detalle`, `contraparte`, `rfc`. Orden: `'Retiro'` (abono ≤ 0) · `'Cobro prepago'` (id en $idsPrepago) · `'Cobro crédito'` (id en $idsCredito) · `'Traspaso entre cuentas'` (alguna cuenta de $cuentasPropias, de ≥6 dígitos, aparece en detalle o contraparte) · `'No identificado'`.

- [ ] **Step 1: Write the failing test** `tests/ConciliacionDebitoTest.php` con `check()` para: `periodoFactura('@N:Consumos Agosto 2026@t:GLOBAL','2026-09','2026-09-02') === 'Mes anterior'`; `…'Consumos Septiembre 2026'…'2026-09','2026-09-30' === 'Del mes'`; mismo periodo con fecha `'2026-10-03'` → `'Mes siguiente'`; `'@N:Consumos Agosto  Diesel 2026@t:GLOBAL@c:7767'` con mes 2026-09 → `'Mes anterior'`; texto sin nota → `'Sin periodo'`; `'@N:Consumos Diciembre 2026'` con `'2027-01'`, `'2027-01-04'` → `'Mes anterior'`; `'@N:Consumos Enero 2027'` con `'2027-01'`, `'2027-02-02'` → `'Mes siguiente'`; `'@N:Consumos Julio 2026'` con 2026-09 → `'Sin periodo'`. `facturasDeReferencia` con el ejemplo de arriba; con `'Sin factura'` → `[]`; con `'Fac.G55 ($10.00)'` → `[]`. `estatusCliente`: una prueba por cada estatus (ejemplos de septiembre: monto 10536.85+X facturado=cortes → base en cortes; monto 6644.18, cortes 0, facturado 0 → sin corte; monto 20471.30, cortes 20471.30, facturado 0 → sin factura; monto 100.00 facturado 99.60 → Redondeo; monto 100, facturado 0, cortes 100, y `clase` 'Consumo propio' → 'No se factura'; monto 0, facturado 500 → 'Facturado de más' (sin NaN); monto 0, cortes 300, facturado 0 → 'Consumo sin factura'; cancelada 33000, facturado 0, monto 33000, cortes 33000 → 'Factura cancelada'). `clasificarMovimiento`: un caso por cada salida, incluida cuenta propia `'0601500947'` dentro de `detalle` → traspaso, y una cuenta de 4 dígitos no cuenta.
- [ ] **Step 2:** Run `php tests/ConciliacionDebitoTest.php` → FAIL.
- [ ] **Step 3:** Implementar la clase con las firmas anteriores (constantes `const` públicas para los textos de estatus, reutilizadas por la vista en Task 3). Mapa de meses en español sin acentos; comparar con `mb_strtolower` + `strtr` de acentos.
- [ ] **Step 4:** Run `php tests/ConciliacionDebitoTest.php` → todo `ok`, exit 0.

---

### Task 3: Tabs 2-6 — Desglose venta, Consumos por cliente, Consumos propios, Facturas Serie E, Despachos pendientes (+ página y sidebar)

**Files:**
- Create: `_assets/models/ConciliacionDebitoModel.php`, `views/income/conciliation_debit.html`, `_assets/js/conciliation_debit.js`, `tests/ConciliacionDebitoViewTest.php`
- Modify: `_assets/controllers/income.php` (después de `conciliation_own_consumption`, ~línea 580), `routes/income.php`, `views/layouts/sidebar.html` (agregar `<li>` "Conciliación débito" tras el de crédito, `url('income.conciliation_debit')`)

**Interfaces:**
- Produces (modelo, `class ConciliacionDebitoModel extends Model`):
  - `facturas_serie_e(int $from, int $until, int $empresa): array` — filas: `codgas, nro, fch, fecha('Y-m-d'), codcli(DocumentosC.codopr), cliente(Clientes.den), subope, total (SUM(Documentos.mto)/100), cancelada(0|1), satuid, txtref(nvarchar 500), empresa(DocumentosC.codemp)`. Trae `fch BETWEEN $from AND $until+10` (el controlador descarta las posteriores a `$until` que no sean "Mes siguiente"). Filtro de razón social sobre `DocumentosC.codemp` con `Empresas` (misma expresión que `EMP_FILTER` de crédito).
  - `despachos_pendientes(int $from, int $until, int $empresa): array` — despachos de clientes `tipval = 4` con `ISNULL(nrofac,0) = 0` y `mto <> 0`: `fecha, nrotrn, estacion(Gasolineras.abr), producto, litros, monto, codcli, cliente, categoria(clientes_consumo_propio)`. Mismos filtros que `ClientesModel::get_dispatches_summary_by_client` (usar `pagoBancarioFilter`/`consumoPropioFilter` o replicar su condición); producto como en `ConciliacionVentaModel::despachos_cliente`.
  - Reutiliza `ClientesModel::get_dispatches_summary_by_client($from,$until,4,0,$empresa,'incluir')` y `get_dispatches_by_client($from,$until,4,0,$empresa,'solo')` para consumos y propios.
- Produces (controlador `Income`, todos POST, `set_time_limit(300)`, usan `conciliationRange()`/`conciliationEmpresa()` existentes, responden `json_output(['data'=>…])`): `conciliation_debit()` (GET, render con `mes` del mes anterior y `empresas`), `conciliation_debit_sales()` (una fila por cliente: `codcli, cliente, clase, despachos, litros, monto, cortes, dif_cortes, facturado_mes, facturado_mes_anterior, facturado_siguiente, cancelada, facturas, diferencia, estatus`), `conciliation_debit_consumption()`, `conciliation_debit_own()`, `conciliation_debit_invoices()` (fila por factura con `periodo` ∈ Del mes/Mes anterior/Mes siguiente/Sin periodo/Cancelada, `total`, `cliente`, `cfdi` Timbrada|Sin timbrar, `referencia`), `conciliation_debit_pending()`.
- Rutas (`routes/income.php`, permiso 14, junto a las de crédito): `income.conciliation_debit` GET `conciliation-debit/`; POST `income.conciliation_debit_sales` `conciliation-debit/sales/`, `…_consumption` `consumption/`, `…_own` `own-consumption/`, `…_invoices` `invoices/`, `…_pending` `pending-dispatches/`.

- [ ] **Step 1: Write the failing test** `tests/ConciliacionDebitoViewTest.php` (lectura de archivos, como `LayoutTest.php`): para cada `routeUrl('income.conciliation_debit…')` presente en `_assets/js/conciliation_debit.js` existe un `name: 'income.conciliation_debit…'` en `routes/income.php`; `conciliation_debit.html` contiene los ids `cvMes`, `cvEmpresa`, `cvFiltros`, `cvTabs`, `modalAyuda`, `tablaVenta`, `tablaApp`, `tablaPropio`, `tablaFacturas`, `tablaPendientes`; el sidebar contiene `income.conciliation_debit`; todas las rutas `conciliation-debit/` declaran `permission: 14`.
- [ ] **Step 2:** Run `php tests/ConciliacionDebitoViewTest.php` → FAIL.
- [ ] **Step 3:** Modelo `ConciliacionDebitoModel`: `facturas_serie_e` y `despachos_pendientes` con tablas temporales y `OPTION (RECOMPILE)` (patrón de `ConciliacionVentaModel::facturas`).
- [ ] **Step 4:** Controlador y rutas. `conciliation_debit_sales` une en PHP: consumos (summary), facturas Serie E agrupadas por `codcli` según `periodoFactura(txtref, mes, fecha)` y `cancelada` (cancelada → suma a `cancelada` y no a `facturado_*`; "Mes anterior" → `facturado_mes_anterior`; "Mes siguiente" → `facturado_siguiente`; Del mes/Sin periodo → `facturado_mes`), `clase` desde `Categoria` ('' → Venta; categoría que contenga "BONIFICACION" → Bonificación; otra → Consumo propio, igual que crédito), `estatus = ConciliacionDebito::estatusCliente()`. Clientes que solo aparecen en facturas se incluyen con monto 0. `conciliation_debit_invoices` descarta las de `fecha > hasta` que no sean "Mes siguiente".
- [ ] **Step 5:** Vista `conciliation_debit.html` (`{% extends "views/layouts/base.html" %}`, `{% include "views/income/_conciliation_styles.html" %}`, JS `conciliation_common.js` + `conciliation_debit.js` vía `js_v`): sidebar colapsado, card de filtros (mismos ids), insignia de progreso, `#cvTabs` con los 12 tabs (los de Tasks 4-6 como paneles vacíos con su contador), modal `#modalAyuda`. Paneles reales de este task: Resumen (solo contenedor vacío; lo llena Task 6), Desglose venta (card colapsable "Resumen por clasificación" + `#chipsVenta` + tabla + `tfoot`), Consumos por cliente, Consumos propios, Facturas de consumos (Serie E) (card "Resumen de facturas por periodo" + chips), Despachos pendientes de facturar. Copiar la estructura de los tabs equivalentes de crédito.
- [ ] **Step 6:** `conciliation_debit.js`: `Conc.bindAyuda(AYUDA)` con textos "¿Qué estoy viendo?" por tab (de dónde viene cada dato / cómo interpretarlo, estilo de crédito); `CONSULTAS`, progreso, `buscar()` y `consultar()` con el mismo patrón que `conciliation.html:1049-1160`; `Conc.ESTATUS_CLASE` ampliado con los estatus de `ConciliacionDebito` (`Facturado`→cv-ok, `Redondeo`→cv-ok, `Se factura al mes siguiente`→cv-info, `Factura cancelada`→cv-bad, `Facturado con base en cortes`→cv-info, `Consumo sin corte`/`Consumo sin factura`→cv-bad, `Facturado de menos`→cv-warn, `Facturado de más`→cv-warn, `Mes anterior`/`Mes siguiente`→cv-info, `Sin periodo`→cv-muted). Todo texto de BD pasa por `esc()` (renderer `texto`). Clic en un cliente de Desglose venta abre el modal de despachos vs cortes **solo si** crédito ya expone endpoints reutilizables; si no, queda como mejora (el modal no bloquea esta entrega).
- [ ] **Step 7:** Run `php tests/ConciliacionDebitoViewTest.php`, `php tests/ConciliacionDebitoTest.php`, `php tools/routes.php lint`, `php -l` de los .php tocados → verdes.
- [ ] **Step 8:** Verificar datos reales: crear `tools/conciliacion_debito_validar.php` (arranque igual que `tools/check_string_agg.php`) con la función `validar_ventas()` que, para septiembre 2026 y empresa 1, imprime y compara con tolerancia 0.01: venta 31,932,212.69; cortes 31,918,014.67; 774 clientes; facturas Serie E vigentes 759 / 31,201,737.84; canceladas 24 / 786,358.92; facturas "Mes anterior" 185,444.95. Run `php tools/conciliacion_debito_validar.php` → todo `ok`.

---

### Task 4: Tabs 7-9 — Anticipos, Anticipos por cliente, Cobros por factura

**Files:**
- Modify: `_assets/models/ConciliacionDebitoModel.php`, `_assets/controllers/income.php`, `routes/income.php`, `views/income/conciliation_debit.html`, `_assets/js/conciliation_debit.js`, `_assets/models/ConciliacionVentaModel.php`, `tools/conciliacion_debito_validar.php`, `tests/ConciliacionDebitoViewTest.php`

**Interfaces:**
- Consumes: `ConciliacionDebito::facturasDeReferencia(string): array`.
- Produces:
  - `ConciliacionVentaModel::SUBOPE_COBRO_DEBITO = 19` y `cobros(int $from, int $until, int $subope = self::SUBOPE_COBRANZA): array` (parámetro opcional al final; crédito no cambia).
  - `ConciliacionDebitoModel::anticipos(int $from, int $until, int $empresa): array` — envuelve `ClientesModel::get_debit_anticipos_report` y agrega `cobro_aplicado` (suma de `DocumentosA.mtoapl/100` con `nroapl` = factura, tip 4) y `estatus` ∈ `Con cobro` | `Sin cobro` (aplicado < total − 0.01). Además `anticipos_cancelados(…): array` (DocumentosC tip 3, subope 18, nro 12…, cancelada, en rango, sin importe: las canceladas no conservan líneas en `Documentos`) con `nro, fecha, cliente`.
  - Endpoints POST: `conciliation_debit_anticipos()` (`income.conciliation_debit_anticipos`, ruta `anticipos/`; devuelve anticipos vigentes + canceladas con estatus `Cancelada` e importe vacío), `conciliation_debit_anticipos_clients()` (`…_anticipos_clients`, `anticipos-clients/`: `codcli, cliente, monto, saldo` con `saldo = Clientes.debsdo`), `conciliation_debit_payment_invoices()` (`…_payment_invoices`, `payments-invoices/`: por cobro prepago y por factura de su Referencia/`DocumentosA`: `cobro, fecha, cliente, factura, importe, existe_en_anticipos` Sí|No|`Sin factura en referencia`).

- [ ] **Step 1: Write the failing test:** ampliar `ConciliacionDebitoViewTest.php` con los ids `tablaAnticipos`, `tablaAnticiposCli`, `tablaCobrosFactura` y las tres rutas nuevas con permiso 14. Run → FAIL.
- [ ] **Step 2:** Modelo, controlador, rutas. Un cobro con varias facturas genera una fila por factura; un cobro sin facturas parseables ni `DocumentosA` genera una fila con `existe_en_anticipos = 'Sin factura en referencia'` e importe = monto del cobro. `existe_en_anticipos` se calcula contra los anticipos del mes (`nro` en el conjunto de `anticipos()`).
- [ ] **Step 3:** Vista y JS: tres paneles con chips por estatus (Con cobro/Sin cobro/Cancelada; Sí/No/Sin factura en referencia), `tfoot` con totales, ayuda `?`, contadores y entradas en `CONSULTAS`.
- [ ] **Step 4:** Run `php tests/ConciliacionDebitoViewTest.php`, `php tools/routes.php lint`, `php tests/ConciliacionCobrosTest.php` (crédito intacto), `php -l`.
- [ ] **Step 5:** Ampliar `validar_anticipos()` en `tools/conciliacion_debito_validar.php` (sept-2026, empresa 1): anticipos 1,871 / 32,591,067.05; cobros prepago 1,861 / 32,605,435.05; cobros − anticipos = 14,368.00; partidas del card B: cobros aplicados a anticipos de otro mes 17,868.00 (`1200242940` 3,500 + `1200242934` 6,000 + `1200089958` 8,368), cobros sin factura en referencia 10,000.00, anticipos sin cobro −13,500.00 (`1200244867` 3,500 + `1200244401` 10,000) → sin explicar 0.00; anticipos cancelados del mes = 2 (`1200243922`, `1200244351`). Run → todo `ok`.

---

### Task 5: Tabs 10-12 — Conciliación cobros, Banco 2791, Banco 0956

**Files:**
- Modify: `_assets/models/ConciliacionVentaModel.php`, `_assets/models/ConciliacionDebitoModel.php`, `_assets/controllers/income.php`, `routes/income.php`, `views/income/conciliation_debit.html`, `_assets/js/conciliation_debit.js`, `tools/conciliacion_debito_validar.php`, `tests/ConciliacionDebitoViewTest.php`

**Interfaces:**
- Consumes: `ConciliacionVentaModel::cobros($from,$until,19)`, `ConciliacionCobros::conciliar(array $cobros, array $depositos): array`, `ConciliacionDebito::clasificarMovimiento(...)`.
- Produces:
  - `ConciliacionVentaModel::depositos(array $movs, array $montos, string $desde, string $hasta, string $desdeImporte, string $hastaImporte, array $cuentas = []): array` — parámetro nuevo opcional; si no vacío agrega `AND cuenta IN (…)` (saneado). Crédito no cambia.
  - `ConciliacionDebitoModel::cuentas_propias(): array` — números de cuenta propios desde `TG.dbo.CatalogosCuentasBancarias` (`TipoCuenta='Propia'`); **descubrir primero** las columnas reales con `SELECT TOP 5 *` y anotar el hallazgo en el spec §4.
  - Endpoints POST: `conciliation_debit_payments()` (`income.conciliation_debit_payments`, `payments/`; misma forma de fila que `conciliation_payments` de crédito más el estatus extra `Efectivo` (forma de pago 01), `Depositado en mes anterior` (depósito con fecha anterior al mes) y `Consumo propio` (cliente del catálogo `clientes_consumo_propio`, p. ej. Arqueo D2GO / "dollar to go"); filtra por razón social como crédito; el cruce usa solo depósitos de las cuentas 0186932791 y 0601500956) y `conciliation_debit_bank()` (`…_bank`, `bank/`; POST `cuenta` ∈ {`0186932791`,`0601500956`}: movimientos del mes de esa cuenta con `clasificacion` calculada con `clasificarMovimiento`, usando como `$idsPrepago` los `deposito_id` del cruce de cobros prepago, como `$idsCredito` los del cruce de cobros de crédito (subope 8, mismo procedimiento) y `cuentas_propias()`).
  - Helper privado en `Income`: `conciliationCruce(array $cobros, string $desde, string $hasta, array $cuentas = []): array` (arma `mov` con `movDeTxtref`, trae depósitos con la ventana `DIAS_*` y llama `ConciliacionCobros::conciliar`) — solo lo usa débito; **no** refactorizar `conciliation_payments`.

- [ ] **Step 1: Write the failing test:** ampliar `ConciliacionDebitoViewTest.php`: ids `tablaCruce`, `tablaBanco2791`, `tablaBanco0956`, rutas `payments/` y `bank/` con permiso 14. Run → FAIL.
- [ ] **Step 2:** Descubrir `CatalogosCuentasBancarias` (SELECT) y actualizar el spec §4.
- [ ] **Step 3:** Modelo/controlador/rutas. Un cobro cancelado no entra al cruce (como crédito: estatus `Cancelado`).
- [ ] **Step 4:** Vista y JS: tab Conciliación cobros copiando el de crédito (bandas verde "Cobro" / azul "Movimiento bancario", fila roja `cv-fila-sinmov`, chips, card "Resumen de cobros por cuenta / origen" con filas Cuenta 2791, 0956, Efectivo, Depositado en mes anterior, Consumo propio, No localizado); tabs de banco con chips por clasificación y `tfoot` de abonos/cargos. Carga de banco **perezosa** (solo al abrir el tab o al terminar el cruce) para no repetir el cruce dos veces sin necesidad.
- [ ] **Step 5:** Run `php tests/ConciliacionDebitoViewTest.php`, `php tests/ConciliacionCobrosTest.php`, `php tools/routes.php lint`, `php -l`.
- [ ] **Step 6:** Ampliar `validar_cobros()` (sept-2026, empresa 1): depósitos 2791 = 1,925 abonos / 37,229,115.24; movimientos 0956 = 2,329; cobros prepago ligados a 2791 por el cruce ≈ 32,007,985 (tolerancia $50.00 contra Excel 32,007,985.13) y % cuadrado ≥ 97 %; imprimir los cobros sin banco con su importe para revisión del usuario. Run → todo `ok` (si el % cuadrado queda bajo, **reportarlo, no ajustar las reglas de `ConciliacionCobros` sin acuerdo**).

---

### Task 6: Tab Resumen (3 cards) + ayudas

**Files:**
- Modify: `views/income/conciliation_debit.html`, `_assets/js/conciliation_debit.js`, `tests/ConciliacionDebitoViewTest.php`

**Interfaces:**
- Consumes: arrays `estado.venta`, `estado.facturas`, `estado.anticipos`, `estado.cobrosFactura`, `estado.cobros` ya cargados por Tasks 3-5.
- Produces: `pintarResumen()` (se dispara cuando terminan ventas, facturas, anticipos, cobros por factura y cobros; igual mecanismo que `progresoMarca` de crédito) con los tres cuadros del spec §6, en `<table class="cv-cascada">`, reutilizando `fila()`/`abrirTab()`/`irA()` (copiarlos de `conciliation.html:1276-1295`):
  - **A** Venta vs facturación: venta total · (−) consumo propio · (−) bonificaciones · **venta neta** · facturado Serie E vigente · (−) facturas de mes anterior emitidas en el mes · **facturado consumos del mes** · diferencia · partidas = una fila `cv-link` por estatus (conteo + importe de `dif`, salto a Desglose venta filtrado) excepto Facturado/Redondeo/No se factura · **Diferencia sin explicar** + insignia Conciliado/Revisar · nota Monto − Cortes.
  - **B** Cobros vs anticipos: cobros prepago · anticipos facturados · diferencia · partidas (cobros aplicados a anticipos de otro mes, cobros sin factura en la referencia, anticipos sin cobro aplicado, cada una con enlace a su tab) · sin explicar · nota "Anticipos cancelados del mes: N" (sin importe).
  - **C** Cobranza vs banco: cobros agrupados por estatus (como `resCobros` de crédito) · "% cuadrado" (0 % si no hay cobros, sin división entre cero) · integración de depósitos 2791 (depósitos totales − aplicados a prepago − aplicados a crédito − traspasos = no identificados), calculada desde el tab Banco 2791.

- [ ] **Step 1: Write the failing test:** ampliar `ConciliacionDebitoViewTest.php`: `conciliation_debit.html` contiene `resVenta`, `resAnticipos`, `resCobros`, `resDepositos`; `conciliation_debit.js` define `pintarResumen` y cada clave de ayuda (`filtros`, `resumen`, `res_venta`, `res_anticipos`, `res_cobros`, `venta`, `app`, `propio`, `facturas`, `pendientes`, `anticipos`, `anticipos_cli`, `cobros_factura`, `cruce`, `banco2791`, `banco0956`) aparece en `AYUDA` y en algún `data-ayuda` de la vista. Run → FAIL.
- [ ] **Step 2:** Implementar `pintarResumen()` y completar los textos de `AYUDA` (en español, "de dónde viene cada dato" y "cómo interpretarlo"; mencionar que las facturas canceladas se muestran como partida).
- [ ] **Step 3:** Con datos vacíos (`estado.*=[]`) `pintarResumen()` no lanza (revisar a mano cada división y `reduce`); mes sin datos muestra los cuadros en cero con insignia "Conciliado".
- [ ] **Step 4:** Run `php tests/ConciliacionDebitoViewTest.php` → verde.

---

### Task 7: Validación final, prueba en navegador y cierre

**Files:**
- Modify: `tools/conciliacion_debito_validar.php` (función `main()` que corre `validar_ventas/anticipos/cobros` y sale con código 1 si algo falla), `docs/superpowers/specs/2026-11-08-conciliacion-debito-design.md` (§7 con los resultados reales)

- [ ] **Step 1:** Run `php tools/conciliacion_debito_validar.php` → todo `ok`; pegar el resumen de cifras en el spec §7 (valores del sistema vs Excel y la diferencia explicada por cancelaciones).
- [ ] **Step 2:** Run todas las pruebas: `php tests/ConciliacionDebitoTest.php`, `php tests/ConciliacionDebitoViewTest.php`, `php tests/ConciliacionCobrosTest.php`, `php tests/LayoutTest.php`, `php tests/RouterTest.php`, `php tools/routes.php lint`, `node tests/conciliation_common_test.js`, `node tests/route_url_test.js` → verdes.
- [ ] **Step 3:** Pedir al usuario la prueba en navegador de `/income/conciliation-debit/` (septiembre 2026, DIAZ GAS): cifras del Resumen vs Excel, chips, Excel exportado, ayudas, sidebar con "Conciliación crédito" y "Conciliación débito"; y de nuevo `/income/conciliation` (agosto) sin cambios. Corregir lo que reporte.
- [ ] **Step 4:** Al aprobar: listar los archivos tocados (el usuario hace commit/deploy), guardar memoria del proyecto (`conciliacion-venta-debito.md` + línea en `MEMORY.md`, enlazada a [[conciliacion-venta-credito-mensual]]) y actualizar la sección de avance en Notion "Totalgas".
