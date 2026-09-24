-- ============================================================
-- Despachos pagados con TARJETA bancaria (débito / crédito) · todas las estaciones
-- Débito/Crédito se decide por tiptrn (51=Crédito, 52=Débito), igual que
-- DespachosModel.php (tipo_pago_despacho). v.den solo se usa si tiptrn no lo dice.
-- ============================================================
-- El DROP va en su propio lote (GO): si #tar ya existe de una corrida anterior
-- con otras columnas, SSMS compila el lote contra esa versión y marca
-- "Invalid column name" antes de llegar al DROP.
IF OBJECT_ID('tempdb..#tar') IS NOT NULL
    DROP TABLE #tar;
GO

DECLARE @desde DATE = '2026-01-01';
DECLARE @hasta DATE = '2026-12-31';

DECLARE @fi INT = DATEDIFF(dd, 0, @desde) + 1;
DECLARE @ff INT = DATEDIFF(dd, 0, @hasta) + 1;

;WITH Mov AS (
    -- 1 fila por despacho (evita duplicar cuando se pagó con 2 tarjetas);
    -- se excluyen tipmov 86/97 como en DespachosModel
    SELECT codgas, nrotrn, MAX(codbco) AS codbco, MAX(tiptar) AS tiptar, SUM(mto) AS mto
    FROM SG12.dbo.MovimientosTar
    WHERE mto <> 0 AND tipmov NOT IN (86, 97)
      AND fchmov BETWEEN @fi - 1 AND @ff + 1
    GROUP BY codgas, nrotrn
)
SELECT
    t1.nrotrn,
    CONVERT(DATE, CAST(t1.fchtrn AS DATETIME) - 1) AS fecha,
    t1.mto AS monto_despacho,
    m.mto  AS monto_tarjeta,
    CASE
        WHEN t1.tiptrn = 52 THEN 'DEBITO'
        WHEN t1.tiptrn = 51 THEN 'CREDITO'
        -- MovimientosTar.tiptar, solo en terminales integradas (SMARTBT/HTI/INTERL):
        --   SMARTBT: 67=Crédito, 68=Débito · HTI/INTERL: 51=Crédito, 52=Débito
        WHEN v.den LIKE '%SMARTBT%' AND m.tiptar = 68 THEN 'DEBITO'
        WHEN v.den LIKE '%SMARTBT%' AND m.tiptar = 67 THEN 'CREDITO'
        WHEN v.den LIKE '%D_bito%'  THEN 'DEBITO'
        WHEN v.den LIKE '%Cr_dito%' OR v.den LIKE '%American Express%' THEN 'CREDITO'
        -- Terminales propias del banco (Tarjetas Santander/Banorte/Bancomer/Afirme/Scotiabank):
        -- el despacho queda como contado y tiptar viene fijo (68 o 102), no dice débito/crédito.
        -- Decisión (2026-09-23): se cuentan como DEBITO.
        ELSE 'DEBITO'
    END AS tipo_tarjeta,
    ISNULL(v.den, '(sin MovimientosTar)') AS valor,
    t1.tiptrn,
    t1.codgas AS numero,
    g.abr     AS nombre
INTO #tar
FROM SG12.dbo.Despachos t1
LEFT JOIN Mov m                ON m.nrotrn = t1.nrotrn AND m.codgas = t1.codgas
LEFT JOIN SG12.dbo.Valores v   ON v.cod = m.codbco
LEFT JOIN SG12.dbo.Clientes c  ON c.cod = t1.codcli
LEFT JOIN SG12.dbo.Gasolineras g ON g.cod = t1.codgas
WHERE t1.fchtrn BETWEEN @fi AND @ff
  AND t1.mto > 0
  AND t1.tiptrn <> 74                          -- jarreos
  AND ISNULL(c.tipval, 0) NOT IN (3, 4)        -- fuera clientes crédito/débito de cuenta corriente
  AND (
        t1.tiptrn IN (51, 52)                  -- tarjeta según el despacho (todas las terminales)
        OR v.den IN (                          -- o voucher de tarjeta ligado aunque tiptrn diga otra cosa
            'HTI - Tarjeta de Débito', 'HTI - Tarjeta de Crédito', 'HTI - Tarjeta American Express',
            ' SMARTBT - Bancarias', ' SMARTBT - MANUAL Bancarias', 'SMARTBT - MANUAL Bancarias', ' SMARTBT - American Express',
            'INTERL - Tarjeta de Crédito', 'INTERL - Tarjeta de Débito', 'INTERLOGIC Manual',
            ' Tarjetas Bancomer', ' Tarjetas Santander', ' Tarjetas Banorte', ' Tarjetas Afirme',
            ' Tarjetas Scotiabank', ' Tarjetas American Express')
      );

-- ============================================================
-- RESUMEN
-- ============================================================
SELECT
    YEAR(fecha)  AS anio,
    MONTH(fecha) AS mes,
    numero,
    nombre,
    tipo_tarjeta,
    COUNT(*)            AS transacciones,
    SUM(monto_despacho) AS monto
FROM #tar
GROUP BY YEAR(fecha), MONTH(fecha), numero, nombre, tipo_tarjeta
ORDER BY YEAR(fecha), MONTH(fecha), numero, tipo_tarjeta;

-- Control: tarjetas de terminal bancaria propia que se asumieron como DEBITO
SELECT valor, tiptrn, COUNT(*) AS n, SUM(monto_despacho) AS monto
FROM #tar WHERE tipo_tarjeta = 'DEBITO' AND tiptrn NOT IN (51, 52)
  AND valor NOT LIKE '%SMARTBT%' AND valor NOT LIKE 'HTI%' AND valor NOT LIKE 'INTERL%'
GROUP BY valor, tiptrn ORDER BY monto DESC;
