# Migración al router explícito (estilo Django) — diseño

**Fecha:** 2026-10-16
**Estado:** Fase 0 implementada y en `main` (commit `4041cbe2`); su checklist manual con sesión sigue pendiente. Fase 1 implementada en la rama `router-fase-1` (rutas de `xml`, `tableros`, `humancapital` y `marketing`; `errors` sin rutas por D11), con su checklist manual pendiente; plan: `docs/superpowers/plans/2026-10-16-router-fase-1.md`. Fase 2, bloque 1 (`petrotal` + `arqueo`) implementado en la rama `router-fase-2-bloque-1`, checklist manual pendiente; plan: `docs/superpowers/plans/2026-10-16-router-fase-2-bloque-1.md`. Bloque 2 (`commercial`) implementado en la rama `router-fase-2-bloque-2`, checklist manual pendiente; plan: `docs/superpowers/plans/2026-10-16-router-fase-2-bloque-2.md`. Bloque 3 (`direction`) implementado en la rama `router-fase-2-bloque-3`, checklist manual pendiente; plan: `docs/superpowers/plans/2026-10-16-router-fase-2-bloque-3.md`. Plan de la Fase 0: `docs/superpowers/plans/2026-10-16-router-fase-0.md`.
**Ya implementado:** el router base (`_assets/classes/Router.class.php`, `routes.php`, función Twig `url()`), commits `90d58f0f` y `b774d567`, con `stations` y `home` migrados como ejemplo.

## 1. Problema

El proyecto resuelve las URLs solo por convención: `/controlador/método/param1/param2` ejecuta `Controlador::método('param1', 'param2')` (ver `index.php`). Consecuencias:

- **Todo método público de un controlador es accesible por URL.** No hay lista de métodos permitidos; la protección depende de que cada método llame a `authorized($id)`.
- Los parámetros llegan siempre como strings, en minúsculas (se hace `strtolower` a toda la URL).
- No se restringe el verbo HTTP (GET/POST) por ruta.
- Las URLs están escritas a mano en las vistas y el JS (más de 1,400 referencias), así que cambiar una URL obliga a buscarla en todos lados.

## 2. Objetivo

Tener rutas **explícitas, tipadas y con nombre**, como en Django, y migrar todos los controladores a ellas **poco a poco, sin romper nada en producción**. El responsable del proyecto eligió el alcance más amplio: **URLs limpias y nombradas** (no solo cerrar el hueco de seguridad), reemplazando los enlaces escritos a mano por `url()` en Twig y `routeUrl()` en JS.

Criterios de éxito de la migración completa:

1. Todo controlador tiene sus rutas declaradas en `routes/{controlador}.php`, con verbo(s) y permiso.
2. Ningún enlace de vistas ni de JS usa una URL escrita a mano de un módulo migrado.
3. `ROUTER_LEGACY_FALLBACK` está en `false`: solo es accesible lo declarado.
4. En ningún momento intermedio dejó de funcionar una URL existente (favoritos de las estaciones incluidos).

## 3. Cómo funciona el router (ya existe)

```php
route('supply/payments/<int:id>/', [Supply::class, 'show_payment'],
      name: 'supply.payment', methods: ['GET'], permission: 112);
group('supply/', [ ...rutas... ], ['permission' => 112]);   // el grupo hereda opciones
```

- `index.php` evalúa el router **antes** del login para respetar `public: true`; si no hay coincidencia, usa la convención antigua mientras `ROUTER_LEGACY_FALLBACK` sea `true`.
- Convertidores: `<int:x>` (se castea a int), `<str:x>` (sin `/`), `<slug:x>`, `<path:x>` (puede traer `/`).
- Coincidencia sin distinguir mayúsculas en la parte literal; barras finales opcionales.
- Verbo no permitido → 405 con `Allow`; sin permiso → 403.
- Enlaces: `{{ url('supply.payment', {id: 15}) }}` en Twig (equivalente a `reverse()` de Django).

## 4. Decisiones tomadas

