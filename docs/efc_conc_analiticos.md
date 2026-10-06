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

Para limitar la sincronización a fechas operativas inclusivas, se puede indicar
un límite inicial, final o ambos. El rango se determina por la fecha del nombre
del archivo `TOTAL GAS dd-mm-aaaa.xls`, no por la fecha de recepción del correo:

```powershell
python .\cron\efc_conc_analiticos_diario.py --from 2026-09-08 --to 2026-09-09
```

Para reimportar y reevaluar vínculos únicamente dentro de ese rango, agrega
`--reprocess`:

```powershell
python .\cron\efc_conc_analiticos_diario.py --from 2026-09-08 --to 2026-09-09 --reprocess
```

`--reprocess` elimina y vuelve a crear las papeletas de cada archivo incluido;
los vínculos asociados a esas papeletas se regeneran. Por eso conviene acotar el
rango cuando se reprocesa.

El proceso acepta asuntos que contengan `ANALIT...`, incluyendo `ANALITICOS` y
reenvíos como `Analitos DG`. Toma sólo Excel cuyo nombre corresponde a `TOTAL
GAS`; PDFs de Actas, imágenes y Excel de otras razones sociales se ignoran. La
idempotencia se controla mediante SHA-256 del archivo.

Al terminar una importación, el script guarda de forma persistente los vínculos
automáticos entre papeletas y turnos en `efc_conc_analiticos_vinculos`. Aplica
primero **Dice contener ±$1** y después **Real ±$20** (o **±$8** para USD con
el tipo de cambio histórico del inicio del turno). Sólo enlaza coincidencias
inequívocas y respeta tanto vínculos manuales existentes como una papeleta que
se haya desasociado manualmente. La vista ya no crea vínculos automáticos al
consultarla.

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

## Segunda papeleta manual por turno

La excepción permite que dos papeletas REGIO correspondan a un único turno y
concepto de ControlGas. Se conserva un solo vínculo y un solo importe CG; la
segunda papeleta se guarda en `papeleta_secundaria_id`. Los importes declarados y
reales se suman para calcular las diferencias, reportes, resumen y cierres. En
USD se usa el tipo de cambio histórico guardado en el vínculo.

En el panel de analíticos, selecciona una papeleta disponible y usa **Asociar
segunda papeleta** en un turno que ya tenga la primera. Las dos deben pertenecer
a la misma estación efectiva y no estar asociadas a otro turno. Se admite una
sola papeleta adicional; el criterio de la excepción es `MANUAL_DOBLE`.

El turno doble necesita **dos depósitos distintos**, asociados manualmente como
un solo grupo. Esta condición también aplica a la excepción en USD: los turnos
USD ordinarios conservan su tratamiento habitual. En GASOMEX, el turno doble se
concilia por separado y no se mezcla con otros turnos en un lote.

Las diferencias se calculan una sola vez por turno:

```text
Diferencia REGIO = real de primera + real de segunda − ControlGas
Diferencia banco = depósito 1 + depósito 2 − real de ambas papeletas
```

Un tránsito conserva el turno de origen y ambas papeletas; al recibirlo en el
mes destino también requiere dos depósitos. Deshacer la conciliación bancaria
libera los dos depósitos conjuntamente. Antes de agregar, quitar o cambiar una
papeleta de un turno con banco asociado, se debe deshacer su conciliación. Se
puede quitar únicamente la segunda o desasociar ambas desde el panel.

La asociación automática REGIO sigue vinculando una papeleta por turno; reserva
las dos papeletas de una excepción manual. La conciliación automática bancaria
omite los turnos dobles, incluidas las rutas de Praxedis y lotes GASOMEX. El
reprocesamiento de importaciones y el reinicio de pruebas rechazan documentos
protegidos por esta excepción.

Para actualizar una instalación existente, usar
`docs/sql/efc_conc_segunda_papeleta.sql` en TG; el script no utiliza separadores
`GO` y admite ejecución desde DBeaver. Actualizar conjuntamente los modelos PHP,
la vista y ambos procesos automáticos antes de habilitar la nueva acción manual.
El cambio de código no ejecuta la migración en el servidor remoto.
