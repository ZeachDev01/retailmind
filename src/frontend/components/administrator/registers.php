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

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    $registerId = (int)($_POST['register_id'] ?? 0);

    try {
        if ($action === 'create') {
            $registers->create($actorId, $actorRole, (string)($_POST['name'] ?? ''));
            $message = 'Register created. It is now available for new Cashier Shifts.';
            $messageClass = 'tag-success';
        } elseif ($action === 'rename') {
            $registers->rename($actorId, $actorRole, $registerId, (string)($_POST['name'] ?? ''));
            $message = 'Register renamed. Earlier records keep their original Register identity.';
            $messageClass = 'tag-success';
        } elseif ($action === 'set_status') {
            $status = (string)($_POST['status'] ?? RegisterService::STATUS_ACTIVE);
            $registers->setStatus($actorId, $actorRole, $registerId, $status);
            $message = $status === RegisterService::STATUS_DISABLED
                ? 'Register disabled. It can no longer start a new shift, and its history stays available.'
                : 'Register enabled. It can be selected for new Cashier Shifts again.';
            $messageClass = 'tag-success';
        } elseif ($action === 'delete') {
            $registers->delete($actorId, $actorRole, $registerId);
            $message = 'Register deleted. It was not used by any operational record.';
            $messageClass = 'tag-success';
        }
    } catch (Throwable $exception) {
        $message = OperatorAlert::message(
            $exception,
            'The Register could not be saved. Check the name and try again. Tell your Administrator if this keeps happening.'
        );
        $messageClass = 'tag-warning';
    }
}

$allRegisters = $registers->all();
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Registers</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content">
        <header class="page-heading">
            <div>
                <p class="page-kicker">Administrator workspace</p>
                <h1>Registers</h1>
                <p class="page-subtitle">Create and maintain the Store's physical tills. Renaming a Register keeps the identity that earlier records already reference, and disabling one keeps its history intact.</p>
            </div>
        </header>

        <?php if ($message): ?><div class="alert <?= htmlspecialchars($messageClass) ?>"><?= htmlspecialchars($message) ?></div><?php endif; ?>

        <section class="dashboard-section">
            <h2>Add a Register</h2>
            <p class="section-description">Each Register gets a stable identity that never changes, plus the display name Cashiers see.</p>
            <form method="post" class="form-grid">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create">
                <div class="form-group">
                    <label for="register-name">Register name</label>
                    <input id="register-name" type="text" name="name" maxlength="100" placeholder="Front Counter" required>
                </div>
                <div class="form-group form-group-actions">
                    <button class="btn" type="submit">Create Register</button>
                </div>
            </form>
        </section>

        <section class="dashboard-section">
            <h2>Store Registers (<?= count($allRegisters) ?>)</h2>
            <p class="section-description">
                <?= $availableCount ?> of <?= count($allRegisters) ?> <?= count($allRegisters) === 1 ? 'Register is' : 'Registers are' ?> available for new Cashier Shifts.
                Disabled Registers stay listed so their history remains readable.
            </p>

            <?php if ($allRegisters === []): ?>
                <p>No Registers yet. Create one above so Cashier Shifts have a till to anchor to.</p>
            <?php else: ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Register</th>
                            <th>Identity</th>
                            <th>Availability</th>
                            <th>Rename</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($allRegisters as $register): ?>
                        <?php $isActive = $register['status'] === RegisterService::STATUS_ACTIVE; ?>
                        <tr>
                            <td><?= htmlspecialchars($register['name']) ?></td>
                            <td>#<?= (int)$register['register_id'] ?></td>
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
                                    <input type="hidden" name="action" value="rename">
                                    <input type="hidden" name="register_id" value="<?= (int)$register['register_id'] ?>">
                                    <label class="visually-hidden" for="rename-<?= (int)$register['register_id'] ?>">Rename <?= htmlspecialchars($register['name']) ?></label>
                                    <input id="rename-<?= (int)$register['register_id'] ?>" type="text" name="name" maxlength="100" value="<?= htmlspecialchars($register['name']) ?>" required>
                                    <button class="btn btn-secondary" type="submit">Rename</button>
                                </form>
                            </td>
                            <td class="register-actions">
                                <form method="post" class="inline-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="set_status">
                                    <input type="hidden" name="register_id" value="<?= (int)$register['register_id'] ?>">
                                    <input type="hidden" name="status" value="<?= $isActive ? RegisterService::STATUS_DISABLED : RegisterService::STATUS_ACTIVE ?>">
                                    <button class="btn btn-secondary" type="submit"><?= $isActive ? 'Disable' : 'Enable' ?></button>
                                </form>
                                <form method="post" class="inline-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="register_id" value="<?= (int)$register['register_id'] ?>">
                                    <button class="btn btn-danger" type="submit">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="section-description">A Register that any operational record still references cannot be deleted. Disable it instead so its history stays intact.</p>
            <?php endif; ?>
        </section>
    </main>
</div>
</body>
</html>

$availableCount = count($registers->available());
?>