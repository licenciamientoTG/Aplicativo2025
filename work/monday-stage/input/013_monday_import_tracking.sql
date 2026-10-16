/*
    Monday import run, source-ID map, entity checkpoints and NAS file checkpoints.

    Additive/idempotent migration for SQL Server 2016+; run in database
    [tableros] after 012_access_control.sql. DBeaver-compatible: no GO batches.
    This migration creates tracking tables only. It does not import data or
    alter existing business tables.

    Import contract:
      * One run covers the inventoried 504 active/archived source boards.
      * Boards must be created with actor 6371 and private visibility.
      * Stable identity is (source_account_id, entity_type, monday_id), never
        a source or local name.
      * Store hashes and allowlisted/sanitized error summaries only. Never put
        API keys, bearer tokens, signed URLs, cell contents, or raw API bodies
        in this schema.
      * All FKs intentionally use NO ACTION; rollback must preserve provenance.
*/
SET NOCOUNT ON;
SET XACT_ABORT ON;

IF DB_NAME() <> N'tableros'
    THROW 51300, 'Conecte DBeaver a la base [tableros] antes de ejecutar esta migracion.', 1;

BEGIN TRY
    BEGIN TRANSACTION;

    IF OBJECT_ID(N'dbo.tb_monday_import_run', N'U') IS NULL
    BEGIN
        CREATE TABLE dbo.tb_monday_import_run (
            [id] BIGINT IDENTITY(1,1) NOT NULL,
            [source_account_id] NVARCHAR(128) NOT NULL,
            [run_key] NVARCHAR(128) NOT NULL,
            [expected_board_count] INT NOT NULL
                CONSTRAINT [DF_tb_monday_import_run_expected_count] DEFAULT (504),
            [active_board_count] INT NULL,
            [archived_board_count] INT NULL,
            [inventory_complete] BIT NOT NULL
                CONSTRAINT [DF_tb_monday_import_run_inventory_complete] DEFAULT (0),
            [inventory_sha256] CHAR(64) NULL,
            [source_cutoff_at] DATETIME2(3) NULL,
            [source_api_version] NVARCHAR(32) NULL,
            [actor_user_id] INT NOT NULL
                CONSTRAINT [DF_tb_monday_import_run_actor] DEFAULT (6371),
            [target_visibility] VARCHAR(16) NOT NULL
                CONSTRAINT [DF_tb_monday_import_run_visibility] DEFAULT ('private'),
            [status] VARCHAR(20) NOT NULL
                CONSTRAINT [DF_tb_monday_import_run_status] DEFAULT ('pending'),
            [error_code] VARCHAR(64) NULL,
            [error_summary] NVARCHAR(500) NULL,
            [created_at] DATETIME2(3) NOT NULL
                CONSTRAINT [DF_tb_monday_import_run_created_at] DEFAULT (SYSUTCDATETIME()),
            [updated_at] DATETIME2(3) NOT NULL
                CONSTRAINT [DF_tb_monday_import_run_updated_at] DEFAULT (SYSUTCDATETIME()),
            [completed_at] DATETIME2(3) NULL,
            [version] ROWVERSION,
            CONSTRAINT [PK_tb_monday_import_run] PRIMARY KEY CLUSTERED ([id]),
            CONSTRAINT [UQ_tb_monday_import_run_source_key] UNIQUE ([source_account_id], [run_key]),
            CONSTRAINT [UQ_tb_monday_import_run_id_source] UNIQUE ([id], [source_account_id]),
            CONSTRAINT [CK_tb_monday_import_run_expected_count] CHECK ([expected_board_count] = 504),
            CONSTRAINT [CK_tb_monday_import_run_counts] CHECK (
                ([active_board_count] IS NULL OR [active_board_count] >= 0) AND
                ([archived_board_count] IS NULL OR [archived_board_count] >= 0) AND
                ([inventory_complete] = 0 OR
                    ([active_board_count] IS NOT NULL AND [archived_board_count] IS NOT NULL AND
                     [active_board_count] + [archived_board_count] = [expected_board_count] AND
                     [inventory_sha256] IS NOT NULL))
            ),
            CONSTRAINT [CK_tb_monday_import_run_inventory_hash] CHECK (
                [inventory_sha256] IS NULL OR
                (LEN([inventory_sha256]) = 64 AND [inventory_sha256] NOT LIKE '%[^0-9A-Fa-f]%')
            ),
            CONSTRAINT [CK_tb_monday_import_run_actor] CHECK ([actor_user_id] = 6371),
            CONSTRAINT [CK_tb_monday_import_run_visibility] CHECK ([target_visibility] = 'private'),
            CONSTRAINT [CK_tb_monday_import_run_status] CHECK ([status] IN (
                'pending', 'inventory_ready', 'in_progress', 'verifying', 'complete', 'failed', 'paused'
            )),
            CONSTRAINT [CK_tb_monday_import_run_error_sanitized] CHECK (
                [error_summary] IS NULL OR
                (CHARINDEX(N'://', LOWER([error_summary])) = 0 AND
                 CHARINDEX(N'bearer ', LOWER([error_summary])) = 0 AND
                 CHARINDEX(N'api_key', LOWER([error_summary])) = 0 AND
                 CHARINDEX(N'access_token', LOWER([error_summary])) = 0)
            )
        );
    END;

    IF OBJECT_ID(N'dbo.tb_monday_import_entity', N'U') IS NULL
    BEGIN
        CREATE TABLE dbo.tb_monday_import_entity (
            [id] BIGINT IDENTITY(1,1) NOT NULL,
            [source_account_id] NVARCHAR(128) NOT NULL,
            [entity_type] VARCHAR(16) NOT NULL,
            [monday_id] NVARCHAR(128) NOT NULL,
            [local_id] BIGINT NULL,
            [source_status] VARCHAR(12) NULL,
            [payload_sha256] CHAR(64) NULL,
            [source_updated_at] DATETIME2(3) NULL,
            [last_seen_run_id] BIGINT NULL,
            [created_at] DATETIME2(3) NOT NULL
                CONSTRAINT [DF_tb_monday_import_entity_created_at] DEFAULT (SYSUTCDATETIME()),
            [updated_at] DATETIME2(3) NOT NULL
                CONSTRAINT [DF_tb_monday_import_entity_updated_at] DEFAULT (SYSUTCDATETIME()),
            [version] ROWVERSION,
            CONSTRAINT [PK_tb_monday_import_entity] PRIMARY KEY CLUSTERED ([id]),
            CONSTRAINT [UQ_tb_monday_import_entity_source] UNIQUE
                ([source_account_id], [entity_type], [monday_id]),
            CONSTRAINT [UQ_tb_monday_import_entity_id_source] UNIQUE ([id], [source_account_id]),
            CONSTRAINT [UQ_tb_monday_import_entity_id_type] UNIQUE ([id], [entity_type]),
            CONSTRAINT [FK_tb_monday_import_entity_last_run] FOREIGN KEY ([last_seen_run_id])
                REFERENCES dbo.tb_monday_import_run ([id]),
            CONSTRAINT [CK_tb_monday_import_entity_type] CHECK ([entity_type] IN (
                'workspace', 'space', 'folder', 'board', 'group', 'column', 'item', 'cell', 'file'
            )),
            CONSTRAINT [CK_tb_monday_import_entity_source_status] CHECK (
                [source_status] IS NULL OR [source_status] IN ('active', 'archived')
            ),
            CONSTRAINT [CK_tb_monday_import_entity_hash] CHECK (
                [payload_sha256] IS NULL OR
                (LEN([payload_sha256]) = 64 AND [payload_sha256] NOT LIKE '%[^0-9A-Fa-f]%')
            )
        );
    END;

    IF OBJECT_ID(N'dbo.tb_monday_import_checkpoint', N'U') IS NULL
    BEGIN
        CREATE TABLE dbo.tb_monday_import_checkpoint (
            [run_id] BIGINT NOT NULL,
            [entity_id] BIGINT NOT NULL,
            [source_account_id] NVARCHAR(128) NOT NULL,
            [status] VARCHAR(16) NOT NULL
                CONSTRAINT [DF_tb_monday_import_checkpoint_status] DEFAULT ('pending'),
            [payload_sha256] CHAR(64) NULL,
            [attempt_count] INT NOT NULL
                CONSTRAINT [DF_tb_monday_import_checkpoint_attempts] DEFAULT (0),
            [error_code] VARCHAR(64) NULL,
            [error_summary] NVARCHAR(500) NULL,
            [started_at] DATETIME2(3) NULL,
            [completed_at] DATETIME2(3) NULL,
            [updated_at] DATETIME2(3) NOT NULL
                CONSTRAINT [DF_tb_monday_import_checkpoint_updated_at] DEFAULT (SYSUTCDATETIME()),
            [version] ROWVERSION,
            CONSTRAINT [PK_tb_monday_import_checkpoint] PRIMARY KEY CLUSTERED ([run_id], [entity_id]),
            CONSTRAINT [FK_tb_monday_import_checkpoint_run_source] FOREIGN KEY ([run_id], [source_account_id])
                REFERENCES dbo.tb_monday_import_run ([id], [source_account_id]),
            CONSTRAINT [FK_tb_monday_import_checkpoint_entity_source] FOREIGN KEY ([entity_id], [source_account_id])
                REFERENCES dbo.tb_monday_import_entity ([id], [source_account_id]),
            CONSTRAINT [CK_tb_monday_import_checkpoint_status] CHECK ([status] IN (
                'pending', 'in_progress', 'complete', 'failed', 'skipped'
            )),
            CONSTRAINT [CK_tb_monday_import_checkpoint_attempts] CHECK ([attempt_count] >= 0),
            CONSTRAINT [CK_tb_monday_import_checkpoint_hash] CHECK (
                [payload_sha256] IS NULL OR
                (LEN([payload_sha256]) = 64 AND [payload_sha256] NOT LIKE '%[^0-9A-Fa-f]%')
            ),
            CONSTRAINT [CK_tb_monday_import_checkpoint_error_sanitized] CHECK (
                [error_summary] IS NULL OR
                (CHARINDEX(N'://', LOWER([error_summary])) = 0 AND
                 CHARINDEX(N'bearer ', LOWER([error_summary])) = 0 AND
                 CHARINDEX(N'api_key', LOWER([error_summary])) = 0 AND
                 CHARINDEX(N'access_token', LOWER([error_summary])) = 0)
            )
        );
    END;

    IF OBJECT_ID(N'dbo.tb_monday_import_file_checkpoint', N'U') IS NULL
    BEGIN
        CREATE TABLE dbo.tb_monday_import_file_checkpoint (
            [run_id] BIGINT NOT NULL,
            [entity_id] BIGINT NOT NULL,
            [entity_type] VARCHAR(16) NOT NULL
                CONSTRAINT [DF_tb_monday_import_file_checkpoint_entity_type] DEFAULT ('file'),
            [transfer_status] VARCHAR(16) NOT NULL
                CONSTRAINT [DF_tb_monday_import_file_checkpoint_status] DEFAULT ('pending'),
            [original_sha256] CHAR(64) NULL,
            [original_byte_size] BIGINT NULL,
            [derivatives_status] VARCHAR(16) NOT NULL
                CONSTRAINT [DF_tb_monday_import_file_checkpoint_derivatives] DEFAULT ('pending'),
            [attempt_count] INT NOT NULL
                CONSTRAINT [DF_tb_monday_import_file_checkpoint_attempts] DEFAULT (0),
            [error_code] VARCHAR(64) NULL,
            [error_summary] NVARCHAR(500) NULL,
            [updated_at] DATETIME2(3) NOT NULL
                CONSTRAINT [DF_tb_monday_import_file_checkpoint_updated_at] DEFAULT (SYSUTCDATETIME()),
            [version] ROWVERSION,
            CONSTRAINT [PK_tb_monday_import_file_checkpoint] PRIMARY KEY CLUSTERED ([run_id], [entity_id]),
            CONSTRAINT [FK_tb_monday_import_file_checkpoint_entity_run]
                FOREIGN KEY ([run_id], [entity_id])
                REFERENCES dbo.tb_monday_import_checkpoint ([run_id], [entity_id]),
            CONSTRAINT [FK_tb_monday_import_file_checkpoint_file_entity]
                FOREIGN KEY ([entity_id], [entity_type])
                REFERENCES dbo.tb_monday_import_entity ([id], [entity_type]),
            CONSTRAINT [CK_tb_monday_import_file_checkpoint_entity_type] CHECK ([entity_type] = 'file'),
            CONSTRAINT [CK_tb_monday_import_file_checkpoint_transfer] CHECK ([transfer_status] IN (
                'pending', 'downloading', 'uploaded', 'registered', 'complete', 'failed'
            )),
            CONSTRAINT [CK_tb_monday_import_file_checkpoint_derivatives] CHECK ([derivatives_status] IN (
                'not_required', 'pending', 'in_progress', 'complete', 'failed'
            )),
            CONSTRAINT [CK_tb_monday_import_file_checkpoint_size] CHECK (
                [original_byte_size] IS NULL OR [original_byte_size] >= 0
            ),
            CONSTRAINT [CK_tb_monday_import_file_checkpoint_attempts] CHECK ([attempt_count] >= 0),
            CONSTRAINT [CK_tb_monday_import_file_checkpoint_hash] CHECK (
                [original_sha256] IS NULL OR
                (LEN([original_sha256]) = 64 AND [original_sha256] NOT LIKE '%[^0-9A-Fa-f]%')
            ),
            CONSTRAINT [CK_tb_monday_import_file_checkpoint_error_sanitized] CHECK (
                [error_summary] IS NULL OR
                (CHARINDEX(N'://', LOWER([error_summary])) = 0 AND
                 CHARINDEX(N'bearer ', LOWER([error_summary])) = 0 AND
                 CHARINDEX(N'api_key', LOWER([error_summary])) = 0 AND
                 CHARINDEX(N'access_token', LOWER([error_summary])) = 0)
            )
        );
    END;

    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE [object_id] = OBJECT_ID(N'dbo.tb_monday_import_run') AND [name] = N'IX_tb_monday_import_run_status_updated')
        CREATE INDEX [IX_tb_monday_import_run_status_updated] ON dbo.tb_monday_import_run ([status], [updated_at], [id]);
    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE [object_id] = OBJECT_ID(N'dbo.tb_monday_import_entity') AND [name] = N'IX_tb_monday_import_entity_last_run')
        CREATE INDEX [IX_tb_monday_import_entity_last_run] ON dbo.tb_monday_import_entity ([last_seen_run_id], [entity_type], [id]) INCLUDE ([local_id], [source_status]);
    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE [object_id] = OBJECT_ID(N'dbo.tb_monday_import_entity') AND [name] = N'UX_tb_monday_import_entity_local')
        CREATE UNIQUE INDEX [UX_tb_monday_import_entity_local] ON dbo.tb_monday_import_entity ([source_account_id], [entity_type], [local_id]) WHERE [local_id] IS NOT NULL;
    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE [object_id] = OBJECT_ID(N'dbo.tb_monday_import_checkpoint') AND [name] = N'IX_tb_monday_import_checkpoint_resume')
        CREATE INDEX [IX_tb_monday_import_checkpoint_resume] ON dbo.tb_monday_import_checkpoint ([run_id], [status], [updated_at]) INCLUDE ([entity_id], [payload_sha256], [attempt_count]);
    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE [object_id] = OBJECT_ID(N'dbo.tb_monday_import_file_checkpoint') AND [name] = N'IX_tb_monday_import_file_checkpoint_resume')
        CREATE INDEX [IX_tb_monday_import_file_checkpoint_resume] ON dbo.tb_monday_import_file_checkpoint ([run_id], [transfer_status], [updated_at]) INCLUDE ([entity_id], [derivatives_status], [original_sha256]);

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF XACT_STATE() <> 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;
