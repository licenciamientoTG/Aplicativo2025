# Router — Fase 1: módulos chicos (xml, errors, tableros, humancapital, marketing)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Declarar rutas explícitas (con alias hacia las URLs viejas) para los métodos que usan las vistas y el JS de `xml`, `tableros`, `humancapital` y `marketing`, y cambiar sus enlaces a `url()` / `routeUrl()`, sin que ninguna URL actual deje de funcionar.

**Architecture:** Un archivo `routes/{modulo}.php` por controlador (infraestructura de la Fase 0). Una prueba table-driven, `tests/RoutesFase1Test.php`, fija para cada ruta: URL nueva → handler, verbos, `reverse()` del nombre y alias → mismo handler. `php tools/routes.php lint` comprueba handlers y que todo `url('x')` / `routeUrl('x')` exista. Los enlaces escritos a mano se reemplazan módulo por módulo.

**Tech Stack:** PHP 8.4 (pruebas en scripts con `tests/support.php`), Twig 3, jQuery, Node 24 solo para `tests/route_url_test.js`.

**Spec:** `docs/superpowers/specs/2026-10-16-router-migracion-design.md` — en especial D1, D8–D13 y la sección 7.

## Global Constraints

- `ROUTER_LEGACY_FALLBACK` se queda en `true`. Ninguna URL actual deja de funcionar.
- **D8:** URLs en estilo acción y kebab-case; camelCase también pasa a kebab (`findClientForm` → `find-client-form`).
- **D9:** **ninguna** ruta lleva `permission:` en esta fase.
- **D10:** nombre de ruta = `modulo.metodo` con el nombre del método tal cual (`marketing.findClientForm`, `marketing.print_card`).
- **D11:** solo llevan ruta los métodos que usa alguna vista o JS (más las pantallas de entrada que renderizan una vista).
- **D12:** verbos según el uso real; si no es claro, `['GET', 'POST']`.
- **D13:** `<int:x>` solo si el código trata el valor como número; si hay duda, `<str:x>`.
- **D1:** cada ruta cuya URL cambia lleva su URL vieja como `alias` (absoluta, mismos parámetros y en el mismo orden). Si la URL nueva es idéntica a la vieja, **no** lleva alias (sería un conflicto en `lint`).
- Sin dependencias nuevas. Comentarios en español. Un commit por tarea, con la línea `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>` (ruling de la Fase 0).
- Comandos en sintaxis bash (Git Bash), desde la raíz del repo.

## Hallazgos que el plan ya resuelve

- `errors` no lleva rutas: ninguna vista ni JS enlaza a `fail()` o `get404()` (se usan desde PHP). Cumple D11 sin cambios; se deja constancia en la Tarea 6.
- `tableros_transition.js` se carga en el `<head>` de `base.html` (línea 17) y lee su destino al cargar, **antes** de que existan `window.ROUTES` y `routeUrl()` (hoy junto a `app.js`). Por eso la Tarea 1 sube esos dos scripts al `<head>`, antes de `tableros_transition.js`.
- `tableros.js:526` lee el id del tablero del pathname con `/\/tableros\/board\/([^/]+)/`. La URL `tableros/board/<id>` **no cambia**, así que esa línea no se toca.
- `humancapital/employees` no aparece enlazada en vistas ni JS, pero es la pantalla de entrada del módulo (renderiza `employees.html`): lleva ruta.
- `marketing::print_sticker($station, $from, $until, $image = false)`: el único uso (menú) pasa 3 parámetros; la ruta declara esos 3. Con 4 segmentos sigue entrando por el fallback.

## Review Focus

