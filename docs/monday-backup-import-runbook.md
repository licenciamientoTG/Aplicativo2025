# Respaldo de Monday hacia Tableros

## Alcance acordado

- Incluir tableros `active` y `archived`; excluir `deleted`.
- Copiar workspaces, espacios, carpetas anidadas, tableros, grupos, columnas, elementos/subelementos y valores.
- Crear todos los tableros con `created_by = 6371` (`danieltg25`) y `visibility = 'private'`.
- Mantener columnas de asignación de personas/equipos. Preservar el JSON fuente en `tb_cell.value_json`, sin resolverlo a usuarios TG ni crear asignaciones locales.
- Descargar los adjuntos por la API de Monday, conservar el original en NAS y generar WebP pequeño/grande al importar.

## Comprobaciones de origen y destino

- El workbook contiene 16 claves distintas. Quince claves vigentes respondieron correctamente; la clave de la fila 31 devolvió HTTP 401 y, según la revisión del usuario, corresponde a una clave inexistente. Se registra como `excluded_nonexistent_key`; se excluye deliberadamente, no se guarda ni imprime su valor. La cobertura del conjunto de 15 claves existentes queda completa.
- El inventario paginado con API Monday `2026-07` observó 391 tableros activos, 113 archivados y 296 eliminados en esas 15 claves. El alcance importable es de 504 IDs observados; `deleted` queda fuera. La ejecución registra por separado conteos, hash del inventario, fecha de corte y manifiesto de cobertura para impedir reusar un `run-key` con un origen distinto. No deduplicar ni conciliar solo por nombre.
- El usuario TG `danieltg25` existe con ID 6371.
- La base `tableros` responde por PDO. El tablero piloto local 11 (`Exteriores ARCO`) está privado y tiene `created_by = 6371`; hay cuatro tableros locales activos antes del respaldo.
- IIS publica la aplicación en `http://192.168.0.3:400`. Su configuración usa `nas_service` en `http://127.0.0.1:4400/`; el servicio NAS no acepta conexiones desde otra máquina y el recurso compartido NAS no es escribible con la identidad local.

## Diseño de reanudación

1. Crear un `run_id` persistente con versión API, fecha de corte, filtros de estado, claves/cuentas de origen, creador local y resumen/integridad del inventario. Guardar el manifiesto sin claves API, URLs firmadas ni tokens.
   El CLI fija `inventory_complete` solo cuando coinciden 504 IDs activos/archivados y la cobertura de las 15 claves existentes; la exclusión documentada de fila 31 no cuenta como falla. Si se reusa un `run-key`, deben coincidir hash del inventario/cobertura, fecha de corte UTC, API version y conteos; si no, se aborta y se requiere otra clave de ejecución.
