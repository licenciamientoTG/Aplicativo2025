# Router — Fase 2, bloque 2: `commercial`

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rutas explícitas con alias para los 27 métodos de `commercial` que usan el menú y `commercial.js`, y enlaces por nombre, sin que ninguna URL actual deje de funcionar.

**Architecture:** Igual que el bloque 1: `routes/commercial.php` + filas en `tests/RoutesFase2Test.php` (con `check_routes()`) + `php tools/routes.php lint`.

**Tech Stack:** PHP 8.4, Twig 3, jQuery (DataTables con `ajax.method: 'POST'`) y `fetch`.

**Spec:** `docs/superpowers/specs/2026-10-16-router-migracion-design.md` — D1, D8–D14.

## Global Constraints

- `ROUTER_LEGACY_FALLBACK` sigue en `true`. D8 acción + kebab-case (las erratas de los nombres, `mounth_`, `mistery_`, se conservan: la URL se deriva mecánicamente del método) · D9 sin `permission:` · D10 `commercial.metodo` · D11 solo métodos usados · D12 verbos según el uso real · D13 sin parámetros de URL en este módulo · D14.
- Todas las URLs cambian (todas llevan `_` o camelCase) → **cada ruta lleva alias**.
- Un commit por tarea, `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Hallazgos que el plan ya resuelve

- `mystery_shopper()` está declarado con doble espacio (`function  mystery_shopper`); existe y lleva ruta.
- `sale_lubricants()` lee `$_POST['from']`/`$_POST['until']` aunque ninguna vista le hace POST hoy → `['GET', 'POST']` (D12: uso no claro). Sus parámetros de método `$from/$until` se sobrescriben con `$_POST`, así que la ruta no declara parámetros.
- Las otras 6 pantallas solo renderizan → GET.
- Las 20 llamadas de `commercial.js` usan `method: 'POST'` (DataTables o `fetch`), salvo `download_format_budget` (`fetch` sin método → GET).
- Sin ruta (D11, no se usan): `sale_lubricants_month`, `sales_type_payment_totals_table`, `date_report`, `import_data`, `getFileErrorMessage`, `import_data_budget`.
- Ninguna vista de `views/commercial/` tiene formularios ni URLs escritas a mano; todo está en `sidebar.html` (14 enlaces, 2 secciones del menú) y `commercial.js` (24 llamadas).

## Review Focus

1. Una pantalla GET que también recibe POST → 405. Cubierto: `sale_lubricants` va con GET+POST y las demás no leen `$_POST`.
2. Un DataTable cuyo `method` está lejos de su `url` (hasta 15 líneas arriba): si alguno fuera GET y la ruta solo POST, la tabla no carga. → Tarea 1, verificación por `grep` del verbo de cada bloque `ajax`.
3. `commercial.js` lo cargan 7 vistas: si alguna no extiende `base.html`, `routeUrl` no existe. → Tarea 1, verificación.
4. El menú tiene los enlaces de Comercial en dos secciones (líneas ~42–65 y ~275–294): cambiar las dos. → Tarea 1, `grep` final.
5. URL vieja con mayúsculas o barra final sigue funcionando por alias. → filas con `Commercial/Sales_Indicators/`.

---

### Task 1: `commercial`

**Files:**
- Create: `routes/commercial.php`
- Modify: `tests/RoutesFase2Test.php`, `views/layouts/sidebar.html` (14 enlaces), `_assets/js/commercial.js` (24 llamadas)

- [ ] **Step 1: Filas (prueba que falla)** — handler `Commercial`, args `[]`, reverse `[]`, alias = URL vieja con `_`:

| nombre | verbos | URL nueva |
|---|---|---|
| `commercial.sales_indicators` | GET | `commercial/sales-indicators` (alias de ejemplo `Commercial/Sales_Indicators/`) |
| `commercial.sale_month_turn` | GET | `commercial/sale-month-turn` |
| `commercial.sale_type_payment` | GET | `commercial/sale-type-payment` |
| `commercial.sale_lubricants` | GET, POST | `commercial/sale-lubricants` |
| `commercial.sale_week_zone` | GET | `commercial/sale-week-zone` |
| `commercial.sales_global_total` | GET | `commercial/sales-global-total` |
| `commercial.mystery_shopper` | GET | `commercial/mystery-shopper` |
| `commercial.mistery_shopper_table` | POST | `commercial/mistery-shopper-table` |
| `commercial.import_file_mystery_shopper` | POST | `commercial/import-file-mystery-shopper` |
| `commercial.lubricants_table` | POST | `commercial/lubricants-table` |
| `commercial.lubricants_table_month` | POST | `commercial/lubricants-table-month` |
| `commercial.lubricants_table_day` | POST | `commercial/lubricants-table-day` |
| `commercial.lubricants_table_base` | POST | `commercial/lubricants-table-base` |
| `commercial.sales_type_payment_table` | POST | `commercial/sales-type-payment-table` |
| `commercial.mounth_group_table` | POST | `commercial/mounth-group-table` |
| `commercial.mounth_company_table` | POST | `commercial/mounth-company-table` |
| `commercial.mounth_company_table2` | POST | `commercial/mounth-company-table2` |
| `commercial.mounth_estation_table` | POST | `commercial/mounth-estation-table` |
| `commercial.mounth_estation_eventos` | POST | `commercial/mounth-estation-eventos` |
| `commercial.differencesInfoModal` | POST | `commercial/differences-info-modal` |
| `commercial.sale_month_turn_table` | POST | `commercial/sale-month-turn-table` |
| `commercial.sale_month_turn_base_table` | POST | `commercial/sale-month-turn-base-table` |
| `commercial.sale_week_zone_table` | POST | `commercial/sale-week-zone-table` |
| `commercial.sales_indicators_table` | POST | `commercial/sales-indicators-table` |
| `commercial.sales_global_table` | POST | `commercial/sales-global-table` |
| `commercial.import_file_budget` | POST | `commercial/import-file-budget` |
| `commercial.download_format_budget` | GET | `commercial/download-format-budget` |

Run `php tests/RoutesFase2Test.php` → `FALLA` en las 27 filas, sin errores fatales.

- [ ] **Step 2: `routes/commercial.php`** — `group('commercial/', …)` con la tabla; cada ruta con `alias: ['commercial/<metodo>']` (el nombre del método tal cual, p. ej. `commercial/differencesInfoModal`).
- [ ] **Step 3: Enlaces** — `sidebar.html`: los 14 `href="/commercial/…"` → `{{ url('commercial.…') }}`. `commercial.js`: cada `url: '/commercial/x'` → `url: routeUrl('commercial.x')` y cada `fetch('/commercial/x'` → `fetch(routeUrl('commercial.x')`.
- [ ] **Step 4: Verificar**
  - `php tests/RoutesFase2Test.php && php tests/RoutesFase1Test.php && php tools/routes.php lint` → `Todo bien`, `Todo bien`, `138 rutas revisadas, sin problemas` (84 previas + 27 rutas + 27 alias).
  - `node --check _assets/js/commercial.js`.
  - `grep -rnE "[\"'\`]/commercial[/\"'\`]" views _assets/js _assets/controllers` → sin resultados.
  - Review Focus 2: para cada `routeUrl('commercial.x')` de `commercial.js`, el verbo del bloque (hasta 15 líneas arriba, o `fetch` sin `method`) coincide con la tabla.
  - Review Focus 3: las 7 vistas que cargan `commercial.js` extienden `views/layouts/base.html`.
  - Las vistas tocadas compilan con Twig.
- [ ] **Step 5: Commit** — `feat(router): rutas de commercial con alias y enlaces por nombre`.

### Task 2: Cierre del bloque

- [ ] **Step 1: Suite** — la misma de la Tarea 4 del bloque 1; ningún `FALLÓ`, lint 0.
- [ ] **Step 2: Spec** — **Estado**: bloque 2 implementado, checklist pendiente.
- [ ] **Step 3: Checklist manual (con sesión)** — abrir las 7 pantallas de Comercial desde **las dos secciones** del menú; en cada una, consultar un rango de fechas y confirmar que sus tablas cargan (Indicadores, Venta mensual por turno incluida la base, Tipo de pago y sus totales, Lubricantes mensual/diario/base, Venta semanal por zona, Venta global, Mystery shopper); abrir el modal de diferencias y los eventos por estación; descargar el formato de presupuesto; ⚠️ importar presupuesto y mystery shopper solo en pruebas. `legacy-hits` sin entradas `commercial` inesperadas.
- [ ] **Step 4: Commit** — `docs: estado del bloque 2 de la Fase 2`.
