<?php
class LecturasModel extends Model{

    public $Gasolinera;
    public $Fecha;
    public $Turno;
    public $CodIsla;
    public $Isla;
    public $Bomba;
    public $CodProducto;
    public $Producto;
    public $LecturaInicialElectronica;
    public $LecturaFinalElectronica;
    public $LitrosVendidosElectronica;
    public $ImporteLecturaElectronica;
    public $LecturaInicialMecanica;
    public $LecturaFinalMecanica;
    public $LitrosVendidosMecanica;
    public $FechaRegistro;
    public $IdTabulador;
    public $FechaActualizacion;
    public $CapturaFinal;
    public $Jarreos;
    public $Precio;

    /**
     * @param $Id
     * @return array|false
     * @throws Exception
     */
    function get_readings_by_tabulator($CodigoEstacion, $FechaTabular, $Turno, $tabId, $Fecha_date) : array|false {
        $params = [intval($Turno), $FechaTabular, $Fecha_date, intval($CodigoEstacion), intval($tabId)];
        return $this->sql->executeStoredProcedure('[TG].[dbo].[sp_obtener_lecturas_tabulador]', $params) ?: false ;
    }

    /**
     * @param $CodigoEstacion
     * @param $Fecha
     * @param $data
     * @return bool
     * @throws Exception
     */
    function set_readings($CodigoEstacion, $Fecha, $data) : bool {
        $Bomba                  = $data['Bomba'];
        $Turno                  = $data['Turno'];
        $CodIsla                = $data['CodIsla'];
        $CodProducto            = $data['CodProducto'];
        $LecturaFinalMecanica   = $data['LecturaFinalMecanica'];


        $query = 'UPDATE [TG].[dbo].[Lecturas]
                    SET LecturaFinalMecanica = ?
                        , LitrosVendidosMecanica = ? - ISNULL(LecturaInicialMecanica, 0)
                        , FechaActualizacion = GETDATE()
                        , CapturaFinal = 1
                    WHERE Gasolinera = ?
                        AND Fecha = ?
                        AND Turno = ?
                        AND CodIsla = ?
                        AND CodProducto = ?
                        AND Bomba = ?;';
        $params = [$LecturaFinalMecanica, $LecturaFinalMecanica, $CodigoEstacion, $Fecha, $Turno, $CodIsla, $CodProducto, $Bomba];
        return ($this->sql->update($query, $params)) ? true : false ;

        // return ($this->sql->executeStoredProcedure("[TG].[dbo].[sp_actualizar_lectura]", compact('CodigoEstacion', 'Fecha', 'Turno', 'CodIsla', 'CodProducto', 'LecturaInicialMecanica', 'LecturaFinalMecanica', 'Campo', 'Bomba'))) ? true : false ;
    }

