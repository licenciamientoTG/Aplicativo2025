# Tank Volume Bulk Query — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Agregar una tercera tab "Consulta Masiva" en `operations/tank_volume` que permita seleccionar múltiples estaciones y un rango de fechas, y muestre el historial completo de volumen de todos sus tanques en una sola tabla (sin gráfica).

**Architecture:** Un nuevo endpoint en la API Python (`tanques/volumen_masivo/`) recibe la lista de estaciones y fechas, obtiene los tanques de cada estación y paraleliza las consultas de historial con `ThreadPoolExecutor(max_workers=40)` usando el modelo `get_volumen_date_tanque` ya existente. El PHP expone un método `tank_volume_bulk_table()` que llama a ese endpoint y mapea la respuesta. El frontend añade la nueva tab con multiselect de estaciones, barra de progreso y DataTable.

**Tech Stack:** Python 3.11 + Django REST Framework + `concurrent.futures.ThreadPoolExecutor` + `pyodbc` (API); PHP 8 + cURL (controlador); Bootstrap tabs + jQuery DataTables + Alertify (frontend); Twig (vista).

---

## Mapa de archivos

| Archivo | Acción | Responsabilidad |
|---------|--------|-----------------|
| `ApiER/api/TG_php/views.py` | Modificar | Agregar view `volumen_masivo` |
| `ApiER/api/urls.py` | Modificar | Registrar ruta `tanques/volumen_masivo/` |
| `AplicativoPhp/_assets/controllers/operations.php` | Modificar | Agregar método `tank_volume_bulk_table()` |
| `AplicativoPhp/views/operations/tank_volume.html` | Modificar | Agregar tab "Consulta Masiva" con multiselect y tabla |
| `AplicativoPhp/_assets/js/operations/tank_volume.js` | Modificar | Agregar DataTable, handler AJAX y acciones de la tab masiva |

---

## Task 1: Endpoint Python `volumen_masivo`

**Archivos:**
- Modificar: `ApiER/api/TG_php/views.py` — después de la función `tanques_consolidado` (línea 825)
- Modificar: `ApiER/api/urls.py` — agregar import y path

### Contexto clave

- `get_tanques_estacion(servidor, base, codgas)` devuelve lista de dicts con claves: `cod`, `producto`, `numero_tan`
- `get_volumen_date_tanque(servidor, base, codgas, codtan, from_date, until_date)` devuelve lista de dicts con claves: `fecha`, `hora`, `producto`, `vol`, `volCxT`, `volh2o`, `capacidad_maxima`, `capacidad_operativa`, `util`, `fondage`, `volumen_min`
- Patrón de multihilo ya usado en este archivo: `ThreadPoolExecutor(max_workers=40)` + `as_completed` con dict `future_to_key`
- `from_date` y `until_date` llegan como enteros (ej. `20250101`) igual que en `volumen_date`

- [ ] **Step 1: Agregar el view `volumen_masivo` en `views.py`**

Insertar después de la línea 825 (`}) ` que cierra `tanques_consolidado`), antes de `@api_view(['GET', 'POST'])` de `resumen_movimientos_tanques`:

