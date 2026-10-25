<?php

/**
 * RetailMind Cloudflare Monitoring Verification Script
 * Validates cloudflared ingress configuration, environment flags, and Access token gate.
 *
 * Usage:
 *   php scripts/verify-cloudflare-monitoring.php [--config=path/to/config.yml]
 */

require_once __DIR__ . '/../src/backend/bootstrap/app.php';

use App\Core\Environment;
use App\Core\RemoteIngress;

$options = getopt('', ['config::', 'help']);
if (isset($options['help'])) {
    echo "Usage: php scripts/verify-cloudflare-monitoring.php [--config=path/to/config.yml]\n";
    exit(0);
}

$configPath = $options['config'] ?? null;
if (!$configPath) {
    $candidates = [
        'C:/ProgramData/cloudflared/config.yml',
        __DIR__ . '/../deploy/cloudflare/config.example.yml',
    ];
    foreach ($candidates as $cand) {
        if (file_exists($cand)) {
            $configPath = $cand;
            break;
        }
    }
}

echo "=== RetailMind Cloudflare Monitoring Verification ===\n";
$allPassed = true;

// 1. Verify cloudflared config
echo "[1/4] Checking cloudflared ingress configuration...\n";
if (!$configPath || !file_exists($configPath)) {
    echo "  WARN: cloudflared config not found at " . ($configPath ?? 'default locations') . "\n";
} else {
    $rawContent = file_get_contents($configPath);
    // Strip comments to inspect actual configuration directives
    $content = preg_replace('/#[^\r\n]*/', '', $rawContent);
    $has404 = preg_match('/service:\s*http_status:404/', $content) === 1;
    $noDbExposure = !preg_match('/(3306|mysql)/i', $content);
    $hasAccessRequired = preg_match('/access:\s*[\r\n]+\s*required:\s*true/i', $content) === 1
        || preg_match('/required:\s*true/i', $content) === 1;
    $isLoopbackOnly = preg_match('/service:\s*https?:\/\/(127\.0\.0\.1|localhost):/i', $content) === 1;

    echo "  - Catch-all 404 rule: " . ($has404 ? "OK" : "FAIL (missing http_status:404 catch-all)") . "\n";
    echo "  - No database exposure: " . ($noDbExposure ? "OK" : "FAIL (database port exposed)") . "\n";
    echo "  - Tunnel Access token check: " . ($hasAccessRequired ? "OK" : "FAIL (access.required: true missing)") . "\n";
    echo "  - Loopback-only service: " . ($isLoopbackOnly ? "OK" : "FAIL (ingress not bound to loopback)") . "\n";

    if (!$has404 || !$noDbExposure || !$hasAccessRequired || !$isLoopbackOnly) {
        $allPassed = false;
    }
}

// 2. Verify environment configuration
echo "[2/4] Checking environment configuration...\n";
$ownerEmail = Environment::get('RETAILMIND_REMOTE_OWNER_EMAIL');
$remoteHost = Environment::get('RETAILMIND_REMOTE_HOST');
$aud = Environment::get('RETAILMIND_CF_ACCESS_AUD');

echo "  - RETAILMIND_REMOTE_OWNER_EMAIL: " . ($ownerEmail ? "CONFIGURED (" . htmlspecialchars((string)$ownerEmail) . ")" : "UNSET (using template/header validation)") . "\n";
echo "  - RETAILMIND_REMOTE_HOST: " . ($remoteHost ? "CONFIGURED (" . htmlspecialchars((string)$remoteHost) . ")" : "UNSET (using port/secret ingress)") . "\n";
echo "  - RETAILMIND_CF_ACCESS_AUD: " . ($aud ? "CONFIGURED" : "UNSET (Access token AUD check optional/inactive)") . "\n";

// 3. Verify Access token gate logic
echo "[3/4] Testing Access token and email gate validation logic...\n";
$now = time();
$b64u = static fn(string $v): string => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');

$testEmail = 'owner-test@store.example';
$testAud = 'test-aud-12345';
$_ENV['RETAILMIND_REMOTE_OWNER_EMAIL'] = $testEmail;
$_ENV['RETAILMIND_CF_ACCESS_AUD'] = $testAud;
$_ENV['RETAILMIND_REQUIRE_ACCESS_TOKEN'] = '1';

// Test valid token
$validPayload = ['email' => $testEmail, 'aud' => [$testAud], 'exp' => $now + 3600, 'nbf' => $now - 60];
$validJwt = $b64u(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . $b64u(json_encode($validPayload)) . '.testsig';
$_SERVER['HTTP_CF_ACCESS_AUTHENTICATED_USER_EMAIL'] = $testEmail;
$validPasses = RemoteIngress::isOwnerEmailAllowed() && RemoteIngress::isAccessTokenValid($validJwt);

// Test unapproved email
$_SERVER['HTTP_CF_ACCESS_AUTHENTICATED_USER_EMAIL'] = 'intruder@store.example';
$unapprovedRejected = !RemoteIngress::isOwnerEmailAllowed();

// Test expired token
$expiredPayload = ['email' => $testEmail, 'aud' => [$testAud], 'exp' => $now - 100];
$expiredJwt = $b64u(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . $b64u(json_encode($expiredPayload)) . '.testsig';
$expiredRejected = !RemoteIngress::isAccessTokenValid($expiredJwt);

// Test mismatched aud
$wrongAudPayload = ['email' => $testEmail, 'aud' => ['wrong-aud'], 'exp' => $now + 3600];
$wrongAudJwt = $b64u(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . $b64u(json_encode($wrongAudPayload)) . '.testsig';
$wrongAudRejected = !RemoteIngress::isAccessTokenValid($wrongAudJwt);

// Cleanup
unset(
    $_ENV['RETAILMIND_REMOTE_OWNER_EMAIL'],
    $_ENV['RETAILMIND_CF_ACCESS_AUD'],
    $_ENV['RETAILMIND_REQUIRE_ACCESS_TOKEN'],
    $_SERVER['HTTP_CF_ACCESS_AUTHENTICATED_USER_EMAIL']
);

echo "  - Valid token & email gate: " . ($validPasses ? "PASS" : "FAIL") . "\n";
echo "  - Unapproved email rejection: " . ($unapprovedRejected ? "PASS" : "FAIL") . "\n";
echo "  - Expired token rejection: " . ($expiredRejected ? "PASS" : "FAIL") . "\n";
echo "  - Mismatched AUD rejection: " . ($wrongAudRejected ? "PASS" : "FAIL") . "\n";

if (!$validPasses || !$unapprovedRejected || !$expiredRejected || !$wrongAudRejected) {
    $allPassed = false;
}

// 4. Remote read-only boundary route audit
echo "[4/4] Testing remote read-only route approvals...\n";
$routesApproved = RemoteIngress::isApprovedRoute('components/administrator/dashboard.php')
    && RemoteIngress::isApprovedRoute('components/administrator/shift_report.php')
    && RemoteIngress::isApprovedRoute('components/cashier/shifts.php')
    && !RemoteIngress::isApprovedRoute('components/cashier/pos.php')
    && !RemoteIngress::isApprovedRoute('components/backup/backup.php');

echo "  - Approved/denied route boundary: " . ($routesApproved ? "PASS" : "FAIL") . "\n";
if (!$routesApproved) {
    $allPassed = false;
}

echo "------------------------------------------------------\n";
if ($allPassed) {
    echo "STATUS: ALL CHECKS PASSED. Cloudflare monitoring ingress is secure.\n";
    exit(0);
} else {
    echo "STATUS: ONE OR MORE CHECKS FAILED. Review configuration above.\n";
    exit(1);
}
