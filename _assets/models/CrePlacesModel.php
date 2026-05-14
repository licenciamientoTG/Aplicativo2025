<?php

class CrePlacesModel extends Model {
    public $place_id;
    public $name;
    public $cre_id;
    public $location_x;
    public $location_y;
    public $created_at;
    public $updated_at;

    public function getAll(): array|false {
        $query = "SELECT place_id, name, cre_id, location_x, location_y, created_at, updated_at
                  FROM [TG].[dbo].[cre_places]
                  ORDER BY name;";
        return $this->sql->select($query) ?: false;
    }

    public function getById(int $place_id): array|false {
        $query = "SELECT place_id, name, cre_id, location_x, location_y, created_at, updated_at
                  FROM [TG].[dbo].[cre_places]
                  WHERE place_id = ?;";
        return $this->sql->select($query, [$place_id]) ?: false;
    }

    public function insert(int $place_id, string $name, string $cre_id, float $location_x, float $location_y): bool {
        $query = "INSERT INTO [TG].[dbo].[cre_places] (place_id, name, cre_id, location_x, location_y)
                  VALUES (?, ?, ?, ?, ?);";
        return (bool) $this->sql->insert($query, [$place_id, $name, $cre_id, $location_x, $location_y]);
    }

    public function upsert(int $place_id, string $name, string $cre_id, float $location_x, float $location_y): bool {
        $query = "MERGE [TG].[dbo].[cre_places] AS target
                  USING (VALUES (?, ?, ?, ?, ?)) AS source (place_id, name, cre_id, location_x, location_y)
                  ON target.place_id = source.place_id
                  WHEN MATCHED THEN
                      UPDATE SET name = source.name, cre_id = source.cre_id,
                                 location_x = source.location_x, location_y = source.location_y,
                                 updated_at = GETDATE()
                  WHEN NOT MATCHED THEN
                      INSERT (place_id, name, cre_id, location_x, location_y)
                      VALUES (source.place_id, source.name, source.cre_id, source.location_x, source.location_y);";
        return (bool) $this->sql->insert($query, [$place_id, $name, $cre_id, $location_x, $location_y]);
    }

    public function upsertBatch(array $places): bool {
        try {
            $this->sql->beginTransaction();
            foreach ($places as $p) {
                if (!$this->upsert($p['place_id'], $p['name'], $p['cre_id'], $p['location_x'], $p['location_y'])) {
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
