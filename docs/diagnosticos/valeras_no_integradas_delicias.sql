-- ============================================================
-- Delicias (codgas 19) · ¿se puede saber en Despachos cuáles fueron valeras/tarjetas no integradas?
-- ============================================================
DECLARE @codgas INT = 19;
DECLARE @fecha  DATE = '2026-08-03';                     -- día a revisar
DECLARE @fch    INT  = DATEDIFF(dd, 0, @fecha) + 1;      -- formato ControlGas

-- 1) Lo que se capturó en el corte por turno (valores NO integrados)
--    nrotur: 11/21/31/41 = turno 1..4
SELECT i.fch, CONVERT(DATE, DATEADD(DAY,-1,i.fch)) AS fecha, i.nrotur, i.codisl, v.cod, v.den, i.mto, i.logfch
FROM SG12.dbo.Ingresos i
JOIN SG12.dbo.Valores v ON v.cod = i.codval
WHERE i.codgas = @codgas AND i.fch = @fch
  AND v.cod IN (212, 194, 201, 223, 209)   -- Santander, EfectiCard, TicketCar, TicketCar+, AmEx
ORDER BY i.nrotur, v.den;

-- 2) Despachos "contado" del mismo día SIN movimiento de terminal (aquí deberían estar escondidas)
SELECT t1.nrotur, t1.nrotrn, t1.hratrn, t1.codisl, t1.nrobom, t1.codprd, t1.can, t1.mto,
       t1.tiptrn, t1.codcli, t1.tar, t1.nroveh, t1.rut, t1.cho, t1.pto, t1.nrocte, t1.nrofac, t1.gasfac,
       t1.nroedc, t1.chkedc, t1.logmsk, t1.datref, t1.satuid, t1.satrfc, t1.logusu
FROM SG12.dbo.Despachos t1
WHERE t1.codgas = @codgas AND t1.fchtrn = @fch AND t1.mto > 0 AND t1.tiptrn IN (0, 49)
  AND NOT EXISTS (SELECT 1 FROM SG12.dbo.MovimientosTar m
                  WHERE m.nrotrn = t1.nrotrn AND m.codgas = t1.codgas AND m.mto <> 0)
ORDER BY t1.nrotur, t1.hratrn;

-- 3) Búsqueda directa: despachos del turno cuyo monto es EXACTAMENTE el de un valor del corte
--    (si el voucher fue de 1 solo despacho, aparece aquí)
SELECT i.nrotur, v.den, i.mto AS mto_corte, t1.nrotrn, t1.hratrn, t1.mto AS mto_despacho, t1.tiptrn, t1.codcli, t1.tar, t1.datref, t1.logmsk
FROM SG12.dbo.Ingresos i
JOIN SG12.dbo.Valores v ON v.cod = i.codval
JOIN SG12.dbo.Despachos t1 ON t1.codgas = i.codgas AND t1.fchtrn = i.fch AND t1.nrotur = i.nrotur AND ABS(t1.mto - i.mto) < 0.01
WHERE i.codgas = @codgas AND i.fch = @fch AND v.cod IN (212, 194, 201, 223, 209)
ORDER BY i.nrotur, v.den;

-- 4) ¿Algún campo de los despachos contado se ve distinto? (distribución de campos candidatos, todo agosto)
SELECT t1.tiptrn, t1.logmsk, t1.gasfac, CASE WHEN ISNULL(t1.tar,'') = '' THEN 'sin tar' ELSE 'con tar' END AS tar,
       CASE WHEN ISNULL(CAST(t1.datref AS VARCHAR(200)),'') = '' THEN 'sin datref' ELSE 'con datref' END AS datref,
       COUNT(*) n, SUM(t1.mto) mto
FROM SG12.dbo.Despachos t1
WHERE t1.codgas = @codgas AND t1.fchtrn BETWEEN DATEDIFF(dd,0,'2026-08-01')+1 AND DATEDIFF(dd,0,'2026-08-31')+1
  AND t1.mto > 0 AND t1.tiptrn IN (0, 49)
  AND NOT EXISTS (SELECT 1 FROM SG12.dbo.MovimientosTar m WHERE m.nrotrn = t1.nrotrn AND m.codgas = t1.codgas AND m.mto <> 0)
GROUP BY t1.tiptrn, t1.logmsk, t1.gasfac,
         CASE WHEN ISNULL(t1.tar,'') = '' THEN 'sin tar' ELSE 'con tar' END,
         CASE WHEN ISNULL(CAST(t1.datref AS VARCHAR(200)),'') = '' THEN 'sin datref' ELSE 'con datref' END
ORDER BY n DESC;
