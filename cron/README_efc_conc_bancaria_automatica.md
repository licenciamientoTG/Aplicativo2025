# Conciliación bancaria automática

Ejecutar `python cron/efc_conc_bancaria_automatica.py` cada 10 minutos con el mismo `.env` del proyecto. Requiere las variables `EFC_CONC_DB_*` ya documentadas en `.env.example`; opcionalmente `EFC_CONC_CONTROLGAS_URL` y `EFC_CONC_CONTROLGAS_TIMEOUT`.

El proceso toma un `sp_getapplock`, consulta el mes actual y el inmediato anterior, y registra cada ejecución en `dbo.efc_conc_ejecuciones_automaticas`. La última ejecución terminada (también si acaba en `ERROR`) se expone a Twig como `lastAutomaticRun`, con `finalizada_en` y `estado`.

En el Programador de tareas de Windows, crear una tarea con repetición cada 10 minutos y configurar:

- **Programa:** `C:\ruta\al\entorno\Scripts\python.exe`
- **Argumentos:** `C:\ruta\a\Aplicativo2025\cron\efc_conc_bancaria_automatica.py`
- **Iniciar en:** `C:\ruta\a\Aplicativo2025`

Configurar la tarea para no iniciar una instancia nueva cuando la anterior siga en ejecución. El bloqueo SQL del script es una segunda protección frente a ejecuciones traslapadas.

El procedimiento `dbo.usp_efc_conc_guardar_automatica` se instala al inicio y, en una transacción serializable, rechaza períodos o etapas BANCO cerrados, tránsitos pendientes, turnos ya usados y depósitos ya usados. Los tránsitos entrantes no se concilian automáticamente: se conservan para el flujo manual del mes destino; los tránsitos de origen se rechazan explícitamente. No programe una segunda copia del script con otra credencial sin conservar el mismo SQL Server/applock.
