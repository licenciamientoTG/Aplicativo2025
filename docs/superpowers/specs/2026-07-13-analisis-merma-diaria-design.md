# Análisis de Merma Diaria — Diseño

**Fecha:** 2026-07-13
**Módulo:** Abastos (AplicativoPhp) + ApiER
**Reemplaza:** el flujo manual Excel `FormatoXxx20XX.xlsm` (hojas por estación + hoja MERMA MENSUAL) que hoy se llena descargando `/supply/tgr01` y ejecutando el macro `ImportarDesdeMerma` día por día.

## Objetivo

Un reporte en el aplicativo que muestre la merma diaria/mensual de combustible por estación, producto y turno, con captura de datos manuales (merma s/d y comentarios), sin depender del Excel ni del llenado manual diario.

## Contexto del flujo actual (lo que se reemplaza)

- `/supply/tgr01` ejecuta `TG.dbo.sp_obtener_inventarios_por_turno`: cursor **secuencial** sobre ~37 estaciones (`TG.dbo.Estaciones`, excluye códigos 0, 4, 20) con `sp_testlinkedserver` + `OPENQUERY`. Devuelve por Fecha/Estación/Producto/Turno: `VentasReales`, `Inventario` (físico, último corte StockReal del turno), `CantidadCompra`, `InventarioInicial` (turno anterior), `InventarioContable` (= inicial − ventas + compras), `Diferencia` (= físico − contable), `Estado` ("DIFERENCIA" si ≥ 9000).
- El Excel replica ese cálculo por turno y acumula por mes en MERMA MENSUAL: merma por producto, venta total, % merma = merma/venta, columnas manuales "MERMA S/D (LTS)" y comentarios, y al pie promedio diario, proyección a fin de mes y valorización en pesos (precio fijo 18.99).
- El inventario inicial de cada mes se arrastra a mano desde el archivo del mes anterior. Con este diseño deja de ser necesario: el dato sale del último corte del día anterior directamente en la estación.

## Decisiones tomadas

1. **Alcance:** dos vistas — resumen mensual (tipo MERMA MENSUAL) + detalle diario por turno por estación (tipo hoja del Excel).
2. **Captura manual incluida:** merma s/d por producto y comentarios por estación/mes.
3. **Estrategia de datos:** snapshot diario en tabla de TG llenado por cron, con botón "Actualizar" que re-consulta estaciones bajo demanda (los datos de estación cambian retroactivamente: capturas tardías, correcciones).
4. **Consulta a estaciones:** endpoint nuevo en ApiER con `ThreadPoolExecutor(40)` (patrón de `estacion_documentos_compra`), usado tanto por el cron como por el botón. No se usa el SP secuencial.
5. **Periodo:** selector de mes/año en el resumen.
6. **Permisos:** mismo permiso 33 (Reportes de Abastos) para ver y capturar.
7. **Valorización:** precio por litro editable por mes, default 18.99.

## Arquitectura

```
Cron (madrugada, D-1 y D-2) ─┐
                             ├─> PHP /merma/sync ──POST──> ApiER /api/inventarios_turnos/
Botón "Actualizar" (modal) ──┘         │                        │ ThreadPool(40) × OPENQUERY
                                       │                        └─> estaciones (linked servers)
                                       └─ upsert ─> TG.dbo.merma_diaria + merma_sync_log

/merma/analisis  (resumen mensual)  ──lee──> merma_diaria + merma_manual + merma_mes_config
/merma/detalle   (día × turno)      ──lee──> merma_diaria
```

## Base de datos (TG)

### `TG.dbo.merma_diaria` — snapshot

