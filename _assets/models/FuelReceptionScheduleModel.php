<?php
class FuelReceptionScheduleModel extends Model {

    function get_day(string $fecha): array {
        $query = "
            SELECT
                s.id, s.fecha, s.hora, s.supplier_id, p2.den AS supplier_nombre,
                s.terminal_id, t.nombre AS terminal_nombre,
                s.station_code, e.Nombre AS station_nombre,
                s.product, s.mezcla, s.litros,
                s.carrier_id, c.nombre AS carrier_nombre,
                s.referencia, s.notas, s.estatus,
                CONVERT(VARCHAR(10), s.fecha_original, 23) AS fecha_original,
                mv.usuario_nombre AS movida_por, CONVERT(VARCHAR(16), mv.fecha, 120) AS movida_el
            FROM TG.dbo.fuel_reception_schedule s
            LEFT JOIN TG.dbo.Proveedores p1 ON p1.id = s.supplier_id
            LEFT JOIN SG12.dbo.Proveedores p2 ON p2.cod = p1.id_control_gas
            LEFT JOIN TG.dbo.fuel_terminals t ON t.id = s.terminal_id
            LEFT JOIN TG.dbo.fuel_carriers c ON c.id = s.carrier_id
            LEFT JOIN TG.dbo.Estaciones e ON e.Codigo = s.station_code
            -- Último cambio de fecha (quién y cuándo) para el ícono de
            -- 'fecha original' en la vista; solo si la fecha sí se movió.
            OUTER APPLY (
                SELECT TOP 1 a.usuario_nombre, a.fecha
                FROM TG.dbo.fuel_reception_audit_log a
                WHERE a.schedule_id = s.id AND a.accion = 'CAMBIAR_FECHA'
                ORDER BY a.id DESC
            ) mv
            WHERE s.fecha = ? AND s.estatus <> 'Cancelado'
            -- Desempate dentro de la misma hora: estación, producto e id, para
            -- que una línea duplicada quede justo debajo de su original.
            ORDER BY p2.den, t.nombre, s.hora, e.Nombre, s.product, s.id
        ";
        return $this->sql->select($query, [$fecha]) ?: [];
    }

    // Usado por el portal de estaciones (Mis Recepciones) para mostrar lo
    // que Abastos ya programó en el mismo rango de fechas que el usuario
    // está consultando -- una sola estación por llamada, a diferencia de
    // get_day() que trae todas las estaciones de un día.
    // $stationCode null = todas las estaciones (usuario con permiso "ver todas"
    // en Mis Recepciones eligiendo "(TODAS)"); se agrega el nombre de la
    // estación para poder mostrarlo en ese caso.
    function get_by_station_range(?int $stationCode, string $fechaDesde, string $fechaHasta): array {
        $query = "
            SELECT
                s.id, s.fecha, s.hora, s.supplier_id, p2.den AS supplier_nombre,
                s.terminal_id, t.nombre AS terminal_nombre,
                s.station_code, e.Nombre AS station_nombre, s.product, s.mezcla, s.litros,
                s.carrier_id, c.nombre AS carrier_nombre,
                s.referencia, s.notas, s.estatus,
                f.Id AS invoice_id, f.Folio AS invoice_folio, f.Serie AS invoice_serie,
                f.EmisorNombre AS invoice_proveedor, f.NombreArchivo AS invoice_archivo, f.UUID AS invoice_uuid
            FROM TG.dbo.fuel_reception_schedule s
            LEFT JOIN TG.dbo.Estaciones e ON e.Codigo = s.station_code
            LEFT JOIN TG.dbo.Proveedores p1 ON p1.id = s.supplier_id
            LEFT JOIN SG12.dbo.Proveedores p2 ON p2.cod = p1.id_control_gas
            LEFT JOIN TG.dbo.fuel_terminals t ON t.id = s.terminal_id
            LEFT JOIN TG.dbo.fuel_carriers c ON c.id = s.carrier_id
            LEFT JOIN TG.dbo.fuel_reception_invoices fri ON fri.schedule_id = s.id
            LEFT JOIN TG.dbo.FacturasRecibidas f ON f.Id = fri.invoice_id
            WHERE (? IS NULL OR s.station_code = ?) AND s.fecha BETWEEN ? AND ? AND s.estatus <> 'Cancelado'
            ORDER BY s.fecha, s.hora, e.Nombre
        ";
        return $this->sql->select($query, [$stationCode, $stationCode, $fechaDesde, $fechaHasta]) ?: [];
    }

