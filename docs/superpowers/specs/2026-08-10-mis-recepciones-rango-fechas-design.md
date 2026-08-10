# Mis Recepciones — rango de fechas + botón Buscar

**Fecha:** 2026-08-10
**Estado:** Aprobado — listo para plan de implementación.

## Contexto

La vista `/station_portal/mis_recepciones` (implementada 2026-08-09, ver [spec original](2026-08-09-portal-estaciones-mis-recepciones-design.md)) hoy tiene dos problemas de UX detectados en uso real:

1. **Carga automática:** la tabla recarga en cada `change` de fecha/estación (`_assets/js/station_portal.js:51-57`). El usuario pidió que solo cargue al presionar un botón "Buscar".
2. **Solo un día:** el selector es una única fecha. El usuario pidió un rango "desde/hasta".

El rango de fechas expone una limitación real del backend actual: `MovimientosTanModel::sp_obtener_recepciones_combustible($fchtrn, $codgas, $codprd)` (`_assets/models/MovimientosTanModel.php:4-43`) hace un `OPENQUERY` síncrono contra **una sola estación y un solo día** por llamada (`WHERE M.fchtrn = {entero}`, no `BETWEEN`). No es un stored procedure pese al nombre — es un método PHP que arma el SQL dinámicamente. Iterar día por día desde PHP para cubrir un rango sería lento (una llamada de red síncrona por día) y no tiene paralelismo real disponible en PHP en este proyecto.

## Decisión de arquitectura

El proyecto ya resuelve exactamente este problema (rango de fechas × múltiples estaciones) en otro lugar: **ApiER** (servicio Django/DRF separado, `192.168.0.109:82`) ya expone patrones de consulta paralela contra las ~40 estaciones vía `ThreadPoolExecutor`, consumidos por PHP vía `curl` sencillo. Ejemplo ya en producción: `_assets/controllers/supply.php:2274` llama a `get_resumen_recepciones_combustible` (ApiER), que internamente reparte el trabajo en hasta 40 hilos (`api/TG_php/views.py:1096-1123`), uno por estación, cada uno con su propia conexión `pyodbc` al linked server de esa estación.

Ese endpoint existente no sirve tal cual: hace `tiptrn IN (2)` con joins a `MovimientosTan` (tipo 3 y 4), `DocumentosC`, `Proveedores`, `FacturasRecibidas` y a la tabla puente de Petrotal — trae columnas de facturación que esta vista no usa y que no son un espejo 1:1 de lo que hoy pinta `sp_obtener_recepciones_combustible` (filtro directo `tiptrn=3`, sin ese enriquecimiento).

**Se construye un endpoint nuevo en ApiER**, calcado del SQL que ya usa `MovimientosTanModel.php` (mismas columnas, mismo filtro `tiptrn=3`), pero parametrizado por rango de fechas (`BETWEEN`) y paralelizado por estación con el mismo patrón `ThreadPoolExecutor` + `as_completed` ya probado. PHP le pega una sola vez por rango en vez de iterar día por día.

`upload_remision()` (que valida que una recepción puntual exista antes de aceptar un archivo) **no cambia** — sigue usando el método PHP de un solo día, porque ahí no hay rango que resolver, solo una recepción concreta ya conocida por `nrotrn`/`fchtrn`.

## Cambios en ApiER

### Modelo: `api/modelos/Documentos_estaciones.py`

Nuevo método en la clase `DocumentosEstaciones`:

```python
def get_recepciones_combustible_rango(self, linked_server, short_db, codgas, from_date, until_date, codprd):
    """
    Recepciones de combustible (tiptrn=3) de una estación en un rango de
    fechas. Réplica de MovimientosTanModel::sp_obtener_recepciones_combustible
    (AplicativoPhp) parametrizada por rango en vez de un solo día.
    """
    prod = int(codprd or 0)
    inner_query = f"""
        SELECT
            M.nrotrn,
            CONVERT(DATE, DATEADD(DAY, -1, M.fchtrn)) AS fecha,
            CAST(CONVERT(TIME, DATEADD(MINUTE, M.hratrn % 100, DATEADD(HOUR, M.hratrn / 100, 0))) AS TIME(0)) AS hora,
            M.volrec AS VolumenRecibido,
            M.fchtrn,
            T.den,
            T.codprd,
            M.codgas
        FROM {short_db}.[MovimientosTan] M
            LEFT JOIN {short_db}.[Tanques] T ON M.codtan = T.cod AND M.codgas = T.codgas
        WHERE
            M.nroitm NOT IN (0,1,3,4)
            AND M.tiptrn = 3
            AND M.fchtrn BETWEEN {int(from_date)} AND {int(until_date)}
            AND M.codgas = {int(codgas)}
            AND ({prod} = 0 OR T.codprd = {prod})
        ORDER BY M.nrotrn DESC
    """
    inner_query = inner_query.replace("'", "''")
    sql = f"SELECT * FROM OPENQUERY({linked_server}, '{inner_query}')"

    try:
        with pyodbc.connect(self.conn_str) as conn:
            cursor = conn.cursor()
            cursor.execute(sql)
            cols = [col[0] for col in cursor.description]
            rows = cursor.fetchall()
        return [dict(zip(cols, row)) for row in rows]
    except pyodbc.Error as e:
        print(f"Error get_recepciones_combustible_rango para {codgas}: {e}")
        return []
```

