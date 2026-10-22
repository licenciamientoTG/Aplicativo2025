<?php
declare(strict_types=1);

/**
 * DespachosSql — fragmentos SQL y utilidades de validación compartidos por
 * DespachosModel.
 *
 * Antes de esta clase, las mismas reglas de negocio (serie del folio, tipo de
 * cliente, forma de pago, formato de fecha/hora) estaban copiadas a mano en
 * 6+ consultas y ya habían empezado a divergir (p. ej. el reporte "por
 * facturación" no reconocía las series I y E). Ahora hay UNA definición de
 * cada regla.
 *
 * Reglas de uso:
 *  - Todo es estático y puro: devuelve texto SQL, nunca ejecuta nada.
 *  - Los fragmentos asumen los alias del reporte de despachos:
 *      t1  = Despachos            t3  = DocumentosC
 *      t10 = ClientesValores      t13 = Valores (banco/denominación)
 *  - Nada que venga del usuario se concatena sin pasar antes por
 *    listaEnteros(), nombreSeguro() o una lista blanca de este archivo.
 *
 * Convención de fechas del esquema SG12: las columnas fchtrn/fchcor/fch son
 * enteros = (días desde 1900-01-01) + 1; por eso "fecha real" = DATEADD(day,-1,x).
 */
final class DespachosSql
{
    // ---------------------------------------------------------------------
    // Constantes de negocio
    // ---------------------------------------------------------------------

    /** Cliente (codopr) al que se le emite la Factura Global del público en general. */
    public const CODOPR_FACTURA_GLOBAL = 21701354;

    /** Clientes.tipval */
    public const TIPVAL_CREDITO = 3;
    public const TIPVAL_DEBITO  = 4;

    /** Despachos.tiptrn */
    public const TIPTRN_EFECTIVO        = 0;
    public const TIPTRN_TARJETA_CREDITO = 51;
    public const TIPTRN_TARJETA_DEBITO  = 52;
    public const TIPTRN_MONEDERO        = 53;
    public const TIPTRN_JARREO_PRUEBA   = 65;
    public const TIPTRN_JARREO          = 74;

    /** ClientesValores.codval */
    public const CODVAL_CLIENTE_CREDITO = 28;
    public const CODVAL_CLIENTE_DEBITO  = 127;

    /** Productos de combustible (Regular, Premium, Diesel y sus variantes). */
    public const PRODUCTOS_COMBUSTIBLE = [179, 180, 181, 192, 193];

    /** Turnos de tabulador en orden cronológico (nrotur). */
    public const TURNOS = [11, 21, 31, 41];

    /**
     * Series de folio según el rango numérico de DocumentosC.nro / Despachos.nrofac.
     * El folio visible es "<SERIE> <dígitos desde la posición 4>".
     *
     * @var array<string, array{0:int,1:int}>
     */
    private const SERIES_FOLIO = [
        'Z' => [2100000000, 2499999999], // Factura global
        'T' => [2000000000, 2099999999], // Terminales
        'K' => [1900000000, 1999999999], // Web
        'C' => [1100000000, 1199999999], // Contado
        'I' => [1700000000, 1799999999], // Crédito
        'E' => [1300000000, 1399999999], // Consumos
    ];

    /**
     * Denominaciones (Valores.den) que se tratan como monedero electrónico.
     * Los espacios iniciales son parte del dato en la base; NO recortar.
     *
     * @var list<string>
     */
    private const VALORES_MONEDERO = [
        ' Tarjeta EfectiCard',
        ' SMARTBT - EFECTIVALE',
        ' Tarjeta TicketCar',
        ' Vale Efectivale',
        'Ultra Gas',
        ' Tarjeta Inburgas',
        ' Vale Edenred',
        ' Tarjetas Sodexo (Pluxee)',
        'Mobil FleetPro',
        ' SMARTBT - SODEXO WIZEO',
        ' Vale Sodexo',
    ];

    /**
     * Tipos de cliente: clave interna => [slug usado por los filtros, etiqueta mostrada].
     *
     * @var array<string, array{0:string,1:string}>
     */
    private const TIPO_CLIENTE = [
        'credito'  => ['cliente_credito', 'Cliente Crédito'],
        'debito'   => ['cliente_debito',  'Cliente Débito'],
        'monedero' => ['monedero',        'Monedero'],
        'contado'  => ['contado',         'Contado'],
        'global'   => ['factura_global',  'Factura Global'],
    ];

