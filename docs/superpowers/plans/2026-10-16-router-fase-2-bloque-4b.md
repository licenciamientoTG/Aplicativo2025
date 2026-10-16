# Router — Fase 2, bloque 4b: `administration` (anticipos y débito/crédito)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rutas explícitas con alias para los 10 métodos de anticipos y débito/crédito de `administration`, y enlaces por nombre, sin que ninguna URL actual deje de funcionar ni se rompa el JS de las otras pantallas que cargan `administration.js`.

**Architecture:** Se amplía `routes/administration.php` (sub-bloque 4a) + filas en `tests/RoutesFase2Test.php` + `lint`. Nuevo helper `tableRouteUrl()` en `administration.js` para las tablas que se inicializan al cargar el archivo.

**Spec:** `docs/superpowers/specs/2026-10-16-router-migracion-design.md` — D1, D8–D14 y patrones de la sección 7.

## Global Constraints

- `ROUTER_LEGACY_FALLBACK` sigue en `true`. D8 · D9 · D10 · D11 · D12 · D13 · D14. Las 10 URLs cambian → 10 alias.
- Un commit por tarea, `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Hallazgos que el plan ya resuelve

- **Riesgo principal: tablas que se inicializan en todas las páginas.** `administration.js` (lo cargan 19 vistas, 5 de otros módulos) declara `$('#datatables_anticipos').DataTable({ ajax: { url: '/administration/…/' + $('input#from').val() … } })` a nivel de archivo. La URL se evalúa aunque la tabla no esté en la página. Hoy es una concatenación inofensiva; con `routeUrl()`, un `input#from` ausente o vacío **lanza y detiene el resto del archivo en las 19 pantallas**. Solución: helper `tableRouteUrl(selector, name, params)` que devuelve `''` si la tabla no está en la página o si algún parámetro viene vacío, y si no `routeUrl(name, params)`. Con `''` y sin tabla, DataTables no hace ninguna petición.
- `cd_report.html:411-424` inicializa otra vez `#datatables_customer_anticipos` en un `<script>` en línea **fuera** del `{% if input_from is not null %}` (corre en la carga inicial sin fechas) → también usa `tableRouteUrl()` (`administration.js` se carga antes, en la línea 330).
- `cd_report.html:216,241` (`get_advance_clients/80|20/{{ from }}/{{ until }}`) están dentro del `if`, pero si se llega con `?input_from=…` y sin `input_until`, `from`/`until` son `null` y `url()` rompería la pantalla → envolver cada enlace en `{% if from and until %}`. (El formulario tiene ambos campos `required`; solo pasa manipulando la URL.)
- `cd_report.html:73` usa `item.DiaInicialDelMes`/`DiaFinalDelMes` de la base dentro de un `for` → siempre tienen valor.
- **El controlador arma HTML con URLs** (`administration.php:1797,1838`, botones a `get_advance_clients_details/<cod>/<from>/<until>`) → `Router::reverse()` (D14).
- `administration.js` también inicializa `#datatables_customer_advances` con `/administration/datatables_customer_advances/…`, un **método que no existe**; esa tabla no está en ninguna vista, así que la petición nunca se hace. Código muerto: se deja como está (mismo criterio que el código comentado del 4a).
- Tipos: `percent` se compara con `== 80` → `<int:percent>`; `codcli` viene de la base y no se castea → `<str:codcli>`; fechas `<str:x>`.
- Los formularios de `dashboard_debit_credit` y `cd_report` son `method="get"` a sí mismos → rutas GET bastan. Todas las tablas son `type: 'POST'`; `search_credit_and_debits_clients` es `type: 'GET'`.

## Review Focus

1. Abrir cualquiera de las 19 pantallas que cargan `administration.js` **sin** las tablas de anticipos (p. ej. Tipo de cambio, `supply/binnacle`): ningún error en consola y su propio JS funciona. → Tarea 1 (prueba del helper con Node) y checklist.
2. `cd_report` en la carga inicial (sin fechas): sin error en consola. → helper.
3. `cd_report` con `?input_from=2026-01` sin `input_until`: la pantalla abre (sin los enlaces "Ver detalles"). → guarda Twig.
4. Botón de detalle generado por el controlador: lleva a `/administration/get-advance-clients-details/<cod>/<from>/<until>`. → `Router::reverse()` + fila.
5. Verbo de cada llamada JS vs. su ruta. → script de verificación.

---

### Task 1: `administration` — anticipos y débito/crédito

**Files:**
- Modify: `routes/administration.php`, `tests/RoutesFase2Test.php`, `tests/route_url_test.js` (o nuevo `tests/table_route_url_test.js`), `_assets/js/administration.js`, `views/administration/cd_report.html`, `views/administration/dashboard_debit_credit.html`, `views/layouts/sidebar.html` (2), `_assets/controllers/administration.php:1797,1838`

- [ ] **Step 1: Filas (prueba que falla)** — handler `Administration`, alias = URL vieja:

