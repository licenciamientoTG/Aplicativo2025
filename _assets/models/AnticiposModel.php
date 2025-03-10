<?php
class AnticiposModel extends Model{
    public int $fch;
    public int $nrotur;
    public int $codisl;
    public int $codres;
    public int $sec;
    public $fchcor;
    public $codval;
    public $can;
    public $mto;
    public $codgas;
    public $mcaacu;
    public $codcli;
    public $imp;

    /**
     * @param $CodigoEstacion
     * @param $fch
     * @param $nrotur
     * @param $codisl
     * @param $sec
     * @param $mto
     * @return bool
     * @throws Exception
     */
    public function deleteAnticipo($CodigoEstacion, $fch, $nrotur, $codisl, $sec, $mto) : bool {
        $query = "DELETE FROM {$this->databases[$CodigoEstacion]}.[Anticipos] WHERE fch = ? AND nrotur = ? AND codisl = ? AND sec = ? AND mto = ?;";
        $params = [$fch, $nrotur, $codisl, $sec, $mto];
        return (bool)$this->sql->delete($query, $params);
    }

    /**
     * @param $CodigoEstacion
     * @param $fch
     * @param $nrotur
     * @param $codisl
     * @param $sec
     * @param $mto
     * @param $newMto
     * @param $newIsland
     * @return bool
     * @throws Exception
     */
    function editAnticipo($CodigoEstacion, $fch, $nrotur, $codisl, $sec, $mto, $newMto, $newIsland) : bool {
        $query = "UPDATE {$this->databases[$CodigoEstacion]}.[Anticipos] SET can = {$newMto}, mto = ?, codisl = ? WHERE fch = ? AND nrotur = ? AND codisl = ? AND sec = ? AND mto = ?;";
        $params = [$newMto, $newIsland, $fch, $nrotur, $codisl, $sec, $mto];
        return (bool)$this->sql->update($query, $params);
    }

    /**
     * @param $CodigoEstacion
     * @return int
     * @throws Exception
     */
    function get_last_id($CodigoEstacion) : int {
        $query = "SELECT MAX(sec + 1) sec FROM  {$this->databases[$CodigoEstacion]}.[Anticipos]";
        return $this->sql->select($query)[0]['sec'];
    }

    /**
     * @param $tabular
     * @param $dispatch
     * @param $CodigoValor
     * @param $responsable
     * @param $secuencial_anticipos
     * @return bool
     * @throws Exception
     */
    function addAnticipo($tabular, $dispatch, $CodigoValor, $responsable, $secuencial_anticipos) : bool {

        $query = "INSERT INTO {$this->databases[$tabular['CodigoEstacion']]}.Anticipos
                    (fch, nrotur, codisl, codres, sec, fchcor, codval, can, mto, codgas, mcaacu, codcli, imp)
                VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?);";
        $params = [dateToInt($tabular['FechaTabular']), $dispatch['nrotur'], $dispatch['codisl'], $responsable, $secuencial_anticipos, dateToInt($tabular['FechaTabular']), $CodigoValor['ValorButt_Num'], $dispatch['mto'], $dispatch['mto'], $tabular['CodigoEstacion'], 0, 0, 1];
        return (bool)$this->sql->insert($query, $params);
    }

    function dismark_dispatch_station($codest, $secuencialAnticipo) : bool {
        // Vamos a eliminar el registro de la tabla Anticipos donde la transaccion sea igual a la transaccion de la tabla Despachos
        $query = "DELETE FROM {$this->databases[$codest]}.[Anticipos] WHERE sec = ?;";
        return (bool)$this->sql->delete($query, [$secuencialAnticipo]);
    }

    function dismark_dispatch_central($codest, $secuencialAnticipo) : bool {
        // Vamos a eliminar el registro de la tabla Anticipos donde la transaccion sea igual a la transaccion de la tabla Despachos
        $query = "DELETE FROM [SG12].[dbo].[Anticipos] WHERE sec = ? AND codgas = ?;";
        return (bool)$this->sql->delete($query, [$secuencialAnticipo, $codest]);
    }

    function sp_actualizar_tabulador_y_anticipos($tabular, $dispatch, $CodigoValor, $responsable) {
        $params = array(
            'CodigoEstacion'     => intval($tabular['CodigoEstacion']),
            'tabuladorId'        => intval($tabular['Id']),
            'Despacho'           => intval($dispatch['nrotrn']),
            'fch'                => intval(dateToInt($tabular['FechaTabular'])),
            'nrotur'             => intval($dispatch['nrotur']),
            'codisl'             => intval($dispatch['codisl']),
            'codres'             => intval($responsable),
            'codval'             => intval($CodigoValor['ValorButt_Num']),
            'can'                => floatval($dispatch['mto']),
            'mto'                => floatval($dispatch['mto']),
            'DatabaseName'       => $this->databases[$tabular['CodigoEstacion']],
            'Resultado'          => 0
        );
        return (bool)$this->sql->executeStoredProcedure('[TG].[dbo].[sp_actualizar_tabulador_y_anticipos]', $params);
    }
}

