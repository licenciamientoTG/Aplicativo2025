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
    public function inventoryExists(int $stationId, string $inventoryDate): bool {
        return (bool)$this->sql->select('SELECT 1 FROM [TG].[dbo].[inv_ter_inventarios] WHERE estacion_id=? AND fecha_inventario=?', [$stationId, $inventoryDate]);
    }
    public function getSettings(): array {
        $rows = $this->sql->select('SELECT TOP (1) dia_inventario_semana, valeras_habilitadas, actualizado_por, actualizado_en FROM [TG].[dbo].[inv_ter_configuracion] WHERE id=1');
        return $rows[0] ?? ['dia_inventario_semana' => 7, 'valeras_habilitadas' => 'ticketcard,efecticard,inburgas,sodexo,ultragas,mobil,eox', 'actualizado_por' => null, 'actualizado_en' => null];
    }
    public function saveSettings(int $day, array $enabledValeras, int $userId): void {
        $this->sql->update('UPDATE [TG].[dbo].[inv_ter_configuracion] SET dia_inventario_semana=?, valeras_habilitadas=?, actualizado_por=?, actualizado_en=SYSDATETIME() WHERE id=1', [$day, implode(',', $enabledValeras), $userId]);
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
        return $this->sql->select("SELECT DISTINCT tipo_terminal FROM [TG].[dbo].[inv_ter_incidencias] WHERE fecha_cierre_mojo IS NULL AND tipo_terminal IN ($marks)",array_values($types));
    }
    private function expectedTotalsSubquery(array $types): array {
        $types=array_values(array_unique($types));
        if (!$types) return ['SELECT estacion_id,0 AS terminales_esperadas FROM [TG].[dbo].[inv_ter_configuracion_estacion] WHERE 1=0',[]];
        $marks=implode(',',array_fill(0,count($types),'?'));
        return ["SELECT estacion_id,SUM(terminales_esperadas) AS terminales_esperadas FROM [TG].[dbo].[inv_ter_configuracion_estacion] WHERE tipo_terminal IN ($marks) GROUP BY estacion_id",$types];
    }
    public function stationsInventoryStatus(string $inventoryDate, array $types): array {
        [$totalsSql,$typeParams]=$this->expectedTotalsSubquery($types);
        return $this->sql->select("SELECT e.Codigo, e.Nombre, i.id AS inventario_id, i.fecha_registro, i.usuario_correo,
                COALESCE(c.terminales_esperadas,0) AS terminales_esperadas
            FROM [TG].[dbo].[Estaciones] e
            LEFT JOIN [TG].[dbo].[inv_ter_inventarios] i ON i.estacion_id=e.Codigo AND i.fecha_inventario=?
            LEFT JOIN ($totalsSql) c ON c.estacion_id=e.Codigo
            WHERE e.activa=1 AND e.Codigo NOT IN (0,4,20)
            ORDER BY CASE WHEN i.id IS NULL THEN 0 ELSE 1 END, e.Codigo", array_merge([$inventoryDate],$typeParams));
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
    public function saveSettingsWithStationExpectedCounts(int $day, array $enabledValeras, array $targets, int $userId): void {
        $this->sql->beginTransaction();
        try {
            $this->sql->update('UPDATE [TG].[dbo].[inv_ter_configuracion] SET dia_inventario_semana=?, valeras_habilitadas=?, actualizado_por=?, actualizado_en=SYSDATETIME() WHERE id=1', [$day, implode(',', $enabledValeras), $userId]);
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
        return $this->sql->select('SELECT Codigo,Nombre FROM [TG].[dbo].[Estaciones] WHERE activa=1 AND Codigo NOT IN (0,4,20) ORDER BY Nombre');
    }
    public function incidentAssignees(): array {
        return $this->sql->select("SELECT DISTINCT COALESCE(t.assigned_to_id,0) AS assigned_to_id,
                COALESCE(NULLIF(LTRIM(RTRIM(COALESCE(u.first_name,'')+' '+COALESCE(u.middle_name,'')+' '+COALESCE(u.last_name,''))),''),'Sin asignar') AS asignado_a
            FROM [TG].[dbo].[inv_ter_incidencias] i
            LEFT JOIN [TG].[dbo].[mojo_tickets] t ON t.id_mojo=i.ticket_mojo_id
            LEFT JOIN [TG].[dbo].[mojo_users] u ON u.id_mojo=t.assigned_to_id
            ORDER BY asignado_a");
    }
    public function activeIncidents(int $stationId): array {
        return $this->sql->select("SELECT * FROM [TG].[dbo].[inv_ter_incidencias] WHERE estacion_id=? AND fecha_cierre_mojo IS NULL", [$stationId]);
    }
    public function pendingResolutionConfirmations(int $stationId): array {
        return $this->sql->select("SELECT id AS incident_id,tipo_terminal,ticket_mojo_id,fecha_cierre_mojo,
                cerrado_por_mojo,cerrado_por_mojo AS cerrado_por_mojo_nombre
            FROM [TG].[dbo].[inv_ter_incidencias]
            WHERE estacion_id=? AND fecha_cierre_mojo IS NOT NULL AND ISNULL(resolucion_confirmada,0)=0
            ORDER BY fecha_cierre_mojo DESC", [$stationId]);
    }
    public function ticketUsed(int $ticketId): bool { return (bool)$this->sql->select('SELECT 1 FROM [TG].[dbo].[inv_ter_incidencias] WHERE ticket_mojo_id=?', [$ticketId]); }
    public function saveInventory(array $header, array $details, array $incidentIds): int {
        $this->sql->beginTransaction();
        try {
            $id = (int)$this->sql->insert('INSERT INTO [TG].[dbo].[inv_ter_inventarios] (estacion_id,estacion_nombre,fecha_inventario,usuario_id,usuario_correo) VALUES (?,?,?,?,?)', $header);
            if (!$id) throw new RuntimeException('No fue posible crear el inventario.');
            foreach ($details as $detail) $this->sql->insert('INSERT INTO [TG].[dbo].[inv_ter_inventario_detalles] (inventario_id,tipo_terminal,funcionando,danadas) VALUES (?,?,?,?)', [$id,$detail['type'],$detail['working'],$detail['damaged']]);
            foreach ($incidentIds as $incidentId) $this->sql->insert('INSERT INTO [TG].[dbo].[inv_ter_incidencias_inventario] (inventario_id,incidencia_id) VALUES (?,?)', [$id,$incidentId]);
            $this->sql->commit(); return $id;
        } catch (Throwable $e) { $this->sql->rollBack(); throw $e; }
    }
    /**
     * Persiste el inventario y sus incidencias nuevas como una sola unidad.
     * Si cualquier ticket no se puede registrar, el inventario y ninguna de
     * sus incidencias se conservan parcialmente.
     */
    public function saveInventoryWithIncidents(array $header, array $details, array $activeIncidentIds, array $newIncidents): int {
        $this->sql->beginTransaction();
        try {
            $inventoryId = (int)$this->sql->insert('INSERT INTO [TG].[dbo].[inv_ter_inventarios] (estacion_id,estacion_nombre,fecha_inventario,usuario_id,usuario_correo) VALUES (?,?,?,?,?)', $header);
            if (!$inventoryId) throw new RuntimeException('No fue posible crear el inventario.');

            foreach ($details as $detail) {
                $this->sql->insert('INSERT INTO [TG].[dbo].[inv_ter_inventario_detalles] (inventario_id,tipo_terminal,funcionando,danadas) VALUES (?,?,?,?)', [$inventoryId,$detail['type'],$detail['working'],$detail['damaged']]);
            }

            $incidentIds = $activeIncidentIds;
            foreach ($newIncidents as $incident) {
                $incidentId = (int)$this->sql->insert('INSERT INTO [TG].[dbo].[inv_ter_incidencias] (estacion_id,tipo_terminal,ticket_mojo_id,estado_mojo,fecha_apertura_mojo,folio_proveedor,fecha_reporte_proveedor,descripcion,serial_urovo,usuario_id,usuario_correo) VALUES (?,?,?,?,?,?,?,?,?,?,?)', $incident);
                if (!$incidentId) throw new RuntimeException('No fue posible crear una incidencia.');
                $incidentIds[] = $incidentId;
            }
            foreach ($incidentIds as $incidentId) {
                $this->sql->insert('INSERT INTO [TG].[dbo].[inv_ter_incidencias_inventario] (inventario_id,incidencia_id) VALUES (?,?)', [$inventoryId,$incidentId]);
            }
            $this->sql->commit();
            return $inventoryId;
        } catch (Throwable $e) {
            $this->sql->rollBack();
            throw $e;
        }
    }
    public function createIncident(array $data): int {
        $id=(int)$this->sql->insert('INSERT INTO [TG].[dbo].[inv_ter_incidencias] (estacion_id,tipo_terminal,ticket_mojo_id,estado_mojo,fecha_apertura_mojo,folio_proveedor,fecha_reporte_proveedor,descripcion,serial_urovo,usuario_id,usuario_correo) VALUES (?,?,?,?,?,?,?,?,?,?,?)', $data);
        if (!$id) throw new RuntimeException('No fue posible crear la incidencia.'); return $id;
    }
    public function updateTicketState(int $id, string $old, string $new, ?string $closedAt, ?string $closedByMojo, string $origin, ?string $serialUrovo=null): void {
        $this->sql->update('UPDATE [TG].[dbo].[inv_ter_incidencias] SET estado_mojo=?, fecha_cierre_mojo=COALESCE(?,fecha_cierre_mojo), cerrado_por_mojo=COALESCE(?,cerrado_por_mojo), serial_urovo=COALESCE(NULLIF(?,\'\'),serial_urovo) WHERE id=?', [$new,$closedAt,$closedByMojo,$serialUrovo,$id]);
        if ($old === $new) return;
        $this->sql->insert('INSERT INTO [TG].[dbo].[inv_ter_incidencia_estados] (incidencia_id,estado_anterior,estado_nuevo,fecha_estado_mojo,origen) VALUES (?,?,?,?,?)', [$id,$old,$new,$closedAt,$origin]);
    }
    public function confirmSolvedIncident(int $incidentId, int $stationId, int $userId, string $email, ?string $note): bool {
        $affected=$this->sql->updateSafe('UPDATE [TG].[dbo].[inv_ter_incidencias] SET resolucion_confirmada=1, confirmado_resuelto_por=?, confirmado_resuelto_correo=?, fecha_confirmacion_resolucion=SYSDATETIME(), nota_confirmacion_resolucion=? WHERE id=? AND estacion_id=? AND fecha_cierre_mojo IS NOT NULL AND ISNULL(resolucion_confirmada,0)=0', [$userId,$email,$note,$incidentId,$stationId]);
        return $affected === 1;
    }
    public function incidentForStation(int $incidentId, int $stationId): array|false {
        $rows=$this->sql->select('SELECT id,estado_mojo,fecha_cierre_mojo,ticket_mojo_id FROM [TG].[dbo].[inv_ter_incidencias] WHERE id=? AND estacion_id=?', [$incidentId,$stationId]);
        return $rows[0] ?? false;
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
        if (($filters['status'] ?? '')==='open') $where[]='i.fecha_cierre_mojo IS NULL';
        if (($filters['status'] ?? '')==='closed') $where[]='i.fecha_cierre_mojo IS NOT NULL';
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
    public function inventoryDateGroups(): array {
        return $this->sql->select("SELECT i.fecha_inventario,COUNT(DISTINCT i.estacion_id) AS estaciones_capturadas,
                COALESCE(SUM(d.funcionando),0) AS funcionando,COALESCE(SUM(d.danadas),0) AS danadas
            FROM [TG].[dbo].[inv_ter_inventarios] i
            INNER JOIN [TG].[dbo].[inv_ter_inventario_detalles] d ON d.inventario_id=i.id
            GROUP BY i.fecha_inventario
            ORDER BY i.fecha_inventario DESC");
    }
    public function inventoryGroupStations(string $inventoryDate, array $types): array {
        [$totalsSql,$typeParams]=$this->expectedTotalsSubquery($types);
        return $this->sql->select("SELECT e.Codigo,e.Nombre,i.id AS inventario_id,i.fecha_registro,i.usuario_correo,
                COALESCE(c.terminales_esperadas,0) AS terminales_esperadas,
                COALESCE(det.funcionando,0) AS funcionando,COALESCE(det.danadas,0) AS danadas
            FROM [TG].[dbo].[Estaciones] e
            LEFT JOIN [TG].[dbo].[inv_ter_inventarios] i ON i.estacion_id=e.Codigo AND i.fecha_inventario=?
            LEFT JOIN ($totalsSql) c ON c.estacion_id=e.Codigo
            OUTER APPLY (SELECT SUM(d.funcionando) AS funcionando,SUM(d.danadas) AS danadas FROM [TG].[dbo].[inv_ter_inventario_detalles] d WHERE d.inventario_id=i.id) det
            WHERE e.activa=1 AND e.Codigo NOT IN (0,4,20)
            ORDER BY e.Codigo", array_merge([$inventoryDate],$typeParams));
    }
    public function inventoryTypes(int $inventoryId): array {
        return $this->sql->select("SELECT i.id AS inventario_id,i.estacion_id,i.estacion_nombre,i.fecha_registro,i.usuario_correo,
                COALESCE(c.terminales_esperadas,0) AS terminales_esperadas,
                d.tipo_terminal,d.funcionando,d.danadas
            FROM [TG].[dbo].[inv_ter_inventarios] i
            INNER JOIN [TG].[dbo].[inv_ter_inventario_detalles] d ON d.inventario_id=i.id
            LEFT JOIN [TG].[dbo].[inv_ter_configuracion_estacion] c ON c.estacion_id=i.estacion_id AND c.tipo_terminal=d.tipo_terminal
            WHERE i.id=?
            ORDER BY d.tipo_terminal", [$inventoryId]);
    }
    public function inventoryIncidents(int $inventoryId, string $type): array {
        return $this->sql->select("SELECT i.id,i.estacion_id,i.ticket_mojo_id,i.estado_mojo,i.fecha_apertura_mojo,i.fecha_cierre_mojo,i.cerrado_por_mojo,i.cerrado_por_mojo AS cerrado_por_mojo_nombre,i.folio_proveedor,i.descripcion,i.serial_urovo,i.serial_urovo AS serie_urovo,
                i.resolucion_confirmada,i.resolucion_confirmada AS solucion_confirmada,i.confirmado_resuelto_por,i.confirmado_resuelto_correo,i.confirmado_resuelto_correo AS solucion_confirmada_por_correo,i.fecha_confirmacion_resolucion,i.fecha_confirmacion_resolucion AS solucion_confirmada_en,i.nota_confirmacion_resolucion,i.nota_confirmacion_resolucion AS solucion_confirmacion_nota
            FROM [TG].[dbo].[inv_ter_incidencias_inventario] ii
            INNER JOIN [TG].[dbo].[inv_ter_incidencias] i ON i.id=ii.incidencia_id
            WHERE ii.inventario_id=? AND i.tipo_terminal=?
            ORDER BY i.fecha_apertura_mojo DESC", [$inventoryId,$type]);
    }
}
