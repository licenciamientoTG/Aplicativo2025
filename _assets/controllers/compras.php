<?php

/**
 * Compras de combustible (Abastos).
 *
 * Reemplaza poco a poco el Excel COMPRAS 2026.xlsx: cada vista agrega las
 * columnas del Excel que el sistema ya puede armar o capturar.
 * Plan: docs/superpowers/specs/2026-10-27-compras-registro-plan.md
 * Permisos: docs/sql/compras_permisos.sql
 *
 * Rutas: routes/compras.php
 */
class Compras
{
    private const PERM_VER    = 112;  // Compras de combustible - Ver registro
    private const PERM_EDITAR = 113;  // Compras de combustible - Capturar y editar (Fase 2)

    private $twig;
    private $route;
    private $registroModel;

    public function __construct($twig)
    {
        $this->twig          = $twig;
        $this->route         = 'views/compras/';
        $this->registroModel = new ComprasRegistroModel();
    }

    /** Registro de compras: el renglón por factura del Excel COMPRAS. */
    public function registro(): void
    {
        if (!authorized(self::PERM_VER)) {
            (new Errors())->get404();
            return;
        }
        [$desde, $hasta] = $this->rango();
        echo $this->twig->render($this->route . 'registro.html', [
            'desde'       => $desde,
            'hasta'       => $hasta,
            'proveedores' => $this->registroModel->proveedores(),
            'estaciones'  => $this->registroModel->estaciones(),
            'puedeEditar' => authorized(self::PERM_EDITAR),
            'capturaOk'   => $this->registroModel->capturaDisponible(),
        ]);
    }

    /** JSON del registro para el rango y filtros recibidos. */
    public function registro_datos(): void
    {
        header('Content-Type: application/json');
        if (!authorized(self::PERM_VER)) {
            http_response_code(403);
            json_output(['error' => 'Sin permiso']);
            return;
        }
        ini_set('max_execution_time', 300);
        [$desde, $hasta] = $this->rango();
        $rows = $this->registroModel->listar($desde, $hasta, [
            'proveedor' => $_GET['proveedor'] ?? '',
            'codgas'    => $_GET['codgas'] ?? '',
            'producto'  => $_GET['producto'] ?? '',
            'faltantes' => !empty($_GET['faltantes']),
        ]);
        if ($rows === false) {
            http_response_code(500);
            json_output(['error' => 'No se pudo consultar el registro de compras']);
            return;
        }
        json_output(['data' => $rows]);
    }

    /* ===================================================================== */
    /* Captura (permiso PERM_EDITAR)                                          */
    /* ===================================================================== */

    /**
     * Guarda un campo capturable de una factura (uuid) o de un renglón manual (id).
     * POST: origen = factura|manual, uuid|id, campo, valor ('' borra el dato).
     */
    public function guardar_celda(): void
    {
        $this->prepararEscritura();
        $m      = $this->registroModel;
        $origen = $_POST['origen'] ?? '';
        $campo  = $_POST['campo'] ?? '';
        $valor  = $_POST['valor'] ?? '';
        $userId = $this->userId();

        if ($origen === 'factura') {
            $uuid = strtoupper(trim($_POST['uuid'] ?? ''));
            if (!preg_match('/^[0-9A-F-]{30,50}$/', $uuid) || !$m->facturaExiste($uuid)) {
                $this->fallo(404, 'Factura no encontrada');
            }
            $tipo = ComprasRegistroModel::CAMPOS_FACTURA[$campo] ?? null;
            if ($tipo === null) {
                $this->fallo(400, 'Campo no editable');
            }
            $this->exigirColumnas($campo);
            $n = $m->normalizar($tipo, $valor);
            if (!$n['ok']) {
                $this->fallo(422, $n['error']);
            }
            $antes = $m->valorFactura($uuid, $campo);
            if (!$m->guardarCampoFactura($uuid, $campo, $n['valor'], $userId)) {
                $this->fallo(500, 'No se pudo guardar');
            }
            $m->auditar('F', $uuid, 'EDITAR', $campo, $antes, $n['valor'], $userId, $this->userNombre());
        } elseif ($origen === 'manual') {
            $id   = (int)($_POST['id'] ?? 0);
            $tipo = ComprasRegistroModel::CAMPOS_MANUAL[$campo] ?? null;
            if ($tipo === null) {
                $this->fallo(400, 'Campo no editable');
            }
            $this->exigirColumnas($campo);
            $actual = $m->valorManual($id, $campo);
            if (!is_array($actual)) {
                $this->fallo(404, 'Renglón no encontrado');
            }
            $n = $m->normalizar($tipo, $valor);
            if (!$n['ok']) {
                $this->fallo(422, $n['error']);
            }
            if (($n['valor'] === null) && in_array($campo, ['fecha', 'proveedor'], true)) {
                $this->fallo(422, 'Dato obligatorio');
            }
            if (!$m->guardarCampoManual($id, $campo, $n['valor'], $userId)) {
                $this->fallo(500, 'No se pudo guardar');
            }
            $m->auditar('M', (string)$id, 'EDITAR', $campo, $actual['v'], $n['valor'], $userId, $this->userNombre());
        } else {
            $this->fallo(400, 'Origen inválido');
        }
        json_output(['ok' => true]);
    }

