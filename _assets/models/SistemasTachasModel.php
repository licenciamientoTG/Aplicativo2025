<?php
class SistemasTachasModel extends Model {

    const ALLOWED_USERS = [6382, 6371, 6177, 6296, 6274];
    const EDITORS       = [6274, 6371];
    const SISTEMA_START = '2026-03-30';
    const TARGET_DAYS   = 20; // 4 semanas × 5 días

    private function ids(): string {
        return implode(',', self::ALLOWED_USERS);
    }

    /* ── Usuarios ─────────────────────────────────────── */

    public function get_usuarios(): array {
        return $this->sql->select(
            "SELECT Id, Nombre FROM [TG].[dbo].[Usuario] WHERE Id IN (" . $this->ids() . ") ORDER BY Nombre",
            []
        ) ?: [];
    }

    /* ── Tachas ───────────────────────────────────────── */

    public function get_tachas(string $inicio, string $fin): array {
        return $this->sql->select("
            SELECT
                id,
                usuario_id,
                CONVERT(VARCHAR(10), fecha, 23) AS fecha,
                metodo_pago,
                CAST(pagada AS INT)             AS pagada,
                pagada_por,
                fecha_validacion
            FROM [TG].[dbo].[sistemas_tachas]
            WHERE usuario_id IN (" . $this->ids() . ")
              AND fecha BETWEEN ? AND ?
              AND eliminada = 0
            ORDER BY fecha, usuario_id
        ", [$inicio, $fin]) ?: [];
    }

    public function add_tacha(int $usuario_id, string $fecha, int $creado_por): array {
        $existing = $this->sql->select("
            SELECT id FROM [TG].[dbo].[sistemas_tachas]
            WHERE usuario_id = ? AND fecha = ? AND eliminada = 0
        ", [$usuario_id, $fecha]);

        if (!empty($existing)) {
            return ['success' => false, 'message' => 'Ya existe una tacha para ese día'];
        }

        $this->sql->insert("
            INSERT INTO [TG].[dbo].[sistemas_tachas] (usuario_id, fecha, creado_por, created_at)
            VALUES (?, ?, ?, GETDATE())
        ", [$usuario_id, $fecha, $creado_por]);

        return ['success' => true];
    }

    public function remove_tacha(int $id, int $eliminada_por): array {
        $this->sql->update("
            UPDATE [TG].[dbo].[sistemas_tachas]
            SET eliminada = 1, eliminada_por = ?, fecha_eliminacion = GETDATE()
            WHERE id = ? AND eliminada = 0
        ", [$eliminada_por, $id]);
        return ['success' => true];
    }

    public function set_metodo(int $id, string $metodo): array {
        if (!in_array($metodo, ['tiempo', 'dinero', 'servicio'])) {
            return ['success' => false, 'message' => 'Método inválido'];
        }
        $this->sql->update("
            UPDATE [TG].[dbo].[sistemas_tachas]
            SET metodo_pago = ?, pagada = 0, pagada_por = NULL, fecha_validacion = NULL
            WHERE id = ? AND eliminada = 0
        ", [$metodo, $id]);
        return ['success' => true];
    }

    public function validar_pago(int $id, int $pagada_por): array {
        $this->sql->update("
            UPDATE [TG].[dbo].[sistemas_tachas]
            SET pagada = 1, pagada_por = ?, fecha_validacion = GETDATE()
            WHERE id = ? AND eliminada = 0
        ", [$pagada_por, $id]);
        return ['success' => true];
    }

    public function pagar_efectivo(int $pagada_por): array {
        $this->sql->update("
            UPDATE [TG].[dbo].[sistemas_tachas]
            SET pagada = 1, pagada_por = ?, fecha_validacion = GETDATE()
            WHERE metodo_pago = 'dinero' AND pagada = 0 AND eliminada = 0
              AND usuario_id IN (" . $this->ids() . ")
        ", [$pagada_por]);
        return ['success' => true];
    }

    /* ── Home Office ──────────────────────────────────── */

    public function programar_ho(int $usuario_id, string $fecha, int $programado_por): array {
        $today = date('Y-m-d');

        if ($fecha <= $today) {
            return ['success' => false, 'message' => 'La fecha debe ser futura'];
        }
        if ((int)date('N', strtotime($fecha)) >= 6) {
            return ['success' => false, 'message' => 'Debe ser un día hábil (Lun–Vie)'];
        }

        $existing = $this->sql->select("
            SELECT id FROM [TG].[dbo].[sistemas_home_office]
            WHERE usuario_id = ? AND cancelado = 0 AND fecha_programada > ?
        ", [$usuario_id, $today]);

        if (!empty($existing)) {
            return ['success' => false, 'message' => 'Ya hay un home office programado para este usuario'];
        }

        $this->sql->insert("
            INSERT INTO [TG].[dbo].[sistemas_home_office]
                (usuario_id, fecha_programada, programado_por, created_at)
            VALUES (?, ?, ?, GETDATE())
        ", [$usuario_id, $fecha, $programado_por]);

        return ['success' => true];
    }

    public function cancelar_ho(int $id, int $cancelado_por): array {
        $today = date('Y-m-d');
        $this->sql->update("
            UPDATE [TG].[dbo].[sistemas_home_office]
            SET cancelado = 1, cancelado_por = ?, fecha_cancelacion = GETDATE()
            WHERE id = ? AND cancelado = 0 AND fecha_programada > ?
        ", [$cancelado_por, $id, $today]);
        return ['success' => true];
    }

    /* ── Stats ────────────────────────────────────────── */

    public function build_stats(): array {
        $last_raw    = $this->get_last_tachas();
        $deuda_raw   = $this->get_deudas();
        $ajuste_raw  = $this->get_ajustes();
        $ho_data     = $this->get_ho_data();
        $ho_records  = $this->get_ho_records();

        // Construir maps manualmente en lugar de usar array_column
        $last_map = [];
        foreach ($last_raw as $row) {
            $last_map[(int)$row['usuario_id']] = $row['ultima_tacha'];
        }

        $deuda_map = [];
        foreach ($deuda_raw as $row) {
            $deuda_map[(int)$row['usuario_id']] = (int)$row['deuda'];
        }

        $ajuste_map = [];
        foreach ($ajuste_raw as $row) {
            $ajuste_map[(int)$row['usuario_id']] = (int)$row['ajuste_total'];
        }

        $pendiente_map = $ho_data['pendientes'];
        $records_map   = $ho_records['records'];

        $stats = [];
        foreach (self::ALLOWED_USERS as $uid) {
            $deuda_tachas = $deuda_map[$uid] ?? 0;
            $deuda_ajuste = $ajuste_map[$uid] ?? 0;
            $stats[$uid] = [
                'home_office'  => $this->calc_home_office(
                                      $last_map[$uid]   ?? null,
                                      $records_map[$uid] ?? []
                                  ),
                'deuda'        => $deuda_tachas + $deuda_ajuste,
                'ho_pendiente' => $pendiente_map[$uid] ?? null,
            ];
        }
        return $stats;
    }

    public function calc_home_office(?string $ultima_tacha, array $ho_records = []): array {
        // Si hay tacha: la racha limpia empieza el día hábil siguiente, sin importar la fecha
        // Si no hay tacha: empieza desde el inicio del sistema
        $base_start = $ultima_tacha !== null
            ? $this->next_business_day($ultima_tacha)
            : self::SISTEMA_START;

        // Un HO consumido empuja el inicio hacia adelante si es más reciente

        $today = date('Y-m-d');

        if ($base_start > $today) {
            return ['elapsed' => 0, 'remaining' => self::TARGET_DAYS, 'earned' => false, 'start' => $base_start];
        }

        $elapsed_total = $this->business_days_between($base_start, $today);
        $earned_total  = intdiv($elapsed_total, self::TARGET_DAYS);
        $start         = $base_start;

        if ($earned_total > 0) {
            $start = $this->add_business_days($base_start, $earned_total * self::TARGET_DAYS);
        }

        $elapsed = ($start > $today) ? 0 : $this->business_days_between($start, $today);

        // Rollover: cada vez que se cumplen TARGET_DAYS días limpios se gana 1 HO.
        // El contador continúa inmediatamente hacia el siguiente.
        $remaining = max(0, self::TARGET_DAYS - $elapsed);
        $claimed_total = 0;

        foreach ($ho_records as $fecha_programada) {
            if ($fecha_programada >= $base_start) {
                $claimed_total++;
            }
        }

        return [
            'elapsed'   => $elapsed,
            'remaining' => $remaining,
            'earned'    => $earned_total > $claimed_total,
            'start'     => $start,
        ];
    }

    /* ── Privados ─────────────────────────────────────── */

    private function get_last_tachas(): array {
        return $this->sql->select("
            SELECT usuario_id, CONVERT(VARCHAR(10), MAX(fecha), 23) AS ultima_tacha
            FROM [TG].[dbo].[sistemas_tachas]
            WHERE usuario_id IN (" . $this->ids() . ") AND eliminada = 0
            GROUP BY usuario_id
        ", []) ?: [];
    }

    private function get_deudas(): array {
        // Contar TODAS las tachas no pagadas y no eliminadas (independientemente del método)
        $rows = $this->sql->select("
            SELECT usuario_id, COUNT(*) AS contador
            FROM [TG].[dbo].[sistemas_tachas]
            WHERE usuario_id IN (" . $this->ids() . ") AND eliminada = 0
              AND (pagada = 0 OR pagada IS NULL)
            GROUP BY usuario_id
        ", []) ?: [];

        // Calcular deuda en PHP (100 por cada tacha)
        $result = [];
        foreach ($rows as $row) {
            $contador = (int)$row['contador'];
            $result[] = [
                'usuario_id' => (int)$row['usuario_id'],
                'deuda' => $contador * 100
            ];
        }
        return $result;
    }

    private function get_ajustes(): array {
        $rows = $this->sql->select("
            SELECT usuario_id, SUM(monto) AS ajuste_total
            FROM [TG].[dbo].[sistemas_deuda_ajuste]
            WHERE usuario_id IN (" . $this->ids() . ") AND eliminado = 0
            GROUP BY usuario_id
        ", []) ?: [];

        // Asegurar que ajuste_total sea entero
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'usuario_id' => $row['usuario_id'],
                'ajuste_total' => (int)($row['ajuste_total'] ?? 0)
            ];
        }
        return $result;
    }

    /* ── Tachas pendientes ───────────────────────────────── */

    public function get_tachas_pendientes(): array {
        return $this->sql->select("
            SELECT
                t.id,
                t.usuario_id,
                u.Nombre AS nombre,
                CONVERT(VARCHAR(10), t.fecha, 23) AS fecha,
                t.metodo_pago,
                CAST(t.pagada AS INT) AS pagada
            FROM [TG].[dbo].[sistemas_tachas] t
            JOIN [TG].[dbo].[Usuario] u ON u.Id = t.usuario_id
            WHERE t.usuario_id IN (" . $this->ids() . ")
              AND t.eliminada = 0
              AND t.pagada = 0
            ORDER BY t.fecha, u.Nombre
        ", []) ?: [];
    }

    /* ── Servicio ─────────────────────────────────────── */

    public function get_servicio_hoy(): ?array {
        $rows = $this->sql->select("
            SELECT s.id, s.usuario_id, u.Nombre AS nombre
            FROM [TG].[dbo].[sistemas_servicio] s
            JOIN [TG].[dbo].[Usuario] u ON u.Id = s.usuario_id
            WHERE s.fecha = CAST(GETDATE() AS DATE)
        ", []) ?: [];
        return !empty($rows) ? $rows[0] : null;
    }

    public function reclamar_servicio(int $usuario_id): array {
        if (!in_array($usuario_id, self::ALLOWED_USERS)) {
            return ['success' => false, 'message' => 'No permitido'];
        }

        // Validar hora límite: 9:30 AM hora de Ciudad Juárez
        $tz = new DateTimeZone('America/Chihuahua');
        $now = new DateTime('now', $tz);
        $limite = clone $now;
        $limite->setTime(9, 30, 0);

        if ($now > $limite) {
            return ['success' => false, 'message' => 'El servicio solo se puede reclamar hasta las 9:30 AM'];
        }

        // INSERT condicional — atómico, evita duplicados por race condition
        $this->sql->insert("
            INSERT INTO [TG].[dbo].[sistemas_servicio] (usuario_id, fecha)
            SELECT ?, CAST(GETDATE() AS DATE)
            WHERE NOT EXISTS (
                SELECT 1 FROM [TG].[dbo].[sistemas_servicio]
                WHERE fecha = CAST(GETDATE() AS DATE)
            )
        ", [$usuario_id]);

        $claimed = $this->get_servicio_hoy();
        if (!$claimed || (int)$claimed['usuario_id'] !== $usuario_id) {
            return ['success' => false, 'message' => 'Alguien más acaba de reclamar el servicio'];
        }
        return ['success' => true, 'nombre' => $claimed['nombre']];
    }

    public function add_ajuste(int $usuario_id, int $monto, string $descripcion, int $creado_por): array {
        if ($monto === 0) {
            return ['success' => false, 'message' => 'El monto no puede ser cero'];
        }
        $this->sql->insert("
            INSERT INTO [TG].[dbo].[sistemas_deuda_ajuste]
                (usuario_id, monto, descripcion, creado_por, created_at)
            VALUES (?, ?, ?, ?, GETDATE())
        ", [$usuario_id, $monto, $descripcion ?: null, $creado_por]);
        return ['success' => true];
    }

    private function get_ho_data(): array {
        $today = date('Y-m-d');

        // Último HO consumido por usuario (fecha ya pasó)
        $consumed_raw = $this->sql->select("
            SELECT usuario_id, CONVERT(VARCHAR(10), MAX(fecha_programada), 23) AS ultimo_consumido
            FROM [TG].[dbo].[sistemas_home_office]
            WHERE usuario_id IN (" . $this->ids() . ")
              AND cancelado = 0
              AND fecha_programada <= ?
            GROUP BY usuario_id
        ", [$today]) ?: [];

        // HO pendiente por usuario (fecha futura, no cancelado)
        $pendientes_raw = $this->sql->select("
            SELECT id, usuario_id, CONVERT(VARCHAR(10), fecha_programada, 23) AS fecha_programada
            FROM [TG].[dbo].[sistemas_home_office]
            WHERE usuario_id IN (" . $this->ids() . ")
              AND cancelado = 0
              AND fecha_programada > ?
            ORDER BY fecha_programada ASC
        ", [$today]) ?: [];

        $consumed_map  = array_column($consumed_raw, 'ultimo_consumido', 'usuario_id');

        // Solo el primer pendiente por usuario
        $pendiente_map = [];
        foreach ($pendientes_raw as $p) {
            if (!isset($pendiente_map[$p['usuario_id']])) {
                $pendiente_map[$p['usuario_id']] = [
                    'id'    => (int)$p['id'],
                    'fecha' => $p['fecha_programada'],
                ];
            }
        }

        return [
            'consumed'   => $consumed_map,
            'pendientes' => $pendiente_map,
        ];
    }

    private function get_ho_records(): array {
        $rows = $this->sql->select("
            SELECT usuario_id, CONVERT(VARCHAR(10), fecha_programada, 23) AS fecha_programada
            FROM [TG].[dbo].[sistemas_home_office]
            WHERE usuario_id IN (" . $this->ids() . ")
              AND cancelado = 0
            ORDER BY fecha_programada ASC
        ", []) ?: [];

        $records_map = [];
        foreach ($rows as $row) {
            $uid = (int)$row['usuario_id'];
            if (!isset($records_map[$uid])) {
                $records_map[$uid] = [];
            }
            $records_map[$uid][] = $row['fecha_programada'];
        }

        return ['records' => $records_map];
    }

    private function add_business_days(string $date, int $days): string {
        $ts    = strtotime($date);
        $added = 0;
        while ($added < $days) {
            $ts = strtotime('+1 day', $ts);
            if ((int)date('N', $ts) < 6) $added++;
        }
        return date('Y-m-d', $ts);
    }

    private function next_business_day(string $date): string {
        $ts = strtotime('+1 day', strtotime($date));
        while ((int)date('N', $ts) >= 6) {
            $ts = strtotime('+1 day', $ts);
        }
        return date('Y-m-d', $ts);
    }

    private function business_days_between(string $start, string $end): int {
        $count  = 0;
        $cur    = strtotime($start);
        $end_ts = strtotime($end);
        while ($cur <= $end_ts) {
            if ((int)date('N', $cur) < 6) $count++;
            $cur = strtotime('+1 day', $cur);
        }
        return $count;
    }
}
