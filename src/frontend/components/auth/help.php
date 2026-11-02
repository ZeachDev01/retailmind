<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_role(['admin', 'inventory_manager']);
$error = '';
$sanitizeRedirect = static function (mixed $target): string {
    $prefix = preg_quote(app_url('components/'), '~');
    return (is_string($target) && preg_match('~^' . $prefix . '[a-z_]+/[a-z_]+\.php$~D', $target))
        ? $target
        : app_url('components/auth/help.php');
};
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (isset($_POST['confirm_task'])) {
        $task = (string)$_POST['confirm_task'];
        try {
            \App\Store\StoreSetupConfirmations::confirm($pdo, $task, (int)$_SESSION['user_id'], current_role());
            if (\App\Store\StoreSetupConfirmations::isComplete($pdo)) {
                $_SESSION['store_setup_just_completed'] = true;
            }
            header('Location: ' . $sanitizeRedirect($_POST['return_to'] ?? null), true, 303);
            exit;
        } catch (DomainException $e) {
            http_response_code(403);
            $error = $e->getMessage();
        } catch (InvalidArgumentException $e) {
            http_response_code(422);
            $error = $e->getMessage();
        } catch (Throwable $e) {
            $error = \App\Support\OperatorAlert::message($e, 'Unable to save your confirmation. Please try again.');
        }
    } elseif (isset($_POST['invitation_action']) || isset($_POST['invitation_choice'])) {
        $isJson = ($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json';
        $userId = (int)$_SESSION['user_id'];
        $workspace = current_role();
        try {
            if (isset($_POST['user_id']) && (int)$_POST['user_id'] !== $userId) {
                throw new DomainException('Forged user identity rejected.');
            }
            if (isset($_POST['workspace']) && (string)$_POST['workspace'] !== $workspace) {
                throw new DomainException('Forged workspace rejected.');
            }
            $choice = (string)($_POST['invitation_choice'] ?? '');
            if (!in_array($choice, ['start', 'dismiss', 'not_now'], true)) {
                throw new InvalidArgumentException('Invalid invitation choice.');
            }
            if ($choice === 'start') {
                \App\Guidance\WorkspaceWalkthroughService::recordInvitationChoice($pdo, $userId, $workspace, 'started');
                \App\Guidance\WorkspaceWalkthroughService::updateStep($pdo, $userId, $workspace, 'store_settings', 0);
                if ($isJson) {
                    header('Content-Type: application/json');
                    echo json_encode(['saved' => true, 'choice' => 'started', 'redirect' => app_url('components/administrator/store_settings.php?guidance=store_settings')]);
                    exit;
                }
                header('Location: ' . app_url('components/administrator/store_settings.php?guidance=store_settings'), true, 303);
                exit;
            } else {
                \App\Guidance\WorkspaceWalkthroughService::recordInvitationChoice($pdo, $userId, $workspace, 'dismissed');
                if ($isJson) {
                    header('Content-Type: application/json');
                    echo json_encode(['saved' => true, 'choice' => 'dismissed']);
                    exit;
                }
                $redirectTarget = !empty($_POST['return_to']) ? $sanitizeRedirect($_POST['return_to']) : app_url(\App\Authorization\RoleWorkspaceRouter::pathFor($workspace));
                header('Location: ' . $redirectTarget, true, 303);
                exit;
            }
        } catch (DomainException $e) {
            http_response_code(403);
            if ($isJson) {
                header('Content-Type: application/json');
                echo json_encode(['saved' => false, 'error' => $e->getMessage()]);
                exit;
            }
            $error = $e->getMessage();
        } catch (InvalidArgumentException $e) {
            http_response_code(422);
            if ($isJson) {
                header('Content-Type: application/json');
                echo json_encode(['saved' => false, 'error' => $e->getMessage()]);
                exit;
            }
            $error = $e->getMessage();
        } catch (\Throwable $e) {
            http_response_code(500);
            $error = \App\Support\OperatorAlert::message($e, 'Unable to save your invitation choice.');
            if ($isJson) {
                header('Content-Type: application/json');
                echo json_encode(['saved' => false, 'error' => $error]);
                exit;
            }
        }
    } elseif (isset($_POST['walkthrough_action']) || isset($_POST['walkthrough_topic'])) {
        $isJson = ($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json';
        try {
            $validated = \App\Guidance\WorkspaceWalkthroughService::validateMutation((int)$_SESSION['user_id'], current_role(), $_POST);
            $userId = (int)$_SESSION['user_id'];
            $workspace = current_role();
            match ($validated['action']) {
                'start' => \App\Guidance\WorkspaceWalkthroughService::updateStep($pdo, $userId, $workspace, $validated['topic_id'], 0),
                'step' => \App\Guidance\WorkspaceWalkthroughService::updateStep($pdo, $userId, $workspace, $validated['topic_id'], $validated['step']),
                'complete' => \App\Guidance\WorkspaceWalkthroughService::completeTopic($pdo, $userId, $workspace, $validated['topic_id']),
                'exit' => \App\Guidance\WorkspaceWalkthroughService::exitWalkthrough($pdo, $userId, $workspace, $validated['topic_id'], $validated['step']),
                'replay' => \App\Guidance\WorkspaceWalkthroughService::replayTopic($pdo, $userId, $workspace, $validated['topic_id']),
            };
            if ($isJson) {
                header('Content-Type: application/json');
                echo json_encode(['saved' => true, 'action' => $validated['action'], 'step' => $validated['step']]);
                exit;
            }
            $redirectTarget = !empty($_POST['return_to']) ? $sanitizeRedirect($_POST['return_to']) : app_url($validated['topic']['path']);
            header('Location: ' . $redirectTarget, true, 303);
            exit;
        } catch (DomainException $e) {
            http_response_code(403);
            if ($isJson) {
                header('Content-Type: application/json');
                echo json_encode(['saved' => false, 'error' => $e->getMessage()]);
                exit;
            }
            $error = $e->getMessage();
        } catch (InvalidArgumentException $e) {
            http_response_code(422);
            if ($isJson) {
                header('Content-Type: application/json');
                echo json_encode(['saved' => false, 'error' => $e->getMessage()]);
                exit;
            }
            $error = $e->getMessage();
        } catch (\Throwable $e) {
            http_response_code(500);
            $error = \App\Support\OperatorAlert::message($e, 'Unable to save your walkthrough progress.');
            if ($isJson) {
                header('Content-Type: application/json');
                echo json_encode(['saved' => false, 'error' => $error]);
                exit;
            }
        }
    } else {
        $status = $_POST['guidance_status'] ?? '';
        if (!is_string($status) || !in_array($status, ['skipped', 'deferred', 'completed'], true)) {
            http_response_code(422);
            $error = 'Choose Finish overview, Skip guidance or Not now.';
        } else {
            try {
                // Reopening Help cannot reset a completed overview.
                $query = $pdo->prepare('INSERT INTO workspace_guidance (user_id, workspace, status) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE status = IF(status = \'completed\', status, VALUES(status))');
                $query->execute([(int)$_SESSION['user_id'], current_role(), $status]);
                if (($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json') {
                    header('Content-Type: application/json');
                    echo json_encode(['saved' => true]);
                    exit;
                }
                header('Location: ' . $sanitizeRedirect($_POST['return_to'] ?? null), true, 303);
                exit;
            } catch (Throwable $e) {
                $error = \App\Support\OperatorAlert::message($e, 'Unable to save your guidance choice. Please try again.');
                if (($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json') {
                    http_response_code(500);
                    header('Content-Type: application/json');
                    echo json_encode(['saved' => false]);
                    exit;
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head><?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Workspace Help</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(theme_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/workspace-help.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/workspace-help.css')) ?>">
</head>
<body class="workspace-help-page">
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content">
        <header class="page-heading help-heading">
            <div>
                <h1>Workspace Help</h1>
            </div>
            <a class="btn btn-secondary" href="<?= htmlspecialchars(app_url(current_role() === 'admin' ? 'components/administrator/dashboard.php' : 'components/inventory_management/inventory_overview.php')) ?>">Back to workspace</a>
        </header>
        <?php if ($error !== ''): ?><div class="alert tag-warning" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?php if (!empty($_SESSION['store_setup_just_completed'])): unset($_SESSION['store_setup_just_completed']); ?>
            <div id="store-setup-completion" class="alert tag-success store-setup-completion" role="status" data-rm-alert-processed="1">
                <strong>Store setup complete.</strong> Essential product, opening-stock, and Store calendar setup tasks are confirmed. Automatic onboarding reminders are now turned off without affecting personal orientation invitations for new Administrators. You can revisit guidance anytime from Help in your account menu.
            </div>
        <?php endif; ?>
        <div class="help-layout">
            <section class="workspace-guidance help-workflows" aria-labelledby="workspace-guidance-title">
                <?php $guidanceIsHelp = true; include __DIR__ . '/workspace_overview.php'; ?>
            </section>
            <aside class="help-setup" aria-labelledby="store-setup-checklist-title">
                <?php include __DIR__ . '/store_setup_checklist.php'; ?>
                <p class="help-setup-note">Complete the work before confirming setup.</p>
            </aside>
        </div>
        <section class="help-preferences" aria-labelledby="help-preferences-title">
            <div>
                <h2 id="help-preferences-title">All set?</h2>
                <p>Reopen Help anytime from your account menu.</p>
            </div>
            <?php include __DIR__ . '/workspace_guidance_actions.php'; ?>
        </section>
    </main>
</div>
</body>
</html>
