/*
    TotalGas Tableros - initial schema

    Requires SQL Server 2016 or later (ISJSON is used). Deployment
    prerequisite: before running this file, the server-side owner must confirm
    the target SQL Server host and have the required backup or
    recovery point. This script creates the separate [tableros] database when
    absent, and creates missing objects only. It never drops or rewrites data.
    An existing [tableros] database with another collation is left untouched
    and causes the script to stop; resolve that with the DBA first.

    Run with a SQL Server client that supports GO batch separators (for example
    SSMS or sqlcmd). Permission rows in [TG] are seeded separately by
    002_seed_tg_permissions.sql. TG user IDs stored below are integers only;
    there are no foreign keys to TG or any other database.

    Contract notes:
      * [tb_workspace] represents both workspaces and spaces; spaces use a
        parent workspace. The stable default workspace/folder keys are
        'general' and 'tableros'.
      * The 26 allowed dynamic field types are constrained on [tb_column].[type].
      * [version] is SQL Server rowversion, suitable for optimistic concurrency.
      * No foreign key cascades are used. Soft deletion is available on mutable
        business data; activity and automation run history are retained.
*/
SET NOCOUNT ON;

IF DB_ID(N'tableros') IS NULL
BEGIN
    EXEC(N'CREATE DATABASE [tableros] COLLATE Modern_Spanish_CI_AS;');
END;
GO

IF DB_ID(N'tableros') IS NULL
    THROW 50100, 'No se pudo crear o encontrar la base [tableros].', 1;

IF NOT EXISTS (
    SELECT 1
    FROM sys.databases
    WHERE [name] = N'tableros'
      AND [collation_name] = N'Modern_Spanish_CI_AS'
)
    THROW 50101, 'La base [tableros] debe usar la intercalacion Modern_Spanish_CI_AS. No se altero la base existente.', 1;
GO

USE [tableros];
GO
SET NOCOUNT ON;

