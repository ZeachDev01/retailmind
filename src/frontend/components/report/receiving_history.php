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
try {
    $entries = $receivingService->getGroupedReceivingHistory($historyUserId);
} catch (Throwable $e) {
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
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content">
        <div class="topbar">
            <h1>Receiving History</h1>
        </div>
        <p class="text-muted"><?= $canReceive ? 'My Receipts' : 'Store Receipts (Read-only Oversight)' ?></p>
        <nav class="u-flex-wrap mb-3" aria-label="Stock replenishment">
            <?php if ($canReceive): ?>
                <a class="btn btn-primary" href="stock_receiving.php">Record bought stock</a>
            <?php endif; ?>
            <a class="btn btn-secondary" href="../inventory_management/inventory_overview.php">Check stock</a>
            <a class="btn btn-secondary" href="../inventory_management/replenishment_queue.php">Review recommendations</a>
        </nav>

        <?php if ($error): ?>
            <div class="message error history-section" role="alert">
                <p><?= htmlspecialchars($error) ?></p>
            </div>
        <?php elseif (empty($entries)): ?>
            <div class="empty-state history-section" role="status">
                <h3>No receiving records found</h3>
                <p><?= $canReceive ? 'You have not recorded any stock receipts yet.' : 'No stock receipts found for this store.' ?></p>
                <?php if ($canReceive): ?>
                    <a class="btn btn-primary" href="stock_receiving.php">Record bought stock</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="history-controls mb-3">
                <label for="history_search" class="visually-hidden">Search receiving history</label>
                <input type="search" id="history_search" class="form-control" autocomplete="off" placeholder="Search by product, store, reference, or staff..." style="max-width: 420px;">
            </div>

            <div class="history-section receiving-history-container" aria-label="Receiving History entries">
                <?php foreach ($entries as $index => $entry): ?>
                    <details class="receiving-entry-card" id="entry_<?= $entry['group_id'] ?>" open>
                        <summary class="receiving-entry-header">
                            <div class="receiving-entry-meta">
                                <span class="entry-id font-weight-bold">Entry #<?= $entry['group_id'] ?></span>
                                <span class="entry-store"><strong>Store:</strong> <?= htmlspecialchars($entry['supplier'] ?: 'Not supplied') ?></span>
                                <span class="entry-reference"><strong>Receipt:</strong> <?= htmlspecialchars($entry['invoice_number'] ?: 'Not supplied') ?></span>
                                <span class="entry-staff"><strong>Recorded by:</strong> <?= htmlspecialchars($entry['recorded_by']) ?></span>
                                <span class="entry-time"><strong>Date & Time:</strong> <?= htmlspecialchars($entry['received_at']) ?></span>
                                <span class="badge badge-info"><?= count($entry['lines']) ?> product(s) · <?= $entry['total_accepted_qty'] ?> accepted units</span>
                                <?php if ($entry['total_damaged_qty'] > 0): ?>
                                    <span class="badge badge-warning"><?= $entry['total_damaged_qty'] ?> damaged units</span>
                                <?php endif; ?>
                            </div>
                            <button type="button" class="btn btn-secondary btn-sm inspect-btn" aria-expanded="true" aria-controls="entry_body_<?= $entry['group_id'] ?>">Inspect entry</button>
                        </summary>
                        <div class="receiving-entry-body" id="entry_body_<?= $entry['group_id'] ?>">
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
                                            <th>Cost per base unit</th>
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
    </main>
</div>

<script>
document.querySelectorAll('details.receiving-entry-card').forEach(details => {
    const btn = details.querySelector('.inspect-btn');
    const sync = () => {
        if (btn) {
            btn.setAttribute('aria-expanded', String(details.open));
            btn.textContent = details.open ? 'Hide details' : 'Inspect entry';
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
const searchInput = document.getElementById('history_search');
if (searchInput) {
    searchInput.addEventListener('input', () => {
        const query = searchInput.value.trim().toLowerCase();
        document.querySelectorAll('.receiving-entry-card').forEach(card => {
            const text = card.textContent.toLowerCase();
            card.hidden = query !== '' && !text.includes(query);
        });
    });
}
</script>
</body>
</html>
