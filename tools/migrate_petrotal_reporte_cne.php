<?php
$_SERVER['DOCUMENT_ROOT'] = __DIR__ . '/..';
$_SERVER['REQUEST_URI'] = '/';
chdir($_SERVER['DOCUMENT_ROOT']);
require $_SERVER['DOCUMENT_ROOT'] . '/_assets/classes/header.class.php';
require $_SERVER['DOCUMENT_ROOT'] . '/_assets/classes/php_functions.php';
spl_autoload_register(function ($class) {
    if (file_exists(CLASSES . $class . '.class.php')) require CLASSES . $class . '.class.php';
    if (file_exists(MODELS . $class . '.php')) require MODELS . $class . '.php';
});
$db = MySqlPdoHandler::getInstance();

echo "Creando PetrotalReportesObligacion...\n";
$db->query("
    IF NOT EXISTS (SELECT 1 FROM sys.tables WHERE name = 'PetrotalReportesObligacion')
    CREATE TABLE PetrotalReportesObligacion (
        Id INT IDENTITY PRIMARY KEY,
        PeriodoDesde DATE NOT NULL,
        PeriodoHasta DATE NOT NULL,
        Estado VARCHAR(20) NOT NULL,
        RutaJson VARCHAR(500) NULL,
        RutaAcuse VARCHAR(500) NULL,
        FolioAcuse VARCHAR(50) NULL,
        RespuestaRaw NVARCHAR(MAX) NULL,
        UsuarioId INT NOT NULL,
        FechaEnvio DATETIME NULL,
        CreatedAt DATETIME NOT NULL DEFAULT GETDATE(),
        UpdatedAt DATETIME NOT NULL DEFAULT GETDATE()
    )
", []);

echo "Creando PetrotalFielConfig...\n";
$db->query("
    IF NOT EXISTS (SELECT 1 FROM sys.tables WHERE name = 'PetrotalFielConfig')
    CREATE TABLE PetrotalFielConfig (
        Id INT IDENTITY PRIMARY KEY,
        RutaCer VARCHAR(500) NOT NULL,
        RutaKey VARCHAR(500) NOT NULL,
        PasswordCifrado VARCHAR(500) NOT NULL,
        ActualizadoPor INT NOT NULL,
        UpdatedAt DATETIME NOT NULL DEFAULT GETDATE()
    )
", []);

echo "Insertando permisos 98/99...\n";
$existing = $db->select("SELECT id FROM tg_permissions WHERE id IN (98, 99)", []);
$existingIds = array_column($existing, 'id');

if (!in_array(98, $existingIds)) {
    $db->insert("SET IDENTITY_INSERT tg_permissions ON; INSERT INTO tg_permissions (id, action, department, description, status) VALUES (98, 'read', 'Petrotal', 'Ver módulo Petrotal - Reporte CNE', 1); SET IDENTITY_INSERT tg_permissions OFF;", []);
}
if (!in_array(99, $existingIds)) {
    $db->insert("SET IDENTITY_INSERT tg_permissions ON; INSERT INTO tg_permissions (id, action, department, description, status) VALUES (99, 'update', 'Petrotal', 'Configurar FIEL Petrotal', 1); SET IDENTITY_INSERT tg_permissions OFF;", []);
}

echo "Listo.\n";
