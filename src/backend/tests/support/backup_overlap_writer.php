<?php
// Concurrent Store writer used by database_backup_workflow_integration.php.
//
// This runs as its own OS process against the same Store database as the
// capture, so a mutation genuinely overlaps the snapshot window instead of
// pretending to. Each attempt is one gated transaction that writes a related
// pair of rows (a stock movement and the matching stock level), which is what
// makes a partially captured mutation detectable: after a restore, the movement
// count and the stock level must still agree.
//
// The parent creates a "ready" gate file and then a "go" gate file, so the
// child's first write attempt starts at the same moment as the capture.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$root = $argv[1] ?? '';
$configFile = $argv[2] ?? '';
$readyFile = $argv[3] ?? '';
$goFile = $argv[4] ?? '';
$resultFile = $argv[5] ?? '';
$productId = (int)($argv[6] ?? 0);
$attempts = (int)($argv[7] ?? 3);
$writerId = (int)($argv[8] ?? 0);

require_once $root . '/src/backend/bootstrap/app.php';
require_once $root . '/src/backend/includes/functions.php';

use App\Store\StoreWriteGate;
use App\Store\StoreWritePausedException;

$config = require $configFile;
$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=%s',
    $config['host'],
    $config['port'],
    $config['database'],
    $config['charset'] ?? 'utf8mb4'
);
$pdo = new PDO($dsn, $config['username'], $config['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

file_put_contents($readyFile, (string)getmypid());
$deadline = microtime(true) + 30;
while (!is_file($goFile) && microtime(true) < $deadline) {
    usleep(10000);
}

$outcomes = [];
$movement = $pdo->prepare(
    'INSERT INTO stock_movements (product_id, change_qty, reason, moved_by) VALUES (?, 1, ?, ?)'
);
$level = $pdo->prepare('UPDATE inventory SET quantity_on_hand = quantity_on_hand + 1 WHERE product_id = ?');

$committed = 0;
$paused = 0;
$deadline = microtime(true) + 60;
while ($committed < $attempts && microtime(true) < $deadline) {
    try {
        StoreWriteGate::begin($pdo);
    } catch (StoreWritePausedException) {
        $outcomes[] = 'paused';
        $paused++;
        usleep(40000);
        continue;
    }

    try {
        $movement->execute([$productId, 'adjustment', $writerId]);
        $level->execute([$productId]);
        $pdo->commit();
        $outcomes[] = 'committed';
        $committed++;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $outcomes[] = 'error:' . substr($exception->getMessage(), 0, 120);
    }
    usleep(20000);
}

file_put_contents($resultFile, json_encode(['outcomes' => $outcomes, 'committed' => $committed, 'paused' => $paused]));
