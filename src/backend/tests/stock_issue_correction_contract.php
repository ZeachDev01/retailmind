<?php
// Contract for the stock-issue correction lifecycle (ticket #30):
// pending/legacy returned -> approved | rejected, immutable reports,
// read-only clarification history and direct review of legacy waiting reports,
// locked terminal states, append-only revisions, role-aware notifications,
// pending-review badge counts, and idempotent/concurrent-safe approvals.
require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Services/StockIssueService.php';

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$throws = static function (callable $operation, string $message) use ($assert): void {
    try {
        $operation();
        $assert(false, $message);
    } catch (Throwable $exception) {
        $assert($exception instanceof RuntimeException, $message . ' (received ' . $exception::class . ')');
    }
};

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['pdo'] = $pdo;

$pdo->exec("CREATE TABLE branches (
    branch_id INTEGER PRIMARY KEY AUTOINCREMENT,
    branch_name VARCHAR(100) NOT NULL UNIQUE,
    branch_code VARCHAR(30) NOT NULL UNIQUE,
    status VARCHAR(20) NOT NULL DEFAULT 'active'
)");
$pdo->exec("INSERT INTO branches (branch_name, branch_code, status)
            VALUES ('RetailMind Store', 'RETAILMIND-STORE', 'active')");
$storeId = (int)$pdo->lastInsertId();

$pdo->exec('CREATE TABLE roles (role_id INTEGER PRIMARY KEY AUTOINCREMENT, role_name VARCHAR(50) NOT NULL UNIQUE)');
$pdo->exec("INSERT INTO roles (role_name) VALUES ('cashier'), ('inventory_manager'), ('admin')");
$roleId = static function (string $name) use ($pdo): int {
    $stmt = $pdo->prepare('SELECT role_id FROM roles WHERE role_name = ?');
    $stmt->execute([$name]);
    return (int)$stmt->fetchColumn();
};

$pdo->exec('CREATE TABLE users (
    user_id INTEGER PRIMARY KEY AUTOINCREMENT,
    full_name VARCHAR(150) NOT NULL DEFAULT \'Test User\',
    branch_id INTEGER NULL,
    role_id INTEGER NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT \'active\'
)');
$insertUser = static function (string $name, string $role) use ($pdo, $roleId, $storeId): int {
    $stmt = $pdo->prepare('INSERT INTO users (full_name, branch_id, role_id, status) VALUES (?, ?, ?, \'active\')');
    $stmt->execute([$name, $storeId, $roleId($role)]);
    return (int)$pdo->lastInsertId();
};
$cashierId = $insertUser('Cathy Cashier', 'cashier');
$cashierTwoId = $insertUser('Connie Cashier', 'cashier');
$managerId = $insertUser('Manny Manager', 'inventory_manager');
$adminId = $insertUser('Ada Admin', 'admin');

$pdo->exec('CREATE TABLE products (
    product_id INTEGER PRIMARY KEY AUTOINCREMENT,
    branch_id INTEGER NULL,
    sku VARCHAR(50) NOT NULL,
    barcode VARCHAR(50) NOT NULL DEFAULT \'\',
    product_name VARCHAR(150) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT \'active\'
)');
$pdo->exec("INSERT INTO products (branch_id, sku, barcode, product_name, status)
            VALUES ({$storeId}, 'SKU-ACTIVE', 'CODE-ACTIVE', 'Active Product', 'active')");
$activeProductId = (int)$pdo->lastInsertId();

$pdo->exec('CREATE TABLE inventory (product_id INTEGER PRIMARY KEY, quantity_on_hand INTEGER NOT NULL DEFAULT 0)');
$pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES ({$activeProductId}, 50)");

$pdo->exec('CREATE TABLE cashier_shifts (
    locked_at TEXT NULL,
    shift_id INTEGER PRIMARY KEY AUTOINCREMENT,
    cashier_id INTEGER NOT NULL,
    opened_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status TEXT NOT NULL DEFAULT \'open\'
)');
$pdo->exec('CREATE TABLE inventory_adjustments (
    adjustment_id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    adjustment_qty INTEGER NOT NULL,
    adjustment_type TEXT NOT NULL,
    reported_by INTEGER NOT NULL,
    shift_id INTEGER NULL,
    reported_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    approved_by INTEGER NULL,
    approved_at TEXT NULL,
    reason TEXT NULL,
    review_notes TEXT NULL,
    status TEXT NOT NULL DEFAULT \'pending\'
)');
$pdo->exec('CREATE TABLE stock_movements (
    movement_id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    change_qty INTEGER NOT NULL,
    reason TEXT NOT NULL,
    moved_by INTEGER NULL,
    adjustment_id INTEGER NULL,
    moved_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)');
$pdo->exec('CREATE TABLE fiscal_periods (
    period_id INTEGER PRIMARY KEY AUTOINCREMENT,
    period_name VARCHAR(100) NOT NULL,
    start_date TEXT NOT NULL,
    end_date TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT \'open\'
)');
$pdo->exec('CREATE TABLE fiscal_period_locks (
    period_id INTEGER NOT NULL,
    table_name VARCHAR(100) NULL
)');
$pdo->exec('CREATE TABLE activity_log (
    log_id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NULL,
    action TEXT NOT NULL,
    category TEXT NOT NULL,
    module TEXT NULL,
    record_id INTEGER NULL,
    previous_value TEXT NULL,
    new_value TEXT NULL,
    metadata TEXT NULL,
    ip_address TEXT NULL,
    created_at TEXT NULL
)');
$pdo->exec('CREATE TABLE notifications (
    notification_id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    type TEXT NOT NULL,
    title TEXT NOT NULL,
    message TEXT NOT NULL,
    reference_id INTEGER NULL,
    reference_type TEXT NULL,
    is_read INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)');

require_once __DIR__ . '/support/notification_schema.php';
notification_test_schema($pdo);
$quietNotifications = new class($pdo) extends NotificationService {
    public function checkAndNotifyLowStock(): void
    {
    }
    public function checkAndNotifyExpiringStock(int $days = 30): void
    {
    }
};
$pdo->exec("ALTER TABLE users ADD COLUMN display_name TEXT NOT NULL DEFAULT 'Test staff'");
$pdo->exec("INSERT INTO fiscal_periods (period_name,start_date,end_date,status) VALUES ('Fixture','2000-01-01','2099-12-31','open')");
$pdo->exec("CREATE TABLE product_batches (batch_id INTEGER PRIMARY KEY,product_id INTEGER,batch_number TEXT,quantity INTEGER,remaining_quantity INTEGER,expiration_date TEXT,date_received TEXT)");
$pdo->exec("INSERT INTO product_batches VALUES (1,{$activeProductId},'FIXTURE',50,50,NULL,'2026-01-01')");
$migration = require __DIR__ . '/../database/migrations/202610180002_stock_issue_batches.php';
$migration['up']($pdo);
$pdo->exec('CREATE TABLE inventory_submissions (user_id INTEGER, operation_kind TEXT, operation_token TEXT, payload_hash TEXT, result_json TEXT, PRIMARY KEY(user_id,operation_kind,operation_token))');
$service = new StockIssueService($pdo, $quietNotifications);

$reportOf = static function (int $adjustmentId) use ($pdo): array {
    $stmt = $pdo->prepare('SELECT * FROM inventory_adjustments WHERE adjustment_id = ?');
    $stmt->execute([$adjustmentId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
};
$openShift = static function (int $userId) use ($pdo): int {
    $stmt = $pdo->prepare('INSERT INTO cashier_shifts (cashier_id, status) VALUES (?, \'open\')');
    $stmt->execute([$userId]);
    return (int)$pdo->lastInsertId();
};
$notificationsFor = static function (int $userId, ?int $referenceId = null) use ($pdo): array {
    if ($referenceId === null) {
        $stmt = $pdo->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY notification_id');
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    $stmt = $pdo->prepare('SELECT * FROM notifications WHERE user_id = ? AND reference_id = ? ORDER BY notification_id');
    $stmt->execute([$userId, $referenceId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
};
$revisionsFor = static function (int $adjustmentId) use ($service): array {
    return $service->getRevisions($adjustmentId);
};
$submit = static function (int $userId, array $overrides = []) use ($service, $activeProductId): int {
    return $service->submitReport(array_merge(
        ['product_id' => $activeProductId, 'category' => 'damaged', 'quantity' => 2, 'explanation' => 'Two units crushed during unloading.'],
        $overrides
    ), $userId);
};

$openShift($cashierId);
$openShift($cashierTwoId);

$pdo->exec("INSERT INTO user_roles (user_id,role_id) VALUES ({$adminId}," . $roleId('admin') . "),({$adminId}," . $roleId('inventory_manager') . ")");
$pdo->exec("INSERT INTO notification_preferences (user_id) VALUES ({$adminId})");
// --- Submission creates a revision + notifies Inventory Managers ---
$firstId = $submit($cashierId);
$assert($reportOf($firstId)['status'] === 'pending', 'Submission must create a pending report');
$firstRevisions = $revisionsFor($firstId);
$assert(count($firstRevisions) === 1 && $firstRevisions[0]['action'] === 'submitted', 'Submission must create a submitted revision');
$assert((int)$firstRevisions[0]['actor_id'] === $cashierId, 'Submission revision must record the cashier actor');
$assert($firstRevisions[0]['created_at'] !== null && $firstRevisions[0]['created_at'] !== '', 'Revision must record a timestamp');
$assert($notificationsFor($managerId, $firstId) !== [], 'Inventory Managers must be notified about new reports');
$assert($notificationsFor($adminId, $firstId) !== [], 'A secondary Inventory Manager role receives the immediate report alert');
$assert($notificationsFor($adminId, $firstId)[0]['workspace'] === 'inventory_manager', 'Immediate report alerts belong to the Inventory Workspace');
$assert($notificationsFor($adminId, $firstId)[0]['email_pending'] == 1, 'Immediate alerts queue email for the daily run');

$assert($notificationsFor($cashierId, $firstId) === [], 'Reporting cashier must not be notified about their own submission');
$assert($service->getPendingCount() === 1, 'Pending-review badge must count the new pending report');

// --- Submitted details are locked, including pending reports. ---
$original = $reportOf($firstId);
$throws(static fn() => $service->editReport($firstId, $cashierId, ['quantity' => 3]), 'Cashier cannot rewrite submitted details');
$assert($reportOf($firstId) === $original, 'Refused edit preserves the entire original report');
$throws(
    static fn() => $service->editReport($firstId, $cashierTwoId, ['quantity' => 1, 'explanation' => 'Hijack attempt with a long explanation.']),
    'Cashiers must not edit another cashier\u2019s report'
);
$throws(
    static fn() => $service->editReport($firstId, $managerId, ['quantity' => 1]),
    'Inventory Managers must not use the cashier edit seam'
);
$throws(
    static fn() => $service->editReport($firstId, $cashierId, ['quantity' => 0, 'explanation' => 'Zero quantity should be refused here.']),
    'Edit with a non-positive quantity must be refused'
);
$throws(
    static fn() => $service->editReport($firstId, $cashierId, ['category' => 'other', 'quantity' => 1, 'explanation' => 'Tiny']),
    'Edit recategorizing to Other with a trivial explanation must be refused'
);

// --- Cashier cancel: own pending only, locked afterwards ---
$cancelId = $submit($cashierId);
$throws(
    static fn() => $service->cancelReport($cancelId, $cashierTwoId),
    'Cashiers must not cancel another cashier\u2019s report'
);
$service->cancelReport($cancelId, $cashierId);
$assert($reportOf($cancelId)['status'] === 'cancelled', 'Cancel must move an own pending report to cancelled');
$cancelRevisions = $revisionsFor($cancelId);
$assert(in_array($cancelRevisions[count($cancelRevisions) - 1]['action'], ['cancelled'], true), 'Cancel must append a cancelled revision');
$throws(
    static fn() => $service->editReport($cancelId, $cashierId, ['quantity' => 1, 'explanation' => 'Editing a cancelled report must fail.']),
    'Cancelled reports must not be editable'
);
$throws(
    static fn() => $service->resubmitReport($cancelId, $cashierId),
    'Cancelled reports must not be resubmittable'
);
$throws(
    static fn() => $service->approveReport($cancelId, $managerId),
    'Cancelled reports must not be approvable'
);

// Messages are retired; unresolved reports stay directly actionable.
$returnId = $submit($cashierId);
$beforeReturn = $reportOf($returnId);
$throws(static fn() => $service->returnReport($returnId,$managerId,'Please clarify'), 'Manager cannot return reports to chat');
$throws(static fn() => $service->sendMessage($returnId,$cashierId,'cashier','Reply'), 'Cashier cannot start a chat');
$throws(static fn() => $service->getConversation($returnId,$cashierTwoId,'cashier'), 'Another cashier cannot read history');
$assert($reportOf($returnId) === $beforeReturn, 'Retired message actions leave the report unchanged');

// --- Manager recategorization: Other only, never quantity/explanation ---
$otherId = $submit($cashierId, ['category' => 'other', 'explanation' => 'Found soggy cartons near the chiller drain.']);
$beforeOther = $reportOf($otherId);
$throws(static fn() => $service->returnReport($otherId, $managerId, 'Change type.', 'damaged'), 'Manager cannot rewrite the original issue type');
$assert($reportOf($otherId) === $beforeOther, 'Refused recategorization preserves the report');
$concreteId = $submit($cashierId, ['category' => 'damaged']);
$throws(
    static fn() => $service->returnReport($concreteId, $managerId, 'Trying to rewrite.', 'missing'),
    'Recategorizing a non-Other report must be refused'
);
$throws(
    static fn() => $service->returnReport($concreteId, $managerId, 'Trying a bogus category.', 'vandalized'),
    'Recategorizing to an unknown category must be refused'
);
// Merely opening a report must not lock it: returned/resubmitted report stays actionable.
$service->getReport($concreteId);
$assert($reportOf($concreteId)['status'] === 'pending', 'Merely opening a report must not lock it');

// --- Approval/rejection lock + notify cashier + revision trail ---
$approveId = $submit($cashierId);
$service->approveReport($approveId, $managerId, 'Verified against shelf audit.');
$assert($reportOf($approveId)['status'] === 'approved', 'Approval must record the approved status');
$approveNotes = $notificationsFor($cashierId, $approveId);
$assert($approveNotes !== [] && str_contains(strtolower($approveNotes[0]['title'] . ' ' . $approveNotes[0]['message']), 'approv'), 'Reporting cashier must be notified on approval');
$approveRevisions = $revisionsFor($approveId);
$assert(in_array(end($approveRevisions)['action'], ['approved'], true), 'Approval must append an approved revision');
$movementCount = (int)$pdo->query("SELECT COUNT(*) FROM stock_movements WHERE adjustment_id = {$approveId}")->fetchColumn();
$throws(
    static fn() => $service->approveReport($approveId, $managerId),
    'Double approval must be refused (idempotent approval behavior)'
);
$assert((int)$pdo->query("SELECT COUNT(*) FROM stock_movements WHERE adjustment_id = {$approveId}")->fetchColumn() === $movementCount, 'Duplicate approvals must not create duplicate stock movements');
$throws(
    static fn() => $service->editReport($approveId, $cashierId, ['quantity' => 1, 'explanation' => 'Editing an approved report must fail.']),
    'Approved reports must not be editable'
);
$throws(
    static fn() => $service->cancelReport($approveId, $cashierId),
    'Approved reports must not be cancellable'
);
$throws(
    static fn() => $service->returnReport($approveId, $managerId, 'Too late.'),
    'Approved reports must not be returnable'
);
$throws(
    static fn() => $service->rejectReport($approveId, $managerId, 'Too late.'),
    'Approved reports must not be rejectable'
);

$rejectId = $submit($cashierId);
$service->rejectReport($rejectId, $managerId, 'Recount shows the units are sellable.');
$assert($reportOf($rejectId)['status'] === 'rejected', 'Rejection must record the rejected status');
$rejectNotes = $notificationsFor($cashierId, $rejectId);
$assert($rejectNotes !== [] && str_contains(strtolower($rejectNotes[0]['title'] . ' ' . $rejectNotes[0]['message']), 'reject'), 'Reporting cashier must be notified on rejection');
$throws(
    static fn() => $service->editReport($rejectId, $cashierId, ['quantity' => 1, 'explanation' => 'Editing a rejected report must fail.']),
    'Rejected reports must not be editable'
);
$throws(
    static fn() => $service->resubmitReport($rejectId, $cashierId),
    'Rejected reports must not be resubmittable'
);
$throws(
    static fn() => $service->cancelReport($rejectId, $cashierId),
    'Rejected reports must not be cancellable'
);

// --- Stale/concurrent actions cannot produce invalid transitions or lost revisions ---
$raceId = $submit($cashierId);
$revisionCountBefore = count($revisionsFor($raceId));
$service->approveReport($raceId, $managerId);
$throws(
    static fn() => $service->returnReport($raceId, $managerId, 'Stale return after approval.'),
    'Stale return after approval must be refused'
);
$throws(
    static fn() => $service->cancelReport($raceId, $cashierId),
    'Stale cancel after approval must be refused'
);
$assert(count($revisionsFor($raceId)) === $revisionCountBefore + 1, 'Failed stale actions must not append revisions');

// Legacy return notes retain author/time after a final decision without another reply.
$legacyId = $submit($cashierId);
$pdo->prepare("UPDATE inventory_adjustments SET status='returned',review_notes='Legacy question',approved_by=?,approved_at='2026-10-01 09:00:00' WHERE adjustment_id=?")->execute([$managerId,$legacyId]);
$assert($service->getPendingCount() > 0, 'Legacy waiting reports are counted for review');
$service->rejectReport($legacyId,$managerId,'No affected units');
$legacyMessages = $service->getConversation($legacyId,$cashierId,'cashier');
$assert(count($legacyMessages) === 1 && $legacyMessages[0]['body'] === 'Legacy question' && $legacyMessages[0]['created_at'] === '2026-10-01 09:00:00', 'Legacy question retains original timestamp after rejection');
$throws(static fn() => $service->sendMessage($legacyId,$cashierId,'cashier','Late reply'), 'Rejection closes chat');
$throws(static fn() => $service->sendMessage($approveId,$cashierId,'cashier','Late reply'), 'Approval closes chat');
$assert((int)$pdo->query('SELECT COUNT(*) FROM stock_movements')->fetchColumn() === 2, 'Only approvals create stock movements');

// --- Badge accuracy after mixed lifecycle ---
$badge = $service->getPendingCount();
$pendingRows = $pdo->query("SELECT COUNT(*) FROM inventory_adjustments WHERE status IN ('pending', 'returned')")->fetchColumn();
$assert($badge === (int)$pendingRows, 'Pending-review badge must match the pending report count');

if ($failures) {
    fwrite(STDERR, "Stock issue correction contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Stock issue correction contract: passed\n";
