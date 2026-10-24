# Plan: archivos de facturas de combustible en el NAS (patrón Tableros)

Estado: **propuesta, nada implementado**. Fecha del análisis: 2026-10-24.

Objetivo: guardar y servir los PDF/XML de facturas de combustible igual que Tableros (Monday) guarda sus archivos en `\\192.168.0.15\tableros`: servicio de almacenamiento aislado, metadatos y hash en BD, descarga verificada. Condición del proyecto: **no se toca el proceso actual**. Se construye algo paralelo, se mide hasta llegar al 100 %, y solo entonces se detiene el proceso viejo.

---

## 1. Cómo funciona Tableros (referencia)

| Pieza | Qué hace |
|---|---|
| `services/tableros_storage/index.php` | Aplicación IIS aislada con identidad propia (`svc_web_tableros`), la única con permiso sobre el NAS. Acepta `PUT`, `GET` y `DELETE` con bearer token (mín. 32 caracteres). Sin sesión ni BD. |
| Validación de claves | Solo claves con forma exacta (regex). Evita rutas arbitrarias. |
| Escritura | Archivo temporal en la misma carpeta + `rename` atómico. Si la clave ya existe responde 409 (nunca sobrescribe). Límite 500 MiB. |
| Lectura | `GET` con soporte de `Range` (video). |
| `TablerosModel::nasServiceRequest` | Cliente cURL. HTTP solo en `127.0.0.1`/`::1`; cualquier otra dirección exige HTTPS. |
| Metadatos en BD | `tb_file` / `tb_file_version`: `storage_provider` (`local` o `nas_service`), contenedor, `storage_object_key`, `sha256_hash`, tamaño, tipo MIME, `scan_status`. |
| Descarga | Permisos del tablero en PHP → `GET` al servicio a un temporal → **verifica `sha256` y tamaño** → entrega con `Content-Disposition`, `nosniff`, `no-store`. |
| Borrado | Cola `tb_storage_cleanup` + worker `cron/tableros_trash_worker.py`. |
| Migración | Variable `TABLEROS_STORAGE_DRIVER` (`local` / `nas_service`); conviven filas de ambos proveedores. |

Documentación existente: `docs/tableros_storage_service_deployment.md`.

Dato no verificado: no se sabe si producción ya corre el driver `nas_service`. Desde el usuario `alejandro.martinez` en 192.168.0.3 la carpeta del NAS no es visible (esperable: solo la cuenta de servicio tiene acceso).

---

## 2. Estado actual de las facturas (medido 2026-10-24)

### Archivos en disco
Ruta: `C:\Software\TareasProgramadas\Facturas_proveedores\correoFacturas\attachments` en el servidor 192.168.0.3. Disco C: con ~110 GB libres.

- **64,976 archivos, 4.85 GB** (56k PDF y 9k XML).
- Por proveedor: mcg 39,057 · tesoro 8,503 · petrotal 5,861 · premiergas 3,848 · aemsa 2,925 · lobo 2,876 · enerey 1,393 · essafuel 460.
- Por subcarpeta: `procesadas` 35,379 · `complemento de pago` 10,905 · `errores` 9,226 · `procesadasXml` 4,717 · `complemento de pagoXml` 4,232 · otras ~500.

### Filas en `TG.dbo.FacturasRecibidas`
- **22,056 filas**, UUID único (0 duplicados, índice `UX_FacturasRecibidas_UUID`), sin triggers.
- Con PDF registrado: 19,921. **Con XML registrado: 4,713 (21 %).**
- Ruta absoluta (`C:\…`): 14,347. Ruta relativa (`attachments\…`): 5,574.
- Columnas de archivo: `RutaArchivo` (nvarchar 1000), `NombreArchivo`, `RutaXml`, `NombreXml`.

### Quién escribe
1. Pipeline Python en el servidor: `CorreoFactruras.py` → `main.py` (`move_processed_file`) → API ApiER (`importar_factura_pdf`, `actualizar_ruta_factura`).
2. `Payment::save_imported_pdf` (importación manual).
3. `FuelReceptionInvoiceModel::guardarArchivos` (portal de estaciones / programación).
4. ApiER: `modelos/ImportadorFacturas.py`, `TG_php/views.py` (6 referencias a `RutaArchivo`/`RutaXml`).

### Quién lee (cada uno repite normalizar ruta + validar directorio base)
`payment.php` (≈5 sitios, incluido el ZIP), `station_portal.php`, `supply.php`, `FuelReceptionScheduleModel`, `PaymentAccountingGroupsModel`, `it.php`.

