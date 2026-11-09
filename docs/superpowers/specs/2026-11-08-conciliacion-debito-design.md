# Ingresos → Conciliación débito (prepago) — diseño

Fecha: 2026-11-08 · Estado: BORRADOR para aprobación · Módulo hermano de `income/conciliation` (crédito)

## 1. Intención

Hoy la conciliación de venta **prepago (débito)** de DIAZ GAS se hace a mano en
`Conciliacion prepago Diaz Gas septiembre_2026.xlsx` (14 hojas). Igual que se hizo con
crédito (`/income/conciliation`), el Excel se convierte en una sección de la app que se
recalcula con solo elegir **mes** y **razón social**, sin pegar reportes ni capturar
partidas a mano.

Sidebar (Ingresos → Reportes): "Conciliación" pasa a llamarse **Conciliación crédito**
(misma ruta `income.conciliation`, solo cambia el texto) y debajo se agrega
**Conciliación débito** (`income.conciliation_debit`).

## 2. Objetivos / no objetivos

Objetivos
- Tres cuadres, igual que el Excel: **A** venta vs facturación de consumos (Serie E),
  **B** cobros prepago vs anticipos facturados, **C** cobros vs bancos.
- Una pestaña por hoja del Excel (lista aprobada en §5), con el mismo estilo, ayuda
  "¿Qué estoy viendo?", chips por estatus, totales de filas filtradas y exportación a Excel que ya tiene crédito.
- Las explicaciones de partidas por cliente (hoy texto escrito a mano en el Excel) pasan a
  **estatus calculados**; el Resumen los agrupa en partidas con enlace a su tab.
- Septiembre 2026 DIAZ GAS reproduce el Excel (con las salvedades de §7).

No objetivos
- Comentarios libres por partida o guardar nada en BD (fuera de alcance, igual que en crédito).
- Permiso nuevo: se usa el **14** (Ingresos > Reportes).
- Migrar la hoja `Hoja2` (tabla dinámica de Monto/Cortes: ya sale en Desglose venta).
- Cambiar el cálculo de la conciliación de crédito.

Anti-objetivo: que cuadre "a fuerza" ocultando facturas o partidas raras (el usuario ya rechazó
ocultarlas en crédito). Todo se muestra; lo que no se explica queda como "Diferencia sin explicar".

## 3. Lo que se hereda de crédito (decisiones ya tomadas por el usuario)

- Filtros mes + razón social (`SG12.Empresas`), sidebar colapsado al abrir, insignia de progreso "n/N" con panel de consultas.
- Tabs con contador (activo relleno en verde), botón `?` por tab/card con "de dónde viene cada dato / cómo interpretarlo".
- DataTables con fila de filtros, cabecera fija, `tfoot` con total de filas filtradas, botones Excel y Columnas, cards colapsables de resumen.
- Chips por estatus (conteo + suma; en pendientes muestran lo que falta).
- Cobros: bandas verde (cobro) / azul (banco), fila roja sin movimiento, "Cobros del depósito".
- Resumen en cascada con renglones `cv-link` que saltan al tab con el filtro puesto; insignia Conciliado/Revisar y "% cuadrado".
- No ligar por monto despacho↔vale; turno 1–4; fechas con `dateToInt` (2026-09-01 = 46265); tabs sin "(hoja Excel)".
- Modal de detalle al hacer clic en un cliente.

## 4. Llaves de datos descubiertas (SELECT, sept-2026, DIAZ GAS = empresa 1)

| Dato | Llave en BD | Validado |
|---|---|---|
| Consumos / Cortes por cliente | `ClientesModel::get_dispatches_summary_by_client($from,$until,4,0,$empresa,'incluir')` (tipval 4) | método existente (hoja Consumos) |
| **Serie E** (facturación de consumos) | `SG12.DocumentosC` tip 3, **nro 1300000000–1399999999**, codgas 0, razón social por `DocumentosC.codemp`. Subopes: 44 "Facturacion Consumos" (758 docs, $31,175,347.85) y 18 "Facturación Débito Tarjetas" (1 doc, $26,389.99, la E 101,872) | vigentes = **$31,201,737.84** |
| Cancelada | `txtref LIKE '@N:CANCELAD%'` | igual que crédito |
| **Anticipos** | `ClientesModel::get_debit_anticipos_report` (tipval 4; facturas nro **12…**, subope 18) | 1,871 / $32,591,067.05 |
| **Cobros prepago** | `DocumentosC` tip 4 **subope 19** "Cobro Clientes de Tarjetas debito"; monto = `SUM(Documentos.mto)/100`; razón social por estación | 1,861 / **$32,605,435.05 = Excel exacto** |
| Cobros de crédito (para clasificar depósitos) | tip 4 subope 8 (ya en `ConciliacionVentaModel::cobros`) | |
| Factura del cobro | Referencia `Fac.D242940 ($3,500.00)` → nro **12**00242940 (D = prefijo 12, E = 13); también `DocumentosA` (nroapl/mtoapl) como en crédito | |
| Banco 2791 | `TG.movimientos_bancarios` cuenta **BANORTE 0186932791**: 2,062 movs, 1,925 abonos, **$37,229,115.24 = Excel** | |
| Banco 0956 (tarjetas) | cuenta **BANORTE 0601500956**: 2,329 movs (= hoja 0956) | |
| Cuentas propias (traspasos) | `TG.dbo.CatalogosCuentasBancarias` (SELECT TOP 5 en Task 5): columnas reales `Id, CuentaLocal, Descripcion, TipoCuenta, Banco, TitularCuenta, Activo, Tipo, emp_cod…`. **No existe `TipoCuenta='Propia'` fiable** (solo 7 filas, varias con `Tipo='Terceros'`): las 2791/0956 traen `TipoCuenta` NULL y `Tipo='Propias'`. Se usa `Tipo='Propias' AND Activo=1` sobre `CuentaLocal` | `ConciliacionDebitoModel::cuentas_propias()` |

