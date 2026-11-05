/* Inventario UROVO central en TG. Ejecutar en DBeaver como script completo.
   Eventos y bitácoras usan DATETIME2; la aplicación debe restringir fechas de
   captura al día actual. No ejecutar sobre bases de estaciones. */
USE [TG];

IF OBJECT_ID(N'[dbo].[inv_urovo_terminales]', N'U') IS NULL
BEGIN
    CREATE TABLE [dbo].[inv_urovo_terminales] (
        [id] BIGINT IDENTITY(1,1) NOT NULL,
        [numero_serie] NVARCHAR(100) NOT NULL,
        [numero_serie_normalizado] NVARCHAR(100) NOT NULL,
        [modelo] NVARCHAR(100) NOT NULL,
        [ip] VARCHAR(45) NULL,
        [mac] VARCHAR(17) NULL,
        [imei] NVARCHAR(32) NULL,
        [fecha_compra] DATE NULL,
        [fin_garantia] DATE NULL,
        [condicion] NVARCHAR(40) NOT NULL,
        [accesorios] NVARCHAR(1000) NULL,
        [notas] NVARCHAR(2000) NULL,
        [ubicacion_tipo] VARCHAR(20) NOT NULL,
        [estacion_codigo] INT NULL,
        [proveedor] NVARCHAR(200) NULL,
        [creado_por] INT NOT NULL,
        [creado_en] DATETIME2(0) NOT NULL CONSTRAINT [DF_inv_urovo_terminales_creado] DEFAULT (SYSDATETIME()),
        [actualizado_por] INT NOT NULL,
        [actualizado_en] DATETIME2(0) NOT NULL CONSTRAINT [DF_inv_urovo_terminales_actualizado] DEFAULT (SYSDATETIME()),
        CONSTRAINT [PK_inv_urovo_terminales] PRIMARY KEY CLUSTERED ([id]),
        CONSTRAINT [UQ_inv_urovo_terminales_serie_norm] UNIQUE ([numero_serie_normalizado]),
        CONSTRAINT [CK_inv_urovo_terminales_ubicacion] CHECK ([ubicacion_tipo] IN ('estacion','almacen','reparacion','perdida','baja_definitiva')),
        CONSTRAINT [CK_inv_urovo_terminales_estacion] CHECK (([ubicacion_tipo] = 'estacion' AND [estacion_codigo] IS NOT NULL) OR ([ubicacion_tipo] <> 'estacion' AND [estacion_codigo] IS NULL)),
        CONSTRAINT [FK_inv_urovo_terminales_estacion] FOREIGN KEY ([estacion_codigo]) REFERENCES [dbo].[Estaciones] ([Codigo])
    );
    CREATE INDEX [IX_inv_urovo_terminales_estacion] ON [dbo].[inv_urovo_terminales] ([estacion_codigo]) INCLUDE ([numero_serie_normalizado],[condicion]);
    CREATE INDEX [IX_inv_urovo_terminales_ubicacion] ON [dbo].[inv_urovo_terminales] ([ubicacion_tipo],[proveedor]);
END;

