<?php
// High-level contract for the server-side Receipt Management data interface.
if (getenv('RUN_DB_TESTS') !== '1') {
    echo "Receipt table contract: skipped (set RUN_DB_TESTS=1)\n";
    exit(0);
}

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../config/db.php';

use App\Services\ReceiptTableService;
use App\Store\StoreScope;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

try {
    $pdo->beginTransaction();
    $suffix = bin2hex(random_bytes(4));
    $cashierRoleId = (int)$pdo->query("SELECT role_id FROM roles WHERE role_name = 'cashier' LIMIT 1")->fetchColumn();

    $storeId = (new StoreScope($pdo))->migrate();

    $userStatement = $pdo->prepare(
        "INSERT INTO users (full_name, username, email, password_hash, role_id, status, must_change_password, branch_id)
         VALUES (?, ?, ?, ?, ?, 'active', 0, ?)"
    );
    $password = password_hash('ReceiptContract123', PASSWORD_DEFAULT);
    $userStatement->execute(['Alice Receipt', "receipt_alice_{$suffix}", "receipt_alice_{$suffix}@example.test", $password, $cashierRoleId, $storeId]);
    $aliceId = (int)$pdo->lastInsertId();
    $userStatement->execute(['Bob Receipt', "receipt_bob_{$suffix}", "receipt_bob_{$suffix}@example.test", $password, $cashierRoleId, $storeId]);
    $bobId = (int)$pdo->lastInsertId();

    $productStatement = $pdo->prepare(
        "INSERT INTO products
            (sku, barcode, product_name, unit_price, cost_price, quantity_purchased, quantity_sold, reorder_level, status, branch_id)
         VALUES (?, ?, 'Receipt Contract Product', 20.00, 10.00, 10, 0, 1, 'active', ?)"
    );
    $productStatement->execute(["RC-{$suffix}", "RCB-{$suffix}", $storeId]);
    $productId = (int)$pdo->lastInsertId();

    $saleStatement = $pdo->prepare(
        'INSERT INTO sales (cashier_id, total_amount, payment_method, sale_date) VALUES (?, ?, ?, ?)'
    );
    $itemStatement = $pdo->prepare(
        'INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, subtotal) VALUES (?, ?, ?, ?, ?)'
    );
    $saleIds = [];
    foreach ([
        [$aliceId, 30.00, 'cash', '2026-01-02 09:00:00', 1],
        [$aliceId, 90.00, 'card', '2026-01-03 09:00:00', 3],
        [$aliceId, 50.00, 'ewallet', '2026-01-03 09:00:00', 2],
        [$bobId, 10.00, 'cash', '2026-01-04 09:00:00', 1],
    ] as [$cashierId, $total, $payment, $date, $quantity]) {
        $saleStatement->execute([$cashierId, $total, $payment, $date]);
        $saleId = (int)$pdo->lastInsertId();
        $saleIds[] = $saleId;
        for ($itemIndex = 0; $itemIndex < $quantity; $itemIndex++) {
            $itemStatement->execute([$saleId, $productId, 1, $total / $quantity, $total / $quantity]);
        }
    }

    $reversalStatement = $pdo->prepare(
        "INSERT INTO sale_reversals
            (sale_id, reversal_type, status, reason, settlement_method, refund_amount, requested_by)
         VALUES (?, 'refund', ?, 'Contract test', 'cash', 1.00, ?)"
    );
    $reversalStatement->execute([$saleIds[0], 'pending', $aliceId]);
    $reversalStatement->execute([$saleIds[1], 'approved', $aliceId]);

    // Historical receipts remain Store-queryable even if an old cashier record has no compatibility identity.
    $pdo->prepare('UPDATE users SET branch_id = NULL WHERE user_id = ?')->execute([$bobId]);

    $service = new ReceiptTableService($pdo);
    $baseRequest = ['draw' => '7', 'start' => '0', 'length' => '25', 'branch_id' => '999999'];

    $default = $service->fetch($baseRequest);
    $assert($default['draw'] === 7, 'draw should be returned as an integer');
    $assert($default['recordsTotal'] === 4, 'Store totals should include every receipt regardless of client branch input');
    $assert(count($default['data']) === 4, 'default page should return the Store receipts');
    $assert(array_column($default['data'], 'sale_id') === [$saleIds[3], $saleIds[2], $saleIds[1], $saleIds[0]], 'default order should be date then receipt identifier descending');

    $paged = $service->fetch(array_merge($baseRequest, ['start' => '1', 'length' => '10']), $aliceId);
    $assert($paged['recordsTotal'] === 3, 'cashier capability scope should be applied to the permitted total');
    $assert($paged['recordsFiltered'] === 3 && count($paged['data']) === 2, 'paging should return the requested permitted slice');
    $assert(count(array_filter($paged['data'], static fn(array $row): bool => (int)$row['cashier_id'] !== $aliceId)) === 0, 'cashier scope must constrain returned rows');

    $searched = $service->fetch($baseRequest + ['search' => ['value' => 'ewallet']], $aliceId);
    $assert($searched['recordsFiltered'] === 1 && (int)$searched['data'][0]['sale_id'] === $saleIds[2], 'global search should match payment method');
    $byReceipt = $service->fetch($baseRequest + ['search' => ['value' => (string)$saleIds[1]]], $aliceId);
    $assert($byReceipt['recordsFiltered'] === 1, 'global search should match receipt number');

    $dateFiltered = $service->fetch($baseRequest + ['date_from' => '2026-01-03', 'date_to' => '2026-01-03'], $aliceId);
    $assert($dateFiltered['recordsFiltered'] === 2, 'date range should filter permitted rows');
    $cashierFiltered = $service->fetch($baseRequest + ['cashier_id' => (string)$bobId]);
    $assert($cashierFiltered['recordsFiltered'] === 1, 'authorized cashier filter should match one cashier');
    $pending = $service->fetch($baseRequest + ['reversal_status' => 'pending'], $aliceId);
    $approved = $service->fetch($baseRequest + ['reversal_status' => 'approved'], $aliceId);
    $none = $service->fetch($baseRequest + ['reversal_status' => 'none'], $aliceId);
    $assert($pending['recordsFiltered'] === 1 && $approved['recordsFiltered'] === 1 && $none['recordsFiltered'] === 1, 'each reversal-status filter should be applied');

    $totalAscending = $service->fetch($baseRequest + ['order' => [['column' => '4', 'dir' => 'asc']]], $aliceId);
    $assert(array_column($totalAscending['data'], 'sale_id') === [$saleIds[0], $saleIds[2], $saleIds[1]], 'total should sort numerically');
    $itemDescending = $service->fetch($baseRequest + ['order' => [['column' => '3', 'dir' => 'desc']]], $aliceId);
    $assert(array_column($itemDescending['data'], 'item_count') === [3, 2, 1], 'item count should sort numerically');

    $malformed = $service->fetch(array_merge($baseRequest, [
        'length' => '99999',
        'date_from' => 'not-a-date',
        'reversal_status' => 'arbitrary',
        'order' => [['column' => '999', 'dir' => 'sideways']],
    ]), $aliceId);
    $assert(count($malformed['data']) === 3, 'malformed filters and ordering should safely fall back without excluding rows');
    $assert(array_column($malformed['data'], 'sale_id') === [$saleIds[2], $saleIds[1], $saleIds[0]], 'unsupported ordering should use deterministic newest-first fallback');

    $pdo->rollBack();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $failures[] = 'Receipt table contract threw: ' . $exception->getMessage();
}

if ($failures) {
    fwrite(STDERR, "Receipt table contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Receipt table contract: passed\n";
