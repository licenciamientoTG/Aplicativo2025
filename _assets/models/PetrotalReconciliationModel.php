<?php
class PetrotalReconciliationModel extends Model {

    // RFC fijo de Petrotal, confirmado contra 32 facturas reales en la
    // sesión de diseño (2026-09-01). No varía por estación ni por producto.
    const PETROTAL_RFC = 'PET180213L66';

    // RFC de Tesoro, único proveedor con código externo mapeado hoy en
    // TG.dbo.EstacionesCodigosExternos (columna PresentacionTesoro).
    const TESORO_RFC = 'TMS1611162N5';

    // Quita el prefijo "RP-" (visto en Premiergas) que txtref no trae.
    private function normalizar_remision(string $r): string {
        return preg_replace('/^RP-/i', '', trim($r));
    }

    // Quita ceros de relleno tras el prefijo alfabético ("FE-041741" -> "FE-41741").
    private function normalizar_folio(string $f): string {
        $f = trim($f);
        if (preg_match('/^([A-Za-z]+-?)0*(\d+)$/', $f, $m)) {
            return $m[1] . $m[2];
        }
        return $f;
    }

    // Normaliza nombre de producto a una palabra clave comparable entre el
    // tanque de ControlGas ("T-Super Premium") y el concepto del CFDI de
    // Petrotal ("T-SUPER PREMIUM" / "MAXIMA"): el litraje puede repetirse
    // entre productos distintos de la misma recepción, así que el producto
    // es la única llave confiable para separar varias facturas Petrotal.
    private function normalizar_producto(string $nombre): string {
        $n = strtoupper(trim($nombre));
        $n = preg_replace('/^T[\.\-]\s*/', '', $n);
        $n = preg_replace('/\s+REGULAR$/', '', $n);
        $n = str_replace('-', ' ', $n);
        return trim(preg_replace('/\s+/', ' ', $n));
    }

    function parse_txtref(?string $txtref): ?array {
        if (!$txtref) return null;
        if (!preg_match('/@F:([^@]*)@R:([^@]*)@V:([^@]*)/', $txtref, $m)) return null;
        return ['folio' => trim($m[1]), 'remision' => trim($m[2]), 'vehiculo' => trim($m[3])];
    }

    // Busca la factura del proveedor real por Remision (principal) con
    // fallback a Folio, ambos normalizados. No asume 1 factura por RFC:
    // trae todas las del emisor y compara en PHP porque el volumen por
    // proveedor/estación es bajo (decenas, no miles, por rango consultado).
    function buscar_factura_proveedor(string $folioRef, string $remisionRef, string $emisorRfc, string $fechaDesde, string $fechaHasta): ?array {
        $remNorm = $this->normalizar_remision($remisionRef);
        $folioNorm = $this->normalizar_folio($folioRef);

        $desdeAmpliado = date('Y-m-d', strtotime($fechaDesde . ' -15 days'));
        $hastaAmpliado = date('Y-m-d', strtotime($fechaHasta . ' +15 days'));

        $query = "SELECT Id, Folio, Remision, EmisorNombre, EmisorRfc, Fecha, Total
                   FROM TG.dbo.FacturasRecibidas
                   WHERE EmisorRfc = :emisorRfc
                     AND Fecha BETWEEN :desde AND :hasta";
        $rows = $this->sql->select($query, [
            'emisorRfc' => $emisorRfc,
            'desde' => $desdeAmpliado,
            'hasta' => $hastaAmpliado,
        ]);

        foreach ($rows as $row) {
            if ($row['Remision'] && $this->normalizar_remision($row['Remision']) === $remNorm) {
                return ['factura' => $row, 'confianza' => 'exacta_remision'];
            }
        }
        foreach ($rows as $row) {
            if ($row['Folio'] && $this->normalizar_folio($row['Folio']) === $folioNorm) {
                return ['factura' => $row, 'confianza' => 'exacta_folio'];
            }
        }
        return null;
    }

