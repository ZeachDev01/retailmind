<?php
// components/administrator/registers.php — Register administration (ticket #87).
//
// The Administrator creates and maintains the Store's physical Registers. A
// rename changes only the display name, so the stable identity held by earlier
// operational records never moves. Disabling withdraws a Register from new
// Cashier Shifts while keeping it visible in history, and a Register that any
// operational record still points at cannot be deleted.
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_once __DIR__ . '/../../../backend/includes/csrf.php';

use App\Authorization\RoleCapabilityPolicy;
use App\Services\RegisterService;
use App\Support\OperatorAlert;

require_capability(RoleCapabilityPolicy::MANAGE_REGISTERS);

$registers = new RegisterService($pdo, role_capability_policy());
$actorId = (int)$_SESSION['user_id'];
$actorRole = (string)current_role();
$message = '';
$messageClass = '';
$failedAction = '';
$failedRegisterId = 0;
$submittedName = '';
$submittedPaperWidth = '80';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    $registerId = (int)($_POST['register_id'] ?? 0);

    try {
        if ($action === 'create') {
            $registers->create($actorId, $actorRole, (string)($_POST['name'] ?? ''), (string)($_POST['paper_width_mm'] ?? '80'));
            $message = 'Register created. It is now available for new Cashier Shifts.';
            $messageClass = 'tag-success';
        } elseif ($action === 'rename') {
            $registers->rename($actorId, $actorRole, $registerId, (string)($_POST['name'] ?? ''));
            $message = 'Register renamed. Earlier records keep their original Register identity.';
            $messageClass = 'tag-success';
        } elseif ($action === 'set_paper_width') {
            $registers->setPaperWidth($actorId, $actorRole, $registerId, (string)($_POST['paper_width_mm'] ?? ''));
            $message = 'Register paper width saved. Receipts use this width when printed from its Cashier Shift.';
            $messageClass = 'tag-success';
        } elseif ($action === 'set_status') {
            $status = (string)($_POST['status'] ?? RegisterService::STATUS_ACTIVE);
            $registers->setStatus($actorId, $actorRole, $registerId, $status);
            $message = $status === RegisterService::STATUS_DISABLED
                ? 'Register disabled. It blocks new Shifts without shutting down or closing existing open Shifts, and earlier history remains available.'
                : 'Register enabled. It can be selected for new Cashier Shifts again.';
            $messageClass = 'tag-success';
        } elseif ($action === 'delete') {
            $registers->delete($actorId, $actorRole, $registerId);
            $message = 'Register deleted. It was not used by any operational record.';
            $messageClass = 'tag-success';
        }
    } catch (Throwable $exception) {
        $failedAction = $action;
        $failedRegisterId = $registerId;
        $submittedName = (string)($_POST['name'] ?? '');
        $submittedPaperWidth = (string)($_POST['paper_width_mm'] ?? '80');
        $message = OperatorAlert::message(
            $exception,
            'The Register could not be saved. Check the name and try again. Tell your Administrator if this keeps happening.'
        );
        $messageClass = 'tag-warning';
    }
}

