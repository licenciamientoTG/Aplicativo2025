-- TG only. Forward migration; no source or existing link data changes.
-- Rollback: first remove all secondary associations in the application after undoing banks;
-- then drop index, CHECK, FK and column. Export associations before rollback to preserve history.
EXEC(N'IF COL_LENGTH(''dbo.efc_conc_analiticos_vinculos'',''papeleta_secundaria_id'') IS NULL ALTER TABLE dbo.efc_conc_analiticos_vinculos ADD papeleta_secundaria_id INT NULL');
EXEC(N'IF OBJECT_ID(''dbo.FK_efc_conc_analiticos_vinculos_secundaria'',''F'') IS NULL ALTER TABLE dbo.efc_conc_analiticos_vinculos ADD CONSTRAINT FK_efc_conc_analiticos_vinculos_secundaria FOREIGN KEY(papeleta_secundaria_id) REFERENCES dbo.efc_conc_analiticos_papeletas(id)');
EXEC(N'IF OBJECT_ID(''dbo.CK_efc_conc_analiticos_vinculos_distintas'',''C'') IS NULL ALTER TABLE dbo.efc_conc_analiticos_vinculos ADD CONSTRAINT CK_efc_conc_analiticos_vinculos_distintas CHECK(papeleta_secundaria_id IS NULL OR papeleta_secundaria_id<>papeleta_id)');
EXEC(N'IF NOT EXISTS(SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID(''dbo.efc_conc_analiticos_vinculos'') AND name=''UX_efc_conc_analiticos_vinculos_secundaria_activa'') CREATE UNIQUE INDEX UX_efc_conc_analiticos_vinculos_secundaria_activa ON dbo.efc_conc_analiticos_vinculos(papeleta_secundaria_id) WHERE activo=1 AND papeleta_secundaria_id IS NOT NULL');
