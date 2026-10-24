# Ingresos → Conciliación (venta de crédito vs facturación vs banco)

Fecha: 2026-10-24 · Origen: `Conciliacion_de_venta_Agosto_2026_conciliada.xlsx` (9 hojas, hecho a mano).

## Objetivo

Reproducir en el aplicativo, para cualquier mes y cualquier razón social, la conciliación que hoy se arma en
Excel:

1. **Venta vs facturación:** lo despachado a clientes de crédito contra lo facturado en ControlGas.
2. **Cobranza vs banco:** los cobros de ControlGas contra los depósitos de `TG.dbo.movimientos_bancarios`.

Pantalla `/income/conciliation/` en Sidebar → INGRESOS → Reportes → **Conciliación**, debajo de Clientes, bajo el
permiso 14 (no hay permiso nuevo). Cada tab lleva su nombre más "(hoja del Excel)" entre paréntesis; eso es
temporal.

## Fuentes (validadas con agosto 2026, DIAZ GAS)

| Hoja Excel | Fuente | Validación |
|---|---|---|
| Venta aplicativo | `ClientesModel::get_dispatches_summary_by_client` con tipval 3 y la razón social | 159 clientes, $50,546,497.15 (Corrugados difiere por $3,749.64) |
| Consumos propios | Despachos de clientes en `TG.dbo.clientes_consumo_propio` (excepto Bonificaciones) | 419 despachos, $247,970.86 |
| Control Gas | `SG12.DocumentosC` tip=3 subope=4 "Facturación Crédito"; total = `SUM(Documentos.mto)/100` | — |
| Cobros agosto | `DocumentosC` tip=4 subope=8 "Cobranza a Clientes"; MOV = `@N:MOV.<n>` de `txtref` | 513 cobros, $46,561,695.92 (24 Admin. y 57 Arrendamiento quedan fuera, igual que el Excel) |
| Banco 2791 | `TG.movimientos_bancarios` Banorte 0186932791 | 2,155 movimientos, $32,124,947.32 |

Fechas ControlGas: `dateToInt('2026-08-01')` = 46234 (`Despachos.fchtrn`, `DocumentosC.fch`, `ValesR.fch`).

## Decisión: "facturado" se mide por despacho

El Excel elige facturas por su fecha y por el texto "Consumos del…", y así mezcla periodos: 28 facturas de agosto
traen despachos de julio, y faltan facturas de septiembre con despachos de agosto. Por eso necesitó 5 partidas
a mano.

En el aplicativo, **facturado del mes = despachos del mes cuya factura (`Despachos.nrofac/gasfac` → `DocumentosC`
tip=3) existe y no está cancelada**, sin importar la fecha de la factura. Con agosto DIAZ GAS quedó:

- $50,301,272.50 facturado
- $247,970.86 sin factura (consumo propio)
- 216 despachos sin factura por $3.52

La conciliación cierra sola.

El tab de Facturas conserva la vista "por fecha de factura" y el resumen muestra el puente entre las dos
(facturas del mes − despachos de otros meses + facturas posteriores con despachos del mes).

Factura cancelada: `txtref` empieza con `@N:CANCELAD`. Sin timbrar: `satuid` vacío.

## Tabs

1. Resumen (Conciliación): cascada venta → consumo propio → bonificación → venta neta → facturado → sin facturar →
   diferencia; puente con facturas por fecha; cobranza por estatus.
2. Venta vs facturado (Desglose venta): una fila por cliente con detalle de despachos en un modal.
3. Consumos por cliente (Venta aplicativo).
4. Consumo propio y bonificaciones (Consumos propios).
5. Facturas de crédito (Control Gas): por factura, con despachos del mes y de otros meses.
6. Cobros (Cobros agosto).
7. Cobros vs banco (Conciliación cobros): agrupado por MOV contra `movimientos_bancarios.secuencia` (abono > 0,
   ventana −60/+15 días; se prefiere el importe exacto). Estatus: Cuadra, Diferencia, Sin banco, Sin MOV,
   Cancelado. El MOV se agrupa con los cobros de todas las estaciones, aunque la pantalla filtre por razón social.
8. Banco (Banco 2791): depósitos del mes de las cuentas que recibieron cobros, marcando cuáles quedaron ligados.

Los tabs 1, 2, 5, 6 y 7 se cargan con "Buscar"; los tabs 3, 4 y 8 se cargan al abrirlos.

## Archivos

- `_assets/models/ConciliacionVentaModel.php`, con consultas en tablas temporales y `OPTION (RECOMPILE)`.
- Métodos `conciliation*` en `_assets/controllers/income.php`.
- `views/income/conciliation.html`, con el JS dentro (patrón de `accounting/credit_notes_consumption.html`).
- `routes/income.php` (solo las rutas nuevas; el resto de income sigue por legacy) y el enlace en `views/layouts/sidebar.html`.

## Fuera de alcance

- Débito (facturas E y notas G): ya está en Contabilidad → Notas de Crédito vs Consumos.
- Ligar depósitos de clientes de débito: ver la memoria de anticipos contra Banorte.
- Comentarios manuales por partida (no se crean tablas).
