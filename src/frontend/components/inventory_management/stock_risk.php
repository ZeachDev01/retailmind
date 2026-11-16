<?php
$riskRows = $riskRows ?? [];
$riskError = $riskError ?? '';
$movementPeriod = $movementPeriod ?? \App\Inventory\StockMovementReader::period();
$criteria = [
    'fast' => ['green', 'lightning-charge', 'Sold on 10–30 days in the last 30 days.'],
    'slow' => ['orange', 'clock', 'Sold on 0–9 days in the last 30 days, with a sale in the last 90 days.'],
    'dead' => ['red', 'dash-circle', 'Stock on hand, but no sales in a full 90-day history.'],
    'limited' => ['slate', 'clock-history', 'Under 90 days of product history, or start date unknown.'],
    'stockout' => ['orange', 'box', 'Out of stock now or during the 90-day review. Fast/Slow and trend unavailable.'],
];
$movementLabels = ['fast' => 'Fast-Moving', 'slow' => 'Slow-Moving', 'dead' => 'Dead Stock', 'limited' => 'Limited history', 'stockout' => 'Stockout affected'];
$movementStore = ['store_name' => 'Shalom Store'];
if (isset($pdo) && $pdo instanceof PDO && function_exists('get_store_settings')) {
    try {
        $movementStore = get_store_settings($pdo);
    } catch (\Throwable $e) {
        // Optional letterhead details must not block stock review.
    }
}
?>
<div class="stock-risk" id="stock-risk">
    <?php if ($riskError !== ''): ?>
        <div class="message error"><span role="alert"><?= htmlspecialchars($riskError) ?></span></div>
    <?php else: ?>
    <div class="sr-records">
        <header class="sr-toolbar sr-heading">
            <div><h2>Stock Movement</h2><p class="sr-caption sr-period-label"><?= htmlspecialchars($movementPeriod['label']) ?> · Last 30 days</p></div>
            <button type="button" class="btn btn-primary" id="movement-report"><i class="bi bi-printer" aria-hidden="true"></i>Generate Report</button>
        </header>
        <div class="sr-tools">
            <label class="sr-search"><span class="sr-only">Search Stock Movement products</span><i class="bi bi-search" aria-hidden="true"></i><input type="search" id="risk-search" placeholder="Search products" aria-controls="risk-table-body" autocomplete="off"></label>
            <label><span class="sr-only">Stock</span><select id="movement-stock" aria-label="Stock"><option value="in-stock">With stock</option><option value="all">All products</option></select></label>
            <span class="sr-review-period sr-caption">90-day review <span class="info-tooltip"><button class="info-tooltip-trigger" type="button" aria-label="About the review period" aria-describedby="movement-period-help"><i class="bi bi-info-circle" aria-hidden="true"></i></button><span class="tooltip-box" id="movement-period-help" role="tooltip"><?= htmlspecialchars($movementPeriod['review_label']) ?>. Three consecutive 30-day periods ending yesterday in Philippine time. Today's sales are excluded.</span></span></span>
        </div>
        <p id="movement-report-status" class="sr-caption" role="status"></p>
        <label class="sr-mobile-sort" for="risk-sort-mobile">Sort products<select id="risk-sort-mobile" aria-label="Sort products"><option value="days">Selling days: low to high</option><option value="name">Product A–Z</option><option value="stock">Stock: high to low</option><option value="sales">Sold: low to high</option></select></label>
        <div class="sr-mobile-status">Status <?php $statusGuideId = 'movement-status-mobile-help'; include __DIR__ . '/stock_movement_status_help.php'; ?></div>
        <div class="sr-table-scroll" role="region" aria-label="Stock Movement comparison table" tabindex="0">
        <table class="sr-table" data-no-smart-table data-no-ghost-rows>
            <thead><tr>
                <th scope="col" aria-sort="none"><button type="button" data-risk-sort="name">Product<i class="bi bi-arrow-down-up" aria-hidden="true"></i></button></th>
                <th scope="col" class="sr-num" aria-sort="none"><button type="button" data-risk-sort="stock">Stock<i class="bi bi-arrow-down-up" aria-hidden="true"></i></button></th>
                <th scope="col" class="sr-num" aria-sort="none"><button type="button" data-risk-sort="sales">Sold<i class="bi bi-arrow-down-up" aria-hidden="true"></i></button></th>
                <th scope="col" class="sr-num" aria-sort="ascending"><button type="button" data-risk-sort="days">Selling days<i class="bi bi-arrow-up" aria-hidden="true"></i></button><span class="info-tooltip"><button class="info-tooltip-trigger" type="button" aria-label="About selling days" aria-describedby="movement-days-help"><i class="bi bi-info-circle" aria-hidden="true"></i></button><span class="tooltip-box" id="movement-days-help" role="tooltip">Distinct days with recorded sales in the latest 30 days. Multiple purchases on one day count as one day. Quantity sold is measured separately.</span></span></th>
                <th scope="col">Status <?php $statusGuideId = 'movement-status-help'; include __DIR__ . '/stock_movement_status_help.php'; ?></th><th scope="col">Trend</th><th scope="col" aria-label="Product details"></th>
            </tr></thead>
            <tbody id="risk-table-body">
            <?php foreach ($riskRows as $row):
                $id = (int)$row['product_id'];
                [$color, $icon, $criterion] = $criteria[$row['movement_kind']];
                $trendLabel = $row['trend'] === null ? 'Unavailable' : ($row['trend'] > 0 ? 'Nisaka' : ($row['trend'] < 0 ? 'Nihinay' : 'Pareho'));
                $trendIcon = $row['trend'] > 0 ? 'arrow-up-right' : ($row['trend'] < 0 ? 'arrow-down-right' : 'arrow-right');
            ?>
            <tr data-risk-row data-product-id="<?= $id ?>" data-name="<?= htmlspecialchars($row['product_name']) ?>" data-stock="<?= (int)$row['quantity_on_hand'] ?>" data-sales="<?= $row['recent_sales'] ?>" data-days="<?= $row['selling_days'] ?>" data-search="<?= htmlspecialchars(strtolower($row['product_name'] . ' ' . ($row['sku'] ?? '') . ' ' . ($row['category_name'] ?? ''))) ?>">
                <td><div class="sr-product"><button type="button" class="sr-product-link" data-risk-review="<?= $id ?>" aria-haspopup="dialog" aria-controls="movement-drawer" aria-expanded="false" aria-label="View details for <?= htmlspecialchars($row['product_name']) ?>"><?= htmlspecialchars($row['product_name']) ?></button><span><?= htmlspecialchars($row['sku'] ?? '') ?></span></div></td>
                <td class="sr-num" data-label="Stock"><?= number_format((int)$row['quantity_on_hand']) ?></td>
                <td class="sr-num" data-label="Sold"><?= number_format($row['recent_sales']) ?></td>
                <td class="sr-num" data-label="Selling days"><?= $row['selling_days'] ?><span class="sr-caption"> / 30</span></td>
                <td class="sr-status" data-label="Status"><span class="info-tooltip"><button type="button" class="info-tooltip-trigger sr-badge sr-<?= $color ?>" aria-label="<?= htmlspecialchars($row['movement_label']) ?> criteria for <?= htmlspecialchars($row['product_name']) ?>" aria-describedby="movement-criterion-<?= $id ?>"><i class="bi bi-<?= $icon ?>" aria-hidden="true"></i><?= htmlspecialchars($row['movement_label']) ?></button><span class="tooltip-box" id="movement-criterion-<?= $id ?>" role="tooltip"><?= htmlspecialchars($criterion) ?></span></span></td>
                <td data-label="Trend"><span class="sr-trend"><?php if ($row['trend'] !== null): ?><i class="bi bi-<?= $trendIcon ?>" aria-hidden="true"></i><?= $trendLabel ?><?php else: ?><span aria-label="Trend unavailable">—</span><?php endif; ?></span></td>
                <td class="sr-row-action"><button type="button" class="sr-review" data-risk-review="<?= $id ?>" aria-label="Open details for <?= htmlspecialchars($row['product_name']) ?>" aria-haspopup="dialog" aria-expanded="false" aria-controls="movement-drawer"><i class="bi bi-chevron-right" aria-hidden="true"></i></button></td>
            </tr>
            <?php endforeach; ?>
            <tr id="risk-empty" <?= $riskRows ? 'hidden' : '' ?>><td colspan="7"><div class="sr-empty"><strong>No matching products</strong><p>Try another search or stock filter.</p></div></td></tr>
            </tbody>
        </table>
        </div>
        <nav class="sr-pagination" aria-label="Stock Movement pagination"><span id="risk-page-summary" role="status"></span><div><span class="sr-units">Quantities in base units</span><button type="button" id="risk-previous" aria-label="Previous Stock Movement page"><i class="bi bi-chevron-left" aria-hidden="true"></i></button><span id="risk-page-status"></span><button type="button" id="risk-next" aria-label="Next Stock Movement page"><i class="bi bi-chevron-right" aria-hidden="true"></i></button></div></nav>
    </div>
    <template id="movement-report-letterhead">
        <header class="sr-report-letterhead">
            <div class="sr-report-brand"><strong><?= htmlspecialchars(trim((string)($movementStore['store_name'] ?? '')) ?: 'Shalom Store') ?></strong><span><?= htmlspecialchars(implode(' · ', array_filter(array_map('trim', [$movementStore['store_address'] ?? '', $movementStore['store_phone'] ?? '', $movementStore['store_email'] ?? ''])))) ?></span></div>
            <div class="sr-report-stamp"><span>Generated</span><strong></strong></div>
            <h1>Stock Movement Report</h1>
            <p class="sr-period-label"><?= htmlspecialchars($movementPeriod['label']) ?> · Last 30 days</p>
            <p class="sr-report-scope"></p>
            <?php if (trim((string)($_SESSION['display_name'] ?? '')) !== ''): ?><p class="sr-report-preparer">Prepared by <?= htmlspecialchars($_SESSION['display_name']) ?></p><?php endif; ?>
        </header>
    </template>
    <?php foreach ($riskRows as $row):
        $id = (int)$row['product_id'];
        [$color, $icon, $criterion] = $criteria[$row['movement_kind']];
        $created = substr((string)($row['created_at'] ?? ''), 0, 10);
    ?>
    <template id="risk-detail-<?= $id ?>"><article class="sr-detail">
        <header class="sr-detail-heading"><h3 id="movement-detail-title"><?= htmlspecialchars($row['product_name']) ?></h3><p class="sr-caption"><?= htmlspecialchars($row['sku'] ?? '') ?> · <?= htmlspecialchars($row['category_name'] ?? 'Uncategorized') ?></p></header>
        <div class="sr-detail-status"><span class="sr-badge sr-<?= $color ?>"><i class="bi bi-<?= $icon ?>" aria-hidden="true"></i><?= htmlspecialchars($row['movement_label']) ?></span></div>
        <div class="sr-stock-now"><span>Stock now</span><strong><?= number_format((int)$row['quantity_on_hand']) ?></strong></div>
        <section class="sr-detail-section"><h4>Sales across 90 days</h4><table class="sr-period-table"><thead><tr><th scope="col">Period</th><th scope="col" class="sr-num">Sold</th><th scope="col" class="sr-num">Selling days</th></tr></thead><tbody>
            <?php foreach ($movementPeriod['windows'] as $i => $window): $unavailable = $created === '' || $created >= $window['end']; ?>
            <tr <?= $i === 0 ? 'class="sr-recent"' : '' ?>><td><?= htmlspecialchars($window['label']) ?><?php if ($i === 0): ?><span class="sr-caption">Latest 30 days</span><?php endif; ?></td><td class="sr-num"><?= $unavailable && $row['period_sales'][$i] === 0 ? '—' : number_format($row['period_sales'][$i]) ?></td><td class="sr-num"><?= $unavailable && $row['period_days'][$i] === 0 ? '—' : $row['period_days'][$i] ?></td></tr>
            <?php endforeach; ?>
        </tbody></table>
        <?php if ($row['trend'] !== null): ?><p class="sr-trend-basis"><?= $row['selling_days'] ?> selling days now · <?= number_format($row['previous_average'], 1) ?> average in the previous two periods.</p><?php endif; ?>
        </section>
        <section class="sr-detail-section"><h4>Stock flow <span class="sr-caption">· Latest 30 days</span></h4><dl class="sr-flow">
            <?php foreach (['balance' => 'Opening (reconstructed)', 'received' => 'Received', 'sale_out' => 'Sales stock movement', 'returns' => 'Returns to stock', 'other' => 'Corrections / other', 'remaining' => 'Closing stock (yesterday)'] as $key => $label): ?>
            <div><dt><?= $label ?></dt><dd><?= number_format($row[$key]) ?></dd></div>
            <?php endforeach; ?>
        </dl></section>
        <p class="sr-history-note"><?= htmlspecialchars($row['history_note']) ?></p>
        <?php if (-$row['sale_out'] !== $row['recent_sales']): ?><p class="sr-history-note">Recorded sales and sales stock movements differ. Review the underlying records before relying on the balance.</p><?php endif; ?>
        <details class="sr-calculation"><summary>Calculation basis</summary><p><?= htmlspecialchars($criterion) ?></p><p class="sr-basis">Sales are recorded before refunds or reversals; returns to stock are separate. Corrections / other includes stock issues and supplier pullouts. Forecast-only imports are excluded. Quantities use base units, including pieces sold in packs.</p><p class="sr-basis">Opening + received + sales stock movement + returns + corrections / other = closing. Balances are reconstructed from current recorded stock; missing movements and unrecorded losses can affect accuracy. Stock now includes today's recorded changes.</p></details>
        <footer class="sr-caption sr-detail-footer">Through <?= htmlspecialchars((new DateTimeImmutable($movementPeriod['end']))->modify('-1 day')->format('M j, Y')) ?> · Philippine time</footer>
    </article></template>
    <?php endforeach; ?>
    <dialog id="movement-drawer" class="sr-drawer" aria-labelledby="movement-detail-title"><div class="sr-drawer-bar"><span class="sr-caption">Product details</span><button type="button" class="sr-review" id="movement-drawer-close" aria-label="Close product details" autofocus><i class="bi bi-x-lg" aria-hidden="true"></i></button></div><div id="movement-detail-body"></div></dialog>
    <?php endif; ?>
</div>
