/* Gestión permanente de incidencias de terminales. Ejecutar una sola vez en TG.
   No usa GO: es compatible con clientes que envían el archivo como un solo lote. */
USE [TG];

IF OBJECT_ID('dbo.inv_ter_incidencias', 'U') IS NULL
CREATE TABLE dbo.inv_ter_incidencias (
    id INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
    estacion_id INT NOT NULL,
    tipo_terminal VARCHAR(20) NOT NULL,
    ticket_mojo_id BIGINT NOT NULL,
    estado_mojo VARCHAR(40) NOT NULL,
    estado_local VARCHAR(20) NOT NULL CONSTRAINT DF_inv_ter_incidencias_estado_local DEFAULT 'Abierta',
    fecha_apertura_mojo DATETIME2 NOT NULL,
    fecha_cierre_mojo DATETIME2 NULL,
    folio_proveedor VARCHAR(100) NULL,
    fecha_reporte_proveedor DATE NULL,
    descripcion VARCHAR(250) NOT NULL,
    problema_recurrente BIT NOT NULL CONSTRAINT DF_inv_ter_incidencias_recurrente DEFAULT 0,
    usuario_id INT NOT NULL,
    usuario_correo VARCHAR(160) NOT NULL,
    serial_urovo VARCHAR(100) NULL,
    cerrado_por_mojo VARCHAR(160) NULL,
    confirmado_resuelto_por INT NULL,
    confirmado_resuelto_correo VARCHAR(160) NULL,
    fecha_confirmacion_resolucion DATETIME2 NULL,
    resolucion_confirmada BIT NULL,
    nota_confirmacion_resolucion VARCHAR(500) NULL,
    fecha_registro DATETIME2 NOT NULL CONSTRAINT DF_inv_ter_incidencias_fecha DEFAULT SYSDATETIME(),
    CONSTRAINT UQ_inv_ter_incidencias_ticket UNIQUE (ticket_mojo_id)
);

/* Datos complementarios; los tickets históricos conservan estos valores en NULL. */
IF COL_LENGTH('dbo.inv_ter_incidencias', 'serial_urovo') IS NULL
EXEC(N'ALTER TABLE dbo.inv_ter_incidencias ADD serial_urovo VARCHAR(100) NULL');
IF COL_LENGTH('dbo.inv_ter_incidencias', 'cerrado_por_mojo') IS NULL
EXEC(N'ALTER TABLE dbo.inv_ter_incidencias ADD cerrado_por_mojo VARCHAR(160) NULL');
IF COL_LENGTH('dbo.inv_ter_incidencias', 'confirmado_resuelto_por') IS NULL
EXEC(N'ALTER TABLE dbo.inv_ter_incidencias ADD confirmado_resuelto_por INT NULL');
IF COL_LENGTH('dbo.inv_ter_incidencias', 'confirmado_resuelto_correo') IS NULL
EXEC(N'ALTER TABLE dbo.inv_ter_incidencias ADD confirmado_resuelto_correo VARCHAR(160) NULL');
IF COL_LENGTH('dbo.inv_ter_incidencias', 'fecha_confirmacion_resolucion') IS NULL
EXEC(N'ALTER TABLE dbo.inv_ter_incidencias ADD fecha_confirmacion_resolucion DATETIME2 NULL');
IF COL_LENGTH('dbo.inv_ter_incidencias', 'resolucion_confirmada') IS NULL
EXEC(N'ALTER TABLE dbo.inv_ter_incidencias ADD resolucion_confirmada BIT NULL');
IF COL_LENGTH('dbo.inv_ter_incidencias', 'nota_confirmacion_resolucion') IS NULL
EXEC(N'ALTER TABLE dbo.inv_ter_incidencias ADD nota_confirmacion_resolucion VARCHAR(500) NULL');
IF COL_LENGTH('dbo.inv_ter_incidencias', 'problema_recurrente') IS NULL
EXEC(N'ALTER TABLE dbo.inv_ter_incidencias ADD problema_recurrente BIT NOT NULL CONSTRAINT DF_inv_ter_incidencias_recurrente DEFAULT 0');

