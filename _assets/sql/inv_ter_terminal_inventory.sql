/* Inventario de terminales. Ejecutar una sola vez en TG. */
USE [TG];

IF OBJECT_ID('dbo.inv_ter_inventarios', 'U') IS NULL
CREATE TABLE dbo.inv_ter_inventarios (
    id INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
    estacion_id INT NOT NULL,
    estacion_nombre VARCHAR(120) NOT NULL,
    semana_inicio DATE NOT NULL,
    semana_fin DATE NOT NULL,
    usuario_id INT NOT NULL,
    usuario_correo VARCHAR(160) NOT NULL,
    fecha_registro DATETIME2 NOT NULL CONSTRAINT DF_inv_ter_inventarios_fecha DEFAULT SYSDATETIME(),
    CONSTRAINT UQ_inv_ter_inventarios_estacion_semana UNIQUE (estacion_id, semana_inicio),
    CONSTRAINT CK_inv_ter_inventarios_semana CHECK (semana_fin = DATEADD(DAY, 6, semana_inicio))
);

IF OBJECT_ID('dbo.inv_ter_inventario_detalles', 'U') IS NULL
CREATE TABLE dbo.inv_ter_inventario_detalles (
    id INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
    inventario_id INT NOT NULL,
    tipo_terminal VARCHAR(20) NOT NULL,
    funcionando INT NOT NULL,
    danadas INT NOT NULL,
    CONSTRAINT FK_inv_ter_detalle_inventario FOREIGN KEY (inventario_id) REFERENCES dbo.inv_ter_inventarios(id),
    CONSTRAINT UQ_inv_ter_detalle_tipo UNIQUE (inventario_id, tipo_terminal),
    CONSTRAINT CK_inv_ter_detalle_cantidades CHECK (funcionando >= 0 AND danadas >= 0),
    CONSTRAINT CK_inv_ter_detalle_tipo CHECK (tipo_terminal IN ('urovo','ticketcard','efecticard','inburgas','sodexo','ultragas','mobil','eox'))
);

IF OBJECT_ID('dbo.inv_ter_incidencias', 'U') IS NULL
CREATE TABLE dbo.inv_ter_incidencias (
    id INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
    estacion_id INT NOT NULL,
    tipo_terminal VARCHAR(20) NOT NULL,
    ticket_mojo_id BIGINT NOT NULL,
    estado_mojo VARCHAR(40) NOT NULL,
    fecha_apertura_mojo DATETIME2 NOT NULL,
    fecha_cierre_mojo DATETIME2 NULL,
    folio_proveedor VARCHAR(100) NULL,
    fecha_reporte_proveedor DATE NULL,
    descripcion VARCHAR(250) NOT NULL,
    usuario_id INT NOT NULL,
    usuario_correo VARCHAR(160) NOT NULL,
    fecha_registro DATETIME2 NOT NULL CONSTRAINT DF_inv_ter_incidencias_fecha DEFAULT SYSDATETIME(),
    CONSTRAINT UQ_inv_ter_incidencias_ticket UNIQUE (ticket_mojo_id),
    CONSTRAINT CK_inv_ter_incidencias_tipo CHECK (tipo_terminal IN ('urovo','ticketcard','efecticard','inburgas','sodexo','ultragas','mobil','eox'))
);

IF OBJECT_ID('dbo.inv_ter_incidencias_inventario', 'U') IS NULL
CREATE TABLE dbo.inv_ter_incidencias_inventario (
    inventario_id INT NOT NULL,
    incidencia_id INT NOT NULL,
    CONSTRAINT PK_inv_ter_incidencias_inventario PRIMARY KEY (inventario_id, incidencia_id),
    CONSTRAINT FK_inv_ter_inc_inv FOREIGN KEY (inventario_id) REFERENCES dbo.inv_ter_inventarios(id),
    CONSTRAINT FK_inv_ter_inc_inc FOREIGN KEY (incidencia_id) REFERENCES dbo.inv_ter_incidencias(id)
);

IF OBJECT_ID('dbo.inv_ter_incidencia_estados', 'U') IS NULL
CREATE TABLE dbo.inv_ter_incidencia_estados (
    id INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
    incidencia_id INT NOT NULL,
    estado_anterior VARCHAR(40) NULL,
    estado_nuevo VARCHAR(40) NOT NULL,
    fecha_estado_mojo DATETIME2 NULL,
    origen VARCHAR(30) NOT NULL,
    fecha_registro DATETIME2 NOT NULL CONSTRAINT DF_inv_ter_estados_fecha DEFAULT SYSDATETIME(),
    CONSTRAINT FK_inv_ter_estado_incidencia FOREIGN KEY (incidencia_id) REFERENCES dbo.inv_ter_incidencias(id)
);