    /**
     * Lista blanca columna-del-DataTable => expresión SQL sobre el CTE central.
     * Las columnas "coalesce" se buscan y ordenan igual que se muestran.
     *
     * @var array<string, string>
     */
    public const COLUMNAS_REPORTE = [
        'fecha'                   => 'fecha',
        'hora_formateada'         => 'hora_formateada',
        'turno'                   => 'turno',
        'despacho'                => 'despacho',
        'producto'                => 'producto',
        'estacion'                => 'estacion',
        'empresa'                 => 'empresa',
        'cliente_fac'             => 'COALESCE(cliente_fac, cliente_des)',
        'cantidad'                => 'cantidad',
        'importe'                 => 'importe',
        'precio'                  => 'precio',
        'despachador'             => 'despachador',
        'tipo_pago'               => 'COALESCE(tipo_pago, tipo_pago_despacho)',
        'factura'                 => 'COALESCE(factura, factura_desp)',
        'FechaFactura'            => 'FechaFactura',
        'UUID'                    => 'UUID',
        'txtref'                  => 'txtref',
        'rut'                     => 'rut',
        'denominacion'            => 'denominacion',
        'codigo_cliente'          => 'codigo_cliente',
        'tipo_cliente'            => 'tipo_cliente',
        'tipo_cliente_aplicativo' => 'tipo_cliente_aplicativo',
        'vehiculo'                => 'vehiculo',
        'placas'                  => 'placas',
    ];

    /**
     * Igual que COLUMNAS_REPORTE pero para el CTE por estación, donde las
     * columnas ya vienen resueltas (COALESCE hecho dentro del CTE).
     *
     * @var array<string, string>
     */
    public const COLUMNAS_ESTACION = [
        'fecha' => 'fecha', 'hora_formateada' => 'hora_formateada', 'turno' => 'turno',
        'despacho' => 'despacho', 'producto' => 'producto', 'estacion' => 'estacion',
        'empresa' => 'empresa', 'cliente_fac' => 'cliente_fac', 'cantidad' => 'cantidad',
        'importe' => 'importe', 'precio' => 'precio', 'despachador' => 'despachador',
        'tipo_pago' => 'tipo_pago', 'factura' => 'factura', 'FechaFactura' => 'FechaFactura',
        'UUID' => '[UUID]', 'txtref' => 'txtref', 'rut' => 'rut',
        'denominacion' => 'denominacion', 'codigo_cliente' => 'codigo_cliente',
        'tipo_cliente' => 'tipo_cliente', 'tipo_cliente_aplicativo' => 'tipo_cliente_aplicativo',
        'vehiculo' => 'vehiculo', 'placas' => 'placas',
    ];

    private function __construct()
    {
        // Clase de utilidades: no se instancia.
    }

    // ---------------------------------------------------------------------
    // Fragmentos de expresión
    // ---------------------------------------------------------------------

    /**
     * Folio legible con serie: 2100005341 => 'Z 0005341'.
     * Fuera de los rangos conocidos devuelve el número tal cual (como texto).
     *
     * @param string $columna Columna numérica, p. ej. 't3.nro' o 't1.nrofac'.
     */
    public static function folio(string $columna): string
    {
        $texto = "CAST($columna AS VARCHAR(10))";
        $when  = '';
        foreach (self::SERIES_FOLIO as $serie => [$min, $max]) {
            $when .= "WHEN $columna BETWEEN $min AND $max THEN '$serie ' + SUBSTRING($texto, 4, 10)\n";
        }
        return "CASE\n{$when}ELSE $texto\nEND";
    }

    /** Fecha real ISO (yyyy-mm-dd) a partir de la columna entera de fecha. */
    public static function fechaIso(string $columna): string
    {
        return "CONVERT(VARCHAR(10), DATEADD(day, -1, $columna), 23)";
    }

    /** Hora como TIME(0) (hh:mm:ss) a partir de hratrn (formato hhmm). */
    public static function horaTime(string $columna = 't1.hratrn'): string
    {
        return "CAST(CONVERT(TIME, DATEADD(MINUTE, $columna % 100, DATEADD(HOUR, $columna / 100, 0))) AS TIME(0))";
    }