    // Una recepción con nombres de proveedor/terminal/estación (encabezado
    // del modal "Factura de la recepción").
    function get_one_detalle(int $id): ?array {
        $query = "
            SELECT s.*, p2.den AS supplier_nombre, t.nombre AS terminal_nombre, e.Nombre AS station_nombre
            FROM TG.dbo.fuel_reception_schedule s
            LEFT JOIN TG.dbo.Proveedores p1 ON p1.id = s.supplier_id
            LEFT JOIN SG12.dbo.Proveedores p2 ON p2.cod = p1.id_control_gas
            LEFT JOIN TG.dbo.fuel_terminals t ON t.id = s.terminal_id
            LEFT JOIN TG.dbo.Estaciones e ON e.Codigo = s.station_code
            WHERE s.id = ?
        ";
        $rows = $this->sql->select($query, [$id]);
        return $rows[0] ?? null;
    }

    function get_one(int $id): ?array {
        $query = "SELECT * FROM TG.dbo.fuel_reception_schedule WHERE id = ?";
        $rows = $this->sql->select($query, [$id]);
        return $rows[0] ?? null;
    }

    /**
     * Resuelve la ruta de archivo (PDF o XML) de la factura vinculada a
     * una recepción programada, validando que esa recepción sea de la
     * estación indicada — usado por station_portal::descargar_factura_programada
     * para no confiar en un invoice_id que el cliente pudiera mandar directo
     * (solo recibe schedule_id + station_code de sesión, igual patrón que
     * station_portal::descargar_factura_recepcion para el flujo Petrotal).
     */
    function get_invoice_file_path(int $scheduleId, int $stationCode, string $tipo): ?array {
        $columna = $tipo === 'pdf' ? 'f.RutaArchivo' : 'f.RutaXml';
        $nombreColumna = $tipo === 'pdf' ? 'f.NombreArchivo' : 'f.NombreXml';
        $query = "
            SELECT $columna AS ruta, $nombreColumna AS nombre
            FROM TG.dbo.fuel_reception_schedule s
            JOIN TG.dbo.fuel_reception_invoices fri ON fri.schedule_id = s.id
            JOIN TG.dbo.FacturasRecibidas f ON f.Id = fri.invoice_id
            WHERE s.id = ? AND s.station_code = ?
        ";
        $rows = $this->sql->select($query, [$scheduleId, $stationCode]);
        return $rows[0] ?? null;
    }

    // A dedicated query, NOT ProveedoresModel::get_actives() -- that method
    // selects SG12.dbo.Proveedores.* (PK `cod`) and never exposes
    // TG.dbo.Proveedores.id, which is what this feature's supplier_id
    // foreign key actually points to (confirmed 2026-09-05 against real data).
    // Solo los proveedores que realmente participan en el programa mensual
    // de recepciones de combustible (confirmados contra los Excel de julio
    // y septiembre 2026: Premier Gas, Tesoro, MGC, Enerey, Petrotal, AEMSA,
    // Essa Fuel) -- TG.dbo.Proveedores trae 85 proveedores activos en total
    // (el catálogo general de la empresa), la inmensa mayoría irrelevante
    // para este formulario.
    const IDS_PROVEEDORES_COMBUSTIBLE = [138, 123, 139, 150, 122, 163, 151];

    function get_proveedores(): array {
        $placeholders = implode(',', array_fill(0, count(self::IDS_PROVEEDORES_COMBUSTIBLE), '?'));
        $query = "
            SELECT t1.id, t2.den AS nombre
            FROM TG.dbo.Proveedores t1
            JOIN SG12.dbo.Proveedores t2 ON t2.cod = t1.id_control_gas
            WHERE t1.activo = 1 AND t1.id IN ($placeholders)
            ORDER BY t2.den
        ";
        return $this->sql->select($query, self::IDS_PROVEEDORES_COMBUSTIBLE) ?: [];
    }

