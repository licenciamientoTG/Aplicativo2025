# Compras: remisiones por correo y cierre del registro — diseño

Fecha: 2026-11-05 · Actualizado 2026-11-27 (R0 y prueba de OCR hechas) · Estado: **diseño aprobado en lo general; no hay código escrito de esto** (R0 fue solo lectura: no se descargó ningún adjunto a la NAS ni se escribió en la BD)
Continúa: `2026-10-27-compras-registro-plan.md` (registro ya construido) · Documentación: `docs/compras_combustible_documentacion.html` (Etapa 1, §3.3) y `docs/compras_combustible_tecnica.html` (Etapa 2: §17 remisiones, §18 cruce)

## 1. Intención y por qué

Abastos lleva el registro de compras de combustible en `COMPRAS 2026.xlsx`; el módulo `/compras/registro` ya arma el renglón por factura y coincide 97.6 % con el Excel (cruce del 5-nov). Lo que Abastos sigue haciendo a mano cada día, según Alberto, es:

1. **Bajar las remisiones del correo y teclearlas** (proveedor, estación, fecha de descarga, producto, litros, monto, BOL; transportista y placas vienen en la remisión).
2. Buscar la factura de cada remisión y **validar el BOL**.
3. Otras tareas que ya cubre el registro o que son de otros módulos (ver §3.4 de la documentación).

La meta de este trabajo es **quitar la captura manual de remisiones** leyéndolas del buzón `jesus.olivas@` (el mismo del que ApiER ya baja facturas de Petrotal, MGC y Premier con el mismo método de Gmail API + NAS) y, con eso, cerrar las diferencias que impiden retirar el Excel. **El correo es solo la fuente de arranque**: después las remisiones se tomarán de las que las estaciones suben en Mis Recepciones y el correo deja de ser la fuente.

## 2. Metas, no-metas y anti-metas

**Metas**
- Las remisiones que llegan al buzón quedan guardadas, leídas y ligadas a su factura sin que nadie las baje ni las teclee.
- El registro muestra BOL, transportista, placas, litros y fecha de descarga de la remisión, y avisa «factura sin remisión», «remisión sin factura» y «BOL no coincide».
- Cerrar las diferencias contra el Excel que dependen del sistema (vencimiento, precio de venta, exportación en orden A..BH, proveedor final de Petrotal) para poder correr un mes en paralelo.

**No-metas**
- No se captura nada en ControlGas desde aquí (sigue siendo de Abastos y de las estaciones).
- No se cubre el correo a proveedores con comprobantes de pago (es de pago a proveedores), ni la hoja Pendientes, ni el envío de correos a Susana, Maribel, Luis, Claudia (siguen manuales).
- No se toca Mis Recepciones (la estación sigue pudiendo subir sus remisiones ahí).
- Precio cotizado y TAR Pemex siguen fuera de alcance.

**Anti-metas (sería un fracaso aunque «funcione»)**
- Que el sistema **invente o adivine** un BOL, litros o placas mal leídos y los muestre como ciertos. Todo dato de una remisión con duda queda «por revisar».
- Que un dato leído pise lo que Abastos capturó a mano. Lo capturado siempre gana.
- Que se pierda o duplique una remisión por reintentos del ciclo de cada 15 minutos.
- Que se quiten las pistas de rendimiento de la consulta del registro (`LEFT HASH JOIN`, `NOLOCK`, literales de fecha).

## 3. Restricciones

