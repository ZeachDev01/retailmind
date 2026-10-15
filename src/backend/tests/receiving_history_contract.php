<?php
// Contract and integration test for dedicated grouped receiving history (#183).
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Services/ReceivingService.php';

use App\Core\Database;

$token = 'hist' . bin2hex(random_bytes(3));
$fixtureScript = __DIR__ . '/support/scan_workflow_fixture.php';
$phpBinary = PHP_BINARY;

function runFixture(string $php, string $script, array $args): array {
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' ' . implode(' ', array_map('escapeshellarg', $args));
    exec($cmd, $output, $code);
    if ($code !== 0 || empty($output)) {
        throw new RuntimeException("Fixture failed: code {$code}, output: " . implode("\n", $output));
    }
    return json_decode(end($output), true);
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
    echo "  ok - {$message}\n";
};

$fixture = runFixture($phpBinary, $fixtureScript, ['--create', '--token', $token]);

try {
    $pdo = Database::connection();
    $service = new ReceivingService($pdo);

    $managerId = (int)$fixture['staff']['manager']['user_id'];
    $managerTwoId = (int)$fixture['staff']['manager_two']['user_id'];
    $bulkUnitsId = (int)$fixture['products']['bulk_units']['product_id'];
    $bulkPackId = (int)$fixture['products']['bulk_pack']['product_id'];

    echo "Grouped shopping trip persistence\n";
    $tripOne = [
        'supplier' => 'Costco-' . $token,
        'invoice_number' => 'INV-ONE-' . $token,
    ];
    $linesOne = [
        [
            'product_id' => $bulkUnitsId,
            'quantity_mode' => 'units',
            'received_qty' => 5,
            'damaged_qty' => 1,
            'cost_price' => '7.50',
            'batch_number' => 'BATCH-A-' . $token,
            'expiration_date' => '2028-12-31',
        ],
        [
            'product_id' => $bulkPackId,
            'quantity_mode' => 'packages',
            'received_packages' => 2,
            'damaged_qty' => 0,
            'cost_price' => '', // Unknown cost
            'batch_number' => 'BATCH-B-' . $token,
        ],
    ];

    $resultsOne = $service->receiveShoppingTrip($linesOne, $tripOne, $managerId);
    $assert(count($resultsOne) === 2, 'multi-product shopping trip returns two line summaries');
    $groupIdOne = $resultsOne[0]['group_id'];
    $assert(!empty($groupIdOne) && $resultsOne[1]['group_id'] === $groupIdOne, 'both lines share identical group_id');

    // DB inspection for group creation
    $groupRow = $pdo->query("SELECT * FROM stock_receiving_groups WHERE group_id = {$groupIdOne}")->fetch(PDO::FETCH_ASSOC);
    $assert(!empty($groupRow), 'stock_receiving_groups record created');
    $assert($groupRow['supplier'] === 'Costco-' . $token && $groupRow['invoice_number'] === 'INV-ONE-' . $token, 'group stores shopping details');

    // Second separate trip with exact same supplier name
    $tripTwo = [
        'supplier' => 'Costco-' . $token,
        'invoice_number' => 'INV-TWO-' . $token,
    ];
    $linesTwo = [
        [
            'product_id' => $bulkUnitsId,
            'quantity_mode' => 'units',
            'received_qty' => 3,
            'damaged_qty' => 0,
        ],
    ];
    $resultsTwo = $service->receiveShoppingTrip($linesTwo, $tripTwo, $managerTwoId);
    $groupIdTwo = $resultsTwo[0]['group_id'];
    $assert(!empty($groupIdTwo) && $groupIdTwo !== $groupIdOne, 'separate save receives distinct group_id despite same store');

    // Failed save produces no partial group
    echo "Atomic failure rolls back group creation\n";
    $groupsBeforeFail = (int)$pdo->query('SELECT COUNT(*) FROM stock_receiving_groups')->fetchColumn();
    $linesBeforeFail = (int)$pdo->query('SELECT COUNT(*) FROM stock_receiving')->fetchColumn();
    $failedTrip = [
        'supplier' => 'FailStore-' . $token,
        'invoice_number' => 'INV-FAIL-' . $token,
    ];
    $failingLines = [
        [
            'product_id' => $bulkUnitsId,
            'quantity_mode' => 'units',
            'received_qty' => 2,
        ],
        [
            'product_id' => $bulkUnitsId, // Duplicate product on line 2!
            'quantity_mode' => 'units',
            'received_qty' => 2,
        ],
    ];

    $exceptionThrown = false;
    try {
        $service->receiveShoppingTrip($failingLines, $failedTrip, $managerId);
    } catch (Throwable $e) {
        $exceptionThrown = true;
    }
    $assert($exceptionThrown, 'duplicate product line throws exception');
    $groupsAfterFail = (int)$pdo->query('SELECT COUNT(*) FROM stock_receiving_groups')->fetchColumn();
    $linesAfterFail = (int)$pdo->query('SELECT COUNT(*) FROM stock_receiving')->fetchColumn();
    $assert($groupsAfterFail === $groupsBeforeFail && $linesAfterFail === $linesBeforeFail, 'failed save creates no partial group or receiving lines');

    echo "Grouped history inspection & pack conversion\n";
    // Change product pack size configuration
    $pdo->prepare('UPDATE products SET units_per_package = 48 WHERE product_id = ?')->execute([$bulkPackId]);

    $historyMgrOne = $service->getGroupedReceivingHistory($managerId);
    $historyMgrTwo = $service->getGroupedReceivingHistory($managerTwoId);
    $historyOversight = $service->getGroupedReceivingHistory(null);

    // Oversight sees both
    $oversightGroupIds = array_column($historyOversight, 'group_id');
    $assert(in_array($groupIdOne, $oversightGroupIds, true) && in_array($groupIdTwo, $oversightGroupIds, true),
        'Administrator oversight sees all store receiving groups');

    // Manager 1 only sees trip 1
    $mgrOneGroupIds = array_column($historyMgrOne, 'group_id');
    $assert(in_array($groupIdOne, $mgrOneGroupIds, true) && !in_array($groupIdTwo, $mgrOneGroupIds, true),
        'Inventory Manager history is strictly scoped to own recorded entries');

    // Inspect group 1
    $inspectedGroup = null;
    foreach ($historyMgrOne as $g) {
        if ($g['group_id'] === $groupIdOne) {
            $inspectedGroup = $g;
            break;
        }
    }
    $assert($inspectedGroup !== null, 'found entry 1 in history');
    $assert(count($inspectedGroup['lines']) === 2, 'inspected entry contains 2 product lines');
    $assert($inspectedGroup['total_accepted_qty'] === 52, 'inspected entry total accepted units is 4 + 48 = 52');
    $assert($inspectedGroup['total_damaged_qty'] === 1, 'inspected entry total damaged is 1');

    $packLine = null;
    $unitLine = null;
    foreach ($inspectedGroup['lines'] as $line) {
        if ($line['product_id'] === $bulkPackId) $packLine = $line;
        if ($line['product_id'] === $bulkUnitsId) $unitLine = $line;
    }

    $assert($packLine !== null && $packLine['units_per_package_used'] === 24,
        'history uses recorded pack conversion 24 even after product pack config changed to 48');
    $assert($packLine['cost_price'] === null, 'omitted cost remains strictly null (visibly unknown)');
    $assert($unitLine !== null && $unitLine['cost_price'] === 7.50, 'supplied cost persists correctly');
    $assert($unitLine['accepted_qty'] === 4 && $unitLine['damaged_qty'] === 1, 'accepted and damaged quantities match');

    // Ungrouped legacy line forms independent entry without key collision
    $pdo->prepare('INSERT INTO stock_receiving (group_id, product_id, received_by, received_qty, accepted_qty, damaged_qty, units_per_package_used)
        VALUES (NULL, ?, ?, 1, 1, 0, 1)')->execute([$bulkUnitsId, $managerId]);
    $legacyReceivingId = (int)$pdo->lastInsertId();
    $historyWithLegacy = $service->getGroupedReceivingHistory($managerId);
    $legacyEntry = array_values(array_filter($historyWithLegacy, fn($g) => $g['group_id'] === $legacyReceivingId && !$g['has_explicit_group']));
    $assert(count($legacyEntry) === 1, 'ungrouped line forms independent entry without explicit group or key collision');

    echo "Receiving history contract passed.\n";
} finally {
    runFixture($phpBinary, $fixtureScript, ['--cleanup', '--token', $token]);
}
