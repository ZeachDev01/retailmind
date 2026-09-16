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

## Decisions
- Prefer the project's existing layout, components, and dependency versions over introducing a second table stack.
- Keep audit logs read-only and optimize the page for searching, sorting, filtering, and pagination.
- Use the dependency-free DataTables 3.0.4 CDN build, consistent with the project's existing CDN-based frontend dependencies.
- Retain the old audit-log URL as an authenticated 302/307 compatibility redirect rather than leaving bookmarks broken.
- Place Fiscal Periods under `components/system_administrator`, preserve all create/close/lock behavior, and retain the former modal URL as an authenticated compatibility redirect.
- Use a page-scoped fiscal-period stylesheet and remove the iframe overlay, embedded presentation branches, and trigger attributes.
- Place System Health under `components/system_administrator`, preserve the read-only service checks and refresh behavior, and retain the former modal URL as an authenticated compatibility redirect.
- Use a page-scoped system-health stylesheet and remove the iframe overlay, embedded presentation branches, and trigger attributes.

## Errors Encountered
| Error | Attempt | Resolution |
|---|---:|---|
| Git rejected the repository due to sandbox-user ownership | 1 | Use a command-scoped `safe.directory` setting for read-only Git inspection. |
| `rg.exe` could not launch from the WinGet shim | 1 | Fall back to PowerShell `Get-ChildItem` and `Select-String` for repository search. |
| Browser-control runtime was unavailable for visual QA | 1 | Completed repository-wide lint, smoke, route/reference, and diff checks instead. |
| `apply_patch` rejected a delete-and-add of the same legacy fiscal route in one patch | 1 | Split creation and compatibility-route replacement into separate patches. |
| Browser-control JavaScript runtime was not available for local visual QA | 1 | Continue with full PHP lint, smoke checks, source invariants, and diff review. |
| Browser-control runtime remains unavailable for System Health visual QA | 1 | Complete repository-wide lint, smoke, CSS structure, route-reference, and diff checks instead. |