IF OBJECT_ID(N'dbo.tb_workspace', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.tb_workspace (
        [id] BIGINT IDENTITY(1,1) NOT NULL,
        [workspace_key] NVARCHAR(80) NOT NULL,
        [parent_workspace_id] BIGINT NULL,
        [workspace_type] VARCHAR(16) NOT NULL
            CONSTRAINT [DF_tb_workspace_workspace_type] DEFAULT ('workspace'),
        [name] NVARCHAR(200) NOT NULL,
        [description] NVARCHAR(1000) NULL,
        [visibility] VARCHAR(16) NOT NULL
            CONSTRAINT [DF_tb_workspace_visibility] DEFAULT ('private'),
        [created_by] INT NULL,
        [created_at] DATETIME2(3) NOT NULL
            CONSTRAINT [DF_tb_workspace_created_at] DEFAULT (SYSUTCDATETIME()),
        [updated_at] DATETIME2(3) NOT NULL
            CONSTRAINT [DF_tb_workspace_updated_at] DEFAULT (SYSUTCDATETIME()),
        [deleted_at] DATETIME2(3) NULL,
        [version] ROWVERSION,
        CONSTRAINT [PK_tb_workspace] PRIMARY KEY CLUSTERED ([id]),
        CONSTRAINT [FK_tb_workspace_parent]
            FOREIGN KEY ([parent_workspace_id]) REFERENCES dbo.tb_workspace ([id]),
        CONSTRAINT [CK_tb_workspace_type_parent]
            CHECK (
                ([workspace_type] = 'workspace' AND [parent_workspace_id] IS NULL)
                OR ([workspace_type] = 'space' AND [parent_workspace_id] IS NOT NULL)
            ),
        CONSTRAINT [CK_tb_workspace_visibility]
            CHECK ([visibility] IN ('private', 'workspace', 'public'))
    );
END;

IF OBJECT_ID(N'dbo.tb_workspace_member', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.tb_workspace_member (
        [id] BIGINT IDENTITY(1,1) NOT NULL,
        [workspace_id] BIGINT NOT NULL,
        [user_id] INT NOT NULL,
        [role] VARCHAR(16) NOT NULL,
        [invited_by] INT NULL,
        [created_at] DATETIME2(3) NOT NULL
            CONSTRAINT [DF_tb_workspace_member_created_at] DEFAULT (SYSUTCDATETIME()),
        [updated_at] DATETIME2(3) NOT NULL
            CONSTRAINT [DF_tb_workspace_member_updated_at] DEFAULT (SYSUTCDATETIME()),
        [deleted_at] DATETIME2(3) NULL,
        [version] ROWVERSION,
        CONSTRAINT [PK_tb_workspace_member] PRIMARY KEY CLUSTERED ([id]),
        CONSTRAINT [FK_tb_workspace_member_workspace]
            FOREIGN KEY ([workspace_id]) REFERENCES dbo.tb_workspace ([id]),
        CONSTRAINT [CK_tb_workspace_member_role]
            CHECK ([role] IN ('owner', 'editor', 'viewer'))
    );
END;

IF OBJECT_ID(N'dbo.tb_folder', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.tb_folder (
        [id] BIGINT IDENTITY(1,1) NOT NULL,
        [workspace_id] BIGINT NOT NULL,
        [parent_folder_id] BIGINT NULL,
        [folder_key] NVARCHAR(80) NOT NULL,
        [name] NVARCHAR(200) NOT NULL,
        [color] VARCHAR(32) NULL,
        [sort_order] INT NOT NULL
            CONSTRAINT [DF_tb_folder_sort_order] DEFAULT (0),
        [created_by] INT NULL,
        [created_at] DATETIME2(3) NOT NULL
            CONSTRAINT [DF_tb_folder_created_at] DEFAULT (SYSUTCDATETIME()),
        [updated_at] DATETIME2(3) NOT NULL
            CONSTRAINT [DF_tb_folder_updated_at] DEFAULT (SYSUTCDATETIME()),
        [deleted_at] DATETIME2(3) NULL,
        [version] ROWVERSION,
        CONSTRAINT [PK_tb_folder] PRIMARY KEY CLUSTERED ([id]),
        CONSTRAINT [UQ_tb_folder_id_workspace] UNIQUE ([id], [workspace_id]),
        CONSTRAINT [FK_tb_folder_workspace]
            FOREIGN KEY ([workspace_id]) REFERENCES dbo.tb_workspace ([id]),
        CONSTRAINT [FK_tb_folder_parent]
            FOREIGN KEY ([parent_folder_id], [workspace_id])
            REFERENCES dbo.tb_folder ([id], [workspace_id]),
        CONSTRAINT [CK_tb_folder_sort_order] CHECK ([sort_order] >= 0)
    );
END;

IF OBJECT_ID(N'dbo.tb_board', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.tb_board (
        [id] BIGINT IDENTITY(1,1) NOT NULL,
        [workspace_id] BIGINT NOT NULL,
        [folder_id] BIGINT NULL,
        [name] NVARCHAR(200) NOT NULL,
        [description] NVARCHAR(2000) NULL,
        [visibility] VARCHAR(16) NOT NULL
            CONSTRAINT [DF_tb_board_visibility] DEFAULT ('private'),
        [created_by] INT NULL,
        [created_at] DATETIME2(3) NOT NULL
            CONSTRAINT [DF_tb_board_created_at] DEFAULT (SYSUTCDATETIME()),
        [updated_at] DATETIME2(3) NOT NULL
            CONSTRAINT [DF_tb_board_updated_at] DEFAULT (SYSUTCDATETIME()),
        [version] ROWVERSION,
        [deleted_at] DATETIME2(3) NULL,
        CONSTRAINT [PK_tb_board] PRIMARY KEY CLUSTERED ([id]),
        CONSTRAINT [FK_tb_board_workspace]
            FOREIGN KEY ([workspace_id]) REFERENCES dbo.tb_workspace ([id]),
        CONSTRAINT [FK_tb_board_folder_workspace]
            FOREIGN KEY ([folder_id], [workspace_id])
            REFERENCES dbo.tb_folder ([id], [workspace_id]),
        CONSTRAINT [CK_tb_board_visibility]
            CHECK ([visibility] IN ('private', 'workspace', 'public'))
    );
END;

IF OBJECT_ID(N'dbo.tb_board_member', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.tb_board_member (
        [id] BIGINT IDENTITY(1,1) NOT NULL,
        [board_id] BIGINT NOT NULL,
        [user_id] INT NOT NULL,
        [role] VARCHAR(16) NOT NULL,
        [invited_by] INT NULL,
        [created_at] DATETIME2(3) NOT NULL
            CONSTRAINT [DF_tb_board_member_created_at] DEFAULT (SYSUTCDATETIME()),
        [updated_at] DATETIME2(3) NOT NULL
            CONSTRAINT [DF_tb_board_member_updated_at] DEFAULT (SYSUTCDATETIME()),
        [deleted_at] DATETIME2(3) NULL,
        [version] ROWVERSION,
        CONSTRAINT [PK_tb_board_member] PRIMARY KEY CLUSTERED ([id]),
        CONSTRAINT [FK_tb_board_member_board]
            FOREIGN KEY ([board_id]) REFERENCES dbo.tb_board ([id]),
        CONSTRAINT [CK_tb_board_member_role]
            CHECK ([role] IN ('viewer', 'editor', 'designer'))
    );
END;

IF OBJECT_ID(N'dbo.tb_group', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.tb_group (
        [id] BIGINT IDENTITY(1,1) NOT NULL,
        [board_id] BIGINT NOT NULL,
        [name] NVARCHAR(200) NOT NULL,
        [color] VARCHAR(32) NULL,
        [sort_order] INT NOT NULL
            CONSTRAINT [DF_tb_group_sort_order] DEFAULT (0),
        [created_by] INT NULL,
        [created_at] DATETIME2(3) NOT NULL
            CONSTRAINT [DF_tb_group_created_at] DEFAULT (SYSUTCDATETIME()),
        [updated_at] DATETIME2(3) NOT NULL
            CONSTRAINT [DF_tb_group_updated_at] DEFAULT (SYSUTCDATETIME()),
        [deleted_at] DATETIME2(3) NULL,
        [version] ROWVERSION,
        CONSTRAINT [PK_tb_group] PRIMARY KEY CLUSTERED ([id]),
        CONSTRAINT [UQ_tb_group_id_board] UNIQUE ([id], [board_id]),
        CONSTRAINT [FK_tb_group_board]
            FOREIGN KEY ([board_id]) REFERENCES dbo.tb_board ([id]),
        CONSTRAINT [CK_tb_group_sort_order] CHECK ([sort_order] >= 0)
    );
END;

IF OBJECT_ID(N'dbo.tb_column', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.tb_column (
        [id] BIGINT IDENTITY(1,1) NOT NULL,
        [board_id] BIGINT NOT NULL,
        [name] NVARCHAR(200) NOT NULL,
        [type] VARCHAR(32) NOT NULL,
        [options_json] NVARCHAR(MAX) NULL,
        [required] BIT NOT NULL
            CONSTRAINT [DF_tb_column_required] DEFAULT (0),
        [sort_order] INT NOT NULL
            CONSTRAINT [DF_tb_column_sort_order] DEFAULT (0),
        [enforce_unique] BIT NOT NULL
            CONSTRAINT [DF_tb_column_enforce_unique] DEFAULT (0),
        [created_by] INT NULL,
        [created_at] DATETIME2(3) NOT NULL
            CONSTRAINT [DF_tb_column_created_at] DEFAULT (SYSUTCDATETIME()),
        [updated_at] DATETIME2(3) NOT NULL
            CONSTRAINT [DF_tb_column_updated_at] DEFAULT (SYSUTCDATETIME()),
        [deleted_at] DATETIME2(3) NULL,
        [version] ROWVERSION,
        CONSTRAINT [PK_tb_column] PRIMARY KEY CLUSTERED ([id]),
        CONSTRAINT [UQ_tb_column_id_board] UNIQUE ([id], [board_id]),
        CONSTRAINT [FK_tb_column_board]
            FOREIGN KEY ([board_id]) REFERENCES dbo.tb_board ([id]),
        CONSTRAINT [CK_tb_column_type] CHECK ([type] IN (
            'name', 'text', 'long_text', 'numbers', 'formula', 'progress',
            'rating', 'people', 'person', 'team', 'status', 'dropdown', 'tags',
            'date', 'timeline', 'hour', 'file', 'email', 'phone', 'country',
            'link', 'location', 'board_relation', 'subtasks', 'dependency',
            'item_id'
        )),
        CONSTRAINT [CK_tb_column_options_json]
            CHECK ([options_json] IS NULL OR ISJSON([options_json]) = 1),
        CONSTRAINT [CK_tb_column_sort_order] CHECK ([sort_order] >= 0)
    );
END;

IF OBJECT_ID(N'dbo.tb_item', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.tb_item (
        [id] BIGINT IDENTITY(1,1) NOT NULL,
        [board_id] BIGINT NOT NULL,
        [group_id] BIGINT NOT NULL,
        [parent_item_id] BIGINT NULL,
        [name] NVARCHAR(500) NOT NULL,
        [sort_order] INT NOT NULL
            CONSTRAINT [DF_tb_item_sort_order] DEFAULT (0),
        [version] ROWVERSION,
        [created_by] INT NULL,
        [updated_by] INT NULL,
        [created_at] DATETIME2(3) NOT NULL
            CONSTRAINT [DF_tb_item_created_at] DEFAULT (SYSUTCDATETIME()),
        [updated_at] DATETIME2(3) NOT NULL
            CONSTRAINT [DF_tb_item_updated_at] DEFAULT (SYSUTCDATETIME()),
        [deleted_at] DATETIME2(3) NULL,
        CONSTRAINT [PK_tb_item] PRIMARY KEY CLUSTERED ([id]),
        CONSTRAINT [UQ_tb_item_id_board] UNIQUE ([id], [board_id]),
        CONSTRAINT [FK_tb_item_group_board]
            FOREIGN KEY ([group_id], [board_id])
            REFERENCES dbo.tb_group ([id], [board_id]),
        CONSTRAINT [FK_tb_item_parent_board]
            FOREIGN KEY ([parent_item_id], [board_id])
            REFERENCES dbo.tb_item ([id], [board_id]),
        CONSTRAINT [CK_tb_item_not_own_parent]
            CHECK ([parent_item_id] IS NULL OR [parent_item_id] <> [id]),
        CONSTRAINT [CK_tb_item_sort_order] CHECK ([sort_order] >= 0)
    );
END;

IF OBJECT_ID(N'dbo.tb_cell', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.tb_cell (
        [id] BIGINT IDENTITY(1,1) NOT NULL,
        [item_id] BIGINT NOT NULL,
        [column_id] BIGINT NOT NULL,
        [board_id] BIGINT NOT NULL,
        [value_text] NVARCHAR(MAX) NULL,
        [value_number] DECIMAL(38,10) NULL,
        [value_date] DATE NULL,
        [value_datetime] DATETIME2(3) NULL,
        [value_boolean] BIT NULL,
        [value_json] NVARCHAR(MAX) NULL,
        [unique_value_hash] VARBINARY(32) NULL,
        [version] ROWVERSION,
        [created_by] INT NULL,
        [updated_by] INT NULL,
        [updated_at] DATETIME2(3) NOT NULL
            CONSTRAINT [DF_tb_cell_updated_at] DEFAULT (SYSUTCDATETIME()),
        [deleted_at] DATETIME2(3) NULL,
        CONSTRAINT [PK_tb_cell] PRIMARY KEY CLUSTERED ([id]),
        CONSTRAINT [UQ_tb_cell_item_column] UNIQUE ([item_id], [column_id]),
        CONSTRAINT [FK_tb_cell_item_board]
            FOREIGN KEY ([item_id], [board_id])
            REFERENCES dbo.tb_item ([id], [board_id]),
        CONSTRAINT [FK_tb_cell_column_board]
            FOREIGN KEY ([column_id], [board_id])
            REFERENCES dbo.tb_column ([id], [board_id]),
        CONSTRAINT [CK_tb_cell_one_typed_value]
            CHECK (
                (CASE WHEN [value_text] IS NULL THEN 0 ELSE 1 END)
              + (CASE WHEN [value_number] IS NULL THEN 0 ELSE 1 END)
              + (CASE WHEN [value_date] IS NULL THEN 0 ELSE 1 END)
              + (CASE WHEN [value_datetime] IS NULL THEN 0 ELSE 1 END)
              + (CASE WHEN [value_boolean] IS NULL THEN 0 ELSE 1 END)
              + (CASE WHEN [value_json] IS NULL THEN 0 ELSE 1 END) <= 1
            ),
        CONSTRAINT [CK_tb_cell_value_json]
            CHECK ([value_json] IS NULL OR ISJSON([value_json]) = 1),
        CONSTRAINT [CK_tb_cell_unique_hash_has_value]
            CHECK (
                [unique_value_hash] IS NULL
                OR [value_text] IS NOT NULL OR [value_number] IS NOT NULL
                OR [value_date] IS NOT NULL OR [value_datetime] IS NOT NULL
                OR [value_boolean] IS NOT NULL OR [value_json] IS NOT NULL
            )
    );
END;

IF OBJECT_ID(N'dbo.tb_item_person', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.tb_item_person (
        [id] BIGINT IDENTITY(1,1) NOT NULL,
        [item_id] BIGINT NOT NULL,
        [board_id] BIGINT NOT NULL,
        [column_id] BIGINT NOT NULL,
        [user_id] INT NOT NULL,
        [position] INT NOT NULL
            CONSTRAINT [DF_tb_item_person_position] DEFAULT (0),
        [created_by] INT NULL,
        [created_at] DATETIME2(3) NOT NULL
            CONSTRAINT [DF_tb_item_person_created_at] DEFAULT (SYSUTCDATETIME()),
        [updated_at] DATETIME2(3) NOT NULL
            CONSTRAINT [DF_tb_item_person_updated_at] DEFAULT (SYSUTCDATETIME()),
        [deleted_at] DATETIME2(3) NULL,
        [version] ROWVERSION,
        CONSTRAINT [PK_tb_item_person] PRIMARY KEY CLUSTERED ([id]),
        CONSTRAINT [FK_tb_item_person_item_board]
            FOREIGN KEY ([item_id], [board_id])
            REFERENCES dbo.tb_item ([id], [board_id]),
        CONSTRAINT [FK_tb_item_person_column_board]
            FOREIGN KEY ([column_id], [board_id])
            REFERENCES dbo.tb_column ([id], [board_id]),
        CONSTRAINT [CK_tb_item_person_position] CHECK ([position] >= 0)
    );
END;

/*
    Cell storage map: text/contact/name/id values use value_text; numeric,
    rating and progress values use value_number; date uses value_date; date/time
    values use value_datetime; complex and other multi-value fields use
    value_json. people/person assignments use tb_item_person; file,
    board_relation, subtasks and dependency fields use tb_file,
    tb_item_relation, tb_item.parent_item_id and tb_item_dependency.
    For a column with enforce_unique=1, the writer stores a stable canonical
    value hash in unique_value_hash; its filtered unique index rejects repeats.
    Value-to-column type/cardinality matching and required-field checks belong
    to the API.
*/

IF OBJECT_ID(N'dbo.tb_comment', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.tb_comment (
        [id] BIGINT IDENTITY(1,1) NOT NULL,
        [board_id] BIGINT NOT NULL,
        [item_id] BIGINT NOT NULL,
        [parent_comment_id] BIGINT NULL,
        [body] NVARCHAR(MAX) NOT NULL,
        [created_by] INT NOT NULL,
        [updated_by] INT NULL,
        [created_at] DATETIME2(3) NOT NULL
            CONSTRAINT [DF_tb_comment_created_at] DEFAULT (SYSUTCDATETIME()),
        [updated_at] DATETIME2(3) NOT NULL
            CONSTRAINT [DF_tb_comment_updated_at] DEFAULT (SYSUTCDATETIME()),
        [deleted_at] DATETIME2(3) NULL,
        [version] ROWVERSION,
        CONSTRAINT [PK_tb_comment] PRIMARY KEY CLUSTERED ([id]),
        CONSTRAINT [UQ_tb_comment_id_item] UNIQUE ([id], [item_id]),
        CONSTRAINT [FK_tb_comment_item_board]
            FOREIGN KEY ([item_id], [board_id])
            REFERENCES dbo.tb_item ([id], [board_id]),
        CONSTRAINT [FK_tb_comment_parent_item]
            FOREIGN KEY ([parent_comment_id], [item_id])
            REFERENCES dbo.tb_comment ([id], [item_id]),
        CONSTRAINT [CK_tb_comment_not_empty]
            CHECK (DATALENGTH([body]) > 0)
    );
END;

IF OBJECT_ID(N'dbo.tb_file', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.tb_file (
        [id] BIGINT IDENTITY(1,1) NOT NULL,
        [board_id] BIGINT NOT NULL,
        [item_id] BIGINT NOT NULL,
        [column_id] BIGINT NULL,
        [current_version_id] BIGINT NULL,
        [name] NVARCHAR(260) NOT NULL,
        [created_by] INT NULL,
        [created_at] DATETIME2(3) NOT NULL
            CONSTRAINT [DF_tb_file_created_at] DEFAULT (SYSUTCDATETIME()),
        [updated_at] DATETIME2(3) NOT NULL
            CONSTRAINT [DF_tb_file_updated_at] DEFAULT (SYSUTCDATETIME()),
        [deleted_at] DATETIME2(3) NULL,
        [version] ROWVERSION,
        CONSTRAINT [PK_tb_file] PRIMARY KEY CLUSTERED ([id]),
        CONSTRAINT [UQ_tb_file_id_board] UNIQUE ([id], [board_id]),
        CONSTRAINT [FK_tb_file_item_board]
            FOREIGN KEY ([item_id], [board_id])
            REFERENCES dbo.tb_item ([id], [board_id]),
        CONSTRAINT [FK_tb_file_column_board]
            FOREIGN KEY ([column_id], [board_id])
            REFERENCES dbo.tb_column ([id], [board_id])
    );
END;

IF OBJECT_ID(N'dbo.tb_file_version', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.tb_file_version (
        [id] BIGINT IDENTITY(1,1) NOT NULL,
        [file_id] BIGINT NOT NULL,
        [version_number] INT NOT NULL,
        [original_name] NVARCHAR(260) NOT NULL,
        [content_type] NVARCHAR(255) NOT NULL,
        [byte_size] BIGINT NOT NULL,
        [storage_provider] VARCHAR(32) NOT NULL,
        [storage_container] NVARCHAR(128) NOT NULL,
        [storage_object_key] NVARCHAR(512) NOT NULL,
        [sha256_hash] VARBINARY(32) NULL,
        [scan_status] VARCHAR(16) NOT NULL
            CONSTRAINT [DF_tb_file_version_scan_status] DEFAULT ('unscanned'),
        [scanned_at] DATETIME2(3) NULL,
        [created_by] INT NULL,
        [created_at] DATETIME2(3) NOT NULL
            CONSTRAINT [DF_tb_file_version_created_at] DEFAULT (SYSUTCDATETIME()),
        [deleted_at] DATETIME2(3) NULL,
        [version] ROWVERSION,
        CONSTRAINT [PK_tb_file_version] PRIMARY KEY CLUSTERED ([id]),
        CONSTRAINT [UQ_tb_file_version_file_number]
            UNIQUE ([file_id], [version_number]),
        CONSTRAINT [UQ_tb_file_version_file_id] UNIQUE ([file_id], [id]),
        CONSTRAINT [FK_tb_file_version_file]
            FOREIGN KEY ([file_id]) REFERENCES dbo.tb_file ([id]),
        CONSTRAINT [CK_tb_file_version_number] CHECK ([version_number] > 0),
        CONSTRAINT [CK_tb_file_version_size] CHECK ([byte_size] >= 0),
        CONSTRAINT [CK_tb_file_version_scan_status]
            CHECK ([scan_status] IN ('pending', 'clean', 'unscanned', 'quarantined', 'failed'))
    );
END;

IF OBJECT_ID(N'dbo.tb_activity', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.tb_activity (
        [id] BIGINT IDENTITY(1,1) NOT NULL,
        [workspace_id] BIGINT NULL,
        [board_id] BIGINT NULL,
        [item_id] BIGINT NULL,
        [actor_user_id] INT NULL,
        [event_type] VARCHAR(64) NOT NULL,
        [payload_json] NVARCHAR(MAX) NULL,
        [created_at] DATETIME2(3) NOT NULL
            CONSTRAINT [DF_tb_activity_created_at] DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT [PK_tb_activity] PRIMARY KEY CLUSTERED ([id]),
        CONSTRAINT [FK_tb_activity_workspace]
            FOREIGN KEY ([workspace_id]) REFERENCES dbo.tb_workspace ([id]),
        CONSTRAINT [FK_tb_activity_board]
            FOREIGN KEY ([board_id]) REFERENCES dbo.tb_board ([id]),
        CONSTRAINT [FK_tb_activity_item_board]
            FOREIGN KEY ([item_id], [board_id])
            REFERENCES dbo.tb_item ([id], [board_id]),
        CONSTRAINT [CK_tb_activity_scope]
            CHECK ([workspace_id] IS NOT NULL OR [board_id] IS NOT NULL),
        CONSTRAINT [CK_tb_activity_item_scope]
            CHECK ([item_id] IS NULL OR [board_id] IS NOT NULL),
        CONSTRAINT [CK_tb_activity_payload_json]
            CHECK ([payload_json] IS NULL OR ISJSON([payload_json]) = 1)
    );
END;

IF OBJECT_ID(N'dbo.tb_item_relation', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.tb_item_relation (
        [id] BIGINT IDENTITY(1,1) NOT NULL,
        [source_board_id] BIGINT NOT NULL,
        [source_item_id] BIGINT NOT NULL,
        [target_board_id] BIGINT NOT NULL,
        [target_item_id] BIGINT NOT NULL,
        [column_id] BIGINT NULL,
        [relation_type] VARCHAR(32) NOT NULL
            CONSTRAINT [DF_tb_item_relation_type] DEFAULT ('related'),
        [created_by] INT NULL,
        [created_at] DATETIME2(3) NOT NULL
            CONSTRAINT [DF_tb_item_relation_created_at] DEFAULT (SYSUTCDATETIME()),
        [deleted_at] DATETIME2(3) NULL,
        [version] ROWVERSION,
        CONSTRAINT [PK_tb_item_relation] PRIMARY KEY CLUSTERED ([id]),
        CONSTRAINT [FK_tb_item_relation_source]
            FOREIGN KEY ([source_item_id], [source_board_id])
            REFERENCES dbo.tb_item ([id], [board_id]),
        CONSTRAINT [FK_tb_item_relation_target]
            FOREIGN KEY ([target_item_id], [target_board_id])
            REFERENCES dbo.tb_item ([id], [board_id]),
        CONSTRAINT [FK_tb_item_relation_column]
            FOREIGN KEY ([column_id], [source_board_id])
            REFERENCES dbo.tb_column ([id], [board_id]),
        CONSTRAINT [CK_tb_item_relation_not_self]
            CHECK ([source_item_id] <> [target_item_id])
    );
END;

IF OBJECT_ID(N'dbo.tb_item_dependency', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.tb_item_dependency (
        [id] BIGINT IDENTITY(1,1) NOT NULL,
        [board_id] BIGINT NOT NULL,
        [predecessor_item_id] BIGINT NOT NULL,
        [successor_item_id] BIGINT NOT NULL,
        [column_id] BIGINT NULL,
        [dependency_type] VARCHAR(24) NOT NULL
            CONSTRAINT [DF_tb_item_dependency_type] DEFAULT ('finish_to_start'),
        [lag_minutes] INT NOT NULL
            CONSTRAINT [DF_tb_item_dependency_lag] DEFAULT (0),
        [created_by] INT NULL,
        [created_at] DATETIME2(3) NOT NULL
            CONSTRAINT [DF_tb_item_dependency_created_at] DEFAULT (SYSUTCDATETIME()),
        [deleted_at] DATETIME2(3) NULL,
        [version] ROWVERSION,
        CONSTRAINT [PK_tb_item_dependency] PRIMARY KEY CLUSTERED ([id]),
        CONSTRAINT [FK_tb_item_dependency_predecessor]
            FOREIGN KEY ([predecessor_item_id], [board_id])
            REFERENCES dbo.tb_item ([id], [board_id]),
        CONSTRAINT [FK_tb_item_dependency_successor]
            FOREIGN KEY ([successor_item_id], [board_id])
            REFERENCES dbo.tb_item ([id], [board_id]),
        CONSTRAINT [FK_tb_item_dependency_column]
            FOREIGN KEY ([column_id], [board_id])
            REFERENCES dbo.tb_column ([id], [board_id]),
        CONSTRAINT [CK_tb_item_dependency_not_self]
            CHECK ([predecessor_item_id] <> [successor_item_id]),
        CONSTRAINT [CK_tb_item_dependency_type]
            CHECK ([dependency_type] IN (
                'finish_to_start', 'start_to_start', 'finish_to_finish', 'start_to_finish'
            ))
    );
END;

/*
    Dependencies are directed edges. The database rejects self-edges and
    duplicate active edges; the application must also reject graph cycles in
    the same transaction that creates or changes an edge.
*/

IF OBJECT_ID(N'dbo.tb_view', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.tb_view (
        [id] BIGINT IDENTITY(1,1) NOT NULL,
        [board_id] BIGINT NOT NULL,
        [name] NVARCHAR(150) NOT NULL,
        [view_type] VARCHAR(24) NOT NULL,
        [config_json] NVARCHAR(MAX) NOT NULL,
        [owner_user_id] INT NULL,
        [is_shared] BIT NOT NULL
            CONSTRAINT [DF_tb_view_is_shared] DEFAULT (0),
        [created_at] DATETIME2(3) NOT NULL
            CONSTRAINT [DF_tb_view_created_at] DEFAULT (SYSUTCDATETIME()),
        [updated_at] DATETIME2(3) NOT NULL
            CONSTRAINT [DF_tb_view_updated_at] DEFAULT (SYSUTCDATETIME()),
        [deleted_at] DATETIME2(3) NULL,
        [version] ROWVERSION,
        CONSTRAINT [PK_tb_view] PRIMARY KEY CLUSTERED ([id]),
        CONSTRAINT [FK_tb_view_board]
            FOREIGN KEY ([board_id]) REFERENCES dbo.tb_board ([id]),
        CONSTRAINT [CK_tb_view_type]
            CHECK ([view_type] IN ('table', 'kanban', 'calendar', 'timeline', 'dashboard')),
        CONSTRAINT [CK_tb_view_config_json]
            CHECK (ISJSON([config_json]) = 1)
    );
END;

IF OBJECT_ID(N'dbo.tb_automation', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.tb_automation (
        [id] BIGINT IDENTITY(1,1) NOT NULL,
        [board_id] BIGINT NOT NULL,
        [name] NVARCHAR(200) NOT NULL,
        [status] VARCHAR(16) NOT NULL
            CONSTRAINT [DF_tb_automation_status] DEFAULT ('draft'),
        [definition_json] NVARCHAR(MAX) NOT NULL,
        [created_by] INT NULL,
        [updated_by] INT NULL,
        [created_at] DATETIME2(3) NOT NULL
            CONSTRAINT [DF_tb_automation_created_at] DEFAULT (SYSUTCDATETIME()),
        [updated_at] DATETIME2(3) NOT NULL
            CONSTRAINT [DF_tb_automation_updated_at] DEFAULT (SYSUTCDATETIME()),
        [deleted_at] DATETIME2(3) NULL,
        [version] ROWVERSION,
        CONSTRAINT [PK_tb_automation] PRIMARY KEY CLUSTERED ([id]),
        CONSTRAINT [UQ_tb_automation_id_board] UNIQUE ([id], [board_id]),
        CONSTRAINT [FK_tb_automation_board]
            FOREIGN KEY ([board_id]) REFERENCES dbo.tb_board ([id]),
        CONSTRAINT [CK_tb_automation_status]
            CHECK ([status] IN ('draft', 'active', 'paused', 'error', 'disabled')),
        CONSTRAINT [CK_tb_automation_definition_json]
            CHECK (ISJSON([definition_json]) = 1)
    );
END;

IF OBJECT_ID(N'dbo.tb_automation_run', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.tb_automation_run (
        [id] BIGINT IDENTITY(1,1) NOT NULL,
        [automation_id] BIGINT NOT NULL,
        [board_id] BIGINT NOT NULL,
        [status] VARCHAR(16) NOT NULL
            CONSTRAINT [DF_tb_automation_run_status] DEFAULT ('pending'),
        [idempotency_key] NVARCHAR(200) NULL,
        [trigger_event_id] UNIQUEIDENTIFIER NULL,
        [initiated_by] INT NULL,
        [started_at] DATETIME2(3) NULL,
        [finished_at] DATETIME2(3) NULL,
        [result_json] NVARCHAR(MAX) NULL,
        [error_message] NVARCHAR(2000) NULL,
        [created_at] DATETIME2(3) NOT NULL
            CONSTRAINT [DF_tb_automation_run_created_at] DEFAULT (SYSUTCDATETIME()),
        [version] ROWVERSION,
        CONSTRAINT [PK_tb_automation_run] PRIMARY KEY CLUSTERED ([id]),
        CONSTRAINT [FK_tb_automation_run_automation_board]
            FOREIGN KEY ([automation_id], [board_id])
            REFERENCES dbo.tb_automation ([id], [board_id]),
        CONSTRAINT [CK_tb_automation_run_status]
            CHECK ([status] IN ('pending', 'running', 'succeeded', 'failed', 'cancelled')),
        CONSTRAINT [CK_tb_automation_run_result_json]
            CHECK ([result_json] IS NULL OR ISJSON([result_json]) = 1)
    );
END;

/* The current-version pointer is added after both file tables exist. */
IF NOT EXISTS (
    SELECT 1 FROM sys.foreign_keys
    WHERE [name] = N'FK_tb_file_current_version'
      AND [parent_object_id] = OBJECT_ID(N'dbo.tb_file')
)
BEGIN
    ALTER TABLE dbo.tb_file WITH CHECK
        ADD CONSTRAINT [FK_tb_file_current_version]
        FOREIGN KEY ([id], [current_version_id])
        REFERENCES dbo.tb_file_version ([file_id], [id]);
END;

/* Unique names and stable keys; filtered indexes allow reuse after soft delete. */
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE [object_id] = OBJECT_ID(N'dbo.tb_workspace') AND [name] = N'UX_tb_workspace_key')
    CREATE UNIQUE INDEX [UX_tb_workspace_key] ON dbo.tb_workspace ([workspace_key]);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE [object_id] = OBJECT_ID(N'dbo.tb_workspace') AND [name] = N'UX_tb_workspace_parent_name_active')
    CREATE UNIQUE INDEX [UX_tb_workspace_parent_name_active] ON dbo.tb_workspace ([parent_workspace_id], [name]) WHERE [deleted_at] IS NULL;
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE [object_id] = OBJECT_ID(N'dbo.tb_folder') AND [name] = N'UX_tb_folder_workspace_key')
    CREATE UNIQUE INDEX [UX_tb_folder_workspace_key] ON dbo.tb_folder ([workspace_id], [folder_key]);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE [object_id] = OBJECT_ID(N'dbo.tb_folder') AND [name] = N'UX_tb_folder_workspace_parent_name_active')
    CREATE UNIQUE INDEX [UX_tb_folder_workspace_parent_name_active] ON dbo.tb_folder ([workspace_id], [parent_folder_id], [name]) WHERE [deleted_at] IS NULL;
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE [object_id] = OBJECT_ID(N'dbo.tb_board') AND [name] = N'UX_tb_board_workspace_folder_name_active')
    CREATE UNIQUE INDEX [UX_tb_board_workspace_folder_name_active] ON dbo.tb_board ([workspace_id], [folder_id], [name]) WHERE [deleted_at] IS NULL;
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE [object_id] = OBJECT_ID(N'dbo.tb_board_member') AND [name] = N'UX_tb_board_member_board_user_active')
    CREATE UNIQUE INDEX [UX_tb_board_member_board_user_active] ON dbo.tb_board_member ([board_id], [user_id]) WHERE [deleted_at] IS NULL;
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE [object_id] = OBJECT_ID(N'dbo.tb_workspace_member') AND [name] = N'UX_tb_workspace_member_workspace_user_active')
    CREATE UNIQUE INDEX [UX_tb_workspace_member_workspace_user_active] ON dbo.tb_workspace_member ([workspace_id], [user_id]) WHERE [deleted_at] IS NULL;
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE [object_id] = OBJECT_ID(N'dbo.tb_group') AND [name] = N'UX_tb_group_board_name_active')
    CREATE UNIQUE INDEX [UX_tb_group_board_name_active] ON dbo.tb_group ([board_id], [name]) WHERE [deleted_at] IS NULL;
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE [object_id] = OBJECT_ID(N'dbo.tb_column') AND [name] = N'UX_tb_column_board_name_active')
    CREATE UNIQUE INDEX [UX_tb_column_board_name_active] ON dbo.tb_column ([board_id], [name]) WHERE [deleted_at] IS NULL;
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE [object_id] = OBJECT_ID(N'dbo.tb_view') AND [name] = N'UX_tb_view_board_owner_name_active')
    CREATE UNIQUE INDEX [UX_tb_view_board_owner_name_active] ON dbo.tb_view ([board_id], [owner_user_id], [name]) WHERE [deleted_at] IS NULL;
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE [object_id] = OBJECT_ID(N'dbo.tb_automation') AND [name] = N'UX_tb_automation_board_name_active')
    CREATE UNIQUE INDEX [UX_tb_automation_board_name_active] ON dbo.tb_automation ([board_id], [name]) WHERE [deleted_at] IS NULL;

/* Board, member and item lookups used by privacy and board access checks. */
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE [object_id] = OBJECT_ID(N'dbo.tb_board') AND [name] = N'IX_tb_board_workspace_visibility')
    CREATE INDEX [IX_tb_board_workspace_visibility] ON dbo.tb_board ([workspace_id], [visibility], [id]) INCLUDE ([name], [folder_id]) WHERE [deleted_at] IS NULL;
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE [object_id] = OBJECT_ID(N'dbo.tb_board') AND [name] = N'IX_tb_board_created_by')
    CREATE INDEX [IX_tb_board_created_by] ON dbo.tb_board ([created_by], [id]) INCLUDE ([workspace_id], [folder_id], [visibility]) WHERE [deleted_at] IS NULL;
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE [object_id] = OBJECT_ID(N'dbo.tb_board_member') AND [name] = N'IX_tb_board_member_user_board')
    CREATE INDEX [IX_tb_board_member_user_board] ON dbo.tb_board_member ([user_id], [board_id]) INCLUDE ([role]) WHERE [deleted_at] IS NULL;
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE [object_id] = OBJECT_ID(N'dbo.tb_workspace_member') AND [name] = N'IX_tb_workspace_member_user_workspace')
    CREATE INDEX [IX_tb_workspace_member_user_workspace] ON dbo.tb_workspace_member ([user_id], [workspace_id]) INCLUDE ([role]) WHERE [deleted_at] IS NULL;
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE [object_id] = OBJECT_ID(N'dbo.tb_item') AND [name] = N'IX_tb_item_board_group_sort')
    CREATE INDEX [IX_tb_item_board_group_sort] ON dbo.tb_item ([board_id], [group_id], [sort_order], [id]) INCLUDE ([name], [parent_item_id]) WHERE [deleted_at] IS NULL;
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE [object_id] = OBJECT_ID(N'dbo.tb_item') AND [name] = N'IX_tb_item_parent')
    CREATE INDEX [IX_tb_item_parent] ON dbo.tb_item ([parent_item_id], [sort_order], [id]) WHERE [parent_item_id] IS NOT NULL AND [deleted_at] IS NULL;
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE [object_id] = OBJECT_ID(N'dbo.tb_cell') AND [name] = N'IX_tb_cell_column_item')
    CREATE INDEX [IX_tb_cell_column_item] ON dbo.tb_cell ([column_id], [item_id]) INCLUDE ([board_id], [value_number], [value_date], [value_datetime]) WHERE [deleted_at] IS NULL;
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE [object_id] = OBJECT_ID(N'dbo.tb_cell') AND [name] = N'UX_tb_cell_unique_value_hash')
    CREATE UNIQUE INDEX [UX_tb_cell_unique_value_hash] ON dbo.tb_cell ([column_id], [unique_value_hash]) WHERE [unique_value_hash] IS NOT NULL AND [deleted_at] IS NULL;
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE [object_id] = OBJECT_ID(N'dbo.tb_cell') AND [name] = N'IX_tb_cell_column_number')
    CREATE INDEX [IX_tb_cell_column_number] ON dbo.tb_cell ([column_id], [value_number], [item_id]) WHERE [value_number] IS NOT NULL AND [deleted_at] IS NULL;
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE [object_id] = OBJECT_ID(N'dbo.tb_cell') AND [name] = N'IX_tb_cell_column_date')
    CREATE INDEX [IX_tb_cell_column_date] ON dbo.tb_cell ([column_id], [value_date], [item_id]) WHERE [value_date] IS NOT NULL AND [deleted_at] IS NULL;
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE [object_id] = OBJECT_ID(N'dbo.tb_item_person') AND [name] = N'UX_tb_item_person_active')
    CREATE UNIQUE INDEX [UX_tb_item_person_active] ON dbo.tb_item_person ([item_id], [column_id], [user_id]) WHERE [deleted_at] IS NULL;
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE [object_id] = OBJECT_ID(N'dbo.tb_item_person') AND [name] = N'IX_tb_item_person_item_position')
    CREATE INDEX [IX_tb_item_person_item_position] ON dbo.tb_item_person ([board_id], [item_id], [column_id], [position]) INCLUDE ([user_id]) WHERE [deleted_at] IS NULL;

