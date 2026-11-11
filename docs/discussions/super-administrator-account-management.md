# Super Administrator account management

Discussion confirmed 2026-11-11 Philippine time. The user authorized implementation after both interview rounds.

## Confirmed requirements

- The Super Administrator can create ordinary Administrator accounts and edit their information. Cashiers and Inventory Managers without Administrator access remain managed by Administrators.
- The Super Administrator can reset passwords and activate or deactivate managed ordinary Administrator accounts.
- Revoke means ending all current sessions. It does not change account status or assigned roles; an active account can sign in again.
- Password reset issues a Temporary Password, ends existing sessions, and requires the holder to choose a replacement at the next sign-in.
- No account-delete action is allowed in either the Super Administrator or Administrator user-management workflow. Activation and deactivation retain account identity and history.
- The user confirmed all four scope decisions in the first interview round.

## Additional confirmed decisions

- Editable information includes first name, last name, username, email and profile picture.
- The Super Administrator must leave the target's assigned Workspaces and primary role unchanged, including accounts with additional Administrator access.
- Existing last-active-Administrator, protected-identity and self-action guards remain in force.
- Reactivation restores sign-in eligibility while preserving roles and history, without restoring revoked sessions or bypassing a pending Mandatory Password Change.

## Implementation

- Extend the existing UserLifecycleService and Users drawer rather than creating a separate workflow.
- Remove the delete form and action from the interface; retain a rejecting service entry point for legacy and forged requests. No credentials or historical data are deleted.
- Reuse profile-image storage, validation, notifications, password-reset, session-version and audit behavior.

## Related boundaries

- [ADR-0026](../adr/0026-super-administrator-creates-administrators-only.md) restricts Super Administrator creation to ordinary Administrators and disallows changing another account's roles.
- [ADR-0001](../adr/0001-single-store-administrator-boundaries.md) requires retaining at least one active ordinary Administrator, without a Super Administrator bypass.
- The [earlier account-management specification](../specs/administrator-account-management-and-protection.md) previously permitted permanent deletion of eligible unused accounts. The confirmed no-delete requirement supersedes that behavior in both user-management workflows; direct delete requests are rejected and the interface action is removed.

## Verification

- Passed `npm run check` and the user lifecycle, staff lifecycle audit, profile-picture authority, recovery-exclusion reset audit, dormancy Status tab and password-policy contracts.
- Passed isolated MySQL no-delete checks and concurrent disable, demotion and dormancy checks preserving one active ordinary Administrator.
- Passed browser/HTTP checks for Super Administrator profile editing, Temporary Password reset, session revocation, deactivate/reactivate, absent delete controls, forged deletion rejection and last-Administrator protection. Checked desktop and mobile control visibility and inspected the rendered desktop drawer.
- Used disposable test databases and accounts; no real accounts were modified.
