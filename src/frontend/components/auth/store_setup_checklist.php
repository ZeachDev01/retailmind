<?php
// Shared Store setup checklist
$confirmations = \App\Store\StoreSetupConfirmations::all($pdo);
$tasks = \App\Store\StoreSetupConfirmations::TASKS;
$totalTasks = count($tasks);
$confirmedCount = count($confirmations);
$percent = (int)round(($confirmedCount / $totalTasks) * 100);
$allDone = $confirmedCount >= $totalTasks;
$currentRole = current_role();
?>
<div class="store-setup-checklist" aria-labelledby="store-setup-checklist-title">
    <div class="store-setup-checklist-header">
        <h3 id="store-setup-checklist-title">Store setup checklist</h3>
        <span class="setup-progress-badge <?= $allDone ? 'tag-success' : 'tag-neutral' ?>">
            <?= $confirmedCount ?> of <?= $totalTasks ?> tasks confirmed<?= $allDone ? ' &middot; Setup complete' : '' ?>
        </span>
    </div>
    <div class="setup-progress-track" role="progressbar" aria-valuenow="<?= $confirmedCount ?>" aria-valuemin="0" aria-valuemax="<?= $totalTasks ?>" aria-label="Store setup progress">
        <div class="setup-progress-bar" style="width: <?= $percent ?>%;"></div>
    </div>
    <ul class="setup-task-list">
        <?php foreach ($tasks as $key => $task):
            $isConfirmed = isset($confirmations[$key]);
            $canConfirm = !$isConfirmed && ($currentRole === $task['role']);
            $isPermittedLink = match ($key) {
                'products' => in_array($currentRole, ['admin', 'inventory_manager'], true),
                'store_calendar' => $currentRole === 'admin',
                default => false,
            };
        ?>
        <li class="setup-task-item <?= $isConfirmed ? 'is-confirmed' : 'is-pending' ?>">
            <div class="setup-task-body">
                <div class="setup-task-heading-row">
                    <strong class="setup-task-title"><?= htmlspecialchars($task['title']) ?></strong>
                    <span class="setup-task-owner"><?= htmlspecialchars($task['role_label']) ?></span>
                </div>
                <p class="setup-task-desc"><?= htmlspecialchars($task['description']) ?></p>
                <div class="setup-task-links">
                    <?php if ($isPermittedLink): ?>
                        <a href="<?= htmlspecialchars(app_url('components/' . $task['link'])) ?>" class="setup-task-link"><?= htmlspecialchars($task['link_label']) ?></a>
                    <?php else: ?>
                        <span class="setup-task-restricted-note"><?= htmlspecialchars($task['title']) ?> (<?= htmlspecialchars($task['role_label']) ?> review)</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="setup-task-action">
                <?php if ($isConfirmed): ?>
                    <span class="setup-task-status tag-success">
                        <i class="bi bi-check-circle" aria-hidden="true"></i> Confirmed
                    </span>
                <?php elseif ($canConfirm): ?>
                    <form method="post" action="<?= htmlspecialchars(app_url('components/auth/help.php')) ?>" class="setup-task-form setup-task-confirm-form" data-rm-backup-bypass="true">
                        <?= csrf_field() ?>
                        <input type="hidden" name="return_to" value="<?= htmlspecialchars(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '') ?>">
                        <input type="hidden" name="confirm_task" value="<?= htmlspecialchars($key) ?>">
                        <button type="submit" class="btn btn-small btn-primary"><?= htmlspecialchars($task['action_label']) ?></button>
                    </form>
                <?php else: ?>
                    <span class="setup-task-status tag-neutral">Awaiting <?= htmlspecialchars($task['role_label']) ?></span>
                <?php endif; ?>
            </div>
        </li>
        <?php endforeach; ?>
    </ul>
</div>
