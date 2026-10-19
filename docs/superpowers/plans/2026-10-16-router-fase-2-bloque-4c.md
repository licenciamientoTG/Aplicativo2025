# Router — Fase 2, bloque 4c: `administration` (tickets de Mojo)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rutas explícitas con alias para los 20 métodos de tickets de Mojo de `administration` que usa alguna vista o JS, y enlaces por nombre, sin romper ninguna URL actual ni el JS de las otras 18 pantallas que cargan `administration.js`. Cierra el sub-bloque 4c y, con él, el bloque 4 completo de `administration`.

**Architecture:** Se amplía `routes/administration.php` (4a + 4b) con la sección de tickets de Mojo + filas en `tests/RoutesFase2Test.php` + `lint`. No se necesita un helper nuevo como `tableRouteUrl()` (4b): las 10 tablas `datatables_*` de este bloque reciben sus filtros por `POST` en el body, no en la URL, así que sus rutas nuevas no llevan parámetros y no hay riesgo de que falte uno al cargar `administration.js` en otra pantalla.

**Spec:** `docs/superpowers/specs/2026-10-16-router-migracion-design.md` — D1, D8–D14 y patrones de la sección 7.

## Global Constraints

- `ROUTER_LEGACY_FALLBACK` sigue en `true`.
- Un commit por tarea, `Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>`.

## Hallazgos que el plan ya resuelve

- **D11 (no reciben ruta nueva):** `get_latest_tickets`, `get_ticket`, `update_groups`, `update_ticket_queues`, `update_users`, `update_ticket_tags`, `update_ticket_forms`, `update_ticket_types` son helpers internos invocados con `$this->...` solo desde `update_mojo()`; nunca se llaman por URL. Los helpers de fecha (`getMondayFromWeek`, `getSundayFromWeek`, `getFirstDayOfMonth`, `getLastDayOfMonth`, `getFirstDayOfYear`, `getLastDayOfYear`) tampoco: los usa `list_tickets`/`stats_tickets`/`datatables_tickets_2` internamente. `get_statistics` (exporta un Excel con datos de ejemplo hardcodeados) no está enlazado desde ningún lado — el `#exportExcel` de `list_tickets.html` no tiene handler en `administration.js`. Los ocho primeros siguen alcanzables por el fallback legacy; eso no cambia con este bloque.
- **`get_priority` tiene dos formas viejas** (4 o 5 segmentos, según si el llamador manda `user_id`). El router exige que el alias tenga los mismos parámetros que la ruta principal (`Router::lint`), así que solo se aliasea el patrón de 5 segmentos; las 14 llamadas de `stats_tickets.html` se actualizan a la ruta nueva de 5 segmentos (pasando `0` donde hoy se omite). El patrón viejo de 4 segmentos sigue vivo por el fallback legacy, pero nada en el repo volverá a generarlo tras este cambio.
- `user_id` en `get_priority` es `<str:...>`: en el caso `supports` en realidad viaja `type.problem` (un string), no un id.
- **`close_ticket` está en el whitelist de público de `index.php`** (`$publicRoutes`), pero la comparación es un string exacto sin el `<id>`, así que hoy nunca calza con la URL real (`administration/close_ticket/123` ≠ `administration/close_ticket`): el acceso anónimo que claramente se buscó está roto. Con `public: true` en la ruta nueva, `$routeIsPublic` se resuelve antes de llegar a ese whitelist y el acceso público empieza a funcionar tanto por la ruta nueva como por el alias viejo. Se preserva el whitelist tal cual (igual que se hizo con `doc_agujita` en 4a).
- Todas las tablas `datatables_*` de este bloque llevan sus filtros (`from`, `until`, `ticket_form`, `date_range`, `user_id`) en el `data` del `$.ajax`/DataTables (body `POST`), nunca en la URL — las 10 rutas nuevas no llevan parámetros.
- Tipos: `ticket_id` en `delete_ticket`/`update_ticket_db`/`close_ticket` es `<int:...>` (siempre un `id_mojo` numérico embebido sin comillas en el `onclick`). `ticket_form_id` de `filtered_statistics` es `<int:...>` (se castea `(int)` antes de llamar al modelo); el resto de los parámetros de texto/fecha queda `<str:...>`.
- `agent_id` de `filtered_statistics` es `<str:...>`: puede ser `'0'` o un CSV como `'12,34,-1'`.
- No es código muerto; es un hallazgo aparte y fuera de alcance: `administration.js` tiene hardcodeada una API key de Mojo Helpdesk en el cliente (usada en `delete_ticket`/`updateMojoTicket`). No se toca en este bloque.

## Review Focus

1. Abrir cualquiera de las 18 pantallas que cargan `administration.js` sin las tablas de tickets (p. ej. Tipo de cambio): sin errores en consola.
2. `Lista de tickets`: carga, botón "Actualizar Tickets", tabla principal y la de cerrados/cumplidos (`datatables_tickets_2`).
3. `Estadístico de tickets`: cada pestaña (semanal/mensual/anual) y cada enlace "Ver detalles" de `get_priority` (abiertos, resueltos, urgentes, normales, usuarios, grupos, departamentos, soportes) abre la pantalla correcta; el modal de reporte de agente (`get_agent_report`) carga.
4. `Cálculo mensual ECV`: los tres enlaces de `filtered_statistics` (totales/abiertos/cerrados) y el enlace por agente.
5. `close_ticket` sin sesión: confirmar que ahora sí es accesible (antes, con sesión expirada, mandaba al login).
6. Verbo de cada llamada JS vs. su ruta.

