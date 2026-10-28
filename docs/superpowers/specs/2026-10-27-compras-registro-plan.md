# Registro de compras de combustible — plan para reemplazar COMPRAS 2026.xlsx

Fecha: 2026-10-27. Controlador `Compras`, ruta `/compras/registro/`, menú "Compras" en el sidebar (ya creado, placeholder).
Documentos previos: `docs/compras_combustible_documentacion.html`, `docs/plan_automatizar_compras_combustible.html`.

## 1. Qué es el Excel (releído 2026-10-27)

Hoja `COMPRAS` (Tabla2): 13,814 renglones, 64 columnas (A..BL), un renglón por factura. Desde sep-2026: 2,121 renglones.
Otras hojas: `PAGOS1.1`, `Deuda *` (pivots de deuda), `FALSOS FLETES`, `MORATORIOS`, `PENDIENTES`, `NOTAS DE CREDITO`, `DESTINOS MGC`, `GLOBAL`, `RESUMEN`, `ANEXOS` (pivots), `Matriz` (tablas IEPS y `NombEst`), `Estimulos`, `Costo Almacenaje`.

### Columnas de COMPRAS y de dónde saldría cada una

| Col | Columna Excel | Hoy | Fuente en el sistema |
|---|---|---|---|
| A | FECHA DE DESCARGA | tecleada | recepción de tanque (`MovimientosTan`); respaldo `FacturasRecibidas.Fecha` |
| B,C | MES, AÑO | fórmula/tecleada | derivadas de A |
| D | NUMERO DE ESTACION | tecleada | `FacturasRecibidas.EstacionCodgas` → `Estaciones` (Excel 11007 = `Estaciones.Estacion` "E11007") |
| E | PROVEEDOR | tecleada | `EmisorNombre` normalizado (catálogo `Proveedores`) |
| F,G,AO | CIUDAD, ZONA, ESTACIONES | VLOOKUP NombEst | `Estaciones` (Ciudad, Zona, Nombre) |
| H | PRODUCTO | tecleada | `FacturasRecibidasConceptos.Descripcion` |
| I | PAGO PROGRAMADO | tecleada (65% llena) | `payment_request_invoices.expiration_date` / solicitud de pago |
| J | Monto Pagado | fórmula | `payment_request_invoices.paid_amount` |
| K | FACTURA PROVEEDOR ORIGINAL | tecleada | `Folio` (con reglas de cruce: MGC quitar '3', AEMSA quitar '02-', Premier sin ceros) |
| L | PROVEEDOR FINAL QUE FACTURA | tecleada | proveedor real vs Petrotal (`FacturasMovimientosTanques`) |
| M | LITROS EN DOCUMENTOS SOPORTE | tecleada | `Conceptos.Cantidad` (Tesoro: BOL/carta porte) |
| N | MONTO FACTURA | tecleada | `Total` |
| O | VENCIMIENTO | tecleada ("CONTADO"/fecha) | emisión + `dias_credito`, día hábil |
| R | PRECIO COTIZADO | tecleada | **FUERA DE ALCANCE** (decisión 2026-10-14); sale vacío |
| U | PRECIO VENTA EN ESTACIÓN | tecleada | `TG.dbo.FuelPrices` (codgas, producto, fecha) |
| W | % IVA | tecleada | `Conceptos.TasaOCuota` |
| AB | PRECIO PEMEX AL 16 % (TAR) | tecleada | **FUERA DE ALCANCE**; vacío |
| AD,AE | SUBTOTAL/DESCUENTO TESORO | vacías | no se migran |
| AF | F/FACTURA PETROTAL (razón social) | tecleada | fase Petrotal |
| AG,AH,AI | FACTURA NUEVA, MONTO PETROTAL, % IVA cliente final | vacías en 2026 | `FacturasMovimientosTanques` (MontoPetrotal) en fase Petrotal |
| AJ | PRECIO/LITRO S/IVA PETROTAL A CLIENTE | tecleada | `FacturasMovimientosTanques.PrecioPetrotal` |
| AM | LITROS CAPTURADOS EN CONTROL GAS | fórmula engañosa | `DocumentosC.satuid` (real) |
| AP..AU | FLETE (factura, carta porte, total, transportista, razón social, fecha pago) | tecleadas (solo 6%) | `fuel_carriers`, `fuel_reception_schedule` + captura manual |
| AS | TRANSPORTISTA | tecleada | `fuel_reception_schedule.carrier_id` |
| AV | PLACAS | tecleada | captura en recepción / manual |
| AW | BOL NUMBER | tecleada | `recepcion_remisiones` / `FacturasRecibidas.Remision` |
| AX | RAZÓN SOCIAL FACTURA | tecleada | `ReceptorNombre` |
| BH | OBSERVACIONES | tecleada | campo libre |

