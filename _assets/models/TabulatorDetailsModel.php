<?php
class TabulatorDetailsModel extends Model{
    public $Id;
    public $Secuencial;
    public $Isla;
    public $CodigoValor;
    public $Cantidad;
    public $Monto;
    public $Moneda;
    public $TipoCambio;
    public $Valor;
    public $Fecha;
    public $Estatus;
    public $responsable_id;
    public $Usuario;
    public $FechaCambio;
    public $UsuarioCambio;
    public $Efectivo;
    public $IdRecolecta;
    public $Despacho;
    public $ProductoId;
    public $Litros;
    public $CodigoTar;
    public $CodigoRut;
    public $CodigoEstacion;
    public $Turno;
    public $secuencialAnticipo;
    public $LogReg;

    /**
     * @param $tabId
     * @return array|false
     * @throws Exception
     */
    function get_wads($tabId) : array|false {
        $query = "SELECT
                t1.Id
                , t1.Secuencial
                , t1.Isla AS IdIsla
                , t1.CodigoValor
                , t1.Cantidad
                , t1.Monto
                , t1.Moneda
                , t1.TipoCambio
                , t1.Fecha
                , t1.Estatus
                , t1.Usuario
                , t1.FechaCambio
                , t1.UsuarioCambio
                , t1.Valor
                , t1.Efectivo
                , ISNULL(t1.IdRecolecta, 0) AS IdRecolecta
                , t1.Despacho
                , t1.Turno
                , t1.secuencialAnticipo
                , CONVERT(varchar, t1.logreg, 108) AS Hora
                , t2.den AS Isla
                , LTRIM(t3.den) AS CodigoValorDescripcion
				, t4.Nombre Responsable
            FROM [TG].[dbo].[TabuladorDetalle] t1 (NOLOCK)
                LEFT JOIN SG12.dbo.Islas t2 (NOLOCK) ON t1.Isla = t2.cod
                LEFT JOIN SG12.dbo.Valores t3 (NOLOCK) ON t3.cod = t1.CodigoValor
				LEFT JOIN TG.dbo.Asignaciones t4 ON t1.Id = t4.IdTabulador AND t2.cod = t4.Isla
            WHERE t1.Id = ? AND t1.Estatus = 1
                AND CodigoValor IN(5,6, 192)
            ORDER BY t1.Secuencial;
        ";
        return $this->sql->select($query, [$tabId]) ?: false;
    }

    function get_wads_by_responsable($Responsable, $IdTabulador) : bool {
        // Verificamos si el responsable tiene fajillas a su nombre
        $query = "SELECT *, CONVERT(varchar, logreg, 108) AS Hora FROM [TG].[dbo].[TabuladorDetalle] WHERE Id = ? AND responsable_id = ?;";
        $params = [$IdTabulador, $Responsable];
        return (bool)$this->sql->select($query, $params);

    }

    /**
     * @param array $data
     * @return true
     */
    function add(Array $data): bool {
        // Insertar en TabuladorDetalle el registro con el valor de Efectivo en 0 (cero)
        if ($this->insertIntoTabuladorDetalle($data, ((in_array($data['CodigoValor'], [6, 192])) ? 1 : 0), dateToInt($_POST['FechaTabular']))) {
            return true;
        };
        return false;
    }

    /**
     * @param $data
     * @param $secuencial
     * @param $Efectivo
     * @return bool
     * @throws Exception
     */
    private function insertIntoTabuladorDetalle($data, $Efectivo, int $FechaTabuladorInt) : bool {
        $params = [
            intval($data['Id']), intval($data['Isla']), intval($data['CodigoValor']), floatval($data['Cantidad']), floatval($data['Monto']),
            $data['Moneda'], floatval($data['TipoCambio']), floatval($data['Valor']), $data['Usuario'], intval($Efectivo),
            intval($data['CodigoEstacion']), $this->databases[$data['CodigoEstacion']], intval($data['Turno']), intval($_SESSION['tg_user']['Id']), $FechaTabuladorInt
        ];
        return (bool)$this->sql->executeStoredProcedure('[TG].[dbo].[sp_agregar_fajilla]', $params);
    }