- El buzón es `jesus.olivas@totalgas.com`. Desde 2026-11-23 las facturas ya no se bajan con `correoFacturas` (IMAP, servidor 0.3, tareas deshabilitadas) sino en **ApiER `api/facturas_correo/`**: Gmail API con cuenta de servicio y delegación de dominio (`gmail.readonly`), archivos en la NAS (`\\192.168.0.15\FacturasProveedores\combustible\<prov>\…`, montada en el 109 como `/mnt/nas_facturas`), control en `FacturasCorreoMensajes` / `FacturasCorreoProveedorEstado` y cron en `/etc/cron.d/apier_facturas_correo`. **Ese es el molde de las remisiones**; no se usa IMAP ni credenciales nuevas.
- R0 (2026-11-27, solo lectura, autorizado por el usuario) ya entró al buzón con esa misma cuenta de servicio. Leer más buzones o descargar adjuntos a la NAS vuelve a requerir su autorización.
- El despliegue de ApiER es `git push` + `./deploy.sh` en el 109, y los scripts SQL los corre el usuario.
- Base: SQL Server TG (`192.168.0.6`); el aplicativo escribe con `MySqlPdoHandler`/PDO, los scripts Python con `pyodbc`/ApiER. El asistente no escribe en la BD: los scripts SQL los corre el usuario.
- `fch` de ControlGas es serial de Excel; el vencimiento siempre es emisión + días de crédito a día hábil (nunca `fechaVto`).

## 4. Lo que sabemos hoy

- **Resultado de R0 (nov 1–26, solo lectura):**
  - **Quién:** las estaciones, desde su cuenta `@totalgas.com` (`TG.dbo.Estaciones.email`, 39 activas), al grupo «Remisiones Abastos» `remisiones@totalgas.com`, que llega a `jesus.olivas@`. 832 correos de 38 estaciones; 824 con adjunto (1,535 PDF, 117 PNG, 17 JPG, 1 XML). Asunto casi siempre `BOL 1269667`.
  - **Cobertura:** contra 1,243 recepciones de ControlGas: 62 % con BOL exacto en asunto/adjunto, 36 % solo estación + fecha, 2 % sin correo. Mis Recepciones: 13 %.
  - **Formato:** 100 % de los PDF revisados son escaneos sin texto → OCR obligatorio. Un correo = juego de documentos de **una pipa** (carta porte Pemex, tira de la estación, remisión del proveedor, CFDI carta porte del transportista, incremento). Los `image.png` son capturas de ControlGas «Registro de Compras» (115 en 91 correos) y **sí se leen**.
  - **Ruido:** otros ~558 correos con «remisión/BOL» son facturas de proveedores o hilos de personas (respuestas con factura, NC, cierre, fletes): no son remisiones. El catálogo de remitentes son las estaciones.
  - **Estaciones difíciles** (BOL del asunto casi inútil): Ejército 5 %, Villa Ahumada 4 %, San Rafael y Puertecito 0 %.
- `FacturasRecibidas.Remision` ya guarda el BOL/remisión que viene en el CFDI (Premier, por ejemplo, lo trae en el nombre del adjunto: `... BOL 1258132.pdf`). Es la llave natural de cruce.
- Mis Recepciones ya guarda remisión y carta porte subidas por la estación en `TG.dbo.recepcion_remisiones` (ligadas a `nrotrn/codgas/fchtrn` de ControlGas, no a la factura).
- El registro ya tiene las celdas capturables `bol`, `placas`, `transportista`, `fecha_descarga` en `compras_factura_datos` y `compras_renglon_manual`.

## 5. Diseño propuesto

### 5.1 Flujo

```
Buzón jesus.olivas@ (Gmail API)  →  ApiER api/remisiones_correo/  →  NAS combustible\remisiones\<estación>\…
                                  ↓
                     OCR híbrido: Tesseract (texto completo) + Gemini (tipo y campos)
                                  ↓
                     TG.dbo.compras_remisiones  (una fila por documento; texto OCR guardado)
                                  ↓  cruce por BOL → FacturasRecibidas.Remision / uuid
                     ComprasRegistroModel::listar()  → celdas y avisos del registro
```

### 5.2 Piezas