1. Un enlace con parámetros en `findClientTable.html` cuando `vehicle.Tarjeta`, `vehicle.cod`, `driver.codcli` o `driver.nrocho` vienen vacíos: `url()` lanza `InvalidArgumentException` y rompe el render completo de la tabla de clientes (AJAX). Lo esperado es que la tabla se muestre igual. → Tarea 5 (guarda en la plantilla y fila de prueba).
2. `routeUrl()` usado en un JS que se ejecuta antes de que exista `window.ROUTES` (`tableros_transition.js` en el `<head>`): `ReferenceError` y la transición a Tableros deja de funcionar. → Tarea 1.
3. Parámetros que dependían del `strtolower` del fallback viejo (la ruta nueva entrega el valor tal cual): `print_sticker/station/...` y tarjetas con letras. Lo esperado es el mismo resultado que antes. → Tarea 5 (fila de prueba con mayúsculas en el alias).
4. Una URL vieja con barra final o mayúsculas (`/Marketing/Client_Cards/`, `/tableros/`) debe seguir funcionando por el alias. → Tareas 3 y 5.
5. Un `routeUrl()` con un nombre mal escrito en JS falla recién en el navegador. → `php tools/routes.php lint` en cada tarea (escanea `_assets/js`).

---

## File Structure

| Archivo | Responsabilidad |
|---|---|
| `views/layouts/base.html` (modifica) | `window.ROUTES` y `route_url.js` pasan al `<head>`, antes de `tableros_transition.js` |
| `tests/LayoutTest.php` (nuevo) | Fija ese orden de scripts |
| `tests/RoutesFase1Test.php` (nuevo) | Tabla de rutas de la Fase 1: URL nueva, verbos, nombre, alias |
| `routes/xml.php`, `routes/tableros.php`, `routes/humancapital.php`, `routes/marketing.php` (nuevos) | Rutas por módulo |
| `views/supply/bulk_upload.html`, `views/supply/bulk_upload2.html` | Formularios de `xml.generatexml` |
| `views/general/tableros/index.html`, `views/layouts/navbar.html`, `views/layouts/tableros_navbar.html`, `_assets/js/tableros.js`, `_assets/js/tableros_notifications.js`, `_assets/js/tableros_transition.js` | Enlaces de tableros |
| `_assets/js/humancapital/employess.js` | Llamadas de humancapital |
| `views/layouts/sidebar.html`, `views/marketing/findClientTable.html`, `_assets/js/marketing.js` | Enlaces de marketing |
| `docs/superpowers/specs/2026-10-16-router-migracion-design.md` (modifica) | Estado al cerrar la fase |

---

### Task 1: `window.ROUTES` disponible desde el `<head>`

**Files:**
- Create: `tests/LayoutTest.php`
- Modify: `views/layouts/base.html` (las dos líneas agregadas en la Fase 0 junto a `app.js` suben al `<head>`)

**Interfaces:**
- Produces: en cualquier página que extiende `base.html`, `window.ROUTES` y `routeUrl()` existen antes de que corra cualquier otro script del layout.

- [ ] **Step 1: Escribir la prueba que falla**

`tests/LayoutTest.php` (con `declare(strict_types=1)` y `require __DIR__ . '/support.php'`) lee `views/layouts/base.html` y verifica con `strpos`:
- `check('ROUTES se define una sola vez', substr_count($html, 'window.ROUTES') === 1)`
- `check('route_url.js se carga una sola vez', substr_count($html, "js_v('route_url.js')") === 1)`
- `check('ROUTES antes de route_url.js', pos('window.ROUTES') < pos("js_v('route_url.js')"))`
- `check('route_url.js antes de tableros_transition.js', pos("js_v('route_url.js')") < pos("js_v('tableros_transition.js')"))`
- `check('ROUTES conserva el default para páginas sin sesión', str_contains($html, "routes_json|default('{}')|raw"))`

- [ ] **Step 2: Verificar que falla**

Run: `php tests/LayoutTest.php`
Expected: `FALLA route_url.js antes de tableros_transition.js`; el resto `ok`.

- [ ] **Step 3: Mover los scripts**

