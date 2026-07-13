# Análisis de Merma Diaria — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reporte "Análisis de merma diaria" en Abastos que reemplaza el flujo Excel: snapshot diario en TG (cron + botón Actualizar vía ApiER en paralelo), vista resumen mensual tipo MERMA MENSUAL con captura manual, y vista detalle día×turno por estación.

**Architecture:** ApiER expone `POST /api/inventarios_turnos/` (ThreadPool 40 × OPENQUERY, portando la lógica de `TG.dbo.sp_obtener_inventarios_por_turno`). PHP `/merma/sync` (cron con `CRON_SECRET` o botón con permiso 33) lo consume y hace upsert en `TG.dbo.merma_diaria`. Las vistas `/merma/analisis` y `/merma/detalle` leen solo del snapshot.

**Tech Stack:** PHP 8 MVC propio + Twig + jQuery/DataTables (AplicativoPhp) · Django REST + pyodbc (ApiER) · SQL Server (TG en 192.168.0.6).

**Spec:** `docs/superpowers/specs/2026-07-13-analisis-merma-diaria-design.md`

## Global Constraints

- Dos repos: **AplicativoPhp** (`C:\Users\alejandro.martinez\Desktop\codigo\AplicativoPhp`) y **ApiER** (`C:\Users\alejandro.martinez\Desktop\codigo\ApiER`). Cada uno se commitea en su propio repo.
- ApiER se despliega **manualmente por SFTP** a 192.168.0.109:82 — ese paso lo hace el usuario; el plan lo marca como checkpoint de usuario.
- **NO levantar ni recargar el servidor PHP de desarrollo** — el usuario lo gestiona él mismo (preferencia registrada). Verificar contra el server que el usuario tenga corriendo, o por CLI.
- Fechas ControlGas (`fch`) son **serial**: `(fecha − 1899-12-31).days`, idéntico a `dateToInt()` de PHP (`_assets/classes/php_functions.php:254`). NUNCA YYYYMMDD.
- Fecha operativa mostrada = `fch − 1` (convención de todo el sistema).
- Permiso **33** (Reportes de Abastos) para ver y capturar; cron autentica con `cron_token == CRON_SECRET` (constante en `header.class.php`, valor `TG_CRON_2024`).
- Productos → familias solo en presentación: (1, 179, 192) → MAXIMA; (2, 180, 193) → SUPER; (3, 181) → DIESEL.
- No hay framework de tests: cada tarea cierra con verificación manual por CLI/curl con salida esperada.
- No existe caso de routing que agregar: `index.php` autocarga cualquier controlador de `_assets/controllers/` (patrón `/^[a-z][a-z0-9_]*$/`).
- Commits terminan con `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`.
- La UI y comentarios de código en español, como el resto del proyecto.

---

### Task 1: Schema de BD (4 tablas en TG)

**Files:**
- Create: `docs/sql/merma_schema.sql` (repo AplicativoPhp)

**Interfaces:**
- Produces: tablas `TG.dbo.merma_diaria` (UNIQUE fecha+codgas+codprd+turno), `TG.dbo.merma_manual` (UNIQUE codgas+anio+mes), `TG.dbo.merma_mes_config` (UNIQUE anio+mes), `TG.dbo.merma_sync_log`. Todas las tareas PHP posteriores dependen de estos nombres de columnas exactos.

- [ ] **Step 1: Escribir el schema**

```sql
-- docs/sql/merma_schema.sql
-- Schema del módulo Análisis de Merma Diaria (/merma/...).
-- Snapshot diario por estación/producto/turno + captura manual + bitácora.
-- Spec: docs/superpowers/specs/2026-07-13-analisis-merma-diaria-design.md
USE TG;
GO

IF OBJECT_ID('dbo.merma_diaria') IS NULL
BEGIN
CREATE TABLE dbo.merma_diaria (
    id            INT IDENTITY(1,1) PRIMARY KEY,
    fecha         DATE          NOT NULL,  -- día operativo (fch - 1)
    codgas        INT           NOT NULL,  -- TG.dbo.Estaciones.Codigo
    estacion      NVARCHAR(255) NOT NULL,  -- nombre denormalizado
    codprd        INT           NOT NULL,  -- 1,2,3,179,180,181,192,193
    producto      NVARCHAR(255) NULL,
    turno         INT           NOT NULL,  -- 11, 21, 41
    ventas_reales FLOAT         NULL,
    inv_fisico    FLOAT         NULL,      -- NULL = sin corte capturado
    compras       FLOAT         NULL,
    inv_inicial   FLOAT         NULL,
    inv_contable  FLOAT         NULL,
    diferencia    FLOAT         NULL,      -- negativo = merma
    updated_at    DATETIME      NOT NULL DEFAULT GETDATE(),
    CONSTRAINT UQ_merma_diaria UNIQUE (fecha, codgas, codprd, turno)
);
CREATE INDEX IX_merma_diaria_estacion ON dbo.merma_diaria (codgas, fecha);
END
GO

IF OBJECT_ID('dbo.merma_manual') IS NULL
BEGIN
CREATE TABLE dbo.merma_manual (
    id              INT IDENTITY(1,1) PRIMARY KEY,
    codgas          INT           NOT NULL,
    anio            INT           NOT NULL,
    mes             INT           NOT NULL,
    merma_sd_maxima FLOAT         NULL,
    merma_sd_super  FLOAT         NULL,
    merma_sd_diesel FLOAT         NULL,
    comentarios     NVARCHAR(MAX) NULL,
    updated_by      INT           NULL,    -- Id de usuario TG
    updated_at      DATETIME      NOT NULL DEFAULT GETDATE(),
    CONSTRAINT UQ_merma_manual UNIQUE (codgas, anio, mes)
);
END
GO

IF OBJECT_ID('dbo.merma_mes_config') IS NULL
BEGIN
CREATE TABLE dbo.merma_mes_config (
    id           INT      IDENTITY(1,1) PRIMARY KEY,
    anio         INT      NOT NULL,
    mes          INT      NOT NULL,
    precio_litro FLOAT    NOT NULL DEFAULT 18.99,
    updated_by   INT      NULL,
    updated_at   DATETIME NOT NULL DEFAULT GETDATE(),
    CONSTRAINT UQ_merma_mes_config UNIQUE (anio, mes)
);
END
GO

IF OBJECT_ID('dbo.merma_sync_log') IS NULL
BEGIN
CREATE TABLE dbo.merma_sync_log (
    id               INT IDENTITY(1,1) PRIMARY KEY,
    fecha_sync       DATETIME      NOT NULL DEFAULT GETDATE(),
    origen           NVARCHAR(10)  NOT NULL,  -- 'cron' | 'manual'
    usuario          INT           NULL,      -- NULL para cron
    desde            DATE          NOT NULL,
    hasta            DATE          NOT NULL,
    codgas           INT           NOT NULL DEFAULT 0,  -- 0 = todas
    estaciones_ok    INT           NOT NULL DEFAULT 0,
    estaciones_error INT           NOT NULL DEFAULT 0,
    detalle_errores  NVARCHAR(MAX) NULL,
    duracion_seg     FLOAT         NULL
);
END
GO
```

- [ ] **Step 2: Ejecutar el schema contra TG**

Crear runner temporal en el scratchpad (NO en el repo) `run_schema.php`:

```php
<?php
// Ejecuta docs/sql/merma_schema.sql contra TG (los GO se parten manualmente).
$pdo = new PDO("sqlsrv:Server=192.168.0.6;Database=TG;TrustServerCertificate=yes;LoginTimeout=10", 'cguser', 'sahei1712');
$sql = file_get_contents('C:/Users/alejandro.martinez/Desktop/codigo/AplicativoPhp/docs/sql/merma_schema.sql');
foreach (preg_split('/^\s*GO\s*$/mi', $sql) as $batch) {
    $batch = trim($batch);
    if ($batch === '' || stripos($batch, 'USE TG') === 0) continue;
    $pdo->exec($batch);
    echo "OK batch\n";
}
```

Run: `php run_schema.php`
Expected: `OK batch` × 4 sin excepciones.

- [ ] **Step 3: Verificar que las tablas existen**

```php
<?php // check_schema.php (scratchpad)
$pdo = new PDO("sqlsrv:Server=192.168.0.6;Database=TG;TrustServerCertificate=yes", 'cguser', 'sahei1712');
foreach (['merma_diaria','merma_manual','merma_mes_config','merma_sync_log'] as $t) {
    $r = $pdo->query("SELECT COUNT(*) c FROM TG.sys.tables WHERE name = '$t'")->fetch();
    echo "$t: " . ($r['c'] ? 'EXISTE' : 'FALTA') . "\n";
}
```

Run: `php check_schema.php`
Expected: las 4 con `EXISTE`.

- [ ] **Step 4: Commit (repo AplicativoPhp)**