Notas:
- `from_date`/`until_date` llegan como **enteros** (serial de Excel, mismo formato que `fchtrn` — PHP ya los convierte con `dateToInt()` antes de mandarlos, igual que hace hoy `supply.php:2261-2262` para el endpoint existente).
- Se quitan del SELECT las columnas de `Documentos`/`DocumentosC`/`Proveedores` (factura, txtref, CRE) que trae el método PHP original — no se usan en la tabla de Mis Recepciones (confirmado contra `station_portal.php::datatables_recepciones()`, que solo lee `nrotrn, fecha, hora, VolumenRecibido, den, codgas`). Si una fase futura las necesita, se agregan entonces.
- Igual que el patrón existente, un error en una estación individual se atrapa y devuelve lista vacía — no debe tumbar las demás cuando se paralelice.

### Vista: `api/TG_php/views.py`

Nueva función, calcada estructuralmente de `get_resumen_recepciones_combustible` (líneas 1042-1143):

```python
@api_view(['GET', 'POST'])
def get_recepciones_combustible_rango(request):
    """
    Recepciones de combustible (tiptrn=3) por rango de fechas, una o todas
    las estaciones, en paralelo. Usado por AplicativoPhp /station_portal
    (vista "Mis Recepciones").
    """
    from_date = request.data.get('from')
    until_date = request.data.get('until')
    codgas = request.data.get('codgas')
    codprd = request.data.get('codprd')

    if not all([from_date, until_date]):
        return Response(
            {"detail": "Faltan parámetros requeridos: from y until son obligatorios"},
            status=status.HTTP_400_BAD_REQUEST
        )

    try:
        documentos_estaciones = DocumentosEstaciones()
        estacion_despachos = EstacionDespachos()

        estaciones = estacion_despachos.estaciones()
        codgas_int = int(codgas or 0)

        estaciones_filtradas = estaciones if codgas_int == 0 else [e for e in estaciones if e["Codigo"] == codgas_int]

        if not estaciones_filtradas:
            return Response(
                {"detail": "No se encontraron estaciones con los criterios especificados"},
                status=status.HTTP_404_NOT_FOUND
            )

        resultados = []

        with ThreadPoolExecutor(max_workers=40) as executor:
            future_to_est = {
                executor.submit(
                    documentos_estaciones.get_recepciones_combustible_rango,
                    est["Servidor"], est["BaseDatos"], est["Codigo"],
                    from_date, until_date, codprd
                ): est
                for est in estaciones_filtradas
            }

            for future in as_completed(future_to_est):
                est = future_to_est[future]
                try:
                    res = future.result()
                    if res:
                        resultados.extend(res)
                except Exception as exc:
                    print(f"Error procesando estación {est['Codigo']}: {exc}")

        resultados_ordenados = sorted(
            resultados,
            key=lambda x: (x.get('fecha', ''), x.get('hora', ''))
        )

        return Response(resultados_ordenados, status=status.HTTP_200_OK)

    except Exception as e:
        return Response(
            {"detail": f"Error interno del servidor: {str(e)}"},
            status=status.HTTP_500_INTERNAL_SERVER_ERROR
        )
```

Diferencia clave con el endpoint existente: no filtra por `company` (la vista de estación siempre resuelve un `codgas` concreto — el filtro por empresa no aplica aquí, a diferencia del reporte de compras). El endpoint sí soporta `codgas=0` como "todas las estaciones" (capacidad general, igual que el patrón existente), pero en el flujo actual de `station_portal.php` nunca se manda así: `datatables_recepciones()` resuelve siempre un `codgas` real (>0) antes de llamar a la API — cuando `resolveCodgas()` no puede resolver una estación concreta, el controlador PHP corta antes y devuelve `data:[]` sin tocar la red (ver sección de AplicativoPhp). El soporte a `codgas=0` en ApiER queda disponible para consumidores futuros, no para esta vista.

