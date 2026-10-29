# Escaneo diario de cambios en cortes ControlGas

Ejecutar `python cron/efc_conc_cambios_cortes.py` una vez al día (por ejemplo,
después del cierre nocturno). El proceso usa las variables `EFC_CONC_DB_*` y
`EFC_CONC_CONTROLGAS_*` del `.env` como
`cron/efc_conc_bancaria_automatica.py`. El huso horario predeterminado es
`America/Mexico_City`; se puede cambiar con `EFC_CONC_TIMEZONE`. El rango incluye
los 60 días calendario que terminan ayer.

Se consultan las estaciones 2, 4–18, 22, 30 y 33. Praxedis (40) se excluye.
Una respuesta válida de ControlGas se procesa en una transacción por estación;
si la consulta o validación falla, el snapshot de esa estación queda intacto.
El primer escaneo crea la línea base sin emitir avisos. Después se registran
cambios observados como `PENDIENTE`; una nueva modificación de la misma clave
marca el evento pendiente anterior como `SUPERSEDIDO` y crea otro evento. El
importe aceptado solo se inicializa con la línea base, por lo que la revisión
posterior no pierde el valor vigente.

En una respuesta completa y validada, los cortes anteriores que ya no aparezcan
se registran con importe observado cero. Si falla la consulta o la validación,
no se interpretan ausencias como cambios. El envío de correo usa reintentos con
entrega al menos una vez: si el proceso se interrumpe después de que el servidor
SMTP aceptó el mensaje y antes de registrar el éxito, podría reenviar ese correo.

Cada aviso se envía únicamente a `daniel.ramirez@totalgas.com`. SMTP usa
`SMTP_HOST` / `EMAIL_SMTP_HOST`, `SMTP_PORT` / `EMAIL_SMTP_PORT` y `SMTP_FROM` /
`EMAIL_FROM` con el relay interno como valor predeterminado. Fallos de envío
permanecen en la cola para reintento en la siguiente ejecución. Una aplicación
bloqueante de SQL Server evita ejecuciones simultáneas; los resultados se
guardan en las tablas de ejecuciones generales y por estación.

Antes de programarlo, aplicar la migración SQL de las tablas
`efc_conc_cortes_snapshot`, `efc_conc_cortes_cambios`,
`efc_conc_cortes_notificaciones`, `efc_conc_cortes_runs` y
`efc_conc_cortes_runs_estaciones`. La tabla de destinatarios configurables ya
no se utiliza; si existe de una instalación previa, queda inactiva.
