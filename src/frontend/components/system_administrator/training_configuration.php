<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_capability(\App\Authorization\RoleCapabilityPolicy::CONFIGURE_TRAINING_SETTINGS);

// Each settings action accepts only its own fields, including crafted requests.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $settingFields = [
        'save_schedule' => ['schedule_weekly_retrain_day', 'schedule_hour', 'schedule_minute', 'schedule_period'],
        'save_model_quality' => ['ml_max_wape_7d', 'ml_max_wape_14d', 'ml_max_wape_28d'],
    ];
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    if (isset($settingFields[$action])) {
        csrf_verify();
        if (array_diff(array_keys($_POST), ['action', 'csrf_token', ...$settingFields[$action]])) {
            http_response_code(403);
            exit('Only the weekly training schedule and candidate error limits can be changed here.');
        }
    }
}

$message = '';
$messageClass = '';
$fieldErrors = [];
$fieldGroups = [
    'accuracy' => [
        'title' => 'Candidate error limits',
        'description' => 'WAPE measures prediction error: lower is better. Each horizon must meet its limit and strictly beat repeating last week’s sales. Missing evidence blocks qualification.',
    ],
];
$definitions = [];
foreach ([7, 14, 28] as $horizon) {
    $definitions["ml_max_wape_{$horizon}d"] = [
        'label' => "{$horizon}-day maximum WAPE (%)",
        'help' => 'Allowed error from 0% to 200%. This is not an accuracy guarantee.',
        'min' => 0, 'max' => 200, 'step' => '0.1', 'type' => 'number',
        'required' => true, 'group' => 'accuracy',
    ];
}
$governance = new \App\Forecasting\StoreModelGovernanceService($pdo);
$loadLimits = static function () use ($governance): array {
    $values = [];
    foreach ($governance->getThresholds() as $horizon => $fraction) {
        $values["ml_max_wape_{$horizon}d"] = $fraction === null ? '' : (string)($fraction * 100);
    }
    return $values;
};

$settings = $loadLimits();
$scheduler = new \App\Forecasting\ForecastingSchedulerService($pdo);
$effectiveSchedule = $scheduler->getEffectiveSchedule();
$weekdays = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
$trainingTime = DateTimeImmutable::createFromFormat('!H:i', $effectiveSchedule['weekly_retrain_time']);
$scheduleFields = [
    'schedule_weekly_retrain_day' => $effectiveSchedule['weekly_retrain_day'],
    'schedule_hour' => $trainingTime->format('g'),
    'schedule_minute' => $trainingTime->format('i'),
    'schedule_period' => $trainingTime->format('A'),
];
$scheduleErrors = [];
$demoMetadata = $pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key='demo_import_metadata'")->fetchColumn();
$demoDatabase = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
$isLocalDemo = $demoMetadata !== false
    && preg_match('/^retailmind_demo_[a-z0-9_]+$/D', $demoDatabase)
    && in_array(strtolower((string)env('APP_ENV')), ['development', 'test'], true)
    && in_array((string)env('DB_HOST'), ['localhost', '127.0.0.1', '::1'], true);
