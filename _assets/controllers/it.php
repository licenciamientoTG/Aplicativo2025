<?php
class It{
    private $token;
    private $baseApiUrl = 'https://api.bederr.com/v6/';

    public $twig;
    public $route;
    public UsuariosModel $usersModel;
    public PermissionsModel $permissionsModel;
    public PermissionsUsersModel $permissionsUsersModel;
    public GasolinerasModel $gasolinerasModel;
    public DespachosModel $despachosModel;
    public PerfilModel $profileModel;
    public DepartamentosModel $departamentosModel;
    public EstacionesModel $estacionesModel;
    public DespachosLealtadModel $despachosLealtadModel;
    public BinnacleActivitiesModel $binnacleActivitiesModel;
    public PageVisitsModel $pageVisitsModel;

    /**
     * @param $twig
     */
    public function __construct($twig) {
//        $this->token                   = $this->getToken('uf4WEhBJqHc7AnsGGhen84FjuAj6CHHnJPSPEpFz', '6d7ckmS4zLa9yrdtCJhvZtkpSJhlgOst5G8wYXZqAEbiU2spS0iJR7f3xQi8b5JtlWdYYGJfXCujpA0526KZ0TU2XPXsIFswbocPOzz0xQtYH7JiDp3sSc8bwYtvzf9x');
        $this->twig                    = $twig;
        $this->route                   = 'views/it/';
        $this->usersModel              = new UsuariosModel;
        $this->permissionsModel        = new PermissionsModel;
        $this->permissionsUsersModel   = new PermissionsUsersModel;
        $this->gasolinerasModel        = new GasolinerasModel;
        $this->estacionesModel         = new EstacionesModel;
        $this->despachosModel          = new DespachosModel;
        $this->profileModel            = new PerfilModel;
        $this->departamentosModel      = new DepartamentosModel;
        $this->despachosLealtadModel   = new DespachosLealtadModel();
        $this->binnacleActivitiesModel = new BinnacleActivitiesModel();
        $this->pageVisitsModel = new PageVisitsModel;
    }

    /**
     * @return void
     */
    public function date_to_int() :void {
        echo $this->twig->render($this->route . 'date_to_int.html');
    }

    /**
     * @return void
     */
    public function hello_world(): void {
        header('Location: /it/retardos');
        exit;
    }

    /* ------------------------------------------------------------------ */
    /*  RETARDOS                                                            */
    /* ------------------------------------------------------------------ */

    public function retardos(): void {
        if (!in_array((int)$_SESSION['tg_user']['Id'], SistemasTachasModel::ALLOWED_USERS)) {
            (new Errors())->get404();
            return;
        }
        $today = date('Y-m-d');
        $data  = $this->_build_periodo_data('semana', $today);
        $model = new SistemasTachasModel();

        // Validar si ya pasó la hora límite para reclamar servicio (9:30 AM Ciudad Juárez)
        $tz = new DateTimeZone('America/Chihuahua');
        $now = new DateTime('now', $tz);
        $limite = clone $now;
        $limite->setTime(9, 30, 0);
        $puede_reclamar = $now <= $limite;

        echo $this->twig->render($this->route . 'retardos.html', [
            'initial_data'      => json_encode($data),
            'today'             => $today,
            'can_edit'          => in_array((int)$_SESSION['tg_user']['Id'], SistemasTachasModel::EDITORS),
            'servicio_hoy'      => $model->get_servicio_hoy(),
            'puede_reclamar'    => $puede_reclamar,
            'tachas_pendientes' => $model->get_tachas_pendientes(),
        ]);
    }

