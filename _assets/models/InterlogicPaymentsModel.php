<?php
class InterlogicPaymentsModel extends Model{
    public $id;
    public $IdPago;
    public $IdTransaccion;
    public $TipoOperacion;
    public $idContrato;
    public $numDespacho;
    public $gradoCombustible;
    public $numeroUnidades;
    public $UnidadCarga;
    public $precioUnitario;
    public $TotalAPagar;
    public $totalIngresado;
    public $totalVenta;
    public $Volumen;
    public $fechaHora;
    public $formaPago;
    public $terminacionPago;
    public $folio;
    public $autorizacion;
    public $estado_switch;
    public $no_tarjeta;
    public $tipoTarjeta;
    public $MarcaTarjeta;
    public $Emisor;
    public $nombre_impreso_tarjeta;
    public $voucher_tarjeta;
    public $respuesta_switch;
    public $created_at;
    public $updated_at;


    public function get_rows($from, $until) : array|false {
        set_time_limit(0);
        ini_set('max_execution_time', 0);
        
        $from = $from;
        $until = date('Y-m-d', strtotime($until . ' +1 day'));

        $query = "
                DECLARE @from DATE = '{$from}';
                DECLARE @until DATE = '{$until}';
                SELECT
                    *, CONVERT(date, fechaHora) AS fecha, CONVERT(time, fechaHora, 0) AS hora,
                    SUBSTRING(
                        respuesta_switch, 
                        CHARINDEX('Autorizacion:', respuesta_switch) + LEN('Autorizacion:'), 
                        CHARINDEX(';', respuesta_switch + ';', CHARINDEX('Autorizacion:', respuesta_switch)) - CHARINDEX('Autorizacion:', respuesta_switch) - LEN('Autorizacion:')
                    ) AS Autorizacion,
                    SUBSTRING(
                        respuesta_switch, 
                        CHARINDEX('Referencia:', respuesta_switch) + LEN('Referencia:'), 
                        CHARINDEX(';', respuesta_switch + ';', CHARINDEX('Referencia:', respuesta_switch)) - CHARINDEX('Referencia:', respuesta_switch) - LEN('Referencia:')
                    ) AS Referencia,
                    LEFT(TRY_CAST(
                        REPLACE(
                            REPLACE(
                                REPLACE(
                                    REPLACE(voucher_tarjeta, '&', '&amp;'),
                                '><', '> <'),
                            '<br>', ''),
                        '<br/>', '') 
                    AS XML).value('(/div/p[7]/text())[1]', 'NVARCHAR(100)'), 8) AS afiliacion_bancaria
                FROM
                    [interlogic].[dbo].[payments]
                WHERE 
                    fechaHora BETWEEN @from AND @until
                ORDER BY fechaHora DESC;";
        return ($this->sql->select($query, [])) ?: false ;
    }

    function get_voucher($id) : array|false {
        $query = "SELECT voucher_tarjeta, numDespacho FROM [interlogic].[dbo].[payments] WHERE id = ?;";
        return ($rs = $this->sql->select($query, [$id])) ? $rs[0] : false ;
    }
}