### Problemas que ya costaron tiempo
- Rutas mixtas (absoluta/relativa) → cada lector adivina; el XML recuperado con la carpeta equivocada (`mgc` en vez de `mcg`) fue posible porque la ruta es texto libre.
- Sin hash de integridad: no se detecta un archivo cambiado o corrupto.
- Un solo disco en un solo servidor; las rutas atan los archivos a esa máquina.
- Archivos sin fila: más de 30 mil archivos (complementos de pago, errores, canceladas, notas de crédito, copias `__timestamp`) no están referenciados por ninguna factura.

---

## 3. ¿Hace falta otra tabla? — Sí

**Conclusión: crear una tabla nueva. No conviene seguir con `FacturasRecibidas` ni con las tablas de documentos que ya existen.**

### Por qué no alcanzan las columnas actuales de `FacturasRecibidas`
1. **Solo caben dos archivos por factura** (un PDF y un XML). Ya hay más tipos: complemento de pago, nota de crédito, remisión, carta porte, comprobante. Cada tipo nuevo sería otra columna.
2. **No hay dónde guardar el hash, el proveedor de almacenamiento ni la clave del objeto.** Habría que agregar al menos 6 columnas a una tabla que escriben desde tres sistemas distintos (Python, PHP, ApiER). Cambiar su forma obliga a tocar a todos a la vez, justo lo que se quiere evitar.
3. **No representa los archivos sin factura.** Hay decenas de miles de archivos (complementos, errores, canceladas) cuyo UUID no tiene fila en `FacturasRecibidas`. Una tabla centrada en el archivo sí los puede registrar.
4. **No permite versiones.** El importador deja copias `…__timestamp` cuando el mismo UUID llega con bytes distintos; hoy se pierde la relación.
5. **Es incompatible con correr en paralelo.** Si las columnas viejas son lo que usa el proceso actual, no se puede ir llenando las nuevas en la misma tabla sin riesgo de que ApiER o el pipeline las pise o las ignore. Una tabla aparte es el único lugar donde lo nuevo puede crecer sin afectar lo viejo.

### Por qué no reutilizar las tablas de documentos existentes
`extra_payment_documents`, `payment_transaction_documents`, `recepcion_remisiones` y `ProveedorDocumentosPortal` guardan una `file_path` (o `ruta_relativa`) más nombre y tamaño. Ninguna tiene hash, proveedor/clave de almacenamiento ni estado de verificación, y cada una tiene un modelo distinto. No se propone cambiarlas; si más adelante se quiere, la tabla nueva puede alojar también esos documentos.

### Diseño propuesto: `TG.dbo.FacturaArchivos`

| Columna | Tipo | Notas |
|---|---|---|
| `Id` | bigint identity | PK |
| `Uuid` | varchar(50) NOT NULL | UUID del CFDI. Llave lógica del archivo, aunque no exista la factura. |
| `FacturaId` | int NULL | `FacturasRecibidas.Id` si existe; NULL para complementos/NC/errores sin fila. |
| `Tipo` | varchar(20) NOT NULL | `pdf`, `xml`; luego `remision`, `carta_porte`, `comprobante`. |
| `Clase` | varchar(20) NOT NULL | `factura`, `complemento_pago`, `nota_credito`, `cancelada`, `error`. |
| `EmisorRfc` / `Proveedor` | varchar | Para la clave y para filtros. |
| `StorageProvider` | varchar(20) | `local` o `nas_service`. |
| `StorageContainer` | varchar(30) | `facturas`. |
| `ObjectKey` | varchar(300) | Ver formato abajo. |
| `Sha256` | binary(32) | Hash del contenido. |
| `ByteSize` | bigint | |
| `ContentType` | varchar(100) | |
| `NombreOriginal` | nvarchar(260) | |
| `OrigenLocal` | nvarchar(1000) | Ruta desde la que se copió (trazabilidad). |
| `Origen` | varchar(20) | `correo`, `aplicativo`, `estacion`, `sat`, `espejo`. |
| `EsVigente` | bit | La versión que la factura usa hoy. |
| `Estado` | varchar(15) | `pendiente` → `subido` → `verificado` / `error`. |
| `VerificadoEn` | datetime NULL | Cuándo se comparó contra el NAS. |
| `Error` | nvarchar(400) NULL | |
| `CreadoEn` / `EliminadoEn` | datetime | Borrado lógico. |

Restricciones: `UNIQUE (Uuid, Tipo, Sha256)` (varias versiones con distinto contenido, un solo registro por contenido). Índices por `FacturaId` y por `(Estado, StorageProvider)`.

