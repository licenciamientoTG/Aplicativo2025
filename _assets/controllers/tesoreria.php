<?php

/**
 * Tesorería.
 *
 * Sección Movimientos bancos: tabla consultable de los movimientos
 * bancarios diarios (TG.dbo.movimientos_bancarios) e importación del TXT
 * de movimientos de Enlace Santander (ancho fijo, 630 chars/línea).
 *
 * Rutas: /tesoreria/[metodo]  (autocargado por index.php)
 * Spec:  docs/superpowers/specs/2026-07-22-tesoreria-movimientos-bancos-design.md
 * Schema: docs/sql/tesoreria_schema.sql
 */
class Tesoreria
{
    private const PERM_MOVIMIENTOS  = 80;   // Ver Movimientos Bancos
    private const PERM_CUENTAS      = 79;   // Ver Cuentas Bancarias
    private const PERM_SUBIR_MOV    = 81;   // Subir Movimientos Bancos
    private const PERM_SALDO_GRUPO  = 82;   // Ver Saldo por Grupo
    private const MAX_TXT_BYTES     = 10 * 1024 * 1024;

    /** Grupo de saldos para cuentas que no están en CatalogosCuentasBancarias. */
    private const GRUPO_SIN_CATALOGO = 'SIN CATÁLOGO';

    /**
     * Grupos empresariales para el panel "Saldo final por grupo": a qué grupo
     * pertenece cada razón social, por coincidencia de texto en su
     * Descripcion del catálogo. Lo que no empate cae en OTRAS.
     *
     * El orden importa: se toma el primer grupo que empate.
     */
    private const GRUPOS_EMPRESA = [
        'ALIANZA COMERCIAL' => ['GASOMEX', 'GASO MEX', 'CLARA', 'JARUDO'],
        'SMA'               => ['VENTANAS', 'PICACHOS'],
        // "DIAZ GAS" y no "DIAZ" a secas: INMO DIAZ es otra empresa y va en OTRAS
        'DIAZ GAS'          => ['DIAZ GAS'],
    ];

    /** Grupo al que pertenece una razón social. */
    private static function grupo_de(string $descripcion): string
    {
        $d = mb_strtoupper($descripcion);
        foreach (self::GRUPOS_EMPRESA as $grupo => $claves) {
            foreach ($claves as $clave) {
                if (mb_strpos($d, $clave) !== false) return $grupo;
            }
        }
        return 'OTRAS';
    }

    /**
     * Bancos soportados. Agregar uno nuevo es una entrada aquí más su parser
     * en MovimientosBancariosModel; el endpoint de importación, los KPI por
     * banco y los tabs de la vista salen de este registro.
     *
     * entrada: 'contenido' pasa el archivo leído al parser, 'ruta' pasa el
     * path (PhpSpreadsheet necesita abrir el .xlsx desde disco).
     *
     * color: identidad de cada banco, para teñir su tab, su botón de subida y
     * la cabecera de su modal. Los tomados de la marca vienen anotados; los
     * tres sin identidad publicada se eligieron para que se distingan entre sí
     * y de los dos rojos (Santander y Banorte).
     */
    private const BANCOS = [
        'SANTANDER' => [
            'etiqueta' => 'Santander',
            'color'    => '#EA1D25',   // rojo Santander, oficial desde 2018
            'ext'      => ['txt'],
            'espera'   => 'el TXT de Enlace Santander',
            'parser'   => 'parse_santander_txt',
            'entrada'  => 'contenido',
        ],
        // "BANORTE" y no "BANORTE_CHEQUES": se simplificó el 2026-08-05, hasta
        // ahora es la única cuenta/layout de Banorte que se importa. Junto a
        // Santander porque son los dos bancos que más se suben.
        'BANORTE' => [
            'etiqueta' => 'Banorte',
            'color'    => '#EF2945',   // rojo Banorte
            'ext'      => ['csv'],
            'espera'   => 'el CSV de Cuentas de Cheques de Banorte',
            'parser'   => 'parse_banorte_cheques_csv',
            'entrada'  => 'contenido',
        ],
        'AFIRME' => [
            'etiqueta' => 'Afirme',
            'color'    => '#009D29',   // verde del logo
            'ext'      => ['xls', 'txt', 'tsv', 'csv'],
            'espera'   => 'el export de movimientos de Afirme',
            'parser'   => 'parse_afirme_tsv',
            'entrada'  => 'contenido',
        ],
        'INBURSA' => [
            'etiqueta' => 'Inbursa',
            'color'    => '#10284A',   // azul oscuro del logo
            'ext'      => ['xlsx'],
            'espera'   => 'el Estado de Cuenta Individual de Inbursa',
            'parser'   => 'parse_inbursa_xlsx',
            'entrada'  => 'ruta',
        ],
        'BANKAOOL' => [
            'etiqueta' => 'Bankaool',
            'color'    => '#0F766E',   // sin identidad publicada: teal elegido para distinguirlo
            'ext'      => ['xlsx'],
            'espera'   => 'el export de movimientos de Bankaool',
            'parser'   => 'parse_bankaool_xlsx',
            'entrada'  => 'ruta',
            // Su archivo no trae la cuenta: el modal la pide y se pasa al parser
            'pide_cuenta' => true,
        ],
        'BBVA' => [
            'etiqueta' => 'BBVA',
            'color'    => '#004481',   // Core Blue del manual de identidad
            'ext'      => ['xls'],
            'espera'   => 'el reporte de movimientos de BBVA',
            'parser'   => 'parse_bbva_xml',
            'entrada'  => 'ruta',
        ],
        'VANTAGE' => [
            'etiqueta' => 'Vantage',
            'color'    => '#7E22CE',   // su paleta nueva es morados y rojos
            'ext'      => ['xls'],
            'espera'   => 'el AccountHistory de Vantage Bank',
            'parser'   => 'parse_vantage_xls',
            'entrada'  => 'ruta',
        ],
        'BANREGIO' => [
            'etiqueta' => 'Banregio',
            // naranja de su rebranding (hex exacto no publicado); tono oscuro
            // para que el texto se lea sobre blanco: el naranja vivo daba 2.8:1
            'color'    => '#EA580C',
            'ext'      => ['csv'],
            'espera'   => 'el reporte de movimientos de Banregio',
            'parser'   => 'parse_banregio_csv',
            'entrada'  => 'contenido',
        ],
        'MIFEL' => [
            'etiqueta' => 'Mifel',
            'color'    => '#124679',   // azul del logo
            'ext'      => ['csv'],
            'espera'   => 'el reporte de movimientos de Mifel Empresas',
            'parser'   => 'parse_mifel_csv',
            'entrada'  => 'contenido',
        ],
    ];

