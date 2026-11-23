/*
 * Forward migration for TG.dbo.efc_conc_analiticos_papeletas_etiquetas.
 * Switches to TG explicitly so it is safe to run from a DBeaver connection
 * whose default database is master.
 * Reversible without data loss before labels have been added; rollback drops
 * saved labels, so export/preserve the table first if labels already exist.
 */
USE TG;

IF OBJECT_ID('dbo.efc_conc_analiticos_papeletas','U') IS NULL
BEGIN
    ;THROW 50001, N'No existe dbo.efc_conc_analiticos_papeletas en TG; ejecute primero el esquema del importador REGIO.', 1;
END;

IF OBJECT_ID('dbo.efc_conc_analiticos_papeletas_etiquetas','U') IS NULL
BEGIN
    CREATE TABLE dbo.efc_conc_analiticos_papeletas_etiquetas (
        papeleta_id INT NOT NULL PRIMARY KEY,
        color VARCHAR(7) NOT NULL,
        descripcion NVARCHAR(30) NOT NULL,
        creado_en DATETIME NOT NULL CONSTRAINT DF_efc_conc_analiticos_papeletas_etiquetas_creado DEFAULT GETDATE(),
        actualizado_en DATETIME NULL,
        CONSTRAINT CK_efc_conc_analiticos_papeletas_etiquetas_color CHECK (color IN (
            '#ef4444','#f97316','#f59e0b','#eab308','#84cc16','#22c55e','#10b981','#14b8a6',
            '#06b6d4','#0ea5e9','#3b82f6','#6366f1','#8b5cf6','#a855f7','#d946ef','#ec4899'
        )),
        CONSTRAINT CK_efc_conc_analiticos_papeletas_etiquetas_descripcion CHECK (LEN(LTRIM(RTRIM(descripcion))) BETWEEN 1 AND 30),
        CONSTRAINT FK_efc_conc_analiticos_etiquetas_papeleta FOREIGN KEY (papeleta_id)
            REFERENCES dbo.efc_conc_analiticos_papeletas(id) ON DELETE CASCADE
    );
END;

/* Rollback (deletes saved labels):
DROP TABLE dbo.efc_conc_analiticos_papeletas_etiquetas;
*/
