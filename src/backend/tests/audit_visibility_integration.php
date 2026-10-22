<?php
// Requires a disposable MySQL database loaded with src/backend/database/sql/schema.sql and all migrations applied.
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
        $page = $service->records('super_admin', $filters + ['limit' => 2, 'offset' => 2]);
        $assert(array_column($page, 'log_id') === array_column(array_slice($superRecords, 2, 2), 'log_id'), 'Pagination must retrieve the requested stable slice');
        $assert($service->records('super_admin', $filters + ['limit' => 2, 'offset' => 5]) === [], 'Pagination past the end must be empty');
        $crossColumn = $filters + ['search' => $marker . ' "System / Unknown"'];
        $assert($service->count('super_admin', $crossColumn) === 5, 'Multi-word search must match words across action and displayed user columns');
        $assert(count($service->exportRows('super_admin', $crossColumn)) === 5, 'Export and table search must have identical scope');
        $assert($service->count('super_admin', $filters + ['search' => '%']) === 0, 'Search percent must be literal rather than an SQL wildcard');
        $assert($service->count('super_admin', $filters + ['search' => '!']) === 0, 'LIKE escape characters must remain literal');
        $columnFilters = $filters + ['columns' => [3 => ['columnControl' => ['search' => ['logic' => 'equal', 'value' => 'security']]]]];
        $assert($service->count('super_admin', $columnFilters) === 1, 'ColumnControl equals must filter all permitted history');
        $assert(count($service->exportRows('super_admin', $columnFilters)) === 1, 'ColumnControl filters must also constrain exports');
        $assert($service->count('admin', $columnFilters) === 0, 'ColumnControl must not bypass role scope');
        $emptyModule = ['columns' => [4 => ['columnControl' => ['search' => ['logic' => 'empty', 'value' => '']]]]];
        $assert($service->count('super_admin', $filters + $emptyModule) === 0, 'ColumnControl empty must evaluate without an input value');
        $sorted = $service->records('super_admin', $filters + ['order' => [['column' => 2, 'dir' => 'asc']]]);
        $expectedActions = array_column($superRecords, 'action');
        sort($expectedActions);
        $assert(array_column($sorted, 'action') === $expectedActions, 'Column sorting must use the requested allowed column');
        $assert(count($service->records('super_admin', $filters + ['order' => [['column' => '0; DROP TABLE activity_log', 'dir' => 'asc']]])) === 5, 'Untrusted sort columns must never become SQL');
        $today = (string)$pdo->query('SELECT CURRENT_DATE()')->fetchColumn();
        $assert($service->count('super_admin', $filters + ['columns' => [0 => ['columnControl' => ['search' => ['logic' => 'equal', 'value' => $today]]]]]) === 5, 'Date equality must include all times on the selected day');
        $invalidDateRejected = false;
        try {
            $service->count('super_admin', $filters + ['columns' => [0 => ['columnControl' => ['search' => ['logic' => 'equal', 'value' => '2026-02-30']]]]]);
        } catch (InvalidArgumentException $exception) {
            $invalidDateRejected = true;
        }
        $assert($invalidDateRejected, 'Impossible dates must be rejected');
        $pdo->prepare('UPDATE activity_log SET record_id = 2 WHERE log_id = ?')->execute([$superRecords[0]['log_id']]);
        $pdo->prepare('UPDATE activity_log SET record_id = 10 WHERE log_id = ?')->execute([$superRecords[1]['log_id']]);
        $numericFilter = $filters + ['columns' => [5 => ['columnControl' => ['search' => ['logic' => 'greater', 'value' => '2']]]]];
        $assert($service->count('super_admin', $numericFilter) === 1, 'Record number comparisons must be numeric, so 10 is greater than 2');
        $assert(count($service->exportRows('super_admin', $numericFilter)) === 1, 'Numeric column filters must apply to CSV exports');

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
        $securityLogIds = array_values(array_column(
            array_filter($superRecords, static fn(array $r): bool => $r['category'] === AuditRecordCategory::SECURITY),
            'log_id'
        ));
        $storeLogIds = array_values(array_column($adminRecords, 'log_id'));
        $assert(
            count($service->exportRows('super_admin', $filters + ['log_ids' => $securityLogIds])) === 1,
            'Super Administrator export with matched log_ids should return the exact matched rows'
        );
        $assert(
            $service->exportRows('admin', $filters + ['log_ids' => $securityLogIds]) === [],
            'Administrator must not export a protected record by supplying its log_id'
        );
        $assert(
            count($service->exportRows('admin', $filters + ['log_ids' => $storeLogIds])) === 1,
            'Administrator export with permitted log_ids should return the matched Store-operational row'
        );
        $assert(
            count($service->exportRows('admin', $filters + ['search' => 'Store Operation'])) === 1,
            'Search filter should match human-readable category labels displayed in the table'
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
