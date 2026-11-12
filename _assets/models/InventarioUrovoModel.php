<?php
class InventarioUrovoModel extends Model {
    private const LOCATIONS = ['estacion', 'almacen', 'reparacion', 'perdida', 'baja_definitiva'];
    /* First value is TG.dbo.Estaciones.Codigo; names and goals come from the user's table.
       The user's 11007 / Independencia row maps to TG Codigo 3, named "19 Aguascalientes". */
    private const DASHBOARD_OBJECTIVES = [
        [31,'VILLA AHUMADA',6],[19,'DELICIAS',5],[18,'PARRAL',5],[11,'PUERTO DE PALOS',8],
        [12,'MIGUEL DE LA MADRID',6],[13,'PERMUTA',6],[14,'ELECTROLUX',6],[15,'AERONAUTICA',6],
        [16,'CUSTODIA',5],[33,'TRAVEL CENTER ESTACIÓN',7],[5,'LERDO',3],[6,'LOPEZ MATEOS',4],
        [7,'GEMELA CHICA',6],[2,'GEMELA GRANDE',7],[21,'PLUTARCO',6],[8,'MUNICIPIO LIBRE',8],
        [9,'AZTECAS',8],[17,'ANAPRA',6],[30,'HERMANOS ESCOBAR',6],[29,'JARUDO',5],
        [26,'CLARA',3],[25,'FUENTES',4],[22,'TECNOLOGICO',6],[24,'SATELITE',7],[10,'MISIONES',8],
        [23,'EJERCITO',6],[27,'SOLIS',5],[28,'STGO TRONCOSO',5],[40,'PRAXEDIS',5],[3,'19 AGUASCALIENTES',7],
        [32,'EL CASTAÑO',5],[37,'PUERTECITO',4],[38,'JESUS MARIA',4],[36,'SAN RAFAEL',5],
        [34,'PICACHOS',8],[35,'VENTANAS',8],[39,'GABRIELA MISTRAL',5],
    ];
    public static function locationTypes(): array { return self::LOCATIONS; }

    public function hasAccess(): bool {
        $rows = $this->sql->select("SELECT id FROM [TG].[dbo].[tg_permissions] WHERE [action]=? AND department=? AND description=? AND status=1", ['update', 'Sistemas', 'Inventario UROVO']);
        if (count($rows) !== 1) return false;
        $permissions = array_map('trim', explode(',', (string)($_SESSION['tg_user']['permissions'] ?? '')));
        return in_array((string)$rows[0]['id'], $permissions, true);
    }

    public function stations(): array {
        return $this->sql->select('SELECT Codigo,Nombre FROM [TG].[dbo].[Estaciones] WHERE activa=1 AND Codigo NOT IN (0,4,20) ORDER BY Nombre');
    }

