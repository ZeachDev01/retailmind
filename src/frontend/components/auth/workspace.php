<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';

use App\Authorization\RoleWorkspaceRouter;

if (!is_logged_in()) {
    header('Location: ' . app_url('?login=1'));
    exit;
}

$workspaceDetails = [
    'super_admin' => ['label' => 'Super Administrator', 'description' => 'Platform governance, security, and system health.', 'icon' => 'bi-shield-lock'],
    'admin' => ['label' => 'Administrator', 'description' => 'Store operations, staff, approvals, and reports.', 'icon' => 'bi-shop'],
    'inventory_manager' => ['label' => 'Inventory', 'description' => 'Stock, suppliers, purchasing, and replenishment.', 'icon' => 'bi-boxes'],
    'cashier' => ['label' => 'Cashier', 'description' => 'Point of sale, shifts, and sales documents.', 'icon' => 'bi-cart-check'],
];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $requestedWorkspace = (string)($_POST['workspace'] ?? '');

    if (!switch_current_workspace($requestedWorkspace)) {
        http_response_code(403);
        $error = 'That workspace is not assigned to your account.';
    } else {
        log_activity(
            $pdo,
            (int)$_SESSION['user_id'],
            'Changed workspace',
            'Authentication',
            (int)$_SESSION['user_id'],
            null,
            ['workspace' => $requestedWorkspace]
        );
        header('Location: ' . app_url(RoleWorkspaceRouter::pathFor($requestedWorkspace)));
        exit;
    }
}

$assignedWorkspaces = array_values(array_filter(
    current_workspace_roles(),
    static fn(string $role): bool => isset($workspaceDetails[$role])
));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change workspace</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/workspace.css')) ?>">
</head>
<body>
    <div class="app-shell">
        <?php include __DIR__ . '/../sidebar.php'; ?>
        <main class="main-content workspace-selector-page">
            <header class="page-heading">
                <div>
                    <h1>Change workspace</h1>
                    <p class="page-subtitle">Choose the workspace you want to use.</p>
                </div>
            </header>

            <?php if ($error !== ''): ?>
                <div class="alert tag-warning" role="alert"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="workspace-options">
                <?php foreach ($assignedWorkspaces as $assignedRole): ?>
                    <?php $details = $workspaceDetails[$assignedRole]; ?>
                    <form method="post" class="workspace-option<?= $assignedRole === current_role() ? ' is-active' : '' ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="workspace" value="<?= htmlspecialchars($assignedRole) ?>">
                        <span class="workspace-option-icon"><i class="bi <?= htmlspecialchars($details['icon']) ?>" aria-hidden="true"></i></span>
                        <span class="workspace-option-copy">
                            <strong><?= htmlspecialchars($details['label']) ?></strong>
                            <small><?= htmlspecialchars($details['description']) ?></small>
                        </span>
                        <?php if ($assignedRole === current_role()): ?>
                            <span class="workspace-current">Current</span>
                        <?php else: ?>
                            <button type="submit" class="btn btn-primary">Open</button>
                        <?php endif; ?>
                    </form>
                <?php endforeach; ?>
            </div>
        </main>
    </div>
</body>
</html>
