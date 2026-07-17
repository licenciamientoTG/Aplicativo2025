/* ============================================================================
   Módulo de Arqueos Simultáneos Dollar2Go
   Base de datos: TG  (SQL Server)
   Crea: arqueo_sesiones, arqueo_cajas, arqueo_denominaciones, arqueo_vales
   y agrega 2 permisos al catálogo existente tg_permissions.
   ============================================================================ */

USE [TG];
GO

/* ---------------------------------------------------------------------------
   1) Sesión de arqueo (agrupa todas las cajas de un mismo momento)
   estado: programado -> abierto -> cerrado (cerrado es inmutable)
   --------------------------------------------------------------------------- */
IF OBJECT_ID('[TG].[dbo].[arqueo_sesiones]', 'U') IS NULL
BEGIN
    CREATE TABLE [TG].[dbo].[arqueo_sesiones] (
        [id]         INT IDENTITY(1,1) NOT NULL,
        [nombre]     NVARCHAR(120)     NULL,
        [fecha]      DATE              NOT NULL,
        [estado]     VARCHAR(12)       NOT NULL
                     CONSTRAINT [DF_arqueo_sesiones_estado] DEFAULT ('programado'),
        [created_by] INT               NULL,
        [closed_by]  INT               NULL,
        [closed_at]  DATETIME          NULL,
        [created_at] DATETIME          NOT NULL
                     CONSTRAINT [DF_arqueo_sesiones_created] DEFAULT (GETDATE()),
        CONSTRAINT [PK_arqueo_sesiones] PRIMARY KEY CLUSTERED ([id]),
        CONSTRAINT [CK_arqueo_sesiones_estado]
            CHECK ([estado] IN ('programado','abierto','cerrado'))
    );
END
GO

/* ---------------------------------------------------------------------------
   2) Una fila por caja/cajero
   --------------------------------------------------------------------------- */
IF OBJECT_ID('[TG].[dbo].[arqueo_cajas]', 'U') IS NULL
BEGIN
    CREATE TABLE [TG].[dbo].[arqueo_cajas] (
        [id]                    INT IDENTITY(1,1) NOT NULL,
        [sesion_id]             INT               NOT NULL,
        [sucursal_id]           INT               NULL,
        [sucursal_nombre]       NVARCHAR(100)     NULL,
        [caja_numero]           INT               NOT NULL
                                CONSTRAINT [DF_arqueo_cajas_caja] DEFAULT (1),
        [cajero_nombre]         NVARCHAR(120)     NULL,
        [encargado_revision]    NVARCHAR(120)     NULL,

        /* Sección GO EXCHANGE (captura manual) */
        [go_exchange_dolares]   DECIMAL(14,2)     NULL,
        [go_exchange_mxn]       DECIMAL(14,2)     NULL,
        [costo_promedio]        DECIMAL(10,4)     NULL,

        /* Totales calculados (persistidos para historial) */
        [total_fisico_dolares]  DECIMAL(14,2)     NULL,
        [total_fisico_mxn]      DECIMAL(14,2)     NULL,
        [total_arqueo_mxn]      DECIMAL(14,2)     NULL,
        [total_en_sistema]      DECIMAL(14,2)     NULL,
        [total_vales_dolares]   DECIMAL(14,2)     NULL,
        [total_vales_mxn]       DECIMAL(14,2)     NULL,
        [gran_total_vales_mxn]  DECIMAL(14,2)     NULL,
        [diferencia_dolares]    DECIMAL(14,2)     NULL,
        [diferencia_mxn]        DECIMAL(14,2)     NULL,
        [resultado_final]       DECIMAL(14,2)     NULL,

        [estado]                VARCHAR(12)       NOT NULL
                                CONSTRAINT [DF_arqueo_cajas_estado] DEFAULT ('pendiente'),
        [created_at]            DATETIME          NOT NULL
                                CONSTRAINT [DF_arqueo_cajas_created] DEFAULT (GETDATE()),
        [updated_at]            DATETIME          NOT NULL
                                CONSTRAINT [DF_arqueo_cajas_updated] DEFAULT (GETDATE()),
        CONSTRAINT [PK_arqueo_cajas] PRIMARY KEY CLUSTERED ([id]),
        CONSTRAINT [FK_arqueo_cajas_sesion] FOREIGN KEY ([sesion_id])
            REFERENCES [TG].[dbo].[arqueo_sesiones] ([id]) ON DELETE CASCADE,
        CONSTRAINT [CK_arqueo_cajas_estado]
            CHECK ([estado] IN ('pendiente','completado'))
    );
    CREATE INDEX [IX_arqueo_cajas_sesion] ON [TG].[dbo].[arqueo_cajas] ([sesion_id]);
