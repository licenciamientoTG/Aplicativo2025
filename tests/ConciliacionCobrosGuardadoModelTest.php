<?php
/**
 * Modelo de conciliación de cobros guardada (TG) + búsquedas SG12 de ConciliacionVentaModel.
 * Pega contra la BD real: TODA escritura va dentro de una transacción con rollBack (mes ficticio 1999-01).
 *     php tests/ConciliacionCobrosGuardadoModelTest.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$_SERVER['DOCUMENT_ROOT'] = __DIR__ . '/..';
$_SERVER['REQUEST_URI'] = '/';
chdir($_SERVER['DOCUMENT_ROOT']);
require $_SERVER['DOCUMENT_ROOT'] . '/_assets/classes/header.class.php';
require $_SERVER['DOCUMENT_ROOT'] . '/_assets/classes/php_functions.php';
spl_autoload_register(function ($class) {
    if (file_exists(CLASSES . $class . '.class.php')) require CLASSES . $class . '.class.php';
    if (file_exists(MODELS . $class . '.php')) require MODELS . $class . '.php';
});
require __DIR__ . '/support.php';

$MES = '1999-01';
$m = new ConciliacionCobrosGuardadoModel();
$db = MySqlPdoHandler::getInstance();

check('mes ficticio no existe antes', $m->mesGuardado($MES) === null);

$db->beginTransaction();
try {
    check('reservarMes primera vez', $m->reservarMes($MES, 'test') === true);
    check('reservarMes segunda vez (PK)', $m->reservarMes($MES, 'test') === false);
    $fila = $m->mesGuardado($MES);
    check('mesGuardado devuelve la fila', is_array($fila) && $fila['mes'] === $MES && $fila['guardado_por'] === 'test');
    check('tomarReintento false (ya es hoy)', $m->tomarReintento($MES) === false);

    $grupos = [
        ['origen' => 'auto', 'estatus' => 'Cuadra', 'comentario' => null, 'items' => [
            ['tipo' => 'C', 'codgas' => 7, 'nro' => 990001, 'movimiento_id' => null, 'monto' => 100.5, 'mes' => $MES, 'ocupa' => 1],
            ['tipo' => 'M', 'codgas' => null, 'nro' => null, 'movimiento_id' => 987654321, 'monto' => 100.5, 'mes' => $MES, 'ocupa' => 1],
            ['tipo' => 'F', 'codgas' => 7, 'nro' => 990002, 'movimiento_id' => null, 'monto' => 100.5, 'mes' => $MES, 'ocupa' => 1],
        ]],
        ['origen' => 'auto', 'estatus' => 'Diferencia', 'comentario' => 'x', 'items' => [
            ['tipo' => 'C', 'codgas' => 7, 'nro' => 990003, 'movimiento_id' => null, 'monto' => 50, 'mes' => $MES, 'ocupa' => 1],
            ['tipo' => 'M', 'codgas' => null, 'nro' => null, 'movimiento_id' => 987654322, 'monto' => 49, 'mes' => $MES, 'ocupa' => 0],
        ]],
    ];
    $ids = $m->guardarGrupos($grupos, $MES, 'test');
    check('guardarGrupos devuelve 2 ids', count($ids) === 2 && $ids[0] > 0 && $ids[1] > $ids[0]);

    $act = $m->gruposActivos($MES);
    check('gruposActivos devuelve 2', count($act) === 2);
    check('items del grupo 1', count($act[0]['items']) === 3 && $act[0]['id'] === $ids[0] && $act[0]['origen'] === 'auto');
    check('tipos y tipos de dato', $act[0]['items'][0]['tipo'] === 'C' && $act[0]['items'][0]['codgas'] === 7
        && $act[0]['items'][0]['monto'] === 100.5 && $act[0]['items'][0]['ocupa'] === 1);
    check('item M con movimiento_id', $act[0]['items'][1]['movimiento_id'] === 987654321);
    check('ocupa 0 se conserva', $act[1]['items'][1]['ocupa'] === 0);
    check('gruposActivos de otro mes vacío', $m->gruposActivos('1999-02') === []);

    check('gruposQueOcupan por cobro', $m->gruposQueOcupan(['7|990001'], [], []) === [$ids[0]]);
    check('gruposQueOcupan por factura', $m->gruposQueOcupan([], [], ['7|990002']) === [$ids[0]]);
    check('gruposQueOcupan ignora ocupa=0', $m->gruposQueOcupan([], [987654322], []) === []);
    check('gruposQueOcupan por movimiento ocupa=1', $m->gruposQueOcupan([], [987654321], []) === [$ids[0]]);
    check('gruposQueOcupan sin nada', $m->gruposQueOcupan([], [], []) === []);

    $m->darDeBaja([$ids[0]], 'test');
    $act = $m->gruposActivos($MES);
    check('darDeBaja deja 1 activo', count($act) === 1 && $act[0]['id'] === $ids[1]);
    check('gruposQueOcupan ignora dados de baja', $m->gruposQueOcupan(['7|990001'], [], []) === []);

    $m->bajaAutoDelMes($MES, 'test');
    check('bajaAutoDelMes', $m->gruposActivos($MES) === []);
    $m->marcarRecalculado($MES, 'test');
    $fila = $m->mesGuardado($MES);
    check('marcarRecalculado', $fila['recalculado_por'] === 'test' && $fila['recalculado_en'] !== null);

    // guardado masivo (multi-renglón en lotes de 200)
    $items = [];
    for ($i = 0; $i < 450; $i++) {
        $items[] = ['tipo' => 'C', 'codgas' => 7, 'nro' => 880000 + $i, 'movimiento_id' => null, 'monto' => 1, 'mes' => $MES, 'ocupa' => 1];
    }
    $ids2 = $m->guardarGrupos([['origen' => 'manual', 'estatus' => 'Conciliado manual', 'comentario' => 'masivo', 'items' => $items]], $MES, 'test');
    $act = $m->gruposActivos($MES);
    check('450 items guardados', count($ids2) === 1 && count($act) === 1 && count($act[0]['items']) === 450);
} finally {
    $db->rollBack();
}

check('rollBack: mes ficticio no persiste', $m->mesGuardado($MES) === null);
$left = $db->select("SELECT COUNT(*) AS n FROM TG.dbo.conciliacion_cobros_grupo WHERE mes = ?", [$MES]);
check('rollBack: sin grupos', (int)$left[0]['n'] === 0);

// Lecturas de SG12 (solo lectura)
$v = new ConciliacionVentaModel();
$d = dateToInt('2026-10-01');
$h = dateToInt('2026-10-31');
$r = $v->buscarCobros(['folio' => 64100], $d, $h);
$hit = array_values(array_filter($r, fn($x) => (int)$x['nro'] === 64100 && (int)$x['codgas'] === 2));
check('buscarCobros folio 64100 devuelve el de 07 Gemela Grande (codgas 2)', count($hit) === 1 && $hit[0]['estacion'] === '07 GEMEL GRANDE');
if ($hit) {
    $c = $v->cobrosPorClave([$hit[0]['codgas'] . '|' . $hit[0]['nro']]);
    check('cobrosPorClave lo devuelve', count($c) === 1 && (int)$c[0]['nro'] === 64100 && array_key_exists('facturas', $c[0]) && array_key_exists('txtref', $c[0]));
}
$porMov = $v->buscarCobros(['mov' => 23289], $d, $h);
check('buscarCobros por MOV 23289 incluye 64100', count(array_filter($porMov, fn($x) => (int)$x['nro'] === 64100)) === 1);
check('buscarCobros sin filtros devuelve []', $v->buscarCobros([], $d, $h) === []);
if ($hit) {
    $porImp = $v->buscarCobros(['importe' => $hit[0]['monto']], $d, $h);
    check('buscarCobros por importe incluye 64100', count(array_filter($porImp, fn($x) => (int)$x['nro'] === 64100)) === 1);
    // factura pagada: la que aplica el cobro 64100 tiene pagada > 0 y lista de pagos con ese cobro
    $apl = array_filter(array_map(fn($p) => explode(':', $p)[0], explode(',', (string)$hit[0]['facturas'])), fn($n) => (int)$n > 1200000000);
    if ($apl) {
        $fp = $v->facturasPorClave(['2|' . reset($apl)]);
        check('factura pagada por el cobro 64100', count($fp) === 1 && $fp[0]['pagada'] > 0 && str_contains((string)$fp[0]['pagos'], '2|64100:'));
    }
}
check('buscarFacturas sin filtros devuelve []', $v->buscarFacturas([]) === []);
$mv = new ConciliacionCobrosGuardadoModel();
$movs = $mv->buscarMovimientos(['desde' => '2026-10-01', 'hasta' => '2026-10-31', 'importe_min' => 1000, 'importe_max' => 5000]);
check('buscarMovimientos devuelve filas con grupo_id (null)', count($movs) > 0 && count($movs) <= 200 && array_key_exists('grupo_id', $movs[0]));
if ($movs) {
    $pm = $mv->movimientosPorId([$movs[0]['id']]);
    check('movimientosPorId', count($pm) === 1 && (int)$pm[0]['id'] === (int)$movs[0]['id'] && array_key_exists('detalle', $pm[0]));
    $sin = $mv->depositosSinConciliar('2026-10-01', '2026-10-31', [$movs[0]['cuenta']]);
    check('depositosSinConciliar incluye el movimiento', count(array_filter($sin, fn($x) => (int)$x['id'] === (int)$movs[0]['id'])) === 1);
}
check('buscarMovimientos sin filtros devuelve []', $mv->buscarMovimientos([]) === []);
$f = $v->buscarFacturas(['folio' => 'I104614']);
check('buscarFacturas I104614 devuelve 1', count($f) === 1);
if ($f) {
    check('factura con folio, total y pagada', $f[0]['folio'] === 'I-104614' && isset($f[0]['total']) && array_key_exists('pagada', $f[0]) && array_key_exists('pagos', $f[0]));
    $fk = $v->facturasPorClave([$f[0]['codgas'] . '|' . $f[0]['nro']]);
    check('facturasPorClave la devuelve', count($fk) === 1 && (int)$fk[0]['nro'] === (int)$f[0]['nro']);
    echo '  factura: ' . json_encode($f[0]) . "\n";
}

finish();
