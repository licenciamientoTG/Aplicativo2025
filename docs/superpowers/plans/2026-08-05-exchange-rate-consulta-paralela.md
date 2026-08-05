# Paralelizar consulta de tipo de cambio (exchange_rate) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reemplazar el `foreach` secuencial de 34 estaciones en `CotizacionesModel::get_exchange_rates()` (PHP) por una única llamada HTTP a un endpoint nuevo en ApiER que ejecuta las 34 consultas en paralelo con `ThreadPoolExecutor`.

**Architecture:** ApiER gana un modelo `CotizacionesEstaciones` y una vista `exchange_rates_view` (ruteada en `/api/TG_php/exchange_rates/`) que reciben la lista de estaciones por POST (JSON) y las consultan en paralelo, cada una con su propia conexión pyodbc al servidor central vía `OPENQUERY`. `CotizacionesModel::get_exchange_rates()` en PHP arma el payload desde `exchange_rate_stations()` (que sigue siendo la única fuente de verdad de las 34 estaciones), hace un solo `curl` POST, y devuelve el JSON decodificado — sin cambiar el contrato de datos que consume el controlador.

**Tech Stack:** PHP 8 (PDO, curl), Python 3.11 / Django REST Framework (pyodbc, `concurrent.futures.ThreadPoolExecutor`).

## Global Constraints

- No se modifica `Administration::exchange_rate()`, `datatable_exchange_rate()`, `exchange_rate_process()`, `update_exchange()`, `delete_exchange()`, la vista Twig, ni `administration.js` — el shape de datos que sale de `get_exchange_rates()` debe ser idéntico al actual.
- `exchange_rate_stations()` en PHP sigue siendo la única fuente de verdad de las 34 estaciones — no se duplica en Python.
- El endpoint nuevo en ApiER no lleva `permission_classes`/`authentication_classes` — mismo criterio que el resto de `TG_php` (protección de red/IP interna, no de aplicación).
- No hay framework de tests en ninguno de los dos repos (confirmado en `CLAUDE.md` de AplicativoPhp; ApiER no tiene `tests.py` con contenido). La verificación de cada tarea es manual: llamadas curl/PowerShell y prueba en navegador contra `php -S localhost:8000 router.php`.
- Body de la llamada PHP → ApiER es JSON crudo (`json_encode`), no `http_build_query` — evita ambigüedad de parseo de arrays anidados de objetos, para los que no hay precedente en el proyecto.
- Timeout del curl en PHP: 20s. `ThreadPoolExecutor(max_workers=40)` en Python, igual que `estacion_documentos_compra` y `estacion_porcentaje`.

---

### Task 1: Modelo `CotizacionesEstaciones` en ApiER

**Files:**
- Create: `C:\Users\alejandro.martinez\Desktop\codigo\ApiER\api\modelos\cotizaciones_estaciones.py`

**Interfaces:**
- Consumes: `CONTROLGASTG_CONN_STR` de `api.db_connections` (ya existe, apunta a `192.168.0.6`, BD `TG`).
- Produces: `CotizacionesEstaciones.get_last_exchange(linked_server: str, short_db: str, codgas, station_name: str, no_station: str, description: str) -> dict | None`. Task 2 llama a este método por cada estación dentro de un `ThreadPoolExecutor`.

El `dict` devuelto en éxito tiene exactamente estas claves (mismos nombres que hoy produce el `OPENQUERY` que corre en PHP): `codmda, codgas, fch, hra_format, hra, ctz, ctzcom, ctzven, codpza, codcpo, logusu, logfch, lognew, station_name, no_station, description, Fecha`.

- [ ] **Step 1: Crear el archivo del modelo**

