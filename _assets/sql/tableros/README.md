# Módulo Tableros

## Despliegue

- `001_create_schema.sql` crea la base `tableros` y los objetos del módulo. Es aditivo e idempotente.
- `002_seed_tg_permissions.sql` registra los permisos en `TG.dbo.tg_permissions`; no asigna permisos a personas.
- `003_rollback_notes.sql` contiene consultas de revisión. Las instrucciones destructivas están comentadas y no deben ejecutarse como parte de un despliegue normal.
- La conexión de la aplicación a `tableros` reutiliza la configuración SQL existente sin cambiar la conexión compartida de los demás modelos.

Después de desplegar el código, Sistemas debe asignar en `/it` los permisos **Tableros - Acceso**, **Tableros - Crear** y **Tableros - Administrar** a las personas correspondientes. El módulo no concede acceso automáticamente.

## Automatizaciones

Programar `php cron/tableros_automation_worker.php` para que se ejecute cada minuto en el servidor de la aplicación, bajo la identidad que tenga acceso a SQL Server. El worker usa un bloqueo de aplicación para evitar ejecuciones simultáneas.

## Archivos

Definir `TABLEROS_PRIVATE_STORAGE` con una ruta absoluta, escribible por el proceso de la aplicación y fuera de `DOCUMENT_ROOT` y del directorio del proyecto. El código permite archivos de hasta 25 MB y restringe extensiones y tipo MIME. Las versiones nuevas quedan pendientes; un propietario o diseñador debe aprobar manualmente la versión vigente para habilitar su descarga. Esa aprobación no es un análisis antivirus.

## Monday

La importación de proyectos desde Monday no forma parte del módulo. Los proyectos existentes se importarán por el proceso externo a cargo del equipo.
