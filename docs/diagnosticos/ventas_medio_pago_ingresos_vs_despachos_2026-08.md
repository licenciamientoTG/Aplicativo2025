# Venta por estación mensual — Ingresos vs Despachos (agosto 2026)

Contexto: las cards de `/commercial/sale_type_payment` (tab Venta por estación mensual)
toman el **monto** de `SG12.dbo.Ingresos` (`VentasModel::getMounthEstationPayment`) y el
**# de transacciones** de `SG12.dbo.Despachos` + `MovimientosTar` (`VentasModel::getMounthEstationEventos`).
Este análisis mide qué tanto coincide el reparto por medio de pago entre ambas fuentes.

- Periodo: 2026-08-01 a 2026-08-31, todas las estaciones. Corrido 2026-09-23 con sqlcmd (solo lectura).
- **% mal clasificado** = Σ|Despachos − Ingresos| por medio / 2 / Total Ingresos → porcentaje de la venta
  que Despachos pone en un medio distinto al de Ingresos.
- **Actual** = CASE en producción hoy. **Corregido** = mapeo propuesto (ver abajo), 1 fila por despacho, sin jarreo.
- Diferencias por medio en la tabla = Despachos − Ingresos, ya con mapeo corregido (pesos).

## Correcciones APLICADAS (2026-09-24, `VentasModel.php`)

`getMounthEstationEventos` (Transacc.):
- 1 fila por despacho: `MovimientosTar` con `ROW_NUMBER() OVER (PARTITION BY codgas, nrotrn ORDER BY mto DESC)`, `rn = 1`
  (si se pagó con varios valores gana el de mayor monto), sin `tipmov` 86/97 (cancelación/reverso), `fchmov` ±1 día.
- `HTI - Efectivo`, `INTERL - Efectivo` → EFECTIVO.
- `tiptrn` 51/52 sin voucher: cliente **no existe** en Clientes → TARJETAS; cliente **existe** (contado, p.ej.
  "PAGO EN EFECTIVO CHOFERES DE PLATAFORMA", "PAGO EN EFECTIVO MOTO UNION", "PUBLICO EN GENERAL") → EFECTIVO.
  La regla original "51/52 sin valor → TARJETAS" mandaba a esos clientes contado a Tarjetas.
- `tiptrn` 53 sin voucher → VALERAS.
- `tiptrn` 74 (jarreo) excluido.

`getMounthEstationPayment` (monto de la card):
- `INTERL - Efectivo` → EFECTIVO. En Clara sí es concepto de Ingresos (145k); moverlo solo en Despachos la descuadraba.
  `HTI - Efectivo` no existe en Ingresos (el corte lo captura como Efectivo MN).

Decisiones:
- La card se queda con monto de Ingresos + Transacc. de Despachos. No se busca cuadrar el monto de Despachos:
  la diferencia restante en las estaciones 🟢 son **pagos mixtos** (despacho pagado parte tarjeta, parte efectivo),
  que no afectan el conteo. Verificado en Lerdo: SMARTBT con monto del voucher = Ingresos al centavo.

Segunda ronda (2026-09-24, revisión de todo ene–sep 2026), en **ambos** queries:
- → TARJETAS: `HTI - Tarjeta American Express` (3.48M en 2026), `INTERL - Tarjeta American Express`,
  ` Banca MiFel` (terminal bancaria), ` Tarjeta Inbursa` (bancaria).
- → VALERAS: ` GASnGO MEXICO` (1.42M en 2026, Travel Center/Picachos).
- Solo Despachos: `tiptrn 65` excluido junto con 74 (muestras de turno, igual que `DespachosModel`);
  `tiptrn 50` sin voucher → EFECTIVO (cheque, `datref` `@P:02`).
- Se quedan en OTRO a propósito: `Promociones MKT`, ` TRIBU Rewards`, `Servigas 16 Pesos`, y `tiptrn` 56/48/54/44
  sin voucher (~63k en 2026, significado desconocido).
- `datref` `@P:xx` coincide 1 a 1 con `tiptrn` (01=efectivo/49, 04=crédito/51, 28=débito/52, 05=monedero/53,
  02=cheque/50): no aporta información extra. `tiptrn 49` = efectivo declarado; `tiptrn 0` = sin método capturado.

