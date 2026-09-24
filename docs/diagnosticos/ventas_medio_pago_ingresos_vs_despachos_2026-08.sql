SET NOCOUNT ON;
DECLARE @fi INT = DATEDIFF(dd, 0, '2026-08-01') + 1;
DECLARE @ff INT = DATEDIFF(dd, 0, '2026-08-31') + 1;
IF OBJECT_ID('tempdb..#ing') IS NOT NULL DROP TABLE #ing;
IF OBJECT_ID('tempdb..#des') IS NOT NULL DROP TABLE #des;
IF OBJECT_ID('tempdb..#r') IS NOT NULL DROP TABLE #r;

SELECT i.codgas, v.den, i.mto,
 CASE WHEN v.den IN (' Efectivo MN', ' DOLARES', ' Morralla MN', 'Transferencias') THEN 'EFECTIVO' WHEN v.den IN ('Clientes Crédito') THEN 'CREDITO' WHEN v.den IN ('Clientes Débito') THEN 'DEBITO'
      WHEN v.den IN (' SMARTBT - MANUAL Bancarias',' SMARTBT - Bancarias',' Tarjetas Bancomer', ' SMARTBT - American Express', ' Tarjetas Santander', ' Tarjetas Banorte', ' Tarjetas Afirme', 'SMARTBT - MANUAL Bancarias', 'INTERL - Tarjeta de Crédito', 'INTERL - Tarjeta de Débito', 'INTERLOGIC Manual','HTI - Tarjeta de Crédito','HTI - Tarjeta de Débito',' Tarjetas Scotiabank',' Tarjetas American Express') THEN 'TARJETAS' WHEN v.den IN (' Tarjeta EfectiCard',' Tarjetas Sodexo (Pluxee)',' SMARTBT - SODEXO WIZEO',' Vale Edenred',' Vale Sodexo','Mobil FleetPro', ' Tarjeta Inburgas', ' Tarjeta TicketCar', ' Tarjeta TicketCar +', ' Vale Efectivale', ' SMARTBT - EFECTIVALE', 'Ultra Gas', 'Tarjetas Sodexo (Pluxee)', ' Tarjeta EfectiCard +') THEN 'VALERAS' ELSE 'OTRO' END AS m0,
 CASE WHEN v.den IN (' Efectivo MN', ' DOLARES', ' Morralla MN', 'Transferencias', 'HTI - Efectivo', 'INTERL - Efectivo') THEN 'EFECTIVO' WHEN v.den IN ('Clientes Crédito') THEN 'CREDITO' WHEN v.den IN ('Clientes Débito') THEN 'DEBITO'
      WHEN v.den IN (' SMARTBT - MANUAL Bancarias',' SMARTBT - Bancarias',' Tarjetas Bancomer', ' SMARTBT - American Express', ' Tarjetas Santander', ' Tarjetas Banorte', ' Tarjetas Afirme', 'SMARTBT - MANUAL Bancarias', 'INTERL - Tarjeta de Crédito', 'INTERL - Tarjeta de Débito', 'INTERLOGIC Manual','HTI - Tarjeta de Crédito','HTI - Tarjeta de Débito',' Tarjetas Scotiabank',' Tarjetas American Express', 'HTI - Tarjeta American Express') THEN 'TARJETAS' WHEN v.den IN (' Tarjeta EfectiCard',' Tarjetas Sodexo (Pluxee)',' SMARTBT - SODEXO WIZEO',' Vale Edenred',' Vale Sodexo','Mobil FleetPro', ' Tarjeta Inburgas', ' Tarjeta TicketCar', ' Tarjeta TicketCar +', ' Vale Efectivale', ' SMARTBT - EFECTIVALE', 'Ultra Gas', 'Tarjetas Sodexo (Pluxee)', ' Tarjeta EfectiCard +') THEN 'VALERAS' ELSE 'OTRO' END AS m1
INTO #ing FROM SG12.dbo.Valores v JOIN SG12.dbo.Ingresos i ON v.cod = i.codval WHERE i.fch BETWEEN @fi AND @ff;

