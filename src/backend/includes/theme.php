<?php
require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/csrf.php';

function theme_url(string $path): string
{
    return function_exists('app_url') ? app_url($path) : landing_app_url($path);
}

function retailmind_theme_head(): void
{
    global $pdo;
    $mode = 'system';
    $authenticated = isset($_SESSION['user_id']);
    if ($authenticated) {
        if (isset($_SESSION['theme_preference']) && in_array($_SESSION['theme_preference'], ['light', 'dark', 'system'], true)) {
            $mode = $_SESSION['theme_preference'];
        } else {
            try {
                $connection = $pdo ?? App\Core\Database::connection();
                $statement = $connection->prepare('SELECT theme_preference FROM users WHERE user_id = ?');
                $statement->execute([(int)$_SESSION['user_id']]);
                $saved = $statement->fetchColumn();
                if (in_array($saved, ['light', 'dark', 'system'], true)) {
                    $mode = $saved;
                    $_SESSION['theme_preference'] = $saved;
                }
            } catch (Throwable $exception) {
                // Older installations remain usable until their database update is applied.
                error_log('Theme preference unavailable: ' . $exception->getMessage());
            }
        }
    }
    $config = ['mode' => $mode, 'authenticated' => $authenticated,
        'endpoint' => theme_url('components/auth/theme.php'), 'csrf' => csrf_token()];
    echo '<script>window.retailmindTheme=' . json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>';
    echo '<script src="' . htmlspecialchars(theme_url('assets/js/theme.js'), ENT_QUOTES, 'UTF-8') . '"></script>';
    echo '<link rel="stylesheet" href="' . htmlspecialchars(theme_url('assets/css/theme.css'), ENT_QUOTES, 'UTF-8') . '">';
    echo '<link rel="manifest" crossorigin="use-credentials" href="' . htmlspecialchars(theme_url('manifest.webmanifest'), ENT_QUOTES, 'UTF-8') . '">';
    echo '<meta name="mobile-web-app-capable" content="yes">';
    echo '<meta name="apple-mobile-web-app-capable" content="yes">';
    echo '<meta name="apple-mobile-web-app-title" content="RetailMind">';
    echo '<meta name="apple-mobile-web-app-status-bar-style" content="default">';
    echo '<link rel="apple-touch-icon" href="' . htmlspecialchars(theme_url('assets/img/retailmind-icon-512.png'), ENT_QUOTES, 'UTF-8') . '">';
}
