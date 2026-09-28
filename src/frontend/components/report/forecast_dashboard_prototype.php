<?php
// THROWAWAY UI PROTOTYPE: three Demand Forecast dashboard directions on the
// existing route, switchable with ?variant=A|B|C. No actions persist data.

if (!isset($prototypeVariant, $forecastRows, $modelMetrics)) {
    http_response_code(404);
    exit;
}

function forecast_prototype_level(int $days, ?float $wape): array
{
    if ($days < 56) {
        return ['Insufficient', 'neutral'];
    }
    if ($days < 180 || $wape === null) {
        return ['Low', 'warning'];
    }
    if ($days < 365 || $wape > 35) {
        return ['Medium', 'info'];
    }
    return ['High', 'success'];
}

function forecast_prototype_fallback_rows(): array
{
    $path = dirname(__DIR__, 4) . '/product_seed.csv';
    $rows = [];
    if (($handle = @fopen($path, 'rb')) !== false) {
        $headers = fgetcsv($handle);
        while ($headers && count($rows) < 8 && ($values = fgetcsv($handle)) !== false) {
            $product = array_combine($headers, $values);
            if (!$product) {
                continue;
            }
            $index = count($rows);
            $rows[] = [
                'product_id' => $index + 1,
                'product_name' => $product['product_name'],
                'sku' => $product['sku'],
                'category' => $product['category_name'],
                'current_stock' => max(4, 52 - ($index * 5)),
                'predicted_demand_next_7_days' => 18 + ($index * 3),
                'predicted_demand_next_30_days' => 76 + ($index * 11),
                'calendar_history_days' => [410, 330, 220, 175, 120, 89, 48, 28][$index],
                'product_wape' => [18.2, 22.8, 29.4, 31.6, 36.1, null, null, null][$index],
                'suggested_reorder_qty' => max(0, ($index * 9) - 6),
                'supplier_lead_time_days' => (int)$product['supplier_lead_time_days'],
                'forecast_status' => $index < 6 ? 'Forecast Generated' : 'Insufficient Data',
            ];
        }
        fclose($handle);
    }
    return $rows;
}

$hasLiveForecasts = false;
foreach ($forecastRows as $forecastRow) {
    if ((int)($forecastRow['predicted_demand_next_7_days'] ?? 0) > 0) {
        $hasLiveForecasts = true;
        break;
    }
}
$isIllustrative = !$hasLiveForecasts;
$sourceRows = count($forecastRows) === 0 ? forecast_prototype_fallback_rows() : array_slice($forecastRows, 0, 12);
$products = [];
foreach ($sourceRows as $index => $row) {
    $days = (int)($row['calendar_history_days'] ?? 0);
    $wape = isset($row['product_wape']) && $row['product_wape'] !== null ? (float)$row['product_wape'] : null;
    [$level, $tone] = forecast_prototype_level($days, $wape);
    $productId = (int)($row['product_id'] ?? ($index + 1));
    $d7 = $isIllustrative
        ? 12 + (($productId * 7) % 25)
        : max(0, (int)($row['predicted_demand_next_7_days'] ?? 0));
    $d28 = $isIllustrative
        ? (int)round($d7 * (3.8 + (($productId % 3) * 0.12)))
        : max($d7, (int)round(((float)($row['predicted_demand_next_30_days'] ?? ($d7 * 4))) * 28 / 30));
    $d14 = max($d7, (int)round($d7 + (($d28 - $d7) / 3)));
    $stock = max(0, (int)($row['current_stock'] ?? 0));
    $risk = $stock < $d7 ? 'Urgent' : ($stock < $d14 ? 'Watch' : 'Covered');
    $riskTone = $risk === 'Urgent' ? 'danger' : ($risk === 'Watch' ? 'warning' : 'success');
    $products[] = [
        'id' => $productId,
        'name' => (string)($row['product_name'] ?? ('Product ' . ($index + 1))),
        'sku' => (string)($row['sku'] ?? ('SKU-' . ($index + 1))),
        'category' => (string)($row['category'] ?? 'Uncategorized'),
        'stock' => $stock,
        'd7' => $d7,
        'd14' => $d14,
        'd28' => $d28,
        'history' => $days,
        'wape' => $wape,
        'readiness' => $level,
        'readiness_tone' => $tone,
        'risk' => $risk,
        'risk_tone' => $riskTone,
        'reorder' => $isIllustrative
            ? max(0, $d14 - $stock)
            : max(0, (int)($row['suggested_reorder_qty'] ?? max(0, $d14 - $stock))),
        'lead' => max(1, (int)($row['supplier_lead_time_days'] ?? 7)),
    ];
}

