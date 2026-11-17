-- docs/sql/debit_clients_cierre_anticipos_post.sql
-- Foto débito (2026-11-17): la lectura de las 08:00 ya trae los anticipos con
-- fecha de HOY capturados antes de esa hora (suben bolsa y vehículos), y el
-- cierre de AYER los tomaba como suyos. DebitCierre::reconstruir ahora se los
-- quita; esta columna guarda cuánto se quitó (auditoría, como consumos_post_cierre).
-- Correr ANTES de subir DebitClientsCierreModel.php (el INSERT la incluye).
USE TG;
GO
IF COL_LENGTH('dbo.debit_clients_cierre', 'anticipos_post_cierre') IS NULL
    ALTER TABLE dbo.debit_clients_cierre ADD
        anticipos_post_cierre DECIMAL(18,2) NULL;  -- anticipos con fecha posterior al día, ya en la lectura
GO
