<?php
// Carpeta raíz donde el flujo automático de correos (CorreoFactruras.py,
// fuera de este repo) y el import manual de payment.php dejan los
// adjuntos ya procesados -- constante compartida para que cualquier
// módulo PHP que necesite escribir/leer ahí use la misma ruta, en vez de
// duplicarla (antes vivía privada dentro de Payment::BASE_ATTACHMENTS_PATH).
//
// MIGRACION A LA NAS (plan: docs/plan_migracion_facturas_apier_nas.md):
// las facturas pasan de C:\Software\TareasProgramadas\Facturas_proveedores
// (servidor 192.168.0.3) a \\192.168.0.15\FacturasProveedores\combustible\<prov>\...
// Este es el UNICO lugar que sabe resolver una ruta guardada en BD. Para LEER
// siempre se prueba primero la NAS y luego la ruta vieja ("lectura doble"),
// así cada proveedor puede migrar por separado sin romper nada.
// Fase 4 (2026-11-23): las ESCRITURAS (procesadasDir / procesadasXmlDir) van a la
// NAS (combustible\<prov>\...) y en BD se guarda la ruta lógica (rutaParaBd()).
// Si la NAS no responde se escribe en la ruta vieja para no bloquear la captura.
class AttachmentsPath {
    /** Carpeta vieja (servidor 192.168.0.3). Solo respaldo de escritura si la NAS no responde. */
    const BASE = 'C:\Software\TareasProgramadas\Facturas_proveedores\correoFacturas\attachments';

    /** Raíz vieja (servidor de aplicaciones). Termina en "\". */
    const LEGACY_ROOT = 'C:\Software\TareasProgramadas\Facturas_proveedores\\';
    /** Raíz en la NAS. Termina en "\". */
    const NAS_ROOT = '\\\\192.168.0.15\FacturasProveedores\\';

    private static $legacyRoot = self::LEGACY_ROOT;
    private static $nasRoot = self::NAS_ROOT;
    /** Cache por petición: ¿la raíz NAS responde? null = aún no se pregunta. */
    private static $nasDisponible = null;
    /** Cache por petición de baseEscritura(): PDF y XML de una subida van a la misma raíz. */
    private static $baseEscritura = null;

    public static function procesadasDir(string $proveedorCarpeta): string {
        return self::baseEscritura() . '\\' . $proveedorCarpeta . '\\procesadas';
    }

    public static function procesadasXmlDir(string $proveedorCarpeta): string {
        return self::baseEscritura() . '\\' . $proveedorCarpeta . '\\procesadasXml';
    }

    /**
     * Carpeta donde se escriben los archivos nuevos: NAS combustible\ si responde,
     * si no la carpeta vieja (la lectura doble del resolver encuentra ambas).
     */
    public static function baseEscritura(): string {
        if (self::$baseEscritura === null) {
            $nas = self::$nasRoot . 'combustible';
            self::$baseEscritura = (self::nasDisponible() && @is_dir($nas))
                ? $nas
                : rtrim(self::$legacyRoot, '\\') . '\\correoFacturas\\attachments';
        }
        return self::$baseEscritura;
    }

    /** Nombre de archivo a partir de un UUID de CFDI; Exception si no tiene formato de UUID. */
    public static function nombreDesdeUuid(string $uuid, string $extension): string {
        $uuid = trim($uuid);
        if (!preg_match('/^[0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{12}$/i', $uuid)) {
            throw new Exception('UUID inválido para nombrar el archivo: ' . substr($uuid, 0, 60));
        }
        return strtoupper(str_replace('-', '_', $uuid)) . '.' . $extension;
    }

