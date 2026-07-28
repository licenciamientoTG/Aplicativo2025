# Saldo final por empresa — card "Saldo final por cuenta"

Fecha: 2026-07-28
Vista: `/tesoreria/movimientos_bancos` · card colapsable "Saldo final por cuenta"
Spec base del módulo: `2026-07-22-tesoreria-movimientos-bancos-design.md`

## Problema

El card lista 36 cuentas planas ordenadas por número de cuenta. Tesorería razona
por empresa ("¿cuánto tiene Gasomex en total?"), no por cuenta suelta, y hoy eso
obliga a sumar a mano las 7 cuentas de Gasomex repartidas por la tabla.

Además el TOTAL actual suma pesos con dólares: 5 de las 36 cuentas (`825009…`)
son `DOLAR AMERICANO` y hoy entran al mismo número que las de MXN.

## Regla de agrupación

La llave del grupo es `CatalogosCuentasBancarias.Descripcion`, resuelta con
`c.CuentaLocal = m.cuenta` — **match exacto, sin fallbacks**.

Verificado contra producción al 2026-07-28:

- 35 de 36 cuentas de movimientos empatan por match exacto.
- No hay `CuentaLocal` duplicada en el catálogo, así que el `LEFT JOIN` no
  duplica filas de saldo ni infla los totales (36 filas dentro, 36 fuera).
- Ninguna cuenta empata con dos `Descripcion` distintas.
- La única sin match es la de Afirme `11621009097`, que en el catálogo vive
  como CLABE `062164116210090979` (DIAZ GAS AFIRME).

Las cuentas sin match caen en un grupo `SIN CATÁLOGO`, marcado con ⚠ y forzado
al final de la lista. Es deliberado que se vea: expone cuentas que tesorería
debe dar de alta, en vez de esconderlas detrás de un match difuso.

### Divisa

`Divisa = 'DOLAR AMERICANO'` → USD. Cualquier otro valor, incluidos `NULL` y el
`'peso'` en minúsculas que existe en un registro del catálogo, → MXN.

**Nunca se suman monedas distintas.** Cada grupo lleva subtotal MXN y, solo si
tiene cuentas en dólares, una segunda línea USD. El pie hace lo mismo.

## Componentes

### 1. `MovimientosBancariosModel::get_saldos_finales(string $hasta): array`

Agrega tres columnas del catálogo al query existente. Las claves
`banco / cuenta / fecha / saldo` no cambian, así que el KPI "Saldo final" que
`Tesoreria::movimientos_bancos()` calcula con este mismo método sigue igual.

```sql
SELECT t.banco, t.cuenta, t.fecha, t.saldo,
       c.Descripcion AS descripcion,
       c.Divisa      AS divisa,
       c.Tipo        AS tipo
FROM (
    SELECT banco, cuenta, fecha, saldo,
           ROW_NUMBER() OVER (PARTITION BY banco, cuenta
                              ORDER BY fecha DESC, id DESC) AS rn
    FROM [TG].[dbo].[movimientos_bancarios]
    WHERE fecha <= ?
) t
LEFT JOIN [TG].[dbo].[CatalogosCuentasBancarias] c ON c.CuentaLocal = t.cuenta
WHERE t.rn = 1
ORDER BY t.cuenta;
```

### 2. `Tesoreria::saldos_finales()`

Arma los grupos en PHP; la vista no suma nada. Respuesta JSON:

```json
{
  "success": true,
  "hasta": "2026-07-28",
  "grupos": [
    {
      "descripcion": "DISTRIBUIDORA GASOMEX SA DE CV",
      "sin_catalogo": false,
      "totales": {
        "MXN": { "n": 6, "saldo": 1589202.32 },
        "USD": { "n": 1, "saldo": 16573.90 }
      },
      "cuentas": [
        { "cuenta": "65504998214", "banco": "SANTANDER",
          "fecha": "2026-07-27", "saldo": 1536105.14,
          "moneda": "MXN", "tipo": "Propias" }
      ]
    }
  ],
  "totales": {
    "MXN": { "n": 31, "saldo": 6384552.03 },
    "USD": { "n": 5,  "saldo": 35562.04 }
  },
  "saldos": [],
  "total": 0
}
```

