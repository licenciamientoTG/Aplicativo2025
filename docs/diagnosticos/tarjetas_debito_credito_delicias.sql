-- ============================================================
-- Delicias (codgas 19) · Despachos pagados con TARJETA bancaria de DÉBITO / CRÉDITO
-- Fuente del medio de pago: MovimientosTar -> Valores (terminal HTI integrada).
-- Se incluyen también despachos tiptrn 51/52 que no tienen MovimientosTar (tarjeta sin voucher ligado).
-- OJO: esto NO es "Clientes Crédito/Débito" (cuentas corrientes); son tarjetas de banco.
-- ============================================================
DECLARE @codgas INT  = 19;
DECLARE @desde  DATE = '2026-08-01';
DECLARE @hasta  DATE = '2026-08-31';
DECLARE @fi INT = DATEDIFF(dd, 0, @desde) + 1;
DECLARE @ff INT = DATEDIFF(dd, 0, @hasta) + 1;

IF OBJECT_ID('tempdb..#tar') IS NOT NULL DROP TABLE #tar;

SELECT
    t1.nrotrn,
    CONVERT(DATE, CAST(t1.fchtrn AS DATETIME) - 1)            AS fecha,
    t1.hratrn                                                 AS hora,       -- HHMM
    t1.nrotur                                                 AS turno,
    t1.codisl                                                 AS isla,
    t1.nrobom                                                 AS bomba,
    p.den                                                     AS producto,
    t1.can                                                    AS litros,
    t1.pre                                                    AS precio,
    t1.mto                                                    AS monto_despacho,
    m.mto                                                     AS monto_tarjeta,  -- lo cobrado en terminal (puede diferir si fue pago mixto)
    CASE
        WHEN v.den LIKE '%D_bito%'  OR (v.den IS NULL AND t1.tiptrn = 52) THEN 'DEBITO'
        WHEN v.den LIKE '%Cr_dito%' OR (v.den IS NULL AND t1.tiptrn = 51) THEN 'CREDITO'
        ELSE 'OTRA TARJETA'
    END                                                       AS tipo_tarjeta,
    ISNULL(v.den, '(sin MovimientosTar)')                     AS valor,
    t1.tiptrn,
    t1.codcli,
    c.den                                                     AS cliente,
    c.tipval                                                  AS cliente_tipval,  -- 3=Crédito, 4=Débito (cuenta corriente)
    m.nrotar                                                  AS nro_tarjeta,
    m.nroaut                                                  AS autorizacion,
    t1.satrfc                                                 AS rfc_factura
INTO #tar
FROM SG12.dbo.Despachos t1
LEFT JOIN SG12.dbo.MovimientosTar m ON m.nrotrn = t1.nrotrn AND m.codgas = t1.codgas AND m.mto <> 0
LEFT JOIN SG12.dbo.Valores v        ON v.cod = m.codbco
LEFT JOIN SG12.dbo.Clientes c       ON c.cod = t1.codcli
LEFT JOIN SG12.dbo.Productos p      ON p.cod = t1.codprd
WHERE t1.codgas = @codgas
  AND t1.fchtrn BETWEEN @fi AND @ff
  AND t1.mto > 0
  AND ISNULL(c.tipval, 0) NOT IN (3, 4)                      -- fuera clientes de cuenta corriente
  AND (
        v.den IN ('HTI - Tarjeta de Débito', 'HTI - Tarjeta de Crédito', 'HTI - Tarjeta American Express',
                  ' SMARTBT - Bancarias', ' SMARTBT - MANUAL Bancarias', 'SMARTBT - MANUAL Bancarias', ' SMARTBT - American Express',
                  'INTERL - Tarjeta de Crédito', 'INTERL - Tarjeta de Débito', 'INTERLOGIC Manual',
                  ' Tarjetas Bancomer', ' Tarjetas Santander', ' Tarjetas Banorte', ' Tarjetas Afirme',
                  ' Tarjetas Scotiabank', ' Tarjetas American Express')
        OR (m.nrotrn IS NULL AND t1.tiptrn IN (51, 52))
      );

-- 1) Detalle
SELECT * FROM #tar ORDER BY fecha, hora;

-- 2) Resumen por tipo de tarjeta
SELECT tipo_tarjeta, valor,
       COUNT(DISTINCT nrotrn) AS transacciones,
       SUM(litros)            AS litros,
       SUM(monto_despacho)    AS monto_despacho,
       SUM(monto_tarjeta)     AS monto_tarjeta
FROM #tar
GROUP BY tipo_tarjeta, valor
ORDER BY tipo_tarjeta, monto_despacho DESC;

-- 3) Resumen por cliente (quién paga con tarjeta; la mayoría será público en general)
SELECT codcli, cliente, tipo_tarjeta,
       COUNT(DISTINCT nrotrn) AS transacciones,
       SUM(litros)            AS litros,
       SUM(monto_despacho)    AS monto
FROM #tar
GROUP BY codcli, cliente, tipo_tarjeta
ORDER BY monto DESC;