**Clave del objeto** (determinista, idempotente): `facturas/{EmisorRfc}/{AAAA}/{MM}/{UUID}_{sha12}.{ext}`. Al ser derivada del UUID y del hash, repetir la subida no duplica nada (el 409 del servicio se resuelve comparando hashes, como ya hace el importador de Tableros). El servicio debe ampliar su regex para aceptar este formato.

Tabla de apoyo opcional más adelante: `FacturaArchivosLimpieza`, cola de borrado igual a `tb_storage_cleanup`.

---

## 4. Estrategia paralela: sin tocar el proceso actual

**Regla:** mientras no se decida lo contrario, `run_ciclo.py`, `main.py`, `CorreoFactruras.py`, ApiER, `Payment::save_imported_pdf` y `FuelReceptionInvoiceModel` quedan **exactamente como están**. Nada nuevo escribe en `RutaArchivo`, `RutaXml`, `NombreArchivo` ni `NombreXml`. Nada nuevo mueve ni borra archivos locales.

```
 PROCESO ACTUAL (no se toca)              PROCESO NUEVO (paralelo)
 correo → run_ciclo → attachments\   ──►  espejo_nas.py (tarea propia)
 PHP/ApiER → RutaArchivo/RutaXml          lee attachments\ y FacturasRecibidas
                                          ──► servicio de almacenamiento ──► NAS
                                          ──► escribe SOLO en FacturaArchivos
 lectores actuales leen disco local       lector nuevo lee NAS (solo para ti al inicio)
```

### Pieza 1: servicio de almacenamiento `facturas`
Aplicación IIS aislada, copia del servicio de Tableros, con su propio share en el NAS (`\\192.168.0.15\facturas_combustible`), su propia cuenta de servicio y su propio token. Aislarlo de `tableros` evita que una falla o un permiso de uno afecte al otro. Requiere a sistemas para crear el share y asignar permisos.

