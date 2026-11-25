<?php
require_once __DIR__ . '/../bootstrap/app.php';

use App\Audit\ProtectedAuditRecordService;
use App\Authorization\RoleCapabilityPolicy;

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE users (user_id INTEGER PRIMARY KEY, is_recovery_account INTEGER)');
$pdo->exec('CREATE TABLE activity_log (user_id INTEGER, category TEXT, module TEXT)');
$pdo->exec('INSERT INTO users VALUES (1, 0), (2, 1)');
$pdo->exec("INSERT INTO activity_log VALUES
    (1, 'store_operation', 'Inventory'),
    (1, 'store_operation', 'Inventory'),
    (NULL, 'store_operation', 'Sales'),
    (1, 'security', 'Authentication'),
    (2, 'store_operation', 'Recovery actor'),
    (NULL, 'store_operation', 'Recovery Account'),
    (1, 'store_operation', ''),
    (1, 'store_operation', NULL)");
$service = new ProtectedAuditRecordService($pdo, new RoleCapabilityPolicy());
$pdo->exec("ALTER TABLE activity_log ADD COLUMN action TEXT DEFAULT 'Record changed'");
$pdo->exec("UPDATE activity_log SET action = 'Stock count corrected' WHERE module = 'Inventory'");
$pdo->exec("UPDATE activity_log SET action = 'Signed in' WHERE module = 'Authentication'");
$pdo->exec("UPDATE activity_log SET action = 'Recovery only' WHERE user_id = 2 OR module = 'Recovery Account'");
if ($service->actions('admin') !== ['Record changed', 'Stock count corrected']) {
    throw new RuntimeException('Administrator action choices must exclude security and recovery records.');
}
if ($service->actions('admin', ['module' => 'Inventory']) !== ['Stock count corrected']) {
    throw new RuntimeException('Action choices must narrow to the selected module without duplicates.');
}
if ($service->actions('super_admin', ['category' => 'security']) !== ['Signed in']) {
    throw new RuntimeException('Super Administrator action choices must narrow to the selected category.');
}
if ($service->modules('super_admin', ['category' => 'security']) !== ['Authentication']) {
    throw new RuntimeException('Module choices must narrow to the selected category.');
}
try {
    $service->actions('cashier');
    throw new RuntimeException('Cashiers must not access audit action choices.');
} catch (DomainException $exception) {
}
if ($service->modules('admin') !== ['Inventory', 'Sales']) {
    throw new RuntimeException('Administrator modules must include only permitted Store operations.');
}
if ($service->modules('super_admin') !== ['Authentication', 'Inventory', 'Recovery Account', 'Recovery actor', 'Sales']) {
    throw new RuntimeException('Super Administrator modules must include all nonempty modules.');
}
$pdo->exec("INSERT INTO activity_log VALUES
    (1, 'store_operation', 'Inventory', 'Received shopping entry: Rice: 10 accepted units'),
    (1, 'store_operation', 'Inventory', 'Received shopping entry: Beans: 20 accepted units'),
    (1, 'store_operation', NULL, 'Added category Rice'),
    (1, 'store_operation', NULL, 'Added category Beans'),
    (1, 'store_operation', 'Sales', 'Cash refund issued'),
    (1, 'store_operation', 'Sales', 'Cash refund issued for review'),
    (2, 'store_operation', 'Inventory', 'Received shopping entry: Recovery stock'),
    (1, 'security', 'Inventory', 'Received shopping entry: Restricted stock')");
if ($service->actions('admin') !== ['Cash refund issued', 'Cash refund issued for review', 'Category added', 'Record changed', 'Stock count corrected', 'Stock received']) {
    throw new RuntimeException('Administrator options must group known variants and retain every other visible action.');
}
$inventory = ['module' => 'Inventory'];
if ($service->actions('admin', $inventory) !== ['Stock count corrected', 'Stock received']
    || $service->count('admin', $inventory + ['action_type' => 'Stock received']) !== 2
    || $service->count('admin', $inventory + ['action' => 'Received shopping entry: Rice']) !== 1
    || $service->count('admin', $inventory + ['action_type' => '']) !== 4
    || $service->count('admin', ['action_type' => 'Cash refund issued']) !== 1) {
    throw new RuntimeException('Action type filtering must match the available choices and preserve raw action links and All actions.');
}
if (!in_array('Received shopping entry: Rice: 10 accepted units', $service->actions('super_admin', $inventory), true)) {
    throw new RuntimeException('Protected Audit Records must retain their existing raw action options.');
}
try {
    $service->modules('cashier');
    throw new RuntimeException('Cashiers must not access audit modules.');
} catch (DomainException $exception) {
    // Expected: the module list obeys the same audit capability check as records.
}
echo "Audit modules tests: passed\n";
