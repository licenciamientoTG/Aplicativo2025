# Paralelizar consulta de tipo de cambio (`/administration/exchange_rate`) vía ApiER

**Fecha:** 2026-08-05
**Estado:** Aprobado, pendiente de implementación

## Contexto

La vista `/administration/exchange_rate` muestra el último tipo de cambio (USD) registrado
en cada una de las ~34 estaciones fronterizas/foráneas listadas en
`CotizacionesModel::exchange_rate_stations()`.

Hoy, `CotizacionesModel::get_exchange_rates()` recorre esas estaciones **secuencialmente**:
por cada una hace un `fsockopen` (timeout 0.5s) para verificar que el puerto SQL Server esté
abierto y, si responde, ejecuta un `OPENQUERY` contra el linked server de esa estación usando
la única conexión PDO (Singleton `MySqlPdoHandler`) que tiene la app. El tiempo total de carga
de la tabla es la suma de las 34 esperas, por lo que una sola estación lenta o caída penaliza
a toda la vista.

ApiER (`api/TG_php/views.py`) ya resuelve este mismo problema para otros módulos (p. ej.
`estacion_documentos_compra`, `estacion_porcentaje`) lanzando las consultas por estación en
paralelo con `ThreadPoolExecutor(max_workers=40)`, cada una en su propio hilo con su propia
conexión pyodbc hacia el servidor central (`192.168.0.6`, BD `TG`), ejecutando el mismo
`OPENQUERY` que hoy corre PHP pero de forma concurrente.

## Objetivo

Mover la lectura masiva de "último tipo de cambio por estación" a un endpoint nuevo en ApiER
que ejecute las 34 consultas en paralelo, y que PHP consuma con una sola llamada HTTP. El
tiempo total pasa de "suma de todas las esperas" a "la espera de la estación más lenta".

Fuera de alcance: los flujos de alta/edición/borrado individual (`exchange_rate_process`,
`update_exchange`, `delete_exchange`) no cambian — el problema de velocidad es solo la lectura
masiva de 34 estaciones.

## Arquitectura

```
PHP (Administration::datatable_exchange_rate)
  → CotizacionesModel::get_exchange_rates()
      → arma payload con las 34 estaciones (codgas, linked_server, short_db,
        station_name, no_station, description) desde exchange_rate_stations()
      → POST http://192.168.0.109:82/api/TG_php/exchange_rates/
          body: JSON { "estaciones": [ {...}, ... ] }
      ← JSON: [ {codgas, station_name, no_station, description, Fecha,
                 hra_format, ctz, ...}, ... ]
  → devuelve el array tal cual a datatable_exchange_rate(), que arma $data
    exactamente igual que hoy
```

- **PHP sigue siendo la única fuente de verdad** de la lista de 34 estaciones
  (`exchange_rate_stations()` no se toca ni se duplica en Python). En cada request se envía
  completa a ApiER.
- **ApiER es "tonto"**: no conoce la lista de estaciones de antemano, solo ejecuta el query
  que le pasen, por cada estación que le pasen, en paralelo.
- El endpoint no lleva autenticación explícita — mismo criterio que el resto de `TG_php`
  (`estacion_documentos_compra`, `estacion_porcentaje`, etc. tampoco declaran
  `permission_classes`/`authentication_classes`; la protección es de red/IP interna).

## Componentes

### 1. `api/modelos/cotizaciones_estaciones.py` (nuevo)

Clase `CotizacionesEstaciones(conn_str=CONTROLGASTG_CONN_STR)`, mismo patrón que
`EstacionDespachos`.

`get_last_exchange(linked_server, short_db, codgas, station_name, no_station, description)`:
- Arma el mismo CTE con `OPENQUERY` que hoy vive en
  `CotizacionesModel::get_exchange_rates()` (PHP), escapando comillas simples de los literales
  igual que hace hoy el PHP.
- Ejecuta con `pyodbc.connect(self.conn_str)` dentro de `try/except pyodbc.Error`, devolviendo
  `None` si falla (mismo criterio tolerante a fallos que usan `comparacion_despachos`, etc.).
- Devuelve un `dict` con las columnas que hoy consume
  `Administration::datatable_exchange_rate()`: `codmda, codgas, fch, hra_format, hra, ctz,
  ctzcom, ctzven, codpza, codcpo, logusu, logfch, lognew, station_name, no_station,
  description, Fecha`.
