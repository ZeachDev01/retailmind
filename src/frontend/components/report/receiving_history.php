<?php
// components/report/receiving_history.php - dedicated grouped receiving history
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_once __DIR__ . '/../../../backend/app/Services/ReceivingService.php';

require_role(['admin', 'super_admin', 'inventory_manager']);

$receivingService = new ReceivingService($pdo);
$canReceive = has_capability(\App\Authorization\RoleCapabilityPolicy::MUTATE_INVENTORY);
$historyUserId = $canReceive ? (int)$_SESSION['user_id'] : null;

$error = null;
$entries = [];
$search = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]) ?: 1;
$pageSize = filter_var($_GET['length'] ?? 15, FILTER_VALIDATE_INT);
$pageSize = in_array($pageSize, [10, 15, 25, 50, 100], true) ? $pageSize : 15;
$entryKey = $_GET['entry'] ?? null;
$entry = null;
$neighbors = null;
$sortColumns = ['product_name', 'sku', 'accepted_qty', 'expiration_date'];
$sort = in_array($_GET['sort'] ?? null, $sortColumns, true) ? $_GET['sort'] : 'product_name';
$direction = ($_GET['direction'] ?? null) === 'desc' ? 'desc' : 'asc';
$itemPageSize = filter_var($_GET['item_length'] ?? 15, FILTER_VALIDATE_INT);
$itemPageSize = in_array($itemPageSize, [10, 15, 25, 50, 100], true) ? $itemPageSize : 15;
$itemPage = filter_var($_GET['item_page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
$totalRows = 0;
$totalPages = 1;
$hasNext = false;
try {
    if (preg_match('/\A.{0,200}\z/us', $search) !== 1) {
        throw new InvalidArgumentException('Keep your search to 200 characters.');
    }
    if ($entryKey === null) {
        $totalRows = $receivingService->countGroupedReceivingHistory($historyUserId, $search);
        $totalPages = max(1, (int)ceil($totalRows / $pageSize));
        $page = min($page, $totalPages);
        $entries = array_slice($receivingService->getGroupedReceivingHistory($historyUserId, $page, $search, $pageSize), 0, $pageSize);
        $hasNext = $page < $totalPages;
    } else {
        if (is_string($entryKey) && preg_match('/\A[gr]_[1-9][0-9]{0,19}\z/', $entryKey) === 1) {
            $neighbors = $receivingService->getReceivingHistoryNeighbors($historyUserId, $search, $entryKey);
            if ($neighbors !== null) {
                $entry = $receivingService->getGroupedReceivingHistory($historyUserId, entryKey: $entryKey)[0] ?? null;
            }
        }
        if ($entry === null) {
            http_response_code(404);
            $error = 'Receiving entry not found.';
        }
    }
} catch (InvalidArgumentException $e) {
    $error = $e->getMessage();
} catch (Throwable $e) {
    error_log('Receiving history could not be loaded: ' . $e);
    $error = 'Failed to load receiving history. Please refresh or try again.';
}

$historyTitle = $canReceive ? 'My Receiving History' : 'Store Receiving History';
$title = $entry !== null ? 'Entry #' . $entry['group_id'] : $historyTitle;
$historyFilters = ['page' => $page, 'length' => $pageSize, 'q' => $search === '' ? null : $search];
$url = static fn(array $params = []): string => '?' . http_build_query($params + $historyFilters);

if ($entry !== null) {
    $lines = $entry['lines'];
    $batchCounts = array_count_values(array_column($lines, 'product_id'));
    $firstProductLines = array_column(array_reverse($lines), 'receiving_id', 'product_id');
    usort($lines, static function (array $left, array $right) use ($sort, $direction): int {
        $a = $left[$sort];
        $b = $right[$sort];
        if ($sort === 'expiration_date' && (!$a || !$b)) {
            $result = (int)!$a <=> (int)!$b;
        } else {
            $result = is_int($a) ? $a <=> $b : strnatcasecmp($a, $b);
            $result *= $direction === 'desc' ? -1 : 1;
        }
        return $result ?: $left['receiving_id'] <=> $right['receiving_id'];
    });
    $itemCount = count($lines);
    $itemLastPage = max(1, (int)ceil($itemCount / $itemPageSize));
    $itemPage = min($itemPage, $itemLastPage);
    $itemOffset = ($itemPage - 1) * $itemPageSize;
    $itemRows = array_slice($lines, $itemOffset, $itemPageSize);
    $detailFilters = ['entry' => $entryKey, 'sort' => $sort, 'direction' => $direction, 'item_length' => $itemPageSize];
    $detailUrl = static fn(array $params = []): string => $url($params + $detailFilters);
    $itemHeaders = [
        'product_name' => 'Product', 'sku' => 'SKU', 'accepted_qty' => 'Receive',
        'cost_price' => 'Purchase cost', 'expiration_date' => 'Batch / Expiry',
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head><?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?></title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/reports.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/reports.css')) ?>">
</head>
<body class="receiving-history-page">
<a class="history-skip-link" href="#main-content">Skip to receiving history</a>
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content" id="main-content" tabindex="-1">
        <header class="history-heading">
            <h1><?= htmlspecialchars($title) ?></h1>
            <?php if ($entryKey !== null): ?>
                <nav class="history-entry-navigation" aria-label="Receiving entry navigation">
                    <a class="btn btn-secondary" href="<?= htmlspecialchars($url()) ?>">Back to History</a>
                    <?php if ($entry !== null && $neighbors['previous_entry'] !== null): ?>
                        <a class="btn btn-secondary" href="<?= htmlspecialchars($detailUrl(['entry' => $neighbors['previous_entry']])) ?>" rel="prev">Previous Entry</a>
                    <?php endif; ?>
                    <?php if ($entry !== null && $neighbors['next_entry'] !== null): ?>
                        <a class="btn btn-secondary" href="<?= htmlspecialchars($detailUrl(['entry' => $neighbors['next_entry']])) ?>" rel="next">Next Entry</a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        </header>

        <?php if ($entryKey === null): ?>
            <form class="history-search" method="get" role="search" aria-label="Receiving history">
                <input type="hidden" name="length" value="<?= $pageSize ?>">
                <div class="history-search-field">
                    <label class="sr-only" for="history_search">Search receiving history</label>
                    <svg class="history-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 4.5 4.5"/></svg>
                    <input type="search" id="history_search" name="q" value="<?= htmlspecialchars($search) ?>" autocomplete="off" maxlength="200" placeholder="Product, SKU, store, receipt, or staff…">
                </div>
                <button type="submit" class="btn btn-primary">Search</button>
                <?php if ($search !== ''): ?>
                    <a class="btn btn-secondary" href="receiving_history.php">Clear search</a>
                <?php endif; ?>
            </form>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="message error history-section" role="alert">
                <p><?= htmlspecialchars($error) ?></p>
            </div>
        <?php elseif ($entry === null && empty($entries)): ?>
            <div class="empty-state history-section" role="status">
                <h2><?= $search !== '' ? 'No matching receipts' : ($page > 1 ? 'No more receipts' : 'No receiving records found') ?></h2>
                <p><?= $search !== '' ? 'Try a different product, store, receipt, or staff name.' : ($page > 1 ? 'Return to the previous page to see earlier results.' : ($canReceive ? 'You have not recorded any stock receipts yet.' : 'No stock receipts found for this store.')) ?></p>
                <?php if ($canReceive && $search === '' && $page === 1): ?>
                    <a class="btn btn-primary" href="stock_receiving.php">Record bought stock</a>
                <?php endif; ?>
            </div>
        <?php elseif ($entry === null): ?>
            <div class="u-table-scroll history-table-panel receiving-history-container" tabindex="0" role="region" aria-label="Receiving History entries">
                <table class="history-list-table">
                    <thead>
                        <tr>
                            <th scope="col">Entry</th>
                            <th scope="col">Date &amp; Time</th>
                            <th scope="col">Store</th>
                            <th scope="col">Receipt</th>
                            <th scope="col">Recorded by</th>
                            <th scope="col" class="history-quantity">Products</th>
                            <th scope="col" class="history-quantity">Receive</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($entries as $record): ?>
                            <tr id="entry_<?= $record['entry_key'] ?>">
                                <th scope="row"><a class="history-entry-link" href="<?= htmlspecialchars($url(['entry' => $record['entry_key']])) ?>">Entry #<?= $record['group_id'] ?></a></th>
                                <td><?= htmlspecialchars($record['received_at']) ?></td>
                                <td><?= htmlspecialchars($record['supplier'] ?: 'Not supplied') ?></td>
                                <td><?= htmlspecialchars($record['invoice_number'] ?: 'Not supplied') ?></td>
                                <td><?= htmlspecialchars($record['recorded_by']) ?></td>
                                <td class="history-quantity"><?= count(array_unique(array_column($record['lines'], 'product_id'))) ?></td>
                                <td class="history-quantity"><?= $record['total_accepted_qty'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <dl class="history-entry-summary history-section">
                <div><dt>Date &amp; Time</dt><dd><?= htmlspecialchars($entry['received_at']) ?></dd></div>
                <div><dt>Store</dt><dd><?= htmlspecialchars($entry['supplier'] ?: 'Not supplied') ?></dd></div>
                <div><dt>Receipt</dt><dd><?= htmlspecialchars($entry['invoice_number'] ?: 'Not supplied') ?></dd></div>
                <div><dt>Recorded by</dt><dd><?= htmlspecialchars($entry['recorded_by']) ?></dd></div>
                <div>
                    <dt><?= $entry['total_cost'] !== null && $entry['has_unknown_cost'] ? 'Known cost' : 'Purchase total' ?></dt>
                    <dd><?= $entry['total_cost'] !== null ? '₱' . number_format($entry['total_cost'], 2) : 'Not recorded' ?></dd>
                </div>
                <?php if ($entry['notes']): ?>
                    <div class="history-notes"><dt>Entry notes</dt><dd><?= htmlspecialchars($entry['notes']) ?></dd></div>
                <?php endif; ?>
            </dl>
            <div class="u-table-scroll history-table-panel" tabindex="0" role="region" aria-label="Items in entry #<?= $entry['group_id'] ?>">
                <table class="history-table">
                    <thead>
                        <tr>
                            <?php foreach ($itemHeaders as $key => $label): ?>
                                <th scope="col"<?= $sort === $key ? ' aria-sort="' . ($direction === 'asc' ? 'ascending' : 'descending') . '"' : '' ?>>
                                    <?php if (in_array($key, $sortColumns, true)): ?>
                                        <?php $nextDirection = $sort === $key && $direction === 'asc' ? 'desc' : 'asc'; ?>
                                        <?php if ($key === 'expiration_date'): ?>Batch / <?php endif; ?>
                                        <a class="history-sort-link" href="<?= htmlspecialchars($detailUrl(['sort' => $key, 'direction' => $nextDirection])) ?>"><?= $key === 'expiration_date' ? 'Expiry' : htmlspecialchars($label) ?></a>
                                    <?php else: ?>
                                        <?= htmlspecialchars($label) ?>
                                    <?php endif; ?>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($itemRows as $line): ?>
                            <tr>
                                <td>
                                    <?= htmlspecialchars($line['product_name']) ?>
                                    <?php if (!empty($line['pullout_id'])): ?>
                                        <br><span class="badge badge-info">Replacement (Pullout #<?= (int)$line['pullout_id'] ?>)</span>
                                    <?php endif; ?>
                                </td>
                                <td><code><?= htmlspecialchars($line['sku']) ?></code></td>
                                <td>
                                    <?= $line['accepted_qty'] ?> <?= $line['accepted_qty'] === 1 ? 'unit' : 'units' ?>
                                    <?php if (!empty($line['pack_size_used']) && $line['pack_size_used'] > 1): ?>
                                        <br><small class="history-quantity-note"><?= (int)$line['accepted_packs'] ?> <?= $line['accepted_packs'] === 1 ? 'pack' : 'packs' ?> × <?= (int)$line['pack_size_used'] ?><?php if ($line['accepted_loose'] > 0): ?> + <?= (int)$line['accepted_loose'] ?> loose<?php endif; ?></small>
                                    <?php elseif ($line['received_packages'] > 0 && $line['units_per_package_used'] > 1): ?>
                                        <?php
                                        $acceptedPackages = intdiv($line['accepted_qty'], $line['units_per_package_used']);
                                        $looseUnits = $line['accepted_qty'] % $line['units_per_package_used'];
                                        ?>
                                        <br><small class="history-quantity-note"><?= $acceptedPackages ?> <?= $acceptedPackages === 1 ? 'package' : 'packages' ?> × <?= $line['units_per_package_used'] ?><?php if ($looseUnits > 0): ?> + <?= $looseUnits ?> loose<?php endif; ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($line['cost_price'] !== null): ?>
                                        <?= $line['rounding_difference'] ? 'Approx. ' : '' ?>₱<?= number_format($line['cost_price'], 2) ?> / base unit<br>
                                    <?php endif; ?>
                                    <?php if ($line['total_cost'] !== null): ?>
                                        <strong>Batch total</strong> ₱<?= number_format($line['total_cost'], 2) ?>
                                    <?php else: ?>
                                        <span class="cost-unknown">Not recorded</span>
                                    <?php endif; ?>
                                    <?php if ($line['entered_cost'] !== null && $line['cost_basis'] !== 'units'): ?>
                                        <br><small>Entered ₱<?= number_format($line['entered_cost'], 2) ?> per <?= $line['cost_basis'] === 'packs' ? 'Sales Pack' : 'receiving package' ?>
                                            (<?= $line['cost_units_used'] ?> base units)</small>
                                    <?php elseif (!empty($line['cost_source']) && $line['cost_source'] !== 'unknown'): ?>
                                        <br><small><?= $line['cost_source'] === 'product' ? 'Saved product cost' : 'Entered for this batch' ?></small>
                                    <?php endif; ?>
                                    <?php if ($firstProductLines[$line['product_id']] === $line['receiving_id']): ?>
                                        <?php $productCost = $entry['product_costs'][$line['product_id']]; ?>
                                        <?php if ($batchCounts[$line['product_id']] > 1 && $productCost['total_cost'] !== null): ?>
                                            <br><small><?= $productCost['has_unknown_cost'] ? 'Product known cost' : 'Product total' ?> ₱<?= number_format($productCost['total_cost'], 2) ?></small>
                                        <?php endif; ?>
                                        <?php if ($line['current_product_cost'] !== null): ?>
                                            <br><small>Current product cost: ₱<?= number_format($line['current_product_cost'], 2) ?></small>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= htmlspecialchars($line['batch_number'] ?: 'Not recorded') ?><br>
                                    <small><?= htmlspecialchars($line['expiration_date'] ?: 'Not recorded') ?></small>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php
            $rows = $itemRows; $page = $itemPage; $pageSize = $itemPageSize;
            $totalRows = $itemCount; $totalPages = $itemLastPage; $hasNext = $page < $totalPages;
            $paginationFilters = array_diff_key($detailFilters + $historyFilters, array_flip(['item_page', 'item_length']));
            $paginationLengthKey = 'item_length'; $paginationLabel = 'Receiving item pages';
            $url = static fn(array $params): string => $detailUrl(['item_page' => $params['page']]);
            require __DIR__ . '/../../../backend/app/Views/table_pagination.php';
            ?>
        <?php endif; ?>
        <?php if (!$error && $entryKey === null): ?>
            <?php
            $rows = $entries;
            $paginationFilters = ['q' => $search];
            $paginationLabel = 'Receiving history pages';
            require __DIR__ . '/../../../backend/app/Views/table_pagination.php';
            ?>
        <?php endif; ?>
    </main>
</div>

</body>
</html>
