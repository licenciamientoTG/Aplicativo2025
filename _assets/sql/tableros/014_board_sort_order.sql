/*
    Persistent sibling order for sidebar boards.
    Run in [tableros] BEFORE deploying the PHP changes that read sort_order.
    Idempotent, additive SQL Server migration; existing boards retain their
    alphabetical order within each workspace/folder on the first execution.
    Folder order already exists and does not require a schema change.
    No GO batches: suitable for DBeaver.
*/
SET NOCOUNT ON;
SET XACT_ABORT ON;

IF DB_NAME() <> N'tableros'
    THROW 51400, 'Conecte a la base [tableros] antes de ejecutar esta migracion.', 1;

BEGIN TRY
    BEGIN TRANSACTION;
    IF COL_LENGTH(N'dbo.tb_board', N'sort_order') IS NULL
    BEGIN
        ALTER TABLE dbo.tb_board ADD sort_order INT NOT NULL
            CONSTRAINT DF_tb_board_sort_order DEFAULT (0) WITH VALUES;
        -- Dynamic SQL compiles after the new column exists in this batch.
        EXEC sp_executesql N'
            ;WITH ordered AS (
                SELECT id, ROW_NUMBER() OVER (
                    PARTITION BY workspace_id, folder_id ORDER BY name, id
                ) - 1 AS position
                FROM dbo.tb_board
            )
            UPDATE b SET sort_order = o.position
            FROM dbo.tb_board b INNER JOIN ordered o ON o.id = b.id;';
    END;
    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;