Vista: el tab ahora tiene sub-tabs Ingresos / Despachos / Comparativa / Detalle (ya no se pierden medios que solo
existen en Despachos: la Comparativa une ambos lados).

## Estado por estación tras correcciones (2026-09-24)

Diferencias = Despachos − Ingresos (pesos). Crédito/Débito cuadran salvo donde se indica.

| Nivel | Estación | % mal clas. | Transacc. | Efectivo | Tarjetas | Valeras | Causa |
|---|---|---|---|---|---|---|---|
| 🔴 | 29 Villa Ahumada | 27.7 | 31,018 | +4,848,511 | −2,572,720 | −2,272,769 | Terminales no integradas (Santander, Banorte, EfectiCard, TicketCar, TicketCar+, Ultra Gas, Efectivale, Sodexo) |
| 🔴 | 37 Gabriela Mistral | 23.4 | 22,341 | +1,411,033 | −1,376,772 | −7,696 | Banorte no integrada (1.55M en corte, 8k con voucher); Banca MiFel 27.5k sin medio |
| 🔴 | 23 Las Fuentes | 22.7 | 24,865 | +2,443,938 | −801,058 | −1,642,329 | TicketCar, Santander, EfectiCard, TicketCar+, AmEx, Sodexo no integradas |
| 🟠 | 34 San Rafael | 8.8 | 20,497 | −673,114 | −34,486 | −2,997 | Cliente "(NO UTILIZAR) BIO COMBUSTIBLE" (Crédito +709k), dato de ControlGas |
| 🟠 | 22 Satélite | 6.1 | 46,692 | +1,319,187 | −465,276 | −846,529 | Valeras/tarjetas integración parcial; TicketCar+ no integrada |
| 🟠 | 21 Ejército Nacional | 5.6 | 37,689 | +943,895 | −369,016 | −573,866 | Santander, TicketCar+, EfectiCard, TicketCar no integradas |
| 🟠 | 03 Delicias | 5.3 | 11,432 | +220,223 | −104,945 | −115,059 | Santander, EfectiCard, TicketCar, AmEx no integradas |
| 🟡 | 25 Solís | 3.0 | 48,083 | +361,707 | −313,991 | −46,603 | Integración parcial de tarjetas |
| 🟡 | 31 Travel Center | 3.0 | 20,201 | +821,574 | −708,934 | +162,685 | INTERLOGIC/SMARTBT Manual no integradas; GASnGO 222k sin medio; 63 AmEx en Otro |
| 🟡 | 24 Clara | 1.9 | 7,577 | +86,536 | −58,911 | −24,546 | Sodexo, Inburgas no integradas |
| 🟡 | 27 Jarudo | 1.3 | 20,594 | +135,207 | −27,934 | −104,874 | Valeras parcialmente integradas |
| 🟡 | 30 El Castaño | 1.3 | 18,736 | +124,911 | −40,083 | −34,338 | Santander, EfectiCard no integradas |
| 🟡 | 26 Santiago Troncoso | 1.3 | 47,006 | +170,474 | −77,620 | −92,411 | TicketCar, TicketCar+ no integradas |
| 🟢 | 36 Jesús María | 0.9 | 20,247 | +49,659 | −49,028 | +38 | Pagos mixtos |
| 🟢 | 33 Ventanas | 0.9 | 42,205 | +127,203 | −116,969 | −25,719 | Pagos mixtos / Santander |
| 🟢 | 32 Picachos | 0.5 | 38,701 | +90,519 | −84,367 | −9,299 | Pagos mixtos |
| 🟢 | 02 Lerdo | 0.3 | 27,931 | −26,865 | +26,364 | +779 | Pagos mixtos (revisado a detalle) |
| 🟢 | 04 Parral | 0.2 | 19,081 | +10,165 | −7,499 | −4,939 | Pagos mixtos |
| 🟢 | 08 Plutarco | 0.2 | 34,588 | −21,311 | +24,165 | −2,583 | Pagos mixtos |
| 🟢 | 35 Puertecito | 0.2 | 28,428 | +13,920 | −13,320 | −600 | Pagos mixtos |
| 🟢 | 10 Aztecas | 0.2 | 79,403 | −50,899 | +52,762 | −898 | Pagos mixtos |
| 🟢 | 17 Custodia | 0.2 | 50,255 | −26,141 | +27,352 | +609 | Pagos mixtos |
| 🟢 | 09 Municipio Libre | 0.2 | 67,461 | −38,313 | +38,361 | +300 | Pagos mixtos |
| 🟢 | 20 Tecnológico | 0.2 | 27,579 | +18,019 | −988 | −17,255 | Pagos mixtos |
| 🟢 | 18 Anapra | 0.2 | 64,650 | −34,331 | +38,430 | 0 | Pagos mixtos |
| 🟢 | 05 López Mateos | 0.1 | 29,818 | −16,897 | +16,844 | −11 | Pagos mixtos |
| 🟢 | 12 Puerto de Palos | 0.1 | 69,787 | −29,920 | +30,916 | −496 | Pagos mixtos |
| 🟢 | 11 Misiones | 0.1 | 40,718 | +24,605 | −20,414 | −3,616 | Pagos mixtos |
| 🟢 | 06 Gemela Chica | 0.1 | 38,225 | −27,553 | +27,669 | +124 | Pagos mixtos |
| 🟢 | 19 Aguascalientes | 0.1 | 24,460 | −13,508 | +12,800 | 0 | Pagos mixtos |
| 🟢 | 16 Aeronáutica | 0.1 | 49,371 | −21,988 | +22,408 | 0 | Pagos mixtos |
| 🟢 | 13 Miguel de la Madrid | 0.1 | 60,650 | +16,722 | −2,241 | −14,997 | Pagos mixtos |
| 🟢 | 14 Permuta | 0.1 | 52,625 | −16,201 | +21,412 | 0 | Pagos mixtos |
| 🟢 | 07 Gemela Grande | 0.1 | 30,964 | −10,828 | +10,749 | 0 | Pagos mixtos |
| 🟢 | 15 Electrolux | 0.0 | 72,204 | +11,934 | +1,647 | −7,244 | Pagos mixtos |
| 🟢 | 28 Hermanos Escobar | 0.0 | 33,211 | +559 | −1,158 | −1,758 | Pagos mixtos |

