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
check(!RemoteIngress::isRemoteMonitoringRequest(), 'isRemoteMonitoringRequest matches isRemote when local');

// Arbitrary forwarding headers must not trigger remote or disguise local
$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.195';
$_SERVER['HTTP_X_FORWARDED_HOST'] = 'monitor.example.com';
$_SERVER['HTTP_X_REAL_IP'] = '203.0.113.195';
check(!RemoteIngress::isRemote(), 'Arbitrary forwarding headers must not make request remote');

unset($_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_X_FORWARDED_HOST'], $_SERVER['HTTP_X_REAL_IP']);

// Environment flag
$_SERVER['RETAILMIND_REMOTE_INGRESS'] = '1';
check(RemoteIngress::isRemote(), 'Server environment flag marks request as remote ingress');
check(RemoteIngress::isRemoteMonitoringRequest(), 'isRemoteMonitoringRequest matches isRemote when remote');
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

// Anti-spoofing: spoofed forwarding headers do not turn remote ingress into local trust
$_SERVER['HTTP_X_FORWARDED_FOR'] = '127.0.0.1';
$_SERVER['HTTP_X_FORWARDED_HOST'] = 'localhost';
$_SERVER['HTTP_X_REAL_IP'] = '127.0.0.1';
$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'http';
check(RemoteIngress::isRemote(), 'Spoofed forwarding headers cannot turn remote into local');
check(Session::shouldUseSecureCookie(), 'Secure cookie enforced despite spoofed http proto');

unset(
    $_ENV['RETAILMIND_REMOTE_HOST'],
    $_SERVER['HTTP_HOST'],
    $_SERVER['REMOTE_ADDR'],
    $_SERVER['HTTP_X_FORWARDED_FOR'],
    $_SERVER['HTTP_X_FORWARDED_HOST'],
    $_SERVER['HTTP_X_REAL_IP'],
    $_SERVER['HTTP_X_FORWARDED_PROTO']
);

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
    '/components/administrator/shift_report.php',
    '/retailmind/components/administrator/shift_report.php',
    '/components/cashier/shifts.php',
    '/retailmind/components/cashier/shifts.php',
    '/components/system_administrator/audit_logs.php',
    '/retailmind/components/system_administrator/audit_logs.php',
    '/components/modals/audit_log.php',
    '/retailmind/components/modals/audit_log.php',
    '/components/report/report_generation.php',
    '/retailmind/components/report/report_generation.php',
    '/components/report/receiving_history.php',
    '/retailmind/components/report/receiving_history.php',
    '/components/report/forecast_analytics.php',
    '/retailmind/components/report/forecast_analytics.php',
    '/components/report/forecast_exceptions.php',
    '/retailmind/components/report/forecast_exceptions.php',
    '/components/report/data_readiness.php',
    '/retailmind/components/report/data_readiness.php',
    '/components/report/predictions.php',
    '/retailmind/components/report/predictions.php',
    '/components/invoice/sales.php',
    '/retailmind/components/invoice/sales.php',
    '/reports.php',
    '/retailmind/reports.php',
    '/analytics.php',
    '/retailmind/analytics.php',
    '/components/auth/logout.php',
    '/retailmind/components/auth/logout.php',
    '/components/auth/session_activity.php',
    '/retailmind/components/auth/session_activity.php',
    '/components/auth/workspace.php',
    '/retailmind/components/auth/workspace.php',
    '/assets/css/style.css',
    '/retailmind/assets/css/style.css',
    '/assets/js/app.js',
    '/favicon.ico',
    '/manifest.webmanifest',
    '/sw.js',
] as $approved) {
    check(RemoteIngress::isApprovedRoute($approved), "Route {$approved} must be approved on remote ingress");
}

