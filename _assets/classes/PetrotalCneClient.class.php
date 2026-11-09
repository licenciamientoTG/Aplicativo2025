<?php
class PetrotalCneClient {

    const API_BASE_URL = 'https://api-masivos-qa.cne.gob.mx';
    const ENDPOINT_REPORTE = self::API_BASE_URL . '/ReporteObligacion';

    // Arma el JSON con la forma Permiso->Fecha->Producto->VentasNacional/
    // ComprasNacional->PermisionarioCRECliente/PermisionarioCREProveedor,
    // agrupando las filas planas del modelo (una por factura/concepto) en
    // la jerarquía que pide el XSD. Todas las contrapartes vistas hasta
    // ahora son Permisionario CRE (nunca UsuarioFinal), así que solo se
    // construye ese nodo.
    public static function armar_json(string $numeroPermiso, string $desde, string $hasta, array $ventas, array $compras): string {
        $porFecha = [];

        foreach ($ventas as $fila) {
            $porFecha[$fila['fecha']]['productos'][self::claveProducto($fila)]['ventas'][] = [
                'NumeroPermisoCRECliente' => $fila['permiso_cre'],
                'PrecioVenta' => $fila['precio'],
                'VolumenVendido' => $fila['volumen_bbl'],
            ];
            $porFecha[$fila['fecha']]['productos'][self::claveProducto($fila)]['producto_id'] = $fila['producto_id'];
            $porFecha[$fila['fecha']]['productos'][self::claveProducto($fila)]['subproducto_id'] = $fila['subproducto_id'];
        }

        foreach ($compras as $fila) {
            $porFecha[$fila['fecha']]['productos'][self::claveProducto($fila)]['compras'][] = [
                'NumeroPermisoCREProveedor' => $fila['permiso_cre'],
                'PrecioCompra' => $fila['precio'],
                'VolumenComprado' => $fila['volumen_bbl'],
            ];
            $porFecha[$fila['fecha']]['productos'][self::claveProducto($fila)]['producto_id'] = $fila['producto_id'];
            $porFecha[$fila['fecha']]['productos'][self::claveProducto($fila)]['subproducto_id'] = $fila['subproducto_id'];
        }

        ksort($porFecha);

        $fechasJson = [];
        foreach ($porFecha as $fecha => $datos) {
            $productosJson = [];
            foreach ($datos['productos'] as $producto) {
                $nodoProducto = [
                    'ProductoId' => $producto['producto_id'],
                    'SubProductoId' => $producto['subproducto_id'],
                ];
                if (!empty($producto['ventas'])) {
                    $nodoProducto['VentasNacional'] = [
                        ['PermisionarioCRECliente' => $producto['ventas']],
                    ];
                }
                if (!empty($producto['compras'])) {
                    $nodoProducto['ComprasNacional'] = [
                        ['PermisionarioCREProveedor' => $producto['compras']],
                    ];
                }
                $productosJson[] = $nodoProducto;
            }
            $fechasJson[] = [
                'Diaareportar' => $fecha,
                'Producto' => $productosJson,
            ];
        }

        $estructura = [
            'Permiso' => [
                'Numero' => $numeroPermiso,
                'FechaInicio' => $desde,
                'FechaFin' => $hasta,
                'TipoReporte' => 1,
                'Fecha' => $fechasJson,
            ],
        ];

        return json_encode($estructura, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    private static function claveProducto(array $fila): string {
        return $fila['producto_id'] . '_' . $fila['subproducto_id'];
    }

    // Envía el reporte a la API de la CNE vía multipart/form-data, siguiendo
    // exactamente el ejemplo curl del documento técnico "Web Service-COM":
    // reporte (json), cerFile, keyFile, password, permiso. No lanza
    // excepción ante fallas de red/HTTP — las reporta en 'error_conexion'
    // para que el caller decida cómo mostrarlas (el ambiente QA de la CNE
    // es intermitente, ver spec).
    public static function enviar(string $jsonPath, string $cerPath, string $keyPath, string $password, string $numeroPermiso): array {
        $resultado = [
            'ok' => false, 'rc' => null, 'msg' => '', 'acuse' => null,
            'link_descarga' => null, 'error_conexion' => null,
        ];

        if (!is_readable($jsonPath) || !is_readable($cerPath) || !is_readable($keyPath)) {
            $resultado['error_conexion'] = 'Uno o más archivos requeridos (JSON, .cer, .key) no son legibles.';
            return $resultado;
        }

        $ch = curl_init(self::ENDPOINT_REPORTE);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['accept: */*']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, [
            'reporte' => new CURLFile($jsonPath, 'application/json', basename($jsonPath)),
            'cerFile' => new CURLFile($cerPath, 'application/x-x509-ca-cert', basename($cerPath)),
            'keyFile' => new CURLFile($keyPath, 'application/octet-stream', basename($keyPath)),
            'password' => $password,
            'permiso' => $numeroPermiso,
        ]);

        $respuesta = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($respuesta === false || $curlError) {
            $resultado['error_conexion'] = "Error de conexión: {$curlError}";
            return $resultado;
        }

        if ($httpCode >= 500) {
            $resultado['error_conexion'] = "El servidor de la CNE respondió con error {$httpCode} (ambiente probablemente no disponible en este momento).";
            return $resultado;
        }

        $decoded = json_decode($respuesta, true);
        if ($decoded === null) {
            $resultado['error_conexion'] = "Respuesta no es JSON válido (HTTP {$httpCode}): " . substr($respuesta, 0, 500);
            return $resultado;
        }

        $resultado['rc'] = $decoded['rc'] ?? null;
        $resultado['msg'] = $decoded['msg'] ?? '';
        $resultado['acuse'] = $decoded['acuse'] ?? null;
        $resultado['link_descarga'] = $decoded['linkDescarga'] ?? null;
        $resultado['ok'] = ($resultado['rc'] === 0);

        return $resultado;
    }

    // ------------------------------------------------------------------
    // ApiReportes (api-reportes[-qa|-pre].cne.gob.mx)
    // A diferencia de api-masivos, aquí la firma la hacemos nosotros: la
    // llave privada (.key) NUNCA sale del servidor, solo se envía el .cer.
    // ------------------------------------------------------------------
    const URLS_API_REPORTES = [
        'qa'   => 'https://api-reportes-qa.cne.gob.mx',
        'pre'  => 'https://api-reportes-pre.cne.gob.mx',
        'prod' => 'https://api-reportes.cne.gob.mx',
    ];

    // Cadena original según el documento ApiReportes:
    // ||TipoReporte={obligacionId}|VersionReporte={version}|HashDocumento={b64(sha256)}
    // |NombreArchivo={nombre}|Periodo={dd/MM/yyyy al dd/MM/yyyy}|NumeroPermiso={permiso}
    // |FechaCDMX={dd/MM/yyyy}||
    public static function cadena_original(int $obligacionId, int $version, string $jsonPath, string $desde, string $hasta, string $numeroPermiso, ?DateTime $ahora = null): string {
        $hash = base64_encode(hash_file('sha256', $jsonPath, true));
        $ahora = $ahora ?? new DateTime('now', new DateTimeZone('America/Mexico_City'));
        $fechaCdmx = (clone $ahora)->setTimezone(new DateTimeZone('America/Mexico_City'))->format('d/m/Y');
        return '||TipoReporte=' . $obligacionId
            . '|VersionReporte=' . $version
            . '|HashDocumento=' . $hash
            . '|NombreArchivo=' . basename($jsonPath)
            . '|Periodo=' . self::periodo_cne($desde, $hasta)
            . '|NumeroPermiso=' . $numeroPermiso
            . '|FechaCDMX=' . $fechaCdmx . '||';
    }

    public static function periodo_cne(string $desde, string $hasta): string {
        return (new DateTime($desde))->format('d/m/Y') . ' al ' . (new DateTime($hasta))->format('d/m/Y');
    }

    // Firma RSA-SHA256 de la cadena con el .key de la e.firma. El .key del
    // SAT viene en DER (PKCS#8 cifrado); openssl_* de PHP necesita PEM, así
    // que si el archivo no empieza con "-----" se envuelve como PEM.
    public static function firmar(string $cadena, string $keyPath, string $password): array {
        $resultado = ['ok' => false, 'sello' => null, 'error' => null];
        $contenido = is_readable($keyPath) ? file_get_contents($keyPath) : false;
        if ($contenido === false || $contenido === '') {
            $resultado['error'] = 'No se puede leer el archivo .key.';
            return $resultado;
        }
        if (strpos($contenido, '-----') !== 0) {
            $contenido = "-----BEGIN ENCRYPTED PRIVATE KEY-----\n"
                . chunk_split(base64_encode($contenido), 64, "\n")
                . "-----END ENCRYPTED PRIVATE KEY-----\n";
        }
        while (openssl_error_string() !== false) {} // limpia errores previos
        $llave = openssl_pkey_get_private($contenido, $password);
        if (!$llave) {
            $resultado['error'] = 'No se pudo abrir la llave privada (password incorrecto o formato no soportado): ' . openssl_error_string();
            return $resultado;
        }
        if (!openssl_sign($cadena, $firma, $llave, OPENSSL_ALGO_SHA256)) {
            $resultado['error'] = 'Falló la firma: ' . openssl_error_string();
            return $resultado;
        }
        $resultado['ok'] = true;
        $resultado['sello'] = base64_encode($firma);
        return $resultado;
    }

    // POST /api/reportes/PresentarReporte. Devuelve la misma forma que
    // enviar() (ok, rc, msg, acuse, link_descarga, error_conexion) más
    // 'solicitud_id', para que el controlador use el mismo manejo.
    public static function presentar_reporte(string $ambiente, string $apiKey, int $obligacionId, string $jsonPath, string $cerPath, string $keyPath, string $password, string $numeroPermiso, string $desde, string $hasta): array {
        $resultado = [
            'ok' => false, 'rc' => null, 'msg' => '', 'acuse' => null,
            'link_descarga' => null, 'solicitud_id' => null, 'error_conexion' => null,
        ];
        $base = self::URLS_API_REPORTES[$ambiente] ?? null;
        if ($base === null) {
            $resultado['error_conexion'] = "Ambiente desconocido: {$ambiente}.";
            return $resultado;
        }
        if (!is_readable($jsonPath) || !is_readable($cerPath)) {
            $resultado['error_conexion'] = 'El JSON o el .cer no son legibles.';
            return $resultado;
        }

        $version = 1;
        $cadena = self::cadena_original($obligacionId, $version, $jsonPath, $desde, $hasta, $numeroPermiso);
        $firma = self::firmar($cadena, $keyPath, $password);
        if (!$firma['ok']) {
            $resultado['error_conexion'] = $firma['error'];
            return $resultado;
        }

        $ch = curl_init($base . '/api/reportes/PresentarReporte');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['accept: */*', 'x-api-key: ' . $apiKey]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, [
            'reporte' => new CURLFile($jsonPath, 'application/json', basename($jsonPath)),
            'efirma_cerFile' => new CURLFile($cerPath, 'application/x-x509-ca-cert', basename($cerPath)),
            'obligacionId' => $obligacionId,
            'version' => $version,
            'selloDigitalBase64' => $firma['sello'],
            'numeroPermiso' => $numeroPermiso,
            'periodo' => self::periodo_cne($desde, $hasta),
        ]);
        $respuesta = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($respuesta === false || $curlError) {
            $resultado['error_conexion'] = "Error de conexión: {$curlError}";
            return $resultado;
        }

        $decoded = json_decode($respuesta, true);
        if (!is_array($decoded)) {
            $resultado['error_conexion'] = "Respuesta no es JSON válido (HTTP {$httpCode}): " . substr($respuesta, 0, 500);
            return $resultado;
        }

        // Errores de validación (HTTP 400, problem details): se muestran tal cual.
        if (isset($decoded['errors']) && is_array($decoded['errors'])) {
            $partes = [];
            foreach ($decoded['errors'] as $campo => $mensajes) {
                $partes[] = $campo . ': ' . implode(' ', (array) $mensajes);
            }
            $resultado['msg'] = 'La CNE rechazó la petición: ' . implode(' | ', $partes);
            return $resultado;
        }
        // Nota: la API responde HTTP 500 con {"rc":-1,"msg":...} para errores
        // de negocio, así que el cuerpo manda, no el código HTTP.
        if (!array_key_exists('rc', $decoded)) {
            $resultado['error_conexion'] = "Respuesta inesperada de la CNE (HTTP {$httpCode}): " . substr($respuesta, 0, 500);
            return $resultado;
        }

        $resultado['rc'] = $decoded['rc'];
        $resultado['msg'] = $decoded['msg'] ?? '';
        $resultado['acuse'] = $decoded['acuse'] ?? null;
        $resultado['link_descarga'] = $decoded['acuse']['qrlink'] ?? null;
        $resultado['solicitud_id'] = $decoded['acuse']['solicitudId'] ?? null;
        $resultado['ok'] = ($decoded['rc'] === 0);
        return $resultado;
    }

    public static function descargar_acuse(string $linkDescarga, string $destinoPath): bool {
        $ch = curl_init($linkDescarga);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $contenido = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($contenido === false || $httpCode !== 200) {
            return false;
        }

        return file_put_contents($destinoPath, $contenido) !== false;
    }
}
