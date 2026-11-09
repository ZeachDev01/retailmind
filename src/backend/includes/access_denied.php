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
        ?>
<!DOCTYPE html>
<html lang="en" data-type="software">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Access denied &middot; RetailMind</title>
<style>
    :root {
        --denied-accent: #0f766e;
        --denied-accent-deep: #115e59;
        --denied-ink: #10201f;
        --denied-muted: #526b69;
        --denied-surface: #ffffff;
        --denied-danger: #b91c1c;
        --denied-danger-soft: #fef2f2;
        --denied-danger-border: #fecaca;
        --denied-border: rgba(15, 118, 110, 0.16);
        --denied-shadow: rgba(7, 45, 43, 0.16);
    }
    @media (prefers-color-scheme: dark) {
        :root {
            --denied-ink: #e8f4f2;
            --denied-muted: #9db3b1;
            --denied-surface: #101c1b;
            --denied-danger: #fca5a5;
            --denied-danger-soft: rgba(185, 28, 28, 0.18);
            --denied-danger-border: rgba(248, 113, 113, 0.34);
            --denied-border: rgba(255, 255, 255, 0.12);
            --denied-shadow: rgba(0, 0, 0, 0.5);
        }
    }
    * { box-sizing: border-box; }
    body.denied-body {
        margin: 0;
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 1.5rem;
        color: var(--denied-ink);
        font-family: "Segoe UI", system-ui, -apple-system, "Helvetica Neue", Arial, sans-serif;
        -webkit-font-smoothing: antialiased;
        background:
            radial-gradient(circle at 12% 0%, rgba(15, 118, 110, 0.20), transparent 42%),
            radial-gradient(circle at 88% 100%, rgba(220, 38, 38, 0.14), transparent 44%),
            linear-gradient(135deg, #f3fbfa 0%, #f6f8fb 100%);
    }
    @media (prefers-color-scheme: dark) {
        body.denied-body {
            background:
                radial-gradient(circle at 12% 0%, rgba(15, 118, 110, 0.30), transparent 42%),
                radial-gradient(circle at 88% 100%, rgba(185, 28, 28, 0.24), transparent 44%),
                linear-gradient(135deg, #071110 0%, #0b1218 100%);
        }
    }
    .denied-card {
        width: 100%;
        max-width: 520px;
        min-width: 0;
        padding: 2rem 2rem 1.75rem;
        border-radius: 22px;
        background: var(--denied-surface);
        border: 1px solid var(--denied-border);
        box-shadow: 0 24px 60px var(--denied-shadow);
    }
    .denied-brand {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.78rem;
        font-weight: 700;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        color: var(--denied-accent);
        margin-bottom: 1.6rem;
    }
    .denied-brand svg { width: 20px; height: 20px; }
    .denied-icon {
        display: grid;
        place-items: center;
        width: 52px;
        height: 52px;
        border-radius: 16px;
        margin-bottom: 1.1rem;
        color: var(--denied-danger);
        background: var(--denied-danger-soft);
        border: 1px solid var(--denied-danger-border);
    }
    .denied-icon svg { width: 26px; height: 26px; }
    .denied-code {
        margin: 0 0 0.4rem;
        font-size: 0.72rem;
        font-weight: 700;
        letter-spacing: 0.16em;
        text-transform: uppercase;
        color: var(--denied-muted);
    }
    .denied-title {
        margin: 0 0 0.65rem;
        font-size: clamp(1.5rem, 4vw, 1.85rem);
        line-height: 1.2;
        letter-spacing: -0.02em;
    }
    .denied-message {
        margin: 0 0 1.15rem;
        font-size: 0.98rem;
        line-height: 1.6;
        color: var(--denied-muted);
        overflow-wrap: anywhere;
    }
    .denied-reason {
        display: inline-block;
        margin: 0 0 1.5rem;
        padding: 0.32rem 0.65rem;
        border-radius: 999px;
        max-width: 100%;
        font-size: 0.74rem;
        font-weight: 600;
        letter-spacing: 0.02em;
        overflow-wrap: anywhere;
        color: var(--denied-accent-deep);
        background: rgba(15, 118, 110, 0.10);
        border: 1px solid rgba(15, 118, 110, 0.22);
    }
    @media (prefers-color-scheme: dark) {
        .denied-reason { color: #7fd8cf; background: rgba(15, 118, 110, 0.22); }
    }
    .denied-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 0.65rem;
        margin-bottom: 1.35rem;
    }
    .denied-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.45rem;
        min-height: 44px;
        padding: 0.65rem 1.15rem;
        border-radius: 11px;
        font-size: 0.94rem;
        font-weight: 600;
        text-decoration: none;
        transition: background-color 0.15s ease, box-shadow 0.15s ease;
    }
    .denied-btn-primary {
        color: #ffffff;
        background: var(--denied-accent);
        border: 1px solid var(--denied-accent);
    }
    .denied-btn-primary:hover { background: var(--denied-accent-deep); }
    .denied-btn-ghost {
        color: var(--denied-ink);
        background: transparent;
        border: 1px solid var(--denied-border);
    }
    .denied-btn-ghost:hover { background: rgba(15, 118, 110, 0.08); }
    .denied-btn:focus-visible {
        outline: 2px solid var(--denied-accent);
        outline-offset: 2px;
        box-shadow: 0 0 0 4px rgba(15, 118, 110, 0.22);
    }
    .denied-foot {
        margin: 0;
        padding-top: 1rem;
        border-top: 1px solid var(--denied-border);
        font-size: 0.8rem;
        line-height: 1.55;
        color: var(--denied-muted);
    }
</style>
</head>
<body class="denied-body">
    <main class="denied-card">
        <p class="denied-brand">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                <path d="M4 8.5 12 4l8 4.5v7L12 20l-8-4.5z"></path>
                <path d="M12 4v16"></path>
            </svg>
            RetailMind
        </p>
        <span class="denied-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" focusable="false">
                <rect x="4" y="10.5" width="16" height="10" rx="2.5"></rect>
                <path d="M8 10.5V7.5a4 4 0 0 1 8 0v3"></path>
                <path d="M12 14.5v2.5"></path>
            </svg>
        </span>
        <p class="denied-code">Error <?= (int)$status ?></p>
        <h1 class="denied-title">Access denied</h1>
        <p class="denied-message"><?= $escape($message) ?></p>
        <?php if ($reason !== ''): ?>
            <p class="denied-reason"><?= $escape($reason) ?></p>
        <?php endif; ?>
        <div class="denied-actions">
            <a class="denied-btn denied-btn-primary" href="<?= $escape($primaryHref) ?>"><?= $escape($primaryLabel) ?></a>
            <a class="denied-btn denied-btn-ghost" href="<?= $escape(access_denied_url()) ?>">Store home</a>
        </div>
        <p class="denied-foot"><?= $signedIn
            ? 'Your session is still active. If you believe this is a mistake, ask an administrator to check the workspace assigned to your account.'
            : 'Sign in with an account that has access. If you think this is a mistake, ask an administrator to check your workspace assignment.' ?></p>
    </main>
</body>
</html>
        <?php
        exit;
    }
}
