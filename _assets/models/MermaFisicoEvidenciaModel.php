<?php
/**
 * Evidencia (imagen/PDF) que justifica una corrección de corte físico del
 * módulo de merma. Se liga por (codgas, fecha, codprd, turno) — la misma
 * llave que ya identifica una celda "corregida" en merma_fisico_log — no a
 * una corrección puntual, porque una misma celda puede corregirse varias
 * veces y la evidencia es general para esa celda.
 */
class MermaFisicoEvidenciaModel extends Model
{
    const UPLOAD_BASE = '_assets/uploads/merma_evidencia/';
    const MAX_SIZE     = 10 * 1024 * 1024; // 10 MB
    const ALLOWED_EXT  = ['pdf', 'jpg', 'jpeg', 'png'];

    public function get_by_celda(int $codgas, string $fecha, int $codprd, int $turno): array
    {
        return $this->sql->select(
            'SELECT e.*, u.Nombre AS usuario_nombre
             FROM [TG].[dbo].[merma_fisico_evidencia] e
             LEFT JOIN [TG].[dbo].[Usuario] u ON u.Id = e.created_by
             WHERE e.codgas = ? AND e.fecha = ? AND e.codprd = ? AND e.turno = ?
             ORDER BY e.created_at ASC;',
            [$codgas, $fecha, $codprd, $turno]
        ) ?: [];
    }

    /** Set "fecha-codprd-turno" de celdas que ya tienen al menos una evidencia, para el ícono de la tabla. */
    public function get_celdas_con_evidencia(int $codgas, string $desde, string $hasta): array
    {
        $rs = $this->sql->select(
            'SELECT DISTINCT fecha, codprd, turno FROM [TG].[dbo].[merma_fisico_evidencia]
             WHERE codgas = ? AND fecha BETWEEN ? AND ?;',
            [$codgas, $desde, $hasta]
        ) ?: [];
        $set = [];
        foreach ($rs as $r) {
            $set[substr($r['fecha'], 0, 10) . '-' . (int)$r['codprd'] . '-' . (int)$r['turno']] = true;
        }
        return $set;
    }

    public function get_by_id(int $id): ?array
    {
        $r = $this->sql->select('SELECT * FROM [TG].[dbo].[merma_fisico_evidencia] WHERE id = ?;', [$id]);
        return $r ? $r[0] : null;
    }

    /** Sube un archivo y lo registra. Retorna ['success', 'id'?, 'message']. */
    public function upload(int $codgas, string $fecha, int $codprd, int $turno, array $file, int $user_id): array
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
            'INSERT INTO [TG].[dbo].[merma_fisico_evidencia]
                (codgas, fecha, codprd, turno, file_path, file_extension, original_filename, file_size, created_by)
             VALUES (?, ?, ?, ?, \'\', ?, ?, ?, ?);',
            [$codgas, $fecha, $codprd, $turno, $ext, $file['name'], $file['size'], $user_id]
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
            $this->sql->delete('DELETE FROM [TG].[dbo].[merma_fisico_evidencia] WHERE id = ?;', [$id]);
            return ['success' => false, 'message' => 'Error al guardar el archivo en disco'];
        }

        $this->sql->update('UPDATE [TG].[dbo].[merma_fisico_evidencia] SET file_path = ? WHERE id = ?;', [$storedPath, $id]);

        return ['success' => true, 'id' => $id, 'message' => 'Archivo subido correctamente'];
    }
}
