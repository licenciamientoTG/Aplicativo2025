-- ============================================================
-- AMEX Comisiones — Tablas para almacenar el reporte de envíos
-- Ejecutar en la BD TG (192.168.0.6)
-- ============================================================

-- Log de cargas (un registro por archivo subido)
CREATE TABLE AMEX_Envios_Uploads (
    id             INT IDENTITY(1,1) PRIMARY KEY,
    nombre_archivo VARCHAR(255)  NOT NULL,
    anio           INT           NOT NULL,
    mes            INT           NOT NULL,
    total_filas    INT           NOT NULL DEFAULT 0,
    uploaded_by    INT           NULL,
    created_at     DATETIME      NOT NULL DEFAULT GETDATE()
);

-- Detalle de cada fila del CSV
CREATE TABLE AMEX_Envios (
    id                INT IDENTITY(1,1) PRIMARY KEY,
    upload_id         INT           NOT NULL REFERENCES AMEX_Envios_Uploads(id) ON DELETE CASCADE,
    establecimiento   VARCHAR(30)   NOT NULL,   -- número afiliación normalizado (sin ceros líderes)
    fecha_transaccion DATE          NOT NULL,   -- Fecha de la transacción (cuando cobró la tarjeta)
    fecha_pago        DATE          NOT NULL,   -- Fecha de pago (cuando llega a la cuenta bancaria)
    cargos_totales    DECIMAL(18,2) NOT NULL,   -- bruto (lo que registra CG)
    monto_pago        DECIMAL(18,2) NOT NULL,   -- neto depositado (lo que aparece en Tesorería)
    comision          DECIMAL(18,2) NOT NULL,   -- descuento AMEX
    iva               DECIMAL(18,2) NOT NULL,   -- IVA sobre la comisión
    numero_factura    VARCHAR(64)   NOT NULL    -- "Número de factura del monto del resumen de los cargos"
);

-- Índice único para antiduplicado entre múltiples archivos del mismo mes
CREATE UNIQUE INDEX UX_AMEX_Envios_Dedup
    ON AMEX_Envios (establecimiento, fecha_transaccion, fecha_pago, cargos_totales, numero_factura);

-- Índice para búsquedas rápidas por fecha_pago (usado en get_amex_comisiones)
CREATE INDEX IX_AMEX_Envios_FechaPago
    ON AMEX_Envios (fecha_pago);

-- ============================================================
-- Migración para ambientes existentes
-- ============================================================
IF COL_LENGTH('AMEX_Envios', 'numero_factura') IS NULL
BEGIN
    ALTER TABLE AMEX_Envios ADD numero_factura VARCHAR(64) NOT NULL CONSTRAINT DF_AMEX_Envios_numero_factura DEFAULT('');
END;

IF EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'UX_AMEX_Envios_Dedup' AND object_id = OBJECT_ID('AMEX_Envios'))
BEGIN
    DROP INDEX UX_AMEX_Envios_Dedup ON AMEX_Envios;
END;

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'UX_AMEX_Envios_Dedup' AND object_id = OBJECT_ID('AMEX_Envios'))
BEGIN
    CREATE UNIQUE INDEX UX_AMEX_Envios_Dedup
        ON AMEX_Envios (establecimiento, fecha_transaccion, fecha_pago, cargos_totales, numero_factura);
END;
