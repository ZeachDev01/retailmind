<?php
// Uses a disposable database and leaves the configured Store untouched.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
if (getenv('RUN_REGISTER_DB_TESTS') !== '1') {
    echo "Legacy Register upgrade: skipped (set RUN_REGISTER_DB_TESTS=1; requires CREATE DATABASE)\n";
    exit(0);
}
require_once __DIR__ . '/../bootstrap/app.php';

$config = require __DIR__ . '/../config/database.php';
$root = new PDO(
    sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $config['host'], $config['port']),
    $config['username'], $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$database = 'retailmind_register_probe_' . bin2hex(random_bytes(4));
$root->exec("CREATE DATABASE `{$database}`");
try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $config['host'], $config['port'], $database),
        $config['username'], $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $pdo->exec("CREATE TABLE registers (
        register_id INT PRIMARY KEY AUTO_INCREMENT,
        register_code VARCHAR(30) NOT NULL UNIQUE,
        register_name VARCHAR(100) NOT NULL,
        is_enabled TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    $pdo->exec("INSERT INTO registers (register_code,register_name,is_enabled) VALUES
        ('REG-001','Front Counter',1),('REG-002','Closed Counter',0)");
    $pdo->exec('CREATE TABLE users (user_id INT PRIMARY KEY, full_name VARCHAR(100) NOT NULL)');
    $pdo->exec("INSERT INTO users VALUES (2,'Cashier')");
    $pdo->exec("CREATE TABLE cashier_shifts (
        shift_id INT PRIMARY KEY, cashier_id INT, register_id INT,
        status VARCHAR(10), opened_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("INSERT INTO cashier_shifts VALUES (7,2,1,'open',CURRENT_TIMESTAMP)");

    try {
        (new App\Services\CashierShiftService($pdo))->getOpenShift(2);
        throw new RuntimeException('The old schema should reproduce the Cashier failure.');
    } catch (PDOException $expected) {
        if (!str_contains($expected->getMessage(), 'r.name')) {
            throw $expected;
        }
    }

    $migration = require __DIR__ . '/../database/migrations/202609300004_legacy_register_columns.php';
    ($migration['up'])($pdo);
    ($migration['up'])($pdo);
    $service = new App\Services\CashierShiftService($pdo);
    $shift = $service->getOpenShift(2);
    if (($shift['register_name'] ?? null) !== 'Front Counter') {
        throw new RuntimeException('Cashier Shift lost its Register name.');
    }
    $available = $service->availableRegisters();
    if ($available !== []) {
        throw new RuntimeException('Occupied and disabled Registers must remain unavailable.');
    }
    $pdo->exec("INSERT INTO registers (name,status) VALUES ('New Counter','active')");
    if ($service->registerName((int)$pdo->lastInsertId()) !== 'New Counter') {
        throw new RuntimeException('New Registers must work after the upgrade.');
    }
    echo "Legacy Register upgrade probe: passed\n";
} finally {
    $root->exec("DROP DATABASE `{$database}`");
}
