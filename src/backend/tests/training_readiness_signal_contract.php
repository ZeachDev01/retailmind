<?php
// Contract for the Training Readiness notification decision:
//
// 1. A pure cold start (growing history, no recorded-data holes) is an informational status.
// 2. Missing evidence days are an actionable warning.
// 3. The first eligible Product produces a ready announcement, singular and plural.
// 4. The cold-start message reports progress through yesterday and the earliest projection.
// 5. A usable-day surplus still waits for the evaluation-window span.

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Forecasting/TrainingReadinessSignal.php';

use App\Forecasting\TrainingReadinessSignal;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
        fwrite(STDERR, "  fail - {$message}\n");
    } else {
        echo "  ok - {$message}\n";
    }
};

$youngRow = static fn(int $usableDays, int $spanDays): array => [
    'accumulated_usable_days' => $usableDays,
    'calendar_history_days' => $spanDays,
    'missing_evidence_days' => 0,
];

$coldstart = TrainingReadinessSignal::signal([$youngRow(23, 30), $youngRow(8, 12)], 0);
$assert($coldstart['key'] === 'coldstart', 'A pure cold start produces the coldstart signal');
$assert($coldstart['severity'] === 'info', 'A pure cold start is informational, not a warning');
$assert($coldstart['required'] === false, 'A pure cold start is not required work');
$assert(str_contains($coldstart['message'], '23/140'), 'Cold start message reports the most advanced Product');
$assert(str_contains($coldstart['message'], 'through ' . date('Y-m-d', strtotime('-1 day'))), 'Cold start message states the eligibility cutoff');
$assert(str_contains($coldstart['message'], 'import'), 'Cold start message keeps the history-import option visible');

$gaps = TrainingReadinessSignal::signal([
    $youngRow(40, 60),
    ['accumulated_usable_days' => 5, 'calendar_history_days' => 9, 'missing_evidence_days' => 2],
], 0);
$assert($gaps['key'] === 'readiness', 'Missing evidence produces the actionable readiness signal');
$assert($gaps['severity'] === 'warning', 'Missing evidence is a warning');
$assert($gaps['required'] === true, 'Missing evidence is required work');
$assert(str_contains($gaps['message'], '1 of 2'), 'Gap message counts affected Products');

$ready = TrainingReadinessSignal::signal([$youngRow(140, 140)], 1);
$assert($ready['key'] === 'ready', 'First eligible Product produces the ready signal');
$assert($ready['severity'] === 'info', 'Ready announcement is informational');
$assert($ready['count'] === 1, 'Ready signal counts eligible Products');
$assert(str_contains($ready['message'], '1 Product now meets'), 'Ready message handles the singular case');

$readyMany = TrainingReadinessSignal::signal([$youngRow(141, 141), $youngRow(150, 150)], 2);
$assert(str_contains($readyMany['message'], '2 Products now meet'), 'Ready message handles the plural case');

$holdoutBound = TrainingReadinessSignal::signal([$youngRow(140, 60)], 0);
$assert($holdoutBound['key'] === 'coldstart', 'A usable-day surplus still waits for evaluation-window span');
$assert(str_contains($holdoutBound['message'], 'Training becomes possible'), 'Cold start message keeps the earliest projection');

if ($failures) {
    fwrite(STDERR, "\nTraining readiness signal contract failed (" . count($failures) . " errors):\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "\nTraining readiness signal contract: passed\n";
