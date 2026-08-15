<?php
// Lightweight release checks that do not require a live database.
$root = dirname(__DIR__);
$checks = [
    'Inactive products blocked at checkout' => [
        'file' => 'app/Services/SalesWorkflowService.php',
        'needles' => ["p.status = 'active'", "AND p.status = 'active' FOR UPDATE"],
    ],
    'Cashier shift required' => [
        'file' => 'app/Services/SalesWorkflowService.php',
        'needles' => ['resolveOpenShift', 'Open a cashier shift before processing sales'],
    ],
    'Discount authorization recorded' => [
        'file' => 'app/Services/SalesWorkflowService.php',
        'needles' => ['discount_authorized_by', 'authorizeSupervisor'],
    ],
    'Replenishment segregation of duties' => [
        'file' => 'modules/inventory_management/replenishment_requests.php',
        'needles' => ["requested_by <> ?", "status = 'pending'"],
    ],
    'Accepted stock drives request completion' => [
        'file' => 'app/Services/ReceivingService.php',
        'needles' => ['SUM(accepted_qty)', 'LEAST(ordered_qty, received_qty + ?)'],
    ],
    'Server-held sales enabled' => [
        'file' => 'barcodeScanner/apiScanner/held_sales.php',
        'needles' => ['INSERT INTO held_sales', "status='held'"],
    ],
    'Server product search enabled' => [
        'file' => 'barcodeScanner/apiScanner/products.php',
        'needles' => ["p.status='active'", 'case_barcode'],
    ],
    'Random Forest baseline comparison' => [
        'file' => 'legacy/demandForcasting/train_model.py',
        'needles' => ['baseline_predictions', 'model_beats_baseline'],
    ],
    'Versioned migration runner present' => [
        'file' => 'scripts/migrate.php',
        'needles' => ['MigrationRunner', 'runPending', 'database/migrations'],
    ],
    'Runtime authentication does not migrate schema' => [
        'file' => 'includes/auth.php',
        'needles' => ['must_change_password', 'change_password.php'],
        'forbidden' => ['ALTER TABLE', 'CREATE TABLE', 'ensure_operational_updates_schema'],
    ],
    'Mandatory password change page present' => [
        'file' => 'change_password.php',
        'needles' => ['current_password', 'must_change_password = 0', 'password_policy_error'],
    ],
    'System health dashboard present' => [
        'file' => 'modules/system_administrator/system_health.php',
        'needles' => ['SystemHealthService', 'Environment checks', 'Recommended maintenance commands'],
    ],
    'Supplier-based reorder planning present' => [
        'file' => 'modules/inventory_management/reorder_planner.php',
        'needles' => ['Supplier-Based Reorder Planning', 'minimum_order_quantity', 'Create selected replenishment requests'],
    ],
    'Release cleanup covers runtime files' => [
        'file' => 'scripts/build_release.sh',
        'needles' => ['storage/sessions/*', 'storage/imports/*', 'legacy/demandForcasting/models/*'],
    ],
];
$failures = [];
foreach ($checks as $label => $check) {
    $path = $root . '/' . $check['file'];
    $text = is_readable($path) ? file_get_contents($path) : '';
    foreach ($check['needles'] as $needle) {
        if ($text === false || strpos($text, $needle) === false) {
            $failures[] = "$label: missing {$needle}";
        }
    }
    foreach ($check['forbidden'] ?? [] as $needle) {
        if ($text !== false && strpos($text, $needle) !== false) {
            $failures[] = "$label: forbidden {$needle}";
        }
    }
}
if ($failures) {
    fwrite(STDERR, "Smoke checks failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo 'Smoke checks passed: ' . count($checks) . "\n";
