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
if ($service->modules('admin') !== ['Inventory', 'Sales']) {
    throw new RuntimeException('Administrator modules must include only permitted Store operations.');
}
if ($service->modules('super_admin') !== ['Authentication', 'Inventory', 'Recovery Account', 'Recovery actor', 'Sales']) {
    throw new RuntimeException('Super Administrator modules must include all nonempty modules.');
}
try {
    $service->modules('cashier');
    throw new RuntimeException('Cashiers must not access audit modules.');
} catch (DomainException $exception) {
    // Expected: the module list obeys the same audit capability check as records.
}
echo "Audit modules tests: passed\n";
