<?php
class PetrotalObligacionModel extends Model {

    const LITROS_POR_BARRIL = 158.987;

    // Clasificación por descripción del concepto de factura, validada contra
    // los 4 acuses de agosto 2026: Petrotal usa "MAXIMA"/"T-SUPER PREMIUM" en
    // sus propias facturas de venta; Tesoro usa "UNBRANDED REGULAR/PREMIUM
    // GAS"; ESSA usa "Diésel". No se asume un producto por defecto — lo que
    // no clasifica se excluye y se reporta como advertencia.
    public function clasificar_producto(string $descripcion): ?array {
        $d = mb_strtoupper($descripcion, 'UTF-8');
        if (strpos($d, 'PREMIUM') !== false || strpos($d, 'SUPER') !== false) {
            return ['producto_id' => 7, 'subproducto_id' => 14, 'label' => 'Premium'];
        }
        if (strpos($d, 'REGULAR') !== false || strpos($d, 'MAXIMA') !== false || strpos($d, 'MÁXIMA') !== false) {
            return ['producto_id' => 7, 'subproducto_id' => 13, 'label' => 'Regular'];
        }
        if (strpos($d, 'DIESEL') !== false || strpos($d, 'DIÉSEL') !== false || strpos($d, 'DIÉ') !== false) {
            return ['producto_id' => 3, 'subproducto_id' => 62, 'label' => 'Diesel'];
        }
        return null;
    }

    // Resuelve el permiso CRE de un cliente vía el catálogo de estaciones de
    // servicio con volumétricos (XmlCre). Un mismo RFC puede tener más de un
    // permiso (ej. Estación Custodia, Díaz Gas con múltiples estaciones) —
    // en ese caso primero se intenta desambiguar por coincidencia del permiso
    // dentro del texto libre de FacturasRecibidas.Destino (ej. "ESTACION
    // PLUTARCO PL/2060/EXP/ES/2015"); si no hay match, se listan los
    // candidatos para que el controlador pida desambiguación en el preview.
    public function resolver_cliente(string $rfc, ?string $destino = null): ?array {
        $rows = $this->sql->select(
            "SELECT DISTINCT NumeroPermisoCRE FROM TG.dbo.XmlCre WHERE Rfc = ?",
            [$rfc]
        );
        if (!$rows) return null;
        $permisos = array_column($rows, 'NumeroPermisoCRE');
        $permisoCre = count($permisos) === 1 ? $permisos[0] : null;

        if ($permisoCre === null && $destino !== null) {
            if (preg_match('/[Pp][Ll]\/\d+\/[Ee][Xx][Pp]\/[Ee][Ss]\/\d+/', $destino, $m)) {
                $extraido = $m[0];
                foreach ($permisos as $candidato) {
                    if (strcasecmp($candidato, $extraido) === 0) {
                        $permisoCre = $candidato;
                        break;
                    }
                }
            }
        }

        return [
            'permiso_cre' => $permisoCre,
            'candidatos' => $permisos,
        ];
    }

    // Resuelve el permiso de comercializador de un proveedor vía
    // SG12.dbo.Proveedores.nropcc (confirmado contra Tesoro H/19873/COM/2017
    // y ESSA H/23183/COM/2020, coincide con los acuses reales de agosto
    // 2026). Solo lectura sobre SG12, nunca se escribe ahí.
    public function resolver_proveedor(string $rfc): ?string {
        $rows = $this->sql->select(
            "SELECT nropcc FROM SG12.dbo.Proveedores WHERE rfc = ?",
            [$rfc]
        );
        $permiso = $rows[0]['nropcc'] ?? null;
        return ($permiso !== null && trim($permiso) !== '') ? trim($permiso) : null;
    }

    // Facturas donde Petrotal es el EMISOR: lo que Petrotal vendió a sus
    // clientes (estaciones de servicio) en el periodo.
    public function obtener_facturas_venta(string $desde, string $hasta): array {
        $query = "
            SELECT fr.Id AS FacturaId, fr.Fecha, fr.Folio, fr.Total, fr.Destino,
                   fr.ReceptorRfc AS ContraparteRfc, fr.ReceptorNombre AS ContraparteNombre,
                   c.Cantidad, c.Descripcion
            FROM TG.dbo.FacturasRecibidas fr
            JOIN TG.dbo.FacturasRecibidasConceptos c ON c.FacturaId = fr.Id
            WHERE fr.EmisorRfc = ?
              AND fr.Fecha BETWEEN ? AND ?
            ORDER BY fr.Fecha
        ";
        return $this->sql->select($query, [
            PetrotalReconciliationModel::PETROTAL_RFC,
            $desde . ' 00:00:00',
            $hasta . ' 23:59:59',
        ]) ?: [];
    }

