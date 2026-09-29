<?php

/**
 * Peticiones de descarga masiva del SAT (TGV2.dbo.FacturasPeticiones) y su
 * bitácora de errores (TGV2.dbo.ApiFailures), ambas escritas por ApiTotal.
 *
 * Estados de FacturasPeticiones: 0 = completada, 1 = pendiente (se sigue
 * consultando), 2 = rechazada por el SAT, 3 = vencida/fallida en el SAT,
 * 4 = marcada como error a mano desde el aplicativo (ya no se consulta).
 */
class SatPeticionesModel extends Model
{
    public const ESTADO_PENDIENTE     = 1;
    public const ESTADO_ERROR_MANUAL  = 4;

    // Hasta este Id, ApiTotal guardó FailedAt con día y mes intercambiados
    // (bug de formato de fecha corregido el 2026-09-29)
    public const ULTIMO_ID_FECHA_INVERTIDA = 8837;

    public function get_peticion(int $id): ?array
    {
        $rows = $this->sql->select(
            "SELECT id, peticion, estado, fecha, periodo_inicio, periodo_final, tipo, razon_social, emited
               FROM [TGV2].[dbo].[FacturasPeticiones] WHERE id = ?",
            [$id]
        );
        return $rows[0] ?? null;
    }

    /**
     * Pasa una petición pendiente a "error manual" para que ApiTotal deje de
     * consultarla (todas sus consultas filtran estado = 1).
     *
     * @return int|false filas afectadas (0 si ya no estaba pendiente)
     */
    public function marcar_error(int $id)
    {
        return $this->sql->updateSafe(
            "UPDATE [TGV2].[dbo].[FacturasPeticiones] SET estado = ? WHERE id = ? AND estado = ?",
            [self::ESTADO_ERROR_MANUAL, $id, self::ESTADO_PENDIENTE]
        );
    }

    /** Deja constancia en la misma bitácora de ApiTotal de quién marcó la petición y por qué. */
    public function log_marcado_manual(array $peticion, string $motivo, int $usuarioId, string $usuario): void
    {
        $this->sql->insert(
            "INSERT INTO [TGV2].[dbo].[ApiFailures]
                (Method, Endpoint, ErrorMessage, ErrorCode, RequestData, ResponseData, FailedAt)
             VALUES ('marcar_error_manual', 'aplicativo', ?, 0, ?, 'error_manual', GETDATE())",
            [
                "Petición marcada como error por {$usuario}" . ($motivo !== '' ? ": {$motivo}" : ''),
                json_encode([
                    'razon_social' => $peticion['razon_social'],
                    'peticion'     => $peticion['peticion'],
                    'id'           => (int)$peticion['id'],
                    'emited'       => (int)$peticion['emited'],
                    'tipo'         => (int)$peticion['tipo'],
                    'status'       => 'error_manual',
                    'usuario_id'   => $usuarioId,
                ], JSON_UNESCAPED_UNICODE),
            ]
        );
    }

    /**
     * Últimos errores registrados, opcionalmente de un solo RFC. Se ordena por
     * Id y no por FailedAt porque los registros viejos tienen la fecha invertida.
     */
    public function get_errores(string $rfc = '', int $limite = 300): array
    {
        $params = [];
        $where  = '';
        if ($rfc !== '') {
            $where    = 'WHERE RequestData LIKE ?';
            $params[] = '%"razon_social":"' . $rfc . '"%';
        }
        $rows = $this->sql->select(
            "SELECT TOP ({$limite}) Id, Method, Endpoint, ErrorMessage, ErrorCode, RequestData, ResponseData, FailedAt
               FROM [TGV2].[dbo].[ApiFailures] {$where}
              ORDER BY Id DESC",
            $params ?: null
        ) ?: [];

        foreach ($rows as &$r) {
            $data = json_decode((string)$r['RequestData'], true) ?: [];
            $r['razon_social'] = $data['razon_social'] ?? null;
            $r['peticion']     = $data['peticion'] ?? null;
            $r['emited']       = $data['emited'] ?? null;
            $r['periodo']      = isset($data['start_date'])
                ? substr($data['start_date'], 0, 10) . (isset($data['end_date']) && substr($data['end_date'], 0, 10) !== substr($data['start_date'], 0, 10) ? ' → ' . substr($data['end_date'], 0, 10) : '')
                : null;
            $r['fecha'] = $this->fecha_real($r);
        }
        unset($r);
        return $rows;
    }

    /** Peticiones que ApiTotal sigue consultando (estado 1) y la más vieja. */
    public function get_resumen_pendientes(): array
    {
        $rows = $this->sql->select(
            "SELECT COUNT(*) AS pendientes, MIN(fecha) AS mas_vieja
               FROM [TGV2].[dbo].[FacturasPeticiones] WHERE estado = ?",
            [self::ESTADO_PENDIENTE]
        ) ?: [];
        $r = $rows[0] ?? ['pendientes' => 0, 'mas_vieja' => null];
        return [
            'pendientes' => (int)$r['pendientes'],
            'mas_vieja'  => $r['mas_vieja'] ? date('Y-m-d H:i:s', strtotime($r['mas_vieja'])) : null,
        ];
    }

    /**
     * Fecha del CFDI más reciente entre los últimos 5,000 importados (orden de
     * Id = orden de inserción); evita escanear las tablas completas.
     */
    public function get_ultima_importacion(): array
    {
        $ultima = function (string $tabla): ?string {
            $rows = $this->sql->select(
                "SELECT MAX(Fecha) AS ultima FROM (SELECT TOP 5000 Fecha FROM [TGV2].[dbo].[{$tabla}] ORDER BY Id DESC) t"
            ) ?: [];
            $f = $rows[0]['ultima'] ?? null;
            return $f ? date('Y-m-d H:i:s', strtotime($f)) : null;
        };
        return ['emitidas' => $ultima('Facturas'), 'recibidas' => $ultima('FacturasRecibidas')];
    }

    public static function edad_dias(?string $fecha, ?int $ahora = null): ?float
    {
        if ($fecha === null || $fecha === '') return null;
        return (($ahora ?? time()) - strtotime($fecha)) / 86400;
    }

    public static function semaforo(?float $edadDias, float $umbralAmarillo, float $umbralRojo): string
    {
        if ($edadDias === null)          return 'verde';
        if ($edadDias > $umbralRojo)     return 'rojo';
        if ($edadDias >= $umbralAmarillo) return 'amarillo';
        return 'verde';
    }

    private function fecha_real(array $r): ?string
    {
        if (empty($r['FailedAt'])) return null;
        $ts = strtotime($r['FailedAt']);
        if ((int)$r['Id'] <= self::ULTIMO_ID_FECHA_INVERTIDA) {
            // Se guardó como año-DÍA-MES: se regresa a su orden real
            $ts = mktime((int)date('H', $ts), (int)date('i', $ts), (int)date('s', $ts),
                         (int)date('j', $ts), (int)date('n', $ts), (int)date('Y', $ts));
        }
        return date('Y-m-d H:i:s', $ts);
    }
}
