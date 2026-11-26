-- =====================================================================
-- Ligar las notas de crédito/cargo existentes a FacturasRecibidas (2026-11-25)
--
-- Llena en TG.dbo.invoice_credit_debit_notes: factura_recibida_id, uuid,
-- emp_cod, codgas y origen='historica'. Orden de match (cada paso solo
-- sobre las notas sin pareja):
--   1) CREDIT por UUID en description ('Carga masiva - UUID <uuid>')
--   2) DEBIT y CREDIT por folio numérico + EmisorRfc = RFC proveedor
--      + ABS(Total - amount) < 0.01, solo cuando el match es único
--   3) Se excluyen UUID repetidos y los que ya tiene otra nota activa
-- Además: payment_request_invoices (is_debit_note=1, uuid NULL) recibe
-- uuid y codgas; payment_requests con emp_cod NULL (solo notas) recibe
-- emp_cod si todas sus notas comparten empresa.
--
-- Esperado verificado en dry-run 2026-11-25: 177 por UUID, 78 por folio (72 DEBIT + 6 CREDIT).
-- El análisis del 2026-11-24 daba ~254 por UUID, pero eran 254 notas sobre solo 212 UUID
-- distintos: varias notas comparten el mismo CFDI y el índice UX_icdn_uuid_activa solo
-- permite una nota activa por UUID. Las notas con UUID compartido (77) NO se ligan
-- (se excluyen en el paso 3) y salen en la lista de sin pareja.
-- Si los conteos reales se alejan más de 5 se aborta con RAISERROR.
--
-- @aplicar = 0 : dry-run (todo dentro de BEGIN TRAN ... ROLLBACK)
-- @aplicar = 1 : COMMIT
-- Respaldo: TG.dbo.bkp_notas_fr_20261125_icdn / _pri
-- =====================================================================
SET NOCOUNT ON;
SET XACT_ABORT ON;
SET QUOTED_IDENTIFIER ON;  -- payment_request_invoices tiene índice filtrado
SET ANSI_NULLS ON;

DECLARE @aplicar BIT = 0;
DECLARE @esperado_uuid INT = 177;
DECLARE @esperado_folio INT = 78;

IF OBJECT_ID('tempdb..#m') IS NOT NULL DROP TABLE #m;
CREATE TABLE #m (note_id INT NOT NULL, fr_id INT NOT NULL, uuid VARCHAR(50) NOT NULL,
                 emp_cod INT NULL, codgas INT NULL, metodo VARCHAR(10) NOT NULL);

-- Empresa por RFC receptor (el RFC puede repetirse: la de menor cod)
IF OBJECT_ID('tempdb..#emp') IS NOT NULL DROP TABLE #emp;
SELECT rfc COLLATE DATABASE_DEFAULT AS rfc, MIN(cod) AS cod
INTO #emp FROM SG12.dbo.Empresas WHERE rfc IS NOT NULL GROUP BY rfc;

-- Notas candidatas
IF OBJECT_ID('tempdb..#n') IS NOT NULL DROP TABLE #n;
SELECT n.id, n.provider_id, n.note_type, n.note_number, n.amount, n.description,
       TRY_CAST(SUBSTRING(n.note_number, PATINDEX('%[0-9]%', n.note_number), 50) AS BIGINT) AS folio_num,
       CASE WHEN n.description LIKE 'Carga masiva - UUID %'
            THEN LTRIM(RTRIM(SUBSTRING(n.description, 21, 40))) END AS uuid_desc
INTO #n
FROM TG.dbo.invoice_credit_debit_notes n
WHERE n.status = 1 AND n.factura_recibida_id IS NULL;

-- Paso 1: CREDIT por UUID de la descripción
INSERT INTO #m (note_id, fr_id, uuid, emp_cod, codgas, metodo)
SELECT n.id, fr.Id, fr.UUID, e.cod, TRY_CAST(fr.EstacionCodgas AS INT), 'UUID'
FROM #n n
JOIN TG.dbo.FacturasRecibidas fr
  ON fr.UUID COLLATE DATABASE_DEFAULT = n.uuid_desc COLLATE DATABASE_DEFAULT
LEFT JOIN #emp e ON e.rfc = fr.ReceptorRfc COLLATE DATABASE_DEFAULT
WHERE n.note_type = 'CREDIT' AND n.uuid_desc IS NOT NULL;

