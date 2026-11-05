<?php

/**
 * Bitácora de auditoría de la programación de recepciones de combustible
 * (/supply/scheduling): quién, cuándo, qué acción y qué cambió (JSON
 * antes/después). Patrón de ArqueoAuditLogModel.
 * Tabla: [TG].[dbo].[fuel_reception_audit_log]
 *
 * Nunca debe romper la operación que audita: si el INSERT falla se deja el
 * error en el log de PHP y se sigue.
 */
class FuelReceptionAuditLogModel extends Model
{
    const ACC_CREAR              = 'CREAR';
    const ACC_IMPORTAR           = 'IMPORTAR';
    const ACC_EDITAR             = 'EDITAR';
    const ACC_CAMBIAR_FECHA      = 'CAMBIAR_FECHA';
    const ACC_CANCELAR           = 'CANCELAR';
    const ACC_DUPLICAR           = 'DUPLICAR';
    const ACC_CAMBIO_ESTATUS     = 'CAMBIO_ESTATUS';
    const ACC_VINCULAR_FACTURA   = 'VINCULAR_FACTURA';
    const ACC_DESVINCULAR_FACTURA = 'DESVINCULAR_FACTURA';

    /**
     * Registra un movimiento. $antes/$despues son arrays (o null) y se
     * serializan a JSON. El usuario sale de la sesión salvo que se pase
     * $userId. Devuelve false si el INSERT falla.
     */
    public function log(string $accion, ?int $scheduleId, ?array $antes, ?array $despues, ?int $userId = null): bool
    {
        try {
            $sesion = $_SESSION['tg_user'] ?? [];
            $id = $userId ?? (isset($sesion['Id']) ? (int)$sesion['Id'] : null);
            $nombre = $sesion['Nombre'] ?? $sesion['Usuario'] ?? null;
            $this->sql->insert(
                "INSERT INTO [TG].[dbo].[fuel_reception_audit_log]
                    (schedule_id, accion, usuario_id, usuario_nombre, datos_anteriores, datos_nuevos)
                 VALUES (?, ?, ?, ?, ?, ?);",
                [
                    $scheduleId,
                    $accion,
                    $id ?: null,
                    $nombre !== null ? mb_substr((string)$nombre, 0, 150) : null,
                    $antes === null ? null : json_encode($antes, JSON_UNESCAPED_UNICODE),
                    $despues === null ? null : json_encode($despues, JSON_UNESCAPED_UNICODE),
                ]
            );
            return true;
        } catch (Throwable $e) {
            error_log('FuelReceptionAuditLogModel::log (' . $accion . ' #' . $scheduleId . '): ' . $e->getMessage());
            return false;
        }
    }

    /** Movimientos de una recepción, más reciente primero. */
    public function by_schedule(int $scheduleId): array
    {
        return $this->sql->select(
            "SELECT * FROM [TG].[dbo].[fuel_reception_audit_log]
             WHERE schedule_id = ?
             ORDER BY fecha DESC, id DESC;",
            [$scheduleId]
        ) ?: [];
    }
}