```bash
git add docs/sql/merma_schema.sql
git commit -m "feat(merma): schema de tablas para análisis de merma diaria

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 2: ApiER — consulta por turno de una estación

**Files:**
- Modify: `C:\Users\alejandro.martinez\Desktop\codigo\ApiER\api\modelos\inventarios_estaciones.py` (agregar método al final de la clase `InventariosEstaciones`)

**Interfaces:**
- Consumes: `CONTROLGAS_CONN_STR` (ya importado en el módulo), patrón OPENQUERY existente.
- Produces: `get_inventarios_turnos_estacion(self, servidor, base_datos, codigo_estacion, from_fch, until_fch) -> list[dict]` con claves exactas: `Fecha` (str 'YYYY-MM-DD'), `Producto` (str), `CodProducto` (int), `Turno` (int 11/21/41), `VentasReales`, `Inventario`, `CantidadCompra`, `InventarioInicial`, `InventarioContable`, `Diferencia` (floats; `Inventario`/`Diferencia` pueden ser None si no hay corte físico). Lanza excepción en error SQL (NO regresa [] — el caller distingue "sin datos" de "falló").

- [ ] **Step 1: Agregar el método**

La consulta interna es un port fiel de `TG.dbo.sp_obtener_inventarios_por_turno` (misma normalización de turnos y mismas expresiones, para que los números cuadren con tgr01). Agregar al final de la clase:

```python
    def get_inventarios_turnos_estacion(self, servidor, base_datos, codigo_estacion, from_fch, until_fch):
        """
        Inventarios por TURNO (11/21/41) de una estación. Port fiel de la consulta
        interna de TG.dbo.sp_obtener_inventarios_por_turno para que los números
        cuadren con /supply/tgr01. Fechas en serial ControlGas (días desde 1899-12-31).
        A diferencia de los otros métodos, LANZA la excepción en error SQL para que
        la vista distinga estación caída de estación sin datos.
        """
        from_fch = int(from_fch)
        until_fch = int(until_fch)
        prds = '1,2,3,179,180,181,192,193'
        query = f"""
        SELECT
            V.Fecha, V.Producto, V.CodProducto, V.Turno, V.VentasReales,
            I.Cantidad AS Inventario,
            ISNULL(C.CantidadCompra, 0) AS CantidadCompra,
            ISNULL(IA.Cantidad, 0) AS InventarioInicial,
            ROUND((ISNULL(IA.Cantidad, 0) - V.VentasReales) + ISNULL(C.CantidadCompra, 0), 2) AS InventarioContable,
            ROUND(I.Cantidad - ROUND((IA.Cantidad - V.VentasReales) + ISNULL(C.CantidadCompra, 0), 2), 2) AS Diferencia
        FROM (
            SELECT
                CONVERT(VARCHAR(10), CAST(t1.fch AS DATETIME) - 1, 23) AS Fecha, t3.den AS Producto,
                CASE WHEN t1.nrotur IN (30,31) THEN 41 ELSE t1.nrotur END AS Turno,
                SUM(t1.canven) AS VentasReales, t1.codprd AS CodProducto, t2.codgas AS CodGasolinera, t1.fch
            FROM [{base_datos}].dbo.Ventas t1
            LEFT JOIN [{base_datos}].dbo.Islas t2 ON t1.codisl = t2.cod
            LEFT JOIN [{base_datos}].dbo.Productos t3 ON t1.codprd = t3.cod
            WHERE t1.codprd IN ({prds}) AND t1.fch BETWEEN {from_fch} AND {until_fch}
            GROUP BY t3.den, t1.fch, t2.codgas, t1.codprd, CASE WHEN t1.nrotur IN (30, 31) THEN 41 ELSE t1.nrotur END
        ) V
        LEFT JOIN (
            SELECT
                CONVERT(VARCHAR(10), CAST(fch AS DATETIME) - 1, 23) AS Fecha, SUM(can) AS Cantidad,
                codprd AS CodProducto, codgas AS CodGasolinera,
                CASE WHEN nrotur = 10 THEN 11 WHEN nrotur = 20 THEN 21 WHEN nrotur IN (30, 40) THEN 41 END AS Turno
            FROM [{base_datos}].dbo.StockReal (NOLOCK)
            WHERE fch BETWEEN {from_fch} AND {until_fch} AND codprd IN ({prds}) AND nrotur NOT IN (30, 31)
            GROUP BY fch, codprd, codgas, nrotur
        ) I ON V.Fecha = I.Fecha AND V.CodProducto = I.CodProducto AND V.CodGasolinera = I.CodGasolinera AND V.Turno = I.Turno
        LEFT JOIN (
            SELECT
                CONVERT(VARCHAR(10), CAST(fch AS DATETIME) - 1, 23) AS Fecha, SUM(can) AS Cantidad,
                codprd AS CodProducto, codgas AS CodGasolinera,
                CASE WHEN nrotur = 10 THEN 11 WHEN nrotur = 20 THEN 21 WHEN nrotur IN (30, 40) THEN 41 END AS Turno, fch
            FROM [{base_datos}].dbo.StockReal (NOLOCK)
            WHERE fch BETWEEN ({from_fch} - 1) AND ({until_fch} - 1) AND codprd IN ({prds}) AND nrotur NOT IN (30, 31)
            GROUP BY fch, codprd, codgas, nrotur
        ) IA ON (V.fch - 1) = IA.fch AND V.CodProducto = IA.CodProducto AND V.CodGasolinera = IA.CodGasolinera AND V.Turno = IA.Turno
        LEFT JOIN (
            SELECT
                CONVERT(VARCHAR(10), CAST(fch AS DATETIME) - 1, 23) AS Fecha, codprd AS CodProducto, codgas AS CodGasolinera,
                SUM(ROUND(can, 0)) AS CantidadCompra,
                CASE WHEN nrotur IN (10, 11) THEN 11 WHEN nrotur IN (20, 21) THEN 21 WHEN nrotur IN (30, 31, 40, 41) THEN 41 END AS Turno
            FROM [{base_datos}].dbo.Movimientos (NOLOCK)
            WHERE fch BETWEEN {from_fch} AND {until_fch} AND can > 0 AND codprd IN ({prds})
            GROUP BY fch, codgas, codprd, CASE WHEN nrotur IN (10, 11) THEN 11 WHEN nrotur IN (20, 21) THEN 21 WHEN nrotur IN (30, 31, 40, 41) THEN 41 END
        ) C ON V.Fecha = C.Fecha AND V.CodProducto = C.CodProducto AND V.CodGasolinera = C.CodGasolinera AND V.Turno = C.Turno
        ORDER BY V.Fecha, V.Producto, V.Turno
        """
        query_escaped = query.replace("'", "''")
        sql = f"SELECT * FROM OPENQUERY([{servidor}], '{query_escaped}')"

        with pyodbc.connect(self.conn_str, timeout=60) as conn:
            cursor = conn.cursor()
            cursor.execute(sql)
            cols = [col[0] for col in cursor.description]
            rows = cursor.fetchall()

        results = []
        for row in rows:
            row_dict = {}
            for idx, col in enumerate(cols):
                value = row[idx]
                if isinstance(value, Decimal):
                    value = float(value)
                row_dict[col] = value
            results.append(row_dict)
        return results
```

- [ ] **Step 2: Verificar sintaxis**

Run: `python -c "import ast; ast.parse(open(r'C:\Users\alejandro.martinez\Desktop\codigo\ApiER\api\modelos\inventarios_estaciones.py', encoding='utf-8').read()); print('OK')"`
Expected: `OK`

- [ ] **Step 3: Probar el método contra una estación real (CLI, sin Django)**

Script scratchpad `test_turnos.py` (usar una estación conocida; Lerdo = Codigo 2 según `TG.dbo.Estaciones` — confirmar Servidor/BaseDatos con la query del paso):

```python
import sys, pyodbc
sys.path.insert(0, r'C:\Users\alejandro.martinez\Desktop\codigo\ApiER')
from api.modelos.inventarios_estaciones import InventariosEstaciones
from api.db_connections import CONTROLGAS_CONN_STR
from datetime import date

# Estación de prueba: la primera activa
with pyodbc.connect(CONTROLGAS_CONN_STR) as c:
    cur = c.cursor()
    cur.execute("SELECT TOP 1 Servidor, BaseDatos, Codigo, Nombre FROM TG.dbo.Estaciones WHERE Codigo NOT IN (0,4,20) ORDER BY Codigo")
    srv, db, cod, nom = cur.fetchone()

fch = (date.today() - date(1899, 12, 31)).days - 1   # ayer operativo... fch de ayer
m = InventariosEstaciones()
rows = m.get_inventarios_turnos_estacion(srv, db, cod, fch, fch)
print(f"{nom}: {len(rows)} filas")
for r in rows[:6]:
    print(r)
```

Run: `python test_turnos.py` (desde el scratchpad, con el venv de ApiER si existe)
Expected: `>0` filas con claves `Fecha, Producto, CodProducto, Turno, VentasReales, Inventario, CantidadCompra, InventarioInicial, InventarioContable, Diferencia` y turnos en {11, 21, 41}.

- [ ] **Step 4: Commit (repo ApiER)**

```bash
git -C C:/Users/alejandro.martinez/Desktop/codigo/ApiER add api/modelos/inventarios_estaciones.py
git -C C:/Users/alejandro.martinez/Desktop/codigo/ApiER commit -m "feat(inventarios): consulta de inventarios por turno para merma diaria

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 3: ApiER — endpoint paralelo `/api/inventarios_turnos/`

**Files:**
- Modify: `C:\Users\alejandro.martinez\Desktop\codigo\ApiER\api\TG_php\views.py` (agregar vista al final)
- Modify: `C:\Users\alejandro.martinez\Desktop\codigo\ApiER\api\urls.py` (import + path)

**Interfaces:**
- Consumes: `InventariosEstaciones.get_inventarios_turnos_estacion(...)` (Task 2), `EstacionDespachos.estaciones()` (existente: regresa dicts con `Servidor`, `BaseDatos`, `Codigo`, `Nombre`; ya excluye 0, 4, 20).
- Produces: `POST /api/inventarios_turnos/` body `{"from": "YYYY-MM-DD", "to": "YYYY-MM-DD", "codgas": 0}` (form-data o JSON) → respuesta 200:
  `{"resultados": [{"Codigo": int, "Nombre": str, "filas": [<dicts de Task 2>]}], "errores": [{"Codigo": int, "Nombre": str, "error": str}], "duracion_seg": float}`.
  El controlador PHP de Task 5 consume exactamente este contrato.

- [ ] **Step 1: Agregar la vista en `api/TG_php/views.py`**

Al final del archivo:

```python
@api_view(['POST'])
def inventarios_turnos_distribuido(request):
    """
    Inventarios por TURNO de todas las estaciones en paralelo (merma diaria).
    Fechas YYYY-MM-DD -> serial ControlGas (días desde 1899-12-31, como
    dateToInt() en AplicativoPhp). NO usa YYYYMMDD.
    """
    from datetime import datetime, date
    import time

    from_date = request.data.get('from')
    to_date = request.data.get('to')
    codgas = int(request.data.get('codgas') or 0)
    if not from_date or not to_date:
        return Response({"detail": "Los parámetros 'from' y 'to' son requeridos"},
                        status=status.HTTP_400_BAD_REQUEST)
    try:
        from_fch = (datetime.strptime(from_date, '%Y-%m-%d').date() - date(1899, 12, 31)).days
        until_fch = (datetime.strptime(to_date, '%Y-%m-%d').date() - date(1899, 12, 31)).days
    except ValueError:
        return Response({"detail": "Fechas inválidas, formato esperado YYYY-MM-DD"},
                        status=status.HTTP_400_BAD_REQUEST)

    inicio = time.time()
    estaciones = EstacionDespachos().estaciones()
    if codgas:
        estaciones = [e for e in estaciones if int(e["Codigo"]) == codgas]
    if not estaciones:
        return Response({"detail": "No se encontraron estaciones"}, status=status.HTTP_404_NOT_FOUND)

    inventarios_model = InventariosEstaciones()
    resultados, errores = [], []
    with ThreadPoolExecutor(max_workers=40) as executor:
        future_to_est = {
            executor.submit(
                inventarios_model.get_inventarios_turnos_estacion,
                est["Servidor"], est["BaseDatos"], est["Codigo"], from_fch, until_fch
            ): est
            for est in estaciones
        }
        for future in as_completed(future_to_est):
            est = future_to_est[future]
            try:
                filas = future.result()
                resultados.append({"Codigo": est["Codigo"], "Nombre": est["Nombre"], "filas": filas})
            except Exception as e:
                errores.append({"Codigo": est["Codigo"], "Nombre": est["Nombre"], "error": str(e)})

    return Response({
        "resultados": resultados,
        "errores": errores,
        "duracion_seg": round(time.time() - inicio, 1),
    }, status=status.HTTP_200_OK)
```

- [ ] **Step 2: Registrar la ruta en `api/urls.py`**

En el bloque de imports `from .TG_php.views import (...)`, después de `inventarios_detalles_distribuido,` agregar:

```python
    inventarios_turnos_distribuido,
```

En `urlpatterns`, después de `path('inventarios/detalles/',  inventarios_detalles_distribuido),` agregar:

```python
    path('inventarios_turnos/', inventarios_turnos_distribuido),
```

- [ ] **Step 3: Verificar sintaxis de ambos archivos**

Run: `python -c "import ast; [ast.parse(open(f, encoding='utf-8').read()) for f in [r'C:\Users\alejandro.martinez\Desktop\codigo\ApiER\api\TG_php\views.py', r'C:\Users\alejandro.martinez\Desktop\codigo\ApiER\api\urls.py']]; print('OK')"`
Expected: `OK`

- [ ] **Step 4: Probar el endpoint con Django local**

Levantar Django local temporal SOLO para prueba (esto no es el server PHP del usuario; sí está permitido): en una terminal del repo ApiER: `python manage.py runserver 8099` (en background). Luego:

Run: `curl -s -X POST http://127.0.0.1:8099/api/inventarios_turnos/ -H "Content-Type: application/json" -d "{\"from\": \"<AYER>\", \"to\": \"<AYER>\", \"codgas\": 0}"` (sustituir `<AYER>` por la fecha de ayer YYYY-MM-DD)
Expected: JSON con `resultados` (≥30 estaciones con `filas`), `errores` (posibles estaciones caídas), `duracion_seg` < 60. Matar el runserver al terminar.

- [ ] **Step 5: Verificar números contra tgr01**

Tomar una estación y fecha de la respuesta y comparar 3 filas (producto+turno) contra `http://totalgasonline.net:400/supply/tgr01?from=<AYER>&to=<AYER>&codgas=<COD>&product=0&shift=0`.
Expected: `VentasReales`, `Inventario`, `CantidadCompra`, `InventarioInicial`, `InventarioContable`, `Diferencia` idénticos por fila.

- [ ] **Step 6: Commit (repo ApiER)**

```bash
git -C C:/Users/alejandro.martinez/Desktop/codigo/ApiER add api/TG_php/views.py api/urls.py
git -C C:/Users/alejandro.martinez/Desktop/codigo/ApiER commit -m "feat(inventarios): endpoint paralelo /api/inventarios_turnos/ para merma diaria

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

- [ ] **Step 7: CHECKPOINT USUARIO — desplegar ApiER por SFTP a 192.168.0.109**

Avisar al usuario que despliegue y reinicie el servicio de ApiER. Verificar después:
Run: `curl -s -X POST http://192.168.0.109:82/api/inventarios_turnos/ -d "from=<AYER>&to=<AYER>&codgas=0"`
Expected: mismo JSON del Step 4.

---

### Task 4: PHP — `MermaDiariaModel`

**Files:**
- Create: `_assets/models/MermaDiariaModel.php`

**Interfaces:**
- Consumes: clase base `Model` (`$this->sql` = MySqlPdoHandler con `select/insert/update/delete`), tablas de Task 1.
- Produces (firmas exactas que usa el controlador de Tasks 5–8):
  - `get_estaciones(): array` — `[['Codigo'=>int,'Nombre'=>string],...]` de `TG.dbo.Estaciones` sin (0,4,20), orden por Nombre.
  - `replace_station_range(int $codgas, string $estacion, string $desde, string $hasta, array $filas): int` — borra e inserta el rango de UNA estación; regresa filas insertadas. `$filas` = filas crudas del API (claves de Task 2).
  - `get_resumen_mensual(int $anio, int $mes): array` — por codgas: `merma_maxima, merma_super, merma_diesel, merma_total, venta_total, dias_con_datos, last_update`.
  - `get_fechas_por_estacion(int $anio, int $mes): array` — `[codgas => ['2026-07-01', ...]]`.
  - `get_detalle_mensual(int $codgas, int $anio, int $mes): array` — filas fecha+turno con columnas pivote `vr_/compras_/cont_/fis_/dif_` × `maxima/super/diesel`.
  - `get_manual(int $anio, int $mes): array` — `[codgas => row merma_manual]`.
  - `save_manual(int $codgas, int $anio, int $mes, string $campo, $valor, int $usuario): bool` — `$campo` ∈ {merma_sd_maxima, merma_sd_super, merma_sd_diesel, comentarios}.
  - `get_precio(int $anio, int $mes): float` — default 18.99 si no hay fila.
  - `save_precio(int $anio, int $mes, float $precio, int $usuario): bool`
  - `add_sync_log(string $origen, ?int $usuario, string $desde, string $hasta, int $codgas, int $ok, int $err, string $detalle, float $duracion): void`

- [ ] **Step 1: Escribir el modelo completo**

