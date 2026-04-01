# Formato de Requerimiento de Usuario (F.R.U.)

| Campo | Detalle |
|---|---|
| **Fecha** | 31/03/2026 |
| **Requerimiento** | Fase 2 – Conciliación de Valeras |
| **Solicitante** | Maribel García |
| **Puesto** | Directora de Administración y Finanzas |
| **Área / Departamento** | Administración y Finanzas |
| **Jefe inmediato** | — |

---

## Historias de Usuario (Funcionalidades)

### Nombre del Requerimiento

Módulo de conciliación de valeras: carga, normalización y conciliación por proveedor (TicketCard, Inburgas, Efecticard, Ultragas, Pluxee/Sodexo, MIFIEL, BBVA), agrupado por razón social.

---

### Descripción de la Necesidad y/o Requerimiento

La Fase 2 extiende el módulo de conciliación para integrar las **valeras** (tarjetas de pago corporativas) como fuente adicional de ingresos. Cada proveedor de valeras entrega información en formatos y portales distintos, con lógicas de pago y conciliación propias. El sistema debe permitir la carga de reportes por proveedor, normalizarlos y contrastarlos contra las ventas registradas en ControlGas, agrupadas por razón social.

#### Razones Sociales y sus Proveedores

| Razón Social | Banco | Valeras |
|---|---|---|
| **COLOSIO** | Santander | TicketCard, Efecticard |
| **PRAXEDIS** | Santander | Efecticard, Ultragas (vía Nexus) |
| **Gasomex / Ejército / Fuentes / Santiago** | AMEX | Comparten 3 cuentas bancarias |
| **Gabriela Mistral** | — | MIFIEL (solo afiliación y tesorería) |
| **General** | BBVA | Conciliación CG vs depósitos |

**Nota COLOSIO:** Los cortes de turno se reciben manualmente, se suman y se contrastan con Tesorería y el portal Santander. Los datos de TicketCard y Efecticard vienen en el mismo archivo de valeras.

**Nota PRAXEDIS:** Utiliza el portal Nexus; el reporte de formas de pago (tarjetas, tipo consumos detallados) se descarga en Excel y se usa como fuente para la conciliación.

**Nota AMEX (Gasomex/Ejército/Fuentes/Santiago):** Las tres estaciones comparten sus tres cuentas, por lo que se requiere entrada manual de los tres estados de cuenta para que la tabla dinámica pueda asignar correctamente cada depósito.

#### Proveedores de Valeras a Implementar

| Proveedor | Método | Fuente de datos | Particularidades |
|---|---|---|---|
| **TicketCard** | Tx vs Tx | Portal TC, TC Plus, portal En Punto | Diferencias por TC Plus/Más Plus; estaciones sin afiliación caen en Municipio (5317); filtrar solo gasolinas |
| **Inburgas** | Tx vs Tx | Portal Inburgas (descarga por estación) | Pago irregular (día siguiente a semanas); requiere asignar promotor/estación manualmente desde el concepto del depósito |
| **Efecticard** | Sumatoria mensual por razón social | Portal de facturación (PDF/XML, numeración PF…) | Fecha = fecha de factura, **no** fecha de venta; sin portal de transacciones; validar: reembolso + comisión + IVA = monto venta |
| **Ultragas** | Sumatoria + detalle | Portal Ultragas / Ultragas MobileFleet | Credencial = número de estación; reportes XLS/CSV; distinguir Ultra AGS de MobileFleet |
| **Pluxee / Sodexo** | Tx vs Tx | Portal En Punto | Una sola cuenta; afiliaciones por estación; algunas estaciones de Fluxi (Villahumada, Castaño) se procesan vía Sodexo |
| **MIFIEL** | CG vs Tesorería | Solo afiliación y tesorería | Solo Gabriela Mistral; sin archivo externo de transacciones |
| **BBVA** | CG vs Depósitos | Estado de cuenta | Conciliación directa de depósitos contra ventas CG |

---

### Flujo Propuesto

