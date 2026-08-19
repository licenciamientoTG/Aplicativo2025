<?php

class EfcConciliacionModel {
    private PDO $db;

    public function __construct() {
        $this->db = new PDO('sqlsrv:Server=192.168.0.6;Database=TG;TrustServerCertificate=yes', 'cguser', 'sahei1712');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->ensureSchema();
    }

    private function ensureSchema(): void {
        $this->db->exec("IF OBJECT_ID('dbo.efc_conc_grupos','U') IS NULL CREATE TABLE dbo.efc_conc_grupos (id INT IDENTITY PRIMARY KEY, estacion_id INT NOT NULL, fecha_operativa DATE NOT NULL, turno VARCHAR(20) NULL, concepto VARCHAR(20) NULL, tipo VARCHAR(20) NOT NULL, total_controlgas DECIMAL(18,2) NOT NULL, total_banorte DECIMAL(18,2) NOT NULL, diferencia DECIMAL(18,2) NOT NULL, estado VARCHAR(20) NOT NULL DEFAULT 'ACTIVA', creado_por INT NULL, creado_en DATETIME NOT NULL DEFAULT GETDATE(), cancelado_por INT NULL, cancelado_en DATETIME NULL)");
        $this->db->exec("IF OBJECT_ID('dbo.efc_conc_partidas','U') IS NULL CREATE TABLE dbo.efc_conc_partidas (id INT IDENTITY PRIMARY KEY, grupo_id INT NOT NULL, origen VARCHAR(12) NOT NULL, clave_externa VARCHAR(180) NOT NULL, movimiento_bancario_id INT NULL, fecha_operacion DATE NOT NULL, turno VARCHAR(20) NULL, concepto VARCHAR(20) NULL, importe DECIMAL(18,2) NOT NULL, referencia VARCHAR(255) NULL, estacion_id INT NULL, activo BIT NOT NULL DEFAULT 1, CONSTRAINT FK_efc_conc_partidas_grupo FOREIGN KEY(grupo_id) REFERENCES dbo.efc_conc_grupos(id))");
        $this->db->exec("IF OBJECT_ID('dbo.efc_conc_correcciones_banco','U') IS NULL CREATE TABLE dbo.efc_conc_correcciones_banco (id INT IDENTITY PRIMARY KEY, movimiento_bancario_id INT NOT NULL UNIQUE, estacion_id INT NOT NULL, creado_por INT NULL, creado_en DATETIME NOT NULL DEFAULT GETDATE(), actualizado_por INT NULL, actualizado_en DATETIME NULL)");
        $this->db->exec("IF OBJECT_ID('dbo.efc_conc_bitacora','U') IS NULL CREATE TABLE dbo.efc_conc_bitacora (id INT IDENTITY PRIMARY KEY, grupo_id INT NULL, movimiento_bancario_id INT NULL, accion VARCHAR(40) NOT NULL, detalle NVARCHAR(MAX) NULL, usuario_id INT NULL, creado_en DATETIME NOT NULL DEFAULT GETDATE())");
        $this->db->exec("IF NOT EXISTS(SELECT 1 FROM sys.columns WHERE object_id=OBJECT_ID('dbo.efc_conc_partidas') AND name='activo') ALTER TABLE dbo.efc_conc_partidas ADD activo BIT NOT NULL DEFAULT 1");
        $this->db->exec("IF NOT EXISTS(SELECT 1 FROM sys.indexes WHERE name='UX_efc_conc_partida_cg_activa') CREATE UNIQUE INDEX UX_efc_conc_partida_cg_activa ON dbo.efc_conc_partidas(clave_externa) WHERE origen='CG' AND activo=1");
        $this->db->exec("IF NOT EXISTS(SELECT 1 FROM sys.indexes WHERE name='UX_efc_conc_partida_banco_activa') CREATE UNIQUE INDEX UX_efc_conc_partida_banco_activa ON dbo.efc_conc_partidas(movimiento_bancario_id) WHERE origen='BANCO' AND activo=1 AND movimiento_bancario_id IS NOT NULL");
    }