END
GO

/* ---------------------------------------------------------------------------
   3) Detalle de conteo por denominación
   tipo: billete | moneda | fajilla | bolsa
   valor_bolsa: solo para morrallero_cf (valor fijo por bolsa)
   total: billete/moneda = cantidad*denominacion ;
          fajilla        = cantidad*denominacion*100 ;
          bolsa          = cantidad*valor_bolsa
   --------------------------------------------------------------------------- */
IF OBJECT_ID('[TG].[dbo].[arqueo_denominaciones]', 'U') IS NULL
BEGIN
    CREATE TABLE [TG].[dbo].[arqueo_denominaciones] (
        [id]            INT IDENTITY(1,1) NOT NULL,
        [caja_id]       INT               NOT NULL,
        [seccion]       VARCHAR(20)       NOT NULL,
        [moneda]        VARCHAR(3)        NOT NULL,
        [tipo]          VARCHAR(10)       NOT NULL,
        [denominacion]  DECIMAL(12,2)     NOT NULL,
        [valor_bolsa]   DECIMAL(12,2)     NULL,
        [cantidad]      INT               NOT NULL
                        CONSTRAINT [DF_arqueo_denom_cant] DEFAULT (0),
        [total]         DECIMAL(14,2)     NOT NULL
                        CONSTRAINT [DF_arqueo_denom_total] DEFAULT (0),
        CONSTRAINT [PK_arqueo_denominaciones] PRIMARY KEY CLUSTERED ([id]),
        CONSTRAINT [FK_arqueo_denom_caja] FOREIGN KEY ([caja_id])
            REFERENCES [TG].[dbo].[arqueo_cajas] ([id]) ON DELETE CASCADE,
        CONSTRAINT [CK_arqueo_denom_seccion]
            CHECK ([seccion] IN ('cajon','morrallero','caja_fuerte','morrallero_cf')),
        CONSTRAINT [CK_arqueo_denom_moneda]
            CHECK ([moneda] IN ('USD','MXN')),
        CONSTRAINT [CK_arqueo_denom_tipo]
            CHECK ([tipo] IN ('billete','moneda','fajilla','bolsa'))
    );
    CREATE INDEX [IX_arqueo_denom_caja] ON [TG].[dbo].[arqueo_denominaciones] ([caja_id]);
END
GO

/* ---------------------------------------------------------------------------
   4) Vales (hasta 15 por caja)
   --------------------------------------------------------------------------- */
IF OBJECT_ID('[TG].[dbo].[arqueo_vales]', 'U') IS NULL
BEGIN
    CREATE TABLE [TG].[dbo].[arqueo_vales] (
        [id]          INT IDENTITY(1,1) NOT NULL,
        [caja_id]     INT               NOT NULL,
        [numero_vale] INT               NOT NULL,
        [fecha]       DATE              NULL,
        [concepto]    NVARCHAR(200)     NULL,
        [dolares]     DECIMAL(14,2)     NOT NULL
                      CONSTRAINT [DF_arqueo_vales_usd] DEFAULT (0),
        [mxn]         DECIMAL(14,2)     NOT NULL
                      CONSTRAINT [DF_arqueo_vales_mxn] DEFAULT (0),
        CONSTRAINT [PK_arqueo_vales] PRIMARY KEY CLUSTERED ([id]),
        CONSTRAINT [FK_arqueo_vales_caja] FOREIGN KEY ([caja_id])
            REFERENCES [TG].[dbo].[arqueo_cajas] ([id]) ON DELETE CASCADE
    );
    CREATE INDEX [IX_arqueo_vales_caja] ON [TG].[dbo].[arqueo_vales] ([caja_id]);
