<?php
declare(strict_types=1);
$stage = __DIR__;
$appRoot = $stage . DIRECTORY_SEPARATOR . 'app-root';
putenv('MONDAY_IMPORT_APP_ROOT=' . $appRoot);
require_once $appRoot . '/vendor/autoload.php';
require_once $appRoot . '/_assets/classes/common/MySqlPdoHandler.class.php';
require_once $appRoot . '/_assets/classes/TablerosConnection.class.php';
require_once $appRoot . '/_assets/classes/TablerosAccess.class.php';
require_once $appRoot . '/_assets/models/TablerosModel.php';
MySqlPdoHandler::getInstance()->connect('TG');
$pdo = TablerosConnection::get();
$count = (int)$pdo->query("SELECT COUNT(*) FROM sys.tables WHERE name IN ('tb_monday_import_run','tb_monday_import_entity','tb_monday_import_checkpoint','tb_monday_import_file_checkpoint')")->fetchColumn();
if ($count === 4) { echo "migration_013_already_applied\n"; exit(0); }
$sql = file_get_contents($stage . '/input/013_monday_import_tracking.sql');
if (!is_string($sql) || trim($sql) === '') throw new RuntimeException('migration_source_unavailable');
$pdo->exec($sql);
$count = (int)$pdo->query("SELECT COUNT(*) FROM sys.tables WHERE name IN ('tb_monday_import_run','tb_monday_import_entity','tb_monday_import_checkpoint','tb_monday_import_file_checkpoint')")->fetchColumn();
if ($count !== 4) throw new RuntimeException('migration_verification_failed');
echo "migration_013_applied\n";
