-- Villa Ahumada (31) · búsqueda de patrón en despachos contado sin voucher vs cobros no integrados por turno/isla (agosto 2026)
-- Parte 1: perfil de campos por grupo (Lerdo=efectivo puro, VA_efe, VA_mix)
SET NOCOUNT ON; SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED;
DECLARE @fi INT = DATEDIFF(dd,0,'2026-08-01')+1, @ff INT = DATEDIFF(dd,0,'2026-08-31')+1;
IF OBJECT_ID('tempdb..#i') IS NOT NULL DROP TABLE #i;
IF OBJECT_ID('tempdb..#u') IS NOT NULL DROP TABLE #u;
-- share no integrado por turno/isla en VA
SELECT i.fch, i.nrotur, i.codisl,
  SUM(CASE WHEN v.den IN (' Tarjetas Santander',' Tarjetas Banorte',' SMARTBT - MANUAL Bancarias',' Tarjeta EfectiCard',' Tarjeta TicketCar',' Tarjeta TicketCar +','Ultra Gas',' Vale Efectivale',' Tarjetas Sodexo (Pluxee)') THEN i.mto ELSE 0 END) noint,
  SUM(CASE WHEN v.den IN (' Efectivo MN','Transferencias') THEN i.mto ELSE 0 END) efe
INTO #i FROM SG12.dbo.Ingresos i JOIN SG12.dbo.Valores v ON v.cod=i.codval
WHERE i.codgas=31 AND i.fch BETWEEN @fi AND @ff GROUP BY i.fch,i.nrotur,i.codisl;

SELECT t1.*,
  CASE WHEN t1.codgas=5 THEN 'L'
       WHEN ISNULL(i.noint,0)=0 THEN 'VA_efe'
       WHEN i.noint/(i.noint+i.efe) > 0.5 THEN 'VA_mix'
       ELSE 'VA_otro' END grp
INTO #u
FROM SG12.dbo.Despachos t1
LEFT JOIN SG12.dbo.Clientes c ON c.cod=t1.codcli
LEFT JOIN #i i ON i.fch=t1.fchtrn AND i.nrotur=t1.nrotur AND i.codisl=t1.codisl
WHERE t1.codgas IN (5,31) AND t1.fchtrn BETWEEN @fi AND @ff AND t1.mto>0 AND t1.tiptrn<>74
 AND ISNULL(c.tipval,0) NOT IN (3,4)
 AND NOT EXISTS (SELECT 1 FROM SG12.dbo.MovimientosTar m WHERE m.nrotrn=t1.nrotrn AND m.codgas=t1.codgas AND m.mto<>0 AND m.tipmov NOT IN (86,97));

SELECT grp, COUNT(*) n, CAST(SUM(mto) AS DECIMAL(14,0)) mto, CAST(AVG(mto) AS DECIMAL(10,0)) ticket FROM #u GROUP BY grp;

