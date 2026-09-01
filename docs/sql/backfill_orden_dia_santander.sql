-- docs/sql/backfill_orden_dia_santander.sql
-- Llena orden_dia para los movimientos de SANTANDER ya existentes, en su
-- orden actual por id (correcto salvo los casos donde un archivo de origen
-- distinto se importó después para un día ya cargado — ver comentario de la
-- columna en tesoreria_schema.sql). Correr UNA vez después de agregar la
-- columna; re-ejecutarlo es seguro (vuelve a numerar 1..n en el mismo orden
-- si nada cambió).
USE TG;
GO

;WITH ordenado AS (
    SELECT id,
           ROW_NUMBER() OVER (PARTITION BY cuenta, fecha ORDER BY id) AS rn
    FROM dbo.movimientos_bancarios
    WHERE banco = 'SANTANDER'
)
UPDATE m
SET orden_dia = o.rn
FROM dbo.movimientos_bancarios m
JOIN ordenado o ON o.id = m.id;
GO

-- Corrige los 4 movimientos de "DEPOSITO SALVO BUEN COBRO" importados el
-- 2026-09-01 desde 20260901_MovimientosCheque.csv (parse_santander_chequeras_csv),
-- que quedaron con id posterior a movimientos del TXT diario de horas
-- posteriores en su mismo día — ver conversación 2026-09-01, cuenta
-- 65505339719 con 12 roturas falsas hasta corregir esto. Se renumera cada
-- uno de los 4 días afectados completo, por hora (el TXT va antes que el
-- CSV si empatan hora: el TXT es la fuente diaria de siempre, el CSV solo
-- rellena huecos). Validado contra BD real: 0 roturas de 134 transiciones
-- en el rango 2026-08-03 a 2026-08-31 con este orden.
;WITH dia AS (
    SELECT id, cuenta, fecha, hora, archivo_origen,
           ROW_NUMBER() OVER (PARTITION BY cuenta, fecha ORDER BY
               hora,
               CASE WHEN archivo_origen LIKE '%.csv' THEN 1 ELSE 0 END,
               id
           ) AS rn
    FROM dbo.movimientos_bancarios
    WHERE banco = 'SANTANDER'
      AND cuenta = '65505339719'
      AND fecha IN ('2026-08-06', '2026-08-14', '2026-08-19', '2026-08-27')
)
UPDATE m
SET orden_dia = d.rn
FROM dbo.movimientos_bancarios m
JOIN dia d ON d.id = m.id;
GO

-- Verificación: no debe haber NULLs en SANTANDER después de correr esto.
SELECT COUNT(*) AS pendientes
FROM dbo.movimientos_bancarios
WHERE banco = 'SANTANDER' AND orden_dia IS NULL;
GO
