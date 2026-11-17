-- docs/sql/credit_clients_cierre_retro.sql
-- Foto crédito: ajuste retro (2026-11-15). El saldo calculado de crédito va
-- encadenado y no veía lo que llega con fecha atrasada (cobros capturados
-- después de la foto, despachos quitados, cortes tardíos). Cada fila guarda
-- ahora lo acumulado hasta su día (acum_*) y la corrida siguiente lo compara
-- contra el histórico de hoy: la diferencia va a ajuste_retro_* y corrige el
-- saldo inicial calculado (CreditCierre::ajustesRetro).
-- 1) columnas; 2) carga de acum_* en las filas existentes, tal como la cadena
-- las vio (para que la corrida del 15-nov detecte los atrasados que ya hay).
USE TG;
GO

IF COL_LENGTH('dbo.credit_clients_cierre', 'ajuste_retro_desp') IS NULL
    ALTER TABLE dbo.credit_clients_cierre ADD
        ajuste_retro_desp   DECIMAL(18,2) NULL,  -- efecto en el saldo calc. (desp.) de lo atrasado: despachos - abonos
        ajuste_retro_cortes DECIMAL(18,2) NULL,  -- ídem con cortes
        acum_desp           DECIMAL(18,4) NULL,  -- despachos con fecha <= fecha_desde, como se conocían en la foto
        acum_cortes         DECIMAL(18,4) NULL,  -- cortes (ValesR codval 28) ídem
        acum_abonos         DECIMAL(18,4) NULL;  -- cobros + notas (MovimientosAnl tipope 4/6 tipmov 1) ídem
GO

SET NOCOUNT ON;
DROP TABLE IF EXISTS #u, #mv, #semilla, #h0, #dia, #acum;
SELECT DISTINCT codcli INTO #u FROM dbo.credit_clients_cierre WHERE acum_desp IS NULL;
CREATE UNIQUE CLUSTERED INDEX ix ON #u (codcli);

-- Importe por cliente y día (cada renglón a decimal antes de sumar, igual que el modelo)
CREATE TABLE #mv (codcli int, fch int, desp decimal(18,4), cortes decimal(18,4), abonos decimal(18,4));
INSERT #mv SELECT d.codcli, d.fchtrn, SUM(CAST(d.mto AS decimal(18,4))), 0, 0
  FROM SG12.dbo.Despachos d WITH (NOLOCK) JOIN #u ON #u.codcli = d.codcli GROUP BY d.codcli, d.fchtrn OPTION (RECOMPILE);
INSERT #mv SELECT r.codcli, r.fch, 0, SUM(CAST(r.mto AS decimal(18,4))), 0
  FROM SG12.dbo.ValesR r WITH (NOLOCK) JOIN #u ON #u.codcli = r.codcli WHERE r.codval = 28 GROUP BY r.codcli, r.fch OPTION (RECOMPILE);
INSERT #mv SELECT m.codopr, m.fchope, 0, 0, -SUM(CAST(m.mtoopeori AS decimal(18,4))) / 100
  FROM SG12.dbo.MovimientosAnl m WITH (NOLOCK) JOIN #u ON #u.codcli = m.codopr
 WHERE m.tipopr = 1 AND m.tipmov = 1 AND m.tipope IN (4, 6) GROUP BY m.codopr, m.fchope OPTION (RECOMPILE);
CREATE CLUSTERED INDEX ix ON #mv (codcli, fch);

-- Antes de la siembra de cada cliente: lo que hay hoy (no hay foto contra qué comparar)
SELECT c.codcli, DATEDIFF(day, '1899-12-31', MIN(c.fecha_desde)) seed_i
  INTO #semilla FROM dbo.credit_clients_cierre c JOIN #u ON #u.codcli = c.codcli GROUP BY c.codcli;
SELECT s.codcli, ISNULL(SUM(m.desp), 0) h_desp, ISNULL(SUM(m.cortes), 0) h_cortes, ISNULL(SUM(m.abonos), 0) h_abonos
  INTO #h0 FROM #semilla s LEFT JOIN #mv m ON m.codcli = s.codcli AND m.fch < s.seed_i GROUP BY s.codcli;

