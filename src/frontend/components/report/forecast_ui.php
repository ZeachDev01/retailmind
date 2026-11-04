<?php
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
