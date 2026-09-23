<?php
// Contract for the cashier-to-inventory-manager stock-issue workflow (ticket #29):
// role authorization, active-shift enforcement, validation, no mutation on
// submission/rejection, successful approval, and insufficient-stock /
// concurrent-approval protection.
require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Services/StockIssueService.php';

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
    role_id INTEGER NOT NULL
)');
$insertUser = static function (string $name, string $role) use ($pdo, $roleId, $storeId): int {
    $stmt = $pdo->prepare('INSERT INTO users (full_name, branch_id, role_id) VALUES (?, ?, ?)');
    $stmt->execute([$name, $storeId, $roleId($role)]);
    return (int)$pdo->lastInsertId();
};
$cashierId = $insertUser('Cathy Cashier', 'cashier');
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
$pdo->exec("INSERT INTO products (branch_id, sku, barcode, product_name, status)
            VALUES ({$storeId}, 'SKU-DORMANT', 'CODE-DORMANT', 'Dormant Product', 'inactive')");
$inactiveProductId = (int)$pdo->lastInsertId();

$pdo->exec('CREATE TABLE inventory (product_id INTEGER PRIMARY KEY, quantity_on_hand INTEGER NOT NULL DEFAULT 0)');
$pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES ({$activeProductId}, 10)");

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

$quietNotifications = new class($pdo) extends NotificationService {
    public function checkAndNotifyLowStock(): void
    {
    }
    public function checkAndNotifyExpiringStock(int $days = 30): void
    {
    }
};
$service = new StockIssueService($pdo, $quietNotifications);

$stockOf = static function () use ($pdo, $activeProductId): int {
    $stmt = $pdo->prepare('SELECT quantity_on_hand FROM inventory WHERE product_id = ?');
    $stmt->execute([$activeProductId]);
    return (int)$stmt->fetchColumn();
};
$reportOf = static function (int $adjustmentId) use ($pdo): array {
    $stmt = $pdo->prepare('SELECT * FROM inventory_adjustments WHERE adjustment_id = ?');
    $stmt->execute([$adjustmentId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
};
$openShift = static function () use ($pdo, $cashierId): int {
    $pdo->prepare("INSERT INTO cashier_shifts (cashier_id, status) VALUES ({$cashierId}, 'open')")->execute();
    return (int)$pdo->lastInsertId();
};
$closeShifts = static function () use ($pdo): void {
    $pdo->exec("UPDATE cashier_shifts SET status = 'closed'");
};
$validReport = ['product_id' => $activeProductId, 'category' => 'damaged', 'quantity' => 3, 'explanation' => 'Three units crushed during unloading.'];

// Role authorization at the capability-policy seam.
$policy = new RoleCapabilityPolicy();
$assert(!$policy->allows('cashier', RoleCapabilityPolicy::MUTATE_INVENTORY), 'Cashiers must not hold inventory-mutation capability');
$assert(!$policy->allows('admin', RoleCapabilityPolicy::MUTATE_INVENTORY), 'Administrators must not hold inventory-mutation capability');
$assert($policy->allows('inventory_manager', RoleCapabilityPolicy::MUTATE_INVENTORY), 'Inventory Managers must hold inventory-mutation capability');

// Role authorization at the service seam.
$throws(
    static fn() => $service->submitReport($validReport, $managerId),
    'Only cashiers may submit stock issue reports'
);
$throws(
    static fn() => $service->approveReport(1, $cashierId),
    'Cashiers must not approve stock issue reports'
);
$throws(
    static fn() => $service->approveReport(1, $adminId),
    'Administrators must not approve stock issue reports into inventory mutations'
);
$throws(
    static fn() => $service->rejectReport(1, $adminId, 'No.'),
    'Administrators must not reject stock issue reports'
);

// Active-shift enforcement: submission without a shift is refused.
$throws(
    static fn() => $service->submitReport($validReport, $cashierId),
    'Submission without an active shift must be refused'
);

// Validation (with an open shift present).
$shiftId = $openShift();
$throws(
    static fn() => $service->submitReport(['product_id' => $activeProductId, 'category' => 'vandalized', 'quantity' => 1, 'explanation' => 'Not a category.'], $cashierId),
    'Unknown categories must be rejected'
);
$throws(
    static fn() => $service->submitReport(['product_id' => $activeProductId, 'category' => 'damaged', 'quantity' => 0, 'explanation' => 'Zero units.'], $cashierId),
    'Zero quantity must be rejected'
);
$throws(
    static fn() => $service->submitReport(['product_id' => $activeProductId, 'category' => 'expired', 'quantity' => 1, 'explanation' => '   '], $cashierId),
    'Missing explanation must be rejected'
);
$throws(
    static fn() => $service->submitReport(['product_id' => $activeProductId, 'category' => 'other', 'quantity' => 1, 'explanation' => 'Misc'], $cashierId),
    'Other with a trivial explanation must be rejected'
);
$throws(
    static fn() => $service->submitReport(['product_id' => $inactiveProductId, 'category' => 'damaged', 'quantity' => 1, 'explanation' => 'Inactive product report.'], $cashierId),
    'Inactive products must be rejected'
);

// Submission creates a pending report linked to cashier + shift, without touching inventory.
$before = $stockOf();
$adjustmentId = $service->submitReport($validReport, $cashierId);
$report = $reportOf($adjustmentId);
$assert($report['status'] === 'pending', 'Submission must create a pending report');
$assert((int)$report['reported_by'] === $cashierId, 'Report must be associated with the cashier');
$assert((int)$report['shift_id'] === $shiftId, 'Report must be associated with the active shift');
$assert((int)$report['adjustment_qty'] === -3, 'Reported quantity must be stored as a loss');
$assert($stockOf() === $before, 'Submission must not change inventory');

// Manager review queue surfaces the pending report with available stock.
$pending = $service->getPendingReports();
$assert(count($pending) === 1 && (int)$pending[0]['adjustment_id'] === $adjustmentId, 'Pending report must appear in the manager queue');
$assert((int)$pending[0]['quantity_on_hand'] === $before, 'Queue must expose available stock for the approval decision');

// Rejection requires a reason and leaves inventory unchanged.
$throws(
    static fn() => $service->rejectReport($adjustmentId, $managerId, '   '),
    'Rejection without a reason must be refused'
);
$service->rejectReport($adjustmentId, $managerId, 'Recount shows the units are sellable.');
$rejected = $reportOf($adjustmentId);
$assert($rejected['status'] === 'rejected', 'Rejected report must record the rejected status');
$assert((int)$rejected['approved_by'] === $managerId, 'Rejection must record the actor');
$assert($rejected['approved_at'] !== null, 'Rejection must record the timestamp');
$assert($stockOf() === $before, 'Rejection must not change inventory');
$throws(
    static fn() => $service->approveReport($adjustmentId, $managerId),
    'Rejected reports must not be approvable afterwards'
);

// History remains viewable without an active shift.
$closeShifts();
$history = $service->getReportsByCashier($cashierId);
$assert(count($history) === 1 && $history[0]['status'] === 'rejected', 'Cashiers must view their own history without an active shift');

// Successful approval deducts the full quantity and links a stock movement.
$secondShift = $openShift();
$approveId = $service->submitReport(
    ['product_id' => $activeProductId, 'category' => 'missing', 'quantity' => 4, 'explanation' => 'Four units missing after shelf audit.'],
    $cashierId
);
$service->approveReport($approveId, $managerId, 'Verified against shelf audit.');
$approved = $reportOf($approveId);
$assert($approved['status'] === 'approved', 'Approval must record the approved status');
$assert((int)$approved['approved_by'] === $managerId, 'Approval must record the actor');
$assert($approved['approved_at'] !== null, 'Approval must record the timestamp');
$assert($stockOf() === $before - 4, 'Approval must deduct the full reported quantity');
$movementStmt = $pdo->prepare('SELECT * FROM stock_movements WHERE adjustment_id = ?');
$movementStmt->execute([$approveId]);
$movement = $movementStmt->fetch(PDO::FETCH_ASSOC);
$assert($movement !== false && (int)$movement['change_qty'] === -4, 'Approval must record a linked stock movement for the full quantity');
$assert($movement !== false && (int)$movement['moved_by'] === $managerId, 'Movement must be attributed to the approver');
$assert($movement !== false && (int)$movement['product_id'] === $activeProductId, 'Movement must reference the reported product');

// Insufficient stock blocks approval instead of going negative or partial.
$shortId = $service->submitReport(
    ['product_id' => $activeProductId, 'category' => 'expired', 'quantity' => $stockOf() + 5, 'explanation' => 'Far more units than the shelf holds.'],
    $cashierId
);
$throws(
    static fn() => $service->approveReport($shortId, $managerId),
    'Approval with insufficient stock must be refused'
);
$assert($reportOf($shortId)['status'] === 'pending', 'Refused approval must leave the report pending');
$assert($stockOf() === $before - 4, 'Refused approval must leave inventory unchanged');

// Concurrent-approval protection: a second approval attempt fails.
$raceId = $service->submitReport(
    ['product_id' => $activeProductId, 'category' => 'damaged', 'quantity' => 2, 'explanation' => 'Two units damaged by a fallen shelf.'],
    $cashierId
);
$service->approveReport($raceId, $managerId);
$throws(
    static fn() => $service->approveReport($raceId, $managerId),
    'Double approval must be refused'
);
$throws(
    static fn() => $service->rejectReport($raceId, $managerId, 'Too late.'),
    'Approved reports must not be rejectable afterwards'
);

// Stock that moves after submission is re-checked: drain to zero, then refuse.
$drainId = $service->submitReport(
    ['product_id' => $activeProductId, 'category' => 'damaged', 'quantity' => 1, 'explanation' => 'One unit crushed in the stockroom.'],
    $cashierId
);
$pdo->prepare('UPDATE inventory SET quantity_on_hand = 0 WHERE product_id = ?')->execute([$activeProductId]);
$throws(
    static fn() => $service->approveReport($drainId, $managerId),
    'Approval after stock drains must be refused rather than go negative'
);
$assert($stockOf() === 0, 'Inventory must never go negative through approvals');

if ($failures) {
    fwrite(STDERR, "Stock issue contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Stock issue contract: passed\n";
