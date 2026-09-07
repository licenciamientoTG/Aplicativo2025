<?php
class TerminalInventoryModel extends Model {
    public const CAPTURE_PERMISSION = 'Inventario terminales - Captura propia';
    public const REPORT_PERMISSION = 'Inventario terminales - Reporte global';

    public function hasPermission(int $userId, string $description): bool {
        return (bool)$this->sql->select('SELECT 1 FROM [TG].[dbo].[tg_permissions_users] pu INNER JOIN [TG].[dbo].[tg_permissions] p ON p.id=pu.permission_id WHERE pu.user_id=? AND p.status=1 AND p.description=?', [$userId, $description]);
    }
    public function inventoryExists(int $stationId, string $weekStart): bool {
        return (bool)$this->sql->select('SELECT 1 FROM [TG].[dbo].[inv_ter_inventarios] WHERE estacion_id=? AND semana_inicio=?', [$stationId, $weekStart]);
    }
    public function activeIncidents(int $stationId): array {
        return $this->sql->select("SELECT * FROM [TG].[dbo].[inv_ter_incidencias] WHERE estacion_id=? AND fecha_cierre_mojo IS NULL", [$stationId]);
    }
    public function ticketUsed(int $ticketId): bool { return (bool)$this->sql->select('SELECT 1 FROM [TG].[dbo].[inv_ter_incidencias] WHERE ticket_mojo_id=?', [$ticketId]); }
    public function saveInventory(array $header, array $details, array $incidentIds): int {
        $this->sql->beginTransaction();
        try {
            $this->sql->insert('INSERT INTO [TG].[dbo].[inv_ter_inventarios] (estacion_id,estacion_nombre,semana_inicio,semana_fin,usuario_id,usuario_correo) VALUES (?,?,?,?,?,?)', $header);
            $created = $this->sql->select('SELECT CAST(SCOPE_IDENTITY() AS INT) AS id');
            $id = (int)($created[0]['id'] ?? 0); if (!$id) throw new RuntimeException('No fue posible crear el inventario.');
            foreach ($details as $detail) $this->sql->insert('INSERT INTO [TG].[dbo].[inv_ter_inventario_detalles] (inventario_id,tipo_terminal,funcionando,danadas) VALUES (?,?,?,?)', [$id,$detail['type'],$detail['working'],$detail['damaged']]);
            foreach ($incidentIds as $incidentId) $this->sql->insert('INSERT INTO [TG].[dbo].[inv_ter_incidencias_inventario] (inventario_id,incidencia_id) VALUES (?,?)', [$id,$incidentId]);
            $this->sql->commit(); return $id;
        } catch (Throwable $e) { $this->sql->rollBack(); throw $e; }
    }
    public function createIncident(array $data): int {
        $this->sql->insert('INSERT INTO [TG].[dbo].[inv_ter_incidencias] (estacion_id,tipo_terminal,ticket_mojo_id,estado_mojo,fecha_apertura_mojo,folio_proveedor,fecha_reporte_proveedor,descripcion,usuario_id,usuario_correo) VALUES (?,?,?,?,?,?,?,?,?,?)', $data);
        $created=$this->sql->select('SELECT CAST(SCOPE_IDENTITY() AS INT) AS id');
        $id=(int)($created[0]['id'] ?? 0); if (!$id) throw new RuntimeException('No fue posible crear la incidencia.'); return $id;
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
}
