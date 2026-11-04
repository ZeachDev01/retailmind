<?php
// Only usable forecasts belong in this comparison; missing demand is never zero.
$chartProducts = array_slice(array_values(array_filter($displayProducts, static fn(array $p): bool => !empty($p['is_forecast_backed']))), 0, 5);
$chartMax = 1;
foreach ($chartProducts as $chartProduct) {
    $chartMax = max($chartMax, (float)$chartProduct['current_stock'], (float)$chartProduct['forecast_7_day']);
}
?>
<?php if ($chartProducts): ?>
<section class="forecast-panel" aria-labelledby="stock-demand-title">
    <div class="forecast-panel-heading">
        <div>
            <h2 id="stock-demand-title">Stock vs expected sales · 7 days</h2>
            <small class="muted">First <?= count($chartProducts) ?> forecast-backed products<?= $searchQuery !== '' ? ' matching your search' : '' ?> · Base Units</small>
        </div>
        <div class="forecast-key"><span><i class="stock-key" aria-hidden="true"></i>On hand</span><span><i aria-hidden="true"></i>Expected sales</span></div>
    </div>
    <div class="forecast-bars">
        <?php foreach ($chartProducts as $chartProduct): ?>
        <div class="forecast-bar-row">
            <a href="<?= htmlspecialchars(app_url('components/inventory_management/product_investigation.php?product_id=' . (int)$chartProduct['product_id'])) ?>"><?= htmlspecialchars($chartProduct['product_name']) ?></a>
            <div class="forecast-bar-pair">
                <?php foreach (['current_stock' => 'On hand', 'forecast_7_day' => 'Expected sales'] as $key => $label): ?>
                <div class="forecast-bar-track" aria-label="<?= htmlspecialchars($label . ': ' . $chartProduct[$key] . ' units') ?>">
                    <span aria-hidden="true"><i class="forecast-bar <?= $key === 'current_stock' ? 'stock' : '' ?>" style="width: <?= max(0, (float)$chartProduct[$key]) / $chartMax * 100 ?>%"></i></span>
                    <small><?= htmlspecialchars((string)$chartProduct[$key]) ?></small>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>
