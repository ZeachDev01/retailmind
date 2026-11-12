<?php
$riskRows = $riskRows ?? [];
$riskError = $riskError ?? '';
$riskTypes = [
    'all' => ['All products', 'grid', 'blue'],
    'expiry' => ['Expiry', 'calendar-event', 'orange'],
    'no-sales' => ['No sales', 'hourglass-split', 'purple'],
    'excess' => ['Possible excess', 'boxes', 'blue'],
    'limited' => ['Limited history', 'clock-history', 'slate'],
];
$riskCounts = array_fill_keys(array_keys($riskTypes), 0);
$riskCounts['all'] = count($riskRows);
foreach ($riskRows as $row) foreach ($row['flags'] as $flag) if (isset($riskCounts[$flag])) $riskCounts[$flag]++;
$categoryVisual = static function (string $category): array {
    $name = strtolower($category);
    foreach ([['/coffee|drink|beverage/', 'cup-hot', 'blue'], ['/food|bread|snack|rice|canned/', 'basket2', 'orange'], ['/clean|laundry|household/', 'droplet', 'teal'], ['/personal|beauty|health/', 'heart-pulse', 'purple'], ['/milk|dairy/', 'cup-straw', 'blue']] as [$pattern, $icon, $color]) {
        if (preg_match($pattern, $name)) return [$icon, $color];
    }
    return ['box-seam', ['blue', 'purple', 'teal', 'orange'][abs(crc32($category)) % 4]];
};
?>
<div class="stock-risk" id="stock-risk">
    <header class="sr-heading"><div class="sr-heading-copy"><span class="sr-icon sr-blue"><i class="bi bi-shield-check" aria-hidden="true"></i></span><div><h2>Stock Risk</h2><p>Compare what remains, what sells, and what needs a closer look.</p></div></div><span class="sr-source"><i class="bi bi-arrow-repeat" aria-hidden="true"></i>From recorded stock activity</span></header>
    <?php if ($riskError !== ''): ?>
        <div class="message error"><span role="alert"><?= htmlspecialchars($riskError) ?></span></div>
    <?php else: ?>
    <div class="sr-filters" role="group" aria-label="Filter stock risk">
        <?php foreach ($riskTypes as $key => [$label, $icon, $color]): ?>
        <button type="button" class="sr-filter" data-risk-filter="<?= $key ?>" aria-pressed="<?= $key === 'all' ? 'true' : 'false' ?>"><span class="sr-icon sr-<?= $color ?>"><i class="bi bi-<?= $icon ?>" aria-hidden="true"></i></span><span><?= $label ?></span><strong><?= number_format($riskCounts[$key]) ?></strong></button>
        <?php endforeach; ?>
    </div>
    <div class="sr-records">
        <div class="sr-toolbar"><div><h3>Compare products</h3><span class="sr-caption">Sales: past 30 days · No-sales check: past 90 days</span></div><label class="sr-search"><span class="sr-only">Search Stock Risk products</span><i class="bi bi-search" aria-hidden="true"></i><input type="search" id="risk-search" placeholder="Search product, SKU, category" aria-controls="risk-table-body" autocomplete="off"></label></div>
        <label class="sr-mobile-sort" for="risk-sort-mobile">Sort products<select id="risk-sort-mobile" aria-label="Sort products"><option value="priority">Attention first</option><option value="name">Product A–Z</option><option value="stock">Stock: high to low</option><option value="sales">Sales: high to low</option></select></label>
        <div class="sr-table-scroll" role="region" aria-label="Stock Risk comparison table" tabindex="0">
        <table class="sr-table" data-no-smart-table data-no-ghost-rows>
            <thead><tr><th scope="col" aria-sort="none" aria-label="Product and category"><button type="button" data-risk-sort="name">Product & category<i class="bi bi-arrow-down-up" aria-hidden="true"></i></button></th><th scope="col" class="sr-num" aria-sort="none" aria-label="Recorded stock"><button type="button" data-risk-sort="stock">Recorded stock<i class="bi bi-arrow-down-up" aria-hidden="true"></i></button></th><th scope="col" class="sr-num" aria-sort="none" aria-label="Sales in 30 days"><button type="button" data-risk-sort="sales">Sales / 30 days<i class="bi bi-arrow-down-up" aria-hidden="true"></i></button></th><th scope="col">Stock lasts</th><th scope="col">What to review</th><th scope="col" aria-label="Product details"></th></tr></thead>
            <tbody id="risk-table-body">
            <?php foreach ($riskRows as $row):
                $id = (int)$row['product_id'];
                $category = trim((string)($row['category_name'] ?? '')) ?: 'Uncategorized';
                [$categoryIcon, $categoryColor] = $categoryVisual($category);
                $kind = $row['risk_kind'];
                [, $riskIcon, $riskColor] = $riskTypes[$kind] ?? ['', 'check2-circle', 'green'];
                $cover = $row['days_of_stock'] !== null ? 'About ' . number_format($row['days_of_stock'], 0) . ' days' : ($row['units30'] === 0 ? 'No sales basis' : 'Limited history');
                if ($row['saleable_stock'] <= 0) $cover = 'No saleable stock';
            ?>
            <tr data-risk-row data-name="<?= htmlspecialchars($row['product_name']) ?>" data-stock="<?= (int)$row['quantity_on_hand'] ?>" data-sales="<?= $row['units30'] ?>" data-flags="<?= htmlspecialchars(implode(' ', $row['flags'])) ?>" data-search="<?= htmlspecialchars(strtolower($row['product_name'] . ' ' . $row['sku'] . ' ' . $category)) ?>">
                <td><div class="sr-product"><span class="sr-icon sr-<?= $categoryColor ?>"><i class="bi bi-<?= $categoryIcon ?>" aria-hidden="true"></i></span><div><strong><?= htmlspecialchars($row['product_name']) ?></strong><span><?= htmlspecialchars($row['sku']) ?> · <?= htmlspecialchars($category) ?></span></div></div></td>
                <td class="sr-num" data-label="Recorded stock"><strong><?= number_format((int)$row['quantity_on_hand']) ?></strong><span class="sr-unit">units</span></td>
                <td class="sr-num" data-label="Sales / 30 days"><?= number_format($row['units30']) ?><span class="sr-unit">units</span></td>
                <td data-label="Stock lasts"><span class="sr-cover"><?= $cover ?></span></td>
                <td><span class="sr-badge sr-<?= $riskColor ?>"><i class="bi bi-<?= $riskIcon ?>" aria-hidden="true"></i><?= htmlspecialchars($row['risk_label']) ?></span><?php if (count($row['flags']) > 1): ?><span class="sr-unit">+<?= count($row['flags']) - 1 ?> other <?= count($row['flags']) === 2 ? 'check' : 'checks' ?> in details</span><?php endif; ?></td>
                <td><button type="button" class="sr-review" data-risk-review="<?= $id ?>" aria-label="View details for <?= htmlspecialchars($row['product_name']) ?>" aria-expanded="false" aria-controls="risk-detail-<?= $id ?>">View<i class="bi bi-chevron-down" aria-hidden="true"></i></button></td>
            </tr>
            <tr id="risk-detail-<?= $id ?>" class="sr-detail-row" hidden><td colspan="6"><div class="sr-detail">
                <div class="sr-detail-heading"><h4><?= htmlspecialchars($row['product_name']) ?></h4><span class="sr-caption">Recorded movements · past 90 days</span></div>
                <dl class="sr-flow"><div><dt>Balance at start</dt><dd><?= number_format($row['opening_balance']) ?></dd></div><div><dt><i class="bi bi-arrow-down-left" aria-hidden="true"></i>Stock in</dt><dd>+<?= number_format($row['stock_in90']) ?></dd></div><div><dt><i class="bi bi-arrow-up-right" aria-hidden="true"></i>Stock out</dt><dd>−<?= number_format($row['stock_out90']) ?></dd></div><div><dt>Remaining</dt><dd><?= number_format((int)$row['quantity_on_hand']) ?></dd></div></dl>
                <div class="sr-detail-columns"><div><h5>Suggested next step</h5><p><?= htmlspecialchars($row['suggested_action']) ?></p><h5>Checks for this product</h5><p><?= htmlspecialchars(implode(' · ', array_map(static fn(string $flag): string => $riskTypes[$flag][0], $row['flags'])) ?: 'No stock-risk concern flagged.') ?></p></div><div><h5>Basis & limitations</h5><p><?= htmlspecialchars($row['history_note']) ?> <?= number_format($row['units90']) ?> units sold in 90 days.</p><p>Stock lasts = saleable stock ÷ average daily sales over 30 days. More than 30 days is flagged for review, not proof of excess.</p><p><?= number_format((int)($row['expired_quantity'] ?? 0)) ?> expired · <?= number_format($row['expiring_quantity']) ?> within the product’s expiry warning period.</p></div></div>
            </div></td></tr>
            <?php endforeach; ?>
            <tr id="risk-empty" <?= $riskRows ? 'hidden' : '' ?>><td colspan="6"><div class="sr-empty"><span class="sr-icon sr-slate"><i class="bi bi-search" aria-hidden="true"></i></span><strong><?= $riskRows ? 'No matching products' : 'No active products to review' ?></strong><p><?= $riskRows ? 'Try another search or stock-risk filter.' : 'Add products and record stock activity to start monitoring Stock Risk.' ?></p></div></td></tr>
            </tbody>
        </table>
        </div>
        <nav class="sr-pagination" aria-label="Stock Risk pagination"><span id="risk-page-summary" role="status">Showing <?= min(10, count($riskRows)) ?> of <?= count($riskRows) ?> products</span><div><button type="button" id="risk-previous" aria-label="Previous Stock Risk page"><i class="bi bi-chevron-left" aria-hidden="true"></i></button><span id="risk-page-status">Page 1 of <?= max(1, (int)ceil(count($riskRows) / 10)) ?></span><button type="button" id="risk-next" aria-label="Next Stock Risk page"><i class="bi bi-chevron-right" aria-hidden="true"></i></button></div></nav>
    </div>
    <details class="sr-help"><summary><i class="bi bi-info-circle" aria-hidden="true"></i>How to read Stock Risk</summary><p>Recorded stock follows recorded stock in and stock out, including returns and corrections. Unrecorded damage or losses can make shelf quantities different. Sales are recorded gross units before refunds or reversals; forecast-only imports are excluded. Days of stock is a historical estimate, not a demand forecast. Expiry follows each product’s existing warning period. Product counts can overlap between filters.</p></details>
    <?php endif; ?>
</div>
