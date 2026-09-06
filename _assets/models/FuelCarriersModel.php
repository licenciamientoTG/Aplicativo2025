<?php
class FuelCarriersModel extends Model {

    function get_all(): array {
        $query = "SELECT id, nombre FROM TG.dbo.fuel_carriers WHERE activo = 1 ORDER BY nombre";
        return $this->sql->select($query, []) ?: [];
    }

    function find_by_name(string $nombre): ?array {
        $query = "SELECT id, nombre FROM TG.dbo.fuel_carriers WHERE LOWER(nombre) = LOWER(?) AND activo = 1";
        $rows = $this->sql->select($query, [$nombre]);
        return $rows[0] ?? null;
    }

    // See the note on FuelTerminalsModel::add() above -- same reasoning.
    function add(string $nombre): int {
        $query = "INSERT INTO TG.dbo.fuel_carriers (nombre, activo) VALUES (?, 1)";
        return (int)$this->sql->insert($query, [$nombre]);
    }
}
