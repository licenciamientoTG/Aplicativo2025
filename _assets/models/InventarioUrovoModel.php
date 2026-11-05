<?php
class InventarioUrovoModel extends Model {
    private const LOCATIONS = ['estacion', 'almacen', 'reparacion', 'perdida', 'baja_definitiva'];
    private const CONDITIONS = ['operativa', 'dañada', 'por_diagnosticar', 'desconocida', 'extraviada'];

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

    public function anonymousHistory(array $key): array {
        return $this->sql->select("SELECT m.*,u.Nombre AS actor_nombre FROM [TG].[dbo].[inv_urovo_existencias_movimientos] m LEFT JOIN [TG].[dbo].[Usuario] u ON u.Id=m.actor_id WHERE m.modelo=? AND m.estado=? AND m.ubicacion_tipo=? AND ISNULL(m.estacion_codigo,0)=? AND ISNULL(m.proveedor,'')=? ORDER BY m.fecha_evento DESC,m.id DESC",[$key['model'],$key['condition'],$key['location_type'],(int)($key['station_id']??0),(string)($key['provider']??'')]);
    }

    public function activeStation(int $id): bool {
        return (bool)$this->sql->select('SELECT Codigo FROM [TG].[dbo].[Estaciones] WHERE Codigo=? AND activa=1 AND Codigo NOT IN (0,4,20)', [$id]);
    }

    public function data(array $filters): array {
        $where = []; $params = []; $stockWhere=[]; $stockParams=[];
        if (!empty($filters['station_id'])) { $where[]='t.estacion_codigo=?'; $params[]=(int)$filters['station_id']; $stockWhere[]='m.estacion_codigo=?'; $stockParams[]=(int)$filters['station_id']; }
        if (!empty($filters['status'])) { $where[]='t.ubicacion_tipo=?'; $params[]=$filters['status']; $stockWhere[]='m.ubicacion_tipo=?'; $stockParams[]=$filters['status']; }
        if (!empty($filters['q'])) { $where[]='(t.numero_serie LIKE ? OR t.modelo LIKE ? OR t.ip LIKE ? OR t.imei LIKE ?)'; $q='%'.$filters['q'].'%'; array_push($params,$q,$q,$q,$q); $stockWhere[]='m.modelo LIKE ?'; $stockParams[]=$q; }
        $sqlWhere = $where ? ' WHERE '.implode(' AND ',$where) : '';
        $rows = $this->sql->select("SELECT t.*,s.Nombre AS estacion_nombre,lm.fecha_evento AS last_movement,lm.tipo_evento AS last_movement_type,lm.motivo AS last_movement_reason FROM [TG].[dbo].[inv_urovo_terminales] t LEFT JOIN [TG].[dbo].[Estaciones] s ON s.Codigo=t.estacion_codigo OUTER APPLY (SELECT TOP (1) h.fecha_evento,h.tipo_evento,h.motivo FROM [TG].[dbo].[inv_urovo_movimientos] h WHERE h.terminal_id=t.id ORDER BY h.fecha_evento DESC,h.id DESC) lm$sqlWhere ORDER BY t.actualizado_en DESC,t.id DESC", $params);
        $stockSqlWhere=$stockWhere?' WHERE '.implode(' AND ',$stockWhere):'';
        $anonymous = $this->sql->select("SELECT m.modelo,m.ubicacion_tipo,m.estacion_codigo,s.Nombre AS estacion_nombre,m.estado,m.proveedor,SUM(m.delta_cantidad) AS cantidad FROM [TG].[dbo].[inv_urovo_existencias_movimientos] m LEFT JOIN [TG].[dbo].[Estaciones] s ON s.Codigo=m.estacion_codigo$stockSqlWhere GROUP BY m.modelo,m.ubicacion_tipo,m.estacion_codigo,s.Nombre,m.estado,m.proveedor HAVING SUM(m.delta_cantidad)>0 ORDER BY m.modelo,m.estado",$stockParams);
        return ['stations'=>$this->stations(), 'rows'=>$rows, 'anonymous'=>$anonymous];
    }

