<?php

class DespachosLealtadModel extends Model {
    public $DespachoId;
    public $DespachoFecha;
    public $DespachoNrotrn;
    public $DespachoTurno;
    public $DespachoMonto;
    public $DespachoLitros;
    public $ProductoId;
    public $ProductoDescripcion;
    public $EstacionId;
    public $EstacionDescripcion;
    public $ClienteId;
    public $ClienteDescripcion;
    public $ClientePuntos;
    public $DespachoLleno;
    public $ClienteRazonSocial;
    public $procesado;

    /**
     * @param $DespachoNrotrn
     * @param $EstacionId
     * @return array|false
     * @throws Exception
     */
    public function get_row($DespachoNrotrn, $EstacionId) : array|false{
        $query = "SELECT TOP(1) * FROM [TG].[dbo].[DespachosLealtad] WHERE [DespachoNrotrn] = ? AND [EstacionId] = ?;";
        return ($rs = $this->sql->select($query, [$DespachoNrotrn, $EstacionId])) ? $rs[0] : false ;
    }

    /**
     * @param $Fecha
     * @param $IdDespacho
     * @param $Turno
     * @param $Monto
     * @param $Litros
     * @param $Producto
     * @param $ProductoDesc
     * @param $Estacion
     * @param $EstacionDesc
     * @param $CodCliente
     * @param $Cliente
     * @param $Puntos
     * @param $RazonSocial
     * @return bool
     * @throws Exception
     */
    public function insert($Fecha, $IdDespacho, $Turno , $Monto , $Litros , $Producto , $ProductoDesc , $Estacion , $EstacionDesc , $CodCliente , $Cliente , $Puntos, $RazonSocial) {
        $query = "INSERT INTO [TG].[dbo].[DespachosLealtad] (
                        [DespachoFecha]
                        ,[DespachoNrotrn]
                        ,[DespachoTurno]
                        ,[DespachoMonto]
                        ,[DespachoLitros]
                        ,[ProductoId]
                        ,[ProductoDescripcion]
                        ,[EstacionId]
                        ,[EstacionDescripcion]
                        ,[ClienteId]
                        ,[ClienteDescripcion]
                        ,[ClientePuntos]
                        ,[DespachoLleno]
                        ,[ClienteRazonSocial])
                    VALUES
                        (GETDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $params = [$IdDespacho, intval($Turno), $Monto, floatval(number_format($Litros, 3, '.', '')), intval($Producto), trim($ProductoDesc), intval($Estacion), $EstacionDesc, intval($CodCliente), $Cliente, $Puntos, intval(($Litros >= 45 ? 1 : 0 )), $RazonSocial];
        return (bool)$this->sql->insert($query, $params);
    }
}