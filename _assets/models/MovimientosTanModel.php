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
                    t1.nrotrn,
                    CONVERT(DATE, DATEADD(DAY, -1, t1.fchtrn)) AS fecha,
                    CAST(CONVERT(TIME, DATEADD(MINUTE, t1.hratrn % 100, DATEADD(HOUR, t1.hratrn / 100, 0))) AS TIME(0)) AS hora,
                    t1.volrec AS VolumenRecibido,
                    t1.fchtrn,
                    t2.den,
                    t2.codprd,
                    t1.nroitm,
                    t1.tiptrn,
                    t1.vol,
                    t1.volCxT,
                    t1.volh2o,
                    t1.tipdoc,
                    t1.nrodoc,
                    t1.nroarc,
                    t1.nroarcrec,
                    t1.nrotrnrec
                FROM " . $params['database'] . ".MovimientosTan t1
                LEFT JOIN " . $params['database'] . ".Tanques t2 ON t1.codtan = t2.cod
                WHERE
                    t1.nroitm NOT IN (0,1,3,4)
                    AND t1.fchtrn = " . $params['fchtrn'] . "
                    AND t1.codgas = " . $params['codgas'] . "
                    AND t2.codprd = " . $params['codprd'] . "
                ORDER BY
                    t1.nrotrn DESC
            ');
        ";

        return $this->sql->select($query);
    }
}