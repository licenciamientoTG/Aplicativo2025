# Conciliación bancaria automática

Ejecutar `python cron/efc_conc_bancaria_automatica.py` cada 10 minutos. El script busca `.env`, en este orden: junto al script, en la carpeta configurada como **Iniciar en**, y en la raíz del proyecto. Requiere las variables `EFC_CONC_DB_*` ya documentadas en `.env.example`; opcionalmente `EFC_CONC_CONTROLGAS_URL` y `EFC_CONC_CONTROLGAS_TIMEOUT`.

El proceso toma un `sp_getapplock` y registra cada ejecución en `dbo.efc_conc_ejecuciones_automaticas`. Consulta ControlGas desde siete días antes del inicio del mes actual hasta hoy para encontrar turnos cuyo depósito llega durante el mes siguiente; el rango de depósitos bancarios también abarca hasta siete días después de hoy. Praxedis conserva una ventana ampliada que incluye todo el mes anterior para admitir cargas tardías de cortes. La última ejecución terminada (también si acaba en `ERROR`) se expone a Twig como `lastAutomaticRun`, con `finalizada_en` y `estado`.

Los depósitos sin estación detectada pueden asignarse automáticamente cuando su importe coincide exactamente al centavo con el real de un vínculo REGIO activo y existe un único turno y depósito candidato dentro de la ventana normal (fecha del turno hasta siete días después). La estación inferida se guarda como corrección del depósito en la misma transacción que la conciliación. Si el importe tiene varias coincidencias posibles, se deja pendiente para revisión manual. GASOMEX conserva su conciliación por secuencias y cuentas permitidas.

La conciliación automática de Parral compara el depósito directamente con el importe del turno de ControlGas y permite una diferencia máxima de $6.00. La cuenta terminada en `369` se clasifica como Parral, igual que en la pantalla de Movimientos de efectivo. Las demás estaciones conservan la tolerancia de $1.00.

En el Programador de tareas de Windows, crear una tarea con repetición cada 10 minutos y configurar:

- **Programa:** `C:\ruta\al\entorno\Scripts\python.exe`
- **Argumentos:** `C:\ruta\a\Aplicativo2025\cron\efc_conc_bancaria_automatica.py`
- **Iniciar en:** `C:\ruta\a\Aplicativo2025`

Configurar la tarea para no iniciar una instancia nueva cuando la anterior siga en ejecución. El bloqueo SQL del script es una segunda protección frente a ejecuciones traslapadas.

Al inicio se instalan `dbo.usp_efc_conc_guardar_automatica` y `dbo.usp_efc_conc_guardar_gasomex`. En una transacción serializable validan cierres, turnos y depósitos ya usados. Cuando un depósito del mes siguiente corresponde a turnos del mes anterior, crean y concilian automáticamente los tránsitos de origen para cualquier estación; la conciliación queda operativa el primer día del mes bancario. El proceso mantiene disponibles los últimos siete días del mes anterior para reconocer esos lotes. No programe una segunda copia del script con otra credencial sin conservar el mismo SQL Server/applock.

GASOMEX usa `dbo.usp_efc_conc_guardar_gasomex` y concilia por secuencias continuas de turnos, separadas en MN (incluye morralla) y USD. El corte puede terminar tras T1 o T2: se admiten secuencias de 3 a 9 turnos, incluidos los acumulados de fin de semana. Cada secuencia debe coincidir con un único depósito de la cuenta y estación correspondientes, fechado el mismo día o después del último turno. Si falta un vínculo REGIO, ya se concilió un turno, hay turnos duplicados o un turno pertenece a un tránsito pendiente, la secuencia queda bloqueada. Si un depósito admite varias secuencias, el proceso lo deja para revisión manual. Los casos que mezclan importes de otra estación requieren revisión manual.

La asignación de cuentas compartidas se valida por cuenta, estación y moneda: 8214 corresponde a Ejército USD y Fuentes MN; 8492 corresponde a Fuentes USD y Clara MN/USD; 4669 corresponde a Santiago Troncoso MN y 3678 a Santiago Troncoso USD; 8504 corresponde a Jarudo MN, según el depósito de ejemplo. Una misma cuenta puede aparecer como ambigua en el banco si el movimiento no incluye referencia de estación; en ese caso permanece disponible para clasificación/asignación manual, y el algoritmo automático valida la moneda permitida para cada estación.
