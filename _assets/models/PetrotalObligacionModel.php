<?php
class PetrotalObligacionModel extends Model {

    const LITROS_POR_BARRIL = 158.987;

    // Patrones del XSD de la CNE (XSD_Reporte_Estadistico_Comercializacion).
    const PATRON_PERMISO_CLIENTE = '#^(CNE/)?(PL|H)/[0-9]{1,6}/(EXP|DIS|COM)/((ES|ESA|AE|OM)/)?(MM/)?[0-9]{4}$#';
    const PATRON_PERMISO_PROVEEDOR = '#^(CNE/)?(H|PL)/[0-9]{4,6}/(DIS|COM)/(OM/)?[0-9]{4}$#';

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
        // PEMEX MAGNA = gasolina Regular (87 octanos), p. ej. compras a MGC.
        if (strpos($d, 'REGULAR') !== false || strpos($d, 'MAXIMA') !== false || strpos($d, 'MÁXIMA') !== false || strpos($d, 'MAGNA') !== false) {
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
                   c.Cantidad, c.Descripcion, c.ValorUnitario, c.Importe, c.ImporteImpuesto
            FROM TG.dbo.FacturasRecibidas fr
            JOIN TG.dbo.FacturasRecibidasConceptos c ON c.FacturaId = fr.Id
            WHERE fr.EmisorRfc = ?
              AND fr.TipoDeComprobante = 'I'
              AND ISNULL(c.ClaveUnidad, 'LTR') = 'LTR'
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
                   c.Cantidad, c.Descripcion, c.ValorUnitario, c.Importe, c.ImporteImpuesto
            FROM TG.dbo.FacturasRecibidas fr
            JOIN TG.dbo.FacturasRecibidasConceptos c ON c.FacturaId = fr.Id
            WHERE fr.ReceptorRfc = ?
              AND fr.TipoDeComprobante = 'I'
              AND ISNULL(c.ClaveUnidad, 'LTR') = 'LTR'
              AND fr.Fecha BETWEEN ? AND ?
            ORDER BY fr.Fecha
        ";
        return $this->sql->select($query, [
            PetrotalReconciliationModel::PETROTAL_RFC,
            $desde . ' 00:00:00',
            $hasta . ' 23:59:59',
        ]) ?: [];
    }

    // Solo se reportan comprobantes de Ingreso (I). Las notas de crédito (E)
    // y los complementos de pago (P) no son operaciones de volumen: antes se
    // mezclaban y los P generaban advertencias de "producto no clasificado".
    // Se cuentan para avisarlo en la vista previa (informativo, no bloquea).
    // OJO: TG no guarda el estatus de cancelación del CFDI, así que una
    // factura cancelada en el SAT no se puede excluir desde aquí.
    public function contar_omitidos(string $desde, string $hasta): array {
        $rows = $this->sql->select("
            SELECT TipoDeComprobante, COUNT(*) AS n
            FROM TG.dbo.FacturasRecibidas
            WHERE (EmisorRfc = ? OR ReceptorRfc = ?)
              AND TipoDeComprobante <> 'I'
              AND Fecha BETWEEN ? AND ?
            GROUP BY TipoDeComprobante
        ", [
            PetrotalReconciliationModel::PETROTAL_RFC,
            PetrotalReconciliationModel::PETROTAL_RFC,
            $desde . ' 00:00:00',
            $hasta . ' 23:59:59',
        ]) ?: [];
        $conteo = [];
        foreach ($rows as $r) $conteo[$r['TipoDeComprobante']] = (int) $r['n'];