foreach ([
    '/components/cashier/pos.php',
    '/retailmind/components/cashier/pos.php',
    '/components/cashier/refund.php',
    '/retailmind/components/cashier/refund.php',
    '/components/inventory_management/products.php',
    '/retailmind/components/inventory_management/products.php',
    '/components/inventory_management/suppliers.php',
    '/components/inventory_management/categories.php',
    '/components/inventory_management/stock_adjustments.php',
    '/components/inventory_management/purchase_orders.php',
    '/components/administrator/users.php',
    '/retailmind/components/administrator/users.php',
    '/components/system_administrator/settings.php',
    '/components/backup/backup.php',
    '/retailmind/components/backup/backup.php',
    '/components/backup/recovery.php',
    '/api/backup.php',
    '/api/database_dump.php',
    '/export_reports.php',
    '/sales_export.php',
] as $unapproved) {
    check(!RemoteIngress::isApprovedRoute($unapproved), "Route {$unapproved} must be denied on remote ingress");
}

// 3. Export request and side-effecting GET action detection
check(!RemoteIngress::isExportRequest(), 'Normal request is not export');
$_POST['action'] = 'export_csv';
check(RemoteIngress::isExportRequest(), 'POST action=export_csv is export request');
unset($_POST['action']);

$_GET['export'] = 'csv';
check(RemoteIngress::isExportRequest(), 'GET export=csv is export request');
unset($_GET['export']);

$_GET['format'] = 'csv';
check(RemoteIngress::isExportRequest(), 'GET format=csv is export request');
unset($_GET['format']);

// Side-effecting actions masquerading as GET
$_GET['action'] = 'delete';
check(RemoteIngress::isSideEffectingAction(), 'GET action=delete is side-effecting');
$_GET['action'] = 'refund';
check(RemoteIngress::isSideEffectingAction(), 'GET action=refund is side-effecting');
$_GET['action'] = 'reset';
check(RemoteIngress::isSideEffectingAction(), 'GET action=reset is side-effecting');
$_GET['action'] = 'update';
check(RemoteIngress::isSideEffectingAction(), 'GET action=update is side-effecting');
unset($_GET['action']);

// 4. Database configuration uses Store PC database on public hostname with trusted config
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

// 5. Session cookie secure flag under remote ingress
$_SERVER['RETAILMIND_REMOTE_INGRESS'] = '1';
$_SERVER['HTTPS'] = 'off';
check(Session::shouldUseSecureCookie(), 'Remote ingress must enforce secure session cookie even over HTTP tunnel hop');
unset($_SERVER['RETAILMIND_REMOTE_INGRESS'], $_SERVER['HTTPS']);

// 6. Cloudflare Access token validation
function makeTestJwt(array $payload, ?string $privateKey = null, array $header = ['alg' => 'RS256', 'typ' => 'JWT']): string {
    $h = RemoteIngress::base64UrlEncode(json_encode($header));
    $p = RemoteIngress::base64UrlEncode(json_encode($payload));
    $sig = 'mock-sig';
    if ($privateKey !== null) {
        openssl_sign($h . '.' . $p, $rawSig, $privateKey, OPENSSL_ALGO_SHA256);
        $sig = RemoteIngress::base64UrlEncode($rawSig);
    }
    return $h . '.' . $p . '.' . $sig;
}

// Requirement flag
check(!RemoteIngress::isAccessTokenRequired(), 'Access token not required by default');
$_ENV['RETAILMIND_REQUIRE_ACCESS_TOKEN'] = '1';
check(RemoteIngress::isAccessTokenRequired(), 'RETAILMIND_REQUIRE_ACCESS_TOKEN=1 requires token');
unset($_ENV['RETAILMIND_REQUIRE_ACCESS_TOKEN']);

$_ENV['RETAILMIND_CF_ACCESS_AUD'] = 'aud-tag-12345';
check(RemoteIngress::isAccessTokenRequired(), 'Configured AUD requires token');
unset($_ENV['RETAILMIND_CF_ACCESS_AUD']);

// Parse JWT validation
check(RemoteIngress::parseJwt('') === null, 'Empty JWT parses to null');
check(RemoteIngress::parseJwt('invalid.token') === null, 'Invalid token segment count parses to null');
check(RemoteIngress::parseJwt('bad.base64.signature') === null, 'Bad base64 parses to null');

