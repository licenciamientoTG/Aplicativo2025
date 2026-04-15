# EPIC — Módulo de Remisiones Petrotal

## Epic

| Campo        | Detalle |
|-------------|---------|
| **Título**  | Módulo de Remisiones Petrotal |
| **Área**    | Contabilidad |
| **Descripción** | Desarrollo de un módulo dentro del área de Contabilidad que permite al usuario cargar un archivo Excel con información de remisiones de entrega de productos petrolíferos, visualizar los registros en una tabla interactiva y generar documentos de remisión listos para revisión en línea e impresión, con el formato oficial de PETROTAL, S.A. DE C.V. |

---

## Actividad 1 — Configuración base del módulo

| Campo        | Detalle |
|-------------|---------|
| **Título**  | Configuración base del módulo de Remisiones Petrotal |
| **Tipo**    | Task |
| **Epic**    | Módulo de Remisiones Petrotal |
| **Descripción** | Crear la estructura base del módulo: agregar el enlace "Remisiones Petrotal" en la sección de Contabilidad del sidebar, crear la función `remisiones_petrotal()` en el controlador `accounting.php` y generar la vista `views/accounting/remisiones_petrotal.html` extendiendo el template base de la aplicación. |
| **Criterios de aceptación** | - El enlace aparece en el sidebar bajo la sección Contabilidad. - La ruta `/accounting/remisiones_petrotal` carga la vista correctamente. - La vista extiende el layout base con título "Remisiones Petrotal". |

---

## Actividad 2 — Carga de archivo Excel y visualización en DataTable

| Campo        | Detalle |
|-------------|---------|
| **Título**  | Carga de archivo Excel y visualización en DataTable |
| **Tipo**    | Task |
| **Epic**    | Módulo de Remisiones Petrotal |
| **Descripción** | Implementar en la vista un componente de carga de archivo Excel (con soporte de drag & drop) que procese el archivo completamente en el cliente usando SheetJS. Los datos deben desplegarse en un DataTable de jQuery con las columnas: Lugar, Fecha, Razón Social, RFC, Dirección Fiscal, Cantidad, Descripción, Lugar físico de entrega, Fecha deseada de entrega, Domicilio estación, Permiso de estación y Razón Social de estación. El procesamiento debe detectar dinámicamente la fila de encabezados del Excel para ignorar filas vacías o de títulos previas a los datos. |
| **Criterios de aceptación** | - Se acepta únicamente archivos `.xlsx` y `.xls`. - Soporta arrastrar y soltar el archivo. - Los datos se muestran correctamente en el DataTable sin incluir la fila de encabezados ni filas vacías. - El DataTable incluye botones de exportación a Excel, PDF y Copiar. - Un badge muestra el total de registros cargados. - El botón "Limpiar" resetea la vista al estado inicial. |

---

## Actividad 3 — Generación de documentos de remisión en HTML

| Campo        | Detalle |
|-------------|---------|
| **Título**  | Generación de documentos de remisión en HTML para impresión |
| **Tipo**    | Task |
| **Epic**    | Módulo de Remisiones Petrotal |
| **Descripción** | Implementar el botón "Generar remisiones" que abre una nueva pestaña del navegador con todos los documentos de remisión generados en HTML, listos para revisión en línea e impresión. Cada remisión debe renderizarse como un documento tamaño carta con el formato oficial de PETROTAL, S.A. DE C.V., incluyendo logo, datos del cliente, descripción del producto, lugar de entrega, datos de la estación e información de recepción. La ventana debe incluir una barra de herramientas con el botón "Imprimir todo". El botón debe respetar los filtros activos del DataTable y generar únicamente los registros visibles. |
| **Criterios de aceptación** | - Se abre una nueva pestaña con los documentos generados. - Cada registro del DataTable (filtrado) genera un documento independiente. - La barra de herramientas muestra el total de remisiones generadas y el botón de impresión. - Cada documento incluye todas las secciones requeridas con sus datos correctos. - El campo Cantidad se formatea con separadores de miles y tres decimales. - Los documentos se separan con salto de página al imprimir (`page-break-after: always`). |

---

## Actividad 4 — Diseño y estilos del documento de remisión

| Campo        | Detalle |
|-------------|---------|
| **Título**  | Diseño y estilos del documento de remisión oficial |
| **Tipo**    | Task |
| **Epic**    | Módulo de Remisiones Petrotal |
| **Descripción** | Definir e implementar el diseño visual del documento de remisión conforme a la identidad de PETROTAL, S.A. DE C.V. El documento debe incluir: cabecera con logo de Petrotal a la derecha y nombre/RFC de la empresa centrados en azul corporativo (`#0067B0`); título del documento "REMISION DE ENTREGA DE PRODUCTOS PETROLÍFEROS"; encabezados de sección con fondo azul (`#0067B0`) y texto blanco; footer fijo al pie de página con dirección fiscal y teléfono, separado por línea azul. Los estilos de color deben preservarse al imprimir mediante `print-color-adjust: exact`. El footer debe permanecer al fondo de la página tanto en pantalla como en impresión usando posicionamiento absoluto en el contexto de impresión. |
| **Criterios de aceptación** | - El logo de Petrotal aparece en la esquina superior derecha. - El nombre y RFC de la empresa se muestran centrados en color `#0067B0`. - Los divs de sección tienen fondo `#0067B0` con texto blanco, tanto en pantalla como al imprimir. - El footer siempre aparece en la parte inferior de cada página impresa. - El documento se ve correctamente en navegadores Chrome, Edge y Firefox. |