END
GO

/* ---------------------------------------------------------------------------
   5) Captura manual por sucursal+sesión para el Concentrado
   (Capital de Trabajo, Gastos en trámite, Adeudo, Reinversión, Utilidad).
   Ver hoja "Concentrado" de los Excel de referencia, columnas C, I, J, K, M.
   --------------------------------------------------------------------------- */
IF OBJECT_ID('[TG].[dbo].[arqueo_concentrado_extras]', 'U') IS NULL
BEGIN
    CREATE TABLE [TG].[dbo].[arqueo_concentrado_extras] (
        [id]              INT IDENTITY(1,1) NOT NULL,
        [sesion_id]       INT               NOT NULL,
        [sucursal_id]     INT               NOT NULL,
        [capital_trabajo] DECIMAL(14,2)     NOT NULL
                          CONSTRAINT [DF_ace_capital] DEFAULT (0),
        [gastos_tramite]  DECIMAL(14,2)     NOT NULL
                          CONSTRAINT [DF_ace_gastos]  DEFAULT (0),
        [adeudo]          DECIMAL(14,2)     NOT NULL
                          CONSTRAINT [DF_ace_adeudo]  DEFAULT (0),
        [reinversion]     DECIMAL(14,2)     NOT NULL
                          CONSTRAINT [DF_ace_reinv]   DEFAULT (0),
        [utilidad]        DECIMAL(14,2)     NOT NULL
                          CONSTRAINT [DF_ace_util]    DEFAULT (0),
        [updated_by]      INT               NULL,
        [updated_at]      DATETIME          NOT NULL
                          CONSTRAINT [DF_ace_updated] DEFAULT (GETDATE()),
        CONSTRAINT [PK_arqueo_concentrado_extras] PRIMARY KEY CLUSTERED ([id]),
        CONSTRAINT [FK_ace_sesion] FOREIGN KEY ([sesion_id])
            REFERENCES [TG].[dbo].[arqueo_sesiones] ([id]) ON DELETE CASCADE,
        CONSTRAINT [UQ_arqueo_concentrado_extras] UNIQUE ([sesion_id], [sucursal_id])
    );
    CREATE INDEX [IX_ace_sesion] ON [TG].[dbo].[arqueo_concentrado_extras] ([sesion_id]);
END
GO

/* ---------------------------------------------------------------------------
   6) Permisos nuevos en el catálogo existente.
   El id lo asigna IDENTITY: tras correr esto, consulta los ids y ajústalos en
   las constantes PERM_ADMIN / PERM_AUDITOR de _assets/controllers/arqueo.php.
   --------------------------------------------------------------------------- */
IF NOT EXISTS (SELECT 1 FROM [TG].[dbo].[tg_permissions] WHERE [action] = 'arqueo_admin')
    INSERT INTO [TG].[dbo].[tg_permissions] ([action],[department],[description],[status],[updated_at],[created_at])
    VALUES ('arqueo_admin','Arqueo D2GO','Auditor administrador: programa, abre y cierra arqueos; ve concentrado y exporta','1',GETDATE(),GETDATE());

IF NOT EXISTS (SELECT 1 FROM [TG].[dbo].[tg_permissions] WHERE [action] = 'arqueo_capturar')
    INSERT INTO [TG].[dbo].[tg_permissions] ([action],[department],[description],[status],[updated_at],[created_at])
    VALUES ('arqueo_capturar','Arqueo D2GO','Usuario auditor: captura el arqueo físico de la(s) caja(s)','1',GETDATE(),GETDATE());
GO

SELECT [id],[action],[description] FROM [TG].[dbo].[tg_permissions]
WHERE [action] IN ('arqueo_admin','arqueo_capturar');
GO

/* ---------------------------------------------------------------------------
   7) Capital de Trabajo BASE por sucursal.
   Catálogo que se copia a arqueo_concentrado_extras al crear cada sesión.
   Editable desde el modal del Concentrado (checkbox "actualizar base").
   --------------------------------------------------------------------------- */
