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
                s.referencia, s.notas, s.estatus
            FROM TG.dbo.fuel_reception_schedule s
            LEFT JOIN TG.dbo.Proveedores p1 ON p1.id = s.supplier_id
            LEFT JOIN SG12.dbo.Proveedores p2 ON p2.cod = p1.id_control_gas
            LEFT JOIN TG.dbo.fuel_terminals t ON t.id = s.terminal_id
            LEFT JOIN TG.dbo.fuel_carriers c ON c.id = s.carrier_id
            LEFT JOIN TG.dbo.Estaciones e ON e.Codigo = s.station_code
            WHERE s.fecha = ? AND s.estatus <> 'Cancelado'
            ORDER BY p2.den, t.nombre, s.hora
        ";
        return $this->sql->select($query, [$fecha]) ?: [];
    }

    // Usado por el portal de estaciones (Mis Recepciones) para mostrar lo
    // que Abastos ya programó en el mismo rango de fechas que el usuario
    // está consultando -- una sola estación por llamada, a diferencia de
    // get_day() que trae todas las estaciones de un día.
    function get_by_station_range(int $stationCode, string $fechaDesde, string $fechaHasta): array {
        $query = "
            SELECT
                s.id, s.fecha, s.hora, s.supplier_id, p2.den AS supplier_nombre,
                s.terminal_id, t.nombre AS terminal_nombre,
                s.station_code, s.product, s.mezcla, s.litros,
                s.carrier_id, c.nombre AS carrier_nombre,
                s.referencia, s.notas, s.estatus,
                f.Id AS invoice_id, f.Folio AS invoice_folio, f.EmisorNombre AS invoice_proveedor
            FROM TG.dbo.fuel_reception_schedule s
            LEFT JOIN TG.dbo.Proveedores p1 ON p1.id = s.supplier_id
            LEFT JOIN SG12.dbo.Proveedores p2 ON p2.cod = p1.id_control_gas
            LEFT JOIN TG.dbo.fuel_terminals t ON t.id = s.terminal_id
            LEFT JOIN TG.dbo.fuel_carriers c ON c.id = s.carrier_id
            LEFT JOIN TG.dbo.fuel_reception_invoices fri ON fri.schedule_id = s.id
            LEFT JOIN TG.dbo.FacturasRecibidas f ON f.Id = fri.invoice_id
            WHERE s.station_code = ? AND s.fecha BETWEEN ? AND ? AND s.estatus <> 'Cancelado'
            ORDER BY s.fecha, s.hora
        ";
        return $this->sql->select($query, [$stationCode, $fechaDesde, $fechaHasta]) ?: [];
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
    function add(array $data, int $userId): int {
        $query = "
            INSERT INTO TG.dbo.fuel_reception_schedule
                (fecha, hora, supplier_id, terminal_id, station_code, product, mezcla, litros,
                 carrier_id, referencia, notas, estatus, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Programado', ?, GETDATE())
        ";
        return (int)$this->sql->insert($query, [
            $data['fecha'], $data['hora'] ?: null, $data['supplier_id'], $data['terminal_id'],
            $data['station_code'], $data['product'], $data['mezcla'] ?? null, $data['litros'],
            $data['carrier_id'] ?: null, $data['referencia'] ?? null, $data['notas'] ?? null,
            $userId,
        ]);
    }

    function update(int $id, array $data, int $userId): void {
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
    }

    function cancel(int $id, int $userId): void {
        $query = "
            UPDATE TG.dbo.fuel_reception_schedule
            SET estatus = 'Cancelado', updated_by = ?, updated_at = GETDATE()
            WHERE id = ?
        ";
        $this->sql->update($query, [$userId, $id]);
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
        return $nuevo;
    }
}
