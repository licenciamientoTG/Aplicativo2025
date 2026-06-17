<?php
class PaymentTransactionDocumentsModel extends Model
{
    const UPLOAD_BASE = '_assets/uploads/payment_documents/';
    const MAX_SIZE    = 10 * 1024 * 1024; // 10 MB
    const ALLOWED_EXT = ['pdf', 'jpg', 'jpeg', 'png'];

    public function get_by_transaction(int $transaction_id): array
    {
        $query = "
            SELECT d.*, u.Nombre as created_by_name
            FROM [TG].[dbo].[payment_transaction_documents] d
            LEFT JOIN [TG].[dbo].[Usuario] u ON u.Id = d.created_by
            WHERE d.transaction_id = ?
            ORDER BY d.created_at ASC
        ";
        return $this->sql->select($query, [$transaction_id]) ?: [];
    }

    public function get_by_id(int $id): ?array
    {
        $result = $this->sql->select(
            "SELECT * FROM [TG].[dbo].[payment_transaction_documents] WHERE id = ?",
            [$id]
        );
        return $result ? $result[0] : null;
    }

    /**
     * Sube un archivo y lo registra en BD.
     * Retorna ['success', 'doc_id', 'message'].
     */
    /**
     * Sube un archivo y lo registra. Puede ligarse a una transacción
     * ($transaction_id) o a un lote de pago ($batch_id). Para conciliación se
     * usa $batch_id (un comprobante = un lote = N facturas) y $transaction_id
     * va null.
     */
    public function upload(?int $transaction_id, array $file, int $user_id, ?int $batch_id = null): array
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

        // Insertar primero para obtener el ID
        $doc_id = $this->sql->insert(
            "INSERT INTO [TG].[dbo].[payment_transaction_documents]
                (transaction_id, batch_id, file_path, file_extension, original_filename, file_size, created_by)
             VALUES (?, ?, '', ?, ?, ?, ?)",
            [$transaction_id, $batch_id, $ext, $file['name'], $file['size'], $user_id]
        );

        if (!$doc_id) {
            return ['success' => false, 'message' => 'Error al registrar el documento en BD'];
        }

        // Crear carpeta YYYY/MM/
        $subdir = self::UPLOAD_BASE . date('Y') . '/' . date('m') . '/';
        $fullDir = __DIR__ . '/../../' . $subdir;
        if (!is_dir($fullDir)) {
            mkdir($fullDir, 0755, true);
        }

        $filename  = $doc_id . '.' . $ext;
        $fullPath  = $fullDir . $filename;
        $storedPath = $subdir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
            $this->sql->delete("DELETE FROM [TG].[dbo].[payment_transaction_documents] WHERE id = ?", [$doc_id]);
            return ['success' => false, 'message' => 'Error al guardar el archivo en disco'];
        }

        $this->sql->update(
            "UPDATE [TG].[dbo].[payment_transaction_documents] SET file_path = ? WHERE id = ?",
            [$storedPath, $doc_id]
        );

        return ['success' => true, 'doc_id' => $doc_id, 'message' => 'Archivo subido correctamente'];
    }
}
