# Formato de Requerimiento de Usuario (F.R.U.)

| Campo | Detalle |
|---|---|
| **Fecha** | 31/03/2026 |
| **Requerimiento** | Fase 1 — Conciliacion Bancaria V3 (CG vs Tesoreria) |
| **Solicitante** | Maribel Garcia |
| **Puesto** | Directora de Administracion y Finanzas |
| **Area / Departamento** | Administracion y Finanzas |
| **Estado** | Implementado y en produccion |

---

## Historias de Usuario (Funcionalidades)

### Nombre del Requerimiento

Modulo de conciliacion bancaria V3: comparacion de ventas ControlGas contra depositos de Tesoreria, con soporte de transitos entre meses, diferidos dentro del mes, cierre de mes, y dashboard de resultados.

---

### Descripcion de la Necesidad y/o Requerimiento

El area de Tesoreria necesita contrastar diariamente las ventas registradas en **ControlGas** (sistema de punto de venta de cada estacion) contra los **depositos reales** recibidos en el banco. Las diferencias entre ambos deben quedar documentadas, y los casos especiales donde el deposito no corresponde al mismo dia de la venta deben manejarse de forma estructurada mediante transitos (mes siguiente) y diferidos (mismo mes, dia distinto).

#### Bancos y Entidades Soportadas

| Banco | Formato de reporte | Tipo de carga |
|---|---|---|
| Santander / Getnet | .CSV | Manual por usuario |
| Banorte | .XLSX | Manual por usuario |
| American Express (AMEX) | .CSV | Manual por usuario |
| Afirme | .CSV | Manual por usuario |
| AMEX Comisiones (Envios) | .CSV / .XLSX | Manual — reporte separado de comisiones e IVA |

#### Razon Social como Eje Principal

| Razon Social | RFC | Bancos asociados |
|---|---|---|
| DIAZ GAS | DGA930823KD3 | Banorte, Santander, AMEX, Afirme |
| GASOMEX | DGM880621FU5 | AMEX |
| FORANEAS | Resto de RFC | Varios |

---

### Flujo del Sistema Implementado

```
1. El usuario carga el reporte del banco desde la pantalla de Carga de Reportes.
   - El archivo se lee en el navegador y se envia al servidor en Base64.
   - El servidor normaliza las filas y las inserta en Tesoreria_V3_Unificada.
   - Registros duplicados se omiten automaticamente por hash unico.

2. El usuario abre la pantalla de Conciliacion V3 y selecciona:
   - Razon Social (DIAZ GAS / FORANEAS / GASOMEX)
   - Estacion
   - Banco / Entidad
   - Afiliacion (terminal bancaria, si aplica)
   - Mes y Año a conciliar

3. La pantalla carga en paralelo:
   - Ventas de ControlGas por dia (panel izquierdo)
   - Depositos bancarios del mes (panel derecho)
   - Conciliaciones ya realizadas del mes
   - Transitos pendientes del mes anterior
   - Diferidos activos del mes

4. El usuario concilia seleccionando una venta CG y un deposito de Tesoreria.
   - Si coinciden exactamente: estado = CONCILIADO (verde).
   - Si hay diferencia: estado = CONCILIADO CON DIFERENCIA (azul).

5. Casos especiales:
   a) TRANSITO: el deposito del banco llega en el mes siguiente.
      - El usuario parte el corte: monto efectivo + monto en transito.
      - En el mes siguiente aparece una fila pendiente para conciliar.
   b) DIFERIDO: el deposito llega en otro dia del mismo mes.
      - El usuario parte el corte: monto efectivo + monto diferido.
      - En el dia destino aparece una fila adicional para conciliar.

6. Al terminar el mes el usuario puede:
   - Ver el resumen previo (pendientes, transitos, diferencias).
   - Cerrar el mes (queda bloqueado para edicion).
   - Reabrir el mes si se requiere correccion.

7. Dashboard disponible con KPIs, tendencia mensual y desglose por banco y estacion.

8. Exportacion a Excel: diferencias detalladas y resumen general.
```

---

### Funcionalidades Implementadas

#### Carga de Reportes Bancarios

- Carga manual de archivos CSV y XLSX desde el navegador.
- Normalizacion automatica de columnas por banco.
- Deduplicacion por hash unico (fecha + monto + referencia).
- Soporte para reporte adicional de comisiones e IVA de AMEX que enriquece el monto bruto de cada deposito.

#### Pantalla de Conciliacion

- Vista split con panel CG a la izquierda y panel Tesoreria a la derecha.
- Agrupacion de ventas CG por dia con totales.
- Panel TX informativo (transacciones bancarias) sin valor de conciliacion.
- Barra de resumen superior: total CG, total Tesoreria, total TX, cantidad y suma de diferencias.
- Selector de rango de dias con calculo de totales parciales.
- Indicador de mes cerrado con banner visible.
- Alerta de transitos pendientes del mes anterior.

#### Conciliacion

- Seleccion multiple: uno o varios cortes CG contra uno o varios depositos de Tesoreria.
- Guardado en grupo (Conciliacion_V3_Grupos + Conciliacion_V3_Detalles).
- Deshacer conciliacion individual.
- Deshacer todas las conciliaciones del mes.
- Actualizacion de detalle de una conciliacion ya guardada.
- Deteccion de cambios: si un deposito de Tesoreria fue modificado despues de conciliar, el sistema lo marca como desincronizado.
- Sincronizacion de montos: actualiza el grupo con el nuevo monto de Tesoreria.

