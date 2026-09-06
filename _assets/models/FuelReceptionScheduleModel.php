<?php
class FuelReceptionScheduleModel extends Model {

    function get_day(string $fecha): array {
        $query = "
            SELECT
                s.id, s.fecha, s.hora, s.supplier_id, p2.den AS supplier_nombre,
                s.terminal_id, t.nombre AS terminal_nombre,
                s.station_code, e.Nombre AS station_nombre,
                s.product, s.mezcla, s.litros,
                s.carrier_id, c.nombre AS carrier_nombre,
                s.referencia, s.notas, s.estatus
            FROM TG.dbo.fuel_reception_schedule s
            LEFT JOIN TG.dbo.Proveedores p1 ON p1.id = s.supplier_id
            LEFT JOIN SG12.dbo.Proveedores p2 ON p2.cod = p1.id_control_gas
            LEFT JOIN TG.dbo.fuel_terminals t ON t.id = s.terminal_id
            LEFT JOIN TG.dbo.fuel_carriers c ON c.id = s.carrier_id
            LEFT JOIN TG.dbo.Estaciones e ON e.Codigo = s.station_code
            WHERE s.fecha = ? AND s.estatus <> 'Cancelado'
            ORDER BY p2.den, t.nombre, s.hora
        ";
        return $this->sql->select($query, [$fecha]) ?: [];
    }

    function get_one(int $id): ?array {
        $query = "SELECT * FROM TG.dbo.fuel_reception_schedule WHERE id = ?";
        $rows = $this->sql->select($query, [$id]);
        return $rows[0] ?? null;
    }

    // A dedicated query, NOT ProveedoresModel::get_actives() -- that method
    // selects SG12.dbo.Proveedores.* (PK `cod`) and never exposes
    // TG.dbo.Proveedores.id, which is what this feature's supplier_id
    // foreign key actually points to (confirmed 2026-09-05 against real data).
    function get_proveedores(): array {
        $query = "
            SELECT t1.id, t2.den AS nombre
            FROM TG.dbo.Proveedores t1
            JOIN SG12.dbo.Proveedores t2 ON t2.cod = t1.id_control_gas
            WHERE t1.activo = 1
            ORDER BY t2.den
        ";
        return $this->sql->select($query, []) ?: [];
    }

    // NOTE: do NOT use "INSERT ... OUTPUT INSERTED.id" with $this->sql->select() --
    // confirmed 2026-09-06 against the real DB: select() requires the literal word
    // "select" in the query text and rejects this as "query mal formado". Use
    // $sql->insert(), which already returns the new id via PDO::lastInsertId().
    function add(array $data, int $userId): int {
        $query = "
            INSERT INTO TG.dbo.fuel_reception_schedule
                (fecha, hora, supplier_id, terminal_id, station_code, product, mezcla, litros,
                 carrier_id, referencia, notas, estatus, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Programado', ?, GETDATE())
        ";
        return (int)$this->sql->insert($query, [
            $data['fecha'], $data['hora'] ?: null, $data['supplier_id'], $data['terminal_id'],
            $data['station_code'], $data['product'], $data['mezcla'] ?: null, $data['litros'],
            $data['carrier_id'] ?: null, $data['referencia'] ?: null, $data['notas'] ?: null,
            $userId,
        ]);
    }

    function update(int $id, array $data, int $userId): void {
        $query = "
            UPDATE TG.dbo.fuel_reception_schedule
            SET fecha = ?, hora = ?, supplier_id = ?, terminal_id = ?, station_code = ?,
                product = ?, mezcla = ?, litros = ?, carrier_id = ?, referencia = ?, notas = ?,
                estatus = 'Modificado', updated_by = ?, updated_at = GETDATE()
            WHERE id = ? AND estatus <> 'Cancelado'
        ";
        $this->sql->update($query, [
            $data['fecha'], $data['hora'] ?: null, $data['supplier_id'], $data['terminal_id'],
            $data['station_code'], $data['product'], $data['mezcla'] ?: null, $data['litros'],
            $data['carrier_id'] ?: null, $data['referencia'] ?: null, $data['notas'] ?: null,
            $userId, $id,
        ]);
    }

    function cancel(int $id, int $userId): void {
        $query = "
            UPDATE TG.dbo.fuel_reception_schedule
            SET estatus = 'Cancelado', updated_by = ?, updated_at = GETDATE()
            WHERE id = ?
        ";
        $this->sql->update($query, [$userId, $id]);
    }
}