IF OBJECT_ID('dbo.inv_ter_incidencia_estados', 'U') IS NULL
CREATE TABLE dbo.inv_ter_incidencia_estados (
    id INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
    incidencia_id INT NOT NULL,
    ticket_mojo_id BIGINT NULL,
    estado_anterior VARCHAR(40) NULL,
    estado_nuevo VARCHAR(40) NOT NULL,
    fecha_estado_mojo DATETIME2 NULL,
    origen VARCHAR(30) NOT NULL,
    usuario_id INT NULL,
    usuario_correo VARCHAR(160) NULL,
    comentario VARCHAR(1000) NULL,
    sincronizacion VARCHAR(30) NULL,
    fecha_registro DATETIME2 NOT NULL CONSTRAINT DF_inv_ter_estados_fecha DEFAULT SYSDATETIME(),
    CONSTRAINT FK_inv_ter_estado_incidencia FOREIGN KEY (incidencia_id) REFERENCES dbo.inv_ter_incidencias(id)
);
IF COL_LENGTH('dbo.inv_ter_incidencias','estado_local') IS NULL
    ALTER TABLE dbo.inv_ter_incidencias ADD estado_local VARCHAR(20) NULL;
EXEC(N'UPDATE dbo.inv_ter_incidencias SET estado_local=CASE
    WHEN LOWER(estado_mojo) IN ('solved','resolved','resuelto') THEN 'Solved'
    WHEN LOWER(estado_mojo) IN ('closed','cerrado') THEN 'Closed'
    WHEN fecha_cierre_mojo IS NOT NULL THEN 'Closed'
    ELSE 'Abierta' END WHERE estado_local IS NULL');
ALTER TABLE dbo.inv_ter_incidencias ALTER COLUMN estado_local VARCHAR(20) NOT NULL;
IF NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE parent_object_id=OBJECT_ID('dbo.inv_ter_incidencias') AND name='CK_inv_ter_incidencias_estado_local')
    EXEC(N'ALTER TABLE dbo.inv_ter_incidencias ADD CONSTRAINT CK_inv_ter_incidencias_estado_local CHECK (estado_local IN (''Abierta'',''Solved'',''Reabierta'',''Closed''))');
