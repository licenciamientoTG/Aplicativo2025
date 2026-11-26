-- =====================================================================
-- Ligar las notas de crédito/cargo existentes a FacturasRecibidas (2026-11-25)
--
-- Llena en TG.dbo.invoice_credit_debit_notes: factura_recibida_id, uuid,
-- emp_cod, codgas y origen='historica'. Match (solo notas sin pareja):
--   CREDIT: folio numérico + EmisorRfc = RFC del proveedor + ABS(Total-amount)<0.01
--           + TipoDeComprobante='E'
--   DEBIT : igual, con TipoDeComprobante='I'
--   Solo se liga cuando el match es único por nota Y único por fila de FR
--   (dos notas nunca a la misma FR). Se excluyen FR/UUID ya ligados a otra
--   nota activa (índice UX_icdn_uuid_activa).
-- El UUID de description ('Carga masiva - UUID <uuid>') NO es el de la nota:
-- es el de la factura de combustible relacionada (tipo I); no se usa para ligar,
-- solo se cuenta de forma informativa.
-- Además: payment_request_invoices (is_debit_note=1, uuid NULL) recibe
-- uuid y codgas; payment_requests con emp_cod NULL (solo notas) recibe
-- emp_cod si todas sus notas comparten empresa.
--
-- Esperado (dry-run 2026-11-25): 142 CREDIT y 72 DEBIT. Las 142 son las notas con
-- match único por nota y por FR; hay ~148 con match por folio, pero 6 FR (12 notas,
-- p.ej. 'NC842663' y 'CT 842663') tienen dos notas capturadas con el mismo CFDI y
-- no se ligan (quedan en la lista de sin pareja). Si los conteos reales se alejan
-- más de 5 se aborta con RAISERROR.
--
-- @aplicar = 0 : dry-run (todo dentro de BEGIN TRAN ... ROLLBACK)
-- @aplicar = 1 : COMMIT
-- Respaldo: TG.dbo.bkp_notas_fr_20261125_icdn / _pri / _pr
-- =====================================================================
SET NOCOUNT ON;
SET XACT_ABORT ON;
SET QUOTED_IDENTIFIER ON;  -- payment_request_invoices tiene índice filtrado
SET ANSI_NULLS ON;

DECLARE @aplicar BIT = 0;
DECLARE @esperado_credit INT = 142;
DECLARE @esperado_debit INT = 72;

IF OBJECT_ID('tempdb..#m') IS NOT NULL DROP TABLE #m;
CREATE TABLE #m (note_id INT NOT NULL, fr_id INT NOT NULL, uuid VARCHAR(50) NOT NULL,
                 emp_cod INT NULL, codgas INT NULL, metodo VARCHAR(20) NOT NULL);

-- Empresa por RFC receptor (el RFC puede repetirse: la de menor cod)
IF OBJECT_ID('tempdb..#emp') IS NOT NULL DROP TABLE #emp;
SELECT rfc COLLATE DATABASE_DEFAULT AS rfc, MIN(cod) AS cod
INTO #emp FROM SG12.dbo.Empresas WHERE rfc IS NOT NULL GROUP BY rfc;

-- Notas candidatas
IF OBJECT_ID('tempdb..#n') IS NOT NULL DROP TABLE #n;
SELECT n.id, n.provider_id, n.note_type, n.note_number, n.amount, n.description,
       TRY_CAST(SUBSTRING(n.note_number, PATINDEX('%[0-9]%', n.note_number), 50) AS BIGINT) AS folio_num
INTO #n
FROM TG.dbo.invoice_credit_debit_notes n
WHERE n.status = 1 AND n.factura_recibida_id IS NULL;

-- Informativo: el UUID en la descripción es la factura relacionada, no se usa
SELECT COUNT(*) AS uuid_en_descripcion_factura_relacionada_no_se_usa
FROM #n WHERE description LIKE 'Carga masiva - UUID %';

-- Match por folio + RFC emisor + monto + tipo de comprobante
IF OBJECT_ID('tempdb..#c') IS NOT NULL DROP TABLE #c;
SELECT n.id AS note_id, fr.Id AS fr_id, fr.UUID, fr.ReceptorRfc, fr.EstacionCodgas, n.note_type
INTO #c
FROM #n n
JOIN SG12.dbo.Proveedores p ON p.cod = n.provider_id
JOIN TG.dbo.FacturasRecibidas fr
  ON fr.EmisorRfc COLLATE DATABASE_DEFAULT = p.rfc COLLATE DATABASE_DEFAULT
 AND TRY_CAST(fr.Folio AS BIGINT) = n.folio_num
 AND ABS(fr.Total - n.amount) < 0.01
 AND fr.TipoDeComprobante = CASE n.note_type WHEN 'CREDIT' THEN 'E' ELSE 'I' END
WHERE n.folio_num IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM TG.dbo.invoice_credit_debit_notes o
                  WHERE o.status = 1
                    AND (o.factura_recibida_id = fr.Id
                         OR o.uuid COLLATE DATABASE_DEFAULT = fr.UUID COLLATE DATABASE_DEFAULT));

INSERT INTO #m (note_id, fr_id, uuid, emp_cod, codgas, metodo)
SELECT c.note_id, c.fr_id, c.UUID, e.cod, TRY_CAST(c.EstacionCodgas AS INT), 'FOLIO_' + c.note_type
FROM #c c
LEFT JOIN #emp e ON e.rfc = c.ReceptorRfc COLLATE DATABASE_DEFAULT
WHERE c.note_id IN (SELECT note_id FROM #c GROUP BY note_id HAVING COUNT(*) = 1)
  AND c.fr_id   IN (SELECT fr_id   FROM #c GROUP BY fr_id   HAVING COUNT(*) = 1);

-- Seguridad: UUID repetidos en #m (índice único por UUID activo)
DELETE FROM #m WHERE uuid COLLATE DATABASE_DEFAULT IN (
    SELECT uuid COLLATE DATABASE_DEFAULT FROM #m GROUP BY uuid HAVING COUNT(*) > 1);

-- Validaciones
DECLARE @total INT = (SELECT COUNT(*) FROM #m);
DECLARE @credit INT = (SELECT COUNT(*) FROM #m WHERE metodo = 'FOLIO_CREDIT');
DECLARE @debit INT = (SELECT COUNT(*) FROM #m WHERE metodo = 'FOLIO_DEBIT');
DECLARE @multi INT = (SELECT COUNT(*) FROM (SELECT note_id FROM #m GROUP BY note_id HAVING COUNT(*) > 1) x);
DECLARE @dupUuid INT = (SELECT COUNT(*) FROM (SELECT uuid FROM #m GROUP BY uuid HAVING COUNT(*) > 1) x);

SELECT @total total, @credit credit, @debit debit, @multi notas_multi_match, @dupUuid uuid_repetidos;
SELECT metodo, COUNT(*) cuantas,
       SUM(CASE WHEN m.emp_cod IS NULL THEN 1 ELSE 0 END) sin_empresa,
       SUM(CASE WHEN m.codgas IS NULL THEN 1 ELSE 0 END) sin_codgas
FROM #m m GROUP BY metodo ORDER BY metodo;

IF @multi > 0 OR @dupUuid > 0
   OR ABS(@credit - @esperado_credit) > 5 OR ABS(@debit - @esperado_debit) > 5
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
