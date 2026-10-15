<?php
require __DIR__ . '/../_assets/classes/common/MySqlPdoHandler.class.php';
require __DIR__ . '/../_assets/classes/TablerosConnection.class.php';
$handler = MySqlPdoHandler::getInstance();
$handler->connect('TG');
$pdo = TablerosConnection::get();
$account = '4450682';
$sourceId = '10023956374';
$q = $pdo->prepare("SELECT e.local_id,b.created_by,b.visibility,w.visibility workspace_visibility,w.created_by workspace_owner, (SELECT COUNT(*) FROM dbo.tb_group g WHERE g.board_id=b.id AND g.deleted_at IS NULL) groups_count,(SELECT COUNT(*) FROM dbo.tb_column c WHERE c.board_id=b.id AND c.deleted_at IS NULL) columns_count,(SELECT COUNT(*) FROM dbo.tb_item i WHERE i.board_id=b.id AND i.deleted_at IS NULL) items_count,(SELECT COUNT(*) FROM dbo.tb_cell x WHERE x.board_id=b.id AND x.deleted_at IS NULL) cells_count,(SELECT COUNT(*) FROM dbo.tb_file f WHERE f.board_id=b.id AND f.deleted_at IS NULL) files_count FROM dbo.tb_monday_import_entity e JOIN dbo.tb_board b ON b.id=e.local_id JOIN dbo.tb_workspace w ON w.id=b.workspace_id WHERE e.source_account_id=? AND e.entity_type='board' AND e.monday_id=?");
$q->execute([$account, $sourceId]);
$board = $q->fetch(PDO::FETCH_ASSOC);
$q = $pdo->prepare("SELECT c.transfer_status,c.derivatives_status,COUNT(*) n FROM dbo.tb_monday_import_entity e JOIN dbo.tb_monday_import_file_checkpoint c ON c.entity_id=e.id AND c.run_id=1 JOIN dbo.tb_file f ON f.id=e.local_id WHERE e.source_account_id=? AND e.entity_type='file' AND e.last_seen_run_id=1 AND f.board_id=? GROUP BY c.transfer_status,c.derivatives_status");
$q->execute([$account, $board['local_id'] ?? 0]);
$files = $q->fetchAll(PDO::FETCH_ASSOC);
echo json_encode(['board'=>$board,'files'=>$files], JSON_UNESCAPED_SLASHES) . "\n";
