<?php
// CLI fixture for the Inventory Counts scan browser workflow test (#79).
// Creates disposable Store data (products, stock, staff accounts) keyed by a
// run token and removes every row it created, so the suite never depends on
// whatever happens to be seeded in the development database.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../../bootstrap/app.php';

use App\Core\Database;

$options = getopt('', ['token:', 'create', 'cleanup', 'state', 'seed-guidance', 'hold-gate', 'close-period', 'open-period', 'fail-purchase', 'allow-purchase', 'delay-purchase', 'purchase-product:']);

$token = preg_replace('/[^A-Za-z0-9]/', '', (string)($options['token'] ?? ''));
if (strlen($token) < 4 || strlen($token) > 12) {
    fwrite(STDERR, "A 4-12 character alphanumeric token is required.\n");
    exit(1);
}
$shortNumericSku = str_pad((string)(crc32($token) % 10000000), 7, '0', STR_PAD_LEFT);

$pdo = Database::connection();

$storeId = (int)(new App\Store\StoreScope($pdo))->id();

$roleId = static function (PDO $pdo, string $name): int {
    $stmt = $pdo->prepare('SELECT role_id FROM roles WHERE role_name = ?');
    $stmt->execute([$name]);
    $roleId = (int)$stmt->fetchColumn();
    if ($roleId <= 0) {
        throw new RuntimeException("Role {$name} is missing.");
    }
    return $roleId;
};

$products = [
    'bulk_units' => [
        'sku' => 'SCN' . $token . 'U', 'barcode' => '840' . $token . '00010', 'case_barcode' => null,
        'product_name' => 'Scan WF Shopping Units', 'status' => 'active', 'quantity_on_hand' => 8,
    ],
    'bulk_pack' => [
        'sku' => 'SCN' . $token . 'C', 'barcode' => '840' . $token . '00011', 'case_barcode' => 'CS' . $token . '0011',
        'product_name' => 'Scan WF Shopping Cases', 'status' => 'active', 'quantity_on_hand' => 8,
        'units_per_package' => 24, 'receiving_unit' => 'case',
    ],
    'direct' => [
        'sku' => 'SCN' . $token . 'D',
        'barcode' => '840' . $token . '00008',
        'case_barcode' => 'CS' . $token . '0008',
        'product_name' => 'Scan WF Direct Receiving',
        'status' => 'active',
        'quantity_on_hand' => 8,
        'units_per_package' => 24,
        'receiving_unit' => 'case',
    ],
    'invalid_pack' => [
        'sku' => 'SCN' . $token . 'I',
        'barcode' => '840' . $token . '00009',
        'case_barcode' => null,
        'product_name' => 'Scan WF Invalid Pack',
        'status' => 'active',
        'quantity_on_hand' => 8,
        'units_per_package' => 0,
    ],
    'countable' => [
        'sku' => 'SCN' . $token . 'A',
        'barcode' => '840' . $token . '00001',
        'case_barcode' => 'CS' . $token . '0001',
        'product_name' => 'Scan WF Countable',
        'status' => 'active',
        'quantity_on_hand' => 10,
        'units_per_package' => 6,
        'base_unit' => 'piece',
        'receiving_unit' => 'case',
    ],
    'zero' => [
        'sku' => 'SCN' . $token . 'Z',
        'barcode' => '840' . $token . '00002',
        'case_barcode' => 'CS' . $token . '0002',
        'product_name' => 'Scan WF Zero Stock',
        'status' => 'active',
        'quantity_on_hand' => 0,
    ],
    'ambiguous_a' => [
        'sku' => 'SCN' . $token . 'P',
        'barcode' => 'AMBIG' . $token,
        'case_barcode' => null,
        'product_name' => 'Scan WF Ambiguous Stocked',
        'status' => 'active',
        'quantity_on_hand' => 5,
    ],
    'ambiguous_b' => [
        'sku' => 'AMBIG' . $token,
        'barcode' => 'SCN' . $token . 'Q',
        'case_barcode' => null,
        'product_name' => 'Scan WF Ambiguous Empty',
        'status' => 'active',
        'quantity_on_hand' => 0,
    ],
    'inactive' => [
        'sku' => 'SCN' . $token . 'X',
        'barcode' => '840' . $token . '00005',
        'case_barcode' => 'CS' . $token . '0005',
        'product_name' => 'Scan WF Inactive',
        'status' => 'inactive',
        'quantity_on_hand' => 3,
    ],
    // Seven characters: short enough that a wedge burst of this code cannot
    // lean on the burst-length threshold alone to be recognised as a scan.
    'short' => [
        'sku' => 'W' . substr($token, -5) . 'S',
        'barcode' => 'W' . substr($token, -5) . 'B',
        'case_barcode' => null,
        'product_name' => 'Scan WF Short Code',
        'status' => 'active',
        'quantity_on_hand' => 4,
    ],
    'short_numeric' => [
        'sku' => $shortNumericSku,
        'barcode' => 'N' . substr($token, -5) . 'B',
        'case_barcode' => null,
        'product_name' => 'Scan WF Short Numeric Code',
        'status' => 'active',
        'quantity_on_hand' => 6,
    ],
];

