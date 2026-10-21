<?php
// Contract for role-based notification routing (issue #261, delivered by #262, #265, #266):
//
// - recipients come from every currently assigned role and active-account eligibility,
//   with a primary-role fallback for accounts that have no role mapping;
// - routine low stock reaches Inventory Managers only, while an out-of-stock product
//   adds a required Administrator oversight condition in the Administrator Workspace;
// - expiring batches reach Inventory Managers, and Administrator escalation appears
//   exactly at the Manila expiration day plus two days (24 hours past the end of the
//   expiration day), then resolves with depleted stock and recurs after a restock;
// - required work is reported per Workspace and per reporting Cashier;
// - optional-delivery preferences only suppress optional reminders.
//
// Runs against an in-memory SQLite database with the shared notification schema helper.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Services/NotificationService.php';

set_exception_handler(static function (Throwable $exception): void {
    fwrite(STDERR, 'Role-based notification routing contract threw: ' . $exception::class
        . ': ' . $exception->getMessage() . ' @ ' . $exception->getFile() . ':' . $exception->getLine() . PHP_EOL);
    exit(1);
});

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
        fwrite(STDERR, "  fail - {$message}\n");
    } else {
        echo "  ok - {$message}\n";
    }
};

// --- Fixture: disposable SQLite Store shaped like the notification contract ---
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$GLOBALS['pdo'] = $pdo;

$pdo->exec('CREATE TABLE roles (role_id INTEGER PRIMARY KEY AUTOINCREMENT, role_name TEXT NOT NULL UNIQUE)');
$pdo->exec("INSERT INTO roles (role_id, role_name) VALUES (1, 'cashier'), (2, 'inventory_manager'), (3, 'admin'), (4, 'super_admin')");
$roleIds = [];
foreach ($pdo->query('SELECT role_id, role_name FROM roles') as $roleRow) {
    $roleIds[$roleRow['role_name']] = (int)$roleRow['role_id'];
}

$pdo->exec("CREATE TABLE users (
    user_id INTEGER PRIMARY KEY AUTOINCREMENT,
    full_name TEXT NOT NULL,
    branch_id INTEGER NOT NULL DEFAULT 1,
    role_id INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'active'
)");
$insertUser = static function (string $name, string $role, string $status = 'active') use ($pdo, $roleIds): int {
    $stmt = $pdo->prepare('INSERT INTO users (full_name, branch_id, role_id, status) VALUES (?, 1, ?, ?)');
    $stmt->execute([$name, $roleIds[$role], $status]);
    return (int)$pdo->lastInsertId();
};

$managerId = $insertUser('Manny Manager', 'inventory_manager');
$adminSecondaryId = $insertUser('Ada Admin', 'admin');
$crossoverId = $insertUser('Cory Cashier', 'cashier');
$disabledManagerId = $insertUser('Dino Manager', 'inventory_manager', 'disabled');
$cashierId = $insertUser('Cathy Cashier', 'cashier');
$cashierTwoId = $insertUser('Connie Cashier', 'cashier');
$adminId = $insertUser('Art Admin', 'admin');
$managerOptedOutId = $insertUser('Mila Manager', 'inventory_manager');

