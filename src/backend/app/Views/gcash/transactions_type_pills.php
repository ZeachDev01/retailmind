<div class="gcash-type-pills" role="group" aria-label="Transaction type" data-fragment="transactions-type-pills">
    <?php foreach (['all' => ['All', 'all_count'], 'Cash-In' => ['Cash-In', 'cash_in_count'], 'Cash-Out' => ['Cash-Out', 'cash_out_count']] as $value => [$label, $countKey]): ?>
        <button type="button" class="btn btn-secondary" data-type="<?= $value ?>" aria-pressed="<?= ($_GET['type'] ?? 'all') === $value ? 'true' : 'false' ?>"><?= $label ?> (<?= $overview === null ? 'Unavailable' : (int)$overview['type_counts'][$countKey] ?>)</button>
    <?php endforeach; ?>
</div>