    // Facturas donde Petrotal es el RECEPTOR: lo que Petrotal compró a sus
    // proveedores (comercializadores mayoristas) en el periodo.
    public function obtener_facturas_compra(string $desde, string $hasta): array {
        $query = "
            SELECT fr.Id AS FacturaId, fr.Fecha, fr.Folio, fr.Total,
                   fr.EmisorRfc AS ContraparteRfc, fr.EmisorNombre AS ContraparteNombre,
                   c.Cantidad, c.Descripcion
            FROM TG.dbo.FacturasRecibidas fr
            JOIN TG.dbo.FacturasRecibidasConceptos c ON c.FacturaId = fr.Id
            WHERE fr.ReceptorRfc = ?
              AND fr.Fecha BETWEEN ? AND ?
            ORDER BY fr.Fecha
        ";
        return $this->sql->select($query, [
            PetrotalReconciliationModel::PETROTAL_RFC,
            $desde . ' 00:00:00',
            $hasta . ' 23:59:59',
        ]) ?: [];
    }

    // Punto de entrada del modelo: arma el reporte completo del periodo,
    // clasificando producto y resolviendo contraparte fila por fila. No
    // excluye silenciosamente nada que no resuelva — todo lo problemático
    // queda en 'advertencias' para que el controlador decida qué mostrar.
    public function construir_reporte(string $desde, string $hasta): array {
        $ventas = [];
        $compras = [];
        $advertencias = [];

        $filasVenta = $this->obtener_facturas_venta($desde, $hasta);
        foreach ($filasVenta as $fila) {
            $this->procesar_fila($fila, 'cliente', $ventas, $advertencias);
        }

        $filasCompra = $this->obtener_facturas_compra($desde, $hasta);
        foreach ($filasCompra as $fila) {
            $this->procesar_fila($fila, 'proveedor', $compras, $advertencias);
        }

        return ['ventas' => $ventas, 'compras' => $compras, 'advertencias' => $advertencias];
    }

    private function procesar_fila(array $fila, string $tipoContraparte, array &$destino, array &$advertencias): void {
        $clasificacion = $this->clasificar_producto($fila['Descripcion']);
        if ($clasificacion === null) {
            $advertencias[] = [
                'tipo' => 'producto_no_clasificado',
                'factura_id' => $fila['FacturaId'],
                'descripcion' => $fila['Descripcion'],
                'mensaje' => "Factura {$fila['Folio']}: descripción \"{$fila['Descripcion']}\" no se pudo clasificar como Regular/Premium/Diesel.",
            ];
            return;
        }

        $rfc = $fila['ContraparteRfc'];
        $permisoCre = null;
        if ($tipoContraparte === 'cliente') {
            $resolucion = $this->resolver_cliente($rfc, $fila['Destino'] ?? null);
            if ($resolucion === null) {
                $advertencias[] = [
                    'tipo' => 'sin_permiso',
                    'contraparte_rfc' => $rfc,
                    'contraparte_nombre' => $fila['ContraparteNombre'],
                    'mensaje' => "Cliente {$fila['ContraparteNombre']} ({$rfc}) no tiene NumeroPermisoCRE en XmlCre.",
                ];
                return;
            }
            if ($resolucion['permiso_cre'] === null) {
                $advertencias[] = [
                    'tipo' => 'permiso_ambiguo',
                    'contraparte_rfc' => $rfc,
                    'contraparte_nombre' => $fila['ContraparteNombre'],
                    'candidatos' => $resolucion['candidatos'],
                    'mensaje' => "Cliente {$fila['ContraparteNombre']} ({$rfc}) tiene más de un permiso CRE, requiere selección manual.",
                ];
                return;
            }
            $permisoCre = $resolucion['permiso_cre'];
        } else {
            $permisoCre = $this->resolver_proveedor($rfc);
            if ($permisoCre === null) {
                $advertencias[] = [
                    'tipo' => 'sin_permiso',
                    'contraparte_rfc' => $rfc,
                    'contraparte_nombre' => $fila['ContraparteNombre'],
                    'mensaje' => "Proveedor {$fila['ContraparteNombre']} ({$rfc}) no tiene nropcc en SG12.Proveedores.",
                ];
                return;
            }
        }

        $volumenBbl = round(((float) $fila['Cantidad']) / self::LITROS_POR_BARRIL, 2);
        $total = (float) $fila['Total'];
        $precio = $volumenBbl > 0 ? round($total / $volumenBbl, 2) : 0.0;

        $destino[] = [
            'fecha' => substr($fila['Fecha'], 0, 10),
            'factura_id' => $fila['FacturaId'],
            'folio' => $fila['Folio'],
            'producto_id' => $clasificacion['producto_id'],
            'subproducto_id' => $clasificacion['subproducto_id'],
            'producto_label' => $clasificacion['label'],
            'contraparte_rfc' => $rfc,
            'contraparte_nombre' => $fila['ContraparteNombre'],
            'permiso_cre' => $permisoCre,
            'volumen_bbl' => $volumenBbl,
            'precio' => $precio,
            'descripcion_original' => $fila['Descripcion'],
        ];
    }
}
