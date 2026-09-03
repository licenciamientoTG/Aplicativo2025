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
    // Perfil acotado: ve movimientos_bancos() pero solo de CUENTAS_PERFIL_CREDITO.
    // No da acceso a saldos por grupo, catálogo de cuentas ni subir movimientos.
    private const PERM_MOVIMIENTOS_CREDITO  = 87;   // Ver Movimientos Bancos - Crédito
    private const PERM_MOV_CHEQUES          = 88;   // Ver Movimientos Bancos Cheques (tarjetas de crédito)
    // Mismo patrón que PERM_MOVIMIENTOS_CREDITO pero con CUENTAS_PERFIL_INGRESOS.
    private const PERM_MOVIMIENTOS_INGRESOS = 89;   // Ver Movimientos Bancos - Ingresos
    // Mismo patrón, con CUENTAS_PERFIL_ALIANZA: las 22 cuentas del grupo
    // Alianza Comercial (Gasomex + Clara + Jarudo) del panel de saldos.
    private const PERM_MOVIMIENTOS_ALIANZA  = 92;   // Ver Movimientos Bancos - Alianza Comercial
    // Columna Comentarios de la tabla: oculta por default, solo visible y
    // editable (icono de lápiz, mismo patrón que arqueo/concentrado) para
    // quien tenga este permiso. No amplía qué cuentas se ven, solo si se
    // puede comentar las que ya se ven.
    private const PERM_COMENTARIOS  = 93;   // Editar Comentarios Movimientos Bancos
    private const MAX_TXT_BYTES     = 10 * 1024 * 1024;

    /**
     * Cuentas visibles para el perfil "Crédito" (permiso 83), por
     * CuentaLocal exacta del catálogo (TG.dbo.CatalogosCuentasBancarias).
     * Confirmadas 2026-08-13 contra el catálogo: las cuentas "principales"
     * en Santander de las 4 empresas de Alianza Comercial más la cuenta de
     * Banorte de Héctor Armandino Fierro Holguín.
     * Las 3 de Banorte/Diaz Gas se agregaron el 2026-08-19, confirmadas
     * contra el catálogo (mismo titular, activas).
     */
    private const CUENTAS_PERFIL_CREDITO = [
        '1209082994',    // Banorte, Héctor Armandino Fierro Holguín
        '65500563281',   // Santander, Gasolinera Villa Ahumada
        '65504998214',   // Santander, Distribuidora Gasomex
        '65505528588',   // Santander, Distribuidora Clara
        '65505339719',   // Santander, Servicio El Jarudo
        '0601500956',    // Banorte, Diaz Gas SA de CV
        '0601500947',    // Banorte, Diaz Gas SA de CV
        '0186932791',    // Banorte, Diaz Gas SA de CV
    ];

    /**
     * Cuentas visibles para el perfil "Ingresos" (permiso 89), por
     * CuentaLocal exacta del catálogo (TG.dbo.CatalogosCuentasBancarias).
     * Confirmadas 2026-08-14 contra el catálogo.
     */
    private const CUENTAS_PERFIL_INGRESOS = [
        '60630878973',          // Santander, Héctor Armandino Fierro Holguín
        '00000369',             // Bankaool, Diaz Gas SA de CV
        '0601500741',           // Banorte, Diaz Gas SA de CV (MXN)
        '072164006015007416',   // Banorte, DG 0741 DLLS (USD)
        '0185322470',           // Banorte, Diaz Gas SA de CV
        '65510224098',   // Santander, TSAcuenta nuev
        '0102925486' //bbva, custodia
    ];

    /**
     * Cuentas visibles para el perfil "Alianza Comercial" (permiso 92), por
     * CuentaLocal exacta del catálogo (TG.dbo.CatalogosCuentasBancarias).
     * Confirmadas 2026-09-02 contra movimientos_bancarios/get_saldos_finales:
     * son las 22 cuentas con saldo del grupo ALIANZA COMERCIAL del panel de
     * saldos por grupo — Distribuidora Gasomex (10), Servicio El Jarudo (6)
     * y Distribuidora Clara (6).
     */
    private const CUENTAS_PERFIL_ALIANZA = [
        // Distribuidora Gasomex SA de CV (10)
        '036420500416619210',   // Inbursa
        '177129372',            // Afirme
        '177129399',            // Afirme
        '65504998214',          // Santander, principal
        '65507674547',          // Santander
        '65507674638',          // Santander
        '65507674669',          // Santander
        '65507674777',          // Santander
        '65508985231',          // Santander
        '82500973678',          // Santander, USD
        // Servicio El Jarudo SA de CV (6)
        '036420500416642355',   // Inbursa
        '177122211',            // Afirme
        '65505339719',          // Santander
        '65507678504',          // Santander
        '65508985413',          // Santander
        '82500974409',          // Santander, USD
        // Distribuidora Clara SA de CV (6)
        '036420500416675461',   // Inbursa
        '177126713',            // Afirme
        '65505528588',          // Santander
        '65507678492',          // Santander
        '65508985029',          // Santander
        '82500974412',          // Santander, USD
    ];

    /** Grupo de saldos para cuentas que no están en CatalogosCuentasBancarias. */
    private const GRUPO_SIN_CATALOGO = 'SIN CATÁLOGO';

    /**
     * Corte efectivo para el saldo final por grupo/cuenta: el día de hoy
     * nunca está cerrado (el banco puede seguir mandando movimientos), así
     * que si se pide el corte de hoy se usa el de ayer. Un corte a una fecha
     * pasada no cambia. Mismo criterio replicado en
     * MovimientosBancariosModel::get_saldos_finales().
     */
    private static function corte_efectivo(string $hasta): string
    {
        return $hasta === date('Y-m-d') ? date('Y-m-d', strtotime($hasta . ' -1 day')) : $hasta;
    }

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
        // Tres formatos, mismo botón: el TXT diario de Enlace (el que se
        // sube todos los días) y dos pantallas del portal para bajar un
        // periodo ya cerrado (p.ej. al detectar movimientos faltantes) —
        // "Consulta de movimientos > Chequeras" y "Movimientos SPEI
        // detallados", mismos movimientos en layouts de CSV distintos.
        // 'parser' queda sin valor porque cuál de los tres es se decide por
        // el contenido del archivo, no por su extensión — ver
        // SANTANDER_PARSERS y upload_movimientos().
        'SANTANDER' => [
            'etiqueta' => 'Santander',
            'color'    => '#EA1D25',   // rojo Santander, oficial desde 2018
            'ext'      => ['txt', 'csv'],
            'espera'   => 'el TXT de Enlace Santander o alguno de los CSV de Consulta de movimientos',
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
        // Segundo layout de Banorte: el TXT de interconexión, que trae las 19
        // cuentas en un archivo en vez de una por una. Son los MISMOS
        // movimientos que el CSV, así que se guardan con banco BANORTE y se ven
        // en su tab; esta entrada solo existe para tener su propio botón de
        // subida, de ahí solo_subida.
        'BANORTE_TXT' => [
            'etiqueta'    => 'Banorte 2',
            'color'       => '#EF2945',
            'ext'         => ['txt'],
            'espera'      => 'el TXT del Estado de Cuenta Electrónico Especial Diario de Banorte',
            'parser'      => 'parse_banorte_edc_txt',
            'entrada'     => 'contenido',
            'solo_subida' => true,
        ],
        'AFIRME' => [
            'etiqueta' => 'Afirme',
            'color'    => '#009D29',   // verde del logo
            // El portal sólo acepta el export CSV actual. Los formatos
            // antiguos (.xls/.txt/.tsv) usan referencias distintas y pueden
            // duplicar movimientos ya cargados desde el CSV.
            'ext'      => ['csv'],
            'espera'   => 'el archivo CSV de movimientos de Afirme',
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
     * Bancos soportados en "Movimientos bancos cheques" (estados de cuenta
     * de tarjeta de crédito en PDF). Mismo registro que BANCOS pero
     * independiente: es un import y una tabla distintos (sin saldo corrido).
     */
    private const BANCOS_CHEQUES = [
        'BANORTE' => [
            'etiqueta' => 'Banorte',
            'color'    => '#EF2945',
            'ext'      => ['pdf'],
            'espera'   => 'el PDF del estado de cuenta Empuje Negocio de Banorte',
            'parser'   => 'parse_banorte_credito_pdf',
        ],
        // Un solo botón de subida para Amex, sin pedirle al usuario qué
        // tarjeta o formato es: acepta el PDF del estado de cuenta (Platinum
        // o Gold Corporate, hay más de un layout) y el Excel de "actividad"
        // (mismo formato de columnas para cualquier tarjeta Amex). El
        // controlador decide el parser por extensión y, en PDF, prueba los
        // layouts conocidos hasta que uno reconoce el archivo — ver
        // upload_movimientos_cheques().
        'AMEX' => [
            'etiqueta' => 'American Express',
            'color'    => '#006FCF',   // azul oficial de American Express
            'ext'      => ['pdf', 'xlsx'],
            'espera'   => 'el PDF del estado de cuenta o el Excel de actividad de American Express',
        ],
    ];

    /**
     * Parsers de PDF de Amex a probar en orden hasta que uno reconozca el
     * archivo (cada layout de tarjeta trae su propio formato de encabezado).
     */
    private const AMEX_PDF_PARSERS = ['parse_amex_credito_pdf', 'parse_amex_gold_credito_pdf'];

    /**
     * Parsers de Santander a probar en orden hasta que uno reconozca el
     * archivo: el TXT diario primero, por ser el que se sube casi siempre;
     * los otros dos son pantallas del portal para bajar un periodo ya
     * cerrado (mismos movimientos, dos layouts de CSV distintos).
     *
     * parser => etiqueta legible: el modal de resultado (upload_movimientos)
     * usa la etiqueta para decirle al usuario cuál de los tres detectó, ya
     * que la extensión (.txt/.csv) no alcanza para distinguir Chequeras de
     * SPEI detallado (ambos .csv).
     */
    private const SANTANDER_PARSERS = [
        'parse_santander_txt'          => 'TXT diario de Enlace',
        'parse_santander_chequeras_csv' => 'CSV de Consulta de movimientos > Chequeras',
        'parse_santander_spei_csv'      => 'CSV de Movimientos SPEI detallados',
    ];

    /**
     * Normaliza el banco de un movimiento a una de las llaves de BANCOS.
     * Lo que no reconozca cae en SANTANDER, que es el banco histórico y el
     * único que existía cuando se creó la tabla.
     */
    private static function banco_de($valor): string
    {
        $b = strtoupper(trim((string)$valor));
        return isset(self::bancos_con_tabla()[$b]) ? $b : 'SANTANDER';
    }

    /**
     * Bancos que tienen tab y tabla propios, que son todos menos los layouts
     * alternos de un banco ya listado (Banorte 2), cuyos movimientos se guardan
     * y se muestran bajo el banco principal.
     */
    private static function bancos_con_tabla(): array
    {
        return array_filter(self::BANCOS, fn($c) => empty($c['solo_subida']));
    }

    /** Acumuladores en cero para cada banco con tabla. */
    private static function por_banco_vacio(array $campos): array
    {
        $out = [];
        foreach (array_keys(self::bancos_con_tabla()) as $b) $out[$b] = $campos;
        return $out;
    }

    private $twig;
    private $route;
    private $movsModel;
    private $cuentasModel;
    private $proveedores;
    private $tarjetasModel;
    private $tarjetasDocsModel;
    private $departamentosModel;
    private $reglasDeptoModel;

    public function __construct($twig)
    {
        $this->twig         = $twig;
        $this->route        = 'views/tesoreria/';
        $this->movsModel    = new MovimientosBancariosModel();
        $this->cuentasModel = new CuentasBancariasModel();
        $this->proveedores  = new ProveedoresModel();
        $this->tarjetasModel = new TarjetasCreditoModel();
        $this->tarjetasDocsModel = new TarjetasCreditoDocumentosModel();
        $this->departamentosModel = new DepartamentosModel();
        $this->reglasDeptoModel = new TarjetasCreditoReglasDepartamentoModel();
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
        if (!authorized(self::PERM_MOVIMIENTOS) && !authorized(self::PERM_MOVIMIENTOS_CREDITO)
            && !authorized(self::PERM_MOVIMIENTOS_INGRESOS) && !authorized(self::PERM_MOVIMIENTOS_ALIANZA)) {
            (new Errors())->get404();
            return;
        }

        $cuentasPermitidas = $this->cuentas_permitidas();

        echo $this->twig->render($this->route . 'movimientos_bancos.html', [
            'filtros'          => $this->filtros_movimientos($_GET),
            'cuentas'          => $this->movsModel->get_cuentas($cuentasPermitidas),
            // bancos: todos, para los botones de subida (incluye Banorte 2)
            // bancosTabla: los que tienen tab y tabla propios
            'bancos'           => self::BANCOS,
            'bancosTabla'      => self::bancos_con_tabla(),
            'cuentasSugeridas' => $this->cuentas_sugeridas(),
            'puedeComentar'    => authorized(self::PERM_COMENTARIOS),
        ]);
    }

    /**
     * Restricción de cuentas para el usuario actual: null = sin restricción
     * (ve todas), array = solo esas cuentas. El permiso completo (80) siempre
     * gana sobre el acotado (83) si el usuario tuviera ambos.
     */
    private function cuentas_permitidas(): ?array
    {
        if (authorized(self::PERM_MOVIMIENTOS)) return null;
        if (authorized(self::PERM_MOVIMIENTOS_CREDITO)) return self::CUENTAS_PERFIL_CREDITO;
        if (authorized(self::PERM_MOVIMIENTOS_INGRESOS)) return self::CUENTAS_PERFIL_INGRESOS;
        if (authorized(self::PERM_MOVIMIENTOS_ALIANZA)) return self::CUENTAS_PERFIL_ALIANZA;
        return [];
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
        if (!authorized(self::PERM_MOVIMIENTOS) && !authorized(self::PERM_MOVIMIENTOS_CREDITO)
            && !authorized(self::PERM_MOVIMIENTOS_INGRESOS) && !authorized(self::PERM_MOVIMIENTOS_ALIANZA)) {
            json_output(['success' => false, 'message' => 'Sin permiso']);
            return;
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            json_output(['success' => false, 'message' => 'Método no permitido']);
            return;
        }

        $filtros = $this->filtros_movimientos($_POST);

        try {
            $movimientos = $this->movsModel->get_movimientos($filtros, $this->cuentas_permitidas());
        } catch (Exception $e) {
            error_log('movimientos_table: ' . $e->getMessage());
            json_output(['success' => false, 'message' => 'Error al consultar los movimientos']);
            return;
        }

        // Una tabla independiente por banco: el export a Excel de cada una
        // alimenta el Drive de tesorería, por eso no se mezclan.
        $porTabla = self::por_banco_vacio([]);
        foreach ($movimientos as $m) {
            $banco = self::banco_de($m['banco']);
            // Solo Bankaool: su export trae fecha+hora en una sola celda y un
            // único "Monto" con signo (ver docs/sql, parse_bankaool_xlsx). El
            // resto de bancos separa cargo/abono y fecha/hora porque así
            // vienen en sus estados de cuenta y así se concilian.
            if ($banco === 'BANKAOOL') {
                $m['fecha_hora'] = trim($m['fecha'] . ' ' . ($m['hora'] ?? ''));
                $m['movimiento'] = $m['cargo'] !== null ? -(float)$m['cargo'] : (float)($m['abono'] ?? 0);
            }
            $porTabla[$banco][] = $m;
        }

        json_output([
            'success'     => true,
            'filtros'     => $filtros,
            'movimientos' => $porTabla,
        ]);
    }

    /**
     * POST AJAX: guarda el comentario libre de un movimiento (edición inline
     * con lápiz en la tabla). Requiere permiso 93 y que la cuenta del
     * movimiento esté dentro de lo que el perfil del usuario puede ver —
     * misma restricción que movimientos_table(), para que un perfil acotado
     * (Crédito/Ingresos/Alianza) no pueda comentar cuentas fuera de su
     * alcance aunque conozca el id. La cuenta se vuelve a exigir en el
     * UPDATE (ver update_comentario) por si el payload la falsea.
     */
    public function guardar_comentario_movimiento(): void
    {
        header('Content-Type: application/json');
        if (!authorized(self::PERM_COMENTARIOS)) {
            json_output(['success' => false, 'message' => 'Sin permiso para editar comentarios']);
            return;
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            json_output(['success' => false, 'message' => 'Método no permitido']);
            return;
        }

        $id     = (int)($_POST['id'] ?? 0);
        $cuenta = trim($_POST['cuenta'] ?? '');
        $comentario = trim(mb_substr((string)($_POST['comentario'] ?? ''), 0, 300));
        if ($id <= 0 || $cuenta === '') {
            json_output(['success' => false, 'message' => 'Movimiento inválido']);
            return;
        }

        $cuentasPermitidas = $this->cuentas_permitidas();
        if ($cuentasPermitidas !== null && !in_array($cuenta, $cuentasPermitidas, true)) {
            json_output(['success' => false, 'message' => 'Sin permiso para esa cuenta']);
            return;
        }

        try {
            $ok = $this->movsModel->update_comentario($id, $cuenta, $comentario);
        } catch (Exception $e) {
            error_log('guardar_comentario_movimiento: ' . $e->getMessage());
            json_output(['success' => false, 'message' => 'Error al guardar el comentario']);
            return;
        }

        json_output([
            'success'    => $ok,
            'message'    => $ok ? 'Comentario guardado' : 'No se encontró el movimiento',
            'comentario' => $comentario,
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

        // Se calcula aquí (y no solo dentro del modelo) para poder informarlo
        // en la respuesta: la vista lo usa tanto para el encabezado "al ..."
        // como para decidir qué cuenta marcar como atrasada respecto al corte.
        $hastaEfectivo = self::corte_efectivo($hasta);

        $saldos = $this->movsModel->get_saldos_finales($hasta);
        $total  = 0.0;
        foreach ($saldos as $s) $total += (float)$s['saldo'];

        $grupos = $this->agrupar_saldos_por_empresa($saldos);

        json_output([
            'success'  => true,
            'hasta'    => $hastaEfectivo,
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
     * GET AJAX: cuentas de Santander cuya cadena de saldos no cierra en el
     * rango pedido —indicio de movimientos que el banco omitió del TXT
     * diario. Alimenta el card "¿Faltan movimientos de Santander?" de la
     * vista: mismo diagnóstico que ya hacen los parsers al importar un
     * archivo nuevo (ver roturas_cadena_saldos() en el modelo), aplicado
     * aquí a un rango arbitrario de lo que ya hay en BD.
     *
     * Mismo permiso que subir movimientos (81): es una herramienta de
     * revisión para quien carga los archivos, no parte de la consulta que
     * ve cualquiera con acceso de solo lectura al módulo.
     */
    public function santander_huecos(): void
    {
        header('Content-Type: application/json');
        if (!authorized(self::PERM_SUBIR_MOV)) {
            json_output(['success' => false, 'message' => 'Sin permiso']);
            return;
        }

        $fmt   = '/^\d{4}-\d{2}-\d{2}$/';
        $desde = $_GET['desde'] ?? '';
        $hasta = $_GET['hasta'] ?? '';
        if (!preg_match($fmt, $desde)) $desde = date('Y-m-d', strtotime('-30 days'));
        if (!preg_match($fmt, $hasta)) $hasta = date('Y-m-d');
        if ($desde > $hasta) $desde = $hasta;

        try {
            $cuentas = $this->movsModel->huecos_santander($desde, $hasta, $this->cuentas_permitidas());
        } catch (Exception $e) {
            error_log('santander_huecos: ' . $e->getMessage());
            json_output(['success' => false, 'message' => 'Error al revisar los movimientos']);
            return;
        }

        json_output([
            'success' => true,
            'desde'   => $desde,
            'hasta'   => $hasta,
            'cuentas' => $cuentas,
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
        $hastaEfectivo = self::corte_efectivo($hasta);
        $porGrupo = $this->agrupar_por_grupo_empresarial(
            $this->agrupar_saldos_por_empresa($this->movsModel->get_saldos_finales($hasta))
        );

        $libro = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $hoja  = $libro->getActiveSheet();
        $hoja->setTitle('Saldo por grupo');

        $hoja->fromArray(['Saldo final por grupo al ' . date('d/m/Y', strtotime($hastaEfectivo))], null, 'A1');
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

        // Etiqueta del formato reconocido, para que el modal le diga al
        // usuario qué detectó (no todos los bancos tienen más de un
        // formato: queda null salvo Santander).
        $formato = null;
        if (isset($cfg['parser'])) {
            $parseo = MovimientosBancariosModel::{$cfg['parser']}(...$args);
        } else {
            // Santander: no se le pide al usuario decir cuál de los tres
            // formatos es (mismo patrón que los PDF de Amex en
            // upload_movimientos_cheques()). Se prueban en orden hasta que
            // uno reconozca el archivo; si ninguno lo hace, se reportan los
            // errores del último intento.
            $parseo = ['movimientos' => [], 'errores' => []];
            foreach (self::SANTANDER_PARSERS as $parser => $etiqueta) {
                $intento = MovimientosBancariosModel::$parser(...$args);
                $parseo  = $intento;
                if (!empty($intento['movimientos'])) {
                    $formato = $etiqueta;
                    break;
                }
            }
        }
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
        // El TXT de Banorte puede traer cuentas en dos monedas: el resumen las
        // separa en bloques (info.por_moneda), así que no hace falta avisarlo.

        $pendientes = (int)($parseo['info']['pendientes'] ?? 0);
        if ($pendientes > 0) {
            $avisos[] = "$pendientes movimiento" . ($pendientes === 1 ? '' : 's')
                      . ' sin confirmar por el banco: si cambia su importe al confirmarse, se importará de nuevo';
        }

        // La cadena de saldos rota (parse_santander_txt/chequeras/spei) es
        // informativa, no un error de línea: no debe pintar el modal de rojo
        // cuando el import en sí salió bien. Se separa de $errores aquí y no
        // en el parser porque el resto de bancos SÍ trata su propia cadena
        // rota como señal seria (su dedup depende más de ella) — ver
        // roturas_cadena_saldos() y el docblock de cada parser.
        $errores = [];
        foreach ($parseo['errores'] as $e) {
            if (str_contains($e, 'probablemente faltan movimientos en el archivo')) {
                $avisos[] = $e;
            } else {
                $errores[] = $e;
            }
        }

        $fechas = array_column($parseo['movimientos'], 'fecha');
        json_output([
            'success'    => true,
            'banco'      => $banco,
            'formato'    => $formato,
            'insertados' => $res['insertados'],
            'duplicados' => $res['duplicados'],
            'errores'    => array_slice($errores, 0, 20),
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

    /* ===================================================================== */
    /* Movimientos bancos cheques (estados de cuenta de tarjeta de crédito)  */
    /* ===================================================================== */

    /**
     * Vista de "Movimientos bancos cheques": casi copia de movimientos_bancos(),
     * pero para cargos de tarjeta de crédito importados de PDF. Sin saldo
     * corrido (no aplica a una línea de crédito), por eso no hay panel de
     * saldos finales aquí.
     */
    public function movimientos_bancos_cheques(): void
    {
        if (!authorized(self::PERM_MOV_CHEQUES)) {
            (new Errors())->get404();
            return;
        }

        $usuario = (int)($_SESSION['tg_user']['Id'] ?? 0);

        echo $this->twig->render($this->route . 'movimientos_bancos_cheques.html', [
            'filtros'       => $this->filtros_movimientos($_GET),
            'cuentas'       => $this->tarjetasModel->get_cuentas(),
            'bancos'        => self::BANCOS_CHEQUES,
            // Departamento es un catálogo cerrado (TG.dbo.Departamentos): se
            // carga una sola vez al entrar al módulo y se comparte entre
            // todas las filas de la tabla (edición en línea).
            'departamentos' => $this->departamentosModel->all() ?: [],
            // Centro de Costo sigue siendo texto libre con sugerencias.
            'centrosCosto'  => $this->tarjetasModel->get_centros_costo(),
            // Departamento del usuario logueado: preselecciona el select en
            // filas sin departamento capturado, sin forzar el valor (sigue
            // siendo editable a cualquier otro de la lista).
            'departamentoUsuario' => $usuario ? $this->departamentosModel->nombre_de_usuario($usuario) : null,
        ]);
    }

    /** POST AJAX: cargos de tarjeta de crédito del rango, ya partidos por banco. */
    public function movimientos_cheques_table(): void
    {
        header('Content-Type: application/json');
        if (!authorized(self::PERM_MOV_CHEQUES)) {
            json_output(['success' => false, 'message' => 'Sin permiso']);
            return;
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            json_output(['success' => false, 'message' => 'Método no permitido']);
            return;
        }

        $filtros = $this->filtros_movimientos($_POST);

        try {
            $movimientos = $this->tarjetasModel->get_movimientos($filtros);
        } catch (Exception $e) {
            error_log('movimientos_cheques_table: ' . $e->getMessage());
            json_output(['success' => false, 'message' => 'Error al consultar los movimientos']);
            return;
        }

        // Conteo de documentos adjuntos por movimiento, para el icono junto
        // al campo Factura: un solo query para todo el rango en vez de una
        // consulta por fila.
        $conteoDocs = $this->tarjetasDocsModel->get_conteos(array_column($movimientos, 'id'));
        foreach ($movimientos as &$m) {
            $m['n_documentos'] = $conteoDocs[(int)$m['id']] ?? 0;
        }
        unset($m);

        $porTabla = array_fill_keys(array_keys(self::BANCOS_CHEQUES), []);
        foreach ($movimientos as $m) {
            $banco = strtoupper((string)$m['banco']);
            $porTabla[isset(self::BANCOS_CHEQUES[$banco]) ? $banco : 'BANORTE'][] = $m;
        }

        json_output([
            'success'     => true,
            'filtros'     => $filtros,
            'movimientos' => $porTabla,
        ]);
    }

    /**
     * POST AJAX: importa el PDF de estado de cuenta de tarjeta de crédito.
     * Recibe $_POST['banco'] y $_FILES['archivo']; mismo esqueleto de
     * validación que upload_movimientos(), adaptado a un solo formato (PDF)
     * y sin campo "cuenta" manual (el PDF trae el número de cuenta/tarjeta).
     */
    public function upload_movimientos_cheques(): void
    {
        header('Content-Type: application/json');
        if (!authorized(self::PERM_MOV_CHEQUES) || !authorized(self::PERM_SUBIR_MOV)) {
            json_output(['success' => false, 'message' => 'No tienes permiso para importar movimientos']);
            return;
        }

        $banco = strtoupper(trim($_POST['banco'] ?? ''));
        $cfg   = self::BANCOS_CHEQUES[$banco] ?? null;
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

        if ($ext === 'xlsx') {
            // El Excel de Amex ya trae fecha completa (con año) en cada fila:
            // no hay "periodo de corte" que leer ni resolver, a diferencia
            // del PDF. anio/mes van en 0 porque resolverFechasYHuellas() no
            // los usa cuando el movimiento ya trae 'fecha' resuelta.
            $parseo = TarjetasCreditoModel::parse_amex_excel_credito($file['tmp_name']);
            $anioCorte = $mesCorte = 0;
        } else {
            // El parser necesita la ruta en disco (pdftotext abre el archivo).
            $periodo = TarjetasCreditoModel::extraer_periodo_corte($file['tmp_name']);
            if ($periodo === null) {
                json_output(['success' => false, 'message' => 'No se pudo leer la fecha de corte del PDF']);
                return;
            }
            $anioCorte = $periodo['anio'];
            $mesCorte  = $periodo['mes'];

            if (isset($cfg['parser'])) {
                $parseo = TarjetasCreditoModel::{$cfg['parser']}($file['tmp_name']);
            } else {
                // Amex tiene más de un layout de PDF (Platinum, Gold
                // Corporate...): se prueban en orden hasta que uno reconozca
                // el archivo, sin que el usuario tenga que saber cuál es.
                $parseo = ['movimientos' => [], 'errores' => []];
                foreach (self::AMEX_PDF_PARSERS as $parser) {
                    $intento = TarjetasCreditoModel::$parser($file['tmp_name']);
                    if (!empty($intento['movimientos'])) {
                        $parseo = $intento;
                        break;
                    }
                    $parseo = $intento;   // se conserva el último para reportar sus errores si ninguno reconoce el archivo
                }
            }
        }

        if (empty($parseo['movimientos'])) {
            json_output([
                'success' => false,
                'message' => 'El archivo no contiene movimientos con el formato de ' . $banco,
                'errores' => array_slice($parseo['errores'], 0, 20),
            ]);
            return;
        }

        try {
            $res = $this->tarjetasModel->insert_bulk(
                $parseo['movimientos'],
                $anioCorte,
                $mesCorte,
                mb_substr($file['name'], 0, 120),
                (int)($_SESSION['tg_user']['Id'] ?? 0) ?: null
            );
            // Los cargos recién importados llegan sin departamento capturado:
            // se les aplica la regla por concepto si alguna matchea (nunca
            // pisa una edición manual, porque solo toca los que están vacíos).
            if ($res['insertados'] > 0) {
                $this->reglasDeptoModel->aplicar_a_pendientes();
            }
        } catch (Exception $e) {
            error_log("upload_movimientos_cheques($banco): " . $e->getMessage());
            json_output(['success' => false, 'message' => 'Error al guardar en base de datos, no se importó nada']);
            return;
        }

        json_output([
            'success'    => true,
            'banco'      => $banco,
            'insertados' => $res['insertados'],
            'duplicados' => $res['duplicados'],
            // Detalle de qué se consideró "ya existía" (mismo banco+cuenta+
            // tarjeta+fecha+descripción+RFC+cargo/abono que algo ya
            // guardado): para que el usuario pueda revisar si de verdad son
            // duplicados o cargos legítimos que coinciden en fecha e importe.
            'detalleDuplicados' => array_slice($res['detalleDuplicados'] ?? [], 0, 20),
            'errores'    => array_slice($parseo['errores'], 0, 20),
        ]);
    }

    /**
     * POST AJAX: guarda UN campo de la clasificación manual (Departamento,
     * Conf. No., Factura, Comentarios o Centro de Costo — campos que el PDF
     * no trae), editado en línea directo en la tabla. Cada campo se guarda
     * por separado al salir de él (blur), no los 5 juntos: más simple para
     * el usuario y evita pisar un cambio concurrente en otro campo de la
     * misma fila.
     */
    public function guardar_campo_movimiento_cheque(): void
    {
        header('Content-Type: application/json');
        if (!authorized(self::PERM_MOV_CHEQUES)) {
            json_output(['success' => false, 'message' => 'Sin permiso']);
            return;
        }

        $id = intval($_POST['id'] ?? 0);
        if (!$id || !$this->tarjetasModel->get_by_id($id)) {
            json_output(['success' => false, 'message' => 'El movimiento no existe']);
            return;
        }

        $campo = trim($_POST['campo'] ?? '');
        $usuario = (int)($_SESSION['tg_user']['Id'] ?? 0) ?: null;
        $ok = $this->tarjetasModel->update_campo_clasificacion($id, $campo, $_POST['valor'] ?? null, $usuario);

        json_output([
            'success' => $ok,
            'message' => $ok ? 'Guardado' : 'No se pudo guardar',
            'id'      => $id,
            'campo'   => $campo,
        ]);
    }

    /* ===================================================================== */
    /* Documentos (factura/comprobante) adjuntos a un movimiento de tarjeta  */
    /* ===================================================================== */

    /** Lista los documentos ya subidos para un movimiento. */
    public function documentos_movimiento_cheque(): void
    {
        header('Content-Type: application/json');
        if (!authorized(self::PERM_MOV_CHEQUES)) {
            json_output(['success' => false, 'message' => 'Sin permiso']);
            return;
        }
        $movimientoId = (int)($_POST['movimiento_id'] ?? 0);
        if (!$movimientoId || !$this->tarjetasModel->get_by_id($movimientoId)) {
            json_output(['success' => false, 'message' => 'El movimiento no existe']);
            return;
        }
        $archivos = $this->tarjetasDocsModel->get_by_movimiento($movimientoId);
        json_output(['success' => true, 'archivos' => $archivos]);
    }

    /** Sube uno o más documentos para un movimiento de tarjeta de crédito. */
    public function subir_documento_movimiento_cheque(): void
    {
        header('Content-Type: application/json');
        if (!authorized(self::PERM_MOV_CHEQUES) || !authorized(self::PERM_SUBIR_MOV)) {
            json_output(['success' => false, 'message' => 'No tienes permiso para subir documentos']);
            return;
        }
        $movimientoId = (int)($_POST['movimiento_id'] ?? 0);
        if (!$movimientoId || !$this->tarjetasModel->get_by_id($movimientoId)) {
            json_output(['success' => false, 'message' => 'El movimiento no existe']);
            return;
        }
        if (empty($_FILES['documento']) || !is_array($_FILES['documento']['name'])) {
            json_output(['success' => false, 'message' => 'No se recibieron archivos']);
            return;
        }

        $usuario = (int)($_SESSION['tg_user']['Id'] ?? 0);
        $files   = $_FILES['documento'];
        $total   = count($files['name']);
        $subidos = 0;
        $errores = [];

        for ($i = 0; $i < $total; $i++) {
            $file = [
                'name'     => $files['name'][$i],
                'type'     => $files['type'][$i],
                'tmp_name' => $files['tmp_name'][$i],
                'error'    => $files['error'][$i],
                'size'     => $files['size'][$i],
            ];
            $res = $this->tarjetasDocsModel->upload($movimientoId, $file, $usuario);
            if ($res['success']) $subidos++;
            else $errores[] = $file['name'] . ': ' . $res['message'];
        }

        json_output([
            'success' => $subidos > 0,
            'subidos' => $subidos,
            'errores' => $errores,
            'message' => $subidos > 0
                ? "$subidos archivo(s) subido(s)" . ($errores ? ' (' . count($errores) . ' con error)' : '')
                : 'No se pudo subir ningún archivo',
        ]);
    }

    /** Soft-delete de un documento: se oculta de la lista, no se borra de disco. */
    public function eliminar_documento_movimiento_cheque(): void
    {
        header('Content-Type: application/json');
        if (!authorized(self::PERM_MOV_CHEQUES)) {
            json_output(['success' => false, 'message' => 'Sin permiso']);
            return;
        }
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            json_output(['success' => false, 'message' => 'Parámetros inválidos']);
            return;
        }
        $usuario = (int)($_SESSION['tg_user']['Id'] ?? 0);
        $ok = $this->tarjetasDocsModel->soft_delete($id, $usuario);
        json_output($ok
            ? ['success' => true, 'message' => 'Archivo eliminado']
            : ['success' => false, 'message' => 'No se pudo eliminar (¿ya estaba eliminado?)']);
    }

    /** Sirve un documento (PDF/imagen). GET /tesoreria/ver_documento_movimiento_cheque/ID */
    public function ver_documento_movimiento_cheque($id): void
    {
        if (!authorized(self::PERM_MOV_CHEQUES)) {
            http_response_code(403);
            echo 'No autorizado';
            return;
        }
        $doc = $this->tarjetasDocsModel->get_by_id((int)$id);
        if (!$doc) {
            http_response_code(404);
            echo 'Documento no encontrado';
            return;
        }

        $fullPath = realpath(__DIR__ . '/../../' . $doc['file_path']);
        $base     = realpath(__DIR__ . '/../../' . TarjetasCreditoDocumentosModel::UPLOAD_BASE);

        if (!$fullPath || !$base || !str_starts_with($fullPath, $base) || !file_exists($fullPath)) {
            http_response_code(404);
            echo 'Archivo no encontrado';
            return;
        }

        $mime = match ($doc['file_extension']) {
            'pdf'         => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png'         => 'image/png',
            default       => 'application/octet-stream',
        };

        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . basename($fullPath) . '"');
        header('Content-Length: ' . filesize($fullPath));
        readfile($fullPath);
    }

    /* ===================================================================== */
    /* Reglas de auto-clasificación de Departamento por concepto             */
    /* ===================================================================== */

    /** Lista las reglas activas, para el modal "Reglas de departamento". */
    public function reglas_departamento_cheque(): void
    {
        header('Content-Type: application/json');
        if (!authorized(self::PERM_MOV_CHEQUES)) {
            json_output(['success' => false, 'message' => 'Sin permiso']);
            return;
        }
        json_output(['success' => true, 'reglas' => $this->reglasDeptoModel->all()]);
    }

    /** Crea una regla (palabra clave -> departamento). */
    public function crear_regla_departamento_cheque(): void
    {
        header('Content-Type: application/json');
        if (!authorized(self::PERM_MOV_CHEQUES) || !authorized(self::PERM_SUBIR_MOV)) {
            json_output(['success' => false, 'message' => 'No tienes permiso para crear reglas']);
            return;
        }
        $palabraClave = trim($_POST['palabra_clave'] ?? '');
        $departamentoId = (int)($_POST['departamento_id'] ?? 0);
        if ($palabraClave === '' || !$departamentoId) {
            json_output(['success' => false, 'message' => 'Captura la palabra clave y elige un departamento']);
            return;
        }
        $usuario = (int)($_SESSION['tg_user']['Id'] ?? 0) ?: null;
        $ok = $this->reglasDeptoModel->create($palabraClave, $departamentoId, $usuario);
        json_output(['success' => $ok, 'message' => $ok ? 'Regla creada' : 'No se pudo crear la regla']);
    }

    /** Elimina una regla. */
    public function eliminar_regla_departamento_cheque(): void
    {
        header('Content-Type: application/json');
        if (!authorized(self::PERM_MOV_CHEQUES) || !authorized(self::PERM_SUBIR_MOV)) {
            json_output(['success' => false, 'message' => 'No tienes permiso para eliminar reglas']);
            return;
        }
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            json_output(['success' => false, 'message' => 'Parámetros inválidos']);
            return;
        }
        $ok = $this->reglasDeptoModel->delete($id);
        json_output(['success' => $ok, 'message' => $ok ? 'Regla eliminada' : 'No se pudo eliminar']);
    }

    /**
     * Botón "Reaplicar reglas": corre las reglas activas contra los cargos
     * que sigan sin departamento capturado (nunca pisa una edición manual ya
     * hecha ni un departamento ya asignado por una regla anterior).
     */
    public function reaplicar_reglas_departamento_cheque(): void
    {
        header('Content-Type: application/json');
        if (!authorized(self::PERM_MOV_CHEQUES) || !authorized(self::PERM_SUBIR_MOV)) {
            json_output(['success' => false, 'message' => 'No tienes permiso para reaplicar reglas']);
            return;
        }
        $n = $this->reglasDeptoModel->aplicar_a_pendientes();
        json_output([
            'success' => true,
            'actualizados' => $n,
            'message' => $n > 0 ? "$n cargo(s) clasificado(s)" : 'No había cargos pendientes que matchearan alguna regla',
        ]);
    }
}
