<?php
class InvoiceCreditDebitNotesModel extends Model
{
    public $id;
    public $provider_id;
    public $note_type;
    public $note_number;
    public $note_date;
    public $amount;
    public $currency;
    public $description;
    public $reason_code;
    public $status;
    public $created_by;
    public $created_at;

    /**
     * Obtener todas las notas activas de un proveedor (con saldo disponible calculado)
     */
    public function getNotesByProvider($providerId) : array|false {
        if (!$providerId || $providerId == 1) {
            $where  = 'WHERE t1.status = 1';
            $params = [];
        } else {
            $where  = 'WHERE t1.provider_id = ? AND t1.status = 1';
            $params = [$providerId];
        }

        $query = "
            SELECT
                t1.*,
                t2.Nombre as created_by_name,
                t3.den as proveedor,
                ISNULL((
                    SELECT SUM(a.applied_amount)
                    FROM [tg].[dbo].credit_note_applications a
                    WHERE a.credit_note_id = t1.id AND a.status = 1
                ), 0) as total_applied,
                t1.amount - ISNULL((
                    SELECT SUM(a.applied_amount)
                    FROM [tg].[dbo].credit_note_applications a
                    WHERE a.credit_note_id = t1.id AND a.status = 1
                ), 0) as available_balance,
                (SELECT COUNT(*)
                    FROM [tg].[dbo].invoice_credit_debit_notes_doc
                    WHERE credit_note_id = t1.id) as documents_count,
                emp.den AS emp_den,
                CASE WHEN ISNULL(fr.RutaArchivo, '') <> '' THEN 1 ELSE 0 END AS tiene_pdf_fr,
                CASE WHEN ISNULL(fr.RutaXml, '') <> '' THEN 1 ELSE 0 END AS tiene_xml_fr,
                pg.pago_id, pg.pago_status,
                COALESCE(ptx.payment_date, pg.pago_fecha_comment) AS pago_fecha,
                COALESCE(NULLIF(ptx.payment_reference, ''), pg.pago_ref_comment) AS pago_ref,
                CASE
                    WHEN t1.note_type <> 'DEBIT' THEN NULL
                    WHEN pg.pago_id IS NULL THEN 'DISPONIBLE'
                    WHEN pg.pago_status = 2 OR pg.pri_status = 2 THEN 'PAGADA'
                    WHEN pg.pago_status = 1 THEN 'AUTORIZADA'
                    ELSE 'EN_PAGO'
                END AS estado_pago
            FROM [tg].[dbo].invoice_credit_debit_notes t1
            LEFT JOIN [TG].[dbo].[Usuario] t2 ON t1.created_by = t2.Id
            LEFT JOIN SG12.dbo.Proveedores t3 on t3.cod = t1.provider_id
            LEFT JOIN TG.dbo.FacturasRecibidas fr ON fr.Id = t1.factura_recibida_id
            LEFT JOIN SG12.dbo.Empresas emp ON emp.cod = t1.emp_cod
            OUTER APPLY (
                SELECT TOP 1 pr.id AS pago_id, pr.status AS pago_status, pri.status AS pri_status,
                       pr.comment AS pago_comment,
                       TRY_CONVERT(DATETIME, SUBSTRING(pr.comment, NULLIF(PATINDEX('%Pago registrado el [0-9][0-9]/[0-9][0-9]/[0-9][0-9][0-9][0-9]%', pr.comment), 0) + 19, 10), 103) AS pago_fecha_comment,
                       CASE WHEN CHARINDEX('Ref: ', pr.comment) > 0
                            THEN LTRIM(RTRIM(SUBSTRING(pr.comment, CHARINDEX('Ref: ', pr.comment) + 5, 100))) END AS pago_ref_comment
                FROM TG.dbo.payment_request_invoices pri
                JOIN TG.dbo.payment_requests pr ON pr.id = pri.payment_request_id
                WHERE t1.note_type = 'DEBIT'
                  AND pri.is_debit_note = 1 AND pri.is_deleted = 0 AND pr.is_deleted = 0
                  AND ((t1.uuid IS NOT NULL AND pri.uuid COLLATE DATABASE_DEFAULT = t1.uuid COLLATE DATABASE_DEFAULT)
                       OR (pri.folio COLLATE DATABASE_DEFAULT = t1.note_number COLLATE DATABASE_DEFAULT
                           AND pr.provider_cod = t1.provider_id))
                ORDER BY CASE WHEN pr.status = 2 OR pri.status = 2 THEN 0 ELSE 1 END, pr.id DESC
            ) pg
            OUTER APPLY (
                SELECT TOP 1 x.payment_date, x.payment_reference
                FROM TG.dbo.payment_transactions x
                WHERE x.payment_request_id = pg.pago_id AND x.status IN (1, 2)
                ORDER BY x.payment_date DESC, x.id DESC
            ) ptx
                $where
            ORDER BY t1.created_at DESC";
        return $this->sql->select($query, $params);
    }