En `base.html`, quitar las dos líneas `<script>window.ROUTES = …</script>` y `<script src="{{ js_v('route_url.js') }}"></script>` de junto a `app.js` y ponerlas, sin cambios, justo antes de la línea de `tableros_transition.js` (línea 17).

- [ ] **Step 4: Verificar**

Run: `php tests/LayoutTest.php && node tests/route_url_test.js`
Expected: `Todo bien` dos veces.

- [ ] **Step 5: Commit**

```bash
git add tests/LayoutTest.php views/layouts/base.html
git commit -m "fix(router): window.ROUTES y routeUrl() disponibles desde el head" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Prueba de rutas de la Fase 1 y módulo `xml`

**Files:**
- Create: `tests/RoutesFase1Test.php`
- Create: `routes/xml.php`
- Modify: `views/supply/bulk_upload.html:102`, `views/supply/bulk_upload2.html:115`

**Interfaces:**
- Consumes: `Router::load/match/reverse/reset`, `routes.php` (Fase 0).
- Produces: `tests/RoutesFase1Test.php` con un arreglo `$rows`; las tareas 3–5 **agregan filas** a ese arreglo. Cada fila:
  `[nombre, verbos permitidos, URL nueva de ejemplo, handler [Clase, método], args esperados, parámetros para reverse, alias de ejemplo o null]`.
  Para cada fila la prueba verifica:
  1. `Router::match(URL nueva, verbo)` coincide para **cada** verbo permitido, con ese handler, `is_alias === false` y esos args;
  2. si los verbos no incluyen `POST`, `match(URL nueva, 'POST')['error'] === 405`; si no incluyen `GET`, lo mismo con `GET`;
  3. `Router::reverse(nombre, parámetros) === '/' . URL nueva` (la llamada va dentro de `try { … } catch (InvalidArgumentException) { false }` para que una ruta que falta se reporte como `FALLA` y no aborte el resto de la prueba);
  4. si hay alias: `match(alias, primer verbo)` da el mismo handler, `is_alias === true` y los mismos args.
  Además, una sola vez: `check('lint limpio', Router::lint() === [])`.

- [ ] **Step 1: Escribir la prueba con la fila de xml**

Fila:

| nombre | verbos | URL nueva | handler | args | reverse | alias |
|---|---|---|---|---|---|---|
| `xml.generatexml` | `['GET']` | `xml/generatexml` | `[Xml::class, 'generatexml']` | `[]` | `[]` | `null` |

(La URL no cambia: `generatexml` no tiene `_`. Sin alias. GET porque los dos formularios son `method="get"`.)

La prueba no instancia controladores: `Xml::class` es solo el string `'Xml'`, no carga la clase.

- [ ] **Step 2: Verificar que falla**

Run: `php tests/RoutesFase1Test.php`
Expected: `FALLA` en las comprobaciones de `xml.generatexml` y el resumen `N falla(s)`, sin errores fatales.

- [ ] **Step 3: Crear `routes/xml.php`**

`group('xml/', [route('generatexml/', [Xml::class, 'generatexml'], name: 'xml.generatexml', methods: ['GET'])])`.

- [ ] **Step 4: Cambiar los formularios**

En ambas vistas: `action="/xml/generatexml"` → `action="{{ url('xml.generatexml') }}"`.

- [ ] **Step 5: Verificar**

Run: `php tests/RoutesFase1Test.php && php tests/RouterTest.php && php tools/routes.php lint`
Expected: `Todo bien`, `Todo bien`, `3 rutas revisadas, sin problemas`.

- [ ] **Step 6: Commit**

```bash
git add tests/RoutesFase1Test.php routes/xml.php views/supply/bulk_upload.html views/supply/bulk_upload2.html
git commit -m "feat(router): rutas de xml y prueba de rutas de la Fase 1" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: `tableros`