    // Catálogo de proveedores con al menos una factura hacia Petrotal —
    // llena el filtro de la vista. Se agrupa por RFC (no por EmisorNombre,
    // que varía en formato: "MGC MEXICO" / "MGC\nMEXICO" / "mgcmexico" son
    // el mismo proveedor).
    function proveedores_hacia_petrotal(): array {
        $query = "
            SELECT EmisorRfc, MAX(EmisorNombre) AS EmisorNombre, COUNT(*) AS n
            FROM TG.dbo.FacturasRecibidas
            WHERE ReceptorNombre = 'PETROTAL' AND EmisorRfc IS NOT NULL AND EmisorRfc <> ''
            GROUP BY EmisorRfc
            ORDER BY n DESC
        ";
        return $this->sql->select($query);
    }

    // Facturas de la COMPRA ORIGINAL (proveedor real -> Petrotal), punto de
    // partida de la vista: ReceptorNombre='PETROTAL' con EmisorRfc del
    // proveedor filtrado (2026-09-04, diseño invertido a pedido del
    // usuario — antes se partía de la recepción en ControlGas o de la
    // factura Petrotal->estación; ahora se parte de la factura que el
    // comprador ya conoce de primera mano: la de Tesoro/proveedor hacia
    // Petrotal). El Destino de ESTA factura nunca sirve para identificar
    // estación (para Tesoro siempre es el permiso fijo de recolecta
    // 'H/19873/COM/2017', confirmado 2026-09-03) — la estación se resuelve
    // aparte vía EstacionesCodigosExternos en sugerir_factura_petrotal().
    function buscar_facturas_proveedor_hacia_petrotal(string $fechaDesde, string $fechaHasta, string $proveedorRfc): array {
        // Suma Cantidad por factura (GROUP BY): 932 de 4878 facturas de
        // Tesoro (19%, confirmado 2026-09-04) traen más de un concepto —
        // tomar una sola fila del JOIN subestimaba los litros hasta a la
        // mitad y arruinaba el match por volumen. MAX(Descripcion) es solo
        // para mostrar (nunca se compara texto contra el nombre de tanque).
        $query = "
            SELECT fr.Id, fr.Folio, fr.Remision, fr.UUID, fr.Total, fr.Fecha, fr.EmisorNombre, fr.EmisorRfc,
                   fr.PresentacionTesoro, MAX(fc.Descripcion) AS Producto, SUM(fc.Cantidad) AS Litros
            FROM TG.dbo.FacturasRecibidas fr
            LEFT JOIN TG.dbo.FacturasRecibidasConceptos fc ON fc.FacturaId = fr.Id
            WHERE fr.ReceptorNombre = 'PETROTAL'
              AND fr.EmisorRfc = :proveedorRfc
              AND fr.Fecha BETWEEN :fechaDesde AND :fechaHasta
            GROUP BY fr.Id, fr.Folio, fr.Remision, fr.UUID, fr.Total, fr.Fecha, fr.EmisorNombre, fr.EmisorRfc, fr.PresentacionTesoro
        ";
        return $this->sql->select($query, [
            'proveedorRfc' => $proveedorRfc,
            'fechaDesde' => $fechaDesde,
            'fechaHasta' => $fechaHasta,
        ]);
    }

    // Estaciones con al menos un código externo mapeado para $proveedorRfc
    // (ej. las estaciones de Tesoro ya identificadas en
    // EstacionesCodigosExternos) — llena el selector de la pestaña
    // "Confirmar recepción ↔ factura", que solo tiene sentido para
    // estaciones ya resueltas.
    function estaciones_con_codigo_externo(string $proveedorRfc): array {
        $query = "
            SELECT DISTINCT e.Codigo, e.Estacion, e.Nombre
            FROM TG.dbo.EstacionesCodigosExternos ece
            JOIN TG.dbo.Estaciones e ON e.Codigo = ece.codgas
            WHERE ece.proveedor_rfc = :proveedorRfc AND ece.activo = 1
            ORDER BY e.Nombre
        ";
        return $this->sql->select($query, ['proveedorRfc' => $proveedorRfc]);
    }