    /**
     * Normaliza el banco de un movimiento a una de las llaves de BANCOS.
     * Lo que no reconozca cae en SANTANDER, que es el banco histórico y el
     * único que existía cuando se creó la tabla.
     */
    private static function banco_de($valor): string
    {
        $b = strtoupper(trim((string)$valor));
        return isset(self::BANCOS[$b]) ? $b : 'SANTANDER';
    }

    /** Acumuladores en cero para cada banco soportado. */
    private static function por_banco_vacio(array $campos): array
    {
        $out = [];
        foreach (array_keys(self::BANCOS) as $b) $out[$b] = $campos;
        return $out;
    }

    private $twig;
    private $route;
    private $movsModel;
    private $cuentasModel;
    private $proveedores;

    public function __construct($twig)
    {
        $this->twig         = $twig;
        $this->route        = 'views/tesoreria/';
        $this->movsModel    = new MovimientosBancariosModel();
        $this->cuentasModel = new CuentasBancariasModel();
        $this->proveedores  = new ProveedoresModel();
    }

    /**
     * Vista principal: filtros + tablas vacías + botón de upload.
     *
     * NO consulta movimientos. Los datos los pide el JS a movimientos_table()
     * cuando el usuario presiona "Buscar", mismo patrón que /income/clients:
     * entrar al módulo no debe disparar una consulta de miles de filas que
     * nadie pidió, y buscar de nuevo no debe recargar la página (se perdían el
     * panel de saldos abierto, el tab activo y el scroll).
     */
    public function movimientos_bancos(): void
    {
        if (!authorized(self::PERM_MOVIMIENTOS)) {
            (new Errors())->get404();
            return;
        }

        echo $this->twig->render($this->route . 'movimientos_bancos.html', [
            'filtros'          => $this->filtros_movimientos($_GET),
            'cuentas'          => $this->movsModel->get_cuentas(),
            'bancos'           => self::BANCOS,
            'cuentasSugeridas' => $this->cuentas_sugeridas(),
        ]);
    }

    /**
     * Cuentas del catálogo que se ofrecen al subir un archivo de un banco que
     * no manda la cuenta (hoy solo Bankaool). Se sugieren las de ese banco;
     * el campo igual acepta captura libre por si aún no está dada de alta.
     *
     * @return array<string, array> banco => [['cuenta' => ..., 'descripcion' => ...]]
     */
    private function cuentas_sugeridas(): array
    {
        $pide = array_keys(array_filter(self::BANCOS, fn($c) => !empty($c['pide_cuenta'])));
        if (!$pide) return [];

        $out = array_fill_keys($pide, []);
        foreach ($this->cuentasModel->get_cuentas_admin() as $c) {
            $banco = strtoupper((string)$c['Banco']);
            foreach ($pide as $b) {
                if (strpos($banco, $b) !== false) {
                    $out[$b][] = [
                        'cuenta'      => trim((string)$c['CuentaLocal']),
                        'descripcion' => trim((string)$c['Descripcion']),
                    ];
                }
            }
        }
        foreach ($out as &$lista) {
            usort($lista, fn($a, $b) => strcmp($a['descripcion'], $b['descripcion']));
        }
        return $out;
    }

