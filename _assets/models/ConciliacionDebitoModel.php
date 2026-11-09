<?php
/**
 * ConciliacionDebitoModel — Ingresos > Conciliación débito (prepago): venta a clientes
 * de débito contra la facturación de consumos (Serie E) de ControlGas.
 * Diseño: docs/superpowers/specs/2026-11-08-conciliacion-debito-design.md
 *
 * Fechas: enteros de ControlGas (dateToInt), los mismos para Despachos.fchtrn y DocumentosC.fch.
 * $empresa: 0 = todas, -1 = sin razón social, >0 = SG12.Empresas.cod.
 * Consumos y consumos propios no se consultan aquí: usan ClientesModel (tipval 4).
 *
 * Tablas temporales + OPTION (RECOMPILE), igual que ConciliacionVentaModel.
 */
class ConciliacionDebitoModel extends Model {

    /** Serie E (facturación de consumos): DocumentosC.nro 1300000000–1399999999 (serie D = 12…). */
    public const SERIE_E_DESDE = 1300000000;
    public const SERIE_E_HASTA = 1399999999;

    /** Filtro de razón social sobre el alias de SG12.Empresas ya unido (EMP). */
    private const EMP_FILTER = "(@emp = 0 OR (@emp = -1 AND EMP.cod IS NULL) OR EMP.cod = @emp)";

    /**
     * Facturas Serie E con fecha entre $from y $until + 10 días (el controlador descarta las posteriores
     * a $until que no sean "Mes siguiente"). La razón social sale de DocumentosC.codemp. Una fila por factura:
     * total = SUM(Documentos.mto) / 100; cancelada = txtref '@N:CANCELAD%'.
     */
    public function facturas_serie_e(int $from, int $until, int $empresa): array {
        $emp = self::EMP_FILTER;
        $desde = self::SERIE_E_DESDE;
        $hasta = self::SERIE_E_HASTA;
        $query = "
            SET NOCOUNT ON;
            DECLARE @d int = ?, @h int = ?, @emp int = ?;
            DROP TABLE IF EXISTS #k, #t;
            CREATE TABLE #k (codgas int NOT NULL, nro int NOT NULL, PRIMARY KEY (codgas, nro));

            INSERT INTO #k (codgas, nro)
            SELECT c.codgas, c.nro
              FROM SG12.dbo.DocumentosC c WITH (NOLOCK)
              LEFT JOIN SG12.dbo.Empresas EMP WITH (NOLOCK) ON EMP.cod = c.codemp
             WHERE c.tip = 3 AND c.codgas = 0
               AND c.nro BETWEEN {$desde} AND {$hasta}
               AND c.fch BETWEEN @d AND @h + 10
               AND {$emp}
            OPTION (RECOMPILE);

            SELECT d.codgas, d.nro, SUM(d.mto) / 100.0 AS total
              INTO #t
              FROM #k k
              JOIN SG12.dbo.Documentos d WITH (NOLOCK) ON d.nro = k.nro AND d.codgas = k.codgas AND d.tip = 3
             GROUP BY d.codgas, d.nro;

            SELECT k.codgas, k.nro, c.fch,
                   CONVERT(varchar(10), DATEADD(day, c.fch - 1, '19000101'), 23) AS fecha,
                   c.codopr AS codcli, cl.den AS cliente, c.subope,
                   ISNULL(t.total, 0) AS total,
                   CASE WHEN CAST(c.txtref AS varchar(20)) LIKE '@N:CANCELAD%' THEN 1 ELSE 0 END AS cancelada,
                   c.satuid,
                   CAST(c.txtref AS nvarchar(500)) AS txtref,
                   c.codemp AS empresa
              FROM #k k
              JOIN SG12.dbo.DocumentosC c WITH (NOLOCK) ON c.nro = k.nro AND c.codgas = k.codgas AND c.tip = 3
              LEFT JOIN SG12.dbo.Clientes cl WITH (NOLOCK) ON cl.cod = c.codopr
              LEFT JOIN #t t ON t.codgas = k.codgas AND t.nro = k.nro
             ORDER BY c.fch, k.nro;

            DROP TABLE IF EXISTS #k, #t;";
        return $this->sql->select($query, [$from, $until, $empresa]) ?: [];
    }