if (!$products) {
    $products = forecast_prototype_fallback_rows();
}

$requestedProduct = (int)($_GET['product'] ?? ($products[0]['id'] ?? 0));
$selected = $products[0] ?? null;
foreach ($products as $product) {
    if ($product['id'] === $requestedProduct) {
        $selected = $product;
        break;
    }
}

$totals = ['d7' => 0, 'd14' => 0, 'd28' => 0, 'urgent' => 0, 'reorder' => 0];
foreach ($products as $product) {
    $totals['d7'] += $product['d7'];
    $totals['d14'] += $product['d14'];
    $totals['d28'] += $product['d28'];
    $totals['urgent'] += $product['risk'] === 'Urgent' ? 1 : 0;
    $totals['reorder'] += $product['reorder'];
}

$daily = [];
$dailyBase = $selected ? max(1, $selected['d28'] / 28) : 4;
for ($day = 1; $day <= 28; $day++) {
    $weekdayFactor = in_array($day % 7, [5, 6], true) ? 1.18 : 0.96;
    $pulse = ($day % 9 === 0) ? 1.22 : 1;
    $daily[] = max(0.4, round($dailyBase * $weekdayFactor * $pulse, 1));
}
$chartMax = max($daily ?: [1]);
$chartPoints = [];
foreach ($daily as $index => $value) {
    $x = 18 + ($index * (664 / 27));
    $y = 174 - (($value / $chartMax) * 136);
    $chartPoints[] = round($x, 1) . ',' . round($y, 1);
}
$chartPointsText = implode(' ', $chartPoints);
$variantNames = ['A' => 'Store overview', 'B' => 'Product workspace', 'C' => 'Action queue'];
$metricMae = isset($modelMetrics['mean_absolute_error']) ? number_format((float)$modelMetrics['mean_absolute_error'], 1) : '—';
$metricRmse = isset($modelMetrics['root_mean_squared_error']) ? number_format((float)$modelMetrics['root_mean_squared_error'], 1) : '—';
$metricWape = isset($modelMetrics['wape']) ? number_format((float)$modelMetrics['wape'], 1) . '%' : '—';

function forecast_prototype_url(string $variant, ?int $productId = null): string
{
    $query = ['variant' => $variant];
    if ($productId !== null) {
        $query['product'] = $productId;
    }
    return app_url('components/report/predictions.php') . '?' . http_build_query($query);
}