A diferencia de `get_resumen_recepciones_combustible`, esta vista **no** devuelve 404 cuando `resultados` queda vacío tras la consulta — un rango sin recepciones es un resultado válido (tabla vacía), no un error. Devuelve `200` con `[]`.

### Ruta: `api/urls.py`

```python
path('get_recepciones_combustible_rango/', get_recepciones_combustible_rango),
```

## Cambios en AplicativoPhp

### Controlador: `_assets/controllers/station_portal.php`

`datatables_recepciones()` deja de llamar `MovimientosTanModel::sp_obtener_recepciones_combustible()` y en su lugar llama a ApiER vía `curl`, calcado del patrón ya usado en `supply.php:2274-2298`:

```php
public function datatables_recepciones(): void
{
    if (!authorized(self::PERM_VER)) {
        json_output(['data' => [], 'error' => 'No autorizado']);
        return;
    }

    $todasEstaciones = authorized(self::PERM_TODAS_ESTACIONES);
    $codgas = $this->resolveCodgas();

    if ($codgas === null) {
        if ($todasEstaciones) {
            json_output(['data' => []]);
            return;
        }
        json_output(['data' => [], 'error' => 'Sin estación asignada']);
        return;
    }

    $fechaDesde = $_REQUEST['fecha_desde'] ?? date('Y-m-d');
    $fechaHasta = $_REQUEST['fecha_hasta'] ?? date('Y-m-d');

    $postData = [
        'from'   => dateToInt($fechaDesde),
        'until'  => dateToInt($fechaHasta),
        'codgas' => $codgas,
        'codprd' => 0,
    ];

    try {
        $ch = curl_init('http://192.168.0.109:82/api/get_recepciones_combustible_rango/');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            throw new Exception('Error de cURL: ' . curl_error($ch));
        }
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception("Error HTTP: $httpCode");
        }

        $recepciones = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($recepciones)) {
            throw new Exception('Respuesta inválida de la API');
        }
    } catch (Exception $e) {
        json_output(['data' => [], 'error' => 'Error al consultar recepciones: ' . $e->getMessage()]);
        return;
    }

    // resolveCodgas() nunca devuelve 0 (ver Finding #3 de la revisión final
    // del módulo original) — o devuelve un codgas real (>0) o null, y el
    // caso null con permiso de todas las estaciones ya se atajó arriba
    // devolviendo data:[] sin llamar a la API. Por lo tanto $codgas aquí
    // siempre es un entero > 0: la API sólo consulta una estación por
    // request. El límite de conteos por fchtrn+codgas sigue siendo por
    // fila porque el rango puede cubrir varios días.
    $counts = [];
    foreach ($recepciones as $r) {
        $fchtrnFila = (int)$r['fchtrn'];
        $codgasFila = (int)$r['codgas'];
        $key = $codgasFila . '_' . $fchtrnFila;
        if (!isset($counts[$key])) {
            $counts[$key] = $this->recepcionRemisionesModel->get_counts_by_day($codgasFila, $fchtrnFila);
        }
    }

    $data = array_map(function ($r) use ($counts) {
        $nrotrn = (int)$r['nrotrn'];
        $fchtrn = (int)$r['fchtrn'];
        $codgasFila = (int)$r['codgas'];
        $key = $codgasFila . '_' . $fchtrn;
        $totalRemisiones = $counts[$key][$nrotrn] ?? 0;

        return [
            'nrotrn'           => $nrotrn,
            'codgas'           => $codgasFila,
            'fchtrn'           => $fchtrn,
            'fecha'            => $r['fecha'],
            'hora'             => $r['hora'],
            'producto'         => $r['den'],
            'volumen'          => $r['VolumenRecibido'],
            'total_remisiones' => $totalRemisiones,
        ];
    }, $recepciones);

    json_output(['data' => $data]);
}
```

Nota sobre `$counts`: `get_counts_by_day(codgas, fchtrn)` ya existe (`RecepcionRemisionesModel.php`) y trae el conteo de remisiones de **un día**. Con rango de varios días hay que llamarlo una vez por combinación única `(codgas, fchtrn)` presente en los resultados — se cachea en `$counts` para no repetir la consulta a `TG` por cada fila. Con rangos cortos (días) esto es un puñado de llamadas; no requiere optimizarse más en esta iteración.

