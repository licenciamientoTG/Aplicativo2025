-- =============================================
-- MODULO: Renegociacion de Proveedores
-- BD: TG
-- =============================================

-- Tabla: Historial de importaciones
IF OBJECT_ID('[TG].[dbo].[reneg_uploads]', 'U') IS NULL
BEGIN
    CREATE TABLE [TG].[dbo].[reneg_uploads] (
        id              INT IDENTITY(1,1) PRIMARY KEY,
        nombre_archivo  VARCHAR(255)    NOT NULL,
        total_filas     INT             NOT NULL DEFAULT 0,
        uploaded_by     INT             NULL,
        created_at      DATETIME        NOT NULL DEFAULT GETDATE()
    );
END;

-- Tabla: Pagos pendientes (hoja PENDIENTE PAGO del Excel)
IF OBJECT_ID('[TG].[dbo].[reneg_pagos_pendientes]', 'U') IS NULL
BEGIN
    CREATE TABLE [TG].[dbo].[reneg_pagos_pendientes] (
        id                INT IDENTITY(1,1) PRIMARY KEY,
        upload_id         INT             NULL REFERENCES [TG].[dbo].[reneg_uploads](id) ON DELETE CASCADE,
        oc                VARCHAR(50)     NULL,
        req               VARCHAR(50)     NULL,
        fecha_aut         DATE            NULL,
        rfc               VARCHAR(30)     NULL,
        proveedor         NVARCHAR(200)   NULL,
        responsable       NVARCHAR(150)   NULL,
        razon_social      NVARCHAR(200)   NULL,
        factura           VARCHAR(100)    NULL,
        fecha_factura     DATE            NULL,
        iva               DECIMAL(5,4)    NULL,
        subtotal          DECIMAL(18,2)   NULL,
        impt_iva          DECIMAL(18,2)   NULL,
        descuento         DECIMAL(18,2)   NULL,
        ret_4pct          DECIMAL(18,2)   NULL,
        iva_ret           DECIMAL(18,2)   NULL,
        isr_ret           DECIMAL(18,2)   NULL,
        total             DECIMAL(18,2)   NULL,
        importe_dlls      DECIMAL(18,2)   NULL,
        cc                INT             NULL,
        concepto          NVARCHAR(500)   NULL,
        prov              VARCHAR(50)     NULL,
        autoriza_oc       NVARCHAR(150)   NULL,
        metodo_pago       VARCHAR(10)     NULL,
        fecha_vencimiento DATE            NULL,
        dias_vencimiento  INT             NULL,
        fecha_pago_real   DATE            NULL,
        created_at        DATETIME        NOT NULL DEFAULT GETDATE()
    );
    CREATE INDEX IX_reneg_responsable ON [TG].[dbo].[reneg_pagos_pendientes] (responsable);
    CREATE INDEX IX_reneg_upload      ON [TG].[dbo].[reneg_pagos_pendientes] (upload_id);
END;

-- Tabla: Contactos responsable -> correo
IF OBJECT_ID('[TG].[dbo].[reneg_contactos]', 'U') IS NULL
BEGIN
    CREATE TABLE [TG].[dbo].[reneg_contactos] (
        id              INT IDENTITY(1,1) PRIMARY KEY,
        responsable     NVARCHAR(150)   NOT NULL,
        correo          VARCHAR(255)    NOT NULL,
        activo          BIT             NOT NULL DEFAULT 1,
        CONSTRAINT UQ_reneg_responsable UNIQUE (responsable)
    );
END;

-- Tabla: Log de correos enviados
IF OBJECT_ID('[TG].[dbo].[reneg_email_log]', 'U') IS NULL
BEGIN
    CREATE TABLE [TG].[dbo].[reneg_email_log] (
        id              INT IDENTITY(1,1) PRIMARY KEY,
        upload_id       INT             NULL,
        responsable     NVARCHAR(150)   NOT NULL,
        correo          VARCHAR(255)    NOT NULL,
        total_facturas  INT             NOT NULL DEFAULT 0,
        monto_total     DECIMAL(18,2)   NULL,
        enviado_por     INT             NULL,
        sent_at         DATETIME        NOT NULL DEFAULT GETDATE(),
        status          VARCHAR(10)     NOT NULL DEFAULT 'sent',
        error_msg       NVARCHAR(500)   NULL
    );
END;
