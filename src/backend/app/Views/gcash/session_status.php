<span class="gcash-status <?= ($overview['session_status'] ?? '') === 'open' ? 'gcash-status-completed' : '' ?>" id="gcash-session-status" data-fragment="session-status">
    <?= $overview === null ? 'Unavailable' : (!empty($overview['session_id']) ? 'Session #' . (int)$overview['session_id'] . ' · Open' : 'No active session') ?>
</span>
