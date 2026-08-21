# Analíticos REGIO

El proceso `cron/efc_conc_analiticos_diario.py` consulta el buzón en modo
solo lectura, interpreta `PLANILLA` y guarda directamente en TG. No marca,
mueve ni elimina correos, y no usa PHP.

Configurar estas variables de entorno para la cuenta que ejecuta la tarea de
Windows, sin guardarlas en el repositorio:

```text
EFC_CONC_ANALITICOS_MAIL_USER
EFC_CONC_ANALITICOS_MAIL_PASSWORD
EFC_CONC_DB_HOST
EFC_CONC_DB_PORT
EFC_CONC_DB_NAME
EFC_CONC_DB_USER
EFC_CONC_DB_PASSWORD
```

La conexión a TG se arma con IP, puerto, base, usuario y contraseña. Copiar
`.env.example` como `.env` en el servidor y colocar ahí los valores reales;
el archivo `.env` está ignorado por Git y no debe quedar en el script.

Opcionales (los valores predeterminados corresponden a Gmail por IMAPS):

```text
EFC_CONC_ANALITICOS_IMAP_HOST=imap.gmail.com
EFC_CONC_ANALITICOS_IMAP_PORT=993
EFC_CONC_ANALITICOS_IMAP_FOLDER=INBOX
```

Programar una ejecución diaria con:

```text
python C:\ruta\Aplicativo2025\cron\efc_conc_analiticos_diario.py
```

El proceso toma únicamente adjuntos `.xls` o `.xlsx` de mensajes cuyo asunto
contiene `ANALITICOS`. La idempotencia se controla mediante SHA-256 del archivo.

En el servidor instalar una vez las dependencias del script:

```text
pip install pyodbc xlrd openpyxl
```