**Files:**
- Create: `routes/tableros.php`
- Modify: `tests/RoutesFase1Test.php` (filas), `views/general/tableros/index.html:8,13,14,15`, `views/layouts/navbar.html:54`, `views/layouts/tableros_navbar.html:10`, `_assets/js/tableros.js:536`, `_assets/js/tableros_notifications.js:53`, `_assets/js/tableros_transition.js:4`

**Interfaces:**
- Consumes: `$rows` de la Tarea 2; `routeUrl()` disponible en el `<head>` (Tarea 1).
- Produces: nombres `tableros.index`, `tableros.manage`, `tableros.manage_workspaces`, `tableros.board` (parámetro `id`).

- [ ] **Step 1: Agregar las filas (prueba que falla)**

| nombre | verbos | URL nueva | handler | args | reverse | alias |
|---|---|---|---|---|---|---|
| `tableros.index` | `['GET']` | `tableros/index` | `[Tableros::class, 'index']` | `[]` | `[]` | `tableros/` (con barra final: Review Focus 4) |
| `tableros.manage` | `['GET']` | `tableros/manage` | `[Tableros::class, 'manage']` | `[]` | `[]` | `null` |
| `tableros.manage_workspaces` | `['GET']` | `tableros/manage-workspaces` | `[Tableros::class, 'manage_workspaces']` | `[]` | `[]` | `TABLEROS/Manage_Workspaces` (mayúsculas: Review Focus 4) |
| `tableros.board` | `['GET']` | `tableros/board/15` | `[Tableros::class, 'board']` | `['15']` | `['id' => '15']` | `null` |

Notas: `board` usa `<str:id>` (D13: el JS acepta ids `[A-Za-z0-9_-]+` y el controlador ya valida con `FILTER_VALIDATE_INT` y responde `notFound()`; con `<int:id>` un id raro haría que `routeUrl()` lance en las notificaciones). El alias de `index` es `tableros` porque `tableros.js` hoy hace `pushState` a `/tableros`.

Run: `php tests/RoutesFase1Test.php`
Expected: `FALLA` en las filas de tableros.

- [ ] **Step 2: Crear `routes/tableros.php`**

`group('tableros/', [...])` con las cuatro rutas de la tabla, `methods: ['GET']`; `index` con `alias: ['tableros']`; `manage-workspaces/` con `alias: ['tableros/manage_workspaces']`; `board/<str:id>/`.

- [ ] **Step 3: Cambiar los enlaces**

- Twig: `href="/tableros/index"` → `href="{{ url('tableros.index') }}"` (index.html ×2, navbar.html, tableros_navbar.html); `/tableros/manage` → `url('tableros.manage')`; `/tableros/manage_workspaces` → `url('tableros.manage_workspaces')`.
- `tableros.js:536`: `id ? routeUrl('tableros.board', { id: id }) : routeUrl('tableros.index')`.
- `tableros_notifications.js:53`: `new URL(routeUrl('tableros.board', { id: String(boardId) }), window.location.origin)`.
- `tableros_transition.js:4`: `var destination = routeUrl('tableros.index');`.
- No tocar `tableros.js:526` (lee el pathname; la URL de `board` no cambia).

- [ ] **Step 4: Verificar**

Run: `php tests/RoutesFase1Test.php && php tests/LayoutTest.php && php tools/routes.php lint`
Expected: `Todo bien`, `Todo bien`, `9 rutas revisadas, sin problemas` (home + stations.readings + xml + 4 de tableros + 2 alias de tableros).
Run: `grep -nE "['\"\`]/tableros(/index|/manage|/board|['\"\`])" views _assets/js -r --include=*.html --include=*.js | grep -v tableros_api`
Expected: solo `tableros.js:526` (la regex que lee el pathname).

- [ ] **Step 5: Commit**