    /** Hora como texto 'hh:mm' a partir de hratrn (formato hhmm). */
    public static function horaTexto(string $columna = 't1.hratrn'): string
    {
        return "CONVERT(VARCHAR(5), CONVERT(TIME, DATEADD(MINUTE, $columna % 100, DATEADD(HOUR, $columna / 100, 0))))";
    }

    /**
     * Expresión CASE del tipo de cliente.
     *
     * @param bool $slug        true => 'cliente_credito'… (para filtrar); false => etiqueta visible.
     * @param bool $porValores  true => antepone la regla "Monedero por denominación del banco"
     *                          (columna "Tipo Aplicativo"); requiere el alias t13.
     */
    public static function tipoCliente(bool $slug = false, bool $porValores = false): string
    {
        $i = $slug ? 0 : 1;
        $g = self::CODOPR_FACTURA_GLOBAL;

        $monederoPorValor = '';
        if ($porValores) {
            $lista = implode(',', array_map(static fn (string $v): string => "'$v'", self::VALORES_MONEDERO));
            $monederoPorValor = "WHEN t13.den IN ($lista) THEN '" . self::TIPO_CLIENTE['monedero'][$i] . "'\n";
        }

        return "CASE\n"
            . $monederoPorValor
            . self::reglasTipoCliente($i, $g, $porValores)
            . 'END';
    }

    /**
     * Cuerpo común del CASE de tipo de cliente. El orden importa:
     *  - "Aplicativo" evalúa Monedero (por tiptrn) ANTES que Crédito/Débito.
     *  - El tipo "visible" evalúa Crédito/Débito ANTES que Monedero.
     */
    private static function reglasTipoCliente(int $i, int $global, bool $monederoPrimero): string
    {
        $credito  = "WHEN t10.codval = " . self::CODVAL_CLIENTE_CREDITO . " THEN '" . self::TIPO_CLIENTE['credito'][$i] . "'\n";
        $debito   = "WHEN t10.codval = " . self::CODVAL_CLIENTE_DEBITO . " THEN '" . self::TIPO_CLIENTE['debito'][$i] . "'\n";
        $monedero = "WHEN t1.tiptrn = " . self::TIPTRN_MONEDERO . " AND t1.gasfac != 2 THEN '" . self::TIPO_CLIENTE['monedero'][$i] . "'\n";
        $contado  = "WHEN t3.codopr != $global AND t10.codval IS NULL THEN '" . self::TIPO_CLIENTE['contado'][$i] . "'\n";
        $globalSql = "WHEN t3.codopr = $global THEN '" . self::TIPO_CLIENTE['global'][$i] . "'\n";

        return ($monederoPrimero ? $monedero . $credito . $debito : $credito . $debito . $monedero)
            . $contado
            . $globalSql
            . "ELSE 'N/A'\n";
    }

    /**
     * Forma de pago según la factura (DocumentosC.tipref) — requiere alias t3.
     * Solo aplica a despachos con logmsk 2 o 3 (cobrados en el POS).
     */
    public static function pagoFactura(): string
    {
        return "CASE
                    WHEN t3.tipref = 4  AND t1.logmsk IN (2, 3) THEN 'Tarjeta Credito'
                    WHEN t3.tipref = 28 AND t1.logmsk IN (2, 3) THEN 'Tarjeta Debito'
                    WHEN t3.tipref = 1  AND t1.logmsk IN (2, 3) THEN 'Efectivo'
                END";
    }

    /** Forma de pago deducida del tipo de transacción del propio despacho. */
    public static function pagoDespacho(): string
    {
        return "CASE
                    WHEN t1.tiptrn = " . self::TIPTRN_TARJETA_CREDITO . " AND t1.gasfac != 2 THEN 'Tarjeta Credito'
                    WHEN t1.tiptrn = " . self::TIPTRN_TARJETA_DEBITO . " AND t1.gasfac != 2 THEN 'Tarjeta Debito'
                    WHEN t1.tiptrn = " . self::TIPTRN_MONEDERO . " AND t1.gasfac != 2 THEN 'Efectivale'
                    WHEN t1.tiptrn = " . self::TIPTRN_EFECTIVO . " THEN 'Efectivo'
                END";
    }

    /** Forma de pago final: la de la factura y, si no hay, la del despacho. */
    public static function pagoFinal(): string
    {
        return 'COALESCE(' . self::pagoFactura() . ', ' . self::pagoDespacho() . ')';
    }

