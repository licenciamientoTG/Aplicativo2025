-- docs/sql/rollback_banco_bankaool_schema.sql
-- Rollback de docs/sql/banco_bankaool_schema.sql.
-- ATENCION: DROP TABLE elimina permanentemente todas las transacciones
-- Bankaool almacenadas en dbo.banco_bankaool. Respaldar/exportar primero si
-- se requiere conservarlas. Solo afecta esta tabla y sus indices.

USE [TG];

IF OBJECT_ID(N'dbo.banco_bankaool', N'U') IS NOT NULL
    DROP TABLE dbo.banco_bankaool;
