<?php
class TabuladorRecolectasModel extends Model{
    public $IdRecolecta;
    public $IdTabulador;
    public $Descripcion;
    public $Total;
    public $Fecha;
    public $Estatus;
    public $CodigoValor;
    public $IdUsuario;

    /**
     * @param $tab_id
     * @return array|false
     * @throws Exception
     */
    public function get_tabulator_collects($tab_id) : array|false {
        $query = "SELECT t1.IdRecolecta, t1.IdTabulador, t1.Descripcion, t2.Total, t1.Fecha, t1.Estatus, t1.CodigoValor,
                    CONCAT(
                        RIGHT('0' + CAST(DATEPART(HOUR, t1.Fecha) AS VARCHAR(2)), 2), ':',
                        RIGHT('0' + CAST(DATEPART(MINUTE, t1.Fecha) AS VARCHAR(2)), 2), ':',
                        RIGHT('0' + CAST(DATEPART(SECOND, t1.Fecha) AS VARCHAR(2)), 2)
                    ) AS HoraCompleta
                FROM
                    [TG].[dbo].[TabuladorRecolectas] t1
                    LEFT JOIN (
                        SELECT SUM(Total) Total, IdRecolecta, IdTabulador
                        FROM [TG].[dbo].[TabuladorDenominaciones]
                        WHERE IdTabulador = ? GROUP BY IdRecolecta, IdTabulador) t2 ON t1.IdTabulador = t2.IdTabulador AND t1.IdRecolecta = t2.IdRecolecta
                WHERE
                    t1.IdTabulador = ?
                ORDER BY
                    t1.IdRecolecta
                DESC;";
        $params = [$tab_id, $tab_id];

        return ($this->sql->select($query,$params)) ?: false ;
    }

    /**
     * @param $tabId
     * @return int|false
     * @throws Exception
     */
    function get_active_collect($tabId) : int|false {
        $query = 'SELECT
                    IdRecolecta
                FROM
                    [TG].[dbo].[TabuladorRecolectas]
                WHERE
                    IdTabulador = ? AND Estatus = 1;';
        $params = [$tabId];
        return ($rs=$this->sql->select($query,$params)) ? $rs[0]['IdRecolecta'] : false ;
    }

    /**
     * @param $tabId
     * @return array|false
     * @throws Exception
     */
    function get_collect($tabId) : array|false {
        $query = 'SELECT * FROM [TG].[dbo].[TabuladorRecolectas] WHERE IdTabulador = ? AND Estatus = 1;';
        $params = [$tabId];
        return ($rs=$this->sql->select($query,$params)) ? $rs[0] : false ;
    }

    /**
     * @param $tabId
     * @return int|false
     * @throws Exception
     */
    function get_last_collect($tabId) : int|false {
        // La función COALESCE() se utiliza para devolver el valor 1 en caso de que la consulta devuelva NULL. De esta manera, si no existen registros previos, la consulta devolverá 1 en lugar de NULL. Si existen registros previos, la consulta devolverá el valor máximo de IdRecolecta más 1.
        $query = 'SELECT COALESCE(MAX(IdRecolecta + 1), 1) AS consecutive FROM [TG].[dbo].[TabuladorRecolectas] WHERE IdTabulador = ?;';
        $params = [$tabId];
        return ($rs=$this->sql->select($query,$params)) ? $rs[0]['consecutive'] : false ;
    }

    /**
     * @param array $params
     * @return bool
     * @throws Exception
     */
    function add(Array $params) : bool {
        $query = "INSERT INTO [TG].[dbo].[TabuladorRecolectas] ( IdRecolecta,  IdTabulador,  Descripcion,  Total,   Fecha,  Estatus, CodigoValor, IdUsuario)
                  VALUES (?, ?, ?, ?, GETDATE(), 1, ?, ?);";
        return (bool)$this->sql->insert($query, [$params['IdRecolecta'], $params['IdTabulador'], $params['Descripcion'], $params['Total'], $params['CodigoValor'], $_SESSION['tg_user']['Id']]);
    }

    /**
     * @param $IdRecolecta
     * @return bool
     * @throws Exception
     */
    function cancel_collect($IdTabulator, $IdRecolecta) : bool {
        $query = 'DELETE FROM [TG].[dbo].[TabuladorRecolectas] WHERE IdTabulador = ? AND IdRecolecta = ?;';
        return (bool)$this->sql->delete($query, [$IdTabulator, $IdRecolecta]);
    }

