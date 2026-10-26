<?php
require_once __DIR__ . '/../bootstrap/app.php';

use App\Services\GCashTransactionService;

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE roles (role_id INTEGER PRIMARY KEY, role_name TEXT);
    CREATE TABLE users (user_id INTEGER PRIMARY KEY, username TEXT, email TEXT, password_hash TEXT,
        role_id INTEGER, status TEXT, must_change_password INTEGER, is_recovery_account INTEGER DEFAULT 0);
    CREATE TABLE user_roles (user_id INTEGER, role_id INTEGER);
    INSERT INTO roles VALUES (1, 'admin'), (2, 'cashier');");
$pdo->prepare("INSERT INTO users (user_id, username, email, password_hash, role_id, status, must_change_password)
    VALUES (1, 'owner', 'owner@example.test', ?, 1, 'active', 0)")
    ->execute([password_hash('fixture-password', PASSWORD_DEFAULT)]);
$service = new GCashTransactionService($pdo);
$check = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};

$check($service->authorizeAdmin('owner', 'fixture-password') === 1, 'Username approval failed.');
$check($service->authorizeAdmin('owner@example.test', 'fixture-password') === 1, 'Email sign-in credential rejected for approval.');
$pdo->exec("UPDATE users SET role_id = 2 WHERE user_id = 1;
    INSERT INTO user_roles VALUES (1, 1)");
$check($service->authorizeAdmin('owner@example.test', 'fixture-password') === 1, 'Additional Administrator role rejected.');
$pdo->prepare("INSERT INTO users (user_id, username, email, password_hash, role_id, status, must_change_password)
    VALUES (2, 'owner@example.test', 'cashier@example.test', ?, 2, 'active', 0)")
    ->execute([password_hash('cashier-password', PASSWORD_DEFAULT)]);
try {
    $service->authorizeAdmin('owner@example.test', 'fixture-password');
    throw new RuntimeException('Email fallback bypassed a matching cashier username.');
} catch (DomainException $expected) {
    $check($expected->getCode() === 403, 'Username match must take priority over email.');
}
$pdo->exec("DELETE FROM users WHERE user_id = 2");
try {
    $service->authorizeAdmin('owner@example.test', 'wrong-password');
    throw new RuntimeException('Incorrect password accepted.');
} catch (DomainException $expected) {
    $check($expected->getCode() === 403, 'Incorrect password must be forbidden.');
}
echo "GCash administrator approval: passed\n";
