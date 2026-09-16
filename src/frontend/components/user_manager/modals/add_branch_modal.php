<div class="user-modal-overlay" id="branchModalOverlay" aria-hidden="true">
    <div class="user-modal branch-modal" role="dialog" aria-modal="true" aria-labelledby="branchModalTitle">
        <div class="user-modal-header">
            <div>
                <h3 id="branchModalTitle">Create Branch</h3>
                <p>Add a store location for staff assignments and inventory operations.</p>
            </div>
            <button type="button" class="user-modal-close" id="closeBranchModal" aria-label="Close create branch form">&times;</button>
        </div>
        <form method="POST" class="user-form" id="createBranchForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_branch">
            <div class="form-group">
                <label for="branchName">Branch Name</label>
                <input id="branchName" name="branch_name" value="<?= htmlspecialchars($branchFormValues['branch_name'], ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g., Shalom Main" autocomplete="organization" required>
            </div>
            <div class="form-group">
                <label for="branchCode">Branch Code</label>
                <input id="branchCode" name="branch_code" value="<?= htmlspecialchars($branchFormValues['branch_code'], ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g., SHALOM-MAIN" maxlength="30" autocapitalize="characters" required>
                <span class="field-help">Use a short, unique code for reports and internal references.</span>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" id="cancelBranchModal">Cancel</button>
                <button class="btn manage-users-primary" type="submit"><i class="bi bi-building-add" aria-hidden="true"></i> Create Branch</button>
            </div>
        </form>
    </div>
</div>
