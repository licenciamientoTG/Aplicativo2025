/* =====================================================================
   Migracion de la descarga de facturas por correo a ApiER + NAS
   Plan: docs/plan_migracion_facturas_apier_nas.md  (BD: TG)
   Solo CREA tablas nuevas e inserta el semaforo. No modifica FacturasRecibidas.
   Idempotente: se puede correr mas de una vez.
   ===================================================================== */
USE TG;
GO

/* 1) Una fila por cada correo de Gmail revisado (dedupe + auditoria) */
IF OBJECT_ID('dbo.FacturasCorreoMensajes','U') IS NULL
BEGIN
    CREATE TABLE dbo.FacturasCorreoMensajes (
        Id           bigint IDENTITY(1,1) NOT NULL CONSTRAINT PK_FacturasCorreoMensajes PRIMARY KEY,
        Buzon        varchar(100)  NOT NULL,
        GmailId      varchar(32)   NOT NULL,
        Proveedor    varchar(30)   NULL,
        Remitente    varchar(150)  NULL,
        Asunto       varchar(300)  NULL,
        FechaCorreo  datetime2(0)  NULL,
        Adjuntos     int           NOT NULL CONSTRAINT DF_FCM_Adjuntos DEFAULT (0),
        Estado       varchar(25)   NOT NULL,   -- descargado | sin_adjunto_objetivo | omitido_prefijo | error
        Detalle      varchar(500)  NULL,
        Uuid         varchar(40)   NULL,       -- solo si el correo trae una sola factura
        Motor        varchar(10)   NOT NULL CONSTRAINT DF_FCM_Motor DEFAULT ('apier'),  -- sombra | apier
        Creado       datetime2(0)  NOT NULL CONSTRAINT DF_FCM_Creado DEFAULT (SYSDATETIME()),
        CONSTRAINT UQ_FacturasCorreoMensajes_Buzon_Gmail UNIQUE (Buzon, GmailId)
    );
    CREATE INDEX IX_FacturasCorreoMensajes_Prov_Fecha ON dbo.FacturasCorreoMensajes (Proveedor, FechaCorreo);
END
GO

/* 2) Semaforo por proveedor (una fila por proveedor) */
IF OBJECT_ID('dbo.FacturasCorreoProveedorEstado','U') IS NULL
BEGIN
    CREATE TABLE dbo.FacturasCorreoProveedorEstado (
        Proveedor           varchar(30)  NOT NULL CONSTRAINT PK_FacturasCorreoProveedorEstado PRIMARY KEY,
        Motor               varchar(10)  NOT NULL CONSTRAINT DF_FCPE_Motor DEFAULT ('python'),  -- python | sombra | apier
        Activo              bit          NOT NULL CONSTRAINT DF_FCPE_Activo DEFAULT (1),
        CutoverEn           datetime2(0) NULL,
        ListoEn             datetime2(0) NULL,
        HistoricoMigradoEn  datetime2(0) NULL,
        UltimoCicloOk       datetime2(0) NULL,
        UltimoCorreo        datetime2(0) NULL,
        UltimaFactura       datetime2(0) NULL,
        PendientesCola      int          NOT NULL CONSTRAINT DF_FCPE_Pend DEFAULT (0),
        ErroresCola         int          NOT NULL CONSTRAINT DF_FCPE_Err  DEFAULT (0),
        Notas               varchar(500) NULL
    );
END
GO

/* 3) Semilla: todos arrancan en python (motor actual). Lobo inactivo. */
MERGE dbo.FacturasCorreoProveedorEstado AS t
USING (VALUES
    ('petrotal',1),('premiergas',1),('aemsa',1),('enerey',1),
    ('essafuel',1),('tesoro',1),('mcg',1),('lobo',0)
) AS s(Proveedor, Activo)
ON t.Proveedor = s.Proveedor
WHEN NOT MATCHED THEN INSERT (Proveedor, Motor, Activo) VALUES (s.Proveedor, 'python', s.Activo);
GO

SELECT * FROM dbo.FacturasCorreoProveedorEstado ORDER BY Proveedor;
GO

/* ---------------------------------------------------------------------
   FASE 3 (NO correr ahora): respaldo antes del UPDATE masivo de rutas.
   Hoy hay dos formatos en RutaArchivo:
     - 'C:\Software\TareasProgramadas\...\attachments\<prov>\...'  (14,326)
     - 'attachments\<prov>\...'                                     (~7,125)
     - vacia                                                        (2,135)
   --------------------------------------------------------------------- */
-- IF OBJECT_ID('dbo.FacturasRecibidas_rutas_bkp','U') IS NULL
--     SELECT Id, RutaArchivo, RutaXml, SYSDATETIME() AS Fecha
--     INTO dbo.FacturasRecibidas_rutas_bkp
--     FROM dbo.FacturasRecibidas;