    public function addBatch(array $items, array $location, ?array $source, int $actor): array {
        if ($source !== null) {
            $sourceModel=mb_strtoupper(trim((string)$source['model']),'UTF-8');
            foreach ($items as $item) {
                $itemModel=mb_strtoupper(trim((string)($item['model']??'I9000S')),'UTF-8');
                if ($itemModel!==$sourceModel) throw new InvalidArgumentException('El modelo de cada terminal debe coincidir con la existencia anónima seleccionada.');
            }
        }
        $result=['inserted'=>[], 'skipped'=>[]];
        foreach ($items as $item) {
            $this->sql->beginTransaction();
            try {
                $serial=trim((string)$item['serial']); $normalized=mb_strtoupper($serial,'UTF-8');
                if ($this->sql->select('SELECT id FROM [TG].[dbo].[inv_urovo_terminales] WITH (UPDLOCK,HOLDLOCK) WHERE numero_serie_normalizado=?',[$normalized])) {
                    $this->sql->commit(); $result['skipped'][]=['serial'=>$serial,'reason'=>'duplicate']; continue;
                }
                if ($source!==null) {
                    $this->stockMove($source['model'],-1,$source,'individualized',$actor,$source['condition']);
                }
                $id=$this->sql->insert('INSERT INTO [TG].[dbo].[inv_urovo_terminales] (numero_serie,numero_serie_normalizado,modelo,ip,mac,imei,fecha_compra,fin_garantia,condicion,accesorios,notas,ubicacion_tipo,estacion_codigo,proveedor,creado_por,creado_en,actualizado_por,actualizado_en) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,SYSDATETIME(),?,SYSDATETIME())', [$serial,$normalized,$item['model']?:'I9000S',$item['ip']?:null,$item['mac']?:null,$item['imei']?:null,$item['purchase_date']?:null,$item['warranty_until']?:null,$item['condition']?:'desconocida',$item['accessories']?:null,$item['notes']?:null,$location['location_type'],$location['station_id'],$location['provider'],$actor,$actor]);
                if (!$id) throw new RuntimeException('No se pudo guardar el terminal.');
                $eventSource=$source===null?null:['location_type'=>$source['location_type'],'station_id'=>$source['station_id'],'provider'=>$source['provider']];
                $this->terminalMove((int)$id,$eventSource,$location,$source['condition']??null,(string)($item['condition']??'desconocida'),$source===null?'alta':'asignacion',$source===null?'alta':'Individualizada desde existencia anónima.',$actor);
                $this->sql->commit(); $result['inserted'][]=['serial'=>$serial,'id'=>(int)$id];
            } catch (DomainException $e) {
                $this->sql->rollBack();
                $result['skipped'][]=['serial'=>(string)$item['serial'],'reason'=>'insufficient_anonymous_quantity'];
            } catch (Throwable $e) {
                $this->sql->rollBack();
                throw $e;
            }
        }
        return $result;
    }

    public function adjustAnonymous(array $data, array $location, int $actor): void {
        $this->sql->beginTransaction();
        try { $this->stockMove((string)($data['model']??'I9000S'),(int)$data['delta'],$location,(string)$data['reason'],$actor,(string)($data['condition']??'desconocida')); $this->sql->commit(); }
        catch (Throwable $e) { $this->sql->rollBack(); throw $e; }
    }

