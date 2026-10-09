# Servicio aislado de almacenamiento Tableros

El endpoint vive en `services/tableros_storage/index.php`. Publica esa carpeta como una aplicación o sitio IIS independiente; esta guía permanece fuera de la carpeta publicada. El cambio no requiere modificar el pool compartido `TG_PHP`. La aplicación principal conserva almacenamiento local mientras `TABLEROS_STORAGE_DRIVER=local` o la variable no esté definida.

## IIS y permisos

1. Publica únicamente esta carpeta en una aplicación IIS dedicada, por ejemplo `https://intranet.example.local/tableros-storage/`. Configura PHP/FastCGI para esa aplicación.
2. Crea un Application Pool exclusivo, sin compartirlo con otros módulos, y configura su identidad personalizada como `TOTALGAS\svc_web_tableros`. No cambies `TG_PHP`.
3. Concede al usuario de servicio permiso de modificación en `\\192.168.0.15\tableros`. Confirma también los permisos del recurso compartido y del sistema de archivos NAS. No des permisos a `Everyone`.
4. Configura en el proceso PHP de ese pool `TABLEROS_STORAGE_ROOT=\\192.168.0.15\tableros` y `TABLEROS_STORAGE_SERVICE_TOKEN` con un secreto aleatorio largo. No guardes el secreto en este repositorio ni en archivos publicados.
5. Para `TG_PHP`, crea una entrada FastCGI propia para `C:\php\php-cgi.exe`, con argumentos únicos que carguen el mismo `php.ini` que usa hoy ese sitio. Agrega en esa entrada `TABLEROS_STORAGE_DRIVER=nas_service`, `TABLEROS_STORAGE_SERVICE_URL=http://127.0.0.1:4400/` y el mismo `TABLEROS_STORAGE_SERVICE_TOKEN`; configura el handler de `TG_PHP` con el mismo `scriptProcessor` (ruta del ejecutable + argumentos). No agregues estas variables a la entrada FastCGI global sin argumentos ni al entorno global de Windows. Habilita cURL en el `php.ini` elegido.
6. Si el endpoint corre en el mismo servidor y está enlazado exclusivamente a `127.0.0.1` (o `::1`), la URL puede usar HTTP local. Para cualquier enlace de red, usa HTTPS, limita el acceso a la IP del servidor de aplicación y restringe el firewall. No expongas el endpoint a Internet.
7. Verifica que el usuario de identidad puede leer, crear y eliminar un archivo de prueba en el recurso compartido antes de habilitar el driver en producción.

Configura IIS Request Filtering y el mapeo PHP/FastCGI de esta aplicación para aceptar los verbos `GET`, `PUT` y `DELETE`; deshabilita WebDAV en esta aplicación si intercepta esos verbos. Permite cuerpos PUT de hasta 100 MB (por ejemplo, `maxAllowedContentLength` de 104857600 bytes) y revisa límites de tiempo de FastCGI para las conexiones de archivos grandes.

La entrada FastCGI dedicada del sitio principal mantiene sin cambios las identidades y configuración de los otros sitios IIS. Todos los scripts PHP dentro de la aplicación `TG_PHP` podrán leer las variables de esa entrada; IIS no ofrece aislamiento de variables de entorno por controlador PHP dentro de un mismo proceso. Si se requiere aislar también el secreto de los demás módulos dentro de `TG_PHP`, el módulo Tableros debe ejecutarse como aplicación IIS separada.

El servicio acepta `PUT`, `GET` y `DELETE` autenticados por bearer token de al menos 32 caracteres y solo permite claves opacas legacy de 64 caracteres hexadecimales, claves generadas con forma `board/item/YYYY/MM/shard/hash` o stickers con forma `stickers/userId/YYYY/MM/shard/hash`. Los PUT se limitan a 100 MB y se escriben en un temporal del mismo directorio antes del cambio atómico de nombre. Al publicar una versión nueva de `services/tableros_storage/index.php`, conserva habilitada esta expresión de claves para que las cargas y lecturas NAS de stickers no fallen. El endpoint no tiene sesión, acceso a base de datos ni autorización de usuario; la aplicación principal conserva autenticación, permisos y CSRF. El cliente PHP acepta HTTP solo para `127.0.0.1` o `::1`; requiere HTTPS para otras direcciones.

## Despliegue

El despliegue IIS, la creación de pools, la asignación de identidad, la carga de variables y los permisos NAS se realizan manualmente en el servidor IIS. Este repositorio solo incluye el endpoint y la guía; no contiene contraseñas ni aplica cambios remotos.
