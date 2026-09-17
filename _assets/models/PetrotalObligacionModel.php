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
}
