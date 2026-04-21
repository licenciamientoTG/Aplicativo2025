<?php
class RenegociacionModel {
    public $sql;
    function __construct() {
        $this->sql = MySqlPdoHandler::getInstance();
        $this->sql->connect('TG');
    }

    public function get_all(int $upload_id, ?string $responsable = null, ?string $renegociado = null): array|false {
        $params = [$upload_id];
        $where  = '';
        if ($responsable) {
            $where   .= ' AND p.responsable = ?';
            $params[] = $responsable;
        }
        if ($renegociado !== null && $renegociado !== '') {
            $where   .= ' AND p.renegociado = ?';
            $params[] = (int)$renegociado;
        }
        $query = "SELECT p.id, p.oc, p.proveedor, p.razon_social, p.factura,
                         p.fecha_factura, p.total, p.importe_dlls, p.responsable,
                         p.rfc, p.fecha_vencimiento, p.dias_vencimiento, p.concepto,
                         p.metodo_pago, p.fecha_pago_real, p.subtotal, p.impt_iva,
                         p.iva, p.cc, p.autoriza_oc, p.renegociado
                  FROM [TG].[dbo].[reneg_pagos_pendientes] p
                  WHERE p.upload_id = ?{$where}
                  ORDER BY p.responsable, p.proveedor";
        return $this->sql->select($query, $params) ?: false;
    }

    public function toggle_renegociado(int $id, int $valor): bool {
        $query = "UPDATE [TG].[dbo].[reneg_pagos_pendientes]
                  SET renegociado = ?, fecha_renegociado = ?
                  WHERE id = ?";
        $fecha = $valor ? date('Y-m-d H:i:s') : null;
        $this->sql->update($query, [$valor, $fecha, $id]);
        return true;
    }

    public function get_by_responsable(int $upload_id): array|false {
        $query = "SELECT p.responsable,
                         COUNT(*) AS total_facturas,
                         SUM(p.total) AS monto_total,
                         SUM(ISNULL(p.importe_dlls, 0)) AS monto_dlls
                  FROM [TG].[dbo].[reneg_pagos_pendientes] p
                  WHERE p.upload_id = ?
                  GROUP BY p.responsable
                  ORDER BY p.responsable";
        return $this->sql->select($query, [$upload_id]) ?: false;
    }

    public function get_facturas_responsable(int $upload_id, string $responsable): array|false {
        $query = "SELECT oc, proveedor, razon_social, factura,
                         fecha_factura, total, importe_dlls
                  FROM [TG].[dbo].[reneg_pagos_pendientes]
                  WHERE upload_id = ? AND responsable = ? AND renegociado = 0
                  ORDER BY proveedor, factura";
        return $this->sql->select($query, [$upload_id, $responsable]) ?: false;
    }

    public function insert_batch(array $rows, int $upload_id): int {
        $this->sql->beginTransaction();
        $count = 0;
        try {
            $query = "INSERT INTO [TG].[dbo].[reneg_pagos_pendientes]
                        (upload_id, oc, req, fecha_aut, rfc, proveedor, responsable,
                         razon_social, factura, fecha_factura, iva, subtotal, impt_iva,
                         descuento, ret_4pct, iva_ret, isr_ret, total, importe_dlls,
                         cc, concepto, prov, autoriza_oc, metodo_pago,
                         fecha_vencimiento, dias_vencimiento, fecha_pago_real)
                      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
            foreach ($rows as $r) {
                $this->sql->insert($query, [
                    $upload_id,
                    $r['oc'], $r['req'], $r['fecha_aut'], $r['rfc'],
                    $r['proveedor'], $r['responsable'], $r['razon_social'],
                    $r['factura'], $r['fecha_factura'], $r['iva'],
                    $r['subtotal'], $r['impt_iva'], $r['descuento'],
                    $r['ret_4pct'], $r['iva_ret'], $r['isr_ret'],
                    $r['total'], $r['importe_dlls'], $r['cc'],
                    $r['concepto'], $r['prov'], $r['autoriza_oc'],
                    $r['metodo_pago'], $r['fecha_vencimiento'],
                    $r['dias_vencimiento'], $r['fecha_pago_real']
                ]);
                $count++;
            }
            $this->sql->commit();
        } catch (Exception $e) {
            $this->sql->rollBack();
            throw $e;
        }
        return $count;
    }

    public function get_latest_upload(): array|false {
        $query = "SELECT TOP 1 id, nombre_archivo, total_filas, created_at
                  FROM [TG].[dbo].[reneg_uploads]
                  ORDER BY created_at DESC";
        $result = $this->sql->select($query);
        return $result ? $result[0] : false;
    }

    public function get_uploads_history(): array|false {
        $query = "SELECT id, nombre_archivo, total_filas, created_at
                  FROM [TG].[dbo].[reneg_uploads]
                  ORDER BY created_at DESC";
        return $this->sql->select($query) ?: false;
    }

    public function create_upload(string $nombre_archivo, int $total_filas, ?int $user_id): int {
        $query = "INSERT INTO [TG].[dbo].[reneg_uploads]
                    (nombre_archivo, total_filas, uploaded_by)
                  VALUES (?, ?, ?)";
        return $this->sql->insert($query, [$nombre_archivo, $total_filas, $user_id]);
    }

    public function get_responsables(int $upload_id): array|false {
        $query = "SELECT DISTINCT responsable
                  FROM [TG].[dbo].[reneg_pagos_pendientes]
                  WHERE upload_id = ?
                  ORDER BY responsable";
        return $this->sql->select($query, [$upload_id]) ?: false;
    }
}
