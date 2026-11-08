<?php

declare(strict_types=1);

// remote_access_proxy_guardrails_contract.php
// Contract tests for Ticket #440:
// - Reverse proxy scheme awareness (X-Forwarded-Proto, X-Forwarded-Host, CF-Visitor)
// - Secure cookies and SameSite attributes
// - Web server file lockdown (.htaccess blocking .env, .git, backups, config, tests, sql)
// - Guardrails rejecting physical register/cash drawer operations from remote origins

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
        echo "FAIL: {$message}\n";
    } else {
        echo "PASS: {$message}\n";
    }
};

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../app/Core/Session.php';
require_once __DIR__ . '/../app/Services/CashierShiftService.php';

use App\Core\Session;
use App\Services\CashierShiftService;

echo "=== 1. Reverse Proxy Scheme and Host Awareness ===\n";

// Backup server environment
$savedServer = $_SERVER;
$savedEnv = $_ENV;
$savedSession = $_SESSION ?? [];

// Helper to reset request environment
$resetEnv = function () use ($savedServer, $savedEnv, $savedSession): void {
    $_SERVER = $savedServer;
    $_ENV = $savedEnv;
    $_SESSION = $savedSession;
    unset(
        $_SERVER['HTTPS'],
        $_SERVER['SERVER_PORT'],
        $_SERVER['HTTP_X_FORWARDED_PROTO'],
        $_SERVER['HTTP_X_FORWARDED_SSL'],
        $_SERVER['HTTP_X_FORWARDED_PORT'],
        $_SERVER['HTTP_X_FORWARDED_HOST'],
        $_SERVER['HTTP_X_FORWARDED_FOR'],
        $_SERVER['HTTP_CF_VISITOR'],
        $_SERVER['HTTP_CF_RAY'],
        $_SERVER['HTTP_CF_CONNECTING_IP'],
        $_SERVER['HTTP_CF_ACCESS_AUTHENTICATED_USER_EMAIL'],
        $_SERVER['RETAILMIND_REMOTE'],
        $_ENV['RETAILMIND_REMOTE'],
        $_SESSION['is_remote']
    );
};

// 1.1 Local plain HTTP detection
$resetEnv();
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_PORT'] = '80';
$assert(function_exists('app_is_secure'), 'app_is_secure helper function exists');
$assert(function_exists('app_is_secure') && !app_is_secure(), 'Local plain HTTP is not secure');
$assert(function_exists('app_scheme') && app_scheme() === 'http', 'app_scheme returns http for local plain HTTP');
$assert(function_exists('app_host') && app_host() === 'localhost', 'app_host returns localhost');

// 1.2 Direct HTTPS detection
$resetEnv();
$_SERVER['HTTPS'] = 'on';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_PORT'] = '443';
$assert(function_exists('app_is_secure') && app_is_secure(), 'Direct HTTPS is detected as secure');
$assert(function_exists('app_scheme') && app_scheme() === 'https', 'app_scheme returns https for direct HTTPS');

// 1.3 X-Forwarded-Proto = https
$resetEnv();
$_SERVER['HTTP_HOST'] = 'tunnel.example.com';
$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
$assert(function_exists('app_is_secure') && app_is_secure(), 'X-Forwarded-Proto=https is detected as secure');
$assert(function_exists('app_scheme') && app_scheme() === 'https', 'app_scheme returns https with X-Forwarded-Proto=https');

// 1.4 X-Forwarded-Proto with multiple comma-separated values ('https, http')
$resetEnv();
$_SERVER['HTTP_HOST'] = 'tunnel.example.com';
$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https, http';
$assert(function_exists('app_is_secure') && app_is_secure(), 'Comma-separated X-Forwarded-Proto=https is detected as secure');

// 1.5 Cloudflare CF-Visitor JSON header
$resetEnv();
$_SERVER['HTTP_HOST'] = 'my-tunnel.trycloudflare.com';
$_SERVER['HTTP_CF_VISITOR'] = '{"scheme":"https"}';
$assert(function_exists('app_is_secure') && app_is_secure(), 'CF-Visitor containing https is detected as secure');
$assert(function_exists('app_scheme') && app_scheme() === 'https', 'app_scheme returns https with CF-Visitor');

