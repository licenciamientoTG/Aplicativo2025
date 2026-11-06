# Compras: remisiones por correo y cierre del registro — diseño

Fecha: 2026-11-05 · Estado: **borrador para aprobar** (no hay código escrito de esto)
Continúa: `2026-10-27-compras-registro-plan.md` (registro ya construido) · Documentación: `docs/compras_combustible_documentacion.html` (Etapa 1, §3.3) y `docs/compras_combustible_tecnica.html` (Etapa 2: §17 remisiones, §18 cruce)

## 1. Intención y por qué

Abastos lleva el registro de compras de combustible en `COMPRAS 2026.xlsx`; el módulo `/compras/registro` ya arma el renglón por factura y coincide 97.6 % con el Excel (cruce del 5-nov). Lo que Abastos sigue haciendo a mano cada día, según Alberto, es:

1. **Bajar las remisiones del correo y teclearlas** (proveedor, estación, fecha de descarga, producto, litros, monto, BOL; transportista y placas vienen en la remisión).
2. Buscar la factura de cada remisión y **validar el BOL**.
3. Otras tareas que ya cubre el registro o que son de otros módulos (ver §3.4 de la documentación).

La meta de este trabajo es **quitar la captura manual de remisiones** leyéndolas del buzón `jesus.olivas@` (el mismo del que `correoFacturas` ya baja facturas de Petrotal y MGC) y, con eso, cerrar las diferencias que impiden retirar el Excel. **El correo es solo la fuente de arranque**: después las remisiones se tomarán de las que las estaciones suben en Mis Recepciones y el correo deja de ser la fuente.

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

- El buzón es `jesus.olivas@totalgas.com`, ya configurado en `correoFacturas/CorreoFactruras.py` (lista `ACCOUNTS`). El script corre en el servidor 192.168.0.3 con `run_ciclo.py` cada 15 minutos y un respaldo diario a las 06:35.
- **No se ha entrado al buzón** para ver las remisiones. Hacerlo (aunque sea de solo lectura) requiere autorización expresa de Alberto/Alejandro; hasta entonces el formato de las remisiones es desconocido.
- Credenciales de ese script están en claro en `config.py` (auditoría 2026-09-08). No se agregan más; no se imprimen en logs ni en documentación.
- El despliegue de scripts de `correoFacturas` y ApiER es manual (el usuario copia los archivos al servidor).
- Base: SQL Server TG (`192.168.0.6`); el aplicativo escribe con `MySqlPdoHandler`/PDO, los scripts Python con `pyodbc`/ApiER. El asistente no escribe en la BD: los scripts SQL los corre el usuario.
- `fch` de ControlGas es serial de Excel; el vencimiento siempre es emisión + días de crédito a día hábil (nunca `fechaVto`).

## 4. Lo que sabemos hoy

- Las remisiones llegan por correo a `jesus.olivas@`. **Desconocido**: quién las manda (estaciones, transportistas, proveedores), en qué formato llegan (PDF con texto, escaneado, foto, XML) y si un correo trae una o varias remisiones.
- `FacturasRecibidas.Remision` ya guarda el BOL/remisión que viene en el CFDI (Premier, por ejemplo, lo trae en el nombre del adjunto: `... BOL 1258132.pdf`). Es la llave natural de cruce.
- Mis Recepciones ya guarda remisión y carta porte subidas por la estación en `TG.dbo.recepcion_remisiones` (ligadas a `nrotrn/codgas/fchtrn` de ControlGas, no a la factura).
- El registro ya tiene las celdas capturables `bol`, `placas`, `transportista`, `fecha_descarga` en `compras_factura_datos` y `compras_renglon_manual`.

## 5. Diseño propuesto

### 5.1 Flujo

```
Buzón jesus.olivas@  →  correoFacturas (nuevo tipo «remision»)  →  attachments/remisiones/<proveedor>/
                                  ↓
                     lector de remisión (texto PDF u OCR)
                                  ↓
                     TG.dbo.compras_remisiones  (una fila por remisión)
                                  ↓  cruce por BOL → FacturasRecibidas.Remision / uuid
                     ComprasRegistroModel::listar()  → celdas y avisos del registro
```

### 5.2 Piezas