    /**
     * POST AJAX: movimientos del rango, ya partidos por banco.
     *
     * Solo devuelve las filas de las tablas: los resúmenes de la cabecera son
     * de SALDOS, no de flujo del rango, y los sirve saldos_finales().
     */
    public function movimientos_table(): void
    {
        header('Content-Type: application/json');
        if (!authorized(self::PERM_MOVIMIENTOS)) {
            json_output(['success' => false, 'message' => 'Sin permiso']);
            return;
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            json_output(['success' => false, 'message' => 'Método no permitido']);
            return;
        }

        $filtros = $this->filtros_movimientos($_POST);

        try {
            $movimientos = $this->movsModel->get_movimientos($filtros);
        } catch (Exception $e) {
            error_log('movimientos_table: ' . $e->getMessage());
            json_output(['success' => false, 'message' => 'Error al consultar los movimientos']);
            return;
        }

        // Una tabla independiente por banco: el export a Excel de cada una
        // alimenta el Drive de tesorería, por eso no se mezclan.
        $porTabla = self::por_banco_vacio([]);
        foreach ($movimientos as $m) {
            $porTabla[self::banco_de($m['banco'])][] = $m;
        }

        json_output([
            'success'     => true,
            'filtros'     => $filtros,
            'movimientos' => $porTabla,
        ]);
    }

    /**
     * Normaliza y valida los filtros de movimientos, vengan de $_GET (la vista)
     * o de $_POST (el ajax de la tabla), para que ambos apliquen las mismas
     * reglas: fechas con formato válido, rango por defecto de 7 días y tipo
     * restringido a cargo/abono.
     */
    private function filtros_movimientos(array $src): array
    {
        $fmt   = '/^\d{4}-\d{2}-\d{2}$/';
        $desde = $src['desde'] ?? '';
        $hasta = $src['hasta'] ?? '';
        if (!preg_match($fmt, $desde)) $desde = date('Y-m-d', strtotime('-7 days'));
        if (!preg_match($fmt, $hasta)) $hasta = date('Y-m-d');
        if ($desde > $hasta) $desde = $hasta;

        return [
            'desde'  => $desde,
            'hasta'  => $hasta,
            'cuenta' => trim($src['cuenta'] ?? ''),
            'tipo'   => in_array($src['tipo'] ?? '', ['cargo', 'abono']) ? $src['tipo'] : '',
            'q'      => trim($src['q'] ?? ''),
        ];
    }

    /**
     * GET AJAX: último saldo de cada cuenta al corte de ?hasta, agrupado por
     * empresa (Descripcion del catálogo de cuentas), para el panel colapsable
     * de saldos finales. Tesorería razona por empresa, no por cuenta suelta.
     *
     * Los subtotales se separan por moneda: 5 de las cuentas son en dólares y
     * sumarlas con las de pesos da un número que no existe. Se agrupa aquí y
     * no en la vista para que el JS solo pinte.
     *
     * Spec: docs/superpowers/specs/2026-07-28-tesoreria-saldos-por-empresa-design.md
     */
    public function saldos_finales()
    {
        if (!authorized(self::PERM_SALDO_GRUPO)) {
            json_output(['success' => false, 'message' => 'Sin permiso']);
            return;
        }
        $hasta = $_GET['hasta'] ?? '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) $hasta = date('Y-m-d');

        $saldos = $this->movsModel->get_saldos_finales($hasta);
        $total  = 0.0;
        foreach ($saldos as $s) $total += (float)$s['saldo'];

        $grupos = $this->agrupar_saldos_por_empresa($saldos);

        json_output([
            'success'  => true,
            'hasta'    => $hasta,
            'grupos'   => $grupos,
            'porGrupo' => $this->agrupar_por_grupo_empresarial($grupos),
            'porBanco' => $this->resumen_por_banco($saldos),
            'totales'  => $this->totalizar_por_moneda($saldos),
            // contrato plano anterior, por si otro consumidor lo usa
            'saldos'   => $saldos,
            'total'    => $total,
        ]);
    }