$staff = [
    'manager_two' => [
        'username' => 'scnmgrtwo' . $token, 'email' => 'scnmgrtwo' . $token . '@example.test',
        'full_name' => 'Scan WF Second Manager', 'role' => 'inventory_manager',
    ],
    'super_admin' => [
        'username' => 'scnsup' . $token,
        'email' => 'scnsup' . $token . '@example.test',
        'full_name' => 'Scan WF Super Administrator',
        'role' => 'super_admin',
    ],
    'manager' => [
        'username' => 'scnmgr' . $token,
        'email' => 'scnmgr' . $token . '@example.test',
        'full_name' => 'Scan WF Manager',
        'role' => 'inventory_manager',
    ],
    'admin' => [
        'username' => 'scnadm' . $token,
        'email' => 'scnadm' . $token . '@example.test',
        'full_name' => 'Scan WF Administrator',
        'role' => 'admin',
    ],
    'cashier' => [
        'username' => 'scncsh' . $token,
        'email' => 'scncsh' . $token . '@example.test',
        'full_name' => 'Scan WF Cashier',
        'role' => 'cashier',
    ],
];

$productByName = [];
$staffByUsername = [];
$approvedIssue = null;
$receivingDocuments = [];

if (array_key_exists('seed-guidance', $options)) {
    $pdo->prepare("UPDATE products SET reorder_level = 10, safety_stock = 5, supplier_lead_time_days = 7,
        minimum_order_quantity = 1 WHERE sku IN (?, ?)")->execute([$products['bulk_units']['sku'], $products['bulk_pack']['sku']]);
    $pdo->prepare("INSERT INTO ml_jobs (trigger_type, status, queued_at, completed_at, diagnostic_reference)
        VALUES ('forecast_generation', 'succeeded', NOW(), NOW(), ?)")->execute(['receiving-wf-' . $token]);
    $jobId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO demand_forecasts
        (job_id, product_id, forecast_origin, target_date, horizon_day, predicted_units, model_version, readiness_level)
        SELECT ?, product_id, CURRENT_DATE, CURRENT_DATE + INTERVAL 7 DAY, 7, 20, ?, 'High'
        FROM products WHERE sku = ?")->execute([$jobId, 'receiving-wf-' . $token, $products['bulk_pack']['sku']]);
    echo json_encode(['job_id' => $jobId]) . PHP_EOL;
    exit(0);
}

if (array_key_exists('fail-purchase', $options) || array_key_exists('allow-purchase', $options) || array_key_exists('delay-purchase', $options)) {
    $pdo->exec("DROP TRIGGER IF EXISTS `receiving_wf_{$token}`");
    if (array_key_exists('fail-purchase', $options) || array_key_exists('delay-purchase', $options)) {
        $key = $options['purchase-product'] ?? 'direct';
        if (!isset($products[$key])) throw new RuntimeException('Unknown fixture purchase product.');
        $stmt = $pdo->prepare('SELECT product_id FROM products WHERE sku = ?');
        $stmt->execute([$products[$key]['sku']]);
        $productId = (int)$stmt->fetchColumn();
        if ($productId <= 0) throw new RuntimeException('Direct receiving fixture product is missing.');
        $effect = array_key_exists('fail-purchase', $options)
            ? "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Receiving fixture failure'" : 'DO SLEEP(0.5)';
        $pdo->exec("CREATE TRIGGER `receiving_wf_{$token}` BEFORE INSERT ON purchase_history FOR EACH ROW
            BEGIN IF NEW.product_id = {$productId} THEN {$effect}; END IF; END");
    }
    echo json_encode(['ok' => true]) . PHP_EOL;
    exit(0);
}

if (array_key_exists('hold-gate', $options)) {
    $pdo->beginTransaction();
    $pdo->query("SELECT gate_key FROM store_write_gate WHERE gate_key = 'store_writes' FOR UPDATE")->fetch();
    echo json_encode(['ready' => true]) . PHP_EOL;
    flush();
    fgets(STDIN);
    $pdo->rollBack();
    exit(0);
}

if (array_key_exists('close-period', $options) || array_key_exists('open-period', $options)) {
    $name = 'Receiving WF ' . $token;
    $pdo->prepare('DELETE FROM fiscal_periods WHERE period_name = ?')->execute([$name]);
    if (array_key_exists('close-period', $options)) {
        $stmt = $pdo->prepare('SELECT user_id FROM users WHERE username = ?');
        $stmt->execute([$staff['admin']['username']]);
        $pdo->prepare("INSERT INTO fiscal_periods (period_name, start_date, end_date, status, created_by)
            VALUES (?, CURRENT_DATE, CURRENT_DATE, 'closed', ?)")->execute([$name, $stmt->fetchColumn()]);
    }
    echo json_encode(['ok' => true]) . PHP_EOL;
    exit(0);
}

$cleanup = static function () use ($pdo, $products, $staff, $token, &$productByName, &$staffByUsername): void {
    $pdo->exec("DROP TRIGGER IF EXISTS `receiving_wf_{$token}`");
    $pdo->prepare('DELETE FROM ml_jobs WHERE diagnostic_reference = ?')->execute(['receiving-wf-' . $token]);
    $productIds = [];
    $userIds = [];
    foreach ($products as $product) {
        $stmt = $pdo->prepare('SELECT product_id FROM products WHERE sku = ?');
        $stmt->execute([$product['sku']]);
        if ($id = (int)$stmt->fetchColumn()) {
            $productIds[] = $id;
        }
    }
    foreach ($staff as $member) {
        $stmt = $pdo->prepare('SELECT user_id FROM users WHERE username = ?');
        $stmt->execute([$member['username']]);
        if ($id = (int)$stmt->fetchColumn()) {
            $userIds[] = $id;
        }
    }

    // Remove every row that points at this disposable Store data. The tables
    // come from the live schema, so a child table added later is cleaned up
    // without the fixture having to remember it.
    $deleteReferencing = static function (string $parent, array $ids) use ($pdo): void {
        if ($ids === []) {
            return;
        }
        $list = implode(',', array_map('intval', $ids));
        $stmt = $pdo->prepare(
            'SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = ?'
        );
        $stmt->execute([$parent]);
        $deferred = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $table = $row['TABLE_NAME'];
            $column = $row['COLUMN_NAME'];
            try {
                $pdo->exec("DELETE FROM `{$table}` WHERE `{$column}` IN ({$list})");
            } catch (PDOException $ignored) {
                $deferred[] = [$table, $column];
            }
        }
        // A second pass clears rows whose parent row was only reachable after
        // the first pass emptied another child table.
        foreach ($deferred as [$table, $column]) {
            try {
                $pdo->exec("DELETE FROM `{$table}` WHERE `{$column}` IN ({$list})");
            } catch (PDOException $errorAgain) {
                throw new RuntimeException(
                    "Could not clear {$table}.{$column}: " . $errorAgain->getMessage(),
                    0,
                    $errorAgain
                );
            }
        }
    };

    $in = static function (array $ids): string {
        return $ids === [] ? 'NULL' : implode(',', array_map('intval', $ids));
    };
    $userList = $in($userIds);

    if ($userIds !== []) {
        $pdo->exec("DELETE FROM login_attempts WHERE username IN (" . implode(
            ',',
            array_map(static fn(int $id): string => "'user:{$id}'", $userIds)
        ) . ")");
    }

    $deleteReferencing('products', $productIds);
    $deleteReferencing('users', $userIds);

    if ($productIds !== []) {
        $pdo->exec('DELETE FROM products WHERE product_id IN (' . $in($productIds) . ')');
    }
    if ($userIds !== []) {
        $pdo->exec("DELETE FROM users WHERE user_id IN ({$userList})");
    }
};

if (array_key_exists('cleanup', $options)) {
    $cleanup();
    echo json_encode(['ok' => true]) . PHP_EOL;
    exit(0);
}

if (array_key_exists('state', $options)) {
    $skus = array_column($products, 'sku');
    $placeholders = implode(',', array_fill(0, count($skus), '?'));
    $stmt = $pdo->prepare(
        "SELECT p.sku, p.product_id, COALESCE(i.quantity_on_hand, 0) AS quantity_on_hand,
                p.status, p.cost_price, p.preferred_supplier, p.supplier, p.quantity_purchased
         FROM products p LEFT JOIN inventory i ON i.product_id = p.product_id
         WHERE p.sku IN ({$placeholders})"
    );
    $stmt->execute($skus);
    $catalog = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare(
        "SELECT ic.count_id, ic.product_id, p.sku, ic.system_quantity, ic.physical_quantity,
                ic.difference_qty, ic.status, ic.discrepancy_reason, ic.related_adjustment_id
         FROM inventory_counts ic JOIN products p ON p.product_id = ic.product_id
         WHERE p.sku IN ({$placeholders})
         ORDER BY ic.count_id"
    );
    $stmt->execute($skus);
    $counts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare(
        "SELECT sr.receiving_id, sr.product_id, p.sku, sr.received_qty, sr.received_packages,
                sr.units_per_package_used, sr.accepted_qty, sr.damaged_qty,
                sr.purchase_order_item_id, sr.replenishment_request_id,
                sr.supplier, sr.po_number, sr.invoice_number, sr.batch_number,
                sr.expiration_date, sr.discrepancy_type, sr.discrepancy_notes,
                sr.cost_price, sr.total_cost, sr.received_by, sr.received_at
         FROM stock_receiving sr JOIN products p ON p.product_id = sr.product_id
         WHERE p.sku IN ({$placeholders}) ORDER BY sr.receiving_id"
    );
    $stmt->execute($skus);
    $receipts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare(
        "SELECT poi.purchase_order_item_id, p.sku, poi.ordered_qty, poi.received_qty, po.status
         FROM purchase_order_items poi
         JOIN purchase_orders po ON po.purchase_order_id = poi.purchase_order_id
         JOIN products p ON p.product_id = poi.product_id
         WHERE p.sku IN ({$placeholders}) ORDER BY poi.purchase_order_item_id"
    );
    $stmt->execute($skus);
    $purchaseOrderItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare(
        "SELECT rr.request_id, p.sku, rr.request_qty, rr.status
         FROM replenishment_requests rr JOIN products p ON p.product_id = rr.product_id
         WHERE p.sku IN ({$placeholders}) ORDER BY rr.request_id"
    );
    $stmt->execute($skus);
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $details = [];
    foreach (['product_batches', 'purchase_history', 'stock_movements', 'inventory_adjustments'] as $table) {
        $stmt = $pdo->prepare("SELECT t.*, p.sku FROM {$table} t JOIN products p ON p.product_id = t.product_id
            WHERE p.sku IN ({$placeholders})");
        $stmt->execute($skus);
        $details[$table] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    echo json_encode([
        'ok' => true,
        'products' => $catalog,
        'counts' => $counts,
        'receipts' => $receipts,
        'purchase_order_items' => $purchaseOrderItems,
        'requests' => $requests,
        'recommendations' => array_values(array_filter(
            (new App\Forecasting\ReplenishmentQueueService($pdo))->getRecommendations(
                (new App\Forecasting\DemandForecastService($pdo))->getLatestSuccessfulJobId()
            ), static fn(array $item): bool => in_array($item['sku'], $skus, true)
        )),
        'forecast_jobs' => $pdo->query('SELECT * FROM ml_jobs ORDER BY job_id')->fetchAll(PDO::FETCH_ASSOC),
        'forecasts' => $pdo->query('SELECT * FROM demand_forecasts ORDER BY forecast_id')->fetchAll(PDO::FETCH_ASSOC),
        ...$details,
    ]) . PHP_EOL;
    exit(0);
}

if (!array_key_exists('create', $options)) {
    fwrite(STDERR, "Use --create, --state or --cleanup with --token.\n");
    exit(1);
}

$cleanup();

try {
    $insertProduct = $pdo->prepare(
        'INSERT INTO products (branch_id, sku, barcode, case_barcode, product_name, unit_price, cost_price,
                               units_per_package, base_unit, receiving_unit, status)
         VALUES (?, ?, ?, ?, ?, 10.00, 5.00, ?, ?, ?, ?)'
    );
    $insertInventory = $pdo->prepare(
        'INSERT INTO inventory (product_id, quantity_on_hand) VALUES (?, ?)'
    );
    foreach ($products as $key => $product) {
        $insertProduct->execute([
            $storeId,
            $product['sku'],
            $product['barcode'],
            $product['case_barcode'],
            $product['product_name'],
            $product['units_per_package'] ?? 1,
            $product['base_unit'] ?? 'piece',
            $product['receiving_unit'] ?? 'package',
            $product['status'],
        ]);
        $productId = (int)$pdo->lastInsertId();
        $pdo->prepare("UPDATE products SET preferred_supplier = 'Original preferred supplier', supplier = 'Original supplier' WHERE product_id = ?")
            ->execute([$productId]);
        $insertInventory->execute([$productId, $product['quantity_on_hand']]);
        $productByName[$key] = [
            'product_id' => $productId,
            'sku' => $product['sku'],
            'barcode' => $product['barcode'],
            'case_barcode' => $product['case_barcode'],
            'product_name' => $product['product_name'],
            'status' => $product['status'],
            'quantity_on_hand' => $product['quantity_on_hand'],
            'units_per_package' => $product['units_per_package'] ?? 1,
            'base_unit' => $product['base_unit'] ?? 'piece',
            'receiving_unit' => $product['receiving_unit'] ?? 'package',
        ];
    }

    $insertUser = $pdo->prepare(
        'INSERT INTO users (full_name, username, email, password_hash, role_id, status, session_version, must_change_password, branch_id)
         VALUES (?, ?, ?, ?, ?, \'active\', 1, 0, ?)'
    );
    foreach ($staff as $key => $member) {
        $insertUser->execute([
            $member['full_name'],
            $member['username'],
            $member['email'],
            password_hash('Scanwf-Test-Passw0rd!', PASSWORD_DEFAULT),
            $roleId($pdo, $member['role']),
            $storeId,
        ]);
        $staffByUsername[$key] = [
            'user_id' => (int)$pdo->lastInsertId(),
            'username' => $member['username'],
            'password' => 'Scanwf-Test-Passw0rd!',
            'role' => $member['role'],
        ];
    }

    $insertApprovedIssue = $pdo->prepare(
        "INSERT INTO inventory_adjustments
            (product_id, adjustment_qty, adjustment_type, reported_by, approved_by,
             approved_at, reason, review_notes, status)
         VALUES (?, -1, 'damaged', ?, ?, CURRENT_TIMESTAMP, ?, ?, 'approved')"
    );
    $insertApprovedIssue->execute([
        $productByName['countable']['product_id'],
        $staffByUsername['cashier']['user_id'],
        $staffByUsername['manager']['user_id'],
        'Disposable approved Stock Issue for scan correction coverage.',
        'Approved for browser workflow coverage.',
    ]);
    $approvedIssue = [
        'adjustment_id' => (int)$pdo->lastInsertId(),
        'product_id' => $productByName['countable']['product_id'],
    ];

    $insertSupplier = $pdo->prepare(
        'INSERT INTO suppliers (supplier_name, contact_person, status, created_by) VALUES (?, ?, \'active\', ?)'
    );
    $supplierName = 'Scan WF Supplier ' . $token;
    $insertSupplier->execute([$supplierName, 'Receiving Test Contact', $staffByUsername['admin']['user_id']]);
    $supplierId = (int)$pdo->lastInsertId();

    $insertApprovedRequest = $pdo->prepare(
        "INSERT INTO replenishment_requests
            (product_id, request_qty, requested_by, status, approved_by, approved_at, source, notes)
         VALUES (?, ?, ?, 'approved', ?, CURRENT_TIMESTAMP, 'manual', ?)"
    );
    $createApprovedRequest = static function (int $productId, int $quantity, string $notes) use (
        $insertApprovedRequest,
        $pdo,
        $staffByUsername
    ): int {
        $insertApprovedRequest->execute([
            $productId,
            $quantity,
            $staffByUsername['manager']['user_id'],
            $staffByUsername['admin']['user_id'],
            $notes,
        ]);
        return (int)$pdo->lastInsertId();
    };
    $poRequestId = $createApprovedRequest(
        $productByName['countable']['product_id'],
        12,
        'Disposable approved request linked to the Stock Receiving PO.'
    );

    $poNumber = 'PO-SCAN-' . strtoupper($token);
    $pdo->prepare(
        "INSERT INTO purchase_orders
            (po_number, supplier_id, status, expected_delivery_date, created_by, approved_by, approved_at)
         VALUES (?, ?, 'approved', CURRENT_DATE, ?, ?, CURRENT_TIMESTAMP)"
    )->execute([
        $poNumber,
        $supplierId,
        $staffByUsername['manager']['user_id'],
        $staffByUsername['admin']['user_id'],
    ]);
    $purchaseOrderId = (int)$pdo->lastInsertId();
    $insertPurchaseOrderItem = $pdo->prepare(
        'INSERT INTO purchase_order_items
            (purchase_order_id, replenishment_request_id, product_id, ordered_qty, received_qty, unit_cost)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $createPurchaseOrderItem = static function (
        ?int $requestId,
        int $orderedQuantity,
        int $receivedQuantity,
        float $unitCost
    ) use ($insertPurchaseOrderItem, $pdo, $purchaseOrderId, $productByName): int {
        $insertPurchaseOrderItem->execute([
            $purchaseOrderId,
            $requestId,
            $productByName['countable']['product_id'],
            $orderedQuantity,
            $receivedQuantity,
            $unitCost,
        ]);
        return (int)$pdo->lastInsertId();
    };
    $purchaseOrderItemId = $createPurchaseOrderItem($poRequestId, 12, 2, 5.50);
    $pdo->prepare("INSERT INTO stock_receiving
        (product_id, received_qty, accepted_qty, received_by, replenishment_request_id,
         purchase_order_id, purchase_order_item_id, supplier, po_number, invoice_number, cost_price, total_cost)
        VALUES (?, 2, 2, ?, ?, ?, ?, ?, ?, ?, 5.50, 11.00)")->execute([
            $productByName['countable']['product_id'], $staffByUsername['manager']['user_id'],
            $poRequestId, $purchaseOrderId, $purchaseOrderItemId, $supplierName, $poNumber, 'HISTORICAL-' . $token,
        ]);

    $alternatePoRequestId = $createApprovedRequest(
        $productByName['countable']['product_id'],
        7,
        'Disposable approved request linked to a second line on the same PO.'
    );
    $alternatePurchaseOrderItemId = $createPurchaseOrderItem($alternatePoRequestId, 7, 1, 5.75);
    $fullyReceivedPurchaseOrderItemId = $createPurchaseOrderItem(null, 5, 5, 5.25);

    $standaloneRequestId = $createApprovedRequest(
        $productByName['countable']['product_id'],
        9,
        'Disposable standalone approved request for multiple-choice receiving coverage.'
    );

    $approvedRequestId = $createApprovedRequest(
        $productByName['zero']['product_id'],
        8,
        'Disposable approved request for Stock Receiving scan coverage.'
    );

    // This pending request proves that an ineligible document is not offered.
    $pdo->prepare(
        "INSERT INTO replenishment_requests
            (product_id, request_qty, requested_by, status, source, notes)
         VALUES (?, 6, ?, 'pending', 'manual', ?)"
    )->execute([
        $productByName['short']['product_id'],
        $staffByUsername['manager']['user_id'],
        'Disposable pending request; scanning must not offer it.',
    ]);
    $pendingRequestId = (int)$pdo->lastInsertId();

    $receivingDocuments = [
        'po' => [
            'purchase_order_id' => $purchaseOrderId,
            'purchase_order_item_id' => $purchaseOrderItemId,
            'po_number' => $poNumber,
            'supplier' => $supplierName,
            'remaining_qty' => 10,
            'replenishment_request_id' => $poRequestId,
        ],
        'alternate_po_line' => [
            'purchase_order_id' => $purchaseOrderId,
            'purchase_order_item_id' => $alternatePurchaseOrderItemId,
            'po_number' => $poNumber,
            'supplier' => $supplierName,
            'remaining_qty' => 6,
            'replenishment_request_id' => $alternatePoRequestId,
        ],
        'standalone_request' => [
            'request_id' => $standaloneRequestId,
            'remaining_qty' => 9,
        ],
        'fully_received_po_line' => [
            'purchase_order_id' => $purchaseOrderId,
            'purchase_order_item_id' => $fullyReceivedPurchaseOrderItemId,
            'po_number' => $poNumber,
        ],
        'approved_request' => [
            'request_id' => $approvedRequestId,
            'remaining_qty' => 8,
        ],
        'pending_request' => [
            'request_id' => $pendingRequestId,
        ],
    ];
} catch (Throwable $exception) {
    $cleanup();
    fwrite(STDERR, 'Fixture creation failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

echo json_encode([
    'ok' => true,
    'store_id' => $storeId,
    'token' => $token,
    'products' => $productByName,
    'staff' => $staffByUsername,
    'approved_issue' => $approvedIssue,
    'receiving_documents' => $receivingDocuments,
]) . PHP_EOL;