    public function insertIntoTabuladorDetalle2($params) : bool {
        return (bool)$this->sql->executeStoredProcedure('[TG].[dbo].[sp_agregar_fajilla]', $params);
    }


    /**
     * @param $Id
     * @return mixed
     * @throws Exception
     */
    // Función para obtener el siguiente valor de Secuencial
    private function getNextSecuencial($Id): mixed {
        $query = "SELECT ISNULL(MAX(Secuencial), 0) + 1 AS Secuencial FROM [TG].[dbo].[TabuladorDetalle] WHERE Id = ?;";
        return $this->sql->select($query, [$Id])[0]['Secuencial'];
    }


    /**
     * @param $data
     * @param $todayInt
     * @param $secuencial
     * @return bool
     * @throws Exception
     */
    // Función para insertar en Anticipos
    private function insertIntoAnticipos($data, $todayInt, int $secuencial) : bool {
        $Cod3 = $this->sql->select("SELECT MAX(sec + 1) AS Cod3 FROM {$this->databases[$data['CodigoEstacion']]}.Anticipos")[0]['Cod3'];

        $Cod3 == null ? $Cod3 = 1 : $Cod3;

        $query = "INSERT INTO {$this->databases[$data['CodigoEstacion']]}.[Anticipos]
            (fch, nrotur, codisl, codres, sec, fchcor, codval, can, mto, codgas, mcaacu, codcli, imp)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?);";

        $params = [
            $todayInt, $data['Turno'], $data['Isla'], $data['responsable_id'], $Cod3, $todayInt, $data['CodigoValor'],
            $data['Monto'], $data['Monto'], $data['CodigoEstacion'], 0, 0, 1
        ];