```php
<?php

/**
 * Snapshot y captura del módulo Análisis de Merma Diaria (/merma/...).
 * Tablas: TG.dbo.merma_diaria | merma_manual | merma_mes_config | merma_sync_log
 * Schema: docs/sql/merma_schema.sql
 */
class MermaDiariaModel extends Model
{
    /** Familias de producto para presentación (codprd reales en el snapshot). */
    public const FAMILIAS = [
        'maxima' => [1, 179, 192],
        'super'  => [2, 180, 193],
        'diesel' => [3, 181],
    ];

    private function familiaCase(string $familia, string $columna): string
    {
        $codes = implode(',', self::FAMILIAS[$familia]);
        return "SUM(CASE WHEN codprd IN ($codes) THEN $columna END)";
    }

    public function get_estaciones(): array
    {
        $query = 'SELECT Codigo, Nombre FROM [TG].[dbo].[Estaciones]
                  WHERE Codigo NOT IN (0, 4, 20) ORDER BY Nombre;';
        return $this->sql->select($query) ?: [];
    }

    /**
     * Reemplaza el snapshot de UNA estación en un rango de fechas (delete +
     * insert dentro de transacción). Solo se llama con estaciones que SÍ
     * respondieron, para no borrar datos de estaciones caídas.
     */
    public function replace_station_range(int $codgas, string $estacion, string $desde, string $hasta, array $filas): int
    {
        $this->sql->beginTransaction();
        try {
            $this->sql->delete(
                'DELETE FROM [TG].[dbo].[merma_diaria] WHERE codgas = ? AND fecha BETWEEN ? AND ?;',
                [$codgas, $desde, $hasta]
            );
            $insertadas = 0;
            foreach ($filas as $f) {
                // Turnos fuera de 11/21/41 no deben existir (el SP normaliza), se ignoran por seguridad
                if (!in_array((int)$f['Turno'], [11, 21, 41])) continue;
                $this->sql->insert(
                    'INSERT INTO [TG].[dbo].[merma_diaria]
                     (fecha, codgas, estacion, codprd, producto, turno, ventas_reales,
                      inv_fisico, compras, inv_inicial, inv_contable, diferencia, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE());',
                    [
                        $f['Fecha'], $codgas, $estacion, (int)$f['CodProducto'], $f['Producto'],
                        (int)$f['Turno'], $f['VentasReales'], $f['Inventario'], $f['CantidadCompra'],
                        $f['InventarioInicial'], $f['InventarioContable'], $f['Diferencia'],
                    ]
                );
                $insertadas++;
            }
            $this->sql->commit();
            return $insertadas;
        } catch (Exception $e) {
            $this->sql->rollBack();
            throw $e;
        }
    }

    public function get_resumen_mensual(int $anio, int $mes): array
    {
        $query = 'SELECT codgas, MAX(estacion) AS estacion,
                    ' . $this->familiaCase('maxima', 'diferencia') . ' AS merma_maxima,
                    ' . $this->familiaCase('super', 'diferencia') . '  AS merma_super,
                    ' . $this->familiaCase('diesel', 'diferencia') . ' AS merma_diesel,
                    SUM(diferencia)          AS merma_total,
                    SUM(ventas_reales)       AS venta_total,
                    COUNT(DISTINCT fecha)    AS dias_con_datos,
                    MAX(updated_at)          AS last_update
                  FROM [TG].[dbo].[merma_diaria]
                  WHERE YEAR(fecha) = ? AND MONTH(fecha) = ?
                  GROUP BY codgas;';
        $rows = $this->sql->select($query, [$anio, $mes]) ?: [];
        $out = [];
        foreach ($rows as $r) $out[(int)$r['codgas']] = $r;
        return $out;
    }

    public function get_fechas_por_estacion(int $anio, int $mes): array
    {
        $query = 'SELECT DISTINCT codgas, fecha FROM [TG].[dbo].[merma_diaria]
                  WHERE YEAR(fecha) = ? AND MONTH(fecha) = ? ORDER BY codgas, fecha;';
        $rows = $this->sql->select($query, [$anio, $mes]) ?: [];
        $out = [];
        foreach ($rows as $r) $out[(int)$r['codgas']][] = substr($r['fecha'], 0, 10);
        return $out;
    }

    public function get_detalle_mensual(int $codgas, int $anio, int $mes): array
    {
        $cols = [];
        foreach (self::FAMILIAS as $fam => $codes) {
            $cols[] = $this->familiaCase($fam, 'ventas_reales') . " AS vr_$fam";
            $cols[] = $this->familiaCase($fam, 'compras') . " AS compras_$fam";
            $cols[] = $this->familiaCase($fam, 'inv_contable') . " AS cont_$fam";
            $cols[] = $this->familiaCase($fam, 'inv_fisico') . " AS fis_$fam";
            $cols[] = $this->familiaCase($fam, 'diferencia') . " AS dif_$fam";
        }
        $query = 'SELECT fecha, turno, ' . implode(', ', $cols) . '
                  FROM [TG].[dbo].[merma_diaria]
                  WHERE codgas = ? AND YEAR(fecha) = ? AND MONTH(fecha) = ?
                  GROUP BY fecha, turno
                  ORDER BY fecha, turno;';
        return $this->sql->select($query, [$codgas, $anio, $mes]) ?: [];
    }

    public function get_manual(int $anio, int $mes): array
    {
        $rows = $this->sql->select(
            'SELECT * FROM [TG].[dbo].[merma_manual] WHERE anio = ? AND mes = ?;',
            [$anio, $mes]
        ) ?: [];
        $out = [];
        foreach ($rows as $r) $out[(int)$r['codgas']] = $r;
        return $out;
    }

    public function save_manual(int $codgas, int $anio, int $mes, string $campo, $valor, int $usuario): bool
    {
        // Whitelist de columnas: el nombre viene del cliente
        if (!in_array($campo, ['merma_sd_maxima', 'merma_sd_super', 'merma_sd_diesel', 'comentarios'])) {
            return false;
        }
        if ($valor === '') $valor = null;
        $exists = $this->sql->select(
            'SELECT id FROM [TG].[dbo].[merma_manual] WHERE codgas = ? AND anio = ? AND mes = ?;',
            [$codgas, $anio, $mes]
        );
        if ($exists) {
            $this->sql->update(
                "UPDATE [TG].[dbo].[merma_manual]
                 SET $campo = ?, updated_by = ?, updated_at = GETDATE() WHERE id = ?;",
                [$valor, $usuario, $exists[0]['id']]
            );
        } else {
            $this->sql->insert(
                "INSERT INTO [TG].[dbo].[merma_manual] (codgas, anio, mes, $campo, updated_by)
                 VALUES (?, ?, ?, ?, ?);",
                [$codgas, $anio, $mes, $valor, $usuario]
            );
        }
        return true;
    }

    public function get_precio(int $anio, int $mes): float
    {
        $rows = $this->sql->select(
            'SELECT precio_litro FROM [TG].[dbo].[merma_mes_config] WHERE anio = ? AND mes = ?;',
            [$anio, $mes]
        );
        return $rows ? (float)$rows[0]['precio_litro'] : 18.99;
    }

    public function save_precio(int $anio, int $mes, float $precio, int $usuario): bool
    {
        $exists = $this->sql->select(
            'SELECT id FROM [TG].[dbo].[merma_mes_config] WHERE anio = ? AND mes = ?;',
            [$anio, $mes]
        );
        if ($exists) {
            $this->sql->update(
                'UPDATE [TG].[dbo].[merma_mes_config]
                 SET precio_litro = ?, updated_by = ?, updated_at = GETDATE() WHERE id = ?;',
                [$precio, $usuario, $exists[0]['id']]
            );
        } else {
            $this->sql->insert(
                'INSERT INTO [TG].[dbo].[merma_mes_config] (anio, mes, precio_litro, updated_by)
                 VALUES (?, ?, ?, ?);',
                [$anio, $mes, $precio, $usuario]
            );
        }
        return true;
    }

    public function add_sync_log(string $origen, ?int $usuario, string $desde, string $hasta, int $codgas, int $ok, int $err, string $detalle, float $duracion): void
    {
        $this->sql->insert(
            'INSERT INTO [TG].[dbo].[merma_sync_log]
             (origen, usuario, desde, hasta, codgas, estaciones_ok, estaciones_error, detalle_errores, duracion_seg)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?);',
            [$origen, $usuario, $desde, $hasta, $codgas, $ok, $err, $detalle, $duracion]
        );
    }
}
```

- [ ] **Step 2: Verificar sintaxis**

Run: `php -l _assets/models/MermaDiariaModel.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Probar el modelo por CLI**

Script scratchpad `test_model.php`:

```php
<?php
chdir('C:/Users/alejandro.martinez/Desktop/codigo/AplicativoPhp');
$_SERVER['REQUEST_URI'] = '/cli';
$_SERVER['DOCUMENT_ROOT'] = getcwd();
require '_assets/classes/header.class.php';
require '_assets/classes/php_functions.php';
require '_assets/models/Model.php';
require '_assets/models/MermaDiariaModel.php';

$m = new MermaDiariaModel();
$est = $m->get_estaciones();
echo "Estaciones: " . count($est) . "\n";

// insertar 2 filas dummy y leerlas
$filas = [
    ['Fecha'=>'2020-01-15','CodProducto'=>179,'Producto'=>'T-Maxima','Turno'=>11,'VentasReales'=>100.0,'Inventario'=>5000.0,'CantidadCompra'=>0,'InventarioInicial'=>5100.0,'InventarioContable'=>5000.0,'Diferencia'=>0.0],
    ['Fecha'=>'2020-01-15','CodProducto'=>180,'Producto'=>'T-Super','Turno'=>21,'VentasReales'=>50.0,'Inventario'=>null,'CantidadCompra'=>0,'InventarioInicial'=>2000.0,'InventarioContable'=>1950.0,'Diferencia'=>null],
];
$n = $m->replace_station_range(999, 'PRUEBA', '2020-01-15', '2020-01-15', $filas);
echo "Insertadas: $n\n";
$res = $m->get_resumen_mensual(2020, 1);
print_r($res[999] ?? 'NO ENCONTRADO');
$m->save_manual(999, 2020, 1, 'comentarios', 'prueba', 1);
$man = $m->get_manual(2020, 1);
echo "\nComentario: " . ($man[999]['comentarios'] ?? 'FALTA') . "\n";
echo "Precio default: " . $m->get_precio(2020, 1) . "\n";
// limpiar
$m->sql->delete('DELETE FROM [TG].[dbo].[merma_diaria] WHERE codgas = 999;');
$m->sql->delete('DELETE FROM [TG].[dbo].[merma_manual] WHERE codgas = 999;');
echo "LIMPIO\n";
```

Run: `php test_model.php`
Expected: `Estaciones: ~37`, `Insertadas: 2`, resumen con `merma_maxima = 0` y `venta_total = 150`, `Comentario: prueba`, `Precio default: 18.99`, `LIMPIO`.

- [ ] **Step 4: Commit**

```bash
git add _assets/models/MermaDiariaModel.php
git commit -m "feat(merma): modelo MermaDiariaModel (snapshot, captura manual, log)

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 5: PHP — controlador `merma.php` con `/merma/sync` y `/merma/sync_diario`

**Files:**
- Create: `_assets/controllers/merma.php`

**Interfaces:**
- Consumes: `MermaDiariaModel` (Task 4), endpoint ApiER de Task 3, `authorized()` y `json_output()` de `php_functions.php`, `CRON_SECRET`.
- Produces:
  - Clase `Merma` con `__construct($twig)`; propiedad `$this->route = 'views/merma/'`.
  - `POST /merma/sync` (`from`, `to`, `codgas` opcional; sesión con permiso 33 **o** `cron_token`) → JSON `{success, message, estaciones_ok, estaciones_error, errores: [..], filas, duracion_seg}`.
  - `GET/POST /merma/sync_diario?cron_token=...` → sincroniza D-2..D-1, mismo JSON.
  - Métodos de vistas `analisis()`, `detalle($codgas)` y de captura se agregan en Tasks 6–8; este task deja el archivo con la estructura y el sync funcionando.

- [ ] **Step 1: Escribir el controlador base**

