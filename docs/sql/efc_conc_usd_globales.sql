/*
   Forward migration: global daily USD Banorte allocations for Díaz Gas.
   Run against TG in DBeaver. Existing assignments/history are never dropped.
*/
IF OBJECT_ID('dbo.efc_conc_usd_globales','U') IS NULL
BEGIN
    CREATE TABLE dbo.efc_conc_usd_globales (
        id INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_efc_conc_usd_globales PRIMARY KEY,
        fecha_operativa DATE NOT NULL,
        movimiento_bancario_id INT NOT NULL,
        estado VARCHAR(12) NOT NULL CONSTRAINT DF_efc_conc_usd_globales_estado DEFAULT 'ACTIVA',
        creado_por INT NULL,
        creado_en DATETIME NOT NULL CONSTRAINT DF_efc_conc_usd_globales_creado DEFAULT GETDATE(),
        liberado_por INT NULL,
        liberado_en DATETIME NULL
    );
END;
IF NOT EXISTS(SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID('dbo.efc_conc_usd_globales') AND name='UX_efc_conc_usd_globales_movimiento_activo')
    CREATE UNIQUE INDEX UX_efc_conc_usd_globales_movimiento_activo
        ON dbo.efc_conc_usd_globales(movimiento_bancario_id) WHERE estado='ACTIVA';
