# Conciliación bancaria automática

Ejecutar `python cron/efc_conc_bancaria_automatica.py` cada 10 minutos. El script busca `.env`, en este orden: junto al script, en la carpeta configurada como **Iniciar en**, y en la raíz del proyecto. Requiere las variables `EFC_CONC_DB_*` ya documentadas en `.env.example`; opcionalmente `EFC_CONC_CONTROLGAS_URL` y `EFC_CONC_CONTROLGAS_TIMEOUT`.

El proceso toma un `sp_getapplock`, consulta únicamente el mes actual (desde su primer día hasta hoy), y registra cada ejecución en `dbo.efc_conc_ejecuciones_automaticas`. La última ejecución terminada (también si acaba en `ERROR`) se expone a Twig como `lastAutomaticRun`, con `finalizada_en` y `estado`.

En el Programador de tareas de Windows, crear una tarea con repetición cada 10 minutos y configurar:

- **Programa:** `C:\ruta\al\entorno\Scripts\python.exe`
- **Argumentos:** `C:\ruta\a\Aplicativo2025\cron\efc_conc_bancaria_automatica.py`
- **Iniciar en:** `C:\ruta\a\Aplicativo2025`

Configurar la tarea para no iniciar una instancia nueva cuando la anterior siga en ejecución. El bloqueo SQL del script es una segunda protección frente a ejecuciones traslapadas.

El procedimiento `dbo.usp_efc_conc_guardar_automatica` se instala al inicio y, en una transacción serializable, rechaza períodos o etapas BANCO cerrados, tránsitos pendientes, turnos ya usados y depósitos ya usados. Los tránsitos entrantes no se concilian automáticamente: se conservan para el flujo manual del mes destino; los tránsitos de origen se rechazan explícitamente. No programe una segunda copia del script con otra credencial sin conservar el mismo SQL Server/applock.

GASOMEX usa `dbo.usp_efc_conc_guardar_gasomex` y concilia por secuencias continuas de turnos, separadas en MN (incluye morralla) y USD. El corte puede terminar tras T1 o T2: se admiten secuencias de 3 a 9 turnos, incluidos los acumulados de fin de semana. Cada secuencia debe coincidir con un único depósito de la cuenta y estación correspondientes, fechado el mismo día o después del último turno. Si falta un vínculo REGIO, ya se concilió un turno, hay turnos duplicados o un turno pertenece a un tránsito pendiente, la secuencia queda bloqueada. Si un depósito admite varias secuencias, el proceso lo deja para revisión manual. Los casos que mezclan importes de otra estación requieren revisión manual.

La asignación de cuentas compartidas se valida por cuenta, estación y moneda: 8214 corresponde a Ejército USD y Fuentes MN; 8492 corresponde a Fuentes USD y Clara MN/USD. Una misma cuenta puede aparecer como ambigua en el banco si el movimiento no incluye referencia de estación; en ese caso permanece disponible para clasificación/asignación manual, y el algoritmo automático valida la moneda permitida para cada estación.
