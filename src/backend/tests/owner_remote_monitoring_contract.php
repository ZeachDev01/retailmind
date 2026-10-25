<?php

require_once __DIR__ . '/../bootstrap/app.php';

use App\Core\Environment;
use App\Core\RemoteIngress;
use App\Core\Session;

function check(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $message);
    }
}

// 1. Distinguishing remote ingress without trusting arbitrary forwarding headers or loopback address
RemoteIngress::reset();
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_HOST'] = 'localhost';
check(!RemoteIngress::isRemote(), 'Localhost with loopback address must be local');

// Arbitrary forwarding headers must not trigger remote or disguise local
$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.195';
$_SERVER['HTTP_X_FORWARDED_HOST'] = 'monitor.example.com';
$_SERVER['HTTP_X_REAL_IP'] = '203.0.113.195';
check(!RemoteIngress::isRemote(), 'Arbitrary forwarding headers must not make request remote');

unset($_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_X_FORWARDED_HOST'], $_SERVER['HTTP_X_REAL_IP']);

// Environment flag
$_SERVER['RETAILMIND_REMOTE_INGRESS'] = '1';
check(RemoteIngress::isRemote(), 'Server environment flag marks request as remote ingress');
unset($_SERVER['RETAILMIND_REMOTE_INGRESS']);

// Dedicated listener port
$_ENV['RETAILMIND_REMOTE_PORT'] = '8081';
$_SERVER['SERVER_PORT'] = '8080';
check(!RemoteIngress::isRemote(), 'Different port is not remote ingress');
$_SERVER['SERVER_PORT'] = '8081';
check(RemoteIngress::isRemote(), 'Configured listener port marks request as remote ingress');
unset($_ENV['RETAILMIND_REMOTE_PORT'], $_SERVER['SERVER_PORT']);

// Shared tunnel secret
$_ENV['RETAILMIND_TUNNEL_SECRET'] = 'secret-token-xyz';
$_SERVER['HTTP_X_RETAILMIND_TUNNEL_SECRET'] = 'wrong-token';
check(!RemoteIngress::isRemote(), 'Mismatched tunnel secret is not remote ingress');
$_SERVER['HTTP_X_RETAILMIND_TUNNEL_SECRET'] = 'secret-token-xyz';
check(RemoteIngress::isRemote(), 'Matching tunnel secret marks request as remote ingress');
unset($_ENV['RETAILMIND_TUNNEL_SECRET'], $_SERVER['HTTP_X_RETAILMIND_TUNNEL_SECRET']);

// Configured remote host
$_ENV['RETAILMIND_REMOTE_HOST'] = 'monitor.retailmind.store';
$_SERVER['HTTP_HOST'] = 'localhost';
check(!RemoteIngress::isRemote(), 'Localhost does not match remote host');
$_SERVER['HTTP_HOST'] = 'monitor.retailmind.store';
check(RemoteIngress::isRemote(), 'Matching remote host marks request as remote ingress');

// Loopback address with remote host must still be recognized as remote
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
check(RemoteIngress::isRemote(), 'Proxy loopback address with remote host stays remote');
unset($_ENV['RETAILMIND_REMOTE_HOST'], $_SERVER['HTTP_HOST'], $_SERVER['REMOTE_ADDR']);

// Cloudflare Access owner email check
$_SERVER['RETAILMIND_REMOTE_INGRESS'] = '1';
$_ENV['RETAILMIND_REMOTE_OWNER_EMAIL'] = 'owner@retailmind.store';
$_SERVER['HTTP_CF_ACCESS_AUTHENTICATED_USER_EMAIL'] = 'intruder@retailmind.store';
check(!RemoteIngress::isOwnerEmailAllowed(), 'Unapproved Access email must be rejected');
$_SERVER['HTTP_CF_ACCESS_AUTHENTICATED_USER_EMAIL'] = 'owner@retailmind.store';
check(RemoteIngress::isOwnerEmailAllowed(), 'Approved Access email must be allowed');
unset($_SERVER['RETAILMIND_REMOTE_INGRESS'], $_ENV['RETAILMIND_REMOTE_OWNER_EMAIL'], $_SERVER['HTTP_CF_ACCESS_AUTHENTICATED_USER_EMAIL']);

// 2. Approved vs unapproved routes on remote ingress
foreach ([
    '/',
    '/index.php',
    '/retailmind/',
    '/retailmind/index.php',
    '/components/administrator/dashboard.php',
    '/retailmind/components/administrator/dashboard.php',
    '/components/auth/logout.php',
    '/components/auth/session_activity.php',
    '/components/auth/change_password.php',
    '/components/auth/workspace.php',
    '/components/theme.php',
    '/assets/css/style.css',
    '/assets/img/logo.png',
    '/sw.js',
    '/manifest.webmanifest',
] as $approved) {
    check(RemoteIngress::isApprovedRoute($approved), "Route {$approved} must be approved on remote ingress");
}

foreach ([
    '/components/cashier/pos.php',
    '/components/cashier/refunds.php',
    '/components/cashier/shifts.php',
    '/components/inventory_management/stock_issues.php',
    '/components/system_administrator/backup_restore.php',
    '/components/system_administrator/system_settings.php',
    '/components/user_manager/user_manager.php',
    '/components/administrator/reset_data.php',
    '/components/administrator/registers.php',
    '/backend/scripts/restore_database.php',
] as $unapproved) {
    check(!RemoteIngress::isApprovedRoute($unapproved), "Route {$unapproved} must be denied on remote ingress");
}

// 3. Database configuration uses Store PC database on public hostname with trusted config
$_ENV['DATABASE_ENV'] = 'local';
$_SERVER['HTTP_HOST'] = 'monitor.retailmind.store';
$dbConfig = require __DIR__ . '/../config/database.php';
check($dbConfig['host'] === 'localhost', 'Local Store PC database host must be used');
check($dbConfig['database'] === 'inventory_system', 'Local Store PC database name must be used');

$_ENV['DATABASE_ENV'] = 'hosted';
$_ENV['DB_HOST'] = 'sql.cloud.test';
$_ENV['DB_NAME'] = 'cloud_db';
$_ENV['DB_USER'] = 'cloud_user';
$_ENV['DB_PASSWORD'] = 'cloud_pass';
$dbConfig = require __DIR__ . '/../config/database.php';
check($dbConfig['database'] === 'cloud_db', 'Hosted database must be selected when DATABASE_ENV=hosted');
unset($_ENV['DATABASE_ENV'], $_ENV['DB_HOST'], $_ENV['DB_NAME'], $_ENV['DB_USER'], $_ENV['DB_PASSWORD'], $_SERVER['HTTP_HOST']);

// 4. Session cookie secure flag under remote ingress
$_SERVER['RETAILMIND_REMOTE_INGRESS'] = '1';
$_SERVER['HTTPS'] = 'off';
check(Session::shouldUseSecureCookie(), 'Remote ingress must enforce secure session cookie even over HTTP tunnel hop');
unset($_SERVER['RETAILMIND_REMOTE_INGRESS'], $_SERVER['HTTPS']);

echo "Owner remote monitoring contract: passed\n";
