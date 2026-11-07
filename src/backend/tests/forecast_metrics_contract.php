<?php
declare(strict_types=1);

/**
 * Contract for issue #431: one shared readiness-band definition and one shared
 * metric implementation for PHP services, PHP views, and the Python worker.
 */

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Forecasting/ForecastMetrics.php';

use App\Forecasting\ForecastMetrics;

$failures = [];
$assert = function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
        echo "  [FAIL] {$message}\n";
    } else {
        echo "  [PASS] {$message}\n";
    }
};
$assert_same = function ($expected, $actual, string $message) use (&$failures): void {
    if ($expected !== $actual) {
        $failures[] = $message;
        echo "  [FAIL] {$message} (expected " . var_export($expected, true)
            . ", got " . var_export($actual, true) . ")\n";
    } else {
        echo "  [PASS] {$message}\n";
    }
};
$assert_close = function (?float $expected, ?float $actual, string $message) use (&$failures): void {
    $ok = ($expected === null || $actual === null)
        ? $expected === $actual
        : abs($expected - $actual) < 1e-9;
    if (!$ok) {
        $failures[] = $message;
        echo "  [FAIL] {$message} (expected " . var_export($expected, true)
            . ", got " . var_export($actual, true) . ")\n";
    } else {
        echo "  [PASS] {$message}\n";
    }
};

echo "Testing App\\Forecasting\\ForecastMetrics (issue #431)...\n\n";

// --- Shared data file is the single source of band values -------------------
echo "--- Shared band definition ---\n";
$bandsPath = __DIR__ . '/../config/forecast_readiness_bands.json';
$bandsJson = json_decode((string)file_get_contents($bandsPath), true);
$assert(is_array($bandsJson), 'Shared band file parses as JSON');
$assert_same(56, $bandsJson['thresholds']['low'] ?? null, 'Low threshold is 56');
$assert_same(180, $bandsJson['thresholds']['medium'] ?? null, 'Medium threshold is 180');
$assert_same(365, $bandsJson['thresholds']['high'] ?? null, 'High threshold is 365');
$assert_same(true, $bandsJson['high_requires_qualification'] ?? null, 'High band requires qualification');

// --- Band boundaries and qualification nuance -------------------------------
echo "\n--- Readiness boundaries (ADR-0005) ---\n";
$assert_same('Insufficient', ForecastMetrics::readinessLevel(55, true)['level'], '55 usable days is Insufficient');
$assert_same('Low', ForecastMetrics::readinessLevel(56, true)['level'], '56 usable days is Low');
$assert_same('Low', ForecastMetrics::readinessLevel(179, true)['level'], '179 usable days is Low');
$assert_same('Medium', ForecastMetrics::readinessLevel(180, true)['level'], '180 usable days is Medium');
$assert_same('Medium', ForecastMetrics::readinessLevel(364, true)['level'], '364 usable days is Medium');
$assert_same('High', ForecastMetrics::readinessLevel(365, true)['level'], '365 qualified usable days is High');
$assert_same('High', ForecastMetrics::readinessLevel(400, true)['level'], '400 qualified usable days is High');

// Qualification only gates the High band; below the High threshold an unqualified
// count still reads its own band.
$assert_same('Insufficient', ForecastMetrics::readinessLevel(55, false)['level'], 'Qualification does not change Insufficient');
$assert_same('Low', ForecastMetrics::readinessLevel(56, false)['level'], 'Qualification does not change Low');
$assert_same('Medium', ForecastMetrics::readinessLevel(180, false)['level'], 'Qualification does not change Medium band');
$assert_same('Medium', ForecastMetrics::readinessLevel(364, false)['level'], 'Qualification does not change Medium band at 364');
$assert_same('Medium', ForecastMetrics::readinessLevel(365, false)['level'], '365 unqualified stays Medium (Python nuance)');
$assert_same('Medium', ForecastMetrics::readinessLevel(400, false)['level'], '400 unqualified stays Medium (Python nuance)');

