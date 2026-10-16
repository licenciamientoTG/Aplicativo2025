# Router — Fase 2, bloque 1: `petrotal` y `arqueo`

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rutas explícitas con alias para los 26 métodos de `petrotal` y `arqueo` que usan vistas, JS o redirecciones, y enlaces por nombre, sin que ninguna URL actual deje de funcionar.

**Architecture:** Igual que la Fase 1: `routes/{modulo}.php` + filas en una prueba table-driven + `php tools/routes.php lint`. Primero se extrae a `tests/support.php` el verificador de filas de `RoutesFase1Test.php` para reutilizarlo, y `lint` empieza a revisar también el `routeUrl()` escrito dentro de vistas Twig (D14), porque `arqueo` tiene casi todo su JS inline en `concentrado.html` y `cajas.html`.

**Tech Stack:** PHP 8.4 (scripts de prueba con `tests/support.php`), Twig 3, jQuery/fetch.

**Spec:** `docs/superpowers/specs/2026-10-16-router-migracion-design.md` — D1, D8–D14.

## Global Constraints

- `ROUTER_LEGACY_FALLBACK` sigue en `true`; ninguna URL actual deja de funcionar.
- D8 acción + kebab-case · D9 sin `permission:` (los `authorized()` y `guard()` internos de ambos controladores **no se tocan**) · D10 nombre `modulo.metodo` · D11 solo métodos usados · D12 verbos según el uso real · D13 `<int:x>` solo si el código castea a número · D14 redirecciones PHP con `Router::reverse()`.
- Alias solo cuando la URL cambia (URL idéntica + alias = conflicto en `lint`).
- Un commit por tarea, con `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- `station_portal` **no** se toca en este bloque (lo está modificando otro desarrollador).

## Hallazgos que el plan ya resuelve

- Todos los ids de `arqueo` y `petrotal` se castean con `(int)` en el controlador → `<int:x>` (D13).
- `petrotal::descargar_json($id = null)` y `descargar_acuse($id = null)`: la ruta declara el `id` (único uso real); sin id sigue el fallback.
- `arqueo::exportar($sesion_id)` no se usa en ningún lado → sin ruta (D11).
- El menú enlaza `/arqueo` sin método → `arqueo.index` en `arqueo/index` con alias `arqueo` (mismo criterio que `tableros`).
- `ajaxJson()` de `arqueo.js` siempre hace `POST` con JSON; `fetch()` sin `method` es `GET`.
- Ningún formulario de estas vistas se envía a su propia URL con POST (caso `mqg_cards` de la Fase 1): `caja.html#form_arqueo` no tiene `action` y lo maneja el JS.

## Review Focus

1. `routeUrl('x')` con un nombre mal escrito dentro de un `<script>` de una vista Twig: hoy `lint` solo revisa `routeUrl` en `.js` y fallaría recién en el navegador. → Tarea 1.
2. Una pantalla GET que en realidad también recibe POST (formulario hacia sí misma): daría 405. → revisado en "Hallazgos"; las filas fijan cada verbo y `lint` no lo detecta, así que el checklist manual recorre cada formulario (Tarea 4).
3. `routeUrl('arqueo.ver_imagen_concentrado', {imagen_id: img.id})` con `img.id` vacío o no numérico: `<int:x>` hace que `routeUrl` lance y la galería entera no se pinte. Lo esperado es que se muestren las demás imágenes. → Tarea 3 (guarda en el JS).
4. La redirección de `guardar_fiel` tras un error debe seguir llevando a la configuración FIEL. → Tarea 2 (fila del alias + checklist).
5. Una URL vieja con barra final o mayúsculas (`/Arqueo/`, `/petrotal/Historial/`) sigue funcionando por alias. → Tareas 2 y 3.

---

### Task 1: Verificador de rutas reutilizable y `routeUrl` en vistas

**Files:**
- Modify: `tests/support.php` (nueva función), `tests/RoutesFase1Test.php` (usa la función), `tools/routes.php` (escaneo), `tests/RouteUsageTest.php`

