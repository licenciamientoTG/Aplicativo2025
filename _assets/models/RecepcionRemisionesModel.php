<?php
class RecepcionRemisionesModel extends Model
{
    const UPLOAD_BASE = '_assets/uploads/recepcion_remisiones/';
    const MAX_SIZE    = 10 * 1024 * 1024; // 10 MB
    const ALLOWED_EXT = ['pdf', 'jpg', 'jpeg', 'png'];

    // Tipos de documento que se suben por recepción (columna tipo_documento).
    const TIPO_REMISION    = 'remision';
    const TIPO_CARTA_PORTE = 'carta_porte';
    const TIPOS_DOCUMENTO  = [self::TIPO_REMISION, self::TIPO_CARTA_PORTE];

    public function upload(int $nrotrn, int $codgas, int $fchtrn, array $file, int $user_id, string $tipo_documento = self::TIPO_REMISION): array
    {
        if (!in_array($tipo_documento, self::TIPOS_DOCUMENTO, true)) {
            return ['success' => false, 'message' => 'Tipo de documento no válido'];
        }

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
                (nrotrn, codgas, fchtrn, file_path, file_extension, original_filename, file_size, created_by, tipo_documento)
             VALUES (?, ?, ?, '', ?, ?, ?, ?, ?)",
            [$nrotrn, $codgas, $fchtrn, $ext, $file['name'], $file['size'], $user_id, $tipo_documento]
        );

        $etiqueta = $tipo_documento === self::TIPO_CARTA_PORTE ? 'Carta porte' : 'Remisión';

        if (!$doc_id) {
            return ['success' => false, 'message' => 'Error al registrar el documento en BD'];
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
            // Nunca se borra físico: el intento fallido queda como registro
            // eliminado (soft delete) para conservar quién y cuándo lo intentó.
            $this->sql->update(
                "UPDATE [TG].[dbo].[recepcion_remisiones] SET is_deleted = 1, deleted_at = GETDATE(), deleted_by = ? WHERE id = ?",
                [$user_id, $doc_id]
            );
            return ['success' => false, 'message' => 'Error al guardar el archivo en disco'];
        }

        $this->sql->update(
            "UPDATE [TG].[dbo].[recepcion_remisiones] SET file_path = ? WHERE id = ?",
            [$storedPath, $doc_id]
        );

        return ['success' => true, 'doc_id' => $doc_id, 'message' => $etiqueta . ' subida correctamente'];
    }

    public function get_by_recepcion(int $nrotrn, int $codgas, int $fchtrn): array
    {
        $query = "
            SELECT r.id, r.tipo_documento, r.original_filename, r.file_path, r.file_extension, r.file_size, r.created_at, r.created_by, u.Nombre as created_by_name
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

    /**
     * Conteo de documentos activos por recepción del día, separado por tipo.
     * @return array<int, array{remision: int, carta_porte: int}> indexado por nrotrn
     */
    public function get_counts_by_day(int $codgas, int $fchtrn): array
    {
        $query = "
            SELECT nrotrn, tipo_documento, COUNT(*) AS total
            FROM [TG].[dbo].[recepcion_remisiones]
            WHERE codgas = ? AND fchtrn = ? AND is_deleted = 0
            GROUP BY nrotrn, tipo_documento
        ";
        $rows = $this->sql->select($query, [$codgas, $fchtrn]) ?: [];

        $out = [];
        foreach ($rows as $r) {
            $nrotrn = (int)$r['nrotrn'];
            $out[$nrotrn] ??= [self::TIPO_REMISION => 0, self::TIPO_CARTA_PORTE => 0];
            $out[$nrotrn][$r['tipo_documento']] = (int)$r['total'];
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
            return ['success' => false, 'message' => 'El documento no existe, ya fue eliminado o no pertenece a tu estación'];
        }

        $this->sql->update(
            "UPDATE [TG].[dbo].[recepcion_remisiones]
             SET is_deleted = 1, deleted_at = GETDATE(), deleted_by = ?
             WHERE id = ?" . $stationFilter,
            array_merge([$user_id, $id], $codgas !== null ? [$codgas] : [])
        );

        return ['success' => true, 'message' => 'Documento eliminado correctamente'];
    }
}
