<?php
class StationTransferModel extends Model {
    private const AUDIT_TABLE = '[TG].[dbo].[estacion_transferencias_historial]';

    /** Active users whose profile name matches exactly and who have a station assignment. */
    public function candidates(): array {
        $query = "SELECT u.Id AS user_id, u.Usuario AS username, u.Nombre AS name,
                         ue.IdEstacion AS station_id, e.Nombre AS station_name
                  FROM [TG].[dbo].[Usuario] u
                  INNER JOIN [TG].[dbo].[Perfil] p ON p.Id = u.IdPerfil
                  INNER JOIN (SELECT IdUsuario, MAX(IdEstacion) AS IdEstacion
                              FROM [TG].[dbo].[UsuarioEstacion]
                              GROUP BY IdUsuario HAVING COUNT(*) = 1) ue ON ue.IdUsuario = u.Id
                  LEFT JOIN [TG].[dbo].[Estaciones] e ON e.Codigo = ue.IdEstacion
                  WHERE u.Estatus = 1 AND p.Nombre = 'Encargado Estacion'
                  ORDER BY u.Nombre, u.Id;";
        return $this->sql->select($query) ?: [];
    }

    /** Active station options used by the transfer page. */
    public function activeStations(): array {
        $query = 'SELECT Codigo, Nombre FROM [TG].[dbo].[Estaciones] WHERE activa = 1 AND Codigo NOT IN (0,4,20) ORDER BY Codigo;';
        return $this->sql->select($query) ?: [];
    }

    public function history(int $userId): array {
        $query = "SELECT h.id AS transfer_id, h.tipo_operacion AS operation_type,
                         h.usuario_id_1 AS user_id, u.Nombre AS user_name,
                         h.usuario_id_2 AS other_user_id, ou.Nombre AS other_user_name,
                         h.estacion_origen_1 AS from_station_id, fs.Nombre AS from_station_name,
                         h.estacion_destino_1 AS to_station_id, ts.Nombre AS to_station_name,
                         h.estacion_origen_2 AS other_from_station_id, ofs.Nombre AS other_from_station_name,
                         h.estacion_destino_2 AS other_to_station_id, ots.Nombre AS other_to_station_name,
                         h.actor_usuario_id AS performed_by, actor.Nombre AS performed_by_name,
                         h.ocurrido_en AS performed_at
                  FROM " . self::AUDIT_TABLE . " h
                  INNER JOIN [TG].[dbo].[Usuario] u ON u.Id = h.usuario_id_1
                  LEFT JOIN [TG].[dbo].[Usuario] ou ON ou.Id = h.usuario_id_2
                  LEFT JOIN [TG].[dbo].[Estaciones] fs ON fs.Codigo = h.estacion_origen_1
                  LEFT JOIN [TG].[dbo].[Estaciones] ts ON ts.Codigo = h.estacion_destino_1
                  LEFT JOIN [TG].[dbo].[Estaciones] ofs ON ofs.Codigo = h.estacion_origen_2
                  LEFT JOIN [TG].[dbo].[Estaciones] ots ON ots.Codigo = h.estacion_destino_2
                  LEFT JOIN [TG].[dbo].[Usuario] actor ON actor.Id = h.actor_usuario_id
                  WHERE h.usuario_id_1 = ? OR h.usuario_id_2 = ?
                  ORDER BY h.ocurrido_en DESC, h.id DESC;";
        return $this->sql->select($query, [$userId, $userId]) ?: [];
    }

    /** Move one assignment or exchange two assignments and write the audit atomically. */
    public function save(string $operation, int $userId, ?int $otherUserId, ?int $stationId, int $actorId): void {
        if (!in_array($operation, ['move', 'exchange'], true)) throw new InvalidArgumentException('Operación inválida.');
        if ($userId < 1 || $actorId < 1) throw new InvalidArgumentException('Usuario u operador inválido.');
        if ($operation === 'move' && (!$stationId || $stationId < 1)) throw new InvalidArgumentException('Seleccione una estación destino.');
        if ($operation === 'exchange' && (!$otherUserId || $otherUserId < 1 || $otherUserId === $userId)) throw new InvalidArgumentException('Seleccione dos usuarios distintos.');

        $db = $this->sql->getConnection();
        $db->beginTransaction();
        try {
            $ids = $operation === 'exchange' ? [$userId, $otherUserId] : [$userId];
            sort($ids, SORT_NUMERIC); // consistent lock order avoids deadlocks
            $assignments = [];
            foreach ($ids as $id) {
                $stmt = $db->prepare("SELECT u.Id, u.Estatus, p.Nombre AS perfil, ue.IdEstacion AS station_id
                    FROM [TG].[dbo].[Usuario] u WITH (UPDLOCK, HOLDLOCK)
                    INNER JOIN [TG].[dbo].[Perfil] p ON p.Id = u.IdPerfil
                    INNER JOIN [TG].[dbo].[UsuarioEstacion] ue WITH (UPDLOCK, HOLDLOCK) ON ue.IdUsuario = u.Id
                    WHERE u.Id = ?;");
                $stmt->execute([$id]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (count($rows) !== 1 || (int)$rows[0]['Estatus'] !== 1 || $rows[0]['perfil'] !== 'Encargado Estacion') {
                    throw new InvalidArgumentException('El usuario debe estar activo, ser Encargado Estacion y tener una sola estación asignada.');
                }
                $assignments[(int)$id] = (int)$rows[0]['station_id'];
            }
            if ($operation === 'move') {
                $stmt = $db->prepare('SELECT Codigo FROM [TG].[dbo].[Estaciones] WHERE Codigo = ? AND activa = 1 AND Codigo NOT IN (0,4,20);');
                $stmt->execute([$stationId]);
                if (!$stmt->fetchColumn()) throw new InvalidArgumentException('La estación destino no existe.');
                $from = $assignments[$userId];
                if ($from === $stationId) throw new InvalidArgumentException('El usuario ya está asignado a esa estación.');
                $stmt = $db->prepare('UPDATE [TG].[dbo].[UsuarioEstacion] SET IdEstacion = ? WHERE IdUsuario = ? AND IdEstacion = ?;');
                $stmt->execute([$stationId, $userId, $from]);
                if ($stmt->rowCount() !== 1) throw new RuntimeException('No se pudo actualizar la asignación de estación.');
                $audit = [$operation, $userId, $from, $stationId, null, null, null, $actorId];
            } else {
                $from = $assignments[$userId]; $otherFrom = $assignments[$otherUserId];
                if ($from === $otherFrom) throw new InvalidArgumentException('Los usuarios ya están asignados a la misma estación.');
                $stmt = $db->prepare('UPDATE [TG].[dbo].[UsuarioEstacion] SET IdEstacion = CASE WHEN IdUsuario = ? THEN ? ELSE ? END WHERE IdUsuario IN (?, ?) AND IdEstacion IN (?, ?);');
                $stmt->execute([$userId, $otherFrom, $from, $userId, $otherUserId, $from, $otherFrom]);
                if ($stmt->rowCount() !== 2) throw new RuntimeException('No se pudieron intercambiar las asignaciones.');
                $audit = [$operation, $userId, $from, $otherFrom, $otherUserId, $otherFrom, $from, $actorId];
            }
            $stmt = $db->prepare('INSERT INTO ' . self::AUDIT_TABLE . ' (tipo_operacion, usuario_id_1, estacion_origen_1, estacion_destino_1, usuario_id_2, estacion_origen_2, estacion_destino_2, actor_usuario_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?);');
            $stmt->execute($audit);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }
}
