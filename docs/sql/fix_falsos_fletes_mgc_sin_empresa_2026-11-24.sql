-- =====================================================================
-- Falsos fletes MGC capturados como nota de cargo sin empresa ni UUID
-- (2026-11-24). Requisiciones 1545,1652,1688,1748,1749,1857,1984,1985,2026.
--
-- Causa: add_payment.html toma la empresa del filtro #company cuando la
-- requisición solo trae notas de cargo; si no se elige queda emp_cod NULL.
-- Las notas son CFDI reales en FacturasRecibidas (no están en ControlGas).
--
-- Corrige:
--   1) payment_request_invoices.uuid  <- UUID del CFDI en FacturasRecibidas
--      (match: folio 'SE0034398' = Folio '034398' numérico, emisor MGC, mismo monto)
--   2) payment_requests.emp_cod       <- SG12.Empresas.cod por RFC receptor
-- Respaldo: TG.dbo.bkp_fletes_mgc_20261124_pri / _pr
-- =====================================================================
SET NOCOUNT ON;
SET XACT_ABORT ON;
SET QUOTED_IDENTIFIER ON;  -- sqlcmd lo trae OFF; payment_request_invoices tiene índice filtrado
SET ANSI_NULLS ON;

IF OBJECT_ID('tempdb..#m') IS NOT NULL DROP TABLE #m;

SELECT pri.id AS pri_id, pri.payment_request_id AS rid, pri.folio, pri.amount,
       fr.UUID, fr.ReceptorRfc, e.cod AS emp_cod, e.den AS emp_den
INTO #m
FROM TG.dbo.payment_request_invoices pri
JOIN TG.dbo.payment_requests r ON r.id = pri.payment_request_id
JOIN TG.dbo.FacturasRecibidas fr
  ON fr.EmisorNombre LIKE '%mgc%'
 AND fr.TipoDeComprobante = 'I'
 AND TRY_CAST(fr.Folio AS INT) = TRY_CAST(SUBSTRING(pri.folio, 3, 20) AS INT)
 AND ABS(fr.Total - pri.amount) < 0.01
LEFT JOIN SG12.dbo.Empresas e ON e.rfc = fr.ReceptorRfc
WHERE pri.payment_request_id IN (1545,1652,1688,1748,1749,1857,1984,1985,2026)
  AND pri.is_debit_note = 1 AND pri.is_deleted = 0
  AND pri.uuid IS NULL AND r.emp_cod IS NULL AND r.provider_cod = 72;

-- Validaciones: 24 líneas, 1 CFDI por línea, empresa encontrada, 1 empresa por requisición
DECLARE @lineas INT = (SELECT COUNT(*) FROM #m);
DECLARE @dup INT = (SELECT COUNT(*) FROM (SELECT pri_id FROM #m GROUP BY pri_id HAVING COUNT(*) > 1) x);
DECLARE @sinEmp INT = (SELECT COUNT(*) FROM #m WHERE emp_cod IS NULL);
DECLARE @mix INT = (SELECT COUNT(*) FROM (SELECT rid FROM #m GROUP BY rid HAVING COUNT(DISTINCT emp_cod) > 1) x);
DECLARE @uuidUsado INT = (SELECT COUNT(*) FROM #m m JOIN TG.dbo.payment_request_invoices p
                           ON p.uuid = m.UUID AND p.is_deleted = 0);

SELECT @lineas lineas, @dup duplicados, @sinEmp sin_empresa, @mix req_mixtas, @uuidUsado uuid_ya_usado;
SELECT rid, emp_cod, emp_den, COUNT(*) lineas, SUM(amount) total FROM #m GROUP BY rid, emp_cod, emp_den ORDER BY rid;

IF @lineas <> 24 OR @dup > 0 OR @sinEmp > 0 OR @mix > 0 OR @uuidUsado > 0
BEGIN
    RAISERROR('Validación falló; no se modificó nada.', 16, 1);
    RETURN;
END

BEGIN TRAN;

    SELECT p.* INTO TG.dbo.bkp_fletes_mgc_20261124_pri
    FROM TG.dbo.payment_request_invoices p WHERE p.id IN (SELECT pri_id FROM #m);

    SELECT r.* INTO TG.dbo.bkp_fletes_mgc_20261124_pr
    FROM TG.dbo.payment_requests r WHERE r.id IN (SELECT DISTINCT rid FROM #m);

    UPDATE p SET p.uuid = m.UUID
    FROM TG.dbo.payment_request_invoices p JOIN #m m ON m.pri_id = p.id;
    PRINT CONCAT('uuid actualizados: ', @@ROWCOUNT);

    UPDATE r SET r.emp_cod = x.emp_cod
    FROM TG.dbo.payment_requests r
    JOIN (SELECT DISTINCT rid, emp_cod FROM #m) x ON x.rid = r.id
    WHERE r.emp_cod IS NULL;
    PRINT CONCAT('emp_cod actualizados: ', @@ROWCOUNT);

COMMIT;

-- Verificación
SELECT r.id, r.emp_cod, e.den, COUNT(p.id) lineas, SUM(CASE WHEN p.uuid IS NOT NULL THEN 1 ELSE 0 END) con_uuid
FROM TG.dbo.payment_requests r
JOIN SG12.dbo.Empresas e ON e.cod = r.emp_cod
JOIN TG.dbo.payment_request_invoices p ON p.payment_request_id = r.id AND p.is_deleted = 0
WHERE r.id IN (1545,1652,1688,1748,1749,1857,1984,1985,2026)
GROUP BY r.id, r.emp_cod, e.den ORDER BY r.id;