**Calculadas** (no se capturan, se calculan en el modelo): P saldo, Q precio/litro factura, S diferencia vs cotizado (vacía sin R), T, V estímulo (solo Cd. Juárez, tabla `Estimulos`), X costo almacenamiento (`Costo Almacenaje`), Y/Z utilidad por litro, AA, AC, AK litros facturados, AL pendientes de facturar, AN pendientes de capturar en ControlGas, AY IEPS (tabla IEPS por producto: REGULAR 0.59139, PREMIUM 0.721605, DIESEL 0.490817, vigencia 2026), AZ, BA..BD precios con IVA 8 %, BE, BF, BG utilidad Petrotal.

Columnas BI..BL son basura (URLs); no se migran.

### Tablas de apoyo a migrar (hoy dentro del Excel)
- IEPS por producto y año, `Estimulos`, `Costo Almacenaje` → tablas paramétricas con vigencia (nueva `compras_parametros`).
- `NombEst` → ya existe en `Estaciones`.

## 2. Datos verificados en BD (2026-10-27, solo SELECT)

- `TG.dbo.FacturasRecibidas`: Id, Folio, Serie, Fecha, Total, SubTotal, EmisorNombre/Rfc, ReceptorNombre/Rfc, UUID, Destino, Remision, EstacionCodgas, OrigenImportacion, RutaArchivo/RutaXml…; conceptos en `FacturasRecibidasConceptos` (FacturaId, Cantidad, Descripcion, ValorUnitario, Importe, TasaOCuota).
- Septiembre 2026: 1,691 facturas tipo I (el Excel tiene ~1,598 renglones de compras; el resto son fletes/otros).
- `payment_request_invoices` (uuid, amount, paid_amount, expiration_date, status), `FacturasMovimientosTanques` (par factura proveedor/Petrotal por recepción), `fuel_reception_schedule`, `fuel_carriers`, `recepcion_remisiones`, `FuelPrices`.
- `Estaciones.Estacion` = "E"+número del Excel.

## 3. Fases de construcción

Cada fase termina usable y se prueba contra el Excel de septiembre/octubre (cruce renglón a renglón).

**Fase 1 — Lista base (solo lectura).**
`ComprasRegistroModel::listar($desde,$hasta,$filtros)` arma un renglón por factura tipo I desde `FacturasRecibidas` + conceptos + `Estaciones` + `payment_request_invoices` + `DocumentosC`. Filtros mes/proveedor/estación/producto. Columnas: A,B,C,D,E,F,G,H,I,J,K,M,N,O,P,Q,AM,AN,AO,AX. Paginación por servidor, columnas fijas (fecha, estación, folio). Archivos: `_assets/models/ComprasRegistroModel.php`, `views/compras/registro.html`, `_assets/js/compras.js`, ruta `compras.registro_datos`.
Validación: totales de septiembre vs Excel (renglones, litros, monto).

**Fase 2 — Captura manual y renglones manuales.**
Tabla `compras_factura_datos` (PK uuid; observaciones, pago programado manual, placas, BOL, transportista, correcciones de estación/producto, flag `manual`) y `compras_renglon_manual` para lo que no llega al buzón (Repsol/Colosio). Edición en celda con `escHtml`, auditoría de cambios (patrón `arqueo_audit_log`). Permisos propios (a definir con el usuario; 106-110 ya están usados por Pagos extraordinarios).

**Fase 3 — Columnas calculadas.**
`compras_parametros` (IEPS, estímulo, almacenaje, con vigencia) y `ComprasCalculos` (PHP puro, probado contra el Excel). Columnas P,Q,V,X,Y,Z,AA,AY..BG. Validación: diferencia = 0 contra las fórmulas del Excel en septiembre.

**Fase 4 — Petrotal.**
Columnas L,AF,AH,AJ,BF,BG desde `FacturasMovimientosTanques` + facturas Petrotal (ya se importan desde 2026-09-01). Requiere que Abastos use la conciliación (hoy es manual).

**Fase 5 — Transporte y fletes.**
AP..AW desde Programación de recepciones y Mis Recepciones; fletes capturados a mano donde no haya fuente.

**Fase 6 — Vistas de columnas y exportación.**
Grupos con interruptores (Factura, Pago, Transporte, Petrotal, Fletes, Análisis) + vistas guardadas (Captura, Pagos, Completa) por usuario; export `.xlsx` con las columnas A..BH en el mismo orden (PhpSpreadsheet).

**Fase 7 — Hojas secundarias como reportes.**
Pivots (`GLOBAL`, `RESUMEN`, `ANEXOS`, deuda por zona), `FALSOS FLETES`, `MORATORIOS`, `NOTAS DE CREDITO`, `PENDIENTES`: se evalúa uno por uno cuáles ya existen en el sistema (Estado Facturas, notas de crédito, etc.) y cuáles faltan.

**Fase 8 — Retiro del Excel.** Corrida en paralelo un mes completo; cuando cuadre, se archiva el Excel.