    /**
     * Obtener notas de un proveedor con saldo disponible > 0 (para aplicar en un pago)
     */
    public function getAvailableNotesByProvider($providerId) : array|false {
        $allProviders = (empty($providerId) || $providerId == '0');

        $whereProvider = $allProviders ? '' : 'AND t1.provider_id = ?';
        $params = $allProviders ? [] : [$providerId];

        $query = "
            SELECT
                t1.*,
                t2.Nombre as created_by_name,
                t3.den as provider_name,
                ISNULL((
                    SELECT SUM(a.applied_amount)
                    FROM [tg].[dbo].credit_note_applications a
                    WHERE a.credit_note_id = t1.id AND a.status = 1
                ), 0) as total_applied,
                t1.amount - ISNULL((
                    SELECT SUM(a.applied_amount)
                    FROM [tg].[dbo].credit_note_applications a
                    WHERE a.credit_note_id = t1.id AND a.status = 1
                ), 0) as available_balance
            FROM [tg].[dbo].invoice_credit_debit_notes t1
            LEFT JOIN [TG].[dbo].[Usuario] t2 ON t1.created_by = t2.Id
            LEFT JOIN SG12.dbo.Proveedores t3 ON t3.cod = t1.provider_id
            WHERE t1.status = 1
              $whereProvider
              AND t1.amount > ISNULL((
                    SELECT SUM(a.applied_amount)
                    FROM [tg].[dbo].credit_note_applications a
                    WHERE a.credit_note_id = t1.id AND a.status = 1
                ), 0)
              -- Excluir notas de cargo ya cobradas como fila de pago
              -- (is_debit_note=1, falsos fletes): esas no generan aplicación,
              -- su consumo es la propia fila (folio = note_number, mismo proveedor)
              AND NOT EXISTS (
                    SELECT 1
                    FROM [TG].[dbo].[payment_request_invoices] pri
                    INNER JOIN [TG].[dbo].[payment_requests] pr ON pr.id = pri.payment_request_id
                    WHERE pri.is_debit_note = 1
                      AND pri.is_deleted = 0
                      AND pr.is_deleted = 0
                      AND pri.folio = t1.note_number
                      AND pr.provider_cod = t1.provider_id
                )
            ORDER BY t1.note_date ASC";
        return $this->sql->select($query, $params);
    }

    /**
     * Tab "Estado por Proveedor": saldo disponible de notas de crédito activas
     * por proveedor (monto de la nota menos lo ya aplicado).
     */
    public function getAvailableCreditByProvider() : array|false {
        // La subconsulta va en CROSS APPLY: SUM() directo sobre una
        // subconsulta no es válido en SQL Server.
        $query = "
            SELECT
                n.provider_id AS provider_cod,
                SUM(n.amount - calc.aplicado) AS disponible
            FROM [tg].[dbo].invoice_credit_debit_notes n
            CROSS APPLY (
                SELECT ISNULL((
                    SELECT SUM(a.applied_amount)
                    FROM [tg].[dbo].credit_note_applications a
                    WHERE a.credit_note_id = n.id AND a.status = 1
                ), 0) AS aplicado
            ) calc
            WHERE n.status = 1
              AND n.note_type = 'CREDIT'
              AND n.amount - calc.aplicado > 0.01
            GROUP BY n.provider_id";
        return $this->sql->selectSafe($query);
    }

