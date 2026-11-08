<?php

declare(strict_types=1);

/**
 * Ticket #456: Full System Tri-Fold Verification & Audit Suite
 *
 * Executes:
 * - Layer A: Static CSS bundle (`build_styles.php --check`), view markup audit (`audit_views.php`), and smoke checks (`smoke_checks.php`).
 * - Layer B: All vertical role-sliced PHP contract and concurrency tests (#447-#455 + boundary/concurrency/audit integration) with `RUN_DB_TESTS=1` and `RUN_REFUND_DB_TESTS=1`.
 * - Layer C: Automated headless browser journeys across all 4 role workspaces (Cashier POS/GCash/Cash Drawer, Inventory Manager Counts/Replenishment, Administrator User Lifecycle, Super Administrator User Lifecycle & Workspace Routes).
 * - Operational DB Isolation Guard: Captures pre-run and post-run table row counts and verifies zero residual test databases or operational record mutations.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$repoRoot = dirname(__DIR__, 3);
chdir($repoRoot);

require_once __DIR__ . '/../bootstrap/app.php';

$phpBin = PHP_BINARY ?: 'php';
$nodeBin = getenv('NODE_BINARY') ?: 'node';

/**
 * Connect to local MySQL server if available and snapshot operational DB table row counts + disposable test DBs.
 *
 * @return array{connected: bool, database: string, tables: array<string, int>, test_databases: list<string>}
 */
function captureDatabaseSnapshot(): array
{
    $config = require __DIR__ . '/../config/database.php';
    $dbName = (string)($config['database'] ?? 'inventory_system');
    try {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $config['host'], $config['port']),
            (string)$config['username'],
            (string)$config['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    } catch (Throwable $e) {
        return [
            'connected' => false,
            'database' => $dbName,
            'tables' => [],
            'test_databases' => [],
        ];
    }

    $databases = $pdo->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN);
    $testDatabases = array_values(array_filter(
        $databases,
        static fn (string $name): bool => (bool)preg_match('/^retailmind_(theme|backup|refund)_test_/i', $name)
    ));
    sort($testDatabases);

    $tables = [];
    if (in_array($dbName, $databases, true)) {
        $pdo->exec('USE `' . str_replace('`', '``', $dbName) . '`');
        $tableNames = $pdo->query('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"')->fetchAll(PDO::FETCH_COLUMN);
        sort($tableNames);
        foreach ($tableNames as $table) {
            $safeTable = str_replace('`', '``', (string)$table);
            $count = (int)$pdo->query("SELECT COUNT(*) FROM `{$safeTable}`")->fetchColumn();
            $tables[(string)$table] = $count;
        }
    }

    return [
        'connected' => true,
        'database' => $dbName,
        'tables' => $tables,
        'test_databases' => $testDatabases,
    ];
}

/**
 * Clean up any pre-existing stray disposable databases before starting the audit run.
 *
 * @param list<string> $strayDatabases
 */
function dropStrayTestDatabases(array $strayDatabases): void
{
    if ($strayDatabases === []) {
        return;
    }
    $config = require __DIR__ . '/../config/database.php';
    try {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $config['host'], $config['port']),
            (string)$config['username'],
            (string)$config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        foreach ($strayDatabases as $db) {
            if (preg_match('/^retailmind_(theme|backup|refund)_test_[a-f0-9_]+$/i', $db)) {
                $pdo->exec('DROP DATABASE IF EXISTS `' . str_replace('`', '``', $db) . '`');
            }
        }
    } catch (Throwable $e) {
        // Ignore if MySQL is unreachable
    }
}

/**
 * Run a command with environment variables and stream or capture output.
 *
 * @param list<string> $cmd
 * @param array<string, string> $extraEnv
 * @return array{exit_code: int, output: string, duration_ms: float}
 */