```
1. El usuario selecciona: Razón Social + Mes + Proveedor de Valera.

2. El sistema carga automáticamente las ventas de ControlGas
   para la razón social y período seleccionados.

3. El usuario carga (manual o automática) el reporte del proveedor:

   TicketCard / TC Plus
   ├── Reporte "Reembolsos Liquidados Grupal" en Excel (portal TC o TC Plus)
   ├── Filtro automático: solo transacciones de tipo gasolina
   └── Opción alternativa: descarga desde portal En Punto

   Inburgas
   ├── Descarga desde portal Inburgas (todas las estaciones)
   ├── Extracción automática de fecha pura de pago
   └── Asignación de promotor/estación desde concepto de depósito (manual con asistencia)

   Efecticard
   ├── Carga de facturas PDF/XML (por estación o global)
   ├── Extracción: fecha cierre, núm. factura, monto venta, reembolso, comisión, IVA
   └── Validación: reembolso + comisión + IVA = monto venta (por factura)

   Ultragas
   ├── Descarga XLS/CSV del portal (facturación + transacciones detalladas)
   └── Identificación de variante: Ultra AGS o MobileFleet

   Pluxee / Sodexo
   ├── Descarga desde portal En Punto
   └── Validación de estaciones Fluxi → Sodexo según configuración de afiliaciones

   MIFIEL (Gabriela Mistral)
   └── Comparación directa CG vs Tesorería (sin archivo externo)

   BBVA
   └── Carga de estado de cuenta; CG vs depósitos por razón social

4. El sistema normaliza los datos y los vincula por día/razón social.

5. Interfaz muestra la comparativa:
   Ventas ControlGas  |  Reporte Valera  |  Tesorería (referencia)

6. Las diferencias se resaltan; el usuario puede:
   - Enviar a tránsito (pago diferido al período siguiente)
   - Marcar como revisado con nota
   - Desligar y reconciliar

7. Exportar resultado en Excel o PDF (aunque el mes esté incompleto).
```

---

### Criterios de Aceptación

1. **Carga por proveedor:** El sistema acepta los formatos nativos de cada valera (Excel, CSV, PDF, XML) y los normaliza correctamente antes de persistirlos.

2. **TicketCard – filtro gasolinas:** El reporte cargado permite filtrar automáticamente solo las transacciones de tipo gasolina, descartando otros conceptos.

3. **TicketCard – afiliaciones:** El sistema mantiene una tabla de afiliaciones estación ↔ código (ej. Municipio 5317) editable por el usuario, usada para identificar el origen de cada depósito consolidado.

4. **TicketCard Plus:** El sistema diferencia las transacciones de TC Plus/Más Plus de las de TC normal; las diferencias derivadas de TC Plus se registran como partida pendiente hasta que se cargue el reporte correspondiente.

5. **Inburgas – fecha y promotor:** El sistema extrae la fecha pura de pago (eliminando horas) y propone la asignación de promotor/estación con posibilidad de corrección manual.

6. **Efecticard – fecha de factura:** El sistema usa la fecha de cierre de factura como referencia, no la fecha de venta, y lo indica visualmente al usuario.

7. **Efecticard – validación:** El sistema verifica automáticamente `reembolso + comisión + IVA = monto venta` por cada factura; las facturas que no cumplen se marcan como error y no se guardan.

8. **Ultragas – dos variantes:** El sistema distingue entre Ultra AGS y Ultra Gas MobileFleet al normalizar, manteniendo trazabilidad de qué portal fue la fuente.

9. **Pluxee/Sodexo – unificación de Fluxi:** El sistema permite configurar que ciertas estaciones de Fluxi se concilien bajo Sodexo cuando la conciliación transacción vs transacción no muestre diferencias.

10. **MIFIEL:** La conciliación de Gabriela Mistral solo requiere contrastar ControlGas vs Tesorería; el sistema lo resuelve sin solicitar carga de archivo externo.

11. **BBVA:** La conciliación opera comparando los depósitos del estado de cuenta contra las ventas de CG agrupadas por razón social.

12. **AMEX / Ejército / Fuentes / Santiago:** El sistema acepta la carga de los tres estados de cuenta de forma independiente y permite que la tabla dinámica asigne correctamente los depósitos aunque las afiliaciones de Santiago caigan en la cuenta de Ejército.