function forecast_prototype_chart(string $points, array $daily): void
{
    $last = count($daily) ? end($daily) : 0;
    ?>
    <div class="fp-chart" role="img" aria-label="Illustrative daily Expected Demand for the next 28 days">
        <svg viewBox="0 0 700 205" preserveAspectRatio="none" aria-hidden="true">
            <line x1="18" y1="38" x2="682" y2="38"></line>
            <line x1="18" y1="106" x2="682" y2="106"></line>
            <line x1="18" y1="174" x2="682" y2="174"></line>
            <polyline class="fp-chart-line" points="<?= htmlspecialchars($points) ?>"></polyline>
            <?php foreach (explode(' ', $points) as $index => $point): if ($index % 7 !== 0 && $index !== 27) continue; [$cx, $cy] = explode(',', $point); ?>
                <circle cx="<?= $cx ?>" cy="<?= $cy ?>" r="4"></circle>
            <?php endforeach; ?>
        </svg>
        <div class="fp-chart-axis"><span>Tomorrow</span><span>Day 7</span><span>Day 14</span><span>Day 21</span><span>Day 28 · <?= htmlspecialchars((string)$last) ?> units</span></div>
    </div>
    <?php
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Demand Forecast prototype · <?= htmlspecialchars($variantNames[$prototypeVariant]) ?></title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/forecast-dashboard-prototype.css')) ?>">
</head>
<body class="forecast-prototype">
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content fp-main">
        <div class="fp-prototype-note" role="note">
            <strong>Throwaway UI prototype</strong>
            <span><?= $isIllustrative ? 'Using the real product catalog with clearly illustrative demand values because the model is not trained.' : 'Using available Store forecasts; 14- and 28-day values are preview calculations.' ?></span>
            <a href="<?= htmlspecialchars(app_url('components/report/predictions.php')) ?>">Exit prototype</a>
        </div>

        <?php if ($prototypeVariant === 'A'): ?>
            <header class="fp-page-heading">
                <div><h1>Demand Forecast</h1><p>See expected demand, inventory exposure, and model readiness across the Store.</p></div>
                <div class="fp-heading-meta"><span>Last run</span><strong><?= htmlspecialchars((string)($modelMetrics['training_date'] ?? 'Not trained')) ?></strong></div>
            </header>

            <section class="fp-horizon-band" aria-label="Expected demand horizons">
                <div><span>Next 7 days</span><strong><?= number_format($totals['d7']) ?></strong><small>units expected</small></div>
                <div><span>Next 14 days</span><strong><?= number_format($totals['d14']) ?></strong><small>units expected</small></div>
                <div><span>Next 28 days</span><strong><?= number_format($totals['d28']) ?></strong><small>units expected</small></div>
                <aside><span>Needs attention</span><strong><?= $totals['urgent'] ?></strong><small>products at urgent risk</small></aside>
            </section>

            <div class="fp-overview-grid">
                <section class="fp-panel fp-demand-panel">
                    <div class="fp-panel-heading"><div><h2>Store demand outlook</h2><p>Daily Expected Demand across the next four weeks</p></div><span class="fp-state fp-state-info">28 days</span></div>
                    <?php forecast_prototype_chart($chartPointsText, $daily); ?>
                    <dl class="fp-metric-row"><div><dt>MAE</dt><dd><?= $metricMae ?></dd></div><div><dt>RMSE</dt><dd><?= $metricRmse ?></dd></div><div><dt>WAPE</dt><dd><?= $metricWape ?></dd></div></dl>
                </section>
                <section class="fp-panel fp-attention-panel">
                    <div class="fp-panel-heading"><div><h2>Attention first</h2><p>Highest inventory exposure</p></div><a href="<?= htmlspecialchars(forecast_prototype_url('C')) ?>">Open queue</a></div>
                    <div class="fp-attention-list">
                        <?php foreach (array_slice($products, 0, 5) as $product): ?>
                            <a href="<?= htmlspecialchars(forecast_prototype_url('B', $product['id'])) ?>"><span class="fp-risk-dot fp-risk-<?= $product['risk_tone'] ?>"></span><span><strong><?= htmlspecialchars($product['name']) ?></strong><small><?= $product['stock'] ?> on hand · <?= $product['d14'] ?> expected in 14 days</small></span><b><?= htmlspecialchars($product['risk']) ?></b></a>
                        <?php endforeach; ?>
                    </div>
                </section>
            </div>

            <section class="fp-records">
                <div class="fp-panel-heading"><div><h2>Product readiness</h2><p>History coverage and forecast status</p></div><span><?= count($products) ?> shown</span></div>
                <div class="fp-table-wrap"><table><thead><tr><th>Product</th><th>History</th><th>Readiness</th><th>7 days</th><th>14 days</th><th>28 days</th><th>Stock cover</th></tr></thead><tbody>
                <?php foreach ($products as $product): ?><tr><td><strong><?= htmlspecialchars($product['name']) ?></strong><small><?= htmlspecialchars($product['sku']) ?></small></td><td><?= $product['history'] ?> days</td><td><span class="fp-state fp-state-<?= $product['readiness_tone'] ?>"><?= $product['readiness'] ?></span></td><td><?= $product['d7'] ?></td><td><?= $product['d14'] ?></td><td><?= $product['d28'] ?></td><td><span class="fp-state fp-state-<?= $product['risk_tone'] ?>"><?= $product['risk'] ?></span></td></tr><?php endforeach; ?>
                </tbody></table></div>
            </section>

        <?php elseif ($prototypeVariant === 'B'): ?>
            <header class="fp-page-heading fp-page-heading-compact">
                <div><h1>Product forecast workspace</h1><p>Inspect one product before making a replenishment decision.</p></div>
                <div class="fp-heading-metrics"><span>MAE <b><?= $metricMae ?></b></span><span>RMSE <b><?= $metricRmse ?></b></span></div>
            </header>
            <div class="fp-workspace">
                <aside class="fp-product-rail" aria-label="Products">
                    <label for="fp-product-search">Products</label>
                    <input id="fp-product-search" type="search" placeholder="Find a product" autocomplete="off">
                    <nav>
                        <?php foreach ($products as $product): ?><a class="<?= $selected && $selected['id'] === $product['id'] ? 'is-selected' : '' ?>" href="<?= htmlspecialchars(forecast_prototype_url('B', $product['id'])) ?>" data-product-name="<?= htmlspecialchars(strtolower($product['name'])) ?>"><span><strong><?= htmlspecialchars($product['name']) ?></strong><small><?= htmlspecialchars($product['sku']) ?></small></span><i class="fp-risk-dot fp-risk-<?= $product['risk_tone'] ?>"></i></a><?php endforeach; ?>
                    </nav>
                </aside>
                <section class="fp-product-canvas">
                    <div class="fp-product-title"><div><span><?= htmlspecialchars($selected['category']) ?></span><h2><?= htmlspecialchars($selected['name']) ?></h2><p><?= htmlspecialchars($selected['sku']) ?> · <?= $selected['history'] ?> days of history</p></div><span class="fp-state fp-state-<?= $selected['readiness_tone'] ?>"><?= $selected['readiness'] ?> readiness</span></div>
                    <div class="fp-product-horizons"><div><span>7 days</span><strong><?= $selected['d7'] ?></strong></div><div><span>14 days</span><strong><?= $selected['d14'] ?></strong></div><div><span>28 days</span><strong><?= $selected['d28'] ?></strong></div></div>
                    <div class="fp-chart-heading"><div><h3>Daily Expected Demand</h3><p>Range and scheduled events will appear here in the implemented version.</p></div><span>Units</span></div>
                    <?php forecast_prototype_chart($chartPointsText, $daily); ?>
                    <div class="fp-driver-list"><h3>What is shaping this forecast</h3><div><span>Recent 7-day demand</span><b>Strong influence</b></div><div><span>Day-of-week pattern</span><b>Moderate influence</b></div><div><span>Scheduled promotion</span><b>None scheduled</b></div></div>
                </section>
                <aside class="fp-decision-rail">
                    <div><span>Current stock</span><strong><?= $selected['stock'] ?></strong><small>units on hand</small></div>
                    <div><span>Stock position</span><strong class="fp-text-<?= $selected['risk_tone'] ?>"><?= $selected['risk'] ?></strong><small>against the 14-day horizon</small></div>
                    <div><span>Suggested reorder</span><strong><?= $selected['reorder'] ?></strong><small>lead time <?= $selected['lead'] ?> days</small></div>
                    <hr>
                    <p>This is decision support. Review incoming stock, supplier terms, and Store conditions before acting.</p>
                    <button type="button" disabled>Review replenishment</button>
                </aside>
            </div>

        <?php else: ?>
            <header class="fp-page-heading">
                <div><h1>Forecast action queue</h1><p>Work from the most exposed products toward healthy stock coverage.</p></div>
                <div class="fp-queue-summary"><span><b><?= $totals['urgent'] ?></b> urgent</span><span><b><?= number_format($totals['reorder']) ?></b> suggested units</span></div>
            </header>
            <section class="fp-queue-toolbar" aria-label="Queue filters">
                <div class="fp-segmented"><button class="is-active" type="button">All products</button><button type="button">Urgent</button><button type="button">Watch</button><button type="button">Covered</button></div>
                <label><span>Sort</span><select><option>Highest risk first</option><option>Largest reorder</option><option>Lowest readiness</option></select></label>
            </section>
            <section class="fp-queue" aria-label="Forecast actions">
                <div class="fp-queue-header"><span>Priority</span><span>Product</span><span>Inventory position</span><span>Expected demand</span><span>Readiness</span><span>Next step</span></div>
                <?php foreach ($products as $index => $product): ?>
                    <article class="fp-queue-row">
                        <div><span class="fp-priority fp-priority-<?= $product['risk_tone'] ?>"><?= str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT) ?></span></div>
                        <div><strong><?= htmlspecialchars($product['name']) ?></strong><small><?= htmlspecialchars($product['sku']) ?> · <?= htmlspecialchars($product['category']) ?></small></div>
                        <div><span><?= $product['stock'] ?> on hand</span><strong class="fp-text-<?= $product['risk_tone'] ?>"><?= htmlspecialchars($product['risk']) ?></strong></div>
                        <div class="fp-demand-cells"><span><b><?= $product['d7'] ?></b> 7d</span><span><b><?= $product['d14'] ?></b> 14d</span><span><b><?= $product['d28'] ?></b> 28d</span></div>
                        <div><span class="fp-state fp-state-<?= $product['readiness_tone'] ?>"><?= $product['readiness'] ?></span><small><?= $product['history'] ?> history days</small></div>
                        <div><?php if ($product['reorder'] > 0): ?><strong><?= $product['reorder'] ?> units suggested</strong><a href="<?= htmlspecialchars(forecast_prototype_url('B', $product['id'])) ?>">Inspect forecast</a><?php else: ?><strong>No order suggested</strong><a href="<?= htmlspecialchars(forecast_prototype_url('B', $product['id'])) ?>">Review coverage</a><?php endif; ?></div>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>
    </main>
