<?php
declare(strict_types=1);

// Never run destructive verification against the configured Store database.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
if (getenv('RUN_DB_TESTS') !== '1') { echo "Development notification rebuild MySQL: skipped (RUN_DB_TESTS=1 required)\n"; exit; }
require_once __DIR__ . '/../bootstrap/app.php';
set_exception_handler(static function (Throwable $exception): void { fwrite(STDERR, (string)$exception . "\n"); exit(1); });
$database = 'retailmind_theme_test_' . bin2hex(random_bytes(6));
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$fixture = static function (string $action) use ($database): void {
    $output = [];
    exec(implode(' ', array_map('escapeshellarg', [PHP_BINARY, __DIR__ . '/support/theme_fixture.php', $action, $database])) . ' 2>&1', $output, $code);
    if ($code !== 0) throw new RuntimeException(implode("\n", $output));
};
$rebuild = static function (array $arguments) use ($database): array {
    $output = [];
    exec(implode(' ', array_map('escapeshellarg', array_merge(
        [PHP_BINARY, __DIR__ . '/../scripts/rebuild_development_notifications.php'],
        $arguments
    ))) . ' 2>&1', $output, $code);
    return [$code, implode("\n", $output)];
};
$rebuildWithEnvironment = static function (array $arguments, array $environment): array {
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $pipes = [];
    $process = proc_open(array_merge([PHP_BINARY, __DIR__ . '/../scripts/rebuild_development_notifications.php'], $arguments), $descriptors, $pipes, null, $environment);
    if (!is_resource($process)) throw new RuntimeException('Unable to start the rebuild command.');
    $output = (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $output];
};
try {
    $fixture('create');
    $config = require __DIR__ . '/../config/database.php';
    $pdo = new PDO("mysql:host={$config['host']};port={$config['port']};dbname={$database};charset=utf8mb4",
        $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec("SET time_zone = '+08:00'");
    $count = static fn(string $sql): int => (int)$pdo->query($sql)->fetchColumn();
    $generated = static fn(string $where): int => (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE {$where}")->fetchColumn();
    $operational = static function () use ($pdo): array {
        $state = [];
        foreach (['notifications', 'attention_states', 'inventory_adjustments', 'sales', 'inventory', 'stock_movements', 'product_batches'] as $table) {
            $state[$table] = (int)$pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        }
        return $state;
    };

    // Escalate the base pending Stock Issue so Administrator oversight regenerates deterministically.
    $pdo->exec("UPDATE inventory_adjustments SET reported_at='2026-01-01 00:00:00' WHERE adjustment_id=1");
    // Misrouted generated alerts: routine low stock filed under the Administrator Workspace and an
    // unknown Workspace row that the completed Workspace rules can never produce.
    $pdo->exec("INSERT INTO notifications (user_id,type,title,message,workspace,reference_type,reference_id) VALUES
        (1,'low_stock','Low Stock Alert: Misrouted','Filed under the wrong Workspace.','admin','product',1),
        (1,'attention','Misrouted attention summary','Filed under an unknown Workspace.','bogus_workspace',NULL,NULL)");
    // A valid reporting-Cashier Stock Issue workflow update (returned report) must survive cleanup.
    $pdo->exec("INSERT INTO inventory_adjustments (product_id,adjustment_qty,adjustment_type,reported_by,reason,status)
        VALUES (1,1,'damaged',4,'Ticket 268 returned Stock Issue history','returned')");
    $workflowAdjustment = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO notifications (user_id,type,title,message,workspace,reference_type,reference_id,inapp_enabled)
        VALUES (4,'adjustment','Returned Stock Issue #{$workflowAdjustment}','Correct and resubmit your report.','cashier','stock_issue',{$workflowAdjustment},1)");
    // Unrelated operational and history sentinels.
    $pdo->exec("INSERT INTO sales (sale_id,cashier_id,shift_id,total_amount,payment_method) VALUES (5,4,1,25,'cash')");
    $pdo->exec("INSERT INTO notifications (user_id,type,title,message,workspace) VALUES (1,'system','Preserved operational note','Unrelated to generated alerts.','admin')");
    $before = $operational();

    [$code, $output] = $rebuild(['--database=' . $database]);
    $assert($code !== 0 && str_contains($output, 'Name a local development target'),
        'Rebuild refuses a target that omits --confirm-development: ' . $output);
    $assert($operational() === $before, 'Refusal without an explicit confirmation mutates nothing');

    $environment = getenv();
    $environment['DB_HOST'] = 'db.ticket268.example.com';
    [$code, $output] = $rebuildWithEnvironment(['--database=' . $database, '--confirm-development'], $environment);
    $assert($code !== 0 && str_contains($output, 'Name a local development target'),
        'Rebuild refuses a non-local database host: ' . $output);
    $assert($operational() === $before, 'Refusal on a non-local host mutates nothing');

    [$code, $output] = $rebuild(['--database=' . (string)$config['database'], '--confirm-development']);
    $assert($code !== 0 && str_contains($output, 'Name a local development target'),
        'Rebuild refuses the configured Store database: ' . $output);
    $assert($operational() === $before, 'Refusal on the configured Store database mutates nothing');

    [$code, $output] = $rebuild(['--database=' . $database, '--confirm-development', '--apply']);
    $assert($code === 0 && str_contains($output, 'operational records preserved'),
        'Rebuild applies against the explicitly identified local development database: ' . $output);

    $assert($generated("title='Low Stock Alert: Misrouted'") === 0,
        'Misrouted low-stock generated alert filed under the Administrator Workspace is removed');
    $assert($generated("workspace='bogus_workspace'") === 0,
        'Misrouted unknown-Workspace generated alert is removed');

    $assert($generated("user_id=4 AND reference_type='stock_issue' AND reference_id={$workflowAdjustment} AND title='Returned Stock Issue #{$workflowAdjustment}'") === 1,
        'Valid reporting-Cashier Stock Issue workflow update is preserved');
    $assert($count('SELECT COUNT(*) FROM sales WHERE sale_id=5') === 1, 'Sales records are never modified');
    $assert($count('SELECT COUNT(*) FROM inventory WHERE product_id=1 AND quantity_on_hand=10') === 1, 'Inventory records are never modified');
    $assert($generated("title='Preserved operational note'") === 1, 'Unrelated Notification history is preserved');

    $assert($generated("type='low_stock' AND workspace='inventory_manager' AND reference_id=1") > 0,
        'Current low-stock condition regenerates in the Inventory Manager Workspace');
    $assert($generated("type='low_stock' AND workspace='admin'") === 0,
        'Routine low stock never regenerates in the Administrator Workspace');
    $assert($generated("type='adjustment' AND reference_type='stock_issue' AND reference_id=1 AND workspace='inventory_manager'") > 0,
        'Pending Stock Issue regenerates for Inventory Manager review');
    $assert($generated("type='attention' AND workspace='admin' AND attention_key='store.inventory.issue.1'") > 0,
        'Escalated Stock Issue regenerates Administrator oversight');
    $assert($generated("type='attention' AND workspace='super_admin' AND attention_key LIKE 'platform.%'") > 0,
        'Platform conditions regenerate in the Super Administrator Workspace');

    $assert($count("SELECT COUNT(*) FROM (SELECT user_id, attention_key FROM notifications
        WHERE attention_key IS NOT NULL GROUP BY user_id, attention_key HAVING COUNT(*) > 1) duplicates") === 0,
        'No duplicate current attention alerts exist');
    $assert($count("SELECT COUNT(*) FROM (SELECT user_id, reference_id FROM notifications
        WHERE type='low_stock' GROUP BY user_id, reference_id HAVING COUNT(*) > 1) duplicates") === 0,
        'No duplicate current low-stock alerts exist');
    $assert($count("SELECT COUNT(*) FROM (SELECT user_id, reference_id FROM notifications
        WHERE reference_type='stock_issue' GROUP BY user_id, reference_id HAVING COUNT(*) > 1) duplicates") === 0,
        'No duplicate current Stock Issue alerts exist');

    $signature = static fn(): string => json_encode($pdo->query(
        'SELECT type, workspace, reference_type, COUNT(*) AS total FROM notifications
         GROUP BY type, workspace, reference_type ORDER BY type, workspace, reference_type'
    )->fetchAll());
    $firstSignature = $signature();

    [$code, $output] = $rebuild(['--database=' . $database, '--confirm-development', '--apply']);
    $assert($code === 0, 'A second rebuild run succeeds: ' . $output);
    $assert($signature() === $firstSignature, 'A second run regenerates the same current conditions and preserves history');
    $assert($count("SELECT COUNT(*) FROM (SELECT user_id, attention_key FROM notifications
        WHERE attention_key IS NOT NULL GROUP BY user_id, attention_key HAVING COUNT(*) > 1) duplicates") === 0,
        'A second run creates no duplicate current attention alerts');
    $assert($count("SELECT COUNT(*) FROM (SELECT user_id, reference_id FROM notifications
        WHERE type='low_stock' GROUP BY user_id, reference_id HAVING COUNT(*) > 1) duplicates") === 0,
        'A second run creates no duplicate current low-stock alerts');
    $assert($count("SELECT COUNT(*) FROM (SELECT user_id, reference_id FROM notifications
        WHERE reference_type='stock_issue' GROUP BY user_id, reference_id HAVING COUNT(*) > 1) duplicates") === 0,
        'A second run creates no duplicate current Stock Issue alerts');
    $assert($generated('email_pending = 1') === 0, 'Regenerated alerts are not queued for a replayed daily email');
    $assert($count('SELECT COUNT(*) FROM email_delivery_log') === 0, 'Rebuild never sends email');
    $assert($generated("user_id=4 AND reference_type='stock_issue' AND reference_id={$workflowAdjustment}") === 1,
        'Stock Issue workflow update survives the second run');
    $assert($count('SELECT COUNT(*) FROM sales WHERE sale_id=5') === 1, 'Sales records survive the second run');
    $assert($generated("title='Preserved operational note'") === 1, 'Unrelated Notification history survives the second run');

    echo "Development notification rebuild MySQL: passed\n";
} finally {
    $pdo = null;
    $fixture('cleanup');
}
