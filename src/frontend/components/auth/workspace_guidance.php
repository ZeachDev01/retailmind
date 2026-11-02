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
                <strong>Store setup complete.</strong> Essential product, opening-stock, and Store calendar setup tasks are confirmed. Automatic onboarding reminders are now turned off. You can revisit guidance anytime from Help in your account menu.
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

        if (!$allTasksComplete) {
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

        $guidanceParam = isset($_GET['guidance']) ? trim((string)$_GET['guidance']) : '';
        if ($guidanceParam !== '') {
            $topic = \App\Guidance\WorkspaceWalkthroughService::findTopic(current_role(), $guidanceParam);
            $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
            if ($topic && !empty($topic['steps']) && str_ends_with($scriptName, $topic['path'])) {
                $walkthroughProgress = \App\Guidance\WorkspaceWalkthroughService::getProgress($pdo, (int)$_SESSION['user_id'], current_role());
                $isReplay = !empty($_GET['replay']);
                $initialStep = $isReplay ? 0 : ($walkthroughProgress['active_topic'] === $topic['id'] ? ($walkthroughProgress['active_step'] ?? 0) : 0);
                include __DIR__ . '/workspace_walkthrough_dock.php';
            }
        }
    } catch (Throwable $e) {
        // Guidance must not block Store work when its state cannot be read.
        if (class_exists('App\\Support\\OperatorAlert')) {
            \App\Support\OperatorAlert::message($e, 'Workspace guidance is unavailable. You can continue working.');
        }
    }
}