## 4. Preguntas abiertas para el usuario / Abastos
1. ¿Qué columnas teclea el empleado cada día? (define la vista Captura; la lista de arriba sale del análisis del Excel).
2. ¿Las remisiones (sin UUID) entran al registro?
3. ¿Permiso propio para Compras o se queda en 32 (Abastos)?
4. Origen de los fletes (AP..AU) y de las placas: ¿se capturan en la recepción o solo en este módulo?
5. ¿Se migra el histórico (13,814 renglones desde 2024) o solo 2026 en adelante?

## 5. Reglas a respetar
- Vencimiento siempre emisión + días de crédito a día hábil; nunca `fechaVto`.
- No filtrar por `satuid`; documentos sin satuid son remisiones.
- Cruce de folios: MGC (quitar '3' si 7 díg.), AEMSA (quitar '02-'), Premier (sin ceros a la izquierda).
- `fch` de ControlGas es serial de Excel (`dateToInt()`).
- Actualizar `docs/compras_combustible_documentacion.html` al terminar cada fase.

## 6. Decisiones del usuario (2026-10-28) y avance

- Lo que no salga del PDF/XML se deja **capturable** (Fase 2). Las remisiones que están en el Excel se incluyen.
- Permisos propios: 112 (ver registro) y 113 (capturar/editar), script `docs/sql/compras_permisos.sql` (**pendiente de correr**; sin él nadie ve el menú).
- Fletes y placas se capturan en este módulo.
- Alcance inicial de datos: agosto, septiembre y octubre de 2026.

**Fase 1 — hecha en código, falta probar en navegador y correr el script de permisos.**
`ComprasRegistroModel::listar()`, `Compras::registro()` / `registro_datos()`, `views/compras/registro.html`, `_assets/js/compras.js`, `_assets/css/compras.css`, `routes/compras.php`.
Septiembre: 1,670 facturas de combustible, 29.29 M litros, $627.5 M; 327 sin estación, 94 sin ControlGas, 1,542 en pagos (consulta ~1 s).
Se detectó que `SG12.dbo.documentosC` no tiene índice por `satuid` (5.6 M filas): la consulta lo une una sola vez filtrando por `fch`; no usar un EXISTS por renglón (67 s).

**Excel releído 2026-10-28** (archivo del 28-oct 08:59): mismas 23 hojas y 64 columnas con los mismos encabezados; solo creció la data (14,590 renglones; octubre 1,297). No cambia el diseño.

**Fase 2 — hecha en código, falta correr `docs/sql/compras_captura_schema.sql` y probar en navegador.**
- Tablas `compras_factura_datos` (UUID), `compras_renglon_manual`, `compras_audit_log`. Mientras no existan, la pantalla funciona en solo lectura y avisa (`capturaDisponible()`).
- Captura en celda (clic): estación, fecha de descarga, pago programado, placas, BOL, transportista y observaciones; la estación y el pago programado capturados ganan sobre los del sistema.
- Renglones manuales (botón "Agregar renglón manual"): todos sus campos editables en celda, eliminación lógica.
- Endpoints POST `compras/guardar-celda/`, `compras/renglon-manual/crear/` y `/eliminar/` (permiso 113) con auditoría.

**Perfiles de columnas + todas las columnas del Excel (2026-10-28).**
- La pantalla muestra las 60 columnas A..BH del Excel (más fecha factura, remisión, en pagos, en ControlGas y origen). Perfiles: Captura, Pagos, Análisis, IEPS, Petrotal, Tesoro, ControlGas, Fletes y Completa; el perfil se recuerda por navegador (`localStorage`) y el botón "Columnas" sigue permitiendo ajustes.
- Las fórmulas se portaron a `_assets/classes/ComprasCalculos.class.php` (prueba: `php tests/ComprasCalculosTest.php`, 29 casos con valores reales del Excel). Parámetros IEPS 2025/2026, estímulo y almacenaje 2026 dentro de la clase.
- El precio de venta (U) sale de `FuelPrices` (codprd 179/192 regular, 180/193 super, 181 diésel); el producto usa SUPER como el Excel.
- Columnas de captura nuevas (Petrotal, Tesoro, fletes, proveedor final, litros de soporte): script `docs/sql/compras_captura_columnas.sql` (**pendiente de correr**). Sin él esas celdas se muestran pero no se pueden guardar.
- Cruce con el Excel (sep–oct, 2,841 renglones): IVA 100 %, IEPS/precio con IVA 8 %/monto sin IVA ≥99.9 %, estímulo 99.6 %, costo de almacenamiento 96.9 %, utilidad por litro 94 %. Las diferencias vienen de: proveedor final = PETROTAL capturado a mano en el Excel (89 renglones Tesoro), precio de venta tecleado distinto al de `FuelPrices` (81) y estaciones sin identificar.
- Precio cotizado (R) y TAR Pemex (AB) siguen fuera de alcance: columnas vacías, igual que S, T y AC.