    public function retardos_data(): void {
        header('Content-Type: application/json');
        if (!in_array((int)$_SESSION['tg_user']['Id'], SistemasTachasModel::ALLOWED_USERS)) {
            echo json_encode(['success' => false, 'message' => 'Sin permisos']);
            return;
        }
        $vista = in_array($_GET['vista'] ?? '', ['semana', 'mes']) ? $_GET['vista'] : 'semana';
        $fecha = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['fecha'] ?? '') ? $_GET['fecha'] : date('Y-m-d');
        echo json_encode($this->_build_periodo_data($vista, $fecha));
    }

    public function retardos_add(): void {
        header('Content-Type: application/json');
        if (!in_array((int)$_SESSION['tg_user']['Id'], SistemasTachasModel::EDITORS)) {
            echo json_encode(['success' => false, 'message' => 'Sin permisos']); return;
        }
        $uid   = (int)($_POST['usuario_id'] ?? 0);
        $fecha = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['fecha'] ?? '') ? $_POST['fecha'] : '';
        if (!$uid || !$fecha) { echo json_encode(['success' => false, 'message' => 'Datos incompletos']); return; }
        $model = new SistemasTachasModel();
        echo json_encode($model->add_tacha($uid, $fecha, (int)$_SESSION['tg_user']['Id']));
    }

    public function retardos_remove(): void {
        header('Content-Type: application/json');
        if (!in_array((int)$_SESSION['tg_user']['Id'], SistemasTachasModel::EDITORS)) {
            echo json_encode(['success' => false, 'message' => 'Sin permisos']); return;
        }
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['success' => false, 'message' => 'ID inválido']); return; }
        $model = new SistemasTachasModel();
        echo json_encode($model->remove_tacha($id, (int)$_SESSION['tg_user']['Id']));
    }

    public function retardos_metodo(): void {
        header('Content-Type: application/json');
        if (!in_array((int)$_SESSION['tg_user']['Id'], SistemasTachasModel::EDITORS)) {
            echo json_encode(['success' => false, 'message' => 'Sin permisos']); return;
        }
        $id     = (int)($_POST['id'] ?? 0);
        $metodo = $_POST['metodo'] ?? '';
        if (!$id || !$metodo) { echo json_encode(['success' => false, 'message' => 'Datos incompletos']); return; }
        $model = new SistemasTachasModel();
        echo json_encode($model->set_metodo($id, $metodo));
    }

    public function retardos_validar(): void {
        header('Content-Type: application/json');
        if (!in_array((int)$_SESSION['tg_user']['Id'], SistemasTachasModel::EDITORS)) {
            echo json_encode(['success' => false, 'message' => 'Sin permisos']); return;
        }
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['success' => false, 'message' => 'ID inválido']); return; }
        $model = new SistemasTachasModel();
        echo json_encode($model->validar_pago($id, (int)$_SESSION['tg_user']['Id']));
    }

    public function retardos_programar_ho(): void {
        header('Content-Type: application/json');
        if (!in_array((int)$_SESSION['tg_user']['Id'], SistemasTachasModel::EDITORS)) {
            echo json_encode(['success' => false, 'message' => 'Sin permisos']); return;
        }
        $uid   = (int)($_POST['usuario_id'] ?? 0);
        $fecha = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['fecha'] ?? '') ? $_POST['fecha'] : '';
        if (!$uid || !$fecha) { echo json_encode(['success' => false, 'message' => 'Datos incompletos']); return; }
        $model = new SistemasTachasModel();
        echo json_encode($model->programar_ho($uid, $fecha, (int)$_SESSION['tg_user']['Id']));
    }

    public function retardos_cancelar_ho(): void {
        header('Content-Type: application/json');
        if (!in_array((int)$_SESSION['tg_user']['Id'], SistemasTachasModel::EDITORS)) {
            echo json_encode(['success' => false, 'message' => 'Sin permisos']); return;
        }
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['success' => false, 'message' => 'ID inválido']); return; }
        $model = new SistemasTachasModel();
        echo json_encode($model->cancelar_ho($id, (int)$_SESSION['tg_user']['Id']));
    }

    public function retardos_ajuste_deuda(): void {
        header('Content-Type: application/json');
        if (!in_array((int)$_SESSION['tg_user']['Id'], SistemasTachasModel::EDITORS)) {
            echo json_encode(['success' => false, 'message' => 'Sin permisos']); return;
        }
        $uid   = (int)($_POST['usuario_id'] ?? 0);
        $monto = (int)($_POST['monto']      ?? 0);
        $desc  = trim($_POST['descripcion'] ?? '');
        if (!$uid || $monto === 0) {
            echo json_encode(['success' => false, 'message' => 'Datos incompletos']); return;
        }
        $model = new SistemasTachasModel();
        echo json_encode($model->add_ajuste($uid, $monto, $desc, (int)$_SESSION['tg_user']['Id']));
    }

    public function retardos_pagar_efectivo(): void {
        header('Content-Type: application/json');
        if (!in_array((int)$_SESSION['tg_user']['Id'], SistemasTachasModel::EDITORS)) {
            echo json_encode(['success' => false, 'message' => 'Sin permisos']); return;
        }
        $model = new SistemasTachasModel();
        echo json_encode($model->pagar_efectivo((int)$_SESSION['tg_user']['Id']));
    }

    public function retardos_reclamar_servicio(): void {
        header('Content-Type: application/json');
        if (!in_array((int)$_SESSION['tg_user']['Id'], SistemasTachasModel::ALLOWED_USERS)) {
            echo json_encode(['success' => false, 'message' => 'Sin permisos']); return;
        }
        $model = new SistemasTachasModel();
        echo json_encode($model->reclamar_servicio((int)$_SESSION['tg_user']['Id']));
    }

    public function retardos_pendientes(): void {
        header('Content-Type: application/json');
        if (!in_array((int)$_SESSION['tg_user']['Id'], SistemasTachasModel::ALLOWED_USERS)) {
            echo json_encode(['success' => false]); return;
        }
        $model = new SistemasTachasModel();
        echo json_encode([
            'success'  => true,
            'tachas'   => $model->get_tachas_pendientes(),
            'servicio' => $model->get_servicio_hoy(),
        ]);
    }

    public function retardos_preview_informe(): void {
        header('Content-Type: application/json');
        if (!in_array((int)$_SESSION['tg_user']['Id'], SistemasTachasModel::ALLOWED_USERS)) {
            echo json_encode(['success' => false, 'message' => 'Sin permisos']); return;
        }
        
        // Debug: registrar información sobre las deudas
        $model = new SistemasTachasModel();
        $stats = $model->build_stats();
        error_log('Stats de usuarios: ' . print_r($stats, true));
        
        echo json_encode(['success' => true, 'mensaje' => $this->_build_informe()]);
    }

    public function retardos_enviar_informe(): void {
        header('Content-Type: application/json');
        if (!in_array((int)$_SESSION['tg_user']['Id'], SistemasTachasModel::EDITORS)) {
            echo json_encode(['success' => false, 'message' => 'Sin permisos']); return;
        }

        $mensaje = $this->_build_informe();
        $phone   = '5216566743249';
        $apikey  = '1546951';

        // Codificar el mensaje para URL
        $texto_codificado = rawurlencode($mensaje);
        $url = 'https://api.callmebot.com/whatsapp.php?phone=' . $phone
             . '&text=' . $texto_codificado
             . '&apikey=' . $apikey;

        $ctx      = stream_context_create(['http' => ['timeout' => 15]]);
        $response = @file_get_contents($url, false, $ctx);

        if ($response === false) {
            echo json_encode(['success' => false, 'message' => 'No se pudo conectar con CallMeBot. Verifica la conexión a internet.']);
            return;
        }

        // Verificar si la respuesta contiene un error
        if (stripos($response, 'error') !== false || stripos($response, '/system/bin/sh') !== false) {
            error_log('CallMeBot error response: ' . $response);
            echo json_encode(['success' => false, 'message' => 'Error en la API de WhatsApp. Intente de nuevo.']);
            return;
        }

        echo json_encode(['success' => true, 'message' => 'Informe enviado por WhatsApp ✓']);
    }

    private function _build_informe(): string {
        $data   = $this->_build_periodo_data('semana', date('Y-m-d'));
        $meses  = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];

        $d0   = new DateTime($data['inicio']);
        $d1   = new DateTime($data['fin']);
        $mes0 = $meses[(int)$d0->format('n') - 1];
        $mes1 = $meses[(int)$d1->format('n') - 1];

        $periodo = ($mes0 === $mes1)
            ? $d0->format('j') . ' al ' . $d1->format('j') . ' ' . $mes0 . ' ' . $d0->format('Y')
            : $d0->format('j') . ' ' . $mes0 . ' al ' . $d1->format('j') . ' ' . $mes1 . ' ' . $d0->format('Y');

        $msg  = "Informe de Retardos\n";
        $msg .= "Semana del " . $periodo . "\n";
        $msg .= str_repeat('-', 30) . "\n\n";

        $total_deuda = 0;

        foreach ($data['usuarios'] as $u) {
            $tachas_semana = count(array_filter($u['tachas']));
            $deuda = (int)($u['deuda'] ?? 0);
            $ho = $u['ho'];
            $total_deuda += $deuda;

            $ho_str = $ho['earned'] ? 'Ganado!' : $ho['remaining'] . ' dias para HO';
            $deuda_str = $deuda > 0 ? (string)$deuda : '-';

            $nombre_parts = explode(' ', trim($u['nombre']));
            $nombre = implode(' ', array_slice($nombre_parts, 0, 2));

            $msg .= $nombre . "\n";
            $msg .= "  Tachas: " . $tachas_semana . "\n";
            $msg .= "  Deuda: " . $deuda_str . "\n";
            $msg .= "  HO: " . $ho_str . "\n\n";
        }

        $msg .= str_repeat('-', 30) . "\n";
        $msg .= "Deuda total: " . ($total_deuda > 0 ? (string)$total_deuda : '0');

        return $msg;
    }

    private function _build_periodo_data(string $vista, string $fecha_ref): array {
        $model = new SistemasTachasModel();

        if ($vista === 'semana') {
            $dow    = (int)date('N', strtotime($fecha_ref));
            $inicio = date('Y-m-d', strtotime('-' . ($dow - 1) . ' days', strtotime($fecha_ref)));
            $fin    = date('Y-m-d', strtotime('+4 days', strtotime($inicio)));
        } else {
            $inicio = date('Y-m-01', strtotime($fecha_ref));
            $fin    = date('Y-m-t',  strtotime($fecha_ref));
        }

        $tachas_raw   = $model->get_tachas($inicio, $fin);
        $usuarios_raw = $model->get_usuarios();
        $stats        = $model->build_stats();

        // Indexar tachas por [usuario_id][fecha]
        $tachas_idx = [];
        foreach ($tachas_raw as $t) {
            $tachas_idx[(int)$t['usuario_id']][$t['fecha']] = $t;
        }

        // Días hábiles del período
        $dias = [];
        $cur  = strtotime($inicio);
        $end_ts = strtotime($fin);
        while ($cur <= $end_ts) {
            if ((int)date('N', $cur) < 6) $dias[] = date('Y-m-d', $cur);
            $cur = strtotime('+1 day', $cur);
        }

        // Construir usuarios con sus tachas
        $usuarios = [];
        foreach ($usuarios_raw as $u) {
            $uid = (int)$u['Id'];
            $user_tachas = [];
            foreach ($dias as $d) {
                $user_tachas[$d] = $tachas_idx[$uid][$d] ?? null;
            }
            $usuarios[] = [
                'id'          => $uid,
                'nombre'      => $u['Nombre'],
                'tachas'      => $user_tachas,
                'ho'          => $stats[$uid]['home_office']  ?? ['remaining' => 20, 'earned' => false, 'elapsed' => 0],
                'deuda'       => $stats[$uid]['deuda']        ?? 0,
                'ho_pendiente'=> $stats[$uid]['ho_pendiente'] ?? null,
            ];
        }

        // Para vista mes: agrupar días por semana (lunes a viernes)
        $semanas = [];
        if ($vista === 'mes') {
            $week_buf = [];
            foreach ($dias as $d) {
                if ((int)date('N', strtotime($d)) === 1 && !empty($week_buf)) {
                    $semanas[] = $week_buf;
                    $week_buf  = [];
                }
                $week_buf[] = $d;
            }
            if (!empty($week_buf)) $semanas[] = $week_buf;
        }

        return [
            'vista'    => $vista,
            'inicio'   => $inicio,
            'fin'      => $fin,
            'dias'     => $dias,
            'semanas'  => $semanas,
            'usuarios' => $usuarios,
        ];
    }

    /**
     * @return void
     */
    public function users() : void {
        echo $this->twig->render($this->route . 'users.html');
    }

    /**
     * @return void
     */
    public function datatables_users() : void {
        $data = [];

        foreach ($this->usersModel->get_users() as $user) {

            $actions = "
            <div class='dropdown'>
            <button class='btn btn-primary btn-sm dropdown-toggle' type='button' data-bs-toggle='dropdown' aria-expanded='false'>
                Acciones
            </button>
            <ul class='dropdown-menu' data-container='body'>
                <li><a class='dropdown-item' href='javascript:void(0);' data-bs-toggle='modal' data-bs-target='#changePasswordModal' data-id='{$user['Id']}'>Cambiar contraseña</a></li>
                <li><a class='dropdown-item' href='javascript:void(0);' data-bs-toggle='modal' data-bs-target='#editUserModal' data-id='{$user['Id']}'>Editar</a></li>
                <li><a class='dropdown-item' href='/it/permission_users/{$user['Id']}'>Permisos</a></li>
            </ul>
            </div>
            ";
            $data[] = array(
                'ID'       => $user['Id'],
                'USUARIO'  => $user['Usuario'],
                'NOMBRE'   => $user['Nombre'],
                'STATUS'   => $user['Estatus'],
                'PERFIL'   => $user['Perfil'],
                'CORREO'   => $user['Correo'],
                'ESTACION' => (($user['Estacion'] == '' OR is_null($user['Estacion'])) ? '--' : $user['Estacion'] ),
                'FECHA'    => $user['FechaRegistro'],
                'PERMISOS' => $user['Permissions'],
                'ACCIONES' => $actions
            );
        }
        json_output(array("data" => $data));
    }

    /**
     * @param $user_id
     * @return void
     */
    public function permission_users($user_id) : void{
        echo $this->twig->render($this->route . 'permission_users.html', compact('user_id'));
    }

    /**
     * @return void
     * @throws Exception
     */
    public function datatables_permissions_users() : void {
        $data = [];
        foreach ($this->permissionsUsersModel->get_permissions_users($_GET['user_id']) as $permission) {
            $data[] = array(
                'ID'           => $permission['permission_id'],
                'CLASE'        => $permission['Accion'],
                'DEPARTAMENTO' => $permission['Departamento'],
                'DESCRIPCION'  => $permission['Descripcion'],
                'STATUS'       => $permission['Status'],
                'FECHA'        => $permission['Fecha'],
                'ACCIONES'     => '<div class="form-check form-switch fs-5">
                                        <input class="form-check-input" type="checkbox" role="switch" id="btncheck'. $permission['permission_id'] .'" onChange="assignPermission(this)" data-permission="'. $permission['permission_id'] .'" data-user="'. (int)$_GET['user_id'] .'" '. ($permission['Permitido'] == 0 ? '' : 'checked' ) .'>
                                    </div>'
            );
        }
        json_output(array("data" => $data));
    }

    /**
     * @return json
     * @throws Exception
     */
    public function assignPermission() {
        $check = in_array($_GET['check'] ?? '', ['0', '1']) ? (int)$_GET['check'] : 0;
        return json_output($this->permissionsUsersModel->assignPermission((int)$_GET['user_id'], (int)$_GET['permission_id'], $check));
    }

    /**
     * @return void
     */
    public function permissions() : void {
        if (preg_match('/GET/i',$_SERVER['REQUEST_METHOD'])){
            echo $this->twig->render($this->route . 'permissions.html');
        } else {


            if ($this->permissionsModel->add($_POST['action'],$_POST['department'],$_POST['description'],$_POST['status'])) {
                setFlashMessage('success', 'Permiso agregado correctamente');
            } else {
                setFlashMessage('error', 'El permiso no pudo ser agregado');
            }
            redirect();
        }
    }

    /**
     * @return void
     * @throws Exception
     */
    public function datatables_permissions() : void {
        $data = [];
        foreach ($this->permissionsModel->get_permissions() as $permission) {
            $actions = "
            <div class='dropdown'>
            <button class='btn btn-primary btn-sm dropdown-toggle' type='button' data-bs-toggle='dropdown' aria-expanded='false'>
                Acciones
            </button>
            <ul class='dropdown-menu'>
                <li><a class='dropdown-item' href='#'>Deshabilitar</a></li>
                <li><a class='dropdown-item' href='#'>Editar</a></li>
            </ul>
            </div>
            ";
            $data[] = array(
                'ID'           => $permission['permission_id'],
                'CLASE'        => $permission['Accion'],
                'DEPARTAMENTO' => $permission['Departamento'],
                'DESCRIPCION'  => $permission['Descripcion'],
                'STATUS'       => $permission['Status'],
                'FECHA'        => $permission['Fecha'],
                'ACCIONES'     => $actions
            );
        }
        json_output(array("data" => $data));
    }

    /**
     * @return void
     */
    public function permissionModal() : void {
        $modal = [
            "title"    => "Permisos",
            "size"     => "modal-sm",
            "position" => "modal-dialog-centered",
            "content"  => $this->twig->render($this->route . 'modals/permissionModal.html')
        ];
        json_output($modal);
    }

    /**
     * @return void
     */
    public function stationModal() : void {
        $modal = [
            "title"    => "Estaciones",
            "size"     => "modal-sm",
            "position" => "modal-dialog-centered",
            "content"  => $this->twig->render($this->route . 'modals/stationModal.html')
        ];
        json_output($modal);
    }

    /**
     * @return void
     * @throws Exception
     */
    public function release_dispatches() : void {
        if (preg_match('/GET/i',$_SERVER['REQUEST_METHOD'])){
            $nrotrn = $_GET['nrotrn'] ?? false;
            $codgas = $_GET['codgas'] ?? false;
            $stations = $this->gasolinerasModel->get_stations();
            echo $this->twig->render($this->route . 'release_dispatches.html', compact('stations', 'nrotrn', 'codgas'));
        } else {
            if ($this->despachosModel->release_dispatches(dateToInt($_POST['from']), dateToInt($_POST['until']), $_POST['codgas'])) {
                json_output(["status" => "OK", "message" => "¡Los despachos fueron liberados correctamente!"]);
            } else {
                json_output(["status" => "ERROR", "message" => "¡Los despachos no pudieron ser liberados!"]);
            }
        }
    }

    public function release_dispatch() : void {
        if (!isset($_POST['nrotrn'], $_POST['codgas'])) {
            json_output(["status" => "ERROR", "message" => "¡No se recibieron los datos necesarios!"]);
            return;
        }

        if ($this->despachosModel->release_dispatch($_POST['nrotrn'], $_POST['codgas'], 1)) {
            json_output(["status" => "OK", "message" => "¡El despacho fue liberado correctamente!"]);
        } else {
            json_output(["status" => "ERROR", "message" => "¡El despacho no pudo ser liberado!"]);
        }
    }

    /**
     * @return void
     * @throws Exception
     */
    public function datatables_release_dispatches() : void {
        $data = [];
        if ($despachos = $this->despachosModel->get_to_release($_GET['nrotrn'], $_GET['codgas'])) {
            foreach ($despachos as $despacho) {
                $data[] = array(
                    'DESPACHO'  => $despacho['nrotrn'],
                    'FDESPACHO' => intToDate($despacho['fchtrn']),
                    'LITROS'    => $despacho['can'],
                    'MONTO'     => $despacho['mto'],
                    'FACTURA'   => $despacho['nrofac'],
                    'FACTEST'   => $despacho['station'],
                    'UUID'      => $despacho['satuid'],
                    'CLIENTE'   => (int)$despacho['codcli'] > 0 ? trim($despacho['codcli']) . ' - ' . trim($despacho['cliente'] ?? '') : '',
                    'RFC'       => $despacho['satrfc'],
                    'LOGFECHA'  => $despacho['logfch'],
                    'ACCIONES'  => '<a href="javascript:void(0);" onclick="release_dispatch('. (int)$_GET['nrotrn'] .', '. (int)$_GET['codgas'] .')" data-bs-toggle="tooltip" data-bs-placement="top" title="Liberación de despacho"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-skip-back align-middle me-2"><polygon points="19 20 9 12 19 4 19 20"></polygon><line x1="5" y1="19" x2="5" y2="5"></line></svg></a>'
                );
            }
        }
        json_output(array("data" => $data));
    }

    /**
     * @return void
     * @throws Exception
     */
    public function mark_jarreo() : void {
        $fecha = $_GET['fecha'] ?? false;
        $codgas = $_GET['codgas'] ?? false;
        $stations = $this->gasolinerasModel->get_stations();
        echo $this->twig->render($this->route . 'mark_jarreo.html', compact('stations', 'fecha', 'codgas'));
    }

    /**
     * @return void
     * @throws Exception
     */
    public function datatables_mark_jarreo() : void {
        $data = [];
        if ($despachos = $this->despachosModel->get_dispatches_to_mark_jarreo($_GET['codgas'], dateToInt($_GET['fecha']))) {
            foreach ($despachos as $despacho) {
                $data[] = array(
                    'SELECT'    => '<input type="checkbox" class="jarreo-checkbox" value="' . (int)$despacho['nrotrn'] . '" data-tiptrn="' . (int)$despacho['tiptrn'] . '">',
                    'DESPACHO'  => $despacho['nrotrn'],
                    'TIPO'      => match(true) {
                        in_array((int)$despacho['tiptrn'], [65, 74], true) => 'Jarreo',
                        (int)$despacho['tiptrn'] === 0  => 'Efectivo',
                        (int)$despacho['tiptrn'] === 49 => 'Efectivo',
                        (int)$despacho['tiptrn'] === 50 => 'Cheque',
                        (int)$despacho['tiptrn'] === 51 => 'Tarjeta de Crédito',
                        (int)$despacho['tiptrn'] === 52 => 'Tarjeta de Débito',
                        (int)$despacho['tiptrn'] === 53 => 'Efectivale / Monedero',
                        default => 'Otro (' . $despacho['tiptrn'] . ')',
                    },
                    'FDESPACHO' => intToDate($despacho['fchtrn']),
                    'POSICION'  => $despacho['nrobom'],
                    'LITROS'    => $despacho['can'],
                    'MONTO'     => $despacho['mto'],
                    'FACTURA'   => $despacho['nrofac'],
                    'FACTEST'   => $despacho['station'],
                );
            }
        }
        json_output(array("data" => $data));
    }

    /**
     * @return void
     * @throws Exception
     */
    public function mark_jarreo_dispatches() : void {
        if (!isset($_POST['codgas'], $_POST['nrotrn']) || !is_array($_POST['nrotrn']) || empty($_POST['nrotrn'])) {
            json_output(["status" => "ERROR", "message" => "¡No se recibieron los datos necesarios!"]);
            return;
        }

        $marcados = 0;
        foreach ($_POST['nrotrn'] as $nrotrn) {
            if ($this->despachosModel->mark_dispatch_as_jarreo($nrotrn, $_POST['codgas'])) {
                $marcados++;
            }
        }

        if ($marcados > 0) {
            json_output(["status" => "OK", "message" => "¡Se marcaron {$marcados} despacho(s) como jarreo correctamente!"]);
        } else {
            json_output(["status" => "ERROR", "message" => "¡Los despachos no pudieron ser marcados como jarreo!"]);
        }
    }

    /**
     * @return void
     * @throws Exception
     */
    public function unmark_jarreo_dispatches() : void {
        if (!isset($_POST['codgas'], $_POST['nrotrn']) || !is_array($_POST['nrotrn']) || empty($_POST['nrotrn'])) {
            json_output(["status" => "ERROR", "message" => "¡No se recibieron los datos necesarios!"]);
            return;
        }

        $desmarcados = 0;
        foreach ($_POST['nrotrn'] as $nrotrn) {
            if ($this->despachosModel->unmark_dispatch_as_jarreo($nrotrn, $_POST['codgas'])) {
                $desmarcados++;
            }
        }

        if ($desmarcados > 0) {
            json_output(["status" => "OK", "message" => "¡Se desmarcaron {$desmarcados} despacho(s) de jarreo y se pasaron a contado correctamente!"]);
        } else {
            json_output(["status" => "ERROR", "message" => "¡Los despachos no pudieron ser desmarcados de jarreo!"]);
        }
    }

    /**
     * @return void
     * @throws Exception
     */
    public function userModal() : void {
        $profiles = $this->profileModel->all();
        $modal = [
            "title"    => "Agregar usuario",
            "size"     => "modal-sm",
            "position" => "modal-dialog-centered",
            "content"  => $this->twig->render($this->route . 'modals/userModal.html', compact('profiles'))
        ];
        json_output($modal);
    }

    /**
     * @param $id
     * @return void
     * @throws Exception
     */
    public function editUserModal($id) : void {
        $user = $this->usersModel->get_user($id);
        $profiles = $this->profileModel->all();
        $departments = $this->departamentosModel->all();
        $stations = $this->estacionesModel->get_stations();
        $modal = [
            "title"    => "Editar usuario",
            "size"     => "modal-sm",
            "position" => "modal-dialog-centered",
            "content"  => $this->twig->render($this->route . 'modals/editUserModal.html', compact('profiles', 'departments', 'user', 'stations'))
        ];
        json_output($modal);
    }

    /**
     * @return void
     * @throws Exception
     */
    public function changePasswordModal() :void {
        $id = $_GET['id'] ?? $_POST['id'] ?? null;
        $user = $this->usersModel->get_user($id);
        if (preg_match('/GET/i',$_SERVER['REQUEST_METHOD'])){
            $modal = [
                "title"    => "Cambiar contraseña",
                "size"     => "modal-sm",
                "position" => "modal-dialog-centered",
                "content"  => $this->twig->render($this->route . 'modals/changePasswordModal.html', compact('user'))
            ];
            json_output($modal);
        } else {
            if ($this->usersModel->changePassword($user['Id'], $_POST['password1'])) {
                json_output(1);
            } else {
                json_output(0);
            }
        }
    }

    /**
     * @return json|int
     * @throws Exception
     */
    public function userForm() {
        if (preg_match('/POST/i',$_SERVER['REQUEST_METHOD'])){
            return json_output($this->usersModel->add(trim($_POST['name']), trim($_POST['username']), trim($_POST['password']), $_POST['profile_id'], trim(strtolower($_POST['email']))));
        }

        return 0;
    }

    /**
     * @return null
     * @throws Exception
     */
    public function editUserForm() {
        $rs = $this->usersModel->editUser(trim($_POST['name']), $_POST['profile_id'], trim(strtolower($_POST['email'])), $_POST['IdEstacion'], $_POST['status'], $_POST['id'], $_POST['department_id'] ?? null);
        return json_output(($rs ? 1 : 0));
    }

    /**
     * @return void
     * @throws Exception
     */
    public function stations() : void {
        if (preg_match('/GET/i',$_SERVER['REQUEST_METHOD'])){
            echo $this->twig->render($this->route . 'stations.html');
        } else {
            if ($this->estacionesModel->add($_POST)) {
                setFlashMessage('success', 'Estación agregada correctamente');
            } else {
                setFlashMessage('error', 'La estación no pudo ser agregada');
            }
            redirect();
        }
    }

    /**
     * @return void
     * @throws Exception
     */
    public function datatables_stations() : void {
        $data = [];
        if ($stations = $this->estacionesModel->get_stations('0,4,20')) {
            $data = array_map(function ($station) {
                return [
                    'ID'           => $station['Codigo'],
                    'NOMBRE'       => preg_replace('/^[0-9]+/', '', $station['Nombre']),
                    'DOMICILIO'    => $station['Domicilio'],
                    'ESTACIÓN'     => $station['Estacion'],
                    'SERVIDOR'     => $station['Servidor'],
                    'BD'           => $station['BaseDatos'],
                    'CRE'          => $station['PermisoCRE'],
                    'DENOMINACIÓN' => $station['Denominacion'],
                    'ZONA'         => $station['Zona'],
                    'RFC'          => $station['RFC'],
                    'STATUS'       => ( $station['activa'] == 1 ? '<span class="badge bg-success">Activa</span>' : '<span class="badge bg-primary">Inactiva</span>' ),
                    'CONEXION'     => (function() use ($station) { $s = @fsockopen($station['Servidor'], 1433, $errno, $errstr, 2); if ($s !== false) { fclose($s); return "✅"; } return "❌"; })()
                ];
            }, $stations);
        }
        json_output(array("data" => $data));
    }

    /**
     * @return void
     * @throws Exception
     */
    public function profile() : void {
        $permissions = $this->permissionsUsersModel->get_permissions_users($_SESSION['tg_user']['Id']);
        echo $this->twig->render($this->route . 'profile.html', compact('permissions'));
    }

    /**
     * @return void
     * @throws Exception
     */
    public function change_password() : void {
        if ($this->usersModel->changePassword($_SESSION['tg_user']['Id'], $_POST['password1'])) {
            json_output(1);
        } else {
            json_output(0);
        }
    }

    /**
     * @return void
     */
    public function binnacle() : void {
        echo $this->twig->render($this->route . 'binnacle.html');
    }

    /**
     * @return void
     */
    public function modalActivities() : void {
        $date_modal = $_POST['date'];
        $modal = [
            "title"    => "Agregar actividad en bitácora",
            "size"     => "modal-sm",
            "position" => "modal-dialog-centered",
            "content"  => $this->twig->render($this->route . 'modals/modalActivities.html', compact('date_modal'))
        ];
        json_output($modal);
    }

    /**
     * @return void
     * @throws Exception
     */
    public function add_activity() : void {
        $activity_date = $_POST['activity_date'];
        $start_hour    = $_POST['start_hour'];
        $end_hour      = $_POST['end_hour'];
        $title         = $_POST['title'];
        $description   = $_POST['description'];
        $user_id       = $_SESSION['tg_user']['Id'];

        // Validamos si los campos estan vacios
        if (empty($activity_date) OR empty($start_hour) OR empty($end_hour) OR empty($title) OR empty($description)) {
            setFlashMessage('error', 'Todos los campos son obligatorios');
            redirect('/it/binnacle');
        }

        // Validamos que la hora de inicio no sea mayor a la hora de fin
        if (strtotime($start_hour) > strtotime($end_hour)) {
            setFlashMessage('error', 'La hora de inicio no puede ser mayor a la hora de fin');
            redirect('/it/binnacle');
        }

        // Validamos que la fecha de la actividad no sea mayor a la fecha actual
        if (strtotime($activity_date) > strtotime(date('Y-m-d'))) {
            setFlashMessage('error', 'La fecha de la actividad no puede ser mayor a la fecha actual');
            redirect('/it/binnacle');
        }
        
        if ($this->binnacleActivitiesModel->addActivity($user_id, $activity_date, $start_hour, $end_hour, $title, $description)) {
            setFlashMessage('success', 'Actividad agregada correctamente');
            redirect('/it/binnacle');
        } else {
            setFlashMessage('error', 'No se pudo agregar la actividad');
            redirect('/it/binnacle');
        }
    }

    /**
     * @return void
     * @throws Exception
     */
    public function edit_activity() : void {
        $activity_date = $_POST['activity_date'];
        $start_hour    = $_POST['start_hour'];
        $end_hour      = $_POST['end_hour'];
        $title         = $_POST['title'];
        $description   = $_POST['description'];
        $user_id       = $_SESSION['tg_user']['Id'];
        $activity_id   = $_POST['id'];

        // Validamos si los campos estan vacios
        if (empty($activity_date) OR empty($start_hour) OR empty($end_hour) OR empty($title) OR empty($description)) {
            setFlashMessage('error', 'Todos los campos son obligatorios');
            redirect('/it/binnacle');
        }

        // Validamos que la hora de inicio no sea mayor a la hora de fin
        if (strtotime($start_hour) > strtotime($end_hour)) {
            setFlashMessage('error', 'La hora de inicio no puede ser mayor a la hora de fin');
            redirect('/it/binnacle');
        }

        // Validamos que la fecha de la actividad no sea mayor a la fecha actual
        if (strtotime($activity_date) > strtotime(date('Y-m-d'))) {
            setFlashMessage('error', 'La fecha de la actividad no puede ser mayor a la fecha actual');
            redirect('/it/binnacle');
        }

        if ($this->binnacleActivitiesModel->editActivity($activity_date, $start_hour, $end_hour, $title, $description, $activity_id)) {
            setFlashMessage('success', 'Actividad actualizada correctamente');
            redirect('/it/binnacle');
        } else {
            setFlashMessage('error', 'No se pudo actualizar la actividad');
            redirect('/it/binnacle');
        }
    }

    /**
     * @return void
     * @throws Exception
     */
    public function get_activities() : void {
        if (preg_match('/GET/i',$_SERVER['REQUEST_METHOD'])){
            $data = [];
            $activities = $this->binnacleActivitiesModel->getActivities();
            foreach ($activities as $activity) {
                $data[] = array(
                    'id' => $activity['id'],
                    'title' => $activity['title'],
                    'start' => date("Y-m-d", strtotime($activity['activity_date'])) . ' ' . $activity['start_hour'],
                    'end' => date("Y-m-d", strtotime($activity['activity_date'])) . ' ' . $activity['end_hour'],
                    'description' => $activity['description'],
                    'backgroundColor' => '#'.substr(md5((string)$activity['id']), 0, 6),
                    'textColor' => '#fff',
                    'activity_date' => $activity['activity_date'],
                    'user_id' => $activity['user_id'],
                    'created_at' => $activity['created_at']
                );
            }
            // retornamos la respuesta en formato JSON
            json_output($data);
        }
    }

    /**
     * @return void
     * @throws Exception
     */
    public function activityModal() : void {
        $activity = $this->binnacleActivitiesModel->getActivity($_POST['activity_id']);
        $modal = [
            "title"    => "Detalles de la actividad",
            "size"     => "modal-sm",
            "position" => "modal-dialog-centered",
            "content"  => $this->twig->render($this->route . 'modals/activityModal.html', compact('activity'))
        ];
        json_output($modal);
    }

    public function modalEditActivities() : void {
        if ($activity = $this->binnacleActivitiesModel->getActivity($_POST['activity_id'])) {
            $modal = [
                "title"    => "Editar actividad",
                "size"     => "modal-sm",
                "position" => "modal-dialog-centered",
                "content"  => $this->twig->render($this->route . 'modals/activityEditModal.html', compact('activity'))
            ];
            json_output($modal);
        } else {
            json_output(["status" => "ERROR", "message" => "No se encontró la actividad"]);
        }
    }

    /**
     * @return void
     * @throws Exception
     */
    public function activities_list() : void {
        $activities = $this->binnacleActivitiesModel->getActivities();
        echo $this->twig->render($this->route . 'activities_list.html', compact('activities'));
    }

    public function CFDISender_monitor() {
        $active_stations = $this->gasolinerasModel->get_active_station_TG();
        echo $this->twig->render($this->route . 'CFDISender_monitor.html', compact( 'active_stations'));
    }

    public function CFDISender_monitor_data($from, $codgas) {
        $data = [];
        if ($dispatches = $this->despachosModel->getCFDIs(dateToInt($from), $codgas)) {
            foreach ($dispatches as $dispatch) {
                $data[] = array(
                    'DESPACHO'   => $dispatch['nrotrn'],
                    'FECHA'      => intToDate($dispatch['fchtrn']),
                    'HORA'       => substr($dispatch['hortrn'], 0, 2) . ':' . substr($dispatch['hortrn'], 2, 2) . ':' . substr($dispatch['hortrn'], 4, 2),
                    'ESTACIÓN'   => $dispatch['station'],
                    'FACTURA'    => $dispatch['nrofac'],
                    'RFC'        => $dispatch['satrfc'],
                    'UUID'       => $dispatch['satuid'],
                    'MONTO'      => number_format($dispatch['mto'], 2),
                    'LITROS'     => number_format($dispatch['can'], 2)
                );
            }
        }
        return json_output(array("data" => $data));
    }


    public function cfdi_comparison_table()
    {
        ini_set('memory_limit', '512M');
        set_time_limit(300);
        
        $data = [];
        $from = dateToInt($_POST['from']);
        $until = dateToInt($_POST['until']);
        $codgas = isset($_POST['codgas']) ? $_POST['codgas'] : null;
        $estacion = $this->gasolinerasModel->get_estations_servidor_cod_gas($codgas);
        $bd = $estacion['base_datos'];
        $ip = $estacion['servidor'];
        if ($registros = $this->despachosModel->cfdi_comparison_advance($from, $until, $codgas, $bd, $ip)) {
            
            foreach ($registros as $registro) {
                // Determinar el badge de estado
                $estado = $registro["Estado"];
                $estadoText = '';
                
                switch ($estado) {
                    case 'match':
                        $estadoText = '<span class="status-badge status-match">✓ Coincide</span>';
                        break;
                    case 'missing':
                        $estadoText = '<span class="status-badge status-missing">✗ Faltante</span>';
                        break;
                    case 'mismatch':
                        $estadoText = '<span class="status-badge status-pending">⚠ Diferente</span>';
                        break;
                    default:
                        $estadoText = '<span class="status-badge status-pending">⏳ Pendiente</span>';
                }
                
                $data[] = array(
                    'estacion'          => $registro["Estacion"],
                    'fecha_fac'         => $registro["FechaFac"],
                    'despacho'          => $registro["Despacho"],
                    'factura_corp'      => $registro["FacturaCorp"],
                    'serie'             => $registro["Serie"],
                    'cliente'           => $registro["Cliente"],
                    'rfc'               => $registro["RFC"],
                    'uuid_corp'         => $registro["UUIDCorp"],
                    'despacho_estacion' => $registro["despacho_estacion"] ?? '<span class="text-muted">Sin Despacho</span>',
                    'factura_estacion'  => $registro["FacturaEstacion"] ?? '<span class="text-muted">N/A</span>',
                    'uuid_estacion'     => $registro["UUIDEstacion"] ?? '<span class="text-muted">Sin UUID</span>',
                    'estado'            => $estadoText,
                    'estado_raw'        => $estado  // Para filtros
                );
            }
        }
        
        json_output(array("data" => $data));
    }

    public function page_visits_dashboard(): void {
        $allowed = [6382, 6371, 6177, 6296, 6274];
        if (!in_array((int)$_SESSION['tg_user']['Id'], $allowed)) {
            (new Errors())->get404();
            return;
        }

        $to   = $_GET['to']   ?? date('Y-m-d');
        $from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));

        $top_pages    = $this->pageVisitsModel->getTopPages($from, $to);
        $top_users    = $this->pageVisitsModel->getTopUsers($from, $to);
        $pages_reach  = $this->pageVisitsModel->getPagesReach($from, $to);
        $unused_pages = $this->pageVisitsModel->getUnusedInPeriod($from, $to);
        $total_visits = (int) array_sum(array_column($top_pages, 'total_visits'));

        echo $this->twig->render($this->route . 'page_visits_dashboard.html', compact(
            'top_pages', 'top_users', 'pages_reach', 'unused_pages', 'from', 'to', 'total_visits'
        ));
    }

    public function page_visits_user(int $user_id): void {
        $allowed = [6382, 6371, 6177, 6296, 6274];
        if (!in_array((int)$_SESSION['tg_user']['Id'], $allowed)) {
            (new Errors())->get404();
            return;
        }

        $to   = $_GET['to']   ?? date('Y-m-d');
        $from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));

        $user  = $this->pageVisitsModel->getUserInfo($user_id);
        $pages = $this->pageVisitsModel->getUserPages($user_id, $from, $to);

        if (!$user) {
            (new Errors())->get404();
            return;
        }

        echo $this->twig->render($this->route . 'page_visits_user.html', compact(
            'user', 'pages', 'from', 'to'
        ));
    }

    public function controlgas_users(): void {
        if (!$this->is_controlgas_admin()) {
            (new Errors())->get404();
            return;
        }
        echo $this->twig->render($this->route . 'controlgas_users.html');
    }

    private function is_controlgas_admin(): bool {
        return isset($_SESSION['tg_user']['Id'])
            && in_array((int)$_SESSION['tg_user']['Id'], SistemasTachasModel::ALLOWED_USERS, true);
    }

    private function deny_controlgas_api(): void {
        http_response_code(403);
        json_output(['success' => false, 'message' => 'Sin permisos']);
    }

    public function disable_controlgas_user(): void {
        if (!$this->is_controlgas_admin()) {
            $this->deny_controlgas_api();
            return;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            json_output(['success' => false, 'message' => 'Método no permitido']);
            return;
        }
        $cods = $this->parse_controlgas_user_codes($_POST['cod'] ?? null);
        if ($cods === null || count($cods) !== 1) {
            json_output(['success' => false, 'message' => 'Código inválido']);
            return;
        }
        $model = new ControlgasUsersModel();
        json_output($model->disable_user($cods[0]));
    }

    public function rehabilitate_controlgas_user(): void {
        if (!$this->is_controlgas_admin()) {
            $this->deny_controlgas_api();
            return;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            json_output(['success' => false, 'message' => 'Método no permitido']);
            return;
        }

        $rawCod = $_POST['cod'] ?? null;
        if ((!is_string($rawCod) && !is_int($rawCod))
            || !preg_match('/^[0-9]+$/', (string)$rawCod)) {
            json_output(['success' => false, 'message' => 'Código inválido']);
            return;
        }
        $normalized = ltrim((string)$rawCod, '0');
        if ($normalized === '' || strlen($normalized) > strlen((string)PHP_INT_MAX)
            || (strlen($normalized) === strlen((string)PHP_INT_MAX)
                && strcmp($normalized, (string)PHP_INT_MAX) > 0)) {
            json_output(['success' => false, 'message' => 'Código inválido']);
            return;
        }

        $model = new ControlgasUsersModel();
        json_output($model->rehabilitate_user((int)$normalized));
    }

    private function parse_controlgas_user_codes($value): ?array {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $tokens = explode(',', $value);
        if (count($tokens) > 500) {
            return null;
        }

        $cods = [];
        foreach ($tokens as $token) {
            $token = trim($token);
            if ($token === '' || !preg_match('/^[0-9]+$/', $token)) {
                return null;
            }
            $normalized = ltrim($token, '0');
            if ($normalized === '') {
                return null;
            }
            $maxInt = (string)PHP_INT_MAX;
            if (strlen($normalized) > strlen($maxInt)
                || (strlen($normalized) === strlen($maxInt) && strcmp($normalized, $maxInt) > 0)) {
                return null;
            }
            $cod = (int)$normalized;
            if (!in_array($cod, $cods, true)) {
                $cods[] = $cod;
            }
        }
        return $cods ?: null;
    }

    public function preview_controlgas_users(): void {
        if (!$this->is_controlgas_admin()) {
            $this->deny_controlgas_api();
            return;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            json_output(['success' => false, 'message' => 'Método no permitido']);
            return;
        }
        $cods = $this->parse_controlgas_user_codes($_POST['cods'] ?? null);
        if ($cods === null) {
            json_output(['success' => false, 'message' => 'Ingresa una lista válida de códigos (máximo 500)']);
            return;
        }
        $model = new ControlgasUsersModel();
        $matches = $model->find_users_by_codes($cods);
        json_output(['success' => true, 'users' => $matches['users'], 'missing' => $matches['missing']]);
    }

    public function disable_controlgas_users(): void {
        if (!$this->is_controlgas_admin()) {
            $this->deny_controlgas_api();
            return;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            json_output(['success' => false, 'message' => 'Método no permitido']);
            return;
        }
        $cods = $this->parse_controlgas_user_codes($_POST['cods'] ?? null);
        if ($cods === null) {
            json_output(['success' => false, 'message' => 'Ingresa una lista válida de códigos (máximo 500)']);
            return;
        }
        $model = new ControlgasUsersModel();
        json_output($model->disable_users($cods));
    }

    /* ------------------------------------------------------------------ */
    /*  LLAMADAS SAT (peticiones de descarga masiva vía ApiTotal)           */
    /* ------------------------------------------------------------------ */

    private const SAT_USERS   = [6382, 6371, 6177, 6296, 6274];
    private const SAT_API_URL = 'http://192.168.0.3:388/api/facturas/';

    // Semáforo del panel "Estado del proceso" (días)
    private const SAT_UMBRAL_PETICION_AMARILLO = 1;
    private const SAT_UMBRAL_PETICION_ROJO     = 3;
    private const SAT_UMBRAL_ARCHIVO_ROJO      = 1;  // ZIP/XML esperando
    private const SAT_UMBRAL_IMPORT_AMARILLO   = 1;
    private const SAT_UMBRAL_IMPORT_ROJO       = 3;
    private const SAT_IMPORT_LIMITE = 100;  // XML por carpeta por lote (bajar si IIS corta por timeout)

    private const SAT_RFCS    = [
        'DGA930823KD3' => 'Dias Gas',
        'ECU0602287R6' => 'Estación Custodia',
        'GVA9709154V2' => 'Villa Ahumada',
        'DCL880518UG2' => 'Clara',
        'DGM880621FU5' => 'Gasomex',
        'GOG181220973' => 'TSA',
        'SSY940520271' => 'SYC',
        'PET180213L66' => 'Petrotal',
    ];

    public function llamadas_sat(): void {
        if (!in_array((int)$_SESSION['tg_user']['Id'], self::SAT_USERS)) {
            (new Errors())->get404();
            return;
        }
        echo $this->twig->render($this->route . 'llamadas_sat.html', [
            'rfcs' => self::SAT_RFCS,
        ]);
    }

    public function llamadas_sat_pendientes(): void {
        if (!in_array((int)$_SESSION['tg_user']['Id'], self::SAT_USERS)) {
            json_output(['success' => false, 'message' => 'Sin permisos']);
        }
        $res = $this->_sat_api('GET', 'get_petitions', null, 30);
        if (!$res['success']) {
            json_output($res);
        }
        $data = array_map(function ($p) {
            $p['empresa'] = self::SAT_RFCS[$p['razon_social']] ?? '';
            return $p;
        }, is_array($res['body']) ? $res['body'] : []);
        json_output(['success' => true, 'data' => $data]);
    }

    public function llamadas_sat_crear(): void {
        if (!in_array((int)$_SESSION['tg_user']['Id'], self::SAT_USERS)) {
            json_output(['success' => false, 'message' => 'Sin permisos']);
        }
        $rfc    = $_POST['razon_social'] ?? '';
        $tipo   = (int)($_POST['tipo'] ?? 0);
        $emited = (int)($_POST['emited'] ?? -1);
        $start  = $this->_sat_fecha($_POST['start_date'] ?? '');
        $end    = $this->_sat_fecha($_POST['end_date'] ?? '');

        if (!isset(self::SAT_RFCS[$rfc]))     json_output(['success' => false, 'message' => 'Razón social inválida']);
        if (!in_array($tipo, [1, 2], true))   json_output(['success' => false, 'message' => 'Tipo inválido']);
        if (!in_array($emited, [0, 1], true)) json_output(['success' => false, 'message' => 'Indica emitidas o recibidas']);
        if (!$start || !$end)                 json_output(['success' => false, 'message' => 'Fechas inválidas']);
        if ($start >= $end)                   json_output(['success' => false, 'message' => 'La fecha final debe ser mayor a la inicial']);

        // Se manda como JSON con enteros: la API compara tipo/emited con ===
        $res = $this->_sat_api('POST', 'create_petition', [
            'razon_social' => $rfc,
            'tipo'         => $tipo,
            'emited'       => $emited,
            'start_date'   => $start,
            'end_date'     => $end,
        ], 90);
        json_output($res);
    }

    public function llamadas_sat_verificar(): void {
        if (!in_array((int)$_SESSION['tg_user']['Id'], self::SAT_USERS)) {
            json_output(['success' => false, 'message' => 'Sin permisos']);
        }
        $rfc = $_POST['razon_social'] ?? '';
        if (!isset(self::SAT_RFCS[$rfc])) {
            json_output(['success' => false, 'message' => 'Razón social inválida']);
        }
        // consult_status solo consulta al SAT la petición pendiente más antigua del RFC; no modifica nada
        json_output($this->_sat_api('POST', 'consult_status', ['razon_social' => $rfc], 120));
    }

    /**
     * Modal con la bitácora de errores de ApiTotal (TGV2.dbo.ApiFailures):
     * fallas al crear peticiones, al verificarlas y marcados manuales.
     * POST razon_social (opcional, filtra por RFC). Responde HTML parcial.
     */
    public function llamadas_sat_errores_modal(): void {
        if (!in_array((int)$_SESSION['tg_user']['Id'], self::SAT_USERS)) {
            echo '<div class="modal-body"><div class="alert alert-danger">Sin permisos</div></div>';
            return;
        }
        $rfc = $_POST['razon_social'] ?? '';
        if ($rfc !== '' && !isset(self::SAT_RFCS[$rfc])) $rfc = '';

        $errores = (new SatPeticionesModel)->get_errores($rfc);
        echo $this->twig->render($this->route . 'modals/llamadas_sat_errores.html', [
            'errores' => $errores,
            'rfcs'    => self::SAT_RFCS,
            'rfc'     => $rfc,
        ]);
    }

    /**
     * Marca una petición pendiente como error (estado 4) para que ApiTotal ya
     * no la vuelva a consultar, y deja el registro en la bitácora.
     * POST id, motivo (opcional).
     */
    public function llamadas_sat_marcar_error(): void {
        if (!in_array((int)$_SESSION['tg_user']['Id'], self::SAT_USERS)) {
            json_output(['success' => false, 'message' => 'Sin permisos']);
        }
        $id     = (int)($_POST['id'] ?? 0);
        $motivo = mb_substr(trim($_POST['motivo'] ?? ''), 0, 500);

        $model    = new SatPeticionesModel;
        $peticion = $id > 0 ? $model->get_peticion($id) : null;
        if (!$peticion) {
            json_output(['success' => false, 'message' => 'La petición no existe']);
        }
        if ((int)$peticion['estado'] !== SatPeticionesModel::ESTADO_PENDIENTE) {
            json_output(['success' => false, 'message' => 'La petición ya no está pendiente (estado ' . $peticion['estado'] . ')']);
        }

        $afectadas = $model->marcar_error($id);
        if ($afectadas === false) {
            json_output(['success' => false, 'message' => 'No se pudo actualizar la petición']);
        }
        if ($afectadas === 0) {
            json_output(['success' => false, 'message' => 'La petición cambió de estado mientras tanto; recarga la tabla']);
        }

        $usuario = $_SESSION['tg_user']['Nombre'] ?? $_SESSION['tg_user']['Usuario'] ?? ('usuario ' . $_SESSION['tg_user']['Id']);
        try {
            $model->log_marcado_manual($peticion, $motivo, (int)$_SESSION['tg_user']['Id'], $usuario);
        } catch (Throwable $e) {
            // El marcado ya quedó; que falle la bitácora no debe revertirlo
            error_log('llamadas_sat_marcar_error: no se pudo registrar en ApiFailures: ' . $e->getMessage());
        }
        json_output(['success' => true, 'message' => 'Petición marcada como error; ya no se consultará']);
    }

    /** Panel "Estado del proceso": combina carpetas (ApiTotal) con datos de BD. */
    public function llamadas_sat_estado(): void {
        if (!in_array((int)$_SESSION['tg_user']['Id'], self::SAT_USERS)) {
            json_output(['success' => false, 'message' => 'Sin permisos']);
        }
        session_write_close();
        $model = new SatPeticionesModel;

        $pend = $model->get_resumen_pendientes();
        $peticiones = $pend + ['semaforo' => SatPeticionesModel::semaforo(
            SatPeticionesModel::edad_dias($pend['mas_vieja']),
            self::SAT_UMBRAL_PETICION_AMARILLO, self::SAT_UMBRAL_PETICION_ROJO)];

        $imp = $model->get_ultima_importacion();
        $fechaImp = max($imp['emitidas'] ?? '', $imp['recibidas'] ?? '') ?: null;
        $importado = $imp + ['semaforo' => $fechaImp === null ? 'sin_datos' : SatPeticionesModel::semaforo(
            SatPeticionesModel::edad_dias($fechaImp),
            self::SAT_UMBRAL_IMPORT_AMARILLO, self::SAT_UMBRAL_IMPORT_ROJO)];

        $res = $this->_sat_api('GET', 'pipeline_status', null, 60);
        $c = ($res['success'] && is_array($res['body'])) ? ($res['body']['carpetas'] ?? null) : null;
        if ($c === null) {
            $mensaje = ($res['status'] ?? 0) === 404
                ? 'Falta subir ApiTotal (pipeline_status)'
                : ($res['message'] ?? 'No se pudo leer el estado de las carpetas');
            $zip = ['disponible' => false, 'semaforo' => 'sin_datos', 'mensaje' => $mensaje];
            $xml = ['disponible' => false, 'semaforo' => 'sin_datos', 'mensaje' => $mensaje];
        } else {
            // ApiTotal puede correr en otra zona horaria; normaliza a hora local antes
            // de usarlo, y rellena las 6 llaves esperadas por si alguna faltó.
            $loc = fn(?string $iso): ?string => $iso ? date('Y-m-d H:i:s', strtotime($iso)) : null;
            foreach (['zip_emited', 'zip_received', 'xml_emited', 'xml_received', 'error_emited', 'error_received'] as $clave) {
                $c[$clave] = $c[$clave] ?? ['cantidad' => 0, 'mas_viejo' => null];
            }
            $masViejo = function (array ...$cs) use ($loc): ?string {
                $fechas = array_filter(array_map($loc, array_column($cs, 'mas_viejo')));
                return $fechas ? min($fechas) : null;
            };
            $semArchivo = function (int $cantidad, ?string $viejo): string {
                if ($cantidad === 0) return 'verde';
                return SatPeticionesModel::semaforo(SatPeticionesModel::edad_dias($viejo), 0, self::SAT_UMBRAL_ARCHIVO_ROJO);
            };

            $zipViejo = $masViejo($c['zip_emited'], $c['zip_received']);
            $zipCant  = $c['zip_emited']['cantidad'] + $c['zip_received']['cantidad'];
            $zip = ['disponible' => true, 'emitidas' => $c['zip_emited']['cantidad'],
                    'recibidas' => $c['zip_received']['cantidad'], 'mas_viejo' => $zipViejo,
                    'semaforo' => $semArchivo($zipCant, $zipViejo)];

            $xmlViejo = $masViejo($c['xml_emited'], $c['xml_received']);
            $xmlCant  = $c['xml_emited']['cantidad'] + $c['xml_received']['cantidad'];
            $enError  = $c['error_emited']['cantidad'] + $c['error_received']['cantidad'];
            $semXml   = $semArchivo($xmlCant, $xmlViejo);
            if ($semXml === 'verde' && $enError > 0) $semXml = 'amarillo';
            $xml = ['disponible' => true, 'emitidas' => $c['xml_emited']['cantidad'],
                    'recibidas' => $c['xml_received']['cantidad'], 'en_error' => $enError,
                    'mas_viejo' => $xmlViejo, 'semaforo' => $semXml];
        }

        json_output(['success' => true, 'tarjetas' => compact('peticiones') + ['zip' => $zip, 'xml' => $xml, 'importado' => $importado]]);
    }

    /** Descarga ahora las pendientes terminadas de un RFC (lo que hace la tarea de cada 4 h). */
    public function llamadas_sat_descargar(): void {
        if (!in_array((int)$_SESSION['tg_user']['Id'], self::SAT_USERS)) {
            json_output(['success' => false, 'message' => 'Sin permisos']);
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_output(['success' => false, 'message' => 'Método no permitido']);
        session_write_close();
        set_time_limit(0);
        $rfc = $_POST['razon_social'] ?? '';
        if (!isset(self::SAT_RFCS[$rfc])) {
            json_output(['success' => false, 'message' => 'Razón social inválida']);
        }
        $res = $this->_sat_api('POST', 'consult_pending', ['razon_social' => $rfc], 600);
        if (($res['status'] ?? 0) === 404) {
            json_output(['success' => true, 'sin_pendientes' => true, 'results' => [],
                         'message' => 'No hay peticiones pendientes para ' . self::SAT_RFCS[$rfc]]);
        }
        if (!$res['success']) json_output($res);
        json_output(['success' => true, 'results' => $res['body']['results'] ?? []]);
    }

    /** Descomprime todos los ZIP descargados (process_downloaded_packages). */
    public function llamadas_sat_descomprimir(): void {
        if (!in_array((int)$_SESSION['tg_user']['Id'], self::SAT_USERS)) {
            json_output(['success' => false, 'message' => 'Sin permisos']);
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_output(['success' => false, 'message' => 'Método no permitido']);
        session_write_close();
        set_time_limit(0);
        $res = $this->_sat_api('POST', 'process_downloaded_packages', [], 600);
        if (!$res['success']) json_output($res);
        $body = $res['body'];
        $fallidos = array_filter($body['results'] ?? [], fn($r) => ($r['status'] ?? '') !== 'success');
        json_output([
            'success'   => true,
            'processed' => (int)($body['processed'] ?? 0),
            'failed'    => (int)($body['failed'] ?? 0),
            'errores'   => $this->_sat_agrupar_errores(array_values($fallidos), 'file', 'message'),
        ]);
    }

    /** Un lote de importación (process_xml: ≤SAT_IMPORT_LIMITE emitidas + ≤SAT_IMPORT_LIMITE recibidas). */
    public function llamadas_sat_importar_lote(): void {
        if (!in_array((int)$_SESSION['tg_user']['Id'], self::SAT_USERS)) {
            json_output(['success' => false, 'message' => 'Sin permisos']);
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_output(['success' => false, 'message' => 'Método no permitido']);
        session_write_close();
        set_time_limit(0);
        $res = $this->_sat_api('POST', 'process_xml', ['limit' => self::SAT_IMPORT_LIMITE], 600);
        if (($res['status'] ?? 0) === 409) {
            json_output(['success' => false, 'message' => 'Ya hay una importación en curso (otra pestaña, otro usuario o la tarea programada). Intenta en unos minutos.']);
        }
        if (!$res['success'] || !is_array($res['body'])) {
            json_output(['success' => false, 'message' => $res['message'] ?? 'Respuesta inesperada de process_xml']);
        }
        $s = $res['body']['summary'] ?? [];
        $errores = array_values(array_filter($res['body']['logs'] ?? [], fn($l) => ($l['status'] ?? '') === 'error'));
        json_output([
            'success' => true,
            'summary' => [
                'total_processed' => (int)($s['total_processed'] ?? 0),
                'successful'      => (int)($s['successful'] ?? 0),
                'failed'          => (int)($s['failed'] ?? 0),
                'skipped'         => (int)($s['skipped'] ?? 0),
                'moved_to_error'  => (int)($s['moved_to_error'] ?? 0),
                'remaining'       => isset($s['remaining_emited'], $s['remaining_received'])
                    ? (int)$s['remaining_emited'] + (int)$s['remaining_received'] : null,
            ],
            'errores' => $this->_sat_agrupar_errores($errores, 'file', 'message'),
        ]);
    }

    /** Agrupa errores por mensaje: top 10 con cantidad y hasta 3 archivos de ejemplo. */
    private function _sat_agrupar_errores(array $items, string $campoArchivo, string $campoMensaje): array {
        $grupos = [];
        foreach ($items as $it) {
            $msg = mb_substr((string)($it[$campoMensaje] ?? 'Error sin mensaje'), 0, 300);
            $grupos[$msg] ??= ['mensaje' => $msg, 'cantidad' => 0, 'ejemplos' => []];
            $grupos[$msg]['cantidad']++;
            if (count($grupos[$msg]['ejemplos']) < 3 && !empty($it[$campoArchivo])) {
                $grupos[$msg]['ejemplos'][] = $it[$campoArchivo];
            }
        }
        usort($grupos, fn($a, $b) => $b['cantidad'] <=> $a['cantidad']);
        return array_slice($grupos, 0, 10);
    }

    /** Convierte 'YYYY-MM-DDTHH:MM[:SS]' (input datetime-local) a 'YYYY-MM-DD HH:MM:SS'. */
    private function _sat_fecha(string $value): ?string {
        $value = str_replace('T', ' ', trim($value));
        foreach (['Y-m-d H:i:s', 'Y-m-d H:i'] as $format) {
            $date = DateTime::createFromFormat($format, $value);
            if ($date && $date->format($format) === $value) {
                return $date->format('Y-m-d H:i:s');
            }
        }
        return null;
    }

    private function _sat_api(string $method, string $endpoint, ?array $payload, int $timeout): array {
        $ch = curl_init(self::SAT_API_URL . $endpoint);
        $headers = ['Accept: application/json'];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $timeout,
        ]);
        if ($method === 'POST') {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload ?? []));
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $raw    = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return ['success' => false, 'message' => "No se pudo conectar con ApiTotal ({$endpoint}): $error"];
        }
        $body   = json_decode($raw, true);
        $result = [
            'success' => $status >= 200 && $status < 300,
            'status'  => $status,
            'body'    => $body ?? $raw,
        ];
        if (!$result['success']) {
            $result['message'] = "ApiTotal respondió HTTP {$status} en {$endpoint}: " . $this->_sat_api_error($body, $raw);
        }
        return $result;
    }

    /** Arma un mensaje legible con cualquier formato de error que regrese ApiTotal. */
    private function _sat_api_error($body, string $raw): string {
        if (is_array($body)) {
            if (!empty($body['detalles'])) {
                return implode(' ', array_merge(...array_values(array_map('array_values', (array)$body['detalles']))));
            }
            if (!empty($body['error'])) {
                return $body['error'] . (!empty($body['details']) ? ': ' . $body['details'] : '');
            }
            if (!empty($body['message'])) {
                return $body['message'];
            }
            return json_encode($body, JSON_UNESCAPED_UNICODE);
        }
        // Respuesta no JSON (p. ej. página de error de IIS/Laravel): texto plano recortado
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($raw)));
        return $text === '' ? 'respuesta vacía' : mb_substr($text, 0, 500);
    }

    /* ------------------------------------------------------------------ */
    /*  VISOR DEL LOG DE ERRORES PHP (logs/php_errors.log)                  */
    /* ------------------------------------------------------------------ */

    private const ERROR_LOG_USERS = [6382, 6371, 6177, 6296, 6274];

    private function error_log_path(): string {
        return ROOT . 'logs' . DS . 'php_errors.log';
    }

    /**
     * Fuentes de log que el visor puede leer (whitelist: nunca se acepta una
     * ruta arbitraria del cliente). 'php' es el error_log original del php.ini
     * del servidor; se lee de global_value porque header.class.php lo re-apunta
     * con ini_set() al log de la app en cada petición.
     */
    private function error_log_sources(): array {
        $iniLog = ini_get_all()['error_log']['global_value'] ?? '';
        return [
            'app' => $this->error_log_path(),
            'php' => (string) $iniLog,
        ];
    }

    public function error_log(): void {
        if (!in_array((int)$_SESSION['tg_user']['Id'], self::ERROR_LOG_USERS)) {
            (new Errors())->get404();
            return;
        }
        echo $this->twig->render($this->route . 'error_log.html');
    }

    public function error_log_data(): void {
        header('Content-Type: application/json');
        if (!in_array((int)$_SESSION['tg_user']['Id'], self::ERROR_LOG_USERS)) {
            echo json_encode(['success' => false, 'message' => 'Sin permisos']); return;
        }
        $sources = $this->error_log_sources();
        $source  = ($_GET['source'] ?? 'app') === 'php' ? 'php' : 'app';
        $file    = $sources[$source];
        $lines   = min(5000, max(50, (int)($_GET['lines'] ?? 500)));

        if ($file === '') {
            echo json_encode(['success' => false, 'message' => 'El php.ini de este servidor no tiene configurada la directiva error_log.']);
            return;
        }
        if (is_file($file) && !is_readable($file)) {
            echo json_encode(['success' => false, 'message' => "Sin permiso de lectura sobre $file. Otorgue lectura al usuario del app pool (icacls)."]);
            return;
        }
        if (!is_file($file) || filesize($file) === 0) {
            echo json_encode(['success' => true, 'content' => '', 'size' => 0, 'mtime' => null, 'file' => $file]);
            return;
        }

        // Leer solo el final del archivo (máximo 1MB) para que el visor no
        // cargue en memoria un log que puede crecer mucho.
        $size  = filesize($file);
        $chunk = (int) min($size, 1048576);
        $fh = fopen($file, 'rb');
        fseek($fh, -$chunk, SEEK_END);
        $data = fread($fh, $chunk);
        fclose($fh);

        $arr = explode("\n", $data);
        if ($chunk < $size) {
            array_shift($arr); // la primera línea del chunk puede venir cortada
        }
        if (count($arr) > $lines) {
            $arr = array_slice($arr, -$lines);
        }

        // JSON_INVALID_UTF8_SUBSTITUTE: los mensajes del driver ODBC pueden
        // traer acentos en encoding Windows que romperían json_encode.
        echo json_encode([
            'success' => true,
            'content' => implode("\n", $arr),
            'size'    => $size,
            'mtime'   => date('Y-m-d H:i:s', filemtime($file)),
            'file'    => $file,
        ], JSON_INVALID_UTF8_SUBSTITUTE);
    }

    public function error_log_clear(): void {
        header('Content-Type: application/json');
        if (!in_array((int)$_SESSION['tg_user']['Id'], self::ERROR_LOG_USERS)) {
            echo json_encode(['success' => false, 'message' => 'Sin permisos']); return;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            echo json_encode(['success' => false, 'message' => 'Método no permitido']); return;
        }
        $file = $this->error_log_path();
        if (is_file($file)) {
            file_put_contents($file, '');
        }
        error_log('Log de errores vaciado por el usuario ' . $_SESSION['tg_user']['Id']);
        echo json_encode(['success' => true]);
    }

    public function datatables_controlgas_users(): void {
        if (!$this->is_controlgas_admin()) {
            $this->deny_controlgas_api();
            return;
        }
        $model = new ControlgasUsersModel();
        $data  = [];
        foreach ($model->get_users() as $row) {
            $cod = (int)$row['cod'];
            $den = (string)$row['den'];
            $denForHandler = json_encode(
                $den,
                JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE
            );
            $isDisabled = strncmp($den, 'BAJA ', 5) === 0;
            $actions = $isDisabled
                ? "<button class='btn btn-success btn-sm' onclick='rehabilitateControlgasUser({$cod}, {$denForHandler})' title='Rehabilitar' aria-label='Rehabilitar usuario COD {$cod}'><i class='fas fa-user-check' aria-hidden='true'></i></button>"
                : "<button class='btn btn-warning btn-sm' onclick='disableControlgasUser({$cod}, {$denForHandler})' title='Deshabilitar' aria-label='Deshabilitar usuario COD {$cod}'><svg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><circle cx='12' cy='12' r='10'></circle><line x1='4.93' y1='4.93' x2='19.07' y2='19.07'></line></svg></button>";
            $data[] = [
                'COD'     => $cod,
                'DEN'     => $row['den'],
                'CLV'     => $row['clv'],
                'ACC'     => $row['acc'],
                'ACCX'    => $row['accx'],
                'TIPOPR'  => $row['tipopr'],
                'TIPUSU'  => $row['tipusu'],
                'CODROL'  => $row['codrol'],
                'CODEST'  => $row['codest'],
                'LOGUSU'  => $row['logusu'],
                'LOGFCH'  => $row['logfch'],
                'LOGNEW'  => $row['lognew'],
                'USERID'  => $row['userid'],
                'CLVFCH'  => $row['clvfch'],
                'CLVEXP'  => $row['clvexp'],
                'ACCIONES' => $actions,
            ];
        }
        json_output(['data' => $data]);
    }
}
