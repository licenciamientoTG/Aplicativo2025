/*
    EFC Conciliación de cortes de Praxedis (estación 40)
    Base de datos: TG
    Crear las tablas de captura, lote OCR e historial para la estación 40.
    Este archivo es idempotente: puede volver a ejecutarse sin duplicar objetos.
    Incluye cambios de esquema idempotentes y no modifica datos existentes.
    Compatible con DBeaver: ejecutar como script con delimitador ; (no requiere GO).
*/
USE [TG];

IF OBJECT_ID(N'dbo.efc_conc_praxedis_cortes', N'U') IS NULL
    CREATE TABLE dbo.efc_conc_praxedis_cortes (
        id                  BIGINT IDENTITY(1,1) NOT NULL,
        estacion_id         INT NOT NULL,
        fecha_operativa     DATE NOT NULL,
        turno               TINYINT NOT NULL,
        isla                NVARCHAR(100) NULL,
        ventas              DECIMAL(19,4) NULL,
        donativo            DECIMAL(19,4) NULL,
        vale_interno        DECIMAL(19,4) NULL,
        vale_externo        DECIMAL(19,4) NULL,
        efectivo            DECIMAL(19,4) NULL,
        [dollar]            DECIMAL(19,4) NULL,
        estado              NVARCHAR(40) NOT NULL,
        creado_en           DATETIME2(0) NOT NULL CONSTRAINT DF_efc_prax_cortes_creado DEFAULT SYSDATETIME(),
        actualizado_en      DATETIME2(0) NOT NULL CONSTRAINT DF_efc_prax_cortes_actualizado DEFAULT SYSDATETIME(),
        CONSTRAINT PK_efc_conc_praxedis_cortes PRIMARY KEY CLUSTERED (id),
        CONSTRAINT UQ_efc_prax_cortes_est_fecha_turno UNIQUE (estacion_id, fecha_operativa, turno),
        CONSTRAINT CK_efc_prax_cortes_estacion CHECK (estacion_id = 40)
    );

IF OBJECT_ID(N'dbo.efc_conc_praxedis_lotes', N'U') IS NULL
    CREATE TABLE dbo.efc_conc_praxedis_lotes (
        id                      BIGINT IDENTITY(1,1) NOT NULL,
        estacion_id             INT NOT NULL CONSTRAINT DF_efc_prax_lotes_estacion DEFAULT 40,
        usuario_id              INT NULL,
        usuario_nombre          NVARCHAR(150) NULL,
        creado_en               DATETIME2(0) NOT NULL CONSTRAINT DF_efc_prax_lotes_creado DEFAULT SYSDATETIME(),
        /* Columna heredada; las capturas nuevas sólo conservan una huella SHA-256. */
        imagen_original         VARBINARY(MAX) NULL,
        /* SHA-256 hexadecimal (64 caracteres), calculado por el servidor de aplicación. */
        sha256_servidor         CHAR(64) NOT NULL,
        texto_ocr               NVARCHAR(MAX) NULL,
        registros_insertados    INT NOT NULL CONSTRAINT DF_efc_prax_lotes_insertados DEFAULT 0,
        registros_actualizados  INT NOT NULL CONSTRAINT DF_efc_prax_lotes_actualizados DEFAULT 0,
        registros_sin_cambios   INT NOT NULL CONSTRAINT DF_efc_prax_lotes_sin_cambios DEFAULT 0,
        CONSTRAINT PK_efc_conc_praxedis_lotes PRIMARY KEY CLUSTERED (id),
        CONSTRAINT CK_efc_prax_lotes_estacion CHECK (estacion_id = 40),
        CONSTRAINT CK_efc_prax_lotes_conteos CHECK (
            registros_insertados >= 0 AND registros_actualizados >= 0 AND registros_sin_cambios >= 0
        )
    );

