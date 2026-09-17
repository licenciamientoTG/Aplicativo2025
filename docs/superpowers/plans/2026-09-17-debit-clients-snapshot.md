# Estado de Cuenta Foto (snapshot diario clientes débito) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Agregar un tab nuevo "Estado de Cuenta Foto" a `/income/clients` que muestre instantáneamente una foto diaria (guardada en `TG.dbo.debit_clients_snapshot`) del Saldo Inicial / Anticipos del día / Consumos del día / Saldo Final / Saldo Sistema / Saldo Vehículos de los ~4,011 clientes débito activos, sin recalcular al vuelo (la query agregada actual tarda 30-48s).

**Architecture:** Una tabla con esquema de vigencia (SCD2: `fecha_desde`/`fecha_hasta`, NULL = vigente) guarda una fila por cliente que solo se reabre cuando cambia alguno de los 6 valores numéricos. Un método de modelo (`ClientesModel::refresh_debit_snapshot`) calcula los valores del día vía una query agregada de una sola pasada y hace el diffing INSERT/UPDATE contra las filas vigentes, dentro de una transacción. Un endpoint HTTP protegido por `cron_token` (mismo patrón que `Merma::isCron()`) o sesión dispara ese refresh; un script CLI en `cron/` lo invoca para la Tarea Programada de Windows diaria. Un segundo endpoint de solo lectura sirve la tabla ya calculada a un DataTable nuevo en un tab aislado — el tab "Edo. Cuenta Débito" existente no se toca.

**Tech Stack:** PHP 8 (sin framework de tests — este proyecto no tiene test runner, ver CLAUDE.md), PDO con driver `sqlsrv` contra SQL Server (TG y SG12 en 192.168.0.6), Twig 3, jQuery + DataTables (Bootstrap Material Design), Programador de Tareas de Windows.

**Spec:** `docs/superpowers/specs/2026-09-17-debit-clients-snapshot-design.md`

## Global Constraints

- No hay test runner en este proyecto: cada tarea se verifica ejecutando el código real (scripts PHP puntuales o llamadas HTTP), nunca con `pytest`/`phpunit`.
- Los queries SQL Server usan `WITH (NOLOCK)` en los JOINs de lectura de SG12, igual que el resto de `ClientesModel.php`.
- `dateToInt($date)` (en `_assets/classes/php_functions.php:213`) es la única función válida para convertir fechas a enteros seriales — nunca reimplementarla.
- El tab `#edo_debit` y sus tablas/IDs/JS existentes (`edo_debit_table`, `edo_debit_summary_table`, `account_statement_table`) **no se modifican** en ninguna tarea de este plan.
- Todo el trabajo pesado (30-48s) ocurre en el proceso batch (refresh), nunca en el request que sirve la tabla al navegador (que es un `SELECT` simple e indexado).
- Nombres exactos ya fijados por el spec: tabla `TG.dbo.debit_clients_snapshot`, método `ClientesModel::refresh_debit_snapshot(string $fecha)`, endpoints `POST /income/debit_snapshot_refresh` y `POST /income/debit_snapshot_table`, script `cron/debit_snapshot_diario.php`.

---

## Task 1: Tabla `debit_clients_snapshot` en TG

**Files:**
- Create: `docs/sql/debit_clients_snapshot_schema.sql`

**Interfaces:**
- Produces: tabla `TG.dbo.debit_clients_snapshot` con columnas `id, codcli, cliente, fecha_desde, fecha_hasta, saldo_inicial, anticipos_dia, consumos_dia, saldo_final, saldo_sistema, saldo_vehiculos, updated_at`, constraint única `(codcli, fecha_desde)`, índice `(codcli, fecha_hasta)`.

- [ ] **Step 1: Escribir el script de creación de tabla**

Seguir el patrón de `docs/sql/merma_schema.sql` (`IF OBJECT_ID(...) IS NULL BEGIN ... END`, `GO`).

```sql
-- docs/sql/debit_clients_snapshot_schema.sql
-- Schema del snapshot diario de clientes débito (/income/clients, tab
-- "Estado de Cuenta Foto"). Esquema de vigencia (SCD2): una fila por
-- cliente solo se reabre cuando cambia alguno de los 6 valores numéricos.
-- Spec: docs/superpowers/specs/2026-09-17-debit-clients-snapshot-design.md
USE TG;
GO

IF OBJECT_ID('dbo.debit_clients_snapshot') IS NULL
BEGIN
CREATE TABLE dbo.debit_clients_snapshot (
    id              INT IDENTITY(1,1) PRIMARY KEY,
    codcli          INT            NOT NULL,
    cliente         NVARCHAR(255)  NOT NULL,
    fecha_desde     DATE           NOT NULL,
    fecha_hasta     DATE           NULL,
    saldo_inicial   DECIMAL(18,2)  NOT NULL,
    anticipos_dia   DECIMAL(18,2)  NOT NULL,
    consumos_dia    DECIMAL(18,2)  NOT NULL,
    saldo_final     DECIMAL(18,2)  NOT NULL,
    saldo_sistema   DECIMAL(18,2)  NOT NULL,
    saldo_vehiculos DECIMAL(18,2)  NOT NULL,
    updated_at      DATETIME       NOT NULL DEFAULT GETDATE(),
    CONSTRAINT UQ_debit_snapshot_cliente_desde UNIQUE (codcli, fecha_desde)
);
CREATE INDEX IX_debit_snapshot_vigente ON dbo.debit_clients_snapshot (codcli, fecha_hasta);
END
GO
```