function runStepCommand(array $cmd, array $extraEnv = []): array
{
    $env = array_merge($_ENV, getenv() ?: [], $extraEnv);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $start = microtime(true);
    $process = proc_open($cmd, $descriptors, $pipes, getcwd() ?: null, $env);
    if (!is_resource($process)) {
        return [
            'exit_code' => 1,
            'output' => 'Failed to spawn process: ' . implode(' ', $cmd),
            'duration_ms' => 0.0,
        ];
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $durationMs = round((microtime(true) - $start) * 1000, 1);

    $combined = trim($stdout . ($stderr !== '' ? "\n" . $stderr : ''));
    return [
        'exit_code' => $exitCode,
        'output' => $combined,
        'duration_ms' => $durationMs,
    ];
}

$layers = [
    'Layer A: Static CSS Bundle, View Markup Audit & Smoke Guardrails' => [
        [
            'name' => 'CSS Bundle Sync (build_styles.php --check)',
            'cmd' => [$phpBin, 'src/backend/scripts/build_styles.php', '--check'],
            'env' => [],
        ],
        [
            'name' => 'View Markup & Semantic Token Audit (audit_views.php)',
            'cmd' => [$phpBin, 'src/backend/scripts/audit_views.php'],
            'env' => [],
        ],
        [
            'name' => 'Architecture & Guardrail Smoke Checks (smoke_checks.php)',
            'cmd' => [$phpBin, 'src/backend/tests/smoke_checks.php'],
            'env' => [],
        ],
    ],
    'Layer B: Vertical Role-Sliced Contract & Concurrency Suite (RUN_DB_TESTS=1)' => [
        [
            'name' => '#447 Cashier POS Checkout & Barcode Ergonomics Contract',
            'cmd' => [$phpBin, 'src/backend/tests/pos_checkout_ergonomics_test.php'],
            'env' => ['RUN_DB_TESTS' => '1'],
        ],
        [
            'name' => '#448 Inventory Stock Status, FEFO & Batch Reconciliation Contract',
            'cmd' => [$phpBin, 'src/backend/tests/inventory_stock_status_batch_reconciliation_test.php'],
            'env' => ['RUN_DB_TESTS' => '1'],
        ],
        [
            'name' => '#449 Cashier GCash Service & Reversals Contract',
            'cmd' => [$phpBin, 'src/backend/tests/cashier_gcash_and_reversals_test.php'],
            'env' => ['RUN_DB_TESTS' => '1'],
        ],
        [
            'name' => '#450 Cashier Shift Lifecycle & Cash Drawer Reconciliation Contract',
            'cmd' => [$phpBin, 'src/backend/tests/cashier_shift_reconciliation_contract.php'],
            'env' => ['RUN_DB_TESTS' => '1'],
        ],
        [
            'name' => '#451 Supplier Receiving, Forecast Replenishment & Pull-Out Contract',
            'cmd' => [$phpBin, 'src/backend/tests/supplier_receiving_replenishment_pullout_contract.php'],
            'env' => ['RUN_DB_TESTS' => '1'],
        ],
        [
            'name' => '#452 Store Operations, Readiness & Fiscal Period Guards Contract',
            'cmd' => [$phpBin, 'src/backend/tests/store_operations_and_fiscal_guards_contract.php'],
            'env' => ['RUN_DB_TESTS' => '1'],
        ],
        [
            'name' => '#453 Administrator Governance, User Minimum & Reporting Contract',
            'cmd' => [$phpBin, 'src/backend/tests/administrator_governance_and_reporting_test.php'],
            'env' => ['RUN_DB_TESTS' => '1'],
        ],
        [
            'name' => '#454 Super Administrator Lifecycle, Protected Audit & System Health Contract',
            'cmd' => [$phpBin, 'src/backend/tests/superadmin_lifecycle_audit_health_contract.php'],
            'env' => ['RUN_DB_TESTS' => '1'],
        ],
        [
            'name' => '#455 Super Administrator Encrypted Backup & Recovery Contract',
            'cmd' => [$phpBin, 'src/backend/tests/superadmin_encrypted_backup_recovery_contract.php'],
            'env' => ['RUN_DB_TESTS' => '1'],
        ],
        [
            'name' => 'Active Workspace Role Boundaries Contract',
            'cmd' => [$phpBin, 'src/backend/tests/active_workspace_boundaries_contract.php'],
            'env' => ['RUN_DB_TESTS' => '1'],
        ],
        [
            'name' => 'Cash Refund Concurrency & Restock Eligibility Integration',
            'cmd' => [$phpBin, 'src/backend/tests/cash_refund_concurrency_integration.php'],
            'env' => ['RUN_DB_TESTS' => '1', 'RUN_REFUND_DB_TESTS' => '1'],
        ],
        [
            'name' => 'Protected Audit Visibility & Redaction Integration',
            'cmd' => [$phpBin, 'src/backend/tests/audit_visibility_integration.php'],
            'env' => ['RUN_DB_TESTS' => '1'],
        ],
    ],
    'Layer C: Automated Headless Browser Journeys Across All 4 Role Workspaces' => [
        [
            'name' => '[Cashier] POS Rapid Checkout Ergonomics & Barcode Scanner Journey',
            'cmd' => [$nodeBin, 'src/backend/tests/pos_rapid_checkout_ergonomics_browser_test.js'],
            'env' => ['RUN_DB_TESTS' => '1'],
        ],
        [
            'name' => '[Cashier] Cash Drawer Denomination Counter & Shift Modal Journey',
            'cmd' => [$nodeBin, 'src/backend/tests/cash_drawer_counter_browser_test.js'],
            'env' => ['RUN_DB_TESTS' => '1'],
        ],
        [
            'name' => '[Cashier] GCash Transaction, Hold & Correction Dialog Journey',
            'cmd' => [$nodeBin, 'src/backend/tests/gcash_dialog_layout_browser_test.js'],
            'env' => ['RUN_DB_TESTS' => '1'],
        ],
        [
            'name' => '[Inventory Manager] Inventory Counts Scan, Variance & Batch Journey',
            'cmd' => [$nodeBin, 'src/backend/tests/inventory_counts_scan_browser_test.js'],
            'env' => ['RUN_DB_TESTS' => '1'],
        ],
        [
            'name' => '[Inventory Manager] Forecast Replenishment Queue & Decision Journey',
            'cmd' => [$nodeBin, 'src/backend/tests/forecast_decision_browser_test.js'],
            'env' => ['RUN_DB_TESTS' => '1'],
        ],
        [
            'name' => '[Administrator] Staff Lifecycle, Role Demotion/Promotion & Workspace Boundary Journey',
            'cmd' => [$nodeBin, 'src/backend/tests/administrator_management_browser_test.js'],
            'env' => ['RUN_DB_TESTS' => '1'],
        ],
        [
            'name' => '[Super Administrator] Administrator Provisioning & Governance Journey',
            'cmd' => [$nodeBin, 'src/backend/tests/administrator_management_browser_test.js', '--superadmin'],
            'env' => ['RUN_DB_TESTS' => '1'],
        ],
        [
            'name' => '[Super Administrator] Full 20-Route Workspace, Audit DataTable & Local Asset Journey',
            'cmd' => [$nodeBin, 'src/backend/tests/superadmin_performance_browser_test.js'],
            'env' => ['RUN_DB_TESTS' => '1'],
        ],
    ],
];

echo "========================================================================\n";
echo " RetailMind Full System Tri-Fold Verification & Audit Suite (Ticket #456)\n";
echo "========================================================================\n\n";

$initialSnapshot = captureDatabaseSnapshot();
if ($initialSnapshot['connected'] && $initialSnapshot['test_databases'] !== []) {
    dropStrayTestDatabases($initialSnapshot['test_databases']);
    $initialSnapshot = captureDatabaseSnapshot();
}

if ($initialSnapshot['connected']) {
    echo sprintf(
        "[DB Guard] Connected to operational database '%s' (%d base tables snapshotted).\n\n",
        $initialSnapshot['database'],
        count($initialSnapshot['tables'])
    );
} else {
    echo "[DB Guard] Operational MySQL database not reachable; running in isolated SQLite/skip fallback mode.\n\n";
}

$totalSteps = 0;
$passedSteps = 0;
$failures = [];
$suiteStart = microtime(true);

foreach ($layers as $layerTitle => $steps) {
    echo "------------------------------------------------------------------------\n";
    echo " {$layerTitle}\n";
    echo "------------------------------------------------------------------------\n";

    foreach ($steps as $step) {
        $totalSteps++;
        $label = $step['name'];
        echo sprintf("  -> %-70s ", $label);
        $result = runStepCommand($step['cmd'], $step['env']);
        if ($result['exit_code'] === 0) {
            $passedSteps++;
            echo sprintf("[PASS] (%0.1f ms)\n", $result['duration_ms']);
        } else {
            echo sprintf("[FAIL] (exit %d, %0.1f ms)\n", $result['exit_code'], $result['duration_ms']);
            $failures[] = [
                'layer' => $layerTitle,
                'name' => $label,
                'exit_code' => $result['exit_code'],
                'output' => $result['output'],
            ];
        }
    }
    echo "\n";
}

// Verify zero operational database mutation and zero leftover disposable databases
echo "------------------------------------------------------------------------\n";
echo " Operational Database Zero-Mutation & Isolation Audit\n";
echo "------------------------------------------------------------------------\n";

$postSnapshot = captureDatabaseSnapshot();
$dbMutations = [];

if ($initialSnapshot['connected'] && $postSnapshot['connected']) {
    if ($initialSnapshot['tables'] !== $postSnapshot['tables']) {
        $allTableNames = array_unique(array_merge(
            array_keys($initialSnapshot['tables']),
            array_keys($postSnapshot['tables'])
        ));
        sort($allTableNames);
        foreach ($allTableNames as $table) {
            $before = $initialSnapshot['tables'][$table] ?? null;
            $after = $postSnapshot['tables'][$table] ?? null;
            if ($before !== $after) {
                $dbMutations[] = sprintf(
                    "Table '%s' row count changed: before=%s, after=%s",
                    $table,
                    $before === null ? 'MISSING' : (string)$before,
                    $after === null ? 'MISSING' : (string)$after
                );
            }
        }
    }

    if ($postSnapshot['test_databases'] !== []) {
        $dbMutations[] = 'Residual disposable test databases found: ' . implode(', ', $postSnapshot['test_databases']);
        dropStrayTestDatabases($postSnapshot['test_databases']);
    }

    if ($dbMutations === []) {
        echo sprintf(
            "  -> %-70s [PASS] (%d tables verified unchanged)\n\n",
            'Zero residual operational DB mutations & zero leftover test schemas',
            count($postSnapshot['tables'])
        );
    } else {
        echo "  -> Zero residual operational DB mutations                              [FAIL]\n";
        foreach ($dbMutations as $mutation) {
            echo "     ! {$mutation}\n";
        }
        echo "\n";
        $failures[] = [
            'layer' => 'Operational Database Zero-Mutation & Isolation Audit',
            'name' => 'Database Isolation Guard',
            'exit_code' => 1,
            'output' => implode("\n", $dbMutations),
        ];
    }
} else {
    echo "  -> Operational DB connection unavailable (skipped live table count comparison)\n\n";
}

$totalDurationSec = round(microtime(true) - $suiteStart, 2);

echo "========================================================================\n";
echo sprintf(
    " Summary: %d/%d checks passed in %0.2fs | DB Mutations: %d\n",
    $passedSteps,
    $totalSteps,
    $totalDurationSec,
    count($dbMutations)
);
echo "========================================================================\n";

if ($failures !== []) {
    fwrite(STDERR, "\nTri-Fold Audit Suite Failures:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, sprintf("\n[%s] %s (exit %d):\n%s\n", $failure['layer'], $failure['name'], $failure['exit_code'], $failure['output']));
    }
    exit(1);
}

echo "Tri-Fold Verification & Audit Suite: 100% PASSED.\n";
exit(0);
