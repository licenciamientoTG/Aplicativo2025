-- docs/sql/tesoreria_schema.sql
-- Schema del módulo Tesorería (/tesoreria/...).
-- Movimientos bancarios importados de los TXT diarios de los bancos
-- (por ahora solo Santander; la columna banco deja listo Banorte).
-- Spec: docs/superpowers/specs/2026-07-22-tesoreria-movimientos-bancos-design.md
USE TG;
GO

IF OBJECT_ID('dbo.movimientos_bancarios') IS NULL
BEGIN
CREATE TABLE dbo.movimientos_bancarios (
    id                 INT IDENTITY(1,1) PRIMARY KEY,
    banco              NVARCHAR(20)  NOT NULL,           -- 'SANTANDER'
    cuenta             NVARCHAR(20)  NOT NULL,
    fecha              DATE          NOT NULL,
    hora               CHAR(5)       NULL,               -- HH:MM
    sucursal           NVARCHAR(10)  NULL,
    clave_trans        NVARCHAR(10)  NULL,
    -- 150 y no 60: Bankaool manda descripciones de hasta 75 caracteres que
    -- traen el nombre de la contraparte y la CLABE destino (ampliada el
    -- 2026-07-29; para bases ya creadas, alter_movimientos_descripcion_150.sql).
    descripcion        NVARCHAR(150) NULL,               -- DEPOSITO VTAS, ABONO POR SPEI -NOMBRE - ...
    cargo              DECIMAL(18,2) NULL,               -- signo '-' del TXT
    abono              DECIMAL(18,2) NULL,               -- signo '+' del TXT
    saldo              DECIMAL(18,2) NULL,               -- saldo tras el movimiento
    referencia         NVARCHAR(20)  NULL,
    concepto           NVARCHAR(120) NULL,               -- referencia del cliente
    banco_contraparte  NVARCHAR(60)  NULL,               -- solo SPEI/transferencias
    cuenta_contraparte NVARCHAR(30)  NULL,
    nombre_contraparte NVARCHAR(60)  NULL,
    rfc_contraparte    NVARCHAR(15)  NULL,
    clave_rastreo      NVARCHAR(40)  NULL,
    descripcion_larga  NVARCHAR(150) NULL,
    huella             CHAR(40)      NOT NULL,           -- SHA1 de campos crudos (dedup)
    secuencia          INT           NULL,               -- No. Secuencia de Afirme (orden de aplicación); NULL en Santander
    archivo_origen     NVARCHAR(120) NULL,
    created_by         INT           NULL,               -- Id de usuario TG
    created_at         DATETIME      NOT NULL DEFAULT GETDATE(),
    CONSTRAINT UQ_movimientos_bancarios_huella UNIQUE (huella)
);
CREATE INDEX IX_movimientos_bancarios_fecha ON dbo.movimientos_bancarios (banco, cuenta, fecha);
END
GO
