<?php
require __DIR__ . '/../_assets/classes/common/MySqlPdoHandler.class.php';
require __DIR__ . '/../_assets/classes/TablerosConnection.class.php';
$handler = MySqlPdoHandler::getInstance();
$handler->connect('TG');
$pdo = TablerosConnection::get();
$pdo->exec('SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED; SET LOCK_TIMEOUT 2000');
$constraint = $pdo->query("SELECT name, definition FROM sys.check_constraints WHERE name='CK_tb_workspace_visibility'")->fetch(PDO::FETCH_ASSOC);
$activeRequests = null;
try { $activeRequests = $pdo->query('SELECT r.session_id,r.status,r.command,r.wait_type,r.blocking_session_id,r.wait_time FROM sys.dm_exec_requests r WHERE r.database_id=DB_ID() AND r.session_id<>@@SPID')->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $ignored) {}
$blockingSession = null;
try { $blockingSession = $pdo->query('SELECT session_id,program_name,status,open_transaction_count,last_request_start_time,last_request_end_time FROM sys.dm_exec_sessions WHERE session_id=164')->fetch(PDO::FETCH_ASSOC) ?: null; } catch (Throwable $ignored) {}
$blockerRequest = null;
try { $blockerRequest = $pdo->query('SELECT session_id,status,command,wait_type,blocking_session_id,wait_time FROM sys.dm_exec_requests WHERE session_id=164')->fetch(PDO::FETCH_ASSOC) ?: null; } catch (Throwable $ignored) {}
$run = $pdo->query("SELECT TOP (1) id,status,expected_board_count,completed_at FROM dbo.tb_monday_import_run WHERE run_key='monday-backup-2026-10-14' ORDER BY id DESC")->fetch(PDO::FETCH_ASSOC);
if (!$run) { echo "run_not_visible\n"; exit(0); }
$runId = (int)$run['id'];
$pilot = $pdo->prepare("SELECT e.local_id FROM dbo.tb_monday_import_entity e WHERE e.source_account_id='4450682' AND e.entity_type='board' AND e.monday_id='1793587467'");
$pilot->execute();
$localBoard = $pilot->fetchColumn();
$identity = null; $content = null; $files = null;
if ($localBoard !== false && $localBoard !== null) {
    $q = $pdo->prepare('SELECT b.id,b.created_by,b.visibility,w.visibility AS workspace_visibility,w.created_by AS workspace_owner,m.role AS owner_role FROM dbo.tb_board b JOIN dbo.tb_workspace w ON w.id=b.workspace_id LEFT JOIN dbo.tb_workspace_member m ON m.workspace_id=w.id AND m.user_id=6371 WHERE b.id=?');
    $q->execute([(int)$localBoard]); $identity=$q->fetch(PDO::FETCH_ASSOC);
    $q=$pdo->prepare('SELECT (SELECT COUNT(*) FROM dbo.tb_group WHERE board_id=? AND deleted_at IS NULL) groups_count,(SELECT COUNT(*) FROM dbo.tb_column WHERE board_id=? AND deleted_at IS NULL) columns_count,(SELECT COUNT(*) FROM dbo.tb_item WHERE board_id=? AND deleted_at IS NULL) items_count,(SELECT COUNT(*) FROM dbo.tb_file WHERE board_id=? AND deleted_at IS NULL) files_count');
    $q->execute([(int)$localBoard,(int)$localBoard,(int)$localBoard,(int)$localBoard]); $content=$q->fetch(PDO::FETCH_ASSOC);
    $q=$pdo->prepare("SELECT fc.transfer_status,fc.derivatives_status,COUNT(*) n FROM dbo.tb_monday_import_entity e JOIN dbo.tb_monday_import_file_checkpoint fc ON fc.entity_id=e.id AND fc.run_id=? WHERE e.source_account_id='4450682' AND e.entity_type='file' AND e.last_seen_run_id=? GROUP BY fc.transfer_status,fc.derivatives_status");
    $q->execute([$runId,$runId]); $files=$q->fetchAll(PDO::FETCH_ASSOC);
}
$stmt = $pdo->prepare("SELECT status,COUNT(*) AS n FROM dbo.tb_monday_import_checkpoint WHERE run_id=? GROUP BY status");
$stmt->execute([$runId]);
$checkpoints = $stmt->fetchAll(PDO::FETCH_ASSOC);
$entities = $pdo->prepare("SELECT entity_type,COUNT(*) AS n FROM dbo.tb_monday_import_entity WHERE last_seen_run_id=? GROUP BY entity_type");
$entities->execute([$runId]);
$failures = $pdo->prepare("SELECT TOP (10) error_code,COUNT(*) AS n FROM dbo.tb_monday_import_checkpoint WHERE run_id=? AND status='failed' GROUP BY error_code ORDER BY n DESC");
$failures->execute([$runId]);
$boardStates=$pdo->prepare("SELECT c.status,COUNT(*) AS n,SUM(CASE WHEN e.local_id IS NULL THEN 0 ELSE 1 END) AS mapped_n FROM dbo.tb_monday_import_entity e JOIN dbo.tb_monday_import_checkpoint c ON c.entity_id=e.id AND c.run_id=? WHERE e.source_account_id='4450682' AND e.entity_type='board' GROUP BY c.status");
$boardStates->execute([$runId]);
$samples = $pdo->prepare("SELECT TOP (5) e.monday_id FROM dbo.tb_monday_import_entity e JOIN dbo.tb_monday_import_checkpoint c ON c.entity_id=e.id AND c.run_id=? WHERE e.entity_type='board' AND c.status='failed' ORDER BY e.monday_id");
$samples->execute([$runId]);
echo json_encode(['run'=>$run,'active_requests'=>$activeRequests,'blocking_session'=>$blockingSession,'blocker_request'=>$blockerRequest,'board_states'=>$boardStates->fetchAll(PDO::FETCH_ASSOC),'workspace_visibility_constraint'=>$constraint,'pilot_identity'=>$identity,'pilot_content'=>$content,'pilot_file_checkpoints'=>$files,'checkpoints'=>$checkpoints,'entities'=>$entities->fetchAll(PDO::FETCH_ASSOC),'failure_codes'=>$failures->fetchAll(PDO::FETCH_ASSOC),'sample_failed_board_ids'=>$samples->fetchAll(PDO::FETCH_COLUMN)], JSON_UNESCAPED_SLASHES) . "\n";