## Detalle: 29 Villa Ahumada (codgas 31) — 2026-09-24

- Solo SMARTBT (Bancarias + AmEx) está integrada y cuadra al centavo con voucher. Crédito/Débito cuadran.
- No integradas (4.95M = 28% de la venta): Santander 2,201,724 · EfectiCard 779,579 · TicketCar 633,424 ·
  Banorte 439,280 · TicketCar+ 340,182 · Ultra Gas 301,411 · Efectivale 145,200 · Sodexo 92,050 · SMARTBT Manual 16,840.
- Ingresos solo guarda totales por día/turno/isla (no por voucher) → no hay match 1 a 1 posible.
- **Búsqueda de patrón** (despachos contado sin voucher, 45 campos de Despachos; grupos: turnos/isla sin cobros
  no integrados vs con >50%; Lerdo como perfil de efectivo puro):
  - Ningún campo distingue: `logmsk`, `codcli`, `nrofac`/`gasfac`, `satuid`, `datref` (100% en todos, formato `@Q:…@$:…`),
    `nroveh`/`tar`/`odm`/`rut`/`cho` vacíos en todos.
  - Correlación por turno/isla (380) entre monto no integrado y cada campo, **controlando volumen** (proporciones):
    r ≤ 0.14 para todos (tiptrn 49, nrocte, ticket ≥1000, no redondo, graprd…). Sin controlar, todo da ~0.8 (efecto tamaño de turno).
  - `tiptrn 49` = siempre con `nrocte`, ticket alto (~1,200–2,500), pero aparece también en turnos sin cobros no
    integrados (702k de 1.40M) → es contado, no marca de tarjeta/vale.
  - **Conclusión: Despachos no contiene la información.** Solución operativa: integrar terminales o que el despachador
    marque el tipo de pago. Script: `villa_ahumada_patrones_2026-08.sql`.

