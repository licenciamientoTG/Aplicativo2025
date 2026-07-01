<?php
class ApiKeyAuth
{
    public static function check(): bool
    {
        $provided = $_SERVER['HTTP_X_API_KEY'] ?? '';
        return $provided !== '' && hash_equals(DATASTUDIO_API_KEY, $provided);
    }
}
