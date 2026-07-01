<?php
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/lib/ApiKeyAuth.php';
require __DIR__ . '/lib/JsonResponse.php';

if (!ApiKeyAuth::check()) {
    JsonResponse::error('No autorizado', 401);
    exit;
}

$version = preg_replace('/[^a-z0-9]/', '', strtolower($_GET['version'] ?? ''));
$report  = preg_replace('/[^a-z0-9_]/', '', strtolower($_GET['report'] ?? ''));

if ($version === '' || $report === '') {
    JsonResponse::error('Parámetros version y report son requeridos', 400);
    exit;
}

$reportFile = __DIR__ . "/{$version}/reports/{$report}.php";

if (!is_file($reportFile)) {
    JsonResponse::error("Reporte no encontrado: {$version}/{$report}", 404);
    exit;
}

try {
    $result = require $reportFile;

    if (!is_array($result) || !isset($result['schema'], $result['rows'])) {
        throw new RuntimeException("El reporte {$report} no devolvió schema/rows válidos");
    }

    JsonResponse::success($result['schema'], $result['rows']);
} catch (Throwable $e) {
    error_log('[DataStudio] ' . $e->getMessage());
    JsonResponse::error('Error interno al generar el reporte', 500);
}
