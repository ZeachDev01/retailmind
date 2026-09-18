<?php
// Requires a disposable MySQL database loaded with src/backend/sql/schema.sql and all migrations applied.
if (getenv('RUN_DB_TESTS') !== '1') {
    echo "Audit visibility integration tests: skipped (set RUN_DB_TESTS=1)\n";
    exit(0);
}

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../app/Services/DashboardService.php';

use App\Audit\ProtectedAuditRecordService;
use App\Audit\AuditRecordCategory;
use App\Authorization\RoleCapabilityPolicy;
use App\Database\Schema;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$assert(Schema::columnExists($pdo, 'activity_log', 'category'), 'activity_log.category is missing');
$columnStatement = $pdo->prepare(
    "SELECT IS_NULLABLE, COLUMN_TYPE FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'activity_log' AND column_name = 'category'"
);
$columnStatement->execute();
$categoryColumn = $columnStatement->fetch(PDO::FETCH_ASSOC) ?: [];
$assert(($categoryColumn['IS_NULLABLE'] ?? '') === 'NO', 'activity_log.category must be required');
foreach (AuditRecordCategory::ALL as $category) {
    $assert(
        str_contains((string)($categoryColumn['COLUMN_TYPE'] ?? ''), "'{$category}'"),
        "activity_log.category must accept {$category}"
    );
}
$assert(
    AuditRecordCategory::classify('Authentication', 'Login success') === AuditRecordCategory::SECURITY,
    'Authentication activity should be categorized as security'
);
$assert(
    AuditRecordCategory::classify('Backup & Restore', 'Database restore') === AuditRecordCategory::RECOVERY,
    'Restore activity should be categorized as recovery'
);
$assert(
    AuditRecordCategory::classify('Demand Forecasting', 'ML settings update') === AuditRecordCategory::PLATFORM_SETTING,
    'Model-setting activity should be categorized as a Platform Setting'
);
$assert(
    AuditRecordCategory::classify('Store Settings', 'Store settings update') === AuditRecordCategory::STORE_OPERATION,
    'Store settings activity should be categorized as a Store operation'
);
$assert(
    AuditRecordCategory::classify('User Access', 'User created', ['role' => 'cashier']) === AuditRecordCategory::STORE_OPERATION,
    'delegated staff lifecycle activity should be categorized as a Store operation'
);
$assert(
    AuditRecordCategory::classify('User Access', 'User created', ['role' => 'admin']) === AuditRecordCategory::SECURITY,
    'privileged-account lifecycle activity should be categorized as security'
);
$assert(
    AuditRecordCategory::classify(
        'User Access',
        'User updated',
        ['role' => 'cashier'],
        ['role' => 'admin']
    ) === AuditRecordCategory::SECURITY,
    'privileged-account demotion should remain categorized as security'
);
$assert(
    AuditRecordCategory::classify('Recovery Account', 'Activated') === AuditRecordCategory::RECOVERY_ACCOUNT,
    'Recovery Account activity should have its own category'
);
$assert(
    AuditRecordCategory::classify('Inventory', 'Stock received') === AuditRecordCategory::STORE_OPERATION,
    'Routine inventory activity should be categorized as a Store operation'
);

if (!$failures) {
    try {
        $pdo->beginTransaction();
        $insert = $pdo->prepare(
            'INSERT INTO activity_log (user_id, action, module, category) VALUES (NULL, ?, ?, ?)'
        );
        $marker = 'audit_visibility_' . bin2hex(random_bytes(6));
        $categories = [
            AuditRecordCategory::STORE_OPERATION,
            AuditRecordCategory::SECURITY,
            AuditRecordCategory::RECOVERY,
            AuditRecordCategory::PLATFORM_SETTING,
            AuditRecordCategory::RECOVERY_ACCOUNT,
        ];
        foreach ($categories as $category) {
            $insert->execute([$marker . '_' . $category, $marker, $category]);
        }

        $service = new ProtectedAuditRecordService($pdo, new RoleCapabilityPolicy());
        $filters = ['module' => $marker];

        $assert($service->count('super_admin', $filters) === 5, 'Super Administrator should count every audit category');
        $superRecords = $service->records('super_admin', $filters);
        $actualCategories = array_column($superRecords, 'category');
        sort($actualCategories);
        $expectedCategories = $categories;
        sort($expectedCategories);
        $assert(
            $actualCategories === $expectedCategories,
            'Super Administrator should receive every audit category'
        );
        $assert(count($service->exportRows('super_admin', $filters)) === 5, 'Super Administrator export should contain every permitted record');

        $assert($service->count('admin', $filters) === 1, 'Administrator count should include only Store-operational records');
        $adminRecords = $service->records('admin', $filters);
        $assert(
            array_column($adminRecords, 'category') === [AuditRecordCategory::STORE_OPERATION],
            'Administrator results should exclude protected platform categories'
        );
        $assert(count($service->exportRows('admin', $filters)) === 1, 'Administrator export should include only Store-operational records');

        $dashboard = new DashboardService($pdo);
        $assert(
            $dashboard->getAdminMetrics('admin')['audit_events'] === $service->count('admin'),
            'Administrator dashboard count should exclude protected platform categories'
        );
        $assert(
            $dashboard->getAdminMetrics('super_admin')['audit_events'] === $service->count('super_admin'),
            'Super Administrator dashboard count should include every permitted category'
        );
        $assert(
            $service->records('admin', $filters + ['category' => AuditRecordCategory::SECURITY]) === [],
            'Administrator must not retrieve a security record by filtering directly for its category'
        );

        $denied = false;
        try {
            $service->records('cashier', $filters);
        } catch (DomainException $exception) {
            $denied = true;
        }
        $assert($denied, 'A role without an audit capability should be denied direct access');

        $routeSource = file_get_contents(__DIR__ . '/../../frontend/components/system_administrator/audit_logs.php');
        $legacyRouteSource = file_get_contents(__DIR__ . '/../legacy/routes/admin/audit_log.php');
        foreach ([$routeSource, $legacyRouteSource] as $source) {
            $assert(
                str_contains((string)$source, 'require_any_capability')
                    && str_contains((string)$source, 'VIEW_STORE_AUDIT')
                    && str_contains((string)$source, 'VIEW_PLATFORM_AUDIT'),
                'Every directly accessible Protected Audit Record route should enforce audit capabilities'
            );
        }

        $publicMethods = array_map(
            static fn(ReflectionMethod $method): string => strtolower($method->getName()),
            (new ReflectionClass(ProtectedAuditRecordService::class))->getMethods(ReflectionMethod::IS_PUBLIC)
        );
        foreach (['create', 'insert', 'update', 'edit', 'delete', 'remove'] as $mutation) {
            $assert(!in_array($mutation, $publicMethods, true), "Audit service must not expose a {$mutation} workflow");
        }

        $pdo->rollBack();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $failures[] = 'Audit visibility database test failed: ' . $exception->getMessage();
    }
}

if ($failures) {
    fwrite(STDERR, "Audit visibility integration tests failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Audit visibility integration tests: passed\n";
