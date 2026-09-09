<?php

class EfcConciliacionModel {
    private PDO $db;
    private array $bankStationCatalogCache = [];
    private const TOLERANCE = 20.00;
    private const COMPANY_ACCOUNTS = [
        'DIAZ GAS' => ['0185322470', '369'],
        'FORANEAS'  => ['3281', '8837', '8520', '7291', '2570', '7533', '2627', '5247', '7604', '0031'],
        'GASOMEX'   => ['8504', '4409', '4547', '8214', '8492', '4412', '4777', '4669', '3678', '4457'],
    ];
    /* La razón social fiscal no identifica por sí sola la operación. Gasomex
       tiene estaciones con RFC propio; este es el catálogo operativo usado por
       conciliación y sus cuentas bancarias. */
    private const COMPANY_STATIONS = [
        'DIAZ GAS' => [2,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,22,30,33],
        'FORANEAS' => [19,21,31,32,34,35,36,37,38,39,199],
        'GASOMEX' => [23,24,25,26,27,28,29],
    ];
    /* Cuenta → estación operativa. Algunas cuentas atienden más de una
       estación: en esos casos se conserva la validación por referencia. */
    private const COMPANY_ACCOUNT_STATIONS = [
        'DIAZ GAS' => ['369' => ['PARRAL']],
        'FORANEAS' => [
            '3281'=>['VILLA AHUMADA'], '8837'=>['DELICIAS'], '8520'=>['PLUTARCO'],
            '7291'=>['PICACHOS'], '2570'=>['PICACHOS'], '7533'=>['VENTANAS'],
            '2627'=>['VENTANAS'], '5247'=>['CASTANO'], '7604'=>['GABRIELA MISTRAL'],
            '0031'=>['PUERTECITO','SAN RAFAEL','COLOSIO','JESUS MARIA'],
        ],
        'GASOMEX' => [
            '8504'=>['JARUDO'], '4409'=>['JARUDO'], '4547'=>['EJERCITO'],
            '8214'=>['EJERCITO','FUENTES'], '8492'=>['FUENTES','CLARA'],
            '4412'=>['CLARA'], '4777'=>['CLARA','SOLIS'], '4669'=>['SOLIS','SANTIAGO'],
            '3678'=>['SANTIAGO','SATELITE'], '4457'=>['SATELITE'],
        ],
    ];

    public static function normalizeCompany(?string $company): string {
        $company = strtoupper(trim((string)$company));
        return array_key_exists($company, self::COMPANY_ACCOUNTS) ? $company : 'DIAZ GAS';
    }

    public static function accountSuffixes(string $company): array {
        return self::COMPANY_ACCOUNTS[self::normalizeCompany($company)];
    }

    /** Todas las cuentas conocidas; la cuenta no define la empresa del depósito. */
    public static function allAccountSuffixes(): array {
        return array_values(array_unique(array_merge(...array_values(self::COMPANY_ACCOUNTS))));
    }

    public static function stationIds(string $company): array {
        return self::COMPANY_STATIONS[self::normalizeCompany($company)];
    }

    public static function stationCompany(int $stationId): ?string {
        foreach (self::COMPANY_STATIONS as $company => $stationIds) {
            if (in_array($stationId, $stationIds, true)) return $company;
        }
        return null;
    }

    public static function stationSqlCondition(string $company, string $alias = 'E'): string {
        $stationIds = implode(',', self::stationIds($company));
        $column = $alias === '' ? 'Codigo' : "$alias.Codigo";
        return "$column IN ($stationIds)";
    }

