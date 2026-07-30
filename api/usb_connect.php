<?php
/**
 * Starts the Android USB scanner handoff for an existing pairing.
 *
 * This runs two ADB commands from the local XAMPP server:
 *   1. adb reverse tcp:<port> tcp:<port>
 *   2. adb shell am start ... <scanner-url>
 *
 * Android USB debugging and adb must already be available on the laptop.
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/pairing.php';

require_role(['admin', 'inventory_manager', 'cashier']);

function json_fail(string $message, int $status = 400, array $extra = []): void {
    http_response_code($status);
    echo json_encode(array_merge([
        'success' => false,
        'message' => $message,
    ], $extra));
    exit();
}

function find_adb_executable(): ?string {
    $candidates = [
        env('ADB_PATH', '') ?: null,
        getenv('ANDROID_HOME') ? rtrim(getenv('ANDROID_HOME'), "\\/") . '/platform-tools/adb.exe' : null,
        getenv('ANDROID_SDK_ROOT') ? rtrim(getenv('ANDROID_SDK_ROOT'), "\\/") . '/platform-tools/adb.exe' : null,
        getenv('LOCALAPPDATA') ? rtrim(getenv('LOCALAPPDATA'), "\\/") . '/Android/Sdk/platform-tools/adb.exe' : null,
        'adb',
    ];

    foreach ($candidates as $candidate) {
        if (!$candidate) {
            continue;
        }
        if ($candidate === 'adb' || is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function run_adb_command(string $adb, array $args): array {
    $output = [];
    $exitCode = 0;
    $command = escapeshellarg($adb);
    foreach ($args as $arg) {
        $command .= ' ' . escapeshellarg($arg);
    }
    exec($command . ' 2>&1', $output, $exitCode);

    return [
        'exit_code' => $exitCode,
        'output' => trim(implode("\n", $output)),
    ];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_fail('POST required.', 405);
}

if (!function_exists('exec')) {
    json_fail('PHP exec() is disabled, so the server cannot run adb.', 500);
}

$adb = find_adb_executable();
if (!$adb) {
    json_fail('adb.exe was not found. Install Android platform-tools or set ANDROID_HOME for Apache/PHP.', 500);
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
csrf_verify($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? null));
$code = strtoupper(trim($input['code'] ?? ''));
$scannerUrl = trim($input['scanner_url'] ?? '');
$port = (int)($input['port'] ?? ($_SERVER['SERVER_PORT'] ?? 80));

if ($code === '' || $scannerUrl === '') {
    json_fail('Pairing code and scanner URL are required.');
}

if ($port < 1 || $port > 65535) {
    json_fail('Invalid USB port.');
}

$urlParts = parse_url($scannerUrl);
$host = strtolower($urlParts['host'] ?? '');
$scheme = strtolower($urlParts['scheme'] ?? '');

if (!in_array($scheme, ['http', 'https'], true) || !in_array($host, ['localhost', '127.0.0.1'], true)) {
    json_fail('Only the generated USB localhost scanner URL can be opened.');
}

$pairing = pairing_find_by_code($pdo, $code);
if (!$pairing || (int)$pairing['created_by'] !== (int)$_SESSION['user_id']) {
    json_fail('That pairing code is invalid, expired, or not yours.', 403);
}

$devices = run_adb_command($adb, ['devices', '-l']);
if ($devices['exit_code'] !== 0) {
    json_fail('Could not check connected Android devices.', 500, [
        'adb_output' => $devices['output'],
    ]);
}
if (strpos($devices['output'], 'unauthorized') !== false) {
    json_fail('The phone/tablet is connected but not authorized. Unlock it and tap "Allow USB debugging", then try again.', 500, [
        'adb_output' => $devices['output'],
    ]);
}
if (!preg_match('/\bdevice\b/', $devices['output'])) {
    json_fail('No authorized Android phone/tablet was found. Check the cable and USB debugging.', 500, [
        'adb_output' => $devices['output'],
    ]);
}

$reverse = run_adb_command($adb, ['reverse', 'tcp:' . $port, 'tcp:' . $port]);
if ($reverse['exit_code'] !== 0) {
    json_fail('Could not start the wired ADB tunnel. Check that adb is installed, the phone is plugged in, and USB debugging is allowed.', 500, [
        'adb_output' => $reverse['output'],
    ]);
}

$open = run_adb_command($adb, ['shell', 'am', 'start', '-a', 'android.intent.action.VIEW', '-d', $scannerUrl]);
if ($open['exit_code'] !== 0) {
    json_fail('The wired tunnel started, but Android did not open the scanner automatically. Open the USB URL on the phone manually.', 500, [
        'adb_output' => $open['output'],
    ]);
}

echo json_encode([
    'success' => true,
    'message' => 'Wired connection started. The scanner should open on the phone/tablet.',
    'adb_output' => trim($reverse['output'] . "\n" . $open['output']),
]);