$validToken = makeTestJwt([
    'aud' => ['aud-tag-12345'],
    'email' => 'owner@retailmind.store',
    'exp' => time() + 3600,
    'nbf' => time() - 60,
    'iss' => 'https://retailmind-team.cloudflareaccess.com',
]);
$parsed = RemoteIngress::parseJwt($validToken);
check($parsed !== null && isset($parsed['payload']['email']), 'Valid JWT parses correctly');

// Absent token when required
$_ENV['RETAILMIND_REQUIRE_ACCESS_TOKEN'] = '1';
$_SERVER['HTTP_CF_ACCESS_JWT_ASSERTION'] = '';
check(!RemoteIngress::isAccessTokenValid(), 'Absent token rejected when required');

// Expired token rejected
$expiredToken = makeTestJwt([
    'aud' => ['aud-tag-12345'],
    'email' => 'owner@retailmind.store',
    'exp' => time() - 100,
    'nbf' => time() - 3600,
]);
check(!RemoteIngress::isAccessTokenValid($expiredToken), 'Expired token rejected');

// Future nbf token rejected
$futureToken = makeTestJwt([
    'aud' => ['aud-tag-12345'],
    'email' => 'owner@retailmind.store',
    'exp' => time() + 3600,
    'nbf' => time() + 300,
]);
check(!RemoteIngress::isAccessTokenValid($futureToken), 'Future nbf token rejected');

// AUD mismatch rejected
$_ENV['RETAILMIND_CF_ACCESS_AUD'] = 'expected-aud-12345';
$wrongAudToken = makeTestJwt([
    'aud' => ['wrong-aud'],
    'email' => 'owner@retailmind.store',
    'exp' => time() + 3600,
]);
check(!RemoteIngress::isAccessTokenValid($wrongAudToken), 'Mismatched AUD rejected');

$rightAudToken = makeTestJwt([
    'aud' => ['expected-aud-12345'],
    'email' => 'owner@retailmind.store',
    'exp' => time() + 3600,
]);
check(RemoteIngress::isAccessTokenValid($rightAudToken), 'Matching AUD accepted');
unset($_ENV['RETAILMIND_CF_ACCESS_AUD']);

// Team / issuer mismatch rejected
$_ENV['RETAILMIND_CF_ACCESS_TEAM'] = 'correct-team';
$wrongIssToken = makeTestJwt([
    'iss' => 'https://evil-team.cloudflareaccess.com',
    'email' => 'owner@retailmind.store',
    'exp' => time() + 3600,
]);
check(!RemoteIngress::isAccessTokenValid($wrongIssToken), 'Mismatched issuer/team rejected');

$rightIssToken = makeTestJwt([
    'iss' => 'https://correct-team.cloudflareaccess.com',
    'email' => 'owner@retailmind.store',
    'exp' => time() + 3600,
]);
check(RemoteIngress::isAccessTokenValid($rightIssToken), 'Matching issuer/team accepted');
unset($_ENV['RETAILMIND_CF_ACCESS_TEAM']);

// Email claim mismatch with owner email rejected
$_ENV['RETAILMIND_REMOTE_OWNER_EMAIL'] = 'owner@retailmind.store';
$wrongEmailToken = makeTestJwt([
    'email' => 'attacker@retailmind.store',
    'exp' => time() + 3600,
]);
check(!RemoteIngress::isAccessTokenValid($wrongEmailToken), 'Mismatched token email rejected');

// Header email vs token email mismatch rejected
$_SERVER['HTTP_CF_ACCESS_AUTHENTICATED_USER_EMAIL'] = 'attacker@retailmind.store';
$matchingEmailToken = makeTestJwt([
    'email' => 'owner@retailmind.store',
    'exp' => time() + 3600,
]);
check(!RemoteIngress::isAccessTokenValid($matchingEmailToken), 'Mismatch between header email and token email rejected');