### Pieza 2: worker espejo `espejo_nas.py`
- Tarea programada propia en 192.168.0.3 (cada 15 min), con su propio lock; **solo lee** el árbol `attachments\` y la BD.
- Recorre `FacturasRecibidas` (archivos registrados) y el árbol de carpetas (archivos sin fila), calcula `sha256`, hace `PUT` al servicio, verifica con `GET` y registra en `FacturaArchivos`.
- Ignora archivos con menos de 5 minutos de antigüedad para no competir con `move_processed_file`.
- Es idempotente y reanudable: si se interrumpe, la siguiente corrida continúa. Si el NAS no responde, deja el registro en `pendiente` y reintenta; no afecta al pipeline de correos.
- Primer paso: copiar el histórico (≈65 mil archivos, ≈5 GB; unos 60-90 minutos a ritmo de decenas por segundo). Después sigue copiando lo nuevo cada 15 minutos.

### Pieza 3: lector nuevo
Una clase única `FacturaArchivos` (sustituye la lógica repetida en 8 sitios) y una ruta nueva, por ejemplo `/payment/factura_archivo/<id>/<pdf|xml>`:
- Lee de `FacturaArchivos` + NAS, verifica `sha256`, entrega con `Content-Disposition`, `nosniff` y `no-store`.
- **Bandera por usuario:** al inicio solo disponible para el usuario 6296. Los lectores viejos no cambian.
- **Lectura sombra opcional:** para el usuario de prueba, cada descarga compara el hash del NAS contra el archivo local; si difiere, lo registra. Es la prueba de que ambos caminos entregan lo mismo.

### Pieza 4: tarjeta de monitoreo en el home
Ampliar la tarjeta que ya existe en `/home/index` con la cobertura de la migración (sección 5).

---

## 5. Cuándo se considera "al 100 %"

Se define con números, para decidir sin intuición. Todo se calcula con una consulta sobre `FacturaArchivos` y el árbol local.

| Indicador | Meta |
|---|---|
| Facturas con PDF registrado y archivo local existente que tienen fila `verificado` en el NAS | **100 %** |
| Ídem para XML | **100 %** (de los que existen; el 79 % que hoy no tiene XML registrado es un problema aparte, ver nota) |
| Archivos locales sin fila `verificado` (huérfanos clasificados: complemento, error, cancelada, copia) | **0** sin clasificar |
| Filas en estado `error` o `pendiente` con más de 24 h | **0** |
| Descargas sombra con hash distinto | **0** durante 14 días seguidos |
| Archivos nuevos del día espejados en menos de 30 min | **100 %** durante 14 días seguidos |

Nota sobre XML: hoy solo 4,713 de 22,056 facturas tienen XML registrado. El espejo copia lo que existe; completar los XML faltantes se resuelve con las recuperaciones ya hechas (SAT, correo) y no depende de este proyecto.

---

## 6. Cambio de uno a otro (solo cuando el 100 % sea sostenido)

Cada paso es reversible apagando una bandera; los archivos locales nunca se tocan hasta el último.

1. **Pruebas con tu usuario** (ya con espejo corriendo): descargar PDF/XML desde el lector nuevo, comparar con el viejo.
2. **Lector nuevo con respaldo:** se activa para más usuarios, módulo por módulo (pagos, portal de estaciones, programación). Si el NAS falla, cae al lector viejo.
3. **Doble escritura en origen:** el pipeline Python y los dos escritores PHP, además de guardar local como hoy, hacen `PUT` al servicio al momento de importar y registran la fila. El espejo pasa a ser solo red de seguridad.
4. **NAS como fuente:** los lectores dejan de caer al local. Se vigila una semana.
5. **Tú detienes el proceso viejo:** se desactiva `sya_descargar_facturas_proveedor` (o solo su escritura local) y el espejo. Las copias locales se conservan, solo lectura, un periodo acordado (propuesta: 30 días) y se borran al final.
6. **Retiro de columnas viejas** (`RutaArchivo`/`RutaXml`): es opcional y mucho después; puede no hacerse nunca.

**Reversa en cualquier paso:** apagar la bandera del lector y reactivar el proceso viejo. Hasta el paso 5 los archivos locales siguen siendo la fuente completa.

---

## 7. Riesgos y mitigaciones

| Riesgo | Mitigación |
|---|---|
| NAS caído o lento | El pipeline actual no depende del NAS. El espejo reintenta. El lector nuevo cae al local mientras exista. |
| Archivo cambiado mientras se copia | Se ignoran archivos de menos de 5 minutos; se compara hash antes y después. |
| Mismo UUID con bytes distintos | La clave incluye el hash; las versiones coexisten con `EsVigente`. |
| Descargas masivas (ZIP de hasta 500 facturas) lentas | Endpoint de ZIP en el servicio o `curl_multi` en paralelo. A medir en la fase de pruebas. |
| Permisos del NAS | Cuenta de servicio dedicada, sin acceso a `Everyone`; probar crear, leer y borrar un archivo antes de usarlo. |
| Token filtrado | Token aleatorio largo en variable de entorno del pool, fuera del repo; HTTP solo en loopback. |
| Espacio del NAS | El histórico son ≈5 GB; estimar crecimiento mensual con el ritmo actual de importación. |

---

## 8. Fases y esfuerzo estimado

| Fase | Contenido | Estimado |
|---|---|---|
| A | Share, cuenta de servicio, token, servicio IIS `facturas` y prueba de escritura/lectura | 1-2 días (depende de sistemas) |
| B | DDL de `FacturaArchivos` + `espejo_nas.py` + primera copia del histórico | 3 días + corrida |
| C | Clase `FacturaArchivos`, ruta nueva de descarga, bandera por usuario, lectura sombra | 2 días |
| D | Ampliar la tarjeta del home con los indicadores de la sección 5 | 1 día |
| E | Observación de 14 días (sin desarrollo) | 14 días |
| F | Doble escritura en origen (Python + 2 escritores PHP) y paso de lectores | 3 días |
| G | Cambio: apagar proceso viejo, periodo de solo lectura local | acordado contigo |

---

## 9. Decisiones pendientes

1. **¿Share propio (`facturas_combustible`) o dentro de `tableros`?** Recomendado: propio.
2. **¿Cuánto tiempo conservar las copias locales** después del cambio? Propuesta: 30 días en solo lectura.
3. **¿Se incluyen también remisiones y cartas porte** que suben las estaciones, o solo PDF/XML de facturas en la primera etapa? Recomendado: primero facturas; la tabla ya queda lista para los demás tipos.
4. **¿Los complementos de pago y los archivos de `errores`** (10,905 y 9,226) se migran al NAS o se dejan fuera? Recomendado: migrar complementos; los de `errores` revisarlos antes, porque son facturas que el importador no pudo procesar.

---

## 10. Archivos y piezas que se tocarían (para el deploy manual)

No se ha tocado nada todavía. Cuando se implemente:
- **AplicativoPhp:** clase nueva `FacturaArchivos`, controlador/ruta de descarga, ampliación del monitoreo del home. Los lectores viejos no se modifican hasta la fase F.
- **Servidor 192.168.0.3:** servicio IIS `facturas` (manual, con sistemas), tarea programada del espejo, `espejo_nas.py` (se copia a mano, igual que `run_ciclo.py`).
- **BD `TG`:** `FacturaArchivos` (el DDL lo corres tú). Ninguna columna existente cambia.
- **ApiER:** sin cambios hasta la fase F.