- [ ] **Step 2: Ejecutar el script contra TG y verificar que la tabla existe**

`MySqlPdoHandler::select()` exige que la query contenga la palabra "select"
(`stristr($query,"select")`, ver `_assets/classes/common/MySqlPdoHandler.class.php:119`)
y hace `die()` si no — no sirve para DDL. `update()`/`insert()` exigen sus
propias palabras clave por el mismo motivo. El único método genérico que
acepta cualquier sentencia es `query($query, $params=NULL)`
(`MySqlPdoHandler.class.php:355`), que no valida palabra clave. Usarlo para
correr el DDL:

```bash
php -r '
require_once "_assets/classes/common/MySqlPdoHandler.class.php";
$db = MySqlPdoHandler::getInstance();
$db->connect("TG");
$sql = file_get_contents("docs/sql/debit_clients_snapshot_schema.sql");
foreach (array_filter(array_map("trim", preg_split("/^GO$/mi", $sql))) as $batch) {
    $db->query($batch);
}
$rows = $db->select("SELECT COUNT(*) AS n FROM sys.tables WHERE name = '"'"'debit_clients_snapshot'"'"'");
print_r($rows);
'
```

Expected: imprime `[0] => Array([n] => 1)` confirmando que la tabla existe.

- [ ] **Step 3: Commit**

```bash
git add docs/sql/debit_clients_snapshot_schema.sql
git commit -m "Agrega schema de TG.dbo.debit_clients_snapshot (Estado de Cuenta Foto)"
```

---

## Task 2: `ClientesModel::refresh_debit_snapshot()` — cálculo y diffing

**Files:**
- Modify: `_assets/models/ClientesModel.php` (agregar método nuevo cerca de `get_account_summary_debit`, línea ~931)

**Interfaces:**
- Consumes: `$this->sql` (PDO wrapper, ya disponible en toda instancia de `ClientesModel` vía `Model::__construct`, conectado a SG12 por defecto).
- Produces: `public function refresh_debit_snapshot(string $fecha): array` — retorna `['nuevos' => int, 'actualizados' => int, 'sin_cambio' => int, 'duracion_seg' => float]`. `$fecha` en formato `Y-m-d`.

Nota de conexión: `ClientesModel` hereda de `Model`, cuyo constructor llama `$this->sql->connect('SG12')`. Como `MySqlPdoHandler` es un singleton y ambas BDs viven en el mismo host, y ya se verificó que una conexión a `TG` puede leer `[SG12].dbo....` vía nombre de servidor completo, este método debe **reconectar explícitamente a TG** al inicio (`$this->sql->connect('TG')`) porque escribe en `TG.dbo.debit_clients_snapshot`, y usar el prefijo `[SG12].dbo.` en todas las lecturas de Clientes/Documentos/Despachos/ClientesVehiculos — exactamente como se verificó en el spike (`SELECT cod, den FROM [SG12].dbo.Clientes ...` funcionó correctamente conectado a TG). Al terminar el método, no hace falta reconectar a SG12: cada modelo reconecta a lo que necesita al invocarse (ver `RenegociacionModel` como precedente de un modelo que vive permanentemente en TG).

Nota sobre el wrapper PDO (`_assets/classes/common/MySqlPdoHandler.class.php`):
`update($query, $params)` hace `die()` con mensaje genérico si la query
falla (`failGeneric()`, línea 163-180) — **no lanza excepción**, así que un
`try/catch` alrededor no lo detecta y el proceso terminaría a media
transacción sin `rollBack()`. Usar en su lugar `updateSafe($query, $params)`
(línea 192-206), que devuelve `int` (filas afectadas) o `false` en error sin
matar el proceso, y verificar el resultado con un `if` explícito para forzar
el throw manual que sí dispara el `catch`. `insert()` en cambio sí relanza
excepción en error (línea 225-230), así que puede usarse tal cual dentro del
`try`.

- [ ] **Step 1: Escribir el método `refresh_debit_snapshot`**

