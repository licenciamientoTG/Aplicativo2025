<?php

/** Conexión aislada del módulo: no reemplaza la conexión PDO compartida. */
class TablerosConnection
{
    private static ?PDO $connection = null;

    public static function get(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        try {
            self::$connection = MySqlPdoHandler::getInstance()
                ->createIsolatedConnection('tableros');
        } catch (Throwable $e) {
            error_log('TablerosConnection: no fue posible abrir la base tableros: ' . $e->getMessage());
            throw new RuntimeException('El módulo Tableros no está disponible.');
        }

        return self::$connection;
    }
}