        if ($this->sql->insert($query, $params)) {
            // Ahora actualizamos la tabla TabuladorDetalle con el sec del Anticipo. No es un Id pero nos puede ayudar a casar el Anticipo contra la Fajilla
            if ($this->sql->update("UPDATE [TG].[dbo].[TabuladorDetalle] SET secuencialAnticipo = ? WHERE Id = ? AND Secuencial = ?", [$Cod3, $data['Id'], $secuencial])) {
                return true;
            } else {
                return false;
            }
        } else {
            return false;
        }
    }

    /**
     * @param $tabId
     * @param $secuencial
     * @return bool
     * @throws Exception
     */
    function delete_wad($tabId, $secuencial) : bool {
        $query = "DELETE FROM [TG].[dbo].[TabuladorDetalle] WHERE Id = ? AND Secuencial = ?;";
        return (bool)$this->sql->delete($query, [$tabId, $secuencial]);
    }

    /**
     * @param $tabId
     * @param $secuencial
     * @return array|false
     * @throws Exception
     */
    function get_wad($tabId, $secuencial) : array|false {
        $query = "SELECT t1.Id
                    , t1.Secuencial
                    , t1.Isla AS IdIsla
                    , t1.CodigoValor
                    , t1.Cantidad
                    , t1.Monto
                    , t1.Moneda
                    , t1.TipoCambio
                    , t1.Fecha
                    , t1.Estatus
                    , t1.Usuario
                    , t1.FechaCambio
                    , t1.UsuarioCambio
                    , t1.Valor
                    , t1.Efectivo
                    , t1.IdRecolecta
                    , t1.Despacho
                    , t1.Turno
                    , t1.secuencialAnticipo
                    , t2.den AS Isla
                    , t3.den AS CodigoValorDescripcion
                FROM [TG].[dbo].[TabuladorDetalle] t1 (NOLOCK)
                    LEFT JOIN SG12.dbo.Islas t2 (NOLOCK) ON t1.Isla = t2.cod
                    LEFT JOIN SG12.dbo.Valores t3 (NOLOCK) ON t3.cod = t1.CodigoValor
                WHERE t1.Id = ? AND t1.Secuencial = ?
                ORDER BY t1.Secuencial ASC;";
        return $this->sql->select($query, [$tabId, $secuencial])[0] ?: false;
    }

    /**
     * @param $monto
     * @param $island_id
     * @param $newValor
     * @param $tabId
     * @param $secuencial
     * @return bool
     * @throws Exception
     */
    function edit($monto, $island_id, $newValor, $tabId, $secuencial) : bool {
        $query = "UPDATE [TG].[dbo].[TabuladorDetalle] SET Monto = ?, Isla = ?, Valor = ?, Cantidad = {$monto} WHERE Id = ? AND Secuencial = ?;";
        return (bool)$this->sql->update($query, [$monto, $island_id, $newValor, $tabId, $secuencial]);
    }

    /**
     * @param $tabId
     * @param $currency
     * @return array|float
     * @throws Exception
     */
    function getPendingWads($tabId, $currency) {
        switch ($currency) {
            case 'MXN':
                $currency = '6,192';
                break;
            case 'MRL':
                $currency = '6,192';
                break;
            case 'USD':
                $currency = '5';
                break;
        }

        $query = 'SELECT
                    COALESCE(CAST(SUM(ISNULL(td.Monto, 0)) AS FLOAT), 0.0) AS TotalMonto,
                    COALESCE(CAST(SUM(ISNULL(td.Valor, 0)) AS FLOAT), 0.0) AS TotalValor,
                    CASE 
                        WHEN EXISTS (
                            SELECT
                                1
                            FROM
                                [TG].[dbo].[TabuladorRecolectas] tr
                            WHERE
                                tr.IdTabulador = td.Id
                                AND tr.Estatus = 1
                        ) THEN 1
                        ELSE 0
                    END AS TieneRecolectas,
					COALESCE((
						SELECT 
							MAX(tr2.IdRecolecta + 1)
						FROM 
							[TG].[dbo].[TabuladorRecolectas] tr2
						WHERE 
							tr2.IdTabulador = td.Id
					), 1) AS consecutive
                FROM
                    [TG].[dbo].[TabuladorDetalle] td
                WHERE
                    td.Id = ?
                    AND ISNULL(td.IdRecolecta, 0) = 0
                    AND td.Estatus = 1
                    AND td.CodigoValor IN(' . $currency . ')
                GROUP BY
                    td.Id;';

        $params = [$tabId];

        return ($rs = $this->sql->select($query, $params)) ? $rs[0] : 0;
    }

    /**
     * @param $IdRecolecta
     * @param $IdTabulador
     * @param $CodigoValor
     * @return bool
     * @throws Exception
     */
    function close_wads($IdRecolecta, $IdTabulador, $CodigoValor) : bool {
        if ($CodigoValor == 6) {
            $query = "UPDATE [TG].[dbo].[TabuladorDetalle] SET IdRecolecta = ? WHERE Id = ? AND CodigoValor IN(6, 192) AND ISNULL(IdRecolecta, 0) = 0;";
        } else {
            $query = "UPDATE [TG].[dbo].[TabuladorDetalle] SET IdRecolecta = ? WHERE Id = ? AND CodigoValor = 5 AND ISNULL(IdRecolecta, 0) = 0;";
        }
        $params = [$IdRecolecta, $IdTabulador];
        return (bool)$this->sql->update($query, $params);
    }

    /**
     * @param $tabular
     * @param $dispatch
     * @param $valor
     * @param $exchange_now
     * @return bool
     * @throws Exception
     */
    function addMarkDispatches($tabular, $dispatch, $valor, $exchange_now) : bool {
        // Obtenemos el último secuencial
        $query = "INSERT INTO [TG].[dbo].[TabuladorDetalle] (Id, Secuencial, Isla, CodigoValor, Cantidad, Monto, Moneda, TipoCambio, Valor, Fecha, Estatus, Usuario, Efectivo, Despacho, CodigoEstacion, Turno, LogReg, IdUsuario)
                    VALUES(?,(SELECT ISNULL(MAX(Secuencial), 0) + 1 AS Secuencial FROM [TG].[dbo].[TabuladorDetalle] WHERE Id = ?),?,?,?,?,?,?,?,GETDATE(),?,?,?,?,?,?,GETDATE(),?);";
        $params = [$tabular['Id'], $tabular['Id'], $dispatch['codisl'], $valor['ValorButt_Num'], 1, $dispatch['mto'], 'MXN', $exchange_now, $dispatch['mto'], 1, $tabular['Usuario'], 1, $dispatch['nrotrn'], $tabular['CodigoEstacion'], $tabular['Turno'], $_SESSION['tg_user']['Id']];
        return ($this->sql->insert($query, $params)) ? true : false ;
    }

    function get_deposit_wads($IdRecolecta, $IdTabulador) : array | false {
        $query = "SELECT *, CONVERT(varchar, logreg, 108) AS Hora FROM [TG].[dbo].[TabuladorDetalle] WHERE IdRecolecta = ? AND Id = ?;";
        $params = [$IdRecolecta, $IdTabulador];
        return $this->sql->select($query, $params) ?: false;
    }

    function get_wads_by_island($IdTabulador) : array|false {
        $query = "SELECT
                    t1.cod,
                    t1.den,
                    t1.codgas,
                    t2.*
                FROM
                    [SG12].[dbo].[Islas] t1 
                        LEFT JOIN (
                            SELECT 
                                Id, 
                                COUNT(*) AS hits, 
                                SUM(CASE WHEN CodigoValor = 6 THEN COALESCE(Valor, 0) ELSE 0 END) AS Monto_MXN,
                                SUM(CASE WHEN CodigoValor = 5 THEN COALESCE(Monto, 0) ELSE 0 END) AS Monto_USD,
                                SUM(CASE WHEN CodigoValor = 192 THEN COALESCE(Monto, 0) ELSE 0 END) AS Monto_MRL,
                                Isla 
                            FROM 
                                [TG].[dbo].[TabuladorDetalle] 
                            WHERE 
                                CodigoValor IN (5, 6, 192) 
                                AND Id = ? 
                            GROUP BY 
                                Isla, Id) t2 ON t1.cod = t2.Isla 
                WHERE
                        t2.Id = ?";
        $params = [$IdTabulador, $IdTabulador];
        $rs = $this->sql->select($query, $params);
        return $rs ?: false;
    }

    function dismark_dispatch($codest, $nrotrn) : bool {
        // Eliminamos el registro de la tabla
        $query = "DELETE FROM [TG].[dbo].[TabuladorDetalle] WHERE CodigoEstacion = ? AND Despacho = ?;";
        $params = [$codest, $nrotrn];
        return (bool)$this->sql->delete($query, $params);
    }

    function get_wad_by_dispatch($codest, $nrotrn) : array|false {
        $query = "SELECT *, CONVERT(varchar, logreg, 108) AS Hora FROM [TG].[dbo].[TabuladorDetalle] WHERE CodigoEstacion = ? AND Despacho = ?;";
        $params = [$codest, $nrotrn];
        if ($rs = $this->sql->select($query, $params)) {
            return $rs[0];
        } else {
            return false;
        }
    }

    function release_wads($IdRecolecta, $IdTabulador) : bool {
        $query = "UPDATE [TG].[dbo].[TabuladorDetalle] SET IdRecolecta = NULL WHERE Id = ? AND IdRecolecta = ?;";
        $params = [$IdTabulador, $IdRecolecta];
        return (bool)$this->sql->update($query, $params);
    }

    function get_total_by_tab($tabId) : float {
        $query = "SELECT
                    COALESCE(CAST(SUM(Valor) AS FLOAT), 0) AS TotalValor
                FROM
                    [TG].[dbo].[TabuladorDetalle]
                WHERE
                    Id = ?
                    AND CodigoValor IN(5,6,192)
                ;";
        $params = [$tabId];
        return $this->sql->select($query, $params)[0]['TotalValor'];
    }
}