# Router — Fase 2, bloque 4a: `administration` (varios)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rutas explícitas con alias para 16 métodos de `administration` (despachos mensuales, tipo de cambio, bitácora de ajustes, documento público y los indicadores de `main.js`), sin que ninguna URL actual deje de funcionar. Los tickets de Mojo (4c) y los anticipos (4b) quedan para sus propios sub-bloques.

**Architecture:** Igual que los bloques anteriores: `routes/administration.php` (que 4b y 4c ampliarán) + filas en `tests/RoutesFase2Test.php` + `lint`.

**Spec:** `docs/superpowers/specs/2026-10-16-router-migracion-design.md` — D1, D8–D14 y los patrones de inventario de la sección 7.

## Global Constraints

- `ROUTER_LEGACY_FALLBACK` sigue en `true`. D8 · D9 (sin `permission:`) · D10 (`administration.tablaAuditoria`) · D11 · D12 · D13 · D14.
- Las 16 URLs cambian → 16 alias.
- `doc_agujita` es **pública** (está en `$publicRoutes` de `index.php` y se comparte fuera del sistema): la ruta lleva `public: true` y su alias hereda esa opción. `$publicRoutes` no se toca.
- Un commit por tarea, `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Hallazgos que el plan ya resuelve

- `administration.js` lo cargan 14 vistas de `administration` y 5 de otros módulos (`income/balance_age`, `supply/binnacle`, `supply/changes`, `supply/inventarioMermas`, `supply/tgr01`); todas deben extender `base.html` (Review Focus 3).
- `main.js` (cargado por `base.html` en **todas** las pantallas) llama a los tres `porcent_*` con `fetch` POST.
- `datatables_monthly_dispatches($codgas, $from, $until)`: 30 llamadas en `administration.js`, una por estación (`codgas` 2 a 33), todas POST, con las fechas de `#from`/`#until`. `DespachosModel::get_rows(int $codgas, …)` es tipado → `<int:codgas>`; fechas `<str:from>`, `<str:until>`.
- `monthly_dispatches` no tiene enlace en el menú, pero es la pantalla que hospeda esas 30 tablas → lleva ruta (mismo criterio que `humancapital.employees`).
- `tablaAuditoria` y `tablaTickets` (`$from, $until, $codgas`) se piden desde `binnacle_adjustments.html` con valores de Twig (DataTables sin `type` → GET).
- `exchange_rate_process`, `update_exchange`, `delete_exchange` exigen POST en el propio controlador; `datatable_exchange_rate` es POST.
- `get_binnacle` es un enlace `target="_blank"` → GET. `binnacle_adjustments` recibe su propio formulario por GET (sin `action`).

## Review Focus

1. **Fechas vacías.** `routeUrl()`/`url()` lanzan si un parámetro viene vacío (hoy se armaba `/…/2//` y el método recibía `''`).
   - JS de despachos: si `#from` o `#until` están vacíos, avisar con `alertify.myAlert('Selecciona el rango de fechas')` y no consultar. → Tarea 1.
   - `binnacle_adjustments`: si el usuario envía el filtro con una fecha vacía, `$_GET['from']` es `''` y `url()` rompería la pantalla. El controlador pasa de `isset(...)` a `!empty(...)` en esas 3 líneas, así una fecha vacía usa el mismo valor por defecto que una ausente. → Tarea 1 (D14 ampliado: cambio mínimo de controlador para que el enlace con nombre no rompa la vista).
2. `main.js` corre en todas las pantallas: un nombre mal escrito rompería los indicadores del inicio en todo el sistema. → `lint` lo revisa; checklist abre el inicio.
3. Las 19 vistas que cargan `administration.js` extienden `base.html`. → Tarea 1.
4. `doc_agujita` sigue abriendo **sin sesión** por la URL vieja y por la nueva. → filas con `public` y prueba con el servidor local.
5. El verbo de cada llamada JS coincide con su ruta (DataTables con `type` lejos de `url`). → script de verificación de los bloques anteriores.

---

### Task 1: `administration` — varios

**Files:**
- Create: `routes/administration.php`
- Modify: `tests/RoutesFase2Test.php`, `views/layouts/sidebar.html` (2 enlaces: `relation_corpo_estaciones`, `exchange_rate`), `views/administration/exchange_rate.html` (3), `views/administration/binnacle_adjustments.html` (2), `_assets/js/administration.js` (30 de despachos + 3 de tipo de cambio), `_assets/js/main.js` (3), `_assets/controllers/administration.php` (`binnacle_adjustments`: `isset` → `!empty`)

- [ ] **Step 1: Filas (prueba que falla)** — handler `Administration`, alias = URL vieja:

