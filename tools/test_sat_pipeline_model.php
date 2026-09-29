<?php
// Verificación CLI de SatPeticionesModel (solo lectura contra la BD real).
// Uso: php tools/test_sat_pipeline_model.php
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
$_SERVER['REQUEST_URI']   = '/cli/test_sat_pipeline_model';
chdir($_SERVER['DOCUMENT_ROOT']);
require '_assets/classes/header.class.php';
require '_assets/classes/php_functions.php';
spl_autoload_register(function ($c) {
    foreach ([CLASSES . $c . '.class.php', MODELS . $c . '.php'] as $f) if (file_exists($f)) require $f;
});

$fallas = 0;
$ok = function (bool $cond, string $msg) use (&$fallas) {
    echo ($cond ? 'OK   ' : 'FAIL ') . $msg . "\n";
    if (!$cond) $fallas++;
};

$ahora = strtotime('2026-09-29 12:00:00');
$ok(SatPeticionesModel::edad_dias(null, $ahora) === null, 'edad_dias(null) = null');
$ok(abs(SatPeticionesModel::edad_dias('2026-09-27 12:00:00', $ahora) - 2.0) < 0.001, 'edad_dias 2 días');
$ok(SatPeticionesModel::semaforo(null, 1, 3) === 'verde', 'semaforo null = verde');
$ok(SatPeticionesModel::semaforo(0.5, 1, 3) === 'verde', 'semaforo 0.5 = verde');
$ok(SatPeticionesModel::semaforo(2, 1, 3) === 'amarillo', 'semaforo 2 = amarillo');
$ok(SatPeticionesModel::semaforo(4, 1, 3) === 'rojo', 'semaforo 4 = rojo');
$ok(SatPeticionesModel::semaforo(0, 0, 1) === 'amarillo', 'umbral amarillo 0: cualquier archivo = amarillo');

$m = new SatPeticionesModel;
$p = $m->get_resumen_pendientes();
$ok(is_int($p['pendientes']), 'pendientes es int (' . $p['pendientes'] . ')');
$ok($p['pendientes'] === 0 ? $p['mas_vieja'] === null : is_string($p['mas_vieja']), 'mas_vieja coherente (' . var_export($p['mas_vieja'], true) . ')');

$u = $m->get_ultima_importacion();
$ok(is_string($u['emitidas']) && is_string($u['recibidas']), "ultima importacion: {$u['emitidas']} / {$u['recibidas']}");

echo $fallas ? "\n$fallas fallas\n" : "\nTodo OK\n";
exit($fallas ? 1 : 0);
