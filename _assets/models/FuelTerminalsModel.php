<?php
class FuelTerminalsModel extends Model {

    function get_all(): array {
        $query = "SELECT id, nombre, supplier_id FROM TG.dbo.fuel_terminals WHERE activo = 1 ORDER BY nombre";
        return $this->sql->select($query, []) ?: [];
    }

    function find_by_name(string $nombre): ?array {
        $query = "SELECT id, nombre, supplier_id FROM TG.dbo.fuel_terminals WHERE LOWER(nombre) = LOWER(?) AND activo = 1";
        $rows = $this->sql->select($query, [$nombre]);
        return $rows[0] ?? null;
    }

    // NOTE: do NOT use "INSERT ... OUTPUT INSERTED.id" with $this->sql->select() —
    // confirmed 2026-09-06 by running this exact query: select() (MySqlPdoHandler.class.php:119)
    // requires the query to contain the literal word "select" (via stristr), which
    // "OUTPUT INSERTED.id" does not, so it's rejected as "query mal formado".
    // $sql->insert() already returns the new id via PDO::lastInsertId() (confirmed
    // working) — use that instead.
    function add(string $nombre, ?int $supplierId): int {
        $query = "INSERT INTO TG.dbo.fuel_terminals (nombre, supplier_id, activo) VALUES (?, ?, 1)";
        return (int)$this->sql->insert($query, [$nombre, $supplierId]);
    }
}
