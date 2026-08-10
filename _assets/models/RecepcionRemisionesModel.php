<?php
class RecepcionRemisionesModel extends Model
{
    const UPLOAD_BASE = '_assets/uploads/recepcion_remisiones/';
    const MAX_SIZE    = 10 * 1024 * 1024; // 10 MB
    const ALLOWED_EXT = ['pdf', 'jpg', 'jpeg', 'png'];

    public function upload(int $nrotrn, int $codgas, int $fchtrn, array $file, int $user_id): array
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'message' => 'Error al recibir el archivo'];
        }

        if ($file['size'] > self::MAX_SIZE) {
            return ['success' => false, 'message' => 'El archivo excede el tamaño máximo de 10 MB'];
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXT)) {
            return ['success' => false, 'message' => 'Tipo de archivo no permitido. Use: PDF, JPG, PNG'];
        }

        $doc_id = $this->sql->insert(
            "INSERT INTO [TG].[dbo].[recepcion_remisiones]
                (nrotrn, codgas, fchtrn, file_path, file_extension, original_filename, file_size, created_by)
             VALUES (?, ?, ?, '', ?, ?, ?, ?)",
            [$nrotrn, $codgas, $fchtrn, $ext, $file['name'], $file['size'], $user_id]
        );

        if (!$doc_id) {
            return ['success' => false, 'message' => 'Error al registrar la remisión en BD'];
        }

        $subdir = self::UPLOAD_BASE . date('Y') . '/' . date('m') . '/';
        $fullDir = __DIR__ . '/../../' . $subdir;
        if (!is_dir($fullDir)) {
            mkdir($fullDir, 0755, true);
        }

        $filename   = $doc_id . '.' . $ext;
        $fullPath   = $fullDir . $filename;
        $storedPath = $subdir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
            $this->sql->delete("DELETE FROM [TG].[dbo].[recepcion_remisiones] WHERE id = ?", [$doc_id]);
            return ['success' => false, 'message' => 'Error al guardar el archivo en disco'];
        }

        $this->sql->update(
            "UPDATE [TG].[dbo].[recepcion_remisiones] SET file_path = ? WHERE id = ?",
            [$storedPath, $doc_id]
        );

        return ['success' => true, 'doc_id' => $doc_id, 'message' => 'Remisión subida correctamente'];
    }

    public function get_by_recepcion(int $nrotrn, int $codgas, int $fchtrn): array
    {
        $query = "
            SELECT r.id, r.original_filename, r.file_path, r.file_extension, r.file_size, r.created_at, r.created_by, u.Nombre as created_by_name
            FROM [TG].[dbo].[recepcion_remisiones] r
            LEFT JOIN [TG].[dbo].[Usuario] u ON u.Id = r.created_by
            WHERE r.nrotrn = ? AND r.codgas = ? AND r.fchtrn = ? AND r.is_deleted = 0
            ORDER BY r.created_at ASC
        ";
        return $this->sql->select($query, [$nrotrn, $codgas, $fchtrn]) ?: [];
    }

    /**
     * Trae una fila completa de recepcion_remisiones por id, solo si sigue activa
     * (is_deleted = 0). Usado para servir el archivo con control de acceso.
     */
    public function get_by_id(int $id): ?array
    {
        $query = "
            SELECT id, nrotrn, codgas, fchtrn, file_path, file_extension, original_filename, file_size, created_by, created_at
            FROM [TG].[dbo].[recepcion_remisiones]
            WHERE id = ? AND is_deleted = 0
        ";
        $rows = $this->sql->select($query, [$id]);
        return $rows ? $rows[0] : null;
    }

    public function get_counts_by_day(int $codgas, int $fchtrn): array
    {
        $query = "
            SELECT nrotrn, COUNT(*) AS total
            FROM [TG].[dbo].[recepcion_remisiones]
            WHERE codgas = ? AND fchtrn = ? AND is_deleted = 0
            GROUP BY nrotrn
        ";
        $rows = $this->sql->select($query, [$codgas, $fchtrn]) ?: [];

        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r['nrotrn']] = (int)$r['total'];
        }
        return $out;
    }

    /**
     * Soft-delete de una remisión. Si $codgas no es null, se restringe la
     * operación a remisiones de esa estación (usuario sin permiso de "todas
     * las estaciones"); si es null, no se restringe por estación (usuario con
     * permiso de "todas las estaciones").
     */
    public function soft_delete(int $id, int $user_id, ?int $codgas): array
    {
        $params = [$id];
        $stationFilter = '';
        if ($codgas !== null) {
            $stationFilter = ' AND codgas = ?';
            $params[] = $codgas;
        }

        $existing = $this->sql->select(
            "SELECT id FROM [TG].[dbo].[recepcion_remisiones] WHERE id = ? AND is_deleted = 0" . $stationFilter,
            $params
        );

        if (!$existing) {
            return ['success' => false, 'message' => 'La remisión no existe, ya fue eliminada o no pertenece a tu estación'];
        }

        $this->sql->update(
            "UPDATE [TG].[dbo].[recepcion_remisiones]
             SET is_deleted = 1, deleted_at = GETDATE(), deleted_by = ?
             WHERE id = ?" . $stationFilter,
            array_merge([$user_id, $id], $codgas !== null ? [$codgas] : [])
        );

        return ['success' => true, 'message' => 'Remisión eliminada correctamente'];
    }
}
