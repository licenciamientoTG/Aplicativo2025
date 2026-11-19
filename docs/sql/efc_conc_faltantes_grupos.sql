/* TG: persistencia para agrupar diferencias visibles en el reporte de efectivo.
   Ejecutar en la base TG. Rollback: conservar tablas mientras haya grupos; para
   retirar la función de forma segura, archivar/exportar ambas tablas antes de
   eliminar miembros y grupos. No se eliminan datos automáticamente. */
USE [TG];

IF OBJECT_ID('dbo.efc_conc_faltantes_grupos','U') IS NULL
 CREATE TABLE dbo.efc_conc_faltantes_grupos(id INT IDENTITY PRIMARY KEY, estacion_id INT NOT NULL, color CHAR(7) NOT NULL, creado_por INT NULL, creado_en DATETIME2 NOT NULL DEFAULT SYSDATETIME(), activo BIT NOT NULL DEFAULT 1);
IF OBJECT_ID('dbo.efc_conc_faltantes_grupos_miembros','U') IS NULL
 CREATE TABLE dbo.efc_conc_faltantes_grupos_miembros(grupo_id INT NOT NULL, estacion_id INT NOT NULL, fecha_operativa DATE NOT NULL, turno VARCHAR(20) NOT NULL, concepto VARCHAR(20) NOT NULL, diferencia DECIMAL(18,2) NOT NULL, CONSTRAINT PK_efc_conc_faltantes_grupos_miembros PRIMARY KEY(estacion_id,fecha_operativa,turno,concepto), CONSTRAINT FK_efc_conc_faltantes_grupos_miembros_grupo FOREIGN KEY(grupo_id) REFERENCES dbo.efc_conc_faltantes_grupos(id));