    /** Crea un renglón manual (compras que no llegan por correo). POST con los campos de CAMPOS_MANUAL. */
    public function renglon_manual_crear(): void
    {
        $this->prepararEscritura();
        $m = $this->registroModel;
        $d = [];
        foreach (ComprasRegistroModel::CAMPOS_MANUAL as $campo => $tipo) {
            if (!isset($_POST[$campo]) || trim((string)$_POST[$campo]) === '') {
                continue;
            }
            $this->exigirColumnas($campo);
            $n = $m->normalizar($tipo, $_POST[$campo]);
            if (!$n['ok']) {
                $this->fallo(422, "$campo: " . $n['error']);
            }
            $d[$campo] = $n['valor'];
        }
        if (empty($d['fecha']) || empty($d['proveedor'])) {
            $this->fallo(422, 'Fecha y proveedor son obligatorios');
        }
        $userId = $this->userId();
        $id = $m->crearManual($d, $userId);
        if ($id === false) {
            $this->fallo(500, 'No se pudo crear el renglón');
        }
        $m->auditar('M', (string)$id, 'CREAR', null, null, json_encode($d, JSON_UNESCAPED_UNICODE), $userId, $this->userNombre());
        json_output(['ok' => true, 'id' => $id]);
    }

    /** Elimina (soft-delete) un renglón manual. POST: id. */
    public function renglon_manual_eliminar(): void
    {
        $this->prepararEscritura();
        $id     = (int)($_POST['id'] ?? 0);
        $userId = $this->userId();
        if ($id <= 0 || !$this->registroModel->eliminarManual($id, $userId)) {
            $this->fallo(404, 'Renglón no encontrado');
        }
        $this->registroModel->auditar('M', (string)$id, 'ELIMINAR', null, null, null, $userId, $this->userNombre());
        json_output(['ok' => true]);
    }

    private function prepararEscritura(): void
    {
        header('Content-Type: application/json');
        if (!authorized(self::PERM_EDITAR)) {
            $this->fallo(403, 'Sin permiso');
        }
        if (!$this->registroModel->capturaDisponible()) {
            $this->fallo(503, 'Falta crear las tablas de captura (docs/sql/compras_captura_schema.sql)');
        }
    }

    /** Los campos de captura v2 requieren haber corrido docs/sql/compras_captura_columnas.sql. */
    private function exigirColumnas(string $campo): void
    {
        if (in_array($campo, ComprasRegistroModel::CAMPOS_V2, true) && !$this->registroModel->capturaV2()) {
            $this->fallo(503, 'Falta correr docs/sql/compras_captura_columnas.sql');
        }
    }

    private function fallo(int $codigo, string $mensaje): void
    {
        http_response_code($codigo);
        json_output(['ok' => false, 'error' => $mensaje]);
        exit;
    }

    private function userId(): ?int
    {
        return isset($_SESSION['tg_user']['Id']) ? (int)$_SESSION['tg_user']['Id'] : null;
    }

    private function userNombre(): ?string
    {
        return $_SESSION['tg_user']['Nombre'] ?? $_SESSION['tg_user']['Usuario'] ?? null;
    }

    /** desde/hasta (Y-m-d) de GET; por defecto el mes en curso. */
    private function rango(): array
    {
        $fmt   = '/^\d{4}-\d{2}-\d{2}$/';
        $desde = $_GET['desde'] ?? '';
        $hasta = $_GET['hasta'] ?? '';
        if (!preg_match($fmt, $desde) || !preg_match($fmt, $hasta) || $desde > $hasta) {
            $desde = date('Y-m-01');
            $hasta = date('Y-m-d');
        }
        return [$desde, $hasta];
    }
}
