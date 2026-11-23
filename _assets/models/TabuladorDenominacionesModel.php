<?php
class TabuladorDenominacionesModel extends Model{
    public $Id; // Id del Tabular
    public $IdTabulador;  // Id del tabulador
    public $Denominacion; // Id de la tabla Denominación
    public $Cantidad; // Cantidad de unidades
    public $Valor; // Valor de la denominación
    public $Total; // Total de la denominación
    public $Estatus; // Estatus del registro
    public $TipoCambio; // Al insertar, siempre será 1
    public $IdRecolecta;
    public $IdUsuario;

    function __construct() {
        parent::__construct();
    }

    /**
     * Denominaciones por moneda del depósito: [IdDenominacion => [campo del formulario, valor unitario]].
     * CodigoValor 6 = MXN (incluye morralla), cualquier otro = USD.
     */
    private function denomination_map($CodigoValor) : array {
        if ($CodigoValor == '6') {
            return [
                13 => ['bill_thousand', 1000],
                12 => ['bill_five_hundred', 500],
                11 => ['bill_two_hundred', 200],
                10 => ['bill_hundred', 100],
                9  => ['bill_fifty', 50],
                8  => ['bill_twenty', 20],
                6  => ['coin_twenty', 20],
                5  => ['coin_ten', 10],
                4  => ['coin_five', 5],
                3  => ['coin_two', 2],
                2  => ['coin_one', 1],
                1  => ['coin_point_fifty', 0.5],
            ];
        }
        return [
            20 => ['bill_hundred_usd', 100],
            19 => ['bill_fifty_usd', 50],
            18 => ['bill_twenty_usd', 20],
            17 => ['bill_ten_usd', 10],
            16 => ['bill_five_usd', 5],
            15 => ['bill_two_usd', 2],
            14 => ['bill_one_usd', 1],
            22 => ['coin_one_usd', 1],
            23 => ['coin_half_usd', 0.5],
            24 => ['coin_quarter_usd', 0.25],
            25 => ['coin_dime_usd', 0.1],
            26 => ['coin_nickel_usd', 0.05],
            27 => ['coin_penny_usd', 0.01],
        ];
    }

    /**
     * Total contado en las denominaciones enviadas, en centavos de la moneda del depósito
     * (pesos para MXN, dólares para USD). Se trabaja en centavos para evitar errores de redondeo.
     */
    function count_total_cents(array $data, $CodigoValor) : int {
        $cents = 0;
        foreach ($this->denomination_map($CodigoValor) as [$field, $value]) {
            $quantity = max(0, intval($data[$field] ?? 0));
            $cents += $quantity * intval(round($value * 100));
        }
        return $cents;
    }

    /**
     * Guarda las denominaciones del depósito. En USD, Total se guarda en pesos (cantidad x valor x tipo de cambio).
     * Lanza excepción si algún INSERT falla, para que el llamador haga rollBack.
     * @throws Exception
     */
    function add($data, $deposit, $exchange_now) : bool {
        $query = 'INSERT INTO [TG].[dbo].[TabuladorDenominaciones] (IdTabulador, Denominacion, Cantidad, Valor, Total, Estatus, TipoCambio, IdRecolecta, IdUsuario) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?);';
        $isMXN = ($deposit['CodigoValor'] == '6');
        $rate = $isMXN ? 1 : $exchange_now;
        $inserted = 0;

        foreach ($this->denomination_map($deposit['CodigoValor']) as $denomination => [$field, $value]) {
            $quantity = max(0, intval($data[$field] ?? 0));
            if ($quantity > 0) {
                $params = [$data['IdTabulador'], $denomination, $quantity, $value, ($value * $quantity * $rate), 1, $rate, $deposit['IdRecolecta'], $_SESSION['tg_user']['Id']];
                if (!$this->sql->insert($query, $params)) {
                    return false;
                }
                $inserted++;
            }
        }
        return $inserted > 0;
    }

    /**
     * @param $IdTabulador
     * @return float|false
     * @throws Exception
     */
    function getTotalByTabular($IdTabulador) : float|false {
        $query = "SELECT ISNULL(SUM(t.Total), 0) Total  FROM [TG].[dbo].TabuladorDenominaciones t (NOLOCK) LEFT JOIN [TG].[dbo].Denominacion d (NOLOCK) ON t.Denominacion = d.Id WHERE t.IdTabulador = ?";
        return ($rs=$this->sql->select($query, [$IdTabulador])) ? $rs[0]['Total'] : false ;
    }

    function getDenominationsByTabularDeposit($IdRecolecta, $IdTabulador) : array|false {
        $query = "SELECT t.Id, t.Cantidad, t.Valor, t.TipoCambio, d.Moneda, t.Total, d.Descripcion, CASE 
                        WHEN d.Tipo = 1 THEN 'Moneda'
                        WHEN d.Tipo = 2 THEN 'Billete'
                    ELSE 'Otro'
                END AS Tipo FROM [TG].[dbo].TabuladorDenominaciones t (NOLOCK) LEFT JOIN [TG].[dbo].Denominacion d (NOLOCK) ON t.Denominacion = d.Id WHERE t.IdRecolecta = ? AND t.IdTabulador = ? ORDER BY t.Valor DESC;";
        return $this->sql->select($query, [$IdRecolecta, $IdTabulador]) ?: false ;
    }

    function delete($IdRecolecta, $IdTabulador) : bool {
        $query = "DELETE FROM [TG].[dbo].[TabuladorDenominaciones] WHERE IdRecolecta = ? AND IdTabulador = ?;";
        return (bool)$this->sql->delete($query, [$IdRecolecta, $IdTabulador]);
    }
}