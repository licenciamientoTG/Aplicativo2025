<?php

/**
 * Consulta de evidencia importada por cron/efc_conc_analiticos_diario.py.
 * Esta clase nunca extrae correos ni interpreta Excel; sólo administra
 * vínculos informativos y capas de corrección trazables en TG.
 */
class EfcAnaliticosModel {
    private PDO $db;

    public function __construct() {
        $this->db = new PDO('sqlsrv:Server=192.168.0.6;Database=TG;TrustServerCertificate=yes', 'cguser', 'sahei1712');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->ensureLinksSchema();
    }

    /** Evidencia informativa; no altera conciliaciones ni fuentes operativas. */
    public function evidence(int $stationId, string $date, ?float $cgAmount, ?float $bankAmount): array {
        if (!$stationId || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new RuntimeException('Estación o fecha inválida.');
        $stmt = $this->db->prepare("SELECT TOP 8 P.id,P.effective_date AS fecha_reportada,P.effective_time AS hora_original,P.effective_remittance AS remesa_numero,P.effective_account AS cuenta_mn_original,P.effective_declared_mn AS dice_contener_mn,P.effective_real_mn AS real_mn,P.effective_difference_mn AS diferencia_mn,P.effective_declared_usd AS dice_contener_usd,P.effective_real_usd AS real_usd,P.effective_difference_usd AS diferencia_usd,I.nombre_archivo FROM dbo.v_efc_conc_analiticos_papeletas_efectivas P JOIN dbo.efc_conc_analiticos_importaciones I ON I.id=P.importacion_id WHERE P.effective_station_id=? AND P.effective_date BETWEEN DATEADD(day,-7,?) AND DATEADD(day,7,?) AND I.estado='IMPORTADA' ORDER BY ABS(DATEDIFF(day,P.effective_date,?)),P.id DESC");
        $stmt->execute([$stationId,$date,$date,$date]);
        $items=[];
        while ($row=$stmt->fetch(PDO::FETCH_ASSOC)) {
            $reasons=['Misma estación'];
            $paperDate=$this->dateValue($row['fecha_reportada']);
            if ($paperDate === $date) $reasons[]='Misma fecha';
            $targets=array_filter([$cgAmount,$bankAmount],static fn($value)=>$value!==null);
            foreach ($targets as $target) foreach ([(float)($row['dice_contener_mn'] ?? 0),(float)($row['real_mn'] ?? 0)] as $amount) if ($amount > 0 && abs($amount-$target) <= 20) { $reasons[]='Importe cercano'; break 2; }
            $items[]=['id'=>(int)$row['id'],'date'=>$paperDate,'time'=>$row['hora_original'],'remittance'=>$this->normaliseRemittance($row['remesa_numero']),'account'=>$row['cuenta_mn_original'],'declared_mn'=>(float)($row['dice_contener_mn'] ?? 0),'real_mn'=>(float)($row['real_mn'] ?? 0),'difference_mn'=>(float)($row['diferencia_mn'] ?? 0),'declared_usd'=>(float)($row['dice_contener_usd'] ?? 0),'real_usd'=>(float)($row['real_usd'] ?? 0),'difference_usd'=>(float)($row['diferencia_usd'] ?? 0),'file'=>$row['nombre_archivo'],'reasons'=>$reasons];
        }
        return $items;
    }

    /** Papeletas del periodo para el modo informativo CG vs Analíticos. */
    public function period(int $stationId, int $year, int $month): array {
        if (!$stationId || $year < 2020 || $month < 1 || $month > 12) throw new RuntimeException('Estación o periodo inválidos.');
        $stmt=$this->db->prepare("SELECT P.id,P.importacion_id,P.effective_date AS fecha_reportada,P.effective_time AS hora_original,P.effective_remittance AS remesa_numero,P.effective_account AS cuenta_mn_original,P.effective_declared_mn AS dice_contener_mn,P.effective_real_mn AS real_mn,P.effective_difference_mn AS diferencia_mn,P.effective_declared_usd AS dice_contener_usd,P.effective_real_usd AS real_usd,P.effective_difference_usd AS diferencia_usd,I.nombre_archivo FROM dbo.v_efc_conc_analiticos_papeletas_efectivas P JOIN dbo.efc_conc_analiticos_importaciones I ON I.id=P.importacion_id WHERE P.effective_station_id=? AND YEAR(P.effective_date)=? AND MONTH(P.effective_date)=? AND I.estado='IMPORTADA' ORDER BY P.effective_date,P.id");
        $stmt->execute([$stationId,$year,$month]); $out=[];
        while($row=$stmt->fetch(PDO::FETCH_ASSOC)) $out[]=['id'=>(int)$row['id'],'importation_id'=>(int)$row['importacion_id'],'date'=>$this->dateValue($row['fecha_reportada']),'time'=>$row['hora_original'],'remittance'=>$this->normaliseRemittance($row['remesa_numero']),'account'=>$row['cuenta_mn_original'],'declared_mn'=>(float)($row['dice_contener_mn']??0),'real_mn'=>(float)($row['real_mn']??0),'difference_mn'=>(float)($row['diferencia_mn']??0),'declared_usd'=>(float)($row['dice_contener_usd']??0),'real_usd'=>(float)($row['real_usd']??0),'difference_usd'=>(float)($row['diferencia_usd']??0),'file'=>$row['nombre_archivo']];
        return $out;
    }

    /** Archivo original conservado por el importador Python; sólo lectura. */
    public function originalFile(int $importationId): array {
        if ($importationId < 1) throw new RuntimeException('Importación inválida.');
        $stmt=$this->db->prepare("SELECT nombre_archivo,mime_type,archivo FROM dbo.efc_conc_analiticos_importaciones WHERE id=? AND estado='IMPORTADA'");
        $stmt->execute([$importationId]);
        $file=$stmt->fetch(PDO::FETCH_ASSOC);
        if (!$file || $file['archivo'] === null) throw new RuntimeException('El archivo original no está disponible.');
        return $file;
    }

    /** Papeletas disponibles sin imponer fecha; la fecha sólo es trazabilidad. */
    public function workspace(int $stationId, int $year, int $month, ?string $company=null): array {
        if (!$stationId || $year < 2020 || $month < 1 || $month > 12) throw new RuntimeException('Estación o periodo inválidos.');
        $period=sprintf('%04d-%02d',$year,$month);
        $monthStart=sprintf('%04d-%02d-01',$year,$month);
        $monthEnd=(new DateTimeImmutable($monthStart))->modify('+1 month')->format('Y-m-d');
        $workspaceStarted=hrtime(true);
        $gasomex=strtoupper(trim((string)$company))==='GASOMEX';
        $range=$gasomex
            ? "P.effective_date>=DATEADD(day,-2,DATEFROMPARTS(?, ?, 1)) AND P.effective_date<DATEADD(day,2,EOMONTH(DATEFROMPARTS(?, ?, 1)))"
            : "P.effective_date>=DATEFROMPARTS(?, ?, 1) AND P.effective_date<DATEADD(day,2,EOMONTH(DATEFROMPARTS(?, ?, 1)))";
        $linkRange=$gasomex
            ? "V.fecha_cg>=DATEADD(day,-2,DATEFROMPARTS(?, ?, 1)) AND V.fecha_cg<DATEADD(day,2,EOMONTH(DATEFROMPARTS(?, ?, 1)))"
            : "V.fecha_cg>=? AND V.fecha_cg<?";
        $paperLinkRange=str_replace('V.','R.',$linkRange);
        $paperLinkRangeSecondary=str_replace('R.','R2.',$paperLinkRange);
        // Un tránsito sigue siendo el turno de su mes de origen. Al abrir el
        // mes receptor traemos además su papeleta y vínculo originales, sin
        // duplicarlos ni alterar la fecha persistida del vínculo.
        // Primero se obtienen candidatos por mes desde ramas independientes. Evita
        // que los OR correlacionados mezclen toda la tabla de vínculos en un scan.
        $paperCandidates=$this->db->prepare("SELECT P.id FROM dbo.v_efc_conc_analiticos_papeletas_efectivas P JOIN dbo.efc_conc_analiticos_importaciones I ON I.id=P.importacion_id WHERE I.estado='IMPORTADA' AND ($range)
            UNION SELECT V.papeleta_id FROM dbo.efc_conc_analiticos_vinculos V WHERE V.estacion_id=? AND V.activo=1 AND V.papeleta_id IS NOT NULL AND ($linkRange)
            UNION SELECT V.papeleta_secundaria_id FROM dbo.efc_conc_analiticos_vinculos V WHERE V.estacion_id=? AND V.activo=1 AND V.papeleta_secundaria_id IS NOT NULL AND ($linkRange)
            UNION SELECT V.papeleta_id FROM dbo.efc_conc_analiticos_vinculos V JOIN dbo.efc_conc_transitos T ON T.estacion_id=V.estacion_id AND T.fecha_origen=V.fecha_cg AND T.turno=V.turno AND T.concepto=V.concepto WHERE V.activo=1 AND V.papeleta_id IS NOT NULL AND T.estacion_id=? AND T.mes_destino=? AND T.estado IN ('PENDIENTE','CONCILIADO')
            UNION SELECT V.papeleta_secundaria_id FROM dbo.efc_conc_analiticos_vinculos V JOIN dbo.efc_conc_transitos T ON T.estacion_id=V.estacion_id AND T.fecha_origen=V.fecha_cg AND T.turno=V.turno AND T.concepto=V.concepto WHERE V.activo=1 AND V.papeleta_secundaria_id IS NOT NULL AND T.estacion_id=? AND T.mes_destino=? AND T.estado IN ('PENDIENTE','CONCILIADO')");
        $dateParams=[$year,$month,$year,$month];
        $linkParams=$gasomex?[$stationId,$year,$month,$year,$month]:[$stationId,$monthStart,$monthEnd];
        $started=hrtime(true);
        $paperCandidates->execute(array_merge($dateParams,$linkParams,$linkParams,[$stationId,$period,$stationId,$period]));
        $candidateIds=array_values(array_unique(array_map('intval',$paperCandidates->fetchAll(PDO::FETCH_COLUMN))));
        $timings=['papers_query_ms'=>round((hrtime(true)-$started)/1e6,2),'paper_candidate_rows'=>count($candidateIds)];
        $started=hrtime(true);
        $paperRows=[]; $candidateStations=[];
        foreach(array_chunk($candidateIds,400) as $idBatch) {
            $marks=implode(',',array_fill(0,count($idBatch),'?'));
            $q=$this->db->prepare("SELECT P.id,P.effective_station_id FROM dbo.v_efc_conc_analiticos_papeletas_efectivas P JOIN dbo.efc_conc_analiticos_importaciones I ON I.id=P.importacion_id WHERE P.id IN ($marks) AND I.estado='IMPORTADA'");
            $q->execute($idBatch); foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row)$candidateStations[(int)$row['id']]=(int)$row['effective_station_id'];
        }
        $linkedIds=[]; $stationLinkedIds=[];
        foreach(array_chunk(array_keys($candidateStations),400) as $idBatch) {
            $marks=implode(',',array_fill(0,count($idBatch),'?'));
            foreach(['papeleta_id','papeleta_secundaria_id'] as $key) {
                $q=$this->db->prepare("SELECT V.$key AS paper_id,V.estacion_id FROM dbo.efc_conc_analiticos_vinculos V WHERE V.activo=1 AND V.$key IN ($marks)");
                $q->execute($idBatch);
                foreach($q->fetchAll(PDO::FETCH_ASSOC) as $link) { $pid=(int)$link['paper_id']; $linkedIds[$pid]=true; if((int)$link['estacion_id']===$stationId)$stationLinkedIds[$pid]=true; }
            }
        }
        $eligibleIds=[]; foreach($candidateStations as $id=>$effectiveStation) if($effectiveStation===$stationId || isset($stationLinkedIds[$id]))$eligibleIds[]=$id;
        foreach(array_chunk($eligibleIds,400) as $idBatch) {
            $marks=implode(',',array_fill(0,count($idBatch),'?'));
            $q=$this->db->prepare("SELECT P.id,P.importacion_id,P.source_date AS fecha_original,P.effective_date AS fecha_efectiva,P.effective_station_id,P.effective_time AS hora_original,P.effective_remittance AS remesa_numero,P.effective_account AS cuenta_mn_original,P.effective_declared_mn AS dice_contener_mn,P.effective_real_mn AS real_mn,P.effective_declared_usd AS dice_contener_usd,P.effective_real_usd AS real_usd,I.nombre_archivo FROM dbo.v_efc_conc_analiticos_papeletas_efectivas P JOIN dbo.efc_conc_analiticos_importaciones I ON I.id=P.importacion_id WHERE P.id IN ($marks) AND I.estado='IMPORTADA' ORDER BY P.id DESC");
            $q->execute($idBatch); array_push($paperRows,...$q->fetchAll(PDO::FETCH_ASSOC));
        }
        usort($paperRows,static fn($a,$b)=>(int)$b['id']<=>(int)$a['id']);
        $timings['papers_hydrate_ms']=round((hrtime(true)-$started)/1e6,2);
        $started=hrtime(true); $items=[];
        foreach($paperRows as $row) $items[]=['id'=>(int)$row['id'],'importation_id'=>(int)$row['importacion_id'],'date'=>$this->dateValue($row['fecha_efectiva'] ?: $row['fecha_original']),'original_date'=>$this->dateValue($row['fecha_original']),'effective_date'=>$row['fecha_efectiva']?$this->dateValue($row['fecha_efectiva']):null,'time'=>$row['hora_original'],'remittance'=>$this->normaliseRemittance($row['remesa_numero']),'account'=>$row['cuenta_mn_original'],'declared_mn'=>(float)($row['dice_contener_mn']??0),'real_mn'=>(float)($row['real_mn']??0),'declared_usd'=>(float)($row['dice_contener_usd']??0),'real_usd'=>(float)($row['real_usd']??0),'linked'=>isset($linkedIds[(int)$row['id']]),'file'=>$row['nombre_archivo']];
        $timings['papers_map_ms']=round((hrtime(true)-$started)/1e6,2);
        $links=$this->db->prepare("SELECT V.id,V.papeleta_id,V.papeleta_secundaria_id,V.fecha_cg,V.turno,V.concepto,V.importe_cg,V.criterio,V.tipo_cambio_usd AS exchange_rate FROM dbo.efc_conc_analiticos_vinculos V WHERE V.estacion_id=? AND V.activo=1 AND (($linkRange) OR EXISTS(SELECT 1 FROM dbo.efc_conc_transitos T WHERE T.estacion_id=V.estacion_id AND T.fecha_origen=V.fecha_cg AND T.turno=V.turno AND T.concepto=V.concepto AND T.mes_destino=? AND T.estado IN ('PENDIENTE','CONCILIADO')))");
        $started=hrtime(true);
        $links->execute($gasomex?[$stationId,$year,$month,$year,$month,$period]:[$stationId,$monthStart,$monthEnd,$period]);
        $linkRows=$links->fetchAll(PDO::FETCH_ASSOC);
        $timings['links_query_ms']=round((hrtime(true)-$started)/1e6,2);
        $blocked=$this->db->prepare("SELECT V.fecha_cg,V.turno,V.concepto FROM dbo.efc_conc_analiticos_vinculos V WHERE V.estacion_id=? AND V.activo=0 AND V.bloqueado_auto=1 AND ((V.fecha_cg>=? AND V.fecha_cg<?) OR EXISTS(SELECT 1 FROM dbo.efc_conc_transitos T WHERE T.estacion_id=V.estacion_id AND T.fecha_origen=V.fecha_cg AND T.turno=V.turno AND T.concepto=V.concepto AND T.mes_destino=? AND T.estado IN ('PENDIENTE','CONCILIADO'))) AND NOT EXISTS (SELECT 1 FROM dbo.efc_conc_analiticos_vinculos A WHERE A.activo=1 AND A.estacion_id=V.estacion_id AND A.fecha_cg=V.fecha_cg AND A.turno=V.turno AND A.concepto=V.concepto)");
        $started=hrtime(true);
        $blocked->execute([$stationId,$monthStart,$monthEnd,$period]);
        $blockedRows=$blocked->fetchAll(PDO::FETCH_ASSOC);
        $timings['blocked_query_ms']=round((hrtime(true)-$started)/1e6,2);
        $started=hrtime(true);
        foreach($linkRows as &$r) $r['paired']=!empty($r['papeleta_secundaria_id']); unset($r);
        foreach($blockedRows as $row) {$row['blocked']=1; $linkRows[]=$row;}
        $timings['links_map_ms']=round((hrtime(true)-$started)/1e6,2);
        $timings['workspace_total_ms']=round((hrtime(true)-$workspaceStarted)/1e6,2);
        $timings['papers_rows']=count($items); $timings['links_rows']=count($linkRows);
        return ['papers'=>$items,'links'=>$linkRows,'_timings_ms'=>$timings];
    }

    /**
     * Consola independiente de papeletas. La fecha efectiva es una capa propia
     * de TG: fecha_reportada y el Excel original nunca se actualizan.
     */
    public function papersForManagement(int $stationId, int $year, int $month, string $company = 'DIAZ GAS'): array {
        if ($year < 2020 || $month < 1 || $month > 12) throw new RuntimeException('Periodo inválido.');
        if (!in_array($company, ['DIAZ GAS','FORANEAS','GASOMEX'], true)) throw new RuntimeException('Empresa inválida.');
        $stationIds=EfcConciliacionModel::stationIds($company);
        if ($stationId > 0 && !in_array($stationId,$stationIds,true)) throw new RuntimeException('Estación fuera de la empresa seleccionada.');
        $stationMarks=implode(',',array_fill(0,count($stationIds),'?'));
        $sql = "SELECT P.id,P.importacion_id,P.fecha_reportada AS fecha_original,C.id AS correccion_id,C.fecha_efectiva,C.usuario_id AS correccion_usuario,C.creado_en AS correccion_creada,
                       D.id AS datos_correccion_id,D.usuario_id AS datos_correccion_usuario,D.creado_en AS datos_correccion_creada,COALESCE(D.fecha_efectiva,C.fecha_efectiva,P.fecha_reportada) fecha_actual,D.fecha_efectiva AS datos_fecha,D.hora_efectiva hora_actual,COALESCE(D.remesa_efectiva,P.remesa_numero) remesa_actual,COALESCE(D.cuenta_efectiva,P.cuenta_mn_original) cuenta_actual,
                       COALESCE(D.declarado_mn,P.dice_contener_mn) declarado_mn_actual,COALESCE(D.real_mn,P.real_mn) real_mn_actual,COALESCE(D.declarado_usd,P.dice_contener_usd) declarado_usd_actual,COALESCE(D.real_usd,P.real_usd) real_usd_actual,
                       COALESCE(D.estacion_id,S.estacion_corregida_id,P.estacion_id) estacion_actual,
                       P.hora_original,P.remesa_numero,P.cuenta_mn_original,P.dice_contener_mn,P.real_mn,P.dice_contener_usd,P.real_usd,P.estacion_id AS estacion_original_id,S.id AS estacion_correccion_id,S.estacion_corregida_id,E.Nombre AS estacion_nombre,EOriginal.Nombre AS estacion_original_nombre,I.nombre_archivo,
                       P.creado_en AS creado_en,P.creado_por,COALESCE(Creador.Nombre,Creador.Usuario,CASE WHEN P.creado_por IS NOT NULL THEN CONCAT('Usuario ',P.creado_por) END) creador_nombre,ISNULL(P.captura_manual,0) captura_manual,
                       CASE WHEN EXISTS(SELECT 1 FROM dbo.efc_conc_analiticos_vinculos V WHERE (V.papeleta_id=P.id OR V.papeleta_secundaria_id=P.id) AND V.activo=1) THEN 1 ELSE 0 END AS vinculada
                FROM dbo.efc_conc_analiticos_papeletas P
                JOIN dbo.efc_conc_analiticos_importaciones I ON I.id=P.importacion_id
                LEFT JOIN dbo.efc_conc_analiticos_correcciones_fecha C ON C.papeleta_id=P.id AND C.activo=1
                LEFT JOIN dbo.efc_conc_analiticos_correcciones_estacion S ON S.papeleta_id=P.id AND S.activo=1
                LEFT JOIN dbo.efc_conc_analiticos_correcciones_datos D ON D.papeleta_id=P.id AND D.activo=1
                LEFT JOIN TG.dbo.Usuario Creador ON Creador.Id=P.creado_por
                LEFT JOIN TG.dbo.Estaciones E ON E.Codigo=COALESCE(D.estacion_id,S.estacion_corregida_id,P.estacion_id)
                LEFT JOIN TG.dbo.Estaciones EOriginal ON EOriginal.Codigo=P.estacion_id
                WHERE I.estado='IMPORTADA'
                  AND YEAR(COALESCE(D.fecha_efectiva,C.fecha_efectiva,P.fecha_reportada))=?
                  AND MONTH(COALESCE(D.fecha_efectiva,C.fecha_efectiva,P.fecha_reportada))=?
                  AND COALESCE(D.estacion_id,S.estacion_corregida_id,P.estacion_id) IN ($stationMarks)";
        $params=array_merge([$year,$month],$stationIds);
        if ($stationId > 0) { $sql .= ' AND COALESCE(D.estacion_id,S.estacion_corregida_id,P.estacion_id)=?'; $params[]=$stationId; }
        $sql .= ' ORDER BY COALESCE(D.fecha_efectiva,C.fecha_efectiva,P.fecha_reportada) DESC,P.id DESC';
        $stmt=$this->db->prepare($sql); $stmt->execute($params); $items=[];
        while($row=$stmt->fetch(PDO::FETCH_ASSOC)) {
            $originalDate=$this->dateValue($row['fecha_original']); $effectiveDate=$this->dateValue($row['fecha_actual']);
            $effectiveStation=(int)($row['estacion_actual'] ?: $row['estacion_original_id']); $originalStation=(int)$row['estacion_original_id'];
            $items[]=[
                'id'=>(int)$row['id'], 'importation_id'=>(int)$row['importacion_id'],
                'original_date'=>$originalDate,
                'effective_date'=>$effectiveDate!==$originalDate?$effectiveDate:null,
                'date'=>$effectiveDate, 'time'=>$row['hora_actual']??$row['hora_original'],
                'remittance'=>$this->normaliseRemittance($row['remesa_actual']),
                'account'=>$row['cuenta_actual'], 'station_id'=>$effectiveStation, 'original_station_id'=>$originalStation, 'station'=>$row['estacion_nombre'], 'original_station'=>$row['estacion_original_nombre'],
                'declared_mn'=>(float)($row['declarado_mn_actual']??0), 'real_mn'=>(float)($row['real_mn_actual']??0),
                'declared_usd'=>(float)($row['declarado_usd_actual']??0), 'real_usd'=>(float)($row['real_usd_actual']??0),
                'difference_mn'=>round((float)($row['real_mn_actual']??0)-(float)($row['declarado_mn_actual']??0),2), 'difference_usd'=>round((float)($row['real_usd_actual']??0)-(float)($row['declarado_usd_actual']??0),2),
                'file'=>$row['nombre_archivo'], 'correction_id'=>$row['correccion_id']?(int)$row['correccion_id']:null,
                'correction_user'=>($row['datos_correccion_usuario']??$row['correccion_usuario'])?(int)($row['datos_correccion_usuario']??$row['correccion_usuario']):null,
                'correction_at'=>($row['datos_correccion_creada']??$row['correccion_creada'])?$this->dateTimeValue($row['datos_correccion_creada']??$row['correccion_creada']):null,
                'station_corrected'=>$effectiveStation!==$originalStation || (bool)$row['estacion_correccion_id'], 'data_corrected'=>(bool)$row['datos_correccion_id'], 'linked'=>(bool)$row['vinculada'],
                'created_at'=>$this->dateTimeValue($row['creado_en']), 'created_by'=>$row['creador_nombre']?:null, 'manually_created'=>(bool)$row['captura_manual']
            ];
        }
        return $items;
    }

    public function createManagedPaper(array $data, int $userId): int {
        $values=$this->validateManagedPaper($data);
        if ($userId<1) throw new RuntimeException('No se pudo identificar al usuario de sesión.');
        $company=strtoupper(trim((string)($data['empresa']??'DIAZ GAS')));
        if (!in_array($company,['DIAZ GAS','FORANEAS','GASOMEX'],true)) throw new RuntimeException('Empresa inválida.');
        if (!in_array($values['station_id'],EfcConciliacionModel::stationIds($company),true)) throw new RuntimeException('La estación no pertenece a la empresa seleccionada.');
        $this->db->beginTransaction();
        try {
            $payload=json_encode($values,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            $import=$this->db->prepare("INSERT dbo.efc_conc_analiticos_importaciones(hash_archivo,nombre_archivo,archivo,version_lector,estado,total_papeletas,total_errores) OUTPUT INSERTED.id VALUES(?,N'Captura manual',CONVERT(VARBINARY(MAX),''),'MANUAL','IMPORTADA',1,0)");
            $import->execute([hash('sha256','manual:'.bin2hex(random_bytes(32)))]); $importId=(int)$import->fetchColumn();
            $station=$this->db->prepare('SELECT Nombre FROM TG.dbo.Estaciones WHERE Codigo=?'); $station->execute([$values['station_id']]); $stationName=(string)$station->fetchColumn();
            $insert=$this->db->prepare("INSERT dbo.efc_conc_analiticos_papeletas(importacion_id,hoja,fila_origen,fecha_reportada,fecha_original,hora_original,estacion_original,estacion_nombre_original,estacion_id,estado_estacion,cuenta_mn_original,remesa_numero,dice_contener_mn,real_mn,diferencia_mn,dice_contener_usd,real_usd,diferencia_usd,datos_originales,creado_por,captura_manual) OUTPUT INSERTED.id VALUES(?,N'Captura manual',1,?, ?, ?, ?, ?, ?,'IDENTIFICADA',?,?,?,?,?,?,?, ?, ?,?,1)");
            $insert->execute([$importId,$values['date'],$values['date'],$values['time'],$stationName,$stationName,$values['station_id'],$values['account'],$values['remittance'],$values['declared_mn'],$values['real_mn'],$values['real_mn']-$values['declared_mn'],$values['declared_usd'],$values['real_usd'],$values['real_usd']-$values['declared_usd'],$payload,$userId]);
            $id=(int)$insert->fetchColumn();
            $this->insertPaperAudit($id,'CREACION',null,$values,$userId);
            $this->db->commit(); return $id;
        } catch(Throwable $e) { if($this->db->inTransaction())$this->db->rollBack(); throw $e; }
    }

    public function updateManagedPaper(int $id, array $data, int $userId): void {
        if($id<1||$userId<1) throw new RuntimeException('Papeleta o usuario inválido.');
        $this->db->beginTransaction();
        try {
            $q=$this->db->prepare("SELECT P.*,C.fecha_efectiva,S.estacion_corregida_id,D.fecha_efectiva AS datos_fecha,D.hora_efectiva,D.estacion_id AS datos_estacion,D.remesa_efectiva,D.cuenta_efectiva,D.declarado_mn,D.real_mn AS datos_real_mn,D.declarado_usd,D.real_usd AS datos_real_usd FROM dbo.efc_conc_analiticos_papeletas P WITH (UPDLOCK,HOLDLOCK) JOIN dbo.efc_conc_analiticos_importaciones I ON I.id=P.importacion_id LEFT JOIN dbo.efc_conc_analiticos_correcciones_fecha C ON C.papeleta_id=P.id AND C.activo=1 LEFT JOIN dbo.efc_conc_analiticos_correcciones_estacion S ON S.papeleta_id=P.id AND S.activo=1 LEFT JOIN dbo.efc_conc_analiticos_correcciones_datos D ON D.papeleta_id=P.id AND D.activo=1 WHERE P.id=? AND I.estado='IMPORTADA'");
            $q->execute([$id]); $row=$q->fetch(PDO::FETCH_ASSOC); if(!$row)throw new RuntimeException('Papeleta no encontrada.');
            $activeLinks=$this->db->prepare("SELECT id,estacion_id,fecha_cg,turno,concepto FROM dbo.efc_conc_analiticos_vinculos WITH (UPDLOCK,HOLDLOCK) WHERE activo=1 AND (papeleta_id=? OR papeleta_secundaria_id=?)");
            $activeLinks->execute([$id,$id]); $paperLinks=$activeLinks->fetchAll(PDO::FETCH_ASSOC);
            if($paperLinks && empty($data['confirm_linked'])) throw new RuntimeException('La papeleta tiene vínculos activos y requiere confirmación explícita para editarse.');
            foreach($paperLinks as $link) $this->assertLinkEditable($link);
            $old=$this->managedValuesFromRow($row); $merged=array_merge($old,is_array($data['fields']??null)?$data['fields']:$data); $new=$this->validateManagedPaper($merged);
            if(EfcConciliacionModel::stationCompany($new['station_id'])===null) throw new RuntimeException('Estación inválida.');
            if($old===$new){$this->db->commit();return;}
            $this->db->prepare('UPDATE dbo.efc_conc_analiticos_correcciones_datos SET activo=0 WHERE papeleta_id=? AND activo=1')->execute([$id]);
            $s=$this->db->prepare('INSERT dbo.efc_conc_analiticos_correcciones_datos(papeleta_id,fecha_efectiva,hora_efectiva,estacion_id,remesa_efectiva,cuenta_efectiva,declarado_mn,real_mn,declarado_usd,real_usd,usuario_id,activo) VALUES(?,?,?,?,?,?,?,?,?,?,?,1)');
            $s->execute([$id,$new['date'],$new['time'],$new['station_id'],$new['remittance'],$new['account'],$new['declared_mn'],$new['real_mn'],$new['declared_usd'],$new['real_usd'],$userId]);
            $this->insertPaperAudit($id,'EDICION',$old,$new,$userId); $this->db->commit();
        } catch(Throwable $e) { if($this->db->inTransaction())$this->db->rollBack(); throw $e; }
    }

    public function managedPaperHistory(int $id): array {
        $p=$this->db->prepare("SELECT P.creado_por,COALESCE(P.creado_en,I.creado_en) creado_en,ISNULL(P.captura_manual,0) captura_manual,COALESCE(U.Nombre,U.Usuario) creador FROM dbo.efc_conc_analiticos_papeletas P JOIN dbo.efc_conc_analiticos_importaciones I ON I.id=P.importacion_id LEFT JOIN TG.dbo.Usuario U ON U.Id=P.creado_por WHERE P.id=?"); $p->execute([$id]); $created=$p->fetch(PDO::FETCH_ASSOC); if(!$created)throw new RuntimeException('Papeleta no encontrada.');
        $q=$this->db->prepare('SELECT A.operacion,A.valores_anteriores,A.valores_nuevos,A.usuario_id,A.creado_en,COALESCE(U.Nombre,U.Usuario) usuario_nombre FROM dbo.efc_conc_analiticos_papeletas_auditoria A LEFT JOIN TG.dbo.Usuario U ON U.Id=A.usuario_id WHERE A.papeleta_id=? ORDER BY A.creado_en,A.id'); $q->execute([$id]); $entries=[];
        while($r=$q->fetch(PDO::FETCH_ASSOC))$entries[]=['operation'=>$r['operacion'],'old_values'=>$r['valores_anteriores']?json_decode($r['valores_anteriores'],true):null,'new_values'=>json_decode($r['valores_nuevos'],true),'user_id'=>$r['usuario_id']?(int)$r['usuario_id']:null,'user_name'=>$r['usuario_nombre'],'created_at'=>$this->dateTimeValue($r['creado_en'])];
        return ['created_at'=>$this->dateTimeValue($created['creado_en']),'created_by'=>$created['creador']?:($created['creado_por']?'Usuario '.$created['creado_por']:'Sistema'),'manually_created'=>(bool)$created['captura_manual'],'entries'=>$entries];
    }

    private function validateManagedPaper(array $data): array {
        $date=trim((string)($data['date']??'')); $time=trim((string)($data['time']??'')); $station=(int)($data['station_id']??0);
        $parsed=DateTime::createFromFormat('!Y-m-d',$date);
        if(!$parsed||$parsed->format('Y-m-d')!==$date)throw new RuntimeException('Capture una fecha válida.');
        if(strlen($time)>100)throw new RuntimeException('La hora excede la longitud permitida.');
        $stationQ=$this->db->prepare('SELECT 1 FROM TG.dbo.Estaciones WHERE Codigo=? AND Codigo<>0');$stationQ->execute([$station]);if($station<1||!$stationQ->fetchColumn())throw new RuntimeException('Seleccione una estación válida.');
        $num=static function($key)use($data){$v=$data[$key]??0;if($v===''||$v===null)return 0.0;if(!is_numeric($v)||!is_finite((float)$v)||(float)$v<0||(float)$v>9999999999999999.99)throw new RuntimeException('Los importes deben ser números válidos dentro del rango permitido.');return round((float)$v,2);};
        $remittance=trim((string)($data['remittance']??'')); $account=trim((string)($data['account']??''));
        $remittanceLength=preg_match_all('/./us',$remittance); $accountLength=preg_match_all('/./us',$account);
        if($remittanceLength===false||$remittanceLength>100||$accountLength===false||$accountLength>100)throw new RuntimeException('La remesa o cuenta excede la longitud permitida.');
        return ['date'=>$date,'time'=>$time,'station_id'=>$station,'remittance'=>$remittance,'account'=>$account,'declared_mn'=>$num('declared_mn'),'real_mn'=>$num('real_mn'),'declared_usd'=>$num('declared_usd'),'real_usd'=>$num('real_usd')];
    }

    private function managedValuesFromRow(array $r): array {
        return ['date'=>$this->dateValue($r['datos_fecha']??$r['fecha_efectiva']??$r['fecha_reportada']),'time'=>(string)($r['hora_efectiva']??$r['hora_original']??''),'station_id'=>(int)($r['datos_estacion']??$r['estacion_corregida_id']??$r['estacion_id']??0),'remittance'=>(string)($r['remesa_efectiva']??$r['remesa_numero']??''),'account'=>(string)($r['cuenta_efectiva']??$r['cuenta_mn_original']??''),'declared_mn'=>round((float)($r['declarado_mn']??$r['dice_contener_mn']??0),2),'real_mn'=>round((float)($r['datos_real_mn']??$r['real_mn']??0),2),'declared_usd'=>round((float)($r['declarado_usd']??$r['dice_contener_usd']??0),2),'real_usd'=>round((float)($r['datos_real_usd']??$r['real_usd']??0),2)];
    }

    private function insertPaperAudit(int $id,string $operation,?array $old,?array $new,int $userId): void {
        $s=$this->db->prepare('INSERT dbo.efc_conc_analiticos_papeletas_auditoria(papeleta_id,operacion,valores_anteriores,valores_nuevos,usuario_id) VALUES(?,?,?,?,?)');
        $s->execute([$id,$operation,$old?json_encode($old,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null,$new?json_encode($new,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null,$userId]);
    }

    /** Aplica una misma fecha efectiva a varias papeletas, dejando historial. */
    public function correctDates(array $paperIds, ?string $effectiveDate, int $userId): int {
        $ids=array_values(array_unique(array_filter(array_map('intval',$paperIds),static fn($id)=>$id>0)));
        if (!$ids || count($ids)>100) throw new RuntimeException('Seleccione entre una y 100 papeletas.');
        if ($effectiveDate !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$effectiveDate)) throw new RuntimeException('La fecha indicada no es válida.');
        $marks=implode(',',array_fill(0,count($ids),'?'));
        $this->db->beginTransaction();
        try {
            $locked=$this->db->prepare("SELECT P.id,P.fecha_reportada,CASE WHEN EXISTS(SELECT 1 FROM dbo.efc_conc_analiticos_vinculos V WHERE (V.papeleta_id=P.id OR V.papeleta_secundaria_id=P.id) AND V.activo=1) THEN 1 ELSE 0 END AS vinculada FROM dbo.efc_conc_analiticos_papeletas P WITH (UPDLOCK,HOLDLOCK) WHERE P.id IN ($marks)");
            $locked->execute($ids); $papers=$locked->fetchAll(PDO::FETCH_ASSOC);
            if (count($papers)!==count($ids)) throw new RuntimeException('Una o más papeletas ya no están disponibles.');
            foreach($papers as $paper) if((bool)$paper['vinculada']) throw new RuntimeException('No puede cambiar la fecha de una papeleta ya asociada. Desasóciela primero.');
            $deactivate=$this->db->prepare("UPDATE dbo.efc_conc_analiticos_correcciones_fecha SET activo=0,actualizado_en=GETDATE(),actualizado_por=? WHERE activo=1 AND papeleta_id IN ($marks)");
            $deactivate->execute(array_merge([$userId?:null],$ids));
            if ($effectiveDate !== null) {
                $insert=$this->db->prepare('INSERT dbo.efc_conc_analiticos_correcciones_fecha(papeleta_id,fecha_original,fecha_efectiva,usuario_id) VALUES(?,?,?,?)');
                foreach($papers as $paper) {
                    $original=$this->dateValue($paper['fecha_reportada']);
                    if ($original!==$effectiveDate) $insert->execute([(int)$paper['id'],$original,$effectiveDate,$userId?:null]);
                }
            }
            $this->db->commit();
            return count($papers);
        } catch(Throwable $e) { if($this->db->inTransaction())$this->db->rollBack(); throw $e; }
    }

    /** Corrige/restaura la estación efectiva sin reescribir la importación fuente. */
    public function correctStations(array $paperIds, ?int $stationId, int $userId): int {
        $ids=array_values(array_unique(array_filter(array_map('intval',$paperIds),static fn($id)=>$id>0)));
        if (!$ids || count($ids)>100) throw new RuntimeException('Seleccione entre una y 100 papeletas.');
        if ($stationId !== null) {
            $valid=$this->db->prepare('SELECT 1 FROM TG.dbo.Estaciones WHERE Codigo=? AND Codigo<>0');
            $valid->execute([$stationId]);
            if (!$valid->fetchColumn()) throw new RuntimeException('La estación indicada no es válida.');
        }
        $marks=implode(',',array_fill(0,count($ids),'?'));
        $this->db->beginTransaction();
        try {
            $locked=$this->db->prepare("SELECT P.id,P.estacion_id,CASE WHEN EXISTS(SELECT 1 FROM dbo.efc_conc_analiticos_vinculos V WHERE (V.papeleta_id=P.id OR V.papeleta_secundaria_id=P.id) AND V.activo=1) THEN 1 ELSE 0 END AS vinculada FROM dbo.efc_conc_analiticos_papeletas P WITH (UPDLOCK,HOLDLOCK) WHERE P.id IN ($marks)");
            $locked->execute($ids); $papers=$locked->fetchAll(PDO::FETCH_ASSOC);
            if (count($papers)!==count($ids)) throw new RuntimeException('Una o más papeletas ya no están disponibles.');
            foreach($papers as $paper) if((bool)$paper['vinculada']) throw new RuntimeException('No puede cambiar la estación de una papeleta ya asociada. Desasóciela primero.');
            $deactivate=$this->db->prepare("UPDATE dbo.efc_conc_analiticos_correcciones_estacion SET activo=0,actualizado_en=GETDATE(),actualizado_por=? WHERE activo=1 AND papeleta_id IN ($marks)");
            $deactivate->execute(array_merge([$userId?:null],$ids));
            if ($stationId !== null) {
                $insert=$this->db->prepare('INSERT dbo.efc_conc_analiticos_correcciones_estacion(papeleta_id,estacion_original_id,estacion_corregida_id,usuario_id) VALUES(?,?,?,?)');
                foreach($papers as $paper) if((int)$paper['estacion_id']!==$stationId) $insert->execute([(int)$paper['id'],(int)$paper['estacion_id'],$stationId,$userId?:null]);
            }
            $this->db->commit();
            return count($papers);
        } catch(Throwable $e) { if($this->db->inTransaction())$this->db->rollBack(); throw $e; }
    }

    public function link(array $data, int $userId): void {
        $station=(int)($data['station_id']??0); $paper=(int)($data['paper_id']??0); $date=(string)($data['date']??''); $turn=(string)($data['turn']??''); $concept=(string)($data['currency']??''); $amount=(float)($data['amount']??0); $criterion=(string)($data['criterion']??'MANUAL');
        if(!$station||!$paper||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)||$turn===''||!in_array($concept,['MN','MORRALLA','USD'],true)||$amount<=0) throw new RuntimeException('Datos de vínculo inválidos.');
        $this->db->beginTransaction();
        try {
            $this->assertPaperAvailable($paper,$station);
            $current=$this->db->prepare("SELECT * FROM dbo.efc_conc_analiticos_vinculos WITH (UPDLOCK,HOLDLOCK) WHERE estacion_id=? AND fecha_cg=? AND turno=? AND concepto=? AND activo=1");
            $current->execute([$station,$date,$turn,$concept]); $existing=$current->fetch(PDO::FETCH_ASSOC);
            if($existing && $existing['papeleta_secundaria_id']) throw new RuntimeException('Retire la segunda papeleta antes de cambiar el vínculo.');
            $this->assertLinkEditable($existing ?: ['estacion_id'=>$station,'fecha_cg'=>$date,'turno'=>$turn,'concepto'=>$concept]);
            // El TC enviado por el navegador no es una fuente válida: siempre se
            // vuelve a resolver contra Cotizaciones para conservar trazabilidad.
            $exchangeRate=$concept==='USD' ? $this->exchangeRateForTurn($station,$date,$turn) : null;
            if($concept==='USD' && ($exchangeRate===null || $exchangeRate<=0)) throw new RuntimeException('No existe tipo de cambio histórico para este turno.');
            $this->db->prepare("UPDATE dbo.efc_conc_analiticos_vinculos SET activo=0,actualizado_en=GETDATE() WHERE activo=1 AND (estacion_id=? AND fecha_cg=? AND turno=? AND concepto=?)")->execute([$station,$date,$turn,$concept]);
            $this->db->prepare("INSERT dbo.efc_conc_analiticos_vinculos(estacion_id,papeleta_id,fecha_cg,turno,concepto,importe_cg,criterio,tipo_cambio_usd,usuario_id,bloqueado_auto) VALUES(?,?,?,?,?,?,?,?,?,0)")->execute([$station,$paper,$date,$turn,$concept,$amount,$criterion,$concept==='USD'?$exchangeRate:null,$userId?:null]);
            $this->db->commit();
        } catch(Throwable $e) { if($this->db->inTransaction())$this->db->rollBack(); throw $e; }
    }

    /** TC vigente al inicio de cada turno del periodo. Sólo lectura a SG12. */
    public function exchangeRatesForTurns(int $stationId, int $year, int $month): array {
        if (!$stationId || $year < 2020 || $month < 1 || $month > 12) throw new RuntimeException('Estación o periodo inválidos.');
        $station=$this->db->prepare("SELECT 1 FROM TG.dbo.Estaciones WHERE Codigo=? AND Codigo<>0");
        $station->execute([$stationId]);
        if (!$station->fetchColumn()) throw new RuntimeException('Estación inválida.');
        $rates=$this->scheduledExchangeRates($stationId);
        $last=(new DateTimeImmutable(sprintf('%04d-%02d-01',$year,$month)))->modify('last day of this month');
        $current=new DateTimeImmutable(sprintf('%04d-%02d-01',$year,$month)); $out=[];
        while ($current <= $last) {
            foreach ([1,2,3,4] as $turn) {
                $found=$this->rateAt($rates,$this->turnStart($current,$turn));
                $out[]=['date'=>$current->format('Y-m-d'),'turn'=>$turn,'rate'=>$found['rate']??null,'scheduled_at'=>$found['scheduled_at']??null];
            }
            $current=$current->modify('+1 day');
        }
        return $out;
    }

    private function assertPaperAvailable(int $paper,int $station): void {
        $q=$this->db->prepare("SELECT P.id FROM dbo.efc_conc_analiticos_papeletas P WITH (UPDLOCK,HOLDLOCK) JOIN dbo.efc_conc_analiticos_importaciones I ON I.id=P.importacion_id LEFT JOIN dbo.efc_conc_analiticos_correcciones_estacion S WITH (UPDLOCK,HOLDLOCK) ON S.papeleta_id=P.id AND S.activo=1 LEFT JOIN dbo.efc_conc_analiticos_correcciones_datos D WITH (UPDLOCK,HOLDLOCK) ON D.papeleta_id=P.id AND D.activo=1 WHERE P.id=? AND COALESCE(D.estacion_id,S.estacion_corregida_id,P.estacion_id)=? AND I.estado='IMPORTADA'");
        $q->execute([$paper,$station]); if(!$q->fetchColumn()) throw new RuntimeException('La papeleta no está importada o no pertenece a la estación efectiva.');
        $q=$this->db->prepare("SELECT id FROM dbo.efc_conc_analiticos_vinculos WITH (UPDLOCK,HOLDLOCK) WHERE activo=1 AND (papeleta_id=? OR papeleta_secundaria_id=?)");
        $q->execute([$paper,$paper]); if($q->fetchColumn()) throw new RuntimeException('La papeleta ya está asociada a un turno.');
    }

    private function assertLinkEditable(array $link): void {
        $station=(int)$link['estacion_id']; $date=$this->dateValue($link['fecha_cg']); $turn=(string)$link['turno']; $concept=(string)$link['concepto'];
        $q=$this->db->prepare("SELECT id,mes_destino,clave_externa FROM dbo.efc_conc_transitos WITH (UPDLOCK,HOLDLOCK) WHERE estacion_id=? AND fecha_origen=? AND turno=? AND concepto=? AND estado IN ('PENDIENTE','CONCILIADO') ORDER BY id DESC");
        $q->execute([$station,$date,$turn,$concept]); $transit=$q->fetch(PDO::FETCH_ASSOC); $month=$transit ? (string)$transit['mes_destino'] : substr($date,0,7);
        $q=$this->db->prepare("SELECT 1 FROM dbo.efc_conc_cierres WITH (UPDLOCK,HOLDLOCK) WHERE estacion_id=? AND mes=? AND concepto=? AND estado='CERRADO' UNION ALL SELECT 1 FROM dbo.efc_conc_cierres_etapas WITH (UPDLOCK,HOLDLOCK) WHERE estacion_id=? AND mes=? AND concepto=? AND estado='CERRADO'");
        $q->execute([$station,$month,$concept,$station,$month,$concept]); if($q->fetchColumn()) throw new RuntimeException('El periodo o una etapa está cerrada. Reábrala antes de modificar papeletas.');
        $q=$this->db->prepare("SELECT TOP 1 G.id FROM dbo.efc_conc_grupos G WITH (UPDLOCK,HOLDLOCK) JOIN dbo.efc_conc_partidas C ON C.grupo_id=G.id AND C.origen='CG' AND C.activo=1 WHERE G.estacion_id=? AND G.estado='ACTIVA' AND C.concepto=? AND ((C.fecha_operacion=? AND C.turno=?) OR C.clave_externa IN (?,?)) AND EXISTS(SELECT 1 FROM dbo.efc_conc_partidas B WHERE B.grupo_id=G.id AND B.origen='BANCO' AND B.activo=1)");
        $q->execute([$station,$concept,$date,$turn,$transit['clave_externa']??'',isset($transit['id'])?'TR:'.$transit['id']:'']);
        if($q->fetchColumn()) throw new RuntimeException('Deshaga primero la conciliación bancaria del turno.');
    }

    public function linkSecond(array $data,int $userId): void {
        $id=(int)($data['link_id']??0); $paper=(int)($data['paper_id']??0);
        if($id<1 || $paper<1) throw new RuntimeException('Vínculo o papeleta inválidos.');
        $this->db->beginTransaction();
        try {
            $q=$this->db->prepare("SELECT * FROM dbo.efc_conc_analiticos_vinculos WITH (UPDLOCK,HOLDLOCK) WHERE id=? AND activo=1"); $q->execute([$id]); $link=$q->fetch(PDO::FETCH_ASSOC);
            if(!$link || $link['papeleta_secundaria_id']) throw new RuntimeException('El turno debe tener una sola papeleta activa.');
            $this->assertLinkEditable($link); $this->assertPaperAvailable($paper,(int)$link['estacion_id']);
            $this->db->prepare("UPDATE dbo.efc_conc_analiticos_vinculos SET papeleta_secundaria_id=?,criterio='MANUAL_DOBLE',usuario_id=?,actualizado_en=GETDATE() WHERE id=? AND activo=1")->execute([$paper,$userId?:null,$id]);
            $this->db->commit();
        } catch(Throwable $e) { if($this->db->inTransaction())$this->db->rollBack(); throw $e; }
    }

    public function unlink(int $linkId,string $scope='all'): void {
        if(!in_array($scope,['all','secondary'],true)) throw new RuntimeException('Alcance inválido.');
        $this->db->beginTransaction();
        try {
            $q=$this->db->prepare("SELECT * FROM dbo.efc_conc_analiticos_vinculos WITH (UPDLOCK,HOLDLOCK) WHERE id=? AND activo=1"); $q->execute([$linkId]); $link=$q->fetch(PDO::FETCH_ASSOC);
            if(!$link) throw new RuntimeException('El vínculo ya no está activo.'); $this->assertLinkEditable($link);
            if($scope==='secondary') {
                if(!$link['papeleta_secundaria_id']) throw new RuntimeException('El turno no tiene segunda papeleta.');
                $this->db->prepare("UPDATE dbo.efc_conc_analiticos_vinculos SET papeleta_secundaria_id=NULL,criterio='MANUAL',actualizado_en=GETDATE() WHERE id=?")->execute([$linkId]);
            } else $this->db->prepare("UPDATE dbo.efc_conc_analiticos_vinculos SET activo=0,bloqueado_auto=1,actualizado_en=GETDATE() WHERE id=?")->execute([$linkId]);
            $this->db->commit();
        } catch(Throwable $e) { if($this->db->inTransaction())$this->db->rollBack(); throw $e; }
    }

    public function swapLinks(int $firstId, int $secondId, int $userId): void {
        if ($firstId < 1 || $secondId < 1 || $firstId === $secondId) throw new RuntimeException('Seleccione dos vínculos distintos.');
        $this->db->beginTransaction();
        try {
            $q=$this->db->prepare("SELECT id,estacion_id,papeleta_id,papeleta_secundaria_id,fecha_cg,turno,concepto,importe_cg,criterio,tipo_cambio_usd FROM dbo.efc_conc_analiticos_vinculos WITH (UPDLOCK,HOLDLOCK) WHERE id IN (?,?) AND activo=1");
            $q->execute([$firstId,$secondId]); $rows=$q->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows)!==2 || (int)$rows[0]['estacion_id']!==(int)$rows[1]['estacion_id']) throw new RuntimeException('Los vínculos no son intercambiables.');
            foreach($rows as $row) { if($row['papeleta_secundaria_id']) throw new RuntimeException('No se pueden intercambiar vínculos con dos papeletas.'); $this->assertLinkEditable($row); }
            $this->db->prepare("UPDATE dbo.efc_conc_analiticos_vinculos SET activo=0,actualizado_en=GETDATE() WHERE id IN (?,?)")->execute([$firstId,$secondId]);
            $insert=$this->db->prepare("INSERT dbo.efc_conc_analiticos_vinculos(estacion_id,papeleta_id,fecha_cg,turno,concepto,importe_cg,criterio,tipo_cambio_usd,usuario_id) VALUES(?,?,?,?,?,?,?,?,?)");
            $insert->execute([(int)$rows[0]['estacion_id'],(int)$rows[1]['papeleta_id'],$rows[0]['fecha_cg'],$rows[0]['turno'],$rows[0]['concepto'],$rows[0]['importe_cg'],'INTERCAMBIO',$rows[0]['tipo_cambio_usd'],$userId?:null]);
            $insert->execute([(int)$rows[1]['estacion_id'],(int)$rows[0]['papeleta_id'],$rows[1]['fecha_cg'],$rows[1]['turno'],$rows[1]['concepto'],$rows[1]['importe_cg'],'INTERCAMBIO',$rows[1]['tipo_cambio_usd'],$userId?:null]);
            $this->db->commit();
        } catch(Throwable $e) { if($this->db->inTransaction())$this->db->rollBack(); throw $e; }
    }