```python
@api_view(['POST'])
def volumen_masivo(request):
    """
    Historial de volumen de múltiples estaciones en paralelo
    """
    print("INICIANDO volumen_masivo")

    try:
        codgas_list = request.data.get('codgas_list', [])
        from_date = request.data.get('from_date')
        until_date = request.data.get('until_date')

        if not codgas_list:
            return Response(
                {"detail": "El parámetro 'codgas_list' es requerido y no puede estar vacío"},
                status=status.HTTP_400_BAD_REQUEST
            )
        if not all([from_date, until_date]):
            return Response(
                {"detail": "Los parámetros 'from_date' y 'until_date' son requeridos"},
                status=status.HTTP_400_BAD_REQUEST
            )

        codgas_list = [int(c) for c in codgas_list]

        estacion_despachos = EstacionDespachos()
        inventarios_model = InventariosEstaciones()
        todas = estacion_despachos.estaciones()
        estaciones = [est for est in todas if est["Codigo"] in codgas_list]

        if not estaciones:
            return Response(
                {"detail": "No se encontraron las estaciones especificadas"},
                status=status.HTTP_404_NOT_FOUND
            )

        # Obtener tanques de cada estación (sincrónico — consulta rápida a BD central)
        trabajos = []  # lista de (est, tanque_dict)
        for est in estaciones:
            tanques = inventarios_model.get_tanques_estacion(
                est["Servidor"], est["BaseDatos"], est["Codigo"]
            )
            for tanque in tanques:
                trabajos.append((est, tanque))

        if not trabajos:
            return Response(
                {"detail": "No se encontraron tanques para las estaciones seleccionadas"},
                status=status.HTTP_404_NOT_FOUND
            )

        print(f"Total trabajos (estacion+tanque): {len(trabajos)}")

        resultados = []
        errores = 0

        with ThreadPoolExecutor(max_workers=40) as executor:
            future_to_trabajo = {
                executor.submit(
                    inventarios_model.get_volumen_date_tanque,
                    est["Servidor"],
                    est["BaseDatos"],
                    est["Codigo"],
                    int(tanque["cod"]),
                    int(from_date),
                    int(until_date)
                ): (est, tanque)
                for est, tanque in trabajos
            }

            for future in as_completed(future_to_trabajo):
                est, tanque = future_to_trabajo[future]
                try:
                    res = future.result()
                    if res:
                        for registro in res:
                            registro["estacion"] = est["Codigo"]
                            registro["nombre_estacion"] = est["Nombre"]
                        resultados.extend(res)
                        print(f"✓ {est['Nombre']} T{tanque['numero_tan']}: {len(res)} registros")
                    else:
                        print(f"○ {est['Nombre']} T{tanque['numero_tan']}: Sin datos")
                except Exception as e:
                    errores += 1
                    print(f"✗ Error en {est['Nombre']} T{tanque['numero_tan']}: {str(e)}")
                    continue

        print(f"Consulta masiva completada: {len(resultados)} registros, {errores} errores")

        return Response({"Resultados": resultados}, status=status.HTTP_200_OK)

    except Exception as e:
        print(f"Error general en volumen_masivo: {str(e)}")
        return Response(
            {"detail": f"Error interno: {str(e)}"},
            status=status.HTTP_500_INTERNAL_SERVER_ERROR
        )
```

- [ ] **Step 2: Registrar el import y la ruta en `urls.py`**

En `ApiER/api/urls.py`, agregar `volumen_masivo` al import existente de `api.TG_php.views`:

```python
from .TG_php.views import (
    estacion_porcentaje,
    porcent_estacion_facturados_info,
    porcent_facturas_info,
    estacion_despachos_porcentaje,
    estacion_despachos_facturados_porcentaje,
    estacion_comparacion_series,
    estacion_documentos_compra,
    analisis_de_compras,
    inventarios_distribuido,
    inventarios_detalles_distribuido,
    volumen_tanque,
    tanques_estacion,
    tanques_consolidado,
    resumen_movimientos_tanques,
    get_resumen_recepciones_combustible,
    volumen_date,
    volumen_masivo,
    compras_facturas_base,
    factura_detalle,
    compras_estadisticas,
    importar_factura_pdf,
    facturas_vencen_hoy,
)
```

Y en `urlpatterns`, después de `path('tanques/consolidado/', tanques_consolidado),` (línea 64):

```python
    path('tanques/volumen_masivo/', volumen_masivo),
```

---

## Task 2: Método PHP `tank_volume_bulk_table()`

**Archivos:**
- Modificar: `AplicativoPhp/_assets/controllers/operations.php` — insertar después del cierre de `tank_volume_table()` (línea 2814), antes de `tank_consolidated_report()`

