<?php
/**
 * Análisis ejecutivo por IA del DASHBOARD de /merma/ventas.
 * Uno por (anio, mes, fecha de generación); schema en
 * docs/sql/merma_ventas_analisis_ia.sql.
 */
class MermaVentasAnalisisIaModel extends Model
{
    /**
     * El más reciente del mes, con el nombre de quien lo generó. selectSafe
     * para que /merma/ventas no truene si la tabla aún no existe.
     */
    public function get_ultimo(int $anio, int $mes): ?array
    {
        $r = $this->sql->selectSafe(
            'SELECT TOP 1 a.*, u.Nombre AS usuario_nombre
             FROM [TG].[dbo].[merma_ventas_analisis_ia] a
             LEFT JOIN [TG].[dbo].[Usuario] u ON u.Id = a.usuario
             WHERE a.anio = ? AND a.mes = ?
             ORDER BY a.fecha DESC, a.creado_en DESC;',
            [$anio, $mes]
        );
        return $r ? $r[0] : null;
    }

    /** Guarda el análisis de hoy; si ya había uno hoy para ese mes, lo reemplaza. */
    public function guardar(int $anio, int $mes, string $texto, string $modelo, int $usuario): bool
    {
        $hoy = date('Y-m-d');
        $afectadas = $this->sql->updateSafe(
            'UPDATE [TG].[dbo].[merma_ventas_analisis_ia]
             SET texto = ?, modelo = ?, usuario = ?, creado_en = GETDATE()
             WHERE anio = ? AND mes = ? AND fecha = ?;',
            [$texto, $modelo, $usuario, $anio, $mes, $hoy]
        );
        if ($afectadas === false) return false;
        if ($afectadas > 0) return true;
        return (bool) $this->sql->insert(
            'INSERT INTO [TG].[dbo].[merma_ventas_analisis_ia] (anio, mes, fecha, texto, modelo, usuario)
             VALUES (?, ?, ?, ?, ?, ?);',
            [$anio, $mes, $hoy, $texto, $modelo, $usuario]
        );
    }
}
