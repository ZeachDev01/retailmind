<?php
// Explicit development-only rebuild. Never call from a migration or scheduled job.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once dirname(__DIR__) . '/bootstrap/app.php';
require_once dirname(__DIR__) . '/app/Services/NotificationService.php';
$options = getopt('', ['database:', 'confirm-development', 'apply']);
$config = require dirname(__DIR__) . '/config/database.php';
$database = $options['database'] ?? '';
// Never the configured Store database, even on a local host.
$configured = (string)($config['database'] ?? '');
if (!preg_match('/^[a-zA-Z0-9_]+$/D', $database) || !isset($options['confirm-development'])
    || !in_array($config['host'], ['localhost', '127.0.0.1', '::1'], true)
    || ($configured !== '' && strcasecmp($database, $configured) === 0)) {
    fwrite(STDERR, "Name a local development target other than the configured Store database: --database=NAME --confirm-development [--apply]\n");
    exit(1);
}
$config['database'] = $database;
$pdo = App\Core\Database::connection($config);
echo "Development notification target: {$config['host']}:{$config['port']}/{$database}\n";
// Restrict deletion to known generated families; preserve unrelated history and every workflow update.
$generated = "(type IN ('low_stock','expiring_stock','expired_stock','attention')
    OR reference_type = 'model_training' OR (type = 'system' AND title LIKE 'Unusual demand forecast:%'))
    AND (reference_type IS NULL OR reference_type <> 'stock_issue')";
echo 'Generated alerts to rebuild: ' . $pdo->query("SELECT COUNT(*) FROM notifications WHERE $generated")->fetchColumn() . "\n";
if (!isset($options['apply'])) exit;
set_exception_handler(static function (Throwable $exception) use ($pdo): void {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, (string)$exception . "\n");
    exit(1);
});
$pdo->beginTransaction();
$pdo->exec("DELETE FROM notifications WHERE $generated");
$pdo->exec("DELETE FROM attention_states WHERE attention_key LIKE 'platform.%' OR attention_key LIKE 'store.%'
    OR attention_key LIKE 'inventory.%' OR attention_key LIKE 'oversight.%' OR attention_key LIKE 'platform-training.%'");
$clock = new App\Attention\SystemClock();
$notifications = new NotificationService($pdo, $clock);
$notifications->checkAndNotifyLowStock();
$notifications->checkAndNotifyExpiringStock();
$notifications->checkAndNotifyForecasts();
$source = new App\Attention\DatabaseAttentionSignalSource($pdo, $clock);
$center = new App\Attention\AttentionCenter(
    new App\Attention\AttentionRuleService(new App\Attention\AttentionSettingsService($pdo, $clock), $clock),
    new App\Attention\AttentionNotificationService($pdo, $clock));
require_once dirname(__DIR__) . '/app/Services/SystemHealthService.php';
$signals = ['super_admin' => $source->platform((new SystemHealthService($pdo, dirname(__DIR__)))->checks()), 'admin' => $source->store()];
foreach ($signals as $role => $values) {
    foreach ($notifications->recipients($role) as $recipient) {
        $center->refresh((int)$recipient['user_id'], $role, $values, (bool)($recipient['notify_inapp'] ?? true));
    }
}
foreach ($notifications->recipients('inventory_manager') as $recipient) {
    $pending = $pdo->prepare("SELECT ia.adjustment_id FROM inventory_adjustments ia WHERE ia.status = 'pending'
        AND NOT EXISTS (SELECT 1 FROM notifications n WHERE n.user_id = ? AND n.workspace = 'inventory_manager'
            AND n.reference_type = 'stock_issue' AND n.reference_id = ia.adjustment_id)");
    $pending->execute([(int)$recipient['user_id']]);
    foreach ($pending->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $notifications->createNotification((int)$recipient['user_id'], 'adjustment', "Pending Stock Issue #{$id}",
            'A Stock Issue awaits Inventory Manager review.', (int)$id, 'stock_issue', 'inventory_manager');
    }
}
// Regenerated alerts describe conditions the Store already knows about: nothing is re-emailed.
$pdo->exec('UPDATE notifications SET email_pending = 0');
$pdo->commit();
echo "Development notifications rebuilt; operational records preserved.\n";
