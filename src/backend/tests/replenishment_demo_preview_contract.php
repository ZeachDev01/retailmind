<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Core/Environment.php';
function env(string $key, $default = null) { return App\Core\Environment::get($key, $default); }

ob_start();
require __DIR__ . '/replenishment_metadata_contract.php';
ob_end_clean();

$pdo->exec("CREATE TABLE inventory (product_id INTEGER, quantity_on_hand INTEGER);
    INSERT INTO inventory VALUES (1, 0);
    UPDATE products SET status='active';
    UPDATE ml_jobs SET trigger_type='forecast_observation' WHERE job_id=10;
    DELETE FROM demand_forecasts;
    UPDATE platform_settings SET setting_value='{\"source_kind\":\"m5_daily\"}' WHERE setting_key='demo_import_metadata';
    INSERT INTO platform_settings VALUES ('demo_replay_result', '{\"kind\":\"m5_historical\",\"preview_job\":10}')");
$insert = $pdo->prepare("INSERT INTO demand_forecasts VALUES (?,10,1,'2016-04-24',?,2,'demo_saved','Medium')");
for ($day = 1; $day <= 28; $day++) $insert->execute([$day, $day]);
$_ENV['APP_ENV'] = 'development';
$_ENV['FORECAST_REPLAY_DATABASE'] = '';
$pdo->sqliteCreateFunction('DATABASE', static fn() => 'retailmind_demo_m5_fixture');
$html = renderReplenishmentPage($pdo, 'replenishment_queue');
if (!str_contains($html, 'data-expected-sales="56"')
    || !str_contains($html, 'Historical demo · unapproved')
    || !str_contains($html, '2016-04-25 – 2016-05-22')
    || !str_contains($html, 'data-quantity="15"')) {
    throw new RuntimeException('Prepared M5 Demo must display original-date expected sales independently of stock-rule guidance');
}

// Even an explicit replay override must not apply this page exception to a Store database.
$pdo->sqliteCreateFunction('DATABASE', static fn() => 'inventory_system');
$_ENV['FORECAST_REPLAY_DATABASE'] = 'inventory_system';
$store = renderReplenishmentPage($pdo, 'replenishment_queue');
if (str_contains($store, 'data-expected-sales="56"') || str_contains($store, 'Historical demo · unapproved')) {
    throw new RuntimeException('Store database must retain approved/current forecast requirements');
}
$_ENV['FORECAST_REPLAY_DATABASE'] = '';
$pdo->sqliteCreateFunction('DATABASE', static fn() => 'retailmind_demo_m5_fixture');
$pdo->exec('DELETE FROM demand_forecasts WHERE horizon_day=28');
$incomplete = renderReplenishmentPage($pdo, 'replenishment_queue');
if (str_contains($incomplete, 'data-expected-sales="54"') || str_contains($incomplete, 'Historical demo · unapproved')) {
    throw new RuntimeException('Incomplete historical coverage must remain unavailable');
}
$insert->execute([28, 28]);
$_ENV['APP_ENV'] = 'production';
$disabled = renderReplenishmentPage($pdo, 'replenishment_queue');
if (str_contains($disabled, 'data-expected-sales="56"')) {
    throw new RuntimeException('A Demo name without development/test mode or explicit replay configuration is insufficient');
}
echo "M5 replenishment preview contract passed (quantities, dates, independent stock rules, Store isolation, incomplete/disabled preview).\n";
