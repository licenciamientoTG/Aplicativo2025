<?php
/**
 * Catálogos de pagos extraordinarios: motivos y empresas.
 */
class ExtraPaymentCatalogModel extends Model
{
    /**
     * Motivos activos ordenados por `orden`.
     */
    public function reasons() : array {
        $query = '
            SELECT id, nombre, requiere_texto, activo, orden
            FROM [TG].[dbo].[extra_payment_reasons]
            WHERE activo = 1
            ORDER BY orden ASC, id ASC
        ';
        return $this->sql->select($query, []) ?: [];
    }

    /**
     * Empresas (cod, den) de SG12.dbo.Empresas. La tabla no tiene columna de
     * estatus, por lo que se devuelven todas.
     */
    public function empresas() : array {
        $query = '
            SELECT cod, den
            FROM [SG12].[dbo].[Empresas]
            ORDER BY den ASC
        ';
        return $this->sql->select($query, []) ?: [];
    }
}