**Interfaces:**
- Produces: `check_routes(array $rows): void` en `tests/support.php`, con el mismo formato de fila y las mismas comprobaciones que hoy hace el `foreach` de `RoutesFase1Test.php` (match por verbo, 405 para GET/POST no permitidos, `reverse` dentro de try, alias). Requiere que `Router` ya esté cargado.
- Produces: `lint` aplica `RouteUsage::inJs()` también a `views/**/*.html` (además de `inTwig`).

- [ ] **Step 1: Prueba que falla (lint)** — en `tests/RouteUsageTest.php` agregar: `check('JS inline en vista: encuentra routeUrl()', RouteUsage::inJs('<script>fetch(routeUrl("arqueo.x"))</script>') === ['arqueo.x'])`. Esta ya pasa (inJs sirve para cualquier texto); la prueba real es de la herramienta: crear temporalmente `views/_lint_tmp.html` con `<script>routeUrl('no.existe')</script>`, correr `php tools/routes.php lint` y comprobar que **no** lo reporta (RED), borrar el archivo.
- [ ] **Step 2: Implementar** — en `tools/routes.php`, la lista `$scan` pasa a `[['views','html','inTwig'], ['views','html','inJs'], ['_assets/js','js','inJs']]`.
- [ ] **Step 3: Verificar** — repetir el archivo temporal: `lint` imprime `views\_lint_tmp.html: usa la ruta 'no.existe' que no existe` y sale con 1; borrar el archivo; `lint` vuelve a `40 rutas revisadas, sin problemas`.
- [ ] **Step 4: Extraer `check_routes()`** — mover el `foreach ($rows …)` de `RoutesFase1Test.php` a `check_routes()` en `support.php`; `RoutesFase1Test.php` queda en `Router::load(...)`, `$rows`, `check_routes($rows)`, la comprobación de mayúsculas, `check('lint limpio', …)` y `finish()`.
- [ ] **Step 5: Verificar que no cambió nada** — `php tests/RoutesFase1Test.php` → `Todo bien` con el **mismo número** de líneas `ok` que antes del cambio (contarlas antes y después con `grep -c '^ok'`).
- [ ] **Step 6: Commit** — `refactor(router): check_routes() reutilizable y lint de routeUrl en vistas`.

---

### Task 2: `petrotal`

**Files:**
- Create: `routes/petrotal.php`, `tests/RoutesFase2Test.php`
- Modify: `views/layouts/sidebar.html:794,800,807`, `views/petrotal/configuracion_fiel.html:17`, `views/petrotal/historial.html:30,33`, `_assets/js/petrotal.js:56,77,99`, `_assets/controllers/petrotal.php:203,210,223,231,240,248`

**Interfaces:**
- Consumes: `check_routes()` (Tarea 1).
- Produces: `tests/RoutesFase2Test.php` (estructura igual a `RoutesFase1Test.php`); la Tarea 3 agrega filas.

- [ ] **Step 1: Filas (prueba que falla)** — handler `Petrotal`:

| nombre | verbos | URL nueva | método | args | reverse | alias de ejemplo |
|---|---|---|---|---|---|---|
| `petrotal.volumetricos` | GET | `petrotal/volumetricos` | `volumetricos` | `[]` | `[]` | `null` |
| `petrotal.preview_json` | GET | `petrotal/preview-json` | `preview_json` | `[]` | `[]` | `petrotal/preview_json` |
| `petrotal.generar_json` | POST | `petrotal/generar-json` | `generar_json` | `[]` | `[]` | `petrotal/generar_json` |
| `petrotal.enviar_reporte` | POST | `petrotal/enviar-reporte` | `enviar_reporte` | `[]` | `[]` | `petrotal/enviar_reporte` |
| `petrotal.configuracion_fiel` | GET | `petrotal/configuracion-fiel` | `configuracion_fiel` | `[]` | `[]` | `petrotal/configuracion_fiel` |
| `petrotal.guardar_fiel` | POST | `petrotal/guardar-fiel` | `guardar_fiel` | `[]` | `[]` | `petrotal/guardar_fiel` |
| `petrotal.historial` | GET | `petrotal/historial` | `historial` | `[]` | `[]` | `null` |
| `petrotal.descargar_json` | GET | `petrotal/descargar-json/15` | `descargar_json` | `[15]` | `['id'=>15]` | `petrotal/descargar_json/15` |
| `petrotal.descargar_acuse` | GET | `petrotal/descargar-acuse/15` | `descargar_acuse` | `[15]` | `['id'=>15]` | `Petrotal/Descargar_Acuse/15/` |

