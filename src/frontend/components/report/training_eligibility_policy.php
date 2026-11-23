<?php $trainingPolicy = \App\Forecasting\StoreTrainingEligibilityService::policy(); ?>
<section aria-label="Training Eligibility policy">
    <h2>Training Eligibility</h2>
    <p>Each Product needs at least <?= (int)$trainingPolicy['minimum_usable_days'] ?> accumulated usable days, a supported recent <?= (int)$trainingPolicy['input_window_days'] ?>-calendar-day input window, and <?= (int)$trainingPolicy['evaluation_periods'] ?> supported chronological <?= (int)$trainingPolicy['evaluation_period_days'] ?>-day evaluation periods backed by earlier training history &mdash; usable days alone never imply eligibility.</p>
    <p>Genuine observed zeros count. Closures, unknown observations, and Stockout Days do not add usable days. Supported closures do not automatically invalidate otherwise supported windows. Calendar span and days with sales are separate counts.</p>
    <p>Fixed and read-only: Forecast Readiness bands remain separate evidence ratings, and candidate qualification, observation, local Administrator approval, and daily Forecast Generation stay separate steps &mdash; completing training never approves a model.</p>
</section>
