<?php
/**
 * Reglas de flujo de pagos extraordinarios (sin acceso a BD).
 *
 * Flujo: 1 Pendiente CxP -> 2 Pendiente Admon -> 3 Autorizada -> 4 Pagada.
 * Terminales/laterales: 8 Cancelada, 9 Rechazada (editable y reenviable).
 */
class ExtraPaymentWorkflow
{
    const ST_PEND_CXP   = 1;
    const ST_PEND_ADMON = 2;
    const ST_AUTORIZADA = 3;
    const ST_PAGADA     = 4;
    const ST_CANCELADA  = 8;
    const ST_RECHAZADA  = 9;

    const PERM_CREAR      = 106;
    const PERM_CXP        = 107;
    const PERM_ADMON      = 108;
    const PERM_TESORERIA  = 109;
    const PERM_VER_TODAS  = 110;

    /** Estado al que pasa una solicitud al aprobarse; null si ya no se puede aprobar. */
    public static function next_status_on_approve(int $status): ?int
    {
        return match ($status) {
            self::ST_PEND_CXP   => self::ST_PEND_ADMON,
            self::ST_PEND_ADMON => self::ST_AUTORIZADA,
            default             => null,
        };
    }

    /** Permiso necesario para actuar (aprobar/rechazar/pagar) sobre un estado; null si no aplica. */
    public static function required_permission(int $status): ?int
    {
        return match ($status) {
            self::ST_PEND_CXP   => self::PERM_CXP,
            self::ST_PEND_ADMON => self::PERM_ADMON,
            self::ST_AUTORIZADA => self::PERM_TESORERIA,
            default             => null,
        };
    }

    /** Edición: solo las solicitudes rechazadas (estatus 9) se pueden editar y reenviar. */
    public static function can_edit(int $status): bool
    {
        return $status === self::ST_RECHAZADA;
    }

    public static function can_cancel(int $status): bool
    {
        return in_array($status, [self::ST_PEND_CXP, self::ST_PEND_ADMON, self::ST_RECHAZADA], true);
    }

    /**
     * Valida encabezado y renglones. Devuelve lista de mensajes de error (vacía = válida).
     */
    public static function validate_request(array $header, array $lines): array
    {
        $errors = [];

        if (!empty($header['requiere_texto']) && trim((string)($header['motivo_otros'] ?? '')) === '') {
            $errors[] = 'Debe especificar el motivo cuando el motivo seleccionado es "Otros".';
        }

        if (count($lines) === 0) {
            $errors[] = 'Debe capturar al menos un renglón.';
            return $errors;
        }

        if (($header['tipo'] ?? '') === 'individual' && count($lines) > 1) {
            $errors[] = 'Una solicitud individual solo puede tener un renglón.';
        }

        foreach (array_values($lines) as $i => $line) {
            $n = $i + 1;
            foreach (['empresa_cod' => 'la empresa', 'proveedor' => 'el proveedor', 'descripcion' => 'la descripción'] as $campo => $etiqueta) {
                if (trim((string)($line[$campo] ?? '')) === '') {
                    $errors[] = "Renglón $n: falta $etiqueta.";
                }
            }
            $monto = $line['monto'] ?? null;
            if (!is_numeric($monto) || (float)$monto <= 0) {
                $errors[] = "Renglón $n: el monto debe ser un número mayor a cero.";
            }
        }

        return $errors;
    }

    /** Suma de los montos de cada renglón redondeado a 2 decimales (igual a lo que se guarda). */
    public static function total(array $lines): float
    {
        $sum = 0.0;
        foreach ($lines as $line) {
            $sum += round((float)($line['monto'] ?? 0), 2);
        }
        return round($sum, 2);
    }
}