    /**
     * @param $measurements
     * @param $FechaTabular
     * @param $turno
     * @param $tabId
     * @return bool
     * @throws Exception
     */
    function insertMeasurements($CodigoEstacion, $FechaTabular, $turno, $tabId) {
        $query = "
            DECLARE @Turno INT = $turno; -- Puedes cambiar el valor según tus necesidades
            DECLARE @Fecha VARCHAR(50) = $FechaTabular; -- Puedes cambiar la fecha según tus necesidades
            DECLARE @CodigoEstacion INT = $CodigoEstacion; -- Puedes cambiar el código según tus necesidades
            DECLARE @IdTabulador INT = $tabId; -- Puedes cambiar el IdTabulador según tus necesidades
            
            -- Cálculo del turno anterior
            DECLARE @TurnoAnterior INT = CASE 
                WHEN @Turno = 11 THEN 41 
                WHEN @Turno = 21 THEN 11 
                WHEN @Turno = 31 THEN 21 
                WHEN @Turno = 41 THEN 31 
            END;
            
            WITH MedicionesCTE AS (
                SELECT
                    m.codgas AS Gasolinera,
                    i.cod AS CodIsla,
                    i.den AS Isla,
                    m.nrobom AS Bomba,
                    m.codprd AS CodProducto,
                    p.den AS Producto,
                    ROUND(SUM(CASE WHEN m.nrotur = @Turno THEN m.canacu ELSE 0 END), 2) AS LecturaFinalElectronica,
                    ROUND(SUM(CASE WHEN m.nrotur = @TurnoAnterior THEN m.canacu ELSE 0 END), 2) AS LecturaInicialElectronica,
                    ROUND(SUM(m.mto), 2) AS ImporteLecturaElectronica
                FROM SG12.dbo.Medicion m (NOLOCK)
                LEFT JOIN SG12.dbo.Productos p (NOLOCK) ON m.codprd = p.cod
                LEFT JOIN SG12.dbo.Islas i (NOLOCK) ON m.codisl = i.cod
                WHERE m.codgas = @CodigoEstacion
                    AND m.fch = CASE WHEN @TurnoAnterior = 41 THEN (CAST(@Fecha AS DATETIME) - 1) ELSE CAST(@Fecha AS DATETIME) END
                GROUP BY m.codgas, m.nrobom, m.codprd, p.den, i.cod, i.den
            )
            
            -- Combinar resultados
            INSERT INTO [TG].[dbo].Lecturas (Gasolinera, Fecha, Turno, CodIsla, Isla, Bomba, CodProducto, Producto, LecturaInicialElectronica, LecturaFinalElectronica, ImporteLecturaElectronica, FechaRegistro, IdTabulador)
            SELECT
                Gasolinera,
                @Fecha AS Fecha,
                @Turno AS Turno,
                CodIsla,
                Isla,
                Bomba,
                CodProducto,
                Producto,
                SUM(LecturaInicialElectronica) AS LecturaInicialElectronica,
                SUM(LecturaFinalElectronica) AS LecturaFinalElectronica,
                SUM(ImporteLecturaElectronica) AS ImporteLecturaElectronica,
                GETDATE() AS FechaRegistro,
                @IdTabulador AS IdTabulador
            FROM MedicionesCTE
            GROUP BY Gasolinera, CodIsla, Isla, Bomba, CodProducto, Producto
            
            -- Actualizar LitrosVendidosElectronica
            UPDATE [TG].[dbo].Lecturas SET LitrosVendidosElectronica = LecturaFinalElectronica - LecturaInicialElectronica
            WHERE IdTabulador = @IdTabulador;
            ";
        return $this->sql->query($query);
    }

    function exists($CodigoEstacion, $Fecha) : int|false {
        $query = "SELECT * FROM [TG].[dbo].[Lecturas] WHERE IdTabulador = ? AND Gasolinera = ? AND Bomba = ? AND CodProducto = ?;";
        $params = [$_POST['tabId'], $CodigoEstacion, $_POST['Bomba'], $_POST['CodProducto']];
        return ($rs = $this->sql->select($query, $params)) ? $rs[0]['Id'] : false ;
    }

    function add($tabulador) : bool {
        $query = "INSERT INTO [TG].[dbo].[Lecturas] (
                        [Gasolinera]
                       ,[Fecha]
                       ,[Turno]
                       ,[CodIsla]
                       ,[Bomba]
                       ,[CodProducto]
                       ,[LecturaFinalMecanica]
                       ,[FechaRegistro]
                       ,[IdTabulador]
                       ,[IdUsuario]
                   )
                 VALUES
                       (?,CONVERT(datetime, ?, 120),?,?,?,?,?,GETDATE(),?,?);";
        $params = [$tabulador['CodigoEstacion'], $tabulador['FechaTabular'], $tabulador['Turno'], $_POST['CodIsla'], $_POST['Bomba'], $_POST['CodProducto'], $_POST['LecturaFinalMecanica'], $tabulador['Id'], $_SESSION['tg_user']['Id']];
        return ($this->sql->insert($query, $params)) ? true : false ;
    }

    function update($id, $data) : bool {
        $query = "UPDATE [TG].[dbo].[Lecturas] SET [LecturaFinalMecanica] = ? WHERE Id = ?;";
        $params = [trim(floatval($data['LecturaFinalMecanica'])), intval($id)];
        return (bool)$this->sql->update($query, $params);
    }
}