-- =====================================================================
-- Ligar las notas de crédito/cargo existentes a FacturasRecibidas (2026-11-25)
--
-- Llena en TG.dbo.invoice_credit_debit_notes: factura_recibida_id, uuid,
-- emp_cod, codgas y origen='historica'. Match (solo notas sin pareja):
--   CREDIT: folio numérico + EmisorRfc = RFC del proveedor + ABS(Total-amount)<0.01
--           + TipoDeComprobante='E'
--   DEBIT : igual, con TipoDeComprobante='I'
--   Pasada 1: folio exacto. Pasada 2 (solo notas sin pareja y FR libres): el número de
--   la nota TERMINA en el folio de la FR (CT4401842666 <-> 842666), mismas reglas.
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
-- Esperado (dry-run 2026-11-25): 142 CREDIT (todas pasada 1) y 73 DEBIT (72 folio + 1 sufijo).
-- La pasada 2 no agrega CREDIT: las notas 9 (CT4401842666) y 10 (NC4401842664) son el
-- mismo CFDI (FR 59589 / 59562) que las notas 49 y 52 ('CT 842666'/'CT 842664'), ya
-- ligadas en la pasada 1; al ser duplicadas no se ligan y salen en la lista "Duplicadas".
-- Hay 6 FR con 2 notas (NC/CT/BA del mismo CFDI) y 1 FR con 3 notas DEBIT SE034092 que
-- no se ligan. Si los conteos reales se alejan más de 5 se aborta con RAISERROR.
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
DECLARE @esperado_credit INT = 142;  -- pasada 1 + pasada 2
DECLARE @esperado_debit INT = 73;  -- 72 por folio + 1 por sufijo

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

-- Candidatos por folio exacto (pasada 1)
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

-- Candidatos por sufijo (pasada 2): el número de la nota termina en el folio de la FR
IF OBJECT_ID('tempdb..#c2') IS NOT NULL DROP TABLE #c2;
SELECT n.id AS note_id, fr.Id AS fr_id, fr.UUID, fr.ReceptorRfc, fr.EstacionCodgas, n.note_type
INTO #c2
FROM #n n
JOIN SG12.dbo.Proveedores p ON p.cod = n.provider_id
JOIN TG.dbo.FacturasRecibidas fr
  ON fr.EmisorRfc COLLATE DATABASE_DEFAULT = p.rfc COLLATE DATABASE_DEFAULT
 AND TRY_CAST(fr.Folio AS BIGINT) > 0
 AND LEN(CAST(TRY_CAST(fr.Folio AS BIGINT) AS VARCHAR(30))) <= LEN(CAST(n.folio_num AS VARCHAR(30)))
 AND RIGHT(CAST(n.folio_num AS VARCHAR(30)), LEN(CAST(TRY_CAST(fr.Folio AS BIGINT) AS VARCHAR(30))))
     = CAST(TRY_CAST(fr.Folio AS BIGINT) AS VARCHAR(30))
 AND ABS(fr.Total - n.amount) < 0.01
 AND fr.TipoDeComprobante = CASE n.note_type WHEN 'CREDIT' THEN 'E' ELSE 'I' END
WHERE n.folio_num IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM TG.dbo.invoice_credit_debit_notes o
                  WHERE o.status = 1
                    AND (o.factura_recibida_id = fr.Id
                         OR o.uuid COLLATE DATABASE_DEFAULT = fr.UUID COLLATE DATABASE_DEFAULT));