$allRegisters = $registers->all();
?>
<!DOCTYPE html>
<html lang="en">
<head><?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Registers</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(theme_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(theme_url('assets/css/registers.css')) ?>">
</head>
<body class="registers-page">
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content">
        <header class="page-heading">
            <div>
                <h1>Registers</h1>
            </div>
        </header>

        <?php if ($message): ?><div id="register-feedback" class="alert <?= htmlspecialchars($messageClass) ?>" role="status"><?= htmlspecialchars($message) ?></div><?php endif; ?>

        <section class="dashboard-section" aria-labelledby="add-register-heading">
            <h2 id="add-register-heading">Add a Register</h2>
            <form method="post" class="form-grid">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create">
                <div class="form-group">
                    <label for="register-name">Register name</label>
                    <input id="register-name" type="text" name="name" maxlength="100" placeholder="Front Counter" value="<?= htmlspecialchars($failedAction === 'create' ? $submittedName : '') ?>" <?= $failedAction === 'create' ? 'aria-describedby="register-feedback"' : '' ?> required>
                </div>
                <div class="form-group">
                    <label for="register-paper-width">Receipt paper width</label>
                    <select id="register-paper-width" name="paper_width_mm" required>
                        <option value="80" <?= $failedAction !== 'create' || $submittedPaperWidth !== '58' ? 'selected' : '' ?>>80 mm</option>
                        <option value="58" <?= $failedAction === 'create' && $submittedPaperWidth === '58' ? 'selected' : '' ?>>58 mm</option>
                    </select>
                </div>
                <div class="form-group">
                    <button class="btn" type="submit">Create Register</button>
                </div>
            </form>
        </section>

        <section class="dashboard-section" aria-labelledby="store-registers-heading">
            <h2 id="store-registers-heading">Store Registers <span class="register-count">(<?= count($allRegisters) ?>)</span></h2>
            <p class="section-description">Disabling a Register blocks new Shifts on it while preserving existing open Shifts until normal closure.</p>

            <?php if ($allRegisters === []): ?>
                <p class="section-description">No Registers yet. Create one above so Cashier Shifts have a till to anchor to.</p>
            <?php else: ?>
                <div class="data-table-shell">
                    <div class="data-table-scroll" tabindex="0" role="region" aria-labelledby="store-registers-heading">
                        <table class="data-table">
                    <thead>
                        <tr>
                            <th scope="col">Register</th>
                            <th scope="col">Availability</th>
                            <th scope="col">Receipt paper width</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($allRegisters as $register): ?>
                        <?php
                        $isActive = $register['status'] === RegisterService::STATUS_ACTIVE;
                        $renameFailed = $failedAction === 'rename' && $failedRegisterId === $register['register_id'];
                        ?>
                        <tr>
                            <td class="register-name-cell">
                                <div class="register-identity">
                                    <strong><?= htmlspecialchars($register['name']) ?></strong>
                                    <button class="register-edit-toggle" type="button" aria-label="Edit <?= htmlspecialchars($register['name']) ?>" aria-expanded="<?= $renameFailed ? 'true' : 'false' ?>" aria-controls="rename-form-<?= (int)$register['register_id'] ?>">
                                        <i class="bi bi-pencil" aria-hidden="true"></i>
                                    </button>
                                    <span class="register-id">Register ID #<?= (int)$register['register_id'] ?></span>
                                </div>
                                <form method="post" class="inline-form register-rename-form" id="rename-form-<?= (int)$register['register_id'] ?>" <?= $renameFailed ? '' : 'hidden' ?>>
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="rename">
                                    <input type="hidden" name="register_id" value="<?= (int)$register['register_id'] ?>">
                                    <input id="rename-<?= (int)$register['register_id'] ?>" type="text" name="name" maxlength="100" value="<?= htmlspecialchars($renameFailed ? $submittedName : $register['name']) ?>" aria-label="Rename <?= htmlspecialchars($register['name']) ?>" <?= $renameFailed ? 'aria-describedby="register-feedback"' : '' ?> required>
                                    <button class="btn btn-secondary" type="submit" aria-label="Rename <?= htmlspecialchars($register['name']) ?>">Rename</button>
                                </form>
                            </td>
                            <td>
                                <?php if ($isActive): ?>
                                    <span class="tag-success">Available for new shifts</span>
                                <?php else: ?>
                                    <span class="tag-warning">Disabled<?= $register['disabled_at'] !== null ? ' since ' . htmlspecialchars(format_display_date($register['disabled_at'])) : '' ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form method="post" class="inline-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="set_paper_width">
                                    <input type="hidden" name="register_id" value="<?= (int)$register['register_id'] ?>">
                                    <select name="paper_width_mm" aria-label="Receipt paper width for <?= htmlspecialchars($register['name']) ?>" required>
                                        <option value="80" <?= $register['paper_width_mm'] === 80 ? 'selected' : '' ?>>80 mm</option>
                                        <option value="58" <?= $register['paper_width_mm'] === 58 ? 'selected' : '' ?>>58 mm</option>
                                    </select>
                                    <button class="btn btn-secondary" type="submit" aria-label="Save width for <?= htmlspecialchars($register['name']) ?>">Save width</button>
                                </form>
                            </td>
                            <td>
                                <div class="register-actions">
                                <form method="post" class="inline-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="set_status">
                                    <input type="hidden" name="register_id" value="<?= (int)$register['register_id'] ?>">
                                    <input type="hidden" name="status" value="<?= $isActive ? RegisterService::STATUS_DISABLED : RegisterService::STATUS_ACTIVE ?>">
                                    <button class="btn btn-secondary" type="submit" aria-label="<?= $isActive ? 'Disable' : 'Enable' ?> <?= htmlspecialchars($register['name']) ?>"><?= $isActive ? 'Disable' : 'Enable' ?></button>
                                </form>
                                <form method="post" class="inline-form" data-confirm="<?= htmlspecialchars('Permanently delete Register “' . $register['name'] . '”? This cannot be undone.') ?>" data-confirm-title="Delete Register" data-confirm-button="Delete Register" data-confirm-danger="1">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="register_id" value="<?= (int)$register['register_id'] ?>">
                                    <button class="btn btn-danger" type="submit" aria-label="Delete <?= htmlspecialchars($register['name']) ?>">Delete</button>
                                </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </section>
    </main>
</div>
<script>
document.querySelectorAll('.register-edit-toggle').forEach(button => {
    button.addEventListener('click', () => {
        const form = document.getElementById(button.getAttribute('aria-controls'));
        const open = form.hidden;
        form.hidden = !open;
        button.setAttribute('aria-expanded', String(open));
        if (open) form.querySelector('input[name="name"]').focus();
    });
});
</script>
</body>
</html>
