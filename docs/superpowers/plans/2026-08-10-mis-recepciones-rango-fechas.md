# Mis Recepciones — rango de fechas + botón Buscar Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Sustituir la carga automática de un solo día por un rango "desde/hasta" que solo se busca al presionar un botón, en la vista `/station_portal/mis_recepciones` de AplicativoPhp — respaldado por un endpoint nuevo en ApiER que paraleliza la consulta por estación en vez de golpear OPENQUERY día por día desde PHP.

**Architecture:** Repo `ApiER` (Django/DRF, `C:\Users\alejandro.martinez\Desktop\codigo\ApiER`) gana un método nuevo en `DocumentosEstaciones` + una vista nueva paralelizada con `ThreadPoolExecutor` (mismo patrón que `get_resumen_recepciones_combustible`) + una ruta nueva. Repo `AplicativoPhp` (`C:\Users\alejandro.martinez\Desktop\codigo\AplicativoPhp`) cambia `station_portal.php::datatables_recepciones()` de una llamada directa a `MovimientosTanModel` (un día, OPENQUERY síncrono) a un `curl` HTTP al endpoint nuevo de ApiER (rango completo, una sola llamada de red); la vista y el JS cambian de un input de fecha a dos (desde/hasta) + botón Buscar, con la primera carga de la tabla diferida hasta el clic.

**Tech Stack:** ApiER: Python 3, Django REST Framework, `pyodbc`, `concurrent.futures.ThreadPoolExecutor`. AplicativoPhp: PHP 8 MVC propio, cURL, Twig 3, jQuery + DataTables (server-side AJAX).

## Global Constraints

- El endpoint nuevo de ApiER replica el filtro `tiptrn=3` directo de `MovimientosTanModel::sp_obtener_recepciones_combustible` (AplicativoPhp `_assets/models/MovimientosTanModel.php:4-43`) — NO usa el patrón de `get_resumen_recepciones_combustible` (que parte de `tiptrn IN (2)` con joins a facturas/Petrotal); son consultas de negocio distintas.
- `from_date`/`until_date` que PHP manda a ApiER son **enteros** (serial de Excel vía `dateToInt()`), igual que ya hace `supply.php:2261-2262` para el endpoint existente — no strings de fecha.
- `codprd=0` significa "todos los productos" en el SQL nuevo, mismo sentinela ya usado en `MovimientosTanModel.php:36` (Task 3 del plan anterior).
- `resolveCodgas()` (`_assets/controllers/station_portal.php:42-54`) nunca devuelve `0` — devuelve un entero de estación real (>0) o `null`. `datatables_recepciones()` debe seguir cortando con `data:[]` **antes** de llamar a la API cuando no hay estación resuelta, tal como ya hace hoy — el endpoint de ApiER jamás recibe `codgas=0` desde este flujo.
- Ningún cambio toca `upload_remision()`, `remisiones_by_recepcion()`, `delete_remision()`, `view_remision()` — siguen operando sobre una recepción puntual con el método PHP existente de un solo día.
- Sin límite duro de rango de fechas en frontend ni backend (decisión explícita del usuario).
- No hay framework de tests en ninguno de los dos repos — verificación por `php -l` / sintaxis Python + prueba manual.

---

## Task 1: Método nuevo en `DocumentosEstaciones` (ApiER)

**Files:**
- Modify: `C:\Users\alejandro.martinez\Desktop\codigo\ApiER\api\modelos\Documentos_estaciones.py` (agregar método al final de la clase, después de la línea 1076)

**Interfaces:**
- Consumes: `pyodbc`, `self.conn_str` (ya inicializado en `__init__`, `CONTROLGAS_CONN_STR`).
- Produces: `DocumentosEstaciones.get_recepciones_combustible_rango(self, linked_server, short_db, codgas, from_date, until_date, codprd) -> list[dict]`. Cada dict trae las llaves `nrotrn, fecha, hora, VolumenRecibido, fchtrn, den, codprd, codgas`. Lista vacía si no hay resultados o si ocurre un error de conexión (nunca lanza excepción hacia el llamador).

- [ ] **Step 1: Agregar el método al final de la clase `DocumentosEstaciones`**

Insertar después de la línea 1076 (fin del archivo, cierre de `get_resumen_recepciones_combustible`), con la misma indentación de método de clase (4 espacios):

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