Salvedades del Excel vs sistema (el Excel es una foto; el sistema ve cancelaciones posteriores):
- **Serie E:** el Excel suma $31,988,096.76 = vigentes $31,201,737.84 + **24 facturas E 101,823…** canceladas el 30/09 con motivo 02 ($786,358.92) que el reporte aún traía con monto.
- **Anticipos:** el Excel trae 1,873 / $32,599,567.05; faltan **1200244351 ($2,500)** y **1200243922 ($6,000)**, hoy canceladas (motivo 02). Eran justo dos partidas "anticipo facturado sin cobro" del Excel.
- Por eso el sistema mostrará esas cancelaciones como partida **"Factura cancelada"** (como crédito), y la validación contra el Excel se hace sumándolas.

## 5. Tabs (una por hoja)

| # | Tab | Hoja(s) del Excel | Contenido |
|---|---|---|---|
| 1 | **Resumen** | Conciliación, Conciliación anticipos, resumen de Conciliación cobros | 3 cards (§6) |
| 2 | **Desglose venta** | Desglose venta | Card "Resumen por clasificación" (Venta / Consumo propio / Bonificación) + una fila por cliente: despachos, litros, monto, cortes, Monto−Cortes, facturado mes, facturado mes anterior, facturado mes siguiente, cancelada, diferencia, **Estatus**. Clic → modal despachos vs cortes |
| 3 | **Consumos por cliente** | Consumos | Mismo reporte de Clientes > Consumos Débito |
| 4 | **Consumos propios** | (nuevo; en el Excel solo hay columna "Propio") | Clientes de `TG.clientes_consumo_propio`; ahí cae el renglón "dollar to go" ($11,200) |
| 5 | **Facturas de consumos (Serie E)** | facturacion consumos + facturacion consumos oct | Una fila por factura E con columna **Periodo**: Del mes / Mes anterior / Mes siguiente / Cancelada / Sin periodo. Octubre es el periodo "Mes siguiente", ya no un monto capturado |
| 6 | **Despachos pendientes de facturar** | despachos pendientes de fact | Despachos débito sin factura (`nrofac = 0`), con cliente, estación, producto |
| 7 | **Anticipos** | Anticipos | Facturas de anticipo del mes; columna "Cobro aplicado" y estatus (Con cobro / Sin cobro / Cancelada) |
| 8 | **Anticipos por cliente** | anticipos x cliente | Monto anticipado por cliente + saldo (`Clientes.debsdo`) |
| 9 | **Cobros por factura** | Cobros x factura | Cobro → factura(s) leída(s) de la Referencia/`DocumentosA`, importe aplicado, **¿Existe en Anticipos?** |
| 10 | **Conciliación cobros** | Cobros + Conciliación cobros (unidas, como en crédito) | Una fila por cobro, mismo cruce contra banco; estatus extra: Efectivo, Depositado en mes anterior, Consumo propio (D2GO), No localizado |
| 11 | **Banco 2791** | cuenta 2791 | Movimientos con **Clasificación**: Cobro prepago / Cobro crédito / Traspaso entre cuentas / Retiro / No identificado + integración de depósitos del mes |
| 12 | **Banco 0956** | 0956 | Movimientos de tarjetas, misma tabla |

(12 tabs: los 11 aprobados con Banco dividido en sus dos hojas.)

## 6. Resumen (3 cards)

**A. Venta vs facturación** — Venta total consumos (monto) · (−) consumos propios · (−) bonificaciones = **venta neta** ·
Facturado Serie E vigente · (−) facturas del mes anterior emitidas en el mes = **facturado consumos del mes** ·
**Diferencia** · partidas agrupadas por estatus (cada una con conteo, importe y enlace al tab 2 filtrado) ·
"Factura cancelada" (la Serie E cancelada que el Excel aún contaba) · **Diferencia sin explicar** (Conciliado/Revisar) ·
Nota: Monto − Cortes (enlace a Consumos por cliente).

