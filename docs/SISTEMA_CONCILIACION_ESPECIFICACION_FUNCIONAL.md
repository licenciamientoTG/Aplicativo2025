# SISTEMA DE CONCILIACION - ESPECIFICACION FUNCIONAL

## 1. FUNCIONES PRINCIPALES

### Conciliacion Operativa por Estacion, Banco y Afiliacion
El sistema permite conciliar montos entre cortes operativos (CG) y depositos de tesoreria por combinacion de estacion, entidad bancaria y afiliacion. El usuario selecciona partidas de ambos lados y genera grupos de conciliacion con diferencia calculada y trazabilidad de detalle.

### Gestion de Cierres Mensuales
El sistema permite cerrar un mes por combinacion estacion-entidad-afiliacion, consolidando totales de CG conciliado, depositado, transitos y diferencias. Si existen items pendientes, exige nota de cierre para justificar excepciones operativas.

### Reapertura de Mes
El sistema permite reabrir meses cerrados para habilitar ajustes posteriores. La reapertura desbloquea la edicion de grupos y elimina el registro de cierre del mes, manteniendo el historial de conciliaciones existentes.

### Gestion de Transitos
El sistema permite registrar transitos cuando parte del corte se deposita en un mes destino distinto al mes de origen. Soporta creacion individual y por dia completo, calculo sugerido desde transacciones bancarias y cancelacion controlada cuando no hay meses cerrados involucrados.

### Gestion de Diferidos
El sistema permite diferir montos de un corte a otra fecha destino dentro del mismo mes. Soporta creacion individual y por lote diario, distribucion proporcional, movimiento de fecha destino y cancelacion con reglas de estado y cierre.

### Carga y Normalizacion de Reportes Bancarios
El sistema permite carga manual de reportes de BANORTE, SANTANDER/GETNET, AMEX y AFIRME, con mapeo de columnas, validacion de campos clave y omision de duplicados por huella de transaccion. Para AMEX, integra carga separada de comisiones e IVA por deposito.

### Sincronizacion de Tesoreria
El sistema sincroniza movimientos de tesoreria desde hojas de Google Sheets hacia tablas SQL, con deteccion de registros nuevos, soporte de layouts por banco/cuenta y normalizacion para su consumo en conciliacion.

## 2. FUNCIONES PERIFERICAS

### Dashboard Ejecutivo de Conciliacion
El sistema genera un tablero de seguimiento con KPIs de cierres, evolucion de diferencias, desglose por razon social, banco, estacion y combinaciones completas de catalogo, incluyendo casos sin cierres.

### Seguimiento de Transitos Pendientes
El sistema muestra antiguedad de transitos pendientes con segmentacion temporal y monto total en riesgo operativo, permitiendo priorizar casos criticos por dias de rezago.

### Exportacion de Informacion
El sistema permite exportar resumenes y diferencias para analisis externo y seguimiento administrativo.

### Ajuste de Montos de Detalle en Conciliaciones
El sistema permite recalcular montos de detalle de tesoreria en conciliaciones existentes y propagar automaticamente nuevos totales y diferencias al grupo afectado.

### Control de Reportes Incompletos
Cuando un archivo bancario no contiene fechas de deposito completas, el sistema lo mueve a una bandeja de pendientes para reproceso posterior, evitando contaminar la conciliacion con datos parciales.

### Reporte de Incidencias desde la Vista Operativa
El sistema incluye modulo de bug report con descripcion y capturas para comunicar incidencias funcionales al equipo de soporte.

## 3. FUNCIONES MISCELANEAS

### Trazabilidad y Auditoria por Grupo y Detalle
Cada conciliacion se guarda como grupo con detalle de origen (CG, TES, TRANSITO, DIFERIDO), referencia externa, fecha, monto y concepto, permitiendo reconstruccion completa de la operacion.

### Reglas de Estado y Bloqueo Operativo
El sistema aplica estados de negocio (ACTIVO, CERRADO, PENDIENTE, CONCILIADO, CANCELADO) para impedir operaciones inconsistentes, como cancelar transitos o diferidos ya conciliados o modificar periodos cerrados.

### Integridad Referencial de Proceso
Las operaciones de guardado, deshacer y cierre se ejecutan en transaccion de base de datos, conservando consistencia entre grupos, detalles, transitos, diferidos y cierres mensuales.

### Compatibilidad Multi-afiliacion
El sistema soporta configuraciones con afiliaciones combinadas y consolidacion por fecha para evitar duplicidad de lectura operativa en tesoreria.

### Reglas Especificas por Banco
El sistema maneja particularidades de origen por banco, incluyendo normalizacion de columnas, parseo de fechas/horas, formatos monetarios y procesamiento de comisiones AMEX.

### Automatizacion de Extraccion Bancaria
Existe soporte de robot para extraccion automatizada de reportes bancarios y reproceso de pendientes. En la version actual de interfaz, este flujo se mantiene disponible a nivel backend/script, con activacion de UI sujeta a mantenimiento.

### Notificacion de Ejecucion Tecnica
Los procesos automatizados de bancos generan reporte de ejecucion con estatus, registros insertados, archivos procesados y resumen de pendientes/fallidos para seguimiento operativo.