- [ ] **Step 2: Verificación de sintaxis**

```bash
python -m py_compile "C:\Users\alejandro.martinez\Desktop\codigo\ApiER\api\modelos\Documentos_estaciones.py"
```

Expected: sin salida ni error (compila limpio).

- [ ] **Step 3: Commit**

```bash
cd "C:\Users\alejandro.martinez\Desktop\codigo\ApiER"
git add api/modelos/Documentos_estaciones.py
git commit -m "feat: agrega get_recepciones_combustible_rango a DocumentosEstaciones"
```

---

## Task 2: Vista y ruta nuevas en ApiER

**Files:**
- Modify: `C:\Users\alejandro.martinez\Desktop\codigo\ApiER\api\TG_php\views.py` (agregar función al final del archivo)
- Modify: `C:\Users\alejandro.martinez\Desktop\codigo\ApiER\api\urls.py:12-39` (agregar import), `:72` (agregar ruta)

**Interfaces:**
- Consumes: `DocumentosEstaciones.get_recepciones_combustible_rango(...)` (Task 1), `EstacionDespachos.estaciones()` (`api/modelos/estaciones_despachos.py:10-30`, ya usado por `get_resumen_recepciones_combustible` — sin cambios), `ThreadPoolExecutor`/`as_completed`/`Response`/`status`/`api_view` (ya importados en `views.py:1-5`, sin cambios).
- Produces: función `get_recepciones_combustible_rango(request)` en `api/TG_php/views.py`, ruta `POST/GET /api/get_recepciones_combustible_rango/`. Request: `from`, `until` (enteros, requeridos), `codgas` (entero, opcional, default 0), `codprd` (entero, opcional, default 0). Response: `200` con lista JSON plana de recepciones (mismas llaves que Task 1), o `[]` si no hay resultados; `400` si faltan `from`/`until`; `404` si `codgas` no corresponde a ninguna estación; `500` en error interno.

- [ ] **Step 1: Agregar la vista al final de `api/TG_php/views.py`**

El archivo ya importa todo lo necesario (`api_view` línea 1, `Response`/`status` líneas 3-4, `ThreadPoolExecutor`/`as_completed` línea 5, `EstacionDespachos` línea 7, `DocumentosEstaciones` línea 8) — no se requiere ningún import nuevo. Agregar al final del archivo:

```python
@api_view(['GET', 'POST'])
def get_recepciones_combustible_rango(request):
    """
    Recepciones de combustible (tiptrn=3) por rango de fechas, una sola
    estación, en paralelo (aunque hoy el llamador de AplicativoPhp siempre
    resuelve una estación concreta antes de llamar). Usado por
    AplicativoPhp /station_portal (vista "Mis Recepciones").
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

Nota: a diferencia de `get_resumen_recepciones_combustible` (que devuelve 404 cuando `resultados` está vacío), esta vista devuelve `200` con `[]` cuando no hay recepciones en el rango — un rango sin recepciones es un resultado válido, no un error.

- [ ] **Step 2: Verificación de sintaxis**

```bash
python -m py_compile "C:\Users\alejandro.martinez\Desktop\codigo\ApiER\api\TG_php\views.py"
```

Expected: sin salida ni error.

- [ ] **Step 3: Agregar el import en `api/urls.py`**

En `api/urls.py:12-39` (bloque `from .TG_php.views import (...)`), agregar `get_recepciones_combustible_rango` a la lista de imports. Insertar justo después de `get_resumen_recepciones_combustible,` (línea 27):

```python
    get_resumen_recepciones_combustible,
    get_recepciones_combustible_rango,
```

- [ ] **Step 4: Agregar la ruta en `api/urls.py`**

En el bloque `urlpatterns` (`api/urls.py:72`), insertar justo después de la línea `path('get_resumen_recepciones_combustible/', get_resumen_recepciones_combustible),`:

```python
    path('get_resumen_recepciones_combustible/', get_resumen_recepciones_combustible),
    path('get_recepciones_combustible_rango/', get_recepciones_combustible_rango),
