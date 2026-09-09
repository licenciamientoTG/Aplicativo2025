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
    public function inventoryExists(int $stationId, string $weekStart): bool {
        return (bool)$this->sql->select('SELECT 1 FROM [TG].[dbo].[inv_ter_inventarios] WHERE estacion_id=? AND semana_inicio=?', [$stationId, $weekStart]);
    }
    public function getSettings(): array {
        $rows = $this->sql->select('SELECT TOP (1) dia_cierre_semana, valeras_habilitadas, actualizado_por, actualizado_en FROM [TG].[dbo].[inv_ter_configuracion] WHERE id=1');
        return $rows[0] ?? ['dia_cierre_semana' => 7, 'valeras_habilitadas' => 'ticketcard,efecticard,inburgas,sodexo,ultragas,mobil,eox', 'actualizado_por' => null, 'actualizado_en' => null];
    }
    public function saveSettings(int $day, array $enabledValeras, int $userId): void {
        $this->sql->update('UPDATE [TG].[dbo].[inv_ter_configuracion] SET dia_cierre_semana=?, valeras_habilitadas=?, actualizado_por=?, actualizado_en=SYSDATETIME() WHERE id=1', [$day, implode(',', $enabledValeras), $userId]);
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
    public function stationsInventoryStatus(string $weekStart): array {
        return $this->sql->select("SELECT e.Codigo, e.Nombre, i.id AS inventario_id, i.fecha_registro, i.usuario_correo
            FROM [TG].[dbo].[Estaciones] e
            LEFT JOIN [TG].[dbo].[inv_ter_inventarios] i ON i.estacion_id=e.Codigo AND i.semana_inicio=?
            WHERE e.Codigo NOT IN (0,4,20)
            ORDER BY CASE WHEN i.id IS NULL THEN 0 ELSE 1 END, e.Codigo", [$weekStart]);
    }
    public function activeIncidents(int $stationId): array {
        return $this->sql->select("SELECT * FROM [TG].[dbo].[inv_ter_incidencias] WHERE estacion_id=? AND fecha_cierre_mojo IS NULL", [$stationId]);
    }
    public function ticketUsed(int $ticketId): bool { return (bool)$this->sql->select('SELECT 1 FROM [TG].[dbo].[inv_ter_incidencias] WHERE ticket_mojo_id=?', [$ticketId]); }
    public function saveInventory(array $header, array $details, array $incidentIds): int {
        $this->sql->beginTransaction();
        try {
            $id = (int)$this->sql->insert('INSERT INTO [TG].[dbo].[inv_ter_inventarios] (estacion_id,estacion_nombre,semana_inicio,semana_fin,usuario_id,usuario_correo) VALUES (?,?,?,?,?,?)', $header);
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
            $inventoryId = (int)$this->sql->insert('INSERT INTO [TG].[dbo].[inv_ter_inventarios] (estacion_id,estacion_nombre,semana_inicio,semana_fin,usuario_id,usuario_correo) VALUES (?,?,?,?,?,?)', $header);
            if (!$inventoryId) throw new RuntimeException('No fue posible crear el inventario.');

            foreach ($details as $detail) {
                $this->sql->insert('INSERT INTO [TG].[dbo].[inv_ter_inventario_detalles] (inventario_id,tipo_terminal,funcionando,danadas) VALUES (?,?,?,?)', [$inventoryId,$detail['type'],$detail['working'],$detail['damaged']]);
            }

            $incidentIds = $activeIncidentIds;
            foreach ($newIncidents as $incident) {
                $incidentId = (int)$this->sql->insert('INSERT INTO [TG].[dbo].[inv_ter_incidencias] (estacion_id,tipo_terminal,ticket_mojo_id,estado_mojo,fecha_apertura_mojo,folio_proveedor,fecha_reporte_proveedor,descripcion,usuario_id,usuario_correo) VALUES (?,?,?,?,?,?,?,?,?,?)', $incident);
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
        $id=(int)$this->sql->insert('INSERT INTO [TG].[dbo].[inv_ter_incidencias] (estacion_id,tipo_terminal,ticket_mojo_id,estado_mojo,fecha_apertura_mojo,folio_proveedor,fecha_reporte_proveedor,descripcion,usuario_id,usuario_correo) VALUES (?,?,?,?,?,?,?,?,?,?)', $data);
        if (!$id) throw new RuntimeException('No fue posible crear la incidencia.'); return $id;
    }
    public function updateTicketState(int $id, string $old, string $new, ?string $closedAt, string $origin): void {
        if ($old === $new) return;
        $this->sql->update('UPDATE [TG].[dbo].[inv_ter_incidencias] SET estado_mojo=?, fecha_cierre_mojo=? WHERE id=?', [$new,$closedAt,$id]);
        $this->sql->insert('INSERT INTO [TG].[dbo].[inv_ter_incidencia_estados] (incidencia_id,estado_anterior,estado_nuevo,fecha_estado_mojo,origen) VALUES (?,?,?,?,?)', [$id,$old,$new,$closedAt,$origin]);
    }
    public function history(int $stationId, bool $global, array $filters=[]): array {
        $where = $global ? '1=1' : 'i.estacion_id=?'; $params = $global ? [] : [$stationId];
        if (!empty($filters['type'])) { $where .= ' AND i.tipo_terminal=?'; $params[]=$filters['type']; }
        return $this->sql->select("SELECT i.*, s.Nombre AS estacion_nombre, DATEDIFF(DAY,i.fecha_apertura_mojo,GETDATE()) AS dias_naturales FROM [TG].[dbo].[inv_ter_incidencias] i LEFT JOIN [TG].[dbo].[Estaciones] s ON s.Codigo=i.estacion_id WHERE $where ORDER BY i.fecha_registro DESC", $params);
    }
    public function inventoryOverview(): array {
        return $this->sql->select("SELECT i.id AS inventario_id,i.estacion_id,i.estacion_nombre,i.semana_inicio,i.semana_fin,i.fecha_registro,i.usuario_correo,
                d.tipo_terminal,d.funcionando,d.danadas
            FROM [TG].[dbo].[inv_ter_inventarios] i
            INNER JOIN [TG].[dbo].[inv_ter_inventario_detalles] d ON d.inventario_id=i.id
            ORDER BY i.semana_inicio DESC,i.estacion_nombre,d.tipo_terminal");
    }
    public function inventoryIncidents(int $inventoryId, string $type): array {
        return $this->sql->select("SELECT i.id,i.ticket_mojo_id,i.estado_mojo,i.fecha_apertura_mojo,i.fecha_cierre_mojo,i.folio_proveedor,i.descripcion
            FROM [TG].[dbo].[inv_ter_incidencias_inventario] ii
            INNER JOIN [TG].[dbo].[inv_ter_incidencias] i ON i.id=ii.incidencia_id
            WHERE ii.inventario_id=? AND i.tipo_terminal=?
            ORDER BY i.fecha_apertura_mojo DESC", [$inventoryId,$type]);
    }
}
