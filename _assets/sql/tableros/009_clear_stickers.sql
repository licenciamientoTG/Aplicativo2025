/*
    TotalGas Tableros - reset the sticker catalog

    This one-time data cleanup removes every sticker association and catalog
    row. It preserves comment text, normal comment attachments, and all board
    files. It does not delete sticker binaries from NAS; after this script
    succeeds, clear only the NAS `stickers` folder separately.

    This operation is destructive and intended only for a deliberate reset.
*/
USE [tableros];
GO
SET NOCOUNT ON;
SET XACT_ABORT ON;

DECLARE @DeletedCommentStickerLinks INT = 0;
DECLARE @DeletedStickers INT = 0;

BEGIN TRY
    BEGIN TRANSACTION;

    DELETE FROM dbo.tb_file_comment_sticker;
    SET @DeletedCommentStickerLinks = @@ROWCOUNT;

    DELETE FROM dbo.tb_user_sticker;
    SET @DeletedStickers = @@ROWCOUNT;

    COMMIT TRANSACTION;

    SELECT
        @DeletedCommentStickerLinks AS deleted_comment_sticker_links,
        @DeletedStickers AS deleted_stickers;
END TRY
BEGIN CATCH
    IF XACT_STATE() <> 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;
GO