```php
/**
 * Calcula la foto del día para todos los clientes débito activos y la
 * compara contra las filas vigentes de TG.dbo.debit_clients_snapshot:
 * abre una fila nueva solo si cambió saldo_inicial, anticipos_dia,
 * consumos_dia, saldo_final, saldo_sistema o saldo_vehiculos.
 * Ver docs/superpowers/specs/2026-09-17-debit-clients-snapshot-design.md
 */
public function refresh_debit_snapshot(string $fecha) : array {
    $t0 = microtime(true);
    $this->sql->connect('TG');

    $hoy = dateToInt($fecha);

    $query = "
        DECLARE @Hoy INT = ?;

        ;WITH Ini AS (
            SELECT t2.codopr, CAST(SUM(t2.mtoori + t2.mtoiva)/100.0 AS decimal(18,2)) AS AnticiposIni
            FROM [SG12].dbo.DocumentosC t1 WITH (NOLOCK)
            JOIN [SG12].dbo.Documentos t2 WITH (NOLOCK)
              ON t1.nro = t2.nro AND t1.codgas = t2.codgas AND t1.tip = t2.tip
            WHERE t1.fch < @Hoy
              AND t2.mtoiva > 0
              AND t2.codprd NOT IN (1,2,3,-64,179,180,181,192,193)
              AND t2.mto > 100
              AND t2.codopr <> 0
              AND ISNULL(t1.flgcon, 0) <> 141
            GROUP BY t2.codopr
        ),
        ConIni AS (
            SELECT d.codcli AS codopr, CAST(SUM(d.mto) AS decimal(18,2)) AS ConsumosIni
            FROM [SG12].dbo.Despachos d WITH (NOLOCK)
            WHERE d.fchtrn < @Hoy AND d.codcli > 0
            GROUP BY d.codcli
        ),
        Dia AS (
            SELECT t2.codopr, CAST(SUM(t2.mtoori + t2.mtoiva)/100.0 AS decimal(18,2)) AS AnticiposDia
            FROM [SG12].dbo.DocumentosC t1 WITH (NOLOCK)
            JOIN [SG12].dbo.Documentos t2 WITH (NOLOCK)
              ON t1.nro = t2.nro AND t1.codgas = t2.codgas AND t1.tip = t2.tip
            WHERE t1.fch = @Hoy
              AND t2.mtoiva > 0
              AND t2.codprd NOT IN (1,2,3,-64,179,180,181,192,193)
              AND t2.mto > 100
              AND t2.codopr <> 0
              AND ISNULL(t1.flgcon, 0) <> 141
            GROUP BY t2.codopr
        ),
        ConDia AS (
            SELECT d.codcli AS codopr, CAST(SUM(d.mto) AS decimal(18,2)) AS ConsumosDia
            FROM [SG12].dbo.Despachos d WITH (NOLOCK)
            WHERE d.fchtrn = @Hoy AND d.codcli > 0
            GROUP BY d.codcli
        ),
        Veh AS (
            SELECT codcli, CAST(SUM(debsdo) AS decimal(18,2)) AS SaldoVehiculos
            FROM [SG12].dbo.ClientesVehiculos WITH (NOLOCK)
            GROUP BY codcli
        )
        SELECT
            C.cod AS codcli,
            C.den AS cliente,
            CAST(ISNULL(I.AnticiposIni,0) - ISNULL(CI.ConsumosIni,0) AS decimal(18,2)) AS saldo_inicial,
            ISNULL(D.AnticiposDia,0) AS anticipos_dia,
            ISNULL(CD.ConsumosDia,0) AS consumos_dia,
            CAST(ISNULL(I.AnticiposIni,0) - ISNULL(CI.ConsumosIni,0) + ISNULL(D.AnticiposDia,0) - ISNULL(CD.ConsumosDia,0) AS decimal(18,2)) AS saldo_final,
            C.debsdo AS saldo_sistema,
            ISNULL(V.SaldoVehiculos,0) AS saldo_vehiculos
        FROM [SG12].dbo.Clientes C
        LEFT JOIN Ini I     ON I.codopr  = C.cod
        LEFT JOIN ConIni CI ON CI.codopr = C.cod
        LEFT JOIN Dia D     ON D.codopr  = C.cod
        LEFT JOIN ConDia CD ON CD.codopr = C.cod
        LEFT JOIN Veh V     ON V.codcli  = C.cod
        WHERE C.tipval = 4 AND C.codest <> -1
        ORDER BY C.den";

    $calculado = $this->sql->select($query, [$hoy]) ?: [];

    $vigentes = $this->sql->select(
        "SELECT id, codcli, saldo_inicial, anticipos_dia, consumos_dia, saldo_final, saldo_sistema, saldo_vehiculos
         FROM [TG].dbo.debit_clients_snapshot WHERE fecha_hasta IS NULL"
    ) ?: [];
    $vigentesPorCliente = [];
    foreach ($vigentes as $v) {
        $vigentesPorCliente[(int)$v['codcli']] = $v;
    }

    $nuevos = $actualizados = $sinCambio = 0;
    $ayer = date('Y-m-d', strtotime($fecha . ' -1 day'));

    $this->sql->beginTransaction();
    try {
        foreach ($calculado as $row) {
            $codcli = (int)$row['codcli'];
            $actual = $vigentesPorCliente[$codcli] ?? null;

            if ($actual === null) {
                $this->sql->insert(
                    "INSERT INTO [TG].dbo.debit_clients_snapshot
                        (codcli, cliente, fecha_desde, fecha_hasta, saldo_inicial, anticipos_dia, consumos_dia, saldo_final, saldo_sistema, saldo_vehiculos)
                     VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, ?)",
                    [$codcli, $row['cliente'], $fecha,
                     $row['saldo_inicial'], $row['anticipos_dia'], $row['consumos_dia'],
                     $row['saldo_final'], $row['saldo_sistema'], $row['saldo_vehiculos']]
                );
                $nuevos++;
                continue;
            }

            $sinCambios = abs((float)$actual['saldo_inicial']   - (float)$row['saldo_inicial'])   < 0.005
                       && abs((float)$actual['anticipos_dia']   - (float)$row['anticipos_dia'])   < 0.005
                       && abs((float)$actual['consumos_dia']    - (float)$row['consumos_dia'])    < 0.005
                       && abs((float)$actual['saldo_final']     - (float)$row['saldo_final'])     < 0.005
                       && abs((float)$actual['saldo_sistema']   - (float)$row['saldo_sistema'])   < 0.005
                       && abs((float)$actual['saldo_vehiculos'] - (float)$row['saldo_vehiculos']) < 0.005;

            if ($sinCambios) {
                $sinCambio++;
                continue;
            }

            $affected = $this->sql->updateSafe(
                "UPDATE [TG].dbo.debit_clients_snapshot SET fecha_hasta = ? WHERE id = ?",
                [$ayer, $actual['id']]
            );
            if ($affected === false) {
                throw new Exception("No se pudo cerrar la vigencia del cliente $codcli");
            }
            $this->sql->insert(
                "INSERT INTO [TG].dbo.debit_clients_snapshot
                    (codcli, cliente, fecha_desde, fecha_hasta, saldo_inicial, anticipos_dia, consumos_dia, saldo_final, saldo_sistema, saldo_vehiculos)
                 VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, ?)",
                [$codcli, $row['cliente'], $fecha,
                 $row['saldo_inicial'], $row['anticipos_dia'], $row['consumos_dia'],
                 $row['saldo_final'], $row['saldo_sistema'], $row['saldo_vehiculos']]
            );
            $actualizados++;
        }
        $this->sql->commit();
    } catch (Throwable $e) {
        $this->sql->rollBack();
        throw $e;
    }

    return [
        'nuevos'       => $nuevos,
        'actualizados' => $actualizados,
        'sin_cambio'   => $sinCambio,
        'duracion_seg' => round(microtime(true) - $t0, 1),
    ];
}
```