13. **Diferencias y tránsito:** Las diferencias se resaltan por día; el usuario puede enviarlas a tránsito o marcarlas. Los pagos diferidos (venta de un día, depósito en días posteriores) se netean correctamente entre días.

14. **Afiliaciones editables:** El usuario puede dar de alta, modificar o eliminar afiliaciones desde el sistema (incluyendo la eliminación de afiliaciones canceladas como "Firme Gas").

15. **Sin duplicados:** Control por hash único (fecha + monto + referencia de transacción + proveedor) para evitar registros repetidos.

16. **Exportación:** El usuario puede exportar en Excel o PDF aunque el mes esté incompleto.

17. **Nexus (PRAXEDIS):** El reporte de formas de pago descargado de Nexus en Excel puede cargarse al sistema como fuente alternativa/complementaria de ventas para las estaciones de PRAXEDIS.

---

### Reglas de Negocio

1. **Fecha de referencia variable por proveedor:**
   - TicketCard, Inburgas, Sodexo → fecha de transacción
   - Efecticard → fecha de cierre de factura
   - Ultragas, Fluxi → fecha de pago indicada en el portal (depósito cae al día siguiente)

2. **Conciliación por razón social (no por estación):** Efecticard, TicketCard y BBVA consolidan depósitos a nivel de razón social. El sistema debe agrupar ventas CG por razón social para estos casos.

3. **TicketCard Plus como variante separada:** Las transacciones de TC Plus/Más Plus no se pueden conciliar directamente contra el banco en el mismo flujo que TC normal; se registran como diferencias pendientes con etiqueta "TC Plus – pendiente de reporte".

4. **Afiliaciones dinámicas:** La tabla afiliación ↔ estación es mantenible por el usuario. Afiliaciones canceladas (ej. "Firme Gas") deben poder eliminarse sin afectar el histórico.

5. **Periodicidad irregular (Inburgas):** El sistema no asume que el pago ocurre el mismo día de la venta; permite conciliar ventas con depósitos de hasta N días posteriores configurables.

6. **Validación Efecticard obligatoria:** `reembolso + comisión + IVA = total venta` debe cumplirse por cada factura antes de registrarla. Las que fallen quedan en estado "error de validación".

7. **Nexus como fuente alternativa:** Para PRAXEDIS, el reporte de Nexus puede sustituir o complementar los cortes manuales como fuente de ventas en la columna ControlGas.

8. **Control de duplicados:** Hash único por transacción (fecha + monto + referencia + proveedor). Ningún registro puede duplicarse, incluso si el archivo se carga más de una vez.

9. **Tránsito heredado de Fase 1:** Las diferencias entre días (ventas de un día pagadas en días subsiguientes) se gestionan con la lógica de tránsito ya implementada; una diferencia de -$X en día 1 que se netea con +$X en día 2 se considera conciliada.

10. **AMEX – tres cuentas compartidas:** Al conciliar, el sistema debe permitir consultar simultáneamente las tres cuentas (Ejército, Santiago, Fuentes) para que las afiliaciones mal asignadas no generen diferencias artificiales.

11. **Portal En Punto como alternativa a TicketCard y Sodexo:** Cuando el portal En Punto proporciona datos de TC o Sodexo, el sistema debe poder distinguir la fuente e indicar que es una fuente alternativa.

12. **Exportación regulada:** Solo se exportan datos normalizados. Las facturas con error de validación (Efecticard) no se incluyen en el resumen de exportación hasta ser corregidas.

---

## Evaluación del Requerimiento

| Campo | Detalle |
|---|---|
| **Fecha Recepción** | |
| **Horas estimadas de desarrollo** | |
| **Responsable de la Evaluación** | |
| **Atendido** | [ ] Aceptado &nbsp;&nbsp; [ ] Rechazado &nbsp;&nbsp; [ ] Se requiere más información |

---

_Firma del solicitante: _____________________________ &nbsp;&nbsp;&nbsp; Firma del desarrollador: _____________________________
