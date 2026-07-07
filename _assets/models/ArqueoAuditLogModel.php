<?php

/**
 * Log de auditoría del módulo arqueo: quién, cuándo, qué acción y qué
 * cambió (JSON antes/después). Patrón de PaymentRequestAuditLogModel.
 * Tabla: [TG].[dbo].[arqueo_audit_log]
 */
class ArqueoAuditLogModel extends Model
{
    const ACC_CREAR_SESION        = 'CREAR_SESION';
    const ACC_ABRIR_SESION        = 'ABRIR_SESION';
    const ACC_CERRAR_SESION       = 'CERRAR_SESION';
    const ACC_GUARDAR_CAJA        = 'GUARDAR_CAJA';
    const ACC_EDITAR_CONCENTRADO  = 'EDITAR_CONCENTRADO';
    const ACC_EDITAR_CAPITAL_BASE = 'EDITAR_CAPITAL_BASE';

    /**
     * Inserta un movimiento. $antes/$despues son arrays (o null) y se
     * serializan a JSON. Devuelve false si el INSERT falla.
     */
    public function log(
        string $accion,
        int $sesion_id,
        ?int $caja_id,
        ?int $sucursal_id,
        ?array $antes,
        ?array $despues,
        ?int $usuario_id,
        ?string $usuario_nombre
    ): bool {
        return (bool) $this->sql->insert(
            "INSERT INTO [TG].[dbo].[arqueo_audit_log]
                (sesion_id, caja_id, sucursal_id, accion, usuario_id,
                 usuario_nombre, datos_anteriores, datos_nuevos)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?);",
            [
                $sesion_id,
                $caja_id,
                $sucursal_id,
                $accion,
                $usuario_id,
                $usuario_nombre,
                $antes === null ? null : json_encode($antes, JSON_UNESCAPED_UNICODE),
                $despues === null ? null : json_encode($despues, JSON_UNESCAPED_UNICODE),
            ]
        );
    }

    /** Movimientos de una sesión, más reciente primero. */
    public function by_sesion(int $sesion_id): array
    {
        return $this->sql->select(
            "SELECT * FROM [TG].[dbo].[arqueo_audit_log]
             WHERE sesion_id = ?
             ORDER BY fecha DESC, id DESC;",
            [$sesion_id]
        ) ?: [];
    }
}