Run `php tests/RoutesFase2Test.php` → `FALLA` en las 9 filas, sin errores fatales.

- [ ] **Step 2: `routes/petrotal.php`** — `group('petrotal/', …)` con la tabla; ids `<int:id>`; cada URL que cambia con su alias (`alias: ['petrotal/descargar_json/<int:id>']`, etc.).
- [ ] **Step 3: Enlaces** — menú (3) y vistas → `{{ url('petrotal.…') }}` (`historial.html` con `{id: envio.Id}`, dentro de los `{% if %}` que ya existen); `petrotal.js` → `routeUrl('petrotal.preview_json')` etc.; `petrotal.php` → las 6 `redirect('/petrotal/configuracion_fiel')` pasan a `redirect(Router::reverse('petrotal.configuracion_fiel'))` (D14).
- [ ] **Step 4: Verificar** — `php tests/RoutesFase2Test.php && php tests/RoutesFase1Test.php && php tools/routes.php lint` → `Todo bien`, `Todo bien`, `56 rutas revisadas, sin problemas` (40 previas + 9 rutas + 7 alias); `php -l _assets/controllers/petrotal.php`; `grep -rn "/petrotal/" views _assets/js _assets/controllers --include=*.html --include=*.js --include=*.php` → sin resultados.
- [ ] **Step 5: Commit** — `feat(router): rutas de petrotal con alias y enlaces por nombre`.

---

### Task 3: `arqueo`

**Files:**
- Create: `routes/arqueo.php`
- Modify: `tests/RoutesFase2Test.php`, `views/layouts/sidebar.html:258`, `views/arqueo/index.html:143,148,167`, `views/arqueo/cajas.html:20,91,117`, `views/arqueo/caja.html:91`, `views/arqueo/concentrado.html:74,459,551,577,593,629,649`, `_assets/js/arqueo.js:34,49,67,262`

- [ ] **Step 1: Filas (prueba que falla)** — handler `Arqueo`; todos los ids `<int:x>`, args como int:

| nombre | verbos | URL nueva | método | args | alias de ejemplo |
|---|---|---|---|---|---|
| `arqueo.index` | GET | `arqueo/index` | `index` | `[]` | `Arqueo/` |
| `arqueo.cajas` | GET | `arqueo/cajas/7` | `cajas` (`sesion_id`) | `[7]` | `null` |
| `arqueo.asignar_caja` | POST | `arqueo/asignar-caja` | `asignar_caja` | `[]` | `arqueo/asignar_caja` |
| `arqueo.crear_sesion` | POST | `arqueo/crear-sesion` | `crear_sesion` | `[]` | `arqueo/crear_sesion` |
| `arqueo.abrir` | POST | `arqueo/abrir/7` | `abrir` (`sesion_id`) | `[7]` | `null` |
| `arqueo.cerrar` | POST | `arqueo/cerrar/7` | `cerrar` (`sesion_id`) | `[7]` | `null` |
| `arqueo.caja` | GET | `arqueo/caja/9` | `caja` (`caja_id`) | `[9]` | `null` |
| `arqueo.imprimir_caja` | GET | `arqueo/imprimir-caja/9` | `imprimir_caja` (`caja_id`) | `[9]` | `arqueo/imprimir_caja/9` |
| `arqueo.guardar_caja` | POST | `arqueo/guardar-caja/9` | `guardar_caja` (`caja_id`) | `[9]` | `arqueo/guardar_caja/9` |
| `arqueo.concentrado` | GET | `arqueo/concentrado/7` | `concentrado` (`sesion_id`) | `[7]` | `null` |
| `arqueo.auditoria` | GET | `arqueo/auditoria/7` | `auditoria` (`sesion_id`) | `[7]` | `null` |
| `arqueo.guardar_concentrado_extra` | POST | `arqueo/guardar-concentrado-extra` | `guardar_concentrado_extra` | `[]` | `arqueo/guardar_concentrado_extra` |
| `arqueo.subir_imagen_concentrado` | POST | `arqueo/subir-imagen-concentrado` | `subir_imagen_concentrado` | `[]` | `arqueo/subir_imagen_concentrado` |
| `arqueo.imagenes_concentrado` | GET | `arqueo/imagenes-concentrado` | `imagenes_concentrado` | `[]` | `arqueo/imagenes_concentrado` |
| `arqueo.ver_imagen_concentrado` | GET | `arqueo/ver-imagen-concentrado/3` | `ver_imagen_concentrado` (`imagen_id`) | `[3]` | `arqueo/ver_imagen_concentrado/3` |
| `arqueo.eliminar_imagen_concentrado` | POST | `arqueo/eliminar-imagen-concentrado` | `eliminar_imagen_concentrado` | `[]` | `arqueo/eliminar_imagen_concentrado` |
| `arqueo.exportar_cajas` | GET | `arqueo/exportar-cajas/7` | `exportar_cajas` (`sesion_id`) | `[7]` | `arqueo/exportar_cajas/7` |

