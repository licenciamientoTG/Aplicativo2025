# Módulo Tableros

## Despliegue

- `001_create_schema.sql` crea la base `tableros` y los objetos del módulo. Es aditivo e idempotente.
- `002_seed_tg_permissions.sql` registra los permisos en `TG.dbo.tg_permissions`; no asigna permisos a personas.
- `003_rollback_notes.sql` contiene consultas de revisión. Las instrucciones destructivas están comentadas y no deben ejecutarse como parte de un despliegue normal.
- `004_workspace_hierarchy.sql` actualiza el espacio inicial **General** a visibilidad de equipo y limita las carpetas a tres niveles, igual que Monday. Conserva los tableros privados y no elimina contenido.
- La conexión de la aplicación a `tableros` reutiliza la configuración SQL existente sin cambiar la conexión compartida de los demás modelos.

Después de desplegar el código, Sistemas debe asignar en `/it` los permisos **Tableros - Acceso**, **Tableros - Crear** y **Tableros - Administrar** a las personas correspondientes. El módulo no concede acceso automáticamente.

## Automatizaciones

Programar `php cron/tableros_automation_worker.php` para que se ejecute cada minuto en el servidor de la aplicación, bajo la identidad que tenga acceso a SQL Server. El worker usa un bloqueo de aplicación para evitar ejecuciones simultáneas.

## Archivos

Por defecto, los archivos se guardan dentro del proyecto en `uploads/tableros/`, una carpeta ignorada por Git. La estructura es `tableros/{id_tablero}/{id_elemento}/{año}/{mes}/{fragmento_hash}/{hash}`, sin usar el nombre original como ruta. El acceso HTTP directo está bloqueado en `web.config` (IIS) y `.htaccess` (Apache); las descargas se sirven por la ruta autorizada del módulo. Para cambiar el destino, `TABLEROS_PRIVATE_STORAGE` puede apuntar a una ruta absoluta escribible fuera de la raíz pública y del directorio del proyecto.

El código permite archivos de hasta 25 MB y restringe extensiones y tipo MIME. Las versiones nuevas quedan pendientes; un propietario o diseñador debe aprobar manualmente la versión vigente para habilitar su descarga. Esa aprobación no es un análisis antivirus. Los archivos guardados con el formato anterior de clave hash siguen siendo resolubles.

## Monday

La importación de proyectos desde Monday no forma parte del módulo. Los proyectos existentes se importarán por el proceso externo a cargo del equipo.

## Orden de actualización

En instalaciones existentes, ejecutar `004_workspace_hierarchy.sql` una sola vez después de los scripts base. El script es idempotente, no borra datos y agrega una validación de profundidad/ciclos para carpetas. En instalaciones nuevas, `001_create_schema.sql` ya crea **General** como espacio visible para el equipo.
