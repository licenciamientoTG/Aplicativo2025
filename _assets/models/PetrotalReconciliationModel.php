<?php
class PetrotalReconciliationModel extends Model {

    // RFC fijo de Petrotal, confirmado contra 32 facturas reales en la
    // sesión de diseño (2026-09-01). No varía por estación ni por producto.
    const PETROTAL_RFC = 'PET180213L66';

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
    function buscar_factura_proveedor(string $folioRef, string $remisionRef, string $emisorRfc): ?array {
        $remNorm = $this->normalizar_remision($remisionRef);
        $folioNorm = $this->normalizar_folio($folioRef);

        $query = "SELECT Id, Folio, Remision, EmisorNombre, EmisorRfc, Fecha, Total
                   FROM TG.dbo.FacturasRecibidas WHERE EmisorRfc = :emisorRfc";
        $rows = $this->sql->select($query, ['emisorRfc' => $emisorRfc]);

        foreach ($rows as $row) {
            if ($row['Remision'] && $this->normalizar_remision($row['Remision']) === $remNorm) {
                return ['factura' => $row, 'confianza' => 'exacta_remision'];
            }
        }
        foreach ($rows as $row) {
            if ($this->normalizar_folio($row['Folio']) === $folioNorm) {
                return ['factura' => $row, 'confianza' => 'exacta_folio'];
            }
        }
        return null;
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

    function ya_asignada(int $facturaId): ?array {
        $query = "SELECT Id FROM TG.dbo.FacturasMovimientosTanques
                   WHERE (FacturaProveedorId = :id1 OR FacturaPetrotalId = :id2) AND Activo = 1";
        $r = $this->sql->select($query, ['id1' => $facturaId, 'id2' => $facturaId]);
        return $r[0] ?? null;
    }

    function confirmar_asignacion(int $nrotrn, int $codgas, int $facturaProveedorId, int $facturaPetrotalId, string $usuario): void {
        $query = "
            INSERT INTO TG.dbo.FacturasMovimientosTanques
                (nrotrn, codgas, TipoOperacion, FacturaProveedorId, FacturaPetrotalId,
                 FechaAsignacion, UsuarioAsignacion, Activo, Petrotal)
            VALUES (:nrotrn, :codgas, 2, :facturaProveedorId, :facturaPetrotalId,
                    GETDATE(), :usuario, 1, 1)
        ";
        $this->sql->insert($query, compact('nrotrn', 'codgas', 'facturaProveedorId', 'facturaPetrotalId', 'usuario'));
    }

    function deshacer_asignacion(int $id, string $usuario): void {
        $query = "UPDATE TG.dbo.FacturasMovimientosTanques
                   SET Activo = 0,
                       Observaciones = CONCAT(ISNULL(Observaciones,''), ' [Deshecho por ', :usuario, ' ', CONVERT(varchar, GETDATE(), 120), ']')
                   WHERE Id = :id";
        $this->sql->update($query, compact('id', 'usuario'));
    }
}
