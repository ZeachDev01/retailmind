<?php
// Contract for administrator stock-issue oversight and immutable history
// (ticket #31): role-scoped visibility, filtering, administrator mutation
// denial, immutable approved/rejected history, and correction of an approved
// report through a separate Inventory Manager inventory-count transaction that
// preserves references to both records.
require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Services/StockIssueService.php';
require_once __DIR__ . '/../app/Services/InventoryCountService.php';

use App\Authorization\RoleCapabilityPolicy;

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
$pdo->exec("INSERT INTO roles (role_name) VALUES ('cashier'), ('inventory_manager'), ('admin'), ('super_admin')");
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
$superAdminId = $insertUser('Sam Super', 'super_admin');

$pdo->exec('CREATE TABLE products (
    product_id INTEGER PRIMARY KEY AUTOINCREMENT,
    branch_id INTEGER NULL,
    sku VARCHAR(50) NOT NULL,
    barcode VARCHAR(50) NOT NULL DEFAULT \'\',
    product_name VARCHAR(150) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT \'active\'
)');
$pdo->exec("INSERT INTO products (branch_id, sku, barcode, product_name, status)
            VALUES ({$storeId}, 'SKU-APPLE', 'CODE-APPLE', 'Apple Juice', 'active')");
$productA = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO products (branch_id, sku, barcode, product_name, status)
            VALUES ({$storeId}, 'SKU-BANANA', 'CODE-BANANA', 'Banana Bread', 'active')");
$productB = (int)$pdo->lastInsertId();

$pdo->exec('CREATE TABLE inventory (product_id INTEGER PRIMARY KEY, quantity_on_hand INTEGER NOT NULL DEFAULT 0)');
$pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES ({$productA}, 50)");
$pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES ({$productB}, 40)");

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
$pdo->exec('CREATE TABLE inventory_counts (
    count_id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    system_quantity INTEGER NOT NULL,
    physical_quantity INTEGER NOT NULL,
    difference_qty INTEGER NOT NULL,
    discrepancy_reason TEXT NOT NULL,
    counted_by INTEGER NOT NULL,
    approved_by INTEGER NULL,
    counted_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    approved_at TEXT NULL,
    status TEXT NOT NULL DEFAULT \'pending\',
    related_adjustment_id INTEGER NULL
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
$countService = new InventoryCountService($pdo);
$policy = new RoleCapabilityPolicy();

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
$idsOf = static function (array $rows): array {
    return array_map(static fn(array $row): int => (int)$row['adjustment_id'], $rows);
};
$submit = static function (int $userId, array $overrides = []) use ($service, $productA): int {
    return $service->submitReport(array_merge(
        ['product_id' => $productA, 'category' => 'damaged', 'quantity' => 2, 'explanation' => 'Two units crushed during unloading.'],
        $overrides
    ), $userId);
};

$openShift($cashierId);
$openShift($cashierTwoId);

// Seed one report per lifecycle outcome so filters have something to bite on.
$approvedId = $submit($cashierId, ['product_id' => $productA, 'category' => 'damaged', 'quantity' => 2]);
$service->approveReport($approvedId, $managerId, 'Verified against shelf audit.');

$rejectedId = $submit($cashierId, ['product_id' => $productA, 'category' => 'expired', 'quantity' => 1, 'explanation' => 'Past best-before date on shelf.']);
$service->rejectReport($rejectedId, $managerId, 'Recount shows the units are sellable.');

$pendingId = $submit($cashierTwoId, ['product_id' => $productB, 'category' => 'missing', 'quantity' => 5, 'explanation' => 'Carton missing after delivery check.']);

$cancelledId = $submit($cashierTwoId, ['product_id' => $productB, 'category' => 'other', 'quantity' => 1, 'explanation' => 'Found a torn wrapper near the till.']);
$service->cancelReport($cancelledId, $cashierTwoId);

// --- Filtering (administrator view) ---
$all = $service->getOversightReports([], $adminId);
$assert(count($all) === 4, 'Administrator oversight must list every stock-issue report');

$approvedRows = $service->getOversightReports(['status' => 'approved'], $adminId);
$assert($idsOf($approvedRows) === [$approvedId], 'Status filter must return only approved reports');

$missingRows = $service->getOversightReports(['category' => 'missing'], $adminId);
$assert($idsOf($missingRows) === [$pendingId], 'Category filter must match Missing/Lost reports');

$productARows = $service->getOversightReports(['product_id' => $productA], $adminId);
$assert(count($productARows) === 2, 'Product filter must scope to the selected product');

$productTextRows = $service->getOversightReports(['product' => 'Banana'], $adminId);
$assert(count($productTextRows) === 2, 'Product text filter must match the product name');

$cashierRows = $service->getOversightReports(['cashier_id' => $cashierTwoId], $adminId);
$assert(count($cashierRows) === 2 && !in_array($approvedId, $idsOf($cashierRows), true), 'Cashier filter must scope to the selected cashier');

$approverRows = $service->getOversightReports(['approver_id' => $managerId], $adminId);
$assert(count($approverRows) === 2, 'Approver filter must return reports decided by the selected approver');

$today = date('Y-m-d');
$todayRows = $service->getOversightReports(['date_from' => $today, 'date_to' => $today], $adminId);
$assert(count($todayRows) === 4, 'Date-range filter must include reports reported within the range');

$futureRows = $service->getOversightReports(['date_from' => date('Y-m-d', strtotime('+1 day'))], $adminId);
$assert($futureRows === [], 'Date-range filter must exclude reports outside the range');

$throws(
    static fn() => $service->getOversightReports(['status' => 'exploded'], $adminId),
    'An unknown status filter must be refused'
);
$throws(
    static fn() => $service->getOversightReports(['category' => 'vandalized'], $adminId),
    'An unknown category filter must be refused'
);
$throws(
    static fn() => $service->getOversightReports(['date_from' => '09-24-2026'], $adminId),
    'A malformed date filter must be refused'
);

// --- Role-scoped visibility ---
$assert(count($service->getOversightReports([], $managerId)) === 4, 'Inventory Managers retain full operational review scope');
$cathyRows = $service->getOversightReports([], $cashierId);
$assert($idsOf($cathyRows) === [$rejectedId, $approvedId], 'Cashiers must see only their own reports');
$connieRows = $service->getOversightReports([], $cashierTwoId);
$assert(count($connieRows) === 2 && !in_array($approvedId, $idsOf($connieRows), true), 'Cashier scoping must exclude other cashiers\' reports');
$throws(
    static fn() => $service->getOversightReports([], $superAdminId),
    'Roles outside the oversight scope must be refused'
);

$adminDetail = $service->getReportDetail($pendingId, $adminId);
$assert(is_array($adminDetail) && (int)$adminDetail['report']['adjustment_id'] === $pendingId, 'Administrators can open any report detail');
$managerDetail = $service->getReportDetail($pendingId, $managerId);
$assert(is_array($managerDetail), 'Inventory Managers can open report details for operational review');
$cathyOwn = $service->getReportDetail($approvedId, $cashierId);
$assert(is_array($cathyOwn), 'Cashiers can open their own report details');
$throws(
    static fn() => $service->getReportDetail($pendingId, $cashierId),
    'Cashiers must not open another cashier\'s report detail'
);
$assert($service->getReportDetail(999999, $adminId) === null, 'Unknown report details must return null');

// --- Administrator mutation denial + read-only capability ---
$assert(
    !$policy->allows('admin', RoleCapabilityPolicy::MUTATE_INVENTORY),
    'Administrators must not hold the mutate-inventory capability'
);
$throws(
    static fn() => $service->approveReport($pendingId, $adminId),
    'Administrators must not approve stock-issue reports'
);
$throws(
    static fn() => $service->rejectReport($pendingId, $adminId, 'Admin rejection attempt.'),
    'Administrators must not reject stock-issue reports'
);
$throws(
    static fn() => $service->returnReport($pendingId, $adminId, 'Admin return attempt.'),
    'Administrators must not return stock-issue reports'
);
$throws(
    static fn() => $service->editReport($approvedId, $adminId, ['quantity' => 1]),
    'Administrators must not edit stock-issue reports'
);
$throws(
    static fn() => $service->cancelReport($pendingId, $adminId),
    'Administrators must not cancel stock-issue reports'
);
$throws(
    static fn() => $service->resubmitReport($pendingId, $adminId),
    'Administrators must not resubmit stock-issue reports'
);
$assert($reportOf($pendingId)['status'] === 'pending', 'Denied administrator actions must leave the report untouched');

// --- Immutable history for approved/rejected reports ---
$approvedBefore = $reportOf($approvedId);
$approvedRevisionsBefore = $service->getRevisions($approvedId);
$movementBefore = (int)$pdo->query("SELECT COUNT(*) FROM stock_movements WHERE adjustment_id = {$approvedId}")->fetchColumn();
$assert($movementBefore === 1, 'Approval must leave exactly one linked stock movement');

$throws(
    static fn() => $service->editReport($approvedId, $cashierId, ['quantity' => 9, 'explanation' => 'Rewriting an approved report.']),
    'Approved reports must stay immutable for cashiers'
);
$throws(
    static fn() => $service->cancelReport($approvedId, $cashierId),
    'Approved reports must not be cancellable'
);
$throws(
    static fn() => $service->returnReport($approvedId, $managerId, 'Reopening attempt.'),
    'Approved reports must not be returned'
);
$throws(
    static fn() => $service->rejectReport($approvedId, $managerId, 'Rejection after approval.'),
    'Approved reports must not be rejectable after approval'
);
$throws(
    static fn() => $service->approveReport($approvedId, $managerId),
    'Approved reports must not be re-approvable'
);
$throws(
    static fn() => $service->editReport($rejectedId, $cashierId, ['quantity' => 3, 'explanation' => 'Rewriting a rejected report.']),
    'Rejected reports must stay immutable'
);
$throws(
    static fn() => $service->resubmitReport($rejectedId, $cashierId),
    'Rejected reports must not be resubmittable'
);

$approvedAfter = $reportOf($approvedId);
$assert($approvedAfter == $approvedBefore, 'Failed mutation attempts must not change an approved report');
$assert(
    $service->getRevisions($approvedId) == $approvedRevisionsBefore,
    'Failed mutation attempts must not append or alter revisions'
);
$assert(
    (int)$pdo->query("SELECT COUNT(*) FROM stock_movements WHERE adjustment_id = {$approvedId}")->fetchColumn() === $movementBefore,
    'Failed mutation attempts must not alter linked stock movements'
);
$approvedDetail = $service->getReportDetail($approvedId, $adminId);
$assert(
    is_array($approvedDetail)
    && count($approvedDetail['revisions']) === count($approvedRevisionsBefore)
    && !empty($approvedDetail['stock_movements'])
    && (int)$approvedDetail['stock_movements'][0]['movement_id'] > 0,
    'Report details must expose the full revision trail and linked stock movement'
);
$assert(
    is_array($approvedDetail)
    && str_contains((string)$approvedDetail['report']['review_notes'], 'shelf audit'),
    'Report details must expose the decision reason'
);
$actorNamed = false;
foreach ($approvedDetail['revisions'] as $revision) {
    if ((string)($revision['actor_name'] ?? '') !== '') {
        $actorNamed = true;
        break;
    }
}
$assert($actorNamed, 'Revisions must resolve the acting user for oversight display');

// --- Separate Inventory Manager inventory-count correction ---
$systemBefore = (int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = {$productA}")->fetchColumn();
$correctionCountId = $countService->recordCount([
    'product_id' => $productA,
    'physical_quantity' => $systemBefore + 2,
    'discrepancy_reason' => 'Correction for approved stock issue report: two units were not actually lost.',
    'related_adjustment_id' => $approvedId,
], $managerId);

$linkedCount = $pdo->query("SELECT * FROM inventory_counts WHERE count_id = {$correctionCountId}")->fetch(PDO::FETCH_ASSOC);
$assert($linkedCount !== false && (int)$linkedCount['related_adjustment_id'] === $approvedId, 'A correction count must reference the approved stock-issue report');

$stillApproved = $reportOf($approvedId);
$assert($stillApproved['status'] === 'approved', 'The original approved report must remain approved after linking a correction count');
$assert(
    $stillApproved['adjustment_qty'] === $approvedBefore['adjustment_qty']
    && $stillApproved['reason'] === $approvedBefore['reason'],
    'The original approved report must not be rewritten by the correction count'
);
$assert(
    (int)$pdo->query("SELECT COUNT(*) FROM stock_movements WHERE adjustment_id = {$approvedId}")->fetchColumn() === $movementBefore,
    'Recording a correction count must not add stock movements to the original report'
);

$detailWithCorrection = $service->getReportDetail($approvedId, $adminId);
$correctionIds = array_map(
    static fn(array $row): int => (int)$row['count_id'],
    $detailWithCorrection['correction_counts'] ?? []
);
$assert(in_array($correctionCountId, $correctionIds, true), 'Report details must expose linked correction counts');

$throws(
    static fn() => $countService->recordCount([
        'product_id' => $productB,
        'physical_quantity' => 1,
        'discrepancy_reason' => 'Linking a correction to a report that is still pending.',
        'related_adjustment_id' => $pendingId,
    ], $managerId),
    'Only approved stock-issue reports may be corrected through an inventory count'
);
$throws(
    static fn() => $countService->recordCount([
        'product_id' => $productB,
        'physical_quantity' => 1,
        'discrepancy_reason' => 'Linking a correction to a rejected report.',
        'related_adjustment_id' => $rejectedId,
    ], $managerId),
    'Rejected stock-issue reports must not accept correction counts'
);
$throws(
    static fn() => $countService->recordCount([
        'product_id' => $productB,
        'physical_quantity' => 1,
        'discrepancy_reason' => 'Correction counted against the wrong product on purpose.',
        'related_adjustment_id' => $approvedId,
    ], $managerId),
    'A correction count must target the same product as the stock-issue report'
);
$throws(
    static fn() => $countService->recordCount([
        'product_id' => $productA,
        'physical_quantity' => 1,
        'discrepancy_reason' => 'Administrator attempting a linked correction without mutation rights.',
        'related_adjustment_id' => $approvedId,
    ], $adminId),
    'Administrators must not create linked correction counts'
);
$throws(
    static fn() => $countService->recordCount([
        'product_id' => $productA,
        'physical_quantity' => 1,
        'discrepancy_reason' => 'Cashier attempting a linked correction count.',
        'related_adjustment_id' => $approvedId,
    ], $cashierId),
    'Cashiers must not create linked correction counts'
);
$unlinkedCountId = $countService->recordCount([
    'product_id' => $productA,
    'physical_quantity' => $systemBefore,
    'discrepancy_reason' => 'Routine recount with no stock-issue linkage.',
], $managerId);
$unlinked = $pdo->query("SELECT related_adjustment_id FROM inventory_counts WHERE count_id = {$unlinkedCountId}")->fetch(PDO::FETCH_ASSOC);
$assert($unlinked !== false && $unlinked['related_adjustment_id'] === null, 'Routine counts remain unlinked');

// --- Stock Issue terminology covers all four categories ---
$throws(
    static fn() => $submit($cashierId, ['category' => 'vandalized', 'explanation' => 'Unknown category should be refused.']),
    'Unknown categories must be refused'
);
$labelsCaught = false;
try {
    $submit($cashierId, ['category' => 'vandalized']);
} catch (Throwable $exception) {
    $labelsCaught = str_contains($exception->getMessage(), 'Damaged, Missing/Lost, Expired, or Other');
}
$assert($labelsCaught, 'Category validation must name Damaged, Missing/Lost, Expired, and Other');

if ($failures) {
    fwrite(STDERR, "Stock issue oversight contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Stock issue oversight contract: passed\n";
