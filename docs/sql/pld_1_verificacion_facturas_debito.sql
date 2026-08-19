-- PLD Prepago -- Query 1: Verificacion de facturas D (anticipos de clientes de debito).
--
-- Extrae, para el periodo indicado, cada documento de anticipo de cliente de debito
-- con el mismo criterio de negocio usado en DocumentosModel::get_anticipos_customer()
-- (_assets/models/DocumentosModel.php):
--   - t1.codprd NOT IN (1,2,3,-64,179,180,181,192,193)  -> excluye productos no-anticipo
--   - t1.mtoiva > 0 AND t1.mto > 100                    -> filtra "ruido" de montos chicos
--   - Clientes.tipval = 4                               -> SOLO clientes de debito
--
-- Trae el UUID fiscal (DocumentosC.satuid) de cada documento para poder conciliar
-- 1:1 contra la columna "UUID" de la hoja "Detalle CFDI" del tablero Excel.
-- Ya se verifico manualmente que este campo coincide exactamente con el UUID del
-- Excel (ejemplo: factura D 206 / CLARA / feb-2026 -> nro=1200000206, tip=3, codgas=26,
-- satuid=8082bfc2-7c87-4af5-8b54-a268e22d254f).
--
-- Ajustar @from / @until segun el periodo a verificar (ej. todo 2026, o un mes puntual).

USE [SG12];
GO

DECLARE @from  DATE = '2026-01-01';
DECLARE @until DATE = '2026-08-31';

SELECT
    g.abr                                          AS Entidad,
    c.cod                                           AS CodCliente,
    c.den                                            AS Cliente,
    c.rfc                                            AS RFC,
    CONVERT(date, CONVERT(datetime, t2.fch - 1))     AS Fecha,
    t1.nro                                           AS NroDocumento,
    t1.tip                                           AS TipoDocumento,
    t1.codgas                                        AS CodEstacion,
    (t1.mtoori / 100)                                AS Subtotal,
    (t1.mtoiva / 100)                                AS IVA,
    ((t1.mtoori + t1.mtoiva) / 100)                  AS MontoTotal,
    t2.satuid                                        AS UUID,
    t2.satser                                        AS SatSerie,
    t2.satnro                                        AS SatFolio
FROM
    [SG12].[dbo].[Documentos] t1 WITH (NOLOCK)
    LEFT JOIN [SG12].[dbo].[DocumentosC] t2 WITH (NOLOCK)
        ON t1.nro = t2.nro AND t1.codgas = t2.codgas AND t1.tip = t2.tip
    INNER JOIN [SG12].[dbo].[Clientes] c WITH (NOLOCK)
        ON t1.codopr = c.cod
    LEFT JOIN [SG12].[dbo].[Gasolineras] g WITH (NOLOCK)
        ON t1.codgas = g.cod
WHERE
    t1.codprd NOT IN (1, 2, 3, -64, 179, 180, 181, 192, 193)
    AND t1.mtoiva > 0
    AND t1.mto > 100
    AND c.tipval = 4
    AND t2.fch BETWEEN (DATEDIFF(day, 0, @from) + 1) AND (DATEDIFF(day, 0, @until) + 1)
ORDER BY
    c.den, t2.fch;
GO