| Pieza | Dónde | Qué hace |
|---|---|---|
| Descarga de remisiones | ApiER `api/remisiones_correo/` (nuevo; reutiliza `gmail_cliente`, `almacen`, ventana y latido de `api/facturas_correo/`) | Busca en `jesus.olivas@` los correos cuyo remitente está en `Estaciones.email`; baja los adjuntos a la NAS `combustible\remisiones\<estación>\…` (escritura atómica); guarda id de Gmail, remitente, asunto y fecha. Idempotente por buzón + id de Gmail; llave del documento = hash + id del correo + BOL (no hay UUID). Cron propio de ApiER. |
| Lector (OCR híbrido) | `api/remisiones_correo/lector.py` (nuevo) | **Tesseract** guarda el texto completo de cada archivo y aporta candidatos de BOL; **Gemini** (con la imagen, `gemini-3.5-flash-lite`) clasifica el tipo de documento y devuelve los campos, con reintento si el JSON viene roto. Extrae BOL, fecha, producto, litros, transportista, placas, estación y monto con parser por tipo (carta porte Pemex, tira, remisión de proveedor, CFDI carta porte, captura de ControlGas). BOL candidato = unión de asunto, Tesseract y Gemini; `estado = por_revisar` si no coincide con una factura o recepción de ControlGas o algún dato clave no se lee con certeza. |
| Importación | Dentro del mismo ciclo (como `importar_cola` de facturas) | Inserta en `compras_remisiones` sin duplicar. |
| Tabla | `docs/sql/compras_remisiones.sql` (lo corre el usuario) | Ver §5.3. |
| Cruce | Paso al final de la importación y botón/proceso de reintento | Liga cada remisión a su factura por BOL; si no, por proveedor + estación + fecha ±1 día + litros ±1 %. |
| Registro | `ComprasRegistroModel` + `compras.js` | Toma BOL/placas/transportista/fecha de descarga de la remisión cuando no hay captura manual; agrega avisos y un enlace al archivo. |

### 5.3 Tabla `TG.dbo.compras_remisiones` (propuesta)

`id`, `tipo_documento` (`carta_porte_pemex` / `tira` / `remision_proveedor` / `cfdi_carta_porte` / `captura_controlgas` / `incremento` / `otro`), `proveedor_rfc`, `proveedor`, `bol`, `bol_candidatos` (los que dio cada método), `folio_remision`, `fecha_descarga`, `producto`, `litros`, `monto`, `est_codgas`, `nrotrn` (recepción de ControlGas si se ligó), `transportista`, `placas`, `texto_ocr` (texto completo de Tesseract: así quedan «todos los campos» y se puede reprocesar), `campos_json` (respuesta de Gemini), `ruta_archivo` (lógica, `combustible\remisiones\…`), `hash_archivo`, `gmail_id`, `buzon`, `correo_remitente`, `correo_asunto`, `correo_fecha`, `uuid_factura` (nullable, el cruce), `estado` (`ligada` / `sin_factura` / `por_revisar` / `descartada`), `leido_con` (`tesseract` / `gemini` / `ambos` / `manual`), `created_at`, `updated_by`, `updated_at`. Índices por `bol`, `uuid_factura`, `(est_codgas, fecha_descarga)` y único `(buzon, gmail_id, hash_archivo)`.

### 5.4 Reglas

- **Prioridad de datos** en una celda del registro: captura manual > remisión leída > calculado de la factura.
- **Cruce**: primero BOL exacto contra `FacturasRecibidas.Remision` del mismo proveedor; después proveedor + estación + fecha ±1 día + litros ±1 %. Más de una candidata ⇒ no se liga y queda `por_revisar`.
- **Avisos**: *factura sin remisión* (pasados N días de la descarga; N por definir con Abastos), *remisión sin factura*, *BOL no coincide* (la factura trae un BOL y la remisión otro).
- **Nada se borra**: una remisión equivocada se marca `descartada`.
- Las remisiones que hoy están como renglones manuales (sin factura) se pueden ligar a una remisión leída para cerrarlas.

## 6. Fases (cada una se aprueba y se prueba antes de la siguiente)

