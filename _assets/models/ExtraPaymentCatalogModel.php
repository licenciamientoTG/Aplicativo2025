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
     * Razones sociales que pagan (cod, den, rfc): catálogo propio TG.dbo.razones_sociales_pago
     * (docs/sql/razones_sociales_pago.sql), solo activas. Los códigos de ControlGas se conservan.
     */
    public function empresas() : array {
        $query = '
            SELECT cod, den, rfc
            FROM [TG].[dbo].[razones_sociales_pago]
            WHERE activo = 1
            ORDER BY den ASC
        ';
        return $this->sql->select($query, []) ?: [];
    }

    /**
     * Cuentas de proveedor que se pueden elegir en un renglón: las del catálogo que entraron
     * por una alta de cuenta APROBADA (Terceros, activas). El nombre es el proveedor de la alta.
     */
    private function cuentas_proveedor_query(string $filtro, int $top) : string {
        return '
            SELECT TOP ' . $top . ' c.Id AS id, sol.proveedor, c.Banco AS banco, c.CuentaLocal AS cuenta,
                   c.Clave AS clabe, c.Divisa AS divisa
              FROM [TG].[dbo].[CatalogosCuentasBancarias] c
             CROSS APPLY (SELECT TOP 1 r.proveedor
                            FROM [TG].[dbo].[bank_account_requests] r
                           WHERE r.catalogo_cuenta_id = c.Id AND r.status = 2
                           ORDER BY r.id DESC) sol
             WHERE c.Activo = 1 AND c.Tipo = \'Terceros\' ' . $filtro . '
             ORDER BY sol.proveedor, c.Banco, c.Id;';
    }

    /** Buscador (mínimo 3 letras): por proveedor, titular, cuenta o CLABE. Hasta 20. */
    public function buscar_cuentas_proveedor(string $q) : array {
        $like = '%' . strtr($q, ['[' => '[[]', '%' => '[%]', '_' => '[_]']) . '%';
        $rows = $this->sql->select(
            $this->cuentas_proveedor_query('AND (sol.proveedor LIKE ? OR c.Descripcion LIKE ? OR c.TitularCuenta LIKE ? OR c.CuentaLocal LIKE ? OR c.Clave LIKE ?)', 20),
            [$like, $like, $like, $like, $like]
        ) ?: [];
        return $this->con_pagadores($rows);
    }

    /** Una cuenta elegible por Id (para validar al guardar y para mostrar al editar); null si no lo es. */
    public function cuenta_proveedor(int $id) : ?array {
        if ($id <= 0) {
            return null;
        }
        $rows = $this->sql->select($this->cuentas_proveedor_query('AND c.Id = ?', 1), [$id]) ?: [];
        $rows = $this->con_pagadores($rows);
        return $rows[0] ?? null;
    }

    /** Agrega a cada cuenta las razones sociales que pueden pagarle (bank_account_payers). */
    private function con_pagadores(array $rows) : array {
        if (!$rows) {
            return [];
        }
        $ids = array_map(fn($r) => (int) $r['id'], $rows);
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $pag = [];
        try {
            foreach ($this->sql->select(
                'SELECT catalogo_cuenta_id AS id, emp_cod FROM [TG].[dbo].[bank_account_payers] WHERE catalogo_cuenta_id IN (' . $marcas . ');',
                $ids
            ) ?: [] as $p) {
                $pag[(int) $p['id']][] = (int) $p['emp_cod'];
            }
        } catch (Throwable $e) {
            error_log('ExtraPaymentCatalogModel::con_pagadores: ' . $e->getMessage());
        }
        foreach ($rows as &$r) {
            $num = trim((string) ($r['clabe'] ?: $r['cuenta']));
            $r['proveedor'] = trim((string) $r['proveedor']);
            $r['terminacion'] = $num !== '' ? substr($num, -4) : '';
            $r['pagadores'] = $pag[(int) $r['id']] ?? [];
            $r['texto'] = $r['proveedor'] . ' · ' . trim((string) $r['banco']) . ($r['terminacion'] !== '' ? ' ···' . $r['terminacion'] : '');
        }
        unset($r);
        return $rows;
    }
}