        // Conceptos que no son litros (servicios, anticipos: ClaveUnidad ACT/E48…).
        // Todo concepto de combustible de Petrotal viene en LTR (validado ene-2025 a la fecha).
        $serv = $this->sql->select("
            SELECT COUNT(*) AS n
            FROM TG.dbo.FacturasRecibidas fr
            JOIN TG.dbo.FacturasRecibidasConceptos c ON c.FacturaId = fr.Id
            WHERE (fr.EmisorRfc = ? OR fr.ReceptorRfc = ?)
              AND fr.TipoDeComprobante = 'I'
              AND ISNULL(c.ClaveUnidad, 'LTR') <> 'LTR'
              AND fr.Fecha BETWEEN ? AND ?
        ", [
            PetrotalReconciliationModel::PETROTAL_RFC,
            PetrotalReconciliationModel::PETROTAL_RFC,
            $desde . ' 00:00:00',
            $hasta . ' 23:59:59',
        ]);
        if (!empty($serv[0]['n'])) $conteo['SERV'] = (int) $serv[0]['n'];
        return $conteo;
    }

    // Punto de entrada del modelo: arma el reporte completo del periodo,
    // clasificando producto y resolviendo contraparte fila por fila. No
    // excluye silenciosamente nada que no resuelva — todo lo problemático
    // queda en 'advertencias' para que el controlador decida qué mostrar.
    //
    // $asignaciones: [FacturaId => NumeroPermisoCRE] elegido a mano por el
    // usuario para clientes con varios permisos que no se pudieron
    // desambiguar. Solo se acepta un permiso que esté entre los candidatos.
    public function construir_reporte(string $desde, string $hasta, array $asignaciones = []): array {
        $ventas = [];
        $compras = [];
        $advertencias = [];

        $filasVenta = $this->obtener_facturas_venta($desde, $hasta);
        foreach ($filasVenta as $fila) {
            $this->procesar_fila($fila, 'cliente', $ventas, $advertencias, $asignaciones);
        }

        $filasCompra = $this->obtener_facturas_compra($desde, $hasta);
        foreach ($filasCompra as $fila) {
            $this->procesar_fila($fila, 'proveedor', $compras, $advertencias, $asignaciones);
        }

        $omitidos = $this->contar_omitidos($desde, $hasta);
        $nombres = ['E' => 'nota(s) de crédito', 'P' => 'complemento(s) de pago', 'T' => 'comprobante(s) de traslado', 'SERV' => 'concepto(s) que no son litros (servicios/anticipos)'];
        foreach ($omitidos as $tipo => $n) {
            $advertencias[] = [
                'tipo' => 'comprobantes_omitidos',
                'mensaje' => "Se omitieron {$n} " . ($nombres[$tipo] ?? "comprobante(s) tipo {$tipo}") . " del periodo (solo se reportan conceptos de combustible en litros de facturas de ingreso).",
            ];
        }

        return ['ventas' => $ventas, 'compras' => $compras, 'advertencias' => $advertencias];
    }

    private function procesar_fila(array $fila, string $tipoContraparte, array &$destino, array &$advertencias, array $asignaciones = []): void {
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
                // ¿El usuario ya eligió el permiso para esta factura?
                $elegido = $asignaciones[$fila['FacturaId']] ?? null;
                if ($elegido !== null && in_array($elegido, $resolucion['candidatos'], true)) {
                    $resolucion['permiso_cre'] = $elegido;
                }
            }
            if ($resolucion['permiso_cre'] === null) {
                $advertencias[] = [
                    'tipo' => 'permiso_ambiguo',
                    'factura_id' => $fila['FacturaId'],
                    'contraparte_rfc' => $rfc,
                    'contraparte_nombre' => $fila['ContraparteNombre'],
                    'destino' => $fila['Destino'] ?? null,
                    'candidatos' => $resolucion['candidatos'],
                    'mensaje' => "Factura {$fila['Folio']}: {$fila['ContraparteNombre']} ({$rfc}) tiene más de un permiso CRE; elige cuál corresponde" . (!empty($fila['Destino']) ? " (destino: {$fila['Destino']})." : '.'),
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

        // Formato del permiso según el XSD de la CNE (t_permisoCRECliente /
        // t_permiso_COM). Un permiso mal formado haría rechazar todo el archivo.
        $patron = $tipoContraparte === 'cliente' ? self::PATRON_PERMISO_CLIENTE : self::PATRON_PERMISO_PROVEEDOR;
        $permisoCre = trim((string) $permisoCre);
        $longitud = strlen($permisoCre);
        if ($longitud < 14 || $longitud > 24 || !preg_match($patron, $permisoCre)) {
            $advertencias[] = [
                'tipo' => 'permiso_formato_invalido',
                'factura_id' => $fila['FacturaId'],
                'contraparte_rfc' => $rfc,
                'contraparte_nombre' => $fila['ContraparteNombre'],
                'mensaje' => "Factura {$fila['Folio']}: el permiso \"{$permisoCre}\" de {$fila['ContraparteNombre']} ({$rfc}) no cumple el formato que exige la CNE.",
            ];
            return;
        }

        // Volumen: litros del concepto convertidos a barriles.
        $cantidad = (float) $fila['Cantidad'];
        $volumenBbl = round($cantidad / self::LITROS_POR_BARRIL, 2);

        // Precio por barril = (importe del concepto + su impuesto) / litros,
        // convertido a barril. Validado contra el acuse de agosto 2026
        // (ej. PL/2060: $3,301.47/bbl). NO se usa fr.Total: es de toda la
        // factura (varios conceptos) y en estas facturas excluye el IVA.
        $importe = $fila['Importe'] !== null ? (float) $fila['Importe'] : $cantidad * (float) ($fila['ValorUnitario'] ?? 0);
        $impuesto = (float) ($fila['ImporteImpuesto'] ?? 0);
        $precio = $cantidad > 0 ? round(($importe + $impuesto) / $cantidad * self::LITROS_POR_BARRIL, 2) : 0.0;

        // Rangos del XSD: precio y volumen con mínimo 0.01 (t_precio máx.
        // 99999.99). Si no cumplen, se excluye la línea y se advierte.
        if ($volumenBbl < 0.01 || $precio < 0.01 || $precio > 99999.99) {
            $advertencias[] = [
                'tipo' => 'valor_fuera_de_rango',
                'factura_id' => $fila['FacturaId'],
                'mensaje' => "Factura {$fila['Folio']}: volumen ({$volumenBbl} bbl) o precio ({$precio} $/bbl) fuera del rango que acepta la CNE (mínimo 0.01, precio máximo 99999.99).",
            ];
            return;
        }

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

    // La tabla vive en TG (creada por la migración del Task 1 sin pasar por
    // Model::connect), pero este modelo conecta a SG12 en su constructor
    // (Model::__construct) — igual que obtener_facturas_venta/compra, hay
    // que calificar el nombre con TG.dbo. explícitamente en cada consulta.
    public function crear_envio(string $desde, string $hasta, int $usuarioId): int {
        $id = $this->sql->insert(
            "INSERT INTO TG.dbo.PetrotalReportesObligacion (PeriodoDesde, PeriodoHasta, Estado, UsuarioId) VALUES (?, ?, 'borrador', ?)",
            [$desde, $hasta, $usuarioId]
        );
        return (int) $id;
    }

    public function actualizar_envio(int $id, array $campos): bool {
        $columnasPermitidas = ['Estado', 'RutaJson', 'RutaAcuse', 'FolioAcuse', 'RespuestaRaw', 'FechaEnvio'];
        $sets = [];
        $params = [];
        foreach ($campos as $columna => $valor) {
            if (!in_array($columna, $columnasPermitidas, true)) continue;
            $sets[] = "{$columna} = ?";
            $params[] = $valor;
        }
        if (!$sets) return false;
        $sets[] = "UpdatedAt = GETDATE()";
        $params[] = $id;

        $query = "UPDATE TG.dbo.PetrotalReportesObligacion SET " . implode(', ', $sets) . " WHERE Id = ?";
        return (bool) $this->sql->update($query, $params);
    }

    public function buscar_envio_periodo(string $desde, string $hasta): ?array {
        $rows = $this->sql->select(
            "SELECT TOP 1 * FROM TG.dbo.PetrotalReportesObligacion WHERE PeriodoDesde = ? AND PeriodoHasta = ? ORDER BY Id DESC",
            [$desde, $hasta]
        );
        return $rows[0] ?? null;
    }

    public function listar_envios(): array {
        return $this->sql->select("SELECT * FROM TG.dbo.PetrotalReportesObligacion ORDER BY PeriodoDesde DESC", []) ?: [];
    }

    public function obtener_envio(int $id): ?array {
        $rows = $this->sql->select("SELECT * FROM TG.dbo.PetrotalReportesObligacion WHERE Id = ?", [$id]);
        return $rows[0] ?? null;
    }
}
