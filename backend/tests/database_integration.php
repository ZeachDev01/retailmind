<?php
// Requires a disposable MySQL database loaded with backend/sql/schema.sql.
if (getenv('RUN_DB_TESTS') !== '1') {
    echo "Database integration tests: skipped (set RUN_DB_TESTS=1)\n";
    exit(0);
}

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../config/db.php';

use App\Database\Schema;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$assert(Schema::columnExists($pdo, 'users', 'must_change_password'), 'users.must_change_password is missing');
$assert(Schema::tableExists($pdo, 'login_attempts'), 'login_attempts table is missing');
$assert(Schema::tableExists($pdo, 'purchase_orders'), 'purchase_orders table is missing');
$assert(
    Schema::foreignKeyRelationExists($pdo, 'supplier_products', 'supplier_id', 'suppliers', 'supplier_id'),
    'supplier_products.supplier_id is not protected by a foreign key'
);
$assert(
    Schema::foreignKeyRelationExists($pdo, 'purchase_order_items', 'purchase_order_id', 'purchase_orders', 'purchase_order_id'),
    'purchase_order_items.purchase_order_id is not protected by a foreign key'
);

try {
    $pdo->beginTransaction();
    $roleId = (int)$pdo->query("SELECT role_id FROM roles WHERE role_name='cashier' LIMIT 1")->fetchColumn();
    $username = 'integration_' . bin2hex(random_bytes(4));
    $stmt = $pdo->prepare(
        "INSERT INTO users (full_name, username, email, password_hash, role_id, status, must_change_password)
         VALUES ('Integration User', ?, ?, ?, ?, 'active', 1)"
    );
    $stmt->execute([$username, $username . '@example.test', password_hash('Integration@123', PASSWORD_DEFAULT), $roleId]);
    $userId = (int)$pdo->lastInsertId();
    $locked = $pdo->prepare('SELECT user_id FROM users WHERE user_id = ? FOR UPDATE');
    $locked->execute([$userId]);
    $assert((int)$locked->fetchColumn() === $userId, 'SELECT FOR UPDATE did not return the inserted user');
    $pdo->rollBack();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $failures[] = 'Transactional database test failed: ' . $e->getMessage();
}

if ($failures) {
    fwrite(STDERR, "Database integration tests failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Database integration tests: passed\n";
