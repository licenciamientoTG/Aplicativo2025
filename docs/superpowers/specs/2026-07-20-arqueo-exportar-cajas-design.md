# Exportar relación de cajas de un arqueo a Excel

**Fecha:** 2026-07-20
**Vista afectada:** `/arqueo/cajas/{sesion_id}`

## Objetivo

Permitir al administrador de arqueos descargar en Excel la relación
Sucursal / Caja / Cajero / Asignado de las cajas de una sesión, para
distribuir y documentar las asignaciones de captura.

## Decisiones

- **Formato:** `.xlsx` con PhpSpreadsheet (ya instalada vía Composer).
- **Columnas:** solo las 4 — Sucursal, Caja, Cajero, Asignado. Sin Estado ni
  Resultado Final.
- **Permisos:** solo admin (`PERM_ADMIN = 73`). El botón no se muestra a
  auditores.
- **Endpoint nuevo** `exportar_cajas($sesion_id)`; NO se toca el stub
  `exportar($sesion_id)`, reservado para el export completo del concentrado.

## Diseño

### Controlador — `_assets/controllers/arqueo.php`

Método `exportar_cajas($sesion_id)`:

1. `guard([PERM_ADMIN])`.
2. 404 si la sesión no existe.
3. Cajas vía `cajasModel->by_sesion($sesion_id)` (mismo query que la vista;
   trae `sucursal_nombre`, `caja_numero`, `cajero_nombre`, `asignado_nombre`).
4. Hoja con encabezado en fila 1 en negrita, una fila por caja.
   `Asignado` = `asignado_nombre` o `Sin asignar`. Anchos de columna
   automáticos.
5. Headers de descarga (patrón de `administration.php`):
   `Content-Type` xlsx + `Content-Disposition: attachment` con nombre
   `arqueo_cajas_{nombre-sesion-slug}_{fecha}.xlsx`.

### Vista — `views/arqueo/cajas.html`

Botón verde "Descargar Excel" (`fa-file-excel`) junto a "Regresar" en el
`menutitle`, visible solo si `es_admin`. Enlace directo a
`/arqueo/exportar_cajas/{{ sesion.id }}` — sin JS.

### Sin cambios en modelos ni base de datos

Todo sale de `by_sesion()`. La ruta se resuelve sola por el front controller
(`/arqueo/[metodo]/[params]`).