| Columna | Tipo | Nota |
|---|---|---|
| `id` | INT IDENTITY PK | |
| `fecha` | DATE | día operativo (fch − 1) |
| `codgas` | INT | código de estación en TG.dbo.Estaciones |
| `estacion` | NVARCHAR(255) | nombre denormalizado |
| `codprd` | INT | 1, 2, 3, 179, 180, 181, 192, 193 |
| `producto` | NVARCHAR(255) | descripción de la estación |
| `turno` | INT | 11, 21, 41 (30/31 se normalizan a 41, igual que el SP) |
| `ventas_reales` | FLOAT | |
| `inv_fisico` | FLOAT | |
| `compras` | FLOAT | |
| `inv_inicial` | FLOAT | |
| `inv_contable` | FLOAT | inicial − ventas + compras |
| `diferencia` | FLOAT | físico − contable (negativo = merma) |
| `updated_at` | DATETIME | última sincronización |

`UNIQUE (fecha, codgas, codprd, turno)`. Cada sync hace upsert (MERGE o delete+insert por rango/estación), por lo que re-sincronizar sobreescribe correcciones sin duplicar.

**Familias de producto (solo presentación):** (1, 179, 192) → MAXIMA; (2, 180, 193) → SUPER; (3, 181) → DIESEL. El snapshot guarda el `codprd` real.

### `TG.dbo.merma_manual` — captura por estación/mes

`id`, `codgas`, `anio`, `mes`, `merma_sd_maxima`, `merma_sd_super`, `merma_sd_diesel` (FLOAT NULL), `comentarios` NVARCHAR(MAX), `updated_by`, `updated_at`. `UNIQUE (codgas, anio, mes)`.

### `TG.dbo.merma_mes_config` — configuración por mes

`id`, `anio`, `mes`, `precio_litro` FLOAT (default 18.99), `updated_by`, `updated_at`. `UNIQUE (anio, mes)`.

### `TG.dbo.merma_sync_log` — bitácora

`id`, `fecha_sync` DATETIME, `origen` ('cron' | 'manual'), `usuario` (NULL para cron), `desde` DATE, `hasta` DATE, `codgas` (0 = todas), `estaciones_ok` INT, `estaciones_error` INT, `detalle_errores` NVARCHAR(MAX), `duracion_seg` FLOAT.

## ApiER — endpoint nuevo

**`POST /api/inventarios_turnos/`** — body: `{"from": "YYYY-MM-DD", "to": "YYYY-MM-DD", "codgas": 0}` (codgas 0 = todas).

- Nueva función `get_inventarios_turnos_estacion(servidor, base_datos, codgas, from_fch, until_fch)` en `api/modelos/inventarios_estaciones.py`, portando **la consulta interna del SP** `sp_obtener_inventarios_por_turno` (misma lógica por turno: ventas normalizando nrotur 30/31→41; físico de StockReal mapeando 10→11, 20→21, 30/40→41 excluyendo 30/31; inv. inicial del día anterior; compras de Movimientos con can > 0 agrupadas a 11/21/41) vía `OPENQUERY` contra el linked server, ejecutada desde `CONTROLGAS_CONN_STR`.
- Vista `inventarios_turnos_distribuido` en `api/TG_php/views.py` con `ThreadPoolExecutor(max_workers=40)`, estaciones desde `TG.dbo.Estaciones` (activas, excluyendo 0, 4, 20; o solo `codgas`).
- **Fechas:** convertir YYYY-MM-DD a serial ControlGas (días desde 1899-12-31; equivalente a `dateToInt()` en PHP — validar contra él en la implementación). NO usar YYYYMMDD (defecto conocido de `inventarios_distribuido`).
- **Respuesta:** `{"resultados": [ {Estacion, Codigo, filas: [...]}, ... ], "errores": [ {Codigo, Nombre, error}, ... ], "duracion_seg": n}` — los errores alimentan `merma_sync_log`.
- Despliegue por SFTP como el resto de ApiER.

## AplicativoPhp — controlador, modelo, vistas

- **Controlador nuevo** `_assets/controllers/merma.php` (agregar caso de routing en `index.php`), permiso 33.
- **Modelo nuevo** `_assets/models/MermaDiariaModel.php` (extiende `Model`): upsert del snapshot, agregados mensuales, CRUD de `merma_manual` / `merma_mes_config`, inserción en `merma_sync_log`.
- **JS** `_assets/js/merma.js`; **vistas** `views/merma/`.
- **Sidebar:** "Análisis de merma diaria" dentro de Reportes de ABASTOS (`authorized(33)`), junto a "Mermas por estación".