#### Transitos

- Crear transito partiendo un corte CG: define monto efectivo (conciliable en mes origen) y monto en transito (aparecera en mes destino).
- En el mes destino aparece una fila de transito pendiente lista para conciliar contra el deposito correspondiente.
- Cancelar transito: devuelve el corte a su estado original.
- Creacion de transitos por dia completo en lote.
- Vista de todos los transitos activos del mes con gestion individual.

#### Diferidos

- Crear diferido partiendo un corte CG: define monto efectivo y monto diferido con fecha destino dentro del mismo mes.
- Validacion: la fecha destino debe ser distinta a la origen y ambas deben pertenecer al mismo mes activo.
- En la fecha destino aparece una fila diferida lista para conciliar.
- Mover diferido: cambia la fecha destino a otro dia del mismo mes.
- Cancelar diferido.
- Creacion de diferidos por dia completo en lote.

#### Cierre de Mes

- Vista previa del cierre con resumen de: total CG, total depositado, total en transito, diferencias, items pendientes.
- Cerrar mes: registra el cierre en Conciliacion_V3_CierreMes con estado CERRADO, bloquea edicion.
- Reabrir mes: permite corregir conciliaciones ya guardadas.
- Historial de cierres consultable desde el dashboard.

#### Dashboard

- KPIs globales: total CG conciliado, total depositado, total en transito, total diferencias.
- Tendencia mensual en grafica (CG vs depositado vs diferencias).
- Desglose por razon social, por banco/afiliacion y por estacion.
- Filtros por año, banco, estacion y razon social.
- Resumen de transitos activos en dashboard.

#### Exportacion

- Exportar diferencias del mes a Excel con detalle por dia.
- Exportar resumen general del mes a Excel.

---

### Criterios de Aceptacion (verificados en produccion)

1. **Carga sin limites de tamaño:** El archivo se envia como Base64 desde el navegador evitando el limite de `upload_max_filesize` de PHP.

2. **Sin duplicados:** Un mismo archivo puede cargarse mas de una vez sin generar registros repetidos.

3. **Panel split funcional:** Ambos paneles cargan de forma independiente y reflejan el estado actualizado despues de cada accion.

4. **Transito entre meses:** Al crear un transito, el corte origen queda partido visualmente y en el mes destino aparece la fila pendiente correcta.

5. **Diferido en el mismo mes:** Al crear un diferido, la fecha destino recibe la fila adicional y la fecha origen muestra el monto restante.

6. **Cierre irreversible sin reabrir:** Un mes cerrado no puede modificarse sin pasar por la accion explicita de reabrir.

7. **Deteccion de cambios:** Si Tesoreria actualiza un monto ya conciliado, el sistema lo detecta y permite sincronizar sin perder el grupo.

8. **Exportacion en cualquier momento:** El usuario puede exportar aunque el mes no este cerrado.

9. **AMEX Comisiones:** Los depositos AMEX muestran el monto bruto y el desglose de comisiones e IVA cuando el reporte de envios ha sido cargado.

10. **Mes cerrado bloqueado:** La pantalla muestra un banner e impide acciones de conciliacion sobre meses cerrados.

---

### Reglas de Negocio

1. **CG vs Tesoreria como eje:** La conciliacion principal siempre es entre ventas de ControlGas y depositos bancarios. Las transacciones individuales del banco son referencia informativa.

2. **Razon Social como segmento:** DIAZ GAS (RFC DGA930823KD3), GASOMEX (RFC DGM880621FU5) y FORANEAS (resto) son los tres segmentos de conciliacion.

3. **Afiliacion por terminal:** Cada estacion puede tener multiples afiliaciones (terminales bancarias). La conciliacion se realiza por afiliacion especifica.

4. **Transito: cruza mes, no dia:** Solo aplica cuando el deposito cae en el mes siguiente. Para depositos del mismo mes pero dia distinto se usa el diferido.

5. **Diferido: mismo mes, dia distinto:** El sistema valida que fecha origen y fecha destino pertenezcan al mismo mes activo y sean dias diferentes.

6. **Suma diferida no supera el corte:** La suma de todos los diferidos activos de un corte no puede exceder el monto del corte original.

7. **Mes cerrado = bloqueado:** Ninguna accion de conciliacion, transito o diferido puede ejecutarse sobre un mes con estado CERRADO sin reabrirlo previamente.

8. **Hash unico en carga bancaria:** El sistema calcula un hash por registro al cargar reportes. Registros con hash existente se omiten silenciosamente.

9. **AMEX Comisiones opcionales:** Si no se ha cargado el reporte de comisiones, los depositos AMEX se muestran sin desglose pero son conciliables igualmente.

10. **Deteccion de cambios no bloquea:** Si un deposito de Tesoreria cambia despues de conciliar, el sistema alerta pero no deshace la conciliacion automaticamente; el usuario decide si sincronizar.

---

## Evaluacion del Requerimiento

| Campo | Detalle |
|---|---|
| **Fecha Recepcion** | 2025 |
| **Estado** | Implementado y en produccion |
| **Responsable de Desarrollo** | Daniel Ramirez |
| **Atendido** | [x] Aceptado |

---

_Firma del solicitante: _____________________________ &nbsp;&nbsp;&nbsp; Firma del desarrollador: _____________________________
