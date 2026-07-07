<?php

/**
 * Arqueos simultáneos Dollar2Go.
 *
 * Todas las sucursales cuentan su caja física al mismo tiempo; cada cajero
 * (usuario auditor) captura su propia caja. Un auditor administrador programa,
 * abre y cierra la sesión. Una sesión cerrada es inmutable.
 *
 * Rutas: /arqueo/[metodo]/[params]  (autocargado por index.php).
 *
 * Tablas: [TG].[dbo].arqueo_sesiones | arqueo_cajas | arqueo_denominaciones | arqueo_vales
 * Schema: docs/sql/arqueo_schema.sql
 */
class Arqueo
{
    private const PERM_ADMIN   = 73; // arqueo_admin    — auditor administrador
    private const PERM_AUDITOR = 74; // arqueo_capturar — usuario auditor (captura)

    /**
     * Sucursales del arqueo. Waterfill y Perez Serna tienen 2 cajas.
     * sucursal_id agrupa las cajas de una misma sucursal en el concentrado.
     */
    private const SUCURSALES = [
        ['id' => 1,  'nombre' => 'Waterfill',       'caja' => 1],
        ['id' => 1,  'nombre' => 'Waterfill',       'caja' => 2],
        ['id' => 2,  'nombre' => 'Misiones',        'caja' => 1],
        ['id' => 3,  'nombre' => 'Municipio',       'caja' => 1],
        ['id' => 4,  'nombre' => 'Puerto de Palos', 'caja' => 1],
        ['id' => 5,  'nombre' => 'Permuta',         'caja' => 1],
        ['id' => 6,  'nombre' => 'Anapra',          'caja' => 1],
        ['id' => 7,  'nombre' => 'Gomez Morin',     'caja' => 1],
        ['id' => 8,  'nombre' => 'Lopez Mateos',    'caja' => 1],
        ['id' => 9,  'nombre' => 'Villa Ahumada',   'caja' => 1],
        ['id' => 10, 'nombre' => 'Km 30',           'caja' => 1],
        ['id' => 11, 'nombre' => 'Curva',           'caja' => 1],
        ['id' => 12, 'nombre' => 'Custodia',        'caja' => 1],
        ['id' => 13, 'nombre' => 'Perez Serna',     'caja' => 1],
        ['id' => 13, 'nombre' => 'Perez Serna',     'caja' => 2],
    ];

    /**
     * Denominaciones fijas por sección. Para morrallero_cf el valor por bolsa
     * está alineado posicionalmente con la denominación facial.
     */
    private const DENOMINACIONES = [
        'cajon' => [
            'USD' => [100, 50, 20, 10, 5, 2, 1],
            'MXN' => [1000, 500, 200, 100, 50, 20],
        ],
        'morrallero' => [
            'USD' => [1, 0.50, 0.25, 0.10, 0.05, 0.01],
            'MXN' => [20, 10, 5, 2, 1, 0.50],
        ],
        'caja_fuerte' => [ // fajillas (× 100 billetes)
            'USD' => [100, 50, 20, 10, 5, 2, 1],
            'MXN' => [1000, 500, 200, 100, 50, 20],
        ],
        'morrallero_cf' => [ // bolsas (valor fijo por bolsa = piezas por bolsa × denominación)
            'USD' => ['valor_bolsa' => [100, 50, 20, 10, 5, 1],
                      'denominacion' => [1, 0.50, 0.25, 0.10, 0.05, 0.01]],
            'MXN' => ['valor_bolsa' => [2000, 500, 500, 200, 100, 50],
                      'denominacion' => [20, 10, 5, 2, 1, 0.50]],
        ],
    ];

    public $twig;
    public $route;
    public ArqueoSesionesModel $sesionesModel;
    public ArqueoCajasModel $cajasModel;
    public ArqueoDenominacionesModel $denominacionesModel;
    public ArqueoValesModel $valesModel;
    public ArqueoConcentradoExtrasModel $concentradoExtrasModel;
    public ArqueoCapitalBaseModel $capitalBaseModel;
    public ArqueoAuditLogModel $auditLogModel;
    public UsuariosModel $usuariosModel;

    public function __construct($twig)
    {
        $this->twig                   = $twig;
        $this->route                  = 'views/arqueo/';
        $this->sesionesModel          = new ArqueoSesionesModel();
        $this->cajasModel             = new ArqueoCajasModel();
        $this->denominacionesModel    = new ArqueoDenominacionesModel();
        $this->valesModel             = new ArqueoValesModel();
        $this->concentradoExtrasModel = new ArqueoConcentradoExtrasModel();
        $this->capitalBaseModel       = new ArqueoCapitalBaseModel();
        $this->auditLogModel          = new ArqueoAuditLogModel();
        $this->usuariosModel          = new UsuariosModel();
    }