</div>

<nav class="fp-switcher" aria-label="Prototype variants" data-current-variant="<?= $prototypeVariant ?>">
    <button type="button" data-prototype-direction="-1" aria-label="Previous variant"><i class="bi bi-arrow-left"></i></button>
    <span><small>Prototype variant</small><strong><?= $prototypeVariant ?> · <?= htmlspecialchars($variantNames[$prototypeVariant]) ?></strong></span>
    <button type="button" data-prototype-direction="1" aria-label="Next variant"><i class="bi bi-arrow-right"></i></button>
</nav>
<script>
(function () {
    const variants = ['A', 'B', 'C'];
    const current = <?= json_encode($prototypeVariant) ?>;
    function move(direction) {
        const url = new URL(window.location.href);
        const index = variants.indexOf(current);
        url.searchParams.set('variant', variants[(index + direction + variants.length) % variants.length]);
        window.location.href = url.toString();
    }
    document.querySelectorAll('[data-prototype-direction]').forEach((button) => {
        button.addEventListener('click', () => move(Number(button.dataset.prototypeDirection)));
    });
    document.addEventListener('keydown', (event) => {
        if (!['ArrowLeft', 'ArrowRight'].includes(event.key)) return;
        if (event.target.closest('input, select, textarea, [contenteditable]')) return;
        move(event.key === 'ArrowLeft' ? -1 : 1);
    });
    const search = document.getElementById('fp-product-search');
    if (search) {
        search.addEventListener('input', () => {
            const query = search.value.trim().toLowerCase();
            document.querySelectorAll('[data-product-name]').forEach((item) => {
                item.hidden = query !== '' && !item.dataset.productName.includes(query);
            });
        });
    }
})();
</script>
</body>
</html>