`totales.USD` se omite en un grupo que no tiene cuentas en dólares. Dentro de
cada grupo las cuentas van ordenadas por saldo descendente.

Orden de los grupos: subtotal MXN descendente; `SIN CATÁLOGO` siempre al final,
sin importar su saldo.

`saldos` y `total` se conservan con el contrato plano anterior para no romper a
otro consumidor del endpoint.

### 3. Vista `views/tesoreria/movimientos_bancos.html`

Se reescribe el render de `#saldosCollapseBody`:

- Una fila de encabezado por grupo: chevron, nombre de la empresa, número de
  cuentas y subtotal MXN; línea USD debajo solo si aplica. Clic en la fila
  expande/colapsa sus cuentas.
- Todos los grupos arrancan **colapsados**: al abrir el card se ve la posición
  por empresa en una pantalla.
- Filas de cuenta: mismo formato de hoy (cuenta, banco, último movimiento,
  saldo), con el prefijo de moneda en las de USD y conservando el aviso ⚠ de
  "sin movimientos al corte: saldo de un día anterior".
- El pie pasa a dos líneas: TOTAL MXN y TOTAL USD.
- Se conservan `.mb-saldos-scroll`, `.mb-saldos-tabla`, `fmtMonto` y `fmtFecha`.

## Manejo de error

Sin cambios respecto de hoy: `success: false` o fallo de red pintan el mismo
`alert-danger` y `saldosCargados` vuelve a `false` para permitir reintento al
reabrir el card. Un `grupos` vacío muestra "Sin saldos registrados al …".

## Carga de la tabla de movimientos

Cambio adjunto, del mismo día: la vista dejó de traer movimientos al entrar.

`Tesoreria::movimientos_bancos()` ya no consulta nada — solo renderiza el
cascarón (filtros, tablas vacías, KPIs en blanco). Los datos los pide el JS por
`POST /tesoreria/movimientos_table`, mismo patrón que `/income/clients`.

Un solo endpoint alimenta las dos tablas y las tarjetas, porque salen de una
sola consulta:

```json
{ "success": true,
  "filtros":   { "desde": "…", "hasta": "…", "cuenta": "", "tipo": "" },
  "santander": [ … ], "afirme": [ … ],
  "kpis": { "totales":  { "abonos": 0, "cargos": 0, "neto": 0, "movs": 0 },
            "porBanco": { "SANTANDER": {…}, "AFIRME": {…} },
            "saldo":    { "final": 0, "fecha": "…", "porBanco": {…} } } }
```

`filtros_movimientos()` normaliza `$_GET` y `$_POST` con las mismas reglas para
que la vista y el ajax no puedan divergir.

Las tablas se crean **una sola vez, vacías**; cada búsqueda hace
`clear().rows.add().draw()`. No se destruyen ni se recarga la página, así que
se conservan el panel de saldos abierto, el tab activo y el ancho de columnas.
Se mantienen `ordering: false` y `paging: false`: el orden lo fija el SQL
(`fecha, id`) y reordenar rompería la cadena de saldos.

`get_movimientos()` pasó de `SELECT *` a proyectar solo las 17 columnas que la
tabla muestra, porque el resultado ahora viaja como JSON. Medido a 7 días
(1,852 movimientos): payload de 1,145 KB a 728 KB y consulta de 273 ms a 157 ms.

Efectos colaterales atendidos: el corte del panel de saldos y su encabezado
siguen al campo "Hasta" en vez del valor horneado en el render, y el flujo
post-importación mueve los filtros al rango del archivo y vuelve a buscar en
lugar de redirigir.

## Fuera de alcance

El KPI "Saldo final (todas)" de la fila de tarjetas sigue sumando MXN y USD en
un solo número. Es el mismo defecto de divisa, pero en otro componente; se
atiende aparte para no mezclar cambios.

## Verificación

Cifras esperadas al corte 2026-07-28 (validadas contra producción):

| | Cuentas | Saldo |
|---|---|---|
| TOTAL MXN | 31 | 6,384,552.03 |
| TOTAL USD | 5 | 35,562.04 |
| GASOMEX MXN | 6 | 1,589,202.32 |
| GASOMEX USD | 1 | 16,573.90 |
| SIN CATÁLOGO | 1 | 121,210.70 |

Total de grupos: 24.