    /**
     * Agregar nota de crédito o cargo (sin ligar a pago ni factura)
     */
    public function addCreditDebitNote($data) : int|false {
        $columns = ['provider_id', 'note_type', 'note_number', 'note_date', 'amount', 'description', 'reason_code', 'created_by'];
        $params = [
            $data['provider_id'],
            $data['note_type'],
            $data['note_number'] ?? null,
            $data['note_date'],
            $data['amount'],
            $data['description'],
            $data['reason_code'] ?? null,
            $data['created_by']
        ];

        // Columnas de notas ligadas a FacturasRecibidas (solo si vienen en $data)
        foreach (['factura_recibida_id', 'uuid', 'emp_cod', 'codgas', 'origen'] as $extra) {
            if (array_key_exists($extra, $data)) {
                $columns[] = $extra;
                $params[] = $data[$extra];
            }
        }

        $query = "
            INSERT INTO [tg].[dbo].invoice_credit_debit_notes
                (" . implode(', ', $columns) . ", status)
            VALUES (" . implode(', ', array_fill(0, count($columns), '?')) . ", 1)";

        return $this->sql->insert($query, $params);
    }

    /**
     * Verifica si ya existe una nota activa con ese UUID de CFDI.
     */
    public function existsUuid(string $uuid) : bool {
        $query = "
            SELECT TOP 1 id
            FROM [tg].[dbo].invoice_credit_debit_notes
            WHERE status = 1
              AND uuid COLLATE DATABASE_DEFAULT = ? COLLATE DATABASE_DEFAULT";
        $result = $this->sql->select($query, [$uuid]);
        return !empty($result);
    }

