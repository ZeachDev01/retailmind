<?php

namespace App\Core;

final class RemoteIngress
{
    private static ?bool $remoteOverride = null;

    public static function isRemote(): bool
    {
        if (self::$remoteOverride !== null) {
            return self::$remoteOverride;
        }

        if (self::isTrue(Environment::get('RETAILMIND_REMOTE_INGRESS'))
            || self::isTrue($_SERVER['RETAILMIND_REMOTE_INGRESS'] ?? null)) {
            return true;
        }

        $remotePort = trim((string)Environment::get('RETAILMIND_REMOTE_PORT', ''));
        if ($remotePort !== '' && (string)($_SERVER['SERVER_PORT'] ?? '') === $remotePort) {
            return true;
        }

        $tunnelSecret = (string)Environment::get('RETAILMIND_TUNNEL_SECRET', '');
        $providedSecret = (string)($_SERVER['HTTP_X_RETAILMIND_TUNNEL_SECRET'] ?? '');
        if ($tunnelSecret !== '' && $providedSecret !== '' && hash_equals($tunnelSecret, $providedSecret)) {
            return true;
        }

        $remoteHost = strtolower(trim((string)Environment::get('RETAILMIND_REMOTE_HOST', '')));
        $currentHost = self::normalizeHost($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '');
        if ($remoteHost !== '' && $currentHost === $remoteHost) {
            return true;
        }

        return false;
    }

    public static function isRemoteMonitoringRequest(): bool
    {
        return self::isRemote();
    }

    public static function isOwnerEmailAllowed(): bool
    {
        $requiredEmail = strtolower(trim((string)Environment::get('RETAILMIND_REMOTE_OWNER_EMAIL', '')));
        if ($requiredEmail === '') {
            return true;
        }

        $provided = strtolower(trim((string)($_SERVER['HTTP_CF_ACCESS_AUTHENTICATED_USER_EMAIL'] ?? '')));
        return $provided !== '' && hash_equals($requiredEmail, $provided);
    }

    public static function isAccessTokenRequired(): bool
    {
        if (self::isTrue(Environment::get('RETAILMIND_REQUIRE_ACCESS_TOKEN'))
            || self::isTrue($_SERVER['RETAILMIND_REQUIRE_ACCESS_TOKEN'] ?? null)) {
            return true;
        }

        $expectedAud = trim((string)Environment::get('RETAILMIND_CF_ACCESS_AUD', ''));
        if ($expectedAud === '') {
            $expectedAud = trim((string)($_SERVER['RETAILMIND_CF_ACCESS_AUD'] ?? ''));
        }

        return $expectedAud !== '';
    }

    public static function base64UrlDecode(string $input): string|false
    {
        $remainder = strlen($input) % 4;
        if ($remainder) {
            $padlen = 4 - $remainder;
            $input .= str_repeat('=', $padlen);
        }
        return base64_decode(strtr($input, '-_', '+/'), true);
    }

    public static function base64UrlEncode(string $input): string
    {
        return rtrim(strtr(base64_encode($input), '+/', '-_'), '=');
    }

    public static function parseJwt(?string $jwt): ?array
    {
        if ($jwt === null || trim($jwt) === '') {
            return null;
        }

        $parts = explode('.', trim($jwt));
        if (count($parts) !== 3) {
            return null;
        }

        $headerJson = self::base64UrlDecode($parts[0]);
        $payloadJson = self::base64UrlDecode($parts[1]);
        if ($headerJson === false || $payloadJson === false) {
            return null;
        }

        $header = json_decode($headerJson, true);
        $payload = json_decode($payloadJson, true);
        if (!is_array($header) || !is_array($payload)) {
            return null;
        }

        return [
            'header' => $header,
            'payload' => $payload,
            'signature' => $parts[2],
            'raw_header' => $parts[0],
            'raw_payload' => $parts[1],
        ];
    }

