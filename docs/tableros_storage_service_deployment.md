# Servicio aislado de almacenamiento Tableros

El endpoint vive en `services/tableros_storage/index.php`. Publica esa carpeta como una aplicación o sitio IIS independiente; esta guía permanece fuera de la carpeta publicada. El cambio no requiere modificar el pool compartido `TG_PHP`. La aplicación principal conserva almacenamiento local mientras `TABLEROS_STORAGE_DRIVER=local` o la variable no esté definida.

## IIS y permisos

1. Publica únicamente esta carpeta en una aplicación IIS dedicada, por ejemplo `https://intranet.example.local/tableros-storage/`. Configura PHP/FastCGI para esa aplicación.
2. Crea un Application Pool exclusivo, sin compartirlo con otros módulos, y configura su identidad personalizada como `TOTALGAS\svc_web_tableros`. No cambies `TG_PHP`.
3. Concede al usuario de servicio permiso de modificación en `\\192.168.0.15\tableros`. Confirma también los permisos del recurso compartido y del sistema de archivos NAS. No des permisos a `Everyone`.
4. Configura en el proceso PHP de ese pool `TABLEROS_STORAGE_ROOT=\\192.168.0.15\tableros` y `TABLEROS_STORAGE_SERVICE_TOKEN` con un secreto aleatorio largo. No guardes el secreto en este repositorio ni en archivos publicados.
5. En el pool de la aplicación principal, configura `TABLEROS_STORAGE_DRIVER=nas_service`, `TABLEROS_STORAGE_SERVICE_URL` con la URL privada del endpoint y el mismo `TABLEROS_STORAGE_SERVICE_TOKEN`. Habilita la extensión PHP cURL.
6. Restringe el endpoint a HTTPS, a la red privada y, si es posible, a la IP del servidor de aplicación. Limita el firewall para que solo la aplicación principal pueda conectarse. No expongas el endpoint a Internet.
7. Verifica que el usuario de identidad puede leer, crear y eliminar un archivo de prueba en el recurso compartido antes de habilitar el driver en producción.

Configura IIS Request Filtering y el mapeo PHP/FastCGI de esta aplicación para aceptar los verbos `GET`, `PUT` y `DELETE`; deshabilita WebDAV en esta aplicación si intercepta esos verbos. Permite cuerpos PUT de hasta 100 MB (por ejemplo, `maxAllowedContentLength` de 104857600 bytes) y revisa límites de tiempo de FastCGI para las conexiones de archivos grandes.

El servicio acepta `PUT`, `GET` y `DELETE` autenticados por bearer token y solo permite claves opacas legacy de 64 caracteres hexadecimales o claves generadas con forma `board/item/YYYY/MM/shard/hash`. Los PUT se limitan a 100 MB y se escriben en un temporal del mismo directorio antes del cambio atómico de nombre. El endpoint no tiene sesión, acceso a base de datos ni autorización de usuario; la aplicación principal conserva autenticación, permisos y CSRF.

## Despliegue

El despliegue IIS, la creación de pools, la asignación de identidad, la carga de variables y los permisos NAS se realizan manualmente en el servidor IIS. Este repositorio solo incluye el endpoint y la guía; no contiene contraseñas ni aplica cambios remotos.