    /** Serie + folio de la factura: la de DocumentosC y, si no hay, la que trae el despacho. */
    public static function folioFinal(): string
    {
        return 'COALESCE(' . self::folio('t3.nro') . ', ' . self::folio('t1.nrofac') . ')';
    }

    // ---------------------------------------------------------------------
    // Filtros del reporte (devuelven " AND ..." o '')
    // ---------------------------------------------------------------------

    /** @param int $modo 0 = todos, 1 = sin timbrar, 2 = timbrados */
    public static function filtroTimbrado(int $modo): string
    {
        return match ($modo) {
            1       => ' AND t1.satuid IS NULL AND t3.satuid IS NULL AND t1.tiptrn != ' . self::TIPTRN_MONEDERO,
            2       => ' AND (t1.satuid IS NOT NULL OR t3.satuid IS NOT NULL)',
            default => '',
        };
    }

    /** @param int $modo 0 = todos, 1 = sin facturar, 2 = facturados */
    public static function filtroFacturado(int $modo): string
    {
        return match ($modo) {
            1       => ' AND t3.nro IS NULL AND t1.nrofac = 0',
            2       => ' AND (t3.nro IS NOT NULL OR t1.nrofac != 0)',
            default => '',
        };
    }

    /**
     * Filtro por tipo de cliente a partir del slug que manda la pantalla.
     * '', '0' y 0 significan "sin filtro". Un slug desconocido NO devuelve
     * nada (igual que antes: comparaba contra un literal que nunca coincidía).
     */
    public static function filtroTipoCliente(string|int $slug): string
    {
        $slug = (string) $slug;
        if ($slug === '' || $slug === '0') {
            return '';
        }
        $validos = array_column(self::TIPO_CLIENTE, 0);
        if (!in_array($slug, $validos, true)) {
            return ' AND 1 = 0';
        }
        return ' AND ' . self::tipoCliente(true) . " = '$slug'";
    }

    /**
     * Etiqueta visible ('Cliente Crédito') de un slug de filtro ('cliente_credito').
     * null si el slug no existe. Sirve para filtrar contra la columna ya calculada
     * (`tipo_cliente = ?`) sin repetir el CASE.
     */
    public static function etiquetaTipoCliente(string|int $slug): ?string
    {
        foreach (self::TIPO_CLIENTE as [$clave, $etiqueta]) {
            if ($clave === (string) $slug) {
                return $etiqueta;
            }
        }
        return null;
    }

    // ---------------------------------------------------------------------
    // Búsqueda / orden de DataTables (todo con lista blanca + parámetros)
    // ---------------------------------------------------------------------

    /**
     * Arma el WHERE de búsqueda por columna y global de DataTables.
     *
     * @param array<string,string> $columnas    Lista blanca clave => expresión SQL.
     * @param array<string,mixed>  $porColumna  Búsquedas por columna ['clave' => 'texto'].
     * @return array{0:string,1:list<string>}   [fragmento " AND ...", parámetros posicionales]
     */
    public static function busqueda(array $columnas, array $porColumna, string $global, ?array $columnasGlobal = null): array
    {
        $sql    = '';
        $params = [];

        foreach ($porColumna as $clave => $valor) {
            if (!is_scalar($valor)) {
                continue;
            }
            $valor = (string) $valor;
            if ($valor !== '' && isset($columnas[$clave])) {
                $sql      .= ' AND CAST(' . $columnas[$clave] . ' AS NVARCHAR(MAX)) LIKE ?';
                $params[]  = '%' . $valor . '%';
            }
        }

        // La búsqueda global puede limitarse a unas cuantas columnas (24 CAST+LIKE con OR es muy caro).
        $globales = $columnasGlobal === null ? $columnas : array_intersect_key($columnas, array_flip($columnasGlobal));
        if ($global !== '' && $globales !== []) {
            $ors = [];
            foreach ($globales as $expresion) {
                $ors[]    = "CAST($expresion AS NVARCHAR(MAX)) LIKE ?";
                $params[] = '%' . $global . '%';
            }
            $sql .= ' AND (' . implode(' OR ', $ors) . ')';
        }

        return [$sql, $params];
    }

    /**
     * Columnas sobre las que actúa la búsqueda global del reporte (el resto solo
     * se busca por su filtro de columna).
     *
     * @var list<string>
     */
    public const COLUMNAS_BUSQUEDA_GLOBAL = ['despacho', 'cliente_fac', 'factura', 'UUID', 'rut', 'placas'];

