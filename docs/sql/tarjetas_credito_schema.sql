-- docs/sql/tarjetas_credito_schema.sql
-- Schema del módulo Tesorería / Movimientos bancos cheques (/tesoreria/...).
-- Cargos de tarjeta de crédito importados de los PDF de estado de cuenta
-- (por ahora solo Banorte "Empuje Negocio"; la columna banco deja listo otro).
-- Spec: docs/superpowers/specs/2026-08-13-tesoreria-tarjetas-credito-design.md
USE TG;
GO

IF OBJECT_ID('dbo.tarjetas_credito_movimientos') IS NULL
BEGIN
CREATE TABLE dbo.tarjetas_credito_movimientos (
    id                 INT IDENTITY(1,1) PRIMARY KEY,
    banco              NVARCHAR(20)  NOT NULL,           -- 'BANORTE', 'AMEX'
    cuenta             NVARCHAR(20)  NOT NULL,           -- número de cuenta/tarjeta titular (encabezado del estado de cuenta)
    tarjeta            NVARCHAR(20)  NOT NULL,           -- tarjeta que hizo el movimiento (titular o adicional)
    es_adicional       BIT           NOT NULL DEFAULT 0,
    titular_adicional  NVARCHAR(60)  NULL,               -- nombre de quien usa la tarjeta adicional; NULL si es la titular
    fecha              DATE          NOT NULL,
    descripcion        NVARCHAR(150) NOT NULL,           -- concepto del movimiento
    rfc_contraparte    NVARCHAR(20)  NULL,               -- RFC o CURP del comercio
    -- cargo/abono y no un solo "importe": Amex trae pagos a la tarjeta (CR)
    -- en el mismo estado de cuenta que los cargos, mismo patrón que
    -- movimientos_bancarios (cargo/abono NULL exclusivos entre sí).
    cargo              DECIMAL(18,2) NULL,
    abono              DECIMAL(18,2) NULL,
    referencia         NVARCHAR(40)  NULL,
    huella             CHAR(40)      NOT NULL,           -- SHA1 de campos crudos (dedup)
    archivo_origen     NVARCHAR(120) NULL,
    created_by         INT           NULL,               -- Id de usuario TG
    created_at         DATETIME      NOT NULL DEFAULT GETDATE(),
    CONSTRAINT UQ_tarjetas_credito_movimientos_huella UNIQUE (huella)
);
CREATE INDEX IX_tarjetas_credito_movimientos_fecha ON dbo.tarjetas_credito_movimientos (banco, cuenta, fecha);
END
GO

-- Clasificación manual por movimiento (modal "Acciones" en la vista): campos
-- que el PDF no trae, capturados a mano igual que la hoja de Susie's, pero
-- guardados en BD en vez de un Excel aparte.
IF COL_LENGTH('dbo.tarjetas_credito_movimientos', 'departamento') IS NULL
BEGIN
ALTER TABLE dbo.tarjetas_credito_movimientos ADD
    departamento    NVARCHAR(60)  NULL,
    conf_no         NVARCHAR(40)  NULL,
    factura         NVARCHAR(80)  NULL,
    comentarios     NVARCHAR(300) NULL,
    centro_costo    NVARCHAR(60)  NULL,
    clasificado_by  INT           NULL,   -- Id de usuario TG que capturó/editó por última vez
    clasificado_at  DATETIME      NULL;
END
GO
