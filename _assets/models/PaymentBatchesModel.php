<?php
/**
 * Modelo de lotes de pago (cada lote = una tanda de pago: un comprobante que
 * liquida N facturas en una fecha). Una requisición puede tener varios lotes.
 */
class PaymentBatchesModel extends Model
{
    public $id;
    public $fecha_pago;
    public $referencia;
    public $banco;
    public $emp_cod;
    public $provider_cod;
    public $monto_total;
    public $observaciones;
    public $created_by;
    public $created_at;

    /**
     * Crea la cabecera de un lote y devuelve su id.
     */
    public function create(array $data): int|false
    {
        $query = "
            INSERT INTO [TG].[dbo].[payment_batches]
            (fecha_pago, referencia, banco, emp_cod, provider_cod, monto_total, observaciones, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ";
        return $this->sql->insert($query, [
            $data['fecha_pago'],
            $data['referencia']    ?? null,
            $data['banco']         ?? null,
            $data['emp_cod']       ?? null,
            $data['provider_cod']  ?? null,
            $data['monto_total']   ?? 0,
            $data['observaciones'] ?? null,
            $data['created_by']    ?? null,
        ]);
    }

    public function get_by_id($id): ?array
    {
        $r = $this->sql->select("SELECT * FROM [TG].[dbo].[payment_batches] WHERE id = ?", [$id]);
        return $r ? $r[0] : null;
    }
}
