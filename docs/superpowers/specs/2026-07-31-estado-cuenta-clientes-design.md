# Estado de Cuenta de Clientes (Crédito y Débito) — Diseño

**Fecha:** 2026-07-31
**Vista afectada:** `/income/clients` (`views/income/clients.html`)

## Objetivo

Agregar a la vista de clientes un reporte de estado de cuenta por cliente:
movimientos (cargos y abonos) en un rango de fechas, con saldo inicial,
saldo corrido por renglón y resumen del periodo.

## Fuentes de datos

- **Crédito:** `SG12.dbo.MovimientosAnl` (la tabla que lee `sp_SelMovPen`), con los
  mismos filtros del aplicativo: `tipopr = 1`, `tipmov = 1`, `tipope <> 101`.
  Cargos = `tipope 3` (facturas); abonos = `tipope 4, 6` (pagos / notas de crédito,
  vienen con signo negativo). Montos entre 100. Saldo oficial: `Clientes.cresdo`.
- **Débito:** abonos = facturas de anticipo (`DocumentosC` + `Documentos` con
  `mtoiva > 0`, `codprd NOT IN (combustibles)`, `mto > 100`); cargos = consumos en
  `SG12.dbo.Despachos` (`mto` ya viene en pesos). Saldo oficial: `Clientes.debsdo`.

## Interfaz

Dos pestañas nuevas al final de la vista, siguiendo el patrón de las pestañas de
Consumos: **Edo. Cuenta Crédito** y **Edo. Cuenta Débito**. Cada una con:

1. Selector de cliente con búsqueda (obligatorio), fechas Desde/Hasta
   (default 1 de enero → hoy) y botón Consultar.
2. Tarjetas de resumen: Saldo Inicial, Cargos del periodo, Abonos del periodo,
   Saldo Final calculado y Saldo según sistema (`cresdo`/`debsdo`).
3. DataTable con filtros por columna y botón Excel:
   - Crédito: Fecha, Vencimiento, Movimiento, Documento (con serie C/D/E/G/I/K/T/Z),
     Estación, Importe, Pendiente, Estatus (Saldado/Parcial/Sin aplicar), Saldo corrido.
   - Débito: Fecha, Movimiento, Detalle, Estación, Monto, Saldo corrido.

## Backend

- `ClientesModel`: 4 métodos nuevos —
  `get_account_statement_credit`, `get_initial_balance_credit`,
  `get_account_statement_debit`, `get_initial_balance_debit`.
- `income.php`: endpoint `account_statement_table()` (POST: `tipo`, `codcli`,
  `from`, `until`). Calcula el saldo corrido y los totales en PHP y regresa
  `{ data: [...], summary: {...} }` en un solo JSON.
- `income.js`: función `account_statement_table(tipo)` calcada de
  `clients_dispatches_table()`; llena las tarjetas desde `summary` en `dataSrc`.

## Manejo de errores

Igual que el resto de la vista: alerta alertify si falta cliente o si el
query falla; sin cliente el endpoint regresa data vacía.

## Verificación

No hay framework de pruebas. Validación: `php -l` de los archivos tocados y
comparación en pantalla contra los resultados ya validados en SSMS
(Saldo Final ≈ Saldo Sistema para clientes de crédito).

## Decisiones

- Dos pestañas separadas (consistente con Consumos Débito/Crédito) en lugar de
  una pestaña con switch.
- El selector incluye la opción "— Todos los clientes (resumen) —" (`codcli = 0`):
  muestra una tabla de resumen con un renglón por cliente con movimientos en el
  periodo (crédito: saldo inicial, cargos, abonos, saldo final, saldo sistema;
  débito: anticipos, consumos, diferencia, bolsa) con totales en el pie. El
  detalle movimiento por movimiento sigue requiriendo elegir un cliente, para no
  cargar decenas de miles de renglones al navegador. En el resumen de débito no
  se calcula saldo inicial para evitar recorrer todo el histórico de Despachos.
- El saldo inicial de débito se calcula con el histórico completo de anticipos
  menos consumos previos al periodo; puede diferir de `debsdo` si hubo ajustes
  manuales de bolsa — por eso se muestra también el saldo del sistema.
