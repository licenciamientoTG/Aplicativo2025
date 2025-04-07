<?php
class CXpPagosModel{
    public $sql;
    public $num_doc;
    



    function __construct() {
        $this->sql = MySqlPdoHandler::getInstance();
        $this->sql->connect('1G_TOTALGAS', '192.168.0.5', 'sa', 'mEiLsS121806');
        $this->sql->Query("SET NAMES utf8");
        $this->sql->Query("SET GLOBAL group_concat_max_len=15000");
    }

    function get_rows(){
        $query = "Select
                    t1.num_doc,
                    t2.clave,t2.id_prov,t2.nom1,
                    t5.num as cuenta,
                    t6.nom as banco,
                    t1.num_doc_cli AS Ref_num,
                    t1.ref_ben,
                    t1.fecha,
                    t1.monto,
                    t8.id_doc AS \'folio\',
                    t8.fec_doc,
                    t8.importe,
                    t8.imptos,
                    t8.total,
                    t8.aplicado,
                    t8.ptg_apl,   
                    t8.uuid_i,
                    \'Control\' as \'control\',
                    CONVERT(DATE, DATEADD(DAY, -1, t9.fch)) AS \'Fecha_control\',
                    CONVERT(DATE, DATEADD(DAY, -1, t9.vto)) AS \'Fecha_vencimiento\',
                    t9.can,
                    t9.pre,
                    t9.mto,t9.mtoiva,t9.codgas,t9.codprd,
                    t9.den as producto,t9.abr as estacion
                    from [1G_TOTALGAS].dbo.cxp_pagos t1
                    LEFT JOIN [1G_TOTALGAS].dbo.cat_prov t2 on t1.id_prov = t2.id_prov
                    LEFT JOIN [1G_TOTALGAS].dbo.bco_cuentas t5 on t1.id_cta = t5.id_cta-----cuenta
                    LEFT JOIN [1G_TOTALGAS].dbo.bco_bancos t6 on t5.id_bco = t6.id_bco-----banco
                    left join [1G_TOTALGAS].dbo.bco_iva_aux t8 on t1.id_pago = t8.id_pag
                    LEFT JOIN (
                        SELECT dc.satuid, dc.fch, dc.vto, dc.nro, t10.can,t10.pre,
                    t10.mto,t10.mtoiva,t10.codgas,t10.codprd,t11.den,t12.abr
                        FROM [192.168.0.6].SG12.dbo.documentosC dc
                        LEFT JOIN [192.168.0.6].SG12.dbo.documentos t10 on dc.nro =t10.nro and dc.codgas = t10.codgas and t10.nroitm = 1 AND t10.tip = 1
                        LEFT JOIN [192.168.0.6].SG12.dbo.Productos t11 on t10.codprd = t11.cod
                        LEFT JOIN [192.168.0.6].SG12.dbo.gasolineras t12 on t10.codgas = t12.cod
                        WHERE satuid IS NOT NULL and dc.tipopr = 3 and dc.tip = 1 and dc.tipref = 103
                    ) AS t9 ON t8.uuid_i COLLATE Modern_Spanish_CI_AS = t9.satuid COLLATE Modern_Spanish_CI_AS
                    --LEFT JOIN [192.168.0.6].SG12.dbo.documentos t10 on t9.nro =t10.nro and t9.codgas = t10.codgas and t10.nroitm = 1 AND t10.tip = 1
                    --LEFT JOIN [192.168.0.6].SG12.dbo.Productos t11 on t10.codprd = t11.cod
                    --LEFT JOIN [192.168.0.6].SG12.dbo.gasolineras t12 on t10.codgas = t12.cod
                    where  
                    --t1.num_doc =81009
                    t1.fecha BETWEEN \'2024-01-01T00:00:00.001\' and \'2024-01-31T23:59:59.000\'
                    and t1.id_prov  in( 1390, 2187, 837, 1838, 1640)";
        $params = [];
        return ($rs = $this->sql->select($query,$params)) ? $rs : false ;
    }
}