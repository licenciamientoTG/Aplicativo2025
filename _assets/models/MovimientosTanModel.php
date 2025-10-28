<?php
class MovimientosTanModel extends Model{

    function sp_obtener_recepciones_combustible($fchtrn, $codgas, $codprd) {
        $params = array(
            'ip_server'    => $this->linked_server[$codgas],
            'database'     => $this->short_databases[$codgas],
            'fchtrn'       => dateToInt($fchtrn),
            'codgas'       => $codgas,
            'codprd'       => $codprd
        );

        $query = "
        SELECT *
            FROM OPENQUERY(" . $params['ip_server'] . ", '
                SELECT
                    M.nrotrn,CONVERT(DATE, DATEADD(DAY, -1, M.fchtrn)) AS fecha,
                    CAST(CONVERT(TIME, DATEADD(MINUTE, M.hratrn % 100, DATEADD(HOUR, M.hratrn / 100, 0))) AS TIME(0)) AS hora,
                    M.volrec AS VolumenRecibido,
                    M.fchtrn,T.den, T.codprd,M.nroitm,M.tiptrn,M.vol,M.volCxT,M.volh2o,M.tipdoc,M.nrodoc, M.nroarc,M.nroarcrec,M.nrotrnrec,
                    M.codgas,M.codtan,M.hratrn,
                    D.nro AS nrodoc_documentos,
                    D.can VolumenFacturado,D.pre,D.mto,D.mtoiva,D.mtoiie,DC.txtref,
                    DC.logfch,DC.satdat,DC.codopr ProveedorId,
	                P.nropcc ProveedorCRE
                FROM " . $params['database'] . ".[MovimientosTan] M
                    LEFT JOIN " . $params['database'] . ".[Tanques] T ON M.codtan = T.cod AND M.codgas = T.codgas
                    LEFT JOIN " . $params['database'] . ".[Documentos] D ON M.nrodoc = D.nro AND M.codgas = D.codgas AND D.tip = 1 AND D.nroitm = 1
                    LEFT JOIN " . $params['database'] . ".[DocumentosC] DC ON M.nrodoc = DC.nro AND M.codgas = DC.codgas AND DC.tip = 1
                    LEFT JOIN " . $params['database'] . ".[Proveedores] P ON DC.codopr = P.cod
                WHERE
                    M.nroitm NOT IN (0,1,3,4)
                    AND M.tiptrn = 3
                    AND M.fchtrn = " . $params['fchtrn'] . "
                    AND M.codgas = " . $params['codgas'] . "
                    AND T.codprd = " . $params['codprd'] . "
                ORDER BY
                    M.nrotrn DESC
            ');
        ";

        return $this->sql->select($query);
    }

    function buscarPorUUID($uuidsCadena) : array | false {
        $query = "
            SELECT
                t1.nro AS Documento,
                t3.abr Estacion,
                CONVERT(DATE, DATEADD(DAY, -1, t1.fch)) AS Fecha,
                t4.den Proveedor,
                t1.satuid,
                t1.satdat,
                t1.txtref,
                -- 🔹 FACTURA (entre @F: y @R: o @V:)
                LTRIM(RTRIM(
                    SUBSTRING(
                        CAST(t1.txtref AS VARCHAR(MAX)),
                        CHARINDEX('@F:', CAST(t1.txtref AS VARCHAR(MAX))) + 3,
                        CASE 
                            WHEN CHARINDEX('@R:', CAST(t1.txtref AS VARCHAR(MAX))) > 0 
                                THEN CHARINDEX('@R:', CAST(t1.txtref AS VARCHAR(MAX))) - (CHARINDEX('@F:', CAST(t1.txtref AS VARCHAR(MAX))) + 3)
                            WHEN CHARINDEX('@V:', CAST(t1.txtref AS VARCHAR(MAX))) > 0 
                                THEN CHARINDEX('@V:', CAST(t1.txtref AS VARCHAR(MAX))) - (CHARINDEX('@F:', CAST(t1.txtref AS VARCHAR(MAX))) + 3)
                            ELSE LEN(CAST(t1.txtref AS VARCHAR(MAX)))
                        END
                    )
                )) AS Factura,
                -- 🔹 REMISIÓN (entre @R: y @V:)
                LTRIM(RTRIM(
                    SUBSTRING(
                        CAST(t1.txtref AS VARCHAR(MAX)),
                        CHARINDEX('@R:', CAST(t1.txtref AS VARCHAR(MAX))) + 3,
                        CASE 
                            WHEN CHARINDEX('@V:', CAST(t1.txtref AS VARCHAR(MAX))) > 0 
                                THEN CHARINDEX('@V:', CAST(t1.txtref AS VARCHAR(MAX))) - (CHARINDEX('@R:', CAST(t1.txtref AS VARCHAR(MAX))) + 3)
                            ELSE LEN(CAST(t1.txtref AS VARCHAR(MAX)))
                        END
                    )
                )) AS Remision,
                -- 🔹 VEHÍCULO (desde @V: hasta el final)
                LTRIM(RTRIM(
                    SUBSTRING(
                        CAST(t1.txtref AS VARCHAR(MAX)),
                        CHARINDEX('@V:', CAST(t1.txtref AS VARCHAR(MAX))) + 3,
                        LEN(CAST(t1.txtref AS VARCHAR(MAX)))
                    )
                )) AS Vehiculo,
                ISNULL(t2.VolRecibido, 0) AS VolRecibido,  -- 🔥 AQUÍ ESTÁ EL CAMBIO
                t1.codgas,
                t1.fch
            FROM [SG12].[dbo].[DocumentosC] t1
                LEFT JOIN (
                    SELECT nrodoc, codgas, VolRecibido
                    FROM (
                        SELECT 
                            nrodoc,
                            codgas,
                            SUM(volrec) AS VolRecibido,
                            ROW_NUMBER() OVER (
                                PARTITION BY nrodoc, codgas 
                                ORDER BY 
                                    CASE WHEN tiptrn = 3 THEN 1 WHEN tiptrn = 4 THEN 2 ELSE 3 END
                            ) AS rn
                        FROM [SG12].[dbo].[MovimientosTan]
                        WHERE tiptrn IN (3,4)
                        AND nrodoc > 0
                        GROUP BY nrodoc, codgas, tiptrn
                    ) AS t
                    WHERE rn = 1
                ) t2 ON t1.codgas = t2.codgas AND t1.nro = t2.nrodoc
                LEFT JOIN [SG12].[dbo].[Gasolineras] t3 ON t1.codgas = t3.cod
                LEFT JOIN [SG12].[dbo].[Proveedores] t4 ON t1.codopr = t4.cod
            WHERE t1.satuid IN ({$uuidsCadena});
        ";
        return ($rs=$this->sql->select($query, [])) ? $rs : false ;
        
    }
}