    private function ensureLinksSchema(): void {
        $statements = [];

        $statements[] = "IF OBJECT_ID('dbo.efc_conc_analiticos_vinculos','U') IS NULL CREATE TABLE dbo.efc_conc_analiticos_vinculos (id INT IDENTITY PRIMARY KEY, estacion_id INT NOT NULL, papeleta_id INT NOT NULL, fecha_cg DATE NOT NULL, turno NVARCHAR(40) NOT NULL, concepto VARCHAR(10) NOT NULL, importe_cg DECIMAL(18,2) NOT NULL, criterio VARCHAR(20) NOT NULL, tipo_cambio_usd DECIMAL(18,6) NULL, usuario_id INT NULL, activo BIT NOT NULL DEFAULT 1, creado_en DATETIME NOT NULL DEFAULT GETDATE(), actualizado_en DATETIME NULL, CONSTRAINT FK_efc_conc_analiticos_vinculos_papeleta FOREIGN KEY(papeleta_id) REFERENCES dbo.efc_conc_analiticos_papeletas(id))";
        $statements[] = "IF COL_LENGTH('dbo.efc_conc_analiticos_vinculos','papeleta_secundaria_id') IS NULL ALTER TABLE dbo.efc_conc_analiticos_vinculos ADD papeleta_secundaria_id INT NULL";
        $statements[] = "IF OBJECT_ID('dbo.FK_efc_conc_analiticos_vinculos_secundaria','F') IS NULL ALTER TABLE dbo.efc_conc_analiticos_vinculos ADD CONSTRAINT FK_efc_conc_analiticos_vinculos_secundaria FOREIGN KEY(papeleta_secundaria_id) REFERENCES dbo.efc_conc_analiticos_papeletas(id)";
        $statements[] = "IF OBJECT_ID('dbo.CK_efc_conc_analiticos_vinculos_distintas','C') IS NULL ALTER TABLE dbo.efc_conc_analiticos_vinculos ADD CONSTRAINT CK_efc_conc_analiticos_vinculos_distintas CHECK(papeleta_secundaria_id IS NULL OR papeleta_secundaria_id<>papeleta_id)";
        $statements[] = "IF NOT EXISTS(SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID('dbo.efc_conc_analiticos_vinculos') AND name='UX_efc_conc_analiticos_vinculos_secundaria_activa') CREATE UNIQUE INDEX UX_efc_conc_analiticos_vinculos_secundaria_activa ON dbo.efc_conc_analiticos_vinculos(papeleta_secundaria_id) WHERE activo=1 AND papeleta_secundaria_id IS NOT NULL";
        $statements[] = "IF COL_LENGTH('dbo.efc_conc_analiticos_vinculos','tipo_cambio_usd') IS NULL ALTER TABLE dbo.efc_conc_analiticos_vinculos ADD tipo_cambio_usd DECIMAL(18,6) NULL";
        $statements[] = "IF COL_LENGTH('dbo.efc_conc_analiticos_vinculos','bloqueado_auto') IS NULL ALTER TABLE dbo.efc_conc_analiticos_vinculos ADD bloqueado_auto BIT NOT NULL CONSTRAINT DF_efc_conc_analiticos_vinculos_bloqueado DEFAULT 0";
        $statements[] = "IF NOT EXISTS(SELECT 1 FROM sys.indexes WHERE name='UX_efc_conc_analiticos_vinculos_papeleta_activa') CREATE UNIQUE INDEX UX_efc_conc_analiticos_vinculos_papeleta_activa ON dbo.efc_conc_analiticos_vinculos(papeleta_id) WHERE activo=1";
        $statements[] = "IF NOT EXISTS(SELECT 1 FROM sys.indexes WHERE name='UX_efc_conc_analiticos_vinculos_turno_activo') CREATE UNIQUE INDEX UX_efc_conc_analiticos_vinculos_turno_activo ON dbo.efc_conc_analiticos_vinculos(estacion_id,fecha_cg,turno,concepto) WHERE activo=1";
        $statements[] = "IF OBJECT_ID('dbo.efc_conc_analiticos_correcciones_fecha','U') IS NULL CREATE TABLE dbo.efc_conc_analiticos_correcciones_fecha (id INT IDENTITY PRIMARY KEY,papeleta_id INT NOT NULL,fecha_original DATE NOT NULL,fecha_efectiva DATE NOT NULL,usuario_id INT NULL,activo BIT NOT NULL DEFAULT 1,creado_en DATETIME NOT NULL DEFAULT GETDATE(),actualizado_en DATETIME NULL,actualizado_por INT NULL,CONSTRAINT FK_efc_conc_analiticos_correcciones_fecha_papeleta FOREIGN KEY(papeleta_id) REFERENCES dbo.efc_conc_analiticos_papeletas(id))";
        $statements[] = "IF NOT EXISTS(SELECT 1 FROM sys.indexes WHERE name='UX_efc_conc_analiticos_corr_fecha_activa') CREATE UNIQUE INDEX UX_efc_conc_analiticos_corr_fecha_activa ON dbo.efc_conc_analiticos_correcciones_fecha(papeleta_id) WHERE activo=1";
        $statements[] = "IF OBJECT_ID('dbo.efc_conc_analiticos_correcciones_estacion','U') IS NULL CREATE TABLE dbo.efc_conc_analiticos_correcciones_estacion (id INT IDENTITY PRIMARY KEY,papeleta_id INT NOT NULL,estacion_original_id INT NOT NULL,estacion_corregida_id INT NOT NULL,usuario_id INT NULL,activo BIT NOT NULL DEFAULT 1,creado_en DATETIME NOT NULL DEFAULT GETDATE(),actualizado_en DATETIME NULL,actualizado_por INT NULL,CONSTRAINT FK_efc_conc_analiticos_corr_estacion_papeleta FOREIGN KEY(papeleta_id) REFERENCES dbo.efc_conc_analiticos_papeletas(id))";
        $statements[] = "IF NOT EXISTS(SELECT 1 FROM sys.indexes WHERE name='UX_efc_conc_analiticos_corr_estacion_activa') CREATE UNIQUE INDEX UX_efc_conc_analiticos_corr_estacion_activa ON dbo.efc_conc_analiticos_correcciones_estacion(papeleta_id) WHERE activo=1";
        $this->db->exec(implode(";\n", $statements));
    }