```

- [ ] **Step 5: Verificación de sintaxis**

```bash
python -m py_compile "C:\Users\alejandro.martinez\Desktop\codigo\ApiER\api\urls.py"
```

Expected: sin salida ni error.

- [ ] **Step 6: Verificación funcional del servidor (si hay entorno disponible)**

Si hay forma de levantar el servidor Django de desarrollo en este entorno (`python manage.py runserver` o equivalente ya documentado en el repo), confirmar que arranca sin `ImportError` ni error de rutas. Si no hay forma de levantarlo en este entorno, dejar constancia en el reporte de que la verificación funcional queda pendiente para el humano (Task 5 de este plan).

- [ ] **Step 7: Commit**

```bash
cd "C:\Users\alejandro.martinez\Desktop\codigo\ApiER"
git add api/TG_php/views.py api/urls.py
git commit -m "feat: endpoint get_recepciones_combustible_rango (paralelo por estacion)"
```

---

## Task 3: `datatables_recepciones()` consume el endpoint nuevo (AplicativoPhp)

**Files:**
- Modify: `_assets/controllers/station_portal.php:83-126` (reemplazar el cuerpo del método `datatables_recepciones()`)

**Interfaces:**
- Consumes: `resolveCodgas()` (`station_portal.php:42-54`, sin cambios), `RecepcionRemisionesModel::get_counts_by_day(int $codgas, int $fchtrn): array` (`_assets/models/RecepcionRemisionesModel.php`, ya existente, sin cambios), endpoint HTTP `POST http://192.168.0.109:82/api/get_recepciones_combustible_rango/` (Task 2).
- Produces: `datatables_recepciones()` devuelve el mismo shape JSON que antes (`{'data': [...]}`, cada fila con `nrotrn, codgas, fchtrn, hora, producto, volumen, total_remisiones`), más una llave nueva `fecha` (string `YYYY-MM-DD`) que Task 4 consumirá para pintar la columna Fecha.

- [ ] **Step 1: Reemplazar el método `datatables_recepciones()` completo**

