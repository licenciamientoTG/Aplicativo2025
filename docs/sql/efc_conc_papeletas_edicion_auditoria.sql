/*
    Esquema para editar y crear papeletas REGIO con historial.
    Base de datos destino: TG.
    Ejecutar en DBeaver después de que existan:
      dbo.efc_conc_analiticos_papeletas
      dbo.efc_conc_analiticos_importaciones
    Script idempotente: puede volver a ejecutarse si una ejecución anterior
    se interrumpió. No usa GO y no modifica ni elimina datos importados.
*/

IF OBJECT_ID('dbo.efc_conc_analiticos_papeletas','U') IS NULL
BEGIN
    ;THROW 50001, N'Falta dbo.efc_conc_analiticos_papeletas; ejecute primero el esquema del importador REGIO.', 1;
END;

IF OBJECT_ID('dbo.efc_conc_analiticos_importaciones','U') IS NULL
BEGIN
    ;THROW 50002, N'Falta dbo.efc_conc_analiticos_importaciones; ejecute primero el esquema del importador REGIO.', 1;
END;

IF OBJECT_ID('dbo.efc_conc_analiticos_correcciones_fecha','U') IS NULL
    CREATE TABLE dbo.efc_conc_analiticos_correcciones_fecha (
        id INT IDENTITY PRIMARY KEY,
        papeleta_id INT NOT NULL,
        fecha_original DATE NOT NULL,
        fecha_efectiva DATE NOT NULL,
        usuario_id INT NULL,
        activo BIT NOT NULL DEFAULT 1,
        creado_en DATETIME NOT NULL DEFAULT GETDATE(),
        actualizado_en DATETIME NULL,
        actualizado_por INT NULL,
        CONSTRAINT FK_efc_conc_analiticos_correcciones_fecha_papeleta
            FOREIGN KEY(papeleta_id) REFERENCES dbo.efc_conc_analiticos_papeletas(id)
    );

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id=OBJECT_ID('dbo.efc_conc_analiticos_correcciones_fecha')
      AND name='UX_efc_conc_analiticos_corr_fecha_activa'
)
    CREATE UNIQUE INDEX UX_efc_conc_analiticos_corr_fecha_activa
        ON dbo.efc_conc_analiticos_correcciones_fecha(papeleta_id)
        WHERE activo=1;

IF OBJECT_ID('dbo.efc_conc_analiticos_correcciones_estacion','U') IS NULL
    CREATE TABLE dbo.efc_conc_analiticos_correcciones_estacion (
        id INT IDENTITY PRIMARY KEY,
        papeleta_id INT NOT NULL,
        estacion_original_id INT NOT NULL,
        estacion_corregida_id INT NOT NULL,
        usuario_id INT NULL,
        activo BIT NOT NULL DEFAULT 1,
        creado_en DATETIME NOT NULL DEFAULT GETDATE(),
        actualizado_en DATETIME NULL,
        actualizado_por INT NULL,
        CONSTRAINT FK_efc_conc_analiticos_corr_estacion_papeleta
            FOREIGN KEY(papeleta_id) REFERENCES dbo.efc_conc_analiticos_papeletas(id)
    );

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id=OBJECT_ID('dbo.efc_conc_analiticos_correcciones_estacion')
      AND name='UX_efc_conc_analiticos_corr_estacion_activa'
)
    CREATE UNIQUE INDEX UX_efc_conc_analiticos_corr_estacion_activa
        ON dbo.efc_conc_analiticos_correcciones_estacion(papeleta_id)
        WHERE activo=1;

IF COL_LENGTH('dbo.efc_conc_analiticos_papeletas','creado_por') IS NULL
    ALTER TABLE dbo.efc_conc_analiticos_papeletas ADD creado_por INT NULL;

IF COL_LENGTH('dbo.efc_conc_analiticos_papeletas','captura_manual') IS NULL
    ALTER TABLE dbo.efc_conc_analiticos_papeletas
        ADD captura_manual BIT NOT NULL
            CONSTRAINT DF_efc_conc_analiticos_papeletas_manual DEFAULT 0;