    /** Objetivos fijos del usuario contra terminales Urovo operativas por código interno de TG. */
    public function dashboard(): array {
        $counts=$this->sql->select("SELECT estacion_codigo AS station_id,COUNT(*) AS funcionando
            FROM [TG].[dbo].[inv_urovo_terminales]
            WHERE tipo_terminal=? AND ubicacion_tipo=? AND condicion=? AND estacion_codigo IS NOT NULL
            GROUP BY estacion_codigo",['urovo','estacion','operativa']);
        $byStation=[];
        foreach ($counts as $row) $byStation[(int)$row['station_id']]=(int)$row['funcionando'];
        $rows=[]; $totalObjective=0; $totalFunctioning=0;
        foreach (self::DASHBOARD_OBJECTIVES as [$stationId,$name,$objective]) {
            $functioning=$byStation[$stationId]??0;
            $rows[]=['station_id'=>$stationId,'station_name'=>$name,'objetivo'=>$objective,'funcionando'=>$functioning,'diferencia'=>$objective-$functioning];
            $totalObjective+=$objective; $totalFunctioning+=$functioning;
        }
        return ['rows'=>$rows,'total_objetivo'=>$totalObjective,'total_funcionando'=>$totalFunctioning,'faltantes'=>max(0,$totalObjective-$totalFunctioning)];
    }

    /** Resumen por estación, agregando cada fuente antes de unirla para evitar multiplicar conteos. */
    public function summary(): array {
        $rows=$this->sql->select("SELECT s.Codigo AS station_id,s.Nombre AS station_name,
                COALESCE(t.terminales_count,0) AS terminales_count,
                COALESCE(t.sin_serie_count,0) AS sin_serie_count,
                COALESCE(t.operativa,0) AS operativa,
                COALESCE(t.danada,0) AS danada,
                COALESCE(t.desconocida,0) AS desconocida,
                COALESCE(t.extraviada,0) AS extraviada,
                COALESCE(t.total_count,0) AS total_count
            FROM [TG].[dbo].[Estaciones] s
            LEFT JOIN (
                SELECT estacion_codigo,SUM(CASE WHEN numero_serie IS NOT NULL THEN 1 ELSE 0 END) AS terminales_count,
                    SUM(CASE WHEN numero_serie IS NULL THEN 1 ELSE 0 END) AS sin_serie_count,
                    COUNT(*) AS total_count,
                    SUM(CASE WHEN condicion='operativa' THEN 1 ELSE 0 END) AS operativa,
                    SUM(CASE WHEN condicion='dañada' THEN 1 ELSE 0 END) AS danada,
                    SUM(CASE WHEN condicion='desconocida' THEN 1 ELSE 0 END) AS desconocida,
                    SUM(CASE WHEN condicion='extraviada' THEN 1 ELSE 0 END) AS extraviada
                FROM [TG].[dbo].[inv_urovo_terminales]
                WHERE ubicacion_tipo='estacion' AND estacion_codigo IS NOT NULL
                GROUP BY estacion_codigo
            ) t ON t.estacion_codigo=s.Codigo
            WHERE s.activa=1 AND s.Codigo NOT IN (0,4,20)
            ORDER BY s.Nombre");
        return array_map(static function(array $row): array {
            return [
                'station_id'=>$row['station_id'],
                'station_name'=>$row['station_name'],
                'terminales_count'=>(int)$row['terminales_count'],
                'sin_serie_count'=>(int)$row['sin_serie_count'],
                'total_count'=>(int)$row['total_count'],
                'conditions'=>[
                    'operativa'=>(int)$row['operativa'],
                    'dañada'=>(int)$row['danada'],
                    'desconocida'=>(int)$row['desconocida'],
                    'extraviada'=>(int)$row['extraviada'],
                ],
            ];
        },$rows);
    }

    /** Detalle bajo demanda para una estación activa. */
    public function summaryDetail(int $stationId): array {
        $stations=$this->sql->select('SELECT Codigo AS station_id,Nombre AS station_name FROM [TG].[dbo].[Estaciones] WHERE Codigo=? AND activa=1 AND Codigo NOT IN (0,4,20)',[$stationId]);
        if (!$stations) throw new OutOfBoundsException('Estación inválida.');
        $terminals=$this->sql->select("SELECT t.id,t.tipo_terminal,t.numero_serie,t.modelo,t.condicion,t.damage_types_json,t.mojo_ticket_number,
                t.ubicacion_tipo,t.estacion_codigo,s.Nombre AS estacion_nombre,
                lm.fecha_evento AS last_movement
            FROM [TG].[dbo].[inv_urovo_terminales] t
            LEFT JOIN [TG].[dbo].[Estaciones] s ON s.Codigo=t.estacion_codigo
            OUTER APPLY (SELECT TOP (1) h.fecha_evento FROM [TG].[dbo].[inv_urovo_movimientos] h WHERE h.terminal_id=t.id ORDER BY h.fecha_evento DESC,h.id DESC) lm
            WHERE t.ubicacion_tipo='estacion' AND t.estacion_codigo=?
            ORDER BY t.modelo,t.numero_serie",[$stationId]);
        return ['station'=>$stations[0],'terminals'=>array_map([$this,'presentTerminal'], $terminals)];
    }

    public function activeStation(int $id): bool {
        return (bool)$this->sql->select('SELECT Codigo FROM [TG].[dbo].[Estaciones] WHERE Codigo=? AND activa=1 AND Codigo NOT IN (0,4,20)', [$id]);
    }

    public function data(array $filters): array {
        $where = []; $params = [];
        if (!empty($filters['station_id'])) { $where[]='t.estacion_codigo=?'; $params[]=(int)$filters['station_id']; }
        if (!empty($filters['status'])) {
            if ($filters['status']==='sin_serie') $where[]='t.numero_serie IS NULL';
            else { $where[]='t.ubicacion_tipo=?'; $params[]=$filters['status']; }
        }
        if (!empty($filters['terminal_type'])) { $where[]='t.tipo_terminal=?'; $params[]=$filters['terminal_type']; }
        if (!empty($filters['q'])) { $where[]='(t.numero_serie LIKE ? OR t.modelo LIKE ? OR t.ip LIKE ? OR t.imei LIKE ?)'; $q='%'.$filters['q'].'%'; array_push($params,$q,$q,$q,$q); }
        $sqlWhere = $where ? ' WHERE '.implode(' AND ',$where) : '';
        $rows = $this->sql->select("SELECT t.*,s.Nombre AS estacion_nombre,lm.fecha_evento AS last_movement,lm.tipo_evento AS last_movement_type,lm.motivo AS last_movement_reason FROM [TG].[dbo].[inv_urovo_terminales] t LEFT JOIN [TG].[dbo].[Estaciones] s ON s.Codigo=t.estacion_codigo OUTER APPLY (SELECT TOP (1) h.fecha_evento,h.tipo_evento,h.motivo FROM [TG].[dbo].[inv_urovo_movimientos] h WHERE h.terminal_id=t.id ORDER BY h.fecha_evento DESC,h.id DESC) lm$sqlWhere ORDER BY t.actualizado_en DESC,t.id DESC", $params);
        return ['stations'=>$this->stations(), 'rows'=>array_map([$this,'presentTerminal'], $rows)];
    }

    public function addBatch(array $items, array $location, int $actor): array {
        $result=['inserted'=>[], 'skipped'=>[]];
        foreach ($items as $item) {
            $this->sql->beginTransaction();
            try {
                $noSerial=($item['no_serial']??false)===true; $serial=$noSerial?null:trim((string)($item['serial']??'')); $normalized=$noSerial?null:mb_strtoupper($serial,'UTF-8');
                if (!$noSerial && $this->sql->select('SELECT id FROM [TG].[dbo].[inv_urovo_terminales] WITH (UPDLOCK,HOLDLOCK) WHERE numero_serie_normalizado=?',[$normalized])) {
                    $this->sql->commit(); $result['skipped'][]=['serial'=>$serial,'reason'=>'duplicate']; continue;
                }
                $ticketNumber=$item['mojo_ticket_number']??'';
                $id=$this->sql->insert('INSERT INTO [TG].[dbo].[inv_urovo_terminales] (tipo_terminal,numero_serie,numero_serie_normalizado,modelo,ip,mac,imei,fecha_compra,fin_garantia,condicion,damage_types_json,mojo_ticket_number,accesorios,notas,ubicacion_tipo,estacion_codigo,proveedor,creado_por,creado_en,actualizado_por,actualizado_en) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,SYSDATETIME(),?,SYSDATETIME())', [$item['terminal_type']??'urovo',$serial,$normalized,$item['model']?:'I9000S',$item['ip']?:null,$item['mac']?:null,$item['imei']?:null,$item['purchase_date']?:null,$item['warranty_until']?:null,$item['condition']?:'desconocida',!empty($item['damage_types'])?json_encode($item['damage_types'],JSON_UNESCAPED_UNICODE):null,$ticketNumber!==''?$ticketNumber:null,$item['accessories']?:null,$item['notes']?:null,$location['location_type'],$location['station_id'],$location['provider'],$actor,$actor]);
                if (!$id) throw new RuntimeException('No se pudo guardar el terminal.');
                $this->terminalMove((int)$id,null,$location,null,(string)($item['condition']??'desconocida'),'alta','alta',$actor);
                $this->sql->commit(); $result['inserted'][]=['serial'=>$noSerial?null:$serial,'id'=>(int)$id];
            } catch (Throwable $e) {
                $this->sql->rollBack();
                throw $e;
            }
        }
        return $result;
    }

    public function moveTerminal(int $id, array $location, string $status, string $reason, int $actor,array $damageTypes=[],string $ticket=''): void {
        $this->sql->beginTransaction();
        try {
            $rows=$this->sql->select('SELECT * FROM [TG].[dbo].[inv_urovo_terminales] WITH (UPDLOCK,HOLDLOCK) WHERE id=?',[$id]);
            if (!$rows) throw new OutOfBoundsException('No se encontró el terminal.'); $old=$rows[0];
            $dest=['location_type'=>$location['location_type'],'station_id'=>$location['station_id'],'provider'=>$location['provider']];
            $damageJson=$damageTypes?json_encode($damageTypes,JSON_UNESCAPED_UNICODE):null;
            $oldDamage=$this->damageTypesFromJson($old['damage_types_json']??null);
            $changes=[];
            if ($oldDamage!==$damageTypes) $changes['Tipos de daño']=['from'=>$oldDamage,'to'=>$damageTypes];
            if ((string)($old['mojo_ticket_number']??'')!==$ticket) $changes['Ticket Mojo']=['from'=>$old['mojo_ticket_number']??'','to'=>$ticket];
            $this->sql->update('UPDATE [TG].[dbo].[inv_urovo_terminales] SET ubicacion_tipo=?,estacion_codigo=?,proveedor=?,condicion=?,damage_types_json=?,mojo_ticket_number=?,actualizado_por=?,actualizado_en=SYSDATETIME() WHERE id=?',[$dest['location_type'],$dest['station_id'],$dest['provider'],$status,$damageJson,$ticket!==''?$ticket:null,$actor,$id]);
            $event=$old['ubicacion_tipo']===$dest['location_type'] && (string)$old['condicion']!==$status ? 'cambio_condicion' : 'traslado';
            if ($changes) $reason.='; '.json_encode($changes,JSON_UNESCAPED_UNICODE);
            $this->terminalMove($id,['location_type'=>$old['ubicacion_tipo'],'station_id'=>$old['estacion_codigo'],'provider'=>$old['proveedor']],$dest,$old['condicion'],$status,$event,$reason,$actor);
            $this->sql->commit();
        } catch (Throwable $e) { $this->sql->rollBack(); throw $e; }
    }

    public function history(int $id): array {
        return $this->sql->select('SELECT m.*,u.Nombre AS actor_nombre,so.Nombre AS origen_estacion_nombre,sd.Nombre AS destino_estacion_nombre FROM [TG].[dbo].[inv_urovo_movimientos] m LEFT JOIN [TG].[dbo].[Usuario] u ON u.Id=m.actor_id LEFT JOIN [TG].[dbo].[Estaciones] so ON so.Codigo=m.estacion_origen_codigo LEFT JOIN [TG].[dbo].[Estaciones] sd ON sd.Codigo=m.estacion_destino_codigo WHERE m.terminal_id=? ORDER BY m.fecha_evento DESC,m.id DESC',[$id]);
    }

    public function editTerminal(int $id, array $changes, int $actor): array {
        $this->sql->beginTransaction();
        try {
            $rows=$this->sql->select('SELECT * FROM [TG].[dbo].[inv_urovo_terminales] WITH (UPDLOCK,HOLDLOCK) WHERE id=?',[$id]);
            if (!$rows) throw new OutOfBoundsException('No se encontró el terminal.'); $old=$rows[0];
            $fields=['tipo_terminal'=>'terminal_type','modelo'=>'model','ip'=>'ip','mac'=>'mac','imei'=>'imei','fecha_compra'=>'purchase_date','fin_garantia'=>'warranty_until','condicion'=>'condition','accesorios'=>'accessories','notas'=>'notes'];
            $set=[]; $params=[]; $diff=[];
            foreach ($fields as $column=>$key) {
                $value=$changes[$key]??($column==='tipo_terminal'?($old[$column]??'urovo'):null); if ($column==='tipo_terminal') $value=$value?:'urovo'; if ($column==='modelo') $value=$value?:'I9000S'; if ($column==='condicion') $value=$value?:'desconocida';
                $prior=$old[$column]??null; if ((string)($prior??'')!==(string)($value??'')) $diff[$column]=['from'=>$prior,'to'=>$value];
                $set[]="[$column]=?"; $params[]=$value;
            }
            $damageTypes=$changes['damage_types']??[];
            $damageJson=$damageTypes?json_encode($damageTypes,JSON_UNESCAPED_UNICODE):null;
            $oldDamage=$this->damageTypesFromJson($old['damage_types_json']??null);
            if ($oldDamage!==$damageTypes) $diff['Tipos de daño']=['from'=>$oldDamage,'to'=>$damageTypes];
            $ticket=(string)($changes['mojo_ticket_number']??'');
            if ((string)($old['mojo_ticket_number']??'')!==$ticket) $diff['Ticket Mojo']=['from'=>$old['mojo_ticket_number']??'','to'=>$ticket];
            $set[]='[damage_types_json]=?'; $params[]=$damageJson; $set[]='[mojo_ticket_number]=?'; $params[]=$ticket!==''?$ticket:null;
            $set[]='actualizado_por=?'; $params[]=$actor; $set[]='actualizado_en=SYSDATETIME()'; $params[]=$id;
            $this->sql->update('UPDATE [TG].[dbo].[inv_urovo_terminales] SET '.implode(',',$set).' WHERE id=?',$params);
            if ($diff) {
                $where=['location_type'=>$old['ubicacion_tipo'],'station_id'=>$old['estacion_codigo'],'provider'=>$old['proveedor']];
                $this->terminalMove($id,$where,$where,(string)$old['condicion'],(string)($changes['condition']?:'desconocida'),'ajuste','ajuste: '.json_encode($diff,JSON_UNESCAPED_UNICODE),$actor);
            }
            $this->sql->commit();
            $updated=$this->sql->select('SELECT * FROM [TG].[dbo].[inv_urovo_terminales] WHERE id=?',[$id]); return $this->presentTerminal($updated[0]??[]);
        } catch (Throwable $e) { $this->sql->rollBack(); throw $e; }
    }

    private function presentTerminal(array $row): array {
        $decoded=json_decode((string)($row['damage_types_json']??'[]'),true);
        $row['damage_types']=is_array($decoded)?array_values($decoded):[];
        $row['mojo_ticket_number']=(string)($row['mojo_ticket_number']??'');
        return $row;
    }
    private function damageTypesFromJson($json): array {
        $types=json_decode((string)($json??''),true);
        return is_array($types)?array_values($types):[];
    }

    private function terminalMove(int $id, ?array $from, array $to, ?string $oldCondition, ?string $newCondition, string $event, string $reason, int $actor): void {
        $this->sql->insert('INSERT INTO [TG].[dbo].[inv_urovo_movimientos] (terminal_id,fecha_evento,tipo_evento,ubicacion_origen_tipo,estacion_origen_codigo,proveedor_origen,ubicacion_destino_tipo,estacion_destino_codigo,proveedor_destino,condicion_anterior,condicion_nueva,motivo,actor_id,registrado_en) VALUES (?,SYSDATETIME(),?,?,?,?,?,?,?,?,?,?,?,SYSDATETIME())',[$id,$event,$from['location_type']??null,$from['station_id']??null,$from['provider']??null,$to['location_type'],$to['station_id'],$to['provider'],$oldCondition,$newCondition,$reason,$actor]);
    }
}
