<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';

if (!is_logged_in()) {
    header('Location: ' . app_url('?login=1'));
    exit;
}
if ((bool)($_SESSION['is_recovery_account'] ?? false)) {
    http_response_code(403);
    exit('Recovery Account credentials can be rotated only through the offline recovery procedure.');
}

// Dual-mode screen (ticket #44, part of #42): the Temporary Password flag
// selects Mandatory mode (forced copy, navigation blocked by the session
// gate) versus Voluntary mode (standard copy, reachable from Profile).
// Validation, flag clearing, session revocation, and workspace landing are
// shared by both modes on the single code path below.
$isMandatory = (bool)($_SESSION['must_change_password'] ?? false);

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $currentPassword = (string)($_POST['current_password'] ?? '');
    $newPassword = (string)($_POST['new_password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    $stmt = $pdo->prepare('SELECT password_hash, session_version FROM users WHERE user_id = ? LIMIT 1');
    $stmt->execute([(int)$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($currentPassword, (string)$user['password_hash'])) {
        $message = 'The current password is incorrect.';
    } elseif ($newPassword !== $confirmPassword) {
        $message = 'The new passwords do not match.';
    } elseif ($newPassword === $currentPassword) {
        $message = 'Choose a new password that is different from the current password.';
    } elseif ($policyError = password_policy_error($newPassword)) {
        $message = $policyError;
    } else {
        try {
            $newVersion = (int)$user['session_version'] + 1;
            $pdo->prepare(
                'UPDATE users SET password_hash = ?, password_changed_at = NOW(), must_change_password = 0, session_version = ?, failed_login_attempts = 0, locked_until = NULL WHERE user_id = ?'
            )->execute([password_hash($newPassword, PASSWORD_DEFAULT), $newVersion, (int)$_SESSION['user_id']]);

            $_SESSION['session_version'] = $newVersion;
            $_SESSION['must_change_password'] = false;
            $_SESSION['_flash_success'] = 'Password changed successfully.';
            if ($isMandatory) {
                $userRoles = $_SESSION['roles'] ?? [current_role()];
                if (in_array('admin', $userRoles, true) || current_role() === 'admin') {
                    \App\Guidance\WorkspaceWalkthroughService::inviteAdministrator($pdo, (int)$_SESSION['user_id']);
                }
            }
            log_activity(
                $pdo,
                (int)$_SESSION['user_id'],
                $isMandatory ? 'Mandatory password change completed' : 'Voluntary password change completed',
                'Authentication',
                (int)$_SESSION['user_id'],
                null,
                ['status' => 'completed', 'mode' => $isMandatory ? 'mandatory' : 'voluntary']
            );
        } catch (Throwable $exception) {
            $message = \App\Support\OperatorAlert::message($exception, 'Your password could not be changed. Check your connection and try again. Tell your Administrator if this keeps happening.');
        }
        if ($message === '') {
            redirect_by_role();
        }
    }
}

$brandBaseUrl = htmlspecialchars(app_url('assets/img/retailmind-web-assets%20dark%20mode/'), ENT_QUOTES, 'UTF-8');
$brandLogoUrl = $brandBaseUrl . 'logos/light/logo-header.png';
?>
<!DOCTYPE html>
<html lang="en">

<head><?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $isMandatory ? 'Security Update — RetailMind' : 'Change Password — RetailMind' ?></title>
    <link rel="stylesheet" href="<?= htmlspecialchars(theme_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <style>
        /* ==========================================================================
           Landing-Page Canvas & Theme Variables
           ========================================================================== */
        :root {
            --cp-canvas: #f8fafc;
            --cp-surface: #ffffff;
            --cp-surface-soft: #f1f5f9;
            --cp-line: #cbd5e1;
            --cp-line-subtle: #94a3b8;
            --cp-ink: #0f172a;
            --cp-muted: #334155;
            --cp-blue: #2563eb;
            --cp-blue-deep: #1d4ed8;
            --cp-blue-glow: rgba(37, 99, 235, 0.22);
        }

        html[data-theme="dark"] {
            --cp-canvas: #080d1a;
            --cp-surface: #0e1626;
            --cp-surface-soft: #141f33;
            --cp-line: #334155;
            --cp-line-subtle: #475569;
            --cp-ink: #f8fafc;
            --cp-muted: #cbd5e1;
            --cp-blue: #3b82f6;
            --cp-blue-deep: #2563eb;
            --cp-blue-glow: rgba(59, 130, 246, 0.32);
        }

        body {
            background-color: var(--cp-canvas);
            color: var(--cp-ink);
            min-height: 100vh;
            margin: 0;
            overflow-x: hidden;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
        }

        /* Fixed always-visible Theme / Dark Mode toggle */
        .theme-topbar {
            position: fixed !important;
            top: 1rem !important;
            right: 1.25rem !important;
            z-index: 1000 !important;
            background: transparent !important;
            border: none !important;
            padding: 0 !important;
            display: flex !important;
            justify-content: flex-end !important;
        }

        .theme-topbar .theme-trigger {
            background: var(--cp-surface, #ffffff) !important;
            border: 1px solid var(--cp-line, #cbd5e1) !important;
            box-shadow: 0 4px 14px rgba(15, 23, 42, 0.08) !important;
            border-radius: 999px !important;
            min-height: 38px !important;
            height: 38px !important;
            padding: 0.35rem 0.95rem !important;
            color: var(--cp-ink, #0f172a) !important;
            font-size: 0.82rem !important;
            font-weight: 700 !important;
            cursor: pointer !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 0.45rem !important;
        }

        .theme-topbar .theme-trigger:hover {
            border-color: var(--cp-blue) !important;
        }

        html[data-theme="dark"] .theme-topbar .theme-trigger {
            background: #152238 !important;
            border-color: #41516a !important;
            color: #e5edf8 !important;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.45) !important;
        }

        /* Viewport Center Shell (Fits completely without zoom) */
        .u-auth-main {
            position: relative;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 1.5rem 1rem;
            box-sizing: border-box;
            background: var(--cp-canvas);
            overflow-y: auto;
            z-index: 1;
        }

        /* Ambient Glowing Lighting & Pattern */
        .cp-bg-glow-top {
            position: absolute;
            top: -120px;
            left: 50%;
            transform: translateX(-50%);
            width: clamp(480px, 70vw, 840px);
            height: clamp(320px, 45vw, 500px);
            background: radial-gradient(ellipse at center, rgba(37, 99, 235, 0.18) 0%, rgba(6, 182, 212, 0.08) 45%, transparent 70%);
            filter: blur(60px);
            pointer-events: none;
            z-index: 0;
        }

        .cp-bg-glow-bottom {
            position: absolute;
            bottom: -120px;
            right: 15%;
            width: clamp(380px, 50vw, 620px);
            height: clamp(280px, 40vw, 450px);
            background: radial-gradient(circle, rgba(99, 102, 241, 0.12) 0%, rgba(16, 185, 129, 0.06) 50%, transparent 70%);
            filter: blur(70px);
            pointer-events: none;
            z-index: 0;
        }

        .cp-bg-grid {
            position: absolute;
            inset: 0;
            background-image: radial-gradient(circle at center, rgba(37, 99, 235, 0.14) 1.25px, transparent 1.25px);
            background-size: 24px 24px;
            mask-image: radial-gradient(ellipse 75% 65% at 50% 50%, #000 35%, transparent 100%);
            -webkit-mask-image: radial-gradient(ellipse 75% 65% at 50% 50%, #000 35%, transparent 100%);
            pointer-events: none;
            z-index: 0;
        }

        html[data-theme="dark"] .cp-bg-glow-top {
            background: radial-gradient(ellipse at center, rgba(59, 130, 246, 0.24) 0%, rgba(99, 102, 241, 0.14) 45%, transparent 70%);
        }

        html[data-theme="dark"] .cp-bg-glow-bottom {
            background: radial-gradient(circle, rgba(99, 102, 241, 0.16) 0%, rgba(6, 182, 212, 0.08) 50%, transparent 70%);
        }

        html[data-theme="dark"] .cp-bg-grid {
            background-image: radial-gradient(circle at center, rgba(96, 165, 250, 0.14) 1.25px, transparent 1.25px);
        }

        /* RetailMind Brand Logo: Bold & Sizable */
        .cp-brand-header {
            position: relative;
            z-index: 2;
            margin-bottom: 0.95rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            transition: opacity 0.2s ease, transform 0.2s ease;
        }

        .cp-brand-header:hover {
            opacity: 0.92;
            transform: translateY(-1px);
        }

        .cp-brand-logo {
            height: 48px;
            width: auto;
            display: block;
        }

        /* Card Container */
        .cp-card {
            position: relative;
            z-index: 2;
            width: min(480px, 100%);
            padding: 1.75rem 1.85rem 1.45rem;
            border-radius: 20px;
            background: rgba(255, 255, 255, 0.97);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--cp-line);
            box-shadow: 0 20px 45px -12px rgba(15, 23, 42, 0.12), 0 0 0 1px rgba(15, 23, 42, 0.03);
            overflow: hidden;
            box-sizing: border-box;
            animation: cpCardIn 0.35s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }

        html[data-theme="dark"] .cp-card {
            background: rgba(14, 22, 38, 0.94);
            border-color: var(--cp-line);
            box-shadow: 0 25px 55px -15px rgba(0, 0, 0, 0.6), 0 0 0 1px rgba(255, 255, 255, 0.06);
        }

        .cp-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #2563eb 0%, #6366f1 50%, #06b6d4 100%);
        }

        @keyframes cpCardIn {
            from {
                opacity: 0;
                transform: translateY(14px) scale(0.985);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        /* Card Header & Badges */
        .cp-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 0.85rem;
        }

        .cp-icon-wrap {
            width: 40px;
            height: 40px;
            border-radius: 11px;
            background: rgba(37, 99, 235, 0.1);
            color: var(--cp-blue);
            display: grid;
            place-items: center;
            font-size: 1.2rem;
            flex-shrink: 0;
            border: 1px solid rgba(37, 99, 235, 0.2);
        }

        .cp-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            padding: 0.28rem 0.7rem;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.01em;
            white-space: nowrap;
        }

        .cp-badge-mandatory {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #f87171;
        }

        .cp-badge-voluntary {
            background: #dbeafe;
            color: #1e40af;
            border: 1px solid #93c5fd;
        }

        html[data-theme="dark"] .cp-badge-mandatory {
            background: rgba(239, 68, 68, 0.2);
            color: #fca5a5;
            border-color: rgba(239, 68, 68, 0.45);
        }

        html[data-theme="dark"] .cp-badge-voluntary {
            background: rgba(59, 130, 246, 0.2);
            color: #93c5fd;
            border-color: rgba(59, 130, 246, 0.45);
        }

        .cp-status-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            display: inline-block;
        }

        .cp-badge-mandatory .cp-status-dot {
            background: #dc2626;
            box-shadow: 0 0 0 2px rgba(220, 38, 38, 0.25);
            animation: cpPulse 2s infinite ease-in-out;
        }

        .cp-badge-voluntary .cp-status-dot {
            background: #2563eb;
            box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.25);
            animation: cpPulse 2s infinite ease-in-out;
        }

        @keyframes cpPulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.5; transform: scale(0.85); }
        }

        /* Typography */
        .u-auth-title {
            font-size: 1.4rem;
            font-weight: 800;
            color: var(--cp-ink);
            margin: 0 0 0.25rem 0;
            letter-spacing: -0.02em;
            line-height: 1.2;
        }

        .cp-subtitle {
            font-size: 0.85rem;
            color: var(--cp-muted);
            line-height: 1.45;
            margin: 0;
            font-weight: 500;
        }

        .section-header {
            margin-bottom: 0.95rem;
        }

        /* Error Alert */
        .cp-alert {
            display: flex;
            align-items: center;
            gap: 0.65rem;
            padding: 0.75rem 0.95rem;
            border-radius: 10px;
            margin-bottom: 1rem;
            font-size: 0.86rem;
            font-weight: 600;
            animation: cpShake 0.4s ease-in-out;
        }

        .cp-alert i {
            font-size: 1.15rem;
            flex-shrink: 0;
        }

        @keyframes cpShake {
            0%, 100% { transform: translateX(0); }
            20%, 60% { transform: translateX(-4px); }
            40%, 80% { transform: translateX(4px); }
        }

        /* Form Layout: Full-Width Uniform Grid */
        .cp-form {
            display: flex !important;
            flex-direction: column !important;
            width: 100% !important;
            gap: 0.95rem !important;
        }

        /* Field Container: Guaranteed Full Width */
        .cp-field {
            display: flex !important;
            flex-direction: column !important;
            width: 100% !important;
            min-width: 100% !important;
            box-sizing: border-box !important;
            gap: 0.35rem !important;
            margin-bottom: 0 !important;
        }

        .cp-field-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
        }

        .cp-label {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            font-size: 0.84rem;
            font-weight: 700;
            color: var(--cp-ink);
            margin: 0;
        }

        .cp-label i {
            color: var(--cp-blue);
            font-size: 0.92rem;
        }

        /* ==========================================================================
           Guaranteed Uniform Length (Width & Height) for All Input Fields
           ========================================================================== */
        .cp-input-wrap {
            position: relative !important;
            display: block !important;
            width: 100% !important;
            min-width: 100% !important;
            max-width: 100% !important;
            box-sizing: border-box !important;
            margin: 0 !important;
        }

        .cp-input-wrap input {
            display: block !important;
            width: 100% !important;
            min-width: 100% !important;
            max-width: 100% !important;
            height: 46px !important;
            min-height: 46px !important;
            max-height: 46px !important;
            box-sizing: border-box !important;
            padding: 0 4.6rem 0 1rem !important;
            font-size: 0.95rem !important;
            line-height: 44px !important;
            border-radius: 10px !important;
            border: 1.5px solid var(--cp-line) !important;
            background: var(--cp-surface) !important;
            color: var(--cp-ink) !important;
            transition: border-color 0.16s ease, box-shadow 0.16s ease !important;
            margin: 0 !important;
        }

        html[data-theme="dark"] .cp-input-wrap input {
            background: #101c2e !important;
            border-color: var(--cp-line) !important;
            color: #f8fafc !important;
        }

        .cp-input-wrap input::placeholder {
            color: #64748b !important;
            opacity: 1 !important;
            font-weight: 500 !important;
        }

        html[data-theme="dark"] .cp-input-wrap input::placeholder {
            color: #94a3b8 !important;
        }

        .cp-input-wrap input:hover {
            border-color: var(--cp-line-subtle) !important;
        }

        .cp-input-wrap input:focus {
            border-color: var(--cp-blue) !important;
            box-shadow: 0 0 0 3px var(--cp-blue-glow) !important;
            outline: none !important;
        }

        /* Password Toggle Button: Uniform, Cleanly Positioned inside Input */
        .password-toggle {
            position: absolute !important;
            top: 50% !important;
            right: 0.45rem !important;
            bottom: auto !important;
            transform: translateY(-50%) !important;
            height: 32px !important;
            min-height: 32px !important;
            max-height: 32px !important;
            padding: 0 0.65rem !important;
            border-radius: 7px !important;
            border: none !important;
            background: var(--cp-surface-soft) !important;
            color: var(--cp-ink) !important;
            font-size: 0.78rem !important;
            font-weight: 700 !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 0.35rem !important;
            cursor: pointer !important;
            margin: 0 !important;
            transition: background-color 0.15s ease, color 0.15s ease !important;
        }

        .password-toggle:hover,
        .password-toggle:focus-visible {
            background: var(--cp-line) !important;
            color: var(--cp-ink) !important;
            outline: none !important;
        }

        /* Password Strength Meter */
        .cp-meter-container {
            width: 100% !important;
            box-sizing: border-box !important;
            margin-top: 0.4rem;
            display: flex;
            flex-direction: column;
            gap: 0.4rem;
        }

        .cp-meter-bars {
            display: flex;
            width: 100%;
            gap: 6px;
            height: 4px;
        }

        .cp-meter-bar {
            flex: 1;
            height: 100%;
            border-radius: 999px;
            background: var(--cp-line);
            transition: background-color 0.25s ease;
        }

        .cp-meter-bar.active-weak { background: #ef4444; }
        .cp-meter-bar.active-fair { background: #f59e0b; }
        .cp-meter-bar.active-good { background: #3b82f6; }
        .cp-meter-bar.active-strong { background: #10b981; }

        /* Requirement Chips */
        .cp-requirements {
            display: flex;
            flex-wrap: wrap;
            width: 100%;
            gap: 0.4rem;
        }

        .cp-req-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.22rem 0.55rem;
            border-radius: 999px;
            font-size: 0.73rem;
            font-weight: 600;
            color: var(--cp-muted);
            background: var(--cp-surface-soft);
            border: 1px solid var(--cp-line);
            transition: all 0.22s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .cp-req-chip i {
            font-size: 0.75rem;
            opacity: 0.7;
        }

        .cp-req-chip.valid {
            color: #065f46;
            background: #d1fae5;
            border-color: #6ee7b7;
            font-weight: 700;
            transform: translateY(-1px);
        }

        .cp-req-chip.valid i {
            opacity: 1;
            color: #059669;
        }

        html[data-theme="dark"] .cp-req-chip {
            background: rgba(255, 255, 255, 0.05);
            border-color: var(--cp-line);
            color: var(--cp-muted);
        }

        html[data-theme="dark"] .cp-req-chip.valid {
            background: rgba(16, 185, 129, 0.22);
            color: #a7f3d0;
            border-color: rgba(16, 185, 129, 0.5);
        }

        html[data-theme="dark"] .cp-req-chip.valid i {
            color: #6ee7b7;
        }

        /* Match Indicator */
        .cp-match-pill {
            font-size: 0.74rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            opacity: 0;
            transform: translateY(2px);
            transition: opacity 0.2s ease, transform 0.2s ease;
        }

        .cp-match-pill.visible {
            opacity: 1;
            transform: translateY(0);
        }

        .cp-match-pill.valid { color: #059669; }
        .cp-match-pill.invalid { color: #dc2626; }

        html[data-theme="dark"] .cp-match-pill.valid { color: #6ee7b7; }
        html[data-theme="dark"] .cp-match-pill.invalid { color: #fca5a5; }

        /* Actions Footer */
        .u-auth-actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            width: 100%;
            margin-top: 0.5rem;
            padding-top: 1.15rem;
            border-top: 1px solid var(--cp-line);
            box-sizing: border-box;
        }

        .cp-btn-quiet {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            min-height: 44px;
            padding: 0 1.15rem;
            border-radius: 10px;
            font-size: 0.88rem;
            font-weight: 700;
            color: var(--cp-muted);
            text-decoration: none;
            border: 1.5px solid var(--cp-line);
            background: var(--cp-surface);
            transition: all 0.16s ease;
        }

        .cp-btn-quiet:hover,
        .cp-btn-quiet:focus-visible {
            background: var(--cp-surface-soft);
            color: var(--cp-ink);
            border-color: var(--cp-line-subtle);
            transform: translateY(-1px);
        }

        .cp-btn-primary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.55rem;
            min-height: 44px;
            padding: 0 1.65rem;
            border-radius: 10px;
            font-size: 0.94rem;
            font-weight: 700;
            color: #ffffff;
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            border: none;
            box-shadow: 0 4px 16px var(--cp-blue-glow);
            cursor: pointer;
            transition: transform 0.16s ease, box-shadow 0.16s ease, background 0.16s ease;
        }

        .cp-btn-primary:hover {
            background: linear-gradient(135deg, #1d4ed8 0%, #1e40af 100%);
            transform: translateY(-1.5px);
            box-shadow: 0 6px 20px var(--cp-blue-glow);
        }

        .cp-btn-primary:active {
            transform: translateY(1px);
        }

        .cp-btn-primary i {
            transition: transform 0.18s ease;
        }

        .cp-btn-primary:hover i {
            transform: translateX(3px);
        }

        /* Screen reader assistance hint for contracts */
        .cp-sr-hint {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip-path: inset(50%);
            white-space: nowrap;
            border: 0;
        }

        @media (max-width: 480px) {
            .cp-card {
                padding: 1.4rem 1.15rem 1.25rem;
            }
            .u-auth-actions {
                flex-direction: column-reverse;
                width: 100%;
            }
            .cp-btn-quiet, .cp-btn-primary {
                width: 100%;
                justify-content: center;
            }
            .theme-topbar {
                top: 0.6rem !important;
                right: 0.8rem !important;
            }
        }
    </style>
</head>

<body>
    <main class="u-auth-main">
        <!-- Landing-style background effects: ambient orbs & blueprint dot grid -->
        <div class="cp-bg-glow-top" aria-hidden="true"></div>
        <div class="cp-bg-glow-bottom" aria-hidden="true"></div>
        <div class="cp-bg-grid" aria-hidden="true"></div>

        <!-- Prominent RetailMind Brand Logo -->
        <a class="cp-brand-header" href="<?= htmlspecialchars(app_url(), ENT_QUOTES, 'UTF-8') ?>" title="RetailMind">
            <img class="cp-brand-logo" src="<?= $brandLogoUrl ?>" data-brand-base="<?= $brandBaseUrl ?>" data-brand-variant="logos/STYLE/logo-header.png" alt="RetailMind">
        </a>

        <section class="dashboard-section u-auth-card cp-card" aria-labelledby="auth_title">
            <div class="cp-header">
                <div class="cp-icon-wrap" aria-hidden="true">
                    <i class="bi bi-shield-lock-fill"></i>
                </div>
                <span class="cp-badge <?= $isMandatory ? 'cp-badge-mandatory' : 'cp-badge-voluntary' ?>">
                    <span class="cp-status-dot"></span>
                    <?= $isMandatory ? 'Mandatory Security Update' : 'Account Security' ?>
                </span>
            </div>

            <div class="section-header">
                <div>
                    <h1 id="auth_title" class="u-auth-title"><?= $isMandatory ? 'Create a new password' : 'Change password' ?></h1>
                    <p class="section-description cp-subtitle">
                        <?= $isMandatory ? 'The temporary password must be replaced before you can access RetailMind.' : 'You are changing your password voluntarily. Enter your current password to choose a new one.' ?>
                    </p>
                </div>
            </div>

            <?php if ($message): ?>
                <div class="alert tag-warning cp-alert" role="alert" aria-live="polite">
                    <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                    <div><?= htmlspecialchars($message) ?></div>
                </div>
            <?php endif; ?>

            <form method="POST" class="cp-form form-grid" autocomplete="off">
                <?= csrf_field() ?>

                <div class="cp-field form-group u-grid-full">
                    <label for="current_password" class="cp-label">
                        <i class="bi bi-key-fill" aria-hidden="true"></i> Current password
                    </label>
                    <div class="cp-input-wrap password-input">
                        <input type="password" id="current_password" name="current_password" required autocomplete="current-password" placeholder="Enter current password">
                        <button type="button" class="password-toggle" data-password-toggle="current_password" aria-controls="current_password" aria-pressed="false" aria-label="Show current password">
                            <i class="bi bi-eye" aria-hidden="true"></i>
                            <span>Show</span>
                        </button>
                    </div>
                </div>

                <div class="cp-field form-group u-grid-full">
                    <label for="new_password" class="cp-label">
                        <i class="bi bi-lock-fill" aria-hidden="true"></i> New password
                    </label>
                    <div class="cp-input-wrap password-input">
                        <input type="password" id="new_password" name="new_password" required minlength="8" autocomplete="new-password" placeholder="Enter new password" aria-describedby="new_password_help">
                        <button type="button" class="password-toggle" data-password-toggle="new_password" aria-controls="new_password" aria-pressed="false" aria-label="Show new password">
                            <i class="bi bi-eye" aria-hidden="true"></i>
                            <span>Show</span>
                        </button>
                    </div>
                    <!-- Accessible hint requirement for policy contract -->
                    <small id="new_password_help" class="field-help cp-sr-hint">Use at least 8 characters with uppercase, lowercase, and a number.</small>

                    <!-- Real-time visual strength meter & requirement chips -->
                    <div class="cp-meter-container" aria-hidden="true">
                        <div class="cp-meter-bars">
                            <span class="cp-meter-bar" id="bar1"></span>
                            <span class="cp-meter-bar" id="bar2"></span>
                            <span class="cp-meter-bar" id="bar3"></span>
                            <span class="cp-meter-bar" id="bar4"></span>
                        </div>
                        <div class="cp-requirements">
                            <span class="cp-req-chip" id="req-len"><i class="bi bi-circle"></i> 8+ chars</span>
                            <span class="cp-req-chip" id="req-upper"><i class="bi bi-circle"></i> Uppercase</span>
                            <span class="cp-req-chip" id="req-lower"><i class="bi bi-circle"></i> Lowercase</span>
                            <span class="cp-req-chip" id="req-num"><i class="bi bi-circle"></i> Number</span>
                        </div>
                    </div>
                </div>

                <div class="cp-field form-group u-grid-full">
                    <div class="cp-field-header">
                        <label for="confirm_password" class="cp-label">
                            <i class="bi bi-shield-check" aria-hidden="true"></i> Confirm new password
                        </label>
                        <span class="cp-match-pill" id="match-status" aria-live="polite">
                            <i class="bi bi-check2-circle"></i> Passwords match
                        </span>
                    </div>
                    <div class="cp-input-wrap password-input">
                        <input type="password" id="confirm_password" name="confirm_password" required minlength="8" autocomplete="new-password" placeholder="Re-enter new password">
                        <button type="button" class="password-toggle" data-password-toggle="confirm_password" aria-controls="confirm_password" aria-pressed="false" aria-label="Show confirmation password">
                            <i class="bi bi-eye" aria-hidden="true"></i>
                            <span>Show</span>
                        </button>
                    </div>
                </div>

                <div class="u-auth-actions">
                    <a class="btn btn-quiet cp-btn-quiet" href="<?= htmlspecialchars(app_url('components/auth/logout.php')) ?>">
                        <i class="bi bi-box-arrow-left" aria-hidden="true"></i> Log Out
                    </a>
                    <button class="btn cp-btn-primary" type="submit">
                        <span>Save new password</span>
                        <i class="bi bi-arrow-right" aria-hidden="true"></i>
                    </button>
                </div>
            </form>
        </section>
    </main>

    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/vendor/sweetalert2/sweetalert2.min.css')) ?>">
    <script src="<?= htmlspecialchars(app_url('assets/vendor/sweetalert2/sweetalert2.all.min.js')) ?>"></script>
    <script src="<?= htmlspecialchars(app_url('assets/js/ui.js')) ?>"></script>
    <script src="<?= htmlspecialchars(app_url('assets/js/password-feedback.js')) ?>"></script>
    <script>
        // Password visibility toggle with icon & aria sync
        document.querySelectorAll('[data-password-toggle]').forEach(function(toggle) {
            var input = document.getElementById(toggle.dataset.passwordToggle);
            if (!input) return;
            var targetName = (toggle.getAttribute('aria-label') || '').replace(/^Show\s+/i, '');
            toggle.addEventListener('click', function() {
                var isVisible = input.type === 'text';
                input.type = isVisible ? 'password' : 'text';
                var icon = toggle.querySelector('i');
                var span = toggle.querySelector('span');
                if (icon) {
                    icon.className = isVisible ? 'bi bi-eye' : 'bi bi-eye-slash';
                }
                if (span) {
                    span.textContent = isVisible ? 'Show' : 'Hide';
                } else {
                    toggle.textContent = isVisible ? 'Show' : 'Hide';
                }
                toggle.setAttribute('aria-pressed', isVisible ? 'false' : 'true');
                toggle.setAttribute('aria-label', (isVisible ? 'Show ' : 'Hide ') + targetName);
            });
        });

        // Real-time password strength & requirements checklist
        var newPasswordInput = document.getElementById('new_password');
        var confirmPasswordInput = document.getElementById('confirm_password');
        var reqLen = document.getElementById('req-len');
        var reqUpper = document.getElementById('req-upper');
        var reqLower = document.getElementById('req-lower');
        var reqNum = document.getElementById('req-num');
        var bar1 = document.getElementById('bar1');
        var bar2 = document.getElementById('bar2');
        var bar3 = document.getElementById('bar3');
        var bar4 = document.getElementById('bar4');
        var matchStatus = document.getElementById('match-status');

        function updateReq(el, valid) {
            if (!el) return;
            if (valid) {
                el.classList.add('valid');
                el.querySelector('i').className = 'bi bi-check-circle-fill';
            } else {
                el.classList.remove('valid');
                el.querySelector('i').className = 'bi bi-circle';
            }
        }

        function updateStrength() {
            var val = (newPasswordInput && newPasswordInput.value) || '';
            var hasLen = val.length >= 8;
            var hasUpper = /[A-Z]/.test(val);
            var hasLower = /[a-z]/.test(val);
            var hasNum = /[0-9]/.test(val);

            updateReq(reqLen, hasLen);
            updateReq(reqUpper, hasUpper);
            updateReq(reqLower, hasLower);
            updateReq(reqNum, hasNum);

            var score = (hasLen ? 1 : 0) + (hasUpper ? 1 : 0) + (hasLower ? 1 : 0) + (hasNum ? 1 : 0);
            var bars = [bar1, bar2, bar3, bar4];
            bars.forEach(function(b) {
                if (!b) return;
                b.className = 'cp-meter-bar';
            });

            if (val.length > 0) {
                var levelClass = score === 4 ? 'active-strong' : (score === 3 ? 'active-good' : (score === 2 ? 'active-fair' : 'active-weak'));
                for (var i = 0; i < score; i++) {
                    if (bars[i]) bars[i].classList.add(levelClass);
                }
            }

            validatePasswordMatch();
        }

        function validatePasswordMatch() {
            if (!confirmPasswordInput || !newPasswordInput) return;
            var nVal = newPasswordInput.value || '';
            var cVal = confirmPasswordInput.value || '';

            if (!cVal) {
                if (matchStatus) {
                    matchStatus.className = 'cp-match-pill';
                    matchStatus.innerHTML = '';
                }
                confirmPasswordInput.setCustomValidity('');
                return;
            }

            var matches = (nVal === cVal);
            if (matches) {
                confirmPasswordInput.setCustomValidity('');
                if (matchStatus) {
                    matchStatus.className = 'cp-match-pill visible valid';
                    matchStatus.innerHTML = '<i class="bi bi-check-circle-fill" aria-hidden="true"></i> Passwords match';
                }
            } else {
                confirmPasswordInput.setCustomValidity('Passwords do not match.');
                if (matchStatus) {
                    matchStatus.className = 'cp-match-pill visible invalid';
                    matchStatus.innerHTML = '<i class="bi bi-x-circle-fill" aria-hidden="true"></i> Passwords do not match';
                }
            }
        }

        if (newPasswordInput) {
            newPasswordInput.addEventListener('input', updateStrength);
        }
        if (confirmPasswordInput) {
            confirmPasswordInput.addEventListener('input', validatePasswordMatch);
        }
    </script>
<?php require __DIR__ . '/session_activity_client.php'; ?>
</body>

</html>
