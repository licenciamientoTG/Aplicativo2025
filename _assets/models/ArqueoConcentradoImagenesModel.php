<?php

/**
 * Imágenes adjuntas por sucursal en el concentrado de una sesión de arqueo.
 * Archivo en disco (_assets/uploads/arqueo_imagenes/YYYY/MM/{id}.{ext}) +
 * metadata en BD. Patrón de PaymentTransactionDocumentsModel.
 * Tabla: [TG].[dbo].[arqueo_concentrado_imagenes]
 */
class ArqueoConcentradoImagenesModel extends Model
{
    const UPLOAD_BASE = '_assets/uploads/arqueo_imagenes/';
    const MAX_SIZE    = 10 * 1024 * 1024; // 10 MB
    const ALLOWED_EXT = ['jpg', 'jpeg', 'png'];

    public function by_sesion_sucursal(int $sesion_id, int $sucursal_id): array
    {
        $query = "
            SELECT i.*, u.Nombre AS created_by_name
            FROM [TG].[dbo].[arqueo_concentrado_imagenes] i
            LEFT JOIN [TG].[dbo].[Usuario] u ON u.Id = i.created_by
            WHERE i.sesion_id = ? AND i.sucursal_id = ?
            ORDER BY i.created_at ASC, i.id ASC;
        ";
        return $this->sql->select($query, [$sesion_id, $sucursal_id]) ?: [];
    }

    /** Conteo de imágenes por sucursal de una sesión: [sucursal_id => n]. */
    public function count_by_sesion(int $sesion_id): array
    {
        $rows = $this->sql->select(
            "SELECT sucursal_id, COUNT(*) AS n
             FROM [TG].[dbo].[arqueo_concentrado_imagenes]
             WHERE sesion_id = ?
             GROUP BY sucursal_id;",
            [$sesion_id]
        ) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['sucursal_id']] = (int) $r['n'];
        }
        return $out;
    }

    public function get_by_id(int $id): ?array
    {
        $rows = $this->sql->select(
            "SELECT * FROM [TG].[dbo].[arqueo_concentrado_imagenes] WHERE id = ?;",
            [$id]
        );
        return $rows ? $rows[0] : null;
    }

    /**
     * Sube una imagen y la registra en BD (insert primero para obtener el id,
     * el archivo se guarda como {id}.{ext}).
     * Retorna ['success', 'imagen_id', 'message'].
     */
    public function upload(int $sesion_id, int $sucursal_id, array $file, ?int $user_id): array
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'message' => 'Error al recibir el archivo.'];
        }

        if ($file['size'] > self::MAX_SIZE) {
            return ['success' => false, 'message' => 'La imagen excede el tamaño máximo de 10 MB.'];
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXT)) {
            return ['success' => false, 'message' => 'Tipo de archivo no permitido. Use: JPG o PNG.'];
        }

        $imagen_id = $this->sql->insert(
            "INSERT INTO [TG].[dbo].[arqueo_concentrado_imagenes]
                (sesion_id, sucursal_id, file_path, file_extension,
                 original_filename, file_size, created_by)
             VALUES (?, ?, '', ?, ?, ?, ?);",
            [$sesion_id, $sucursal_id, $ext, $file['name'], $file['size'], $user_id]
        );

        if (!$imagen_id) {
            return ['success' => false, 'message' => 'Error al registrar la imagen en BD.'];
        }

        $subdir  = self::UPLOAD_BASE . date('Y') . '/' . date('m') . '/';
        $fullDir = __DIR__ . '/../../' . $subdir;
        if (!is_dir($fullDir)) {
            mkdir($fullDir, 0755, true);
        }

        $filename   = $imagen_id . '.' . $ext;
        $fullPath   = $fullDir . $filename;
        $storedPath = $subdir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
            $this->sql->delete(
                "DELETE FROM [TG].[dbo].[arqueo_concentrado_imagenes] WHERE id = ?;",
                [$imagen_id]
            );
            return ['success' => false, 'message' => 'Error al guardar el archivo en disco.'];
        }

        $this->sql->update(
            "UPDATE [TG].[dbo].[arqueo_concentrado_imagenes] SET file_path = ? WHERE id = ?;",
            [$storedPath, $imagen_id]
        );

        return ['success' => true, 'imagen_id' => $imagen_id, 'message' => 'Imagen subida correctamente.'];
    }

    /** Borra el registro y el archivo en disco. */
    public function delete(int $id): bool
    {
        $img = $this->get_by_id($id);
        if (!$img) {
            return false;
        }

        $ok = (bool) $this->sql->delete(
            "DELETE FROM [TG].[dbo].[arqueo_concentrado_imagenes] WHERE id = ?;",
            [$id]
        );

        if ($ok && !empty($img['file_path'])) {
            $fullPath = realpath(__DIR__ . '/../../' . $img['file_path']);
            $base     = realpath(__DIR__ . '/../../' . self::UPLOAD_BASE);
            if ($fullPath && $base && str_starts_with($fullPath, $base) && is_file($fullPath)) {
                @unlink($fullPath);
            }
        }
        return $ok;
    }
}