```bash
git add routes/tableros.php tests/RoutesFase1Test.php views/general/tableros/index.html views/layouts/navbar.html views/layouts/tableros_navbar.html _assets/js/tableros.js _assets/js/tableros_notifications.js _assets/js/tableros_transition.js
git commit -m "feat(router): rutas de tableros con alias y enlaces por nombre" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: `humancapital`

**Files:**
- Create: `routes/humancapital.php`
- Modify: `tests/RoutesFase1Test.php`, `_assets/js/humancapital/employess.js:43,113`

**Interfaces:**
- Produces: `humancapital.employees`, `humancapital.datatables_employees`, `humancapital.import_file_reporte_empleados`.

- [ ] **Step 1: Agregar las filas (prueba que falla)**

| nombre | verbos | URL nueva | handler | args | reverse | alias |
|---|---|---|---|---|---|---|
| `humancapital.employees` | `['GET']` | `humancapital/employees` | `[HumanCapital::class, 'employees']` | `[]` | `[]` | `null` |
| `humancapital.datatables_employees` | `['POST']` | `humancapital/datatables-employees` | `[HumanCapital::class, 'datatables_employees']` | `[]` | `[]` | `humancapital/datatables_employees` |
| `humancapital.import_file_reporte_empleados` | `['POST']` | `humancapital/import-file-reporte-empleados` | `[HumanCapital::class, 'import_file_reporte_empleados']` | `[]` | `[]` | `humancapital/import_file_reporte_empleados` |

POST porque el DataTable usa `method: 'POST'` y la importación usa `fetch` con `method: 'POST'`. Sin ruta: `procesarEmpleado`, `parseFecha` (D11).

Run: `php tests/RoutesFase1Test.php` → Expected: `FALLA` en las filas de humancapital.

- [ ] **Step 2: Crear `routes/humancapital.php`** con las tres rutas, verbos y alias de la tabla.

- [ ] **Step 3: Cambiar `employess.js`**: `url: '/humancapital/datatables_employees'` → `url: routeUrl('humancapital.datatables_employees')`; `fetch('/humancapital/import_file_reporte_empleados', …)` → `fetch(routeUrl('humancapital.import_file_reporte_empleados'), …)`.

- [ ] **Step 4: Verificar**

Run: `php tests/RoutesFase1Test.php && php tools/routes.php lint`
Expected: `Todo bien` y lint sin problemas.
Run: `grep -rn "/humancapital/" views _assets/js --include=*.html --include=*.js`
Expected: sin resultados.

- [ ] **Step 5: Commit**

```bash
git add routes/humancapital.php tests/RoutesFase1Test.php _assets/js/humancapital/employess.js
git commit -m "feat(router): rutas de humancapital con alias y enlaces por nombre" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: `marketing`

**Files:**
- Create: `routes/marketing.php`
- Modify: `tests/RoutesFase1Test.php`, `views/layouts/sidebar.html:651-664`, `views/marketing/findClientTable.html:72-76,146,182`, `_assets/js/marketing.js:7,24,58`

**Interfaces:**
- Produces: los nombres de la tabla siguiente.

- [ ] **Step 1: Agregar las filas (prueba que falla)**

