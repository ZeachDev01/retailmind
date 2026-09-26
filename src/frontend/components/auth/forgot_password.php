<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';

$message = '';
$messageClass = 'tag-success';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $identity = trim((string)($_POST['identity'] ?? ''));
    try {
        if ($identity !== '') {
            $stmt = $pdo->prepare("SELECT user_id, full_name, email FROM users WHERE (username = ? OR email = ?) AND status = 'active' AND is_recovery_account = 0 LIMIT 1");
            $stmt->execute([$identity, $identity]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user && filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
                $token = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $token);
                $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = ? OR expires_at < NOW()')->execute([(int)$user['user_id']]);
                $pdo->prepare('INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))')
                    ->execute([(int)$user['user_id'], $tokenHash]);
                $resetUrl = rtrim((string)env('APP_URL', ''), '/') . '/components/auth/reset_password.php?token=' . urlencode($token);
                send_email_notification(
                    (string)$user['email'],
                    'RetailMind password reset',
                    "Hello {$user['full_name']},\n\nUse this link within 30 minutes to reset your password:\n{$resetUrl}\n\nIgnore this email if you did not request a reset."
                );
            }
        }
        $message = 'If the account exists and has an email address, a password-reset link has been sent.';
    } catch (Throwable $exception) {
        $message = \App\Support\OperatorAlert::message($exception, 'The reset link could not be sent. Check your connection and try again. Tell your Administrator if this keeps happening.');
        $messageClass = 'tag-warning';
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Reset password | RetailMind</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
</head>

<body class="auth-page">
    <!-- impeccable-variants-start 6876d324 -->
    <div data-impeccable-variants="6876d324" data-impeccable-variant-count="3" style="display: contents">
      <!-- Original -->
      <div data-impeccable-variant="original">
        <main class="login-wrapper">
            <section class="login-card login-card--split" aria-labelledby="reset-password-title">
                <div class="login-card__identity-panel login-card__identity-panel--steps">
                    <div class="login-card__identity-brand">
                        <span class="brand-icon" aria-hidden="true">RM</span>
                        <strong>RetailMind</strong>
                    </div>
                    <div class="login-card__recovery-steps" aria-label="Password reset process">
                        <div class="login-card__recovery-step">
                            <span aria-hidden="true">1</span>
                            <p>Enter your staff username or email.</p>
                        </div>
                        <div class="login-card__recovery-step">
                            <span aria-hidden="true">2</span>
                            <p>Use the emailed link within 30 minutes.</p>
                        </div>
                    </div>
                </div>
                <div class="login-card__form-panel">
                    <h1 id="reset-password-title">Reset password</h1>
                    <p class="subtitle">We’ll send a reset link to the email address on your staff account.</p>
                    <?php if ($message): ?>
                        <div
                            class="alert <?= htmlspecialchars($messageClass) ?>"
                            role="<?= $messageClass === 'tag-warning' ? 'alert' : 'status' ?>"
                            aria-live="<?= $messageClass === 'tag-warning' ? 'assertive' : 'polite' ?>"
                            aria-atomic="true"
                        ><?= htmlspecialchars($message) ?></div>
                    <?php endif; ?>
                    <form method="post">
                        <?= csrf_field() ?>
                        <div class="form-group">
                            <label for="identity">Username or email</label>
                            <input id="identity" name="identity" type="text" autocomplete="username" autocapitalize="none" spellcheck="false" required>
                        </div>
                        <button class="btn btn-block" type="submit">Send reset link</button>
                    </form>
                    <p class="u-back-link"><a href="<?= htmlspecialchars(app_url('?login=1')) ?>">Back to login</a></p>
                </div>
            </section>
        </main>
      </div>
      <!-- Variants: insert below this line -->
      <style data-impeccable-css="6876d324">
        @scope ([data-impeccable-variant="1"]) {
          :scope > .recovery-focus-stage {
            --focus-lift: calc(var(--p-response, 1) * -4px);
            --focus-ambient: var(--p-ambient, 0.2);
            position: relative;
            overflow: hidden;
          }
          :scope > .recovery-focus-stage::before {
            content: "";
            position: absolute;
            inset: 0;
            pointer-events: none;
            background: radial-gradient(circle at 62% 50%, rgba(15, 118, 110, var(--focus-ambient)), transparent 29%);
            opacity: 0.3;
            transition: opacity 520ms cubic-bezier(0.16, 1, 0.3, 1), transform 700ms cubic-bezier(0.16, 1, 0.3, 1);
            transform: scale(0.82);
          }
          :scope > .recovery-focus-stage > .login-card {
            position: relative;
            transition: transform 520ms cubic-bezier(0.16, 1, 0.3, 1), box-shadow 520ms cubic-bezier(0.16, 1, 0.3, 1);
          }
          :scope > .recovery-focus-stage:focus-within::before {
            opacity: 1;
            transform: scale(1);
          }
          :scope > .recovery-focus-stage:focus-within > .login-card {
            transform: translateY(var(--focus-lift));
            box-shadow: 0 28px 72px rgba(7, 45, 43, 0.2);
          }
          :scope > .recovery-focus-stage:focus-within .login-card__recovery-step:first-child span {
            box-shadow: 0 0 0 4px rgba(242, 201, 76, 0.16);
          }
          @media (prefers-reduced-motion: reduce) {
            :scope > .recovery-focus-stage::before,
            :scope > .recovery-focus-stage > .login-card {
              transition: none;
            }
            :scope > .recovery-focus-stage:focus-within > .login-card {
              transform: none;
            }
          }
        }

        @scope ([data-impeccable-variant="2"]) {
          :scope > .recovery-relay-stage .login-card__recovery-step span,
          :scope > .recovery-relay-stage .btn {
            transition: background-color 360ms cubic-bezier(0.16, 1, 0.3, 1), color 360ms cubic-bezier(0.16, 1, 0.3, 1), box-shadow 360ms cubic-bezier(0.16, 1, 0.3, 1), transform 360ms cubic-bezier(0.16, 1, 0.3, 1);
          }
          :scope > .recovery-relay-stage .login-card__recovery-step:first-child span {
            box-shadow: 0 0 0 calc(var(--p-signal, 1) * 4px) rgba(242, 201, 76, 0.14);
          }
          :scope > .recovery-relay-stage:has(input:valid:not(:placeholder-shown)) .login-card__recovery-step:first-child span {
            border-color: var(--auth-panel-muted);
            background: transparent;
            color: var(--auth-panel-muted);
            box-shadow: none;
          }
          :scope > .recovery-relay-stage:has(input:valid:not(:placeholder-shown)) .login-card__recovery-step:nth-child(2) span {
            border-color: var(--auth-signal);
            background: var(--auth-signal);
            color: var(--auth-ink);
            box-shadow: 0 0 0 calc(var(--p-signal, 1) * 4px) rgba(242, 201, 76, 0.14);
          }
          :scope > .recovery-relay-stage:has(input:valid:not(:placeholder-shown)) .btn {
            background: var(--auth-accent-deep);
            transform: translateY(-1px);
            box-shadow: 0 10px 24px rgba(15, 118, 110, 0.2);
          }
          :scope[data-p-guidance="quiet"] > .recovery-relay-stage .login-card__recovery-step span,
          :scope[data-p-guidance="quiet"] > .recovery-relay-stage:has(input:valid:not(:placeholder-shown)) .login-card__recovery-step:nth-child(2) span {
            box-shadow: none;
          }
          @media (prefers-reduced-motion: reduce) {
            :scope > .recovery-relay-stage .login-card__recovery-step span,
            :scope > .recovery-relay-stage .btn {
              transition: none;
            }
          }
        }

        @scope ([data-impeccable-variant="3"]) {
          :scope > .recovery-aperture-stage > .login-card {
            grid-template-columns: calc(var(--p-panel-width, 0.34) * 100%) minmax(0, 1fr);
            transition: grid-template-columns 620ms cubic-bezier(0.16, 1, 0.3, 1), box-shadow 620ms cubic-bezier(0.16, 1, 0.3, 1);
          }
          :scope > .recovery-aperture-stage .login-card__identity-panel,
          :scope > .recovery-aperture-stage .login-card__form-panel {
            transition: padding 620ms cubic-bezier(0.16, 1, 0.3, 1);
          }
          :scope > .recovery-aperture-stage:focus-within > .login-card {
            grid-template-columns: calc((var(--p-panel-width, 0.34) - 0.06) * 100%) minmax(0, 1fr);
            box-shadow: 0 30px 78px rgba(7, 45, 43, 0.2);
          }
          :scope > .recovery-aperture-stage:focus-within .login-card__form-panel {
            padding-inline: calc(2.5rem + (var(--p-breathing-room, 1) * 0.5rem));
          }
          :scope > .recovery-aperture-stage .form-group {
            position: relative;
          }
          :scope > .recovery-aperture-stage .form-group::after {
            content: "";
            position: absolute;
            right: 0;
            bottom: 0;
            left: 0;
            height: 2px;
            border-radius: 999px;
            background: var(--auth-accent);
            transform: scaleX(0);
            transform-origin: left;
            transition: transform 440ms cubic-bezier(0.16, 1, 0.3, 1);
          }
          :scope > .recovery-aperture-stage .form-group:focus-within::after {
            transform: scaleX(1);
          }
          @media (max-width: 680px) {
            :scope > .recovery-aperture-stage > .login-card,
            :scope > .recovery-aperture-stage:focus-within > .login-card {
              grid-template-columns: 1fr;
            }
            :scope > .recovery-aperture-stage:focus-within .login-card__form-panel {
              padding-inline: 1.5rem;
            }
          }
          @media (prefers-reduced-motion: reduce) {
            :scope > .recovery-aperture-stage > .login-card,
            :scope > .recovery-aperture-stage .login-card__identity-panel,
            :scope > .recovery-aperture-stage .login-card__form-panel,
            :scope > .recovery-aperture-stage .form-group::after {
              transition: none;
            }
          }
        }
      </style>

      <div data-impeccable-variant="1" data-impeccable-params='[{"id":"response","kind":"range","min":0,"max":1.5,"step":0.25,"default":1,"label":"Focus lift"},{"id":"ambient","kind":"range","min":0.08,"max":0.3,"step":0.02,"default":0.2,"label":"Ambient focus"}]' style="display: none">
        <main class="login-wrapper recovery-focus-stage">
          <section class="login-card login-card--split" aria-labelledby="reset-password-title-v1">
            <div class="login-card__identity-panel login-card__identity-panel--steps"><div class="login-card__identity-brand"><span class="brand-icon" aria-hidden="true">RM</span><strong>RetailMind</strong></div><div class="login-card__recovery-steps" aria-label="Password reset process"><div class="login-card__recovery-step"><span aria-hidden="true">1</span><p>Enter your staff username or email.</p></div><div class="login-card__recovery-step"><span aria-hidden="true">2</span><p>Use the emailed link within 30 minutes.</p></div></div></div>
            <div class="login-card__form-panel"><h1 id="reset-password-title-v1">Reset password</h1><p class="subtitle">We’ll send a reset link to the email address on your staff account.</p><?php if ($message): ?><div class="alert <?= htmlspecialchars($messageClass) ?>" role="<?= $messageClass === 'tag-warning' ? 'alert' : 'status' ?>" aria-live="<?= $messageClass === 'tag-warning' ? 'assertive' : 'polite' ?>" aria-atomic="true"><?= htmlspecialchars($message) ?></div><?php endif; ?><form method="post"><?= csrf_field() ?><div class="form-group"><label for="identity-v1">Username or email</label><input id="identity-v1" name="identity" type="text" autocomplete="username" autocapitalize="none" spellcheck="false" required></div><button class="btn btn-block" type="submit">Send reset link</button></form><p class="u-back-link"><a href="<?= htmlspecialchars(app_url('?login=1')) ?>">Back to login</a></p></div>
          </section>
        </main>
      </div>

      <div data-impeccable-variant="2" data-impeccable-params='[{"id":"signal","kind":"range","min":0,"max":1.5,"step":0.25,"default":1,"label":"State signal"},{"id":"guidance","kind":"steps","options":["quiet","expressive"],"default":"expressive","label":"Guidance"}]' style="display: none">
        <main class="login-wrapper recovery-relay-stage">
          <section class="login-card login-card--split" aria-labelledby="reset-password-title-v2">
            <div class="login-card__identity-panel login-card__identity-panel--steps"><div class="login-card__identity-brand"><span class="brand-icon" aria-hidden="true">RM</span><strong>RetailMind</strong></div><div class="login-card__recovery-steps" aria-label="Password reset process"><div class="login-card__recovery-step"><span aria-hidden="true">1</span><p>Enter your staff username or email.</p></div><div class="login-card__recovery-step"><span aria-hidden="true">2</span><p>Use the emailed link within 30 minutes.</p></div></div></div>
            <div class="login-card__form-panel"><h1 id="reset-password-title-v2">Reset password</h1><p class="subtitle">We’ll send a reset link to the email address on your staff account.</p><?php if ($message): ?><div class="alert <?= htmlspecialchars($messageClass) ?>" role="<?= $messageClass === 'tag-warning' ? 'alert' : 'status' ?>" aria-live="<?= $messageClass === 'tag-warning' ? 'assertive' : 'polite' ?>" aria-atomic="true"><?= htmlspecialchars($message) ?></div><?php endif; ?><form method="post"><?= csrf_field() ?><div class="form-group"><label for="identity-v2">Username or email</label><input id="identity-v2" name="identity" type="text" placeholder=" " autocomplete="username" autocapitalize="none" spellcheck="false" required></div><button class="btn btn-block" type="submit">Send reset link</button></form><p class="u-back-link"><a href="<?= htmlspecialchars(app_url('?login=1')) ?>">Back to login</a></p></div>
          </section>
        </main>
      </div>

      <div data-impeccable-variant="3" data-impeccable-params='[{"id":"panel-width","kind":"range","min":0.3,"max":0.42,"step":0.02,"default":0.34,"label":"Panel width"},{"id":"breathing-room","kind":"range","min":0,"max":1.5,"step":0.25,"default":1,"label":"Focus space"}]' style="display: none">
        <main class="login-wrapper recovery-aperture-stage">
          <section class="login-card login-card--split" aria-labelledby="reset-password-title-v3">
            <div class="login-card__identity-panel login-card__identity-panel--steps"><div class="login-card__identity-brand"><span class="brand-icon" aria-hidden="true">RM</span><strong>RetailMind</strong></div><div class="login-card__recovery-steps" aria-label="Password reset process"><div class="login-card__recovery-step"><span aria-hidden="true">1</span><p>Enter your staff username or email.</p></div><div class="login-card__recovery-step"><span aria-hidden="true">2</span><p>Use the emailed link within 30 minutes.</p></div></div></div>
            <div class="login-card__form-panel"><h1 id="reset-password-title-v3">Reset password</h1><p class="subtitle">We’ll send a reset link to the email address on your staff account.</p><?php if ($message): ?><div class="alert <?= htmlspecialchars($messageClass) ?>" role="<?= $messageClass === 'tag-warning' ? 'alert' : 'status' ?>" aria-live="<?= $messageClass === 'tag-warning' ? 'assertive' : 'polite' ?>" aria-atomic="true"><?= htmlspecialchars($message) ?></div><?php endif; ?><form method="post"><?= csrf_field() ?><div class="form-group"><label for="identity-v3">Username or email</label><input id="identity-v3" name="identity" type="text" autocomplete="username" autocapitalize="none" spellcheck="false" required></div><button class="btn btn-block" type="submit">Send reset link</button></form><p class="u-back-link"><a href="<?= htmlspecialchars(app_url('?login=1')) ?>">Back to login</a></p></div>
          </section>
        </main>
      </div>
    </div>
    <!-- impeccable-variants-end 6876d324 -->
<!-- impeccable-live-start -->
<script src="http://localhost:8400/live.js?token=9d673aff-16bf-4b27-a8df-dbe423e3e255"></script>
<!-- impeccable-live-end -->
</body>

</html>