    private function dateValue($value): string { return $value instanceof DateTimeInterface ? $value->format('Y-m-d') : substr((string)$value,0,10); }
    private function dateTimeValue($value): string { return $value instanceof DateTimeInterface ? $value->format('Y-m-d H:i') : substr((string)$value,0,16); }
    private function normaliseRemittance($value): string { $text=(string)$value; return preg_match('/^\d+\.0$/',$text) ? substr($text,0,-2) : $text; }

    private function exchangeRateForTurn(int $stationId, string $date, string $turn): ?float {
        $number=$this->turnNumber($turn);
        if ($number===null) throw new RuntimeException('Turno inválido para tipo de cambio.');
        $at=$this->turnStart(new DateTimeImmutable($date),$number);
        $rate=$this->rateAt($this->scheduledExchangeRates($stationId),$at);
        return $rate['rate']??null;
    }

    /** Lee el historial completo de una estación, sin escribir ni alterar SG12. */
    private function scheduledExchangeRates(int $stationId): array {
        $stmt=$this->db->prepare("SELECT fch,hra,ctz,logfch FROM SG12.dbo.Cotizaciones WHERE codgas=? AND codmda=2 ORDER BY fch,hra,logfch");
        $stmt->execute([$stationId]); $out=[];
        $base=new DateTimeImmutable('1900-01-01');
        while($row=$stmt->fetch(PDO::FETCH_ASSOC)) {
            $hour=(int)$row['hra']; $hours=intdiv($hour,100); $minutes=$hour%100;
            if($hours>23 || $minutes>59 || (float)$row['ctz']<=0) continue;
            $at=$base->modify('+'.((int)$row['fch']-1).' days')->setTime($hours,$minutes);
            $out[]=['at'=>$at,'rate'=>(float)$row['ctz'],'scheduled_at'=>$at->format('Y-m-d H:i')];
        }
        return $out;
    }

    private function rateAt(array $rates, DateTimeImmutable $at): ?array {
        $selected=null;
        foreach($rates as $rate) { if($rate['at']<=$at) $selected=$rate; else break; }
        return $selected;
    }

    private function turnNumber(string $turn): ?int {
        preg_match('/\d+/', $turn, $match); $value=$match[0]??'';
        if(in_array($value,['1','2','3','4'],true)) return (int)$value;
        if(in_array($value,['11','21','31','41'],true)) return (int)$value[0];
        return null;
    }

    private function turnStart(DateTimeImmutable $date, int $turn): DateTimeImmutable {
        $time=[1=>[0,0],2=>[6,0],3=>[14,0],4=>[22,0]][$turn]??null;
        if($time===null) throw new RuntimeException('Turno inválido para tipo de cambio.');
        return $date->setTime($time[0],$time[1]);
    }
}
