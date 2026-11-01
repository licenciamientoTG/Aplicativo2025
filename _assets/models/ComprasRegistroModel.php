<?php
/**
 * Registro de compras de combustible: un renglón por factura (equivale a la
 * hoja COMPRAS de COMPRAS 2026.xlsx). Se arma desde TG.dbo.FacturasRecibidas
 * + conceptos + Estaciones + requisiciones de pago + ControlGas
 * (SG12.dbo.documentosC). Lo que el sistema no puede obtener se captura en
 * TG.dbo.compras_factura_datos (llave UUID) o, para renglones que no llegan
 * por correo, en TG.dbo.compras_renglon_manual. Esquema: docs/sql/compras_captura_schema.sql
 * Plan: docs/superpowers/specs/2026-10-27-compras-registro-plan.md
 */
class ComprasRegistroModel extends Model
{
    // ClaveProdServ SAT de los combustibles; "Servicio asociado" (80141629) son fletes.
    private const CLAVES_COMBUSTIBLE = ['15101514' => 'REGULAR', '15101515' => 'SUPER', '15101505' => 'DIESEL'];

    // Ajuste de vencimiento a día hábil por proveedor (id_control_gas). Misma regla
    // que ApiER api/modelos/Documentos_estaciones.py::_ajustar_dia_habil.
    private const GRUPO_VIERNES = [76, 96];
    private const GRUPO_LUNES   = [56, 71, 72, 83];

    // Nombre corto por RFC del emisor (los mismos que usa el Excel); el catálogo trae la razón social completa.
    private const NOMBRE_CORTO = [
        'MME141110IJ9' => 'MGC',
        'TMS1611162N5' => 'TESORO',
        'PRE190706416' => 'PREMIER GAS',
        'AEM160511LMA' => 'AEMSA',
        'PET180213L66' => 'PETROTAL',
        'SGE151215F71' => 'ENEREY',
        'EFA1903122IA' => 'ESSAFUEL',
    ];

    // Margen (días) antes de "desde" al buscar el documento en ControlGas.
    private const MARGEN_CG_DIAS = 45;

    /**
     * Campos capturables por renglón de factura (compras_factura_datos) y su tipo.
     * est_codgas se valida contra TG.dbo.Estaciones al guardar.
     */
    public const CAMPOS_FACTURA = [
        'est_codgas'      => 'estacion',
        'fecha_descarga'  => 'fecha',
        'pago_programado' => 'fecha',
        'placas'          => 'texto:30',
        'bol'             => 'texto:50',
        'transportista'   => 'texto:100',
        'observaciones'   => 'texto:500',
        // Columnas del resto del Excel; requieren docs/sql/compras_captura_columnas.sql
        'proveedor_final'    => 'texto:100',
        'litros_soporte'     => 'decimal',
        'subtotal_tesoro'    => 'decimal',
        'descuento_tesoro'   => 'decimal',
        'factura_petrotal'   => 'texto:100',
        'factura_nueva'      => 'texto:50',
        'monto_petrotal'     => 'decimal',
        'iva_cliente_final'  => 'iva',
        'precio_petrotal'    => 'decimal',
        'factura_flete'      => 'texto:50',
        'monto_carta_porte'  => 'decimal',
        'total_a_pagar'      => 'decimal',
        'razon_social_flete' => 'texto:100',
        'pago_flete'         => 'fecha',
    ];

    /** Campos que solo existen después de correr compras_captura_columnas.sql. */
    public const CAMPOS_V2 = [
        'proveedor_final', 'litros_soporte', 'subtotal_tesoro', 'descuento_tesoro', 'factura_petrotal', 'factura_nueva',
        'monto_petrotal', 'iva_cliente_final', 'precio_petrotal', 'factura_flete', 'monto_carta_porte', 'total_a_pagar',
        'razon_social_flete', 'pago_flete',
    ];

    /** Campos de un renglón manual (los de factura + los datos de la factura que no existe). */
    public const CAMPOS_MANUAL = self::CAMPOS_FACTURA + [
        'fecha'        => 'fecha',
        'proveedor'    => 'texto:100',
        'producto'     => 'producto',
        'folio'        => 'texto:50',
        'remision'     => 'texto:100',
        'litros'       => 'decimal',
        'monto'        => 'decimal',
        'iva'          => 'iva',
        'razon_social' => 'texto:255',
        'vencimiento'  => 'fecha',
        'monto_pagado' => 'decimal',
    ];

