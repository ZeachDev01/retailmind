<?php
function forecast_readiness_state(array $row): array
{
    if (!array_key_exists('training_eligible', $row)) {
        return ['label' => 'Check unavailable', 'reason' => 'Readiness could not be checked.', 'next' => 'Check demand history and try again.'];
    }
    if (!empty($row['training_eligible'])) {
        return ['label' => 'Ready to train', 'reason' => 'All three data checks passed.', 'next' => 'The Super Administrator can train a model. Approval comes later.'];
    }
    $remaining = max(0, (int)$row['required_usable_days'] - (int)$row['accumulated_usable_days']);
    if ($remaining > 0) {
        return ['label' => 'More history needed', 'reason' => "Needs {$remaining} more usable sales days.", 'next' => 'Record sales or import verified daily sales history.'];
    }
    if (empty($row['has_feature_window'])) {
        $lastDate = $row['last_sale_date'] ?? null;
        $windowStart = date('Y-m-d', strtotime($row['eligibility_through_date'] . ' -27 days'));
        $oldHistory = $lastDate && $lastDate < $windowStart;
        return [
            'label' => $oldHistory ? 'Recent records missing' : 'Recent records need checking',
            'reason' => $oldHistory ? "Enough history, but it ends {$lastDate}." : 'Enough history, but the latest 28 days have unsupported records.',
            'next' => 'Check recent sales, closures and stockouts; import verified missing records.',
        ];
    }
    if (empty($row['has_holdout_coverage'])) {
        return ['label' => 'Testing records incomplete', 'reason' => 'History is sufficient, but three 28-day test periods are not supported.', 'next' => 'Check the missing dates in the training details.'];
    }
    return ['label' => 'Check unavailable', 'reason' => 'Readiness could not be confirmed.', 'next' => 'Check demand history and try again.'];
}

function forecast_workflow(string $current): void
{
    $steps = [
        ['data', 'Check sales data', 'report/data_readiness.php', \App\Authorization\RoleCapabilityPolicy::VIEW_FORECASTS, 'Store staff'],
        ['train', 'Train model', 'system_administrator/ml_settings.php', \App\Authorization\RoleCapabilityPolicy::CONFIGURE_MODELS, 'Super Administrator'],
        ['review', 'Review & approve', 'administrator/model_review.php', \App\Authorization\RoleCapabilityPolicy::APPROVE_MODELS, 'Administrator'],
        ['forecast', 'See expected sales', 'report/predictions.php', \App\Authorization\RoleCapabilityPolicy::VIEW_FORECASTS, 'Store staff'],
        ['buy', 'Plan buying', 'inventory_management/replenishment_queue.php', null, 'Inventory Manager'],
    ];
    ?>
    <nav class="forecast-workflow" aria-label="Forecasting steps">
        <ol>
            <?php foreach ($steps as $index => [$key, $label, $path, $capability, $owner]): ?>
                <li>
                    <?php if ($capability === null || has_capability($capability)): ?>
                        <a href="<?= htmlspecialchars(app_url('components/' . $path)) ?>" <?= $key === $current ? 'aria-current="step"' : '' ?>><span><?= $index + 1 ?></span><?= htmlspecialchars($label) ?></a>
                    <?php else: ?>
                        <span class="forecast-workflow-owner"><span><?= $index + 1 ?></span><?= htmlspecialchars($label) ?><small><?= htmlspecialchars($owner) ?></small></span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
    </nav>
    <?php
}

function forecast_status_summary(string $title, string $blocker, string $next, ?string $url = null, string $linkLabel = 'Continue'): void
{
    ?>
    <section class="forecast-status-summary" aria-label="Current forecasting status">
        <h2><?= htmlspecialchars($title) ?></h2>
        <p><strong>Blocking this:</strong> <?= htmlspecialchars($blocker) ?></p>
        <p><strong>Next:</strong> <?= htmlspecialchars($next) ?></p>
        <?php if ($url): ?><a class="btn btn-secondary" href="<?= htmlspecialchars($url) ?>"><?= htmlspecialchars($linkLabel) ?></a><?php endif; ?>
    </section>
    <?php
}

// Shared help follows the replenishment queue's keyboard and touch behavior.
function forecast_help(string $id, string $text, string $label = 'About this value'): void
{
    ?>
    <span class="info-tooltip">
        <button class="info-tooltip-trigger" type="button" aria-label="<?= htmlspecialchars($label) ?>" aria-describedby="<?= htmlspecialchars($id) ?>"><i class="bi bi-info-circle" aria-hidden="true"></i></button>
        <span class="tooltip-box" id="<?= htmlspecialchars($id) ?>" role="tooltip"><?= htmlspecialchars($text) ?></span>
    </span>
    <?php
}
?>
<link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/forecast-workspace.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/forecast-workspace.css')) ?>">
<script src="<?= htmlspecialchars(app_url('assets/js/info-tooltip.js') . '?v=' . filemtime(__DIR__ . '/../../assets/js/info-tooltip.js')) ?>" defer></script>