En `_assets/controllers/station_portal.php`, localizar el método `datatables_recepciones()` (actualmente líneas 83-126) y reemplazarlo entero por:

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
            // Con permiso 85 y sin estación elegida todavía: no es un error,
            // simplemente no hay nada que mostrar aún. Se corta ANTES de
            // llamar a la API — el endpoint de ApiER nunca recibe codgas=0
            // desde este flujo.
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

        // resolveCodgas() nunca devuelve 0 (o entero >0, o null ya atajado
        // arriba), así que $codgas aquí siempre es una sola estación real:
        // la API sólo consulta esa estación. El conteo de remisiones se
        // cachea por fchtrn (el rango puede cubrir varios días) para no
        // repetir la consulta a TG por cada fila con la misma fecha.
        $counts = [];
        foreach ($recepciones as $r) {
            $fchtrnFila = (int)$r['fchtrn'];
            if (!isset($counts[$fchtrnFila])) {
                $counts[$fchtrnFila] = $this->recepcionRemisionesModel->get_counts_by_day($codgas, $fchtrnFila);
            }
        }

        $data = array_map(function ($r) use ($counts, $codgas) {
            $nrotrn = (int)$r['nrotrn'];
            $fchtrn = (int)$r['fchtrn'];
            $totalRemisiones = $counts[$fchtrn][$nrotrn] ?? 0;

            return [
                'nrotrn'           => $nrotrn,
                'codgas'           => $codgas,
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

- [ ] **Step 2: Verificación de sintaxis**

```bash
php -l "_assets/controllers/station_portal.php"
```

Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add _assets/controllers/station_portal.php
git commit -m "feat: datatables_recepciones consume ApiER por rango en vez de OPENQUERY dia a dia"
```

---

## Task 4: Vista y JS — rango desde/hasta + botón Buscar (AplicativoPhp)

**Files:**
- Modify: `views/station_portal/mis_recepciones.html:12-40` (reemplazar el bloque de filtros y la tabla)
- Modify: `_assets/js/station_portal.js:1-57` (reemplazar la inicialización del DataTable y los listeners de filtro)

**Interfaces:**
- Consumes: `datatables_recepciones()` (Task 3) — endpoint sin cambio de ruta, solo de parámetros esperados (`fecha_desde`/`fecha_hasta` en vez de `fecha`) y de shape de respuesta (nueva llave `fecha` por fila).
- Produces: tabla `#datatables_mis_recepciones` con columna `fecha` nueva antes de `hora`; inputs `#fecha_desde`/`#fecha_hasta` (reemplazan `#fecha_recepciones`); botón `#btnBuscarRecepciones` que dispara la única carga (inicial y subsecuentes).

- [ ] **Step 1: Reemplazar el bloque de filtros y el `<thead>` en la vista**

En `views/station_portal/mis_recepciones.html`, reemplazar las líneas 12-40 (desde `<div class="card-body h-100 table-responsive">` hasta el `</table>` de cierre) por:

```html
            <div class="card-body h-100 table-responsive">
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
                <table id="datatables_mis_recepciones" class="table table-sm w-100" data-codgas="{{ tg_user['IdEstacion'] }}" data-can-delete="{{ canDelete ? '1' : '0' }}">
                    <thead>
                        <tr>
                            <th>FECHA</th>
                            <th>HORA</th>
                            <th>PRODUCTO</th>
                            <th>VOLUMEN RECIBIDO</th>
                            <th>REMISIÓN</th>
                            <th>ACCIONES</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td colspan="6" class="text-center text-muted">Selecciona un rango y presiona Buscar</td></tr>
                    </tbody>
                </table>
            </div>
```

- [ ] **Step 2: Verificación de bloques Twig balanceados**

Confirmar visualmente (o con un editor) que el `{% if showStationSelect %}...{% endif %}` sigue balanceado tras el cambio, y que el resto del archivo (modales de subir/ver remisiones, líneas 46 en adelante del archivo original) no fue tocado ni movido.

- [ ] **Step 3: Reemplazar la inicialización del DataTable y los listeners en el JS**

Reemplazar TODO el contenido de `_assets/js/station_portal.js` desde la línea 1 hasta la línea 57 (desde `let datatables_mis_recepciones = ...` hasta el cierre del listener `$('#codgas_recepciones').on('change', ...)`) por:

```js
let datatables_mis_recepciones = null;

function construirConfigDataTable() {
    return {
        dom: '<"top"f>rt<"bottom"lip>',
        pageLength: 100,
        ajax: {
            url: '/station_portal/datatables_recepciones',
            data: function (d) {
                d.fecha_desde = $('#fecha_desde').val();
                d.fecha_hasta = $('#fecha_hasta').val();
                const codgasSelect = $('#codgas_recepciones');
                if (codgasSelect.length) {
                    d.codgas = codgasSelect.val();
                }
            },
            error: function() {
                alertify.myAlert(
                    `<div class="container text-center text-danger">
                        <h4 class="mt-2 text-danger">¡Error!</h4>
                    </div>
                    <div class="text-dark">
                        <p class="text-center">No se pudieron cargar las recepciones. Intente nuevamente.</p>
                    </div>`
                );
            }
        },
        deferRender: true,
        columns: [
            { data: 'fecha' },
            { data: 'hora' },
            { data: 'producto' },
            { data: 'volumen', render: $.fn.dataTable.render.number(',', '.', 2) },
            {
                data: 'total_remisiones',
                render: function (data) {
                    return data > 0
                        ? `<span class="badge bg-success">${data} subida(s)</span>`
                        : `<span class="badge bg-warning text-dark">Sin remisión</span>`;
                }
            },
            {
                data: null,
                render: function (row) {
                    let html = `<button type="button" class="btn btn-sm btn-primary btn-subir-remision" data-nrotrn="${row.nrotrn}" data-codgas="${row.codgas}" data-fchtrn="${row.fchtrn}">Subir</button> `;
                    if (row.total_remisiones > 0) {
                        html += `<button type="button" class="btn btn-sm btn-secondary btn-ver-remisiones" data-nrotrn="${row.nrotrn}" data-codgas="${row.codgas}" data-fchtrn="${row.fchtrn}">Ver</button>`;
                    }
                    return html;
                }
            },
        ],
    };
}

function rangoEsValido() {
    const desde = $('#fecha_desde').val();
    const hasta = $('#fecha_hasta').val();

    if (!desde || !hasta) {
        alertify.myAlert('<div class="text-danger text-center"><p>Selecciona ambas fechas.</p></div>');
        return false;
    }

    if (desde > hasta) {
        alertify.myAlert('<div class="text-danger text-center"><p>La fecha "Desde" no puede ser posterior a "Hasta".</p></div>');
        return false;
    }

    return true;
}

$('#btnBuscarRecepciones').on('click', function () {
    if (!rangoEsValido()) {
        return;
    }

    if (datatables_mis_recepciones === null) {
        datatables_mis_recepciones = $('#datatables_mis_recepciones').DataTable(construirConfigDataTable());
    } else {
        datatables_mis_recepciones.ajax.reload();
    }
});
```

Nota importante: la referencia a `datatables_mis_recepciones` en los handlers de subir/ver/eliminar remisión más adelante en el mismo archivo (después de la línea 57 original) sigue funcionando sin cambios — sigue siendo la misma variable, solo que ahora puede ser `null` hasta el primer clic en Buscar. Los botones "Subir"/"Ver"/"Eliminar" de una fila solo existen si la tabla ya se pobló (es decir, si `datatables_mis_recepciones` ya no es `null`), así que no hace falta ningún guard adicional en esos handlers — nunca se puede hacer clic en un botón de una fila que no existe todavía.

- [ ] **Step 4: Verificación manual de sintaxis JS**

Revisar visualmente que las llaves `{}` y paréntesis `()` del archivo completo estén balanceados (no hay linter de JS configurado en el proyecto). Confirmar que el resto del archivo (handlers de `#btnConfirmarSubirRemision`, `.btn-subir-remision`, `.btn-ver-remisiones`, `.btn-eliminar-remision`, que empiezan después de la línea 57 original) permanece intacto y sin duplicación.

- [ ] **Step 5: Commit**

```bash
git add views/station_portal/mis_recepciones.html _assets/js/station_portal.js
git commit -m "feat: rango desde/hasta + boton Buscar en Mis Recepciones (sin autocarga)"
```

---

## Task 5: Verificación end-to-end manual

**Files:** ninguno (solo verificación)

- [ ] **Step 1: Confirmar que ApiER expone la ruta nueva**

Con el servidor de ApiER corriendo (gestionado por el humano, no por el agente), hacer una petición de prueba:

```bash
curl -X POST http://192.168.0.109:82/api/get_recepciones_combustible_rango/ \
  -d "from=46000&until=46010&codgas=6&codprd=0"
```

(sustituir `from`/`until` por seriales de Excel reales de un rango con recepciones conocidas, y `codgas` por una estación real de prueba — p. ej. 6 = López Mateos, según el catálogo en `Model.php`). Expected: `200` con un array JSON (puede estar vacío si no hay recepciones en ese rango, pero no debe dar error 500 ni 404 si la estación existe).

- [ ] **Step 2: Confirmar que la vista de AplicativoPhp NO carga automáticamente**

Navegar a `/station_portal/mis_recepciones` con un usuario que tenga el permiso "Ver Mis Recepciones" (id 84). Confirmar que la tabla muestra el mensaje "Selecciona un rango y presiona Buscar" y que no se dispara ninguna petición de red al cargar la página (verificar con las herramientas de desarrollador del navegador, pestaña Network).

- [ ] **Step 3: Confirmar el rango de fechas**

Elegir un rango de 2-3 días con recepciones conocidas, presionar "Buscar", y confirmar que la tabla muestra recepciones de más de un día (columna FECHA distinta entre filas) en una sola carga.

- [ ] **Step 4: Confirmar validación de rango invertido**

Poner una fecha "Desde" posterior a "Hasta" y presionar "Buscar" — debe mostrar la alerta de validación y NO disparar la petición de red.

- [ ] **Step 5: Confirmar que subir/ver/eliminar remisión sigue funcionando**

Sobre una fila de la tabla con rango de varios días, subir una remisión de prueba, verla, y eliminarla — confirmar que el flujo completo (sin cambios en esta iteración) sigue funcionando igual que antes del cambio de rango.

- [ ] **Step 6: Confirmar el caso "todas las estaciones" (si aplica al usuario de prueba)**

Con un usuario que tenga también el permiso "todas las estaciones" (id 85): al entrar sin haber elegido estación en el `<select>`, confirmar que presionar Buscar sin seleccionar estación no rompe nada (debe mostrar tabla vacía o pedir seleccionar estación, según el comportamiento heredado de `resolveCodgas()` ya implementado — no se modifica en este plan). Elegir una estación del combo y confirmar que el rango se aplica correctamente a esa estación.