    private ?bool $capturaOk = null;
    private ?bool $v2Ok = null;

    /** true si ya se corrió docs/sql/compras_captura_schema.sql. */
    public function capturaDisponible(): bool
    {
        if ($this->capturaOk === null) {
            $r = $this->sql->selectSafe("SELECT
                CASE WHEN OBJECT_ID('TG.dbo.compras_factura_datos','U') IS NOT NULL
                      AND OBJECT_ID('TG.dbo.compras_renglon_manual','U') IS NOT NULL
                      AND OBJECT_ID('TG.dbo.compras_audit_log','U') IS NOT NULL THEN 1 ELSE 0 END AS ok");
            $this->capturaOk = !empty($r) && (int)$r[0]['ok'] === 1;
        }
        return $this->capturaOk;
    }

    /** true si ya se corrió docs/sql/compras_captura_columnas.sql. */
    public function capturaV2(): bool
    {
        if ($this->v2Ok === null) {
            $r = $this->sql->selectSafe("SELECT
                CASE WHEN COL_LENGTH('TG.dbo.compras_factura_datos','pago_flete') IS NOT NULL
                      AND COL_LENGTH('TG.dbo.compras_renglon_manual','pago_flete') IS NOT NULL THEN 1 ELSE 0 END AS ok");
            $this->v2Ok = !empty($r) && (int)$r[0]['ok'] === 1;
        }
        return $this->v2Ok;
    }

    /**
     * Facturas de combustible + renglones manuales del rango, ordenados por fecha.
     *
     * @param string $desde  Y-m-d (fecha de la factura)
     * @param string $hasta  Y-m-d
     * @param array  $f      proveedor (RFC o nombre del manual), codgas, producto, faltantes (1)
     * @return array|false
     */
    public function listar(string $desde, string $hasta, array $f = [])
    {
        $facturas = $this->listarFacturas($desde, $hasta, $f);
        if ($facturas === false) {
            return false;
        }
        $manuales = $this->capturaDisponible() ? $this->listarManuales($desde, $hasta, $f) : [];
        if ($manuales === false) {
            return false;
        }
        $rows = array_merge($facturas, $manuales);
        usort($rows, fn($a, $b) => [$a['fecha'], $a['clave']] <=> [$b['fecha'], $b['clave']]);
        return $this->enriquecer($rows);
    }

    // OJO: el join con documentosC va como LEFT HASH JOIN. documentosC no tiene índice por satuid; sin la
    // pista, con la configuración de sesión de PDO (ARITHABORT OFF) SQL Server elige un nested loop que
    // reevalúa el DISTINCT por cada factura (190 M lecturas lógicas, minutos) y el F5 se cuelga.

    // Las tablas grandes se leen WITH (NOLOCK), como el resto de los reportes del proyecto: FacturasRecibidas
    // y documentosC reciben escrituras de los importadores y de las estaciones, y una lectura bloqueante se
    // quedaba esperando o caía como víctima de deadlock.

    /** Fecha Y-m-d validada como literal SQL ('2026-10-01'). Lanza InvalidArgumentException si no es válida. */
    private function fechaSql(string $f): string
    {
        $d = DateTime::createFromFormat('Y-m-d', $f);
        if (!$d || $d->format('Y-m-d') !== $f) {
            throw new InvalidArgumentException("Fecha inválida: $f");
        }
        return "'" . $f . "'";
    }

    private function listarFacturas(string $desde, string $hasta, array $f)
    {
        $fchMin  = dateToInt(date('Y-m-d', strtotime($desde . ' -' . self::MARGEN_CG_DIAS . ' days')));
        $claves  = "'" . implode("','", array_keys(self::CLAVES_COMBUSTIBLE)) . "'";
        $captura = $this->capturaDisponible();

        // Estación efectiva: la capturada gana sobre la del CFDI/correo.
        $codgasExpr = $captura ? 'COALESCE(d.est_codgas, TRY_CAST(f.EstacionCodgas AS int))' : 'TRY_CAST(f.EstacionCodgas AS int)';

        // Fechas y fch como literales (validados): con parámetros nvarchar(4000) el plan de SQL Server
        // pierde el índice por fecha y la consulta pasa de ~1 s a minutos de CPU.
        $dSql   = $this->fechaSql($desde);
        $hSql   = $this->fechaSql($hasta);
        $where  = ["f.TipoDeComprobante = 'I'", "f.Fecha >= $dSql", "f.Fecha < DATEADD(day, 1, $hSql)"];
        $params = [];

        if (!empty($f['proveedor'])) {
            $where[]  = 'f.EmisorRfc = ?';
            $params[] = $f['proveedor'];
        }
        if (!empty($f['codgas'])) {
            $where[]  = "$codgasExpr = ?";
            $params[] = (int)$f['codgas'];
        }
        if (!empty($f['producto'])) {
            $clave = array_search($f['producto'], self::CLAVES_COMBUSTIBLE, true);
            if ($clave !== false) {
                $where[]  = 'x.clave = ?';
                $params[] = $clave;
            }
        }
        if (!empty($f['faltantes'])) {
            $where[] = '(e.Codigo IS NULL OR dc.satuid IS NULL)';
        }

        // Campos capturados (compras_factura_datos); los de captura v2 solo si ya existen las columnas.
        $v2 = $captura && $this->capturaV2();
        $selCaptura = $captura
            ? "CONVERT(varchar(10), d.fecha_descarga, 23) AS fecha_descarga,
               CONVERT(varchar(10), d.pago_programado, 23) AS pago_manual,
               d.placas, d.bol, d.transportista, d.observaciones,
               d.est_codgas AS est_manual,"
            : "NULL AS fecha_descarga, NULL AS pago_manual, NULL AS placas, NULL AS bol,
               NULL AS transportista, NULL AS observaciones, NULL AS est_manual,";
        foreach (self::CAMPOS_V2 as $c) {
            $col = $v2 ? ($c === 'pago_flete' ? "CONVERT(varchar(10), d.pago_flete, 23)" : "d.$c") : 'NULL';
            $selCaptura .= "
                   $col AS cap_$c,";
        }
        $joinCaptura = $captura ? 'LEFT JOIN TG.dbo.compras_factura_datos d WITH (NOLOCK) ON d.uuid = f.UUID' : '';

        $query = "
            SELECT f.Id, f.UUID, f.Serie, f.Folio, f.Remision, f.Destino,
                   CONVERT(varchar(10), f.Fecha, 23) AS fecha,
                   f.Total, f.EmisorRfc, f.ReceptorNombre,
                   x.clave, x.litros, x.productos, x.tasa_iva,
                   e.Codigo AS est_codigo, e.Nombre AS est_nombre, e.Ciudad AS est_ciudad, e.Zona AS est_zona,
                   SUBSTRING(e.Estacion, 2, 10) AS est_numero,
                   pv.cod AS prov_cod, pv.den AS prov_nombre, tp.dias_credito,
                   $selCaptura
                   pri.paid_amount, pri.status AS pago_status,
                   CONVERT(varchar(10), prq.scheduled_payment_date, 23) AS pago_programado,
                   CASE WHEN dc.satuid IS NULL THEN 0 ELSE 1 END AS en_controlgas
            FROM TG.dbo.FacturasRecibidas f WITH (NOLOCK)
            CROSS APPLY (
                SELECT MIN(c.ClaveProdServ) AS clave, SUM(c.Cantidad) AS litros,
                       COUNT(DISTINCT c.ClaveProdServ) AS productos,
                       MAX(CASE WHEN c.Impuesto = 'IVA' THEN c.TasaOCuota END) AS tasa_iva
                FROM TG.dbo.FacturasRecibidasConceptos c WITH (NOLOCK)
                WHERE c.FacturaId = f.Id AND c.ClaveProdServ IN ($claves)
                HAVING COUNT(*) > 0
            ) x
            $joinCaptura
            LEFT JOIN TG.dbo.Estaciones e ON e.Codigo = $codgasExpr AND e.Codigo > 0
            LEFT JOIN SG12.dbo.Proveedores pv ON pv.rfc = f.EmisorRfc
            LEFT JOIN TG.dbo.Proveedores tp ON tp.id_control_gas = pv.cod
            OUTER APPLY (
                SELECT TOP 1 p.paid_amount, p.status, p.payment_request_id
                FROM TG.dbo.payment_request_invoices p WITH (NOLOCK)
                WHERE p.uuid = f.UUID AND ISNULL(p.is_deleted, 0) = 0
                ORDER BY p.id DESC
            ) pri
            LEFT JOIN TG.dbo.payment_requests prq WITH (NOLOCK) ON prq.id = pri.payment_request_id
            LEFT HASH JOIN (
                SELECT DISTINCT satuid FROM SG12.dbo.documentosC WITH (NOLOCK)
                WHERE satuid IS NOT NULL AND fch >= " . (int)$fchMin . "
            ) dc ON dc.satuid = f.UUID COLLATE Modern_Spanish_CI_AS
            WHERE " . implode(' AND ', $where) . "
            ORDER BY f.Fecha, f.Id";

        $rows = $this->sql->selectSafe($query, $params);
        if ($rows === false) {
            return false;
        }
        return array_map([$this, 'armarRenglon'], $rows);
    }

    private function listarManuales(string $desde, string $hasta, array $f)
    {
        $where  = ['m.is_deleted = 0', 'm.fecha >= ' . $this->fechaSql($desde), 'm.fecha <= ' . $this->fechaSql($hasta)];
        $params = [];

        if (!empty($f['proveedor'])) {
            // Los manuales no tienen RFC; el filtro por proveedor del catálogo no aplica.
            $where[] = '1 = 0';
        }
        if (!empty($f['codgas'])) {
            $where[]  = 'm.est_codgas = ?';
            $params[] = (int)$f['codgas'];
        }
        if (!empty($f['producto'])) {
            $where[]  = 'm.producto = ?';
            $params[] = $f['producto'];
        }
        if (!empty($f['faltantes'])) {
            $where[] = 'e.Codigo IS NULL';
        }

        $rows = $this->sql->selectSafe("
            SELECT m.*, CONVERT(varchar(10), m.fecha, 23) AS fecha_s,
                   CONVERT(varchar(10), m.fecha_descarga, 23) AS fecha_descarga_s,
                   CONVERT(varchar(10), m.vencimiento, 23) AS vencimiento_s,
                   CONVERT(varchar(10), m.pago_programado, 23) AS pago_programado_s,
                   " . ($this->capturaV2() ? "CONVERT(varchar(10), m.pago_flete, 23) AS pago_flete_s," : "NULL AS pago_flete_s,") . "
                   e.Codigo AS est_codigo, e.Nombre AS est_nombre, e.Ciudad AS est_ciudad, e.Zona AS est_zona,
                   SUBSTRING(e.Estacion, 2, 10) AS est_numero
            FROM TG.dbo.compras_renglon_manual m WITH (NOLOCK)
            LEFT JOIN TG.dbo.Estaciones e ON e.Codigo = m.est_codgas AND e.Codigo > 0
            WHERE " . implode(' AND ', $where) . "
            ORDER BY m.fecha, m.id", $params);
        if ($rows === false) {
            return false;
        }

        return array_map(function ($r) {
            $litros = $r['litros'] !== null ? (float)$r['litros'] : 0.0;
            $monto  = $r['monto'] !== null ? (float)$r['monto'] : 0.0;
            $pagado = $r['monto_pagado'] !== null ? (float)$r['monto_pagado'] : null;
            return [
                'clave'           => 'M' . $r['id'],
                'origen'          => 'manual',
                'id'              => (int)$r['id'],
                'uuid'            => null,
                'fecha'           => $r['fecha_s'],
                'fecha_descarga'  => $r['fecha_descarga_s'] ?: $r['fecha_s'],
                'estacion_num'    => $r['est_numero'] !== null ? (string)(int)$r['est_numero'] : null,
                'estacion'        => $r['est_nombre'],
                'ciudad'          => $r['est_ciudad'],
                'proveedor'       => $r['proveedor'],
                'proveedor_rfc'   => null,
                'producto'        => $r['producto'],
                'varios_productos' => false,
                'folio'           => $r['folio'],
                'remision'        => $r['remision'],
                'litros'          => $litros,
                'monto'           => $monto,
                'precio_litro'    => $litros > 0 ? round($monto / $litros, 4) : null,
                'iva'             => $r['iva'] !== null ? (float)$r['iva'] : null,
                'razon_social'    => $r['razon_social'],
                'dias_credito'    => null,
                'vencimiento'     => $r['vencimiento_s'],
                'pago_programado' => $r['pago_programado_s'],
                'monto_pagado'    => $pagado,
                'saldo'           => round($monto - ($pagado ?? 0), 2),
                'placas'          => $r['placas'],
                'bol'             => $r['bol'],
                'transportista'   => $r['transportista'],
                'observaciones'   => $r['observaciones'],
                'en_pagos'        => null,
                'en_controlgas'   => null,
                'sin_estacion'    => $r['est_codigo'] === null,
                'est_codgas'      => $r['est_codigo'] !== null ? (int)$r['est_codigo'] : null,
                'est_zona'        => $r['est_zona'],
            ] + $this->capturadosV2($r, $litros, $r['proveedor'], $r['pago_flete_s']);
        }, $rows);
    }

    private function armarRenglon(array $r): array
    {
        $litros = (float)$r['litros'];
        $total  = (float)$r['Total'];
        $pagado = $r['paid_amount'] !== null ? (float)$r['paid_amount'] : null;
        $dias   = $r['dias_credito'] !== null ? (int)$r['dias_credito'] : null;

        return [
            'clave'           => 'F' . $r['Id'],
            'origen'          => 'factura',
            'id'              => (int)$r['Id'],
            'uuid'            => $r['UUID'],
            'fecha'           => $r['fecha'],
            'fecha_descarga'  => $r['fecha_descarga'] ?: $r['fecha'],
            'estacion_num'    => $r['est_numero'] !== null ? (string)(int)$r['est_numero'] : null,
            'estacion'        => $r['est_nombre'],
            'ciudad'          => $r['est_ciudad'],
            'proveedor'       => $this->nombreCorto($r['EmisorRfc'], $r['prov_nombre']),
            'proveedor_rfc'   => $r['EmisorRfc'],
            'producto'        => self::CLAVES_COMBUSTIBLE[$r['clave']] ?? null,
            'varios_productos' => (int)$r['productos'] > 1,
            'folio'           => $r['Folio'],
            'remision'        => $r['Remision'],
            'litros'          => $litros,
            'monto'           => $total,
            'precio_litro'    => $litros > 0 ? round($total / $litros, 4) : null,
            'iva'             => $r['tasa_iva'] !== null ? (float)$r['tasa_iva'] : null,
            'razon_social'    => $r['ReceptorNombre'],
            'dias_credito'    => $dias,
            'vencimiento'     => $this->vencimiento($r['fecha'], $dias, $r['prov_cod'] !== null ? (int)$r['prov_cod'] : 0),
            // El pago programado capturado gana sobre el de la requisición.
            'pago_programado' => $r['pago_manual'] ?: $r['pago_programado'],
            'monto_pagado'    => $pagado,
            'saldo'           => round($total - ($pagado ?? 0), 2),
            'placas'          => $r['placas'],
            'bol'             => $r['bol'],
            'transportista'   => $r['transportista'],
            'observaciones'   => $r['observaciones'],
            'en_pagos'        => $r['pago_status'] !== null,
            'en_controlgas'   => (bool)$r['en_controlgas'],
            'sin_estacion'    => $r['est_codigo'] === null,
            'est_codgas'      => $r['est_codigo'] !== null ? (int)$r['est_codigo'] : null,
            'est_zona'        => $r['est_zona'],
        ] + $this->capturadosV2($r, $litros, $this->nombreCorto($r['EmisorRfc'], $r['prov_nombre']), $r['cap_pago_flete'] ?? null, 'cap_');
    }

    /**
     * Campos de captura v2 de un renglón (los $r['cap_x'] de factura o los $r['x'] del manual) y los
     * valores efectivos que dependen de ellos (litros de soporte, proveedor final).
     */
    private function capturadosV2(array $r, float $litrosBase, ?string $proveedor, ?string $pagoFlete, string $pre = ''): array
    {
        $num = function ($c) use ($r, $pre) {
            $v = $r[$pre . $c] ?? null;
            return $v !== null ? (float)$v : null;
        };
        $txt = fn($c) => ($r[$pre . $c] ?? null) !== null && $r[$pre . $c] !== '' ? $r[$pre . $c] : null;
        $soporte = $num('litros_soporte');
        return [
            'litros_factura'     => $litrosBase,
            'litros'             => $soporte ?? $litrosBase,
            'proveedor_final'    => $txt('proveedor_final') ?? $proveedor,
            'subtotal_tesoro'    => $num('subtotal_tesoro'),
            'descuento_tesoro'   => $num('descuento_tesoro'),
            'factura_petrotal'   => $txt('factura_petrotal'),
            'factura_nueva'      => $txt('factura_nueva'),
            'monto_petrotal'     => $num('monto_petrotal'),
            'iva_cliente_final'  => $num('iva_cliente_final'),
            'precio_petrotal'    => $num('precio_petrotal'),
            'factura_flete'      => $txt('factura_flete'),
            'monto_carta_porte'  => $num('monto_carta_porte'),
            'total_a_pagar'      => $num('total_a_pagar'),
            'razon_social_flete' => $txt('razon_social_flete'),
            'pago_flete'         => $pagoFlete ?: null,
        ];
    }

    /** Precio de venta (col U) y columnas calculadas para todos los renglones. */
    private function enriquecer(array $rows): array
    {
        if (!$rows) {
            return $rows;
        }
        $codgases = [];
        $min = '9999-12-31';
        $max = '0000-01-01';
        foreach ($rows as $r) {
            if ($r['est_codgas']) $codgases[$r['est_codgas']] = true;
            $min = min($min, $r['fecha_descarga'] ?: $r['fecha']);
            $max = max($max, $r['fecha_descarga'] ?: $r['fecha']);
        }
        $precios = $this->preciosVenta(array_keys($codgases), $min, $max);
        foreach ($rows as &$r) {
            $r['zona']         = ComprasCalculos::zona($r['ciudad'], $r['est_zona']);
            $r['precio_venta'] = $this->precioVenta($precios, $r['est_codgas'], $r['producto'], $r['fecha_descarga'] ?: $r['fecha']);
            $r = ComprasCalculos::calcular($r);
        }
        unset($r);
        return $rows;
    }

    // FuelPrices.codprd -> producto del Excel (179/192 regular, 180/193 super, 181 diésel).
    private const PRD_PRODUCTO = [179 => 'REGULAR', 192 => 'REGULAR', 180 => 'SUPER', 193 => 'SUPER', 181 => 'DIESEL'];

    /** @return array [codgas][producto] => [[price_date, precio], ...] ascendente */
    private function preciosVenta(array $codgases, string $desde, string $hasta): array
    {
        if (!$codgases) {
            return [];
        }
        $in   = implode(',', array_map('intval', $codgases));
        $rows = $this->sql->selectSafe("
            SELECT codgas, codprd, precio, CONVERT(varchar(10), price_date, 23) AS d
            FROM TG.dbo.FuelPrices
            WHERE codgas IN ($in) AND price_date <= " . $this->fechaSql($hasta) . " AND price_date >= DATEADD(day, -180, " . $this->fechaSql($desde) . ")
            ORDER BY price_date, hour");
        $out = [];
        foreach ($rows ?: [] as $p) {
            $prod = self::PRD_PRODUCTO[(int)$p['codprd']] ?? null;
            if ($prod) {
                $out[(int)$p['codgas']][$prod][] = [$p['d'], (float)$p['precio']];
            }
        }
        return $out;
    }

    /** Último precio vigente en la fecha (el de ese día o el anterior más cercano). */
    private function precioVenta(array $precios, ?int $codgas, ?string $producto, string $fecha): ?float
    {
        $lista = $precios[$codgas][$producto] ?? null;
        if (!$lista) {
            return null;
        }
        $res = null;
        foreach ($lista as [$d, $precio]) {
            if ($d > $fecha) break;
            $res = $precio;
        }
        return $res;
    }

    private function nombreCorto(?string $rfc, ?string $nombre): ?string
    {
        return self::NOMBRE_CORTO[$rfc] ?? ($nombre ?: null);
    }

    /** Emisión + días de crédito, ajustado a día hábil. null si el proveedor no tiene crédito capturado. */
    private function vencimiento(string $fecha, ?int $dias, int $provCod): ?string
    {
        if ($dias === null) {
            return null;
        }
        $d = new DateTime($fecha);
        $d->modify("+{$dias} days");
        $dow = (int)$d->format('N'); // 6 = sábado, 7 = domingo
        if ($dow === 7) {
            $d->modify('+1 day');
        } elseif ($dow === 6) {
            if (in_array($provCod, self::GRUPO_VIERNES, true)) {
                $d->modify('-1 day');
            } elseif (in_array($provCod, self::GRUPO_LUNES, true)) {
                $d->modify('+2 days');
            }
        }
        return $d->format('Y-m-d');
    }

    /* ===================================================================== */
    /* Captura                                                               */
    /* ===================================================================== */

    /**
     * Valida y normaliza un valor según el tipo del campo. Devuelve
     * ['ok' => true, 'valor' => mixed|null] o ['ok' => false, 'error' => string].
     */
    public function normalizar(string $tipo, $valor): array
    {
        $valor = is_string($valor) ? trim($valor) : $valor;
        if ($valor === '' || $valor === null) {
            return ['ok' => true, 'valor' => null];
        }
        if ($tipo === 'estacion') {
            $cod = (int)$valor;
            $r = $this->sql->selectSafe("SELECT Codigo FROM TG.dbo.Estaciones WHERE Codigo = ? AND Codigo > 0", [$cod]);
            return $r ? ['ok' => true, 'valor' => $cod] : ['ok' => false, 'error' => 'Estación inválida'];
        }
        if ($tipo === 'fecha') {
            $d = DateTime::createFromFormat('Y-m-d', (string)$valor);
            return ($d && $d->format('Y-m-d') === $valor) ? ['ok' => true, 'valor' => $valor] : ['ok' => false, 'error' => 'Fecha inválida'];
        }
        if ($tipo === 'producto') {
            return in_array($valor, self::CLAVES_COMBUSTIBLE, true) ? ['ok' => true, 'valor' => $valor] : ['ok' => false, 'error' => 'Producto inválido'];
        }
        if ($tipo === 'decimal') {
            $n = str_replace([',', '$', ' '], '', (string)$valor);
            return is_numeric($n) ? ['ok' => true, 'valor' => round((float)$n, 6)] : ['ok' => false, 'error' => 'Número inválido'];
        }
        if ($tipo === 'iva') {
            $n = str_replace(['%', ' '], '', (string)$valor);
            if (!is_numeric($n)) return ['ok' => false, 'error' => 'IVA inválido'];
            $n = (float)$n;
            if ($n > 1) $n /= 100;
            return ($n >= 0 && $n <= 1) ? ['ok' => true, 'valor' => round($n, 4)] : ['ok' => false, 'error' => 'IVA inválido'];
        }
        if (strpos($tipo, 'texto:') === 0) {
            $max = (int)substr($tipo, 6);
            return mb_strlen((string)$valor) <= $max ? ['ok' => true, 'valor' => (string)$valor] : ['ok' => false, 'error' => "Máximo $max caracteres"];
        }
        return ['ok' => false, 'error' => 'Tipo de campo desconocido'];
    }

    /** Valor actual de un campo capturado de una factura (null si no hay captura). */
    public function valorFactura(string $uuid, string $campo)
    {
        $r = $this->sql->selectSafe("SELECT CONVERT(varchar(500), [$campo], 23) AS v FROM TG.dbo.compras_factura_datos WHERE uuid = ?", [$uuid]);
        return $r ? $r[0]['v'] : null;
    }

    public function facturaExiste(string $uuid): bool
    {
        $r = $this->sql->selectSafe("SELECT 1 AS x FROM TG.dbo.FacturasRecibidas WHERE UUID = ?", [$uuid]);
        return !empty($r);
    }

    /** Inserta o actualiza un campo de compras_factura_datos. $campo ya viene de la lista blanca. */
    public function guardarCampoFactura(string $uuid, string $campo, $valor, ?int $userId): bool
    {
        $existe = $this->sql->selectSafe("SELECT 1 AS x FROM TG.dbo.compras_factura_datos WHERE uuid = ?", [$uuid]);
        if ($existe === false) {
            return false;
        }
        if ($existe) {
            return $this->sql->updateSafe(
                "UPDATE TG.dbo.compras_factura_datos SET [$campo] = ?, updated_by = ?, updated_at = GETDATE() WHERE uuid = ?",
                [$valor, $userId, $uuid]
            ) !== false;
        }
        return (bool)$this->sql->insert(
            "INSERT INTO TG.dbo.compras_factura_datos (uuid, [$campo], updated_by) VALUES (?, ?, ?)",
            [$uuid, $valor, $userId]
        );
    }

    public function valorManual(int $id, string $campo)
    {
        $r = $this->sql->selectSafe("SELECT CONVERT(varchar(500), [$campo], 23) AS v FROM TG.dbo.compras_renglon_manual WHERE id = ? AND is_deleted = 0", [$id]);
        return $r === false ? false : ($r ? ['v' => $r[0]['v']] : null);
    }

    public function guardarCampoManual(int $id, string $campo, $valor, ?int $userId): bool
    {
        return $this->sql->updateSafe(
            "UPDATE TG.dbo.compras_renglon_manual SET [$campo] = ?, updated_by = ?, updated_at = GETDATE() WHERE id = ? AND is_deleted = 0",
            [$valor, $userId, $id]
        ) !== false;
    }

    /** @return int|false id nuevo */
    public function crearManual(array $d, ?int $userId)
    {
        $cols   = array_keys($d);
        $params = array_values($d);
        $params[] = $userId;
        $sql = 'INSERT INTO TG.dbo.compras_renglon_manual (' . implode(',', array_map(fn($c) => "[$c]", $cols)) . ', created_by) VALUES ('
             . implode(',', array_fill(0, count($cols) + 1, '?')) . ')';
        $id = $this->sql->insert($sql, $params);
        return $id ? (int)$id : false;
    }

    public function eliminarManual(int $id, ?int $userId): bool
    {
        return $this->sql->updateSafe(
            "UPDATE TG.dbo.compras_renglon_manual SET is_deleted = 1, updated_by = ?, updated_at = GETDATE() WHERE id = ? AND is_deleted = 0",
            [$userId, $id]
        ) > 0;
    }

    public function auditar(string $tipo, string $clave, string $accion, ?string $campo, $antes, $despues, ?int $userId, ?string $userNombre): void
    {
        $this->sql->insert(
            "INSERT INTO TG.dbo.compras_audit_log (tipo, clave, accion, campo, valor_anterior, valor_nuevo, usuario_id, usuario_nombre)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [$tipo, $clave, $accion, $campo,
             $antes === null ? null : (string)$antes, $despues === null ? null : (string)$despues,
             $userId, $userNombre]
        );
    }

    /** Proveedores de combustible con facturas en el último medio año, para el filtro. */
    public function proveedores(): array
    {
        $rows = $this->sql->selectSafe("
            SELECT DISTINCT pv.rfc, pv.den
            FROM SG12.dbo.Proveedores pv
            WHERE pv.rfc IN (SELECT DISTINCT EmisorRfc FROM TG.dbo.FacturasRecibidas WHERE Fecha >= DATEADD(month, -6, GETDATE()))
            ORDER BY pv.den");
        $rows = $rows ?: [];
        foreach ($rows as &$r) {
            $r['den'] = $this->nombreCorto($r['rfc'], $r['den']);
        }
        unset($r);
        return $rows;
    }

    public function estaciones(): array
    {
        $rows = $this->sql->selectSafe("SELECT Codigo, Nombre FROM TG.dbo.Estaciones WHERE Codigo > 0 AND activa = 1 ORDER BY Nombre");
        return $rows ?: [];
    }
}
