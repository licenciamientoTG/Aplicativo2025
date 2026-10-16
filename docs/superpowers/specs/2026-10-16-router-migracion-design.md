# Migración al router explícito (estilo Django) — diseño

**Fecha:** 2026-10-16
**Estado:** Fase 0 planificada, **no ejecutada**. Plan: `docs/superpowers/plans/2026-10-16-router-fase-0.md`.
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

## 5. Fases

Tamaño medido el 2026-10-16: unos **560 métodos públicos** en 22 controladores; `income` (115), `it` (79), `payment` (71), `tableros_api` (64) y `accounting` (45) concentran más de la mitad. Hay más de 1,400 referencias a URLs en `views/` y `_assets/js/`.

| Fase | Alcance | Prueba antes de seguir |
|---|---|---|
| **0** | Infraestructura: alias, `Router::lint()`, `routes/` por módulo, `RouteLog`, 404 real, `routeUrl()` + `window.ROUTES`, `tools/routes.php`. **No migra controladores.** | Pruebas automáticas + checklist manual (ver el plan de la Fase 0) |
| **1** | Módulos chicos: `administration`, `marketing`, `humancapital`, `tableros`, `xml`, `errors` (`stations` y `home` ya están). Aquí se fijan las convenciones de URL, verbos y permisos. | Recorrer a mano las pantallas de cada módulo; `lint` limpio; log sin sorpresas |
| **2** | Medianos: `petrotal`, `direction`, `commercial`, `station_portal`, `arqueo`, `tableros_api` (API JSON: verbos estrictos y errores en JSON). | Igual |
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

## 7. Pendiente de decidir (Fase 1)

Estas decisiones se toman con datos reales del primer módulo, no antes:

- **Estilo de las URLs nuevas:** ¿se traducen al español o se mantienen en inglés como los controladores? ¿Estilo recurso (`/supply/payments/15/`) o estilo acción (`/supply/show-payment/15/`)? Propuesta inicial: inglés, recurso cuando hay un id, kebab-case.
- **Permisos por ruta:** de dónde sacar el id correcto de cada método (hoy cada método consulta `authorized()` por su cuenta).
- **Cuánto tiempo observar el log** antes de retirar un alias (propuesta: 2 semanas sin hits).

## 8. Fuera de alcance

- Cambiar la estructura de los controladores, modelos o vistas (solo se tocan los enlaces).
- Reemplazar `authorized()` o el sistema de permisos.
- Introducir un framework de pruebas o un framework web completo.
- `DataStudio/` (tiene su propio `index.php`, reescrito aparte en `web.config`/`router.php`).
