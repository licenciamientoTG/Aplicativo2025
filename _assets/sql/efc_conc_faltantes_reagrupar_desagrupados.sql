/*
   Permite conservar membresías históricas de grupos desactivados y volver a
   agrupar la misma diferencia. No elimina ni modifica filas existentes.
   Base afectada: TG, esquema dbo.
*/
BEGIN TRY
    BEGIN TRANSACTION;

    IF OBJECT_ID('dbo.efc_conc_faltantes_grupos_miembros', 'U') IS NOT NULL
       AND NOT EXISTS (
            SELECT 1
            FROM sys.key_constraints K
            JOIN sys.index_columns IC
              ON IC.object_id = K.parent_object_id
             AND IC.index_id = K.unique_index_id
            JOIN sys.columns C
              ON C.object_id = IC.object_id
             AND C.column_id = IC.column_id
            WHERE K.parent_object_id = OBJECT_ID('dbo.efc_conc_faltantes_grupos_miembros')
              AND K.type = 'PK'
              AND C.name = 'grupo_id'
       )
    BEGIN
        DECLARE @faltantes_miembros_pk SYSNAME;
        DECLARE @faltantes_miembros_sql NVARCHAR(MAX);
        SELECT @faltantes_miembros_pk = name
        FROM sys.key_constraints
        WHERE parent_object_id = OBJECT_ID('dbo.efc_conc_faltantes_grupos_miembros')
          AND type = 'PK';

        IF @faltantes_miembros_pk IS NOT NULL
        BEGIN
            SET @faltantes_miembros_sql = N'ALTER TABLE dbo.efc_conc_faltantes_grupos_miembros DROP CONSTRAINT '
                + QUOTENAME(@faltantes_miembros_pk);
            EXEC sp_executesql @faltantes_miembros_sql;
        END;

        ALTER TABLE dbo.efc_conc_faltantes_grupos_miembros
            ADD CONSTRAINT PK_efc_conc_faltantes_grupos_miembros
            PRIMARY KEY (estacion_id, fecha_operativa, turno, concepto, grupo_id);
    END;

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;