| # | Decisión | Por qué |
|---|---|---|
| D1 | Los **alias** son URLs absolutas viejas que apuntan al mismo método que la ruta nueva (`route(..., alias: ['supply/show_payment/<int:id>'])`). | Los enlaces viejos (vistas, JS, favoritos) nunca se rompen mientras se migra. Se retiran al final. |
| D2 | **Cada petición por alias o por el fallback se registra** en `logs/legacy_routes.log`. | Es la evidencia para retirar un alias o apagar el fallback con seguridad. |
| D3 | **Un archivo de rutas por controlador** (`routes/*.php`), cargados en **orden alfabético; gana la primera coincidencia.** Dentro de un archivo, lo específico (`pay/new`) va antes que lo general (`pay/<str:id>`). | Cada módulo es un commit chico y sin conflictos de merge. El linter detecta rutas tapadas, así que el orden no depende de la memoria de nadie. |
| D4 | El helper de JS se llama **`routeUrl()`**; el de Twig, **`url()`**. | `url(...)` ya aparece en CSS y JS con otro sentido; un nombre propio evita falsos positivos del linter. |
| D5 | El **404 real** reemplaza al `var_dump`: estado 404, página HTML sencilla o JSON para peticiones AJAX. | El comportamiento anterior respondía 200 con un volcado de texto; el JS no podía distinguir un error. |
| D6 | Las rutas con nombre usan `modulo.accion` (`supply.payment`). Los parámetros van en la URL cuando identifican un recurso (`<int:id>`). | Convención simple y mecánica. El estilo exacto de las URLs nuevas se define en la Fase 1 (ver sección 7). |
| D7 | Se migra **por módulo**, empezando por los más chicos, y se observa el log unos días antes de pasar al siguiente. | Sin framework de pruebas ni ambiente de staging garantizado, la red de seguridad es ir poco a poco. |
| D8 | **URLs en estilo acción y kebab-case** para toda la migración: el método `print_card` queda en `/marketing/print-card/<card>/<codcli>`. Un método = una URL; camelCase también pasa a kebab (`findClientForm` → `find-client-form`, decisión del ejecutor). (Decidido por el responsable el 2026-10-16.) | Migración mecánica, encaja con controladores escritos como acciones; las ganancias del router (tipos, verbos, nombres, alias) no dependen del estilo. |
| D9 | **Todavía no se agrega `permission:` a las rutas.** El acceso queda como hoy (menú de `sidebar.html` y los chequeos propios de cada controlador). (Decidido por el responsable el 2026-10-16.) | Cerrar el hueco de permisos cambia quién puede entrar; se decide aparte. |
| D10 | El **nombre de ruta** es `modulo.metodo` con el nombre del método tal cual (`marketing.print_card`). | Del nombre se llega al método sin buscar. |
| D11 | **Solo llevan ruta los métodos que usa alguna vista o JS** (o un enlace externo conocido). Las funciones internas que hoy son públicas por accidente no reciben ruta nueva; siguen alcanzables por el fallback hasta la Fase 5. | No se publican a propósito métodos que nunca debieron ser URLs. |
| D12 | **Verbos según el uso real** (formularios, `$.post`, `fetch` con `method`). Si el uso no es claro: `['GET', 'POST']`. | Restringir de más rompería pantallas sin aviso. |
| D14 | Las **redirecciones en PHP** hacia un módulo migrado (`redirect('/petrotal/configuracion_fiel')`) también pasan a `Router::reverse('petrotal.configuracion_fiel')`, y el `routeUrl()` escrito dentro de `<script>` en vistas Twig también lo revisa `lint`. (Decisión del ejecutor, 2026-10-16.) | Son URLs escritas a mano igual que las de las vistas; sin esto quedarían fuera del linter. |
| D13 | **`<int:x>` solo si el código trata el valor como número**; si hay duda, `<str:x>`. Ojo: el fallback viejo pasaba toda la URL a minúsculas y la ruta nueva no; se revisa método por método si un parámetro depende de eso. | Evita 404 por un tipo demasiado estricto y cambios silenciosos de valor. |

## 5. Fases

Tamaño medido el 2026-10-16 (corregido el mismo día): unos **985 métodos accesibles por URL** en 22 controladores (sin contar `__construct`). La primera medición (~560) solo contaba los métodos con `public` explícito; en PHP un método sin modificador también es público y el fallback lo expone. Los más grandes: `income` (206), `operations` (131), `payment` (100), `supply` (84), `it` (78), `accounting` (70), `tableros_api` (63) y `administration` (61). Avance al cerrar el bloque 3 de la Fase 2: ~128 métodos resueltos (≈13 %), 107 rutas con nombre. Hay más de 1,400 referencias a URLs en `views/` y `_assets/js/`.