### Contexto clave

- `dateToInt()` es una función global disponible en `php_functions.php` que convierte `'2025-01-01'` → `20250101`
- cURL con JSON body: igual que en `tank_volume_table()` (líneas 2761-2773)
- La API devuelve `{ "Resultados": [...] }` donde cada registro tiene las claves: `fecha`, `hora`, `producto`, `vol`, `volCxT`, `volh2o`, `capacidad_maxima`, `capacidad_operativa`, `util`, `fondage`, `volumen_min`, `estacion` (int), `nombre_estacion` (string)

- [ ] **Step 3: Agregar método `tank_volume_bulk_table()` en `operations.php`**

Insertar entre la línea 2814 (`    }`) que cierra `tank_volume_table` y la línea 2816 (`    public function tank_consolidated_report()`):

```php
    public function tank_volume_bulk_table() {
        ini_set('max_execution_time', 300);
        ini_set('memory_limit', '512M');
        set_time_limit(0);
        header('Content-Type: application/json');

        $data = [];

        try {
            $codgas_list = isset($_POST['codgas_list']) ? $_POST['codgas_list'] : [];
            if (empty($codgas_list)) {
                echo json_encode(["data" => [], "error" => "Debe seleccionar al menos una estación"]);
                return;
            }

            $postData = [
                'codgas_list' => array_map('intval', $codgas_list),
                'from_date'   => dateToInt($_POST['from_date']),
                'until_date'  => dateToInt($_POST['until_date']),
            ];

            $ch = curl_init('http://192.168.0.109:82/api/tanques/volumen_masivo/');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Accept: application/json'
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 300);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                throw new Exception("Error en API Python: HTTP {$httpCode}");
            }

            $apiData = json_decode($response, true);

            if (!$apiData || !isset($apiData['Resultados'])) {
                throw new Exception("Respuesta inválida de la API");
            }

            foreach ($apiData['Resultados'] as $row) {
                $data[] = [
                    'ESTACION'      => $row['nombre_estacion'] ?? '',
                    'FECHA'         => $row['fecha'] ?? '',
                    'HORA'          => $row['hora'] ?? '',
                    'PRODUCTO'      => $row['producto'] ?? '',
                    'VOLUMEN'       => floatval($row['vol'] ?? 0),
                    'VOLUMEN_CXT'   => floatval($row['volCxT'] ?? 0),
                    'AGUA'          => floatval($row['volh2o'] ?? 0),
                    'CAP_MAXIMA'    => floatval($row['capacidad_maxima'] ?? 0),
                    'CAP_OPERATIVA' => floatval($row['capacidad_operativa'] ?? 0),
                    'UTIL'          => floatval($row['util'] ?? 0),
                    'FONDAJE'       => floatval($row['fondage'] ?? 0),
                    'VOL_MINIMO'    => floatval($row['volumen_min'] ?? 0),
                ];
            }

            echo json_encode(["data" => $data]);

        } catch (Exception $e) {
            error_log("Error en tank_volume_bulk_table: " . $e->getMessage());
            echo json_encode([
                "data"  => [],
                "error" => $e->getMessage()
            ]);
        }
    }

```

---

## Task 3: Tab HTML "Consulta Masiva"

**Archivos:**
- Modificar: `AplicativoPhp/views/operations/tank_volume.html`

### Contexto clave

- El archivo actualmente termina el `</div>` del `tab-content` en la línea 319, justo antes de `{% endblock %}`
- La variable `stations` ya está disponible en el contexto Twig (viene del controlador `tank_volume()`)
- Cada estación tiene: `station.Codigo`, `station.Servidor`, `station.BaseDatos`, `station.Nombre`
- El multiselect Bootstrap permite selección múltiple con `multiple` en el `<select>`. Para seleccionar con Ctrl+click o se puede añadir Select2, pero aquí usamos el nativo para no agregar dependencias