`reverse` usa el nombre del parámetro del método (`['sesion_id'=>7]`, `['caja_id'=>9]`, `['imagen_id'=>3]`). Run → `FALLA` en las 17 filas.

- [ ] **Step 2: `routes/arqueo.php`** — `group('arqueo/', …)`; `index` con `alias: ['arqueo']`; cada URL con `_` con su alias absoluto y los mismos parámetros.
- [ ] **Step 3: Enlaces**
  - Twig (`href`, menú): `{{ url('arqueo.cajas', {sesion_id: s.id}) }}`, etc.
  - JS inline en vistas sin parámetros dinámicos: `fetch('{{ url('arqueo.guardar_concentrado_extra') }}', …)`; la query de `imagenes_concentrado` queda igual detrás de la URL: `fetch('{{ url('arqueo.imagenes_concentrado') }}?sesion_id={{ sesion.id }}&sucursal_id=' + imgSucursalId, …)`.
  - JS con id de JS: `routeUrl('arqueo.abrir', {sesion_id: id})`, `routeUrl('arqueo.cerrar', {sesion_id: id})`, `routeUrl('arqueo.guardar_caja', {caja_id: cajaId})`, `routeUrl('arqueo.crear_sesion')`.
  - **Review Focus 3** (`concentrado.html:593`): dentro del `forEach`, saltar la imagen si `!/^[0-9]+$/.test(String(img.id))` antes de llamar a `routeUrl('arqueo.ver_imagen_concentrado', {imagen_id: img.id})`, para que una imagen rara no impida pintar las demás.
- [ ] **Step 4: Verificar** — `php tests/RoutesFase2Test.php && php tools/routes.php lint` → `Todo bien` y `84 rutas revisadas, sin problemas` (56 previas + 17 rutas + 11 alias); `node --check _assets/js/arqueo.js`; `grep -rn "/arqueo" views _assets/js --include=*.html --include=*.js` → sin resultados.
- [ ] **Step 5: Commit** — `feat(router): rutas de arqueo con alias y enlaces por nombre`.

---

### Task 4: Cierre del bloque

- [ ] **Step 1: Suite** — `for t in RouterTest RouteLogTest RouteUsageTest ErrorsTest LayoutTest RoutesFase1Test RoutesFase2Test DespachosSqlTest; do php tests/$t.php >/dev/null || echo "FALLÓ $t"; done; node tests/route_url_test.js; php tools/routes.php lint` → ningún `FALLÓ`, lint 0.
- [ ] **Step 2: Spec** — línea **Estado**: bloque 1 de la Fase 2 implementado, checklist pendiente.
- [ ] **Step 3: Checklist manual (con sesión)** — Petrotal: Volumétricos (vista previa, generar JSON, enviar), Historial (descargar JSON y acuse), Configuración FIEL (guardar con y sin error: debe volver a la configuración). Arqueo: entrar desde el menú, crear/abrir/cerrar sesión, asignar caja, capturar y guardar una caja, imprimirla, concentrado (guardar extra, subir/ver/eliminar imagen), auditoría, exportar cajas. `legacy-hits` sin entradas inesperadas.
- [ ] **Step 4: Commit** — `docs: estado del bloque 1 de la Fase 2`.