    /**
     * Agrupa los saldos finales por empresa (Descripcion del catálogo).
     * Las cuentas sin match caen en SIN CATÁLOGO, que siempre va al final:
     * son cuentas por dar de alta y conviene que se vean.
     * Los grupos se ordenan por subtotal en pesos descendente.
     */
    private function agrupar_saldos_por_empresa(array $saldos): array
    {
        $grupos = [];
        foreach ($saldos as $s) {
            $desc = trim((string)($s['descripcion'] ?? ''));
            $key  = $desc !== '' ? $desc : self::GRUPO_SIN_CATALOGO;

            if (!isset($grupos[$key])) {
                $grupos[$key] = [
                    'descripcion'  => $key,
                    'sin_catalogo' => $desc === '',
                    'totales'      => [],
                    'porBanco'     => [],
                    'cuentas'      => [],
                ];
            }

            $banco  = self::banco_de($s['banco']);
            $moneda = self::moneda($s['divisa'] ?? null);
            $saldo  = (float)$s['saldo'];

            if (!isset($grupos[$key]['porBanco'][$banco])) {
                $grupos[$key]['porBanco'][$banco] = [
                    'banco'    => $banco,
                    'etiqueta' => self::BANCOS[$banco]['etiqueta'],
                    'totales'  => [],
                ];
            }
            $grupos[$key]['porBanco'][$banco]['totales'][$moneda]['n']
                = ($grupos[$key]['porBanco'][$banco]['totales'][$moneda]['n'] ?? 0) + 1;
            $grupos[$key]['porBanco'][$banco]['totales'][$moneda]['saldo']
                = ($grupos[$key]['porBanco'][$banco]['totales'][$moneda]['saldo'] ?? 0.0) + $saldo;

            $grupos[$key]['cuentas'][] = [
                'cuenta' => trim((string)$s['cuenta']),
                'banco'  => $s['banco'],
                'fecha'  => substr((string)$s['fecha'], 0, 10),
                'saldo'  => $saldo,
                'moneda' => $moneda,
                'tipo'   => $s['tipo'] ?? null,
            ];
            $grupos[$key]['totales'][$moneda]['n']     = ($grupos[$key]['totales'][$moneda]['n'] ?? 0) + 1;
            $grupos[$key]['totales'][$moneda]['saldo'] = ($grupos[$key]['totales'][$moneda]['saldo'] ?? 0.0) + $saldo;
        }

        // Mismo criterio que las tarjetas de banco: el mayor saldo del banco,
        // sea en la moneda que sea, para que un banco solo en dólares no quede
        // hasta abajo por no tener pesos.
        $peso = fn($b) => max(array_column($b['totales'], 'saldo') ?: [0]);

        foreach ($grupos as &$g) {
            // Por banco (etiqueta) y, dentro del mismo banco, por saldo desc.
            usort($g['cuentas'], function ($a, $b) {
                $etiquetaA = self::BANCOS[self::banco_de($a['banco'])]['etiqueta'];
                $etiquetaB = self::BANCOS[self::banco_de($b['banco'])]['etiqueta'];
                return $etiquetaA <=> $etiquetaB ?: $b['saldo'] <=> $a['saldo'];
            });
            uasort($g['porBanco'], fn($a, $b) => $peso($b) <=> $peso($a));
            $g['porBanco'] = array_values($g['porBanco']);
        }
        unset($g);

        // SIN CATÁLOGO al final sin importar su saldo; el resto por MXN desc
        uasort($grupos, function ($a, $b) {
            if ($a['sin_catalogo'] !== $b['sin_catalogo']) return $a['sin_catalogo'] ? 1 : -1;
            return ($b['totales']['MXN']['saldo'] ?? 0) <=> ($a['totales']['MXN']['saldo'] ?? 0);
        });

        return array_values($grupos);
    }

