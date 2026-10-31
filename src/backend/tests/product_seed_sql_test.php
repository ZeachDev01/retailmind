<?php
// Creates/drops only a randomly named disposable database on loopback MySQL/MariaDB.
// RUN_DB_TESTS=1 php src/backend/tests/product_seed_sql_test.php
if (getenv('RUN_DB_TESTS') !== '1') {
    echo "Product seed SQL tests: skipped (set RUN_DB_TESTS=1)\n";
    exit(0);
}

require_once __DIR__ . '/../app/Database/ProductSeeder.php';

$pdo = new PDO('mysql:host=127.0.0.1;charset=utf8mb4', getenv('DB_TEST_USER') ?: 'root', getenv('DB_TEST_PASSWORD') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$database = 'rm_product_seed_test_' . bin2hex(random_bytes(6));
$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$sql = file_get_contents(__DIR__ . '/../database/seeds/product_seed.sql');
// PDO sends the routine body as one statement; DELIMITER is a CLI/phpMyAdmin instruction.
$sql = preg_replace('/^--.*\R|^DELIMITER .*\R/m', '', $sql);
$statements = explode('CREATE PROCEDURE', $sql, 2);
[$routine, $tail] = explode('END$$', $statements[1], 2);
$seed = static function () use ($pdo, $statements, $routine, $tail): void {
    $pdo->exec($statements[0]);
    $pdo->exec('CREATE PROCEDURE' . $routine . 'END');
    $pdo->exec($tail);
};

try {
    $pdo->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$database`");
    $schema = file_get_contents(__DIR__ . '/../database/sql/schema.sql');
    $pdo->exec('CREATE TABLE users (user_id INT PRIMARY KEY, branch_id INT) ENGINE=InnoDB');
    foreach (['branches', 'categories', 'products', 'inventory'] as $table) {
        preg_match('/CREATE TABLE `' . $table . '` \(.*?\) ENGINE=InnoDB[^;]*;/s', $schema, $match);
        $pdo->exec($match[0]);
    }
    $pdo->exec("INSERT INTO branches (branch_name, branch_code) VALUES ('Test Store', 'TEST')");
    $seed();
    $expected = App\Database\ProductSeeder::loadProducts(__DIR__ . '/../database/seeds/product_seed.csv');
    $actual = $pdo->query('SELECT p.sku, p.barcode, p.product_name, p.brand, c.category_name, p.unit_price,
        p.cost_price, p.reorder_level, p.preferred_supplier, p.supplier_lead_time_days, p.safety_stock,
        p.minimum_order_quantity, p.units_per_package FROM products p JOIN categories c USING (category_id)
        ORDER BY p.product_id')->fetchAll(PDO::FETCH_ASSOC);
    $check(count($actual) === count($expected), 'SQL product count differs from CSV.');
    $actual = array_column($actual, null, 'sku');
    foreach ($expected as $row) {
        foreach ($row as $key => $value) {
            $check(is_numeric($value) ? (float)$actual[$row['sku']][$key] === (float)$value : $actual[$row['sku']][$key] === $value,
                'SQL seed differs from CSV: ' . $row['sku'] . '/' . $key);
        }
    }
    $check((int)$pdo->query('SELECT COUNT(*) FROM inventory WHERE quantity_on_hand = 0')->fetchColumn() === 60, 'Missing zero-stock inventory.');
    $pdo->exec('UPDATE products SET unit_price = 99, status = \'inactive\' WHERE product_id = 1');
    $pdo->exec('UPDATE inventory SET quantity_on_hand = 7 WHERE product_id = 1');
    $seed();
    $check((int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn() === 60, 'Re-import duplicated products.');
    $check((int)$pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn() === 11, 'Re-import duplicated categories.');
    $check((int)$pdo->query('SELECT COUNT(*) FROM inventory')->fetchColumn() === 60, 'Re-import duplicated inventory.');
    $check((int)$pdo->query("SELECT COUNT(*) FROM products p JOIN inventory i USING (product_id)
        WHERE p.product_id = 1 AND p.unit_price = 99 AND p.status = 'inactive' AND i.quantity_on_hand = 7")->fetchColumn() === 1,
        'Re-import changed existing data.');

    $pdo->exec("UPDATE products SET barcode = 'USER-EDITED' WHERE product_id = 1");
    try {
        $seed();
        throw new RuntimeException('Identifier collision was accepted.');
    } catch (PDOException $exception) {
        $check(str_contains($exception->getMessage(), 'Seed identifier collision'), 'Unexpected SQL failure.');
    }
    $check(!$pdo->inTransaction(), 'Failed seed left an open transaction.');
    $check((int)$pdo->query("SELECT IS_FREE_LOCK('retailmind_product_seed')")->fetchColumn() === 1, 'Failed seed leaked its lock.');
    echo "Product seed SQL tests passed: CSV parity, repeat import, preservation, collision rollback.\n";
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS `$database`");
}
