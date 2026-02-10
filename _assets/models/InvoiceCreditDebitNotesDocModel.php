<?php
class InvoiceCreditDebitNotesDocModel extends Model
{
    public $id;
    public $credit_note_id;
    public $file_extension;
    public $file_path;
    public $created_at;
    public $created_by;

    /**
     * Crear registro del documento (sin ruta todavía)
     */
    public function createDocumentRecord($data) : int|false {
        $query = "
            INSERT INTO [tg].[dbo].invoice_credit_debit_notes_doc 
                (credit_note_id, file_extension, created_by)
            VALUES (?, ?, ?)";

        $params = [
            $data['credit_note_id'],
            $data['file_extension'],
            $data['created_by']
        ];

        $result = $this->sql->insert($query, $params);
        return $result;
    }

    /**
     * Actualizar ruta del archivo después de subirlo
     */
    public function updateFilePath($docId, $filePath) : bool {
        $query = "
            UPDATE [tg].[dbo].invoice_credit_debit_notes_doc 
            SET file_path = ? 
            WHERE id = ?";
        
        return $this->sql->update($query, [$filePath, $docId]);
    }

    /**
     * Obtener documentos de una nota
     */
    public function getDocumentsByNoteId($noteId) : array|false {
        $query = "
            SELECT 
                t1.*,
                t2.Nombre as created_by_name
            FROM [tg].[dbo].invoice_credit_debit_notes_doc t1
            LEFT JOIN [TG].[dbo].[Usuario] t2 ON t1.created_by = t2.Id
            WHERE t1.credit_note_id = ?
            ORDER BY t1.created_at DESC";
        
        return $this->sql->select($query, [$noteId]);
    }

    /**
     * Obtener un documento por ID
     */
    public function getDocumentById($docId) : array|false {
        $query = "
            SELECT * 
            FROM [tg].[dbo].invoice_credit_debit_notes_doc 
            WHERE id = ?";
        
        $result = $this->sql->select($query, [$docId]);
        return $result ? $result[0] : false;
    }

    /**
     * Eliminar documento (físico y registro)
     */
    public function deleteDocument($docId) : bool {
        // Primero obtener la ruta del archivo para eliminarlo físicamente
        $doc = $this->getDocumentById($docId);
        
        if ($doc && !empty($doc['file_path'])) {
            $fullPath = __DIR__ . '/../' . $doc['file_path'];
            if (file_exists($fullPath)) {
                unlink($fullPath);
            }
        }

        // Eliminar registro de BD
        $query = "
            DELETE FROM [tg].[dbo].invoice_credit_debit_notes_doc 
            WHERE id = ?";
        
        return $this->sql->delete($query, [$docId]);
    }
}