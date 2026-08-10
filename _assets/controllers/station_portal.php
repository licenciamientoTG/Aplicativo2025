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
     * Estación efectiva para el usuario en sesión.
     *
     * Reglas (única fuente de verdad — todos los endpoints del controlador
     * deben llamar a este método en vez de reimplementar la lógica):
     * - Sin permiso "todas las estaciones": siempre se fuerza la IdEstacion de
     *   sesión, ignorando por completo cualquier codgas que mande el cliente.
     *   Si no hay IdEstacion en sesión, devuelve null ("sin acceso en absoluto").
     * - Con permiso "todas las estaciones": se respeta el codgas de
     *   $_REQUEST si viene y es > 0. Si no viene, se usa la IdEstacion de
     *   sesión si existe. Si tampoco hay IdEstacion, devuelve null — pero en
     *   este caso null significa "tiene acceso, aún no eligió estación", no
     *   "sin acceso"; los llamadores de lectura (vista, datatable) deben
     *   distinguir este caso usando authorized(PERM_TODAS_ESTACIONES) y
     *   tratarlo como resultado vacío, no como rechazo. Los llamadores de
     *   escritura (upload/delete/ver archivo) siempre deben rechazar cuando
     *   el resultado es null, sin excepción, porque no tiene sentido escribir
     *   sin una estación concreta.
     */
    private function resolveCodgas(): ?int
    {
        $hasIdEstacion = isset($_SESSION['tg_user']['IdEstacion']) && (int)$_SESSION['tg_user']['IdEstacion'] > 0;

        if (authorized(self::PERM_TODAS_ESTACIONES)) {
            if (isset($_REQUEST['codgas']) && (int)$_REQUEST['codgas'] > 0) {
                return (int)$_REQUEST['codgas'];
            }
            return $hasIdEstacion ? (int)$_SESSION['tg_user']['IdEstacion'] : null;
        }

        return $hasIdEstacion ? (int)$_SESSION['tg_user']['IdEstacion'] : null;
    }

    public function mis_recepciones(): void
    {
        if (!authorized(self::PERM_VER)) {
            header('Location: /home/index');
            exit;
        }

        $todasEstaciones = authorized(self::PERM_TODAS_ESTACIONES);
        $codgas = $this->resolveCodgas();

        // "Sin acceso en absoluto" = no tiene permiso de todas las estaciones
        // Y no hay codgas resuelto (sin IdEstacion de sesión). Ese es el único
        // caso que niega la vista. Si tiene permiso 85 pero aún no hay codgas
        // (no eligió estación todavía), sí se renderiza la vista, con tabla vacía.
        if ($codgas === null && !$todasEstaciones) {
            setFlashMessage('danger', 'Tu usuario no tiene una estación asignada.');
            header('Location: /home/index');
            exit;
        }

        $showStationSelect = $todasEstaciones;
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

        $todasEstaciones = authorized(self::PERM_TODAS_ESTACIONES);
        $codgas = $this->resolveCodgas();

        if ($codgas === null) {
            // Con permiso 85 y sin estación elegida todavía: no es un error,
            // simplemente no hay nada que mostrar aún.
            if ($todasEstaciones) {
                json_output(['data' => []]);
                return;
            }
            json_output(['data' => [], 'error' => 'Sin estación asignada']);
            return;
        }

        $fechaDesde = $_REQUEST['fecha_desde'] ?? date('Y-m-d');
        $fechaHasta = $_REQUEST['fecha_hasta'] ?? date('Y-m-d');

        $postData = [
            'from'   => dateToInt($fechaDesde),
            'until'  => dateToInt($fechaHasta),
            'codgas' => $codgas,
            'codprd' => 0,
        ];

        try {
            $ch = curl_init('http://192.168.0.109:82/api/get_recepciones_combustible_rango/');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 120);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if (curl_errno($ch)) {
                throw new Exception('Error de cURL: ' . curl_error($ch));
            }
            curl_close($ch);

            if ($httpCode !== 200) {
                throw new Exception("Error HTTP: $httpCode");
            }

            $recepciones = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($recepciones)) {
                throw new Exception('Respuesta inválida de la API');
            }
        } catch (Exception $e) {
            json_output(['data' => [], 'error' => 'Error al consultar recepciones: ' . $e->getMessage()]);
            return;
        }

        // resolveCodgas() nunca devuelve 0 (o entero >0, o null ya atajado
        // arriba), así que $codgas aquí siempre es una sola estación real:
        // la API sólo consulta esa estación. El conteo de remisiones se
        // cachea por fchtrn (el rango puede cubrir varios días) para no
        // repetir la consulta a TG por cada fila con la misma fecha.
        $counts = [];
        foreach ($recepciones as $r) {
            $fchtrnFila = (int)$r['fchtrn'];
            if (!isset($counts[$fchtrnFila])) {
                $counts[$fchtrnFila] = $this->recepcionRemisionesModel->get_counts_by_day($codgas, $fchtrnFila);
            }
        }

        $data = array_map(function ($r) use ($counts, $codgas) {
            $nrotrn = (int)$r['nrotrn'];
            $fchtrn = (int)$r['fchtrn'];
            $totalRemisiones = $counts[$fchtrn][$nrotrn] ?? 0;

            return [
                'nrotrn'           => $nrotrn,
                'codgas'           => $codgas,
                'fchtrn'           => $fchtrn,
                'fecha'            => $r['fecha'],
                'hora'             => $r['hora'],
                'producto'         => $r['den'],
                'volumen'          => $r['VolumenRecibido'],
                'total_remisiones' => $totalRemisiones,
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
        $fchtrn = (int)($_POST['fchtrn'] ?? 0);
        $fecha = trim($_POST['fecha'] ?? '');

        if ($nrotrn <= 0 || $fchtrn <= 0 || $fecha === '' || !isset($_FILES['archivo'])) {
            json_output(['success' => false, 'message' => 'Datos incompletos']);
            return;
        }

        // Escritura: null siempre significa rechazar, sin excepción (a
        // diferencia de las lecturas, donde null con permiso 85 es "aún sin
        // elegir estación" y se traduce en resultado vacío en vez de error).
        $codgasEfectivo = $this->resolveCodgas();
        if ($codgasEfectivo === null) {
            json_output(['success' => false, 'message' => 'No autorizado para esta estación']);
            return;
        }

        // Verifica que la recepción exista realmente en la estación resuelta
        // antes de aceptar el archivo, para no dejar filas huérfanas.
        $recepciones = $this->movimientosTanModel->sp_obtener_recepciones_combustible($fecha, $codgasEfectivo, 0) ?: [];
        $existe = false;
        foreach ($recepciones as $r) {
            if ((int)$r['nrotrn'] === $nrotrn) {
                $existe = true;
                break;
            }
        }
        if (!$existe) {
            json_output(['success' => false, 'message' => 'La recepción no existe o no pertenece a esta estación']);
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
        $fchtrn = (int)($_GET['fchtrn'] ?? 0);
        $canDelete = authorized(self::PERM_ELIMINAR);

        $codgasEfectivo = $this->resolveCodgas();
        if ($codgasEfectivo === null) {
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

        // Escritura: null siempre significa rechazar. Si el usuario tiene el
        // permiso de "todas las estaciones", se pasa null al modelo para no
        // restringir por estación; si no, se fuerza su codgas de sesión real
        // para que solo pueda borrar remisiones de su propia estación aunque
        // adivine ids ajenos.
        $todasEstaciones = authorized(self::PERM_TODAS_ESTACIONES);
        $codgasEfectivo = $this->resolveCodgas();
        if (!$todasEstaciones && $codgasEfectivo === null) {
            json_output(['success' => false, 'message' => 'No autorizado para esta estación']);
            return;
        }
        $codgasParaBorrar = $todasEstaciones ? null : $codgasEfectivo;

        $userId = (int)$_SESSION['tg_user']['Id'];
        $result = $this->recepcionRemisionesModel->soft_delete($id, $userId, $codgasParaBorrar);

        json_output($result);
    }

    public function view_remision($id = null): void
    {
        if (!authorized(self::PERM_VER)) {
            http_response_code(403);
            echo "Acceso denegado";
            exit;
        }

        $id = (int)$id;
        if ($id <= 0) {
            http_response_code(404);
            echo "Documento no encontrado";
            exit;
        }

        $doc = $this->recepcionRemisionesModel->get_by_id($id);

        if (!$doc) {
            http_response_code(404);
            echo "Documento no encontrado";
            exit;
        }

        // Mismo scoping que el resto del controlador (resolveCodgas()): sin
        // permiso de "todas las estaciones", el codgas del documento debe
        // coincidir exactamente con la estación efectiva del usuario. Con el
        // permiso, cualquier estación es válida siempre que el documento
        // exista (ya verificado arriba).
        if (!authorized(self::PERM_TODAS_ESTACIONES)) {
            $codgasEfectivo = $this->resolveCodgas();
            if ($codgasEfectivo === null || (int)$doc['codgas'] !== $codgasEfectivo) {
                http_response_code(403);
                echo "Acceso denegado";
                exit;
            }
        }

        if (empty($doc['file_path'])) {
            http_response_code(404);
            echo "Este documento no tiene archivo";
            exit;
        }

        $fullPath = realpath(__DIR__ . '/../../' . $doc['file_path']);
        $baseAllowed = realpath(__DIR__ . '/../uploads/recepcion_remisiones');

        if ($fullPath === false || $baseAllowed === false || strpos($fullPath, $baseAllowed) !== 0) {
            http_response_code(403);
            echo "Acceso denegado";
            exit;
        }

        if (!file_exists($fullPath) || !is_readable($fullPath)) {
            http_response_code(404);
            echo "Archivo no encontrado";
            exit;
        }

        $ext = strtolower($doc['file_extension'] ?? '');
        $contentTypes = [
            'pdf'  => 'application/pdf',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
        ];
        $contentType = $contentTypes[$ext] ?? 'application/octet-stream';

        header('Content-Type: ' . $contentType);
        header('Content-Disposition: inline; filename="' . basename($fullPath) . '"');
        header('Content-Length: ' . filesize($fullPath));
        readfile($fullPath);
        exit;
    }
}
