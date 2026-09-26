<?php
// Shared encrypted Database Backup workflow (ticket #69).
//
// Primary boundary test: a backup captured through the shared workflow is
// decrypted and restored into a separate disposable destination, and the
// recovered business state is asserted rather than the generated SQL text.
//
// Every database used here is disposable and created by this test. Destructive
// restoration never touches the developer's Store database.
if (getenv('RUN_DB_TESTS') !== '1') {
    echo "Database Backup workflow integration: skipped (set RUN_DB_TESTS=1)\n";
    exit(0);
}

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/backup.php';
require_once __DIR__ . '/../includes/functions.php';

use App\Authorization\RoleCapabilityPolicy;
use App\Backup\BackupCoordinator;
use App\Backup\BackupKeyProvider;
use App\Backup\DatabaseSnapshotWriter;
use App\Backup\EncryptedBackupEnvelope;
use App\Database\MigrationRunner;
use App\Database\Schema;
use App\Services\DatabaseBackupService;
use App\Services\DatabaseRestoreService;
use App\Store\StoreWriteGate;
use App\Store\StoreWritePausedException;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$root = dirname(__DIR__, 3);
$config = require __DIR__ . '/../config/database.php';
$admin = $config;
$admin['username'] = $config['username'] === 'root' ? 'root' : $config['username'];
$sourceName = 'retailmind_backup_fixture_source';
$destinationName = 'retailmind_backup_fixture_destination';
$scratch = sys_get_temp_dir() . '/retailmind-backup-fixture-' . bin2hex(random_bytes(4));
$artifactDirectory = $scratch . '/artifacts';

$serverPdo = null;
$sourcePdo = null;
$destinationPdo = null;
$writerPdo = null;
$envBackupKey = getenv('BACKUP_ENCRYPTION_KEY');
$restoreKey = bin2hex(random_bytes(32));

$connect = static function (array $config, ?string $database = null): PDO {
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $config['host'],
        $config['port'],
        $database ?? $config['database'],
        $config['charset'] ?? 'utf8mb4'
    );
    return new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
    ]);
};

$loadSchema = static function (PDO $pdo) use ($root): void {
    $sql = (string)file_get_contents($root . '/src/backend/sql/schema.sql');
    // schema.sql is a mysqldump whose tables reference each other out of order.
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    try {
        foreach (preg_split('/;\s*\n/', $sql) ?: [] as $statement) {
            $trimmed = trim($statement);
            if ($trimmed === '' || str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '--')) {
                continue;
            }
            $pdo->exec($trimmed);
        }
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }
    (new MigrationRunner($pdo, $root . '/src/backend/database/migrations'))->runPending();
};

