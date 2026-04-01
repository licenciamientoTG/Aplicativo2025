<?php
/**
 * Script de diagnóstico para depurar el problema de deudas en retardos
 */
session_start();

// Simular un usuario válido (cambia esto según necesites)
$_SESSION['tg_user'] = ['Id' => 6274];

require_once '_assets/models/SistemasTachasModel.php';

echo "<h2>Debug de Deudas - Sistemas Tachas</h2>";

$model = new SistemasTachasModel();

echo "<h3>1. Usuarios permitidos:</h3>";
echo "<pre>" . print_r(SistemasTachasModel::ALLOWED_USERS, true) . "</pre>";

echo "<h3>2. Usuarios en la base de datos:</h3>";
$usuarios = $model->get_usuarios();
echo "<pre>" . print_r($usuarios, true) . "</pre>";

echo "<h3>3. Deudas por usuario (query directa):</h3>";
$deudas = $model->get_deudas();
echo "<pre>" . print_r($deudas, true) . "</pre>";

echo "<h3>4. Ajustes de deuda:</h3>";
$ajustes = $model->get_ajustes();
echo "<pre>" . print_r($ajustes, true) . "</pre>";

echo "<h3>5. Últimas tachas:</h3>";
$last_tachas = $model->get_last_tachas();
echo "<pre>" . print_r($last_tachas, true) . "</pre>";

echo "<h3>6. Build stats completo:</h3>";
$stats = $model->build_stats();
echo "<pre>" . print_r($stats, true) . "</pre>";

echo "<h3>7. Informe generado:</h3>";
require_once '_assets/controllers/it.php';
$controller = new It();
// Usar reflexión para acceder al método privado
$reflection = new ReflectionClass($controller);
$method = $reflection->getMethod('_build_informe');
$method->setAccessible(true);
$informe = $method->invoke($controller);
echo "<pre>" . htmlspecialchars($informe) . "</pre>";

echo "<h3>8. Query de deudas (para verificar):</h3>";
$ids = implode(',', SistemasTachasModel::ALLOWED_USERS);
$query = "
    SELECT usuario_id, COUNT(*) * 100 AS deuda
    FROM [TG].[dbo].[sistemas_tachas]
    WHERE usuario_id IN ($ids) AND eliminada = 0
      AND metodo_pago = 'dinero' AND pagada = 0
    GROUP BY usuario_id
";
echo "<pre>$query</pre>";

echo "<h3>9. Datos crudos de sistemas_tachas:</h3>";
$raw_query = "
    SELECT TOP 20 *
    FROM [TG].[dbo].[sistemas_tachas]
    WHERE usuario_id IN ($ids)
    ORDER BY created_at DESC
";
$raw_data = $model->sql->select($raw_query, []);
echo "<pre>" . print_r($raw_data, true) . "</pre>";

echo "<h3>10. Todas las tachas recientes (para verificar método_pago y pagada):</h3>";
$all_query = "
    SELECT TOP 50 usuario_id, fecha, metodo_pago, pagada, eliminada
    FROM [TG].[dbo].[sistemas_tachas]
    WHERE usuario_id IN ($ids) AND eliminada = 0
    ORDER BY fecha DESC
";
$all_data = $model->sql->select($all_query, []);
echo "<pre>" . print_r($all_data, true) . "</pre>";
