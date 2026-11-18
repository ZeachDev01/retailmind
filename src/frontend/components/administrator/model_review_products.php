<?php
$products = array_merge($coverage['covered'] ?? [], $coverage['excluded'] ?? []);
usort($products, static fn(array $a, array $b): int => strnatcasecmp($a['product_name'], $b['product_name']));
?>
<details class="forecast-details model-review-products" name="model-review-evidence" data-review-products>
    <summary>
        <span>Product coverage <small class="text-muted"><?= empty($coverage['available']) ? 'Coverage details unavailable.' : (int)$coverage['covered_count'] . ' included · ' . (int)$coverage['excluded_count'] . ' excluded' ?></small></span>
        <?php if ($products): ?><span class="model-review-products-label" aria-hidden="true"></span><?php endif; ?>
    </summary>
    <?php if (!$products): ?>
        <p>No product results available yet.</p>
    <?php else: ?>
        <div class="model-review-context">
            <div class="model-review-product-search" hidden>
                <label for="review-product-search" class="sr-only">Search products</label>
                <input type="search" id="review-product-search" autocomplete="off" placeholder="Search name or SKU">
            </div>
            <?php if (!empty($reviewDates['start'])): ?>
                <?php forecast_help('review-products-help', 'Observation: ' . $reviewDates['start'] . ' to ' . $reviewDates['end'] . '. Each product comparison uses only days with both a forecast and usable sales records.', 'About the product observation dates'); ?>
            <?php endif; ?>
        </div>
        <div class="data-table-shell">
            <div class="data-table-scroll" tabindex="0" role="region" aria-label="Product review results">
                <table class="data-table model-review-table">
                    <thead>
                    <tr>
                        <th scope="col">Product</th>
                        <th scope="col">Forecast</th>
                        <th scope="col">Actual sales</th>
                        <th scope="col" data-review-sort-heading>
                            <span>Difference</span>
                            <button class="model-review-sort" type="button" data-review-sort aria-label="Sort by size of difference" hidden>Difference <i class="bi bi-arrow-down-up" aria-hidden="true"></i></button>
                        </th>
                    </tr>
                    </thead>
                    <?php foreach ($products as $product): ?>
                        <?php
                        $id = $reviewEscape($product['product_id']);
                        $result = $product['result'] ?? null;
                        $difference = $result ? round($result['forecast'] - $result['actual'], 2) : null;
                        $differenceLabel = match (true) {
                            isset($product['exclusion_reason']) => 'Excluded',
                            $difference === null => 'Not enough data to compare',
                            $difference > 0 => $reviewUnits($difference) . ' higher',
                            $difference < 0 => $reviewUnits(abs($difference)) . ' lower',
                            default => 'Matched actual sales',
                        };
                        ?>
                        <tbody data-review-product="<?= $reviewEscape($product['product_name'] . ' ' . $product['sku']) ?>" data-difference="<?= $difference === null ? '' : abs($difference) ?>">
                            <tr>
                                <th scope="row">
                                    <?php if ($result): ?>
                                        <button type="button" class="model-review-product-toggle" aria-expanded="false" aria-controls="review-product-<?= $id ?>" hidden>
                                            <i class="bi bi-chevron-right" aria-hidden="true"></i> <?= $reviewEscape($product['product_name']) ?>
                                        </button>
                                    <?php endif; ?>
                                    <span data-review-product-label><?= $reviewEscape($product['product_name']) ?></span>
                                    <?php if ($product['sku'] !== ''): ?><small><?= $reviewEscape($product['sku']) ?></small><?php endif; ?>
                                </th>
                                <td><?= $result ? $reviewUnits($result['forecast']) : '—' ?></td>
                                <td><?= $result ? $reviewUnits($result['actual']) : '—' ?></td>
                                <td>
                                    <?= $reviewEscape($differenceLabel) ?>
                                    <?php if (!empty($product['exclusion_reason'])): ?><small><?= $reviewEscape($product['exclusion_reason']) ?></small><?php endif; ?>
                                </td>
                            </tr>
                            <?php if ($result): ?>
                                <tr id="review-product-<?= $id ?>" class="model-review-product-chart" hidden>
                                    <td colspan="4">
                                        <div class="model-review-chart">
                                            <p class="text-muted"><?= (int)$result['days'] ?> matched <?= $result['days'] === 1 ? 'day' : 'days' ?></p>
                                            <?php
                                            $reviewBars([
                                                ['label' => 'Forecast', 'value' => $result['forecast'], 'display' => $reviewUnits($result['forecast'])],
                                                ['label' => 'Actual sales', 'value' => $result['actual'], 'display' => $reviewUnits($result['actual'])],
                                            ]);
                                            ?>
                                            <p><?= $difference === 0.0 ? 'Forecast matched actual sales.' : 'Forecast was ' . $reviewEscape($differenceLabel) . ' than actual sales.' ?></p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    <?php endforeach; ?>
                </table>
            </div>
            <p class="model-review-empty" hidden>No products match your search.</p>
            <div class="table-pagination" hidden>
                <span role="status" data-review-page-count></span>
                <nav class="pagination-buttons" aria-label="Product results pages"></nav>
            </div>
        </div>
    <?php endif; ?>
</details>
