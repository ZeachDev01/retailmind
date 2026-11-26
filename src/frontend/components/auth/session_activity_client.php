<script id="rm-session-policy" type="application/json"><?= json_encode([
    'endpoint' => app_url('components/auth/session_activity.php'),
    'login' => app_url('?login=1'),
    'transfer' => app_url('components/auth/cashier_login.php'),
    'csrf' => csrf_token(),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="<?= htmlspecialchars(app_url('assets/js/session-activity.js?v=' . filemtime(__DIR__ . '/../../assets/js/session-activity.js')), ENT_QUOTES, 'UTF-8') ?>"></script>