    // NOTE: do NOT use "INSERT ... OUTPUT INSERTED.id" with $this->sql->select() --
    // confirmed 2026-09-06 against the real DB: select() requires the literal word
    // "select" in the query text and rejects this as "query mal formado". Use
    // $sql->insert(), which already returns the new id via PDO::lastInsertId().
    // fecha_original = fecha al crear; después solo la modifica nadie (update()
    // no la toca), así siempre dice cuándo se programó por primera vez.
    // $data['_accion'] permite que la importación de Excel quede auditada
    // como IMPORTAR en vez de CREAR.
    function add(array $data, int $userId): int {
        $query = "
            INSERT INTO TG.dbo.fuel_reception_schedule
                (fecha, fecha_original, hora, supplier_id, terminal_id, station_code, product, mezcla, litros,
                 carrier_id, referencia, notas, estatus, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Programado', ?, GETDATE())
        ";
        $nuevoId = (int)$this->sql->insert($query, [
            $data['fecha'], $data['fecha'], $data['hora'] ?: null, $data['supplier_id'], $data['terminal_id'],
            $data['station_code'], $data['product'], $data['mezcla'] ?? null, $data['litros'],
            $data['carrier_id'] ?: null, $data['referencia'] ?? null, $data['notas'] ?? null,
            $userId,
        ]);
        if ($nuevoId) {
            $this->auditoria()->log(
                $data['_accion'] ?? FuelReceptionAuditLogModel::ACC_CREAR,
                $nuevoId,
                null,
                self::datos_auditables($data),
                $userId
            );
        }
        return $nuevoId;
    }

    private ?FuelReceptionAuditLogModel $auditoria = null;

    private function auditoria(): FuelReceptionAuditLogModel {
        return $this->auditoria ??= new FuelReceptionAuditLogModel();
    }

    /** Campos de una recepción que se guardan en la bitácora (antes/después). */
    private const CAMPOS_AUDITABLES = [
        'fecha', 'hora', 'supplier_id', 'terminal_id', 'station_code', 'product',
        'mezcla', 'litros', 'carrier_id', 'referencia', 'notas',
    ];

    private static function datos_auditables(array $data): array {
        $out = [];
        foreach (self::CAMPOS_AUDITABLES as $campo) {
            if (!array_key_exists($campo, $data)) {
                continue;
            }
            $v = $data[$campo];
            $out[$campo] = ($v === '' || $v === null) ? null : (string)$v;
        }
        return $out;
    }

    /**
     * Cuántas recepciones hay ya por llave fecha|proveedor|estación|
     * producto|litros, para las fechas dadas: ['activas' => [...],
     * 'canceladas' => [...]]. La importación desde Excel las usa para no
     * duplicar lo ya cargado ni resucitar lo que alguien canceló a propósito.
     */
    function get_existing_counts(array $fechas): array {
        $fechas = array_values(array_unique($fechas));
        $counts = ['activas' => [], 'canceladas' => []];
        if (!$fechas) {
            return $counts;
        }
        $placeholders = implode(',', array_fill(0, count($fechas), '?'));
        $query = "
            SELECT CONVERT(VARCHAR(10), fecha, 23) AS fecha, supplier_id, station_code, product, litros,
                   CASE WHEN estatus = 'Cancelado' THEN 1 ELSE 0 END AS cancelada, COUNT(*) AS n
            FROM TG.dbo.fuel_reception_schedule
            WHERE fecha IN ($placeholders)
            GROUP BY fecha, supplier_id, station_code, product, litros, CASE WHEN estatus = 'Cancelado' THEN 1 ELSE 0 END
        ";
        foreach ($this->sql->select($query, $fechas) ?: [] as $r) {
            $counts[$r['cancelada'] ? 'canceladas' : 'activas'][self::llave_importacion($r)] = (int)$r['n'];
        }
        return $counts;
    }

    static function llave_importacion(array $r): string {
        return implode('|', [$r['fecha'], (int)$r['supplier_id'], (int)$r['station_code'], $r['product'], (int)$r['litros']]);
    }

    /**
     * Inserta en una sola transacción las filas ya resueltas de la
     * importación desde Excel. Devuelve cuántas insertó; si algo falla
     * revierte todo.
     */
    function import_rows(array $rows, int $userId): int {
        $this->sql->beginTransaction();
        try {
            foreach ($rows as $r) {
                $this->add([
                    'fecha' => $r['fecha'], 'hora' => $r['hora'], 'supplier_id' => $r['supplier_id'],
                    'terminal_id' => $r['terminal_id'], 'station_code' => $r['station_code'],
                    'product' => $r['product'], 'mezcla' => null, 'litros' => $r['litros'],
                    'carrier_id' => $r['carrier_id'], 'referencia' => $r['referencia'], 'notas' => $r['notas'],
                    '_accion' => FuelReceptionAuditLogModel::ACC_IMPORTAR,
                ], $userId);
            }
            $this->sql->commit();
        } catch (Throwable $e) {
            $this->sql->rollBack();
            throw $e;
        }
        return count($rows);
    }

    /**
     * Copia exacta de una recepción en el mismo día (Abastos la usa cuando
     * ControlGas registra una descarga doble: duplican la de 30,000 y luego
     * editan a mano cada una a 15,000). La copia nace como 'Programado' y
     * sin factura vinculada (una factura solo puede ir a una recepción).
     */
    function duplicate(int $id, int $userId): int {
        $query = "
            INSERT INTO TG.dbo.fuel_reception_schedule
                (fecha, fecha_original, hora, supplier_id, terminal_id, station_code, product, mezcla, litros,
                 carrier_id, referencia, notas, estatus, created_by, created_at)
            SELECT fecha, fecha, hora, supplier_id, terminal_id, station_code, product, mezcla, litros,
                   carrier_id, referencia, notas, 'Programado', ?, GETDATE()
            FROM TG.dbo.fuel_reception_schedule
            WHERE id = ? AND estatus <> 'Cancelado'
        ";
        $nuevoId = (int)$this->sql->insert($query, [$userId, $id]);
        if ($nuevoId) {
            $this->auditoria()->log(
                FuelReceptionAuditLogModel::ACC_DUPLICAR,
                $nuevoId,
                ['duplicada_de' => $id],
                self::datos_auditables($this->get_one($nuevoId) ?? []),
                $userId
            );
        }
        return $nuevoId;
    }

    function update(int $id, array $data, int $userId): void {
        $antes = $this->get_one($id);
        $query = "
            UPDATE TG.dbo.fuel_reception_schedule
            SET fecha = ?, hora = ?, supplier_id = ?, terminal_id = ?, station_code = ?,
                product = ?, mezcla = ?, litros = ?, carrier_id = ?, referencia = ?, notas = ?,
                estatus = 'Modificado', updated_by = ?, updated_at = GETDATE()
            WHERE id = ? AND estatus <> 'Cancelado'
        ";
        $this->sql->update($query, [
            $data['fecha'], $data['hora'] ?: null, $data['supplier_id'], $data['terminal_id'],
            $data['station_code'], $data['product'], $data['mezcla'] ?? null, $data['litros'],
            $data['carrier_id'] ?: null, $data['referencia'] ?? null, $data['notas'] ?? null,
            $userId, $id,
        ]);

        // Auditoría: solo los campos que sí cambiaron (antes -> después).
        // fecha_original no se toca aquí a propósito.
        if ($antes && $antes['estatus'] !== 'Cancelado') {
            $nuevos = self::datos_auditables($data);
            $previos = self::datos_auditables($antes);
            $dif = array_filter(array_keys($nuevos), function ($k) use ($nuevos, $previos) {
                return $this->normalizar_audit($k, $nuevos[$k]) !== $this->normalizar_audit($k, $previos[$k] ?? null);
            });
            if ($dif) {
                $a = $d = [];
                foreach ($dif as $k) {
                    $a[$k] = $previos[$k] ?? null;
                    $d[$k] = $nuevos[$k];
                }
                $log = $this->auditoria();
                $log->log(FuelReceptionAuditLogModel::ACC_EDITAR, $id, $a, $d, $userId);
                if (isset($a['fecha'])) {
                    $log->log(FuelReceptionAuditLogModel::ACC_CAMBIAR_FECHA, $id, ['fecha' => $a['fecha']], ['fecha' => $d['fecha']], $userId);
                }
            }
        }
    }

    // Compara sin falsos cambios: fecha 'Y-m-d', hora 'HH:MM' vs 'HH:MM:SS',
    // números como texto, '' = NULL.
    private function normalizar_audit(string $campo, $valor): ?string {
        if ($valor === null || $valor === '') {
            return null;
        }
        $valor = (string)$valor;
        if ($campo === 'fecha') {
            return substr($valor, 0, 10);
        }
        if ($campo === 'hora' && preg_match('/^\d{2}:\d{2}/', $valor)) {
            return substr($valor, 0, 5);
        }
        return $valor;
    }

    function cancel(int $id, int $userId): void {
        $antes = $this->get_one($id);
        $query = "
            UPDATE TG.dbo.fuel_reception_schedule
            SET estatus = 'Cancelado', updated_by = ?, updated_at = GETDATE()
            WHERE id = ?
        ";
        $this->sql->update($query, [$userId, $id]);
        if ($antes && $antes['estatus'] !== 'Cancelado') {
            $this->auditoria()->log(
                FuelReceptionAuditLogModel::ACC_CANCELAR, $id,
                ['estatus' => $antes['estatus']], ['estatus' => 'Cancelado'], $userId
            );
        }
    }

    /**
     * Alterna entre "Recibido" y "Programado" -- toggle reversible desde
     * la UI (botón de marcar/desmarcar recepción). No toca filas
     * Canceladas. Devuelve el nuevo estatus, o null si la fila no existe
     * o está cancelada.
     */
    function toggle_recibido(int $id, int $userId): ?string {
        $rows = $this->sql->select(
            "SELECT estatus FROM TG.dbo.fuel_reception_schedule WHERE id = ?",
            [$id]
        );
        $actual = $rows[0]['estatus'] ?? null;
        if ($actual === null || $actual === 'Cancelado') {
            return null;
        }

        $nuevo = $actual === 'Recibido' ? 'Programado' : 'Recibido';
        $this->sql->update(
            "UPDATE TG.dbo.fuel_reception_schedule SET estatus = ?, updated_by = ?, updated_at = GETDATE() WHERE id = ?",
            [$nuevo, $userId, $id]
        );
        $this->auditoria()->log(
            FuelReceptionAuditLogModel::ACC_CAMBIO_ESTATUS, $id,
            ['estatus' => $actual], ['estatus' => $nuevo], $userId
        );
        return $nuevo;
    }

    /**
     * Cambia el estatus de varias recepciones a la vez ('Recibido',
     * 'NoLlego' o 'Programado' para desmarcar). No toca Canceladas ni las que ya tienen ese estatus.
     * Devuelve cuántas se actualizaron.
     */
    function marcar_estatus(array $ids, string $estatus, int $userId): int {
        if (!in_array($estatus, ['Recibido', 'NoLlego', 'Programado'], true)) {
            return 0;
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($i) { return $i > 0; })));
        if (!$ids) {
            return 0;
        }
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        // 'Programado' = desmarcar: solo regresa las ya marcadas (Recibido /
        // NoLlego) a pendiente; para los otros destinos se salta Canceladas y
        // las que ya tienen ese estatus.
        $desmarcar = $estatus === 'Programado';
        $cond = $desmarcar ? "estatus IN ('Recibido', 'NoLlego')" : "estatus NOT IN ('Cancelado', ?)";
        $extra = $desmarcar ? [] : [$estatus];
        // Se leen las filas que sí van a cambiar (id + estatus actual) para
        // poder auditar una fila por recepción.
        $afectadas = $this->sql->select(
            "SELECT id, estatus FROM TG.dbo.fuel_reception_schedule
             WHERE id IN ($marcas) AND $cond",
            array_merge($ids, $extra)
        ) ?: [];
        $n = count($afectadas);
        if ($n > 0) {
            $this->sql->update(
                "UPDATE TG.dbo.fuel_reception_schedule
                 SET estatus = ?, updated_by = ?, updated_at = GETDATE()
                 WHERE id IN ($marcas) AND $cond",
                array_merge([$estatus, $userId], $ids, $extra)
            );
            $log = $this->auditoria();
            foreach ($afectadas as $f) {
                $log->log(
                    FuelReceptionAuditLogModel::ACC_CAMBIO_ESTATUS, (int)$f['id'],
                    ['estatus' => $f['estatus']], ['estatus' => $estatus], $userId
                );
            }
        }
        return $n;
    }
}