| nombre | verbos | URL nueva | handler (`Marketing::class`) | args | reverse | alias |
|---|---|---|---|---|---|---|
| `marketing.client_cards` | GET | `marketing/client-cards` | `client_cards` | `[]` | `[]` | `Marketing/Client_Cards/` (Review Focus 4) |
| `marketing.client_cards_nexus` | GET | `marketing/client-cards-nexus` | `client_cards_nexus` | `[]` | `[]` | `marketing/client_cards_nexus` |
| `marketing.mqg_cards` | GET | `marketing/mqg-cards` | `mqg_cards` | `[]` | `[]` | `marketing/mqg_cards` |
| `marketing.findClientForm` | POST | `marketing/find-client-form` | `findClientForm` | `[]` | `[]` | `marketing/findClientForm` |
| `marketing.findClientTable` | POST | `marketing/find-client-table` | `findClientTable` | `[]` | `[]` | `marketing/findClientTable` |
| `marketing.stickerModal` | POST | `marketing/sticker-modal` | `stickerModal` | `[]` | `[]` | `marketing/stickerModal` |
| `marketing.print_card` | GET | `marketing/print-card/ABC123/77` | `print_card` | `['ABC123','77']` | `['card'=>'ABC123','codcli'=>'77']` | `marketing/print_card/ABC123/77` |
| `marketing.print_card_2` | GET | `marketing/print-card-2/ABC123/77` | `print_card_2` | `['ABC123','77']` | idem | `marketing/print_card_2/ABC123/77` |
| `marketing.print_uber_card` | GET | `marketing/print-uber-card/ABC123/77` | `print_uber_card` | `['ABC123','77']` | idem | `marketing/print_uber_card/ABC123/77` |
| `marketing.print_sticker_2` | GET | `marketing/print-sticker-2/ABC123/77` | `print_sticker_2` | `['ABC123','77']` | idem | `marketing/print_sticker_2/ABC123/77` |
| `marketing.print_driver_card` | GET | `marketing/print-driver-card/77/3` | `print_driver_card` | `['77','3']` | `['codcli'=>'77','nrocho'=>'3']` | `marketing/print_driver_card/77/3` |
| `marketing.print_card_nexus` | GET | `marketing/print-card-nexus` | `print_card_nexus` | `[]` | `[]` | `marketing/print_card_nexus` |
| `marketing.print_sticker` | GET | `marketing/print-sticker/station/1/2` | `print_sticker` | `['station','1','2']` | `['station'=>'station','from'=>'1','until'=>'2']` | `marketing/print_sticker/station/1/2/` |

Todos los parámetros son `<str:x>` (D13: tarjetas y códigos de cliente pueden traer letras o ceros a la izquierda). Las `print_*` son GET porque son enlaces `<a target="_blank">`/`window.open`; `findClientForm`/`findClientTable` son `$.ajax` POST; `stickerModal` es `<form method="post">`.

Review Focus 3 (mayúsculas que antes se pasaban a minúsculas): agregar una comprobación aparte: `Router::match('marketing/print_card/ABC123/77', 'GET')['args'] === ['ABC123', '77']` — documenta que la ruta ya no baja a minúsculas. Como el fallback viejo entregaba `abc123`, el checklist manual (Tarea 6) incluye imprimir una tarjeta con letras para confirmar que el modelo (`getVehiclesInfo`) la encuentra igual (SQL Server compara sin distinguir mayúsculas con la intercalación por defecto).

Run: `php tests/RoutesFase1Test.php` → Expected: `FALLA` en las filas de marketing.

- [ ] **Step 2: Crear `routes/marketing.php`** con `group('marketing/', [...])` y las 13 rutas de la tabla, cada una con su alias (las URLs con parámetros declaran el alias con los mismos nombres y orden, p. ej. `alias: ['marketing/print_card/<str:card>/<str:codcli>']`).

- [ ] **Step 3: Cambiar los enlaces**

- `sidebar.html:651-664`: `client_cards`, `client_cards_nexus` (×2), `mqg_cards` → `{{ url('marketing.…') }}`; `print_sticker/station/1/2/` → `{{ url('marketing.print_sticker', {station: 'station', from: '1', until: '2'}) }}`.
- `marketing.js:7,24`: `url: routeUrl('marketing.findClientForm')`, `url: routeUrl('marketing.findClientTable')`; `:58`: `` let url = `${routeUrl('marketing.print_card_nexus')}?number_card=…` `` (la query string queda igual).
- `findClientTable.html:182`: `action="{{ url('marketing.stickerModal') }}"`.
- `findClientTable.html:72-76` y `:146` (**Review Focus 1**): cada enlace con parámetros pasa a `url()`, envuelto en una guarda para que un valor vacío no rompa el render:
  - vehículos: `{% if vehicle.Tarjeta is not empty and vehicle.cod is not empty %}` …los `<li>` con `url('marketing.print_card', {card: vehicle.Tarjeta, codcli: vehicle.cod})` y equivalentes… `{% else %}<li><span class="dropdown-item disabled">Sin tarjeta asignada</span></li>{% endif %}`;
  - choferes: igual con `driver.codcli` y `driver.nrocho`, texto `Sin datos del chofer`.
  - La línea comentada `<!-- … print_sticker … -->` (línea 75) se deja como está.