## Análisis original 2026-09-23 (antes de correcciones, % mal clasificado actual → corregido propuesto)

| Nivel | Estación (codgas) | Total Ing. | Tot. Δ% | Transacc. | % mal clas. actual → corr. | Crédito | Débito | Efectivo | Tarjetas | Valeras | Otro | Causa principal |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 🔴 | 29 Villa Ahumada (31) | 17,482,454 | 0.05 | 31,018 | 28.3 → **27.7** | 0 | 0 | +4,848,511 | −2,572,720 | −2,272,769 | 0 | Terminales no integradas: Santander 2.20M, Banorte 439k, EfectiCard 780k, TicketCar 633k, TicketCar+ 340k, Ultra Gas 301k, Efectivale 145k, Sodexo 92k |
| 🔴 | 23 Las Fuentes (25) | 10,788,366 | 0.06 | 24,865 | 22.7 → **21.9** | 0 | +1,610 | +2,362,569 | −719,689 | −1,642,329 | 0 | No integradas: TicketCar 934k, Santander 679k, EfectiCard 394k, TicketCar+ 190k, AmEx 123k, Sodexo 97k |
| 🔴 | 37 Gabriela Mistral (39) | 5,998,081 | 1.52 | 22,341 | 26.7 → **19.8** | 0 | 0 | +1,195,523 | −1,161,262 | −7,696 | −11,783 | Tarjetas cobradas que en Despachos quedan como contado (798 tiptrn 51/52 sin valor, ya corregidos); resto por investigar. Banca MiFel 27.5k |
| 🟠 | 34 San Rafael (36) | 8,079,731 | 0.45 | 20,497 | 15.7 → **8.8** | +709,057 | 0 | −673,114 | −34,486 | −2,997 | +1,541 | Cliente **(NO UTILIZAR) BIO COMBUSTIBLE DEL NORTE** (tipval 3): 2,345 despachos contado cargados a cliente crédito. Dato de ControlGas. HTI-Efectivo 487k ya corregido |
| 🟠 | 22 Satélite (24) | 21,546,011 | 0.06 | 46,692 | 6.2 → **6.0** | 0 | +600 | +1,295,327 | −441,416 | −846,529 | +150 | Valeras/tarjetas con integración parcial (sí aparecen en MovimientosTar pero no todas); TicketCar+ 96k no integrada |
| 🟠 | 03 Delicias (19) | 4,160,433 | 0.01 | 11,432 | 9.5 → **5.3** | 0 | 0 | +220,223 | −104,945 | −115,059 | 0 | No integradas: Santander 90k, EfectiCard 53k, TicketCar 45k, TicketCar+ 16k, AmEx 15k. HTI-Efectivo 394k ya corregido |
| 🟠 | 21 Ejército Nacional (23) | 16,731,097 | 0.04 | 37,689 | 5.6 → **5.2** | 0 | 0 | +878,515 | −303,636 | −573,866 | 0 | No integradas: Santander 386k, TicketCar+ 215k, EfectiCard 172k, TicketCar 146k, Ultra Gas 28k |
| 🟡 | 31 Travel Center (33) | 32,068,902 | 0.87 | 20,201 | 10.1 → **2.9** | −717 | −22,782 | +766,842 | −657,230 | +165,712 | −221,952 | HTI-Efectivo 3.63M ya corregido. Resta: INTERLOGIC Manual 206k y SMARTBT Manual 33k no integradas; GASnGO 222k sin medio |
| 🟡 | 25 Solís (27) | 11,949,083 | 0.05 | 48,083 | 3.0 → **2.8** | 0 | −1,400 | +338,428 | −290,712 | −46,603 | +900 | Integración parcial de tarjetas |
| 🟡 | 24 Clara (26) | 4,538,761 | 0.63 | 7,577 | 2.2 → **1.5** | 0 | 0 | +69,223 | −41,598 | −24,546 | 0 | Sodexo 12k, Inburgas 11k no integradas; 107 jarreos |
| 🟡 | 30 El Castaño (32) | 7,767,234 | 1.52 | 18,736 | 1.6 → **1.3** | 0 | 0 | +124,616 | −39,788 | −34,338 | +6,327 | Santander 182k, EfectiCard 168k no integradas; 174 jarreos |
| 🟡 | 27 Jarudo (29) | 10,170,235 | 0.47 | 20,594 | 1.5 → **1.3** | 0 | −256 | +129,118 | −21,845 | −104,874 | +1,284 | Valeras parcialmente integradas |
| 🟡 | 26 Santiago Troncoso (28) | 13,397,804 | 0.02 | 47,006 | 1.3 → **1.1** | −423 | 0 | +150,127 | −57,273 | −92,411 | +200 | TicketCar 49k, TicketCar+ 24k no integradas |
| 🟢 | 36 Jesús María (38) | 5,291,004 | 0.47 | 20,247 | 5.8 → 0.9 | 0 | −800 | +49,659 | −49,028 | +38 | +240 | HTI-Efectivo 297k ya corregido |
| 🟢 | 33 Ventanas (35) | 15,741,119 | 0.14 | 42,205 | 1.4 → 0.8 | 0 | +3,800 | +115,092 | −104,858 | −25,719 | +11,205 | Santander 185k, EfectiCard 40k no integradas |
| 🟢 | 02 Lerdo (5) | 8,996,615 | 0.09 | 27,931 | 0.7 → 0.6 | 0 | 0 | −56,647 | +56,146 | +779 | 0 | Pagos mixtos (tarjeta+efectivo) |
| 🟢 | 32 Picachos (34) | 20,846,588 | 0.15 | 38,701 | 1.7 → 0.4 | 0 | +3,779 | +81,830 | −75,678 | −9,299 | 0 | Santander 87k no integrada; 412 tiptrn sin valor ya corregidos |
| 🟢 | 10 Aztecas (9) | 28,318,374 | 0.07 | 79,403 | 0.4 → 0.4 | 0 | 0 | −98,702 | +100,564 | −898 | +485 | Pagos mixtos |
| 🟢 | 17 Custodia (16) | 14,983,846 | 0.07 | 50,255 | 0.5 → 0.4 | 0 | 0 | −63,659 | +64,870 | +609 | +45 | Pagos mixtos |
| 🟢 | 18 Anapra (17) | 24,034,024 | 0.07 | 64,650 | 0.4 → 0.3 | 0 | 0 | −80,026 | +84,125 | 0 | 0 | Pagos mixtos |
| 🟢 | 12 Puerto de Palos (11) | 23,841,588 | 0.13 | 69,787 | 0.4 → 0.3 | 0 | 0 | −70,609 | +71,604 | −496 | +250 | Pagos mixtos |
| 🟢 | 08 Plutarco (21) | 12,396,075 | 0.05 | 34,588 | 0.3 → 0.3 | 0 | 0 | −33,617 | +36,470 | −2,583 | 0 | Pagos mixtos |
| 🟢 | 09 Municipio Libre (8) | 22,669,665 | 0.03 | 67,461 | 0.3 → 0.3 | 0 | 0 | −61,577 | +61,625 | +300 | +500 | Pagos mixtos |
| 🟢 | 05 López Mateos (6) | 11,989,956 | 0.14 | 29,818 | 0.3 → 0.3 | 0 | 0 | −30,038 | +29,985 | −11 | +150 | Pagos mixtos |
| 🟢 | 06 Gemela Chica (7) | 25,300,764 | 0.23 | 38,225 | 0.3 → 0.2 | 0 | 0 | −39,418 | +39,535 | +124 | 0 | Pagos mixtos |
| 🟢 | 04 Parral (18) | 7,178,617 | 0.21 | 19,081 | 7.5 → 0.2 | 0 | +4,000 | +9,141 | −7,999 | −3,415 | +2,420 | HTI-Efectivo 531k ya corregido |
| 🟢 | 14 Permuta (13) | 24,954,154 | 0.07 | 52,625 | 0.3 → 0.2 | 0 | 0 | −55,605 | +60,816 | 0 | +193 | Pagos mixtos |
| 🟢 | 19 Aguascalientes (3) | 13,350,140 | 0.50 | 24,460 | 0.4 → 0.2 | 0 | +1,466 | −24,611 | +23,903 | 0 | 0 | Pagos mixtos |
| 🟢 | 35 Puertecito (37) | 7,391,505 | 0.31 | 28,428 | 6.1 → 0.2 | 0 | 0 | +13,920 | −13,320 | −600 | 0 | HTI-Efectivo 434k ya corregido |
| 🟢 | 16 Aeronáutica (15) | 25,648,564 | 0.06 | 49,371 | 0.2 → 0.2 | 0 | 0 | −42,913 | +43,332 | 0 | +752 | Pagos mixtos |
| 🟢 | 28 Hermanos Escobar (30) | 13,208,860 | 0.06 | 33,211 | 0.2 → 0.1 | 0 | +600 | −16,255 | +15,656 | −1,758 | +1,648 | Pagos mixtos |
| 🟢 | 20 Tecnológico (22) | 11,143,639 | 0.99 | 27,579 | 5.0 → 0.1 | 0 | 0 | +7,969 | −988 | −7,206 | +948 | HTI-Efectivo 642k ya corregido |
| 🟢 | 15 Electrolux (14) | 36,150,346 | 0.05 | 72,204 | 0.2 → 0.1 | −4,029 | 0 | −41,086 | +54,666 | −7,243 | 0 | Pagos mixtos |
| 🟢 | 07 Gemela Grande (2) | 21,279,846 | 0.05 | 30,964 | 0.2 → 0.1 | −1,146 | +2,922 | −27,197 | +27,118 | 0 | −1,000 | Pagos mixtos; cliente débito "(NO UTILIZAR)" 1 despacho |
| 🟢 | 11 Misiones (10) | 19,598,452 | 0.78 | 40,718 | 4.7 → 0.1 | 0 | 0 | +20,709 | −16,518 | −3,616 | +300 | HTI-Efectivo 992k ya corregido |
| 🟢 | 13 Miguel de la Madrid (12) | 21,432,935 | 1.82 | 60,650 | 4.6 → 0.0 | 0 | 0 | +5,940 | −1,741 | −4,714 | +1,400 | HTI-Efectivo 1.18M ya corregido |