-- % de despachos por grupo con cada flag
SELECT grp,
 CAST(100.0*AVG(CASE WHEN tiptrn=49 THEN 1.0 ELSE 0 END) AS DECIMAL(5,1)) tip49,
 CAST(100.0*AVG(CASE WHEN ISNULL(codcli,0)<>0 THEN 1.0 ELSE 0 END) AS DECIMAL(5,1)) codcli,
 CAST(100.0*AVG(CASE WHEN ISNULL(nroveh,0)<>0 THEN 1.0 ELSE 0 END) AS DECIMAL(5,1)) nroveh,
 CAST(100.0*AVG(CASE WHEN ISNULL(tar,0)<>0 THEN 1.0 ELSE 0 END) AS DECIMAL(5,1)) tar,
 CAST(100.0*AVG(CASE WHEN ISNULL(odm,0)<>0 THEN 1.0 ELSE 0 END) AS DECIMAL(5,1)) odm,
 CAST(100.0*AVG(CASE WHEN ISNULL(nrocte,0)<>0 THEN 1.0 ELSE 0 END) AS DECIMAL(5,1)) nrocte,
 CAST(100.0*AVG(CASE WHEN ISNULL(mtogto,0)<>0 THEN 1.0 ELSE 0 END) AS DECIMAL(5,1)) mtogto,
 CAST(100.0*AVG(CASE WHEN ISNULL(rut,'')<>'' THEN 1.0 ELSE 0 END) AS DECIMAL(5,1)) rut,
 CAST(100.0*AVG(CASE WHEN ISNULL(cho,'')<>'' THEN 1.0 ELSE 0 END) AS DECIMAL(5,1)) cho,
 CAST(100.0*AVG(CASE WHEN ISNULL(pto,0)<>0 THEN 1.0 ELSE 0 END) AS DECIMAL(5,1)) pto,
 CAST(100.0*AVG(CASE WHEN ISNULL(codres,0)<>0 THEN 1.0 ELSE 0 END) AS DECIMAL(5,1)) codres,
 CAST(100.0*AVG(CASE WHEN ISNULL(nroarc,0)<>0 THEN 1.0 ELSE 0 END) AS DECIMAL(5,1)) nroarc,
 CAST(100.0*AVG(CASE WHEN ISNULL(nrofac,0)<>0 THEN 1.0 ELSE 0 END) AS DECIMAL(5,1)) nrofac,
 CAST(100.0*AVG(CASE WHEN ISNULL(gasfac,0)<>0 THEN 1.0 ELSE 0 END) AS DECIMAL(5,1)) gasfac,
 CAST(100.0*AVG(CASE WHEN ISNULL(nroedc,0)<>0 THEN 1.0 ELSE 0 END) AS DECIMAL(5,1)) nroedc,
 CAST(100.0*AVG(CASE WHEN ISNULL(chkedc,0)<>0 THEN 1.0 ELSE 0 END) AS DECIMAL(5,1)) chkedc,
 CAST(100.0*AVG(CASE WHEN ISNULL(niv,0)<>0 THEN 1.0 ELSE 0 END) AS DECIMAL(5,1)) niv,
 CAST(100.0*AVG(CASE WHEN ISNULL(nrocho,0)<>0 THEN 1.0 ELSE 0 END) AS DECIMAL(5,1)) nrocho,
 CAST(100.0*AVG(CASE WHEN ISNULL(datref,'')<>'' THEN 1.0 ELSE 0 END) AS DECIMAL(5,1)) datref,
 CAST(100.0*AVG(CASE WHEN ISNULL(satuid,'')<>'' THEN 1.0 ELSE 0 END) AS DECIMAL(5,1)) satuid,
 CAST(100.0*AVG(CASE WHEN ISNULL(hstden,'')<>'' THEN 1.0 ELSE 0 END) AS DECIMAL(5,1)) hstden,
 CAST(100.0*AVG(CASE WHEN logexp IS NOT NULL THEN 1.0 ELSE 0 END) AS DECIMAL(5,1)) logexp,
 CAST(100.0*AVG(CASE WHEN ABS(mto-ROUND(mto,-1))<0.02 THEN 1.0 ELSE 0 END) AS DECIMAL(5,1)) mto_redondo10,
 CAST(100.0*AVG(CASE WHEN ABS(mto-ROUND(mto,-2))<0.02 THEN 1.0 ELSE 0 END) AS DECIMAL(5,1)) mto_redondo100
FROM #u GROUP BY grp;
PRINT '== logmsk'; SELECT grp, logmsk, COUNT(*) n FROM #u GROUP BY grp, logmsk HAVING COUNT(*)>20 ORDER BY grp, n DESC;
PRINT '== graprd'; SELECT grp, graprd, COUNT(*) n FROM #u GROUP BY grp, graprd ORDER BY grp, n DESC;
PRINT '== datref muestra VA'; SELECT TOP 15 grp, tiptrn, mto, LEFT(datref,120) datref FROM #u WHERE codgas=31 AND ISNULL(datref,'')<>'' ORDER BY NEWID();
PRINT '== hstden muestra'; SELECT TOP 10 grp, LEFT(hstden,100) hstden, LEFT(hstlog,80) hstlog FROM #u WHERE ISNULL(hstden,'')<>'' ORDER BY NEWID();

-- Parte 2: correlación por turno/isla (volumen y proporciones)
GO
SET NOCOUNT ON; SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED;
DECLARE @fi INT = DATEDIFF(dd,0,'2026-08-01')+1, @ff INT = DATEDIFF(dd,0,'2026-08-31')+1;
IF OBJECT_ID('tempdb..#i') IS NOT NULL DROP TABLE #i;
IF OBJECT_ID('tempdb..#u') IS NOT NULL DROP TABLE #u;
-- share no integrado por turno/isla en VA
SELECT i.fch, i.nrotur, i.codisl,
  SUM(CASE WHEN v.den IN (' Tarjetas Santander',' Tarjetas Banorte',' SMARTBT - MANUAL Bancarias',' Tarjeta EfectiCard',' Tarjeta TicketCar',' Tarjeta TicketCar +','Ultra Gas',' Vale Efectivale',' Tarjetas Sodexo (Pluxee)') THEN i.mto ELSE 0 END) noint,
  SUM(CASE WHEN v.den IN (' Efectivo MN','Transferencias') THEN i.mto ELSE 0 END) efe
INTO #i FROM SG12.dbo.Ingresos i JOIN SG12.dbo.Valores v ON v.cod=i.codval
WHERE i.codgas=31 AND i.fch BETWEEN @fi AND @ff GROUP BY i.fch,i.nrotur,i.codisl;