SELECT t1.codgas, t1.nrotrn, t1.tiptrn, v.den, t1.mto, t12.mto mto_tar,
 ROW_NUMBER() OVER (PARTITION BY t1.codgas, t1.nrotrn ORDER BY t12.mto DESC) rn,
 CASE WHEN t2.tipval=3 THEN 'CREDITO' WHEN t2.tipval=4 THEN 'DEBITO'
      WHEN v.den IN (' Efectivo MN', ' DOLARES', ' Morralla MN', 'Transferencias') THEN 'EFECTIVO' WHEN v.den IN (' SMARTBT - MANUAL Bancarias',' SMARTBT - Bancarias',' Tarjetas Bancomer', ' SMARTBT - American Express', ' Tarjetas Santander', ' Tarjetas Banorte', ' Tarjetas Afirme', 'SMARTBT - MANUAL Bancarias', 'INTERL - Tarjeta de Crédito', 'INTERL - Tarjeta de Débito', 'INTERLOGIC Manual','HTI - Tarjeta de Crédito','HTI - Tarjeta de Débito',' Tarjetas Scotiabank',' Tarjetas American Express') THEN 'TARJETAS' WHEN v.den IN (' Tarjeta EfectiCard',' Tarjetas Sodexo (Pluxee)',' SMARTBT - SODEXO WIZEO',' Vale Edenred',' Vale Sodexo','Mobil FleetPro', ' Tarjeta Inburgas', ' Tarjeta TicketCar', ' Tarjeta TicketCar +', ' Vale Efectivale', ' SMARTBT - EFECTIVALE', 'Ultra Gas', 'Tarjetas Sodexo (Pluxee)', ' Tarjeta EfectiCard +') THEN 'VALERAS'
      WHEN v.den IS NULL AND t1.tiptrn IN (0,49) THEN 'EFECTIVO' ELSE 'OTRO' END AS m0,
 CASE WHEN t1.tiptrn=74 THEN 'JARREO' WHEN t2.tipval=3 THEN 'CREDITO' WHEN t2.tipval=4 THEN 'DEBITO'
      WHEN v.den IN (' Efectivo MN', ' DOLARES', ' Morralla MN', 'Transferencias', 'HTI - Efectivo', 'INTERL - Efectivo') THEN 'EFECTIVO' WHEN v.den IN (' SMARTBT - MANUAL Bancarias',' SMARTBT - Bancarias',' Tarjetas Bancomer', ' SMARTBT - American Express', ' Tarjetas Santander', ' Tarjetas Banorte', ' Tarjetas Afirme', 'SMARTBT - MANUAL Bancarias', 'INTERL - Tarjeta de Crédito', 'INTERL - Tarjeta de Débito', 'INTERLOGIC Manual','HTI - Tarjeta de Crédito','HTI - Tarjeta de Débito',' Tarjetas Scotiabank',' Tarjetas American Express', 'HTI - Tarjeta American Express') THEN 'TARJETAS' WHEN v.den IN (' Tarjeta EfectiCard',' Tarjetas Sodexo (Pluxee)',' SMARTBT - SODEXO WIZEO',' Vale Edenred',' Vale Sodexo','Mobil FleetPro', ' Tarjeta Inburgas', ' Tarjeta TicketCar', ' Tarjeta TicketCar +', ' Vale Efectivale', ' SMARTBT - EFECTIVALE', 'Ultra Gas', 'Tarjetas Sodexo (Pluxee)', ' Tarjeta EfectiCard +') THEN 'VALERAS'
      WHEN t1.tiptrn IN (51,52) THEN 'TARJETAS' WHEN t1.tiptrn=53 THEN 'VALERAS'
      WHEN t1.tiptrn IN (0,49) THEN 'EFECTIVO' ELSE 'OTRO' END AS m1
INTO #des FROM SG12.dbo.Despachos t1
LEFT JOIN SG12.dbo.Clientes t2 ON t1.codcli=t2.cod
LEFT JOIN SG12.dbo.MovimientosTar t12 ON t1.nrotrn=t12.nrotrn AND t1.codgas=t12.codgas AND t12.mto!=0
LEFT JOIN SG12.dbo.Valores v ON t12.codbco=v.cod
WHERE t1.fchtrn BETWEEN @fi AND @ff AND t1.mto>0;