$trainingReadiness = get_forecasting_readiness($pdo);
$eligibleTrainingProducts = array_values(array_filter(
    $trainingReadiness,
    static fn(array $item): bool => !empty($item['training_eligible'])
));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_schedule') {
    csrf_verify();
    foreach ($scheduleFields as $key => $value) {
        $scheduleFields[$key] = is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : '';
    }
    if (!in_array($scheduleFields['schedule_weekly_retrain_day'], $weekdays, true)) {
        $scheduleErrors['schedule_weekly_retrain_day'] = 'Select a weekday from Sunday through Saturday.';
    }
    if (!preg_match('/^(?:[1-9]|1[0-2])$/D', $scheduleFields['schedule_hour'])
        || !preg_match('/^[0-5][0-9]$/D', $scheduleFields['schedule_minute'])
        || !in_array($scheduleFields['schedule_period'], ['AM', 'PM'], true)) {
        $scheduleErrors['schedule_time'] = 'Select a time from 12:00 AM to 11:59 PM.';
    }
    if (!$scheduleErrors) {
        $hour = (int)$scheduleFields['schedule_hour'] % 12 + ($scheduleFields['schedule_period'] === 'PM' ? 12 : 0);
        $scheduleUpdates = [
            'schedule_weekly_retrain_day' => $scheduleFields['schedule_weekly_retrain_day'],
            'schedule_weekly_retrain_time' => sprintf('%02d:%s', $hour, $scheduleFields['schedule_minute']),
        ];
        try {
            $pdo->beginTransaction();
            $saveSchedule = $pdo->prepare('INSERT INTO platform_settings (setting_key, setting_value, updated_by) VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)');
            foreach ($scheduleUpdates as $key => $value) {
                $saveSchedule->execute([$key, $value, (int)$_SESSION['user_id']]);
            }
            $pdo->exec("DELETE FROM ml_settings WHERE setting_key IN ('schedule_weekly_retrain_day', 'schedule_weekly_retrain_time')");
            log_activity($pdo, (int)$_SESSION['user_id'], 'ML schedule update', 'Demand Forecasting', null, null, $scheduleUpdates);
            $pdo->commit();
            $effectiveSchedule = $scheduler->getEffectiveSchedule();
            $message = 'Weekly schedule saved. Training starts when due, subject to eligible history and worker availability. No training was queued or model approved by this save.';
            $messageClass = 'tag-success';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $message = \App\Support\OperatorAlert::message($e, 'Could not save the weekly schedule. Retry.');
            $messageClass = 'tag-warning';
        }
    } else {
        $message = 'Schedule was not saved. Review the highlighted fields below.';
        $messageClass = 'tag-warning';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_model_quality') {
    csrf_verify();
    $updates = [];
    $errors = [];
    foreach ($definitions as $key => $definition) {
        $value = is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : '';
        $settings[$key] = $value;
        if ($definition['type'] === 'number') {
            $step = (string)($definition['step'] ?? '1');
            $isFloatStep = strpos($step, '.') !== false;
            $numericValid = is_numeric($value) && ($isFloatStep || preg_match('/^-?\d+$/', $value) === 1);
            if (!$numericValid || !is_finite((float)$value) || (float)$value < $definition['min'] || (float)$value > $definition['max']) {
                $rangeMessage = sprintf(
                    '%s must be between %s and %s.',
                    $definition['label'],
                    number_format((float)$definition['min'], $isFloatStep ? 1 : 0),
                    number_format((float)$definition['max'], $isFloatStep ? 1 : 0)
                );
                $fieldErrors[$key] = $rangeMessage;
                $errors[] = $rangeMessage;
                continue;
            }
        }
        $updates[$key] = $value;
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $governance->setThresholds(array_map(static fn($value): float => (float)$value / 100, $updates), (int)$_SESSION['user_id']);
            log_activity($pdo, (int)$_SESSION['user_id'], 'ML settings update', 'Demand Forecasting', null, null, $updates);
            $pdo->commit();
            $settings = $loadLimits();
            $message = 'Forecast settings saved. Training Eligibility remains fixed; saving does not train or approve a model.';
            $messageClass = 'tag-success';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $message = \App\Support\OperatorAlert::message($e, 'Could not save candidate error limits. Retry.');
            $messageClass = 'tag-warning';
        }
    } else {
        $message = 'Settings were not saved. Review the highlighted fields below.';
        $messageClass = 'tag-warning';
    }
}
$stylePath = __DIR__ . '/../../assets/css/style.css';
$styleVersion = is_file($stylePath) ? (string)filemtime($stylePath) : '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Training Configuration</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css') . '?v=' . rawurlencode($styleVersion)) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(theme_url('assets/css/ml-settings.css')) ?>">
    <?php require_once __DIR__ . '/../report/forecast_ui.php'; ?>
