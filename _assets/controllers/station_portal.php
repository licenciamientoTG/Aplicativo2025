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
}
