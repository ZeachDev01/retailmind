<?php
// pos_catalog_delta_sync_test.php
// Automated HTTP verification test for POS catalog delta sync & conditional 304 ETag response.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once dirname(__DIR__, 2) . '/backend/bootstrap/app.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$config = require dirname(__DIR__, 2) . '/backend/config/database.php';
$server = new PDO(
    sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $config['host'], $config['port']),
    $config['username'],
    $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$database = 'retailmind_sync_test_' . bin2hex(random_bytes(6));
$server->exec("CREATE DATABASE `{$database}`");

$httpServer = null;
$routerFile = null;

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $config['host'], $config['port'], $database),
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Re-use canonical schema DDL
    preg_match_all('/CREATE TABLE `[^`]+` \(.*?\) ENGINE=[^;]+;/s', file_get_contents(dirname(__DIR__, 2) . '/backend/database/sql/schema.sql'), $tables);
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($tables[0] as $ddl) {
        $pdo->exec($ddl);
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

    $pdo->exec("
        INSERT INTO branches (branch_id, branch_name, branch_code) VALUES (1, 'Test Store', 'TEST');
        INSERT INTO roles (role_id, role_name) VALUES (1, 'cashier');
        INSERT INTO users (user_id, full_name, username, password_hash, role_id, branch_id, must_change_password, status)
            VALUES (1, 'Cashier User', 'cashier1', 'hash', 1, 1, 0, 'active');
        INSERT INTO registers (register_id, name) VALUES (1, 'Main Register');
        INSERT INTO cashier_shifts (shift_id, cashier_id, register_id, opening_cash, status, locked_at)
            VALUES (1, 1, 1, 100.00, 'open', NULL);
        INSERT INTO products (product_id, sku, barcode, product_name, unit_price, cost_price, status, branch_id)
            VALUES (1, 'MILK-01', '100001', 'Fresh Milk 1L', 12.50, 8.00, 'active', 1);
        INSERT INTO inventory (product_id, quantity_on_hand) VALUES (1, 50);
    ");

    // Create router script for built-in PHP server
    $posPath = var_export(realpath(dirname(__DIR__, 2) . '/frontend/components/cashier/pos.php'), true);
    $bootstrapPath = var_export(realpath(dirname(__DIR__, 2) . '/backend/bootstrap/app.php'), true);
    $fixtureDb = var_export($database, true);

    $routerFile = tempnam(sys_get_temp_dir(), 'pos_sync_router_') . '.php';
    $routerCode = <<<PHP
<?php
require {$bootstrapPath};
\$_ENV['DB_NAME'] = {$fixtureDb};
putenv('APP_DEBUG=true');
\$_ENV['APP_DEBUG'] = 'true';
\$GLOBALS['app']['debug'] = true;
App\Core\Session::start();
\$_SESSION['user_id'] = 1;
\$_SESSION['role'] = 'cashier';
\$_SESSION['session_version'] = 1;
\$_SESSION['csrf_token'] = 'fixture-csrf';
\$_SESSION['_authenticated_at'] = time();
\$_SESSION['_last_activity'] = time();
\$_SESSION['_restore_epoch'] = App\Backup\RecoveryStore::epoch();

require {$posPath};
PHP;
    file_put_contents($routerFile, $routerCode);

    // Reserve ephemeral port
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    $assert(is_resource($socket), 'Reserve local port for HTTP test server');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);

    $serverLog = tempnam(sys_get_temp_dir(), 'pos_sync_srv_') . '.log';
    $httpServer = proc_open(
        [PHP_BINARY, '-S', $address, $routerFile],
        [0 => ['pipe', 'r'], 1 => ['file', $serverLog, 'a'], 2 => ['file', $serverLog, 'a']],
        $httpPipes
    );
    $assert(is_resource($httpServer), 'Start built-in HTTP server');
    fclose($httpPipes[0]);

    // Wait until server is accepting connections
    $ready = false;
    for ($i = 0; $i < 100; $i++) {
        $probe = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
        if ($probe) {
            fclose($probe);
            $ready = true;
            break;
        }
        usleep(25000);
    }
    $assert($ready, 'Built-in HTTP server ready on ' . $address);

    $httpRequest = static function (string $route, array $headers = []) use ($address): array {
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = "{$name}: {$value}";
        }
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'ignore_errors' => true,
                'header' => implode("\r\n", $headerLines),
                'timeout' => 5,
            ],
        ]);
        $body = @file_get_contents('http://' . $address . $route, false, $context);
        $responseHeaders = $http_response_header ?? [];
        $statusLine = $responseHeaders[0] ?? '';
        preg_match('#HTTP/\S+\s+(\d+)#', $statusLine, $matches);
        $statusCode = isset($matches[1]) ? (int)$matches[1] : 0;

        $parsedHeaders = [];
        foreach ($responseHeaders as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $parsedHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }

        return [
            'status' => $statusCode,
            'headers' => $parsedHeaders,
            'body' => (string)$body,
            'size' => strlen((string)$body),
        ];
    };

    // 1. Initial catalog sync request (no ETag)
    $res1 = $httpRequest('/?action=catalog_sync');
    if ($res1['status'] !== 200) {
        fwrite(STDERR, "Response status {$res1['status']}, body: {$res1['body']}\nServer log:\n" . file_get_contents($serverLog) . "\n");
    }
    $assert($res1['status'] === 200, "Initial catalog sync returned status {$res1['status']} (expected 200)");
    $etag1 = $res1['headers']['etag'] ?? '';
    $assert($etag1 !== '', 'Server returned non-empty ETag header');
    $json1 = json_decode($res1['body'], true);
    $assert(is_array($json1) && ($json1['success'] ?? false) === true, 'Catalog response indicates success');
    $assert(isset($json1['catalog']) && count($json1['catalog']) === 1, 'Initial catalog contains 1 product');
    $assert($json1['catalog'][0]['sku'] === 'MILK-01', 'Catalog product matches SKU');

    // 2. Conditional catalog sync request via If-None-Match header -> 304 Not Modified (<100 bytes)
    $res2 = $httpRequest('/?action=catalog_sync', ['If-None-Match' => $etag1]);
    $assert($res2['status'] === 304, "Conditional sync with matching If-None-Match returned {$res2['status']} (expected 304)");
    $assert($res2['size'] < 100, "304 body size is {$res2['size']} bytes (must be < 100 bytes)");
    $assert(($res2['headers']['etag'] ?? '') === $etag1, '304 response echoes matching ETag');

    // 3. Conditional catalog sync request via if_none_match query parameter -> 304 Not Modified
    $res3 = $httpRequest('/?action=catalog_sync&if_none_match=' . urlencode($etag1));
    $assert($res3['status'] === 304, "Conditional sync with if_none_match query returned {$res3['status']} (expected 304)");
    $assert($res3['size'] < 100, "Query 304 body size is {$res3['size']} bytes (< 100 bytes)");

    // 4. Conditional catalog sync request via etag query parameter -> 304 Not Modified
    $res4 = $httpRequest('/?action=catalog_sync&etag=' . urlencode($etag1));
    $assert($res4['status'] === 304, "Conditional sync with etag query returned {$res4['status']} (expected 304)");
    $assert($res4['size'] < 100, "Query etag 304 body size is {$res4['size']} bytes (< 100 bytes)");

    // 5. Mutate catalog (update inventory stock) -> ETag changes
    $pdo->exec('UPDATE inventory SET quantity_on_hand = 75, last_updated = CURRENT_TIMESTAMP WHERE product_id = 1');

    // Request with OLD ETag -> returns 200 with new data and new ETag
    $res5 = $httpRequest('/?action=catalog_sync', ['If-None-Match' => $etag1]);
    $assert($res5['status'] === 200, "Sync after inventory update returned {$res5['status']} (expected 200)");
    $etag2 = $res5['headers']['etag'] ?? '';
    $assert($etag2 !== '' && $etag2 !== $etag1, 'New ETag was generated after inventory modification');
    $json5 = json_decode($res5['body'], true);
    $assert($json5['catalog'][0]['quantity_on_hand'] === 75, 'Updated quantity reflected in catalog payload');

    // 6. Request with NEW ETag -> returns 304 Not Modified (<100 bytes)
    $res6 = $httpRequest('/?action=catalog_sync', ['If-None-Match' => $etag2]);
    $assert($res6['status'] === 304, "Sync with new ETag returned {$res6['status']} (expected 304)");
    $assert($res6['size'] < 100, "304 body size is {$res6['size']} bytes (< 100 bytes)");

    // 7. Backward compatibility: action=catalog_preload supports ETag / 304
    $res7 = $httpRequest('/?action=catalog_preload', ['If-None-Match' => $etag2]);
    $assert($res7['status'] === 304, "catalog_preload action returned {$res7['status']} on matching ETag (expected 304)");
    $assert($res7['size'] < 100, "catalog_preload 304 body size is {$res7['size']} bytes (< 100 bytes)");

    // 8. Delta sync with since parameter returns modified products
    $pastTime = date('Y-m-d H:i:s', time() - 3600);
    $pdo->exec("
        INSERT INTO products (product_id, sku, barcode, product_name, unit_price, cost_price, status, branch_id)
            VALUES (2, 'BREAD-01', '100002', 'Wheat Bread', 8.00, 5.00, 'active', 1);
        INSERT INTO inventory (product_id, quantity_on_hand) VALUES (2, 20);
    ");
    $res8 = $httpRequest('/?action=catalog_sync&since=' . urlencode($pastTime));
    $assert($res8['status'] === 200, "Delta sync returned status {$res8['status']} (expected 200)");
    $json8 = json_decode($res8['body'], true);
    $assert($json8['delta'] === false, 'Changed catalogs return authoritative snapshots, including when since is provided');
    $assert(count($json8['catalog']) >= 1, 'Delta updates contain updated products');

    // Existing products can change price without touching creation/inventory timestamps.
    $pdo->exec("UPDATE products SET created_at = '2020-01-01', unit_price = 19.25 WHERE product_id = 1;
        UPDATE inventory SET last_updated = '2020-01-01' WHERE product_id = 1");
    $priceSync = $httpRequest('/?action=catalog_sync&since=' . urlencode(date('Y-m-d H:i:s')));
    $priceData = json_decode($priceSync['body'], true);
    $prices = array_column($priceData['catalog'], 'unit_price', 'product_id');
    $assert(($prices[1] ?? null) === 19.25, 'Price edits must reach cached products even when timestamps are unchanged');

    $etagBeforeSwap = $priceSync['headers']['etag'];
    $pdo->exec('UPDATE products SET unit_price = CASE product_id WHEN 1 THEN 8.00 ELSE 19.25 END');
    $swapped = $httpRequest('/?action=catalog_sync', ['If-None-Match' => $etagBeforeSwap]);
    $assert($swapped['status'] === 200, 'Equal-total price swaps must invalidate the catalog ETag');

    $pdo->exec('UPDATE inventory SET quantity_on_hand = 74 WHERE product_id = 1');
    $saleSync = $httpRequest('/?action=catalog_sync', ['If-None-Match' => $swapped['headers']['etag']]);
    $saleData = json_decode($saleSync['body'], true);
    $assert($saleSync['status'] === 200 && $saleData['catalog'][0]['quantity_on_hand'] === 74,
        'Stock decrements must refresh even when the inventory timestamp does not advance');

    $pdo->exec("UPDATE products SET pack_size = 6, pack_price = 40.00, pack_barcode = 'PACK-01' WHERE product_id = 1");
    $packSync = $httpRequest('/?action=catalog_sync', ['If-None-Match' => $swapped['headers']['etag']]);
    $packData = json_decode($packSync['body'], true);
    $assert($packSync['status'] === 200 && $packData['catalog'][0]['pack_price'] === 40,
        'Pack price edits must invalidate cached product cards');
    $pdo->exec("UPDATE products SET status = 'inactive'");
    $emptySync = $httpRequest('/?action=catalog_sync', ['If-None-Match' => $packSync['headers']['etag']]);
    $emptyData = json_decode($emptySync['body'], true);
    $assert($emptySync['status'] === 200 && $emptyData['catalog'] === [] && $emptyData['delta'] === false,
        'Deactivating the last products must return an authoritative empty snapshot');

    echo "POS catalog delta sync test: passed\n";
} finally {
    if (is_resource($httpServer)) {
        proc_terminate($httpServer);
        proc_close($httpServer);
    }
    if ($routerFile !== null && file_exists($routerFile)) {
        @unlink($routerFile);
    }
    if (isset($serverLog) && file_exists($serverLog)) {
        @unlink($serverLog);
    }
    $server->exec("DROP DATABASE IF EXISTS `{$database}`");
}