    public static function accountStationNames(string $company, ?string $account): array {
        $company = self::normalizeCompany($company);
        $account = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)$account));
        foreach (self::COMPANY_ACCOUNT_STATIONS[$company] ?? [] as $suffix => $names) {
            if (str_ends_with($account, strtoupper($suffix))) return $names;
        }
        return [];
    }

    public static function accountStationNamesAll(?string $account): array {
        $account = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)$account));
        $names = [];
        foreach (self::COMPANY_ACCOUNT_STATIONS as $maps) {
            foreach ($maps as $suffix => $stations) {
                if (str_ends_with($account, strtoupper($suffix))) $names = array_merge($names, $stations);
            }
        }
        return array_values(array_unique($names));
    }

    public function __construct() {
        $this->db = new PDO('sqlsrv:Server=192.168.0.6;Database=TG;TrustServerCertificate=yes', 'cguser', 'sahei1712');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->ensureSchema();
    }

    private function ensureSchema(): void {
        $this->db->exec("IF OBJECT_ID('dbo.efc_conc_grupos','U') IS NULL CREATE TABLE dbo.efc_conc_grupos (id INT IDENTITY PRIMARY KEY, estacion_id INT NOT NULL, fecha_operativa DATE NOT NULL, turno VARCHAR(20) NULL, concepto VARCHAR(20) NULL, tipo VARCHAR(30) NOT NULL, total_controlgas DECIMAL(18,2) NOT NULL, total_banorte DECIMAL(18,2) NOT NULL, diferencia DECIMAL(18,2) NOT NULL, estado VARCHAR(20) NOT NULL DEFAULT 'ACTIVA', creado_por INT NULL, creado_en DATETIME NOT NULL DEFAULT GETDATE(), cancelado_por INT NULL, cancelado_en DATETIME NULL)");
        $this->db->exec("IF OBJECT_ID('dbo.efc_conc_partidas','U') IS NULL CREATE TABLE dbo.efc_conc_partidas (id INT IDENTITY PRIMARY KEY, grupo_id INT NOT NULL, origen VARCHAR(12) NOT NULL, clave_externa VARCHAR(180) NOT NULL, movimiento_bancario_id INT NULL, fecha_operacion DATE NOT NULL, turno VARCHAR(20) NULL, concepto VARCHAR(20) NULL, importe DECIMAL(18,2) NOT NULL, referencia VARCHAR(255) NULL, estacion_id INT NULL, activo BIT NOT NULL DEFAULT 1, CONSTRAINT FK_efc_conc_partidas_grupo FOREIGN KEY(grupo_id) REFERENCES dbo.efc_conc_grupos(id))");
        $this->db->exec("IF OBJECT_ID('dbo.efc_conc_correcciones_banco','U') IS NULL CREATE TABLE dbo.efc_conc_correcciones_banco (id INT IDENTITY PRIMARY KEY, movimiento_bancario_id INT NOT NULL UNIQUE, estacion_id INT NOT NULL, creado_por INT NULL, creado_en DATETIME NOT NULL DEFAULT GETDATE(), actualizado_por INT NULL, actualizado_en DATETIME NULL)");
        $this->db->exec("IF OBJECT_ID('dbo.efc_conc_bitacora','U') IS NULL CREATE TABLE dbo.efc_conc_bitacora (id INT IDENTITY PRIMARY KEY, grupo_id INT NULL, movimiento_bancario_id INT NULL, accion VARCHAR(40) NOT NULL, detalle NVARCHAR(MAX) NULL, usuario_id INT NULL, creado_en DATETIME NOT NULL DEFAULT GETDATE())");
        $this->db->exec("IF OBJECT_ID('dbo.efc_conc_reclasificaciones_cg','U') IS NULL CREATE TABLE dbo.efc_conc_reclasificaciones_cg (id INT IDENTITY PRIMARY KEY, estacion_id INT NOT NULL, fecha_operativa DATE NOT NULL, turno VARCHAR(20) NOT NULL, concepto_original VARCHAR(20) NOT NULL, clave_original VARCHAR(180) NOT NULL, importe_original DECIMAL(18,2) NOT NULL, estado VARCHAR(20) NOT NULL DEFAULT 'ACTIVA', creado_por INT NULL, creado_en DATETIME NOT NULL DEFAULT GETDATE(), cancelado_por INT NULL, cancelado_en DATETIME NULL)");
        $this->db->exec("IF OBJECT_ID('dbo.efc_conc_reclasificaciones_cg_partidas','U') IS NULL CREATE TABLE dbo.efc_conc_reclasificaciones_cg_partidas (id INT IDENTITY PRIMARY KEY, reclasificacion_id INT NOT NULL, concepto VARCHAR(20) NOT NULL, importe DECIMAL(18,2) NOT NULL, movimiento_bancario_id INT NULL, grupo_id INT NULL, estado VARCHAR(20) NOT NULL DEFAULT 'ACTIVA', creado_en DATETIME NOT NULL DEFAULT GETDATE(), CONSTRAINT FK_efc_conc_reclasificacion_partida FOREIGN KEY(reclasificacion_id) REFERENCES dbo.efc_conc_reclasificaciones_cg(id), CONSTRAINT UQ_efc_conc_reclasificacion_concepto UNIQUE(reclasificacion_id,concepto))");
        $this->db->exec("IF NOT EXISTS(SELECT 1 FROM sys.columns WHERE object_id=OBJECT_ID('dbo.efc_conc_partidas') AND name='activo') ALTER TABLE dbo.efc_conc_partidas ADD activo BIT NOT NULL DEFAULT 1");
        $this->db->exec("IF NOT EXISTS(SELECT 1 FROM sys.indexes WHERE name='UX_efc_conc_partida_cg_activa') CREATE UNIQUE INDEX UX_efc_conc_partida_cg_activa ON dbo.efc_conc_partidas(clave_externa) WHERE origen='CG' AND activo=1");
        $this->db->exec("IF NOT EXISTS(SELECT 1 FROM sys.indexes WHERE name='UX_efc_conc_partida_banco_activa') CREATE UNIQUE INDEX UX_efc_conc_partida_banco_activa ON dbo.efc_conc_partidas(movimiento_bancario_id) WHERE origen='BANCO' AND activo=1 AND movimiento_bancario_id IS NOT NULL");
        $this->db->exec("IF NOT EXISTS(SELECT 1 FROM sys.indexes WHERE name='UX_efc_conc_reclasificacion_activa') CREATE UNIQUE INDEX UX_efc_conc_reclasificacion_activa ON dbo.efc_conc_reclasificaciones_cg(clave_original) WHERE estado='ACTIVA'");
        $this->db->exec("IF OBJECT_ID('dbo.efc_conc_transitos','U') IS NULL CREATE TABLE dbo.efc_conc_transitos (id INT IDENTITY PRIMARY KEY, estacion_id INT NOT NULL, clave_externa VARCHAR(180) NOT NULL, fecha_origen DATE NOT NULL, mes_origen CHAR(7) NOT NULL, mes_destino CHAR(7) NOT NULL, turno VARCHAR(20) NOT NULL, concepto VARCHAR(20) NOT NULL, importe DECIMAL(18,2) NOT NULL, estado VARCHAR(20) NOT NULL DEFAULT 'PENDIENTE', descripcion VARCHAR(300) NULL, creado_por INT NULL, creado_en DATETIME NOT NULL DEFAULT GETDATE(), cancelado_por INT NULL, cancelado_en DATETIME NULL)");
        $this->db->exec("IF NOT EXISTS(SELECT 1 FROM sys.indexes WHERE name='UX_efc_conc_transito_activo') CREATE UNIQUE INDEX UX_efc_conc_transito_activo ON dbo.efc_conc_transitos(estacion_id,clave_externa) WHERE estado='PENDIENTE'");
        $this->db->exec("IF OBJECT_ID('dbo.efc_conc_cierres','U') IS NULL CREATE TABLE dbo.efc_conc_cierres (id INT IDENTITY PRIMARY KEY, estacion_id INT NOT NULL, mes CHAR(7) NOT NULL, concepto VARCHAR(20) NOT NULL, total_controlgas DECIMAL(18,2) NOT NULL DEFAULT 0, total_banco DECIMAL(18,2) NOT NULL DEFAULT 0, total_regio_declarado DECIMAL(18,2) NOT NULL DEFAULT 0, total_regio_real DECIMAL(18,2) NOT NULL DEFAULT 0, total_diferencia DECIMAL(18,2) NOT NULL DEFAULT 0, total_transito DECIMAL(18,2) NOT NULL DEFAULT 0, operaciones INT NOT NULL DEFAULT 0, pendientes INT NOT NULL DEFAULT 0, estado VARCHAR(12) NOT NULL DEFAULT 'CERRADO', cerrado_por INT NULL, cerrado_en DATETIME NULL, reabierto_por INT NULL, reabierto_en DATETIME NULL, nota VARCHAR(500) NULL, CONSTRAINT UQ_efc_conc_cierre UNIQUE(estacion_id,mes,concepto))");
    }

    public function closureState(int $stationId, int $year, int $month, string $concept): ?array {
        if (!$stationId || $year < 2020 || $month < 1 || $month > 12 || !in_array($concept,['MN','MORRALLA','USD'],true)) throw new RuntimeException('Parámetros de cierre inválidos.');
        $mes=sprintf('%04d-%02d',$year,$month); $q=$this->db->prepare("SELECT TOP 1 id,estacion_id,mes,concepto,total_controlgas,total_banco,total_regio_declarado,total_regio_real,total_diferencia,total_transito,operaciones,pendientes,estado,cerrado_por,cerrado_en,reabierto_por,reabierto_en,nota FROM dbo.efc_conc_cierres WHERE estacion_id=? AND mes=? AND concepto=?"); $q->execute([$stationId,$mes,$concept]); $r=$q->fetch(PDO::FETCH_ASSOC); if(!$r)return null; foreach(['id','estacion_id','operaciones','pendientes'] as $k)$r[$k]=(int)$r[$k]; foreach(['total_controlgas','total_banco','total_regio_declarado','total_regio_real','total_diferencia','total_transito'] as $k)$r[$k]=(float)$r[$k]; return $r;
    }

    public function assertOpen(int $stationId, string $date, string $concept): void { if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)) return; $state=$this->closureState($stationId,(int)substr($date,0,4),(int)substr($date,5,2),$concept); if($state&&$state['estado']==='CERRADO') throw new RuntimeException('La conciliación está cerrada para esta estación, mes y concepto.'); }

    public function closePeriod(array $data, int $userId): array {
        $station=(int)($data['station_id']??0); $year=(int)($data['year']??0); $month=(int)($data['month']??0); $concept=strtoupper(trim((string)($data['concept']??''))); $pending=(int)($data['pending']??0); if(!$station||$year<2020||$month<1||$month>12||!in_array($concept,['MN','MORRALLA','USD'],true)) throw new RuntimeException('Parámetros de cierre inválidos.'); if($pending>0) throw new RuntimeException('No se puede cerrar: existen operaciones pendientes.'); $mes=sprintf('%04d-%02d',$year,$month); if(($this->closureState($station,$year,$month,$concept)['estado']??'')==='CERRADO') throw new RuntimeException('La conciliación ya está cerrada.');
        $q=$this->db->prepare("SELECT ISNULL(SUM(CASE WHEN P.origen='CG' THEN P.importe ELSE 0 END),0) cg,ISNULL(SUM(CASE WHEN P.origen='BANCO' THEN P.importe ELSE 0 END),0) banco,COUNT(DISTINCT G.id) operaciones,ISNULL(SUM(G.diferencia),0) diferencia FROM dbo.efc_conc_grupos G JOIN dbo.efc_conc_partidas P ON P.grupo_id=G.id AND P.activo=1 WHERE G.estacion_id=? AND G.estado='ACTIVA' AND G.concepto=? AND YEAR(G.fecha_operativa)=? AND MONTH(G.fecha_operativa)=?"); $q->execute([$station,$concept,$year,$month]); $r=$q->fetch(PDO::FETCH_ASSOC)?:[]; $trans=$this->db->prepare("SELECT ISNULL(SUM(importe),0) FROM dbo.efc_conc_transitos WHERE estacion_id=? AND mes_origen=? AND concepto=? AND estado='PENDIENTE'"); $trans->execute([$station,$mes,$concept]); $totalTransit=(float)$trans->fetchColumn();
        $ins=$this->db->prepare("INSERT dbo.efc_conc_cierres(estacion_id,mes,concepto,total_controlgas,total_banco,total_diferencia,total_transito,operaciones,pendientes,estado,cerrado_por,cerrado_en) VALUES(?,?,?,?,?,?,?,?,?,'CERRADO',?,GETDATE())"); $ins->execute([$station,$mes,$concept,(float)($r['cg']??0),(float)($r['banco']??0),(float)($r['diferencia']??0),$totalTransit,(int)($r['operaciones']??0),0,$userId]); return $this->closureState($station,$year,$month,$concept)??[];
    }

    public function reopenPeriod(int $stationId,int $year,int $month,string $concept,int $userId): void { $mes=sprintf('%04d-%02d',$year,$month); $q=$this->db->prepare("UPDATE dbo.efc_conc_cierres SET estado='ABIERTO',reabierto_por=?,reabierto_en=GETDATE() WHERE estacion_id=? AND mes=? AND concepto=? AND estado='CERRADO'"); $q->execute([$userId,$stationId,$mes,$concept]); if(!$q->rowCount()) throw new RuntimeException('No existe un cierre activo para reabrir.'); }

    public function summaryDetail(int $stationId, ?int $year=null, ?int $month=null, ?string $concept=null): array {
        // Un grupo tiene una partida CG y otra BANCO. Agrupamos por grupo para
        // que el detalle muestre una sola fila por conciliación.
        $where=["G.estado='ACTIVA'"];
        $params=[];
        if($stationId>0){$where[]='G.estacion_id=?';$params[]=$stationId;}
        if($year){$where[]='YEAR(G.fecha_operativa)=?';$params[]=$year;}
        if($month){$where[]='MONTH(G.fecha_operativa)=?';$params[]=$month;}
        if($concept&&in_array($concept,['MN','MORRALLA','USD'],true)){$where[]='G.concepto=?';$params[]=$concept;}
        $sql="SELECT G.id,CONVERT(VARCHAR(10),G.fecha_operativa,23) fecha,G.estacion_id,E.Nombre estacion_nombre,G.turno,G.concepto,G.tipo,G.total_controlgas,G.total_banorte,G.diferencia,
                MAX(CASE WHEN P.origen='BANCO' THEN P.referencia END) referencia,
                MAX(CASE WHEN P.origen='BANCO' THEN P.movimiento_bancario_id END) movimiento_bancario_id,
                MAX(CASE WHEN P.origen='BANCO' THEN M.descripcion_larga END) descripcion_larga,
                ISNULL(V.real_mn,0) regio_declarado,ISNULL(V.real_mn,0) regio_real,ISNULL(V.real_usd,0) regio_usd,
                ISNULL(V.real_usd*ISNULL(V.tipo_cambio_usd,0),0) regio_usd_mxn
            FROM dbo.efc_conc_grupos G
            LEFT JOIN dbo.efc_conc_partidas P ON P.grupo_id=G.id AND P.activo=1
            LEFT JOIN TG.dbo.Estaciones E ON E.Codigo=G.estacion_id
            LEFT JOIN TG.dbo.movimientos_bancarios M ON M.id=P.movimiento_bancario_id
            OUTER APPLY (SELECT TOP 1 Pa.real_mn,Pa.real_usd,V.tipo_cambio_usd
                FROM dbo.efc_conc_analiticos_vinculos V
                JOIN dbo.efc_conc_analiticos_papeletas Pa ON Pa.id=V.papeleta_id
                WHERE V.estacion_id=G.estacion_id AND V.fecha_cg=G.fecha_operativa AND V.turno=G.turno
                  AND V.concepto=G.concepto AND V.activo=1) V
            WHERE ".implode(' AND ',$where)."
            GROUP BY G.id,G.fecha_operativa,G.estacion_id,E.Nombre,G.turno,G.concepto,G.tipo,G.total_controlgas,G.total_banorte,G.diferencia,V.real_mn,V.real_usd,V.tipo_cambio_usd
            ORDER BY G.fecha_operativa,G.turno,G.id";
        $q=$this->db->prepare($sql);$q->execute($params);return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function summaryGrouped(?int $year=null, ?int $month=null, ?int $stationId=null, ?string $concept=null): array { $where=[];$params=[];if($year){$where[]='C.mes LIKE ?';$params[]=sprintf('%04d-%02d',$year,$month?:1).'%';}if($month&&$year){$where=['C.mes=?'];$params=[sprintf('%04d-%02d',$year,$month)];}if($stationId){$where[]='C.estacion_id=?';$params[]=$stationId;}if($concept&&in_array($concept,['MN','MORRALLA','USD'],true)){$where[]='C.concepto=?';$params[]=$concept;} $sql="SELECT C.estacion_id,C.mes,C.concepto,C.total_controlgas,C.total_banco,C.total_regio_declarado,C.total_regio_real,C.total_diferencia,C.total_transito,C.operaciones,C.pendientes,C.estado,C.cerrado_en FROM dbo.efc_conc_cierres C".($where?' WHERE '.implode(' AND ',$where):'')." ORDER BY C.mes DESC,C.estacion_id,C.concepto";$q=$this->db->prepare($sql);$q->execute($params);$rows=$q->fetchAll(PDO::FETCH_ASSOC);foreach($rows as &$r){foreach(['estacion_id','operaciones','pendientes'] as $k)$r[$k]=(int)$r[$k];foreach(['total_controlgas','total_banco','total_regio_declarado','total_regio_real','total_diferencia','total_transito'] as $k)$r[$k]=(float)$r[$k];}return $rows; }

    public function activeTransits(int $stationId, int $year, int $month): array {
        if (!$stationId || $year < 2020 || $month < 1 || $month > 12) throw new RuntimeException('Periodo o estación inválidos.');
        $origin = sprintf('%04d-%02d', $year, $month);
        $next = (new DateTimeImmutable($origin . '-01'))->modify('+1 month')->format('Y-m');
        $stmt = $this->db->prepare("SELECT id,estacion_id,clave_externa,fecha_origen,mes_origen,mes_destino,turno,concepto,importe,estado,descripcion FROM dbo.efc_conc_transitos WHERE estacion_id=? AND estado='PENDIENTE' AND (mes_origen=? OR mes_destino=?) ORDER BY fecha_origen,turno,concepto,id");
        $stmt->execute([$stationId, $origin, $origin]);
        $outgoing=[]; $incoming=[];
        while ($row=$stmt->fetch(PDO::FETCH_ASSOC)) {
            $item=['id'=>(int)$row['id'],'station_id'=>(int)$row['estacion_id'],'source_key'=>(string)$row['clave_externa'],'date'=>$this->dateValue($row['fecha_origen']),'origin_month'=>(string)$row['mes_origen'],'destination_month'=>(string)$row['mes_destino'],'turn'=>(string)$row['turno'],'currency'=>(string)$row['concepto'],'amount'=>(float)$row['importe'],'status'=>(string)$row['estado'],'description'=>(string)($row['descripcion']??'')];
            if ($row['mes_origen']===$origin) $outgoing[]=$item;
            if ($row['mes_destino']===$origin) $incoming[]=$item;
        }
        return ['origin'=>$outgoing,'incoming'=>$incoming,'next_month'=>$next];
    }

    public function createTransits(int $stationId, array $turns, int $userId): array {
        if (!$stationId || !$turns) throw new RuntimeException('Seleccione al menos un turno.');
        $this->assertOpen($station,$date,'MN'); $this->db->beginTransaction();
        try {
            $created=[];
            foreach ($turns as $turn) {
                $key=trim((string)($turn['id']??$turn['source_key']??'')); $date=trim((string)($turn['date']??'')); $turnNo=trim((string)($turn['turn']??'')); $concept=trim(strtoupper((string)($turn['currency']??''))); $amount=(float)($turn['amount']??0);
                if (!$key || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$date) || $turnNo==='' || !in_array($concept,['MN','MORRALLA','USD'],true) || $amount<=0) throw new RuntimeException('Datos de turno inválidos.');
                $origin=substr($date,0,7); $destination=(new DateTimeImmutable($date))->modify('+1 month')->format('Y-m'); $this->assertOpen($stationId,$date,$concept);
                $check=$this->db->prepare("SELECT TOP 1 id FROM dbo.efc_conc_transitos WHERE estacion_id=? AND clave_externa=? AND estado='PENDIENTE'"); $check->execute([$stationId,$key]); if($check->fetchColumn()) throw new RuntimeException('Uno de los turnos ya tiene un tránsito activo.');
                $group=$this->db->prepare("SELECT TOP 1 1 FROM dbo.efc_conc_partidas P JOIN dbo.efc_conc_grupos G ON G.id=P.grupo_id WHERE P.origen='CG' AND P.clave_externa=? AND P.activo=1 AND G.estado='ACTIVA'"); $group->execute([$key]); if($group->fetchColumn()) throw new RuntimeException('No se puede enviar a tránsito un turno ya conciliado.');
                $ins=$this->db->prepare("INSERT dbo.efc_conc_transitos(estacion_id,clave_externa,fecha_origen,mes_origen,mes_destino,turno,concepto,importe,descripcion,creado_por) OUTPUT INSERTED.id VALUES(?,?,?,?,?,?,?,?,?,?)");
                $ins->execute([$stationId,$key,$date,$origin,$destination,$turnNo,$concept,$amount,(string)($turn['description']??''),$userId]); $created[]=(int)$ins->fetchColumn();
            }
            $this->db->commit(); return $created;
        } catch(Throwable $e) { $this->db->rollBack(); throw $e; }
    }

    public function cancelTransit(int $id, int $userId): void {
        if (!$id) throw new RuntimeException('Tránsito inválido.');
        $stmt=$this->db->prepare("SELECT * FROM dbo.efc_conc_transitos WHERE id=? AND estado='PENDIENTE'"); $stmt->execute([$id]); $transit=$stmt->fetch(PDO::FETCH_ASSOC); if(!$transit) throw new RuntimeException('El tránsito no existe o ya fue cancelado.');
        $linked=$this->db->prepare("SELECT TOP 1 1 FROM dbo.efc_conc_partidas P JOIN dbo.efc_conc_grupos G ON G.id=P.grupo_id WHERE P.origen='CG' AND P.clave_externa IN (?,?) AND P.activo=1 AND G.estado='ACTIVA'"); $linked->execute([$transit['clave_externa'],'TR:'.$id]); if($linked->fetchColumn()) throw new RuntimeException('No se puede deshacer: el turno ya fue conciliado.');
        $upd=$this->db->prepare("UPDATE dbo.efc_conc_transitos SET estado='CANCELADO',cancelado_por=?,cancelado_en=GETDATE() WHERE id=? AND estado='PENDIENTE'"); $upd->execute([$userId,$id]);
    }

    public function correction(int $movementId, ?int $stationId, int $userId): void {
        if ($this->bankIsActive($movementId)) throw new RuntimeException('El deposito ya esta conciliado; primero debe deshacer la conciliacion.');
        $this->db->beginTransaction();
        try {
            if ($stationId) {
                $check = $this->db->prepare("SELECT 1 FROM TG.dbo.Estaciones WHERE Codigo=? AND Codigo<>0"); $check->execute([$stationId]);
                if (!$check->fetchColumn()) throw new RuntimeException('Estacion invalida.');
                $update = $this->db->prepare("UPDATE dbo.efc_conc_correcciones_banco SET estacion_id=?, actualizado_por=?, actualizado_en=GETDATE() WHERE movimiento_bancario_id=?");
                $update->execute([$stationId, $userId, $movementId]);
                if ($update->rowCount() === 0) $this->db->prepare("INSERT dbo.efc_conc_correcciones_banco(movimiento_bancario_id,estacion_id,creado_por) VALUES(?,?,?)")->execute([$movementId, $stationId, $userId]);
                $this->log(null,$movementId,'CORRECCION_ESTACION',json_encode(['estacion_id'=>$stationId]),$userId);
            } else {
                $this->db->prepare("DELETE FROM dbo.efc_conc_correcciones_banco WHERE movimiento_bancario_id=?")->execute([$movementId]);
                $this->log(null,$movementId,'RESTAURAR_REFERENCIA',null,$userId);
            }
            $this->db->commit();
        } catch(Throwable $e) { $this->db->rollBack(); throw $e; }
    }

    public function saveGroup(array $group, array $cg, array $bank, int $userId): int {
        if (count($cg)!==1 || count($bank)<1 || count($bank)>2) throw new RuntimeException('La conciliacion requiere un turno y uno o dos depositos.');
        $this->assertOpen((int)$group['station_id'],(string)$cg[0]['date'],(string)$cg[0]['currency']); $this->db->beginTransaction();
        try { $id=$this->createGroup($group,$cg[0],$bank,$userId); $this->db->commit(); return $id; }
        catch(Throwable $e) { $this->db->rollBack(); throw $e; }
    }

    public function activeReclassifications(int $stationId, int $year, int $month): array {
        $stmt=$this->db->prepare("SELECT R.id AS reclasificacion_id,R.clave_original,R.fecha_operativa,R.turno,R.importe_original,P.concepto,P.importe,P.movimiento_bancario_id,P.grupo_id,P.estado AS partida_estado,M.fecha AS banco_fecha,M.abono AS banco_importe,M.referencia,M.descripcion_larga FROM dbo.efc_conc_reclasificaciones_cg R JOIN dbo.efc_conc_reclasificaciones_cg_partidas P ON P.reclasificacion_id=R.id LEFT JOIN TG.dbo.movimientos_bancarios M ON M.id=P.movimiento_bancario_id WHERE R.estado='ACTIVA' AND R.estacion_id=? AND YEAR(R.fecha_operativa)=? AND MONTH(R.fecha_operativa)=? ORDER BY R.id,P.concepto");
        $stmt->execute([$stationId,$year,$month]);
        $out=[];
        while($row=$stmt->fetch(PDO::FETCH_ASSOC)) {
            $id=(int)$row['reclasificacion_id'];
            if (!isset($out[$id])) $out[$id]=['id'=>$id,'source_key'=>$row['clave_original'],'date'=>$this->dateValue($row['fecha_operativa']),'turn'=>$row['turno'],'original_amount'=>(float)$row['importe_original'],'parts'=>[]];
            $out[$id]['parts'][]=['currency'=>$row['concepto'],'amount'=>(float)$row['importe'],'bank_id'=>$row['partida_estado']==='ACTIVA'&&$row['movimiento_bancario_id']?'mb_'.(int)$row['movimiento_bancario_id']:null,'group_id'=>$row['partida_estado']==='ACTIVA'?(int)$row['grupo_id']:null,'released'=>$row['partida_estado']==='LIBERADA'];
        }
        return array_values($out);
    }

    /**
     * Conciliaciones ya aprobadas del periodo. Se usan para reservar sus
     * partidas fuente antes de volver a proponer excepciones en la pantalla.
     */
    public function activeGroups(int $stationId, int $year, int $month): array {
        if (!$stationId || $year < 2020 || $month < 1 || $month > 12) throw new RuntimeException('Periodo o estación inválidos.');
        $stmt=$this->db->prepare("SELECT G.id,G.tipo,G.total_controlgas,G.total_banorte,G.diferencia,
            P.origen,P.clave_externa,P.movimiento_bancario_id,P.fecha_operacion,P.turno,P.concepto,P.importe,P.referencia,
            M.descripcion,M.descripcion_larga,M.sucursal
            FROM dbo.efc_conc_grupos G
            JOIN dbo.efc_conc_partidas P ON P.grupo_id=G.id AND P.activo=1
            LEFT JOIN TG.dbo.movimientos_bancarios M ON M.id=P.movimiento_bancario_id
            WHERE G.estacion_id=? AND YEAR(G.fecha_operativa)=? AND MONTH(G.fecha_operativa)=? AND G.estado='ACTIVA' AND G.tipo<>'RECLASIFICADA'
            ORDER BY G.id,P.id");
        $stmt->execute([$stationId,$year,$month]); $groups=[];
        while($row=$stmt->fetch(PDO::FETCH_ASSOC)) {
            $id=(int)$row['id'];
            if(!isset($groups[$id])) $groups[$id]=[
                'id'=>$id,'type'=>(string)$row['tipo'],'cg_total'=>(float)$row['total_controlgas'],
                'bank_total'=>(float)$row['total_banorte'],'difference'=>(float)$row['diferencia'],
                'cg'=>[],'bank'=>[]
            ];
            $item=[
                'id'=>(string)$row['clave_externa'],'date'=>$this->dateValue($row['fecha_operacion']),
                'turn'=>$row['turno']!==null?(string)$row['turno']:null,'currency'=>$row['concepto']!==null?(string)$row['concepto']:null,
                'amount'=>(float)$row['importe'],'selected'=>true
            ];
            if($row['origen']==='CG') $groups[$id]['cg'][]=$item;
            else $groups[$id]['bank'][]=$item+[
                'reference'=>(string)($row['referencia']??''),'referenceFull'=>(string)($row['referencia']??''),
                'description'=>(string)($row['descripcion']?:'DEPOSITO EN EFECTIVO'),'detail'=>(string)($row['descripcion_larga']??''),
                'branch'=>(string)($row['sucursal']??'')
            ];
        }
        return array_values($groups);
    }

    public function reclassify(array $data, int $userId): int {
        $station=(int)($data['station_id']??0); $date=(string)($data['date']??''); $turn=trim((string)($data['turn']??''));
        $original=round((float)($data['original_amount']??0),2); $mn=round((float)($data['mn_amount']??0),2); $morralla=round((float)($data['morralla_amount']??0),2);
        $mnBank=(int)preg_replace('/\D/','',(string)($data['mn_bank_id']??'')); $morrallaBank=(int)preg_replace('/\D/','',(string)($data['morralla_bank_id']??''));
        if (!$station || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$date) || $turn==='' || $original<=0 || $mn<=0 || $morralla<=0 || !$mnBank || !$morrallaBank || $mnBank===$morrallaBank) throw new RuntimeException('Datos de reclasificacion incompletos.');
        if (abs(($mn+$morralla)-$original)>0.009) throw new RuntimeException('MN y Morralla deben sumar exactamente el corte original.');
        $sourceKey=$this->sourceKey($station,$date,$turn);
        $this->db->beginTransaction();
        try {
            $stationCheck=$this->db->prepare("SELECT 1 FROM TG.dbo.Estaciones WHERE Codigo=? AND RFC='DGA930823KD3'"); $stationCheck->execute([$station]);
            if (!$stationCheck->fetchColumn()) throw new RuntimeException('Estacion Diaz Gas invalida.');
            $exists=$this->db->prepare("SELECT 1 FROM dbo.efc_conc_reclasificaciones_cg WITH (UPDLOCK,HOLDLOCK) WHERE clave_original=? AND estado='ACTIVA'"); $exists->execute([$sourceKey]);
            if ($exists->fetchColumn()) throw new RuntimeException('El corte ya tiene una reclasificacion activa.');
            $mnDeposit=$this->validateDeposit($mnBank,$station,$date,$mn); $morrallaDeposit=$this->validateDeposit($morrallaBank,$station,$date,$morralla);
            $head=$this->db->prepare("INSERT dbo.efc_conc_reclasificaciones_cg(estacion_id,fecha_operativa,turno,concepto_original,clave_original,importe_original,creado_por) OUTPUT INSERTED.id VALUES(?,?,?,'MN',?,?,?)");
            $head->execute([$station,$date,$turn,$sourceKey,$original,$userId]); $reclassId=(int)$head->fetchColumn();
            $mnCg=['id'=>$sourceKey.':V:MN','date'=>$date,'turn'=>$turn,'currency'=>'MN','amount'=>$mn];
            $morCg=['id'=>$sourceKey.':V:MORRALLA','date'=>$date,'turn'=>$turn,'currency'=>'MORRALLA','amount'=>$morralla];
            $mnGroup=$this->createGroup(['station_id'=>$station,'type'=>'RECLASIFICADA','cg_total'=>$mn,'bank_total'=>$mnDeposit['amount'],'difference'=>$mnDeposit['amount']-$mn],$mnCg,[$mnDeposit],$userId);
            $morGroup=$this->createGroup(['station_id'=>$station,'type'=>'RECLASIFICADA','cg_total'=>$morralla,'bank_total'=>$morrallaDeposit['amount'],'difference'=>$morrallaDeposit['amount']-$morralla],$morCg,[$morrallaDeposit],$userId);
            $part=$this->db->prepare("INSERT dbo.efc_conc_reclasificaciones_cg_partidas(reclasificacion_id,concepto,importe,movimiento_bancario_id,grupo_id) VALUES(?,?,?,?,?)");
            $part->execute([$reclassId,'MN',$mn,$mnBank,$mnGroup]); $part->execute([$reclassId,'MORRALLA',$morralla,$morrallaBank,$morGroup]);
            $this->log($mnGroup,$mnBank,'RECLASIFICACION_CG',json_encode(['reclasificacion_id'=>$reclassId,'original'=>$original,'mn'=>$mn,'morralla'=>$morralla]),$userId);
            $this->log($morGroup,$morrallaBank,'RECLASIFICACION_CG',json_encode(['reclasificacion_id'=>$reclassId]),$userId);
            $this->db->commit(); return $reclassId;
        } catch(Throwable $e) { $this->db->rollBack(); throw $e; }
    }

    public function reverseReclassification(int $id, string $mode, string $concept, int $userId): void {
        if (!in_array($mode,['TODO','CONSERVAR'],true)) throw new RuntimeException('Modo de reversión invalido.');
        $this->db->beginTransaction();
        try {
            $parts=$this->db->prepare("SELECT id,grupo_id,concepto FROM dbo.efc_conc_reclasificaciones_cg_partidas WHERE reclasificacion_id=? AND estado='ACTIVA'"); $parts->execute([$id]); $rows=$parts->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) throw new RuntimeException('No hay reclasificacion activa para revertir.');
            if ($mode==='CONSERVAR') $rows=array_values(array_filter($rows,fn($row)=>$row['concepto']===$concept));
            if (!$rows) throw new RuntimeException('Seleccione la partida a liberar.');
            foreach($rows as $row) { $this->cancelGroup((int)$row['grupo_id'],$userId); $this->db->prepare("UPDATE dbo.efc_conc_reclasificaciones_cg_partidas SET estado='LIBERADA',grupo_id=NULL WHERE id=?")->execute([(int)$row['id']]); }
            if ($mode==='TODO') { $this->db->prepare("UPDATE dbo.efc_conc_reclasificaciones_cg SET estado='CANCELADA',cancelado_por=?,cancelado_en=GETDATE() WHERE id=?")->execute([$userId,$id]); $this->db->prepare("UPDATE dbo.efc_conc_reclasificaciones_cg_partidas SET estado='CANCELADA' WHERE reclasificacion_id=?")->execute([$id]); }
            $this->log(null,null,$mode==='TODO'?'REVERTIR_RECLASIFICACION':'LIBERAR_PARTIDA_RECLASIFICADA',json_encode(['reclasificacion_id'=>$id,'concepto'=>$concept]),$userId);
            $this->db->commit();
        } catch(Throwable $e) { $this->db->rollBack(); throw $e; }
    }

    public function undo(int $groupId,int $userId): void { $q=$this->db->prepare("SELECT estacion_id,fecha_operativa,concepto FROM dbo.efc_conc_grupos WHERE id=? AND estado='ACTIVA'");$q->execute([$groupId]);$g=$q->fetch(PDO::FETCH_ASSOC);if(!$g)throw new RuntimeException('La conciliación no existe o ya fue deshecha.');$this->assertOpen((int)$g['estacion_id'],$this->dateValue($g['fecha_operativa']),(string)$g['concepto']);$this->db->beginTransaction(); try { $this->cancelGroup($groupId,$userId); $this->db->commit(); } catch(Throwable $e) { $this->db->rollBack(); throw $e; } }

    private function createGroup(array $group, array $cg, array $bank, int $userId): int {
        $q=$this->db->prepare("INSERT dbo.efc_conc_grupos(estacion_id,fecha_operativa,turno,concepto,tipo,total_controlgas,total_banorte,diferencia,creado_por) OUTPUT INSERTED.id VALUES(?,?,?,?,?,?,?,?,?)");
        $q->execute([$group['station_id'],$cg['date'],$cg['turn'],$cg['currency'],$group['type'],$group['cg_total'],$group['bank_total'],$group['difference'],$userId]); $id=(int)$q->fetchColumn();
        $part=$this->db->prepare("INSERT dbo.efc_conc_partidas(grupo_id,origen,clave_externa,movimiento_bancario_id,fecha_operacion,turno,concepto,importe,referencia,estacion_id) VALUES(?,?,?,?,?,?,?,?,?,?)");
        $part->execute([$id,'CG',$cg['id'],null,$cg['date'],$cg['turn'],$cg['currency'],$cg['amount'],null,$group['station_id']]);
        foreach($bank as $item) $part->execute([$id,'BANCO',$item['id'],(int)preg_replace('/\D/','',$item['id']),$item['date'],null,null,$item['amount'],$item['reference']??null,$group['station_id']]);
        $this->log($id,null,'CONCILIACION_'.$group['type'],null,$userId); return $id;
    }

    private function validateDeposit(int $id,int $station,string $cutDate,float $allocated): array {
        if ($this->bankIsActive($id)) throw new RuntimeException('Uno de los depositos ya esta conciliado.');
        $company=$this->companyForStation($station);
        // Un movimiento puede haberse depositado en la cuenta de otra empresa;
        // la estación se valida por referencia/detalle, no por la cuenta.
        $suffixes=self::allAccountSuffixes();
        $accountWhere=implode(' OR ',array_fill(0,count($suffixes),"RIGHT(UPPER(REPLACE(REPLACE(ISNULL(cuenta,''),'-',''),' ','')),LEN(?))=?"));
        $stmt=$this->db->prepare("SELECT id,fecha,abono,cuenta,referencia,descripcion_larga,descripcion,concepto FROM TG.dbo.movimientos_bancarios WHERE id=? AND abono>0 AND (UPPER(ISNULL(descripcion,'')) LIKE '%DEPOSITO EN EFECTIVO%' OR UPPER(ISNULL(descripcion,'')) LIKE '%DEPOSITO EFECTIVO%' OR UPPER(ISNULL(descripcion,'')) LIKE '%DEP EN EFECTIV%' OR UPPER(ISNULL(descripcion,'')) LIKE '%DEPOSITO VTAS%') AND ($accountWhere)");
        $params=[$id]; foreach($suffixes as $suffix) { $params[]=$suffix; $params[]=$suffix; } $stmt->execute($params); $row=$stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('Deposito invalido para la empresa de la estacion.');
        $date=$this->dateValue($row['fecha']); $days=(int)((strtotime($date)-strtotime($cutDate))/86400);
        if ($days<0 || $days>7) throw new RuntimeException('El deposito debe estar entre la fecha del corte y siete dias posteriores.');
        if (abs((float)$row['abono']-$allocated)>self::TOLERANCE) throw new RuntimeException('La diferencia de una partida supera $20.');
        if ($this->bankStation((int)$row['id'],$row,$company)!==$station) throw new RuntimeException('El deposito no pertenece a la estacion efectiva del corte.');
        return ['id'=>'mb_'.(int)$row['id'],'date'=>$date,'amount'=>(float)$row['abono'],'reference'=>(string)$row['referencia']];
    }

    private function companyForStation(int $station): string {
        $company=self::stationCompany($station);
        if ($company!==null) return $company;
        throw new RuntimeException('La estación no pertenece al catálogo operativo de conciliación.');
    }

    private function bankStation(int $id,array $movement,?string $company=null): ?int {
        $correct=$this->db->prepare("SELECT estacion_id FROM dbo.efc_conc_correcciones_banco WHERE movimiento_bancario_id=?"); $correct->execute([$id]); $station=$correct->fetchColumn(); if ($station) return (int)$station;
        $company=self::normalizeCompany($company??'DIAZ GAS');
        if (!isset($this->bankStationCatalogCache[$company])) {
            $where=self::stationSqlCondition($company, '');
            $this->bankStationCatalogCache[$company]=$this->db->query("SELECT Codigo,Estacion,Nombre FROM TG.dbo.Estaciones WHERE $where AND Codigo<>0")->fetchAll(PDO::FETCH_ASSOC);
        }
        $catalog=$this->bankStationCatalogCache[$company]; $map=[];
        $normalizeName=static function($value): string {
            $value=strtr((string)$value,['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n','Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N']);
            return preg_replace('/[^A-Z0-9]+/',' ',trim(strtoupper($value)));
        };
        $accountStations=[];
        foreach(self::accountStationNames($company,(string)($movement['cuenta']??'')) as $wanted) {
            $wanted=$normalizeName($wanted);
            foreach($catalog as $item) if(str_contains($normalizeName($item['Nombre']),$wanted)) $accountStations[(int)$item['Codigo']]=true;
        }
        // Una cuenta compartida puede traer el nombre de la estación en el
        // detalle bancario. Se usa esa identificación explícita antes de
        // descartar el movimiento como ambiguo.
        $text=implode(' ',[(string)($movement['descripcion_larga']??''),(string)($movement['descripcion']??''),(string)($movement['concepto']??''),(string)($movement['referencia']??'')]);
        $normalizedText=$normalizeName($text); $textStations=[];
        foreach($catalog as $item) {
            $name=trim($normalizeName($item['Nombre']));
            $name=preg_replace('/^\d+\s+/', '', $name);
            if($name!=='' && str_contains($normalizedText,$name)) $textStations[(int)$item['Codigo']]=true;
        }
        if(count($textStations)===1) return (int)array_key_first($textStations);
        if(count($accountStations)===1) return (int)array_key_first($accountStations);
        foreach($catalog as $item) { $code=ltrim(preg_replace('/\D+/','',(string)$item['Estacion']),'0'); if($code!=='') $map[$code]=(int)$item['Codigo']; }
        preg_match_all('/\d+/',$text,$matches); $found=[];
        foreach($matches[0] as $raw) { $code=ltrim($raw,'0'); if(isset($map[$code])) $found[$map[$code]]=true; }
        return count($found)===1 ? (int)array_key_first($found) : null;
    }

    private function bankIsActive(int $movementId): bool { $active=$this->db->prepare("SELECT TOP 1 1 FROM dbo.efc_conc_partidas P JOIN dbo.efc_conc_grupos G ON G.id=P.grupo_id WHERE P.movimiento_bancario_id=? AND P.activo=1 AND G.estado='ACTIVA'"); $active->execute([$movementId]); return (bool)$active->fetchColumn(); }
    private function cancelGroup(int $groupId,int $userId): void { $this->db->prepare("UPDATE dbo.efc_conc_grupos SET estado='CANCELADA',cancelado_por=?,cancelado_en=GETDATE() WHERE id=? AND estado='ACTIVA'")->execute([$userId,$groupId]); $this->db->prepare("UPDATE dbo.efc_conc_partidas SET activo=0 WHERE grupo_id=?")->execute([$groupId]); $this->log($groupId,null,'DESHACER',null,$userId); }
    private function dateValue($value): string { return $value instanceof DateTimeInterface ? $value->format('Y-m-d') : substr((string)$value,0,10); }
    private function sourceKey(int $station,string $date,string $turn): string { return 'CG:'.$station.':'.$date.':'.$turn.':MN'; }
    private function log(?int $groupId,?int $movementId,string $action,?string $detail,int $userId): void { $this->db->prepare("INSERT dbo.efc_conc_bitacora(grupo_id,movimiento_bancario_id,accion,detalle,usuario_id) VALUES(?,?,?,?,?)")->execute([$groupId,$movementId,$action,$detail,$userId]); }
}