    /**
     * Facturas recibidas del proveedor (por RFC emisor) en el rango que aún no son
     * nota activa ni fila activa de pago. La clase se calcula aquí con NotaCfdi y
     * se descartan las que no son nota de crédito / falso flete / moratorios.
     */
    public function getCandidatosFR(int $providerId, string $desde, string $hasta) : array {
        $query = "
            SELECT fr.Id AS fr_id, fr.UUID AS uuid, fr.Serie AS serie, fr.Folio AS folio, fr.Fecha AS fecha,
                   fr.TipoDeComprobante AS tipo, fr.Total AS total,
                   fr.ReceptorNombre AS receptor_nombre, fr.ReceptorRfc AS receptor_rfc,
                   emp.cod AS emp_cod, emp.den AS emp_den,
                   TRY_CAST(fr.EstacionCodgas AS INT) AS codgas, g.den AS estacion,
                   CASE WHEN ISNULL(fr.RutaArchivo, '') <> '' THEN 1 ELSE 0 END AS tiene_pdf,
                   cc.conceptos, cc.claves
            FROM TG.dbo.FacturasRecibidas fr
            INNER JOIN SG12.dbo.Proveedores p ON p.cod = ? AND p.rfc = fr.EmisorRfc
            OUTER APPLY (SELECT TOP 1 e.cod, e.den FROM SG12.dbo.Empresas e WHERE e.rfc = fr.ReceptorRfc ORDER BY e.cod) emp
            LEFT JOIN SG12.dbo.Gasolineras g ON g.cod = TRY_CAST(fr.EstacionCodgas AS INT)
            OUTER APPLY (
                SELECT STRING_AGG(CAST(ISNULL(c.Descripcion, '') AS NVARCHAR(MAX)), '|') WITHIN GROUP (ORDER BY c.Id) AS conceptos,
                       STRING_AGG(CAST(ISNULL(c.ClaveProdServ, '') AS NVARCHAR(MAX)), '|') WITHIN GROUP (ORDER BY c.Id) AS claves
                FROM TG.dbo.FacturasRecibidasConceptos c
                WHERE c.FacturaId = fr.Id
            ) cc
            WHERE fr.Fecha >= ? AND fr.Fecha < DATEADD(DAY, 1, CAST(? AS DATE))
              AND fr.UUID IS NOT NULL
              AND NOT EXISTS (SELECT 1 FROM TG.dbo.invoice_credit_debit_notes n
                              WHERE n.status = 1
                                AND n.uuid COLLATE DATABASE_DEFAULT = fr.UUID COLLATE DATABASE_DEFAULT)
              AND NOT EXISTS (SELECT 1 FROM TG.dbo.payment_request_invoices pri
                              WHERE pri.is_deleted = 0
                                AND pri.uuid COLLATE DATABASE_DEFAULT = fr.UUID COLLATE DATABASE_DEFAULT)
              -- Notas capturadas a mano (sin uuid): mismo proveedor, mismo folio numérico y mismo monto
              AND NOT EXISTS (SELECT 1 FROM TG.dbo.invoice_credit_debit_notes n
                              WHERE n.status = 1 AND n.uuid IS NULL AND n.provider_id = p.cod
                                AND TRY_CAST(SUBSTRING(n.note_number, PATINDEX('%[0-9]%', n.note_number), 50) AS BIGINT) = TRY_CAST(fr.Folio AS BIGINT)
                                AND ABS(n.amount - fr.Total) < 0.01)
            ORDER BY fr.Fecha DESC, fr.Id DESC";
        $rows = $this->sql->select($query, [$providerId, $desde, $hasta]) ?: [];

        $out = [];
        foreach ($rows as $r) {
            $desc = ($r['conceptos'] ?? '') === '' ? [] : explode('|', (string)$r['conceptos']);
            $claves = ($r['claves'] ?? '') === '' ? [] : explode('|', (string)$r['claves']);
            $clase = NotaCfdi::clasificar((string)$r['tipo'], $desc, $claves);
            if ($clase === null) {
                continue;
            }
            $r['clase'] = $clase;
            $r['note_type'] = NotaCfdi::noteType($clase);
            $r['numero'] = NotaCfdi::numeroNota($r['serie'] ?? null, (string)$r['folio']);
            $out[] = $r;
        }
        return $out;
    }

