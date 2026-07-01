<?php
if (!defined('DATASTUDIO_ENTRY')) {
    http_response_code(404);
    exit;
}
class ApiKeyAuth
{
    public static function check(): bool
    {
        $provided = $_SERVER['HTTP_X_API_KEY'] ?? '';
        return $provided !== '' && hash_equals(DATASTUDIO_API_KEY, $provided);
    }
}
