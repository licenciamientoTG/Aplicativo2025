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
    private const PERM_VER      = 79;   // Ver módulo de Tesorería
    private const MAX_TXT_BYTES = 10 * 1024 * 1024;

    /** Grupo de saldos para cuentas que no están en CatalogosCuentasBancarias. */
    private const GRUPO_SIN_CATALOGO = 'SIN CATÁLOGO';

    /**
     * Bancos soportados. Agregar uno nuevo es una entrada aquí más su parser
     * en MovimientosBancariosModel; el endpoint de importación, los KPI por
     * banco y los tabs de la vista salen de este registro.
     *
     * entrada: 'contenido' pasa el archivo leído al parser, 'ruta' pasa el
     * path (PhpSpreadsheet necesita abrir el .xlsx desde disco).
     */
    private const BANCOS = [
        'SANTANDER' => [
            'etiqueta' => 'Santander',
            'ext'      => ['txt'],
            'espera'   => 'el TXT de Enlace Santander',
            'parser'   => 'parse_santander_txt',
            'entrada'  => 'contenido',
        ],
        'AFIRME' => [
            'etiqueta' => 'Afirme',
            'ext'      => ['xls', 'txt', 'tsv', 'csv'],
            'espera'   => 'el export de movimientos de Afirme',
            'parser'   => 'parse_afirme_tsv',
            'entrada'  => 'contenido',
        ],
        'INBURSA' => [
            'etiqueta' => 'Inbursa',
            'ext'      => ['xlsx'],
            'espera'   => 'el Estado de Cuenta Individual de Inbursa',
            'parser'   => 'parse_inbursa_xlsx',
            'entrada'  => 'ruta',
        ],
        'BBVA' => [
            'etiqueta' => 'BBVA',
            'ext'      => ['xls'],
            'espera'   => 'el reporte de movimientos de BBVA',
            'parser'   => 'parse_bbva_xml',
            'entrada'  => 'ruta',
        ],
        'BANKAOOL' => [
            'etiqueta' => 'Bankaool',
            'ext'      => ['xlsx'],
            'espera'   => 'el export de movimientos de Bankaool',
            'parser'   => 'parse_bankaool_xlsx',
            'entrada'  => 'ruta',
            // Su archivo no trae la cuenta: el modal la pide y se pasa al parser
            'pide_cuenta' => true,
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
        if (!authorized(self::PERM_VER)) {
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
     * POST AJAX: movimientos del rango, ya partidos por banco, más los KPIs
     * de la cabecera. Una sola consulta alimenta las dos tablas y las tarjetas.
     */
    public function movimientos_table(): void
    {
        header('Content-Type: application/json');
        if (!authorized(self::PERM_VER)) {
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
        // porBanco alimenta el desglose de los KPI cards.
        $porTabla = self::por_banco_vacio([]);
        $totales  = ['abonos' => 0.0, 'cargos' => 0.0, 'movs' => count($movimientos)];
        $porBanco = self::por_banco_vacio(['abonos' => 0.0, 'cargos' => 0.0, 'movs' => 0]);

        foreach ($movimientos as $m) {
            $totales['abonos'] += (float)($m['abono'] ?? 0);
            $totales['cargos'] += (float)($m['cargo'] ?? 0);
            $b = self::banco_de($m['banco']);
            $porBanco[$b]['abonos'] += (float)($m['abono'] ?? 0);
            $porBanco[$b]['cargos'] += (float)($m['cargo'] ?? 0);
            $porBanco[$b]['movs']++;
            $porTabla[$b][] = $m;
        }
        $totales['neto'] = $totales['abonos'] - $totales['cargos'];
        foreach ($porBanco as $b => $t) {
            $porBanco[$b]['neto'] = $t['abonos'] - $t['cargos'];
        }

        json_output([
            'success'     => true,
            'filtros'     => $filtros,
            'movimientos' => $porTabla,
            'kpis'        => [
                'totales'  => $totales,
                'porBanco' => $porBanco,
                'saldo'    => $this->kpi_saldo($filtros),
            ],
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
     * KPI de saldo al corte de "hasta": el de la cuenta filtrada, o la suma
     * del último saldo de cada cuenta si se ven todas. saldoBanco es la
     * posición global por banco, independiente del filtro de cuenta.
     */
    private function kpi_saldo(array $filtros): array
    {
        $final      = null;
        $finalFecha = null;
        $porBanco   = array_map(fn() => 0.0, self::BANCOS);
        $saldos     = $this->movsModel->get_saldos_finales($filtros['hasta']);

        if ($filtros['cuenta'] !== '') {
            foreach ($saldos as $s) {
                if ($s['cuenta'] === $filtros['cuenta']) {
                    $final      = (float)$s['saldo'];
                    $finalFecha = substr((string)$s['fecha'], 0, 10);
                    break;
                }
            }
        } elseif (!empty($saldos)) {
            $final = 0.0;
            foreach ($saldos as $s) $final += (float)$s['saldo'];
        }

        foreach ($saldos as $s) {
            $porBanco[self::banco_de($s['banco'])] += (float)$s['saldo'];
        }

        return ['final' => $final, 'fecha' => $finalFecha, 'porBanco' => $porBanco];
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
        if (!authorized(self::PERM_VER)) {
            json_output(['success' => false, 'message' => 'Sin permiso']);
            return;
        }
        $hasta = $_GET['hasta'] ?? '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) $hasta = date('Y-m-d');

        $saldos = $this->movsModel->get_saldos_finales($hasta);
        $total  = 0.0;
        foreach ($saldos as $s) $total += (float)$s['saldo'];

        json_output([
            'success' => true,
            'hasta'   => $hasta,
            'grupos'  => $this->agrupar_saldos_por_empresa($saldos),
            'totales' => $this->totalizar_por_moneda($saldos),
            // contrato plano anterior, por si otro consumidor lo usa
            'saldos'  => $saldos,
            'total'   => $total,
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
                    'cuentas'      => [],
                ];
            }

            $moneda = self::moneda($s['divisa'] ?? null);
            $saldo  = (float)$s['saldo'];

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

        foreach ($grupos as &$g) {
            usort($g['cuentas'], fn($a, $b) => $b['saldo'] <=> $a['saldo']);
        }
        unset($g);

        // SIN CATÁLOGO al final sin importar su saldo; el resto por MXN desc
        uasort($grupos, function ($a, $b) {
            if ($a['sin_catalogo'] !== $b['sin_catalogo']) return $a['sin_catalogo'] ? 1 : -1;
            return ($b['totales']['MXN']['saldo'] ?? 0) <=> ($a['totales']['MXN']['saldo'] ?? 0);
        });

        return array_values($grupos);
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
        if (!authorized(self::PERM_VER)) {
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

        $fechas = array_column($parseo['movimientos'], 'fecha');
        json_output([
            'success'    => true,
            'banco'      => $banco,
            'insertados' => $res['insertados'],
            'duplicados' => $res['duplicados'],
            'errores'    => array_slice($parseo['errores'], 0, 20),
            'avisos'     => $this->avisos_catalogo($parseo['movimientos']),
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
        if (!authorized(self::PERM_VER)) {
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
        if (!authorized(self::PERM_VER)) {
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
        if (!authorized(self::PERM_VER)) {
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
        if (!authorized(self::PERM_VER)) {
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
        if (!authorized(self::PERM_VER)) {
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