**B. Cobros vs anticipos facturados** — Cobros prepago · Anticipos facturados · Diferencia · partidas: cobros aplicados a facturas
de anticipo de otro mes (tab 9, "¿Existe en Anticipos?" = No) · cobros sin factura en la referencia · anticipos facturados sin cobro aplicado ·
anticipos cancelados · Sin explicar.

**C. Cobranza vs banco** — como crédito: cobros agrupados por estatus del cruce, "% cuadrado", más la
**integración de depósitos 2791**: depósitos totales − aplicados a cobros prepago − aplicados a cobros de crédito − traspasos entre cuentas =
depósitos no identificados.

### Estatus por cliente (Desglose venta) — reglas, mi propuesta
Se evalúan en orden, con `dif = monto − facturado_mes_vigente`:
1. **No se factura**: consumo propio / bonificación.
2. **Facturado**: |dif| < 0.01.
3. **Redondeo**: |dif| < 1.00.
4. **Se factura al mes siguiente**: tiene facturas "Mes siguiente" que cubren la diferencia (diésel).
5. **Factura cancelada**: el cliente tiene Serie E cancelada en el mes y `dif` ≥ 1.
6. **Facturado con base en cortes**: facturado ≈ cortes y monto − cortes ≠ 0.
7. **Consumo sin corte**: cortes = 0 y facturado = 0.
8. **Consumo sin factura**: facturado = 0 y cortes > 0.
9. **Facturado de menos / de más**: resto, según el signo de `dif`.
(Orden real de `ConciliacionDebito::estatusCliente`. Excepción en 2 y 3: un cliente con monto ≈ 0, cortes > 0 y sin factura no se evalúa como Facturado/Redondeo y cae a Consumo sin factura.)
"Periodo" de una factura (mes anterior / mes siguiente) sale del texto de `txtref` ("Consumos de AGOSTO…", como
usa el Excel con `*AGOSTO*`); el mes sale de la nota `@N:Consumos <Mes> [Diesel] <año>` (verificado, §9).

## 7. Resultados de la validación (2026-11-08)

Septiembre 2026, DIAZ GAS (empresa 1), solo lectura: `php tools/conciliacion_debito_validar.php` (sale 0, "Todo ok").
Todas las líneas cuadran al centavo, salvo las diferencias explicadas de la tabla.

| Concepto | Sistema | Excel | Diferencia explicada |
|---|---|---|---|
| Venta (monto despachos) | $31,921,675.84 | $31,932,212.69 | −$10,536.85: un despacho de GOLDEN TRAVELER borrado después de extraer el Excel (33 vs 32 despachos) |
| Cortes | $31,918,014.67 | $31,918,014.67 | 0 |
| Clientes de venta | 774 | 774 | 0 |
| Serie E vigentes | 759 / $31,201,737.84 | — | 0 |
| Serie E canceladas (con importe) | 24 / $786,358.92 | Excel las seguía contando: vigentes + canceladas = $31,988,096.76 | partida "Factura cancelada"; 4 canceladas de $0 no cuentan |
| Serie E mes anterior | $185,444.95 | — | facturado del mes + mes anterior = vigentes ($31,201,737.84) |
| Anticipos | 1,871 / $32,591,067.05 | $32,599,567.05 (con $8,500 canceladas) | 2 canceladas conocidas (1200243922, 1200244351), canceladas el 2026-10-31 |
| Anticipos cancelados del mes | 60 | 2 | la BD marca 60 (cancelaciones re-emitidas en el mes); se listan en el tab 7 |
| Cobros prepago | 1,861 / $32,605,435.05 | igual | 0 |
| Cobros − anticipos | $14,368.00 | — | otro mes $17,868.00 (1200242940, 1200242934, 1200089958) + sin factura en referencia $10,000.00 − anticipos sin cobro $13,500.00 (1200244867, 1200244401) |
| Depósitos 2791 | 1,925 abonos / $37,229,115.24 | igual | 0 |
| Movimientos 0956 | 2,329 | igual | 0 |
| Cobros ligados a 2791 por el cruce | $31,485,663.43 | $32,007,985.13 | −$522,321.70 (ver limitación) |
| % cuadrado (monto ligado a banco / cobros no cancelados) | 97.25% | 98.17% (32,007,985.13 ligados a 2791 de 32,605,435.05) | 68 cobros "No localizado" = $867,903.62 |

- **Sin explicar**: card A = $0.00 con el Redondeo ($0.01, 1 cliente) mostrado como partida; card B = $0.00 con las partidas de arriba.
  El criterio original "Sin explicar = $0.00 en A y B" vale solo con esas partidas visibles (nada se oculta).
