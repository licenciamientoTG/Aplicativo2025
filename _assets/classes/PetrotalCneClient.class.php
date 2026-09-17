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
}
