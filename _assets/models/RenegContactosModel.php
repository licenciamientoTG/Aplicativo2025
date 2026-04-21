<?php
class RenegContactosModel {
    public $sql;
    function __construct() {
        $this->sql = MySqlPdoHandler::getInstance();
        $this->sql->connect('TG');
    }

    public function get_all(): array|false {
        $query = "SELECT id, responsable, correo, activo
                  FROM [TG].[dbo].[reneg_contactos]
                  ORDER BY responsable";
        return $this->sql->select($query) ?: false;
    }

    public function get_by_responsable(string $responsable): array|false {
        $query = "SELECT id, responsable, correo, activo
                  FROM [TG].[dbo].[reneg_contactos]
                  WHERE responsable = ?";
        $result = $this->sql->select($query, [$responsable]);
        return $result ? $result[0] : false;
    }

    public function upsert(string $responsable, string $correo): bool {
        $existing = $this->get_by_responsable($responsable);
        if ($existing) {
            $query = "UPDATE [TG].[dbo].[reneg_contactos]
                      SET correo = ?, activo = 1
                      WHERE responsable = ?";
            $this->sql->update($query, [$correo, $responsable]);
        } else {
            $query = "INSERT INTO [TG].[dbo].[reneg_contactos] (responsable, correo)
                      VALUES (?, ?)";
            $this->sql->insert($query, [$responsable, $correo]);
        }
        return true;
    }

    public function delete(int $id): bool {
        $query = "DELETE FROM [TG].[dbo].[reneg_contactos] WHERE id = ?";
        $this->sql->delete($query, [$id]);
        return true;
    }

    public function toggle_activo(int $id, int $activo): bool {
        $query = "UPDATE [TG].[dbo].[reneg_contactos] SET activo = ? WHERE id = ?";
        $this->sql->update($query, [$activo, $id]);
        return true;
    }
}