- **Criterio duro de cobros**: % cuadrado ≥ 97% (pasa con 97.25%). La comparación contra $32,007,985.13 es solo informativa.
- **Tiempos**: `payments/` ~4.5 s; `bank/` en frío 9.7–11.3 s (cruce prepago + crédito + clasificación); 0956 reutilizando el cruce ~4.7 s.
- **Limitación conocida**: el cruce cubre 97.25% y no el 98.17% que el Excel liga a 2791 (más 956, efectivo, etc. a mano) porque 68 cobros ("No localizado", $867,903.62) no tienen un depósito
  libre del mismo importe ±3 días en 2791/0956; el Excel los ligó a mano o agrupados. Las reglas del cruce no se cambiaron a propósito.
  Mejora futura sugerida: empatar por RFC del cliente y por sumas de subconjuntos por día.
- Prueba en navegador con el usuario pendiente (no se levanta el servidor PHP).

## 8. Arquitectura

- `_assets/models/ConciliacionDebitoModel.php` (hereda `Model`, tablas temporales + `OPTION (RECOMPILE)` como crédito; SG12 en compatibilidad 110: sin `STRING_SPLIT`, listas en línea saneadas). Reutiliza `ConciliacionVentaModel::depositos/movimientos_banco` y la clase de cruce `ConciliacionCobros` (sin BD) con cuentas 2791/0956.
- `_assets/classes/ConciliacionDebito.class.php`: lógica pura (estatus por cliente, periodo de factura, factura desde la Referencia) + `tests/ConciliacionDebitoTest.php` (sin framework, como `ConciliacionCobrosTest.php`).
- `income.php`: `conciliation_debit()` y `conciliation_debit_*` (ventas, consumos, propios, facturas, pendientes, anticipos, anticipos-clientes, cobros-factura, cobros, banco, detalle cliente).
- `routes/income.php`: rutas bajo `conciliation-debit/`, permiso 14.
- `views/income/conciliation_debit.html`.
- **Compartido:** el CSS `cv-*` de `conciliation.html` pasa a un parcial incluido por ambas vistas, y las funciones comunes (`initTabla`, `pintarChips`, formatos, ayuda, progreso) a `_assets/js/conciliation_common.js`. Es refactor de crédito: se verifica que agosto 2026 dé las mismas cifras antes y después.
- `views/layouts/sidebar.html`: renombrar y agregar el enlace.

## 9. Decisiones del usuario (2026-11-08: "vamos con tus recomendaciones")

1. **Serie E cancelada:** se sigue la regla de crédito. Las canceladas se excluyen del facturado y se muestran como partida "Factura cancelada" (24 / $786,358.92 en septiembre).
2. **Facturado por cliente**, como el Excel (la factura se emite con base en cortes). El enlace por despacho (`Despachos.nrofac`) se usa solo en "Despachos pendientes de facturar".

Verificado después de escribir el borrador (SELECT, sept-2026):
- Cortes de débito = `ValesR.codval = 127` (ya resuelto por `get_dispatches_summary_by_client(..., 4, ...)`; el valor 145 es "Promociones MKT" = consumo propio).
- Periodo de la factura Serie E: está en la nota del `txtref`: `@N:Consumos Agosto 2026@t:GLOBAL` / `@N:Consumos Agosto  Diesel 2026@t:GLOBAL@c:7767` (mes en español + año).
- Despachos de débito sept-2026: 26,942 con `nrofac` y 521 sin factura (todas las razones sociales).
- `get_invoice_series(1300…)` = 'E'; serie D = 12…, E = 13….

## 10. Decisiones mías (revisar)

- Tab Banco dividido en 2791 y 0956 (una hoja = un tab).
- Tab Consumos propios añadido; clasificación por catálogo `clientes_consumo_propio`.
- Estatus de §6 y su orden de evaluación.
- "dollar to go" ($11,200) se trata como consumo propio D2GO en el cruce de cobros.
- Razón social = Todas por defecto, igual que crédito.
- Sin commit en este paso: lo subes tú ("subir cambios"), como en crédito.

## 11. Plan de construcción (después de aprobar este documento → `writing-plans`)

0. Verificar con SELECT: formato de `txtref` para periodo, cortes de débito (`ValesR.codval`), `Despachos.nrofac` en tipval 4, cuentas propias para traspasos.
1. Parcial CSS + JS común; renombrar sidebar; comprobar crédito sin cambios.
2. Modelo + clase pura + tests (tabs 2–6).
3. Anticipos y cobros por factura (tabs 7–9).
4. Cobros y bancos (tabs 10–12) y card C.
5. Resumen y ayudas.
6. Validación CLI septiembre; navegador con el usuario; actualizar memoria y Notion.