- [ ] **Step 4: Agregar el botón de la tab en `#tankTabs`**

En `views/operations/tank_volume.html`, después del `</li>` que cierra el tab "Reporte Consolidado" (línea 16), antes del `</ul>` (línea 17):

```html
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="bulk-tab" data-bs-toggle="tab" data-bs-target="#bulk" type="button">
            Consulta Masiva
        </button>
    </li>
```

- [ ] **Step 5: Agregar el tab-pane `#bulk` en `#tankTabContent`**

Insertar antes del `</div>` que cierra `#tankTabContent` (línea 319):

```html
    <!-- TAB 3: Consulta Masiva -->
    <div class="tab-pane fade" id="bulk" role="tabpanel">
        <div class="row mt-3">
            <div class="card flex-fill w-100">
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <label for="bulk_station_select" class="col-form-label col-form-label-sm">Estaciones: </label>
                            <select class="form-control" name="bulk_station_select[]" id="bulk_station_select" multiple style="height: 120px;">
                                {% for station in stations %}
                                    <option value="{{ station.Codigo }}">{{ station.Nombre }}</option>
                                {% endfor %}
                            </select>
                            <small class="text-muted">Ctrl+clic para seleccionar varias</small>
                        </div>
                        <div class="col-md-2">
                            <label for="bulk_from_date" class="col-form-label col-form-label-sm">Desde: </label>
                            <input type="date" class="form-control" name="bulk_from_date" id="bulk_from_date">
                        </div>
                        <div class="col-md-2">
                            <label for="bulk_until_date" class="col-form-label col-form-label-sm">Hasta: </label>
                            <input type="date" class="form-control" name="bulk_until_date" id="bulk_until_date">
                        </div>
                        <div class="col-md-4 d-flex flex-column justify-content-between">
                            <div></div>
                            <button type="button" class="btn btn-success btn-block w-100" id="btn_consultar_masivo">
                                <i data-feather="layers"></i> Consultar Masivo
                            </button>
                        </div>
                    </div>
                    <div class="row mt-2">
                        <div class="col">
                            <div id="progress_container_bulk" class="d-none">
                                <div class="progress">
                                    <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" id="progress_bar_bulk" style="width: 0%"></div>
                                </div>
                                <p class="text-center mt-2" id="progress_text_bulk">Consultando estaciones...</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabla Masiva -->
        <div class="row mt-3">
            <div class="card shadow">
                <div class="card-header">
                    <div class="card-actions float-end">
                        <div class="dropdown position-relative">
                            <a href="#" data-bs-toggle="dropdown" data-bs-display="static">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-more-horizontal align-middle">
                                    <circle cx="12" cy="12" r="1"></circle><circle cx="19" cy="12" r="1"></circle><circle cx="5" cy="12" r="1"></circle>
                                </svg>
                            </a>
                            <div class="dropdown-menu dropdown-menu-end">
                                <a class="dropdown-item" href="#" id="exportExcelBulk"><i data-feather="file-text"></i> Exportar a Excel</a>
                                <a class="dropdown-item" href="#" id="refresh_bulk_table"><i data-feather="refresh-ccw"></i> Limpiar</a>
                            </div>
                        </div>
                    </div>
                    <h5 class="card-title mb-0">Historial de Volumen — Consulta Masiva</h5>
                </div>
                <div class="card-body table-responsive">
                    <table class="table table-borderless my-0 w-100 table-striped table-sm" style="font-size: small" id="bulk_volume_table">
                        <thead>
                        <tr>
                            <th>ESTACIÓN</th>
                            <th>FECHA</th>
                            <th>HORA</th>
                            <th>PRODUCTO</th>
                            <th>VOLUMEN</th>
                            <th>VOLUMEN CxT</th>
                            <th>AGUA</th>
                            <th>CAP. MÁXIMA</th>
                            <th>CAP. OPERATIVA</th>
                            <th>ÚTIL</th>
                            <th>FONDAJE</th>
                            <th>VOL. MÍNIMO</th>
                        </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
```

