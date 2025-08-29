<?php
class ProveedoresModel extends Model{
      public $id;
      public $id_control_gas;
      public $dias_credito;
      public $limite_credito;
      public $contacto_principal;
      public $telefono_contacto;
      public $email_contacto;
      public $condiciones_pago;
      public $observaciones;
      public $activo;
      public $fecha_alta;
      public $fecha_modificacion;
      public $usuario_id;

    
    public function get_actives(){
        $query = 'SELECT t1.*,t2.den   
                    FROM [TG].[dbo].[Proveedores] t1
                    left join SG12.dbo.Proveedores t2 on t1.id_control_gas = t2.cod WHERE t1.activo = 1';
        $params = [];
        return ($this->sql->select($query,$params)) ?: false ;
    }

    /**
     * @return array|false
     * @throws Exception
     */
    public function get_rows() : array|false {
        $query = ' WITH cte AS (
                        SELECT
                            t1.nro,
                            CASE 
                                WHEN CHARINDEX(\'@F:\', CAST(t1.txtref AS VARCHAR(MAX))) > 0 THEN
                                    SUBSTRING(
                                        CAST(t1.txtref AS VARCHAR(MAX)),
                                        CHARINDEX(\'@F:\', CAST(t1.txtref AS VARCHAR(MAX))) + 3,
                                        CHARINDEX(\'@\', CAST(t1.txtref AS VARCHAR(MAX)) + \'@\', CHARINDEX(\'@F:\', CAST(t1.txtref AS VARCHAR(MAX))) + 3)
                                        - (CHARINDEX(\'@F:\', CAST(t1.txtref AS VARCHAR(MAX))) + 3)
                                    )
                                ELSE NULL
                            END AS Factura,
                            CASE 
                                WHEN CHARINDEX(\'@R:\', CAST(t1.txtref AS VARCHAR(MAX))) > 0 THEN
                                    SUBSTRING(
                                        CAST(t1.txtref AS VARCHAR(MAX)),
                                        CHARINDEX(\'@R:\', CAST(t1.txtref AS VARCHAR(MAX))) + 3,
                                        CHARINDEX(\'@\',
                                            CAST(t1.txtref AS VARCHAR(MAX)) + \'@\',
                                            CHARINDEX(\'@R:\', CAST(t1.txtref AS VARCHAR(MAX))) + 3
                                        )
                                        - (CHARINDEX(\'@R:\', CAST(t1.txtref AS VARCHAR(MAX))) + 3)
                                    )
                                ELSE NULL
                            END AS Remision,
                            CONVERT(VARCHAR(10), DATEADD(DAY, -1, t1.fch), 23) AS fecha,
                            CONVERT(VARCHAR(10), DATEADD(DAY, -1, t1.vto), 23) AS fechaVto,
                            t3.den as [producto],
                            t4.den as [proveedor],
                            t4.cod as [cod_provider],
                            t8.volrec,
                            t2.can,
                            t2.pre,
                            (t2.mto/100) as [mto],
                            (t2.mtoiie/100) as [mtoiie],
                            (t2.mtoiva/100) as [iva8],
                            (t5.mto/100) as [iva],
                            ((isnull(t2.mtoiva,0) + isnull(t5.mto,0))/100) as [iva_total],
                            t6.mto as [servicio],
                            t7.mto as [iva_servicio],
                            ((t2.mto + (isnull(t2.mtoiva,0) + isnull(t5.mto,0)) + isnull(t6.mto,0)+isnull(t7.mto,0))/100) as [total_fac],
                            t1.satuid,
                            t1.codgas,
                            t9.abr as [gasolinera]
                        FROM DocumentosC t1
                        LEFT JOIN Documentos t2 ON t1.nro = t2.nro and t1.codgas = t2.codgas and t2.codcpt in(1,2,3)
                        LEFT JOIN Documentos t5 ON t1.nro = t5.nro and t1.codgas = t5.codgas and t5.codcpt in(21,22,23)
                        LEFT JOIN Documentos t6 ON t1.nro = t6.nro and t1.codgas = t6.codgas and t6.codcpt in(18,19,20)
                        LEFT JOIN Documentos t7 ON t1.nro = t7.nro and t1.codgas = t7.codgas and t7.codcpt in(24,25,26)
                        LEFT JOIN Productos t3 ON t2.codprd = t3.cod
                        LEFT JOIN Proveedores t4 on t1.codopr = t4.cod
                        LEFT JOIN Gasolineras t9 on t1.codgas = t9.cod
                        LEFT JOIN (SELECT sum(volrec) as volrec, nrodoc FROM [MovimientosTan] where tiptrn = 4 group by nrodoc) t8 on t1.nro = t8.nrodoc 
                        WHERE 
                            t1.tip = 1 
                            AND t1.subope = 2 
                            --AND t1.fch BETWEEN 45838 AND 45893
                            and t1.satuid is not null 
                    ),
                    totales_proveedores AS (
                        SELECT 
                            cod_provider,
                            proveedor, 
                            SUM(total_fac) as total  
                        FROM cte 
                        WHERE cod_provider IS NOT NULL
                        GROUP BY cod_provider, proveedor
                    )
                    SELECT 
                        p.*,
                        ISNULL(tp.total, 0) AS total_facturado,
                        tp.proveedor,
                        p2.den
                    FROM [TG].[dbo].[Proveedores] p
                    LEFT JOIN totales_proveedores tp ON p.id_control_gas = tp.cod_provider
                    LEFT JOIN SG12.dbo.Proveedores p2 on p.id_control_gas = p2.cod';
        $params = [];
        return ($this->sql->select($query,$params)) ?: false ;
    }

}