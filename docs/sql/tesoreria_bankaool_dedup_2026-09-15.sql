-- docs/sql/tesoreria_bankaool_dedup_2026-09-15.sql
-- Limpieza de duplicados BANKAOOL causados por huella distinta entre el
-- layout v1 y v2 del mismo banco (mismo movimiento real re-exportado en un
-- formato de reporte distinto: v1 describe el movimiento desde el banco
-- emisor y trae saldo; v2 lo describe desde la contraparte, con concepto
-- generico "Sin concepto"/"TRASPASOS ENTRE CUENTAS" y sin columna Saldo).
-- Como cada layout calculaba la huella con campos distintos (incluia
-- descripcion/concepto/saldo), el UNIQUE de huella no detectaba que era el
-- mismo movimiento y se duplicaba al resubir en el otro formato.
--
-- Se borra la copia v2 (saldo IS NULL) de cada par y se deja la v1 (con
-- saldo, que sostiene la cadena de saldos usada para el chequeo de cuadre).
-- Analisis y verificacion: ver memoria bankaool-huella-v1-v2-duplicados.
--
-- CORRER DESPUES de desplegar el fix de huella en
-- _assets/models/MovimientosBancariosModel.php (huella_bankaool()), para
-- que si algo se vuelve a resubir no se vuelva a duplicar.
--
-- 135 filas, fecha entre 2026-08-14 y 2026-09-03.
USE TG;
GO

BEGIN TRANSACTION;

DECLARE @ids TABLE (id INT PRIMARY KEY);
INSERT INTO @ids (id) VALUES
    (38306),(38307),(38308),(38309),(38310),(38311),(38312),(38313),(38314),(38315),
    (38316),(38317),(38318),(38319),(38320),(38321),(38322),(38323),(38324),(38325),
    (38326),(38327),(38328),(38373),(38374),(38375),(38376),(38377),(38378),(38379),
    (38380),(38381),(38382),(38383),(38384),(38385),(38386),(38387),(38388),(38389),
    (38390),(38391),(41596),(41597),(41598),(41599),(41600),(41601),(41602),(41603),
    (41604),(41605),(41606),(41607),(41608),(41609),(41610),(41611),(41612),(41613),
    (41614),(41615),(41616),(41617),(41618),(41619),(41620),(41621),(41622),(41623),
    (41624),(41625),(41626),(41627),(41628),(41629),(41630),(41631),(41632),(41633),
    (41634),(41635),(41636),(41637),(41638),(41639),(41640),(41641),(41642),(41643),
    (41644),(41645),(41646),(41648),(41649),(41650),(41651),(41652),(41653),(41654),
    (41655),(41656),(41657),(41658),(41659),(41660),(41661),(41662),(41663),(41664),
    (41665),(41666),(41667),(41668),(41669),(41670),(41671),(41672),(41673),(41674),
    (41675),(41676),(41677),(41678),(41679),(41680),(41681),(41682),(41683),(41684),
    (41685),(41686),(41687),(41688),(41689);

-- Verificacion antes de borrar: deben ser exactamente 135 filas, todas
-- BANKAOOL, todas con saldo NULL (la copia v2). Si algo no cuadra, aborta
-- sin tocar nada (alguien pudo haber borrado/editado algo desde el analisis).
DECLARE @n_antes INT, @n_no_bankaool INT, @n_con_saldo INT;
SELECT @n_antes = COUNT(*) FROM dbo.movimientos_bancarios m JOIN @ids i ON i.id = m.id;
SELECT @n_no_bankaool = COUNT(*) FROM dbo.movimientos_bancarios m JOIN @ids i ON i.id = m.id WHERE m.banco <> 'BANKAOOL';
SELECT @n_con_saldo = COUNT(*) FROM dbo.movimientos_bancarios m JOIN @ids i ON i.id = m.id WHERE m.saldo IS NOT NULL;

IF @n_antes <> 135 OR @n_no_bankaool <> 0 OR @n_con_saldo <> 0
BEGIN
    ROLLBACK TRANSACTION;
    RAISERROR('Verificacion previa fallo: antes=%d no_bankaool=%d con_saldo=%d (se esperaba 135, 0, 0). Abortado sin cambios.', 16, 1, @n_antes, @n_no_bankaool, @n_con_saldo);
    RETURN;
END

DELETE m
FROM dbo.movimientos_bancarios m
JOIN @ids i ON i.id = m.id;

PRINT CONCAT('Filas borradas: ', @@ROWCOUNT);

COMMIT TRANSACTION;
GO

-- Migracion de huellas existentes: las filas BANKAOOL que quedan (todas v1,
-- las que tenian par v2 ya se borraron arriba) tienen la huella VIEJA
-- (incluia descripcion/concepto/saldo). Se recalculan a la formula nueva
-- (cuenta|fecha|hora|referencia|monto firmado) para que si se resube un
-- archivo viejo en el otro layout, el dedup SI lo reconozca. Sin esto, un
-- reupload futuro volveria a duplicar porque la huella nueva del parser no
-- coincidiria con la vieja en BD (mismo patron que el fix de AFIRME
-- 2026-09-01, ver fix-huella-afirme-referencia).
BEGIN TRANSACTION;

DECLARE @afectadas INT;

-- CONVERT(VARCHAR(200), ...) alrededor de toda la cadena es obligatorio: sin
-- el, la concatenacion con columnas NVARCHAR (cuenta, referencia) hace que
-- SQL Server hashee bytes UTF-16LE en vez de los UTF-8 que produce sha1() en
-- PHP, y el hash sale distinto (verificado 2026-09-15: 0/851 coincidian sin
-- el CONVERT, 851/851 coinciden con el).
UPDATE dbo.movimientos_bancarios
SET huella = CONVERT(CHAR(40), HASHBYTES('SHA1', CONVERT(VARCHAR(200),
    'BANKAOOL|' + cuenta + '|' + CONVERT(VARCHAR(10), fecha, 23) + '|' + hora + '|'
    + LTRIM(RTRIM(ISNULL(referencia, ''))) + '|'
    + FORMAT(ISNULL(abono, 0) - ISNULL(cargo, 0), '0.00', 'en-US')
)), 2)
WHERE banco = 'BANKAOOL';

SET @afectadas = @@ROWCOUNT;
PRINT CONCAT('Huellas recalculadas: ', @afectadas);

-- Si la migracion produjera huellas duplicadas (no deberia: ya se verifico
-- que cuenta+fecha+hora+referencia+monto es unico salvo los pares ya
-- borrados), el UNIQUE de la tabla revienta el UPDATE y hace ROLLBACK
-- automatico de esta transaccion explicita al fallar el batch.
COMMIT TRANSACTION;
GO

-- Verificacion final: 0 huellas duplicadas y el conteo total bajo en 135.
SELECT COUNT(*) AS total_bankaool FROM dbo.movimientos_bancarios WHERE banco = 'BANKAOOL';
SELECT huella, COUNT(*) AS n FROM dbo.movimientos_bancarios WHERE banco = 'BANKAOOL' GROUP BY huella HAVING COUNT(*) > 1;
GO