2. Mantener tablas de ejecución y mapa con restricciones únicas: `(source_account_id, entity_type, monday_id) -> local_id`. Guardar estado (`pending`, `in_progress`, `complete`, `failed`), hash de payload, marca de actualización y último error sanitizado. La clave no será el nombre, que puede repetirse. Si cambia el hash fuente, volver a procesar esa entidad y sus dependencias afectadas.
3. Procesar en orden workspace/espacio, carpetas, tablero, grupos/columnas, elementos/subelementos, valores y adjuntos. Confirmar cada tablero/lote en transacciones locales. Mantener fases explícitas para permitir reanudar sin asumir que un paso anterior terminó por completo.
4. Si se corta el proceso, retomar desde el mapa persistente. Para cursores `items_page` vencidos, volver a solicitar páginas desde el inicio y omitir IDs ya confirmados por el mapa; los cursores de Monday caducan a los 60 minutos.
5. Aplicar backoff a errores de cuota y registrar errores por entidad sin registrar tokens, URLs firmadas ni contenido de celdas. Sanitizar respuestas y limitar tamaño de logs.
6. Crear las columnas locales mediante un mapeo explícito a los tipos admitidos por `tb_column`. Conservar metadatos originales permitidos en JSON. Para `people`, `person` y `team`, guardar el valor JSON de Monday en `tb_cell.value_json`; es dato de origen sin asociación a usuarios TG. No insertar filas de asignación en `tb_item_person` ni inventar equivalencias. Crear tableros con `created_by = 6371` y `visibility = 'private'` desde su inserción y validar ambos campos antes de marcar el tablero como completo.
7. Asociar adjuntos a su elemento/columna local. Descargar el activo desde Monday inmediatamente después de obtener su URL temporal (las URLs públicas son temporales), calcular SHA-256, subir el original al servicio NAS y solicitar/generar allí los derivados WebP. El servicio NAS acepta archivos de hasta 500 MiB y la ruta de ejecución debe usar el token Bearer sin imprimirlo ni persistirlo en manifiestos/logs; restringirla al host IIS o a un canal interno autorizado. Confirmar SQL solo después de verificar tamaño/hash del original y existencia de derivados requeridos.
8. Registrar cada archivo con su propio estado y clave determinista. Si el NAS PUT se completa pero falla SQL, reintentar idempotentemente usando la misma clave, verificar el objeto existente y completar el registro; si falla una derivación, dejar el adjunto pendiente/error y regenerar derivados sin duplicar el original. Si falla el upload parcial, limpiar el objeto incompleto mediante la operación soportada por NAS antes de reintentar. No declarar el tablero completo mientras tenga archivos pendientes.
9. Resolver relaciones entre tableros/elementos en una segunda pasada después de crear todos los IDs locales. Aplicar límites y colisiones de nombres mediante reglas deterministas; registrar las colisiones sin perder el ID fuente.
10. Después de importar y verificar cada tablero, registrar los tableros fuente `archived` mediante `TablerosModel::trashEntity(..., 'archive')` usando contexto autenticado del actor 6371 y el ID local. No marcar `deleted_at` directamente. La transición de archivo debe ser un paso reintentable separado; si falla, el tablero queda incompleto y visible en el reporte de errores.

## Reconciliación de cierre

- Comparar conjuntos fuente/destino por ID de Monday y estado, excluyendo explícitamente `deleted`; el cierre solo es válido para las claves/cuentas cuya paginación y cobertura se hayan documentado como completas.
- Por tablero: reconciliar IDs y conteos de grupos, columnas, elementos/subelementos, checkpoints de celdas y checkpoints/bytes/derivados de adjuntos antes de permitir estado `complete`. Los valores de personas/equipos quedan preservados como JSON sin asignaciones TG.
- Por adjunto: validar que exista fila local, original NAS con igual tamaño/SHA-256 y derivados WebP para imágenes. Comparar cantidad de adjuntos de origen y destino e informar archivos omitidos o no disponibles.
- Confirmar creador `6371`, visibilidad privada y estado archivado/activo de cada tablero.
- La repetición del mismo `run_id` no debe crear filas ni objetos duplicados. Inyectar/ensayar cortes en los límites NAS/SQL antes de producción y comprobar la recuperación por clave determinista.
- Entregar reporte final con inventario de origen, cobertura, conteos por entidad/estado, diferencias por ID, adjuntos fallidos, colisiones y errores sanitizados.

El importador conserva folders anidados consultando cada folder padre por ID y mapea el árbol con `parent_folder_id`. Ante colisiones de nombre, agrega sufijos deterministas basados en ID Monday; los tableros duplicados por nombre en el inventario siempre llevan sufijo para que la resolución no dependa del orden. Reporta el conteo de tableros renombrados y nunca descarta un board por colisión.

## Preparación operativa pendiente

El CLI está implementado en `tools/monday_import_cli.php`, con `--dry-run` predeterminado y `--apply` explícito. La ejecución real sigue pendiente de revisión del orquestador. En el host IIS se debe validar que el proceso PHP CLI reciba las variables FastCGI necesarias (`TABLEROS_STORAGE_DRIVER`, URL/token NAS y la conexión SQL) sin imprimirlas ni copiarlas al workbook/manifiesto. No se han ejecutado escrituras de importación en SQL Server o NAS durante la preparación del CLI.

## Límites del alcance

El alcance acordado cubre jerarquía, tableros, grupos, columnas, elementos/subelementos, valores y adjuntos. No incluye actualizaciones/comentarios, actividad, automatizaciones, vistas ni configuraciones visuales salvo que se agreguen expresamente al alcance. Los tableros archivados se importan completos y luego se archivan en Tableros.
