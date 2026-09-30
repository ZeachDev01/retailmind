<?php
// Cashier Shift opening contract (ticket #88).
//
// Opening a Cashier Shift is an exclusively owned drawer session: the
// authenticated Cashier, in the active Cashier workspace, picks an available
// Register and confirms a non-negative opening float. The contract exercises
// that flow at the service seam, then proves the database-level constraints
// that keep both exclusivity rules true when two requests race.
//
// The SQLite fixture expresses each MySQL constraint in its SQLite equivalent
// (a partial unique index standing in for the generated-column unique key) so
// the rule under test is identical and only the dialect differs.
require_once __DIR__ . '/../bootstrap/app.php';

use App\Authorization\RoleCapabilityPolicy;
use App\Services\CashierShiftService;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$expectRefusal = static function (callable $operation, string $message) use (&$failures): void {
    try {
        $operation();
        $failures[] = $message;
    } catch (DomainException | InvalidArgumentException $exception) {
        // Expected: an authorization (DomainException) or validation
        // (InvalidArgumentException) refusal the operator can act on.
    }
};
$expectDatabaseRejection = static function (callable $operation, string $message) use (&$failures): void {
    try {
        $operation();
        $failures[] = $message;
    } catch (PDOException $exception) {
        // Expected: the database refused a write that application validation
        // would have caught, which is exactly what a race looks like.
    }
};

