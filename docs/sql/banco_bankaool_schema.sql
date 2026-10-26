-- docs/sql/banco_bankaool_schema.sql
-- Alta de la tabla de transacciones del reporte de pagos Bankaool en TG.
-- Fecha_Transaccion es la fecha del movimiento del reporte; el layout no
-- proporciona Fecha_Deposito, por lo que esa columna acepta NULL.
-- No se agrega unicidad de negocio: el importador aplica deduplicacion por
-- (Afiliacion, ID_Externo) y por huella de campos para movimientos sin ID unico.
-- Impacto: crea solo dbo.banco_bankaool e indices no unicos; no modifica datos
-- existentes ni otras tablas. Ejecutar en la base TG desde DBeaver.

USE [TG];

IF OBJECT_ID(N'dbo.banco_bankaool', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.banco_bankaool (
        ID_Externo          NVARCHAR(100) NOT NULL,
        Afiliacion          NVARCHAR(30)  NOT NULL,
        Fecha_Transaccion   DATE          NOT NULL,
        Hora                VARCHAR(8)    NULL,
        Monto               DECIMAL(18,2) NOT NULL,
        Codigo_Autorizacion NVARCHAR(50)  NULL,
        Terminal            NVARCHAR(50)  NULL,
        Referencia          NVARCHAR(100) NULL,
        Fecha_Deposito      DATE          NULL,
        Nombre_Archivo      NVARCHAR(260) NULL
    );
END;

-- Busca por afiliacion y periodo usando un rango de Fecha_Transaccion.
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.banco_bankaool')
      AND name = N'IX_banco_bankaool_Afiliacion_FechaTransaccion'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_banco_bankaool_Afiliacion_FechaTransaccion
        ON dbo.banco_bankaool (Afiliacion, Fecha_Transaccion)
        INCLUDE (ID_Externo, Hora, Monto, Codigo_Autorizacion, Terminal, Referencia, Fecha_Deposito, Nombre_Archivo);
END;

-- Acelera la comprobacion de identidad externa; no impone unicidad porque
-- registros ambiguos/repetidos se resuelven en la logica de importacion.
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.banco_bankaool')
      AND name = N'IX_banco_bankaool_Afiliacion_IDExterno'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_banco_bankaool_Afiliacion_IDExterno
        ON dbo.banco_bankaool (Afiliacion, ID_Externo)
        INCLUDE (Fecha_Transaccion, Hora, Monto, Codigo_Autorizacion, Terminal, Referencia);
END;
