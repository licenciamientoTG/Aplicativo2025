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
    function users() : void {
        echo $this->twig->render($this->route . 'users.html');
    }

    /**
     * @return void
     */
    function datatables_users() : void {
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
    function permission_users($user_id) : void{
        echo $this->twig->render($this->route . 'permission_users.html', compact('user_id'));
    }

    /**
     * @return void
     * @throws Exception
     */
    function datatables_permissions_users() : void {
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
                                        <input class="form-check-input" type="checkbox" role="switch" id="btncheck'. $permission['permission_id'] .'" onChange="assignPermission(this)" data-permission="'. $permission['permission_id'] .'" data-user="'. $_GET['user_id'] .'" '. ($permission['Permitido'] == 0 ? '' : 'checked' ) .'>
                                    </div>'
            );
        }
        json_output(array("data" => $data));
    }

    /**
     * @return json
     * @throws Exception
     */
    public function assignPermission() : json {
        return json_output($this->permissionsUsersModel->assignPermission($_GET['user_id'], $_GET['permission_id'], $_GET['check']));
    }

    /**
     * @return void
     */
    function permissions() : void {
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
    function datatables_permissions() : void {
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
    function permissionModal() : void {
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
    function stationModal() : void {
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
    function release_dispatches() : void {
        if (preg_match('/GET/i',$_SERVER['REQUEST_METHOD'])){
            $nrotrn = $_GET['nrotrn'] ?? false;
            $codgas = $_GET['codgas'] ?? false;
            $stations = $this->gasolinerasModel->get_stations();
            echo $this->twig->render($this->route . 'release_dispatches.html', compact('stations', 'nrotrn', 'codgas'));
        } else {
            if ($this->despachosModel->release_dispatches(dateToInt($_POST['from']), dateToInt($_POST['until']), $_POST['codgas'])) {
                $response = [
                    "status" => "OK",
                    "message" => "¡Los despachos fueron liberados correctamente!",
                ];
                json_output($response);
            }
        }
    }

    function release_dispatch() : void {
        // Primero verificamos si recibimos las variables nrotrn y codgas por post
        if (isset($_POST['nrotrn']) AND isset($_POST['codgas'])) {
            // Si recibimos las variables, entonces liberamos el despacho
            if ($this->despachosModel->release_dispatch($_POST['nrotrn'], $_POST['codgas'])) {
                if ($this->despachosModel->release_dispatch($_POST['nrotrn'], $_POST['codgas'], 1)) {
                    $response = [
                        "status" => "OK",
                        "message" => "¡El despacho fue liberado correctamente!",
                    ];
                    json_output($response);
                } else {
                    $response = [
                        "status" => "ERROR",
                        "message" => "¡El despacho no pudo ser liberado!",
                    ];
                    json_output($response);
                }
            } else {
                $response = [
                    "status" => "ERROR",
                    "message" => "¡El despacho no pudo ser liberado!",
                ];
                json_output($response);
            }
        } else {
            $response = [
                "status" => "ERROR",
                "message" => "¡No se recibieron los datos necesarios!",
            ];
            json_output($response);
        }
    }

    /**
     * @return void
     * @throws Exception
     */
    function datatables_release_dispatches() : void {
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
                    'ACCIONES'  => '<a href="javascript:void(0);" onclick="release_dispatch('. $_GET['nrotrn'] .', '. $_GET['codgas'] .')" data-bs-toggle="tooltip" data-bs-placement="top" title="Liberación de despacho"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-skip-back align-middle me-2"><polygon points="19 20 9 12 19 4 19 20"></polygon><line x1="5" y1="19" x2="5" y2="5"></line></svg></a>'
                );
            }
        }
        json_output(array("data" => $data));
    }

    /**
     * @return void
     * @throws Exception
     */
    function userModal() : void {
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
    function editUserModal($id) : void {
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
    function changePasswordModal() :void {
        $user = $this->usersModel->get_user($_REQUEST['id']);
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
    function userForm() : json|int {
        if (preg_match('/POST/i',$_SERVER['REQUEST_METHOD'])){
            return json_output($this->usersModel->add(trim($_POST['name']), trim($_POST['username']), trim($_POST['password']), $_POST['profile_id'], trim(strtolower($_POST['email']))));
        }

        return 0;
    }

    /**
     * @return null
     * @throws Exception
     */
    function editUserForm(): null {
        $rs = $this->usersModel->editUser(trim($_POST['name']), $_POST['profile_id'], trim(strtolower($_POST['email'])), $_POST['IdEstacion'], $_POST['status'], $_POST['id']);
        return json_output(($rs ? 1 : 0));
    }

    /**
     * @return void
     * @throws Exception
     */
    function stations() : void {
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
    function datatables_stations() : void {
        $data = [];
        if ($stations = $this->estacionesModel->get_stations()) {
            $data = array_map(function ($station) {
                return [
                    'ID'                   => $station['Codigo'],
                    'NOMBRE'               => preg_replace('/^[0-9]+/', '', $station['Nombre']),
                    'DOMICILIO'            => $station['Domicilio'],
                    'ESTACIÓN'             => $station['Estacion'],
                    'SERVIDOR'             => $station['Servidor'],
                    'BD'                   => $station['BaseDatos'],
                    'CRE'                  => $station['PermisoCRE'],
                    'DENOMINACIÓN'         => $station['Denominacion'],
                    'ZONA'                 => $station['Zona'],
                    'BLOQUEARSINRECEPCION' => ($station['bloquearSinRecepcion'] == 1 ? '<span class="badge bg-warning">Sí</span>' : '<span class="badge bg-primary">No</span>'),
                    'STATUS'               => ( $station['activa'] == 1 ? '<span class="badge bg-success">Activa</span>' : '<span class="badge bg-primary">Inactiva</span>' ),
                    'CONEXION'             => ((@fsockopen($station['Servidor'], 1433, $errno, $errstr, 2)) ? "✅" : "❌")
                ];
            }, $stations);
        }
        json_output(array("data" => $data));
    }

//    /**
//     * @return void
//     * @throws Exception
//     */
//    function send_consumes() {
//        // Revisamos si hay despachos pendientes
//        if ($pending_dispatches = $this->despachosModel->get_pendings_loyalty_dispatches()) {
//            // Si hay despachoas pendientes los insertamos en la tabla de [TG].[dbo].[DespachosLealtad]
//            foreach ($pending_dispatches as $pending_dispatch) {
//                // Verificamos si existe el despacho en la tabla y si no esta, entonces insertamos
//                if (!$this->despachosLealtadModel->get_row($pending_dispatch['IdDespacho'], $pending_dispatch['Estacion'])) {
//                    $this->despachosLealtadModel->insert($pending_dispatch['Fecha'], $pending_dispatch['IdDespacho'], $pending_dispatch['Turno'], $pending_dispatch['Monto'], $pending_dispatch['Litros'], $pending_dispatch['Producto'], $pending_dispatch['ProductoDesc'], $pending_dispatch['Estacion'], $pending_dispatch['EstacionDesc'], $pending_dispatch['CodCliente'], $pending_dispatch['Cliente'], $pending_dispatch['Puntos'], $pending_dispatch['RazonSocial']);
//                }
//            }
//        }
//
//        // Obtenemos los despachos
//        if ($dispatches = $this->despachosModel->get_today_loyalty_dispatches()) {
//            // Listaremos todos los programas de TotalGas que consumiremos del API de Bederr
//            $programs = $this->get_programs();
//
//            // Obtenemos el UID del programa
//            $program_uid = $programs[0]->uid;
//            $company_uid = $programs[0]->company->uid;
//
//            var_dump($company_uid);
//            die();
//
//            // Obtenemos información sobre el programa
//            $program_info = $this->get_program_info($program_uid);
//
//            // Obtenemos las locaciones
//            $locations = $this->get_locations($company_uid);
//
//            foreach ($dispatches as $dispatch) {
//                $this->send_consume($dispatch, $program_uid);
//            }
//        } else {
//            echo '<pre>';
//            var_dump("No hay despachos");
//            die();
//        }
//    }
//
//    /**
//     * @param $client_id
//     * @param $client_secret
//     * @return false|mixed
//     */
//    private function getToken($client_id, $client_secret) {
//        $token_url = "{$this->baseApiUrl}oauth2/token/";
//
//        $data = [
//            'grant_type' => 'client_credentials',
//            'client_id' => $client_id,
//            'client_secret' => $client_secret,
//        ];
//
//        $ch = curl_init($token_url);
//
//        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
//        curl_setopt($ch, CURLOPT_POST, true);
//        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
//        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
//        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
//
//        $response = curl_exec($ch);
//
//        if (curl_errno($ch)) {
//            echo 'Error al obtener el token: ' . curl_error($ch);
//            return false;
//        }
//
//        curl_close($ch);
//
//        $token_data = json_decode($response, true);
//
//        if (isset($token_data['access_token'])) {
//            return $token_data;
//        } else {
//            echo 'No se pudo obtener el token. Respuesta del servidor: ' . $response;
//            return false;
//        }
//    }
//
//    /**
//     * @return string|array
//     */
//    private function get_programs() : string | array {
//
//        $programs_url = "{$this->baseApiUrl}admin/programs/";
//        $headers = array(
//            "Content-Type: application/json",
//            "Authorization: " . $this->token['token_type'] . " " . $this->token['access_token']
//        );
//
//        $ch = curl_init($programs_url);
//        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
//        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
//        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
//        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
//        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
//
//        $response = curl_exec($ch);
//        curl_close($ch);
//
//        $array = json_decode($response);
//
//        return $array;
//    }
//
//    /**
//     * @param $program_uid
//     * @return mixed
//     */
//    private function get_program_info($program_uid) {
//        $url = "{$this->baseApiUrl}admin/programs/{$program_uid}/";
//        $headers = array(
//            "Content-Type: application/json",
//            "Authorization: " . $this->token['token_type'] . " " . $this->token['access_token']
//        );
//
//        $ch = curl_init($url);
//        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
//        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
//        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
//        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
//        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
//
//        $response = curl_exec($ch);
//        curl_close($ch);
//
//
//        $array = json_decode($response);
//
//        return $array;
//    }
//
//    /**
//     * @param $company_uid
//     * @return array
//     */
//    private function get_locations($company_uid) {
//        $url1 = "{$this->baseApiUrl}admin/companies/{$company_uid}/places/";
//        $url2 = "{$this->baseApiUrl}admin/companies/{$company_uid}/places/?page=2";
//
//        $headers = array(
//            "Content-Type: application/json",
//            "Authorization: " . $this->token['token_type'] . " " . $this->token['access_token']
//        );
//
//        // Inicializa cURL para la primera URL
//        $ch1 = curl_init($url1);
//        curl_setopt($ch1, CURLOPT_CUSTOMREQUEST, "GET");
//        curl_setopt($ch1, CURLOPT_RETURNTRANSFER, true);
//        curl_setopt($ch1, CURLOPT_HTTPHEADER, $headers);
//        curl_setopt($ch1, CURLOPT_SSL_VERIFYPEER, false);
//        curl_setopt($ch1, CURLOPT_SSL_VERIFYHOST, false);
//
//        // Realiza la solicitud a la primera URL
//        $response1 = curl_exec($ch1);
//        curl_close($ch1);
//
//        // Inicializa cURL para la segunda URL
//        $ch2 = curl_init($url2);
//        curl_setopt($ch2, CURLOPT_CUSTOMREQUEST, "GET");
//        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
//        curl_setopt($ch2, CURLOPT_HTTPHEADER, $headers);
//        curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
//        curl_setopt($ch2, CURLOPT_SSL_VERIFYHOST, false);
//
//        // Realiza la solicitud a la segunda URL
//        $response2 = curl_exec($ch2);
//        curl_close($ch2);
//
//        // Decodifica ambas respuestas JSON en arreglos
//        $array1 = json_decode($response1, true);
//        $array2 = json_decode($response2, true);
//
//        // Combina los dos arreglos en uno solo
//        $combinedArray = array_merge($array1['results'], $array2['results']);
//
//        return $combinedArray;
//    }
//
//    /**
//     * @param $dispatch
//     * @param $program_uid
//     * @return void
//     */
//    private function send_consume($dispatch, $program_uid) : void {
//        $url = "{$this->baseApiUrl}admin/programs/{$program_uid}/points/";
//
//        $data = array(
//            "amount"          => $dispatch['amount'],
//            "document_number" => strtoupper($dispatch['document_number']),
//            "document_type"   => strtoupper($dispatch['document_type']),
//            "place_uid"       => $dispatch['place_uid'],
//            "awarded_at"      => date('Y-m-d H:i:s'),
//            "description"     => trim($dispatch['description']),
//            "extra"           => array(
//                                    'DespachoID'   => $dispatch['DespachoID']
//                                 ),
//            'unit_measure'    => $dispatch['unit_measure'],
//            'quantity'        => $dispatch['quantity']
//        );
//
//
//        $data_string = json_encode($data);
//
//        $headers = array(
//            "Content-Type: application/json",
//            "Authorization: " . $this->token['token_type'] . " " . $this->token['access_token']
//        );
//
//        $ch = curl_init($url);
//        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
//        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
//        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
//        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
//        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
//        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
//
//        $response = curl_exec($ch);
//        curl_close($ch);
//    }

    /**
     * @return void
     * @throws Exception
     */
    function profile() : void {
        $permissions = $this->permissionsUsersModel->get_permissions_users($_SESSION['tg_user']['Id']);
        echo $this->twig->render($this->route . 'profile.html', compact('permissions'));
    }

    /**
     * @return void
     * @throws Exception
     */
    function change_password() : void {
        if ($this->usersModel->changePassword($_SESSION['tg_user']['Id'], $_POST['password1'])) {
            json_output(1);
        } else {
            json_output(0);
        }
        die();
    }

    /**
     * @return void
     */
    function binnacle() : void {
        echo $this->twig->render($this->route . 'binnacle.html');
    }

    /**
     * @return void
     */
    function modalActivities() : void {
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
    function add_activity() : void {
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
    function edit_activity() : void {
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
    function get_activities() : void {
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
                    'backgroundColor' => '#'.substr(md5(rand()), 0, 6), // Aqui vamos a poner un color aleatorio solido oscurito
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
    function activityModal() : void {
        $activity = $this->binnacleActivitiesModel->getActivity($_POST['activity_id']);
        $modal = [
            "title"    => "Detalles de la actividad",
            "size"     => "modal-sm",
            "position" => "modal-dialog-centered",
            "content"  => $this->twig->render($this->route . 'modals/activityModal.html', compact('activity'))
        ];
        json_output($modal);
    }

    function modalEditActivities() : void {
        if ($activity = $this->binnacleActivitiesModel->getActivity($_POST['activity_id'])) {
            $modal = [
                "title"    => "Editar actividad",
                "size"     => "modal-sm",
                "position" => "modal-dialog-centered",
                "content"  => $this->twig->render($this->route . 'modals/activityEditModal.html', compact('activity'))
            ];
            json_output($modal);
        } else {
            echo '<pre>';
            var_dump($activity = $this->binnacleActivitiesModel->getActivity($_POST['activity_id']));
            die();
        }
    }

    /**
     * @return void
     * @throws Exception
     */
    function activities_list() : void {
        $activities = $this->binnacleActivitiesModel->getActivities();
        echo $this->twig->render($this->route . 'activities_list.html', compact('activities'));
    }
}