- Se elimina el pre-chequeo `fsockopen`: no aporta nada corriendo en paralelo (el timeout ya
  no se acumula), y si la estación está caída el propio `OPENQUERY`/pyodbc falla y el `except`
  la omite igual que hoy.

### 2. `api/TG_php/views.py` (nueva vista)

```python
@api_view(['POST'])
def exchange_rates_view(request):
    estaciones = json.loads(request.body).get('estaciones', [])
    if not estaciones:
        return Response({"detail": "Falta la lista de estaciones"}, status=status.HTTP_400_BAD_REQUEST)

    cotizaciones = CotizacionesEstaciones()
    resultados = []
    with ThreadPoolExecutor(max_workers=40) as executor:
        future_to_est = {
            executor.submit(
                cotizaciones.get_last_exchange,
                est["linked_server"], est["short_db"], est["codgas"],
                est["station_name"], est["no_station"], est["description"]
            ): est
            for est in estaciones
        }
        for future in as_completed(future_to_est):
            res = future.result()
            if res:
                resultados.append(res)
    return Response(resultados, status=status.HTTP_200_OK)
```

Se lee el body como JSON crudo (`json.loads(request.body)`), no `request.data.get(...)`
form-urlencoded: la carga es una lista de 34 diccionarios y no hay precedente en el proyecto
de mandar arrays anidados de objetos por `http_build_query`: todos los endpoints existentes de
`TG_php` reciben campos planos. JSON evita ambigüedad de parseo en ese caso.

### 3. `api/urls.py`

- Import de `exchange_rates_view` junto a los demás imports de `TG_php`.
- `path('exchange_rates/', exchange_rates_view)` junto a las demás rutas de `TG_php`.

### 4. `CotizacionesModel::get_exchange_rates()` (PHP, reemplazo del cuerpo actual)

- Arma el payload `estaciones` a partir de `exchange_rate_stations()` +
  `$this->linked_server` + `$this->short_databases` (mismos datos que hoy usa el `foreach`,
  pero sin ejecutar nada localmente).
- Hace un único `POST` vía `curl` a `http://192.168.0.109:82/api/TG_php/exchange_rates/`
  con `CURLOPT_POSTFIELDS => json_encode(['estaciones' => $payload])`,
  header `Content-Type: application/json`, `CURLOPT_TIMEOUT` de 20s.
- Si la llamada falla (curl error, HTTP != 200, respuesta vacía/no parseable), hace
  `error_log(...)` y devuelve `[]` — el controlador ya maneja "sin filas" mostrando tabla
  vacía, no rompe la vista.
- Si tiene éxito, decodifica el JSON (`json_decode($response, true)`) y lo retorna tal cual —
  mismo shape de columnas que ya espera `datatable_exchange_rate()`.
- `exchange_rate_stations()`, `puertoAbierto()` (ya no se usa, se elimina), y el resto del
  modelo (`insert`, `insert_remote`, `update`, `update_remote`, `delete`, `delete_remote`) no
  cambian.

### 5. Sin cambios

Controlador `Administration::exchange_rate()`, `datatable_exchange_rate()`,
`exchange_rate_process()`, `update_exchange()`, `delete_exchange()`; vista Twig
`exchange_rate.html`; `administration.js`. El contrato de datos que sale de
`get_exchange_rates()` no cambia, solo cómo se obtiene.

## Manejo de errores / degradación

- Estación individual falla en ApiER (SQL error, linked server caído) → se omite del array de
  resultados, igual que hoy.
- ApiER completo no responde o hace timeout → PHP loguea el error y devuelve `[]`, la vista
  muestra tabla vacía en vez de romperse. Antes, una caída de `SG12` central tenía el mismo
  efecto (nada respondía); ahora el punto de fallo está aislado y logueado en un solo lugar.

## Impacto esperado

De 34 consultas secuenciales (`fsockopen` + `OPENQUERY`, una tras otra) a 34 consultas en
paralelo con `max_workers=40`: el tiempo total pasa de "suma de todas las esperas" a "la
espera de la estación más lenta". Mismo patrón y ganancia ya observados en
`estacion_documentos_compra` y `estacion_porcentaje`.