$pdo->exec("CREATE TABLE products (
    product_id INTEGER PRIMARY KEY AUTOINCREMENT,
    branch_id INTEGER NOT NULL DEFAULT 1,
    sku TEXT NOT NULL,
    barcode TEXT NOT NULL DEFAULT '',
    product_name TEXT NOT NULL,
    reorder_level INTEGER NOT NULL DEFAULT 0,
    warning_lead_time_days INTEGER NULL,
    status TEXT NOT NULL DEFAULT 'active'
)");
$pdo->exec("INSERT INTO products (product_id, sku, barcode, product_name, reorder_level) VALUES
    (1, 'LOW-1', '10001', 'Routine Low Stock', 5),
    (2, 'ZERO-1', '10002', 'Out Of Stock Item', 5),
    (3, 'EXP-1', '10003', 'Expiring Batch Item', 0)");

$pdo->exec('CREATE TABLE inventory (product_id INTEGER PRIMARY KEY, quantity_on_hand INTEGER NOT NULL DEFAULT 0)');
$pdo->exec('INSERT INTO inventory (product_id, quantity_on_hand) VALUES (1, 3), (2, 0), (3, 50)');

$pdo->exec("CREATE TABLE product_batches (
    batch_id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    batch_number TEXT NULL,
    quantity INTEGER NOT NULL,
    remaining_quantity INTEGER NOT NULL,
    expiration_date TEXT NULL
)");
$pdo->exec("INSERT INTO product_batches (batch_id, product_id, batch_number, quantity, remaining_quantity, expiration_date)
    VALUES (1, 3, 'BATCH-EXP-1', 10, 10, '2026-10-25')");

$pdo->exec('CREATE TABLE store_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT NULL)');

$pdo->exec("CREATE TABLE inventory_adjustments (
    adjustment_id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    adjustment_qty INTEGER NOT NULL,
    adjustment_type TEXT NOT NULL,
    reported_by INTEGER NOT NULL,
    reported_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reason TEXT NULL,
    status TEXT NOT NULL DEFAULT 'pending'
)");
$pdo->exec("INSERT INTO inventory_adjustments (adjustment_id, product_id, adjustment_qty, adjustment_type, reported_by, reported_at, reason, status) VALUES
    (1, 1, -2, 'damaged', {$cashierId}, '2026-10-23 09:00:00', 'Two units crushed during unloading', 'pending'),
    (2, 1, -2, 'damaged', {$cashierId}, '2026-10-23 09:05:00', 'Returned for a clearer damage photo', 'returned'),
    (3, 1, -1, 'missing', {$cashierTwoId}, '2026-10-23 09:10:00', 'Another cashier returned report', 'returned')");

$pdo->exec("CREATE TABLE notifications (
    notification_id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    type TEXT NOT NULL,
    title TEXT NOT NULL,
    message TEXT NOT NULL,
    reference_id INTEGER NULL,
    reference_type TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)");

// Option columns must exist before the shared helper runs its CREATE TABLE IF NOT EXISTS.
$pdo->exec("CREATE TABLE notification_preferences (
    user_id INTEGER PRIMARY KEY,
    notify_low_stock INTEGER NOT NULL DEFAULT 1,
    notify_adjustment INTEGER NOT NULL DEFAULT 0,
    notify_email INTEGER NOT NULL DEFAULT 0,
    notify_inapp INTEGER NOT NULL DEFAULT 1,
    low_stock_threshold INTEGER DEFAULT 10
)");

require_once __DIR__ . '/support/notification_schema.php';
notification_test_schema($pdo);

$pdo->exec("INSERT INTO user_roles (user_id, role_id, is_primary) VALUES
    ({$adminSecondaryId}, {$roleIds['inventory_manager']}, 0),
    ({$crossoverId}, {$roleIds['inventory_manager']}, 0)");
// Administrator with optional in-app delivery switched off; oversight is still required.
$pdo->exec("INSERT INTO notification_preferences (user_id, notify_inapp) VALUES ({$adminId}, 0)");
// Inventory Manager who switched off routine low-stock reminders only.
$pdo->exec("INSERT INTO notification_preferences (user_id, notify_low_stock) VALUES ({$managerOptedOutId}, 0)");

$clock = new class implements \App\Attention\Clock {
    public DateTimeImmutable $now;

    public function __construct()
    {
        $this->now = new DateTimeImmutable('2026-10-23T10:00:00+08:00');
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
};
$at = static function (string $moment) use ($clock): void {
    $clock->now = new DateTimeImmutable($moment);
};

$notifications = new NotificationService($pdo, $clock);

$userIds = static fn(array $rows): array => array_map('intval', array_column($rows, 'user_id'));
$rowsFor = static function (int $userId, ?string $attentionKey = null) use ($pdo): array {
    if ($attentionKey === null) {
        $stmt = $pdo->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY notification_id');
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    $stmt = $pdo->prepare('SELECT * FROM notifications WHERE user_id = ? AND attention_key LIKE ? ORDER BY notification_id');
    $stmt->execute([$userId, $attentionKey . '%']);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
};
$stateActive = static function (int $userId, string $attentionKey) use ($pdo): int {
    $stmt = $pdo->prepare('SELECT is_active FROM attention_states WHERE user_id = ? AND attention_key = ?');
    $stmt->execute([$userId, $attentionKey]);
    return (int)$stmt->fetchColumn();
};

echo "Starting role-based notification routing contract...\n";

// --- 1. Recipient eligibility uses every assigned role and active accounts ---
echo "\n--- 1. Recipient eligibility ---\n";
$managerRecipients = $userIds($notifications->recipients('inventory_manager'));
$assert(in_array($managerId, $managerRecipients, true), 'An account without role mappings still receives through its primary Inventory Manager role');
$assert(in_array($adminSecondaryId, $managerRecipients, true), 'A secondary Inventory Manager assignment is a recipient');
$assert(in_array($crossoverId, $managerRecipients, true), 'A Cashier with a secondary Inventory Manager role is a recipient');
$assert(in_array($managerOptedOutId, $managerRecipients, true), 'A routine opt-out does not remove Workspace eligibility');
$assert(!in_array($disabledManagerId, $managerRecipients, true), 'A disabled account receives nothing');
$assert(!in_array($cashierId, $managerRecipients, true), 'A primary Cashier is not an Inventory Manager recipient');
$assert(!in_array($adminId, $managerRecipients, true), 'An Administrator is not an Inventory Manager recipient');

$adminRecipients = $userIds($notifications->recipients('admin'));
$assert(in_array($adminId, $adminRecipients, true), 'An active Administrator is an Administrator recipient');
$assert(!in_array($adminSecondaryId, $adminRecipients, true), 'A user whose Administrator assignment was replaced by a secondary role is not an Administrator recipient');
$assert(!in_array($managerId, $adminRecipients, true), 'An Inventory Manager does not fill the Administrator Workspace');
$assert(!in_array($cashierId, $adminRecipients, true), 'A Cashier does not fill the Administrator Workspace');

// --- 2. Routine low stock, out-of-stock oversight, and role removal ---
echo "\n--- 2. Low-stock routing ---\n";
$notifications->checkAndNotifyLowStock();

$managerRoutine = $rowsFor($managerId, 'inventory.stock.1');
$assert(count($managerRoutine) === 1, 'A routine low-stock product notifies the Inventory Manager once');
$assert($managerRoutine[0]['workspace'] === 'inventory_manager', 'A routine low-stock alert belongs to the Inventory Workspace');
$assert($managerRoutine[0]['type'] === 'low_stock', 'A routine low-stock alert carries the low-stock type');
$assert(str_starts_with($managerRoutine[0]['title'], 'Low Stock Alert:'), 'Routine stock remains an in-stock low-stock alert');
$assert((int)$managerRoutine[0]['inapp_enabled'] === 1, 'A routine low-stock alert is delivered in-app when preferences allow');

$secondaryRoutine = $rowsFor($adminSecondaryId, 'inventory.stock.1');
$assert($secondaryRoutine !== [] && $secondaryRoutine[0]['workspace'] === 'inventory_manager', 'A secondary Inventory Manager receives the routine low-stock alert');
$assert(count($rowsFor($adminSecondaryId, 'oversight.stock.')) === 0, 'A secondary Inventory Manager role does not fill the Administrator Workspace');
$assert(count($rowsFor($crossoverId, 'inventory.stock.1')) === 1, 'A secondary-role Cashier receives the routine low-stock alert');
$assert($rowsFor($cashierId) === [], 'A primary Cashier receives no routine stock alerts');
$assert(count($rowsFor($disabledManagerId)) === 0, 'A disabled account receives no routine stock alerts');
$assert(count($rowsFor($adminId, 'oversight.stock.1')) === 0, 'Administrator oversight ignores products that are still in stock');

$misrouted = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE attention_key LIKE 'inventory.stock.%' AND workspace <> 'inventory_manager'")->fetchColumn();
$assert($misrouted === 0, 'Routine low-stock alerts never land outside the Inventory Workspace');

$managerOut = $rowsFor($managerId, 'inventory.stock.2');
$assert(count($managerOut) === 1 && str_starts_with($managerOut[0]['title'], 'Out of stock:'), 'An out-of-stock product is a critical Inventory Manager alert');

$adminOversight = $rowsFor($adminId, 'oversight.stock.2');
$assert(count($adminOversight) === 1, 'An out-of-stock product additionally escalates to Administrator oversight');
$assert($adminOversight[0]['workspace'] === 'admin', 'The out-of-stock escalation belongs to the Administrator Workspace');
$assert($adminOversight[0]['attention_key'] === 'oversight.stock.2', 'Administrator oversight uses the oversight stock condition identity');
$assert((int)$adminOversight[0]['inapp_enabled'] === 1, 'Required oversight keeps in-app delivery despite a disabled optional preference');
$assert($rowsFor($cashierId) === [], 'A Cashier receives no out-of-stock oversight alert');

$optedOutRoutine = $rowsFor($managerOptedOutId, 'inventory.stock.1');
$assert(count($optedOutRoutine) === 1, 'A routine opt-out keeps the condition active for the Inventory Manager');
$assert((int)$optedOutRoutine[0]['inapp_enabled'] === 0, 'A disabled routine preference suppresses the optional in-app reminder');

$crossoverBefore = count($rowsFor($crossoverId));
$pdo->exec("DELETE FROM user_roles WHERE user_id = {$crossoverId}");
$notifications->checkAndNotifyLowStock();
$assert(count($rowsFor($crossoverId)) === $crossoverBefore, 'Removing the role assignment stops new Inventory Workspace alerts');
$assert(!in_array($crossoverId, $userIds($notifications->recipients('inventory_manager')), true), 'Removing the role assignment revokes Inventory Workspace recipients');
$assert(in_array($crossoverId, $userIds($notifications->recipients('cashier')), true), 'A removed secondary role falls back to the primary Cashier role');

// --- 3. Expiry routine reminder, escalation boundary, resolution, recurrence ---
echo "\n--- 3. Expiry routing ---\n";
$at('2026-10-23T10:00:00+08:00');
$notifications->checkAndNotifyExpiringStock();

$managerExpiry = $rowsFor($managerId, 'inventory.expiry.1');
$assert(count($managerExpiry) === 1, 'An expiring batch reminds the Inventory Manager once');
$assert($managerExpiry[0]['workspace'] === 'inventory_manager', 'A routine expiry reminder belongs to the Inventory Workspace');
$assert($managerExpiry[0]['type'] === 'expiring_stock', 'A batch inside its lead time is a routine expiring reminder');
$assert((int)$managerExpiry[0]['inapp_enabled'] === 1, 'A routine expiry reminder is delivered in-app when preferences allow');
$assert(count($rowsFor($adminSecondaryId, 'inventory.expiry.1')) === 1, 'A secondary Inventory Manager receives the routine expiry reminder');
$assert(count($rowsFor($adminId, 'oversight.expiry.')) === 0, 'Administrator oversight waits for the overdue escalation');
$assert($rowsFor($cashierId) === [], 'A Cashier receives no routine expiry reminder');
$assert(count($rowsFor($disabledManagerId)) === 0, 'A disabled account receives no expiry reminder');

$reminders = $notifications->getBatchReminders($managerId, '2026-10-25');
$assert(isset($reminders[1]) && $reminders[1]['active'], 'The current batch state stays actionable on the expiration day');
$assert(str_starts_with((string)$reminders[1]['title'], 'Prepare for supplier collection:'), 'The expiration day is not yet overdue for collection');
$assert($notifications->getBatchReminders($cashierId, '2026-10-25') === [], 'Current batch reminders stay inside the Inventory Workspace');

$at('2026-10-26T23:59:59+08:00');
$notifications->checkAndNotifyExpiringStock();
$assert(count($rowsFor($adminId, 'oversight.expiry.')) === 0, 'One second before the boundary the Administrator escalation is still absent');

$at('2026-10-27T00:00:00+08:00');
$notifications->checkAndNotifyExpiringStock();
$adminExpiry = $rowsFor($adminId, 'oversight.expiry.1');
$assert(count($adminExpiry) === 1, 'At the Manila expiration day plus two days the Administrator escalation appears');
$assert($adminExpiry[0]['workspace'] === 'admin', 'The overdue escalation belongs to the Administrator Workspace');
$assert((int)$adminExpiry[0]['inapp_enabled'] === 1, 'Required escalation overrides a disabled optional in-app preference');
$assert(count($rowsFor($adminSecondaryId, 'oversight.expiry.')) === 0, 'A secondary Inventory Manager role does not fill the Administrator Workspace');
$assert(count($rowsFor($managerId, 'inventory.expiry.1')) === 2, 'The severity escalation also re-alerts the Inventory Manager');

$pdo->exec('UPDATE product_batches SET remaining_quantity = 0 WHERE batch_id = 1');
$notifications->checkAndNotifyExpiringStock();
$assert($stateActive($managerId, 'inventory.expiry.1') === 0, 'Depleted stock resolves the Inventory Manager expiry condition');
$assert($stateActive($adminId, 'oversight.expiry.1') === 0, 'Depleted stock resolves the Administrator oversight condition');

$managerBefore = count($rowsFor($managerId, 'inventory.expiry.1'));
$adminBefore = count($rowsFor($adminId, 'oversight.expiry.1'));
$pdo->exec('UPDATE product_batches SET remaining_quantity = 6 WHERE batch_id = 1');
$notifications->checkAndNotifyExpiringStock();
$assert(count($rowsFor($managerId, 'inventory.expiry.1')) === $managerBefore + 1, 'A restocked expired batch re-alerts the Inventory Manager');
$assert(count($rowsFor($adminId, 'oversight.expiry.1')) === $adminBefore + 1, 'A restocked overdue batch re-alerts the Administrator');

// --- 4. Required work per Workspace ---
echo "\n--- 4. Required work ---\n";
$managerWork = $notifications->requiredWork($managerId, 'inventory_manager');
$assert(count($managerWork) === 1, 'Pending Stock Issues are the Inventory Manager required work');
$assert($managerWork[0]['title'] === 'Pending Stock Issue #1', 'Pending required work names the Stock Issue');
$assert($managerWork[0]['destination'] === 'components/inventory_management/stock_issues.php', 'Inventory Manager required work points at the Inventory Workspace');
$assert($notifications->requiredWork($cashierId, 'inventory_manager') === [], 'A Cashier is not assigned the Inventory Manager Workspace');

$cashierWork = $notifications->requiredWork($cashierId, 'cashier');
$assert(count($cashierWork) === 1, 'A returned report is the reporting Cashier required work');
$assert($cashierWork[0]['title'] === 'Returned Stock Issue #2', 'Returned required work names the reporting Cashier report');
$assert($cashierWork[0]['destination'] === 'components/cashier/stock_issues.php', 'Cashier required work points at the Cashier Workspace');
$assert($notifications->requiredWork($cashierTwoId, 'cashier')[0]['title'] === 'Returned Stock Issue #3', 'Another Cashier sees only their own returned report');
$assert($notifications->requiredWork($managerId, 'cashier') === [], 'An Inventory Manager is not assigned the Cashier Workspace');

// --- 5. Optional-delivery preference handling ---
echo "\n--- 5. Optional-delivery preferences ---\n";
$assert($notifications->inAppEnabled($cashierTwoId) === true, 'A user without a preference row keeps in-app delivery');
$assert($notifications->inAppEnabled($cashierTwoId, 'notify_low_stock') === true, 'A user without a preference row keeps the default routine preference');
$assert($notifications->inAppEnabled($adminId) === false, 'An explicitly disabled in-app preference reports disabled');
$assert($notifications->inAppEnabled($managerOptedOutId) === true, 'A routine opt-out does not disable the in-app channel itself');
$assert($notifications->inAppEnabled($managerOptedOutId, 'notify_low_stock') === false, 'An explicitly disabled routine preference reports disabled');
$unknownPreferenceRefused = false;
try {
    $notifications->inAppEnabled($managerId, 'notify_anything');
} catch (InvalidArgumentException $exception) {
    $unknownPreferenceRefused = true;
}
$assert($unknownPreferenceRefused, 'An unknown preference key is refused');

// --- 6. Persistence seam ---
echo "\n--- 6. Notification persistence ---\n";
$notifications->createNotification($cashierId, 'adjustment', 'Returned Stock Issue #2', 'Returned for a clearer damage photo', 2, 'stock_issue', 'cashier', false);
$stmt = $pdo->prepare('SELECT workspace, inapp_enabled, email_pending FROM notifications WHERE user_id = ? AND title = ?');
$stmt->execute([$cashierId, 'Returned Stock Issue #2']);
$created = $stmt->fetch(PDO::FETCH_ASSOC);
$assert($created !== false && $created['workspace'] === 'cashier', 'A created workflow update is stored in its Workspace');
$assert($created !== false && (int)$created['inapp_enabled'] === 0, 'A created optional update keeps its in-app flag');
$assert($created !== false && (int)$created['email_pending'] === 1, 'A created workflow update queues the daily email');

if ($failures) {
    fwrite(STDERR, "Role-based notification routing contract failed (" . count($failures) . "):\n- "
        . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "\nRole-based notification routing contract: passed\n";
exit(0);