    public static function isAccessTokenValid(?string $rawJwt = null): bool
    {
        $required = self::isAccessTokenRequired();
        $jwt = $rawJwt ?? (string)($_SERVER['HTTP_CF_ACCESS_JWT_ASSERTION'] ?? $_COOKIE['CF_Authorization'] ?? '');
        $jwt = trim($jwt);

        if ($jwt === '') {
            return !$required;
        }

        $parsed = self::parseJwt($jwt);
        if ($parsed === null) {
            return false;
        }

        $payload = $parsed['payload'];
        $now = time();

        if (isset($payload['exp']) && is_numeric($payload['exp'])) {
            if ((int)$payload['exp'] < ($now - 30)) {
                return false;
            }
        } elseif ($required) {
            return false;
        }

        if (isset($payload['nbf']) && is_numeric($payload['nbf'])) {
            if ((int)$payload['nbf'] > ($now + 60)) {
                return false;
            }
        }

        $expectedAud = trim((string)Environment::get('RETAILMIND_CF_ACCESS_AUD', ''));
        if ($expectedAud === '') {
            $expectedAud = trim((string)($_SERVER['RETAILMIND_CF_ACCESS_AUD'] ?? ''));
        }
        if ($expectedAud !== '') {
            $audClaim = $payload['aud'] ?? null;
            if (is_array($audClaim)) {
                if (!in_array($expectedAud, $audClaim, true)) {
                    return false;
                }
            } elseif (is_string($audClaim)) {
                if (!hash_equals($expectedAud, $audClaim)) {
                    return false;
                }
            } else {
                return false;
            }
        }

        $expectedTeam = trim((string)Environment::get('RETAILMIND_CF_ACCESS_TEAM', ''));
        if ($expectedTeam === '') {
            $expectedTeam = trim((string)($_SERVER['RETAILMIND_CF_ACCESS_TEAM'] ?? ''));
        }
        if ($expectedTeam !== '') {
            $iss = (string)($payload['iss'] ?? '');
            $expectedIss = 'https://' . strtolower($expectedTeam) . '.cloudflareaccess.com';
            if (strtolower(rtrim($iss, '/')) !== strtolower($expectedIss)) {
                return false;
            }
        }

        $requiredEmail = strtolower(trim((string)Environment::get('RETAILMIND_REMOTE_OWNER_EMAIL', '')));
        if ($requiredEmail !== '') {
            $tokenEmail = strtolower(trim((string)($payload['email'] ?? '')));
            if ($tokenEmail === '' || !hash_equals($requiredEmail, $tokenEmail)) {
                return false;
            }

            $headerEmail = strtolower(trim((string)($_SERVER['HTTP_CF_ACCESS_AUTHENTICATED_USER_EMAIL'] ?? '')));
            if ($headerEmail !== '' && !hash_equals($headerEmail, $tokenEmail)) {
                return false;
            }
        }

        $publicKey = Environment::get('RETAILMIND_CF_ACCESS_PUBKEY');
        if ($publicKey === null || $publicKey === '') {
            $publicKey = $_SERVER['RETAILMIND_CF_ACCESS_PUBKEY'] ?? null;
        }
        if ($publicKey !== null && trim((string)$publicKey) !== '') {
            $sig = self::base64UrlDecode($parsed['signature']);
            if ($sig === false) {
                return false;
            }
            $data = $parsed['raw_header'] . '.' . $parsed['raw_payload'];
            $verify = @openssl_verify($data, $sig, (string)$publicKey, OPENSSL_ALGO_SHA256);
            if ($verify !== 1) {
                return false;
            }
        }

        return true;
    }

    public static function isExportRequest(): bool
    {
        $action = strtolower(trim((string)($_POST['action'] ?? $_GET['action'] ?? '')));
        if (in_array($action, ['export', 'export_csv', 'export_pdf', 'download', 'backup', 'backup_download', 'dump'], true)) {
            return true;
        }

        $export = strtolower(trim((string)($_POST['export'] ?? $_GET['export'] ?? '')));
        if ($export !== '') {
            return true;
        }

        $format = strtolower(trim((string)($_POST['format'] ?? $_GET['format'] ?? '')));
        if (in_array($format, ['csv', 'sql', 'dump', 'zip', 'pdf', 'xlsx', 'excel'], true)) {
            return true;
        }

        foreach (['backup', 'restore', 'download', 'export'] as $key) {
            if (isset($_GET[$key]) || isset($_POST[$key])) {
                return true;
            }
        }

        return false;
    }

