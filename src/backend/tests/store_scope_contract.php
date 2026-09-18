<?php
// Database-backed contract for the singleton Store compatibility identity.
require_once __DIR__ . '/../bootstrap/app.php';

use App\Store\StoreConsolidationRequired;
use App\Store\StoreScope;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

try {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE branches (
        branch_id INTEGER PRIMARY KEY AUTOINCREMENT,
        branch_name VARCHAR(100) NOT NULL UNIQUE,
        branch_code VARCHAR(30) NOT NULL UNIQUE,
        status VARCHAR(20) NOT NULL DEFAULT 'active'
    )");
    $pdo->exec('CREATE TABLE users (user_id INTEGER PRIMARY KEY AUTOINCREMENT, branch_id INTEGER NULL)');
    $pdo->exec('CREATE TABLE products (product_id INTEGER PRIMARY KEY AUTOINCREMENT, branch_id INTEGER NULL)');

    $scope = new StoreScope($pdo);
    $migration = require __DIR__ . '/../database/migrations/202609180001_singleton_store_scope.php';

    $zero = $scope->preflight();
    $assert($zero['status'] === 'create_required', 'zero branches should require singleton Store creation');
    try {
        $scope->id();
        $assert(false, 'ordinary Store resolution must not create compatibility data');
    } catch (RuntimeException $exception) {
        $assert((int)$pdo->query('SELECT COUNT(*) FROM branches')->fetchColumn() === 0, 'ordinary Store resolution should remain read-only');
    }
    ($migration['up'])($pdo);
    ($migration['up'])($pdo);
    $createdId = $scope->id();
    $created = $pdo->query('SELECT branch_id, branch_name, branch_code, status FROM branches')->fetchAll(PDO::FETCH_ASSOC);
    $assert(count($created) === 1, 'singleton Store migration should be idempotent');
    $assert((int)$created[0]['branch_id'] === $createdId, 'created Store identity should be stable');
    $assert($created[0]['branch_code'] === StoreScope::COMPATIBILITY_CODE, 'created record should use the reserved compatibility code');
    $assert($created[0]['status'] === 'active', 'created compatibility record should be active');

    $pdo->exec('DELETE FROM products');
    $pdo->exec('DELETE FROM users');
    $pdo->exec('DELETE FROM branches');
    $pdo->exec("INSERT INTO branches (branch_name, branch_code, status) VALUES ('Existing Store', 'EXISTING', 'active')");
    $existingId = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO users (branch_id) VALUES ({$existingId})");
    $pdo->exec("INSERT INTO products (branch_id) VALUES ({$existingId})");
    $_GET['branch_id'] = '999999';
    $_POST['branch_id'] = '999999';
    $one = $scope->preflight();
    $assert($one['status'] === 'ready', 'one data-bearing active branch should be ready');
    $assert($scope->id() === $existingId && $scope->id() === $existingId, 'all Store scope resolutions should return the same identity');
    $assert($scope->id() !== 999999, 'request input must not override Store scope');
    [$scopeSql, $scopeParams] = $scope->productScope('p');
    $assert($scopeSql === ' AND p.branch_id = ?', 'product queries should use the internal Store compatibility column');
    $assert($scopeParams === [$existingId], 'product queries must derive the Store identity server-side');

    $pdo->exec('DELETE FROM products');
    $pdo->exec('DELETE FROM users');
    $pdo->exec('DELETE FROM branches');
    $pdo->exec("INSERT INTO branches (branch_name, branch_code, status) VALUES
        ('Store A', 'STORE-A', 'active'), ('Store B', 'STORE-B', 'active')");
    $branchRows = $pdo->query('SELECT branch_id, branch_code FROM branches ORDER BY branch_id')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($branchRows as $branch) {
        $pdo->prepare('INSERT INTO users (branch_id) VALUES (?)')->execute([(int)$branch['branch_id']]);
    }
    $beforeBranches = $pdo->query('SELECT branch_id, branch_name, branch_code, status FROM branches ORDER BY branch_id')->fetchAll(PDO::FETCH_ASSOC);
    $beforeUsers = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    try {
        ($migration['up'])($pdo);
        $assert(false, 'multiple data-bearing branches should stop migration');
    } catch (StoreConsolidationRequired $exception) {
        $report = $exception->report();
        $assert($report['status'] === 'consolidation_required', 'failure should include a consolidation status');
        $assert(count($report['branches']) === 2, 'consolidation report should identify every data-bearing branch');
        $assert(str_contains($exception->getMessage(), 'STORE-A') && str_contains($exception->getMessage(), 'STORE-B'), 'failure should clearly name conflicting branches');
    }
    $afterBranches = $pdo->query('SELECT branch_id, branch_name, branch_code, status FROM branches ORDER BY branch_id')->fetchAll(PDO::FETCH_ASSOC);
    $afterUsers = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $assert($afterBranches === $beforeBranches && $afterUsers === $beforeUsers, 'failed preflight must not change operational data');
} catch (Throwable $exception) {
    $failures[] = 'Store scope contract threw: ' . $exception->getMessage();
} finally {
    unset($_GET['branch_id'], $_POST['branch_id']);
}

if ($failures) {
    fwrite(STDERR, "Store scope contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Store scope contract: passed\n";
