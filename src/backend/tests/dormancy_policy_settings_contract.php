<?php
// Dormancy Policy Platform Settings contract (ticket #60).
//
// Proves at the public service seam: defaults 45/30 resolve with integer
// cast-at-read; Super Administrator-only updates; non-numeric and
// warn>=disable rejections carry plain operator messages; accepted values
// persist in the platform key-value store; and the Platform Settings UI
// plus migration/schema seed the same keys.
require_once __DIR__ . '/../bootstrap/app.php';

use App\Authorization\DormancyPolicyService;
use App\Support\OperatorAlert;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$throws = static function (callable $operation, string $exceptionClass, string $message) use ($assert): void {
    try {
        $operation();
        $assert(false, $message);
    } catch (Throwable $exception) {
        $assert($exception instanceof $exceptionClass, $message . ' (received ' . $exception::class . ')');
    }
};

try {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE platform_settings (
        setting_key TEXT PRIMARY KEY,
        setting_value TEXT NOT NULL,
        updated_by INTEGER NULL,
        updated_at TEXT NULL
    )');

    $service = new DormancyPolicyService($pdo);

    // --- Defaults with integer cast-at-read ---
    $thresholds = $service->thresholds();
    $assert($thresholds['disable_days'] === 45, 'Default disable-days should be 45, got ' . var_export($thresholds['disable_days'], true));
    $assert($thresholds['warn_days'] === 30, 'Default warn-days should be 30, got ' . var_export($thresholds['warn_days'], true));
    $assert(is_int($thresholds['disable_days']), 'disable_days must be cast to int at read');
    $assert(is_int($thresholds['warn_days']), 'warn_days must be cast to int at read');

    // --- Non–Super Administrator roles cannot change the thresholds ---
    foreach (['admin', 'inventory_manager', 'cashier', null] as $role) {
        $throws(
            static fn() => $service->update((string)$role, ['disable_days' => 45, 'warn_days' => 30], 7),
            DomainException::class,
            'Role ' . var_export($role, true) . ' must not change Dormancy Policy thresholds'
        );
    }

    // --- Non-numeric values are rejected with a plain operator message ---
    $nonNumericCases = [
        ['disable_days' => 'abc', 'warn_days' => 30],
        ['disable_days' => 45, 'warn_days' => 'abc'],
        ['disable_days' => '', 'warn_days' => 30],
        ['disable_days' => 45, 'warn_days' => ''],
        ['disable_days' => null, 'warn_days' => 30],
        ['disable_days' => 45, 'warn_days' => null],
        ['warn_days' => 30],
        ['disable_days' => 45],
    ];
    foreach ($nonNumericCases as $index => $payload) {
        $caught = null;
        try {
            $service->update('super_admin', $payload, 1);
        } catch (Throwable $exception) {
            $caught = $exception;
        }
        $assert($caught instanceof InvalidArgumentException, "Non-numeric case #{$index} must be rejected");
        if ($caught !== null) {
            $assert(
                !OperatorAlert::isTechnicalMessage($caught->getMessage()),
                'Non-numeric rejection must be a plain operator message, got: ' . $caught->getMessage()
            );
        }
    }

    // --- Warn-days greater than or equal to disable-days is rejected ---
    foreach ([[30, 30], [20, 30], [0, 0], [44, 45]] as [$disable, $warn]) {
        $caught = null;
        try {
            $service->update('super_admin', ['disable_days' => $disable, 'warn_days' => $warn], 1);
        } catch (Throwable $exception) {
            $caught = $exception;
        }
        $assert($caught instanceof InvalidArgumentException, "Warn {$warn} vs disable {$disable} must be rejected");
        if ($caught !== null) {
            $assert(
                !OperatorAlert::isTechnicalMessage($caught->getMessage()),
                'Ordering rejection must be a plain operator message, got: ' . $caught->getMessage()
            );
        }
    }

    // --- Validation acceptance: valid values persist ---
    $service->update('super_admin', ['disable_days' => 60, 'warn_days' => 14], 1);
    $saved = $service->thresholds();
    $assert($saved['disable_days'] === 60, 'Accepted disable-days should persist as 60');
    $assert($saved['warn_days'] === 14, 'Accepted warn-days should persist as 14');
    $assert(is_int($saved['disable_days']) && is_int($saved['warn_days']), 'Saved thresholds must still cast to int at read');

    $stored = $pdo->query('SELECT setting_key, setting_value FROM platform_settings ORDER BY setting_key')->fetchAll(PDO::FETCH_KEY_PAIR);
    $assert(
        ($stored['dormancy_disable_days'] ?? null) === '60',
        'disable-days should persist in the platform key-value store'
    );
    $assert(
        ($stored['dormancy_warn_days'] ?? null) === '14',
        'warn-days should persist in the platform key-value store'
    );
    $updatedBy = $pdo->query("SELECT updated_by FROM platform_settings WHERE setting_key = 'dormancy_disable_days'")->fetchColumn();
    $assert((int)$updatedBy === 1, 'Persisted thresholds should record the saving actor');

    // A later valid save replaces the previous values.
    $service->update('super_admin', ['disable_days' => 45, 'warn_days' => 30], 1);
    $replaced = $service->thresholds();
    $assert($replaced['disable_days'] === 45 && $replaced['warn_days'] === 30, 'A later valid save should replace prior thresholds');

    // --- Cast-at-read tolerates a non-numeric stored value by falling back ---
    $pdo->exec("UPDATE platform_settings SET setting_value = 'not-a-number' WHERE setting_key = 'dormancy_disable_days'");
    $fallback = $service->thresholds();
    $assert($fallback['disable_days'] === 45, 'A non-numeric stored value should fall back to the default');
    $pdo->exec("DELETE FROM platform_settings WHERE setting_key = 'dormancy_disable_days'");
    $missing = $service->thresholds();
    $assert($missing['disable_days'] === 45, 'A missing stored value should fall back to the default');

    // --- Role authority at the capability layer (ADR 0001) ---
    $policy = new App\Authorization\RoleCapabilityPolicy();
    $assert(
        $policy->allows('super_admin', App\Authorization\RoleCapabilityPolicy::PLATFORM_GOVERNANCE),
        'Super Administrator should hold Platform Settings authority'
    );
    foreach (['admin', 'inventory_manager', 'cashier'] as $role) {
        $assert(
            !$policy->allows($role, App\Authorization\RoleCapabilityPolicy::PLATFORM_GOVERNANCE),
            ucfirst($role) . ' must not hold Platform Settings authority'
        );
    }

    // --- UI, migration, and schema wiring ---
    $root = dirname(__DIR__, 3);
    $read = static fn(string $relative): string => (string)@file_get_contents($root . '/' . $relative);

    $ui = $read('src/frontend/components/system_administrator/system_settings.php');
    foreach ([
        'RoleCapabilityPolicy::PLATFORM_GOVERNANCE',
        "action === 'save_dormancy'",
        'DormancyPolicyService',
        'name="disable_days"',
        'name="warn_days"',
        'Dormancy Policy',
        'OperatorAlert::message',
        'log_activity',
    ] as $needle) {
        $assert(str_contains($ui, $needle), "Platform Settings UI must contain {$needle}");
    }

    $migration = $read('src/backend/database/migrations/202609240002_dormancy_policy_settings.php');
    foreach (['DormancyPolicyService::DISABLE_DAYS_KEY', 'DormancyPolicyService::WARN_DAYS_KEY', 'DormancyPolicyService::DEFAULT_DISABLE_DAYS', 'DormancyPolicyService::DEFAULT_WARN_DAYS'] as $needle) {
        $assert(str_contains($migration, $needle), "Dormancy migration must seed via {$needle}");
    }
    $serviceSource = $read('src/backend/app/Authorization/DormancyPolicyService.php');
    $assert(str_contains($serviceSource, "public const DISABLE_DAYS_KEY = 'dormancy_disable_days'"), 'Service must define the disable-days platform key');
    $assert(str_contains($serviceSource, "public const WARN_DAYS_KEY = 'dormancy_warn_days'"), 'Service must define the warn-days platform key');
    $assert(str_contains($serviceSource, 'public const DEFAULT_DISABLE_DAYS = 45'), 'Service default disable-days must be 45');
    $assert(str_contains($serviceSource, 'public const DEFAULT_WARN_DAYS = 30'), 'Service default warn-days must be 30');

    $schema = $read('src/backend/sql/schema.sql');
    foreach (['dormancy_disable_days', 'dormancy_warn_days'] as $needle) {
        $assert(str_contains($schema, $needle), "Fresh schema must seed {$needle}");
    }

    $runAll = $read('src/backend/tests/run_all.sh');
    $assert(
        str_contains($runAll, 'dormancy_policy_settings_contract.php'),
        'run_all.sh must run the Dormancy Policy settings contract'
    );
} catch (Throwable $exception) {
    $failures[] = 'Dormancy Policy settings contract threw: ' . $exception->getMessage();
}

if ($failures) {
    fwrite(STDERR, "Dormancy Policy settings contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Dormancy Policy settings contract: passed\n";
