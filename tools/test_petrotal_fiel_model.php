<?php
// tools/test_petrotal_fiel_model.php
$_SERVER['DOCUMENT_ROOT'] = __DIR__ . '/..';
$_SERVER['REQUEST_URI'] = '/';
chdir($_SERVER['DOCUMENT_ROOT']);
require $_SERVER['DOCUMENT_ROOT'] . '/_assets/classes/header.class.php';
require $_SERVER['DOCUMENT_ROOT'] . '/_assets/classes/php_functions.php';
spl_autoload_register(function ($class) {
    if (file_exists(CLASSES . $class . '.class.php')) require CLASSES . $class . '.class.php';
    if (file_exists(MODELS . $class . '.php')) require MODELS . $class . '.php';
});

$model = new PetrotalFielModel();
$fallos = 0;

$guardado = $model->guardar_config('/ruta/prueba.cer', '/ruta/prueba.key', 'PasswordDePrueba123', 1);
echo ($guardado ? "OK  " : "FAIL") . " guardar_config retorna true\n";
if (!$guardado) $fallos++;

$config = $model->obtener_config();
$ok = $config && $config['ruta_cer'] === '/ruta/prueba.cer' && $config['password'] === 'PasswordDePrueba123';
echo ($ok ? "OK  " : "FAIL") . " obtener_config descifra el password correctamente: " . json_encode($config) . "\n";
if (!$ok) $fallos++;

// Limpieza. DELETE sin condiciones reales: delete() exige params no vacíos,
// así que se usa "WHERE 1 = ?" con [1] en vez de un arreglo vacío.
$model->sql->delete("DELETE FROM TG.dbo.PetrotalFielConfig WHERE 1 = ?", [1]);
echo "Configuración de prueba eliminada.\n";

echo $fallos === 0 ? "\nTodos los casos pasaron.\n" : "\n$fallos caso(s) fallaron.\n";
