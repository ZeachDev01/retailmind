# Administrator account management and minimum active Administrator protection

Published as [GitHub issue #298](https://github.com/ZeachDev01/retailmind/issues/298), labelled `ready-for-agent`.

## Problem Statement

Administrators cannot manage another Administrator because the current delegated-role policy permits only Cashier and Inventory Manager accounts. The Users interface consequently marks ordinary Administrator accounts as Protected, preventing routine account maintenance by other Administrators.

Manual disabling and role removal do not consistently preserve an active Administrator. Automatic Dormancy Policy protection counts only primary-role Administrators, overlooking accounts with an additional Administrator Workspace. There is no permanent-delete action for unused accounts.

## Solution

Allow an Administrator in the Administrator Workspace to manage other ordinary Administrator accounts, alongside existing staff management. Permit account creation, promotion, profile editing, password reset, role changes, status changes, and permanent deletion subject to the protections below.

Keep the Super Administrator protected from other accounts and the Recovery Account outside ordinary user management. Prevent self-disable, self-delete, and self-demotion. Preserve at least one active ordinary account with Administrator access, whether its Administrator role is primary or additional. Apply this minimum to manual actions and automatic dormancy, including actions initiated by the Super Administrator.

Only accounts without linked business or audit history may be permanently deleted. Accounts with history must remain attributable and may instead be disabled where the other protections allow it.

## User Stories

1. As an Administrator, I want to manage another ordinary Administrator, so that routine account maintenance does not require the Super Administrator.
2. As an Administrator, I want to create another Administrator account, so that Store administration can be delegated.
3. As an Administrator, I want to promote an existing Cashier or Inventory Manager to Administrator, so that staff can assume administrative responsibilities.
4. As an Administrator, I want to edit another Administrator's profile, so that account details remain correct.
5. As an Administrator, I want to reset another Administrator's password, so that their access can be recovered through the existing reset workflow.
6. As an Administrator, I want to change another Administrator's assigned operational roles, so that access matches their responsibilities.
7. As an Administrator, I want to disable another Administrator when an active Administrator will remain, so that departing staff lose access.
8. As an Administrator, I want to re-enable an eligible disabled account, so that authorized staff can return to work.
9. As an Administrator, I want to remove another account's Administrator role when an active Administrator will remain, so that unnecessary authority can be revoked.
10. As an Administrator, I want to permanently delete an unused account, so that accidental account creation can be corrected.
11. As an Administrator, I want deletion refused when business or audit history references the account, so that historical attribution remains intact.
12. As an Administrator, I want a clear explanation that a history-linked account must be disabled instead, so that I can choose an appropriate action.
13. As the Super Administrator, I want my account protected from other accounts, so that routine user management cannot compromise platform ownership.
14. As the Super Administrator, I want the Recovery Account excluded from ordinary account management, so that offline recovery remains protected.
15. As an Administrator, I want the last active ordinary Administrator protected from disabling, deletion, and loss of Administrator access, so that Store administration remains available.
16. As the Super Administrator, I want the same minimum-active-Administrator guard applied to my account-management actions, so that privileged actions cannot accidentally leave the Store without an operational Administrator.
17. As a multi-role Administrator, I want my active account counted even when Administrator is an additional role, so that protection reflects actual Administrator access.
18. As an Administrator, I want disabled accounts, the Super Administrator, and the Recovery Account excluded from the minimum count, so that the remaining Administrator is usable for ordinary administration.
19. As an Administrator, I want automatic dormancy to preserve the same minimum, so that scheduled security maintenance does not remove all ordinary Administrator access.
20. As an account holder, I want self-disable, self-delete, and self-demotion blocked, so that I cannot accidentally remove my own administrative access.
21. As an Administrator, I want the Users interface to distinguish protected identities, last-Administrator restrictions, and accounts with history, so that unavailable actions have understandable reasons.
22. As an Administrator, I want simultaneous account-management requests to preserve an active Administrator, so that individually valid requests cannot together remove every Administrator.
23. As the Super Administrator, I want account-management actions recorded without deleting existing Protected Audit Records, so that security changes remain attributable.
24. As a Cashier or Inventory Manager, I want user-management permissions to remain unavailable in my Workspace, so that this change does not grant unrelated account-management authority.

## Implementation Decisions

- Extend RoleCapabilityPolicy's existing delegated account-management and role-assignment rules to include the ordinary Administrator role. Do not grant Administrators the Super Administrator role, arbitrary privileges, or platform-governance authority.
- Enforce authorization through the active Workspace. Counting assigned Administrator access for minimum protection does not combine Workspace privileges or authorize mutations from a Cashier or Inventory Manager Workspace.
- Extend UserLifecycleService and its existing Users request handling and interface. Keep shared authorization, validation, password-reset, session invalidation, notification, and audit behavior intact. Reuse the existing account-management architecture rather than adding a parallel administration subsystem.
- Protect identities by account attributes and complete role assignments, including additional roles, rather than merely the displayed primary role. Preserve the Super Administrator's existing permitted self-profile maintenance and the Recovery Account's offline lifecycle.
- A qualifying account is active, has the Administrator role as either its primary or additional assignment, and is neither a Super Administrator identity nor the Recovery Account. Count each account once, regardless of its current Workspace.
- An operation that removes qualifying Administrator access must leave at least one qualifying account. Removing an unrelated additional role or changing primary role while retaining Administrator access must not be treated as demotion out of Administrator access. A disabled Administrator is already outside the count.
- Apply the same qualifying-account rule to disable, permanent delete, role removal, and DormancyRunner. Super Administrator actions do not bypass it. Preserve existing dormancy exclusions and side effects.
- Serialize mutations affecting this minimum through a common transactional locking strategy, including scheduled dormancy. Locking only the target account is insufficient when concurrent requests affect different Administrators. Recheck eligibility atomically before committing; avoid partial changes on denial.
- Keep self-disable, self-delete, and self-demotion prohibited. Preserve existing self-management restrictions outside this explicit scope.
- Add permanent deletion for otherwise manageable accounts only when no retained business or audit history links to that identity. Preserve sales, Cashier Shifts, refunds, GCash activity, stock records, and all other retained historical references. Disable remains the alternative for history-linked accounts.
- Distinguish retained historical references from disposable account-owned state. Remove only necessary credentials, tokens, role assignments, preferences, notices, and other nonhistorical account state atomically with an eligible deletion. Inventory all references before implementation; do not remove foreign-key protection or cascade business history.
- Audit an allowed deletion under the acting account and retain sufficient target identity information to explain the deleted account. Do not delete historical audit entries merely because they mention the target; account-creation or administrative audit entries must not be silently erased to make deletion eligible. The exact eligible set must reflect existing retained references, not a newly invented exemption for audit history.
- Preserve session invalidation and forced-password-change semantics for newly allowed Administrator password resets and role/status changes. Deleted accounts and credentials must no longer authenticate.
- Expose permanent delete with an explicit confirmation and understandable rejection messages. Enforce every protection server-side even for direct requests; UI availability alone is insufficient.
- No new dependency or general soft-delete/anonymization framework is required. Retain the current account status model and database referential integrity; determine any narrowly necessary schema adjustment from the implementation inventory rather than assuming one is needed.
- This deliberately changes the ordinary-Administrator lifecycle boundary described by ADR-0001 and the current Administrator domain definition. Update that boundary and affected terminology during implementation while retaining Super Administrator technical governance, Recovery Account isolation, and ADR-0009's active Workspace rules. Preserve Protected Audit Record immutability.

## Testing Decisions

- Proposed primary seam: public UserLifecycleService operations against database fixtures, extending the existing user lifecycle contract. Assert observable permissions, persisted account state, authentication/session effects, retained history, audit outcomes, and useful rejection behavior rather than private helpers or SQL text.
- Reuse RoleCapabilityPolicy contract coverage for the expanded delegated-role matrix. Verify ordinary Administrators can manage/assign Administrator, Cashier, and Inventory Manager roles while Super Administrator assignment and unrelated capabilities remain forbidden.
- Extend the existing DormancyRunner public-runner contract using its controllable clock and injected notifier. Verify that primary and additional Administrator roles count identically and that scheduled disabling retains one qualifying account.
- Follow the existing password-reset integration coverage for password replacement, Mandatory Password Change, session invalidation, and audit behavior, adding ordinary Administrator targets and actors.
- Cover active and disabled targets; primary and additional Administrator assignments; retaining Administrator access while changing other roles; Super Administrator and Recovery Account exclusions; self-action restrictions; and both Administrator and Super Administrator actors.
- Cover successful deletion of an eligible account and rejection for business/history-linked accounts. Assert disposable state cleanup, retained actor/target attribution in the deletion audit, preserved historical records, and transactional rollback on failure. Include representative restrictive foreign-key relationships rather than testing only a permissive in-memory schema.
- Use an isolated MySQL integration fixture with independent database connections to verify concurrent disable, demotion, deletion, and dormancy cannot together remove all qualifying Administrators. SQLite-only or sequential tests cannot establish production locking correctness.
- Add focused browser/HTTP acceptance coverage through the existing Users workflow for action visibility, delete confirmation, protected-account explanations, and server-side rejection of forged requests. Avoid introducing a new test-only application boundary.
- Do not change real accounts, delete real history, send real notifications, or run concurrency fixtures against production data.
- The user confirmed these testing seams before issue publication. No application tests have been executed for this specification-only work.

## Out of Scope

- Implementing the feature during specification synthesis.
- Deleting or anonymizing accounts that have retained business or audit history, deleting historical records, weakening foreign keys, or introducing account tombstones.
- Allowing self-disable, self-delete, self-demotion, or ordinary management of the Recovery Account.
- Granting ordinary Administrators Super Administrator privileges, arbitrary privilege combinations, or platform-governance authority.
- Replacing active Workspace authorization with combined privileges.
- New account types, custom role builders, bulk account operations, changes to dormancy thresholds, or changes to the offline recovery procedure.
- Repairing existing installations with no qualifying active Administrator automatically, or changing Database Restore and backup semantics.

## Further Notes

The account-management, minimum protection, multi-role counting, recovery, self-action, and history-preserving deletion requirements were explicitly agreed during the grilling conversation on 2026-10-25. The subsequent to-spec request authorizes specification synthesis and tracker publication, not application implementation.

The user confirmed the testing plan and authorized publication to the project's GitHub Issues tracker with the ready-for-agent label. No additional product-design interview is needed.