/* Sin GO: compatible con clientes que envían el script como un solo lote. */
IF OBJECT_ID('dbo.inv_ter_configuracion', 'U') IS NULL
EXEC(N'CREATE TABLE dbo.inv_ter_configuracion (
    id TINYINT NOT NULL CONSTRAINT PK_inv_ter_configuracion PRIMARY KEY,
    dia_cierre_semana TINYINT NOT NULL,
    valeras_habilitadas VARCHAR(200) NOT NULL CONSTRAINT DF_inv_ter_configuracion_valeras DEFAULT ''ticketcard,efecticard,inburgas,sodexo,ultragas,mobil,eox'',
    actualizado_por INT NULL,
    actualizado_en DATETIME2 NOT NULL CONSTRAINT DF_inv_ter_configuracion_actualizado DEFAULT SYSDATETIME(),
    CONSTRAINT CK_inv_ter_configuracion_id CHECK (id = 1),
    CONSTRAINT CK_inv_ter_configuracion_dia CHECK (dia_cierre_semana BETWEEN 1 AND 7)
)');

IF COL_LENGTH('dbo.inv_ter_configuracion', 'valeras_habilitadas') IS NULL
EXEC(N'ALTER TABLE dbo.inv_ter_configuracion ADD valeras_habilitadas VARCHAR(200) NOT NULL
    CONSTRAINT DF_inv_ter_configuracion_valeras DEFAULT ''ticketcard,efecticard,inburgas,sodexo,ultragas,mobil,eox''');

EXEC(N'IF NOT EXISTS (SELECT 1 FROM dbo.inv_ter_configuracion WHERE id = 1)
    INSERT INTO dbo.inv_ter_configuracion (id, dia_cierre_semana, valeras_habilitadas)
    VALUES (1, 7, ''ticketcard,efecticard,inburgas,sodexo,ultragas,mobil,eox'');

UPDATE dbo.inv_ter_configuracion
SET valeras_habilitadas = ''ticketcard,efecticard,inburgas,sodexo,ultragas,mobil,eox''
WHERE id = 1 AND (valeras_habilitadas IS NULL OR valeras_habilitadas = '''')');

/* Catálogo de valeras administrable. */
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
SELECT v.codigo,v.nombre,v.valor_mojo
FROM (VALUES
    (''ticketcard'',''Ticket Card'',''Ticketcard''),
    (''efecticard'',''EfectiCard'',''Efecticard''),
    (''inburgas'',''Inburgas'',''Inburgas''),
    (''sodexo'',''Sodexo'',''Sodexo''),
    (''ultragas'',''Ultragas'',''Ultragas''),
    (''mobil'',''Mobil'',''Mobil''),
    (''eox'',''EOX'',''EOX'')
) v(codigo,nombre,valor_mojo)
WHERE NOT EXISTS (SELECT 1 FROM dbo.inv_ter_valeras x WHERE x.codigo=v.codigo)');

/* Los tipos ya no son una lista fija: provienen del catálogo administrable. */
IF EXISTS (SELECT 1 FROM sys.check_constraints WHERE parent_object_id=OBJECT_ID('dbo.inv_ter_inventario_detalles') AND name='CK_inv_ter_detalle_tipo')
EXEC(N'ALTER TABLE dbo.inv_ter_inventario_detalles DROP CONSTRAINT CK_inv_ter_detalle_tipo');

IF EXISTS (SELECT 1 FROM sys.check_constraints WHERE parent_object_id=OBJECT_ID('dbo.inv_ter_incidencias') AND name='CK_inv_ter_incidencias_tipo')
EXEC(N'ALTER TABLE dbo.inv_ter_incidencias DROP CONSTRAINT CK_inv_ter_incidencias_tipo');

IF NOT EXISTS (SELECT 1 FROM dbo.tg_permissions WHERE department = 'Operaciones' AND description = 'Inventario terminales - Captura propia')
INSERT INTO dbo.tg_permissions ([action], department, description, [status], updated_at, created_at)
VALUES ('read', 'Operaciones', 'Inventario terminales - Captura propia', 1, GETDATE(), GETDATE());
IF NOT EXISTS (SELECT 1 FROM dbo.tg_permissions WHERE department = 'Operaciones' AND description = 'Inventario terminales - Reporte global')
INSERT INTO dbo.tg_permissions ([action], department, description, [status], updated_at, created_at)
VALUES ('read', 'Operaciones', 'Inventario terminales - Reporte global', 1, GETDATE(), GETDATE());
