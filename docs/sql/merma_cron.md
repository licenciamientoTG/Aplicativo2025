# Cron de merma diaria

Sincroniza el mes en curso completo (día 1 -> ayer) de todas las estaciones
cada madrugada (6:00 am). Cubrir el mes completo, no solo D-2/D-1, evita que
un fallo puntual de un día dilate el hueco: el cron del día siguiente vuelve
a intentar todo el mes. Se programa como script CLI (mismo patrón que
cron/auto_group_payments.php); la ruta HTTP /merma/sync_diario NO sirve para
el cron porque index.php exige sesión antes de despachar al controlador.

Programar en el servidor donde corren los demás crons del aplicativo
(Programador de Tareas de Windows):

    schtasks /create /tn "TG merma sync diario" /tr "php C:\ruta\AplicativoPhp\cron\merma_sync_diario.php" /sc daily /st 06:00

(Ajustar C:\ruta\ a la ruta real del working copy en ese servidor.)

Verificación: SELECT TOP 5 * FROM TG.dbo.merma_sync_log ORDER BY id DESC;
debe aparecer una fila origen='cron' cada día, con desde=día 1 del mes en
curso y hasta=ayer. Las fallas quedan con el mensaje en detalle_errores.