    /**
     * @param $IdTabulador
     * @param $IdRecolecta
     * @return bool
     * @throws Exception
     */
    function close_collect($IdTabulador, $IdRecolecta) : bool {
        $query = 'UPDATE [TG].[dbo].[TabuladorRecolectas] SET Estatus = 2 WHERE IdTabulador = ? AND IdRecolecta = ?;';
        return (bool)$this->sql->update($query, [$IdTabulador, $IdRecolecta]);
    }

    /**
     * @param $IdRecolecta
     * @param $IdTabulador
     * @return bool
     * @throws Exception
     */
    function delete($IdRecolecta, $IdTabulador) : bool {
        $query = "DELETE FROM [TG].[dbo].[TabuladorRecolectas] WHERE IdRecolecta = ? AND IdTabulador = ?;";
        return (bool)$this->sql->delete($query, [$IdRecolecta, $IdTabulador]);
    }

    function get_deposit($IdRecolecta, $IdTabulador) {
        $query = "SELECT
                        TOP (1) t1.IdRecolecta, t1.IdTabulador, t1.Descripcion, t2.Total, t2.Valor, t1.Fecha, t1.Estatus, t1.CodigoValor, CASE WHEN t1.CodigoValor = 6 THEN 'MXN' ELSE 'USD' END AS Moneda
                    FROM [TG].[dbo].[TabuladorRecolectas] t1
                        LEFT JOIN (
                            SELECT COALESCE(SUM(Total), 0) Total, COALESCE(SUM(Valor), 0) Valor, IdRecolecta, IdTabulador
                            FROM [TG].[dbo].[TabuladorDenominaciones]
                            WHERE IdTabulador = ? GROUP BY IdRecolecta, IdTabulador
                        ) t2 ON t1.IdTabulador = t2.IdTabulador AND t1.IdRecolecta = t2.IdRecolecta
                    WHERE t1.IdRecolecta = ?
                        AND t1.IdTabulador = ?;";
        return ($rs = $this->sql->select($query, [$IdTabulador, $IdRecolecta, $IdTabulador])) ? $rs[0] : false;
    }

    /**
     * @param $tabId
     * @param $CodigoValor
     * @return mixed
     * @throws Exception
     */
    function get_total_by_tab($tabId, $CodigoValor): mixed
    {
        $monedaMap = ['MXN' => 6,'USD' => 5, 'MRL' => 192];
        $CodigoValor = $monedaMap[$CodigoValor] ?? $CodigoValor;

        if ($CodigoValor == 6) {
            $query = "SELECT COALESCE(SUM(Monto), 0) AS TotalMonto, COALESCE(SUM(Valor), 0) AS TotalValor FROM [TG].[dbo].[TabuladorDetalle] WHERE Id = ? AND CodigoValor IN(6,192) AND IdRecolecta IS NOT NULL;";
        } else {
            $query = "SELECT COALESCE(SUM(Monto), 0) AS TotalMonto, COALESCE(SUM(Valor), 0) AS TotalValor FROM [TG].[dbo].[TabuladorDetalle] WHERE Id = ? AND CodigoValor = 5 AND IdRecolecta IS NOT NULL;";
        }
        $params = [$tabId];
        $rs = $this->sql->select($query, $params);
        return $rs[0] ?: false;
    }

    function get_total_by_island($tabId, $codIsland, int $currency = 6) {
        $query = "SELECT
                        Isla, COALESCE(SUM(Monto), 0) Total
                    FROM
                        [TG].[dbo].[TabuladorDetalle]
                    WHERE
                        Id = ? AND CodigoValor = ? AND Isla = ? AND IdRecolecta IS NOT NULL
                    GROUP BY Isla";
        $params = [$tabId, $currency, $codIsland];
        if ($rs = $this->sql->select($query, $params)) {
            return $rs[0];
        } else {
            return [
                'Isla' => $codIsland,
                'Total' => 0
            ];
        }
    }

    function sp_eliminar_deposito($IdRecolecta, $IdTabulador) : bool {
        return (bool)$this->sql->executeStoredProcedure('[TG].[dbo].[sp_eliminar_deposito]', [$IdTabulador, $IdRecolecta, 0]);
    }
}