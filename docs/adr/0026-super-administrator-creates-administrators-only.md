---
status: accepted
---

# Super Administrator creates Administrators only

The Super Administrator is one platform-governance account with a single Workspace. It creates Administrator accounts and nothing else: it cannot create Cashier or Inventory Manager accounts, cannot assign store Workspaces to itself, and cannot change another account's roles. A second Super Administrator cannot be created through the application; only the sealed offline Recovery Account holds that role.

Administrators own store staffing. They create Staff Accounts with one or more Workspaces at creation (the first selected becomes the default), edit an account to add or remove Workspaces later, and may add optional Workspaces to their own account while retaining the Administrator role. Workspace assignment happens in exactly one place, the account edit drawer, so there is no separate multi-role screen or role bundle.

Considered and rejected: offering every role in the Super Administrator's create form (the service only accepts Administrator anyway, so the extra choices were a lie), and letting the Super Administrator take store Workspaces (platform governance is not store work).