IF OBJECT_ID(N'[dbo].[inv_urovo_movimientos]', N'U') IS NULL
BEGIN
    CREATE TABLE [dbo].[inv_urovo_movimientos] (
        [id] BIGINT IDENTITY(1,1) NOT NULL,
        [terminal_id] BIGINT NOT NULL,
        [fecha_evento] DATE NOT NULL CONSTRAINT [DF_urovo_mov_fecha] DEFAULT (CONVERT(date, SYSDATETIME())),
        [tipo_evento] VARCHAR(30) NOT NULL,
        [ubicacion_origen_tipo] VARCHAR(20) NULL,
        [estacion_origen_codigo] INT NULL,
        [proveedor_origen] NVARCHAR(200) NULL,
        [ubicacion_destino_tipo] VARCHAR(20) NULL,
        [estacion_destino_codigo] INT NULL,
        [proveedor_destino] NVARCHAR(200) NULL,
        [condicion_anterior] NVARCHAR(40) NULL,
        [condicion_nueva] NVARCHAR(40) NULL,
        [motivo] NVARCHAR(1000) NULL,
        [actor_id] INT NOT NULL,
        [registrado_en] DATETIME2(0) NOT NULL CONSTRAINT [DF_urovo_mov_registrado] DEFAULT (SYSDATETIME()),
        CONSTRAINT [PK_inv_urovo_movimientos] PRIMARY KEY CLUSTERED ([id]),
        CONSTRAINT [FK_urovo_mov_terminal] FOREIGN KEY ([terminal_id]) REFERENCES [dbo].[inv_urovo_terminales] ([id]),
        CONSTRAINT [FK_urovo_mov_est_origen] FOREIGN KEY ([estacion_origen_codigo]) REFERENCES [dbo].[Estaciones] ([Codigo]),
        CONSTRAINT [FK_urovo_mov_est_destino] FOREIGN KEY ([estacion_destino_codigo]) REFERENCES [dbo].[Estaciones] ([Codigo]),
        CONSTRAINT [CK_urovo_mov_tipo] CHECK ([tipo_evento] IN ('alta','traslado','cambio_condicion','asignacion','reparacion','baja','ajuste')),
        CONSTRAINT [CK_urovo_mov_origen] CHECK ([ubicacion_origen_tipo] IS NULL OR [ubicacion_origen_tipo] IN ('estacion','almacen','reparacion','perdida','baja_definitiva')),
        CONSTRAINT [CK_urovo_mov_destino] CHECK ([ubicacion_destino_tipo] IS NULL OR [ubicacion_destino_tipo] IN ('estacion','almacen','reparacion','perdida','baja_definitiva'))
    );
    CREATE INDEX [IX_urovo_mov_terminal_fecha] ON [dbo].[inv_urovo_movimientos] ([terminal_id],[fecha_evento],[id]);
END;

IF OBJECT_ID(N'[dbo].[inv_urovo_existencias_movimientos]', N'U') IS NULL
BEGIN
    CREATE TABLE [dbo].[inv_urovo_existencias_movimientos] (
        [id] BIGINT IDENTITY(1,1) NOT NULL,
        [fecha_evento] DATE NOT NULL CONSTRAINT [DF_urovo_cant_fecha] DEFAULT (CONVERT(date, SYSDATETIME())),
        [modelo] NVARCHAR(100) NOT NULL,
        [delta_cantidad] INT NOT NULL,
        [ubicacion_tipo] VARCHAR(20) NOT NULL,
        [estacion_codigo] INT NULL,
        [estado] NVARCHAR(40) NOT NULL,
        [proveedor] NVARCHAR(200) NULL,
        [motivo] NVARCHAR(1000) NOT NULL,
        [actor_id] INT NOT NULL,
        [registrado_en] DATETIME2(0) NOT NULL CONSTRAINT [DF_urovo_cant_registrado] DEFAULT (SYSDATETIME()),
        CONSTRAINT [PK_inv_urovo_existencias_movimientos] PRIMARY KEY CLUSTERED ([id]),
        CONSTRAINT [FK_urovo_cant_estacion] FOREIGN KEY ([estacion_codigo]) REFERENCES [dbo].[Estaciones] ([Codigo]),
        CONSTRAINT [CK_urovo_cant_delta] CHECK ([delta_cantidad] <> 0),
        CONSTRAINT [CK_urovo_cant_ubicacion] CHECK ([ubicacion_tipo] IN ('estacion','almacen','reparacion','perdida','baja_definitiva')),
        CONSTRAINT [CK_urovo_cant_estacion] CHECK (([ubicacion_tipo] = 'estacion' AND [estacion_codigo] IS NOT NULL) OR ([ubicacion_tipo] <> 'estacion' AND [estacion_codigo] IS NULL))
    );
    CREATE INDEX [IX_urovo_cant_balance] ON [dbo].[inv_urovo_existencias_movimientos] ([modelo],[ubicacion_tipo],[estacion_codigo],[estado],[proveedor]) INCLUDE ([delta_cantidad]);
    CREATE INDEX [IX_urovo_cant_fecha] ON [dbo].[inv_urovo_existencias_movimientos] ([fecha_evento],[id]);
END;

/* Seed exact permission tuple. */
IF NOT EXISTS (SELECT 1 FROM [dbo].[tg_permissions] WHERE [action] = 'update' AND [department] = 'Sistemas' AND [description] = 'Inventario UROVO')
    INSERT INTO [dbo].[tg_permissions] ([action],[department],[description],[status],[updated_at],[created_at])
    VALUES ('update','Sistemas','Inventario UROVO','1',GETDATE(),GETDATE());

SELECT [id],[action],[department],[description],[status]
FROM [dbo].[tg_permissions]
WHERE [action] = 'update' AND [department] = 'Sistemas' AND [description] = 'Inventario UROVO';

