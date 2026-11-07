<?php // Tab badges are returned as fragments so the rail never has to be re-rendered. ?>
<span class="gcash-badge" data-fragment="transactions-badge"><?= $overview === null ? 'Unavailable' : (int)$overview['matching_record_count'] ?></span>
<span class="gcash-badge" data-fragment="reconciliation-badge"><?= $overview === null ? 'Unavailable' : (int)$overview['differences_count'] . ' pending' ?></span>
<span class="gcash-badge <?= !empty($overview['unresolved_count']) ? 'gcash-status-needs-checking' : '' ?>" data-fragment="unresolved-badge"><?= $overview === null ? 'Unavailable' : (int)$overview['unresolved_count'] ?></span>