- [ ] **Step 4: Verificar**

Run: `php tests/RoutesFase1Test.php && php tools/routes.php lint`
Expected: `Todo bien` y lint sin problemas.
Run: `grep -rn "/marketing/" views _assets/js --include=*.html --include=*.js | grep -v "<!--"`
Expected: sin resultados.

- [ ] **Step 5: Commit**

```bash
git add routes/marketing.php tests/RoutesFase1Test.php views/layouts/sidebar.html views/marketing/findClientTable.html _assets/js/marketing.js
git commit -m "feat(router): rutas de marketing con alias y enlaces por nombre" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Cierre de la Fase 1

**Files:**
- Modify: `docs/superpowers/specs/2026-10-16-router-migracion-design.md` (línea de **Estado**)

- [ ] **Step 1: Suite completa**

Run:
```bash
for t in RouterTest RouteLogTest RouteUsageTest ErrorsTest LayoutTest RoutesFase1Test DespachosSqlTest; do php tests/$t.php >/dev/null || echo "FALLÓ $t"; done; node tests/route_url_test.js; php tools/routes.php lint; php tools/routes.php list
```
Expected: ningún `FALLÓ`, `Todo bien` de Node, lint sin problemas y `list` con las rutas de los 6 archivos de `routes/` (home, stations, xml, tableros, humancapital, marketing) y sus alias.

- [ ] **Step 2: Actualizar el spec**

Línea **Estado**: Fase 1 implementada (rutas de `xml`, `tableros`, `humancapital`, `marketing`; `errors` sin rutas por D11), checklist manual pendiente.

- [ ] **Step 3: Checklist manual (con sesión, ambiente de pruebas)** — si algo falla, no pasar a la Fase 2:
- [ ] Supply → carga masiva: botón de generar XML abre el archivo (`xml.generatexml`).
- [ ] Tableros: entrar desde el lanzador del navbar (transición con mascota), cambiar de tablero (la URL queda `/tableros/board/N`), abrir Gestionar tableros y Gestionar espacios, abrir una notificación que lleva a un tablero, y recargar `/tableros` (alias).
- [ ] Capital humano → Empleados: la tabla carga y la importación de archivo responde.
- [ ] Marketing → Tarjetas y engomados: buscar un cliente, ver la tabla, imprimir tarjeta, tarjeta +QueGas, Uber, engomado y tarjeta de chofer; enviar el modal de política de engomados. Probar un cliente con una tarjeta que tenga letras (Review Focus 3).
- [ ] Marketing → Tarjetas nexus, Tarjetas MasQueGas y Etiquetas inventarios abren.
- [ ] Un favorito viejo (`/marketing/client_cards`) sigue abriendo.
- [ ] `php tools/routes.php legacy-hits` muestra entradas `alias` solo de URLs viejas visitadas a propósito.
- [ ] `logs/php_errors.log` sin errores nuevos.

- [ ] **Step 4: Commit**

```bash
git add docs/superpowers/specs/2026-10-16-router-migracion-design.md
git commit -m "docs: estado de la Fase 1 del router" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

## Criterio de salida de la Fase 1

1. Toda la suite pasa y `php tools/routes.php lint` termina con código 0.
2. El checklist manual de la Tarea 6 está completo.
3. Ninguna vista ni JS contiene URLs escritas a mano de `xml`, `tableros`, `humancapital` o `marketing` (salvo la regex de lectura en `tableros.js:526` y el comentario HTML de `findClientTable.html`).
4. `ROUTER_LEGACY_FALLBACK` sigue en `true`.
