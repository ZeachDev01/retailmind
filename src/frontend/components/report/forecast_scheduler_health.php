<?php
$schedulerHealth = \App\Forecasting\ForecastingSchedulerService::getSchedulerHealth();
$schedulerLabels = [
    'idle' => 'Healthy · no work waiting',
    'work_pending' => 'Healthy · work pending',
    'stale' => 'Scheduler check overdue',
    'unavailable' => 'Scheduler unavailable',
];
?>
<div class="ml-job-status" role="status" aria-live="polite" data-scheduler-state="<?= htmlspecialchars($schedulerHealth['state']) ?>">
    <span>Background scheduler</span>
    <strong><?= htmlspecialchars($schedulerLabels[$schedulerHealth['state']]) ?></strong>
    <p>Latest check: <?= htmlspecialchars($schedulerHealth['checked_at'] ?? 'Not recorded') ?><?= $schedulerHealth['checked_at'] ? ' · Asia/Manila' : '' ?>.</p>
    <?php if (in_array($schedulerHealth['state'], ['stale', 'unavailable'], true)): ?>
        <p>Ask the Super Administrator to check the Windows forecasting task and scheduler log.
            Reference: <?= htmlspecialchars($schedulerHealth['diagnostic_reference'] ?? 'SCHEDULER_CHECK_STALE') ?>.</p>
    <?php else: ?>
        <p>The scheduler checks every five minutes. Training job status and candidate quality are shown separately.</p>
    <?php endif; ?>
</div>
