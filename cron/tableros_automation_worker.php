<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../_assets/classes/common/MySqlPdoHandler.class.php';
require_once __DIR__ . '/../_assets/classes/TablerosConnection.class.php';
require_once __DIR__ . '/../_assets/classes/TablerosAccess.class.php';
require_once __DIR__ . '/../_assets/models/TablerosModel.php';

try {
    $pdo = TablerosConnection::get();
    $lock = $pdo->prepare(
        "DECLARE @lock_result INT;
         EXEC @lock_result = sys.sp_getapplock
             @Resource = N'Tableros.Automation.Worker',
             @LockMode = N'Exclusive',
             @LockOwner = N'Session',
             @LockTimeout = 0,
             @DbPrincipal = N'public';
         SELECT @lock_result AS lock_result;"
    );
    $lock->execute();
    $lockResult = (int)$lock->fetchColumn();
    $lock->closeCursor();
    if ($lockResult < 0) {
        fwrite(STDOUT, "Another Tableros automation worker holds the database lock.\n");
        exit(0);
    }

    try {
        $model = new TablerosModel($pdo);
        $expired = $model->expireStaleAutomationRuns();
        $queued = $model->queueDueAutomations();
        $processed = $model->processAutomationQueue(500);
        fwrite(STDOUT, json_encode([
            'stale_failed' => $expired,
            'scheduled_queued' => $queued,
            'runs' => $processed,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    } finally {
        $release = $pdo->prepare(
            "DECLARE @release_result INT;
             EXEC @release_result = sys.sp_releaseapplock
                 @Resource = N'Tableros.Automation.Worker',
                 @LockOwner = N'Session',
                 @DbPrincipal = N'public';
             SELECT @release_result AS release_result;"
        );
        $release->execute();
        $release->closeCursor();
    }
} catch (Throwable $e) {
    error_log('Tableros automation worker failed: ' . $e->getMessage());
    fwrite(STDERR, "Tableros automation worker failed. See the server log for details.\n");
    exit(1);
}
