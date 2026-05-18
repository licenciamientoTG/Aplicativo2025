-- =============================================
-- Versión optimizada: solo queries locales.
-- total_ingresado, total_ventas, saldo y TotalProductos
-- se calculan en PHP desde los datos por isla (get_tab_process_data).
-- El check de VentasC se movió al endpoint /operations/tab_check_status/{tabId}.
-- =============================================
ALTER PROCEDURE [dbo].[sp_obtener_info_tabulador_completo]
    @TabId INT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @codgas      VARCHAR(50);
    DECLARE @FechaTabular DATE;
    DECLARE @Turno       INT;
    DECLARE @Servidor    VARCHAR(100);
    DECLARE @BaseDatos   VARCHAR(100);
    DECLARE @IntDate     INT;
    DECLARE @Islands     VARCHAR(255);

    BEGIN TRY
        SELECT TOP 1
            @codgas       = te.CodigoEstacion,
            @FechaTabular = te.FechaTabular,
            @IntDate      = DATEDIFF(DAY, '1900-01-01', te.FechaTabular) + 1,
            @Turno        = te.Turno,
            @Servidor     = e.Servidor,
            @BaseDatos    = e.BaseDatos,
            @Islands      = ISNULL(STUFF((
                SELECT ',' + CAST(i.cod AS VARCHAR(10))
                FROM [SG12].[dbo].[Islas] i
                WHERE i.codgas = te.CodigoEstacion
                FOR XML PATH(''), TYPE).value('.', 'NVARCHAR(MAX)'), 1, 1, ''), '')
        FROM [TG].[dbo].[TabuladorEncabezado] te
        INNER JOIN [TG].[dbo].[Estaciones] e ON te.CodigoEstacion = e.Codigo
        WHERE te.Id = @TabId;

        IF @codgas IS NULL
        BEGIN
            RAISERROR('No se encontró información para el TabId especificado', 16, 1);
            RETURN;
        END

        SELECT
            t1.Id,
            t1.CodigoEstacion,
            t1.FechaTabular,
            t1.Turno,
            t1.Usuario,
            t1.Total,
            t1.Estatus,
            t1.TipoCambio,
            t1.FechaCreacion,
            t1.FechaCierre,
            t1.LimiteFajilla,
            t1.LimiteRecolecta,
            t1.IdUsuario,
            ISNULL(t2.TotalDenominaciones, 0.00) AS TotalDenominaciones,
            t3.Estacion,
            t3.Domicilio,
            @Islands   AS Islands,
            ISNULL(totales.Total, 0)              AS Total,
            ISNULL(totales.Efectivo, 0)           AS Efectivo,
            ISNULL(totales.Morralla, 0)           AS Morralla,
            ISNULL(totales.TotalPending, 0)       AS TotalPending,
            ISNULL(totales.TotalPendingUSD, 0)    AS TotalPendingUSD,
            ISNULL(totales.TotalPendingMXN, 0)    AS TotalPendingMXN,
            ISNULL(totales.TotalPendingMRL, 0)    AS TotalPendingMRL,
            CONCAT('[', @Servidor, '].[', @BaseDatos, '].[dbo]') AS DBString,
            @Servidor  AS station_server,
            @BaseDatos AS BaseDatos,
            @IntDate   AS fechaTabularInt,
            0.00       AS TotalProductos,
            t3.mojo_access_key,
            -- Calculados en PHP desde get_tab_process_data
            0.00       AS total_ingresado,
            0.00       AS total_ventas,
            0.00       AS saldo
        FROM [TG].[dbo].[TabuladorEncabezado] t1
        INNER JOIN [TG].[dbo].[Estaciones] t3 ON t1.CodigoEstacion = t3.Codigo
        LEFT JOIN (
            SELECT IdTabulador, SUM(Total) AS TotalDenominaciones
            FROM [TG].[dbo].[TabuladorDenominaciones]
            WHERE IdTabulador = @TabId
            GROUP BY IdTabulador
        ) t2 ON t1.Id = t2.IdTabulador
        LEFT JOIN (
            SELECT
                Id,
                SUM(CASE WHEN CodigoValor IN (5,6,192) THEN Valor ELSE 0 END)                                                        AS Total,
                SUM(CASE WHEN CodigoValor = 6 THEN Valor ELSE 0 END)                                                                 AS Efectivo,
                SUM(CASE WHEN CodigoValor = 192 THEN Valor ELSE 0 END)                                                               AS Morralla,
                SUM(CASE WHEN ISNULL(IdRecolecta,0)=0 AND Estatus=1 AND CodigoValor IN (5,6,192) THEN Valor ELSE 0 END)              AS TotalPending,
                SUM(CASE WHEN ISNULL(IdRecolecta,0)=0 AND Estatus=1 AND CodigoValor=5   THEN Valor ELSE 0 END)                       AS TotalPendingUSD,
                SUM(CASE WHEN ISNULL(IdRecolecta,0)=0 AND Estatus=1 AND CodigoValor=6   THEN Valor ELSE 0 END)                       AS TotalPendingMXN,
                SUM(CASE WHEN ISNULL(IdRecolecta,0)=0 AND Estatus=1 AND CodigoValor=192 THEN Valor ELSE 0 END)                       AS TotalPendingMRL
            FROM [TG].[dbo].[TabuladorDetalle]
            WHERE Id = @TabId
            GROUP BY Id
        ) totales ON t1.Id = totales.Id
        WHERE t1.Id = @TabId;

    END TRY
    BEGIN CATCH
        DECLARE @ErrorMessage  NVARCHAR(4000) = ERROR_MESSAGE();
        DECLARE @ErrorSeverity INT            = ERROR_SEVERITY();
        DECLARE @ErrorState    INT            = ERROR_STATE();
        RAISERROR(@ErrorMessage, @ErrorSeverity, @ErrorState);
    END CATCH
END
