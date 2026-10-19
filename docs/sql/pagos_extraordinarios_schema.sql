-- =============================================================
-- Pagos extraordinarios: esquema, catalogo de motivos, permisos 106-110
-- y destinatarios de correo (modo pruebas).
-- Script idempotente: se puede correr mas de una vez.
-- Spec: docs/superpowers/specs/2026-10-19-pagos-extraordinarios-design.md
-- Estatus de la solicitud (extra_payment_requests.status) lo define el
-- workflow en PHP; el folio es el id de la solicitud.
-- =============================================================
USE [TG]
GO

-- Solicitudes ------------------------------------------------
IF NOT EXISTS (SELECT 1 FROM sys.tables WHERE name = 'extra_payment_requests')
BEGIN
    CREATE TABLE [TG].[dbo].[extra_payment_requests] (
        id              INT IDENTITY(1,1) PRIMARY KEY,
        fecha           DATE          NOT NULL,
        user_id         INT           NOT NULL,        -- TG.dbo.Usuario.Id (solicitante)
        departamento_id INT           NULL,            -- TG.dbo.Departamentos.Id
        tipo            VARCHAR(10)   NOT NULL,
        reason_id       INT           NOT NULL,        -- extra_payment_reasons.id
        motivo_otros    VARCHAR(200)  NULL,
        total           DECIMAL(18,2) NOT NULL DEFAULT 0,
        status          INT           NOT NULL,
        created_at      DATETIME      NOT NULL DEFAULT GETDATE(),
        updated_at      DATETIME      NOT NULL DEFAULT GETDATE(),
        CONSTRAINT CK_extra_payment_requests_tipo CHECK (tipo IN ('individual','multiple'))
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_extra_payment_requests_status')
    CREATE INDEX IX_extra_payment_requests_status ON [TG].[dbo].[extra_payment_requests] (status);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_extra_payment_requests_user')
    CREATE INDEX IX_extra_payment_requests_user ON [TG].[dbo].[extra_payment_requests] (user_id);
GO

-- Lineas -----------------------------------------------------
IF NOT EXISTS (SELECT 1 FROM sys.tables WHERE name = 'extra_payment_lines')
BEGIN
    CREATE TABLE [TG].[dbo].[extra_payment_lines] (
        id                   INT IDENTITY(1,1) PRIMARY KEY,
        request_id           INT           NOT NULL,   -- extra_payment_requests.id
        empresa_cod          INT           NOT NULL,
        centro_costos        VARCHAR(80)   NOT NULL,
        proveedor            VARCHAR(200)  NOT NULL,
        factura              VARCHAR(60)   NULL,
        fecha_factura        DATE          NULL,
        orden_compra         VARCHAR(60)   NULL,
        descripcion          VARCHAR(400)  NOT NULL,
        monto                DECIMAL(18,2) NOT NULL,
        fecha_pago_requerida DATE          NOT NULL,
        comentario_urgencia  VARCHAR(300)  NULL
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_extra_payment_lines_request')
    CREATE INDEX IX_extra_payment_lines_request ON [TG].[dbo].[extra_payment_lines] (request_id);
GO

-- Catalogo de motivos ---------------------------------------
IF NOT EXISTS (SELECT 1 FROM sys.tables WHERE name = 'extra_payment_reasons')
BEGIN
    CREATE TABLE [TG].[dbo].[extra_payment_reasons] (
        id             INT IDENTITY(1,1) PRIMARY KEY,
        nombre         VARCHAR(120) NOT NULL,
        requiere_texto BIT          NOT NULL DEFAULT 0,
        activo         BIT          NOT NULL DEFAULT 1,
        orden          INT          NOT NULL DEFAULT 0
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM [TG].[dbo].[extra_payment_reasons] WHERE nombre = 'Pocos días de crédito o proveedor no da crédito')
    INSERT INTO [TG].[dbo].[extra_payment_reasons] (nombre, requiere_texto, activo, orden)
    VALUES ('Pocos días de crédito o proveedor no da crédito', 0, 1, 1);
IF NOT EXISTS (SELECT 1 FROM [TG].[dbo].[extra_payment_reasons] WHERE nombre = 'Solicitud de los Sr. Díaz')
    INSERT INTO [TG].[dbo].[extra_payment_reasons] (nombre, requiere_texto, activo, orden)
    VALUES ('Solicitud de los Sr. Díaz', 0, 1, 2);
IF NOT EXISTS (SELECT 1 FROM [TG].[dbo].[extra_payment_reasons] WHERE nombre = 'Viaje esporádico')
    INSERT INTO [TG].[dbo].[extra_payment_reasons] (nombre, requiere_texto, activo, orden)
    VALUES ('Viaje esporádico', 0, 1, 3);
IF NOT EXISTS (SELECT 1 FROM [TG].[dbo].[extra_payment_reasons] WHERE nombre = 'Cortes de servicios o créditos')
    INSERT INTO [TG].[dbo].[extra_payment_reasons] (nombre, requiere_texto, activo, orden)
    VALUES ('Cortes de servicios o créditos', 0, 1, 4);
IF NOT EXISTS (SELECT 1 FROM [TG].[dbo].[extra_payment_reasons] WHERE nombre = 'No se programó con tiempo')
    INSERT INTO [TG].[dbo].[extra_payment_reasons] (nombre, requiere_texto, activo, orden)
    VALUES ('No se programó con tiempo', 0, 1, 5);
IF NOT EXISTS (SELECT 1 FROM [TG].[dbo].[extra_payment_reasons] WHERE nombre = 'Otros')
    INSERT INTO [TG].[dbo].[extra_payment_reasons] (nombre, requiere_texto, activo, orden)
    VALUES ('Otros', 1, 1, 6);
GO

-- Aprobaciones (step 1 = Cuentas por Pagar, 2 = Admon y Finanzas) ----
IF NOT EXISTS (SELECT 1 FROM sys.tables WHERE name = 'extra_payment_approvals')
BEGIN
    CREATE TABLE [TG].[dbo].[extra_payment_approvals] (
        id                INT IDENTITY(1,1) PRIMARY KEY,
        request_id        INT          NOT NULL,   -- extra_payment_requests.id
        step              INT          NOT NULL,
        permission_number INT          NOT NULL,
        user_id           INT          NOT NULL,   -- TG.dbo.Usuario.Id
        decision          VARCHAR(10)  NOT NULL,
        comentario        VARCHAR(400) NULL,
        created_at        DATETIME     NOT NULL DEFAULT GETDATE(),
        is_active         BIT          NOT NULL DEFAULT 1,  -- 0 al reenviar la solicitud
        CONSTRAINT CK_extra_payment_approvals_decision CHECK (decision IN ('aprobada','rechazada'))
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_extra_payment_approvals_request')
    CREATE INDEX IX_extra_payment_approvals_request ON [TG].[dbo].[extra_payment_approvals] (request_id);
GO

-- Documentos (soporte / comprobante), mismo patron que tarjetas_credito_documentos
IF NOT EXISTS (SELECT 1 FROM sys.tables WHERE name = 'extra_payment_documents')
BEGIN
    CREATE TABLE [TG].[dbo].[extra_payment_documents] (
        id                 INT IDENTITY(1,1) PRIMARY KEY,
        request_id         INT          NOT NULL,  -- extra_payment_requests.id
        kind               VARCHAR(20)  NOT NULL,
        file_path          VARCHAR(300) NOT NULL,
        file_extension     VARCHAR(10)  NOT NULL,
        original_filename  VARCHAR(255) NOT NULL,
        file_size          INT          NOT NULL,
        created_by         INT          NOT NULL,  -- TG.dbo.Usuario.Id
        created_at         DATETIME     NOT NULL DEFAULT GETDATE(),
        is_deleted         INT          NOT NULL DEFAULT 0,
        deleted_at         DATETIME     NULL,
        deleted_by         INT          NULL,      -- TG.dbo.Usuario.Id
        CONSTRAINT CK_extra_payment_documents_kind CHECK (kind IN ('soporte','comprobante'))
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_extra_payment_documents_request')
    CREATE INDEX IX_extra_payment_documents_request
        ON [TG].[dbo].[extra_payment_documents] (request_id, is_deleted);
GO

-- Pago de Tesoreria (uno por solicitud) -----------------------
IF NOT EXISTS (SELECT 1 FROM sys.tables WHERE name = 'extra_payment_payments')
BEGIN
    CREATE TABLE [TG].[dbo].[extra_payment_payments] (
        id              INT IDENTITY(1,1) PRIMARY KEY,
        request_id      INT           NOT NULL,
        bank_account_id INT           NOT NULL,
        fecha_pago      DATE          NOT NULL,
        referencia      VARCHAR(80)   NOT NULL,
        monto           DECIMAL(18,2) NOT NULL,
        user_id         INT           NOT NULL,    -- TG.dbo.Usuario.Id
        created_at      DATETIME      NOT NULL DEFAULT GETDATE(),
        CONSTRAINT UQ_extra_payment_payments_request UNIQUE (request_id)
    );
END
GO

-- Bitacora ----------------------------------------------------
IF NOT EXISTS (SELECT 1 FROM sys.tables WHERE name = 'extra_payment_audit_log')
BEGIN
    CREATE TABLE [TG].[dbo].[extra_payment_audit_log] (
        id         INT IDENTITY(1,1) PRIMARY KEY,
        request_id INT          NOT NULL,
        user_id    INT          NOT NULL,
        action     VARCHAR(30)  NOT NULL,
        detail     VARCHAR(500) NULL,
        created_at DATETIME     NOT NULL DEFAULT GETDATE()
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_extra_payment_audit_log_request')
    CREATE INDEX IX_extra_payment_audit_log_request ON [TG].[dbo].[extra_payment_audit_log] (request_id);
GO

-- Permisos 106-110 (tg_permissions.id es IDENTITY) -------------
SET IDENTITY_INSERT [TG].[dbo].[tg_permissions] ON;
GO
IF NOT EXISTS (SELECT 1 FROM [TG].[dbo].[tg_permissions] WHERE id = 106)
    INSERT INTO [TG].[dbo].[tg_permissions] (id, action, department, description, status, updated_at, created_at)
    VALUES (106, 'read', 'Tesorería', 'Pagos extra: crear/ver propias', 1, GETDATE(), GETDATE());
IF NOT EXISTS (SELECT 1 FROM [TG].[dbo].[tg_permissions] WHERE id = 107)
    INSERT INTO [TG].[dbo].[tg_permissions] (id, action, department, description, status, updated_at, created_at)
    VALUES (107, 'read', 'Tesorería', 'Pagos extra: VoBo CxP', 1, GETDATE(), GETDATE());
IF NOT EXISTS (SELECT 1 FROM [TG].[dbo].[tg_permissions] WHERE id = 108)
    INSERT INTO [TG].[dbo].[tg_permissions] (id, action, department, description, status, updated_at, created_at)
    VALUES (108, 'read', 'Tesorería', 'Pagos extra: Autoriza Admón/Finanzas', 1, GETDATE(), GETDATE());
IF NOT EXISTS (SELECT 1 FROM [TG].[dbo].[tg_permissions] WHERE id = 109)
    INSERT INTO [TG].[dbo].[tg_permissions] (id, action, department, description, status, updated_at, created_at)
    VALUES (109, 'read', 'Tesorería', 'Pagos extra: Pago Tesorería', 1, GETDATE(), GETDATE());
IF NOT EXISTS (SELECT 1 FROM [TG].[dbo].[tg_permissions] WHERE id = 110)
    INSERT INTO [TG].[dbo].[tg_permissions] (id, action, department, description, status, updated_at, created_at)
    VALUES (110, 'read', 'Tesorería', 'Pagos extra: Ver todas', 1, GETDATE(), GETDATE());
GO
SET IDENTITY_INSERT [TG].[dbo].[tg_permissions] OFF;
GO

-- Destinatarios de correo: PRUEBAS (reemplazar por los reales despues) ---
IF NOT EXISTS (SELECT 1 FROM [TG].[dbo].[payment_notification_recipients] WHERE evento = 'pago_extra_cxp')
    INSERT INTO [TG].[dbo].[payment_notification_recipients] (email, nombre, evento, activo)
    VALUES ('alejandro.martinez@totalgas.com', 'Alejandro Martinez (pruebas)', 'pago_extra_cxp', 1);
IF NOT EXISTS (SELECT 1 FROM [TG].[dbo].[payment_notification_recipients] WHERE evento = 'pago_extra_admon')
    INSERT INTO [TG].[dbo].[payment_notification_recipients] (email, nombre, evento, activo)
    VALUES ('alejandro.martinez@totalgas.com', 'Alejandro Martinez (pruebas)', 'pago_extra_admon', 1);
IF NOT EXISTS (SELECT 1 FROM [TG].[dbo].[payment_notification_recipients] WHERE evento = 'pago_extra_tesoreria')
    INSERT INTO [TG].[dbo].[payment_notification_recipients] (email, nombre, evento, activo)
    VALUES ('alejandro.martinez@totalgas.com', 'Alejandro Martinez (pruebas)', 'pago_extra_tesoreria', 1);
GO
