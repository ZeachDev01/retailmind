<?php
// Register administration contract (ticket #87).
//
// Exercises the complete Administrator Register lifecycle at the service seam:
// create, rename, disable, re-enable, and the reference-guarded delete. Each
// mutation must write a Protected Audit Record with the actor and the relevant
// before/after data, and no role outside the Administrator workspace may
// administer Registers.
require_once __DIR__ . '/../bootstrap/app.php';

use App\Authorization\RoleCapabilityPolicy;
use App\Services\RegisterService;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$expectDenied = static function (callable $operation, string $message) use (&$failures): void {
    try {
        $operation();
        $failures[] = $message;
    } catch (DomainException | InvalidArgumentException $exception) {
        // Expected authorization (DomainException) or validation rejection
        // (InvalidArgumentException), matching UserLifecycleService.
    }
};

try {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE registers (
        register_id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'active',
        paper_width_mm TEXT NOT NULL DEFAULT '80',
        disabled_at TEXT NULL,
        created_by INTEGER NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec('CREATE UNIQUE INDEX idx_registers_name ON registers (name)');
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
    $pdo->exec("CREATE TABLE cashier_shifts (
        shift_id INTEGER PRIMARY KEY AUTOINCREMENT,
        register_id INTEGER NULL
    )");

    $policy = new RoleCapabilityPolicy();
    $service = new RegisterService($pdo, $policy);
    $adminId = 7;

    // --- Create with a unique stable identity and display name (AC 1) ---
    $frontId = $service->create($adminId, 'admin', 'Front Counter');
    $backId = $service->create($adminId, 'admin', 'Back Counter');
    $assert($frontId > 0 && $backId > 0 && $frontId !== $backId, 'each Register gets its own stable identity');
    $assert($service->get($frontId)['name'] === 'Front Counter', 'a new Register keeps its display name');
    $assert($service->get($frontId)['status'] === 'active', 'a new Register is available for new shifts');

    $expectDenied(
        fn() => $service->create($adminId, 'admin', '  '),
        'a Register name is required'
    );
    $expectDenied(
        fn() => $service->create($adminId, 'admin', 'Front Counter'),
        'display names stay unique among Registers'
    );
    $expectDenied(
        fn() => $service->rename($adminId, 'admin', $frontId, 'Back Counter'),
        'a rename cannot collide with another Register name'
    );

    $assert($service->get($frontId)['paper_width_mm'] === 80, 'new Registers default to 80 mm');
    $service->setPaperWidth($adminId, 'admin', $frontId, '58');
    $assert($service->get($frontId)['paper_width_mm'] === 58, 'Administrator can set 58 mm');
    $assert($service->all()[0]['paper_width_mm'] === 80 || $service->all()[0]['paper_width_mm'] === 58,
        'Register listing includes paper width');
    foreach (['57', '80.0', '', '58oops', 58.5, 80.9, null, true] as $invalid) {
        $expectDenied(fn() => $service->setPaperWidth($adminId, 'admin', $frontId, $invalid), 'unsupported width is rejected');
        $expectDenied(fn() => $service->create($adminId, 'admin', 'Invalid width', $invalid), 'creation rejects unsupported width');
    }
    foreach (['cashier', 'inventory_manager', 'super_admin'] as $role) {
        $expectDenied(fn() => $service->setPaperWidth($adminId, $role, $frontId, '80'), 'only Administrator can change paper width');
    }
    $narrowId = $service->create($adminId, 'admin', 'Narrow Till', '58');
    $assert($service->get($narrowId)['paper_width_mm'] === 58, 'creation accepts 58 mm');
    $service->delete($adminId, 'admin', $narrowId);

    // --- Rename keeps the stable identity that earlier records hold (AC 2) ---
    $pdo->prepare('INSERT INTO cashier_shifts (register_id) VALUES (?)')->execute([$frontId]);
    $service->rename($adminId, 'admin', $frontId, 'Front Counter A');
    $assert($service->get($frontId)['name'] === 'Front Counter A', 'a rename changes the display name');
    $shiftRegister = $pdo->query('SELECT register_id FROM cashier_shifts WHERE shift_id = 1')->fetchColumn();
    $assert((int)$shiftRegister === $frontId, 'an earlier operational record keeps pointing at the same identity after a rename');

    // --- Disable removes availability for new shifts but keeps history (AC 3) ---
    $service->setStatus($adminId, 'admin', $frontId, 'disabled');
    $assert($service->get($frontId)['status'] === 'disabled', 'a disabled Register is unavailable to new shifts');
    $assert($service->get($frontId)['disabled_at'] !== null, 'a disabled Register is stamped');
    $assert(
        !in_array($frontId, array_map('intval', array_column($service->available(), 'register_id')), true),
        'a disabled Register is excluded from new-shift availability'
    );
    $assert(
        in_array($frontId, array_map('intval', array_column($service->all(), 'register_id')), true),
        'a disabled Register remains visible in history'
    );
    $assert(
        (int)$pdo->query('SELECT register_id FROM cashier_shifts WHERE shift_id = 1')->fetchColumn() === $frontId,
        'disabling a Register leaves its earlier references intact'
    );

    // A disabled Register can be brought back for new shifts.
    $service->setStatus($adminId, 'admin', $frontId, 'active');
    $assert($service->get($frontId)['status'] === 'active', 'a Register can be re-enabled');
    $assert($service->get($frontId)['disabled_at'] === null, 're-enabling clears the disabled stamp');

    // --- A referenced Register cannot be deleted (AC 4) ---
    $expectDenied(
        fn() => $service->delete($adminId, 'admin', $frontId),
        'a Register held by an earlier operational record cannot be deleted'
    );
    $assert($service->get($frontId)['name'] === 'Front Counter A', 'a refused delete leaves the Register untouched');

    $service->delete($adminId, 'admin', $backId);
    $remaining = array_map('intval', array_column($service->all(), 'register_id'));
    $assert(!in_array($backId, $remaining, true), 'an unreferenced Register can be deleted');

    // --- Administrator-only authority (ADR-0001 Store operations) ---
    $assert(
        $policy->allows('admin', RoleCapabilityPolicy::MANAGE_REGISTERS),
        'the Administrator workspace administers Registers'
    );
    $assert(
        !$policy->allows('super_admin', RoleCapabilityPolicy::MANAGE_REGISTERS),
        'Register administration is a Store operation, never platform governance (ADR-0001)'
    );
    foreach (['cashier', 'inventory_manager', 'super_admin'] as $role) {
        $expectDenied(
            fn() => $service->create($adminId, $role, 'Unauthorized Till'),
            "{$role} must not create Registers"
        );
    }
    $expectDenied(
        fn() => $service->rename($adminId, 'cashier', $frontId, 'Renamed By Cashier'),
        'a Cashier must not rename Registers'
    );
    $expectDenied(
        fn() => $service->setStatus($adminId, 'inventory_manager', $frontId, 'disabled'),
        'an Inventory Manager must not disable Registers'
    );
    $expectDenied(
        fn() => $service->delete($adminId, 'super_admin', $frontId),
        'the Super Administrator does not perform routine Register operations'
    );

    // --- Protected Audit Records with actor and before/after data (AC 5) ---
    $rows = $pdo->query(
        "SELECT action, category, module, user_id, record_id, previous_value, new_value
         FROM activity_log WHERE module = 'Registers' ORDER BY log_id"
    )->fetchAll(PDO::FETCH_ASSOC);
    $actions = array_column($rows, 'action');
    foreach (['Register created', 'Register renamed', 'Register disabled', 'Register enabled', 'Register deleted'] as $expected) {
        $assert(in_array($expected, $actions, true), "Protected Audit Records must include {$expected}");
    }
    foreach ($rows as $row) {
        $assert($row['category'] === 'store_operation', 'Register administration is a Store operation record');
        $assert((int)$row['user_id'] === $adminId, 'Protected Audit Records attribute the acting Administrator');
    }
    $widthChange = array_values(array_filter($rows, static fn(array $row): bool => $row['action'] === 'Register paper width changed'))[0] ?? [];
    $assert((json_decode($widthChange['previous_value'] ?? '{}', true)['paper_width_mm'] ?? null) === 80,
        'paper width audit preserves the previous width');
    $assert((json_decode($widthChange['new_value'] ?? '{}', true)['paper_width_mm'] ?? null) === 58,
        'paper width audit preserves the new width');
    $created = array_values(array_filter($rows, static fn(array $row): bool => $row['action'] === 'Register created'))[0] ?? [];
    $assert(
        str_contains((string)($created['new_value'] ?? ''), '"actor_role":"admin"'),
        'Protected Audit Records retain the acting workspace'
    );
    $renamed = array_values(array_filter($rows, static fn(array $row): bool => $row['action'] === 'Register renamed'))[0] ?? [];
    $before = json_decode((string)($renamed['previous_value'] ?? ''), true);
    $after = json_decode((string)($renamed['new_value'] ?? ''), true);
    $assert(($before['name'] ?? null) === 'Front Counter', 'a rename records the previous display name');
    $assert(($after['name'] ?? null) === 'Front Counter A', 'a rename records the new display name');
    $assert(
        ($before['register_id'] ?? null) === ($after['register_id'] ?? null),
        'a rename records that the stable identity is unchanged'
    );
    $assert((int)($renamed['record_id'] ?? 0) === $frontId, 'Protected Audit Records name the affected Register');
} catch (Throwable $exception) {
    $failures[] = 'Register administration contract threw: ' . $exception->getMessage();
}

if ($failures) {
    fwrite(STDERR, "Register administration contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Register administration contract: passed\n";