    /**
     * Separa de las búsquedas por columna las que se pueden resolver con un
     * filtro exacto sobre Despachos ANTES del ROW_NUMBER (usan la PK/índices en
     * lugar de CAST(...) LIKE '%x%' sobre todo el CTE):
     *  - despacho / codigo_cliente: número entero => igualdad.
     *  - fecha: fecha ISO completa (aaaa-mm-dd) => igualdad sobre fchtrn.
     * Cualquier otro valor (parcial, texto) se queda como búsqueda LIKE normal.
     *
     * @param array<string,mixed> $porColumna
     * @return array{0:string,1:array<string,mixed>} [" AND ..." para el WHERE interno, búsquedas restantes]
     */
    public static function filtrosExactos(array $porColumna): array
    {
        $sql = '';
        foreach ($porColumna as $clave => $valor) {
            if (!is_scalar($valor)) {
                continue;
            }
            $valor = trim((string) $valor);
            if (($clave === 'despacho' || $clave === 'codigo_cliente') && preg_match('/^-?\d{1,18}$/', $valor) === 1) {
                $col  = $clave === 'despacho' ? 't1.nrotrn' : 't1.codcli';
                $sql .= " AND $col = " . (int) $valor;
                unset($porColumna[$clave]);
            } elseif ($clave === 'fecha' && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $valor, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                // fchtrn guarda la fecha como entero con 1 día de desfase (ver fechaIso()).
                // Formato aaaammdd: 'aaaa-mm-dd' se interpreta como aaaa-dd-mm según el idioma de la sesión.
                $sql .= " AND t1.fchtrn = DATEDIFF(day, 0, '$m[1]$m[2]$m[3]') + 1";
                unset($porColumna[$clave]);
            }
        }
        return [$sql, $porColumna];
    }

    /**
     * Resuelve ORDER BY seguro: la columna sale de la lista blanca (por defecto
     * 'fecha') y la dirección solo puede ser ASC o DESC.
     *
     * @param array<string,string> $columnas
     * @return array{0:string,1:'ASC'|'DESC'} [expresión, dirección]
     */
    public static function orden(array $columnas, string $clave, string $direccion): array
    {
        return [
            $columnas[$clave] ?? 'fecha',
            strtolower($direccion) === 'desc' ? 'DESC' : 'ASC',
        ];
    }

    // ---------------------------------------------------------------------
    // Validación de valores que se interpolan en SQL
    // ---------------------------------------------------------------------

    /**
     * Convierte una lista de enteros (array o "1,2,3") en "1,2,3" garantizando
     * que cada elemento es un entero. Para usar dentro de IN (...).
     *
     * @param int|string|array<int|string> $valores
     * @throws InvalidArgumentException si la lista está vacía o trae algo que no es entero.
     */
    public static function listaEnteros(int|string|array $valores): string
    {
        $items = is_array($valores) ? $valores : explode(',', (string) $valores);
        $ints  = [];
        foreach ($items as $item) {
            $item = trim((string) $item);
            if ($item === '' || preg_match('/^-?\d+$/', $item) !== 1) {
                throw new InvalidArgumentException('Lista de enteros inválida.');
            }
            $ints[] = (int) $item;
        }
        if ($ints === []) {
            throw new InvalidArgumentException('La lista de enteros está vacía.');
        }
        return implode(',', $ints);
    }

    /**
     * Nombre de servidor/base que se va a incrustar en SQL dinámico
     * (OPENQUERY, linked servers). Acepta solo caracteres que usan nuestros
     * nombres reales (p. ej. "SG12_11007+2020", "192.168.7.101").
     *
     * @throws InvalidArgumentException
     */
    public static function nombreSeguro(string $nombre): string
    {
        if (preg_match('/^[\w.+\- ]{1,128}$/u', $nombre) !== 1) {
            throw new InvalidArgumentException('Nombre de servidor o base de datos inválido.');
        }
        return $nombre;
    }

    /** Aplica los filtros comunes del reporte corporativo (estación, timbrado, facturado, tipo de cliente). */
    public static function filtrosReporte(int $codgas, int $uuid, int $billed, string|int $tipoCliente): string
    {
        return ($codgas !== 0 ? "AND t1.codgas = $codgas" : '')
            . self::filtroTimbrado($uuid)
            . self::filtroFacturado($billed)
            . self::filtroTipoCliente($tipoCliente);
    }
}
