<?php
class ComprasPetrotalModel extends Model {
    public $id;
    public $anio;
    public $mes_deuda;
    public $fecha;
    public $factura;
    public $num_estacion;
    public $razon_social;
    public $estacion;
    public $cre_estacion;
    public $fecha_descarga;
    public $proveedor;
    public $codigo_proveedor;
    public $cre_proveedor;
    public $combustible;
    public $factor_ieps;
    public $litros;
    public $precio;
    public $precio_litro;
    public $subtotal_con_ieps;
    public $ieps;
    public $subtotal_sin_ieps;
    public $iva;
    public $total;
    public $costo;
    public $factura_compra;
    public $utilidad_perdida;
    public $monto_pagado;
    public $iva_pagado;
    public $fecha_pago;
    public $uuid;
    public $tasa_iva;
    public $indicador_1;

    // Obtiene registros por mes y año
    function getCompras($mes, $anio) {
        $query = "
            SELECT * 
            FROM [dbo].[ERComprasPetrotal]
            WHERE mes_deuda = ? AND anio = ?
        ";
        $params = [$mes, $anio];
        return ($this->sql->select($query, $params)) ?: false;
    }

    function get_compras_by_fecha($from,$until){
        $query = "
            SELECT *
            FROM [TGV2].[dbo].[ERComprasPetrotal]
            WHERE [fecha] between ? and ? ";
        $params = [$from, $until];
        return ($this->sql->select($query,$params)) ?: false;
    }

    // Inserta múltiples registros
    function insertCompras(array $data) {
        try {
            $this->sql->beginTransaction();
            $query = "
                INSERT INTO [TGV2].[dbo].[ERComprasPetrotal] (
                    anio, mes_deuda, fecha, factura, num_estacion, razon_social,
                    estacion, cre_estacion, fecha_descarga, proveedor, codigo_proveedor,
                    cre_proveedor, combustible, factor_ieps, litros, precio, precio_litro,
                    subtotal_con_ieps, ieps, subtotal_sin_ieps, iva, total, costo,
                    factura_compra, utilidad_perdida, monto_pagado, iva_pagado,
                    fecha_pago, uuid, tasa_iva, indicador_1
                ) VALUES (
                    ?,?,?,?,?,?,
                    ?,?,?,?,?,?,
                    ?,?,?,?,?,?,
                    ?,?,?,?,?,?,    
                    ?,?,?,?,?,?,
                    ?
                )
            ";
            foreach ($data as $row) {
                
               $params = [
                    $row['anio'],
                    $row['mes_deuda'],
                    $row['fecha'],
                    $row['factura'],
                    $row['num_estacion'],
                    $row['razon_social'],
                    $row['estacion'],
                    $row['cre_estacion'],
                    $row['fecha_descarga'],
                    $row['proveedor'],
                    $row['codigo_proveedor'],
                    $row['cre_proveedor'],
                    $row['combustible'],
                    $row['factor_ieps'],
                    $row['litros'],
                    $row['precio'],
                    $row['precio_litro'],
                    $row['subtotal_con_ieps'],
                    $row['ieps'],
                    $row['subtotal_sin_ieps'],
                    $row['iva'],
                    $row['total'],
                    $row['costo'],
                    $row['factura_compra'],
                    $row['utilidad_perdida'],
                    $row['monto_pagado'],
                    $row['iva_pagado'],
                    $row['fecha_pago'],
                    $row['uuid'],
                    $row['tasa_iva'],
                    $row['indicador_1']
                ];
                $this->sql->insert($query, $params);
            }
            $this->sql->commit();
            return ['success' => true];
        } catch (\Exception $e) {
            $this->sql->rollBack();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
