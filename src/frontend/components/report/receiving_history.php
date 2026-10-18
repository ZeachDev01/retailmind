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
$hasNext = false;
try {
    if (preg_match('/\A.{0,200}\z/us', $search) !== 1) {
        throw new InvalidArgumentException('Keep your search to 200 characters.');
    }
    $entries = $receivingService->getGroupedReceivingHistory($historyUserId, $page, $search);
    $hasNext = count($entries) > 20;
    $entries = array_slice($entries, 0, 20);
} catch (InvalidArgumentException $e) {
    $error = $e->getMessage();
} catch (Throwable $e) {
    error_log('Receiving history could not be loaded: ' . $e);
    $error = 'Failed to load receiving history. Please refresh or try again.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head><?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receiving History</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/reports.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/reports.css')) ?>">
</head>
<body class="receiving-history-page">
<a class="history-skip-link" href="#main-content">Skip to receiving history</a>
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content" id="main-content" tabindex="-1">
        <div class="topbar">
            <h1>Receiving History</h1>
        </div>
        <p class="muted"><?= $canReceive ? 'My Receipts' : 'Store Receipts (Read-only Oversight)' ?></p>
        <nav class="u-flex-wrap history-navigation" aria-label="Stock replenishment">
            <?php if ($canReceive): ?>
                <a class="btn btn-primary" href="stock_receiving.php">Record bought stock</a>
            <?php endif; ?>
            <a class="btn btn-secondary" href="../inventory_management/inventory_overview.php">Check stock</a>
            <a class="btn btn-secondary" href="../inventory_management/replenishment_queue.php">Review recommendations</a>
        </nav>

        <form class="history-controls" method="get" role="search" aria-label="Receiving history">
            <div class="form-group">
                <label for="history_search">Search receiving history</label>
                <input type="search" id="history_search" name="q" value="<?= htmlspecialchars($search) ?>" autocomplete="off" maxlength="200" placeholder="Product, SKU, store, receipt, or staff">
            </div>
            <button type="submit" class="btn">Search</button>
            <?php if ($search !== ''): ?>
                <a class="btn btn-secondary" href="receiving_history.php">Clear search</a>
            <?php endif; ?>
        </form>

        <?php if ($error): ?>
            <div class="message error history-section" role="alert">
                <p><?= htmlspecialchars($error) ?></p>
            </div>
        <?php elseif (empty($entries)): ?>
            <div class="empty-state history-section" role="status">
                <h2><?= $search !== '' ? 'No matching receipts' : ($page > 1 ? 'No more receipts' : 'No receiving records found') ?></h2>
                <p><?= $search !== '' ? 'Try a different product, store, receipt, or staff name.' : ($page > 1 ? 'Return to the previous page to see earlier results.' : ($canReceive ? 'You have not recorded any stock receipts yet.' : 'No stock receipts found for this store.')) ?></p>
                <?php if ($canReceive && $search === '' && $page === 1): ?>
                    <a class="btn btn-primary" href="stock_receiving.php">Record bought stock</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <p class="history-results" role="status">Page <?= $page ?> · <?= count($entries) ?> receipt<?= count($entries) === 1 ? '' : 's' ?><?= $search !== '' ? ' matching “' . htmlspecialchars($search) . '”' : '' ?></p>

            <div class="history-section receiving-history-container" aria-label="Receiving History entries">
                <?php foreach ($entries as $index => $entry): ?>
                    <details class="receiving-entry-card" id="entry_<?= $entry['entry_key'] ?>" open>
                        <summary class="receiving-entry-header">
                            <div class="receiving-entry-meta">
                                <strong class="entry-id">Entry #<?= $entry['group_id'] ?></strong>
                                <span class="entry-store"><strong>Store:</strong> <?= htmlspecialchars($entry['supplier'] ?: 'Not supplied') ?></span>
                                <span class="entry-reference"><strong>Receipt:</strong> <?= htmlspecialchars($entry['invoice_number'] ?: 'Not supplied') ?></span>
                                <span class="entry-staff"><strong>Recorded by:</strong> <?= htmlspecialchars($entry['recorded_by']) ?></span>
                                <span class="entry-time"><strong>Date & Time:</strong> <?= htmlspecialchars($entry['received_at']) ?></span>
                                <span class="badge badge-info"><?= count($entry['lines']) ?> product(s) · <?= $entry['total_accepted_qty'] ?> accepted units</span>
                                <?php if ($entry['total_damaged_qty'] > 0): ?>
                                    <span class="badge badge-warning"><?= $entry['total_damaged_qty'] ?> damaged units</span>
                                <?php endif; ?>
                            </div>
                            <button type="button" class="btn btn-secondary inspect-btn" aria-label="Hide details for entry #<?= $entry['group_id'] ?>" aria-expanded="true" aria-controls="entry_body_<?= $entry['entry_key'] ?>">Hide details</button>
                        </summary>
                        <div class="receiving-entry-body" id="entry_body_<?= $entry['entry_key'] ?>">
                            <?php if ($entry['notes']): ?>
                                <p class="history-entry-notes"><strong>Entry notes:</strong> <?= htmlspecialchars($entry['notes']) ?></p>
                            <?php endif; ?>
                            <div class="u-table-scroll" tabindex="0" role="region" aria-label="Products in entry #<?= $entry['group_id'] ?>">
                                <table class="history-table">
                                    <thead>
                                        <tr>
                                            <th>Product</th>
                                            <th>SKU</th>
                                            <th>Delivered</th>
                                            <th>Accepted</th>
                                            <th>Damaged</th>
                                            <th>Quantity Mode & Conversion</th>
                                            <th>Cost per base unit (PHP)</th>
                                            <th>Batch / Expiry</th>
                                            <th>Discrepancy / Notes</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($entry['lines'] as $line): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($line['product_name']) ?></td>
                                                <td><code><?= htmlspecialchars($line['sku']) ?></code></td>
                                                <td><?= $line['received_qty'] ?></td>
                                                <td><?= $line['accepted_qty'] ?></td>
                                                <td><?= $line['damaged_qty'] ?></td>
                                                <td>
                                                    <?php if ($line['received_packages'] > 0): ?>
                                                        Packages / cases (<?= $line['received_packages'] ?> package(s) × <?= $line['units_per_package_used'] ?> = <?= $line['received_qty'] ?> units)
                                                    <?php else: ?>
                                                        Base units (<?= $line['received_qty'] ?> unit(s))
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($line['cost_price'] !== null): ?>
                                                        <?= number_format((float)$line['cost_price'], 2) ?>
                                                    <?php else: ?>
                                                        <span class="cost-unknown">Unknown</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?= htmlspecialchars($line['batch_number'] ?: '-') ?><br>
                                                    <small><?= htmlspecialchars($line['expiration_date'] ?: '-') ?></small>
                                                </td>
                                                <td>
                                                    <?php if (!empty($line['pullout_id'])): ?>
                                                        <span class="badge badge-info">Replacement (Pullout #<?= (int)$line['pullout_id'] ?>)</span><br>
                                                    <?php endif; ?>
                                                    <?= htmlspecialchars(implode(' · ', array_filter([$line['discrepancy_notes'], $line['notes']]))) ?: '-' ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if (!$error && ($page > 1 || $hasNext)): ?>
            <nav class="history-pagination" aria-label="Receiving history pages">
                <?php if ($page > 1): ?>
                    <a class="btn btn-secondary" rel="prev" href="?<?= htmlspecialchars(http_build_query(['q' => $search, 'page' => $page - 1])) ?>">Previous page</a>
                <?php endif; ?>
                <?php if ($hasNext): ?>
                    <a class="btn btn-secondary" rel="next" href="?<?= htmlspecialchars(http_build_query(['q' => $search, 'page' => $page + 1])) ?>">Next page</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </main>
</div>

<script>
document.querySelectorAll('details.receiving-entry-card').forEach(details => {
    const btn = details.querySelector('.inspect-btn');
    const sync = () => {
        if (btn) {
            btn.setAttribute('aria-expanded', String(details.open));
            btn.textContent = details.open ? 'Hide details' : 'Inspect entry';
            btn.setAttribute('aria-label', btn.textContent + ' for ' + details.querySelector('.entry-id').textContent);
        }
    };
    details.addEventListener('toggle', sync);
    if (btn) {
        btn.addEventListener('click', event => {
            event.preventDefault();
            event.stopPropagation();
            details.open = !details.open;
            sync();
        });
    }
});
</script>
</body>
</html>