</head>
<body class="forecast-workspace ml-settings-page">
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content">
        <div class="topbar">
            <h1>Training Configuration</h1>
            <div class="ml-page-actions"><a class="btn btn-secondary" href="ml_settings.php">Back to Model Training</a></div>
        </div>
        <?php if ($message): ?>
            <div class="alert <?= htmlspecialchars($messageClass) ?>" role="<?= $messageClass === 'tag-success' ? 'status' : 'alert' ?>" aria-live="polite"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        <?php forecast_workflow('train'); ?>
        <section class="dashboard-section" aria-labelledby="training-settings-heading">
            <div class="section-header"><h2 id="training-settings-heading">Training settings</h2></div>
            <div class="ml-setting-block">
            <div class="ml-setting-heading">
                <h3 id="schedule-heading">Weekly Model Retraining</h3>
                <?php forecast_help('weekly-training-help', 'Creates a new candidate from eligible Store history on the selected weekday and time. Prefer a time after Store close. The first automatic training uses the next selected time once history is eligible; later runs use the weekly schedule. Missed runs catch up when the PC starts, subject to eligible history and worker availability. Saving only changes the schedule; it does not start training or approve a model.', 'About weekly training'); ?>
            </div>
            <div>
                <p id="effective-training-schedule" class="section-description">Every <?= htmlspecialchars($effectiveSchedule['weekly_retrain_day']) ?> at <?= htmlspecialchars(DateTimeImmutable::createFromFormat('!H:i', $effectiveSchedule['weekly_retrain_time'])->format('g:i A')) ?>, Philippine time (Asia/Manila).</p>
            </div>
            <form method="post" aria-labelledby="schedule-heading">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_schedule">
                <div class="form-grid ml-schedule-grid">
                    <div class="form-group<?= isset($scheduleErrors['schedule_weekly_retrain_day']) ? ' invalid' : '' ?>">
                        <label for="schedule_weekly_retrain_day">Training weekday</label>
                        <select id="schedule_weekly_retrain_day" name="schedule_weekly_retrain_day" required<?= isset($scheduleErrors['schedule_weekly_retrain_day']) ? ' aria-invalid="true" aria-describedby="schedule_day_error"' : '' ?>>
                            <?php foreach ($weekdays as $day): ?>
                                <option value="<?= $day ?>"<?= $scheduleFields['schedule_weekly_retrain_day'] === $day ? ' selected' : '' ?>><?= $day ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($scheduleErrors['schedule_weekly_retrain_day'])): ?><small id="schedule_day_error" class="field-error"><?= htmlspecialchars($scheduleErrors['schedule_weekly_retrain_day']) ?></small><?php endif; ?>
                    </div>
                    <?php foreach ([
                        'schedule_hour' => ['Training hour', range(1, 12)],
                        'schedule_minute' => ['Training minute', range(0, 59)],
                        'schedule_period' => ['AM or PM', ['AM', 'PM']],
                    ] as $key => [$label, $options]): ?>
                        <div class="form-group<?= isset($scheduleErrors['schedule_time']) ? ' invalid' : '' ?>">
                            <div class="forecast-field-label">
                                <label for="<?= $key ?>"><?= $label ?></label>
                                <?php if ($key === 'schedule_hour'): ?>
                                    <?php forecast_help('schedule_time_help', 'Select a time from 12:00 AM to 11:59 PM, Philippine time (Asia/Manila). Prefer a time after Store close. Missed training runs catch up when the PC starts.', 'About training time'); ?>
                                <?php endif; ?>
                            </div>
                            <select id="<?= $key ?>" name="<?= $key ?>" required aria-describedby="schedule_time_help<?= isset($scheduleErrors['schedule_time']) ? ' schedule_time_error' : '' ?>"<?= isset($scheduleErrors['schedule_time']) ? ' aria-invalid="true"' : '' ?>>
                                <?php foreach ($options as $option): $value = $key === 'schedule_minute' ? sprintf('%02d', $option) : (string)$option; ?>
                                    <option value="<?= $value ?>"<?= $scheduleFields[$key] === $value ? ' selected' : '' ?>><?= $value ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if (isset($scheduleErrors['schedule_time'])): ?><small id="schedule_time_error" class="field-error"><?= htmlspecialchars($scheduleErrors['schedule_time']) ?></small><?php endif; ?>
                <div class="form-actions">
                    <button class="btn" type="submit">Save Weekly Schedule</button>
                    <span class="ml-forecast-time">Daily forecast: <?= htmlspecialchars(DateTimeImmutable::createFromFormat('!H:i', $effectiveSchedule['daily_forecast_time'])->format('g:i A')) ?>, Asia/Manila <?php forecast_help('daily-forecast-help', 'Daily Forecast Generation uses the approved model to estimate future sales. It does not retrain the model. Its time is read-only here and is separate from the weekly training schedule.', 'About daily forecasts'); ?></span>
                </div>
            </form>
            </div>
        <?php if (!$isLocalDemo): ?>
        <div class="ml-setting-block"><details class="forecast-details"<?= $fieldErrors ? ' open' : '' ?>><summary id="settings-heading">Candidate error limits</summary>
            <form id="model-quality-settings" method="post" novalidate>
                <input type="hidden" name="action" value="save_model_quality">
                <?= csrf_field() ?>
                <?php foreach ($fieldGroups as $groupKey => $groupMeta): ?>
                    <fieldset class="settings-fieldset">
                        <legend class="ml-setting-heading">Maximum WAPE (%)
                        <?php if (isset($groupMeta['description'])): ?>
                            <?php forecast_help('group-' . $groupKey, $groupMeta['description'] . ' WAPE is the total absolute prediction error divided by total actual sales. For example, 20 units of total absolute error against 100 units sold is 20% WAPE. Lower limits are stricter; higher limits allow more error. Change these only when reviewing acceptable forecast error. Saved limits are used when candidate quality is evaluated. Saving does not train or approve a model.', 'About candidate error limits'); ?>
                        <?php endif; ?>
                        </legend>
                        <div class="form-grid">
                            <?php foreach ($definitions as $key => $definition):
                                if (($definition['group'] ?? '') !== $groupKey) {
                                    continue;
                                }
                                $isRequired = !empty($definition['required']);
                                $hasError = isset($fieldErrors[$key]);
                                $errorId = $key . '_error';
                                $helpId = $key . '_help';
                                $describedBy = trim((isset($definition['help']) ? $helpId : '') . ($hasError ? ' ' . $errorId : ''));
                                $groupClasses = 'form-group' . (!empty($definition['full']) ? ' full' : '') . ($hasError ? ' invalid' : '');
                                $inputMode = $definition['type'] === 'number' ? (isset($definition['step']) ? 'decimal' : 'numeric') : null;
                            ?>
                                <div class="<?= $groupClasses ?>">
                                    <div class="forecast-field-label">
                                        <label for="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($definition['label']) ?></label>
                                        <?php if (isset($definition['help'])): ?>
                                            <?php forecast_help($helpId, $definition['help'], 'About ' . $definition['label']); ?>
                                        <?php endif; ?>
                                    </div>
                                    <input id="<?= htmlspecialchars($key) ?>"
                                           name="<?= htmlspecialchars($key) ?>"
                                           type="<?= htmlspecialchars($definition['type']) ?>"
                                           value="<?= htmlspecialchars((string)($settings[$key] ?? '')) ?>"
                                           <?= $inputMode ? ' inputmode="' . $inputMode . '"' : '' ?>
                                           <?= isset($definition['min']) ? ' min="' . (int)$definition['min'] . '"' : '' ?>
                                           <?= isset($definition['max']) ? ' max="' . (int)$definition['max'] . '"' : '' ?>
                                           <?= isset($definition['step']) ? ' step="' . htmlspecialchars((string)$definition['step']) . '"' : '' ?>
                                           <?= isset($definition['placeholder']) ? ' placeholder="' . htmlspecialchars((string)$definition['placeholder']) . '"' : '' ?>
                                           autocomplete="off"
                                           <?= $describedBy !== '' ? ' aria-describedby="' . htmlspecialchars($describedBy) . '"' : '' ?>
                                           <?= $hasError ? ' aria-invalid="true"' : '' ?>
                                           <?= $isRequired ? ' required' : '' ?>>
                                    <?php if ($hasError): ?>
                                        <small id="<?= htmlspecialchars($errorId) ?>" class="field-error"><?= htmlspecialchars($fieldErrors[$key]) ?></small>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>

                <?php endforeach; ?>

                <div class="form-actions">
                    <button type="submit" class="btn">Save Error Limits</button>

                </div>
            </form>
        </details></div>
        <?php endif; ?>

        </section>

        <section class="dashboard-section" aria-label="Training guide">
            <details class="forecast-details"><summary id="training-guide-heading">How it works</summary>
            <div class="ml-guide-row">
                <div class="ml-setting-heading">
                    <h3>Training eligibility</h3>
                    <?php
                    ob_start();
                    require __DIR__ . '/../report/training_eligibility_policy.php';
                    $eligibilityHelp = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(ob_get_clean()), ENT_QUOTES, 'UTF-8')));
                    forecast_help('training-eligibility-help', $eligibilityHelp, 'About training eligibility');
                    ?>
                </div>
                <span><?= count($eligibleTrainingProducts) ?> of <?= count($trainingReadiness) ?> Products ready</span>
                <a class="btn btn-secondary" href="../report/data_readiness.php">Check Data Readiness</a>
            </div>
            <div class="ml-guide-row" role="region" aria-label="Effective model recipe">
                <div class="ml-setting-heading">
                    <h3>Fixed model recipe</h3>
                    <?php forecast_help('model-recipe-help', 'Shared Random Forest: 15 trees, maximum depth 8, random seed 42. Trees combine several learned sales patterns; depth limits how detailed each tree can become; the seed makes training repeatable with the same inputs. These values are fixed and read-only. Forecast Horizons are 7, 14 and 28 days. Forecasts are point quantities; prediction intervals are unavailable.', 'About the fixed model recipe'); ?>
                </div>
                <span>Random Forest · 15 trees · depth 8</span>
                <span class="ml-readonly">Read-only</span>
            </div>
            <div class="ml-guide-row">
                <div class="ml-setting-heading">
                    <h3>Model approval</h3>
                    <?php forecast_help('model-approval-help', 'Training creates a Training Candidate. It must pass chronological quality checks, the required observation evidence and Store compatibility checks before explicit local Administrator approval. Failed or rejected candidates leave the approved model unchanged. Demo review never activates a Store model. Forecasts estimate sales; they do not change stock or place orders.', 'About model approval'); ?>
                </div>
                <span>Quality checks → observation → Administrator review</span>
            </div>
            </details>
        </section>

    </main>
</div>
</body>
</html>