    // Resuelve la fila de TG.dbo.Estaciones para un (proveedor, código
    // externo) vía TG.dbo.EstacionesCodigosExternos — ej. PresentacionTesoro
    // de una factura Tesoro->Petrotal ya trae el código, no hace falta
    // parsear texto libre (a diferencia del lado Petrotal->estación, donde
    // Destino sí trae el permiso de expendio en texto).
    function resolver_estacion_por_codigo_externo(string $proveedorRfc, string $codigoExterno): ?array {
        if (!$codigoExterno) return null;
        $query = "
            SELECT e.Codigo, e.Estacion, e.Nombre, e.PermisoCRE, e.RFC, e.Servidor, e.BaseDatos
            FROM TG.dbo.EstacionesCodigosExternos ece
            JOIN TG.dbo.Estaciones e ON e.Codigo = ece.codgas
            WHERE ece.proveedor_rfc = :proveedorRfc AND ece.codigo_externo = :codigoExterno AND ece.activo = 1
        ";
        $rows = $this->sql->select($query, ['proveedorRfc' => $proveedorRfc, 'codigoExterno' => $codigoExterno]);
        return $rows[0] ?? null;
    }

    // Facturas de Petrotal candidatas para una estación/rango, cada una ya
    // con su producto (una factura Petrotal trae un solo concepto/producto,
    // confirmado en la muestra de 32 facturas).
    function buscar_facturas_petrotal(string $permisoCRE, string $fechaDesde, string $fechaHasta): array {
        $query = "
            SELECT fr.Id, fr.Folio, fr.UUID, fr.Total, fr.Fecha, fr.Destino, fr.ReceptorNombre,
                   fc.Descripcion AS Producto, fc.Cantidad AS Litros
            FROM TG.dbo.FacturasRecibidas fr
            LEFT JOIN TG.dbo.FacturasRecibidasConceptos fc ON fc.FacturaId = fr.Id
            WHERE fr.EmisorRfc = :petrotalRfc
              AND fr.Fecha BETWEEN :fechaDesde AND :fechaHasta
              AND fr.Destino LIKE :permiso
        ";
        return $this->sql->select($query, [
            'petrotalRfc' => self::PETROTAL_RFC,
            'fechaDesde' => $fechaDesde, 'fechaHasta' => $fechaHasta,
            'permiso' => '%' . $permisoCRE . '%',
        ]);
    }

    // Si ninguna candidata coincide en producto, se devuelven todas sin
    // filtrar: mejor mostrarlas para revisión manual que ocultar una
    // factura real por un nombre de producto que no matcheó.
    function filtrar_por_producto(array $facturasPetrotal, string $productoRecepcion): array {
        $prodNorm = $this->normalizar_producto($productoRecepcion);
        $filtradas = array_values(array_filter($facturasPetrotal, function ($f) use ($prodNorm) {
            return $this->normalizar_producto($f['Producto'] ?? '') === $prodNorm;
        }));
        return $filtradas ?: $facturasPetrotal;
    }