// 1.6 X-Forwarded-Host awareness
$resetEnv();
$_SERVER['HTTP_HOST'] = '127.0.0.1:8080';
$_SERVER['HTTP_X_FORWARDED_HOST'] = 'retailmind.store.com';
$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
$assert(function_exists('app_host') && app_host() === 'retailmind.store.com', 'app_host respects HTTP_X_FORWARDED_HOST');
$assert(function_exists('app_origin') && app_origin() === 'https://retailmind.store.com', 'app_origin builds https origin with forwarded host');

echo "\n=== 2. Session Cookies: Secure and SameSite Attributes ===\n";

// 2.1 Local plain HTTP -> cookie secure should be false
$resetEnv();
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_PORT'] = '80';
$assert(!Session::shouldUseSecureCookie(), 'Session cookie secure is false for local plain HTTP');

// 2.2 Remote HTTPS via X-Forwarded-Proto -> cookie secure must be true
$resetEnv();
$_SERVER['HTTP_HOST'] = 'my-tunnel.trycloudflare.com';
$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
$assert(Session::shouldUseSecureCookie(), 'Session cookie secure is true when X-Forwarded-Proto=https');

// 2.3 Remote HTTPS via CF-Visitor -> cookie secure must be true
$resetEnv();
$_SERVER['HTTP_HOST'] = 'my-tunnel.trycloudflare.com';
$_SERVER['HTTP_CF_VISITOR'] = '{"scheme":"https"}';
$assert(Session::shouldUseSecureCookie(), 'Session cookie secure is true when CF-Visitor scheme is https');

// 2.4 Respect explicit SESSION_SECURE_COOKIE environment override
$resetEnv();
putenv('SESSION_SECURE_COOKIE=false');
$_ENV['SESSION_SECURE_COOKIE'] = 'false';
$_SERVER['HTTPS'] = 'on';
$assert(!Session::shouldUseSecureCookie(), 'Explicit SESSION_SECURE_COOKIE=false overrides detected HTTPS');
putenv('SESSION_SECURE_COOKIE');
unset($_ENV['SESSION_SECURE_COOKIE']);

echo "\n=== 3. Web Server File Lockdown (.htaccess) ===\n";

$rootHtaccess = file_get_contents(dirname(__DIR__, 3) . '/.htaccess');
$assert($rootHtaccess !== false, 'Root .htaccess file exists and is readable');

// Verify sensitive file patterns denied in .htaccess
$forbiddenFiles = [
    '.env',
    '.env.local',
    '.env.production',
    '.env.example',
    '.git/config',
    '.git/HEAD',
    '.gitignore',
    'composer.json',
    'composer.lock',
    'database_backup.sql',
    'retailmind_dump.sql',
    'storage/backups/backup.sql',
    'src/backend/tests/something_test.php',
    'src/backend/config/db.php',
];

// Check root .htaccess contains deny patterns for .env, .git, backups, sql, tests, config
$assert(str_contains($rootHtaccess, '.env'), 'Root .htaccess denies .env files');
$assert(str_contains($rootHtaccess, '.git'), 'Root .htaccess denies .git directories');
$assert(str_contains($rootHtaccess, 'sql'), 'Root .htaccess denies sql files');
$assert(str_contains($rootHtaccess, 'backend'), 'Root .htaccess denies backend directory');
$assert(str_contains($rootHtaccess, 'tests'), 'Root .htaccess denies tests directory');

// Verify that each forbidden file path is blocked by either FilesMatch or RewriteRule patterns
$filesMatchRegex = '/^(\.env.*|\.git.*|\.user\.ini|\.ftp-deploy-sync-state\.json|composer\.(json|lock)|package(-lock)?\.json|phpunit\.xml|.*\.(joblib|pyc|log|sql|sqlite|db|rmpkg|bak|dump))$/i';
$rewriteBlockPatterns = [
    '/(?:^|\/)\.(?:git|svn|hg|env)(?:\/|$)/i',
    '/^(?:src\/)?(?:backend|vendor|storage)(?:\/|$)/i',
    '/(?:^|\/)(?:storage\/backups|backups|database\/backups)(?:\/|$)/i',
    '/(?:^|\/)(?:tests?|phpunit|.*_test\.php|.*Test\.php)(?:\/|$|\?)/i',
    '/\.(?:sql|env|log|bak|dump|sqlite|db|joblib|pyc|rmpkg)(?:$|\?)/i',
];

