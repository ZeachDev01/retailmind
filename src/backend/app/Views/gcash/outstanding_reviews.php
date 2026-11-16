<section class="pos-panel gcash-overview-section" aria-labelledby="gcash-outstanding-title" id="gcash-outstanding-reviews" data-fragment="outstanding-reviews">
    <div class="pos-panel-header">
        <div class="pos-panel-title">
            <div>
                <h2 id="gcash-outstanding-title">Needs review</h2>
            </div>
        </div>
        <?php $totalIssues = ($overview['unresolved_count'] ?? 0) + ($overview['differences_count'] ?? 0); ?>
        <?php if ($totalIssues > 0): ?>
            <span class="gcash-badge gcash-badge-attention">
                <i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i> <?= (int)$totalIssues ?> pending
            </span>
        <?php endif; ?>
    </div>
    <?php if ($overview === null): ?>
        <p role="alert">Outstanding reviews unavailable. Refresh to try again.</p>
    <?php elseif ($totalIssues === 0): ?>
        <div class="gcash-empty-state">
            <i class="bi bi-check2-circle" aria-hidden="true"></i>
            <h3>Nothing needs review</h3>
        </div>
    <?php else: ?>
        <div class="gcash-actions gcash-review-action-list">
            <?php if ((int)$overview['unresolved_count'] > 0): ?>
                <button type="button" class="btn btn-secondary gcash-review-card-btn gcash-review-unresolved" data-review-tab="unresolved">
                    <span class="gcash-review-card-left">
                        <span class="gcash-review-icon gcash-review-icon-unresolved"><i class="bi bi-clock-history" aria-hidden="true"></i></span>
                        <span class="gcash-review-card-text">
                            <strong>Unresolved exchanges &middot; <?= (int)$overview['unresolved_count'] ?></strong>
                        </span>
                    </span>
                    <i class="bi bi-arrow-up-right" aria-hidden="true"></i>
                </button>
            <?php endif; ?>
            <?php if ((int)$overview['differences_count'] > 0): ?>
                <button type="button" class="btn btn-secondary gcash-review-card-btn gcash-review-diff" data-review-tab="reconciliation">
                    <span class="gcash-review-card-left">
                        <span class="gcash-review-icon gcash-review-icon-diff"><i class="bi bi-journal-check" aria-hidden="true"></i></span>
                        <span class="gcash-review-card-text">
                            <strong>Closing checks &middot; <?= (int)$overview['differences_count'] ?> pending</strong>
                        </span>
                    </span>
                    <i class="bi bi-arrow-up-right" aria-hidden="true"></i>
                </button>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</section>
