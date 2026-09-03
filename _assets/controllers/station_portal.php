<?php
class station_portal
{
    public $twig;
    public $route;
    public MovimientosTanModel $movimientosTanModel;
    public RecepcionRemisionesModel $recepcionRemisionesModel;
    public GasolinerasModel $gasolinerasModel;
    public PetrotalReconciliationModel $petrotalReconciliationModel;

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
        $this->petrotalReconciliationModel = new PetrotalReconciliationModel();
    }

    /**
     * Estación efectiva para el usuario en sesión.
     *
     * Reglas (única fuente de verdad — todos los endpoints del controlador
     * deben llamar a este método en vez de reimplementar la lógica):
     * - Sin permiso "todas las estaciones": siempre se fuerza la IdEstacion de
     *   sesión, ignorando por completo cualquier codgas que mande el cliente.
     *   Si no hay IdEstacion en sesión, devuelve null ("sin acceso en absoluto").
     * - Con permiso "todas las estaciones": se respeta el codgas de $_REQUEST
     *   si viene y es numérico >= 0 — 0 es válido y significa "(TODAS)" (la
     *   opción con ese value en el selector, ver SG12.dbo.Gasolineras cod=0);
     *   los llamadores deben tratar 0 como caso especial de agregación, nunca
     *   como "no vino". Si no viene codgas en absoluto, se usa la IdEstacion
     *   de sesión si existe. Si tampoco hay IdEstacion, devuelve null — pero
     *   en este caso null significa "tiene acceso, aún no eligió estación", no
     *   "sin acceso"; los llamadores de lectura (vista, datatable) deben
     *   distinguir este caso usando authorized(PERM_TODAS_ESTACIONES) y
     *   tratarlo como resultado vacío, no como rechazo. Los llamadores de
     *   escritura (upload/delete/ver archivo) siempre deben rechazar cuando
     *   el resultado es null O 0, sin excepción, porque no tiene sentido
     *   escribir sin una estación concreta.
     */
    private function resolveCodgas(): ?int
    {
        $hasIdEstacion = isset($_SESSION['tg_user']['IdEstacion']) && (int)$_SESSION['tg_user']['IdEstacion'] > 0;

        if (authorized(self::PERM_TODAS_ESTACIONES)) {
            if (isset($_REQUEST['codgas']) && $_REQUEST['codgas'] !== '' && is_numeric($_REQUEST['codgas']) && (int)$_REQUEST['codgas'] >= 0) {
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

    /**
     * Recepciones (tiptrn=3) de UNA estación en el rango dado, tal cual las
     * devuelve la API de ApiER (sin enriquecer con conteo de remisiones).
     * Único punto que arma y ejecuta la llamada a get_recepciones_combustible_rango,
     * reutilizado tanto para una estación como para el loop de "TODAS".
     *
     * @return array|null null si la llamada falló (error de red/HTTP/JSON);
     *                     array (posiblemente vacío) si respondió bien.
     */
    private function fetchRecepcionesEstacion(int $codgas, string $fechaDesde, string $fechaHasta): ?array
    {
        $postData = [
            'from'   => dateToInt($fechaDesde),
            'until'  => dateToInt($fechaHasta),
            'codgas' => $codgas,
            'codprd' => 0,
        ];

        $ch = curl_init('http://192.168.0.109:82/api/get_recepciones_combustible_rango/');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_errno($ch) ? curl_error($ch) : null;
        curl_close($ch);

        if ($curlErr !== null || $httpCode !== 200) {
            return null;
        }

        $recepciones = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($recepciones)) {
            return null;
        }

        return $recepciones;
    }

    /**
     * Igual que fetchRecepcionesEstacion pero para varias estaciones a la vez,
     * en paralelo (curl_multi) para no sumar sus tiempos de respuesta uno tras
     * otro. Usado solo por la opción "(TODAS)" del selector (codgas=0), tanto
     * en la tabla principal como en el resumen "Sin documento". Cada fila del
     * resultado incluye 'codgas' aunque la API ya lo trae, para blindarnos si
     * algún día dejara de hacerlo.
     *
     * @param int[] $codgasList
     * @return array{recepciones: array, fallidas: int[]} fallidas = estaciones
     *         cuya llamada falló (se omiten del resultado, no se rompe todo).
     */
    private function fetchRecepcionesTodasLasEstaciones(array $codgasList, string $fechaDesde, string $fechaHasta): array
    {
        $postDataBase = [
            'from'  => dateToInt($fechaDesde),
            'until' => dateToInt($fechaHasta),
            'codprd' => 0,
        ];

        $mh = curl_multi_init();
        $handles = [];
        foreach ($codgasList as $codgas) {
            $ch = curl_init('http://192.168.0.109:82/api/get_recepciones_combustible_rango/');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postDataBase + ['codgas' => $codgas]));
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 120);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
            curl_multi_add_handle($mh, $ch);
            $handles[$codgas] = $ch;
        }

        $running = null;
        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh);
        } while ($running > 0);

        $recepciones = [];
        $fallidas = [];
        foreach ($handles as $codgas => $ch) {
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_errno($ch) ? curl_error($ch) : null;
            $response = curl_multi_getcontent($ch);

            if ($curlErr === null && $httpCode === 200) {
                $rows = json_decode($response, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($rows)) {
                    foreach ($rows as $r) {
                        $r['codgas'] = $codgas;
                        $recepciones[] = $r;
                    }
                } else {
                    $fallidas[] = $codgas;
                }
            } else {
                $fallidas[] = $codgas;
            }

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);

        return ['recepciones' => $recepciones, 'fallidas' => $fallidas];
    }

    /**
     * Lista de códigos de estación activos, excluyendo la fila 0=(TODAS) que
     * SG12.dbo.Gasolineras trae mezclada con las estaciones reales.
     * @return int[]
     */
    private function codgasEstacionesActivas(): array
    {
        $stations = $this->gasolinerasModel->get_active_stations() ?: [];
        $codgas = array_map(fn($s) => (int)$s['cod'], $stations);
        return array_values(array_filter($codgas, fn($c) => $c > 0));
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

        // codgas=0 ("(TODAS)") solo es válido con el permiso de todas las
        // estaciones; resolveCodgas() ya lo garantiza (sin el permiso nunca
        // deja pasar el codgas de request), pero se revalida aquí porque este
        // es el único punto que decide si se dispara el loop de 38 estaciones.
        if ($codgas === 0 && $todasEstaciones) {
            $codgasList = $this->codgasEstacionesActivas();
            $resultado = $this->fetchRecepcionesTodasLasEstaciones($codgasList, $fechaDesde, $fechaHasta);
            $recepciones = $resultado['recepciones'];

            if (!empty($resultado['fallidas'])) {
                error_log('station_portal::datatables_recepciones (TODAS): fallaron estaciones ' . implode(',', $resultado['fallidas']));
            }
        } else {
            $recepciones = $this->fetchRecepcionesEstacion($codgas, $fechaDesde, $fechaHasta);
            if ($recepciones === null) {
                json_output(['data' => [], 'error' => 'Error al consultar recepciones']);
                return;
            }
        }

        // El conteo de remisiones se cachea por [codgas][fchtrn] para no repetir
        // la consulta a TG por cada fila con la misma estación+fecha (relevante
        // sobre todo en "TODAS", donde hay muchas combinaciones distintas).
        $counts = [];
        // Asignaciones directas (recepción<->factura del proveedor, sin
        // Petrotal) cacheadas por [codgas] — una sola consulta por estación
        // distinta en el resultado, nunca por fila.
        $asignacionesPorCodgas = [];
        foreach ($recepciones as $r) {
            $codgasFila = (int)$r['codgas'];
            $fchtrnFila = (int)$r['fchtrn'];
            if (!isset($counts[$codgasFila][$fchtrnFila])) {
                $counts[$codgasFila][$fchtrnFila] = $this->recepcionRemisionesModel->get_counts_by_day($codgasFila, $fchtrnFila);
            }
            if (!isset($asignacionesPorCodgas[$codgasFila])) {
                $asignacionesPorCodgas[$codgasFila] = $this->petrotalReconciliationModel->asignaciones_directas_por_estacion($codgasFila);
            }
        }

        $data = array_map(function ($r) use ($counts, $asignacionesPorCodgas) {
            $codgasFila = (int)$r['codgas'];
            $nrotrn = (int)$r['nrotrn'];
            $fchtrn = (int)$r['fchtrn'];
            $totalRemisiones = $counts[$codgasFila][$fchtrn][$nrotrn] ?? 0;
            $asignacion = $asignacionesPorCodgas[$codgasFila][$nrotrn] ?? null;

            return [
                'nrotrn'           => $nrotrn,
                'codgas'           => $codgasFila,
                'fchtrn'           => $fchtrn,
                'fecha'            => $r['fecha'],
                'hora'             => $r['hora'],
                'tanque'           => $r['codtan'],
                'producto'         => $r['den'],
                'volumen'          => $r['VolumenRecibido'],
                'documento'        => $r['documento'],
                'referencia'       => $r['referencia'],
                'total_remisiones' => $totalRemisiones,
                'factura_id'       => $asignacion['FacturaId'] ?? null,
                'factura_folio'    => $asignacion['Folio'] ?? null,
                'factura_proveedor' => $asignacion['EmisorNombre'] ?? null,
            ];
        }, $recepciones);

        json_output(['data' => $data]);
    }

    /**
     * Resumen "Sin documento": cuenta recepciones sin documento asignado en
     * el rango, agrupadas por estación cuando el pedido es "(TODAS)" (codgas=0).
     * Solo visible/llamable con permiso de "todas las estaciones" — es el mismo
     * dato que ya trae datatables_recepciones, pero agregado del lado del
     * servidor para no mandar el detalle completo solo para contar. Se dispara
     * bajo demanda desde el JS (al abrir el card), nunca automáticamente.
     */
    public function resumen_sin_documento(): void
    {
        if (!authorized(self::PERM_VER) || !authorized(self::PERM_TODAS_ESTACIONES)) {
            json_output(['error' => 'No autorizado']);
            return;
        }

        $codgas = $this->resolveCodgas();
        if ($codgas === null) {
            json_output(['total' => 0, 'por_estacion' => []]);
            return;
        }

        $fechaDesde = $_REQUEST['fecha_desde'] ?? date('Y-m-d');
        $fechaHasta = $_REQUEST['fecha_hasta'] ?? date('Y-m-d');

        $totalEstacionesConsultadas = 1;
        if ($codgas === 0) {
            $codgasList = $this->codgasEstacionesActivas();
            $totalEstacionesConsultadas = count($codgasList);
            $resultado = $this->fetchRecepcionesTodasLasEstaciones($codgasList, $fechaDesde, $fechaHasta);
            $recepciones = $resultado['recepciones'];
            $fallidas = $resultado['fallidas'];
        } else {
            $recepciones = $this->fetchRecepcionesEstacion($codgas, $fechaDesde, $fechaHasta);
            if ($recepciones === null) {
                json_output(['error' => 'Error al consultar recepciones']);
                return;
            }
            $fallidas = [];
        }

        // Por estación: conteo, volumen acumulado y la fecha MÁS ANTIGUA sin
        // documento (para mostrar "lleva N días esperando" en el frontend —
        // se calculan los días allá para no depender del timezone del server).
        $porEstacionStats = [];
        $total = 0;
        $volumenTotal = 0.0;
        foreach ($recepciones as $r) {
            if (!empty($r['documento'])) {
                continue;
            }
            $total++;
            $vol = (float)($r['VolumenRecibido'] ?? 0);
            $volumenTotal += $vol;

            $codgasFila = (int)$r['codgas'];
            $fecha = (string)($r['fecha'] ?? '');
            if (!isset($porEstacionStats[$codgasFila])) {
                $porEstacionStats[$codgasFila] = ['total' => 0, 'volumen' => 0.0, 'fecha_mas_antigua' => $fecha];
            }
            $porEstacionStats[$codgasFila]['total']++;
            $porEstacionStats[$codgasFila]['volumen'] += $vol;
            if ($fecha !== '' && $fecha < $porEstacionStats[$codgasFila]['fecha_mas_antigua']) {
                $porEstacionStats[$codgasFila]['fecha_mas_antigua'] = $fecha;
            }
        }

        $nombresPorCodgas = [];
        if (!empty($porEstacionStats)) {
            foreach ($this->gasolinerasModel->get_active_stations() ?: [] as $s) {
                $nombresPorCodgas[(int)$s['cod']] = $s['abr'];
            }
        }

        $porEstacion = [];
        foreach ($porEstacionStats as $codgasFila => $stats) {
            $porEstacion[] = [
                'codgas'            => $codgasFila,
                'nombre'            => $nombresPorCodgas[$codgasFila] ?? ('Estación ' . $codgasFila),
                'total'             => $stats['total'],
                'volumen'           => round($stats['volumen'], 2),
                'fecha_mas_antigua' => $stats['fecha_mas_antigua'],
            ];
        }
        usort($porEstacion, fn($a, $b) => $b['total'] <=> $a['total']);

        json_output([
            'total'                        => $total,
            'volumen_total'                => round($volumenTotal, 2),
            'por_estacion'                 => $porEstacion,
            'fallidas'                     => $fallidas,
            'total_estaciones_consultadas' => $totalEstacionesConsultadas,
        ]);
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

        // Escritura: null o 0 ("(TODAS)") siempre significa rechazar, sin
        // excepción (a diferencia de las lecturas, donde null con permiso 85
        // es "aún sin elegir estación" y se traduce en resultado vacío en vez
        // de error; y 0 en la tabla sí es válido porque ahí solo se agrega).
        $codgasEfectivo = $this->resolveCodgas();
        if ($codgasEfectivo === null || $codgasEfectivo === 0) {
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
        if ($codgasEfectivo === null || $codgasEfectivo === 0) {
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

    // Descarga el PDF o XML de la factura del proveedor real ligada a una
    // recepción (TG.dbo.FacturasMovimientosTanques, TipoOperacion=1) — solo
    // existe el botón en el frontend cuando esa liga ya fue confirmada desde
    // /supply/petrotal_reconciliation. No recibe el FacturaId directo del
    // cliente: recibe (codgas, nrotrn) y resuelve la factura del lado del
    // servidor, para que un usuario no pueda pedir cualquier FacturaId
    // manipulando la URL — solo puede descargar lo que está ligado a una
    // recepción de SU estación (o cualquiera, con permiso de todas).
    public function descargar_factura_recepcion($nrotrn = null, $tipo = null): void
    {
        if (!authorized(self::PERM_VER)) {
            http_response_code(403);
            echo "Acceso denegado";
            exit;
        }

        $codgas = (int)($_GET['codgas'] ?? 0);
        $nrotrn = (int)$nrotrn;
        $tipo = strtolower((string)$tipo);

        if (!$codgas || !$nrotrn || !in_array($tipo, ['pdf', 'xml'], true)) {
            http_response_code(400);
            echo "Parámetros inválidos";
            exit;
        }

        if (!authorized(self::PERM_TODAS_ESTACIONES)) {
            $codgasEfectivo = $this->resolveCodgas();
            if ($codgasEfectivo === null || $codgas !== $codgasEfectivo) {
                http_response_code(403);
                echo "Acceso denegado";
                exit;
            }
        }

        $asignaciones = $this->petrotalReconciliationModel->asignaciones_directas_por_estacion($codgas);
        $asignacion = $asignaciones[$nrotrn] ?? null;
        if (!$asignacion) {
            http_response_code(404);
            echo "Esta recepción no tiene factura confirmada";
            exit;
        }

        $rutaArchivo = $tipo === 'pdf' ? ($asignacion['RutaArchivo'] ?? '') : ($asignacion['RutaXml'] ?? '');
        $nombreArchivo = $tipo === 'pdf' ? ($asignacion['NombreArchivo'] ?? '') : ($asignacion['NombreXml'] ?? '');

        if (empty($rutaArchivo) || !file_exists($rutaArchivo)) {
            http_response_code(404);
            echo "Archivo no encontrado en el servidor";
            exit;
        }

        $contentType = $tipo === 'pdf' ? 'application/pdf' : 'application/xml';
        $nombreArchivo = $nombreArchivo ?: basename($rutaArchivo);

        header('Content-Type: ' . $contentType);
        header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
        header('Content-Length: ' . filesize($rutaArchivo));
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: public');

        ob_clean();
        flush();
        readfile($rutaArchivo);
        exit;
    }
}
