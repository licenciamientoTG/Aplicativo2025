# Reporte semanal de obligación estadística Petrotal (CNE) — Diseño

**Fecha:** 2026-09-17
**Estado:** Aprobado para implementación

## Contexto

PETROTAL SA DE CV (permiso `H/22730/COM/2019`) es Comercializador de petrolíferos y tiene
la obligación semanal ante la CNE (Comisión Nacional de Energía) de reportar inventarios,
ventas nacionales y compras nacionales de los productos que comercializa. Hoy esa obligación
se cumple manualmente: alguien descarga dos Excel (`Reporte_de_Volumenes_de_Compra_Nacional`,
`Reporte_de_Volumenes_de_Venta_Nacional`) y los carga a mano en el portal OPE de la CNE.

La CNE está migrando esta obligación a un servicio REST ("API de Masivos") que recibe un
archivo JSON firmado con la FIEL del representante legal (Alfredo Escalera Baca,
RFC `EABA641109B7A`). El ambiente de pruebas (QA) es intermitente — **según observación del
usuario, solo responde los viernes** — así que el objetivo inmediato es dejar todo el flujo
listo para poder probarlo apenas el ambiente esté disponible.

Se validó (ver conversación previa) que los datos de `TG.dbo.FacturasRecibidas` +
`FacturasRecibidasConceptos`, filtrando por el RFC de Petrotal (`PET180213L66`) como emisor
(ventas) y receptor (compras), reproducen exactamente los volúmenes que Petrotal ya declaró
a la CNE en agosto 2026 (4 semanas comparadas, coincidencia en barriles usando el factor
158.987 L/bbl). También se confirmó que el catálogo de permisos CRE/COM de las contrapartes
**ya existe en el sistema** — no hace falta crear un catálogo nuevo:

- **Clientes (ventas)** → `TG.dbo.XmlCre` (columnas `Rfc`, `NumeroPermisoCRE`), catálogo de
  estaciones de servicio con volumétricos.
- **Proveedores (compras)** → `SG12.dbo.Proveedores` (columna `nropcc` = permiso
  comercializador CNE, `nroptc` = permiso transportista).

## Alcance

Un módulo nuevo, autocontenido, dentro de la aplicación PHP MVC existente:

1. Pantalla que, dado un periodo (semana lunes-domingo), arma una vista previa de lo que se
   reportaría a la CNE, permitiendo revisar antes de enviar.
2. Generación del JSON final en el formato esperado por la API (`ReporteObligacion`).
3. Envío del JSON firmado (multipart con `.cer`/`.key`/password) al endpoint de la CNE.
4. Guardado en disco + BD del JSON enviado y del acuse de respuesta.
5. Historial de envíos previos, con posibilidad de reconsultar/descargar.
6. Pantalla de configuración (una sola vez) para subir la FIEL (.cer/.key/password).

Fuera de alcance de este spec: automatizar el envío recurrente sin intervención humana
(cron), soportar productos fuera de Gasolina Regular/Premium y Diésel si aparecen nuevos
(se diseña extensible pero no se anticipan catálogos que hoy no existen), inventarios (los
acuses de agosto siempre declaran `AceptaNoInventario: true`, Petrotal no reporta
inventarios).

## Arquitectura

### Controlador y modelos nuevos

- `_assets/controllers/petrotal.php` — nuevo controlador. Métodos principales:
  - `volumetricos()` — pantalla principal (selector de periodo + preview + acciones).
  - `preview_json($desde, $hasta)` (AJAX) — arma y devuelve el JSON candidato para el
    periodo, junto con advertencias (RFC sin permiso resuelto, productos no clasificados).
  - `enviar_reporte()` (POST) — dispara el envío real a la API de la CNE.
  - `historial()` — lista de envíos previos.
  - `configuracion_fiel()` / `guardar_fiel()` — subir/actualizar `.cer`/`.key`/password.
  - `descargar_json($id)` / `descargar_acuse($id)` — recuperar archivos guardados de un
    envío histórico.

- `_assets/models/PetrotalObligacionModel.php` — nuevo modelo, responsable de:
  - Construir el reporte: consultar `FacturasRecibidas`/`FacturasRecibidasConceptos` por
    periodo y RFC de Petrotal, clasificar producto por descripción, convertir litros→bbl,
    resolver cliente (`XmlCre`) y proveedor (`SG12.Proveedores.nropcc`), armar el arreglo
    PHP con la forma del JSON (`Permiso → Fecha → Producto → VentasNacional/ComprasNacional`).
  - CRUD de la tabla de historial (`PetrotalReportesObligacion`, ver más abajo).
  - No conoce HTTP ni firma — eso lo hace un servicio aparte.

- `_assets/models/PetrotalFielModel.php` — nuevo modelo, responsable de:
  - Guardar/leer configuración de la FIEL (ruta de `.cer`, ruta de `.key`, password
    cifrado) en una tabla `PetrotalFielConfig`.