/* Evita guardar capturas nuevas en SQL Server; conserva intactos los blobs heredados. */
IF EXISTS (
    SELECT 1
    FROM sys.columns
    WHERE object_id = OBJECT_ID(N'dbo.efc_conc_praxedis_lotes')
      AND name = N'imagen_original'
      AND is_nullable = 0
)
    ALTER TABLE dbo.efc_conc_praxedis_lotes ALTER COLUMN imagen_original VARBINARY(MAX) NULL;

IF OBJECT_ID(N'dbo.efc_conc_praxedis_historial', N'U') IS NULL
    CREATE TABLE dbo.efc_conc_praxedis_historial (
        id                  BIGINT IDENTITY(1,1) NOT NULL,
        corte_id            BIGINT NOT NULL,
        lote_id             BIGINT NULL,
        accion              VARCHAR(6) NOT NULL,
        datos_anteriores    NVARCHAR(MAX) NULL,
        datos_nuevos        NVARCHAR(MAX) NULL,
        usuario_id          INT NULL,
        usuario_nombre      NVARCHAR(150) NULL,
        creado_en           DATETIME2(0) NOT NULL CONSTRAINT DF_efc_prax_hist_creado DEFAULT SYSDATETIME(),
        CONSTRAINT PK_efc_conc_praxedis_historial PRIMARY KEY CLUSTERED (id),
        CONSTRAINT CK_efc_prax_hist_accion CHECK (accion IN ('INSERT', 'UPDATE'))
    );

/* Índices de consulta y relaciones; los nombres se verifican para permitir reejecución. */
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'dbo.efc_conc_praxedis_cortes') AND name = N'IX_efc_prax_cortes_fecha_estado')
    CREATE NONCLUSTERED INDEX IX_efc_prax_cortes_fecha_estado
        ON dbo.efc_conc_praxedis_cortes (fecha_operativa, estado) INCLUDE (turno, isla);

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'dbo.efc_conc_praxedis_lotes') AND name = N'IX_efc_prax_lotes_creado')
    CREATE NONCLUSTERED INDEX IX_efc_prax_lotes_creado ON dbo.efc_conc_praxedis_lotes (creado_en DESC);

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'dbo.efc_conc_praxedis_historial') AND name = N'IX_efc_prax_hist_corte_creado')
    CREATE NONCLUSTERED INDEX IX_efc_prax_hist_corte_creado ON dbo.efc_conc_praxedis_historial (corte_id, creado_en DESC);

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'dbo.efc_conc_praxedis_historial') AND name = N'IX_efc_prax_hist_lote')
    CREATE NONCLUSTERED INDEX IX_efc_prax_hist_lote ON dbo.efc_conc_praxedis_historial (lote_id) WHERE lote_id IS NOT NULL;

IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = N'FK_efc_prax_hist_corte' AND parent_object_id = OBJECT_ID(N'dbo.efc_conc_praxedis_historial'))
    ALTER TABLE dbo.efc_conc_praxedis_historial WITH CHECK ADD CONSTRAINT FK_efc_prax_hist_corte
        FOREIGN KEY (corte_id) REFERENCES dbo.efc_conc_praxedis_cortes (id);

IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = N'FK_efc_prax_hist_lote' AND parent_object_id = OBJECT_ID(N'dbo.efc_conc_praxedis_historial'))
    ALTER TABLE dbo.efc_conc_praxedis_historial WITH CHECK ADD CONSTRAINT FK_efc_prax_hist_lote
        FOREIGN KEY (lote_id) REFERENCES dbo.efc_conc_praxedis_lotes (id);

/*
ROLLBACK MANUAL — NO EJECUTAR COMO PARTE DE ESTA MIGRACIÓN.
La reversión elimina las tablas de esta función y todos sus lotes, imágenes,
cortes e historial. Antes de cualquier reversión, respalde los datos y confirme
que la aplicación ya no los necesita. Ejecutar manualmente en TG solo con una
aprobación específica de operación/despliegue:

USE [TG];
GO
DROP TABLE dbo.efc_conc_praxedis_historial;
DROP TABLE dbo.efc_conc_praxedis_cortes;
DROP TABLE dbo.efc_conc_praxedis_lotes;
GO
*/





