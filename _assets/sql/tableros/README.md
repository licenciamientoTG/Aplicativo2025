# Módulo Tableros

## Despliegue

- `001_create_schema.sql` crea la base `tableros` y los objetos del módulo. Es aditivo e idempotente.
- `002_seed_tg_permissions.sql` registra los permisos en `TG.dbo.tg_permissions`; no asigna permisos a personas.
- `003_rollback_notes.sql` contiene consultas de revisión. Las instrucciones destructivas están comentadas y no deben ejecutarse como parte de un despliegue normal.
- `004_workspace_hierarchy.sql` actualiza el espacio inicial **General** a visibilidad de equipo y limita las carpetas a tres niveles, igual que Monday. Conserva los tableros privados y no elimina contenido.
- `005_file_scan_availability.sql` habilita el estado `unscanned` y convierte las versiones que esperaban aprobación manual para que puedan descargarse. Ejecutar junto con el despliegue de la nueva aplicación.
- `006_file_comments.sql` agrega comentarios asociados al archivo lógico para mostrarlos en el panel de vista previa. Es aditivo e idempotente; ejecutar después de `001_create_schema.sql`.
- `007_comment_mentions_notifications.sql` agrega menciones opcionales a comentarios, vínculos de archivos adjuntos a comentarios y notificaciones dentro de la aplicación. Es aditivo e idempotente; ejecutar después de `006_file_comments.sql`.
- La conexión de la aplicación a `tableros` reutiliza la configuración SQL existente sin cambiar la conexión compartida de los demás modelos.

Después de desplegar el código, Sistemas debe asignar en `/it` los permisos **Tableros - Acceso**, **Tableros - Crear** y **Tableros - Administrar** a las personas correspondientes. El módulo no concede acceso automáticamente.

## Automatizaciones

Programar `php cron/tableros_automation_worker.php` para que se ejecute cada minuto en el servidor de la aplicación, bajo la identidad que tenga acceso a SQL Server. El worker usa un bloqueo de aplicación para evitar ejecuciones simultáneas.

## Archivos

Por defecto, los archivos se guardan dentro del proyecto en `uploads/tableros/`, una carpeta ignorada por Git. La estructura es `tableros/{id_tablero}/{id_elemento}/{año}/{mes}/{fragmento_hash}/{hash}`, sin usar el nombre original como ruta. El acceso HTTP directo está bloqueado en `web.config` (IIS) y `.htaccess` (Apache); las descargas se sirven por la ruta autorizada del módulo. Para cambiar el destino, `TABLEROS_PRIVATE_STORAGE` puede apuntar a una ruta absoluta escribible fuera de la raíz pública y del directorio del proyecto.

El código permite cargas de hasta 100 MB, restringe extensiones y tipo MIME, y convierte las imágenes JPG, PNG, GIF y WebP a WebP antes de guardarlas. La imagen se guarda con extensión `.webp`; por seguridad de memoria, imágenes de resolución excesiva para la configuración PHP del servidor se rechazan. Los archivos quedan disponibles inmediatamente y no se analizan con antivirus. PHP debe permitir `upload_max_filesize=100M`, `post_max_size=110M` y contar con al menos 512 MB de memoria para imágenes grandes (valores fijados en `.htaccess` y `.user.ini`; IIS debe respetar `.user.ini` o configurar su `php.ini`). Los archivos guardados con el formato anterior de clave hash siguen siendo resolubles.

## Monday

La importación de proyectos desde Monday no forma parte del módulo. Los proyectos existentes se importarán por el proceso externo a cargo del equipo.

## Orden de actualización

En instalaciones existentes, ejecutar `004_workspace_hierarchy.sql`, `005_file_scan_availability.sql`, `006_file_comments.sql` y `007_comment_mentions_notifications.sql`, en ese orden. Los scripts son idempotentes; `005` actualiza solamente estados `pending` a `unscanned`. En instalaciones nuevas, ejecutar `001_create_schema.sql`, `006_file_comments.sql` y `007_comment_mentions_notifications.sql` en ese orden.

`007` requiere que existan `tb_board`, `tb_item`, `tb_file`, `tb_comment` y `tb_file_comment`; no ejecuta cambios de datos existentes. Los IDs de usuario (`user_id` y `actor_user_id`) se guardan como enteros sin FK porque pertenecen a TG. La aplicación debe validar que cada archivo adjunto pertenezca al mismo tablero/elemento del comentario y que el usuario tenga acceso al archivo privado. Los vínculos sí tienen FKs locales a los registros de archivo y comentario.

La compatibilidad es aditiva: `mentions_json` admite `NULL`, las tablas nuevas no cambian lecturas/escrituras previas, y `read_at`/`dedupe_key` son opcionales. Desplegar primero la migración y después el código que escriba estos campos/tablas. Para rollback de aplicación, volver al código anterior y dejar el esquema instalado; no hay rollback destructivo automático. Si se decide retirar la función, respaldar/exportar antes de eliminar `tb_notification` y `tb_file_comment_attachment`; borrar esas tablas elimina permanentemente notificaciones y asociaciones de adjuntos. Eliminar `mentions_json` también descartaría cualquier contenido de menciones guardado.