```python
import pyodbc
from api.db_connections import CONTROLGASTG_CONN_STR


class CotizacionesEstaciones:
    def __init__(self, conn_str: str = CONTROLGASTG_CONN_STR):
        self.conn_str = conn_str

    def get_last_exchange(self, linked_server, short_db, codgas, station_name, no_station, description):
        codgas = int(codgas)

        # Escapamos comillas simples para los literales que van dentro de OPENQUERY.
        station_name_sql = str(station_name).replace("'", "''")
        no_station_sql = str(no_station).replace("'", "''")
        description_sql = str(description).replace("'", "''")

        query = f"""
        WITH cte AS (
            SELECT *,
                 CAST(CONVERT(VARCHAR(100), CAST(fch AS DATETIME) - 1, 23) AS VARCHAR(10)) AS Fecha
            FROM (
                SELECT TOP (1) [codmda], [codgas], [fch], CONCAT(RIGHT('00' + CAST(FLOOR(hra / 100) AS VARCHAR(2)), 2), ':', RIGHT('00' + CAST(hra % 100 AS VARCHAR(2)), 2)) AS hra_format, [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew], N'{station_name_sql}' AS station_name, N'{no_station_sql}' AS no_station, N'{description_sql}' AS description
                FROM OPENQUERY({linked_server}, '
                    SELECT TOP (1) [codmda], [codgas], [fch], [hra], [ctz], [ctzcom], [ctzven], [codpza], [codcpo], [logusu], [logfch], [lognew] FROM {short_db}.[Cotizaciones]
                    WHERE codgas = {codgas} ORDER BY lognew DESC
                ')
            ) AS inner_cte
        )
        SELECT * FROM cte;
        """

        try:
            with pyodbc.connect(self.conn_str) as conn:
                cursor = conn.cursor()
                cursor.execute(query)
                cols = [col[0] for col in cursor.description]
                row = cursor.fetchone()
            if row is None:
                return None
            return dict(zip(cols, row))
        except pyodbc.Error as e:
            print(f"CotizacionesEstaciones error para estacion {codgas} ({station_name}): {e}")
            return None
```

- [ ] **Step 2: Verificar sintaxis del archivo**

Run: `cd C:\Users\alejandro.martinez\Desktop\codigo\ApiER && python -c "import ast; ast.parse(open('api/modelos/cotizaciones_estaciones.py', encoding='utf-8').read())"`
Expected: sin salida (sin `SyntaxError`).

- [ ] **Step 3: Probar el método manualmente contra una estación real**

Run (PowerShell, dentro del entorno virtual de ApiER):
```
cd C:\Users\alejandro.martinez\Desktop\codigo\ApiER
.\env\Scripts\python.exe -c "from api.modelos.cotizaciones_estaciones import CotizacionesEstaciones; import django, os; os.environ.setdefault('DJANGO_SETTINGS_MODULE','myproject.settings'); django.setup(); c = CotizacionesEstaciones(); print(c.get_last_exchange('[192.168.5.101]', '[SG12_25262020].[dbo]', 6, 'Lopez Mateos', '2526', 'Casa de cambio terceros'))"
```
Expected: imprime un `dict` con las claves listadas arriba (o `None` si la estación 6 no tiene registros/está caída — en ese caso probar con otra estación de la lista de `exchange_rate_stations()` en `CotizacionesModel.php`).

- [ ] **Step 4: Commit**

```bash
cd C:\Users\alejandro.martinez\Desktop\codigo\ApiER
git add api/modelos/cotizaciones_estaciones.py
git commit -m "feat: agrega modelo CotizacionesEstaciones para consultar tipo de cambio por estación"
```

---

### Task 2: Vista `exchange_rates_view` y ruta en ApiER

**Files:**
- Modify: `C:\Users\alejandro.martinez\Desktop\codigo\ApiER\api\TG_php\views.py`
- Modify: `C:\Users\alejandro.martinez\Desktop\codigo\ApiER\api\urls.py`

**Interfaces:**
- Consumes: `CotizacionesEstaciones.get_last_exchange(...)` de Task 1.
- Produces: endpoint `POST http://192.168.0.109:82/api/TG_php/exchange_rates/`, body JSON `{"estaciones": [{"codgas": int, "linked_server": str, "short_db": str, "station_name": str, "no_station": str, "description": str}, ...]}`, respuesta `200` con un array JSON de los `dict` que devuelve `get_last_exchange` (estaciones sin datos/caídas simplemente no aparecen), o `400` si falta `estaciones`. Task 3 (PHP) consume esta respuesta.

- [ ] **Step 1: Agregar el import del modelo nuevo**

En `api/TG_php/views.py`, después de la línea 11 (`from api.modelos.ImportadorFacturas import ImportadorFacturas`):

```python
from api.modelos.cotizaciones_estaciones import CotizacionesEstaciones
```

- [ ] **Step 2: Agregar la vista al final de `api/TG_php/views.py`**