Antes de dar este paso por terminado, leer las firmas reales de `insert()`, `update()`, `beginTransaction()`, `commit()`, `rollBack()` en `_assets/classes/common/MySqlPdoHandler.class.php` (no asumir — confirmar orden de parámetros y tipo de retorno) y ajustar las llamadas si difieren de lo escrito arriba.

- [ ] **Step 2: Verificar backfill contra la BD real (primera corrida, todo INSERT)**

```bash
php -r '
require_once "_assets/classes/common/MySqlPdoHandler.class.php";
require_once "_assets/models/Model.php";
require_once "_assets/models/ClientesModel.php";
$m = new ClientesModel();
$r = $m->refresh_debit_snapshot(date("Y-m-d"));
print_r($r);
'
```

Expected: `nuevos` cercano a 4,011 (todos los clientes débito activos), `actualizados = 0`, `sin_cambio = 0` (no hay filas previas). `duracion_seg` esperado en el rango 25-50s (igual orden de magnitud que el benchmark de la query extendida).

- [ ] **Step 3: Verificar idempotencia (segunda corrida el mismo día → todo sin cambio)**

```bash
php -r '
require_once "_assets/classes/common/MySqlPdoHandler.class.php";
require_once "_assets/models/Model.php";
require_once "_assets/models/ClientesModel.php";
$m = new ClientesModel();
$r = $m->refresh_debit_snapshot(date("Y-m-d"));
print_r($r);
'
```

Expected: `nuevos = 0`, `actualizados = 0`, `sin_cambio` cercano a 4,011. Si `actualizados` sale distinto de 0 aquí, hay un bug de redondeo o de tipos en la comparación — revisar antes de continuar (los decimales de SQL Server vueltos string por PDO pueden traer más precisión de la esperada; el `abs(...) < 0.005` ya cubre eso, pero confirmar con un `var_dump` puntual si falla).

- [ ] **Step 4: Verificar una fila real en TG**

```bash
php -r '
require_once "_assets/classes/common/MySqlPdoHandler.class.php";
$db = MySqlPdoHandler::getInstance();
$db->connect("TG");
$rows = $db->select("SELECT TOP 3 * FROM [TG].dbo.debit_clients_snapshot WHERE fecha_hasta IS NULL ORDER BY cliente");
print_r($rows);
$count = $db->select("SELECT COUNT(*) AS n FROM [TG].dbo.debit_clients_snapshot WHERE fecha_hasta IS NULL");
print_r($count);
'
```

Expected: filas con `saldo_vehiculos` poblado (no NULL), `fecha_hasta` NULL, y el conteo total coincide con el número de clientes débito activos.

- [ ] **Step 5: Commit**

```bash
git add _assets/models/ClientesModel.php
git commit -m "Agrega ClientesModel::refresh_debit_snapshot (cálculo diario + diffing de vigencia)"
```

---

## Task 3: Endpoint de refresh + script cron

**Files:**
- Modify: `_assets/controllers/income.php` (agregar método `debit_snapshot_refresh()` cerca de `account_statement_table`, línea ~587)
- Create: `cron/debit_snapshot_diario.php`

**Interfaces:**
- Consumes: `ClientesModel::refresh_debit_snapshot(string $fecha): array` (Task 2).
- Produces: `POST /income/debit_snapshot_refresh` — JSON `{success: bool, ...resumen}`. Script CLI `cron/debit_snapshot_diario.php` invocable por `php cron/debit_snapshot_diario.php`.

- [ ] **Step 1: Agregar el método al controlador `Income`**

Insertar después de `account_statement_table()` (después de la línea 587 de `_assets/controllers/income.php`, justo antes del comentario `/** * @return void ...`):

```php
/**
 * ¿La petición viene del cron con token válido? Mismo patrón que
 * Merma::isCron() en _assets/controllers/merma.php.
 */
private function isCronDebitSnapshot(): bool
{
    $token = $_POST['cron_token'] ?? $_GET['cron_token'] ?? null;
    return defined('CRON_SECRET') && $token === CRON_SECRET;
}

/**
 * Genera/actualiza la foto del día en TG.dbo.debit_clients_snapshot para
 * todos los clientes débito activos. Autoriza por cron_token (tarea
 * programada diaria) o por sesión iniciada (corrida manual desde
 * navegador/CLI autenticado). POST /income/debit_snapshot_refresh
 */
public function debit_snapshot_refresh(): void
{
    set_time_limit(0);
    if (!$this->isCronDebitSnapshot() && !isset($_SESSION['tg_user'])) {
        json_output(['success' => false, 'message' => 'No autorizado']);
        return;
    }
    $fecha = $_POST['fecha'] ?? $_GET['fecha'] ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
        json_output(['success' => false, 'message' => 'Fecha inválida']);
        return;
    }
    $resumen = $this->clientesModel->refresh_debit_snapshot($fecha);
    json_output(array_merge(['success' => true], $resumen));
}
```

