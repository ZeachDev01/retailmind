<?php
declare(strict_types=1);

function app_url(string $path): string { return '/' . $path; }

function render_chart(array $products): string
{
    $displayProducts = $products;
    $searchQuery = '';
    ob_start();
    require __DIR__ . '/../../frontend/components/report/forecast_stock_chart.php';
    return (string)ob_get_clean();
}

function check(bool $passed, string $message): void
{
    if (!$passed) throw new RuntimeException($message);
}

$product = ['product_id' => 1, 'product_name' => '<Milk>', 'is_forecast_backed' => true, 'current_stock' => 20, 'forecast_7_day' => 10];
$html = render_chart([$product, array_replace($product, ['product_name' => 'Excluded', 'is_forecast_backed' => false])]);
check(str_contains($html, 'width: 100%') && str_contains($html, 'width: 50%'), 'Stock and sales must use one common scale.');
check(str_contains($html, '&lt;Milk&gt;') && !str_contains($html, '<Milk>'), 'Product labels must be escaped.');
check(!str_contains($html, 'Excluded'), 'Missing forecasts must never appear as zero demand.');
check(!str_contains(render_chart([]), '<section'), 'An empty catalog must not produce a chart.');
check(!str_contains(render_chart([array_replace($product, ['is_forecast_backed' => false])]), '<section'), 'Stock-rule products must not produce a forecast chart.');
$zero = render_chart([array_replace($product, ['current_stock' => 0, 'forecast_7_day' => 0])]);
check(substr_count($zero, 'width: 0%') === 2, 'Genuine zero values must remain zero without dividing by zero.');
check(substr_count(render_chart(array_fill(0, 10, $product)), 'class="forecast-bar-row"') === 5, 'The overview must stay bounded and label its first five products.');
echo "Forecast stock chart checks passed.\n";
