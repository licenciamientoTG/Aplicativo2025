<?php
class PaymentAccountingGroupsModel extends Model
{
    /**
     * Genera el siguiente ID de contabilidad en formato YYYY-XXXX
     * El número secuencial es el próximo ID de la tabla (autoincrement)
     */
    public function get_next_accounting_id(): string
    {
        $year = date('Y');
        $query = "
            SELECT ISNULL(MAX(id), 0) + 1 AS next_id
            FROM [TG].[dbo].[payment_accounting_groups]
            WHERE YEAR(created_at) = ?
        ";
        $result = $this->sql->select($query, [$year]);
        $next = $result[0]['next_id'] ?? 1;
        return $year . '-' . str_pad($next, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Crea un grupo de contabilidad y asigna las requisiciones seleccionadas.
     * Retorna array con success/message/group_id.
     */
    public function create_group(string $accounting_id, ?string $provider_cod, string $emp_cod, string $razon_social, int $user_id, array $request_ids): array
    {
        if (empty($request_ids)) {
            return ['success' => false, 'message' => 'Debe seleccionar al menos una requisición'];
        }

        // Verificar que el accounting_id no esté duplicado
        $check = $this->sql->select(
            "SELECT id FROM [TG].[dbo].[payment_accounting_groups] WHERE accounting_id = ?",
            [$accounting_id]
        );
        if (!empty($check)) {
            return ['success' => false, 'message' => "El ID de contabilidad '$accounting_id' ya existe"];
        }

        $this->sql->beginTransaction();
        try {
            // 1. Insertar grupo
            $group_id = $this->sql->insert(
                "INSERT INTO [TG].[dbo].[payment_accounting_groups]
                    (accounting_id, provider_cod, emp_cod, razon_social, created_by, created_at)
                 VALUES (?, ?, ?, ?, ?, GETDATE())",
                [$accounting_id, $provider_cod, $emp_cod, $razon_social, $user_id]
            );

            if (!$group_id) {
                throw new \Exception('Error al crear el grupo de contabilidad');
            }

            // 2. Asignar requisiciones
            foreach ($request_ids as $req_id) {
                $updated = $this->sql->update(
                    "UPDATE [TG].[dbo].[payment_requests]
                     SET accounting_group_id = ?
                     WHERE id = ? AND accounting_group_id IS NULL",
                    [$group_id, (int)$req_id]
                );
                if ($updated === false) {
                    throw new \Exception("Error al asignar requisición #$req_id al grupo");
                }
            }

            $this->sql->commit();
            return ['success' => true, 'group_id' => $group_id, 'accounting_id' => $accounting_id];
        } catch (\Exception $e) {
            $this->sql->rollBack();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Obtiene todos los grupos con resumen de requisiciones.
     */
    public function get_all_groups(): array
    {
        $query = "
            SELECT
                g.id,
                g.accounting_id,
                g.razon_social,
                g.provider_cod,
                g.emp_cod,
                g.created_at,
                g.notes,
                u.Nombre AS created_by_name,
                COUNT(pr.id)            AS total_requisiciones,
                ISNULL(SUM(pr.monto_total), 0) AS monto_total,
                e.den AS emp_name
            FROM [TG].[dbo].[payment_accounting_groups] g
            LEFT JOIN [TG].[dbo].[payment_requests] pr ON pr.accounting_group_id = g.id
            LEFT JOIN [TG].[dbo].[Usuario] u ON u.id = g.created_by
            LEFT JOIN [SG12].[dbo].[Empresas] e ON e.cod = g.emp_cod
            GROUP BY g.id, g.accounting_id, g.razon_social, g.provider_cod, g.emp_cod,
                     g.created_at, g.notes, u.Nombre, e.den
            ORDER BY g.created_at DESC
        ";
        return $this->sql->select($query, []) ?: [];
    }

    /**
     * Obtiene un grupo con el detalle de sus requisiciones.
     */
    public function get_group_with_requests(int $group_id): ?array
    {
        $group = $this->sql->select(
            "SELECT g.*, u.Nombre AS created_by_name
             FROM [TG].[dbo].[payment_accounting_groups] g
             LEFT JOIN [TG].[dbo].[Usuarios] u ON u.id = g.created_by
             WHERE g.id = ?",
            [$group_id]
        );

        if (empty($group)) return null;

        $requests = $this->sql->select(
            "SELECT pr.id, pr.request_date, pr.scheduled_payment_date, pr.monto_total,
                    pr.comment, pr.status,
                    ISNULL(inv.total_invoices, 0) AS total_invoices,
                    pv.den AS provider_name
             FROM [TG].[dbo].[payment_requests] pr
             LEFT JOIN (
                 SELECT payment_request_id, COUNT(*) AS total_invoices
                 FROM [TG].[dbo].[payment_request_invoices]
                 GROUP BY payment_request_id
             ) inv ON inv.payment_request_id = pr.id
             LEFT JOIN [TG].[dbo].[Proveedores] pv ON pv.id_control_gas = pr.provider_cod
             WHERE pr.accounting_group_id = ?",
            [$group_id]
        );

        return [
            'group'    => $group[0],
            'requests' => $requests ?: []
        ];
    }

    /**
     * Obtiene todas las facturas de todas las requisiciones de un grupo.
     * Retorna invoice_number (folio de factura SG12) necesario para imprimir comprobantes.
     */
    public function get_invoices_by_group(int $group_id): array
    {
        $query = "SELECT
                    pri.id,
                    pri.payment_request_id,
                    pri.folio,
                    pri.invoice_number,
                    pri.codgas,
                    pri.amount,
                    pri.expiration_date,
                    pri.uuid,
                    pv.den  AS provider_name,
                    e.den   AS emp_name,
                    -- Factura descargada del correo (FacturasRecibidas)
                    fr.Id            AS fr_id,
                    fr.NombreArchivo AS fr_nombre_archivo,
                    fr.RutaArchivo   AS fr_ruta_archivo,
                    fr.Fecha         AS fr_fecha,
                    fr.Total         AS fr_total,
                    CASE WHEN fr.Id IS NOT NULL THEN 1 ELSE 0 END AS tiene_factura_recibida
                FROM [TG].[dbo].[payment_requests] pr
                INNER JOIN [TG].[dbo].[payment_request_invoices] pri
                    ON pri.payment_request_id = pr.id
                LEFT JOIN [SG12].[dbo].[Proveedores] pv ON pv.cod = pr.provider_cod
                LEFT JOIN [SG12].[dbo].[Empresas]    e  ON e.cod  = pr.emp_cod
                LEFT JOIN [TG].[dbo].[FacturasRecibidas] fr
                    ON fr.UUID = pri.uuid
                    AND fr.RutaArchivo IS NOT NULL
                    AND fr.RutaArchivo != ''
                WHERE pr.accounting_group_id = ?
                ORDER BY pr.id, pri.id
            ";
        return $this->sql->select($query, [$group_id]) ?: [];
    }

    /**
     * Obtiene los invoice_number (folios de factura SG12) de un grupo, listos para el PDF.
     */
    public function get_invoice_numbers_by_group(int $group_id): array
    {
        $query = "
            SELECT DISTINCT pri.invoice_number
            FROM [TG].[dbo].[payment_requests] pr
            INNER JOIN [TG].[dbo].[payment_request_invoices] pri
                ON pri.payment_request_id = pr.id
            WHERE pr.accounting_group_id = ?
              AND pri.invoice_number IS NOT NULL
              AND TRIM(pri.invoice_number) != ''
        ";
        $rows = $this->sql->select($query, [$group_id]) ?: [];
        return array_column($rows, 'invoice_number');
    }

    /**
     * Obtiene pares (folio/nro, codgas) de todas las facturas del grupo.
     * Usados para buscar documentos en ControlGas por nro+codgas.
     */
    public function get_folio_codgas_pairs_by_group(int $group_id): array
    {
        $query = "
            SELECT DISTINCT pri.folio, pri.codgas
            FROM [TG].[dbo].[payment_requests] pr
            INNER JOIN [TG].[dbo].[payment_request_invoices] pri
                ON pri.payment_request_id = pr.id
            WHERE pr.accounting_group_id = ?
              AND pri.folio IS NOT NULL
              AND pri.codgas IS NOT NULL
        ";
        return $this->sql->select($query, [$group_id]) ?: [];
    }

    /**
     * Obtiene las rutas de archivos PDF de facturas recibidas asociadas a un grupo.
     * Usado para combinar PDFs en print_accounting_group_receipts().
     */
    public function get_invoice_pdf_paths_by_group(int $group_id): array
    {
        $query = "
            SELECT DISTINCT fr.RutaArchivo
            FROM [TG].[dbo].[payment_requests] pr
            INNER JOIN [TG].[dbo].[payment_request_invoices] pri
                ON pri.payment_request_id = pr.id
            INNER JOIN [TG].[dbo].[FacturasRecibidas] fr
                ON fr.UUID = pri.uuid
                AND fr.RutaArchivo IS NOT NULL
                AND fr.RutaArchivo != ''
            WHERE pr.accounting_group_id = ?
        ";
        $rows = $this->sql->select($query, [$group_id]) ?: [];
        return array_column($rows, 'RutaArchivo');
    }

    /**
     * Obtiene las requisiciones aprobadas por abastos (nivel 66) que aún no tienen grupo.
     * La agrupación es por empresa nuestra (emp_cod de SG12).
     */
    public function get_ungrouped_abastos_approved(): array
    {
        $query = "
            SELECT
                pr.id,
                pr.request_date,
                pr.scheduled_payment_date,
                pr.monto_total,
                pr.comment,
                pr.provider_cod,
                pr.emp_cod,
                ISNULL(inv.total_invoices, 0) AS total_invoices,
                pv.den AS provider_name,
                e.den AS emp_name
            FROM [TG].[dbo].[payment_requests] pr
            INNER JOIN [TG].[dbo].[payment_request_authorizations] auth
                ON auth.payment_request_id = pr.id
                AND auth.permission_number = 66
            LEFT JOIN (
                SELECT payment_request_id, COUNT(*) AS total_invoices
                FROM [TG].[dbo].[payment_request_invoices]
                GROUP BY payment_request_id
            ) inv ON inv.payment_request_id = pr.id
            LEFT JOIN [SG12].[dbo].[Proveedores] pv ON pv.cod = pr.provider_cod
            LEFT JOIN [SG12].[dbo].[Empresas] e ON e.cod = pr.emp_cod
            WHERE pr.status = 0
              AND pr.accounting_group_id IS NULL
              AND pr.tipo = 0
            ORDER BY pr.request_date DESC
        ";

        return $this->sql->select($query, []) ?: [];
    }
}
