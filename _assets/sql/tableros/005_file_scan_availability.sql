/*
   Permite adjuntos sin antivirus configurado y elimina el bloqueo por aprobación manual.
   Ejecutar en la base tableros, junto con el despliegue de la aplicación.
*/
SET XACT_ABORT ON;
BEGIN TRANSACTION;

IF OBJECT_ID(N'dbo.tb_file_version', N'U') IS NOT NULL
BEGIN
    IF EXISTS (
        SELECT 1 FROM sys.check_constraints
        WHERE parent_object_id = OBJECT_ID(N'dbo.tb_file_version')
          AND name = N'CK_tb_file_version_scan_status'
    )
        ALTER TABLE dbo.tb_file_version DROP CONSTRAINT CK_tb_file_version_scan_status;

    IF EXISTS (
        SELECT 1 FROM sys.default_constraints
        WHERE parent_object_id = OBJECT_ID(N'dbo.tb_file_version')
          AND name = N'DF_tb_file_version_scan_status'
    )
        ALTER TABLE dbo.tb_file_version DROP CONSTRAINT DF_tb_file_version_scan_status;

    UPDATE dbo.tb_file_version
       SET scan_status = 'unscanned'
     WHERE scan_status = 'pending' AND deleted_at IS NULL;

    ALTER TABLE dbo.tb_file_version
        ADD CONSTRAINT DF_tb_file_version_scan_status DEFAULT ('unscanned') FOR scan_status;

    ALTER TABLE dbo.tb_file_version WITH CHECK
        ADD CONSTRAINT CK_tb_file_version_scan_status
        CHECK (scan_status IN ('pending', 'clean', 'unscanned', 'quarantined', 'failed'));
    ALTER TABLE dbo.tb_file_version CHECK CONSTRAINT CK_tb_file_version_scan_status;
END;

COMMIT TRANSACTION;
