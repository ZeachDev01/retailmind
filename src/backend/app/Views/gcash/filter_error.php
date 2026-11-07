<?php if ($filterError): ?>
    <div class="alert alert-danger gcash-filter-error" role="alert">
        <i class="bi bi-exclamation-triangle" aria-hidden="true"></i> <?= gcash_admin_e($filterError) ?>
    </div>
<?php endif; ?>
