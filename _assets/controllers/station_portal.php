<?php
class station_portal
{
    public $twig;
    public $route;
    public MovimientosTanModel $movimientosTanModel;
    public RecepcionRemisionesModel $recepcionRemisionesModel;
    public GasolinerasModel $gasolinerasModel;

    const PERM_VER              = 84; // "Ver Mis Recepciones (portal estaciones)"
    const PERM_TODAS_ESTACIONES = 85; // "Mis Recepciones: ver todas las estaciones"
    const PERM_ELIMINAR         = 86; // "Mis Recepciones: eliminar remisión"

    public function __construct($twig)
    {
        $this->twig = $twig;
        $this->route = 'views/station_portal/';
        $this->movimientosTanModel = new MovimientosTanModel();
        $this->recepcionRemisionesModel = new RecepcionRemisionesModel();
        $this->gasolinerasModel = new GasolinerasModel();
    }

    /**
     * Estación efectiva para el usuario en sesión: si tiene el permiso de
     * "todas las estaciones" y mandó un codgas válido por request, se respeta;
     * si no, siempre se fuerza la IdEstacion de sesión. Devuelve null si el
     * usuario no puede resolver ninguna estación (sin permiso y sin IdEstacion).
     */
    private function resolveCodgas(): ?int
    {
        $hasIdEstacion = isset($_SESSION['tg_user']['IdEstacion']) && (int)$_SESSION['tg_user']['IdEstacion'] > 0;

        if (authorized(self::PERM_TODAS_ESTACIONES)) {
            if (isset($_REQUEST['codgas']) && (int)$_REQUEST['codgas'] > 0) {
                return (int)$_REQUEST['codgas'];
            }
            return $hasIdEstacion ? (int)$_SESSION['tg_user']['IdEstacion'] : 0; // 0 = todas
        }

        return $hasIdEstacion ? (int)$_SESSION['tg_user']['IdEstacion'] : null;
    }

    public function mis_recepciones(): void
    {
        if (!authorized(self::PERM_VER)) {
            header('Location: /home/index');
            exit;
        }

        $codgas = $this->resolveCodgas();
        if ($codgas === null) {
            setFlashMessage('danger', 'Tu usuario no tiene una estación asignada.');
            header('Location: /home/index');
            exit;
        }

        $showStationSelect = authorized(self::PERM_TODAS_ESTACIONES);
        $stations = $showStationSelect ? $this->gasolinerasModel->get_active_stations() : [];
        $canDelete = authorized(self::PERM_ELIMINAR);

        echo $this->twig->render($this->route . 'mis_recepciones.html', compact('stations', 'showStationSelect', 'canDelete'));
    }

    public function datatables_recepciones(): void
    {
        if (!authorized(self::PERM_VER)) {
            json_output(['data' => [], 'error' => 'No autorizado']);
            return;
        }

        $codgas = $this->resolveCodgas();
        if ($codgas === null) {
            json_output(['data' => [], 'error' => 'Sin estación asignada']);
            return;
        }

        $fecha = $_REQUEST['fecha'] ?? date('Y-m-d');
        $fchtrn = dateToInt($fecha);

        $recepciones = $this->movimientosTanModel->sp_obtener_recepciones_combustible($fecha, $codgas, 0) ?: [];
        $counts = $this->recepcionRemisionesModel->get_counts_by_day($codgas, $fchtrn);

        $data = array_map(function ($r) use ($counts) {
            $nrotrn = (int)$r['nrotrn'];
            $totalRemisiones = $counts[$nrotrn] ?? 0;

            return [
                'nrotrn'          => $nrotrn,
                'codgas'          => (int)$r['codgas'],
                'fchtrn'          => (int)$r['fchtrn'],
                'hora'            => $r['hora'],
                'producto'        => $r['den'],
                'volumen'         => $r['VolumenRecibido'],
                'total_remisiones'=> $totalRemisiones,
            ];
        }, $recepciones);

        json_output(['data' => $data]);
    }

    public function upload_remision(): void
    {
        if (!authorized(self::PERM_VER)) {
            json_output(['success' => false, 'message' => 'No autorizado']);
            return;
        }

        $nrotrn = (int)($_POST['nrotrn'] ?? 0);
        $codgasPost = (int)($_POST['codgas'] ?? 0);
        $fchtrn = (int)($_POST['fchtrn'] ?? 0);

        if ($nrotrn <= 0 || $codgasPost <= 0 || $fchtrn <= 0 || !isset($_FILES['archivo'])) {
            json_output(['success' => false, 'message' => 'Datos incompletos']);
            return;
        }

        // El codgas del POST solo se respeta si el usuario tiene el permiso de
        // todas las estaciones; si no, se ignora por completo y se fuerza el de
        // sesión, sin importar qué haya mandado el cliente.
        $hasIdEstacion = isset($_SESSION['tg_user']['IdEstacion']) && (int)$_SESSION['tg_user']['IdEstacion'] > 0;
        if (authorized(self::PERM_TODAS_ESTACIONES)) {
            $codgasEfectivo = $codgasPost;
        } elseif ($hasIdEstacion) {
            $codgasEfectivo = (int)$_SESSION['tg_user']['IdEstacion'];
        } else {
            json_output(['success' => false, 'message' => 'No autorizado para esta estación']);
            return;
        }

        $userId = (int)$_SESSION['tg_user']['Id'];
        $result = $this->recepcionRemisionesModel->upload($nrotrn, $codgasEfectivo, $fchtrn, $_FILES['archivo'], $userId);

        json_output($result);
    }

    public function remisiones_by_recepcion(): void
    {
        if (!authorized(self::PERM_VER)) {
            http_response_code(403);
            exit;
        }

        $nrotrn = (int)($_GET['nrotrn'] ?? 0);
        $codgasGet = (int)($_GET['codgas'] ?? 0);
        $fchtrn = (int)($_GET['fchtrn'] ?? 0);
        $canDelete = authorized(self::PERM_ELIMINAR);

        // El codgas del GET solo se respeta si el usuario tiene el permiso de
        // todas las estaciones; si no, se ignora por completo y se fuerza el de
        // sesión, sin importar qué haya mandado el cliente.
        $hasIdEstacion = isset($_SESSION['tg_user']['IdEstacion']) && (int)$_SESSION['tg_user']['IdEstacion'] > 0;
        if (authorized(self::PERM_TODAS_ESTACIONES)) {
            $codgasEfectivo = $codgasGet;
        } elseif ($hasIdEstacion) {
            $codgasEfectivo = (int)$_SESSION['tg_user']['IdEstacion'];
        } else {
            http_response_code(403);
            exit;
        }

        $remisiones = $this->recepcionRemisionesModel->get_by_recepcion($nrotrn, $codgasEfectivo, $fchtrn);

        echo $this->twig->render($this->route . 'modals/remisiones_list.html', compact('remisiones', 'canDelete'));
    }

    public function delete_remision(): void
    {
        if (!authorized(self::PERM_ELIMINAR)) {
            json_output(['success' => false, 'message' => 'No autorizado']);
            return;
        }

        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            json_output(['success' => false, 'message' => 'Id inválido']);
            return;
        }

        $userId = (int)$_SESSION['tg_user']['Id'];
        $result = $this->recepcionRemisionesModel->soft_delete($id, $userId);

        json_output($result);
    }
}