`$codgas` seguirá siendo `0` únicamente si `resolveCodgas()` así lo determina para un usuario con permiso "todas las estaciones" sin selección explícita — ya soportado hoy por el controlador post-fixes de la revisión anterior; el endpoint de ApiER interpreta `codgas=0` como "todas", igual que el existente `get_resumen_recepciones_combustible`.

`upload_remision()`, `remisiones_by_recepcion()`, `delete_remision()`, `view_remision()` — **sin cambios**, siguen operando sobre una recepción puntual.

### Vista: `views/station_portal/mis_recepciones.html`

Reemplazar el input único de fecha por dos inputs (desde/hasta) y agregar el botón Buscar:

```html
<div class="row mb-3">
    <div class="col-auto">
        <label for="fecha_desde" class="form-label">Desde:</label>
        <input type="date" class="form-control" id="fecha_desde" value="{{ now | date('Y-m-d') }}">
    </div>
    <div class="col-auto">
        <label for="fecha_hasta" class="form-label">Hasta:</label>
        <input type="date" class="form-control" id="fecha_hasta" value="{{ now | date('Y-m-d') }}">
    </div>
    {% if showStationSelect %}
    <div class="col-auto">
        <label for="codgas_recepciones" class="form-label">Estación:</label>
        <select class="selectpicker form-control" data-live-search="true" id="codgas_recepciones">
            {% for station in stations %}
            <option value="{{ station.cod }}">{{ station.abr }}</option>
            {% endfor %}
        </select>
    </div>
    {% endif %}
    <div class="col-auto d-flex align-items-end">
        <button type="button" class="btn btn-primary" id="btnBuscarRecepciones">
            <i data-feather="search"></i> Buscar
        </button>
    </div>
</div>
```

La tabla `#datatables_mis_recepciones` gana una columna nueva **Fecha** antes de Hora (con varios días en pantalla, la hora sola ya no identifica la fila) — se agrega `<th>FECHA</th>` antes de `<th>HORA</th>` en el `<thead>`.

### JS: `_assets/js/station_portal.js`

- **No carga automáticamente al iniciar la página.** El `DataTable(...)` no se inicializa al cargar el script — se retrasa su creación hasta el primer clic en "Buscar". Antes del primer clic, la tabla HTML muestra solo el `<thead>` con un mensaje ("Selecciona un rango y presiona Buscar") en el `<tbody>`, sin instancia de DataTables corriendo aún.
- La config del `DataTable(...)` es la misma de hoy (mismo `ajax.url`, mismo `error` handler, mismo `deferRender`), con `columns` actualizado para incluir `fecha` como primera columna de datos.
- Se eliminan los listeners `change` en `#fecha_recepciones`/`#codgas_recepciones` (esos ids ya no existen con ese comportamiento) y se agrega un único listener `click` en `#btnBuscarRecepciones` que:
  - En el primer clic: valida el rango (ver abajo), luego construye e inicializa el `DataTable` sobre `#datatables_mis_recepciones` con la config completa — este primer `ajax` es la primera y única carga automática, disparada por el clic, no por la carga de la página.
  - En clics siguientes: vuelve a validar el rango y llama `.ajax.reload()` sobre la instancia ya creada.
- Los handlers `data: function(d) {...}` del `ajax` de DataTables leen `fecha_desde`/`fecha_hasta`/`codgas_recepciones` al momento de cada request (igual que hoy, solo cambian los ids leídos) — esto ya cubre "aplicar el rango actual" sin lógica adicional, porque DataTables llama a esa función en cada `reload()`.
- Validación mínima en el cliente antes de buscar: si `fecha_desde > fecha_hasta`, mostrar `alertify.myAlert(...)` y no lanzar la búsqueda (evita mandar un rango invertido a la API).
- Sin límite duro de rango en frontend ni backend (decisión del usuario) — no se agrega validación de "máximo N días".

## Testing

Sin framework de tests en ninguno de los dos repos (confirmado en `CLAUDE.md` de AplicativoPhp; ApiER tampoco tiene suite corriendo hoy para este módulo). Verificación manual:
- ApiER: probar el endpoint nuevo con Postman/curl directo contra una estación conocida y un rango de 2-3 días, confirmar que trae las mismas recepciones que hoy trae la vista día por día.
- AplicativoPhp: `php -l` sobre los archivos tocados; en navegador, confirmar que la tabla NO carga hasta presionar Buscar, que el rango desde/hasta filtra correctamente, y que el flujo de subir/ver/eliminar remisión (sin cambios) sigue funcionando sobre las filas de varios días.