-- Resumen por estación y medio: actual (m0, con duplicados) y corregido (m1, 1 fila por despacho, sin jarreo)
WITH meds AS (SELECT m FROM (VALUES('CREDITO'),('DEBITO'),('EFECTIVO'),('TARJETAS'),('VALERAS'),('OTRO')) x(m)),
 est AS (SELECT DISTINCT codgas FROM #ing UNION SELECT DISTINCT codgas FROM #des),
 I0 AS (SELECT codgas, m0 m, SUM(mto) s FROM #ing GROUP BY codgas, m0),
 I1 AS (SELECT codgas, m1 m, SUM(mto) s FROM #ing GROUP BY codgas, m1),
 D0 AS (SELECT codgas, m0 m, SUM(mto) s, COUNT(*) n FROM #des GROUP BY codgas, m0),
 D1 AS (SELECT codgas, m1 m, SUM(mto) s, COUNT(*) n FROM #des WHERE rn=1 AND m1<>'JARREO' GROUP BY codgas, m1)
SELECT e.codgas, meds.m,
 ISNULL(I0.s,0) i0, ISNULL(D0.s,0) d0, ISNULL(D0.n,0) n0,
 ISNULL(I1.s,0) i1, ISNULL(D1.s,0) d1, ISNULL(D1.n,0) n1
INTO #r FROM est e CROSS JOIN meds
LEFT JOIN I0 ON I0.codgas=e.codgas AND I0.m=meds.m LEFT JOIN D0 ON D0.codgas=e.codgas AND D0.m=meds.m
LEFT JOIN I1 ON I1.codgas=e.codgas AND I1.m=meds.m LEFT JOIN D1 ON D1.codgas=e.codgas AND D1.m=meds.m;

PRINT '=== R1 resumen por estacion';
SELECT e.codgas, es.Nombre,
 CAST(SUM(i0) AS DECIMAL(14,0)) ing, CAST(SUM(d0) AS DECIMAL(14,0)) des, CAST(100*(SUM(d0)-SUM(i0))/NULLIF(SUM(i0),0) AS DECIMAL(6,2)) pct_tot,
 SUM(n0) n_actual, SUM(n1) n_corr,
 CAST(100*SUM(ABS(d0-i0))/2/NULLIF(SUM(i0),0) AS DECIMAL(6,1)) malclas_actual,
 CAST(100*SUM(ABS(d1-i1))/2/NULLIF(SUM(i1),0) AS DECIMAL(6,1)) malclas_corr,
 CAST(SUM(CASE WHEN m='CREDITO' THEN d1-i1 END) AS DECIMAL(14,0)) cred,
 CAST(SUM(CASE WHEN m='DEBITO' THEN d1-i1 END) AS DECIMAL(14,0)) deb,
 CAST(SUM(CASE WHEN m='EFECTIVO' THEN d1-i1 END) AS DECIMAL(14,0)) efec,
 CAST(SUM(CASE WHEN m='TARJETAS' THEN d1-i1 END) AS DECIMAL(14,0)) tarj,
 CAST(SUM(CASE WHEN m='VALERAS' THEN d1-i1 END) AS DECIMAL(14,0)) valer,
 CAST(SUM(CASE WHEN m='OTRO' THEN d1-i1 END) AS DECIMAL(14,0)) otro
FROM #r e LEFT JOIN TG.dbo.Estaciones es ON e.codgas=es.Codigo
GROUP BY e.codgas, es.Nombre ORDER BY malclas_corr DESC;

PRINT '=== R2 factores por estacion';
SELECT d.codgas,
 SUM(CASE WHEN d.den='HTI - Efectivo' OR d.den='INTERL - Efectivo' THEN 1 ELSE 0 END) n_hti_efe,
 CAST(SUM(CASE WHEN d.den IN ('HTI - Efectivo','INTERL - Efectivo') THEN d.mto ELSE 0 END) AS DECIMAL(14,0)) m_hti_efe,
 SUM(CASE WHEN d.den IS NULL AND d.tiptrn IN (51,52,53) AND d.m0 NOT IN ('CREDITO','DEBITO') THEN 1 ELSE 0 END) n_tip_sinval,
 SUM(CASE WHEN d.tiptrn=74 THEN 1 ELSE 0 END) n_jarreo,
 SUM(CASE WHEN d.rn>1 THEN 1 ELSE 0 END) n_dup,
 CAST(SUM(CASE WHEN d.den=' DOLARES' AND d.rn=1 THEN d.mto ELSE 0 END) AS DECIMAL(14,0)) usd_desp
FROM #des d GROUP BY d.codgas ORDER BY d.codgas;

PRINT '=== R3 valores en Ingresos que NO aparecen en MovimientosTar de la estacion (no integrados), >10k';
SELECT i.codgas, i.den, i.m1, CAST(SUM(i.mto) AS DECIMAL(14,0)) mto
FROM #ing i
WHERE i.m1 IN ('TARJETAS','VALERAS','OTRO') OR i.den='Transferencias'
GROUP BY i.codgas, i.den, i.m1
HAVING NOT EXISTS (SELECT 1 FROM #des d WHERE d.codgas=i.codgas AND d.den=i.den) AND SUM(i.mto) > 10000
ORDER BY i.codgas, mto DESC;

PRINT '=== R4 dolares ingresos';
SELECT codgas, CAST(SUM(mto) AS DECIMAL(14,0)) usd_ing FROM #ing WHERE den=' DOLARES' GROUP BY codgas;

PRINT '=== R5 clientes tipval 3/4 con tiptrn 0 (posible mal capturado), >20k';
SELECT t1.codgas, t2.den, t2.tipval, COUNT(*) n, CAST(SUM(t1.mto) AS DECIMAL(14,0)) m
FROM SG12.dbo.Despachos t1 JOIN SG12.dbo.Clientes t2 ON t1.codcli=t2.cod
WHERE t1.fchtrn BETWEEN @fi AND @ff AND t1.mto>0 AND t2.tipval IN (3,4) AND t2.den LIKE '%NO UTILIZAR%'
GROUP BY t1.codgas, t2.den, t2.tipval ORDER BY m DESC;