    /**
     * GET: descarga el saldo por grupo en .xlsx, una fila por empresa con sus
     * subtotales de grupo. Sirve para pegarlo en el Drive de tesorería.
     */
    public function exportar_saldos_grupo(): void
    {
        if (!authorized(self::PERM_SALDO_GRUPO)) {
            (new Errors())->get404();
            return;
        }
        $hasta = $_GET['hasta'] ?? '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) $hasta = date('Y-m-d');

        $libro  = $this->libro_saldos_grupo($hasta);
        $nombre = 'saldo_por_grupo_' . $hasta . '.xlsx';

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $nombre . '"');
        header('Cache-Control: max-age=0');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($libro))->save('php://output');
        exit;
    }

    /**
     * Arma el libro del saldo por grupo. Separado del endpoint para poder
     * generarlo y revisarlo sin la descarga (headers + exit) de por medio.
     */
    private function libro_saldos_grupo(string $hasta): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        $porGrupo = $this->agrupar_por_grupo_empresarial(
            $this->agrupar_saldos_por_empresa($this->movsModel->get_saldos_finales($hasta))
        );

        $libro = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $hoja  = $libro->getActiveSheet();
        $hoja->setTitle('Saldo por grupo');

        $hoja->fromArray(['Saldo final por grupo al ' . date('d/m/Y', strtotime($hasta))], null, 'A1');
        $hoja->mergeCells('A1:F1');
        $hoja->getStyle('A1')->getFont()->setBold(true)->setSize(12);

        $hoja->fromArray(['Grupo', 'Empresa / Cuenta', 'Banco', 'Cuentas', 'MXN', 'USD'], null, 'A3');
        $hoja->getStyle('A3:F3')->getFont()->setBold(true);
        $hoja->getStyle('A3:F3')->getFill()
             ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
             ->getStartColor()->setRGB('E2E8F0');

        $fila  = 4;
        $tMxn  = 0.0; $tUsd = 0.0; $tCtas = 0;

        foreach ($porGrupo as $g) {
            foreach ($g['empresas'] as $e) {
                $n = ($e['totales']['MXN']['n'] ?? 0) + ($e['totales']['USD']['n'] ?? 0);
                $hoja->fromArray([
                    $g['grupo'],
                    $e['descripcion'],
                    '',
                    $n,
                    $e['totales']['MXN']['saldo'] ?? null,
                    $e['totales']['USD']['saldo'] ?? null,
                ], null, 'A' . $fila);
                $fila++;

                // Detalle de cuentas: colapsado por default (mismo patrón que
                // el card, que arranca cerrado y se expande por empresa).
                foreach ($e['cuentas'] as $c) {
                    $hoja->fromArray([
                        '',
                        $c['cuenta'],
                        $c['banco'],
                        '',
                        $c['moneda'] === 'MXN' ? $c['saldo'] : null,
                        $c['moneda'] === 'USD' ? $c['saldo'] : null,
                    ], null, 'A' . $fila);
                    $hoja->getStyle("B$fila")->getFont()->getColor()->setRGB('64748B');
                    $hoja->getRowDimension($fila)->setOutlineLevel(1)->setVisible(false);
                    $fila++;
                }
            }

            $nG = ($g['totales']['MXN']['n'] ?? 0) + ($g['totales']['USD']['n'] ?? 0);
            $hoja->fromArray([
                '', 'Total ' . $g['grupo'], '', $nG,
                $g['totales']['MXN']['saldo'] ?? null,
                $g['totales']['USD']['saldo'] ?? null,
            ], null, 'A' . $fila);
            $hoja->getStyle("A$fila:F$fila")->getFont()->setBold(true);
            $hoja->getStyle("A$fila:F$fila")->getFill()
                 ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                 ->getStartColor()->setRGB('F1F5F9');
            $fila += 2;   // renglón en blanco entre grupos

            $tMxn  += $g['totales']['MXN']['saldo'] ?? 0;
            $tUsd  += $g['totales']['USD']['saldo'] ?? 0;
            $tCtas += $nG;
        }

        $hoja->fromArray(['', 'TOTAL GENERAL', '', $tCtas, $tMxn, $tUsd], null, 'A' . $fila);
        $hoja->getStyle("A$fila:F$fila")->getFont()->setBold(true)->setSize(11);
        $hoja->getStyle("A$fila:F$fila")->getBorders()->getTop()
             ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_DOUBLE);

        $hoja->getStyle('E4:F' . $fila)->getNumberFormat()->setFormatCode('#,##0.00');
        $hoja->getStyle('D4:D' . $fila)->getAlignment()->setHorizontal('center');
        foreach (range('A', 'F') as $col) $hoja->getColumnDimension($col)->setAutoSize(true);
        $hoja->freezePane('A4');

        // Outline por filas: los "[-]" de agrupado quedan a la izquierda y
        // el resumen (fila "Total ...") va arriba del detalle que resume,
        // igual que el card en la vista (la empresa antes que sus cuentas).
        $hoja->setShowSummaryBelow(false);

        return $libro;
    }

    /**
     * Mete las empresas ya agrupadas en su grupo empresarial, conservando el
     * detalle de cuentas de cada una. Alimenta el panel "Saldo final por
     * grupo" y su export a Excel.
     *
     * Los grupos salen en el orden de GRUPOS_EMPRESA y OTRAS siempre al final;
     * dentro de cada uno las empresas van por saldo MXN descendente, que es el
     * orden con el que ya llegan.
     */
    private function agrupar_por_grupo_empresarial(array $porEmpresa): array
    {
        $out = [];
        foreach (array_keys(self::GRUPOS_EMPRESA) as $g) {
            $out[$g] = ['grupo' => $g, 'totales' => [], 'empresas' => []];
        }
        $out['OTRAS'] = ['grupo' => 'OTRAS', 'totales' => [], 'empresas' => []];

        foreach ($porEmpresa as $e) {
            $g = self::grupo_de($e['descripcion']);
            $out[$g]['empresas'][] = $e;
            foreach ($e['totales'] as $moneda => $t) {
                $out[$g]['totales'][$moneda]['n']     = ($out[$g]['totales'][$moneda]['n'] ?? 0) + $t['n'];
                $out[$g]['totales'][$moneda]['saldo'] = ($out[$g]['totales'][$moneda]['saldo'] ?? 0.0) + $t['saldo'];
            }
        }

        // Un grupo sin empresas no se muestra
        return array_values(array_filter($out, fn($g) => !empty($g['empresas'])));
    }

    /**
     * Saldo de cada banco, separado por moneda y con cuántas empresas tiene.
     * Alimenta las tarjetas de "Saldo por banco".
     *
     * Se ordena por el saldo de la moneda dominante del propio banco: Vantage
     * solo tiene cuentas en dólares, y ordenar por MXN lo dejaría al final con
     * un $0.00 que no significa nada.
     */
    private function resumen_por_banco(array $saldos): array
    {
        $out = [];
        foreach ($saldos as $s) {
            $b = self::banco_de($s['banco']);
            $m = self::moneda($s['divisa'] ?? null);
            if (!isset($out[$b])) {
                $out[$b] = [
                    'banco'    => $b,
                    'etiqueta' => self::BANCOS[$b]['etiqueta'],
                    'totales'  => [],
                    'empresas' => [],
                ];
            }
            $out[$b]['totales'][$m]['n']     = ($out[$b]['totales'][$m]['n'] ?? 0) + 1;
            $out[$b]['totales'][$m]['saldo'] = ($out[$b]['totales'][$m]['saldo'] ?? 0.0) + (float)$s['saldo'];
            $out[$b]['empresas'][trim((string)($s['descripcion'] ?? '')) ?: self::GRUPO_SIN_CATALOGO] = true;
        }

        foreach ($out as &$b) {
            $b['empresas'] = count($b['empresas']);
            $b['cuentas']  = array_sum(array_column($b['totales'], 'n'));
        }
        unset($b);

        // el mayor saldo del banco, sea en la moneda que sea
        $peso = fn($b) => max(array_column($b['totales'], 'saldo') ?: [0]);
        uasort($out, fn($a, $b) => $peso($b) <=> $peso($a));

        return array_values($out);
    }

    /** Totales generales separados por moneda (no se suman divisas distintas). */
    private function totalizar_por_moneda(array $saldos): array
    {
        $totales = [];
        foreach ($saldos as $s) {
            $m = self::moneda($s['divisa'] ?? null);
            $totales[$m]['n']     = ($totales[$m]['n'] ?? 0) + 1;
            $totales[$m]['saldo'] = ($totales[$m]['saldo'] ?? 0.0) + (float)$s['saldo'];
        }
        return $totales;
    }

    /**
     * Moneda de una cuenta a partir de la Divisa del catálogo. Solo
     * "DOLAR AMERICANO" es USD; el resto (incluidos NULL y el 'peso' suelto
     * que hay en un registro del catálogo) se trata como MXN.
     */
    private static function moneda($divisa): string
    {
        return stripos((string)$divisa, 'DOLAR') !== false ? 'USD' : 'MXN';
    }

    /**
     * POST AJAX: importa el archivo de movimientos de cualquier banco.
     * Recibe $_POST['banco'] y $_FILES['archivo']; la validación es común y
     * lo único específico del banco sale del registro self::BANCOS.
     *
     * Antes había un método por banco con la misma validación copiada; agregar
     * Inbursa habría sido la tercera copia.
     */
    public function upload_movimientos(): void
    {
        header('Content-Type: application/json');
        if (!authorized(self::PERM_MOVIMIENTOS) || !authorized(self::PERM_SUBIR_MOV)) {
            json_output(['success' => false, 'message' => 'No tienes permiso para importar movimientos']);
            return;
        }

        $banco = strtoupper(trim($_POST['banco'] ?? ''));
        $cfg   = self::BANCOS[$banco] ?? null;
        if ($cfg === null) {
            json_output(['success' => false, 'message' => 'Banco no reconocido']);
            return;
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['archivo']['tmp_name'])) {
            json_output(['success' => false, 'message' => 'No se recibió ningún archivo']);
            return;
        }

        $file = $_FILES['archivo'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            json_output(['success' => false, 'message' => 'Error al subir el archivo (código ' . $file['error'] . ')']);
            return;
        }
        if ($file['size'] > self::MAX_TXT_BYTES) {
            json_output(['success' => false, 'message' => 'El archivo excede el tamaño máximo (10 MB)']);
            return;
        }
        if ($file['size'] === 0) {
            json_output(['success' => false, 'message' => 'El archivo está vacío']);
            return;
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $cfg['ext'])) {
            json_output(['success' => false, 'message' =>
                'El archivo debe ser ' . $cfg['espera'] . ' (.' . implode('/.', $cfg['ext']) . ')']);
            return;
        }

        // Los parsers de texto reciben el contenido; el de Inbursa necesita la
        // ruta porque PhpSpreadsheet abre el .xlsx desde disco.
        if ($cfg['entrada'] === 'ruta') {
            $entrada = $file['tmp_name'];
        } else {
            $entrada = file_get_contents($file['tmp_name']);
            if ($entrada === false || trim($entrada) === '') {
                json_output(['success' => false, 'message' => 'El archivo está vacío o no se pudo leer']);
                return;
            }
        }

        // Bankaool no trae la cuenta en el archivo: viene del formulario
        $args = [$entrada];
        if (!empty($cfg['pide_cuenta'])) {
            $cuenta = trim($_POST['cuenta'] ?? '');
            if ($cuenta === '') {
                json_output(['success' => false, 'message' =>
                    'El archivo de ' . $cfg['etiqueta'] . ' no incluye la cuenta: selecciónala antes de subirlo']);
                return;
            }
            $args[] = $cuenta;
        }

        $parseo = MovimientosBancariosModel::{$cfg['parser']}(...$args);
        if (empty($parseo['movimientos'])) {
            json_output([
                'success' => false,
                'message' => 'El archivo no contiene movimientos con el formato de ' . $banco,
                'errores' => array_slice($parseo['errores'], 0, 20),
            ]);
            return;
        }

        try {
            $res = $this->movsModel->insert_bulk(
                $parseo['movimientos'],
                mb_substr($file['name'], 0, 120),
                (int)($_SESSION['tg_user']['Id'] ?? 0) ?: null
            );
        } catch (Exception $e) {
            error_log("upload_movimientos($banco): " . $e->getMessage());
            json_output(['success' => false, 'message' => 'Error al guardar en base de datos, no se importó nada']);
            return;
        }

        $avisos = $this->avisos_catalogo($parseo['movimientos']);

        // Vantage marca los movimientos aún no confirmados por el banco. Se
        // importan igual, pero se avisa: si el banco los confirma con otro
        // importe, entran de nuevo como un movimiento distinto.
        $pendientes = (int)($parseo['info']['pendientes'] ?? 0);
        if ($pendientes > 0) {
            $avisos[] = "$pendientes movimiento" . ($pendientes === 1 ? '' : 's')
                      . ' sin confirmar por el banco: si cambia su importe al confirmarse, se importará de nuevo';
        }

        $fechas = array_column($parseo['movimientos'], 'fecha');
        json_output([
            'success'    => true,
            'banco'      => $banco,
            'insertados' => $res['insertados'],
            'duplicados' => $res['duplicados'],
            'errores'    => array_slice($parseo['errores'], 0, 20),
            'avisos'     => $avisos,
            'info'       => $parseo['info'] ?? null,
            'fecha_min'  => min($fechas),
            'fecha_max'  => max($fechas),
        ]);
    }

    /**
     * Aviso (no bloqueante) por cada cuenta del archivo que no esté dada de
     * alta en el catálogo: sin ella el saldo cae en el grupo "SIN CATÁLOGO".
     */
    private function avisos_catalogo(array $movimientos): array
    {
        $avisos   = [];
        $catalogo = array_column($this->cuentasModel->get_cuentas_admin(), 'CuentaLocal');
        foreach (array_unique(array_column($movimientos, 'cuenta')) as $cta) {
            if (!in_array($cta, $catalogo)) {
                $avisos[] = "La cuenta $cta no está registrada en el catálogo de cuentas bancarias";
            }
        }
        return $avisos;
    }

    /* ===================================================================== */
    /* Catálogo de cuentas bancarias (migrado de payment.php el 2026-07-23)  */
    /* ===================================================================== */

    /** Vista de administración del catálogo de cuentas bancarias. */
    public function bank_accounts()
    {
        if (!authorized(self::PERM_CUENTAS)) {
            (new Errors())->get404();
            return;
        }
        $proveedores = $this->proveedores->get_actives() ?: [];
        echo $this->twig->render($this->route . 'bank_accounts.html', compact('proveedores'));
    }

    /** JSON con todas las cuentas bancarias para el DataTable de administración. */
    public function bank_accounts_table()
    {
        header('Content-Type: application/json');
        if (!authorized(self::PERM_CUENTAS)) {
            json_output(['data' => [], 'error' => 'Sin permiso']);
            return;
        }
        $cuentas = $this->cuentasModel->get_cuentas_admin();
        json_output(['data' => $cuentas]);
    }

    /** Alta de cuenta bancaria desde el modal (id vacío en el form). */
    public function create_bank_account()
    {
        header('Content-Type: application/json');
        if (!authorized(self::PERM_CUENTAS)) {
            json_output(['success' => false, 'message' => 'Sin permiso']);
            return;
        }

        $cuenta_local = trim($_POST['CuentaLocal'] ?? '');
        if ($cuenta_local === '') {
            json_output(['success' => false, 'message' => 'La cuenta (CLABE/Cuenta) es obligatoria']);
            return;
        }

        $data = [
            'CuentaLocal'   => $cuenta_local,
            'Descripcion'   => trim($_POST['Descripcion'] ?? ''),
            'Banco'         => trim($_POST['Banco'] ?? ''),
            'TitularCuenta' => trim($_POST['TitularCuenta'] ?? ''),
            'Tipo'          => trim($_POST['Tipo'] ?? ''),
            'Divisa'        => trim($_POST['Divisa'] ?? ''),
            'emp_cod'       => trim($_POST['emp_cod'] ?? ''),
            'proveedor_cod' => trim($_POST['proveedor_cod'] ?? ''),
            'Activo'        => intval($_POST['Activo'] ?? 1),
        ];

        $user_id = $_SESSION['tg_user']['Id'] ?? 0;
        $res = $this->cuentasModel->create_admin($data, $user_id);

        if ($res === 2) {
            json_output(['success' => false, 'message' => 'Ya existe una cuenta con esa CLABE/Cuenta']);
            return;
        }
        json_output([
            'success' => $res === 1,
            'message' => $res === 1 ? 'Cuenta creada correctamente' : 'No se pudo crear la cuenta',
        ]);
    }

    /** Actualiza una cuenta bancaria desde el modal de edición. */
    public function update_bank_account()
    {
        header('Content-Type: application/json');
        if (!authorized(self::PERM_CUENTAS)) {
            json_output(['success' => false, 'message' => 'Sin permiso']);
            return;
        }

        $id = intval($_POST['id'] ?? 0);
        if (!$id) {
            json_output(['success' => false, 'message' => 'ID inválido']);
            return;
        }

        $cuenta = $this->cuentasModel->get_by_id($id);
        if (!$cuenta) {
            json_output(['success' => false, 'message' => 'La cuenta no existe']);
            return;
        }

        $cuenta_local = trim($_POST['CuentaLocal'] ?? '');
        if ($cuenta_local === '') {
            json_output(['success' => false, 'message' => 'La cuenta (CLABE/Cuenta) es obligatoria']);
            return;
        }

        $data = [
            'CuentaLocal'   => $cuenta_local,
            'Descripcion'   => trim($_POST['Descripcion'] ?? ''),
            'Banco'         => trim($_POST['Banco'] ?? ''),
            'TitularCuenta' => trim($_POST['TitularCuenta'] ?? ''),
            'Tipo'          => trim($_POST['Tipo'] ?? ''),
            'Divisa'        => trim($_POST['Divisa'] ?? ''),
            'emp_cod'       => trim($_POST['emp_cod'] ?? ''),
            'proveedor_cod' => trim($_POST['proveedor_cod'] ?? ''),
            'Activo'        => intval($_POST['Activo'] ?? 1),
        ];

        $user_id = $_SESSION['tg_user']['Id'] ?? 0;
        $ok = $this->cuentasModel->update_admin($id, $data, $user_id);

        json_output([
            'success' => (bool)$ok,
            'message' => $ok ? 'Cuenta actualizada correctamente' : 'No se pudo actualizar la cuenta',
        ]);
    }

    /** Activa o desactiva una cuenta bancaria. */
    public function toggle_bank_account()
    {
        header('Content-Type: application/json');
        if (!authorized(self::PERM_CUENTAS)) {
            json_output(['success' => false, 'message' => 'Sin permiso']);
            return;
        }

        $id     = intval($_POST['id'] ?? 0);
        $activo = intval($_POST['activo'] ?? 0) === 1 ? 1 : 0;
        if (!$id) {
            json_output(['success' => false, 'message' => 'ID inválido']);
            return;
        }

        $user_id = $_SESSION['tg_user']['Id'] ?? 0;
        $ok = $this->cuentasModel->set_activo($id, $activo, $user_id);

        json_output([
            'success' => (bool)$ok,
            'activo'  => $activo,
            'message' => $ok
                ? ($activo ? 'Cuenta activada' : 'Cuenta desactivada')
                : 'No se pudo cambiar el estado',
        ]);
    }
}
