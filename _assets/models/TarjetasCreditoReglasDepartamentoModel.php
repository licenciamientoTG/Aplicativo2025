<?php
/**
 * Reglas de auto-clasificación de Departamento por concepto, para
 * tarjetas de crédito (/tesoreria/movimientos_bancos_cheques). Se aplican
 * al importar un cargo nuevo (si no trae departamento capturado) y bajo
 * demanda vía "Reaplicar reglas" — nunca pisan una edición manual ya hecha.
 * Schema: docs/sql/tarjetas_credito_reglas_departamento.sql
 */
class TarjetasCreditoReglasDepartamentoModel extends Model
{
    public function all(): array
    {
        return $this->sql->select(
            'SELECT r.id, r.palabra_clave, r.departamento_id, d.Nombre AS departamento
             FROM [TG].[dbo].[tarjetas_credito_reglas_departamento] r
             JOIN [TG].[dbo].[Departamentos] d ON d.Id = r.departamento_id
             WHERE r.activo = 1
             ORDER BY d.Nombre, r.palabra_clave;'
        ) ?: [];
    }

    public function create(string $palabraClave, int $departamentoId, ?int $userId): bool
    {
        return (bool) $this->sql->insert(
            'INSERT INTO [TG].[dbo].[tarjetas_credito_reglas_departamento]
                (palabra_clave, departamento_id, created_by)
             VALUES (?, ?, ?);',
            [trim($palabraClave), $departamentoId, $userId]
        );
    }

    public function delete(int $id): bool
    {
        return (bool) $this->sql->delete(
            'DELETE FROM [TG].[dbo].[tarjetas_credito_reglas_departamento] WHERE id = ?;',
            [$id]
        );
    }

    /**
     * Departamento que le corresponde a una descripción de cargo, según las
     * reglas activas ("contiene", sin distinguir mayúsculas/minúsculas). Si
     * varias reglas matchean, gana la de palabra_clave más larga: evita que
     * una palabra genérica ("SAN") opaque a una más específica ("ANTHROPIC")
     * sin que el usuario tenga que pensar en el orden de captura.
     */
    public function resolver(string $descripcion): ?string
    {
        $reglas = $this->all();
        $mejor = null;
        foreach ($reglas as $r) {
            if (stripos($descripcion, $r['palabra_clave']) === false) continue;
            if ($mejor === null || mb_strlen($r['palabra_clave']) > mb_strlen($mejor['palabra_clave'])) {
                $mejor = $r;
            }
        }
        return $mejor['departamento'] ?? null;
    }

    /**
     * Aplica las reglas a los movimientos de tarjeta de crédito que sigan
     * sin departamento capturado (nunca a los que ya tienen algo, sea de una
     * regla anterior o de una edición manual). Usado tanto al importar como
     * por el botón "Reaplicar reglas".
     *
     * @return int cantidad de movimientos actualizados
     */
    public function aplicar_a_pendientes(): int
    {
        $pendientes = $this->sql->select(
            "SELECT id, descripcion FROM [TG].[dbo].[tarjetas_credito_movimientos]
             WHERE departamento IS NULL OR departamento = '';"
        ) ?: [];
        if (!$pendientes) return 0;

        $actualizados = 0;
        foreach ($pendientes as $m) {
            $departamento = $this->resolver($m['descripcion']);
            if ($departamento === null) continue;
            $this->sql->update(
                'UPDATE [TG].[dbo].[tarjetas_credito_movimientos] SET departamento = ? WHERE id = ?;',
                [$departamento, $m['id']]
            );
            $actualizados++;
        }
        return $actualizados;
    }
}
