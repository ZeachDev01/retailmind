<?php
// components/administrator/registers.php — Register administration (ticket #87, #452).
//
// The Administrator creates and maintains the Store's physical Registers. A
// rename changes only the display name, so the stable identity held by earlier
// operational records never moves. Disabling withdraws a Register from new
// Cashier Shifts while keeping it visible in history, and a Register that any
// operational record still points at cannot be deleted.
// Terminals can be assigned, active status monitored, and transaction limits configured.
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
$submittedTerminal = '';
$submittedLimit = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    $registerId = (int)($_POST['register_id'] ?? 0);

    try {
        if ($action === 'create') {
            $terminal = trim((string)($_POST['terminal_identifier'] ?? ''));
            $limit = trim((string)($_POST['transaction_limit'] ?? ''));
            $registers->create(
                $actorId,
                $actorRole,
                (string)($_POST['name'] ?? ''),
                (string)($_POST['paper_width_mm'] ?? '80'),
                $terminal === '' ? null : $terminal,
                $limit === '' ? null : $limit
            );
            $message = 'Register created. It is now available for new Cashier Shifts.';
            $messageClass = 'tag-success';
        } elseif ($action === 'rename') {
            $registers->rename($actorId, $actorRole, $registerId, (string)($_POST['name'] ?? ''));
            $message = 'Register renamed. Earlier records keep their original Register identity.';
            $messageClass = 'tag-success';
        } elseif ($action === 'assign_terminal') {
            $terminal = trim((string)($_POST['terminal_identifier'] ?? ''));
            $registers->assignTerminal($actorId, $actorRole, $registerId, $terminal === '' ? null : $terminal);
            $message = 'Terminal assignment saved for register.';
            $messageClass = 'tag-success';
        } elseif ($action === 'set_transaction_limit') {
            $limit = trim((string)($_POST['transaction_limit'] ?? ''));
            $registers->setTransactionLimit($actorId, $actorRole, $registerId, $limit === '' ? null : $limit);
            $message = 'Register transaction limit updated.';
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
        $submittedTerminal = (string)($_POST['terminal_identifier'] ?? '');
        $submittedLimit = (string)($_POST['transaction_limit'] ?? '');
        $message = OperatorAlert::message(
            $exception,
            'The Register could not be saved. Check the input and try again. Tell your Administrator if this keeps happening.'
        );
        $messageClass = 'tag-warning';
    }
}

