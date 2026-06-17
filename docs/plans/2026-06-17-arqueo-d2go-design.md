# Diseño — Módulo de Arqueos Simultáneos Dollar2Go

Fecha: 2026-06-17
Autor: Alejandro Martínez (asistido por Claude Code)

## Contexto

Dollar2Go (casa de cambio) realiza **arqueos simultáneos**: todas las sucursales
cuentan su caja física al mismo tiempo y cada cajero llena su propia hoja.
Hay 13 sucursales; Waterfill y Pérez Serna tienen 2 cajas cada una (registros
separados). Cada arqueo captura **USD** y **MXN**.

Este módulo se integra al aplicativo PHP MVC existente de TotalGas (NO Laravel),
sobre SQL Server (`sqlsrv` vía PDO), con las tablas dentro de la base **TG**.

## Decisiones de diseño

- **Stack**: clase de controlador plana construida con `$twig` (patrón de
  `payment.php`); métodos leen `$_POST`/`$_GET`; modelos extienden `Model` y usan
  `$this->sql->select/insert/update/delete`. Schema en T-SQL (`IDENTITY`,
  `CHECK`, FKs) — no MySQL.
- **Base de datos**: tablas `arqueo_*` en `TG.dbo`.
- **Ciclo de sesión**: `programado → abierto → cerrado`. `cerrado` es inmutable.
- **Permisos**: se usa el sistema existente (`tg_permissions` / `tg_permissions_users`
  y el helper `authorized($id)`). Se agregan **2 permisos nuevos**:
  - `arqueo_admin` — auditor administrador: programa/abre/cierra sesiones, ve
    concentrado, exporta.
  - `arqueo_capturar` — usuario auditor: abre y guarda la(s) caja(s) que captura
    al ir a la casa de cambio.
- **Routing**: `index.php` autocarga el controlador por nombre de archivo, así que
  `/arqueo/...` funciona en cuanto exista `arqueo.php`. No se edita `index.php`.

## Modelo de datos (TG.dbo)

- `arqueo_sesiones` — cabecera del arqueo simultáneo (nombre, fecha, estado,
  created_by, closed_by/at).
- `arqueo_cajas` — una fila por caja/cajero (Go Exchange, tipos de cambio, totales
  calculados persistidos, estado pendiente/completado).
- `arqueo_denominaciones` — detalle de conteo (seccion, moneda, tipo, denominacion,
  valor_bolsa, cantidad, total).
- `arqueo_vales` — hasta 15 vales por caja.
- El **Concentrado** se calcula on-the-fly agrupando `arqueo_cajas` por sucursal.

Ver `docs/sql/arqueo_schema.sql`.

## Cálculos (validados contra "ARQUEO SIMULTANEO JUNIO 2026.xlsx")

Por denominación:
- billete/moneda: `total = cantidad * denominacion`
- fajilla:        `total = cantidad * denominacion * 100` (1 fajilla = 100 billetes)
- bolsa (morrallero_cf): `total = cantidad * valor_bolsa`

```
total_fisico_dolares = cajon_usd + morrallero_usd + caja_fuerte_usd + morrallero_cf_usd
total_fisico_mxn     = cajon_mxn + morrallero_mxn + caja_fuerte_mxn + morrallero_cf_mxn
total_vales_mxn      = Σ vales.mxn ; total_vales_dolares = Σ vales.dolares
gran_total_vales_mxn = total_vales_dolares + total_vales_mxn
total_arqueo_mxn     = total_fisico_mxn + total_vales_mxn
total_en_sistema     = (go_exchange_dolares * tipo_cambio_venta) + go_exchange_mxn
diferencia_dolares   = total_fisico_dolares - go_exchange_dolares
diferencia_mxn       = total_arqueo_mxn - go_exchange_mxn
resultado_final      = diferencia_mxn + (diferencia_dolares * tipo_cambio_venta)
                       (> 0 sobrante, < 0 faltante)
```

## Métodos del controlador `Arqueo`

- `index()` — lista de sesiones con conteo completadas/total.
- `crear_sesion()` — [admin] crea sesión `programado` + cajas por sucursal.
- `abrir($sesion_id)` — [admin] `programado → abierto`.
- `mostrar_caja($caja_id)` — [auditor] formulario de captura (solo-lectura si cerrado).
- `guardar_caja($caja_id)` — [auditor] valida, recalcula totales, guarda (transacción);
  rechaza si la sesión está cerrada.
- `concentrado($sesion_id)` — [admin] consolidado agrupado por sucursal.
- `cerrar($sesion_id)` — [admin] exige todas las cajas completadas; `abierto → cerrado`.
- `exportar($sesion_id)` — [admin] xlsx con PhpSpreadsheet.

## Pendientes (post-entrega)

- Ajustar `Arqueo::PERM_ADMIN` y `Arqueo::PERM_AUDITOR` con los IDs reales tras
  correr los INSERT a `tg_permissions`.
- Mapear DIRECTORIO (sucursal → cajero) para precargar nombres.
- Vistas Twig (`views/arqueo/*`): segunda iteración.