---

## Task 4: JS — DataTable y lógica de la tab masiva

**Archivos:**
- Modificar: `AplicativoPhp/_assets/js/operations/tank_volume.js`

### Contexto clave

- Las tres DataTables (`tank_volume_table`, `consolidated_table`, `bulk_volume_table`) se inicializan en `$(document).ready`
- El patrón de AJAX + progreso + botón spinner ya existe en `#btn_consultar_volumen` (líneas 136-211) y `#btn_consultar_consolidado` (líneas 311-369) — seguir exactamente el mismo patrón
- `$('#bulk_station_select').val()` devuelve un array de strings con los values seleccionados, o `null` si ninguno
- Para enviar array por AJAX con jQuery usar `traditional: true` y `codgas_list: selectedStations`

- [ ] **Step 6: Inicializar `bulk_volume_table` en `document.ready`**

En `tank_volume.js`, dentro del bloque `$(document).ready(function() { ... })`, después de la inicialización de `consolidated_table` (después del `});` que cierra esa DataTable, antes de `initChart()`):

```javascript
    // Inicializar DataTable consulta masiva
    bulk_volume_table = $('#bulk_volume_table').DataTable({
        colReorder: true,
        order: [[0, "asc"], [1, "desc"], [2, "desc"]],
        dom: '<"top"Bf>rt<"bottom"lip>',
        pageLength: 50,
        data: [],
        buttons: [
            {
                extend: 'excel',
                className: 'd-none',
                title: 'Volumen_Masivo_Tanques',
                exportOptions: {
                    columns: ':visible'
                }
            }
        ],
        columns: [
            {'data': 'ESTACION'},
            {'data': 'FECHA'},
            {'data': 'HORA'},
            {'data': 'PRODUCTO'},
            {'data': 'VOLUMEN',       'render': $.fn.dataTable.render.number(',', '.', 2)},
            {'data': 'VOLUMEN_CXT',   'render': $.fn.dataTable.render.number(',', '.', 2)},
            {'data': 'AGUA',          'render': $.fn.dataTable.render.number(',', '.', 2)},
            {'data': 'CAP_MAXIMA',    'render': $.fn.dataTable.render.number(',', '.', 0)},
            {'data': 'CAP_OPERATIVA', 'render': $.fn.dataTable.render.number(',', '.', 0)},
            {'data': 'UTIL',          'render': $.fn.dataTable.render.number(',', '.', 0)},
            {'data': 'FONDAJE',       'render': $.fn.dataTable.render.number(',', '.', 0)},
            {'data': 'VOL_MINIMO',    'render': $.fn.dataTable.render.number(',', '.', 0)}
        ],
        initComplete: function () {
            $('.dt-buttons').addClass('d-none');
        }
    });
```

También agregar la declaración de la variable al inicio del archivo, junto con las otras dos:

```javascript
let bulk_volume_table;
```

- [ ] **Step 7: Agregar la declaración de variable al tope del archivo**

Al inicio de `tank_volume.js`, la línea 1-3 actualmente dice:
```javascript
let tank_volume_table;
let consolidated_table;
let volumeChart;
```

Cambiar a:
```javascript
let tank_volume_table;
let consolidated_table;
let bulk_volume_table;
let volumeChart;
```

- [ ] **Step 8: Agregar handler del botón `#btn_consultar_masivo`**

Al final del archivo, después de la función `prepararDatosGrafica` (última línea del archivo), agregar:

