<?php
// Cashier Shift register lock and resume contract (ticket #90).
//
// A Cashier who steps away locks the point of sale without closing their shift:
// the drawer stays theirs, the Register stays claimed, and the Cashier Shift
// stays open so no reconciliation is triggered. The lock is recorded against the
// shift row rather than the session, so losing the session — an idle timeout or
// a logout — never silently reopens a register the Cashier walked away from.
//
// Resumption is the owning Cashier's normal account password. No separate PIN or
// second credential is introduced, so a Cashier who has forgotten their password
// cannot be talked into a weaker one at the till.
//
// The SQLite fixture expresses each MySQL constraint in its SQLite equivalent so
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
    } catch (DomainException | InvalidArgumentException | RuntimeException $exception) {
        // Expected: an operator-readable refusal, never a raw database error
        // (ADR-0002).
        if (trim($exception->getMessage()) === '') {
            $failures[] = $message . ' (and it explains itself)';
        }
    }
};

try {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // MySQL NOW(), expressed in SQLite terms so only the dialect differs.
    $pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'), 0);

    $pdo->exec('CREATE TABLE users (
        user_id INTEGER PRIMARY KEY AUTOINCREMENT,
        full_name TEXT NOT NULL,
        username TEXT NULL,
        password_hash TEXT NULL,
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
    $pdo->exec('CREATE TABLE cashier_shifts (
        shift_id INTEGER PRIMARY KEY AUTOINCREMENT,
        cashier_id INTEGER NOT NULL,
        register_id INTEGER NULL,
        opened_at TEXT DEFAULT CURRENT_TIMESTAMP,
        opening_cash REAL NOT NULL DEFAULT 0.00,
        status TEXT NOT NULL DEFAULT \'open\',
        closed_at TEXT NULL,
        expected_cash REAL NULL,
        actual_cash REAL NULL,
        cash_variance REAL NULL,
        closing_notes TEXT NULL,
        reviewed_by INTEGER NULL,
        reviewed_at TEXT NULL,
        locked_at TEXT NULL
    )');
    // The open-shift exclusivity of ticket #88, expressed as partial indexes.
    $pdo->exec("CREATE UNIQUE INDEX uq_cashier_shifts_open_cashier
        ON cashier_shifts (cashier_id) WHERE status = 'open'");
    $pdo->exec("CREATE UNIQUE INDEX uq_cashier_shifts_open_register
        ON cashier_shifts (register_id) WHERE status = 'open'");
    // The drawer a locked Register has to protect: a pay-in, a pay-out, and the
    // reconciliation all write against it.
    $pdo->exec('CREATE TABLE cash_drawer_movements (
        drawer_movement_id INTEGER PRIMARY KEY AUTOINCREMENT,
        shift_id INTEGER NOT NULL,
        movement_type TEXT NOT NULL,
        amount REAL NOT NULL,
        reason TEXT NOT NULL,
        recorded_by INTEGER NOT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )');
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
    // Reconciliation includes refunds issued under this shift (#92).
    $pdo->exec("CREATE TABLE cash_refunds (
        refund_id INTEGER PRIMARY KEY AUTOINCREMENT,
        shift_id INTEGER NOT NULL,
        refund_amount REAL NOT NULL DEFAULT 0.00,
        payment_method TEXT NOT NULL DEFAULT 'cash'
    )");
    // Ticket #91: a closure reads this table to refuse a shift that still has a
    // cart parked on it, so it has to exist here even though this contract never
    // parks one. Held-sale behaviour itself is proven in
    // held_sale_shift_contract.php; all that is needed here is for the closure
    // path this contract exercises to have the table it queries.
    $pdo->exec("CREATE TABLE held_sales (
        held_sale_id INTEGER PRIMARY KEY AUTOINCREMENT,
        cashier_id INTEGER NOT NULL,
        shift_id INTEGER NULL,
        reference_no TEXT NOT NULL,
        customer_label TEXT NULL,
        status TEXT NOT NULL DEFAULT 'held',
        item_count INTEGER NOT NULL DEFAULT 0,
        total_amount REAL NOT NULL DEFAULT 0.00,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        expires_at TEXT NULL
    )");
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
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )');

    // Every Cashier authenticates with their own normal account password. The
    // hashes are real so the unlock path exercises password_verify exactly as
    // sign-in does, rather than a comparison against a stored value.
    $insertCashier = static function (string $name, string $username, string $password) use ($pdo): int {
        $pdo->prepare('INSERT INTO users (full_name, username, password_hash) VALUES (?, ?, ?)')
            ->execute([$name, $username, password_hash($password, PASSWORD_DEFAULT)]);
        return (int)$pdo->lastInsertId();
    };
    $casey = $insertCashier('Casey Cashier', 'casey', 'casey-pos-1');
    $dana = $insertCashier('Dana Cashier', 'dana', 'dana-pos-2');
    $alex = $insertCashier('Alex Cashier-Also-Administrator', 'alex', 'alex-pos-3');
    $pdo->exec("INSERT INTO registers (register_id, name, status) VALUES
        (10, 'Front Counter', 'active'),
        (11, 'Back Counter', 'active')");

    $service = new CashierShiftService($pdo, new RoleCapabilityPolicy());

    // --- Locking needs an open shift, the Cashier workspace, and once only ---
    $expectRefusal(
        fn() => $service->lockRegister($casey, 'cashier'),
        'locking is refused when the Cashier has no open Cashier Shift'
    );
    foreach (['admin', 'super_admin', 'inventory_manager'] as $role) {
        $expectRefusal(
            fn() => $service->lockRegister($alex, $role),
            "the {$role} workspace must not lock a Register"
        );
    }

    $shiftId = $service->openShift($casey, 'cashier', 10, 500.00);
    $lockedShiftId = $service->lockRegister($casey, 'cashier');
    $assert($lockedShiftId === $shiftId, 'locking names the Cashier Shift it secured');
    $assert($service->isRegisterLocked($casey), 'the Register reports itself locked');
    $expectRefusal(
        fn() => $service->lockRegister($casey, 'cashier'),
        'locking an already locked Register is refused'
    );

    // --- Locking never closes or reconciles the shift (AC 1, AC 3) ----------
    $open = $service->getOpenShift($casey);
    $assert($open !== null, 'a locked Register keeps the Cashier Shift open');
    $assert($open['status'] === 'open', 'locking leaves the shift open, not closed');
    $assert((int)$open['register_id'] === 10, 'locking leaves the shift on the same Register');
    $assert(
        abs((float)$open['opening_cash'] - 500.00) < 0.001,
        'locking leaves the confirmed opening float untouched'
    );
    $assert($open['closed_at'] === null, 'locking records no closing time');
    $assert($open['cash_variance'] === null, 'locking triggers no reconciliation');
    $assert(!empty($open['locked_at']), 'the lock time is recorded against the shift');
    $assert(
        (int)$pdo->query("SELECT COUNT(*) FROM cashier_shifts WHERE status = 'open'")->fetchColumn() === 1,
        'locking creates no second Cashier Shift'
    );

    // The lock must refuse point-of-sale work rather than merely hide the page.
    $expectRefusal(
        fn() => $service->requireUnlockedRegister($casey),
        'a locked Register refuses point-of-sale work'
    );
    // A Cashier with no open shift is not "locked": the missing-shift gate is the
    // one that has to speak, so the lock guard must not pre-empt it.
    $service->requireUnlockedRegister($dana);

    // --- Only the owning Cashier can unlock, and only with their password ---
    // The workspace is settled before the password on both sides of the lock, so
    // an Administrator who also holds the Cashier role cannot secure or resume a
    // Register from the administrative workspace.
    $expectRefusal(
        fn() => $service->unlockRegister($casey, 'admin', 'casey-pos-1'),
        'the administrative workspace must not unlock a Register'
    );
    $assert($service->isRegisterLocked($casey), 'a workspace refusal leaves the Register locked');
    $expectRefusal(
        fn() => $service->unlockRegister($dana, 'cashier', 'casey-pos-1'),
        'another Cashier cannot unlock a Register they do not own'
    );
    $expectRefusal(
        fn() => $service->unlockRegister($casey, 'cashier', ''),
        'an empty password cannot unlock a Register'
    );
    $expectRefusal(
        fn() => $service->unlockRegister($casey, 'cashier', 'wrong-password'),
        'a wrong password cannot unlock a Register'
    );
    $expectRefusal(
        fn() => $service->unlockRegister($casey, 'cashier', 'dana-pos-2'),
        'another Cashier\'s password cannot unlock this Register'
    );
    $assert($service->isRegisterLocked($casey), 'every refused unlock leaves the Register locked');
    $assert(
        (int)$pdo->query("SELECT COUNT(*) FROM cashier_shifts WHERE status = 'open'")->fetchColumn() === 1,
        'a refused unlock never closes the Cashier Shift'
    );

    // --- The owning Cashier resumes the same shift and Register (AC 4) ------
    $resumed = $service->unlockRegister($casey, 'cashier', 'casey-pos-1');
    $assert($resumed === $shiftId, 'resuming names the same Cashier Shift');
    $assert(!$service->isRegisterLocked($casey), 'the correct password unlocks the Register');
    $service->requireUnlockedRegister($casey);
    $resumedShift = $service->getOpenShift($casey);
    $assert((int)$resumedShift['shift_id'] === $shiftId, 'resuming keeps the same open shift');
    $assert((int)$resumedShift['register_id'] === 10, 'resuming keeps the same Register');
    $assert($resumedShift['register_name'] === 'Front Counter', 'the resumed shift still names its Register');
    $expectRefusal(
        fn() => $service->unlockRegister($casey, 'cashier', 'casey-pos-1'),
        'unlocking a Register that is not locked is refused'
    );

    // --- No separate PIN or second credential is introduced (AC 2) ----------
    // Unlocking takes `pos_unlock_password` and nothing else: the account's own
    // password. Whether a column for some other credential reached the schema
    // is register_lock_schema_parity_contract.php's question, not this one's.
    $posSource = (string)@file_get_contents(dirname(__DIR__, 3) . '/src/frontend/components/cashier/pos.php');
    $assert(
        preg_match('/<form method="post" class="pos-lock-form".*?<\/form>/s', $posSource, $unlockForm) === 1,
        'the lock screen carries one unlock form'
    );
    preg_match_all('/<input\b[^>]*>/', $unlockForm[0] ?? '', $unlockInputs);
    $assert(
        count($unlockInputs[0] ?? []) === 2
        && str_contains($unlockForm[0] ?? '', 'name="pos_unlock_password"')
        && str_contains($unlockForm[0] ?? '', 'csrf_field()'),
        'the unlock form asks for a CSRF token and the Cashier account password, and nothing else'
    );
    $assert(
        !preg_match('/name="(pos_)?(pin|unlock_code)"/i', $posSource),
        'no PIN or unlock-code field is introduced'
    );

    // --- Logging out leaves the shift open, and the Register still claimed ---
    // Behaviour first: a real session is started, the Cashier locks their
    // Register, and the session is destroyed exactly as logout_user() destroys
    // it. If anything on that path reached the shift, a Cashier's break would
    // silently reconcile their drawer and free their Register for somebody else
    // to claim.
    $service->lockRegister($casey, 'cashier');
    App\Core\Session::start();
    $_SESSION['user_id'] = $casey;
    $_SESSION['role'] = 'cashier';
    $assert(
        App\Core\Session::get('user_id') === $casey,
        'the Cashier is signed in before the session ends'
    );
    App\Core\Session::destroy();
    $assert(
        App\Core\Session::get('user_id') === null,
        'logging out ends the session'
    );
    $assert(
        $service->isRegisterLocked($casey),
        'a Register locked before logout stays locked after it'
    );
    $stillOpen = $service->getOpenShift($casey);
    $assert($stillOpen !== null, 'logging out leaves the Cashier Shift open');
    $assert($stillOpen['status'] === 'open', 'logging out does not close the Cashier Shift');
    $assert($stillOpen['closed_at'] === null, 'logging out records no closing time');
    $assert($stillOpen['cash_variance'] === null, 'logging out reconciles nothing');
    $busyIds = array_map('intval', array_column($service->availableRegisters(), 'register_id'));
    sort($busyIds);
    $assert($busyIds === [11], 'the Register stays claimed after logout');

    // Signing in again resumes the same shift on the same Register, and only the
    // owner's password lifts the lock.
    App\Core\Session::start();
    $_SESSION['user_id'] = $casey;
    $assert(
        (int)$service->getOpenShift($casey)['shift_id'] === $shiftId,
        'the Cashier signs back in to the same Cashier Shift'
    );
    $assert(
        (int)$service->getOpenShift($casey)['register_id'] === 10,
        'the Cashier signs back in to the same Register'
    );
    $expectRefusal(
        fn() => $service->unlockRegister($casey, 'cashier', 'wrong-password'),
        'signing in again is not enough to unlock the Register'
    );
    $assert($service->unlockRegister($casey, 'cashier', 'casey-pos-1') === $shiftId, 'the owner resumes with their password');
    App\Core\Session::destroy();

    // The source check complements that behaviour: logout must be the session
    // destruction and nothing else, so no future edit can quietly add shift
    // handling to it.
    $auth = (string)@file_get_contents(dirname(__DIR__, 3) . '/src/backend/includes/auth.php');
    $logoutPage = (string)@file_get_contents(dirname(__DIR__, 3) . '/src/frontend/components/auth/logout.php');
    $assert($auth !== '' && $logoutPage !== '', 'the logout sources must be readable');
    $assert(
        preg_match('/function logout_user\(\): void\s*\{\s*App\\\\Core\\\\Session::destroy\(\);\s*\}/', $auth) === 1,
        'logout destroys the session and does nothing else'
    );
    $assert(
        !preg_match('/cashier_shifts/i', $logoutPage),
        'logging out never touches the Cashier Shift'
    );

    // --- Another Cashier cannot transact on or claim it (AC 5) --------------
    $service->lockRegister($casey, 'cashier');
    $expectRefusal(
        fn() => $service->openShift($dana, 'cashier', 10, 100.00),
        'another Cashier cannot claim a Register whose shift is locked'
    );
    $assert(
        (int)$pdo->query("SELECT COUNT(*) FROM cashier_shifts WHERE cashier_id = {$dana}")->fetchColumn() === 0,
        'a refused claim creates no Cashier Shift for the other Cashier'
    );
    $expectRefusal(
        fn() => $service->unlockRegister($dana, 'cashier', 'dana-pos-2'),
        'another Cashier cannot unlock a Register they never owned'
    );
    $otherShift = $service->openShift($dana, 'cashier', 11, 0.00);
    $service->requireUnlockedRegister($dana);
    $assert($otherShift !== $shiftId, 'the other Cashier\'s shift is their own, not the locked one');
    $assert(
        (int)$service->getOpenShift($dana)['register_id'] === 11,
        'the other Cashier works their own Register, never the locked one'
    );

    // --- A locked Register pauses the drawer, at the service seam -----------
    // A pay-in and a reconciliation are both financial records written under the
    // Cashier's name. Page-level gating would be advisory, so the refusal has to
    // live where a direct call to the service still meets it.
    $expectRefusal(
        fn() => $service->addDrawerMovement($casey, 'pay_in', 200.00, 'Float top-up'),
        'a locked Register refuses a pay-in from its own Cashier'
    );
    $expectRefusal(
        fn() => $service->addDrawerMovement($casey, 'pay_out', 50.00, 'Petty cash'),
        'a locked Register refuses a pay-out from its own Cashier'
    );
    $expectRefusal(
        fn() => $service->closeShift($casey, 500.00, 'Closing while on a break'),
        'a locked Register refuses reconciliation by its own Cashier'
    );
    $assert(
        (int)$pdo->query('SELECT COUNT(*) FROM cash_drawer_movements')->fetchColumn() === 0,
        'a locked Register records no drawer movement'
    );
    $assert(
        (int)$pdo->query("SELECT COUNT(*) FROM cashier_shifts WHERE status = 'closed'")->fetchColumn() === 0,
        'a refused reconciliation closes nothing'
    );

    // An Administrator intervening on the abandoned shift is the one way a locked
    // Register is ever released, so the lock must not refuse it. A Cashier who
    // has forgotten their password is therefore never stranded by it.
    $adminSummary = $service->closeShift($casey, 500.00, 'Cashier did not return', $alex);
    $assert((int)$adminSummary['shift_id'] === $shiftId, 'an Administrator can still close an abandoned locked shift');
    $assert((int)$adminSummary['reviewed_by'] === $alex, 'the closing Administrator is recorded as the actor');
    $assert($adminSummary['status'] === 'closed', 'an Administrator close really does end the shift');
    $releasedIds = array_map('intval', array_column($service->availableRegisters(), 'register_id'));
    sort($releasedIds);
    $assert(
        $releasedIds === [10],
        'closing an abandoned locked shift releases the Register for the next Cashier'
    );

    // --- Locking and unlocking are Protected Audit Records ------------------
    $root = dirname(__DIR__, 3);
    $auditRows = $pdo->query(
        "SELECT user_id, action, category, module, record_id, new_value
         FROM activity_log WHERE module = 'Cashier Shifts' ORDER BY log_id"
    )->fetchAll(PDO::FETCH_ASSOC);
    $byAction = [];
    foreach ($auditRows as $row) {
        $byAction[$row['action']][] = $row;
    }
    $assert(
        count($byAction['Cashier Shift locked'] ?? []) === 3,
        'each successful lock is a Protected Audit Record, and refusals write none'
    );
    $assert(
        count($byAction['Cashier Shift unlocked'] ?? []) === 2,
        'each successful unlock is a Protected Audit Record, and refusals write none'
    );
    $lockAudit = $byAction['Cashier Shift locked'][0];
    $assert((int)$lockAudit['user_id'] === $casey, 'the lock record names the acting Cashier');
    $assert((int)$lockAudit['record_id'] === $shiftId, 'the lock record names the Cashier Shift');
    $assert($lockAudit['category'] === 'store_operation', 'locking is a Store operation record');
    $lockPayload = json_decode((string)$lockAudit['new_value'], true);
    $assert((int)($lockPayload['shift_id'] ?? 0) === $shiftId, 'the lock record names the Cashier Shift');
    $assert((int)($lockPayload['register_id'] ?? 0) === 10, 'the lock record names the Register');
    $assert(
        ($lockPayload['register_name'] ?? null) === 'Front Counter',
        'the lock record names the Register as the operator knows it'
    );
    $unlockAudit = $byAction['Cashier Shift unlocked'][0];
    $assert((int)$unlockAudit['user_id'] === $casey, 'the unlock record names the acting Cashier');
    $unlockPayload = json_decode((string)$unlockAudit['new_value'], true);
    $assert(
        ($unlockPayload['locked_at'] ?? null) !== null,
        'the unlock record retains when the Register was locked'
    );

    // A refused unlock is a security event, so it is recorded as one. Four
    // attempts reach the password check — blank, wrong, another Cashier's
    // password, and wrong again after signing back in — and the two refusals
    // from a Cashier who owns no open shift are never asked for a password at
    // all, so they write nothing.
    $assert(
        (int)$pdo->query(
            "SELECT COUNT(*) FROM activity_log WHERE action = 'Register unlock refused' AND category = 'security'"
        )->fetchColumn() === 4,
        'every refused password is recorded as a Protected Audit Record'
    );
    // Locking and unlocking are events, not sales, so they are never mirrored
    // into the sales audit module.
    $assert(
        (int)$pdo->query("SELECT COUNT(*) FROM activity_log WHERE module = 'Sales'")->fetchColumn() === 0,
        'register lock events are not mirrored into the sales audit module'
    );

    // --- The lock is wired into every point-of-sale surface -----------------
    // The services above are proven behaviourally. What is left is the wiring:
    // which page and endpoint call them. There is no HTTP harness in this repo,
    // so these are read from source, and they are kept to the wiring claim alone
    // rather than restating rules already proven above.
    $pos = (string)@file_get_contents($root . '/src/frontend/components/cashier/pos.php');
    $held = (string)@file_get_contents($root . '/src/frontend/components/barcodeScanner/apiScanner/held_sales.php');
    $assert($pos !== '' && $held !== '', 'the point-of-sale sources must be readable');
    $assert(
        preg_match('/if \(\$posRegisterLocked\): \?>/', $pos) === 1
        && preg_match('/\$posRegisterLocked\s*=/', $pos) === 1,
        'the point of sale branches on the lock rather than throwing: a locked Register is a screen the Cashier unlocks from, not an error page'
    );
    $assert(
        str_contains($pos, 'lockRegister(') && str_contains($pos, 'unlockRegister('),
        'the point of sale offers both locking and unlocking'
    );
    $assert(
        str_contains($pos, '$lock_error') && substr_count($pos, '$lock_error') > 2,
        'a refused lock is reported on the page the Cashier is left on, not only on the lock screen'
    );
    $assert(
        str_contains($pos, 'isRegisterLocked('),
        'the point of sale asks the service whether the Register is locked'
    );
    $assert(
        preg_match('/requireUnlockedRegister\(\$userId\)/', $held) === 1,
        'held sales are refused while the Register is locked'
    );
    // The claim is about what the lock covers, not about how the endpoint is
    // spelled: the refusal has to be settled before the first listing call, or a
    // locked till is handed the carts it can resume.
    $assert(
        strpos($held, "requireUnlockedRegister(\$userId)") < strpos($held, "openForCashier(\$userId)"),
        'the held-sales lock covers listing as well as holding, so a locked till is not handed the carts it can resume'
    );
} catch (Throwable $exception) {
    $failures[] = 'Cashier Shift register lock contract threw: ' . $exception->getMessage();
}

if ($failures) {
    fwrite(STDERR, "Cashier Shift register lock contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Cashier Shift register lock contract: passed\n";
