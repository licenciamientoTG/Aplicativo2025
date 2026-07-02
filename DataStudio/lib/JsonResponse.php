<?php
if (!defined('DATASTUDIO_ENTRY')) {
    http_response_code(404);
    exit;
}
class JsonResponse
{
    public static function success(array $schema, array $rows): void
    {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(200);
        echo json_encode(['schema' => $schema, 'rows' => $rows], JSON_UNESCAPED_UNICODE);
    }

    public static function error(string $message, int $httpCode): void
    {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($httpCode);
        echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    }
}
