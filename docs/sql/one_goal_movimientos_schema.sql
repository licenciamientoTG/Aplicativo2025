/* ============================================================================
   One Goal: estado de captura para movimientos bancarios en conciliación.
   Base afectada: TG (SQL Server).

   Desplegar después de docs/sql/tesoreria_schema.sql, que crea
   dbo.movimientos_bancarios.id como INT PRIMARY KEY. La FK valida la relación
   contra esa clave estable al crear la tabla.

   Compatibilidad: la ausencia de fila representa capturado = 0; los lectores
   deben usar LEFT JOIN. En INSERT/UPDATE de captura, el backend debe escribir
   capturado_por/actualizado_por junto con el estado correspondiente.

   Rollback (pérdida de datos): tras confirmar que se desea eliminar todas las
   capturas One Goal, ejecutar manualmente:
       USE [TG];
       DROP TABLE [dbo].[efc_conc_one_goal_movimientos];
   Esto elimina permanentemente el estado y la atribución almacenados.
   ============================================================================ */

USE [TG];
GO

IF OBJECT_ID(N'[dbo].[movimientos_bancarios]', N'U') IS NULL
    THROW 50001, 'Requisito faltante: TG.dbo.movimientos_bancarios debe existir antes de esta migración.', 1;
GO

IF COL_LENGTH(N'dbo.movimientos_bancarios', N'id') IS NULL
    THROW 50002, 'Requisito faltante: TG.dbo.movimientos_bancarios.id debe existir.', 1;
GO

IF OBJECT_ID(N'[dbo].[efc_conc_one_goal_movimientos]', N'U') IS NULL
BEGIN
    CREATE TABLE [dbo].[efc_conc_one_goal_movimientos] (
        [movimiento_bancario_id] INT      NOT NULL,
        [capturado]              BIT      NOT NULL,
        [capturado_por]          INT      NULL,
        [capturado_en]           DATETIME NOT NULL
            CONSTRAINT [DF_efc_conc_one_goal_mov_capturado_en] DEFAULT (GETDATE()),
        [actualizado_en]         DATETIME NULL,
        [actualizado_por]        INT      NULL,
        CONSTRAINT [PK_efc_conc_one_goal_movimientos]
            PRIMARY KEY CLUSTERED ([movimiento_bancario_id]),
        CONSTRAINT [FK_efc_conc_one_goal_mov_mov_bancarios]
            FOREIGN KEY ([movimiento_bancario_id])
            REFERENCES [dbo].[movimientos_bancarios] ([id])
    );
END;
GO