-- Paso 2: folio + RFC emisor + monto, solo si es único en ambos sentidos
IF OBJECT_ID('tempdb..#c') IS NOT NULL DROP TABLE #c;
SELECT n.id AS note_id, fr.Id AS fr_id, fr.UUID, fr.ReceptorRfc, fr.EstacionCodgas
INTO #c
FROM #n n
JOIN SG12.dbo.Proveedores p ON p.cod = n.provider_id
JOIN TG.dbo.FacturasRecibidas fr
  ON fr.EmisorRfc COLLATE DATABASE_DEFAULT = p.rfc COLLATE DATABASE_DEFAULT
 AND TRY_CAST(fr.Folio AS BIGINT) = n.folio_num
 AND ABS(fr.Total - n.amount) < 0.01
WHERE n.folio_num IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM #m m WHERE m.note_id = n.id);

INSERT INTO #m (note_id, fr_id, uuid, emp_cod, codgas, metodo)
SELECT c.note_id, c.fr_id, c.UUID, e.cod, TRY_CAST(c.EstacionCodgas AS INT), 'FOLIO'
FROM #c c
LEFT JOIN #emp e ON e.rfc = c.ReceptorRfc COLLATE DATABASE_DEFAULT
WHERE c.note_id IN (SELECT note_id FROM #c GROUP BY note_id HAVING COUNT(*) = 1)
  AND c.fr_id   IN (SELECT fr_id   FROM #c GROUP BY fr_id   HAVING COUNT(*) = 1)
  AND NOT EXISTS (SELECT 1 FROM #m m WHERE m.fr_id = c.fr_id);

-- Paso 3: excluir UUID repetidos en #m o ya usados por otra nota activa
DELETE FROM #m WHERE uuid COLLATE DATABASE_DEFAULT IN (
    SELECT uuid COLLATE DATABASE_DEFAULT FROM #m GROUP BY uuid HAVING COUNT(*) > 1);
DELETE m FROM #m m
WHERE EXISTS (SELECT 1 FROM TG.dbo.invoice_credit_debit_notes o
              WHERE o.status = 1 AND o.uuid COLLATE DATABASE_DEFAULT = m.uuid COLLATE DATABASE_DEFAULT);

-- Validaciones
DECLARE @total INT = (SELECT COUNT(*) FROM #m);
DECLARE @porUuid INT = (SELECT COUNT(*) FROM #m WHERE metodo = 'UUID');
DECLARE @porFolio INT = (SELECT COUNT(*) FROM #m WHERE metodo = 'FOLIO');
DECLARE @multi INT = (SELECT COUNT(*) FROM (SELECT note_id FROM #m GROUP BY note_id HAVING COUNT(*) > 1) x);
DECLARE @dupUuid INT = (SELECT COUNT(*) FROM (SELECT uuid FROM #m GROUP BY uuid HAVING COUNT(*) > 1) x);

SELECT @total total, @porUuid por_uuid, @porFolio por_folio, @multi notas_multi_match, @dupUuid uuid_repetidos;
SELECT metodo, n.note_type, COUNT(*) cuantas,
       SUM(CASE WHEN m.emp_cod IS NULL THEN 1 ELSE 0 END) sin_empresa,
       SUM(CASE WHEN m.codgas IS NULL THEN 1 ELSE 0 END) sin_codgas
FROM #m m JOIN TG.dbo.invoice_credit_debit_notes n ON n.id = m.note_id
GROUP BY metodo, n.note_type ORDER BY metodo, n.note_type;

IF @multi > 0 OR @dupUuid > 0
   OR ABS(@porUuid - @esperado_uuid) > 5 OR ABS(@porFolio - @esperado_folio) > 5
BEGIN
    RAISERROR('Validación falló (multi-match, UUID repetido o conteos fuera de lo esperado); no se modificó nada.', 16, 1);
    RETURN;
END

BEGIN TRAN;

    SELECT n.* INTO TG.dbo.bkp_notas_fr_20261125_icdn
    FROM TG.dbo.invoice_credit_debit_notes n WHERE n.id IN (SELECT note_id FROM #m);

    SELECT p.* INTO TG.dbo.bkp_notas_fr_20261125_pri
    FROM TG.dbo.payment_request_invoices p
    JOIN TG.dbo.payment_requests r ON r.id = p.payment_request_id
    JOIN TG.dbo.invoice_credit_debit_notes n ON n.note_number = p.folio AND n.provider_id = r.provider_cod
    JOIN #m m ON m.note_id = n.id
    WHERE p.is_debit_note = 1 AND p.uuid IS NULL;

    SELECT r.* INTO TG.dbo.bkp_notas_fr_20261125_pr
    FROM TG.dbo.payment_requests r
    WHERE r.emp_cod IS NULL AND r.is_deleted = 0
      AND EXISTS (SELECT 1 FROM TG.dbo.payment_request_invoices p WHERE p.payment_request_id = r.id AND p.is_deleted = 0)
      AND NOT EXISTS (SELECT 1 FROM TG.dbo.payment_request_invoices p
                      WHERE p.payment_request_id = r.id AND p.is_deleted = 0
                        AND ISNULL(p.is_debit_note, 0) = 0);

    UPDATE n SET n.factura_recibida_id = m.fr_id, n.uuid = m.uuid, n.emp_cod = m.emp_cod,
                 n.codgas = m.codgas, n.origen = 'historica'
    FROM TG.dbo.invoice_credit_debit_notes n JOIN #m m ON m.note_id = n.id;
    PRINT CONCAT('notas actualizadas: ', @@ROWCOUNT);

    -- Filas de pago de notas de cargo (mismo folio y proveedor); solo si la nota es única por folio+proveedor
    UPDATE p SET p.uuid = m.uuid, p.codgas = m.codgas
    FROM TG.dbo.payment_request_invoices p
    JOIN TG.dbo.payment_requests r ON r.id = p.payment_request_id
    JOIN TG.dbo.invoice_credit_debit_notes n ON n.note_number = p.folio AND n.provider_id = r.provider_cod AND n.status = 1
    JOIN #m m ON m.note_id = n.id
    WHERE p.is_debit_note = 1 AND p.uuid IS NULL
      AND (SELECT COUNT(*) FROM TG.dbo.invoice_credit_debit_notes n2
           WHERE n2.status = 1 AND n2.note_number = n.note_number AND n2.provider_id = n.provider_id) = 1;
    PRINT CONCAT('payment_request_invoices actualizadas: ', @@ROWCOUNT);

    -- emp_cod de requisiciones solo de notas, si todas comparten empresa
    UPDATE r SET r.emp_cod = x.emp_cod
    FROM TG.dbo.payment_requests r
    JOIN (SELECT p.payment_request_id AS rid, MIN(n.emp_cod) AS emp_cod
          FROM TG.dbo.payment_request_invoices p
          JOIN TG.dbo.payment_requests r2 ON r2.id = p.payment_request_id
          JOIN TG.dbo.invoice_credit_debit_notes n
            ON n.note_number = p.folio AND n.provider_id = r2.provider_cod AND n.status = 1
          WHERE p.is_deleted = 0
          GROUP BY p.payment_request_id
          HAVING COUNT(DISTINCT n.emp_cod) = 1 AND SUM(CASE WHEN n.emp_cod IS NULL THEN 1 ELSE 0 END) = 0) x
      ON x.rid = r.id
    WHERE r.emp_cod IS NULL
      AND NOT EXISTS (SELECT 1 FROM TG.dbo.payment_request_invoices p
                      WHERE p.payment_request_id = r.id AND p.is_deleted = 0
                        AND ISNULL(p.is_debit_note, 0) = 0);
    PRINT CONCAT('payment_requests emp_cod actualizadas: ', @@ROWCOUNT);

    -- Verificación dentro de la transacción
    SELECT origen, note_type, COUNT(*) cuantas
    FROM TG.dbo.invoice_credit_debit_notes GROUP BY origen, note_type ORDER BY origen, note_type;

    -- Notas sin pareja (a entregar al usuario)
    SELECT n.id, n.provider_id, pr.den AS proveedor, n.note_type, n.note_number, n.note_date, n.amount, n.description
    FROM TG.dbo.invoice_credit_debit_notes n
    LEFT JOIN SG12.dbo.Proveedores pr ON pr.cod = n.provider_id
    WHERE n.status = 1 AND n.factura_recibida_id IS NULL
    ORDER BY n.provider_id, n.note_date;

IF @aplicar = 1
BEGIN
    COMMIT;
    PRINT 'APLICADO (COMMIT).';
END
ELSE
BEGIN
    ROLLBACK;
    PRINT 'DRY-RUN: ROLLBACK, no se guardó nada.';
END
