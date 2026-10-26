-- TGV2.dbo.ClientesTG6: datos propios de TG6 por cliente (zona, asesor, fecha de ingreso).
-- Nombre, RFC y demás datos del cliente se leen de SG12.dbo.Clientes. Reemplaza el uso de TGV2.dbo.Clientes (foto de 2025, sin sincronización).
-- Idempotente: se puede volver a correr.
SET NOCOUNT ON;

IF OBJECT_ID('TGV2.dbo.ClientesTG6') IS NULL
    CREATE TABLE TGV2.dbo.ClientesTG6 (
        cod           INT      NOT NULL CONSTRAINT PK_ClientesTG6 PRIMARY KEY,
        zona_id       INT      NULL,
        asesor_id     INT      NULL,
        fecha_ingreso DATE     NULL,   -- primer consumo (MIN fchtrn de SG12.Despachos), se guarda una sola vez
        fecha_alta    DATETIME NOT NULL CONSTRAINT DF_ClientesTG6_alta DEFAULT GETDATE()
    );

BEGIN TRAN;

-- 1) Lo propio que ya existía en TGV2.Clientes
INSERT INTO TGV2.dbo.ClientesTG6 (cod, zona_id, asesor_id, fecha_ingreso)
SELECT c.cod, c.zona_id, c.asesor_id, c.fecha_ingreso
FROM TGV2.dbo.Clientes c
WHERE (c.zona_id IS NOT NULL OR c.asesor_id IS NOT NULL OR c.fecha_ingreso IS NOT NULL)
  AND NOT EXISTS (SELECT 1 FROM TGV2.dbo.ClientesTG6 t WHERE t.cod = c.cod);

-- 2) Clientes de TG6 que todavía no tienen fila (clientes nuevos desde la última copia)
INSERT INTO TGV2.dbo.ClientesTG6 (cod)
SELECT DISTINCT m.cliente_id
FROM TGV2.dbo.ConsumoCreditoDebitoMensual m
WHERE NOT EXISTS (SELECT 1 FROM TGV2.dbo.ClientesTG6 t WHERE t.cod = m.cliente_id);

-- 3) Primer consumo de los que aún no tienen fecha_ingreso
UPDATE t SET t.fecha_ingreso = DATEADD(dd, x.f - 1, 0)
FROM TGV2.dbo.ClientesTG6 t
JOIN (SELECT d.codcli, MIN(d.fchtrn) AS f
      FROM SG12.dbo.Despachos d (NOLOCK)
      WHERE d.codcli IN (SELECT cod FROM TGV2.dbo.ClientesTG6 WHERE fecha_ingreso IS NULL)
      GROUP BY d.codcli) x ON x.codcli = t.cod
WHERE t.fecha_ingreso IS NULL;

COMMIT;

SELECT COUNT(*) AS filas,
       SUM(CASE WHEN zona_id IS NOT NULL THEN 1 ELSE 0 END) AS con_zona,
       SUM(CASE WHEN asesor_id IS NOT NULL THEN 1 ELSE 0 END) AS con_asesor,
       SUM(CASE WHEN fecha_ingreso IS NOT NULL THEN 1 ELSE 0 END) AS con_fecha_ingreso
FROM TGV2.dbo.ClientesTG6;

SELECT COUNT(*) AS clientes_tg6,
       SUM(CASE WHEN t.cod IS NULL THEN 1 ELSE 0 END) AS sin_fila
FROM (SELECT DISTINCT cliente_id FROM TGV2.dbo.ConsumoCreditoDebitoMensual) m
LEFT JOIN TGV2.dbo.ClientesTG6 t ON t.cod = m.cliente_id;
