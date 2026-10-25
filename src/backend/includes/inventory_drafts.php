<?php
// Include only after page authorization, and only for a mutating Inventory Manager.
if (current_role() !== 'inventory_manager' || !has_capability(\App\Authorization\RoleCapabilityPolicy::MUTATE_INVENTORY)) return;
$draftKey = null;
try {
    $draftKeyStmt = $pdo->prepare('SELECT encryption_key FROM inventory_draft_keys WHERE user_id = ?');
    $draftKeyStmt->execute([(int)$_SESSION['user_id']]);
    $draftKey = $draftKeyStmt->fetchColumn();
    if (!$draftKey) {
        $newDraftKey = base64_encode(random_bytes(32));
        $insertDraftKey = $pdo->prepare('INSERT INTO inventory_draft_keys (user_id, encryption_key) VALUES (?, ?)');
        try { $insertDraftKey->execute([(int)$_SESSION['user_id'], $newDraftKey]); }
        catch (PDOException $e) {
            if (!str_starts_with((string)$e->getCode(), '23')) throw $e;
        }
        $draftKeyStmt->execute([(int)$_SESSION['user_id']]);
        $draftKey = $draftKeyStmt->fetchColumn();
    }
} catch (Throwable $e) { error_log('Inventory draft key unavailable: ' . $e->getMessage()); }
?>
<script src="<?= htmlspecialchars(app_url('assets/js/inventory-drafts.js')) ?>"></script>
<script>
window.RetailMindInventoryDrafts.start({
    key: <?= json_encode($draftKey ?: null) ?>,
    user: <?= json_encode((string)$_SESSION['user_id']) ?>,
    confirmed: <?= json_encode(isset($_SESSION['inventory_confirmed_draft']) || ($message ?? '') !== '' || isset($_GET['success']) || isset($_GET['warning'])) ?>,
    confirmedForm: <?= json_encode($_POST['inventory_draft_id'] ?? $_SESSION['inventory_confirmed_draft'] ?? null) ?>,
    retainedForm: <?= json_encode(($error ?? '') !== '' ? ($_POST['inventory_draft_id'] ?? null) : null) ?>,
    retainedToken: <?= json_encode(($error ?? '') !== '' ? ($_POST['operation_token'] ?? null) : null) ?>,
    statusUrl: <?= json_encode(app_url('components/inventory_management/submission_status.php')) ?>
});
document.currentScript.remove();
<?php unset($_SESSION['inventory_confirmed_draft']); ?>
</script>