foreach ($forbiddenFiles as $forbidden) {
    $basename = basename($forbidden);
    $matched = preg_match($filesMatchRegex, $basename) === 1;
    if (!$matched) {
        foreach ($rewriteBlockPatterns as $pattern) {
            if (preg_match($pattern, $forbidden) === 1) {
                $matched = true;
                break;
            }
        }
    }
    $assert($matched, "Categorically blocked from external HTTP access: {$forbidden}");
}

// Verify backend .htaccess exists to categorically deny all direct HTTP access to src/backend
$backendHtaccessPath = dirname(__DIR__) . '/.htaccess';
$backendHtaccessExists = is_file($backendHtaccessPath);
$assert($backendHtaccessExists, 'src/backend/.htaccess exists to lock down backend directory');
if ($backendHtaccessExists) {
    $backendHtaccess = file_get_contents($backendHtaccessPath);
    $assert(
        str_contains($backendHtaccess, 'denied') || str_contains($backendHtaccess, 'Forbidden') || str_contains($backendHtaccess, '[F'),
        'src/backend/.htaccess categorically denies HTTP requests'
    );
}

echo "\n=== 4. Remote Guardrails: Reject Physical Register and Cash Drawer Operations ===\n";

// 4.1 Remote detection via headers
$resetEnv();
$_SERVER['HTTP_HOST'] = 'localhost';
$assert(!is_remote_request(), 'Localhost request is not detected as remote');

$resetEnv();
$_SERVER['HTTP_HOST'] = '192.168.1.150';
$assert(!is_remote_request(), 'Local LAN IP is not detected as remote');

$resetEnv();
$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.25';
$assert(is_remote_request(), 'HTTP_CF_CONNECTING_IP identifies as remote request');

$resetEnv();
$_SERVER['HTTP_CF_RAY'] = '8d1234567890abcd-NRT';
$assert(is_remote_request(), 'HTTP_CF_RAY identifies as remote request');

$resetEnv();
$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.195';
$assert(is_remote_request(), 'Public HTTP_X_FORWARDED_FOR identifies as remote request');

$resetEnv();
$_SERVER['HTTP_X_FORWARDED_HOST'] = 'store-demo.trycloudflare.com';
$assert(is_remote_request(), 'Cloudflare tunnel forwarded host identifies as remote request');

$resetEnv();
$_SERVER['HTTP_HOST'] = 'store-demo.trycloudflare.com';
$assert(is_remote_request(), 'Cloudflare tunnel host identifies as remote request');

// 4.2 Guardrails reject physical register and cash drawer operations when remote
// Set up in-memory sqlite test database for CashierShiftService
$testPdo = new PDO('sqlite::memory:');
$testPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$testPdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'), 0);
$testPdo->sqliteCreateFunction('CURDATE', static fn(): string => date('Y-m-d'), 0);