```php
<?php

/**
 * Análisis de Merma Diaria (Abastos).
 *
 * Snapshot diario de inventarios por turno de todas las estaciones
 * (TG.dbo.merma_diaria) llenado vía ApiER en paralelo; vistas de resumen
 * mensual y detalle por estación; captura manual de merma s/d y comentarios.
 *
 * Rutas: /merma/[metodo]  (autocargado por index.php)
 * Spec:  docs/superpowers/specs/2026-07-13-analisis-merma-diaria-design.md
 * Schema: docs/sql/merma_schema.sql
 */
class Merma
{
    private const PERM_VER = 33;   // Reportes de Abastos
    private const API_URL  = 'http://192.168.0.109:82/api/inventarios_turnos/';

    private $twig;
    private $route;
    private $mermaModel;

    public function __construct($twig)
    {
        $this->twig       = $twig;
        $this->route      = 'views/merma/';
        $this->mermaModel = new MermaDiariaModel();
    }

    /* ===================================================================== */
    /* Sincronización                                                        */
    /* ===================================================================== */

    /** ¿La petición viene del cron con token válido? */
    private function isCron(): bool
    {
        $token = $_POST['cron_token'] ?? $_GET['cron_token'] ?? null;
        return defined('CRON_SECRET') && $token === CRON_SECRET;
    }

    /**
     * Consulta ApiER y reemplaza el snapshot del rango. Regresa el resumen
     * que responden tanto /merma/sync como /merma/sync_diario.
     */
    private function runSync(string $desde, string $hasta, int $codgas, string $origen, ?int $usuario): array
    {
        $inicio = microtime(true);

        $ch = curl_init(self::API_URL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'from' => $desde, 'to' => $hasta, 'codgas' => $codgas,
        ]));
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        $response = curl_exec($ch);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['success' => false, 'message' => "No se pudo contactar ApiER: $curlErr"];
        }
        $api = json_decode($response, true);
        if (!isset($api['resultados'])) {
            $detail = $api['detail'] ?? substr((string)$response, 0, 200);
            return ['success' => false, 'message' => "Respuesta inesperada de ApiER: $detail"];
        }

        $filasTotal = 0;
        foreach ($api['resultados'] as $est) {
            $filasTotal += $this->mermaModel->replace_station_range(
                (int)$est['Codigo'], $est['Nombre'], $desde, $hasta, $est['filas']
            );
        }

        $errores  = $api['errores'] ?? [];
        $duracion = round(microtime(true) - $inicio, 1);
        $detalle  = $errores
            ? implode('; ', array_map(fn($e) => $e['Nombre'] . ': ' . substr($e['error'], 0, 150), $errores))
            : '';

        $this->mermaModel->add_sync_log(
            $origen, $usuario, $desde, $hasta, $codgas,
            count($api['resultados']), count($errores), $detalle, $duracion
        );

        return [
            'success'          => true,
            'message'          => count($api['resultados']) . ' estaciones sincronizadas'
                                  . ($errores ? ', ' . count($errores) . ' sin conexión' : ''),
            'estaciones_ok'    => count($api['resultados']),
            'estaciones_error' => count($errores),
            'errores'          => array_map(fn($e) => $e['Nombre'], $errores),
            'filas'            => $filasTotal,
            'duracion_seg'     => $duracion,
        ];
    }

    /**
     * Botón "Actualizar datos" (POST from, to, codgas) — permiso 33 o cron_token.
     */
    public function sync(): void
    {
        set_time_limit(0);
        if (!$this->isCron() && !authorized(self::PERM_VER)) {
            json_output(['success' => false, 'message' => 'No autorizado']);
            return;
        }
        $desde  = $_POST['from'] ?? null;
        $hasta  = $_POST['to'] ?? null;
        $codgas = (int)($_POST['codgas'] ?? 0);
        if (!$desde || !$hasta
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)
            || $desde > $hasta) {
            json_output(['success' => false, 'message' => 'Rango de fechas inválido']);
            return;
        }
        // Tope de 40 días por sync para no tumbar las estaciones
        if ((strtotime($hasta) - strtotime($desde)) / 86400 > 40) {
            json_output(['success' => false, 'message' => 'El rango máximo por sincronización es de 40 días']);
            return;
        }
        $usuario = $_SESSION['tg_user']['Id'] ?? null;
        json_output($this->runSync($desde, $hasta, $codgas, $this->isCron() ? 'cron' : 'manual', $usuario));
    }

    /**
     * Cron de madrugada: sincroniza D-2 y D-1 de todas las estaciones.
     * GET/POST /merma/sync_diario?cron_token=CRON_SECRET
     */
    public function sync_diario(): void
    {
        set_time_limit(0);
        if (!$this->isCron()) {
            json_output(['success' => false, 'message' => 'No autorizado']);
            return;
        }
        $desde = date('Y-m-d', strtotime('-2 days'));
        $hasta = date('Y-m-d', strtotime('-1 day'));
        json_output($this->runSync($desde, $hasta, 0, 'cron', null));
    }
}
```

- [ ] **Step 2: Verificar sintaxis**

