-- Petrotal / CNE: configuración de ApiReportes (ejecutar a mano en TG)
-- Agrega ApiKey cifrada (AES-256-CBC, igual que el password de la FIEL),
-- ID de obligación y ambiente (qa | pre | prod). Idempotente.
USE TG;

IF COL_LENGTH('dbo.PetrotalFielConfig', 'ApiKeyCifrada') IS NULL
    ALTER TABLE dbo.PetrotalFielConfig ADD ApiKeyCifrada VARCHAR(500) NULL;

IF COL_LENGTH('dbo.PetrotalFielConfig', 'ObligacionId') IS NULL
    ALTER TABLE dbo.PetrotalFielConfig ADD ObligacionId INT NULL;

IF COL_LENGTH('dbo.PetrotalFielConfig', 'Ambiente') IS NULL
    ALTER TABLE dbo.PetrotalFielConfig ADD Ambiente VARCHAR(10) NULL;
