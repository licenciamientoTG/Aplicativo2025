# XML procesados agrupados por empresa / tipo / año / mes — diseño

Fecha: 2026-09-29 · Sistema: ApiTotal (Laravel 11, `C:\Users\alejandro.martinez\Desktop\codigo\ApiTotal`; en el servidor 192.168.0.3 en `C:\inetpub\wwwroot\ApiTotal`, se sube por FTP a mano)

## Objetivo

Que los XML ya importados dejen de acumularse sueltos en una sola carpeta y queden
ordenados por la empresa del grupo, el tipo de descarga y el mes de emisión, tanto
los nuevos (cambio de proceso) como los que ya existen (reorganización retroactiva).

## Situación actual (medida el 2026-09-29)

- `C:\tareasprogramadas\invoice\cfdis_processed\`: 290,423 archivos `UUID.xml`
  sueltos, 3.2 GB, sin subcarpetas; emitidas y recibidas mezcladas. El más viejo es
  de oct-2025: lo anterior lo borró el endpoint `delete_xml_processed` (42 llamadas
  desde PCs de usuarios, abr–jun 2025; nadie lo llama desde entonces).
- En BD (TGV2): emitidas en `Facturas` (desde 2023, índice único en UUID),
  recibidas en `FacturasRecibidas` (desde 2024, sin índice en UUID).
- 11,312 UUID están en ambas tablas (facturas entre empresas del grupo): se
  descargaron dos veces (como emitida de una empresa y como recibida de otra) pero en
  disco sobrevive un solo archivo, porque la segunda descarga pisa a la primera.
- Las 8 empresas del grupo (`DGA930823KD3`, `DGM880621FU5`, `ECU0602287R6`,
  `GOG181220973`, `GVA9709154V2`, `DCL880518UG2`, `PET180213L66`, `SSY940520271`)
  cubren el 100% de `EmisorRfc` en emitidas y de `ReceptorRfc` en recibidas.
- Únicos puntos del código que escriben en `cfdis_processed`: los dos `rename` de
  `procesarArchivosXML` (factura importada y factura "ya procesada"). Único que lo lee:
  `delete_xml_processed`. Nómina (tipo N) va a `…\invoice\tipo_n\` (fuera de alcance).

## Decisiones del usuario

1. "Razón social" = la empresa del grupo: el **emisor** en las emitidas y el
   **receptor** en las recibidas.
2. Cada archivo se guarda **según cómo se descargó**: descargado como emitida →
   carpeta de emitidas del emisor; como recibida → carpeta de recibidas del receptor.
   Una factura entre empresas del grupo descargada de las dos formas queda en las dos.

## Estructura destino

```
C:\tareasprogramadas\invoice\cfdis_processed\
  {RFC empresa}\{emitidas|recibidas}\{AAAA}\{MM}\{UUID}.xml
  sin_clasificar\{UUID}.xml