| Fase | Alcance | Prueba antes de seguir |
|---|---|---|
| **0** | Infraestructura: alias, `Router::lint()`, `routes/` por módulo, `RouteLog`, 404 real, `routeUrl()` + `window.ROUTES`, `tools/routes.php`. **No migra controladores.** | Pruebas automáticas + checklist manual (ver el plan de la Fase 0) |
| **1** | Módulos chicos: `xml`, `errors`, `tableros`, `humancapital`, `marketing`, en ese orden y un commit por módulo (`stations` y `home` ya están). Unos 25 métodos. | Recorrer a mano las pantallas de cada módulo; `lint` limpio; log sin sorpresas |
| **2** | Medianos, ~240 métodos accesibles, **un plan por bloque**: (1) `petrotal` + `arqueo`; (2) `commercial`; (3) `direction`; (4) `administration` (62, en sub-bloques); (5) `tableros_api` (API JSON: verbos estrictos y errores en JSON); (6) `station_portal` al final, porque otro desarrollador lo está modificando activamente (2026-10-16). | Igual |
| **3** | Grandes: `merma`, `tesoreria`, `operations`, `supply`, `accounting`, en bloques de 15 a 20 métodos por commit. | Igual |
| **4** | Gigantes: `payment` (71), `it` (79), `income` (115), por bloques funcionales, con una semana de observación del log entre bloques. | Igual |
| **5** | Retirar lo viejo: quitar los alias que ya no aparecen en el log y poner `ROUTER_LEGACY_FALLBACK = false`. | `legacy-hits` vacío durante el periodo acordado |

### Ciclo que se repite por módulo (Fases 1 a 4)

1. Listar los métodos públicos del controlador.
2. Proponer una tabla de URL nueva, nombre, verbos y permiso por método, y **aprobarla** antes de escribir código.
3. Crear `routes/{modulo}.php` con alias hacia las URLs viejas.
4. Reemplazar los enlaces en vistas y JS por `url()` / `routeUrl()`.
5. Correr `php tools/routes.php lint`, probar el módulo a mano y hacer commit.
6. Desplegar y observar `php tools/routes.php legacy-hits` unos días.

## 6. Riesgos y cómo se controlan

| Riesgo | Control |
|---|---|
| Romper una URL que usa una estación o un favorito | Alias (D1) + fallback encendido hasta la Fase 5 |
| Ruta que apunta a un método inexistente o con otro número de parámetros | `Router::lint(true)` (revisa clase, método y parámetros) |
| Ruta tapada por otra anterior, URL duplicada o nombre repetido | `Router::lint()` (D3) |
| `url('x')` o `routeUrl('x')` con un nombre que no existe | `tools/routes.php lint` escanea `views/` y `_assets/js/` |
| Un método que hoy se protege por cuenta propia deja de protegerse | La ruta no quita ninguna comprobación existente de `authorized()`; el `permission:` de la ruta se **agrega** como segunda barrera. No se borran los chequeos internos hasta la Fase 5. |
| Peticiones AJAX que esperaban 200 y ahora reciben 404 (D5) | Solo ocurre con URLs que de todos modos no existían; se revisa en el checklist de cada fase |
| No hay framework de pruebas ni staging garantizado | Pruebas en scripts PHP/Node sin dependencias (`tests/`) + checklist manual por fase |

## 7. Decisiones de la Fase 1 (2026-10-16)

Tomadas con los datos reales de los controladores:

- **Hallazgo:** en PHP un método sin modificador es público. `administration` tiene ~58 métodos alcanzables por URL (incluidas funciones internas como `getMondayFromWeek`), por eso pasó a la Fase 2. Ningún controlador de la Fase 1 llama a `authorized()`; el único control es el menú (`sidebar.html`), salvo `tableros`, que tiene su propio `requireAccess()`.
- **Estilo de URL:** acción + kebab-case, en inglés como los controladores (D8).
- **Permisos por ruta:** pospuesto (D9). Cuando se retome, la fuente natural es el `authorized(n)` de la sección del menú que enlaza cada pantalla.
- **Observación del log:** un alias se retira tras **2 semanas sin hits** en `legacy-hits` (aplica en la Fase 5).
- **Por módulo**, antes de escribir código se presenta la tabla URL nueva / nombre / verbos / parámetros / alias y se aprueba (el plan de cada fase la trae ya propuesta).
- Los enlaces del menú (`views/layouts/sidebar.html`) de un módulo migrado también pasan a `url()`.
- **El inventario de cada módulo busca, además de `/modulo/metodo`:** enlaces a la raíz del módulo (`href="/arqueo"`), URLs relativas (`action="graph_prices"`), URLs armadas con globales de Twig (`{{ APP_NAME ~ CONTROLLER }}/metodo`), redirecciones PHP y formularios que hacen POST a su propia URL (la ruta debe aceptar POST). Los cinco casos aparecieron entre la Fase 1 y el bloque 3 de la Fase 2.

## 8. Fuera de alcance

- Cambiar la estructura de los controladores, modelos o vistas (solo se tocan los enlaces).
- Reemplazar `authorized()` o el sistema de permisos.
- Introducir un framework de pruebas o un framework web completo.
- `DataStudio/` (tiene su propio `index.php`, reescrito aparte en `web.config`/`router.php`).
