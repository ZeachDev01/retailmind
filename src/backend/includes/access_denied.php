<?php
// Branded access-denied surface shared by every authorization gate.
// Self-contained on purpose: it must render even when the request failed
// before authentication, CSRF, the theme, or the view layer loaded.

if (!function_exists('access_denied_base_url')) {
    function access_denied_base_url(): string
    {
        $documentRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
        $projectRoot = rtrim(str_replace('\\', '/', dirname(__DIR__, 2) . '/frontend'), '/');
        if ($documentRoot === '' || $projectRoot === '') {
            return '';
        }
        if (strpos($projectRoot, $documentRoot) === 0) {
            $basePath = substr($projectRoot, strlen($documentRoot));
            $normalizedBasePath = $basePath === '' ? '' : '/' . trim($basePath, '/');
            if ($normalizedBasePath !== '' && isset($_SERVER['REQUEST_URI'])) {
                $requestPath = explode('?', (string)$_SERVER['REQUEST_URI'])[0];
                if (!str_starts_with($requestPath, $normalizedBasePath)) {
                    return !empty($_SERVER['HTTP_X_FORWARDED_PREFIX'])
                        ? '/' . trim((string)$_SERVER['HTTP_X_FORWARDED_PREFIX'], '/')
                        : '';
                }
            }
            return $normalizedBasePath;
        }
        return '';
    }
}

if (!function_exists('access_denied_url')) {
    function access_denied_url(string $path = ''): string
    {
        $baseUrl = access_denied_base_url();
        $normalizedPath = ltrim($path, '/');
        if ($normalizedPath === '') {
            return $baseUrl === '' ? '/' : $baseUrl . '/';
        }
        return ($baseUrl === '' ? '' : $baseUrl) . '/' . $normalizedPath;
    }
}

