-- Tabla de auditoría de movimientos sobre payment_request_invoices
-- (agregar / quitar facturas de una requisición de pago).
-- Sigue el mismo patrón que TG.dbo.TabuladorHistorial.
USE [TG]
GO

CREATE TABLE [dbo].[PaymentRequestAuditLog] (
    [Id]                 INT IDENTITY(1,1) PRIMARY KEY,
    [PaymentRequestId]   INT NOT NULL,
    [InvoiceId]          INT NULL,
    [Operacion]          VARCHAR(20) NOT NULL, -- 'ADD_INVOICE' | 'REMOVE_INVOICE'
    [Fecha]              DATETIME NOT NULL DEFAULT GETDATE(),
    [UsuarioAplicativo]  INT NULL,
    [UsuarioNombre]      VARCHAR(150) NULL,
    [DatosAnteriores]    NVARCHAR(MAX) NULL, -- snapshot JSON antes del cambio (NULL en ADD)
    [DatosNuevos]        NVARCHAR(MAX) NULL, -- snapshot JSON después del cambio (NULL en REMOVE)
    [AccountingGroupId]  INT NULL            -- accounting_group_id del pago AL MOMENTO del movimiento
);
GO

CREATE INDEX IX_PaymentRequestAuditLog_PaymentRequestId
    ON [dbo].[PaymentRequestAuditLog] ([PaymentRequestId]);
GO