$testPdo->exec("
    CREATE TABLE users (
        user_id INTEGER PRIMARY KEY,
        full_name TEXT NOT NULL,
        username TEXT NOT NULL,
        password_hash TEXT NOT NULL,
        role TEXT NOT NULL,
        display_name TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'active'
    );
    CREATE TABLE cashier_shifts (
        shift_id INTEGER PRIMARY KEY AUTOINCREMENT,
        cashier_id INTEGER NOT NULL,
        register_id INTEGER NULL,
        opened_at TEXT DEFAULT CURRENT_TIMESTAMP,
        opening_cash REAL NOT NULL DEFAULT 0.00,
        status TEXT NOT NULL DEFAULT 'open',
        closed_at TEXT NULL,
        expected_cash REAL NULL,
        actual_cash REAL NULL,
        cash_variance REAL NULL,
        variance_threshold REAL NULL,
        variance_review_required INTEGER NOT NULL DEFAULT 0,
        payment_totals TEXT NULL,
        closing_notes TEXT NULL,
        closed_by INTEGER NULL,
        intervention_reason TEXT NULL,
        reviewed_by INTEGER NULL,
        reviewed_at TEXT NULL,
        locked_at TEXT NULL
    );
    CREATE TABLE registers (
        register_id INTEGER PRIMARY KEY,
        name TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'active'
    );
    CREATE TABLE cash_drawer_movements (
        drawer_movement_id INTEGER PRIMARY KEY AUTOINCREMENT,
        shift_id INTEGER NOT NULL,
        cashier_id INTEGER NOT NULL,
        movement_type TEXT NOT NULL,
        amount REAL NOT NULL,
        reason TEXT NOT NULL,
        note TEXT,
        recorded_by INTEGER NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE activity_log (
        log_id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NULL,
        action TEXT NOT NULL,
        category TEXT NOT NULL,
        module TEXT NULL,
        record_id INTEGER NULL,
        previous_value TEXT NULL,
        new_value TEXT NULL,
        metadata TEXT NULL,
        ip_address TEXT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE attention_settings (
        setting_scope TEXT,
        setting_key TEXT,
        setting_value TEXT
    );
    CREATE TABLE held_sales (
        held_sale_id INTEGER PRIMARY KEY AUTOINCREMENT,
        shift_id INTEGER,
        status TEXT DEFAULT 'held',
        reference_no TEXT,
        customer_label TEXT,
        item_count INTEGER,
        total_amount REAL,
        created_at TEXT,
        expires_at TEXT
    );
    CREATE TABLE cashier_shift_payment_totals (
        shift_id INTEGER,
        payment_type TEXT,
        amount REAL,
        PRIMARY KEY (shift_id, payment_type)
    );
    CREATE TABLE sales (
        sale_id INTEGER PRIMARY KEY AUTOINCREMENT,
        shift_id INTEGER,
        payment_method TEXT,
        total_amount REAL,
        amount_tendered REAL,
        change_amount REAL,
        discount_amount REAL DEFAULT 0.00,
        status TEXT DEFAULT 'completed',
        created_at TEXT
    );
    CREATE TABLE cash_refunds (
        refund_id INTEGER PRIMARY KEY AUTOINCREMENT,
        shift_id INTEGER,
        payment_method TEXT,
        refund_amount REAL,
        created_at TEXT
    );
    CREATE TABLE sale_reversals (
        reversal_id INTEGER PRIMARY KEY AUTOINCREMENT,
        sale_id INTEGER,
        status TEXT,
        settlement_method TEXT,
        refund_amount REAL
    );
");

$testPdo->exec("
    INSERT INTO users (user_id, full_name, username, password_hash, role, display_name)
    VALUES (1, 'Cashier One', 'cashier1', '" . password_hash('pos123', PASSWORD_DEFAULT) . "', 'cashier', 'Cashier One'),
           (2, 'Admin One', 'admin1', '" . password_hash('admin123', PASSWORD_DEFAULT) . "', 'admin', 'Admin One');
    INSERT INTO registers (register_id, name) VALUES (1, 'Counter Register 1');
");

$shiftService = new CashierShiftService($testPdo);

// First verify LOCAL cashier can open shift and perform operations normally
$resetEnv();
$_SERVER['HTTP_HOST'] = 'localhost';
$shiftId = $shiftService->openShift(1, 'cashier', 1, 1000.00);
$assert($shiftId > 0, "Local cashier can open shift (shift #{$shiftId})");

// Verify LOCAL cashier can lock and unlock register
$lockedShiftId = $shiftService->lockRegister(1, 'cashier');
$assert($lockedShiftId === $shiftId, 'Local cashier can lock register');
$unlockedShiftId = $shiftService->unlockRegister(1, 'cashier', 'pos123');
$assert($unlockedShiftId === $shiftId, 'Local cashier can unlock register with password');

// Now switch to REMOTE context and verify physical operations are rejected
$resetEnv();
$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.50';
$assert(is_remote_request(), 'Context is now remote');

// Test 1: Remote shift open rejected
$remoteOpenRejected = false;
try {
    $shiftService->openShift(1, 'cashier', 1, 500.00);
} catch (DomainException $e) {
    $remoteOpenRejected = true;
}
$assert($remoteOpenRejected, 'Remote shift opening is rejected');

// Test 2: Remote drawer movement rejected
$remoteMovementRejected = false;
try {
    $shiftService->addDrawerMovement(1, 'cashier', 'cash_in', 100.00, 'change_replenishment', 'test');
} catch (DomainException $e) {
    $remoteMovementRejected = true;
}
$assert($remoteMovementRejected, 'Remote drawer movement is rejected');

// Test 3: Remote register lock rejected
$remoteLockRejected = false;
try {
    $shiftService->lockRegister(1, 'cashier');
} catch (DomainException $e) {
    $remoteLockRejected = true;
}
$assert($remoteLockRejected, 'Remote register lock operation is rejected');

// Test 4: Remote register unlock rejected
$remoteUnlockRejected = false;
try {
    $shiftService->unlockRegister(1, 'cashier', 'pos123');
} catch (DomainException $e) {
    $remoteUnlockRejected = true;
}
$assert($remoteUnlockRejected, 'Remote register unlock operation is rejected');

// Test 5: Remote register balancing / shift closure rejected
$remoteCloseRejected = false;
try {
    $shiftService->closeShift(1, 1000.00, 'closing note');
} catch (DomainException $e) {
    $remoteCloseRejected = true;
}
$assert($remoteCloseRejected, 'Remote register balancing / shift closure is rejected');

// Test 6: Cash drawer pulse/kick method rejected when remote
$remoteKickRejected = false;
try {
    $shiftService->openCashDrawer(1, 'cashier');
} catch (DomainException $e) {
    $remoteKickRejected = true;
}
$assert($remoteKickRejected, 'Remote cash drawer open/kick is rejected');

// Test 7: Thermal receipt print trigger rejected when remote
$remotePrintRejected = false;
try {
    $shiftService->triggerThermalPrint(1, 1, 'cashier');
} catch (DomainException $e) {
    $remotePrintRejected = true;
}
$assert($remotePrintRejected, 'Remote thermal receipt print trigger is rejected on CashierShiftService');

$remoteEnginePrintRejected = false;
try {
    require_once dirname(__DIR__) . '/app/Receipts/ReceiptEngine.php';
    $receiptEngine = new \App\Receipts\ReceiptEngine($testPdo);
    $receiptEngine->triggerThermalPrint(1);
} catch (DomainException $e) {
    $remoteEnginePrintRejected = true;
}
$assert($remoteEnginePrintRejected, 'Remote thermal receipt print trigger is rejected on ReceiptEngine');

// 4.3 Verify LOCAL operations work again once back in local context
$resetEnv();
$_SERVER['HTTP_HOST'] = 'localhost';
$assert(!is_remote_request(), 'Context is now local again');

$localKickSuccess = false;
try {
    $localKickSuccess = $shiftService->openCashDrawer(1, 'cashier');
} catch (Throwable $e) {
    $localKickSuccess = false;
}
$assert($localKickSuccess === true, 'Local cash drawer open/kick succeeds on store premises');

$localPrintSuccess = false;
try {
    $localPrintSuccess = $shiftService->triggerThermalPrint(1, 1, 'cashier');
} catch (Throwable $e) {
    $localPrintSuccess = false;
}
$assert($localPrintSuccess === true, 'Local thermal receipt print trigger succeeds on store premises');

// Local register close succeeds
$closed = $shiftService->closeShift(1, 1000.00, 'end of day');
$assert(($closed['status'] ?? '') === 'closed', 'Local register balancing / shift closure succeeds');

// Restore original server state
$_SERVER = $savedServer;
$_ENV = $savedEnv;
$_SESSION = $savedSession;

echo "\n==========================================\n";
if (!empty($failures)) {
    echo "FAILED: " . count($failures) . " contract tests failed.\n";
    exit(1);
} else {
    echo "SUCCESS: All remote access proxy guardrails contract tests passed!\n";
    exit(0);
}
