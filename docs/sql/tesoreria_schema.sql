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
    fecha              DATE          NOT NULL,           -- fecha de aplicación/liquidación
    -- Solo Banorte la trae distinta de 'fecha' (su CSV separa FECHA DE
    -- OPERACIÓN de FECHA); NULL en el resto de bancos.
    fecha_operacion    DATE          NULL,
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
    -- 40 y no 20: la Referencia de Banregio llega a 26 y es polimórfica
    -- (clave de rastreo SPEI, folio de crédito o texto libre). Ampliada el
    -- 2026-07-30; para bases ya creadas, alter_movimientos_referencia_40.sql.
    referencia         NVARCHAR(40)  NULL,
    concepto           NVARCHAR(120) NULL,               -- referencia del cliente
    banco_contraparte  NVARCHAR(60)  NULL,               -- solo SPEI/transferencias
    cuenta_contraparte NVARCHAR(30)  NULL,
    nombre_contraparte NVARCHAR(60)  NULL,
    rfc_contraparte    NVARCHAR(15)  NULL,
    clave_rastreo      NVARCHAR(40)  NULL,
    -- 400 y no 150: la DESCRIPCIÓN DETALLADA de Banorte llega a 304 y de ella
    -- se extrae la contraparte de los SPEI (ampliada el 2026-07-30; para bases
    -- ya creadas, alter_movimientos_descripcion_larga_400.sql).
    descripcion_larga  NVARCHAR(400) NULL,
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

-- Para bases ya creadas antes de fecha_operacion (Banorte: FECHA DE
-- OPERACIÓN vs FECHA venían distintas y el parser solo guardaba una).
IF COL_LENGTH('dbo.movimientos_bancarios', 'fecha_operacion') IS NULL
BEGIN
ALTER TABLE dbo.movimientos_bancarios ADD fecha_operacion DATE NULL;
END
GO

-- Posición del movimiento dentro de su día (banco, cuenta, fecha), aparte
-- del id. El id solo refleja el orden de aplicación real cuando TODOS los
-- movimientos de un día vienen del mismo archivo/import: si dos archivos de
-- origen distinto traen movimientos del mismo día (p.ej. Santander: el TXT
-- diario y el CSV de Consulta de movimientos > Chequeras para un periodo ya
-- cerrado), el que se importó después queda con id más alto sin importar su
-- hora real, y ORDER BY fecha, id ya no reconstruye la cadena de saldos.
--
-- Por ahora solo se llena y se usa para SANTANDER (ver
-- MovimientosBancariosModel::recalcula_orden_dia_santander()); el resto de
-- bancos sigue ordenando por id como siempre. NULL para todos hasta correr
-- el backfill (docs/sql/backfill_orden_dia_santander.sql).
IF COL_LENGTH('dbo.movimientos_bancarios', 'orden_dia') IS NULL
BEGIN
ALTER TABLE dbo.movimientos_bancarios ADD orden_dia INT NULL;
END
GO