| Pieza | Dónde | Qué hace |
|---|---|---|
| Descarga de remisiones | `correoFacturas/CorreoFactruras.py` (entrada nueva en `ACCOUNTS` por remitente, `kind: 'remision'`) | Baja adjuntos de remisión a `attachments/remisiones/<proveedor>/`; guarda `Message-ID`, remitente, asunto y fecha del correo. Idempotente. |
| Lector | `correoFacturas/extractors_remisiones.py` (nuevo) | Extrae BOL, fecha, producto, litros, transportista, placas, estación y monto. Un perfil por proveedor, como `extractors.py`. Marca `estado = por_revisar` si algún dato clave no se lee con certeza. |
| Importación | `main.py` o paso nuevo en `run_ciclo.py` (vía ApiER) | Inserta en `compras_remisiones` sin duplicar (hash del archivo + BOL). |
| Tabla | `docs/sql/compras_remisiones.sql` (lo corre el usuario) | Ver §5.3. |
| Cruce | Paso al final de la importación y botón/proceso de reintento | Liga cada remisión a su factura por BOL; si no, por proveedor + estación + fecha ±1 día + litros ±1 %. |
| Registro | `ComprasRegistroModel` + `compras.js` | Toma BOL/placas/transportista/fecha de descarga de la remisión cuando no hay captura manual; agrega avisos y un enlace al archivo. |

### 5.3 Tabla `TG.dbo.compras_remisiones` (propuesta)

`id`, `proveedor_rfc`, `proveedor`, `bol`, `folio_remision`, `fecha_descarga`, `producto`, `litros`, `monto`, `est_codgas` (si se pudo resolver), `transportista`, `placas`, `ruta_archivo`, `hash_archivo`, `message_id`, `correo_remitente`, `correo_fecha`, `uuid_factura` (nullable, el cruce), `estado` (`ligada` / `sin_factura` / `por_revisar` / `descartada`), `leido_con` (`texto` / `ocr` / `manual`), `created_at`, `updated_by`, `updated_at`. Índices por `bol`, `uuid_factura` y `(proveedor_rfc, fecha_descarga)`.

### 5.4 Reglas

- **Prioridad de datos** en una celda del registro: captura manual > remisión leída > calculado de la factura.
- **Cruce**: primero BOL exacto contra `FacturasRecibidas.Remision` del mismo proveedor; después proveedor + estación + fecha ±1 día + litros ±1 %. Más de una candidata ⇒ no se liga y queda `por_revisar`.
- **Avisos**: *factura sin remisión* (pasados N días de la descarga; N por definir con Abastos), *remisión sin factura*, *BOL no coincide* (la factura trae un BOL y la remisión otro).
- **Nada se borra**: una remisión equivocada se marca `descartada`.
- Las remisiones que hoy están como renglones manuales (sin factura) se pueden ligar a una remisión leída para cerrarlas.

## 6. Fases (cada una se aprueba y se prueba antes de la siguiente)

| Fase | Qué | Depende de |
|---|---|---|
| **R0 Descubrimiento** | Con autorización, listar de solo lectura 20–30 correos de remisión del buzón: remitentes, asuntos, formato del adjunto, cuántas remisiones por correo. Resultado: lista de proveedores/formatos y decisión texto vs OCR. | Autorización para entrar al buzón |
| **R1 Descarga** | Descargar y guardar las remisiones sin leerlas; tabla y script SQL; monitoreo (cuántas por día). | R0 |
| **R2 Lectura** | Lector por proveedor, con pruebas sobre las remisiones reales guardadas en R1; `por_revisar` cuando hay duda. | R1 |
| **R3 Cruce y registro** | Ligar a factura, mostrar BOL/placas/transportista y los tres avisos en `/compras/registro`. | R2 |
| **C1 Vencimiento** | Confirmar con Abastos fecha base y día hábil por proveedor y ajustar `ComprasRegistroModel::vencimiento()` (hoy coincide 25 % contra el Excel). | Respuesta de Abastos |
| **C2 Precio de venta** | Cargar o completar `FuelPrices` para Anapra, Puerto de Palos, Puertecito, Picachos, Ventanas y Praxedis (701 renglones sin precio). | Datos de precios |
| **C3 Exportación** | `/compras/exportar` con columnas A..BH en orden del Excel, con valores. | — |
| **C4 Proveedor final Petrotal** | Inferirlo de la conciliación Petrotal (hoy a mano, 89 renglones Tesoro) y con él el almacenaje. | Conciliación Petrotal |
| **C4b Solicitud de facturas Petrotal** | Que el sistema genere la solicitud: Abastos marca en el registro las facturas por solicitar y un botón arma y manda el correo a Marco, Christofer, Liliana, Maribel y Claudia con la factura del proveedor original adjunta (sin redactar ni adjuntar a mano) (decisión del usuario; no es automático; Petrotal no factura hasta que se lo piden). Por definir: desde qué cuenta se envía y quiénes van en cada correo. | Respuesta de Abastos |
| **C4c Deuda por empresa** | Sección nueva con la deuda a un día antes, por razón social, y un botón que arma y manda el correo a Susana, Maribel, Luis y Claudia (nombres de las notas de Alberto, por verificar). Toma saldo y vencimiento del registro; depende de cerrar C1 (vencimiento). | C1 |
| **C5 Mes en paralelo** | Un mes completo Excel + sistema comparando cada semana; criterio de retiro en la documentación §15. | R3, C1–C4 |

