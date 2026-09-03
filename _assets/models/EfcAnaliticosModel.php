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
        $stmt = $this->db->prepare("SELECT TOP 8 P.id,COALESCE(C.fecha_efectiva,P.fecha_reportada) AS fecha_reportada,P.hora_original,P.remesa_numero,P.cuenta_mn_original,P.dice_contener_mn,P.real_mn,P.diferencia_mn,P.dice_contener_usd,P.real_usd,P.diferencia_usd,I.nombre_archivo FROM dbo.efc_conc_analiticos_papeletas P JOIN dbo.efc_conc_analiticos_importaciones I ON I.id=P.importacion_id LEFT JOIN dbo.efc_conc_analiticos_correcciones_fecha C ON C.papeleta_id=P.id AND C.activo=1 WHERE P.estacion_id=? AND COALESCE(C.fecha_efectiva,P.fecha_reportada) BETWEEN DATEADD(day,-7,?) AND DATEADD(day,7,?) AND I.estado='IMPORTADA' ORDER BY ABS(DATEDIFF(day,COALESCE(C.fecha_efectiva,P.fecha_reportada),?)),P.id DESC");
        $stmt->execute([$stationId,$date,$date,$date]);
        $items=[];
        while ($row=$stmt->fetch(PDO::FETCH_ASSOC)) {
            $reasons=['Misma estación'];
            $paperDate=$this->dateValue($row['fecha_reportada']);
            if ($paperDate === $date) $reasons[]='Misma fecha';
            $targets=array_filter([$cgAmount,$bankAmount],static fn($value)=>$value!==null);
            foreach ($targets as $target) foreach ([(float)($row['dice_contener_mn'] ?? 0),(float)($row['real_mn'] ?? 0)] as $amount) if ($amount > 0 && abs($amount-$target) <= 20) { $reasons[]='Importe cercano'; break 2; }
            $items[]=['id'=>(int)$row['id'],'date'=>$paperDate,'time'=>$row['hora_original'],'remittance'=>$row['remesa_numero'],'account'=>$row['cuenta_mn_original'],'declared_mn'=>(float)($row['dice_contener_mn'] ?? 0),'real_mn'=>(float)($row['real_mn'] ?? 0),'difference_mn'=>(float)($row['diferencia_mn'] ?? 0),'declared_usd'=>(float)($row['dice_contener_usd'] ?? 0),'real_usd'=>(float)($row['real_usd'] ?? 0),'difference_usd'=>(float)($row['diferencia_usd'] ?? 0),'file'=>$row['nombre_archivo'],'reasons'=>$reasons];
        }
        return $items;
    }

    /** Papeletas del periodo para el modo informativo CG vs Analíticos. */
    public function period(int $stationId, int $year, int $month): array {
        if (!$stationId || $year < 2020 || $month < 1 || $month > 12) throw new RuntimeException('Estación o periodo inválidos.');
        $stmt=$this->db->prepare("SELECT P.id,P.importacion_id,COALESCE(C.fecha_efectiva,P.fecha_reportada) AS fecha_reportada,P.hora_original,P.remesa_numero,P.cuenta_mn_original,P.dice_contener_mn,P.real_mn,P.diferencia_mn,P.dice_contener_usd,P.real_usd,P.diferencia_usd,I.nombre_archivo FROM dbo.efc_conc_analiticos_papeletas P JOIN dbo.efc_conc_analiticos_importaciones I ON I.id=P.importacion_id LEFT JOIN dbo.efc_conc_analiticos_correcciones_fecha C ON C.papeleta_id=P.id AND C.activo=1 WHERE P.estacion_id=? AND YEAR(COALESCE(C.fecha_efectiva,P.fecha_reportada))=? AND MONTH(COALESCE(C.fecha_efectiva,P.fecha_reportada))=? AND I.estado='IMPORTADA' ORDER BY COALESCE(C.fecha_efectiva,P.fecha_reportada),P.id");
        $stmt->execute([$stationId,$year,$month]); $out=[];
        while($row=$stmt->fetch(PDO::FETCH_ASSOC)) $out[]=['id'=>(int)$row['id'],'importation_id'=>(int)$row['importacion_id'],'date'=>$this->dateValue($row['fecha_reportada']),'time'=>$row['hora_original'],'remittance'=>$row['remesa_numero'],'account'=>$row['cuenta_mn_original'],'declared_mn'=>(float)($row['dice_contener_mn']??0),'real_mn'=>(float)($row['real_mn']??0),'difference_mn'=>(float)($row['diferencia_mn']??0),'declared_usd'=>(float)($row['dice_contener_usd']??0),'real_usd'=>(float)($row['real_usd']??0),'difference_usd'=>(float)($row['diferencia_usd']??0),'file'=>$row['nombre_archivo']];
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
    public function workspace(int $stationId, int $year, int $month): array {
        if (!$stationId || $year < 2020 || $month < 1 || $month > 12) throw new RuntimeException('Estación o periodo inválidos.');
        $papers=$this->db->prepare("SELECT P.id,P.importacion_id,P.fecha_reportada AS fecha_original,C.fecha_efectiva,P.hora_original,P.remesa_numero,P.cuenta_mn_original,P.dice_contener_mn,P.real_mn,P.dice_contener_usd,P.real_usd,I.nombre_archivo FROM dbo.efc_conc_analiticos_papeletas P JOIN dbo.efc_conc_analiticos_importaciones I ON I.id=P.importacion_id LEFT JOIN dbo.efc_conc_analiticos_correcciones_fecha C ON C.papeleta_id=P.id AND C.activo=1 WHERE P.estacion_id=? AND I.estado='IMPORTADA' ORDER BY P.id DESC");
        $papers->execute([$stationId]); $items=[];
        while($row=$papers->fetch(PDO::FETCH_ASSOC)) $items[]=['id'=>(int)$row['id'],'importation_id'=>(int)$row['importacion_id'],'date'=>$this->dateValue($row['fecha_efectiva'] ?: $row['fecha_original']),'original_date'=>$this->dateValue($row['fecha_original']),'effective_date'=>$row['fecha_efectiva']?$this->dateValue($row['fecha_efectiva']):null,'time'=>$row['hora_original'],'remittance'=>$row['remesa_numero'],'account'=>$row['cuenta_mn_original'],'declared_mn'=>(float)($row['dice_contener_mn']??0),'real_mn'=>(float)($row['real_mn']??0),'declared_usd'=>(float)($row['dice_contener_usd']??0),'real_usd'=>(float)($row['real_usd']??0),'file'=>$row['nombre_archivo']];
        $links=$this->db->prepare("SELECT id,papeleta_id,fecha_cg,turno,concepto,importe_cg,criterio,tipo_cambio_usd AS exchange_rate FROM dbo.efc_conc_analiticos_vinculos WHERE estacion_id=? AND YEAR(fecha_cg)=? AND MONTH(fecha_cg)=? AND activo=1");
        $links->execute([$stationId,$year,$month]);
        return ['papers'=>$items,'links'=>$links->fetchAll(PDO::FETCH_ASSOC)];
    }

    /**
     * Consola independiente de papeletas. La fecha efectiva es una capa propia
     * de TG: fecha_reportada y el Excel original nunca se actualizan.
     */
    public function papersForManagement(int $stationId, int $year, int $month): array {
        if ($year < 2020 || $month < 1 || $month > 12) throw new RuntimeException('Periodo inválido.');
        $sql = "SELECT P.id,P.importacion_id,P.fecha_reportada AS fecha_original,C.id AS correccion_id,C.fecha_efectiva,C.usuario_id AS correccion_usuario,C.creado_en AS correccion_creada,
                       P.remesa_numero,P.cuenta_mn_original,P.dice_contener_mn,P.real_mn,P.dice_contener_usd,P.real_usd,P.estacion_id,E.Nombre AS estacion_nombre,I.nombre_archivo,
                       CASE WHEN EXISTS(SELECT 1 FROM dbo.efc_conc_analiticos_vinculos V WHERE V.papeleta_id=P.id AND V.activo=1) THEN 1 ELSE 0 END AS vinculada
                FROM dbo.efc_conc_analiticos_papeletas P
                JOIN dbo.efc_conc_analiticos_importaciones I ON I.id=P.importacion_id
                LEFT JOIN TG.dbo.Estaciones E ON E.Codigo=P.estacion_id
                LEFT JOIN dbo.efc_conc_analiticos_correcciones_fecha C ON C.papeleta_id=P.id AND C.activo=1
                WHERE I.estado='IMPORTADA'
                  AND YEAR(COALESCE(C.fecha_efectiva,P.fecha_reportada))=?
                  AND MONTH(COALESCE(C.fecha_efectiva,P.fecha_reportada))=?";
        $params=[$year,$month];
        if ($stationId > 0) { $sql .= ' AND P.estacion_id=?'; $params[]=$stationId; }
        $sql .= ' ORDER BY COALESCE(C.fecha_efectiva,P.fecha_reportada) DESC,P.id DESC';
        $stmt=$this->db->prepare($sql); $stmt->execute($params); $items=[];
        while($row=$stmt->fetch(PDO::FETCH_ASSOC)) {
            $items[]=[
                'id'=>(int)$row['id'], 'importation_id'=>(int)$row['importacion_id'],
                'original_date'=>$this->dateValue($row['fecha_original']),
                'effective_date'=>$row['fecha_efectiva']?$this->dateValue($row['fecha_efectiva']):null,
                'date'=>$this->dateValue($row['fecha_efectiva'] ?: $row['fecha_original']),
                'remittance'=>$this->normaliseRemittance($row['remesa_numero']),
                'account'=>$row['cuenta_mn_original'], 'station_id'=>(int)$row['estacion_id'], 'station'=>$row['estacion_nombre'],
                'declared_mn'=>(float)($row['dice_contener_mn']??0), 'real_mn'=>(float)($row['real_mn']??0),
                'declared_usd'=>(float)($row['dice_contener_usd']??0), 'real_usd'=>(float)($row['real_usd']??0),
                'file'=>$row['nombre_archivo'], 'correction_id'=>$row['correccion_id']?(int)$row['correccion_id']:null,
                'correction_user'=>$row['correccion_usuario']?(int)$row['correccion_usuario']:null,
                'correction_at'=>$row['correccion_creada']?$this->dateTimeValue($row['correccion_creada']):null,
                'linked'=>(bool)$row['vinculada']
            ];
        }
        return $items;
    }

    /** Aplica una misma fecha efectiva a varias papeletas, dejando historial. */
    public function correctDates(array $paperIds, ?string $effectiveDate, int $userId): int {
        $ids=array_values(array_unique(array_filter(array_map('intval',$paperIds),static fn($id)=>$id>0)));
        if (!$ids || count($ids)>100) throw new RuntimeException('Seleccione entre una y 100 papeletas.');
        if ($effectiveDate !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$effectiveDate)) throw new RuntimeException('La fecha indicada no es válida.');
        $marks=implode(',',array_fill(0,count($ids),'?'));
        $this->db->beginTransaction();
        try {
            $locked=$this->db->prepare("SELECT P.id,P.fecha_reportada,CASE WHEN EXISTS(SELECT 1 FROM dbo.efc_conc_analiticos_vinculos V WHERE V.papeleta_id=P.id AND V.activo=1) THEN 1 ELSE 0 END AS vinculada FROM dbo.efc_conc_analiticos_papeletas P WITH (UPDLOCK,HOLDLOCK) WHERE P.id IN ($marks)");
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

    public function link(array $data, int $userId): void {
        $station=(int)($data['station_id']??0); $paper=(int)($data['paper_id']??0); $date=(string)($data['date']??''); $turn=(string)($data['turn']??''); $concept=(string)($data['currency']??''); $amount=(float)($data['amount']??0); $criterion=(string)($data['criterion']??'MANUAL');
        if(!$station||!$paper||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)||$turn===''||!in_array($concept,['MN','MORRALLA','USD'],true)||$amount<=0) throw new RuntimeException('Datos de vínculo inválidos.');
        $this->db->beginTransaction();
        try {
            $check=$this->db->prepare("SELECT id FROM dbo.efc_conc_analiticos_papeletas WHERE id=? AND estacion_id=?"); $check->execute([$paper,$station]); if(!$check->fetch()) throw new RuntimeException('La papeleta no pertenece a la estación seleccionada.');
            // El TC enviado por el navegador no es una fuente válida: siempre se
            // vuelve a resolver contra Cotizaciones para conservar trazabilidad.
            $exchangeRate=$concept==='USD' ? $this->exchangeRateForTurn($station,$date,$turn) : null;
            if($concept==='USD' && ($exchangeRate===null || $exchangeRate<=0)) throw new RuntimeException('No existe tipo de cambio histórico para este turno.');
            $this->db->prepare("UPDATE dbo.efc_conc_analiticos_vinculos SET activo=0,actualizado_en=GETDATE() WHERE activo=1 AND (papeleta_id=? OR (estacion_id=? AND fecha_cg=? AND turno=? AND concepto=?))")->execute([$paper,$station,$date,$turn,$concept]);
            $this->db->prepare("INSERT dbo.efc_conc_analiticos_vinculos(estacion_id,papeleta_id,fecha_cg,turno,concepto,importe_cg,criterio,tipo_cambio_usd,usuario_id) VALUES(?,?,?,?,?,?,?,?,?)")->execute([$station,$paper,$date,$turn,$concept,$amount,$criterion,$concept==='USD'?$exchangeRate:null,$userId?:null]);
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

    public function unlink(int $linkId): void { $stmt=$this->db->prepare("UPDATE dbo.efc_conc_analiticos_vinculos SET activo=0,actualizado_en=GETDATE() WHERE id=? AND activo=1"); $stmt->execute([$linkId]); }

    private function ensureLinksSchema(): void {
        $this->db->exec("IF OBJECT_ID('dbo.efc_conc_analiticos_vinculos','U') IS NULL CREATE TABLE dbo.efc_conc_analiticos_vinculos (id INT IDENTITY PRIMARY KEY, estacion_id INT NOT NULL, papeleta_id INT NOT NULL, fecha_cg DATE NOT NULL, turno NVARCHAR(40) NOT NULL, concepto VARCHAR(10) NOT NULL, importe_cg DECIMAL(18,2) NOT NULL, criterio VARCHAR(20) NOT NULL, tipo_cambio_usd DECIMAL(18,6) NULL, usuario_id INT NULL, activo BIT NOT NULL DEFAULT 1, creado_en DATETIME NOT NULL DEFAULT GETDATE(), actualizado_en DATETIME NULL, CONSTRAINT FK_efc_conc_analiticos_vinculos_papeleta FOREIGN KEY(papeleta_id) REFERENCES dbo.efc_conc_analiticos_papeletas(id))");
        $this->db->exec("IF COL_LENGTH('dbo.efc_conc_analiticos_vinculos','tipo_cambio_usd') IS NULL ALTER TABLE dbo.efc_conc_analiticos_vinculos ADD tipo_cambio_usd DECIMAL(18,6) NULL");
        $this->db->exec("IF NOT EXISTS(SELECT 1 FROM sys.indexes WHERE name='UX_efc_conc_analiticos_vinculos_papeleta_activa') CREATE UNIQUE INDEX UX_efc_conc_analiticos_vinculos_papeleta_activa ON dbo.efc_conc_analiticos_vinculos(papeleta_id) WHERE activo=1");
        $this->db->exec("IF NOT EXISTS(SELECT 1 FROM sys.indexes WHERE name='UX_efc_conc_analiticos_vinculos_turno_activo') CREATE UNIQUE INDEX UX_efc_conc_analiticos_vinculos_turno_activo ON dbo.efc_conc_analiticos_vinculos(estacion_id,fecha_cg,turno,concepto) WHERE activo=1");
        $this->db->exec("IF OBJECT_ID('dbo.efc_conc_analiticos_correcciones_fecha','U') IS NULL CREATE TABLE dbo.efc_conc_analiticos_correcciones_fecha (id INT IDENTITY PRIMARY KEY,papeleta_id INT NOT NULL,fecha_original DATE NOT NULL,fecha_efectiva DATE NOT NULL,usuario_id INT NULL,activo BIT NOT NULL DEFAULT 1,creado_en DATETIME NOT NULL DEFAULT GETDATE(),actualizado_en DATETIME NULL,actualizado_por INT NULL,CONSTRAINT FK_efc_conc_analiticos_correcciones_fecha_papeleta FOREIGN KEY(papeleta_id) REFERENCES dbo.efc_conc_analiticos_papeletas(id))");
        $this->db->exec("IF NOT EXISTS(SELECT 1 FROM sys.indexes WHERE name='UX_efc_conc_analiticos_corr_fecha_activa') CREATE UNIQUE INDEX UX_efc_conc_analiticos_corr_fecha_activa ON dbo.efc_conc_analiticos_correcciones_fecha(papeleta_id) WHERE activo=1");
    }

    private function dateValue($value): string { return $value instanceof DateTimeInterface ? $value->format('Y-m-d') : substr((string)$value,0,10); }
    private function dateTimeValue($value): string { return $value instanceof DateTimeInterface ? $value->format('Y-m-d H:i') : substr((string)$value,0,16); }
    private function normaliseRemittance($value): string { $text=trim((string)$value); return preg_replace('/\.0+$/','',$text) ?: $text; }

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
