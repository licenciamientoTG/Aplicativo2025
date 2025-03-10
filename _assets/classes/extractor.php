<?php
class Extractor {
    public static function extract($archivo,  $destino) {
        $ext  =  pathinfo ($archivo, PATHINFO_EXTENSION );
        switch ($ext) {
            case  'zip' :
                $res  =  self::extractZipArchive ($archivo, $destino);
                break;
            case  'gz' :
                $res  =  self::extractGzipFile ($archivo, $destino);
                break;
            case  'rar' :
                $res  =  self::extractRarArchive ($archivo, $destino);
                break;
        }
        return  $res;
    }


    public static function  extractZipArchive($archivo, $destino) {
        if (!class_exists('ZipArchive')) {
            $GLOBALS['status'] = array('error'=>'Su versión de PHP no admite la funcionalidad de descompresión.');
            return false;
        }
        $zip  = new ZipArchive;

        // Verificar si el archivo es legible.
        if($zip->open($archivo) ===  TRUE) {
            if(is_writeable($destino.'/')) {
                $zip->extractTo($destino);
                $zip->close();
                $GLOBALS['status'] = array('success'=>'Archivos descomprimidos con éxito');
                return true;
            } else {
                $GLOBALS['status'] = array('error'=>'Directory no se puede escribir en el servidor web.');
                return false;
            }
        } else {
            $GLOBALS['status'] = array('error'=>'Imposible leer el archivo .zip.');
            return false;
        }
    }


    public static function  extractGzipFile($archivo, $destino) {
        // Compruebe si zlib está habilitado
        if (!function_exists('gzopen')) {
            $GLOBALS['status'] = array('error'=>'Error: Su PHP no tiene habilitado el soporte de zlib.');
            return false;
        }

        $filename = pathinfo ($archivo, PATHINFO_FILENAME);
        $gzipped = gzopen($archivo, "rb");
        $archivo = fopen($filename, "w");

        while ($string = gzread($gzipped, 4096)) {
            fwrite($file, $string, strlen($string));
        }
        gzclose($gzipped);
        fclose($file );

        // Verificar si el archivo fue extraído.
        if(file_exists($destino.'/'.$filename)) {
            $GLOBALS['status'] = array('success'=>'File descomprimido con éxito.');
            return true;
        } else {
            $GLOBALS['status'] = array('error'=>'Error al descomprimir el archivo.');
            return false;
        }
    }


    public static function extractRarArchive($archivo, $destino) {
        // Compruebe si el servidor web admite descomprimir.
        if (!class_exists('RarArchive')) {
            $GLOBALS['status'] = array('error'=>'Su versión de PHP no admite la funcionalidad de archivo .rar.');
            return false;
        }
        // Comprueba si el archivo es legible.
        if($rar = RarArchive::open($archivo)) {
            // Comprobar si el destino se puede escribir
            if(is_writeable($destino  . '/')) {
                $entries = $rar->getEntries();
                foreach ($entries as $entry ) {
                    $entry->extract($destino);
                }
                $rar->close();
                $GLOBALS['status'] = array('success'=>'Archivo extraído exitosamente.');
                return true;
            } else {
                $GLOBALS['status'] = array ('error'=>'Directorio no se puede escribir en el servidor web.');
                return false;
            }
        } else {
            $GLOBALS['status'] = array('error'=>'Imposible leer el archivo .rar.');
            return false;
        }
    }
}