    public function moveTerminal(int $id, array $location, string $status, string $reason, int $actor): void {
        $this->sql->beginTransaction();
        try {
            $rows=$this->sql->select('SELECT * FROM [TG].[dbo].[inv_urovo_terminales] WITH (UPDLOCK,HOLDLOCK) WHERE id=?',[$id]);
            if (!$rows) throw new OutOfBoundsException('No se encontró el terminal.'); $old=$rows[0];
            $dest=['location_type'=>$location['location_type'],'station_id'=>$location['station_id'],'provider'=>$location['provider']];
            $this->sql->update('UPDATE [TG].[dbo].[inv_urovo_terminales] SET ubicacion_tipo=?,estacion_codigo=?,proveedor=?,condicion=?,actualizado_por=?,actualizado_en=SYSDATETIME() WHERE id=?',[$dest['location_type'],$dest['station_id'],$dest['provider'],$status,$actor,$id]);
            $event=$old['ubicacion_tipo']===$dest['location_type'] && (string)$old['condicion']!==$status ? 'cambio_condicion' : 'traslado';
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
            $fields=['modelo'=>'model','ip'=>'ip','mac'=>'mac','imei'=>'imei','fecha_compra'=>'purchase_date','fin_garantia'=>'warranty_until','condicion'=>'condition','accesorios'=>'accessories','notas'=>'notes'];
            $set=[]; $params=[]; $diff=[];
            foreach ($fields as $column=>$key) {
                $value=$changes[$key]; if ($column==='modelo') $value=$value?:'I9000S'; if ($column==='condicion') $value=$value?:'desconocida';
                $prior=$old[$column]??null; if ((string)($prior??'')!==(string)($value??'')) $diff[$column]=['from'=>$prior,'to'=>$value];
                $set[]="[$column]=?"; $params[]=$value;
            }
            $set[]='actualizado_por=?'; $params[]=$actor; $set[]='actualizado_en=SYSDATETIME()'; $params[]=$id;
            $this->sql->update('UPDATE [TG].[dbo].[inv_urovo_terminales] SET '.implode(',',$set).' WHERE id=?',$params);
            if ($diff) {
                $where=['location_type'=>$old['ubicacion_tipo'],'station_id'=>$old['estacion_codigo'],'provider'=>$old['proveedor']];
                $this->terminalMove($id,$where,$where,(string)$old['condicion'],(string)($changes['condition']?:'desconocida'),'ajuste','ajuste: '.json_encode($diff,JSON_UNESCAPED_UNICODE),$actor);
            }
            $this->sql->commit();
            $updated=$this->sql->select('SELECT * FROM [TG].[dbo].[inv_urovo_terminales] WHERE id=?',[$id]); return $updated[0]??[];
        } catch (Throwable $e) { $this->sql->rollBack(); throw $e; }
    }

    private function terminalMove(int $id, ?array $from, array $to, ?string $oldCondition, ?string $newCondition, string $event, string $reason, int $actor): void {
        $this->sql->insert('INSERT INTO [TG].[dbo].[inv_urovo_movimientos] (terminal_id,fecha_evento,tipo_evento,ubicacion_origen_tipo,estacion_origen_codigo,proveedor_origen,ubicacion_destino_tipo,estacion_destino_codigo,proveedor_destino,condicion_anterior,condicion_nueva,motivo,actor_id,registrado_en) VALUES (?,SYSDATETIME(),?,?,?,?,?,?,?,?,?,?,?,SYSDATETIME())',[$id,$event,$from['location_type']??null,$from['station_id']??null,$from['provider']??null,$to['location_type'],$to['station_id'],$to['provider'],$oldCondition,$newCondition,$reason,$actor]);
    }
    private function stockMove(string $model, int $delta, array $location, string $reason, int $actor, string $condition='desconocida'): void {
        if ($delta < 0) {
            $rows=$this->sql->select("SELECT SUM(delta_cantidad) AS cantidad FROM [TG].[dbo].[inv_urovo_existencias_movimientos] WITH (UPDLOCK,HOLDLOCK) WHERE modelo=? AND ubicacion_tipo=? AND ISNULL(estacion_codigo,0)=? AND estado=? AND ISNULL(proveedor,'')=?",[$model,$location['location_type'],(int)($location['station_id']??0),$condition,(string)($location['provider']??'')]);
            if ((int)($rows[0]['cantidad']??0)<abs($delta)) throw new DomainException('La cantidad anónima disponible es insuficiente.');
        }
        $this->sql->insert('INSERT INTO [TG].[dbo].[inv_urovo_existencias_movimientos] (fecha_evento,modelo,delta_cantidad,ubicacion_tipo,estacion_codigo,estado,proveedor,motivo,actor_id,registrado_en) VALUES (SYSDATETIME(),?,?,?,?,?,?,?,?,SYSDATETIME())',[$model?:'I9000S',$delta,$location['location_type'],$location['station_id'],$condition,$location['provider'],$reason,$actor]);
    }
}
