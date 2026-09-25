<?php
class TerminalInventoryModel extends Model {
    public const CAPTURE_PERMISSION = 'Inventario terminales - Captura propia';
    public const REPORT_PERMISSION = 'Inventario terminales - Reporte global';
    public const CAPTURE_PERMISSION_ID = 96;
    public const REPORT_PERMISSION_ID = 97;

    public function hasPermission(int $userId, string $description): bool {
        // Los permisos ya se cargan en la sesión al iniciar sesión. Consultar la
        // base desde cada función Twig convertía el render del sidebar en varias
        // consultas remotas por petición.
        $permissionId = match ($description) {
            self::CAPTURE_PERMISSION => self::CAPTURE_PERMISSION_ID,
            self::REPORT_PERMISSION => self::REPORT_PERMISSION_ID,
            default => 0,
        };
        $permissions = explode(',', (string)($_SESSION['tg_user']['permissions'] ?? ''));
        return $permissionId > 0 && in_array((string)$permissionId, $permissions, true);
    }
    public function getSettings(): array {
        $rows = $this->sql->select('SELECT TOP (1) valeras_habilitadas, actualizado_por, actualizado_en FROM [TG].[dbo].[inv_ter_configuracion] WHERE id=1');
        return $rows[0] ?? ['valeras_habilitadas' => 'ticketcard,efecticard,inburgas,sodexo,ultragas,mobil,eox', 'actualizado_por' => null, 'actualizado_en' => null];
    }
    public function saveSettings(array $enabledValeras, int $userId): void {
        $this->sql->update('UPDATE [TG].[dbo].[inv_ter_configuracion] SET valeras_habilitadas=?, actualizado_por=?, actualizado_en=SYSDATETIME() WHERE id=1', [implode(',', $enabledValeras), $userId]);
    }
    public function valeraCatalog(): array {
        return $this->sql->select('SELECT codigo, nombre, valor_mojo FROM [TG].[dbo].[inv_ter_valeras] WHERE activo=1 ORDER BY nombre');
    }
    public function addValera(string $code, string $name, string $mojoValue, int $userId): void {
        $this->sql->insert('INSERT INTO [TG].[dbo].[inv_ter_valeras] (codigo,nombre,valor_mojo,creado_por) VALUES (?,?,?,?)', [$code,$name,$mojoValue,$userId]);
    }
    public function activeIncidentTypes(array $types): array {
        if (!$types) return [];
        $marks=implode(',',array_fill(0,count($types),'?'));
        return $this->sql->select("SELECT DISTINCT tipo_terminal FROM [TG].[dbo].[inv_ter_incidencias] WHERE estado_local IN ('Abierta','Solved','Reabierta') AND tipo_terminal IN ($marks)",array_values($types));
    }
    public function stationExpectedConfigurations(): array {
        $rows=$this->sql->select("SELECT e.Codigo AS station_id,e.Nombre AS name,c.tipo_terminal,c.terminales_esperadas
            FROM [TG].[dbo].[Estaciones] e
            LEFT JOIN [TG].[dbo].[inv_ter_configuracion_estacion] c ON c.estacion_id=e.Codigo
            WHERE e.activa=1 AND e.Codigo NOT IN (0,4,20)
            ORDER BY e.Codigo,c.tipo_terminal");
        $stations=[];
        foreach ($rows as $row) {
            $stationId=(int)$row['station_id'];
            if (!isset($stations[$stationId])) $stations[$stationId]=['id'=>$stationId,'station_id'=>$stationId,'name'=>(string)$row['name'],'type_targets'=>[],'targets'=>[]];
            if ($row['tipo_terminal'] !== null) {
                $type=(string)$row['tipo_terminal']; $expected=max(0,(int)$row['terminales_esperadas']);
                $stations[$stationId]['type_targets'][$type]=$expected;
                $stations[$stationId]['targets'][]=['station_id'=>$stationId,'tipo_terminal'=>$type,'terminales_esperadas'=>$expected];
            }
        }
        return array_values($stations);
    }
    public function saveStationExpectedCount(int $stationId, int $expectedCount, int $userId): void {
        $this->saveStationExpectedCounts([['station_id'=>$stationId,'tipo_terminal'=>'urovo','terminales_esperadas'=>$expectedCount]],$userId);
    }
    public function saveStationExpectedCounts(array $targets, int $userId): void {
        $this->sql->beginTransaction();
        try {
            $this->saveStationExpectedCountsInTransaction($targets,$userId);
            $this->sql->commit();
        } catch (Throwable $e) { $this->sql->rollBack(); throw $e; }
    }
    public function saveSettingsWithStationExpectedCounts(array $enabledValeras, array $targets, int $userId): void {
        $this->sql->beginTransaction();
        try {
            $this->sql->update('UPDATE [TG].[dbo].[inv_ter_configuracion] SET valeras_habilitadas=?, actualizado_por=?, actualizado_en=SYSDATETIME() WHERE id=1', [implode(',', $enabledValeras), $userId]);
            $this->saveStationExpectedCountsInTransaction($targets,$userId);
            $this->sql->commit();
        } catch (Throwable $e) { $this->sql->rollBack(); throw $e; }
    }
    private function saveStationExpectedCountsInTransaction(array $targets, int $userId): void {
        foreach ($targets as $target) {
            $stationId=(int)$target['station_id']; $type=(string)$target['tipo_terminal']; $expectedCount=(int)$target['terminales_esperadas'];
            $existing=$this->sql->select('SELECT 1 FROM [TG].[dbo].[inv_ter_configuracion_estacion] WITH (UPDLOCK,HOLDLOCK) WHERE estacion_id=? AND tipo_terminal=?', [$stationId,$type]);
            if ($existing) {
                $this->sql->update('UPDATE [TG].[dbo].[inv_ter_configuracion_estacion] SET terminales_esperadas=?, actualizado_por=?, actualizado_en=SYSDATETIME() WHERE estacion_id=? AND tipo_terminal=?', [$expectedCount,$userId,$stationId,$type]);
            } else {
                $this->sql->insert('INSERT INTO [TG].[dbo].[inv_ter_configuracion_estacion] (estacion_id,tipo_terminal,terminales_esperadas,actualizado_por,actualizado_en) VALUES (?,?,?,?,SYSDATETIME())', [$stationId,$type,$expectedCount,$userId]);
            }
        }
    }
    public function stationExpectedCounts(int $stationId, array $types): array {
        $types=array_values(array_unique($types));
        if (!$types) return [];
        $marks=implode(',',array_fill(0,count($types),'?'));
        $rows=$this->sql->select("SELECT tipo_terminal,terminales_esperadas FROM [TG].[dbo].[inv_ter_configuracion_estacion] WHERE estacion_id=? AND tipo_terminal IN ($marks)", array_merge([$stationId],$types));
        $counts=[];
        foreach ($rows as $row) $counts[(string)$row['tipo_terminal']]=max(0,(int)$row['terminales_esperadas']);
        return $counts;
    }
    public function activeStation(int $stationId): array|false {
        $rows=$this->sql->select('SELECT Codigo,Nombre,mojo_user_id,email FROM [TG].[dbo].[Estaciones] WHERE Codigo=? AND activa=1 AND Codigo NOT IN (0,4,20)', [$stationId]);
        return $rows[0] ?? false;
    }
    public function activeStationIds(): array {
        return array_map(fn($row)=>(int)$row['Codigo'],$this->sql->select('SELECT Codigo FROM [TG].[dbo].[Estaciones] WHERE activa=1 AND Codigo NOT IN (0,4,20)'));
    }
    public function activeStations(): array {
        return $this->sql->select("SELECT e.Codigo,e.Nombre,COUNT(i.id) AS incident_count FROM [TG].[dbo].[Estaciones] e
            LEFT JOIN [TG].[dbo].[inv_ter_incidencias] i ON i.estacion_id=e.Codigo
            WHERE e.activa=1 AND e.Codigo NOT IN (0,4,20)
            GROUP BY e.Codigo,e.Nombre ORDER BY COUNT(i.id) DESC,e.Nombre");
    }
    public function incidentAssignees(?int $stationId=null): array {
        $stationWhere=$stationId===null ? '' : ' WHERE i.estacion_id=?';
        $params=$stationId===null ? [] : [$stationId];
        return $this->sql->select("SELECT DISTINCT COALESCE(t.assigned_to_id,0) AS assigned_to_id,
                COALESCE(NULLIF(LTRIM(RTRIM(COALESCE(u.first_name,'')+' '+COALESCE(u.middle_name,'')+' '+COALESCE(u.last_name,''))),''),'Sin asignar') AS asignado_a
            FROM [TG].[dbo].[inv_ter_incidencias] i
            LEFT JOIN [TG].[dbo].[mojo_tickets] t ON t.id_mojo=i.ticket_mojo_id
            LEFT JOIN [TG].[dbo].[mojo_users] u ON u.id_mojo=t.assigned_to_id
            $stationWhere ORDER BY asignado_a",$params);
    }
    public function activeIncidents(int $stationId): array {
        return $this->sql->select("SELECT * FROM [TG].[dbo].[inv_ter_incidencias] WHERE estacion_id=? AND estado_local IN ('Abierta','Solved','Reabierta') ORDER BY fecha_apertura_mojo DESC", [$stationId]);
    }
    public function pendingResolutionConfirmations(int $stationId): array {
        return $this->sql->select("SELECT id AS incident_id,tipo_terminal,ticket_mojo_id,fecha_cierre_mojo,
                cerrado_por_mojo,cerrado_por_mojo AS cerrado_por_mojo_nombre
            FROM [TG].[dbo].[inv_ter_incidencias]
            WHERE estacion_id=? AND fecha_cierre_mojo IS NOT NULL AND ISNULL(resolucion_confirmada,0)=0
            ORDER BY fecha_cierre_mojo DESC", [$stationId]);
    }
    public function ticketUsed(int $ticketId): bool { return (bool)$this->sql->select('SELECT 1 FROM [TG].[dbo].[inv_ter_incidencias] WHERE ticket_mojo_id=?', [$ticketId]); }
    public function incidentRequest(string $requestKey): array|false {
        $rows=$this->sql->select('SELECT TOP (1) * FROM [TG].[dbo].[inv_ter_solicitudes] WHERE request_key=?', [$requestKey]);
        return $rows[0] ?? false;
    }
    public function setIncidentRequestTicket(string $requestKey, int $ticketId): void {
        $this->sql->update("UPDATE [TG].[dbo].[inv_ter_solicitudes] SET ticket_mojo_id=?, actualizado_en=SYSDATETIME() WHERE request_key=?", [$ticketId,$requestKey]);
    }
    public function incidentById(int $incidentId): array|false {
        $rows=$this->sql->select('SELECT * FROM [TG].[dbo].[inv_ter_incidencias] WHERE id=?', [$incidentId]);
        return $rows[0] ?? false;
    }
    public function incidentStateHistory(int $incidentId): array {
        return $this->sql->select('SELECT ticket_mojo_id,estado_anterior,estado_nuevo,fecha_estado_mojo,origen,usuario_id,usuario_correo,comentario,sincronizacion,fecha_registro FROM [TG].[dbo].[inv_ter_incidencia_estados] WHERE incidencia_id=? ORDER BY fecha_registro DESC,id DESC', [$incidentId]);
    }
    public function startIncidentRequest(string $requestKey, int $stationId, int $userId, string $payloadHash, string $payloadJson): array {
        $existing=$this->incidentRequest($requestKey);
        if ($existing) {
            if ((int)$existing['estacion_id']!==$stationId || (int)$existing['usuario_id']!==$userId || !hash_equals((string)$existing['payload_hash'],$payloadHash)) throw new InvalidArgumentException('La clave de idempotencia ya se usó con otros datos.');
            return $existing;
        }
        try {
            $this->sql->insert('INSERT INTO [TG].[dbo].[inv_ter_solicitudes] (request_key,estacion_id,usuario_id,payload_hash,payload_json,estado) VALUES (?,?,?,?,?,?)', [$requestKey,$stationId,$userId,$payloadHash,$payloadJson,'pendiente']);
        } catch (Throwable $e) {
            $existing=$this->incidentRequest($requestKey);
            if (!$existing) throw $e;
            if ((int)$existing['estacion_id']!==$stationId || (int)$existing['usuario_id']!==$userId || !hash_equals((string)$existing['payload_hash'],$payloadHash)) throw new InvalidArgumentException('La clave de idempotencia ya se usó con otros datos.');
            return $existing;
        }
        return $this->incidentRequest($requestKey) ?: throw new RuntimeException('No fue posible registrar la solicitud de incidencia.');
    }
    public function completeIncidentRequest(string $requestKey, array $data): int {
        $this->sql->beginTransaction();
        try {
            $request=$this->incidentRequest($requestKey);
            if (!$request) throw new RuntimeException('La solicitud de incidencia no existe.');
            $existing=$this->sql->select('SELECT id FROM [TG].[dbo].[inv_ter_incidencias] WHERE ticket_mojo_id=?', [(int)$data[2]]);
            if ($existing) $incidentId=(int)$existing[0]['id'];
            else {
                $incidentId=(int)$this->sql->insert('INSERT INTO [TG].[dbo].[inv_ter_incidencias] (estacion_id,tipo_terminal,ticket_mojo_id,estado_mojo,estado_local,fecha_apertura_mojo,folio_proveedor,fecha_reporte_proveedor,descripcion,problema_recurrente,serial_urovo,usuario_id,usuario_correo) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)', [$data[0],$data[1],$data[2],$data[3],'Abierta',$data[4],$data[5],$data[6],$data[7],$data[8],$data[9],$data[10],$data[11]]);
                if (!$incidentId) throw new RuntimeException('No fue posible crear la incidencia.');
                $this->sql->insert('INSERT INTO [TG].[dbo].[inv_ter_incidencia_estados] (incidencia_id,ticket_mojo_id,estado_anterior,estado_nuevo,fecha_estado_mojo,origen,usuario_id,usuario_correo,comentario,sincronizacion) VALUES (?,?,NULL,?,?,?,?,?,?,?)', [$incidentId,(int)$data[2],'Abierta',date('Y-m-d H:i:s'),'registro',$data[9],$data[10],null,'sincronizado']);
            }
            $this->sql->update("UPDATE [TG].[dbo].[inv_ter_solicitudes] SET ticket_mojo_id=?, incidencia_id=?, estado='completada', actualizado_en=SYSDATETIME() WHERE request_key=?", [(int)$data[2],$incidentId,$requestKey]);
            $this->sql->commit(); return $incidentId;
        } catch (Throwable $e) { $this->sql->rollBack(); throw $e; }
    }
    public function recordIncidentError(string $requestKey, string $error): void {
        $this->sql->update("UPDATE [TG].[dbo].[inv_ter_solicitudes] SET ultimo_error=?, actualizado_en=SYSDATETIME() WHERE request_key=?", [mb_substr($error,0,500),$requestKey]);
    }
    public function createIncident(array $data): int {
        $id=(int)$this->sql->insert('INSERT INTO [TG].[dbo].[inv_ter_incidencias] (estacion_id,tipo_terminal,ticket_mojo_id,estado_mojo,fecha_apertura_mojo,folio_proveedor,fecha_reporte_proveedor,descripcion,serial_urovo,usuario_id,usuario_correo) VALUES (?,?,?,?,?,?,?,?,?,?,?)', $data);
        if (!$id) throw new RuntimeException('No fue posible crear la incidencia.'); return $id;
    }
    public function updateTicketState(int $id, string $old, string $new, ?string $closedAt, ?string $closedByMojo, string $origin, ?string $serialUrovo=null, ?int $userId=null, ?string $userEmail=null, ?string $comment=null, string $sync='sincronizado'): void {
        $this->sql->beginTransaction();
        try {
            $this->sql->update('UPDATE [TG].[dbo].[inv_ter_incidencias] SET estado_mojo=?,estado_local=?,fecha_cierre_mojo=?,cerrado_por_mojo=COALESCE(?,cerrado_por_mojo),serial_urovo=COALESCE(NULLIF(?,\'\'),serial_urovo) WHERE id=?', [$new,$new==='Closed' ? 'Closed' : $new,$new==='Closed' ? $closedAt : null,$closedByMojo,$serialUrovo,$id]);
            if ($old !== $new) {
                $ticket=$this->sql->select('SELECT ticket_mojo_id FROM [TG].[dbo].[inv_ter_incidencias] WHERE id=?', [$id]);
                $this->sql->insert('INSERT INTO [TG].[dbo].[inv_ter_incidencia_estados] (incidencia_id,ticket_mojo_id,estado_anterior,estado_nuevo,fecha_estado_mojo,origen,usuario_id,usuario_correo,comentario,sincronizacion) VALUES (?,?,?,?,?,?,?,?,?,?)', [$id,(int)($ticket[0]['ticket_mojo_id'] ?? 0),$old,$new,$closedAt ?? date('Y-m-d H:i:s'),$origin,$userId,$userEmail ?? 'MOJO/API',$comment,$sync]);
            }
            $this->sql->commit();
        } catch (Throwable $e) { $this->sql->rollBack(); throw $e; }
    }
    public function confirmSolvedIncident(int $incidentId, int $stationId, int $userId, string $email, ?string $note): bool {
        $affected=$this->sql->updateSafe('UPDATE [TG].[dbo].[inv_ter_incidencias] SET resolucion_confirmada=1, confirmado_resuelto_por=?, confirmado_resuelto_correo=?, fecha_confirmacion_resolucion=SYSDATETIME(), nota_confirmacion_resolucion=? WHERE id=? AND estacion_id=? AND fecha_cierre_mojo IS NOT NULL AND ISNULL(resolucion_confirmada,0)=0', [$userId,$email,$note,$incidentId,$stationId]);
        return $affected === 1;
    }
    public function incidentForStation(int $incidentId, int $stationId): array|false {
        $rows=$this->sql->select('SELECT id,estado_mojo,estado_local,fecha_cierre_mojo,ticket_mojo_id FROM [TG].[dbo].[inv_ter_incidencias] WHERE id=? AND estacion_id=?', [$incidentId,$stationId]);
        return $rows[0] ?? false;
    }
    public function changeIncidentState(int $incidentId, string $old, string $new, int $userId, string $email, string $origin, ?string $comment=null, string $sync='sincronizado'): void {
        $this->updateTicketState($incidentId,$old,$new,$new==='Closed' ? date('Y-m-d H:i:s') : null,null,$origin,null,$userId,$email,$comment,$sync);
    }
    public function recordIncidentSyncFailure(int $incidentId, string $state, string $origin, string $error): void {
        $incident=$this->incidentById($incidentId);
        if (!$incident) return;
        $this->sql->insert('INSERT INTO [TG].[dbo].[inv_ter_incidencia_estados] (incidencia_id,ticket_mojo_id,estado_anterior,estado_nuevo,fecha_estado_mojo,origen,usuario_id,usuario_correo,comentario,sincronizacion) VALUES (?,?,?,?,?,?,?,?,?,?)', [$incidentId,(int)$incident['ticket_mojo_id'],$state,$state,date('Y-m-d H:i:s'),$origin,null,'MOJO/API',mb_substr($error,0,1000),'fallido']);
    }
    public function history(int $stationId, bool $global, array $filters=[]): array {
        $where = $global ? '1=1' : 'i.estacion_id=?'; $params = $global ? [] : [$stationId];
        if (!empty($filters['type'])) { $where .= ' AND i.tipo_terminal=?'; $params[]=$filters['type']; }
        return $this->sql->select("SELECT i.*, s.Nombre AS estacion_nombre,COALESCE(c.terminales_esperadas,0) AS terminales_esperadas,
                i.serial_urovo AS serie_urovo,i.cerrado_por_mojo AS cerrado_por_mojo_nombre,
                i.resolucion_confirmada AS solucion_confirmada,i.confirmado_resuelto_correo AS solucion_confirmada_por_correo,
                i.fecha_confirmacion_resolucion AS solucion_confirmada_en,i.nota_confirmacion_resolucion AS solucion_confirmacion_nota,
                DATEDIFF(DAY,i.fecha_apertura_mojo,GETDATE()) AS dias_naturales
            FROM [TG].[dbo].[inv_ter_incidencias] i
            LEFT JOIN [TG].[dbo].[Estaciones] s ON s.Codigo=i.estacion_id
            LEFT JOIN [TG].[dbo].[inv_ter_configuracion_estacion] c ON c.estacion_id=i.estacion_id AND c.tipo_terminal=i.tipo_terminal
            WHERE $where ORDER BY i.fecha_registro DESC", $params);
    }
    /**
     * Reporte gerencial de incidencias. Los filtros se aplican en SQL para que
     * los enlaces enviados por correo puedan conservar el corte y la estación.
     */
    public function incidentReport(array $filters=[]): array {
        $where=['1=1']; $params=[];
        if (!empty($filters['station'])) { $where[]='i.estacion_id=?'; $params[]=(int)$filters['station']; }
        if (!empty($filters['type'])) { $where[]='i.tipo_terminal=?'; $params[]=(string)$filters['type']; }
        if (($filters['status'] ?? '')==='open') $where[]="i.estado_local IN ('Abierta','Solved','Reabierta')";
        if (($filters['status'] ?? '')==='solved') $where[]="i.estado_local='Solved'";
        if (($filters['status'] ?? '')==='reopened') $where[]="i.estado_local='Reabierta'";
        if (($filters['status'] ?? '')==='closed') $where[]="i.estado_local='Closed'";
        if (array_key_exists('assigned',$filters) && $filters['assigned']!=='') { $where[]='COALESCE(t.assigned_to_id,0)=?'; $params[]=(int)$filters['assigned']; }
        if (!empty($filters['month'])) {
            $where[]="i.fecha_apertura_mojo >= CAST(? + '-01' AS date) AND i.fecha_apertura_mojo < DATEADD(month,1,CAST(? + '-01' AS date))";
            $params[]=(string)$filters['month']; $params[]=(string)$filters['month'];
        }
        if (!empty($filters['from'])) { $where[]='i.fecha_apertura_mojo >= ?'; $params[]=(string)$filters['from'].' 00:00:00'; }
        if (!empty($filters['to'])) { $where[]='i.fecha_apertura_mojo < DATEADD(day,1,CAST(? AS date))'; $params[]=(string)$filters['to']; }
        if (!empty($filters['as_of'])) { $where[]='i.fecha_apertura_mojo < DATEADD(day,1,CAST(? AS date))'; $params[]=(string)$filters['as_of']; }
        if (!empty($filters['q'])) {
            $where[]="(CONVERT(varchar(30),i.ticket_mojo_id) LIKE ? OR s.Nombre LIKE ? OR i.tipo_terminal LIKE ? OR i.descripcion LIKE ? OR i.folio_proveedor LIKE ? OR i.serial_urovo LIKE ? OR i.estado_mojo LIKE ? OR t.title LIKE ? OR t.description LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR st.name LIKE ? OR pr.name LIKE ? OR q.name LIKE ? OR tf.name LIKE ? OR co.name LIKE ? OR ur.first_name LIKE ? OR ur.last_name LIKE ?)";
            $needle='%'.(string)$filters['q'].'%';
            for ($n=0;$n<18;$n++) $params[]=$needle;
        }
        return $this->sql->select("SELECT i.*,s.Nombre AS estacion_nombre,
                COALESCE(c.terminales_esperadas,0) AS terminales_esperadas,
                i.serial_urovo AS serie_urovo,
                i.cerrado_por_mojo AS cerrado_por_mojo_nombre,
                i.resolucion_confirmada AS solucion_confirmada,
                i.confirmado_resuelto_correo AS solucion_confirmada_por_correo,
                i.fecha_confirmacion_resolucion AS solucion_confirmada_en,
                i.nota_confirmacion_resolucion AS solucion_confirmacion_nota,
                COALESCE(t.assigned_to_id,0) AS assigned_to_id,
                NULLIF(LTRIM(RTRIM(COALESCE(u.first_name,'')+' '+COALESCE(u.middle_name,'')+' '+COALESCE(u.last_name,''))),'') AS asignado_a,
                t.title AS mojo_title,t.description AS mojo_description,
                t.created_on AS mojo_created_on,t.solved_on AS mojo_solved_on,
                st.name AS mojo_status,pr.name AS mojo_priority,
                q.name AS mojo_queue,tf.name AS mojo_form,
                co.name AS mojo_company,
                NULLIF(LTRIM(RTRIM(COALESCE(ur.first_name,'')+' '+COALESCE(ur.middle_name,'')+' '+COALESCE(ur.last_name,''))),'') AS mojo_solicitante,
                DATEDIFF(DAY,i.fecha_apertura_mojo,COALESCE(i.fecha_cierre_mojo,GETDATE())) AS dias_naturales
            FROM [TG].[dbo].[inv_ter_incidencias] i
            LEFT JOIN [TG].[dbo].[Estaciones] s ON s.Codigo=i.estacion_id
            LEFT JOIN [TG].[dbo].[inv_ter_configuracion_estacion] c ON c.estacion_id=i.estacion_id AND c.tipo_terminal=i.tipo_terminal
            LEFT JOIN [TG].[dbo].[mojo_tickets] t ON t.id_mojo=i.ticket_mojo_id
            LEFT JOIN [TG].[dbo].[mojo_users] u ON u.id_mojo=t.assigned_to_id
            LEFT JOIN [TG].[dbo].[mojo_users] ur ON ur.id_mojo=t.user_id
            LEFT JOIN [TG].[dbo].[mojo_companies] co ON co.id_mojo=t.company_id
            LEFT JOIN [TG].[dbo].[mojo_ticket_status] st ON st.id_mojo=t.status_id
            LEFT JOIN [TG].[dbo].[mojo_ticket_priority] pr ON pr.id_mojo=t.priority_id
            LEFT JOIN [TG].[dbo].[mojo_ticket_queue] q ON q.id_mojo=t.ticket_queue_id
            LEFT JOIN [TG].[dbo].[mojo_ticket_forms] tf ON tf.id_mojo=t.ticket_form_id
            WHERE ".implode(' AND ',$where)." ORDER BY i.fecha_apertura_mojo DESC,i.id DESC", $params);
    }
    public function monthlyIncidentSummary(?int $stationId=null): array {
        $where=$stationId===null ? '' : 'WHERE estacion_id=?';
        $params=$stationId===null ? [] : [$stationId];
        return $this->sql->select("WITH monthly AS (
                SELECT YEAR(fecha_apertura_mojo) AS [year],MONTH(fecha_apertura_mojo) AS [month],
                       COUNT(*) AS incident_count,SUM(CASE WHEN tipo_terminal='urovo' THEN 1 ELSE 0 END) AS urovo_count
                FROM [TG].[dbo].[inv_ter_incidencias] $where
                GROUP BY YEAR(fecha_apertura_mojo),MONTH(fecha_apertura_mojo)
            ) SELECT [year],[month],incident_count,urovo_count,
                SUM(incident_count) OVER (ORDER BY [year],[month] ROWS UNBOUNDED PRECEDING) AS cumulative_count
              FROM monthly ORDER BY [year] DESC,[month] DESC",$params);
    }
}
