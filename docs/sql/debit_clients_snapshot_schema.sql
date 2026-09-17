-- docs/sql/debit_clients_snapshot_schema.sql
-- Schema del snapshot diario de clientes débito (/income/clients, tab
-- "Estado de Cuenta Foto"). Esquema de vigencia (SCD2): una fila por
-- cliente solo se reabre cuando cambia alguno de los 6 valores numéricos.
-- Spec: docs/superpowers/specs/2026-09-17-debit-clients-snapshot-design.md
USE TG;
GO

IF OBJECT_ID('dbo.debit_clients_snapshot') IS NULL
BEGIN
CREATE TABLE dbo.debit_clients_snapshot (
    id              INT IDENTITY(1,1) PRIMARY KEY,
    codcli          INT            NOT NULL,
    cliente         NVARCHAR(255)  NOT NULL,
    fecha_desde     DATE           NOT NULL,
    fecha_hasta     DATE           NULL,
    saldo_inicial   DECIMAL(18,2)  NOT NULL,
    anticipos_dia   DECIMAL(18,2)  NOT NULL,
    consumos_dia    DECIMAL(18,2)  NOT NULL,
    saldo_final     DECIMAL(18,2)  NOT NULL,
    saldo_sistema   DECIMAL(18,2)  NOT NULL,
    saldo_vehiculos DECIMAL(18,2)  NOT NULL,
    updated_at      DATETIME       NOT NULL DEFAULT GETDATE(),
    CONSTRAINT UQ_debit_snapshot_cliente_desde UNIQUE (codcli, fecha_desde)
);
CREATE INDEX IX_debit_snapshot_vigente ON dbo.debit_clients_snapshot (codcli, fecha_hasta);
END
GO
