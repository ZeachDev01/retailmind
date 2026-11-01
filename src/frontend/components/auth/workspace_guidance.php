<?php
if (in_array(current_role(), ['admin', 'inventory_manager'], true)
    && basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'help.php') {
    try {
        $guidanceQuery = $pdo->prepare('SELECT status FROM workspace_guidance WHERE user_id = ? AND workspace = ?');
        $guidanceQuery->execute([(int)$_SESSION['user_id'], current_role()]);
        if (!$guidanceQuery->fetchColumn()) {
            ob_start();
            $guidanceIsHelp = false;
            ?>
            <section id="workspace-guidance" class="workspace-guidance" aria-labelledby="workspace-guidance-title">
                <?php include __DIR__ . '/workspace_overview.php'; ?>
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
    } catch (Throwable $e) {
        // Guidance must not block Store work when its state cannot be read.
        \App\Support\OperatorAlert::message($e, 'Workspace guidance is unavailable. You can continue working.');
    }
}
