# Reporte Valeras (Dirección / TG6)

**Fecha:** 2026-07-07
**Estado:** Aprobado

## Objetivo

Nueva vista `/direction/valeras` en el menú Dirección → TG6 que muestra los litros
despachados pagados con vales/valeras (Sodexo, Efectivale, Edenred, Inburgas, etc.)
por estación y denominación, con los meses del rango como columnas.

## Fuente de datos

Query sobre `SG12` (misma instancia `192.168.0.6`, sin OPENQUERY):
`Despachos` ⋈ `gasolineras` ⋈ `Productos` ⋈ `MovimientosTar` ⋈ `Valores`,
agrupado por estación (`abr`), denominación (`Valores.den`), año y mes de `fchtrn`.
Se excluyen denominaciones bancarias/no-vale (lista `NOT EXISTS ... VALUES`).
Orden: estación, orden personalizado de denominaciones, año, mes.

Nota: se eliminó el `LEFT JOIN DocumentosC` del query original porque no se usa en
SELECT/WHERE y podría duplicar filas en la suma.

Las fechas se reciben como `Y-m-d`, se validan en PHP y se convierten a serial
entero con `dateToInt()` (días desde 1900-01-01 + 1); se pasan como parámetros
ligados al query.

## Diseño (pivote en PHP)

| Pieza | Archivo | Detalle |
|-------|---------|---------|
| Modelo | `_assets/models/MovimientosTarModel.php` | `get_valeras_report($fromInt, $untilInt)` — filas planas (abr, denominacion, anio, mes, total) |
| Controlador | `_assets/controllers/direction.php` | `valeras()` renderiza la vista; `valeras_table()` (AJAX POST) pivotea en PHP y responde `{columns, data}` |
| Vista | `views/direction/tg6/valeras.html` | Filtros Desde (1-ene año actual) / Hasta (hoy) + tabla DataTables |
| JS | `_assets/js/direction/tg6.js` | `valeras_table()` — construye thead/tfoot dinámicos según `columns`, DataTable con botón Excel y totales en footer |
| Sidebar | `views/layouts/sidebar.html` | Link "Valeras" dentro del dropdown TG6 (permiso 43) |

### Formato del pivote

- Fila = estación + denominación (orden del SQL preservado por inserción en PHP).
- Columna por cada mes del rango (`Ene 2026 … Jul 2026`), clave `YYYY_M`.
- Columna final Total por fila; footer con totales por columna.

### Manejo de errores

- Fechas inválidas o rango invertido → `{columns: [], data: []}`.
- Sin resultados → tabla vacía + alerta estándar del proyecto (patrón de `tg6_product`).

## Fuera de alcance

- Nuevo ID de permiso (usa el 43 de Dirección como el resto de TG6).
- Filtro por estación (se puede filtrar con los inputs por columna de DataTables).