    public function correction(int $movementId, ?int $stationId, int $userId): void {
        $active = $this->db->prepare("SELECT TOP 1 p.grupo_id FROM dbo.efc_conc_partidas p JOIN dbo.efc_conc_grupos g ON g.id=p.grupo_id WHERE p.movimiento_bancario_id=? AND g.estado='ACTIVA'");
        $active->execute([$movementId]);
        if ($active->fetchColumn()) throw new RuntimeException('El depósito ya está conciliado; primero debe deshacer la conciliación.');
        $this->db->beginTransaction();
        try {
            if ($stationId) {
                $check = $this->db->prepare("SELECT 1 FROM TG.dbo.Estaciones WHERE Codigo=? AND RFC='DGA930823KD3'"); $check->execute([$stationId]);
                if (!$check->fetchColumn()) throw new RuntimeException('Estación Díaz Gas inválida.');
                $update = $this->db->prepare("UPDATE dbo.efc_conc_correcciones_banco SET estacion_id=?, actualizado_por=?, actualizado_en=GETDATE() WHERE movimiento_bancario_id=?");
                $update->execute([$stationId, $userId, $movementId]);
                if ($update->rowCount() === 0) {
                    $this->db->prepare("INSERT dbo.efc_conc_correcciones_banco(movimiento_bancario_id,estacion_id,creado_por) VALUES(?,?,?)")->execute([$movementId, $stationId, $userId]);
                }
                $this->log(null,$movementId,'CORRECCION_ESTACION',json_encode(['estacion_id'=>$stationId]),$userId);
            } else { $this->db->prepare("DELETE FROM dbo.efc_conc_correcciones_banco WHERE movimiento_bancario_id=?")->execute([$movementId]); $this->log(null,$movementId,'RESTAURAR_REFERENCIA',null,$userId); }
            $this->db->commit();
        } catch(Throwable $e) { $this->db->rollBack(); throw $e; }
    }

    public function saveGroup(array $group, array $cg, array $bank, int $userId): int {
        if (count($cg)!==1 || count($bank)<1 || count($bank)>2) throw new RuntimeException('La conciliación requiere un turno y uno o dos depósitos.');
        $this->db->beginTransaction();
        try {
            $q=$this->db->prepare("INSERT dbo.efc_conc_grupos(estacion_id,fecha_operativa,turno,concepto,tipo,total_controlgas,total_banorte,diferencia,creado_por) OUTPUT INSERTED.id VALUES(?,?,?,?,?,?,?,?,?)");
            $q->execute([$group['station_id'],$cg[0]['date'],$cg[0]['turn'],$cg[0]['currency'],$group['type'],$group['cg_total'],$group['bank_total'],$group['difference'],$userId]); $id=(int)$q->fetchColumn();
            $part=$this->db->prepare("INSERT dbo.efc_conc_partidas(grupo_id,origen,clave_externa,movimiento_bancario_id,fecha_operacion,turno,concepto,importe,referencia,estacion_id) VALUES(?,?,?,?,?,?,?,?,?,?)");
            $part->execute([$id,'CG',$cg[0]['id'],null,$cg[0]['date'],$cg[0]['turn'],$cg[0]['currency'],$cg[0]['amount'],null,$group['station_id']]);
            foreach($bank as $item) $part->execute([$id,'BANCO',$item['id'],(int)preg_replace('/\D/','',$item['id']),$item['date'],null,null,$item['amount'],$item['reference']??null,$group['station_id']]);
            $this->log($id,null,'CONCILIACION_'.$group['type'],null,$userId); $this->db->commit(); return $id;
        } catch(Throwable $e) { $this->db->rollBack(); throw $e; }
    }
    public function undo(int $groupId,int $userId): void { $this->db->beginTransaction(); try{$this->db->prepare("UPDATE dbo.efc_conc_grupos SET estado='CANCELADA',cancelado_por=?,cancelado_en=GETDATE() WHERE id=? AND estado='ACTIVA'")->execute([$userId,$groupId]);$this->db->prepare("UPDATE dbo.efc_conc_partidas SET activo=0 WHERE grupo_id=?")->execute([$groupId]);$this->log($groupId,null,'DESHACER',null,$userId);$this->db->commit();}catch(Throwable $e){$this->db->rollBack();throw $e;} }
    private function log(?int $groupId,?int $movementId,string $action,?string $detail,int $userId): void { $this->db->prepare("INSERT dbo.efc_conc_bitacora(grupo_id,movimiento_bancario_id,accion,detalle,usuario_id) VALUES(?,?,?,?,?)")->execute([$groupId,$movementId,$action,$detail,$userId]); }
}
