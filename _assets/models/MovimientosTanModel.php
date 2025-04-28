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

        // if ($_SESSION['tg_user']['Id'] == 6177) {
        //     if ($codgas = 2 AND $codprd = 179) {
        //         echo '<pre>';
        //         var_dump($query);
        //         die();
        //     }
        // }

        return $this->sql->select($query);
    }
}