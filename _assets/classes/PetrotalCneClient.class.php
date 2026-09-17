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