try {
    if (!function_exists('restore_database_backup')) {
        throw new RuntimeException('The shared backup module did not load.');
    }

    mkdir($artifactDirectory, 0775, true);
    $serverPdo = $connect($config);
    foreach ([$sourceName, $destinationName] as $name) {
        $serverPdo->exec('DROP DATABASE IF EXISTS `' . $name . '`');
        $serverPdo->exec('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    }

    $sourcePdo = $connect($config, $sourceName);
    $destinationPdo = $connect($config, $destinationName);
    $loadSchema($sourcePdo);
    $loadSchema($destinationPdo);

    // The destination starts from a different, independently seeded state, so
    // a real recovery has to replace it rather than no-op.
    $destinationProductsBefore = (int)$destinationPdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
    $assert($destinationProductsBefore > 0, 'The disposable restore destination must start from real seeded data');

    putenv('BACKUP_ENCRYPTION_KEY=' . $restoreKey);
    $_ENV['BACKUP_ENCRYPTION_KEY'] = $restoreKey;
    $_SERVER['BACKUP_ENCRYPTION_KEY'] = $restoreKey;

    $policy = new RoleCapabilityPolicy();
    $backups = new DatabaseBackupService($sourcePdo, $policy, $artifactDirectory, $sourcePdo);

    // --- role-aware actors -------------------------------------------------
    $insertUser = $sourcePdo->prepare(
        "INSERT INTO users (full_name, username, password_hash, role_id, must_change_password) VALUES (?, ?, ?, ?, 0)"
    );
    $actors = [];
    foreach (['super_admin' => 'Super', 'admin' => 'Owner', 'cashier' => 'Cashier'] as $role => $name) {
        $roleId = (int)$sourcePdo->query("SELECT role_id FROM roles WHERE role_name = " . $sourcePdo->quote($role))->fetchColumn();
        $insertUser->execute([$name . ' Fixture', strtolower($role) . '_backup_fixture_' . bin2hex(random_bytes(3)), password_hash('Fixture@2026', PASSWORD_DEFAULT), $roleId]);
        $actors[$role] = (int)$sourcePdo->lastInsertId();
    }
    $ownerId = $actors['admin'];
    $superId = $actors['super_admin'];

    // --- representative business state, including awkward values ---------
    $sourcePdo->exec("INSERT INTO categories (category_name) VALUES ('Wholesale')");
    $categoryId = (int)$sourcePdo->lastInsertId();
    $sourcePdo->prepare(
        "INSERT INTO products (sku, barcode, product_name, brand, supplier, category_id, unit_price, cost_price, reorder_level, safety_stock, created_by)
         VALUES ('SKU-1', 'BAR-1', 'Kape jelly', NULL, ?, ?, 55.50, 40.00, 10, 5, ?)"
    )->execute(["O'Brien \"Supplier\"\nsecond line — ünïcode", $categoryId, $superId]);
    $productId = (int)$sourcePdo->lastInsertId();
    $sourcePdo->prepare('INSERT INTO inventory (product_id, quantity_on_hand) VALUES (?, 42)')->execute([$productId]);

    $sourcePdo->prepare(
        "INSERT INTO sales (cashier_id, gross_amount, total_amount, discount_type, discount_value, discount_amount, payment_method, sale_date)
         VALUES (?, 500.00, 500.00, 'none', 0.00, 0.00, 'cash', NOW())"
    )->execute([$actors['cashier']]);
    $saleId = (int)$sourcePdo->lastInsertId();
    $sourcePdo->prepare(
        'INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, subtotal) VALUES (?, ?, 3, 55.50, 166.50)'
    )->execute([$saleId, $productId]);
    $sourcePdo->prepare(
        "INSERT INTO activity_log (user_id, action, category, module, new_value, metadata)
         VALUES (?, 'Multiline receipt note', 'store_operation', 'Sales', ?, NULL)"
    )->execute([$actors['cashier'], "O'Brien \"said no\"\nsecond line — ünïcode\ttab"]);
    $multilineLogId = (int)$sourcePdo->lastInsertId();

    $coordinator = new BackupCoordinator($sourcePdo, $artifactDirectory, $sourcePdo);
    $assert(!$coordinator->isOperationActive(), 'No backup operation should be active before a capture');

    // --- authorization at the shared workflow boundary ---------------------
    foreach (['admin', 'super_admin'] as $role) {
        $assert(
            $policy->allows($role, RoleCapabilityPolicy::MANAGE_DATABASE_BACKUP),
            "{$role} should be allowed to create a Database Backup"
        );
    }
    foreach (['cashier', 'inventory_manager'] as $role) {
        try {
            $backups->authorize($role);
            $assert(false, "{$role} must not be allowed to create a Database Backup");
        } catch (DomainException) {
            $assert(true, "{$role} was denied backup creation");
        }
    }
    $restorerForDenial = new DatabaseRestoreService($sourcePdo, $policy, $backups, $artifactDirectory);
    foreach (['admin', 'cashier', 'inventory_manager'] as $role) {
        $denied = false;
        try {
            $restorerForDenial->authorize($role);
        } catch (DomainException) {
            $denied = true;
        }
        $assert($denied, "{$role} must not be allowed to perform a Database Restore");
    }
    $restorerForDenial->authorize('super_admin');

    // Restoration runs against the separate disposable destination, never the
    // developer's Store database and never the captured source.
    $destinationBackups = new DatabaseBackupService($destinationPdo, $policy, $artifactDirectory, $destinationPdo);
    $restorer = new DatabaseRestoreService($destinationPdo, $policy, $destinationBackups, $artifactDirectory);

    // --- the write pause is a real database boundary ----------------------
    $writerPdo = $connect($config, $sourceName);
    $gateCoordinator = new BackupCoordinator($sourcePdo, $artifactDirectory, $sourcePdo);
    $gateKey = $gateCoordinator->claim($superId, 'super_admin');
    $gateCoordinator->beginCapture($superId);

    $refused = false;
    try {
        StoreWriteGate::begin($writerPdo);
        $writerPdo->rollBack();
    } catch (StoreWritePausedException $exception) {
        $refused = true;
        $assert(
            !\App\Support\OperatorAlert::isTechnicalMessage($exception->getMessage()),
            'A paused save must give the operator plain words, not a technical message'
        );
    }
    $assert($refused, 'A new save must not bypass the pause while a capture holds it');

    // An interrupted or failed capture must not leave a stale permanent pause.
    $gateCoordinator->abortCapture();
    $gateCoordinator->fail((string)$gateKey, 'test interruption');
    $resumed = true;
    try {
        StoreWriteGate::begin($writerPdo);
        $writerPdo->rollBack();
    } catch (Throwable) {
        $resumed = false;
    }
    $assert($resumed, 'A failed capture must make saving available again');

    // --- a mutation overlapping the capture is never partially represented --
    // A dedicated Product is written by a separate OS process, so the mutation
    // really does overlap the capture window. Each attempt writes a related
    // pair of rows (a stock movement and its stock level) in one transaction,
    // which is what makes a partially captured mutation detectable afterwards.
    $sourcePdo->prepare(
        "INSERT INTO products (sku, barcode, product_name, unit_price, cost_price, reorder_level, created_by)
         VALUES ('SKU-OVERLAP', 'BAR-OVERLAP', 'Overlap fixture', 1.00, 1.00, 0, ?)"
    )->execute([$superId]);
    $overlapProductId = (int)$sourcePdo->lastInsertId();
    $sourcePdo->prepare('INSERT INTO inventory (product_id, quantity_on_hand) VALUES (?, 0)')->execute([$overlapProductId]);

    $writerScript = dirname(__DIR__) . '/tests/support/backup_overlap_writer.php';
    $readyFile = $scratch . '/writer.ready';
    $goFile = $scratch . '/writer.go';
    $resultFile = $scratch . '/overlap.json';
    $writerConfigFile = $scratch . '/writer-config.php';
    $writerConfig = $config;
    $writerConfig['database'] = $sourceName;
    file_put_contents($writerConfigFile, '<?php return ' . var_export($writerConfig, true) . ';');

    $process = proc_open(
        [
            PHP_BINARY,
            $writerScript,
            $root,
            $writerConfigFile,
            $readyFile,
            $goFile,
            $resultFile,
            (string)$overlapProductId,
            '3',
            (string)$superId,
        ],
        [1 => ['file', $scratch . '/writer.out', 'w'], 2 => ['file', $scratch . '/writer.err', 'w']],
        $pipes
    );
    $assert(is_resource($process), 'The concurrent writer process should start');
    $readyDeadline = microtime(true) + 30;
    while (!is_file($readyFile) && microtime(true) < $readyDeadline) {
        usleep(10000);
    }
    $assert(is_file($readyFile), 'The concurrent writer should reach its starting line');
    file_put_contents($goFile, 'go');

    $overlapResult = $backups->create($ownerId, 'admin');
    $assert(
        ($overlapResult['status'] ?? '') === DatabaseBackupService::OUTCOME_READY,
        'A backup taken while another writer is active should still succeed'
    );
    $overlapArtifact = (string)($overlapResult['path'] ?? '');
    if (is_resource($process)) {
        proc_close($process);
    }

    $writerReport = json_decode((string)@file_get_contents($resultFile), true);
    $writerReport = is_array($writerReport) ? $writerReport : ['outcomes' => [], 'committed' => 0, 'paused' => 0];
    $outcomes = (array)$writerReport['outcomes'];
    $assert(
        array_filter($outcomes, static fn(string $o): bool => str_starts_with($o, 'error')) === [],
        'An overlapping writer must never fail with a technical error: ' . implode(',', $outcomes)
    );
    $assert(
        (int)$writerReport['paused'] > 0,
        'A mutation overlapping the capture must be told to retry, got: ' . implode(',', $outcomes)
    );
    $assert(
        (int)$writerReport['committed'] === 3,
        'Every overlapping mutation must eventually be committed, got: ' . implode(',', $outcomes)
    );
    $assert(
        (int)$sourcePdo->query('SELECT COUNT(*) FROM stock_movements WHERE product_id = ' . $overlapProductId)->fetchColumn() === 3
        && (int)$sourcePdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id = ' . $overlapProductId)->fetchColumn() === 3,
        'The Store must end up with all overlapping movements and matching stock levels'
    );

    // --- create the backup as the Administrator ---------------------------
    $result = $overlapResult;
    $assert(
        ($result['status'] ?? '') === DatabaseBackupService::OUTCOME_READY,
        'The Administrator should be able to create a Database Backup, got: ' . json_encode($result)
    );
    $artifactPath = (string)($result['path'] ?? '');
    $assert(is_file($artifactPath), 'An encrypted temporary artifact should exist for delivery');

    // --- the artifact is not readable by opening it ------------------------
    $raw = (string)file_get_contents($artifactPath);
    $assert(str_starts_with($raw, EncryptedBackupEnvelope::MAGIC . "\n"), 'The artifact must carry the versioned envelope identifier');
    $assert(!str_contains($raw, 'INSERT INTO'), 'The encrypted artifact must not expose readable SQL');
    $assert(!str_contains($raw, 'Kape jelly'), 'The encrypted artifact must not expose Store record contents');
    $assert(!str_contains($raw, $restoreKey), 'The recovery key must never appear in the artifact');
    $assert(!str_contains($result['filename'], $restoreKey), 'Key material must not appear in download metadata');
    $assert(str_ends_with($result['filename'], '.' . EncryptedBackupEnvelope::EXTENSION), 'The download should use the encrypted extension');

    // --- missing key configuration fails closed, never a plaintext copy ---
    putenv('BACKUP_ENCRYPTION_KEY');
    unset($_ENV['BACKUP_ENCRYPTION_KEY'], $_SERVER['BACKUP_ENCRYPTION_KEY']);
    $failedClosed = false;
    try {
        $backups->create($superId, 'super_admin');
    } catch (Throwable $exception) {
        $failedClosed = true;
    }
    $assert($failedClosed, 'A backup without recovery key configuration must fail closed');
    $assert(
        $backups->keyStatus()->isConfigured() === false,
        'The deployment must report that no recovery key is configured'
    );
    foreach ($backups->coordinator()->artifactFiles() as $leftover) {
        $assert(
            !str_ends_with($leftover, '.sql'),
            'A failed closed backup must never leave a readable capture behind'
        );
    }
    putenv('BACKUP_ENCRYPTION_KEY=' . $restoreKey);
    $_ENV['BACKUP_ENCRYPTION_KEY'] = $restoreKey;
    $_SERVER['BACKUP_ENCRYPTION_KEY'] = $restoreKey;
    $assert($backups->keyStatus()->isConfigured() === true, 'A configured recovery key must be recognised');

    // --- the pause is released and the Store accepts writes again ----------
    $status = $backups->status();
    $assert(($status['paused'] ?? true) === false, 'Saving must be available once capture no longer needs the pause');
    $insertUser->execute(['Post Backup', 'post_backup_' . bin2hex(random_bytes(3)), password_hash('Fixture@2026', PASSWORD_DEFAULT), (int)$sourcePdo->query("SELECT role_id FROM roles WHERE role_name = 'cashier'")->fetchColumn()]);
    $assert($sourcePdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 3, 'A write after capture must be admitted normally');

    // --- overlapping requests cannot both capture -------------------------
    $claim = $coordinator->claim($superId, 'super_admin');
    $assert($claim !== null, 'A fresh operation should be claimable while idle');
    $assert($coordinator->claim($superId, 'super_admin') === null, 'A second overlapping request must not claim the active slot');
    $assert($backups->status()['paused'] === true, 'An active operation must be visible to active sessions');

    $inProgress = $backups->create($superId, 'super_admin');
    $assert(
        ($inProgress['status'] ?? '') === DatabaseBackupService::OUTCOME_IN_PROGRESS,
        'An overlapping backup request must report already-in-progress, got: ' . json_encode($inProgress)
    );
    $assert(
        str_contains((string)($inProgress['message'] ?? ''), 'already being prepared'),
        'The already-in-progress outcome must be understandable'
    );
    $coordinator->fail((string)$claim, 'test cleanup');
    $assert(!$coordinator->isOperationActive(), 'Finishing an operation must free the active slot');
    $assert($backups->status()['paused'] === false, 'Saving must be available again once the operation finishes');

    // --- history reflects the real creation outcome ------------------------
    $ownerHistory = $backups->history('admin');
    $assert($ownerHistory !== [], 'The Administrator should see limited backup history');
    $assert(
        ($ownerHistory[0]['status'] ?? '') === 'completed' && ($ownerHistory[0]['requested_by'] ?? '') === 'Owner Fixture',
        'A completed Administrator backup must appear with its requester'
    );
    $assert(
        str_contains((string)$ownerHistory[0]['notes'], 'does not confirm the file was saved'),
        'History must distinguish creation from guaranteed local retention'
    );
    $assert(
        str_contains(strtoupper((string)$ownerHistory[0]['format']), 'AES-256-GCM'),
        'History must record the encrypted envelope format'
    );
    $superHistory = $backups->history('super_admin');
    $assert(
        count($superHistory) >= count($ownerHistory),
        'The Super Administrator sees at least the Administrator-visible history'
    );

    // --- the temporary download is bound to its requester ------------------
    $wrongRequester = false;
    try {
        $backups->resolveDownload((string)$result['token'], $superId, 'super_admin');
    } catch (DomainException) {
        $wrongRequester = true;
    }
    $assert($wrongRequester, 'Another administrator must not download someone else’s backup');
    $resolved = $backups->resolveDownload((string)$result['token'], $ownerId, 'admin');
    $assert($resolved['path'] === $artifactPath, 'The requester must resolve their own backup artifact');
    $guessed = false;
    try {
        $backups->resolveDownload(str_repeat('a', 48), $ownerId, 'admin');
    } catch (DomainException) {
        $guessed = true;
    }
    $assert($guessed, 'A guessed token must not resolve to any artifact');

    // --- restore round trip into the separate destination -----------------
    $restoreTarget = (string)file_get_contents($resolved['path']);
    file_put_contents($artifactDirectory . '/roundtrip.rmbak', $restoreTarget);
    $restorePath = $artifactDirectory . '/roundtrip.rmbak';

    // A wrong key must be rejected before any destination change.
    $wrongKeyProvider = new BackupKeyProvider(static fn(string $name): ?string => bin2hex(random_bytes(32)));
    $rejected = false;
    try {
        (new EncryptedBackupEnvelope($wrongKeyProvider))->openFile($restorePath);
    } catch (Throwable) {
        $rejected = true;
    }
    $assert($rejected, 'A wrong recovery key must be rejected');
    $assert(
        (int)$destinationPdo->query('SELECT COUNT(*) FROM products')->fetchColumn() === $destinationProductsBefore,
        'A wrong-key attempt must not change the destination database'
    );

    // Truncated and tampered artifacts must also be rejected before any change.
    file_put_contents($artifactDirectory . '/truncated.rmbak', substr($restoreTarget, 0, max(1, intdiv(strlen($restoreTarget), 2))));
    $truncatedRejected = false;
    try {
        (new EncryptedBackupEnvelope(new BackupKeyProvider()))->openFile($artifactDirectory . '/truncated.rmbak');
    } catch (Throwable) {
        $truncatedRejected = true;
    }
    $assert($truncatedRejected, 'A truncated backup must be rejected');

    $tampered = $restoreTarget;
    $tampered[strlen($tampered) - 5] = $tampered[strlen($tampered) - 5] === "\x00" ? "\x01" : "\x00";
    file_put_contents($artifactDirectory . '/tampered.rmbak', $tampered);
    $tamperedRejected = false;
    try {
        (new EncryptedBackupEnvelope(new BackupKeyProvider()))->openFile($artifactDirectory . '/tampered.rmbak');
    } catch (Throwable) {
        $tamperedRejected = true;
    }
    $assert($tamperedRejected, 'Modified encrypted content must be rejected');

    $assert(
        (int)$destinationPdo->query('SELECT COUNT(*) FROM products')->fetchColumn() === $destinationProductsBefore,
        'Rejected recovery attempts must leave the destination database untouched'
    );

    // Evidence that postdates the snapshot must survive the restore.
    $destinationPdo->exec(
        "INSERT INTO activity_log (user_id, action, category, module, metadata)
         VALUES (NULL, 'Recovery drill recorded after the snapshot', 'recovery', 'Backup & Restore', NULL)"
    );
    $preservedLogId = (int)$destinationPdo->lastInsertId();

    $restoreResult = $restorer->restore($superId, 'super_admin', $restorePath, 'roundtrip.rmbak');
    $assert(($restoreResult['statements'] ?? 0) > 0, 'The restore must execute the captured statements');
    $assert(
        (int)$restoreResult['preserved'] >= 1,
        'Current Protected Audit Records must be preserved before destructive statements'
    );
    $assert(
        (int)$destinationPdo->query('SELECT COUNT(*) FROM activity_log WHERE log_id = ' . $preservedLogId)->fetchColumn() === 1,
        'Protected Audit Records that postdate the snapshot must survive a restore'
    );

    // --- recovered business state, not generated SQL text -----------------
    $recoveredProduct = $destinationPdo->query('SELECT * FROM products WHERE product_id = ' . $productId)->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($recoveredProduct), 'The captured Product must be recoverable');
    $assert(($recoveredProduct['product_name'] ?? '') === 'Kape jelly', 'Product text must round trip');
    $assert(
        (int)$destinationPdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id = ' . $productId)->fetchColumn() === 42,
        'Stock levels must round trip with the Product'
    );
    $items = $destinationPdo->query('SELECT * FROM sale_items WHERE sale_id = ' . $saleId . ' ORDER BY sale_item_id')->fetchAll(PDO::FETCH_ASSOC);
    $assert(count($items) === 1, 'Every captured Sale Item must be recoverable');
    $assert((int)$items[0]['quantity'] === 3, 'Sale Item quantities must round trip');
    $assert(
        (int)$destinationPdo->query('SELECT COUNT(*) FROM sales WHERE sale_id = ' . $saleId)->fetchColumn() === 1,
        'The captured Sale must be recoverable'
    );
    $recoveredNote = $destinationPdo->query('SELECT new_value FROM activity_log WHERE log_id = ' . $multilineLogId)->fetchColumn();
    $assert(
        $recoveredNote === "O'Brien \"said no\"\nsecond line — ünïcode\ttab",
        'Quoted, multiline, and Unicode content must round trip unchanged, got: ' . var_export($recoveredNote, true)
    );
    $assert(
        $destinationPdo->query('SELECT brand FROM products WHERE product_id = ' . $productId)->fetchColumn() === null,
        'A null column must round trip as NULL, not as an empty string'
    );
    $recoveredSupplier = $destinationPdo->query('SELECT supplier FROM products WHERE product_id = ' . $productId)->fetchColumn();
    $assert(
        $recoveredSupplier === "O'Brien \"Supplier\"\nsecond line — ünïcode",
        'Quoted and multiline text in a column must round trip, got: ' . var_export($recoveredSupplier, true)
    );
    $assert(
        (int)$destinationPdo->query('SELECT COUNT(*) FROM users WHERE user_id IN (' . implode(',', [$ownerId, $superId, $actors['cashier']]) . ')')->fetchColumn() === 3,
        'Accounts must round trip'
    );
    $assert(
        (int)$destinationPdo->query('SELECT COUNT(*) FROM products')->fetchColumn() === (int)$sourcePdo->query('SELECT COUNT(*) FROM products')->fetchColumn(),
        'The restored database must hold the captured number of Products'
    );

    // The restore outcome is recorded as restricted recovery detail.
    $restoreRow = $destinationPdo->query("SELECT * FROM backup_history WHERE backup_type = 'restore' ORDER BY backup_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($restoreRow) && $restoreRow['status'] === 'completed', 'The restore outcome must be recorded');
    $recoveryAudit = $destinationPdo->query("SELECT COUNT(*) FROM activity_log WHERE action = 'Database restore' AND category = 'recovery'")->fetchColumn();
    $assert((int)$recoveryAudit >= 1, 'The recovery event must be recorded with restricted visibility');

    // Administrator history must never expose restricted recovery detail.
    $adminAfterRestore = $destinationBackups->history('admin');
    foreach ($adminAfterRestore as $row) {
        $assert(
            ($row['backup_type'] ?? '') !== 'restore',
            'Administrator history must not include restore rows'
        );
    }

    // The overlapping mutation must appear in the recovered state entirely
    // before or entirely after the snapshot, never as half of a movement.
    $restoredMovements = (int)$destinationPdo->query('SELECT COUNT(*) FROM stock_movements WHERE product_id = ' . $overlapProductId)->fetchColumn();
    $restoredLevel = (int)$destinationPdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id = ' . $overlapProductId)->fetchColumn();
    $assert(
        $restoredMovements === $restoredLevel,
        "A mutation overlapping the snapshot must be represented entirely before or after it, never partially (movements={$restoredMovements}, level={$restoredLevel})"
    );
    $assert(
        $restoredMovements >= 0 && $restoredMovements <= 3,
        'The recovered state must contain a whole number of overlapping mutations'
    );

    // --- cleanup after delivery -------------------------------------------
    $backups->discardAfterDelivery($artifactPath);
    $assert(!is_file($artifactPath), 'A delivered temporary artifact must be removed from the server');

    $stranded = $artifactDirectory . '/stranded.rmbak';
    file_put_contents($stranded, 'RMBAK1');
    touch($stranded, time() - 7200);
    $assert($backups->cleanupStrandedArtifacts() >= 1, 'Bounded cleanup must remove an interrupted request’s artifact');
    $assert(!is_file($stranded), 'The stranded artifact must be gone after cleanup');
    $assert(is_file($restorePath), 'Cleanup must not delete a file that is still being delivered');
} catch (Throwable $exception) {
    $failures[] = 'Database Backup workflow integration threw: ' . $exception->getMessage();
} finally {
    if (isset($envBackupKey) && $envBackupKey !== false) {
        putenv('BACKUP_ENCRYPTION_KEY=' . $envBackupKey);
        $_ENV['BACKUP_ENCRYPTION_KEY'] = $envBackupKey;
    } else {
        putenv('BACKUP_ENCRYPTION_KEY');
        unset($_ENV['BACKUP_ENCRYPTION_KEY'], $_SERVER['BACKUP_ENCRYPTION_KEY']);
    }
    if ($serverPdo instanceof PDO) {
        foreach ([$sourceName, $destinationName] as $name) {
            try {
                $serverPdo->exec('DROP DATABASE IF EXISTS `' . $name . '`');
            } catch (Throwable) {
                // A leftover disposable fixture must never fail the run.
            }
        }
    }
    if (is_dir($scratch)) {
        foreach (glob($scratch . '/*/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($artifactDirectory);
        @rmdir($scratch);
    }
}

if ($failures) {
    fwrite(STDERR, "Database Backup workflow integration failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Database Backup workflow integration: passed\n";