| Fase | Qué | Depende de |
|---|---|---|
| **R0 Descubrimiento** — ✅ hecho 2026-11-27 | Solo lectura sobre noviembre: remitentes = estaciones vía `remisiones@`, formato = escaneos sin texto (OCR), cobertura 62 % BOL exacto, `image.png` = capturas de ControlGas. Prueba de OCR: Tesseract + Gemini encuentra el BOL en 88 % (60 correos). | — |
| **R1 Descarga** | `api/remisiones_correo/` con Gmail API → NAS sin leer; tabla y script SQL (lo corre el usuario); semáforo por estación y monitoreo (cuántas por día, estaciones sin correo). Arranca con noviembre completo. | R0 |
| **R2a Lectura: BOL y tipo** | Tesseract (texto completo guardado) + Gemini (tipo y campos) sobre lo guardado en R1; BOL candidato = unión; `por_revisar` cuando no coincide. | R1 |
| **R2b Lectura: litros y demás campos** | Muestra etiquetada a mano (≈100 documentos) y parser por tipo de documento; medir litros contra ControlGas antes de mostrarlos como confiables. | R2a |
| **R3 Cruce y registro** | Ligar a factura y a la recepción de ControlGas, mostrar BOL/placas/transportista y los tres avisos en `/compras/registro`; aviso extra «BOL de la remisión ≠ BOL capturado en ControlGas» (hallazgo de la prueba: Travel Center y otros). | R2a (R2b para litros) |
| **C1 Vencimiento** | Confirmar con Abastos fecha base y día hábil por proveedor y ajustar `ComprasRegistroModel::vencimiento()` (hoy coincide 25 % contra el Excel). | Respuesta de Abastos |
| **C2 Precio de venta** | Cargar o completar `FuelPrices` para Anapra, Puerto de Palos, Puertecito, Picachos, Ventanas y Praxedis (701 renglones sin precio). | Datos de precios |
| **C3 Exportación** | `/compras/exportar` con columnas A..BH en orden del Excel, con valores. | — |
| **C4 Proveedor final Petrotal** | Inferirlo de la conciliación Petrotal (hoy a mano, 89 renglones Tesoro) y con él el almacenaje. | Conciliación Petrotal |
| **C4b Solicitud de facturas Petrotal** | Que el sistema genere la solicitud: Abastos marca en el registro las facturas por solicitar y un botón arma y manda el correo a Marco, Christofer, Liliana, Maribel y Claudia con la factura del proveedor original adjunta (sin redactar ni adjuntar a mano) (decisión del usuario; no es automático; Petrotal no factura hasta que se lo piden). Por definir: desde qué cuenta se envía y quiénes van en cada correo. | Respuesta de Abastos |
| **C4c Deuda por empresa** | Sección nueva con la deuda a un día antes, por razón social, y un botón que arma y manda el correo a Susana, Maribel, Luis y Claudia (nombres de las notas de Alberto, por verificar). Toma saldo y vencimiento del registro; depende de cerrar C1 (vencimiento). | C1 |
| **C5 Mes en paralelo** | Un mes completo Excel + sistema comparando cada semana; criterio de retiro en la documentación §15. | R3, C1–C4 |

**Calendario estimado** (Gantt en la documentación §3.3; todo por construir): remisiones por correo (R0–R3) en 2 semanas (S1–2), solo como arranque; después se toman de Mis Recepciones (S3); después, con varias etapas en paralelo: igualar columnas y perfiles (S3), cruce contra facturas (S3–4), cruce contra ControlGas (S4), vencimiento y pago programado (S3–4), precio de venta (S4), botón Excel (S4), proveedor final de Petrotal (S4–5), solicitud de facturas a Petrotal (S5), deuda por empresa (S5–6), mes en paralelo (S6–9) y retiro del Excel (S9). Total: dos meses (9 semanas).

R0–R3 y C1–C4 son independientes entre sí; el orden recomendado es **(R0 hecho) → C1 → C3 → R1…R3 → C2 → C4 → C5** porque C1 y C3 no dependen del buzón y desbloquean la comparación en paralelo.