- `_assets/classes/PetrotalCneClient.class.php` — nueva clase de infraestructura (no un
  modelo de datos), responsable de:
  - Hacer el POST multipart a `https://api-masivos-qa.cne.gob.mx/ReporteObligacion` con
    cURL, adjuntando el JSON generado, `.cer`, `.key`, password y `permiso`.
  - Decodificar la respuesta (`rc`, `msg`, `acuse`, `linkDescarga`) o capturar errores de
    red/HTTP (502, timeout) de forma clara.
  - Descargar el acuse desde `linkDescarga` cuando el envío es exitoso.

Esta separación (modelo de datos vs. cliente HTTP) sigue el patrón ya usado en
`CotizacionesModel.php` (cURL dentro de un modelo) pero aislado en una clase propia porque
aquí hay lógica de firma/multipart más compleja que amerita su propio archivo, reusable si
en el futuro se agrega la función `ReporteObligacionEmpresa`.

### Datos nuevos en TG

```sql
CREATE TABLE PetrotalReportesObligacion (
    Id INT IDENTITY PRIMARY KEY,
    PeriodoDesde DATE NOT NULL,
    PeriodoHasta DATE NOT NULL,
    Estado VARCHAR(20) NOT NULL,        -- 'borrador', 'enviado', 'error', 'acuse_recibido'
    RutaJson VARCHAR(500) NULL,
    RutaAcuse VARCHAR(500) NULL,
    FolioAcuse VARCHAR(50) NULL,
    RespuestaRaw NVARCHAR(MAX) NULL,    -- respuesta cruda de la API (rc/msg/acuse) para auditoría
    UsuarioId INT NOT NULL,
    FechaEnvio DATETIME NULL,
    CreatedAt DATETIME NOT NULL DEFAULT GETDATE(),
    UpdatedAt DATETIME NOT NULL DEFAULT GETDATE()
);

CREATE TABLE PetrotalFielConfig (
    Id INT IDENTITY PRIMARY KEY,
    RutaCer VARCHAR(500) NOT NULL,
    RutaKey VARCHAR(500) NOT NULL,
    PasswordCifrado VARCHAR(500) NOT NULL,  -- cifrado con openssl_encrypt, clave en header.class.php
    ActualizadoPor INT NOT NULL,
    UpdatedAt DATETIME NOT NULL DEFAULT GETDATE()
);
```

Solo un registro activo en `PetrotalFielConfig` a la vez (se sobreescribe al resubir).
Ambas tablas van en TG — no se toca SG12 ni `devTotalGas` en absoluto (son de solo lectura
para este módulo).

### Almacenamiento de archivos

Nueva carpeta fuera de webroot público, siguiendo el patrón de `RutaArchivo` en
`FacturasRecibidas`:

```
_uploads/petrotal/
  fiel/           <- .cer y .key subidos (no descargables desde el navegador)
  json/           <- copia del JSON enviado, nombrado {PeriodoDesde}_{PeriodoHasta}_{Id}.json
  acuse/          <- acuse recibido (PDF si linkDescarga lo entrega, o el JSON de respuesta)
```

### Construcción del reporte (lógica central)

1. **Clasificación de producto** por descripción del concepto de factura (ya validado):
   - Contiene "MAXIMA" o "REGULAR" → ProductoId=7, SubProductoId=13 (Regular)
   - Contiene "SUPER" o "PREMIUM" → ProductoId=7, SubProductoId=14 (Premium)
   - Contiene "DIESEL"/"DIÉSEL" → ProductoId=3, SubProductoId=62 (Diésel UBA) — único
     subproducto de Diésel visto en los acuses de agosto.
   - Cualquier otra descripción → excluida del reporte y listada como advertencia en el
     preview (no se asume un producto por defecto).

2. **Conversión de unidades**: `VolumenBarriles = round(Cantidad_litros / 158.987, 2)`.

3. **Resolución de fecha (`DiaReporte`)**: se usa `FacturasRecibidas.Fecha` tal cual. El
   desfase de 1-3 días observado entre fecha de factura y `DiaReporte` real de Petrotal es
   inherente a cuándo se timbra cada factura — no se intenta "corregir" la fecha; el preview
   muestra la fecha de factura y el usuario puede ajustar el rango del periodo si hace falta
   incluir/excluir alguna al límite de la semana.

4. **Resolución de contraparte**:
   - Ventas: `JOIN XmlCre ON FacturasRecibidas.ReceptorRfc = XmlCre.Rfc` → `NumeroPermisoCRE`.
     Si el RFC tiene más de un `NumeroPermisoCRE` (caso Estación Custodia, Díaz Gas), se
     resuelve por coincidencia con el nombre/dirección en `FacturasRecibidas.Destino`; si no
     se puede desambiguar, se marca como advertencia y se deja que el usuario elija
     manualmente en el preview.
   - Compras: `JOIN SG12.Proveedores ON FacturasRecibidas.EmisorRfc = Proveedores.rfc` →
     `nropcc` (PermisoCOM/CRE del proveedor). Si `nropcc` viene vacío, se marca como
     advertencia — no se envía esa línea hasta resolverse.
   - Todas las contrapartes vistas hasta ahora son `TipoCliente = Permisionario CRE`
     (`PermisionarioCRECliente`/`PermisionarioCREProveedor` en el JSON), no `UsuarioFinal` —
     se construye siempre bajo ese nodo salvo que se detecte lo contrario en el futuro.