```javascript
// =================== TAB 3: CONSULTA MASIVA ===================

$('#btn_consultar_masivo').on('click', function() {
    const selectedStations = $('#bulk_station_select').val();
    const fromDate = $('#bulk_from_date').val();
    const untilDate = $('#bulk_until_date').val();

    if (!selectedStations || selectedStations.length === 0) {
        alertify.error('Por favor seleccione al menos una estación');
        return;
    }
    if (!fromDate || !untilDate) {
        alertify.error('Por favor seleccione ambas fechas');
        return;
    }
    if (new Date(fromDate) > new Date(untilDate)) {
        alertify.error('La fecha inicial debe ser menor a la fecha final');
        return;
    }

    $('#progress_container_bulk').removeClass('d-none');
    $('#progress_bar_bulk').css('width', '30%');
    $('#progress_text_bulk').text(`Consultando ${selectedStations.length} estación(es)...`);

    $(this).prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Consultando...');

    bulk_volume_table.clear().draw();

    $.ajax({
        url: '/operations/tank_volume_bulk_table',
        method: 'POST',
        traditional: true,
        data: {
            codgas_list: selectedStations,
            from_date: fromDate,
            until_date: untilDate
        },
        beforeSend: function() {
            $('#progress_bar_bulk').css('width', '50%');
        },
        success: function(response) {
            $('#progress_bar_bulk').css('width', '100%').removeClass('progress-bar-animated');
            $('#progress_text_bulk').text('¡Consulta completada!');

            if (response.data && response.data.length > 0) {
                bulk_volume_table.rows.add(response.data).draw();
                alertify.success(`Se cargaron ${response.data.length} registros`);
            } else {
                alertify.warning('No se encontraron registros para los parámetros especificados');
            }

            setTimeout(() => {
                $('#progress_container_bulk').addClass('d-none');
            }, 2000);
        },
        error: function(xhr, status, error) {
            $('#progress_container_bulk').addClass('d-none');
            alertify.error('Error al consultar: ' + error);
            console.error('Error:', xhr.responseText);
        },
        complete: function() {
            $('#btn_consultar_masivo').prop('disabled', false).html('<i data-feather="layers"></i> Consultar Masivo');
            feather.replace();
        }
    });
});

// Exportar Excel masivo
$('#exportExcelBulk').on('click', function(e) {
    e.preventDefault();
    bulk_volume_table.button('.buttons-excel').trigger();
});

// Limpiar tabla masiva
$('#refresh_bulk_table').on('click', function(e) {
    e.preventDefault();
    bulk_volume_table.clear().draw();
    $('#bulk_station_select').val(null);
    $('#bulk_from_date').val('');
    $('#bulk_until_date').val('');
    alertify.success('Datos limpiados');
});
```

---

## Self-Review

**Cobertura del spec:**
- [x] Endpoint API `tanques/volumen_masivo/` → Task 1
- [x] URL registrada → Task 1 Step 2
- [x] Método PHP `tank_volume_bulk_table()` → Task 2
- [x] Tab HTML con multiselect, fechas, barra de progreso → Task 3
- [x] Tabla con columna ESTACIÓN al inicio → Task 3 Step 5
- [x] DataTable inicializada en `document.ready` → Task 4 Steps 6-7
- [x] Handler AJAX con validaciones → Task 4 Step 8
- [x] Export Excel + Limpiar → Task 4 Step 8
- [x] `ThreadPoolExecutor(max_workers=40)` + `as_completed` → Task 1 Step 1
- [x] Error por tanque no interrumpe el resto → Task 1 Step 1 (try/except en el loop)
- [x] `codgas_list` vacío → HTTP 400 en API, error en JS → Task 1 Step 1 + Task 4 Step 8

**Consistencia de tipos:**
- `bulk_volume_table` declarada en Step 7, usada en Steps 6, 8 ✓
- Claves de respuesta API (`nombre_estacion`, `vol`, `volCxT`, etc.) coinciden entre Python (Step 1) y PHP (Step 3) ✓
- Claves DataTable (`ESTACION`, `FECHA`, etc.) coinciden entre PHP (Step 3) y JS (Step 6) ✓
- Ruta `/operations/tank_volume_bulk_table` coincide entre JS (Step 8) y nombre del método PHP (Step 3) ✓
