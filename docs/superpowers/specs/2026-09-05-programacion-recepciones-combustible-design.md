# Programación de recepciones de combustible — diseño

**Fecha:** 2026-09-05
**Módulo:** Abastos (`_assets/controllers/supply.php`)
**Objetivo:** reemplazar el Excel manual en Drive ("Programa Julio 2026.xlsx") con una sección del aplicativo donde Abastos programa y visualiza las recepciones de combustible de las 40+ estaciones.

## Contexto: qué hace el Excel hoy

Un libro con una hoja por día del mes (01–31). Dentro de cada hoja, bloques repetidos en columnas por **proveedor + terminal/base de carga** (ej. "TESORO MÉXICO SUPPLY AND MARKETING (Diaz Gas)", "MGC (Petrotal) 7", "ENEREY (Petrotal)", "PREMIER GAS (Gaso Mex)"). Cada bloque es una mini-tabla con columnas que **cambian de significado según el proveedor**:

- La mayoría: `FECHA | LITROS | PRODUCTO | ESTACION | HORARIO | TRANSP`
- MGC: `FECHA | LITROS | PRODUCTO | ESTACION | HORARIO | DESTINO | EMBARQUE` (sin columna TRANSP fija, con folio de embarque)
- Petrotal: agrega columna `PROV` (proveedor final real, ya que Petrotal es una terminal compartida por varios proveedores)

Problemas del formato actual: columnas ambiguas (TRANSP es a veces transportista tipo "CARRETERA", a veces código de camión "T2/T3"), fechas de fila que a veces no coinciden con la pestaña del día, notas sueltas mezcladas en celdas ("NO SOLICITAR NADA DE TESORO A GASOMEX"), sin catálogo, sin historial de cambios, un solo archivo compartido en Drive.

## Alcance de esta fase

- Captura y visualización de recepciones programadas. **Sin** enlace automático a la recepción física real (eso ya existe parcialmente vía `PetrotalReconciliationModel` / `MovimientosTanModel` y queda para una fase futura).
- Solo Abastos (usuarios centrales) captura/edita. Las estaciones no capturan ni confirman en esta fase.
- Un formato único y estandarizado de campos para todos los proveedores (se deja de replicar la variabilidad del Excel).
- Import único de "Programa Julio 2026.xlsx" como carga de prueba (script de una sola vez, no una pantalla de importación permanente).

## Modelo de datos

### Tabla nueva: `TG.dbo.fuel_reception_schedule`

| Campo | Tipo | Notas |
|---|---|---|
| `id` | int identity PK | |
| `fecha` | date | día programado de la recepción |
| `hora` | varchar(10) NULL | "HH:MM", opcional |
| `supplier_id` | int | FK a `TG.dbo.Proveedores` |
| `terminal_id` | int | FK a `fuel_terminals` |
| `station_code` | int | FK a `TG.dbo.Estaciones.Codigo` |
| `product` | varchar(20) | 'Regular' \| 'Premium' \| 'Diesel' \| 'Mixta' |
| `mezcla` | varchar(20) NULL | libre, solo cuando `product = 'Mixta'` (ej. "21/11", "16/16") |
| `litros` | int | |
| `carrier_id` | int NULL | FK a `fuel_carriers` |
| `referencia` | varchar(100) NULL | folio de embarque / código de camión / guía |
| `notas` | varchar(500) NULL | texto libre |
| `estatus` | varchar(20) | 'Programado' \| 'Modificado' \| 'Cancelado' (default 'Programado') |
| `created_by` | int | `$_SESSION['tg_user']['id']` |
| `created_at` | datetime | default getdate() |
| `updated_by` | int NULL | |
| `updated_at` | datetime NULL | |

### Tabla nueva: `TG.dbo.fuel_terminals`

| Campo | Tipo | Notas |
|---|---|---|
| `id` | int identity PK | |
| `nombre` | varchar(100) | ej. "Diaz Gas", "Gaso Mex", "Petrotal", "Aguascalientes", "San Miguel de Allende" |
| `supplier_id` | int NULL | proveedor por defecto de esta terminal (informativo; el renglón de programación siempre guarda su propio `supplier_id`, porque una terminal puede recibir despachos de más de un proveedor — caso Petrotal) |
| `activo` | bit | default 1 |

### Tabla nueva: `TG.dbo.fuel_carriers`

| Campo | Tipo | Notas |
|---|---|---|
| `id` | int identity PK | |
| `nombre` | varchar(100) | transportista/ruta/chofer, ej. "Carretera", "QT", "Martillo", "Transpac", "IsaacFZN5287" |
| `activo` | bit | default 1 |

Nota: `fuel_carriers` es un catálogo operativo nuevo, distinto de `creCarriers` (catálogo regulatorio CRE con RFC y permiso, usado para timbrado/cumplimiento). No se reutiliza ese catálogo porque tiene un propósito distinto.

### Proveedores

Se usa `TG.dbo.Proveedores` existente. Antes de implementar, verificar cuáles de estos ya están dados de alta como proveedor: Premier Gas, Tesoro México Supply and Marketing, MGC, Enerey, Petrotal, AEMSA, Essa Fuel, Lobo/Gasomex. Los que falten se dan de alta como proveedor normal (permite a futuro enlazar con facturas/pagos si se desea).

## Pantallas