-- Días con foto: lo exacto de hoy si solo difiere por redondeo (< 1 centavo);
-- si no, lo que la foto vio en su momento (así lo atrasado queda como diferencia)
SELECT c.id, c.codcli, c.fecha_desde,
  c.consumos_desp_dia ch_desp, c.consumos_cortes_dia ch_cortes, c.cobros_dia + c.notas_dia ch_abonos,
  ISNULL(SUM(m.desp), 0) ex_desp, ISNULL(SUM(m.cortes), 0) ex_cortes, ISNULL(SUM(m.abonos), 0) ex_abonos
  INTO #dia FROM dbo.credit_clients_cierre c JOIN #u ON #u.codcli = c.codcli
  LEFT JOIN #mv m ON m.codcli = c.codcli AND m.fch = DATEDIFF(day, '1899-12-31', c.fecha_desde)
 GROUP BY c.id, c.codcli, c.fecha_desde, c.consumos_desp_dia, c.consumos_cortes_dia, c.cobros_dia, c.notas_dia;

SELECT d.id, d.codcli,
  h.h_desp + SUM(CASE WHEN ABS(d.ex_desp - d.ch_desp) < 0.01 THEN d.ex_desp ELSE d.ch_desp END) OVER (PARTITION BY d.codcli ORDER BY d.fecha_desde ROWS UNBOUNDED PRECEDING) acum_desp,
  h.h_cortes + SUM(CASE WHEN ABS(d.ex_cortes - d.ch_cortes) < 0.01 THEN d.ex_cortes ELSE d.ch_cortes END) OVER (PARTITION BY d.codcli ORDER BY d.fecha_desde ROWS UNBOUNDED PRECEDING) acum_cortes,
  h.h_abonos + SUM(CASE WHEN ABS(d.ex_abonos - d.ch_abonos) < 0.01 THEN d.ex_abonos ELSE d.ch_abonos END) OVER (PARTITION BY d.codcli ORDER BY d.fecha_desde ROWS UNBOUNDED PRECEDING) acum_abonos
  INTO #acum FROM #dia d JOIN #h0 h ON h.codcli = d.codcli;

-- Cobros con fecha ANTERIOR a la siembra (11-nov) capturados después de ella:
-- se vieron como desfase calc. vs sistema en la foto del 13-nov
-- (nroope 10901/10903 gas 24 y 3395 gas 29, mismo lote que los atrasados del 11-nov).
UPDATE #acum SET acum_abonos = acum_abonos - 7460.74             WHERE codcli = 1711397; -- INTERNATIONAL CARDBORD, cobro 03-nov
UPDATE #acum SET acum_abonos = acum_abonos - (846.36 + 3216.23)  WHERE codcli = 1714493; -- IKRALG, cobros 06-nov

BEGIN TRAN;
UPDATE c SET c.acum_desp = a.acum_desp, c.acum_cortes = a.acum_cortes, c.acum_abonos = a.acum_abonos
  FROM dbo.credit_clients_cierre c JOIN #acum a ON a.id = c.id
 WHERE c.acum_desp IS NULL;
SELECT @@ROWCOUNT AS filas_con_acumulado;
-- Filas encadenadas (no la de siembra): su inicial fue el final anterior, o sea ajuste 0.
-- Sin esto la corrida siguiente vería NULL vs 0 como cambio y abriría fila a todos.
UPDATE c SET c.ajuste_retro_desp = 0, c.ajuste_retro_cortes = 0
  FROM dbo.credit_clients_cierre c
 WHERE c.ajuste_retro_desp IS NULL
   AND EXISTS (SELECT 1 FROM dbo.credit_clients_cierre p WHERE p.codcli = c.codcli AND p.fecha_desde < c.fecha_desde);
SELECT @@ROWCOUNT AS filas_ajuste_cero;
COMMIT;
DROP TABLE IF EXISTS #u, #mv, #semilla, #h0, #dia, #acum;
GO
