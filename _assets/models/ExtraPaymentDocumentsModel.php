<?php
/**
 * Adjuntos de pagos extraordinarios: soporte (solicitante) y comprobante (Tesorería).
 * Mismo patrón que TarjetasCreditoDocumentosModel: se inserta primero (sin path)
 * para obtener el id, el archivo se guarda en disco con ese id como nombre y se
 * actualiza file_path; soft-delete (el archivo nunca se borra de disco).
 * Los archivos se sirven SOLO por ExtraPayments::doc_view, nunca como estáticos.
 * Schema: docs/sql/pagos_extraordinarios_schema.sql (extra_payment_documents)
 */
class ExtraPaymentDocumentsModel extends Model
{
    const UPLOAD_BASE = '_assets/uploads/pagos_extraordinarios/';
    const MAX_SIZE    = 10 * 1024 * 1024; // 10 MB
    const ALLOWED_EXT = ['pdf', 'jpg', 'jpeg', 'png', 'xml'];
    const KINDS       = ['soporte', 'comprobante'];
    const MAX_ACTIVOS = 20; // por solicitud

    public function get_by_request(int $requestId): array
    {
        return $this->sql->select(
            'SELECT d.*, u.Nombre AS usuario_nombre
             FROM [TG].[dbo].[extra_payment_documents] d
             LEFT JOIN [TG].[dbo].[Usuario] u ON u.Id = d.created_by
             WHERE d.request_id = ? AND d.is_deleted = 0
             ORDER BY d.created_at ASC, d.id ASC;',
            [$requestId]
        ) ?: [];
    }

    /** Adjuntos activos sueltos (no cuenta el XML/PDF de los renglones, que no tienen tope propio). */
    public function count_active(int $requestId): int
    {
        $r = $this->sql->select(
            'SELECT COUNT(*) AS n FROM [TG].[dbo].[extra_payment_documents] d
             WHERE d.request_id = ? AND d.is_deleted = 0
               AND NOT EXISTS (SELECT 1 FROM [TG].[dbo].[extra_payment_lines] l
                               WHERE l.request_id = d.request_id AND d.id IN (l.xml_document_id, l.pdf_document_id));',
            [$requestId]
        );
        return (int) ($r[0]['n'] ?? 0);
    }

    /** ¿El documento es el XML o el PDF de algún renglón? (no se elimina suelto) */
    public function es_de_renglon(int $docId): bool
    {
        return (bool) $this->sql->select(
            'SELECT TOP 1 1 AS x FROM [TG].[dbo].[extra_payment_lines] WHERE xml_document_id = ? OR pdf_document_id = ?;',
            [$docId, $docId]
        );
    }

    /** Ruta absoluta del archivo de un documento, o null si no está en la carpeta de adjuntos. */
    public static function ruta_absoluta(array $doc): ?string
    {
        $raiz = realpath(__DIR__ . '/../../' . self::UPLOAD_BASE);
        $ruta = realpath(__DIR__ . '/../../' . $doc['file_path']);
        if (!$raiz || !$ruta || !is_file($ruta) || strncmp($ruta, $raiz . DIRECTORY_SEPARATOR, strlen($raiz) + 1) !== 0) {
            return null;
        }
        return $ruta;
    }

