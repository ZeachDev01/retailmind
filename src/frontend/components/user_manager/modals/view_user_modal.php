<div class="user-modal-overlay" id="viewUserModalOverlay" aria-hidden="true">
    <div class="user-modal" role="dialog" aria-modal="true" aria-labelledby="viewUserTitle">
        <div class="user-modal-header">
            <div>
                <h3 id="viewUserTitle">User Details</h3>
                <p id="viewUserMeta"></p>
            </div>
            <button type="button" class="user-modal-close" id="closeViewUserModal" aria-label="Close user details">&times;</button>
        </div>
        <div class="user-form">
            <div class="form-group"><label>Email</label><p id="viewUserEmail"></p></div>
            <div class="form-group"><label>Role</label><p id="viewUserRole"></p></div>
            <div class="form-group"><label>Branch</label><p id="viewUserBranch"></p></div>
            <div class="form-group"><label>Status</label><p id="viewUserStatus"></p></div>
            <div class="form-group"><label>Created</label><p id="viewUserCreated"></p></div>
            <div class="form-group"><label>Last Activity</label><p id="viewUserLastActive"></p></div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" id="cancelViewUserModal">Close</button>
            </div>
        </div>
    </div>
</div>