$_SERVER['HTTP_CF_ACCESS_AUTHENTICATED_USER_EMAIL'] = 'owner@retailmind.store';
check(RemoteIngress::isAccessTokenValid($matchingEmailToken), 'Matching header and token email accepted');
unset($_ENV['RETAILMIND_REMOTE_OWNER_EMAIL'], $_SERVER['HTTP_CF_ACCESS_AUTHENTICATED_USER_EMAIL'], $_ENV['RETAILMIND_REQUIRE_ACCESS_TOKEN']);

// Cryptographic signature verification with OpenSSL
$privKey = "-----BEGIN PRIVATE KEY-----\nMIICdgIBADANBgkqhkiG9w0BAQEFAASCAmAwggJcAgEAAoGBAMbLpJZ9kNFx6+pL\nYnDY4WVb9jPiUFzYze2FpPgirAxNUyqSC27U1coTupDy9Y7jVbLGwNVX0/TFTJlD\npHjKHeMlz3blFs+RC25Y2lZoK8gnHvTdUVaKDo4DiSaoLKu5yYw1IJMNZi+I8WkT\ne05RzH9B/eH9l3EJGLx7FhGTglqjAgMBAAECgYAG+T/V06PP5RoshuZ2JPWLM7Wo\nTkkZaLWYnnaSCc1VRSUkuijSg37Ybua9jPhWQd1Qvy4mjcUusfkk6z2KyzRPIrse\nIOM90uSoWtC9OjbtEiJPzXvJ7Prp2xOEPpg/5mCCWoRfaPNgyywPKWREAnQn+xqr\nq3O3tEiIlseMS/omAQJBAO6ynHij1tmIy99ZTjsczosGD88clkXPAv2aNVyrI7tJ\nwp2rIYeTgkIHD9pPAzuVL/JmjYb00gWZ1+wKgC28z6MCQQDVNJZGb8ZCUEaOUQi8\n6L/PnGjPANCpQ1jqY0U+FrBYbTi4ARaC843s9XJWvsny5hXS6jOZx5KnRORpnYr9\n8PkBAkAiDBANlTEcLksGPLLP87fyVJcEr8svgeunSMu7wB2EpWzBGJvbvsqhqGg2\nd3a6lPkV1lLxoRIkAmGuJYFLBNeJAkEAuCuo9Edxl68Ig790MNtNdeXCk648kTpg\nAHioQ64NvxT6PdiSvaPuIZcUoeJFSNGAYKV21YwUHvHf7WvtuNcEAQJAfmSeQjDh\nxleE62K0ILZzOYzTj9jk0zu3LsqDsPJIwJBFofFvtHWW77V6wKWDVZhuJcjygjKm\nRnnlGTR86eIx/g==\n-----END PRIVATE KEY-----\n";
$pubKey = "-----BEGIN PUBLIC KEY-----\nMIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQDGy6SWfZDRcevqS2Jw2OFlW/Yz\n4lBc2M3thaT4IqwMTVMqkgtu1NXKE7qQ8vWO41WyxsDVV9P0xUyZQ6R4yh3jJc92\n5RbPkQtuWNpWaCvIJx703VFWig6OA4kmqCyrucmMNSCTDWYviPFpE3tOUcx/Qf3h\n/ZdxCRi8exYRk4JaowIDAQAB\n-----END PUBLIC KEY-----\n";

$_ENV['RETAILMIND_CF_ACCESS_PUBKEY'] = $pubKey;
$signedToken = makeTestJwt([
    'email' => 'owner@retailmind.store',
    'exp' => time() + 3600,
], $privKey);
check(RemoteIngress::isAccessTokenValid($signedToken), 'Valid RS256 signature accepted');

$tamperedToken = substr($signedToken, 0, -5) . 'aaaaa';
check(!RemoteIngress::isAccessTokenValid($tamperedToken), 'Tampered RS256 signature rejected');
unset($_ENV['RETAILMIND_CF_ACCESS_PUBKEY']);

echo "Owner remote monitoring contract: passed\n";
