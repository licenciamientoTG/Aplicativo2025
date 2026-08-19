<?php
/**
 * Documentos (factura/comprobante en PDF, JPG o PNG) adjuntos a un
 * movimiento de tarjeta de crédito. Se abre desde el icono junto al campo
 * Factura en /tesoreria/movimientos_bancos_cheques — varios documentos por
 * movimiento (ej. factura + comprobante de pago aparte).
 * Mismo patrón que MermaFisicoEvidenciaModel: insertar primero (sin path)
 * para obtener el id, mover el archivo a disco usando ese id como nombre,
 * luego actualizar file_path; soft-delete (el archivo nunca se borra de
 * disco, solo se oculta de la lista).
 * Schema: docs/sql/tarjetas_credito_documentos.sql
 */
class TarjetasCreditoDocumentosModel extends Model
{
    const UPLOAD_BASE = '_assets/uploads/tarjetas_credito_documentos/';
    const MAX_SIZE     = 10 * 1024 * 1024; // 10 MB
    const ALLOWED_EXT  = ['pdf', 'jpg', 'jpeg', 'png'];

    public function get_by_movimiento(int $movimientoId): array
    {
        return $this->sql->select(
            'SELECT d.*, u.Nombre AS usuario_nombre
             FROM [TG].[dbo].[tarjetas_credito_documentos] d
             LEFT JOIN [TG].[dbo].[Usuario] u ON u.Id = d.created_by
             WHERE d.movimiento_id = ? AND d.is_deleted = 0
             ORDER BY d.created_at ASC;',
            [$movimientoId]
        ) ?: [];
    }

    /**
     * Conteo de documentos (no eliminados) por movimiento, para el icono de
     * la tabla — un solo query en vez de una consulta por fila.
     */
    public function get_conteos(array $movimientoIds): array
    {
        $movimientoIds = array_values(array_unique(array_filter(array_map('intval', $movimientoIds))));
        if (empty($movimientoIds)) return [];

        $ph = implode(',', array_fill(0, count($movimientoIds), '?'));
        $rows = $this->sql->select(
            "SELECT movimiento_id, COUNT(*) AS n FROM [TG].[dbo].[tarjetas_credito_documentos]
             WHERE movimiento_id IN ($ph) AND is_deleted = 0
             GROUP BY movimiento_id;",
            $movimientoIds
        ) ?: [];

        $out = [];
        foreach ($rows as $r) $out[(int)$r['movimiento_id']] = (int)$r['n'];
        return $out;
    }

    public function get_by_id(int $id): ?array
    {
        $r = $this->sql->select('SELECT * FROM [TG].[dbo].[tarjetas_credito_documentos] WHERE id = ?;', [$id]);
        return $r ? $r[0] : null;
    }

    /** Sube un archivo y lo registra. Retorna ['success', 'id'?, 'message']. */
    public function upload(int $movimientoId, array $file, int $userId): array
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'message' => 'Error al recibir el archivo'];
        }
        if ($file['size'] > self::MAX_SIZE) {
            return ['success' => false, 'message' => 'El archivo excede el tamaño máximo de 10 MB'];
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXT, true)) {
            return ['success' => false, 'message' => 'Tipo de archivo no permitido. Usa: PDF, JPG, PNG'];
        }

        $id = $this->sql->insert(
            'INSERT INTO [TG].[dbo].[tarjetas_credito_documentos]
                (movimiento_id, file_path, file_extension, original_filename, file_size, created_by)
             VALUES (?, \'\', ?, ?, ?, ?);',
            [$movimientoId, $ext, $file['name'], $file['size'], $userId]
        );
        if (!$id) {
            return ['success' => false, 'message' => 'Error al registrar el documento en BD'];
        }

        $subdir  = self::UPLOAD_BASE . date('Y') . '/' . date('m') . '/';
        $fullDir = __DIR__ . '/../../' . $subdir;
        if (!is_dir($fullDir)) mkdir($fullDir, 0755, true);

        $filename   = $id . '.' . $ext;
        $fullPath   = $fullDir . $filename;
        $storedPath = $subdir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
            $this->sql->delete('DELETE FROM [TG].[dbo].[tarjetas_credito_documentos] WHERE id = ?;', [$id]);
            return ['success' => false, 'message' => 'Error al guardar el archivo en disco'];
        }

        $this->sql->update('UPDATE [TG].[dbo].[tarjetas_credito_documentos] SET file_path = ? WHERE id = ?;', [$storedPath, $id]);

        return ['success' => true, 'id' => $id, 'message' => 'Archivo subido correctamente'];
    }

    /** Soft-delete: oculta el archivo de la lista, nunca lo borra de disco. */
    public function soft_delete(int $id, int $userId): bool
    {
        return (bool) $this->sql->update(
            'UPDATE [TG].[dbo].[tarjetas_credito_documentos]
             SET is_deleted = 1, deleted_at = GETDATE(), deleted_by = ?
             WHERE id = ? AND is_deleted = 0;',
            [$userId, $id]
        );
    }
}