Nota: como `index.php` exige sesión para *cualquier* ruta no listada en `$publicRoutes`, esta ruta HTTP tampoco es alcanzable sin sesión — igual que documenta el comentario de `cron/merma_sync_diario.php`. El chequeo `isCronDebitSnapshot()` aquí solo importa para cuando el script CLI la invoca sin pasar por `index.php` (ver Step 2).

- [ ] **Step 2: Crear el script cron**

```php
<?php
/**
 * Tarea programada: genera la foto diaria de TG.dbo.debit_clients_snapshot
 * para todos los clientes débito activos. Equivalente al backfill manual
 * corrido una vez el 2026-09-17, pero ejecutado cada día.
 *
 * Configurar en Programador de Tareas de Windows a las 06:00 AM:
 *   Programa:   php
 *   Argumentos: C:\ruta\AplicativoPhp\cron\debit_snapshot_diario.php
 *
 * Nota: la ruta HTTP /income/debit_snapshot_refresh NO sirve para el cron
 * porque index.php exige sesión antes de despachar al controlador.
 */

$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
$_SERVER['REQUEST_URI']   = '/cron/debit_snapshot_diario';
chdir($_SERVER['DOCUMENT_ROOT']);

require '_assets/classes/header.class.php';
require '_assets/classes/php_functions.php';

spl_autoload_register(function ($class) {
    if (file_exists(CLASSES . $class . '.class.php')) {
        require CLASSES . $class . '.class.php';
    }
    if (file_exists(CONTROLLERS . strtolower($class) . '.php')) {
        require CONTROLLERS . strtolower($class) . '.php';
    }
    if (file_exists(MODELS . $class . '.php')) {
        require MODELS . $class . '.php';
    }
});

echo "[" . date('Y-m-d H:i:s') . "] Iniciando snapshot diario de clientes débito\n";

// El controlador autoriza el cron por token; en CLI lo pasamos por $_GET.
$_GET['cron_token'] = CRON_SECRET;

$income = new Income($twig);
$income->debit_snapshot_refresh(); // imprime el JSON del resultado y termina el proceso
```

- [ ] **Step 3: Verificar el script cron corre y produce el mismo resultado idempotente**

```bash
php cron/debit_snapshot_diario.php
```

Expected: imprime la línea de log y luego el JSON `{"success":true,"nuevos":0,"actualizados":0,"sin_cambio":N,"duracion_seg":X}` (ya se corrió el backfill en Task 2, así que hoy debe salir sin cambios).

- [ ] **Step 4: Commit**

```bash
git add _assets/controllers/income.php cron/debit_snapshot_diario.php
git commit -m "Agrega endpoint y cron diario para refrescar el snapshot de clientes débito"
```

---

## Task 4: Endpoint de lectura del snapshot

**Files:**
- Modify: `_assets/controllers/income.php` (agregar método `debit_snapshot_table()` justo después de `debit_snapshot_refresh()`)

**Interfaces:**
- Consumes: tabla `TG.dbo.debit_clients_snapshot` (Task 1/2).
- Produces: `POST /income/debit_snapshot_table` — JSON `{data: [...], fecha_foto: "YYYY-MM-DD"|null}`.

- [ ] **Step 1: Agregar método de lectura al modelo**

En `_assets/models/ClientesModel.php`, cerca de `refresh_debit_snapshot`:

```php
/** Foto vigente (más reciente) de todos los clientes débito, para el tab "Estado de Cuenta Foto". */
public function get_debit_snapshot() : array|false {
    $this->sql->connect('TG');
    $query = "
        SELECT codcli, cliente, fecha_desde, saldo_inicial, anticipos_dia,
               consumos_dia, saldo_final, saldo_sistema, saldo_vehiculos
        FROM [TG].dbo.debit_clients_snapshot
        WHERE fecha_hasta IS NULL
        ORDER BY cliente";
    return $this->sql->select($query) ?: false;
}
```

- [ ] **Step 2: Agregar el método al controlador**

En `_assets/controllers/income.php`, después de `debit_snapshot_refresh()`:

```php
/**
 * Lee la foto vigente de clientes débito (sin recalcular). Tab "Estado
 * de Cuenta Foto" de /income/clients. POST /income/debit_snapshot_table
 */
public function debit_snapshot_table(): void
{
    $rows = $this->clientesModel->get_debit_snapshot() ?: [];
    $fechaFoto = null;
    foreach ($rows as $r) {
        $f = $r['fecha_desde'];
        if ($fechaFoto === null || $f > $fechaFoto) {
            $fechaFoto = $f;
        }
    }
    json_output(['data' => $rows, 'fecha_foto' => $fechaFoto]);
}
```

- [ ] **Step 3: Verificar el endpoint con una petición HTTP real**

Con el servidor de desarrollo ya corriendo (el usuario lo gestiona — no levantarlo), hacer una petición autenticada de prueba no es viable sin sesión de navegador; en su lugar, verificar el modelo directamente:

```bash
php -r '
require_once "_assets/classes/common/MySqlPdoHandler.class.php";
require_once "_assets/models/Model.php";
require_once "_assets/models/ClientesModel.php";
$m = new ClientesModel();
$rows = $m->get_debit_snapshot();
echo "filas=" . count($rows) . "\n";
print_r(array_slice($rows, 0, 2));
'
```

Expected: `filas` cercano a 4,011, cada fila con las 9 columnas seleccionadas y `saldo_vehiculos` no NULL.

- [ ] **Step 4: Commit**

```bash
git add _assets/models/ClientesModel.php _assets/controllers/income.php
git commit -m "Agrega endpoint de lectura del snapshot de clientes débito"
```

---

## Task 5: Tab "Estado de Cuenta Foto" en la vista

**Files:**
- Modify: `views/income/clients.html` (agregar `<li>` de nav-tab después de línea 41, y `<div class="tab-pane">` nuevo después del cierre de `#edo_debit` en línea 644)

**Interfaces:**
- Consumes: `POST /income/debit_snapshot_table` (Task 4).
- Produces: elemento DOM `#edo_debit_foto` con botón `#btn_debit_snapshot`, tabla `#debit_snapshot_table`, y span `#debit_snapshot_fecha` para la fecha de la foto — nombres que usará el JS de Task 6.

- [ ] **Step 1: Agregar el tab a la barra de navegación**

En `views/income/clients.html`, después del `<li>` de "Edo. Cuenta Débito" (línea 39-41):

```html
    <li class="nav-item" role="presentation">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#edo_debit_foto" type="button">Estado de Cuenta Foto</button>
    </li>
```

Insertar exactamente antes de `</ul>` (línea 42), sin modificar ningún `<li>` existente.

- [ ] **Step 2: Agregar el `tab-pane` nuevo**

Insertar después del cierre `</div>` del tab `#edo_debit` (línea 644, justo antes de `</div>` que cierra `.tab-content` en la línea 646):

```html
    {# ── TAB 7: ESTADO DE CUENTA FOTO ── #}
    <div class="tab-pane" id="edo_debit_foto" role="tabpanel">
        <div class="card">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-auto">
                        <button id="btn_debit_snapshot" class="btn btn-sm btn-primary">
                            <i class="fas fa-search"></i> Consultar Foto
                        </button>
                    </div>
                    <div class="col-auto">
                        <span class="text-muted">
                            Fecha de la foto: <strong id="debit_snapshot_fecha">—</strong>
                        </span>
                    </div>
                </div>
            </div>
        </div>
        <div class="card">
            <div class="card-header">
                <h5 class="card-title">
                    Estado de Cuenta Foto — Todos los Clientes Débito
                    <a href="#" class="ms-1" data-bs-toggle="modal" data-bs-target="#modal_info_debit_snapshot" title="¿De dónde viene esta información?">
                        <i class="fas fa-question-circle"></i>
                    </a>
                </h5>
            </div>
            <div class="card-body table-responsive">
                <table id="debit_snapshot_table" class="table table-hover table-sm w-100">
                    <thead>
                        <tr>
                            <th>Código</th><th>Cliente</th><th>Saldo Inicial</th>
                            <th>Anticipos (día)</th><th>Consumos (día)</th>
                            <th>Saldo Final</th><th>Saldo Sistema</th><th>Saldo Vehículos</th>
                            <th>Fecha Foto</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                    <tfoot>
                        <tr>
                            <th></th><th>Total</th><th></th><th></th><th></th><th></th><th></th><th></th><th></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
```

- [ ] **Step 3: Agregar el modal informativo**

Insertar antes del cierre `{% endblock %}` (línea 826, después del modal `modal_info_edo_debit`):

```html
{# ── Modal: origen de la información de la foto ── #}
<div class="modal fade" id="modal_info_debit_snapshot" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-question-circle"></i> ¿De dónde viene esta información?</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <p class="mb-3">
                    Esta tabla es una <strong>foto guardada</strong>, no un cálculo en vivo: se genera una vez
                    al día por una tarea programada (madrugada) y aquí solo se lee lo ya guardado en
                    <code>TG.dbo.debit_clients_snapshot</code>. Se creó porque calcular esto en vivo para los
                    ~4,000 clientes débito activos tarda 30-48 segundos.
                </p>
                <table class="table table-sm table-bordered">
                    <thead>
                        <tr><th style="width:22%">Columna</th><th>Origen</th></tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>Saldo Inicial</strong></td>
                            <td>Anticipos menos consumos de <strong>todo el histórico</strong> del cliente antes
                                del día de la foto.</td>
                        </tr>
                        <tr>
                            <td><strong>Anticipos (día) / Consumos (día)</strong></td>
                            <td>Solo del día en que se generó la foto (no acumulado del mes o año), para que la
                                foto no cambie en días sin movimiento.</td>
                        </tr>
                        <tr>
                            <td><strong>Saldo Final</strong></td>
                            <td>Saldo Inicial + Anticipos (día) − Consumos (día).</td>
                        </tr>
                        <tr>
                            <td><strong>Saldo Sistema</strong></td>
                            <td>Bolsa actual del cliente (<code>debsdo</code>) al momento de generar la foto.</td>
                        </tr>
                        <tr>
                            <td><strong>Saldo Vehículos</strong></td>
                            <td>Suma del saldo individual de sus vehículos/tarjetas (<code>ClientesVehiculos.debsdo</code>)
                                al momento de generar la foto.</td>
                        </tr>
                        <tr>
                            <td><strong>Fecha Foto</strong></td>
                            <td>Día en que se calculó esta fila. Si un cliente no tuvo ningún cambio desde
                                entonces, la fecha se mantiene aunque hayan pasado varios días.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>
```