---

### Task 1: `administration` — tickets de Mojo

**Files:**
- Modify: `routes/administration.php`, `tests/RoutesFase2Test.php`, `_assets/js/administration.js`, `views/administration/stats_tickets.html`, `views/administration/ecv_calc.html`, `views/layouts/sidebar.html`

- [ ] **Step 1: Filas (prueba que falla)** — handler `Administration`, alias = URL vieja:

| nombre | verbos | URL nueva de ejemplo | args |
|---|---|---|---|
| `administration.list_tickets` | GET | `administration/list-tickets` | — |
| `administration.stats_tickets` | GET | `administration/stats-tickets` | — |
| `administration.ecv_calc` | GET | `administration/ecv-calc` | — |
| `administration.filtered_statistics` | GET | `administration/filtered-statistics/total_tickets/2026-10/51598/0` | `['total_tickets','2026-10',51598,'0']` |
| `administration.datatables_tickets` | POST | `administration/datatables-tickets` | — |
| `administration.datatables_tickets_2` | POST | `administration/datatables-tickets-2` | — |
| `administration.update_mojo` | POST | `administration/update-mojo` | — |
| `administration.delete_ticket` | GET | `administration/delete-ticket/12345` | `[12345]` |
| `administration.datatables_urgentes_tickets` | POST | `administration/datatables-urgentes-tickets` | — |
| `administration.datatables_normales_tickets` | POST | `administration/datatables-normales-tickets` | — |
| `administration.datatables_abiertos_tickets` | POST | `administration/datatables-abiertos-tickets` | — |
| `administration.datatables_resueltos_tickets` | POST | `administration/datatables-resueltos-tickets` | — |
| `administration.datatables_usuarios_tickets` | POST | `administration/datatables-usuarios-tickets` | — |
| `administration.datatables_grupos_tickets` | POST | `administration/datatables-grupos-tickets` | — |
| `administration.update_ticket_db` | GET | `administration/update-ticket-db/12345` | `[12345]` |
| `administration.datatables_departments_tickets` | POST | `administration/datatables-departments-tickets` | — |
| `administration.datatables_supports_tickets` | POST | `administration/datatables-supports-tickets` | — |
| `administration.get_priority` | GET | `administration/get-priority/2026-10-01/2026-10-31/51598/abiertos/0` | `['2026-10-01','2026-10-31','51598','abiertos','0']` |
| `administration.get_agent_report` | GET | `administration/get-agent-report/4351669/2026-10-01/2026-10-31/mensual/51598` | `['4351669','2026-10-01','2026-10-31','mensual','51598']` |
| `administration.close_ticket` | GET, público | `administration/close-ticket/12345` | `[12345]` |

Run → `FALLA` solo en estas filas.

- [ ] **Step 2: `routes/administration.php`** — agregar las 20 rutas con alias (sección "4c: tickets de Mojo"); `close_ticket` con `public: true`.
- [ ] **Step 3: Enlaces**
  - `administration.js` (13 llamadas): reemplazar las URLs escritas a mano por `routeUrl('administration.xxx')` (sin parámetros, las 10 `datatables_*` + `update_mojo`) y `routeUrl('administration.delete_ticket', {ticket_id})` / `routeUrl('administration.update_ticket_db', {ticket_id})` donde sí los hay.
  - `stats_tickets.html`: los 14 enlaces de `get_priority` → `url('administration.get_priority', {from:…, until:…, ticket_form:…, priority:…, user_id: … })` (pasando `user_id: 0` donde hoy no se manda); el `$.ajax` de `get_agent_report` (línea ~1505) → `routeUrl('administration.get_agent_report', {...})`.
  - `ecv_calc.html`: los 4 enlaces de `filtered_statistics` → `url('administration.filtered_statistics', {...})`.
  - `sidebar.html`: `list_tickets`, `stats_tickets`, `ecv_calc`.
- [ ] **Step 4: Verificar** — `RoutesFase2Test`, `RoutesFase1Test`, `LayoutTest`, `route_url_test.js` → `Todo bien`; `lint` → `270 rutas revisadas, sin problemas`; `php -l`; verbos JS vs. rutas → `ok`; vistas tocadas compilan; `grep` de URLs viejas de estos 20 métodos → ninguna fuera de alias/fallback.
- [ ] **Step 5: Commit** — `feat(router): rutas de administration (tickets de Mojo) con alias y enlaces por nombre`.

### Task 2: Cierre del sub-bloque y del bloque 4

- [ ] **Step 1: Suite** completa, ningún `FALLÓ`, lint 0.
- [ ] **Step 2: Spec** — **Estado**: 4c implementado; bloque 4 de `administration` completo.
- [ ] **Step 3: Checklist manual** — ver Review Focus arriba.
- [ ] **Step 4: Commit** — `docs: estado del sub-bloque 4c de la Fase 2 (cierre del bloque 4)`.