```
- `{RFC empresa}` = RFC en mayúsculas (no el nombre: evita caracteres inválidos en rutas).
- `{AAAA}\{MM}` = año y mes de la **fecha de emisión** del CFDI (atributo `Fecha`).
- `sin_clasificar` = no se pudo determinar RFC o fecha (proceso) o el UUID no está en
  ninguna tabla (retroactivo).

## A. Cambio de proceso

1. `App\Services\SatPipelineDirs` gana
   `rutaProcesado(string $type, ?string $rfcEmisor, ?string $rfcReceptor, ?string $fecha): string`:
   - `$type` `'emited'` → RFC = emisor, subcarpeta `emitidas`; `'received'` → RFC = receptor, `recibidas`.
   - Fecha parseable y RFC no vacío → `…\cfdis_processed\{RFC}\{tipo}\{AAAA}\{MM}\`; si no → `…\cfdis_processed\sin_clasificar\`.
   - Crea la carpeta si no existe (recursivo) y devuelve la ruta con separador final.
   - RFC saneado: mayúsculas, solo `[A-Z0-9&Ñ]`; si queda vacío → `sin_clasificar`.
2. `procesarArchivosXML`: los dos `rename($file, $directory_final . basename($file))`
   pasan a usar `rutaProcesado($type, $facturaData['EmisorRfc'], $facturaData['ReceptorRfc'], $facturaData['Fecha'])`.
   `$type` ya existe (`'emited'`/`'received'` según la carpeta de entrada = cómo se descargó).
   Si ya existe un archivo con el mismo nombre en el destino (misma factura
   re-descargada del mismo modo) se reemplaza (mismo contenido; comportamiento actual de `rename`).
3. Se **elimina** `delete_xml_processed` (método y ruta en `routes/api.php`): borra el
   archivo histórico completo y no se usa desde jun-2025.

## B. Reorganización retroactiva

Comando Artisan `sat:reorganizar-procesadas` (en `app/Console/Commands/`), para correr en
el servidor (`php artisan sat:reorganizar-procesadas …` desde `C:\inetpub\wwwroot\ApiTotal`).

- **Universo:** solo los `*.xml` sueltos en la raíz de `cfdis_processed` (no entra a
  subcarpetas) ⇒ se puede interrumpir y volver a correr sin duplicar.
- **Clasificación por BD** (por lotes de 1,000 UUID, nombre de archivo sin `.xml` = UUID):
  - UUID en `Facturas` → destino emitidas con `EmisorRfc` y `Fecha` de esa fila.
  - UUID en `FacturasRecibidas` → destino recibidas con `ReceptorRfc` y `Fecha` de esa fila.
  - En ambas → dos destinos: se **copia** al primero y se **mueve** al segundo.
  - En ninguna → `sin_clasificar`.
  - Varias filas del mismo UUID en una tabla (no debería pasar en `Facturas`; posible en
    `FacturasRecibidas` por falta de índice) → se usa la de menor `Id`.
  - Destinos calculados con el mismo `rutaProcesado` del proceso (una sola regla).
- **Opciones:**
  - `--dry-run`: no mueve nada; escribe reporte CSV de conteos por RFC/tipo/año/mes y
    la lista de `sin_clasificar`.
  - `--limit=N`: procesa como máximo N archivos (para ir por partes).
  - `--revertir=<bitácora.csv>`: regresa a la raíz lo movido según la bitácora (y
    borra las copias hechas para los que estaban en ambas tablas).
- **Bitácora:** cada corrida real escribe CSV `accion,origen,destino` (`move`/`copy`) en
  `storage/logs/reorganizar-procesadas-{AAAAmmdd-HHiiss}.csv`; el reporte del dry-run
  va a `storage/logs/reorganizar-procesadas-dryrun-{…}.csv`. El comando imprime la ruta.
- **Candado:** toma el mismo archivo de candado que `process_xml`
  (`sys_get_temp_dir()/sat_process_xml.lock`, `LOCK_EX|LOCK_NB`); si está ocupado
  termina con mensaje "Hay una importación en curso" y código de salida 1.
- **Resumen final** en consola: archivos vistos, movidos, copiados, sin_clasificar, errores.
- Un archivo que falla al moverse se registra en la bitácora como `error` y el comando
  sigue; nunca borra un archivo sin haberlo movido/copiado antes.

## Pruebas

PHPUnit (ApiTotal), con carpetas temporales y sin BD real:
- `rutaProcesado`: emitida/recibida, RFC en minúsculas/con basura, fecha inválida o nula → `sin_clasificar`.
- Comando: dry-run no mueve; movimiento a la ruta correcta; UUID en ambas tablas →
  archivo en los dos destinos; UUID sin fila → `sin_clasificar`; segunda corrida no
  hace nada; `--revertir` deja la raíz como estaba; candado ocupado → sale sin mover.
  La consulta a BD se aísla detrás de una clase inyectable (`buscarEnBd(array $uuids): array`)
  que las pruebas reemplazan con datos fijos.

## Despliegue y ejecución

1. Usuario sube por FTP los archivos de ApiTotal tocados.
2. `php artisan sat:reorganizar-procesadas --dry-run` en el servidor → revisar reporte.
3. Corrida real (opcionalmente por partes con `--limit`), fuera de horario de
   `sya_peticion_decompress` (01:45) y sin importaciones en curso.

## Fuera de alcance

- Nómina (`tipo_n`), `invoice_processed`, `meta`.
- Recuperar XML anteriores a oct-2025 (se borraron; habría que volver a descargarlos del SAT).
- Índice único en `FacturasRecibidas.UUID`.
