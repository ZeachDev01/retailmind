# Task Plan: Dedicated Administration Pages

## Goal
Replace modal-only administration workflows with dedicated pages while preserving their behavior and established application styling.

## Phases
- [x] Phase 1: Inspect the existing audit-log and user-management implementations, routes, dependencies, and tests.
- [x] Phase 2: Implement the dedicated audit-log route/page and remove the modal entry flow.
- [x] Phase 3: Integrate DataTables using existing project conventions and preserve audit-specific behavior.
- [x] Phase 4: Run focused tests/build checks and review the final diff.
- [x] Phase 5: Inspect the existing fiscal-period modal, routes, dependencies, and tests.
- [x] Phase 6: Implement a dedicated fiscal-period page and remove the modal entry flow.
- [x] Phase 7: Run focused tests/build checks and review the fiscal-period diff.
- [x] Phase 8: Inspect the existing system-health modal, service data, routes, styles, and tests.
- [x] Phase 9: Implement a dedicated system-health page and remove the modal entry flow.
- [x] Phase 10: Run focused checks and review the system-health diff.
- [x] Phase 11: Inspect the Manage Users tab markup, state handling, counts, and responsive styles.
- [x] Phase 12: Redesign the Users/Branches tab control without changing its behavior.
- [x] Phase 13: Run focused checks and review the Manage Users tab diff.
- [x] Phase 14: Inspect existing user and branch action markup plus modal lifecycle behavior.
- [x] Phase 15: Move actions into their panel headers and convert branch creation to a compact modal.
- [x] Phase 16: Run focused checks and review the contextual-action diff.
- [x] Phase 17: Reproduce and diagnose the Add Staff modal scrolling failure.
- [x] Phase 18: Make the modal form the viewport-constrained scroll region.
- [x] Phase 19: Run the regression and repository verification checks.
- [x] Phase 20: Inspect Notification Preferences and Profile Preferences markup, state, routes, styles, and tests.
- [x] Phase 21: Move Notification Preferences into Profile Preferences and remove it from Notifications.
- [x] Phase 22: Run focused regressions and repository verification checks.
- [x] Phase 23: Inspect User Management and Audit Logs summary markup and styling.
- [x] Phase 24: Add meaningful icons to the requested summary cards without changing behavior.
- [x] Phase 25: Run focused regressions and repository verification checks.

## Decisions
- Prefer the project's existing layout, components, and dependency versions over introducing a second table stack.
- Keep audit logs read-only and optimize the page for searching, sorting, filtering, and pagination.
- Use the dependency-free DataTables 3.0.4 CDN build, consistent with the project's existing CDN-based frontend dependencies.
- Retain the old audit-log URL as an authenticated 302/307 compatibility redirect rather than leaving bookmarks broken.
- Place Fiscal Periods under `components/system_administrator`, preserve all create/close/lock behavior, and retain the former modal URL as an authenticated compatibility redirect.
- Use a page-scoped fiscal-period stylesheet and remove the iframe overlay, embedded presentation branches, and trigger attributes.
- Place System Health under `components/system_administrator`, preserve the read-only service checks and refresh behavior, and retain the former modal URL as an authenticated compatibility redirect.
- Use a page-scoped system-health stylesheet and remove the iframe overlay, embedded presentation branches, and trigger attributes.
- Replace the full-width dark Manage Users tab bar with a compact light segmented control on the full page, while providing a coordinated dark variant for embedded mode.
- Preserve the existing ARIA roles, roving keyboard focus, and panel-switching JavaScript; add only presentational icon, description, and count elements.
- Move Add Staff User from the page topbar into the Users panel header; keep the existing add-user modal and form-state behavior unchanged.
- Move Create Branch into the Branches panel header and open a compact branch modal using the existing overlay, form, and keyboard-dismiss patterns.
- Keep modal headers visible and make each modal's form/body the internal scroll region, sized with dynamic viewport units for mobile keyboards.
- Preserve notification preference persistence and validation while changing only where the controls are presented.
- Make `components/auth/preferences.php` the canonical account-level Preferences page, keep the old notification URL as a compatibility redirect, and scope its styles independently from the notification inbox.
- Reuse Bootstrap Icons already loaded by the application and style icons through page-specific classes rather than inline presentation.

## Errors Encountered
| Error | Attempt | Resolution |
|---|---:|---|
| Git rejected the repository due to sandbox-user ownership | 1 | Use a command-scoped `safe.directory` setting for read-only Git inspection. |
| `rg.exe` could not launch from the WinGet shim | 1 | Fall back to PowerShell `Get-ChildItem` and `Select-String` for repository search. |
| Browser-control runtime was unavailable for visual QA | 1 | Completed repository-wide lint, smoke, route/reference, and diff checks instead. |
| `apply_patch` rejected a delete-and-add of the same legacy fiscal route in one patch | 1 | Split creation and compatibility-route replacement into separate patches. |
| Browser-control JavaScript runtime was not available for local visual QA | 1 | Continue with full PHP lint, smoke checks, source invariants, and diff review. |
| Browser-control runtime remains unavailable for System Health visual QA | 1 | Complete repository-wide lint, smoke, CSS structure, route-reference, and diff checks instead. |
| Browser-control runtime remains unavailable for Manage Users visual QA | 1 | Complete source-level responsive-state review plus full lint, smoke, CSS structure, and diff checks. |
| Contextual-action patch missed a responsive CSS anchor | 1 | Split PHP markup, modal file, JavaScript, CSS, and smoke updates into independent patches. |
| Browser-control runtime remains unavailable for contextual-action QA | 1 | Complete full lint, smoke, CSS structure, modal-reference, and diff checks instead. |
| Add Staff modal could not reach lower fields | 1 | Removed the contradictory dialog overflow rules and added a viewport-constrained scroll region to the modal form. |
| Browser-control runtime remains unavailable for modal-scroll QA | 1 | Added a deterministic stylesheet regression and completed static/runtime verification instead. |
| `git diff --check` found a blank line at the end of `notifications.css` after removing the legacy preference block | 1 | Removed the extra trailing blank line and reran verification. |
