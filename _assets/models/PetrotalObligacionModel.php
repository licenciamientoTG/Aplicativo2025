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
    // en ese caso no se adivina: se listan los candidatos para que el
    // controlador pida desambiguación en el preview.
    public function resolver_cliente(string $rfc): ?array {
        $rows = $this->sql->select(
            "SELECT DISTINCT NumeroPermisoCRE FROM TG.dbo.XmlCre WHERE Rfc = ?",
            [$rfc]
        );
        if (!$rows) return null;
        $permisos = array_column($rows, 'NumeroPermisoCRE');
        return [
            'permiso_cre' => count($permisos) === 1 ? $permisos[0] : null,
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
            SELECT fr.Id AS FacturaId, fr.Fecha, fr.Folio, fr.Total,
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
}