SELECT t1.*,
  CASE WHEN t1.codgas=5 THEN 'L'
       WHEN ISNULL(i.noint,0)=0 THEN 'VA_efe'
       WHEN i.noint/(i.noint+i.efe) > 0.5 THEN 'VA_mix'
       ELSE 'VA_otro' END grp
INTO #u
FROM SG12.dbo.Despachos t1
LEFT JOIN SG12.dbo.Clientes c ON c.cod=t1.codcli
LEFT JOIN #i i ON i.fch=t1.fchtrn AND i.nrotur=t1.nrotur AND i.codisl=t1.codisl
WHERE t1.codgas = 31 AND t1.fchtrn BETWEEN @fi AND @ff AND t1.mto>0 AND t1.tiptrn<>74
 AND ISNULL(c.tipval,0) NOT IN (3,4)
 AND NOT EXISTS (SELECT 1 FROM SG12.dbo.MovimientosTar m WHERE m.nrotrn=t1.nrotrn AND m.codgas=t1.codgas AND m.mto<>0 AND m.tipmov NOT IN (86,97));

SELECT grp, COUNT(*) n, CAST(SUM(mto) AS DECIMAL(14,0)) mto, CAST(AVG(mto) AS DECIMAL(10,0)) ticket FROM #u GROUP BY grp;

IF OBJECT_ID('tempdb..#t') IS NOT NULL DROP TABLE #t;
SELECT i.fch, i.nrotur, i.codisl, i.noint, i.efe,
 SUM(u.mto) tot,
 SUM(CASE WHEN u.tiptrn=49 THEN u.mto ELSE 0 END) f_49,
 SUM(CASE WHEN u.tiptrn=0 THEN u.mto ELSE 0 END) f_0,
 SUM(CASE WHEN ISNULL(u.nrocte,0)<>0 THEN u.mto ELSE 0 END) f_nrocte,
 SUM(CASE WHEN u.logmsk=0 THEN u.mto ELSE 0 END) f_logmsk0,
 SUM(CASE WHEN ISNULL(u.codcli,0)=0 THEN u.mto ELSE 0 END) f_sincli,
 SUM(CASE WHEN ISNULL(u.nrofac,0)=0 THEN u.mto ELSE 0 END) f_sinfac,
 SUM(CASE WHEN u.graprd=51 THEN u.mto ELSE 0 END) f_gra51,
 SUM(CASE WHEN ABS(u.mto-ROUND(u.mto,-1))>=0.02 THEN u.mto ELSE 0 END) f_noredondo,
 SUM(CASE WHEN u.mto>=1000 THEN u.mto ELSE 0 END) f_mayor1000
INTO #t FROM #i i JOIN #u u ON u.fchtrn=i.fch AND u.nrotur=i.nrotur AND u.codisl=i.codisl
GROUP BY i.fch,i.nrotur,i.codisl,i.noint,i.efe;
PRINT '== correlacion de proporciones (controla volumen)';
SELECT f, CAST((AVG(x*y)-AVG(x)*AVG(y))/NULLIF(STDEVP(x)*STDEVP(y),0) AS DECIMAL(4,2)) r
FROM (SELECT noint/tot y, v.f, v.x/tot x FROM #t CROSS APPLY (VALUES ('tiptrn49',f_49),('tiptrn0',f_0),('nrocte',f_nrocte),('logmsk0',f_logmsk0),('sin_cliente',f_sincli),('sin_factura',f_sinfac),('graprd51',f_gra51),('no_redondo',f_noredondo),('mto>=1000',f_mayor1000)) v(f,x) WHERE tot>0) z
GROUP BY f ORDER BY r DESC;
PRINT '== cruce tiptrn49 x nrocte';
SELECT tiptrn, CASE WHEN ISNULL(nrocte,0)<>0 THEN 'nrocte' ELSE '-' END c, grp, COUNT(*) n, CAST(AVG(mto) AS INT) ticket FROM #u WHERE tiptrn IN (0,49) GROUP BY tiptrn, CASE WHEN ISNULL(nrocte,0)<>0 THEN 'nrocte' ELSE '-' END, grp ORDER BY 1,2,3;
PRINT '== turnos/isla SIN no integrados: cuanto 49 hay?';
SELECT CASE WHEN noint=0 THEN 'sin noint' ELSE 'con noint' END g, COUNT(*) turnos, CAST(SUM(f_49) AS INT) m49, CAST(SUM(noint) AS INT) noint, CAST(SUM(tot) AS INT) tot FROM #t GROUP BY CASE WHEN noint=0 THEN 'sin noint' ELSE 'con noint' END;