    /**
     * Rechazos que no dependen de que el CFDI ya esté en FacturasRecibidas: ya está en una
     * solicitud de pago, proveedor desconocido o nota capturada a mano (mismo proveedor,
     * folio numérico y monto). Devuelve el mensaje de rechazo o null si se puede crear.
     */
    public function rechazoPrevio(string $uuid, string $emisorRfc, string $folio, float $total) : ?string {
        $enPago = $this->sql->select("
            SELECT TOP 1 id FROM TG.dbo.payment_request_invoices
            WHERE is_deleted = 0
              AND uuid COLLATE DATABASE_DEFAULT = ? COLLATE DATABASE_DEFAULT", [$uuid]);
        if (!empty($enPago)) {
            return 'El CFDI ya está en una solicitud de pago';
        }

        $prov = $this->getProviderByRfc($emisorRfc);
        if (!$prov) {
            return 'Proveedor no encontrado para RFC ' . $emisorRfc;
        }

        // Nota capturada a mano (sin uuid): mismo proveedor, folio numérico y monto
        $legacy = $this->sql->select("
            SELECT TOP 1 n.id, n.note_number
            FROM TG.dbo.invoice_credit_debit_notes n
            WHERE n.status = 1 AND n.uuid IS NULL AND n.provider_id = ?
              AND TRY_CAST(SUBSTRING(n.note_number, PATINDEX('%[0-9]%', n.note_number), 50) AS BIGINT) = TRY_CAST(? AS BIGINT)
              AND ABS(n.amount - ?) < 0.01
            ORDER BY n.id", [(int)$prov['cod'], $folio, $total]);
        if (!empty($legacy)) {
            return 'Ya existe una nota capturada para este CFDI (#' . $legacy[0]['id'] . ' ' . $legacy[0]['note_number'] . ')';
        }
        return null;
    }

    /** Descripciones y claves de los conceptos de una factura recibida (arreglos paralelos). */
    public function conceptosFactura(int $frId) : array {
        $cs = $this->sql->select("
            SELECT Descripcion, ClaveProdServ FROM TG.dbo.FacturasRecibidasConceptos
            WHERE FacturaId = ? ORDER BY Id", [$frId]) ?: [];
        return [
            array_map(fn($c) => (string)$c['Descripcion'], $cs),
            array_map(fn($c) => (string)$c['ClaveProdServ'], $cs),
        ];
    }

    /**
     * Crea una nota en el catálogo a partir de un CFDI de FacturasRecibidas.
     * Relee el CFDI de la BD (no confía en el cliente).
     * @return array{success:bool, note_id:?int, message:string}
     */
    public function crearDesdeFactura(int $frId, int $userId, string $origen = 'ligada', ?string $claseForzada = null) : array {
        $fail = fn(string $m) => ['success' => false, 'note_id' => null, 'message' => $m];

        $rows = $this->sql->select("
            SELECT Id, UUID, Serie, Folio, Fecha, Total, TipoDeComprobante, EmisorRfc, ReceptorRfc, EstacionCodgas
            FROM TG.dbo.FacturasRecibidas WHERE Id = ?", [$frId]);
        if (empty($rows)) {
            return $fail('Factura recibida no encontrada');
        }
        $fr = $rows[0];
        if (empty($fr['UUID'])) {
            return $fail('El CFDI no tiene UUID');
        }

        if ($claseForzada !== null) {
            $clase = $claseForzada;
        } else {
            $cs = $this->sql->select("
                SELECT Descripcion, ClaveProdServ FROM TG.dbo.FacturasRecibidasConceptos
                WHERE FacturaId = ? ORDER BY Id", [$frId]) ?: [];
            $clase = NotaCfdi::clasificar(
                (string)$fr['TipoDeComprobante'],
                array_map(fn($c) => (string)$c['Descripcion'], $cs),
                array_map(fn($c) => (string)$c['ClaveProdServ'], $cs)
            );
        }
        if ($clase === null || !in_array($clase, [NotaCfdi::NOTA_CREDITO, NotaCfdi::FALSO_FLETE, NotaCfdi::MORATORIOS], true)) {
            return $fail('Este CFDI no es nota de crédito ni de cargo');
        }
        if ($this->existsUuid((string)$fr['UUID'])) {
            return $fail('Ya existe una nota con ese UUID');
        }

        $rechazo = $this->rechazoPrevio((string)$fr['UUID'], (string)$fr['EmisorRfc'], (string)$fr['Folio'], (float)$fr['Total']);
        if ($rechazo !== null) {
            return $fail($rechazo);
        }
        $prov = $this->getProviderByRfc($fr['EmisorRfc']);
        $emp = $this->sql->select("SELECT TOP 1 cod FROM SG12.dbo.Empresas WHERE rfc = ? ORDER BY cod", [$fr['ReceptorRfc']]);
        $empCod = !empty($emp) ? (int)$emp[0]['cod'] : null;
        $codgas = is_numeric($fr['EstacionCodgas']) ? (int)$fr['EstacionCodgas'] : null;

        $fecha = $fr['Fecha'] instanceof DateTimeInterface ? $fr['Fecha']->format('Y-m-d') : substr((string)$fr['Fecha'], 0, 10);

        try {
        $noteId = $this->addCreditDebitNote([
            'provider_id'         => (int)$prov['cod'],
            'note_type'           => NotaCfdi::noteType($clase),
            'note_number'         => NotaCfdi::numeroNota($fr['Serie'] ?? null, (string)$fr['Folio']),
            'note_date'           => $fecha,
            'amount'              => (float)$fr['Total'],
            'description'         => NotaCfdi::descripcion($clase),
            'reason_code'         => 'CFDI',
            'created_by'          => $userId,
            'factura_recibida_id' => $frId,
            'uuid'                => $fr['UUID'],
            'emp_cod'             => $empCod,
            'codgas'              => $codgas,
            'origen'              => $origen,
        ]);
        } catch (Exception $e) {
            // Carrera con el índice único de uuid: otra sesión creó la nota primero
            if ($this->existsUuid((string)$fr['UUID'])) {
                return $fail('Ya existe una nota con ese UUID');
            }
            return $fail('No se pudo guardar la nota');
        }
        if (!$noteId) {
            return $fail('No se pudo guardar la nota');
        }
        return ['success' => true, 'note_id' => (int)$noteId, 'message' => 'Nota ' . NotaCfdi::numeroNota($fr['Serie'] ?? null, (string)$fr['Folio']) . ' creada'];
    }

    /**
     * Soft delete de una nota
     */
    public function deleteCreditDebitNote($noteId) : bool {
        $query = "
            UPDATE [tg].[dbo].invoice_credit_debit_notes
            SET status = 0
            WHERE id = ? AND status = 1";
        return $this->sql->update($query, [$noteId]);
    }

    /**
     * Obtener una nota por ID
     */
    public function getNoteById($noteId) : array|false {
        $query = "
            SELECT *
            FROM [tg].[dbo].invoice_credit_debit_notes
            WHERE id = ?";
        $result = $this->sql->select($query, [$noteId]);
        return $result ? $result[0] : false;
    }

    /**
     * Busca el proveedor (cod) por RFC en el catálogo de SG12.
     */
    public function getProviderByRfc($rfc) : array|false {
        $query = "SELECT cod, den, rfc FROM SG12.dbo.Proveedores WHERE rfc = ? ORDER BY cod";
        $result = $this->sql->select($query, [$rfc]);
        return $result ? $result[0] : false;
    }

    /**
     * Verifica si ya existe una nota activa para un proveedor con ese número.
     * Sirve para detectar duplicados en la carga masiva.
     */
    public function existsNote($providerId, $noteNumber) : bool {
        $query = "
            SELECT TOP 1 id
            FROM [tg].[dbo].invoice_credit_debit_notes
            WHERE provider_id = ? AND note_number = ? AND status = 1";
        $result = $this->sql->select($query, [$providerId, $noteNumber]);
        return !empty($result);
    }

    /**
     * Obtener saldo disponible de una nota
     */
    public function getAvailableBalance($noteId) : float {
        $query = "
            SELECT
                CASE WHEN EXISTS (
                    -- Nota de cargo ya cobrada como fila de pago (is_debit_note=1):
                    -- consumida por la propia fila, sin aplicación de por medio
                    SELECT 1
                    FROM [TG].[dbo].[payment_request_invoices] pri
                    INNER JOIN [TG].[dbo].[payment_requests] pr ON pr.id = pri.payment_request_id
                    WHERE pri.is_debit_note = 1
                      AND pri.is_deleted = 0
                      AND pr.is_deleted = 0
                      AND pri.folio = n.note_number
                      AND pr.provider_cod = n.provider_id
                ) THEN 0
                ELSE n.amount - ISNULL((
                    SELECT SUM(a.applied_amount)
                    FROM [tg].[dbo].credit_note_applications a
                    WHERE a.credit_note_id = n.id AND a.status = 1
                ), 0)
                END as available_balance
            FROM [tg].[dbo].invoice_credit_debit_notes n
            WHERE n.id = ? AND n.status = 1";
        $result = $this->sql->select($query, [$noteId]);
        return $result ? (float)$result[0]['available_balance'] : 0.0;
    }
}
