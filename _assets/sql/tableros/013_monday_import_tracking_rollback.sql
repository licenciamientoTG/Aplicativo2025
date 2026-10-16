/*
    Guarded rollback for 013_monday_import_tracking.sql.

    DBeaver-compatible: no GO batches. This rollback refuses to drop tracking
    data if any run, source mapping, or checkpoint exists. Export and retain
    provenance, resolve application references, and obtain explicit approval
    before preparing any data-bearing rollback. Never use CASCADE semantics.
*/
SET NOCOUNT ON;
SET XACT_ABORT ON;

IF DB_NAME() <> N'tableros'
    THROW 51310, 'Conecte DBeaver a la base [tableros] antes de ejecutar el rollback.', 1;

BEGIN TRY
    BEGIN TRANSACTION;

    IF OBJECT_ID(N'dbo.tb_monday_import_run', N'U') IS NOT NULL AND EXISTS (SELECT 1 FROM dbo.tb_monday_import_run)
        THROW 51311, 'Rollback detenido: hay runs Monday; conservar/exportar el mapeo y checkpoints.', 1;
    IF OBJECT_ID(N'dbo.tb_monday_import_entity', N'U') IS NOT NULL AND EXISTS (SELECT 1 FROM dbo.tb_monday_import_entity)
        THROW 51312, 'Rollback detenido: hay mapeos de entidades Monday; no se borraron datos.', 1;
    IF OBJECT_ID(N'dbo.tb_monday_import_checkpoint', N'U') IS NOT NULL AND EXISTS (SELECT 1 FROM dbo.tb_monday_import_checkpoint)
        THROW 51313, 'Rollback detenido: hay checkpoints Monday; no se borraron datos.', 1;
    IF OBJECT_ID(N'dbo.tb_monday_import_file_checkpoint', N'U') IS NOT NULL AND EXISTS (SELECT 1 FROM dbo.tb_monday_import_file_checkpoint)
        THROW 51314, 'Rollback detenido: hay checkpoints de archivos/NAS; no se borraron datos.', 1;

    IF OBJECT_ID(N'dbo.tb_monday_import_file_checkpoint', N'U') IS NOT NULL
        DROP TABLE dbo.tb_monday_import_file_checkpoint;
    IF OBJECT_ID(N'dbo.tb_monday_import_checkpoint', N'U') IS NOT NULL
        DROP TABLE dbo.tb_monday_import_checkpoint;
    IF OBJECT_ID(N'dbo.tb_monday_import_entity', N'U') IS NOT NULL
        DROP TABLE dbo.tb_monday_import_entity;
    IF OBJECT_ID(N'dbo.tb_monday_import_run', N'U') IS NOT NULL
        DROP TABLE dbo.tb_monday_import_run;

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF XACT_STATE() <> 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;