**Calendario estimado** (Gantt en la documentación §3.3; todo por construir): remisiones por correo (R0–R3) en 2 semanas (S1–2), solo como arranque; después se toman de Mis Recepciones (S3); después, con varias etapas en paralelo: igualar columnas y perfiles (S3), cruce contra facturas (S3–4), cruce contra ControlGas (S4), vencimiento y pago programado (S3–4), precio de venta (S4), botón Excel (S4), proveedor final de Petrotal (S4–5), solicitud de facturas a Petrotal (S5), deuda por empresa (S5–6), mes en paralelo (S6–9) y retiro del Excel (S9). Total: dos meses (9 semanas).

R0–R3 y C1–C4 son independientes entre sí; el orden recomendado es **R0 → C1 → C3 → R1…R3 → C2 → C4 → C5** porque C1 y C3 no dependen del buzón y desbloquean la comparación en paralelo.

## 7. Qué queda a criterio de quien construya

- Lectura: texto del PDF (`pdfplumber`/`pdftotext`) cuando se pueda; OCR solo si R0 muestra remisiones escaneadas (qué motor, por decidir con evidencia de R0).
- Nombres exactos de archivos, índices y mensajes de log.
- Cómo mostrar el enlace al PDF en la tabla (icono en la celda de BOL).

## 8. Pruebas

- Lector: pruebas con remisiones reales guardadas (conjunto de muestra por proveedor, sin datos sensibles en el repositorio) con el resultado esperado escrito a mano.
- Cruce: casos de BOL exacto, BOL con ceros a la izquierda, dos candidatas, sin factura, factura con BOL distinto.
- Registro: `php tests/ComprasCalculosTest.php` sigue pasando; prueba nueva del orden de prioridad de datos.
- Humano: Alberto revisa una semana de remisiones leídas contra las que él habría tecleado.

## 9. Riesgos

| Riesgo | Mitigación |
|---|---|
| Remisiones escaneadas ilegibles | `por_revisar` + captura manual; R0 mide cuántas son. |
| Un correo con varias remisiones | R0 lo detecta; una fila por remisión, no por correo. |
| Duplicados por reintento IMAP | Idempotencia por `message_id` + hash + BOL. |
| Cuelgues IMAP (ya hubo, 2026-11-05) | Reutilizar el timeout y la reconexión que ya tiene `CorreoFactruras.py`. |
| Credenciales en claro | No agregar nuevas; recomendación de moverlas a variables de entorno, fuera de alcance aquí. |

## 10. Preguntas que solo pueden contestar Alberto o Alejandro

1. ¿Autorizan R0 (entrar de solo lectura al buzón `jesus.olivas@` a ver las remisiones)? Sin esto no se puede definir el lector.
2. ¿Quién manda las remisiones (estaciones, transportistas, proveedores) y de qué proveedores llegan por correo?
3. ¿Cuántos días después de la descarga debe saltar el aviso «factura sin remisión»?
4. ¿Reesa llega por correo y cómo se integra al pago a proveedores (Alberto lo marcó como pendiente)?
5. Vencimiento: ¿qué fecha base y qué ajuste de día hábil usa Abastos para Tesoro, MGC y AEMSA? El Excel y el sistema difieren de −1 a +6 días.