-- #c2 incluye candidatos ya tomados en la pasada 1 (para listar duplicadas); aquí solo los libres
IF OBJECT_ID('tempdb..#c2l') IS NOT NULL DROP TABLE #c2l;
SELECT * INTO #c2l FROM #c2 c
WHERE NOT EXISTS (SELECT 1 FROM #m m WHERE m.note_id = c.note_id OR m.fr_id = c.fr_id);

INSERT INTO #m (note_id, fr_id, uuid, emp_cod, codgas, metodo)
SELECT c.note_id, c.fr_id, c.UUID, e.cod, TRY_CAST(c.EstacionCodgas AS INT), 'SUFIJO_' + c.note_type
FROM #c2l c
LEFT JOIN #emp e ON e.rfc = c.ReceptorRfc COLLATE DATABASE_DEFAULT
WHERE c.note_id IN (SELECT note_id FROM #c2l GROUP BY note_id HAVING COUNT(*) = 1)
  AND c.fr_id   IN (SELECT fr_id   FROM #c2l GROUP BY fr_id   HAVING COUNT(*) = 1);

-- Duplicadas: el mismo CFDI capturado 2 veces — revisar (no se ligan)
SELECT 'Duplicadas: el mismo CFDI capturado 2 veces - revisar' AS etiqueta;
SELECT d.fr_id, fr.Folio AS fr_folio, fr.Total AS fr_total, fr.Fecha AS fr_fecha,
       n.id AS note_id, n.note_number, n.amount,
       (SELECT COUNT(*) FROM TG.dbo.credit_note_applications a WHERE a.credit_note_id = n.id AND a.status = 1) AS aplicaciones_activas,
       (SELECT COUNT(*) FROM TG.dbo.payment_request_invoices p
          JOIN TG.dbo.payment_requests r ON r.id = p.payment_request_id
         WHERE p.folio = n.note_number AND r.provider_cod = n.provider_id AND p.is_deleted = 0) AS en_pagos_activos
FROM (SELECT fr_id FROM (SELECT note_id, fr_id FROM #c UNION SELECT note_id, fr_id FROM #c2) u
      GROUP BY fr_id HAVING COUNT(DISTINCT note_id) > 1) d
JOIN TG.dbo.FacturasRecibidas fr ON fr.Id = d.fr_id
JOIN (SELECT note_id, fr_id FROM #c UNION SELECT note_id, fr_id FROM #c2) u2 ON u2.fr_id = d.fr_id
JOIN TG.dbo.invoice_credit_debit_notes n ON n.id = u2.note_id
ORDER BY d.fr_id, n.id;

-- Filas de pago de notas de cargo a actualizar (activas; nota única por folio+proveedor)
IF OBJECT_ID('tempdb..#pri') IS NOT NULL DROP TABLE #pri;
SELECT p.id AS pri_id, m.uuid, m.codgas
INTO #pri
FROM TG.dbo.payment_request_invoices p
JOIN TG.dbo.payment_requests r ON r.id = p.payment_request_id
JOIN TG.dbo.invoice_credit_debit_notes n
  ON n.note_number = p.folio AND n.provider_id = r.provider_cod AND n.status = 1 AND n.note_type = 'DEBIT'
JOIN #m m ON m.note_id = n.id
WHERE p.is_debit_note = 1 AND p.uuid IS NULL AND ISNULL(p.is_deleted, 0) = 0
  AND (SELECT COUNT(*) FROM TG.dbo.invoice_credit_debit_notes n2
       WHERE n2.status = 1 AND n2.note_number = n.note_number AND n2.provider_id = n.provider_id) = 1;

-- Requisiciones a las que se les completa emp_cod: sin emp_cod, activas, TODAS sus filas activas
-- son notas de cargo con nota emparejada, y una sola empresa
IF OBJECT_ID('tempdb..#pr') IS NOT NULL DROP TABLE #pr;
SELECT p.payment_request_id AS rid, MIN(x.emp_cod) AS emp_cod
INTO #pr
FROM TG.dbo.payment_request_invoices p
JOIN TG.dbo.payment_requests r ON r.id = p.payment_request_id AND r.emp_cod IS NULL AND r.is_deleted = 0
OUTER APPLY (SELECT COUNT(*) AS hits, MIN(m.emp_cod) AS emp_cod,
                    SUM(CASE WHEN m.emp_cod IS NULL THEN 1 ELSE 0 END) AS nulos
             FROM TG.dbo.invoice_credit_debit_notes n JOIN #m m ON m.note_id = n.id
             WHERE n.note_number = p.folio AND n.provider_id = r.provider_cod AND n.note_type = 'DEBIT') x
WHERE ISNULL(p.is_deleted, 0) = 0
GROUP BY p.payment_request_id
HAVING COUNT(*) = SUM(CASE WHEN p.is_debit_note = 1 AND x.hits = 1 AND x.nulos = 0 THEN 1 ELSE 0 END)
   AND COUNT(DISTINCT x.emp_cod) = 1;

-- Validaciones
DECLARE @total INT = (SELECT COUNT(*) FROM #m);
DECLARE @credit INT = (SELECT COUNT(*) FROM #m WHERE metodo LIKE '%_CREDIT');
DECLARE @debit INT = (SELECT COUNT(*) FROM #m WHERE metodo LIKE '%_DEBIT');
DECLARE @multi INT = (SELECT COUNT(*) FROM (SELECT note_id FROM #m GROUP BY note_id HAVING COUNT(*) > 1) x);
DECLARE @dupUuid INT = (SELECT COUNT(*) FROM (SELECT uuid FROM #m GROUP BY uuid HAVING COUNT(*) > 1) x);
DECLARE @pri_esperado INT = (SELECT COUNT(*) FROM #pri);
DECLARE @pr_esperado INT = (SELECT COUNT(*) FROM #pr);

SELECT @total total, @credit credit, @debit debit, @multi notas_multi_match, @dupUuid uuid_repetidos,
       @pri_esperado pri_a_actualizar, @pr_esperado pr_a_actualizar;
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
    FROM TG.dbo.payment_request_invoices p WHERE p.id IN (SELECT pri_id FROM #pri);

    SELECT r.* INTO TG.dbo.bkp_notas_fr_20261125_pr
    FROM TG.dbo.payment_requests r WHERE r.id IN (SELECT rid FROM #pr);

    DECLARE @n_upd INT, @pri_upd INT, @pr_upd INT;

    UPDATE n SET n.factura_recibida_id = m.fr_id, n.uuid = m.uuid, n.emp_cod = m.emp_cod,
                 n.codgas = m.codgas, n.origen = 'historica'
    FROM TG.dbo.invoice_credit_debit_notes n JOIN #m m ON m.note_id = n.id;
    SET @n_upd = @@ROWCOUNT;
    PRINT CONCAT('notas actualizadas: ', @n_upd);

    UPDATE p SET p.uuid = t.uuid, p.codgas = t.codgas
    FROM TG.dbo.payment_request_invoices p JOIN #pri t ON t.pri_id = p.id;
    SET @pri_upd = @@ROWCOUNT;
    PRINT CONCAT('payment_request_invoices actualizadas: ', @pri_upd);

    UPDATE r SET r.emp_cod = t.emp_cod
    FROM TG.dbo.payment_requests r JOIN #pr t ON t.rid = r.id
    WHERE r.emp_cod IS NULL AND r.is_deleted = 0;
    SET @pr_upd = @@ROWCOUNT;
    PRINT CONCAT('payment_requests emp_cod actualizadas: ', @pr_upd);

    -- Comprobaciones previas al COMMIT (también se imprimen en dry-run)
    DECLARE @dup_icdn INT = (SELECT COUNT(*) FROM (SELECT uuid FROM TG.dbo.invoice_credit_debit_notes
                              WHERE status = 1 AND uuid IS NOT NULL GROUP BY uuid HAVING COUNT(*) > 1) x);
    DECLARE @dup_pri INT = (SELECT COUNT(*) FROM (SELECT uuid FROM TG.dbo.payment_request_invoices
                              WHERE is_deleted = 0 AND uuid IS NOT NULL GROUP BY uuid HAVING COUNT(*) > 1) x);
    SELECT @n_upd notas_upd, @total notas_esperadas, @pri_upd pri_upd, @pri_esperado pri_esperadas,
           @pr_upd pr_upd, @pr_esperado pr_esperadas, @dup_icdn uuid_dup_notas, @dup_pri uuid_dup_pri;

    IF @n_upd <> @total OR @pri_upd <> @pri_esperado OR @pr_upd <> @pr_esperado
       OR @dup_icdn > 0 OR @dup_pri > 0
    BEGIN
        ROLLBACK;
        RAISERROR('Comprobación previa al COMMIT falló (conteos o UUID duplicados); se hizo ROLLBACK.', 16, 1);
        RETURN;
    END

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