### 1. Vista por día — `GET /supply/scheduling` y `/supply/scheduling/{fecha}`

- Selector de fecha con flechas prev/siguiente día (equivalente a paginar entre pestañas del Excel), default: hoy.
- Contenido agrupado por **Proveedor → Terminal**: por cada grupo, una tabla con columnas `Hora | Producto | Litros | Estación | Transportista | Referencia | Notas | Acciones`.
- Total de litros programados del día visible arriba (agregable por proveedor si es sencillo).
- Botón "Agregar recepción" → abre modal de captura (patrón existente: vista parcial Twig vía fetch, `modals/xxx.html` sin layout, controlador hace echo del render, JS inyecta con `.html()`).
- Fila con acciones: editar (reabre el modal precargado) y cancelar (soft: pasa `estatus` a 'Cancelado', no se borra físicamente).

### 2. Modal de captura/edición — `views/supply/modals/frmProgramacionRecepcion.html`

Campos, en este orden: Fecha, Proveedor (select), Terminal (select — filtrable por proveedor si `fuel_terminals.supplier_id` coincide, pero no bloqueante), Producto (select: Regular/Premium/Diesel/Mixta) con campo `mezcla` que aparece solo si Producto = Mixta, Litros, Estación (select con buscador sobre las 40+ estaciones), Hora, Transportista (select con opción de alta rápida), Referencia, Notas.

Guardado vía AJAX (`add`/`update` en el controlador), sin recargar página; refresca solo la tabla del grupo correspondiente en la vista del día.

### 3. Catálogos al vuelo

`fuel_terminals` y `fuel_carriers` no tienen pantalla de administración dedicada en esta fase: se dan de alta desde el mismo modal de captura (un select "creatable" que hace insert simple si el usuario escribe un valor nuevo).

## Controlador y modelo

- Nuevo modelo `_assets/models/FuelReceptionScheduleModel.php` — CRUD sobre `fuel_reception_schedule`, más `get_terminals()`, `add_terminal()`, `get_carriers()`, `add_carrier()` (estas dos últimas pueden vivir en modelos propios `FuelTerminalsModel` / `FuelCarriersModel` si se prefiere separar responsabilidades — decidir en el plan de implementación).
- Nuevos métodos en `Supply` controller (mismo archivo `supply.php`, seción Abastos ya lo aloja):
  - `scheduling($fecha = null)` — renderiza la vista por día
  - `scheduling_day_data($fecha)` — devuelve JSON agrupado por proveedor/terminal para poblar/refrescar la tabla (o se resuelve todo server-side con Twig, a decidir en el plan)
  - `scheduling_add()` / `scheduling_update()` / `scheduling_cancel()` — AJAX
  - `scheduling_add_terminal()` / `scheduling_add_carrier()` — alta rápida de catálogo
- Nueva vista `views/supply/scheduling.html` + el modal.
- Nuevo JS `_assets/js/supply.js` (agregar funciones, no archivo nuevo, seguir convención existente) o archivo dedicado `_assets/js/scheduling.js` si el bloque crece mucho — a decidir en el plan.
- Nuevo permiso (ID a definir, siguiendo el patrón de permisos existente en `$_SESSION['tg_user']['permissions']`) para controlar quién accede a `/supply/scheduling`.

## Import de julio 2026 (carga de prueba, una sola vez)

Script PHP standalone (no parte del flujo de la app) que:
1. Lee `Programa Julio 2026.xlsx` con PhpSpreadsheet (ya es dependencia del proyecto).
2. Por cada una de las 31 hojas, recorre los bloques de proveedor/terminal (detectados por las celdas de encabezado tipo "PROVEEDOR (Terminal)" seguidas de la fila de columnas `FECHA|LITROS|PRODUCTO|...`).
3. Normaliza producto a Regular/Premium/Diesel/Mixta (+ mezcla), separa "código estación" y "nombre" del texto libre de la columna ESTACION (ej. "6410 misiones" → station_code=6410, se valida contra `TG.dbo.Estaciones`), resuelve/crea terminal y transportista por nombre, resuelve proveedor por nombre (con mapeo manual para variantes como "TESORO MÉXICO SUPPLY AND MARKETING" → proveedor "Tesoro").
4. Inserta en `fuel_reception_schedule` con `estatus = 'Programado'`.
5. Registra en consola/log filas que no pudo mapear (estación no encontrada, proveedor ambiguo) para revisión manual — no se descartan silenciosamente.

Este script se ejecuta una vez, manualmente, contra la base, y no se integra a ninguna pantalla ni cron.

## Fuera de alcance (fases futuras)

- Enlace/conciliación automática contra la recepción física real (ControlGas / `MovimientosTanModel` / patrón similar a `PetrotalReconciliationModel`).
- Vista calendario mensual con resumen por día.
- Que las estaciones puedan ver, confirmar o solicitar recepciones desde el portal de estaciones.
- Pantallas de administración dedicadas para `fuel_terminals` / `fuel_carriers` (se resuelven con alta rápida desde el modal por ahora).

## Testing

No hay framework de tests en el proyecto (ver CLAUDE.md). Verificación manual: captura de una recepción por cada proveedor real usado en julio 2026, edición, cancelación, navegación entre días, y validación cruzada del import contra el total de líneas de datos del Excel (conteo de renglones no vacíos por hoja vs. filas insertadas).
