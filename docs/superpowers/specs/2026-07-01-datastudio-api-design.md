# API `DataStudio/` para gráficas en Google Looker Studio (Data Studio)

## Contexto

TotalGas necesita exponer datos (empezando por ventas) para graficarlos en Looker Studio. Looker Studio no tiene un conector genérico nativo para REST/JSON propio — la forma soportada por Google de conectar una API propia es un **Community Connector** (script de Apps Script) que llama a nuestra API y traduce la respuesta al contrato `getSchema`/`getData` que Looker Studio espera.

Requisito explícito del usuario: esta API debe vivir en una carpeta raíz nueva y **completamente separada** de las carpetas MVC existentes (`_assets/`, `views/`), sin depender de `index.php`, los controllers ni los models de la app.

Hallazgo clave de la regla de reescritura actual (`web.config`):
```xml
<add input="{REQUEST_FILENAME}" matchType="IsFile" negate="true" />
<add input="{REQUEST_FILENAME}" matchType="IsDirectory" negate="true" />
```
Solo reescribe a `index.php` cuando la ruta pedida **no** corresponde a un archivo o carpeta físicos existentes. Una carpeta real `DataStudio/` con sus propios archivos PHP se sirve directo, sin pasar por el front controller de la app — esto ya da la separación pedida sin tocar `index.php`.

## Decisión de diseño

Estructura de "mini front-controller" propio dentro de `DataStudio/`: un único punto de entrada (`DataStudio/index.php`) que centraliza autenticación, formato de respuesta y manejo de errores, y despacha a archivos de reporte individuales que solo contienen la consulta de datos. Se descartaron dos alternativas:
- **Script plano por reporte** (cada archivo se autentica y arma su propio JSON): más rápido al inicio, pero repite boilerplate de auth/formato en cada reporte nuevo y no da un lugar único para versionar el contrato.
- **Adaptador sobre los Models existentes** (`_assets/models/`): evita duplicar lógica de negocio, pero rompe la separación total pedida — un cambio en un Model de la app (constructor, dependencia de `$twig`, etc.) podría romper la API sin que se note hasta que falle.

Primer módulo a implementar: **Ventas** (dominio de `income.php`/`commercial.php`), con SQL propia dentro de `DataStudio/`, no reutilizando `IncomeModel`.

## Comportamiento

### Layout de carpetas

```
DataStudio/
  index.php              # front-controller propio: valida API key, arma envelope JSON, despacha
  _bootstrap.php          # constantes propias + conexión BD (reusa MySqlPdoHandler::getInstance())
  lib/
    ApiKeyAuth.php        # valida header X-API-Key
    JsonResponse.php      # helpers success()/error() con envelope consistente
  v1/
    reports/
      ventas.php          # primer reporte
```

### Ruteo y URLs amigables

Se agrega **una regla nueva** en `web.config`, acotada solo a `/DataStudio/*`, colocada **antes** de la regla catch-all existente (para no alterar el comportamiento del resto del sitio):

```xml
<rule name="DataStudio API rewrite" stopProcessing="true">
  <match url="^DataStudio/([^/]+)/([^/]+)$" />
  <conditions logicalGrouping="MatchAll">
    <add input="{REQUEST_FILENAME}" matchType="IsFile" negate="true" />
  </conditions>
  <action type="Rewrite" url="DataStudio/index.php?version={R:1}&amp;report={R:2}" />
</rule>
```
Mapea `/DataStudio/v1/ventas` → `DataStudio/index.php?version=v1&report=ventas`: primer segmento después de `DataStudio/` es siempre la versión (`v1`), segundo segmento es siempre el nombre del reporte (debe coincidir con el nombre de archivo en `v1/reports/`, sin extensión). No se soportan segmentos adicionales (parámetros extra van por querystring, ej. `?desde=&hasta=`).

Para desarrollo local (`router.php`, servidor embebido de PHP) se necesita una rama equivalente que detecte el prefijo `DataStudio/` en la ruta y simule la misma reescritura antes de caer al `require` del `index.php` raíz de la app — de otro modo, en local esas URLs amigables caerían en el ruteo de controllers/methods de la app y darían 404.

### Autenticación

Header `X-API-Key`, validado en `DataStudio/index.php` (vía `lib/ApiKeyAuth.php`) contra un secreto definido en `_bootstrap.php`, siguiendo el mismo patrón que `CRON_SECRET` ya usado en `cron/`. Si falta o no coincide, responde `401` con el envelope de error estándar **antes** de tocar cualquier archivo de `v1/reports/`.

### Contrato de respuesta

```json
{
  "schema": [
    {"name": "estacion", "dataType": "STRING"},
    {"name": "fecha", "dataType": "DATE"},
    {"name": "monto", "dataType": "NUMBER"}
  ],
  "rows": [
    {"estacion": "...", "fecha": "2026-06-01", "monto": 12345.67}
  ]
}
```
Cada archivo en `v1/reports/` retorna únicamente `[$schema, $rows]` (o los asigna a variables que `index.php` recoge tras el `require`). `DataStudio/index.php` es el único lugar que:
- arma el JSON final con este envelope,
- pone los headers de respuesta (`Content-Type: application/json`),
- captura excepciones y las traduce a error controlado.

Este formato existe pensando en el Community Connector: el Apps Script recibe siempre la misma forma sin importar qué reporte llamó, así `getSchema()` y `getData()` del connector pueden mapear genéricamente `schema` → definición de campos y `rows` → filas, sin lógica distinta por reporte.

### Primer reporte: Ventas

`v1/reports/ventas.php` — SQL directa contra la BD correspondiente (no vía `IncomeModel`), con filtros de rango de fecha por querystring (`?desde=&hasta=`), pensado para alimentar gráficas de ventas por estación y periodo. Los campos exactos (dimensiones/métricas) se definen en la fase de implementación a partir de lo que ya usa `income.php`/`commercial.php` como referencia de negocio, sin importar su código.

### Manejo de errores

`DataStudio/index.php` envuelve el `require` del archivo de reporte en try/catch. Cualquier excepción (SQL, validación, etc.) se traduce a:
```json
{"error": "mensaje"}
```
con el código HTTP apropiado (400/401/404/500), nunca un fatal error de PHP crudo — un fatal sin capturar rompería el parseo JSON que hace el Community Connector.

### Fuera de alcance (por ahora)

- Autenticación por usuario/estación con scopes distintos (se usa un solo API key global para esta primera fase).
- Reportes de otros módulos (supply, accounting, operaciones) — se agregan como archivos nuevos en `v1/reports/` una vez validado el patrón con Ventas.
- El propio Community Connector (Apps Script del lado de Google) — esta spec cubre solo la API que el connector va a consumir.