/* Collaboration, files, history, relations, views and automation lookups. */
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE [object_id] = OBJECT_ID(N'dbo.tb_group') AND [name] = N'IX_tb_group_board_order')
    CREATE INDEX [IX_tb_group_board_order] ON dbo.tb_group ([board_id], [sort_order], [id]) WHERE [deleted_at] IS NULL;
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE [object_id] = OBJECT_ID(N'dbo.tb_column') AND [name] = N'IX_tb_column_board_order')
    CREATE INDEX [IX_tb_column_board_order] ON dbo.tb_column ([board_id], [sort_order], [id]) WHERE [deleted_at] IS NULL;
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE [object_id] = OBJECT_ID(N'dbo.tb_comment') AND [name] = N'IX_tb_comment_item_created')
    CREATE INDEX [IX_tb_comment_item_created] ON dbo.tb_comment ([board_id], [item_id], [created_at] DESC, [id] DESC) WHERE [deleted_at] IS NULL;
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE [object_id] = OBJECT_ID(N'dbo.tb_file') AND [name] = N'IX_tb_file_item_created')
    CREATE INDEX [IX_tb_file_item_created] ON dbo.tb_file ([board_id], [item_id], [created_at] DESC) WHERE [deleted_at] IS NULL;
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE [object_id] = OBJECT_ID(N'dbo.tb_file_version') AND [name] = N'UX_tb_file_version_storage_key')
    CREATE UNIQUE INDEX [UX_tb_file_version_storage_key] ON dbo.tb_file_version ([storage_provider], [storage_container], [storage_object_key]);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE [object_id] = OBJECT_ID(N'dbo.tb_file_version') AND [name] = N'IX_tb_file_version_file_number')
    CREATE INDEX [IX_tb_file_version_file_number] ON dbo.tb_file_version ([file_id], [version_number] DESC) INCLUDE ([byte_size], [content_type], [scan_status]);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE [object_id] = OBJECT_ID(N'dbo.tb_activity') AND [name] = N'IX_tb_activity_board_created')
    CREATE INDEX [IX_tb_activity_board_created] ON dbo.tb_activity ([board_id], [created_at] DESC, [id] DESC) INCLUDE ([item_id], [actor_user_id], [event_type]) WHERE [board_id] IS NOT NULL;
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE [object_id] = OBJECT_ID(N'dbo.tb_activity') AND [name] = N'IX_tb_activity_item_created')
    CREATE INDEX [IX_tb_activity_item_created] ON dbo.tb_activity ([item_id], [created_at] DESC, [id] DESC) WHERE [item_id] IS NOT NULL;
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE [object_id] = OBJECT_ID(N'dbo.tb_item_relation') AND [name] = N'UX_tb_item_relation_active')
    CREATE UNIQUE INDEX [UX_tb_item_relation_active] ON dbo.tb_item_relation ([source_item_id], [target_board_id], [target_item_id], [relation_type]) WHERE [deleted_at] IS NULL;
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE [object_id] = OBJECT_ID(N'dbo.tb_item_relation') AND [name] = N'IX_tb_item_relation_target')
    CREATE INDEX [IX_tb_item_relation_target] ON dbo.tb_item_relation ([target_board_id], [target_item_id], [source_item_id]) WHERE [deleted_at] IS NULL;
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE [object_id] = OBJECT_ID(N'dbo.tb_item_dependency') AND [name] = N'UX_tb_item_dependency_active')
    CREATE UNIQUE INDEX [UX_tb_item_dependency_active] ON dbo.tb_item_dependency ([predecessor_item_id], [successor_item_id], [dependency_type]) WHERE [deleted_at] IS NULL;
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE [object_id] = OBJECT_ID(N'dbo.tb_item_dependency') AND [name] = N'IX_tb_item_dependency_successor')
    CREATE INDEX [IX_tb_item_dependency_successor] ON dbo.tb_item_dependency ([successor_item_id], [predecessor_item_id]) WHERE [deleted_at] IS NULL;
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE [object_id] = OBJECT_ID(N'dbo.tb_view') AND [name] = N'IX_tb_view_board_shared')
    CREATE INDEX [IX_tb_view_board_shared] ON dbo.tb_view ([board_id], [is_shared], [owner_user_id], [id]) WHERE [deleted_at] IS NULL;
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE [object_id] = OBJECT_ID(N'dbo.tb_automation') AND [name] = N'IX_tb_automation_board_status')
    CREATE INDEX [IX_tb_automation_board_status] ON dbo.tb_automation ([board_id], [status], [id]) WHERE [deleted_at] IS NULL;
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE [object_id] = OBJECT_ID(N'dbo.tb_automation_run') AND [name] = N'UX_tb_automation_run_idempotency')
    CREATE UNIQUE INDEX [UX_tb_automation_run_idempotency] ON dbo.tb_automation_run ([automation_id], [idempotency_key]) WHERE [idempotency_key] IS NOT NULL;
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE [object_id] = OBJECT_ID(N'dbo.tb_automation_run') AND [name] = N'IX_tb_automation_run_board_created')
    CREATE INDEX [IX_tb_automation_run_board_created] ON dbo.tb_automation_run ([board_id], [created_at] DESC, [id] DESC) INCLUDE ([automation_id], [status]);

