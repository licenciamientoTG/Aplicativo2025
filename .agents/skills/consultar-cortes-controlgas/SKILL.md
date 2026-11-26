---
name: consultar-cortes-controlgas
description: Consulta cortes de ControlGas y sus renglones fuente en SG12, incluidos los campos de Ingresos y sus marcas lognew/logfch. Úsala cuando se necesite verificar importes por estación, fecha, turno y concepto, investigar cambios en cortes o listar sus registros de origen.
---

# Consultar cortes de ControlGas

Obtén los importes actuales de los cortes solicitados y, cuando haya acceso a SG12, muestra los renglones que los forman. Trabaja en modo de solo lectura. No apruebes, reviertas ni modifiques registros.

## Flujo

1. Extrae de la solicitud cada estación, fecha operativa, turno y concepto. Conserva los importes anterior/nuevo y el estado si el usuario compartió una tabla de cambios.
2. Resuelve el `codgas` real usando `SG12.dbo.Gasolineras`; no deduzcas el código de estación a partir del prefijo mostrado al usuario. Verifica también el nombre de estación.
3. Inspecciona la definición vigente de `TG.dbo.sp_estaciones_depositos` antes de usarla. Confirma sus parámetros, categorías de pago, columna para cada concepto y conversión de fecha. El procedimiento observado en este proyecto vive en TG y consulta `SG12.dbo.Ingresos`, `Valores` y `Gasolineras`; no des por hecho que sigue igual si cambió en la base.
4. Consulta el procedimiento para obtener los totales actuales de los cortes seleccionados. Limita las fechas y estaciones para evitar traer periodos/estaciones innecesarios.
5. Consulta las filas fuente de `SG12.dbo.Ingresos` para las claves solicitadas. Incluye `Ingresos.*` cuando el usuario pida todos los campos y añade la descripción de `Valores.den` para interpretar `codval`. Si solo solicita la conciliación por concepto, enfoca el detalle en los códigos que el procedimiento usa para ese concepto.
6. Agrupa las filas por estación, fecha, turno y concepto. Compara la suma del detalle con la salida del procedimiento; explica cualquier diferencia en vez de atribuirla a una isla o forma de pago sin evidencia.
7. Devuelve una tabla resumida de cortes y, si se pidieron los registros, otra de detalle. Conserva segundos en las marcas de tiempo. Distingue `lognew` (marca inicial del registro) de `logfch` (última marca de escritura visible); no los presentes como historial completo ni como identidad del usuario.

## Reglas de fecha, turno y concepto

Usa siempre la conversión de fecha del procedimiento vigente. En la versión observada, el filtro de `Ingresos.fch` se calcula con `DATEDIFF(day, 0, fecha) + 1`. No conviertas `fch` como serial de Excel desde `1899-12-30`: esa interpretación desplaza la fecha.

En la versión observada, los turnos operativos se codifican en `Ingresos.nrotur` como 11, 21, 31 y 41 para los turnos 1, 2, 3 y 4. Verifica la definición actual del procedimiento si aparecen otros turnos.

El mapeo de pago observado en `sp_estaciones_depositos` es:

| Concepto de salida | `Valores.cod` | Columna del procedimiento |
|---|---:|---|
| MN / Efectivo MN | 6 | `MN` |
| Dólares 2 | 5 | `Dolares2` |
| Morralla | 192 | `Morralla` |
| Cheques | 53 | `Cheques` |
| Dólares | -1128 | `Dolares` |

Usa el conjunto de códigos del procedimiento vigente como filtro para reproducir su cálculo. Otros medios de pago pueden existir en `Ingresos`, pero no necesariamente forman parte de la salida de este endpoint.

## Marcas de log y límites de auditoría

- Para un corte con varias islas, calcula `MIN(lognew)` y `MAX(logfch)` sobre las filas que contribuyen al concepto consultado. Etiqueta claramente esos valores agregados como primer log y última modificación visible del conjunto.
- Si se necesitan marcas por fila, muestra `lognew` y `logfch` de cada renglón sin agregarlas.
- `logfch` solo da la última escritura que conserva la fila; no reconstruye sus versiones anteriores. `logusu` no identifica a una persona si está en cero o no se puede resolver con un catálogo verificado.
- No afirmes quién hizo el cambio sin una bitácora o evidencia que identifique al usuario. Si hace falta atribución, revisa de forma separada las bitácoras disponibles y comunica si no hay registro correlacionable.

## Acceso a datos

Usa una conexión SQL ya disponible y autorizada en el entorno. No copies credenciales al skill, a la salida ni a archivos nuevos. Si no hay acceso directo a la base, entrega una consulta SQL reproducible parametrizada; no simules resultados.

Las consultas deben ser `SELECT` o ejecución de procedimientos de lectura. No ejecutes actualizaciones, aprobaciones ni reversiones como parte de esta skill.