try {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec('CREATE TABLE users (
        user_id INTEGER PRIMARY KEY AUTOINCREMENT,
        full_name TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT \'active\'
    )');
    $pdo->exec("CREATE TABLE registers (
        register_id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'active',
        disabled_at TEXT NULL,
        created_by INTEGER NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec('CREATE UNIQUE INDEX idx_registers_name ON registers (name)');
    $pdo->exec("CREATE TABLE cashier_shifts (
        shift_id INTEGER PRIMARY KEY AUTOINCREMENT,
        cashier_id INTEGER NOT NULL,
        register_id INTEGER NULL,
        opened_at TEXT DEFAULT CURRENT_TIMESTAMP,
        opening_cash REAL NOT NULL DEFAULT 0.00,
        status TEXT NOT NULL DEFAULT 'open',
        closed_at TEXT NULL,
        expected_cash REAL NULL,
        actual_cash REAL NULL,
        cash_variance REAL NULL,
        closing_notes TEXT NULL,
        reviewed_by INTEGER NULL,
        reviewed_at TEXT NULL,
        locked_at TEXT NULL
    )");
    // Exclusivity as MySQL expresses it through generated columns: only rows
    // whose status is 'open' are constrained, so a Cashier and a Register each
    // keep an unlimited history of closed shifts.
    $pdo->exec("CREATE UNIQUE INDEX uq_cashier_shifts_open_cashier
        ON cashier_shifts (cashier_id) WHERE status = 'open'");
    $pdo->exec("CREATE UNIQUE INDEX uq_cashier_shifts_open_register
        ON cashier_shifts (register_id) WHERE status = 'open'");
    $pdo->exec('CREATE INDEX idx_cashier_shifts_register ON cashier_shifts (register_id)');
    // calculateShift() carries the Register into the closing Protected Audit
    // Record, so the summary must be able to name it.
    $pdo->exec("CREATE TABLE sales (
        sale_id INTEGER PRIMARY KEY AUTOINCREMENT,
        cashier_id INTEGER NOT NULL,
        shift_id INTEGER NULL,
        total_amount REAL NOT NULL DEFAULT 0.00,
        payment_method TEXT NOT NULL DEFAULT 'cash',
        discount_amount REAL NOT NULL DEFAULT 0.00
    )");
    $pdo->exec("CREATE TABLE sale_reversals (
        reversal_id INTEGER PRIMARY KEY AUTOINCREMENT,
        sale_id INTEGER NOT NULL,
        refund_amount REAL NOT NULL DEFAULT 0.00,
        status TEXT NOT NULL DEFAULT 'approved',
        settlement_method TEXT NOT NULL DEFAULT 'cash'
    )");
    // Ticket #92: calculateShift() also subtracts the append-only Cash Refunds
    // the shift issued, so this fixture carries that ledger too. It is empty
    // here because this contract is about the Register, not about refunds.
    $pdo->exec("CREATE TABLE cash_refunds (
        refund_id INTEGER PRIMARY KEY AUTOINCREMENT,
        sale_id INTEGER NOT NULL,
        shift_id INTEGER NOT NULL,
        cashier_id INTEGER NOT NULL,
        refund_amount REAL NOT NULL DEFAULT 0.00,
        payment_method TEXT NOT NULL DEFAULT 'cash',
        reason TEXT NOT NULL,
        note TEXT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE cash_drawer_movements (
        drawer_movement_id INTEGER PRIMARY KEY AUTOINCREMENT,
        shift_id INTEGER NOT NULL,
        movement_type TEXT NOT NULL,
        amount REAL NOT NULL,
        reason TEXT NOT NULL,
        recorded_by INTEGER NOT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE activity_log (
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
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("INSERT INTO users (user_id, full_name) VALUES (1, 'Casey Cashier'), (2, 'Dana Cashier'), (3, 'Alex Admin')");
    $pdo->exec("INSERT INTO registers (register_id, name, status) VALUES
        (10, 'Front Counter', 'active'),
        (11, 'Back Counter', 'active'),
        (12, 'Retired Till', 'disabled')");
    $pdo->exec("UPDATE registers SET disabled_at = '2026-09-01 09:00:00' WHERE register_id = 12");

    $service = new CashierShiftService($pdo, new RoleCapabilityPolicy());
    $casey = 1;
    $dana = 2;
    $admin = 3;

    // --- The opening flow lists only enabled Registers with no open shift (AC 1) ---
    $availableIds = array_map('intval', array_column($service->availableRegisters(), 'register_id'));
    sort($availableIds);
    $assert($availableIds === [10, 11], 'the opening flow offers exactly the enabled Registers');
    $assert(
        !in_array(12, $availableIds, true),
        'a disabled Register is never offered for a new Cashier Shift'
    );

    // --- Opening requires the Cashier workspace, a Register, and a float (AC 2) ---
    foreach (['admin', 'super_admin', 'inventory_manager'] as $role) {
        $expectRefusal(
            fn() => $service->openShift($admin, $role, 10, 100.0),
            "the {$role} workspace must not open a Cashier Shift"
        );
    }
    $expectRefusal(
        fn() => $service->openShift($casey, 'cashier', 0, 100.0),
        'a Cashier Shift requires a selected Register'
    );
    $expectRefusal(
        fn() => $service->openShift($casey, 'cashier', 999, 100.0),
        'an unknown Register cannot anchor a Cashier Shift'
    );
    $expectRefusal(
        fn() => $service->openShift($casey, 'cashier', 12, 100.0),
        'a disabled Register cannot anchor a Cashier Shift'
    );
    $expectRefusal(
        fn() => $service->openShift($casey, 'cashier', 10, -0.01),
        'a negative opening float is refused'
    );
    $assert(
        (int)$pdo->query("SELECT COUNT(*) FROM cashier_shifts WHERE status = 'open'")->fetchColumn() === 0,
        'a refused opening leaves no Cashier Shift behind'
    );

    // --- Opening binds the Cashier to the Register (AC 2, happy path) ---
    $shiftId = $service->openShift($casey, 'cashier', 10, 250.50);
    $assert($shiftId > 0, 'opening returns the new Cashier Shift identity');
    $row = $pdo->query("SELECT * FROM cashier_shifts WHERE shift_id = {$shiftId}")->fetch(PDO::FETCH_ASSOC);
    $assert((int)$row['cashier_id'] === $casey, 'the Cashier owns the shift they opened');
    $assert((int)$row['register_id'] === 10, 'the shift records the selected Register');
    $assert(abs((float)$row['opening_cash'] - 250.50) < 0.001, 'the shift records the confirmed opening float');
    $assert($row['status'] === 'open', 'a new shift is open');

    // --- The open shift reports the Register it anchors ---
    $open = $service->getOpenShift($casey);
    $assert($open !== null && (int)$open['register_id'] === 10, 'the open shift reports its Register');
    $assert($open['register_name'] === 'Front Counter', 'the open shift names the Register the Cashier picked');

    // The reconciling summary names the Register too, because the page writes
    // that summary into the closing Protected Audit Record.
    $pdo->prepare("INSERT INTO sales (cashier_id, shift_id, total_amount, payment_method) VALUES (?, ?, 40.00, 'cash')")
        ->execute([$casey, $shiftId]);
    $summary = $service->calculateShift($shiftId);
    $assert($summary['register_name'] === 'Front Counter', 'the reconciling summary names the Register');
    $assert(
        abs((float)$summary['calculated_expected_cash'] - 290.50) < 0.001,
        'the reconciling summary still counts the opening float'
    );

    // --- A Cashier owns at most one open shift; a Register joins one (AC 3) ---
    $expectRefusal(
        fn() => $service->openShift($casey, 'cashier', 11, 100.0),
        'a Cashier cannot own two open Cashier Shifts'
    );
    $expectRefusal(
        fn() => $service->openShift($dana, 'cashier', 10, 100.0),
        'a Register cannot belong to two open Cashier Shifts'
    );
    $assert(
        (int)$pdo->query("SELECT COUNT(*) FROM cashier_shifts WHERE status = 'open'")->fetchColumn() === 1,
        'refusals never create a second open shift'
    );
    $busyIds = array_map('intval', array_column($service->availableRegisters(), 'register_id'));
    sort($busyIds);
    $assert($busyIds === [11], 'an occupied Register leaves the opening flow immediately');
    $danaShift = $service->openShift($dana, 'cashier', 11, 0.0);
    $assert($danaShift > 0, 'a second Cashier may open a shift on a free Register');
    $assert(
        $service->availableRegisters() === [],
        'with every Register occupied the opening flow offers nothing'
    );

    // --- Database-level constraints hold when two requests race (AC 4) ---
    $pdo->exec("UPDATE cashier_shifts SET status = 'closed' WHERE shift_id = {$danaShift}");
    $expectDatabaseRejection(
        fn() => $pdo->prepare(
            "INSERT INTO cashier_shifts (cashier_id, register_id, opening_cash) VALUES (?, ?, 0.00)"
        )->execute([$dana, 10]),
        'the database refuses a second open shift for one Cashier'
    );
    $expectDatabaseRejection(
        fn() => $pdo->prepare(
            "INSERT INTO cashier_shifts (cashier_id, register_id, opening_cash) VALUES (?, ?, 0.00)"
        )->execute([$casey, 12]),
        'the database refuses a second open shift on one Register'
    );
    // The constraint is scoped to open shifts, so history is never blocked.
    foreach ([0, 1] as $ignored) {
        $pdo->prepare(
            "INSERT INTO cashier_shifts (cashier_id, register_id, opening_cash, status) VALUES (?, ?, 0.00, 'closed')"
        )->execute([$dana, 10]);
    }
    $assert(
        (int)$pdo->query("SELECT COUNT(*) FROM cashier_shifts WHERE status = 'closed'")->fetchColumn() === 3,
        'closed history stays unlimited for both a Cashier and a Register'
    );

    // A request that loses a race must reach the operator as a readable
    // refusal, never as a raw database error. The trigger stands in for the
    // competing request committing between this call's validation and insert.
    $expectLostRace = static function (string $winner) use ($assert, $failures, $service, $pdo, $dana): void {
        $pdo->exec('DROP TRIGGER IF EXISTS race_winner');
        $pdo->exec("CREATE TRIGGER race_winner BEFORE INSERT ON cashier_shifts
            BEGIN
                INSERT INTO cashier_shifts (cashier_id, register_id, opening_cash)
                VALUES (2, {$winner}, 500.00);
            END");
        try {
            $service->openShift($dana, 'cashier', 10, 100.0);
            $failures[] = "the losing request for a busy {$winner} is refused";
        } catch (DomainException $exception) {
            $assert(
                trim($exception->getMessage()) !== '',
                "the losing request for a busy {$winner} explains itself"
            );
        } catch (PDOException $exception) {
            $failures[] = "a lost {$winner} race is an operator-friendly error, not a database error";
        }
        $pdo->exec('DROP TRIGGER race_winner');
        $pdo->exec("DELETE FROM cashier_shifts WHERE status = 'open'");
    };
    $expectLostRace('11');
    $expectLostRace('10');
    $assert(
        (int)$pdo->query("SELECT COUNT(*) FROM cashier_shifts WHERE status = 'open'")->fetchColumn() === 0,
        'a lost race rolls back cleanly and leaves no open shift behind'
    );
    $assert(
        (int)$pdo->query("SELECT COUNT(*) FROM activity_log WHERE module = 'Cashier Shifts'")->fetchColumn() === 2,
        'a lost race writes no Protected Audit Record'
    );

    // --- The opening event is a Protected Audit Record (AC 5) ---
    $audit = $pdo->query(
        "SELECT user_id, action, category, module, record_id, new_value
         FROM activity_log WHERE module = 'Cashier Shifts' ORDER BY log_id LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    $assert($audit !== false, 'opening a Cashier Shift records a Protected Audit Record');
    $assert((int)$audit['user_id'] === $casey, 'the Protected Audit Record names the actor');
    $assert($audit['category'] === 'store_operation', 'a Cashier Shift is a Store operation record');
    $assert((int)$audit['record_id'] === $shiftId, 'the Protected Audit Record names the Cashier Shift');
    $payload = json_decode((string)$audit['new_value'], true);
    $assert(is_array($payload), 'the Protected Audit Record payload is readable');
    $assert((int)($payload['shift_id'] ?? 0) === $shiftId, 'the record names the Cashier Shift');
    $assert((int)($payload['cashier_id'] ?? 0) === $casey, 'the record names the owning Cashier');
    $assert((int)($payload['register_id'] ?? 0) === 10, 'the record names the Register');
    $assert(($payload['register_name'] ?? null) === 'Front Counter', 'the record names the Register as the operator knows it');
    $assert(
        isset($payload['opening_float']) && abs((float)$payload['opening_float'] - 250.50) < 0.001,
        'the record names the confirmed opening float'
    );
    $assert(($payload['actor_role'] ?? null) === 'cashier', 'the record retains the acting Cashier workspace');
} catch (Throwable $exception) {
    $failures[] = 'Cashier Shift opening contract threw: ' . $exception->getMessage();
}

if ($failures) {
    fwrite(STDERR, "Cashier Shift opening contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Cashier Shift opening contract: passed\n";