    /**
     * Despachos de clientes de débito (tipval 4) del rango sin factura (nrofac = 0) y con monto.
     * Mismas condiciones que ClientesModel::get_dispatches_summary_by_client en débito: sin filtro
     * de terminal bancaria (solo aplica a crédito) y con los consumos propios incluidos (categoria los marca).
     */
    public function despachos_pendientes(int $from, int $until, int $empresa): array {
        $emp = self::EMP_FILTER;
        $query = "
            SET NOCOUNT ON;
            DECLARE @d int = ?, @h int = ?, @emp int = ?;
            DROP TABLE IF EXISTS #d;

            SELECT t.codgas, t.nrotrn, t.fchtrn, t.hratrn, t.codprd, t.codcli, t.can, t.mto
              INTO #d
              FROM SG12.dbo.Despachos t WITH (NOLOCK)
              JOIN SG12.dbo.Clientes c WITH (NOLOCK) ON c.cod = t.codcli AND c.tipval = 4
              LEFT JOIN TG.dbo.Estaciones E WITH (NOLOCK) ON E.Codigo = t.codgas
              LEFT JOIN SG12.dbo.Empresas EMP WITH (NOLOCK) ON EMP.cod = E.codemp
             WHERE t.fchtrn BETWEEN @d AND @h
               AND t.codcli > 0
               AND ISNULL(t.nrofac, 0) = 0
               AND t.mto <> 0
               AND {$emp}
            OPTION (RECOMPILE);

            SELECT CONVERT(varchar(10), DATEADD(day, d.fchtrn - 1, '19000101'), 23) AS fecha,
                   d.hratrn, d.nrotrn, g.abr AS estacion, p.den AS producto,
                   d.can AS litros, d.mto AS monto, d.codcli, cl.den AS cliente,
                   ISNULL(CP.categoria, '') AS categoria
              FROM #d d
              LEFT JOIN SG12.dbo.Gasolineras g WITH (NOLOCK) ON g.cod = d.codgas
              LEFT JOIN SG12.dbo.Productos p WITH (NOLOCK) ON p.cod = d.codprd
              LEFT JOIN SG12.dbo.Clientes cl WITH (NOLOCK) ON cl.cod = d.codcli
              LEFT JOIN TG.dbo.clientes_consumo_propio CP WITH (NOLOCK) ON CP.codcli = d.codcli AND CP.activo = 1
             ORDER BY d.fchtrn, d.hratrn, d.codgas
            OPTION (RECOMPILE);

            DROP TABLE IF EXISTS #d;";
        return $this->sql->select($query, [$from, $until, $empresa]) ?: [];
    }

