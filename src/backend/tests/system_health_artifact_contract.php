<?php
require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../app/Services/SystemHealthService.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$backendPath = sys_get_temp_dir() . '/retailmind-health-artifact-' . bin2hex(random_bytes(6));
$artifactDir = $backendPath . '/storage/ml_models/store/0123456789abcdef';
mkdir($artifactDir, 0700, true);

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$artifactCheck = static function () use ($pdo, $backendPath): ?array {
    $checks = (new SystemHealthService($pdo, $backendPath))->checks();
    return array_values(array_filter(
        $checks,
        static fn(array $check): bool => $check['name'] === 'Forecast model artifact'
    ))[0] ?? null;
};

$missing = $artifactCheck();
$assert(is_array($missing), 'System Health must expose a Forecast model artifact check');
$assert($missing['status'] === 'warning', 'Missing model directory must require attention');

$artifact = $artifactDir . '/random_forest_candidate_store-20261109.joblib';
file_put_contents($artifact, 'candidate');
$nested = $artifactCheck();
$assert($nested['status'] === 'healthy', 'A nested per-dataset artifact must be reported healthy');
$assert(str_contains($nested['detail'], basename($artifact)), 'Health detail must name the latest artifact');

file_put_contents($artifactDir . '/notes.txt', 'ignored');
$assert($artifactCheck()['status'] === 'healthy', 'Non-artifact files must not break the check');

unlink($artifact);
unlink($artifactDir . '/notes.txt');
$empty = $artifactCheck();
$assert($empty['status'] === 'warning', 'Empty model storage must require attention');

rmdir($artifactDir);
rmdir($backendPath . '/storage/ml_models/store');
rmdir($backendPath . '/storage/ml_models');
rmdir($backendPath . '/storage');
rmdir($backendPath);

echo "System Health artifact contract: passed\n";
