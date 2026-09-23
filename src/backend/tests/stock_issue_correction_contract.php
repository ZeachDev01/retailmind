<?php
// Contract for the stock-issue correction lifecycle (ticket #30):
// pending -> approved | rejected | returned | cancelled, cashier edit/cancel/
// resubmit ownership, manager return with reason (+ Other recategorization only),
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

$quietNotifications = new class($pdo) extends NotificationService {
    public function checkAndNotifyLowStock(): void
    {
    }
    public function checkAndNotifyExpiringStock(int $days = 30): void
    {
    }
};
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

// --- Submission creates a revision + notifies Inventory Managers ---
$firstId = $submit($cashierId);
$assert($reportOf($firstId)['status'] === 'pending', 'Submission must create a pending report');
$firstRevisions = $revisionsFor($firstId);
$assert(count($firstRevisions) === 1 && $firstRevisions[0]['action'] === 'submitted', 'Submission must create a submitted revision');
$assert((int)$firstRevisions[0]['actor_id'] === $cashierId, 'Submission revision must record the cashier actor');
$assert($firstRevisions[0]['created_at'] !== null && $firstRevisions[0]['created_at'] !== '', 'Revision must record a timestamp');
$assert($notificationsFor($managerId, $firstId) !== [], 'Inventory Managers must be notified about new reports');
$assert($notificationsFor($cashierId, $firstId) === [], 'Reporting cashier must not be notified about their own submission');
$assert($service->getPendingCount() === 1, 'Pending-review badge must count the new pending report');

// --- Cashier edit: own pending only ---
$service->editReport($firstId, $cashierId, ['quantity' => 3, 'explanation' => 'Three units crushed; recount confirmed.']);
$edited = $reportOf($firstId);
$assert((int)$edited['adjustment_qty'] === -3, 'Cashier edit must update the reported quantity');
$assert($edited['status'] === 'pending', 'Edit must keep the report pending (no silent decision)');
$editRevisions = $revisionsFor($firstId);
$assert(count($editRevisions) === 2 && $editRevisions[1]['action'] === 'edited', 'Edit must append an edited revision');
$assert(str_contains((string)$editRevisions[1]['old_values'], '-2') && str_contains((string)$editRevisions[1]['new_values'], '-3'), 'Edit revision must carry previous and new values');
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

// --- Manager return: pending only, reason required, editable by cashier ---
$returnId = $submit($cashierId);
$throws(
    static fn() => $service->returnReport($returnId, $managerId, '   '),
    'Return without a reason must be refused'
);
$throws(
    static fn() => $service->returnReport($returnId, $cashierId, 'Cashiers cannot return.'),
    'Cashiers must not return reports'
);
$throws(
    static fn() => $service->returnReport($returnId, $adminId, 'Admins cannot return.'),
    'Administrators must not return reports'
);
$service->returnReport($returnId, $managerId, 'Photo is blurry; clarify the damage.');
$assert($reportOf($returnId)['status'] === 'returned', 'Return must move a pending report to returned');
$returnRevisions = $revisionsFor($returnId);
$assert($returnRevisions[count($returnRevisions) - 1]['action'] === 'returned', 'Return must append a returned revision');
$assert((int)$returnRevisions[count($returnRevisions) - 1]['actor_id'] === $managerId, 'Return revision must record the manager actor');
$cashierNotes = $notificationsFor($cashierId, $returnId);
$assert($cashierNotes !== [] && str_contains(strtolower($cashierNotes[0]['title'] . ' ' . $cashierNotes[0]['message']), 'return'), 'Reporting cashier must be notified when a report is returned');

// Returned reports: cashier can edit, then resubmit to pending (notifies managers).
$service->editReport($returnId, $cashierId, ['quantity' => 2, 'explanation' => 'Two units crushed; added a clearer photo note.']);
$assert($reportOf($returnId)['status'] === 'returned', 'Editing a returned report must keep it returned until resubmission');
$throws(
    static fn() => $service->approveReport($returnId, $managerId),
    'Returned reports must not be approvable before resubmission'
);
$managerNoticeCount = count($notificationsFor($managerId, $returnId));
$service->resubmitReport($returnId, $cashierId);
$assert($reportOf($returnId)['status'] === 'pending', 'Resubmit must move an own returned report back to pending');
$assert(count($notificationsFor($managerId, $returnId)) === $managerNoticeCount + 1, 'Inventory Managers must be notified about resubmitted reports');
$resubmitRevisions = $revisionsFor($returnId);
$assert($resubmitRevisions[count($resubmitRevisions) - 1]['action'] === 'resubmitted', 'Resubmit must append a resubmitted revision');
$throws(
    static fn() => $service->resubmitReport($returnId, $cashierTwoId),
    'Cashiers must not resubmit another cashier\u2019s returned report'
);

// --- Manager recategorization: Other only, never quantity/explanation ---
$otherId = $submit($cashierId, ['category' => 'other', 'explanation' => 'Found soggy cartons near the chiller drain.']);
$beforeOther = $reportOf($otherId);
$service->returnReport($otherId, $managerId, 'Recategorized after photo review.', 'damaged');
$afterRecat = $reportOf($otherId);
$assert($afterRecat['adjustment_type'] === 'damaged', 'Manager may recategorize Other while returning');
$assert((int)$afterRecat['adjustment_qty'] === (int)$beforeOther['adjustment_qty'], 'Recategorization must not alter the reported quantity');
$assert($afterRecat['reason'] === $beforeOther['reason'], 'Recategorization must not rewrite the cashier explanation');
$recatRevisions = $revisionsFor($otherId);
$assert(str_contains((string)end($recatRevisions)['old_values'], 'other') && str_contains((string)end($recatRevisions)['new_values'], 'damaged'), 'Category change must be captured with old and new values');
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

// --- Badge accuracy after mixed lifecycle ---
$badge = $service->getPendingCount();
$pendingRows = $pdo->query("SELECT COUNT(*) FROM inventory_adjustments WHERE status = 'pending'")->fetchColumn();
$assert($badge === (int)$pendingRows, 'Pending-review badge must match the pending report count');

if ($failures) {
    fwrite(STDERR, "Stock issue correction contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Stock issue correction contract: passed\n";
