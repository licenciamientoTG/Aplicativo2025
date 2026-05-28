# Diseño: Tab Consulta Masiva de Volumen de Tanques

**Fecha:** 2026-05-28  
**Proyecto:** TotalGas — AplicativoPhp + ApiER  
**Feature:** Nueva tab "Consulta Masiva" en `operations/tank_volume`

---

## Contexto

La vista `operations/tank_volume` actualmente tiene dos tabs:
- **Tab 1 — Consulta Individual:** una estación, un tanque, rango de fechas → gráfica + tabla de historial
- **Tab 2 — Reporte Consolidado:** todas las estaciones, rango de fechas → máximos/mínimos/promedios por tanque

Se agrega una **Tab 3 — Consulta Masiva**: selección de múltiples estaciones, rango de fechas → tabla de historial completo (igual que individual) sin gráfica.

---

## Arquitectura

### Flujo de datos

```
Usuario selecciona N estaciones + rango fechas
  → AJAX POST /operations/tank_volume_bulk_table
    → PHP llama POST http://192.168.0.109:82/api/tanques/volumen_masivo/
      → API filtra estaciones por codgas_list
      → API obtiene tanques por estación (get_tanques_estacion)
      → ThreadPoolExecutor(max_workers=40) lanza get_volumen_date_tanque por cada (estacion, tanque)
      → Retorna todos los registros con ESTACION + NOMBRE_ESTACION extra
    → PHP mapea y retorna JSON { "data": [...] }
  → JS puebla DataTable bulk_volume_table
```

---

## Cambios por archivo

### 1. `ApiER/api/TG_php/views.py`

**Nuevo view:** `volumen_masivo(request)`

- Método: `POST`
- Parámetros recibidos:
  ```json
  {
    "codgas_list": [1, 2, 5],
    "from_date": 20250101,
    "until_date": 20250528
  }
  ```
- Lógica:
  1. Filtra `estacion_despachos.estaciones()` donde `est["Codigo"] in codgas_list`
  2. Para cada estación llama `inventarios_model.get_tanques_estacion(servidor, base, codgas)` (sincrónico, consulta rápida a BD central)
  3. Construye lista de tuplas `[(est, tanque), ...]` — una por cada tanque de cada estación seleccionada
  4. Lanza `ThreadPoolExecutor(max_workers=40)` con `as_completed`, llamando `get_volumen_date_tanque(servidor, base, codgas, codtan, from_date, until_date)` por cada tupla
  5. A cada registro del resultado agrega: `estacion` (codigo), `nombre_estacion` (nombre)
  6. Retorna: `{ "Resultados": [...] }`

- Manejo de errores: si una estación/tanque falla, se registra en log y se omite (no interrumpe el resto, igual que `tanques_consolidado`)

### 2. `ApiER/api/urls.py`

```python
from api.TG_php.views import (
    ...,
    volumen_masivo,
)

path('tanques/volumen_masivo/', volumen_masivo),
```

### 3. `_assets/controllers/operations.php`

**Nuevo método:** `tank_volume_bulk_table()`

- Recibe `POST`:
  - `codgas_list[]` → array de códigos enteros
  - `from_date` → string `YYYY-MM-DD` (se convierte con `dateToInt()`)
  - `until_date` → string `YYYY-MM-DD`
- Llama `http://192.168.0.109:82/api/tanques/volumen_masivo/` con JSON
- Timeout cURL: 300s (igual que `tank_consolidated_report`)
- Mapea respuesta a:
  ```php
  [
    'ESTACION'      => $row['nombre_estacion'],
    'FECHA'         => $row['fecha'],
    'HORA'          => $row['hora'],
    'PRODUCTO'      => $row['producto'],
    'VOLUMEN'       => floatval($row['vol']),
    'VOLUMEN_CXT'   => floatval($row['volCxT']),
    'AGUA'          => floatval($row['volh2o']),
    'CAP_MAXIMA'    => floatval($row['capacidad_maxima']),
    'CAP_OPERATIVA' => floatval($row['capacidad_operativa']),
    'UTIL'          => floatval($row['util']),
    'FONDAJE'       => floatval($row['fondage']),
    'VOL_MINIMO'    => floatval($row['volumen_min']),
  ]
  ```
- Retorna `{ "data": [...] }`

### 4. `views/operations/tank_volume.html`

**Nueva tab en `#tankTabs`:**
```html
<li class="nav-item" role="presentation">
    <button class="nav-link" id="bulk-tab" data-bs-toggle="tab" data-bs-target="#bulk" type="button">
        Consulta Masiva
    </button>
</li>
```

**Nuevo tab-pane `#bulk`:**

Filtros:
- `<select multiple>` de estaciones — mismo loop `{% for station in stations %}`, ID `bulk_station_select`
- `<input type="date">` desde: `bulk_from_date`
- `<input type="date">` hasta: `bulk_until_date`
- Botón: `btn_consultar_masivo`
- Barra de progreso: `progress_container_bulk` / `progress_bar_bulk` / `progress_text_bulk`

Tabla `#bulk_volume_table` con columnas:
```
ESTACIÓN | FECHA | HORA | PRODUCTO | VOLUMEN | VOLUMEN CxT | AGUA | CAP. MÁXIMA | CAP. OPERATIVA | ÚTIL | FONDAJE | VOL. MÍNIMO
```

Dropdown de acciones: Exportar a Excel (`exportExcelBulk`) + Limpiar (`refresh_bulk_table`)

### 5. `_assets/js/operations/tank_volume.js`

**Nueva DataTable `bulk_volume_table`** inicializada en `document.ready`:
- Misma config que `tank_volume_table` pero con columna `ESTACION` al inicio
- Orden default: `[[0, "asc"], [1, "desc"], [2, "desc"]]` (estación, fecha, hora)
- Export Excel title: `'Volumen_Masivo_Tanques'`

**Handler `#btn_consultar_masivo`:**
- Valida: al menos 1 estación seleccionada, ambas fechas, fecha_inicio <= fecha_fin
- Recoge array de estaciones del multiselect: `$('#bulk_station_select').val()`
- AJAX POST `/operations/tank_volume_bulk_table` con `codgas_list[]` + fechas
- Mismo patrón de barra de progreso y spinner en botón que `btn_consultar_volumen`
- Al recibir respuesta: `bulk_volume_table.rows.add(response.data).draw()`

**Handlers de acciones:**
- `#exportExcelBulk` → `bulk_volume_table.button('.buttons-excel').trigger()`
- `#refresh_bulk_table` → limpia tabla, resetea fechas y multiselect

---

## Validaciones

- Mínimo 1 estación seleccionada (error alertify si no)
- Ambas fechas requeridas
- `from_date <= until_date`
- En la API: si `codgas_list` está vacío → HTTP 400
- Si una estación/tanque falla en el hilo → se omite, no interrumpe

---

## Lo que NO cambia

- El modelo `get_volumen_date_tanque` en `inventarios_estaciones.py` — se reutiliza sin modificar
- Los tabs 1 y 2 existentes — sin tocar
- El endpoint `volumen_date/` existente — sin tocar
- La función `get_tanques_estacion` — se reutiliza sin modificar
