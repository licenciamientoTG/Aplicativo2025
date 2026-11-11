<?php
/**
 * ConciliacionOneGoalModel — anticipos (serie D) de ControlGas y los CFDI que One Goal tiene de ellos.
 *
 * One Goal se consulta por servidor vinculado ([192.168.0.5].1G_TOTALGAS, el mismo que usa DocumentosModel)
 * con OPENQUERY, para filtrar en el servidor remoto. Solo lectura. Lógica de cruce: ConciliacionOneGoal.
 */
class ConciliacionOneGoalModel extends Model {

    /**
     * Anticipos serie D (tip 3, subope 18) con fecha entre $from y $until (enteros ControlGas), vigentes y cancelados.
     * Una fila por factura: total = SUM(Documentos.mto) / 100; cancelada = txtref '@N:CANCELAD%'.
     */
    public function anticipos_controlgas(int $from, int $until): array {
        $query = "
            SET NOCOUNT ON;
            SELECT c.nro,
                   LOWER(LTRIM(RTRIM(ISNULL(c.satuid, '')))) AS uuid,
                   CONVERT(varchar(10), DATEADD(day, c.fch - 1, '19000101'), 23) AS fecha,
                   c.codopr AS codcli, cl.den AS cliente,
                   ISNULL(t.total, 0) AS total,
                   CASE WHEN CAST(c.txtref AS varchar(20)) LIKE '@N:CANCELAD%' THEN 1 ELSE 0 END AS cancelada
              FROM SG12.dbo.DocumentosC c WITH (NOLOCK)
              LEFT JOIN SG12.dbo.Clientes cl WITH (NOLOCK) ON cl.cod = c.codopr
              LEFT JOIN (SELECT d.codgas, d.nro, SUM(d.mto) / 100.0 AS total
                           FROM SG12.dbo.Documentos d WITH (NOLOCK)
                          WHERE d.tip = 3 AND d.nro BETWEEN " . ConciliacionOneGoal::SERIE_D . " AND " . (ConciliacionOneGoal::SERIE_D + 99999999) . "
                          GROUP BY d.codgas, d.nro) t ON t.codgas = c.codgas AND t.nro = c.nro
             WHERE c.tip = 3 AND c.codgas = 0 AND c.subope = 18
               AND c.nro BETWEEN " . ConciliacionOneGoal::SERIE_D . " AND " . (ConciliacionOneGoal::SERIE_D + 99999999) . "
               AND c.fch BETWEEN ? AND ?
             ORDER BY c.fch, c.nro
            OPTION (RECOMPILE);";
        return $this->sql->select($query, [$from, $until]) ?: [];
    }

    /**
     * CFDI de serie D en One Goal timbrados entre $desde y $hasta (Y-m-d). Se amplía la ventana unos días para
     * ligar anticipos timbrados fuera del mes (por UUID); las filas fuera del mes solo se devuelven si su UUID
     * está en $uuids (los de ControlGas), así "Solo en One Goal" se limita al mes.
     */
    public function cfdi_onegoal(string $desde, string $hasta, array $uuids): array {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) return [];
        $ini = date('Ymd', strtotime($desde . ' -5 days'));
        $fin = date('Ymd', strtotime($hasta . ' +11 days'));   // tope exclusivo
        $query = "
            SET NOCOUNT ON;
            SELECT LOWER(imp_uuid) AS uuid, imp_xml AS archivo, imp_nom1 AS cliente, imp_rfc AS rfc,
                   CONVERT(varchar(10), imp_fec_tim_pac, 23) AS fecha,
                   imp_id_fact AS id_fact, imp_id_clie AS id_clie, imp_cp AS cp, imp_codsat_uso_cfdi AS uso_cfdi,
                   imp_mtd_pag AS metodo_pago, imp_tip_pag AS forma_pago, imp_reg_fis AS regimen, imp_mail AS correo,
                   imp_cfd_status AS estatus_cfdi
              FROM OPENQUERY([192.168.0.5], 'SELECT imp_uuid, imp_xml, imp_nom1, imp_rfc, imp_fec_tim_pac, imp_id_fact, imp_id_clie,
                            imp_cp, imp_codsat_uso_cfdi, imp_mtd_pag, imp_tip_pag, imp_reg_fis, imp_mail, imp_cfd_status
                     FROM [1G_TOTALGAS].dbo.imp_vta_fac_enc_clie WITH (NOLOCK)
                    WHERE imp_fec_tim_pac >= ''{$ini}'' AND imp_fec_tim_pac < ''{$fin}''
                      AND imp_xml LIKE ''%[_]CFDI[_]D%''');";
        $conocidos = array_flip($uuids);
        $mes = [];
        foreach ($this->sql->select($query) ?: [] as $r) {
            $r['uuid'] = strtolower(trim((string)$r['uuid']));
            if ($r['fecha'] >= $desde && $r['fecha'] <= $hasta || isset($conocidos[$r['uuid']])) $mes[] = $r;
        }
        return $mes;
    }

    /**
     * Facturas serie D de One Goal (imp_fac_enc) con fecha entre $desde y $hasta (Y-m-d), con la misma ventana ampliada que
     * cfdi_onegoal. nro = imp_id_fac_sust = DocumentosC.nro de ControlGas; status 1 vigente, 3 cancelada, -1 sin timbrar.
     * Las que están fuera del mes solo se devuelven si su nro está en $nros (anticipos de ControlGas del mes).
     */
    public function facturas_onegoal(string $desde, string $hasta, array $nros): array {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) return [];
        $ini = date('Ymd', strtotime($desde . ' -5 days'));
        $fin = date('Ymd', strtotime($hasta . ' +11 days'));
        $query = "
            SET NOCOUNT ON;
            SELECT imp_id_fac AS id_fac, imp_id_fac_sust AS nro, imp_numero AS numero,
                   CONVERT(varchar(10), imp_fecha, 23) AS fecha, imp_sub AS sub, imp_imptos AS iva, imp_total AS total,
                   imp_status AS status, imp_status_importacion AS status_imp, imp_id_cli AS id_cli, mensaje
              FROM OPENQUERY([192.168.0.5], 'SELECT imp_id_fac, imp_id_fac_sust, imp_numero, imp_fecha, imp_sub, imp_imptos, imp_total,
                            imp_status, imp_status_importacion, imp_id_cli, CAST(imp_cmt_imp AS varchar(200)) AS mensaje
                     FROM [1G_TOTALGAS].dbo.imp_fac_enc WITH (NOLOCK)
                    WHERE imp_fecha >= ''{$ini}'' AND imp_fecha < ''{$fin}'' AND imp_serie = ''D''');";
        $conocidos = array_flip($nros);
        $mes = [];
        foreach ($this->sql->select($query) ?: [] as $r) {
            if (($r['fecha'] >= $desde && $r['fecha'] <= $hasta) || isset($conocidos[(int)$r['nro']])) $mes[] = $r;
        }
        return $mes;
    }
}
