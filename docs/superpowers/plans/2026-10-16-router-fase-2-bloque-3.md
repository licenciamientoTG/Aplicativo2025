# Router — Fase 2, bloque 3: `direction`

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rutas explícitas con alias para los 31 métodos de `direction` que usan el menú, las vistas y sus 5 archivos JS, y enlaces por nombre, sin que ninguna URL actual deje de funcionar.

**Architecture:** Igual que los bloques 1 y 2: `routes/direction.php` + filas en `tests/RoutesFase2Test.php` (`check_routes()`) + `php tools/routes.php lint`.

**Tech Stack:** PHP 8.4, Twig 3, jQuery/DataTables y `fetch`.

**Spec:** `docs/superpowers/specs/2026-10-16-router-migracion-design.md` — D1, D8–D14 y la lista de patrones de inventario de la sección 7.

## Global Constraints

- `ROUTER_LEGACY_FALLBACK` sigue en `true`. D8 acción + kebab-case (erratas conservadas: `shortge`, `comsumption`, `cumsumption`) · D9 sin `permission:` · D10 `direction.metodo` (`direction.SaveHoursShortage`, `direction.Vta_vs_meta_canvas`) · D11 · D12 · D13 · D14.
- Todas las URLs cambian (todas llevan `_` o mayúsculas) → **cada ruta lleva alias**.
- Un commit por tarea, `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Hallazgos que el plan ya resuelve

- **Formularios que hacen POST a su propia URL** (caso `mqg_cards`): `historic_prices.html` (`method="post"` a `historic_prices`, que lee `$_POST`) y `tg6/consumption_customer_credit.html` y `tg6/consumption_debit.html` (ambos POST a `consumption_customer_credit`) → esas dos rutas aceptan **GET y POST**.
- **URL armada con globales:** `historic_prices.html:24` usa `action="{{APP_NAME ~ CONTROLLER}}/historic_prices"` → pasa a `url('direction.historic_prices')`.
- **URL relativa:** `graph_prices.html:26` usa `<form action="graph_prices" method="get">` → pasa a `url('direction.graph_prices')`. (Con la URL nueva `/direction/graph-prices`, la relativa seguiría cayendo en el alias y ensuciaría `legacy-hits` para siempre.)
- `daily_summary_shortge($id_producto)`: el menú enlaza `/1`, `/2`, `/3`; el controlador no castea el valor (lo pasa a la vista) → `<str:id_producto>` (D13).
- `excel_tg6` y `download_format` se piden con `fetch` GET; las otras 26 llamadas son POST.
- **Pantallas sin ningún enlace** (ni menú, ni vistas, ni JS): `graph_historic_prices($Id_plaza)`, `consumption_account_credit`, `consumption_debit` → **sin ruta** (D11). Siguen abiertas por el fallback hasta la Fase 5; si alguien las usa por favorito, aparecerán en `legacy-hits` como `fallback` antes de apagarlo.
- **Sin ruta, funciones internas o sin uso:** `get_months_list`, `generate_columns_and_pivot_clause`, `getMonthsArray`, `generateDaysString`, `historic_price_table_pivot`.
- Las rutas de archivos JS en las vistas (`js_v('/direction/tg6.js')`) no son URLs de rutas y no se tocan.

## Review Focus

1. Formulario que hace POST a una ruta solo GET → 405. Cubierto con las filas GET+POST de `historic_prices` y `consumption_customer_credit`; la Tarea 1 revisa los 5 `<form>` de las vistas.
2. El verbo de cada una de las 28 llamadas JS coincide con su ruta (los DataTables declaran `method` lejos de `url`). → Tarea 1, misma verificación automática que el bloque 2.
3. Las vistas que cargan `direction.js`, `direction/tg6.js`, `direction/shortage.js` y `direction/graph_historic_prices*.js` extienden `base.html` (si no, `routeUrl` no existe). → Tarea 1.
4. `daily_summary_shortge` con un id que no sea 1/2/3 o con letras sigue llegando al método (str). → fila con `ABC`.
5. URL vieja con mayúsculas o barra final sigue funcionando por alias. → filas `Direction/TG6/` y `direction/SaveHoursShortage`.

---

### Task 1: `direction`

**Files:**
- Create: `routes/direction.php`
- Modify: `tests/RoutesFase2Test.php`, `views/layouts/sidebar.html` (14 enlaces), `views/direction/historic_prices.html:24`, `views/direction/graph_prices.html:26`, `views/direction/tg6/consumption_customer_credit.html:8`, `views/direction/tg6/consumption_debit.html:8`, `_assets/js/direction.js` (9 llamadas), `_assets/js/direction/tg6.js` (10), `_assets/js/direction/shortage.js` (2), `_assets/js/direction/graph_historic_prices.js` (5), `_assets/js/direction/graph_historic_prices_echarts.js` (2)

- [ ] **Step 1: Filas (prueba que falla)** — handler `Direction`, alias = URL vieja (`direction/<metodo>`):

| nombre | verbos | URL nueva | args / reverse |
|---|---|---|---|
| `direction.tg6` | GET | `direction/tg6` (sin `_`: **sin alias**) | — |
| `direction.consult_consumption` | GET | `direction/consult-consumption` | — |
| `direction.tg6_product` | GET | `direction/tg6-product` | — |
| `direction.valeras` | GET | `direction/valeras` (**sin alias**) | — |
| `direction.monthly_dollar_sales_report` | GET | `direction/monthly-dollar-sales-report` | — |
| `direction.historic_shortage` | GET | `direction/historic-shortage` | — |
| `direction.monthly_summary_shortge` | GET | `direction/monthly-summary-shortge` | — |
| `direction.daily_summary_shortge` | GET | `direction/daily-summary-shortge/2` | `['2']` / `['id_producto'=>'2']`; prueba extra con `ABC` |
| `direction.graph_prices` | GET | `direction/graph-prices` | — |
| `direction.historic_prices` | GET, POST | `direction/historic-prices` | — |
| `direction.consumption_customer_credit` | GET, POST | `direction/consumption-customer-credit` | — |
| `direction.monthly_dollar_sales_report_table` | POST | `direction/monthly-dollar-sales-report-table` | — |
| `direction.cumsumption_credit_table` | POST | `direction/cumsumption-credit-table` | — |
| `direction.historic_price_table` | POST | `direction/historic-price-table` | — |
| `direction.download_format` | GET | `direction/download-format` | — |
| `direction.import_file_historic_price` | POST | `direction/import-file-historic-price` | — |
| `direction.import_file_historic_price_horizontal` | POST | `direction/import-file-historic-price-horizontal` | — |
| `direction.historic_shortage_table` | POST | `direction/historic-shortage-table` | — |
| `direction.SaveHoursShortage` | POST | `direction/save-hours-shortage` | — |
| `direction.delete_shortage` | POST | `direction/delete-shortage` | — |
| `direction.credit_debit_product_table` | POST | `direction/credit-debit-product-table` | — |
| `direction.comsumption_credit_client_table` | POST | `direction/comsumption-credit-client-table` | — |
| `direction.comsumption_credit_count_table` | POST | `direction/comsumption-credit-count-table` | — |
| `direction.Vta_vs_meta_canvas` | POST | `direction/vta-vs-meta-canvas` | — |
| `direction.valeras_table` | POST | `direction/valeras-table` | — |
| `direction.excel_tg6` | GET | `direction/excel-tg6` | — |
| `direction.monthly_summary_shortge_table` | POST | `direction/monthly-summary-shortge-table` | — |
| `direction.daily_summary_shortge_table` | POST | `direction/daily-summary-shortge-table` | — |
| `direction.week_graph` | POST | `direction/week-graph` | — |
| `direction.graph_month` | POST | `direction/graph-month` | — |
| `direction.historic_price_table_pivot2` | POST | `direction/historic-price-table-pivot2` | — |

Alias de ejemplo con mayúsculas/barra final en `tg6_product` (`Direction/TG6_Product/`). Run `php tests/RoutesFase2Test.php` → `FALLA` solo en filas de `direction`, sin errores fatales.

- [ ] **Step 2: `routes/direction.php`** — `group('direction/', …)` con la tabla; las 29 URLs que cambian llevan `alias: ['direction/<metodo>']` (`daily_summary_shortge` con `<str:id_producto>` también en el alias).
- [ ] **Step 3: Enlaces**
  - `sidebar.html`: los 14 `href="/direction/…"` → `{{ url('direction.…') }}`; los tres de mermas diarias → `{{ url('direction.daily_summary_shortge', {id_producto: '1'}) }}` (y `'2'`, `'3'`).
  - Vistas: `historic_prices.html:24` y `graph_prices.html:26` → `action="{{ url('direction.historic_prices') }}"` / `url('direction.graph_prices')`; las dos `tg6/consumption_*.html:8` → `url('direction.consumption_customer_credit')`.
  - JS (5 archivos): `url: '/direction/x'` → `url: routeUrl('direction.x')`, `fetch('/direction/x'` → `fetch(routeUrl('direction.x')`.
- [ ] **Step 4: Verificar**
  - `php tests/RoutesFase2Test.php && php tests/RoutesFase1Test.php && php tools/routes.php lint` → `Todo bien`, `Todo bien`, `198 rutas revisadas, sin problemas` (138 previas + 31 rutas + 29 alias).
  - `node --check` de los 5 JS.
  - `grep -rnE "[\"'\`]/direction/[A-Za-z]|action=\"[a-z_]+\"|APP_NAME ~ CONTROLLER" views/direction views/layouts/sidebar.html _assets/js/direction.js _assets/js/direction` → solo las líneas `js_v('/direction/….js')`.
  - Review Focus 2: verbo de cada `routeUrl('direction.x')` vs. su ruta (script del bloque 2) → todo `ok`.
  - Review Focus 3: las vistas que cargan los 5 JS extienden `base.html`.
  - Las vistas tocadas y `sidebar.html` compilan con Twig.
- [ ] **Step 5: Commit** — `feat(router): rutas de direction con alias y enlaces por nombre`.

### Task 2: Cierre del bloque

- [ ] **Step 1: Suite** — misma de los bloques anteriores; ningún `FALLÓ`, lint 0.
- [ ] **Step 2: Spec** — **Estado**: bloque 3 implementado, checklist pendiente.
- [ ] **Step 3: Checklist manual (con sesión)** — desde el menú: TG6 (pestañas, gráfica Vta vs Meta, descarga de Excel), Consulta de consumos, TG6 por producto, Valeras (las 4 tablas), Reporte mensual de ventas en dólares, Histórico de desabasto (agregar horas y eliminar), Resumen mensual de mermas y diario para los productos 1, 2 y 3, Gráfica de precios (filtrar: el formulario GET recarga la misma pantalla con los datos), Precios históricos (enviar el formulario POST), descargar formato de precios; ⚠️ importar precios (normal y horizontal) solo en pruebas. `legacy-hits` sin entradas `direction` inesperadas.
- [ ] **Step 4: Commit** — `docs: estado del bloque 3 de la Fase 2`.
