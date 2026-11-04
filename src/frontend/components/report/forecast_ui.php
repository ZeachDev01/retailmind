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
    if (current_role() === 'super_admin') {
        $pages = [
            ['forecast_import.php', 'Import history', 'cloud-arrow-up', 'system_administrator'],
            ['data_readiness.php', 'Data readiness', 'database-check', 'report'],
            ['ml_settings.php', 'Model training', 'cpu', 'system_administrator'],
            ['forecast_analytics.php', 'Analytics', 'bar-chart-line', 'report'],
            ['predictions.php', 'Demand forecast', 'graph-up-arrow', 'report'],
            ['forecast_exceptions.php', 'Exceptions', 'exclamation-triangle', 'report'],
        ];
        $page = basename($_SERVER['SCRIPT_NAME'] ?? '');
        ?>
        <nav class="forecast-studio-nav" aria-label="Forecasting workspace">
            <?php foreach ($pages as [$file, $label, $icon, $folder]): ?>
                <a href="<?= htmlspecialchars(app_url('components/' . $folder . '/' . $file)) ?>" <?= $page === $file ? 'aria-current="page"' : '' ?>>
                    <i class="bi bi-<?= $icon ?>" aria-hidden="true"></i><span><?= $label ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
        <?php
        return;
    }
    if (current_role() === 'admin') {
        $pages = [
            ['data_readiness.php', 'Data readiness', 'database-check', 'report'],
            ['model_review.php', 'Model review', 'clipboard-check', 'administrator'],
            ['predictions.php', 'Demand forecast', 'graph-up-arrow', 'report'],
            ['forecast_exceptions.php', 'Exceptions', 'exclamation-triangle', 'report'],
        ];
        $page = basename($_SERVER['SCRIPT_NAME'] ?? '');
        ?>
        <nav class="forecast-studio-nav forecast-studio-nav-admin" aria-label="Forecasting workspace">
            <?php foreach ($pages as [$file, $label, $icon, $folder]): ?>
                <a href="<?= htmlspecialchars(app_url('components/' . $folder . '/' . $file)) ?>" <?= $page === $file ? 'aria-current="page"' : '' ?>>
                    <i class="bi bi-<?= $icon ?>" aria-hidden="true"></i><span><?= $label ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
        <?php
        return;
    }
    $role = current_role() === 'super_admin'
        ? ['Super Administrator', 'Prepare sales data and train models.']
        : (current_role() === 'admin'
            ? ['Administrator', 'Review models and monitor Store forecasts.']
            : ['Inventory Manager', 'Check expected sales and plan what to buy.']);
    $steps = [
        ['data', 'Check sales data', 'report/data_readiness.php', \App\Authorization\RoleCapabilityPolicy::VIEW_FORECASTS, 'Super Administrator'],
        ['train', 'Train model', 'system_administrator/ml_settings.php', \App\Authorization\RoleCapabilityPolicy::CONFIGURE_MODELS, 'Super Administrator'],
        ['review', 'Review & approve', 'administrator/model_review.php', \App\Authorization\RoleCapabilityPolicy::APPROVE_MODELS, 'Administrator'],
        ['forecast', 'See expected sales', 'report/predictions.php', \App\Authorization\RoleCapabilityPolicy::VIEW_FORECASTS, 'Store staff'],
        ['buy', 'Plan buying', 'inventory_management/replenishment_queue.php', null, 'Inventory Manager'],
    ];
    ?>
    <div class="forecast-role-context">
        <p><strong><?= htmlspecialchars($role[0]) ?></strong><span><?= htmlspecialchars($role[1]) ?></span></p>
        <details class="forecast-workflow">
        <summary>View workflow</summary>
        <nav aria-label="Forecasting steps">
        <p class="forecast-workflow-note">Model setup and approval happen when needed. Scheduled jobs update forecasts; staff review the results.</p>
        <ol>
            <?php foreach ($steps as $index => [$key, $label, $path, $capability, $owner]): ?>
                <li>
                    <?php if ($capability === null || has_capability($capability)): ?>
                        <a href="<?= htmlspecialchars(app_url('components/' . $path)) ?>" <?= $key === $current ? 'aria-current="location"' : '' ?>><span class="forecast-step-number" aria-hidden="true"><?= $index + 1 ?></span><span><?= htmlspecialchars($label) ?><small><?= htmlspecialchars($owner) ?></small></span></a>
                    <?php else: ?>
                        <span class="forecast-workflow-owner"><span class="forecast-step-number" aria-hidden="true"><?= $index + 1 ?></span><span><?= htmlspecialchars($label) ?><small><?= htmlspecialchars($owner) ?></small></span></span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
        </nav>
        </details>
    </div>
    <?php
}

function forecast_status_summary(string $title, string $blocker, string $next, ?string $url = null, string $linkLabel = 'Continue'): void
{
    ?>
    <section class="forecast-status-summary" aria-label="Current forecasting status">
        <div class="forecast-status-content">
            <h2><i class="bi bi-activity" aria-hidden="true"></i> <?= htmlspecialchars($title) ?></h2>
            <p><?= htmlspecialchars($next) ?></p>
            <?php forecast_help('forecast-status-help', $blocker, 'Status details'); ?>
        </div>
        <?php if ($url): ?><a class="btn btn-primary" href="<?= htmlspecialchars($url) ?>"><?= htmlspecialchars($linkLabel) ?></a><?php endif; ?>
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
<script src="<?= htmlspecialchars(app_url('assets/js/forecast-notices.js') . '?v=' . filemtime(__DIR__ . '/../../assets/js/forecast-notices.js')) ?>" defer></script>