// --- Limitation markers -----------------------------------------------------
echo "\n--- Limitation markers ---\n";
$assert_same('Usable history under 56 days (minimum required for reliable forecasting)', ForecastMetrics::readinessLevel(10)['limitation_marker'], 'Insufficient limitation text');
$assert_same('Early history (56-179 days)', ForecastMetrics::readinessLevel(100)['limitation_marker'], 'Low limitation text');
$assert_same('Standard history (180-364 days)', ForecastMetrics::readinessLevel(200)['limitation_marker'], 'Medium limitation text');
$assert_same(null, ForecastMetrics::readinessLevel(400, true)['limitation_marker'], 'High has no limitation mark');
$assert_same('Standard history (180-364 days)', ForecastMetrics::readinessLevel(400, false)['limitation_marker'], 'Unqualified 400 keeps Medium limitation text');

// --- Metric formulas --------------------------------------------------------
echo "\n--- Metric formulas ---\n";
$actuals = [10, 20, 30];
$preds = [12, 18, 33];
$assert_close(7 / 3, ForecastMetrics::mae($actuals, $preds), 'MAE is mean absolute error');
$assert_close(sqrt(17 / 3), ForecastMetrics::rmse($actuals, $preds), 'RMSE is root mean squared error');
$assert_close(7 / 60, ForecastMetrics::wape($actuals, $preds), 'WAPE is total absolute error over total actual');
$assert_close(5.0, ForecastMetrics::mae([0, 0], [5, 5]), 'MAE is finite when actuals are zero');
$assert_same(null, ForecastMetrics::wape([0, 0], [5, 5]), 'WAPE is null when the actual total is zero');
$assert_same(null, ForecastMetrics::mae([], []), 'MAE is null without comparisons');
$assert_same(null, ForecastMetrics::rmse([], []), 'RMSE is null without comparisons');
$assert_same(null, ForecastMetrics::wape([], []), 'WAPE is null without comparisons');
$assert_same(null, ForecastMetrics::wape([1, 2], [1]), 'WAPE is null on mismatched pairs');

// --- The Python worker reads the same definition ----------------------------
echo "\n--- Python worker agreement ---\n";
$scriptsDir = realpath(__DIR__ . '/../scripts');
$workerSource = (string)file_get_contents($scriptsDir . '/ml_worker.py');
$assert(!str_contains($workerSource, 'days < 56'), 'Worker band logic has no literal 56');
$assert(!str_contains($workerSource, 'days < 180'), 'Worker band logic has no literal 180');
$assert(!str_contains($workerSource, 'days >= 365'), 'Worker band logic has no literal 365');

$cases = [[55, true], [55, false], [56, true], [179, true], [180, true], [180, false],
          [364, true], [365, true], [365, false], [400, true], [400, false]];
$pyCode = <<<'PY'
import sys, json
sys.path.insert(0, sys.argv[1])
import ml_worker as worker
cases = [(55, True), (55, False), (56, True), (179, True), (180, True), (180, False),
         (364, True), (365, True), (365, False), (400, True), (400, False)]
print(json.dumps([[d, q, worker.resolve_readiness(d, q)[0]] for d, q in cases]))
PY;
$tmpScript = tempnam(sys_get_temp_dir(), 'readiness_bands_') . '.py';
file_put_contents($tmpScript, $pyCode);
$command = 'python ' . escapeshellarg($tmpScript) . ' ' . escapeshellarg((string)$scriptsDir);
$output = shell_exec($command);
unlink($tmpScript);

$pythonDecisions = json_decode(trim((string)$output), true);
$assert(is_array($pythonDecisions) && count($pythonDecisions) === count($cases),
    'Python worker printed band decisions for every boundary case');

if (is_array($pythonDecisions)) {
    foreach ($pythonDecisions as $decision) {
        [$days, $qualified, $level] = $decision;
        $qualified = (bool)$qualified;
        $phpLevel = ForecastMetrics::readinessLevel((int)$days, $qualified)['level'];
        $assert_same($phpLevel, (string)$level, "Python and PHP agree on {$days} days, qualified=" . ($qualified ? 'true' : 'false'));
    }
}

echo "\n========================================================\n";
if ($failures === []) {
    echo "PASS: ForecastMetrics contract (issue #431) passed cleanly!\n";
    echo "========================================================\n";
    exit(0);
}

echo "FAIL: " . count($failures) . " check(s) failed in ForecastMetrics contract:\n";
foreach ($failures as $failure) {
    echo " - {$failure}\n";
}
echo "========================================================\n";
exit(1);
