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

El proceso acepta asuntos que contengan `ANALIT...`, incluyendo `ANALITICOS` y
reenvíos como `Analitos DG`. Toma sólo Excel cuyo nombre corresponde a `TOTAL
GAS`; PDFs de Actas, imágenes y Excel de otras razones sociales se ignoran. La
idempotencia se controla mediante SHA-256 del archivo.

En el servidor instalar una vez las dependencias del script:

```text
pip install pyodbc xlrd openpyxl
```
# Carga histórica única

Para recuperar los Analíticos cuya fecha de archivo sea del 31 de julio al 17 de agosto de 2026,
ejecutar una sola vez desde la carpeta que contiene el archivo `.env`:

```powershell
& C:/python31210/python.exe .\efc_conc_analiticos_historico.py
```

El rango es inclusivo y se toma de `TOTAL GAS dd-mm-aaaa.xls`, no de la fecha
en que llegó el correo. Puede cambiarse si fuera necesario:

```powershell
& C:/python31210/python.exe .\efc_conc_analiticos_historico.py --from 2026-07-31 --to 2026-08-17
```

Si se actualiza el lector de Excel o la normalización de estaciones, se puede
reprocesar únicamente ese rango sin crear importaciones nuevas:

```powershell
& C:/python31210/python.exe .\efc_conc_analiticos_historico.py --reprocess
```

El proceso diario no importa únicamente los correos del día: revisa todos los
mensajes con asunto `ANALITICOS` del buzón configurado, en modo lectura. El hash
SHA-256 del adjunto evita volver a almacenar un archivo idéntico.