    public static function isSideEffectingAction(): bool
    {
        $normalized = self::normalizePath();
        $action = strtolower(trim((string)($_POST['action'] ?? $_GET['action'] ?? '')));
        if ($action === '') {
            return false;
        }

        if ($normalized === 'components/system_administrator/audit_logs.php'
            && strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'GET') {
            return false;
        }

        $disallowed = [
            'delete', 'destroy', 'remove', 'drop', 'clear', 'truncate',
            'update', 'edit', 'save', 'modify', 'set',
            'create', 'insert', 'add', 'new',
            'reset', 'restore', 'rollback',
            'refund', 'void', 'cancel',
            'open', 'close', 'reopen',
            'pay', 'checkout', 'complete',
            'backup', 'export', 'download',
        ];

        return in_array($action, $disallowed, true);
    }

    public static function isApprovedRoute(?string $path = null): bool
    {
        $normalized = self::normalizePath($path);

        if ($normalized === '' || $normalized === 'index.php') {
            return true;
        }

        if (in_array($normalized, [
            'components/administrator/dashboard.php',
            'components/administrator/shift_report.php',
            'components/cashier/shifts.php',
            'components/system_administrator/audit_logs.php',
            'components/modals/audit_log.php',
            'components/report/report_generation.php',
            'components/report/receiving_history.php',
            'components/report/forecast_analytics.php',
            'components/report/forecast_exceptions.php',
            'components/report/data_readiness.php',
            'components/report/predictions.php',
            'components/invoice/sales.php',
            'reports.php',
            'analytics.php',
        ], true)) {
            return true;
        }

        if (in_array($normalized, [
            'components/auth/logout.php',
            'components/auth/session_activity.php',
            'components/auth/workspace.php',
            'favicon.ico',
            'manifest.webmanifest',
            'sw.js',
        ], true)) {
            return true;
        }

        if (str_starts_with($normalized, 'assets/')) {
            return true;
        }

        return false;
    }

    public static function enforceBoundary(): void
    {
        if (PHP_SAPI === 'cli' && empty($_SERVER['RETAILMIND_TEST_ENFORCE_REMOTE'])) {
            return;
        }

        if (!self::isRemote()) {
            return;
        }

        if (!self::isOwnerEmailAllowed()) {
            self::deny('Access denied: unapproved owner identity on remote ingress.');
        }

        if (!self::isAccessTokenValid()) {
            self::deny('Access denied: missing or invalid Cloudflare Access token on remote ingress.');
        }

        if (!self::isApprovedRoute()) {
            self::deny('Access denied: unapproved route on remote ingress.');
        }

        if (self::isExportRequest()) {
            self::deny('Access denied: bulk export or download is unavailable on remote ingress.');
        }

        if (self::isSideEffectingAction()) {
            self::deny('Access denied: write actions and exports are disabled on remote ingress.');
        }

        $normalized = self::normalizePath();
        $allowedWriteRoutes = [
            '',
            'index.php',
            'components/auth/workspace.php',
            'components/auth/session_activity.php',
        ];
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (!in_array($method, ['GET', 'HEAD'], true) && !in_array($normalized, $allowedWriteRoutes, true)) {
            self::deny('Access denied: write actions and exports are disabled on remote ingress.');
        }
    }

    public static function normalizePath(?string $rawPath = null): string
    {
        $path = $rawPath ?? (string)($_SERVER['REQUEST_URI'] ?? $_SERVER['SCRIPT_NAME'] ?? '');
        $parsed = parse_url($path, PHP_URL_PATH);
        if (is_string($parsed)) {
            $path = $parsed;
        }
        $path = str_replace('\\', '/', $path);

        $prefixes = [
            '/retailmind/src/frontend',
            '/retailmind/frontend',
            '/retailmind',
            '/src/frontend',
            '/frontend',
        ];
        foreach ($prefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                $path = substr($path, strlen($prefix));
                break;
            }
        }

        return trim($path, '/');
    }

    public static function setRemoteOverride(?bool $isRemote): void
    {
        self::$remoteOverride = $isRemote;
    }

    public static function reset(): void
    {
        self::$remoteOverride = null;
    }

    private static function isTrue(mixed $value): bool
    {
        if ($value === null || $value === false || $value === '') {
            return false;
        }
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private static function normalizeHost(?string $host): string
    {
        $host = strtolower(trim((string)$host));
        if ($host === '') {
            return '';
        }
        if ($host[0] === '[') {
            $end = strpos($host, ']');
            return $end === false ? trim($host, '[]') : substr($host, 1, $end - 1);
        }
        if (substr_count($host, ':') === 1) {
            return explode(':', $host, 2)[0];
        }
        return $host;
    }

    private static function deny(string $message): void
    {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo $message;
        exit;
    }
}
