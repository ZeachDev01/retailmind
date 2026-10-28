<?php
// Included by Demand Forecast; technical controls retain their own authorization.
if (!function_exists('has_capability') || !has_capability(\App\Authorization\RoleCapabilityPolicy::GENERATE_FORECASTS)) {
    http_response_code(403);
    return;
}

$reviewError = false;
$reviewCandidate = null;
$reviewQuality = null;
$reviewApproved = '';
$reviewSettings = [];
try {
    $reviewSettings = $pdo->query("SELECT setting_key, setting_value FROM platform_settings
        WHERE setting_key IN ('ml_approved_model_version', 'ml_observation_mode', 'ml_recommendations_suspended')")
        ->fetchAll(PDO::FETCH_KEY_PAIR);
    $reviewApproved = (string)($reviewSettings['ml_approved_model_version'] ?? '');
    $reviewCandidate = $pdo->query("SELECT model_version FROM model_training_runs
        WHERE status = 'completed' ORDER BY training_run_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($reviewCandidate) {
        $reviewQuality = (new \App\Forecasting\CandidateEvaluationService($pdo))
            ->evaluateCandidateQuality($reviewCandidate['model_version']);
    }
} catch (Throwable $e) {
    error_log('Forecast evaluation summary unavailable: ' . $e->getMessage());
    $reviewError = true;
}
$reviewRows = [];
foreach (($reviewQuality['horizons'] ?? []) as $reviewRow) {
    $reviewRows[(int)$reviewRow['horizon_days']] = $reviewRow;
}
$reviewPercent = static fn($value): string => is_numeric($value) && is_finite((float)$value) && (float)$value >= 0
    ? number_format((float)$value * 100, 2) . '%' : 'Not available';
?>
<section class="forecast-header-card model-review" aria-labelledby="model-review-title">
    <div>
        <h2 id="model-review-title">Can we use these forecasts?</h2>
        <?php if ($reviewError): ?>
            <p>Model checks could not be loaded. Ask the Super Administrator to review the model before relying on its suggestions.</p>
        <?php else: ?>
            <p><strong>Approved model:</strong> <?= $reviewApproved !== '' ? htmlspecialchars($reviewApproved) : 'None yet' ?>.</p>
            <?php if (!empty($displayedForecastModel)): ?>
                <p><strong>Model behind the forecasts below:</strong> <?= htmlspecialchars($displayedForecastModel) ?>.
                <?php if ($displayedForecastModel !== $reviewApproved): ?>
                    This differs from the approved model. Ask the Super Administrator to review it before using these suggestions.
                <?php endif; ?></p>
            <?php endif; ?>
            <?php if (in_array($reviewSettings['ml_recommendations_suspended'] ?? '', ['1', 'true'], true)): ?>
                <p><strong>Purchase suggestions are paused.</strong> Ask the Administrator to review the pause before using them.</p>
            <?php elseif (in_array($reviewSettings['ml_observation_mode'] ?? '', ['1', 'true'], true)): ?>
                <p><strong>Observation only.</strong> Forecasts are being checked and are not approved for routine purchasing.</p>
            <?php elseif ($reviewApproved === ''): ?>
                <p><strong>Not approved for routine use.</strong> Training and approval must be completed first.</p>
            <?php else: ?>
                <p>An approved model is selected. Check the forecast date and product warnings below before reviewing purchase suggestions.</p>
            <?php endif; ?>

            <?php if ($reviewCandidate): ?>
                <h3>Latest trained model</h3>
                <p><?= htmlspecialchars($reviewCandidate['model_version']) ?> —
                    <?= $reviewCandidate['model_version'] === $reviewApproved ? 'Selected as the approved model.' : 'Not the approved model. Training does not give approval.' ?></p>
                <p>The tests compare predictions with later recorded sales and with repeating last week's sales. Lower error is better; it is not an accuracy percentage.</p>
                <div class="table-wrap">
                    <table>
                        <caption>Latest trained model: sales prediction checks</caption>
                        <thead><tr><th scope="col">Days ahead</th><th scope="col">Model error (WAPE)</th><th scope="col">Repeat last week: error</th><th scope="col">Test result</th></tr></thead>
                        <tbody>
                        <?php foreach ([7, 14, 28] as $reviewHorizon): ?>
                            <?php
                            $reviewRow = $reviewRows[$reviewHorizon] ?? [];
                            $reviewComplete = $reviewPercent($reviewRow['wape'] ?? null) !== 'Not available'
                                && $reviewPercent($reviewRow['baseline_wape'] ?? null) !== 'Not available';
                            $reviewResult = !$reviewComplete ? 'Not enough test results'
                                : (($reviewRow['gate_status'] ?? '') === 'passed' ? 'Passed'
                                    : (($reviewRow['gate_status'] ?? '') === 'failed' ? 'Needs improvement' : 'Not enough test results'));
                            ?>
                            <tr><th scope="row"><?= $reviewHorizon ?> days</th>
                                <td><?= $reviewPercent($reviewRow['wape'] ?? null) ?></td>
                                <td><?= $reviewPercent($reviewRow['baseline_wape'] ?? null) ?></td>
                                <td><?= $reviewResult ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p>These results combine products. Some products may perform worse. Recorded sales cannot show all demand lost during stockouts, and these tests do not guarantee future results.</p>
            <?php else: ?>
                <p>No completed model training is available yet. The Super Administrator can train and review a model in ML Operation.</p>
            <?php endif; ?>
        <?php endif; ?>
        <p>Forecasts guide staff decisions. They do not place orders or change stock.</p>
        <?php if (has_capability(\App\Authorization\RoleCapabilityPolicy::CONFIGURE_MODELS)): ?>
            <p><a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('components/system_administrator/ml_settings.php')) ?>">Train and review models</a>
                <a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('components/report/forecast_analytics.php')) ?>">View detailed model results</a></p>
        <?php endif; ?>
    </div>
</section>