    // Punto de entrada del nuevo diseño: dada UNA factura del proveedor
    // real hacia Petrotal (la compra original, ej. Tesoro), busca su mejor
    // sugerencia de factura de Petrotal hacia la estación. La estación se
    // resuelve por el código externo que la factura YA trae
    // (PresentacionTesoro) vía EstacionesCodigosExternos — no por su
    // Destino, que para el lado proveedor->Petrotal es siempre un permiso
    // fijo de recolecta sin información de estación (confirmado 2026-09-03).
    // Camino preferido: match exacto por Remisión/Folio si la recepción de
    // esa estación en ControlGas trae el txtref de este mismo proveedor en
    // el rango de la factura (±5 días). Si no hay recepción o no matchea,
    // cae a cercanía de LITROS entre esta factura y las candidatas de
    // Petrotal de esa estación (nunca monto — Petrotal agrega margen).
    // Nunca lanza: si no se puede resolver ni la estación (código externo
    // aún no mapeado para este proveedor), devuelve null en 'estacion'.
    function sugerir_factura_petrotal(array $facturaProveedor, string $proveedorRfc): array {
        $resultado = ['estacion' => null, 'factura_petrotal' => null, 'confianza' => null];

        $codigoExterno = $facturaProveedor['PresentacionTesoro'] ?? '';
        $estacion = $this->resolver_estacion_por_codigo_externo($proveedorRfc, $codigoExterno);
        if (!$estacion) return $resultado;
        $resultado['estacion'] = $estacion;

        $fecha = $facturaProveedor['Fecha'] ?? date('Y-m-d');
        $fechaSoloDia = substr($fecha, 0, 10);
        $desde = date('Y-m-d', strtotime($fechaSoloDia . ' -5 days'));
        $hasta = date('Y-m-d', strtotime($fechaSoloDia . ' +5 days'));

        // Camino 1: recepción real en ControlGas de esa estación con txtref
        // de ESTA MISMA factura del proveedor, cruzando por Remision/Folio
        // (exacto) — confirma que la recepción corresponde a esta compra.
        $recepciones = $this->movimientosTan_con_txtref($estacion['Codigo'], $fechaSoloDia);
        foreach ($recepciones as $r) {
            $ref = $this->parse_txtref($r['txtref'] ?? null);
            if (!$ref) continue;
            $remNorm = $this->normalizar_remision($ref['remision']);
            $folioNorm = $this->normalizar_folio($ref['folio']);
            $matchaEstaFactura =
                ($facturaProveedor['Remision'] && $this->normalizar_remision($facturaProveedor['Remision']) === $remNorm) ||
                ($facturaProveedor['Folio'] && $this->normalizar_folio($facturaProveedor['Folio']) === $folioNorm);
            if (!$matchaEstaFactura) continue;

            // La recepción es de esta compra: la factura de Petrotal para
            // esa misma estación/rango con el mismo producto es el match.
            $candidatasPetrotal = $this->buscar_facturas_petrotal($estacion['PermisoCRE'], $desde, $hasta);
            $candidatasPetrotal = $this->filtrar_por_producto($candidatasPetrotal, $facturaProveedor['Producto'] ?? '');
            if (count($candidatasPetrotal) >= 1) {
                $resultado['factura_petrotal'] = $candidatasPetrotal[0];
                $resultado['confianza'] = 'exacta_recepcion';
                return $resultado;
            }
        }

        // Camino 2: cercanía de litros entre esta factura y las candidatas
        // de Petrotal para la estación resuelta.
        $litrosProveedor = (float)($facturaProveedor['Litros'] ?? 0);
        $candidatasPetrotal = $this->buscar_facturas_petrotal($estacion['PermisoCRE'], $desde, $hasta);
        if (!$candidatasPetrotal) return $resultado;

        usort($candidatasPetrotal, function ($a, $b) use ($litrosProveedor) {
            $diffA = abs((float)($a['Litros'] ?? 0) - $litrosProveedor);
            $diffB = abs((float)($b['Litros'] ?? 0) - $litrosProveedor);
            return $diffA <=> $diffB;
        });

        $mejor = $candidatasPetrotal[0];
        $diferenciaLitros = abs((float)($mejor['Litros'] ?? 0) - $litrosProveedor);
        $resultado['factura_petrotal'] = $mejor;
        // Tolerancia de 1 litro para "exacta" (redondeos de captura), el
        // resto queda como aproximada — nunca se descarta, la revisión es
        // siempre manual.
        $resultado['confianza'] = $diferenciaLitros <= 1.0 ? 'exacta_litros' : 'aproximada_litros';
        return $resultado;
    }

