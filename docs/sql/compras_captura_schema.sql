/* ============================================================================
   Compras de combustible - Fase 2: captura manual
   Base de datos: TG (SQL Server)
   Crea: compras_factura_datos, compras_renglon_manual, compras_audit_log
   Plan: docs/superpowers/specs/2026-10-27-compras-registro-plan.md
   ============================================================================ */

USE [TG];
GO

/* Datos capturados sobre una factura que ya existe en FacturasRecibidas
   (llave: UUID del CFDI). est_codgas / pago_programado, si vienen, ganan
   sobre lo que arma el sistema. */
IF OBJECT_ID('[TG].[dbo].[compras_factura_datos]', 'U') IS NULL
BEGIN
    CREATE TABLE [TG].[dbo].[compras_factura_datos] (
        [uuid]             VARCHAR(50)   NOT NULL,
        [est_codgas]       INT           NULL,
        [fecha_descarga]   DATE          NULL,
        [pago_programado]  DATE          NULL,
        [placas]           VARCHAR(30)   NULL,
        [bol]              VARCHAR(50)   NULL,
        [transportista]    VARCHAR(100)  NULL,
        [observaciones]    VARCHAR(500)  NULL,
        [updated_by]       INT           NULL,
        [updated_at]       DATETIME      NOT NULL CONSTRAINT DF_cfd_updated DEFAULT (GETDATE()),
        [created_at]       DATETIME      NOT NULL CONSTRAINT DF_cfd_created DEFAULT (GETDATE()),
        CONSTRAINT PK_compras_factura_datos PRIMARY KEY CLUSTERED ([uuid])
    );
END
GO

/* Renglones que no llegan por correo (Repsol, Colosio, etc.). */
IF OBJECT_ID('[TG].[dbo].[compras_renglon_manual]', 'U') IS NULL
BEGIN
    CREATE TABLE [TG].[dbo].[compras_renglon_manual] (
        [id]               INT IDENTITY(1,1) NOT NULL,
        [fecha]            DATE          NOT NULL,
        [est_codgas]       INT           NULL,
        [proveedor]        VARCHAR(100)  NOT NULL,
        [producto]         VARCHAR(10)   NULL,
        [folio]            VARCHAR(50)   NULL,
        [remision]         VARCHAR(100)  NULL,
        [litros]           DECIMAL(18,3) NULL,
        [monto]            DECIMAL(18,2) NULL,
        [iva]              DECIMAL(5,4)  NULL,
        [razon_social]     VARCHAR(255)  NULL,
        [fecha_descarga]   DATE          NULL,
        [vencimiento]      DATE          NULL,
        [pago_programado]  DATE          NULL,
        [monto_pagado]     DECIMAL(18,2) NULL,
        [placas]           VARCHAR(30)   NULL,
        [bol]              VARCHAR(50)   NULL,
        [transportista]    VARCHAR(100)  NULL,
        [observaciones]    VARCHAR(500)  NULL,
        [is_deleted]       BIT           NOT NULL CONSTRAINT DF_crm_deleted DEFAULT (0),
        [created_by]       INT           NULL,
        [created_at]       DATETIME      NOT NULL CONSTRAINT DF_crm_created DEFAULT (GETDATE()),
        [updated_by]       INT           NULL,
        [updated_at]       DATETIME      NULL,
        CONSTRAINT PK_compras_renglon_manual PRIMARY KEY CLUSTERED ([id])
    );
    CREATE INDEX IX_crm_fecha ON [TG].[dbo].[compras_renglon_manual] ([fecha]) WHERE [is_deleted] = 0;
END
GO

/* Quién cambió qué (patrón arqueo_audit_log). tipo: 'F' factura, 'M' renglón manual. */
IF OBJECT_ID('[TG].[dbo].[compras_audit_log]', 'U') IS NULL
BEGIN
    CREATE TABLE [TG].[dbo].[compras_audit_log] (
        [id]               INT IDENTITY(1,1) NOT NULL,
        [tipo]             CHAR(1)       NOT NULL,
        [clave]            VARCHAR(50)   NOT NULL,
        [accion]           VARCHAR(20)   NOT NULL,
        [campo]            VARCHAR(30)   NULL,
        [valor_anterior]   VARCHAR(500)  NULL,
        [valor_nuevo]      VARCHAR(500)  NULL,
        [usuario_id]       INT           NULL,
        [usuario_nombre]   VARCHAR(100)  NULL,
        [fecha]            DATETIME      NOT NULL CONSTRAINT DF_cal_fecha DEFAULT (GETDATE()),
        CONSTRAINT PK_compras_audit_log PRIMARY KEY CLUSTERED ([id])
    );
    CREATE INDEX IX_cal_clave ON [TG].[dbo].[compras_audit_log] ([tipo], [clave]);
END
GO
