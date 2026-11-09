# Resumen semanal de incidencias resueltas

`terminal_incidents_solved_weekly_email.py` envía una fotografía de todos los
tickets cuyo estado local actual es `Solved`, agrupados por estación activa.
No limita la consulta a los siete días anteriores: así incluye los tickets que
sigan resueltos hasta que cambien de estado. La tabla muestra la estación y su
cantidad de tickets resueltos, ordenada de mayor a menor; en empates, ordena por
nombre de estación. No muestra identificadores de tickets de Mojo. Cada
cantidad enlaza al reporte de incidencias con
`station=<código>&status=solved`.

En modo de prueba, el único destinatario habilitado es
`daniel.ramirez@totalgas.com`. En la función `recipients()` quedaron
comentados los correos de gerencia y los jefes de zona, junto con la línea que
agrega las direcciones de las estaciones desde `TG.dbo.Estaciones.email`.
Después de validar el envío, se pueden descomentar esos bloques. Se excluyen
las estaciones 0, 4 y 20 y se deduplican direcciones repetidas.

Configuración requerida en `cron/terminal_incidents_solved_weekly_email.env`
(archivo local ignorado por Git) o en el entorno del programador:

```dotenv
EFC_CONC_DB_HOST=...
EFC_CONC_DB_USER=...
EFC_CONC_DB_PASSWORD=...
```

Las direcciones de los destinatarios adicionales ya están guardadas como
comentarios en el script para habilitarlas después de las pruebas.

Para revisar consulta y destinatarios sin envío:

```powershell
python cron/terminal_incidents_solved_weekly_email.py --dry-run
```

Para el programador del servidor, ejecutar una vez por semana:

```powershell
python cron/terminal_incidents_solved_weekly_email.py
```

El SMTP usa `SMTP_HOST`/`EMAIL_SMTP_HOST` (predeterminado
`smtp-relay.gmail.com`), `SMTP_PORT`/`EMAIL_SMTP_PORT` (587) y
`SMTP_FROM`/`EMAIL_FROM`. El reporte web se puede cambiar con
`TERMINAL_INCIDENT_REPORT_URL`. La tabla incluye todas las estaciones activas,
incluso cuando no tienen tickets resueltos.
