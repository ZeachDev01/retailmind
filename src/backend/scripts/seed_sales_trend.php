<?php
// Targeted local-development seed:
// php src/backend/scripts/seed_sales_trend.php --confirm-local

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}


require_once __DIR__ . '/../bootstrap/app.php';

use App\Database\DailySalesTrendSeeder;

$arguments = array_slice($argv, 1);
if (in_array('--help', $arguments, true) || in_array('-h', $arguments, true)) {
    echo "Seed deterministic daily sales for the Sales Trend dashboard.\n\n";
    echo "Usage:\n";
    echo "  php src/backend/scripts/seed_sales_trend.php --confirm-local\n\n";
    echo "Safety: APP_ENV must be development and DB_HOST must be localhost/loopback.\n";
    exit(0);
}

if (!in_array('--confirm-local', $arguments, true)) {
    fwrite(STDERR, "Refusing to seed without the explicit --confirm-local flag.\n");
    exit(1);
}

$unexpected = array_values(array_diff($arguments, ['--confirm-local']));
if ($unexpected) {
    fwrite(STDERR, 'Unknown argument(s): ' . implode(', ', $unexpected) . "\n");
    exit(1);
}

$config = require __DIR__ . '/../config/database.php';
$appEnv = strtolower(trim((string)env('APP_ENV', '')));
$dbHost = strtolower(trim((string)($config['host'] ?? '')));
$localHosts = ['localhost', '127.0.0.1', '::1'];
if ($appEnv !== 'development') {
    fwrite(STDERR, "Refusing to seed: APP_ENV must be development.\n");
    exit(1);
}
if (!in_array($dbHost, $localHosts, true)) {
    fwrite(STDERR, "Refusing to seed: DB_HOST must be localhost or a loopback address.\n");
    exit(1);
}
if (strtolower((string)env('DATABASE_ENV', '')) === 'hosted') {
    fwrite(STDERR, "Refusing to seed: DATABASE_ENV=hosted is not a local target.\n");
    exit(1);
}

try {
    $pdo = App\Core\Database::connection($config);
    $selectedDatabase = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($selectedDatabase === '' || $selectedDatabase !== (string)$config['database']) {
        throw new RuntimeException('The selected database does not match DB_NAME.');
    }

    $timezone = new DateTimeZone((string)env('APP_TIMEZONE', 'Asia/Manila'));
    $now = new DateTimeImmutable('now', $timezone);
    $pdo->prepare('SET time_zone = ?')->execute([$now->format('P')]);

    echo "Safety confirmed: development environment, local database host, database '{$selectedDatabase}'.\n";
    $seeder = new DailySalesTrendSeeder($pdo, $now);
    $result = $seeder->run();
    $owned = $seeder->ownedSummary();

    if ((int)$owned['sales'] !== (int)$result['sales']
        || (int)$owned['sale_items'] !== (int)$result['sale_items']
        || (int)$owned['units_sold'] !== (int)$result['units_sold']) {
        throw new RuntimeException('Post-seed ownership counts do not match the deterministic plan.');
    }

    echo "Seed complete ({$result['seed_key']}):\n";
    echo "  Date span: {$result['start_date']} through {$result['end_date']} ({$result['calendar_days']} calendar days)\n";
    echo "  Sales days / zero-sales days: {$result['sales_days']} / {$result['zero_sales_days']}";
    echo " ({$result['fiscal_blocked_days']} blocked by fiscal controls)\n";
    echo "  Sales / line items / units: {$result['sales']} / {$result['sale_items']} / {$result['units_sold']}\n";
    echo "  Products / total sales: {$result['products']} / {$result['gross_total']}\n";
    echo "Rerunning this command replaces only records owned by {$result['seed_key']}.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Sales trend seed failed: ' . $exception->getMessage() . "\n");
    exit(1);
}