$allRegisters = $registers->all();
$drawerSummary = $registers->activeDrawerStatus();
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
                <p class="page-subheading">Manage points of sale, assigned terminals, transaction limits, and shift availability.</p>
            </div>
        </header>

        <?php if ($message): ?><div id="register-feedback" class="alert <?= htmlspecialchars($messageClass) ?>" role="status"><?= htmlspecialchars($message) ?></div><?php endif; ?>

        <?php
        $activeCount = 0;
        $disabledCount = 0;
        $inUseCount = 0;
        foreach ($allRegisters as $reg) {
            if (($reg['status'] ?? '') === RegisterService::STATUS_ACTIVE) {
                $activeCount++;
            } else {
                $disabledCount++;
            }
            if (!empty($reg['is_in_use'])) {
                $inUseCount++;
            }
        }
        ?>
        <div class="card-grid" style="margin-bottom: 1.5rem;">
            <article class="stat-card with-icon">
                <span class="stat-icon"><i class="bi bi-hdd-network" aria-hidden="true"></i></span>
                <div class="label">Total Registers</div>
                <div class="value"><?= count($allRegisters) ?></div>
                <div class="hint">Configured tills</div>
            </article>
            <article class="stat-card with-icon status-healthy">
                <span class="stat-icon"><i class="bi bi-check-circle" aria-hidden="true"></i></span>
                <div class="label">Available Registers</div>
                <div class="value"><?= $activeCount ?></div>
                <div class="hint">Open for shifts</div>
            </article>
            <article class="stat-card with-icon <?= $inUseCount > 0 ? 'status-healthy' : '' ?>">
                <span class="stat-icon"><i class="bi bi-person-badge" aria-hidden="true"></i></span>
                <div class="label">Active Drawers</div>
                <div class="value"><?= $inUseCount ?></div>
                <div class="hint"><?= (int)($drawerSummary['open_shifts'] ?? 0) ?> open shift(s)</div>
            </article>
            <article class="stat-card with-icon <?= $disabledCount > 0 ? 'status-warning' : '' ?>">
                <span class="stat-icon"><i class="bi bi-pause-circle" aria-hidden="true"></i></span>
                <div class="label">Disabled Registers</div>
                <div class="value"><?= $disabledCount ?></div>
                <div class="hint">Blocked from shifts</div>
            </article>
        </div>

        <section class="dashboard-section" aria-labelledby="add-register-heading">
            <h2 id="add-register-heading"><i class="bi bi-plus-circle" aria-hidden="true"></i> Add a Register</h2>
            <form method="post" class="form-grid">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create">
                <div class="form-group">
                    <label for="register-name">Register name</label>
                    <input id="register-name" type="text" name="name" maxlength="100" autocomplete="off" placeholder="e.g. Front Counter" value="<?= htmlspecialchars($failedAction === 'create' ? $submittedName : '') ?>" <?= $failedAction === 'create' ? 'aria-describedby="register-feedback"' : '' ?> required>
                </div>
                <div class="form-group">
                    <label for="register-terminal">Assigned terminal</label>
                    <input id="register-terminal" type="text" name="terminal_identifier" maxlength="64" autocomplete="off" placeholder="e.g. POS-TERM-01 (optional)" value="<?= htmlspecialchars($failedAction === 'create' ? $submittedTerminal : '') ?>">
                </div>
                <div class="form-group">
                    <label for="register-limit">Transaction limit (₱)</label>
                    <input id="register-limit" type="number" step="0.01" min="0" name="transaction_limit" autocomplete="off" placeholder="e.g. 50000.00 (blank for unlimited)" value="<?= htmlspecialchars($failedAction === 'create' ? $submittedLimit : '') ?>">
                </div>
                <div class="form-group">
                    <label for="register-paper-width">Receipt paper width</label>
                    <select id="register-paper-width" name="paper_width_mm" required>
                        <option value="80" <?= $failedAction !== 'create' || $submittedPaperWidth !== '58' ? 'selected' : '' ?>>80 mm (Standard POS roll)</option>
                        <option value="58" <?= $failedAction === 'create' && $submittedPaperWidth === '58' ? 'selected' : '' ?>>58 mm (Compact roll)</option>
                    </select>
                </div>
                <div class="form-group" style="align-self: flex-end;">
                    <button class="btn btn-primary" type="submit"><i class="bi bi-plus-lg" aria-hidden="true"></i> Create Register</button>
                </div>
            </form>
        </section>

        <section class="dashboard-section" aria-labelledby="store-registers-heading">
            <h2 id="store-registers-heading"><i class="bi bi-hdd-network" aria-hidden="true"></i> Store Registers <span class="register-count">(<?= count($allRegisters) ?>)</span></h2>
            <p class="section-description">Disabling blocks new Shifts while preserving existing open Shifts.</p>

            <?php if ($allRegisters === []): ?>
                <div class="empty-state">
                    <i class="bi bi-hdd-network" aria-hidden="true"></i>
                    <strong>No Registers Configured</strong>
                    <p class="section-description">Add a register above to enable cashier shifts.</p>
                </div>
            <?php else: ?>
                <div class="data-table-shell">
                    <div class="data-table-scroll" tabindex="0" role="region" aria-labelledby="store-registers-heading">
                        <table class="data-table">
                    <thead>
                        <tr>
                            <th scope="col">Register &amp; Terminal</th>
                            <th scope="col">Active Status</th>
                            <th scope="col">Transaction Limit</th>
                            <th scope="col">Receipt paper width</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($allRegisters as $register): ?>
                        <?php
                        $isActive = $register['status'] === RegisterService::STATUS_ACTIVE;
                        $isInUse = !empty($register['is_in_use']);
                        $renameFailed = $failedAction === 'rename' && $failedRegisterId === $register['register_id'];
                        $terminalFailed = $failedAction === 'assign_terminal' && $failedRegisterId === $register['register_id'];
                        $limitFailed = $failedAction === 'set_transaction_limit' && $failedRegisterId === $register['register_id'];
                        ?>
                        <tr>
                            <td class="register-name-cell">
                                <div class="register-identity">
                                    <strong><?= htmlspecialchars($register['name']) ?></strong>
                                    <button class="register-edit-toggle" type="button" aria-label="Edit <?= htmlspecialchars($register['name']) ?>" aria-expanded="<?= $renameFailed ? 'true' : 'false' ?>" aria-controls="rename-form-<?= (int)$register['register_id'] ?>" title="Rename register">
                                        <i class="bi bi-pencil" aria-hidden="true"></i>
                                    </button>
                                    <span class="register-id">Register ID #<?= (int)$register['register_id'] ?></span>
                                    <?php if (!empty($register['terminal_identifier'])): ?>
                                        <span class="tag-quiet" style="font-size: 0.75rem;"><i class="bi bi-display" aria-hidden="true"></i> <?= htmlspecialchars($register['terminal_identifier']) ?></span>
                                    <?php else: ?>
                                        <span class="tag-quiet" style="font-size: 0.75rem; color: var(--muted);"><i class="bi bi-display" aria-hidden="true"></i> No terminal</span>
                                    <?php endif; ?>
                                </div>
                                <form method="post" class="inline-form register-rename-form" id="rename-form-<?= (int)$register['register_id'] ?>" <?= $renameFailed ? '' : 'hidden' ?>>
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="rename">
                                    <input type="hidden" name="register_id" value="<?= (int)$register['register_id'] ?>">
                                    <input id="rename-<?= (int)$register['register_id'] ?>" type="text" name="name" maxlength="100" autocomplete="off" value="<?= htmlspecialchars($renameFailed ? $submittedName : $register['name']) ?>" aria-label="Rename <?= htmlspecialchars($register['name']) ?>" <?= $renameFailed ? 'aria-describedby="register-feedback"' : '' ?> required>
                                    <button class="btn btn-secondary" type="submit" aria-label="Rename <?= htmlspecialchars($register['name']) ?>">Rename</button>
                                    <button class="btn btn-quiet register-rename-cancel" type="button" aria-label="Cancel rename" data-cancel-for="rename-form-<?= (int)$register['register_id'] ?>">Cancel</button>
                                </form>
                                <form method="post" class="inline-form" style="margin-top: 0.35rem;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="assign_terminal">
                                    <input type="hidden" name="register_id" value="<?= (int)$register['register_id'] ?>">
                                    <input type="text" name="terminal_identifier" maxlength="64" autocomplete="off" placeholder="Terminal identifier" value="<?= htmlspecialchars($terminalFailed ? $submittedTerminal : ($register['terminal_identifier'] ?? '')) ?>" aria-label="Terminal identifier for <?= htmlspecialchars($register['name']) ?>" style="font-size: 0.8rem; padding: 0.25rem 0.45rem; width: 140px;">
                                    <button class="btn btn-quiet" type="submit" aria-label="Set terminal for <?= htmlspecialchars($register['name']) ?>"><i class="bi bi-check" aria-hidden="true"></i> Assign</button>
                                </form>
                            </td>
                            <td>
                                <?php if ($isActive): ?>
                                    <span class="tag-success"><i class="bi bi-check-circle" aria-hidden="true"></i> Available</span>
                                <?php else: ?>
                                    <span class="tag-warning"><i class="bi bi-dash-circle" aria-hidden="true"></i> Disabled<?= $register['disabled_at'] !== null ? ' since ' . htmlspecialchars(format_display_date($register['disabled_at'])) : '' ?></span>
                                <?php endif; ?>

                                <div style="margin-top: 0.35rem; font-size: 0.8rem;">
                                    <?php if ($isInUse): ?>
                                        <span class="tag-success"><i class="bi bi-broadcast" aria-hidden="true"></i> Active Shift #<?= (int)$register['open_shift_id'] ?></span>
                                    <?php else: ?>
                                        <span class="tag-quiet" style="color: var(--muted);"><i class="bi bi-circle" aria-hidden="true"></i> No active shift</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <form method="post" class="inline-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="set_transaction_limit">
                                    <input type="hidden" name="register_id" value="<?= (int)$register['register_id'] ?>">
                                    <div style="display: flex; align-items: center; gap: 0.3rem;">
                                        <input type="number" step="0.01" min="0" name="transaction_limit" autocomplete="off" placeholder="Unlimited" value="<?= htmlspecialchars($limitFailed ? $submittedLimit : ($register['transaction_limit'] !== null ? number_format((float)$register['transaction_limit'], 2, '.', '') : '')) ?>" aria-label="Transaction limit for <?= htmlspecialchars($register['name']) ?>" style="font-size: 0.85rem; padding: 0.25rem 0.45rem; width: 110px;">
                                        <button class="btn btn-secondary" type="submit" aria-label="Save limit for <?= htmlspecialchars($register['name']) ?>"><i class="bi bi-check2" aria-hidden="true"></i> Save</button>
                                    </div>
                                    <div style="font-size: 0.75rem; color: var(--muted); margin-top: 0.2rem;">
                                        <?= $register['transaction_limit'] !== null ? 'Max: ₱' . number_format((float)$register['transaction_limit'], 2) : 'No limit set' ?>
                                    </div>
                                </form>
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
                                    <button class="btn btn-secondary" type="submit" aria-label="Save width for <?= htmlspecialchars($register['name']) ?>"><i class="bi bi-check2" aria-hidden="true"></i> Save width</button>
                                </form>
                            </td>
                            <td>
                                <div class="register-table-actions">
                                <form method="post" class="inline-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="set_status">
                                    <input type="hidden" name="register_id" value="<?= (int)$register['register_id'] ?>">
                                    <input type="hidden" name="status" value="<?= $isActive ? RegisterService::STATUS_DISABLED : RegisterService::STATUS_ACTIVE ?>">
                                    <button class="btn btn-secondary" type="submit" aria-label="<?= $isActive ? 'Disable' : 'Enable' ?> <?= htmlspecialchars($register['name']) ?>"><i class="bi <?= $isActive ? 'bi-pause-circle' : 'bi-play-circle' ?>" aria-hidden="true"></i> <?= $isActive ? 'Disable' : 'Enable' ?></button>
                                </form>
                                <form method="post" class="inline-form" data-confirm="<?= htmlspecialchars('Permanently delete Register “' . $register['name'] . '”? This cannot be undone.') ?>" data-confirm-title="Delete Register" data-confirm-button="Delete Register" data-confirm-danger="1">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="register_id" value="<?= (int)$register['register_id'] ?>">
                                    <button class="btn btn-danger" type="submit" aria-label="Delete <?= htmlspecialchars($register['name']) ?>"><i class="bi bi-trash" aria-hidden="true"></i> Delete</button>
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
        if (open) {
            const input = form.querySelector('input[name="name"]');
            input.focus();
            input.select();
        }
    });
});
document.querySelectorAll('.register-rename-cancel').forEach(button => {
    button.addEventListener('click', () => {
        const formId = button.getAttribute('data-cancel-for');
        const form = document.getElementById(formId);
        if (form) {
            form.hidden = true;
            const toggle = document.querySelector(`[aria-controls="${formId}"]`);
            if (toggle) {
                toggle.setAttribute('aria-expanded', 'false');
                toggle.focus();
            }
        }
    });
});
document.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
        document.querySelectorAll('.register-rename-form:not([hidden])').forEach(form => {
            form.hidden = true;
            const toggle = document.querySelector(`[aria-controls="${form.id}"]`);
            if (toggle) {
                toggle.setAttribute('aria-expanded', 'false');
                toggle.focus();
            }
        });
    }
});
</script>
</body>
</html>
