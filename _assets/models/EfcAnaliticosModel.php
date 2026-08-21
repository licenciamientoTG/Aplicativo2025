<?php

/**
 * Consulta de evidencia importada por cron/efc_conc_analiticos_diario.py.
 * Esta clase nunca extrae correos, interpreta Excel ni escribe Analíticos.
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
        $stmt = $this->db->prepare("SELECT TOP 8 P.id,P.fecha_reportada,P.hora_original,P.remesa_numero,P.cuenta_mn_original,P.dice_contener_mn,P.real_mn,P.diferencia_mn,P.dice_contener_usd,P.real_usd,P.diferencia_usd,I.nombre_archivo FROM dbo.efc_conc_analiticos_papeletas P JOIN dbo.efc_conc_analiticos_importaciones I ON I.id=P.importacion_id WHERE P.estacion_id=? AND P.fecha_reportada BETWEEN DATEADD(day,-7,?) AND DATEADD(day,7,?) AND I.estado='IMPORTADA' ORDER BY ABS(DATEDIFF(day,P.fecha_reportada,?)),P.id DESC");
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
        $stmt=$this->db->prepare("SELECT P.id,P.importacion_id,P.fecha_reportada,P.hora_original,P.remesa_numero,P.cuenta_mn_original,P.dice_contener_mn,P.real_mn,P.diferencia_mn,P.dice_contener_usd,P.real_usd,P.diferencia_usd,I.nombre_archivo FROM dbo.efc_conc_analiticos_papeletas P JOIN dbo.efc_conc_analiticos_importaciones I ON I.id=P.importacion_id WHERE P.estacion_id=? AND YEAR(P.fecha_reportada)=? AND MONTH(P.fecha_reportada)=? AND I.estado='IMPORTADA' ORDER BY P.fecha_reportada,P.id");
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
        $papers=$this->db->prepare("SELECT P.id,P.importacion_id,P.fecha_reportada,P.hora_original,P.remesa_numero,P.cuenta_mn_original,P.dice_contener_mn,P.real_mn,P.dice_contener_usd,P.real_usd,I.nombre_archivo FROM dbo.efc_conc_analiticos_papeletas P JOIN dbo.efc_conc_analiticos_importaciones I ON I.id=P.importacion_id WHERE P.estacion_id=? AND I.estado='IMPORTADA' ORDER BY P.id DESC");
        $papers->execute([$stationId]); $items=[];
        while($row=$papers->fetch(PDO::FETCH_ASSOC)) $items[]=['id'=>(int)$row['id'],'importation_id'=>(int)$row['importacion_id'],'date'=>$this->dateValue($row['fecha_reportada']),'time'=>$row['hora_original'],'remittance'=>$row['remesa_numero'],'account'=>$row['cuenta_mn_original'],'declared_mn'=>(float)($row['dice_contener_mn']??0),'real_mn'=>(float)($row['real_mn']??0),'declared_usd'=>(float)($row['dice_contener_usd']??0),'real_usd'=>(float)($row['real_usd']??0),'file'=>$row['nombre_archivo']];
        $links=$this->db->prepare("SELECT id,papeleta_id,fecha_cg,turno,concepto,importe_cg,criterio FROM dbo.efc_conc_analiticos_vinculos WHERE estacion_id=? AND YEAR(fecha_cg)=? AND MONTH(fecha_cg)=? AND activo=1");
        $links->execute([$stationId,$year,$month]);
        return ['papers'=>$items,'links'=>$links->fetchAll(PDO::FETCH_ASSOC)];
    }

    public function link(array $data, int $userId): void {
        $station=(int)($data['station_id']??0); $paper=(int)($data['paper_id']??0); $date=(string)($data['date']??''); $turn=(string)($data['turn']??''); $concept=(string)($data['currency']??''); $amount=(float)($data['amount']??0); $criterion=(string)($data['criterion']??'MANUAL');
        if(!$station||!$paper||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)||$turn===''||!in_array($concept,['MN','USD'],true)||$amount<=0) throw new RuntimeException('Datos de vínculo inválidos.');
        $this->db->beginTransaction();
        try {
            $check=$this->db->prepare("SELECT id FROM dbo.efc_conc_analiticos_papeletas WHERE id=? AND estacion_id=?"); $check->execute([$paper,$station]); if(!$check->fetch()) throw new RuntimeException('La papeleta no pertenece a la estación seleccionada.');
            $this->db->prepare("UPDATE dbo.efc_conc_analiticos_vinculos SET activo=0,actualizado_en=GETDATE() WHERE activo=1 AND (papeleta_id=? OR (estacion_id=? AND fecha_cg=? AND turno=? AND concepto=?))")->execute([$paper,$station,$date,$turn,$concept]);
            $this->db->prepare("INSERT dbo.efc_conc_analiticos_vinculos(estacion_id,papeleta_id,fecha_cg,turno,concepto,importe_cg,criterio,usuario_id) VALUES(?,?,?,?,?,?,?,?)")->execute([$station,$paper,$date,$turn,$concept,$amount,$criterion,$userId?:null]);
            $this->db->commit();
        } catch(Throwable $e) { if($this->db->inTransaction())$this->db->rollBack(); throw $e; }
    }

    public function unlink(int $linkId): void { $stmt=$this->db->prepare("UPDATE dbo.efc_conc_analiticos_vinculos SET activo=0,actualizado_en=GETDATE() WHERE id=? AND activo=1"); $stmt->execute([$linkId]); }

    private function ensureLinksSchema(): void {
        $this->db->exec("IF OBJECT_ID('dbo.efc_conc_analiticos_vinculos','U') IS NULL CREATE TABLE dbo.efc_conc_analiticos_vinculos (id INT IDENTITY PRIMARY KEY, estacion_id INT NOT NULL, papeleta_id INT NOT NULL, fecha_cg DATE NOT NULL, turno NVARCHAR(40) NOT NULL, concepto VARCHAR(10) NOT NULL, importe_cg DECIMAL(18,2) NOT NULL, criterio VARCHAR(20) NOT NULL, usuario_id INT NULL, activo BIT NOT NULL DEFAULT 1, creado_en DATETIME NOT NULL DEFAULT GETDATE(), actualizado_en DATETIME NULL, CONSTRAINT FK_efc_conc_analiticos_vinculos_papeleta FOREIGN KEY(papeleta_id) REFERENCES dbo.efc_conc_analiticos_papeletas(id))");
        $this->db->exec("IF NOT EXISTS(SELECT 1 FROM sys.indexes WHERE name='UX_efc_conc_analiticos_vinculos_papeleta_activa') CREATE UNIQUE INDEX UX_efc_conc_analiticos_vinculos_papeleta_activa ON dbo.efc_conc_analiticos_vinculos(papeleta_id) WHERE activo=1");
        $this->db->exec("IF NOT EXISTS(SELECT 1 FROM sys.indexes WHERE name='UX_efc_conc_analiticos_vinculos_turno_activo') CREATE UNIQUE INDEX UX_efc_conc_analiticos_vinculos_turno_activo ON dbo.efc_conc_analiticos_vinculos(estacion_id,fecha_cg,turno,concepto) WHERE activo=1");
    }

    private function dateValue($value): string { return $value instanceof DateTimeInterface ? $value->format('Y-m-d') : substr((string)$value,0,10); }
}