    // Recepciones (tiptrn=3) de una estación en un día puntual, solo con
    // txtref no vacío — versión ligera de sp_obtener_recepciones_combustible
    // sin los JOINs a Tanques/Documentos que no hacen falta aquí (solo se
    // usa el texto de txtref para extraer @F:/@R:).
    private function movimientosTan_con_txtref(int $codgas, string $fecha): array {
        if (!isset($this->linked_server[$codgas]) || !isset($this->short_databases[$codgas])) return [];
        $serverIp = $this->linked_server[$codgas];
        $database = $this->short_databases[$codgas];
        $fchtrn = dateToInt($fecha);

        $query = "
            SELECT * FROM OPENQUERY(" . $serverIp . ", '
                SELECT DC.txtref
                FROM " . $database . ".[MovimientosTan] M
                    LEFT JOIN " . $database . ".[Documentos] D ON M.nrodoc = D.nro AND M.codgas = D.codgas AND D.tip = 1 AND D.nroitm = 1
                    LEFT JOIN " . $database . ".[DocumentosC] DC ON M.nrodoc = DC.nro AND M.codgas = DC.codgas AND DC.tip = 1
                WHERE M.nroitm NOT IN (0,1,3,4) AND M.tiptrn = 3
                  AND M.fchtrn = " . $fchtrn . " AND M.codgas = " . $codgas . "
                  AND DC.txtref IS NOT NULL
            ');
        ";
        return $this->sql->select($query);
    }

    // Estado de una factura de Petrotal en ControlGas: si su UUID ya está
    // subido a DocumentosC.satuid de la estación resuelta. Informativo, no
    // limita la conciliación (una factura sin subir a ControlGas igual se
    // puede conciliar). satuid puede venir en mayúsculas o minúsculas según
    // la estación (confirmado: Plutarco mayúsculas, Tecnológico minúsculas),
    // por eso LOWER() en ambos lados. Devuelve null si no se puede resolver
    // la estación o la consulta a la estación falla (nunca rompe la vista).
    function esta_en_controlgas(int $codgas, string $uuid): ?bool {
        if (!isset($this->linked_server[$codgas]) || !isset($this->short_databases[$codgas])) return null;
        $serverIp = $this->linked_server[$codgas];
        $database = $this->short_databases[$codgas];
        $uuidEscapado = str_replace("'", "''", $uuid);

        $query = "
            SELECT * FROM OPENQUERY(" . $serverIp . ", '
                SELECT COUNT(*) AS n FROM " . $database . ".[DocumentosC]
                WHERE LOWER(satuid) = LOWER(''" . $uuidEscapado . "'')
            ');
        ";
        try {
            $rows = $this->sql->select($query);
            return !empty($rows) && (int)($rows[0]['n'] ?? 0) > 0;
        } catch (Exception $e) {
            return null;
        }
    }

    // $facturaPetrotalId null: caso "recepción directa" (proveedor real
    // ligado a una recepción, sin Petrotal de por medio — TipoOperacion=1),
    // se busca por FacturaProveedorId con FacturaPetrotalId IS NULL.
    // Recepciones (tiptrn=3) de una estación en un rango de fechas, con su
    // volumen/producto/txtref — para la sugerencia recepción<->factura del
    // proveedor real. A diferencia de sp_obtener_recepciones_combustible
    // (usado en Mis Recepciones) no trae Tanques/Documentos, solo lo que
    // hace falta para sugerir: volumen, producto y si ya tiene txtref.
    function recepciones_con_volumen(int $codgas, string $fechaDesde, string $fechaHasta): array {
        if (!isset($this->linked_server[$codgas]) || !isset($this->short_databases[$codgas])) return [];
        $serverIp = $this->linked_server[$codgas];
        $database = $this->short_databases[$codgas];
        $fchtrnDesde = dateToInt($fechaDesde);
        $fchtrnHasta = dateToInt($fechaHasta);

        $query = "
            SELECT * FROM OPENQUERY(" . $serverIp . ", '
                SELECT
                    M.nrotrn, M.fchtrn,
                    CONVERT(DATE, DATEADD(DAY, -1, M.fchtrn)) AS fecha,
                    M.volrec AS VolumenRecibido, T.den AS Producto, DC.txtref
                FROM " . $database . ".[MovimientosTan] M
                    LEFT JOIN " . $database . ".[Tanques] T ON M.codtan = T.cod AND M.codgas = T.codgas
                    LEFT JOIN " . $database . ".[Documentos] D ON M.nrodoc = D.nro AND M.codgas = D.codgas AND D.tip = 1 AND D.nroitm = 1
                    LEFT JOIN " . $database . ".[DocumentosC] DC ON M.nrodoc = DC.nro AND M.codgas = DC.codgas AND DC.tip = 1
                WHERE M.nroitm NOT IN (0,1,3,4) AND M.tiptrn = 3
                  AND M.fchtrn BETWEEN " . $fchtrnDesde . " AND " . $fchtrnHasta . "
                  AND M.codgas = " . $codgas . "
                ORDER BY M.nrotrn DESC
            ');
        ";
        return $this->sql->select($query);
    }

    // Sugerencia por lote: dada una estación con código externo mapeado
    // para $proveedorRfc, empareja cada recepción SIN txtref (o cuyo
    // txtref no matchea ninguna factura conocida) con la factura del
    // proveedor más cercana en LITROS dentro del rango — el mismo criterio
    // ya validado para Petrotal (fecha puede variar varios días, litros es
    // la llave confiable). No consume facturas ya usadas por otra recepción
    // en la misma corrida (evita sugerir la misma factura dos veces).
    function sugerir_recepciones_por_volumen(int $codgas, string $proveedorRfc, string $fechaDesde, string $fechaHasta): array {
        $recepciones = $this->recepciones_con_volumen($codgas, $fechaDesde, $fechaHasta);
        $facturas = $this->buscar_facturas_proveedor_por_codigo_externo_directo($codgas, $proveedorRfc, $fechaDesde, $fechaHasta);

        $facturasDisponibles = $facturas;
        $filas = [];

        foreach ($recepciones as $r) {
            $volumen = (float)($r['VolumenRecibido'] ?? 0);
            $ref = $this->parse_txtref($r['txtref'] ?? null);
            // Un txtref con folio "PET..." es de Petrotal, no del proveedor
            // real — nunca intentar el match exacto contra facturas de
            // Tesoro/otro proveedor en ese caso, aunque la Remision
            // coincida por casualidad de formato (bug real encontrado
            // 2026-09-04: remisión de Petrotal '451302188' coincidió con
            // una factura de Tesoro no relacionada, dando un falso "exacto"
            // con diferencia de litros de 27937 vs 14467).
            if ($ref && preg_match('/^PET/i', $ref['folio'])) {
                $ref = null;
            }

            $facturaExacta = null;
            if ($ref) {
                foreach ($facturasDisponibles as $f) {
                    $remNorm = $ref['remision'] ? $this->normalizar_remision($ref['remision']) : '';
                    $folioNorm = $ref['folio'] ? $this->normalizar_folio($ref['folio']) : '';
                    if (($f['Remision'] && $remNorm && $this->normalizar_remision($f['Remision']) === $remNorm) ||
                        ($f['Folio'] && $folioNorm && $this->normalizar_folio($f['Folio']) === $folioNorm)) {
                        $facturaExacta = $f;
                        break;
                    }
                }
            }

            // Defensa en profundidad: aunque el texto de la remisión
            // coincida, un match "exacto" cuya diferencia de litros es
            // absurda (>20%) probablemente es una coincidencia de formato,
            // no la misma entrega — se degrada a litros en vez de confiar
            // ciegamente en el texto.
            if ($facturaExacta) {
                $litrosFactura = (float)($facturaExacta['Litros'] ?? 0);
                $diferenciaRelativa = $volumen > 0 ? abs($litrosFactura - $volumen) / $volumen : 1.0;
                if ($diferenciaRelativa > 0.20) {
                    $facturaExacta = null;
                }
            }

            if ($facturaExacta) {
                $filas[] = [
                    'recepcion' => $r,
                    'factura_proveedor' => $facturaExacta,
                    'confianza' => 'exacta_remision',
                ];
                $facturasDisponibles = array_values(array_filter($facturasDisponibles, fn($f) => $f['Id'] !== $facturaExacta['Id']));
                continue;
            }

            if (!$facturasDisponibles) {
                $filas[] = ['recepcion' => $r, 'factura_proveedor' => null, 'confianza' => null];
                continue;
            }

            $candidatas = $facturasDisponibles;
            usort($candidatas, function ($a, $b) use ($volumen) {
                $diffA = abs((float)($a['Litros'] ?? 0) - $volumen);
                $diffB = abs((float)($b['Litros'] ?? 0) - $volumen);
                return $diffA <=> $diffB;
            });

            $mejor = $candidatas[0];
            $diferencia = abs((float)($mejor['Litros'] ?? 0) - $volumen);
            $filas[] = [
                'recepcion' => $r,
                'factura_proveedor' => $mejor,
                'confianza' => $diferencia <= 1.0 ? 'exacta_litros' : 'aproximada_litros',
            ];
            $facturasDisponibles = array_values(array_filter($facturasDisponibles, fn($f) => $f['Id'] !== $mejor['Id']));
        }

        return $filas;
    }

    // Facturas del proveedor real ya identificadas para una estación (vía
    // EstacionesCodigosExternos), sin exigir ReceptorNombre='PETROTAL' —
    // a diferencia de buscar_facturas_proveedor_hacia_petrotal, aquí se
    // busca la factura del proveedor tal cual la recibió TotalGas
    // (compra directa a esa estación), que es lo relevante para Mis
    // Recepciones.
    function buscar_facturas_proveedor_por_codigo_externo_directo(int $codgas, string $proveedorRfc, string $fechaDesde, string $fechaHasta): array {
        // SUM(Cantidad)/GROUP BY: mismo motivo que en
        // buscar_facturas_proveedor_hacia_petrotal (19% de las facturas de
        // Tesoro traen más de un concepto).
        $query = "
            SELECT fr.Id, fr.Folio, fr.Remision, fr.UUID, fr.EmisorNombre, fr.EmisorRfc, fr.Fecha, fr.Total,
                   fr.RutaArchivo, fr.NombreArchivo, fr.RutaXml, fr.NombreXml,
                   MAX(fc.Descripcion) AS Producto, SUM(fc.Cantidad) AS Litros
            FROM TG.dbo.FacturasRecibidas fr
            JOIN TG.dbo.EstacionesCodigosExternos ece
                ON ece.proveedor_rfc = fr.EmisorRfc
               AND ece.codigo_externo = fr.PresentacionTesoro
               AND ece.activo = 1
            LEFT JOIN TG.dbo.FacturasRecibidasConceptos fc ON fc.FacturaId = fr.Id
            WHERE ece.codgas = :codgas
              AND fr.EmisorRfc = :proveedorRfc
              AND fr.Fecha BETWEEN :fechaDesde AND :fechaHasta
            GROUP BY fr.Id, fr.Folio, fr.Remision, fr.UUID, fr.EmisorNombre, fr.EmisorRfc, fr.Fecha, fr.Total,
                     fr.RutaArchivo, fr.NombreArchivo, fr.RutaXml, fr.NombreXml
        ";
        return $this->sql->select($query, [
            'codgas' => $codgas,
            'proveedorRfc' => $proveedorRfc,
            'fechaDesde' => $fechaDesde,
            'fechaHasta' => $fechaHasta,
        ]);
    }

    function ya_asignada(int $facturaProveedorId, ?int $facturaPetrotalId): ?array {
        if ($facturaPetrotalId === null) {
            $query = "SELECT Id FROM TG.dbo.FacturasMovimientosTanques
                       WHERE FacturaProveedorId = :facturaProveedorId
                         AND FacturaPetrotalId IS NULL
                         AND Activo = 1";
            $r = $this->sql->select($query, ['facturaProveedorId' => $facturaProveedorId]);
            return $r[0] ?? null;
        }
        $query = "SELECT Id FROM TG.dbo.FacturasMovimientosTanques
                   WHERE FacturaProveedorId = :facturaProveedorId
                     AND FacturaPetrotalId = :facturaPetrotalId
                     AND Activo = 1";
        $r = $this->sql->select($query, [
            'facturaProveedorId' => $facturaProveedorId,
            'facturaPetrotalId' => $facturaPetrotalId,
        ]);
        return $r[0] ?? null;
    }

    // $facturaPetrotalId null + $tipoOperacion=1: recepción ligada
    // directamente a la factura del proveedor real, sin Petrotal de por
    // medio (habilita la descarga del PDF/XML desde Mis Recepciones).
    // $tipoOperacion=2 (default, caso Petrotal) exige $facturaPetrotalId.
    function confirmar_asignacion(int $nrotrn, int $codgas, int $facturaProveedorId, ?int $facturaPetrotalId, string $usuario, int $tipoOperacion = 2): void {
        $esPetrotal = $tipoOperacion === 2 ? 1 : 0;
        $query = "
            INSERT INTO TG.dbo.FacturasMovimientosTanques
                (nrotrn, codgas, TipoOperacion, FacturaProveedorId, FacturaPetrotalId,
                 FechaAsignacion, UsuarioAsignacion, Activo, Petrotal)
            VALUES (:nrotrn, :codgas, :tipoOperacion, :facturaProveedorId, :facturaPetrotalId,
                    GETDATE(), :usuario, 1, :esPetrotal)
        ";
        $this->sql->insert($query, [
            'nrotrn' => $nrotrn, 'codgas' => $codgas, 'tipoOperacion' => $tipoOperacion,
            'facturaProveedorId' => $facturaProveedorId, 'facturaPetrotalId' => $facturaPetrotalId,
            'usuario' => $usuario, 'esPetrotal' => $esPetrotal,
        ]);
    }

    function deshacer_asignacion(int $id, string $usuario): void {
        $query = "UPDATE TG.dbo.FacturasMovimientosTanques
                   SET Activo = 0,
                       Observaciones = CONCAT(ISNULL(Observaciones,''), ' [Deshecho por ', :usuario, ' ', CONVERT(varchar, GETDATE(), 120), ']')
                   WHERE Id = :id";
        $this->sql->update($query, compact('id', 'usuario'));
    }
}
