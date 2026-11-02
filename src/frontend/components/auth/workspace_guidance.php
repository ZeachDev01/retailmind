<?php
if (in_array(current_role(), ['admin', 'inventory_manager'], true)
    && basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'help.php') {
    try {
        $allTasksComplete = \App\Store\StoreSetupConfirmations::isComplete($pdo);
        $justCompleted = !empty($_SESSION['store_setup_just_completed']);

        if ($justCompleted) {
            unset($_SESSION['store_setup_just_completed']);
            ob_start();
            ?>
            <div id="store-setup-completion" class="alert tag-success store-setup-completion" role="status" data-rm-alert-processed="1">
                <strong>Store setup complete.</strong> Essential product, opening-stock, and Store calendar setup tasks are confirmed. Automatic onboarding reminders are now turned off without affecting personal orientation invitations for new Administrators. You can revisit guidance anytime from Help in your account menu.
            </div>
            <?php
            $completionHtml = ob_get_clean();
            ?>
            <script>
            document.addEventListener('DOMContentLoaded', function () {
                const main = document.querySelector('.main-content');
                if (main && !document.getElementById('store-setup-completion')) {
                    main.insertAdjacentHTML('afterbegin', <?= json_encode($completionHtml, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
                }
            });
            </script>
            <?php
        }

        $guidanceProgress = \App\Guidance\WorkspaceWalkthroughService::getProgress($pdo, (int)$_SESSION['user_id'], current_role());
        $isInvitedAdmin = (current_role() === 'admin' && ($guidanceProgress['invitation_status'] ?? null) === 'invited');

        if ($isInvitedAdmin) {
            ob_start();
            ?>
            <section id="workspace-invitation" class="workspace-guidance workspace-invitation" aria-labelledby="workspace-invitation-title">
                <div class="workspace-invitation-content">
                    <div class="workspace-invitation-icon" aria-hidden="true">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10"></circle>
                            <line x1="12" y1="16" x2="12" y2="12"></line>
                            <line x1="12" y1="8" x2="12.01" y2="8"></line>
                        </svg>
                    </div>
                    <div class="workspace-invitation-text">
                        <h2 id="workspace-invitation-title" class="workspace-invitation-heading">Welcome to the Administrator Workspace</h2>
                        <p class="workspace-invitation-desc">
                            Get oriented with your Store operational configuration. Follow guided steps through Store Settings, Registers, Fiscal Periods, staff accounts, and Store calendar.
                        </p>
                        <p class="workspace-invitation-help-note">
                            Guidance is optional and does not confirm Store setup or alter operational records. You can revisit it anytime from <a href="<?= htmlspecialchars(app_url('components/auth/help.php')) ?>">Help</a>.
                        </p>
                    </div>
                </div>
                <form method="post" action="<?= htmlspecialchars(app_url('components/auth/help.php')) ?>" class="workspace-invitation-actions" data-rm-backup-bypass="true">
                    <?= csrf_field() ?>
                    <input type="hidden" name="invitation_action" value="choice">
                    <input type="hidden" name="workspace" value="admin">
                    <input type="hidden" name="user_id" value="<?= (int)$_SESSION['user_id'] ?>">
                    <input type="hidden" name="return_to" value="<?= htmlspecialchars(app_url(ltrim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '', '/'))) ?>">
                    <button type="submit" name="invitation_choice" value="start" class="btn btn-primary" id="btn-invitation-start">Start guidance</button>
                    <button type="submit" name="invitation_choice" value="dismiss" class="btn btn-secondary" id="btn-invitation-dismiss">Not now</button>
                </form>
            </section>
            <?php
            $invitationHtml = ob_get_clean();
            ?>
            <script>
            document.addEventListener('DOMContentLoaded', function () {
                const main = document.querySelector('.main-content');
                if (main && !document.getElementById('workspace-invitation')) {
                    main.insertAdjacentHTML('afterbegin', <?= json_encode($invitationHtml, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
                }
            });
            </script>
            <?php
        } elseif (!$allTasksComplete && empty($guidanceProgress['invitation_status'])) {
            $guidanceQuery = $pdo->prepare('SELECT status FROM workspace_guidance WHERE user_id = ? AND workspace = ?');
            $guidanceQuery->execute([(int)$_SESSION['user_id'], current_role()]);
            if (!$guidanceQuery->fetchColumn()) {
                ob_start();
                $guidanceIsHelp = false;
                ?>
                <section id="workspace-guidance" class="workspace-guidance" aria-labelledby="workspace-guidance-title">
                    <?php include __DIR__ . '/workspace_overview.php'; ?>
                    <?php include __DIR__ . '/store_setup_checklist.php'; ?>
                    <?php include __DIR__ . '/workspace_guidance_actions.php'; ?>
                </section>
                <?php $guidanceHtml = ob_get_clean(); ?>
                <script>
                document.addEventListener('DOMContentLoaded', function () {
                    const main = document.querySelector('.main-content');
                    if (main && !document.getElementById('workspace-guidance')) {
                        main.insertAdjacentHTML('afterbegin', <?= json_encode($guidanceHtml, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
                    }
                });
                </script>
                <?php
            }
        }

        $currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
        if ($currentScript === 'store_settings.php' && current_role() === 'admin' && isset($_GET['guidance'])) {
            $walkthroughProgress = \App\Guidance\WorkspaceWalkthroughService::getProgress($pdo, (int)$_SESSION['user_id'], current_role());
            $topic = \App\Guidance\WorkspaceWalkthroughService::findTopic('admin', 'store_settings');
            $isReplay = !empty($_GET['replay']);
            $initialStep = $isReplay ? 0 : ($walkthroughProgress['active_step'] ?? 0);
            include __DIR__ . '/workspace_walkthrough_dock.php';
        }
    } catch (Throwable $e) {
        // Guidance must not block Store work when its state cannot be read.
        if (class_exists('App\\Support\\OperatorAlert')) {
            \App\Support\OperatorAlert::message($e, 'Workspace guidance is unavailable. You can continue working.');
        }
    }
}
