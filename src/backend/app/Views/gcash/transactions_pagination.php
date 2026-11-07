<div class="gcash-pagination" data-fragment="transactions-pagination">
    <div>
        Page <strong id="admin-current-page"><?= $page ?></strong>
    </div>
    <div class="gcash-pagination-actions">
        <?php if ($page > 1): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" class="btn btn-secondary btn-sm" id="admin-prev-page">Previous</a>
        <?php endif; ?>
        <?php if (!empty($overview['has_next'])): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" class="btn btn-secondary btn-sm" id="admin-next-page">Next</a>
        <?php endif; ?>
    </div>
</div>
