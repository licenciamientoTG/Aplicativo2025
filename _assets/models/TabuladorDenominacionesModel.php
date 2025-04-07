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
     * @param $data
     * @param $deposit
     * @param $exchange_now
     * @return bool
     * @throws Exception
     */
    function add($data, $deposit, $exchange_now) : bool {
        $query = 'INSERT INTO [TG].[dbo].[TabuladorDenominaciones] (IdTabulador, Denominacion, Cantidad, Valor, Total, Estatus, TipoCambio, IdRecolecta, IdUsuario) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?);';
        if ($deposit['CodigoValor'] == '6') { // Si es efectivo (moneda) en MXN
            $denominations = [
                13 => $data['bill_thousand'],       // Valor 1000
                12 => $data['bill_five_hundred'],   // Valor 500
                11 => $data['bill_two_hundred'],    // Valor 200
                10 => $data['bill_hundred'],        // Valor 100
                9  => $data['bill_fifty'],          // Valor 50
                8  => $data['bill_twenty'],         // Valor 20
                6  => $data['coin_twenty'],         // Valor 20
                5  => $data['coin_ten'],            // Valor 10
                4  => $data['coin_five'],           // Valor 5
                3  => $data['coin_two'],            // Valor 2
                2  => $data['coin_one'],            // Valor 1
                1  => $data['coin_point_fifty'] // Valor 0.50
            ];

            $valores = [
                13 => 1000,
                12 => 500,
                11 => 200,
                10 => 100,
                9  => 50,
                8  => 20,
                6  => 20,
                5  => 10,
                4  => 5,
                3  => 2,
                2  => 1,
                1  => 0.5
            ];
            foreach ($denominations as $denomination => $quantity) {
                if ($quantity > 0) {
                    $params = [$data['IdTabulador'], $denomination, $quantity, $valores[$denomination], ($valores[$denomination] * $quantity), 1, 1, $deposit['IdRecolecta'], $_SESSION['tg_user']['Id']];
                    $this->sql->insert($query, $params);
                }
            }
        } else {
            $denominations = [
                20 => $data['bill_hundred_usd'], // Valor 100
                19 => $data['bill_fifty_usd'],   // Valor 50
                18 => $data['bill_twenty_usd'],  // Valor 20
                17 => $data['bill_ten_usd'],     // Valor 10
                16 => $data['bill_five_usd'],    // Valor 5
                15 => $data['bill_two_usd'],     // Valor 2
                14 => $data['bill_one_usd'],     // Valor 1
                22 => $data['coin_one_usd'],     // Valor 1
                23 => $data['coin_half_usd'],    // Valor .5
                24 => $data['coin_quarter_usd'], // Valor .25
                25 => $data['coin_dime_usd'],    // Valor .1
                26 => $data['coin_nickel_usd'],  // Valor .05
                27 => $data['coin_penny_usd']    // Valor 0.01
            ];

            $valores = [
                20 => 100, // Valor 100
                19 => 50,  // Valor 50
                18 => 20,  // Valor 20
                17 => 10,  // Valor 10
                16 => 5,   // Valor 5
                15 => 2,   // Valor 2
                14 => 1,   // Valor 1
                22 => 1,   // Valor 1
                23 => .5,  // Valor .5
                24 => .25, // Valor .25
                25 => .1,  // Valor .1
                26 => .05, // Valor .05
                27 => .01  // Valor 0.01
            ];

            foreach ($denominations as $denomination => $quantity) {
                if ($quantity > 0) {
                    $params = [$data['IdTabulador'], $denomination, $quantity, $valores[$denomination], (($valores[$denomination] * $quantity) * $exchange_now), 1, $exchange_now, $deposit['IdRecolecta'], $_SESSION['tg_user']['Id']];
                    $this->sql->insert($query, $params);
                }
            }
        }
        return true;
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