IF OBJECT_ID('[TG].[dbo].[arqueo_capital_base]', 'U') IS NULL
BEGIN
    CREATE TABLE [TG].[dbo].[arqueo_capital_base] (
        [id]              INT IDENTITY(1,1) NOT NULL,
        [sucursal_id]     INT               NOT NULL,
        [capital_trabajo] DECIMAL(14,2)     NOT NULL
                          CONSTRAINT [DF_acb_capital] DEFAULT (0),
        [updated_by]      INT               NULL,
        [updated_at]      DATETIME          NOT NULL
                          CONSTRAINT [DF_acb_updated] DEFAULT (GETDATE()),
        CONSTRAINT [PK_arqueo_capital_base] PRIMARY KEY CLUSTERED ([id]),
        CONSTRAINT [UQ_arqueo_capital_base] UNIQUE ([sucursal_id])
    );
END
GO

/* ---------------------------------------------------------------------------
   8) Log de auditoría del módulo arqueo.
   Quién, cuándo, qué acción y qué cambió (JSON antes/después).
   Sin FK a propósito: la auditoría sobrevive a cualquier borrado.
   --------------------------------------------------------------------------- */
IF OBJECT_ID('[TG].[dbo].[arqueo_audit_log]', 'U') IS NULL
BEGIN
    CREATE TABLE [TG].[dbo].[arqueo_audit_log] (
        [id]               INT IDENTITY(1,1) NOT NULL,
        [sesion_id]        INT               NOT NULL,
        [caja_id]          INT               NULL,
        [sucursal_id]      INT               NULL,
        [accion]           VARCHAR(30)       NOT NULL,
        [usuario_id]       INT               NULL,
        [usuario_nombre]   NVARCHAR(120)     NULL,
        [datos_anteriores] NVARCHAR(MAX)     NULL,
        [datos_nuevos]     NVARCHAR(MAX)     NULL,
        [fecha]            DATETIME          NOT NULL
                           CONSTRAINT [DF_aal_fecha] DEFAULT (GETDATE()),
        CONSTRAINT [PK_arqueo_audit_log] PRIMARY KEY CLUSTERED ([id])
    );
    CREATE INDEX [IX_aal_sesion] ON [TG].[dbo].[arqueo_audit_log] ([sesion_id]);
END
GO

/* ---------------------------------------------------------------------------
   9) Asignación de usuario capturista por caja.
   NULL = sin asignar (solo administradores pueden capturar esa caja).
   --------------------------------------------------------------------------- */
IF COL_LENGTH('[TG].[dbo].[arqueo_cajas]', 'asignado_user_id') IS NULL
    ALTER TABLE [TG].[dbo].[arqueo_cajas] ADD [asignado_user_id] INT NULL;
GO

/* ---------------------------------------------------------------------------
   10) Imágenes adjuntas por sucursal en el concentrado (sesión + sucursal).
   El archivo vive en _assets/uploads/arqueo_imagenes/YYYY/MM/{id}.{ext};
   aquí solo se guarda la metadata. Patrón de payment_transaction_documents.
   --------------------------------------------------------------------------- */
IF OBJECT_ID('[TG].[dbo].[arqueo_concentrado_imagenes]', 'U') IS NULL
BEGIN
    CREATE TABLE [TG].[dbo].[arqueo_concentrado_imagenes] (
        [id]                INT IDENTITY(1,1) NOT NULL,
        [sesion_id]         INT               NOT NULL,
        [sucursal_id]       INT               NOT NULL,
        [file_path]         NVARCHAR(300)     NOT NULL,
        [file_extension]    VARCHAR(10)       NOT NULL,
        [original_filename] NVARCHAR(255)     NULL,
        [file_size]         INT               NULL,
        [created_by]        INT               NULL,
        [created_at]        DATETIME          NOT NULL
                            CONSTRAINT [DF_aci_created] DEFAULT (GETDATE()),
        CONSTRAINT [PK_arqueo_concentrado_imagenes] PRIMARY KEY CLUSTERED ([id])
    );
    CREATE INDEX [IX_aci_sesion_sucursal]
        ON [TG].[dbo].[arqueo_concentrado_imagenes] ([sesion_id], [sucursal_id]);
END
GO
