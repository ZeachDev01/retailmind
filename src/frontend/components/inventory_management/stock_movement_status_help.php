<span class="info-tooltip">
    <button type="button" class="info-tooltip-trigger" aria-label="About Stock Movement statuses" aria-describedby="<?= $statusGuideId ?>"><i class="bi bi-info-circle" aria-hidden="true"></i></button>
    <span class="tooltip-box sr-status-guide" id="<?= $statusGuideId ?>" role="tooltip">
        <?php foreach ($criteria as $kind => [$statusColor, $statusIcon, $statusCriterion]): ?>
        <span class="sr-status-definition"><span class="sr-badge sr-<?= $statusColor ?>"><i class="bi bi-<?= $statusIcon ?>" aria-hidden="true"></i><?= $movementLabels[$kind] ?></span><span><?= htmlspecialchars($statusCriterion) ?></span></span>
        <?php endforeach; ?>
    </span>
</span>
