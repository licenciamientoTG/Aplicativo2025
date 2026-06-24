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
             LEFT JOIN [TG].[dbo].[Usuario] u ON u.id = g.created_by
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
                 WHERE is_deleted = 0
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
                WHERE pr.accounting_group_id = ? AND pri.is_deleted = 0
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
              AND pri.is_deleted = 0
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
              AND pri.is_deleted = 0
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
            WHERE pr.accounting_group_id = ? AND pri.is_deleted = 0
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
            LEFT JOIN (
                SELECT payment_request_id, COUNT(*) AS total_invoices
                FROM [TG].[dbo].[payment_request_invoices]
                WHERE is_deleted = 0
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

    /**
     * Obtiene requisiciones listas para agrupar: status=0, sin grupo, tipo pago,
     * con TODAS sus facturas con PDF (dot verde). Mismo criterio que el correo.
     * El parámetro $date se reserva para uso futuro (filtrar por scheduled_payment_date).
     */
    public function get_ungrouped_by_date(string $date): array
    {
        $query = "
            SELECT
                pr.id,
                pr.request_date,
                pr.scheduled_payment_date,
                pr.monto_total,
                pr.emp_cod,
                e.den AS emp_name
            FROM [TG].[dbo].[payment_requests] pr
            LEFT JOIN [SG12].[dbo].[Empresas] e ON e.cod = pr.emp_cod
            WHERE pr.accounting_group_id IS NULL
              AND pr.status = 0
              AND pr.tipo = 0
              AND EXISTS (
                  SELECT 1 FROM [TG].[dbo].[payment_request_invoices] pri
                  WHERE pri.payment_request_id = pr.id AND pri.is_deleted = 0
              )
              -- Que NO exista ninguna factura normal sin archivo. Las notas de cargo
              -- (is_debit_note = 1, falsos fletes) se capturan a mano y por diseno no
              -- tienen CFDI/PDF, asi que se omiten de este requisito, igual que ya lo
              -- hacen get_payments_ready_for_request() y el calculo de pdf_status.
              AND NOT EXISTS (
                  SELECT 1 FROM [TG].[dbo].[payment_request_invoices] pri
                  LEFT JOIN [TG].[dbo].[FacturasRecibidas] fr
                      ON pri.uuid COLLATE DATABASE_DEFAULT = fr.UUID COLLATE DATABASE_DEFAULT
                      AND fr.RutaArchivo IS NOT NULL AND fr.RutaArchivo != ''
                  WHERE pri.payment_request_id = pr.id
                    AND pri.is_debit_note = 0
                    AND pri.is_deleted = 0
                    AND fr.UUID IS NULL
              )
            ORDER BY pr.emp_cod, pr.id
        ";
        return $this->sql->select($query, []) ?: [];
    }

    /**
     * Agrupa automáticamente las requisiciones de una fecha dada por empresa.
     * Retorna array con resultado.
     */
    public function auto_group_by_date(string $date, int $user_id): array
    {
        $requisiciones = $this->get_ungrouped_by_date($date);

        if (empty($requisiciones)) {
            return ['success' => true, 'message' => 'No hay requisiciones pendientes para agrupar en esa fecha', 'grupos' => 0];
        }

        // Agrupar por empresa
        $byEmpresa = [];
        foreach ($requisiciones as $req) {
            $key = $req['emp_cod'] ?: 'SIN_EMPRESA';
            $byEmpresa[$key]['emp_name'] = $req['emp_name'] ?: 'Sin empresa';
            $byEmpresa[$key]['emp_cod']  = $req['emp_cod'] ?: '';
            $byEmpresa[$key]['ids'][]    = $req['id'];
        }

        $gruposCreados = 0;
        $errors = [];

        foreach ($byEmpresa as $emp_cod => $grupo) {
            $next_id       = $this->get_next_accounting_id();
            $razon_social  = $grupo['emp_name'];

            $result = $this->create_group(
                $next_id,
                null,
                $grupo['emp_cod'],
                $razon_social,
                $user_id,
                $grupo['ids']
            );

            if ($result['success']) {
                $gruposCreados++;
            } else {
                $errors[] = $result['message'];
            }
        }

        return [
            'success'       => empty($errors),
            'grupos'        => $gruposCreados,
            'total_reqs'    => count($requisiciones),
            'message'       => $gruposCreados > 0
                ? "Se crearon $gruposCreados grupo(s) con " . count($requisiciones) . " requisición(es)"
                : 'No se pudo crear ningún grupo',
            'errors'        => $errors
        ];
    }

    /**
     * Agrupa una sola requisición puntual (no agrupada todavía) al momento en que
     * Tesorería la autoriza sin que Abastos la haya enviado primero. Reutiliza el
     * grupo del día para la misma empresa si ya existe (creado por auto_group_by_date()
     * u otra llamada a este método), o crea uno nuevo si no.
     */
    public function auto_group_single_request(int $request_id, string $emp_cod, int $user_id): array
    {
        // 0. Sin empresa asignada no se puede agrupar de forma segura: una
        // emp_cod vacía empataría con CUALQUIER otra requisición sin empresa
        // creada el mismo día, mezclando grupos de empresas distintas.
        if (trim($emp_cod) === '') {
            return ['success' => false, 'accounting_id' => null, 'group_id' => null, 'message' => 'La requisición no tiene empresa asignada; no se puede agrupar automáticamente'];
        }

        // 1. Buscar grupo ya creado hoy para esta empresa
        $existing = $this->sql->select(
            "SELECT TOP 1 id, accounting_id
             FROM [TG].[dbo].[payment_accounting_groups]
             WHERE emp_cod = ? AND CAST(created_at AS DATE) = CAST(GETDATE() AS DATE)
             ORDER BY id DESC",
            [$emp_cod]
        );

        if (!empty($existing)) {
            $group_id      = $existing[0]['id'];
            $accounting_id = $existing[0]['accounting_id'];

            // El wrapper sql->update() solo devuelve true/false según si el
            // execute() del statement tuvo éxito (rowCount() está deshabilitado
            // en MySqlPdoHandler::update()), por lo que NO distingue "0 filas
            // afectadas" de "1+ filas afectadas". Para reportar éxito de forma
            // correcta verificamos explícitamente el estado anterior y posterior.
            $current = $this->sql->select(
                "SELECT accounting_group_id FROM [TG].[dbo].[payment_requests] WHERE id = ?",
                [$request_id]
            );

            if (empty($current) || $current[0]['accounting_group_id'] !== null) {
                return ['success' => false, 'accounting_id' => null, 'group_id' => null, 'message' => 'La requisición ya está agrupada o no existe; no se pudo asignar al grupo del día'];
            }

            $updated = $this->sql->update(
                "UPDATE [TG].[dbo].[payment_requests]
                 SET accounting_group_id = ?
                 WHERE id = ? AND accounting_group_id IS NULL",
                [$group_id, $request_id]
            );

            if ($updated === false) {
                return ['success' => false, 'accounting_id' => null, 'group_id' => null, 'message' => "No se pudo asignar la requisición #$request_id al grupo existente"];
            }

            // Confirmar que la fila realmente quedó asignada al grupo (cubre el
            // caso de condición de carrera entre la verificación y el UPDATE).
            $confirm = $this->sql->select(
                "SELECT accounting_group_id FROM [TG].[dbo].[payment_requests] WHERE id = ?",
                [$request_id]
            );

            if (empty($confirm) || (int)$confirm[0]['accounting_group_id'] !== (int)$group_id) {
                return ['success' => false, 'accounting_id' => null, 'group_id' => null, 'message' => 'La requisición ya está agrupada o no existe; no se pudo asignar al grupo del día'];
            }

            return ['success' => true, 'accounting_id' => $accounting_id, 'group_id' => $group_id, 'message' => "Requisición agregada al grupo contable $accounting_id"];
        }

        // 2. No hay grupo del día para esta empresa: crear uno nuevo
        $emp_name = $this->sql->select(
            "SELECT den FROM [SG12].[dbo].[Empresas] WHERE cod = ?",
            [$emp_cod]
        );
        $razon_social = $emp_name[0]['den'] ?? 'Sin empresa';

        $next_id = $this->get_next_accounting_id();
        $result  = $this->create_group($next_id, null, $emp_cod, $razon_social, $user_id, [$request_id]);

        if (!$result['success']) {
            return ['success' => false, 'accounting_id' => null, 'group_id' => null, 'message' => $result['message']];
        }

        return ['success' => true, 'accounting_id' => $result['accounting_id'], 'group_id' => $result['group_id'], 'message' => "Requisición agrupada en nuevo archivo contable {$result['accounting_id']}"];
    }

    /**
     * Devuelve los pagos (requisiciones) cuyos grupos de contabilidad fueron
     * creados en la fecha dada. Sirve para REENVIAR el correo de los pagos que
     * "se cerraron hoy" sin volver a agrupar nada.
     *
     * Devuelve las mismas columnas que PaymentRequestsModel::get_payments_ready_for_request()
     * para que generar_html_solicitud_pagos() funcione idéntico.
     */
    public function get_payments_by_group_date(string $date): array
    {
        $query = "
            SELECT
                t1.id,
                t1.request_date,
                t1.scheduled_payment_date,
                t1.comment,
                ISNULL(t2.total_invoices, 0)  AS total_invoices,
                ISNULL(t2.total_amount, 0)    AS total_amount,
                ISNULL(t3.total_credito, 0)   AS total_notas_credito,
                ISNULL(t3.total_cargo, 0)     AS total_notas_cargo,
                ISNULL(t2.total_amount, 0)
                    - ISNULL(t3.total_credito, 0)
                    + ISNULL(t3.total_cargo, 0) AS monto_neto,
                t5.den AS provider_name,
                t6.den AS emp_name,
                g.accounting_id
            FROM [TG].[dbo].[payment_requests] t1
            INNER JOIN [TG].[dbo].[payment_accounting_groups] g
                ON g.id = t1.accounting_group_id
                AND CAST(g.created_at AS DATE) = ?
            LEFT JOIN (
                SELECT payment_request_id, COUNT(*) AS total_invoices, SUM(amount) AS total_amount
                FROM [TG].[dbo].[payment_request_invoices]
                WHERE is_deleted = 0
                GROUP BY payment_request_id
            ) t2 ON t1.id = t2.payment_request_id
            LEFT JOIN (
                SELECT
                    cna.payment_request_id,
                    SUM(CASE WHEN n.note_type = 'CREDIT' THEN cna.applied_amount ELSE 0 END) AS total_credito,
                    SUM(CASE WHEN n.note_type = 'DEBIT'  THEN cna.applied_amount ELSE 0 END) AS total_cargo
                FROM [TG].[dbo].[credit_note_applications] cna
                JOIN [TG].[dbo].[invoice_credit_debit_notes] n ON cna.credit_note_id = n.id
                GROUP BY cna.payment_request_id
            ) t3 ON t1.id = t3.payment_request_id
            LEFT JOIN [SG12].[dbo].Proveedores t5 ON t1.provider_cod = t5.cod
            LEFT JOIN [SG12].[dbo].Empresas    t6 ON t1.emp_cod = t6.cod
            ORDER BY g.accounting_id ASC, t1.id ASC";

        return $this->sql->select($query, [$date]) ?: [];
    }
}