    /**
     * Facturas de anticipo (serie D, subope 18) del rango: el mismo reporte de Clientes > Anticipos débito
     * (ClientesModel::get_debit_anticipos_report) más:
     *  - cobro_aplicado: suma de DocumentosA.mtoapl / 100 de los cobros prepago (tip 4, subope 19, no cancelados)
     *    del MISMO rango que aplican a la factura (nroapl). Los consumos que la factura va "gastando" (Serie E, tip 3)
     *    y los cobros de otros meses no cuentan: un cobro de octubre no cubre un anticipo de septiembre.
     *  - estatus: 'Con cobro' | 'Sin cobro' (aplicado < total − 0.01).
     */
    public function anticipos(int $from, int $until, int $empresa): array {
        $filas = (new ClientesModel)->get_debit_anticipos_report($from, $until, 0, $empresa) ?: [];
        if (!$filas) return [];
        $nros = array_map(fn($f) => (int)$f['Factura'], $filas);
        $query = "
            SET NOCOUNT ON;
            SELECT a.nroapl AS nro, SUM(a.mtoapl) / 100.0 AS aplicado
              FROM SG12.dbo.DocumentosA a WITH (NOLOCK)
              JOIN SG12.dbo.DocumentosC c WITH (NOLOCK) ON c.nro = a.nro AND c.codgas = a.codgas AND c.tip = a.tip
             WHERE a.tip = 4
               AND a.nroapl BETWEEN ? AND ?
               AND c.subope = " . ConciliacionVentaModel::SUBOPE_COBRO_DEBITO . "
               AND c.fch BETWEEN ? AND ?
               AND CAST(c.txtref AS varchar(40)) NOT LIKE '@N:CANCELAD%'
             GROUP BY a.nroapl
            OPTION (RECOMPILE);";
        $aplicado = [];
        foreach ($this->sql->select($query, [min($nros), max($nros), $from, $until]) ?: [] as $r) {
            $aplicado[(int)$r['nro']] = (float)$r['aplicado'];
        }
        foreach ($filas as &$f) {
            $total = round((float)$f['Total'], 2);
            $cobro = round($aplicado[(int)$f['Factura']] ?? 0.0, 2);
            $f['cobro_aplicado'] = $cobro;
            $f['estatus'] = $cobro < $total - 0.01 ? 'Sin cobro' : 'Con cobro';
        }
        unset($f);
        return $filas;
    }

    /**
     * Facturas de anticipo (serie D, subope 18) canceladas con fecha en el rango. Las canceladas ya no conservan líneas
     * en Documentos: no se conoce el importe, solo número, fecha y cliente. Razón social por DocumentosC.codemp.
     */
    public function anticipos_cancelados(int $from, int $until, int $empresa): array {
        $emp = self::EMP_FILTER;
        $query = "
            SET NOCOUNT ON;
            DECLARE @d int = ?, @h int = ?, @emp int = ?;
            SELECT c.nro, CONVERT(varchar(10), DATEADD(day, c.fch - 1, '19000101'), 23) AS fecha,
                   c.codopr AS codcli, cl.den AS cliente,
                   CONVERT(varchar(10), c.logfch, 23) AS fecha_cancelacion
              FROM SG12.dbo.DocumentosC c WITH (NOLOCK)
              LEFT JOIN SG12.dbo.Empresas EMP WITH (NOLOCK) ON EMP.cod = c.codemp
              LEFT JOIN SG12.dbo.Clientes cl WITH (NOLOCK) ON cl.cod = c.codopr
             WHERE c.tip = 3 AND c.codgas = 0 AND c.subope = 18
               AND c.nro BETWEEN 1200000000 AND 1299999999
               AND CAST(c.txtref AS varchar(40)) LIKE '@N:CANCELAD%'
               AND c.fch BETWEEN @d AND @h
               AND {$emp}
             ORDER BY c.fch, c.nro
            OPTION (RECOMPILE);";
        return $this->sql->select($query, [$from, $until, $empresa]) ?: [];
    }

    /**
     * Anticipos agrupados por cliente: monto anticipado del rango y saldo actual (Clientes.debsdo, en pesos).
     * Una fila por cliente, ordenada por nombre.
     */
    public function anticipos_clientes(int $from, int $until, int $empresa): array {
        $por = [];
        foreach ($this->anticipos($from, $until, $empresa) as $f) {
            $k = (int)$f['codcli'];
            $por[$k] ??= ['codcli' => $k, 'cliente' => trim((string)$f['Cliente']), 'anticipos' => 0, 'monto' => 0.0, 'saldo' => null];
            $por[$k]['anticipos']++;
            $por[$k]['monto'] += (float)$f['Total'];
        }
        if (!$por) return [];
        $query = "SELECT cod, debsdo FROM SG12.dbo.Clientes WITH (NOLOCK) WHERE cod IN (" . implode(',', array_map('intval', array_keys($por))) . ")";
        foreach ($this->sql->select($query, []) ?: [] as $r) {
            $por[(int)$r['cod']]['saldo'] = round((float)$r['debsdo'], 2);
        }
        foreach ($por as &$p) $p['monto'] = round($p['monto'], 2);
        unset($p);
        usort($por, fn($a, $b) => strcmp($a['cliente'], $b['cliente']));
        return $por;
    }

