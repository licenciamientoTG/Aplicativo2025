<?php
// Carpeta raíz donde el flujo automático de correos (CorreoFactruras.py,
// fuera de este repo) y el import manual de payment.php dejan los
// adjuntos ya procesados -- constante compartida para que cualquier
// módulo PHP que necesite escribir/leer ahí use la misma ruta, en vez de
// duplicarla (antes vivía privada dentro de Payment::BASE_ATTACHMENTS_PATH).
class AttachmentsPath {
    const BASE = 'C:\Software\TareasProgramadas\Facturas_proveedores\correoFacturas\attachments';

    public static function procesadasDir(string $proveedorCarpeta): string {
        return self::BASE . '\\' . $proveedorCarpeta . '\\procesadas';
    }
}