```python
@api_view(['POST'])
def exchange_rates_view(request):
    try:
        payload = json.loads(request.body)
    except (ValueError, TypeError):
        return Response({"detail": "Body inválido, se esperaba JSON"}, status=status.HTTP_400_BAD_REQUEST)

    estaciones = payload.get('estaciones', [])
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

- [ ] **Step 3: Registrar la ruta en `api/urls.py`**

Agregar `exchange_rates_view` al import de `.TG_php.views` (línea 12-38), después de `inventarios_turnos_distribuido,`:

```python
    inventarios_turnos_distribuido,
    exchange_rates_view,
)
```

Y agregar la ruta al final de `urlpatterns` (después de la línea 80, `path('facturas_vencen_hoy/', facturas_vencen_hoy),`):

```python
    path('facturas_vencen_hoy/', facturas_vencen_hoy),
    path('TG_php/exchange_rates/', exchange_rates_view),
]
```

- [ ] **Step 4: Verificar sintaxis**

Run: `cd C:\Users\alejandro.martinez\Desktop\codigo\ApiER && python -c "import ast; ast.parse(open('api/TG_php/views.py', encoding='utf-8').read()); ast.parse(open('api/urls.py', encoding='utf-8').read())"`
Expected: sin salida.

- [ ] **Step 5: Levantar el servidor Django de desarrollo y probar el endpoint con curl**

Confirmar primero con el usuario si el servidor de ApiER ya está corriendo o si hace falta levantarlo (`.\env\Scripts\python.exe manage.py runserver 0.0.0.0:82` desde `C:\Users\alejandro.martinez\Desktop\codigo\ApiER`) — no asumir.

Con el servidor arriba, probar:
```
curl -X POST http://localhost:82/api/TG_php/exchange_rates/ ^
  -H "Content-Type: application/json" ^
  -d "{\"estaciones\": [{\"codgas\": 6, \"linked_server\": \"[192.168.5.101]\", \"short_db\": \"[SG12_25262020].[dbo]\", \"station_name\": \"Lopez Mateos\", \"no_station\": \"2526\", \"description\": \"Casa de cambio terceros\"}]}"
```
Expected: `200` con un array JSON (uno o cero elementos, según si la estación responde).

Probar también el caso de error:
```
curl -X POST http://localhost:82/api/TG_php/exchange_rates/ -H "Content-Type: application/json" -d "{}"
```
Expected: `400` con `{"detail": "Falta la lista de estaciones"}`.

- [ ] **Step 6: Commit**

```bash
cd C:\Users\alejandro.martinez\Desktop\codigo\ApiER
git add api/TG_php/views.py api/urls.py
git commit -m "feat: agrega endpoint exchange_rates_view para consultar tipo de cambio en paralelo"
```

---

### Task 3: `CotizacionesModel::get_exchange_rates()` consume el endpoint nuevo

**Files:**
- Modify: `C:\Users\alejandro.martinez\Desktop\codigo\AplicativoPhp\_assets\models\CotizacionesModel.php:88-150` (docblock + `get_exchange_rates()`)
- Modify: `C:\Users\alejandro.martinez\Desktop\codigo\AplicativoPhp\_assets\models\CotizacionesModel.php:75-86` (`puertoAbierto()` — se elimina, ya no se usa)

**Interfaces:**
- Consumes: `POST http://192.168.0.109:82/api/TG_php/exchange_rates/` de Task 2; `exchange_rate_stations()` (sin cambios, misma firma `private function exchange_rate_stations() : array`); `$this->linked_server[$codgas]` y `$this->short_databases[$codgas]` (heredados de `Model.php`, sin cambios).
- Produces: `get_exchange_rates() : array` — mismo tipo de retorno y mismas claves por fila (`codmda, codgas, fch, hra_format, hra, ctz, ctzcom, ctzven, codpza, codcpo, logusu, logfch, lognew, station_name, no_station, description, Fecha`) que consume `Administration::datatable_exchange_rate()` en `_assets/controllers/administration.php:1441-1465` — ese archivo no se toca.

- [ ] **Step 1: Eliminar `puertoAbierto()` (ya no se usa)**

En `_assets/models/CotizacionesModel.php`, borrar el método completo (líneas 75-86):

```php
    /**
     * Verifica conectividad básica al puerto SQL Server de una estación.
     */
    private function puertoAbierto($host, $port = 1433, $timeout = 0.5) : bool {
        $conn = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if ($conn) {
            fclose($conn);
            return true;
        }

        return false;
    }

```

- [ ] **Step 2: Reemplazar `get_exchange_rates()` para que llame al endpoint de ApiER**

Reemplazar el método completo (docblock + cuerpo, lo que queda en las líneas ~88-150 después del Step 1) por:

```php
    /**
     * Devuelve el último tipo de cambio de cada estación.
     *
     * La consulta a las 34 estaciones se delega a ApiER (endpoint
     * /api/TG_php/exchange_rates/), que las ejecuta en paralelo con
     * ThreadPoolExecutor en vez del foreach secuencial que corría antes aquí.
     * Si una estación individual falla, ApiER simplemente la omite del
     * resultado. Si ApiER completo no responde, se loguea y se devuelve un
     * array vacío para que la vista muestre la tabla vacía sin tronar.
     */
    function get_exchange_rates() : array {
        $payload = [];
        foreach ($this->exchange_rate_stations() as $codgas => $meta) {
            if (empty($this->linked_server[$codgas]) || empty($this->short_databases[$codgas])) {
                continue;
            }
            $payload[] = [
                'codgas'       => $codgas,
                'linked_server'=> $this->linked_server[$codgas],
                'short_db'     => $this->short_databases[$codgas],
                'station_name' => $meta['station_name'],
                'no_station'   => $meta['no_station'],
                'description'  => $meta['description'],
            ];
        }

        $ch = curl_init('http://192.168.0.109:82/api/TG_php/exchange_rates/');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['estaciones' => $payload]));
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            error_log("get_exchange_rates: fallo al llamar a ApiER (HTTP {$httpCode}) {$curlError}");
            return [];
        }

        $rows = json_decode($response, true);
        if (!is_array($rows)) {
            error_log("get_exchange_rates: respuesta de ApiER no es un array válido: {$response}");
            return [];
        }

        return $rows;
    }
```

- [ ] **Step 3: Verificar sintaxis PHP**

Run: `php -l "C:\Users\alejandro.martinez\Desktop\codigo\AplicativoPhp\_assets\models\CotizacionesModel.php"`
Expected: `No syntax errors detected in ...`

- [ ] **Step 4: Prueba manual end-to-end**

Confirmar con el usuario si el servidor PHP local (`php -S localhost:8000 router.php`) ya está corriendo — el usuario indicó previamente que él gestiona ese servidor, no levantarlo sin avisar.

Con el servidor arriba (o el que el usuario indique) y sesión iniciada, abrir `/administration/exchange_rate` en el navegador y confirmar:
- La tabla carga con filas de estaciones (mismas columnas que antes: DESCRIPCIÓN, NO. ESTACIÓN, ESTACIÓN, FECHA, HORA, CAMBIO, ACCIONES).
- El tiempo de carga es visiblemente menor que antes (comparar contra el comportamiento previo si es posible).
- Los botones editar/eliminar de una fila siguen funcionando (no se tocaron, pero confirman que el `unique_id` — `codmda,codgas,fch,hra` — sigue viniendo correcto en la respuesta).

Si la tabla aparece vacía, revisar el log de PHP (`error_log`) y el log de Django (consola donde corre `runserver`) para el mensaje de error específico antes de asumir que el código está mal.

- [ ] **Step 5: Commit**

```bash
cd C:\Users\alejandro.martinez\Desktop\codigo\AplicativoPhp
git add _assets/models/CotizacionesModel.php
git commit -m "perf: consulta tipo de cambio vía ApiER en paralelo en vez de foreach secuencial"
```

---

## Self-Review Notes

- **Cobertura del spec:** Task 1 cubre el modelo Python (sección "1." del spec), Task 2 cubre la vista + ruta (secciones "2." y "3."), Task 3 cubre el cambio en PHP y la eliminación de `puertoAbierto()` (sección "4."). La sección "5. Sin cambios" del spec se respeta explícitamente en los Global Constraints y no se toca ningún archivo fuera de los tres listados.
- **Placeholders:** ninguno — cada step tiene código completo, no descripciones de "agregar manejo de errores" genérico.
- **Consistencia de tipos:** `get_last_exchange` (Task 1) devuelve `dict | None`; la vista (Task 2) filtra `if res:` antes de agregarlo — coherente. El payload que arma PHP (Task 3, claves `codgas, linked_server, short_db, station_name, no_station, description`) coincide exactamente con las claves que lee la vista de ApiER (`est["linked_server"], est["short_db"], est["codgas"], est["station_name"], est["no_station"], est["description"]`).
- **Manejo de estaciones sin `linked_server`/`short_databases`:** Task 3 Step 2 agrega un chequeo `empty(...)` que el código original no tenía explícito (antes el `foreach` original hacía `trim($this->linked_server[$codgas] ?? '', '[]')` y saltaba si estaba vacío) — se preserva el mismo comportamiento de "omitir estaciones sin configuración".