### Pantalla 1: `/merma/analisis` — resumen mensual

- Selector mes/año (default: mes actual).
- Tabla, fila por estación (todas las activas de TG, no lista hardcodeada):
  `ESTACIÓN | MAXIMA | SUPER | DIESEL | TOTAL | VTA TOTAL | % MERMA | MERMA S/D (M | S | D) | COMENTARIOS`
  - Merma por familia = SUM(diferencia) del mes; VTA TOTAL = SUM(ventas_reales); % MERMA = total/venta con color: verde |%| < 0.5%, amarillo 0.5–1%, rojo > 1% (umbral ajustable en código).
  - MERMA S/D y COMENTARIOS editables inline (AJAX → `merma_manual`, guarda usuario y fecha).
  - Clic en la estación → pantalla 2.
  - Estación con días faltantes en el mes → ícono de advertencia con tooltip de los días sin datos.
- Fila TOTALES + KPIs al pie: merma promedio diaria (total ÷ **días con datos**), proyección al cierre (promedio × días del mes), valorización en pesos (× `precio_litro` editable inline → `merma_mes_config`).
- Botón **"Actualizar datos"**: modal con desde/hasta (default: ayer y hoy) y estación opcional → `POST /merma/sync` → muestra resultado ("35 estaciones OK, 2 sin conexión: …") y recarga.
- Export a Excel (botón DataTables, como tgr01).

### Pantalla 2: `/merma/detalle/{codgas}?anio=&mes=`

- Encabezado: estación, mes, y totales del mes por familia (merma, ventas, % merma).
- Tabla: fila por día × turno (11/21/41); 3 bloques de columnas MAXIMA/SUPER/DIESEL, cada uno: `VR | COMPRAS | INV. CONT. | INV. FÍSICO | DIFERENCIA | ACUM.` (acumulado del mes de la diferencia).
- Diferencia negativa en rojo, positiva en verde; badge "DIFERENCIA" si |dif| ≥ 9,000 lts (mismo criterio del SP).
- Botón para re-sincronizar solo esa estación en el mes mostrado.

### Sincronización

- **`/merma/sync`** (POST `from`, `to`, `codgas`): llama a ApiER, upsert en `merma_diaria`, registra en `merma_sync_log`, devuelve JSON con resumen. Autorización: sesión con permiso 33 **o** `cron_token == CRON_SECRET` (patrón de `payment.php`).
- **Cron diario** (madrugada): invoca `/merma/sync` con D-1 y D-2 y `cron_token` — se programa en el mismo mecanismo que dispara los crons de payment (Task Scheduler).

## Manejo de errores

- Estación sin conexión durante un sync: no trae filas; queda en `detalle_errores` del log y visible como "sin datos" en las vistas; el siguiente sync (cron o manual) la completa.
- ApiER caído: `/merma/sync` responde error claro; el reporte sigue funcionando con el último snapshot.
- Timeout: el request PHP→ApiER usa timeout amplio (~120 s); ApiER limita cada estación a 60 s (timeout de pyodbc ya usado en el módulo).

## Fuera de alcance

- Migrar históricos de los Excel anuales (MERMA ANUAL 23–26).
- Vista anual consolidada (puede construirse después sobre `merma_diaria`).
- Modificar `/supply/tgr01` o `/supply/inventarioMermas` (siguen funcionando igual).

## Pruebas / verificación

No hay framework de tests en el proyecto. Verificación manual:

1. Comparar la salida del endpoint ApiER contra `/supply/tgr01` para una estación y fecha con datos (mismos números por turno).
2. Sync de un mes en curso y comparación de la fila de una estación en `/merma/analisis` contra la hoja del Excel del mismo mes.
3. Botón actualizar con una estación apagada → aparece en errores y no rompe el resto.
4. Captura de merma s/d, comentario y precio → persisten y registran usuario.
5. Cron vía `curl` con `cron_token` → llena D-1/D-2 y escribe log.
