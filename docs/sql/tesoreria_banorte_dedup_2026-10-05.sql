/*
 * Limpieza de duplicados BANORTE cuenta 0185322470, 01 y 02 de octubre 2026.
 *
 * Causa: "Cuentas de Cheques (6).csv" (subido 2026-10-02 15:48) traía las
 * columnas MOVIMIENTO y DESCRIPCIÓN DETALLADA invertidas; el parser las leía
 * por posición, el folio quedó en descripcion_larga, secuencia = NULL, y la
 * llave natural no pudo cruzar contra RIDIAZ_20261001/20261002.TXT → 175
 * movimientos duplicados. Cada fila del CSV tiene su gemela exacta en el TXT
 * (mismo folio = TRY_CAST(csv.descripcion_larga AS INT), fecha, importes y
 * saldo): verificado 175/175 el 2026-10-05.
 *
 * Se conserva la fila del TXT (folio y contraparte correctos). Pero la
 * conciliación EFC ya usó varias filas del CSV, así que ANTES de borrar se
 * re-apuntan sus referencias a la gemela del TXT:
 *   - efc_conc_correcciones_banco  (UNIQUE por movimiento; ningún par tiene
 *                                    corrección en ambas filas)
 *   - efc_conc_partidas            (UNIQUE activa por movimiento; ningún par
 *                                    tiene partida activa en ambas filas)
 *   - efc_conc_bitacora            (historial)
 * Conciliacion_V3_Detalles (mb_<id>) no tiene referencias a estas filas.
 *
 * Correr primero con ROLLBACK (dry-run), revisar conteos, luego con COMMIT.
 */
SET XACT_ABORT ON;
SET QUOTED_IDENTIFIER ON;   -- requerido por el índice filtrado UX_efc_conc_partida_banco_activa
BEGIN TRANSACTION;

IF OBJECT_ID('tempdb..#par') IS NOT NULL DROP TABLE #par;
SELECT c.id AS csv_id, t.id AS txt_id
INTO #par
FROM TG.dbo.movimientos_bancarios c
JOIN TG.dbo.movimientos_bancarios t
  ON  t.cuenta = c.cuenta AND t.fecha = c.fecha
  AND t.secuencia = TRY_CAST(c.descripcion_larga AS INT)
  AND ISNULL(t.cargo, 0) = ISNULL(c.cargo, 0)
  AND ISNULL(t.abono, 0) = ISNULL(c.abono, 0)
  AND t.saldo = c.saldo
  AND t.archivo_origen IN ('RIDIAZ_20261001.TXT', 'RIDIAZ_20261002.TXT')
WHERE c.archivo_origen = 'Cuentas de Cheques (6).csv'
  AND c.secuencia IS NULL
  AND c.cuenta = '0185322470';

IF (SELECT COUNT(*) FROM #par) <> 175
   OR (SELECT COUNT(DISTINCT csv_id) FROM #par) <> 175
   OR (SELECT COUNT(DISTINCT txt_id) FROM #par) <> 175
BEGIN
    RAISERROR('Se esperaban 175 pares 1:1 exactos — abortando', 16, 1);
    ROLLBACK; RETURN;
END

-- Conflictos que harían tronar los UNIQUE: deben ser 0
IF EXISTS (SELECT 1 FROM #par p
           JOIN TG.dbo.efc_conc_correcciones_banco a ON a.movimiento_bancario_id = p.csv_id
           JOIN TG.dbo.efc_conc_correcciones_banco b ON b.movimiento_bancario_id = p.txt_id)
   OR EXISTS (SELECT 1 FROM #par p
           JOIN TG.dbo.efc_conc_partidas a ON a.movimiento_bancario_id = p.csv_id AND a.activo = 1
           JOIN TG.dbo.efc_conc_partidas b ON b.movimiento_bancario_id = p.txt_id AND b.activo = 1)
BEGIN
    RAISERROR('Hay pares con referencia EFC en ambas filas — revisar a mano', 16, 1);
    ROLLBACK; RETURN;
END

UPDATE x SET movimiento_bancario_id = p.txt_id
FROM TG.dbo.efc_conc_correcciones_banco x JOIN #par p ON p.csv_id = x.movimiento_bancario_id;
PRINT CONCAT('correcciones re-apuntadas: ', @@ROWCOUNT);   -- esperado 71

UPDATE x SET movimiento_bancario_id = p.txt_id
FROM TG.dbo.efc_conc_partidas x JOIN #par p ON p.csv_id = x.movimiento_bancario_id;
PRINT CONCAT('partidas re-apuntadas: ', @@ROWCOUNT);       -- esperado 74

UPDATE x SET movimiento_bancario_id = p.txt_id
FROM TG.dbo.efc_conc_bitacora x JOIN #par p ON p.csv_id = x.movimiento_bancario_id;
PRINT CONCAT('bitácora re-apuntada: ', @@ROWCOUNT);        -- esperado 147

DELETE m FROM TG.dbo.movimientos_bancarios m JOIN #par p ON p.csv_id = m.id;
PRINT CONCAT('movimientos borrados: ', @@ROWCOUNT);        -- esperado 175

-- Verificación: ningún movimiento de la cuenta duplicado por folio en esos días
SELECT CONVERT(varchar(10), fecha, 23) AS fecha, COUNT(*) AS filas,
       COUNT(DISTINCT secuencia) AS folios, SUM(CASE WHEN secuencia IS NULL THEN 1 ELSE 0 END) AS sin_folio
FROM TG.dbo.movimientos_bancarios
WHERE cuenta = '0185322470' AND fecha IN ('2026-10-01', '2026-10-02')
GROUP BY fecha;   -- esperado 01-oct 83/83/0, 02-oct 96/96/0

ROLLBACK;   -- cambiar a COMMIT tras revisar el dry-run