    /**
     * Guarda un archivo subido ($_FILES[..]['tmp_name']) en $destino sin dejar nunca
     * un archivo a medias: primero a "<destino>.tmp", se compara el tamaño y luego se
     * renombra encima (rename reemplaza en Windows). Hacia la NAS move_uploaded_file
     * copia, no renombra, así que un corte a media copia no pisa el archivo bueno.
     */
    public static function guardarSubido(string $tmpSubido, string $destino): void {
        $parcial = $destino . '.tmp';
        $tam = @filesize($tmpSubido);
        if (!move_uploaded_file($tmpSubido, $parcial)) {
            @unlink($parcial);   // copia cortada a media: no dejar basura en la NAS
            throw new Exception('No se pudo copiar el archivo a ' . dirname($destino));
        }
        clearstatcache(true, $parcial);
        if ($tam === false || @filesize($parcial) !== $tam) {
            @unlink($parcial);
            throw new Exception('La copia quedó incompleta en ' . dirname($destino));
        }
        if (!@rename($parcial, $destino)) {
            @unlink($parcial);
            throw new Exception('No se pudo dejar el archivo en ' . dirname($destino));
        }
    }

    /**
     * Ruta que se guarda en BD para un archivo recién escrito: "combustible\<prov>\..."
     * si está en la NAS (independiente del servidor), o la absoluta tal cual si cayó
     * en la carpeta vieja.
     */
    public static function rutaParaBd(string $rutaAbsoluta): string {
        $ruta = self::normalizar($rutaAbsoluta);
        if (stripos($ruta, self::$nasRoot) === 0) {
            return substr($ruta, strlen(self::$nasRoot));
        }
        return $ruta;
    }

    /** Solo para pruebas: cambia las raíces. Sin argumentos las restablece. */
    public static function setRoots(?string $legacy = null, ?string $nas = null): void {
        self::$legacyRoot = self::cerrar($legacy ?? self::LEGACY_ROOT);
        self::$nasRoot = self::cerrar($nas ?? self::NAS_ROOT);
        self::$nasDisponible = null;
        self::$baseEscritura = null;
    }

    /**
     * Se pregunta UNA vez por petición si la NAS responde; si no, se dejan de
     * probar sus candidatas. Evita pagar el timeout SMB en cada archivo (p. ej.
     * el ZIP de 500 facturas) cuando la NAS está caída o sin permisos.
     */
    private static function nasDisponible(): bool {
        if (self::$nasDisponible === null) {
            self::$nasDisponible = @is_dir(self::$nasRoot);
        }
        return self::$nasDisponible;
    }

    /**
     * Normaliza separadores de una ruta guardada en BD: "/" -> "\", colapsa
     * "\\" repetidas pero conserva el "\\" inicial de una ruta UNC.
     */
    public static function normalizar(string $ruta): string {
        $ruta = trim($ruta);
        $ruta = str_replace('/', '\\', $ruta);
        $unc = (substr($ruta, 0, 2) === '\\\\');
        $ruta = preg_replace('/\\\\{2,}/', '\\', $ruta);
        return $unc ? '\\' . $ruta : $ruta;
    }

