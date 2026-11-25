<?php

if (PHP_SAPI !== 'cli' || getenv('RUN_DB_TESTS') !== '1') {
    exit("GCash recording upgrade: skipped (RUN_DB_TESTS=1 required)\n");
}
require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../includes/functions.php';

$database = $argv[1] ?? '';
if (!preg_match('/\Aretailmind_theme_test_[a-f0-9]{12}\z/', $database)) {
    throw new RuntimeException('Disposable database required.');
}
$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO("mysql:host={$config['host']};port={$config['port']};dbname={$database};charset=utf8mb4",
    $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// Reproduce the prior schema with genuinely approved historical fixture movements.
$pdo->exec('UPDATE gcash_funding_movements SET authorized_by=1 WHERE authorized_by IS NULL');
$pdo->exec('ALTER TABLE gcash_funding_movements MODIFY authorized_by INT NOT NULL');
$pdo->exec('ALTER TABLE gcash_transactions DROP COLUMN customer_name, DROP COLUMN customer_gcash_number');
$pdo->exec('ALTER TABLE gcash_service_sessions DROP COLUMN wallet_number');
$service = new App\Services\GCashTransactionService($pdo);
$before = $service->getMoneyHistory(['funding_only' => true]);
$historyBefore = $service->history(4, 1)['transactions'];
$migration = require __DIR__ . '/../database/migrations/202611250002_gcash_cashier_recording.php';
$migration['up']($pdo);
$migration['up']($pdo);
if ($service->getMoneyHistory(['funding_only' => true]) !== $before) {
    throw new RuntimeException('Upgrade changed historical money movements or approvals.');
}
$after = $service->history(4, 1)['transactions'];
foreach ($after as &$transaction) {
    if ($transaction['customer_name'] !== null || $transaction['customer_gcash_number'] !== null) {
        throw new RuntimeException('Upgrade invented customer details for an older exchange.');
    }
    unset($transaction['customer_name'], $transaction['customer_gcash_number']);
}
unset($transaction);
if ($after !== $historyBefore || $service->getCurrentSession()['wallet_number'] !== null) {
    throw new RuntimeException('Upgrade changed exchange history or invented a historical wallet number.');
}
echo "GCash recording upgrade: passed (repeatable, historical approvals and exchanges preserved, unknown details stay empty)\n";