    /**
     * Lee los datos fiscales de un CFDI (3.3 o 4.0). Devuelve null si el archivo no es un
     * comprobante timbrado. Sin DTD ni entidades (se valida antes con validate_content).
     * @return array{uuid:string,total:string,rfc_emisor:string,nombre_emisor:string,rfc_receptor:string,fecha:string,serie_folio:string,descripcion:string}|null
     */
    public static function leer_cfdi(string $path): ?array
    {
        $xml = (string) file_get_contents($path);
        if ($xml === '' || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            return null;
        }
        $prev = libxml_use_internal_errors(true);
        $dom  = new DOMDocument();
        $ok   = $dom->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$ok) {
            return null;
        }
        $nodo = fn(string $n) => $dom->getElementsByTagNameNS('*', $n)->item(0);
        $attr = fn($el, string $a) => $el ? trim((string) $el->getAttribute($a)) : '';
        $comp = $nodo('Comprobante');
        $tfd  = $nodo('TimbreFiscalDigital');
        if (!$comp || $attr($tfd, 'UUID') === '') {
            return null;
        }
        return [
            'uuid'          => strtoupper($attr($tfd, 'UUID')),
            'total'         => $attr($comp, 'Total'),
            'rfc_emisor'    => strtoupper($attr($nodo('Emisor'), 'Rfc')),
            'nombre_emisor' => $attr($nodo('Emisor'), 'Nombre'),
            'rfc_receptor'  => strtoupper($attr($nodo('Receptor'), 'Rfc')),
            'fecha'         => substr($attr($comp, 'Fecha'), 0, 10),
            'serie_folio'   => trim($attr($comp, 'Serie') . $attr($comp, 'Folio')),
            'descripcion'   => $attr($nodo('Concepto'), 'Descripcion'),
        ];
    }

    public function get_by_id(int $id): ?array
    {
        $r = $this->sql->select('SELECT * FROM [TG].[dbo].[extra_payment_documents] WHERE id = ?;', [$id]);
        return $r ? $r[0] : null;
    }

    /**
     * Valida el contenido real del archivo (no solo la extensión).
     * Devuelve null si es válido o el mensaje de error.
     */
    public static function validate_content(string $path, string $ext): ?string
    {
        $head = (string) file_get_contents($path, false, null, 0, 1024);
        if ($head === '') {
            return 'El archivo está vacío';
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: '';
        $malo = 'El contenido del archivo no corresponde a su extensión';

        switch ($ext) {
            case 'pdf':
                // Firma %PDF- al inicio del archivo (un HTML con "%PDF-" dentro no pasa).
                if (strncmp($head, '%PDF-', 5) !== 0 || $mime !== 'application/pdf') {
                    return $malo;
                }
                return null;
            case 'png':
                return (strncmp($head, "\x89PNG\r\n\x1a\n", 8) === 0 && $mime === 'image/png') ? null : $malo;
            case 'jpg':
            case 'jpeg':
                return (strncmp($head, "\xFF\xD8\xFF", 3) === 0 && $mime === 'image/jpeg') ? null : $malo;
            case 'xml':
                if (!in_array($mime, ['text/xml', 'application/xml', 'text/plain'], true)) {
                    return $malo;
                }
                $xml = (string) file_get_contents($path);
                // Sin DTD ni entidades: evita XXE y "billion laughs". Un CFDI no las usa.
                if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
                    return 'El XML no puede contener DOCTYPE ni entidades';
                }
                $prev = libxml_use_internal_errors(true);
                $dom  = new DOMDocument();
                // Sin LIBXML_NOENT ni LIBXML_DTDLOAD; NONET bloquea acceso a red.
                $ok = $dom->loadXML($xml, LIBXML_NONET);
                libxml_clear_errors();
                libxml_use_internal_errors($prev);
                return ($ok && $dom->documentElement !== null) ? null : 'El XML no es válido';
        }
        return 'Tipo de archivo no permitido';
    }

    /** Sube un archivo y lo registra. Retorna ['success', 'id'?, 'message']. */
    public function upload(int $requestId, string $kind, array $file, int $userId): array
    {
        if (!in_array($kind, self::KINDS, true)) {
            return ['success' => false, 'message' => 'Tipo de documento inválido'];
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['success' => false, 'message' => 'Error al recibir el archivo'];
        }
        if ($file['size'] > self::MAX_SIZE) {
            return ['success' => false, 'message' => 'El archivo excede el tamaño máximo de 10 MB'];
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXT, true)) {
            return ['success' => false, 'message' => 'Tipo de archivo no permitido. Usa: PDF, JPG, PNG, XML'];
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            return ['success' => false, 'message' => 'Error al recibir el archivo'];
        }
        $err = self::validate_content($file['tmp_name'], $ext);
        if ($err !== null) {
            return ['success' => false, 'message' => $err];
        }

        $nombre = mb_substr(basename(str_replace('\\', '/', (string) $file['name'])), 0, 255);
        $id = $this->sql->insert(
            'INSERT INTO [TG].[dbo].[extra_payment_documents]
                (request_id, kind, file_path, file_extension, original_filename, file_size, created_by)
             VALUES (?, ?, \'\', ?, ?, ?, ?);',
            [$requestId, $kind, $ext, $nombre, (int) $file['size'], $userId]
        );
        if (!$id) {
            return ['success' => false, 'message' => 'Error al registrar el documento en BD'];
        }

        $subdir  = self::UPLOAD_BASE . date('Y') . '/' . date('m') . '/';
        $fullDir = __DIR__ . '/../../' . $subdir;
        if (!is_dir($fullDir)) {
            mkdir($fullDir, 0755, true);
        }

        $filename   = $id . '.' . $ext;
        $storedPath = $subdir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $fullDir . $filename)) {
            $this->sql->delete('DELETE FROM [TG].[dbo].[extra_payment_documents] WHERE id = ?;', [$id]);
            return ['success' => false, 'message' => 'Error al guardar el archivo en disco'];
        }

        $this->sql->update('UPDATE [TG].[dbo].[extra_payment_documents] SET file_path = ? WHERE id = ?;', [$storedPath, $id]);

        return ['success' => true, 'id' => $id, 'message' => 'Archivo subido correctamente'];
    }

    /** Soft-delete: oculta el archivo de la lista, nunca lo borra de disco. */
    public function soft_delete(int $id, int $userId): bool
    {
        return (bool) $this->sql->update(
            'UPDATE [TG].[dbo].[extra_payment_documents]
             SET is_deleted = 1, deleted_at = GETDATE(), deleted_by = ?
             WHERE id = ? AND is_deleted = 0;',
            [$userId, $id]
        );
    }
}
