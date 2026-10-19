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
    // Each existing 20-receipt service batch supplies two 10-entry display pages.
    $entries = $receivingService->getGroupedReceivingHistory($historyUserId, intdiv($page - 1, 2) + 1, $search);
    $displayOffset = (($page - 1) % 2) * 10;
    $hasNext = count($entries) > $displayOffset + 10;
    $entries = array_slice($entries, $displayOffset, 10);
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
    <style>
        /* Page-scoped Tailwind utilities, compiled with preflight disabled. */
        /* tailwind:start */
        .rh-sr-only{position:absolute!important;width:1px!important;height:1px!important;padding:0!important;margin:-1px!important;overflow:hidden!important;clip:rect(0,0,0,0)!important;white-space:nowrap!important;border-width:0!important}.rh-pointer-events-none{pointer-events:none!important}.rh-absolute{position:absolute!important}.rh-relative{position:relative!important}.rh-bottom-0{bottom:0!important}.rh-left-3{left:.75rem!important}.rh-top-0{top:0!important}.rh-top-1{top:.25rem!important}.rh-m-0{margin:0!important}.rh-my-auto{margin-top:auto!important;margin-bottom:auto!important}.rh-mb-0{margin-bottom:0!important}.rh-mb-1{margin-bottom:.25rem!important}.rh-mb-5{margin-bottom:1.25rem!important}.rh-mb-6{margin-bottom:1.5rem!important}.rh-mt-1{margin-top:.25rem!important}.rh-block{display:block!important}.rh-flex{display:flex!important}.rh-grid{display:grid!important}.rh-h-4{height:1rem!important}.rh-h-5{height:1.25rem!important}.rh-min-h-11{min-height:2.75rem!important}.rh-w-4{width:1rem!important}.rh-w-5{width:1.25rem!important}.rh-w-full{width:100%!important}.rh-min-w-0{min-width:0!important}.rh-flex-1{flex:1 1 0%!important}.rh-flex-\[1_1_20rem\]{flex:1 1 20rem!important}.rh-shrink-0{flex-shrink:0!important}.-rh-translate-y-1{--tw-translate-y:-0.25rem!important;transform:translate(var(--tw-translate-x),var(--tw-translate-y)) rotate(var(--tw-rotate)) skewX(var(--tw-skew-x)) skewY(var(--tw-skew-y)) scaleX(var(--tw-scale-x)) scaleY(var(--tw-scale-y))!important}.rh-grid-cols-1{grid-template-columns:repeat(1,minmax(0,1fr))!important}.rh-flex-col{flex-direction:column!important}.rh-flex-wrap{flex-wrap:wrap!important}.rh-items-start{align-items:flex-start!important}.rh-items-center{align-items:center!important}.rh-items-stretch{align-items:stretch!important}.rh-gap-2{gap:.5rem!important}.rh-gap-3{gap:.75rem!important}.rh-gap-4{gap:1rem!important}.rh-space-y-3>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0!important;margin-top:calc(.75rem*(1 - var(--tw-space-y-reverse)))!important;margin-bottom:calc(.75rem*var(--tw-space-y-reverse))!important}.rh-rounded-\[10px\]{border-radius:10px!important}.rh-rounded-\[14px\]{border-radius:14px!important}.rh-border{border-width:1px!important}.rh-border-solid{border-style:solid!important}.rh-border-\[var\(--border\)\]{border-color:var(--border)!important}.rh-border-\[var\(--muted\)\]{border-color:var(--muted)!important}.rh-bg-\[var\(--theme-surface\,\#fff\)\]{background-color:var(--theme-surface,#fff)!important}.rh-p-3{padding:.75rem!important}.rh-p-4{padding:1rem!important}.rh-py-3{padding-top:.75rem!important;padding-bottom:.75rem!important}.rh-pl-10{padding-left:2.5rem!important}.rh-pr-3{padding-right:.75rem!important}.rh-text-2xl{font-size:1.5rem!important;line-height:2rem!important}.rh-text-base{font-size:1rem!important;line-height:1.5rem!important}.rh-text-sm{font-size:.875rem!important;line-height:1.25rem!important}.rh-text-xs{font-size:.75rem!important;line-height:1rem!important}.rh-font-bold{font-weight:700!important}.rh-font-medium{font-weight:500!important}.rh-font-semibold{font-weight:600!important}.rh-tabular-nums{--tw-numeric-spacing:tabular-nums!important;font-variant-numeric:var(--tw-ordinal) var(--tw-slashed-zero) var(--tw-numeric-figure) var(--tw-numeric-spacing) var(--tw-numeric-fraction)!important}.rh-text-\[var\(--muted\)\]{color:var(--muted)!important}.rh-text-\[var\(--text\)\]{color:var(--text)!important}@media (min-width:640px){.sm\:rh-col-span-2{grid-column:span 2/span 2!important}.sm\:rh-grid-cols-2{grid-template-columns:repeat(2,minmax(0,1fr))!important}.sm\:rh-flex-row{flex-direction:row!important}.sm\:rh-flex-wrap{flex-wrap:wrap!important}}@media (min-width:1024px){.lg\:rh-flex-row{flex-direction:row!important}.lg\:rh-items-center{align-items:center!important}}@media (min-width:1280px){.xl\:rh-col-span-4{grid-column:span 4/span 4!important}.xl\:rh-grid-cols-4{grid-template-columns:repeat(4,minmax(0,1fr))!important}.xl\:rh-flex-row{flex-direction:row!important}.xl\:rh-items-center{align-items:center!important}.xl\:rh-justify-between{justify-content:space-between!important}}
        /* tailwind:end */
        .receiving-history-page .history-chevron { transition: transform 220ms ease-out; }
        .receiving-history-page summary[aria-expanded="true"] .history-chevron { transform: rotate(180deg); }
        .receiving-history-page .history-table th:nth-child(n+3):nth-child(-n+5),
        .receiving-history-page .history-table th:nth-child(7) { text-align: right; }
        .receiving-history-page .history-table tbody tr:hover { background: var(--theme-surface-soft, #f8fafc); }
        @media (prefers-reduced-motion: reduce) {
            .receiving-history-page .history-chevron { transition: none; }
        }
    </style>
</head>
<body class="receiving-history-page">
<a class="history-skip-link" href="#main-content">Skip to receiving history</a>
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content" id="main-content" tabindex="-1">
        <header class="rh-mb-6 rh-flex rh-flex-col rh-gap-4 xl:rh-flex-row xl:rh-items-center xl:rh-justify-between">
            <div>
                <h1 class="rh-m-0 rh-text-2xl rh-font-bold">Receiving History</h1>
                <p class="muted rh-mb-0 rh-mt-1 rh-text-sm"><?= $canReceive ? 'My Receipts' : 'Store Receipts (Read-only Oversight)' ?></p>
            </div>
        <nav class="rh-flex rh-flex-col rh-gap-2 sm:rh-flex-row sm:rh-flex-wrap" aria-label="Stock replenishment">
            <?php if ($canReceive): ?>
                <a class="btn btn-primary" href="stock_receiving.php">Record bought stock</a>
            <?php endif; ?>
            <a class="btn btn-secondary" href="../inventory_management/inventory_overview.php">Check stock</a>
            <a class="btn btn-secondary" href="../inventory_management/replenishment_queue.php">Review recommendations</a>
        </nav>
        </header>

        <form class="rh-mb-5 rh-flex rh-flex-wrap rh-items-center rh-gap-2 rh-rounded-[14px] rh-border rh-border-solid rh-border-[var(--border)] rh-bg-[var(--theme-surface,#fff)] rh-p-3" method="get" role="search" aria-label="Receiving history">
            <div class="rh-relative rh-min-w-0 rh-flex-[1_1_20rem]">
                <label class="rh-sr-only" for="history_search">Search receiving history</label>
                <svg class="rh-pointer-events-none rh-absolute rh-bottom-0 rh-left-3 rh-top-0 rh-my-auto rh-h-5 rh-w-5 rh-text-[var(--muted)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 4.5 4.5"/></svg>
                <input class="rh-m-0 rh-min-h-11 rh-w-full rh-rounded-[10px] rh-border rh-border-solid rh-border-[var(--muted)] rh-bg-[var(--theme-surface,#fff)] rh-py-3 rh-pl-10 rh-pr-3 rh-text-base rh-text-[var(--text)]" type="search" id="history_search" name="q" value="<?= htmlspecialchars($search) ?>" autocomplete="off" maxlength="200" placeholder="Product, SKU, store, receipt, or staff">
            </div>
            <button type="submit" class="btn btn-primary">Search</button>
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

            <div class="receiving-history-container rh-min-w-0 rh-space-y-3" aria-label="Receiving History entries">
                <?php foreach ($entries as $index => $entry): ?>
                    <details class="receiving-entry-card rh-mb-0 rh-rounded-[14px]" id="entry_<?= $entry['entry_key'] ?>">
                        <summary class="receiving-entry-header rh-flex rh-flex-col rh-items-stretch rh-gap-3 rh-bg-[var(--theme-surface,#fff)] rh-p-4 lg:rh-flex-row lg:rh-items-center" aria-controls="entry_body_<?= $entry['entry_key'] ?>" aria-expanded="false">
                            <div class="receiving-entry-meta rh-grid rh-flex-1 rh-grid-cols-1 rh-items-start rh-gap-3 sm:rh-grid-cols-2 xl:rh-grid-cols-4">
                                <div class="rh-flex rh-flex-wrap rh-items-center rh-gap-2 sm:rh-col-span-2 xl:rh-col-span-4">
                                <strong class="entry-id rh-font-semibold">Entry #<?= $entry['group_id'] ?></strong>
                                <span class="badge badge-info rh-text-xs rh-font-medium"><?= count($entry['lines']) ?> product(s) · <?= $entry['total_accepted_qty'] ?> accepted units</span>
                                <?php if ($entry['total_damaged_qty'] > 0): ?>
                                    <span class="badge badge-warning"><?= $entry['total_damaged_qty'] ?> damaged units</span>
                                <?php endif; ?>
                                </div>
                                <span class="entry-store rh-min-w-0 rh-text-sm"><strong class="rh-mb-1 rh-block rh-text-xs rh-font-medium rh-text-[var(--muted)]">Store</strong> <?= htmlspecialchars($entry['supplier'] ?: 'Not supplied') ?></span>
                                <span class="entry-reference rh-min-w-0 rh-text-sm"><strong class="rh-mb-1 rh-block rh-text-xs rh-font-medium rh-text-[var(--muted)]">Receipt</strong> <?= htmlspecialchars($entry['invoice_number'] ?: 'Not supplied') ?></span>
                                <span class="entry-staff rh-min-w-0 rh-text-sm"><strong class="rh-mb-1 rh-block rh-text-xs rh-font-medium rh-text-[var(--muted)]">Recorded by</strong> <?= htmlspecialchars($entry['recorded_by']) ?></span>
                                <span class="entry-time rh-min-w-0 rh-text-sm rh-tabular-nums"><strong class="rh-mb-1 rh-block rh-text-xs rh-font-medium rh-text-[var(--muted)]">Date &amp; Time</strong> <?= htmlspecialchars($entry['received_at']) ?></span>
                            </div>
                            <span class="btn btn-secondary inspect-btn rh-shrink-0 rh-gap-2"><span class="history-toggle-label">View Details</span><svg class="history-chevron rh-h-4 rh-w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></span>
                        </summary>
                        <div class="receiving-entry-body" id="entry_body_<?= $entry['entry_key'] ?>">
                            <?php if ($entry['notes']): ?>
                                <p class="history-entry-notes"><strong>Entry notes:</strong> <?= htmlspecialchars($entry['notes']) ?></p>
                            <?php endif; ?>
                            <div class="u-table-scroll" tabindex="0" role="region" aria-label="Products in entry #<?= $entry['group_id'] ?>">
                                <table class="history-table rh-tabular-nums">
                                    <thead>
                                        <tr>
                                            <th scope="col">Product</th>
                                            <th scope="col">SKU</th>
                                            <th scope="col">Delivered</th>
                                            <th scope="col">Accepted</th>
                                            <th scope="col">Damaged</th>
                                            <th scope="col">Quantity Mode &amp; Conversion</th>
                                            <th scope="col">Cost per base unit (PHP)</th>
                                            <th scope="col">Batch / Expiry</th>
                                            <th scope="col">Discrepancy / Notes</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($entry['lines'] as $line): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($line['product_name']) ?></td>
                                                <td><code><?= htmlspecialchars($line['sku']) ?></code></td>
                                                <td>
                                                    <?= $line['received_qty'] ?>
                                                    <?php if (!empty($line['pack_size_used']) && $line['pack_size_used'] > 1): ?>
                                                        <br><small class="rh-text-xs rh-text-[var(--muted)]"><?= (int)$line['received_packs'] ?> pack(s), <?= (int)$line['received_loose'] ?> loose</small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?= $line['accepted_qty'] ?>
                                                    <?php if (!empty($line['pack_size_used']) && $line['pack_size_used'] > 1): ?>
                                                        <br><small class="rh-text-xs rh-text-[var(--muted)]"><?= (int)$line['accepted_packs'] ?> pack(s), <?= (int)$line['accepted_loose'] ?> loose</small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?= $line['damaged_qty'] ?>
                                                    <?php if (!empty($line['pack_size_used']) && $line['pack_size_used'] > 1 && ((int)$line['damaged_packs'] > 0 || (int)$line['damaged_loose'] > 0)): ?>
                                                        <br><small class="rh-text-xs rh-text-[var(--muted)]"><?= (int)$line['damaged_packs'] ?> pack(s), <?= (int)$line['damaged_loose'] ?> loose</small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (!empty($line['pack_size_used']) && $line['pack_size_used'] > 1): ?>
                                                        Sales packs (<?= (int)$line['received_packs'] ?> pack(s) × <?= (int)$line['pack_size_used'] ?> + <?= (int)$line['received_loose'] ?> loose = <?= $line['received_qty'] ?> units)
                                                    <?php elseif ($line['received_packages'] > 0): ?>
                                                        Packages / cases (<?= $line['received_packages'] ?> package(s) × <?= $line['units_per_package_used'] ?> = <?= $line['received_qty'] ?> units)
                                                    <?php else: ?>
                                                        Base units (<?= $line['received_qty'] ?> unit(s))
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($line['cost_price'] !== null): ?>
                                                        ₱<?= number_format((float)$line['cost_price'], 2) ?>
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
                <span class="badge badge-info" aria-current="page">Page <?= $page ?> · 10 per page</span>
                <?php if ($hasNext): ?>
                    <a class="btn btn-secondary" rel="next" href="?<?= htmlspecialchars(http_build_query(['q' => $search, 'page' => $page + 1])) ?>">Next page</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </main>
</div>

<script>
document.querySelectorAll('details.receiving-entry-card').forEach(details => {
    const summary = details.querySelector('summary');
    const label = details.querySelector('.history-toggle-label');
    let animation;
    let expanded = details.open;
    const sync = () => {
        summary.setAttribute('aria-expanded', String(expanded));
        label.textContent = expanded ? 'Hide Details' : 'View Details';
    };
    summary.addEventListener('click', event => {
        event.preventDefault();
        const start = details.getBoundingClientRect().height;
        if (animation) animation.cancel();
        expanded = !expanded;
        sync();
        if (matchMedia('(prefers-reduced-motion: reduce)').matches) {
            details.open = expanded;
            return;
        }
        details.open = true;
        const end = expanded ? details.getBoundingClientRect().height : summary.getBoundingClientRect().height + 2;
        animation = details.animate({height: [start + 'px', end + 'px']}, {duration: 220, easing: 'cubic-bezier(0.16, 1, 0.3, 1)'});
        animation.onfinish = () => {
            details.open = expanded;
            animation = null;
        };
    });
    details.addEventListener('toggle', () => {
        if (!animation) {
            expanded = details.open;
            sync();
        }
    });
});
</script>
</body>
</html>