- [ ] **Step 4: Verificar que el HTML es válido y no rompe el resto de la página**

No hay test runner; verificar manualmente:

```bash
php -l views/income/clients.html
```

Esto fallará porque no es PHP puro (es Twig) — en su lugar, verificar que las etiquetas abren/cierran igual contando ocurrencias:

```bash
grep -o '<div class="tab-pane"' views/income/clients.html | wc -l
grep -o '</div>' views/income/clients.html | wc -l
```

Expected: el conteo de `tab-pane` debe ser 7 (los 6 existentes + el nuevo). El chequeo real de balanceo de `</div>` se hace visualmente en el navegador en el Step 5 de Task 6 (cuando ya hay JS para probarlo end-to-end) — este paso solo confirma que no se te olvidó agregar el `<li>` o el `tab-pane`.

- [ ] **Step 5: Commit**

```bash
git add views/income/clients.html
git commit -m "Agrega tab 'Estado de Cuenta Foto' a views/income/clients.html"
```

---

## Task 6: JS del tab nuevo

**Files:**
- Modify: `_assets/js/income.js` (agregar función nueva al final del archivo, después de la sección de Estado de Cuenta actual, ej. después de línea 3040)
- Modify: `views/income/clients.html` (conectar el botón nuevo en el bloque `{% block myjs %}`, línea ~871)

**Interfaces:**
- Consumes: `POST /income/debit_snapshot_table` → `{data: [...], fecha_foto: string|null}` (Task 4).
- Produces: función `debit_snapshot_table()` sin parámetros, colgada del click de `#btn_debit_snapshot`.

- [ ] **Step 1: Agregar la función al JS**

Insertar en `_assets/js/income.js`, después del bloque de `edo_debit_drill` (después de la línea 3040, antes del comentario `// cargarGraficaDesdeController();`):

```javascript
// ── Estado de Cuenta Foto (snapshot diario, tab aislado) ────────────────────

function debit_snapshot_table() {
    if ($.fn.DataTable.isDataTable('#debit_snapshot_table')) {
        $('#debit_snapshot_table').DataTable().destroy();
        $('#debit_snapshot_table thead .filter').remove();
    }

    $('#debit_snapshot_table thead').prepend($('#debit_snapshot_table thead tr').clone().addClass('filter'));
    $('#debit_snapshot_table thead tr.filter th').each(function (index) {
        var col = $('#debit_snapshot_table thead th').length / 2;
        if (index < col) {
            var title = $(this).text();
            $(this).html('<input type="text" class="form-control form-control-sm" placeholder=" ' + title + '" />');
        }
    });
    $('#debit_snapshot_table thead tr.filter th input').on('keyup change', function () {
        $('#debit_snapshot_table').DataTable()
            .column($(this).parent().index())
            .search(this.value).draw();
    });

    var numFmt = $.fn.dataTable.render.number(',', '.', 2, '$');
    var moneyCols = [2, 3, 4, 5, 6, 7];

    $('#debit_snapshot_table').DataTable({
        ordering: true,
        colReorder: true,
        dom: '<"top"Bf>rt<"bottom"lip>',
        paging: true,
        pageLength: 100,
        buttons: [{ extend: 'excel', className: 'btn btn-success', text: ' Excel' }],
        ajax: {
            method: 'POST',
            url: '/income/debit_snapshot_table',
            timeout: 60000,
            dataSrc: function (json) {
                $('#debit_snapshot_fecha').text(json.fecha_foto || '—');
                return json.data || [];
            },
            error: function () {
                $('.table-responsive').removeClass('loading');
                alertify.myAlert('<div class="text-center text-danger"><h4>¡Error!</h4><p>No se pudo consultar la foto.</p></div>');
            },
            beforeSend: function () { $('.table-responsive').addClass('loading'); }
        },
        columns: [
            { data: 'codcli' },
            { data: 'cliente',        className: 'text-nowrap' },
            { data: 'saldo_inicial',   render: numFmt, className: 'text-nowrap text-end' },
            { data: 'anticipos_dia',   render: numFmt, className: 'text-nowrap text-end' },
            { data: 'consumos_dia',    render: numFmt, className: 'text-nowrap text-end' },
            { data: 'saldo_final',     render: numFmt, className: 'text-nowrap text-end' },
            { data: 'saldo_sistema',   render: numFmt, className: 'text-nowrap text-end' },
            { data: 'saldo_vehiculos', render: numFmt, className: 'text-nowrap text-end' },
            { data: 'fecha_desde',     className: 'text-center' },
        ],
        deferRender: true,
        initComplete: function () { $('.table-responsive').removeClass('loading'); },
        footerCallback: function () {
            var api = this.api();
            moneyCols.forEach(function (idx) {
                var total = api.column(idx, { search: 'applied' }).data()
                    .reduce(function (a, b) { return (parseFloat(a) || 0) + (parseFloat(b) || 0); }, 0);
                $(api.column(idx).footer()).html('$' + total.toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
            });
        }
    });
}
```

- [ ] **Step 2: Conectar el botón**

En `views/income/clients.html`, dentro de `{% block myjs %}` (después de la línea 869, junto a los otros `.on('click', ...)`):

