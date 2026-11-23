<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap/app.php';
use App\Forecasting\ForecastingSchedulerService;

$logs = sys_get_temp_dir() . '/rm-health-' . bin2hex(random_bytes(5));
mkdir($logs);
$now = new DateTimeImmutable('2026-11-23 12:00:00', new DateTimeZone('Asia/Manila'));
$check = static function ($expected, $actual): void {
    if ($expected !== $actual) throw new RuntimeException("Expected $expected, got $actual");
};
try {
    $check('unavailable', ForecastingSchedulerService::getSchedulerHealth($logs, $now)['state']);
    ForecastingSchedulerService::recordSchedulerCheck(0, null, $logs, $now);
    $health = ForecastingSchedulerService::getSchedulerHealth($logs, $now->modify('+5 minutes'));
    $check('idle', $health['state']);
    $check('2026-11-23 12:00:00', $health['checked_at']);
    $check('stale', ForecastingSchedulerService::getSchedulerHealth($logs, $now->modify('+16 minutes'))['state']);
    ForecastingSchedulerService::recordSchedulerCheck(1, null, $logs, $now);
    $check('work_pending', ForecastingSchedulerService::getSchedulerHealth($logs, $now)['state']);
    ForecastingSchedulerService::recordSchedulerCheck(0, 'WORKER_STARTUP_FAILED', $logs, $now);
    $check('unavailable', ForecastingSchedulerService::getSchedulerHealth($logs, $now)['state']);
    $check('WORKER_STARTUP_FAILED', ForecastingSchedulerService::getSchedulerHealth($logs, $now)['diagnostic_reference']);
    ForecastingSchedulerService::recordSchedulerCheck(0, null, $logs, $now->modify('+5 minutes'));
    $check('idle', ForecastingSchedulerService::getSchedulerHealth($logs, $now->modify('+5 minutes'))['state']);
    file_put_contents($logs . '/forecasting_scheduler_health.json', '{bad json');
    $check('unavailable', ForecastingSchedulerService::getSchedulerHealth($logs, $now)['state']);
    echo "Scheduler health passed: unavailable, healthy idle without job completion, queued work, stale check, failure reference, recovery, corrupt status.\n";
} finally {
    @unlink($logs . '/forecasting_scheduler_health.json');
    rmdir($logs);
}