    /**
     * Rutas candidatas (en orden de preferencia) donde puede estar el archivo
     * al que apunta $rutaDb. No toca el disco.
     *  - combustible\<prov>\...                 -> NAS (y, de respaldo, attachments\ viejo)
     *  - \\servidor\...                         -> tal cual
     *  - attachments\<prov>\...  (relativa vieja) -> NAS combustible\ y luego ruta vieja
     *  - C:\...\correoFacturas\attachments\...  (absoluta vieja) -> tal cual y luego NAS combustible\
     *  - cualquier otra absoluta                -> tal cual
     *  - cualquier otra relativa                -> bajo correoFacturas\ viejo (comportamiento histórico)
     */
    public static function candidatos(string $rutaDb): array {
        $ruta = self::normalizar($rutaDb);
        if ($ruta === '') {
            return [];
        }
        $legacyCorreo = self::$legacyRoot . 'correoFacturas\\';

        if (substr($ruta, 0, 2) === '\\\\') {
            return [$ruta];
        }
        if (preg_match('/^combustible\\\\/i', $ruta)) {
            $resto = substr($ruta, strlen('combustible\\'));
            return [self::$nasRoot . 'combustible\\' . $resto, $legacyCorreo . 'attachments\\' . $resto];
        }
        if (preg_match('/^attachments\\\\/i', $ruta)) {
            $resto = substr($ruta, strlen('attachments\\'));
            return [self::$nasRoot . 'combustible\\' . $resto, $legacyCorreo . $ruta];
        }
        if (preg_match('/^[A-Za-z]:\\\\/', $ruta)) {
            if (preg_match('/\\\\correoFacturas\\\\attachments\\\\(.+)$/i', $ruta, $m)) {
                // La absoluta vieja se prueba PRIMERO: desde la fase 4 solo se guarda una
                // absoluta cuando se escribió en la vieja por NAS caída, y esa es la versión
                // vigente (la de la NAS puede ser una anterior). Si ya no existe, la NAS.
                return [$ruta, self::$nasRoot . 'combustible\\' . $m[1]];
            }
            return [$ruta];
        }
        return [$legacyCorreo . ltrim($ruta, '\\')];
    }

    /** ¿La ruta (ya realpath-eada) está dentro de la NAS o de la raíz vieja? Sin realpath() de carpetas. */
    public static function dentroDeBase(string $real): bool {
        return $real !== ''
            && (stripos($real, self::$legacyRoot) === 0 || stripos($real, self::$nasRoot) === 0);
    }

    /**
     * Devuelve la ruta real del archivo (primera candidata que exista, sea
     * legible y esté dentro de las raíces permitidas) o null.
     *
     * Se valida por prefijo de string sobre el realpath() del ARCHIVO, no del
     * directorio base: en este servidor realpath() de una carpeta puede fallar
     * por permisos NTFS granulares aunque el archivo sí sea legible
     * (ver station_portal.php, 2026-09-14).
     */
    public static function resolver(string $rutaDb): ?string {
        foreach (self::candidatos($rutaDb) as $cand) {
            if (!self::candidataSegura($cand)) {
                continue;
            }
            if (stripos($cand, self::$nasRoot) === 0 && !self::nasDisponible()) {
                continue;
            }
            if (is_file($cand) && self::legible($cand)) {
                // Si realpath() resuelve y sigue dentro de base, se devuelve ese; si la carpeta
                // es un enlace/junction a otra unidad, realpath() la sacaría de base y se usa la
                // candidata (ya validada por prefijo y sin "..").
                $real = @realpath($cand);
                return ($real !== false && self::dentroDeBase($real)) ? $real : $cand;
            }
        }
        return null;
    }

    /**
     * ¿La ruta apunta fuera de las raíces permitidas (o trae "..")? Sirve para
     * distinguir 403 (intento de salirse de la base) de 404 (no existe).
     */
    public static function fueraDeBase(string $rutaDb): bool {
        foreach (self::candidatos($rutaDb) as $cand) {
            if (!self::candidataSegura($cand)) {
                return true;
            }
        }
        return false;
    }

    private static function candidataSegura(string $cand): bool {
        // Solo se rechaza ".." como SEGMENTO (\..\); un nombre como "F-123..pdf" es válido.
        return !preg_match('/(^|\\\\)\.\.(\\\\|$)/', $cand) && self::dentroDeBase($cand);
    }

    /**
     * is_readable() da falso negativo en rutas UNC con la identidad del pool de IIS
     * (IIS APPPOOL\TG_PHP en la NAS, prueba 2026-11-23): se confirma abriendo el archivo.
     */
    private static function legible(string $ruta): bool {
        if (is_readable($ruta)) {
            return true;
        }
        $h = @fopen($ruta, 'rb');
        if ($h === false) {
            return false;
        }
        fclose($h);
        return true;
    }

    private static function cerrar(string $dir): string {
        return rtrim($dir, '\\/') . '\\';
    }
}