```javascript
// ── Estado de Cuenta Foto ──
$('#btn_debit_snapshot').on('click', function () {
    debit_snapshot_table();
});
```

- [ ] **Step 3: Verificar en el navegador**

El usuario gestiona el servidor de desarrollo — pedirle que confirme (no levantar el servidor). Pasos a validar manualmente en `http://localhost:8001/income/clients`:
1. El nuevo tab "Estado de Cuenta Foto" aparece en la barra de navegación, después de "Edo. Cuenta Débito".
2. Al hacer click en el tab, se ve el botón "Consultar Foto" y el texto "Fecha de la foto: —".
3. Al hacer click en "Consultar Foto", la tabla se llena (~4,011 filas), la fecha de la foto se actualiza al día del backfill, el pie totaliza las columnas de dinero, y el botón de exportar a Excel funciona.
4. El tab "Edo. Cuenta Débito" original sigue funcionando exactamente igual que antes (sin cambios).

- [ ] **Step 4: Commit**

```bash
git add _assets/js/income.js views/income/clients.html
git commit -m "Agrega JS del tab 'Estado de Cuenta Foto' (DataTable + botón)"
```

---

## Task 7: Backfill inicial en producción + documentar la Tarea Programada

**Files:**
- Modify: `docs/superpowers/specs/2026-09-17-debit-clients-snapshot-design.md` (agregar sección "Despliegue" con los pasos ya ejecutados y los pendientes del usuario)

**Interfaces:**
- Consumes: todo lo anterior (Tasks 1-6) ya desplegado en el servidor real (no local — recordar: deploy manual, "nada es producción hasta que el usuario confirme").

- [ ] **Step 1: Confirmar con el usuario que el código ya está desplegado en el servidor real**

Este paso es una pausa de coordinación, no código: preguntar al usuario si ya subió los archivos tocados (`_assets/models/ClientesModel.php`, `_assets/controllers/income.php`, `views/income/clients.html`, `_assets/js/income.js`, `cron/debit_snapshot_diario.php`, `docs/sql/debit_clients_snapshot_schema.sql`) al servidor de producción, siguiendo su flujo manual de deploy habitual.

- [ ] **Step 2: Correr el backfill en el servidor de producción (una sola vez)**

Indicar al usuario el comando exacto a correr en el servidor (no ejecutarlo remotamente sin su confirmación explícita, dado que ya se corrió en Task 2 contra la BD real desde este entorno — si Task 2 ya escribió el backfill completo en la BD compartida 192.168.0.6, este paso es *verificar*, no repetir):

```bash
php cron/debit_snapshot_diario.php
```

Expected: como el backfill de Task 2 ya insertó las ~4,011 filas contra la BD real (compartida entre este entorno y el servidor de producción — mismo host 192.168.0.6), esta corrida debe salir con `nuevos:0, actualizados:0, sin_cambio:~4011`, confirmando que no hay doble backfill accidental.

- [ ] **Step 3: Documentar los pasos de la Tarea Programada de Windows**

Agregar a la sección "Endpoint y cron" del spec (o en un README aparte si el usuario lo prefiere) los pasos concretos:

```
1. Abrir "Programador de tareas" en el servidor donde vive el sitio (IIS).
2. Crear tarea básica:
   - Nombre: TotalGas - Snapshot diario clientes débito
   - Desencadenador: Diariamente, 06:00 AM
   - Acción: Iniciar un programa
     Programa/script: php
     Agregar argumentos: C:\ruta\real\AplicativoPhp\cron\debit_snapshot_diario.php
     Iniciar en: C:\ruta\real\AplicativoPhp
3. En Configuración, marcar "Ejecutar con los privilegios más altos" si el
   usuario que ejecuta la tarea lo requiere para acceder a la red (linked
   servers no aplican aquí, pero sí la conexión TCP normal a 192.168.0.6).
4. Probar con clic derecho → Ejecutar, y verificar en
   TG.dbo.debit_clients_snapshot que updated_at se refresca en las filas
   que cambiaron.
```

- [ ] **Step 4: Commit de la documentación de despliegue**

```bash
git add docs/superpowers/specs/2026-09-17-debit-clients-snapshot-design.md
git commit -m "Documenta pasos de despliegue y Tarea Programada del snapshot de clientes débito"
```

---

## Self-Review Notes (completed during plan authoring)

- **Spec coverage:** Tabla (Task 1), cálculo+diffing (Task 2), endpoint refresh+cron (Task 3), endpoint lectura (Task 4), UI tab aislado (Task 5), JS (Task 6), despliegue/backfill real (Task 7). Todas las secciones del spec están cubiertas.
- **saldo_vehiculos:** presente en la tabla (Task 1), en el cálculo y comparación de cambio (Task 2), en la columna de lectura (Task 4), en la UI y JS (Tasks 5-6) — corrección del spec aplicada de punta a punta.
- **Aislamiento del tab existente:** ninguna tarea toca `edo_debit_table`, `edo_debit_summary_table`, `account_statement_table`, `get_account_summary_debit`, `get_initial_balance_debit` o `get_account_statement_debit`.
- **Nombres consistentes:** `refresh_debit_snapshot` (Task 2) es el mismo nombre usado por el endpoint (Task 3) en todas las tareas; `get_debit_snapshot` (Task 4) es el único método de lectura y coincide entre modelo y controlador; IDs de DOM (`btn_debit_snapshot`, `debit_snapshot_table`, `debit_snapshot_fecha`) coinciden entre Task 5 (HTML) y Task 6 (JS).
