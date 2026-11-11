---
status: accepted
---

# Super Administrator creates Administrators only

The Super Administrator is one platform-governance account with a single Workspace. It creates only ordinary Administrator accounts: it cannot create Cashier or Inventory Manager accounts, cannot assign store Workspaces to itself, and cannot change another account's roles. A second Super Administrator cannot be created through the application; only the sealed offline Recovery Account holds that role.

As confirmed on 2026-11-11, the Super Administrator may maintain ordinary accounts with Administrator access, including an additional Administrator Workspace: edit names, username, email and profile picture; issue a Temporary Password; revoke sessions; and activate or deactivate the account. Profile changes preserve every assigned Workspace and the primary role. Staff without Administrator access remain managed by Administrators. Reactivation preserves a pending Mandatory Password Change and requires a new login. Last-active-Administrator, protected-identity and self-action restrictions remain enforced.

Neither Administrator nor Super Administrator user management permits account deletion. Deactivation retains identity and history; direct deletion requests are rejected as well as removing the interface action. This supersedes the deletion proposal in the earlier account-management specification.

Administrators own store staffing. They create Staff Accounts with one or more Workspaces at creation (the first selected becomes the default), edit an account to add or remove Workspaces later, and may add optional Workspaces to their own account while retaining the Administrator role. Workspace assignment happens in exactly one place, the account edit drawer, so there is no separate multi-role screen or role bundle.

Considered and rejected: offering every role in the Super Administrator's create form (the service only accepts Administrator anyway, so the extra choices were a lie), and letting the Super Administrator take store Workspaces (platform governance is not store work).