/*
    Stable, idempotent defaults. These rows contain no user assignments and do
    not grant TG permissions. Existing mismatched rows stop with an error so
    reruns never silently rename or revive user data.
*/
SET XACT_ABORT ON;
BEGIN TRY
    BEGIN TRANSACTION;

    DECLARE @default_workspace_id BIGINT;

    IF EXISTS (
        SELECT 1 FROM dbo.tb_workspace WITH (UPDLOCK, HOLDLOCK)
        WHERE [workspace_key] = N'general'
          AND ([workspace_type] <> 'workspace' OR [name] <> N'General'
               OR [parent_workspace_id] IS NOT NULL OR [deleted_at] IS NOT NULL)
    )
        THROW 50102, 'La clave de workspace general ya existe con otros datos.', 1;

    IF NOT EXISTS (
        SELECT 1 FROM dbo.tb_workspace WITH (UPDLOCK, HOLDLOCK)
        WHERE [workspace_key] = N'general'
    )
    BEGIN
        IF EXISTS (
            SELECT 1 FROM dbo.tb_workspace WITH (UPDLOCK, HOLDLOCK)
            WHERE [parent_workspace_id] IS NULL AND [name] = N'General' AND [deleted_at] IS NULL
        )
            THROW 50103, 'Ya existe un workspace General sin la clave estable general.', 1;

        INSERT INTO dbo.tb_workspace ([workspace_key], [workspace_type], [name], [visibility])
        VALUES (N'general', 'workspace', N'General', 'workspace');
    END;

    SELECT @default_workspace_id = [id]
    FROM dbo.tb_workspace
    WHERE [workspace_key] = N'general';

    IF EXISTS (
        SELECT 1 FROM dbo.tb_folder WITH (UPDLOCK, HOLDLOCK)
        WHERE [workspace_id] = @default_workspace_id
          AND [folder_key] = N'tableros'
          AND ([name] <> N'Tableros' OR [parent_folder_id] IS NOT NULL OR [deleted_at] IS NOT NULL)
    )
        THROW 50104, 'La clave de folder tableros ya existe con otros datos.', 1;

    IF NOT EXISTS (
        SELECT 1 FROM dbo.tb_folder WITH (UPDLOCK, HOLDLOCK)
        WHERE [workspace_id] = @default_workspace_id AND [folder_key] = N'tableros'
    )
    BEGIN
        IF EXISTS (
            SELECT 1 FROM dbo.tb_folder WITH (UPDLOCK, HOLDLOCK)
            WHERE [workspace_id] = @default_workspace_id
              AND [parent_folder_id] IS NULL AND [name] = N'Tableros' AND [deleted_at] IS NULL
        )
            THROW 50105, 'Ya existe la carpeta Tableros sin la clave estable tableros.', 1;

        INSERT INTO dbo.tb_folder ([workspace_id], [folder_key], [name])
        VALUES (@default_workspace_id, N'tableros', N'Tableros');
    END;

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;

SELECT w.[id] AS [default_workspace_id], w.[workspace_key], w.[name] AS [workspace_name],
       f.[id] AS [default_folder_id], f.[folder_key], f.[name] AS [folder_name]
FROM dbo.tb_workspace AS w
JOIN dbo.tb_folder AS f ON f.[workspace_id] = w.[id]
WHERE w.[workspace_key] = N'general' AND f.[folder_key] = N'tableros';
GO
