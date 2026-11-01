/* Column names are labels, not keys. Column IDs remain unique per board. */
/* Rollback requires renaming duplicate active columns first, then recreating
   UX_tb_column_board_name_active. No data is deleted by this migration. */
IF EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE [object_id] = OBJECT_ID(N'dbo.tb_column')
      AND [name] = N'UX_tb_column_board_name_active'
)
    DROP INDEX [UX_tb_column_board_name_active] ON dbo.tb_column;