IF OBJECT_ID('dbo.efc_conc_analiticos_correcciones_datos','U') IS NULL
    CREATE TABLE dbo.efc_conc_analiticos_correcciones_datos (
        id INT IDENTITY PRIMARY KEY,
        papeleta_id INT NOT NULL,
        fecha_efectiva DATE NOT NULL,
        hora_efectiva NVARCHAR(100) NULL,
        estacion_id INT NOT NULL,
        remesa_efectiva NVARCHAR(100) NULL,
        cuenta_efectiva NVARCHAR(100) NULL,
        declarado_mn DECIMAL(18,2) NULL,
        real_mn DECIMAL(18,2) NULL,
        declarado_usd DECIMAL(18,2) NULL,
        real_usd DECIMAL(18,2) NULL,
        usuario_id INT NOT NULL,
        activo BIT NOT NULL DEFAULT 1,
        creado_en DATETIME NOT NULL DEFAULT GETDATE(),
        CONSTRAINT FK_efc_conc_analiticos_corr_datos_papeleta
            FOREIGN KEY(papeleta_id) REFERENCES dbo.efc_conc_analiticos_papeletas(id)
    );

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id=OBJECT_ID('dbo.efc_conc_analiticos_correcciones_datos')
      AND name='UX_efc_conc_analiticos_corr_datos_activa'
)
    CREATE UNIQUE INDEX UX_efc_conc_analiticos_corr_datos_activa
        ON dbo.efc_conc_analiticos_correcciones_datos(papeleta_id)
        WHERE activo=1;

IF OBJECT_ID('dbo.efc_conc_analiticos_papeletas_auditoria','U') IS NULL
    CREATE TABLE dbo.efc_conc_analiticos_papeletas_auditoria (
        id BIGINT IDENTITY PRIMARY KEY,
        papeleta_id INT NOT NULL,
        operacion VARCHAR(20) NOT NULL,
        valores_anteriores NVARCHAR(MAX) NULL,
        valores_nuevos NVARCHAR(MAX) NULL,
        usuario_id INT NOT NULL,
        creado_en DATETIME NOT NULL DEFAULT GETDATE(),
        CONSTRAINT FK_efc_conc_analiticos_papeletas_auditoria_papeleta
            FOREIGN KEY(papeleta_id) REFERENCES dbo.efc_conc_analiticos_papeletas(id)
    );

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id=OBJECT_ID('dbo.efc_conc_analiticos_papeletas_auditoria')
      AND name='IX_efc_conc_analiticos_papeletas_auditoria_papeleta'
)
    CREATE INDEX IX_efc_conc_analiticos_papeletas_auditoria_papeleta
        ON dbo.efc_conc_analiticos_papeletas_auditoria(papeleta_id,creado_en,id);

IF OBJECT_ID('dbo.v_efc_conc_analiticos_papeletas_efectivas','V') IS NULL
    EXEC(N'CREATE VIEW dbo.v_efc_conc_analiticos_papeletas_efectivas AS
        SELECT P.*,
            COALESCE(D.fecha_efectiva,C.fecha_efectiva,P.fecha_reportada) effective_date,
            COALESCE(D.hora_efectiva,P.hora_original) effective_time,
            COALESCE(D.estacion_id,S.estacion_corregida_id,P.estacion_id) effective_station_id,
            COALESCE(D.remesa_efectiva,P.remesa_numero) effective_remittance,
            COALESCE(D.cuenta_efectiva,P.cuenta_mn_original) effective_account,
            COALESCE(D.declarado_mn,P.dice_contener_mn) effective_declared_mn,
            COALESCE(D.real_mn,P.real_mn) effective_real_mn,
            COALESCE(D.declarado_usd,P.dice_contener_usd) effective_declared_usd,
            COALESCE(D.real_usd,P.real_usd) effective_real_usd,
            ISNULL(COALESCE(D.real_mn,P.real_mn),0)-ISNULL(COALESCE(D.declarado_mn,P.dice_contener_mn),0) effective_difference_mn,
            ISNULL(COALESCE(D.real_usd,P.real_usd),0)-ISNULL(COALESCE(D.declarado_usd,P.dice_contener_usd),0) effective_difference_usd,
            P.fecha_reportada source_date
        FROM dbo.efc_conc_analiticos_papeletas P
        LEFT JOIN dbo.efc_conc_analiticos_correcciones_fecha C ON C.papeleta_id=P.id AND C.activo=1
        LEFT JOIN dbo.efc_conc_analiticos_correcciones_estacion S ON S.papeleta_id=P.id AND S.activo=1
        LEFT JOIN dbo.efc_conc_analiticos_correcciones_datos D ON D.papeleta_id=P.id AND D.activo=1');