    /* ===================================================================== */
    /* Helpers                                                               */
    /* ===================================================================== */

    private function user_id(): ?int
    {
        return isset($_SESSION['tg_user']['Id']) ? (int) $_SESSION['tg_user']['Id'] : null;
    }

    /** Nombre visible del usuario logueado (mismo campo que la auditoría de pagos). */
    private function user_name(): ?string
    {
        return $_SESSION['tg_user']['Nombre']
            ?? $_SESSION['tg_user']['Usuario']
            ?? null;
    }

    /**
     * Corta la ejecución si el usuario no tiene NINGUNO de los permisos dados.
     * Responde JSON si la petición es AJAX, texto plano en otro caso.
     */
    private function guard(array $permisos): void
    {
        foreach ($permisos as $p) {
            if (authorized($p)) {
                return;
            }
        }
        http_response_code(403);
        if ($this->es_ajax()) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'No autorizado.']);
        } else {
            echo 'No autorizado para acceder a este módulo.';
        }
        exit;
    }

    /**
     * ¿El usuario actual puede entrar/guardar esta caja?
     * Admin: siempre. Capturista: solo si la caja está asignada a él.
     * Caja sin asignar: solo admin.
     */
    private function puede_capturar(array $caja): bool
    {
        if (authorized(self::PERM_ADMIN)) {
            return true;
        }
        $asignado = (int) ($caja['asignado_user_id'] ?? 0);
        return $asignado > 0 && $asignado === (int) $this->user_id();
    }

    private function es_ajax(): bool
    {
        return isset($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }

    private function json($data): void
    {
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    /* ===================================================================== */
    /* Listado                                                               */
    /* ===================================================================== */

    /** Lista de sesiones de arqueo (admin o auditor). */
    public function index(): void
    {
        $this->guard([self::PERM_ADMIN, self::PERM_AUDITOR]);
        $sesiones = $this->sesionesModel->all();
        $es_admin = authorized(self::PERM_ADMIN);
        echo $this->twig->render($this->route . 'index.html', compact('sesiones', 'es_admin'));
    }

    /**
     * Lista las cajas de una sesión, cada una con enlace a su captura.
     * Es la pantalla a la que entra el auditor cuando el arqueo está abierto.
     */
    public function cajas($sesion_id): void
    {
        $this->guard([self::PERM_ADMIN, self::PERM_AUDITOR]);

        $sesion = $this->sesionesModel->find((int) $sesion_id);
        if (!$sesion) {
            (new Errors())->get404();
            return;
        }
        $es_admin = authorized(self::PERM_ADMIN);
        if ($es_admin) {
            $cajas    = $this->cajasModel->by_sesion((int) $sesion_id);
            $usuarios = $this->usuariosModel->get_users_by_permission(self::PERM_AUDITOR, false);
        } else {
            $cajas    = $this->cajasModel->by_sesion_asignadas((int) $sesion_id, (int) $this->user_id());
            $usuarios = [];
        }
        echo $this->twig->render($this->route . 'cajas.html', compact('sesion', 'cajas', 'es_admin', 'usuarios'));
    }

    /**
     * Asigna (o desasigna) el usuario capturista de una caja. Solo admin.
     * POST JSON: caja_id, user_id (null o 0 = desasignar).
     */
    public function asignar_caja(): void
    {
        $this->guard([self::PERM_ADMIN]);
        header('Content-Type: application/json');

        $in      = $this->input();
        $caja_id = (int) ($in['caja_id'] ?? 0);
        $user_id = isset($in['user_id']) && (int) $in['user_id'] > 0 ? (int) $in['user_id'] : null;

        if ($caja_id <= 0) {
            $this->json(['success' => false, 'message' => 'Caja inválida.']);
        }
        $caja = $this->cajasModel->find($caja_id);
        if (!$caja) {
            $this->json(['success' => false, 'message' => 'Caja no encontrada.']);
        }
        $sesion = $this->sesionesModel->find((int) $caja['sesion_id']);
        if (!$sesion) {
            $this->json(['success' => false, 'message' => 'Sesión no encontrada.']);
        }
        if ($sesion['estado'] === 'cerrado') {
            $this->json(['success' => false, 'message' => 'La sesión está cerrada; no se puede reasignar.']);
        }

        $asignado_nombre = null;
        if ($user_id !== null) {
            $capturistas = $this->usuariosModel->get_users_by_permission(self::PERM_AUDITOR, false);
            foreach ($capturistas as $u) {
                if ((int) $u['Id'] === $user_id) {
                    $asignado_nombre = $u['Nombre'];
                    break;
                }
            }
            if ($asignado_nombre === null) {
                $this->json(['success' => false, 'message' => 'El usuario no tiene el permiso de captura de arqueos.']);
            }
        }

        // Nombre del asignado anterior (para auditoría)
        $anterior_id     = isset($caja['asignado_user_id']) ? (int) $caja['asignado_user_id'] : 0;
        $anterior_nombre = $anterior_id > 0 ? $this->sql_nombre_usuario($anterior_id) : null;

        if ($anterior_id === (int) ($user_id ?? 0)) {
            $this->json(['success' => true, 'asignado_nombre' => $asignado_nombre, 'message' => 'Sin cambios.']);
        }

        $this->sql_begin();
        try {
            if (!$this->cajasModel->asignar($caja_id, $user_id)) {
                throw new Exception('No se pudo asignar la caja.');
            }
            $ok_log = $this->auditLogModel->log(
                ArqueoAuditLogModel::ACC_ASIGNAR_CAJA,
                (int) $caja['sesion_id'], $caja_id, (int) $caja['sucursal_id'],
                $anterior_id > 0 ? ['asignado_user_id' => $anterior_id, 'asignado_nombre' => $anterior_nombre] : null,
                $user_id !== null ? ['asignado_user_id' => $user_id, 'asignado_nombre' => $asignado_nombre] : null,
                $this->user_id(), $this->user_name()
            );
            if (!$ok_log) {
                throw new Exception('No se pudo registrar la auditoría.');
            }
            $this->cajasModel->sql->commit();
        } catch (Exception $e) {
            $this->cajasModel->sql->rollBack();
            $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
        $this->json(['success' => true, 'asignado_nombre' => $asignado_nombre]);
    }

    /** Nombre de un usuario por Id (para auditoría de reasignaciones). */
    private function sql_nombre_usuario(int $user_id): ?string
    {
        $rows = $this->cajasModel->sql->select(
            "SELECT Nombre FROM [TG].[dbo].[Usuario] WHERE Id = ?;",
            [$user_id]
        );
        return $rows ? ($rows[0]['Nombre'] ?? null) : null;
    }

    /* ===================================================================== */
    /* Ciclo de la sesión (admin)                                            */
    /* ===================================================================== */

    /**
     * Crea una sesión 'programado' y genera una caja 'pendiente' por cada
     * sucursal configurada. POST: nombre, fecha (YYYY-MM-DD).
     */
    public function crear_sesion(): void
    {
        $this->guard([self::PERM_ADMIN]);
        header('Content-Type: application/json');

        $in     = $this->input();
        $nombre = trim($in['nombre'] ?? '');
        $fecha  = trim($in['fecha'] ?? '');

        if ($nombre === '' || $fecha === '') {
            $this->json(['success' => false, 'message' => 'Nombre y fecha son obligatorios.']);
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            $this->json(['success' => false, 'message' => 'Fecha inválida (use YYYY-MM-DD).']);
        }

        $base = $this->capitalBaseModel->get_all();

        $this->sql_begin();
        try {
            $sesion_id = $this->sesionesModel->create($nombre, $fecha, (int) $this->user_id());
            if (!$sesion_id) {
                throw new Exception('No se pudo crear la sesión.');
            }
            $sucursales_hechas = [];
            foreach (self::SUCURSALES as $s) {
                $this->cajasModel->create([
                    'sesion_id'       => $sesion_id,
                    'sucursal_id'     => $s['id'],
                    'sucursal_nombre' => $s['nombre'],
                    'caja_numero'     => $s['caja'],
                ]);
                if (isset($sucursales_hechas[$s['id']])) {
                    continue;
                }
                $sucursales_hechas[$s['id']] = true;
                $ok_extra = $this->concentradoExtrasModel->upsert($sesion_id, (int) $s['id'], [
                    'capital_trabajo' => $base[$s['id']] ?? 0.0,
                    'gastos_tramite'  => 0.0,
                    'adeudo'          => 0.0,
                    'reinversion'     => 0.0,
                    'utilidad'        => 0.0,
                ], $this->user_id());
                if (!$ok_extra) {
                    throw new Exception('No se pudo copiar el capital base de la sucursal ' . $s['id'] . '.');
                }
            }
            $ok_log = $this->auditLogModel->log(
                ArqueoAuditLogModel::ACC_CREAR_SESION,
                (int) $sesion_id, null, null,
                null,
                ['nombre' => $nombre, 'fecha' => $fecha],
                $this->user_id(), $this->user_name()
            );
            if (!$ok_log) {
                throw new Exception('No se pudo registrar la auditoría.');
            }
            $this->sesionesModel->sql->commit();
            $this->json(['success' => true, 'sesion_id' => $sesion_id]);
        } catch (Exception $e) {
            $this->sesionesModel->sql->rollBack();
            $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /** Abre una sesión: programado -> abierto. */
    public function abrir($sesion_id): void
    {
        $this->guard([self::PERM_ADMIN]);
        header('Content-Type: application/json');

        $sesion = $this->sesionesModel->find((int) $sesion_id);
        if (!$sesion) {
            $this->json(['success' => false, 'message' => 'Sesión no encontrada.']);
        }
        if ($sesion['estado'] !== 'programado') {
            $this->json(['success' => false, 'message' => 'Solo se puede abrir una sesión programada.']);
        }
        $ok = $this->sesionesModel->set_estado((int) $sesion_id, 'abierto');
        if ($ok) {
            $this->auditLogModel->log(
                ArqueoAuditLogModel::ACC_ABRIR_SESION,
                (int) $sesion_id, null, null,
                ['estado' => 'programado'], ['estado' => 'abierto'],
                $this->user_id(), $this->user_name()
            );
        }
        $this->json(['success' => (bool) $ok]);
    }

    /**
     * Cierra una sesión: exige que todas las cajas estén completadas.
     * abierto -> cerrado (inmutable).
     */
    public function cerrar($sesion_id): void
    {
        $this->guard([self::PERM_ADMIN]);
        header('Content-Type: application/json');

        $sesion = $this->sesionesModel->find((int) $sesion_id);
        if (!$sesion) {
            $this->json(['success' => false, 'message' => 'Sesión no encontrada.']);
        }
        if ($sesion['estado'] !== 'abierto') {
            $this->json(['success' => false, 'message' => 'Solo se puede cerrar una sesión abierta.']);
        }
        if (!$this->cajasModel->todas_completadas((int) $sesion_id)) {
            $pendientes = array_map(
                fn($c) => $c['sucursal_nombre'] . ' (Caja ' . $c['caja_numero'] . ')',
                $this->cajasModel->pendientes((int) $sesion_id)
            );
            $this->json([
                'success'    => false,
                'message'    => 'Faltan cajas por completar.',
                'pendientes' => $pendientes,
            ]);
        }
        $ok = $this->sesionesModel->set_estado((int) $sesion_id, 'cerrado', $this->user_id());
        if ($ok) {
            $this->auditLogModel->log(
                ArqueoAuditLogModel::ACC_CERRAR_SESION,
                (int) $sesion_id, null, null,
                ['estado' => 'abierto'], ['estado' => 'cerrado'],
                $this->user_id(), $this->user_name()
            );
        }
        $this->json(['success' => (bool) $ok]);
    }

    /* ===================================================================== */
    /* Captura de caja (auditor)                                             */
    /* ===================================================================== */

    /** Formulario de captura de una caja (solo-lectura si la sesión está cerrada). */
    public function caja($caja_id): void
    {
        $this->guard([self::PERM_AUDITOR, self::PERM_ADMIN]);

        $caja = $this->cajasModel->find((int) $caja_id);
        if (!$caja) {
            (new Errors())->get404();
            return;
        }
        if (!$this->puede_capturar($caja)) {
            http_response_code(403);
            echo 'No tienes asignada esta caja. Pide a un administrador que te la asigne.';
            return;
        }
        $sesion         = $this->sesionesModel->find((int) $caja['sesion_id']);
        $denominaciones = $this->denominacionesModel->by_caja((int) $caja_id);
        $vales          = $this->valesModel->by_caja((int) $caja_id);
        $solo_lectura   = ($sesion['estado'] === 'cerrado');
        $estructura     = self::DENOMINACIONES;

        echo $this->twig->render($this->route . 'caja.html', compact(
            'caja', 'sesion', 'denominaciones', 'vales', 'solo_lectura', 'estructura'
        ));
    }

    /**
     * Formato imprimible de UNA hoja con el resumen del arqueo de la caja
     * (lo guardado en BD) y espacios de firma para cajero y auditor.
     */
    public function imprimir_caja($caja_id): void
    {
        $this->guard([self::PERM_AUDITOR, self::PERM_ADMIN]);

        $caja = $this->cajasModel->find((int) $caja_id);
        if (!$caja) {
            (new Errors())->get404();
            return;
        }
        if (!$this->puede_capturar($caja)) {
            http_response_code(403);
            echo 'No tienes asignada esta caja. Pide a un administrador que te la asigne.';
            return;
        }
        if ($caja['estado'] === 'pendiente') {
            echo 'La caja aún no tiene captura guardada.';
            return;
        }
        $sesion = $this->sesionesModel->find((int) $caja['sesion_id']);
        $vales  = $this->valesModel->by_caja((int) $caja_id);

        echo $this->twig->render($this->route . 'caja_impresion.html', compact('caja', 'sesion', 'vales'));
    }

    /**
     * Guarda la captura de una caja. POST (JSON o form):
     *   cajero_nombre, encargado_revision,
     *   go_exchange_dolares, go_exchange_mxn, costo_promedio,
     *   denominaciones[] => {seccion,moneda,tipo,denominacion,valor_bolsa,cantidad},
     *   vales[]          => {numero_vale,fecha,concepto,dolares,mxn}
     * Recalcula todos los totales y marca la caja como completada.
     */
    public function guardar_caja($caja_id): void
    {
        $this->guard([self::PERM_AUDITOR, self::PERM_ADMIN]);
        header('Content-Type: application/json');

        $caja = $this->cajasModel->find((int) $caja_id);
        if (!$caja) {
            $this->json(['success' => false, 'message' => 'Caja no encontrada.']);
        }
        if (!$this->puede_capturar($caja)) {
            $this->json(['success' => false, 'message' => 'No tienes asignada esta caja.']);
        }
        $sesion = $this->sesionesModel->find((int) $caja['sesion_id']);
        if (!$sesion) {
            $this->json(['success' => false, 'message' => 'Sesión no encontrada.']);
        }
        if ($sesion['estado'] === 'cerrado') {
            $this->json(['success' => false, 'message' => 'La sesión está cerrada; la caja no se puede modificar.']);
        }
        if ($sesion['estado'] !== 'abierto') {
            $this->json(['success' => false, 'message' => 'La sesión debe estar abierta para capturar.']);
        }

        // Datos de entrada (soporta JSON body o $_POST). Se permite guardado
        // parcial (p.ej. solo el nombre del cajero) mientras la sesión esté
        // abierta; los campos numéricos ausentes se toman como 0.
        $in = $this->input();

        $denom_rows = $this->normalizar_denominaciones($in['denominaciones'] ?? []);
        $vales_in   = $in['vales'] ?? [];

        $go = [
            'go_exchange_dolares' => (float) ($in['go_exchange_dolares'] ?? 0),
            'go_exchange_mxn'     => (float) ($in['go_exchange_mxn'] ?? 0),
            'costo_promedio'      => (float) ($in['costo_promedio'] ?? 0),
        ];

        $totales = $this->calcular_totales_caja($denom_rows, $vales_in, $go);
        $totales['cajero_nombre']      = trim($in['cajero_nombre'] ?? '') ?: $caja['cajero_nombre'];
        $totales['encargado_revision'] = trim($in['encargado_revision'] ?? '');

        $snap_antes = $this->snapshot_caja(
            $caja,
            $this->denominacionesModel->by_caja((int) $caja_id),
            $this->valesModel->by_caja((int) $caja_id)
        );

        $this->sql_begin();
        try {
            $this->denominacionesModel->delete_by_caja((int) $caja_id);
            $this->valesModel->delete_by_caja((int) $caja_id);
            $this->denominacionesModel->insert_bulk((int) $caja_id, $denom_rows);
            $this->valesModel->insert_bulk((int) $caja_id, $vales_in);
            $this->cajasModel->update_totales((int) $caja_id, $totales);
            $snap_despues = $this->snapshot_caja($totales, $denom_rows, $vales_in);
            $ok_log = $this->auditLogModel->log(
                ArqueoAuditLogModel::ACC_GUARDAR_CAJA,
                (int) $caja['sesion_id'], (int) $caja_id, (int) $caja['sucursal_id'],
                $snap_antes, $snap_despues,
                $this->user_id(), $this->user_name()
            );
            if (!$ok_log) {
                throw new Exception('No se pudo registrar la auditoría de la caja.');
            }
            $this->cajasModel->sql->commit();
            $this->json(['success' => true, 'totales' => $totales]);
        } catch (Exception $e) {
            $this->cajasModel->sql->rollBack();
            $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /* ===================================================================== */
    /* Concentrado (admin)                                                   */
    /* ===================================================================== */

    /**
     * Consolidado de la sesión, agrupando las cajas por sucursal
     * (Waterfill C1+C2, Perez Serna C1+C2 se suman).
     */
    public function concentrado($sesion_id): void
    {
        $this->guard([self::PERM_ADMIN]);

        $sesion = $this->sesionesModel->find((int) $sesion_id);
        if (!$sesion) {
            (new Errors())->get404();
            return;
        }
        $cajas  = $this->cajasModel->by_sesion((int) $sesion_id);
        $extras = $this->concentradoExtrasModel->by_sesion((int) $sesion_id);

        $grupos = [];
        foreach ($cajas as $c) {
            $sid = (int) $c['sucursal_id'];
            if (!isset($grupos[$sid])) {
                $grupos[$sid] = [
                    'sucursal_id' => $sid,
                    'sucursal'    => $c['sucursal_nombre'],
                    'D'           => 0, // Total en Sistemas M.N.
                    'E'           => 0, // Total Conteo Físico sin Vales
                    'G'           => 0, // Vales Autorizados
                ];
            }
            $grupos[$sid]['D'] += (float) $c['total_en_sistema'];
            // E: físico MXN + (físico USD valuado al costo promedio de la caja)
            $grupos[$sid]['E'] += (float) $c['total_fisico_mxn']
                + ((float) $c['total_fisico_dolares'] * (float) $c['costo_promedio']);
            $grupos[$sid]['G'] += (float) $c['gran_total_vales_mxn'];
        }

        foreach ($grupos as $sid => &$g) {
            $ex = $extras[$sid] ?? null;
            $g['capital_trabajo'] = (float) ($ex['capital_trabajo'] ?? 0);
            $g['gastos_tramite']  = (float) ($ex['gastos_tramite']  ?? 0);
            $g['adeudo']          = (float) ($ex['adeudo']          ?? 0);
            $g['reinversion']     = (float) ($ex['reinversion']     ?? 0);
            $g['utilidad']        = (float) ($ex['utilidad']        ?? 0);

            // F: Faltantes/Sobrantes sin vales = E - D
            $g['F'] = $g['E'] - $g['D'];
            // H: Faltante Real de Arqueo = E - D + G
            $g['H'] = $g['E'] - $g['D'] + $g['G'];
            // L: Conteo Físico, Vales y Gastos = E + Gastos + Adeudo + G
            $g['L'] = $g['E'] + $g['gastos_tramite'] + $g['adeudo'] + $g['G'];
            // N: Capital, Utilidad y Reinversión = Capital + Reinversión + Utilidad
            $g['N'] = $g['capital_trabajo'] + $g['reinversion'] + $g['utilidad'];
            // O: Variación del Arqueo vs Indicadores D2GO = L - N
            $g['O'] = $g['L'] - $g['N'];
        }
        unset($g);

        $concentrado = array_values($grupos);
        echo $this->twig->render($this->route . 'concentrado.html', compact('sesion', 'concentrado'));
    }

    /** Historial de auditoría de una sesión (solo admin). */
    public function auditoria($sesion_id): void
    {
        $this->guard([self::PERM_ADMIN]);

        $sesion = $this->sesionesModel->find((int) $sesion_id);
        if (!$sesion) {
            (new Errors())->get404();
            return;
        }
        $logs = $this->auditLogModel->by_sesion((int) $sesion_id);

        // Mapa sucursal_id => nombre para mostrar en la tabla
        $sucursales = [];
        foreach (self::SUCURSALES as $s) {
            $sucursales[$s['id']] = $s['nombre'];
        }

        echo $this->twig->render($this->route . 'auditoria.html', compact('sesion', 'logs', 'sucursales'));
    }

    /**
     * Guarda (upsert) los 5 valores manuales de una sucursal en una sesión:
     * Capital de Trabajo, Gastos en trámite, Adeudo, Reinversión, Utilidad.
     * POST JSON: sesion_id, sucursal_id, capital_trabajo, gastos_tramite,
     * adeudo, reinversion, utilidad.
     * actualizar_base (bool, opcional): además guarda capital_trabajo como base.
     */
    public function guardar_concentrado_extra(): void
    {
        $this->guard([self::PERM_ADMIN]);
        header('Content-Type: application/json');

        $in          = $this->input();
        $sesion_id   = (int) ($in['sesion_id']   ?? 0);
        $sucursal_id = (int) ($in['sucursal_id'] ?? 0);
        if ($sesion_id <= 0 || $sucursal_id <= 0) {
            $this->json(['success' => false, 'message' => 'Sesión y sucursal son obligatorias.']);
        }

        $datos = [
            'capital_trabajo' => (float) ($in['capital_trabajo'] ?? 0),
            'gastos_tramite'  => (float) ($in['gastos_tramite']  ?? 0),
            'adeudo'          => (float) ($in['adeudo']          ?? 0),
            'reinversion'     => (float) ($in['reinversion']     ?? 0),
            'utilidad'        => (float) ($in['utilidad']        ?? 0),
        ];

        $previos = $this->concentradoExtrasModel->by_sesion($sesion_id)[$sucursal_id] ?? null;
        $antes = $previos === null ? null : [
            'capital_trabajo' => (float) $previos['capital_trabajo'],
            'gastos_tramite'  => (float) $previos['gastos_tramite'],
            'adeudo'          => (float) $previos['adeudo'],
            'reinversion'     => (float) $previos['reinversion'],
            'utilidad'        => (float) $previos['utilidad'],
        ];

        $ok = $this->concentradoExtrasModel->upsert($sesion_id, $sucursal_id, $datos, $this->user_id());

        if ($ok) {
            $this->auditLogModel->log(
                ArqueoAuditLogModel::ACC_EDITAR_CONCENTRADO,
                $sesion_id, null, $sucursal_id,
                $antes, $datos,
                $this->user_id(), $this->user_name()
            );
        }

        if ($ok && !empty($in['actualizar_base'])) {
            $base_anterior = $this->capitalBaseModel->get_all()[$sucursal_id] ?? null;
            $ok_base = $this->capitalBaseModel->upsert(
                $sucursal_id,
                $datos['capital_trabajo'],
                $this->user_id()
            );
            if ($ok_base) {
                $this->auditLogModel->log(
                    ArqueoAuditLogModel::ACC_EDITAR_CAPITAL_BASE,
                    $sesion_id, null, $sucursal_id,
                    $base_anterior === null ? null : ['capital_trabajo' => $base_anterior],
                    ['capital_trabajo' => $datos['capital_trabajo']],
                    $this->user_id(), $this->user_name()
                );
            }
        }

        $this->json(['success' => $ok]);
    }

    /* ===================================================================== */
    /* Exportar (admin)                                                      */
    /* ===================================================================== */

    /**
     * Exporta la sesión a Excel (una hoja por caja + Concentrado), replicando
     * el layout del formato original.
     * TODO: implementar el layout completo con PhpSpreadsheet (pendiente de
     * iteración con las vistas). Por ahora deja la cabecera lista.
     */
    public function exportar($sesion_id): void
    {
        $this->guard([self::PERM_ADMIN]);
        // Pendiente: construir el .xlsx con \PhpOffice\PhpSpreadsheet a partir de
        // by_sesion()/by_caja() y el concentrado calculado en concentrado().
        http_response_code(501);
        echo 'Exportación pendiente de implementar.';
    }

    /* ===================================================================== */
    /* Lógica de cálculo y entrada                                           */
    /* ===================================================================== */

    /** Lee el cuerpo de la petición: JSON si viene como tal, si no $_POST. */
    private function input(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return $_POST;
    }

    /**
     * Convierte las denominaciones de entrada a filas listas para insertar,
     * calculando el total de cada una según el tipo.
     */
    private function normalizar_denominaciones(array $denoms): array
    {
        $rows = [];
        foreach ($denoms as $d) {
            $tipo        = $d['tipo'] ?? 'billete';
            $denom       = (float) ($d['denominacion'] ?? 0);
            $cantidad    = (int) ($d['cantidad'] ?? 0);
            $valor_bolsa = isset($d['valor_bolsa']) ? (float) $d['valor_bolsa'] : null;

            $total = $this->total_denominacion($tipo, $denom, $cantidad, $valor_bolsa);

            $rows[] = [
                'seccion'      => $d['seccion'] ?? 'cajon',
                'moneda'       => $d['moneda'] ?? 'USD',
                'tipo'         => $tipo,
                'denominacion' => $denom,
                'valor_bolsa'  => $valor_bolsa,
                'cantidad'     => $cantidad,
                'total'        => $total,
            ];
        }
        return $rows;
    }

    /**
     * Total de una denominación:
     *  - billete/moneda: cantidad * denominacion
     *  - fajilla:        cantidad * denominacion * 100  (1 fajilla = 100 billetes)
     *  - bolsa:          cantidad * valor_bolsa         (valor fijo por bolsa)
     */
    private function total_denominacion(string $tipo, float $denom, int $cantidad, ?float $valor_bolsa): float
    {
        switch ($tipo) {
            case 'fajilla':
                return $cantidad * $denom * 100;
            case 'bolsa':
                return $cantidad * (float) $valor_bolsa;
            default: // billete | moneda
                return $cantidad * $denom;
        }
    }

    /**
     * Snapshot uniforme de una caja para auditoría. $enc acepta tanto la fila
     * de arqueo_cajas como el array de totales calculados (mismas claves).
     */
    private function snapshot_caja(array $enc, array $denominaciones, array $vales): array
    {
        return [
            'cajero_nombre'       => $enc['cajero_nombre'] ?? null,
            'encargado_revision'  => $enc['encargado_revision'] ?? null,
            'go_exchange_dolares' => round((float) ($enc['go_exchange_dolares'] ?? 0), 2),
            'go_exchange_mxn'     => round((float) ($enc['go_exchange_mxn'] ?? 0), 2),
            'costo_promedio'      => round((float) ($enc['costo_promedio'] ?? 0), 4),
            'totales' => [
                'total_fisico_dolares' => round((float) ($enc['total_fisico_dolares'] ?? 0), 2),
                'total_fisico_mxn'     => round((float) ($enc['total_fisico_mxn'] ?? 0), 2),
                'total_en_sistema'     => round((float) ($enc['total_en_sistema'] ?? 0), 2),
                'gran_total_vales_mxn' => round((float) ($enc['gran_total_vales_mxn'] ?? 0), 2),
                'resultado_final'      => round((float) ($enc['resultado_final'] ?? 0), 2),
            ],
            'denominaciones' => array_values(array_map(fn($d) => [
                'seccion'      => $d['seccion'],
                'moneda'       => $d['moneda'],
                'tipo'         => $d['tipo'],
                'denominacion' => (float) $d['denominacion'],
                'cantidad'     => (int) ($d['cantidad'] ?? 0),
                'total'        => (float) ($d['total'] ?? 0),
            ], $denominaciones)),
            'vales' => array_values(array_map(fn($v) => [
                'numero_vale' => $v['numero_vale'] ?? null,
                'fecha'       => $v['fecha'] ?? null,
                'concepto'    => $v['concepto'] ?? null,
                'dolares'     => (float) ($v['dolares'] ?? 0),
                'mxn'         => (float) ($v['mxn'] ?? 0),
            ], array_filter($vales, function ($v) {
                $dolares = (float) ($v['dolares'] ?? 0);
                $mxn     = (float) ($v['mxn'] ?? 0);
                return !($dolares == 0 && $mxn == 0 && empty($v['concepto']));
            }))),
        ];
    }

    /**
     * Calcula todos los totales de una caja a partir de las denominaciones
     * (ya normalizadas con su total), los vales y los datos Go Exchange.
     */
    private function calcular_totales_caja(array $denom_rows, array $vales, array $go): array
    {
        $sum = function (string $seccion, string $moneda) use ($denom_rows): float {
            $t = 0.0;
            foreach ($denom_rows as $r) {
                if ($r['seccion'] === $seccion && $r['moneda'] === $moneda) {
                    $t += (float) $r['total'];
                }
            }
            return $t;
        };

        $total_fisico_dolares =
            $sum('cajon', 'USD') + $sum('morrallero', 'USD')
            + $sum('caja_fuerte', 'USD') + $sum('morrallero_cf', 'USD');

        $total_fisico_mxn =
            $sum('cajon', 'MXN') + $sum('morrallero', 'MXN')
            + $sum('caja_fuerte', 'MXN') + $sum('morrallero_cf', 'MXN');

        $total_vales_dolares = 0.0;
        $total_vales_mxn     = 0.0;
        foreach ($vales as $v) {
            $total_vales_dolares += (float) ($v['dolares'] ?? 0);
            $total_vales_mxn     += (float) ($v['mxn'] ?? 0);
        }
        $gran_total_vales_mxn = $total_vales_dolares + $total_vales_mxn;

        $costo_promedio = (float) $go['costo_promedio'];

        $total_arqueo_mxn = $total_fisico_mxn + $total_vales_mxn;
        $total_en_sistema = ((float) $go['go_exchange_dolares'] * $costo_promedio) + (float) $go['go_exchange_mxn'];

        $diferencia_dolares = $total_fisico_dolares - (float) $go['go_exchange_dolares'];
        $diferencia_mxn     = $total_arqueo_mxn - (float) $go['go_exchange_mxn'];
        $resultado_final    = $diferencia_mxn + ($diferencia_dolares * $costo_promedio);

        return [
            'go_exchange_dolares'  => (float) $go['go_exchange_dolares'],
            'go_exchange_mxn'      => (float) $go['go_exchange_mxn'],
            'costo_promedio'       => $costo_promedio,
            'total_fisico_dolares' => $total_fisico_dolares,
            'total_fisico_mxn'     => $total_fisico_mxn,
            'total_arqueo_mxn'     => $total_arqueo_mxn,
            'total_en_sistema'     => $total_en_sistema,
            'total_vales_dolares'  => $total_vales_dolares,
            'total_vales_mxn'      => $total_vales_mxn,
            'gran_total_vales_mxn' => $gran_total_vales_mxn,
            'diferencia_dolares'   => $diferencia_dolares,
            'diferencia_mxn'       => $diferencia_mxn,
            'resultado_final'      => $resultado_final,
        ];
    }

    /** Inicia transacción sobre la conexión compartida del Singleton. */
    private function sql_begin(): void
    {
        $this->sesionesModel->sql->beginTransaction();
    }
}