## 7. Qué queda a criterio de quien construya

- Lectura: **decidido con evidencia de R0** — todos los PDF son escaneos, así que se usa OCR híbrido (Tesseract + Gemini, ver §5.2); no se prueba la capa de texto. Falta instalar Tesseract (con idioma `spa`) en el 109 y definir la cuota/costo de Gemini por volumen (~830 correos y ~1,700 archivos al mes).
- Qué guardar: el texto OCR completo de todos los documentos (campos «todos», interpretación a confirmar con el usuario) y estructurar primero solo los necesarios (BOL, fecha, producto, litros, placas, transportista, estación, tipo).
- Nombres exactos de archivos, índices y mensajes de log.
- Cómo mostrar el enlace al PDF en la tabla (icono en la celda de BOL).

## 8. Pruebas

- Lector: pruebas con remisiones reales guardadas (muestra etiquetada a mano por tipo de documento, sin datos sensibles en el repositorio) con el resultado esperado escrito a mano. Métrica de BOL contra ControlGas con **control** (evaluar contra otra estación: debe dar 0 falsos positivos; en R0 dio 0). La métrica de litros contra ControlGas no sirve sin etiquetas (en el control dio 24 de 53 por casualidad).
- Cruce: casos de BOL exacto, BOL con ceros a la izquierda, dos candidatas, sin factura, factura con BOL distinto.
- Registro: `php tests/ComprasCalculosTest.php` sigue pasando; prueba nueva del orden de prioridad de datos.
- Humano: Alberto revisa una semana de remisiones leídas contra las que él habría tecleado.

## 9. Riesgos

| Riesgo | Mitigación |
|---|---|
| Escaneos ilegibles o BOL mal leído | `por_revisar` + captura manual. En la prueba de nov el BOL se halló en 88 % de 60 correos; el resto se revisa a mano. |
| Un correo con varios documentos y hasta 2 BOL | Una fila por documento, no por correo; el correo agrupa por pipa. |
| Duplicados por reintento del ciclo | Idempotencia por buzón + id de Gmail + hash del archivo. |
| Litros mal leídos | No se muestran como confiables hasta R2b; marcados `por_revisar`. |
| Gemini: respuestas con JSON roto (3–15 % según variante), cuota y costo | Reintento, Tesseract como respaldo, medir volumen mensual antes de activar. |
| BOL de la remisión distinto al capturado en ControlGas (Travel Center, otros) | Es hallazgo, no error del lector: se avisa, no se corrige solo. |
| Documentos personales en `image.png` o correos ajenos | Solo se procesan remitentes del catálogo de estaciones. |
| Llave de servicio / Gemini | La cuenta de servicio ya vive en `/etc/apier/gmail_sa.json`; la llave de Gemini va en `/etc/apier/` (nunca en el repositorio ni en logs). |

## 10. Preguntas que solo pueden contestar Alberto o Alejandro

1. ~~¿Autorizan R0?~~ **Resuelto 2026-11-27** (autorizado y hecho).
2. ~~¿Quién manda las remisiones?~~ **Resuelto**: las estaciones, vía el grupo `remisiones@totalgas.com`. Por confirmar con Abastos: ¿hay transportistas o proveedores que también manden sueltas fuera de ese grupo?
2b. «Todos los campos, solo los necesarios»: ¿se confirma guardar el texto OCR completo y estructurar solo BOL, fecha, producto, litros, placas, transportista y estación?
2c. ¿Se aceptan Gemini (costo por archivo) + Tesseract en el servidor 109, o se prefiere solo Tesseract y revisión manual de lo dudoso?
3. ¿Cuántos días después de la descarga debe saltar el aviso «factura sin remisión»?
4. ¿Reesa llega por correo y cómo se integra al pago a proveedores (Alberto lo marcó como pendiente)?
5. Vencimiento: ¿qué fecha base y qué ajuste de día hábil usa Abastos para Tesoro, MGC y AEMSA? El Excel y el sistema difieren de −1 a +6 días.