| nombre | verbos | URL nueva de ejemplo | args |
|---|---|---|---|
| `administration.monthly_dispatches` | GET | `administration/monthly-dispatches` | — |
| `administration.datatables_monthly_dispatches` | POST | `administration/datatables-monthly-dispatches/2/2026-10-01/2026-10-16` | `[2,'2026-10-01','2026-10-16']` |
| `administration.relation_corpo_estaciones` | GET | `administration/relation-corpo-estaciones` | — |
| `administration.exchange_rate` | GET | `administration/exchange-rate` | — |
| `administration.datatable_exchange_rate` | POST | `administration/datatable-exchange-rate` | — |
| `administration.exchange_rate_process` | POST | `administration/exchange-rate-process` | — |
| `administration.update_exchange` | POST | `administration/update-exchange` | — |
| `administration.delete_exchange` | POST | `administration/delete-exchange` | — |
| `administration.get_binnacle` | GET | `administration/get-binnacle` | — |
| `administration.binnacle_adjustments` | GET | `administration/binnacle-adjustments` | — |
| `administration.tablaAuditoria` | GET | `administration/tabla-auditoria/2026-07-01/2026-10-16/0` | `['2026-07-01','2026-10-16','0']` |
| `administration.tablaTickets` | GET | `administration/tabla-tickets/2026-07-01/2026-10-16/0` | idem |
| `administration.doc_agujita` | GET (**public**) | `administration/doc-agujita` | — |
| `administration.porcent_estacion_info` | POST | `administration/porcent-estacion-info` | — |
| `administration.porcent_estacion_facturados_info` | POST | `administration/porcent-estacion-facturados-info` | — |
| `administration.porcent_facturas_info` | POST | `administration/porcent-facturas-info` | — |

Más: `check('doc_agujita es pública (ruta y alias)', …['public'] === true` para `administration/doc-agujita` y `administration/doc_agujita`). Run → `FALLA` solo en filas de `administration`.

- [ ] **Step 2: `routes/administration.php`** — `group('administration/', …)`; comentario de cabecera que diga que 4b (anticipos) y 4c (tickets) agregarán sus rutas aquí.
- [ ] **Step 3: Enlaces y guardas**
  - `sidebar.html` y `exchange_rate.html` → `url()`.
  - `binnacle_adjustments.html:115,175` → `url: '{{ url('administration.tablaAuditoria', {from: from, until: until, codgas: station_selected}) }}'` (y `tablaTickets`).
  - `administration.js`: las 30 llamadas → `url: routeUrl('administration.datatables_monthly_dispatches', { codgas: N, from: from, until: until })`, con la guarda de fechas vacías al inicio del manejador de `#download_info`; tipo de cambio → `routeUrl('administration.…')`.
  - `main.js`: los tres `fetch('/administration/porcent_…'` → `fetch(routeUrl('administration.porcent_…')`.
  - `administration.php::binnacle_adjustments`: `isset($_GET['from'])` → `!empty($_GET['from'])` (y `until`, `station_selected`).
- [ ] **Step 4: Verificar** — `php tests/RoutesFase2Test.php && php tests/RoutesFase1Test.php && php tools/routes.php lint` → `230 rutas revisadas, sin problemas` (198 + 16 + 16); `node --check` de `administration.js` y `main.js`; `php -l` del controlador; verbos JS vs. rutas → todo `ok`; las 19 vistas extienden `base.html`; las vistas tocadas compilan; `grep` de URLs viejas de los 16 métodos → sin resultados; servidor local sin sesión: `/administration/doc-agujita` y `/administration/doc_agujita` → 200 con el documento (no el login).
- [ ] **Step 5: Commit** — `feat(router): rutas de administration (varios) con alias y enlaces por nombre`.

### Task 2: Cierre del sub-bloque

- [ ] **Step 1: Suite** completa, ningún `FALLÓ`, lint 0.
- [ ] **Step 2: Spec** — **Estado**: sub-bloque 4a implementado.
- [ ] **Step 3: Checklist manual** — inicio (los indicadores de estación y facturación cargan), Relación corporativo-estaciones, Despachos mensuales (consultar un rango: las 30 tablas cargan; con una fecha vacía sale el aviso), Tipo de cambio (tabla, ver bitácora, ⚠️ procesar/actualizar/eliminar solo en pruebas), Bitácora de ajustes (filtrar; enviar con fecha vacía usa el rango por defecto), y `doc_agujita` en una ventana privada sin sesión.
- [ ] **Step 4: Commit** — `docs: estado del sub-bloque 4a de la Fase 2`.