| nombre | verbos | URL nueva de ejemplo | args |
|---|---|---|---|
| `administration.dashboard_debit_credit` | GET | `administration/dashboard-debit-credit` | — |
| `administration.in_process` | GET | `administration/in-process` | — |
| `administration.search_credit_and_debits_clients` | GET | `administration/search-credit-and-debits-clients` | — |
| `administration.get_advances` | GET | `administration/get-advances/2026-10-01/2026-10-31` | `['2026-10-01','2026-10-31']` |
| `administration.get_advance_clients` | GET | `administration/get-advance-clients/80/2026-10-01/2026-10-31` | `[80,'2026-10-01','2026-10-31']` |
| `administration.get_advance_clients_details` | GET | `administration/get-advance-clients-details/C123/2026-10-01/2026-10-31` | `['C123',…]` |
| `administration.datatables_anticipos` | POST | `administration/datatables-anticipos/2026-10-01/2026-10-31` | fechas |
| `administration.datatables_customer_anticipos` | POST | `administration/datatables-customer-anticipos/2026-10-01/2026-10-31` | fechas |
| `administration.datatables_customer_anticipos2` | POST | `administration/datatables-customer-anticipos2/20/2026-10-01/2026-10-31` | `[20,…]` |
| `administration.datatables_customer_advances_details` | POST | `administration/datatables-customer-advances-details/C123/2026-10-01/2026-10-31` | `['C123',…]` |

Run → `FALLA` solo en estas filas.

- [ ] **Step 2: Prueba del helper (falla)** — `tests/table_route_url_test.js`: con un `$` falso (`selector => ({ length: presentes.includes(selector) ? 1 : 0 })`) y `ROUTES` con `administration.datatables_anticipos`, carga `route_url.js` y la función `tableRouteUrl` (exportada igual que `route_url.js`: `module.exports` si existe) y verifica: tabla ausente → `''`; tabla presente con `from: ''` → `''`; con `from: undefined` → `''`; tabla presente y fechas → `'/administration/datatables-anticipos/2026-10-01/2026-10-31'`; nunca lanza. Run `node tests/table_route_url_test.js` → falla (no existe).
- [ ] **Step 3: Implementar el helper** en `_assets/js/table_route_url.js` (archivo propio para poder probarlo con Node y reutilizarlo en 4c), cargado en `base.html` justo después de `route_url.js` (actualizar `tests/LayoutTest.php`: `table_route_url.js` después de `route_url.js` y antes de `tableros_transition.js`, fuera de cualquier `if`). Run → pasa.
- [ ] **Step 4: `routes/administration.php`** — agregar las 10 rutas con alias.
- [ ] **Step 5: Enlaces**
  - `administration.js` (4 tablas vivas): `url: tableRouteUrl('#datatables_anticipos', 'administration.datatables_anticipos', { from: $('input#from').val(), until: $('input#until').val() })`, igual para `customer_anticipos`, `customer_anticipos2` (`percent`) y `customer_advances_details` (`codcli`). La de `datatables_customer_advances` no se toca.
  - `cd_report.html:424` → `tableRouteUrl(...)`; `:73` → `url('administration.get_advances', {from: item.DiaInicialDelMes, until: item.DiaFinalDelMes})`; `:216,241` → `url('administration.get_advance_clients', {percent: 80|20, from: from, until: until})` dentro de `{% if from and until %}`.
  - `dashboard_debit_credit.html:358` → `'{{ url('administration.search_credit_and_debits_clients') }}'`.
  - `sidebar.html`: `dashboard_debit_credit`, `in_process`.
  - `administration.php:1797,1838` → `Router::reverse('administration.get_advance_clients_details', ['codcli' => $anticipo['cod'], 'from' => $from, 'until' => $until])`.
- [ ] **Step 6: Verificar** — `RoutesFase2Test`, `RoutesFase1Test`, `LayoutTest`, `table_route_url_test.js`, `route_url_test.js` → `Todo bien`; `lint` → `250 rutas revisadas, sin problemas`; `node --check`; `php -l`; verbos JS vs. rutas → `ok`; vistas tocadas compilan; `grep` de URLs viejas de estos 10 métodos → solo la línea muerta de `datatables_customer_advances`.
- [ ] **Step 7: Commit** — `feat(router): rutas de administration (anticipos) con alias y tableRouteUrl()`.

### Task 2: Cierre del sub-bloque

- [ ] **Step 1: Suite** completa (incluye `node tests/table_route_url_test.js`), ningún `FALLÓ`, lint 0.
- [ ] **Step 2: Spec** — **Estado**: 4b implementado.
- [ ] **Step 3: Checklist manual** — Dashboard débito y crédito (selector de clientes carga), Anticipos débito: carga inicial sin errores en consola, filtrar meses, tarjetas por mes (abre `get-advances`), "Ver detalles" 80 % y 20 %, botón de detalle de un cliente; y abrir Tipo de cambio y `supply/binnacle` con la consola abierta: sin errores.
- [ ] **Step 4: Commit** — `docs: estado del sub-bloque 4b de la Fase 2`.