if (!function_exists('access_denied_page')) {
    /**
     * Render the shared "no access" page and stop the request.
     *
     * @param string $message Plain-language explanation shown to the user.
     * @param string $reason  Short scope label, e.g. "Super Administrator workspace · local only".
     */
    function access_denied_page(string $message, string $reason = '', int $status = 403): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Cache-Control: no-store');
            header('Content-Type: text/html; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
        }

        $signedIn = function_exists('is_logged_in') && is_logged_in();
        $primaryHref = $signedIn
            ? access_denied_url('components/auth/workspace.php')
            : access_denied_url('?login=1');
        $primaryLabel = $signedIn ? 'Switch workspace' : 'Go to sign in';
        $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $logoBase = access_denied_url('assets/img/retailmind-web-assets%20dark%20mode/logos/');
        $logoLight = $logoBase . 'light/logo-header.png';
        $logoDark = $logoBase . 'dark/logo-header.png';
        $themeMode = (string)($_SESSION['theme_preference'] ?? 'system');
        if (!in_array($themeMode, ['light', 'dark', 'system'], true)) {
            $themeMode = 'system';
        }
        $themeUserId = (int)($_SESSION['user_id'] ?? 0);
        ?>
<!DOCTYPE html>
<html lang="en" data-type="software">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Access denied &middot; RetailMind</title>
<script>
    // Standalone theme read: this page can render before the app theme layer loads.
    (function () {
        var mode = <?= json_encode($themeMode) ?>;
        var userId = <?= $themeUserId ?>;
        try {
            var savedUser = localStorage.getItem('retailmind.theme-user');
            if (userId > 0) {
                if (savedUser === String(userId)) {
                    mode = localStorage.getItem('retailmind.theme') || mode;
                }
            } else {
                mode = localStorage.getItem('retailmind.login-theme') || localStorage.getItem('retailmind.theme') || mode;
            }
        } catch (error) { /* Storage is optional on private browsers. */ }
        var dark = mode === 'dark'
            || (mode !== 'light' && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
        document.documentElement.dataset.theme = dark ? 'dark' : 'light';
        document.documentElement.style.colorScheme = dark ? 'dark' : 'light';
    })();
</script>
<style>
    :root {
        --denied-canvas: #f4f6f9;
        --denied-surface: #ffffff;
        --denied-surface-soft: #f8fafc;
        --denied-line: #e5e7eb;
        --denied-ink: #1f2937;
        --denied-muted: #6b7280;
        --denied-primary: #2563eb;
        --denied-primary-hover: #1d4ed8;
        --denied-shadow: 0 6px 18px rgba(15, 23, 42, 0.04);
    }
    html[data-theme="dark"] {
        --denied-canvas: #0b1220;
        --denied-surface: #152238;
        --denied-surface-soft: #1c2c43;
        --denied-line: #41516a;
        --denied-ink: #e5edf8;
        --denied-muted: #b1bfd3;
        --denied-primary: #2563eb;
        --denied-primary-hover: #1d4ed8;
        --denied-shadow: 0 6px 18px rgba(2, 6, 23, 0.28);
    }
    * { box-sizing: border-box; }
    body.denied-body {
        margin: 0;
        min-height: 100vh;
        background: var(--denied-canvas);
        color: var(--denied-ink);
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
        -webkit-font-smoothing: antialiased;
        line-height: 1.5;
    }
    .denied-main {
        min-height: 100vh;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 1.5rem 1rem;
    }
    .denied-brand {
        display: inline-flex;
        margin-bottom: 1.25rem;
        text-decoration: none;
    }
    .denied-logo { display: block; height: 40px; width: auto; }
    .denied-logo-dark { display: none; }
    html[data-theme="dark"] .denied-logo-light { display: none; }
    html[data-theme="dark"] .denied-logo-dark { display: block; }
    .denied-card {
        width: min(440px, 100%);
        padding: 1.75rem;
        border: 1px solid var(--denied-line);
        border-radius: 14px;
        background: var(--denied-surface);
        box-shadow: var(--denied-shadow);
    }
    .denied-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        margin-bottom: 1rem;
    }
    .denied-icon {
        width: 40px;
        height: 40px;
        border-radius: 10px;
        display: grid;
        place-items: center;
        flex-shrink: 0;
        color: var(--denied-muted);
        background: var(--denied-surface-soft);
        border: 1px solid var(--denied-line);
    }
    .denied-icon svg { width: 20px; height: 20px; }
    .denied-badge {
        display: inline-flex;
        align-items: center;
        padding: 0.2rem 0.55rem;
        border-radius: 6px;
        font-size: 0.72rem;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        white-space: nowrap;
        color: var(--denied-muted);
        background: var(--denied-surface-soft);
        border: 1px solid var(--denied-line);
    }
    .denied-title {
        margin: 0 0 0.4rem;
        font-size: 1.5rem;
        font-weight: 700;
        line-height: 1.2;
        letter-spacing: -0.02em;
    }
    .denied-message {
        margin: 0;
        font-size: 0.95rem;
        line-height: 1.5;
        color: var(--denied-muted);
        overflow-wrap: anywhere;
    }
    .denied-reason {
        margin: 0.75rem 0 0;
        font-size: 0.8rem;
        line-height: 1.4;
        color: var(--denied-muted);
        overflow-wrap: anywhere;
    }
    .denied-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        margin-top: 1.5rem;
        padding-top: 1.25rem;
        border-top: 1px solid var(--denied-line);
    }
    .denied-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        min-height: 44px;
        padding: 0 1rem;
        border-radius: 10px;
        font-size: 0.95rem;
        font-weight: 600;
        text-decoration: none;
        transition: background 0.16s ease, border-color 0.16s ease;
    }
    .denied-btn-primary {
        color: #ffffff;
        background: var(--denied-primary);
        border: 1px solid var(--denied-primary);
    }
    .denied-btn-primary:hover {
        background: var(--denied-primary-hover);
        border-color: var(--denied-primary-hover);
    }
    .denied-btn-ghost {
        color: var(--denied-ink);
        background: var(--denied-surface);
        border: 1px solid var(--denied-line);
    }
    .denied-btn-ghost:hover {
        background: var(--denied-surface-soft);
        border-color: var(--denied-muted);
    }
    .denied-btn:focus-visible {
        outline: 2px solid var(--denied-primary);
        outline-offset: 2px;
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.22);
    }
    @media (max-width: 480px) {
        .denied-card { padding: 1.4rem 1.15rem 1.25rem; }
        .denied-actions { flex-direction: column-reverse; }
        .denied-btn { width: 100%; }
    }
    @media (prefers-reduced-motion: reduce) {
        .denied-btn { transition: none; }
    }
</style>
</head>
<body class="denied-body">
    <main class="denied-main">
        <a class="denied-brand" href="<?= $escape(access_denied_url()) ?>" title="RetailMind">
            <img class="denied-logo denied-logo-light" src="<?= $escape($logoLight) ?>" alt="RetailMind">
            <img class="denied-logo denied-logo-dark" src="<?= $escape($logoDark) ?>" alt="" aria-hidden="true">
        </a>

        <section class="denied-card" aria-labelledby="denied_title">
            <div class="denied-head">
                <span class="denied-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" focusable="false">
                        <rect x="4" y="10.5" width="16" height="10" rx="2.5"></rect>
                        <path d="M8 10.5V7.5a4 4 0 0 1 8 0v3"></path>
                        <path d="M12 14.5v2.5"></path>
                    </svg>
                </span>
                <span class="denied-badge">Error <?= (int)$status ?></span>
            </div>

            <h1 id="denied_title" class="denied-title">Access denied</h1>
            <p class="denied-message"><?= $escape($message) ?></p>
            <?php if ($reason !== ''): ?>
                <p class="denied-reason"><?= $escape($reason) ?></p>
            <?php endif; ?>

            <div class="denied-actions">
                <a class="denied-btn denied-btn-primary" href="<?= $escape($primaryHref) ?>">
                    <span><?= $escape($primaryLabel) ?></span>
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                        <path d="M5 12h14"></path>
                        <path d="m13 6 6 6-6 6"></path>
                    </svg>
                </a>
                <a class="denied-btn denied-btn-ghost" href="<?= $escape(access_denied_url()) ?>">Store home</a>
            </div>
        </section>
    </main>
</body>
</html>
        <?php
        exit;
    }
}