    /**
     * Cobros prepago (tip 4, subope 19) del rango, sin cancelar, de la razón social pedida
     * (la de la estación del cobro; 0 = todas, -1 = sin razón social). Cada fila trae `facturas`
     * ("nroapl:mto,…" de DocumentosA) y txtref, como ConciliacionVentaModel::cobros.
     */
    public function cobros_prepago(int $from, int $until, int $empresa): array {
        $out = [];
        foreach ((new ConciliacionVentaModel)->cobros($from, $until, ConciliacionVentaModel::SUBOPE_COBRO_DEBITO) as $r) {
            if ((int)$r['cancelado'] === 1) continue;
            $emp = $r['empresa'] === null ? -1 : (int)$r['empresa'];
            if ($empresa !== 0 && $emp !== $empresa) continue;
            $out[] = $r;
        }
        return $out;
    }

    /** Cuentas donde cae el prepago: BANORTE 2791 (depósitos) y 0956 (tarjetas). */
    public const CUENTAS_DEBITO = ['0186932791', '0601500956'];

    /**
     * Números de cuenta propios (para detectar traspasos): TG.dbo.CatalogosCuentasBancarias.CuentaLocal con
     * Tipo = 'Propias' y Activo = 1 (TipoCuenta no sirve: viene NULL en las cuentas de Banorte; ver spec §4).
     */
    public function cuentas_propias(): array {
        $rows = $this->sql->select("SELECT DISTINCT LTRIM(RTRIM(CuentaLocal)) AS cuenta
                                      FROM TG.dbo.CatalogosCuentasBancarias WITH (NOLOCK)
                                     WHERE Tipo = 'Propias' AND Activo = 1 AND CuentaLocal IS NOT NULL") ?: [];
        return array_values(array_filter(array_column($rows, 'cuenta'), fn($c) => $c !== ''));
    }

    /** codcli de los clientes de consumo propio activos (TG.dbo.clientes_consumo_propio). */
    public function clientes_consumo_propio(): array {
        $rows = $this->sql->select("SELECT codcli FROM TG.dbo.clientes_consumo_propio WITH (NOLOCK) WHERE activo = 1") ?: [];
        return array_map('intval', array_column($rows, 'codcli'));
    }

    /**
     * Cruce cobros ↔ depósitos (ConciliacionCobros::conciliar): arma el MOV de cada cobro, trae los depósitos con la
     * ventana DIAS_* (opcionalmente solo de $cuentas) y devuelve los cobros en el mismo orden con su estatus de cruce.
     * $desde / $hasta: 'Y-m-d' del mes.
     */
    public function cruce(array $cobros, string $desde, string $hasta, array $cuentas = []): array {
        $cobros = array_map(function ($r) {
            $r['mov'] = ConciliacionCobros::movDeTxtref((string)$r['txtref']);
            return $r;
        }, $cobros);
        $dias = fn(string $fecha, int $n) => date('Y-m-d', strtotime($fecha . sprintf(' %+d days', $n)));
        $depositos = (new ConciliacionVentaModel)->depositos(
            array_column($cobros, 'mov'),
            array_column($cobros, 'monto'),
            $dias($desde, -ConciliacionCobros::DIAS_ANTES), $dias($hasta, ConciliacionCobros::DIAS_DESPUES),
            $dias($desde, -ConciliacionCobros::DIAS_IMPORTE), $dias($hasta, ConciliacionCobros::DIAS_IMPORTE),
            $cuentas
        );
        return ConciliacionCobros::conciliar($cobros, $depositos);
    }

    /**
     * Cobros prepago (subope 19) de TODAS las razones sociales cruzados con los depósitos de 2791 y 0956, con el estatus
     * final de ConciliacionDebito::estatusCruce (Efectivo, Depositado en mes anterior, Consumo propio, No localizado).
     * El controlador filtra la razón social después, porque un MOV puede juntar cobros de varias.
     */
    public function cruce_prepago(int $from, int $until, string $desde, string $hasta): array {
        $cobros = (new ConciliacionVentaModel)->cobros($from, $until, ConciliacionVentaModel::SUBOPE_COBRO_DEBITO);
        $propios = $this->clientes_consumo_propio();
        $rows = $this->cruce($cobros, $desde, $hasta, self::CUENTAS_DEBITO);
        foreach ($rows as &$r) {
            $r['estatus_cruce'] = $r['estatus'];
            $r['estatus'] = ConciliacionDebito::estatusCruce($r, $propios, $desde);
        }
        unset($r);
        return $rows;
    }

    /** Ids de depósito ligados por el cruce de cobros de crédito (subope 8) del mes, mismo procedimiento que Conciliación crédito. */
    public function depositos_credito(int $from, int $until, string $desde, string $hasta): array {
        $cobros = (new ConciliacionVentaModel)->cobros($from, $until, ConciliacionVentaModel::SUBOPE_COBRANZA);
        $ids = [];
        foreach ($this->cruce($cobros, $desde, $hasta) as $r) {
            if (($r['deposito_id'] ?? null) !== null && strncmp((string)$r['estatus'], 'Cuadra', 6) === 0) $ids[$r['deposito_id']] = true;
        }
        return array_keys($ids);
    }

    /** Ids de depósito ligados (Cuadra*, incluso de mes anterior) en filas de cruce_prepago. */
    public static function ids_ligados(array $filasCruce): array {
        $ids = [];
        foreach ($filasCruce as $r) {
            if (($r['deposito_id'] ?? null) === null) continue;
            if (strncmp((string)$r['estatus_cruce'], 'Cuadra', 6) === 0) $ids[$r['deposito_id']] = true;
        }
        return array_keys($ids);
    }

    /**
     * Movimientos del mes de una cuenta de débito con su clasificación (ConciliacionDebito::clasificarMovimiento).
     * Misma ruta de cálculo que el endpoint: cruce prepago + cruce crédito + cuentas propias.
     */
    public function movimientos_clasificados(int $from, int $until, string $desde, string $hasta, string $cuenta, ?array $crucePrepago = null): array {
        $cruce = $crucePrepago ?? $this->cruce_prepago($from, $until, $desde, $hasta);
        $idsPrepago = self::ids_ligados($cruce);
        $idsCredito = $this->depositos_credito($from, $until, $desde, $hasta);
        $propias = $this->cuentas_propias();
        $out = [];
        foreach ((new ConciliacionVentaModel)->movimientos_banco($desde, $hasta, [$cuenta]) as $r) {
            $mov = [
                'id'          => (int)$r['id'],
                'banco'       => $r['banco'],
                'cuenta'      => $r['cuenta'],
                'fecha'       => $r['fecha'],
                'fecha_operacion' => $r['fecha_operacion'] ?? '',
                'secuencia'   => $r['secuencia'],
                'descripcion' => trim((string)$r['descripcion']),
                'referencia'  => trim((string)$r['referencia']),
                'abono'       => round((float)$r['abono'], 2),
                'cargo'       => round((float)$r['cargo'], 2),
                'saldo'       => $r['saldo'] === null ? null : round((float)$r['saldo'], 2),
                'contraparte' => trim((string)$r['nombre_contraparte']),
                'rfc'         => trim((string)$r['rfc_contraparte']),
                'detalle'     => trim((string)$r['detalle']),
            ];
            $mov['clasificacion'] = ConciliacionDebito::clasificarMovimiento($mov, $idsPrepago, $idsCredito, $propias);
            $out[] = $mov;
        }
        return $out;
    }
}
