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
    public EstacionesModel $estacionesModel;
    public DespachosLealtadModel $despachosLealtadModel;
    public BinnacleActivitiesModel $binnacleActivitiesModel;

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
        $this->despachosLealtadModel   = new DespachosLealtadModel();
        $this->binnacleActivitiesModel = new BinnacleActivitiesModel();
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
    public function hello_world() : void {
        $allowed = [ 6371, 6177, 6296, 6375, 6274];
        if (!in_array((int)$_SESSION['tg_user']['Id'], $allowed)) {
            (new Errors())->get404();
            return;
        }
        echo $this->twig->render($this->route . 'hello_world.html');
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
        $stations = $this->estacionesModel->get_stations();
        $modal = [
            "title"    => "Editar usuario",
            "size"     => "modal-sm",
            "position" => "modal-dialog-centered",
            "content"  => $this->twig->render($this->route . 'modals/editUserModal.html', compact('profiles', 'user', 'stations'))
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
        $rs = $this->usersModel->editUser(trim($_POST['name']), $_POST['profile_id'], trim(strtolower($_POST['email'])), $_POST['IdEstacion'], $_POST['status'], $_POST['id']);
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
}