Run: `php -l _assets/controllers/merma.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Probar sync vía cron token contra el server del usuario**

(Requiere que Task 3 Step 7 esté desplegado.) Con el server PHP que el usuario tiene corriendo (totalgasonline.net:400 o el que indique):

Run: `curl -s "http://totalgasonline.net:400/merma/sync_diario?cron_token=TG_CRON_2024"`
Expected: `{"success":true,"message":"NN estaciones sincronizadas...","filas":<numero>,...}` con `filas > 0`.

- [ ] **Step 4: Verificar el snapshot en BD**

Script scratchpad `check_snapshot.php` (mismo bootstrap del test de Task 4):

```php
<?php
$pdo = new PDO("sqlsrv:Server=192.168.0.6;Database=TG;TrustServerCertificate=yes", 'cguser', 'sahei1712');
$r = $pdo->query("SELECT COUNT(*) c, COUNT(DISTINCT codgas) e, MIN(fecha) f1, MAX(fecha) f2 FROM TG.dbo.merma_diaria")->fetch();
print_r($r);
$r = $pdo->query("SELECT TOP 3 * FROM TG.dbo.merma_sync_log ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
print_r($r);
```

Run: `php check_snapshot.php`
Expected: `c > 0`, `e ≈ 35+`, fechas = D-2/D-1; el log con `origen = cron` y `estaciones_ok > 0`.

- [ ] **Step 5: Commit**

```bash
git add _assets/controllers/merma.php
git commit -m "feat(merma): controlador con sincronización vía ApiER (manual y cron)

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 6: Vista resumen mensual `/merma/analisis` + sidebar

**Files:**
- Modify: `_assets/controllers/merma.php` (agregar método `analisis()`)
- Create: `views/merma/analisis.html`
- Create: `_assets/js/merma.js`
- Modify: `views/layouts/sidebar.html` (después del item de tgr01, ~línea 206)

**Interfaces:**
- Consumes: `get_estaciones()`, `get_resumen_mensual()`, `get_fechas_por_estacion()`, `get_manual()`, `get_precio()` del modelo.
- Produces: ruta `GET /merma/analisis?anio=&mes=`; variables Twig: `anio`, `mes`, `filas` (array por estación con datos+manual+faltantes), `totales`, `kpis`, `precio`. El JS produce los handlers usados también por Task 7 (`.merma-sd`, `.merma-comentario`, `#precio_litro`, modal `#syncModal`).

- [ ] **Step 1: Agregar `analisis()` al controlador**

Insertar antes del bloque `/* Sincronización */`:

```php
    /* ===================================================================== */
    /* Vistas                                                                */
    /* ===================================================================== */

    /** Resumen mensual (equivalente a la hoja MERMA MENSUAL del Excel). */
    public function analisis(): void
    {
        if (!authorized(self::PERM_VER)) {
            (new Errors())->get404();
            return;
        }
        $anio = (int)($_GET['anio'] ?? date('Y'));
        $mes  = (int)($_GET['mes'] ?? date('n'));
        if ($mes < 1 || $mes > 12) $mes = (int)date('n');

        $estaciones = $this->mermaModel->get_estaciones();
        $resumen    = $this->mermaModel->get_resumen_mensual($anio, $mes);
        $manual     = $this->mermaModel->get_manual($anio, $mes);
        $fechas     = $this->mermaModel->get_fechas_por_estacion($anio, $mes);
        $precio     = $this->mermaModel->get_precio($anio, $mes);

        // Días esperados del mes: hasta ayer si es el mes en curso, o el mes completo
        $ultimoDia = ($anio == date('Y') && $mes == date('n'))
            ? max(1, (int)date('j') - 1)
            : (int)date('t', mktime(0, 0, 0, $mes, 1, $anio));
        $diasEsperados = [];
        for ($d = 1; $d <= $ultimoDia; $d++) {
            $diasEsperados[] = sprintf('%04d-%02d-%02d', $anio, $mes, $d);
        }

        $filas   = [];
        $totales = ['maxima' => 0, 'super' => 0, 'diesel' => 0, 'total' => 0, 'venta' => 0,
                    'sd_maxima' => 0, 'sd_super' => 0, 'sd_diesel' => 0];
        foreach ($estaciones as $est) {
            $cod = (int)$est['Codigo'];
            $r   = $resumen[$cod] ?? null;
            $m   = $manual[$cod] ?? null;
            $faltantes = array_values(array_diff($diasEsperados, $fechas[$cod] ?? []));
            $fila = [
                'codgas'      => $cod,
                'nombre'      => $est['Nombre'],
                'maxima'      => $r['merma_maxima'] ?? null,
                'super'       => $r['merma_super'] ?? null,
                'diesel'      => $r['merma_diesel'] ?? null,
                'total'       => $r['merma_total'] ?? null,
                'venta'       => $r['venta_total'] ?? null,
                'pct'         => ($r && (float)$r['venta_total'] != 0)
                                 ? (float)$r['merma_total'] / (float)$r['venta_total'] * 100 : null,
                'sd_maxima'   => $m['merma_sd_maxima'] ?? null,
                'sd_super'    => $m['merma_sd_super'] ?? null,
                'sd_diesel'   => $m['merma_sd_diesel'] ?? null,
                'comentarios' => $m['comentarios'] ?? '',
                'faltantes'   => $faltantes,
            ];
            $filas[] = $fila;
            foreach (['maxima', 'super', 'diesel', 'total', 'venta'] as $k) {
                $key = $k === 'venta' ? 'venta' : $k;
                $totales[$key] += (float)($fila[$k === 'venta' ? 'venta' : $k] ?? 0);
            }
            $totales['sd_maxima'] += (float)($fila['sd_maxima'] ?? 0);
            $totales['sd_super']  += (float)($fila['sd_super'] ?? 0);
            $totales['sd_diesel'] += (float)($fila['sd_diesel'] ?? 0);
        }

        // KPIs: promedio diario sobre días con datos, proyección y valorización
        $diasConDatos = count(array_unique(array_merge(...array_values($fechas ?: [[]]))));
        $diasDelMes   = (int)date('t', mktime(0, 0, 0, $mes, 1, $anio));
        $promedio     = $diasConDatos > 0 ? $totales['total'] / $diasConDatos : 0;
        $kpis = [
            'dias_con_datos' => $diasConDatos,
            'promedio'       => $promedio,
            'proyeccion'     => $promedio * $diasDelMes,
            'valorizacion'   => $promedio * $diasDelMes * $precio,
        ];

        echo $this->twig->render($this->route . 'analisis.html',
            compact('anio', 'mes', 'filas', 'totales', 'kpis', 'precio'));
    }
```

- [ ] **Step 2: Crear la vista `views/merma/analisis.html`**

```twig
{% extends "views/layouts/base.html" %}
{% block title %}Análisis de merma diaria{% endblock %}
{% block menutitle %}Análisis de merma diaria{% endblock %}
{% block content %}

<div class="card">
    <div class="card-body">
        <form action="#" method="get" class="row align-items-end g-2">
            <div class="col-auto">
                <label for="mes">Mes:</label>
                <select class="form-control" name="mes" id="mes">
                    {% set meses = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'] %}
                    {% for m in 1..12 %}
                    <option value="{{ m }}" {{ m == mes ? 'selected' : '' }}>{{ meses[m-1] }}</option>
                    {% endfor %}
                </select>
            </div>
            <div class="col-auto">
                <label for="anio">Año:</label>
                <select class="form-control" name="anio" id="anio">
                    {% for a in ('now'|date('Y')) .. 2025 %}
                    <option value="{{ a }}" {{ a == anio ? 'selected' : '' }}>{{ a }}</option>
                    {% endfor %}
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary">Buscar</button>
            </div>
            <div class="col-auto ms-auto">
                <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#syncModal">
                    <i class="fas fa-sync"></i> Actualizar datos
                </button>
            </div>
        </form>
    </div>
</div>

<div class="card table-responsive">
    <table class="table table-sm" id="merma_table" style="font-size: small">
        <thead>
            <tr>
                <th></th>
                <th colspan="5" class="text-center border-start">MERMA DEL MES (LTS)</th>
                <th></th>
                <th colspan="3" class="text-center border-start">MERMA S/D (LTS)</th>
                <th></th>
            </tr>
            <tr>
                <th>ESTACIÓN</th>
                <th class="border-start">MAXIMA</th>
                <th>SUPER</th>
                <th>DIESEL</th>
                <th>TOTAL</th>
                <th>VTA TOTAL</th>
                <th>% MERMA</th>
                <th class="border-start">MAXIMA</th>
                <th>SUPER</th>
                <th>DIESEL</th>
                <th style="min-width: 220px;">COMENTARIOS</th>
            </tr>
        </thead>
        <tbody>
            {% for f in filas %}
            <tr>
                <td>
                    <a href="/merma/detalle/{{ f.codgas }}?anio={{ anio }}&mes={{ mes }}">{{ f.nombre }}</a>
                    {% if f.faltantes|length > 0 %}
                    <i class="fas fa-exclamation-triangle text-warning"
                       title="Sin datos: {{ f.faltantes|join(', ') }}"></i>
                    {% endif %}
                </td>
                <td class="border-start text-end">{{ f.maxima is null ? '-' : f.maxima|number_format(0, '.', ',') }}</td>
                <td class="text-end">{{ f.super is null ? '-' : f.super|number_format(0, '.', ',') }}</td>
                <td class="text-end">{{ f.diesel is null ? '-' : f.diesel|number_format(0, '.', ',') }}</td>
                <td class="text-end fw-bold">{{ f.total is null ? '-' : f.total|number_format(0, '.', ',') }}</td>
                <td class="text-end">{{ f.venta is null ? '-' : f.venta|number_format(0, '.', ',') }}</td>
                {% set pctabs = f.pct is null ? 0 : (f.pct < 0 ? -f.pct : f.pct) %}
                <td class="text-end {{ f.pct is null ? '' : (pctabs > 1 ? 'text-danger fw-bold' : (pctabs > 0.5 ? 'text-warning fw-bold' : 'text-success')) }}">
                    {{ f.pct is null ? '-' : f.pct|number_format(2) ~ '%' }}
                </td>
                <td class="border-start p-1" style="max-width: 80px;">
                    <input type="number" step="any" class="form-control form-control-sm merma-sd"
                           data-codgas="{{ f.codgas }}" data-campo="merma_sd_maxima" value="{{ f.sd_maxima }}">
                </td>
                <td class="p-1" style="max-width: 80px;">
                    <input type="number" step="any" class="form-control form-control-sm merma-sd"
                           data-codgas="{{ f.codgas }}" data-campo="merma_sd_super" value="{{ f.sd_super }}">
                </td>
                <td class="p-1" style="max-width: 80px;">
                    <input type="number" step="any" class="form-control form-control-sm merma-sd"
                           data-codgas="{{ f.codgas }}" data-campo="merma_sd_diesel" value="{{ f.sd_diesel }}">
                </td>
                <td class="p-1">
                    <input type="text" class="form-control form-control-sm merma-comentario"
                           data-codgas="{{ f.codgas }}" value="{{ f.comentarios }}">
                </td>
            </tr>
            {% endfor %}
        </tbody>
        <tfoot>
            <tr class="fw-bold table-secondary">
                <td>TOTALES</td>
                <td class="text-end border-start">{{ totales.maxima|number_format(0, '.', ',') }}</td>
                <td class="text-end">{{ totales.super|number_format(0, '.', ',') }}</td>
                <td class="text-end">{{ totales.diesel|number_format(0, '.', ',') }}</td>
                <td class="text-end">{{ totales.total|number_format(0, '.', ',') }}</td>
                <td class="text-end">{{ totales.venta|number_format(0, '.', ',') }}</td>
                <td class="text-end">{{ totales.venta != 0 ? (totales.total / totales.venta * 100)|number_format(2) ~ '%' : '-' }}</td>
                <td class="text-end border-start">{{ totales.sd_maxima|number_format(0, '.', ',') }}</td>
                <td class="text-end">{{ totales.sd_super|number_format(0, '.', ',') }}</td>
                <td class="text-end">{{ totales.sd_diesel|number_format(0, '.', ',') }}</td>
                <td></td>
            </tr>
        </tfoot>
    </table>
</div>

<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-body d-flex flex-wrap gap-4 align-items-center">
                <div><small>Días con datos</small><h4>{{ kpis.dias_con_datos }}</h4></div>
                <div><small>Merma promedio diaria (lts)</small><h4>{{ kpis.promedio|number_format(0, '.', ',') }}</h4></div>
                <div><small>Proyección al cierre (lts)</small><h4>{{ kpis.proyeccion|number_format(0, '.', ',') }}</h4></div>
                <div>
                    <small>Precio por litro ($)</small>
                    <input type="number" step="0.01" id="precio_litro" class="form-control form-control-sm"
                           style="width: 90px;" value="{{ precio }}">
                </div>
                <div><small>Valorización proyectada</small><h4 id="valorizacion">$ {{ kpis.valorizacion|number_format(0, '.', ',') }}</h4></div>
            </div>
        </div>
    </div>
</div>

<!-- Modal de sincronización -->
<div class="modal fade" id="syncModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Actualizar datos desde estaciones</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-2">
                    <div class="col-6">
                        <label>Desde:</label>
                        <input type="date" id="sync_from" class="form-control"
                               value="{{ 'now'|date_modify('-1 day')|date('Y-m-d') }}">
                    </div>
                    <div class="col-6">
                        <label>Hasta:</label>
                        <input type="date" id="sync_to" class="form-control" value="{{ 'now'|date('Y-m-d') }}">
                    </div>
                    <div class="col-12">
                        <label>Estación (opcional):</label>
                        <select id="sync_codgas" class="form-control">
                            <option value="0">Todas</option>
                            {% for f in filas %}
                            <option value="{{ f.codgas }}">{{ f.nombre }}</option>
                            {% endfor %}
                        </select>
                    </div>
                </div>
                <div id="sync_result" class="mt-3"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" id="sync_btn">
                    <i class="fas fa-sync"></i> Sincronizar
                </button>
            </div>
        </div>
    </div>
</div>

{% endblock %}

{% block myjs %}
<script>const MERMA_CTX = { anio: {{ anio }}, mes: {{ mes }} };</script>
<script src="{{ JS }}merma.js"></script>
{% endblock %}
```

- [ ] **Step 3: Crear `_assets/js/merma.js`**

```javascript
/**
 * Análisis de merma diaria: DataTable del resumen, captura inline
 * (merma s/d, comentarios, precio) y modal de sincronización.
 */
$(document).ready(function () {

    if ($('#merma_table').length) {
        $('#merma_table').DataTable({
            paging: false,
            ordering: false,
            info: false,
            dom: '<"top"Bf>rt',
            buttons: [{ extend: 'excel', title: 'Análisis de merma diaria', className: 'btn btn-sm btn-outline-secondary' }],
        });
    }

    // ---- Captura inline: merma s/d y comentarios --------------------------
    function guardarManual(codgas, campo, valor, $el) {
        $.post('/merma/guardar_manual', {
            codgas: codgas, anio: MERMA_CTX.anio, mes: MERMA_CTX.mes,
            campo: campo, valor: valor,
        }, function (res) {
            $el.removeClass('is-invalid is-valid').addClass(res.success ? 'is-valid' : 'is-invalid');
            setTimeout(() => $el.removeClass('is-valid'), 1500);
        }, 'json').fail(() => $el.addClass('is-invalid'));
    }

    $(document).on('change', '.merma-sd', function () {
        guardarManual($(this).data('codgas'), $(this).data('campo'), $(this).val(), $(this));
    });
    $(document).on('change', '.merma-comentario', function () {
        guardarManual($(this).data('codgas'), 'comentarios', $(this).val(), $(this));
    });

    // ---- Precio por litro -------------------------------------------------
    $(document).on('change', '#precio_litro', function () {
        const $el = $(this);
        $.post('/merma/guardar_precio', {
            anio: MERMA_CTX.anio, mes: MERMA_CTX.mes, precio: $el.val(),
        }, function (res) {
            $el.addClass(res.success ? 'is-valid' : 'is-invalid');
            if (res.success) location.reload();
        }, 'json').fail(() => $el.addClass('is-invalid'));
    });

    // ---- Modal de sincronización ------------------------------------------
    $(document).on('click', '#sync_btn', function () {
        const $btn = $(this);
        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Consultando estaciones...');
        $('#sync_result').html('');
        $.post('/merma/sync', {
            from: $('#sync_from').val(),
            to: $('#sync_to').val(),
            codgas: $('#sync_codgas').val(),
        }, function (res) {
            let html = res.success
                ? '<div class="alert alert-success mb-0">' + res.message + ' (' + res.filas + ' registros, ' + res.duracion_seg + 's)'
                : '<div class="alert alert-danger mb-0">' + res.message;
            if (res.errores && res.errores.length) {
                html += '<br><small>Sin conexión: ' + res.errores.join(', ') + '</small>';
            }
            html += '</div>';
            $('#sync_result').html(html);
            if (res.success) setTimeout(() => location.reload(), 2500);
        }, 'json').fail(function (xhr) {
            $('#sync_result').html('<div class="alert alert-danger mb-0">Error de servidor (' + xhr.status + ')</div>');
        }).always(() => $btn.prop('disabled', false).html('<i class="fas fa-sync"></i> Sincronizar'));
    });
});
```

- [ ] **Step 4: Agregar el link al sidebar**

En `views/layouts/sidebar.html`, después del `<li>` de "Mermas por estación" (`href="/supply/tgr01"`, ~línea 204-206), agregar:

```html
          <li class="sidebar-item">
            <a class="sidebar-link" href="/merma/analisis">Análisis de merma diaria</a>
          </li>
```

- [ ] **Step 5: Verificar sintaxis y render**

Run: `php -l _assets/controllers/merma.php`
Expected: `No syntax errors detected`

Run: `curl -s "http://totalgasonline.net:400/merma/analisis" -H "Cookie: <sesión válida del usuario>" | head -50` — o pedir al usuario que abra `/merma/analisis` en su navegador.
Expected: tabla con ~37 estaciones, las sincronizadas en Task 5 con números y las demás con `-`; KPIs al pie; sin errores Twig. (Si no hay cookie de sesión a la mano, este check lo hace el usuario en el navegador — checkpoint.)

- [ ] **Step 6: Commit**

```bash
git add _assets/controllers/merma.php views/merma/analisis.html _assets/js/merma.js views/layouts/sidebar.html
git commit -m "feat(merma): vista de resumen mensual /merma/analisis con KPIs y modal de sync

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 7: Endpoints de captura manual (`guardar_manual`, `guardar_precio`)

**Files:**
- Modify: `_assets/controllers/merma.php` (agregar 2 métodos al final de la clase)

**Interfaces:**
- Consumes: `save_manual()`, `save_precio()` del modelo (Task 4); los handlers JS de Task 6 ya postean a estas rutas.
- Produces: `POST /merma/guardar_manual` (codgas, anio, mes, campo, valor) y `POST /merma/guardar_precio` (anio, mes, precio) → `{"success": bool, "message"?: string}`.

- [ ] **Step 1: Agregar los métodos**

Al final de la clase `Merma` (después de `sync_diario()`):

```php
    /* ===================================================================== */
    /* Captura manual                                                        */
    /* ===================================================================== */

    /** Guarda merma s/d o comentario de una estación/mes (permiso 33). */
    public function guardar_manual(): void
    {
        if (!authorized(self::PERM_VER)) {
            json_output(['success' => false, 'message' => 'No autorizado']);
            return;
        }
        $codgas = (int)($_POST['codgas'] ?? 0);
        $anio   = (int)($_POST['anio'] ?? 0);
        $mes    = (int)($_POST['mes'] ?? 0);
        $campo  = $_POST['campo'] ?? '';
        $valor  = $_POST['valor'] ?? '';
        if (!$codgas || $anio < 2020 || $mes < 1 || $mes > 12) {
            json_output(['success' => false, 'message' => 'Parámetros inválidos']);
            return;
        }
        if ($campo !== 'comentarios' && $valor !== '' && !is_numeric($valor)) {
            json_output(['success' => false, 'message' => 'El valor debe ser numérico']);
            return;
        }
        $ok = $this->mermaModel->save_manual(
            $codgas, $anio, $mes, $campo, $valor, (int)($_SESSION['tg_user']['Id'] ?? 0)
        );
        json_output(['success' => $ok, 'message' => $ok ? 'Guardado' : 'Campo inválido']);
    }

    /** Guarda el precio por litro del mes para la valorización (permiso 33). */
    public function guardar_precio(): void
    {
        if (!authorized(self::PERM_VER)) {
            json_output(['success' => false, 'message' => 'No autorizado']);
            return;
        }
        $anio   = (int)($_POST['anio'] ?? 0);
        $mes    = (int)($_POST['mes'] ?? 0);
        $precio = $_POST['precio'] ?? '';
        if ($anio < 2020 || $mes < 1 || $mes > 12 || !is_numeric($precio) || (float)$precio <= 0) {
            json_output(['success' => false, 'message' => 'Parámetros inválidos']);
            return;
        }
        $ok = $this->mermaModel->save_precio(
            $anio, $mes, (float)$precio, (int)($_SESSION['tg_user']['Id'] ?? 0)
        );
        json_output(['success' => $ok]);
    }
```

- [ ] **Step 2: Verificar sintaxis**

Run: `php -l _assets/controllers/merma.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: CHECKPOINT USUARIO — probar captura en navegador**

Pedir al usuario: en `/merma/analisis`, capturar una merma s/d y un comentario (el input se pinta verde), recargar y confirmar que persisten; cambiar el precio y confirmar que la valorización se recalcula.

Verificación adicional por BD:
Run: `php -r "$p = new PDO('sqlsrv:Server=192.168.0.6;Database=TG;TrustServerCertificate=yes','cguser','sahei1712'); print_r($p->query('SELECT TOP 3 * FROM TG.dbo.merma_manual ORDER BY updated_at DESC')->fetchAll(PDO::FETCH_ASSOC));"`
Expected: la fila capturada con `updated_by` del usuario.

- [ ] **Step 4: Commit**

```bash
git add _assets/controllers/merma.php
git commit -m "feat(merma): captura manual de merma s/d, comentarios y precio por litro

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 8: Vista detalle `/merma/detalle/{codgas}`

**Files:**
- Modify: `_assets/controllers/merma.php` (agregar método `detalle($codgas)` junto a `analisis()`)
- Create: `views/merma/detalle.html`

**Interfaces:**
- Consumes: `get_detalle_mensual()`, `get_estaciones()`, `get_resumen_mensual()` del modelo; el modal/JS de sync de Task 6 (se reusa `merma.js`).
- Produces: `GET /merma/detalle/{codgas}?anio=&mes=` con variables Twig `estacion`, `anio`, `mes`, `filas` (fecha, turno, y por familia: vr, compras, cont, fis, dif, acum), `resumen` (totales del mes de esa estación).

- [ ] **Step 1: Agregar `detalle()` al controlador**

Después de `analisis()`:

```php
    /** Detalle día × turno de una estación (equivalente a la hoja del Excel). */
    public function detalle($codgas = 0): void
    {
        if (!authorized(self::PERM_VER)) {
            (new Errors())->get404();
            return;
        }
        $codgas = (int)$codgas;
        $anio   = (int)($_GET['anio'] ?? date('Y'));
        $mes    = (int)($_GET['mes'] ?? date('n'));

        $estacion = null;
        foreach ($this->mermaModel->get_estaciones() as $e) {
            if ((int)$e['Codigo'] === $codgas) { $estacion = $e; break; }
        }
        if (!$estacion) {
            (new Errors())->get404();
            return;
        }

        $rows = $this->mermaModel->get_detalle_mensual($codgas, $anio, $mes);

        // Acumulado de diferencia por familia (como las columnas I/P del Excel)
        $acum  = ['maxima' => 0.0, 'super' => 0.0, 'diesel' => 0.0];
        $filas = [];
        foreach ($rows as $r) {
            $fila = ['fecha' => substr($r['fecha'], 0, 10), 'turno' => (int)$r['turno']];
            foreach (array_keys(MermaDiariaModel::FAMILIAS) as $fam) {
                $dif = $r["dif_$fam"];
                if ($dif !== null) $acum[$fam] += (float)$dif;
                $fila[$fam] = [
                    'vr'      => $r["vr_$fam"],
                    'compras' => $r["compras_$fam"],
                    'cont'    => $r["cont_$fam"],
                    'fis'     => $r["fis_$fam"],
                    'dif'     => $dif,
                    'acum'    => $dif !== null ? $acum[$fam] : null,
                ];
            }
            $filas[] = $fila;
        }

        $resumenMes = $this->mermaModel->get_resumen_mensual($anio, $mes);
        $resumen    = $resumenMes[$codgas] ?? null;

        echo $this->twig->render($this->route . 'detalle.html',
            compact('estacion', 'anio', 'mes', 'filas', 'resumen'));
    }
```

- [ ] **Step 2: Crear `views/merma/detalle.html`**

```twig
{% extends "views/layouts/base.html" %}
{% block title %}Merma {{ estacion.Nombre }}{% endblock %}
{% block menutitle %}Merma diaria — {{ estacion.Nombre }}{% endblock %}
{% block content %}

{% set meses = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'] %}

<div class="card">
    <div class="card-body d-flex flex-wrap gap-4 align-items-center">
        <a href="/merma/analisis?anio={{ anio }}&mes={{ mes }}" class="btn btn-sm btn-outline-secondary">
            <i class="fas fa-arrow-left"></i> Resumen
        </a>
        <h4 class="mb-0">{{ estacion.Nombre }} — {{ meses[mes-1] }} {{ anio }}</h4>
        {% if resumen %}
        <div><small>Merma MAXIMA</small><h5>{{ resumen.merma_maxima is null ? '-' : resumen.merma_maxima|number_format(0, '.', ',') }}</h5></div>
        <div><small>Merma SUPER</small><h5>{{ resumen.merma_super is null ? '-' : resumen.merma_super|number_format(0, '.', ',') }}</h5></div>
        <div><small>Merma DIESEL</small><h5>{{ resumen.merma_diesel is null ? '-' : resumen.merma_diesel|number_format(0, '.', ',') }}</h5></div>
        <div><small>Venta total</small><h5>{{ resumen.venta_total|number_format(0, '.', ',') }}</h5></div>
        <div><small>% merma</small><h5>{{ resumen.venta_total != 0 ? (resumen.merma_total / resumen.venta_total * 100)|number_format(2) ~ '%' : '-' }}</h5></div>
        {% endif %}
        <button type="button" class="btn btn-success ms-auto" id="sync_estacion"
                data-codgas="{{ estacion.Codigo }}" data-anio="{{ anio }}" data-mes="{{ mes }}">
            <i class="fas fa-sync"></i> Re-sincronizar estación
        </button>
    </div>
</div>

<div class="card table-responsive">
    <table class="table table-sm table-bordered" style="font-size: x-small">
        <thead>
            <tr class="text-center">
                <th colspan="2"></th>
                <th colspan="6" class="table-primary">MAXIMA</th>
                <th colspan="6" class="table-success">SUPER</th>
                <th colspan="6" class="table-warning">DIESEL</th>
            </tr>
            <tr class="text-center">
                <th>FECHA</th><th>TURNO</th>
                {% for i in 1..3 %}
                <th>VR</th><th>COMPRAS</th><th>INV. CONT.</th><th>INV. FÍSICO</th><th>DIF.</th><th>ACUM.</th>
                {% endfor %}
            </tr>
        </thead>
        <tbody>
            {% for f in filas %}
            <tr>
                <td>{{ f.fecha|date('d-m-Y') }}</td>
                <td class="text-center">{{ f.turno }}</td>
                {% for fam in ['maxima', 'super', 'diesel'] %}
                {% set b = attribute(f, fam) %}
                <td class="text-end">{{ b.vr is null ? '-' : b.vr|number_format(0, '.', ',') }}</td>
                <td class="text-end">{{ b.compras is null or b.compras == 0 ? '-' : b.compras|number_format(0, '.', ',') }}</td>
                <td class="text-end">{{ b.cont is null ? '-' : b.cont|number_format(0, '.', ',') }}</td>
                <td class="text-end">{{ b.fis is null ? '-' : b.fis|number_format(0, '.', ',') }}</td>
                {% set difabs = b.dif is null ? 0 : (b.dif < 0 ? -b.dif : b.dif) %}
                <td class="text-end {{ b.dif is null ? '' : (b.dif < 0 ? 'text-danger' : 'text-success') }}">
                    {{ b.dif is null ? '-' : b.dif|number_format(0, '.', ',') }}
                    {% if difabs >= 9000 %}<span class="badge bg-danger">DIFERENCIA</span>{% endif %}
                </td>
                <td class="text-end fw-bold">{{ b.acum is null ? '-' : b.acum|number_format(0, '.', ',') }}</td>
                {% endfor %}
            </tr>
            {% else %}
            <tr><td colspan="20" class="text-center">Sin datos para este mes. Usa "Re-sincronizar estación".</td></tr>
            {% endfor %}
        </tbody>
    </table>
</div>

{% endblock %}

{% block myjs %}
<script>
$(document).ready(function () {
    $('#sync_estacion').click(function () {
        const $btn = $(this);
        const anio = $btn.data('anio'), mes = $btn.data('mes');
        const desde = anio + '-' + String(mes).padStart(2, '0') + '-01';
        const fin = new Date(anio, mes, 0);
        const hoy = new Date();
        const hasta = (fin < hoy ? fin : hoy).toISOString().slice(0, 10);
        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Consultando...');
        $.post('/merma/sync', { from: desde, to: hasta, codgas: $btn.data('codgas') }, function (res) {
            if (res.success) { location.reload(); }
            else { alert(res.message); }
        }, 'json').fail(() => alert('Error de servidor'))
          .always(() => $btn.prop('disabled', false).html('<i class="fas fa-sync"></i> Re-sincronizar estación'));
    });
});
</script>
{% endblock %}
```

- [ ] **Step 3: Verificar sintaxis y render**

Run: `php -l _assets/controllers/merma.php`
Expected: `No syntax errors detected`

CHECKPOINT USUARIO: abrir `/merma/detalle/<codgas>?anio=<año>&mes=<mes>` de una estación sincronizada. Expected: filas día×turno con los 3 bloques, diferencias coloreadas, acumulados que cierran con el total del encabezado; el botón "Re-sincronizar estación" recarga con datos frescos.

- [ ] **Step 4: Commit**

```bash
git add _assets/controllers/merma.php views/merma/detalle.html
git commit -m "feat(merma): vista detalle día×turno por estación con re-sync individual

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 9: Cron + verificación end-to-end

**Files:**
- Create: `docs/sql/merma_cron.md` (instrucciones del cron; no hay carpeta de docs de ops, se documenta junto al schema)

**Interfaces:**
- Consumes: `/merma/sync_diario` (Task 5).
- Produces: tarea programada diaria y verificación final contra el Excel.

- [ ] **Step 1: Documentar el cron**

```markdown
# Cron de merma diaria

Sincroniza D-2 y D-1 de todas las estaciones cada madrugada (5:00 am),
igual que los crons de payment.php. Programar en el servidor donde corren
los demás crons del aplicativo (Task Scheduler de Windows):

    schtasks /create /tn "TG merma sync diario" /tr "curl -s \"http://totalgasonline.net:400/merma/sync_diario?cron_token=TG_CRON_2024\"" /sc daily /st 05:00

Verificación: SELECT TOP 5 * FROM TG.dbo.merma_sync_log ORDER BY id DESC;
debe aparecer una fila origen='cron' cada día.
```

- [ ] **Step 2: CHECKPOINT USUARIO — programar la tarea**

El usuario decide en qué máquina corren los crons actuales (donde está programado el de payment 11am) y registra ahí el comando anterior. Confirmar ejecutándolo una vez a mano.

- [ ] **Step 3: Sincronizar el mes en curso completo**

Desde `/merma/analisis` → "Actualizar datos" con desde = día 1 del mes actual, hasta = hoy, todas las estaciones. (O por curl con `cron_token`.)
Expected: `success: true`, ~35+ estaciones, > 5,000 filas.

- [ ] **Step 4: Verificación end-to-end contra el Excel**

Con el usuario: comparar en `/merma/analisis` del mes en curso 3 estaciones contra la hoja MERMA MENSUAL de `FormatoJul2026.xlsm` (litros de merma por producto y venta total).
Expected: números iguales o con diferencia explicable (el Excel encadena el inventario contable del turno anterior; el sistema usa la fórmula de tgr01 por turno — sobre el mes ambas convergen; las diferencias grandes indican bug).

- [ ] **Step 5: Commit final**

```bash
git add docs/sql/merma_cron.md
git commit -m "docs(merma): instrucciones del cron de sincronización diaria

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

## Self-Review

- **Spec coverage:** 4 tablas (Task 1) ✓; endpoint ApiER paralelo con fechas serial y lista de errores (Tasks 2–3) ✓; sync manual + cron D-1/D-2 con log (Task 5, 9) ✓; resumen mensual con familias, % con colores, faltantes, KPIs, precio editable, export Excel (Task 6) ✓; captura manual con usuario (Task 7) ✓; detalle día×turno con acumulados, colores y umbral 9000 (Task 8) ✓; sidebar permiso 33 (Task 6) ✓; verificación contra tgr01 y Excel (Tasks 3, 9) ✓. Fuera de alcance respetado (sin históricos, sin tocar tgr01).
- **Placeholders:** ninguno — todo el código está completo en los steps.
- **Type consistency:** claves del API (`Codigo`, `Nombre`, `filas`, claves de fila `Fecha/Producto/CodProducto/Turno/VentasReales/Inventario/CantidadCompra/InventarioInicial/InventarioContable/Diferencia`) coinciden entre Task 2 (produce), Task 3 (envuelve) y Tasks 4–5 (consumen). Métodos del modelo usados por el controlador coinciden con las firmas de Task 4. Handlers JS de Task 6 postean a las rutas creadas en Tasks 5 y 7.
