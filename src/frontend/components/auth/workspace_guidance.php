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
                <span class="wi-orb wi-orb-a" aria-hidden="true"></span>
                <span class="wi-orb wi-orb-b" aria-hidden="true"></span>
                <div class="workspace-invitation-content">
                    <div class="workspace-invitation-icon" aria-hidden="true">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/><path d="M19 16l.7 1.8 1.8.7-1.8.7L19 21l-.7-1.8-1.8-.7 1.8-.7z"/></svg>
                    </div>
                    <div class="workspace-invitation-text">
                        <h2 id="workspace-invitation-title" class="workspace-invitation-heading">Welcome to the Administrator Workspace</h2>
                        <ul class="wi-steps" aria-label="Setup areas">
                            <li style="--i:0"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 9l1-5h16l1 5M4 9v11h16V9M9 20v-6h6v6"/></svg>Store</li>
                            <li style="--i:1"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 7h8M8 12h2M12 12h2M8 16h2M12 16h2"/></svg>Registers</li>
                            <li style="--i:2"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>Fiscal</li>
                            <li style="--i:3"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.6-3.6 3.2-5.5 6.5-5.5s5.9 1.9 6.5 5.5M16 4.8a3.5 3.5 0 010 6.4M18 14.8c2 .7 3.2 2.4 3.5 5"/></svg>Staff</li>
                            <li style="--i:4"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg>Calendar</li>
                        </ul>
                    </div>
                </div>
                <form method="post" action="<?= htmlspecialchars(app_url('components/auth/help.php')) ?>" class="workspace-invitation-actions" data-rm-backup-bypass="true">
                    <?= csrf_field() ?>
                    <input type="hidden" name="invitation_action" value="choice">
                    <input type="hidden" name="workspace" value="admin">
                    <input type="hidden" name="user_id" value="<?= (int)$_SESSION['user_id'] ?>">
                    <input type="hidden" name="return_to" value="<?= htmlspecialchars(app_url(ltrim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '', '/'))) ?>">
                    <button type="submit" name="invitation_choice" value="start" class="btn btn-primary" id="btn-invitation-start">Start guidance <span class="wi-arrow" aria-hidden="true">→</span></button>
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
        } elseif (!$allTasksComplete && empty($guidanceProgress['invitation_status']) && empty($guidanceProgress['status'])) {
            ob_start();
            $guidanceIsHelp = false;
            ?>
            <section id="workspace-guidance" class="workspace-guidance <?= current_role() === 'inventory_manager' ? 'workspace-invitation' : 'workspace-guidance-banner' ?>" aria-labelledby="workspace-guidance-title">
                <span class="wi-orb wi-orb-a" aria-hidden="true"></span>
                <span class="wi-orb wi-orb-b" aria-hidden="true"></span>
                <?php if (current_role() === 'inventory_manager'): ?>
                <div class="workspace-invitation-content">
                    <div class="workspace-invitation-icon" aria-hidden="true">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/><path d="M19 16l.7 1.8 1.8.7-1.8.7L19 21l-.7-1.8-1.8-.7 1.8-.7z"/></svg>
                    </div>
                    <div class="workspace-invitation-text">
                        <h2 id="workspace-guidance-title" class="workspace-invitation-heading">Welcome to the Inventory Manager Workspace</h2>
                    </div>
                </div>
                <?php else: ?>
                    <?php include __DIR__ . '/workspace_overview.php'; ?>
                <?php endif; ?>
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

        $guidanceParam = isset($_GET['guidance']) ? trim((string)$_GET['guidance']) : '';
        if ($guidanceParam !== '') {
            $role = current_role();
            $topic = ($guidanceParam !== '1') ? \App\Guidance\WorkspaceWalkthroughService::findTopic($role, $guidanceParam) : null;
            $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
            if (!$topic) {
                foreach (\App\Guidance\WorkspaceWalkthroughService::paths($role) as $p) {
                    foreach ($p['topics'] as $t) {
                        if (str_ends_with($scriptName, $t['path']) || basename($scriptName) === basename($t['path'])) {
                            $topic = \App\Guidance\WorkspaceWalkthroughService::findTopic($role, $t['id']);
                            break 2;
                        }
                    }
                }
            }
            if ($topic && !empty($topic['steps']) && (str_ends_with($scriptName, $topic['path']) || basename($scriptName) === basename($topic['path']))) {
                $walkthroughProgress = $guidanceProgress;
                $isReplay = !empty($_GET['replay']);
                $topicCompleted = in_array((string)$topic['id'], (array)($guidanceProgress['completed_topics'] ?? []), true);
                // A completed topic stays closed; Replay from Help is the only way to reopen it.
                if ($isReplay || !$topicCompleted) {
                    $initialStep = $isReplay ? 0 : ($walkthroughProgress['active_topic'] === $topic['id'] ? ($walkthroughProgress['active_step'] ?? 0) : 0);
                    include __DIR__ . '/workspace_walkthrough_dock.php';
                }
            }
        }
    } catch (Throwable $e) {
        // Guidance must not block Store work when its state cannot be read.
        if (class_exists('App\\Support\\OperatorAlert')) {
            \App\Support\OperatorAlert::message($e, 'Workspace guidance is unavailable. You can continue working.');
        }
    }
}