5. **Filtrado de proveedores/emisores relevantes**: el preview solo considera facturas cuyo
   emisor (compras) o receptor (ventas) tenga, ya sea en `XmlCre` o en
   `SG12.Proveedores.nropcc`, un permiso resuelto — así se excluyen automáticamente
   proveedores de Petrotal no relacionados con esta obligación (ej. MGC, Premiergas, Lobo,
   Enerey vistos en la exploración, que no aparecieron en los acuses de agosto).

### Flujo de pantalla (`/petrotal/volumetricos`)

1. Selector de semana (lunes-domingo). Al cargar, sugiere automáticamente la última semana
   cerrada que no tenga ya un registro en `PetrotalReportesObligacion` con estado distinto
   de `borrador`/`error`.
2. Al elegir/confirmar el periodo, AJAX a `preview_json` construye y muestra:
   - Tabla de ventas (fecha, producto, cliente, permiso resuelto, volumen bbl, precio).
   - Tabla de compras (misma estructura, proveedor).
   - Bloque de advertencias (RFC sin permiso, descripciones no clasificadas).
3. Botón "Generar JSON" — congela la selección actual en un JSON descargable/visible en
   pantalla (no se guarda todavía; es solo para revisión humana).
4. Botón "Enviar a CNE" — solo habilitado si no hay advertencias bloqueantes pendientes.
   Siempre visible independientemente del día. Al presionar:
   - Se intenta el POST real vía `PetrotalCneClient`.
   - Si la API no responde (502/timeout), se muestra el error tal cual y se guarda el
     intento en `PetrotalReportesObligacion` con `Estado = 'error'` (guardando también el
     JSON generado, para no perder el trabajo de preview).
   - Si responde `rc=0`, se guarda `Estado = 'enviado'`, se descarga el acuse desde
     `linkDescarga` y se actualiza a `Estado = 'acuse_recibido'` con `FolioAcuse` y
     `RutaAcuse`.
   - Si responde `rc=-1`, se muestra el `msg` de error de la CNE y se guarda como `error`
     con la `RespuestaRaw` completa para diagnóstico.
5. Pantalla de historial (`/petrotal/historial`): tabla de periodos ya procesados, con
   enlaces para descargar el JSON guardado y el acuse, y para reabrir el preview de un
   periodo en `borrador`/`error` y reintentar.

### Sidebar

Nueva sección propia, permiso raíz `98` ("Ver módulo Petrotal"):

```
{% if authorized(98) %}
<li class="sidebar-header">PETROTAL</li>
<li class="sidebar-item">
  <a class="sidebar-link" href="/petrotal/volumetricos">
    <i data-feather="upload-cloud"></i>
    <span class="align-middle">Reporte CNE / Volumétricos</span>
  </a>
</li>
<li class="sidebar-item">
  <a class="sidebar-link" href="/petrotal/historial">
    <i data-feather="archive"></i>
    <span class="align-middle">Historial de envíos</span>
  </a>
</li>
{% if authorized(99) %}
<li class="sidebar-item">
  <a class="sidebar-link" href="/petrotal/configuracion_fiel">
    <i data-feather="key"></i>
    <span class="align-middle">Configuración FIEL</span>
  </a>
</li>
{% endif %}
{% endif %}
```

Dos permisos nuevos: `98` (ver módulo, department "Petrotal") y `99` (configurar FIEL,
más restrictivo — solo quien administre las credenciales de Alfredo Escalera).

## Manejo de errores

- **API caída (502/timeout)**: mensaje claro en pantalla ("CNE no disponible en este
  momento, intenta más tarde — recuerda que el ambiente QA solo responde los viernes"), sin
  perder el JSON ya generado (queda guardado como intento fallido, reintentable).
- **RFC sin permiso resuelto**: no se excluye silenciosamente — se lista como advertencia
  visible y bloquea el botón de enviar hasta que el usuario decida (excluir la línea
  manualmente o resolver el catálogo).
- **Error de firma/password FIEL**: la respuesta de la API ya trae mensajes específicos
  ("el rfc del certificado ... no corresponde al esperado ...", "Api Key no valida") — se
  muestran tal cual, sin reinterpretarlos.

## Testing

No hay framework de tests en este proyecto (confirmado en CLAUDE.md). Verificación manual:

1. Con datos de agosto ya en BD, generar preview para las 4 semanas y comparar contra los
   4 acuses ya leídos — deben coincidir en volumen por producto (ya validado en la
   conversación, pero se repite como prueba de humo del código nuevo).
2. Probar el envío real contra QA un viernes (única ventana conocida de disponibilidad).
3. Verificar que un error de red (simulable apagando temporalmente conectividad o contra un
   endpoint inválido) no deja el sistema en estado inconsistente — el registro debe quedar
   en `error` con el JSON generado accesible para reintento.
