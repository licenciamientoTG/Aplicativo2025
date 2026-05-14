<?php

class CrePricesModel extends Model {
    public $id;
    public $place_id;
    public $regular;
    public $premium;
    public $diesel;
    public $fetched_at;

    public function getLatest(): array|false {
        $query = "SELECT p.place_id, p.name, p.cre_id, p.location_x, p.location_y,
                         pr.regular, pr.premium, pr.diesel, pr.fetched_at
                  FROM [TG].[dbo].[cre_prices] pr
                  INNER JOIN [TG].[dbo].[cre_places] p ON pr.place_id = p.place_id
                  WHERE pr.fetched_at = (
                      SELECT MAX(fetched_at) FROM [TG].[dbo].[cre_prices]
                  )
                  ORDER BY p.name;";
        return $this->sql->select($query) ?: false;
    }

    public function getByPlace(int $place_id): array|false {
        $query = "SELECT id, place_id, regular, premium, diesel, fetched_at
                  FROM [TG].[dbo].[cre_prices]
                  WHERE place_id = ?
                  ORDER BY fetched_at DESC;";
        return $this->sql->select($query, [$place_id]) ?: false;
    }

    public function getByDateRange(string $from, string $until): array|false {
        $query = "SELECT p.place_id, p.name, p.cre_id,
                         pr.regular, pr.premium, pr.diesel, pr.fetched_at
                  FROM [TG].[dbo].[cre_prices] pr
                  INNER JOIN [TG].[dbo].[cre_places] p ON pr.place_id = p.place_id
                  WHERE pr.fetched_at >= ? AND pr.fetched_at <= ?
                  ORDER BY pr.fetched_at DESC, p.name;";
        return $this->sql->select($query, [$from, $until]) ?: false;
    }

    public function insert(int $place_id, ?float $regular, ?float $premium, ?float $diesel, string $fetched_at): bool {
        $query = "INSERT INTO [TG].[dbo].[cre_prices] (place_id, regular, premium, diesel, fetched_at)
                  VALUES (?, ?, ?, ?, ?);";
        return (bool) $this->sql->insert($query, [$place_id, $regular, $premium, $diesel, $fetched_at]);
    }

    public function insertBatch(array $prices, string $fetched_at): bool {
        try {
            $this->sql->beginTransaction();
            foreach ($prices as $p) {
                if (!$this->insert(
                    $p['place_id'],
                    $p['regular'] ?? null,
                    $p['premium'] ?? null,
                    $p['diesel'] ?? null,
                    $fetched_at
                )) {
                    $this->sql->rollBack();
                    return false;
                }
            }
            $this->sql->commit();
            return true;
        } catch (Exception $e) {
            $this->sql->rollBack();
            return false;
        }
    }
}