IF COL_LENGTH('dbo.inv_ter_incidencia_estados','usuario_id') IS NULL ALTER TABLE dbo.inv_ter_incidencia_estados ADD usuario_id INT NULL;
IF COL_LENGTH('dbo.inv_ter_incidencia_estados','ticket_mojo_id') IS NULL ALTER TABLE dbo.inv_ter_incidencia_estados ADD ticket_mojo_id BIGINT NULL;
IF COL_LENGTH('dbo.inv_ter_incidencia_estados','usuario_correo') IS NULL ALTER TABLE dbo.inv_ter_incidencia_estados ADD usuario_correo VARCHAR(160) NULL;
IF COL_LENGTH('dbo.inv_ter_incidencia_estados','comentario') IS NULL ALTER TABLE dbo.inv_ter_incidencia_estados ADD comentario VARCHAR(1000) NULL;
IF COL_LENGTH('dbo.inv_ter_incidencia_estados','sincronizacion') IS NULL ALTER TABLE dbo.inv_ter_incidencia_estados ADD sincronizacion VARCHAR(30) NULL;
UPDATE h SET ticket_mojo_id=i.ticket_mojo_id
FROM dbo.inv_ter_incidencia_estados h
INNER JOIN dbo.inv_ter_incidencias i ON i.id=h.incidencia_id
WHERE h.ticket_mojo_id IS NULL;
EXEC(N'INSERT INTO dbo.inv_ter_incidencia_estados (incidencia_id,ticket_mojo_id,estado_anterior,estado_nuevo,fecha_estado_mojo,origen,usuario_id,usuario_correo,comentario,sincronizacion)
SELECT i.id,i.ticket_mojo_id,NULL,i.estado_local,i.fecha_apertura_mojo,''migracion'',i.usuario_id,i.usuario_correo,NULL,''migrado''
FROM dbo.inv_ter_incidencias i
WHERE NOT EXISTS (SELECT 1 FROM dbo.inv_ter_incidencia_estados h WHERE h.incidencia_id=i.id)');

IF OBJECT_ID('dbo.inv_ter_solicitudes','U') IS NULL
CREATE TABLE dbo.inv_ter_solicitudes (
    request_key VARCHAR(64) NOT NULL CONSTRAINT PK_inv_ter_solicitudes PRIMARY KEY,
    estacion_id INT NOT NULL,
    usuario_id INT NOT NULL,
    payload_hash CHAR(64) NOT NULL,
    payload_json NVARCHAR(MAX) NOT NULL,
    estado VARCHAR(20) NOT NULL CONSTRAINT DF_inv_ter_solicitudes_estado DEFAULT 'pendiente',
    ticket_mojo_id BIGINT NULL,
    incidencia_id INT NULL,
    ultimo_error VARCHAR(500) NULL,
    creado_en DATETIME2 NOT NULL CONSTRAINT DF_inv_ter_solicitudes_creado DEFAULT SYSDATETIME(),
    actualizado_en DATETIME2 NOT NULL CONSTRAINT DF_inv_ter_solicitudes_actualizado DEFAULT SYSDATETIME(),
    CONSTRAINT CK_inv_ter_solicitudes_estado CHECK (estado IN ('pendiente','completada'))
);

IF OBJECT_ID('dbo.inv_ter_configuracion', 'U') IS NULL
EXEC(N'CREATE TABLE dbo.inv_ter_configuracion (
    id TINYINT NOT NULL CONSTRAINT PK_inv_ter_configuracion PRIMARY KEY,
    valeras_habilitadas VARCHAR(200) NOT NULL CONSTRAINT DF_inv_ter_configuracion_valeras DEFAULT ''ticketcard,efecticard,inburgas,sodexo,ultragas,mobil,eox'',
    actualizado_por INT NULL,
    actualizado_en DATETIME2 NOT NULL CONSTRAINT DF_inv_ter_configuracion_actualizado DEFAULT SYSDATETIME(),
    CONSTRAINT CK_inv_ter_configuracion_id CHECK (id = 1)
)');
DECLARE @dayConstraint SYSNAME, @dayDropSql NVARCHAR(MAX);
DECLARE dayConstraintCursor CURSOR LOCAL FAST_FORWARD FOR
    SELECT name FROM sys.check_constraints WHERE parent_object_id=OBJECT_ID('dbo.inv_ter_configuracion')
      AND (definition LIKE '%dia_inventario_semana%' OR definition LIKE '%dia_cierre_semana%');
OPEN dayConstraintCursor;
FETCH NEXT FROM dayConstraintCursor INTO @dayConstraint;
WHILE @@FETCH_STATUS=0 BEGIN
    SET @dayDropSql=N'ALTER TABLE dbo.inv_ter_configuracion DROP CONSTRAINT '+QUOTENAME(@dayConstraint);
    EXEC sp_executesql @dayDropSql;
    FETCH NEXT FROM dayConstraintCursor INTO @dayConstraint;
END;
CLOSE dayConstraintCursor; DEALLOCATE dayConstraintCursor;
DECLARE dayDefaultCursor CURSOR LOCAL FAST_FORWARD FOR
    SELECT dc.name FROM sys.default_constraints dc JOIN sys.columns c ON c.object_id=dc.parent_object_id AND c.column_id=dc.parent_column_id
    WHERE dc.parent_object_id=OBJECT_ID('dbo.inv_ter_configuracion') AND c.name IN ('dia_inventario_semana','dia_cierre_semana');
OPEN dayDefaultCursor;
FETCH NEXT FROM dayDefaultCursor INTO @dayConstraint;
WHILE @@FETCH_STATUS=0 BEGIN
    SET @dayDropSql=N'ALTER TABLE dbo.inv_ter_configuracion DROP CONSTRAINT '+QUOTENAME(@dayConstraint);
    EXEC sp_executesql @dayDropSql;
    FETCH NEXT FROM dayDefaultCursor INTO @dayConstraint;
END;
CLOSE dayDefaultCursor; DEALLOCATE dayDefaultCursor;
IF COL_LENGTH('dbo.inv_ter_configuracion', 'dia_inventario_semana') IS NOT NULL ALTER TABLE dbo.inv_ter_configuracion DROP COLUMN dia_inventario_semana;
IF COL_LENGTH('dbo.inv_ter_configuracion', 'dia_cierre_semana') IS NOT NULL ALTER TABLE dbo.inv_ter_configuracion DROP COLUMN dia_cierre_semana;

IF COL_LENGTH('dbo.inv_ter_configuracion', 'valeras_habilitadas') IS NULL
EXEC(N'ALTER TABLE dbo.inv_ter_configuracion ADD valeras_habilitadas VARCHAR(200) NOT NULL CONSTRAINT DF_inv_ter_configuracion_valeras DEFAULT ''ticketcard,efecticard,inburgas,sodexo,ultragas,mobil,eox''');

/* Meta por estación y tipo. Los totales históricos no se asignan a un tipo sin evidencia. */
IF OBJECT_ID('dbo.inv_ter_configuracion_estacion', 'U') IS NOT NULL
   AND COL_LENGTH('dbo.inv_ter_configuracion_estacion', 'tipo_terminal') IS NULL
BEGIN
    /*
       La tabla anterior sólo tenía un total por estación. Se conserva íntegra
       como respaldo y la matriz nueva inicia sin metas: Operaciones debe
       configurarlas por tipo. Esto evita interpretar el total histórico como
       una cantidad de UROVO (u otro tipo) sin una asignación explícita.
    */
    IF OBJECT_ID('dbo.inv_ter_configuracion_estacion_legacy', 'U') IS NOT NULL
        THROW 50002, 'Existe inv_ter_configuracion_estacion_legacy; no se puede preservar automáticamente otra configuración antigua.', 1;

    EXEC sys.sp_rename N'dbo.inv_ter_configuracion_estacion', N'inv_ter_configuracion_estacion_legacy';
END;

IF OBJECT_ID('dbo.inv_ter_configuracion_estacion', 'U') IS NULL
EXEC(N'CREATE TABLE dbo.inv_ter_configuracion_estacion (
    estacion_id INT NOT NULL,
    tipo_terminal VARCHAR(20) NOT NULL,
    terminales_esperadas INT NULL,
    actualizado_por INT NULL,
    actualizado_en DATETIME2 NOT NULL CONSTRAINT DF_inv_ter_config_estacion_actualizado_tipo DEFAULT SYSDATETIME(),
    CONSTRAINT PK_inv_ter_configuracion_estacion_tipo PRIMARY KEY (estacion_id, tipo_terminal),
    CONSTRAINT CK_inv_ter_config_estacion_terminales_tipo CHECK (terminales_esperadas >= 0)
)');

IF COL_LENGTH('dbo.inv_ter_configuracion_estacion', 'terminales_esperadas') IS NULL
EXEC(N'ALTER TABLE dbo.inv_ter_configuracion_estacion ADD terminales_esperadas INT NULL');
IF COL_LENGTH('dbo.inv_ter_configuracion_estacion', 'actualizado_por') IS NULL
EXEC(N'ALTER TABLE dbo.inv_ter_configuracion_estacion ADD actualizado_por INT NULL');
IF COL_LENGTH('dbo.inv_ter_configuracion_estacion', 'actualizado_en') IS NULL
EXEC(N'ALTER TABLE dbo.inv_ter_configuracion_estacion ADD actualizado_en DATETIME2 NULL');

/* Una instalación que ya agregó tipo_terminal debe tenerlo completamente definido. */
IF COL_LENGTH('dbo.inv_ter_configuracion_estacion', 'tipo_terminal') IS NULL
    THROW 50003, 'No fue posible crear tipo_terminal en inv_ter_configuracion_estacion.', 1;

EXEC(N'IF EXISTS (SELECT 1 FROM dbo.inv_ter_configuracion_estacion WHERE tipo_terminal IS NULL OR LTRIM(RTRIM(tipo_terminal))='''')
      THROW 50004, ''Existen metas sin tipo_terminal; corríjalas antes de exigir la matriz por tipo.'', 1;');
EXEC(N'IF EXISTS (SELECT 1 FROM dbo.inv_ter_configuracion_estacion WHERE DATALENGTH(tipo_terminal) > 20)
      THROW 50005, ''Existen tipos de terminal de más de 20 caracteres; corríjalos antes de continuar.'', 1;');
EXEC(N'ALTER TABLE dbo.inv_ter_configuracion_estacion ALTER COLUMN tipo_terminal VARCHAR(20) NOT NULL');

EXEC(N'UPDATE dbo.inv_ter_configuracion_estacion
      SET actualizado_en = SYSDATETIME()
      WHERE actualizado_en IS NULL');
EXEC(N'ALTER TABLE dbo.inv_ter_configuracion_estacion ALTER COLUMN actualizado_en DATETIME2 NOT NULL');

IF NOT EXISTS (SELECT 1 FROM sys.default_constraints WHERE parent_object_id=OBJECT_ID('dbo.inv_ter_configuracion_estacion') AND parent_column_id=COLUMNPROPERTY(OBJECT_ID('dbo.inv_ter_configuracion_estacion'), 'actualizado_en', 'ColumnId'))
EXEC(N'ALTER TABLE dbo.inv_ter_configuracion_estacion ADD CONSTRAINT DF_inv_ter_config_estacion_actualizado_tipo DEFAULT SYSDATETIME() FOR actualizado_en');

IF NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE parent_object_id=OBJECT_ID('dbo.inv_ter_configuracion_estacion') AND name='CK_inv_ter_config_estacion_terminales_tipo')
EXEC(N'ALTER TABLE dbo.inv_ter_configuracion_estacion ADD CONSTRAINT CK_inv_ter_config_estacion_terminales_tipo CHECK (terminales_esperadas >= 0)');

/* Sustituye una PK anterior de estacion_id por la PK compuesta requerida. */
IF NOT EXISTS (
    SELECT 1
    FROM sys.key_constraints kc
    WHERE kc.parent_object_id=OBJECT_ID('dbo.inv_ter_configuracion_estacion')
      AND kc.[type]='PK'
      AND 2=(SELECT COUNT(*) FROM sys.index_columns ic WHERE ic.object_id=kc.parent_object_id AND ic.index_id=kc.unique_index_id AND ic.key_ordinal > 0)
      AND EXISTS (SELECT 1 FROM sys.index_columns ic JOIN sys.columns c ON c.object_id=ic.object_id AND c.column_id=ic.column_id WHERE ic.object_id=kc.parent_object_id AND ic.index_id=kc.unique_index_id AND ic.key_ordinal=1 AND c.name='estacion_id')
      AND EXISTS (SELECT 1 FROM sys.index_columns ic JOIN sys.columns c ON c.object_id=ic.object_id AND c.column_id=ic.column_id WHERE ic.object_id=kc.parent_object_id AND ic.index_id=kc.unique_index_id AND ic.key_ordinal=2 AND c.name='tipo_terminal')
)
BEGIN
    DECLARE @invTerPk SYSNAME;
    SELECT @invTerPk=kc.name FROM sys.key_constraints kc WHERE kc.parent_object_id=OBJECT_ID('dbo.inv_ter_configuracion_estacion') AND kc.[type]='PK';
    IF @invTerPk IS NOT NULL
    BEGIN
        /* Guardar el SQL dinámico en una variable evita un error de sintaxis en
           algunos clientes SQL al combinar EXEC con QUOTENAME directamente. */
        DECLARE @invTerDropPkSql NVARCHAR(MAX);
        SET @invTerDropPkSql = N'ALTER TABLE dbo.inv_ter_configuracion_estacion DROP CONSTRAINT ' + QUOTENAME(@invTerPk) + N';';
        EXEC sp_executesql @invTerDropPkSql;
    END;
    ALTER TABLE dbo.inv_ter_configuracion_estacion ADD CONSTRAINT PK_inv_ter_configuracion_estacion_tipo PRIMARY KEY (estacion_id, tipo_terminal);
END;

EXEC(N'IF NOT EXISTS (SELECT 1 FROM dbo.inv_ter_configuracion WHERE id=1)
      INSERT INTO dbo.inv_ter_configuracion (id,valeras_habilitadas)
      VALUES (1,''ticketcard,efecticard,inburgas,sodexo,ultragas,mobil,eox'');
      UPDATE dbo.inv_ter_configuracion
      SET valeras_habilitadas=''ticketcard,efecticard,inburgas,sodexo,ultragas,mobil,eox''
      WHERE id=1 AND (valeras_habilitadas IS NULL OR valeras_habilitadas='''')');

/* Catálogo administrable de valeras. */
IF OBJECT_ID('dbo.inv_ter_valeras', 'U') IS NULL
EXEC(N'CREATE TABLE dbo.inv_ter_valeras (
    id INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_inv_ter_valeras PRIMARY KEY,
    codigo VARCHAR(20) NOT NULL CONSTRAINT UQ_inv_ter_valeras_codigo UNIQUE,
    nombre VARCHAR(100) NOT NULL,
    valor_mojo VARCHAR(100) NOT NULL,
    activo BIT NOT NULL CONSTRAINT DF_inv_ter_valeras_activo DEFAULT 1,
    creado_por INT NULL,
    creado_en DATETIME2 NOT NULL CONSTRAINT DF_inv_ter_valeras_creado DEFAULT SYSDATETIME()
)');

EXEC(N'INSERT INTO dbo.inv_ter_valeras (codigo,nombre,valor_mojo)
SELECT v.codigo,v.nombre,v.valor_mojo FROM (VALUES
 (''ticketcard'',''Ticket Card'',''Ticketcard''),( ''efecticard'',''EfectiCard'',''Efecticard''),
 (''inburgas'',''Inburgas'',''Inburgas''),( ''sodexo'',''Sodexo'',''Sodexo''),
 (''ultragas'',''Ultragas'',''Ultragas''),( ''mobil'',''Mobil'',''Mobil''),( ''eox'',''EOX'',''EOX'')
) v(codigo,nombre,valor_mojo)
WHERE NOT EXISTS (SELECT 1 FROM dbo.inv_ter_valeras x WHERE x.codigo=v.codigo)');

IF EXISTS (SELECT 1 FROM sys.check_constraints WHERE parent_object_id=OBJECT_ID('dbo.inv_ter_inventario_detalles') AND name='CK_inv_ter_detalle_tipo')
EXEC(N'ALTER TABLE dbo.inv_ter_inventario_detalles DROP CONSTRAINT CK_inv_ter_detalle_tipo');
IF EXISTS (SELECT 1 FROM sys.check_constraints WHERE parent_object_id=OBJECT_ID('dbo.inv_ter_incidencias') AND name='CK_inv_ter_incidencias_tipo')
EXEC(N'ALTER TABLE dbo.inv_ter_incidencias DROP CONSTRAINT CK_inv_ter_incidencias_tipo');

IF NOT EXISTS (SELECT 1 FROM dbo.tg_permissions WHERE department='Operaciones' AND description='Inventario terminales - Captura propia')
INSERT INTO dbo.tg_permissions ([action],department,description,[status],updated_at,created_at) VALUES ('read','Operaciones','Inventario terminales - Captura propia',1,GETDATE(),GETDATE());
IF NOT EXISTS (SELECT 1 FROM dbo.tg_permissions WHERE department='Operaciones' AND description='Inventario terminales - Reporte global')
INSERT INTO dbo.tg_permissions ([action],department,description,[status],updated_at,created_at) VALUES ('read','Operaciones','Inventario terminales - Reporte global',1,GETDATE(),GETDATE());

/* Las capturas semanales fueron pruebas. El código nuevo ya no las consulta.
   Se elimina su contenido y esquema, conservando incidencias, estados, stock,
   configuración de valeras y trazabilidad. Para restaurar los datos eliminados
   se requiere una copia de seguridad tomada antes de ejecutar esta migración. */
BEGIN TRY
BEGIN TRANSACTION;
IF EXISTS (
    SELECT 1 FROM sys.foreign_keys fk
    JOIN sys.tables parent ON parent.object_id=fk.parent_object_id
    JOIN sys.tables referenced ON referenced.object_id=fk.referenced_object_id
    WHERE (referenced.name IN ('inv_ter_inventarios','inv_ter_inventario_detalles','inv_ter_incidencias_inventario')
        OR parent.name IN ('inv_ter_inventarios','inv_ter_inventario_detalles','inv_ter_incidencias_inventario'))
      AND parent.name NOT IN ('inv_ter_inventarios','inv_ter_inventario_detalles','inv_ter_incidencias_inventario')
)
    THROW 50006, 'Hay dependencias FK externas a las tablas de captura semanal; resolverlas antes de eliminarlas.', 1;
IF EXISTS (
    SELECT 1 FROM sys.sql_expression_dependencies d
    WHERE d.referenced_id IN (OBJECT_ID('dbo.inv_ter_inventarios'),OBJECT_ID('dbo.inv_ter_inventario_detalles'),OBJECT_ID('dbo.inv_ter_incidencias_inventario'))
      AND NOT EXISTS (SELECT 1 FROM sys.tables t WHERE t.object_id=d.referencing_id AND t.name IN ('inv_ter_inventarios','inv_ter_inventario_detalles','inv_ter_incidencias_inventario'))
)
    THROW 50007, 'Hay módulos SQL que dependen de tablas de captura semanal; retirarlos antes de eliminarlas.', 1;
IF OBJECT_ID('dbo.inv_ter_incidencias_inventario','U') IS NOT NULL DELETE FROM dbo.inv_ter_incidencias_inventario;
IF OBJECT_ID('dbo.inv_ter_inventario_detalles','U') IS NOT NULL DELETE FROM dbo.inv_ter_inventario_detalles;
IF OBJECT_ID('dbo.inv_ter_inventarios','U') IS NOT NULL DELETE FROM dbo.inv_ter_inventarios;
IF OBJECT_ID('dbo.inv_ter_incidencias_inventario','U') IS NOT NULL DROP TABLE dbo.inv_ter_incidencias_inventario;
IF OBJECT_ID('dbo.inv_ter_inventario_detalles','U') IS NOT NULL DROP TABLE dbo.inv_ter_inventario_detalles;
IF OBJECT_ID('dbo.inv_ter_inventarios','U') IS NOT NULL DROP TABLE dbo.inv_ter_inventarios;
COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT>0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;
