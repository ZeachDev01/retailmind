<?php
// HTTP verification test: asserts Cache-Control: private, max-age=86400, immutable and 304 cache hits.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../bootstrap/app.php';
set_exception_handler(static function (Throwable $exception): void {
    global $log;
    if (isset($log) && file_exists($log)) {
        fwrite(STDERR, "Server log:\n" . file_get_contents($log) . "\n");
    }
    fwrite(STDERR, $exception->getMessage() . "\n" . $exception->getTraceAsString() . "\n");
    exit(1);
});

use App\Core\Database;
use App\Receipts\ReceiptDetailsService;

$config = require __DIR__ . '/../config/database.php';
$server = new PDO(
    sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $config['host'], $config['port']),
    $config['username'],
    $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$database = 'retailmind_receipt_cache_test_' . bin2hex(random_bytes(6));
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $message);
    }
};

$router = tempnam(sys_get_temp_dir(), 'rcache-router-');
$log = tempnam(sys_get_temp_dir(), 'rcache-log-');
$process = null;

try {
    $server->exec("CREATE DATABASE `{$database}`");
    $config['database'] = $database;
    $pdo = Database::connection($config);

    preg_match_all('/CREATE TABLE `[^`]+` \(.*?\) ENGINE=[^;]+;/s', file_get_contents(__DIR__ . '/../database/sql/schema.sql'), $tables);
    $assert(count($tables[0]) > 0, 'Canonical database schema loaded');

    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($tables[0] as $ddl) {
        $pdo->exec($ddl);
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

    $pdo->exec("
        INSERT INTO branches (branch_id, branch_name, branch_code) VALUES (1, 'Receipt Store', 'RECEIPT');
        INSERT INTO roles (role_id, role_name) VALUES (1, 'cashier'), (2, 'admin');
        INSERT INTO users (user_id, full_name, username, password_hash, role_id, branch_id, must_change_password) VALUES
            (1, 'Saved Seller', 'cashier-one', 'unused', 1, 1, 0),
            (2, 'Other Seller', 'cashier-two', 'unused', 1, 1, 0),
            (3, 'Receipt Admin', 'admin-one', 'unused', 2, 1, 0);
        INSERT INTO registers (register_id, name, status, paper_width_mm) VALUES
            (1, 'Saved Till', 'active', '80'),
            (2, 'Other Till', 'active', '58');
        INSERT INTO cashier_shifts (shift_id, cashier_id, register_id, opening_cash, locked_at, status) VALUES
            (1, 1, 1, 100.00, NULL, 'open'),
            (2, 2, 2, 100.00, NULL, 'open');
        INSERT INTO products (product_id, sku, barcode, product_name, unit_price, cost_price, branch_id, status) VALUES
            (1, 'REC-1', 'REC-1', 'Saved Product', 10.00, 3.00, 1, 'active');
        INSERT INTO sales (sale_id, cashier_id, shift_id, total_amount, payment_method, cash_received, change_due, sale_date) VALUES
            (6, 1, 1, 10.00, 'cash', 20.00, 10.00, '2026-10-01 08:39:18');
        INSERT INTO sale_items (sale_item_id, sale_id, product_id, quantity, unit_price, subtotal) VALUES
            (1, 6, 1, 1, 10.00, 10.00);
        INSERT INTO checkout_attempts (cashier_id, attempt_id, request_hash, sale_id, result_json) VALUES
            (1, '" . str_repeat('a', 32) . "', '" . str_repeat('b', 64) . "', 6, '{}');
    ");

    $pdo->beginTransaction();
    (new ReceiptDetailsService($pdo))->preserveSale(6);
    $pdo->commit();

    $bootstrapPath = var_export(realpath(__DIR__ . '/../bootstrap/app.php'), true);
    $salesPath = var_export(realpath(__DIR__ . '/../../frontend/components/invoice/sales.php'), true);
    $posPath = var_export(realpath(__DIR__ . '/../../frontend/components/cashier/pos.php'), true);
    $dbExport = var_export($database, true);

    file_put_contents($router, '<?php
require ' . $bootstrapPath . ';
$_ENV["DB_NAME"] = ' . $dbExport . ';
App\Core\Session::start();
$_SESSION["user_id"] = (int)($_SERVER["HTTP_X_FIXTURE_USER"] ?? 1);
$_SESSION["role"] = $_SESSION["user_id"] === 3 ? "admin" : "cashier";
$_SESSION["session_version"] = 1;
$_SESSION["csrf_token"] = "fixture-csrf";
$_SESSION["_authenticated_at"] = time();
$_SESSION["_last_activity"] = time();
$_SESSION["_restore_epoch"] = App\Backup\RecoveryStore::epoch();
if (isset($_SERVER["HTTP_X_FIXTURE_COMPLETE"])) {
    $_SESSION["completed_checkout_sale"] = (int)$_SERVER["HTTP_X_FIXTURE_COMPLETE"];
}
$uri = parse_url($_SERVER["REQUEST_URI"] ?? "/", PHP_URL_PATH);
if (str_contains($uri, "pos.php")) {
    require ' . $posPath . ';
} else {
    require ' . $salesPath . ';
}
');

    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    $assert(is_resource($socket), 'Reserve HTTP fixture port: ' . $error);
    $address = stream_socket_get_name($socket, false);
    fclose($socket);

    $process = proc_open(
        [PHP_BINARY, '-S', $address, $router],
        [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
        $pipes
    );
    $assert(is_resource($process), 'Receipt caching HTTP server process started');
    fclose($pipes[0]);

    $ready = false;
    for ($i = 0; $i < 100; $i++) {
        $probe = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
        if ($probe) {
            fclose($probe);
            $ready = true;
            break;
        }
        usleep(20000);
    }
    $assert($ready, 'Receipt caching HTTP server ready on ' . $address);

    $request = static function (string $path, array $query = [], array $extraHeaders = [], int $user = 1) use ($address, $log): array {
        $headerLines = ["X-Fixture-User: {$user}"];
        foreach ($extraHeaders as $k => $v) {
            $headerLines[] = "{$k}: {$v}";
        }
        $url = 'http://' . $address . $path . ($query ? '?' . http_build_query($query) : '');
        $context = stream_context_create([
            'http' => [
                'ignore_errors' => true,
                'follow_location' => 0,
                'header' => implode("\r\n", $headerLines) . "\r\n",
                'timeout' => 5,
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        $responseHeaders = $http_response_header ?? [];
        $statusLine = $responseHeaders[0] ?? '';
        preg_match('#HTTP/\S+\s+(\d+)#', $statusLine, $m);
        $statusCode = (int)($m[1] ?? 0);
        $headers = [];
        foreach ($responseHeaders as $h) {
            if (strpos($h, ':') !== false) {
                [$k, $v] = explode(':', $h, 2);
                $headers[strtolower(trim($k))] = trim($v);
            }
        }
        return [$statusCode, $headers, (string)$body];
    };

    // 1. Finalized Sale Receipt AJAX view endpoint emits private, max-age=86400, immutable
    [$status, $headers, $body] = $request('/components/invoice/sales.php', ['action' => 'view', 'ajax' => 1, 'sale_id' => 6], [], 1);
    $assert($status === 200, "Finalized receipt view returns HTTP 200 (got {$status})");
    $assert(isset($headers['cache-control']), 'Finalized receipt view emits Cache-Control header');
    $assert(
        str_contains($headers['cache-control'], 'private')
        && str_contains($headers['cache-control'], 'max-age=86400')
        && str_contains($headers['cache-control'], 'immutable'),
        "Cache-Control contains 'private, max-age=86400, immutable' (got: {$headers['cache-control']})"
    );
    $assert(!empty($headers['etag']), 'Finalized receipt view emits ETag header');
    $assert(str_contains($body, 'Saved Product'), 'Receipt HTML contains item details');
    $etag = $headers['etag'];

    // 2. Browser 304 cache hit validation using If-None-Match
    [$status304, $headers304, $body304] = $request(
        '/components/invoice/sales.php',
        ['action' => 'view', 'ajax' => 1, 'sale_id' => 6],
        ['If-None-Match' => $etag],
        1
    );
    $assert($status304 === 304, "Conditional If-None-Match returns HTTP 304 (got {$status304})");
    $assert(
        isset($headers304['cache-control'])
        && str_contains($headers304['cache-control'], 'private')
        && str_contains($headers304['cache-control'], 'max-age=86400')
        && str_contains($headers304['cache-control'], 'immutable'),
        'HTTP 304 preserves immutable caching headers'
    );
    $assert(trim($body304) === '', 'HTTP 304 response transfers zero body bytes');

    // 3. Browser 304 cache hit validation using If-Modified-Since
    if (!empty($headers['last-modified'])) {
        [$statusSince, $headersSince] = $request(
            '/components/invoice/sales.php',
            ['action' => 'view', 'ajax' => 1, 'sale_id' => 6],
            ['If-Modified-Since' => $headers['last-modified']],
            1
        );
        $assert($statusSince === 304, "Conditional If-Modified-Since returns HTTP 304 (got {$statusSince})");
    }

    // 4. Thermal reprint layout emits identical caching headers
    [$statusThermal, $headersThermal, $bodyThermal] = $request(
        '/components/invoice/sales.php',
        ['action' => 'reprint', 'sale_id' => 6],
        [],
        1
    );
    $assert($statusThermal === 200, "Thermal reprint layout returns HTTP 200 (got {$statusThermal})");
    $assert(
        isset($headersThermal['cache-control'])
        && str_contains($headersThermal['cache-control'], 'private')
        && str_contains($headersThermal['cache-control'], 'max-age=86400')
        && str_contains($headersThermal['cache-control'], 'immutable'),
        "Thermal reprint layout emits identical caching headers: 'private, max-age=86400, immutable'"
    );
    $assert(str_contains($bodyThermal, 'sale-receipt receipt-print-area'), 'Thermal reprint contains print markup');

    // 5. Active or draft checkout states are strictly excluded from immutable caching
    [$posStatus, $posHeaders] = $request('/components/cashier/pos.php', [], [], 1);
    $assert($posStatus === 200, 'Active POS screen returns HTTP 200');
    $assert(
        !str_contains($posHeaders['cache-control'] ?? '', 'immutable'),
        'Active POS screen strictly excludes immutable caching headers'
    );
    $assert(
        str_contains($posHeaders['cache-control'] ?? '', 'no-store')
        || str_contains($posHeaders['cache-control'] ?? '', 'no-cache'),
        "Active POS screen emits no-store or no-cache (got: " . ($posHeaders['cache-control'] ?? 'none') . ")"
    );

    // 5b. Recover checkout / active draft attempt excludes immutable caching
    [$recoverStatus, $recoverHeaders] = $request('/components/cashier/pos.php', ['recover_checkout' => 'test-token'], [], 1);
    $assert(
        !str_contains($recoverHeaders['cache-control'] ?? '', 'immutable'),
        'Checkout recovery strictly excludes immutable caching'
    );
    $assert(
        str_contains($recoverHeaders['cache-control'] ?? '', 'no-store'),
        'Checkout recovery emits no-store'
    );

    // 5c. Post-checkout transition page excludes immutable caching
    [$completeStatus, $completeHeaders] = $request('/components/cashier/pos.php', ['sale_id' => 6, 'checkout' => 'complete'], [], 1);
    $assert(
        !str_contains($completeHeaders['cache-control'] ?? '', 'immutable'),
        'POS checkout complete page strictly excludes immutable caching'
    );
    $assert(
        str_contains($completeHeaders['cache-control'] ?? '', 'no-store'),
        'POS checkout complete page emits no-store'
    );

    // 6. Security and access control: unowned receipt forbidden and not cached
    [$forbiddenStatus, $forbiddenHeaders] = $request(
        '/components/invoice/sales.php',
        ['action' => 'view', 'ajax' => 1, 'sale_id' => 6],
        [],
        2
    );
    $assert($forbiddenStatus === 403, 'Unowned receipt returns HTTP 403 Access denied');
    $assert(
        !str_contains($forbiddenHeaders['cache-control'] ?? '', 'immutable'),
        'HTTP 403 strictly excludes immutable caching'
    );

    // 7. Non-existent receipt returns 404 and is not cached as immutable
    [$notFoundStatus, $notFoundHeaders] = $request(
        '/components/invoice/sales.php',
        ['action' => 'view', 'ajax' => 1, 'sale_id' => 99999],
        [],
        1
    );
    $assert($notFoundStatus === 404, 'Non-existent receipt returns HTTP 404');
    $assert(
        !str_contains($notFoundHeaders['cache-control'] ?? '', 'immutable'),
        'HTTP 404 strictly excludes immutable caching'
    );

    echo "Receipt caching HTTP verification: passed\n";
} finally {
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    @unlink($router);
    @unlink($log);
    if (isset($server, $database)) {
        $server->exec("DROP DATABASE IF EXISTS `{$database}`");
    }
}
