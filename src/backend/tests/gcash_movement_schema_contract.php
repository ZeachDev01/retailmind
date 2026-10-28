<?php
require_once __DIR__ . '/../bootstrap/app.php';

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE gcash_adjustments (adjustment_id INTEGER PRIMARY KEY, transaction_id INTEGER, session_id INTEGER, service_cash_delta REAL DEFAULT 0, wallet_delta REAL DEFAULT 0)');
$pdo->exec('CREATE TABLE gcash_funding_movements (movement_id INTEGER PRIMARY KEY, session_id INTEGER, movement_type TEXT, recorded_by INTEGER)');
$pdo->exec('INSERT INTO gcash_adjustments VALUES (1, 1, 1, 10, 0)');
$migration = require __DIR__ . '/../database/migrations/202610280005_gcash_movement_evidence.php';
$migration['up']($pdo);
$migration['up']($pdo);
$check = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$row = $pdo->query('SELECT correction_mode, fee_delta, evidence FROM gcash_adjustments WHERE adjustment_id=1')->fetch(PDO::FETCH_ASSOC);
$check($row['correction_mode'] === 'money_movement' && (float)$row['fee_delta'] === 0.0 && $row['evidence'] === null, 'Existing adjustments retain money effects without invented evidence');
$pdo->exec("INSERT INTO gcash_funding_movements (movement_id, session_id, movement_type, recorded_by, received_by, receipt_confirmed, linked_session_id) VALUES (1,2,'shortage_recovery',3,3,1,1)");
$check((int)$pdo->query('SELECT linked_session_id FROM gcash_funding_movements')->fetchColumn() === 1, 'Approved recovery can persist receipt attribution and its historical link');
foreach (['schema.sql', 'update.sql'] as $file) {
    $sql = file_get_contents(__DIR__ . '/../database/sql/' . $file);
    foreach (['correction_mode', 'fee_delta', 'evidence', 'received_by', 'receipt_confirmed', 'linked_session_id', 'shortage_recovery'] as $column) {
        $check(str_contains($sql, $column), "{$file} must support {$column}");
    }
}
$runtime = file_get_contents(__DIR__ . '/../includes/operational_schema.php');
foreach (['correction_mode', 'fee_delta', 'evidence', 'received_by', 'receipt_confirmed', 'linked_session_id', 'shortage_recovery'] as $column) {
    $check(str_contains($runtime, $column), "Runtime upgrades must support {$column}");
}
echo "GCash movement schema contract: passed\n";