Niveles: 🔴 >15% · 🟠 5–15% · 🟡 1–5% · 🟢 <1% (con mapeo corregido).

## Hallazgos transversales
1. **Total por estación cuadra** (0.01%–1.82%): ambas tablas cubren el mismo universo de venta.
2. **Crédito/Débito cuadran** en todas salvo San Rafael (cliente "NO UTILIZAR") y centavos/ajustes menores.
3. **Pagos mixtos** (patrón 🟢: Efectivo −X / Tarjetas +X casi simétrico): un despacho pagado parte tarjeta y
   parte efectivo cuenta completo como Tarjeta en Despachos. Afecta el monto, no el conteo; en las cards el monto viene de Ingresos.
4. **Terminales no integradas**: valores cobrados fuera de ControlGas (Tarjetas Santander, EfectiCard, TicketCar,
   Ultra Gas, Transferencias…) se capturan en Ingresos al corte, pero el despacho queda como contado → Despachos no
   puede saber el medio. Es la causa de las 3 🔴 y de casi todas las 🟠/🟡.
5. **Dólares**: Ingresos DOLARES > monto de los despachos pagados en USD (ej. Lerdo 785k vs 429k) porque el cambio se da en MN.
   No afecta totales ni conteo.
6. **Jarreos (tiptrn 74)**: 1,330 despachos / 363k en el mes; más en Gabriela Mistral (254), Picachos (174), Clara (107).
7. **Duplicados por varias MovimientosTar**: 2,721 despachos; más en Miguel de la Madrid (1,197), Misiones (499), Tecnológico (378), Travel Center (273).

## Scripts
`ventas_medio_pago_ingresos_vs_despachos_2026-08.sql` (junto a este archivo): actual vs corregido por estación, factores (R2), valores no integrados (R3), dólares (R4), clientes "NO UTILIZAR" (R5). Cambiar las fechas en los DECLARE para otro mes.
